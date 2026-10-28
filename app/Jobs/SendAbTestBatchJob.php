<?php

/**
 * Tourfecto - Send AB Test Batch Job
 * @version 1.0.0
 *
 * يرسل دفعة (حتى 100 مستلم لكل متغير) من اختبار أ/ب عبر AbTestService::sendBatch().
 * لو فاضل مستلمين pending بعد الدفعة دي، يعيد جدولة نفسه في الطابور عشان
 * الـ cron اللي بيتشغل كل دقيقة يكمّل — نفس نمط SendEmailCampaignBatchJob.
 *
 * الـ cron:  cron/process_queue.php  (يستدعي QueueManager::processDue)
 */
class SendAbTestBatchJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $abTestId = (int) ($payload['ab_test_id'] ?? 0);

        if ($userId <= 0 || $abTestId <= 0) {
            throw new Exception('SendAbTestBatchJob: payload ناقص (user_id/ab_test_id)');
        }

        $service = new AbTestService();
        $result = $service->sendBatch($userId, $abTestId);

        // لو لسه فيه مستلمين - نعيد الجدولة لدفعة تانية (المهمة الحالية اكتملت)
        if (!empty($result['remaining']) && class_exists('QueueManager')) {
            $queue = new QueueManager();
            $queue->push(SendAbTestBatchJob::class, [
                'user_id' => $userId,
                'ab_test_id' => $abTestId,
            ], 'email', 1);
        }
    }
}
