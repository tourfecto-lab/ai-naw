<?php
require_once __DIR__.'/../Services/SocialMedia/SocialMonthlyPlanService.php';
/** Generates one billed plan once; an interrupted provider call is never replayed automatically. */
class GenerateSocialMonthlyPlanJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        @set_time_limit(180);
        (new SocialMonthlyPlanService())->generateQueuedPlan((int)($payload['plan_id']??0));
    }
}
