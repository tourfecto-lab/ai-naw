<?php

/**
 * Reputation: a public "review us" page + printable QR for a site (counter card, receipt, brochure, tour bus).
 * The URL is stateless: /review-us/{websiteId}-{signature}; the signature is an HMAC of the website id, so ids cannot be
 * enumerated and no migration is needed. Destinations come from the existing review_request_settings links.
 *
 * Policy: every visitor sees every review destination. We never filter unhappy visitors away from public review
 * sites (review gating is prohibited by Google and TripAdvisor).
 */
class ReviewLanding
{
    private static function key(): string
    {
        foreach (['ENCRYPTION_KEY', 'APP_KEY', 'JWT_SECRET'] as $k) {
            $v = function_exists('env') ? env($k) : getenv($k);
            if (is_string($v) && strlen($v) >= 16) {
                return $v;
            }
        }
        throw new RuntimeException('Signing key is not configured');
    }

    public static function code(int $websiteId): string
    {
        return $websiteId . '-' . substr(hash_hmac('sha256', 'review-us:' . $websiteId, self::key()), 0, 20);
    }

    /** @return int website id, or 0 when the code is not valid */
    public static function verify(string $code): int
    {
        if (!preg_match('/^([1-9][0-9]{0,9})-([a-f0-9]{20})$/', $code, $m)) {
            return 0;
        }
        return hash_equals(substr(hash_hmac('sha256', 'review-us:' . $m[1], self::key()), 0, 20), $m[2]) ? (int) $m[1] : 0;
    }

    public static function url(int $websiteId, string $base): string
    {
        return rtrim($base, '/') . '/review-us/' . self::code($websiteId);
    }

    /** @return array<int,array{key:string,label:string,url:string}> only valid http(s) links, no duplicates */
    public static function destinations(array $settings): array
    {
        $out = [];
        $seen = [];
        foreach ([
            ['google', 'Google', $settings['google_review_link'] ?? ''],
            ['tripadvisor', 'TripAdvisor', $settings['tripadvisor_review_link'] ?? ''],
            ['other', 'Leave a review', $settings['default_review_link'] ?? ''],
        ] as [$key, $label, $url]) {
            $url = trim((string) $url);
            if ($url === '' || isset($seen[$url]) || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $seen[$url] = true;
            $out[] = ['key' => $key, 'label' => $label, 'url' => $url];
        }
        return $out;
    }

    public static function qrSvg(string $url): string
    {
        return QrCodeService::svg($url);
    }

    /** Public page HTML (standalone, no panel chrome). */
    public static function page(string $company, array $destinations, string $qrSvg = '', string $surveyUrl = '', bool $ar = false): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $tr = static fn (string $en, string $arText) => $ar ? $arText : $en;
        $buttons = '';
        foreach ($destinations as $d) {
            $buttons .= '<a class="btn" rel="noopener noreferrer" href="' . $e($d['url']) . '">' . $e($d['label']) . '</a>';
        }
        if ($surveyUrl !== '') {
            $buttons .= '<a class="btn" style="background:transparent;color:var(--brand);border:2px solid var(--brand)" href="' . $e($surveyUrl) . ($ar ? '?lang=ar' : '') . '">' . $e($tr('Send private feedback', 'ابعت رأيك بشكل خاص')) . '</a>';
        }
        if ($buttons === '') {
            $buttons = '<p class="muted">' . $e($tr('This business has not set up its review links yet.', 'النشاط ده لسه ما ضافش روابط التقييم.')) . '</p>';
        }
        return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>' . $e($company) . ' - ' . $e($tr('Leave a review', 'قيّمنا')) . '</title>'
            . '<style>:root{color-scheme:light dark;--bg:#f6f7fb;--card:#fff;--ink:#111827;--muted:#6b7280;--brand:#4f46e5}'
            . '@media(prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--ink:#f1f5f9;--muted:#94a3b8;--brand:#818cf8}}'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Tahoma,sans-serif;padding:16px}'
            . '.card{background:var(--card);max-width:420px;width:100%;border-radius:18px;padding:28px 22px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.12)}'
            . 'h1{font-size:22px;margin:0 0 6px}p{margin:0 0 18px;line-height:1.6}.muted{color:var(--muted)}'
            . '.btn{display:block;margin:10px 0;padding:14px;border-radius:12px;background:var(--brand);color:#fff;text-decoration:none;font-weight:700}'
            . '.btn:focus-visible{outline:3px solid #f59e0b;outline-offset:2px}</style></head><body><main class="card">'
            . '<div aria-hidden="true" style="font-size:34px">⭐⭐⭐⭐⭐</div><h1>' . $e($company) . '</h1>'
            . '<p class="muted">' . $e($tr('Thank you for choosing us. Your honest review helps other travellers.', 'شكرًا إنك اخترتنا. رأيك الصادق بيساعد مسافرين تانيين.')) . '</p>' . $buttons . '</main></body></html>';
    }
}
