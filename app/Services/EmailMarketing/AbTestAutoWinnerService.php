<?php

/**
 * Tourfecto - A/B Auto-Winner
 * @version 1.0.0
 *
 * سير العمل (لما holdout_percent > 0 و auto_winner = 1):
 *   1) start(): الاختبار بيتعمل على (100 - holdout)% من الجمهور (عينة عشوائية ثابتة)
 *      وبيتحجز holdout% للمرحلة التانية.
 *   2) cron/process_ab_winners.php => finalizeDue(): لما تخلص مدة test_hours ويخلص إرسال
 *      المتغيرين، بيختار الفائز (z-test ≥95%، وإلا المتقدم، وإلا أ) وبيعمل حملة
 *      "الباقي" بمحتوى الفائز لباقي الجمهور (اللي ماخدش أي متغير) وبيدفعها للطابور.
 *      الإرسال بيمر على EmailReputationGuard (حدود/Throttle/إيقاف) زي أي حملة.
 * الدوال النقية (splitForTest, chooseWinner, isDue) متغطاة باختبارات وحدة.
 */
class AbTestAutoWinnerService
{
    /** @var Database */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    // ------------------------------------------------------------ pure

    /**
     * يقسم معرّفات الجمهور لعينة الاختبار والمحجوز، بترتيب عشوائي ثابت (salt) —
     * مش بترتيب id عشان ما يحصلش انحياز (المشتركين القدام مقابل الجداد).
     * @return array{0:int[],1:int[]} [testIds, holdoutIds]
     */
    public static function splitForTest(array $ids, int $holdoutPercent, string $salt): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $holdoutPercent = max(0, min(90, $holdoutPercent));
        if ($holdoutPercent === 0 || count($ids) < 2) {
            return [$ids, []];
        }
        usort($ids, fn ($a, $b) => strcmp(md5($salt . ':' . $a), md5($salt . ':' . $b)));
        $hold = (int) round(count($ids) * $holdoutPercent / 100);
        $hold = max(1, min(count($ids) - 2, $hold)); // لازم يتبقى واحد على الأقل لكل متغير
        $test = array_slice($ids, 0, count($ids) - $hold);
        $holdout = array_slice($ids, count($ids) - $hold);
        sort($test);
        sort($holdout);
        return [$test, $holdout];
    }

    /**
     * اختيار الفائز من تقرير AbTestService::report().
     * @return array{winner:string,reason:string}
     */
    public static function chooseWinner(array $report): array
    {
        if (!empty($report['winner'])) {
            return ['winner' => $report['winner'], 'reason' => 'significant'];
        }
        if (!empty($report['leader'])) {
            return ['winner' => $report['leader'], 'reason' => 'leader_not_significant'];
        }
        return ['winner' => EmailAbTest::WINNER_A, 'reason' => 'tie_default_a'];
    }

    /** هل الاختبار جاهز لاختيار الفائز؟ */
    public static function isDue(?string $startedAtUtc, int $testHours, int $now, int $pendingRecipients): bool
    {
        if ($startedAtUtc === null || $startedAtUtc === '') {
            return false;
        }
        $start = strtotime($startedAtUtc . ' UTC');
        if ($start === false) {
            return false;
        }
        return $pendingRecipients === 0 && $now >= $start + max(1, $testHours) * 3600;
    }

    // ------------------------------------------------------------ orchestration

    /** يضبط إعدادات الأوتو-وينر للاختبار (يستدعيها create/update). */
    public function configure(int $userId, int $abTestId, int $holdoutPercent, bool $auto, int $testHours): array
    {
        if ($holdoutPercent < 0 || $holdoutPercent > 90) {
            return ['success' => false, 'error' => 'نسبة المحجوز بين 0 و90'];
        }
        if ($auto && $holdoutPercent < 10) {
            return ['success' => false, 'error' => 'الإرسال التلقائي للفائز يحتاج محجوز 10% على الأقل'];
        }
        $testHours = max(1, min(168, $testHours));
        $st = $this->db->getConnection()->prepare(
            "UPDATE email_ab_tests SET holdout_percent = ?, auto_winner = ?, test_hours = ?
             WHERE id = ? AND user_id = ? AND status IN ('draft','running')"
        );
        $st->execute([$holdoutPercent, $auto ? 1 : 0, $testHours, $abTestId, $userId]);
        return $st->rowCount() > 0 ? ['success' => true] : ['success' => false, 'error' => 'الاختبار غير موجود أو منتهٍ'];
    }

    /**
     * يعالج الاختبارات المستحقة. يُستدعى من cron كل 5-10 دقائق.
     * @return array{checked:int,finalized:int,errors:int}
     */
    public function finalizeDue(int $limit = 25): array
    {
        $rows = $this->db->query(
            "SELECT * FROM email_ab_tests
             WHERE auto_winner = 1 AND holdout_percent > 0 AND holdout_campaign_id IS NULL
               AND status IN ('running','finished') AND started_at IS NOT NULL
             ORDER BY id ASC LIMIT " . max(1, min(200, $limit))
        );
        $out = ['checked' => count($rows), 'finalized' => 0, 'errors' => 0];
        foreach ($rows as $ab) {
            try {
                if ($this->finalizeOne($ab)) {
                    $out['finalized']++;
                }
            } catch (\Throwable $e) {
                $out['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('AB auto-winner failed', ['ab' => $ab['id'] ?? null, 'error' => $e->getMessage()]);
                }
            }
        }
        return $out;
    }

    private function finalizeOne(array $ab): bool
    {
        $userId = (int) $ab['user_id'];
        $abId = (int) $ab['id'];
        $variantIds = [(int) $ab['variant_a_id'], (int) $ab['variant_b_id']];

        $pending = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM email_campaign_recipients WHERE campaign_id IN (?, ?) AND status IN ('pending','sending')",
            $variantIds
        )[0]['n'];
        if (!self::isDue($ab['started_at'], (int) $ab['test_hours'], time(), $pending)) {
            return false;
        }

        // قفل: نحجز الاختبار قبل ما نعمل أي حاجة (cron متوازي ما يكررش الحملة)
        $claim = $this->db->getConnection()->prepare("UPDATE email_ab_tests SET holdout_campaign_id = 0 WHERE id = ? AND holdout_campaign_id IS NULL");
        $claim->execute([$abId]);
        if ($claim->rowCount() !== 1) {
            return false;
        }

        try {
            $svc = new AbTestService();
            $winner = (string) ($ab['winner'] ?? '');
            if (!in_array($winner, [EmailAbTest::WINNER_A, EmailAbTest::WINNER_B], true)) {
                $report = $svc->report($userId, $abId);
                $choice = self::chooseWinner($report ?: []);
                $winner = $choice['winner'];
                $svc->declareWinner($userId, $abId, $winner);
            }

            $winnerCampaignId = (int) $ab[$winner === EmailAbTest::WINNER_A ? 'variant_a_id' : 'variant_b_id'];
            $winnerRow = (new EmailCampaign())->find($winnerCampaignId);
            $base = (new EmailCampaign())->find((int) $ab['base_campaign_id']);
            if (!$winnerRow || !$base) {
                throw new RuntimeException('حملة الفائز/الأساس غير موجودة');
            }
            $w = $winnerRow->toArray();

            // الباقي = جمهور الأساس اللي ماخدش أي متغير
            $campaignSvc = new EmailCampaignService();
            $audience = $campaignSvc->audience($userId, $base);
            $taken = $this->db->query(
                "SELECT DISTINCT subscriber_id FROM email_campaign_recipients WHERE campaign_id IN (?, ?) AND subscriber_id IS NOT NULL",
                $variantIds
            );
            $takenSet = array_flip(array_map(fn ($r) => (int) $r['subscriber_id'], $taken));
            $holdoutIds = [];
            foreach ($audience as $m) {
                if (!isset($takenSet[(int) $m['id']])) {
                    $holdoutIds[] = (int) $m['id'];
                }
            }

            if (!$holdoutIds) {
                $this->finishHoldout($abId, null);
                return true;
            }

            $holdoutCampaign = new EmailCampaign([
                'user_id' => $userId,
                'name' => (string) $ab['name'] . ' (الفائز → الباقي)',
                'subject' => (string) ($w['subject'] ?? ''),
                'from_name' => $w['from_name'] ?? null,
                'from_email' => $w['from_email'] ?? null,
                'template_id' => !empty($w['template_id']) ? (int) $w['template_id'] : null,
                'list_id' => !empty($w['list_id']) ? (int) $w['list_id'] : null,
                'audience_ids' => (string) ($w['audience_ids'] ?? '[]'),
                'html_body' => (string) ($w['html_body'] ?? ''),
                'status' => EmailCampaign::STATUS_DRAFT,
            ]);
            $hid = (int) $holdoutCampaign->save();
            if ($hid <= 0) {
                throw new RuntimeException('تعذر إنشاء حملة الباقي');
            }
            $prep = $campaignSvc->prepareRecipientsForSubset($userId, $hid, $holdoutIds);
            if (empty($prep['success']) || (int) $prep['total'] === 0) {
                $this->finishHoldout($abId, $hid);
                return true;
            }
            if (class_exists('QueueManager')) {
                (new QueueManager())->push('SendEmailCampaignBatchJob', ['user_id' => $userId, 'campaign_id' => $hid], 'email', 1);
            }
            $this->finishHoldout($abId, $hid);
            return true;
        } catch (\Throwable $e) {
            // نفك القفل عشان المحاولة الجاية تعيد
            $this->db->getConnection()->prepare("UPDATE email_ab_tests SET holdout_campaign_id = NULL WHERE id = ? AND holdout_campaign_id = 0")->execute([$abId]);
            throw $e;
        }
    }

    private function finishHoldout(int $abId, ?int $campaignId): void
    {
        $this->db->getConnection()->prepare("UPDATE email_ab_tests SET holdout_campaign_id = ?, holdout_sent_at = ? WHERE id = ?")
            ->execute([$campaignId ?: 0, gmdate('Y-m-d H:i:s'), $abId]);
    }
}
