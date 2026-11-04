<?php
/**
 * يفرّق بين رابط مكسور فعلًا (404/410) وبين عطل مؤقت أو حجب (timeout, DNS, 403, 429, 5xx, صيانة).
 * قراءة فاشلة واحدة مش دليل إن الرابط مكسور، ومنها مفيش حذف ولا استبدال تلقائي.
 */
final class SeoLinkHealth
{
    /** @return array{state:string,reason:string,status:int,retry_after:?int} state: ok|gone|transient|maintenance */
    public static function classify(array $ctx): array
    {
        $status = (int)($ctx['http_status'] ?? 0);
        $retry = self::retryAfter($ctx['retry_after'] ?? null);
        if (!empty($ctx['coming_soon'])) return self::r('maintenance', 'maintenance_page', $status, $retry);
        if (!empty($ctx['ok'])) return self::r('ok', 'ok', $status, null);
        if ($status === 404 || $status === 410) return self::r('gone', 'not_found', $status, null);
        if ($status === 429) return self::r('transient', 'rate_limited', $status, $retry);
        if ($status === 401 || $status === 403) return self::r('transient', 'blocked_forbidden', $status, $retry);
        if ($status === 503 && $retry !== null) return self::r('transient', 'maintenance_retry_after', $status, $retry);
        if ($status >= 500) return self::r('transient', 'server_error', $status, $retry);
        if ($status >= 300 && $status < 400) return self::r('transient', 'redirect_unresolved', $status, null);
        $detail = strtolower((string)($ctx['error_detail'] ?? ''));
        if ($detail !== '') {
            if (str_contains($detail, 'resolve')) return self::r('transient', 'dns_failure', 0, null);
            if (str_contains($detail, 'timed out') || str_contains($detail, 'timeout')) return self::r('transient', 'timeout', 0, null);
            if (str_contains($detail, 'blocked')) return self::r('transient', 'blocked_by_platform_policy', 0, null);
        }
        if ($status >= 400) return self::r('transient', 'client_error_'.$status, $status, null);
        return self::r('transient', 'fetch_failed', $status, null);
    }

    /** يقرا الرابط تاني قبل أي قرار: "gone" لازم تتأكد في قراءتين متتاليتين. */
    public static function confirmGone(object $reader, string $url, int $reads = 2): array
    {
        $last = self::r('transient', 'fetch_failed', 0, null);
        for ($i = 0; $i < $reads; $i++) {
            $last = self::classify($reader->fresh($url));
            if ($last['state'] !== 'gone') return $last;
        }
        return $last;
    }

    /** أسباب بتتحول لقرار "مفيش تعديل" ويتسجل سببها للعميل والإدارة. */
    public static function label(string $reason, bool $ar): string
    {
        $m = [
            'timeout' => ['The page did not answer in time', 'الصفحة ماردتش في الوقت المناسب'],
            'dns_failure' => ['The domain could not be resolved', 'تعذر الوصول لعنوان الدومين'],
            'blocked_forbidden' => ['The site blocked our check (401/403)', 'الموقع منع الفحص (401/403)'],
            'rate_limited' => ['The site asked us to slow down (429)', 'الموقع طلب تقليل الطلبات (429)'],
            'server_error' => ['The site returned a server error (5xx)', 'الموقع رجّع خطأ سيرفر (5xx)'],
            'maintenance_page' => ['The page showed a maintenance screen', 'الصفحة كانت في وضع الصيانة'],
            'maintenance_retry_after' => ['The site is temporarily unavailable', 'الموقع غير متاح مؤقتًا'],
            'not_found' => ['The page does not exist (404/410)', 'الصفحة غير موجودة (404/410)'],
        ];
        return ($m[$reason] ?? ['The page could not be read', 'تعذرت قراءة الصفحة'])[$ar ? 1 : 0];
    }

    private static function retryAfter($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return max(0, min(86400, (int)$v));
        $t = strtotime((string)$v);
        return $t ? max(0, min(86400, $t - time())) : null;
    }

    private static function r(string $state, string $reason, int $status, ?int $retry): array
    {
        return ['state' => $state, 'reason' => $reason, 'status' => $status, 'retry_after' => $retry];
    }
}
