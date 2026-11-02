<?php

/**
 * Tourfecto - Outreach Performance Service (Item 2c)
 * @version 1.0.0
 *
 * تقرير أداء الـ Outreork/Backlink Pipeline لموقع واحد:
 * - قمع المراحل (funnel): توزيع المرشّحين على كل حالات الـ Pipeline.
 * - معدلات التحويل بين المراحل (prospect→contacted→replied→link_acquired).
 * - حالة الباك لينكس الحية/المفقودة/قيد الفحص (من monitored_backlinks).
 * - متوسط الوقت للحصول على الرابط (أيام) من تاريخ إنشاء المرشّح لآخر
 *   حالة link_acquired.
 */
class OutreachPerformanceService
{
    private const STAGES = ['prospect', 'researched', 'contacted', 'replied', 'negotiating', 'link_acquired', 'declined'];

    /**
     * تقرير الأداء لموقع معيّن.
     * @param int $userId
     * @param int $websiteId
     * @return array{funnel:array, conversion:array, backlinks:array, avg_time_to_link_days:?float}
     */
    public function report(int $userId, int $websiteId): array
    {
        $db = Database::getInstance();

        // 1) القمع: عدّ كل حالة
        $funnel = array_fill_keys(self::STAGES, 0);
        $rows = $db->query(
            'SELECT status, COUNT(*) AS cnt FROM outreach_prospects
             WHERE user_id = ? AND website_id = ? GROUP BY status',
            [$userId, $websiteId]
        );
        $total = 0;
        foreach ($rows as $row) {
            if (array_key_exists($row['status'], $funnel)) {
                $funnel[$row['status']] = (int) $row['cnt'];
                $total += (int) $row['cnt'];
            }
        }
        $funnel['total'] = $total;

        // 2) معدلات التحويل بين المراحل
        $reached = $funnel['contacted'] + $funnel['replied'] + $funnel['negotiating'] + $funnel['link_acquired'];
        $replied = $funnel['replied'] + $funnel['negotiating'] + $funnel['link_acquired'];
        $negotiating = $funnel['negotiating'] + $funnel['link_acquired'];
        $acquired = $funnel['link_acquired'];

        $conversion = [
            'contact_rate' => $this->pct($reached, $total),
            'reply_rate' => $this->pct($replied, $reached),
            'negotiation_rate' => $this->pct($negotiating, $replied),
            'acquisition_rate' => $this->pct($acquired, $negotiating),
            'overall_acquired_rate' => $this->pct($acquired, $total),
        ];

        // 3) حالة الباك لينكس
        $backlinks = (new BacklinkMonitorService())->summaryForWebsite($userId, $websiteId);

        // 4) متوسط الوقت للحصول على الرابط (من إنشاء المرشّح لآخر updated
        //    عند وصوله link_acquired) بالأيام
        $avgRow = $db->query(
            'SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) AS avg_sec
             FROM outreach_prospects
             WHERE user_id = ? AND website_id = ? AND status = ? AND updated_at >= created_at',
            [$userId, $websiteId, 'link_acquired']
        );
        $avgDays = null;
        if (!empty($avgRow) && $avgRow[0]['avg_sec'] !== null) {
            $avgDays = round((float) $avgRow[0]['avg_sec'] / 86400, 1);
        }

        // 3-ب) الأرقام الفعلية: رسايل اتبعتت من المنصة، مقابل مراحل العميل غيّرها بإيده من غير إرسال
        $sent = $db->query(
            "SELECT COUNT(*) AS emails, COUNT(DISTINCT e.prospect_id) AS prospects FROM outreach_emails e
             JOIN outreach_prospects p ON p.id = e.prospect_id WHERE p.user_id = ? AND p.website_id = ? AND e.status = 'sent'",
            [$userId, $websiteId]
        );
        $emails = (int) ($sent[0]['emails'] ?? 0);
        $emailed = (int) ($sent[0]['prospects'] ?? 0);
        $verified = [
            'emails_sent' => $emails,
            'prospects_emailed' => $emailed,
            'manual_without_email' => max(0, $reached - $emailed),
            'note' => 'المراحل بتتحدد من العميل بإيده؛ «تواصل» الفعلي هو اللي اتبعت من المنصة. الردود بتتسجل يدويًا (المنصة مش بتقرا صندوق الإيميل).',
        ];

        return [
            'funnel' => $funnel,
            'conversion' => $conversion,
            'verified' => $verified,
            'backlinks' => $backlinks,
            'avg_time_to_link_days' => $avgDays,
        ];
    }

    private function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
