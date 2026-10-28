<?php
/** Durable approval delivery. Boolean-only channel failures are uncertain, never safe to blindly retry. */
class ApprovedReplyDelivery
{
    private PDO $pdo; private $sender;
    public function __construct(?PDO $pdo=null,?callable $sender=null) { $this->pdo=$pdo??Database::getInstance()->getConnection();$this->sender=$sender; }
    private function q(string $sql,array $args=[]): PDOStatement {$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    private function owned(int $uid,int $id): array {
        $m=$this->q("SELECT m.* FROM chat_messages m JOIN websites w ON w.id=m.website_id AND w.user_id=m.user_id WHERE m.id=? AND m.user_id=? AND m.message_direction='incoming'",[$id,$uid])->fetch(PDO::FETCH_ASSOC);
        if(!$m)throw new RuntimeException('Message not found',404);return $m;
    }
    public function approve(int $id,int $uid): array {
        $key='approved-reply:'.$uid.':'.$id;if(!ExternalExecution::lock($key))return ['success'=>false,'error'=>'Another delivery is in progress'];
        try {
            $m=$this->owned($uid,$id);
            if(!in_array($m['bot_status'],['pending_approval','approved','sent'],true))throw new RuntimeException('Message is not available for approval');
            $d=$this->q('SELECT * FROM chat_approved_deliveries WHERE message_id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);
            if($m['bot_status']==='sent'||($d&&$d['state']==='sent')){
                $this->q("UPDATE chat_messages SET bot_status='sent',sent_at=COALESCE(sent_at,NOW()),updated_at=NOW() WHERE id=? AND user_id=?",[$id,$uid]);return ['success'=>true,'sent'=>true,'status'=>'sent','already_sent'=>true,'message_id'=>$id];
            }
            if($d&&in_array($d['state'],['sending','uncertain'],true)){
                $this->q("UPDATE chat_approved_deliveries SET state='uncertain',updated_at=NOW() WHERE message_id=? AND user_id=?",[$id,$uid]);return ['success'=>true,'sent'=>false,'status'=>'uncertain','message_id'=>$id,'message'=>'Delivery needs provider verification before another attempt.'];
            }
            // Existing approved rows have already attempted delivery under the old implementation.
            if(!$d && $m['bot_status']==='approved'){
                $this->q("INSERT INTO chat_approved_deliveries(message_id,user_id,state,last_error,created_at,updated_at) VALUES(?,?,'uncertain','Legacy delivery outcome needs verification',NOW(),NOW())",[$id,$uid]);return ['success'=>true,'sent'=>false,'status'=>'uncertain','message_id'=>$id,'message'=>'Verify the previous delivery outcome first.'];
            }
            $reply=trim((string)$m['ai_reply_generated']);if($reply==='')throw new RuntimeException('Approved reply is empty');
            if(!$d)$this->q("INSERT INTO chat_approved_deliveries(message_id,user_id,state,reply_hash,created_at,updated_at) VALUES(?,?,'ready',?,NOW(),NOW())",[$id,$uid,hash('sha256',$reply)]);
            if($d&&!empty($d['reply_hash'])&&!hash_equals($d['reply_hash'],hash('sha256',$reply)))throw new RuntimeException('Reply changed after approval; refresh and verify before sending.');
            $this->q("UPDATE chat_messages SET bot_status='approved',approved_by_user_id=?,approved_at=COALESCE(approved_at,NOW()),updated_at=NOW() WHERE id=? AND user_id=?",[$uid,$id,$uid]);
            // A durable claim is saved before any provider call; a crash remains uncertain.
            $this->q("UPDATE chat_approved_deliveries SET state='sending',attempts=attempts+1,updated_at=NOW() WHERE message_id=? AND user_id=?",[$id,$uid]);
            try{$result=$this->sender?($this->sender)($m,$reply):$this->send($m,$reply);}catch(Throwable $e){$result=['success'=>false,'error'=>'Delivery outcome could not be verified'];}
            $ok=!empty($result['success']);$code=(int)($result['http_code']??0);
            $state=$ok?'sent':(!empty($result['definitive_failure'])||($code>=400&&$code<500&&!in_array($code,[408,429],true))?'failed':'uncertain');
            $error=$ok?null:mb_substr((string)($result['error']??'Delivery needs review'),0,500);
            $this->q('UPDATE chat_approved_deliveries SET state=?,external_id=?,last_error=?,updated_at=NOW() WHERE message_id=? AND user_id=?',[$state,$result['id']??null,$error,$id,$uid]);
            if($ok)$this->q("UPDATE chat_messages SET bot_status='sent',sent_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?",[$id,$uid]);
            return ['success'=>true,'sent'=>$ok,'message_id'=>$id,'status'=>$state,'message'=>$ok?'Reply sent.':($state==='failed'?'The provider rejected the delivery. Correct the connection and retry.':'Delivery outcome is uncertain. Verify it with the provider before retrying.')];
        }catch(Throwable $e){return ['success'=>false,'error'=>$e->getCode()===404?'Message not found':$e->getMessage()];}finally{ExternalExecution::unlock($key);}
    }
    private function send(array $m,string $reply): array {
        $phone=(string)$m['customer_phone'];if(!empty($m['encrypted_phone']))$phone=(new Encryption())->decryptCustomerData($m['encrypted_phone'],$phone);
        if(($m['platform']??'')==='whatsapp'){
            $cloud=WhatsAppCloudAPI::forWebsite((int)$m['website_id']);
            if($cloud){$phone=WhatsAppCloudAPI::normalizePhone($phone);
                if(!$this->q("SELECT id FROM chat_messages WHERE user_id=? AND website_id=? AND message_direction='incoming' AND platform='whatsapp' AND customer_phone IN (?,?) AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) LIMIT 1",[$m['user_id'],$m['website_id'],$phone,'+'.$phone])->fetchColumn())return ['success'=>false,'definitive_failure'=>true,'error'=>'The WhatsApp reply window has closed. Use an approved template.'];
                if($this->q("SELECT id FROM wa_marketing_contacts WHERE user_id=? AND website_id=? AND phone=? AND consent_state='opted_out' LIMIT 1",[$m['user_id'],$m['website_id'],$phone])->fetchColumn())return ['success'=>false,'definitive_failure'=>true,'error'=>'This customer has opted out.'];
                $r=$cloud->sendText($phone,$reply);return ['success'=>!empty($r['ok']),'id'=>$r['id']??null,'http_code'=>$r['http_code']??0,'error'=>$r['error']??null];
            }
        }
        $ok=(new ChatManager())->sendMessageForWebsite((int)$m['website_id'],$phone,$reply,(string)$m['platform']);return ['success'=>$ok,'error'=>$ok?null:'Channel delivery could not be verified'];
    }
    public function listing(int $uid): array {
        $rows=$this->q("SELECT m.id,m.website_id,m.customer_name,m.ai_reply_generated,m.platform,m.approved_at,COALESCE(d.state,'uncertain') delivery_state,d.last_error,d.external_id,d.attempts FROM chat_messages m JOIN websites w ON w.id=m.website_id AND w.user_id=m.user_id LEFT JOIN chat_approved_deliveries d ON d.message_id=m.id AND d.user_id=m.user_id WHERE m.user_id=? AND m.bot_status='approved' ORDER BY m.approved_at DESC LIMIT 100",[$uid])->fetchAll(PDO::FETCH_ASSOC);return $rows;
    }
    public function resolve(int $uid,int $id,string $outcome,string $evidence): array {
        if(!in_array($outcome,['sent','not_sent'],true)||mb_strlen(trim($evidence))<12||mb_strlen($evidence)>2000)throw new InvalidArgumentException('Choose a verified outcome and provide provider evidence (12–2000 characters).');
        $key='approved-reply:'.$uid.':'.$id;if(!ExternalExecution::lock($key))throw new RuntimeException('Delivery is busy');
        try{$m=$this->owned($uid,$id);if($m['bot_status']!=='approved')throw new RuntimeException('Reply is not awaiting delivery review');
            $this->q("INSERT IGNORE INTO chat_approved_deliveries(message_id,user_id,state,reply_hash,created_at,updated_at) VALUES(?,?,'uncertain',?,NOW(),NOW())",[$id,$uid,hash('sha256',(string)$m['ai_reply_generated'])]);
            $d=$this->q('SELECT state FROM chat_approved_deliveries WHERE message_id=? AND user_id=?',[$id,$uid])->fetchColumn();if(!in_array($d,['uncertain','sending'],true))throw new RuntimeException('Only uncertain attempts need manual reconciliation');
            $this->q('UPDATE chat_approved_deliveries SET state=?,evidence=?,updated_at=NOW() WHERE message_id=? AND user_id=?',[$outcome==='sent'?'sent':'failed',trim($evidence),$id,$uid]);
            if($outcome==='sent')$this->q("UPDATE chat_messages SET bot_status='sent',sent_at=COALESCE(sent_at,NOW()),updated_at=NOW() WHERE id=? AND user_id=?",[$id,$uid]);return ['resolved'=>true,'state'=>$outcome==='sent'?'sent':'failed'];
        }finally{ExternalExecution::unlock($key);}
    }
}
