<?php

/**
 * Tourfecto - Competitor Intelligence: Battlecard Service (G6)
 * @version 1.0.0
 *
 * توليد "بطاقة معركة" (Battlecard) لكل منافس لإعداد فريق المبيعات:
 * نقاط قوة/ضعف المنافس، موقعه السعري، نشاطه المحتوى، وتوصيات
 * إجرائية مقترحة - كلها مبنية على بيانات مراقبة حقيقية (Scorecard +
 * أسعار منتجات + رؤى + تغييرات). لا يختلق حقائق أبدًا (NO FAKE DATA):
 *
 * - لو مفيش بيانات كافية (مفيش scorecard ولا أسعار ولا تغييرات) يرجّع
 *   insufficient_data بدل توليد بطاقة فارغة/مضللة.
 * - نقاط القوة/الضعف مستمدة من أبعاد Scorecard الفعلية (عتبات شفافة)
 *   + رؤى threats/opportunities المخزنة فعليًا.
 * - الموقف السعري من ci_product_prices (آخر سعر + أول سعر لكل منتج).
 * - التوصيات قواعد صريحة (rules-based) مقرونة بالأدلة اللي بُنيت منها.
 *
 * أعمدة JSON تُخزَّن كنصوص JSON في ci_battlecards.
 */
class BattlecardService
{
    private const SCORECARD_STRENGTH_MIN = 60;
    private const SCORECARD_WEAKNESS_MAX = 40;

    /**
     * يولّد بطاقة معركة لمنافس من بيانات حقيقية ويحفظها. يرجّع البطاقة
     * المحفوظة (آخر بطاقة = الأحدث). لو مفيش بيانات كافية يرجّع
     * available=false بدون حفظ أي شيء.
     *
     * @return array{success:bool, available?:bool, error?:string, battlecard?:array}
     */
    public function generate(int $userId, int $competitorId): array
    {
        $competitor = (new Competitor())->find($competitorId);
        if (!$competitor || (int) $competitor->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'available' => false, 'error' => 'competitor_not_found'];
        }

        $name = (string) ($competitor->getAttribute('competitor_name') ?: $competitor->getAttribute('competitor_domain'));

        $scorecard = $this->latestScorecard($competitorId);
        $insights = $this->recentInsights($userId, $competitorId, 30);
        $changes = $this->recentChanges($competitorId, 30);
        $prices = (new ProductPriceTrackerService())->listProducts($competitorId, 50);

        if ($scorecard === null && empty($insights) && empty($changes) && empty($prices)) {
            return [
                'success' => false,
                'available' => false,
                'error' => 'insufficient_data',
            ];
        }

        // --- نقاط القوة والضعف من Scorecard (عتبات شفافة) + رؤى فعلية ---
        $strengths = $this->scorecardDimensions($scorecard, true);
        $weaknesses = $this->scorecardDimensions($scorecard, false);
        foreach ($insights as $i) {
            $dimension = 'insights_' . (($i['type'] ?? '') === 'opportunity' ? 'opportunity' : 'threat');
            if (($i['type'] ?? '') === 'opportunity') {
                $strengths[] = $this->insightStrength($i);
            } else {
                $weaknesses[] = $this->insightWeakness($i);
            }
        }
        $strengths = array_values(array_filter($strengths));
        $weaknesses = array_values(array_filter($weaknesses));

        // --- الموقف السعري من أسعار منتجات حقيقية ---
        $pricePosition = [];
        foreach ($prices as $p) {
            $pricePosition[] = [
                'product' => $p['product_name'],
                'latest_price' => $p['latest_price'],
                'first_price' => $p['first_price'],
                'currency' => $p['currency'],
                'readings' => $p['readings'],
            ];
        }

        // --- نشاط المحتوى من التغييرات الحقيقية (حسب نوع الصفحة) ---
        $contentPosition = $this->contentPosition($changes);

        // --- التوصيات: قواعد صريحة مقرونة بالأدلة ---
        $recommendations = $this->buildRecommendations($scorecard, $prices, $changes, $strengths, $weaknesses, $name);

        // --- الأدلة: كل نقطة بيانات استُخدمت ---
        $evidence = [
            'scorecard' => $scorecard !== null ? [
                'computed_at' => $scorecard['computed_at'],
                'basis' => $scorecard['basis'],
                'visibility_score' => $scorecard['visibility_score'],
                'content_activity_score' => $scorecard['content_activity_score'],
                'offer_activity_score' => $scorecard['offer_activity_score'],
                'product_coverage_score' => $scorecard['product_coverage_score'],
                'market_presence_score' => $scorecard['market_presence_score'],
            ] : null,
            'insights_count' => count($insights),
            'changes_last_30d' => count($changes),
            'products_tracked' => count($prices),
            'generated_from' => 'rules_engine:scorecard+insights+product_prices+changes',
        ];

        $positioningSummary = $this->buildPositioningSummary($name, $scorecard, $contentPosition, $pricePosition);

        $battlecard = new CiBattlecard([
            'user_id' => $userId,
            'competitor_id' => $competitorId,
            'title' => "Battlecard: {$name}",
            'positioning_summary' => $positioningSummary,
            'strengths' => $this->jsonEncode($strengths),
            'weaknesses' => $this->jsonEncode($weaknesses),
            'price_position' => $this->jsonEncode($pricePosition),
            'content_position' => $this->jsonEncode($contentPosition),
            'recommended_actions' => $this->jsonEncode($recommendations),
            'evidence' => $this->jsonEncode($evidence),
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
        $battlecard->save();

        return ['success' => true, 'available' => true, 'battlecard' => $battlecard->toArray()];
    }

    /** أحدث بطاقة معركة محفوظة لمنافس (أو null). */
    public function latest(int $userId, int $competitorId): ?array
    {
        $rows = Database::getInstance()->query(
            "SELECT * FROM ci_battlecards
             WHERE user_id = ? AND competitor_id = ?
             ORDER BY generated_at DESC, id DESC LIMIT 1",
            [$userId, $competitorId]
        );
        if (empty($rows)) {
            return null;
        }
        return $this->decodeBattlecard($rows[0]);
    }

    /** قائمة أحدث بطاقات المعركة لمستخدم (لكل منافس آخر بطاقة). */
    public function listForUser(int $userId, int $limit = 50): array
    {
        $rows = Database::getInstance()->query(
            "SELECT b.*, c.competitor_name, c.competitor_domain
             FROM ci_battlecards b
             JOIN competitors c ON c.id = b.competitor_id
             WHERE b.user_id = ?
             ORDER BY b.generated_at DESC, b.id DESC
             LIMIT ?",
            [$userId, $limit]
        );
        return array_map(fn ($r) => $this->decodeBattlecard($r), $rows);
    }

    // ============================ Rules ============================

    /**
     * أبعاد Scorecard كقوة (>= 60) أو ضعف (<= 40) - عتبات شفافة.
     * @return string[]
     */
    private function scorecardDimensions(?array $scorecard, bool $strengths): array
    {
        if ($scorecard === null) {
            return [];
        }
        $threshold = $strengths ? self::SCORECARD_STRENGTH_MIN : self::SCORECARD_WEAKNESS_MAX;
        $out = [];
        $dims = [
            'visibility_score' => 'visibility',
            'content_activity_score' => 'content activity',
            'offer_activity_score' => 'offer activity',
            'product_coverage_score' => 'product coverage',
            'market_presence_score' => 'market presence',
        ];
        foreach ($dims as $col => $label) {
            $val = $scorecard[$col] ?? null;
            if ($val === null) {
                continue;
            }
            $val = (int) $val;
            if ($strengths && $val >= $threshold) {
                $out[] = "Strong {$label} ({$val}/100)";
            } elseif (!$strengths && $val <= $threshold && $val > 0) {
                $out[] = "Weak {$label} ({$val}/100)";
            }
        }
        return $out;
    }

    private function insightStrength(array $insight): string
    {
        return 'Opportunity: ' . ($insight['title'] ?? 'detected opportunity');
    }

    private function insightWeakness(array $insight): string
    {
        return 'Threat: ' . ($insight['title'] ?? 'detected threat');
    }

    /**
     * نشاط المحتوى من التغييرات: عدّ التغييرات لكل نوع صفحة/نوع تغيير
     * خلال آخر 30 يوم + إجمالي عالي الخطورة.
     * @return array{total_changes:int, high_severity:int, by_page:array<string,int>, by_type:array<string,int>}
     */
    private function contentPosition(array $changes): array
    {
        $byPage = [];
        $byType = [];
        $high = 0;
        foreach ($changes as $c) {
            $page = $c['page_type'] ?? 'unknown';
            $byPage[$page] = ($byPage[$page] ?? 0) + 1;
            $type = $c['change_type'] ?? 'unknown';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            if (in_array($c['severity'] ?? '', ['high', 'critical'], true)) {
                $high++;
            }
        }
        return [
            'total_changes' => count($changes),
            'high_severity' => $high,
            'by_page' => $byPage,
            'by_type' => $byType,
        ];
    }

    /**
     * توصيات إجرائية قواعد صريحة مقرونة بالأدلة - للمبيعات/المنتجات.
     * @return array<int, array{action:string, rationale:string}>
     */
    private function buildRecommendations(
        ?array $scorecard,
        array $prices,
        array $changes,
        array $strengths,
        array $weaknesses,
        string $name
    ): array {
        $recs = [];

        if (!empty($weaknesses)) {
            $recs[] = [
                'action' => "Address competitor's recorded strengths proactively in sales conversations with {$name}.",
                'rationale' => 'Battlecard detected ' . count($weaknesses) . ' strength area(s) to counter with differentiated positioning.',
            ];
        }

        if ($scorecard !== null) {
            $visibility = (int) ($scorecard['visibility_score'] ?? 0);
            if ($visibility >= self::SCORECARD_STRENGTH_MIN) {
                $recs[] = [
                    'action' => 'Highlight your own market visibility advantage when competitors claim strong presence.',
                    'rationale' => "Competitor visibility score is {$visibility}/100 (high).",
                ];
            }
        }

        $priceChanges = array_filter($changes, fn ($c) => ($c['change_type'] ?? '') === 'pricing_change' || ($c['change_type'] ?? '') === 'offer_change');
        if (count($priceChanges) > 0) {
            $recs[] = [
                'action' => "Re-check your pricing against {$name} - it changed pricing/offers " . count($priceChanges) . " time(s) in the last 30 days.",
                'rationale' => count($priceChanges) . ' pricing/offer change(s) recorded in ci_changes.',
            ];
        }

        $newProducts = array_filter($changes, fn ($c) => ($c['change_type'] ?? '') === 'new_product');
        if (count($newProducts) > 0) {
            $recs[] = [
                'action' => 'Review whether the new product(s) by this competitor overlap your catalog.',
                'rationale' => count($newProducts) . ' new_product change(s) recorded.',
            ];
        }

        $blogActivity = array_filter($changes, fn ($c) => ($c['page_type'] ?? '') === 'blog');
        if (count($blogActivity) > 0 && count($prices) > 0) {
            $recs[] = [
                'action' => 'Monitor this competitor actively - it shows both content publishing and product pricing activity.',
                'rationale' => count($blogActivity) . ' blog change(s) and ' . count($prices) . ' tracked product(s).',
            ];
        }

        if (empty($recs)) {
            $recs[] = [
                'action' => 'Continue scheduled monitoring to build a stronger battlecard for this competitor.',
                'rationale' => 'Limited activity data so far; more monitoring cycles will surface pricing/content signals.',
            ];
        }

        return $recs;
    }

    /** ملخص توضعي مبني من البيانات الفعلية (جُمل وصفية لا تخمين). */
    private function buildPositioningSummary(string $name, ?array $scorecard, array $content, array $pricePosition): string
    {
        $parts = [];
        if ($scorecard !== null) {
            $parts[] = "Scorecard basis: {$scorecard['basis']} (visibility {$scorecard['visibility_score']}, content {$scorecard['content_activity_score']}, offers {$scorecard['offer_activity_score']}, product coverage {$scorecard['product_coverage_score']}, market presence {$scorecard['market_presence_score']}).";
        }
        if ($content['total_changes'] > 0) {
            $parts[] = "Activity: {$content['total_changes']} change(s) in last 30 days ({$content['high_severity']} high-severity).";
        } else {
            $parts[] = 'Activity: no changes recorded in the last 30 days.';
        }
        if (!empty($pricePosition)) {
            $cheapest = null;
            foreach ($pricePosition as $p) {
                if ($cheapest === null || $p['latest_price'] < $cheapest['latest_price']) {
                    $cheapest = $p;
                }
            }
            if ($cheapest !== null) {
                $parts[] = "Pricing: {$cheapest['product']} from {$cheapest['currency']} {$cheapest['latest_price']} (lowest tracked of " . count($pricePosition) . " product(s)).";
            }
        }
        return implode(' ', $parts);
    }

    // ============================ Data helpers ============================

    private function latestScorecard(int $competitorId): ?array
    {
        $rows = Database::getInstance()->query(
            "SELECT * FROM ci_scorecards WHERE competitor_id = ? ORDER BY computed_at DESC, id DESC LIMIT 1",
            [$competitorId]
        );
        return $rows[0] ?? null;
    }

    /** رؤى غير مرفوضة خلال آخر 30 يوم (نوع + عنوان كافيان للبطاقة). */
    private function recentInsights(int $userId, int $competitorId, int $days): array
    {
        return Database::getInstance()->query(
            "SELECT type, title FROM ci_insights
             WHERE user_id = ? AND competitor_id = ? AND status != 'dismissed'
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC LIMIT 20",
            [$userId, $competitorId, $days]
        );
    }

    private function recentChanges(int $competitorId, int $days): array
    {
        return Database::getInstance()->query(
            "SELECT page_type, change_type, severity, detected_at
             FROM ci_changes
             WHERE competitor_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY detected_at DESC LIMIT 500",
            [$competitorId, $days]
        );
    }

    /** يفك أعمدة JSON الخاصة بالبطاقة مع الحفاظ على باقي الحقول. */
    private function decodeBattlecard(array $row): array
    {
        foreach (['strengths', 'weaknesses', 'price_position', 'content_position', 'recommended_actions', 'evidence'] as $col) {
            if (isset($row[$col]) && $row[$col] !== null) {
                $decoded = json_decode((string) $row[$col], true);
                $row[$col] = is_array($decoded) ? $decoded : null;
            } else {
                $row[$col] = null;
            }
        }
        return $row;
    }

    private function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
