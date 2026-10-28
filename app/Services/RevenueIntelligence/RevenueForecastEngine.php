<?php

/**
 * Tourfecto - Revenue Forecast Engine (PRO)
 * @version 2.0.0
 *
 * محرك تنبؤ متعدد النماذج مع اختيار تلقائي بالاختبار الرجعي (Backtesting)
 * - نفس فكرة منصات Revenue Management الاحترافية: بدل ما نفترض إن
 * نموذج واحد مناسب لكل نشاط، بنجرّب كذا نموذج على بيانات العميل نفسه
 * ونختار اللي كان أدق فعليًا على آخر أسابيع محجوبة عن التدريب.
 *
 * النماذج:
 *   - linear              : انحدار خطي (نفس النموذج القديم لكن على سلسلة كثيفة بالأيام الصفرية)
 *   - seasonal_naive_week : متوسط نفس يوم الأسبوع آخر 4 أسابيع (نمط الأسبوع في السياحة)
 *   - holt_winters        : Holt-Winters جمعي بموسمية أسبوعية + اتجاه مخمَّد (Damped)
 *   - yoy_seasonal        : نفس الفترة من السنة الماضية × معدل النمو الحالي (موسمية السنة:
 *                           الشتا/الصيف/الأعياد) - يشتغل فقط مع 13+ شهر تاريخ
 *
 * المقياس: WAPE = Σ|خطأ| / Σ فعلي (أنسب من MAPE لأن فيه أيام إيرادها صفر).
 *
 * مبدأ الموديول ثابت: لو البيانات مش كفاية -> insufficient_data صريح، والـ
 * RevenueForecastService يرجع للنموذج الأساسي. كل الدوال Pure (بدون I/O).
 */
class RevenueForecastEngine
{
    public const MIN_SPAN_DAYS = 28;
    public const MIN_NONZERO_DAYS = 10;

    /**
     * يحوّل سلسلة متفرقة (أيام فيها سجلات بس) لسلسلة يومية كثيفة من أول
     * تاريخ لحد $toDate شاملًا، والأيام الناقصة = 0. ده تصحيح مهم: الانحدار
     * القديم كان بيتجاهل الأيام اللي مفيهاش إيراد فبيبالغ في التوقع.
     *
     * @param array $series [['date'=>'Y-m-d','revenue'=>float], ...]
     * @return array<string, float> date => revenue
     */
    public static function densify(array $series, string $toDate, ?string $fromDate = null): array
    {
        $map = [];
        foreach ($series as $p) {
            if (!isset($p['date'])) {
                continue;
            }
            $d = substr((string) $p['date'], 0, 10);
            $map[$d] = ($map[$d] ?? 0.0) + (float) ($p['revenue'] ?? 0);
        }
        if (empty($map) && $fromDate === null) {
            return [];
        }
        ksort($map);
        $start = new DateTimeImmutable($fromDate ?? array_key_first($map));
        $end = new DateTimeImmutable($toDate);
        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $out[$k] = $map[$k] ?? 0.0;
        }
        return $out;
    }

    /**
     * التنبؤ لعدد أيام قادمة مع اختيار أفضل نموذج.
     *
     * @param array<string,float> $dense سلسلة كثيفة (من densify) تنتهي عند "النهارده"
     */
    public static function forecast(array $dense, int $horizon): array
    {
        $horizon = max(1, $horizon);
        $ys = array_values(array_map('floatval', $dense));
        $dates = array_keys($dense);
        $n = count($ys);
        $nonZero = count(array_filter($ys, static function ($v) {
            return $v != 0.0;
        }));

        if ($n < self::MIN_SPAN_DAYS || $nonZero < self::MIN_NONZERO_DAYS) {
            return [
                'insufficient_data' => true,
                'message' => 'Not enough data for the advanced forecast engine.',
                'span_days' => $n,
                'non_zero_days' => $nonZero,
            ];
        }

        // Backtest: نحجب آخر H يوم (بحد أقصى 28 وبحد أدنى 7) ونقيس دقة كل نموذج
        $holdout = (int) max(7, min(28, $horizon, intdiv($n, 4)));
        $train = array_slice($ys, 0, $n - $holdout);
        $actual = array_slice($ys, $n - $holdout);
        $trainEndDate = $dates[$n - $holdout - 1];

        $models = self::availableModels(count($train));
        $scores = [];
        $residuals = [];
        foreach ($models as $model) {
            $pred = self::predict($model, $train, $holdout, $trainEndDate);
            if ($pred === null) {
                continue;
            }
            $scores[$model] = self::wape($actual, $pred);
            $residuals[$model] = array_map(static function ($a, $p) {
                return $a - $p;
            }, $actual, $pred);
        }
        if (empty($scores)) {
            return ['insufficient_data' => true, 'message' => 'No model could be evaluated on this history.', 'span_days' => $n, 'non_zero_days' => $nonZero];
        }
        asort($scores);
        $best = array_key_first($scores);

        $daily = self::predict($best, $ys, $horizon, $dates[$n - 1]);
        if ($daily === null) {
            return ['insufficient_data' => true, 'message' => 'Selected model could not produce a forecast.', 'span_days' => $n, 'non_zero_days' => $nonZero];
        }
        $expected = array_sum($daily);

        // نطاق 80%: من انحراف أخطاء الاختبار الرجعي اليومية، مع حد أدنى = WAPE × التوقع
        $res = $residuals[$best];
        $mean = array_sum($res) / max(1, count($res));
        $var = 0.0;
        foreach ($res as $r) {
            $var += ($r - $mean) ** 2;
        }
        $sd = count($res) > 1 ? sqrt($var / (count($res) - 1)) : 0.0;
        $margin = max(1.2816 * $sd * sqrt($horizon), $scores[$best] * $expected);
        $wape = $scores[$best];

        $start = new DateTimeImmutable($dates[$n - 1]);
        $points = [];
        foreach ($daily as $i => $v) {
            $points[] = ['date' => $start->modify('+' . ($i + 1) . ' day')->format('Y-m-d'), 'revenue' => round($v, 2)];
        }

        $roundedScores = [];
        foreach ($scores as $m => $sc) {
            $roundedScores[$m] = round($sc, 4);
        }

        return [
            'insufficient_data' => false,
            'model' => $best,
            'expected_revenue' => round($expected, 2),
            'forecast_range' => ['low' => round(max(0.0, $expected - $margin), 2), 'high' => round($expected + $margin, 2)],
            'interval' => '80%',
            'backtest' => [
                'holdout_days' => $holdout,
                'wape' => round($wape, 4),
                'accuracy_percent' => round(max(0.0, 1 - $wape) * 100, 1),
                'models_compared' => $roundedScores,
            ],
            'confidence' => $wape <= 0.2 ? 'high' : ($wape <= 0.4 ? 'medium' : 'low'),
            'daily_forecast' => $points,
            'span_days' => $n,
            'non_zero_days' => $nonZero,
        ];
    }

    /** النماذج المتاحة حسب طول التاريخ. Pure. */
    public static function availableModels(int $trainLength): array
    {
        $models = ['linear', 'seasonal_naive_week'];
        if ($trainLength >= 21) {
            $models[] = 'holt_winters';
        }
        if ($trainLength >= 365 + 28) {
            $models[] = 'yoy_seasonal';
        }
        return $models;
    }

    /** WAPE. Pure. */
    public static function wape(array $actual, array $pred): float
    {
        $sumAbs = 0.0;
        $sumAct = 0.0;
        foreach ($actual as $i => $a) {
            $sumAbs += abs($a - ($pred[$i] ?? 0.0));
            $sumAct += abs($a);
        }
        if ($sumAct <= 0) {
            return $sumAbs > 0 ? 1.0 : 0.0;
        }
        return $sumAbs / $sumAct;
    }

    /** @return float[]|null */
    public static function predict(string $model, array $ys, int $h, string $lastDate): ?array
    {
        switch ($model) {
            case 'linear':
                return self::predictLinear($ys, $h);
            case 'seasonal_naive_week':
                return self::predictSeasonalNaive($ys, $h, 7, 4);
            case 'holt_winters':
                return self::predictHoltWinters($ys, $h, 7);
            case 'yoy_seasonal':
                return self::predictYoY($ys, $h);
        }
        return null;
    }

    public static function predictLinear(array $ys, int $h): ?array
    {
        $n = count($ys);
        if ($n < 2) {
            return null;
        }
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($ys) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($ys as $i => $y) {
            $num += ($i - $meanX) * ($y - $meanY);
            $den += ($i - $meanX) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $intercept = $meanY - $slope * $meanX;
        $out = [];
        for ($k = 0; $k < $h; $k++) {
            $out[] = max(0.0, $intercept + $slope * ($n + $k));
        }
        return $out;
    }

    public static function predictSeasonalNaive(array $ys, int $h, int $m = 7, int $cycles = 4): ?array
    {
        $n = count($ys);
        if ($n < $m) {
            return null;
        }
        $out = [];
        for ($k = 0; $k < $h; $k++) {
            $vals = [];
            for ($c = 1; $c <= $cycles; $c++) {
                // نفس يوم الأسبوع: أقرب $cycles أسابيع متاحة فعلًا في التاريخ
                $idx = $n + $k - $m * ((int) ceil(($k + 1) / $m) + $c - 1);
                if ($idx >= 0 && $idx < $n) {
                    $vals[] = $ys[$idx];
                }
            }
            $out[] = empty($vals) ? 0.0 : max(0.0, array_sum($vals) / count($vals));
        }
        return $out;
    }

    /** Holt-Winters جمعي + اتجاه مخمَّد، مع اختيار المعاملات بأقل خطأ خطوة واحدة. */
    public static function predictHoltWinters(array $ys, int $h, int $m = 7): ?array
    {
        $n = count($ys);
        if ($n < 3 * $m) {
            return null;
        }
        $best = null;
        foreach ([0.1, 0.3, 0.5] as $alpha) {
            foreach ([0.01, 0.1] as $beta) {
                foreach ([0.05, 0.2, 0.4] as $gamma) {
                    $fit = self::hwFit($ys, $m, $alpha, $beta, $gamma, 0.9);
                    if ($best === null || $fit['sse'] < $best['sse']) {
                        $best = $fit;
                    }
                }
            }
        }
        $out = [];
        $phi = 0.9;
        $damp = 0.0;
        for ($k = 1; $k <= $h; $k++) {
            $damp += $phi ** $k;
            $season = $best['season'][($n + $k - 1) % $m];
            $out[] = max(0.0, $best['level'] + $damp * $best['trend'] + $season);
        }
        return $out;
    }

    private static function hwFit(array $ys, int $m, float $alpha, float $beta, float $gamma, float $phi): array
    {
        $first = array_slice($ys, 0, $m);
        $second = array_slice($ys, $m, $m);
        $level = array_sum($first) / $m;
        $trend = (array_sum($second) / $m - $level) / $m;
        $season = [];
        for ($i = 0; $i < $m; $i++) {
            $season[$i] = $first[$i] - $level;
        }
        $sse = 0.0;
        $n = count($ys);
        for ($t = $m; $t < $n; $t++) {
            $s = $season[$t % $m];
            $forecast = $level + $phi * $trend + $s;
            $sse += ($ys[$t] - $forecast) ** 2;
            $prevLevel = $level;
            $level = $alpha * ($ys[$t] - $s) + (1 - $alpha) * ($prevLevel + $phi * $trend);
            $trend = $beta * ($level - $prevLevel) + (1 - $beta) * $phi * $trend;
            $season[$t % $m] = $gamma * ($ys[$t] - $level) + (1 - $gamma) * $s;
        }
        return ['level' => $level, 'trend' => $trend, 'season' => $season, 'sse' => $sse];
    }

    /**
     * نفس الأيام من السنة الماضية × نسبة النمو (آخر 90 يوم ÷ نفس الـ90 يوم
     * السنة الماضية، محصورة بين 0.25 و 4 لمنع القيم الشاذة).
     */
    public static function predictYoY(array $ys, int $h): ?array
    {
        $n = count($ys);
        $window = 90;
        if ($n < 365 + $window) {
            return null;
        }
        $recent = array_sum(array_slice($ys, $n - $window, $window));
        $lastYear = array_sum(array_slice($ys, $n - 365 - $window, $window));
        if ($lastYear <= 0) {
            return null;
        }
        $growth = max(0.25, min(4.0, $recent / $lastYear));
        $out = [];
        for ($k = 0; $k < $h; $k++) {
            $idx = $n + $k - 365;
            $out[] = ($idx >= 0 && $idx < $n) ? max(0.0, $ys[$idx] * $growth) : 0.0;
        }
        return $out;
    }

    /** وصف عربي/إنجليزي مختصر للنموذج (للواجهة). */
    public static function modelLabel(string $model, string $lang = 'ar'): string
    {
        $ar = [
            'linear' => 'اتجاه خطي',
            'seasonal_naive_week' => 'نمط أيام الأسبوع',
            'holt_winters' => 'Holt-Winters (موسمية أسبوعية + اتجاه)',
            'yoy_seasonal' => 'موسمية سنوية (نفس الفترة السنة الماضية × النمو)',
        ];
        $en = [
            'linear' => 'Linear trend',
            'seasonal_naive_week' => 'Day-of-week pattern',
            'holt_winters' => 'Holt-Winters (weekly seasonality + damped trend)',
            'yoy_seasonal' => 'Year-over-year seasonality × current growth',
        ];
        return ($lang === 'en' ? $en : $ar)[$model] ?? $model;
    }
}
