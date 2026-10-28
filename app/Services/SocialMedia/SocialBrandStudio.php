<?php
require_once __DIR__ . '/SocialPostDesigner.php';

/**
 * Tourfecto - هوية الشركة + الذكاء الصناعي بيتعلم من صور العميل
 *
 * - هوية الشركة (لوجو + لونين) لكل موقع: بتتحفظ في brand_profiles (نفس هوية استوديو التصميم)
 * - "تعلّم الصورة": الذكاء الصناعي بيشوف كل صورة العميل رفعها ويكتب هي إيه (المكان، النوع، كلمات)
 *   وده بيتحفظ مع الصورة، والخطة الشهرية بتختار الصورة المناسبة لكل بوست بناءً عليه
 * - تصميم بوست: صورة حقيقية + هوية الشركة + عنوان => صورة جديدة في مكتبة العميل
 */
class SocialBrandStudio
{
    public const DEFAULT_PRIMARY = '#0E4D64';
    public const DEFAULT_ACCENT = '#F2B134';
    private const LOGO_MAX_BYTES = 5 * 1024 * 1024;

    private PDO $pdo;
    private string $publicRoot;

    public function __construct(?PDO $pdo = null, ?string $publicRoot = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->publicRoot = rtrim($publicRoot ?? ROOT_PATH . '/public_html', '/');
    }

    private function q(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }

    private function hasBrandTable(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (bool) $this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='brand_profiles'")->fetchColumn();
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    private function site(int $uid, int $wid): array
    {
        $s = $this->q('SELECT id,domain,company_name,main_url FROM websites WHERE id=? AND user_id=?', [$wid, $uid])->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            throw new InvalidArgumentException('Website unavailable');
        }
        $s['domain'] = $s['domain'] ?: preg_replace('#^https?://(www\.)?#i', '', rtrim((string) $s['main_url'], '/'));
        return $s;
    }

    // ------------------------------------------------------------ هوية الشركة

    /** @return array{id:?int, brand_name:string, domain:string, logo_url:?string, primary:string, accent:string, has_logo:bool} */
    public function kit(int $uid, int $wid): array
    {
        $site = $this->site($uid, $wid);
        $row = null;
        if ($this->hasBrandTable()) {
            $row = $this->q('SELECT id,brand_name,logo_path,palette_json FROM brand_profiles WHERE user_id=? AND website_id=? ORDER BY updated_at DESC,id DESC LIMIT 1', [$uid, $wid])->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $file = $this->kitFile($uid, $wid);
            $row = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        }
        $palette = is_array($row) ? (json_decode((string) ($row['palette_json'] ?? ''), true) ?: []) : [];
        $logo = is_array($row) ? (string) ($row['logo_path'] ?? '') : '';
        $logoOk = $logo !== '' && preg_match('#^/uploads/branding/[A-Za-z0-9_.-]+\.(png|jpe?g|webp)$#D', $logo) && is_file($this->publicRoot . $logo);
        return [
            'id' => is_array($row) && isset($row['id']) ? (int) $row['id'] : null,
            'brand_name' => (string) (($row['brand_name'] ?? '') ?: ($site['company_name'] ?: $site['domain'])),
            'domain' => (string) $site['domain'],
            'logo_url' => $logoOk ? $logo : null,
            'has_logo' => (bool) $logoOk,
            'primary' => self::hex($palette['primary'] ?? '') ?: self::DEFAULT_PRIMARY,
            'accent' => self::hex($palette['accent'] ?? '') ?: self::DEFAULT_ACCENT,
        ];
    }

    /** يحفظ اللوجو و/أو الألوان. لو اترفع لوجو والعميل ماحددش ألوان: بناخدها من اللوجو */
    public function saveKit(int $uid, int $wid, ?array $logoFile, string $primary = '', string $accent = ''): array
    {
        $site = $this->site($uid, $wid);
        $current = $this->kit($uid, $wid);
        $logoPath = $current['logo_url'];
        $palette = ['primary' => $current['primary'], 'accent' => $current['accent']];
        if ($logoFile && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$logoPath, $colors] = $this->storeLogo($uid, $logoFile);
            if ($colors) {
                $palette['primary'] = $colors[0];
                $palette['accent'] = $colors[1] ?? self::DEFAULT_ACCENT;
            }
        }
        if (($p = self::hex($primary)) !== '') {
            $palette['primary'] = $p;
        }
        if (($a = self::hex($accent)) !== '') {
            $palette['accent'] = $a;
        }
        $paletteJson = json_encode($palette);
        if ($this->hasBrandTable()) {
            if ($current['id']) {
                $this->q('UPDATE brand_profiles SET logo_path=?,palette_json=?,updated_at=NOW() WHERE id=? AND user_id=?', [$logoPath, $paletteJson, $current['id'], $uid]);
            } else {
                $url = (string) ($site['main_url'] ?: 'https://' . $site['domain']);
                $this->q("INSERT INTO brand_profiles(user_id,website_id,source_url,brand_name,logo_path,palette_json,status) VALUES(?,?,?,?,?,?,'ready') ON DUPLICATE KEY UPDATE website_id=VALUES(website_id),logo_path=VALUES(logo_path),palette_json=VALUES(palette_json),updated_at=NOW()", [$uid, $wid, $url, $current['brand_name'], $logoPath, $paletteJson]);
            }
        } else {
            $file = $this->kitFile($uid, $wid);
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, json_encode(['brand_name' => $current['brand_name'], 'logo_path' => $logoPath, 'palette_json' => $paletteJson]));
        }
        return $this->kit($uid, $wid);
    }

    private function kitFile(int $uid, int $wid): string
    {
        return ROOT_PATH . '/storage/cache/brandkit/' . $uid . '_' . $wid . '.json';
    }

    /** @return array{0:string,1:string[]} [مسار اللوجو، ألوانه] */
    private function storeLogo(int $uid, array $file): array
    {
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('اللوجو ما اترفعش كامل، جرّب تاني');
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('اللوجو ما اترفعش كامل، جرّب تاني');
        }
        if (filesize($file['tmp_name']) > self::LOGO_MAX_BYTES) {
            throw new InvalidArgumentException('اللوجو أكبر من 5 ميجا');
        }
        $im = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$im) {
            throw new InvalidArgumentException('اللوجو لازم يكون صورة PNG أو JPG');
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $scale = min(1, 800 / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $dir = $this->publicRoot . '/uploads/branding';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Branding folder not writable');
        }
        $name = 'kit_' . $uid . '_' . bin2hex(random_bytes(5)) . '.png';
        imagepng($out, $dir . '/' . $name, 6);
        $colors = self::dominantColors($out);
        imagedestroy($out);
        return ['/uploads/branding/' . $name, $colors];
    }

    /** أكتر لونين واضحين في اللوجو (من غير الأبيض/الأسود/الرمادي) */
    public static function dominantColors($im): array
    {
        $n = 32;
        $small = imagecreatetruecolor($n, $n);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $im, 0, 0, 0, 0, $n, $n, imagesx($im), imagesy($im));
        $bucket = [];
        for ($x = 0; $x < $n; $x++) {
            for ($y = 0; $y < $n; $y++) {
                $c = imagecolorat($small, $x, $y);
                if ((($c >> 24) & 0x7F) > 60) {
                    continue;
                }
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                if (max($r, $g, $b) - min($r, $g, $b) < 40 || max($r, $g, $b) < 35) {
                    continue; // رمادي/أبيض/أسود
                }
                $key = sprintf('#%02X%02X%02X', ($r >> 5 << 5) + 16, ($g >> 5 << 5) + 16, ($b >> 5 << 5) + 16);
                $bucket[$key] = ($bucket[$key] ?? 0) + 1;
            }
        }
        imagedestroy($small);
        arsort($bucket);
        $out = [];
        foreach (array_keys($bucket) as $hex) {
            $far = true;
            foreach ($out as $o) {
                $a = sscanf($hex, '#%02x%02x%02x');
                $b = sscanf($o, '#%02x%02x%02x');
                if (abs($a[0] - $b[0]) + abs($a[1] - $b[1]) + abs($a[2] - $b[2]) < 120) {
                    $far = false;
                }
            }
            if ($far) {
                $out[] = $hex;
            }
            if (count($out) === 2) {
                break;
            }
        }
        // اللون الأساسي لازم يبقى غامق كفاية عشان الكلام الأبيض يبان عليه
        if ($out) {
            $p = sscanf($out[0], '#%02x%02x%02x');
            if (0.2126 * $p[0] + 0.7152 * $p[1] + 0.0722 * $p[2] > 150 && isset($out[1])) {
                $out = [$out[1], $out[0]];
            }
        }
        return $out;
    }

    public static function hex($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $v) ? strtoupper($v) : '';
    }

    // ------------------------------------------------------------ تعلّم الصور

    /** المسار الحقيقي لصورة من مكتبة العميل (أو null لو مش صورة) */
    public function imageFile(int $uid, array $row): ?string
    {
        $path = (string) ($row['file_path'] ?? '');
        if (($row['type'] ?? '') === 'short_video' || preg_match('/\.(mp4|mov)$/i', $path)) {
            return null;
        }
        try {
            if (PrivateMedia::valid($path)) {
                return PrivateMedia::resolve($path);
            }
        } catch (Throwable $e) {
            return null;
        }
        if (preg_match('#^/uploads/social/' . $uid . '/[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp)$#D', $path) && is_file($this->publicRoot . $path)) {
            return $this->publicRoot . $path;
        }
        return null;
    }

    public static function meta(array $row): array
    {
        $m = json_decode((string) ($row['generation_meta'] ?? ''), true);
        return is_array($m) ? $m : [];
    }

    /** الذكاء الصناعي يشوف الصورة ويوصفها (مرة واحدة لكل صورة) */
    public function learn(int $uid, int $mediaId, ?callable $vision = null): ?array
    {
        $row = PrivateMedia::owned($uid, $mediaId);
        $meta = self::meta($row);
        if (!empty($meta['vision']['description'])) {
            return $meta['vision'];
        }
        if (($meta['source'] ?? '') === 'brand_design') {
            return null;
        }
        $file = $this->imageFile($uid, $row);
        if (!$file) {
            return null;
        }
        $jpeg = self::smallJpeg((string) file_get_contents($file));
        if ($jpeg === null) {
            return null;
        }
        $prompt = 'You help a tourism company pick photos for social media posts. Look at this photo and return JSON only: '
            . '{"description":"one factual English sentence (max 25 words): what is shown and where","place":"city/landmark only if clearly recognizable, else empty",'
            . '"kind":"destination|hotel|activity|food|transport|people|team|graphic|logo|other","tags":["up to 6 short English tags"],'
            . '"has_text":true if the photo already has big text or is a flyer,"quality":1-5 for how good it looks as a social post}';
        $vision ??= static function (string $jpeg, string $prompt) use ($uid): array {
            return (new GeminiClient())->describeImage($jpeg, 'image/jpeg', $prompt, ['user_id' => $uid, 'responseMimeType' => 'application/json']);
        };
        $r = $vision($jpeg, $prompt);
        if (empty($r['success'])) {
            return null;
        }
        $parsed = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) ($r['data'] ?? ''))), true);
        if (!is_array($parsed) || empty($parsed['description'])) {
            return null;
        }
        $kinds = ['destination', 'hotel', 'activity', 'food', 'transport', 'people', 'team', 'graphic', 'logo', 'other'];
        $out = [
            'description' => mb_substr(trim((string) $parsed['description']), 0, 220),
            'place' => mb_substr(trim((string) ($parsed['place'] ?? '')), 0, 80),
            'kind' => in_array($parsed['kind'] ?? '', $kinds, true) ? $parsed['kind'] : 'other',
            'tags' => array_slice(array_values(array_filter(array_map(static fn ($t) => mb_substr(trim((string) $t), 0, 30), is_array($parsed['tags'] ?? null) ? $parsed['tags'] : []))), 0, 6),
            'has_text' => !empty($parsed['has_text']),
            'quality' => max(1, min(5, (int) ($parsed['quality'] ?? 3))),
        ];
        $meta['vision'] = $out;
        $this->q('UPDATE media_items SET generation_meta=? WHERE id=? AND user_id=?', [json_encode($meta, JSON_UNESCAPED_UNICODE), $mediaId, $uid]);
        return $out;
    }

    /** يتعلم الصور اللي لسه ماتعلمهاش (بحد أقصى عشان مايطوّلش) */
    public function learnMissing(int $uid, array $ids, int $max = 12): void
    {
        foreach (array_slice($ids, 0, 60) as $id) {
            if ($max <= 0) {
                return;
            }
            try {
                $row = PrivateMedia::owned($uid, (int) $id);
                if (!empty(self::meta($row)['vision']) || !$this->imageFile($uid, $row)) {
                    continue;
                }
                $max--;
                $this->learn($uid, (int) $id);
            } catch (Throwable $e) {
                // صورة واحدة فشلت مش بتوقف الخطة
            }
        }
    }

    /** ملخص اللي الذكاء الصناعي اتعلمه من صور العميل */
    public function learnedSummary(int $uid): array
    {
        $rows = $this->q("SELECT generation_meta FROM media_items WHERE user_id=? AND status='completed' AND deleted_at IS NULL AND generation_meta LIKE '%\"vision\"%' ORDER BY id DESC LIMIT 200", [$uid])->fetchAll(PDO::FETCH_COLUMN);
        $places = [];
        $kinds = [];
        foreach ($rows as $json) {
            $v = json_decode((string) $json, true)['vision'] ?? null;
            if (!is_array($v)) {
                continue;
            }
            if (($v['place'] ?? '') !== '') {
                $places[$v['place']] = ($places[$v['place']] ?? 0) + 1;
            }
            $kinds[$v['kind'] ?? 'other'] = ($kinds[$v['kind'] ?? 'other'] ?? 0) + 1;
        }
        arsort($places);
        arsort($kinds);
        return ['photos' => count($rows), 'places' => array_slice(array_keys($places), 0, 6), 'kinds' => array_slice($kinds, 0, 5, true)];
    }

    public static function smallJpeg(string $binary, int $max = 900): ?string
    {
        $im = @imagecreatefromstring($binary);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $s = min(1, $max / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) ($w * $s)), max(1, (int) ($h * $s)));
        imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start();
        imagejpeg($out, null, 82);
        imagedestroy($im);
        imagedestroy($out);
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------ التصميم

    /**
     * يعمل بوست بهوية الشركة ويحفظه في مكتبة العميل.
     * @param string $format design|card
     * @return int رقم الصورة الجديدة
     */
    private function existingDesign(int $uid, int $wid, string $format, ?int $baseMediaId, string $headline, string $subline): ?int
    {
        $rows = $this->q("SELECT id,generation_meta FROM media_items WHERE user_id=? AND status='completed' AND deleted_at IS NULL AND generation_meta LIKE '%\"brand_design\"%' ORDER BY id DESC LIMIT 300", [$uid])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $m = json_decode((string) $r['generation_meta'], true) ?: [];
            if ((int) ($m['website_id'] ?? 0) === $wid && ($m['format'] ?? '') === $format && ($format === 'card' || (int) ($m['base_media_id'] ?? 0) === (int) $baseMediaId)
                && trim((string) ($m['headline'] ?? '')) === $headline && trim((string) ($m['subline'] ?? '')) === $subline) {
                return (int) $r['id'];
            }
        }
        return null;
    }

    public function design(int $uid, int $wid, string $format, string $headline, string $subline, ?int $baseMediaId, int $variant = 0): int
    {
        if (!SocialPostDesigner::available()) {
            throw new RuntimeException('Designer unavailable');
        }
        $kit = $this->kit($uid, $wid);
        $photo = null;
        if ($baseMediaId) {
            $file = $this->imageFile($uid, PrivateMedia::owned($uid, $baseMediaId));
            $photo = $file ? (string) file_get_contents($file) : null;
        }
        $format = $format === 'card' || !$photo ? 'card' : 'design';
        // نفس الصورة ونفس النص اتصمموا قبل كده: نرجّع التصميم الموجود بدل ما نملا المكتبة بنسخ متطابقة
        $existing = $this->existingDesign($uid, $wid, $format, $format === 'card' ? null : $baseMediaId, trim($headline), trim($subline));
        if ($existing) {
            return $existing;
        }
        $template = SocialPostDesigner::TEMPLATES[abs($variant) % count(SocialPostDesigner::TEMPLATES)];
        $jpeg = (new SocialPostDesigner())->render([
            'format' => $format,
            'template' => $template,
            'photo' => $format === 'card' ? null : $photo,
            'headline' => mb_substr(trim($headline), 0, 90),
            'subline' => mb_substr(trim($subline), 0, 50),
            'brand_name' => $kit['brand_name'],
            'domain' => $kit['domain'],
            'logo' => $kit['logo_url'] ? (string) file_get_contents($this->publicRoot . $kit['logo_url']) : null,
            'primary' => $kit['primary'],
            'accent' => $kit['accent'],
        ]);
        $dir = $this->publicRoot . '/uploads/social/' . $uid;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Upload folder not writable');
        }
        $path = '/uploads/social/' . $uid . '/design_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.jpg';
        file_put_contents($this->publicRoot . $path, $jpeg);
        $meta = ['source' => 'brand_design', 'format' => $format, 'template' => $template, 'base_media_id' => $baseMediaId, 'headline' => $headline, 'subline' => $subline, 'website_id' => $wid];
        $this->q("INSERT INTO media_items(user_id,type,prompt,file_path,thumbnail_path,width,height,status,generation_meta,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'completed',?,NOW(),NOW())", [
            $uid, 'social_image', mb_substr($headline, 0, 250), $path, $path, SocialPostDesigner::W, SocialPostDesigner::H, json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
