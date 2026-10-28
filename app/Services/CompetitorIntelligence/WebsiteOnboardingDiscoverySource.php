<?php

/**
 * Tourfecto - Competitor Intelligence: Website Onboarding Discovery Source
 * @version 1.0.0
 *
 * مصدر اكتشاف مجاني بالكامل ومتاح فورًا بدون أي API Key: جدول
 * `websites` عنده بالفعل 3 أعمدة (competitor_1_url, competitor_2_url,
 * competitor_3_url) بتتملى وقت الـ onboarding لو المستخدم دخّلهم -
 * بيانات حقيقية أدخلها المستخدم نفسه، مش مُخترعة. لو موجودة ولسه مش
 * مُضافة لجدول competitors، بيقترحها كمرشّحين بثقة عالية (المستخدم نفسه
 * قال إنهم منافسين).
 */
class WebsiteOnboardingDiscoverySource implements CompetitorDiscoverySourceInterface
{
    public function discover(array $context): array
    {
        $websiteId = (int) ($context['website_id'] ?? 0);
        $userId = (int) ($context['user_id'] ?? 0);

        if ($websiteId <= 0) {
            return ['available' => false, 'reason' => 'missing_website_id', 'candidates' => []];
        }

        $db = Database::getInstance();
        $rows = $db->query("SELECT competitor_1_url, competitor_2_url, competitor_3_url, industry_niche AS industry, target_country FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        if (empty($rows)) {
            return ['available' => false, 'reason' => 'website_not_found', 'candidates' => []];
        }
        $website = $rows[0];

        $urls = array_filter([
            $website['competitor_1_url'] ?? null,
            $website['competitor_2_url'] ?? null,
            $website['competitor_3_url'] ?? null,
        ]);

        if (empty($urls)) {
            return ['available' => false, 'reason' => 'no_onboarding_competitor_urls_saved', 'candidates' => []];
        }

        // نستبعد أي دومين موجود بالفعل في competitors لنفس المستخدم -
        // مفيش داعي نقترح حاجة مُضافة أصلاً.
        $existingDomains = [];
        if ($userId > 0) {
            $existing = $db->query("SELECT competitor_domain FROM competitors WHERE user_id = ?", [$userId]);
            foreach ($existing as $e) {
                $existingDomains[] = CompetitorDomain::host((string) $e['competitor_domain']) ?? '';
            }
        }

        $candidates = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $host = CompetitorDomain::host($url) ?? strtolower($url);

            if (in_array($host, $existingDomains, true)) {
                continue; // مُضاف بالفعل
            }

            $candidates[] = [
                'name' => $host, // مفيش اسم شركة مُدخَل وقت onboarding - الدومين هو أوثق قيمة متاحة
                'website' => $url,
                'industry' => $website['industry'] ?? null,
                'country' => $website['target_country'] ?? null,
                'category' => 'direct', // المستخدم نفسه حدده كمنافس وقت الإعداد
                'confidence' => 'high', // مصدره المستخدم نفسه، مش استنتاج آلي
            ];
        }

        return [
            'available' => !empty($candidates),
            'reason' => empty($candidates) ? 'all_onboarding_urls_already_added' : null,
            'candidates' => $candidates,
        ];
    }

    public function sourceName(): string
    {
        return 'website_onboarding';
    }
}
