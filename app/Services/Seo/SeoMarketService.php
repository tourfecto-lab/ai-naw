<?php
/** All entry points verify site ownership; website-row locks serialize plan market limits. */
class SeoMarketService
{
    private Database $db;
    public function __construct(?Database $db=null) { $this->db=$db ?? Database::getInstance(); }
    public function site(int $wid,int $uid): array
    {
        $r=$this->db->query('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid]);
        if (!$r) throw new InvalidArgumentException('الموقع غير موجود');
        SeoDataSchema::ensureSchema($this->db);
        return $r[0];
    }
    public function limit(int $uid): int
    {
        $owner=$this->db->query('SELECT owner_user_id FROM users WHERE id=?',[$uid])[0]['owner_user_id']??0;
        $s=Subscription::activeSubscriptionRow((int)$owner ?: $uid);
        if (!$s) return 1;
        require_once __DIR__.'/../SeoExpert/SeoPlanLimits.php';
        $max=SeoPlanLimits::forUser($uid,$this->db)['markets'];
        $features=json_decode((string)($s['plan_features_json']??$s['features_json']??'{}'),true) ?: [];
        return max(1,min($max,(int)($features['seo_markets']??$max)));
    }
    public static function defaultMarket(string $language): array
    {
        $code=strtolower(explode('-',$language)[0]);
        $defaults=['en'=>[2840,'US – English'],'ar'=>[2818,'Egypt – Arabic'],'de'=>[2276,'Germany – German'],'fr'=>[2250,'France – French'],'es'=>[2724,'Spain – Spanish'],'it'=>[2380,'Italy – Italian'],
            'pt'=>[2620,'Portugal – Portuguese'],'nl'=>[2528,'Netherlands – Dutch'],'ru'=>[2643,'Russia – Russian'],'tr'=>[2792,'Turkey – Turkish'],'pl'=>[2616,'Poland – Polish'],'ja'=>[2392,'Japan – Japanese'],'ko'=>[2410,'South Korea – Korean'],
            'sv'=>[2752,'Sweden – Swedish'],'da'=>[2208,'Denmark – Danish'],'no'=>[2578,'Norway – Norwegian'],'fi'=>[2246,'Finland – Finnish'],'cs'=>[2203,'Czechia – Czech'],'el'=>[2300,'Greece – Greek'],'he'=>[2376,'Israel – Hebrew']];
        if (!isset($defaults[$code])) throw new InvalidArgumentException('اختار السوق الأول يدويًا للغة الموقع');
        return ['location_code'=>$defaults[$code][0],'language_code'=>$code,'label'=>$defaults[$code][1],'is_primary'=>true];
    }
    public function markets(int $wid,int $uid): array
    {
        $this->site($wid,$uid);
        $rows=$this->db->query('SELECT * FROM seo_markets WHERE website_id=? ORDER BY is_primary DESC,id',[$wid]);
        if (!$rows) {
            $language=ContentLanguageService::forWebsite($wid,$uid);
            $this->add($wid,$uid,self::defaultMarket($language));
            $rows=$this->db->query('SELECT * FROM seo_markets WHERE website_id=? ORDER BY is_primary DESC,id',[$wid]);
        }
        return array_slice($rows,0,$this->limit($uid));
    }
    public function resolve(int $wid,int $uid,int $mid=0): array
    {
        foreach ($this->markets($wid,$uid) as $m) if (!$mid || (int)$m['id']===$mid) return $m;
        throw new InvalidArgumentException('السوق مش تابع للموقع');
    }
    public function add(int $wid,int $uid,array $in): array
    {
        $this->site($wid,$uid);
        $location=filter_var($in['location_code']??null,FILTER_VALIDATE_INT);
        $language=(string)($in['language_code']??''); $label=trim((string)($in['label']??''));
        if (!$location || $location<1 || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$language) || mb_strlen($label)<2 || mb_strlen($label)>100) throw new InvalidArgumentException('راجع كود البلد واللغة واسم السوق');
        $limit=$this->limit($uid);
        return $this->db->transaction(function () use ($wid,$uid,$location,$language,$label,$in,$limit) {
            $this->db->query('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]);
            $rows=$this->db->query('SELECT * FROM seo_markets WHERE website_id=?',[$wid]);
            foreach ($rows as $r) if ((int)$r['location_code']===$location && $r['language_code']===$language) return $r;
            if (count($rows)>=$limit) throw new InvalidArgumentException('وصلت لعدد الأسواق المتاح في باقتك');
            $primary=!$rows || !empty($in['is_primary']);
            if ($primary) $this->db->exec('UPDATE seo_markets SET is_primary=0 WHERE website_id=?',[$wid]);
            $this->db->exec('INSERT INTO seo_markets (website_id,location_code,language_code,label,is_primary) VALUES (?,?,?,?,?)',[$wid,$location,$language,$label,(int)$primary]);
            $m=$this->db->query('SELECT * FROM seo_markets WHERE website_id=? AND location_code=? AND language_code=?',[$wid,$location,$language])[0];
            // Legacy ranks have no geographical provenance. Keep their history unassigned.
            $this->db->exec('UPDATE tracked_keywords SET market_id=?,current_position=NULL,last_checked_at=NULL WHERE website_id=? AND market_id IS NULL',[$m['id'],$wid]);
            return $m;
        });
    }
    public function primary(int $wid,int $uid,int $mid): void
    {
        $this->resolve($wid,$uid,$mid);
        $this->db->transaction(function () use ($wid,$uid,$mid) {
            $this->db->query('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]);
            $this->db->exec('UPDATE seo_markets SET is_primary=(id=?) WHERE website_id=?',[$mid,$wid]);
        });
    }
    /** A default country may be corrected before any geographically scoped work exists. */
    public function replaceUnmeasured(int $wid,int $uid,int $mid,array $in): array
    {
        $this->resolve($wid,$uid,$mid);
        $location=filter_var($in['location_code']??null,FILTER_VALIDATE_INT);$language=(string)($in['language_code']??'');$label=trim((string)($in['label']??''));
        if(($in['reviewed']??false)!==true||!$location||$location<1||!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$language)||mb_strlen($label)<2||mb_strlen($label)>100)throw new InvalidArgumentException('راجع البلد واللغة ووافق على تغيير السوق غير المقاس');
        return $this->db->transaction(function()use($wid,$uid,$mid,$location,$language,$label){
            $pdo=$this->db->getConnection();$s=$pdo->prepare('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE');$s->execute([$wid,$uid]);if(!$s->fetchColumn())throw new InvalidArgumentException('الموقع غير متاح');
            $s=$pdo->prepare('SELECT id FROM seo_markets WHERE id=? AND website_id=? FOR UPDATE');$s->execute([$mid,$wid]);if(!$s->fetchColumn())throw new InvalidArgumentException('السوق غير متاح');
            foreach(['seo_keyword_research','seo_research_cache','seo_rank_tracking_history','competitor_keywords','seo_ai_prompt_sets','seo_ai_answers','seo_ai_calls','seo_ai_tasks'] as $table){
                $s=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'market_id\'');$s->execute([$table]);if(!$s->fetchColumn())continue;
                $s=$pdo->prepare('SELECT 1 FROM `'.$table.'` WHERE market_id=? LIMIT 1');$s->execute([$mid]);if($s->fetchColumn())throw new InvalidArgumentException('لهذا السوق قياسات أو إعدادات مرتبطة؛ احتفظ بسجله وأضف سوقًا منفصلًا حسب الباقة');
            }
            $s=$pdo->prepare('SELECT 1 FROM tracked_keywords WHERE website_id=? AND user_id=? AND market_id=? AND (last_checked_at IS NOT NULL OR enriched_at IS NOT NULL OR current_position IS NOT NULL OR search_volume IS NOT NULL OR difficulty IS NOT NULL OR gsc_checked_at IS NOT NULL) LIMIT 1');$s->execute([$wid,$uid,$mid]);if($s->fetchColumn())throw new InvalidArgumentException('لهذا السوق بيانات كلمات محفوظة؛ لا نغير بلد القياسات القديمة');
            $s=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'seo_content_quality\'');$s->execute();
            if($s->fetchColumn()){$s=$pdo->prepare("SELECT 1 FROM seo_content_quality WHERE website_id=? AND user_id=? AND JSON_VALID(brief_json) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(brief_json) THEN brief_json ELSE '{}' END,'$.market.id')) AS UNSIGNED)=? LIMIT 1");$s->execute([$wid,$uid,$mid]);if($s->fetchColumn())throw new InvalidArgumentException('هذا السوق مرتبط بمحتوى محفوظ؛ احتفظ بتاريخه');}
            $s=$pdo->prepare('SELECT id FROM seo_markets WHERE website_id=? AND location_code=? AND language_code=? AND id<>?');$s->execute([$wid,$location,$language,$mid]);if($s->fetchColumn())throw new InvalidArgumentException('السوق موجود بالفعل؛ اختَره من القائمة بدل تغيير سوق آخر');
            $s=$pdo->prepare('UPDATE seo_markets SET location_code=?,language_code=?,label=? WHERE id=? AND website_id=?');$s->execute([$location,$language,$label,$mid,$wid]);
            $s=$pdo->prepare('SELECT * FROM seo_markets WHERE id=? AND website_id=?');$s->execute([$mid,$wid]);return $s->fetch(PDO::FETCH_ASSOC);
        });
    }
}
