<?php
/** Alternates come from approved versions or a multilingual plugin, never URL inference. */
class HreflangService
{
    public static function language(string $language): bool {return (bool)preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$language);}
    public static function map(array $known,?string $default=null): array {
        $out=[];
        foreach($known as $language=>$url){
            if(!self::language((string)$language)||!is_string($url)||!self::url($url))continue;
            $out[$language]=$url;
        }
        if(count($out)<2)return [];
        if($default!==null&&self::url($default))$out['x-default']=$default;
        ksort($out);return $out;
    }
    public static function url(string $url): bool {
        $p=parse_url($url);return filter_var($url,FILTER_VALIDATE_URL)&&($p['scheme']??'')==='https'&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment'])&&!isset($p['query'])&&!preg_match('/[\x00-\x20<>"\']/', $url);
    }
    public static function html(array $map): string {
        $s='';foreach($map as $l=>$url)if(($l==='x-default'||self::language($l))&&self::url($url))$s.='<link rel="alternate" hreflang="'.htmlspecialchars($l,ENT_QUOTES,'UTF-8').'" href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">'."\n";return $s;
    }
}
