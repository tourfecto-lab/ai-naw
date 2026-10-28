<?php

/**
 * Tourfecto - Chat Manager
 * مدير الشات الرئيسي لإدارة المحادثات والرسائل
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatManager
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var MessageProcessor $messageProcessor - معالج الرسائل
     */
    private $messageProcessor;

    /**
     * @var AutoReplyEngine $autoReplyEngine - محرك الردود التلقائية
     */
    private $autoReplyEngine;

    /**
     * @var ApprovalSystem $approvalSystem - نظام الموافقات
     */
    private $approvalSystem;

    /**
     * @var WhatsAppAPI $whatsAppAPI - تكامل WhatsApp
     */
    private $whatsAppAPI;

    /**
     * @var UltraMsgAPI $ultraMsgAPI - تكامل UltraMsg
     */
    private $ultraMsgAPI;

    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;

    /**
     * @var SubscriptionValidator $subscription - نظام الاشتراكات
     */
    private $subscription;

    /**
     * @var UnifiedInboxService $unifiedInbox - Unified Inbox (AI Chat Platform)
     */
    private $unifiedInbox;

    /**
     * @var RateLimiter $rateLimiter - حماية AI Chat من Spam/Abuse/Infinite loops (بند 22)
     */
    private $rateLimiter;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->messageProcessor = new MessageProcessor();
        $this->autoReplyEngine = new AutoReplyEngine();
        $this->approvalSystem = new ApprovalSystem();
        $this->whatsAppAPI = new WhatsAppAPI();
        $this->ultraMsgAPI = new UltraMsgAPI();
        $this->encryption = new Encryption();
        $this->subscription = new SubscriptionValidator();
        $this->unifiedInbox = new UnifiedInboxService();
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * معالجة رسالة واردة
     * @param array $webhookData - بيانات Webhook
     * @return array
     */
    public function processIncomingMessage(array $webhookData): array
    {
        try {
            // 1. التحقق من البيانات الأساسية
            if (!isset($webhookData['message']) || !isset($webhookData['phone_number'])) {
                return [
                    'success' => false,
                    'error' => 'Missing required fields: message, phone_number'
                ];
            }

            // 2. تحديد المستخدم والموقع
            $userId = $this->resolveUserId($webhookData);
            $websiteId = $this->resolveWebsiteId($webhookData);

            if (!$userId || !$websiteId) {
                return [
                    'success' => false,
                    'error' => 'Unable to resolve user or website.'
                ];
            }

            // 3. التحقق من صلاحية الاشتراك
            $subscriptionCheck = $this->subscription->validateSubscription($userId);
            $viaWallet = false;

            if (!$subscriptionCheck['valid']) {
                // تصحيح حرج: العميل من غير اشتراك نشط كان بيترفض تمامًا
                // من البوت هنا - حتى لو عنده رصيد محفظة كافي يدفع بيه
                // ثمن الرد ده "حسب الطلب". دلوقتي بنفحص المحفظة كبديل
                // قبل الرفض النهائي.
                $walletCheck = (new WalletService())->canAffordUsage($userId, 'chat_message');
                if (!$walletCheck['can_afford']) {
                    return [
                        'success' => false,
                        'error' => 'No active subscription and insufficient wallet balance.'
                    ];
                }
                $viaWallet = true;
            }

            // 4. جلب إعدادات البوت
            $botSettings = $this->subscription->getBotSettings($userId, $websiteId);
            if (!$botSettings['is_enabled']) {
                return [
                    'success' => false,
                    'error' => 'Bot is disabled for this website.'
                ];
            }

            // 5. معالجة الرسالة
            $processedMessage = $this->messageProcessor->process(
                $webhookData,
                $userId,
                $websiteId,
                $botSettings
            );

            if (!$processedMessage['success']) {
                return $processedMessage;
            }

            // 6. حفظ الرسالة الواردة
            $messageId = $this->saveIncomingMessage(
                $userId,
                $websiteId,
                $webhookData,
                $processedMessage
            );

            if (!$messageId) {
                return [
                    'success' => false,
                    'error' => 'Failed to save message.'
                ];
            }

            // 6ب. مزامنة الرسالة مع Unified Inbox (AI Chat Platform - بند 1).
            // فشل هذه الخطوة لا يجب أن يوقف استقبال الرسالة - فقط تُفقد
            // ميزات AI Chat المتقدمة (Knowledge Base/Memory/Handoff) لهذه
            // الرسالة تحديدًا ويستمر المسار القديم كما كان يعمل قبلها.
            $conversationId = null;
            try {
                $conversation = $this->unifiedInbox->findOrCreateConversation(
                    $websiteId,
                    $userId,
                    $webhookData['platform'] ?? 'whatsapp',
                    $webhookData['phone_number'],
                    [
                        'name' => $webhookData['sender_name'] ?? null,
                        'phone' => $webhookData['phone_number'],
                        'email' => $webhookData['email'] ?? null,
                    ]
                );
                $conversationId = (int) $conversation->getAttribute('id');
                $this->unifiedInbox->linkMessage($messageId, $conversationId, true);
            } catch (Exception $syncError) {
                Logger::warning('Unified Inbox sync failed, continuing with legacy flow', [
                    'message_id' => $messageId,
                    'error' => $syncError->getMessage(),
                ]);
            }

            // 7. توليد رد ذكي - مع حماية Rate Limiting (بند 22): حد أقصى
            // لعدد الردود الآلية لكل موقع خلال دقيقة، لمنع الإغراق أو أي
            // حلقة لا نهائية ناتجة عن خطأ في تكامل خارجي (Webhook مكرر مثلاً).
            $rateLimitMax = defined('AI_CHAT_RATE_LIMIT_MAX') ? AI_CHAT_RATE_LIMIT_MAX : 20;
            $rateLimitWindow = defined('AI_CHAT_RATE_LIMIT_WINDOW_SECONDS') ? AI_CHAT_RATE_LIMIT_WINDOW_SECONDS : 60;
            $rateLimitOk = $this->rateLimiter->check('ai_chat_website_' . $websiteId, 'ai_chat_reply', $rateLimitMax, $rateLimitWindow);
            if (!$rateLimitOk) {
                Logger::warning('ChatManager: AI Chat rate limit exceeded, skipping AI reply', ['website_id' => $websiteId]);
                $reply = null;
            } else {
                $reply = $this->autoReplyEngine->generateReply(
                    $webhookData['message'],
                    $userId,
                    $processedMessage['context'] ?? [],
                    $botSettings,
                    $websiteId,
                    $conversationId
                );
            }

            // خصم ثمن الرد من المحفظة لو العميل مفيهوش اشتراك نشط (استخدام
            // "حسب الطلب") - بعد نجاح توليد الرد فعليًا، مش قبله.
            if ($viaWallet && $reply) {
                (new WalletService())->chargeForUsage($userId, 'chat_message', 'رد شات تلقائي');
            }

            // 8. حفظ الرد المُولَّد
            // مركز الأتمتة: هو اللي بيقرر الرد يتبعت لوحده ولا يستنى موافقة
            // (أوتوماتيك / بموافقتي / يتعلم مني / يدوي). لو المحرك مش متاح
            // بنرجع لمفتاح auto_pilot القديم زي ما كان.
            $automationGate = null;
            if ($reply && class_exists('AutomationEngine')) {
                $automationGate = AutomationEngine::instance()->gate(
                    (int) $userId,
                    (int) $websiteId,
                    'chat_replies',
                    'default',
                    'chat_message',
                    (string) $messageId,
                    [
                        'title' => ($webhookData['sender_name'] ?? $webhookData['phone_number'] ?? '') . ' · ' . ($webhookData['platform'] ?? 'whatsapp'),
                        'context' => (string) ($webhookData['message'] ?? ''),
                        'draft' => (string) $reply,
                        'payload' => ['platform' => $webhookData['platform'] ?? 'whatsapp'],
                    ]
                );
                $botSettings['auto_pilot'] = $automationGate['action'] === 'execute';
            }

            $botStatus = $botSettings['auto_pilot'] ? 'sent' : 'pending_approval';

            $this->saveReply(
                $messageId,
                $reply,
                $botStatus,
                $botSettings['auto_pilot']
            );

            // 9. معالجة نظام الموافقات
            if (!$botSettings['auto_pilot']) {
                $this->approvalSystem->addPendingApproval($messageId, $userId);
            }

            // 10. إرسال الرد إذا كان Auto Pilot مفعلاً - عبر القناة الصحيحة
            // لهذا الموقع تحديدًا (multi-tenant + multi-channel حقيقي).
            // ملاحظة: sendMessage() القديمة (أسفل) ما زالت موجودة ومتاحة
            // للاستخدام المباشر خارج هذا التدفق، لكن التدفق التلقائي هنا
            // كان يستخدمها بالخطأ رغم أنها WhatsApp-only فعليًا - ما كان
            // يمنع إرسال أي رد تلقائي عبر Messenger/Instagram/Email حتى
            // لو تم توليده بنجاح. تم تصحيحه لاستخدام sendMessageForWebsite()
            // متعددة القنوات (بند 1).
            if ($botSettings['auto_pilot'] && $reply) {
                $channelForSend = $webhookData['platform'] ?? 'whatsapp';
                $recipientForSend = $channelForSend === 'email'
                    ? ($webhookData['email'] ?? $webhookData['phone_number'])
                    : $webhookData['phone_number'];

                $sent = $this->sendMessageForWebsite($websiteId, $recipientForSend, $reply, $channelForSend);

                if ($sent) {
                    $this->markMessageAsSent($messageId);
                }
                if ($automationGate && class_exists('AutomationEngine')) {
                    AutomationEngine::instance()->complete($automationGate['decision_id'] ?? null, (bool) $sent, $sent ? null : 'تعذر الإرسال على القناة');
                }
            }

            // 11. تسجيل النشاط
            Logger::info('Chat message processed', [
                'message_id' => $messageId,
                'user_id' => $userId,
                'website_id' => $websiteId,
                'auto_pilot' => $botSettings['auto_pilot'],
                'bot_status' => $botStatus
            ]);

            return [
                'success' => true,
                'message_id' => $messageId,
                'bot_status' => $botStatus,
                'auto_pilot' => (bool) $botSettings['auto_pilot'],
                'reply_generated' => !empty($reply),
                'message' => 'Message processed successfully.'
            ];

        } catch (Exception $e) {
            Logger::error('Chat Processing Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * الموافقة على رد البوت أو رفضه - wrapper بينادي ApprovalSystem الحقيقي.
     * ملحوظة: الدالة دي كانت بتتنادى من ChatController::approveReply() من
     * غير ما تكون موجودة أصلاً في الكلاس ده، يعني كل زرار "موافقة/رفض" في
     * صفحة الرسائل المعلّقة كان بيسبب Fatal Error (Call to undefined
     * method) بدل ما يشتغل.
     * @param int $messageId
     * @param int $userId
     * @param string $action 'approve' أو 'reject'
     * @param string $reason سبب الرفض (اختياري)
     * @return array
     */
    public function approveBotReply(int $messageId, int $userId, string $action = 'approve', string $reason = ''): array
    {
        if ($action === 'reject') {
            return $this->approvalSystem->reject($messageId, $userId, $reason);
        }
        return $this->approvalSystem->approve($messageId, $userId);
    }

    /**
     * إرسال رسالة
     * @param string $phoneNumber - رقم المستلم
     * @param string $message - نص الرسالة
     * @param string $platform - المنصة
     * @return bool
     */
    public function sendMessage(string $phoneNumber, string $message, string $platform = 'whatsapp'): bool
    {
        try {
            switch ($platform) {
                case 'whatsapp':
                    return $this->whatsAppAPI->sendMessage($phoneNumber, $message);

                case 'ultramsg':
                    return $this->ultraMsgAPI->sendMessage($phoneNumber, $message);

                default:
                    Logger::warning('Unsupported platform for sending', ['platform' => $platform]);
                    return false;
            }

        } catch (Exception $e) {
            Logger::error('Send Message Error', [
                'phone' => $phoneNumber,
                'platform' => $platform,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * إرسال رسالة عن طريق القناة الصحيحة الخاصة بموقع معيّن (multi-tenant
     * حقيقي، multi-channel حقيقي - بند 1). لكل قناة تكامل خاص بها:
     * WhatsApp/UltraMsg (كان موجودًا)، Messenger وInstagram (عبر
     * PlatformConnection مثل باقي التكاملات)، Email (عبر Mailer الموجود).
     * @param int $websiteId
     * @param string $recipient رقم الهاتف، أو Facebook PSID، أو Instagram IGSID، أو الإيميل - حسب $channel
     * @param string $message
     * @param string $channel whatsapp|website_chat|messenger|instagram|email (افتراضيًا whatsapp للتوافق مع الكود القديم)
     * @return bool
     */
    public function sendMessageForWebsite(int $websiteId, string $recipient, string $message, string $channel = 'whatsapp'): bool
    {
        try {
            switch ($channel) {
                case 'messenger':
                    return $this->sendViaPlatformConnection($websiteId, 'messenger', function ($token) use ($recipient, $message) {
                        return (new MessengerAPI($token))->sendMessage($recipient, $message);
                    });

                case 'instagram':
                    return $this->sendViaPlatformConnection($websiteId, 'instagram', function ($token) use ($recipient, $message) {
                        return (new InstagramAPI($token))->sendMessage($recipient, $message);
                    });

                case 'email':
                    return (new EmailChannelAPI())->sendMessage($recipient, $message);

                case 'whatsapp':
                case 'website_chat':
                default:
                    return $this->sendViaUltraMsg($websiteId, $recipient, $message);
            }
        } catch (Exception $e) {
            Logger::error('Send Message For Website Error', [
                'website_id' => $websiteId,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * مسار الإرسال الأصلي عبر UltraMsg (WhatsApp) - نفس المنطق القديم
     * بالضبط، فقط مستخرَج في دالة مستقلة حتى يستدعيه sendMessageForWebsite()
     * كأحد فروع القناة.
     * @param int $websiteId
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    private function sendViaUltraMsg(int $websiteId, string $phoneNumber, string $message): bool
    {
        $connection = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'ultramsg',
            'status' => 'connected',
        ], [], 1);

        if (empty($connection)) {
            Logger::warning('No UltraMsg connection for website', ['website_id' => $websiteId]);
            return false;
        }

        $encryption = new Encryption();
        $instanceId = $connection[0]->getAttribute('external_account_id');
        $apiKey = $encryption->decrypt((string) $connection[0]->getAttribute('access_token'));

        $api = new UltraMsgAPI($instanceId, $apiKey);
        return $api->sendMessage($phoneNumber, $message);
    }

    /**
     * مسار مشترك لأي قناة تعتمد على PlatformConnection بـaccess_token
     * مشفّر واحد (Messenger/Instagram) - يفكّ التشفير ثم يستدعي الكولباك.
     * @param int $websiteId
     * @param string $platform
     * @param callable $sendCallback function(string $decryptedToken): bool
     * @return bool
     */
    private function sendViaPlatformConnection(int $websiteId, string $platform, callable $sendCallback): bool
    {
        $connection = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => $platform,
            'status' => 'connected',
        ], [], 1);

        if (empty($connection)) {
            Logger::warning('No ' . $platform . ' connection for website', ['website_id' => $websiteId]);
            return false;
        }

        $encryption = new Encryption();
        $token = $encryption->decrypt((string) $connection[0]->getAttribute('access_token'));

        return $sendCallback($token);
    }

    /**
     * الحصول على محادثة
     * @param string $sessionId - معرف الجلسة
     * @param int $userId - معرف المستخدم
     * @param int $limit - عدد الرسائل
     * @return array
     */
    public function getConversation(string $sessionId, int $userId, int $limit = 50): array
    {
        try {
            $sql = "SELECT * FROM chat_messages 
                    WHERE session_id = :session_id 
                    AND user_id = :user_id
                    ORDER BY created_at DESC 
                    LIMIT :limit";

            $messages = $this->db->query($sql, [
                ':session_id' => $sessionId,
                ':user_id' => $userId,
                ':limit' => $limit
            ]);

            // فك تشفير البيانات الحساسة
            foreach ($messages as &$message) {
                $message = $this->decryptMessageData($message);
            }

            return [
                'success' => true,
                'messages' => array_reverse($messages),
                'count' => count($messages)
            ];

        } catch (Exception $e) {
            Logger::error('Get Conversation Error', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * الحصول على إحصائيات الشات
     * @param int $userId - معرف المستخدم
     * @param int|null $websiteId - معرف الموقع
     * @return array
     */
    public function getChatStats(int $userId, ?int $websiteId = null): array
    {
        try {
            $params = [$userId];
            $sql = "SELECT 
                        COUNT(*) as total_messages,
                        SUM(CASE WHEN message_direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                        SUM(CASE WHEN message_direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
                        SUM(CASE WHEN bot_status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
                        SUM(CASE WHEN bot_status = 'sent' THEN 1 ELSE 0 END) as sent,
                        SUM(CASE WHEN bot_status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN bot_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        COUNT(DISTINCT session_id) as total_conversations
                    FROM chat_messages 
                    WHERE user_id = ?";

            if ($websiteId) {
                $sql .= " AND website_id = ?";
                $params[] = $websiteId;
            }

            $result = $this->db->query($sql, $params);

            if (empty($result)) {
                return [
                    'total_messages' => 0,
                    'incoming' => 0,
                    'outgoing' => 0,
                    'pending_approval' => 0,
                    'sent' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'total_conversations' => 0
                ];
            }

            return [
                'total_messages' => (int) $result[0]['total_messages'],
                'incoming' => (int) $result[0]['incoming'],
                'outgoing' => (int) $result[0]['outgoing'],
                'pending_approval' => (int) $result[0]['pending_approval'],
                'sent' => (int) $result[0]['sent'],
                'approved' => (int) $result[0]['approved'],
                'rejected' => (int) $result[0]['rejected'],
                'total_conversations' => (int) $result[0]['total_conversations']
            ];

        } catch (Exception $e) {
            Logger::error('Get Chat Stats Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'total_messages' => 0,
                'incoming' => 0,
                'outgoing' => 0,
                'pending_approval' => 0,
                'sent' => 0,
                'approved' => 0,
                'rejected' => 0,
                'total_conversations' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * حفظ رسالة واردة
     * @param int $userId
     * @param int $websiteId
     * @param array $webhookData
     * @param array $processedMessage
     * @return int
     */
    private function saveIncomingMessage(
        int $userId,
        int $websiteId,
        array $webhookData,
        array $processedMessage
    ): int {
        try {
            $phoneNumber = $webhookData['phone_number'];
            $encryptedPhone = $this->encryption->encryptCustomerData($phoneNumber, $phoneNumber);

            $sql = "INSERT INTO chat_messages (
                        website_id, user_id, session_id, platform, platform_message_id,
                        customer_name, customer_phone, encrypted_phone,
                        customer_email, encrypted_email, message_direction,
                        message_text, message_language, webhook_raw_data,
                        ip_address, user_agent, bot_status, is_auto_pilot,
                        created_at
                    ) VALUES (
                        :website_id, :user_id, :session_id, :platform, :platform_message_id,
                        :customer_name, :customer_phone, :encrypted_phone,
                        :customer_email, :encrypted_email, 'incoming',
                        :message_text, :message_language, :webhook_raw_data,
                        :ip_address, :user_agent, 'pending_approval', :is_auto_pilot,
                        NOW()
                    )";

            $params = [
                ':website_id' => $websiteId,
                ':user_id' => $userId,
                ':session_id' => $processedMessage['session_id'] ?? $this->generateSessionId($phoneNumber),
                ':platform' => $webhookData['platform'] ?? 'whatsapp',
                ':platform_message_id' => $webhookData['platform_message_id'] ?? null,
                ':customer_name' => $webhookData['sender_name'] ?? null,
                ':customer_phone' => $phoneNumber,
                ':encrypted_phone' => $encryptedPhone,
                ':customer_email' => $webhookData['email'] ?? null,
                ':encrypted_email' => !empty($webhookData['email'])
                    ? $this->encryption->encryptCustomerData($webhookData['email'], $phoneNumber)
                    : null,
                ':message_text' => $webhookData['message'],
                ':message_language' => $webhookData['language'] ?? 'ar',
                ':webhook_raw_data' => json_encode($webhookData),
                ':ip_address' => $webhookData['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $webhookData['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':is_auto_pilot' => $processedMessage['auto_pilot'] ? 1 : 0
            ];

            return (int) $this->db->query($sql, $params);

        } catch (Exception $e) {
            Logger::error('Save Incoming Message Error', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * حفظ الرد المُولَّد
     * @param int $messageId
     * @param string|null $reply
     * @param string $botStatus
     * @param bool $isAutoPilot
     */
    private function saveReply(int $messageId, ?string $reply, string $botStatus, bool $isAutoPilot): void
    {
        try {
            $sql = "UPDATE chat_messages 
                    SET ai_reply_generated = :reply,
                        ai_reply_language = 'ar',
                        bot_status = :bot_status,
                        is_auto_pilot = :is_auto_pilot,
                        updated_at = NOW()
                    WHERE id = :message_id";

            $this->db->query($sql, [
                ':message_id' => $messageId,
                ':reply' => $reply,
                ':bot_status' => $botStatus,
                ':is_auto_pilot' => $isAutoPilot ? 1 : 0
            ]);

        } catch (Exception $e) {
            Logger::error('Save Reply Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تحديث حالة الرسالة إلى مرسلة
     * @param int $messageId
     */
    private function markMessageAsSent(int $messageId): void
    {
        try {
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'sent',
                        sent_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :message_id";

            $this->db->query($sql, [':message_id' => $messageId]);

        } catch (Exception $e) {
            Logger::error('Mark Message As Sent Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تحديد معرف المستخدم من بيانات Webhook
     * @param array $data
     * @return int|null
     */
    private function resolveUserId(array $data): ?int
    {
        if (isset($data['user_id'])) {
            return (int) $data['user_id'];
        }

        if (isset($data['api_key'])) {
            $sql = "SELECT id FROM users WHERE api_key = :api_key LIMIT 1";
            $result = $this->db->query($sql, [':api_key' => $data['api_key']]);
            return !empty($result) ? (int) $result[0]['id'] : null;
        }

        if (isset($data['whatsapp_phone'])) {
            $sql = "SELECT user_id FROM bot_settings WHERE whatsapp_phone_number = :phone LIMIT 1";
            $result = $this->db->query($sql, [':phone' => $data['whatsapp_phone']]);
            return !empty($result) ? (int) $result[0]['user_id'] : null;
        }

        return null;
    }

    /**
     * تحديد معرف الموقع من بيانات Webhook
     * @param array $data
     * @return int|null
     */
    private function resolveWebsiteId(array $data): ?int
    {
        if (isset($data['website_id'])) {
            return (int) $data['website_id'];
        }

        if (isset($data['website_url'])) {
            $urlCol = Website::urlColumn();
            $sql = "SELECT id FROM websites WHERE {$urlCol} = :url LIMIT 1";
            $result = $this->db->query($sql, [':url' => $data['website_url']]);
            return !empty($result) ? (int) $result[0]['id'] : null;
        }

        if (isset($data['whatsapp_phone'])) {
            $sql = "SELECT website_id FROM bot_settings WHERE whatsapp_phone_number = :phone LIMIT 1";
            $result = $this->db->query($sql, [':phone' => $data['whatsapp_phone']]);
            return !empty($result) ? (int) $result[0]['website_id'] : null;
        }

        return null;
    }

    /**
     * توليد معرف جلسة
     * @param string $phoneNumber
     * @return string
     */
    private function generateSessionId(string $phoneNumber): string
    {
        return 'session_' . md5($phoneNumber . '_' . date('Y-m-d'));
    }

    /**
     * فك تشفير بيانات الرسالة
     * @param array $message
     * @return array
     */
    private function decryptMessageData(array $message): array
    {
        if (!empty($message['encrypted_phone'])) {
            $message['customer_phone'] = $this->encryption->decryptCustomerData(
                $message['encrypted_phone'],
                $message['customer_phone'] ?? ''
            );
        }

        if (!empty($message['encrypted_email'])) {
            $message['customer_email'] = $this->encryption->decryptCustomerData(
                $message['encrypted_email'],
                $message['customer_phone'] ?? ''
            );
        }

        unset($message['encrypted_phone']);
        unset($message['encrypted_email']);

        return $message;
    }
}
