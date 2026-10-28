<?php
/**
 * Tourfecto - سرعة الصفحة الحقيقية من جوجل (PageSpeed Insights / Lighthouse + بيانات زوار Chrome الحقيقية CrUX)
 *
 * مفتاح الـ API اختياري (pagespeed_api_key في إعدادات النظام): من غيره جوجل بيسمح بعدد طلبات قليل.
 * النتيجة: درجة الأداء على الموبايل، مقاييس Core Web Vitals (LCP / INP / CLS)، وأكبر الحاجات اللي بتبطّأ الصفحة
 * مع الوقت اللي هيتوفر لو اتصلّحت.
 */
class PageSpeedService
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    /** حدود جوجل لـ "كويس" */
    public const GOOD = ['lcp_ms' => 2500, 'inp_ms' => 200, 'cls' => 0.1];

    /** @var callable|null للاختبار: fn(string $url): array{code:int, body:?string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    private function key(): string
    {
        try {
            return class_exists('SystemSettingsService') ? trim((new SystemSettingsService())->get('pagespeed_api_key', (string) (getenv('PAGESPEED_API_KEY') ?: ''))) : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return array{ok:bool, error?:string, url:string, strategy:string, score?:int, lab?:array, field?:?array, opportunities?:array, checked_at:string}
     */
    public function run(string $url, string $strategy = 'mobile'): array
    {
        $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';
        $out = ['ok' => false, 'url' => $url, 'strategy' => $strategy, 'checked_at' => gmdate('c')];
        if (!preg_match('#^https?://#i', $url)) {
            return $out + ['error' => 'invalid_url'];
        }
        $q = ['url' => $url, 'strategy' => $strategy, 'category' => 'performance'];
        if (($k = $this->key()) !== '') {
            $q['key'] = $k;
        }
        $req = self::ENDPOINT . '?' . http_build_query($q);
        if ($this->transport) {
            $r = ($this->transport)($req);
        } else {
            $ch = curl_init($req);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 70, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
            $body = curl_exec($ch);
            $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : null];
            curl_close($ch);
        }
        $d = is_string($r['body'] ?? null) ? json_decode($r['body'], true) : null;
        if (($r['code'] ?? 0) !== 200 || !is_array($d) || empty($d['lighthouseResult'])) {
            $msg = (string) ($d['error']['message'] ?? '');
            return $out + ['error' => ($r['code'] ?? 0) === 429 ? 'quota' : ($msg !== '' ? mb_substr($msg, 0, 200) : 'unavailable')];
        }
        return self::parse($d) + $out;
    }

    /** تحويل رد جوجل لأرقام مفهومة */
    public static function parse(array $d): array
    {
        $lh = $d['lighthouseResult'];
        $a = $lh['audits'] ?? [];
        $num = static fn ($k) => isset($a[$k]['numericValue']) ? (float) $a[$k]['numericValue'] : null;
        $lab = [
            'lcp_ms' => $num('largest-contentful-paint'),
            'cls' => $num('cumulative-layout-shift'),
            'tbt_ms' => $num('total-blocking-time'),
            'fcp_ms' => $num('first-contentful-paint'),
            'si_ms' => $num('speed-index'),
        ];
        // بيانات زوار Chrome الحقيقية (لو الموقع عليه زيارات كفاية)
        $field = null;
        $le = $d['loadingExperience']['metrics'] ?? ($d['originLoadingExperience']['metrics'] ?? null);
        if (is_array($le) && $le) {
            $field = [
                'source' => isset($d['loadingExperience']['metrics']) ? 'page' : 'origin',
                'lcp_ms' => $le['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null,
                'inp_ms' => $le['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null,
                'cls' => isset($le['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile']) ? $le['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null,
                'overall' => $d['loadingExperience']['overall_category'] ?? ($d['originLoadingExperience']['overall_category'] ?? null),
            ];
        }
        $ops = [];
        foreach ($a as $id => $audit) {
            $save = (float) ($audit['details']['overallSavingsMs'] ?? 0);
            if (($audit['details']['type'] ?? '') === 'opportunity' && $save >= 150 && ($audit['score'] ?? 1) < 0.9) {
                $ops[] = ['id' => (string) $id, 'title' => (string) ($audit['title'] ?? $id), 'savings_ms' => (int) round($save), 'savings_kb' => (int) round(((float) ($audit['details']['overallSavingsBytes'] ?? 0)) / 1024)];
            }
        }
        usort($ops, static fn ($x, $y) => $y['savings_ms'] <=> $x['savings_ms']);
        $score = isset($lh['categories']['performance']['score']) ? (int) round($lh['categories']['performance']['score'] * 100) : null;
        return ['ok' => $score !== null, 'score' => $score, 'lab' => $lab, 'field' => $field, 'opportunities' => array_slice($ops, 0, 6)];
    }

    /** شرح عربي/إنجليزي لأشهر مشاكل السرعة + طريقة حلها في ووردبريس */
    public const FIXES = [
        'render-blocking-resources' => ['ar' => 'ملفات CSS/JS بتأخر ظهور الصفحة: فعّل "تأجيل JavaScript" و"Critical CSS" من إضافة كاش (WP Rocket / LiteSpeed Cache).', 'en' => 'CSS/JS block rendering: enable "Delay JavaScript" and "Critical CSS" in a cache plugin (WP Rocket / LiteSpeed Cache).'],
        'unused-javascript' => ['ar' => 'JavaScript مش مستخدم: اقفل الإضافات اللي مش محتاجها، وفعّل "Delay JS" في إضافة الكاش.', 'en' => 'Unused JavaScript: disable plugins you do not need and enable "Delay JS" in your cache plugin.'],
        'unused-css-rules' => ['ar' => 'CSS مش مستخدم: فعّل "Remove Unused CSS" في إضافة الكاش.', 'en' => 'Unused CSS: enable "Remove Unused CSS" in your cache plugin.'],
        'modern-image-formats' => ['ar' => 'الصور تقيلة: حوّلها لـ WebP (إضافة Imagify / ShortPixel / LiteSpeed Image Optimization).', 'en' => 'Heavy images: convert to WebP (Imagify / ShortPixel / LiteSpeed Image Optimization).'],
        'uses-optimized-images' => ['ar' => 'الصور مش مضغوطة: اضغطها بإضافة ضغط الصور قبل الرفع.', 'en' => 'Images are not compressed: compress them with an image optimization plugin.'],
        'uses-responsive-images' => ['ar' => 'الصور أكبر من مقاس الشاشة: ارفع صور بمقاس مناسب (عرض 1600px كفاية للهيرو).', 'en' => 'Images are larger than displayed: upload properly sized images (1600px wide is enough for heroes).'],
        'offscreen-images' => ['ar' => 'صور تحت الصفحة بتتحمل بدري: فعّل Lazy Load للصور.', 'en' => 'Offscreen images load early: enable lazy loading for images.'],
        'server-response-time' => ['ar' => 'السيرفر بطيء في الرد: فعّل كاش الصفحات، أو انقل لاستضافة أسرع / CDN (Cloudflare).', 'en' => 'Slow server response: enable page caching or move to faster hosting / a CDN (Cloudflare).'],
        'uses-text-compression' => ['ar' => 'الملفات مش مضغوطة: فعّل GZIP/Brotli من الاستضافة أو Cloudflare.', 'en' => 'No text compression: enable GZIP/Brotli at the host or Cloudflare.'],
        'unminified-javascript' => ['ar' => 'ملفات JavaScript مش مصغّرة: فعّل Minify JS في إضافة الكاش.', 'en' => 'JavaScript is not minified: enable Minify JS in your cache plugin.'],
        'unminified-css' => ['ar' => 'ملفات CSS مش مصغّرة: فعّل Minify CSS في إضافة الكاش.', 'en' => 'CSS is not minified: enable Minify CSS in your cache plugin.'],
        'efficient-animated-content' => ['ar' => 'صور GIF تقيلة: حوّلها لفيديو MP4/WebM.', 'en' => 'Heavy GIFs: convert them to MP4/WebM video.'],
        'redirects' => ['ar' => 'تحويلات كتير قبل ما الصفحة تفتح: خلّي الروابط تشاور على العنوان النهائي مباشرة.', 'en' => 'Multiple redirects: link directly to the final URL.'],
        'preload-lcp-image' => ['ar' => 'صورة الهيرو بتتحمل متأخر: اعمل لها Preload وبطّل Lazy Load عليها.', 'en' => 'The hero image loads late: preload it and do not lazy-load it.'],
    ];

    /** نص مهمة واضح للعميل (بالأرقام وطريقة الحل) */
    public static function taskText(array $r, string $lang = 'ar'): array
    {
        $ar = $lang === 'ar';
        $f = $r['field'] ?? null;
        $lab = $r['lab'] ?? [];
        $lcp = $f['lcp_ms'] ?? $lab['lcp_ms'] ?? null;
        $cls = $f['cls'] ?? $lab['cls'] ?? null;
        $inp = $f['inp_ms'] ?? null;
        $fmt = static fn ($ms) => $ms === null ? '-' : number_format($ms / 1000, 1) . 's';
        $lines = [];
        $lines[] = ($ar ? 'درجة السرعة على الموبايل: ' : 'Mobile performance score: ') . ($r['score'] ?? '-') . '/100' . ($f ? ($ar ? ' (فيها بيانات زوار حقيقيين من Chrome)' : ' (includes real Chrome user data)') : '');
        $lines[] = 'LCP: ' . $fmt($lcp) . ($ar ? ' (لازم أقل من 2.5s)' : ' (should be under 2.5s)');
        if ($inp !== null) {
            $lines[] = 'INP: ' . (int) $inp . 'ms' . ($ar ? ' (لازم أقل من 200ms)' : ' (should be under 200ms)');
        }
        $lines[] = 'CLS: ' . ($cls === null ? '-' : number_format((float) $cls, 2)) . ($ar ? ' (لازم أقل من 0.1)' : ' (should be under 0.1)');
        foreach ($r['opportunities'] ?? [] as $o) {
            $fix = self::FIXES[$o['id']][$ar ? 'ar' : 'en'] ?? $o['title'];
            $lines[] = '• ' . $fix . ' (' . ($ar ? 'يوفّر حوالي ' : 'saves about ') . number_format($o['savings_ms'] / 1000, 1) . 's)';
        }
        $lines[] = $ar ? 'بعد التصليح، الدورة الجاية بتقيس تاني وتقارن.' : 'The next cycle measures again and compares.';
        $bad = ($r['score'] ?? 100) < 70 || ($lcp !== null && $lcp > self::GOOD['lcp_ms']) || ($cls !== null && $cls > self::GOOD['cls']) || ($inp !== null && $inp > self::GOOD['inp_ms']);
        return ['needs_work' => $bad, 'title' => $ar ? 'سرعة الموبايل: ' . ($r['score'] ?? '-') . '/100 - ' . ($bad ? 'محتاجة تحسين' : 'كويسة') : 'Mobile speed: ' . ($r['score'] ?? '-') . '/100', 'instructions' => implode("\n", $lines)];
    }
}
