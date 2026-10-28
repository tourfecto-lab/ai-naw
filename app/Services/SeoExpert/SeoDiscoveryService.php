<?php
require_once __DIR__.'/SeoPagePolicy.php';
/** Bounded resumable discovery. All returned URLs still require a fresh public-page audit. */
final class SeoDiscoveryService {
    private $transport;
    public function __construct(?callable $transport=null) { $this->transport=$transport; }
    public static function pageUrl(string $url,string $home): ?string {
        if(strlen($url)>1800)return null;$p=parse_url($url);$host=(string)parse_url($home,PHP_URL_HOST);
        if(!$p || !in_array($p['scheme']??'',['https','http'],true) || empty($p['host']) || !WordPressConnector::hostsEqual($p['host'],$host) || isset($p['user']) || isset($p['pass']) || !empty($p['query']) || isset($p['port']) && $p['port']!==parse_url($home,PHP_URL_PORT))return null;
        $path=$p['path']??'/';if(!SeoPagePolicy::route($url)['eligible'])return null;if(strlen($path)>1000 || preg_match('/[\x00-\x20\\\\]/',$path) || preg_match('#/(?:wp-admin|wp-login(?:\.php)?|cart|checkout|account|my-account|login|logout|wp-json|api|admin)(?:/|$)#i',$path) || preg_match('/\.(?:xml|pdf|zip|gz|css|js|png|jpe?g|webp|svg|gif|avif|ico|woff2?|ttf|eot|mp3|mp4)$/i',$path))return null;
        return $p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').$path;
    }
    private static function sitemapUrl(string $url,string $home): ?string {
        if(strlen($url)>1800)return null;$p=parse_url($url);if(!$p || !in_array($p['scheme']??'',['https','http'],true) || !WordPressConnector::hostsEqual((string)($p['host']??''),(string)parse_url($home,PHP_URL_HOST)) || isset($p['user']) || isset($p['pass']) || !empty($p['query']) || (isset($p['port']) && $p['port']!==parse_url($home,PHP_URL_PORT)) || preg_match('/[\x00-\x20\\\\]/',$url))return null;
        return $url;
    }
    public static function parseSitemap(string $xml,string $home): array {
        if(strlen($xml)>2000000 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))return ['pages'=>[],'sitemaps'=>[],'valid'=>false];
        $prior=libxml_use_internal_errors(true);$doc=new DOMDocument();
        try {
            if(!$doc->loadXML($xml,LIBXML_NONET) || !$doc->documentElement)return ['pages'=>[],'sitemaps'=>[],'valid'=>false];
            $root=$doc->documentElement->localName;if(!in_array($root,['urlset','sitemapindex'],true))return ['pages'=>[],'sitemaps'=>[],'valid'=>false];
            $xp=new DOMXPath($doc);$nodes=$xp->query($root==='urlset'?'/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]':'/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]');$urls=[];
            foreach($nodes as $node){$raw=trim($node->textContent);$url=$root==='urlset'?self::pageUrl($raw,$home):self::sitemapUrl($raw,$home);if($url!==null)$urls[$url]=true;if(count($urls)>=5000)break;}
            return ['pages'=>$root==='urlset'?array_keys($urls):[],'sitemaps'=>$root==='sitemapindex'?array_keys($urls):[],'valid'=>true];
        } finally { libxml_clear_errors();libxml_use_internal_errors($prior); }
    }
    private function fetch(string $url,?array $credentials=null): array {
        if($this->transport)return ($this->transport)($url,$credentials);
        $body='';$headers=['Cache-Control: no-cache','Accept: application/xml, text/plain, application/json'];$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){$body.=$chunk;return strlen($body)>2000000?0:strlen($chunk);}];
        if($credentials){$headers[]='Content-Type: application/json';$headers[]='Authorization: Basic '.base64_encode($credentials['auth']);$opts[CURLOPT_CUSTOMREQUEST]='POST';$opts[CURLOPT_POSTFIELDS]=json_encode(['page'=>$credentials['page']],JSON_THROW_ON_ERROR);}
        $opts[CURLOPT_HTTPHEADER]=$headers;$r=SafeHttp::exec($url,$opts,0);
        return ['ok'=>$r['error']===''&&(int)$r['code']===200,'body'=>$body];
    }
    public function advance(string $home,?array $state=null,?array $wordpress=null): array {
        $origin=parse_url($home,PHP_URL_SCHEME).'://'.parse_url($home,PHP_URL_HOST).(parse_url($home,PHP_URL_PORT)?':'.parse_url($home,PHP_URL_PORT):'');$deadline=microtime(true)+18;
        if($state===null) {
            $state=['sitemap_queue'=>[$origin.'/sitemap.xml',$origin.'/wp-sitemap.xml',$origin.'/sitemap_index.xml'],'sitemap_seen'=>[],'wordpress_page'=>1,'wordpress_state'=>$wordpress?'pending':'not_connected','known_urls'=>[],'warnings'=>[],'sources'=>['sitemap'=>0,'wordpress'=>0]];
            $robots=$this->fetch($origin.'/robots.txt');
            if($robots['ok'] && preg_match_all('/^Sitemap:\s*(\S+)/mi',$robots['body'],$matches))foreach($matches[1] as $raw)if($u=self::sitemapUrl($raw,$home))array_unshift($state['sitemap_queue'],$u);
        }
        if(!$wordpress && $state['wordpress_state']==='pending'){$state['wordpress_state']='unavailable';$state['warnings'][]='wordpress_connection_changed';}
        $new=[];$processed=0;
        while($state['sitemap_queue'] && $processed<2 && count($state['sitemap_seen'])<30 && microtime(true)<$deadline) {
            $url=array_shift($state['sitemap_queue']);if(in_array($url,$state['sitemap_seen'],true))continue;$state['sitemap_seen'][]=$url;$processed++;
            $r=$this->fetch($url);if(!$r['ok'])continue;$parsed=self::parseSitemap($r['body'],$home);
            if(!$parsed['valid']){$state['warnings'][]='invalid_or_unsafe_sitemap';continue;}
            $state['sitemap_queue']=array_values(array_unique(array_merge($state['sitemap_queue'],$parsed['sitemaps'])));
            foreach($parsed['pages'] as $u)if(!isset($state['known_urls'][hash('sha256',$u)]) && count($state['known_urls'])<5000){$state['known_urls'][hash('sha256',$u)]=true;$new[]=$u;$state['sources']['sitemap']++;}
        }
        if(count($state['sitemap_seen'])>=30 && $state['sitemap_queue']){$state['warnings'][]='sitemap_limit_30';$state['sitemap_queue']=[];}
        if($wordpress && $state['wordpress_state']==='pending' && microtime(true)<$deadline) {
            $r=$this->fetch(rtrim($wordpress['url'],'/').'/wp-json/tourfecto-editor/v1/discover',['auth'=>$wordpress['auth'],'page'=>$state['wordpress_page']]);$j=$r['ok']?json_decode($r['body'],true):null;
            $valid=is_array($j)&&is_array($j['items']??null)&&WordPressConnector::hostsEqual((string)parse_url((string)($j['site_url']??''),PHP_URL_HOST),(string)parse_url($home,PHP_URL_HOST))&&rtrim((string)parse_url($j['site_url']??'',PHP_URL_PATH),'/')===rtrim((string)parse_url($wordpress['url'],PHP_URL_PATH),'/');
            if(!$valid){$state['wordpress_state']='unavailable';$state['warnings'][]='wordpress_inventory_unavailable';}
            else {foreach($j['items'] as $item)if(is_string($item['url']??null)&&($u=self::pageUrl($item['url'],$home))&&!isset($state['known_urls'][hash('sha256',$u)])&&count($state['known_urls'])<5000){$state['known_urls'][hash('sha256',$u)]=true;$new[]=$u;$state['sources']['wordpress']++;}
                if(!empty($j['has_more'])&&$state['wordpress_page']<10)$state['wordpress_page']++;else {$state['wordpress_state']='complete';if(!empty($j['has_more']))$state['warnings'][]='wordpress_inventory_limit_1000';}}
        }
        if(count($state['known_urls'])>=5000){$state['warnings'][]='discovery_limit_5000';$state['sitemap_queue']=[];if($state['wordpress_state']==='pending')$state['wordpress_state']='limited';}
        $state['warnings']=array_values(array_unique($state['warnings']));$state['sitemap_queue']=array_slice($state['sitemap_queue'],0,60);
        return ['state'=>$state,'urls'=>$new,'pending'=>!empty($state['sitemap_queue'])||$state['wordpress_state']==='pending'];
    }
}
