<?php

/**
 * شات الموقع (webchat): فقاعة شات على موقع العميل بيرد عليها نفس محرك الواتساب
 * (ChatManager) وبتظهر في نفس صندوق الرسائل. الزائر مالوش حساب: بنميّزه بـvisitor id
 * عشوائي (128 بت) متخزن في متصفحه، والموقع بتوكن HMAC (زي ويدجت التقييمات).
 * الردود (بوت أو موظف) بتوصل للزائر بالـpolling - مفيش إرسال خارجي.
 */
class WebChatService
{
    private const MAX_LEN = 1000;
    private const SITE_DAILY_CAP = 400;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function token(int $websiteId): string
    {
        $key = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? (string) ENCRYPTION_KEY : '';
        return $key === '' || $websiteId <= 0 ? '' : 'wc_' . substr(hash_hmac('sha256', 'webchat:' . $websiteId, $key), 0, 20);
    }

    public static function validToken(int $websiteId, string $token): bool
    {
        $expected = self::token($websiteId);
        return $expected !== '' && hash_equals($expected, $token);
    }

    public static function snippet(int $websiteId): string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        return '<script src="' . $base . '/widget/chat?site=' . $websiteId . '&t=' . self::token($websiteId) . '" async></script>';
    }

    public static function validVisitor(string $v): bool
    {
        return (bool) preg_match('/^[a-z0-9]{20,32}$/', $v);
    }

    /** @return array{id:int,user_id:int,name:string}|null */
    public function site(int $websiteId): ?array
    {
        $r = $this->db->query('SELECT id, user_id, ' . WebsiteSchema::nameExpr() . ' AS name FROM websites WHERE id = ?' . WebsiteSchema::alive() . ' LIMIT 1', [$websiteId]);
        if (!$r) return null;
        return ['id' => (int) $r[0]['id'], 'user_id' => (int) $r[0]['user_id'], 'name' => (string) $r[0]['name']];
    }

    /** صاحب الموقع قفل الشات؟ (صف bot_settings بمنصة webchat ومقفول - getBotSettings بتتجاهل المقفول فمش بيأثر على باقي القنوات) */
    public function isSwitchedOff(int $websiteId): bool
    {
        return (bool) $this->db->query("SELECT id FROM bot_settings WHERE website_id = ? AND platform = 'webchat' AND is_enabled = 0 LIMIT 1", [$websiteId]);
    }

    public function setEnabled(int $userId, int $websiteId, bool $on): void
    {
        if ($on) {
            $this->db->query("DELETE FROM bot_settings WHERE website_id = ? AND user_id = ? AND platform = 'webchat' AND is_enabled = 0", [$websiteId, $userId]);
        } elseif (!$this->isSwitchedOff($websiteId)) {
            // الأعمدة الموجودة فعلًا بس (جدول الإنتاج ممكن يختلف)
            $have = [];
            foreach ((array) $this->db->query('SHOW COLUMNS FROM `bot_settings`') as $c) $have[strtolower((string) $c['Field'])] = true;
            $row = array_intersect_key(['user_id' => $userId, 'website_id' => $websiteId, 'platform' => 'webchat', 'is_enabled' => 0, 'auto_pilot' => 0, 'requires_approval' => 1, 'created_at' => date('Y-m-d H:i:s')], $have);
            $this->db->query('INSERT INTO bot_settings (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
        }
    }

    /** الشات بيشتغل بس لو صاحب الموقع عنده باقة المنصة (أو رصيد محفظة) ومقفلوش */
    public function isLive(int $websiteId): bool
    {
        $site = $this->site($websiteId);
        if (!$site || $this->isSwitchedOff($websiteId)) return false;
        try {
            if ((new SubscriptionValidator())->validateSubscription($site['user_id'])['valid'] ?? false) return true;
            return (bool) ((new WalletService())->canAffordUsage($site['user_id'], 'chat_message')['can_afford'] ?? false);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** الموقع المبني عندنا ← صف websites بتاع نفس العميل (اللي رابطه بيشاور على الموقع ده، وإلا أول موقع ليه) */
    public function websiteIdForGenerated(int $userId, string $slug, string $customDomain = ''): int
    {
        $rows = $this->db->query('SELECT id, `' . WebsiteSchema::url() . '` AS main_url, ' . WebsiteSchema::col('domain', 'domain') . ' FROM websites WHERE user_id = ?' . WebsiteSchema::alive() . ' ORDER BY id ASC', [$userId]);
        if (!$rows) return 0;
        $customDomain = preg_replace('/^www\./', '', strtolower(trim($customDomain)));
        foreach ($rows as $r) {
            $u = strtolower((string) ($r['main_url'] ?: $r['domain']));
            if (str_contains($u, '/sites/' . strtolower($slug)) || ($customDomain !== '' && str_contains($u, $customDomain))) return (int) $r['id'];
        }
        return (int) $rows[0]['id'];
    }

    /** @return array{success:bool, error?:string} */
    public function send(int $websiteId, string $visitor, string $text, string $lang, string $page): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') return ['success' => false, 'error' => 'empty'];
        $text = mb_substr($text, 0, self::MAX_LEN);
        $site = $this->site($websiteId);
        if (!$site || !$this->isLive($websiteId)) return ['success' => false, 'error' => 'offline'];

        $today = $this->db->query("SELECT COUNT(*) c FROM chat_messages WHERE website_id = ? AND platform = 'webchat' AND message_direction = 'incoming' AND created_at >= CURDATE()", [$websiteId]);
        if ((int) ($today[0]['c'] ?? 0) >= self::SITE_DAILY_CAP) return ['success' => false, 'error' => 'busy'];

        $r = (new ChatManager())->processIncomingMessage([
            'message' => $text,
            'phone_number' => 'web:' . $visitor,
            'platform' => 'webchat',
            'user_id' => $site['user_id'],
            'website_id' => $websiteId,
            'sender_name' => null,
            'language' => preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en',
            'page_url' => mb_substr($page, 0, 300),
        ]);
        if (empty($r['success'])) {
            Logger::warning('Webchat message not processed', ['website_id' => $websiteId, 'error' => $r['error'] ?? '']);
            return ['success' => false, 'error' => 'offline'];
        }
        return ['success' => true];
    }

    /**
     * محادثة الزائر: رسايله + ردود البوت اللي اتبعتت فعلًا + ردود الموظف من صندوق الرسائل.
     * الرد المستني موافقة مش بيظهر لحد ما يتوافق عليه.
     * @return list<array{id:string, from:string, text:string, at:string}>
     */
    public function transcript(int $websiteId, string $visitor): array
    {
        $thread = 'web:' . $visitor;
        $conv = $this->db->query("SELECT id FROM ai_conversations WHERE website_id = ? AND channel = 'website_chat' AND channel_thread_id = ? ORDER BY id DESC LIMIT 1", [$websiteId, $thread]);
        $convId = (int) ($conv[0]['id'] ?? 0);
        $rows = $this->db->query(
            "SELECT id, message_direction, message_text, ai_reply_generated, bot_status, created_at, sent_at FROM chat_messages
             WHERE website_id = ? AND (customer_phone = ?" . ($convId ? ' OR (conversation_id = ? AND message_direction = \'outgoing\')' : '') . ")
             ORDER BY id DESC LIMIT 60",
            $convId ? [$websiteId, $thread, $convId] : [$websiteId, $thread]
        );
        $out = [];
        foreach (array_reverse($rows) as $m) {
            if ($m['message_direction'] === 'incoming') {
                $out[] = ['id' => 'i' . $m['id'], 'from' => 'me', 'text' => (string) $m['message_text'], 'at' => (string) $m['created_at']];
                if ($m['bot_status'] === 'sent' && trim((string) $m['ai_reply_generated']) !== '') {
                    $out[] = ['id' => 'b' . $m['id'], 'from' => 'bot', 'text' => (string) $m['ai_reply_generated'], 'at' => (string) ($m['sent_at'] ?: $m['created_at'])];
                }
            } else {
                $out[] = ['id' => 'a' . $m['id'], 'from' => 'agent', 'text' => (string) $m['message_text'], 'at' => (string) $m['created_at']];
            }
        }
        return $out;
    }
}
