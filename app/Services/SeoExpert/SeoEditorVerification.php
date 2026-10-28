<?php
/** Anonymous, bounded same-site HTML checks. Never sends WordPress credentials to public URLs. */
final class SeoEditorVerification {
    private $transport;
    public function __construct(?callable $transport=null){$this->transport=$transport;}
    private static function text(string $value): string {
        return trim((string)preg_replace('/\s+/u',' ',html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8')));
    }
    private function fetch(string $url,bool $fresh): array {
        if($this->transport)return ($this->transport)($url,$fresh);
        $body='';$headers=[];
        $r=SafeHttp::exec($url,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'TourfectoEditorVerification/1.0',CURLOPT_HTTPHEADER=>$fresh?['Accept: text/html','Cache-Control: no-cache','Pragma: no-cache']:['Accept: text/html'],
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){if(strpos($line,':')!==false){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){$body.=$chunk;return strlen($body)>1500000?0:strlen($chunk);}],0);
        return ['code'=>(int)$r['code'],'error'=>$r['error'],'body'=>$body,'content_type'=>$headers['content-type']??''];
    }
    private static function inspect(array $response,array $expected): array {
        if(($response['error']??'')!==''||($response['code']??0)!==200||strlen($response['body']??'')>1500000||!str_contains(strtolower($response['content_type']??''),'text/html'))return ['state'=>'unavailable','checks'=>[]];
        if(!class_exists('DOMDocument'))return ['state'=>'unavailable','checks'=>[]];
        $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{$ok=$doc->loadHTML('<?xml encoding="UTF-8">'.($response['body']??''),LIBXML_NONET);libxml_clear_errors();}finally{libxml_use_internal_errors($old);}
        if(!$ok)return ['state'=>'unavailable','checks'=>[]];
        $xp=new DOMXPath($doc);
        foreach($xp->query('//script|//style|//noscript|//template') as $node)$node->parentNode->removeChild($node);
        $titles=[];foreach($xp->query('//head/title') as $n)$titles[]=self::text($n->textContent);
        $descriptions=[];foreach($xp->query('//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content') as $n)$descriptions[]=self::text($n->nodeValue);
        $h1=[];foreach($xp->query('//h1') as $n)$h1[]=self::text($n->textContent);
        $body='';foreach($xp->query('//body') as $node)$body.=' '.$node->textContent;$body=self::text($body);
        $checks=[];$all=true;
        foreach($expected as $item){
            if(!is_array($item)||!is_string($item['field']??null)||!is_string($item['value']??null)||strlen($item['value'])>60000||!in_array($item['kind']??'', ['title','description','h1','text'],true))throw new RuntimeException('Invalid WordPress verification evidence.');
            $value=self::text($item['value']);
            $match=$value!==''&&match($item['kind']){'title'=>count($titles)===1&&$titles[0]===$value,'description'=>count($descriptions)===1&&$descriptions[0]===$value,'h1'=>in_array($value,$h1,true),'text'=>str_contains($body,$value)};
            $all=$all&&$match;$checks[]=['field'=>$item['field'],'state'=>$match?'seen':'not_seen'];
        }
        return ['state'=>$expected?($all?'verified':'needs_review'):'manual_check','checks'=>$checks];
    }
    public function check(string $target,array $evidence): array {
        $base=['stored_state'=>$evidence['stored_state']??'needs_review','public_state'=>'not_checked','uncheckable_fields'=>$evidence['uncheckable_fields']??[],'checked_at'=>gmdate('c'),'note'=>'HTML text checks do not prove visual layout, Google indexing, rankings or booking availability.'];
        if($base['stored_state']!=='verified')return $base+['reason'=>'stored_values_changed'];
        if(($evidence['page']['status']??'')!=='publish')return array_replace($base,['public_state'=>'not_public','reason'=>'draft_or_pending']);
        $url=(string)($evidence['page']['url']??'');$u=parse_url($url);$t=parse_url($target);
        $prefix=rtrim($t['path']??'','/');$path=$u['path']??'/';
        if(!$u||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||isset($u['port'])||str_contains($path,'..')||!WordPressConnector::hostsEqual($u['host']??'',$t['host']??'')||($prefix!==''&&$path!==$prefix&&!str_starts_with($path,$prefix.'/')))throw new RuntimeException('Public verification URL does not match this WordPress site.');
        $expected=$evidence['expectations']??[];if(!is_array($expected)||count($expected)>100)throw new RuntimeException('Invalid public expectations.');
        if(!$expected)return array_replace($base,['public_state'=>'manual_check','reason'=>'no_directly_checkable_public_fields']);
        try{
            $normal=self::inspect($this->fetch($url,false),$expected);
            $freshUrl=$url.'?_tourfecto_audit='.bin2hex(random_bytes(8));
            $fresh=self::inspect($this->fetch($freshUrl,true),$expected);
        }catch(Throwable $e){return array_replace($base,['public_state'=>'unavailable','reason'=>'public_fetch_or_parse_failed']);}
        $state=$normal['state']==='verified'&&$fresh['state']==='verified'?'verified':($normal['state']==='needs_review'&&$fresh['state']==='verified'?'cache_suspected':($normal['state']==='unavailable'||$fresh['state']==='unavailable'?'unavailable':'needs_review'));
        if($state==='verified'&&!empty($base['uncheckable_fields']))$state='partial_verified';
        return array_replace($base,['public_state'=>$state,'normal'=>$normal,'fresh'=>$fresh,'url'=>$url]);
    }
}
