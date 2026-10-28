<?php

/**
 * Tourfecto - Advanced SEO Audit Checks Engine
 * @version 1.0.0
 *
 * محرك فحوصات احترافي بيوسّع الـ Website Optimizer من ~30 فحص أساسي إلى
 * 100+ فحص على مستوى أدوات عالمية (Screaming Frog / Semrush Site Audit).
 * مبني على فحص حقيقي مباشر من HTML + Headers + ملفات الموقع (robots.txt /
 * sitemap.xml / llms.txt)، من غير نتائج وهمية.
 *
 * التصميم: كل فئة (SEO/Speed/Security/Mobile/Accessibility/AEO/GEO) لها
 * method مستقل، وكل فحص بيستخدم helper موحّد (add/hdr/http). النتيجة
 * نفس شكل الـ findings بتاعة WebsiteOptimizerController عشان تندمج
 * مباشرة في الـ audit من غير تغيير الواجهة.
 */
class AuditChecksService
{
    /** @var array سياق التدقيق اللي بيتبعت من performAudit */
    private $ctx = [];

    /** @var array */
    private $findings = [];

    /** @var array */
    private $brokenLinks = [];

    /** @var array كاش طلبات HTTP عشان منكررش نفس الطلب */
    private $httpCache = [];

    /** سقف زمني (timestamp) مشترك مع WebsiteOptimizerController: بعده الطلبات بتتخطّى فورًا. null = من غير سقف. */
    private $deadline = null;

    /** @var int عدد الطلبات اللي اتخطّت بسبب انتهاء الميزانية الزمنية */
    private $skipped = 0;

    public function setDeadline(?float $timestamp): void
    {
        $this->deadline = $timestamp;
    }

    public function skippedRequests(): int
    {
        return $this->skipped;
    }

    /**
     * تشغيل كل الفحوصات المتقدمة على السياق المجهّز مسبقًا.
     * @return array ['findings'=>array, 'broken_links'=>array]
     */
    public function run(array $ctx): array
    {
        $this->ctx = $ctx;
        $this->findings = [];
        $this->brokenLinks = [];
        $this->httpCache = [];

        $this->checkSeo();
        $this->checkSpeed();
        $this->checkSecurity();
        $this->checkMobile();
        $this->checkAccessibility();
        $this->checkAeo();
        $this->checkGeo();
        $this->checkBrokenResources();

        $this->checkSeoAdvanced();
        $this->checkSpeedAdvanced();
        $this->checkSecurityAdvanced();
        $this->checkMobileAdvanced();
        $this->checkAccessibilityAdvanced();
        $this->checkAeoAdvanced();
        $this->checkGeoAdvanced();

        return ['findings' => $this->findings, 'broken_links' => $this->brokenLinks];
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function add(string $category, string $key, string $title, string $status, string $severity, string $message): void
    {
        $this->findings[] = [
            'category' => $category,
            'check_key' => $key,
            'title' => $title,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    /** قراءة هيدر HTTP من السياق (case-insensitive) */
    private function hdr(string $name): ?string
    {
        $headers = $this->ctx['headers'] ?? [];
        $key = strtolower($name);
        if (isset($headers[$key])) {
            return $headers[$key];
        }
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $key) {
                return (string) $v;
            }
        }
        return null;
    }

    /** وجود قيمة في هيدر معين (جزئية) */
    private function hdrHas(string $name, string $needle): bool
    {
        $v = $this->hdr($name);
        return $v !== null && stripos($v, $needle) !== false;
    }

    private function html(): string
    {
        return (string) ($this->ctx['html'] ?? '');
    }

    private function host(): string
    {
        return (string) ($this->ctx['host'] ?? '');
    }

    private function origin(): string
    {
        return (string) ($this->ctx['origin'] ?? '');
    }

    private function schemaTypes(): array
    {
        return is_array($this->ctx['schema_types'] ?? null) ? $this->ctx['schema_types'] : [];
    }

    /** طلب HTTP مع كاش - من غير تكرار نفس الرابط */
    private function http(string $url, int $timeout = 8, bool $headOnly = false, bool $withHeaders = false): array
    {
        $cacheKey = $url . '|' . ($headOnly ? '1' : '0') . '|' . ($withHeaders ? '1' : '0');
        if (isset($this->httpCache[$cacheKey])) {
            return $this->httpCache[$cacheKey];
        }

        $start = microtime(true);
        if ($this->deadline !== null) {
            $remaining = $this->deadline - $start;
            if ($remaining < 1.5) {
                $this->skipped++;
                return ['body' => null, 'code' => 0, 'error' => 'audit time budget exceeded', 'time' => 0.0, 'headers' => [], 'redirects' => 0];
            }
            $timeout = (int) max(2, min($timeout, (int) ceil($remaining)));
        }
        $safe = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TourfectoAudit/3.0 (+SEO audit bot)',
            CURLOPT_NOBODY => $headOnly,
            CURLOPT_HEADER => $withHeaders,
        ], 5);
        $response = $safe['response'];
        $code = $safe['code'];
        $error = $safe['error'];
        $time = microtime(true) - $start;
        $redirects = $safe['redirects'];

        $body = null;
        $headers = [];
        if ($response !== false) {
            if ($withHeaders) {
                $headerSize = $safe['header_size'];
                $rawHeaders = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);
                foreach (explode("\r\n", $rawHeaders) as $line) {
                    if (strpos($line, ':') !== false) {
                        [$k, $v] = explode(':', $line, 2);
                        $headers[strtolower(trim($k))] = trim($v);
                    }
                }
            } else {
                $body = $response;
            }
        }

        $result = ['body' => $body, 'code' => $code, 'error' => $error, 'time' => $time, 'headers' => $headers, 'redirects' => $redirects];
        $this->httpCache[$cacheKey] = $result;
        return $result;
    }

    // ============================================================
    // SEO (كلاسيكي + تقني متقدم)
    // ============================================================

    private function checkSeo(): void
    {
        $html = $this->html();
        $host = $this->host();
        $origin = $this->origin();
        $types = $this->schemaTypes();
        $url = (string) ($this->ctx['url'] ?? '');

        // --- charset ---
        $hasCharset = preg_match('/<meta\s+[^>]*charset=/i', $html) || $this->hdr('content-type') !== null && stripos((string) $this->hdr('content-type'), 'charset') !== false;
        $this->add('seo', 'meta_charset', 'ترميز الأحرف (Charset)', $hasCharset ? 'pass' : 'fail', $hasCharset ? 'info' : 'medium', $hasCharset ? 'الترميز محدد (UTF-8 أو غيره)' : 'مفيش تحديد للترميز - بيسبب مشاكل عرض للعربي ونصوص تانية');

        // --- meta keywords (deprecated) ---
        $hasMetaKeywords = preg_match('/<meta\s+[^>]*name=["\']keywords["\']/i', $html);
        $this->add('seo', 'meta_keywords', 'وسم Meta Keywords', $hasMetaKeywords ? 'warn' : 'pass', $hasMetaKeywords ? 'low' : 'info', $hasMetaKeywords ? 'مستخدم meta keywords - جوجل بتتجاهله من 2009 وممكن يكون إشارة لممارسات قديمة' : 'مفيش meta keywords (صح - مهجور من جوجل)');

        // --- canonical self-referencing ---
        $canonicalHref = '';
        if (preg_match('/<link\s+[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $cm)) {
            $canonicalHref = $cm[1];
        }
        if ($canonicalHref !== '') {
            $self = $this->normalizeUrl($url) === $this->normalizeUrl($canonicalHref);
            $this->add('seo', 'canonical_self', 'تطابق الرابط الأساسي (Self-Referencing Canonical)', $self ? 'pass' : 'warn', $self ? 'info' : 'medium', $self ? 'الـ canonical بيشاور على نفس الصفحة (صح)' : "الـ canonical بيشاور على رابط مختلف: {$canonicalHref} - تأكد إن ده مقصود مش غلط");
        }

        // --- robots nofollow ---
        $nofollow = preg_match('/<meta\s+[^>]*name=["\']robots["\'][^>]*content=["\'][^"\']*nofollow/i', $html);
        $this->add('seo', 'robots_nofollow', 'سمة Nofollow على مستوى الصفحة', $nofollow ? 'warn' : 'pass', $nofollow ? 'medium' : 'info', $nofollow ? 'الصفحة فيها nofollow - جوجل مش هتتبع الروابط منها' : 'الصفحة بتسمح بمتابعة الروابط');

        // --- H1 length ---
        if (preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $h1m) && count($h1m[0]) > 0) {
            $h1Text = trim(html_entity_decode(strip_tags($h1m[1][0])));
            $h1Len = mb_strlen($h1Text);
            $st = $h1Len > 0 && $h1Len <= 70 ? 'pass' : 'warn';
            $this->add('seo', 'h1_length', 'طول عنوان H1', $st, $st === 'warn' ? 'low' : 'info', "الطول: {$h1Len} حرف (المثالي تحت 70)");
        }

        // Optional: China market elements (ICP filing note)
        $isChinaTarget = false;
        $targetLangs = $this->ctx['target_languages'] ?? null;
        if (!empty($targetLangs)) {
            $decoded = json_decode((string) $targetLangs, true);
            if (is_array($decoded)) {
                foreach ($decoded as $lang) {
                    $code = is_array($lang) ? ($lang['code'] ?? '') : $lang;
                    if (strtolower((string) $code) === 'zh' || strtolower((string) $code) === 'zh-cn') {
                        $isChinaTarget = true;
                        break;
                    }
                }
            }
        }
        if ($isChinaTarget) {
            $hasIcp = preg_match('/ICP|备案|京ICP/i', $html);
            $this->add(
                'seo',
                'china_icp_note',
                'ملاحظة ICP للسوق الصيني',
                'info',
                'info',
                $hasIcp ? 'موجود إشارة ICP — تأكد من صحة التسجيل الرسمي' : 'مطلوب ICP filing للمواقع المستضافة في الصين — تأكد من الامتثال'
            );
        }

        // --- heading order (skipped levels) ---
        preg_match_all('/<h([1-6])\b/i', $html, $hm);
        $levels = array_map('intval', $hm[1]);
        $skipped = $this->countSkippedHeadings($levels);
        $this->add('seo', 'heading_order', 'ترتيب العناوين (Heading Hierarchy)', $skipped === 0 ? 'pass' : 'warn', $skipped > 0 ? 'low' : 'info', $skipped === 0 ? 'التسلسل سليم (مفيش تخطي مستويات)' : "في {$skipped} قفزة في مستويات العناوين (مثلًا H1→H3) - يفضل تسلسل منطقي متدرج");

        // --- URL structure ---
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $hasUnderscore = strpos($path, '_') !== false;
        $hasUppercase = preg_match('/[A-Z]/', $path) === 1;
        $urlLen = mb_strlen($url);
        $this->add('seo', 'url_structure', 'بنية الرابط (URL)', ($hasUnderscore || $hasUppercase) ? 'warn' : 'pass', ($hasUnderscore || $hasUppercase) ? 'low' : 'info', $hasUppercase ? 'الرابط فيه حروف كبيرة - يفضل lowercase' : ($hasUnderscore ? 'الرابط بيستخدم _ بدل - في فصل الكلمات' : 'الرابط نظيف (lowercase وبدون _)'));

        $this->add('seo', 'url_length', 'طول الرابط (URL)', $urlLen <= 75 ? 'pass' : 'warn', $urlLen > 75 ? 'low' : 'info', "الطول: {$urlLen} حرف (المثالي تحت 75)");

        // --- robots.txt existence ---
        $robotsExists = (bool) ($this->ctx['robots_exists'] ?? false);
        $this->add('seo', 'robots_txt', 'ملف robots.txt', $robotsExists ? 'pass' : 'warn', $robotsExists ? 'info' : 'medium', $robotsExists ? 'موجود' : 'مفيش robots.txt - جوجل هيفترض إن كل حاجة مسموحة، بس الأفضل تحديد قواعد واضحة');

        // --- sitemap reference in robots ---
        $robotsBody = (string) ($this->ctx['robots_body'] ?? '');
        $sitemapInRobots = stripos($robotsBody, 'Sitemap:') !== false;
        $this->add('seo', 'sitemap_robots_ref', 'الإشارة للـ Sitemap في robots.txt', $sitemapInRobots ? 'pass' : 'warn', $sitemapInRobots ? 'info' : 'low', $sitemapInRobots ? 'في إشارة Sitemap: في robots.txt' : 'مفيش إشارة Sitemap: في robots.txt - أضفها لتسريع اكتشاف الصفحات');

        // --- sitemap validation ---
        $sitemapUrl = $this->detectSitemapUrl($robotsBody, $origin);
        if ($sitemapUrl !== '') {
            $sm = $this->http($sitemapUrl, 8, false, false);
            $isXml = $sm['body'] !== null && (stripos($sm['body'], '<urlset') !== false || stripos($sm['body'], '<sitemapindex') !== false);
            $urlCount = 0;
            if ($isXml) {
                $urlCount = substr_count((string) $sm['body'], '<loc>');
            }
            $this->add('seo', 'sitemap_status', 'صلاحية خريطة الموقع (Sitemap.xml)', $sm['code'] < 400 && $isXml ? 'pass' : 'fail', $isXml ? 'info' : 'medium', $isXml ? "صالح وبيحتوي على {$urlCount} رابط" : "sitemap مش صالح (HTTP {$sm['code']} أو مش XML)");
        } else {
            $this->add('seo', 'sitemap_status', 'صلاحية خريطة الموقع (Sitemap.xml)', 'warn', 'low', 'مفيش sitemap.xml ظاهر');
        }

        // --- hreflang ---
        $hasHreflang = preg_match('/<link\s+[^>]*rel=["\']alternate["\'][^>]*hreflang=/i', $html);
        $this->add('seo', 'hreflang', 'وسوم اللغة المتعددة (Hreflang)', $hasHreflang ? 'pass' : 'info', 'info', $hasHreflang ? 'موجودة - تحديد صحيح للنسخ اللغوية/الإقليمية' : 'مش مطبقة - ضرورية بس لو عندك نسخ متعددة اللغات/الدول');

        // --- pagination ---
        $hasPagination = preg_match('/rel=["\'](next|prev)["\']/i', $html);
        $this->add('seo', 'pagination', 'وسوم الترقيم (Pagination)', $hasPagination ? 'pass' : 'info', 'info', $hasPagination ? 'موجودة' : 'مش مطبقة - مفيدة للصفحات متعددة الأجزاء');

        // --- twitter cards ---
        $hasTwitter = preg_match('/<meta\s+[^>]*name=["\']twitter:(card|title|description)["\']/i', $html);
        $this->add('seo', 'twitter_cards', 'وسوم Twitter Cards', $hasTwitter ? 'pass' : 'warn', $hasTwitter ? 'info' : 'low', $hasTwitter ? 'موجودة - شكل محسّن عند المشاركة على تويتر/X' : 'مفيش Twitter Cards - هتظهر المشاركات بشكل عادي من غير بطاقة غنية');

        // --- PWA manifest ---
        $hasManifest = preg_match('/<link\s+[^>]*rel=["\']manifest["\']/i', $html);
        $this->add('mobile', 'manifest', 'بيان تطبيق الويب (Web App Manifest)', $hasManifest ? 'pass' : 'info', 'info', $hasManifest ? 'موجود - يدعم التثبيت كـ PWA' : 'مش موجود - اختياري بس بيحسّن تجربة الموبايل');

        // --- image dimensions (CLS) ---
        preg_match_all('/<img\s+[^>]*>/i', $html, $imgs);
        $withDims = 0;
        $totalImgs = count($imgs[0]);
        foreach ($imgs[0] as $tag) {
            if (preg_match('/\swidth=/i', $tag) && preg_match('/\sheight=/i', $tag)) {
                $withDims++;
            }
        }
        if ($totalImgs > 0) {
            $dimRatio = $withDims / $totalImgs;
            $this->add('speed', 'image_dimensions', 'أبعاد الصور (منع CLS)', $dimRatio > 0.8 ? 'pass' : 'warn', $dimRatio <= 0.8 ? 'medium' : 'info', "{$withDims} من {$totalImgs} صورة محددة العرض والارتفاع - التحديد بيمنع انزياح المحتوى (CLS)");
        }

        // --- lazy loading ---
        $hasLazy = preg_match('/loading=["\']lazy["\']/i', $html);
        $this->add('speed', 'lazy_loading', 'التحميل الكسول للصور (Lazy Loading)', $hasLazy ? 'pass' : 'info', 'info', $hasLazy ? 'مطبق - بيحسّن سرعة التحميل' : 'مش مطبق على الصور - ممكن يحسّن السرعة');

        // --- internal/external links ---
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $anchors);
        $internal = 0;
        $external = 0;
        $externalNoFollow = 0;
        foreach ($anchors[0] as $i => $anchorTag) {
            $href = $anchors[1][$i] ?? '';
            if (stripos($href, 'mailto:') === 0 || stripos($href, 'tel:') === 0 || $href === '' || $href === '#') {
                continue;
            }
            $resolved = $this->resolve($url, $href);
            $isInternal = stripos($resolved, $host) !== false;
            if ($isInternal) {
                $internal++;
            } else {
                $external++;
                if (preg_match('/rel=["\'][^"\']*nofollow/i', $anchorTag)) {
                    $externalNoFollow++;
                }
            }
        }
        $this->add('seo', 'internal_links', 'عدد الروابط الداخلية', $internal > 0 ? 'pass' : 'warn', $internal > 0 ? 'info' : 'low', "في {$internal} رابط داخلي - الروابط الداخلية بتوزع قوة الـ SEO بين الصفحات");

        if ($external > 0) {
            $this->add('seo', 'external_links', 'الروابط الخارجية وNofollow', $externalNoFollow === $external ? 'warn' : 'pass', $externalNoFollow === $external ? 'low' : 'info', "{$external} رابط خارجي (منهم {$externalNoFollow} بـ nofollow)");
        }

        // --- anchor text quality ---
        $genericAnchors = 0;
        foreach ($anchors[0] as $tag) {
            if (preg_match('/>(.*?)<\/a>/is', $tag, $am)) {
                $text = trim(html_entity_decode(strip_tags($am[1])));
                if ($text === '' || preg_match('/^(click here|اقرأ المزيد|المزيد|اضغط هنا|here|read more|انقر هنا)$/i', $text)) {
                    $genericAnchors++;
                }
            }
        }
        $this->add('seo', 'anchor_text', 'جودة نصوص الروابط (Anchor Text)', $genericAnchors === 0 ? 'pass' : 'warn', $genericAnchors > 0 ? 'low' : 'info', $genericAnchors === 0 ? 'النصوص وصفية (مفيش "اقرأ المزيد" عامة)' : "في {$genericAnchors} رابط بنص عام - استخدم نصوص وصفية للـ SEO");

        // --- target=_blank security ---
        preg_match_all('/<a\s+[^>]*target=["\']_blank["\']/i', $html, $blankTags);
        $unprotected = 0;
        foreach ($blankTags[0] as $tag) {
            if (!preg_match('/rel=["\'][^"\']*(noopener|noreferrer)/i', $tag)) {
                $unprotected++;
            }
        }
        if (count($blankTags[0]) > 0) {
            $this->add('security', 'target_blank_rel', 'أمان الروابط الجديدة (target=_blank)', $unprotected === 0 ? 'pass' : 'warn', $unprotected > 0 ? 'low' : 'info', $unprotected === 0 ? 'كل روابط _blank فيها rel=noopener' : "في {$unprotected} رابط target=_blank من غير noopener - خطر أمني (tabnabbing)");
        }

        // --- structured data validity ---
        $invalidJson = $this->ctx['invalid_json_ld'] ?? 0;
        $this->add('seo', 'structured_data_valid', 'سلامة بيانات JSON-LD', $invalidJson === 0 ? 'pass' : 'fail', $invalidJson > 0 ? 'high' : 'info', $invalidJson === 0 ? 'كل بلوكات JSON-LD سليمة' : "في {$invalidJson} بلوك JSON-LD مش متحقق منه - بيبوظ الـ rich results");

        // --- breadcrumb schema ---
        $hasBreadcrumb = in_array('BreadcrumbList', $types, true);
        $this->add('seo', 'breadcrumb_schema', 'بيانات مسار التنقل (BreadcrumbList)', $hasBreadcrumb ? 'pass' : 'info', 'info', $hasBreadcrumb ? 'موجودة - بتعرض مسار التنقل في نتائج البحث' : 'مش موجودة - مفيدة للمواقع متعددة الأقسام');

        // --- http -> https redirect ---
        $httpUrl = 'http://' . $host . (parse_url($url, PHP_URL_PATH) ?? '/');
        $httpProbe = $this->http($httpUrl, 6, true, false);
        $httpsOk = stripos($url, 'https://') === 0;
        if ($httpsOk) {
            $redirectsToHttps = $httpProbe['code'] >= 300 && $httpProbe['code'] < 400 && stripos((string) $httpProbe['body'], 'https://') !== false;
            // كشف الوجهة من الـ Location هيدر أضمن
            $redirectsToHttps = $this->http($httpUrl, 6, true, true)['headers']['location'] ?? '';
            $redirectsToHttps = stripos((string) $redirectsToHttps, 'https://') === 0;
            $this->add('availability', 'http_https_redirect', 'إعادة التوجيه HTTP→HTTPS', $redirectsToHttps ? 'pass' : 'warn', $redirectsToHttps ? 'info' : 'medium', $redirectsToHttps ? 'الـ HTTP بيحوّل لـ HTTPS صح' : 'النسخة الـ HTTP مش بتحوّل لـ HTTPS - أضف 301');
        }
    }

    // ============================================================
    // SPEED
    // ============================================================

    private function checkSpeed(): void
    {
        $html = $this->html();

        // compression
        $enc = $this->hdr('content-encoding');
        $hasCompression = $enc !== null && preg_match('/(gzip|br|deflate|zstd)/i', $enc);
        $this->add('speed', 'compression', 'ضغط الاستجابة (Gzip/Brotli)', $hasCompression ? 'pass' : 'warn', $hasCompression ? 'info' : 'medium', $hasCompression ? "مضغوط بـ {$enc}" : 'مفيش ضغط - فعّل gzip/brotli لتقليل الحجم والوقت');

        // caching headers
        $cacheControl = $this->hdr('cache-control');
        $expires = $this->hdr('expires');
        $hasCache = ($cacheControl !== null && stripos($cacheControl, 'no-store') === false) || $expires !== null;
        $this->add('speed', 'cache_headers', 'توجيهات التخزين المؤقت (Cache)', $hasCache ? 'pass' : 'warn', $hasCache ? 'info' : 'low', $hasCache ? 'في توجيهات caching' : 'مفيش cache-control/expires - الموارد هتتحمل من جديد كل مرة');

        // etag
        $etag = $this->hdr('etag');
        $this->add('speed', 'etag', 'ترويسة ETag', $etag !== null ? 'pass' : 'info', 'info', $etag !== null ? 'موجودة - تساعد في إعادة التحقق من الكاش' : 'مفيش ETag - اختياري بس مفيد للتخزين المؤقت');

        // render-blocking scripts in head
        $headMatch = preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $hm2) ? $hm2[1] : '';
        preg_match_all('/<script\b[^>]*src=[^>]*>/i', $headMatch, $headScripts);
        $renderBlocking = 0;
        foreach ($headScripts[0] as $s) {
            if (!preg_match('/\s(defer|async)\b/i', $s) && !preg_match('/type=["\']module["\']/i', $s)) {
                $renderBlocking++;
            }
        }
        $this->add('speed', 'render_blocking', 'سكربتات معطّلة للعرض (Render-Blocking)', $renderBlocking === 0 ? 'pass' : 'warn', $renderBlocking > 0 ? 'medium' : 'info', $renderBlocking === 0 ? 'مفيش سكربتات معطّلة في <head>' : "في {$renderBlocking} سكربت من غير defer/async في <head> - بيأخر عرض الصفحة");

        // redirect chain on main URL
        $mainRedirects = $this->ctx['main_redirects'] ?? 0;
        $this->add('speed', 'redirect_chain', 'سلسلة إعادة التوجيه', $mainRedirects <= 1 ? 'pass' : 'warn', $mainRedirects > 1 ? 'low' : 'info', "عدد التحويلات: {$mainRedirects}" . ($mainRedirects > 1 ? ' - قللها لتقليل زمن الاستجابة' : ''));

        // CDN detection
        $cdnSignals = ['x-cache' => 1, 'cf-ray' => 1, 'x-served-by' => 1, 'x-cdn' => 1, 'via' => 1, 'server-timing' => 1];
        $hasCdn = false;
        foreach ($cdnSignals as $sig => $_) {
            if ($this->hdr($sig) !== null) {
                $hasCdn = true;
                break;
            }
        }
        $this->add('speed', 'cdn', 'شبكة توزيع المحتوى (CDN)', $hasCdn ? 'pass' : 'info', 'info', $hasCdn ? 'في إشارة لاستخدام CDN' : 'مفيش إشارة واضحة لـ CDN - بيساعد في السرعة العالمية');
    }

    // ============================================================
    // SECURITY
    // ============================================================

    private function checkSecurity(): void
    {
        // X-Frame-Options / CSP frame-ancestors
        $xFrame = $this->hdr('x-frame-options');
        $csp = $this->hdr('content-security-policy');
        $hasFrameProtection = ($xFrame !== null) || ($csp !== null && stripos($csp, 'frame-ancestors') !== false);
        $this->add('security', 'clickjacking', 'الحماية من Clickjacking', $hasFrameProtection ? 'pass' : 'warn', $hasFrameProtection ? 'info' : 'medium', $hasFrameProtection ? 'محمي (X-Frame-Options أو CSP frame-ancestors)' : 'مفيش حماية من التضمين - خطر Clickjacking');

        // X-Content-Type-Options
        $xcto = $this->hdr('x-content-type-options');
        $this->add('security', 'content_type_sniffing', 'منع تخمين نوع المحتوى (MIME Sniffing)', $xcto !== null && stripos($xcto, 'nosniff') !== false ? 'pass' : 'warn', $xcto ? 'info' : 'medium', $xcto !== null ? 'مضبوط nosniff' : 'مفيش X-Content-Type-Options: nosniff');

        // Referrer-Policy
        $referrer = $this->hdr('referrer-policy');
        $this->add('security', 'referrer_policy', 'سياسة الإحالة (Referrer-Policy)', $referrer !== null ? 'pass' : 'info', 'info', $referrer !== null ? "مضبوط: {$referrer}" : 'مفيش Referrer-Policy - ممكن يسرب معلومات في الروابط');

        // Permissions-Policy
        $permissions = $this->hdr('permissions-policy');
        $this->add('security', 'permissions_policy', 'سياسة الصلاحيات (Permissions-Policy)', $permissions !== null ? 'pass' : 'info', 'info', $permissions !== null ? 'موجودة - بتحد من ميزات المتصفح' : 'مفيش Permissions-Policy - اختياري لتقليل سطح الهجوم');

        // CSP
        $this->add('security', 'csp', 'سياسة أمان المحتوى (CSP)', $csp !== null ? 'pass' : 'warn', $csp !== null ? 'info' : 'low', $csp !== null ? 'موجودة' : 'مفيش Content-Security-Policy - بتمنع XSS وحقن السكربتات');

        // Server header exposure
        $server = $this->hdr('server');
        $exposesVersion = $server !== null && preg_match('/([0-9]+\.[0-9]+)/', $server);
        $this->add('security', 'server_header', 'كشف ترويسة الخادم', $exposesVersion ? 'warn' : 'pass', $exposesVersion ? 'low' : 'info', $exposesVersion ? "الخادم بيكشف النسخة: {$server} - بيخلي الاستهداف أسهل" : 'مفيش كشف واضح لنسخة الخادم');

        // secure cookies
        $setCookie = $this->hdr('set-cookie');
        $insecureCookie = false;
        if ($setCookie !== null && stripos($setCookie, 'Secure') === false && stripos($this->ctx['url'] ?? '', 'https://') === 0) {
            $insecureCookie = true;
        }
        $this->add('security', 'secure_cookies', 'أمان الكوكيز (Secure/HttpOnly)', $insecureCookie ? 'warn' : 'pass', $insecureCookie ? 'medium' : 'info', $insecureCookie ? 'كوكيز من غير سمة Secure على HTTPS' : 'الكوكيز مضبوطة أو مفيش كوكيز');
    }

    // ============================================================
    // MOBILE
    // ============================================================

    private function checkMobile(): void
    {
        $html = $this->html();

        // viewport user-scalable
        if (preg_match('/<meta\s+[^>]*name=["\']viewport["\'][^>]*>/i', $html, $vm)) {
            $disablesZoom = stripos($vm[0], 'user-scalable=no') !== false || stripos($vm[0], 'maximum-scale=1') !== false;
            $this->add('mobile', 'viewport_zoom', 'إمكانية تكبير الصفحة (Zoom)', $disablesZoom ? 'warn' : 'pass', $disablesZoom ? 'low' : 'info', $disablesZoom ? 'التكبير معطل - بيضر إمكانية الوصول للمستخدمين' : 'التكبير متاح (أفضل ممارسة)');
        }

        // theme-color
        $hasThemeColor = preg_match('/<meta\s+[^>]*name=["\']theme-color["\']/i', $html);
        $this->add('mobile', 'theme_color', 'لون شريط المتصفح (Theme Color)', $hasThemeColor ? 'pass' : 'info', 'info', $hasThemeColor ? 'موجود - تجربة أفضل على الموبايل' : 'مش موجود - اختياري');

        // apple-mobile-web-app
        $hasAppleMeta = preg_match('/<meta\s+[^>]*name=["\']apple-mobile-web-app-capable["\']/i', $html);
        $this->add('mobile', 'apple_web_app', 'وضع التطبيق على iOS (Apple Web App)', $hasAppleMeta ? 'pass' : 'info', 'info', $hasAppleMeta ? 'مضبوط' : 'مش مضبوط - اختياري لشكل التطبيق على iOS');
    }

    // ============================================================
    // ACCESSIBILITY
    // ============================================================

    private function checkAccessibility(): void
    {
        $html = $this->html();

        // form inputs without labels
        preg_match_all('/<input\b[^>]*type=["\'](text|email|password|tel|number|search|textarea|select)["\'][^>]*>/i', $html, $inputs);
        $unlabeled = 0;
        foreach ($inputs[0] as $input) {
            if (preg_match('/\bid=["\'"]([^"\']+)/', $input, $idm)) {
                $id = $idm[1];
                if (!preg_match('/<label[^>]*for=["\']' . preg_quote($id, '/') . '["\']/i', $html) && !preg_match('/aria-label=/i', $input)) {
                    $unlabeled++;
                }
            } elseif (!preg_match('/aria-label=/i', $input)) {
                $unlabeled++;
            }
        }
        if (count($inputs[0]) > 0) {
            $this->add('accessibility', 'input_labels', 'تسمية حقول الإدخال (Form Labels)', $unlabeled === 0 ? 'pass' : 'warn', $unlabeled > 0 ? 'medium' : 'info', $unlabeled === 0 ? 'كل الحقول متسمية' : "في {$unlabeled} حقل من غير label - بيضر قارئات الشاشة");
        }

        // aria landmarks
        $landmarks = preg_match_all('/<(main|nav|banner|contentinfo|aside)\b/i', $html);
        $this->add('accessibility', 'aria_landmarks', 'معالم الصفحة (Landmarks)', $landmarks >= 2 ? 'pass' : 'warn', $landmarks >= 2 ? 'info' : 'low', "في {$landmarks} معلم دلالي (main/nav/footer)");

        // skip link
        $hasSkip = preg_match('/href=["\']#(main|content|skip)/i', $html);
        $this->add('accessibility', 'skip_link', 'رابط تخطي المحتوى (Skip Link)', $hasSkip ? 'pass' : 'info', 'info', $hasSkip ? 'موجود - بيساعد مستخدمي الكيبورد' : 'مش موجود - اختياري لإمكانية وصول أفضل');

        // iframe titles
        preg_match_all('/<iframe\b[^>]*>/i', $html, $iframes);
        $untitled = 0;
        foreach ($iframes[0] as $f) {
            if (!preg_match('/title=["\']/i', $f)) {
                $untitled++;
            }
        }
        if (count($iframes[0]) > 0) {
            $this->add('accessibility', 'iframe_title', 'عناوين الإطارات (iframe title)', $untitled === 0 ? 'pass' : 'warn', $untitled > 0 ? 'low' : 'info', $untitled === 0 ? 'كل الـ iframes ليها title' : "في {$untitled} iframe من غير title");
        }

        // lang attribute on html (already in base, but here ensure present)
        // skip - handled by html_lang in base audit
    }

    // ============================================================
    // AEO (Answer Engine Optimization)
    // ============================================================

    private function checkAeo(): void
    {
        $types = $this->schemaTypes();
        $html = $this->html();

        $hasQa = in_array('QAPage', $types, true) || in_array('Question', $types, true);
        $this->add('aeo', 'qa_schema', 'بيانات سؤال وجواب (QAPage Schema)', $hasQa ? 'pass' : 'info', 'info', $hasQa ? 'موجودة - مثالية لصفحات الأسئلة' : 'مش موجودة - مفيدة لو في محتوى سؤال وجواب');

        $hasArticle = in_array('Article', $types, true) || in_array('BlogPosting', $types, true) || in_array('NewsArticle', $types, true);
        $this->add('aeo', 'article_schema', 'بيانات المقالات (Article Schema)', $hasArticle ? 'pass' : 'info', 'info', $hasArticle ? 'موجودة' : 'مش موجودة - زوّدها للمقالات والمدونة');

        // listicle content (ol/ul)
        $listCount = preg_match_all('/<(ul|ol)\b[^>]*>/i', $html);
        $this->add('aeo', 'list_content', 'محتوى على شكل قوائم (Listicle)', $listCount > 0 ? 'pass' : 'info', 'info', $listCount > 0 ? "في {$listCount} قائمة - تنسيق منظم؛ ليس دليلًا على Featured Snippet" : 'مفيش قوائم - تقسيم المحتوى لقوائم يزود فرصة الـ Featured Snippet');

        // definition blocks
        $hasDl = preg_match('/<dl\b/i', $html);
        $this->add('aeo', 'definition_content', 'محتوى تعريفي (Definition)', $hasDl ? 'pass' : 'info', 'info', $hasDl ? 'موجود' : 'مفيش تعريفات منظمة - مفيدة للمصطلحات');

        // word count
        $bodyText = $this->ctx['body_text'] ?? '';
        $wordCount = mb_substr_count($bodyText, ' ') + (mb_strlen($bodyText) > 0 ? 1 : 0);
        $this->add('aeo', 'content_length', 'حجم المحتوى النصي', $wordCount >= 300 ? 'pass' : 'warn', $wordCount >= 300 ? 'info' : 'low', "حوالي {$wordCount} كلمة - المحتوى الغني بيساعد محركات الإجابة");
    }

    // ============================================================
    // GEO (Generative Engine Optimization)
    // ============================================================

    private function checkGeo(): void
    {
        $origin = $this->origin();
        $html = $this->html();

        // llms-full.txt
        $llmsFull = $this->http(rtrim($origin, '/') . '/llms-full.txt', 6, false, false);
        $hasLlmsFull = $llmsFull['code'] >= 200 && $llmsFull['code'] < 400 && !empty($llmsFull['body']);
        $this->add('geo', 'llms_full_txt', 'ملف llms-full.txt (المحتوى الكامل)', $hasLlmsFull ? 'pass' : 'info', 'info', $hasLlmsFull ? 'موجود - النسخة الكاملة للمحتوى لروبوتات AI' : 'مش موجود - اختياري، بيوفر المحتوى الكامل (مش بس الفهارس) للنماذج التوليدية');

        // OAI-SearchBot access
        $robotsBody = (string) ($this->ctx['robots_body'] ?? '');
        $oaiBlocked = preg_match('/User-agent:\s*OAI-SearchBot\s*\n(?:\s*\n)*\s*Disallow:\s*\/\s*(?:$|\n)/im', $robotsBody);
        $this->add('geo', 'oai_search_bot', 'وصول OAI-SearchBot (بحث ChatGPT)', $oaiBlocked ? 'warn' : 'pass', $oaiBlocked ? 'medium' : 'info', $oaiBlocked ? 'ظهر حظر OAI-SearchBot؛ راجع إعداد الوصول. هذا ليس تقريرًا لظهور الموقع في ChatGPT' : 'مسموح - محتواك قابل للظهور في بحث ChatGPT');

        // citations / authoritative external links
        preg_match_all('/<a\s+[^>]*href=["\'](https?:\/\/[^"\']+)["\']/i', $html, $extLinks);
        $authoritative = 0;
        $authDomains = ['wikipedia.org', 'gov', 'edu', 'who.int', 'un.org', 'scholar.google'];
        foreach ($extLinks[1] as $link) {
            $lh = parse_url($link, PHP_URL_HOST) ?? '';
            foreach ($authDomains as $d) {
                if (stripos($lh, $d) !== false) {
                    $authoritative++;
                    break;
                }
            }
        }
        $this->add('geo', 'citations', 'الاستشهاد بمصادر موثوقة', $authoritative > 0 ? 'pass' : 'info', 'info', $authoritative > 0 ? "في {$authoritative} استشهاد بمصدر موثوق - بيقوي مصداقية المحتوى عند النماذج التوليدية" : 'مفيش استشهادات بمصادر موثوقة - الاستشهاد بيزود الثقة والاستشهاد بالمحتوى');

        // schema mentions/sameAs for entity resolution
        $types = $this->schemaTypes();
        $hasSameAs = false;
        foreach ($types as $_) {
            // تقريبية: نتحقق من وجود sameAs في الـ JSON-LD الخام
        }
        $jsonLdRaw = $this->ctx['json_ld_raw'] ?? '';
        $hasSameAs = $jsonLdRaw !== '' && stripos($jsonLdRaw, 'sameAs') !== false;
        $this->add('geo', 'entity_sameas', 'روابط تعريف الكيان (sameAs)', $hasSameAs ? 'pass' : 'info', 'info', $hasSameAs ? 'في روابط sameAs - بتوحّد هوية الكيان عبر المنصات' : 'مفيش sameAs - ربط حساباتك الرسمية بيساعد في توحيد الكيان');
    }

    // ============================================================
    // Broken resources (images + assets)
    // ============================================================

    private function checkBrokenResources(): void
    {
        $html = $this->html();
        $host = $this->host();
        $url = (string) ($this->ctx['url'] ?? '');

        // broken images
        preg_match_all('/<img\s+[^>]*src=["\']([^"\']+)["\']/i', $html, $imgSrcs);
        $imgUrls = array_slice(array_unique($imgSrcs[1]), 0, 8);
        foreach ($imgUrls as $src) {
            if (stripos($src, 'data:') === 0) {
                continue;
            }
            $target = $this->resolve($url, $src);
            $res = $this->http($target, 5, true, false);
            if ($res['code'] >= 400 || $res['code'] === 0) {
                $this->brokenLinks[] = ['target_url' => $target, 'link_type' => 'image', 'status_code' => $res['code'], 'error' => $res['error'] ?: "HTTP {$res['code']}"];
            }
        }
        $brokenImgCount = count(array_filter($this->brokenLinks, fn ($b) => ($b['link_type'] ?? '') === 'image'));
        $this->add('broken_links', 'broken_images', 'فحص الصور المكسورة', $brokenImgCount === 0 ? 'pass' : 'warn', $brokenImgCount > 0 ? 'medium' : 'info', $brokenImgCount === 0 ? 'مفيش صور مكسورة في العينة' : "في {$brokenImgCount} صورة مكسورة من عينة " . count($imgUrls));
    }

    // ============================================================
    // URL helpers
    // ============================================================

    private function normalizeUrl(string $u): string
    {
        $u = preg_replace('#^https?://#i', '', $u);
        $u = rtrim($u, '/');
        return strtolower($u);
    }

    private function resolve(string $base, string $link): string
    {
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (strpos($link, '/') === 0) {
            return "{$scheme}://{$host}{$port}{$link}";
        }
        return rtrim($base, '/') . '/' . ltrim($link, '/');
    }

    private function detectSitemapUrl(string $robotsBody, string $origin): string
    {
        if (preg_match('/Sitemap:\s*(\S+)/i', $robotsBody, $m)) {
            return trim($m[1]);
        }
        $res = $this->http(rtrim($origin, '/') . '/sitemap.xml', 6, true, false);
        if ($res['code'] >= 200 && $res['code'] < 400) {
            return rtrim($origin, '/') . '/sitemap.xml';
        }
        return '';
    }

    private function countSkippedHeadings(array $levels): int
    {
        $skipped = 0;
        $prev = 0;
        foreach ($levels as $lvl) {
            if ($prev > 0 && $lvl > $prev + 1) {
                $skipped++;
            }
            $prev = max($prev, $lvl);
        }
        return $skipped;
    }

    // ============================================================
    // Advanced batches (crossing 100+ checks)
    // ============================================================

    private function checkSeoAdvanced(): void
    {
        $html = $this->html();

        // doctype
        $hasDoctype = preg_match('/^<!DOCTYPE\s+html/i', ltrim($html)) || preg_match('/<!DOCTYPE\s+html/i', substr($html, 0, 500));
        $this->add('seo', 'doctype', 'إعلان نوع المستند (DOCTYPE)', $hasDoctype ? 'pass' : 'warn', $hasDoctype ? 'info' : 'low', $hasDoctype ? 'DOCTYPE html موجود - بيفعّل وضع المعايير' : 'مفيش DOCTYPE - ممكن يشغّل وضع التوافق');

        // title vs h1 consistency
        $titleText = (string) ($this->ctx['title'] ?? '');
        $h1Text = '';
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $h1Text = trim(html_entity_decode(strip_tags($m[1])));
        }
        if ($titleText !== '' && $h1Text !== '') {
            $similar = $this->similarity(mb_strtolower($titleText), mb_strtolower($h1Text));
            $this->add('seo', 'title_h1_match', 'التطابق بين Title وH1', $similar > 0.5 ? 'pass' : 'warn', $similar > 0.5 ? 'info' : 'low', $similar > 0.5 ? 'الـ Title والـ H1 متوافقين (رسالة واضحة موحدة)' : 'الـ Title والـ H1 مختلفين بشكل ملحوظ - يفضل اتساق الرسالة');
        }

        // meta refresh redirect (deprecated)
        $hasMetaRefresh = preg_match('/<meta\s+[^>]*http-equiv=["\']refresh["\']/i', $html);
        $this->add('seo', 'meta_refresh', 'إعادة التوجيه بـ Meta Refresh', $hasMetaRefresh ? 'fail' : 'pass', $hasMetaRefresh ? 'high' : 'info', $hasMetaRefresh ? 'في meta refresh - جوجل بتنصح ضده وبيضعف UX' : 'مفيش meta refresh');

        // deprecated frames
        $hasFrames = preg_match('/<(frame|frameset)\b/i', $html);
        $this->add('seo', 'frames', 'استخدام الـ Frames (قديم)', $hasFrames ? 'fail' : 'pass', $hasFrames ? 'medium' : 'info', $hasFrames ? 'بيستخدم frame/frameset - تقنية قديمة بتضر الـ SEO' : 'مفيش frames قديمة');

        // flash
        $hasFlash = preg_match('/<(embed|object)\b[^>]*(swf|flash)/i', $html);
        $this->add('seo', 'flash', 'محتوى Flash', $hasFlash ? 'fail' : 'pass', $hasFlash ? 'low' : 'info', $hasFlash ? 'في محتوى Flash - قديم ومش مدعوم' : 'مفيش Flash');

        // inline styles
        preg_match_all('/style=["\'][^"\']{20,}["\']/i', $html, $inlineStyles);
        $inlineCount = count($inlineStyles[0]);
        $this->add('seo', 'inline_styles', 'الأنماط المضمّنة (Inline Styles)', $inlineCount <= 5 ? 'pass' : 'warn', $inlineCount > 5 ? 'low' : 'info', "في {$inlineCount} inline style - يفضل CSS خارجي للفصل والسرعة");

        // protocol-relative URLs
        $protoRelative = preg_match_all('/(?:src|href)=["\']\/\/[^"\']+["\']/i', $html);
        $this->add('seo', 'protocol_relative', 'روابط بنمط // (Protocol-Relative)', $protoRelative === 0 ? 'pass' : 'warn', $protoRelative > 0 ? 'low' : 'info', $protoRelative === 0 ? 'مفيش روابط protocol-relative' : "في {$protoRelative} رابط // - أسلوب قديم، استخدم https مباشرة");

        // empty alt (decorative) vs missing alt
        preg_match_all('/<img\s+[^>]*>/i', $html, $allImgs);
        $emptyAlt = 0;
        foreach ($allImgs[0] as $tag) {
            if (preg_match('/alt=["\']["\']/', $tag)) {
                $emptyAlt++;
            }
        }
        if (count($allImgs[0]) > 0) {
            $this->add('accessibility', 'alt_empty_vs_missing', 'التمييز بين alt فارغ ومفقود', $emptyAlt >= 0 ? 'info' : 'info', 'info', "في {$emptyAlt} صورة بـ alt=\"\" (صحيحة للصور التزيينية)");
        }
    }

    private function checkSpeedAdvanced(): void
    {
        $html = $this->html();

        // resource hints (preconnect/preload/dns-prefetch)
        $hints = preg_match_all('/<link\s+[^>]*rel=["\'](preconnect|preload|dns-prefetch|prefetch)["\']/i', $html);
        $this->add('speed', 'resource_hints', 'تلميحات الموارد (Preconnect/Preload)', $hints > 0 ? 'pass' : 'info', 'info', $hints > 0 ? "في {$hints} تلميح موارد - بيسرع جلب الأصول الحرجة" : 'مفيش preconnect/preload - ممكن يسرع تحميل الخطوط والـ CDN');

        // inline scripts count
        preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $html, $inlineScripts);
        $inlineCount = count($inlineScripts[0]);
        $this->add('speed', 'inline_scripts', 'السكربتات المضمّنة (Inline Scripts)', $inlineCount <= 3 ? 'pass' : 'warn', $inlineCount > 3 ? 'low' : 'info', "في {$inlineCount} inline script");

        // stylesheet count
        preg_match_all('/<link\s+[^>]*rel=["\']stylesheet["\']/i', $html, $cssFiles);
        $cssCount = count($cssFiles[0]);
        $this->add('speed', 'css_count', 'عدد ملفات CSS', $cssCount <= 5 ? 'pass' : 'warn', $cssCount > 5 ? 'low' : 'info', "في {$cssCount} ملف CSS");

        // font-display
        $hasFontDisplay = preg_match('/font-display\s*:\s*(swap|optional|block)/i', $html);
        $this->add('speed', 'font_display', 'استراتيجية تحميل الخطوط (font-display)', $hasFontDisplay ? 'pass' : 'info', 'info', $hasFontDisplay ? 'ظهرت قيمة font-display داخل HTML؛ يلزم فحص CSS والعرض الفعلي' : 'مفيش إشارة لـ font-display - لو بتستخدم خطوط خارجية ضبطه بـ swap');
    }

    private function checkSecurityAdvanced(): void
    {
        // HSTS strength
        $hsts = $this->hdr('strict-transport-security');
        if ($hsts !== null) {
            $hasMaxAge = preg_match('/max-age=(\d+)/i', $hsts, $mm) && (int) $mm[1] >= 15552000;
            $hasSubdomains = stripos($hsts, 'includeSubDomains') !== false;
            $hasPreload = stripos($hsts, 'preload') !== false;
            $this->add('security', 'hsts_strength', 'قوة ترويسة HSTS', ($hasMaxAge && $hasSubdomains) ? 'pass' : 'warn', ($hasMaxAge && $hasSubdomains) ? 'info' : 'low', 'max-age=' . ($mm[1] ?? '?') . ($hasSubdomains ? ' + subdomains' : '') . ($hasPreload ? ' + preload' : '') . ($hasMaxAge ? '' : ' - max-age قصير، يفضل سنة على الأقل'));
        }

        // X-XSS-Protection (deprecated)
        $xxss = $this->hdr('x-xss-protection');
        $this->add('security', 'xss_header', 'ترويسة X-XSS-Protection (قديمة)', $xxss !== null ? 'info' : 'pass', 'info', $xxss !== null ? 'موجودة - header قديم، الأفضل الاعتماد على CSP' : 'مفيش (أفضل - header مهجور، استخدم CSP بدله)');
    }

    private function checkMobileAdvanced(): void
    {
        $html = $this->html();

        // responsive signals (media queries)
        $hasMediaQuery = preg_match('/@media\b/i', $html) || preg_match('/<link[^>]*media=["\'](?:\([^)]*\)|screen and[^"\']*)/i', $html);
        $this->add('mobile', 'responsive_design', 'التصميم المتجاوب (Responsive)', 'info', 'info', $hasMediaQuery ? 'في إشارة لـ media queries؛ يلزم اختبار عرض الصفحة على الموبايل' : 'لم تظهر media queries داخل HTML؛ قد تكون في CSS خارجي. حالة التجاوب غير محسومة');
    }

    private function checkAccessibilityAdvanced(): void
    {
        $html = $this->html();

        // title attributes on links
        preg_match_all('/<a\b[^>]*>/i', $html, $links);
        $withTitle = 0;
        foreach ($links[0] as $l) {
            if (preg_match('/\stitle=["\']/i', $l)) {
                $withTitle++;
            }
        }
        if (count($links[0]) > 0) {
            $this->add('accessibility', 'link_titles', 'سمات Title على الروابط', $withTitle > 0 ? 'info' : 'pass', 'info', "{$withTitle} من " . count($links[0]) . ' رابط بيه title - غالبًا مش ضروري وبيضيف ضوضاء لقارئات الشاشة');
        }

        // aria-label presence
        $ariaCount = preg_match_all('/aria-label=/i', $html);
        $this->add('accessibility', 'aria_labels', 'استخدام ARIA labels', $ariaCount > 0 ? 'pass' : 'info', 'info', $ariaCount > 0 ? "في {$ariaCount} aria-label" : 'مفيش aria-label - مفيد للعناصر من غير نص مرئي');
    }

    private function checkAeoAdvanced(): void
    {
        $html = $this->html();

        // ordered lists for step-by-step
        $hasOl = preg_match('/<ol\b/i', $html);
        $this->add('aeo', 'ordered_lists', 'قوائم مرتبة (خطوات)', $hasOl ? 'pass' : 'info', 'info', $hasOl ? 'في قوائم مرتبة - مثالية لمحتوى "إزاي" والخطوات' : 'مفيش قوائم مرتبة - مناسبة لشرح الخطوات');

        // how-to headings
        $howToHeadings = preg_match_all('/<h[1-3][^>]*>[^<]*(كيف|إزاي|طريقة|how to|steps|خطوات)[^<]*<\/h[1-3]>/iu', $html);
        $this->add('aeo', 'howto_headings', 'عناوين إرشادية (How-To)', $howToHeadings > 0 ? 'pass' : 'info', 'info', $howToHeadings > 0 ? "في {$howToHeadings} عنوان إرشادي - محتوى إرشادي؛ وجود العنوان لا يضمن Featured Snippet" : 'مفيش عناوين إرشادية صريحة');
    }

    private function checkGeoAdvanced(): void
    {
        $robotsBody = (string) ($this->ctx['robots_body'] ?? '');

        // dedicated AI bot section in robots.txt
        $aiSections = 0;
        foreach (['GPTBot', 'Google-Extended', 'ClaudeBot', 'PerplexityBot', 'CCBot', 'OAI-SearchBot'] as $bot) {
            if (stripos($robotsBody, $bot) !== false) {
                $aiSections++;
            }
        }
        $this->add('geo', 'ai_bot_sections', 'أقسام مخصصة لروبوتات AI في robots.txt', $aiSections > 0 ? 'pass' : 'info', 'info', $aiSections > 0 ? "في {$aiSections} قسم مخصص - تحكم دقيق في وصول روبوتات AI" : 'مفيش أقسام مخصصة - ممكن تحدد وصول كل روبوت AI على حدة');
    }

    /** تشابه نصي بسيط بين سلسلتين (نسبة تطابق الكلمات) */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $wa = array_filter(explode(' ', $a));
        $wb = array_filter(explode(' ', $b));
        if (empty($wa) || empty($wb)) {
            return 0.0;
        }
        $intersect = count(array_intersect($wa, $wb));
        return $intersect / max(count($wa), count($wb));
    }
}
