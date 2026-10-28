<?php

/**
 * Tourfecto - Competitor Intelligence: Benchmarking Service
 * @version 1.0.0
 *
 * يقارن My Business ضد Competitor A/B/C باستخدام إشارات حقيقية متاحة
 * فقط داخل النظام (لا Metrics مُخترعة):
 *  - Website Presence: صفحات عامة تم رصدها فعليًا (pricing/products/offers...)
 *  - Content Activity: عدد المقالات المنشورة (My Business) مقابل عدد
 *    التغييرات المكتشفة في صفحة blog للمنافس (Competitor)
 *  - Offer Activity: عدد offer_change المكتشفة لكل منافس
 *  - Product / Service Coverage: هل صفحة products/services مرصودة فعليًا
 *  - Market Position Signals: عدد التغييرات الكلي كمؤشر نشاط عام (Estimated)
 *
 * أي مقياس غير متاح يُعرض "Not Available" صراحة بدل تخمينه.
 */
class BenchmarkingService
{
    public function compare(int $websiteId, array $competitorIds, int $days = 90): array
    {
        $db = Database::getInstance();

        $myArticles = $db->query(
            "SELECT COUNT(*) AS c FROM `ai_articles` WHERE website_id = ? AND status = 'published' AND published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$websiteId, $days]
        );
        $myContentActivity = (int) ($myArticles[0]['c'] ?? 0);

        $rows = ['my_business' => [
            'label' => 'My Business',
            'website_presence' => 'not_applicable',
            'content_activity' => $myContentActivity,
            'content_activity_basis' => 'data_backed',
            'offer_activity' => 'not_available',
            'product_service_coverage' => 'not_applicable',
            'market_position_signals' => 'not_available',
        ]];

        foreach ($competitorIds as $competitorId) {
            $competitorId = (int) $competitorId;
            $competitor = (new Competitor())->find($competitorId);
            if (!$competitor) {
                continue;
            }

            $rows['competitor_' . $competitorId] = [
                'label' => (string) ($competitor->getAttribute('competitor_name') ?: $competitor->getAttribute('competitor_domain')),
                'website_presence' => $this->presenceSignals($competitorId),
                'content_activity' => $this->countChanges($competitorId, 'blog', $days),
                'content_activity_basis' => 'data_backed',
                'offer_activity' => $this->countChanges($competitorId, 'offers', $days) + $this->countChangesByType($competitorId, 'offer_change', $days),
                'product_service_coverage' => $this->presenceSignals($competitorId, ['products', 'services']),
                'market_position_signals' => $this->countAllChanges($competitorId, $days) . ' (estimated activity index)',
            ];
        }

        return [
            'period_days' => $days,
            'generated_at' => date('Y-m-d H:i:s'),
            'rows' => $rows,
        ];
    }

    private function presenceSignals(int $competitorId, array $onlyPages = []): array
    {
        $db = Database::getInstance();
        $pages = $onlyPages ?: CiConstants::PAGE_TYPES;
        $placeholders = implode(',', array_fill(0, count($pages), '?'));

        $rows = $db->query(
            "SELECT page_type, MAX(captured_at) AS last_seen, MAX(fetch_status='ok') AS ever_ok
             FROM `ci_snapshots` WHERE competitor_id = ? AND page_type IN ({$placeholders})
             GROUP BY page_type",
            array_merge([$competitorId], $pages)
        );

        $result = [];
        foreach ($rows as $r) {
            $result[$r['page_type']] = (bool) $r['ever_ok'];
        }
        foreach ($pages as $p) {
            if (!array_key_exists($p, $result)) {
                $result[$p] = 'not_available';
            }
        }
        return $result;
    }

    private function countChanges(int $competitorId, string $pageType, int $days): int
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM `ci_changes` WHERE competitor_id = ? AND page_type = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$competitorId, $pageType, $days]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    private function countChangesByType(int $competitorId, string $changeType, int $days): int
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM `ci_changes` WHERE competitor_id = ? AND change_type = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$competitorId, $changeType, $days]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    private function countAllChanges(int $competitorId, int $days): int
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM `ci_changes` WHERE competitor_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$competitorId, $days]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Scorecard دوري (يُستخدم من الـ cron الأسبوعي أو عند الطلب) - يحفظ
     * لقطة في ci_scorecards بدرجات 0-100 محسوبة من نفس الإشارات أعلاه.
     * basis = data_backed فقط لو فيه لقطات كافية (>=3) وإلا estimated.
     */
    public function computeScorecard(int $competitorId, int $days = 30): CiScorecard
    {
        $snapshotCount = $this->snapshotCount($competitorId, $days);
        $changes90 = $this->countAllChanges($competitorId, $days);
        $offerChanges = $this->countChangesByType($competitorId, 'offer_change', $days);
        $presence = $this->presenceSignals($competitorId);
        $pagesPresent = count(array_filter($presence, fn ($v) => $v === true));

        $basis = $snapshotCount >= 3 ? 'data_backed' : 'estimated';

        $scores = [
            'visibility_score' => min(100, $pagesPresent * (100 / 7)),
            'content_activity_score' => min(100, $this->countChanges($competitorId, 'blog', $days) * 20),
            'offer_activity_score' => min(100, $offerChanges * 25),
            'customer_signals_score' => null, // Not Available - يحتاج تكامل مراجعات عام (Reputation module منفصل)
            'product_coverage_score' => ($presence['products'] === true || $presence['services'] === true) ? 100 : 0,
            'market_presence_score' => min(100, $changes90 * 10),
        ];

        $scorecard = new CiScorecard([
            'competitor_id' => $competitorId,
            'period_start' => date('Y-m-d', strtotime("-{$days} days")),
            'period_end' => date('Y-m-d'),
            'visibility_score' => round($scores['visibility_score'], 2),
            'content_activity_score' => round($scores['content_activity_score'], 2),
            'offer_activity_score' => round($scores['offer_activity_score'], 2),
            'customer_signals_score' => $scores['customer_signals_score'],
            'product_coverage_score' => $scores['product_coverage_score'],
            'market_presence_score' => round($scores['market_presence_score'], 2),
            'basis' => $basis,
            'raw_metrics' => json_encode([
                'snapshot_count' => $snapshotCount, 'changes_in_period' => $changes90,
                'offer_changes' => $offerChanges, 'pages_present' => $pagesPresent,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $scorecard->save();

        return $scorecard;
    }

    private function snapshotCount(int $competitorId, int $days): int
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM `ci_snapshots` WHERE competitor_id = ? AND captured_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND fetch_status = 'ok'",
            [$competitorId, $days]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }
}
