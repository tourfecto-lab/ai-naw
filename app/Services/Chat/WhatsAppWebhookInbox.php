<?php
/** Durable, encrypted WhatsApp inbox. HTTP handlers only persist and enqueue. */
class WhatsAppWebhookInbox
{
    /** Queue job IDs created during this request, so the webhook can run them right after replying to Meta. */
    private static array $queuedJobIds = [];

    /**
     * Run this request's queued events immediately instead of waiting for the per-minute cron.
     * Call only after the HTTP response is sent. Jobs are claimed atomically, so the cron never runs one twice;
     * anything not finished here (failure, timeout) stays in the queue for the cron to retry.
     */
    public static function processQueuedNow(int $limit = 20): void
    {
        $ids = array_slice(array_unique(self::$queuedJobIds), 0, $limit);
        self::$queuedJobIds = [];
        if (!$ids) return;
        $queue = new QueueManager();
        foreach ($ids as $id) {
            try {
                $queue->processJob($id);
            } catch (Throwable $e) {
                Logger::error('WhatsApp immediate processing failed', ['job_id' => $id, 'exception_class' => get_class($e)]);
            }
        }
    }

    public static function enqueue(int $websiteId, int $userId, string $kind, string $eventKey, array $payload): bool
    {
        if ($websiteId < 1 || $userId < 1 || !in_array($kind, ['message', 'delivery'], true) || $eventKey === '') {
            throw new InvalidArgumentException('Invalid WhatsApp event');
        }
        $db = Database::getInstance();
        $encrypted = (new Encryption())->encrypt(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $db->transaction(function () use ($db, $websiteId, $userId, $kind, $eventKey, $encrypted, $payload) {
            $db->query('INSERT INTO whatsapp_webhook_inbox (website_id,user_id,kind,event_key,payload_enc,state,created_at,updated_at) VALUES (?,?,?,?,?,\'queued\',NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$websiteId,$userId,$kind,hash('sha256',$eventKey),$encrypted]);
            $row = $db->query('SELECT id,state,job_id FROM whatsapp_webhook_inbox WHERE website_id=? AND kind=? AND event_key=? FOR UPDATE', [$websiteId,$kind,hash('sha256',$eventKey)])[0];
            // Repeated Meta deliveries never create a second job or rerun a completed event.
            if ($row['job_id'] !== null || $row['state'] !== 'queued') return false;
            if($kind==='message'&&WhatsAppMarketingService::installed())WhatsAppMarketingService::handleOptOut($websiteId,$userId,$payload);
            $jobId = (new QueueManager($db))->push('ProcessWhatsAppWebhookJob', ['event_id'=>(int)$row['id']], 'whatsapp');
            if (!$jobId) throw new RuntimeException('WhatsApp queue unavailable');
            $db->query('UPDATE whatsapp_webhook_inbox SET job_id=? WHERE id=?', [(int)$jobId,(int)$row['id']]);
            self::$queuedJobIds[] = (int)$jobId;
            return true;
        });
    }

    public static function process(int $eventId, ?object $manager = null): void
    {
        $db = Database::getInstance();
        // Connection-scoped advisory lock survives slow AI calls without a long SQL transaction.
        $lock = 'tf_wa_event_' . $eventId;
        if ((int)($db->query('SELECT GET_LOCK(?,0) AS acquired', [$lock])[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('WhatsApp event is being processed');
        }
        try {
            $r = $db->query('SELECT * FROM whatsapp_webhook_inbox WHERE id=?', [$eventId])[0] ?? null;
            if (!$r || in_array($r['state'], ['completed','uncertain'], true)) return;
            $db->query("UPDATE whatsapp_webhook_inbox SET state='processing',attempts=attempts+1,updated_at=NOW() WHERE id=?", [$eventId]);
            $payload = json_decode((new Encryption())->decrypt($r['payload_enc']), true, 512, JSON_THROW_ON_ERROR);
            if ($r['kind'] === 'delivery') {
                self::recordDelivery((int)$r['website_id'],(int)$r['user_id'],$payload);
            } else {
                // Username-only WhatsApp senders have a BSUID, not a phone number.
                // Persist safely in the inbox and require human handling. No automatic sends.
                if (!empty($payload['wa_bsuid']) && empty($payload['phone_number'])) {
                    self::storeBsuidInbound((int)$r['user_id'], (int)$r['website_id'], $payload);
                    $db->query("UPDATE whatsapp_webhook_inbox SET state='completed',last_error=NULL,payload_enc=NULL,updated_at=NOW() WHERE id=?", [$eventId]);
                    return;
                }
                WhatsAppCrmInboxBridge::store((int)$r['user_id'],(int)$r['website_id'],$payload);
                if(isset($payload['flow_response'])&&is_array($payload['flow_response'])){
                    WhatsAppStudioService::storeFlowResponse((int)$r['user_id'],(int)$r['website_id'],$payload);
                    WhatsAppMarketingService::storeOptOutInbox((int)$r['user_id'],(int)$r['website_id'],$payload);
                    $db->query("UPDATE whatsapp_webhook_inbox SET state='completed',last_error=NULL,payload_enc=NULL,updated_at=NOW() WHERE id=?",[$eventId]);return;
                }
                if(WhatsAppMarketingService::installed()&&WhatsAppStudioService::confirmSignup((int)$r['user_id'],(int)$r['website_id'],$payload)){
                    WhatsAppMarketingService::storeOptOutInbox((int)$r['user_id'],(int)$r['website_id'],$payload);
                    $db->query("UPDATE whatsapp_webhook_inbox SET state='completed',last_error=NULL,payload_enc=NULL,updated_at=NOW() WHERE id=?",[$eventId]);return;
                }
                if(WhatsAppMarketingService::installed()&&WhatsAppMarketingService::isOptOut((string)($payload['message']??''))){
                    WhatsAppMarketingService::storeOptOutInbox((int)$r['user_id'],(int)$r['website_id'],$payload);
                    $db->query("UPDATE whatsapp_webhook_inbox SET state='completed',last_error=NULL,payload_enc=NULL,updated_at=NOW() WHERE id=?",[$eventId]);
                    return;
                }
                $known = $db->query("SELECT id FROM chat_messages WHERE website_id=? AND user_id=? AND platform='whatsapp' AND message_direction='incoming' AND platform_message_id=? LIMIT 1", [(int)$r['website_id'],(int)$r['user_id'],$payload['platform_message_id']])[0] ?? null;
                if ($known) {
                    // A worker may have died after saving/sending. Do not regenerate or resend an uncertain reply.
                    $db->query("UPDATE whatsapp_webhook_inbox SET state='uncertain',last_error='Message is stored; inspect inbox before retrying any reply',updated_at=NOW() WHERE id=?", [$eventId]);
                    return;
                }
                $payload['user_id']=(int)$r['user_id']; $payload['website_id']=(int)$r['website_id'];
                $result = ($manager ?: new ChatManager())->processIncomingMessage($payload);
                if (empty($result['success'])) throw new RuntimeException('WhatsApp message processing did not complete; inspect bot settings and balance');
            }
            $db->query("UPDATE whatsapp_webhook_inbox SET state='completed',last_error=NULL,payload_enc=NULL,updated_at=NOW() WHERE id=?", [$eventId]);
        } catch (Throwable $e) {
            $db->query("UPDATE whatsapp_webhook_inbox SET state='failed',last_error=?,updated_at=NOW() WHERE id=? AND state NOT IN ('completed','uncertain')", [mb_substr($e instanceof JsonException ? 'Invalid stored payload' : 'Processing failed; retry through admin jobs',0,500),$eventId]);
            throw $e;
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Save a username-only inbound message without pretending that a BSUID is a phone. */
    private static function storeBsuidInbound(int $uid, int $wid, array $payload): void
    {
        $bsuid = (string)($payload['wa_bsuid'] ?? '');
        $wamid = (string)($payload['platform_message_id'] ?? '');
        if (!preg_match('/^[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9._-]{8,128}$/D', $bsuid)
            || $wamid === '' || strlen($wamid) > 255) {
            throw new InvalidArgumentException('Invalid WhatsApp BSUID inbound event');
        }
        $db = Database::getInstance();
        $found = $db->query("SELECT id FROM chat_messages WHERE website_id=? AND user_id=? AND platform='whatsapp' AND message_direction='incoming' AND platform_message_id=? LIMIT 1", [$wid,$uid,$wamid]);
        if ($found) return;
        // The customer key is a stable opaque identity, not a fabricated telephone number.
        $thread = 'bsuid:' . $bsuid;
        $session = 'wa_bsuid_' . hash('sha256', $wid . '|' . $bsuid);
        $id = (int)$db->query("INSERT INTO chat_messages (website_id,user_id,session_id,platform,platform_message_id,customer_name,customer_phone,message_direction,message_text,message_language,bot_status,is_auto_pilot,created_at) VALUES (?,?,?,?,?, ?,NULL,'incoming',?,?,'pending_approval',0,NOW())", [
            $wid,$uid,$session,'whatsapp',$wamid,
            (string)($payload['sender_name'] ?: 'WhatsApp username customer'),
            (string)$payload['message'],'en'
        ]);
        // Link to the shared Inbox when available; never block message storage for a UI-sync error.
        if ($id > 0 && class_exists('UnifiedInboxService')) {
            try {
                $inbox = new UnifiedInboxService();
                $conversation = $inbox->findOrCreateConversation($wid,$uid,'whatsapp',$thread,[
                    'name' => (string)($payload['sender_name'] ?: 'WhatsApp username customer'),
                    'phone' => null, 'email' => null,
                ]);
                $inbox->linkMessage($id, (int)$conversation->getAttribute('id'), true);
            } catch (Throwable $e) {
                Logger::error('WhatsApp BSUID inbox sync failed', ['website_id'=>$wid,'exception_class'=>get_class($e)]);
            }
        }
    }

    public static function verifiedSignature(string $raw, string $signature, string $secret): bool
    {
        return $secret !== '' && preg_match('/^sha256=[a-f0-9]{64}$/D',$signature) === 1
            && hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$signature);
    }

    public static function recordDelivery(int $websiteId, int $userId, array $status): void
    {
        $id=(string)($status['id']??''); $state=(string)($status['status']??'');
        $ranks=['sent'=>1,'failed'=>2,'delivered'=>3,'read'=>4];
        if ($id==='' || strlen($id)>255 || !isset($ranks[$state])) return;
        $error=$status['errors'][0]??null;
        $errorText=$error ? mb_substr(WhatsAppCloudAPI::friendlyError((int)($error['code']??0),(string)($error['title']??$error['message']??'')),0,500) : null;
        $timestamp=filter_var($status['timestamp']??null,FILTER_VALIDATE_INT);
        $timestamp=$timestamp!==false && $timestamp>0 ? $timestamp : time();
        $db=Database::getInstance();
        $db->transaction(function()use($db,$websiteId,$userId,$id,$state,$ranks,$errorText,$timestamp){
            $db->query('INSERT INTO whatsapp_delivery_statuses (website_id,user_id,wamid,status,status_rank,error_message,event_timestamp,updated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$websiteId,$userId,$id,$state,$ranks[$state],$errorText,$timestamp]);
            $old=$db->query('SELECT id,status_rank,event_timestamp FROM whatsapp_delivery_statuses WHERE website_id=? AND wamid=? FOR UPDATE',[$websiteId,$id])[0];
            // Reordered webhooks must not turn a read message back into sent/failed.
            if ($ranks[$state]>(int)$old['status_rank'] || ($ranks[$state]===(int)$old['status_rank'] && $timestamp>=(int)$old['event_timestamp'])) {
                $db->query('UPDATE whatsapp_delivery_statuses SET status=?,status_rank=?,error_message=?,event_timestamp=?,updated_at=NOW() WHERE id=?',[$state,$ranks[$state],$errorText,$timestamp,(int)$old['id']]);
            }
        });
    }
}
