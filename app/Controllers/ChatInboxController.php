<?php

/**
 * Tourfecto - AI Chat Platform
 * Unified Inbox Controller (بند 1، 8، 15، 16): واجهة API للوحة الإدارة
 * لعرض كل المحادثات من كل القنوات في مكان واحد، والبحث/الفلترة، وتحويل
 * المحادثة لموظف والعكس، وإرسال رد يدوي من الموظف.
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatInboxController extends Controller
{
    /** @var UnifiedInboxService */
    private $inbox;

    /** @var AiChatConversation */
    private $conversationModel;

    /** @var AiReplySuggestionsService */
    private $replySuggestions;

    public function __construct()
    {
        parent::__construct();
        $this->inbox = new UnifiedInboxService();
        $this->conversationModel = new AiChatConversation();
        $this->replySuggestions = new AiReplySuggestionsService();
    }

    /**
     * قائمة المحادثات مع فلاتر وبحث (بند 15، 16).
     * GET /api/ai-chat/websites/{id}/conversations
     * Query: status, ai_status, lead_status, channel, priority, tag, search, page
     */
    /**
     * GET /api/ai-chat/websites/{id}/conversations/pulse - "في جديد؟" خفيف جدًا
     * الصفحة بتسأل كل كام ثانية، وبتعيد تحميل القائمة/المحادثة بس لما البصمة تتغير
     * (بدل ما تحمّل كل حاجة كل 20 ثانية والرسالة الجديدة تتأخر).
     */
    public function pulse(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $r = Database::getInstance()->query(
            'SELECT COUNT(*) AS n, MAX(updated_at) AS u, MAX(last_message_at) AS m, COALESCE(SUM(unread_count), 0) AS unread
               FROM ai_conversations WHERE website_id = ?',
            [(int) $website->getAttribute('id')]
        );
        $row = $r[0] ?? [];
        $active = (int) $this->get('active', 0);
        $activeStamp = '';
        if ($active > 0) {
            $a = Database::getInstance()->query('SELECT updated_at, last_message_at FROM ai_conversations WHERE id = ? AND website_id = ?', [$active, (int) $website->getAttribute('id')]);
            $activeStamp = $a ? ($a[0]['updated_at'] . '|' . $a[0]['last_message_at']) : '';
        }
        return $this->success([
            'fp' => md5(($row['n'] ?? 0) . '|' . ($row['u'] ?? '') . '|' . ($row['m'] ?? '')),
            'active_fp' => $activeStamp !== '' ? md5($activeStamp) : '',
            'unread' => (int) ($row['unread'] ?? 0),
        ]);
    }

    public function index(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $filters = [
            'status' => $this->get('status'),
            'ai_status' => $this->get('ai_status'),
            'lead_status' => $this->get('lead_status'),
            'channel' => $this->get('channel'),
            'priority' => $this->get('priority'),
            'tag' => $this->get('tag'),
            'search' => $this->get('search'),
            'assigned_agent_id' => $this->get('assigned_agent_id'),
            'unread_only' => $this->get('unread_only'),
        ];
        $filters = array_filter($filters, function ($v) {
            return $v !== null && $v !== '';
        });

        $page = max(1, (int) $this->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        if (($filters['assigned_agent_id'] ?? '') === 'me') {
            $filters['assigned_agent_id'] = (int) $this->user['id'];
        }
        $conversations = $this->inbox->search((int) $website->getAttribute('id'), $filters, $limit, $offset);
        $list = array_map([$this, 'serializeConversation'], $conversations);
        $names = self::userNames(array_column($list, 'assigned_agent_id'));
        foreach ($list as &$c) {
            $c['assigned_agent_name'] = $c['assigned_agent_id'] ? ($names[(int) $c['assigned_agent_id']] ?? null) : null;
        }
        unset($c);

        return $this->success([
            'conversations' => $list,
            'page' => $page,
            'my_role' => $this->inboxRole,
        ]);
    }

    /**
     * تفاصيل محادثة واحدة: الرسائل + الملخص + الوسوم + حالة الـLead.
     * GET /api/ai-chat/websites/{id}/conversations/{conversationId}
     * كل رسالة معاها: media (لو صورة/صوت/ملف)، delivery (sent/delivered/read/failed) لرسايل واتساب الطالعة،
     * وreply_delivery لرد الـAI المتسجل على الرسالة الواردة. المحادثة معاها whatsapp.window_seconds_left.
     */
    public function show(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $db = Database::getInstance();
        $websiteId = (int) $website->getAttribute('id');
        // Messenger / Instagram conversations that still show a bare id or no picture get their profile on open (best effort).
        $channel = (string) $conversation->getAttribute('channel');
        if (in_array($channel, ['messenger', 'instagram'], true) && (int) $this->get('before', 0) === 0
            && (trim((string) $conversation->getAttribute('customer_name')) === '' || ChatContactPhotos::version($websiteId, (int) $conversation->getAttribute('id')) === 0)) {
            MetaContactProfile::enrichForWebsite($db, $websiteId, $channel, (string) $conversation->getAttribute('channel_thread_id'));
            $conversation = $this->authorizedConversation((int) $conversation->getAttribute('id'), $websiteId) ?: $conversation;
        }
        // Newest 200 messages, shown oldest-first (ASC + LIMIT used to hide new messages in long conversations).
        // ?before=<message id> returns the 200 messages before that one, so the inbox can load earlier history.
        $before = max(0, (int) $this->get('before', 0));
        $rows = $db->query(
            "SELECT id, user_id, session_id, message_direction, message_text, ai_reply_generated, ai_confidence_score,
                    bot_status, is_auto_pilot, sent_at, created_at, platform_message_id, webhook_raw_data
             FROM chat_messages WHERE conversation_id = ? AND website_id = ?" . ($before > 0 ? ' AND id < ?' : '') . " ORDER BY id DESC LIMIT 200",
            $before > 0 ? [$conversation->getAttribute('id'), $websiteId, $before] : [$conversation->getAttribute('id'), $websiteId]
        );
        $hasMore = count($rows ?: []) >= 200;
        $rows = array_reverse($rows ?: []);

        $wamids = [];
        foreach ($rows as &$m) {
            $raw = json_decode((string) ($m['webhook_raw_data'] ?? ''), true);
            unset($m['webhook_raw_data']); // never send raw provider data to the browser
            $m['media'] = null;
            // Messenger / Instagram attachments are served from Meta's own CDN (https only), not stored by us.
            if (is_array($raw) && is_array($raw['media_ext'] ?? null) && self::isMetaCdnUrl((string) ($raw['media_ext']['url'] ?? ''))) {
                $m['media'] = ['type' => (string) ($raw['media_ext']['type'] ?? 'file'), 'mime' => '', 'filename' => '', 'voice' => false, 'url' => (string) $raw['media_ext']['url']];
            }
            if (is_array($raw) && isset($raw['media']['id'], $raw['media']['type'])) {
                $m['media'] = [
                    'type' => (string) $raw['media']['type'],
                    'mime' => (string) ($raw['media']['mime'] ?? ''),
                    'filename' => (string) ($raw['media']['filename'] ?? ''),
                    'voice' => !empty($raw['media']['voice']),
                ];
            }
            $m['template'] = is_array($raw) && !empty($raw['template']) ? (string) $raw['template'] : null;
            $m['catalog'] = is_array($raw) && !empty($raw['catalog']) ? (string) $raw['catalog'] : null;
            $m['reply_wamid'] = is_array($raw) && is_string($raw['reply_wamid'] ?? null) ? $raw['reply_wamid'] : null;
            $m['quote'] = is_array($raw) && is_array($raw['quote'] ?? null) ? ['id' => (int) ($raw['quote']['id'] ?? 0), 'text' => (string) ($raw['quote']['text'] ?? ''), 'who' => (string) ($raw['quote']['who'] ?? '')] : null;
            $m['our_reaction'] = is_array($raw) && is_string($raw['our_reaction'] ?? null) ? $raw['our_reaction'] : null;
            $m['interactive'] = is_array($raw) && is_array($raw['interactive'] ?? null) ? ['kind' => (string) ($raw['interactive']['kind'] ?? ''), 'options' => array_slice(array_map('strval', (array) ($raw['interactive']['options'] ?? [])), 0, 10)] : null;
            $m['customer_reaction'] = is_array($raw) && is_string($raw['customer_reaction'] ?? null) ? $raw['customer_reaction'] : null;
            $m['customer_reaction_reply'] = is_array($raw) && is_string($raw['customer_reaction_reply'] ?? null) ? $raw['customer_reaction_reply'] : null;
            $m['reply_to_wamid'] = is_array($raw) && is_string($raw['reply_to_wamid'] ?? null) ? $raw['reply_to_wamid'] : null;
            if ($m['message_direction'] === 'outgoing' && !empty($m['platform_message_id'])) $wamids[] = (string) $m['platform_message_id'];
            if ($m['reply_wamid'] !== null) $wamids[] = $m['reply_wamid'];
        }
        unset($m);

        // A customer's swipe-reply: look up the message they quoted (same conversation) so the bubble can show it.
        $quoted = [];
        $replyIds = array_values(array_unique(array_filter(array_column($rows, 'reply_to_wamid'))));
        if ($replyIds) {
            $in = implode(',', array_fill(0, count($replyIds), '?'));
            foreach ($db->query("SELECT id, message_direction, message_text, platform_message_id FROM chat_messages WHERE conversation_id = ? AND website_id = ? AND platform_message_id IN ($in)", array_merge([$conversation->getAttribute('id'), $websiteId], $replyIds)) ?: [] as $q) {
                $quoted[(string) $q['platform_message_id']] = ['id' => (int) $q['id'], 'text' => mb_substr(trim((string) $q['message_text']) !== '' ? trim((string) $q['message_text']) : '📎', 0, 120), 'who' => $q['message_direction'] === 'outgoing' ? 'you' : 'customer'];
            }
        }

        $statuses = [];
        if ($wamids && $conversation->getAttribute('channel') === 'whatsapp') {
            try {
                $wamids = array_values(array_unique($wamids));
                $in = implode(',', array_fill(0, count($wamids), '?'));
                foreach ($db->query("SELECT wamid, status, error_message FROM whatsapp_delivery_statuses WHERE website_id = ? AND wamid IN ($in)", array_merge([$websiteId], $wamids)) ?: [] as $st) {
                    $statuses[(string) $st['wamid']] = ['status' => (string) $st['status'], 'error' => $st['error_message']];
                }
            } catch (Throwable $e) {
                // Delivery table may be missing on older installs; the inbox still works without ticks.
            }
        }
        $senderNames = self::userNames(array_column(array_filter($rows, fn($r) => $r['message_direction'] === 'outgoing'), 'user_id'));
        foreach ($rows as &$m) {
            $own = $m['message_direction'] === 'outgoing' ? (string) ($m['platform_message_id'] ?? '') : '';
            $m['delivery'] = $own !== '' ? ($statuses[$own]['status'] ?? 'accepted') : null;
            $m['delivery_error'] = $own !== '' ? ($statuses[$own]['error'] ?? null) : null;
            $m['reply_delivery'] = $m['reply_wamid'] !== null ? ($statuses[$m['reply_wamid']]['status'] ?? 'accepted') : null;
            $m['by_me'] = $m['message_direction'] === 'outgoing' && (int) $m['user_id'] === (int) $this->user['id'];
            $m['sender_name'] = $m['message_direction'] === 'outgoing' && str_starts_with((string) ($m['session_id'] ?? ''), 'agent_') ? ($senderNames[(int) $m['user_id']] ?? null) : null;
            if (empty($m['quote']) && !empty($m['reply_to_wamid']) && isset($quoted[$m['reply_to_wamid']])) $m['quote'] = $quoted[$m['reply_to_wamid']];
            unset($m['reply_wamid'], $m['reply_to_wamid'], $m['platform_message_id'], $m['user_id'], $m['session_id']);
        }
        unset($m);

        // إعادة تفعيل عدّاد غير المقروء عند فتح الموظف للمحادثة
        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), ['unread_count' => 0]);

        $data = $this->serializeConversation($conversation, true);
        $data['my_role'] = $this->inboxRole;
        $aid = (int) $conversation->getAttribute('assigned_agent_id');
        $data['assigned_agent_name'] = $aid ? (self::userNames([$aid])[$aid] ?? null) : null;
        if (in_array((string) $conversation->getAttribute('channel'), ['messenger', 'instagram'], true)) {
            $r = Database::getInstance()->query(
                "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(created_at), INTERVAL 24 HOUR)) AS s FROM chat_messages WHERE conversation_id = ? AND website_id = ? AND message_direction = 'incoming'",
                [(int) $conversation->getAttribute('id'), $websiteId]
            );
            $data['meta_window'] = ['seconds_left' => max(0, (int) ($r[0]['s'] ?? 0))];
        }
        if ($conversation->getAttribute('channel') === 'whatsapp') {
            $data['whatsapp'] = [
                'window_seconds_left' => $this->windowSecondsLeft((int) $conversation->getAttribute('id'), $websiteId),
                // Media, templates and delivery ticks need the official WhatsApp Cloud API connection.
                'cloud' => WhatsAppCloudAPI::connectionRow($websiteId) !== null,
            ];
        }

        return $this->success([
            'conversation' => $data,
            'messages' => $rows,
            'has_more' => $hasMore,
        ]);
    }

    /**
     * إرسال رد يدوي من موظف داخل محادثة (لا يمر على AI - رد بشري مباشر).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/reply
     * Body: message
     */
    public function reply(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $message = trim((string) $this->get('message', ''));
        if ($message === '') {
            return $this->error('message is required', 422);
        }

        [$replyWamid, $quote] = $this->quotedMessage($conversation, (int) $this->get('reply_to_message_id', 0));
        [$sent, $failReason, $wamid] = $this->deliverText($website, $conversation, $message, $replyWamid);
        $agentMessageId = $this->insertOutgoing($website, $conversation, $message, $sent, $wamid, $quote ? ['quote' => $quote] : []);

        // مركز الأتمتة: رد الموظف اليدوي بيبقى مثال لأسلوب الردود الآلية
        if (class_exists('AutomationEngine')) {
            try {
                $last = Database::getInstance()->query(
                    "SELECT message_text FROM chat_messages WHERE conversation_id = ? AND message_direction <> 'outgoing' ORDER BY id DESC LIMIT 1",
                    [(int) $conversation->getAttribute('id')]
                );
                AutomationEngine::instance()->recordManualAction(
                    (int) $this->user['id'], 'chat_replies', 'default', 'chat_manual', (string) $agentMessageId,
                    $message, (string) ($last[0]['message_text'] ?? '')
                );
            } catch (Throwable $e) {
            }
        }

        return $this->success(['sent' => $sent, 'reason' => $sent ? null : $failReason, 'message_id' => (int) $agentMessageId], $sent ? 'Reply sent' : 'Reply saved but delivery to channel failed');
    }

    /**
     * إعادة إرسال رسالة نصية فشلت (نفس السطر بيتحدث، مش رسالة جديدة).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/messages/{messageId}/retry
     */
    public function retry(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        $db = Database::getInstance();
        $row = $db->query(
            "SELECT id, message_text, webhook_raw_data FROM chat_messages WHERE id = ? AND conversation_id = ? AND website_id = ? AND message_direction = 'outgoing' AND bot_status = 'failed' LIMIT 1",
            [(int) ($params['messageId'] ?? 0), (int) $conversation->getAttribute('id'), (int) $website->getAttribute('id')]
        )[0] ?? null;
        if (!$row) {
            return $this->error('Only a failed message can be resent', 404);
        }
        $raw = json_decode((string) ($row['webhook_raw_data'] ?? ''), true);
        if (is_array($raw) && (!empty($raw['media']) || !empty($raw['template']) || !empty($raw['catalog']))) {
            return $this->error('Send the file or template again from the reply box', 422);
        }
        // Claim the row first so a double click cannot send the same message twice.
        $claimed = $db->query("UPDATE chat_messages SET bot_status = 'approved' WHERE id = ? AND bot_status = 'failed'", [(int) $row['id']]);
        if ((int) $claimed !== 1) {
            return $this->error('This message is already being resent', 409);
        }
        [$sent, $failReason, $wamid] = $this->deliverText($website, $conversation, (string) $row['message_text']);
        $db->query(
            "UPDATE chat_messages SET bot_status = ?, platform_message_id = ?, sent_at = IF(? = 1, NOW(), NULL) WHERE id = ?",
            [$sent ? 'sent' : 'failed', $wamid, $sent ? 1 : 0, (int) $row['id']]
        );
        return $this->success(['sent' => $sent, 'reason' => $sent ? null : $failReason], $sent ? 'Reply sent' : 'Delivery failed again');
    }

    /**
     * عرض صورة/صوت/فيديو/ملف من رسالة واتساب (بيتحمل من Meta ويتحفظ نسخة خاصة على السيرفر).
     * GET /api/ai-chat/websites/{id}/conversations/{conversationId}/messages/{messageId}/media
     */
    public function media(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        $row = Database::getInstance()->query(
            "SELECT webhook_raw_data FROM chat_messages WHERE id = ? AND conversation_id = ? AND website_id = ? LIMIT 1",
            [(int) ($params['messageId'] ?? 0), (int) $conversation->getAttribute('id'), $websiteId]
        )[0] ?? null;
        $raw = $row ? json_decode((string) ($row['webhook_raw_data'] ?? ''), true) : null;
        $mediaId = is_array($raw) ? (string) ($raw['media']['id'] ?? '') : '';
        if (!preg_match('/^[0-9]{1,64}$/D', $mediaId)) {
            return $this->error('No media in this message', 404);
        }

        $dir = TOURFECTO_STORAGE . '/private/wa-media/' . $websiteId;
        $file = $dir . '/' . hash('sha256', $websiteId . '|' . $mediaId);
        $mime = '';
        if (is_file($file) && is_file($file . '.mime')) {
            $mime = (string) file_get_contents($file . '.mime');
        } else {
            $api = WhatsAppCloudAPI::forWebsite($websiteId);
            if (!$api) {
                return $this->error('WhatsApp is not connected for this website', 409);
            }
            $r = $api->downloadMedia($mediaId);
            if (!$r['ok']) {
                return $this->error($r['error'] ?: 'Media is not available', 404);
            }
            $mime = (string) $r['mime'];
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            if (@file_put_contents($file, $r['bytes'], LOCK_EX) === false) {
                // Could not cache: serve directly.
                return $this->streamMedia($r['bytes'], $mime, (string) ($raw['media']['filename'] ?? ''));
            }
            @file_put_contents($file . '.mime', $mime, LOCK_EX);
        }
        return $this->streamMedia((string) file_get_contents($file), $mime, (string) ($raw['media']['filename'] ?? ''));
    }

    private function streamMedia(string $bytes, string $mime, string $filename): array
    {
        if (!preg_match('#^[a-z]+/[a-z0-9.+-]+(;\s*codecs=[a-z0-9.,"]+)?$#i', $mime)) $mime = 'application/octet-stream';
        // Only types a browser can show inline safely; everything else downloads.
        $inline = preg_match('#^(image/(jpeg|png|webp|gif)|audio/|video/(mp4|3gpp))#i', $mime) === 1;
        $name = preg_replace('/[^\w.\- ]+/u', '_', $filename !== '' ? $filename : 'whatsapp-media');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        echo $bytes;
        exit;
    }

    /**
     * إرسال صورة/ملف/صوت/فيديو للعميل على واتساب (داخل نافذة الـ24 ساعة).
     * POST multipart: file, caption (اختياري)
     * /api/ai-chat/websites/{id}/conversations/{conversationId}/media
     */
    public function sendMedia(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation || $conversation->getAttribute('channel') !== 'whatsapp') {
            return $this->error('Files can only be sent in WhatsApp conversations', 422);
        }
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $tooBig = in_array((int) ($f['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return $this->error($tooBig ? 'The file is larger than the server upload limit' : 'Choose a file to send', 422);
        }
        // Trust the file's content, not the browser-supplied type.
        $mime = self::detectMime((string) $f['tmp_name'], (string) ($f['name'] ?? ''));
        if ($mime === 'audio/x-m4a') $mime = 'audio/mp4';
        // WhatsApp Cloud API supported types and size limits.
        $types = [
            'image/jpeg' => ['image', 5], 'image/png' => ['image', 5],
            'video/mp4' => ['video', 16], 'video/3gpp' => ['video', 16],
            'audio/mpeg' => ['audio', 16], 'audio/ogg' => ['audio', 16], 'audio/aac' => ['audio', 16], 'audio/mp4' => ['audio', 16], 'audio/amr' => ['audio', 16],
            'application/pdf' => ['document', 100], 'text/plain' => ['document', 100],
            'application/msword' => ['document', 100], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 100],
            'application/vnd.ms-excel' => ['document', 100], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', 100],
            'application/vnd.ms-powerpoint' => ['document', 100], 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', 100],
        ];
        if (!isset($types[$mime])) {
            return $this->error('WhatsApp does not accept this file type. Use JPG/PNG images, MP4 video, MP3/OGG audio, or PDF/Word/Excel/PowerPoint files', 422);
        }
        [$type, $maxMb] = $types[$mime];
        if ((int) $f['size'] > $maxMb * 1048576) {
            return $this->error('WhatsApp allows up to ' . $maxMb . ' MB for this file type', 422);
        }
        $caption = trim((string) $this->get('caption', ''));
        $filename = mb_substr(basename((string) ($f['name'] ?? 'file')), 0, 240);

        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('Sending files needs the official WhatsApp connection (Cloud API)', 409);
        }
        [$to, $reason] = $this->whatsappRecipient($conversation);
        if ($to === '') {
            return $this->success(['sent' => false, 'reason' => $reason], 'Not sent');
        }
        if ((int) $this->windowSecondsLeft((int) $conversation->getAttribute('id'), $websiteId) <= 0) {
            return $this->success(['sent' => false, 'reason' => 'outside_24h'], 'Not sent');
        }
        $up = $api->uploadMedia((string) $f['tmp_name'], $mime);
        if (empty($up['ok']) || empty($up['value'])) {
            return $this->success(['sent' => false, 'reason' => 'provider_rejected'], 'Upload to WhatsApp failed');
        }
        $r = $api->sendMedia($to, $type, (string) $up['value'], $caption, $filename);
        $sent = !empty($r['ok']);
        if (!$sent) {
            Logger::warning('WhatsApp media send failed', ['website_id' => $websiteId, 'http_code' => (int) ($r['http_code'] ?? 0), 'error_code' => $r['error_code'] ?? null]);
        }
        $label = ['image' => '[Image]', 'video' => '[Video]', 'audio' => '[Audio]', 'document' => '[Document] ' . $filename][$type];
        $text = trim($label . ($caption !== '' && $type !== 'audio' ? ' ' . $caption : ''));
        $id = $this->insertOutgoing($website, $conversation, $text, $sent, $sent ? (string) $r['id'] : null, [
            'media' => ['type' => $type, 'id' => (string) $up['value'], 'mime' => $mime, 'filename' => $filename],
        ]);
        return $this->success([
            'sent' => $sent, 'message_id' => (int) $id,
            'reason' => $sent ? null : (!empty($r['window']) ? 'outside_24h' : 'provider_rejected'),
        ], $sent ? 'Sent' : 'Saved but not delivered');
    }

    /**
     * Real file type from its content. Uses fileinfo when installed; otherwise reads the file signature,
     * because aaPanel PHP builds often ship without fileinfo.
     */
    private static function detectMime(string $path, string $name): string
    {
        if (class_exists('finfo')) {
            $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
            // A browser voice note (.m4a recorded by Chrome/Safari) is an MP4 container that libmagic calls video/mp4;
            // sent as such WhatsApp would show it as a video, so the extension decides: it is audio.
            if ($detected === 'video/mp4' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'm4a') {
                return 'audio/mp4';
            }
            return $detected;
        }
        $img = @getimagesize($path);
        if ($img && in_array($img[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return $img[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png';
        }
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($head, '%PDF')) return 'application/pdf';
        if (str_starts_with($head, 'OggS')) return 'audio/ogg';
        if (str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) return 'audio/mpeg';
        if (str_starts_with($head, '#!AMR')) return 'audio/amr';
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if (str_starts_with($brand, '3gp')) return 'video/3gpp';
            return in_array($brand, ['M4A ', 'M4B '], true) || $ext === 'm4a' ? 'audio/mp4' : 'video/mp4';
        }
        $office = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        $legacy = ['doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint'];
        if (str_starts_with($head, "PK\x03\x04") && isset($office[$ext])) return $office[$ext];
        if (str_starts_with($head, "\xD0\xCF\x11\xE0") && isset($legacy[$ext])) return $legacy[$ext];
        if ($ext === 'txt' && strpos((string) @file_get_contents($path, false, null, 0, 4096), "\0") === false) return 'text/plain';
        return 'application/octet-stream';
    }

    /**
     * قوالب واتساب المعتمدة اللي تنفع تتبعت من الشات (لما نافذة الـ24 ساعة تقفل).
     * GET /api/ai-chat/websites/{id}/whatsapp-templates
     */
    public function templates(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $api = WhatsAppCloudAPI::forWebsite((int) $website->getAttribute('id'));
        if (!$api) {
            return $this->error('Templates need the official WhatsApp connection (Cloud API)', 409);
        }
        $items = [];
        $after = null;
        for ($page = 0; $page < 5; $page++) {
            $r = $api->marketingTemplates($after);
            if (!$r['ok']) {
                if ($page === 0) return $this->error('Could not load templates from WhatsApp', 502);
                break;
            }
            foreach ($r['items'] as $t) {
                if (($t['status'] ?? '') !== 'APPROVED') continue;
                $items[] = self::describeTemplate($t);
            }
            $after = $r['after'];
            if (!$after) break;
        }
        usort($items, fn($a, $b) => [$b['supported'], $a['name']] <=> [$a['supported'], $b['name']]);
        return $this->success(['templates' => $items]);
    }

    /** What the inbox needs to show and fill a template; templates needing header media or URL variables are listed as unsupported. */
    private static function describeTemplate(array $t): array
    {
        $body = '';
        $header = '';
        $supported = true;
        foreach ((array) ($t['components'] ?? []) as $c) {
            $type = strtoupper((string) ($c['type'] ?? ''));
            if ($type === 'BODY') $body = (string) ($c['text'] ?? '');
            if ($type === 'HEADER') {
                if (strtoupper((string) ($c['format'] ?? 'TEXT')) !== 'TEXT' || preg_match('/\{\{[^}]+\}\}/', (string) ($c['text'] ?? ''))) $supported = false;
                else $header = (string) ($c['text'] ?? '');
            }
            if ($type === 'BUTTONS') {
                foreach ((array) ($c['buttons'] ?? []) as $b) {
                    if (preg_match('/\{\{[^}]+\}\}/', (string) ($b['url'] ?? ''))) $supported = false;
                }
            }
        }
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $mm);
        $params = array_values(array_unique($mm[1]));
        $named = strtoupper((string) ($t['parameter_format'] ?? 'POSITIONAL')) === 'NAMED';
        if (!$named) sort($params, SORT_NUMERIC); // positional values are sent in {{1}}, {{2}}... order
        return [
            'name' => (string) ($t['name'] ?? ''),
            'language' => (string) ($t['language'] ?? ''),
            'category' => (string) ($t['category'] ?? ''),
            'header' => $header,
            'body' => $body,
            'params' => $params,
            'named' => $named,
            'supported' => $supported,
        ];
    }

    /**
     * إرسال قالب واتساب معتمد للعميل (مسموح حتى بعد نافذة الـ24 ساعة).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/template
     * Body: name, language, params (object: اسم/رقم المتغير ← القيمة)
     */
    public function sendTemplate(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation || $conversation->getAttribute('channel') !== 'whatsapp') {
            return $this->error('Templates can only be sent in WhatsApp conversations', 422);
        }
        if ($conversation->getAttribute('do_not_contact')) {
            return $this->error('This customer asked not to be contacted', 422);
        }
        $name = (string) $this->get('name', '');
        $language = (string) $this->get('language', '');
        if (!preg_match('/^[a-z0-9_]{1,512}$/D', $name) || !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/D', $language)) {
            return $this->error('Choose a template', 422);
        }
        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('Templates need the official WhatsApp connection (Cloud API)', 409);
        }
        // Read the template from Meta again so the stored text matches what the customer receives.
        $tpl = null;
        $after = null;
        for ($page = 0; $page < 5 && $tpl === null; $page++) {
            $r = $api->marketingTemplates($after);
            if (!$r['ok']) break;
            foreach ($r['items'] as $t) {
                if (($t['name'] ?? '') === $name && ($t['language'] ?? '') === $language && ($t['status'] ?? '') === 'APPROVED') { $tpl = self::describeTemplate($t); break; }
            }
            $after = $r['after'];
            if (!$after) break;
        }
        if ($tpl === null || !$tpl['supported']) {
            return $this->error('This template is not approved or needs media/links the inbox cannot fill yet', 422);
        }
        $given = (array) $this->get('params', []);
        $values = [];
        $text = $tpl['body'];
        foreach ($tpl['params'] as $p) {
            $v = trim((string) ($given[$p] ?? ''));
            if ($v === '') {
                return $this->error('Fill in every template field', 422);
            }
            if ($tpl['named']) $values[$p] = $v; else $values[] = $v;
            $text = preg_replace('/\{\{\s*' . preg_quote($p, '/') . '\s*\}\}/', str_replace(['\\', '$'], ['\\\\', '\\$'], $v), $text);
        }
        $phone = (string) $conversation->getAttribute('customer_phone');
        if ($phone === '') {
            // Meta template sends need a phone number; username-only customers must message first.
            return $this->success(['sent' => false, 'reason' => 'no_recipient'], 'Not sent');
        }
        try {
            $r = $api->sendTemplate($phone, $name, $language, $values);
        } catch (InvalidArgumentException $e) {
            return $this->success(['sent' => false, 'reason' => 'no_recipient'], 'Not sent');
        }
        $sent = !empty($r['ok']);
        if (!$sent) {
            Logger::warning('WhatsApp template send from inbox failed', ['website_id' => $websiteId, 'http_code' => (int) ($r['http_code'] ?? 0), 'error_code' => $r['error_code'] ?? null]);
        }
        $full = trim(($tpl['header'] !== '' ? $tpl['header'] . "\n" : '') . $text);
        $id = $this->insertOutgoing($website, $conversation, $full, $sent, $sent ? (string) $r['id'] : null, ['template' => $name]);
        return $this->success([
            'sent' => $sent, 'message_id' => (int) $id,
            'reason' => $sent ? null : 'provider_rejected',
            'error' => $sent ? null : ($r['error'] ?? null),
        ], $sent ? 'Sent' : 'Saved but not delivered');
    }

    /**
     * منتجات كتالوج واتساب المربوط (لإرسال رحلة/خدمة من الشات).
     * GET /api/ai-chat/websites/{id}/whatsapp-catalog
     */
    public function catalogProducts(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $websiteId = (int) $website->getAttribute('id');
        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('The catalog needs the official WhatsApp connection (Cloud API)', 409);
        }
        $catalogId = $this->chosenCatalogId($websiteId, $api);
        if ($catalogId === '') {
            return $this->success(['catalog_id' => '', 'products' => [], 'error' => 'No catalog is connected to this WhatsApp number yet. Connect one from WhatsApp settings.']);
        }
        $p = $api->catalogProducts($catalogId);
        return $this->success([
            'catalog_id' => $catalogId,
            'products' => array_values(array_filter(array_map([WhatsAppCloudController::class, 'productView'], $p['items']), fn($x) => $x['retailer_id'] !== '')),
            'error' => $p['ok'] ? null : 'Products could not be listed (you can still send the whole catalog)',
        ]);
    }

    /**
     * إرسال الكتالوج كله أو منتج واحد للعميل.
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/catalog  Body: retailer_id (اختياري), text (اختياري)
     */
    public function sendCatalog(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation || $conversation->getAttribute('channel') !== 'whatsapp') {
            return $this->error('The catalog can only be sent in WhatsApp conversations', 422);
        }
        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('The catalog needs the official WhatsApp connection (Cloud API)', 409);
        }
        [$to, $reason] = $this->whatsappRecipient($conversation);
        if ($to === '') {
            return $this->success(['sent' => false, 'reason' => $reason], 'Not sent');
        }
        if ($this->windowSecondsLeft((int) $conversation->getAttribute('id'), $websiteId) <= 0) {
            return $this->success(['sent' => false, 'reason' => 'outside_24h'], 'Not sent');
        }
        $retailerId = trim((string) $this->get('retailer_id', ''));
        $name = trim((string) $this->get('name', ''));
        $text = trim((string) $this->get('text', ''));
        $catalogId = '';
        if ($retailerId !== '') {
            $catalogId = $this->chosenCatalogId($websiteId, $api);
            if ($catalogId === '') {
                return $this->error('No catalog is connected to this WhatsApp number', 422);
            }
        }
        $r = $api->sendCatalog($to, $text, $catalogId, $retailerId);
        $sent = !empty($r['ok']);
        if (!$sent) {
            Logger::warning('WhatsApp catalog send failed', ['website_id' => $websiteId, 'http_code' => (int) ($r['http_code'] ?? 0), 'error_code' => $r['error_code'] ?? null]);
        }
        $label = $retailerId !== '' ? '[Product] ' . ($name !== '' ? mb_substr($name, 0, 200) : $retailerId) : '[Catalog]';
        $id = $this->insertOutgoing($website, $conversation, trim($label . ($text !== '' ? ' — ' . $text : '')), $sent, $sent ? (string) $r['id'] : null, ['catalog' => $retailerId !== '' ? 'product' : 'catalog']);
        return $this->success([
            'sent' => $sent, 'message_id' => (int) $id,
            'reason' => $sent ? null : (!empty($r['window']) ? 'outside_24h' : 'provider_rejected'),
            'error' => $sent ? null : ($r['error'] ?? null),
        ], $sent ? 'Sent' : 'Saved but not delivered');
    }

    /**
     * حظر العميل على واتساب أو فك الحظر (المحادثة بتاخد وسم BLOCKED).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/block  Body: block (true/false)
     */
    public function block(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation || $conversation->getAttribute('channel') !== 'whatsapp') {
            return $this->error('Only WhatsApp customers can be blocked', 422);
        }
        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('Blocking needs the official WhatsApp connection (Cloud API)', 409);
        }
        [$to] = $this->whatsappRecipient($conversation);
        if ($to === '') {
            return $this->error('No WhatsApp number for this customer', 422);
        }
        $block = filter_var($this->get('block', true), FILTER_VALIDATE_BOOLEAN);
        try {
            $r = $api->blockUser($to, $block);
        } catch (InvalidArgumentException $e) {
            return $this->error('No valid WhatsApp number for this customer', 422);
        }
        if (!$r['ok']) {
            return $this->error(($block ? 'WhatsApp did not block the customer: ' : 'WhatsApp did not unblock the customer: ') . $r['error'] . ($block ? ' (WhatsApp only allows blocking someone who messaged in the last 24 hours)' : ''), 422);
        }
        $tags = json_decode((string) $conversation->getAttribute('tags'), true) ?: [];
        $tags = array_values(array_diff($tags, ['BLOCKED']));
        if ($block) $tags[] = 'BLOCKED';
        $update = ['tags' => json_encode($tags, JSON_UNESCAPED_UNICODE)];
        // A blocked customer should not get automatic replies or follow-ups.
        if ($block) $update['do_not_contact'] = 1;
        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), $update);
        return $this->success(['blocked' => $block], $block ? 'Customer blocked' : 'Customer unblocked');
    }

    /** The catalog chosen in WhatsApp settings, or the first one linked to the account. */
    private function chosenCatalogId(int $websiteId, WhatsAppCloudAPI $api): string
    {
        $row = WhatsAppCloudAPI::connectionRow($websiteId);
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        if (preg_match('/^[0-9]{1,32}$/D', (string) ($meta['catalog_id'] ?? ''))) {
            return (string) $meta['catalog_id'];
        }
        $c = $api->linkedCatalogs();
        return (string) ($c['items'][0]['id'] ?? '');
    }

    /**
     * Send quick-reply buttons (up to 3) or an option list (up to 10) to a WhatsApp customer (official API, 24-hour window).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/interactive
     * Body: kind = buttons|list, body, buttons[] | rows[] + button_label, footer?
     */
    public function sendInteractive(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation || $conversation->getAttribute('channel') !== 'whatsapp') {
            return $this->error('Buttons can only be sent in WhatsApp conversations', 422);
        }
        $spec = [
            'kind' => (string) $this->get('kind', ''),
            'body' => (string) $this->get('body', ''),
            'buttons' => (array) $this->get('buttons', []),
            'rows' => (array) $this->get('rows', []),
            'button_label' => (string) $this->get('button_label', ''),
            'footer' => (string) $this->get('footer', ''),
        ];
        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('Buttons need the official WhatsApp connection (Cloud API)', 409);
        }
        [$to, $reason] = $this->whatsappRecipient($conversation);
        if ($to === '') {
            return $this->success(['sent' => false, 'reason' => $reason], 'Not sent');
        }
        if ((int) $this->windowSecondsLeft((int) $conversation->getAttribute('id'), $websiteId) <= 0) {
            return $this->success(['sent' => false, 'reason' => 'outside_24h'], 'Not sent');
        }
        $r = $api->sendInteractive($to, $spec);
        // sendInteractive() refuses (without any network call) what breaks WhatsApp's limits.
        if (($r['error'] ?? '') === 'Invalid interactive message') {
            return $this->error('Check the message and the options: up to 3 buttons (20 characters each) or 10 list rows (24 characters each)', 422);
        }
        $sent = !empty($r['ok']);
        if (!$sent) {
            Logger::warning('WhatsApp interactive send failed', ['website_id' => $websiteId, 'http_code' => (int) ($r['http_code'] ?? 0), 'error_code' => $r['error_code'] ?? null]);
        }
        $options = $spec['kind'] === 'list'
            ? array_map(static fn ($x) => trim((string) (is_array($x) ? ($x['title'] ?? '') : $x)), $spec['rows'])
            : array_map(static fn ($x) => trim((string) (is_array($x) ? ($x['title'] ?? '') : $x)), $spec['buttons']);
        $id = $this->insertOutgoing($website, $conversation, trim($spec['body']), $sent, $sent ? (string) ($r['id'] ?? '') : null, [
            'interactive' => ['kind' => $spec['kind'], 'options' => array_values($options)],
        ]);
        return $this->success([
            'sent' => $sent, 'message_id' => (int) $id,
            'reason' => $sent ? null : (!empty($r['window']) ? 'outside_24h' : 'provider_rejected'),
        ], $sent ? 'Sent' : 'Saved but not delivered');
    }

    /**
     * The agent's own pin / archive / unread marks for this website's conversations.
     * GET /api/ai-chat/websites/{id}/conversation-prefs  ->  { ready, prefs: { "<conversation id>": { pinned, archived, unread } } }
     * ready=false (migration not applied) tells the browser to keep these choices locally.
     */
    public function prefs(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $ready = ChatConversationPrefs::ready();
        return $this->success([
            'ready' => $ready,
            'prefs' => $ready ? (object) ChatConversationPrefs::forUser((int) $this->user['id'], (int) $website->getAttribute('id')) : (object) [],
        ]);
    }

    /**
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/prefs  - body: pinned?, archived?, unread? (booleans).
     * Personal to the agent, so viewers may use it too.
     */
    public function setPrefs(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        $flags = [];
        foreach (['pinned', 'archived', 'unread'] as $k) {
            $v = $this->get($k, null);
            if ($v !== null && $v !== '') {
                $flags[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        }
        $state = ChatConversationPrefs::set((int) $this->user['id'], (int) $conversation->getAttribute('id'), $flags);
        if ($state === null) {
            return $this->success(['ready' => false], 'Saved in this browser only');
        }
        return $this->success(['ready' => true, 'prefs' => $state]);
    }

    /** GET .../conversations/{conversationId}/photo - the contact photo an agent set (small JPEG). */
    public function photo(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        $bytes = ChatContactPhotos::read($websiteId, (int) $conversation->getAttribute('id'));
        if ($bytes === null) {
            return $this->error('No photo', 404);
        }
        return $this->streamMedia($bytes, 'image/jpeg', '');
    }

    /** POST .../conversations/{conversationId}/photo - multipart "file": set or replace the contact photo. */
    public function setPhoto(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            return $this->error('Choose a picture', 422);
        }
        $err = ChatContactPhotos::save($websiteId, (int) $conversation->getAttribute('id'), (string) file_get_contents((string) $f['tmp_name']));
        if ($err !== null) {
            return $this->error($err, 422);
        }
        return $this->success(['photo_v' => ChatContactPhotos::version($websiteId, (int) $conversation->getAttribute('id'))], 'Photo saved');
    }

    /** POST .../conversations/{conversationId}/photo/remove */
    public function removePhoto(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $websiteId = (int) $website->getAttribute('id');
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), $websiteId);
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        ChatContactPhotos::remove($websiteId, (int) $conversation->getAttribute('id'));
        return $this->success(['photo_v' => 0], 'Photo removed');
    }

    /**
     * The customer message the agent is replying to (WhatsApp only): its WhatsApp id for the "context" field and a short
     * snippet kept with our outgoing row so the bubble can show the quote.
     * @return array{0:?string,1:?array}
     */
    private function quotedMessage(AiChatConversation $conversation, int $messageId): array
    {
        if ($messageId <= 0 || (string) $conversation->getAttribute('channel') !== 'whatsapp') {
            return [null, null];
        }
        $row = Database::getInstance()->query(
            'SELECT id, message_direction, message_text, platform_message_id FROM chat_messages WHERE id = ? AND conversation_id = ? AND website_id = ? LIMIT 1',
            [$messageId, (int) $conversation->getAttribute('id'), (int) $conversation->getAttribute('website_id')]
        )[0] ?? null;
        if (!$row) {
            return [null, null];
        }
        $wamid = (string) ($row['platform_message_id'] ?? '');
        $text = trim((string) ($row['message_text'] ?? ''));
        return [
            $wamid !== '' ? $wamid : null,
            ['id' => (int) $row['id'], 'text' => mb_substr($text !== '' ? $text : '📎', 0, 120), 'who' => $row['message_direction'] === 'outgoing' ? 'you' : 'customer'],
        ];
    }

    private const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', ''];

    /**
     * Emoji reaction on a customer's WhatsApp message (official API only, inside the 24-hour window). An empty emoji removes it.
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/messages/{messageId}/react
     */
    public function react(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }
        if ((string) $conversation->getAttribute('channel') !== 'whatsapp') {
            return $this->error('Reactions are only available on WhatsApp', 422);
        }
        $emoji = (string) $this->get('emoji', '');
        if (!in_array($emoji, self::REACTIONS, true)) {
            return $this->error('Unsupported reaction', 422);
        }
        $websiteId = (int) $website->getAttribute('id');
        $db = Database::getInstance();
        $row = $db->query(
            "SELECT id, platform_message_id, webhook_raw_data FROM chat_messages WHERE id = ? AND conversation_id = ? AND website_id = ? AND message_direction = 'incoming' LIMIT 1",
            [(int) ($params['messageId'] ?? 0), (int) $conversation->getAttribute('id'), $websiteId]
        )[0] ?? null;
        if (!$row || (string) ($row['platform_message_id'] ?? '') === '') {
            return $this->error('Message not found', 404);
        }
        if ((int) $this->windowSecondsLeft((int) $conversation->getAttribute('id'), $websiteId) <= 0) {
            return $this->error('The 24-hour window is closed', 422);
        }
        [$to, $reason] = $this->whatsappRecipient($conversation);
        if ($to === '') {
            return $this->error('No recipient for this conversation', 422);
        }
        $api = WhatsAppCloudAPI::forWebsite($websiteId);
        if (!$api) {
            return $this->error('The official WhatsApp number is not connected', 422);
        }
        $result = $api->sendReaction($to, (string) $row['platform_message_id'], $emoji);
        if (empty($result['ok'])) {
            Logger::error('WhatsApp reaction failed', ['website_id' => $websiteId, 'http_code' => (int) ($result['http_code'] ?? 0), 'error_code' => $result['error_code'] ?? null]);
            return $this->error('The reaction was not delivered', 502);
        }
        $raw = json_decode((string) ($row['webhook_raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        if ($emoji === '') {
            unset($raw['our_reaction']);
        } else {
            $raw['our_reaction'] = $emoji;
        }
        $db->query('UPDATE chat_messages SET webhook_raw_data = ? WHERE id = ?', [$raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null, (int) $row['id']]);
        return $this->success(['emoji' => $emoji], $emoji === '' ? 'Reaction removed' : 'Reaction sent');
    }

    /**
     * Send a text on the conversation's channel.
     * @return array{0:bool,1:?string,2:?string} [sent, failure reason, WhatsApp message ID]
     */
    private function deliverText(Website $website, AiChatConversation $conversation, string $message, ?string $replyToWamid = null): array
    {
        $websiteId = (int) $website->getAttribute('id');
        $channel = (string) $conversation->getAttribute('channel');
        $recipient = $channel === 'email'
            ? $conversation->getAttribute('customer_email')
            : $conversation->getAttribute('customer_phone');

        if ($channel === 'whatsapp' && !$recipient) {
            // New WhatsApp username users have a BSUID but no phone number.
            [$bsuid, $reason] = $this->whatsappRecipient($conversation);
            if ($bsuid === '') return [false, $reason, null];
            if ((int) $this->windowSecondsLeft((int) $conversation->getAttribute('id'), $websiteId) <= 0) return [false, 'outside_24h', null];
            $api = WhatsAppCloudAPI::forWebsite($websiteId);
            if (!$api) return [false, 'not_connected', null];
            $result = $api->sendText($bsuid, $message, $replyToWamid);
            if (!empty($result['ok'])) return [true, null, is_string($result['id'] ?? null) ? $result['id'] : null];
            $failReason = !empty($result['window']) ? 'outside_24h' : 'provider_rejected';
            Logger::error('WhatsApp manual BSUID send failed', [
                'website_id' => $websiteId,
                'http_code' => (int) ($result['http_code'] ?? 0),
                'error_code' => $result['error_code'] ?? null,
                'error_type' => $failReason,
            ]);
            return [false, $failReason, null];
        }
        if (!$recipient) {
            return [false, 'no_recipient', null];
        }
        $chatManager = new ChatManager();
        $sent = $chatManager->sendMessageForWebsite($websiteId, (string) $recipient, $message, $channel, $replyToWamid);
        return [$sent, $sent ? null : ($chatManager->lastSendError ?? 'send_failed'), $sent ? $chatManager->lastSendMessageId : null];
    }

    /**
     * WhatsApp destination for this conversation: phone number, or the BSUID of a username-only customer
     * (stored as the conversation thread "bsuid:<id>", or in the latest inbound message).
     * @return array{0:string,1:?string} [recipient or '', failure reason]
     */
    private function whatsappRecipient(AiChatConversation $conversation): array
    {
        $phone = (string) $conversation->getAttribute('customer_phone');
        if ($phone !== '') {
            try {
                return [WhatsAppCloudAPI::recipientForText($phone), null];
            } catch (InvalidArgumentException $e) {
                return ['', 'no_recipient'];
            }
        }
        $bsuid = '';
        $thread = (string) $conversation->getAttribute('channel_thread_id');
        if (str_starts_with($thread, 'bsuid:')) {
            $bsuid = substr($thread, 6);
        } else {
            $recent = Database::getInstance()->query(
                "SELECT webhook_raw_data FROM chat_messages WHERE conversation_id=? AND website_id=? AND platform='whatsapp' AND message_direction='incoming' ORDER BY id DESC LIMIT 1",
                [(int) $conversation->getAttribute('id'), (int) $conversation->getAttribute('website_id')]
            );
            $raw = json_decode((string) ($recent[0]['webhook_raw_data'] ?? ''), true);
            $bsuid = is_array($raw) ? trim((string) ($raw['wa_bsuid'] ?? '')) : '';
        }
        if (preg_match('/^[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9._-]{8,128}$/D', $bsuid) !== 1) {
            return ['', 'no_recipient'];
        }
        return [$bsuid, null];
    }

    /** Seconds left in Meta's 24-hour customer-service window (0 when closed), from the last inbound message. */
    /** Same check as MetaWebhookController::isMetaCdn, used when the inbox shows a stored attachment URL. */
    private static function isMetaCdnUrl(string $url): bool
    {
        return MetaWebhookController::isMetaCdn($url);
    }

    private function windowSecondsLeft(int $conversationId, int $websiteId): int
    {
        $r = Database::getInstance()->query(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(created_at), INTERVAL 24 HOUR)) AS s
             FROM chat_messages WHERE conversation_id = ? AND website_id = ? AND platform = 'whatsapp' AND message_direction = 'incoming'",
            [$conversationId, $websiteId]
        );
        return max(0, (int) ($r[0]['s'] ?? 0));
    }

    /** Save an outgoing message in the conversation and bump it in the inbox list. */
    private function insertOutgoing(Website $website, AiChatConversation $conversation, string $text, bool $sent, ?string $wamid, array $raw = []): int
    {
        $channel = (string) $conversation->getAttribute('channel');
        $id = Database::getInstance()->query(
            "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform, platform_message_id,
                customer_name, customer_phone, customer_email, message_direction, message_text, webhook_raw_data,
                bot_status, is_auto_pilot, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'outgoing', ?, ?, ?, 0, IF(? = 1, NOW(), NULL), NOW())",
            [
                $website->getAttribute('id'),
                $conversation->getAttribute('id'),
                $this->user['id'],
                'agent_' . $conversation->getAttribute('id'),
                $channel === 'website_chat' ? 'webchat' : $channel, // اسم القناة في chat_messages
                $wamid,
                $conversation->getAttribute('customer_name'),
                $conversation->getAttribute('customer_phone'),
                $conversation->getAttribute('customer_email'),
                $text,
                $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null,
                $sent ? 'sent' : 'failed',
                $sent ? 1 : 0,
            ]
        );
        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), [
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $id;
    }

    /**
     * تحويل المحادثة لموظف يدويًا (بند 8).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/handoff
     * Body: reason (اختياري)
     */
    public function handoff(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $reason = (string) $this->get('reason', 'manual_handoff');
        $this->inbox->handoffToHuman((int) $conversation->getAttribute('id'), $reason, (int) $this->user['id']);

        return $this->success([], 'Conversation handed off to human agent');
    }

    /**
     * إعادة تفعيل الـAI بعد التحويل اليدوي.
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/resume-ai
     */
    public function resumeAI(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $this->inbox->resumeAI((int) $conversation->getAttribute('id'));

        return $this->success([], 'AI resumed for this conversation');
    }

    /**
     * تحديث حالة/أولوية/تعيين/وسوم المحادثة يدويًا.
     * PUT /api/ai-chat/websites/{id}/conversations/{conversationId}
     * Body: status, priority, assigned_agent_id, tags (array), do_not_contact
     */
    public function update(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $updatable = ['status', 'priority', 'assigned_agent_id', 'do_not_contact'];
        $fields = array_intersect_key($this->all(), array_flip($updatable));
        // Conversations can only be assigned to the website owner or an active team member.
        if (array_key_exists('assigned_agent_id', $fields) && $fields['assigned_agent_id'] !== null && $fields['assigned_agent_id'] !== '') {
            $agentIds = array_column($this->teamAgents($website), 'id');
            if (!in_array((int) $fields['assigned_agent_id'], $agentIds, true)) {
                return $this->error('This person is not on the team', 422);
            }
            $fields['assigned_agent_id'] = (int) $fields['assigned_agent_id'];
        } elseif (array_key_exists('assigned_agent_id', $fields)) {
            $fields['assigned_agent_id'] = null;
        }

        if (($tags = $this->get('tags')) !== null && is_array($tags)) {
            $fields['tags'] = json_encode(array_values(array_unique($tags)));
        }

        if (empty($fields)) {
            return $this->error('No valid fields to update', 422);
        }

        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), $fields);

        // Learning Loop: عند إغلاق/حل المحادثة نسجّل نتيجتها (هل حلها الـAI
        // أم أحيلت لموظف؟) لتحسين معدلات الحل مستقبلًا (Zendesk/Fin).
        if (in_array((string) ($fields['status'] ?? ''), ['resolved', 'closed'], true)) {
            try {
                (new LearningLoopService())->recordResolutionForClosedConversation((int) $conversation->getAttribute('id'));
            } catch (Exception $e) {
                Logger::warning('ChatInboxController: resolution recording failed', ['error' => CustomerError::message($e->getMessage(), 500)]);
            }
        }

        return $this->success([], 'Conversation updated');
    }

    /**
     * اقتراحات ردود جاهزة للموظف (بند 12) - لا يُرسَل أي شيء تلقائيًا.
     * GET /api/ai-chat/websites/{id}/conversations/{conversationId}/reply-suggestions
     */
    public function suggestReplies(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        if ($deny = $this->denyReadOnly()) {
            return $deny;
        }
        // Usage is billed to the business account (website owner), also when a team member asks.
        $result = $this->replySuggestions->suggestFor(
            (int) $website->getAttribute('id'),
            (int) $website->getAttribute('user_id'),
            (int) $conversation->getAttribute('id')
        );

        if ($result['error']) {
            return $this->error($result['error'], 422);
        }

        return $this->success(['suggestions' => $result['suggestions']]);
    }

    /**
     * @param int $websiteId
     * @return Website|null
     */
    /** Role of the current user on the website being handled: owner, admin, member or viewer. */
    private ?string $inboxRole = null;

    /**
     * The website owner, or an active member of a business the owner runs (team inbox).
     * admin/member can reply; viewer can only read.
     */
    private function authorizedWebsite(int $websiteId): ?Website
    {
        $this->inboxRole = null;
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website) {
            return null;
        }
        $ownerId = (int) $website->getAttribute('user_id');
        if ($ownerId === (int) $this->user['id']) {
            $this->inboxRole = 'owner';
            return $website;
        }
        $role = TeamAccess::inboxRole($ownerId, (int) $this->user['id']);
        if ($role === null || $role === 'owner') {
            return null;
        }
        $this->inboxRole = $role;
        return $website;
    }

    /** Viewers can read the inbox but not send or change anything. */
    private function denyReadOnly(): ?array
    {
        return $this->inboxRole === 'viewer' ? $this->error('Your team role can view conversations but not reply or change them', 403) : null;
    }

    /** Display name for users: first + last name, else company name, else email. */
    private static function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db = Database::getInstance();
        try {
            $rows = $db->query("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS n, company_name, email FROM users WHERE id IN ($in)", $ids);
        } catch (Throwable $e) {
            $rows = $db->query("SELECT id, '' AS n, company_name, email FROM users WHERE id IN ($in)", $ids);
        }
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[(int) $r['id']] = (string) ($r['n'] !== null && $r['n'] !== '' ? $r['n'] : ($r['email'] ?: $r['company_name']));
        }
        return $out;
    }

    /** People who can handle this website's conversations: the owner and active admin/member team members. */
    private function teamAgents(Website $website): array
    {
        $ownerId = (int) $website->getAttribute('user_id');
        $ids = array_merge([$ownerId], TeamAccess::agentIds($ownerId));
        $names = self::userNames($ids);
        $out = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $out[] = ['id' => $id, 'name' => $names[$id] ?? ('#' . $id), 'owner' => $id === $ownerId];
        }
        return $out;
    }

    /**
     * المواقع اللي المستخدم يقدر يفتح واردها: مواقعه + مواقع الشركات اللي هو عضو في فريقها.
     * GET /api/ai-chat/inbox-websites
     */
    public function inboxWebsites(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $db = Database::getInstance();
        $sites = [];
        foreach ($db->query('SELECT id, company_name, main_url FROM websites WHERE user_id = ? ORDER BY id', [$uid]) ?: [] as $w) {
            $sites[(int) $w['id']] = ['id' => (int) $w['id'], 'name' => (string) ($w['company_name'] ?: $w['main_url']), 'url' => (string) $w['main_url'], 'role' => 'owner'];
        }
        foreach (TeamAccess::ownersFor($uid) as $ownerId => $role) {
            foreach ($db->query('SELECT id, company_name, main_url FROM websites WHERE user_id = ? ORDER BY id', [$ownerId]) ?: [] as $w) {
                if (!isset($sites[(int) $w['id']])) {
                    $sites[(int) $w['id']] = ['id' => (int) $w['id'], 'name' => (string) ($w['company_name'] ?: $w['main_url']), 'url' => (string) $w['main_url'], 'role' => $role];
                }
            }
        }
        return $this->success(['websites' => array_values($sites)]);
    }

    /**
     * فريق المحادثات للموقع (للتعيين).
     * GET /api/ai-chat/websites/{id}/agents
     */
    public function agents(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        return $this->success(['agents' => $this->teamAgents($website), 'my_role' => $this->inboxRole, 'me' => (int) $this->user['id']]);
    }

    /**
     * @param int $conversationId
     * @param int $websiteId
     * @return AiChatConversation|null
     */
    private function authorizedConversation(int $conversationId, int $websiteId): ?AiChatConversation
    {
        if ($conversationId <= 0) {
            return null;
        }
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation || (int) $conversation->getAttribute('website_id') !== $websiteId) {
            return null;
        }
        return $conversation;
    }

    /**
     * @param AiChatConversation $conversation
     * @param bool $detailed
     * @return array
     */
    private function serializeConversation(AiChatConversation $conversation, bool $detailed = false): array
    {
        $data = [
            'id' => $conversation->getAttribute('id'),
            'channel' => $conversation->getAttribute('channel'),
            'customer_name' => $conversation->getAttribute('customer_name'),
            'customer_phone' => $conversation->getAttribute('customer_phone'),
            'customer_email' => $conversation->getAttribute('customer_email'),
            'status' => $conversation->getAttribute('status'),
            'ai_status' => $conversation->getAttribute('ai_status'),
            'lead_status' => $conversation->getAttribute('lead_status'),
            'priority' => $conversation->getAttribute('priority'),
            'tags' => json_decode((string) $conversation->getAttribute('tags'), true) ?: [],
            'unread_count' => (int) $conversation->getAttribute('unread_count'),
            'assigned_agent_id' => $conversation->getAttribute('assigned_agent_id'),
            'last_message_at' => $conversation->getAttribute('last_message_at'),
            // Agent-set contact photo (the WhatsApp API never provides one): 0 = none, otherwise a cache-busting version.
            'photo_v' => ChatContactPhotos::version((int) $conversation->getAttribute('website_id'), (int) $conversation->getAttribute('id')),
        ];

        if ($detailed) {
            $data['ai_summary'] = $conversation->getAttribute('ai_summary');
            $data['ai_confidence_score'] = $conversation->getAttribute('ai_confidence_score');
            $data['handoff_reason'] = $conversation->getAttribute('handoff_reason');
            $data['handoff_at'] = $conversation->getAttribute('handoff_at');
            $data['language'] = $conversation->getAttribute('language');
            $data['do_not_contact'] = (bool) $conversation->getAttribute('do_not_contact');
            $data['created_at'] = $conversation->getAttribute('created_at');
            $data['next_recommended_action'] = $conversation->getAttribute('next_recommended_action');
        }

        return $data;
    }
}
