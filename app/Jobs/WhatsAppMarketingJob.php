<?php
final class WhatsAppMarketingJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $uid=(int)($payload['user_id']??0);$id=(int)($payload['campaign_id']??0);
        try{(new WhatsAppMarketingService())->process($uid,$id,5);}finally{
            $q=Database::getInstance()->getConnection()->prepare('UPDATE wa_marketing_campaigns SET job_pending=0 WHERE id=? AND user_id=?');$q->execute([$id,$uid]);
        }
    }
}
