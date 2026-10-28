<?php

/**
 * Tourfecto - SEO Scheduler Service (جدولة إعادة التدقيق والفهرسة)
 * @version 1.0.0
 *
 * بيكمّل "التنفيذ التلقائي" بجدولة دورية من غير تدخل يدوي:
 * - إعادة فهرسة (IndexNow): كل فترة بنبعت الصفحة الرئيسية + sitemap لمحركات
 *   البحث عشان أي تعديل جديد يتفهرس من غير ما العميل يضغط أي زرار.
 * - إعادة تدقيق: حسب التكرار المختار لكل موقع (daily/weekly/monthly) بنعيد
 *   فحص الموقع وبنطبّق الإصلاحات الجديدة تلقائيًا.
 *
 * السكربت cron/auto_seo_scheduler.php بينادي الدوال دي، والجزء الثقيل
 * (إعادة التدقيق) بيتحط في طابور المهام العادي مش في نفس الـ request.
 */
class SeoSchedulerService
{
    /** @var Database */
    private $db;

    /** عدد أيام انتظار بين كل إعادة فهرسة (IndexNow) لنفس الموقع */
    private const REINDEX_INTERVAL_DAYS = 1;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * المواقع المستحقة لإعادة الفهرسة (IndexNow مفعّل + مفتاح موجود + مرّ وقت كافٍ).
     * @return array قائمة صفوف المواقع (id, main_url, indexnow_key)
     */
    public function reindexDueSites(int $limit = 100): array
    {
        return $this->db->query(
            "SELECT id, main_url, indexnow_key FROM websites
             WHERE is_connected = 1 AND indexnow_enabled = 1
               AND indexnow_key IS NOT NULL AND indexnow_key <> ''
               AND (last_indexnow_at IS NULL OR last_indexnow_at <= DATE_SUB(NOW(), INTERVAL ? DAY))
             ORDER BY last_indexnow_at ASC
             LIMIT ?",
            [self::REINDEX_INTERVAL_DAYS, $limit]
        );
    }

    /**
     * إعادة فهرسة موقع واحد (الصفحة الرئيسية + sitemap) وتحديث last_indexnow_at.
     * @return array ['success'=>bool, 'status'=>?int, 'submitted'=>?int]
     */
    public function reindexSite(array $site): array
    {
        $host = parse_url((string) ($site['main_url'] ?? ''), PHP_URL_HOST);
        if (!$host) {
            return ['success' => false, 'error' => 'invalid main_url'];
        }

        $base = rtrim((string) $site['main_url'], '/');
        $urls = [$base . '/', $base . '/sitemap.xml'];

        $service = new IndexNowService();
        $result = $service->submitUrls($host, (string) $site['indexnow_key'], $urls);

        if (!empty($result['success'])) {
            $this->db->exec("UPDATE websites SET last_indexnow_at = NOW() WHERE id = ?", [$site['id']]);
        }

        return $result;
    }

    /**
     * المواقع المستحقة لإعادة التدقيق حسب التكرار المختار.
     * last_seo_audit_at NULL = أول مرة (مستحق فورًا).
     * @return array قائمة صفوف المواقع (id, user_id, main_url, auto_pilot_mode, seo_audit_frequency)
     */
    public function reauditDueSites(int $limit = 50): array
    {
        return $this->db->query(
            "SELECT id, user_id, main_url, auto_pilot_mode, seo_audit_frequency FROM websites
             WHERE is_connected = 1 AND auto_fix_enabled = 1
               AND (
                   last_seo_audit_at IS NULL
                   OR (seo_audit_frequency = 'daily' AND last_seo_audit_at <= DATE_SUB(NOW(), INTERVAL 1 DAY))
                   OR (seo_audit_frequency = 'weekly' AND last_seo_audit_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))
                   OR (seo_audit_frequency = 'monthly' AND last_seo_audit_at <= DATE_SUB(NOW(), INTERVAL 30 DAY))
               )
             ORDER BY last_seo_audit_at ASC
             LIMIT ?",
            [$limit]
        );
    }

    /** تحديد آخر موعد إعادة تدقيق لموقع (بعد نجاح الدورة) */
    public function markReaudited(int $websiteId): void
    {
        $this->db->exec("UPDATE websites SET last_seo_audit_at = NOW() WHERE id = ?", [$websiteId]);
    }
}
