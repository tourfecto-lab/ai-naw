<?php
/** Capture request IP before cron; geolocation never uses the worker/server IP. */
class AccountAlertLocation
{
    public static function capture(): string{$ip=function_exists('get_client_ip')?get_client_ip():($_SERVER['REMOTE_ADDR']??'');return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'';}
    public static function lookup(string $ip): array{
        $out=['ip'=>filter_var($ip,FILTER_VALIDATE_IP)?$ip:'غير متاح','city'=>'غير متاحة','country'=>'غير متاح'];
        if(!filter_var($ip,FILTER_VALIDATE_IP))return $out;
        try{if(!class_exists('GeoIPService')){if(is_file(__DIR__.'/GeoIPService.php'))require_once __DIR__.'/GeoIPService.php';}if(class_exists('GeoIPService')){$geo=GeoIPService::lookup($ip);$out['city']=!empty($geo['city'])?mb_substr((string)$geo['city'],0,100):'غير متاحة';$out['country']=!empty($geo['country'])?mb_substr((string)$geo['country'],0,100):'غير متاح';}}
        catch(Throwable $e){}return $out;
    }
}
