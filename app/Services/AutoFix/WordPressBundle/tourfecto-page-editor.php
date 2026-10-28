<?php
/**
 * Module Name: Tourfecto Page Editor
 * Description: Authenticated previews, original-page edits and guarded restores for Tourfecto.
 * Version: 1.8.0
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/tourfecto-block-text.php';
require_once __DIR__.'/tourfecto-traveler.php';
require_once __DIR__.'/tourfecto-tour-draft.php';
require_once __DIR__.'/tourfecto-tour-settings.php';
require_once __DIR__.'/tourfecto-native-seo.php';
require_once __DIR__.'/tourfecto-content-seo.php';
require_once __DIR__.'/tourfecto-tour-calendar.php';
final class TourfectoPageEditor {
    public static function routes(): void {
        foreach (['list','read','resolve','preview','apply','history','restore','discover','capabilities','block-preview','traveler-preview','workspace-preview','verify','status','tour-calendar-read','tour-create-preview','tour-create','tour-create-status','tour-settings-read','tour-settings-preview','tour-settings-apply','tour-settings-status','tour-settings-history','tour-settings-restore'] as $op) {
            register_rest_route('tourfecto-editor/v1', '/'.$op, [
                'methods'=>'POST', 'permission_callback'=>[self::class,'permission'],
                'callback'=>static function($request) use ($op) { $result=self::dispatch($op,$request);if(!is_wp_error($result))$result['site_url']=home_url();return $result; }
            ]);
        }
    }
    public static function permission() {
        return is_user_logged_in() && current_user_can('edit_posts') ? true : new WP_Error('tf_auth','An authenticated WordPress editor is required.',['status'=>403]);
    }
    private static function fail(string $message,int $status=422): void { throw new RuntimeException($message,$status); }
    private static function contentTypes(): array {
        // Explicit editorial types only; publicly registered order/coupon/template types are not content.
        return array_values(array_intersect(['post','page','st_tours','st_hotel','st_activity','st_rental','st_cars','location'],array_keys(get_post_types(['public'=>true]))));
    }
    private static function post(int $id) {
        $p=get_post($id);$type=$p?get_post_type_object($p->post_type):null;
        if (!$p || !$type || !$type->public || !in_array($p->post_type,self::contentTypes(),true) || !in_array($p->post_status,['publish','draft','pending'],true) || !empty($p->post_password) || !current_user_can('edit_post',$id)) self::fail('Editable page unavailable or permission denied.',403);
        return $p;
    }
    private static function adapter(): string {return TourfectoNativeSeo::adapter();}
    private static function keys(string $adapter): array {
        return TourfectoNativeSeo::keys($adapter);
    }
    private static function otherBuilder($p): bool {
        return get_post_meta($p->ID,'_elementor_edit_mode',true)==='builder'||get_post_meta($p->ID,'_elementor_data',true)!==''||preg_match('/\[(?:vc_|et_pb_|fusion_)/i',$p->post_content)===1;
    }
    private static function builder($p): bool {
        return get_post_meta($p->ID,'_elementor_edit_mode',true)==='builder' || get_post_meta($p->ID,'_elementor_data',true)!=='' || has_blocks($p->post_content) || preg_match('/\[(?:vc_|et_pb_|fusion_)/i',$p->post_content)===1;
    }
    private static function snapshot($p): array {
        $adapter=self::adapter();$meta=[];
        foreach (self::keys($adapter) as $field=>$key){$value=get_post_meta($p->ID,$key,true);if(!is_string($value))self::fail('Unsupported SEO metadata shape. Review this field in WordPress before editing.');$meta[$field]=['exists'=>metadata_exists('post',$p->ID,$key),'value'=>$value];}
        $state=['id'=>(int)$p->ID,'type'=>$p->post_type,'status'=>$p->post_status,'title'=>$p->post_title,'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'adapter'=>$adapter,'meta'=>$meta,'builder'=>self::builder($p)];$excluded=array_values(self::keys($adapter));if($p->post_status==='draft'&&TourfectoTraveler::active($p)&&current_user_can('manage_options')){try{$state['native_settings']=TourfectoTourSettings::editorState((int)$p->ID);$excluded=array_merge($excluded,TourfectoTourSettings::editorKeys());}catch(Throwable $e){}}$tour=TourfectoTraveler::snapshot($p,$excluded);if($tour!==null)$state['traveler']=$tour;return $state;
    }
    public static function resolveIdentity(string $url): int {
        if(strlen($url)>2000 || preg_match('/[\x00-\x20\\\\]/',$url))self::fail('Provide a valid public HTTPS page URL.');
        $u=parse_url($url);$home=parse_url(home_url());
        if(!is_array($u)||!is_array($home)||($u['scheme']??'')!=='https'||($home['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment']))self::fail('Use a public HTTPS URL without credentials, query or fragment.');
        $host=static fn($h)=>preg_replace('/^www\./','',strtolower((string)$h));
        if($host($u['host']??'')!==$host($home['host']??'')||($u['port']??443)!==($home['port']??443))self::fail('Page URL belongs to another site.',403);
        $path=(string)($u['path']??'/');$base=rtrim((string)($home['path']??''),'/');
        if(preg_match('/%(?:2f|5c|00)/i',$path)||preg_match('#(?:^|/)\.\.?(/|$)#',rawurldecode($path))||($base!==''&&$path!==$base&&!str_starts_with($path,$base.'/')))self::fail('Page URL is outside the WordPress site path.',403);
        $local=rtrim(home_url(),'/').($base!==''?substr($path,strlen($base)):$path);
        $id=(int)url_to_postid($local);
        if(!$id&&rtrim($path,'/')===$base&&get_option('show_on_front')==='page')$id=(int)get_option('page_on_front');
        if(!$id)self::fail('No original published page matched this URL. Search by title or ID; archive and dynamic home pages need a separate adapter.',404);
        $p=self::post($id);if($p->post_status!=='publish')self::fail('The task URL must resolve to a published original page.',403);
        $canonical=parse_url(get_permalink($p));
        if(!is_array($canonical)||isset($canonical['query'])||$host($canonical['host']??'')!==$host($home['host']??'')||($canonical['port']??443)!==($home['port']??443)||rtrim((string)($canonical['path']??'/'),'/')!==rtrim($path,'/'))self::fail('The URL did not match the exact original page. Review redirects or select the page manually.',409);
        return $id;
    }
    private static function resolve(string $url): array {return self::view(self::post(self::resolveIdentity($url)));}
    private static function hash(array $state): string { if(isset($state['native_settings']))unset($state['native_settings']['language_ids']);return hash('sha256',wp_json_encode($state)); }
    private static function view($p): array {
        $s=self::snapshot($p);return ['id'=>(int)$p->ID,'type'=>$p->post_type,'status'=>$p->post_status,'url'=>get_permalink($p),'preview_url'=>$p->post_status!=='publish'&&function_exists('get_preview_post_link')?get_preview_post_link($p):get_permalink($p),'revision'=>self::hash($s),'title'=>$s['title'],'content'=>$s['content'],'excerpt'=>$s['excerpt'],'seo_title'=>$s['meta']['seo_title']['value']??'','seo_description'=>$s['meta']['seo_description']['value']??'','seo_keyword'=>$s['meta']['seo_keyword']['value']??'','native_settings'=>$s['native_settings']??null,'calendar_readable'=>current_user_can('manage_options')&&TourfectoTraveler::active($p),'adapter'=>$s['adapter'],'content_editable'=>!$s['builder'],'block_editor'=>TourfectoBlockText::inspect($s['content'],self::otherBuilder($p)),'traveler_editor'=>$s['traveler']??null,'note'=>$s['builder']?'Builder content is protected. Eligible plain Gutenberg text is editable separately; formatted/custom/Elementor fields require an adapter.':'Classic post_content is supported; custom tour fields need a theme adapter.'];
    }
    private static function pack(array $plan): string {
        $encoded=rtrim(strtr(base64_encode(wp_json_encode($plan)),'+/','-_'),'=');
        return $encoded.'.'.hash_hmac('sha256',$encoded,wp_salt('auth'));
    }
    private static function unpack(string $token): array {
        if (strlen($token)>600000) self::fail('Preview exceeds the safe size limit.');
        $parts=explode('.',$token);if(count($parts)!==2 || !hash_equals(hash_hmac('sha256',$parts[0],wp_salt('auth')),$parts[1])) self::fail('Invalid preview.',403);
        $j=json_decode((string)base64_decode(strtr($parts[0],'-_','+/'),true),true);
        if(!is_array($j) || ($j['user']??0)!==get_current_user_id() || ($j['expires']??0)<time() || ($j['site']??'')!==home_url()) self::fail('Preview expired or belongs to another account/site.',409);
        return $j;
    }
    private static function preview(array $before,array $after,array $fields,string $mode='edit',?string $restoreId=null): array {
        if (!$fields) self::fail('No changes selected.');
        $plan=['id'=>$before['id'],'user'=>get_current_user_id(),'site'=>home_url(),'expires'=>time()+900,'request'=>wp_generate_uuid4(),'before'=>$before,'after'=>$after,'fields'=>$fields,'mode'=>$mode,'restore_id'=>$restoreId];
        $token=self::pack($plan);if(strlen($token)>600000)self::fail('Preview exceeds the safe size limit.');
        return ['state'=>'preview','preview_token'=>$token,'expires_at'=>gmdate('c',$plan['expires']),'fields'=>$fields,'before'=>$before,'after'=>$after,'note'=>'Review the exact fields. No page has been changed.'];
    }
    private static function prepareState($p,array $input): array {
        $before=self::snapshot($p);if(!hash_equals(self::hash($before),(string)($input['revision']??''))) self::fail('Page changed. Reload before preparing a preview.',409);
        $changes=$input['changes']??null;if(!is_array($changes) || count($changes)>6) self::fail('Invalid changes.');
        $after=$before;$fields=[];
        foreach($changes as $field=>$value) {
            if(!in_array($field,['title','content','excerpt','seo_title','seo_description','seo_keyword'],true) || !is_string($value)) self::fail('Unsupported field.');
            $limit=in_array($field,['content','excerpt'],true)?60000:1000;if(strlen($value)>$limit) self::fail('Field exceeds the size limit.');
            if($field==='content' && ($before['builder'] || has_blocks($value) || preg_match('/\[(?:vc_|et_pb_|fusion_)/i',$value))) self::fail('Builder and block content need a dedicated adapter; content was not changed.');
            $clean=in_array($field,['content','excerpt'],true)?wp_kses_post($value):sanitize_text_field($value);
            if($clean!==$value) self::fail('HTML or text contains unsupported markup. Remove it and preview again.');
            if($field==='title' && trim($value)==='') self::fail('Page title cannot be empty.');
            if(str_starts_with($field,'seo_')) {
                if(!isset(self::keys($before['adapter'])[$field])) self::fail('Enable exactly one supported SEO writer (Tourfecto native, Yoast or Rank Math) for SEO field writes.');
                $after['meta'][$field]=['exists'=>true,'value'=>$value];
            } else $after[$field]=$value;
            if($after!==$before) $fields[]=$field;
        }
        return [$before,$after,array_keys($changes)];
    }
    private static function nativeChanged(array $a,array $b): bool {
        $left=$a['native_settings']??null;$right=$b['native_settings']??null;
        if(is_array($left))unset($left['language_ids']);if(is_array($right))unset($right['language_ids']);return $left!==$right;
    }
    private static function workspacePreview($p,array $input): array {
        if(array_diff(array_keys($input),['id','revision','changes','block_changes','tour_changes','row_changes','append_faq','facts_confirmed','settings']))self::fail('Unsupported workspace fields.');
        $input['changes']=$input['changes']??[];[$before,$after,$fields]=self::prepareState($p,$input);
        if(!empty($input['block_changes'])){
            if(isset($input['changes']['content']))self::fail('Choose classic content or block changes, not both.');
            $after['content']=TourfectoBlockText::change($before['content'],$input['block_changes'],self::otherBuilder($p));$fields[]='content';
        }
        if(!empty($input['tour_changes'])||!empty($input['row_changes'])||!empty($input['append_faq'])){
            if(!isset($before['traveler']))self::fail('A supported Traveler tour is required.');
            $after['traveler']=TourfectoTraveler::prepare($before['traveler'],$input);$fields[]='traveler';
        }
        if(array_key_exists('settings',$input)){
            if(!isset($before['native_settings'])||!is_array($input['settings']))self::fail('Native settings editing requires an administrator and a supported Traveler 3.4 draft.');
            $after['native_settings']=TourfectoTourSettings::editorPrepare((int)$p->ID,$input['settings']);
            if(self::nativeChanged($before,$after))$fields[]='native_settings';
        }
        if(self::hash($after)===self::hash($before))self::fail('No values changed.');
        return self::preview($before,$after,array_values(array_unique($fields)),'workspace');
    }
    private static function plain(string $value): string {
        $value=html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8');return trim((string)preg_replace('/\s+/u',' ',$value));
    }
    private static function diagnosis(array $expected,array $actual): array {
        $groups=[];$keys=[];
        foreach(['title','content','excerpt','status','type','adapter','builder','meta'] as $field)if(($expected[$field]??null)!==($actual[$field]??null))$groups[]=$field;
        if(self::nativeChanged($expected,$actual))$groups[]='native_settings';
        $et=$expected['traveler']??null;$at=$actual['traveler']??null;
        if(is_array($et)&&is_array($at)){
            if(($et['meta']??null)!==($at['meta']??null))$groups[]='traveler_text';
            foreach(['adapter','supported','style'] as $field)if(($et[$field]??null)!==($at[$field]??null))$groups[]='traveler_adapter';
            if(($et['protected_hash']??null)!==($at['protected_hash']??null)){
                $groups[]='protected_metadata';
                if(isset($et['protected_fingerprints'],$at['protected_fingerprints']))foreach(array_unique(array_merge(array_keys($et['protected_fingerprints']),array_keys($at['protected_fingerprints']))) as $key){
                    if(($et['protected_fingerprints'][$key]??null)!==($at['protected_fingerprints'][$key]??null)&&count($keys)<50)$keys[]=preg_match('/secret|password|token|credential|nonce|api.?key/i',$key)?'sensitive_metadata':sanitize_text_field((string)$key);
                }
            }
        }elseif($et!==$at)$groups[]='traveler_adapter';
        $groups=array_values(array_unique($groups));
        return ['difference_groups'=>$groups,'protected_metadata_keys'=>array_values(array_unique($keys)),'selected_values_state'=>array_diff($groups,['protected_metadata'])?'needs_review':'verified','note'=>'Protected-value fingerprints are compared without exporting their values. A protected change remains a review condition; it is never ignored or automatically overwritten.'];
    }
    private static function verification($p,array $logs,string $key,bool $includePublic): array {
        $entry=$logs[$key]??null;if(!$entry)return ['state'=>'not_started','operation_id'=>$key,'stored_state'=>'not_started','page'=>self::view($p),'public_state'=>'not_checked','expectations'=>[],'note'=>'No durable operation record was found. Review the current fields before preparing another edit.'];
        $now=self::snapshot($p);$ok=self::hash($now)===self::hash($entry['after'])&&empty($entry['write_error']);
        $out=['state'=>$ok?'saved':'needs_review','operation_id'=>$key,'stored_state'=>$ok?'verified':'needs_review','diagnosis'=>self::diagnosis($entry['after'],$now),'page'=>self::view($p),'public_state'=>$p->post_status==='publish'?'pending':'not_public','expectations'=>[],'uncheckable_fields'=>[]];
        if(!empty($entry['write_error']))$out['diagnosis']['difference_groups'][]='write_protocol';
        if(!$ok||!$includePublic||$p->post_status!=='publish')return $out;
        $add=static function(string $field,string $kind,string $text)use(&$out){$value=self::plain($text);if($value!==''&&strlen($value)<=60000&&count($out['expectations'])<100)$out['expectations'][]=['field'=>$field,'kind'=>$kind,'value'=>$value];else $out['uncheckable_fields'][]=$field;};
        foreach($entry['fields'] as $field){
            if($field==='title')$add('title','h1',(string)$now['title']);
            elseif($field==='seo_title'||$field==='seo_description'){
                $value=(string)($now['meta'][$field]['value']??'');
                if($value!==''&&!str_contains($value,'%'))$add($field,$field==='seo_title'?'title':'description',$value);else $out['uncheckable_fields'][]=$field;
            }elseif($field==='seo_keyword'||$field==='native_settings')$out['uncheckable_fields'][]=$field;
            elseif($field==='content'||$field==='excerpt')$add($field,'text',(string)$now[$field]);
            elseif($field==='traveler')foreach($now['traveler']['meta']??[] as $k=>$m){
                if(($entry['before']['traveler']['meta'][$k]??null)===$m)continue;
                if(is_string($m['value']))$add($k,'text',$m['value']);
                elseif(is_array($m['value']))foreach($m['value'] as $i=>$row)foreach(['title','desc'] as $part)$add($k.':'.$i.':'.$part,'text',(string)$row[$part]);
            }
        }
        $out['verification_note']='Checks cover saved values and text found in one public HTML response. Focus keywords, empty removals, SEO template variables, visual layout, availability and search ranking need separate checks.';
        return $out;
    }
    /** Suspend only the five reviewed Traveler admin callbacks; SEO and ordinary WordPress hooks still run. */
    private static function updateEditorialPost(int $id,array $fields) {
        $removed=[];global $wp_filter;
        if(TourfectoTraveler::active(self::post($id))&&isset($wp_filter['save_post']->callbacks)){
            foreach($wp_filter['save_post']->callbacks as $priority=>$callbacks)foreach($callbacks as $callback){
                $fn=$callback['function'];if(!is_array($fn)||count($fn)!==2)continue;
                $class=is_object($fn[0])?get_class($fn[0]):$fn[0];
                if($class==='STAdminTours'&&in_array($fn[1],['tours_update_price_sale','meta_update_sale_price','meta_update_min_price','_update_list_location','_update_duplicate_data'],true)){
                    if(remove_action('save_post',$fn,$priority))$removed[]=[$fn,$priority,$callback['accepted_args']];
                }
            }
        }
        try{return wp_update_post(wp_slash($fields),true);}
        finally{foreach($removed as [$fn,$priority,$args])add_action('save_post',$fn,$priority,$args);}
    }
    private static function purge(int $id): void {
        clean_post_cache($id);do_action('litespeed_purge_post',$id);
        if(function_exists('rocket_clean_post')) rocket_clean_post($id);
        do_action('tourfecto_page_editor_updated',$id);
    }
    private static function write(int $id,array $state): void {
        // Preserve the general WordPress save protocol; bypass it only for tour-text-only writes.
        $p=self::post($id);$current=self::snapshot($p);$settingsChanged=self::nativeChanged($current,$state);
        if($settingsChanged)TourfectoTourSettings::editorCheck($id,$state['native_settings']);
        $tourOnly=isset($state['traveler'])&&$p->post_title===$state['title']&&$p->post_content===$state['content']&&$p->post_excerpt===$state['excerpt'];
        foreach(self::keys($state['adapter']) as $field=>$key){$m=$state['meta'][$field];if(metadata_exists('post',$id,$key)!==$m['exists']||($m['exists']&&(string)get_post_meta($id,$key,true)!==$m['value']))$tourOnly=false;}
        // Write the actual SEO plugin fields before save_post hooks rebuild their data.
        foreach(self::keys($state['adapter']) as $field=>$key) {
            $m=$state['meta'][$field];if($m['exists']) update_post_meta($id,$key,wp_slash($m['value']));else delete_post_meta($id,$key);
        }
        if(isset($state['traveler']))TourfectoTraveler::write($id,$state['traveler']);
        if($settingsChanged)TourfectoTourSettings::editorWrite($id,$state['native_settings']);
        $p=self::post($id);
        // Metadata-only tour writes do not run Traveler save_post price/availability hooks.
        if(!$tourOnly){
            $result=self::updateEditorialPost($id,['ID'=>$id,'post_title'=>$state['title'],'post_content'=>$state['content'],'post_excerpt'=>$state['excerpt']]);
            if(is_wp_error($result)) self::fail('WordPress save failed; inspect the page and operation history.',503);
        }
        self::purge($id);
    }
    private static function apply(array $input): array {
        if(($input['confirmed']??false)!==true) self::fail('Review and confirm the preview.');
        $plan=self::unpack((string)($input['preview_token']??''));$id=(int)$plan['id'];$p=self::post($id);
        global $wpdb;$lock='tf_editor_'.md5($wpdb->prefix.':'.$id);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1) self::fail('Page is busy. Retry after the current operation.',409);
        try {
            $logs=get_post_meta($id,'_tourfecto_editor_history',true);if(!is_array($logs))$logs=[];
            $key=$plan['request'];$current=self::snapshot(self::post($id));
            if(isset($logs[$key])) {
                return self::verification(self::post($id),$logs,$key,false)+['replayed'=>true];
            }
            if(!hash_equals(self::hash($plan['before']),self::hash($current))) self::fail('Page changed after preview. Reload and review a new preview.',409);
            if(isset($plan['after']['native_settings'])&&self::nativeChanged($current,$plan['after']))TourfectoTourSettings::editorCheck($id,$plan['after']['native_settings']);
            if(count($logs)>=50) self::fail('History limit reached. Export and archive history with your administrator before further edits.');
            $logs[$key]=['status'=>'applying','time'=>gmdate('c'),'user'=>get_current_user_id(),'mode'=>$plan['mode'],'restores'=>$plan['restore_id'],'fields'=>$plan['fields'],'before'=>$current,'after'=>$plan['after']];
            if(!update_post_meta($id,'_tourfecto_editor_history',wp_slash($logs))) self::fail('Could not persist the recovery snapshot. Nothing was changed.',503);
            wp_save_post_revision($id);
            try {
                self::write($id,$plan['after']);$actual=self::snapshot(self::post($id));
                if(self::hash($actual)!==self::hash($plan['after'])){
                    $logs[$key]['status']='needs_review';$logs[$key]['diagnosis']=self::diagnosis($plan['after'],$actual);update_post_meta($id,'_tourfecto_editor_history',wp_slash($logs));
                    return self::verification(self::post($id),$logs,$key,false);
                }
                $logs[$key]['status']='saved';$logs[$key]['after']=$actual;
            } catch(Throwable $e) {
                $logs[$key]['status']='needs_review';$logs[$key]['write_error']=true;update_post_meta($id,'_tourfecto_editor_history',wp_slash($logs));
                return self::verification(self::post($id),$logs,$key,false)+['reason'=>'write_incomplete'];
            }
            if(!update_post_meta($id,'_tourfecto_editor_history',wp_slash($logs))) self::fail('Page was saved but history confirmation failed. Inspect before retrying.',503);
            return ['state'=>'saved','operation_id'=>$key,'page'=>self::view(self::post($id)),'note'=>'Stored fields verified. Public rendering and business results are separate checks.'];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function dispatch(string $op,$request) {
        try {
            $in=$request->get_json_params();if(!is_array($in))$in=[];
            if(str_starts_with($op,'tour-create'))return TourfectoTourDraft::dispatch($op,$in);
            if($op==='tour-calendar-read')return TourfectoTourCalendar::read((int)($in['id']??0));
            if(str_starts_with($op,'tour-settings-'))return TourfectoTourSettings::dispatch($op,$in);
            if($op==='capabilities') {
                if(!current_user_can('manage_options'))self::fail('An administrator is required for the theme compatibility report.',403);
                $theme=wp_get_theme();$plugins=[];if(!function_exists('get_plugins')&&is_file(ABSPATH.'wp-admin/includes/plugin.php'))require_once ABSPATH.'wp-admin/includes/plugin.php';
                if(function_exists('get_plugins')){foreach(get_plugins() as $slug=>$details)if((in_array($slug,(array)get_option('active_plugins',[]),true)||(function_exists('is_plugin_active_for_network')&&is_plugin_active_for_network($slug)))&&count($plugins)<50)$plugins[]=['slug'=>$slug,'name'=>$details['Name']??$slug,'version'=>$details['Version']??''];}
                $types=[];foreach(get_post_types(['public'=>true],'objects') as $name=>$type)if($name!=='attachment'&&count($types)<50)$types[]=['name'=>$name,'label'=>$type->label];
                return ['plugin_version'=>'1.7.0','theme'=>['name'=>$theme->get('Name'),'version'=>$theme->get('Version'),'stylesheet'=>$theme->get_stylesheet(),'template'=>$theme->get_template()],'post_types'=>$types,'active_plugins'=>$plugins,'seo_adapter'=>self::adapter(),'elementor_detected'=>defined('ELEMENTOR_VERSION'),'woo_detected'=>class_exists('WooCommerce'),'scope'=>['classic_content'=>true,'gutenberg_plain_text'=>true,'elementor_content'=>false,'custom_tour_fields'=>'traveler_3_4_text_and_draft_settings','existing_manual_tours'=>true,'unified_preview'=>true,'public_html_verification'=>true],'next_step'=>'Provide the exact active theme and tour plugin source ZIPs for a verified field adapter. No field values, passwords or customer records are exported.'];
            }
            if($op==='discover') {
                $page=max(1,min(10,(int)($in['page']??1)));$types=self::contentTypes();
                $query=new WP_Query(['post_type'=>$types,'post_status'=>'publish','has_password'=>false,'posts_per_page'=>100,'paged'=>$page,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>false]);
                $items=[];foreach($query->posts as $p)if(in_array($p->post_type,self::contentTypes(),true)&&current_user_can('edit_post',$p->ID))$items[]=['id'=>(int)$p->ID,'type'=>$p->post_type,'url'=>get_permalink($p)];
                return ['items'=>$items,'page'=>$page,'has_more'=>$query->max_num_pages>$page,'limit'=>100];
            }
            if($op==='list') {
                $types=self::contentTypes();
                $posts=get_posts(['post_type'=>$types,'post_status'=>['publish','draft','pending'],'has_password'=>false,'s'=>sanitize_text_field((string)($in['search']??'')),'numberposts'=>20,'orderby'=>'modified','order'=>'DESC','suppress_filters'=>false]);
                $items=[];foreach($posts as $p)if(in_array($p->post_type,self::contentTypes(),true)&&current_user_can('edit_post',$p->ID))$items[]=['id'=>(int)$p->ID,'type'=>$p->post_type,'title'=>$p->post_title,'status'=>$p->post_status,'url'=>get_permalink($p)];
                return ['items'=>$items,'limit'=>20];
            }
            if($op==='resolve')return self::resolve((string)($in['url']??''));
            if($op==='apply')return self::apply($in);
            $id=(int)($in['id']??0);$p=self::post($id);
            if($op==='read')return self::view($p);
            if($op==='preview'){[$before,$after,$fields]=self::prepareState($p,$in);if($after===$before)self::fail('No field values changed.');return self::preview($before,$after,$fields);}
            if($op==='workspace-preview')return self::workspacePreview($p,$in);
            if($op==='traveler-preview'){
                $before=self::snapshot($p);if(!hash_equals(self::hash($before),(string)($in['revision']??'')))self::fail('Page or tour fields changed. Reload before previewing.',409);
                if(!isset($before['traveler']))self::fail('A Traveler 3.4 tour is required.');
                $after=$before;try{$after['traveler']=TourfectoTraveler::prepare($before['traveler'],$in);}catch(InvalidArgumentException $e){self::fail($e->getMessage(),422);}return self::preview($before,$after,['traveler']);
            }
            if($op==='block-preview'){
                $before=self::snapshot($p);if(!hash_equals(self::hash($before),(string)($in['revision']??'')))self::fail('Page changed. Reload before previewing blocks.',409);
                if(!is_array($in['block_changes']??null))self::fail('Invalid text-block changes.');
                $after=$before;try{$after['content']=TourfectoBlockText::change($before['content'],$in['block_changes'],self::otherBuilder($p));}catch(InvalidArgumentException $e){self::fail($e->getMessage(),422);}return self::preview($before,$after,['content']);
            }
            $logs=get_post_meta($id,'_tourfecto_editor_history',true);if(!is_array($logs))$logs=[];
            if($op==='status'||$op==='verify')return self::verification($p,$logs,(string)($in['operation_id']??''),$op==='verify');
            if($op==='history') {
                $out=[];foreach(array_reverse($logs,true) as $key=>$l)$out[]=['id'=>$key,'time'=>$l['time'],'status'=>$l['status'],'mode'=>$l['mode'],'fields'=>$l['fields']];
                return ['items'=>$out];
            }
            $key=(string)($in['operation_id']??'');$log=$logs[$key]??null;
            if(!$log || !in_array($log['status'],['saved','needs_review','applying'],true))self::fail('Operation unavailable.');
            $now=self::snapshot($p);if(isset($now['native_settings'])!==isset($log['before']['native_settings']))self::fail('This history predates the native settings workspace; review recovery in WordPress.',409);
            if(isset($now['traveler'])&&!isset($log['before']['traveler']))self::fail('This older history predates the Traveler adapter. Review the original backup with an administrator; automatic recovery is blocked.',409);
            if(self::hash($now)===self::hash($log['before']))self::fail('The page already matches the original snapshot; no restore is needed.');
            if(self::hash($now)!==self::hash($log['after'])) {
                if(isset($now['native_settings'])||isset($log['after']['native_settings']))self::fail('Partial or later native settings require manual review before recovery.',409);
                // A partial failed save can be restored only if every observed field is an old/new known value.
                if($log['status']==='saved')self::fail('Page has later edits. Restore is blocked to preserve them.',409);
                foreach($now as $field=>$value) {
                    if($field==='traveler'){
                        if(($value['protected_hash']??null)!==($log['after']['traveler']['protected_hash']??null))self::fail('A later protected tour edit blocks recovery.',409);
                        foreach($value as $part=>$partValue){if($part==='meta'){foreach($partValue as $key=>$m)if($m!==($log['before']['traveler']['meta'][$key]??null)&&$m!==($log['after']['traveler']['meta'][$key]??null))self::fail('A later tour text edit blocks recovery.',409);}elseif($partValue!==($log['before']['traveler'][$part]??null)&&$partValue!==($log['after']['traveler'][$part]??null))self::fail('Tour adapter changed; recovery blocked.',409);}
                    } elseif($field==='meta') {
                        foreach($value as $key=>$meta)if($meta!==($log['before']['meta'][$key]??null) && $meta!==($log['after']['meta'][$key]??null))self::fail('A later SEO edit blocks recovery.',409);
                    } elseif($value!==($log['before'][$field]??null) && $value!==($log['after'][$field]??null))self::fail('A later page edit blocks recovery.',409);
                }
            }
            return self::preview($now,$log['before'],$log['fields'],'restore',$key);
        } catch(Throwable $e) { return new WP_Error('tf_editor',$e->getMessage(),['status'=>in_array($e->getCode(),[403,409,422,503],true)?$e->getCode():503]); }
    }
}
add_action('rest_api_init',[TourfectoPageEditor::class,'routes']);
