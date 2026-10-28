<?php

/**
 * Tourfecto - Recurring Email Campaigns (نشرة يومية/أسبوعية/شهرية)
 * @version 1.0.0
 *
 * الحملة المتكررة "قالب" بيفضل draft وعليه recurrence + next_run_at (UTC).
 * كل ما الموعد ييجي، الـ cron (process_email_automations) بياخد نسخة منها
 * (parent_campaign_id) ويبعتها بنفس مسار "إرسال الآن" — والجمهور بيتحسب من
 * جديد كل مرة، فالمشتركين الجداد بيوصلهم واللي لغى ما يوصلوش.
 * الموعد الجاي بيتقدّم بـ UPDATE شرطي على القيمة القديمة، فلو cron اشتغل
 * مرتين في نفس الوقت النسخة بتتعمل مرة واحدة بس.
 */
class EmailRecurringCampaignService
{
    public const RECURRENCES = ['daily', 'weekly', 'monthly'];

    /** @var Database */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** الموعد الجاي بعد $from (نفس الساعة). الشهري بيثبت على آخر يوم لو الشهر أقصر. */
    public static function nextOccurrence(string $recurrence, int $from, ?int $anchorDay = null): int
    {
        switch ($recurrence) {
            case 'daily':
                return strtotime('+1 day', $from);
            case 'weekly':
                return strtotime('+7 days', $from);
            case 'monthly':
                $day = $anchorDay ?? (int) gmdate('j', $from);
                $year = (int) gmdate('Y', $from);
                $month = (int) gmdate('n', $from) + 1;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
                $lastDay = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
                return gmmktime((int) gmdate('G', $from), (int) gmdate('i', $from), 0, $month, min($day, $lastDay), $year);
        }
        throw new InvalidArgumentException('تكرار غير مدعوم');
    }

    /**
     * تفعيل/إيقاف التكرار لحملة draft. $firstRunAt بتوقيت الحساب (زي الجدولة).
     * $recurrence فاضي = إيقاف.
     */
    public function configure(int $userId, int $campaignId, string $recurrence, string $firstRunAt = ''): array
    {
        try {
            return $this->doConfigure($userId, $campaignId, $recurrence, $firstRunAt);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'شغّل migration 2026_10_20_000002_email_platform_integration.sql الأول'];
        }
    }

    private function doConfigure(int $userId, int $campaignId, string $recurrence, string $firstRunAt): array
    {
        $campaign = $this->db->query("SELECT id, status FROM email_campaigns WHERE id = ? AND user_id = ? LIMIT 1", [$campaignId, $userId])[0] ?? null;
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }
        if ($recurrence === '') {
            $this->db->query("UPDATE email_campaigns SET recurrence = NULL, next_run_at = NULL WHERE id = ?", [$campaignId]);
            return ['success' => true, 'recurrence' => null];
        }
        if (!in_array($recurrence, self::RECURRENCES, true)) {
            return ['success' => false, 'error' => 'تكرار غير مدعوم'];
        }
        if ($campaign['status'] !== 'draft') {
            return ['success' => false, 'error' => 'التكرار بيتفعّل على حملة مسودة (هي اللي بتتنسخ وتتبعت كل مرة)'];
        }
        try {
            $ts = InternationalSettingsService::scheduledTimestamp($userId, $firstRunAt);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'حدد أول موعد إرسال صحيح'];
        }
        if (!$ts || $ts <= time()) {
            return ['success' => false, 'error' => 'أول موعد لازم يكون في المستقبل'];
        }
        $this->db->query(
            "UPDATE email_campaigns SET recurrence = ?, next_run_at = ? WHERE id = ? AND user_id = ?",
            [$recurrence, gmdate('Y-m-d H:i:s', $ts), $campaignId, $userId]
        );
        return ['success' => true, 'recurrence' => $recurrence, 'next_run_at' => gmdate('Y-m-d H:i:s', $ts)];
    }

    /** بيتنادى من الـ cron. @return int عدد النسخ اللي اتبعتت */
    public function processDue(): int
    {
        try {
            $due = $this->db->query(
                "SELECT * FROM email_campaigns
                 WHERE recurrence IS NOT NULL AND next_run_at IS NOT NULL AND next_run_at <= ? AND status = 'draft'
                 ORDER BY next_run_at ASC LIMIT 20",
                [gmdate('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            return 0; // الأعمدة لسه ما اتضافتش (migration)
        }
        $sent = 0;
        foreach ($due as $parent) {
            try {
                if ($this->runOccurrence($parent)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('Recurring email campaign failed', ['campaign_id' => $parent['id'], 'error' => $e->getMessage()]);
                }
            }
        }
        return $sent;
    }

    private function runOccurrence(array $parent): bool
    {
        $current = strtotime($parent['next_run_at'] . ' UTC');
        $next = self::nextOccurrence((string) $parent['recurrence'], $current);
        // لو الـ cron كان واقف فترة: ما نبعتش كل المواعيد الفايتة، نروح لأقرب موعد جاي
        while ($next <= time()) {
            $next = self::nextOccurrence((string) $parent['recurrence'], $next);
        }
        $q = $this->db->getConnection()->prepare("UPDATE email_campaigns SET next_run_at = ? WHERE id = ? AND next_run_at = ?");
        $q->execute([gmdate('Y-m-d H:i:s', $next), (int) $parent['id'], $parent['next_run_at']]);
        if ($q->rowCount() !== 1) {
            return false; // تشغيل تاني سبقنا
        }

        $userId = (int) $parent['user_id'];
        $copy = new EmailCampaign([
            'user_id' => $userId,
            'name' => trim((string) $parent['name']) . ' — ' . gmdate('Y-m-d', $current),
            'subject' => (string) $parent['subject'],
            'from_name' => $parent['from_name'],
            'from_email' => $parent['from_email'],
            'template_id' => $parent['template_id'],
            'list_id' => $parent['list_id'],
            'audience_ids' => $parent['audience_ids'],
            'segment_id' => $parent['segment_id'],
            'html_body' => (string) $parent['html_body'],
            'status' => EmailCampaign::STATUS_DRAFT,
            'send_time_optimization' => (int) ($parent['send_time_optimization'] ?? 0),
            'parent_campaign_id' => (int) $parent['id'],
        ]);
        $childId = (int) $copy->save();
        if ($childId <= 0) {
            return false;
        }

        $service = new EmailCampaignService();
        $pre = $service->guard()->preflight($userId, $childId);
        if (!$pre['allowed']) {
            $this->db->query("UPDATE email_campaigns SET status = 'failed', error_message = ? WHERE id = ?", [mb_substr((string) $pre['message'], 0, 255), $childId]);
            return false;
        }
        $prepared = $service->prepareRecipients($userId, $childId);
        if (empty($prepared['success']) || (int) $prepared['total'] <= 0) {
            $this->db->query("UPDATE email_campaigns SET status = 'cancelled', error_message = 'No active subscribers in the target audience' WHERE id = ?", [$childId]);
            return false;
        }
        if (!class_exists('QueueManager')) {
            return false;
        }
        (new QueueManager())->push(SendEmailCampaignBatchJob::class, ['user_id' => $userId, 'campaign_id' => $childId], 'email');
        $this->db->query("UPDATE email_campaigns SET status = 'sending' WHERE id = ?", [$childId]);
        return true;
    }
}
