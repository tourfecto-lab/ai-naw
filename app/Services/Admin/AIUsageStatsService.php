<?php

/**
 * Tourfecto - AI Usage Stats Service
 * بيجاوب على السؤال الأساسي المطلوب في السبيك: "أعرف بالظبط العميل كلفني كام".
 * كل الاستعلامات هنا Read-Only على api_usage_logs - مفيش أي تعديل على بيانات
 * الاستخدام أو الفوترة الفعلية (اللي بتتم عن طريق SubscriptionValidator/
 * WalletService الموجودين بالفعل ومحدش لمسهم).
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AIUsageStatsService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * إجمالي التكلفة + عدد الطلبات + التوكنات، مقسّمة حسب Provider
     * (api_type). بيشمل الصفوف اللي api_type بتاعها من: gemini/openai/
     * deepseek/kimi (بيتجاهل whatsapp/stripe/... تلقائيًا لأنهم مش AI).
     *
     * @param string|null $from تاريخ البداية (Y-m-d)، افتراضيًا آخر 30 يوم
     * @param string|null $to تاريخ النهاية (Y-m-d)، افتراضيًا النهاردة
     * @return array
     */
    public function costByProvider(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->normalizeRange($from, $to);
        $sql = "SELECT api_type AS provider,
                       COUNT(*) AS requests,
                       SUM(tokens_used) AS total_tokens,
                       SUM(cost_in_usd) AS total_cost,
                       SUM(CASE WHEN status_code IS NULL OR status_code >= 400 THEN 1 ELSE 0 END) AS failed_requests
                FROM api_usage_logs
                WHERE api_type IN ('gemini','openai','deepseek','kimi')
                  AND created_at BETWEEN :from AND :to
                GROUP BY api_type
                ORDER BY total_cost DESC";
        return $this->db->query($sql, [':from' => $from, ':to' => $to]) ?: [];
    }

    /**
     * نفس الفكرة بس مقسّمة حسب الـModel المحدد (يحتاج عمود model من Migration
     * Phase 4 - الصفوف القديمة قبل الـMigration هتظهر بـmodel = NULL).
     */
    public function costByModel(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->normalizeRange($from, $to);
        $sql = "SELECT api_type AS provider,
                       COALESCE(model, endpoint, 'unknown') AS model,
                       COUNT(*) AS requests,
                       SUM(tokens_used) AS total_tokens,
                       SUM(cost_in_usd) AS total_cost
                FROM api_usage_logs
                WHERE api_type IN ('gemini','openai','deepseek','kimi')
                  AND created_at BETWEEN :from AND :to
                GROUP BY api_type, COALESCE(model, endpoint, 'unknown')
                ORDER BY total_cost DESC";
        return $this->db->query($sql, [':from' => $from, ':to' => $to]) ?: [];
    }

    /**
     * التكلفة حسب الـFeature (seo_analysis, chat, review_reply...) - يحتاج
     * عمود feature من Migration Phase 4.
     */
    public function costByFeature(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->normalizeRange($from, $to);
        $sql = "SELECT COALESCE(feature, 'unclassified') AS feature,
                       COUNT(*) AS requests,
                       SUM(tokens_used) AS total_tokens,
                       SUM(cost_in_usd) AS total_cost
                FROM api_usage_logs
                WHERE api_type IN ('gemini','openai','deepseek','kimi')
                  AND created_at BETWEEN :from AND :to
                GROUP BY COALESCE(feature, 'unclassified')
                ORDER BY total_cost DESC";
        return $this->db->query($sql, [':from' => $from, ':to' => $to]) ?: [];
    }

    /**
     * أكتر العملاء تكلفة - "العميل ده كلفني كام" بالظبط.
     * الصفوف اللي user_id فيها NULL (نداءات مستوى-نظام قديمة قبل ما نبدأ
     * نسجّل user_id) بتتجمع تحت "system/unattributed" بدل ما تتجاهل بصمت.
     */
    public function mostExpensiveCustomers(?string $from = null, ?string $to = null, int $limit = 20): array
    {
        [$from, $to] = $this->normalizeRange($from, $to);
        $sql = "SELECT COALESCE(l.user_id, 0) AS user_id,
                       u.email AS user_email,
                       u.name AS user_name,
                       COUNT(*) AS requests,
                       SUM(l.tokens_used) AS total_tokens,
                       SUM(l.cost_in_usd) AS total_cost
                FROM api_usage_logs l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE l.api_type IN ('gemini','openai','deepseek','kimi')
                  AND l.created_at BETWEEN :from AND :to
                GROUP BY COALESCE(l.user_id, 0), u.email, u.name
                ORDER BY total_cost DESC
                LIMIT " . (int) $limit;
        return $this->db->query($sql, [':from' => $from, ':to' => $to]) ?: [];
    }

    /**
     * طلبات + توكنات يوميًا (للرسم البياني - Requests per Day / Tokens per Day)
     */
    public function dailyUsage(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->normalizeRange($from, $to);
        $sql = "SELECT DATE(created_at) AS day,
                       COUNT(*) AS requests,
                       SUM(tokens_used) AS total_tokens,
                       SUM(cost_in_usd) AS total_cost,
                       SUM(CASE WHEN status_code IS NULL OR status_code >= 400 THEN 1 ELSE 0 END) AS failed_requests
                FROM api_usage_logs
                WHERE api_type IN ('gemini','openai','deepseek','kimi')
                  AND created_at BETWEEN :from AND :to
                GROUP BY DATE(created_at)
                ORDER BY day ASC";
        return $this->db->query($sql, [':from' => $from, ':to' => $to]) ?: [];
    }

    /**
     * إجمالي عام (ملخص أعلى الصفحة في لوحة الأدمن)
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->normalizeRange($from, $to);
        $sql = "SELECT COUNT(*) AS total_requests,
                       SUM(tokens_used) AS total_tokens,
                       SUM(cost_in_usd) AS total_cost,
                       SUM(CASE WHEN status_code IS NULL OR status_code >= 400 THEN 1 ELSE 0 END) AS failed_requests,
                       COUNT(DISTINCT user_id) AS distinct_customers
                FROM api_usage_logs
                WHERE api_type IN ('gemini','openai','deepseek','kimi')
                  AND created_at BETWEEN :from AND :to";
        $rows = $this->db->query($sql, [':from' => $from, ':to' => $to]);
        return $rows[0] ?? [
            'total_requests' => 0, 'total_tokens' => 0, 'total_cost' => 0,
            'failed_requests' => 0, 'distinct_customers' => 0,
        ];
    }

    private function normalizeRange(?string $from, ?string $to): array
    {
        $to = $to ?: date('Y-m-d 23:59:59');
        $from = $from ?: date('Y-m-d 00:00:00', strtotime('-30 days'));
        if (strlen($from) === 10) {
            $from .= ' 00:00:00';
        }
        if (strlen($to) === 10) {
            $to .= ' 23:59:59';
        }
        return [$from, $to];
    }
}
