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
                if (PlanUsage::charge((int)$set['user_id'],'ai_visibility','ai_visibility','GEO '.$provider.' weekly question')==='failed') throw new RuntimeException('geo_quota_and_wallet_exhausted');
                $s=$pdo->prepare("INSERT INTO seo_ai_calls(website_id,user_id,market_id,provider,question_hash,question,week_start,set_version,profile_version,reserved_cost,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
                $s->execute([$set['website_id'],$set['user_id'],$set['market_id'],$provider,$hash,$question,$week,$set['version'],$set['profile_version'],$ceiling]);return (int)$pdo->lastInsertId();
            });
        } finally {$pdo->query("SELECT RELEASE_LOCK('tourfecto:geo-budget')");}
    }
    public function finish(int $id,array $result,?array $analysis,float $cap): void
    {
        $pdo=$this->db->getConnection();$cost=$result['cost']??null;
        if ($cost!==null && (!is_numeric($cost)||!is_finite((float)$cost)||$cost<0)) $cost=null;
        $basis=$result['cost_basis']??($cost===null?'unknown':'calculated_from_reported_usage');
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
