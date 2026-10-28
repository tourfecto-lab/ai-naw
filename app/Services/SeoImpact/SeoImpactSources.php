<?php
require_once __DIR__.'/../SearchConsole/GoogleSearchConsoleAPI.php';
require_once __DIR__.'/../Analytics/GoogleAnalyticsAPI.php';
require_once __DIR__.'/../OAuth/GoogleOAuthClient.php';
final class SeoImpactSources {
    public function __construct(private Database $db){}
    private function connection(int $uid,int $wid,string $type): ?array {
        $r=$this->db->query("SELECT * FROM platform_connections WHERE user_id=? AND website_id=? AND platform=? AND status='connected' ORDER BY id DESC LIMIT 1",[$uid,$wid,$type]);return $r[0]??null;
    }
    private function token(array $c,string $scope): string {
        $e=new Encryption();$token=$e->decrypt($c['access_token']);
        if(!empty($c['token_expires_at'])&&strtotime($c['token_expires_at'].' UTC')<=time()+60){
            if(empty($c['refresh_token']))throw new RuntimeException('Reconnect the provider.');$r=(new GoogleOAuthClient($scope))->refreshAccessToken($e->decrypt($c['refresh_token']));
            if(empty($r['success']))throw new RuntimeException('Provider refresh unavailable.');$token=$r['access_token'];
            $this->db->query('UPDATE platform_connections SET access_token=?,token_expires_at=? WHERE id=? AND user_id=?',[$e->encrypt($token),gmdate('Y-m-d H:i:s',time()+(int)($r['expires_in']??3600)),$c['id'],$c['user_id']]);
        }return $token;
    }
    public function search(array $monitor,array $periods): array {
        $c=$this->connection((int)$monitor['user_id'],(int)$monitor['website_id'],'google_search_console');if(!$c)return ['state'=>'not_connected'];
        try {
            $property=(string)$c['external_location_id'];$host=(string)parse_url($monitor['page_url'],PHP_URL_HOST);
            $valid=str_starts_with($property,'sc-domain:')?($host===substr($property,10)||str_ends_with($host,'.'.substr($property,10))):(parse_url($property,PHP_URL_SCHEME)==='https'&&WordPressConnector::hostsEqual((string)parse_url($property,PHP_URL_HOST),$host)&&str_starts_with($monitor['page_url'],rtrim($property,'/').'/'));
            if(!$valid)return ['state'=>'property_mismatch'];$api=new GoogleSearchConsoleAPI($this->token($c,GoogleOAuthClient::SCOPE_SEARCH_CONSOLE));
            $out=['state'=>'collected','property'=>$property,'timezone'=>'America/Los_Angeles'];
            foreach(['before','after'] as $p)$out[$p]=$periods[$p]['end']>gmdate('Y-m-d',time()-3*86400)?['state'=>$p==='after'?'waiting_period':'waiting_data']: $api->getPagePeriod($property,$monitor['page_url'],$periods[$p]['start'],$periods[$p]['end']);
            return $out;
        }catch(Throwable $e){return ['state'=>'unavailable','note'=>'Review Search Console property, token and API access.'];}
    }
    public function analytics(array $monitor,array $periods): array {
        $c=$this->connection((int)$monitor['user_id'],(int)$monitor['website_id'],'google_analytics');if(!$c)return ['state'=>'not_connected'];
        try {
            $api=new GoogleAnalyticsAPI($this->token($c,GoogleOAuthClient::SCOPE_ANALYTICS));$property=(string)$c['external_location_id'];
            if(!$api->verifyWebHost($property,(string)parse_url($monitor['page_url'],PHP_URL_HOST)))return ['state'=>'property_host_unverified','note'=>'GA4 web stream must match this website.'];
            $out=['state'=>'collected','property'=>$property];foreach(['before','after'] as $p)$out[$p]=$periods[$p]['end']>gmdate('Y-m-d',time()-3*86400)?['state'=>$p==='after'?'waiting_period':'waiting_data']:$api->getLandingPeriod($property,$monitor['page_url'],$periods[$p]['start'],$periods[$p]['end'],$monitor['goal_event'],$monitor['channel']);return $out;
        }catch(Throwable $e){return ['state'=>'unavailable','note'=>'Review GA4 property, registered key event and API permissions.'];}
    }
    /** Saved connection readiness, not a provider health check or plugin verification. */
    public function readiness(int $uid,int $wid,string $home): array {
        $out=['search'=>'unavailable','analytics'=>'unavailable','wordpress_orders_available'=>false];
        try{
            $out['search']=$this->connection($uid,$wid,'google_search_console')?'saved_connection':'not_connected';
            $out['analytics']=$this->connection($uid,$wid,'google_analytics')?'saved_connection':'not_connected';
            $this->assertWordPressTarget($uid,$wid,$home);$out['wordpress_orders_available']=true;
        }catch(InvalidArgumentException $e){/* A missing or different WordPress target is not a PHP measurement failure. */}
        catch(Throwable $e){/* Leave unknown connections unavailable, never pretend a provider was verified. */}
        return $out;
    }
    private function wordpressTarget(int $uid,int $wid,string $home): array {
        $c=$this->connection($uid,$wid,'wordpress');if(!$c)throw new InvalidArgumentException('Connect WordPress publishing first.');$url=rtrim((string)$c['external_location_id'],'/');$p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!WordPressConnector::hostsEqual((string)($p['host']??''),(string)parse_url($home,PHP_URL_HOST)))throw new InvalidArgumentException('Wrong WordPress target.');
        return [$c,$url];
    }
    public function assertWordPressTarget(int $uid,int $wid,string $home): void {$this->wordpressTarget($uid,$wid,$home);}
    public function wordpress(int $uid,int $wid,string $home,array $payload): array {
        [$c,$url]=$this->wordpressTarget($uid,$wid,$home);
        $body='';$r=SafeHttp::exec($url.'/wp-json/tourfecto-measure/v1/configure',[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Basic '.base64_encode((new Encryption())->decrypt($c['access_token']))],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){$body.=$chunk;return strlen($body)>100000?0:strlen($chunk);} ],0);
        $j=json_decode($body,true);if($r['error']!==''||(int)$r['code']!==200||!is_array($j)||($j['state']??'')!=='configured')throw new InvalidArgumentException('Install Tourfecto Measure on WordPress and use an account allowed to manage WooCommerce. Configuration could not be confirmed.');
        if(!WordPressConnector::hostsEqual((string)parse_url((string)($j['site_url']??''),PHP_URL_HOST),(string)parse_url($home,PHP_URL_HOST))||rtrim((string)parse_url($j['site_url']??'',PHP_URL_PATH),'/')!==rtrim((string)parse_url($url,PHP_URL_PATH),'/'))throw new RuntimeException('WordPress source identity mismatch.');return $j;
    }
}
