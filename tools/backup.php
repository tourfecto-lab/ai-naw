<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Services/Operations/BackupService.php';
$options=getopt('',['output:','source:']);$root=realpath($options['source']??dirname(__DIR__));$output=$options['output']??'';
if($root&&is_file(dirname(__DIR__).'/vendor/autoload.php')){require dirname(__DIR__).'/vendor/autoload.php';if(class_exists('Dotenv\\Dotenv'))\Dotenv\Dotenv::createImmutable($root)->safeLoad();}
$key=(string)(getenv('BACKUP_ENCRYPTION_KEY') ?: ($_ENV['BACKUP_ENCRYPTION_KEY']??''));
if(!$root||$output===''){fwrite(STDERR,"Usage: BACKUP_ENCRYPTION_KEY=... DB_HOST=... DB_NAME=... DB_USER=... DB_PASSWORD=... php tools/backup.php --output=/private/path/backup.tfb\n");exit(1);}
$temp=sys_get_temp_dir().'/tf-backup-'.bin2hex(random_bytes(8));mkdir($temp,0700);$oldMask=umask(0077);
try{
    $parent=realpath(dirname($output));if(!$parent||str_starts_with($parent.'/',$root.'/'))throw new RuntimeException('Backup output must be outside the application directory');
    $value=function($name,$fallback=''){return (string)(getenv($name)!==false?getenv($name):($_ENV[$name]??$fallback));};
    $escape=function($v){return '"'.str_replace(["\\","\n","\r",'"'],["\\\\","\\n","\\r","\\\""],$v).'"';};
    $config="[client]\nhost=".$escape($value('DB_HOST','127.0.0.1'))."\nport=".$escape($value('DB_PORT','3306'))."\nuser=".$escape($value('DB_USER'))."\npassword=".$escape($value('DB_PASSWORD'))."\n";
    file_put_contents($temp.'/client.cnf',$config);chmod($temp.'/client.cnf',0600);
    $database=$value('DB_NAME');if(!preg_match('/^[A-Za-z0-9_]+$/D',$database))throw new RuntimeException('Invalid DB_NAME');
    $binary=$value('BACKUP_DUMP_BINARY','mysqldump');
    $process=proc_open([$binary,'--defaults-extra-file='.$temp.'/client.cnf','--single-transaction','--quick','--routines','--triggers','--events','--hex-blob',$database],[0=>['file','/dev/null','r'],1=>['file',$temp.'/database.sql','w'],2=>['file',$temp.'/dump-error.log','w']],$pipes);
    if(!is_resource($process)||proc_close($process)!==0||!is_file($temp.'/database.sql')||filesize($temp.'/database.sql')<100)throw new RuntimeException('Database dump failed; check database privileges and the local MySQL client installation');
    $manifest=BackupService::archive($root,$temp.'/database.sql',$temp.'/backup.tar');BackupService::verifyArchive($temp.'/backup.tar');BackupService::encrypt($temp.'/backup.tar',$parent.'/'.basename($output),$key);
    echo json_encode(['success'=>true,'file_count'=>count($manifest['files']),'output'=>$parent.'/'.basename($output)])."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exitCode(1);}finally{foreach(glob($temp.'/*')as$f)@unlink($f);@rmdir($temp);umask($oldMask);} 
function exitCode($code){$GLOBALS['backup_exit_code']=$code;}exit($GLOBALS['backup_exit_code']??0);
