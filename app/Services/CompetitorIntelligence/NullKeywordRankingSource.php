<?php

/**
 * Tourfecto - Competitor Intelligence: Null Keyword Ranking Source (G1)
 * @version 1.0.0
 *
 * التنفيذ الافتراضي لـ KeywordRankingSourceInterface طالما مفيش مزوّد
 * SERP خارجي حقيقي مُعدّ (مفتاح API). بيرجّع isConfigured()=false و
 * check() برجع available=false بسبب واضح بدل اختلاق ترتيبات وهمية -
 * طبقًا لقاعدة "NO FAKE DATA" (نفس نمط NullDiscoverySource).
 */
class NullKeywordRankingSource implements KeywordRankingSourceInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function sourceName(): string
    {
        return 'none';
    }

    public function check(string $domain, array $keywords): array
    {
        return [
            'available' => false,
            'reason' => 'no_keyword_ranking_integration_configured',
            'rankings' => [],
        ];
    }
}
