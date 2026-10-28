<?php

/**
 * Tourfecto - GBP Background Sync Job
 * Job منفصل حقيقي في نظام الطابور (queue) - مش بس اعتماد على الـ Cron
 * القديم. بيتنفّذ فورًا في الخلفية (Background Sync) لما حدث مهم يحصل
 * (زي GBPConnected) من غير ما يخلي الـ request نفسه ينتظر عملية المزامنة
 * الثقيلة (زي ما مطلوب بالظبط في بند "Sync System" بالسبيك: "لا تجعل
 * الصفحة تنتظر عمليات Sync الثقيلة").
 * @version 1.0.0
 * @since 2026-08-10 (GBP Module Upgrade - Round 5)
 */
class GbpBackgroundSyncJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $websiteId = (int) ($payload['website_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        if (!$websiteId || !$userId) {
            throw new Exception('GbpBackgroundSyncJob: website_id/user_id مطلوبين');
        }

        $sync = new GbpSyncService();
        $result = $sync->syncWebsite($websiteId, $userId);

        if (!$result['success']) {
            // منسجّلش استثناء (Exception) هنا عشان الـ Job منظامش يعيد المحاولة
            // بلا نهاية على اتصال محتاج Reconnect بشري فعليًا - الخطأ اتسجل
            // بالفعل جوه GbpSyncService::finishLog() + Logger.
            Logger::info('GBP Background Sync finished with failure (no retry needed)', [
                'website_id' => $websiteId,
                'error' => $result['error'] ?? null,
            ]);
            return;
        }

        // مركز الأتمتة: بعد المزامنة على طول، الرد على التقييمات الجديدة
        // (أوتوماتيك أو تجهيزها للموافقة) من غير ما نستنى دورة الـ cron.
        if (class_exists('ReviewAutoReplyRunner')) {
            try {
                (new ReviewAutoReplyRunner())->runForWebsite($userId, $websiteId);
            } catch (Throwable $e) {
                Logger::warning('Review automation after sync failed', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
            }
        }
    }
}
