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
        $key = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? (string) ENCRYPTION_KEY : (defined('SALT') ? (string) SALT : 'tourfecto');
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
        $secret = MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET');
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if (!WhatsAppWebhookInbox::verifiedSignature($raw, $sig, $secret)) {
            return $this->error('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->error('Invalid JSON', 400);
        }
        return $this->success(self::route($payload));
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
                "SELECT c.website_id, w.user_id FROM platform_connections c JOIN websites w ON w.id = c.website_id AND w.user_id = c.user_id
                 WHERE c.platform = ? AND c.status = 'connected' AND c.external_location_id = ? ORDER BY c.id DESC LIMIT 1",
                [$platform, $accountId]
            );
            if (!$rows) {
                $total['unknown']++;
                continue;
            }
            $websiteId = (int) $rows[0]['website_id'];
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $message = (array) ($event['message'] ?? []);
                $senderId = (string) ($event['sender']['id'] ?? '');
                $text = trim((string) ($message['text'] ?? ''));
                // رسايلنا إحنا (echo) أو من غير نص: مفيش حاجة نرد عليها
                if (!empty($message['is_echo']) || $senderId === '' || $senderId === $accountId || $text === '') {
                    $total['skipped']++;
                    continue;
                }
                if (!self::isNew($db, $platform, (string) ($message['mid'] ?? ''), $websiteId)) {
                    $total['skipped']++;
                    continue;
                }
                try {
                    ($chatManager ?? new ChatManager())->processIncomingMessage([
                        'user_id' => (int) $rows[0]['user_id'],
                        'website_id' => $websiteId,
                        'platform' => $platform,
                        'phone_number' => $senderId, // PSID/IGSID - نفس الحقل العام لمعرّف العميل
                        'message' => mb_substr($text, 0, 4000),
                    ]);
                    $total['messages']++;
                } catch (Throwable $e) {
                    Logger::error('Meta webhook message failed', ['website_id' => $websiteId, 'platform' => $platform, 'message' => $e->getMessage()]);
                }
            }
        }
        return $total;
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
