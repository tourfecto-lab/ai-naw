<?php

/**
 * Tourfecto - تجديد باقات السوشيال المنفصلة من المحفظة + تذكير قبل التجديد بـ 3 أيام.
 * بتعيد جدولة نفسها كل ساعة؛ ensureScheduled() بتتنادى من شاشة /social.
 * @version 1.0.0
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialPackages.php';

class SocialPackagesRenewJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 3600;

    public function handle(array $payload): void
    {
        try {
            $n = SocialPackages::renewDue(200);
            if ($n > 0) {
                Logger::info('Social packages renewed', ['count' => $n]);
            }
        } catch (Throwable $e) {
            Logger::warning('Social packages renewal run failed', ['message' => $e->getMessage()]);
        } finally {
            self::schedule();
        }
    }

    public static function ensureScheduled(): void
    {
        try {
            if (!SocialPackages::ready()) {
                return;
            }
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'SocialPackagesRenewJob' AND status = 'pending' LIMIT 1")) {
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
            Logger::warning('Social packages renew reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
