<?php
/**
 * Which websites the AI engines cite in the stored answers, and which of them back competitors but not you.
 * Reads seo_ai_answers only: no provider calls and no extra cost.
 */
final class AiCitationSourcesService
{
    /** Host suffix => category. Suffix match keeps regional domains (tripadvisor.co.uk) in the same group. */
    private const CATEGORIES=[
        'tripadvisor'=>'reviews','trustpilot'=>'reviews','reddit.com'=>'forums','quora.com'=>'forums',
        'booking.com'=>'ota','expedia'=>'ota','viator.com'=>'ota','getyourguide'=>'ota','klook.com'=>'ota','airbnb'=>'ota','hotels.com'=>'ota','agoda.com'=>'ota','trip.com'=>'ota','kayak'=>'ota','musement.com'=>'ota','civitatis.com'=>'ota',
        'youtube.com'=>'social','facebook.com'=>'social','instagram.com'=>'social','tiktok.com'=>'social','x.com'=>'social','twitter.com'=>'social','linkedin.com'=>'social',
        'wikipedia.org'=>'reference','wikivoyage.org'=>'reference','britannica.com'=>'reference',
        'lonelyplanet.com'=>'media','timeout.com'=>'media','cntraveler.com'=>'media','travelandleisure.com'=>'media','nationalgeographic.com'=>'media','theculturetrip.com'=>'media','forbes.com'=>'media','nytimes.com'=>'media',
        'google.com'=>'maps','maps.app.goo.gl'=>'maps',
    ];
    private Database $db;
    public function __construct(?Database $db=null){$this->db=$db??Database::getInstance();}
    public static function category(string $host,string $own,array $competitors): string
    {
        if ($own!=='' && DataForSeoRankingSource::matchesHost($host,$own)) return 'own';
        foreach ($competitors as $c) if ($c!=='' && DataForSeoRankingSource::matchesHost($host,$c)) return 'competitor';
        foreach (self::CATEGORIES as $suffix=>$cat) {
            if ($host===$suffix || str_ends_with($host,'.'.$suffix)) return $cat;
            // Brand stems without a TLD (tripadvisor, expedia) cover every regional domain.
            if (!str_contains($suffix,'.') && preg_match('/(?:^|\.)'.preg_quote($suffix,'/').'\.[a-z.]{2,12}$/',$host)) return $cat;
        }
        if (preg_match('/\.(?:gov|gob|gouv)(?:\.[a-z]{2})?$/',$host)) return 'government';
        return 'other';
    }
    /** Pure aggregation so it can be tested without a database. */
    public static function summarize(array $answers,string $own,array $competitors,int $limit=40): array
    {
        $own=DataForSeoRankingSource::normalizeHost($own);$competitors=array_values(array_filter(array_map([DataForSeoRankingSource::class,'normalizeHost'],$competitors)));
        $hosts=[];$withSources=0;$total=0;
        foreach ($answers as $a) {
            $total++;
            $urls=is_string($a['cited_urls']??null)?json_decode($a['cited_urls'],true):($a['cited_urls']??[]);
            $rivals=is_string($a['competitors_mentioned']??null)?json_decode($a['competitors_mentioned'],true):($a['competitors_mentioned']??[]);
            $seen=[];
            foreach (is_array($urls)?$urls:[] as $u) { $h=is_string($u)?DataForSeoRankingSource::normalizeHost($u):''; if ($h!=='') $seen[$h]=$u; }
            if (!$seen) continue;
            $withSources++;$brand=(bool)($a['mentioned_brand']??false);$rivalOnly=!$brand && is_array($rivals) && $rivals;
            foreach ($seen as $h=>$url) {
                $row=$hosts[$h]??['host'=>$h,'category'=>self::category($h,$own,$competitors),'answers'=>0,'with_brand'=>0,'competitor_only'=>0,'providers'=>[],'example_url'=>$url,'example_question'=>(string)($a['question']??'')];
                $row['answers']++;if($brand)$row['with_brand']++;if($rivalOnly)$row['competitor_only']++;$row['providers'][(string)($a['provider']??'')]=true;
                $hosts[$h]=$row;
            }
        }
        foreach ($hosts as &$r) { $r['providers']=array_values(array_filter(array_keys($r['providers'])));$r['share']=$withSources?$r['answers']/$withSources:null; } unset($r);
        $sources=array_values($hosts);usort($sources,fn($a,$b)=>[$b['answers'],$a['host']]<=>[$a['answers'],$b['host']]);
        // Third-party pages that keep appearing beside competitors while your company is absent.
        $gaps=array_values(array_filter($sources,fn($r)=>$r['competitor_only']>0 && $r['with_brand']===0 && !in_array($r['category'],['own','competitor'],true)));
        usort($gaps,fn($a,$b)=>[$b['competitor_only'],$b['answers']]<=>[$a['competitor_only'],$a['answers']]);
        $categories=[];foreach ($sources as $r) $categories[$r['category']]=($categories[$r['category']]??0)+$r['answers'];arsort($categories);
        return ['answers'=>$total,'answers_with_sources'=>$withSources,'sources'=>array_slice($sources,0,$limit),'gaps'=>array_slice($gaps,0,15),'categories'=>$categories,
            'basis'=>'Distinct hosts cited per stored grounded answer over the last 8 weeks; a host counts once per answer.'];
    }
    public function forSite(int $uid,int $wid,int $mid=0,string $provider=''): array
    {
        $site=$this->db->getConnection()->prepare('SELECT * FROM websites WHERE id=? AND user_id=?');$site->execute([$wid,$uid]);$s=$site->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new InvalidArgumentException('الموقع مش موجود');
        $sql='SELECT provider,question,mentioned_brand,cited_urls,competitors_mentioned FROM seo_ai_answers WHERE website_id=? AND user_id=? AND week_start>=DATE_SUB(UTC_DATE(),INTERVAL 8 WEEK)';$args=[$wid,$uid];
        if ($mid) {$sql.=' AND market_id=?';$args[]=$mid;}
        if ($provider!=='') {$sql.=' AND provider=?';$args[]=$provider;}
        $q=$this->db->getConnection()->prepare($sql.' ORDER BY checked_at DESC LIMIT 2000');$q->execute($args);
        $c=$this->db->getConnection()->prepare('SELECT competitor_domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1');$c->execute([$wid,$uid]);
        return self::summarize($q->fetchAll(PDO::FETCH_ASSOC),(string)(($s['main_url']??'')?:($s['domain']??'')),$c->fetchAll(PDO::FETCH_COLUMN));
    }
}
