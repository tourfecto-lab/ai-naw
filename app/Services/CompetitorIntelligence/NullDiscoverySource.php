<?php

/**
 * Tourfecto - Competitor Intelligence: Null Discovery Source
 * @version 1.0.0
 *
 * التنفيذ الافتراضي لـ CompetitorDiscoverySourceInterface طالما مفيش
 * مزوّد بيانات خارجي حقيقي مُعدّ (مفتاح API). بيرجّع available=false
 * وسبب واضح بدل اختلاق منافسين وهميين - طبقًا لقاعدة "NO FAKE DATA".
 */
class NullDiscoverySource implements CompetitorDiscoverySourceInterface
{
    public function discover(array $context): array
    {
        return [
            'available' => false,
            'reason' => 'no_discovery_integration_configured',
            'candidates' => [],
        ];
    }

    public function sourceName(): string
    {
        return 'none';
    }
}
