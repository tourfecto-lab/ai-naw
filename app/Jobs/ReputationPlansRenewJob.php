<?php

/**
 * Renews stand-alone reputation plans from the wallet. Reschedules itself every hour; ensureScheduled() is called when the
 * customer plans page or the admin page is opened (same pattern as the social packages job).
 */
class ReputationPlansRenewJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 3600;

    public function handle(array $payload): void
    {
        try {
            $n = ReputationPlans::renewDue(200);
            if ($n > 0) {
                Logger::info('Reputation plans renewed', ['count' => $n]);
            }
        } catch (Throwable $e) {
            Logger::warning('Reputation plans renewal run failed', ['message' => $e->getMessage()]);
        } finally {
            self::schedule();
        }
    }

    public static function ensureScheduled(): void
    {
        try {
            if (!ReputationPlans::ready()) {
                return;
            }
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'ReputationPlansRenewJob' AND status = 'pending' LIMIT 1")) {
                self::schedule(60);
            }
        } catch (Throwable $e) {
            error_log('[ReputationPlansRenewJob] ignored error: ' . $e->getMessage());
        }
    }

    private static function schedule(int $delay = self::INTERVAL_SECONDS): void
    {
        try {
            Container::getInstance()->make(QueueManager::class)->push(self::class, [], 'social', $delay);
        } catch (Throwable $e) {
            Logger::warning('Reputation plans renew reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
