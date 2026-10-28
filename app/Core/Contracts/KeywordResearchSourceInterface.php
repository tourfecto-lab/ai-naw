<?php

/**
 * Tourfecto - SEO: Keyword Research Source Interface (G4)
 * @version 1.0.0
 *
 * عقد أي مصدر خارجي لبيانات الكلمات المفتاحية (حجم بحث/صعوبة/SERP) مثل
 * Google Keyword Planner API أو مزوّد Keyword Intelligence خارجي. التنفيذ
 * الافتراضي NullKeywordResearchSource بيقول إنه مش مهيأ (available=false)
 * بدل اختلاق أرقام - طبقًا لقاعدة NO FAKE DATA.
 */
interface KeywordResearchSourceInterface
{
    /** هل المصدر مهيأ فعليًا (مفاتيح/إعدادات موجودة) ويمكن استدعاؤه؟ */
    public function isConfigured(): bool;

    /** اسم المصدر للتوثيق. */
    public function sourceName(): string;

    /**
     * جلب بيانات بحث حقيقية لمجموعة كلمات.
     *
     * @param string[] $keywords
     * @return array{available:bool, reason:?string,
     *               data:array<string, array{search_volume:?int, difficulty:?int}>}
     */
    public function getKeywordData(array $keywords): array;
}
