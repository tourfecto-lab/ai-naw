<?php

/**
 * Tourfecto - GBP Media Upload Handler
 * نفس نمط AvatarUploadHandler.php بالظبط (رفع لـ public_html/uploads عشان
 * الرابط يشتغل فعليًا)، لكن هنا لازم نرجّع رابط https مطلق كامل (مش نسبي)
 * لأن Media API بتاعة Google لازم تقدر تجيب الصورة بنفسها عن طريق sourceUrl.
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpMediaUploadHandler
{
    private array $allowedTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    private int $maxSize = 5 * 1024 * 1024; // 5MB

    /**
     * @param array $file عنصر من $_FILES
     * @param int $userId
     * @return array ['success'=>bool, 'public_url'=>?string, 'error'=>?string]
     */
    public function upload(array $file, int $userId): array
    {
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
            return ['success' => false, 'error' => 'الصورة أكبر من 5 ميجا - اختار صورة أصغر'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';

        if (!isset($this->allowedTypes[$extension]) || !in_array($mimeType, $this->allowedTypes, true)) {
            return ['success' => false, 'error' => 'نوع الصورة غير مدعوم - استخدم JPG أو PNG أو WEBP'];
        }

        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['success' => false, 'error' => 'الملف ده مش صورة صالحة'];
        }
        // Google بيرفض صور أصغر من 250x250 فعليًا - نتأكد قبل ما نرفع لتخزيننا حتى
        if (($imageInfo[0] ?? 0) < 250 || ($imageInfo[1] ?? 0) < 250) {
            return ['success' => false, 'error' => 'أبعاد الصورة صغيرة جدًا - الحد الأدنى المطلوب من Google هو 250×250 بكسل'];
        }

        $publicDir = ROOT_PATH . '/public_html/uploads/gbp_photos';
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0755, true);
        }
        if (!is_dir($publicDir) || !is_writable($publicDir)) {
            return ['success' => false, 'error' => 'تعذر الوصول لمجلد رفع الصور على السيرفر (صلاحيات الكتابة)'];
        }
        $this->ensureHtaccessProtection($publicDir);

        $filename = 'gbp_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $fullPath = $publicDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return ['success' => false, 'error' => 'تعذر حفظ الصورة على السيرفر'];
        }

        $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';

        return ['success' => true, 'public_url' => $baseUrl . '/uploads/gbp_photos/' . $filename];
    }

    /**
     * صورة من مكتبة الاستوديو الإبداعي (المتولدة أو المرفوعة) → نسخة JPEG عامة لـ Google.
     * Google بيحتاج sourceUrl عام، وملفات الاستوديو خاصة؛ فبننسخ الصورة بس (مش الأصل) بعد التحقق من الملكية.
     * @return array ['success'=>bool, 'public_url'=>?string, 'error'=>?string]
     */
    public function uploadFromLibrary(int $mediaId, int $userId): array
    {
        try {
            $row = PrivateMedia::owned($userId, $mediaId);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'الصورة مش موجودة في مكتبتك'];
        }
        if ($row['status'] !== 'completed' || $row['type'] === 'short_video') {
            return ['success' => false, 'error' => 'اختار صورة جاهزة (مش فيديو)'];
        }
        $path = (string) $row['file_path'];
        $full = PrivateMedia::socialFile($path) ?? (PrivateMedia::valid($path) ? PrivateMedia::resolve($path) : null);
        $bytes = $full ? @file_get_contents($full) : false;
        $im = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if (!$im) {
            return ['success' => false, 'error' => 'تعذر قراءة الصورة'];
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 250 || $h < 250) {
            imagedestroy($im);
            return ['success' => false, 'error' => 'أبعاد الصورة صغيرة جدًا - الحد الأدنى المطلوب من Google هو 250×250 بكسل'];
        }
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $jpeg = '';
        foreach ([90, 82, 72] as $q) {
            ob_start();
            imagejpeg($flat, null, $q);
            $jpeg = (string) ob_get_clean();
            if (strlen($jpeg) <= $this->maxSize) {
                break;
            }
        }
        imagedestroy($flat);
        if ($jpeg === '' || strlen($jpeg) > $this->maxSize) {
            return ['success' => false, 'error' => 'الصورة أكبر من 5 ميجا'];
        }
        $publicDir = ROOT_PATH . '/public_html/uploads/gbp_photos';
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0755, true);
        }
        if (!is_dir($publicDir) || !is_writable($publicDir)) {
            return ['success' => false, 'error' => 'تعذر الوصول لمجلد رفع الصور على السيرفر (صلاحيات الكتابة)'];
        }
        $this->ensureHtaccessProtection($publicDir);
        $filename = 'gbp_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (file_put_contents($publicDir . '/' . $filename, $jpeg, LOCK_EX) !== strlen($jpeg)) {
            return ['success' => false, 'error' => 'تعذر حفظ الصورة على السيرفر'];
        }
        $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return ['success' => true, 'public_url' => $baseUrl . '/uploads/gbp_photos/' . $filename];
    }

    /**
     * Round 7 (2026-08-14 - Production Finalization / Security Audit):
     * لقينا إن public_html/uploads/gbp_photos/ (ومجلد public_html/uploads/
     * كله فعليًا) معندوش .htaccess بيمنع تنفيذ PHP، على عكس
     * public_html/storage/uploads/.htaccess اللي فيه الحماية دي بالفعل.
     * ده Defense-in-Depth إضافي فوق الـ whitelist الصارم للامتداد اللي
     * أصلاً بيمنع حفظ أي ملف بامتداد غير jpg/jpeg/png/webp - حتى لو حصل
     * أي ثغرة تانية تسمح برفع اسم ملف مختلف، السيرفر مش هينفذه كـ PHP.
     * بنحصر الإصلاح في مجلد الموديول ده بس (gbp_photos) - مش بنلمس بقية
     * public_html/uploads/ (خارج نطاق موديول GBP).
     */
    private function ensureHtaccessProtection(string $publicDir): void
    {
        $htaccessPath = $publicDir . '/.htaccess';
        if (file_exists($htaccessPath)) {
            return;
        }

        $content = <<<'HTACCESS'
# Tourfecto GBP Photos - حماية من تنفيذ الملفات + منع عرض محتويات المجلد
<FilesMatch "\.(php|php5|php7|phtml|pl|py|jsp|asp|cgi|sh)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
Options -Indexes
<FilesMatch "\.(jpg|jpeg|png|webp)$">
    Order Allow,Deny
    Allow from all
</FilesMatch>
HTACCESS;

        @file_put_contents($htaccessPath, $content);
    }
}
