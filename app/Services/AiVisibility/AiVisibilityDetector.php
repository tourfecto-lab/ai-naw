<?php
/** Deterministic entity/citation analysis. Rank means entity mention order in the returned sample. */
final class AiVisibilityDetector
{
    public static function normalize(string $text): string
    {
        if (class_exists('Normalizer')) $text=Normalizer::normalize($text,Normalizer::FORM_D);
        $text=strtr(mb_strtolower($text,'UTF-8'),['أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا','ى'=>'ي','ة'=>'ه','ؤ'=>'و','ئ'=>'ي','ـ'=>'',
            'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ß'=>'ss']);
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[^\p{L}\p{N}]+/u',' ',preg_replace('/\p{M}+/u','',$text))));
    }
    public static function host(string $url): string { return DataForSeoRankingSource::normalizeHost($url); }
    public static function safeUrl(string $url): ?string
    {
        $p=parse_url($url);
        if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array($p['scheme']??'',['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || strlen($url)>1800) return null;
        $ip=trim($p['host'],'[]');
        if (filter_var($ip,FILTER_VALIDATE_IP) && !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return null;
        if (preg_match('/(?:localhost|\.local|\.internal)$/i',$p['host'])) return null;
        return $url;
    }
    public static function aliases(string $name,array $entered=[],string $domain=''): array
    {
        $aliases=array_merge([$name],$entered); $normal=self::normalize($name);
        $generic=['tours','tour','travel','travels','adventures','adventure','company','agency','egypt','safari','رحلات','رحله','سياحه','للسياحه','للسفر','شركه','وكاله'];
        foreach(array_merge([$name],$entered) as $variant){$tokens=array_values(array_filter(explode(' ',self::normalize($variant)),fn($s)=>!in_array($s,$generic,true)));if($tokens)$aliases[]=implode(' ',$tokens);}
        $host=self::host($domain); if ($host!=='') { $aliases[]=$host; $stem=explode('.',$host)[0]; if (mb_strlen($stem)>=4) $aliases[]=$stem; }
        return array_values(array_unique(array_filter(array_map([self::class,'normalize'],$aliases),fn($s)=>mb_strlen($s)>=3 && !in_array($s,$generic,true))));
    }
    public static function firstMention(string $answer,array $aliases): ?int
    {
        $answer=self::normalize($answer); $first=null;
        foreach ($aliases as $alias) {
            $alias=self::normalize($alias); if ($alias==='') continue;
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias,'/').'(?![\p{L}\p{N}])/u',$answer,$m,PREG_OFFSET_CAPTURE))
                $first=$first===null?$m[0][1]:min($first,$m[0][1]);
        }
        return $first;
    }
    public static function analyze(string $answer,array $urls,array $brand,array $competitors): array
    {
        $entities=[];$brandAliases=self::aliases($brand['name'],$brand['aliases']??[],$brand['domain']);
        $own=self::firstMention($answer,$brandAliases); if ($own!==null) $entities[]=['own'=>true,'offset'=>$own];
        $mentioned=[];
        foreach ($competitors as $c) {
            $aliases=self::aliases((string)($c['name']??''),$c['aliases']??[],(string)$c['domain']);$at=self::firstMention($answer,$aliases);
            if ($at===null) continue;
            $mentioned[]=['name'=>$c['name']?:self::host($c['domain']),'domain'=>self::host($c['domain']),'offset'=>$at];$entities[]=['own'=>false,'offset'=>$at];
        }
        usort($entities,fn($a,$b)=>$a['offset']<=>$b['offset']); $position=null;
        foreach ($entities as $i=>$e) if ($e['own']) { $position=$i+1; break; }
        usort($mentioned,fn($a,$b)=>$a['offset']<=>$b['offset']);
        $safe=array_values(array_unique(array_filter(array_map(fn($u)=>is_string($u)?self::safeUrl($u):null,$urls))));$cited=false;
        foreach ($safe as $url) if (DataForSeoRankingSource::matchesHost($url,$brand['domain'])) $cited=true;
        return ['mentioned_brand'=>$own!==null,'brand_position'=>$position,'cited_domain'=>$cited,'cited_urls'=>$safe,'competitors_mentioned'=>$mentioned,
            'mention_position_basis'=>'first occurrence among configured customer and competitor entities; not a search ranking'];
    }
}
