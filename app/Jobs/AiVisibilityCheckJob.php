<?php
/** Small resumable batches; durable per-week claims prevent duplicate paid retries. */
class AiVisibilityCheckJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        @set_time_limit(240);
        $week=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
        if(($payload['week']??'')!==$week)return;
        $uid=(int)($payload['user_id']??0);$wid=(int)($payload['website_id']??0);$mid=(int)($payload['market_id']??0);$s=new AiVisibilityService();
        $set=$s->set($uid,$wid,$mid);if(!$set||!$set['approved_at']||(int)$set['version']!==(int)($payload['version']??0))return;
        $r=$s->run($uid,$wid,$mid,1);
        if(!empty($r['more']) && !(new QueueManager())->push(self::class,$payload,'low',5))throw new RuntimeException('GEO continuation enqueue failed');
    }
}
