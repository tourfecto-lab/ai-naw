<?php

/**
 * Tourfecto - Business Context Service
 * Business Control Center - Phase 6 (أهم نقطة في الطلب الأصلي)
 * @version 1.0.0
 *
 * ده الـSingle Source of Truth المطلوب صراحة: أي AI Module (مقال، Meta
 * description، FAQ، Google Business Post، Review response، Social
 * post، SEO recommendation...) لازم يستدعي getContext() هنا بدل ما
 * يستعلم من Business/BusinessLocation/BusinessService/
 * BusinessTargetMarket/BusinessAiContext كل واحد لوحده، ومتأكدش يخزن
 * نسخة تانية من نفس البيانات في مكان تاني.
 *
 * Caching: نتيجة getContext() بتتخزن في الكاش (Cache class الموجودة
 * بالفعل في المشروع) عشان مفيش Business Context بيعمل 5 استعلامات
 * منفصلة في كل AI request زي ما اتطلب صراحة (Phase 27 - Performance).
 * أي Controller بيعدّل أي جزء من البيانات دي (Business نفسه، Location،
 * Service، Target Market، AI Context) *لازم* ينادي invalidate() بعد
 * الحفظ الناجح - وده اتعمل فعليًا في كل الـControllers المعنية (راجع
 * BUSINESS_CONTROL_CENTER_CHANGELOG.md لقائمة كل نقطة استدعاء).
 */
class BusinessContextService
{
    private const CACHE_TTL = 3600; // ساعة - بيانات شبه ثابتة، مش محتاجة تحديث لحظي
    private const CACHE_KEY_PREFIX = 'business_context:';

    /**
     * السياق الكامل لـBusiness معيّن - النقطة الوحيدة اللي أي AI Module
     * لازم يستخدمها. مش بيتحقق من الملكية هنا عمدًا (Service layer مش
     * مسؤول عن Authorization - ده مسؤولية الـController اللي بينادي
     * الدالة دي؛ لازم يتأكد إن الـbusinessId اللي بيبعته فعلًا يخص
     * السياق اللي المستخدم/الـJob الحالي مسموحله بيه قبل النداء).
     */
    public function getContext(int $businessId): array
    {
        $cache = new Cache();
        $cacheKey = self::CACHE_KEY_PREFIX . $businessId;

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $context = $this->buildContext($businessId);
        $cache->set($cacheKey, $context, self::CACHE_TTL);

        return $context;
    }

    /**
     * لازم تتنادى بعد أي تعديل ناجح على Business أو أي جدول تابع ليه
     * (Locations, Services, Target Markets, AI Context نفسه). بدون
     * الاستدعاء ده، الـCache هيفضل شايل بيانات قديمة لحد ما ينتهي الـTTL
     * (ساعة) - غير مقبول لمستخدم عدّل بياناته دلوقتي وعايز الـAI يستخدم
     * النسخة الجديدة فورًا.
     */
    public function invalidate(int $businessId): void
    {
        (new Cache())->delete(self::CACHE_KEY_PREFIX . $businessId);
    }

    private function buildContext(int $businessId): array
    {
        $business = (new Business())->find($businessId);
        if (!$business) {
            return ['exists' => false];
        }

        $locations = (new BusinessLocation())->where(['business_id' => $businessId], ['is_primary' => 'DESC']);
        $services = (new BusinessService())->where(['business_id' => $businessId, 'active' => 1], ['name' => 'ASC']);
        $marketRows = (new BusinessTargetMarket())->where(['business_id' => $businessId], [], 1);
        $aiContextRows = (new BusinessAiContext())->where(['business_id' => $businessId], [], 1);
        $brandRows = (new BusinessBrandSettings())->where(['business_id' => $businessId], [], 1);

        $targetMarket = !empty($marketRows) ? $marketRows[0] : null;
        $aiContext = !empty($aiContextRows) ? $aiContextRows[0] : null;
        $brandSettings = !empty($brandRows) ? $brandRows[0] : null;

        return [
            'exists' => true,
            'business' => $business->toArray(),
            'primary_location' => $this->findPrimaryLocation($locations),
            'locations' => array_map(fn ($l) => $l->toArray(), $locations),
            'services' => array_map(fn ($s) => $s->toArray(), $services),
            'target_markets' => $targetMarket ? [
                'countries' => $targetMarket->getTargetCountries(),
                'cities' => $targetMarket->getTargetCities(),
                'languages' => $targetMarket->getTargetLanguages(),
                'customer_type' => $targetMarket->getAttribute('customer_type'),
                'customer_segments' => $targetMarket->getCustomerSegments(),
            ] : null,
            'ai_context' => $aiContext ? [
                'business_summary' => $aiContext->getAttribute('business_summary'),
                'brand_description' => $aiContext->getAttribute('brand_description'),
                'target_audience' => $aiContext->getAttribute('target_audience'),
                'unique_selling_points' => $aiContext->getUniqueSellingPoints(),
                'brand_voice' => $aiContext->getAttribute('brand_voice'),
                'preferred_tone' => $aiContext->getAttribute('preferred_tone'),
                'forbidden_claims' => $aiContext->getForbiddenClaims(),
                'preferred_keywords' => $aiContext->getPreferredKeywords(),
                'business_goals' => $aiContext->getBusinessGoals(),
                'seo_goals' => $aiContext->getSeoGoals(),
                'content_goals' => $aiContext->getContentGoals(),
                'competitors' => $aiContext->getCompetitors(),
                'important_notes' => $aiContext->getAttribute('important_notes'),
            ] : null,
            'brand_settings' => $brandSettings ? [
                'favicon_url' => $brandSettings->getAttribute('favicon_url'),
                'brand_colors' => $brandSettings->getBrandColors(),
                'font_preference' => $brandSettings->getAttribute('font_preference'),
                'writing_style' => $brandSettings->getAttribute('writing_style'),
                'preferred_terminology' => $brandSettings->getPreferredTerminology(),
                'prohibited_terminology' => $brandSettings->getProhibitedTerminology(),
            ] : null,
            'generated_at' => date('c'),
        ];
    }

    private function findPrimaryLocation(array $locations): ?array
    {
        foreach ($locations as $location) {
            if ((bool) $location->getAttribute('is_primary')) {
                return $location->toArray();
            }
        }
        return null;
    }

    /**
     * تحويل السياق الكامل لنص Prompt جاهز للاستخدام المباشر مع أي AI
     * Model - عشان كل AI Module متضطرش يبني الـPrompt structure بنفسه
     * من الـarray الخام. لو حقل فاضي (لسه المستخدم مكملش بياناته)، بيتم
     * تجاهله من النص تمامًا بدل ما يظهر "N/A" أو قيمة وهمية.
     */
    public function toPromptContext(int $businessId): string
    {
        $context = $this->getContext($businessId);
        if (!$context['exists']) {
            return '';
        }

        $lines = [];
        $business = $context['business'];

        if (!empty($business['trade_name']) || !empty($business['legal_name'])) {
            $lines[] = 'Business Name: ' . ($business['trade_name'] ?: $business['legal_name']);
        }
        if (!empty($business['business_type'])) {
            $lines[] = 'Business Type: ' . $business['business_type'];
        }
        if (!empty($context['ai_context']['business_summary'])) {
            $lines[] = 'Summary: ' . $context['ai_context']['business_summary'];
        }
        if (!empty($context['ai_context']['brand_voice'])) {
            $lines[] = 'Brand Voice: ' . $context['ai_context']['brand_voice'];
        }
        if (!empty($context['ai_context']['target_audience'])) {
            $lines[] = 'Target Audience: ' . $context['ai_context']['target_audience'];
        }
        if (!empty($context['target_markets']['countries'])) {
            $lines[] = 'Target Markets: ' . implode(', ', $context['target_markets']['countries']);
        }
        if (!empty($context['services'])) {
            $serviceNames = array_map(fn ($s) => $s['name'], $context['services']);
            $lines[] = 'Services: ' . implode(', ', $serviceNames);
        }
        if (!empty($context['ai_context']['unique_selling_points'])) {
            $lines[] = 'Unique Selling Points: ' . implode('; ', $context['ai_context']['unique_selling_points']);
        }
        if (!empty($context['ai_context']['preferred_keywords'])) {
            $lines[] = 'Preferred Keywords: ' . implode(', ', $context['ai_context']['preferred_keywords']);
        }
        if (!empty($context['ai_context']['forbidden_claims'])) {
            $lines[] = 'IMPORTANT - Never claim: ' . implode('; ', $context['ai_context']['forbidden_claims']);
        }
        if (!empty($context['brand_settings']['writing_style'])) {
            $lines[] = 'Writing Style: ' . $context['brand_settings']['writing_style'];
        }
        if (!empty($context['brand_settings']['preferred_terminology'])) {
            $lines[] = 'Preferred Terminology: ' . implode(', ', $context['brand_settings']['preferred_terminology']);
        }
        if (!empty($context['brand_settings']['prohibited_terminology'])) {
            $lines[] = 'IMPORTANT - Never use these terms: ' . implode(', ', $context['brand_settings']['prohibited_terminology']);
        }

        return implode("\n", $lines);
    }
}
