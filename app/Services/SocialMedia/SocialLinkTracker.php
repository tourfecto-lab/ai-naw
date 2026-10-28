<?php

/**
 * Tourfecto - تتبّع روابط السوشيال (UTM) - منطق نقي قابل للاختبار.
 *
 * أي رابط لموقع العميل في نص المنشور بياخد utm_source=المنصة&utm_medium=social&utm_campaign=الحملة،
 * عشان Google Analytics والحجوزات تعرف إن الزيارة/الحجز جه من البوست ده بالظبط.
 *  - الروابط الخارجية (مش دومين العميل) ما بتتلمسش.
 *  - الرابط اللي فيه utm_ أصلًا ما بيتغيّرش (العميل حاطط تتبّعه بنفسه).
 *  - نفس المدخلات = نفس الناتج دايمًا (المصالحة بتعيد التركيب وتطابق النص).
 * @version 1.0.0
 */
require_once __DIR__ . '/SocialAttribution.php';

final class SocialLinkTracker
{
    /**
     * @param string[] $hosts دومينات العميل (بدون www). فاضية = مفيش تتبّع.
     */
    public static function apply(string $message, string $platform, ?string $campaign, array $hosts): string
    {
        $campaign = self::slug((string) $campaign);
        $hosts = array_values(array_filter(array_map([self::class, 'host'], $hosts)));
        if ($campaign === '' || !$hosts || $message === '') {
            return $message;
        }
        $source = preg_replace('/[^a-z_]/', '', strtolower($platform)) ?: 'social';
        return (string) preg_replace_callback('~https?://[^\s<>"\'()\[\]]+~iu', static function (array $m) use ($hosts, $source, $campaign): string {
            $url = $m[0];
            // علامات الترقيم في آخر الجملة مش جزء من الرابط
            $tail = '';
            if (preg_match('/[.,!?؟،:;]+$/u', $url, $t)) {
                $tail = $t[0];
                $url = mb_substr($url, 0, mb_strlen($url) - mb_strlen($tail));
            }
            $host = self::host((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || !self::ownHost($host, $hosts) || stripos($url, 'utm_') !== false) {
                return $m[0];
            }
            $fragment = '';
            if (($p = strpos($url, '#')) !== false) {
                $fragment = substr($url, $p);
                $url = substr($url, 0, $p);
            }
            $sep = strpos($url, '?') === false ? '?' : (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&');
            return $url . $sep . 'utm_source=' . $source . '&utm_medium=social&utm_campaign=' . rawurlencode($campaign) . $fragment . $tail;
        }, $message);
    }

    /** اسم الحملة: حروف/أرقام/شرطة (عربي مسموح) بحد أقصى 60 */
    public static function slug(string $campaign): string
    {
        $s = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(trim($campaign))), '-');
        return mb_substr($s, 0, 60);
    }

    /** اسم حملة افتراضي من الموضوع/التاريخ: social-2026-10 */
    public static function defaultCampaign(?int $ts = null): string
    {
        return 'social-' . gmdate('Y-m', $ts ?? time());
    }

    private static function host(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '/')) {
            $value = (string) parse_url(preg_match('~^https?://~', $value) ? $value : 'https://' . $value, PHP_URL_HOST);
        }
        return preg_replace('/^www\./', '', $value);
    }

    private static function ownHost(string $host, array $hosts): bool
    {
        foreach ($hosts as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return true;
            }
        }
        return false;
    }

    /** دومينات العميل من جدول websites (موقع المنشور، أو كل مواقعه لو المنشور مش مربوط بموقع) */
    public static function hostsFor(int $userId, ?int $websiteId): array
    {
        try {
            $rows = $websiteId
                ? Database::getInstance()->query('SELECT domain, main_url FROM websites WHERE id = ? AND user_id = ?', [$websiteId, $userId])
                : Database::getInstance()->query('SELECT domain, main_url FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$userId]);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows ?: [] as $r) {
            foreach ([(string) ($r['domain'] ?? ''), (string) ($r['main_url'] ?? '')] as $v) {
                $h = self::host($v);
                if ($h !== '') {
                    $out[$h] = $h;
                }
            }
        }
        return array_values($out);
    }

    /**
     * النص النهائي اللي بيتنشر فعلًا على منصة معيّنة: المحتوى + الهاشتاجات + UTM + (لو مفعّل) روابط قصيرة لتتبّع الحجوزات.
     * مصدر واحد للنشر والمصالحة: نفس الهدف = نفس الروابط القصيرة.
     */
    public static function finalMessage(SocialPost $post, string $platform, ?int $targetId = null): string
    {
        $message = PublishPolicy::composeMessage((string) $post->getAttribute('content'), (string) $post->getAttribute('hashtags'));
        $campaign = (string) ($post->getAttribute('utm_campaign') ?? '');
        $track = (int) ($post->getAttribute('track_links') ?? 0) === 1 && $targetId;
        if ($campaign === '' && !$track) {
            return $message;
        }
        $uid = (int) $post->getAttribute('user_id');
        $wid = (int) $post->getAttribute('website_id');
        $hosts = self::hostsFor($uid, $wid > 0 ? $wid : null);
        if ($campaign !== '') {
            $message = self::apply($message, $platform, $campaign, $hosts);
        }
        if ($track && class_exists('SocialAttribution')) {
            $postId = (int) $post->getAttribute('id');
            $message = SocialAttribution::replaceOwnLinks($message, $hosts, static fn (string $url): string => SocialAttribution::shortLink($uid, $postId, (int) $targetId, $platform, $url));
        }
        return $message;
    }
}
