<?php

/**
 * Tourfecto - مصالحة دورية لمنشورات uncertain (كل 10 دقايق).
 * payload اختياري: ['target_id'=>int] لهدف واحد، وإلا دفعة (أقصى 15).
 * بيعيد جدولة نفسه؛ ensureScheduled() بتتنادى من شاشة /social عشان تضمن إنه شغال.
 * @version 1.0.0
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialPostReconciler.php';

class ReconcileUncertainPostsJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 600;
    private const BATCH = 15;

    public function handle(array $payload): void
    {
        @set_time_limit(120);
        $single = (int) ($payload['target_id'] ?? 0);
        try {
            if ($single > 0) {
                SocialPostReconciler::reconcileTarget($single);
                return;
            }
            // الأقدم أولًا، ومنتظرين دقيقتين على الأقل بعد آخر محاولة عشان ما نسابقش job لسه شغال
            // (publish_attempt_at من migration الموثوقية؛ NULL = منشورات قديمة قبلها فتتفحص)
            try {
                $rows = Database::getInstance()->query(
                    "SELECT id FROM social_post_targets WHERE status = 'uncertain' AND (publish_attempt_at IS NULL OR publish_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)) ORDER BY id ASC LIMIT " . self::BATCH
                ) ?: [];
            } catch (Throwable $e) {
                Logger::warning('Reconcile skipped: reliability migration not applied', ['message' => $e->getMessage()]);
                return;
            }
            foreach ($rows as $r) {
                SocialPostReconciler::reconcileTarget((int) $r['id']);
            }
        } finally {
            if ($single === 0) {
                self::schedule();
            }
        }
    }

    public static function ensureScheduled(): void
    {
        try {
            // ما نجدولش قبل migration الموثوقية (الاستعلام بيعتمد على publish_attempt_at)
            if (!Database::getInstance()->query("SHOW COLUMNS FROM social_post_targets LIKE 'publish_attempt_at'")) {
                return;
            }
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'ReconcileUncertainPostsJob' AND status = 'pending' LIMIT 1")) {
                self::schedule();
            }
        } catch (Throwable $e) {
        }
    }

    private static function schedule(): void
    {
        try {
            Container::getInstance()->make(QueueManager::class)->push(self::class, [], 'social', self::INTERVAL_SECONDS);
        } catch (Throwable $e) {
            Logger::warning('Reconcile job reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
