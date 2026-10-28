<?php
require_once __DIR__.'/SeoPagePolicy.php';
/** Transparent priority signals, not predicted ROI or ranking guarantees. */
final class SeoOpportunityService {
    public static function key(string $url,string $home): ?string {
        if(str_starts_with($url,'/'))$url=parse_url($home,PHP_URL_SCHEME).'://'.parse_url($home,PHP_URL_HOST).$url;
        $safe=SeoDiscoveryService::pageUrl($url,$home);return $safe===null?null:(rtrim((string)parse_url($safe,PHP_URL_PATH),'/')?:'/');
    }
    private static function lower(string $s): string { return function_exists('mb_strtolower')?mb_strtolower(trim($s),'UTF-8'):strtolower(trim($s)); }
    public static function gather(Database $db,int $uid,int $wid): array {
        $out=['keywords'=>[],'competitors'=>[],'competitor_keywords'=>[],'states'=>[]];
        $queries=[
            'keywords'=>"SELECT k.keyword,k.market_id,m.label market_label,k.current_position,r.volume search_volume,r.difficulty,r.intent,k.commercial_intent,k.target_page,k.last_checked_at,r.fetched_at enriched_at,r.source FROM tracked_keywords k JOIN seo_markets m ON m.id=k.market_id AND m.website_id=k.website_id LEFT JOIN seo_keyword_research r ON r.website_id=k.website_id AND r.market_id=k.market_id AND r.keyword COLLATE utf8mb4_unicode_ci=k.keyword COLLATE utf8mb4_unicode_ci AND r.source='dataforseo' AND r.fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) WHERE k.website_id=? AND k.user_id=? ORDER BY k.opportunity_score DESC,k.id DESC LIMIT 200",
            'competitors'=>"SELECT competitor_domain,competitor_name,my_score,competitor_score,last_analyzed_at FROM competitors WHERE website_id=? AND user_id=? AND is_active=1 ORDER BY last_analyzed_at DESC LIMIT 5",
            'competitor_keywords'=>"SELECT k.keyword,k.market_id,k.search_volume,k.difficulty,k.source,k.fetched_at,c.competitor_domain FROM competitor_keywords k JOIN competitors c ON c.id=k.competitor_id JOIN seo_markets m ON m.id=k.market_id AND m.website_id=c.website_id WHERE c.website_id=? AND c.user_id=? AND c.is_active=1 AND k.source='dataforseo' AND k.fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY k.search_volume DESC LIMIT 50"
        ];
        foreach($queries as $kind=>$sql) {
            try {$rows=$db->query($sql,[$wid,$uid]);$out[$kind]=is_array($rows)?$rows:[];$out['states'][$kind]=$out[$kind]?'available':'no_records';}
            catch(Throwable $e) {$out['states'][$kind]='unavailable';}
        }
        foreach($out['keywords'] as &$k) {
            $checked=strtotime((string)($k['last_checked_at']??''). ' UTC');$fresh=$checked!==false && $checked>time()-30*86400 && $checked<=time()+300;
            $k['rank_state']=$fresh&&is_numeric($k['current_position']??null)?'recorded_recent':'unknown_or_stale';
            if($k['rank_state']!=='recorded_recent')$k['current_position']=null;
            $k['search_volume_state']=empty($k['enriched_at'])?'unverified':'recorded';if(empty($k['enriched_at']))$k['search_volume']=null;
        } unset($k);
        foreach($out['competitor_keywords'] as &$k)$k['evidence_state']='research_record_not_verified_ranking';unset($k);
        try { $out['verified_keyword_gaps']=SeoKeywordGapService::rows($db,$wid,$uid); $out['states']['verified_keyword_gaps']='available'; }
        catch(Throwable $e) { $out['verified_keyword_gaps']=[]; $out['states']['verified_keyword_gaps']='unavailable'; }
        return $out;
    }
    public static function rank(array $pages,array $metrics,array $research,string $home,int $selectedLimit=24): array {
        $pageMetrics=[];foreach($metrics['gsc']['pages']??[] as $row)if($key=self::key((string)($row['page']??''),$home))$pageMetrics[$key]=$row;
        $titles=[];foreach($pages as $p){if(!SeoPagePolicy::classify($p)['eligible'])continue;$t=self::lower((string)($p['title']??''));if($t!=='')$titles[$t]=($titles[$t]??0)+1;}
        $ranked=[];$excluded=[];
        foreach($pages as $i=>$p) {
            $scope=SeoPagePolicy::classify($p);if(!$scope['eligible']){$excluded[]=['page_index'=>$i,'url'=>$p['url'],'kind'=>$scope['kind'],'reason'=>$scope['reason']];continue;}
            $key=self::key((string)$p['url'],$home);$m=$key!==null?($pageMetrics[$key]??null):null;$score=in_array($scope['kind'],['homepage','service'],true)?8:($scope['kind']==='destination'?5:0);$reasons=in_array($scope['kind'],['homepage','service','destination'],true)?['marketing_route_hint']:[];$confidence='page_observation';
            if(str_contains(strtolower((string)($p['robots']??'')),'noindex')){$score+=40;$reasons[]='review_indexing_intent';}
            if(empty($p['title'])){$score+=25;$reasons[]='missing_title';}
            if(empty($p['h1'])){$score+=15;$reasons[]='missing_main_heading';}
            if(empty($p['description'])){$score+=10;$reasons[]='missing_description';}
            if(!empty($p['title'])&&($titles[self::lower($p['title'])]??0)>1){$score+=15;$reasons[]='duplicate_title';}
            if($m && (int)($m['impressions']??0)>=100 && ((float)($m['clicks']??0)/(int)$m['impressions'])<0.02){$score+=10;$reasons[]='observed_low_ctr_review_query_intent';$confidence='page_and_search_observations';}
            if($m && (int)($m['impressions']??0)>=50 && (float)($m['position']??0)>=8 && (float)$m['position']<=30){$score+=12;$reasons[]='observed_search_position_8_30';$confidence='page_and_search_observations';}
            $targets=[];foreach($research['keywords']??[] as $kw)if($key!==null && !empty($kw['target_page']) && self::key((string)$kw['target_page'],$home)===$key){$targets[]=array_intersect_key($kw,array_flip(['keyword','current_position','rank_state','search_volume','search_volume_state','commercial_intent']));if(count($targets)>=5)break;}
            if($targets){$score+=5;$reasons[]='explicit_keyword_target_mapping';}
            $ranked[]=['page_index'=>$i,'url'=>$p['url'],'priority_score'=>$score,'priority'=>$score>=35?'high':($score>=15?'medium':'low'),'reasons'=>$reasons,'confidence'=>$confidence,'search_metrics'=>$m,'target_keywords'=>$targets,'business_value_state'=>'not_measured'];
        }
        usort($ranked,static fn($a,$b)=>$b['priority_score']<=>$a['priority_score'] ?: $a['page_index']<=>$b['page_index']);
        $selected=array_slice(array_column($ranked,'page_index'),0,$selectedLimit);if(isset($pages[0])&&SeoPagePolicy::classify($pages[0])['eligible']&&!in_array(0,$selected,true)&&$selectedLimit>0){array_pop($selected);array_unshift($selected,0);}
        return ['scope_version'=>SeoPagePolicy::VERSION,'eligible_count'=>count($ranked),'excluded_count'=>count($excluded),'excluded_pages'=>array_slice($excluded,0,100),'selected_indices'=>$selected,'pages'=>array_slice($ranked,0,50),'basis'=>'Heuristic task priority from observed page/search signals, not SEO quality, causation, revenue or a ranking prediction.','search_data_state'=>$metrics['gsc']['pages_state']??'not_available','research'=>$research];
    }
}
