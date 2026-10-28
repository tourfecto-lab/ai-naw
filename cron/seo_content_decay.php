<?php
require_once __DIR__.'/bootstrap.php';
$db=Database::getInstance();
if (!(new ContentQualityService($db))->ready()) throw new RuntimeException('Apply Phase 4 migration before scheduling content decay.');
$queue=new QueueManager($db);
foreach ($db->query("SELECT website_id,user_id FROM seo_expert_sites WHERE status='active'") as $site) {
    $payload=['website_id'=>(int)$site['website_id'],'user_id'=>(int)$site['user_id']];
    if (!$db->query("SELECT id FROM jobs WHERE job_class='ContentDecayJob' AND status IN ('pending','processing') AND payload=? LIMIT 1",[json_encode($payload)])) $queue->push(ContentDecayJob::class,$payload,'seo');
}
