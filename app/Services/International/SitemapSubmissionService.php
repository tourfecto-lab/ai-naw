<?php
/** Explicit, connection-bound write permission; readonly connections never submit. */
class SitemapSubmissionService
{
    public const SCOPE='https://www.googleapis.com/auth/webmasters';
    public function __construct(private Database $db,private $clientFactory=null,private $reader=null){$this->reader??=new SeoPublicPageReader();}
    public static function granted(string $scopes): bool {return in_array(self::SCOPE,preg_split('/\s+/',trim($scopes))?:[],true);}
    public static function covers(string $property,string $url): bool {
        if(!HreflangService::url($url))return false;$u=parse_url($url);
        if(str_starts_with($property,'sc-domain:')){$d=strtolower(substr($property,10));$h=strtolower($u['host']);return $d!==''&&($h===$d||str_ends_with($h,'.'.$d));}
        if(!HreflangService::url($property))return false;$p=parse_url($property);
        return ($p['scheme']??'')===($u['scheme']??'')&&strtolower($p['host'])===strtolower($u['host'])&&($p['port']??443)===($u['port']??443)&&str_starts_with($u['path']??'/',rtrim($p['path']??'/','/').'/');
    }
    public static function submit(object $api,string $property,string $url,string $scopes,bool $enabled,SeoPublicPageReader $reader): array {
        if(!$enabled||!self::granted($scopes))return ['success'=>false,'state'=>'permission_required','error'=>'Reconnect with Allow sitemap submit'];
        if(!self::covers($property,$url))return ['success'=>false,'state'=>'wrong_property','error'=>'Sitemap is outside the selected Search Console property'];
        $r=$reader->fetch($url);$xml=(string)($r['html']??'');
        $doc=new DOMDocument();$ok=strlen($xml)<=1000000&&!preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)&&@$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        if(empty($r['success'])||($r['http_status']??0)!==200||($r['url']??$url)!==$url||!$ok||!in_array($doc->documentElement->localName??'', ['urlset','sitemapindex'],true))return ['success'=>false,'state'=>'sitemap_not_live','error'=>'Sitemap must be live valid XML at the exact selected URL'];
        $submitted=$api->submitSitemap($property,$url);
        $listed=$api->listSitemaps($property);
        return ['success'=>!empty($submitted['success']),'state'=>!empty($submitted['success'])?'submitted':'api_error','error'=>$submitted['error']??null,'sitemaps'=>$listed['sitemaps']??[],'listing_ok'=>!empty($listed['ok']),'listing_error'=>empty($listed['ok'])?'Search Console sitemap listing unavailable':null];
    }
    public function run(int $uid,int $wid,?string $url=null,bool $force=false): array {
        $permission=$this->db->query("SELECT p.*,c.status,c.external_location_id,w.main_url FROM seo_sitemap_permissions p JOIN platform_connections c ON c.id=p.connection_id AND c.user_id=p.user_id AND c.website_id=p.website_id AND c.platform='google_search_console' JOIN websites w ON w.id=p.website_id AND w.user_id=p.user_id WHERE p.website_id=? AND p.user_id=?",[$wid,$uid])[0]??null;
        if(!$permission||!$permission['enabled']||$permission['status']!=='connected'||!self::granted($permission['granted_scope']))return ['success'=>false,'state'=>'permission_required'];
        $client=$this->clientFactory?($this->clientFactory)($uid,$wid):SeoTrafficAlertService::client($uid,$wid);
        if(!$client)return $this->save($uid,$wid,['success'=>false,'state'=>'reconnect_required','error'=>'Search Console access unavailable']);
        [$api,$property]=$client;
        $url ??= $permission['sitemap_url']?:(self::detect($this->reader,$permission['main_url'])??rtrim($permission['main_url'],'/').'/sitemap.xml');
        // Never turn a user-entered sitemap into a request to another host.
        if(strtolower((string)parse_url($url,PHP_URL_HOST))!==strtolower((string)parse_url($permission['main_url'],PHP_URL_HOST)))throw new InvalidArgumentException('Sitemap must be on the customer domain');
        $old=json_decode($permission['result_json']??'',true)?:[];
        if(!$force&&$url===$permission['sitemap_url']&&($old['state']??'')==='submitted'){
            $listed=$api->listSitemaps($property);$result=array_replace($old,['sitemaps'=>$listed['sitemaps']??[],'listing_ok'=>!empty($listed['ok']),'listing_error'=>empty($listed['ok'])?'Search Console sitemap listing unavailable':null]);
        }else $result=self::submit($api,$property,$url,$permission['granted_scope'],true,$this->reader);
        return $this->save($uid,$wid,$result,$url);
    }
    /** بيلاقي خريطة الموقع الحقيقية: من سطر Sitemap في robots.txt، أو الأسماء المعروفة (Yoast وووردبريس). */
    public static function detect(SeoPublicPageReader $reader,string $mainUrl): ?string {
        $base=rtrim($mainUrl,'/');$host=strtolower((string)parse_url($mainUrl,PHP_URL_HOST));$candidates=[];
        try{$robots=$reader->fetch($base.'/robots.txt');if(!empty($robots['success'])&&($robots['http_status']??0)===200&&preg_match_all('/^\s*Sitemap:\s*(\S+)/mi',(string)($robots['html']??''),$m))foreach($m[1] as $u)if(strtolower((string)parse_url($u,PHP_URL_HOST))===$host)$candidates[]=$u;}catch(Throwable $e){}
        foreach(['/sitemap_index.xml','/sitemap.xml','/wp-sitemap.xml'] as $path)$candidates[]=$base.$path;
        foreach(array_unique($candidates) as $url){
            try{$r=$reader->fetch($url);}catch(Throwable $e){continue;}
            $xml=(string)($r['html']??'');if(empty($r['success'])||($r['http_status']??0)!==200||strlen($xml)>1000000||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))continue;
            $doc=new DOMDocument();if(@$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)&&in_array($doc->documentElement->localName??'',['urlset','sitemapindex'],true))return ($r['url']??$url)===$url?$url:null;
        }
        return null;
    }
    private function save(int $uid,int $wid,array $result,?string $url=null): array {
        $this->db->exec('UPDATE seo_sitemap_permissions SET sitemap_url=COALESCE(?,sitemap_url),result_json=?,checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE website_id=? AND user_id=?',[$url,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$wid,$uid]);return $result;
    }
    public function daily(): int {
        $rows=$this->db->query("SELECT p.website_id,p.user_id FROM seo_sitemap_permissions p JOIN platform_connections c ON c.id=p.connection_id AND c.website_id=p.website_id AND c.user_id=p.user_id AND c.platform='google_search_console' AND c.status='connected' WHERE p.enabled=1 AND (p.checked_at IS NULL OR p.checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)) ORDER BY COALESCE(p.checked_at,'1970-01-01'),p.website_id LIMIT 10");$n=0;
        foreach($rows as $r)try{$this->run((int)$r['user_id'],(int)$r['website_id']);$n++;}catch(Throwable $e){Logger::warning('Sitemap sync unavailable',['website_id'=>$r['website_id']]);}return $n;
    }
}
