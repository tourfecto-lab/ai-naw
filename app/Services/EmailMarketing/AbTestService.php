<?php

/**
 * Tourfecto - Email Marketing A/B Testing Service (المرحلة 4)
 * @version 1.0.0
 *
 * اختبار أ/ب للحملات (مثل Brevo/Mailchimp): من حملة أساسية بيقسم المستخدم
 * الجمهور لنسبتين (مثل 50/50) بين متغيرين (أ/ب) مختلفين في العنوان أو
 * المحتوى، وبيتم الإرسال لكل متغير بسجلات مستلمين منفصلة، ثم يُحسب
 * معدل الفتح/الكليك ويُحدد المتغير الفائز.
 *
 * Additive خالص - يبني على EmailCampaignService القائمة دون تعديلها.
 */
class AbTestService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ CRUD ============================

    /**
     * إنشاء اختبار أ/ب: ينشئ حملتين (متغير أ و ب) من نسخ الحملة الأساسية.
     * @return array ['success'=>bool, 'id'=>int, 'error'=>?string]
     */
    public function create(int $userId, array $data): array
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return ['success' => false, 'id' => 0, 'error' => 'اسم الاختبار مطلوب'];
        }
        $baseCampaignId = (int) ($data['base_campaign_id'] ?? 0);
        $base = (new EmailCampaign())->find($baseCampaignId);
        if (!$base || (int) $base->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'id' => 0, 'error' => 'الحملة الأساسية غير موجودة'];
        }
        $split = (int) ($data['split_percent'] ?? 50);
        if ($split < 5 || $split > 95) {
            return ['success' => false, 'id' => 0, 'error' => 'نسبة التقسيم يجب أن تكون بين 5 و95'];
        }
        $metric = in_array($data['metric'] ?? '', [EmailAbTest::METRIC_OPEN, EmailAbTest::METRIC_CLICK], true)
            ? $data['metric']
            : EmailAbTest::METRIC_OPEN;

        $baseRow = $base->toArray();
        $aId = $this->duplicateAsVariant($userId, $baseRow, (string) $data['name'] . ' (أ)');
        $bId = $this->duplicateAsVariant($userId, $baseRow, (string) $data['name'] . ' (ب)');
        if ($aId <= 0 || $bId <= 0) {
            return ['success' => false, 'id' => 0, 'error' => 'تعذر إنشاء متغيرات الاختبار'];
        }

        $ab = new EmailAbTest([
            'user_id' => $userId,
            'name' => trim((string) $data['name']),
            'base_campaign_id' => $baseCampaignId,
            'variant_a_id' => $aId,
            'variant_b_id' => $bId,
            'split_percent' => $split,
            'metric' => $metric,
            'status' => EmailAbTest::STATUS_DRAFT,
        ]);
        $id = (int) $ab->save();
        if ($id <= 0) {
            return ['success' => false, 'id' => 0, 'error' => 'تعذر حفظ الاختبار'];
        }
        // اختياري: إرسال الفائز تلقائيًا (holdout_percent, auto_winner, test_hours)
        if (isset($data['holdout_percent']) || !empty($data['auto_winner'])) {
            $cfg = (new AbTestAutoWinnerService())->configure(
                $userId, $id, (int) ($data['holdout_percent'] ?? 0), !empty($data['auto_winner']), (int) ($data['test_hours'] ?? 24)
            );
            if (empty($cfg['success'])) {
                return ['success' => true, 'id' => $id, 'warning' => $cfg['error']];
            }
        }
        return ['success' => true, 'id' => $id];
    }

    public function get(int $userId, int $abTestId): ?array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return null;
        }
        $row = $ab->toArray();
        $row['base_campaign'] = $this->campaignArray((int) $row['base_campaign_id']);
        $row['variant_a'] = $this->campaignArray((int) $row['variant_a_id']);
        $row['variant_b'] = $this->campaignArray((int) $row['variant_b_id']);
        $row['recipients'] = [
            'a' => (int) ($row['variant_a']['total_recipients'] ?? 0),
            'b' => (int) ($row['variant_b']['total_recipients'] ?? 0),
        ];
        return $row;
    }

    public function list(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT t.*,
                    c.name AS base_name
             FROM email_ab_tests t
             LEFT JOIN email_campaigns c ON c.id = t.base_campaign_id
             WHERE t.user_id = ?
             ORDER BY t.created_at DESC",
            [$userId]
        );
        foreach ($rows as &$row) {
            $row['status_label'] = EmailAbTest::statuses()[$row['status']] ?? $row['status'];
        }
        return $rows;
    }

    public function delete(int $userId, int $abTestId): array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['success' => false, 'error' => 'الاختبار غير موجود'];
        }
        if ($ab->getAttribute('status') === EmailAbTest::STATUS_RUNNING) {
            return ['success' => false, 'error' => 'لا يمكن حذف اختبار قيد التشغيل'];
        }
        $ab->delete();
        return ['success' => true];
    }

    /**
     * تعديل محتوى متغير (أ/ب): subject/html_body على حملة المتغير.
     */
    public function setVariantContent(int $userId, int $abTestId, string $variant, array $data): array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['success' => false, 'error' => 'الاختبار غير موجود'];
        }
        if (in_array($ab->getAttribute('status'), [EmailAbTest::STATUS_FINISHED, EmailAbTest::STATUS_CANCELLED], true)) {
            return ['success' => false, 'error' => 'لا يمكن تعديل اختبار منتهٍ أو ملغي'];
        }
        if (!in_array($variant, ['a', 'b'], true)) {
            return ['success' => false, 'error' => 'متغير غير صالح'];
        }
        $campaignId = (int) $ab->getAttribute($variant === 'a' ? 'variant_a_id' : 'variant_b_id');
        $result = (new EmailCampaignService())->update($userId, $campaignId, $data);
        return $result;
    }

    // ============================ Run ============================

    /**
     * تشغيل الاختبار: يقسم جمهور الحملة الأساسية بين المتغيرين ويجهّز
     * سجلات المستلمين لكل متغير. المتغير ب يأخذ split_percent%، و أ يأخذ
     * الباقي (تقسيم بنسبة تقريبية حسب الترتيب الافتراضي id ASC).
     * @return array ['success'=>bool, 'a'=>int, 'b'=>int, 'total'=>int, 'error'=>?string]
     */
    public function start(int $userId, int $abTestId): array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['success' => false, 'a' => 0, 'b' => 0, 'total' => 0, 'error' => 'الاختبار غير موجود'];
        }
        if ($ab->getAttribute('status') === EmailAbTest::STATUS_FINISHED) {
            return ['success' => false, 'a' => 0, 'b' => 0, 'total' => 0, 'error' => 'الاختبار منتهٍ بالفعل'];
        }

        $baseCampaign = (new EmailCampaign())->find((int) $ab->getAttribute('base_campaign_id'));
        if (!$baseCampaign || (int) $baseCampaign->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'a' => 0, 'b' => 0, 'total' => 0, 'error' => 'الحملة الأساسية غير موجودة'];
        }

        $audience = (new EmailCampaignService())->audience($userId, $baseCampaign);
        $total = count($audience);
        if ($total === 0) {
            return ['success' => false, 'a' => 0, 'b' => 0, 'total' => 0, 'error' => 'لا يوجد جمهور للتقسيم'];
        }

        // holdout: الاختبار على عينة عشوائية ثابتة، والباقي يتحجز للفائز (AbTestAutoWinnerService)
        $holdoutPct = (int) ($this->db->query("SELECT holdout_percent FROM email_ab_tests WHERE id = ? LIMIT 1", [$abTestId])[0]['holdout_percent'] ?? 0);
        if ($holdoutPct > 0 && $total >= 4) {
            [$testIds] = AbTestAutoWinnerService::splitForTest(array_map(fn ($m) => (int) $m['id'], $audience), $holdoutPct, 'ab' . $abTestId);
            $keep = array_flip($testIds);
            $audience = array_values(array_filter($audience, fn ($m) => isset($keep[(int) $m['id']])));
            $total = count($audience);
        }

        $split = max(5, min(95, (int) $ab->getAttribute('split_percent')));
        $bCount = (int) round($total * $split / 100);
        $bCount = max(1, min($total - 1, $bCount)); // كل متغير لازم ياخد واحد على الأقل

        $aIds = [];
        $bIds = [];
        foreach ($audience as $i => $member) {
            if ($i < $total - $bCount) {
                $aIds[] = (int) $member['id'];
            } else {
                $bIds[] = (int) $member['id'];
            }
        }

        $svc = new EmailCampaignService();
        $rA = $svc->prepareRecipientsForSubset($userId, (int) $ab->getAttribute('variant_a_id'), $aIds);
        $rB = $svc->prepareRecipientsForSubset($userId, (int) $ab->getAttribute('variant_b_id'), $bIds);

        if (empty($rA['success']) || empty($rB['success'])) {
            return ['success' => false, 'a' => 0, 'b' => 0, 'total' => $total, 'error' => 'تعذر تجهيز سجلات المستلمين'];
        }

        if ($ab->getAttribute('status') !== EmailAbTest::STATUS_RUNNING) {
            $ab->setAttribute('status', EmailAbTest::STATUS_RUNNING);
            $ab->save();
        }
        $this->db->query("UPDATE email_ab_tests SET started_at = COALESCE(started_at, ?) WHERE id = ?", [gmdate('Y-m-d H:i:s'), $abTestId]);

        return [
            'success' => true,
            'a' => (int) $rA['total'],
            'b' => (int) $rB['total'],
            'total' => (int) $rA['total'] + (int) $rB['total'],
        ];
    }

    /**
     * إرسال دفعة من كل متغير (يستدعيه cron أو المستخدم). يرسل حتى BATCH_SIZE
     * لكل متغير ويعيد ملخص الدفعة.
     * @return array ['processed'=>int, 'failed'=>int, 'remaining'=>bool, 'error'=>?string]
     */
    public function sendBatch(int $userId, int $abTestId): array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => false, 'error' => 'الاختبار غير موجود'];
        }
        if ($ab->getAttribute('status') !== EmailAbTest::STATUS_RUNNING) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => false, 'error' => 'الاختبار غير قيد التشغيل'];
        }

        $svc = new EmailCampaignService();
        $ra = $svc->sendBatch($userId, (int) $ab->getAttribute('variant_a_id'));
        $rb = $svc->sendBatch($userId, (int) $ab->getAttribute('variant_b_id'));

        $processed = (int) $ra['processed'] + (int) $rb['processed'];
        $failed = (int) $ra['failed'] + (int) $rb['failed'];
        $remaining = (bool) ($ra['remaining'] || $rb['remaining']);
        $error = $ra['error'] ?? $rb['error'] ?? null;

        if (!$remaining && $processed === 0 && $failed === 0) {
            // كل المتغيرات خلصت إرسال (حتى لو فشلت فرديًا)
            $ab->setAttribute('status', EmailAbTest::STATUS_FINISHED);
            $ab->save();
        }

        return ['processed' => $processed, 'failed' => $failed, 'remaining' => $remaining, 'error' => $error];
    }

    /**
     * إرسال فوري متزامن لاختبار أ/ب: يرسل نسخة من المتغير أ و/أو ب إلى بريد
     * محدد (مثل زر "إرسال اختبار" في الحملات) — دون التأثير على الجمهور.
     *
     * @param string $variant 'a' | 'b' | 'all'
     * @return array ['success'=>bool, 'sent'=>array, 'error'=>?string]
     */
    public function sendTest(int $userId, int $abTestId, string $variant, string $toEmail): array
    {
        if (!in_array($variant, ['a', 'b', 'all'], true)) {
            return ['success' => false, 'sent' => [], 'error' => 'المتغير غير صالح'];
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'sent' => [], 'error' => 'بريد إرسال الاختبار غير صالح'];
        }
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['success' => false, 'sent' => [], 'error' => 'الاختبار غير موجود'];
        }

        $svc = new EmailCampaignService();
        $sent = [];
        $campaigns = [];
        if ($variant !== 'b') {
            $campaigns['a'] = (int) $ab->getAttribute('variant_a_id');
        }
        if ($variant !== 'a') {
            $campaigns['b'] = (int) $ab->getAttribute('variant_b_id');
        }

        foreach ($campaigns as $key => $campaignId) {
            $r = $svc->sendTest($userId, $campaignId, $toEmail);
            if ($r['success']) {
                $sent[$key] = ['sent' => 1];
            } else {
                $sent[$key] = ['sent' => 0, 'error' => $r['error']];
            }
        }

        $allOk = !empty($sent) && count(array_filter($sent, fn ($s) => $s['sent'] === 1)) === count($sent);
        return $allOk
            ? ['success' => true, 'sent' => $sent]
            : ['success' => false, 'sent' => $sent, 'error' => 'تعذر إرسال واحد أو أكثر من المتغيرات'];
    }

    // ============================ Report ============================

    /**
     * تقرير الاختبار: مقارنة معدلات الفتح/الكليك لكل متغير.
     * @return array|null ['variant_a'=>array, 'variant_b'=>array, 'winner'=>?string, ...]
     */
    public function report(int $userId, int $abTestId): ?array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return null;
        }
        $svc = new EmailCampaignService();
        $ra = $svc->report($userId, (int) $ab->getAttribute('variant_a_id'));
        $rb = $svc->report($userId, (int) $ab->getAttribute('variant_b_id'));

        $metric = $ab->getAttribute('metric') ?: EmailAbTest::METRIC_OPEN;
        $keyA = $metric === EmailAbTest::METRIC_CLICK ? 'click_rate' : 'open_rate';
        $keyB = $metric === EmailAbTest::METRIC_CLICK ? 'click_rate' : 'open_rate';

        $rateA = (float) ($ra[$keyA] ?? 0);
        $rateB = (float) ($rb[$keyB] ?? 0);

        // 📊 قبل كده أي فرق ولو 0.1% كان بيتعلن "فائز" - حتى لو 20 إيميل بس (صدفة).
        //    دلوقتي اختبار إحصائي (two-proportion z-test): الفائز بيتعلن بس لو الثقة ≥ 95%.
        $countKey = $metric === EmailAbTest::METRIC_CLICK ? 'clicked_count' : 'opened_count';
        $sig = self::significance(
            (int) ($ra[$countKey] ?? 0), (int) ($ra['sent_count'] ?? 0),
            (int) ($rb[$countKey] ?? 0), (int) ($rb['sent_count'] ?? 0)
        );
        $winner = null;
        $leader = null;
        if (abs($rateA - $rateB) > 0.001 && ($rateA > 0 || $rateB > 0)) {
            $leader = $rateA > $rateB ? EmailAbTest::WINNER_A : EmailAbTest::WINNER_B;
            if ($sig['significant']) {
                $winner = $leader;
            }
        }
        $leaderName = $leader === EmailAbTest::WINNER_A ? 'أ' : 'ب';
        if ($winner !== null) {
            $recommendation = 'المتغير ' . $leaderName . ' هو الفائز بثقة ' . $sig['confidence'] . '% (فرق ' . $sig['lift_pct'] . '%) - تقدر تعلنه وتنسخه للحملة الأساسية';
        } elseif (!$sig['enough_data']) {
            $recommendation = 'لسه بدري - محتاجين على الأقل ' . $sig['min_per_variant'] . ' مستلم لكل متغير ونتايج كفاية قبل ما نحكم';
        } elseif ($leader !== null) {
            $recommendation = 'المتغير ' . $leaderName . ' متقدم بس الفرق ممكن يكون صدفة (الثقة ' . $sig['confidence'] . '% أقل من 95%)'
                . ($sig['needed_per_variant'] ? ' - تقريبًا محتاج ' . $sig['needed_per_variant'] . ' مستلم لكل متغير عشان نتأكد' : '');
        } else {
            $recommendation = 'النتايج متعادلة - مفيش فرق حقيقي بين المتغيرين';
        }

        return [
            'id' => (int) $ab->getAttribute('id'),
            'name' => (string) $ab->getAttribute('name'),
            'status' => (string) $ab->getAttribute('status'),
            'metric' => $metric,
            'metric_label' => $metric === EmailAbTest::METRIC_CLICK ? 'معدل الكليك' : 'معدل الفتح',
            'declared_winner' => $ab->getAttribute('winner'),
            'variant_a' => $ra,
            'variant_b' => $rb,
            'winner' => $winner,
            'leader' => $leader,
            'significance' => $sig,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * اختبار فرق نسبتين (two-proportion z-test، ذو طرفين).
     * @return array{confidence:float, p_value:?float, significant:bool, enough_data:bool, lift_pct:?float, min_per_variant:int, needed_per_variant:?int}
     */
    public static function significance(int $succA, int $nA, int $succB, int $nB): array
    {
        $min = 100;
        $out = ['confidence' => 0.0, 'p_value' => null, 'significant' => false, 'enough_data' => false, 'lift_pct' => null, 'min_per_variant' => $min, 'needed_per_variant' => null];
        if ($nA <= 0 || $nB <= 0) {
            return $out;
        }
        $succA = min($succA, $nA);
        $succB = min($succB, $nB);
        $pA = $succA / $nA;
        $pB = $succB / $nB;
        $base = min($pA, $pB);
        $out['lift_pct'] = $base > 0 ? round(abs($pA - $pB) / $base * 100, 1) : null;
        // شرط الحجم: 100 لكل متغير و5 نجاحات/فشل على الأقل (عشان التقريب الطبيعي يبقى معقول)
        $out['enough_data'] = $nA >= $min && $nB >= $min && min($succA, $succB, $nA - $succA, $nB - $succB) >= 5;
        $pool = ($succA + $succB) / ($nA + $nB);
        $se = sqrt($pool * (1 - $pool) * (1 / $nA + 1 / $nB));
        if ($se <= 0) {
            return $out;
        }
        $z = abs($pA - $pB) / $se;
        $p = 2 * (1 - self::normCdf($z));
        $out['p_value'] = round($p, 4);
        $out['confidence'] = round(max(0, min(99.9, (1 - $p) * 100)), 1);
        $out['significant'] = $out['enough_data'] && $p < 0.05;
        // تقدير الحجم المطلوب لكل متغير (قوة 80%، ثقة 95%) لنفس الفرق الحالي
        $d = abs($pA - $pB);
        if (!$out['significant'] && $d > 0) {
            $pbar = ($pA + $pB) / 2;
            $n = pow(1.96 * sqrt(2 * $pbar * (1 - $pbar)) + 0.8416 * sqrt($pA * (1 - $pA) + $pB * (1 - $pB)), 2) / ($d * $d);
            $out['needed_per_variant'] = $n < 1e7 ? (int) ceil($n) : null;
        }
        return $out;
    }

    /** دالة التوزيع الطبيعي التراكمية (تقريب Abramowitz–Stegun 7.1.26) */
    private static function normCdf(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x) / M_SQRT2);
        $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-($x * $x) / 2);
        return $x >= 0 ? 0.5 * (1 + $y) : 0.5 * (1 - $y);
    }

    /**
     * إعلان فائز رسمي وإنهاء الاختبار.
     */
    public function declareWinner(int $userId, int $abTestId, string $winner): array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['success' => false, 'error' => 'الاختبار غير موجود'];
        }
        if (!in_array($winner, [EmailAbTest::WINNER_A, EmailAbTest::WINNER_B], true)) {
            return ['success' => false, 'error' => 'متغير الفائز غير صالح'];
        }
        $ab->setAttribute('winner', $winner);
        $ab->setAttribute('winner_at', date('Y-m-d H:i:s'));
        $ab->setAttribute('status', EmailAbTest::STATUS_FINISHED);
        $ab->save();
        return ['success' => true];
    }

    /**
     * نسخ الفائز إلى الحملة الأساسية (ليكون هو النسخة المرسلة نهائيًا).
     */
    public function applyWinnerToBase(int $userId, int $abTestId): array
    {
        $ab = $this->findOwned($userId, $abTestId);
        if (!$ab) {
            return ['success' => false, 'error' => 'الاختبار غير موجود'];
        }
        $winner = $ab->getAttribute('winner');
        if (!in_array($winner, [EmailAbTest::WINNER_A, EmailAbTest::WINNER_B], true)) {
            return ['success' => false, 'error' => 'لا يوجد فائز معلن بعد'];
        }
        $winnerCampaignId = (int) $ab->getAttribute($winner === EmailAbTest::WINNER_A ? 'variant_a_id' : 'variant_b_id');
        $winnerCampaign = (new EmailCampaign())->find($winnerCampaignId);
        if (!$winnerCampaign) {
            return ['success' => false, 'error' => 'حملة الفائز غير موجودة'];
        }
        $row = $winnerCampaign->toArray();

        $svc = new EmailCampaignService();
        return $svc->update($userId, (int) $ab->getAttribute('base_campaign_id'), [
            'subject' => $row['subject'] ?? '',
            'html_body' => $row['html_body'] ?? '',
        ]);
    }

    // ============================ Helpers ============================

    private function findOwned(int $userId, int $abTestId): ?EmailAbTest
    {
        $ab = (new EmailAbTest())->find($abTestId);
        if (!$ab || (int) $ab->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $ab;
    }

    private function campaignArray(int $campaignId): ?array
    {
        $campaign = (new EmailCampaign())->find($campaignId);
        return $campaign ? $campaign->toArray() : null;
    }

    private function duplicateAsVariant(int $userId, array $baseRow, string $name): int
    {
        $campaign = new EmailCampaign([
            'user_id' => $userId,
            'name' => $name,
            'subject' => (string) ($baseRow['subject'] ?? ''),
            'from_name' => $baseRow['from_name'] ?? null,
            'from_email' => $baseRow['from_email'] ?? null,
            'template_id' => !empty($baseRow['template_id']) ? (int) $baseRow['template_id'] : null,
            'list_id' => !empty($baseRow['list_id']) ? (int) $baseRow['list_id'] : null,
            'audience_ids' => (string) ($baseRow['audience_ids'] ?? '[]'),
            'html_body' => (string) ($baseRow['html_body'] ?? ''),
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        return (int) $campaign->save();
    }
}
