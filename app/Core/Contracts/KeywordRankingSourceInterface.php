<?php

/**
 * Tourfecto - Competitor Intelligence: Keyword Ranking Source Interface (G1)
 * @version 1.0.0
 *
 * عقد أي مصدر خارجي لجمع ترتيبات SERP الفعلية (مثل مزوّد Keyword
 * Tracking API). التنفيذ الافتراضي هو NullKeywordRankingSource اللي
 * بيقول إنه مش مهيأ (available=false) بدل اختلاق ترتيبات - طبقًا
 * لقاعدة NO FAKE DATA.
 */
interface KeywordRankingSourceInterface
{
    /**
     * هل المصدر مهيأ فعليًا (مفاتيح/إعدادات موجودة) ويمكن فحصه؟
     */
    public function isConfigured(): bool;

    /**
     * اسم المصدر للتوثيق (يُخزَّن في ci_keyword_rankings.source
     * كـ integration:{name}).
     */
    public function sourceName(): string;

    /**
     * يفحص ترتيب مجموعة كلمات مفتاحية لدومين منافس.
     *
     * @param string   $domain   دومين المنافس (بدون protocol)
     * @param string[] $keywords الكلمات المطلوب فحصها
     * @return array{available:bool, reason:?string,
     *               rankings:array<int, array{keyword:string, position:?int, url:?string}>}
     */
    public function check(string $domain, array $keywords): array;
}
