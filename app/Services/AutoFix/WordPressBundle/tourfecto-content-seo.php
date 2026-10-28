<?php
if (!defined('ABSPATH')) exit;
/** Signed, user/site/post-bound metadata writes for posts created through the platform. */
final class TourfectoContentSeo
{
    public static function created($post, $request, bool $creating): void
    {
        if ($creating && $post->post_type==='post' && $request->get_param('tourfecto_source')==='content-quality-v1' && current_user_can('edit_post',$post->ID)) {
            update_post_meta($post->ID,'_tourfecto_created_by',(string)get_current_user_id());
            update_post_meta($post->ID,'_tourfecto_created_at',time());
        }
    }
    public static function routes(): void
    {
        foreach (['content-seo-preview','content-seo-apply'] as $op) register_rest_route('tourfecto-editor/v1','/'.$op,[
            'methods'=>'POST','permission_callback'=>[TourfectoPageEditor::class,'permission'],
            'callback'=>static function($request) use ($op) {
                try {return $op==='content-seo-preview'?self::preview($request->get_json_params()):self::apply((string)($request->get_json_params()['token']??''));}
                catch (Throwable $e) {return new WP_Error('tf_content_seo',$e->getMessage(),['status'=>in_array($e->getCode(),[403,409,422],true)?$e->getCode():422]);}
            }]);
    }
    private static function post(int $id)
    {
        $p=get_post($id);
        if (!$p || $p->post_type!=='post' || !in_array($p->post_status,['publish','draft','pending'],true) || !empty($p->post_password) || !current_user_can('edit_post',$id)
            || (string)get_post_meta($id,'_tourfecto_created_by',true)!==(string)get_current_user_id()) throw new RuntimeException('Only posts created by this platform account can receive SEO fields.',403);
        return $p;
    }
    private static function state(int $id,string $adapter): array
    {
        $state=[];
        foreach (TourfectoNativeSeo::keys($adapter) as $key) $state[$key]=['exists'=>metadata_exists('post',$id,$key),'value'=>get_post_meta($id,$key,true)];
        return $state;
    }
    public static function preview(array $input): array
    {
        $id=(int)($input['post_id']??0);self::post($id);$adapter=TourfectoNativeSeo::adapter();
        if (!in_array($adapter,['yoast','rankmath','native'],true)) throw new RuntimeException('Enable one supported SEO adapter; native requires the native head bridge.',409);
        $fields=$input['fields']??null;
        if (!is_array($fields) || array_diff(array_keys($fields),['seo_title','seo_description','seo_keyword']) || count($fields)!==3) throw new RuntimeException('Provide exactly title, description and focus keyword.',422);
        foreach ($fields as $name=>$value) if (!is_string($value) || mb_strlen($value)>($name==='seo_description'?500:255) || sanitize_text_field($value)!==$value) throw new RuntimeException('Invalid SEO text.',422);
        $plan=['post_id'=>$id,'site'=>home_url(),'user'=>get_current_user_id(),'adapter'=>$adapter,'before'=>self::state($id,$adapter),'fields'=>$fields,'expires'=>time()+300,'nonce'=>wp_generate_uuid4()];
        $body=rtrim(strtr(base64_encode(wp_json_encode($plan)),'+/','-_'),'=');
        return ['token'=>$body.'.'.hash_hmac('sha256','content-seo:'.$body,wp_salt('auth')), 'expires_at'=>gmdate('c',$plan['expires'])];
    }
    public static function verify(string $token,string $salt,string $site,int $user,int $now): array
    {
        $parts=explode('.',$token);
        if (strlen($token)>20000 || count($parts)!==2 || !hash_equals(hash_hmac('sha256','content-seo:'.$parts[0],$salt),$parts[1])) throw new RuntimeException('Invalid SEO signature.',403);
        $plan=json_decode((string)base64_decode(strtr($parts[0],'-_','+/'),true),true);
        if (!is_array($plan) || ($plan['site']??'')!==$site || ($plan['user']??0)!==$user || ($plan['expires']??0)<$now || ($plan['expires']??0)>$now+300 || (int)($plan['post_id']??0)<1) throw new RuntimeException('SEO signature expired or belongs to another site/account.',403);
        return $plan;
    }
    public static function apply(string $token): array
    {
        $plan=self::verify($token,wp_salt('auth'),home_url(),get_current_user_id(),time());$id=(int)$plan['post_id'];self::post($id);
        if ($plan['adapter']!==TourfectoNativeSeo::adapter()) throw new RuntimeException('SEO adapter changed. Prepare a new update.',409);
        $receipt=hash('sha256',$token);
        if (get_post_meta($id,'_tourfecto_content_seo_receipt',true)===$receipt) return ['success'=>true,'post_id'=>$id,'replayed'=>true];
        if (self::state($id,$plan['adapter'])!==$plan['before']) throw new RuntimeException('SEO fields changed after preview. Reload before applying.',409);
        foreach (TourfectoNativeSeo::keys($plan['adapter']) as $field=>$key) update_post_meta($id,$key,$plan['fields'][$field]);
        update_post_meta($id,'_tourfecto_content_seo_receipt',$receipt);
        clean_post_cache($id);
        if (function_exists('rocket_clean_post')) rocket_clean_post($id);
        if (function_exists('do_action')) do_action('litespeed_purge_post',$id);
        return ['success'=>true,'post_id'=>$id,'adapter'=>$plan['adapter']];
    }
}
add_action('rest_after_insert_post',[TourfectoContentSeo::class,'created'],10,3);
add_action('rest_api_init',[TourfectoContentSeo::class,'routes']);
