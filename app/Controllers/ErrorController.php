<?php

/**
 * Tourfecto - Error Controller
 * صفحات الأخطاء الموحّدة (404 / 403 / 500)
 * @version 1.0.0
 */

class ErrorController extends Controller
{
    /** /404 */
    public function notFound(array $params = []): array
    {
        return $this->error('الصفحة المطلوبة غير موجودة', 404);
    }

    /** /403 */
    public function forbidden(array $params = []): array
    {
        return $this->error('ممنوع الوصول لهذا المورد', 403);
    }

    /** /500 */
    public function serverError(array $params = []): array
    {
        return $this->error('حدث خطأ غير متوقع في الخادم', 500);
    }
}
