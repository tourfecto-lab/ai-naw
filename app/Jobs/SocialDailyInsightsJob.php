<?php

/**
 * Tourfecto - مهمة يومية للسوشيال: لقطة عدد المتابعين لكل حساب + تحديث بيانات المنافسين على إنستجرام.
 * بتعيد جدولة نفسها كل 24 ساعة؛ ensureScheduled() بتتنادى من شاشة /social.
 * @version 1.0.0
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialGrowth.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialCompetitors.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialAccounts.php';

class SocialDailyInsightsJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 86400;

    public function handle(array $payload): void
    {
        @set_time_limit(300);
        try {
            $n = SocialGrowth::snapshot(null, 300);
            $c = (new SocialCompetitors())->refreshDue(60);
            $pics = class_exists('SocialAccounts') ? SocialAccounts::refreshStale(null, 300) : 0;
            Logger::info('Social daily insights', ['snapshots' => $n, 'competitors' => $c, 'profiles' => $pics]);
        } catch (Throwable $e) {
            Logger::warning('Social daily insights failed', ['message' => $e->getMessage()]);
        } finally {
            self::schedule();
        }
    }

    public static function ensureScheduled(): void
    {
        try {
            if (!SocialGrowth::ready()) {
                return;
            }
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'SocialDailyInsightsJob' AND status = 'pending' LIMIT 1")) {
                self::schedule(60);
            }
        } catch (Throwable $e) {
        }
    }

    private static function schedule(int $delay = self::INTERVAL_SECONDS): void
    {
        try {
            Container::getInstance()->make(QueueManager::class)->push(self::class, [], 'social', $delay);
        } catch (Throwable $e) {
            Logger::warning('Social daily insights reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
