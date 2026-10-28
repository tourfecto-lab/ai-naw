<?php

/** Real DataForSEO referring-domain gaps feed outreach. The separately named
 * discoverTrackedCompetitorSites() retains legacy competitor-site discovery,
 * and is never presented as measured backlink evidence. */
class CompetitorBacklinkDiscoverySource implements ProspectDiscoverySourceInterface
{
    /** أنواع الصفحات الأكثر صلة بذكر/ربط محتوى سياحي */
    private const RELEVANT_PAGE_TYPES = ['blog', 'homepage', 'contact', 'landing', 'offers'];

    public function sourceName(): string
    {
        return 'competitor_backlinks';
    }

    public function discover(array $context): array
    {
        $userId = (int) ($context['user_id'] ?? 0);
        $websiteId = (int) ($context['website_id'] ?? 0);
        if (!$userId || !$websiteId) {
            return ['available' => false, 'reason' => 'missing_context', 'candidates' => []];
        }

        $db = Database::getInstance();
        return (new DataForSeoLinkGapService($db))->discover($userId,$websiteId);


    }

    public function discoverTrackedCompetitorSites(array $context): array
    {
        $userId=(int)($context['user_id']??0);$websiteId=(int)($context['website_id']??0);
        if(!$userId||!$websiteId)return ['available'=>false,'candidates'=>[]];
        $db=Database::getInstance();
        $competitors = $db->query(
            "SELECT id, competitor_domain, competitor_name, competitor_score, my_score, last_analyzed_at
             FROM competitors
             WHERE user_id = ? AND website_id = ? AND is_active = 1
               AND competitor_domain IS NOT NULL AND TRIM(competitor_domain) <> ''
             ORDER BY COALESCE(competitor_score, 0) DESC
             LIMIT 30",
            [$userId, $websiteId]
        );

        if (empty($competitors)) {
            return ['available' => false, 'reason' => 'no_tracked_competitors', 'candidates' => []];
        }

        $candidates = [];
        foreach ($competitors as $c) {
            $domain = $this->normalizeDomain((string) $c['competitor_domain']);
            if ($domain === '') {
                continue;
            }

            $snapshot = $this->pickRelevantSnapshot($db, (int) $c['id']);
            $name = trim((string) ($c['competitor_name'] ?? ''));

            $candidates[] = [
                'domain' => $domain,
                'business_type' => $this->inferBusinessType($snapshot, $name),
                'relevant_page' => $snapshot !== null
                    ? (string) $snapshot['url']
                    : 'https://' . $domain,
                'collaboration_idea' => 'ذكر/رابط لمحتوى سياحي ذي صلة في صفحة/دليل مرتبط بمجال نشاطك',
                'signals' => [
                    'competitor_score' => (float) ($c['competitor_score'] ?? 0),
                    'has_snapshot' => $snapshot !== null,
                    'business_type' => $snapshot['page_type'] ?? null,
                    'name' => $name,
                ],
            ];
        }

        if (empty($candidates)) {
            return ['available' => false, 'reason' => 'no_valid_candidate_domains', 'candidates' => []];
        }

        return ['available' => true, 'reason' => null, 'candidates' => $candidates];
    }

    /**
     * صفحة ذات صلة من آخر لقطة للمنافس (بيانات عامة معلنة).
     */
    private function pickRelevantSnapshot(Database $db, int $competitorId): ?array
    {
        $rows = $db->query(
            "SELECT page_type, url FROM ci_snapshots
             WHERE competitor_id = ? AND fetch_status = 'ok' AND http_status = 200
             ORDER BY FIELD(page_type, 'blog', 'homepage', 'contact', 'landing', 'offers'), captured_at DESC
             LIMIT 1",
            [$competitorId]
        );
        return $rows[0] ?? null;
    }

    private function inferBusinessType(?array $snapshot, string $name): ?string
    {
        $pageType = $snapshot['page_type'] ?? null;
        $label = match ($pageType) {
            'blog' => 'مدونة/محتوى في نفس المجال',
            'homepage' => 'موقع سياحي/نشاط محلي',
            'contact' => 'موقع محلي في نفس المجال',
            'landing' => 'صفحة هبوط لخدمة سياحية',
            'offers' => 'موقع عروض/حزم سياحية',
            default => null,
        };
        if ($label !== null) {
            return $label;
        }
        return $name !== '' ? 'موقع في نفس المجال' : null;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim((string) $domain);
        $domain = preg_replace('~^https?://~i', '', $domain);
        $domain = preg_replace('~^www\.~i', '', $domain);
        $domain = preg_replace('~[/?#].*$~', '', $domain);
        return mb_strtolower(trim($domain));
    }
}
