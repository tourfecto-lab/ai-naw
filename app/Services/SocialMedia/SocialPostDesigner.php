<?php
require_once __DIR__ . '/ArabicShaper.php';

/**
 * Tourfecto - مصمم بوستات بهوية الشركة (زي شغل شركات السوشيال)
 *
 * بياخد صورة حقيقية من صور العميل + اللوجو + ألوان الشركة + عنوان قصير،
 * ويطلع بوست 1080x1350 (المقاس المثالي لإنستجرام وفيسبوك) جاهز للنشر.
 *
 * الأشكال:
 *   design: الصورة هي البطل + العنوان والعرض بألوان الشركة (3 قوالب بتتبدّل)
 *   card:   بوستر بألوان الشركة (نصيحة / عرض / سؤال) والصورة خلفية خفيفة لو موجودة
 */
class SocialPostDesigner
{
    public const W = 1080;
    public const H = 1350;
    public const TEMPLATES = ['overlay', 'panel', 'frame'];

    private string $bold;
    private string $heavy;

    public function __construct(?string $fontDir = null)
    {
        $dir = $fontDir ?? dirname(__DIR__, 3) . '/public_html/assets/fonts';
        $this->bold = $dir . '/Tajawal-Bold.ttf';
        $this->heavy = $dir . '/Tajawal-ExtraBold.ttf';
    }

    public static function available(): bool
    {
        return function_exists('imagettftext') && function_exists('imagecreatefromstring')
            && is_file(dirname(__DIR__, 3) . '/public_html/assets/fonts/Tajawal-ExtraBold.ttf');
    }

    /**
     * @param array{format:string, template?:string, photo?:?string, headline:string, subline?:string,
     *              brand_name?:string, domain?:string, logo?:?string, primary?:string, accent?:string} $spec
     * @return string JPEG
     */
    public function render(array $spec): string
    {
        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);
        $primary = self::rgb($spec['primary'] ?? '#0F4C81', [15, 76, 129]);
        $accent = self::rgb($spec['accent'] ?? '#F5A623', [245, 166, 35]);
        $photo = !empty($spec['photo']) ? @imagecreatefromstring((string) $spec['photo']) : false;
        $logo = !empty($spec['logo']) ? @imagecreatefromstring((string) $spec['logo']) : false;
        $headline = trim((string) ($spec['headline'] ?? ''));
        $subline = trim((string) ($spec['subline'] ?? ''));
        $footer = trim((string) ($spec['domain'] ?? ''));
        $format = ($spec['format'] ?? 'design') === 'card' || !$photo ? 'card' : 'design';
        $template = in_array($spec['template'] ?? '', self::TEMPLATES, true) ? $spec['template'] : 'overlay';

        if ($format === 'card') {
            $this->card($img, $photo, $primary, $accent, $headline, $subline, $footer, $logo, (string) ($spec['brand_name'] ?? ''));
        } elseif ($template === 'panel') {
            $this->panel($img, $photo, $primary, $accent, $headline, $subline, $footer, $logo);
        } elseif ($template === 'frame') {
            $this->frame($img, $photo, $primary, $accent, $headline, $subline, $footer, $logo);
        } else {
            $this->overlay($img, $photo, $primary, $accent, $headline, $subline, $footer, $logo);
        }
        if ($photo) {
            imagedestroy($photo);
        }
        if ($logo) {
            imagedestroy($logo);
        }
        ob_start();
        imagejpeg($img, null, 90);
        $out = (string) ob_get_clean();
        imagedestroy($img);
        return $out;
    }

    /** الصورة كاملة + تدرّج بلون الشركة تحت + العنوان */
    private function overlay($img, $photo, array $p, array $a, string $head, string $sub, string $footer, $logo): void
    {
        $this->cover($img, $photo, 0, 0, self::W, self::H);
        $dark = self::mix($p, [0, 0, 0], 0.55);
        $gh = (int) (self::H * 0.58);
        for ($i = 0; $i < $gh; $i++) {
            $t = $i / $gh;
            $alpha = (int) round(127 - pow($t, 1.25) * 117);
            imageline($img, 0, self::H - $gh + $i, self::W, self::H - $gh + $i, imagecolorallocatealpha($img, $dark[0], $dark[1], $dark[2], max(0, min(127, $alpha))));
        }
        $this->textBox($img, $head, $this->heavy, 76, 50, (int) (self::H * 0.55), self::H - ($sub !== '' ? 255 : 110), [255, 255, 255], 4, 'bottom');
        if ($sub !== '') {
            $this->pill($img, $sub, self::H - 200, $a, self::readable($a));
        }
        $this->accentBar($img, $a);
        $this->logoBadge($img, $logo, 'top');
        $this->footer($img, $footer, [255, 255, 255]);
    }

    /** الصورة فوق + لوحة بلون الشركة تحت فيها الكلام */
    private function panel($img, $photo, array $p, array $a, string $head, string $sub, string $footer, $logo): void
    {
        $ph = (int) (self::H * 0.64);
        $this->cover($img, $photo, 0, 0, self::W, $ph);
        imagefilledrectangle($img, 0, $ph, self::W, self::H, imagecolorallocate($img, $p[0], $p[1], $p[2]));
        // موجة خفيفة بين الصورة واللوحة
        imagefilledellipse($img, (int) (self::W * 0.5), $ph, (int) (self::W * 1.6), 90, imagecolorallocate($img, $p[0], $p[1], $p[2]));
        imagefilledrectangle($img, 0, $ph + 46, 240, $ph + 54, imagecolorallocate($img, $a[0], $a[1], $a[2]));
        $txt = self::readable($p);
        $this->textBox($img, $head, $this->heavy, 68, 44, $ph + 70, self::H - ($sub !== '' ? 250 : 100), $txt, 3);
        if ($sub !== '') {
            $this->pill($img, $sub, self::H - 196, $a, self::readable($a));
        }
        $this->logoBadge($img, $logo, 'top');
        $this->footer($img, $footer, $txt);
    }

    /** خلفية بلون الشركة + الصورة في إطار بحواف دائرية */
    private function frame($img, $photo, array $p, array $a, string $head, string $sub, string $footer, $logo): void
    {
        imagefilledrectangle($img, 0, 0, self::W, self::H, imagecolorallocate($img, $p[0], $p[1], $p[2]));
        $soft = self::mix($p, [255, 255, 255], 0.12);
        imagefilledellipse($img, self::W - 60, 120, 520, 520, imagecolorallocate($img, $soft[0], $soft[1], $soft[2]));
        imagefilledellipse($img, 40, self::H - 60, 360, 360, imagecolorallocate($img, $a[0], $a[1], $a[2]));
        $m = 70;
        $top = 170;
        $bottom = (int) (self::H * 0.66);
        $layer = imagecreatetruecolor(self::W - 2 * $m, $bottom - $top);
        $this->cover($layer, $photo, 0, 0, self::W - 2 * $m, $bottom - $top);
        $this->roundedCopy($img, $layer, $m, $top, 36);
        imagedestroy($layer);
        $txt = self::readable($p);
        $this->textBox($img, $head, $this->heavy, 64, 40, $bottom + 30, self::H - ($sub !== '' ? 210 : 100), $txt, 3);
        if ($sub !== '') {
            $this->pill($img, $sub, self::H - 190, $a, self::readable($a));
        }
        $this->logoBadge($img, $logo, 'top', 34);
        $this->footer($img, $footer, $txt);
    }

    /** بوستر: نصيحة / عرض / سؤال */
    private function card($img, $photo, array $p, array $a, string $head, string $sub, string $footer, $logo, string $brand): void
    {
        imagefilledrectangle($img, 0, 0, self::W, self::H, imagecolorallocate($img, $p[0], $p[1], $p[2]));
        if ($photo) {
            $this->cover($img, $photo, 0, 0, self::W, self::H);
            imagefilledrectangle($img, 0, 0, self::W, self::H, imagecolorallocatealpha($img, $p[0], $p[1], $p[2], 22));
        }
        $soft = self::mix($p, [255, 255, 255], 0.1);
        imagefilledellipse($img, self::W, 0, 700, 700, imagecolorallocatealpha($img, $soft[0], $soft[1], $soft[2], 40));
        imagefilledellipse($img, 0, self::H, 560, 560, imagecolorallocatealpha($img, $a[0], $a[1], $a[2], 30));
        $txt = self::readable($p);
        // علامة اقتباس كبيرة بلون الأكسنت
        imagefilledrectangle($img, (int) (self::W / 2 - 60), 360, (int) (self::W / 2 + 60), 372, imagecolorallocate($img, $a[0], $a[1], $a[2]));
        $this->textBox($img, $head, $this->heavy, 84, 50, 410, self::H - ($sub !== '' ? 340 : 160), $txt, 6);
        if ($sub !== '') {
            $this->pill($img, $sub, self::H - 300, $a, self::readable($a));
        }
        if ($logo) {
            $this->logoBadge($img, $logo, 'center');
        } elseif ($brand !== '') {
            $this->textBox($img, $brand, $this->bold, 40, 30, 150, 230, $txt, 1);
        }
        $this->footer($img, $footer, $txt);
    }

    // ------------------------------------------------------------------ أدوات

    /** يقص الصورة عشان تملا المساحة من غير مط */
    private function cover($dst, $src, int $x, int $y, int $w, int $h): void
    {
        if (!$src) {
            return;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $ch = (int) round($h / $scale);
        imagecopyresampled($dst, $src, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2.4), $w, $h, $cw, $ch);
    }

    /**
     * عنوان على كذا سطر جوه مساحة (من top لـ bottom)، بيصغّر الخط لحد ما يدخل
     * وبيتوسّط في المساحة (أو يلزق تحت مع 'bottom').
     */
    private function textBox($img, string $text, string $font, int $size, int $minSize, int $top, int $bottom, array $rgb, int $maxLines, string $align = 'center'): void
    {
        if ($text === '') {
            return;
        }
        $maxW = self::W - 140;
        for (; ; $size -= 4) {
            $lines = $this->wrap($text, $font, $size, $maxW);
            $lh = (int) round($size * 1.42);
            $height = $lh * (count($lines) - 1) + $size;
            if ((count($lines) <= $maxLines && $height <= $bottom - $top) || $size - 4 < $minSize) {
                break;
            }
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] .= '…';
            $height = $lh * ($maxLines - 1) + $size;
        }
        $first = $align === 'bottom' ? $bottom - $height + $size : $top + (int) (($bottom - $top - $height) / 2) + $size;
        $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 90);
        foreach ($lines as $i => $line) {
            $vis = ArabicShaper::line($line);
            $box = imagettfbbox($size, 0, $font, $vis);
            $x = (int) ((self::W - abs($box[2] - $box[0])) / 2);
            $by = $first + $i * $lh;
            imagettftext($img, $size, 0, $x + 2, $by + 3, $shadow, $font, $vis);
            imagettftext($img, $size, 0, $x, $by, $color, $font, $vis);
        }
    }

    /** @return string[] سطور بالترتيب المنطقي */
    private function wrap(string $text, string $font, int $size, int $maxW): array
    {
        $words = preg_split('/\s+/u', trim($text));
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            $box = imagettfbbox($size, 0, $font, ArabicShaper::line($try));
            if ($cur !== '' && abs($box[2] - $box[0]) > $maxW) {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines;
    }

    /** العرض/السعر/الدعوة في كبسولة بلون الأكسنت */
    private function pill($img, string $text, int $y, array $bg, array $fg): void
    {
        $size = 38;
        $vis = ArabicShaper::line(mb_substr($text, 0, 60));
        do {
            $box = imagettfbbox($size, 0, $this->bold, $vis);
            $tw = abs($box[2] - $box[0]);
            $size -= 2;
        } while ($tw > self::W - 220 && $size > 22);
        $size += 2;
        $padX = 44;
        $h = $size + 48;
        $x1 = (int) ((self::W - $tw) / 2) - $padX;
        self::roundedRect($img, $x1, $y, $x1 + $tw + 2 * $padX, $y + $h, (int) ($h / 2), imagecolorallocate($img, $bg[0], $bg[1], $bg[2]));
        imagettftext($img, $size, 0, $x1 + $padX, $y + (int) ($h / 2 + $size / 2) - 2, imagecolorallocate($img, $fg[0], $fg[1], $fg[2]), $this->bold, $vis);
    }

    private function accentBar($img, array $a): void
    {
        imagefilledrectangle($img, 0, self::H - 10, self::W, self::H, imagecolorallocate($img, $a[0], $a[1], $a[2]));
    }

    /** اللوجو في كبسولة بيضا (عشان يبان على أي خلفية) */
    private function logoBadge($img, $logo, string $pos, int $top = 46): void
    {
        if (!$logo) {
            return;
        }
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $th = 84;
        $tw = (int) round($lw * $th / max(1, $lh));
        if ($tw > 300) {
            $tw = 300;
            $th = (int) round($lh * $tw / max(1, $lw));
        }
        $pad = 18;
        $bw = $tw + 2 * $pad;
        $bh = $th + 2 * $pad;
        $x = $pos === 'center' ? (int) ((self::W - $bw) / 2) : self::W - $bw - 46;
        $y = $pos === 'center' ? 140 : $top;
        self::roundedRect($img, $x, $y, $x + $bw, $y + $bh, 22, imagecolorallocatealpha($img, 255, 255, 255, 8));
        imagecopyresampled($img, $logo, $x + $pad, $y + $pad, 0, 0, $tw, $th, $lw, $lh);
    }

    private function footer($img, string $text, array $rgb): void
    {
        if ($text === '') {
            return;
        }
        $vis = ArabicShaper::line(mb_substr($text, 0, 50));
        $box = imagettfbbox(24, 0, $this->bold, $vis);
        imagettftext($img, 24, 0, (int) ((self::W - abs($box[2] - $box[0])) / 2), self::H - 40, imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], 25), $this->bold, $vis);
    }

    private function roundedCopy($dst, $src, int $dx, int $dy, int $r): void
    {
        $w = imagesx($src);
        $h = imagesy($src);
        imagecopy($dst, $src, $dx, $dy, 0, 0, $w, $h);
        // نقص الزوايا بلون الخلفية اللي تحتها
        for ($y = 0; $y < $r; $y++) {
            $cut = (int) ceil($r - sqrt($r * $r - ($r - $y) * ($r - $y)));
            foreach ([[$dy + $y, true], [$dy + $h - 1 - $y, false]] as [$yy]) {
                $c = imagecolorat($dst, max(0, $dx - 1), $yy);
                imageline($dst, $dx, $yy, $dx + $cut - 1, $yy, $c);
                $c2 = imagecolorat($dst, min(self::W - 1, $dx + $w), $yy);
                imageline($dst, $dx + $w - $cut, $yy, $dx + $w - 1, $yy, $c2);
            }
        }
    }

    private static function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = max(1, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $color);
        imagefilledrectangle($img, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r, 180, 270], [$x2 - $r, $y1 + $r, 270, 360], [$x1 + $r, $y2 - $r, 90, 180], [$x2 - $r, $y2 - $r, 0, 90]] as [$cx, $cy, $s, $e]) {
            imagefilledarc($img, $cx, $cy, $r * 2, $r * 2, $s, $e, $color, IMG_ARC_PIE);
        }
    }

    public static function rgb(string $hex, array $fallback): array
    {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $fallback;
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function mix(array $a, array $b, float $t): array
    {
        return [(int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t)];
    }

    /** أبيض على الألوان الغامقة، كحلي غامق على الفاتحة */
    public static function readable(array $bg): array
    {
        $l = 0.2126 * $bg[0] + 0.7152 * $bg[1] + 0.0722 * $bg[2];
        return $l > 150 ? [20, 28, 45] : [255, 255, 255];
    }
}
