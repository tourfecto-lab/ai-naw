<?php
if (!defined('ABSPATH')) exit;
/** Native Traveler 3.4 editorial draft creation. No publication, remote media or pricing import. */
final class TourfectoTourDraft {
    private static function requireAccess(): void {
        $type=get_post_type_object('st_tours');
        if (!$type || !TourfectoTraveler::active((object)['post_type'=>'st_tours']) || !is_user_logged_in()
            || !current_user_can($type->cap->create_posts??$type->cap->edit_posts??'edit_posts')) throw new RuntimeException('Traveler 3.4 and tour creation permission are required.',403);
    }
    private static function text($v,int $limit,bool $html=false): string {
        if (!is_string($v)||strlen($v)>$limit||preg_match('//u',$v)!==1||strpbrk($v,'[]')!==false||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) throw new RuntimeException('Invalid tour text.',422);
        if (($html?wp_kses_post($v):sanitize_text_field($v))!==$v) throw new RuntimeException('Unsupported tour markup.',422);
        return $v;
    }
    private static function rows($rows,int $max): array {
        if (!is_array($rows)||array_values($rows)!==$rows||count($rows)>$max) throw new RuntimeException('Invalid itinerary/FAQ rows.',422);
        $out=[];foreach($rows as $r){if(!is_array($r)||array_diff(array_keys($r),['title','desc']))throw new RuntimeException('Unsupported row fields.',422);
            $title=self::text($r['title']??'',300);$desc=self::text($r['desc']??'',5000,true);if(trim($title)===''||trim($desc)==='')throw new RuntimeException('Complete the row title and description.',422);$out[]=['title'=>$title,'desc'=>$desc];}return $out;
    }
    private static function input(array $in): array {
        if(array_diff(array_keys($in),['title','content','included','excluded','highlights','itinerary','faqs','source_url']))throw new RuntimeException('Unsupported creation fields; commercial data is configured separately.',422);
        $title=self::text($in['title']??'',300);$content=self::text($in['content']??'',30000,true);
        if(trim($title)===''||trim($content)==='')throw new RuntimeException('Title and original description are required.',422);
        $source=self::text($in['source_url']??'',1800);if($source!==''){$p=parse_url($source);if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment']))throw new RuntimeException('Use a public HTTPS reference URL without credentials or tracking parameters.',422);}
        $out=['title'=>$title,'content'=>$content,'included'=>self::text($in['included']??'',10000,true),'excluded'=>self::text($in['excluded']??'',10000,true),'highlights'=>self::text($in['highlights']??'',10000,true),'itinerary'=>self::rows($in['itinerary']??[],20),'faqs'=>self::rows($in['faqs']??[],10),'source_url'=>$source];
        if(strlen(wp_json_encode($out))>80000)throw new RuntimeException('Tour draft is too large.',422);return $out;
    }
    private static function pack(array $p): string {
        $b=rtrim(strtr(base64_encode(wp_json_encode($p)),'+/','-_'),'=');return $b.'.'.hash_hmac('sha256','tour-create:'.$b,wp_salt('auth'));
    }
    private static function unpack(string $token,bool $expired=false): array {
        if(strlen($token)>120000)throw new RuntimeException('Invalid creation preview.',422);$parts=explode('.',$token);
        if(count($parts)!==2||!hash_equals(hash_hmac('sha256','tour-create:'.$parts[0],wp_salt('auth')),$parts[1]))throw new RuntimeException('Invalid creation preview signature.',403);
        $p=json_decode((string)base64_decode(strtr($parts[0],'-_','+/'),true),true);
        if(!is_array($p)||($p['user']??0)!==get_current_user_id()||($p['site']??'')!==home_url()||(! $expired&&($p['expires']??0)<time())||!preg_match('/^[a-f0-9]{32}$/D',$p['request']??''))throw new RuntimeException('Preview expired or belongs to another user/site.',409);
        return $p;
    }
    private static function key(array $p): string {return '_tourfecto_create_'.hash('sha256',$p['request']);}
    private static function result(int $id,array $data): array {
        $p=get_post($id);if(!$p||$p->post_type!=='st_tours'||!current_user_can('edit_post',$id))throw new RuntimeException('Created draft unavailable; review WordPress before retrying.',409);
        $ok=$p->post_status==='draft' && $p->post_title===$data['title'] && $p->post_content===$data['content'];
        foreach(['tours_include'=>'included','tours_exclude'=>'excluded','tours_highlight'=>'highlights','tours_program'=>'itinerary','tours_faq'=>'faqs'] as $key=>$field)if(get_post_meta($id,$key,true)!==$data[$field])$ok=false;
        return ['state'=>$ok?'created':'needs_review','id'=>$id,'status'=>$p->post_status,'title'=>$p->post_title,'edit_url'=>admin_url('post.php?post='.$id.'&action=edit'),'site_url'=>home_url(),'pending'=>['pricing','dates_and_availability','pickup','duration','location','media_rights','booking_and_cancellation_terms'],'note'=>'Native tour content saved. It is not ready to publish or sell until business facts and booking settings are reviewed in WordPress.'];
    }
    public static function dispatch(string $op,array $in): array {
        self::requireAccess();
        if($op==='tour-create-preview'){
            $data=self::input($in['tour']??[]);$p=['user'=>get_current_user_id(),'site'=>home_url(),'expires'=>time()+900,'request'=>bin2hex(random_bytes(16)),'tour'=>$data];
            return ['state'=>'preview','preview_token'=>self::pack($p),'after'=>$data,'status'=>'draft','pending'=>['pricing','availability','pickup','duration','location','images','booking_and_cancellation'],'site_url'=>home_url()];
        }
        $p=self::unpack((string)($in['preview_token']??''),$op==='tour-create-status');$key=self::key($p);$record=get_option($key);
        if($op==='tour-create-status'){if(is_array($record)&&!empty($record['id']))return self::result((int)$record['id'],$p['tour']);return ['state'=>$record?'uncertain':'not_started','site_url'=>home_url(),'note'=>'A missing acknowledgement is not permission to create another tour.'];}
        if($op!=='tour-create'||($in['confirmed_draft']??false)!==true)throw new RuntimeException('Review and approve creating this unpublished draft.',422);
        global $wpdb;$lock='tf-tour-create:'.substr(hash('sha256',$p['request']),0,40);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)throw new RuntimeException('Draft creation busy. Check the same preview status.',409);
        try {
            $record=get_option($key);if(is_array($record)&&!empty($record['id']))return self::result((int)$record['id'],$p['tour'])+['replayed'=>true];
            // An interrupted insertion can be found through its durable meta request marker.
            $existing=get_posts(['post_type'=>'st_tours','post_status'=>'any','meta_key'=>'_tourfecto_creation_request','meta_value'=>$p['request'],'numberposts'=>2,'fields'=>'ids']);
            if(count($existing)===1){update_option($key,['id'=>(int)$existing[0]],false);return self::result((int)$existing[0],$p['tour'])+['replayed'=>true];}
            if($record||count($existing)>1)throw new RuntimeException('Previous creation outcome needs review. Do not create another preview.',409);
            $data=self::input($p['tour']);if(!add_option($key,['state'=>'started','user'=>$p['user']], '', false))throw new RuntimeException('Creation reservation unavailable.',409);
            $meta=['tours_program_style'=>'style1','tours_include'=>$data['included'],'tours_exclude'=>$data['excluded'],'tours_highlight'=>$data['highlights'],'tours_program'=>$data['itinerary'],'tours_faq'=>$data['faqs'],'_tourfecto_creation_request'=>$p['request'],'_tourfecto_draft_reference'=>$data['source_url'],'_tourfecto_draft_pending_business_review'=>'1'];
            $id=wp_insert_post(wp_slash(['post_type'=>'st_tours','post_status'=>'draft','post_title'=>$data['title'],'post_content'=>$data['content'],'post_author'=>get_current_user_id(),'comment_status'=>'closed','meta_input'=>$meta]),true);
            if(is_wp_error($id)||!$id)throw new RuntimeException('Draft insertion was not confirmed. Check preview status before retrying.',409);
            update_option($key,['id'=>(int)$id],false);$post=get_post($id);
            if(!$post||$post->post_status!=='draft')throw new RuntimeException('Unexpected status: inspect WordPress before proceeding.',409);
            foreach($meta as $k=>$v)if(get_post_meta($id,$k,true)!==$v)throw new RuntimeException('Draft created but native field verification needs review in WordPress.',409);
            clean_post_cache($id);return self::result((int)$id,$p['tour']);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
