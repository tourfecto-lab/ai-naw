<?php

/**
 * Tourfecto - Deal Win Probability Model (learned from history)
 * @version 2.0.0
 *
 * بدل احتمالية ثابتة لكل مرحلة في الـ CRM، النموذج ده بيتعلّم من صفقات
 * الحساب نفسه اللي اتقفلت (won/lost):
 *
 *   1) شريحة القيمة (Value band): الصفقات الكبيرة غالبًا نسبة فوزها مختلفة.
 *   2) عمر الصفقة (Survival-conditioned): صفقة مفتوحة بقالها A يوم، نحسب
 *      نسبة الفوز بين الصفقات المقفولة اللي فضلت مفتوحة A يوم على الأقل -
 *      ده بيلقط إن الصفقات اللي "بتطوّل" احتمال فوزها بيقل.
 *   3) Bayesian smoothing هرمي: شريحة+عمر -> شريحة -> المعدل العام، عشان
 *      العينات الصغيرة ماتطلعش أرقام متطرفة.
 *   4) المزج مع احتمالية المرحلة بوزن يكبر مع حجم بيانات التدريب.
 *
 * التقييم: Brier score على أحدث 30% من الصفقات (مدرّب على الأقدم فقط) مقابل
 * خط أساس "المعدل العام" - لو النموذج مش أحسن من الأساس بنقول كده صراحة.
 * Pure functions بالكامل.
 */
class DealWinModelService
{
    public const MIN_TRAINING = 10;
    private const PRIOR_STRENGTH = 5.0;

    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function scoreOpenDeals(int $userId): array
    {
        $closed = $this->gateway->getClosedDealsForModel($userId);
        $open = $this->gateway->getDeals($userId, 'open');
        return self::score(self::train($closed), $open, date('Y-m-d'));
    }

    /** Pure: تدريب النموذج من الصفقات المقفولة. */
    public static function train(array $closedDeals): array
    {
        $rows = [];
        foreach ($closedDeals as $d) {
            if (!in_array($d['status'] ?? '', ['won', 'lost'], true) || empty($d['created_at']) || empty($d['closed_at'])) {
                continue;
            }
            $rows[] = [
                'won' => $d['status'] === 'won' ? 1 : 0,
                'value' => (float) ($d['value'] ?? 0),
                'cycle' => max(0, (int) floor((strtotime((string) $d['closed_at']) - strtotime((string) $d['created_at'])) / 86400)),
                'closed_at' => (string) $d['closed_at'],
            ];
        }
        if (count($rows) < self::MIN_TRAINING) {
            return ['trained' => false, 'training_size' => count($rows), 'reason' => 'Need at least ' . self::MIN_TRAINING . ' closed (won/lost) deals to learn win probability.'];
        }
        $model = self::fitRows($rows);
        $model['evaluation'] = self::evaluate($rows);
        return $model;
    }

    /** Pure: بناء النموذج من صفوف مطبّعة (won/value/cycle/closed_at). */
    public static function fitRows(array $rows): array
    {
        $n = count($rows);
        $values = array_column($rows, 'value');
        sort($values);
        $q = static function (float $p) use ($values) {
            return $values[(int) floor($p * (count($values) - 1))];
        };
        $wins = array_sum(array_column($rows, 'won'));
        $wonCycles = [];
        foreach ($rows as $r) {
            if ($r['won']) {
                $wonCycles[] = $r['cycle'];
            }
        }
        sort($wonCycles);
        return [
            'trained' => true,
            'training_size' => $n,
            'wins' => $wins,
            'global_win_rate' => round(($wins + 1) / ($n + 2), 4),
            'value_bands' => [$q(0.25), $q(0.5), $q(0.75)],
            'rows' => $rows,
            'median_won_cycle_days' => empty($wonCycles) ? null : $wonCycles[intdiv(count($wonCycles), 2)],
        ];
    }

    /** Pure: شريحة القيمة (0..3). */
    public static function band(float $value, array $bands): int
    {
        $b = 0;
        foreach ($bands as $edge) {
            if ($value > $edge) {
                $b++;
            }
        }
        return $b;
    }

    /** Pure: احتمالية الفوز لصفقة مفتوحة (قيمة + عمر). */
    public static function predict(array $model, float $value, int $ageDays): float
    {
        $rows = $model['rows'];
        $global = (float) $model['global_win_rate'];
        $band = self::band($value, $model['value_bands']);
        $k = self::PRIOR_STRENGTH;

        $bw = 0;
        $bn = 0;
        $aw = 0;
        $an = 0;
        foreach ($rows as $r) {
            if (self::band($r['value'], $model['value_bands']) !== $band) {
                continue;
            }
            $bn++;
            $bw += $r['won'];
            if ($r['cycle'] >= $ageDays) {
                $an++;
                $aw += $r['won'];
            }
        }
        $bandRate = ($bw + $k * $global) / ($bn + $k);
        $ageRate = ($aw + $k * $bandRate) / ($an + $k);

        // صفقة عمرها أطول من كل الصفقات المكسوبة تقريبًا: لو مفيش ولا صفقة
        // بالعمر ده اتكسبت، الاحتمالية بتنزل للنصف (إشارة "متعثرة") بدل ما تفضل كما هي.
        if ($an === 0 && $ageDays > 0) {
            $ageRate = $bandRate * 0.5;
        }
        return max(0.01, min(0.99, $ageRate));
    }

    /** Pure: Brier score للنموذج مقابل خط الأساس على أحدث 30%. */
    public static function evaluate(array $rows): array
    {
        usort($rows, static function ($a, $b) {
            return strcmp($a['closed_at'], $b['closed_at']);
        });
        $n = count($rows);
        $cut = (int) floor($n * 0.7);
        if ($cut < self::MIN_TRAINING - 3 || $n - $cut < 3) {
            return ['evaluated' => false, 'reason' => 'Not enough closed deals for a holdout evaluation.'];
        }
        $train = array_slice($rows, 0, $cut);
        $test = array_slice($rows, $cut);
        $m = self::fitRows($train);
        $base = array_sum(array_column($train, 'won')) / max(1, count($train));
        $bm = 0.0;
        $bb = 0.0;
        foreach ($test as $r) {
            // نقيّم وقت فتح الصفقة (العمر = 0) - نفس معلومة البائع وقت التسجيل
            $p = self::predict($m, $r['value'], 0);
            $bm += ($p - $r['won']) ** 2;
            $bb += ($base - $r['won']) ** 2;
        }
        $bm /= count($test);
        $bb /= count($test);
        return [
            'evaluated' => true,
            'test_size' => count($test),
            'brier_model' => round($bm, 4),
            'brier_baseline' => round($bb, 4),
            'beats_baseline' => $bm < $bb,
        ];
    }

    /** Pure: تسجيل الصفقات المفتوحة. */
    public static function score(array $model, array $openDeals, string $today): array
    {
        if (empty($model['trained'])) {
            return ['has_data' => false, 'model' => self::publicModel($model), 'deals' => [], 'message' => $model['reason'] ?? 'Not enough data.'];
        }
        $weight = $model['training_size'] / ($model['training_size'] + 20);
        $deals = [];
        $weightedStage = 0.0;
        $weightedModel = 0.0;
        foreach ($openDeals as $d) {
            $value = (float) ($d['value'] ?? 0);
            $age = !empty($d['created_at']) ? max(0, (int) floor((strtotime($today) - strtotime(substr((string) $d['created_at'], 0, 10))) / 86400)) : 0;
            $stageP = $d['probability'] !== null && $d['probability'] !== '' && (float) $d['probability'] > 0
                ? (float) $d['probability'] / 100
                : ((float) ($d['stage_win_probability'] ?? 0) / 100);
            $modelP = self::predict($model, $value, $age);
            $final = $stageP > 0 ? $weight * $modelP + (1 - $weight) * $stageP : $modelP;
            $weightedStage += $value * $stageP;
            $weightedModel += $value * $final;
            $deals[] = [
                'deal_id' => (int) $d['id'],
                'title' => (string) ($d['title'] ?? ''),
                'contact_name' => (string) ($d['contact_name'] ?? ''),
                'value' => round($value, 2),
                'age_days' => $age,
                'stage' => (string) ($d['stage_name'] ?? ''),
                'stage_probability' => round($stageP * 100, 1),
                'learned_probability' => round($final * 100, 1),
                'delta_points' => round(($final - $stageP) * 100, 1),
                'stalled' => $model['median_won_cycle_days'] !== null && $age > 2 * max(1, (int) $model['median_won_cycle_days']),
            ];
        }
        usort($deals, static function ($a, $b) {
            return abs($b['delta_points']) <=> abs($a['delta_points']);
        });
        return [
            'has_data' => true,
            'model' => self::publicModel($model),
            'blend_weight' => round($weight, 3),
            'weighted_pipeline_stage' => round($weightedStage, 2),
            'weighted_pipeline_learned' => round($weightedModel, 2),
            'deals' => $deals,
        ];
    }

    private static function publicModel(array $model): array
    {
        unset($model['rows']);
        return $model;
    }
}
