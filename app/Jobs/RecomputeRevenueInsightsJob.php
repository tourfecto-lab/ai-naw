<?php

/**
 * Tourfecto - Recompute Revenue Insights Job
 * @version 1.0.0
 *
 * Section 18: PERFORMANCE (Background Jobs) + Section 25: EVENTS
 *
 * بيتنفذ في الخلفية (عن طريق QueueManager/cron/process_queue.php - مفيش
 * نظام queue جديد اتعمل، بنستخدم اللي موجود فعلاً في المشروع) بدل ما
 * نجبر المستخدم يستنى إعادة حساب Forecast/Insights وقت حدث مهم (صفقة
 * اتقفلت "مكسوبة"، إيراد جديد اتسجّل). النتيجة بتترصد في revai_forecasts
 * و revai_insights (Audit Log)، والداشبورد بتاعت المستخدم بتفضل سريعة
 * لأنها بتقرأ من الكاش (RevenueCacheService) اللي بيتبطّل فور ما الـ
 * Job ده يخلص.
 *
 * إضافة: لو Risk أو Anomaly بدرجة خطورة "high" اتكتشف، بنبعت Notification
 * حقيقي عن طريق Notification::notify() الموجود فعلاً في المشروع (نفس
 * الجرس 🔔 اللي في أعلى كل صفحة) - بدل ما المستخدم يعتمد إنه يفتح تاب
 * المخاطر بنفسه كل يوم عشان يكتشف مشكلة. اتحمى من التكرار (24 ساعة لكل
 * فئة خطر) عن طريق RevenueCacheService::shouldNotify().
 */
class RecomputeRevenueInsightsJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new Exception('RecomputeRevenueInsightsJob: missing/invalid user_id in payload');
        }

        $forecastService = new RevenueForecastService();
        $insightService = new RevenueInsightService();
        $anomalyService = new RevenueAnomalyService();
        $cache = new RevenueCacheService();

        // إعادة حساب وتخزين Forecast الشهري (الأكثر استخدامًا) في السجل التاريخي.
        $forecastService->forecast($userId, 'monthly', true);

        // إعادة توليد وتسجيل Opportunities/Risks/Anomalies الحالية (Audit Log).
        RevenueInsightPersister::persist($userId, $insightService->getOpportunities($userId));
        $risks = $insightService->getRisks($userId);
        RevenueInsightPersister::persist($userId, $risks);

        $anomalies = $anomalyService->detect($userId);
        if (!empty($anomalies['anomalies'])) {
            RevenueInsightPersister::persist(
                $userId,
                array_map([RevenueInsightPersister::class, 'anomalyToInsight'], $anomalies['anomalies'])
            );
        }

        // v2.0 PRO: مخاطر/فرص سياحية حقيقية (تحصيل متأخر، موسم أضعف من السنة الماضية، منتجات خاسرة)
        $proInsights = self::proInsights($userId);
        if (!empty($proInsights)) {
            RevenueInsightPersister::persist($userId, $proInsights);
        }
        $proRisks = array_values(array_filter($proInsights, static function ($i) {
            return ($i['type'] ?? '') === 'risk';
        }));

        $this->notifyHighSeverity($userId, array_merge($risks, $proRisks), $anomalies['anomalies'] ?? [], $cache);

        // v2.0 PRO: نفس التنبيهات المهمة على Slack/Webhook/Email لو الحساب مفعّلها
        if (class_exists('RevenueAlertChannelService')) {
            try {
                RevenueAlertChannelService::dispatch($userId, array_merge(
                    $risks,
                    $proRisks,
                    array_map([RevenueInsightPersister::class, 'anomalyToInsight'], $anomalies['anomalies'] ?? [])
                ));
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('Revenue alert dispatch failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            }
        }

        // كل حساب قديم في الكاش بقى غير دقيق دلوقتي - نبطّله عشان أول طلب جاي يحسب من جديد بالبيانات المحدّثة.
        $cache->invalidateForUser($userId);

        if (class_exists('Logger')) {
            Logger::info('RecomputeRevenueInsightsJob completed', ['user_id' => $userId]);
        }
    }

    /** Insights الإضافية من خدمات PRO - كل خدمة معزولة عشان فشل واحدة مايوقفش الباقي. */
    private static function proInsights(int $userId): array
    {
        $out = [];
        $tasks = [
            static function () use ($userId) {
                $c = (new RevenueCollectionsService())->getCollections($userId);
                return RevenueCollectionsService::toRiskInsights($c);
            },
            static function () use ($userId) {
                return TourismKpiService::paceToInsights((new TourismKpiService())->getPace($userId, 90));
            },
            static function () use ($userId) {
                return RevenueProfitabilityService::toInsights((new RevenueProfitabilityService())->getProfitability($userId, 'quarterly'));
            },
        ];
        foreach ($tasks as $task) {
            try {
                $out = array_merge($out, $task());
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('Revenue PRO insight failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            }
        }
        return $out;
    }

    /** يبعت Notification حقيقي بس للمخاطر/الشذوذ عالية الخطورة - مش كل حاجة، عشان الجرس متبقاش مليانة ضوضاء. */
    private function notifyHighSeverity(int $userId, array $risks, array $anomalies, RevenueCacheService $cache): void
    {
        if (!class_exists('Notification')) {
            return; // موديول الإشعارات مش متاح في هذه النشرة - لا نكسر الـ Job لأجله
        }

        foreach ($risks as $risk) {
            if (($risk['severity'] ?? null) !== 'high') {
                continue;
            }
            $dedupKey = "risk:{$userId}:{$risk['category']}";
            if (!$cache->shouldNotify($dedupKey)) {
                continue;
            }
            Notification::notify(
                $userId,
                'revenue_risk',
                $risk['title'],
                $risk['finding'],
                '/revenue/intelligence'
            );
        }

        foreach ($anomalies as $anomaly) {
            if (($anomaly['severity'] ?? null) !== 'high') {
                continue;
            }
            $dedupKey = "anomaly:{$userId}:{$anomaly['type']}:{$anomaly['period']}";
            if (!$cache->shouldNotify($dedupKey)) {
                continue;
            }
            Notification::notify(
                $userId,
                'revenue_anomaly',
                ($anomaly['type'] === 'sudden_drop' ? 'Sudden revenue drop detected' : 'Unusual revenue spike detected') . " ({$anomaly['period']})",
                $anomaly['recommended_investigation'],
                '/revenue/intelligence'
            );
        }
    }
}
