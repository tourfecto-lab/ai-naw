<?php
/** Conservative content targeting: measured demand cannot authorize unsupported services. */
final class SeoContentTargetSelector
{
    private static function words(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u',mb_strtolower($value),$matches);
        return array_values(array_diff(array_unique($matches[0]),['a','an','the','and','or','in','on','of','to','for','from','with','best','top','tour','tours','trip','trips','day','days','guide','guides','private','رحلة','رحلات','في','من','الى','إلى','أفضل','افضل']));
    }
    public static function select(array $rows,array $profile,array $pages,string $siteUrl,int $marketId,?int $now=null): ?array
    {
        if(empty($profile['confirmed_at'])||!empty($profile['needs_reconfirmation'])||$marketId<1)return null;
        $facts=$profile['facts']??[];
        // Audience countries and campaign wishes are not confirmed destinations or services.
        $supported=self::words(implode(' ',array_filter(array_map(static fn($k)=>is_string($facts[$k]??null)?$facts[$k]:'',['business_name','business_facts','services','destinations']))));
        $now=$now??time();$eligible=[];
        foreach($rows as $row){
            $stamp=(string)($row['fetched_at']??'');$date=$stamp!==''?strtotime($stamp.' UTC'):false;
            if(($row['source']??'')!=='dataforseo'||(int)($row['market_id']??0)!==$marketId||!is_numeric($row['volume']??null)||(float)$row['volume']<0||!$date||$date<$now-30*86400||$date>$now+300)continue;
            $keyword=trim((string)($row['keyword']??''));$terms=self::words($keyword);
            if(!$terms||array_diff($terms,$supported)||preg_match('/navigational|branded/i',(string)($row['intent']??'')))continue;
            $pillar=ContentQualityService::cluster($keyword,$pages,$siteUrl);if(!$pillar)continue;
            $pillarTerms=self::words($pillar['title'].' '.rawurldecode((string)parse_url($pillar['url'],PHP_URL_PATH)));
            $coverage=count(array_intersect($terms,$pillarTerms))/count($terms);
            $row['pillar']=$pillar;$row['selection_reason']='Confirmed service and destination terms; relevant public pillar; measured difficulty and demand.';
            $difficulty=is_numeric($row['difficulty']??null)?max(0,(float)$row['difficulty']):101;
            $eligible[]=['row'=>$row,'coverage'=>$coverage,'difficulty'=>$difficulty];
        }
        usort($eligible,static fn($a,$b)=>($b['coverage']<=>$a['coverage'])?:($a['difficulty']<=>$b['difficulty'])?:((float)$b['row']['volume']<=>(float)$a['row']['volume'])?:strcmp($a['row']['keyword'],$b['row']['keyword']));
        return $eligible[0]['row']??null;
    }
}
