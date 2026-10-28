<?php
require_once __DIR__.'/../Services/SocialMedia/SocialMonthlyPlanService.php';
class RefreshSocialPlanMetricsJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        (new SocialMonthlyPlanService())->refreshTargetMetrics((int)($payload['target_id']??0));
    }
}
