<?php
require_once __DIR__.'/../Services/BusinessHub/SocialRecurringService.php';
class SocialMonthlyRecurringJob implements QueueJobInterface
{
    public function handle(array $payload): void{@set_time_limit(90);(new SocialRecurringService())->run($payload);}
}
