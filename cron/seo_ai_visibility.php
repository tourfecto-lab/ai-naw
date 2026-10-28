<?php
require_once __DIR__.'/bootstrap.php';
try {
    $settings=new SystemSettingsService();
    if($settings->get('geo_enabled')!=='1'){fwrite(STDOUT,"GEO: not measured (disabled)\n");exit(0);}
    $service=new AiVisibilityService();if(!array_filter($service->providers(),fn($p)=>$p->configured())){fwrite(STDOUT,"GEO: not measured (no providers)\n");exit(0);}
    $db=Database::getInstance();AiVisibilitySchema::ensureSchema($db);$count=0;
    // Queue all approved markets. The central scheduler supplies the weekly cadence.
    $s=$db->getConnection()->query('SELECT p.user_id,p.website_id,p.market_id FROM seo_ai_prompt_sets p JOIN websites w ON w.id=p.website_id AND w.user_id=p.user_id WHERE p.approved_at IS NOT NULL');
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){try{$service->enqueue((int)$row['user_id'],(int)$row['website_id'],(int)$row['market_id']);$count++;}catch(InvalidArgumentException $e){/* Stale identity/approval needs customer review. */}}
    fwrite(STDOUT,"GEO weekly: $count markets queued\n");
}catch(Throwable $e){fwrite(STDERR,"GEO scheduling failed; review database/queue configuration\n");exit(1);}
