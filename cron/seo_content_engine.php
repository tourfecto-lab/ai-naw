<?php

/**
 * Tourfecto - SEO Content Engine Cron (Phase 24)
 * @version 1.0.0
 *
 * محرك محتوى SEO تلقائي بحلقة مغلقة يشتغل من الـ Cron من غير تدخل يدوي:
 *   queued -> توليد (Job خلفي) -> indexed (IndexNow) -> A/B title -> published
 *                    ^_________________________|
 *   (عند اكتمال تجربة A/B، يتم تطبيق العنوان الفائز تلقائيًا)
 *
 * السكريبت ده مجرد وسيط رفيع بيستدعي SeoContentService::runEngineCycle()
 * (نفس المنطق اللي بيستخدمه زر "تشغيل دورة" في لوحة /seo-content)، عشان
 * الكرون والـ API يتشاركوا نفس الكود.
 *
 * إعداد Cron Job في cPanel (Hostinger) - كل 10-15 دقيقة:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/seo_content_engine.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/seo_content_engine.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();
    // خطة النمو: المقالات اللي كانت مستنية حصة (بعد الاشتراك/التجديد) بتدخل الحملة
    if (class_exists('GrowthPlanService')) {
        try { (new GrowthPlanService())->releaseDue(); } catch (Throwable $e) { error_log('growth plan release: ' . $e->getMessage()); }
    }
    // خطة المقالات: صور المقالات الجاية + اللي يومها جه يدخل التوليد + عنوانها وصورتها قبل النشر
    if (class_exists('ContentCalendarService')) {
        try { (new ContentCalendarService($db))->tick(); } catch (Throwable $e) { error_log('content calendar: ' . $e->getMessage()); }
    }
    $summary = (new SeoContentService($db))->runEngineCycle();

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] SEO Content Engine: %d campaigns enqueued, %d indexed, %d A/B created, %d winner applied (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['campaigns_enqueued'],
        $summary['indexed'],
        $summary['ab_created'],
        $summary['winner_applied'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] SEO content engine error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('SEO content engine failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
