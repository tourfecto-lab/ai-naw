<?php
/** Approved owner-scoped utility templates with durable send evidence. */
final class WhatsAppTransactionalService
{
    public static function send(int $uid,int $wid,string $phone,string $name,string $language,array $params,string $operation): array
    {
        try {
            $db=Database::getInstance();$w=$db->query('SELECT company_name FROM websites WHERE id=? AND user_id=?',[$wid,$uid])[0]??null;
            if(!$w)throw new InvalidArgumentException('Select the website that owns this booking or review request.');
            $row=WhatsAppCloudAPI::connectionRow($wid);if(!$row||(int)$row['user_id']!==$uid)throw new InvalidArgumentException('Connect WhatsApp for this website.');
            $phone=WhatsAppCloudAPI::normalizePhone($phone);$api=WhatsAppCloudAPI::forWebsite($wid);if(!$api)throw new InvalidArgumentException('Reconnect the WhatsApp sender.');
            $result=ExternalExecution::run('wa_utility:'.$uid.':'.$wid.':'.$operation,$uid,function()use($api,$phone,$name,$language,$params){
                $after=null;$approved=false;$seen=[];
                for($i=0;$i<10;$i++){$r=$api->marketingTemplates($after);if(empty($r['ok']))return ['success'=>false,'definitive_failure'=>true,'error'=>'Unable to refresh template approval. No message sent.'];foreach($r['items'] as $t){if(($t['name']??'')===$name&&($t['language']??'')===$language&&($t['status']??'')==='APPROVED'&&($t['category']??'')==='UTILITY'){$components=$t['components']??[];$body='';$valid=true;foreach($components as $c){if(($c['type']??'')==='BODY')$body=$c['text']??'';elseif(($c['type']??'')!=='FOOTER')$valid=false;}preg_match_all('/\{\{([0-9]+)\}\}/',$body,$m);$ids=array_values(array_unique(array_map('intval',$m[1])));sort($ids);$approved=$valid&&($t['parameter_format']??'POSITIONAL')==='POSITIONAL'&&$ids===range(1,count($params));}}
                    $after=$r['after']??null;if(!$after)break;if(isset($seen[$after]))break;$seen[$after]=true;
                }
                if(!$approved)return ['success'=>false,'definitive_failure'=>true,'error'=>'Approve the exact utility template and audience language in WhatsApp Manager. No message sent.'];
                $r=$api->sendTemplate($phone,$name,$language,$params);$code=(int)($r['http_code']??0);
                return ['success'=>!empty($r['ok']),'message_id'=>$r['id']??null,'error'=>empty($r['ok'])?'Meta did not confirm this send. Check delivery before retrying.':null,'definitive_failure'=>empty($r['ok'])&&$code>=400&&$code<500&&!in_array($code,[408,429],true)];
            });
            return ['ok'=>!empty($result['success']),'channel'=>'whatsapp','error'=>$result['error']??null,'message_id'=>$result['message_id']??null,'uncertain'=>empty($result['success'])&&($result['outcome_state']??'uncertain')!=='failed'];
        }catch(InvalidArgumentException $e){return ['ok'=>false,'channel'=>'whatsapp','error'=>$e->getMessage(),'uncertain'=>false];}
        catch(Throwable $e){return ['ok'=>false,'channel'=>'whatsapp','error'=>'Send outcome needs review before retrying.','uncertain'=>true];}
    }
}
