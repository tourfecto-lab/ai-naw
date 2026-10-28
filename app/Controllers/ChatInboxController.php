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

        $conversations = $this->inbox->search((int) $website->getAttribute('id'), $filters, $limit, $offset);

        return $this->success([
            'conversations' => array_map([$this, 'serializeConversation'], $conversations),
            'page' => $page,
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
        // Newest 200 messages, shown oldest-first (ASC + LIMIT used to hide new messages in long conversations).
        $rows = $db->query(
            "SELECT id, user_id, message_direction, message_text, ai_reply_generated, ai_confidence_score,
                    bot_status, is_auto_pilot, sent_at, created_at, platform_message_id, webhook_raw_data
             FROM chat_messages WHERE conversation_id = ? AND website_id = ? ORDER BY id DESC LIMIT 200",
            [$conversation->getAttribute('id'), $websiteId]
        );
        $rows = array_reverse($rows ?: []);

        $wamids = [];
        foreach ($rows as &$m) {
            $raw = json_decode((string) ($m['webhook_raw_data'] ?? ''), true);
            unset($m['webhook_raw_data']); // never send raw provider data to the browser
            $m['media'] = null;
            if (is_array($raw) && isset($raw['media']['id'], $raw['media']['type'])) {
                $m['media'] = [
                    'type' => (string) $raw['media']['type'],
                    'mime' => (string) ($raw['media']['mime'] ?? ''),
                    'filename' => (string) ($raw['media']['filename'] ?? ''),
                    'voice' => !empty($raw['media']['voice']),
                ];
            }
            $m['template'] = is_array($raw) && !empty($raw['template']) ? (string) $raw['template'] : null;
            $m['reply_wamid'] = is_array($raw) && is_string($raw['reply_wamid'] ?? null) ? $raw['reply_wamid'] : null;
            if ($m['message_direction'] === 'outgoing' && !empty($m['platform_message_id'])) $wamids[] = (string) $m['platform_message_id'];
            if ($m['reply_wamid'] !== null) $wamids[] = $m['reply_wamid'];
        }
        unset($m);

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
        foreach ($rows as &$m) {
            $own = $m['message_direction'] === 'outgoing' ? (string) ($m['platform_message_id'] ?? '') : '';
            $m['delivery'] = $own !== '' ? ($statuses[$own]['status'] ?? 'accepted') : null;
            $m['delivery_error'] = $own !== '' ? ($statuses[$own]['error'] ?? null) : null;
            $m['reply_delivery'] = $m['reply_wamid'] !== null ? ($statuses[$m['reply_wamid']]['status'] ?? 'accepted') : null;
            $m['by_me'] = $m['message_direction'] === 'outgoing' && (int) $m['user_id'] === (int) $this->user['id'];
            unset($m['reply_wamid'], $m['platform_message_id'], $m['user_id']);
        }
        unset($m);

        // إعادة تفعيل عدّاد غير المقروء عند فتح الموظف للمحادثة
        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), ['unread_count' => 0]);

        $data = $this->serializeConversation($conversation, true);
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

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $message = trim((string) $this->get('message', ''));
        if ($message === '') {
            return $this->error('message is required', 422);
        }

        [$sent, $failReason, $wamid] = $this->deliverText($website, $conversation, $message);
        $agentMessageId = $this->insertOutgoing($website, $conversation, $message, $sent, $wamid);

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
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
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
        if (is_array($raw) && (!empty($raw['media']) || !empty($raw['template']))) {
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
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
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
        $mime = class_exists('finfo') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']) : (string) mime_content_type((string) $f['tmp_name']);
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
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
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
     * Send a text on the conversation's channel.
     * @return array{0:bool,1:?string,2:?string} [sent, failure reason, WhatsApp message ID]
     */
    private function deliverText(Website $website, AiChatConversation $conversation, string $message): array
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
            $result = $api->sendText($bsuid, $message);
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
        $sent = $chatManager->sendMessageForWebsite($websiteId, (string) $recipient, $message, $channel);
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

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $updatable = ['status', 'priority', 'assigned_agent_id', 'do_not_contact'];
        $fields = array_intersect_key($this->all(), array_flip($updatable));

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

        $result = $this->replySuggestions->suggestFor(
            (int) $website->getAttribute('id'),
            (int) $this->user['id'],
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
    private function authorizedWebsite(int $websiteId): ?Website
    {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
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
