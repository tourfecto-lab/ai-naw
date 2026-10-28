<?php
/**
 * Tourfecto - Avatar Upload Handler
 * رفع الصورة الشخصية للمستخدم.
 * @version 1.0.0
 *
 * ملاحظة مهمة: UploadManager الموجود أصلاً بيخزّن في TOURFECTO_STORAGE
 * (= .../storage خارج public_html تمامًا)، وبيرجّع رابط عام
 * APP_URL/storage/uploads/... اللي مش هيشتغل أبدًا لأن storage/ مش جوه
 * الـ web root (مفيش أي طريقة يوصله متصفح مباشرة). عشان كده الكلاس ده
 * منفصل ومتعمد يخزّن جوه public_html/uploads/avatars عشان الرابط يشتغل
 * فعليًا، بدل ما نصلّح UploadManager العام ونخاطر بكسر استخدامات تانية
 * ليه (تقارير/مستندات) مش جزء من المهمة دي.
 */
class AvatarUploadHandler {
    private array $allowedTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    private int $maxSize = 3 * 1024 * 1024; // 3MB كفاية جدًا لصورة شخصية
    private int $maxDimension = 800; // بيتصغّر لو أكبر من كده

    /**
     * @param array $file عنصر من $_FILES (مثال: $_FILES['avatar'])
     * @param int $userId
     * @param string|null $oldAvatarUrl الرابط القديم (لو موجود) عشان نحذف الملف القديم بعد نجاح الرفع
     * @return array ['success'=>bool, 'url'=>?string, 'error'=>?string]
     */
    public function upload(array $file, int $userId, ?string $oldAvatarUrl = null): array {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'الصورة أكبر من الحد المسموح به في إعدادات السيرفر',
                UPLOAD_ERR_FORM_SIZE => 'الصورة أكبر من الحد المسموح به',
                UPLOAD_ERR_PARTIAL => 'الرفع اتقطع في النص، جرّب تاني',
                UPLOAD_ERR_NO_FILE => 'لم يتم اختيار أي صورة',
            ];
            return ['success' => false, 'error' => $messages[$file['error'] ?? -1] ?? 'تعذر رفع الصورة'];
        }

        if ($file['size'] > $this->maxSize) {
            return ['success' => false, 'error' => 'الصورة أكبر من 3 ميجا - اختار صورة أصغر'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';

        if (!isset($this->allowedTypes[$extension]) || !in_array($mimeType, $this->allowedTypes, true)) {
            return ['success' => false, 'error' => 'نوع الصورة غير مدعوم - استخدم JPG أو PNG أو WEBP'];
        }

        // تأكد إنها صورة فعلاً مش ملف باسم مزيّف
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['success' => false, 'error' => 'الملف ده مش صورة صالحة'];
        }

        // ROOT_PATH متاح دايمًا (معرّف في index.php)، أضمن مصدر لمسار public_html
        $publicDir = ROOT_PATH . '/public_html/uploads/avatars';

        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0755, true);
        }
        if (!is_dir($publicDir) || !is_writable($publicDir)) {
            return ['success' => false, 'error' => 'تعذر الوصول لمجلد رفع الصور على السيرفر (صلاحيات الكتابة)'];
        }

        $filename = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $fullPath = $publicDir . '/' . $filename;

        if (!$this->saveResized($file['tmp_name'], $fullPath, $extension, $imageInfo)) {
            // فشل التصغير لأي سبب (مفيش GD مثلاً) - انسخ الملف الأصلي زي ما هو بدل ما نفشل بالكامل
            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                return ['success' => false, 'error' => 'تعذر حفظ الصورة على السيرفر'];
            }
        }

        // احذف الصورة القديمة (لو موجودة ومحلية) عشان مانكدّسش ملفات قديمة
        if ($oldAvatarUrl) {
            $oldPath = ROOT_PATH . '/public_html' . parse_url($oldAvatarUrl, PHP_URL_PATH);
            if (strpos($oldPath, $publicDir) === 0 && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return ['success' => true, 'url' => '/uploads/avatars/' . $filename];
    }

    private function saveResized(string $tmpPath, string $destPath, string $extension, array $imageInfo): bool {
        if (!extension_loaded('gd')) {
            return false;
        }

        [$origWidth, $origHeight, $type] = $imageInfo;

        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($tmpPath);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($tmpPath);
                break;
            case IMAGETYPE_WEBP:
                $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false;
                break;
            default:
                $source = false;
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
            case IMAGETYPE_JPEG:
                $saved = imagejpeg($canvas, $destPath, 88);
                break;
            case IMAGETYPE_PNG:
                $saved = imagepng($canvas, $destPath, 8);
                break;
            case IMAGETYPE_WEBP:
                $saved = function_exists('imagewebp') ? imagewebp($canvas, $destPath, 88) : false;
                break;
        }

        imagedestroy($canvas);
        imagedestroy($source);

        return $saved;
    }
}
