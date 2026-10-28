<?php
if(!defined('ABSPATH'))exit;
/** Only plain core text blocks with exact serialization round-trips are eligible. */
final class TourfectoBlockText {
    private static function parts(array $b): ?array {
        if(!in_array($b['blockName']??'', ['core/paragraph','core/heading'],true)||!empty($b['innerBlocks'])||!empty($b['attrs']['metadata'])||!empty($b['attrs']['lock'])||count($b['innerContent']??[])!==1)return null;
        $html=(string)($b['innerHTML']??'');
        if(!preg_match('~\A(\s*<(p|h[1-6])(?:\s[^<>]*)?>)([^<>]*)(</\2>\s*)\z~sD',$html,$m))return null;
        if(($b['blockName']==='core/paragraph'&&$m[2]!=='p')||($b['blockName']==='core/heading'&&$m[2]==='p')||$b['innerContent'][0]!==$html)return null;
        // Existing markup remains untouched. Binding/formatting/embedded HTML needs its own adapter.
        return [$m[1],html_entity_decode($m[3],ENT_QUOTES|ENT_HTML5,'UTF-8'),$m[4]];
    }
    private static function walk(array $blocks,string $prefix,array &$items,int $depth,int &$visited): void {
        if($depth>8)return;
        foreach($blocks as $i=>$b){if(++$visited>500)return;$path=$prefix.(string)$i;$parts=self::parts($b);
            if($parts&&count($items)<100)$items[]=['path'=>$path,'type'=>$b['blockName'],'text'=>$parts[1]];
            if(in_array($b['blockName']??'', ['core/group','core/columns','core/column','core/cover'],true)&&empty($b['attrs']['templateLock'])&&empty($b['attrs']['lock'])&&empty($b['attrs']['metadata']))self::walk($b['innerBlocks']??[],$path.'/', $items,$depth+1,$visited);
        }
    }
    public static function inspect(string $content,bool $otherBuilder=false): array {
        if($otherBuilder)return ['state'=>'other_builder','items'=>[]];
        if(!has_blocks($content))return ['state'=>'classic','items'=>[]];
        if(strlen($content)>60000||!function_exists('parse_blocks')||!function_exists('serialize_blocks'))return ['state'=>'unsupported_size_or_runtime','items'=>[]];
        $blocks=parse_blocks($content);if(serialize_blocks($blocks)!==$content)return ['state'=>'serialization_mismatch','items'=>[]];
        $items=[];$visited=0;self::walk($blocks,'',$items,0,$visited);
        return ['state'=>$items?'supported_plain_text':'no_supported_plain_text','items'=>$items,'limit'=>100,'scope'=>'Existing plain core paragraph/heading text only. No layout, formatted text, bound data, reusable blocks or custom tour fields.'];
    }
    public static function change(string $content,array $changes,bool $otherBuilder=false): string {
        $inventory=self::inspect($content,$otherBuilder);if($inventory['state']!=='supported_plain_text')throw new InvalidArgumentException('This page has no supported plain-text blocks.');
        if(!$changes||count($changes)>20)throw new InvalidArgumentException('Choose 1–20 text blocks.');
        $allowed=array_column($inventory['items'],null,'path');$blocks=parse_blocks($content);$seen=[];
        foreach($changes as $change){$path=$change['path']??null;$text=$change['text']??null;
            if(!is_string($path)||!isset($allowed[$path])||isset($seen[$path])||!is_string($text)||strlen($text)>10000||strpbrk($text,'[]')!==false||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)||preg_match('//u',$text)!==1)throw new InvalidArgumentException('Unsupported block path, duplicate or invalid text.');
            $seen[$path]=true;if($text===$allowed[$path]['text'])continue;$list=&$blocks;$parts=explode('/',$path);
            foreach($parts as $n=>$part){$index=(int)$part;if($n===count($parts)-1){$block=&$list[$index];$markup=self::parts($block);$block['innerHTML']=$markup[0].esc_html($text).$markup[2];$block['innerContent']=[$block['innerHTML']];unset($block);}else $list=&$list[$index]['innerBlocks'];}unset($list);
        }
        $out=serialize_blocks($blocks);if(strlen($out)>60000)throw new InvalidArgumentException('Modified content exceeds the supported size.');if($out===$content)throw new InvalidArgumentException('No block text changed.');return $out;
    }
}
