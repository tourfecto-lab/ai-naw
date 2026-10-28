<?php

/**
 * صور موقع العميل (استوديو التصميم): لوجو، صورة الغلاف، الجاليري، صورة كل رحلة.
 * أمان: بنقبل JPG/PNG/WebP بس، بنتأكد إنها صورة فعلًا، وبنعيد رسمها بـGD
 * (بيشيل أي كود متخبي وبيصغّر الحجم) وبنحفظها بامتداد ثابت تحت uploads/sites/{id}/.
 */
class SiteMediaService
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    public const KINDS = ['logo' => 600, 'hero' => 2000, 'gallery' => 1600, 'tour' => 1600, 'article' => 1600];
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_GALLERY = 24;

    /** @return array{success:bool, url?:string, error?:string} */
    public function store(int $websiteId, string $kind, array $file, ?string $dirRel = null): array
    {
        if (!isset(self::KINDS[$kind])) return ['success' => false, 'error' => self::L('نوع غير معروف', 'Unknown type')];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['success' => false, 'error' => self::L('الملف ماوصلش - جرّب تاني', 'The file didn\'t arrive - try again')];
        }
        if ((int) $file['size'] > self::MAX_BYTES) return ['success' => false, 'error' => self::L('الصورة أكبر من 8 ميجا', 'Image is larger than 8 MB')];
        return $this->storePath($websiteId, $kind, (string) $file['tmp_name'], $dirRel);
    }

    /** بتشتغل على مسار ملف (منفصلة عشان الاختبارات) */
    /** $dirRel: مجلد تاني تحت public_html (صور خطة المقالات: /uploads/articles/{user}) */
    public function storePath(int $websiteId, string $kind, string $path, ?string $dirRel = null): array
    {
        if (!isset(self::KINDS[$kind])) return ['success' => false, 'error' => self::L('نوع غير معروف', 'Unknown type')];
        $dirRel ??= '/uploads/sites/' . $websiteId;
        $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) : '';
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true))) {
            return ['success' => false, 'error' => self::L('الصورة لازم تكون JPG أو PNG أو WebP', 'Image must be JPG, PNG or WebP')];
        }
        if ($info[0] < 40 || $info[1] < 40 || $info[0] * $info[1] > 40000000) return ['success' => false, 'error' => self::L('مقاس الصورة غير مناسب', 'Image size is not suitable')];
        $dir = ROOT_PATH . '/public_html' . $dirRel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['success' => false, 'error' => self::L('تعذر حفظ الصورة', 'Could not save the image')];
        $name = $kind . '-' . bin2hex(random_bytes(6));
        $max = self::KINDS[$kind];
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string) file_get_contents($path));
            if (!$img) return ['success' => false, 'error' => self::L('الصورة تالفة', 'The image is damaged')];
            [$w, $h] = [imagesx($img), imagesy($img)];
            $scale = min(1, $max / max($w, $h));
            if ($scale < 1) {
                $nw = (int) round($w * $scale);
                $nh = (int) round($h * $scale);
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }
            // اللوجو بيفضل PNG (شفافية)، الباقي WebP لو متاح (أخف) وإلا JPG
            if ($kind === 'logo') {
                imagesavealpha($img, true);
                $ok = imagepng($img, "$dir/$name.png", 7);
                $file = "$name.png";
            } elseif (function_exists('imagewebp')) {
                $ok = imagewebp($img, "$dir/$name.webp", 82);
                $file = "$name.webp";
            } else {
                $ok = imagejpeg($img, "$dir/$name.jpg", 84);
                $file = "$name.jpg";
            }
            imagedestroy($img);
            if (!$ok) return ['success' => false, 'error' => self::L('تعذر حفظ الصورة', 'Could not save the image')];
        } else {
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
            $file = "$name.$ext";
            if (!copy($path, "$dir/$file")) return ['success' => false, 'error' => self::L('تعذر حفظ الصورة', 'Could not save the image')];
        }
        return ['success' => true, 'url' => $dirRel . '/' . $file];
    }

    /** نمسح ملف من مجلد الموقع ده بس (مش أي مسار) */
    public function delete(int $websiteId, string $url): void
    {
        $prefix = '/uploads/sites/' . $websiteId . '/';
        if (!str_starts_with($url, $prefix)) return;
        $name = basename(substr($url, strlen($prefix)));
        if (!preg_match('/^(logo|hero|gallery|tour)-[a-f0-9]{12}\.(png|webp|jpg)$/', $name)) return;
        @unlink(ROOT_PATH . '/public_html' . $prefix . $name);
    }

    public static function maxGallery(): int
    {
        return self::MAX_GALLERY;
    }
}
