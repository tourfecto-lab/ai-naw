<?php

/**
 * Tourfecto - AutoFix PageContext
 * بيجيب الصفحة الرئيسية للعميل (بحماية SSRF) ويطلّع منها الحقائق اللي بنبني عليها القيم الحقيقية:
 * العنوان، H1، الوصف، اللغة، الصورة والشعار (بعد التأكد إنهم موجودين فعلاً)، روابط السوشيال، نص مختصر، ترويسات HTTP.
 *
 * @version 1.0.0
 */
class PageContext
{
    /** @return array<string,mixed> ['ok'=>bool, 'error'=>?string, ...] */
    public function fetch(string $url): array
    {
        $out = ['ok' => false, 'error' => null, 'url' => $url, 'final_url' => $url, 'https' => strpos($url, 'https://') === 0,
            'title' => '', 'h1' => '', 'description' => '', 'lang' => '', 'canonical' => '', 'og' => [], 'site_name' => '',
            'image' => '', 'logo' => '', 'social' => [], 'links' => [], 'text' => '', 'has_hsts' => false, 'has_viewport' => false, 'robots' => ''];

        if (!preg_match('#^https?://#i', $url) || !$this->isSafe($url)) {
            $out['error'] = 'unsafe_url';
            return $out;
        }
        $r = $this->get($url, true);
        if ($r['error'] === null && $r['http'] === 200 && self::isMaintenanceHtml((string)$r['body'])) {
            $fresh = $this->get(self::freshAuditUrl($url), true);
            if ($fresh['error'] === null && $fresh['http'] === 200 && $fresh['body'] !== '') $r = $fresh;
        }
        $out['http_status'] = (int) $r['http'];
        $out['retry_after'] = $r['headers']['retry-after'] ?? null;
        $out['error_detail'] = $r['error_detail'] ?? null;
        if ($r['error'] !== null || $r['http'] < 200 || $r['http'] >= 400 || $r['body'] === '') {
            $out['error'] = $r['error'] ?? ('http_' . $r['http']);
            return $out;
        }
        $out['final_url'] = $r['final_url'];
        $out['https'] = strpos($r['final_url'], 'https://') === 0;
        $out['has_hsts'] = isset($r['headers']['strict-transport-security']);

        $html = $r['body'];
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $out['title'] = $this->clean($xp->evaluate('string(//title)'));
        $out['h1'] = $this->clean($xp->evaluate('string((//h1)[1])'));
        // عناوين الأقسام والكروت (أسماء الرحلات غالبًا بتبقى h2/h3 في صفحات الأقسام)،
        // ومعاها السعر المكتوب في نفس الكارت لو موجود
        $out['headings'] = [];
        $out['card_prices'] = [];
        foreach ($xp->query('//h2|//h3') as $hn) {
            $hv = $this->clean($hn->textContent);
            if ($hv !== '' && mb_strlen($hv) <= 90) {
                $out['headings'][] = $hv;
                $card = $hn->parentNode;
                for ($up = 0; $up < 3 && $card && $card->parentNode; $up++) {
                    $cardText = $this->clean($card->textContent);
                    // وصلنا لحاوية فيها أكتر من كارت: السعر ممكن يبقى بتاع رحلة تانية
                    if (mb_strlen($cardText) > 600 || $xp->query('.//h2|.//h3', $card)->length > 1) { break; }
                    if (preg_match('/(?:US\$|\$|€|£|EGP|USD|EUR|GBP)\s?\d{1,5}(?:[.,]\d{1,2})?|\d{1,5}(?:[.,]\d{1,2})?\s?(?:USD|EUR|GBP|EGP|\$|€|£|جنيه)/u', $cardText, $pm)) {
                        $out['card_prices'][] = $hv . ' — ' . trim($pm[0]);
                        break;
                    }
                    $card = $card->parentNode;
                }
            }
            if (count($out['headings']) >= 30) { break; }
        }
        $htmlNode = $doc->getElementsByTagName('html')->item(0);
        $out['lang'] = $htmlNode ? strtolower(trim($htmlNode->getAttribute('lang'))) : '';
        foreach ($doc->getElementsByTagName('meta') as $m) {
            $name = strtolower($m->getAttribute('name'));
            $prop = strtolower($m->getAttribute('property'));
            $content = $this->clean($m->getAttribute('content'));
            if ($name === 'tourfecto-connector') { $out['connector_version']=$content; }
            if ($name === 'tourfecto-connector-features') { $out['connector_features']=$content; }
            if ($name === 'description') {
                $out['description'] = $content;
            } elseif ($name === 'viewport') {
                $out['has_viewport'] = true;
            } elseif ($name === 'robots') {
                $out['robots'] = strtolower($content);
            } elseif (strpos($prop, 'og:') === 0) {
                $out['og'][$prop] = $content;
            }
        }
        $canon = $xp->query('//link[@rel="canonical"]')->item(0);
        $out['canonical'] = $canon ? trim($canon->getAttribute('href')) : '';
        $out['site_name'] = $out['og']['og:site_name'] ?? '';
        $out['counts']=[];$out['values']=[];
        foreach(['h1'=>'//h1','title'=>'//title','description'=>'//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content','canonical'=>'//link[@rel="canonical"]/@href','robots'=>'//meta[@name="robots"]/@content','viewport'=>'//meta[@name="viewport"]/@content','lang'=>'//html/@lang'] as $field=>$query){
            $nodes=$xp->query($query);$out['counts'][$field]=$nodes->length;$out['values'][$field]=[];
            foreach($nodes as $node)$out['values'][$field][]=$this->clean($node->nodeValue);
        }
        $out['schema']=[];$out['invalid_schema']=0;
        foreach($xp->query('//script[@type="application/ld+json"]') as $node){$v=json_decode($node->textContent,true);if(is_array($v))$out['schema'][]=$v;else $out['invalid_schema']++;}
        $out['hsts']=(string)($r['headers']['strict-transport-security']??'');
        $out['seo_plugin']=preg_match('/yoast|rank-math|aioseo|seopress/i',$html)===1;
        $out['twitter']=[];foreach($doc->getElementsByTagName('meta') as $m){$name=$m->getAttribute('name');if(strpos($name,'twitter:')===0)$out['twitter'][$name]=$m->getAttribute('content');}


        // روابط "رحلات ممكن تعجبك" اللي ملف الربط حطها (للتأكد إنها ظهرت فعلًا)
        $out['tf_related']=[];foreach($xp->query('//*[@data-tf-related]//a[@href]') as $a){$out['tf_related'][]=$this->absolute($out['final_url'],$a->getAttribute('href'));}
        // روابط داخلية بعناوين (للـ llms.txt)
        $host = strtolower((string) parse_url($out['final_url'], PHP_URL_HOST));
        $links = [];
        foreach ($xp->query('//a[@href]') as $a) {
            $text = $this->clean($a->textContent);
            $abs = $this->absolute($out['final_url'], $a->getAttribute('href'));
            if ($text === '' || mb_strlen($text) > 60 || $abs === '' || strtolower((string) parse_url($abs, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $path = (string) parse_url($abs, PHP_URL_PATH);
            if ($path === '' || $path === '/' || preg_match('#\.(jpg|png|pdf|zip|css|js)$#i', $path) || strpos($abs, '#') !== false) {
                continue;
            }
            $links[strtolower($abs)] = ['title' => $text, 'url' => $abs];
            if (count($links) >= 8) {
                break;
            }
        }
        $out['links'] = array_values($links);

        // كل الروابط الداخلية في الصفحة (لحساب الصفحات اليتيمة والروابط المكسورة)
        $internal = [];
        foreach ($xp->query('//a[@href]') as $a) {
            $abs = $this->absolute($out['final_url'], $a->getAttribute('href'));
            if ($abs === '' || strtolower((string) parse_url($abs, PHP_URL_HOST)) !== $host || preg_match('#\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|css|js)$#i', (string) parse_url($abs, PHP_URL_PATH))) {
                continue;
            }
            $abs = preg_replace('/#.*$/', '', $abs);
            $internal[$abs] = true;
            if (count($internal) >= 300) {
                break;
            }
        }
        $out['internal_links'] = array_keys($internal);

        // صور من غير وصف (alt): جوجل وقارئات الشاشة مش بيفهموها
        $out['images'] = 0;
        $out['images_missing_alt'] = [];
        foreach ($xp->query('//img[@src]') as $img) {
            $src = $this->absolute($out['final_url'], $img->getAttribute('src'));
            if ($src === '' || stripos($src, 'data:') === 0) {
                continue;
            }
            $out['images']++;
            if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
                if (count($out['images_missing_alt']) < 30) {
                    $out['images_missing_alt'][] = $src;
                }
            }
        }

        // وسائل التواصل الحقيقية (تليفون / إيميل / واتساب) لملء بيانات النشاط تلقائيًا.
        // قبل حذف الـ footer/nav تحت، لأن بيانات التواصل غالبًا هناك.
        $contacts = ['phones' => [], 'emails' => [], 'whatsapp' => []];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if (stripos($href, 'tel:') === 0) {
                $v = preg_replace('/[^0-9+]/', '', substr($href, 4));
                if (strlen($v) >= 7) { $contacts['phones'][$v] = $v; }
            } elseif (stripos($href, 'mailto:') === 0) {
                $v = strtolower(trim(strtok(substr($href, 7), '?')));
                if (filter_var($v, FILTER_VALIDATE_EMAIL)) { $contacts['emails'][$v] = $v; }
            } elseif (preg_match('#^https://(?:wa\.me/|api\.whatsapp\.com/send\?phone=)\+?([0-9]{7,15})#i', $href, $wm)) {
                $contacts['whatsapp'][$wm[1]] = '+' . $wm[1];
            }
        }
        $out['contacts'] = array_map(static fn ($l) => array_slice(array_values($l), 0, 3), $contacts);

        // نص مختصر للفهم (من غير سكريبت وستايل)
        foreach (['script', 'style', 'noscript', 'nav', 'footer'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) {
                if ($n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        $text = $body ? $this->bodyText($body) : '';
        $out['body_text_length']=mb_strlen((string)$text);
        // إشارات إضافية من النص الكامل قبل ما يتقص (مراجعة صفحات الرحلات وhreflang في مركز السيو)
        $signals = $this->contentSignals((string) $text, $xp);
        if ($signals) {
            $out['signals'] = $signals;
        }
        $out['text'] = mb_substr((string) $text, 0, $this->textLimit());

        // روابط سوشيال حقيقية
        $social = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if (preg_match('#^https://(www\.)?(facebook\.com|instagram\.com|twitter\.com|x\.com|youtube\.com|linkedin\.com|tiktok\.com)/[A-Za-z0-9_.\-@/]{2,}$#i', $href)
                && !preg_match('#/(sharer|share|intent|tr)\b#i', $href)) {
                $social[strtolower($href)] = $href;
            }
        }
        $out['social'] = array_slice(array_values($social), 0, 6);

        // صورة المشاركة والشعار: بنتأكد إنها موجودة فعلاً (مش بنخمّن مسار)
        $candidates = [];
        if (!empty($out['og']['og:image'])) {
            $candidates['image'][] = $out['og']['og:image'];
        }
        foreach ($xp->query('//img[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo") or contains(translate(@src,"LOGO","logo"),"logo")]') as $img) {
            $candidates['logo'][] = $img->getAttribute('src');
        }
        foreach ($xp->query('//link[contains(@rel,"icon") and @href]') as $l) {
            $candidates['logo'][] = $l->getAttribute('href');
        }
        // صور المحتوى (مش لوجو ولا أيقونات) - لاختيار صورة حقيقية للمقالات
        $out['content_images'] = [];
        foreach ($xp->query('//img[@src or @data-src]') as $img) {
            $src = trim($img->getAttribute('src') ?: $img->getAttribute('data-src'));
            $abs = $this->absolute($out['final_url'], $src);
            $w = (int) $img->getAttribute('width');
            if ($abs === '' || strpos($abs, 'https://') !== 0 || preg_match('/logo|icon|sprite|avatar|flag|\.svg(\?|$)|data:/i', $abs . ' ' . $img->getAttribute('class')) || ($w > 0 && $w < 300)) {
                continue;
            }
            $out['content_images'][] = ['src' => $abs, 'alt' => $this->clean($img->getAttribute('alt'))];
            if (count($out['content_images']) >= 5) { break; }
        }
        // روابط اللوجو المرشحة (من غير تحقق إنها صورة) - لملء ملف الشركة، والعميل بيراجع
        $out['logo_candidates'] = [];
        foreach (array_unique($candidates['logo'] ?? []) as $c) {
            $abs = $this->absolute($out['final_url'], $c);
            if ($abs !== '' && strpos($abs, 'https://') === 0) {
                $out['logo_candidates'][] = $abs;
            }
        }
        foreach (['image', 'logo'] as $kind) {
            foreach (array_unique($candidates[$kind] ?? []) as $c) {
                $abs = $this->absolute($out['final_url'], $c);
                if ($abs !== '' && strpos($abs, 'https://') === 0 && $this->isImage($abs)) {
                    $out[$kind] = $abs;
                    break;
                }
            }
        }
        if ($out['image'] === '' && $out['logo'] !== '') {
            $out['image'] = $out['logo'];
        }

        $out['coming_soon'] = self::isMaintenanceHtml($html);
        $out['ok'] = true;
        return $out;
    }

    /** A cache-busting query is only used when the first response looks like maintenance. */
    public static function freshAuditUrl(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        return $url . (strpos($url, '?') === false ? '?' : '&') . '_tourfecto_audit=' . bin2hex(random_bytes(8));
    }

    /** Classify the returned HTML, not the mere presence of plugin names or a title substring. */
    public static function isMaintenanceHtml(string $html): bool
    {
        if ($html === '') return false;
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html)) return false;
            $xp = new DOMXPath($doc);
            $clean = static function (string $value): string {
                return trim((string)preg_replace('/\s+/u', ' ', $value));
            };
            $title = $clean($xp->evaluate('string(//title)'));
            $h1 = $clean($xp->evaluate('string((//h1)[1])'));
            foreach (['script','style','noscript','nav','footer'] as $tag) {
                foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                    if ($node->parentNode) $node->parentNode->removeChild($node);
                }
            }
            $body = $doc->getElementsByTagName('body')->item(0);
            $text = $clean($body ? $body->textContent : '');
            // Long substantive pages and articles mentioning a launch are not maintenance pages.
            if (mb_strlen($text) > 1500) return false;
            $status = '(?:coming\s+soon|under\s+construction|under\s+maintenance|maintenance\s+mode|something\s+is\s+happening|قريبًا|قريبا|الموقع\s+تحت\s+الصيانة|جاري\s+صيانة\s+الموقع)';
            $headline = preg_match('/^'.$status.'[.!…]?$/iu', $h1) === 1;
            $titleStatus = preg_match('/^'.$status.'(?:[.!…]|\s*[-|–—:]\s*.{0,120})?$/iu', $title) === 1;
            return $headline || ($titleStatus && mb_strlen($text) < 700);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    // ------------------------------------------------------------------ مساعدات (protected للاختبار)

    protected function textLimit(): int { return 700; }

    /** @return array إشارات إضافية من النص الكامل (فاضية هنا، مركز السيو بيضيفها) */
    protected function contentSignals(string $text, DOMXPath $xp): array { return []; }

    protected function bodyText(DOMNode $body): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($body->textContent));
    }

    protected function isSafe(string $url): bool
    {
        return class_exists('SsrfGuard') ? SsrfGuard::isSafe($url) : false;
    }

    protected function isImage(string $url): bool
    {
        if (!$this->isSafe($url)) {
            return false;
        }
        $r = $this->get($url, false, 'HEAD');
        return $r['error'] === null && $r['http'] === 200 && stripos($r['headers']['content-type'] ?? '', 'image/') === 0;
    }

    /** @return array{http:int, body:string, headers:array, error:?string, final_url:string} */
    protected function get(string $url, bool $withBody, string $method = 'GET'): array
    {
        $headers = [];
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER=>['Cache-Control: no-cache','Pragma: no-cache'], CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.pro)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, $withBody) {
                if (!$withBody) {
                    return strlen($chunk);
                }
                $body .= $chunk;
                return strlen($body) > 1500000 ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !$this->allowPrivate()) {
            return ['http' => $http, 'body' => '', 'headers' => [], 'error' => 'private_ip', 'final_url' => $final];
        }
        // headers لآخر redirect بس (curl بيكرّر الـ headers لكل hop)
        return ['http' => $http, 'body' => $body, 'headers' => $headers, 'error' => $errno && !($errno === 23 && $withBody) ? 'network' : null, 'final_url' => $final ?: $url];
    }

    protected function allowPrivate(): bool
    {
        return false;
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function absolute(string $base, string $href): string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^(javascript:|mailto:|tel:|data:)#i', $href)) {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (!$p || empty($p['host'])) {
            return '';
        }
        $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '');
        if (strpos($href, '//') === 0) {
            return ($p['scheme'] ?? 'https') . ':' . $href;
        }
        if ($href[0] === '/') {
            return $origin . $href;
        }
        $dir = isset($p['path']) ? rtrim(substr($p['path'], 0, strrpos($p['path'], '/') + 1), '/') : '';
        return $origin . $dir . '/' . $href;
    }

    public function fetchText(string $url): ?string
    {
        if(!$this->isSafe($url))return null;$r=$this->get($url,true);
        return $r['error']===null && $r['http']===200 && strlen($r['body'])<=20000 ? $r['body'] : null;
    }
    public static function benefit(string $field): string
    {
        return match($field){'link_fix'=>'Removes dead-end links for visitors and search engines.','title'=>'Helps visitors and search engines identify the page.','description'=>'Provides a useful summary that search engines may show in results.','canonical'=>'Identifies the preferred page URL.','robots'=>'Controls page indexing instructions.','viewport'=>'Supports correct layout on mobile devices.','lang'=>'Identifies the page language for accessibility and search.','og'=>'Controls the preview when the page is shared.','jsonld'=>'Describes the business using structured data.','hsts'=>'Requests HTTPS-only access for the configured duration.','llms_txt'=>'Provides a readable site summary; discovery or ranking benefits are not guaranteed.','related_links'=>'Links related trips together so visitors and Google find more of your pages.',default=>'Requires a separate implementation and verification method.'};
    }
    private static function normalizeValue(string $s): string
    {return trim(preg_replace('/\s+/u',' ',html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8')));}
    private static function schemaContains(array $candidate,array $expected): bool
    {
        foreach($expected as $k=>$v){if(!array_key_exists($k,$candidate))return false;if(is_array($v)){if(!is_array($candidate[$k])||!self::schemaContains($candidate[$k],$v))return false;}elseif($candidate[$k]!==$v)return false;}return true;
    }
    public static function verifyValue(string $field,string $expected,array $ctx): array
    {
        $out=['state'=>'pending','expected'=>$expected,'observed'=>null,'reason'=>'The approved value has not been detected yet.','checked_at'=>gmdate('c'),'benefit'=>self::benefit($field)];
        if(empty($ctx['ok'])){$out['state']='unreachable';$out['reason']='The public page could not be inspected.';return $out;}
        if(!empty($ctx['coming_soon'])){$out['state']='blocked';$out['reason']='The public page is a coming-soon or maintenance page.';return $out;}
        if($field==='jsonld'){
            $expectedData=json_decode($expected,true);$matches=0;
            foreach($ctx['schema']??[] as $schema){$nodes=array_merge([$schema],is_array($schema['@graph']??null)?$schema['@graph']:[],array_is_list($schema)?$schema:[]);foreach($nodes as $node)if(is_array($node)&&is_array($expectedData)&&self::schemaContains($node,$expectedData))$matches++;}
            $out['observed']=['matching_entities'=>$matches,'invalid_blocks'=>$ctx['invalid_schema']??0];
            if(($ctx['invalid_schema']??0)>0||$matches>1){$out['state']='conflict';$out['reason']='Invalid structured data or duplicate matching entities detected.';return $out;}
            $same=$matches===1;
        }elseif($field==='og'){
            $wanted=json_decode($expected,true);$actual=array_merge($ctx['og']??[],$ctx['twitter']??[]);$out['observed']=$actual;$same=is_array($wanted)&&count($wanted)>0;foreach($wanted??[] as $k=>$v)if(($actual[$k]??null)!==$v)$same=false;
        }elseif($field==='related_links'){
            $want=array_column(json_decode($expected,true)?:[],'url');$found=$ctx['tf_related']??[];$out['observed']=$found;
            $same=$want!==[]&&!array_diff($want,$found);
        }elseif($field==='link_fix'){
            // اتصلح لما الرابط المكسور يختفي من الصفحة، والبديل (لو فيه) يظهر مكانه
            $norm=static fn($u)=>preg_replace('/^www\./','',strtolower((string)parse_url((string)$u,PHP_URL_HOST))).'|'.rtrim((string)parse_url((string)$u,PHP_URL_PATH),'/').(($q=parse_url((string)$u,PHP_URL_QUERY))?'?'.$q:'');
            $links=array_map($norm,$ctx['internal_links']??[]);$fixes=json_decode($expected,true)?:[];$left=[];
            foreach($fixes as $f){if(in_array($norm($f['from']??''),$links,true)||(($f['to']??'')!==''&&!in_array($norm($f['to']),$links,true)))$left[]=$f['from']??'';}
            $out['observed']=['still_broken_or_missing'=>$left];$same=$fixes!==[]&&!$left;
        }elseif($field==='hsts'){$out['observed']=$ctx['hsts']??'';$same=$out['observed']===$expected;
        }elseif($field==='llms_txt'){$out['observed']=$ctx['llms_body']??null;$same=is_string($out['observed']) && self::normalizeValue($out['observed'])===self::normalizeValue($expected);
        }else{
            $values=$ctx['values'][$field]??[];$out['observed']=$values;
            if(count($values)>1){$out['state']='conflict';$out['reason']='More than one '.$field.' value is present on the public page.';return $out;}
            $actual=$values[0]??'';$same=self::normalizeValue($actual)===self::normalizeValue($expected);
            if($field==='robots')$same=strtolower(str_replace(' ','',$actual))===strtolower(str_replace(' ','',$expected));
            if($field==='lang')$same=strtolower($actual)===strtolower($expected);
        }
        if($same){$out['state']='verified';$out['reason']='The approved value was found on the public page.';}
        elseif(!empty($ctx['seo_plugin'])&&in_array($field,['title','description','canonical','og'],true)){$out['state']='conflict';$out['reason']='The approved value is missing and another SEO system was detected.';}
        return $out;
    }

}
