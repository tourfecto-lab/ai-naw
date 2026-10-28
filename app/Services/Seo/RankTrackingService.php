<?php
/** Ranks (daily for important keywords, weekly for the rest) are isolated by market, with bounded source batches and append-only history. */
class RankTrackingService
{
    private ?KeywordRankingSourceInterface $source;
    public function __construct(?KeywordRankingSourceInterface $source=null) { $this->source=$source; }
    /**
     * توفير بيانات: الكلمة المهمة (أولوية عالية أو في أول 20 أو لسه ماتقاستش) بتتفحص كل يوم،
     * والباقي مرة في الأسبوع - الكلمة اللي في المركز 60 مابتتحركش كل يوم ومش محتاجة فلوس يومية.
     */
    public static function dueSql(string $alias=''): string
    {
        $a=$alias!==''?$alias.'.':'';
        static $priority=null;
        if ($priority===null) {
            try { $priority=(bool)Database::getInstance()->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tracked_keywords' AND column_name='priority'"); }
            catch (Throwable $e) { $priority=false; }
        }
        $daily="{$a}last_checked_at IS NULL OR {$a}current_position BETWEEN 1 AND 20".($priority?" OR {$a}priority='high'":'');
        return "({$a}last_checked_at IS NULL OR {$a}last_checked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL IF({$daily},1,7) DAY))";
    }
    public function dueWebsites(Database $db,int $limit=50): array
    {
        SeoDataSchema::ensureSchema($db);
        $connected=WebsiteSchema::has('is_connected')?' AND w.is_connected=1':'';
        $order=WebsiteSchema::has('last_rank_tracked_at')?'w.last_rank_tracked_at ASC,':'';
        return $db->query("SELECT w.id,w.user_id,".WebsiteSchema::col(WebsiteSchema::url(),'main_url','w')." FROM websites w WHERE 1=1".$connected.WebsiteSchema::alive('w')." AND EXISTS (SELECT 1 FROM tracked_keywords k WHERE k.website_id=w.id AND ".self::dueSql('k').") ORDER BY {$order}w.id ASC LIMIT ?",[max(1,min(200,$limit))]);
    }
    public static function batches(KeywordRankingSourceInterface $source,string $host,array $keywords): array
    {
        foreach ($keywords as $keyword) if (DataForSeoRankingSource::keywordPhrase($keyword)!==trim($keyword))
            return ['available'=>false,'reason'=>'keyword_annotations_require_review','rankings'=>[]];
        $rankings=[];
        foreach (array_chunk(array_values(array_unique($keywords)),20) as $chunk) {
            $r=$source->check($host,$chunk);
            if (empty($r['available'])) return ['available'=>false,'reason'=>$r['reason']??'source_unavailable','rankings'=>array_merge($rankings,$r['partial_rankings']??[])];
            $rankings=array_merge($rankings,$r['rankings']??[]);
        }
        return ['available'=>true,'rankings'=>$rankings];
    }
    public function checkWebsite(Database $db,int $websiteId,int $userId,int $marketId=0,array $onlyKeywords=[]): array
    {
        $pdo=$db->getConnection(); $key='seo-ranks:'.$websiteId;
        $s=$pdo->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$key]);
        if ((int)$s->fetchColumn()!==1) return ['available'=>false,'reason'=>'rank_check_busy','checked'=>0,'recorded'=>0,'error'=>'الفحص شغال بالفعل'];
        try { return $this->checkLockedWebsite($db,$websiteId,$userId,$marketId,$onlyKeywords); }
        finally { $s=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$key]); }
    }
    private function checkLockedWebsite(Database $db,int $websiteId,int $userId,int $marketId,array $onlyKeywords): array
    {
        $source=$this->source??new DataForSeoRankingSource();
        $out=['available'=>false,'reason'=>null,'checked'=>0,'recorded'=>0,'source'=>$source->sourceName(),'results'=>[],'error'=>null];
        if (!$source->isConfigured()) return array_replace($out,['reason'=>'not_measured','error'=>'اربط DataForSEO من الأدمن — not measured']);
        $markets=new SeoMarketService($db); $site=$markets->site($websiteId,$userId);
        $host=DataForSeoRankingSource::normalizeHost((string)($site['main_url']??$site['domain']??''));
        if (!$host) return array_replace($out,['reason'=>'invalid_main_url']);
        $selected=$marketId?[$markets->resolve($websiteId,$userId,$marketId)]:$markets->markets($websiteId,$userId);
        foreach ($selected as $market) {
            $scoped=$source instanceof DataForSeoRankingSource?$source->forMarket($market,$websiteId):$source;
            $filter=$onlyKeywords?' AND keyword IN ('.implode(',',array_fill(0,count($onlyKeywords),'?')).')':'';
            $words=$db->query("SELECT id,keyword FROM tracked_keywords WHERE website_id=? AND user_id=? AND market_id=? AND keyword<>'' AND ".($onlyKeywords?'(last_checked_at IS NULL OR last_checked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY))':self::dueSql()).$filter." ORDER BY last_checked_at,id LIMIT 500",array_merge([$websiteId,$userId,$market['id']],array_values($onlyKeywords)));
            $result=self::batches($scoped,$host,array_column($words,'keyword'));
            $by=[]; foreach ($words as $w) $by[mb_strtolower(trim($w['keyword']))]=$w;
            $now=gmdate('Y-m-d H:i:s');
            foreach ($result['rankings']??[] as $row) {
                $word=$by[mb_strtolower(trim($row['keyword']??''))]??null; if (!$word) continue;
                $position=$row['position']??null;
                if ($position!==null && (!is_numeric($position) || (int)$position<1 || (int)$position>100)) continue;
                $position=$position===null?null:(int)$position; $url=$row['url']??null;
                $db->transaction(function () use ($db,$websiteId,$userId,$market,$word,$position,$url,$now,$source) {
                    $db->exec('INSERT INTO seo_rank_tracking_history (website_id,user_id,market_id,keyword,position,url,source,checked_at) VALUES (?,?,?,?,?,?,?,?)',[$websiteId,$userId,$market['id'],$word['keyword'],$position,$url,'integration:'.$source->sourceName(),$now]);
                    $db->exec('UPDATE tracked_keywords SET current_position=?,last_checked_at=? WHERE id=? AND website_id=? AND user_id=? AND market_id=?',[$position,$now,$word['id'],$websiteId,$userId,$market['id']]);
                });
                $out['recorded']++; $out['checked']++; $out['results'][]=$row+['market_id'=>$market['id']];
            }
            if (empty($result['available'])) return array_replace($out,['reason'=>$result['reason']??'source_unavailable','error'=>($result['reason']??'')==='keyword_annotations_require_review'?'راجع الكلمات ونقِّ تعليقات نية البحث والأولوية من صفحة الكلمات قبل القياس المدفوع.':'تعذر استكمال القياس؛ النتائج الجزئية محفوظة']);
        }
        if (WebsiteSchema::has('last_rank_tracked_at')) $db->exec('UPDATE websites SET last_rank_tracked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?',[$websiteId,$userId]);
        return array_replace($out,['available'=>true]);
    }
    public function trackingOverview(Database $db,int $websiteId,int $userId,int $marketId=0): array
    {
        $markets=new SeoMarketService($db); $market=$markets->resolve($websiteId,$userId,$marketId);
        $source=$this->source??new DataForSeoRankingSource(); $available=$source->isConfigured();
        $rows=$db->query("SELECT k.id,k.keyword,k.market_id,k.current_position,k.last_checked_at,k.impressions,r.volume search_volume,r.difficulty,r.intent,r.cpc,r.fetched_at FROM tracked_keywords k LEFT JOIN seo_keyword_research r ON r.website_id=k.website_id AND r.market_id=k.market_id AND r.keyword COLLATE utf8mb4_unicode_ci=k.keyword COLLATE utf8mb4_unicode_ci AND r.source='dataforseo' AND r.fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) WHERE k.website_id=? AND k.user_id=? AND k.market_id=? ORDER BY k.id",[$websiteId,$userId,$market['id']]);
        foreach ($rows as &$row) {
            $history=$db->query('SELECT position,checked_at FROM seo_rank_tracking_history WHERE website_id=? AND user_id=? AND market_id=? AND keyword=? AND checked_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY checked_at,id',[$websiteId,$userId,$market['id'],$row['keyword']]);
            $row['trend_30_days']=$history;
            $first=$history[0]['position']??null; $last=$history?($history[count($history)-1]['position']??null):null;
            $row['trend']=$first!==null && $last!==null && count($history)>1 ? (int)$first-(int)$last:null;
            if (!$available) foreach (['current_position','search_volume','difficulty','intent','cpc'] as $key) $row[$key]=null;
        } unset($row);
        return ['status'=>['available'=>$available,'reason'=>$available?null:'not_measured','message'=>$available?'':'اربط DataForSEO من الأدمن — not measured','source'=>'dataforseo'],'market'=>$market,'keywords'=>$rows];
    }
    public function history(Database $db,int $websiteId,int $userId,string $keyword,int $limit=60,int $marketId=0): array
    {
        $market=(new SeoMarketService($db))->resolve($websiteId,$userId,$marketId);
        return ['keyword'=>$keyword,'market_id'=>$market['id'],'points'=>array_reverse($db->query('SELECT position,url,source,checked_at FROM seo_rank_tracking_history WHERE website_id=? AND user_id=? AND market_id=? AND keyword=? ORDER BY checked_at DESC,id DESC LIMIT ?',[$websiteId,$userId,$market['id'],$keyword,max(1,min(1000,$limit))]))];
    }
}
