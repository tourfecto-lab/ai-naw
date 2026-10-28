<?php

/**
 * Tourfecto - AutoFix Value Builder
 * بيبني القيمة الحقيقية لكل إصلاح من حقائق صفحة العميل. الأكواد الجاهزة في التدقيق قوالب بكلام وهمي
 * (مثلًا "اسم البراند" و"yourpage")، فتطبيقها كان هيحط كلام غلط في الموقع. هنا:
 *   - العنوان والوصف: الذكاء الاصطناعي (لو متاح) بنفس لغة الصفحة، ولو فشل أو رجّع قالب بنرجع لقيمة محسوبة من الصفحة.
 *   - الصورة والشعار وروابط السوشيال: بنستخدم اللي موجود فعلاً في الصفحة ومتأكدين منه، ومش بنخمّن مسارات.
 *   - أي قيمة فيها كلام قالب بتترفض (looksLikePlaceholder).
 *
 * @version 1.0.0
 */
class FixValueBuilder
{
    /** @var object|null يوفّر generateContent($prompt, $options) */
    private $ai;
    private $aiCache = [];

    public function __construct($ai = null)
    {
        $this->ai = $ai;
    }

    private const PLACEHOLDERS = [
        'اسم البراند', 'اسم الشركة', 'الكلمة المفتاحية', 'وصف مختصر', 'عنوان الصفحة', 'سؤال شائع', 'yourpage', 'your-page',
        'coming soon', 'something is happening', 'just another coming soon page', 'under construction', 'example.com', 'lorem ipsum', 'your company', 'brand name', 'your brand', 'اكتب هنا',
    ];

    public static function looksLikePlaceholder(string $s): bool
    {
        $l = mb_strtolower($s);
        foreach (self::PLACEHOLDERS as $p) {
            if (mb_strpos($l, mb_strtolower($p)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $fix  صف wo_fixes (check_key)
     * @param array $ctx  ناتج PageContext::fetch
     * @return array{success:bool, value?:string, warnings:array, error?:string, source?:string}
     */
    public function build(array $fix, array $ctx, int $userId = 0): array
    {
        $cap = FixCapability::forCheck($fix['check_key'] ?? null);
        if ($cap === null) {
            return ['success' => false, 'warnings' => [], 'error' => 'not_automatable'];
        }
        if (empty($ctx['ok'])) {
            return ['success' => false, 'warnings' => [], 'error' => 'page_unreachable'];
        }
        if(!empty($ctx['coming_soon']))return ['success'=>false,'warnings'=>[],'error'=>'coming_soon_page'];
        $w = [];
        switch ($cap['field']) {
            case 'title':
                $r = $this->titleAndDescription($ctx, $userId);
                return $this->done($r['title'], $r['source'], $w);

            case 'description':
                $r = $this->titleAndDescription($ctx, $userId);
                return $this->done($r['description'], $r['source'], $w);

            case 'canonical':
                $u = $this->canonicalOf($ctx);
                return $u === '' ? $this->fail('no_canonical') : $this->done($u, 'page', $w);

            case 'robots':
                if(stripos($ctx['robots']??'','noindex')!==false)return $this->fail('indexing_restricted');
                return $this->done('index, follow', 'static', $w);

            case 'viewport':
                return $this->done('width=device-width, initial-scale=1', 'static', $w);

            case 'lang':
                $lang = $this->langOf($ctx);
                return $this->done($lang, 'page', $w);

            case 'og':
                $r = $this->titleAndDescription($ctx, $userId);
                $og = ['og:type' => 'website', 'og:title' => $r['title'], 'og:description' => $r['description'], 'og:url' => $this->canonicalOf($ctx)];
                if ($this->siteName($ctx) !== '') {
                    $og['og:site_name'] = $this->siteName($ctx);
                }
                if (!empty($ctx['image'])) {
                    $og['og:image'] = $ctx['image'];
                    $og['twitter:card'] = 'summary_large_image';
                } else {
                    $og['twitter:card'] = 'summary';
                    $w[] = 'no_image_found';   // ما لقيناش صورة حقيقية، فمش هنخمّن مسار
                }
                $og['twitter:title'] = $r['title'];
                $og['twitter:description'] = $r['description'];
                return $this->done(json_encode($og, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['source'], $w);

            case 'jsonld':
                $r = $this->titleAndDescription($ctx, $userId);
                $origin = $this->originOf($ctx);
                $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $this->siteName($ctx), 'url' => $origin];
                if (!empty($ctx['logo'])) {
                    $org['logo'] = $ctx['logo'];
                } else {
                    $w[] = 'no_logo_found';
                }
                if ($r['description'] !== '') {
                    $org['description'] = $r['description'];
                }
                $profiles=[];
                try{$profiles=AiVisibilityService::customerProfiles($userId,(int)($fix['website_id']??0));}catch(Throwable $e){$w[]='confirmed_profiles_unavailable';}
                if($profiles)$org['sameAs']=$profiles;else $w[]='no_customer_confirmed_profiles';
                return $this->done(json_encode($org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['source'], $w);

            case 'hsts':
                if (empty($ctx['https'])) {
                    return $this->fail('site_not_https');
                }
                // 30 يوم من غير includeSubDomains ولا preload: أقل خطورة. ممكن ترفعها لسنة بعد ما تتأكد إن كل شيء HTTPS.
                $w[] = 'hsts_conservative';
                return $this->done('max-age=2592000', 'static', $w);

            case 'llms_txt':
                try{$txt=(new GeoLlmsBuilder())->build($this->originOf($ctx).'/');return $this->done($txt,'published_page_evidence',['review_published_facts_before_approval','no_ranking_guarantee']);}
                catch(Throwable $e){return $this->fail('published_pages_unavailable');}

        }
        return $this->fail('not_automatable');
    }

    // ------------------------------------------------------------------ حقائق الصفحة

    private function originOf(array $ctx): string
    {
        $p = parse_url($ctx['final_url'] ?? $ctx['url']);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (!empty($p['port']) ? ':' . $p['port'] : '');
    }

    private function canonicalOf(array $ctx): string
    {
        $p = parse_url($ctx['final_url'] ?? $ctx['url']);
        if (empty($p['host'])) {
            return '';
        }
        $path = $p['path'] ?? '/';
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '') . ($path === '' ? '/' : $path);
    }

    private function langOf(array $ctx): string
    {
        if (!empty($ctx['lang']) && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $ctx['lang'])) {
            return $ctx['lang'];
        }
        $sample = ($ctx['title'] ?? '') . ' ' . ($ctx['text'] ?? '');
        $ar = preg_match_all('/\p{Arabic}/u', $sample);
        $all = max(1, preg_match_all('/\p{L}/u', $sample));
        return ($ar / $all) > 0.4 ? 'ar' : 'en';
    }

    /** اسم الموقع/البراند: og:site_name، وإلا أقصر جزء من الـ title، وإلا الدومين */
    private function siteName(array $ctx): string
    {
        if (!empty($ctx['site_name']) && !self::looksLikePlaceholder($ctx['site_name'])) {
            return $ctx['site_name'];
        }
        $title = (string) ($ctx['title'] ?? '');
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s[\|\-–—·•]\s/u', $title) ?: [])));
        if ($parts) {
            usort($parts, function ($a, $b) {
                return mb_strlen($a) <=> mb_strlen($b);
            });
            if (mb_strlen($parts[0]) >= 2 && mb_strlen($parts[0]) <= 40) {
                return $parts[0];
            }
        }
        $host = preg_replace('/^www\./', '', (string) parse_url($ctx['final_url'] ?? $ctx['url'], PHP_URL_HOST));
        return $host;
    }

    // ------------------------------------------------------------------ العنوان والوصف (ذكاء اصطناعي ثم احتياطي محسوب)

    /** @return array{title:string, description:string, source:string} */
    private function titleAndDescription(array $ctx, int $userId): array
    {
        $key = md5(($ctx['final_url'] ?? '') . ($ctx['title'] ?? ''));
        if (isset($this->aiCache[$key])) {
            return $this->aiCache[$key];
        }
        $title = '';
        $desc = '';
        $source = 'heuristic';

        if ($this->ai !== null) {
            $lang = $this->langOf($ctx);
            $name = $this->siteName($ctx);
            $prompt = "You write SEO metadata for a real website. Use ONLY the facts below. Do not invent services, prices, awards or locations.\n"
                . 'Language of output: ' . ($lang === 'ar' ? 'Arabic' : 'the same language as the page (' . $lang . ')') . ".\n"
                . "Brand: {$name}\nCurrent title: " . ($ctx['title'] ?? '') . "\nMain heading: " . ($ctx['h1'] ?? '') . "\nCurrent description: " . ($ctx['description'] ?? '') . "\nPage text excerpt: " . ($ctx['text'] ?? '') . "\n\n"
                . "Rules: title max 60 characters and includes the brand; description 120-155 characters, an inviting summary of what the page offers; no quotes, no markdown, no placeholders.\n"
                . 'Return ONLY JSON: {"title":"...","description":"..."}';
            try {
                $res = $this->ai->generateContent($prompt, ['temperature' => 0.4, 'maxOutputTokens' => 600, 'responseMimeType' => 'application/json', 'task' => 'autofix_values', 'output_language'=>$lang, 'user_id' => $userId ?: null]);
                if (!empty($res['success'])) {
                    $j = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim((string) $res['data']))), true);
                    if (is_array($j)) {
                        $t = $this->plain((string) ($j['title'] ?? ''));
                        $d = $this->plain((string) ($j['description'] ?? ''));
                        if ($this->okTitle($t)) {
                            $title = $t;
                        }
                        if ($this->okDescription($d)) {
                            $desc = $d;
                        }
                        if ($title !== '' || $desc !== '') {
                            $source = 'ai';
                        }
                    }
                }
            } catch (\Throwable $e) {
                // نكمل بالاحتياطي
            }
        }

        if ($title === '') {
            $t = $this->plain((string) ($ctx['title'] ?? ''));
            if ($t === '' || self::looksLikePlaceholder($t)) {
                $t = $this->plain((string) ($ctx['h1'] ?? ''));
            }
            $name = $this->siteName($ctx);
            if ($t === '') {
                $t = $name;
            } elseif (mb_stripos($t, $name) === false && mb_strlen($t) + mb_strlen($name) + 3 <= 60) {
                $t .= ' | ' . $name;
            }
            $title = mb_substr($t, 0, 60);
        }
        if ($desc === '') {
            $d = $this->plain((string) ($ctx['description'] ?? ''));
            if (!$this->okDescription($d)) {
                $d = $this->plain((string) ($ctx['text'] ?? ''));
                $d = mb_strlen($d) > 155 ? rtrim(mb_substr($d, 0, 152)) . '...' : $d;
            }
            $desc = $d;
        }
        return $this->aiCache[$key] = ['title' => $title, 'description' => $desc, 'source' => $source];
    }

    private function plain(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return trim($s, " \t\"'`");
    }

    private function okTitle(string $t): bool
    {
        $n = mb_strlen($t);
        return $n >= 10 && $n <= 70 && !self::looksLikePlaceholder($t);
    }

    private function okDescription(string $d): bool
    {
        $n = mb_strlen($d);
        return $n >= 50 && $n <= 200 && !self::looksLikePlaceholder($d);
    }

    // ------------------------------------------------------------------ نتائج

    private function done(string $value, string $source, array $warnings): array
    {
        $value = trim($value);
        if ($value === '' || self::looksLikePlaceholder($value)) {
            return ['success' => false, 'warnings' => $warnings, 'error' => 'no_real_value'];
        }
        return ['success' => true, 'value' => $value, 'warnings' => $warnings, 'source' => $source];
    }

    private function fail(string $error): array
    {
        return ['success' => false, 'warnings' => [], 'error' => $error];
    }
}
