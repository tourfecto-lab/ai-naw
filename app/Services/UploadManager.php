<?php

/**
 * Tourfecto - Upload Manager
 * نظام إدارة الملفات المرفوعة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class UploadManager
{
    /**
     * @var string $uploadPath - مسار مجلد المرفوعات
     */
    private $uploadPath;

    /**
     * @var array $allowedTypes - أنواع الملفات المسموحة
     */
    private $allowedTypes = [];

    /**
     * @var int $maxFileSize - الحد الأقصى لحجم الملف
     */
    private $maxFileSize = 20 * 1024 * 1024; // 20MB

    /**
     * @var array $imageTypes - أنواع الصور المسموحة
     */
    private $imageTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];

    /**
     * @var array $documentTypes - أنواع المستندات المسموحة
     */
    private $documentTypes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'txt' => 'text/plain'
    ];

    /**
     * @var array $errorMessages - رسائل الأخطاء
     */
    private $errorMessages = [
        UPLOAD_ERR_OK => 'تم الرفع بنجاح',
        UPLOAD_ERR_INI_SIZE => 'الملف أكبر من الحد الأقصى المسموح به في الإعدادات',
        UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد الأقصى المسموح به في النموذج',
        UPLOAD_ERR_PARTIAL => 'تم رفع الملف بشكل جزئي',
        UPLOAD_ERR_NO_FILE => 'لم يتم رفع أي ملف',
        UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود',
        UPLOAD_ERR_CANT_WRITE => 'فشل في كتابة الملف على القرص',
        UPLOAD_ERR_EXTENSION => 'تم إيقاف رفع الملف بواسطة امتداد PHP'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->uploadPath = TOURFECTO_STORAGE . '/uploads/';
        $this->ensureUploadDirectories();
        $this->allowedTypes = array_merge($this->imageTypes, $this->documentTypes);
    }

    /**
     * التأكد من وجود مجلدات المرفوعات
     */
    private function ensureUploadDirectories(): void
    {
        $directories = ['avatars', 'reports', 'temp', 'documents/contracts', 'documents/invoices'];
        foreach ($directories as $dir) {
            $path = $this->uploadPath . $dir . '/';
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * رفع ملف
     * @param array $file - بيانات الملف من $_FILES
     * @param string $type - نوع الملف (avatar, report, document, temp)
     * @param array $options - خيارات إضافية
     * @return array
     */
    public function upload(array $file, string $type = 'temp', array $options = []): array
    {
        // التحقق من وجود ملف
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            return [
                'success' => false,
                'error' => 'لم يتم رفع أي ملف'
            ];
        }

        // التحقق من وجود أخطاء
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'error' => $this->errorMessages[$file['error']] ?? 'خطأ غير معروف'
            ];
        }

        // التحقق من حجم الملف
        if (!is_file($file['tmp_name']) || filesize($file['tmp_name']) > $this->maxFileSize) {
            return [
                'success' => false,
                'error' => 'الملف كبير جداً. الحد الأقصى ' . $this->formatSize($this->maxFileSize)
            ];
        }

        // التحقق من نوع الملف
        $fileInfo = $this->getFileInfo($file);
        if (!$this->isAllowedType($fileInfo['extension'])) {
            return [
                'success' => false,
                'error' => 'نوع الملف غير مسموح به. الأنواع المسموحة: ' . implode(', ', array_keys($this->allowedTypes))
            ];
        }

        if (!$this->contentMatchesExtension($fileInfo, (string) $file['tmp_name'])) {
            return ['success' => false, 'error' => 'محتوى الملف لا يطابق نوعه أو الصورة أكبر من الحد المسموح'];
        }

        // إنشاء اسم ملف فريد
        $filename = $this->generateFilename($fileInfo, $options);

        // تحديد مسار التخزين
        try {
            $storagePath = $this->getStoragePath($type, $options);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => 'مسار تخزين غير صالح'];
        }
        $fullPath = $storagePath . $filename;

        // نقل الملف
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return [
                'success' => false,
                'error' => 'فشل في نقل الملف إلى المجلد النهائي'
            ];
        }

        // معالجة الصور (إنشاء صور مصغرة)
        if ($this->isImage($fileInfo['extension'])) {
            $this->createThumbnails($fullPath, $storagePath, $filename, $fileInfo['extension']);
        }

        return [
            'success' => true,
            'filename' => $filename,
            'path' => $fullPath,
            'url' => $this->getPublicUrl($type, $filename, $options),
            'size' => $file['size'],
            'size_human' => $this->formatSize($file['size']),
            'extension' => $fileInfo['extension'],
            'mime_type' => $fileInfo['mime_type'],
            'type' => $type
        ];
    }

    /**
     * رفع صورة بروفايل المستخدم
     * @param int $userId - معرف المستخدم
     * @param array $file - بيانات الملف
     * @return array
     */
    public function uploadAvatar(int $userId, array $file): array
    {
        $options = [
            'user_id' => $userId,
            'max_width' => 500,
            'max_height' => 500,
            'quality' => 90
        ];

        $result = $this->upload($file, 'avatars', $options);

        if ($result['success']) {
            // تحديث مسار الصورة في قاعدة البيانات
            $this->updateUserAvatar($userId, $result['filename']);
        }

        return $result;
    }

    /**
     * تصدير تقرير
     * @param array $data - بيانات التقرير
     * @param string $format - صيغة التصدير (pdf, csv, json)
     * @param string $type - نوع التقرير
     * @return array
     */
    public function exportReport(array $data, string $format, string $type = 'seo'): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $type) || !in_array($format, ['pdf', 'csv', 'json', 'html'], true)) {
            return ['success' => false, 'error' => 'نوع تقرير أو صيغة غير مدعومة'];
        }
        $filename = $this->generateReportFilename($type, $format);
        try {
            $storagePath = $this->getStoragePath('reports/' . $type);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => 'تعذر الوصول إلى مجلد التقارير'];
        }
        $fullPath = $storagePath . $filename;

        $result = $this->exportData($data, $format, $fullPath);

        if ($result['success']) {
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $fullPath,
                'url' => $this->getPublicUrl('reports/' . $type, $filename),
                'format' => $format
            ];
        }

        return $result;
    }

    /**
     * تصدير البيانات
     * @param array $data
     * @param string $format
     * @param string $path
     * @return array
     */
    private function exportData(array $data, string $format, string $path): array
    {
        switch ($format) {
            case 'html':
                return ['success' => file_put_contents($path, $this->renderPDFHTML($data), LOCK_EX) !== false];
            case 'pdf':
                return $this->exportPDF($data, $path);
            case 'csv':
                return $this->exportCSV($data, $path);
            case 'json':
                return $this->exportJSON($data, $path);
            default:
                return [
                    'success' => false,
                    'error' => 'صيغة غير مدعومة'
                ];
        }
    }

    /**
     * تصدير PDF
     * @param array $data
     * @param string $path
     * @return array
     */
    private function exportPDF(array $data, string $path): array
    {
        if (!class_exists('Dompdf\\Dompdf')) {
            return ['success' => false, 'error' => 'تصدير PDF يحتاج مكتبة Dompdf. استخدم HTML للطباعة وحفظ PDF من المتصفح، أو CSV/JSON.'];
        }
        try {
            $pdf = new \Dompdf\Dompdf();
            $pdf->loadHtml($this->renderPDFHTML($data), 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            return ['success' => file_put_contents($path, $pdf->output(), LOCK_EX) !== false];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر إنشاء ملف PDF'];
        }
    }

    /**
     * بناء HTML جاهز للطباعة/التحويل لـ PDF من صفوف التقرير
     * (نفس شكل بيانات exportCSV: مصفوفة صفوف، كل صف مصفوفة key => value).
     */
    private function renderPDFHTML(array $data): string
    {
        $esc = static fn ($v): string => htmlspecialchars(
            is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );

        $rows = '';
        if (!empty($data) && is_array(reset($data))) {
            $rows .= '<tr>' . implode('', array_map(fn ($h) => '<th>' . $esc($h) . '</th>', array_keys(reset($data)))) . '</tr>';
            foreach ($data as $row) {
                $rows .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . $esc($c) . '</td>', (array) $row)) . '</tr>';
            }
        } else {
            foreach ($data as $key => $value) {
                $rows .= '<tr><th>' . $esc($key) . '</th><td>' . $esc($value) . '</td></tr>';
            }
        }

        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>Tourfecto Report</title>'
            . '<style>body{font-family:sans-serif;margin:24px}table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #ccc;padding:6px 8px;text-align:start;font-size:12px}th{background:#f3f4f6}</style>'
            . '</head><body><h1>Tourfecto Report</h1><p>' . $esc(date('Y-m-d H:i')) . '</p>'
            . '<table>' . $rows . '</table></body></html>';
    }

    /**
     * تصدير CSV
     * @param array $data
     * @param string $path
     * @return array
     */
    private function exportCSV(array $data, string $path): array
    {
        $handle = @fopen($path, 'w');
        if (!$handle) {
            return ['success' => false, 'error' => 'تعذر كتابة التقرير'];
        }
        $safe = static function ($value): string {
            $value = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            return preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) ? "'" . $value : $value;
        };

        // إضافة BOM لـ UTF-8
        $written = fputs($handle, "\xEF\xBB\xBF") !== false;

        // كتابة الرؤوس
        if (!empty($data)) {
            $written = fputcsv($handle, array_map($safe, array_keys((array) reset($data)))) !== false && $written;

            // كتابة البيانات
            foreach ($data as $row) {
                $written = fputcsv($handle, array_map($safe, (array) $row)) !== false && $written;
            }
        }

        $closed = fclose($handle);
        return ['success' => $written && $closed];
    }

    /**
     * تصدير JSON
     * @param array $data
     * @param string $path
     * @return array
     */
    private function exportJSON(array $data, string $path): array
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return ['success' => $json !== false && file_put_contents($path, $json, LOCK_EX) !== false];
    }

    /**
     * حذف ملف
     * @param string $filename - اسم الملف
     * @param string $type - نوع الملف
     * @return bool
     */
    public function delete(string $filename, string $type): bool
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '\\')) {
            return false;
        }
        try {
            $path = $this->getStoragePath($type) . $filename;
        } catch (InvalidArgumentException $e) {
            return false;
        }
        if (is_link($path)) {
            return false;
        }

        if (file_exists($path)) {
            // حذف الصور المصغرة إن وجدت
            $this->deleteThumbnails($path);
            return unlink($path);
        }

        return false;
    }

    /**
     * الحصول على معلومات الملف
     * @param array $file
     * @return array
     */
    private function getFileInfo(array $file): array
    {
        $pathInfo = pathinfo($file['name']);
        $extension = strtolower($pathInfo['extension'] ?? '');
        // mime_content_type محتاجة إضافة fileinfo، ومش دايمًا متفعّلة على
        // الاستضافات الجديدة - من غيرها كان أي رفع ملف بيوقع بـ Fatal Error.
        if (function_exists('mime_content_type')) {
            $mimeType = (string) @mime_content_type($file['tmp_name']);
        } else {
            $img = @getimagesize($file['tmp_name']);
            $mimeType = is_array($img) && !empty($img['mime']) ? (string) $img['mime'] : 'application/octet-stream';
        }

        return [
            'name' => $file['name'],
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $file['size']
        ];
    }

    /**
     * التحقق من نوع الملف المسموح
     * @param string $extension
     * @return bool
     */
    private function contentMatchesExtension(array $info, string $path): bool
    {
        $extension = $info['extension'];
        if ($this->isImage($extension)) {
            $image = @getimagesize($path);
            return is_array($image)
                && ($image['mime'] ?? '') === $this->imageTypes[$extension]
                && $image[0] > 0 && $image[1] > 0
                && $image[0] * $image[1] <= 25000000;
        }
        $allowed = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'txt' => ['text/plain'],
            'json' => ['application/json', 'text/plain'],
        ];
        if (!in_array($info['mime_type'], $allowed[$extension] ?? [], true)) {
            return false;
        }
        if ($extension === 'json') {
            json_decode((string) file_get_contents($path));
            return json_last_error() === JSON_ERROR_NONE;
        }
        return true;
    }

    private function isAllowedType(string $extension): bool
    {
        return isset($this->allowedTypes[$extension]);
    }

    /**
     * التحقق من كون الملف صورة
     * @param string $extension
     * @return bool
     */
    private function isImage(string $extension): bool
    {
        return isset($this->imageTypes[$extension]);
    }

    /**
     * توليد اسم ملف فريد
     * @param array $fileInfo
     * @param array $options
     * @return string
     */
    private function generateFilename(array $fileInfo, array $options = []): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $random = substr(md5(uniqid()), 0, 8);

        if (isset($options['user_id'])) {
            return "avatar_{$timestamp}_{$random}.{$fileInfo['extension']}";
        }

        return "file_{$timestamp}_{$random}.{$fileInfo['extension']}";
    }

    /**
     * توليد اسم ملف التقرير
     * @param string $type
     * @param string $format
     * @return string
     */
    private function generateReportFilename(string $type, string $format): string
    {
        $date = date('Y-m-d_H-i-s');
        return "{$type}_report_{$date}.{$format}";
    }

    /**
     * الحصول على مسار التخزين
     * @param string $type
     * @param array $options
     * @return string
     */
    private function getStoragePath(string $type, array $options = []): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*$/D', $type)) {
            throw new InvalidArgumentException('Invalid storage path');
        }
        $path = $this->uploadPath . $type . '/';
        if ($type === 'avatars' && isset($options['user_id'])) {
            $userId = filter_var($options['user_id'], FILTER_VALIDATE_INT);
            if ($userId === false || $userId <= 0) {
                throw new InvalidArgumentException('Invalid user ID');
            }
            $path .= 'user_' . $userId . '/';
        }
        // Reject symlinks at every component before creating/writing the final directory.
        $current = rtrim($this->uploadPath, '/');
        foreach (explode('/', trim(substr($path, strlen($this->uploadPath)), '/')) as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new InvalidArgumentException('Symlink storage paths are not allowed');
            }
        }
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new InvalidArgumentException('Storage directory unavailable');
        }
        return $path;
    }

    /**
     * الحصول على الرابط العام للملف
     * @param string $type
     * @param string $filename
     * @return string
     */
    private function getPublicUrl(string $type, string $filename, array $options = []): string
    {
        if ($type === 'avatars' && isset($options['user_id'])) {
            $type .= '/user_' . (int) $options['user_id'];
        }
        return APP_URL . '/storage/uploads/' . $type . '/' . $filename;
    }

    /**
     * إنشاء صور مصغرة
     * @param string $fullPath
     * @param string $storagePath
     * @param string $filename
     * @param string $extension
     */
    private function createThumbnails(string $fullPath, string $storagePath, string $filename, string $extension): void
    {
        // التحقق من وجود مكتبة GD
        if (!extension_loaded('gd')) {
            return;
        }

        $sizes = [
            'thumb' => ['width' => 150, 'height' => 150],
            'small' => ['width' => 300, 'height' => 300],
            'medium' => ['width' => 600, 'height' => 600]
        ];

        foreach ($sizes as $prefix => $size) {
            $thumbFilename = str_replace('.' . $extension, "_{$prefix}." . $extension, $filename);
            $thumbPath = $storagePath . $thumbFilename;

            $this->resizeImage($fullPath, $thumbPath, $size['width'], $size['height']);
        }
    }

    /**
     * تغيير حجم الصورة
     * @param string $source
     * @param string $destination
     * @param int $width
     * @param int $height
     */
    private function resizeImage(string $source, string $destination, int $width, int $height): void
    {
        list($origWidth, $origHeight, $type) = getimagesize($source);

        // حساب النسبة
        $ratio = min($width / $origWidth, $height / $origHeight);
        $newWidth = $origWidth * $ratio;
        $newHeight = $origHeight * $ratio;

        // إنشاء الصورة الجديدة
        $image = imagecreatetruecolor($newWidth, $newHeight);

        // تحميل الصورة الأصلية حسب النوع
        switch ($type) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($source);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($source);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($source);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = imagecreatefromwebp($source);
                break;
            default:
                return;
        }

        // تغيير الحجم
        imagecopyresampled($image, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // حفظ الصورة
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($image, $destination, 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($image, $destination, 9);
                break;
            case IMAGETYPE_GIF:
                imagegif($image, $destination);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image, $destination, 90);
                break;
        }

        imagedestroy($image);
        imagedestroy($sourceImage);
    }

    /**
     * حذف الصور المصغرة
     * @param string $path
     */
    private function deleteThumbnails(string $path): void
    {
        $info = pathinfo($path);
        $patterns = ['_thumb.', '_small.', '_medium.'];

        foreach ($patterns as $pattern) {
            $thumbPath = $info['dirname'] . '/' . str_replace('.' . $info['extension'], $pattern . $info['extension'], $info['basename']);
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
        }
    }

    /**
     * تحديث صورة المستخدم في قاعدة البيانات
     * @param int $userId
     * @param string $filename
     */
    private function updateUserAvatar(int $userId, string $filename): void
    {
        try {
            $db = Database::getInstance();
            $sql = "UPDATE users SET avatar_url = :avatar WHERE id = :user_id";
            $db->query($sql, [
                ':avatar' => $filename,
                ':user_id' => $userId
            ]);
        } catch (Exception $e) {
            Logger::error('Update user avatar failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تنسيق الحجم
     * @param int $bytes
     * @return string
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * تنظيف الملفات المؤقتة
     * @param int $age - عمر الملفات بالثواني
     * @return int
     */
    public function cleanTempFiles(int $age = 3600): int
    {
        $count = 0;
        $tempPath = $this->uploadPath . 'temp/';

        if (is_dir($tempPath)) {
            $files = glob($tempPath . '*');
            foreach ($files as $file) {
                if (filemtime($file) < time() - $age) {
                    if (unlink($file)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * الحصول على إحصائيات المرفوعات
     * @return array
     */
    public function getStats(): array
    {
        $stats = [];
        $directories = ['avatars', 'reports', 'temp', 'documents'];

        foreach ($directories as $dir) {
            $path = $this->uploadPath . $dir . '/';
            if (is_dir($path)) {
                $files = $this->getAllFiles($path);
                $stats[$dir] = [
                    'count' => count($files),
                    'size' => array_sum(array_map('filesize', $files)),
                    'size_human' => $this->formatSize(array_sum(array_map('filesize', $files))),
                    'files' => $files
                ];
            }
        }

        return $stats;
    }

    /**
     * الحصول على جميع الملفات في مجلد
     * @param string $path
     * @return array
     */
    private function getAllFiles(string $path): array
    {
        $files = [];
        $items = glob($path . '*');

        foreach ($items as $item) {
            if (is_file($item)) {
                $files[] = $item;
            } elseif (is_dir($item)) {
                $files = array_merge($files, $this->getAllFiles($item . '/'));
            }
        }

        return $files;
    }
}
