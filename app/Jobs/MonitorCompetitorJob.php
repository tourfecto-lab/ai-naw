<?php

/**
 * Tourfecto - Competitor Intelligence: Monitor Competitor Job
 * @version 1.0.0
 *
 * ينفّذ دورة مراقبة كاملة لمنافس واحد فقط في الخلفية عبر MonitoringEngine.
 * يُدفَع (push) من cron/monitor_competitors.php لكل منافس مستحق حسب
 * monitoring_frequency/last_monitored_at - أبدًا لا يُنفَّذ داخل نفس
 * الـ HTTP request لعشرات المنافسين.
 */
class MonitorCompetitorJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $competitorId = (int) ($payload['competitor_id'] ?? 0);
        if ($competitorId <= 0) {
            throw new InvalidArgumentException('MonitorCompetitorJob: missing competitor_id');
        }

        $competitor = (new Competitor())->find($competitorId);
        if (!$competitor) {
            // المنافس اتحذف بعد ما اتجدولت المهمة - مش خطأ يستاهل Retry
            return;
        }

        if ((int) $competitor->getAttribute('monitoring_paused') === 1 || (int) $competitor->getAttribute('is_active') !== 1) {
            return;
        }

        $engine = new MonitoringEngine();
        $engine->monitor($competitor);
    }
}
