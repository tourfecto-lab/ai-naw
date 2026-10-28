<?php

/**
 * Tourfecto - SEO: Null Keyword Research Source (G4) - fail-safe
 * @version 1.0.0
 *
 * المصدر الافتراضي لما مفيش أي مزوّد Keyword Intelligence متظبط.
 * بيرجع available=false بسبب واضح من غير أي بيانات - منعًا للاختلاق.
 */
class NullKeywordResearchSource implements KeywordResearchSourceInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function sourceName(): string
    {
        return 'null';
    }

    public function getKeywordData(array $keywords): array
    {
        return [
            'available' => false,
            'reason' => 'no_keyword_research_source_configured',
            'data' => [],
        ];
    }
}
