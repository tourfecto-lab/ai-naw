<?php
/** Persist authenticated inbound messages in the same owner's CRM, once per sender/site/message. */
final class WhatsAppCrmInboxBridge
{
    public static function store(int $uid,int $wid,array $payload): void
    {
        $db=Database::getInstance();$tables=$db->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('crm_conversations','crm_messages','wa_crm_inbound_links')")[0]['n']??0;if((int)$tables!==3)return;
        if(!$db->query('SELECT id FROM websites WHERE id=? AND user_id=?',[$wid,$uid]))throw new InvalidArgumentException('Unknown inbound website owner');
        $phone=WhatsAppCloudAPI::normalizePhone((string)($payload['phone_number']??''));$wamid=(string)($payload['platform_message_id']??'');if(!$wamid||strlen($wamid)>255)throw new InvalidArgumentException('Missing inbound message ID');
        $normal="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')','')";
        $contacts=$db->query("SELECT id FROM crm_contacts WHERE user_id=? AND ($normal=? OR $normal=?) LIMIT 2",[$uid,$phone,'00'.$phone]);if(count($contacts)!==1)return;
        $key='wa_crm:'.$uid.':'.$wid.':'.$phone;if(!ExternalExecution::lock($key))throw new RuntimeException('CRM inbox is busy');
        try{$db->transaction(function()use($db,$uid,$wid,$wamid,$phone,$contacts,$payload){
            $q=$db->getConnection()->prepare('INSERT IGNORE INTO wa_crm_inbound_links(user_id,website_id,wamid) VALUES(?,?,?)');$q->execute([$uid,$wid,$wamid]);if(!$q->rowCount())return;
            $conversation=(new CrmConversation())->findOrCreate($uid,(int)$contacts[0]['id'],'whatsapp',$wid.':'.$phone);
            $message=new CrmMessage(['conversation_id'=>(int)$conversation->getAttribute('id'),'direction'=>'inbound','body'=>(string)($payload['message']??''),'status'=>'delivered','external_message_id'=>$wamid,'sent_at'=>gmdate('Y-m-d H:i:s')]);if(!$message->save())throw new RuntimeException('Cannot store CRM inbound message');
            $db->query('UPDATE wa_crm_inbound_links SET message_id=? WHERE user_id=? AND website_id=? AND wamid=?',[(int)$message->getAttribute('id'),$uid,$wid,$wamid]);$db->query('UPDATE crm_conversations SET unread_count=unread_count+1,last_message_at=UTC_TIMESTAMP() WHERE id=?',[(int)$conversation->getAttribute('id')]);
            if($db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_automation_rules'"))(new CrmAutomationService())->trigger('whatsapp.message_received',$uid,['contact_id'=>(int)$contacts[0]['id'],'website_id'=>$wid]);
        });}finally{ExternalExecution::unlock($key);}
    }
}
