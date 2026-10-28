<?php

/**
 * Tourfecto - AI Chat Platform
 * AI Analytics Service (بند 18): يجمّع مؤشرات أداء AI Chat من البيانات
 * التي بُنيت بالفعل عبر المراحل السابقة (ai_conversations, ai_usage_logs,
 * ai_leads, ai_followups, chat_messages) - لا جداول جديدة، فقط استعلامات
 * تجميعية (Aggregation) للعرض في لوحة الإدارة.
 *
 * @version 1.0.0
 */

class AiAnalyticsService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * كل المؤشرات المطلوبة في بند 18 لموقع معيّن خلال فترة زمنية.
     * @param int $websiteId
     * @param string|null $sinceDate 'Y-m-d' - افتراضيًا آخر 30 يوم
     * @return array
     */
    public function getDashboard(int $websiteId, ?string $sinceDate = null): array
    {
        $sinceDate = $sinceDate ?: date('Y-m-d', strtotime('-30 days'));

        return [
            'period_since' => $sinceDate,
            'total_conversations' => $this->totalConversations($websiteId, $sinceDate),
            'ai_conversations' => $this->conversationsByAiStatus($websiteId, $sinceDate, 'ai'),
            'human_conversations' => $this->conversationsByAiStatus($websiteId, $sinceDate, 'human'),
            'leads_generated' => $this->leadsGenerated($websiteId, $sinceDate),
            'hot_leads' => $this->hotLeads($websiteId, $sinceDate),
            'conversion_rate_percent' => $this->conversionRate($websiteId, $sinceDate),
            'average_response_time_seconds' => $this->averageResponseTime($websiteId, $sinceDate),
            'ai_resolution_rate_percent' => $this->aiResolutionRate($websiteId, $sinceDate),
            'human_handoff_rate_percent' => $this->humanHandoffRate($websiteId, $sinceDate),
            'followup_success_rate_percent' => $this->followUpSuccessRate($websiteId, $sinceDate),
            'top_tags' => $this->topTags($websiteId, $sinceDate),
            'most_popular_services' => $this->mostPopularServices($websiteId, $sinceDate),
            'ai_usage_by_provider' => AiUsageLog::statsFor($websiteId, $sinceDate),
        ];
    }

    private function totalConversations(int $websiteId, string $sinceDate): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM ai_conversations WHERE website_id = ? AND created_at >= ?",
            [$websiteId, $sinceDate]
        );
        return (int) ($row[0]['c'] ?? 0);
    }

    private function conversationsByAiStatus(int $websiteId, string $sinceDate, string $aiStatus): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM ai_conversations WHERE website_id = ? AND created_at >= ? AND ai_status = ?",
            [$websiteId, $sinceDate, $aiStatus]
        );
        return (int) ($row[0]['c'] ?? 0);
    }

    private function leadsGenerated(int $websiteId, string $sinceDate): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM ai_leads WHERE website_id = ? AND created_at >= ?",
            [$websiteId, $sinceDate]
        );
        return (int) ($row[0]['c'] ?? 0);
    }

    private function hotLeads(int $websiteId, string $sinceDate): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM ai_conversations
             WHERE website_id = ? AND created_at >= ? AND lead_status = 'hot_lead'",
            [$websiteId, $sinceDate]
        );
        return (int) ($row[0]['c'] ?? 0);
    }

    /**
     * نسبة الـLeads التي وصلت لحالة "won" من إجمالي الـLeads (بند 18).
     */
    private function conversionRate(int $websiteId, string $sinceDate): float
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won
             FROM ai_leads WHERE website_id = ? AND created_at >= ?",
            [$websiteId, $sinceDate]
        );
        $total = (int) ($row[0]['total'] ?? 0);
        $won = (int) ($row[0]['won'] ?? 0);
        return $total > 0 ? round(($won / $total) * 100, 1) : 0.0;
    }

    /**
     * متوسط زمن الرد بالثواني: الفارق بين كل رسالة واردة والرد الصادر
     * التالي مباشرة لها داخل نفس المحادثة (تقدير عملي بدون جدول توقيت مخصص).
     */
    private function averageResponseTime(int $websiteId, string $sinceDate): ?int
    {
        $rows = $this->db->query(
            "SELECT session_id, message_direction, created_at
             FROM chat_messages
             WHERE website_id = ? AND created_at >= ? AND session_id IS NOT NULL
             ORDER BY session_id ASC, created_at ASC
             LIMIT 5000",
            [$websiteId, $sinceDate]
        );

        $diffs = [];
        $pendingIncomingAt = [];

        foreach ($rows as $row) {
            $cid = $row['session_id'];
            if ($row['message_direction'] === 'incoming') {
                $pendingIncomingAt[$cid] = strtotime($row['created_at']);
            } elseif ($row['message_direction'] === 'outgoing' && isset($pendingIncomingAt[$cid])) {
                $diffs[] = strtotime($row['created_at']) - $pendingIncomingAt[$cid];
                unset($pendingIncomingAt[$cid]);
            }
        }

        if (empty($diffs)) {
            return null;
        }

        return (int) round(array_sum($diffs) / count($diffs));
    }

    /**
     * نسبة المحادثات التي انتهت (resolved/closed) دون أي تحويل لموظف إطلاقًا.
     */
    private function aiResolutionRate(int $websiteId, string $sinceDate): float
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_ended,
                SUM(CASE WHEN handoff_at IS NULL THEN 1 ELSE 0 END) AS ai_only
             FROM ai_conversations
             WHERE website_id = ? AND created_at >= ? AND status IN ('resolved', 'closed')",
            [$websiteId, $sinceDate]
        );
        $total = (int) ($row[0]['total_ended'] ?? 0);
        $aiOnly = (int) ($row[0]['ai_only'] ?? 0);
        return $total > 0 ? round(($aiOnly / $total) * 100, 1) : 0.0;
    }

    private function humanHandoffRate(int $websiteId, string $sinceDate): float
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN handoff_at IS NOT NULL THEN 1 ELSE 0 END) AS handed_off
             FROM ai_conversations WHERE website_id = ? AND created_at >= ?",
            [$websiteId, $sinceDate]
        );
        $total = (int) ($row[0]['total'] ?? 0);
        $handedOff = (int) ($row[0]['handed_off'] ?? 0);
        return $total > 0 ? round(($handedOff / $total) * 100, 1) : 0.0;
    }

    /**
     * نسبة المتابعات المُرسَلة التي حصلت على رد فعلي من العميل بعدها
     * (مؤشر تقريبي لنجاح المتابعة - بند 18).
     */
    private function followUpSuccessRate(int $websiteId, string $sinceDate): float
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_sent,
                SUM(CASE WHEN c.last_customer_message_at > f.sent_at THEN 1 ELSE 0 END) AS got_response
             FROM ai_followups f
             INNER JOIN ai_conversations c ON c.id = f.conversation_id
             WHERE f.website_id = ? AND f.status = 'sent' AND f.sent_at >= ?",
            [$websiteId, $sinceDate]
        );
        $total = (int) ($row[0]['total_sent'] ?? 0);
        $responded = (int) ($row[0]['got_response'] ?? 0);
        return $total > 0 ? round(($responded / $total) * 100, 1) : 0.0;
    }

    /**
     * أكثر الوسوم تكرارًا - مؤشر تقريبي لأكثر نوايا/طلبات العملاء
     * شيوعًا (بند 18: Top Customer Intent) بدون الحاجة لتحليل نصي إضافي.
     */
    private function topTags(int $websiteId, string $sinceDate, int $limit = 10): array
    {
        $rows = $this->db->query(
            "SELECT tags FROM ai_conversations WHERE website_id = ? AND created_at >= ? AND tags IS NOT NULL",
            [$websiteId, $sinceDate]
        );

        $counts = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) $row['tags'], true) ?: [];
            foreach ($tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    }

    /**
     * الخدمات الأكثر طلبًا من واقع الـLeads الفعلية (بند 18).
     */
    private function mostPopularServices(int $websiteId, string $sinceDate, int $limit = 10): array
    {
        $rows = $this->db->query(
            "SELECT interest, COUNT(*) AS c FROM ai_leads
             WHERE website_id = ? AND created_at >= ? AND interest IS NOT NULL AND interest != ''
             GROUP BY interest ORDER BY c DESC LIMIT ?",
            [$websiteId, $sinceDate, $limit]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['interest']] = (int) $row['c'];
        }
        return $result;
    }
}
