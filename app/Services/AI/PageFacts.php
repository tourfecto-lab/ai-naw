<?php

/**
 * Tourfecto - بيانات حقيقية من صفحة ويب قبل ما الذكاء الاصطناعي يحلّلها
 *
 * تحليل SEO/AEO/GEO كان بيبعت للنموذج الروابط بس، فالنتيجة كانت تخمين عن صفحات
 * ماشافهاش. هنا بنجيب الصفحة فعلًا (بأمان عن طريق SafeHttp) ونطلّع منها الحقائق
 * اللي الـ SEO بيعتمد عليها، والنموذج بيبني تحليله عليها.
 */
class PageFacts
{
    /** @return array{ok:bool, url:string, error?:string, ...} */
    public static function extract(string $url, int $timeout = 12): array
    {
        $t0 = microtime(true);
        if (!class_exists('SafeHttp')) {
            return ['ok' => false, 'url' => $url, 'error' => 'fetch unavailable'];
        }
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +SEO audit)',
            CURLOPT_HTTPHEADER => ['Accept-Language: ar,en;q=0.8'],
        ], 5);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $html = is_string($r['response']) ? $r['response'] : '';
        if ($r['code'] < 200 || $r['code'] >= 400 || $html === '') {
            return ['ok' => false, 'url' => $url, 'status' => $r['code'], 'error' => $r['error'] ?: ('HTTP ' . $r['code'])];
        }
        $html = substr($html, 0, 2_000_000);

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $txt = fn($n) => trim(preg_replace('/\s+/u', ' ', (string) ($n ? $n->textContent : '')));
        $attr = function (string $q, string $a) use ($xp): string {
            $n = $xp->query($q)->item(0);
            return $n instanceof DOMElement ? trim($n->getAttribute($a)) : '';
        };
        $list = function (string $q, int $max) use ($xp, $txt): array {
            $out = [];
            foreach ($xp->query($q) as $n) {
                $t = mb_substr($txt($n), 0, 120);
                if ($t !== '') {
                    $out[] = $t;
                }
                if (count($out) >= $max) {
                    break;
                }
            }
            return $out;
        };

        // أنواع Schema (JSON-LD)
        $schema = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
            $j = json_decode(trim($s->textContent), true);
            $walk = function ($v) use (&$walk, &$schema) {
                if (is_array($v)) {
                    if (isset($v['@type'])) {
                        foreach ((array) $v['@type'] as $ty) {
                            $schema[(string) $ty] = true;
                        }
                    }
                    foreach ($v as $x) {
                        $walk($x);
                    }
                }
            };
            $walk($j);
        }

        $host = strtolower((string) parse_url($r['url'], PHP_URL_HOST));
        $internal = 0;
        $external = 0;
        foreach ($xp->query('//a[@href]') as $a) {
            $h = (string) $a->getAttribute('href');
            $hh = strtolower((string) parse_url($h, PHP_URL_HOST));
            if ($hh === '' || $hh === $host) {
                $internal++;
            } else {
                $external++;
            }
        }
        $imgs = $xp->query('//img');
        $noAlt = 0;
        foreach ($imgs as $i) {
            if (trim($i->getAttribute('alt')) === '') {
                $noAlt++;
            }
        }
        foreach ($xp->query('//script|//style|//noscript|//svg') as $n) {
            $n->parentNode->removeChild($n);
        }
        $body = $xp->query('//body')->item(0);
        // مسافة بين العناصر عشان الكلمات ماتلزقش في بعض (textContent بيدمج العناوين والفقرات)
        $bodyHtml = $body ? $doc->saveHTML($body) : '';
        $bodyText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $bodyHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $words = count(preg_split('/\s+/u', $bodyText, -1, PREG_SPLIT_NO_EMPTY));
        $hreflang = [];
        foreach ($xp->query('//link[@rel="alternate"][@hreflang]') as $l) {
            $hreflang[] = $l->getAttribute('hreflang');
        }

        return [
            'ok' => true,
            'url' => $r['url'],
            'status' => $r['code'],
            'load_ms' => $ms,
            'lang' => $attr('//html', 'lang'),
            'title' => mb_substr($txt($xp->query('//title')->item(0)), 0, 200),
            'meta_description' => mb_substr($attr('//meta[@name="description"]', 'content'), 0, 300),
            'canonical' => $attr('//link[@rel="canonical"]', 'href'),
            'robots' => $attr('//meta[@name="robots"]', 'content'),
            'og_title' => $attr('//meta[@property="og:title"]', 'content'),
            'h1' => $list('//h1', 3),
            'h2' => $list('//h2', 12),
            'word_count' => $words,
            'schema_types' => array_slice(array_keys($schema), 0, 20),
            'hreflang' => array_slice(array_values(array_unique($hreflang)), 0, 15),
            'images' => $imgs->length,
            'images_without_alt' => $noAlt,
            'internal_links' => $internal,
            'external_links' => $external,
            'has_faq' => isset($schema['FAQPage']) || (bool) preg_match('/(FAQ|الأسئلة الشائعة|أسئلة شائعة)/u', $bodyText),
            'text_sample' => mb_substr($bodyText, 0, 1500),
        ];
    }

    /** ملخص نصي للبرومبت */
    public static function toPrompt(array $f, string $label): string
    {
        if (empty($f['ok'])) {
            return "### {$label}: {$f['url']}\n(تعذر جلب الصفحة: " . ($f['error'] ?? 'unknown') . " - لا تخمّن محتواها، واذكر إن البيانات ناقصة)\n";
        }
        $lines = [
            "### {$label}: {$f['url']}",
            "- حالة HTTP: {$f['status']} · زمن التحميل من سيرفرنا: {$f['load_ms']}ms · لغة الصفحة: " . ($f['lang'] ?: 'غير محددة'),
            '- العنوان (title): ' . ($f['title'] ?: 'غير موجود') . ' (' . mb_strlen($f['title']) . ' حرف)',
            '- الوصف (meta description): ' . ($f['meta_description'] ?: 'غير موجود') . ' (' . mb_strlen($f['meta_description']) . ' حرف)',
            '- H1: ' . ($f['h1'] ? implode(' | ', $f['h1']) : 'غير موجود'),
            '- H2: ' . ($f['h2'] ? implode(' | ', $f['h2']) : 'غير موجود'),
            "- عدد الكلمات: {$f['word_count']} · الصور: {$f['images']} (منها {$f['images_without_alt']} من غير alt) · روابط داخلية: {$f['internal_links']} · خارجية: {$f['external_links']}",
            '- Schema: ' . ($f['schema_types'] ? implode(', ', $f['schema_types']) : 'مفيش') . ' · FAQ: ' . ($f['has_faq'] ? 'موجود' : 'مش موجود'),
            '- canonical: ' . ($f['canonical'] ?: 'مفيش') . ' · robots: ' . ($f['robots'] ?: 'افتراضي') . ' · hreflang: ' . ($f['hreflang'] ? implode(',', $f['hreflang']) : 'مفيش'),
            '- عيّنة من النص: ' . $f['text_sample'],
        ];
        return implode("\n", $lines) . "\n";
    }
}
