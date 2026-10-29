<?php
require_once __DIR__.'/../AutoFix/PageContext.php';
/** Bounded HTML-only fetches, using the existing per-hop SSRF/DNS-pinning transport. */
class SeoExpertPageContext extends PageContext
{
    private string $robotsHeader='';
    private bool $nativeOwner=false;
    private string $guideLayout='';
    private array $publicationAnchors=[];
    private bool $wordpress=false;
    protected function isImage(string $url): bool { return false; } // No image URL is presented as verified.
    private bool $publicationRead=false;
    private bool $sitemapRead=false;
    protected function textLimit(): int { return $this->publicationRead?60000:2500; }
    protected function contentSignals(string $text, DOMXPath $xp): array { require_once __DIR__.'/SeoTourContentAudit.php'; return SeoTourContentAudit::signals($text, $xp); }
    /** HTML block boundaries remain word boundaries even when the markup is minified. */
    protected function bodyText(DOMNode $body): string
    {
        if(!$this->publicationRead)return parent::bodyText($body);
        $copy=$body->cloneNode(true);$doc=$copy->ownerDocument;$xp=new DOMXPath($doc);
        $blocks=$xp->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::div or self::li or self::section or self::article or self::main or self::aside or self::header or self::ul or self::ol or self::dl or self::dt or self::dd or self::figure or self::figcaption or self::table or self::tr or self::td or self::th or self::blockquote or self::pre or self::address or self::br or self::hr]',$copy);
        foreach(iterator_to_array($blocks) as $node){
            $node->parentNode->insertBefore($doc->createTextNode(' '),$node);
            $node->parentNode->insertBefore($doc->createTextNode(' '),$node->nextSibling);
        }
        return parent::bodyText($copy);
    }
    /** Full public text for verification only; planning keeps its existing bounded excerpt. */
    public function freshPublication(string $url): array {
        $previous=$this->publicationRead;$this->publicationRead=true;
        try{return $this->fresh($url);}finally{$this->publicationRead=$previous;}
    }
    protected function get(string $url,bool $withBody,string $method='GET'): array {
        $body='';$headers=[];
        $r=SafeHttp::exec($url,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$this->sitemapRead?4:10,CURLOPT_CONNECTTIMEOUT=>$this->sitemapRead?2:4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_USERAGENT=>'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml','Accept-Language: en-US,en;q=0.9'],
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$body,&$headers){if(preg_match('#^HTTP/#i',$line)){$headers=[];$body='';}elseif(strpos($line,':')!==false){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body,$withBody){if($withBody)$body.=$chunk;return strlen($body)>1500000?0:strlen($chunk);}],4);
        if($withBody&&$body!==''&&class_exists('DOMDocument')){$doc=new DOMDocument();$old=libxml_use_internal_errors(true);try{if($doc->loadHTML($body,LIBXML_NONET)){$xp=new DOMXPath($doc);$this->nativeOwner=$xp->query('//head/meta[@name="tourfecto-seo-owner" and @content="native"]')->length>0;$this->guideLayout=$xp->evaluate('string(//head/meta[@name="tourfecto-guide-layout"]/@content)');if($this->publicationRead){foreach($xp->query('//*[@data-tourfecto-guide]//a[@href]|//article//a[@href]') as $anchor){$href=$anchor->getAttribute('href');if(filter_var($href,FILTER_VALIDATE_URL)&&in_array(parse_url($href,PHP_URL_SCHEME),['http','https'],true)&&!isset($this->publicationAnchors[$href]))$this->publicationAnchors[$href]=true;if(count($this->publicationAnchors)>=80)break;}}}}finally{libxml_clear_errors();libxml_use_internal_errors($old);}}
        $this->wordpress=$withBody && preg_match('~wp-content/|wp-includes/|wp-json/|name=["\']generator["\'][^>]*WordPress~i',$body)===1;
        $this->robotsHeader=(string)($headers['x-robots-tag']??'');
        return ['http'=>$r['code'],'body'=>$body,'headers'=>$headers,'error'=>$r['error']!==''?'fetch_failed':null,'final_url'=>$r['url']?:$url];
    }
    /** Bounded same-site XML reads. A sitemap entry is discovery evidence, never proof of Google indexing. */
    public function publicationSitemap(string $siteUrl,string $articleUrl,bool $connector): array
    {
        $origin=parse_url($siteUrl);$base=rtrim($siteUrl,'/');
        $pending=$connector?[$base.'/?tf_guides_sitemap=1',$base.'/tourfecto-guides-sitemap.xml']:[$base.'/wp-sitemap.xml',$base.'/sitemap_index.xml',$base.'/sitemap.xml'];
        $seen=[];$read=false;$failed=false;$deadline=microtime(true)+12;
        while($pending && count($seen)<5 && microtime(true)<$deadline){
            $url=array_shift($pending);if(isset($seen[$url]))continue;$seen[$url]=true;
            $p=parse_url($url);
            if(!$p||($p['host']??'')!==($origin['host']??'')||($p['scheme']??'')!==($origin['scheme']??'')||($p['port']??null)!==($origin['port']??null)||isset($p['user'])||isset($p['pass']))continue;
            $this->sitemapRead=true;
            try{$r=$this->get($url,true);}catch(Throwable $e){$failed=true;continue;}finally{$this->sitemapRead=false;}
            if(isset($r['final_url'])&&strtolower((string)parse_url($r['final_url'],PHP_URL_HOST))!==strtolower($origin['host']??'')){$failed=true;continue;}
            if($r['error']!==null||$r['http']!==200||strlen($r['body'])>1500000||preg_match('/<!DOCTYPE|<!ENTITY/i',$r['body'])){$failed=true;continue;}
            $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
            try{$ok=$doc->loadXML($r['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
            if(!$ok||!in_array($doc->documentElement->localName,['urlset','sitemapindex'],true)){$failed=true;continue;}
            $read=true;$xp=new DOMXPath($doc);
            if($doc->documentElement->localName==='urlset'){
                foreach($xp->query('/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]') as $loc)if(trim($loc->textContent)===$articleUrl)return ['state'=>'passed','url'=>$url];
            }else{
                foreach($xp->query('/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]') as $loc){$next=trim($loc->textContent);if(!isset($seen[$next]))$pending[]=$next;if(count($pending)>=20)break;}
                // Prefer post/article sitemaps within the fixed request budget.
                usort($pending,static fn($a,$b)=>(int)!preg_match('/post|article|guide/i',$a)<=>(int)!preg_match('/post|article|guide/i',$b));
            }
        }
        return ['state'=>$read&&!$failed&&!$pending?'missing':'not_measured','url'=>null];
    }
    /** Check a bounded set of visible related targets without mutating or billing the site. */
    public function publicationLinks(array $urls,string $siteUrl): array
    {
        $origin=parse_url($siteUrl);$checks=[];$deadline=microtime(true)+8;
        foreach(array_slice(array_values(array_unique($urls)),0,4) as $url){
            $checks[$url]='not_measured';$p=parse_url($url);
            if(!$p||($p['host']??'')!==($origin['host']??'')||($p['scheme']??'')!==($origin['scheme']??'')||($p['port']??null)!==($origin['port']??null)||isset($p['user'])||isset($p['pass'])||microtime(true)>=$deadline)continue;
            $this->sitemapRead=true;
            try{
                $r=$this->get($url,false,'HEAD');
                if(in_array($r['http'],[405,501],true)&&microtime(true)<$deadline)$r=$this->get($url,true);
                $final=parse_url($r['final_url']??$url);
                if($r['error']!==null||($final['host']??'')!==($origin['host']??''))continue;
                $checks[$url]=$r['http']===200?'passed':'missing';
            }catch(Throwable $e){error_log('[SeoExpertPageContext] ignored error: ' . $e->getMessage());}finally{$this->sitemapRead=false;}
        }
        return $checks;
    }
    public function fresh(string $url): array {
        $this->nativeOwner=false;$this->robotsHeader='';$this->guideLayout='';$this->publicationAnchors=[];$started=microtime(true);$r=$this->fetch($url);$r['fetch_seconds']=round(microtime(true)-$started,3);$r['url']=$url;$r['wordpress']=$this->wordpress;$r['guide_layout']=$this->guideLayout;$r['robots_header']=$this->robotsHeader;$r['native_seo_owner']=!empty($r['ok'])&&$this->nativeOwner;if($this->robotsHeader!=='' && !empty($r['ok']))$r['robots']=trim((string)($r['robots']??'').' '.strtolower($this->robotsHeader));
        if(isset($r['final_url'])){$r['final_url']=preg_replace('/([?&])_tourfecto_audit=[a-f0-9]+(?:&|$)/','$1',$r['final_url']);$r['final_url']=rtrim(str_replace('?&','?',$r['final_url']),'?&');}
        if($this->publicationRead)$r['publication_links']=array_keys($this->publicationAnchors);
        return $r;
    }
}
