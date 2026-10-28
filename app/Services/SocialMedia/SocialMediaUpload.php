<?php

/**
 * Tourfecto - رفع صورة/فيديو للمنشور من جهاز العميل
 *
 * قبل كده فورم المنشور ماكانش فيه رفع خالص، فإنستجرام كان بيفشل دايمًا ("محتاج صورة").
 * الصور بتتحول JPG (إنستجرام مابيقبلش غيره) وبيتظبط مقاسها لحدود إنستجرام (4:5 لحد 1.91:1)
 * بإضافة هوامش بدل القص، وأقصى عرض 1440. الفيديو بيتحفظ زي ما هو (MP4/MOV).
 */
class SocialMediaUpload
{
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 100 * 1024 * 1024;
    private const MAX_SIDE = 1440;
    private const MIN_RATIO = 0.8;   // 4:5
    private const MAX_RATIO = 1.91;  // 1.91:1

    /** @return array{ok:bool, item?:array, error?:string} */
    public static function handle(int $userId, ?array $file, ?string $publicRoot = null): array
    {
        if (!$file || !isset($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'اختار صورة أو فيديو الأول'];
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => self::uploadError($err)];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_file($tmp) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            return ['ok' => false, 'error' => 'الملف ما وصلش كامل، جرّب تاني'];
        }
        $size = (int) filesize($tmp);
        $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) : (string) ($file['type'] ?? '');

        $root = rtrim($publicRoot ?: ROOT_PATH . '/public_html', '/');
        $dir = $root . '/uploads/social/' . $userId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'السيرفر مش قادر يحفظ الملف (فولدر uploads مش قابل للكتابة)'];
        }
        $base = date('Ymd') . '_' . bin2hex(random_bytes(6));

        if (in_array($mime, ['video/mp4', 'video/quicktime'], true)) {
            if ($size > self::MAX_VIDEO_BYTES) {
                return ['ok' => false, 'error' => 'الفيديو أكبر من 100 ميجا'];
            }
            $name = $base . ($mime === 'video/mp4' ? '.mp4' : '.mov');
            if (!self::moveFile($tmp, $dir . '/' . $name)) {
                return ['ok' => false, 'error' => 'تعذر حفظ الفيديو'];
            }
            return self::saveItem($userId, 'short_video', '/uploads/social/' . $userId . '/' . $name, null, null, (string) ($file['name'] ?? ''));
        }

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['ok' => false, 'error' => 'نوع الملف مش مدعوم. ارفع صورة JPG/PNG/WEBP أو فيديو MP4/MOV'];
        }
        if ($size > self::MAX_IMAGE_BYTES) {
            return ['ok' => false, 'error' => 'الصورة أكبر من 10 ميجا'];
        }
        $img = self::load($tmp, $mime);
        if (!$img) {
            return ['ok' => false, 'error' => 'الصورة تالفة أو السيرفر مش قادر يقراها'];
        }
        $img = self::fit($img);
        $name = $base . '.jpg';
        $ok = imagejpeg($img, $dir . '/' . $name, 88);
        // الصورة دي موجودة في المكتبة قبل كده؟ نرجّع القديمة بدل ما تتكرر
        $phash = null;
        if ($ok && class_exists('Database')) {
            require_once __DIR__ . '/SiteImageImporter.php';
            try {
                $phash = SiteImageImporter::phash((string) file_get_contents($dir . '/' . $name));
                $dup = $phash ? SiteImageImporter::duplicateOf(Database::getInstance()->getConnection(), $userId, $phash) : null;
                if ($dup) {
                    imagedestroy($img);
                    @unlink($dir . '/' . $name);
                    $r = Database::getInstance()->getConnection()->prepare('SELECT file_path FROM media_items WHERE id=?');
                    $r->execute([$dup]);
                    return ['ok' => true, 'duplicate' => true, 'item' => ['id' => $dup, 'type' => 'social_image', 'file_path' => (string) $r->fetchColumn(), 'is_video' => false]];
                }
            } catch (Throwable $e) {
                $phash = null;
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        imagedestroy($img);
        if (!$ok) {
            return ['ok' => false, 'error' => 'تعذر حفظ الصورة'];
        }
        return self::saveItem($userId, 'social_image', '/uploads/social/' . $userId . '/' . $name, $w, $h, (string) ($file['name'] ?? ''), $phash);
    }

    private static function moveFile(string $tmp, string $dest): bool
    {
        return PHP_SAPI === 'cli' ? @copy($tmp, $dest) : @move_uploaded_file($tmp, $dest);
    }

    /** @return \GdImage|resource|null */
    private static function load(string $path, string $mime)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$img) {
            return null;
        }
        // صور الموبايل: الاتجاه متخزن في EXIF، من غيره الصورة بتتنشر نايمة
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $o = (int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1);
            $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($deg) {
                $r = imagerotate($img, $deg, 0);
                if ($r) {
                    imagedestroy($img);
                    $img = $r;
                }
            }
        }
        return $img;
    }

    /** يصغّر لأقصى 1440 ويضيف هوامش بيضا لو النسبة برّه حدود إنستجرام */
    private static function fit($img)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $ratio = $nw / $nh;
        $cw = $nw;
        $ch = $nh;
        if ($ratio < self::MIN_RATIO) {
            $cw = (int) ceil($nh * self::MIN_RATIO);
        } elseif ($ratio > self::MAX_RATIO) {
            $ch = (int) ceil($nw / self::MAX_RATIO);
        }
        $out = imagecreatetruecolor($cw, $ch);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, (int) (($cw - $nw) / 2), (int) (($ch - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $out;
    }

    private static function saveItem(int $userId, string $type, string $path, ?int $w, ?int $h, string $origName, ?string $phash = null): array
    {
        $label = 'رفع من الجهاز' . ($origName !== '' ? ': ' . mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $origName), 0, 120) : '');
        $item = new MediaItem([
            'user_id' => $userId,
            'type' => $type,
            'prompt' => $label,
            'file_path' => $path,
            'thumbnail_path' => $type === 'short_video' ? null : $path,
            'width' => $w,
            'height' => $h,
            'status' => 'completed',
            'generation_meta' => json_encode(['source' => 'upload'] + ($phash ? ['phash' => $phash] : [])),
        ]);
        $item->save();
        return ['ok' => true, 'item' => [
            'id' => (int) $item->getAttribute('id'),
            'type' => $type,
            'file_path' => $path,
            'is_video' => $type === 'short_video',
        ]];
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد المسموح على السيرفر (upload_max_filesize). صغّر الملف أو زوّد الحد من إعدادات PHP في aaPanel.',
            UPLOAD_ERR_PARTIAL => 'الملف اترفع ناقص، جرّب تاني',
            UPLOAD_ERR_NO_FILE => 'اختار صورة أو فيديو الأول',
            default => 'تعذر رفع الملف (كود ' . $code . ')',
        };
    }
}
