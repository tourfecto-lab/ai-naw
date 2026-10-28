<?php

/**
 * Tourfecto - Revenue Benchmark & Churn Analytics
 * @version 1.0.0
 *
 * v1.5.0 (section C): Baremetrics-style cohort benchmarks + churn-reason analytics.
 *
 * الفلسفة: لا أرقام مخترعة أبدًا. الـ benchmarks إما:
 *   1. تُبنى من بيانات المنصة الحقيقية المجهولة عبر cron rebuild
 *      (`basis='platform'`, sample_size حقيقي, as_of_date حقيقي) أو
 *   2. تُسجّل يدويًا من تقارير معروفة المصدر (`basis='manual'`, source واقعي)
 * وإلا => "Not enough data".
 *
 * Pure functions قابلة للاختبار:
 *   - classifyChurnReason(array $deal, array $events) - تصنيف سبب التوقف
 *   - aggregateChurnReasons(array $deals, array $events) - إجماليات الأسباب
 */
class RevenueBenchmarkService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function tablesAvailable(): bool
    {
        return $this->gateway->hasBenchmarkTables();
    }

    /**
     * سحب صفوف benchmarks الخاصة بالمستخدم مع fallback منصّي
     * (لو المستخدم مالهاش صفوفه، نعرض صفوف platform المجهولة).
     */
    public function getBenchmarks(int $userId): array
    {
        if (!$this->tablesAvailable()) {
            return ['has_data' => false, 'reason' => 'revai_benchmarks table is not installed. Install database/migrations/2026_08_16_000010_create_revai_subscriptions_teams_benchmarks.sql.'];
        }
        $rows = $this->gateway->getBenchmarkRows($userId);
        if (empty($rows)) {
            $platform = $this->gateway->getPlatformBenchmarks();
            if (empty($platform)) {
                return ['has_data' => false, 'reason' => 'No benchmarks recorded yet. Run cron/revai_benchmarks_rebuild.php (requires aggregated platform data) or insert manual benchmark rows.'];
            }
            return ['has_data' => true, 'rows' => $platform, 'source' => 'platform'];
        }
        return ['has_data' => true, 'rows' => $rows, 'source' => 'user'];
    }

    /** Pure: تصنيف سبب التوقف من بيانات الصفقة + أحداث الاشتراكات. */
    public static function classifyChurnReason(array $deal, array $events = []): array
    {
        $status = (string) ($deal['status'] ?? '');
        $stage = (string) ($deal['stage_name'] ?? '');
        $closedLostReason = trim((string) ($deal['lost_reason'] ?? $deal['close_reason'] ?? ''));

        // 1) سبب خسارة مسجل صراحةً على الصفقة.
        if ($closedLostReason !== '') {
            return ['reason' => 'explicit', 'label' => $closedLostReason, 'confidence' => 'high', 'source' => 'deal.lost_reason'];
        }

        // 2) البحث في أحداث الاشتراكات عن سبب churn مرافق للعميل.
        if (!empty($events)) {
            $contactId = (int) ($deal['contact_id'] ?? 0);
            foreach ($events as $e) {
                if (($e['event_type'] ?? '') !== 'churn') {
                    continue;
                }
                $reason = trim((string) ($e['churn_reason'] ?? $e['reason'] ?? ''));
                if ($reason === '') {
                    continue;
                }
                $eContact = (int) ($e['contact_id'] ?? 0);
                if ($contactId > 0 && $eContact > 0 && $contactId !== $eContact) {
                    continue;
                }
                return ['reason' => 'subscription_event', 'label' => $reason, 'confidence' => 'high', 'source' => 'biz_subscription_events.churn_reason'];
            }
        }

        // 3) انعكاس المرحلة (stage مخصصة للمفقود) أو حالة cancelled بلا سبب.
        if ($status === 'lost' || $stage === 'Closed Lost') {
            return ['reason' => 'implied', 'label' => 'Lost deal without stated reason', 'confidence' => 'low', 'source' => 'deal.status'];
        }
        if ($status === 'cancelled' || $status === 'expired') {
            return ['reason' => 'implied', 'label' => 'Subscription ended without stated reason', 'confidence' => 'low', 'source' => 'subscription.status'];
        }
        return ['reason' => 'unknown', 'label' => 'Not enough data', 'confidence' => 'low', 'source' => null];
    }

    /** Pure: إجمالي أسباب churn عبر الصفقات (+ optional أحداث). */
    public static function aggregateChurnReasons(array $deals, array $events = []): array
    {
        $byReason = [];
        $total = 0;
        $hasData = false;

        foreach ($deals as $deal) {
            $status = (string) ($deal['status'] ?? '');
            if (!in_array($status, ['lost', 'cancelled', 'expired'], true)) {
                continue;
            }
            $hasData = true;
            $c = self::classifyChurnReason($deal, $events);
            $total++;
            if (!isset($byReason[$c['label']])) {
                $byReason[$c['label']] = ['label' => $c['label'], 'count' => 0, 'confidence' => $c['confidence'], 'source' => $c['source']];
            }
            $byReason[$c['label']]['count']++;
        }

        // إن لم تكن هناك صفقات خاسرة لكن توجد أحداث churn، نضمّنها من الأحداث.
        if ($total === 0 && !empty($events)) {
            foreach ($events as $e) {
                if (($e['event_type'] ?? '') !== 'churn') {
                    continue;
                }
                $reason = trim((string) ($e['churn_reason'] ?? $e['reason'] ?? ''));
                if ($reason === '') {
                    $reason = 'No stated reason';
                }
                $hasData = true;
                $total++;
                if (!isset($byReason[$reason])) {
                    $byReason[$reason] = ['label' => $reason, 'count' => 0, 'confidence' => $reason === 'No stated reason' ? 'low' : 'high', 'source' => 'biz_subscription_events.churn_reason'];
                }
                $byReason[$reason]['count']++;
            }
        }

        usort($byReason, static function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return [
            'has_data' => $hasData,
            'total_churned' => $total,
            'by_reason' => $byReason,
            'top_reason' => $byReason[0]['label'] ?? null,
        ];
    }
}
