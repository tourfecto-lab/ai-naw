<?php

/**
 * Tourfecto - بيجهّز منشورات رحلات الموسم في الخلفية (تحليل الصورة + توليد صورة لو لازم + كتابة النص).
 * payload: {user_id, batch}
 * @version 1.0.0
 */
require_once __DIR__ . '/../Services/RevenueIntelligence/SeasonTripPosts.php';

class GenerateSeasonPostsJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        @set_time_limit(600);
        $uid = (int) ($payload['user_id'] ?? 0);
        $batch = (string) ($payload['batch'] ?? '');
        if ($uid < 1 || $batch === '') {
            return;
        }
        (new SeasonTripPosts())->process($uid, $batch);
    }
}
