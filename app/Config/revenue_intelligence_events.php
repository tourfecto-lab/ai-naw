<?php

/**
 * Tourfecto - AI Revenue Intelligence: Event Listeners
 * @version 1.0.0
 *
 * Section 25: EVENTS
 *
 * تسجيل مركزي (مرة واحدة عند تحميل الصفحة) لمستمعي الأحداث الخاصة
 * بموديول Revenue Intelligence، باستخدام EventDispatcher/event()/listen()
 * الموجودين فعلاً في المشروع (app/Core/Events + app/Helpers/enterprise_helpers.php)
 * - لم يُبنَ أي نظام Events جديد.
 *
 * الأحداث المُستهلَكة هنا (بيتم إطلاقها من نقطتين موجودتين فعلاً في
 * المشروع، بتعديل بسيط سطر واحد في كل مكان - شوف CHANGELOG.md):
 *   - 'revenue.updated'   -> RevenueController::createRecord()
 *   - 'crm.deal.won'      -> CrmController::updateDealStage()
 *   - 'crm.deal.lost'     -> CrmController::updateDealStage()
 *
 * ملاحظة: 'DealWon'/'DealLost'/'CustomerPurchase'/'SubscriptionChanged'
 * المذكورة في section 25 كأمثلة - حاليًا فقط Deal Won/Lost موجودين
 * فعليًا كأحداث حقيقية قابلة للربط في نقطة واحدة معروفة بالكود
 * (crm_pipeline_stages.is_won/is_lost). "CustomerPurchase" و
 * "SubscriptionChanged" لا تُطلق من أي مكان في billing/subscriptions
 * الحالي في المشروع، فلم نخترع نقطة إطلاق وهمية لهم - أي Listener هنا
 * جاهز للتفعيل فور ما تلك الموديولات تطلق الأحداث دي فعليًا مستقبلاً.
 */

if (function_exists('listen') && class_exists('RevenueCacheService')) {

    // إيراد جديد اتسجّل -> الكاش (Overview/Forecast/Executive) بقى قديم، نبطّله فورًا.
    listen('revenue.updated', function (AppEvent $event) {
        $userId = (int) ($event->payload['user_id'] ?? 0);
        if ($userId > 0) {
            (new RevenueCacheService())->invalidateForUser($userId);
        }
    });

    // صفقة اتقفلت (مكسوبة أو خسرانة) -> تأثير مباشر على Customer/Pipeline
    // Intelligence + احتمال Opportunity/Risk جديد، فنبطّل الكاش ونجدول
    // إعادة حساب كاملة في الخلفية (Section 18: Background Jobs) بدل ما
    // نعطّل رد الطلب الحالي (تحديث مرحلة الصفقة) بحسابات ثقيلة.
    $onDealClosed = function (AppEvent $event) {
        $userId = (int) ($event->payload['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        (new RevenueCacheService())->invalidateForUser($userId);
        if (function_exists('enqueue') && class_exists('RecomputeRevenueInsightsJob')) {
            enqueue(RecomputeRevenueInsightsJob::class, ['user_id' => $userId]);
        }
    };
    listen('crm.deal.won', $onDealClosed);
    listen('crm.deal.lost', $onDealClosed);
}
