<?php
/** Weekly GSC comparison. Missing position is unknown; a zero-click baseline never divides by zero. */
class ContentDecayService
{
    public function __construct(private Database $db, private $briefs=null, private $reader=null) {}
    public static function windows(?DateTimeImmutable $now=null): array
    {
        $end=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->modify('-2 days');
        return ['current'=>[$end->modify('-27 days')->format('Y-m-d'),$end->format('Y-m-d')],
            'previous'=>[$end->modify('-55 days')->format('Y-m-d'),$end->modify('-28 days')->format('Y-m-d')]];
    }
    public static function compare(array $previous,array $current): array
    {
        $before=max(0,(int)($previous['clicks']??0));$after=max(0,(int)($current['clicks']??0));
        $loss=$before>0?($before-$after)/$before:null;
        $old=isset($previous['position'])&&(float)$previous['position']>0?(float)$previous['position']:null;
        $new=isset($current['position'])&&(float)$current['position']>0?(float)$current['position']:null;
        $delta=$old!==null&&$new!==null?$new-$old:null;
        return ['refresh'=>($loss!==null&&$loss>0.25)||($delta!==null&&$delta>3), 'click_loss_ratio'=>$loss,'position_loss'=>$delta,'previous'=>$previous,'current'=>$current];
    }
    public static function refreshBrief(string $url,array $comparison,array $fresh,array $prior,?array $page,int $year): array
    {
        $oldQuestions=array_column($prior['people_also_ask']??[], 'question');
        if (!$oldQuestions) $oldQuestions=$prior['people_also_ask']??[];
        $new=array_values(array_diff($fresh['people_also_ask']??[],$oldQuestions));
        $text=$page['excerpt']??'';preg_match_all('/\b20\d{2}\b/',$text,$years);
        preg_match_all('/(?:[$€£]\s*\d[\d,.]*|(?:USD|EUR|EGP|GBP)\s*\d[\d,.]*|\d[\d,.]*\s*(?:USD|EUR|EGP|GBP))/u',$text,$prices);
        $oldRanks=[];foreach ($prior['ranking_pages']??[] as $p) $oldRanks[$p['url']]=$p['position'];
        $newRanks=[];foreach ($fresh['ranking_pages']??[] as $p) $newRanks[$p['url']]=$p['position'];
        $overtook=[];
        if (isset($oldRanks[$url],$newRanks[$url])) foreach ($newRanks as $competitor=>$rank) {
            if ($competitor!==$url && isset($oldRanks[$competitor]) && $oldRanks[$competitor]>$oldRanks[$url] && $rank<$newRanks[$url]) $overtook[]=['url'=>$competitor,'previous_position'=>$oldRanks[$competitor],'position'=>$rank];
        }
        return ['action'=>'refresh','page_url'=>$url,'comparison'=>$comparison,'content_brief'=>$fresh,
            'new_questions'=>$new,'years_to_confirm'=>array_values(array_unique(array_filter($years[0],static fn($y)=>(int)$y<$year))),
            'prices_to_confirm'=>array_values(array_unique($prices[0])), 'competitors_that_overtook'=>$overtook,
            'competitor_review_candidates'=>$fresh['ranking_pages']??[],
            'instructions'=>['Answer the new relevant questions using confirmed facts.', 'Ask the customer to confirm year-specific advice and every listed price; do not automatically replace a year or price.', 'Compare cited competitor headings and omissions. Overtaking is asserted only when both dated SERP snapshots show a crossing.', 'Review and approve the refresh before editing or republishing.'],
            'observed_at'=>gmdate('c')];
    }
    public function run(int $uid,int $wid,GoogleSearchConsoleAPI $api,string $property): array
    {
        $site=(new SeoMarketService($this->db))->site($wid,$uid);$host=(string)parse_url($site['main_url'],PHP_URL_HOST);
        $windows=self::windows();$old=$api->getSearchAnalytics($property,...array_merge($windows['previous'],[['page'],25000]));
        $new=$api->getSearchAnalytics($property,...array_merge($windows['current'],[['page'],25000]));
        if (empty($old['success'])||empty($new['success'])) return ['state'=>'unavailable','created'=>0];
        // A full page may be truncated. Do not turn omitted pages into fabricated zeroes.
        if (count($old['rows'])>=25000||count($new['rows'])>=25000) return ['state'=>'incomplete','created'=>0];
        $by=[];foreach ($new['rows'] as $row) $by[$row['page']]=$row;
        $created=0;$week=gmdate('Y-m-d',strtotime('monday this week UTC'));
        foreach ($old['rows'] as $row) {
            $url=(string)$row['page'];if (!ContentQualityService::pagePath($url,$host)) continue;
            $comparison=self::compare($row,$by[$url]??['clicks'=>0,'position'=>null]);if (!$comparison['refresh']) continue;
            $hash=hash('sha256',$url);
            if ($this->db->query('SELECT id FROM seo_content_refresh_tasks WHERE website_id=? AND page_hash=? AND week_start=?',[$wid,$hash,$week])) continue;
            $stored=$this->db->query('SELECT brief_json FROM seo_content_quality WHERE website_id=? AND user_id=? AND page_url=? ORDER BY id DESC LIMIT 1',[$wid,$uid,$url]);
            $prior=$stored?json_decode($stored[0]['brief_json'],true,512,JSON_THROW_ON_ERROR):[];$fresh=[];
            $keyword=$prior['keyword']??null;
            if (!$keyword) {
                $keywords=$api->getSearchAnalytics($property,$windows['current'][0],$windows['current'][1],['page','query'],25000);
                foreach ($keywords['rows']??[] as $q) if ($q['page']===$url) {$keyword=$q['query'];break;}
            }
            if ($keyword) {
                try {$fresh=($this->briefs??new ContentBriefService($this->db))->forKeyword($uid,$wid,$keyword,(int)($prior['market']['id']??0));}
                catch (Throwable $e) {$fresh=['keyword'=>$keyword,'research_state'=>'requires_current_keyword_SERP_and_confirmed_facts'];}
            }
            $page=($this->reader??new ContentBriefService($this->db))->fetchPage($url);
            $brief=self::refreshBrief($url,$comparison,$fresh,$prior,$page,(int)gmdate('Y'));$brief['windows']=$windows;
            $this->db->exec("INSERT IGNORE INTO seo_content_refresh_tasks(website_id,user_id,page_hash,page_url,week_start,brief_json,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())",[$wid,$uid,$hash,$url,$week,json_encode($brief,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$created++;
            if ($created>=20) break;
        }
        return ['state'=>'measured','created'=>$created,'windows'=>$windows];
    }
}
