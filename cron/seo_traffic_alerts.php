<?php
/**
 * Tourfecto - تنبيه وقوع زيارات جوجل (يومي)
 * بيقارن آخر 7 أيام بالـ 7 اللي قبلها من Search Console لكل موقع مربوط، ويبعت تنبيه لو وقعت 30%+.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/Seo/SeoTrafficAlertService.php';

try {
    echo json_encode((new SeoTrafficAlertService())->run()) . "\n";
} catch (Throwable $e) {
    Logger::error('SEO traffic alerts cron failed', ['error' => $e->getMessage()]);
    echo 'error: ' . $e->getMessage() . "\n";
    exit(1);
}
