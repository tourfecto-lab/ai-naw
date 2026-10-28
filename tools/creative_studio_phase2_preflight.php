<?php
/** Read-only readiness checks for phase 2. Never generates an image/video or charges a wallet. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/cron/bootstrap.php';
try{
    $pdo=Database::getInstance()->getConnection();$pdo->query('SELECT generation_meta,request_key,usage_charged,generation_stage FROM media_items LIMIT 0');$pdo->query('SELECT feature_key,idempotency_key,amount FROM wallet_transactions LIMIT 0');
    $gd=function_exists('imagecreatefromstring')&&function_exists('imagepng');$prices=(new WalletService())->getUsagePricing();$resolutions=[];
    foreach(['1080p','4k'] as $resolution){$p=$prices['media_video_'.$resolution]??null;$resolutions[$resolution]=VeoClient::supportsResolution($resolution)&&$p&&($p['currency']??'USD')==='USD'&&(float)$p['price']>0;}
    $limit=static function($v){$v=trim((string)$v);$n=(float)$v;$unit=strtolower(substr($v,-1));return (int)($n*(['g'=>1073741824,'m'=>1048576,'k'=>1024][$unit]??1));};
    $upload=$limit(ini_get('upload_max_filesize'));$post=$limit(ini_get('post_max_size'));
    echo json_encode(['schema'=>'available','gd_normalization'=>$gd,'upload_limit_bytes'=>$upload,'post_limit_bytes'=>$post,'ten_mb_upload_ready'=>$upload>=10485760&&$post>10485760,'premium_video_enabled'=>$resolutions,'checks'=>'read-only; verify cron, MySQL transaction locks, provider access, browser and mobile behavior on staging'],JSON_PRETTY_PRINT)."\n";exit($gd?0:1);
}catch(Throwable $e){Logger::error('Creative phase 2 preflight failed',['message'=>$e->getMessage()]);echo "{\"ready\":false,\"message\":\"Check schema and private logs.\"}\n";exit(1);}
