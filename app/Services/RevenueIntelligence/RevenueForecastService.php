<?php

/**
 * Tourfecto - Revenue Forecast Service
 * @version 1.1.0
 *
 * Section 2: REVENUE FORECASTING
 *
 * أسلوب متعمّد بسيط وشفاف (Simple Linear Regression على سلسلة إيراد
 * يومية حقيقية) بدل "AI بلاك بوكس" لا يمكن تفسيره - كل رقم في المخرجات
 * قابل لإعادة الاشتقاق من evidence. النتيجة دائمًا موصوفة كـ Estimated/
 * Forecast مع Confidence، وليست حقيقة (كما ينص section 2 صراحةً).
 *
 * لو البيانات غير كافية لموثوقية معقولة، نرجّع رسالة واضحة:
 * "Not enough data for reliable forecast." - لا نخترع أرقام.
 */
class RevenueForecastService
{
    /** أقل عدد أيام فيها إيراد فعلي مسجّل عشان نحاول Forecast أصلاً. */
    public const MIN_DATA_POINTS = 10;

    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /**
     * يولّد توقع إيراد للفترة القادمة (نفس طول period_type) بناءً على
     * آخر 90 يوم من بيانات فعلية.
     */
    public function forecast(int $userId, string $periodType = 'monthly', bool $persist = true): array
    {
        $lookbackDays = 90;
        $now = new DateTime('now');
        $from = (clone $now)->modify("-{$lookbackDays} days");
        // v2.0: تاريخ أطول (سنة + 90 يوم) للمحرك المتقدم عشان الموسمية السنوية
        $longFrom = (clone $now)->modify('-' . (365 + 120) . ' days');
        $longSeries = $this->gateway->getDailyRevenueSeries($userId, $longFrom->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));
        $fromStr = $from->format('Y-m-d');
        $series = array_values(array_filter($longSeries, static function ($p) use ($fromStr) {
            return (string) $p['date'] >= $fromStr;
        }));

        $result = self::applyAdvancedEngine(
            self::computeForecast($series, $periodType, $now->format('Y-m-d')),
            $longSeries,
            $periodType,
            $now->format('Y-m-d')
        );

        if ($persist) {
            try {
                $forecast = new RevaiForecast([
                    'user_id' => $userId,
                    'period_type' => $periodType,
                    'period_start' => $result['period']['from'],
                    'period_end' => $result['period']['to'],
                    'expected_revenue' => $result['expected_revenue'],
                    'low_estimate' => $result['forecast_range']['low'] ?? null,
                    'high_estimate' => $result['forecast_range']['high'] ?? null,
                    'confidence' => $result['confidence'],
                    'growth_trend' => $result['growth_trend'],
                    'method' => $result['method'] ?? 'linear_regression_daily',
                    'data_points_used' => $result['data_points_used'],
                    'insufficient_data' => $result['insufficient_data'] ? 1 : 0,
                ]);
                $forecast->save();
            } catch (Exception $e) {
                if (class_exists('Logger')) {
                    Logger::error('RevenueForecastService: failed to persist forecast', ['message' => $e->getMessage()]);
                }
            }
        }

        // نضيف السلسلة اليومية التاريخية اللي اتحسب منها التوقع - عشان
        // الواجهة تقدر ترسم Chart واحد يوضح "التاريخي + المتوقع" مع بعض،
        // بدل ما تحتاج تعمل طلب تاني منفصل لنفس البيانات.
        $result['historical_series'] = array_map(static function ($p) {
            return ['date' => $p['date'], 'revenue' => $p['revenue']];
        }, $series);

        return $result;
    }

    /**
     * v2.0: يطبّق RevenueForecastEngine (اختيار أفضل نموذج بالاختبار الرجعي)
     * فوق التوقع الأساسي. لو المحرك مش لاقي بيانات كفاية يفضل الأساسي كما
     * هو تمامًا. التوقع الأساسي بيتحفظ في 'baseline' للشفافية. Pure.
     */
    public static function applyAdvancedEngine(array $baseResult, array $longSeries, string $periodType, string $todayStr): array
    {
        if (!class_exists('RevenueForecastEngine') || empty($longSeries)) {
            return $baseResult;
        }
        $horizon = RevenueOverviewService::periodToDays($periodType);
        $dense = RevenueForecastEngine::densify($longSeries, $todayStr);
        $adv = RevenueForecastEngine::forecast($dense, $horizon);
        if (!empty($adv['insufficient_data'])) {
            $baseResult['engine'] = ['used' => false, 'reason' => $adv['message'] ?? null];
            return $baseResult;
        }
        $baseline = [
            'method' => $baseResult['method'] ?? 'linear_regression_daily',
            'expected_revenue' => $baseResult['expected_revenue'] ?? null,
            'forecast_range' => $baseResult['forecast_range'] ?? null,
            'confidence' => $baseResult['confidence'] ?? null,
        ];
        $baseResult['insufficient_data'] = false;
        $baseResult['message'] = null;
        $baseResult['method'] = 'engine_' . $adv['model'];
        $baseResult['model_label'] = RevenueForecastEngine::modelLabel($adv['model']);
        $baseResult['expected_revenue'] = $adv['expected_revenue'];
        $baseResult['forecast_range'] = $adv['forecast_range'];
        $baseResult['confidence'] = $adv['confidence'];
        $baseResult['backtest'] = $adv['backtest'];
        $baseResult['daily_forecast'] = $adv['daily_forecast'];
        $baseResult['baseline'] = $baseline;
        $baseResult['engine'] = ['used' => true, 'span_days' => $adv['span_days'], 'interval' => $adv['interval']];
        if (empty($baseResult['growth_trend'])) {
            $baseResult['growth_trend'] = 'flat';
        }
        $baseResult['note'] = 'Model auto-selected by backtesting on your own recent history. Estimated, not guaranteed.';
        return $baseResult;
    }

    /**
     * منطق الحساب الصِرف (Pure function) - بدون أي وصول لقاعدة بيانات،
     * عشان يكون قابل للاختبار مباشرة بـ Fixtures ثابتة (section 22).
     *
     * @param array $dailySeries [['date' => 'Y-m-d', 'revenue' => float], ...] مرتبة تصاعديًا
     * @param string $periodType daily/weekly/monthly/quarterly/yearly
     * @param string $todayStr Y-m-d - نقطة الانطلاق لحساب فترة التوقع القادمة
     */
    public static function computeForecast(array $dailySeries, string $periodType, string $todayStr): array
    {
        $futureDays = RevenueOverviewService::periodToDays($periodType);
        $today = new DateTime($todayStr);
        $periodFrom = (clone $today)->modify('+1 day');
        $periodTo = (clone $today)->modify('+' . $futureDays . ' days');

        $points = array_values(array_filter($dailySeries, static function ($p) {
            return isset($p['revenue']);
        }));
        $n = count($points);

        $base = [
            'period_type' => $periodType,
            'period' => ['from' => $periodFrom->format('Y-m-d'), 'to' => $periodTo->format('Y-m-d')],
            'data_points_used' => $n,
            'method' => 'linear_regression_daily',
        ];

        if ($n < self::MIN_DATA_POINTS) {
            return $base + [
                'insufficient_data' => true,
                'message' => 'Not enough data for reliable forecast.',
                'expected_revenue' => null,
                'forecast_range' => ['low' => null, 'high' => null],
                'confidence' => null,
                'growth_trend' => null,
            ];
        }

        // انحدار خطي بسيط (least squares) على y=revenue اليومي مقابل x=index اليوم.
        $xs = range(0, $n - 1);
        $ys = array_map(static function ($p) {
            return (float) $p['revenue'];
        }, $points);

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $num = 0.0;
        $den = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $meanX) * ($ys[$i] - $meanY);
            $den += ($x - $meanX) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $intercept = $meanY - $slope * $meanX;

        // معامل التحديد R^2 لقياس مدى ثبات/انتظام الاتجاه (يستخدم لتحديد Confidence)
        $ssTot = 0.0;
        $ssRes = 0.0;
        foreach ($xs as $i => $x) {
            $predicted = $intercept + $slope * $x;
            $ssRes += ($ys[$i] - $predicted) ** 2;
            $ssTot += ($ys[$i] - $meanY) ** 2;
        }
        $rSquared = $ssTot > 0 ? max(0.0, 1 - ($ssRes / $ssTot)) : 0.0;

        // توقع مجموع الإيراد لأيام الفترة القادمة عن طريق جمع القيم المتوقعة يوم بيوم
        $expectedTotal = 0.0;
        for ($d = 0; $d < $futureDays; $d++) {
            $x = $n + $d;
            $expectedTotal += max(0.0, $intercept + $slope * $x);
        }

        // نطاق التوقع: نستخدم الانحراف المعياري للأخطاء (residuals) كمقياس عدم يقين،
        // ونوسّعه تناسبيًا مع طول فترة التوقع.
        $variance = $n > 1 ? $ssRes / ($n - 1) : 0.0;
        $stdError = sqrt(max(0.0, $variance));
        $uncertainty = $stdError * sqrt($futureDays) * 1.5; // هامش تحفظي

        $low = max(0.0, $expectedTotal - $uncertainty);
        $high = $expectedTotal + $uncertainty;

        // Confidence: تجمع بين عدد نقاط البيانات وانتظام الاتجاه (R^2).
        if ($n >= 45 && $rSquared >= 0.4) {
            $confidence = 'high';
        } elseif ($n >= 20 && $rSquared >= 0.15) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        $growthTrend = 'flat';
        if ($meanY > 0) {
            $relativeSlope = ($slope * $n) / max($meanY, 0.01);
            if ($relativeSlope > 0.1) {
                $growthTrend = 'up';
            } elseif ($relativeSlope < -0.1) {
                $growthTrend = 'down';
            }
        }

        return $base + [
            'insufficient_data' => false,
            'message' => null,
            'expected_revenue' => round($expectedTotal, 2),
            'forecast_range' => ['low' => round($low, 2), 'high' => round($high, 2)],
            'confidence' => $confidence,
            'growth_trend' => $growthTrend,
            'r_squared' => round($rSquared, 3),
            'note' => 'Estimated forecast derived from historical trend. Not a guaranteed outcome.',
        ];
    }

    /**
     * What-if Scenario (ميزة تنافسية - زي ChartMogul "Explore future
     * scenarios"): بياخد نفس الـ Forecast التاريخي الحقيقي وبيطبّق عليه
     * نسبة نمو مفترضة (growthPercent) ليعرض "لو حصل كذا، الإيراد المتوقع
     * هيبقى كام". الرقم الأساسي مش مخترع - مبني على سلسلة إيراد فعلية.
     * Pure function قابلة للاختبار مباشرة.
     *
     * @param array $dailySeries [['date'=>Y-m-d,'revenue'=>float], ...] (نفس شكل computeForecast)
     * @param string $periodType daily/weekly/monthly/quarterly/yearly
     * @param string $todayStr Y-m-d
     * @param float $growthPercent نسبة التغيير المفترضة (20 = زيادة 20%)
     */
    public static function scenarioForecast(array $dailySeries, string $periodType, string $todayStr, float $growthPercent = 0.0): array
    {
        $base = self::computeForecast($dailySeries, $periodType, $todayStr);
        if ($base['insufficient_data']) {
            return $base + ['scenario' => true, 'scenario_growth_percent' => $growthPercent];
        }

        $factor = 1 + ($growthPercent / 100);
        return [
            'scenario' => true,
            'scenario_growth_percent' => round($growthPercent, 2),
            'base_expected_revenue' => $base['expected_revenue'],
            'expected_revenue' => round($base['expected_revenue'] * $factor, 2),
            'forecast_range' => [
                'low' => round($base['forecast_range']['low'] * $factor, 2),
                'high' => round($base['forecast_range']['high'] * $factor, 2),
            ],
            'confidence' => $base['confidence'],
            'period' => $base['period'],
            'data_points_used' => $base['data_points_used'],
            'method' => 'linear_regression_daily_with_growth_scenario',
            'note' => 'Scenario estimate: base forecast scaled by the assumed growth percentage. Not a guarantee.',
        ];
    }

    /**
     * Seasonality adjustment (v1.3.0): مقارنة فترة سابقة مكافئة بنفس الطول
     * من السلسلة التاريخية الحقيقية لاكتشاف ما إذا كانت الفترة الحالية
     * موسميًا أعلى أو أقل من المعتاد (مثل موسم حجوزات الصيف). الناتج
     * factor > 1 يعني أن الفترة الحالية فوق المتوسط الموسمي وfactor < 1
     * تحت المتوسط - يُطبَّق على التوقع الأساسي. Pure function قابلة
     * للاختبار مباشرة، وتعتمد فقط على بيانات فعلية (لا اختراع).
     *
     * ملاحظة صادقة: هذا مجرّد "مقارنة بنفس الفترة السابقة المكافئة"،
     * وليس نموذج موسمية كامل (يتطلب سنوات متعددة من التاريخ) - نصرّح
     * بذلك في المخرجات بدل تقديمه كـ"موسمية حقيقية".
     *
     * @param array $dailySeries [['date' => 'Y-m-d', 'revenue' => float], ...]
     * @param string $periodType daily/weekly/monthly/quarterly/yearly
     * @param string $todayStr Y-m-d
     */
    public static function computeSeasonalFactor(array $dailySeries, string $periodType, string $todayStr): array
    {
        $days = RevenueOverviewService::periodToDays($periodType);
        $today = new DateTime($todayStr);
        $currentStart = (clone $today)->modify("-{$days} days");
        $previousStart = (clone $currentStart)->modify("-{$days} days");

        $currentDaily = self::dailyAverages($dailySeries, $currentStart->format('Y-m-d'), $today->format('Y-m-d'));
        $previousDaily = self::dailyAverages($dailySeries, $previousStart->format('Y-m-d'), $currentStart->format('Y-m-d'));

        if ($previousDaily <= 0) {
            return [
                'seasonal_factor' => null,
                'has_seasonality' => false,
                'reason' => 'Not enough data',
                'current_period_daily_avg' => $currentDaily,
                'previous_period_daily_avg' => $previousDaily,
            ];
        }

        $factor = round($currentDaily / $previousDaily, 3);
        return [
            'seasonal_factor' => $factor,
            // نعتبرها "موسمية ملحوظة" فقط إذا انحرفت الفترة الحالية بأكثر من
            // 20% عن سابقتها - أي أقل من كده هو تشويش عادي مش إشارة موسمية.
            'has_seasonality' => $factor < 0.8 || $factor > 1.2,
            'reason' => null,
            'current_period_daily_avg' => round($currentDaily, 2),
            'previous_period_daily_avg' => round($previousDaily, 2),
            'note' => 'Simple same-length prior-period comparison, not a full multi-year seasonal model.',
        ];
    }

    /**
     * توقع مراعٍ للموسمية (v1.3.0): التوقع الخطي الأساسي × عامل الموسمية
     * المستخرج من الفترة المكافئة السابقة. لو الموسمية غير ملحوظة، الناتج
     * مطابق للتوقع الأساسي مع factor=1. Pure function قابلة للاختبار.
     */
    public static function seasonalForecast(array $dailySeries, string $periodType, string $todayStr): array
    {
        $base = self::computeForecast($dailySeries, $periodType, $todayStr);
        $seasonal = self::computeSeasonalFactor($dailySeries, $periodType, $todayStr);

        if ($base['insufficient_data'] || $seasonal['seasonal_factor'] === null) {
            return $base + [
                'seasonal' => false,
                'seasonal_factor' => $seasonal['seasonal_factor'],
                'seasonality_note' => 'Not enough data to apply a seasonal adjustment.',
            ];
        }

        $factor = $seasonal['seasonal_factor'];
        return array_merge($base, [
            'seasonal' => $seasonal['has_seasonality'],
            'seasonal_factor' => $factor,
            'expected_revenue' => round($base['expected_revenue'] * $factor, 2),
            'forecast_range' => [
                'low' => round($base['forecast_range']['low'] * $factor, 2),
                'high' => round($base['forecast_range']['high'] * $factor, 2),
            ],
            'method' => 'linear_regression_daily_with_seasonality',
            'seasonality_note' => $seasonal['has_seasonality']
                ? 'Current period is ' . ($factor > 1 ? 'above' : 'below') . ' the prior equivalent period by ' . abs(round(($factor - 1) * 100, 1)) . '%; forecast adjusted accordingly. Simple prior-period comparison, not a full multi-year seasonal model.'
                : 'No meaningful seasonality detected vs the prior equivalent period; forecast left unchanged.',
        ]);
    }

    /** متوسط الإيراد اليومي ضمن نافذة زمنية من سلسلة يومية فعلية. */
    private static function dailyAverages(array $dailySeries, string $fromDate, string $toDate): float
    {
        $total = 0.0;
        $count = 0;
        foreach ($dailySeries as $p) {
            $d = (string) ($p['date'] ?? '');
            if ($d >= $fromDate && $d < $toDate && isset($p['revenue'])) {
                $total += (float) $p['revenue'];
                $count++;
            }
        }
        return $count > 0 ? $total / $count : 0.0;
    }

    /**
     * Forecast Accuracy (إضافة): يقارن كل توقع قديم (فترته خلصت فعليًا)
     * بالإيراد الحقيقي اللي حصل في نفس الفترة بالظبط - عشان يديك مصداقية
     * حقيقية قابلة للتحقق للـ AI ("توقعنا 5000، حصل فعليًا 4800، دقة 96%")
     * بدل ما يفضل التوقع رقم مجرد محدش راجعه بعدين.
     */
    public function getAccuracyHistory(int $userId, int $limit = 10): array
    {
        $pastForecasts = $this->gateway->getPastForecasts($userId, $limit);
        if (empty($pastForecasts)) {
            return ['has_data' => false, 'message' => 'Not enough data', 'history' => [], 'average_accuracy_percent' => null];
        }

        $history = [];
        $accuracySum = 0.0;
        $accuracyCount = 0;

        foreach ($pastForecasts as $f) {
            $actualTotals = $this->gateway->getRevenueTotals(
                $userId,
                $f['period_start'] . ' 00:00:00',
                (new DateTime($f['period_end']))->modify('+1 day')->format('Y-m-d 00:00:00')
            );
            if($actualTotals['mixed_currency'])continue;
            $actual = (float) $actualTotals['total'];
            $expected = (float) $f['expected_revenue'];

            $accuracyPercent = null;
            if ($actual > 0) {
                $accuracyPercent = round(max(0, 100 - (abs($actual - $expected) / $actual) * 100), 1);
                $accuracySum += $accuracyPercent;
                $accuracyCount++;
            }

            $history[] = [
                'period_type' => $f['period_type'],
                'period_start' => $f['period_start'],
                'period_end' => $f['period_end'],
                'expected_revenue' => $expected,
                'forecast_range' => ['low' => (float) $f['low_estimate'], 'high' => (float) $f['high_estimate']],
                'actual_revenue' => $actualTotals['count'] > 0 ? round($actual, 2) : null,
                'accuracy_percent' => $accuracyPercent,
                'within_range' => $actualTotals['count'] > 0
                    ? ($actual >= (float) $f['low_estimate'] && $actual <= (float) $f['high_estimate'])
                    : null,
                'confidence_at_time' => $f['confidence'],
            ];
        }

        return [
            'has_data' => true,
            'history' => $history,
            'average_accuracy_percent' => $accuracyCount > 0 ? round($accuracySum / $accuracyCount, 1) : null,
        ];
    }
}
