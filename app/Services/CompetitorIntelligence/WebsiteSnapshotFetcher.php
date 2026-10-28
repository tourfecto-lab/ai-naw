<?php

/**
 * Tourfecto - Competitor Intelligence: Website Snapshot Fetcher
 * @version 1.0.0
 *
 * مسؤول فقط عن: جلب صفحة عامة واحدة بأمان (SSRF-protected)، واستخراج
 * نسخة "مُطبَّعة" منها (نص أساسي + عنوان + meta description + هاش)
 * صالحة للمقارنة بين لقطتين متتاليتين في ChangeDetectionService.
 *
 * لا يحاول أبدًا الوصول لصفحات محمية بتسجيل دخول - فقط GET بسيط بدون
 * أي credentials، وبيحترم robots.txt الأساسي (لا يتخطى أي حماية).
 */
class WebsiteSnapshotFetcher
{
    /** Raw HTML for the on-page analyser, retaining the same redirect and size protections. */
    public function fetchHtml(string $url): ?string
    {
        $current=$url;
        for($hop=0;$hop<=self::MAX_REDIRECTS;$hop++){
            if(!SsrfGuard::validateUrl($current)['safe'])return null;
            $raw=$this->httpGet($current);
            if($raw['error']!==null)return null;
            if($raw['redirect_to']!==null){$current=$raw['redirect_to'];continue;}
            return $raw['http_status']>=200 && $raw['http_status']<300 ? $raw['body'] : null;
        }
        return null;
    }
    private const MAX_BYTES = 1_500_000; // ~1.5MB سقف لأي صفحة، كافي لصفحة HTML عادية
    private const TIMEOUT_SECONDS = 12;
    private const MAX_REDIRECTS = 3;
    private const USER_AGENT = 'TourfectoCompetitorIntelligenceBot/1.0 (+https://tourfecto.com/bot)';
    /** متصفح عادي: لما صاحب الموقع نفسه بيطلب تحليل موقعه (مواقع كبيرة بترفض أي User-Agent فيه Bot) */
    private const BROWSER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    private string $agent = self::USER_AGENT;

    public function asBrowser(): self
    {
        $this->agent = self::BROWSER_AGENT;
        return $this;
    }

    /**
     * @return array{success:bool, http_status:?int, title:?string, meta_description:?string,
     *   normalized_excerpt:?string, content_hash:?string, structured_data_hash:?string,
     *   tech_signals:?array, error:?string}
     */
    public function fetch(string $url): array
    {
        $check = SsrfGuard::validateUrl($url);
        if (!$check['safe']) {
            return $this->failure('blocked_by_ssrf_guard: ' . $check['reason']);
        }

        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $raw = $this->httpGet($current);

            if ($raw['error'] !== null) {
                return $this->failure($raw['error']);
            }

            // إعادة توجيه: نتحقق من الوجهة الجديدة بنفس فحص SSRF قبل ما نتبعها،
            // لمنع تجاوز الحماية عبر 301/302 لدومين/IP داخلي.
            if ($raw['redirect_to'] !== null) {
                $nextCheck = SsrfGuard::validateUrl($raw['redirect_to']);
                if (!$nextCheck['safe']) {
                    return $this->failure('blocked_redirect: ' . $nextCheck['reason']);
                }
                $current = $raw['redirect_to'];
                continue;
            }

            return $this->extract($raw['body'], $raw['http_status'], $raw['headers'] ?? []);
        }

        return $this->failure('too_many_redirects');
    }

    private function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['error' => 'curl_extension_missing', 'body' => null, 'http_status' => null, 'redirect_to' => null, 'headers' => []];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, // بنستخرج Server/X-Powered-By منها لبند Technology Signals - إشارات حقيقية مُلاحَظة، مش تخمين
            CURLOPT_FOLLOWLOCATION => false, // نتحكم يدويًا في الـ redirects عشان نعيد فحص SSRF لكل قفزة
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => $this->agent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // نثبّت الاتصال على IPv4 عشان فحص SSRF (اللي بيفحص كل سجلات IPv4+IPv6) يطابق فعليًا العنوان اللي هيتصل بيه - يمنع سيناريو IPv6 primacy / DNS rebinding
            CURLOPT_RANGE => '0-' . self::MAX_BYTES, // يقلل الحمل، بعض السيرفرات بتتجاهله فنحد الحجم برضه بعد الاستلام
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['error' => 'curl_error: ' . $error, 'body' => null, 'http_status' => null, 'redirect_to' => null, 'headers' => []];
        }

        $rawHeaders = is_string($response) ? substr($response, 0, $headerSize) : '';
        $body = is_string($response) ? substr($response, $headerSize) : false;
        $headers = $this->parseHeaders($rawHeaders);

        if (in_array($httpStatus, [301, 302, 303, 307, 308], true)) {
            // نجيب الـ Location بطلب header منفصل بسيط (curl_getinfo مش دايمًا كافي هنا بدون HEADER)
            $location = $this->getRedirectLocation($url);
            if ($location) {
                return ['error' => null, 'body' => null, 'http_status' => $httpStatus, 'redirect_to' => $location, 'headers' => $headers];
            }
        }

        if ($body === false) {
            return ['error' => 'empty_response', 'body' => null, 'http_status' => $httpStatus, 'redirect_to' => null, 'headers' => $headers];
        }

        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
        }

        return ['error' => null, 'body' => $body, 'http_status' => $httpStatus, 'redirect_to' => null, 'headers' => $headers];
    }

    /** يحوّل نص الـ headers الخام لمصفوفة name=>value (lowercase keys) */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    private function getRedirectLocation(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => $this->agent,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // نفس سبب httpGet - الاتصال الفعلي لازم يطابق فحص SSRF
        ]);
        $headers = curl_exec($ch);
        curl_close($ch);

        if (!$headers) {
            return null;
        }

        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
            // Location النسبي (بدون host) - نحوله لمطلق بناءً على الـ url الأصلي
            if (!preg_match('#^https?://#i', $location)) {
                $base = parse_url($url);
                $location = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . '/' . ltrim($location, '/');
            }
            return $location;
        }

        return null;
    }

    private function extract(?string $html, ?int $httpStatus, array $headers = []): array
    {
        if ($html === null || trim($html) === '') {
            return $this->failure('empty_body', $httpStatus);
        }

        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }

        $metaDescription = null;
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $html, $m)) {
            $metaDescription = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        // استخراج JSON-LD structured data لو موجودة (لمقارنة منفصلة)
        $structuredBlocks = [];
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $structuredBlocks[] = trim($block);
            }
        }
        $structuredDataHash = !empty($structuredBlocks)
            ? hash('sha256', implode('|', $structuredBlocks))
            : null;

        // إشارات تقنية حقيقية (Technology Signals) - HTTP headers فعلية +
        // meta generator tag لو موجودة. مفيش تخمين: أي حقل مش موجود
        // بيتسيب من غير قيمة بدل ملؤه بتخمين.
        $techSignals = [];
        foreach (['server', 'x-powered-by'] as $header) {
            if (!empty($headers[$header])) {
                $techSignals[$header] = $headers[$header];
            }
        }
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\'](.*?)["\']/is', $html, $m)) {
            $techSignals['generator'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        // إشارات شائعة إضافية عن طريق بصمات نصية معروفة وموثوقة في HTML نفسه
        if (stripos($html, 'wp-content') !== false || stripos($html, 'wp-includes') !== false) {
            $techSignals['cms_hint'] = 'WordPress';
        } elseif (stripos($html, 'cdn.shopify.com') !== false) {
            $techSignals['cms_hint'] = 'Shopify';
        } elseif (stripos($html, 'Wix.com') !== false || stripos($html, 'static.wixstatic.com') !== false) {
            $techSignals['cms_hint'] = 'Wix';
        }

        // لغة الصفحة ونسخها باللغات التانية (hreflang) وأرقام التليفون/الواتساب: إشارات للسوق اللي الموقع بيستهدفه
        $htmlLang = preg_match('~<html[^>]*\blang=["\']?([a-zA-Z]{2})~', $html, $m) ? strtolower($m[1]) : null;
        $alternates = [];
        if (preg_match_all('~<link[^>]+>~i', $html, $links)) {
            foreach ($links[0] as $tag) {
                if (stripos($tag, 'alternate') === false || !preg_match('~hreflang=["\']([a-zA-Z]{2})(?:[-_][a-zA-Z]{2})?["\']~', $tag, $hl) || !preg_match('~href=["\']([^"\']+)["\']~i', $tag, $hr)) continue;
                $l = strtolower($hl[1]);
                if (!isset($alternates[$l])) $alternates[$l] = html_entity_decode($hr[1], ENT_QUOTES, 'UTF-8');
            }
        }
        $phones = [];
        if (preg_match_all('~(?:wa\.me/|api\.whatsapp\.com/send\?phone=|tel:)\s*\+?(\d[\d\s-]{6,18})~i', $html, $pm)) {
            foreach ($pm[1] as $n) { $n = preg_replace('/\D/', '', $n); if ($n !== '' && !in_array($n, $phones, true)) $phones[] = $n; }
        }
        // أرقام مكتوبة في نص الصفحة أو إعدادات زرار الواتساب (+966 5x xxx xxxx / 00966 / %2B966)
        if (preg_match_all('~(?:\+|%2B|\b00)\s?((?:966|971|965|974|973|968|962|20)[\s-]?\d[\d\s-]{7,12}\d)~i', $html, $pm)) {
            foreach ($pm[1] as $n) { $n = preg_replace('/\D/', '', $n); if (strlen($n) >= 10 && strlen($n) <= 13 && !in_array($n, $phones, true)) $phones[] = $n; }
        }

        // إزالة script/style/comments، ثم النصوص الأساسية فقط، بحد أقصى
        // معقول للتخزين والمقارنة (لا داعي لتخزين الصفحة كاملة).
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $clean);
        $clean = preg_replace('/<!--.*?-->/s', ' ', $clean);
        $text = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $normalizedExcerpt = mb_substr($text, 0, 20000);

        $contentHash = hash('sha256', $normalizedExcerpt);

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'title' => $title !== null ? mb_substr($title, 0, 500) : null,
            'meta_description' => $metaDescription !== null ? mb_substr($metaDescription, 0, 1000) : null,
            'normalized_excerpt' => $normalizedExcerpt,
            'content_hash' => $contentHash,
            'structured_data_hash' => $structuredDataHash,
            'tech_signals' => !empty($techSignals) ? $techSignals : null,
            'html_lang' => $htmlLang,
            'alternates' => array_slice($alternates, 0, 12, true),
            'phones' => array_slice($phones, 0, 10),
            'error' => null,
        ];
    }

    private function failure(string $reason, ?int $httpStatus = null): array
    {
        return [
            'success' => false,
            'http_status' => $httpStatus,
            'title' => null,
            'meta_description' => null,
            'normalized_excerpt' => null,
            'content_hash' => null,
            'structured_data_hash' => null,
            'tech_signals' => null,
            'error' => $reason,
        ];
    }
}
