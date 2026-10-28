<?php

/**
 * Tourfecto - Competitor Discovery Source Contract
 * @version 1.0.0
 *
 * أي مزوّد بيانات خارجي حقيقي لاكتشاف منافسين (مثال: SimilarWeb،
 * SEMrush، Clearbit، Google Places) لازم يعمل implement للعقد ده بدل
 * ما نكتب استدعاءات خاصة بكل مزوّد داخل CompetitorDiscoveryService
 * مباشرة. لا يوجد أي مزوّد مفعّل افتراضيًا في هذا التسليم (يحتاج API
 * Key حقيقي غير متوفر - انظر "External Integrations Requiring
 * Credentials" في CHANGELOG).
 */
interface CompetitorDiscoverySourceInterface
{
    /**
     * @param array $context ['industry' => ?string, 'country' => ?string, 'my_domain' => ?string]
     * @return array{available:bool, reason:?string, candidates:array} كل عنصر candidate:
     *   ['name'=>string,'website'=>?string,'industry'=>?string,'country'=>?string,
     *    'market_segment'=>?string,'category'=>string,'confidence'=>string]
     */
    public function discover(array $context): array;

    /** اسم المصدر لعرضه في عمود "Source" بالواجهة، مثال: "similarweb" */
    public function sourceName(): string;
}
