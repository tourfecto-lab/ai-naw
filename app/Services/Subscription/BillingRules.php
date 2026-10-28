<?php

/**
 * Tourfecto - Billing Rules (مرجع القواعد المالية الموحّد)
 * @version 1.0.0
 * @date 2026-08-17
 *
 * كل القرارات المالية البحتة (Pure) في نظام الفوترة - مفيش أي اعتماد
 * على قاعدة بيانات أو طلبات شبكة، فمنفصلة في كلاس لوحدها عشان تختبر
 * بسهولة من غير DB، ولأن نفس القيم كانت متفرقة في اكتر من خدمة:
 *
 *   - SubscriptionLifecycleService: GRACE_PERIOD_DAYS / RENEWAL_REMINDER_DAYS /
 *     RENEWAL_REMINDER_EARLY_DAYS / DUNNING_FINAL_NOTICE_DAYS
 *   - WalletService::subscribeWithBalance: فرق السعر عند تغيير الباقة
 *     + خصم/استرجاع التخفيض (ALLOW_PRORATED_DOWNGRADE_CREDIT)
 *
 * القرارات المالية (زي تفعيل استرجاع التخفيض) بتتفعّل من مكان واحد هنا
 * وبقرار صريح من مالك المنصة مش من الكود.
 */
class BillingRules
{
    /** فترة السماح بعد انتهاء current_period_end قبل الإلغاء النهائي */
    public const GRACE_PERIOD_DAYS = 7;

    /** تذكير "التجديد قريب" - قبل التجديد بـ 3 أيام */
    public const RENEWAL_REMINDER_DAYS = 3;

    /** تذكير مبكر - قبل التجديد بـ 7 أيام (متدرج مع التذكير العادي) */
    public const RENEWAL_REMINDER_EARLY_DAYS = 7;

    /** إنذار أخير قبل نهاية فترة السماح - آخر 2 يوم */
    public const DUNNING_FINAL_NOTICE_DAYS = 2;

    /**
     * هل نرجع رصيد تلقائي للعميل عند التخفيض (downgrade)؟
     *
     * تحليل تنافسي (Stripe Billing / Chargebee / Paddle): المنصات العالمية
     * بتعمل prorated credit تلقائي عند تغيير الباقة لأسفل - العميل بياخد
     * فرق السعر عن الأيام المتبقية من الفترة الحالية رصيد في محفظته.
     *
     * ❌ افتراضيًا false (سياسة محافظة): التخفيض بيفعّل الباقة الجديدة من
     * غير أي استرجاع تلقائي. ده قرار مالي حقيقي بيأثر على إيراد المنصة -
     * مش هيتفعّل في الكود إلا بقرار صريح من مالك المنصة.
     *
     * @var bool
     */
    public const ALLOW_PRORATED_DOWNGRADE_CREDIT = false;

    /**
     * حساب فرق السعر عند تغيير الباقة (upgrade/downgrade).
     *
     * نفس المنطق المطبق في WalletService::subscribeWithBalance: الفرق
     * الكامل (new - old)، موجب للترقية وسالب للتخفيض وصفر للثبات.
     *
     * @param float $oldPrice سعر الباقة الحالية
     * @param float $newPrice سعر الباقة الجديدة
     * @return float الفرق مقرّب لخانتين عشريتين
     */
    public static function planChangeCharge(float $oldPrice, float $newPrice): float
    {
        return round($newPrice - $oldPrice, 2);
    }

    /**
     * هل التغيير ده ترقية (يخصم فرق موجبة)؟
     * @param float $chargeAmount ناتج planChangeCharge()
     * @return bool
     */
    public static function isUpgrade(float $chargeAmount): bool
    {
        return $chargeAmount > 0;
    }

    /**
     * هل التغيير ده تخفيض (يستحق استرجاع لو مفعّل)؟
     * @param float $chargeAmount ناتج planChangeCharge()
     * @return bool
     */
    public static function isDowngrade(float $chargeAmount): bool
    {
        return $chargeAmount < 0;
    }

    /**
     * مبلغ الاسترجاع عند التخفيض لو كان مفعّلًا.
     *
     * ملحوظة: المبلغ الحالي هو فرق السعر الكامل (old - new) مش
     * "pro-rated" حرفيًا على الأيام المتبقية - لأن التسعير الحالي بيخصم
     * الفرق الكامل عند الترقية برضه (متماثل الاتجاهين). لو احتجناهم
     * pro-rating حقيقي بدقة على اليوم، useProratedDowngradeCredit().
     *
     * @param float $oldPrice
     * @param float $newPrice
     * @return float 0 لو التفعيل مقفول أو مش تخفيض
     */
    public static function downgradeCredit(float $oldPrice, float $newPrice): float
    {
        $charge = self::planChangeCharge($oldPrice, $newPrice);
        if (!self::ALLOW_PRORATED_DOWNGRADE_CREDIT || !self::isDowngrade($charge)) {
            return 0.0;
        }
        return abs($charge);
    }

    /**
     * الـ pro-rating الحقيقي على اليوم: قيمة الفترة المتبقية من فرق
     * السعر. مش بيعتمد على ALLOW_PRORATED_DOWNGRADE_CREDIT - دي حسبة
     * محايدة بتقدر أي أداة تستخدمها وقت ما القرار يتاخد.
     *
     * @param float  $oldPrice       سعر الباقة الحالية
     * @param float  $newPrice       سعر الباقة الجديدة
     * @param int    $remainingDays  الأيام المتبقية من الفترة الحالية
     * @param int    $periodDays     إجمالي أيام الفترة الحالية (>0)
     * @return float credit مقرّب لخانتين (0 لو فترة غير صالحة أو فرق صفر)
     */
    public static function proratedCredit(float $oldPrice, float $newPrice, int $remainingDays, int $periodDays): float
    {
        if ($periodDays <= 0 || $remainingDays <= 0) {
            return 0.0;
        }
        $diff = $newPrice - $oldPrice;
        if ($diff >= 0) {
            return 0.0;
        }
        $ratio = min(1.0, $remainingDays / $periodDays);
        return round(abs($diff) * $ratio, 2);
    }

    /**
     * هل الاشتراك داخل نافذة إنذار الـ dunning الأخير؟ (النافذة بين
     * GRACE - DUNNING_FINAL و GRACE يوم من انتهاء الفترة)
     *
     * @param int $elapsedDays عدد الأيام اللي مرّت من انتهاء الفترة
     * @return bool
     */
    public static function isInDunningWindow(int $elapsedDays): bool
    {
        return $elapsedDays >= (self::GRACE_PERIOD_DAYS - self::DUNNING_FINAL_NOTICE_DAYS)
            && $elapsedDays < self::GRACE_PERIOD_DAYS;
    }

    /**
     * هل تجاوز الاشتراك فترة السماح؟ (يستحق الانتقال لـ cancelled)
     * @param int $elapsedDays عدد الأيام اللي مرّت من انتهاء الفترة
     * @return bool
     */
    public static function gracePeriodExpired(int $elapsedDays): bool
    {
        return $elapsedDays >= self::GRACE_PERIOD_DAYS;
    }

    /**
     * الأيام المتبقية قبل نهاية الفترة - لتذكيرات التجديد.
     * @param int $daysUntilEnd عدد الأيام الباقية على انتهاء الفترة
     * @param int $reminderDays نافذة التذكير (3 أو 7)
     * @return bool
     */
    public static function isInReminderWindow(int $daysUntilEnd, int $reminderDays): bool
    {
        return $daysUntilEnd >= 0 && $daysUntilEnd <= $reminderDays;
    }
}
