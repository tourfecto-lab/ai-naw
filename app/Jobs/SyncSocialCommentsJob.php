<?php

/**
 * Tourfecto - سحب دوري لتعليقات السوشيال (كل 20 دقيقة) لصندوق التعليقات.
 * بيلف على العملاء اللي عندهم منشورات اتنشرت آخر 30 يوم (أقصى 20 عميل في الدورة، الأقدم مزامنة أولًا)،
 * ويبعت إشعار لو فيه تعليقات أسعار/حجز/شكاوى محتاجة رد. بيعيد جدولة نفسه؛
 * ensureScheduled() بتتنادى من شاشة /social.
 * @version 1.0.0
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialCommentInbox.php';

class SyncSocialCommentsJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 1200;
    private const USERS_PER_RUN = 20;

    public function handle(array $payload): void
    {
        @set_time_limit(240);
        try {
            $inbox = new SocialCommentInbox();
            if (!$inbox->ready()) {
                return;
            }
            // كل عميل عنده صفحة فيسبوك/إنستجرام مربوطة (حتى لو بينشر من Meta مباشرة)، اللي اتسحب من زمان الأول
            $users = Database::getInstance()->query(
                "SELECT u.user_id, COALESCE(MAX(sc.fetched_at), '2000-01-01') AS last_fetch
                   FROM (SELECT DISTINCT COALESCE(w.user_id, c.user_id) AS user_id
                           FROM platform_connections c LEFT JOIN websites w ON w.id = c.website_id
                          WHERE c.platform IN ('facebook','instagram') AND c.status = 'connected') u
                   LEFT JOIN social_comments sc ON sc.user_id = u.user_id
                  WHERE u.user_id IS NOT NULL
                  GROUP BY u.user_id ORDER BY last_fetch ASC LIMIT " . self::USERS_PER_RUN
            ) ?: [];
            foreach ($users as $u) {
                try {
                    $inbox->sync((int) $u['user_id'], 10);
                } catch (Throwable $e) {
                    Logger::warning('Social comments sync failed', ['user_id' => (int) $u['user_id'], 'message' => $e->getMessage()]);
                }
            }
        } finally {
            self::schedule();
        }
    }

    public static function ensureScheduled(): void
    {
        try {
            if (!(new SocialCommentInbox())->ready()) {
                return;
            }
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'SyncSocialCommentsJob' AND status = 'pending' LIMIT 1")) {
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
            Logger::warning('Comments sync job reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
