<?php

/**
 * Tourfecto - Ad Budget Currency Conversion
 * @version 1.0.0
 *
 * Meta وGoogle بيقروا الميزانية بعملة حساب الإعلانات نفسه (مش دولار).
 * قبل كده الرقم كان بيتبعت زي ما هو: 10$ في حساب بالجنيه كانت بتتحول
 * لـ10 جنيه بس فالحملة تترفض أو متصرفش. الكلاس ده بيحوّل بين العملات
 * بسعر الصرف الأساسي (من غير هامش الدفع بتاع CurrencyService).
 */
class AdBudgetCurrency
{
    /**
     * تحويل مبلغ بين عملتين. بيرجّع null لو أي عملة منهم مش معروفة
     * (ساعتها النداء لازم يطلب من العميل يكتب الميزانية بعملة حسابه).
     */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper(trim($from)) ?: 'USD';
        $to = strtoupper(trim($to)) ?: 'USD';
        if ($from === $to) {
            return round($amount, 2);
        }
        $fromRate = self::baseRate($from);
        $toRate = self::baseRate($to);
        if ($fromRate === null || $toRate === null || $fromRate <= 0) {
            return null;
        }
        $converted = $amount / $fromRate * $toRate;
        // أرقام الميزانية الكبيرة بتتقرّب لرقم مقروء (مثلًا 500 مش 497.63)
        return $converted >= 100 ? round($converted / 5) * 5 : round($converted, 2);
    }

    /** أقل ميزانية يومية منطقية (~1$) بعملة الحساب - تحت كده المنصات بترفض أو متوصلش لحد */
    public static function minimumDaily(string $currency): float
    {
        return self::convert(1.0, 'USD', $currency) ?? 1.0;
    }

    /** عدد الوحدات مقابل 1 دولار (السعر الأساسي بدون هامش الدفع) */
    private static function baseRate(string $code): ?float
    {
        if ($code === 'USD') {
            return 1.0;
        }
        if (class_exists('CurrencyService') && CurrencyService::valid($code)) {
            try {
                $rates = CurrencyService::rates();
                $base = (float) ($rates[$code]['base'] ?? 0);
                if ($base > 0) {
                    return $base;
                }
            } catch (Throwable $e) {
                // لو الإعدادات مش متاحة نكمل بالأسعار الاحتياطية
            }
            $fallback = (float) (CurrencyService::CURRENCIES[$code]['fallback'] ?? 0);
            return $fallback > 0 ? $fallback : null;
        }
        return null;
    }
}
