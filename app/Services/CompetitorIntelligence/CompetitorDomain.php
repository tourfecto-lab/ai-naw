<?php

/**
 * Tourfecto - Competitor Intelligence: Competitor Domain Utility
 * @version 1.0.0
 *
 * موحّد لكل عمليات تطبيع الدومين/الـ URL اللي بيدخلها المستخدم (إضافة
 * منافس، استيراد جماعي، مصادر الاكتشاف). كان نفس المنطق متكرر في 5 أماكن
 * (controller + MonitoringEngine + SitemapMonitor + مصادر discovery) مع
 * اختلافات دقيقة بسيطة - هنا في مكان واحد:
 *
 *  1. إزالة المسافات.
 *  2. إضافة `https://` تلقائيًا لو مفيش scheme.
 *  3. استخراج الـ host (للـ dedup والـ display).
 *  4. فحص أمان الـ URL (SsrfGuard) - نفس القاعدة في كل مكان.
 *
 * أي استهلاك جديد للدومين المفروض يعدّي هنا بدل إعادة كتابة المنطق.
 */
class CompetitorDomain
{
    /**
     * تطبيع دومين/URL مدخل: trim + إضافة `https://` لو مفيش scheme.
     * بترجّع string دايماً (فاضية لو المدخل فاضي/null).
     */
    public static function normalize(mixed $domain): string
    {
        $domain = trim((string) $domain);
        if ($domain === '') {
            return '';
        }
        return preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
    }

    /**
     * استخراج الـ host من URL أو دومين خام (lowercase). بترجّع null لو
     * مفيش host قابل للاستخراج.
     */
    public static function host(mixed $urlOrDomain): ?string
    {
        $normalized = self::normalize($urlOrDomain);
        if ($normalized === '') {
            return null;
        }
        $host = parse_url($normalized, PHP_URL_HOST);
        if (!$host) {
            $host = $normalized;
        }
        $host = strtolower($host);
        return $host === '' ? null : $host;
    }

    /**
     * هل الـ URL آمن أن السيرفر يطلبه؟ (نفس فحص SsrfGuard بعد التطبيع).
     */
    public static function isSafe(mixed $domain): bool
    {
        $normalized = self::normalize($domain);
        return $normalized !== '' && SsrfGuard::isSafe($normalized);
    }

    /**
     * تطبيع + فحص أمان دفعة واحدة. بترجّع الـ URL المُطبَّع (جاهز للتخزين)
     * أو null لو الدومين مش آمن/فاضي - الـ caller يقرر إزاي يتعامل مع null.
     */
    public static function normalizeSafe(mixed $domain): ?string
    {
        $normalized = self::normalize($domain);
        if ($normalized === '') {
            return null;
        }
        return SsrfGuard::isSafe($normalized) ? $normalized : null;
    }
}
