<?php

/**
 * Tourfecto - Send Email Campaign Batch Job
 * @version 1.0.0
 *
 * يرسل دفعة (حتى 100 مستلم) من حملة تسويق بريدي عبر EmailCampaignService::sendBatch().
 * لو فاضل مستلمين pending بعد الدفعة دي، يعيد جدولة نفسه في الطابور (بتأخير
 * retry_after لو فيه حد/Throttle/إعادة محاولة) عشان الـ cron يكمّل. لو الحملة/الحساب
 * اتوقفوا (paused/blocked) مفيش إعادة جدولة. لو فشل كله، يُحتسب فشل ويُعاد
 * المحاولة تلقائيًا (QueueManager backoff).
 *
 * الـ cron:  cron/process_queue.php  (يستدعي QueueManager::processDue)
 */
class SendEmailCampaignBatchJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $campaignId = (int) ($payload['campaign_id'] ?? 0);

        if ($userId <= 0 || $campaignId <= 0) {
            throw new Exception('SendEmailCampaignBatchJob: payload ناقص (user_id/campaign_id)');
        }

        $service = new EmailCampaignService();
        $result = $service->sendBatch($userId, $campaignId);

        // الحملة اتوقفت (تلقائيًا/إداريًا) أو الحساب موقوف: ما نعيدش الجدولة.
        // الاستئناف من الأدمن (EmailReputationGuard::resume*) هو اللي بيعيد الـ job.
        if (!empty($result['paused']) || !empty($result['blocked'])) {
            return;
        }

        // لو لسه فيه مستلمين - نعيد الجدولة لدفعة تانية (المهمة الحالية اكتملت).
        // retry_after: لو وصلنا لحد (ساعي/يومي/شهري) أو إعادة محاولة مؤجلة أو
        // Throttle، ننتظر بدل ما نلفّ كل ثانية على الفاضي.
        if (!empty($result['remaining']) && class_exists('QueueManager')) {
            $delay = max(1, (int) ($result['retry_after'] ?? 1));
            $queue = new QueueManager();
            $queue->push(SendEmailCampaignBatchJob::class, [
                'user_id' => $userId,
                'campaign_id' => $campaignId,
            ], 'email', $delay);
        }
    }
}
