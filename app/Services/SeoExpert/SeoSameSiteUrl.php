<?php
/** حارس مشترك: الرابط لازم يكون https ومن نفس نطاق موقع المستخدم، وحد أقصى للطلبات في الساعة (جلسة). */
final class SeoSameSiteUrl
{
    public static function allowed(string $mainUrl, string $url): bool
    {
        $site = parse_url($mainUrl);
        $page = parse_url($url);
        $host = static fn ($p) => strtolower(preg_replace('/^www\./', '', (string) ($p['host'] ?? '')));
        return $site && $page && ($page['scheme'] ?? '') === 'https' && $host($site) !== '' && $host($site) === $host($page)
            && !isset($page['user']) && !isset($page['pass']);
    }

    /** true لو لسه تحت الحد؛ العدّاد في الجلسة بمفتاح الساعة الحالية. */
    public static function throttle(string $bucket, int $max): bool
    {
        $key = $bucket . '_' . gmdate('YmdH');
        $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
        return $_SESSION[$key] <= $max;
    }
}
