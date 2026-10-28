<?php

/**
 * Tourfecto - AI Chat Resolution Rate Service (بند 8)
 * @version 1.0.0
 *
 * سطح "حالة/صحة" إضافي (Additive) فوق البيانات الموجودة فعلًا:
 *   - معدل الحل (Resolution Rate) من حالات ai_conversations:
 *       المحادثات المنتهية (resolved/closed) التي حُسمت بالكامل عبر الـAI
 *       (بدون أي تحويل لموظف) ÷ إجمالي المحادثات المنتهية.
 *   - جودة/موثوقية الاستدعاء من ai_usage_logs: نسبة الطلبات الناجحة
 *       (status = 'success') من إجمالي الطلبات — إشارة استقرار الـAI.
 *
 * مبادئ (مرآة لـ CrmForecastService / AiAnalyticsService):
 *   - لا اختراع بيانات: لا يوجد محادثات منتهية أو طلبات → null صراحةً.
 *   - كل رقم يُعرض مع حجم العينة + توصيف "تقدير إحصائي" وليس حقيقة.
 *   - Additive: لا يُعدَّل AiAnalyticsController/LearningLoopService.
 */

class AiResolutionRateService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * معدل الحل + جودة الاستدعاء لموقع معيّن خلال فترة زمنية.
     * @param int $websiteId
     * @param string|null $sinceDate 'Y-m-d' - افتراضيًا آخر 30 يوم
     * @return array
     */
    public function resolutionRate(int $websiteId, ?string $sinceDate = null): array
    {
        $sinceDate = $sinceDate ?: date('Y-m-d', strtotime('-30 days'));

        $conv = $this->db->query(
            "SELECT
                COUNT(*) AS total_ended,
                SUM(CASE WHEN handoff_at IS NULL THEN 1 ELSE 0 END) AS ai_resolved,
                SUM(CASE WHEN handoff_at IS NOT NULL THEN 1 ELSE 0 END) AS human_resolved
             FROM ai_conversations
             WHERE website_id = ? AND created_at >= ? AND status IN ('resolved', 'closed')",
            [$websiteId, $sinceDate]
        );
        $conv = $conv[0] ?? [];

        $open = $this->db->query(
            "SELECT COUNT(*) AS c FROM ai_conversations
             WHERE website_id = ? AND created_at >= ? AND status IN ('open', 'pending')",
            [$websiteId, $sinceDate]
        );
        $openCount = (int) ($open[0]['c'] ?? 0);

        $usage = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'fallback_used' THEN 1 ELSE 0 END) AS fallback
             FROM ai_usage_logs
             WHERE website_id = ? AND created_at >= ?",
            [$websiteId, $sinceDate]
        );
        $usage = $usage[0] ?? [];

        $totalEnded = (int) ($conv['total_ended'] ?? 0);
        $aiResolved = (int) ($conv['ai_resolved'] ?? 0);
        $humanResolved = (int) ($conv['human_resolved'] ?? 0);

        $usageTotal = (int) ($usage['total'] ?? 0);
        $usageSuccess = (int) ($usage['success'] ?? 0);
        $usageFailed = (int) ($usage['failed'] ?? 0);
        $usageFallback = (int) ($usage['fallback'] ?? 0);

        return [
            'basis' => 'statistical',
            'period_since' => $sinceDate,
            'resolution_rate_percent' => $totalEnded > 0 ? round(($aiResolved / $totalEnded) * 100, 1) : null,
            'resolution_confidence' => $this->confidenceFor($totalEnded),
            'conversations' => [
                'total_ended' => $totalEnded,
                'ai_resolved' => $aiResolved,
                'human_resolved' => $humanResolved,
                'still_open' => $openCount,
                'note' => $totalEnded > 0
                    ? 'نسبة المحادثات المنتهية التي حُسمت بالكامل عبر الـAI دون تحويل لموظف'
                    : 'لا توجد محادثات منتهية في هذه الفترة - لا يوجد معدل حل موثوق بعد',
            ],
            'ai_usage' => [
                'total_requests' => $usageTotal,
                'success_requests' => $usageSuccess,
                'failed_requests' => $usageFailed,
                'fallback_used' => $usageFallback,
                'success_rate_percent' => $usageTotal > 0 ? round(($usageSuccess / $usageTotal) * 100, 1) : null,
                'note' => $usageTotal > 0
                    ? 'نسبة طلبات الـAI الناجحة (بدون فشل/بديل) - إشارة استقرار المزود'
                    : 'لا توجد طلبات AI مسجّلة في هذه الفترة',
            ],
            'note' => 'تقديرات إحصائية من بيانات الحساب الفعلية - ليست ضمانًا لمعدل الحل الفعلي',
        ];
    }

    private function confidenceFor(int $n): string
    {
        if ($n >= 30) {
            return 'high';
        }
        if ($n >= 10) {
            return 'moderate';
        }
        return 'low';
    }
}
