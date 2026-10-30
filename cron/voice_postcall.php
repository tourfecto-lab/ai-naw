<?php
/** Every minute: runs the post-call actions (summary, CRM, booking request, follow-up message, staff task) for calls that have ended. Each action runs at most once per call. */
require_once __DIR__.'/bootstrap.php';
try {
    $db=Database::getInstance();
    $rows=$db->query("SELECT id, tenant_user_id FROM voice_calls WHERE post_call_status IN ('pending','failed') AND post_call_attempts < 3 AND ended_at IS NOT NULL AND ended_at <= NOW() - INTERVAL 20 SECOND ORDER BY id LIMIT 10")?:[];
    $svc=new VoicePostCallService();
    $out=[];
    foreach($rows as $r){$out[(int)$r['id']]=$svc->process((int)$r['tenant_user_id'],(int)$r['id']);}
    fwrite(STDOUT,json_encode($out,JSON_THROW_ON_ERROR).PHP_EOL);
} catch(Throwable $e) {Logger::error('Voice post-call run failed',['message'=>$e->getMessage()]);fwrite(STDERR,"Voice post-call run failed; check the voice migrations.\n");exit(1);}
