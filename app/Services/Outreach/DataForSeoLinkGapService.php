<?php
/** Real referring-domain evidence only; no snapshot-derived or AI-invented prospects. */
class DataForSeoLinkGapService
{
    public function __construct(private Database $db,private ?DataForSeoClient $client=null) {}
    public static function gap(array $customer,array $competitors): array
    {
        $own=[];foreach($customer as $r){$d=DataForSeoRankingSource::normalizeHost((string)($r['domain']??''));if($d!=='')$own[$d]=true;}
        $by=[];foreach($competitors as $target=>$rows){$target=DataForSeoRankingSource::normalizeHost((string)$target);if($target==='')continue;
            foreach($rows as $row){$domain=DataForSeoRankingSource::normalizeHost((string)($row['domain']??''));if($domain===''||isset($own[$domain])||$domain===$target)continue;$by[$domain][$target]=true;}}
        $out=[];foreach($by as $domain=>$targets)if(count($targets)>=2)$out[]=['domain'=>$domain,'competitors'=>array_keys($targets),'source'=>'dataforseo_backlinks','business_type'=>'Measured referring domain','relevant_page'=>'https://'.$domain.'/','collaboration_idea'=>'راجع صلة الموقع وجودة الروابط قبل التواصل؛ لا يوجد إرسال تلقائي','signals'=>['linked_competitors'=>count($targets),'customer_link_observed'=>false,'coverage'=>'provider_snapshot']];
        usort($out,static fn($a,$b)=>count($b['competitors'])<=>count($a['competitors']));return $out;
    }
    public function discover(int $uid,int $wid): array
    {
        $site=(new SeoMarketService($this->db))->site($wid,$uid);$own=DataForSeoRankingSource::normalizeHost($site['main_url']);$targets=[];
        foreach($this->db->query('SELECT competitor_domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1 LIMIT 5',[$wid,$uid]) as $c){$d=DataForSeoRankingSource::normalizeHost($c['competitor_domain']);if($d!==''&&$d!==$own)$targets[$d]=true;}
        if(count($targets)<2)return ['available'=>false,'reason'=>'two_real_competitors_required','candidates'=>[]];
        $all=[];foreach(array_merge([$own],array_keys($targets)) as $domain){
            $cached=$this->db->query('SELECT result_json FROM seo_link_domain_snapshots WHERE website_id=? AND user_id=? AND target_domain=? AND fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)',[$wid,$uid,$domain]);
            if($cached){$all[$domain]=json_decode($cached[0]['result_json'],true,512,JSON_THROW_ON_ERROR);continue;}
            $r=($this->client??new DataForSeoClient())->call('backlinks/referring_domains/live',[[
                'target'=>$domain,'limit'=>1000,'include_subdomains'=>true,'backlinks_status_type'=>'live']],$wid,1,1.0);
            $result=$r['response']['tasks'][0]['result'][0]??[];
            if(empty($r['available'])||!is_array($result['items']??null))return ['available'=>false,'reason'=>$r['reason']??'invalid_backlink_response','candidates'=>[]];
            // Refuse truncated customer/competitor coverage: absence from a partial page is not absence of a backlink.
            if((int)($result['total_count']??count($result['items']))>count($result['items']))return ['available'=>false,'reason'=>'referring_domain_coverage_incomplete','candidates'=>[]];
            $all[$domain]=$result['items'];$this->db->exec('REPLACE INTO seo_link_domain_snapshots(website_id,user_id,target_domain,result_json,fetched_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',[$wid,$uid,$domain,json_encode($result['items'],JSON_THROW_ON_ERROR)]);
        }
        $customer=$all[$own];unset($all[$own]);return ['available'=>true,'source'=>'dataforseo_backlinks','candidates'=>self::gap($customer,$all),'note'=>'A monthly provider snapshot; absence is limited to reported live referring domains. Review each site before outreach.'];
    }
}
