<?php
if(!defined('ABSPATH'))exit;
/** Reviewed Traveler 3.4 native settings for unpublished drafts, including manually created tours. */
final class TourfectoTourSettings {
    private const KEYS=['duration_day','type_tour','max_people','min_people','tour_price_by','adult_price','base_price','hide_adult_in_booking_form','hide_children_in_booking_form','hide_infant_in_booking_form','_tourfecto_settings_preview_price','_tourfecto_settings_currency'];
    private const HISTORY='_tourfecto_settings_history';
    private static function fail(string $message,int $code=422): void {throw new RuntimeException($message,$code);}
    private static function post(int $id) {
        $p=get_post($id);
        if(!$p||$p->post_type!=='st_tours'||$p->post_status!=='draft'||!empty($p->post_password)||!current_user_can('edit_post',$id)||!current_user_can('manage_options')||!TourfectoTraveler::active($p))self::fail('An editable Traveler 3.4 draft and administrator permission are required.',403);
        if(!taxonomy_exists('languages')||!is_object_in_taxonomy('st_tours','languages'))self::fail('Traveler tour language taxonomy is unavailable.');
        $tax=get_taxonomy('languages');if(!current_user_can($tax->cap->assign_terms))self::fail('Language assignment permission required.',403);
        return $p;
    }
    private static function currency(): string {
        if(!class_exists('TravelHelper')||!is_callable(['TravelHelper','get_default_currency']))self::fail('Traveler base currency could not be read.');
        $c=strtoupper((string)TravelHelper::get_default_currency('name'));if(!preg_match('/^[A-Z]{3}$/D',$c))self::fail('Traveler base currency could not be read.');return $c;
    }
    private static function languages(int $id): array {
        $terms=wp_get_post_terms($id,'languages');if(is_wp_error($terms))self::fail('Could not read tour languages.');
        $out=[];foreach($terms as $t)$out[]=['id'=>(int)$t->term_id,'name'=>$t->name];usort($out,static fn($a,$b)=>$a['id']<=>$b['id']);return $out;
    }
    private static function state($p): array {
        $meta=[];foreach(self::KEYS as $k)$meta[$k]=['exists'=>metadata_exists('post',$p->ID,$k),'value'=>get_post_meta($p->ID,$k,true)];
        $all=get_post_meta($p->ID);unset($all[self::HISTORY],$all['_edit_lock'],$all['_edit_last'],$all['_elementor_css']);ksort($all);
        return ['id'=>(int)$p->ID,'title'=>$p->post_title,'status'=>$p->post_status,'currency'=>self::currency(),'meta'=>$meta,'languages'=>self::languages((int)$p->ID),'guard'=>hash('sha256',wp_json_encode([$all,$p->post_title,$p->post_content,$p->post_excerpt]))];
    }
    private static function hash(array $v): string {return hash('sha256',wp_json_encode($v));}
    public static function editorKeys(): array {return self::KEYS;}
    /** Names are compared after a save; IDs are retained for exact restoration. No term is created in preview. */
    public static function editorState(int $id): array {
        $state=self::state(self::post($id));$names=array_column($state['languages'],'name');sort($names);
        return ['currency'=>$state['currency'],'meta'=>$state['meta'],'language_names'=>$names,'language_ids'=>array_column($state['languages'],'id')];
    }
    public static function editorPrepare(int $id,array $input): array {
        $before=self::state(self::post($id));$target=self::prepare($before,$input);sort($target['language_names']);
        return ['currency'=>$before['currency']]+$target;
    }
    public static function editorCheck(int $id,array $target): void {
        self::post($id);if(($target['currency']??'')!==self::currency())self::fail('Website currency changed.',409);
        self::requireEmptyCalendar($id);
        if(array_keys($target['meta']??[])!==self::KEYS)self::fail('Invalid native settings target.');
    }
    public static function editorWrite(int $id,array $target): void {
        self::editorCheck($id,$target);
        foreach($target['meta'] as $k=>$m){if($m['exists'])update_post_meta($id,$k,wp_slash($m['value']));else delete_post_meta($id,$k);}
        $ids=$target['language_ids']??self::namesToIds($target['language_names']);
        if(isset($target['language_ids']))foreach($ids as $termId)if(!term_exists((int)$termId,'languages'))self::fail('An original language term no longer exists; review recovery.',409);
        $r=wp_set_object_terms($id,array_map('intval',$ids),'languages',false);if(is_wp_error($r))self::fail('Language assignment needs review.');
    }
    private static function pack(array $p): string {$b=rtrim(strtr(base64_encode(wp_json_encode($p)),'+/','-_'),'=');return $b.'.'.hash_hmac('sha256','tour-settings:'.$b,wp_salt('auth'));}
    private static function unpack(string $token): array {
        if(strlen($token)>80000)self::fail('Invalid settings preview.');$parts=explode('.',$token);
        if(count($parts)!==2||!hash_equals(hash_hmac('sha256','tour-settings:'.$parts[0],wp_salt('auth')),$parts[1]))self::fail('Invalid settings signature.',403);
        $p=json_decode((string)base64_decode(strtr($parts[0],'-_','+/'),true),true);
        if(!is_array($p)||($p['user']??0)!==get_current_user_id()||($p['site']??'')!==home_url()||($p['expires']??0)<time()||!preg_match('/^[a-f0-9]{32}$/D',$p['request']??''))self::fail('Settings preview expired or belongs to another site/user.',409);return $p;
    }
    private static function text($v,int $max): string {if(!is_string($v)||strlen($v)>$max||sanitize_text_field($v)!==$v||trim($v)===''||strpbrk($v,'[]')!==false||preg_match('//u',$v)!==1)self::fail('Invalid settings text.');return $v;}
    private static function hasCalendar(int $id): bool {
        if(!class_exists('ST_Tour_Availability')||!is_callable(['ST_Tour_Availability','inst']))self::fail('Traveler availability model is unavailable.');
        $model=ST_Tour_Availability::inst();if(!is_callable([$model,'where'])||!is_callable([$model,'get'])||!is_callable([$model,'row']))self::fail('Traveler availability inspection is unavailable.');
        return !empty($model->where(['post_id'=>$id])->get(1)->row());
    }
    private static function requireEmptyCalendar(int $id): void {
        if(self::hasCalendar($id))self::fail('Existing dated pricing must be reviewed in WordPress; this draft base-price adapter does not modify calendars.');
    }
    private static function prepare(array $before,array $input): array {
        if(array_diff(array_keys($input),['duration','type','maximum','minimum','price_basis','price','currency','languages','hide_unknown_child_prices']))self::fail('Unsupported settings fields.');
        if(($input['currency']??'')!==$before['currency'])self::fail('Use the website base currency; global currency is not changed.');
        if(!in_array($input['type']??'', ['daily_tour','specific_date'],true)||!in_array($input['price_basis']??'', ['person','fixed'],true))self::fail('Choose the native tour type and price basis.');
        $price=$input['price']??'';if(!is_string($price)||!preg_match('/^(?:[1-9][0-9]{0,6}|0)(?:\.[0-9]{1,2})?$/D',$price)||(float)$price<=0)self::fail('A positive base price is required.');
        $max=$input['maximum']??null;$min=$input['minimum']??null;if(!is_int($max)||!is_int($min)||$max<1||$max>100||$min<1||$min>$max)self::fail('Confirmed group limits must be between 1 and 100.');
        if(($input['hide_unknown_child_prices']??false)!==true)self::fail('Confirm hiding child/infant booking fields until their prices are known.');
        $names=$input['languages']??null;if(!is_array($names)||!$names||count($names)>10||array_values($names)!==$names)self::fail('Choose confirmed guide languages.');$names=array_values(array_unique(array_map(static fn($v)=>self::text($v,80),$names)));
        self::requireEmptyCalendar($before['id']);
        // Never reset live availability, discounts or deposits while editing a draft base price.
        foreach(['discount','discount_by_adult','discount_by_child','is_sale_schedule'] as $k){$v=get_post_meta($before['id'],$k,true);if($v!==''&&$v!=='0'&&$v!==0&&$v!=='off'&&$v!==[])self::fail('Existing discount/sale settings require WordPress review before changing base pricing.');}
        $meta=$before['meta'];$changes=['duration_day'=>self::text($input['duration']??'',100),'type_tour'=>$input['type'],'max_people'=>(string)$max,'min_people'=>(string)$min,'tour_price_by'=>$input['price_basis'],'hide_adult_in_booking_form'=>'off','hide_children_in_booking_form'=>'on','hide_infant_in_booking_form'=>'on','_tourfecto_settings_preview_price'=>'1','_tourfecto_settings_currency'=>$before['currency']];
        $changes[$input['price_basis']==='person'?'adult_price':'base_price']=$price;
        foreach($changes as $k=>$v)$meta[$k]=['exists'=>true,'value'=>$v];
        return ['meta'=>$meta,'language_names'=>$names];
    }
    private static function plan(array $before,array $after,string $mode='settings'): array {
        $p=['user'=>get_current_user_id(),'site'=>home_url(),'expires'=>time()+900,'request'=>bin2hex(random_bytes(16)),'before'=>$before,'after'=>$after,'mode'=>$mode];
        return ['state'=>'preview','preview_token'=>self::pack($p),'before'=>$before,'after'=>$after,'site_url'=>home_url(),'note'=>'Native draft settings only. No availability dates, publication, images or cancellation policy are configured. Child and infant selectors will be hidden until priced.'];
    }
    private static function namesToIds(array $names): array {
        $ids=[];foreach($names as $name){$t=term_exists($name,'languages');if(!$t){$tax=get_taxonomy('languages');if(!current_user_can($tax->cap->manage_terms))self::fail('Creating a new language requires taxonomy management permission.',403);$t=wp_insert_term($name,'languages');}
            if(is_wp_error($t))self::fail('Language term could not be resolved.');$ids[]=(int)(is_array($t)?$t['term_id']:$t);}return array_values(array_unique($ids));
    }
    private static function targetMatches(array $state,array $target): bool {
        if($state['meta']!==$target['meta'])return false;
        if(isset($target['language_ids'])){$a=array_column($state['languages'],'id');$b=$target['language_ids'];sort($a);sort($b);return $a===$b;}
        $a=array_column($state['languages'],'name');$b=$target['language_names'];sort($a);sort($b);return $a===$b;
    }
    private static function result(int $id,array $entry): array {
        $current=self::state(self::post($id));$ok=self::targetMatches($current,$entry['target']);
        return ['state'=>$ok?'saved':'needs_review','id'=>$id,'operation_id'=>$entry['id'],'status'=>'draft','site_url'=>home_url(),'read'=>$current,'revision'=>self::hash($current),'note'=>$ok?'Native draft fields verified. Configure availability, child prices and booking policies before publication.':'Native fields do not exactly match this operation. Review current values before another save.'];
    }
    public static function dispatch(string $op,array $in): array {
        if($op==='tour-settings-apply') {
            if(($in['confirmed']??false)!==true)self::fail('Review and approve the exact native draft settings.');$plan=self::unpack((string)($in['preview_token']??''));$id=(int)$plan['before']['id'];self::post($id);
            global $wpdb;$lock='tf_editor_'.md5($wpdb->prefix.':'.$id);if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)self::fail('Tour settings are busy.',409);
            try {
                $history=get_post_meta($id,self::HISTORY,true);if(!is_array($history))$history=[];foreach($history as $entry)if(($entry['id']??'')===$plan['request'])return self::result($id,$entry)+['replayed'=>true];
                self::requireEmptyCalendar($id);
                $state=self::state(self::post($id));if(!hash_equals(self::hash($plan['before']),self::hash($state)))self::fail('The draft, languages or price settings changed. Read again before saving.',409);
                $entry=['id'=>$plan['request'],'before'=>$state,'target'=>$plan['after'],'state'=>'started','at'=>gmdate('c')];$history[]=$entry;update_post_meta($id,self::HISTORY,wp_slash($history));
                // Record the request before any field writes so an interrupted attempt cannot blindly replay.
                if(get_post_meta($id,self::HISTORY,true)!==$history)self::fail('Could not record the guarded settings operation.',503);
                foreach($plan['after']['meta'] as $k=>$v){if(!in_array($k,self::KEYS,true))self::fail('Unknown native settings key.');if($v['exists'])update_post_meta($id,$k,wp_slash($v['value']));else delete_post_meta($id,$k);}
                $ids=$plan['after']['language_ids']??self::namesToIds($plan['after']['language_names']);$r=wp_set_object_terms($id,array_map('intval',$ids),'languages',false);if(is_wp_error($r))self::fail('Language assignment needs review.');
                clean_post_cache($id);do_action('litespeed_purge_post',$id);
                return self::result($id,$entry);
            }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        }
        $p=self::post((int)($in['id']??0));$state=self::state($p);
        if($op==='tour-settings-read')return ['state'=>'read','revision'=>self::hash($state),'read'=>$state,'site_url'=>home_url()];
        if($op==='tour-settings-preview'){if(!hash_equals(self::hash($state),(string)($in['revision']??'')))self::fail('Draft changed. Read again.',409);return self::plan($state,self::prepare($state,$in['settings']??[]));}
        $history=get_post_meta($p->ID,self::HISTORY,true);if(!is_array($history))$history=[];
        if($op==='tour-settings-history')return ['items'=>array_map(static fn($e)=>['id'=>$e['id'],'at'=>$e['at']],$history),'site_url'=>home_url()];
        if($op==='tour-settings-status'){foreach($history as $entry)if($entry['id']===($in['operation_id']??''))return self::result($p->ID,$entry);self::fail('Settings operation unavailable.');}
        if($op==='tour-settings-restore'){foreach($history as $entry)if($entry['id']===($in['operation_id']??'')){
            if(!self::targetMatches($state,$entry['target']))self::fail('Draft settings changed or a partial save needs manual review; automatic restore is blocked.',409);
            return self::plan($state,['meta'=>$entry['before']['meta'],'language_ids'=>array_column($entry['before']['languages'],'id')],'restore');}self::fail('Settings history unavailable.');}
        self::fail('Unknown tour settings operation.');
    }
    public static function draftPrice(string $html): string {
        $id=(int)get_the_ID();if(get_post_status($id)!=='draft'||get_post_type($id)!=='st_tours'||get_post_meta($id,'_tourfecto_settings_preview_price',true)!=='1')return $html;
        try {if(self::hasCalendar($id))return $html;}catch(Throwable $e){return $html;}
        if(get_post_meta($id,'_tourfecto_settings_currency',true)!==(string)TravelHelper::get_default_currency('name'))return $html;
        $basis=get_post_meta($id,'tour_price_by',true);$price=(float)get_post_meta($id,$basis==='person'?'adult_price':'base_price',true);if($price<=0)return $html;
        return '<span class="price">'.esc_html(TravelHelper::format_money($price)).'</span><small class="tourfecto-draft-price-note"> '.esc_html($basis==='person'?'Base price per adult — draft preview; dates not configured.':'Base group price — draft preview; dates not configured.').'</small>';
    }
}
add_filter('st_get_tour_price_html',[TourfectoTourSettings::class,'draftPrice'],99);
