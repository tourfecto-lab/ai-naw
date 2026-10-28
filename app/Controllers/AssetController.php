<?php

/**
 * Tourfecto - Asset Controller
 * خدمة الملفات الثابتة (عادة يخدمها Apache مباشرة، وهذا مسار احتياطي فقط)
 * @version 1.0.0
 */

class AssetController extends Controller
{
    private const MIME_TYPES = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    /** GET /assets/{path} */
    public function serve(array $params): array
    {
        $relative = $params['path'] ?? '';
        return $this->serveFile(dirname(__DIR__, 2) . '/public_html/assets/' . $relative);
    }

    /** GET /favicon.ico */
    public function favicon(array $params = []): array
    {
        // لو فيه أيقونة مرفوعة من لوحة الأدمن، نحوّل ليها بدل الملف الثابت
        // (بعض المتصفحات/الزواحف بتطلب /favicon.ico مباشرة وبتتجاهل وسم
        // <link rel="icon"> جوه الصفحة).
        if (class_exists('SystemSettingsService')) {
            try {
                $settings = new SystemSettingsService();
                $faviconUrl = $settings->get('site_favicon_url', '');
                if ($faviconUrl) {
                    header('Location: ' . $faviconUrl, true, 302);
                    exit;
                }
            } catch (Exception $e) {
                // تجاهل - نكمل للملف الثابت الافتراضي
            }
        }
        return $this->serveFile(dirname(__DIR__, 2) . '/public_html/favicon.ico');
    }

    /** GET /robots.txt */
    public function robots(array $params = []): array
    {
        return $this->serveFile(dirname(__DIR__, 2) . '/public_html/robots.txt', 'text/plain');
    }

    /** GET /sitemap.xml */
    public function sitemap(array $params = []): array
    {
        return $this->serveFile(dirname(__DIR__, 2) . '/public_html/sitemap.xml', 'application/xml');
    }

    /** GET /.well-known/{path} */
    public function wellKnown(array $params): array
    {
        $relative = $params['path'] ?? '';
        return $this->serveFile(dirname(__DIR__, 2) . '/public_html/.well-known/' . $relative);
    }

    /**
     * يخدم الملف مباشرة إن وُجد، أو يرجع 404
     */
    private function serveFile(string $path, ?string $forceMime = null): array
    {
        $realBase = realpath(dirname(__DIR__, 2) . '/public_html');
        $realPath = realpath($path);

        // منع الخروج خارج مجلد public_html (Path Traversal)
        if (!$realPath || !$realBase || strpos($realPath, $realBase) !== 0 || !is_file($realPath)) {
            return $this->error('الملف غير موجود', 404);
        }

        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mime = $forceMime ?? (self::MIME_TYPES[$ext] ?? 'application/octet-stream');

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: public, max-age=86400');
        readfile($realPath);
        exit;
    }
}
