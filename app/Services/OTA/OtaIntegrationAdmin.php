<?php
/** Platform credential tests never copy a shared supplier identity into a customer's connection. */
final class OtaIntegrationAdmin
{
    public static function verify(string $platform,callable $value,?callable $factory=null): array
    {
        if($platform==='bokun_app')return ['ok'=>false,'message'=>'Save your Bókun app client ID and secret, whitelist the callback URL, then test authorization with your own vendor account. App credentials alone cannot verify a customer connection.'];
        $meta=OtaProviderCatalog::get($platform);
        if(!$meta['ready'])return ['ok'=>false,'message'=>'Setup credentials can be stored, but the '.$meta['label'].' live adapter is not enabled. Provider approval and a certified adapter are still required.'];
        $get=static function(string $key)use($value):string{$v=trim((string)$value($key));if(strlen($v)>2048||strpbrk($v,"\r\n")!==false)throw new InvalidArgumentException('Invalid OTA credential');return $v;};
        try{
            $environment=in_array($platform,['bokun','rezdy'],true)?($get(strtoupper($platform).'_ENVIRONMENT')?:'production'):'production';
            if(!in_array($environment,['production','sandbox'],true))throw new InvalidArgumentException('Environment must be production or sandbox');
            $values=[];foreach(IntegrationManager::meta($platform)['env_keys'] as $key)$values[$key]=$get($key);
            if($platform==='bokun'&&(!ctype_digit($values['BOKUN_VENDOR_ID'])||(int)$values['BOKUN_VENDOR_ID']<1||strlen($values['BOKUN_VENDOR_ID'])>18))throw new InvalidArgumentException('Enter a valid numeric Bókun vendor ID');
            $client=$factory?$factory($platform,$values,$environment):match($platform){
                'viator'=>new ViatorAPI($values['VIATOR_API_KEY']),
                'getyourguide'=>new GetYourGuideAPI($values['GETYOURGUIDE_ACCESS_TOKEN']),
                'bokun'=>new BokunAPI($values['BOKUN_ACCESS_KEY'],$values['BOKUN_SECRET_KEY'],(int)$values['BOKUN_VENDOR_ID'],$environment),
                'rezdy'=>new RezdyAPI($values['REZDY_API_KEY'],$environment),
            };
            $r=$client->verifyToken();return ['ok'=>!empty($r['success']),'message'=>!empty($r['success'])?'Platform credentials verified for '.($meta['mode']==='supplier_read'?'read-only supplier access':'partner catalog access').'. Customer accounts remain separate; booking/inventory writes are not enabled.':'Provider credentials could not be verified. Check the key, environment and API permissions.'];
        }catch(InvalidArgumentException $e){return ['ok'=>false,'message'=>$e->getMessage()];}catch(Throwable $e){return ['ok'=>false,'message'=>'Unable to verify OTA credentials. Check provider availability and permissions.'];}
    }
    public static function customerAccounts(int $uid,string $platform,?PDO $pdo=null): array
    {
        $meta=OtaProviderCatalog::get($platform);$pdo=$pdo??Database::getInstance()->getConnection();$q=$pdo->prepare('SELECT id,company_name,domain FROM websites WHERE user_id=? ORDER BY id');$q->execute([$uid]);$sites=$q->fetchAll(PDO::FETCH_ASSOC);
        if($meta['mode']==='legacy_partner'){$q=$pdo->prepare('SELECT website_id,status state FROM platform_connections WHERE user_id=? AND platform=?');}
        else{$q=$pdo->prepare('SELECT website_id,state FROM ota_supplier_connections WHERE user_id=? AND platform=?');}
        $q->execute([$uid,$platform]);$states=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$states[(int)$r['website_id']]=$r['state'];$out=[];
        foreach($sites as $s){$state=$states[(int)$s['id']]??'Not connected';if(!$meta['ready']&&$state!=='Not connected')$state='Setup profile saved; live adapter unavailable';elseif($state==='connected')$state='Partner catalog connected';elseif($state==='verified')$state='Supplier credentials verified; read-only';$out[]=['website_id'=>(int)$s['id'],'label'=>$s['company_name']?:$s['domain'],'state'=>$state];}return $out;
    }

    public static function appValue(string $key): string
    {
        $v=(new SystemSettingsService())->get('integration_'.strtolower($key),'');
        return $v!==''?(string)$v:(defined($key)?(string)constant($key):(string)getenv($key));
    }
    public static function bokunDomain(string $domain): string
    {
        $domain=strtolower(trim($domain));
        if(!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',$domain))throw new InvalidArgumentException('Enter the subdomain of your Bókun account, for example mytours.');
        return $domain;
    }
    public static function beginBokun(int $uid,int $wid,string $domain,?PDO $pdo=null): string
    {
        $pdo=$pdo??Database::getInstance()->getConnection();(new OtaSupplierService($pdo))->owned($uid,$wid);
        // Fail before redirect when the new SQL has not been installed.
        $pdo->query('SELECT id FROM ota_customer_authorizations LIMIT 0');
        $domain=self::bokunDomain($domain);$id=self::appValue('BOKUN_CLIENT_ID');$secret=self::appValue('BOKUN_CLIENT_SECRET');
        if($id===''||$secret==='')throw new InvalidArgumentException('Bókun authorization is not enabled by the platform administrator yet.');
        $callback=rtrim(APP_URL,'/').'/ota/bokun/callback';
        if(parse_url($callback,PHP_URL_SCHEME)!=='https')throw new InvalidArgumentException('The platform must use an HTTPS APP_URL.');
        $state=bin2hex(random_bytes(32));
        $_SESSION['ota_bokun_flow']=['uid'=>$uid,'wid'=>$wid,'domain'=>$domain,'state'=>$state,'expires'=>time()+600,'app_hash'=>hash('sha256',$id.'\0'.$secret)];
        return 'https://'.$domain.'.bokun.io/appstore/oauth/authorize?'.http_build_query(['client_id'=>$id,'scope'=>'PRODUCTS_READ,BOOKINGS_READ','redirect_uri'=>$callback,'state'=>$state],'','&',PHP_QUERY_RFC3986);
    }
    public static function finishBokun(int $uid,array $query,?PDO $pdo=null,?callable $exchange=null): void
    {
        $flow=$_SESSION['ota_bokun_flow']??null;unset($_SESSION['ota_bokun_flow']);
        if(!is_array($flow)||$flow['uid']!==$uid||$flow['expires']<time())throw new InvalidArgumentException('Authorization expired. Connect again.');
        foreach($query as $k=>$v)if(!is_string($v)||strlen($v)>4096)throw new InvalidArgumentException('Invalid authorization response.');
        if(!isset($query['domain'],$query['state'],$query['timestamp'],$query['hmac'],$query['code'])||!hash_equals($flow['state'],$query['state'])||self::bokunDomain($query['domain'])!==$flow['domain'])throw new InvalidArgumentException('Invalid authorization response.');
        if(!ctype_digit($query['timestamp'])||abs(time()-(int)$query['timestamp'])>600||$query['code']==='')throw new InvalidArgumentException('Authorization expired. Connect again.');
        $id=self::appValue('BOKUN_CLIENT_ID');$secret=self::appValue('BOKUN_CLIENT_SECRET');
        if($id===''||$secret===''||!hash_equals($flow['app_hash'],hash('sha256',$id.'\0'.$secret)))throw new InvalidArgumentException('Platform settings changed. Connect again.');
        $signature=$query['hmac'];unset($query['hmac']);ksort($query,SORT_STRING);
        if(!hash_equals(hash_hmac('sha256',http_build_query($query,'','&',PHP_QUERY_RFC3986),$secret),$signature))throw new InvalidArgumentException('Invalid provider signature.');
        $pdo=$pdo??Database::getInstance()->getConnection();(new OtaSupplierService($pdo))->owned($uid,$flow['wid']);
        $url='https://'.$flow['domain'].'.bokun.io/appstore/oauth/access_token';$body=['client_id'=>$id,'client_secret'=>$secret,'code'=>$query['code']];
        if($exchange)$data=$exchange($url,$body);
        else{
            $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if($raw===false||$status!==200||strlen($raw)>65536)throw new RuntimeException('Bókun could not complete authorization. Connect again.');
            $data=json_decode($raw,true);
        }
        if(!is_array($data)||!is_string($data['access_token']??null)||$data['access_token']===''||strlen($data['access_token'])>8192||!is_scalar($data['vendor_id']??null)||(string)$data['vendor_id']===''||strlen((string)$data['vendor_id'])>190||!is_string($data['scope']??null))throw new RuntimeException('Bókun returned an invalid authorization response.');
        $scopes=explode(',',$data['scope']);foreach(['PRODUCTS_READ','BOOKINGS_READ'] as $scope)if(!in_array($scope,$scopes,true))throw new RuntimeException('The required Bókun read permissions were not granted.');
        $encrypted=(new Encryption())->encrypt($data['access_token']);
        $q=$pdo->prepare("INSERT INTO ota_customer_authorizations(user_id,website_id,platform,provider_domain,account_id,token_encrypted,scopes,connected_at) VALUES(?,?,'bokun',?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE provider_domain=VALUES(provider_domain),account_id=VALUES(account_id),token_encrypted=VALUES(token_encrypted),scopes=VALUES(scopes),connected_at=UTC_TIMESTAMP()");
        $q->execute([$uid,$flow['wid'],$flow['domain'],(string)$data['vendor_id'],$encrypted,$data['scope']]);
    }
    public static function authorizedSites(int $uid,?PDO $pdo=null): array
    {
        $pdo=$pdo??Database::getInstance()->getConnection();$q=$pdo->prepare("SELECT a.website_id,a.account_id FROM ota_customer_authorizations a JOIN websites w ON w.id=a.website_id AND w.user_id=a.user_id WHERE a.user_id=? AND a.platform='bokun'");$q->execute([$uid]);return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    public static function disconnectBokun(int $uid,int $wid,?PDO $pdo=null): void
    {
        $pdo=$pdo??Database::getInstance()->getConnection();(new OtaSupplierService($pdo))->owned($uid,$wid);
        unset($_SESSION['ota_bokun_flow']);$q=$pdo->prepare("DELETE FROM ota_customer_authorizations WHERE user_id=? AND website_id=? AND platform='bokun'");$q->execute([$uid,$wid]);
    }

}
