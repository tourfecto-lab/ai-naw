<?php

/**
 * Tourfecto - Revenue Anomaly Detection Service
 * @version 1.0.0
 *
 * Section 9: REVENUE ANOMALY DETECTION
 *
 * كشف إحصائي (Z-score على الانحراف عن المتوسط المتحرك) لأيام فيها إيراد
 * غير طبيعي (ارتفاع/انخفاض مفاجئ) - وليس تخمينًا. لكل Anomaly: Severity،
 * التاريخ، المقياس المتأثر (الإيراد اليومي)، سبب مقترح عندما يمكن ربطه
 * بمصدر واحد مهيمن على اليوم، وتوصية بالتحقق (لا حكم قطعي).
 */
class RevenueAnomalyService
{
    /** أقل عدد أيام بيانات قبل ما نحاول كشف شذوذ (نافذة إحصائية دنيا). */
    public const MIN_WINDOW = 14;

    /** عتبة Z-score لاعتبار اليوم "شاذ". */
    public const Z_THRESHOLD_MEDIUM = 2.0;
    public const Z_THRESHOLD_HIGH = 3.0;

    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function detect(int $userId, int $lookbackDays = 60): array
    {
        $now = new DateTime('now');
        $from = (clone $now)->modify("-{$lookbackDays} days");
        $series = $this->gateway->getDailyRevenueSeries($userId, $from->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));
        $bySourceRaw = $this->gateway->getRevenueRecords($userId, $from->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));

        return self::computeAnomalies($series, $bySourceRaw);
    }

    /**
     * Pure function - قابلة للاختبار بـ Fixtures بدون قاعدة بيانات.
     * @param array $dailySeries [['date'=>Y-m-d,'revenue'=>float], ...] مرتّبة تصاعديًا
     * @param array $rawRecords سجلات الإيراد الخام (لمحاولة تحديد "السبب" لو مصدر واحد هيمن على اليوم الشاذ)
     */
    public static function computeAnomalies(array $dailySeries, array $rawRecords = []): array
    {
        $n = count($dailySeries);
        if ($n < self::MIN_WINDOW) {
            return ['has_data' => false, 'message' => 'Not enough data', 'anomalies' => []];
        }

        $values = array_map(static function ($p) {
            return (float) $p['revenue'];
        }, $dailySeries);
        $mean = array_sum($values) / $n;
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= $n;
        $stdDev = sqrt($variance);

        // مصادر مهيمنة لكل يوم (لو متاح) - يساعد فقط في اقتراح "سبب" ولا نجزم به.
        $sourceByDate = [];
        foreach ($rawRecords as $rec) {
            $date = substr($rec['recorded_at'], 0, 10);
            $sourceByDate[$date][$rec['source']] = ($sourceByDate[$date][$rec['source']] ?? 0) + (float) $rec['amount'];
        }

        $anomalies = [];
        if ($stdDev > 0) {
            foreach ($dailySeries as $point) {
                $z = ($point['revenue'] - $mean) / $stdDev;
                $absZ = abs($z);
                if ($absZ < self::Z_THRESHOLD_MEDIUM) {
                    continue;
                }

                $severity = $absZ >= self::Z_THRESHOLD_HIGH ? 'high' : 'medium';
                $direction = $z > 0 ? 'sudden_increase' : 'sudden_drop';

                $reason = null;
                if (!empty($sourceByDate[$point['date']])) {
                    arsort($sourceByDate[$point['date']]);
                    $topSource = array_key_first($sourceByDate[$point['date']]);
                    $topShare = count($sourceByDate[$point['date']]) > 0
                        ? $sourceByDate[$point['date']][$topSource] / max(0.01, array_sum($sourceByDate[$point['date']]))
                        : 0;
                    if ($topShare >= 0.7) {
                        $reason = $direction === 'sudden_increase'
                            ? "Driven mainly by the '{$topSource}' source on this day."
                            : "The '{$topSource}' source, usually a main contributor, was notably low or absent this day.";
                    }
                }

                $anomalies[] = [
                    'type' => $direction,
                    'severity' => $severity,
                    'period' => $point['date'],
                    'affected_metric' => 'daily_revenue',
                    'value' => round($point['revenue'], 2),
                    'expected_range' => [
                        'low' => round(max(0, $mean - self::Z_THRESHOLD_MEDIUM * $stdDev), 2),
                        'high' => round($mean + self::Z_THRESHOLD_MEDIUM * $stdDev, 2),
                    ],
                    'z_score' => round($z, 2),
                    'reason' => $reason,
                    'recommended_investigation' => $direction === 'sudden_drop'
                        ? 'Check for cancelled bookings/orders, payment failures, or a paused marketing channel on this date.'
                        : 'Verify this is legitimate revenue (not a duplicate entry or refund reversal) and identify what drove it to possibly repeat it.',
                ];
            }
        }

        // الأحدث أولًا
        usort($anomalies, static function ($a, $b) {
            return strcmp($b['period'], $a['period']);
        });

        return ['has_data' => true, 'mean' => round($mean, 2), 'std_dev' => round($stdDev, 2), 'anomalies' => $anomalies];
    }
}
