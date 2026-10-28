<?php
require_once __DIR__.'/../Services/SeoExpert/SeoExpertService.php';
class SeoExpertCycleJob implements QueueJobInterface
{
    public function handle(array $payload): void {
        @set_time_limit(240);$s=new SeoExpertService();
        if(!empty($payload['run_id']))$s->build((int)$payload['run_id']);
        elseif(!empty($payload['website_id'])&&!empty($payload['user_id']))$s->recurring((int)$payload['user_id'],(int)$payload['website_id']);
    }
}
