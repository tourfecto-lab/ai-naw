<?php

/**
 * Tourfecto - Competitor Intelligence: Threat & Opportunity Detection
 * @version 1.0.0
 *
 * محرك قواعد شفاف (rules-based) - كل Threat/Opportunity مربوط بدليل
 * (evidence) صريح من ci_changes الحقيقية. لا يستخدم AI هنا (AI منفصل
 * في AICompetitiveAnalyst لتوليد رؤى نصية إضافية فوق نفس البيانات).
 */
class ThreatOpportunityService
{
    public function scanCompetitor(Competitor $competitor, int $days = 30): array
    {
        $competitorId = (int) $competitor->getAttribute('id');
        $userId = (int) $competitor->getAttribute('user_id');
        $websiteId = (int) $competitor->getAttribute('website_id');
        $name = (string) ($competitor->getAttribute('competitor_name') ?: $competitor->getAttribute('competitor_domain'));

        $db = Database::getInstance();
        $changes = $db->query(
            "SELECT * FROM `ci_changes` WHERE competitor_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY detected_at DESC",
            [$competitorId, $days]
        );

        $insights = [];

        // --- New Competitor / Market Entry ---
        // منافس اتضاف حديثًا (آخر 14 يوم) وعنده نشاط مُلاحَظ بالفعل خلال
        // نفس الفترة = دخول سوق جدير بالانتباه. دليل حقيقي: تاريخ الإضافة
        // الفعلي + عدد تغييرات حقيقية، مش تخمين.
        $createdAt = $competitor->getAttribute('created_at');
        if ($createdAt && strtotime($createdAt) >= strtotime('-14 days') && count($changes) >= 1) {
            $insights[] = $this->save(
                $userId,
                $websiteId,
                $competitorId,
                'threat',
                "New market entrant: {$name}",
                'This competitor was added to monitoring in the last 14 days and already shows ' . count($changes) . ' recorded change(s) - an active new entrant worth watching closely.',
                'Competitor added_at=' . $createdAt . "; changes_in_period={$this->evidenceList(array_slice($changes, 0, 5))}",
                'medium',
                'medium',
                'Review this competitor\'s positioning and consider tightening its monitoring frequency to daily.'
            );
        }

        // --- Threats ---
        $criticalOrHigh = array_filter($changes, fn ($c) => in_array($c['severity'], ['high', 'critical'], true));
        if (count($criticalOrHigh) >= 3) {
            $insights[] = $this->save(
                $userId,
                $websiteId,
                $competitorId,
                'threat',
                "Aggressive activity from {$name}",
                count($criticalOrHigh) . " high/critical-severity changes detected in the last {$days} days.",
                $this->evidenceList($criticalOrHigh),
                'high',
                'high',
                'Review your own pricing/offers and consider closer monitoring of this competitor.'
            );
        }

        $pricingDrops = array_filter($changes, fn ($c) => $c['change_type'] === 'pricing_change');
        if (!empty($pricingDrops)) {
            $insights[] = $this->save(
                $userId,
                $websiteId,
                $competitorId,
                'threat',
                "Pricing change from {$name}",
                'Competitor changed publicly listed pricing.',
                $this->evidenceList($pricingDrops),
                'medium',
                'medium',
                'Review your pricing page and confirm it remains competitive.'
            );
        }

        $newOffers = array_filter($changes, fn ($c) => $c['change_type'] === 'offer_change');
        if (count($newOffers) >= 2) {
            $insights[] = $this->save(
                $userId,
                $websiteId,
                $competitorId,
                'threat',
                "Repeated new offers from {$name}",
                count($newOffers) . ' offer changes detected - competitor is actively promoting.',
                $this->evidenceList($newOffers),
                'medium',
                'medium',
                'Consider creating a stronger, time-limited offer of your own.'
            );
        }

        // --- Opportunities ---
        $removedPages = array_filter($changes, fn ($c) => $c['change_type'] === 'removed_page');
        if (!empty($removedPages)) {
            $insights[] = $this->save(
                $userId,
                $websiteId,
                $competitorId,
                'opportunity',
                "{$name} removed a service/page",
                'A previously monitored page for this competitor is no longer reachable - may indicate a discontinued service.',
                $this->evidenceList($removedPages),
                'low',
                null,
                'Investigate whether this represents an unserved market gap you can fill.'
            );
        }

        $noActivity = empty($changes);
        $everMonitored = (bool) $competitor->getAttribute('last_monitored_at');
        if ($noActivity && $everMonitored) {
            $insights[] = $this->save(
                $userId,
                $websiteId,
                $competitorId,
                'opportunity',
                "Weak recent activity from {$name}",
                "No changes detected across monitored public pages in the last {$days} days.",
                'Based on ' . $days . ' days of monitoring with no recorded ci_changes rows.',
                'low',
                null,
                'This competitor may have weak content/offer coverage right now - a window to gain visibility.'
            );
        }

        return $insights;
    }

    private function evidenceList(array $changes): string
    {
        $lines = [];
        foreach (array_slice($changes, 0, 5) as $c) {
            $lines[] = "[{$c['detected_at']}] {$c['page_type']}: {$c['change_type']} (severity={$c['severity']}, confidence={$c['confidence']})";
        }
        return implode("\n", $lines);
    }

    private function save(int $userId, int $websiteId, int $competitorId, string $type, string $title, string $description, string $evidence, string $confidence, ?string $threatLevel, string $recommendedAction): CiInsight
    {
        $insight = new CiInsight([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'competitor_id' => $competitorId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'evidence' => $evidence,
            'confidence' => $confidence,
            'threat_level' => $threatLevel,
            'recommended_action' => $recommendedAction,
            'status' => 'new',
            'generated_by' => 'rules_engine',
        ]);
        $insight->save();
        return $insight;
    }
}
