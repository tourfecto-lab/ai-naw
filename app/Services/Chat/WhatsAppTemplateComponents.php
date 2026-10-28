<?php
/** Approved definitions are immutable; only their declared variables are supplied. */
final class WhatsAppTemplateComponents
{
    public static function variables(string $text): array
    {
        preg_match_all('/\{\{([^}]+)\}\}/',$text,$m);$names=array_values(array_unique($m[1]));
        if(count($names)>10)throw new InvalidArgumentException('Use at most ten variables per component');
        if(!$names)return [];
        $numeric=ctype_digit($names[0]);
        foreach($names as $n)if($numeric?!preg_match('/^[1-9][0-9]*$/D',$n):!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$n))throw new InvalidArgumentException('Use consecutive numbered variables or lowercase named variables');
        if($numeric){$sort=array_map('intval',$names);sort($sort);if($sort!==range(1,count($names)))throw new InvalidArgumentException('Use consecutive numbered variables');$names=array_map('strval',$sort);}
        return $names;
    }
    public static function requirements(array $components,string $prefix=''): array
    {
        $out=[];$seen=[];
        foreach($components as $c){$type=strtoupper((string)($c['type']??''));if(isset($seen[$type]))throw new InvalidArgumentException('Duplicate template component');$seen[$type]=true;
            if($type==='HEADER'){$format=strtoupper((string)($c['format']??'TEXT'));
                if(in_array($format,['IMAGE','VIDEO','DOCUMENT'],true))$out[]=['key'=>$prefix.'header_media','label'=>$prefix.$format.' header: media ID','kind'=>'media_id','format'=>strtolower($format)];
                elseif($format==='TEXT'){$vars=self::variables((string)($c['text']??''));if(count($vars)>1)throw new InvalidArgumentException('Use one text header variable');if($vars)$out[]=['key'=>$prefix.'header_text','label'=>$prefix.'Header variable '.$vars[0],'kind'=>'text','parameter_name'=>ctype_digit($vars[0])?null:$vars[0]];}
                else throw new InvalidArgumentException('This header format is not supported');
            }elseif($type==='BUTTONS'){if(count($c['buttons']??[])>10)throw new InvalidArgumentException('Too many buttons');foreach(($c['buttons']??[]) as $index=>$button){$bt=strtoupper($button['type']??'');
                if($bt==='URL'){$vars=self::variables((string)($button['url']??''));if($vars&&$vars!==['1'])throw new InvalidArgumentException('Use {{1}} only in a dynamic URL button');if($vars)$out[]=['key'=>$prefix.'button_'.$index,'label'=>$prefix.'URL suffix: '.($button['text']??$index),'kind'=>'url_suffix','index'=>$index];}
                elseif($bt==='FLOW'){if(!preg_match('/^[0-9]{1,64}$/D',(string)($button['flow_id']??'')))throw new InvalidArgumentException('Use a published Flow ID');$out[]=['key'=>$prefix.'flow_'.$index,'label'=>$prefix.'Flow starting data (JSON object; {} for none)','kind'=>'json','index'=>$index];}
                elseif(!in_array($bt,['QUICK_REPLY','PHONE_NUMBER'],true))throw new InvalidArgumentException('This button type is not supported');
            }}elseif($type==='CAROUSEL'){if($prefix!==''||count($c['cards']??[])<2||count($c['cards'])>10)throw new InvalidArgumentException('A carousel needs 2–10 cards');foreach($c['cards'] as $i=>$card){$parts=$card['components']??[];$header=false;foreach($parts as $part){if(strtoupper($part['type']??'')==='HEADER'){$header=in_array(strtoupper($part['format']??''),['IMAGE','VIDEO'],true);}if(strtoupper($part['type']??'')==='CAROUSEL')throw new InvalidArgumentException('Nested carousels are not supported');}if(!$header)throw new InvalidArgumentException('Each carousel card needs an image or video header');array_push($out,...self::requirements($parts,'card_'.$i.'_'));}}
            elseif($type==='BODY'){if($prefix!=='')foreach(self::variables((string)($c['text']??'')) as $n)$out[]=['key'=>$prefix.'body_'.$n,'label'=>$prefix.'Body variable '.$n,'kind'=>'text','parameter_name'=>ctype_digit($n)?null:$n];}
            elseif($type!=='FOOTER')throw new InvalidArgumentException('This template component is not supported');
        }return $out;
    }
    public static function validate(array $components,array $input): array
    {
        $result=[];foreach(self::requirements($components) as $req){$value=$input[$req['key']]??'';if(!is_string($value)||trim($value)===''||mb_strlen($value)>1000)throw new InvalidArgumentException('Fill '.$req['label']);$value=trim($value);
            if($req['kind']==='media_id'&&!preg_match('/^[0-9]{1,64}$/D',$value))throw new InvalidArgumentException('Use a media ID from this WhatsApp sender account');
            if($req['kind']==='url_suffix'&&preg_match('/[\x00-\x20]|^https?:|^\/\//i',$value))throw new InvalidArgumentException('Enter a URL suffix without spaces or a new host');
            if($req['kind']==='json'){$v=json_decode($value);if(!is_object($v)||json_last_error()!==JSON_ERROR_NONE)throw new InvalidArgumentException('Flow data must be a JSON object');}
            $result[$req['key']]=$value;
        }return $result;
    }
    public static function payload(array $components,array $input,string $recipientKey,string $prefix=''): array
    {
        if($prefix==='')$input=self::validate($components,$input);$out=[];
        foreach($components as $c){$type=strtoupper($c['type']??'');if($type==='HEADER'){$format=strtolower($c['format']??'text');if(isset($input[$prefix.'header_media']))$out[]=['type'=>'header','parameters'=>[['type'=>$format,$format=>['id'=>$input[$prefix.'header_media']]]]];elseif(isset($input[$prefix.'header_text'])){$p=['type'=>'text','text'=>WhatsAppCloudAPI::cleanParam($input[$prefix.'header_text'])];$vars=self::variables($c['text']??'');if(!ctype_digit($vars[0]))$p['parameter_name']=$vars[0];$out[]=['type'=>'header','parameters'=>[$p]];}}
            elseif($type==='BODY'&&$prefix!==''){$params=[];foreach(self::variables($c['text']??'') as $n){$p=['type'=>'text','text'=>WhatsAppCloudAPI::cleanParam($input[$prefix.'body_'.$n])];if(!ctype_digit($n))$p['parameter_name']=$n;$params[]=$p;}if($params)$out[]=['type'=>'body','parameters'=>$params];}
            elseif($type==='BUTTONS'){foreach($c['buttons']??[] as $index=>$b){$bt=strtoupper($b['type']??'');if($bt==='QUICK_REPLY')$out[]=['type'=>'button','sub_type'=>'quick_reply','index'=>(string)$index,'parameters'=>[['type'=>'payload','payload'=>'wa:'.$recipientKey.':'.$prefix.$index]]];elseif($bt==='FLOW')$out[]=['type'=>'button','sub_type'=>'flow','index'=>(string)$index,'parameters'=>[['type'=>'action','action'=>['flow_token'=>hash('sha256',$recipientKey.':'.$prefix.$index),'flow_action_data'=>json_decode($input[$prefix.'flow_'.$index])]]]];elseif(isset($input[$prefix.'button_'.$index]))$out[]=['type'=>'button','sub_type'=>'url','index'=>(string)$index,'parameters'=>[['type'=>'text','text'=>$input[$prefix.'button_'.$index]]]];}}
            elseif($type==='CAROUSEL'){$cards=[];foreach($c['cards'] as $i=>$card)$cards[]=['card_index'=>$i,'components'=>self::payload($card['components'],$input,$recipientKey,'card_'.$i.'_')];$out[]=['type'=>'carousel','cards'=>$cards];}
        }return $out;
    }
}
