<?php
final class SeoImpactRules {
    public static function date(string $date): DateTimeImmutable {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid calendar date.');return $d;
    }
    public static function periods(string $anchor,int $days,?string $today=null): array {
        if(!in_array($days,[7,14,28],true))throw new InvalidArgumentException('Choose 7, 14 or 28 days.');
        $d=self::date($anchor);$now=self::date($today??gmdate('Y-m-d'));
        if($d>$now||$d<$now->modify('-365 days'))throw new InvalidArgumentException('Change date must be within the past year.');
        $before=['start'=>$d->modify('-'.$days.' days')->format('Y-m-d'),'end'=>$d->modify('-1 day')->format('Y-m-d')];
        $after=['start'=>$d->modify('+1 day')->format('Y-m-d'),'end'=>$d->modify('+'.$days.' days')->format('Y-m-d')];
        $due=$d->modify('+'.($days+3).' days')->setTime(4,0)->format('Y-m-d H:i:s');
        return ['before'=>$before,'after'=>$after,'days'=>$days,'ready'=>self::date($after['end'])<=$now->modify('-3 days'),'due_at'=>$due,'change_day_excluded'=>true];
    }
    public static function difference($before,$after,bool $rate=false): array {
        if(!is_numeric($before)||!is_numeric($after))return ['state'=>'not_comparable','absolute'=>null,'percent'=>null];
        $a=(float)$before;$b=(float)$after;return ['state'=>$a==0&&$b!=0?'new_from_zero':'comparable','absolute'=>$rate?($b-$a)*100:$b-$a,'percent'=>$a!=0?($b-$a)/abs($a)*100:null,'absolute_unit'=>$rate?'percentage_points':'metric_units'];
    }
    public static function page(string $url,string $home): array {
        $p=parse_url($url);$host=parse_url($home,PHP_URL_HOST);
        // PHP connector articles use this exact route when clean /guides URLs are unavailable.
        // Tracking, preview and arbitrary query parameters must not become separate pages.
        $query=(string)($p['query']??'');
        if(strlen($url)>1800||!$p||($p['scheme']??'')!=='https'||!WordPressConnector::hostsEqual((string)($p['host']??''),(string)$host)||isset($p['user'])||isset($p['pass'])||($query!==''&&!preg_match('/^tf_article=[a-z0-9]+(?:-[a-z0-9]+)*$/D',$query))||isset($p['fragment'])||isset($p['port'])&&$p['port']!==parse_url($home,PHP_URL_PORT))throw new InvalidArgumentException('Use a public HTTPS page on this website; only the connector tf_article query route is supported.');
        $raw=$p['path']??'/';if(strlen($raw)>1000||preg_match('/[\x00-\x20\\\\]/',$raw))throw new InvalidArgumentException('Invalid page path.');
        $path=(rtrim($raw,'/')?:'/').($query!==''?'?'.$query:'');
        if(strlen($path)>1000)throw new InvalidArgumentException('Invalid page path.');
        return ['url'=>$url,'path'=>$path,'hash'=>hash('sha256',$path)];
    }
    public static function booking(array $in,string $home): array {
        $key=$in['booking_key']??'';$version=$in['source_version']??null;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_int($version)||$version<1||$version>time()+300)throw new InvalidArgumentException('Invalid booking identity/version.');
        $date=(string)($in['paid_date']??'');self::date($date);if($date>gmdate('Y-m-d'))throw new InvalidArgumentException('Future payment date.');
        $amount=$in['amount']??null;if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0||(float)$amount>999999999999)throw new InvalidArgumentException('Invalid amount.');
        $currency=$in['currency']??'';$status=$in['status']??'';if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)||!in_array($status,['paid','refunded','cancelled'],true))throw new InvalidArgumentException('Invalid currency/status.');
        $path=null;if(!empty($in['landing_url']))$path=self::page((string)$in['landing_url'],$home)['path'];
        return ['booking_key'=>$key,'source_version'=>$version,'paid_date'=>$date,'amount'=>number_format((float)$amount,2,'.',''),'currency'=>$currency,'status'=>$status,'page_path'=>$path];
    }
}
