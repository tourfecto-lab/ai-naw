<?php

/**
 * Tourfecto - نسبة الحجوزات للمنشورات (Social → Booking attribution)
 *
 * كل رابط لموقع العميل في منشور (لو "تتبّع الحجوزات" مفعّل) بيتحول لرابط قصير /go/{code} خاص بالمنشور والمنصة.
 * الزائر لما يضغط: بنعدّ النقرة (من غير بوتات المعاينة) ونحوّله لموقع العميل ومعاه tf_sl={code}.
 * أول ما يوصل أي صفحة من مواقع المنصة (حتى على دومين العميل الخاص) بنحفظ الإسناد في كوكي 30 يوم،
 * ولما يحجز من محرك الحجز بنربط الحجز بالمنشور (bookings.attributed_social_link_id).
 * الإسناد الإعلاني (روابط /r/ المدفوعة) ليه الأولوية لو موجود - ده بيفضل زي ما هو.
 * @version 1.0.0
 */
final class SocialAttribution
{
    public const COOKIE = 'tf_social_attr';
    public const WINDOW = 2592000; // 30 يوم زي الإعلانات
    public const PARAM = 'tf_sl';

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = (bool) Database::getInstance()->query("SHOW COLUMNS FROM bookings LIKE 'attributed_social_link_id'");
            } catch (Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    public static function validCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{6,16}$/D', $code);
    }

    /** كود قصير عشوائي (base62) */
    public static function newCode(int $length = 8): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * رابط قصير ثابت لكل (هدف نشر + رابط أصلي): نفس المدخلات = نفس الكود (المصالحة بتعيد التركيب وتطابق النص).
     * $url = الرابط بعد UTM. بيرجّع رابط /go/{code} الكامل، أو الرابط زي ما هو لو الجدول مش موجود.
     */
    public static function shortLink(int $userId, int $postId, int $targetId, string $platform, string $url): string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        if ($base === '' || !self::ready() || mb_strlen($url) > 900) {
            return $url;
        }
        $db = Database::getInstance();
        try {
            $row = $db->query('SELECT code FROM social_links WHERE target_id = ? AND original_url = ? LIMIT 1', [$targetId, $url])[0] ?? null;
            if (!$row) {
                for ($attempt = 0; $attempt < 3 && !$row; $attempt++) {
                    $code = self::newCode();
                    $dest = $url . (str_contains($url, '?') ? '&' : '?') . self::PARAM . '=' . $code;
                    if (($p = strpos($url, '#')) !== false) {
                        $dest = substr($url, 0, $p) . (str_contains(substr($url, 0, $p), '?') ? '&' : '?') . self::PARAM . '=' . $code . substr($url, $p);
                    }
                    try {
                        $db->query('INSERT INTO social_links (user_id, social_post_id, target_id, platform, code, destination_url, original_url) VALUES (?,?,?,?,?,?,?)', [$userId, $postId, $targetId, $platform, $code, $dest, $url]);
                        $row = ['code' => $code];
                    } catch (Throwable $e) {
                        // تصادم كود نادر أو سباق بين عاملين: نقرا الموجود ونكمّل
                        $row = $db->query('SELECT code FROM social_links WHERE target_id = ? AND original_url = ? LIMIT 1', [$targetId, $url])[0] ?? null;
                    }
                }
            }
        } catch (Throwable $e) {
            return $url;
        }
        return $row ? $base . '/go/' . $row['code'] : $url;
    }

    /** يحوّل روابط دومينات العميل في النص بـ $mapper (نقية - بتتختبر) */
    public static function replaceOwnLinks(string $message, array $hosts, callable $mapper): string
    {
        $hosts = array_values(array_filter(array_map(static fn ($h) => preg_replace('/^www\./', '', strtolower(trim((string) $h))), $hosts)));
        if (!$hosts || $message === '') {
            return $message;
        }
        return (string) preg_replace_callback('~https?://[^\s<>"\'()\[\]]+~iu', static function (array $m) use ($hosts, $mapper): string {
            $url = $m[0];
            $tail = '';
            if (preg_match('/[.,!?؟،:;]+$/u', $url, $t)) {
                $tail = $t[0];
                $url = mb_substr($url, 0, mb_strlen($url) - mb_strlen($tail));
            }
            $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
            foreach ($hosts as $h) {
                if ($host === $h || str_ends_with($host, '.' . $h)) {
                    return $mapper($url) . $tail;
                }
            }
            return $m[0];
        }, $message);
    }

    /** GET /go/{code}: يعدّ النقرة ويحفظ الإسناد ويرجّع رابط الوجهة (null = كود مش موجود) */
    public static function click(string $code): ?string
    {
        if (!self::validCode($code) || !self::ready()) {
            return null;
        }
        $db = Database::getInstance();
        $row = $db->query('SELECT id, destination_url FROM social_links WHERE code = ? LIMIT 1', [$code])[0] ?? null;
        if (!$row) {
            return null;
        }
        if (!self::isBot()) {
            $db->query('UPDATE social_links SET clicks = clicks + 1 WHERE id = ?', [(int) $row['id']]);
            self::store((int) $row['id']);
        }
        return (string) $row['destination_url'];
    }

    /** على صفحات مواقع المنصة (حتى الدومين الخاص): ?tf_sl=code بيحفظ الإسناد على الدومين ده */
    public static function captureFromQuery(): void
    {
        $code = isset($_GET[self::PARAM]) && is_string($_GET[self::PARAM]) ? $_GET[self::PARAM] : '';
        if ($code === '' || !self::validCode($code) || !self::ready() || self::isBot()) {
            return;
        }
        try {
            $row = Database::getInstance()->query('SELECT id FROM social_links WHERE code = ? LIMIT 1', [$code])[0] ?? null;
            if ($row) {
                self::store((int) $row['id']);
            }
        } catch (Throwable $e) {
        }
    }

    private static function store(int $linkId): void
    {
        $payload = json_encode(['id' => $linkId, 'ts' => time()]);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $payload, [
                'expires' => time() + self::WINDOW,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $payload;
    }

    /** آخر رابط سوشيال ضغط عليه الزائر (خلال 30 يوم) */
    public static function current(): ?int
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['id']) || (time() - (int) ($data['ts'] ?? 0)) > self::WINDOW) {
            return null;
        }
        return (int) $data['id'];
    }

    /**
     * يربط حجز جديد بالمنشور اللي جه منه الزائر. الرابط لازم يكون بتاع نفس صاحب الحجز (منع التلاعب).
     * المصدر بيتغيّر لـ social:{platform} لو كان "website" بس (الإسناد الإعلاني ما بيتلمسش).
     */
    public static function attachToBooking(int $bookingId, int $userId): bool
    {
        $linkId = self::current();
        if (!$linkId || !self::ready()) {
            return false;
        }
        try {
            $db = Database::getInstance();
            $link = $db->query('SELECT id, platform FROM social_links WHERE id = ? AND user_id = ? LIMIT 1', [$linkId, $userId])[0] ?? null;
            if (!$link) {
                return false;
            }
            $db->query(
                "UPDATE bookings SET attributed_social_link_id = ?, source = IF(source = 'website', ?, source) WHERE id = ? AND user_id = ? AND attributed_social_link_id IS NULL",
                [(int) $link['id'], mb_substr('social:' . $link['platform'], 0, 30), $bookingId, $userId]
            );
            if (!headers_sent()) {
                setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
            }
            unset($_COOKIE[self::COOKIE]);
            return true;
        } catch (Throwable $e) {
            Logger::warning('Social booking attribution failed', ['booking_id' => $bookingId]);
            return false;
        }
    }

    /**
     * نقرات وحجوزات وإيراد كل منشور. حجوزات = طلبات (pending/confirmed/completed)، الإيراد = المؤكد بس.
     * @param int[] $postIds
     * @return array<int, array{clicks:int,bookings:int,revenue:float,currency:?string}>
     */
    public static function forPosts(int $userId, array $postIds): array
    {
        $postIds = array_values(array_filter(array_map('intval', $postIds)));
        if (!$postIds || !self::ready()) {
            return [];
        }
        $in = implode(',', $postIds);
        $db = Database::getInstance();
        $out = [];
        try {
            foreach ($db->query("SELECT social_post_id AS pid, SUM(clicks) AS clicks FROM social_links WHERE user_id = ? AND social_post_id IN ({$in}) GROUP BY social_post_id", [$userId]) ?: [] as $r) {
                $out[(int) $r['pid']] = ['clicks' => (int) $r['clicks'], 'bookings' => 0, 'revenue' => 0.0, 'currency' => null];
            }
            foreach ($db->query(
                "SELECT l.social_post_id AS pid, COUNT(b.id) AS n, COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS revenue, MAX(b.currency) AS currency
                   FROM bookings b JOIN social_links l ON l.id = b.attributed_social_link_id
                  WHERE b.user_id = ? AND l.social_post_id IN ({$in}) AND b.status IN ('pending','confirmed','completed')
                  GROUP BY l.social_post_id",
                [$userId]
            ) ?: [] as $r) {
                $pid = (int) $r['pid'];
                $out[$pid] = ($out[$pid] ?? ['clicks' => 0, 'bookings' => 0, 'revenue' => 0.0, 'currency' => null]);
                $out[$pid]['bookings'] = (int) $r['n'];
                $out[$pid]['revenue'] = round((float) $r['revenue'], 2);
                $out[$pid]['currency'] = $r['currency'] ?: null;
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** إجماليات فترة (للنظرة العامة والتقرير) */
    public static function totals(int $userId, string $from, string $to): array
    {
        $empty = ['clicks' => 0, 'bookings' => 0, 'revenue' => 0.0, 'currency' => null, 'by_platform' => []];
        if (!self::ready()) {
            return $empty;
        }
        $db = Database::getInstance();
        try {
            $clicks = (int) ($db->query('SELECT COALESCE(SUM(l.clicks),0) AS c FROM social_links l JOIN social_posts sp ON sp.id = l.social_post_id WHERE l.user_id = ? AND sp.created_at >= ? AND sp.created_at < ?', [$userId, $from, $to])[0]['c'] ?? 0);
            $rows = $db->query(
                "SELECT l.platform, COUNT(b.id) AS n, COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END),0) AS revenue, MAX(b.currency) AS currency
                   FROM bookings b JOIN social_links l ON l.id = b.attributed_social_link_id
                  WHERE b.user_id = ? AND b.status IN ('pending','confirmed','completed') AND b.created_at >= ? AND b.created_at < ?
                  GROUP BY l.platform",
                [$userId, $from, $to]
            ) ?: [];
        } catch (Throwable $e) {
            return $empty;
        }
        $out = $empty;
        $out['clicks'] = $clicks;
        foreach ($rows as $r) {
            $out['bookings'] += (int) $r['n'];
            $out['revenue'] += (float) $r['revenue'];
            $out['currency'] = $out['currency'] ?: ($r['currency'] ?: null);
            $out['by_platform'][(string) $r['platform']] = ['bookings' => (int) $r['n'], 'revenue' => round((float) $r['revenue'], 2)];
        }
        $out['revenue'] = round($out['revenue'], 2);
        return $out;
    }

    private static function isBot(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return true;
        }
        $purpose = strtolower($_SERVER['HTTP_X_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_SEC_PURPOSE'] ?? '');
        if (str_contains($purpose, 'preview') || str_contains($purpose, 'prefetch')) {
            return true;
        }
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua === '' || (bool) preg_match('/facebookexternalhit|facebookcatalog|meta-externalagent|twitterbot|slackbot|whatsapp|telegrambot|linkedinbot|discordbot|googlebot|bingbot|bot\b|crawler|spider|preview|embedly|pinterest/i', $ua);
    }
}
