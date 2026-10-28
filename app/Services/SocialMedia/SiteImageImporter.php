<?php

/**
 * Tourfecto - سحب صور العميل من موقعه
 *
 * بيفتح الصفحة الرئيسية + صفحات الرحلات/الفنادق/المعرض، ويجمع الصور الكبيرة
 * (من غير اللوجو والأيقونات والأعلام)، وينزّلها في مكتبة العميل كصور جاهزة
 * للنشر. الصورة اللي اتسحبت قبل كده مش بتتكرر.
 */
class SiteImageImporter
{
    private const UA = 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.pro)';
    private const PAGE_HINTS = '/tour|trip|package|excursion|safari|cruise|hotel|gallery|galler|destination|activit|program|offer|رحل|برنامج|عرض|فندق|معرض|جولات|سفاري/iu';
    private const SKIP_IMG = '/logo|icon|favicon|sprite|avatar|flag|badge|payment|visa|master|paypal|whatsapp|facebook|instagram|twitter|tiktok|youtube|tripadvisor|google|placeholder|loading|spinner|blank|pixel|arrow|star|rating|emoji|banner-ad|\.svg|\.gif/i';
    private const MIN_W = 500;
    private const MIN_H = 350;

    private PDO $pdo;
    private string $publicRoot;
    /** @var callable(string,int):?array{body:string,url:string} */
    private $fetch;
    public int $budgetSeconds = 38;

    public function __construct(?PDO $pdo = null, ?string $publicRoot = null, ?callable $fetch = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->publicRoot = rtrim($publicRoot ?? ROOT_PATH . '/public_html', '/');
        $this->fetch = $fetch ?? [self::class, 'httpFetch'];
    }

    /** @return array{added:int[], found:int, message:string} */
    public function import(int $uid, int $wid, int $max = 20): array
    {
        $start = microtime(true);
        $s = $this->pdo->prepare('SELECT `' . WebsiteSchema::url() . '` AS main_url, ' . WebsiteSchema::col('domain', 'domain') . ' FROM websites WHERE id=? AND user_id=?');
        $s->execute([$wid, $uid]);
        $site = $s->fetch(PDO::FETCH_ASSOC);
        if (!$site) {
            throw new InvalidArgumentException('Website unavailable');
        }
        $home = trim((string) (($site['main_url'] ?? '') ?: 'https://' . ($site['domain'] ?? '')));
        if (!preg_match('#^https?://#i', $home)) {
            $home = 'https://' . $home;
        }
        if ((string) parse_url($home, PHP_URL_PATH) === '') {
            $home .= '/';
        }
        $page = ($this->fetch)($home, 2000000);
        if (!$page) {
            return ['added' => [], 'found' => 0, 'message' => 'مقدرناش نفتح موقعك دلوقتي. اتأكد إن الموقع شغال وجرّب تاني.'];
        }
        $host = strtolower(preg_replace('/^www\./i', '', (string) parse_url($page['url'], PHP_URL_HOST)));

        // الصفحات: الرئيسية + لحد 6 صفحات رحلات/فنادق/معرض من نفس الموقع
        $pages = [$page];
        foreach (array_slice($this->tourLinks($page['body'], $page['url'], $host), 0, 6) as $link) {
            if (microtime(true) - $start > $this->budgetSeconds * 0.4) {
                break;
            }
            $p = ($this->fetch)($link, 2000000);
            if ($p) {
                $pages[] = $p;
            }
        }
        $candidates = [];
        foreach ($pages as $i => $p) {
            foreach ($this->images($p['body'], $p['url']) as $url => $alt) {
                // صور صفحات الرحلات أهم من صور الرئيسية
                $candidates[$url] ??= ['alt' => $alt, 'page' => $p['url'], 'rank' => $i === 0 ? 1 : 0];
            }
        }
        uasort($candidates, static fn ($a, $b) => $a['rank'] <=> $b['rank']);

        $dir = $this->publicRoot . '/uploads/social/' . $uid;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Upload folder not writable');
        }
        $added = [];
        $seen = [];
        foreach ($candidates as $url => $c) {
            if (count($added) >= $max || microtime(true) - $start > $this->budgetSeconds) {
                break;
            }
            if ($this->alreadyImported($uid, $url)) {
                continue;
            }
            $img = ($this->fetch)($url, 8000000);
            if (!$img) {
                continue;
            }
            $hash = md5($img['body']);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $jpeg = self::prepare($img['body']);
            if ($jpeg === null) {
                continue;
            }
            // نفس الصورة بمقاس أو رابط تاني = مكررة
            $phash = self::phash($jpeg[0]);
            if ($phash !== null && self::duplicateOf($this->pdo, $uid, $phash) !== null) {
                continue;
            }
            $path = '/uploads/social/' . $uid . '/site_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.jpg';
            file_put_contents($this->publicRoot . $path, $jpeg[0]);
            $label = $c['alt'] !== '' ? $c['alt'] : urldecode(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
            $meta = ['source' => 'site_import', 'src' => $url, 'page' => $c['page'], 'phash' => $phash];
            $this->pdo->prepare("INSERT INTO media_items(user_id,type,prompt,file_path,thumbnail_path,width,height,status,generation_meta) VALUES(?,'social_image',?,?,?,?,?,'completed',?)")
                ->execute([$uid, mb_substr('من موقعك: ' . $label, 0, 250), $path, $path, $jpeg[1], $jpeg[2], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $added[] = (int) $this->pdo->lastInsertId();
        }
        $msg = $added
            ? 'سحبنا ' . count($added) . ' صورة من موقعك.'
            : ($candidates ? 'كل صور موقعك المناسبة موجودة في مكتبتك بالفعل، أو صغيرة أوي للنشر.' : 'مالقيناش صور كبيرة كفاية على موقعك. ارفع صورك من جهازك.');
        return ['added' => $added, 'found' => count($candidates), 'message' => $msg];
    }

    /**
     * يدوّر على صور الموقع ويرجّعها متقسمة حسب الصفحة (من غير تنزيل) عشان العميل يختار.
     * @param string[] $urls روابط رحلات معينة (اختياري) - لو موجودة بنفتحها هي بس
     * @return array{pages:array, total:int, scanned:int}
     */
    public function scan(int $uid, int $wid, array $urls = [], int $maxPages = 30): array
    {
        $start = microtime(true);
        $queue = [];
        $host = '';
        foreach (array_slice($urls, 0, 15) as $u) {
            $u = trim((string) $u);
            if (preg_match('#^https?://[^\s]+$#i', $u)) {
                $queue[] = $u;
            }
        }
        $explicit = (bool) $queue;
        if (!$explicit) {
            $s = $this->pdo->prepare('SELECT `' . WebsiteSchema::url() . '` AS main_url, ' . WebsiteSchema::col('domain', 'domain') . ' FROM websites WHERE id=? AND user_id=?');
            $s->execute([$wid, $uid]);
            $site = $s->fetch(PDO::FETCH_ASSOC);
            if (!$site) {
                throw new InvalidArgumentException('Website unavailable');
            }
            $home = trim((string) (($site['main_url'] ?? '') ?: 'https://' . ($site['domain'] ?? '')));
            $home = preg_match('#^https?://#i', $home) ? $home : 'https://' . $home;
            if ((string) parse_url($home, PHP_URL_PATH) === '') {
                $home .= '/';
            }
            $queue[] = $home;
        }
        $pages = [];
        $visited = [];
        $owner = [];
        while ($queue && count($visited) < $maxPages && microtime(true) - $start < $this->budgetSeconds) {
            $url = array_shift($queue);
            $key = rtrim(preg_replace('/#.*$/', '', $url), '/');
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $page = ($this->fetch)($url, 2000000);
            if (!$page) {
                continue;
            }
            $finalHost = strtolower(preg_replace('/^www\./i', '', (string) parse_url($page['url'], PHP_URL_HOST)));
            if ($host === '') {
                $host = $finalHost;
                if (!$explicit) {
                    // صفحات الرحلات من الـ sitemap (المواقع الكبيرة فيها رحلات كتير مش كلها في الرئيسية)
                    foreach ($this->sitemapLinks($page['url'], $host) as $link) {
                        $queue[] = $link;
                    }
                }
            }
            if (!$explicit) {
                foreach ($this->tourLinks($page['body'], $page['url'], $host) as $link) {
                    $queue[] = $link;
                }
            }
            $images = [];
            foreach ($this->images($page['body'], $page['url']) as $src => $alt) {
                // الصورة بتظهر مع أول صفحة رحلة فيها، مش مع الرئيسية
                if (isset($owner[$src])) {
                    if ($owner[$src]['home'] && count($pages) > 0) {
                        $pages[$owner[$src]['page']]['images'] = array_values(array_filter($pages[$owner[$src]['page']]['images'], static fn ($i) => $i['src'] !== $src));
                    } else {
                        continue;
                    }
                }
                $owner[$src] = ['page' => count($pages), 'home' => count($pages) === 0 && !$explicit];
                $images[] = ['src' => $src, 'alt' => $alt, 'already' => $this->alreadyImported($uid, $src)];
            }
            $pages[] = ['url' => $page['url'], 'title' => self::pageTitle($page['body']), 'images' => $images];
        }
        $pages = array_values(array_filter($pages, static fn ($p) => $p['images']));
        $total = array_sum(array_map(static fn ($p) => count($p['images']), $pages));
        return ['pages' => $pages, 'total' => $total, 'scanned' => count($visited)];
    }

    /** روابط الرحلات من sitemap.xml (ووردبريس وغيره) */
    private function sitemapLinks(string $base, string $host): array
    {
        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        $out = [];
        $maps = [$origin . '/sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/wp-sitemap.xml'];
        $seen = [];
        for ($i = 0; $i < count($maps) && $i < 8; $i++) {
            if (isset($seen[$maps[$i]])) {
                continue;
            }
            $seen[$maps[$i]] = true;
            $r = ($this->fetch)($maps[$i], 3000000);
            if (!$r || !preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r['body'], $m)) {
                continue;
            }
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc);
                $h = strtolower(preg_replace('/^www\./i', '', (string) parse_url($loc, PHP_URL_HOST)));
                if ($h !== $host) {
                    continue;
                }
                if (preg_match('/\.xml(\?|$)/i', $loc)) {
                    // sitemap فرعي: بنفتح بتاع الرحلات/المنتجات/الصفحات بس
                    if (preg_match('/tour|trip|product|package|excursion|page|post-type|rehla|رحل/i', $loc)) {
                        $maps[] = $loc;
                    }
                } elseif (preg_match(self::PAGE_HINTS, urldecode($loc))) {
                    $out[$loc] = true;
                }
            }
            if (count($out) >= 60) {
                break;
            }
        }
        return array_slice(array_keys($out), 0, 60);
    }

    public static function pageTitle(string $html): string
    {
        foreach (['/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', '/<h1[^>]*>(.*?)<\/h1>/is', '/<title[^>]*>(.*?)<\/title>/is'] as $re) {
            if (preg_match($re, $html, $m)) {
                $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')));
                $t = trim(preg_replace('/\s*[|\-–—]\s*[^|\-–—]{2,40}$/u', '', $t)) ?: $t; // من غير "| اسم الموقع"
                if ($t !== '') {
                    return mb_substr($t, 0, 120);
                }
            }
        }
        return '';
    }

    /**
     * يسحب الصور اللي العميل اختارها.
     * @param array<int,array{src:string,alt?:string,page?:string,title?:string}> $items
     * @return array{added:int[], duplicate:int, rejected:int, remaining:array}
     */
    public function importItems(int $uid, array $items, bool $folderPerTrip = false): array
    {
        $start = microtime(true);
        $dir = $this->publicRoot . '/uploads/social/' . $uid;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Upload folder not writable');
        }
        $added = [];
        $dup = 0;
        $rejected = 0;
        $folders = [];
        $items = array_values($items);
        foreach ($items as $n => $it) {
            if (microtime(true) - $start > $this->budgetSeconds) {
                return ['added' => $added, 'duplicate' => $dup, 'rejected' => $rejected, 'remaining' => array_slice($items, $n)];
            }
            $src = trim((string) ($it['src'] ?? ''));
            if (!preg_match('#^https?://\S+$#i', $src)) {
                $rejected++;
                continue;
            }
            if ($this->alreadyImported($uid, $src)) {
                $dup++;
                continue;
            }
            $img = ($this->fetch)($src, 8000000);
            $jpeg = $img ? self::prepare($img['body']) : null;
            if ($jpeg === null) {
                $rejected++;
                continue;
            }
            $phash = self::phash($jpeg[0]);
            if ($phash !== null && self::duplicateOf($this->pdo, $uid, $phash) !== null) {
                $dup++;
                continue;
            }
            $title = mb_substr(trim((string) ($it['title'] ?? '')), 0, 120);
            $alt = mb_substr(trim((string) ($it['alt'] ?? '')), 0, 120);
            $label = trim($alt . ($title !== '' && $title !== $alt ? ' — ' . $title : ''), ' —') ?: urldecode(pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME));
            $folderId = null;
            if ($folderPerTrip && $title !== '') {
                $folderId = $folders[$title] ??= $this->folderFor($uid, $title);
            }
            $path = '/uploads/social/' . $uid . '/site_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.jpg';
            file_put_contents($this->publicRoot . $path, $jpeg[0]);
            $meta = ['source' => 'site_import', 'src' => $src, 'page' => (string) ($it['page'] ?? ''), 'page_title' => $title, 'phash' => $phash];
            $this->pdo->prepare("INSERT INTO media_items(user_id,type,prompt,file_path,thumbnail_path,width,height,status,generation_meta" . ($folderId ? ',folder_id' : '') . ") VALUES(?,'social_image',?,?,?,?,?,'completed',?" . ($folderId ? ',?' : '') . ")")
                ->execute(array_merge([$uid, mb_substr('من موقعك: ' . $label, 0, 250), $path, $path, $jpeg[1], $jpeg[2], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $folderId ? [$folderId] : []));
            $added[] = (int) $this->pdo->lastInsertId();
        }
        return ['added' => $added, 'duplicate' => $dup, 'rejected' => $rejected, 'remaining' => []];
    }

    /** مجلد باسم الرحلة في مكتبة الاستوديو (لو المجلدات متاحة ولسه في مكان) */
    private function folderFor(int $uid, string $title): ?int
    {
        try {
            $name = mb_substr($title, 0, 100);
            $s = $this->pdo->prepare('SELECT id FROM creative_folders WHERE user_id=? AND name=? LIMIT 1');
            $s->execute([$uid, $name]);
            $id = $s->fetchColumn();
            if ($id) {
                return (int) $id;
            }
            $c = $this->pdo->prepare('SELECT COUNT(*) FROM creative_folders WHERE user_id=?');
            $c->execute([$uid]);
            if ((int) $c->fetchColumn() >= 30) {
                return null;
            }
            $this->pdo->prepare('INSERT INTO creative_folders(user_id,name,created_at,updated_at) VALUES(?,?,NOW(),NOW())')->execute([$uid, $name]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** بصمة الصورة (8x8): بتفضل زي ما هي لو الصورة اتصغرت أو اتضغطت */
    public static function phash(string $binary): ?string
    {
        $im = @imagecreatefromstring($binary);
        if (!$im) {
            return null;
        }
        $s = imagecreatetruecolor(8, 8);
        imagecopyresampled($s, $im, 0, 0, 0, 0, 8, 8, imagesx($im), imagesy($im));
        imagedestroy($im);
        $g = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $c = imagecolorat($s, $x, $y);
                $g[] = (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
            }
        }
        imagedestroy($s);
        $avg = array_sum($g) / 64;
        $bits = '';
        foreach ($g as $v) {
            $bits .= $v >= $avg ? '1' : '0';
        }
        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT) . str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    /** رقم صورة موجودة في مكتبة العميل شبه الصورة دي، أو null */
    public static function duplicateOf(PDO $pdo, int $uid, string $phash): ?int
    {
        $s = $pdo->prepare("SELECT id,generation_meta FROM media_items WHERE user_id=? AND deleted_at IS NULL AND generation_meta LIKE '%\"phash\"%' ORDER BY id DESC LIMIT 3000");
        $s->execute([$uid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $h = json_decode((string) $r['generation_meta'], true)['phash'] ?? null;
            if (is_string($h) && strlen($h) === 16) {
                $d = substr_count(str_pad(decbin(hexdec(substr($h, 0, 8)) ^ hexdec(substr($phash, 0, 8))), 32, '0', STR_PAD_LEFT), '1')
                    + substr_count(str_pad(decbin(hexdec(substr($h, 8)) ^ hexdec(substr($phash, 8))), 32, '0', STR_PAD_LEFT), '1');
                if ($d <= 4) {
                    return (int) $r['id'];
                }
            }
        }
        return null;
    }

    private function alreadyImported(int $uid, string $url): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM media_items WHERE user_id=? AND deleted_at IS NULL AND generation_meta LIKE ? LIMIT 1");
        $s->execute([$uid, '%' . str_replace(['%', '_'], ['\%', '\_'], json_encode($url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '%']);
        return (bool) $s->fetchColumn();
    }

    /** روابط صفحات الرحلات من نفس الموقع */
    public function tourLinks(string $html, string $base, string $host): array
    {
        $out = [];
        if (!preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $a) {
            $url = self::absUrl($base, html_entity_decode($a[1]));
            if (!$url || preg_match('/\.(jpe?g|png|webp|gif|pdf|zip)(\?|$)/i', $url)) {
                continue;
            }
            $h = strtolower(preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)));
            if ($h !== $host) {
                continue;
            }
            $text = $url . ' ' . strip_tags($a[2]);
            if (preg_match(self::PAGE_HINTS, $text) && rtrim($url, '/') !== rtrim($base, '/')) {
                $out[rtrim($url, '/')] = true;
            }
        }
        return array_keys($out);
    }

    /** @return array<string,string> رابط الصورة => وصفها */
    public function images(string $html, string $base): array
    {
        $out = [];
        $add = static function (?string $url, string $alt) use (&$out) {
            if ($url && !preg_match(self::SKIP_IMG, $url) && !preg_match('/logo|icon/i', $alt) && !isset($out[$url])) {
                $out[$url] = mb_substr(trim(preg_replace('/\s+/u', ' ', $alt)), 0, 120);
            }
        };
        if (preg_match_all('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]*>/i', $html, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('/content=["\']([^"\']+)["\']/i', $tag, $c)) {
                    $add(self::absUrl($base, html_entity_decode($c[1])), '');
                }
            }
        }
        if (preg_match_all('/<img\s[^>]*>/i', $html, $m)) {
            foreach ($m[0] as $tag) {
                $alt = preg_match('/\balt=["\']([^"\']*)["\']/i', $tag, $a) ? html_entity_decode($a[1]) : '';
                $src = null;
                // أكبر نسخة في srcset، وبعدين صور التحميل المتأخر، وبعدين src
                foreach (['data-srcset', 'srcset'] as $attr) {
                    if (!$src && preg_match('/\b' . $attr . '=["\']([^"\']+)["\']/i', $tag, $s)) {
                        $best = 0;
                        foreach (explode(',', html_entity_decode($s[1])) as $part) {
                            $bits = preg_split('/\s+/', trim($part));
                            $w = isset($bits[1]) ? (int) $bits[1] : 1;
                            if ($bits[0] !== '' && $w >= $best) {
                                $best = $w;
                                $src = $bits[0];
                            }
                        }
                    }
                }
                foreach (['data-lazy-src', 'data-src', 'data-original', 'src'] as $attr) {
                    if (!$src && preg_match('/\b' . $attr . '=["\']([^"\']+)["\']/i', $tag, $s) && stripos($s[1], 'data:') !== 0) {
                        $src = html_entity_decode($s[1]);
                    }
                }
                if (preg_match('/\b(?:width|height)=["\']?(\d+)/i', $tag, $d) && (int) $d[1] > 0 && (int) $d[1] < 120) {
                    continue; // أيقونة صغيرة
                }
                $add($src ? self::absUrl($base, $src) : null, $alt);
            }
        }
        if (preg_match_all('/background(?:-image)?\s*:\s*url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $html, $m)) {
            foreach ($m[1] as $u) {
                $add(self::absUrl($base, html_entity_decode($u)), '');
            }
        }
        return $out;
    }

    /**
     * صورة كبيرة كفاية => JPG بمقاس مناسب للنشر (قص بسيط من النص لو النسبة برّه حدود إنستجرام)
     * @return array{0:string,1:int,2:int}|null
     */
    public static function prepare(string $binary): ?array
    {
        $im = @imagecreatefromstring($binary);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < self::MIN_W || $h < self::MIN_H && $w < $h * 2) {
            imagedestroy($im);
            return null;
        }
        $ratio = $w / $h;
        $cw = $w;
        $ch = $h;
        if ($ratio > 1.91) {
            $cw = (int) round($h * 1.91);
        } elseif ($ratio < 0.8) {
            $ch = (int) round($w / 0.8);
        }
        if ($ch < self::MIN_H * 0.8) {
            imagedestroy($im);
            return null; // شريط رفيع (بانر) مش صورة
        }
        $scale = min(1, 1440 / max($cw, $ch));
        $nw = (int) round($cw * $scale);
        $nh = (int) round($ch * $scale);
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), $nw, $nh, $cw, $ch);
        imagedestroy($im);
        // صورة لون واحد تقريبًا (خلفية) مش مفيدة
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $out, 0, 0, 0, 0, 8, 8, $nw, $nh);
        $vals = [];
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                $c = imagecolorat($small, $x, $y);
                $vals[] = (($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255);
            }
        }
        imagedestroy($small);
        if (max($vals) - min($vals) < 60) {
            imagedestroy($out);
            return null;
        }
        ob_start();
        imagejpeg($out, null, 88);
        imagedestroy($out);
        return [(string) ob_get_clean(), $nw, $nh];
    }

    public static function absUrl(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^(data|javascript|mailto|tel):#i', $href)) {
            return null;
        }
        if (strpos($href, '//') === 0) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $href;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (empty($p['host'])) {
            return null;
        }
        $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($href[0] === '/') {
            return $origin . $href;
        }
        $path = $p['path'] ?? '/';
        $dir = substr($path, -1) === '/' ? rtrim($path, '/') : rtrim(dirname($path), '/');
        return $origin . $dir . '/' . $href;
    }

    /** @return array{body:string,url:string}|null */
    public static function httpFetch(string $url, int $maxBytes): ?array
    {
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Accept-Language: ar,en;q=0.8'],
        ], 5);
        if (!is_string($r['response']) || $r['code'] < 200 || $r['code'] >= 300 || $r['response'] === '' || strlen($r['response']) > $maxBytes) {
            return null;
        }
        return ['body' => $r['response'], 'url' => (string) $r['url']];
    }
}
