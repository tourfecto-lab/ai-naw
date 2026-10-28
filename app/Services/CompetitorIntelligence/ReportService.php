<?php

/**
 * Tourfecto - Competitor Intelligence: Report Service
 * @version 1.0.0
 *
 * كل تقرير يُبنى من استعلامات مباشرة على ci_changes/ci_insights/
 * ci_scorecards الحقيقية ويُخزَّن كـ JSON في ci_reports (مصدر حقيقة
 * واحد قابل لإعادة العرض/التصدير لاحقًا بدون إعادة حساب).
 */
class ReportService
{
    private const ALLOWED_TYPES = ['weekly', 'monthly', 'profile', 'threat', 'opportunity', 'change'];

    public function generate(int $userId, int $websiteId, string $type, array $competitorIds = [], ?int $singleCompetitorId = null): CiReport
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported report type: {$type}");
        }

        $days = $type === 'monthly' ? 30 : 7;
        [$periodStart, $periodEnd] = [date('Y-m-d', strtotime("-{$days} days")), date('Y-m-d')];

        switch ($type) {
            case 'weekly':
            case 'monthly':
                $content = $this->buildChangesSummary($userId, $days);
                $title = ($type === 'weekly' ? 'Weekly' : 'Monthly') . ' Competitive Report';
                break;
            case 'profile':
                if (!$singleCompetitorId) {
                    throw new InvalidArgumentException('profile report requires a competitor id');
                }
                $content = $this->buildProfileReport($singleCompetitorId);
                $title = 'Competitor Profile Report';
                break;
            case 'threat':
                $content = $this->buildInsightsReport($userId, 'threat', $days);
                $title = 'Threat Report';
                break;
            case 'opportunity':
                $content = $this->buildInsightsReport($userId, 'opportunity', $days);
                $title = 'Opportunity Report';
                break;
            case 'change':
            default:
                $content = $this->buildChangesSummary($userId, $days);
                $title = 'Change Report';
                break;
        }

        $report = new CiReport([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'competitor_id' => $singleCompetitorId,
            'type' => $type,
            'title' => $title,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'content_json' => json_encode($content, JSON_UNESCAPED_UNICODE),
            'generated_by' => 'rules_engine',
        ]);
        $report->save();

        ActivityLog::record('competitor_intelligence', 'report.generated', [
            'user_id' => $userId, 'subject_type' => 'ci_reports', 'subject_id' => (int) $report->getAttribute('id'),
            'meta' => ['type' => $type],
        ]);

        return $report;
    }

    private function buildChangesSummary(int $userId, int $days): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT c.*, comp.competitor_name, comp.competitor_domain
             FROM `ci_changes` c JOIN `competitors` comp ON comp.id = c.competitor_id
             WHERE c.user_id = ? AND c.detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY c.detected_at DESC",
            [$userId, $days]
        );

        $bySeverity = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($rows as $r) {
            $bySeverity[$r['severity']] = ($bySeverity[$r['severity']] ?? 0) + 1;
        }

        return [
            'period_days' => $days,
            'total_changes' => count($rows),
            'changes_by_severity' => $bySeverity,
            'changes' => array_map(function ($r) {
                return [
                    'competitor' => $r['competitor_name'] ?: $r['competitor_domain'],
                    'page_type' => $r['page_type'],
                    'change_type' => $r['change_type'],
                    'severity' => $r['severity'],
                    'confidence' => $r['confidence'],
                    'detected_at' => $r['detected_at'],
                    'source_url' => $r['source_url'],
                ];
            }, $rows),
        ];
    }

    private function buildProfileReport(int $competitorId): array
    {
        $competitor = (new Competitor())->find($competitorId);
        if (!$competitor) {
            throw new RuntimeException('Competitor not found');
        }

        $timeline = (new CompetitorTrackingService())->getTimeline($competitorId, 6);
        $db = Database::getInstance();
        $scorecardRows = $db->query(
            "SELECT * FROM `ci_scorecards` WHERE competitor_id = ? ORDER BY computed_at DESC LIMIT 1",
            [$competitorId]
        );

        return [
            'competitor' => $competitor->toArray(),
            'timeline_by_month' => $timeline,
            'latest_scorecard' => $scorecardRows[0] ?? null,
        ];
    }

    private function buildInsightsReport(int $userId, string $type, int $days): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT i.*, comp.competitor_name, comp.competitor_domain
             FROM `ci_insights` i LEFT JOIN `competitors` comp ON comp.id = i.competitor_id
             WHERE i.user_id = ? AND i.type = ? AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY i.created_at DESC",
            [$userId, $type, $days]
        );

        return [
            'period_days' => $days,
            'total' => count($rows),
            'items' => array_map(function ($r) {
                return [
                    'competitor' => $r['competitor_name'] ?: $r['competitor_domain'] ?: 'market-wide',
                    'title' => $r['title'],
                    'description' => $r['description'],
                    'evidence' => $r['evidence'],
                    'confidence' => $r['confidence'],
                    'threat_level' => $r['threat_level'],
                    'recommended_action' => $r['recommended_action'],
                    'created_at' => $r['created_at'],
                ];
            }, $rows),
        ];
    }
}
