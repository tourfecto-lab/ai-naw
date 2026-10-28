<?php

/**
 * Tourfecto - Admin Middleware
 * التحقق من أن المستخدم الحالي أدمن قبل السماح بالوصول لمسارات الإدارة
 * @version 1.0.0
 *
 * ملاحظة: هذا الملف كان مفقودًا بالكامل رغم أنه مُستخدم في app/routes/web.php
 * و app/routes/api.php لحماية كل مسارات /admin و /api/admin، ما كان يعني عمليًا
 * أن مسارات الأدمن لا تُحمى بأي تحقق فعلي (Router يتجاهل الميدل وير غير الموجود بصمت).
 */

class AdminMiddleware
{
    /**
     * @var Database $db
     */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * معالجة الطلب: يجب أن يكون هناك مستخدم مصادَق عليه (عادة بعد AuthMiddleware)
     * وأن يكون دوره admin أو super_admin
     * @return array|null
     */
    public function handle(): ?array
    {
        $user = $_SESSION['user'] ?? null;

        // دعم حالة التوكن المباشر بدون AuthMiddleware قبله
        if (!$user && isset($_SERVER['auth_user'])) {
            $user = $_SERVER['auth_user'];
        }

        if (!$user) {
            if ($this->isWebPageRequest()) {
                header('Location: /login');
                exit;
            }
            http_response_code(401);
            return [
                'success' => false,
                'error' => 'مطلوب تسجيل الدخول',
                'code' => 401
            ];
        }

        $role = $user['role'] ?? 'user';

        if (!in_array($role, ['admin', 'super_admin'], true)) {
            if ($this->isWebPageRequest()) {
                header('Content-Type: text/html; charset=utf-8');
                http_response_code(403);
                echo $this->renderForbiddenPage();
                exit;
            }
            http_response_code(403);
            return [
                'success' => false,
                'error' => 'هذا المسار مخصص للمدراء فقط',
                'code' => 403
            ];
        }

        // صلاحيات الأدمن الدقيقة (AdminAccess): owner/admin/finance/support/analyst + سجل تدقيق تلقائي
        if (class_exists('AdminAccess')) {
            return AdminAccess::enforceRequest($user);
        }

        return null;
    }

    /**
     * هل الطلب الحالي صفحة ويب عادية (مش نداء API/AJAX)؟
     * @return bool
     */
    private function isWebPageRequest(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (strpos($path, '/api/') === 0) {
            return false;
        }

        $headers = getallheaders() ?: [];
        $acceptHeader = $headers['Accept'] ?? ($headers['accept'] ?? '');

        return stripos($acceptHeader, 'application/json') === false;
    }

    /**
     * صفحة HTML بسيطة لرفض الوصول (403)
     * @return string
     */
    private function renderForbiddenPage(): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>غير مصرح | {$appName}</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="container" style="text-align:center; padding: 80px 0;">
        <h1>🚫 غير مصرح لك بالدخول هنا</h1>
        <p class="text-muted">هذه الصفحة مخصصة للمدراء فقط.</p>
        <a href="/dashboard" class="btn btn-primary">العودة للوحة التحكم</a>
    </div>
</body>
</html>
HTML;
    }
}
