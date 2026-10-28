<?php
if (!defined('ABSPATH')) exit;
/** Read installed plugins' explicit translation relations, including actual publication status. */
final class TourfectoInternational
{
    public static function map(): array {
        $map=[];$default=null;$id=(int)get_queried_object_id();
        if(defined('ICL_SITEPRESS_VERSION')){
            $languages=apply_filters('wpml_active_languages',null,['skip_missing'=>1]);
            $default=apply_filters('wpml_default_language',null);
            if(is_array($languages))foreach($languages as $code=>$language){
                if(is_singular()&&$id){
                    $translated=(int)apply_filters('wpml_object_id',$id,get_post_type($id),false,$code);
                    if(!$translated||get_post_status($translated)!=='publish')continue;
                    $url=get_permalink($translated);
                }elseif(is_front_page()&&!empty($language['url']))$url=$language['url'];
                else continue;
                $map[$code]=$url;
            }
        }elseif(function_exists('pll_get_post_translations')&&is_singular()&&$id){
            $default=function_exists('pll_default_language')?pll_default_language('slug'):null;
            foreach(pll_get_post_translations($id) as $code=>$translated)if(get_post_status($translated)==='publish')$map[$code]=get_permalink($translated);
        }elseif(function_exists('pll_languages_list')&&function_exists('pll_home_url')&&is_front_page()){
            $default=function_exists('pll_default_language')?pll_default_language('slug'):null;
            foreach(pll_languages_list(['fields'=>'slug']) as $code)$map[$code]=pll_home_url($code);
        }
        return self::clean($map,$default);
    }
    public static function clean(array $map,?string $default=null): array {
        $out=[];foreach($map as $language=>$url){
            $language=strtolower(str_replace('_','-',(string)$language));$p=is_string($url)?parse_url($url):false;
            if(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$language)&&$p&&($p['scheme']??'')==='https'&&filter_var($url,FILTER_VALIDATE_URL)&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['query'])&&!isset($p['fragment']))$out[$language]=$url;
        }
        if(count($out)<2)return [];
        $default=strtolower(str_replace('_','-',(string)$default));if(isset($out[$default]))$out['x-default']=$out[$default];ksort($out);return $out;
    }
    public static function head(string $head): string {
        $map=self::map();if(!$map)return $head;
        // A single reciprocal set; leave language markup alone if no verified plugin map exists.
        $head=preg_replace('~<link\b(?=[^>]*\brel\s*=\s*["\']alternate["\'])(?=[^>]*\bhreflang\s*=)[^>]*>~i','',$head);
        foreach($map as $language=>$url)$head.='<link rel="alternate" hreflang="'.esc_attr($language).'" href="'.esc_url($url).'">'."\n";
        return $head;
    }
    public static function keyRoute(): void {
        if(!function_exists('tf_edge_rules'))return;
        $key=(string)(tf_edge_rules()['indexnow_key']??'');
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        // Exact root key location, no query fallback or downloadable file on the platform host.
        if(!preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$key)||$path!=='/'.$key.'.txt')return;
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){status_header(405);exit;}
        status_header(200);header('Content-Type: text/plain; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: public, max-age=120');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $key;exit;
    }
}
add_filter('tourfecto_normalized_head',[TourfectoInternational::class,'head'],20);
add_action('template_redirect',[TourfectoInternational::class,'keyRoute'],-100);

add_filter('tourfecto_has_page_rules',static function($has){return $has || count(TourfectoInternational::map())>0;});
