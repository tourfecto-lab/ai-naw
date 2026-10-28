<?php
/** IndexNow accepts notifications only after public ownership and live-page checks. */
class IndexNowService
{
    private const ENGINES=['https://www.bing.com/indexnow','https://yandex.com/indexnow','https://www.seznam.cz/indexnow','https://searchadvisor.naver.com/indexnow'];
    public function __construct(private $reader=null, private $transport=null) { $this->reader ??= new SeoPublicPageReader(); }
    public function generateKey(): string { return bin2hex(random_bytes(16)); }
    public static function keyResponse(string $path, string $key): ?string {
        return preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$key) && $path==='/'.$key.'.txt' ? $key : null;
    }
    private static function onHost(string $url,string $host): bool {
        $p=parse_url($url);
        return filter_var($url,FILTER_VALIDATE_URL) && ($p['scheme']??'')==='https' && strtolower($p['host']??'')===$host && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) && !isset($p['fragment']);
    }
    private function prepare(string $host,string $key,array $urls,?string $keyLocation=null): array {
        $host=strtolower($host);$keyLocation ??= 'https://'.$host.'/'.$key.'.txt';
        if(!preg_match('/^[a-z0-9.-]+$/D',$host)||!preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$key)||!self::onHost($keyLocation,$host)||parse_url($keyLocation,PHP_URL_PATH)!=='/'.$key.'.txt'||parse_url($keyLocation,PHP_URL_QUERY)!==null)throw new InvalidArgumentException('Invalid customer key location');
        if(!$urls||count($urls)>100)throw new InvalidArgumentException('Provide 1–100 URLs');
        foreach($urls as $url)if(!is_string($url)||!self::onHost($url,$host))throw new InvalidArgumentException('URLs must use the exact customer HTTPS host');
        $r=$this->reader->fetch($keyLocation);
        if(empty($r['success'])||($r['http_status']??0)!==200||($r['url']??$keyLocation)!==$keyLocation||!hash_equals($key,trim((string)($r['html']??''))))throw new RuntimeException('Customer key file is not verified live');
        $live=[];$skipped=[];
        foreach(array_unique($urls) as $url){
            $r=$this->reader->fetch($url);$html=(string)($r['html']??'');
            $doc=new DOMDocument();@$doc->loadHTML($html?:'<html></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
            $noindex=false;foreach($xp->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]/@content') as $n)if(stripos($n->nodeValue,'noindex')!==false)$noindex=true;
            foreach($xp->query('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href') as $n)if($n->nodeValue!==$url)$noindex=true;
            if(!empty($r['success'])&&($r['http_status']??0)===200&&($r['url']??$url)===$url&&!$noindex&&!preg_match('/noindex/i',(string)($r['robots']??''))&&preg_match('/<(html|body|head)\b/i',$html))$live[]=$url;else $skipped[]=$url;
        }
        if(!$live)throw new RuntimeException('No verified live indexable URLs');
        return ['payload'=>['host'=>$host,'key'=>$key,'keyLocation'=>$keyLocation,'urlList'=>$live],'skipped'=>$skipped];
    }
    private function post(string $endpoint,array $prepared): array {
        $payload=$prepared['payload'];
        if($this->transport)$r=($this->transport)(['url'=>$endpoint,'method'=>'POST','body'=>json_encode($payload,JSON_UNESCAPED_SLASHES)]);
        else{
            $ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true]);curl_exec($ch);$r=['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'error'=>curl_error($ch)];curl_close($ch);
        }
        $status=(int)($r['status']??0);$ok=empty($r['error'])&&in_array($status,[200,202],true);
        return ['success'=>$ok,'status'=>$status,'submitted'=>$ok?count($payload['urlList']):0,'skipped'=>$prepared['skipped'],'error'=>$ok?null:'IndexNow request failed ('.$status.')'];
    }
    public function submitUrls(string $host,string $key,array $urls,?string $keyLocation=null): array {
        try{return $this->post('https://api.indexnow.org/indexnow',$this->prepare($host,$key,$urls,$keyLocation));}catch(Throwable $e){return ['success'=>false,'submitted'=>0,'error'=>$e->getMessage()];}
    }
    public function submitSingle(string $host,string $key,string $url): array {return $this->submitUrls($host,$key,[$url]);}
    public function submitToAllEngines(string $host,string $key,array $urls): array {
        try{$p=$this->prepare($host,$key,$urls);$out=[];foreach(self::ENGINES as $engine)$out[$engine]=$this->post($engine,$p);return $out;}catch(Throwable $e){return ['success'=>false,'error'=>$e->getMessage()];}
    }
}
