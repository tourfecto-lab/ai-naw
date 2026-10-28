<?php

/**
 * Tourfecto - الخطط الدايمة: بتجهّز خطة الفترة الجاية قبل ما الحالية تخلص بـ 5 أيام.
 * بتعيد جدولة نفسها كل ساعة؛ ensureScheduled() بتتنادى من شاشة /social.
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialMonthlyPlanService.php';
require_once __DIR__ . '/GenerateSocialMonthlyPlanJob.php';

class SocialPlanRenewJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 3600;

    public function handle(array $payload): void
    {
        try {
            @set_time_limit(120);
            $n = (new SocialMonthlyPlanService())->renewDue(20);
            if ($n > 0) {
                Logger::info('Social plans renewed', ['count' => $n]);
            }
        } catch (Throwable $e) {
            Logger::warning('Social plan renewal run failed', ['message' => $e->getMessage()]);
        } finally {
            self::schedule();
        }
    }

    public static function ensureScheduled(): void
    {
        try {
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'SocialPlanRenewJob' AND status = 'pending' LIMIT 1")) {
                self::schedule(120);
            }
        } catch (Throwable $e) {
        }
    }

    private static function schedule(int $delay = self::INTERVAL_SECONDS): void
    {
        try {
            Container::getInstance()->make(QueueManager::class)->push(self::class, [], 'social', $delay);
        } catch (Throwable $e) {
            Logger::warning('Social plan renew reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
