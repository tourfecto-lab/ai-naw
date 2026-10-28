<?php
if(!defined('ABSPATH'))exit;
/** Adapter reviewed against the user-supplied Traveler 3.4 source. Never imports a license key. */
final class TourfectoTraveler {
    public static function active($post): bool {
        if($post->post_type!=='st_tours'||!function_exists('wp_get_theme'))return false;
        $theme=wp_get_theme();if($theme->get_template()!=='traveler')return false;
        return trim((string)wp_get_theme('traveler')->get('Version'))==='3.4';
    }
    private static function keys(string $style): array {
        $keys=['tours_include','tours_exclude','tours_highlight','tours_faq'];
        $program=[''=>'tours_program','style1'=>'tours_program','style3'=>'tours_program','style2'=>'tours_program_bgr','style4'=>'tours_program_style4'][$style]??null;
        if($program)$keys[]=$program;return $keys;
    }
    private static function guard(int $id,array $excluded): string {
        $all=get_post_meta($id);foreach(array_merge($excluded,['_tourfecto_editor_history','_tourfecto_settings_history','_edit_lock','_edit_last','_elementor_css']) as $key)unset($all[$key]);ksort($all);return hash('sha256',wp_json_encode($all));
    }
    private static function fingerprints(int $id,array $excluded): array {
        $all=get_post_meta($id);foreach(array_merge($excluded,['_tourfecto_editor_history','_tourfecto_settings_history','_edit_lock','_edit_last','_elementor_css']) as $key)unset($all[$key]);ksort($all);
        if(count($all)>1000)throw new RuntimeException('Protected tour metadata exceeds diagnostic limits. Review this tour in WordPress.',422);
        $out=[];foreach($all as $key=>$value)$out[$key]=hash_hmac('sha256',wp_json_encode($value),wp_salt('auth'));return $out;
    }
    public static function snapshot($post,array $seoKeys): ?array {
        if(!self::active($post))return null;
        $style=(string)get_post_meta($post->ID,'tours_program_style',true);$keys=self::keys($style);$meta=[];$valid=true;
        foreach($keys as $key){$exists=metadata_exists('post',$post->ID,$key);$value=$exists?get_post_meta($post->ID,$key,true):'';
            if(in_array($key,['tours_include','tours_exclude','tours_highlight'],true)){if(!is_string($value)||strlen($value)>20000)$valid=false;}
            else if($exists&&$value!==''){
                if(!is_array($value)||count($value)>100||array_values($value)!==$value)$valid=false;
                else foreach($value as $row){if(!is_array($row)||!is_string($row['title']??null)||!is_string($row['desc']??null)||strlen($row['title'])>1000||strlen($row['desc'])>10000)$valid=false;}
            }
            $meta[$key]=['exists'=>$exists,'value'=>$value];
        }
        if(strlen(wp_json_encode($meta))>100000)$valid=false;
        // Unknown/custom keys, program style and inactive itineraries remain in the guard hash.
        return ['adapter'=>'traveler_3_4','supported'=>$valid,'style'=>$style,'meta'=>$valid?$meta:[],'protected_hash'=>self::guard($post->ID,array_merge($valid?$keys:[],$seoKeys)),'protected_fingerprints'=>self::fingerprints($post->ID,array_merge($valid?$keys:[],$seoKeys)),'note'=>$valid?'Traveler 3.4 text fields; existing images, itinerary times and commercial fields are preserved.':'Unsupported field shape/size; dedicated inspection required.'];
    }
    private static function text($value,string $field): string {
        if(!is_string($value)||strlen($value)>($field==='title'?1000:($field==='desc'?10000:20000))||strpbrk($value,'[]')!==false||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new InvalidArgumentException('Invalid tour text, size or shortcode.');
        $clean=$field==='title'?sanitize_text_field($value):wp_kses_post($value);if($clean!==$value)throw new InvalidArgumentException('Unsupported markup; use safe text or supported HTML.');
        if($field==='title'&&trim($value)==='')throw new InvalidArgumentException('A row title cannot be empty.');return $value;
    }
    public static function prepare(array $state,array $in): array {
        if(empty($state['supported']))throw new InvalidArgumentException('Traveler 3.4 adapter is unavailable for this page.');
        if(($in['facts_confirmed']??false)!==true)throw new InvalidArgumentException('Confirm the actual inclusions, exclusions and itinerary facts before previewing.');
        $after=$state;$changes=$in['tour_changes']??[];$rows=$in['row_changes']??[];$append=$in['append_faq']??[];
        if(!is_array($changes)||count($changes)>3||!is_array($rows)||count($rows)>30||!is_array($append)||count($append)>10)throw new InvalidArgumentException('Invalid tour changes.');
        foreach($changes as $key=>$value){if(!in_array($key,['tours_include','tours_exclude','tours_highlight'],true))throw new InvalidArgumentException('Only supported tour text fields may be edited.');$value=self::text($value,$key);if($value===($state['meta'][$key]['value']??''))continue;$after['meta'][$key]=['exists'=>true,'value'=>$value];}
        $seen=[];foreach($rows as $change){$key=$change['key']??'';$index=$change['index']??null;$field=$change['field']??'';
            if(!is_string($key)||!isset($state['meta'][$key])||!is_int($index)||!is_array($state['meta'][$key]['value'])||!isset($state['meta'][$key]['value'][$index])||!in_array($field,['title','desc'],true)||isset($seen[$key.':'.$index.':'.$field]))throw new InvalidArgumentException('Unsupported or duplicate itinerary/FAQ change.');
            $seen[$key.':'.$index.':'.$field]=true;$after['meta'][$key]['value'][$index][$field]=self::text($change['text']??null,$field);
        }
        if($append){$current=$after['meta']['tours_faq']['value'];if($current==='')$current=[];if(count($current)+count($append)>100)throw new InvalidArgumentException('FAQ row limit reached.');foreach($append as $row){if(!is_array($row)||array_diff(array_keys($row),['title','desc']))throw new InvalidArgumentException('Only FAQ question and answer are accepted.');$current[]=['title'=>self::text($row['title']??null,'title'),'desc'=>self::text($row['desc']??null,'desc')];}$after['meta']['tours_faq']=['exists'=>true,'value'=>$current];}
        if(strlen(wp_json_encode($after['meta']))>100000)throw new InvalidArgumentException('Tour text exceeds the supported total size.');if($after===$state)throw new InvalidArgumentException('No tour values changed.');return $after;
    }
    public static function write(int $id,array $target): void {
        $p=get_post($id);if(empty($target['supported']))return;if(!$p||!self::active($p))throw new InvalidArgumentException('Traveler version or adapter changed.');
        foreach($target['meta'] as $key=>$m){if(!in_array($key,self::keys((string)$target['style']),true))throw new InvalidArgumentException('Unknown tour metadata key.');
            $exists=metadata_exists('post',$id,$key);$value=$exists?get_post_meta($id,$key,true):'';
            if($exists===$m['exists']&&$value===$m['value'])continue;
            if($m['exists'])update_post_meta($id,$key,wp_slash($m['value']));else delete_post_meta($id,$key);
        }
    }
}
