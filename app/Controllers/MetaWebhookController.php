<?php

/**
 * Tourfecto - Webhook واحد لرسائل Messenger وInstagram لكل الشركات
 *
 * قبل كده كل موقع كان محتاج رابط webhook خاص بيه يتحط يدويًا في Meta for
 * Developers - وده مستحيل على عميل عادي، وتطبيق Meta الواحد أصلًا ليه رابط
 * واحد بس. دلوقتي: العميل بيربط Meta بضغطة، الصفحة بتشترك في تطبيق المنصة،
 * والرسائل كلها بتيجي هنا ونوزعها على الشركة الصح بمعرّف الصفحة/الحساب.
 *
 *   GET  /api/meta/webhook  - تحقق Meta (hub.challenge)
 *   POST /api/meta/webhook  - الرسائل (موقّعة بـ App Secret بتاع المنصة)
 */
class MetaWebhookController extends Controller
{
    public static function verifyToken(): string
    {
        if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) {
            $key = (string) ENCRYPTION_KEY;
        } elseif (defined('SALT') && SALT) {
            $key = (string) SALT;
        } else {
            // مفيش مفتاح = توكن التحقق كان هيتشتق من نص ثابت ومعروف؛ نفشل بدل ما نشتقه.
            throw new RuntimeException('ENCRYPTION_KEY is not configured');
        }
        return 'tfm_' . substr(hash_hmac('sha256', 'meta-pages-verify', $key), 0, 24);
    }

    public function verify(array $params = []): array
    {
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        if ($mode === 'subscribe' && hash_equals(self::verifyToken(), $token)) {
            return ['_raw_text' => $challenge];
        }
        return $this->error('Verification failed', 403);
    }

    public function receive(array $params = []): array
    {
        $raw = (string) file_get_contents('php://input');
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if (!self::signatureValid($raw, $sig)) {
            MetaWebhookLog::add(['result' => 'bad_signature', 'bytes' => strlen($raw), 'has_sig' => $sig !== '']);
            return $this->error('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->error('Invalid JSON', 400);
        }
        $result = self::route($payload);
        if (!empty($result['retry'])) {
            // A message could not be saved because of an error on our side: ask Meta to deliver it again (it was un-marked below).
            return $this->error('Temporary failure, please retry', 503);
        }
        return $this->success($result);
    }

    /** The main Meta app signs its webhooks with its secret; the optional messaging-only app signs with its own. */
    public static function signatureValid(string $raw, string $sig, ?array $secrets = null): bool
    {
        $secrets = $secrets ?? [MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET'), MetaMessagingApp::secret()];
        foreach ($secrets as $secret) {
            if ($secret !== '' && WhatsAppWebhookInbox::verifiedSignature($raw, $sig, $secret)) {
                return true;
            }
        }
        return false;
    }

    /**
     * بيوزّع الرسائل على الشركات. page => Messenger (معرّف الصفحة)،
     * instagram => Instagram (معرّف حساب إنستجرام بيزنس).
     */
    public static function route(array $payload, ?object $chatManager = null): array
    {
        $platform = ['page' => 'messenger', 'instagram' => 'instagram'][(string) ($payload['object'] ?? '')] ?? null;
        $total = ['messages' => 0, 'skipped' => 0, 'unknown' => 0];
        if ($platform === null) {
            return $total;
        }
        $db = Database::getInstance();
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $accountId = (string) ($entry['id'] ?? '');
            $rows = $accountId === '' ? [] : $db->query(
                "SELECT c.website_id, w.user_id, c.access_token FROM platform_connections c JOIN websites w ON w.id = c.website_id AND w.user_id = c.user_id
                 WHERE c.platform IN (?, ?) AND c.status = 'connected' AND c.external_location_id = ? ORDER BY (c.platform = ?) DESC, c.id DESC LIMIT 1",
                // Instagram DMs: a connection made through the messaging app ("instagram_dm") wins over the publishing one.
                [$platform, $platform === 'instagram' ? 'instagram_dm' : $platform, $accountId, 'instagram_dm']
            );
            if (!$rows) {
                $total['unknown']++;
                MetaWebhookLog::add(['result' => 'no_connection', 'object' => (string) ($payload['object'] ?? ''), 'account' => $accountId]);
                continue;
            }
            $websiteId = (int) $rows[0]['website_id'];
            // Messenger and Instagram usually send entry.messaging[]; Instagram's webhooks product can send the same event as
            // entry.changes[] {field: "messages", value: {...}} - read both.
            $events = array_values((array) ($entry['messaging'] ?? []));
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') === 'messages' && is_array($change['value'] ?? null)) {
                    $events[] = $change['value'];
                }
            }
            if (!$events) {
                MetaWebhookLog::add(['result' => 'no_messaging', 'object' => $platform, 'account' => $accountId, 'keys' => implode(',', array_keys($entry))]);
            }
            foreach ($events as $event) {
                $message = (array) ($event['message'] ?? []);
                $senderId = (string) ($event['sender']['id'] ?? '');
                $text = trim((string) ($message['text'] ?? ''));
                $media = null;
                if ($text === '') {
                    [$text, $media] = self::describeAttachment($message, $event);
                }
                // A reply sent from outside the platform (Page inbox, Business Suite, Instagram app) arrives as an echo:
                // keep it in the thread and pause the AI. Echoes of our own sends are recognised and dropped.
                if (!empty($message['is_echo'])) {
                    $customer = (string) ($event['recipient']['id'] ?? '');
                    $res = $customer === '' ? 'empty' : MetaDmEcho::store($websiteId, (int) $rows[0]['user_id'], $platform, $customer, $message);
                    $total['skipped']++;
                    MetaWebhookLog::add(['result' => 'echo_' . $res, 'object' => $platform, 'account' => $accountId]);
                    continue;
                }
                // من غير نص: مفيش حاجة نرد عليها
                if ($senderId === '' || $senderId === $accountId || $text === '') {
                    $total['skipped']++;
                    MetaWebhookLog::add(['result' => 'skipped', 'object' => $platform, 'account' => $accountId, 'echo' => !empty($message['is_echo']), 'has_text' => $text !== '', 'keys' => implode(',', array_keys($event))]);
                    continue;
                }
                if (!self::isNew($db, $platform, (string) ($message['mid'] ?? ''), $websiteId)) {
                    $total['skipped']++;
                    MetaWebhookLog::add(['result' => 'duplicate', 'object' => $platform, 'account' => $accountId]);
                    continue;
                }
                try {
                    $res = ($chatManager ?? new ChatManager())->processIncomingMessage([
                        'user_id' => (int) $rows[0]['user_id'],
                        'website_id' => $websiteId,
                        'platform' => $platform,
                        'phone_number' => $senderId, // PSID/IGSID - نفس الحقل العام لمعرّف العميل
                        'message' => mb_substr($text, 0, 4000),
                    ] + ($media !== null ? ['media_ext' => $media] : []));
                    $total['messages']++;
                    $ok = !is_array($res) || ($res['success'] ?? true) !== false;
                    MetaWebhookLog::add(['result' => $ok ? 'processed' : 'not_saved', 'object' => $platform, 'account' => $accountId, 'website' => $websiteId] + ($ok ? [] : ['error' => (string) ($res['error'] ?? '')]));
                    if ($chatManager === null) {
                        self::addProfile($db, $websiteId, $platform, $senderId, (string) ($rows[0]['access_token'] ?? ''));
                    }
                } catch (Throwable $e) {
                    Logger::error('Meta webhook message failed', ['website_id' => $websiteId, 'platform' => $platform, 'message' => $e->getMessage()]);
                    MetaWebhookLog::add(['result' => 'error', 'object' => $platform, 'account' => $accountId, 'error' => $e->getMessage()]);
                    // Forget the event id so Meta's redelivery is processed instead of being ignored as a duplicate.
                    try {
                        $db->query('DELETE FROM ai_webhook_events WHERE website_id = ? AND channel = ? AND external_event_id = ?', [$websiteId, $platform, (string) ($message['mid'] ?? '')]);
                    } catch (Throwable $ignore) {
                    }
                    $total['retry'] = ($total['retry'] ?? 0) + 1;
                }
            }
        }
        return $total;
    }

    /**
     * A Messenger / Instagram message without text: a picture, voice note, video, file, sticker, location or a tap on a
     * button. It used to be dropped silently, so the conversation looked broken. Returns [text, media|null]; media keeps
     * only an https URL on Meta's own CDN (the inbox shows it from there, nothing is downloaded or stored by us).
     * @return array{0:string,1:?array}
     */
    public static function describeAttachment(array $message, array $event = []): array
    {
        $labels = ['image' => '[Image]', 'audio' => '[Voice message]', 'video' => '[Video]', 'file' => '[File]', 'sticker' => '[Sticker]',
            'story_mention' => '[Story mention]', 'share' => '[Shared post]', 'reel' => '[Reel]', 'ig_reel' => '[Reel]', 'fallback' => '[Link]'];
        foreach (array_slice((array) ($message['attachments'] ?? []), 0, 1) as $a) {
            $type = strtolower((string) ($a['type'] ?? 'file'));
            if ($type === 'location') {
                $lat = $a['payload']['coordinates']['lat'] ?? null;
                $lng = $a['payload']['coordinates']['long'] ?? null;
                if (is_numeric($lat) && is_numeric($lng)) {
                    return ['[Location] ' . $lat . ',' . $lng, null];
                }
                return ['[Location]', null];
            }
            $url = (string) ($a['payload']['url'] ?? '');
            $media = null;
            if (in_array($type, ['image', 'audio', 'video', 'file', 'sticker'], true) && self::isMetaCdn($url)) {
                $media = ['type' => $type === 'sticker' ? 'image' : $type, 'url' => $url];
            }
            return [$labels[$type] ?? '[' . preg_replace('/[^a-z_]/', '', $type) . ']', $media];
        }
        $postback = trim((string) ($event['postback']['title'] ?? ''));
        return [$postback, null];
    }

    /** Name + picture of the customer for the inbox (best effort, after the message is already saved). */
    private static function addProfile(Database $db, int $websiteId, string $platform, string $senderId, string $encryptedToken): void
    {
        try {
            $token = $encryptedToken === '' ? '' : (new Encryption())->decrypt($encryptedToken);
            if ($token === '') {
                return;
            }
            $secrets = array_values(array_filter([
                class_exists('MetaMessagingApp') ? MetaMessagingApp::secret() : '',
                MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET'),
            ]));
            MetaContactProfile::enrich($db, $websiteId, $platform, $senderId, $token, $secrets);
        } catch (Throwable $e) {
            Logger::warning('Meta profile skipped', ['error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    /** Only https URLs on Meta's own CDNs are ever kept or shown. */
    public static function isMetaCdn(string $url): bool
    {
        if ($url === '' || strlen($url) > 2000) {
            return false;
        }
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) {
            return false;
        }
        return (bool) preg_match('/(^|\.)(fbcdn\.net|cdninstagram\.com|fbsbx\.com)$/i', (string) $p['host']);
    }

    /** Meta بتعيد إرسال نفس الرسالة لو اتأخرنا - الـ UNIQUE على (channel, external_event_id) بيمنع الرد مرتين */
    private static function isNew(Database $db, string $channel, string $eventId, int $websiteId): bool
    {
        if ($eventId === '') {
            return true;
        }
        try {
            $db->query(
                "INSERT INTO ai_webhook_events (website_id, channel, external_event_id, status, received_at) VALUES (?, ?, ?, 'received', NOW())",
                [$websiteId, $channel, $eventId]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
