<?php

/**
 * Tourfecto - Outreach Prospect Discovery Source Interface
 * مصدر مرشّحين للـ Outreach (الاكتشاف). أي مصدر لازم يرجّع قائمة
 * مرشّحين ببيانات عامة معلنة فقط (domain / business_type / relevant_page)
 * — ممنوع استخراج بيانات تواصل شخصية (WHOIS/إيميلات خاصة) من أي مصدر.
 * @version 1.0.0
 */
interface ProspectDiscoverySourceInterface
{
    /** اسم المصدر للتتبع في السجلات */
    public function sourceName(): string;

    /**
     * @param array $context ['user_id'=>int, 'website_id'=>int]
     * @return array{available:bool, reason:?string, candidates:array[]}
     *         candidate: ['domain'=>string, 'business_type'=>?string,
     *                     'relevant_page'=>?string, 'collaboration_idea'=>?string,
     *                     'signals'=>array]  (signals: بيانات عامة تُستخدم في حساب relevance_score)
     */
    public function discover(array $context): array;
}
