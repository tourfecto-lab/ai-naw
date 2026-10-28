<?php

/**
 * Tourfecto - Site Logo Upload Handler
 * رفع لوجو الموقع من لوحة الأدمن. نفس نمط AvatarUploadHandler.php
 * (نفس درس التخزين جوه public_html عشان الرابط يشتغل فعليًا)، بس
 * بمقاس أقصى أكبر شوية مناسب للوجو (400px بدل 800px مش لازم أصلاً -
 * العكس، اللوجو أصغر عادةً من صورة شخصية لكن ممكن يكون عريض، فخلّينا
 * الحد الأقصى معقول لأي اتجاه).
 * @version 1.0.0
 */
class SiteLogoUploadHandler
{
    private array $allowedTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];
    private int $maxSize = 2 * 1024 * 1024; // 2MB كفاية جدًا للوجو
    private int $maxDimension = 600;

    /** @return array ['success'=>bool, 'url'=>?string, 'error'=>?string] */
    public function upload(array $file, ?string $oldLogoUrl = null): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'الملف أكبر من الحد المسموح به في إعدادات السيرفر',
                UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد المسموح به',
                UPLOAD_ERR_PARTIAL => 'الرفع اتقطع في النص، جرّب تاني',
                UPLOAD_ERR_NO_FILE => 'لم يتم اختيار أي ملف',
            ];
            return ['success' => false, 'error' => $messages[$file['error'] ?? -1] ?? 'تعذر رفع اللوجو'];
        }

        if ($file['size'] > $this->maxSize) {
            return ['success' => false, 'error' => 'اللوجو أكبر من 2 ميجا - اختار ملف أصغر'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';

        if (!isset($this->allowedTypes[$extension])) {
            return ['success' => false, 'error' => 'نوع الملف غير مدعوم - استخدم JPG أو PNG أو WEBP أو SVG'];
        }

        // SVG مش بيتفحص بـ getimagesize (مش صورة raster) - فحص بسيط بديل بيتأكد إنه فعلاً كود SVG
        if ($extension === 'svg') {
            $content = @file_get_contents($file['tmp_name']);
            if ($content === false || stripos($content, '<svg') === false) {
                return ['success' => false, 'error' => 'الملف ده مش SVG صالح'];
            }
        } else {
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false || !in_array($mimeType, $this->allowedTypes, true)) {
                return ['success' => false, 'error' => 'الملف ده مش صورة صالحة'];
            }
        }

        $publicDir = ROOT_PATH . '/public_html/uploads/branding';
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0755, true);
        }
        if (!is_dir($publicDir) || !is_writable($publicDir)) {
            return ['success' => false, 'error' => 'تعذر الوصول لمجلد رفع الملفات على السيرفر (صلاحيات الكتابة)'];
        }

        $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $fullPath = $publicDir . '/' . $filename;

        if ($extension === 'svg') {
            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                return ['success' => false, 'error' => 'تعذر حفظ اللوجو على السيرفر'];
            }
        } else {
            $imageInfo = @getimagesize($file['tmp_name']);
            if (!$this->saveResized($file['tmp_name'], $fullPath, $extension, $imageInfo)) {
                if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                    return ['success' => false, 'error' => 'تعذر حفظ اللوجو على السيرفر'];
                }
            }
        }

        if ($oldLogoUrl) {
            $oldPath = ROOT_PATH . '/public_html' . parse_url($oldLogoUrl, PHP_URL_PATH);
            if (strpos($oldPath, $publicDir) === 0 && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return ['success' => true, 'url' => '/uploads/branding/' . $filename];
    }

    private function saveResized(string $tmpPath, string $destPath, string $extension, array $imageInfo): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        [$origWidth, $origHeight, $type] = $imageInfo;

        switch ($type) {
            case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($tmpPath);
                break;
            case IMAGETYPE_PNG: $source = @imagecreatefrompng($tmpPath);
                break;
            case IMAGETYPE_WEBP: $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false;
                break;
            default: $source = false;
        }

        if (!$source) {
            return false;
        }

        $ratio = min(1, $this->maxDimension / max($origWidth, $origHeight));
        $newWidth = max(1, (int) round($origWidth * $ratio));
        $newHeight = max(1, (int) round($origHeight * $ratio));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        $saved = false;
        switch ($type) {
            case IMAGETYPE_JPEG: $saved = imagejpeg($canvas, $destPath, 90);
                break;
            case IMAGETYPE_PNG: $saved = imagepng($canvas, $destPath, 8);
                break;
            case IMAGETYPE_WEBP: $saved = function_exists('imagewebp') ? imagewebp($canvas, $destPath, 90) : false;
                break;
        }

        imagedestroy($canvas);
        imagedestroy($source);

        return $saved;
    }
}
