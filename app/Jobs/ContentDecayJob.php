<?php
/** One site's weekly read-only measurement; never rewrites customer content. */
class ContentDecayJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $db=Database::getInstance();$uid=(int)($payload['user_id']??0);$wid=(int)($payload['website_id']??0);
        if (!$db->query("SELECT w.id FROM websites w JOIN seo_expert_sites s ON s.website_id=w.id AND s.user_id=w.user_id WHERE w.id=? AND w.user_id=? AND s.status='active'",[$wid,$uid])) return;
        $connection=$db->query("SELECT access_token,external_location_id FROM platform_connections WHERE website_id=? AND user_id=? AND platform='google_search_console' AND status='connected' ORDER BY id DESC LIMIT 1",[$wid,$uid]);
        if (!$connection) return;
        $api=new GoogleSearchConsoleAPI((new Encryption())->decrypt($connection[0]['access_token']));
        $result=(new ContentDecayService($db))->run($uid,$wid,$api,$connection[0]['external_location_id']);
        if ($result['created']>0) Notification::notify($uid,'content_refresh','Content refresh review',$result['created'].' pages lost search traffic or position. Review the dated refresh briefs in SEO Expert.','/seo-expert');
    }
}
