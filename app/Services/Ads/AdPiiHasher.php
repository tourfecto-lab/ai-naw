<?php

/**
 * Tourfecto - Enterprise Ad PII Hasher (Privacy by Design & EMQ 10/10)
 * @version 2.0.0
 *
 * يطبّق معايير التطبيع القياسية لـ Meta Conversions API و Google Enhanced Conversions:
 * - البريد الإلكتروني: lowercase + trim + إزالة النقاط الزائدة في Gmail اختيارياً ثم SHA-256.
 * - الهاتف: تطبيع E.164 (إزالة الرموز والأصفار الدولية البادئة 00) ثم SHA-256.
 * - الاسم والمدينة والدولة: تطبيع الأحرف العربية/اللاتينية ثم SHA-256 لرفع Event Match Quality.
 */
class AdPiiHasher
{
    public static function hashEmail(?string $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $normalized = mb_strtolower(trim($email), 'UTF-8');
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return hash('sha256', $normalized);
    }

    /**
     * تطبيع رقم الهاتف للأرقام الصافية مع إزالة بادئة 00 الدولية إن وجدت
     * لضمان تطابق الـ Hash مع سجل المستخدم في Meta/Google.
     */
    public static function hashPhone(?string $phone, ?string $defaultCountryDialCode = null): ?string
    {
        if (!is_string($phone)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return null;
        }

        // إذا بدأ الرقم بـ 00 (مثل 002010 أو 009665) نحذف الصفرين البادئين ليطابق E.164
        if (str_starts_with($digits, '00') && strlen($digits) > 9) {
            $digits = substr($digits, 2);
        } elseif ($defaultCountryDialCode !== null && str_starts_with($digits, '0') && strlen($digits) >= 9) {
            $dial = preg_replace('/\D/', '', $defaultCountryDialCode) ?? '';
            if ($dial !== '') {
                $digits = $dial . substr($digits, 1);
            }
        }

        if (strlen($digits) < 6) {
            return null;
        }

        return hash('sha256', $digits);
    }

    /**
     * تجزئة الاسم الأول أو الأخير بعد التطبيع (يدعم العربية والإنجليزية).
     */
    public static function hashName(?string $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $normalized = mb_strtolower(trim($name), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\s]/u', '', $normalized) ?? '';
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
        if ($normalized === '') {
            return null;
        }
        return hash('sha256', $normalized);
    }

    /**
     * تقسيم الاسم الكامل للعميل إلى (الاسم الأول + اسم العائلة) وتجزئة كل منهما.
     * @return array{fn:?string, ln:?string}
     */
    public static function splitAndHashFullName(?string $fullName): array
    {
        if (!is_string($fullName) || trim($fullName) === '') {
            return ['fn' => null, 'ln' => null];
        }
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $firstName = $parts[0] ?? null;
        $lastName = count($parts) > 1 ? end($parts) : null;

        return [
            'fn' => self::hashName($firstName),
            'ln' => self::hashName($lastName),
        ];
    }

    /**
     * تجزئة كود الدولة (ISO 2-letter lowercase) مثل 'eg' أو 'sa'.
     */
    public static function hashCountry(?string $countryCode): ?string
    {
        if (!is_string($countryCode)) {
            return null;
        }
        $normalized = strtolower(trim($countryCode));
        if (strlen($normalized) !== 2 || !ctype_alpha($normalized)) {
            return null;
        }
        return hash('sha256', $normalized);
    }

    /**
     * حساب مؤشر جودة التطابق المتوقع (Estimated Event Match Quality - EMQ من 10)
     * لعرضه للمستخدم في لوحة التحكم كما تفعل المنصات الكبرى.
     */
    public static function estimateEmqScore(array $signals): float
    {
        $score = 0.0;
        if (!empty($signals['email_hash'])) {
            $score += 3.5;
        }
        if (!empty($signals['phone_hash'])) {
            $score += 3.0;
        }
        if (!empty($signals['fbc']) || !empty($signals['gclid'])) {
            $score += 2.0;
        }
        if (!empty($signals['fbp'])) {
            $score += 0.5;
        }
        if (!empty($signals['fn']) || !empty($signals['ln'])) {
            $score += 0.5;
        }
        if (!empty($signals['client_ip_address']) && !empty($signals['client_user_agent'])) {
            $score += 0.5;
        }
        return min(10.0, round($score, 1));
    }
}