<?php
if(!defined('ABSPATH'))exit;
/** Explicit per-page metadata ownership. No site-wide activation or ranking promises. */
final class TourfectoNativeSeo {
    public static function adapter(): string {
        $plugins=[];
        if(defined('WPSEO_VERSION'))$plugins[]='yoast';
        if(defined('RANK_MATH_VERSION')||class_exists('RankMath'))$plugins[]='rankmath';
        if(defined('AIOSEO_VERSION')||function_exists('aioseo'))$plugins[]='unsupported';
        if(defined('SEOPRESS_VERSION')||defined('THE_SEO_FRAMEWORK_VERSION')||defined('SLIM_SEO_VERSION'))$plugins[]='unsupported';
        foreach((array)(function_exists('get_option')?get_option('active_plugins',[]):[]) as $slug)if(preg_match('~(?:all-in-one-seo(?:-pack)?(?:-pro)?|seo-by-rank-math(?:-pro)?|wordpress-seo(?:-premium)?|wp-seopress(?:-pro)?|autodescription|slim-seo)/~',$slug)){
            $kind=str_starts_with($slug,'seo-by-rank-math')?'rankmath':(str_starts_with($slug,'wordpress-seo')?'yoast':'unsupported');$plugins[]=$kind;
        }
        if(function_exists('get_site_option'))foreach(array_keys((array)get_site_option('active_sitewide_plugins',[])) as $slug)if(preg_match('~(?:all-in-one-seo(?:-pack)?(?:-pro)?|seo-by-rank-math(?:-pro)?|wordpress-seo(?:-premium)?|wp-seopress(?:-pro)?|autodescription|slim-seo)/~',$slug))$plugins[]=str_starts_with($slug,'seo-by-rank-math')?'rankmath':(str_starts_with($slug,'wordpress-seo')?'yoast':'unsupported');
        $plugins=array_values(array_unique($plugins));
        if(count($plugins)>1)return 'conflict';if($plugins)return $plugins[0];
        return defined('TF_NATIVE_HEAD_BRIDGE')&&TF_NATIVE_HEAD_BRIDGE&&function_exists('current_theme_supports')&&current_theme_supports('title-tag')?'native':'none';
    }
    public static function keys(string $adapter='native'): array {
        return match($adapter) {
            'native'=>['seo_title'=>'_tourfecto_native_title','seo_description'=>'_tourfecto_native_description','seo_keyword'=>'_tourfecto_native_keyword'],
            'yoast'=>['seo_title'=>'_yoast_wpseo_title','seo_description'=>'_yoast_wpseo_metadesc','seo_keyword'=>'_yoast_wpseo_focuskw'],
            'rankmath'=>['seo_title'=>'rank_math_title','seo_description'=>'rank_math_description','seo_keyword'=>'rank_math_focus_keyword'],
            default=>[],
        };
    }
    private static function pageId(): int {
        if(self::adapter()!=='native'||!function_exists('is_singular')||!is_singular())return 0;
        $id=(int)get_queried_object_id();$p=get_post($id);
        if(!$p||!in_array($p->post_status,['publish','draft','pending'],true)||!empty($p->post_password)||!in_array($p->post_type,['post','page','st_tours','st_hotel','st_activity','st_rental','st_cars','location'],true))return 0;
        return $id;
    }
    private static function value(int $id,string $key): ?string {if(!metadata_exists('post',$id,$key))return null;$v=get_post_meta($id,$key,true);return is_string($v)?$v:null;}
    public static function hasRules(bool $has): bool { $id=self::pageId();return $has||($id&&(self::value($id,'_tourfecto_native_title')!==null||self::value($id,'_tourfecto_native_description')!==null));}
    public static function rules(array $rules): array {
        $id=self::pageId();if(!$id)return $rules;
        foreach(['title'=>'_tourfecto_native_title','description'=>'_tourfecto_native_description'] as $field=>$key)if(self::value($id,$key)!==null){
            $value=self::value($id,$key);if($value!=='')$rules[$field]=$value;else unset($rules[$field]);
        }
        return $rules;
    }
    public static function normalize(string $head): string {
        $id=self::pageId();if(!$id)return $head;if(self::value($id,'_tourfecto_native_title')===null&&self::value($id,'_tourfecto_native_description')===null)return $head;
        $protected=[];$salt=bin2hex(random_bytes(8));$head=(string)preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1>/is',static function($m)use(&$protected,$salt){$key='<!--tf-native-'.$salt.'-'.count($protected).'-->';$protected[$key]=$m[0];return $key;},$head);$owned=false;$extra='';
        if(self::value($id,'_tourfecto_native_title')!==null){
            $value=self::value($id,'_tourfecto_native_title');$owned=true;
            if($value!==''){$head=(string)preg_replace('/<title\b[^>]*>.*?<\/title>/is','',$head);$extra.='<title>'.esc_html($value).'</title>';} 
        }
        if(self::value($id,'_tourfecto_native_description')!==null){
            $owned=true;$head=(string)preg_replace_callback('/<meta\b[^>]*>/i',static function($m){return preg_match('/\bname\s*=\s*(?:(["\x27])description\1|description(?=\s|\/?>))/i',$m[0])?'':$m[0];},$head);
            $value=self::value($id,'_tourfecto_native_description');if($value!=='')$extra.='<meta name="description" content="'.esc_attr($value).'">';
        }
        if(!$owned)return strtr($head,$protected);
        $head=(string)preg_replace('/<meta\b[^>]*\bname=["\x27]tourfecto-seo-owner["\x27][^>]*>/i','',$head);
        return strtr($head,$protected).$extra.'<meta name="tourfecto-seo-owner" content="native">';
    }
}
add_filter('tourfecto_page_rules',[TourfectoNativeSeo::class,'rules'],1000);
add_filter('tourfecto_normalized_head',[TourfectoNativeSeo::class,'normalize'],1000);

add_filter('tourfecto_has_page_rules',[TourfectoNativeSeo::class,'hasRules'],1000);
