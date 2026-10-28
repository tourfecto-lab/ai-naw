<?php
/** Real demand only. Cache keys include site, market, endpoint and input. */
class DataForSeoResearchService
{
    private const CALL_CEILING = 0.10;
    private DataForSeoClient $client;
    private ?Database $db;
    public function __construct(?Database $db=null,?DataForSeoClient $client=null) { $this->client=$client??new DataForSeoClient(); $this->db=$db; }
    public function configured(): bool { return $this->client->isConfigured(); }
    public static function parse(array $item): array
    {
        $k=$item['keyword_data']??$item;
        $info=$k['keyword_info']??[]; $props=$k['keyword_properties']??[];
        $intent=$k['search_intent_info']['main_intent']??null;
        return ['keyword'=>trim((string)($k['keyword']??'')),
            'volume'=>isset($info['search_volume'])?max(0,(int)$info['search_volume']):null,
            'difficulty'=>isset($props['keyword_difficulty'])?max(0,min(100,(float)$props['keyword_difficulty'])):null,
            'cpc'=>isset($info['cpc'])?max(0.0,(float)$info['cpc']):null,
            'intent'=>in_array($intent,['informational','navigational','commercial','transactional'],true)?$intent:null,
            'position'=>isset($item['ranked_serp_element']['serp_item']['rank_group'])?(int)$item['ranked_serp_element']['serp_item']['rank_group']:null,
            'url'=>$item['ranked_serp_element']['serp_item']['url']??null];
    }
    public function research(int $wid,int $uid,int $mid=0,array $seeds=[],bool $exact=false,int $resultLimit=100): array
    {
        if (!$this->configured()) return ['available'=>false,'reason'=>'not_measured','message'=>'اربط DataForSEO من الأدمن','data'=>[]];
        $db=$this->db??Database::getInstance(); $markets=new SeoMarketService($db); $site=$markets->site($wid,$uid); $market=$markets->resolve($wid,$uid,$mid);
        $host=DataForSeoRankingSource::normalizeHost((string)($site['main_url']??$site['domain']??''));
        $seeds=array_values(array_unique(array_filter(array_map('trim',$seeds))));
        $exact=$exact && (bool)$seeds;
        if($resultLimit<1||$resultLimit>100)throw new InvalidArgumentException('Invalid research result limit');
        if (count($seeds)>20) throw new InvalidArgumentException('Maximum 20 seed keywords');
        foreach ($seeds as $seed) if (mb_strlen($seed)>255) throw new InvalidArgumentException('Invalid seed keyword');
        foreach ($seeds as $seed) if (DataForSeoRankingSource::keywordPhrase($seed)!==$seed)
            throw new InvalidArgumentException('راجع الكلمات التي تحتوي نية البحث والأولوية بين الأقواس، ونقّها من صفحة الكلمات قبل القياس المدفوع.');
        $kind=$seeds?($exact?'keyword_overview':'keyword_ideas'):'keywords_for_site';
        $task=['location_code'=>(int)$market['location_code'],'language_code'=>$market['language_code'],'limit'=>$resultLimit,'include_serp_info'=>false];
        if ($seeds) $task['keywords']=$seeds; else $task['target']=$host;
        $key=hash('sha256',json_encode([$kind,$task]));
        $pdo=$db->getConnection(); $lock='seo-research:'.sha1($wid.'|'.$market['id'].'|'.$key);
        $s=$pdo->prepare('SELECT GET_LOCK(?,10)'); $s->execute([$lock]);
        if ((int)$s->fetchColumn()!==1) return ['available'=>false,'reason'=>'research_busy','data'=>[]];
        try {
        $cache=$db->query('SELECT result_json FROM seo_research_cache WHERE website_id=? AND market_id=? AND cache_key=? AND fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)',[$wid,$market['id'],$key]);
        if ($cache) return ['available'=>true,'from_cache'=>true,'source'=>'dataforseo','market'=>$market,'data'=>json_decode($cache[0]['result_json'],true,512,JSON_THROW_ON_ERROR)];
        $r=$this->client->call('dataforseo_labs/google/'.$kind.'/live',[$task],$wid,$exact?max(1,min(100,count($seeds))):1,self::CALL_CEILING);
        if (!$r['available']) return $r+['data'=>[]];
        $result=$r['response']['tasks'][0]['result'][0]??[];
        if (!array_key_exists('items',$result) || ($result['items']!==null && !is_array($result['items']))) return ['available'=>false,'reason'=>'dataforseo_invalid_response','data'=>[]];
        $data=[];
        foreach (array_slice(DataForSeoClient::items($r),0,$exact?min(count($seeds),$resultLimit):$resultLimit) as $item) {
            if (!is_array($item)) continue;
            $row=self::parse($item);
            if ($exact && !in_array(mb_strtolower($row['keyword']),array_map('mb_strtolower',$seeds),true)) continue;
            if ($row['keyword']!=='' && mb_strlen($row['keyword'])<=255) $data[]=$row;
        }
        if ($data) {
            $difficulty=$this->client->call('dataforseo_labs/google/bulk_keyword_difficulty/live',[[
                'location_code'=>(int)$market['location_code'],'language_code'=>$market['language_code'],'keywords'=>array_column($data,'keyword')]],$wid,max(1,min(100,count($data))),self::CALL_CEILING);
            if (!$difficulty['available']) return $difficulty+['data'=>[]];
            $by=[]; foreach (DataForSeoClient::items($difficulty) as $item)
                if (isset($item['keyword_difficulty'])) $by[mb_strtolower($item['keyword'])]=(float)$item['keyword_difficulty'];
            foreach ($data as &$row) $row['difficulty']=$by[mb_strtolower($row['keyword'])]??$row['difficulty']; unset($row);
        }
        $now=gmdate('Y-m-d H:i:s');
        $db->transaction(function () use ($db,$wid,$market,$data,$now,$key) {
            foreach ($data as $row) $db->exec("INSERT INTO seo_keyword_research (website_id,market_id,keyword,volume,difficulty,cpc,intent,source,fetched_at) VALUES (?,?,?,?,?,?,?,'dataforseo',?) ON DUPLICATE KEY UPDATE volume=VALUES(volume),difficulty=VALUES(difficulty),cpc=VALUES(cpc),intent=VALUES(intent),fetched_at=VALUES(fetched_at)",[$wid,$market['id'],$row['keyword'],$row['volume'],$row['difficulty'],$row['cpc'],$row['intent'],$now]);
            $db->exec('REPLACE INTO seo_research_cache (website_id,market_id,cache_key,result_json,fetched_at) VALUES (?,?,?,?,UTC_TIMESTAMP())',[$wid,$market['id'],$key,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)]);
        });
        return ['available'=>true,'from_cache'=>false,'source'=>'dataforseo','fetched_at'=>$now,'market'=>$market,'data'=>$data];
        } finally { $s=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$lock]); }
    }
    public function competitors(int $wid,int $uid,int $limit=50): array
    {
        if (!$this->configured()) return ['available'=>false,'reason'=>'not_measured'];
        $db=$this->db??Database::getInstance(); $market=(new SeoMarketService($db))->resolve($wid,$uid);
        $count=0;
        foreach ($db->query('SELECT id,competitor_domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1 AND monitoring_paused=0 LIMIT 5',[$wid,$uid]) as $c) {
            if ($db->query("SELECT id FROM competitor_keywords WHERE competitor_id=? AND market_id=? AND source='dataforseo' AND fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) LIMIT 1",[$c['id'],$market['id']])) continue;
            $host=DataForSeoRankingSource::normalizeHost($c['competitor_domain']); if ($host==='') continue;
            $r=$this->client->call('dataforseo_labs/google/ranked_keywords/live',[[
                'target'=>$host,'location_code'=>(int)$market['location_code'],'language_code'=>$market['language_code'],'limit'=>max(1,min(100,$limit)),'order_by'=>['keyword_data.keyword_info.search_volume,desc']]],$wid,1,self::CALL_CEILING);
            if (!$r['available']) return $r+['reason'=>'dataforseo_request_failed','recorded'=>$count];
            $result=$r['response']['tasks'][0]['result'][0]??[];
            if (!array_key_exists('items',$result) || ($result['items']!==null && !is_array($result['items']))) return ['available'=>false,'reason'=>'dataforseo_invalid_response','recorded'=>$count];
            $items=array_slice(DataForSeoClient::items($r),0,max(1,min(100,$limit)));
            $db->transaction(function () use ($db,$c,$market,$items,&$count) {
                $db->exec("DELETE FROM competitor_keywords WHERE competitor_id=? AND market_id=? AND source='dataforseo'",[$c['id'],$market['id']]);
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $row=self::parse($item); if ($row['keyword']==='' || mb_strlen($row['keyword'])>255) continue;
                    $db->exec("INSERT INTO competitor_keywords (competitor_id,market_id,keyword,search_volume,difficulty,position,url,source,fetched_at) VALUES (?,?,?,?,?,?,?,'dataforseo',UTC_TIMESTAMP())",[$c['id'],$market['id'],$row['keyword'],$row['volume'],$row['difficulty'],$row['position'],$row['url']]); $count++;
                }
            });
        }
        return ['available'=>true,'reason'=>null,'recorded'=>$count];
    }
}
