<?php
require_once __DIR__.'/../Services/SeoExpert/SeoExpertService.php';
class SeoExpertExecuteJob implements QueueJobInterface
{
    public function handle(array $payload): void {
        @set_time_limit(240);$operation=(string)($payload['operation']??'');
        if(!in_array($operation,['apply','verify'],true))throw new InvalidArgumentException('Unknown SEO operation');
        (new SeoExpertService())->execute((int)($payload['run_id']??0),$operation,min(3,max(0,(int)($payload['attempt']??0))),max(0,(int)($payload['task_cursor']??0)));
    }
}
