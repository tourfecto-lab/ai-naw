<?php
class ApplyCompetitorSiteImprovementsJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        (new CompetitorSiteExecutionService())->process((int)($payload['execution_id']??0));
    }
}
