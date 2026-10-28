<?php
/** Shared, escaped article presentation. Embedded verbatim in the standalone PHP connector. */
final class GuideContent
{
    private static function inline(string $text): string
    {
        $escape=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $plain=static fn(string $s):string=>preg_replace('/\*\*([^*\n]+)\*\*/u','<strong>$1</strong>',$escape($s));
        $parts=preg_split('~(\[[^\]\r\n]{1,500}\]\(https?://[^\s)]+\))~u',$text,-1,PREG_SPLIT_DELIM_CAPTURE)?:[$text];$html='';
        foreach($parts as $part){
            if(preg_match('~^\[([^\]\r\n]{1,500})\]\((https?://[^\s)]+)\)$~u',$part,$m)){
                $url=$m[2];$parsed=parse_url($url);
                if(filter_var($url,FILTER_VALIDATE_URL)&&$parsed&&in_array($parsed['scheme']??'',['http','https'],true)&&!isset($parsed['user'])&&!isset($parsed['pass'])){$html.='<a href="'.$escape($url).'">'.$plain($m[1]).'</a>';continue;}
            }
            $html.=$plain($part);
        }
        return $html;
    }

    public static function html(string $text): string
    {
        $escape=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $inline=static fn(string $s):string=>self::inline($s);
        $html='';$paragraph=[];$list=[];
        $flush=function()use(&$html,&$paragraph,&$list,$inline):void{
            if($paragraph){$html.='<p>'.implode('<br>',array_map($inline,$paragraph)).'</p>';$paragraph=[];}
            if($list){$html.='<ul>';foreach($list as $line)$html.='<li>'.$inline($line).'</li>';$html.='</ul>';$list=[];}
        };
        $lines=explode("\n",str_replace("\r",'',trim($text)));
        foreach($lines as $i=>$line){
            $line=trim($line);
            if($line===''){$flush();continue;}
            $heading=null;$level=2;
            if(preg_match('/^(#{1,3})\s+(.+?)\s*#*$/u',$line,$m)){$heading=$m[2];$level=strlen($m[1])===3?3:2;}
            elseif(preg_match('/^\*\*([^*]{3,120})\*\*:?$/u',$line,$m))$heading=$m[1];
            // Older approved drafts used a short standalone line followed by a paragraph.
            // Do not infer headings from full sentences, prices, questions or list items.
            elseif(isset($lines[$i+1])&&trim($lines[$i+1])!==''&&($i===0||trim($lines[$i-1])==='')&&mb_strlen($line)<=90
                &&preg_match('/^[\p{L}][\p{L}\p{N}\s,:’\x{0027}()–—-]+$/u',$line)&&!preg_match('/[.!?؟。]$/u',$line))$heading=$line;
            if($heading!==null){$flush();$html.='<h'.$level.'>'.$inline($heading).'</h'.$level.'>';continue;}
            if(preg_match('/^[-*]\s+(.+)$/u',$line,$m)){if($paragraph)$flush();$list[]=$m[1];continue;}
            if($list)$flush();$paragraph[]=$line;
        }
        $flush();return $html;
    }
    public static function style(): string
    {
        return '<style data-tourfecto-guide-style>[data-tourfecto-guide]{display:block;box-sizing:border-box;max-width:860px;margin:32px auto;padding:24px;line-height:1.8;overflow-wrap:anywhere;color:inherit}[data-tourfecto-guide] h1{font-size:clamp(1.7rem,4vw,2.6rem);line-height:1.25;margin:0 0 1rem}[data-tourfecto-guide] h2{font-size:1.5rem;margin:2rem 0 .75rem}[data-tourfecto-guide] h3{font-size:1.2rem;margin:1.5rem 0 .5rem}[data-tourfecto-guide] figure{margin:1.5rem 0}[data-tourfecto-guide] img{max-width:100%;height:auto;border-radius:12px}[data-tourfecto-guide] p{margin:1rem 0}[data-tourfecto-guide] a{text-decoration:underline}[data-tourfecto-guide] .meta{font-size:.9rem;opacity:.75}</style>';
    }
    /** Keep the original document bytes outside one unambiguous <main>. No remote theme fetch or guessed PHP includes. */
    public static function theme(string $html,string $main): ?string
    {
        if(strlen($html)>2000000||stripos($html,'</head>')===false||stripos($html,'</body>')===false)return null;
        $hidden=[];
        $masked=preg_replace_callback('~<!--.*?-->|<(script|style|textarea|template)\b[^>]*>.*?</\1>~is',static function($m)use(&$hidden){$key='<!--tf-guide-mask-'.count($hidden).'-->';$hidden[$key]=$m[0];return $key;},$html);
        if(!is_string($masked)||preg_match_all('~<main\b[^>]*>~i',$masked)!==1||preg_match_all('~</main\s*>~i',$masked)!==1)return null;
        if(!preg_match('~<main\b[^>]*>.*?</main\s*>~is',$masked,$match,PREG_OFFSET_CAPTURE))return null;
        $output=substr_replace($masked,'<main data-tourfecto-guide>'.$main.'</main>',$match[0][1],strlen($match[0][0]));
        // Home-only structured data must not describe the new article. Keep business identity nodes.
        foreach($hidden as $key=>&$block){
            if(!preg_match('~^<script\b[^>]*type\s*=\s*(["\x{0027}])application/ld\+json\1~i',$block))continue;
            $json=preg_replace('~^<script\b[^>]*>|</script>\s*$~is','',$block);$data=json_decode($json,true);
            if(!is_array($data)){$block='';continue;}
            $nodes=$data['@graph']??(array_is_list($data)?$data:[$data]);
            $nodes=array_values(array_filter($nodes,static function($node){$types=(array)($node['@type']??[]);return (bool)array_intersect($types,['Organization','LocalBusiness','TravelAgency','WebSite']);}));
            $block=$nodes?'<script type="application/ld+json">'.str_replace('</','<\/',json_encode(['@context'=>'https://schema.org','@graph'=>$nodes],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</script>':'';
        }
        unset($block);
        $output=strtr($output,$hidden);
        return preg_replace('~</head>~i','<meta name="tourfecto-guide-layout" content="site">'.self::style().'</head>',$output,1);
    }
}
