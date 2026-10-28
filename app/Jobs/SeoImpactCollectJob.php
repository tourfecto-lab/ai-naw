<?php
require_once __DIR__.'/../Services/SeoImpact/SeoImpactService.php';
class SeoImpactCollectJob implements QueueJobInterface {
    public function handle(array $payload): void {@set_time_limit(240);(new SeoImpactService())->collect((int)($payload['monitor_id']??0));}
}
