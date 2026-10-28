<?php

/**
 * Tourfecto - Competitor Intelligence: Discovery Service
 * @version 1.0.0
 *
 * وظيفتان فقط:
 * 1) suggestManualCandidate() - المستخدم يقترح اسم/دومين منافس بسرعة
 *    (بدون كل تفاصيل الإضافة الكاملة) فيُحفظ كـ "مرشّح" (pending) قابل
 *    للموافقة لاحقًا - يُظهر بوضوح source=manual_hint وconfidence=low
 *    (لسه مش مُتحقَّق منه).
 * 2) runExternalDiscovery() - يستدعي أي CompetitorDiscoverySourceInterface
 *    مُسجَّل (integration خارجي حقيقي). لو مفيش أي مصدر مفعّل، يرجّع
 *    بوضوح "insufficient data" بدل اختلاق نتائج.
 *
 * لا يخترع شركات أبدًا.
 */
class CompetitorDiscoveryService
{
    /** @var CompetitorDiscoverySourceInterface[] */
    private $sources;

    public function __construct(array $sources = [])
    {
        $this->sources = !empty($sources) ? $sources : [new NullDiscoverySource()];
    }

    public function suggestManualCandidate(int $userId, int $websiteId, string $name, string $website = '', string $industry = '', string $country = ''): CiDiscoveryCandidate
    {
        $candidate = new CiDiscoveryCandidate([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'competitor_name' => $name,
            'website' => $website ?: null,
            'industry' => $industry ?: null,
            'country' => $country ?: null,
            'source' => 'manual_hint',
            'category' => 'potential',
            'confidence' => 'low',
            'status' => 'pending',
            'discovered_at' => date('Y-m-d H:i:s'),
        ]);
        $candidate->save();

        ActivityLog::record('competitor_intelligence', 'discovery.candidate_suggested', [
            'user_id' => $userId, 'subject_type' => 'ci_discovery_candidates', 'subject_id' => (int) $candidate->getAttribute('id'),
        ]);

        return $candidate;
    }

    /**
     * @return array{available:bool, reason:?string, candidates_saved:int}
     */
    public function runExternalDiscovery(int $userId, int $websiteId, array $context = []): array
    {
        $anyAvailable = false;
        $reasons = [];
        $saved = 0;

        // نضيف user_id/website_id دايمًا للسياق - مصادر زي
        // WebsiteOnboardingDiscoverySource محتاجاهم لقراءة بيانات
        // الموقع/استبعاد المنافسين المُضافين بالفعل.
        $context = array_merge($context, ['user_id' => $userId, 'website_id' => $websiteId]);

        foreach ($this->sources as $source) {
            $result = $source->discover($context);

            if (!($result['available'] ?? false)) {
                $reasons[] = $source->sourceName() . ':' . ($result['reason'] ?? 'unavailable');
                continue;
            }

            $anyAvailable = true;
            foreach ($result['candidates'] as $c) {
                if (empty($c['name'])) {
                    continue; // لا نحفظ مرشّح بدون اسم حقيقي مصدره المزوّد
                }
                $candidate = new CiDiscoveryCandidate([
                    'user_id' => $userId,
                    'website_id' => $websiteId,
                    'competitor_name' => (string) $c['name'],
                    'website' => $c['website'] ?? null,
                    'industry' => $c['industry'] ?? null,
                    'country' => $c['country'] ?? null,
                    'market_segment' => $c['market_segment'] ?? null,
                    'source' => 'integration:' . $source->sourceName(),
                    'category' => CiConstants::within(CiConstants::CATEGORIES, $c['category'] ?? '', 'potential'),
                    'confidence' => CiConstants::within(CiConstants::CONFIDENCE_LEVELS, $c['confidence'] ?? '', 'low'),
                    'status' => 'pending',
                    'discovered_at' => date('Y-m-d H:i:s'),
                ]);
                $candidate->save();
                $saved++;
            }
        }

        return [
            'available' => $anyAvailable,
            'reason' => $anyAvailable ? null : ('insufficient_data: ' . implode(', ', $reasons)),
            'candidates_saved' => $saved,
        ];
    }

    /** الموافقة على مرشّح -> إضافته فعليًا لجدول competitors */
    public function approveCandidate(CiDiscoveryCandidate $candidate): Competitor
    {
        $competitor = new Competitor([
            'user_id' => (int) $candidate->getAttribute('user_id'),
            'website_id' => (int) $candidate->getAttribute('website_id'),
            'competitor_name' => (string) $candidate->getAttribute('competitor_name'),
            'competitor_domain' => (string) ($candidate->getAttribute('website') ?: ''),
            'industry' => $candidate->getAttribute('industry'),
            'country' => $candidate->getAttribute('country'),
            'market_segment' => $candidate->getAttribute('market_segment'),
            'category' => $candidate->getAttribute('category') ?: 'potential',
            'source' => 'discovery',
            'discovery_confidence' => $candidate->getAttribute('confidence'),
            'is_active' => 1,
        ]);
        $competitor->save();

        $candidate->setAttribute('status', 'added');
        $candidate->save();

        ActivityLog::record('competitor_intelligence', 'discovery.candidate_approved', [
            'user_id' => (int) $candidate->getAttribute('user_id'),
            'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
        ]);

        return $competitor;
    }

    public function dismissCandidate(CiDiscoveryCandidate $candidate): void
    {
        $candidate->setAttribute('status', 'dismissed');
        $candidate->save();
    }
}
