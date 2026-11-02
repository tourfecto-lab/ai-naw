<?php
/** Durable once-per-week claims and global GEO budget reservation before any paid request. */
class AiVisibilityLedger
{
    private Database $db;
    public function __construct(?Database $db=null){$this->db=$db??Database::getInstance();}
    public function reserve(array $set,string $provider,string $question,string $week,float $cap,float $ceiling=0.10): ?int
    {
        AiVisibilitySchema::ensureSchema($this->db);$pdo=$this->db->getConnection();
        if (!is_finite($cap)||$cap<=0) throw new RuntimeException('geo_budget_not_configured');
        if ((int)$pdo->query("SELECT GET_LOCK('tourfecto:geo-budget',10)")->fetchColumn()!==1) throw new RuntimeException('geo_budget_busy');
        try {
            $hash=hash('sha256',AiVisibilityDetector::normalize($question));
            $s=$pdo->prepare('SELECT id FROM seo_ai_calls WHERE website_id=? AND market_id=? AND provider=? AND question_hash=? AND week_start=?');$s->execute([$set['website_id'],$set['market_id'],$provider,$hash,$week]);if($s->fetchColumn())return null;
            $spent=(float)$pdo->query("SELECT COALESCE(SUM(IF(cost IS NULL,reserved_cost,cost)),0) FROM seo_ai_calls WHERE created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
            if (round($spent+$ceiling,6)>round($cap,6)) { $this->alert();throw new RuntimeException('geo_budget_reached'); }
            PlanUsage::quota((int)$set['user_id'],'ai_visibility');
            return $this->db->transaction(function()use($pdo,$set,$provider,$question,$hash,$week,$ceiling){
                $s=$pdo->prepare('SELECT version,approved_at FROM seo_ai_prompt_sets WHERE id=? AND website_id=? AND user_id=? FOR UPDATE');$s->execute([$set['id'],$set['website_id'],$set['user_id']]);$current=$s->fetch(PDO::FETCH_ASSOC);
                if (!$current || !$current['approved_at'] || (int)$current['version']!==(int)$set['version']) throw new RuntimeException('geo_approval_changed');
                $this->chargeQuestion($pdo,$set,$provider);
                $s=$pdo->prepare("INSERT INTO seo_ai_calls(website_id,user_id,market_id,provider,question_hash,question,week_start,set_version,profile_version,reserved_cost,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
                $s->execute([$set['website_id'],$set['user_id'],$set['market_id'],$provider,$hash,$question,$week,$set['version'],$set['profile_version'],$ceiling]);return (int)$pdo->lastInsertId();
            });
        } finally {$pdo->query("SELECT RELEASE_LOCK('tourfecto:geo-budget')");}
    }
    /**
     * القياس الأسبوعي الآلي بيخصم من حصة الباقة بس. بعد ما الحصة تخلص مبنخصمش من محفظة العميل إلا لو هو حدد سقف شهري
     * صريح للموقع (max_ai_wallet_monthly في سياسة السيو، الافتراضي صفر)، وبنحترم السقف ده من خصومات الشهر الحالي.
     */
    private function chargeQuestion(PDO $pdo,array $set,string $provider): void
    {
        $uid=(int)$set['user_id'];$wid=(int)$set['website_id'];$note='GEO auto website:'.$wid.' '.$provider.' weekly question';
        if (PlanUsage::consume($uid,'ai_visibility',$note)) return;
        $limit=0.0;
        try{$s=$pdo->prepare("SELECT policy_json FROM seo_expert_sites WHERE website_id=? AND user_id=?");$s->execute([$wid,$uid]);$policy=json_decode((string)$s->fetchColumn(),true)?:[];}catch(Throwable $e){$policy=[];}
        $value=$policy['max_ai_wallet_monthly']??0;
        if (is_numeric($value)&&is_finite((float)$value)&&(float)$value>0) $limit=round((float)$value,2);
        $s=$pdo->prepare("SELECT price,currency,is_active FROM pay_per_use_pricing WHERE feature_key='ai_visibility' FOR UPDATE");$s->execute();$price=$s->fetch(PDO::FETCH_ASSOC);
        $amount=(float)($price['price']??0);
        if ($limit<=0||!$price||!(int)$price['is_active']||$price['currency']!=='USD'||!is_finite($amount)||$amount<=0) throw new RuntimeException('geo_plan_quota_reached');
        $s=$pdo->prepare("SELECT COALESCE(-SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND feature_key='ai_visibility' AND status='completed' AND currency='USD' AND amount<0 AND reference_note LIKE ? AND created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')");
        $s->execute([$uid,'GEO auto website:'.$wid.' %']);
        if (round((float)$s->fetchColumn()+$amount,2)>$limit) throw new RuntimeException('geo_plan_quota_reached');
        if (!(new WalletService())->chargeAmount($uid,$amount,'ai_visibility',$note)) throw new RuntimeException('geo_quota_and_wallet_exhausted');
    }
    public function finish(int $id,array $result,?array $analysis,float $cap): void
    {
        $pdo=$this->db->getConnection();$cost=$result['cost']??null;
        if ($cost!==null && (!is_numeric($cost)||!is_finite((float)$cost)||$cost<0)) $cost=null;
        $basis=$result['cost_basis']??($cost===null?'unknown':'calculated_from_reported_usage');
        // سبب الفشل بيتسجّل في cost_basis (VARCHAR 60) عشان التشخيص من غير migration: fail:<reason>[:<http>]
        if ($cost===null && $analysis===null && !empty($result['reason'])) $basis=mb_substr('fail:'.preg_replace('/[^a-z_]/','',(string)$result['reason']).(isset($result['http'])?':'.(int)$result['http']:''),0,60);
        // 400/401/403/404 = المزوّد رفض الطلب قبل أي معالجة، فمفيش تكلفة: بنفك الحجز من الميزانية بدل ما نسيبه "غير معروف".
        if ($cost===null && $analysis===null && in_array((int)($result['http']??0),[400,401,403,404],true)) $cost=0.0;
        $this->db->transaction(function()use($pdo,$id,$result,$analysis,$cost,$basis){
            $s=$pdo->prepare('SELECT * FROM seo_ai_calls WHERE id=? FOR UPDATE');$s->execute([$id]);$call=$s->fetch(PDO::FETCH_ASSOC);if(!$call)throw new RuntimeException('GEO claim missing');
            if ($analysis!==null && !empty($result['available'])) {
                $s=$pdo->prepare('INSERT IGNORE INTO seo_ai_answers(call_id,website_id,user_id,market_id,provider,question,question_hash,week_start,set_version,mentioned_brand,brand_position,cited_domain,cited_urls,competitors_mentioned,answer_excerpt,model,cost,cost_basis,usage_json,checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
                $s->execute([$id,$call['website_id'],$call['user_id'],$call['market_id'],$call['provider'],$call['question'],$call['question_hash'],$call['week_start'],$call['set_version'],(int)$analysis['mentioned_brand'],$analysis['brand_position'],$analysis['cited_domain']===null?null:(int)$analysis['cited_domain'],json_encode($analysis['cited_urls'],JSON_THROW_ON_ERROR),json_encode($analysis['competitors_mentioned'],JSON_THROW_ON_ERROR),mb_substr($result['answer'],0,10000),$result['model'],$cost,$basis,json_encode($result['usage']??[],JSON_THROW_ON_ERROR)]);
            }
            $s=$pdo->prepare('UPDATE seo_ai_calls SET status=?,cost=?,cost_basis=?,reserved_cost=IF(? IS NULL,reserved_cost,0) WHERE id=?');
            $s->execute([$analysis!==null?'complete':($cost===null?'unknown':'provider_failed'),$cost,$basis,$cost,$id]);
        });
        $spent=(float)$pdo->query("SELECT COALESCE(SUM(IF(cost IS NULL,reserved_cost,cost)),0) FROM seo_ai_calls WHERE created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
        if (round($spent,6)>=round($cap,6)) $this->alert();
    }
    private function alert(): void
    {
        $pdo=$this->db->getConnection();$s=$pdo->prepare('INSERT IGNORE INTO seo_ai_budget_alerts(month_key,created_at) VALUES (?,UTC_TIMESTAMP())');$s->execute([gmdate('Y-m')]);if(!$s->rowCount())return;
        foreach ($this->db->query("SELECT id FROM users WHERE role IN ('admin','super_admin')") as $u) Notification::notify((int)$u['id'],'geo_budget','راجع ميزانية قياس ظهور AI','الميزانية المتاحة لا تكفي لطلب جديد؛ القياس متوقف لحين مراجعة الميزانية.','/admin/settings');
    }
}
