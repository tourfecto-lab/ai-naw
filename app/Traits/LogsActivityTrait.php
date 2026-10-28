<?php

/**
 * Tourfecto - Logs Activity Trait
 * @version 1.0.0
 *
 * سلوك مشترك لتسجيل "نشاط" مستخدم أو نظام (audit trail) بشكل موحّد -
 * بديل عن نداء Logger::info() بصيغ مختلفة في كل كنترولر.
 */
trait LogsActivityTrait
{
    protected function logActivity(string $action, array $context = [], ?int $userId = null): void
    {
        if (class_exists('Logger')) {
            Logger::info('Activity: ' . $action, array_merge([
                'user_id' => $userId ?? ($_SESSION['user_id'] ?? null),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ], $context));
        }
    }
}
