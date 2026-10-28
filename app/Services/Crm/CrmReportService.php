<?php

/**
 * Tourfecto - CRM Report & Goals Service (المرحلة 12 - G4)
 * @version 1.0.0
 *
 * سد فجوتين تنافسيتين (راجع docs/COMPETITIVE_ANALYSIS.md فقرة G4):
 * 1) Win/Loss Analysis: تقرير يوضح الصفقات المكسبة/الخاسرة حسب الفترة
 *    مع تفصيل أسباب الخسارة (`crm_deals.lost_reason`) - كل المنافسين
 *    يقدمون تقارير من هذا النوع.
 * 2) Sales Goals: أهداف إيراد شهرية مع نسبة الإنجاز الفعلي من
 *    `crm_deals` (status = won و closed_at في نفس الشهر).
 *
 * كل رقم محسوب من القاعدة الفعلية - لا قيم افتراضية/وهمية (بند 39).
 * لو مفيش بيانات كافية، يُرجع null صراحة.
 */
class CrmReportService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * تقرير Win/Loss خلال فترة زمنية.
     * @param string|null $from تاريخ YYYY-MM-DD أو null (بداية الشهر الحالي افتراضيًا)
     * @param string|null $to   تاريخ YYYY-MM-DD أو null (نهاية الشهر الحالي افتراضيًا)
     */
    public function winLoss(int $userId, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->normalizePeriod($from, $to);

        $deals = $this->db->query(
            "SELECT d.id, d.title, d.value, d.status, d.lost_reason, d.closed_at
             FROM crm_deals d
             WHERE d.owner_user_id = ?
               AND d.status IN ('won', 'lost')
               AND d.closed_at IS NOT NULL
               AND d.closed_at >= ? AND d.closed_at <= ?
             ORDER BY d.closed_at DESC",
            [$userId, $from . ' 00:00:00', $to . ' 23:59:59']
        );

        $won = 0;
        $lost = 0;
        $wonValue = 0;
        $lostValue = 0;
        $lossReasons = [];

        foreach ($deals as $deal) {
            $value = (float) $deal['value'];
            if ($deal['status'] === 'won') {
                $won++;
                $wonValue += $value;
            } else {
                $lost++;
                $lostValue += $value;
                $reason = trim((string) ($deal['lost_reason'] ?? ''));
                if ($reason !== '') {
                    $key = $reason;
                    $lossReasons[$key] = ($lossReasons[$key] ?? 0) + 1;
                }
            }
        }

        $total = $won + $lost;
        return [
            'from' => $from,
            'to' => $to,
            'won_deals' => $won,
            'lost_deals' => $lost,
            'total_closed' => $total,
            'win_rate' => $total > 0 ? round(($won / $total) * 100, 1) : null,
            'won_value' => round($wonValue, 2),
            'lost_value' => round($lostValue, 2),
            'net_value' => round($wonValue - $lostValue, 2),
            'average_won_value' => $won > 0 ? round($wonValue / $won, 2) : null,
            'loss_reasons' => $lossReasons,
        ];
    }

    /**
     * أهداف الحساب مع الإنجاز الفعلي لكل شهر.
     * الإنجاز = مجموع قيمة الصفقات المكسبة (status=won) المغلقة في نفس الشهر.
     */
    public function salesGoals(int $userId): array
    {
        $goals = (new CrmSalesGoal())->allForUser($userId);
        $results = [];
        foreach ($goals as $goal) {
            $period = (string) $goal['period'];
            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                continue;
            }
            $achieved = $this->scalar(
                "SELECT COALESCE(SUM(value), 0)
                 FROM crm_deals
                 WHERE owner_user_id = ? AND status = 'won' AND closed_at IS NOT NULL
                   AND DATE_FORMAT(closed_at, '%Y-%m') = ?",
                [$userId, $period]
            );
            $target = (float) $goal['target_value'];
            $achievedVal = (float) $achieved;
            $results[] = [
                'id' => (int) $goal['id'],
                'period' => $period,
                'target_value' => round($target, 2),
                'achieved_value' => round($achievedVal, 2),
                'progress_percent' => $target > 0 ? round(($achievedVal / $target) * 100, 1) : null,
            ];
        }
        return $results;
    }

    /** تعيين/تحديث هدف شهر محدد (upsert على UNIQUE user+period) */
    public function setGoal(int $userId, string $period, float $targetValue): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new Exception('صيغة الشهر غير صالحة (YYYY-MM)', 422);
        }
        if ($targetValue < 0) {
            throw new Exception('القيمة المستهدفة لا يمكن أن تكون سالبة', 422);
        }
        $existing = (new CrmSalesGoal())->findForPeriod($userId, $period);
        if ($existing) {
            $existing->setAttribute('target_value', $targetValue);
            $existing->save();
            $goal = $existing;
        } else {
            $goal = new CrmSalesGoal(['user_id' => $userId, 'period' => $period, 'target_value' => $targetValue]);
            $goal->save();
        }
        return $goal->toArray();
    }

    /** حذف هدف شهر محدد */
    public function deleteGoal(int $userId, int $goalId): bool
    {
        $goal = (new CrmSalesGoal())->find($goalId);
        if (!$goal || (int) $goal->getAttribute('user_id') !== $userId) {
            throw new Exception('الهدف غير موجود', 404);
        }
        return $goal->delete();
    }

    /** توحيد الفترة: لو غير محددة → الشهر الحالي */
    private function normalizePeriod(?string $from, ?string $to): array
    {
        if ($from === null || $from === '') {
            $from = date('Y-m-01');
        }
        if ($to === null || $to === '') {
            $to = date('Y-m-t');
        }
        return [$from, $to];
    }

    private function scalar(string $sql, array $params)
    {
        $rows = $this->db->query($sql, $params);
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        return reset($row);
    }
}
