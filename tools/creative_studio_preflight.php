<?php
/** Read-only configuration/schema check; no paid provider calls. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/cron/bootstrap.php';
try{
    $pdo=Database::getInstance()->getConnection();
    $pdo->query('SELECT request_key,generation_meta,generation_stage,usage_charged,job_id,provider_ref,poll_attempts,final_prompt FROM media_items LIMIT 0');
    $pdo->query('SELECT id,payload,status,available_at FROM jobs LIMIT 0');
    $stmt=$pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('media_items','jobs','users','wallet_transactions','plan_usage_events')");
    $engines=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);$transactions=true;
    foreach(['media_items','jobs','users','wallet_transactions','plan_usage_events'] as $table)if(strtoupper((string)($engines[$table]??''))!=='INNODB')$transactions=false;
    $image=(new OpenAIImageClient())->isConfigured();
    $key=defined('GEMINI_API_KEY')?GEMINI_API_KEY:'';
    if(class_exists('SystemSettingsService'))$key=(new SystemSettingsService())->get('gemini_api_key',$key);
    $gemini=is_string($key)&&$key!=='';
    $media=ROOT_PATH.'/public_html/uploads/media';$uploads=ROOT_PATH.'/public_html/uploads';
    $writable=!is_link($media)&&(is_dir($media)?is_writable($media):(is_dir($uploads)&&is_writable($uploads)));
    $pdo->query("SELECT IS_FREE_LOCK('tourfecto_studio_preflight')");
    $ready=$transactions&&($image||$gemini)&&$writable;
    echo json_encode(['ready'=>$ready,'schema'=>'available','transactional_tables'=>$transactions,'image_key_configured'=>$image||$gemini,'video_key_configured'=>$gemini,'media_directory_writable'=>$writable,'provider_access'=>'not tested; configuration does not confirm model access or provider balance'],JSON_PRETTY_PRINT)."\n";exit($ready?0:1);
}catch(Throwable $e){Logger::error('Creative studio preflight failed',['message'=>$e->getMessage()]);echo "{\"ready\":false,\"message\":\"Check migration and existing schema in private logs.\"}\n";exit(1);}
