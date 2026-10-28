<?php

/**
 * Tourfecto - SEO: Chart Data Service (G6)
 * @version 1.0.0
 *
 * يسد فجوة "تقرير بصري/رسوم بيانية" (Ahrefs/SEMrush) بنفس نمط
 * CrmChartService في موديول CRM: يرجع بيانات جاهزة لـ Chart.js مباشرة
 * من قاعدة البيانات الفعلية (لا قيم افتراضية/وهمية).
 *
 * الدوال:
 *   - scoreTrend: تطور درجة التدقيق عبر الوقت (من seo_reports/wo_audits)
 *   - categoryScores: نتائج آخر تدقيق مكتمل لكل فئة (SEO/AEO/GEO/سرعة..)
 *   - gscTopPages: أعلى الصفحات كليكًا/ظهورًا من كاش GSC
 *   - fixesAppliedTrend: اتجاه الإصلاحات المطبقة عبر الوقت
 */
class SeoChartService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** تطور درجة التدقيق عبر الوقت. */
    public function scoreTrend(int $websiteId, int $userId, int $limit = 30): array
    {
        $rows = $this->db->query(
            "SELECT overall_score, created_at FROM seo_reports
             WHERE website_id = ? AND user_id = ? AND overall_score IS NOT NULL
             ORDER BY created_at ASC LIMIT ?",
            [$websiteId, $userId, $limit]
        );
        if (empty($rows)) {
            $rows = $this->db->query(
                "SELECT overall_score, completed_at AS created_at FROM wo_audits
                 WHERE website_id = ? AND user_id = ? AND status = 'completed' AND overall_score IS NOT NULL
                 ORDER BY completed_at ASC LIMIT ?",
                [$websiteId, $userId, $limit]
            );
        }

        return [
            'labels' => array_map(static fn ($r) => substr((string) $r['created_at'], 0, 16), $rows),
            'datasets' => [
                [
                    'label' => 'درجة SEO',
                    'data' => array_map(static fn ($r) => round((float) $r['overall_score'], 1), $rows),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16,185,129,.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
        ];
    }

    /** نتائج آخر تدقيق مكتمل لكل فئة. */
    public function categoryScores(int $websiteId, int $userId): array
    {
        $audit = $this->db->query(
            "SELECT id FROM wo_audits WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1",
            [$websiteId, $userId]
        );
        if (empty($audit)) {
            return ['labels' => [], 'datasets' => []];
        }

        $findings = $this->db->query(
            "SELECT category, status FROM wo_audit_findings WHERE audit_id = ?",
            [$audit[0]['id']]
        );
        $weights = ['pass' => 100, 'warn' => 60, 'fail' => 0, 'info' => 100];

        $byCategory = [];
        foreach ($findings as $f) {
            $byCategory[(string) $f['category']][] = $weights[$f['status']] ?? 50;
        }

        $labels = [];
        $data = [];
        foreach ($byCategory as $cat => $vals) {
            $labels[] = $cat;
            $data[] = round(array_sum($vals) / count($vals), 1);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'نتيجة الفئة',
                    'data' => $data,
                    'backgroundColor' => '#6366f1',
                ],
            ],
        ];
    }

    /** أعلى الصفحات كليكًا/ظهورًا من كاش GSC. */
    public function gscTopPages(int $websiteId, int $limit = 10): array
    {
        $rows = $this->db->query(
            "SELECT page_path, clicks, impressions FROM seo_gsc_page_metrics
             WHERE website_id = ? ORDER BY clicks DESC, impressions DESC LIMIT ?",
            [$websiteId, $limit]
        );

        $labels = array_map(static fn ($r) => (string) $r['page_path'], $rows);

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Clicks', 'data' => array_map(static fn ($r) => (int) $r['clicks'], $rows), 'backgroundColor' => '#10b981'],
                ['label' => 'Impressions', 'data' => array_map(static fn ($r) => (int) $r['impressions'], $rows), 'backgroundColor' => '#6366f1'],
            ],
        ];
    }

    /** اتجاه الإصلاحات المطبقة (auto_seo_change_log / auto_seo_applied_fixes). */
    public function fixesAppliedTrend(int $websiteId, int $limit = 30): array
    {
        $rows = $this->db->query(
            "SELECT DATE(created_at) AS day, COUNT(*) AS c
             FROM auto_seo_applied_fixes
             WHERE website_id = ? AND is_active = 1
             GROUP BY DATE(created_at) ORDER BY day ASC LIMIT ?",
            [$websiteId, $limit]
        );

        return [
            'labels' => array_map(static fn ($r) => (string) $r['day'], $rows),
            'datasets' => [
                ['label' => 'إصلاحات مطبقة', 'data' => array_map(static fn ($r) => (int) $r['c'], $rows), 'backgroundColor' => '#f59e0b'],
            ],
        ];
    }
}
