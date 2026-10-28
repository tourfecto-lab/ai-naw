<?php
/** Read-only hosting checks for the customer audit update. No credentials, messages or migrations. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
if(!is_file($root.'/vendor/autoload.php')){fwrite(STDERR,"Composer autoload is missing. Run the supported installation first.\n");exit(1);}
require $root.'/cron/bootstrap.php';
$result=[];
foreach(['pdo_mysql','curl','mbstring','openssl','gd'] as $ext)$result['php_'.$ext]=['ok'=>extension_loaded($ext)];
$hb=$root.'/storage/cron/heartbeat';$age=is_file($hb)?time()-(int)filemtime($hb):null;
$result['scheduler']=['ok'=>$age!==null&&$age<300,'age_seconds'=>$age,'action'=>'Run cron/scheduler.php every minute; inspect individual job results separately.'];
try{
    $p=Database::getInstance()->getConnection();
    foreach(['platform_connections','business_hub_profiles','ai_knowledge_base','email_platform_settings','email_campaigns','email_campaign_recipients'] as $table){$s=$p->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);$result['table_'.$table]=['ok'=>(int)$s->fetchColumn()>0];}
}catch(Throwable $e){$result['database']=['ok'=>false,'action'=>'Check database configuration and installed module migrations; credentials are not printed.'];}
try{$d=KnowledgeDocumentReader::status();$result['document_tools']=['ok'=>!empty($d['text_pdf']),'status'=>$d];$result['ocr_tools']=['ok'=>!empty($d['ocr']),'action'=>'If scanned PDFs are needed, install pdftoppm, Tesseract and the required language packs.'];}catch(Throwable $e){$result['document_tools']=['ok'=>false];}
try{$result['video_tools']=['ok'=>VideoEditing::available(),'action'=>'Install and configure the supported ffmpeg binary on the hosting server if video editing is required.'];}catch(Throwable $e){$result['video_tools']=['ok'=>false];}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
exit(array_filter($result,static fn($r)=>empty($r['ok']))?1:0);
