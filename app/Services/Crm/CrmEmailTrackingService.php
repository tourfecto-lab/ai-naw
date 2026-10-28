<?php

/**
 * Tourfecto - CRM Email Open Tracking Service (المرحلة 14 - G8)
 * @version 1.0.0
 *
 * سد فجوة G8: "تتبع فتح البريد" (Email Open Tracking) - إضافة بكسل
 * تتبع 1x1 في HTML الإيميل الصادر. عندما يفتح المستلم الإيميل (HTML),
 * يطلب عميل البريد صورة البكسل من `/api/crm/email-track/{token}.gif`
 * فنُسجّل الفتح (التاريخ الأول/الأخير + عدد الفتحات + IP + المتصفح).
 *
 * Additive خالص:
 *   - جدول جديد crm_email_trackings فقط.
 *   - لا يعدّل `Mailer::send()` ولا `CrmEmailService::sendToContact()`.
 *   - يُستدعى عند الإرسال: `CrmEmailTrackingService::create()` تُنشئ سجلًا
 *     وتُعيد وسم البكسل ليُلحق بالـHTML قبل الإرسال، ثم `recordOpen()`
 *     تستقبل الطلب من عميل البريد.
 */
class CrmEmailTrackingService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** ينشئ سجل تتبع ويعيد وسم الصورة (يُلحق بالـHTML قبل الإرسال). */
    public function create(int $userId, int $contactId, string $subject, ?int $messageId = null): array
    {
        $token = bin2hex(random_bytes(16));

        $tracking = new CrmEmailTracking([
            'user_id' => $userId,
            'contact_id' => $contactId,
            'message_id' => $messageId,
            'token' => $token,
            'email_subject' => $subject,
            'open_count' => 0,
        ]);
        $tracking->save();

        return [
            'token' => $token,
            'pixel_html' => $this->pixelHtml($token),
        ];
    }

    /** وسم HTML للبكسل (1x1 شفاف) - يُلحق بنهاية جسم الإيميل. */
    public function pixelHtml(string $token): string
    {
        return '<img src="/api/crm/email-track/' . rawurlencode($token) . '.gif" '
            . 'width="1" height="1" alt="" style="display:none;" />';
    }

    /** يُدمج البكسل في نهاية HTML إذا لم يكن موجودًا من قبل. */
    public function appendPixel(string $htmlBody, int $userId, int $contactId, string $subject, ?int $messageId = null): array
    {
        $track = $this->create($userId, $contactId, $subject, $messageId);
        $hasPixel = strpos($htmlBody, 'crm-email-track') !== false;
        $body = $hasPixel ? $htmlBody : $htmlBody . "\n" . $track['pixel_html'];
        return [
            'token' => $track['token'],
            'pixel_html' => $track['pixel_html'],
            'html_body' => $body,
        ];
    }

    /**
     * يسجّل فتح البريد (يستدعيه عميل البريد عبر GET البكسل).
     * يرجع bool دائمًا - لا يرمي استثناءات حتى لا يفسد استجابة الصورة.
     */
    public function recordOpen(string $token): bool
    {
        $tracking = (new CrmEmailTracking())->findByToken($token);
        if (!$tracking) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $firstOpened = $tracking->getAttribute('first_opened_at');
        $openCount = (int) $tracking->getAttribute('open_count') + 1;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $tracking->setAttribute('open_count', $openCount);
        if ($firstOpened === null) {
            $tracking->setAttribute('first_opened_at', $now);
        }
        $tracking->setAttribute('last_opened_at', $now);
        if ($ip !== null) {
            $tracking->setAttribute('ip_address', $ip);
        }
        if ($ua !== null) {
            $tracking->setAttribute('user_agent', $ua);
        }
        $tracking->save();
        return true;
    }

    /** إحصاءات الفتح لحساب (اختياريًا لجهة اتصال واحدة). */
    public function stats(int $userId, ?int $contactId = null): array
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN first_opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN first_opened_at IS NULL THEN 1 ELSE 0 END) AS not_opened,
                    COALESCE(SUM(open_count), 0) AS total_opens
                FROM crm_email_trackings WHERE user_id = ?";
        $params = [$userId];
        if ($contactId !== null) {
            $sql .= " AND contact_id = ?";
            $params[] = $contactId;
        }
        $rows = $this->db->query($sql, $params);
        $row = $rows[0] ?? [];
        $total = (int) ($row['total'] ?? 0);
        $opened = (int) ($row['opened'] ?? 0);

        return [
            'total_tracked' => $total,
            'opened' => $opened,
            'not_opened' => (int) ($row['not_opened'] ?? 0),
            'total_opens' => (int) ($row['total_opens'] ?? 0),
            'open_rate' => $total > 0 ? round(($opened / $total) * 100, 1) : null,
        ];
    }

    /** أحدث الإيميلات المُتبَّعة (مع اسم جهة الاتصال). */
    public function recent(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->query(
            "SELECT t.*, c.name AS contact_name, c.email AS contact_email
             FROM crm_email_trackings t
             LEFT JOIN crm_contacts c ON c.id = t.contact_id
             WHERE t.user_id = ?
             ORDER BY t.created_at DESC LIMIT " . (int) $limit,
            [$userId]
        );
    }
}
