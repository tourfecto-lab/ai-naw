<?php

/**
 * Tourfecto - Email Marketing Tracking Service
 * @version 1.0.0
 *
 * يسجّل تفاعلات الحملة (فتح/كليك/إلغاء اشتراك) ويحدّث عدادات الحملة
 * والإحصاءات الفعلية جوه email_campaign_recipients + email_campaigns.
 *
 * يستقبل الطلبات العامة من عملاء البريد (من غير AuthMiddleware):
 *   - فتح:  GET /api/email-marketing/track/open/{open_token}.gif
 *   - كليك: GET /api/email-marketing/track/click/{click_token}?u=<base64url>
 *   - إلغاء:GET /api/email-marketing/unsubscribe/{unsubscribe_token}
 *
 * كل الدوال هنا ترجع قيم safe ولا ترمي استثناءات على مسارات التتبع حتى
 * لا نفسد استجابة البكسل/التحويل لعملاء البريد.
 */
class EmailTrackingService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * تسجيل فتح رسالة معاملات (transactional). يرفع open_count على السجل.
     */
    public function recordTransactionalOpen(string $openToken): bool
    {
        $rows = $this->db->query(
            "SELECT id, open_count FROM email_transactional_logs WHERE open_token = ? LIMIT 1",
            [$openToken]
        );
        if (empty($rows)) {
            return false;
        }
        $this->db->query(
            "UPDATE email_transactional_logs
             SET open_count = open_count + 1,
                 opened_at = COALESCE(opened_at, NOW())
             WHERE id = ?",
            [(int) $rows[0]['id']]
        );
        return true;
    }

    /**
     * تسجيل كليك في رسالة معاملات. يعيد الوجهة الأصلية.
     * @return string|null الـ URL الأصلي أو null لو التوكن غير صالح
     */
    public function recordTransactionalClick(string $clickToken, ?string $encodedUrl): ?string
    {
        $rows = $this->db->query(
            "SELECT id, click_count FROM email_transactional_logs WHERE click_token = ? LIMIT 1",
            [$clickToken]
        );
        if (empty($rows)) {
            return null;
        }
        $url = $this->decodeUrl($encodedUrl);
        if ($url === null) {
            return null;
        }
        $this->db->query(
            "UPDATE email_transactional_logs
             SET click_count = click_count + 1,
                 clicked_at = COALESCE(clicked_at, NOW())
             WHERE id = ?",
            [(int) $rows[0]['id']]
        );
        return $url;
    }

    /**
     * تسجيل فتح بريد أتمتة (G3). يرفع open_count على سجل email_automation_logs.
     */
    public function recordAutomationOpen(string $openToken): bool
    {
        $rows = $this->db->query(
            "SELECT id, user_id, subscriber_id, open_count FROM email_automation_logs WHERE open_token = ? LIMIT 1",
            [$openToken]
        );
        if (empty($rows)) {
            return false;
        }
        $this->db->query(
            "UPDATE email_automation_logs
             SET open_count = open_count + 1,
                 opened_at = COALESCE(opened_at, NOW())
             WHERE id = ?",
            [(int) $rows[0]['id']]
        );
        $this->bumpEngagement((int) $rows[0]['user_id'], (int) ($rows[0]['subscriber_id'] ?? 0));
        return true;
    }

    /**
     * تسجيل كليك في بريد أتمتة (G3). يعيد الوجهة الأصلية.
     * @return string|null الـ URL الأصلي أو null لو التوكن غير صالح
     */
    public function recordAutomationClick(string $clickToken, ?string $encodedUrl): ?string
    {
        $rows = $this->db->query(
            "SELECT id, user_id, subscriber_id, click_count FROM email_automation_logs WHERE click_token = ? LIMIT 1",
            [$clickToken]
        );
        if (empty($rows)) {
            return null;
        }
        $url = $this->decodeUrl($encodedUrl);
        if ($url === null) {
            return null;
        }
        $this->db->query(
            "UPDATE email_automation_logs
             SET click_count = click_count + 1,
                 clicked_at = COALESCE(clicked_at, NOW())
             WHERE id = ?",
            [(int) $rows[0]['id']]
        );
        $this->bumpEngagement((int) $rows[0]['user_id'], (int) ($rows[0]['subscriber_id'] ?? 0));
        return $url;
    }

    /**
     * إعادة حساب درجة تفاعل المشترك بعد حدث فتح/كليك (G9).
     */
    private function bumpEngagement(int $userId, int $subscriberId): void
    {
        if ($subscriberId <= 0 || $userId <= 0) {
            return;
        }
        try {
            (new ContactManagementService())->recomputeEngagementScore($userId, $subscriberId);
        } catch (\Throwable $e) {
            // لا نفشل التتبع بسبب فشل تحديث درجة التفاعل
        }
    }

    /** مالك الحملة (user_id) لاستخدامه في تحديث درجة التفاعل. */
    private function campaignOwnerId(int $campaignId): int
    {
        if ($campaignId <= 0) {
            return 0;
        }
        $rows = $this->db->query(
            "SELECT user_id FROM email_campaigns WHERE id = ? LIMIT 1",
            [$campaignId]
        );
        return (int) ($rows[0]['user_id'] ?? 0);
    }

    /**
     * تسجيل فتح البريد. يرفع open_count على المستلم ويحدّث عدّاد الحملة.
     */
    public function recordOpen(string $openToken, ?string $userAgent = null, ?string $ip = null): bool
    {
        $rows = $this->db->query(
            "SELECT r.id, r.campaign_id, r.subscriber_id, r.open_count
             FROM email_campaign_recipients r WHERE r.open_token = ? LIMIT 1",
            [$openToken]
        );
        if (empty($rows)) {
            return false;
        }
        $recipient = $rows[0];
        $id = (int) $recipient['id'];
        $campaignId = (int) $recipient['campaign_id'];
        $subscriberId = (int) ($recipient['subscriber_id'] ?? 0);

        $this->db->query(
            "UPDATE email_campaign_recipients
             SET open_count = open_count + 1,
                 status = CASE WHEN status = 'sent' THEN 'opened' ELSE status END,
                 opened_at = COALESCE(opened_at, NOW())
             WHERE id = ?",
            [$id]
        );
        // ضمان الحالة 'opened' لو كانت pending (تتبع بعد الإرسال مباشرة)
        $this->db->query(
            "UPDATE email_campaign_recipients SET status = 'opened' WHERE id = ? AND status IN ('sent','pending')",
            [$id]
        );
        $this->recomputeCampaignCounts($campaignId);

        // فتح آلي (Apple MPP / فلتر أمان) ما يتحسبش تفاعل وما يشغّلش أتمتة "عند الفتح"
        $openType = class_exists('EmailEngagementService')
            ? (new EmailEngagementService())->recordCampaignOpen($id, $userAgent, $ip)
            : 'human';
        if ($openType !== 'human') {
            return true;
        }
        $this->bumpEngagement($this->campaignOwnerId($campaignId), $subscriberId);
        if (class_exists('EmailEngagementService')) {
            (new EmailEngagementService())->refreshOptimalHour($subscriberId);
        }

        // خطاف الأتمتة: "عند فتح حملة"
        if ($subscriberId > 0 && class_exists('EmailAutomationService')) {
            $userId = (int) $this->db->query("SELECT user_id FROM email_campaigns WHERE id = ? LIMIT 1", [$campaignId])[0]['user_id'] ?? 0;
            if ($userId > 0) {
                try {
                    (new EmailAutomationService())->handleEvent($userId, 'campaign_opened', [
                        'subscriber_id' => $subscriberId,
                        'campaign_id' => $campaignId,
                    ]);
                } catch (\Throwable $e) {
                    // لا نفشل التتبع بسبب الأتمتة
                }
            }
        }
        return true;
    }

    /**
     * تسجيل كليك. يعيد الوجهة الأصلية (فك base64url) ليعيد توجيه المتصفح.
     * @return string|null الـ URL الأصلي أو null لو التوكن غير صالح
     */
    public function recordClick(string $clickToken, ?string $encodedUrl): ?string
    {
        $rows = $this->db->query(
            "SELECT r.id, r.campaign_id, r.subscriber_id, r.click_count
             FROM email_campaign_recipients r WHERE r.click_token = ? LIMIT 1",
            [$clickToken]
        );
        if (empty($rows)) {
            return null;
        }
        $recipient = $rows[0];
        $id = (int) $recipient['id'];
        $campaignId = (int) $recipient['campaign_id'];
        $subscriberId = (int) ($recipient['subscriber_id'] ?? 0);

        $url = $this->decodeUrl($encodedUrl);
        if ($url === null) {
            return null;
        }

        $this->db->query(
            "UPDATE email_campaign_recipients
             SET click_count = click_count + 1,
                 status = CASE WHEN status IN ('sent','opened') THEN 'clicked' ELSE status END,
                 clicked_at = COALESCE(clicked_at, NOW())
             WHERE id = ?",
            [$id]
        );
        $this->db->query(
            "UPDATE email_campaign_recipients SET status = 'clicked' WHERE id = ? AND status IN ('sent','opened','pending')",
            [$id]
        );
        $this->recomputeCampaignCounts($campaignId);
        $this->bumpEngagement($this->campaignOwnerId($campaignId), $subscriberId);
        if (class_exists('EmailEngagementService')) {
            (new EmailEngagementService())->refreshOptimalHour($subscriberId);
        }

        // خطاف الأتمتة: "عند النقر في حملة"
        if ($subscriberId > 0 && class_exists('EmailAutomationService')) {
            $rows2 = $this->db->query("SELECT user_id FROM email_campaigns WHERE id = ? LIMIT 1", [$campaignId]);
            $userId = (int) ($rows2[0]['user_id'] ?? 0);
            if ($userId > 0) {
                try {
                    (new EmailAutomationService())->handleEvent($userId, 'campaign_clicked', [
                        'subscriber_id' => $subscriberId,
                        'campaign_id' => $campaignId,
                    ]);
                } catch (\Throwable $e) {
                    // لا نفشل التتبع بسبب الأتمتة
                }
            }
        }
        return $url;
    }

    /**
     * إلغاء اشتراك عبر توكن المشترك. يسجّل إلغاء على أي حملات حديثة له
     * ويعيد فك الاشتراك في جدول المشتركين (suppression شاملة).
     * @return bool هل تم الإلغاء بنجاح؟
     */
    public function unsubscribe(string $unsubscribeToken): bool
    {
        $subs = $this->db->query(
            "SELECT id FROM email_subscribers WHERE unsubscribe_token = ? LIMIT 1",
            [$unsubscribeToken]
        );
        if (empty($subs)) {
            return false;
        }
        $subscriberId = (int) $subs[0]['id'];

        // تحديث المشترك نفسه
        $this->db->query(
            "UPDATE email_subscribers
             SET status = 'unsubscribed', unsubscribed_at = NOW()
             WHERE id = ?",
            [$subscriberId]
        );

        // تحديث أي مستلمين pending/sent لنفس المشترك في حملات حديثة (30 يوم)
        $this->db->query(
            "UPDATE email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id
             SET r.status = 'unsubscribed'
             WHERE r.subscriber_id = ? 
               AND r.status IN ('pending','failed','sent','opened','clicked')",
            [$subscriberId]
        );

        // إعادة حساب العدادات للحملات المتأثرة
        $campaigns = $this->db->query(
            "SELECT DISTINCT c.id FROM email_campaigns c
             JOIN email_campaign_recipients r ON r.campaign_id = c.id
             WHERE r.subscriber_id = ? ",
            [$subscriberId]
        );
        foreach ($campaigns as $c) {
            $this->recomputeCampaignCounts((int) $c['id']);
        }

        return true;
    }

    /** إعادة حساب كل العدادات الجوهرية لحملة من جدول المستلمين. */
    public function recomputeCampaignCounts(int $campaignId): void
    {
        $this->db->query(
            "UPDATE email_campaigns c
             SET c.sent_count = (SELECT COUNT(*) FROM email_campaign_recipients r
                                 WHERE r.campaign_id = c.id AND r.sent_at IS NOT NULL),
                 c.opened_count = (SELECT COUNT(*) FROM email_campaign_recipients r
                                   WHERE r.campaign_id = c.id AND r.opened_at IS NOT NULL),
                 c.clicked_count = (SELECT COUNT(*) FROM email_campaign_recipients r
                                    WHERE r.campaign_id = c.id AND r.clicked_at IS NOT NULL),
                 c.unsubscribed_count = (SELECT COUNT(*) FROM email_campaign_recipients r
                                         WHERE r.campaign_id = c.id AND r.status = 'unsubscribed'),
                 c.bounced_count = (SELECT COUNT(*) FROM email_campaign_recipients r
                                    WHERE r.campaign_id = c.id AND r.status = 'bounced')
             WHERE c.id = ?",
            [$campaignId]
        );
    }

    /**
     * فك تشفير base64url للوجهة الأصلية. يعيد null لو الرابط مش http(s)
     * (حماية من open redirect - لا يسمح بتحويلات لبروتوكولات أخرى).
     */
    private function decodeUrl(?string $encodedUrl): ?string
    {
        if ($encodedUrl === null || $encodedUrl === '') {
            return null;
        }
        $decoded = base64_decode(strtr($encodedUrl, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $url = $decoded;
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            return null;
        }
        return $url;
    }
}
