<?php

/**
 * Tourfecto - Brand Image Composer
 * بعد توليد الصورة: تدرّج لوني خفيف بلون العلامة أسفل الصورة + شريط لوني رفيع + اللوجو في الزاوية.
 * (النصوص العربية مش بتتكتب جوه الصورة عشان GD مبيعملش ربط الحروف - العنوان بيتنشر مع الكابشن.)
 * @version 1.0.0
 */
class BrandImageComposer
{
    /** @return string|null بيانات PNG/JPG بعد التركيب، أو null لو التركيب مش ممكن (نستخدم الأصلية) */
    public static function compose(string $imageBinary, ?string $logoPath, array $palette, string $mime = 'image/png'): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($imageBinary);
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        imagealphablending($img, true);

        $primary = self::rgb((string) ($palette['primary'] ?? '#0F4C81'));
        $accent = self::rgb((string) ($palette['accent'] ?? '#F5A623'));

        // 1) تدرّج ناعم أسفل الصورة (غامق مائل للون العلامة) عشان اللوجو يبان من غير مربع ثقيل
        $gh = (int) round($h * 0.26);
        $dark = [(int) ($primary[0] * 0.35), (int) ($primary[1] * 0.35), (int) ($primary[2] * 0.35)];
        for ($i = 0; $i < $gh; $i++) {
            $t = $i / $gh;                                  // 0 فوق → 1 تحت
            $alpha = (int) round(127 - (pow($t, 1.6) * 92));   // منحنى ناعم
            $col = imagecolorallocatealpha($img, $dark[0], $dark[1], $dark[2], max(0, min(127, $alpha)));
            imageline($img, 0, $h - $gh + $i, $w, $h - $gh + $i, $col);
        }
        // 2) خط رفيع بلون الأكسنت في أسفل الصورة
        $barH = max(3, (int) round($h * 0.005));
        imagefilledrectangle($img, 0, $h - $barH, $w, $h, imagecolorallocate($img, $accent[0], $accent[1], $accent[2]));

        // 3) اللوجو داخل كبسولة بيضاء شبه شفافة بحواف دائرية
        if ($logoPath) {
            $file = ROOT_PATH . '/public_html' . $logoPath;
            $logo = is_file($file) ? @imagecreatefrompng($file) : false;
            if ($logo) {
                $lw = imagesx($logo);
                $lh = imagesy($logo);
                $targetH = (int) round($h * 0.07);
                $targetW = (int) round($lw * ($targetH / max(1, $lh)));
                $maxW = (int) round($w * 0.26);
                if ($targetW > $maxW) {
                    $targetW = $maxW;
                    $targetH = (int) round($lh * ($targetW / max(1, $lw)));
                }
                $padX = (int) round($targetH * 0.38);
                $padY = (int) round($targetH * 0.28);
                $bw = $targetW + $padX * 2;
                $bh = $targetH + $padY * 2;
                $margin = (int) round($w * 0.035);
                $x = $w - $bw - $margin;
                $y = $h - $bh - $margin - $barH;
                self::roundedRect($img, $x, $y, $x + $bw, $y + $bh, (int) round($bh * 0.28), imagecolorallocatealpha($img, 255, 255, 255, 12));
                imagecopyresampled($img, $logo, $x + $padX, $y + $padY, 0, 0, $targetW, $targetH, $lw, $lh);
                imagedestroy($logo);
            }
        }

        ob_start();
        if (strpos($mime, 'jpeg') !== false) {
            imagejpeg($img, null, 92);
        } else {
            imagepng($img, null, 6);
        }
        $out = ob_get_clean();
        imagedestroy($img);
        return $out !== '' ? $out : null;
    }

    private static function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        $d = $r * 2;
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $d, $d, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $d, $d, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $d, $d, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $d, $d, $color);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '0F4C81';
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
