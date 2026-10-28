<?php

/**
 * Tourfecto - سياسة قرار نتيجة النشر (نقية، قابلة للاختبار).
 *
 * بتحوّل نتيجة نداء Meta المنظّمة (MetaSocialAPI) لقرار واحد:
 *   success      -> اتنشر
 *   retry_later  -> Meta رفضت صراحة بسبب حد استخدام/عطل شبكة قبل الإرسال: نعيد بعد تأخير (backoff)
 *   failed       -> فشل نهائي واضح (توكن/صلاحية/وسائط/بيانات): العميل لازم يتدخل
 *   uncertain    -> غامض (timeout بعد الإرسال، 5xx): ممكن اتنشر - تتحسم بالمصالحة مش بإعادة عمياء
 * @version 1.0.0
 */
final class PublishPolicy
{
    public const MAX_RETRIES = 4;
    private const DELAYS = [120, 600, 1800, 7200];
    private const IG_DAILY_LIMIT_DELAYS = [10800, 21600, 43200, 86400];
    private const MAX_DELAY = 86400;

    public static function classify(array $result): string
    {
        if (!empty($result['success'])) {
            return 'success';
        }
        // نتائج من غير تصنيف Meta (تيك توك/يوتيوب أو ExternalExecution): نفس سلوك v4 عبر outcome_state
        if (!array_key_exists('definitive', $result)) {
            return ($result['outcome_state'] ?? 'uncertain') === 'failed' ? 'failed' : 'uncertain';
        }
        $category = (string) ($result['category'] ?? MetaApiError::UNKNOWN);
        $definitive = !empty($result['definitive']);

        if ($definitive && ($category === MetaApiError::RATE_LIMIT || !empty($result['not_sent']))) {
            return 'retry_later';
        }
        if ($definitive) {
            return 'failed';
        }
        return 'uncertain';
    }

    /**
     * تأخير المحاولة التالية بالثواني، أو null لو المحاولات خلصت.
     * @param int $retryCount عدد المحاولات اللي حصلت قبل كده
     */
    public static function retryDelay(int $retryCount, ?int $retryAfter = null, ?int $subcode = null): ?int
    {
        if ($retryCount >= self::MAX_RETRIES) {
            return null;
        }
        $table = $subcode === 2207042 ? self::IG_DAILY_LIMIT_DELAYS : self::DELAYS;
        $base = $table[$retryCount];
        $delay = max($base, (int) $retryAfter + 30);
        return min($delay, self::MAX_DELAY);
    }

    /**
     * تركيب نص المنشور النهائي (المحتوى + الهاشتاجات). مصدر واحد للنشر والمصالحة:
     * لو اختلف التركيب بينهم المطابقة بتفشل. hashtags ممكن تكون JSON array أو نص جاهز.
     */
    public static function composeMessage(string $content, ?string $hashtags): string
    {
        $hashtags = (string) $hashtags;
        $decoded = json_decode($hashtags, true);
        if (is_array($decoded)) {
            $hashtags = implode(' ', array_map(static fn ($h) => '#' . ltrim(trim((string) $h), '#'), $decoded));
        }
        return $hashtags !== '' ? $content . "\n\n" . $hashtags : $content;
    }

    /** نص الخطأ اللي يتخزن للعميل: شرح عربي بالكود + رمز التتبع للدعم الفني */
    public static function describe(array $result): string
    {
        $parts = [];
        $friendly = (string) ($result['friendly'] ?? '');
        $raw = (string) ($result['error'] ?? 'خطأ غير معروف من المنصة');
        if ($friendly !== '') {
            $parts[] = $friendly;
        }
        $parts[] = $raw;
        $meta = [];
        if (!empty($result['error_code'])) {
            $meta[] = 'code ' . $result['error_code'] . (!empty($result['error_subcode']) ? '/' . $result['error_subcode'] : '');
        }
        if (!empty($result['fbtrace_id'])) {
            $meta[] = 'trace ' . $result['fbtrace_id'];
        }
        $text = implode(' — ', $parts);
        return $meta ? $text . ' [' . implode(', ', $meta) . ']' : $text;
    }
}
