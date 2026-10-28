<?php
/** Conservative marketing scope; exclusions do not change robots, canonicals or site content. */
final class SeoPagePolicy {
    public const VERSION=2;
    public static function route(string $url): array {
        $parsed=parse_url($url);if(!$parsed||!in_array($parsed['scheme']??'',['http','https'],true)||empty($parsed['host']))return ['eligible'=>false,'kind'=>'invalid','reason'=>'invalid_public_url'];$path=(string)($parsed['path']??'/');$path=strtolower(rawurldecode($path));$segments=array_values(array_filter(explode('/',trim($path,'/')),static fn($x)=>$x!==''));
        foreach($segments as $part){
            if(in_array($part,['quiz','cart','basket','checkout','account','my-account','login','logout','register','signup','sign-up','password-reset','reset-password','forgot-password','order-confirm','order-confirmation','order-received','thank-you','wp-admin','wp-json','admin','api'],true)||preg_match('/^(?:function-page(?:-|$)|member-packages-checkout|wp-login\.php$)/',$part))return ['eligible'=>false,'kind'=>'operational','reason'=>'operational_route'];
            if(in_array($part,['coming-soon','maintenance','become-local-expert','traveler-viator-affiliate','affiliate-search','st-layouts','st_layouts'],true)||preg_match('/^(?:footer-page|header-page|layout-page|template-page)(?:-|$)/',$part))return ['eligible'=>false,'kind'=>'theme_system','reason'=>'theme_or_maintenance_route'];
        }
        $kind=$path==='/'?'homepage':(preg_match('#/(?:tour|tours|st_tours|activity|activities|st_activity|product|products|service|services)(?:/|$)#',$path)?'service':(preg_match('#/(?:destination|destinations|location|locations)(?:/|$)#',$path)?'destination':'public_content'));
        return ['eligible'=>true,'kind'=>$kind,'reason'=>'public_route_candidate'];
    }
    public static function classify(array $p): array {
        $decision=self::route((string)($p['url']??''));if(!$decision['eligible'])return $decision;
        if(!empty($p['coming_soon']))return ['eligible'=>false,'kind'=>'theme_system','reason'=>'maintenance_observed'];
        if(preg_match('/\b(?:noindex|none)\b/i',(string)($p['robots']??'')))return ['eligible'=>false,'kind'=>'indexing_review','reason'=>'noindex_intent_review'];
        $canonical=(string)($p['canonical']??'');if($canonical!==''){
            $a=parse_url((string)($p['url']??''));$b=parse_url($canonical);
            if($b&&((!empty($b['host'])&&!WordPressConnector::hostsEqual((string)($a['host']??''),(string)$b['host']))||(rtrim((string)($b['path']??'/'),'/')!==rtrim((string)($a['path']??'/'),'/'))))return ['eligible'=>false,'kind'=>'canonical_review','reason'=>'alternate_canonical_review'];
        }
        return $decision;
    }
    public static function groups(array $pages,array $proposed=[]): array {
        $groups=[];
        foreach($pages as $p){$d=self::classify($p);$fields=[];
            if($d['eligible']){foreach(['title','description','h1','canonical'] as $field)if(empty($p[$field])&&!isset($proposed[$p['url'].':'.$field]))$fields[]=$field;}
            elseif($d['kind']==='indexing_review')$fields[]='indexing';elseif($d['kind']==='canonical_review')$fields[]='canonical';
            foreach($fields as $field){$groups[$field][]=['url'=>$p['url'],'reason'=>$d['reason'],'kind'=>$d['kind']];}
        }
        foreach($groups as &$rows){$unique=[];foreach($rows as $r)$unique[$r['url']]=$r;$rows=array_values($unique);}unset($rows);return $groups;
    }
}
