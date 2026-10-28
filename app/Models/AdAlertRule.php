<?php

/**
 * Tourfecto - Ad Alert Rule Model
 * قاعدة إنذار استباقية لمستخدم - التفعيل/الحد لكل قاعدة موجودة هنا،
 * والتقييم بيتم من AdAlertService (بيانات حقيقية بس - مفيش أرقام مختلقة).
 * @version 1.0.0
 */
class AdAlertRule extends Model
{
    protected $table = 'ad_alert_rules';
    protected $fillable = [
        'user_id', 'rule_type', 'is_enabled', 'threshold_value',
    ];

    /**
     * يرجع كل قواعد مستخدم (الأولوية للصف المسجّل، وإلا افتراضيات آمنة
     * غير محفوظة - مش بتحفظ تلقائيًا).
     * @return AdAlertRule[]
     */
    public static function forUser(int $userId): array
    {
        $rows = (new self())->where(['user_id' => $userId]);
        if (empty($rows)) {
            return array_map(function (string $type) use ($userId): AdAlertRule {
                $r = new self(['user_id' => $userId]);
                $r->setAttribute('rule_type', $type);
                $r->setAttribute('is_enabled', 1);
                $r->setAttribute('threshold_value', self::defaultThreshold($type));
                return $r;
            }, ['budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing']);
        }

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->getAttribute('rule_type')] = $row;
        }

        // ضمان وجود كل القواعد المعروفة حتى لو مفيش صف لبعضها
        foreach (['budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing'] as $type) {
            if (!isset($byType[$type])) {
                $r = new self(['user_id' => $userId]);
                $r->setAttribute('rule_type', $type);
                $r->setAttribute('is_enabled', 1);
                $r->setAttribute('threshold_value', self::defaultThreshold($type));
                $byType[$type] = $r;
            }
        }

        return array_values($byType);
    }

    public static function defaultThreshold(string $ruleType): ?float
    {
        switch ($ruleType) {
            case 'budget_exhausted':
                return 90.0;   // % من الميزانية اليومية المتصرفة
            case 'cpc_spike':
                return 200.0;  // % زيادة في متوسط تكلفة النقرة مقارنة بالأسبوع اللي قبله
            case 'ctr_drop':
                return 50.0;   // % انخفاض في نسبة النقر مقارنة بالأسبوع اللي قبله
            case 'landing_page_down':
                return null;   // بيتم الفحص مباشرة (لا توجد نسبة)
            case 'budget_pacing':
                return 75.0;   // % من اليوم اللي فات والإنفاق أقل من نصف الميزانية اليومية
        }
        return null;
    }
}
