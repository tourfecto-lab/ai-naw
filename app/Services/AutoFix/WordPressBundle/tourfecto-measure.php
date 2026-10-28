<?php
/**
 * Module Name: Tourfecto Measure
 * Description: Sends minimal paid WooCommerce order status records to your connected Tourfecto account.
 * Version: 1.0.0
 * Requires PHP: 8.1
 */
if(!defined('ABSPATH'))exit;
final class TourfectoMeasure {
    private const OPTION='_tourfecto_measure';
    public static function routes(): void {
        register_rest_route('tourfecto-measure/v1','/configure',['methods'=>'POST','permission_callback'=>static fn()=>is_user_logged_in()&&current_user_can('manage_woocommerce'),'callback'=>[self::class,'configure']]);
    }
    private static function seal(string $secret): string {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new RuntimeException('Local encryption unavailable.');return base64_encode($iv.$tag.$cipher);
    }
    private static function unseal(string $stored): string {
        $b=base64_decode($stored,true);if($b===false||strlen($b)<29)return '';
        return (string)openssl_decrypt(substr($b,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16));
    }
    public static function configure($request) {
        try {
            if(!function_exists('wc_get_order')||!function_exists('openssl_encrypt'))return new WP_Error('tf_measure','WooCommerce and OpenSSL are required.',['status'=>422]);
            $in=$request->get_json_params();$endpoint=(string)($in['endpoint']??'');$secret=(string)($in['secret']??'');$p=parse_url($endpoint);
            if(strlen($endpoint)>1800||!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!preg_match('#/api/seo-impact/booking/[1-9][0-9]*$#D',$p['path']??'')||!preg_match('/^[a-f0-9]{64}$/D',$secret)||parse_url(home_url(),PHP_URL_SCHEME)!=='https')return new WP_Error('tf_measure','Invalid HTTPS source configuration.',['status'=>422]);
            update_option(self::OPTION,['endpoint'=>$endpoint,'secret'=>self::seal($secret)],false);
            return ['state'=>'configured','site_url'=>home_url(),'scope'=>'New order changes only; no customer contact data or historical backfill.'];
        }catch(Throwable $e){return new WP_Error('tf_measure','Could not protect the local receipt key.',['status'=>503]);}
    }
    public static function schedule(int $id): void {
        if(!get_option(self::OPTION)||!function_exists('wc_get_order'))return;
        if(!wp_next_scheduled('tourfecto_measure_order',[$id,0]))wp_schedule_single_event(time()+5,'tourfecto_measure_order',[$id,0]);
    }
    public static function payload($order): ?array {
        $paid=$order->get_date_paid();if(!$paid)return null;
        $status=$order->is_paid()?'paid':($order->get_status()==='refunded'?'refunded':'cancelled');
        $landing=(string)$order->get_meta('_wc_order_attribution_session_entry');$p=parse_url($landing);$url='';
        if($p&&strtolower((string)($p['host']??''))===strtolower((string)parse_url(home_url(),PHP_URL_HOST)))$url='https://'.$p['host'].($p['path']??'/');
        $key=(string)$order->get_meta('_tourfecto_receipt_key');if(!preg_match('/^[a-f0-9]{64}$/D',$key)){$key=bin2hex(random_bytes(32));$order->update_meta_data('_tourfecto_receipt_key',$key);$order->save_meta_data();}
        return ['booking_key'=>$key,'source_version'=>time(),'paid_date'=>gmdate('Y-m-d',$paid->getTimestamp()),'amount'=>max(0,(float)$order->get_total()-(float)$order->get_total_refunded()),'currency'=>$order->get_currency(),'status'=>$status,'landing_url'=>$url];
    }
    public static function send(int $id,int $attempt=0): void {
        $config=get_option(self::OPTION);if(!is_array($config)||!function_exists('wc_get_order'))return;$order=wc_get_order($id);if(!$order)return;$payload=self::payload($order);if(!$payload)return;
        $secret=self::unseal($config['secret']??'');if($secret===''){update_option('_tourfecto_measure_error','Receipt key unavailable; reconnect.',false);return;}
        $r=wp_safe_remote_post($config['endpoint'],['timeout'=>12,'redirection'=>0,'sslverify'=>true,'headers'=>['Content-Type'=>'application/json','X-Tourfecto-Secret'=>$secret],'body'=>wp_json_encode($payload)]);
        $j=!is_wp_error($r)?json_decode(wp_remote_retrieve_body($r),true):null;$ok=!is_wp_error($r)&&wp_remote_retrieve_response_code($r)===200&&!empty($j['success'])&&!empty($j['data']['received']);
        if($ok)delete_option('_tourfecto_measure_error');else {
            update_option('_tourfecto_measure_error','Receipt delivery unconfirmed; review the connection.',false);
            if($attempt<3&&!wp_next_scheduled('tourfecto_measure_order',[$id,$attempt+1]))wp_schedule_single_event(time()+60*($attempt+1),'tourfecto_measure_order',[$id,$attempt+1]);
        }
    }
}
add_action('rest_api_init',[TourfectoMeasure::class,'routes']);
add_action('woocommerce_payment_complete',[TourfectoMeasure::class,'schedule']);
add_action('woocommerce_order_status_changed',[TourfectoMeasure::class,'schedule']);
add_action('woocommerce_order_refunded',[TourfectoMeasure::class,'schedule']);
add_action('tourfecto_measure_order',[TourfectoMeasure::class,'send'],10,2);
