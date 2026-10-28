<?php
/** A gap needs recent competitor evidence AND a measured customer SERP absence/position. */
class SeoKeywordGapService
{
    public static function calculate(array $competitors,array $customer): array
    {
        $index=[];
        foreach ($customer as $r) $index[$r['market_id'].'|'.mb_strtolower(trim($r['keyword']))]=$r;
        $out=[];
        foreach ($competitors as $r) {
            $date=!empty($r['fetched_at'])?strtotime($r['fetched_at'].' UTC'):false;
            if (($r['source']??'')!=='dataforseo' || !$date || $date<time()-30*86400 || $date>time()+300 || !isset($r['position'],$r['search_volume']) || (int)$r['position']<1 || (int)$r['position']>10) continue;
            $own=$index[$r['market_id'].'|'.mb_strtolower(trim($r['keyword']))]??null;
            $checked=$own&&!empty($own['last_checked_at'])?strtotime($own['last_checked_at'].' UTC'):false;
            // An untracked word is unknown, not proof of absence. The gap refresh measures it first.
            if (!$checked || $checked<time()-30*86400 || $checked>time()+300) continue;
            if ($own['current_position']!==null && (int)$own['current_position']<=20) continue;
            $out[]=$r+['customer_position'=>$own['current_position'],'customer_checked_at'=>$own['last_checked_at'],'evidence_state'=>'VERIFIED','volume_source'=>'DataForSEO'];
        }
        usort($out,fn($a,$b)=>(int)$b['search_volume']<=>(int)$a['search_volume']);
        return $out;
    }
    public static function rows(Database $db,int $wid,int $uid): array
    {
        $rows=$db->query("SELECT k.*,c.competitor_domain FROM competitor_keywords k JOIN competitors c ON c.id=k.competitor_id JOIN seo_markets m ON m.id=k.market_id AND m.website_id=c.website_id WHERE c.website_id=? AND c.user_id=? AND c.is_active=1 AND c.monitoring_paused=0 AND m.is_primary=1 AND k.source='dataforseo' AND k.fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",[$wid,$uid]);
        $own=$db->query('SELECT keyword,market_id,current_position,last_checked_at FROM tracked_keywords WHERE website_id=? AND user_id=?',[$wid,$uid]);
        return self::calculate($rows,$own);
    }
    public static function refresh(Database $db,int $wid,int $uid,?DataForSeoResearchService $research=null,?RankTrackingService $rankTracking=null): array
    {
        $market=(new SeoMarketService($db))->resolve($wid,$uid);
        $r=($research??new DataForSeoResearchService($db))->competitors($wid,$uid);
        if (empty($r['available'])) return $r;
        // Bounded and user initiated: measure candidates before claiming a gap.
        $candidates=[];
        foreach ($db->query("SELECT DISTINCT k.keyword FROM competitor_keywords k JOIN competitors c ON c.id=k.competitor_id WHERE c.website_id=? AND c.user_id=? AND c.is_active=1 AND c.monitoring_paused=0 AND k.market_id=? AND k.source='dataforseo' AND k.position BETWEEN 1 AND 10 AND k.fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY k.keyword LIMIT 50",[$wid,$uid,$market['id']]) as $k) {
            $candidates[]=$k['keyword'];
            $db->exec('INSERT IGNORE INTO tracked_keywords (website_id,user_id,market_id,keyword) VALUES (?,?,?,?)',[$wid,$uid,$market['id'],$k['keyword']]);
        }
        if (!$candidates) return ['available'=>true,'reason'=>null,'gaps'=>self::rows($db,$wid,$uid)];
        $check=($rankTracking??new RankTrackingService())->checkWebsite($db,$wid,$uid,(int)$market['id'],$candidates);
        return ['available'=>!empty($check['available']),'reason'=>$check['reason']??null,'gaps'=>self::rows($db,$wid,$uid)];
    }
}
