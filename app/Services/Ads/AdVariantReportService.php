<?php

/**
 * Tourfecto - Ad/Variant Performance Reports (بند 3)
 * تقارير على مستوى الإعلان/الـ variant بجوار AdReportService (اللي
 * بيغطي مستوى الحملة من ad_performance_reports). البند ده بيكمل الصورة:
 *
 *   - مستوى الحملة: من ad_performance_reports (بيانات مزامنة فعلية).
 *   - مستوى الأصل/الـ variant: من ad_creative_variants (بيانات أداء
 *     خام حقيقية من بند 1) مع نافذة زمنية فعلية عبر `recorded_on`.
 *
 * مبدأ أساسي: لا اختراع بيانات. كل رقم إما من جدول مزامنة فعلي أو من
 * أداء تنويعات خام محفوظ؛ CTR/CPC/CPA/ROAS تُحسب عند القراءة فقط،
 * ولو المقام صفر الناتج null. أي تنويع بدون `recorded_on` لا يدخل
 * تقرير الفترة (لا يمكن إسناده زمنيًا) — يُوثَّق ذلك.
 *
 * @version 1.0.0
 */
class AdVariantReportService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // تقرير شامل (حملات ← أصول ← تنويعات)
    // ================================================================

    /**
     * تقرير متعدد المستويات: لكل حملة (مقاييسها من ad_performance_reports
     * داخل الفترة) أصولها غير المؤرشفة، ولكل أصل تنويعاته ذات `recorded_on`
     * داخل الفترة مع مقاييس محسوبة لكل تنويع وأصل.
     * @return array
     */
    public function generate(int $userId, string $period = 'weekly', ?int $onlyCampaignId = null): array
    {
        $since = $this->periodSince($period);

        $campaigns = (new AdCampaign())->where(['user_id' => $userId], ['id' => 'DESC']);
        if ($onlyCampaignId !== null) {
            $campaigns = array_values(array_filter($campaigns, fn ($c) => (int) $c->getAttribute('id') === $onlyCampaignId));
        }

        $campaignIds = array_map(fn ($c) => (int) $c->getAttribute('id'), $campaigns);
        $campaignMetrics = $this->campaignPeriodMetrics($campaignIds, $since);

        $summary = [
            'campaigns' => 0, 'creatives' => 0, 'variants' => 0,
            'spend' => 0.0, 'clicks' => 0, 'impressions' => 0,
            'conversions' => 0.0, 'revenue' => 0.0,
        ];
        $campaignsOut = [];
        $bestCandidate = null;

        foreach ($campaigns as $campaign) {
            $cid = (int) $campaign->getAttribute('id');
            $cm = $campaignMetrics[$cid] ?? null;

            $creatives = array_values(array_filter(
                (new AdCreative())->where(['user_id' => $userId, 'campaign_id' => $cid]),
                fn ($cr) => $cr->getAttribute('status') !== 'archived'
            ));
            $creativesOut = [];

            foreach ($creatives as $cr) {
                $crId = (int) $cr->getAttribute('id');
                $variants = array_values(array_filter(
                    (new AdCreativeVariant())->where(['user_id' => $userId, 'creative_id' => $crId]),
                    fn ($v) => $v->getAttribute('recorded_on') !== null && $v->getAttribute('recorded_on') >= $since
                ));

                $variantsOut = [];
                $agg = ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0];
                foreach ($variants as $v) {
                    $va = $v->toArray();
                    $m = $this->metrics(
                        (int) ($va['impressions'] ?? 0),
                        (int) ($va['clicks'] ?? 0),
                        (float) ($va['spend'] ?? 0),
                        (float) ($va['conversions'] ?? 0),
                        (float) ($va['revenue'] ?? 0)
                    );
                    $agg['impressions'] += $m['impressions'];
                    $agg['clicks'] += $m['clicks'];
                    $agg['spend'] += $m['spend'];
                    $agg['conversions'] += $m['conversions'];
                    $agg['revenue'] += $m['revenue'];

                    $variantsOut[] = [
                        'variant_id' => (int) $va['id'],
                        'variant_label' => $v->getAttribute('variant_label'),
                        'is_control' => (int) ($va['is_control'] ?? 0),
                        'recorded_on' => $v->getAttribute('recorded_on'),
                        'impressions' => $m['impressions'],
                        'clicks' => $m['clicks'],
                        'spend' => $m['spend'],
                        'conversions' => $m['conversions'],
                        'revenue' => $m['revenue'],
                        'ctr' => $m['ctr'],
                        'cpc' => $m['cpc'],
                        'cpa' => $m['cpa'],
                        'roas' => $m['roas'],
                        'share_of_creative_clicks' => $agg['clicks'] + $m['clicks'] > 0
                            ? round(($m['clicks'] / ($agg['clicks'] + $m['clicks'])) * 100, 1)
                            : null,
                    ];
                    $bestCandidate = $this->trackBestVariant($bestCandidate, $variantsOut[count($variantsOut) - 1], 50);
                }

                $cAgg = $this->metrics($agg['impressions'], $agg['clicks'], $agg['spend'], $agg['conversions'], $agg['revenue']);
                $creativesOut[] = [
                    'creative_id' => $crId,
                    'name' => $cr->getAttribute('name'),
                    'creative_type' => $cr->getAttribute('creative_type'),
                    'status' => $cr->getAttribute('status'),
                    'variant_count' => count($variantsOut),
                    'metrics' => $cAgg,
                    'variants' => $variantsOut,
                ];
                $summary['variants'] += count($variantsOut);
            }

            $summary['campaigns']++;
            $summary['creatives'] += count($creativesOut);

            $campaignsOut[] = [
                'campaign_id' => $cid,
                'name' => $campaign->getAttribute('name'),
                'status' => $campaign->getAttribute('status'),
                'campaign_metrics' => $cm ? $this->metrics(
                    (int) $cm['impressions'], (int) $cm['clicks'],
                    (float) $cm['spend'], (float) $cm['conversions'], (float) ($cm['revenue'] ?? 0)
                ) : null,
                'creatives' => $creativesOut,
            ];

            if ($cm) {
                $summary['spend'] += (float) $cm['spend'];
                $summary['clicks'] += (int) $cm['clicks'];
                $summary['impressions'] += (int) $cm['impressions'];
                $summary['conversions'] += (float) $cm['conversions'];
                $summary['revenue'] += (float) ($cm['revenue'] ?? 0);
            }
        }

        $summary['cpa'] = $summary['conversions'] > 0 ? round($summary['spend'] / $summary['conversions'], 2) : null;
        $summary['roas'] = $summary['spend'] > 0 ? round($summary['revenue'] / $summary['spend'], 2) : null;
        $summary['ctr'] = $summary['impressions'] > 0
            ? round(($summary['clicks'] / $summary['impressions']) * 100, 3)
            : null;

        return [
            'period' => $period,
            'since' => $since,
            'has_data' => $summary['campaigns'] > 0,
            'summary' => $summary,
            'campaigns' => $campaignsOut,
            'best_variant' => $bestCandidate,
        ];
    }

    // ================================================================
    // تفصيلات
    // ================================================================

    /** تفصيل أصل إعلاني واحد (كل الفترات) */
    public function creativeBreakdown(int $userId, int $creativeId): ?array
    {
        $creative = $this->findOwnedCreative($userId, $creativeId);
        if (!$creative) {
            return null;
        }
        $variants = (new AdCreativeVariant())->where(['user_id' => $userId, 'creative_id' => $creativeId]);
        $variantsOut = [];
        foreach ($variants as $v) {
            $va = $v->toArray();
            $m = $this->metrics(
                (int) ($va['impressions'] ?? 0),
                (int) ($va['clicks'] ?? 0),
                (float) ($va['spend'] ?? 0),
                (float) ($va['conversions'] ?? 0),
                (float) ($va['revenue'] ?? 0)
            );
            $variantsOut[] = $m + [
                'variant_id' => (int) $va['id'],
                'variant_label' => $v->getAttribute('variant_label'),
                'is_control' => (int) ($va['is_control'] ?? 0),
                'recorded_on' => $v->getAttribute('recorded_on'),
            ];
        }
        return [
            'creative' => $creative->toArray(),
            'campaign_id' => (int) $creative->getAttribute('campaign_id'),
            'variants' => $variantsOut,
            'best_variant' => $this->bestOf($variantsOut, 50),
        ];
    }

    /** تفصيل حملة واحدة (أصولها وتنويعاتها - كل الفترات) */
    public function campaignBreakdown(int $userId, int $campaignId): ?array
    {
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== $userId) {
            return null;
        }
        $creatives = array_values(array_filter(
            (new AdCreative())->where(['user_id' => $userId, 'campaign_id' => $campaignId]),
            fn ($cr) => $cr->getAttribute('status') !== 'archived'
        ));
        $creativesOut = [];
        foreach ($creatives as $cr) {
            $crId = (int) $cr->getAttribute('id');
            $variants = (new AdCreativeVariant())->where(['user_id' => $userId, 'creative_id' => $crId]);
            $variantsOut = [];
            foreach ($variants as $v) {
                $va = $v->toArray();
                $m = $this->metrics(
                    (int) ($va['impressions'] ?? 0),
                    (int) ($va['clicks'] ?? 0),
                    (float) ($va['spend'] ?? 0),
                    (float) ($va['conversions'] ?? 0),
                    (float) ($va['revenue'] ?? 0)
                );
                $variantsOut[] = $m + [
                    'variant_id' => (int) $va['id'],
                    'variant_label' => $v->getAttribute('variant_label'),
                    'is_control' => (int) ($va['is_control'] ?? 0),
                    'recorded_on' => $v->getAttribute('recorded_on'),
                ];
            }
            $creativesOut[] = [
                'creative_id' => $crId,
                'name' => $cr->getAttribute('name'),
                'creative_type' => $cr->getAttribute('creative_type'),
                'status' => $cr->getAttribute('status'),
                'variants' => $variantsOut,
                'best_variant' => $this->bestOf($variantsOut, 50),
            ];
        }
        return [
            'campaign' => $campaign->toArray(),
            'creatives' => $creativesOut,
        ];
    }

    /** تفصيل تنويع واحد (كل الفترات) */
    public function variantBreakdown(int $userId, int $variantId): ?array
    {
        $variant = $this->findOwnedVariant($userId, $variantId);
        if (!$variant) {
            return null;
        }
        $va = $variant->toArray();
        $m = $this->metrics(
            (int) ($va['impressions'] ?? 0),
            (int) ($va['clicks'] ?? 0),
            (float) ($va['spend'] ?? 0),
            (float) ($va['conversions'] ?? 0),
            (float) ($va['revenue'] ?? 0)
        );
        $creative = (new AdCreative())->find((int) $variant->getAttribute('creative_id'));
        return $m + [
            'variant_id' => (int) $va['id'],
            'variant_label' => $variant->getAttribute('variant_label'),
            'is_control' => (int) ($va['is_control'] ?? 0),
            'recorded_on' => $variant->getAttribute('recorded_on'),
            'creative' => $creative ? [
                'creative_id' => (int) $creative->getAttribute('id'),
                'name' => $creative->getAttribute('name'),
                'creative_type' => $creative->getAttribute('creative_type'),
                'campaign_id' => (int) $creative->getAttribute('campaign_id'),
            ] : null,
        ];
    }

    /** ملخص تنويعات شامل (كل الفترات) */
    public function variantSummary(int $userId): array
    {
        $variants = (new AdCreativeVariant())->where(['user_id' => $userId], ['id' => 'DESC']);
        $rows = [];
        $totals = ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0];
        foreach ($variants as $v) {
            $va = $v->toArray();
            $m = $this->metrics(
                (int) ($va['impressions'] ?? 0),
                (int) ($va['clicks'] ?? 0),
                (float) ($va['spend'] ?? 0),
                (float) ($va['conversions'] ?? 0),
                (float) ($va['revenue'] ?? 0)
            );
            $rows[] = $m + [
                'variant_id' => (int) $va['id'],
                'variant_label' => $v->getAttribute('variant_label'),
                'creative_id' => (int) ($va['creative_id'] ?? 0),
                'is_control' => (int) ($va['is_control'] ?? 0),
            ];
            foreach (['impressions', 'clicks', 'spend', 'conversions', 'revenue'] as $k) {
                $totals[$k] += $m[$k];
            }
        }
        $totals['ctr'] = $totals['impressions'] > 0 ? round(($totals['clicks'] / $totals['impressions']) * 100, 3) : null;
        $totals['cpc'] = $totals['clicks'] > 0 ? round($totals['spend'] / $totals['clicks'], 2) : null;
        $totals['cpa'] = $totals['conversions'] > 0 ? round($totals['spend'] / $totals['conversions'], 2) : null;
        $totals['roas'] = $totals['spend'] > 0 ? round($totals['revenue'] / $totals['spend'], 2) : null;
        return ['totals' => $totals, 'variants' => $rows, 'best_variant' => $this->bestOf($rows, 50)];
    }

    /**
     * أفضل تنويع أداءً (أعلى CTR) مع كفاية حد أدنى من الانطباعات داخل فترة
     * @return array|null
     */
    public function bestVariant(int $userId, int $minImpressions = 50, string $period = 'weekly', ?int $onlyCampaignId = null): ?array
    {
        $since = $this->periodSince($period);
        $campaigns = (new AdCampaign())->where(['user_id' => $userId]);
        if ($onlyCampaignId !== null) {
            $campaigns = array_values(array_filter($campaigns, fn ($c) => (int) $c->getAttribute('id') === $onlyCampaignId));
        }
        $best = null;
        foreach ($campaigns as $campaign) {
            $creatives = array_values(array_filter(
                (new AdCreative())->where(['user_id' => $userId, 'campaign_id' => (int) $campaign->getAttribute('id')]),
                fn ($cr) => $cr->getAttribute('status') !== 'archived'
            ));
            foreach ($creatives as $cr) {
                $crId = (int) $cr->getAttribute('id');
                $variants = array_values(array_filter(
                    (new AdCreativeVariant())->where(['user_id' => $userId, 'creative_id' => $crId]),
                    fn ($v) => $v->getAttribute('recorded_on') !== null && $v->getAttribute('recorded_on') >= $since
                ));
                foreach ($variants as $v) {
                    $va = $v->toArray();
                    $m = $this->metrics(
                        (int) ($va['impressions'] ?? 0),
                        (int) ($va['clicks'] ?? 0),
                        (float) ($va['spend'] ?? 0),
                        (float) ($va['conversions'] ?? 0),
                        (float) ($va['revenue'] ?? 0)
                    );
                    if ($m['impressions'] < $minImpressions || $m['ctr'] === null) {
                        continue;
                    }
                    $candidate = $m + [
                        'variant_id' => (int) $va['id'],
                        'variant_label' => $v->getAttribute('variant_label'),
                        'creative_id' => $crId,
                        'creative_name' => $cr->getAttribute('name'),
                        'campaign_id' => (int) $campaign->getAttribute('id'),
                        'campaign_name' => $campaign->getAttribute('name'),
                    ];
                    $best = $this->trackBestVariant($best, $candidate, $minImpressions);
                }
            }
        }
        return $best;
    }

    // ================================================================
    // مساعدون
    // ================================================================

    private function periodSince(string $period): string
    {
        $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$period] ?? 7;
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    /** مقاييس الحملات داخل الفترة من ad_performance_reports (بيانات مزامنة فعلية) */
    private function campaignPeriodMetrics(array $campaignIds, string $since): array
    {
        if (empty($campaignIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $rows = $this->db->query(
            "SELECT campaign_id, SUM(spend) AS spend, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    SUM(conversions) AS conversions, SUM(revenue) AS revenue
             FROM ad_performance_reports
             WHERE campaign_id IN ({$placeholders}) AND date_start >= ?
             GROUP BY campaign_id",
            array_merge($campaignIds, [$since])
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['campaign_id']] = $r;
        }
        return $out;
    }

    /** مقاييس محسوبة عند القراءة فقط - المقام صفر = null (لا اختراع أرقام) */
    private function metrics(int $impressions, int $clicks, float $spend, float $conversions, float $revenue): array
    {
        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => round($spend, 2),
            'conversions' => $conversions,
            'revenue' => round($revenue, 2),
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 3) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
            'cpa' => $conversions > 0 ? round($spend / $conversions, 2) : null,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
        ];
    }

    private function bestOf(array $rows, int $minImpressions): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ((int) $row['impressions'] < $minImpressions || $row['ctr'] === null) {
                continue;
            }
            $best = $this->trackBestVariant($best, $row, $minImpressions);
        }
        return $best;
    }

    /** @param array|null $best */
    private function trackBestVariant(?array $best, array $candidate, int $minImpressions): ?array
    {
        if ((int) $candidate['impressions'] < $minImpressions || $candidate['ctr'] === null) {
            return $best;
        }
        if ($best === null || (float) $candidate['ctr'] > (float) $best['ctr']) {
            return $candidate;
        }
        return $best;
    }

    private function findOwnedCreative(int $userId, int $creativeId): ?AdCreative
    {
        $creative = (new AdCreative())->find($creativeId);
        if (!$creative || (int) $creative->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $creative;
    }

    private function findOwnedVariant(int $userId, int $variantId): ?AdCreativeVariant
    {
        $variant = (new AdCreativeVariant())->find($variantId);
        if (!$variant || (int) $variant->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $variant;
    }
}
