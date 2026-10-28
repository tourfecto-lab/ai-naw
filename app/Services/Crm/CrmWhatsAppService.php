<?php

/** CRM replies use the selected tenant-owned WhatsApp connection and active customer window. */
class CrmWhatsAppService
{
    private $settings;
    private ?WhatsAppCloudAPI $websiteClient=null;
    private int $ownerUserId=0;
    private int $ownedWebsiteId=0;

    public function __construct(int $userId=0,int $websiteId=0)
    {
        $this->settings=new SystemSettingsService();
        if($userId>0)$this->loadOwnedConnection($userId,$websiteId);
    }
    private function loadOwnedConnection(int $userId,int $websiteId=0): void
    {
        $this->websiteClient=null;$this->ownerUserId=$userId;$this->ownedWebsiteId=0;
        $pdo=Database::getInstance()->getConnection();
        if(!$websiteId){$q=$pdo->prepare('SELECT id FROM websites WHERE user_id=? LIMIT 2');$q->execute([$userId]);$sites=$q->fetchAll(PDO::FETCH_COLUMN);if(count($sites)!==1)return;$websiteId=(int)$sites[0];}
        $q=$pdo->prepare('SELECT id FROM websites WHERE id=? AND user_id=?');$q->execute([$websiteId,$userId]);if(!$q->fetchColumn())return;
        $row=WhatsAppCloudAPI::connectionRow($websiteId);if(!$row||(int)$row['user_id']!==$userId)return;
        $this->websiteClient=WhatsAppCloudAPI::forWebsite($websiteId);$this->ownedWebsiteId=$websiteId;
    }
    public function isConfigured(): bool {return $this->websiteClient!==null;}

    /**
     * إرسال رسالة نصية عبر WhatsApp Business Cloud API الرسمي.
     * @param string $toPhoneE164 رقم بصيغة دولية بدون + (مثال: 201234567890)
     */
    public function sendTextMessage(string $toPhoneE164, string $text): array
    {
        if(!$this->websiteClient)return ['success'=>false,'error'=>'Select and connect your own website WhatsApp account. A shared admin sender is not used.'];
        try{$phone=WhatsAppMarketingService::phone($toPhoneE164);}catch(InvalidArgumentException $e){return ['success'=>false,'error'=>$e->getMessage()];}
        $pdo=Database::getInstance()->getConnection();$q=$pdo->prepare("SELECT id FROM chat_messages WHERE user_id=? AND website_id=? AND platform='whatsapp' AND message_direction='incoming' AND customer_phone IN (?,?) AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) LIMIT 1");$q->execute([$this->ownerUserId,$this->ownedWebsiteId,$phone,'+'.$phone]);
        if(!$q->fetchColumn())return ['success'=>false,'error'=>'No active 24-hour customer conversation window. Use an approved template in WhatsApp Marketing.'];
        $r=$this->websiteClient->sendText($phone,$text);
        return ['success'=>!empty($r['ok']),'external_message_id'=>$r['id']??null,'error'=>!empty($r['ok'])?null:'Meta did not confirm this reply. Check the sender and message status before retrying.'];
    }

    /**
     * التحقق من Webhook Verification Handshake الرسمي من Meta
     * (GET request بـhub.mode/hub.verify_token/hub.challenge).
     */
    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expectedToken = $this->settings->get('crm_whatsapp_webhook_verify_token', '');
        if ($mode === 'subscribe' && $expectedToken !== '' && $token === $expectedToken) {
            return $challenge;
        }
        return null;
    }

    /** يرسل رسالة نصية لجهة اتصال CRM، ويسجّلها في محادثة (بند 16) */
    public function sendToContact(int $userId, int $contactId, string $text,int $websiteId=0): array
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'جهة الاتصال غير موجودة'];
        }
        $this->loadOwnedConnection($userId,$websiteId);
        if(!$this->isConfigured())return ['success'=>false,'error'=>'Choose a website owned by this account and connect its WhatsApp Cloud API.'];
        $phone = $contact->getAttribute('phone');
        if (empty($phone)) {
            return ['success' => false, 'error' => 'لا يوجد رقم هاتف مسجّل لجهة الاتصال هذه'];
        }

        try { $normalizedPhone = WhatsAppMarketingService::phone((string)$phone); }
        catch (InvalidArgumentException $e) { return ['success'=>false,'error'=>$e->getMessage()]; }
        $conversation = (new CrmConversation())->findOrCreate($userId, $contactId, 'whatsapp', $this->ownedWebsiteId.':'.$normalizedPhone);

        $result = $this->sendTextMessage($normalizedPhone, $text);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'outbound',
            'sender_user_id' => $userId,
            'body' => $text,
            'status' => !empty($result['success']) ? 'sent' : 'failed',
            'external_message_id' => $result['external_message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->save();

        ActivityLog::record('crm', 'whatsapp.sent', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $contactId,
            'meta' => ['success' => !empty($result['success'])],
        ]);

        return array_merge($result, ['conversation_id' => (int) $conversation->getAttribute('id')]);
    }

    /**
     * يحلل Payload الوارد من Webhook (POST) ويرجع رسائل نصية واردة فقط
     * (يتجاهل status updates وأنواع الرسائل الأخرى غير المدعومة حاليًا).
     * @return array<int, array{from: string, text: string, external_id: string}>
     */
    public function parseIncomingWebhook(array $payload): array
    {
        $messages = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['messages'] ?? []) as $msg) {
                    if (($msg['type'] ?? '') === 'text') {
                        $messages[] = [
                            'from' => (string) ($msg['from'] ?? ''),
                            'text' => (string) ($msg['text']['body'] ?? ''),
                            'external_id' => (string) ($msg['id'] ?? ''),
                        ];
                    }
                }
            }
        }
        return $messages;
    }
}
