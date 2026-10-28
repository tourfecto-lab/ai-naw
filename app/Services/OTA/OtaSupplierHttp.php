<?php
/** Fixed provider hosts, no redirects, bounded responses, no secret/body logging. */
final class OtaSupplierHttp
{
    public static function request(string $method,string $url,array $headers,?string $body,?callable $transport=null): array
    {
        $allowed=['api.bokun.io','api.bokuntest.com','api.rezdy.com','api.rezdy-staging.com'];
        if(parse_url($url,PHP_URL_SCHEME)!=='https'||!in_array(parse_url($url,PHP_URL_HOST),$allowed,true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PORT)!==null)throw new InvalidArgumentException('Invalid provider endpoint');
        foreach($headers as $header)if(strpbrk($header,"\r\n")!==false)throw new InvalidArgumentException('Invalid credential');
        if($transport){$r=$transport($method,$url,$headers,$body);}
        else {
            $response='';$tooLarge=false;$ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($ch,$data)use(&$response,&$tooLarge){if(strlen($response)+strlen($data)>4194304){$tooLarge=true;return 0;}$response.=$data;return strlen($data);}]);
            if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$ok=curl_exec($ch);$r=['response'=>$response,'http_code'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'error'=>$ok===false||$tooLarge];curl_close($ch);
        }
        $code=(int)($r['http_code']??0);$raw=(string)($r['response']??'');
        $failure=static fn($message)=>['success'=>false,'http_code'=>$code,'error'=>$message,'data'=>null];
        if(!empty($r['error']))return $failure('Provider connection failed. Try again later.');
        if($code===429||$code===406)return $failure('Provider rate limit or request restriction. Retry later.');
        if($code===401||$code===403)return $failure('Provider denied access. Check credentials and API permissions.');
        if($code<200||$code>=300)return $failure('Provider request failed (HTTP '.$code.').');
        if(strlen($raw)>4194304)return $failure('Provider response too large.');
        $data=json_decode($raw,true);if(json_last_error()!==JSON_ERROR_NONE||!is_array($data))return $failure('Invalid provider response.');
        return ['success'=>true,'http_code'=>$code,'error'=>null,'data'=>$data];
    }
}
