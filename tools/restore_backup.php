<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Services/Operations/BackupService.php';
$options=getopt('',['file:','check','restore-to:']);$input=realpath($options['file']??'');$key=(string)getenv('BACKUP_ENCRYPTION_KEY');$temp=sys_get_temp_dir().'/tf-restore-'.bin2hex(random_bytes(8));mkdir($temp,0700);$oldMask=umask(0077);$exitCode=0;
try{
    if(!$input)throw new RuntimeException('Usage: php tools/restore_backup.php --file=/private/backup.tfb --check OR --restore-to=/private/new-directory');
    BackupService::decrypt($input,$temp.'/backup.tar',$key);$manifest=BackupService::verifyArchive($temp.'/backup.tar');
    if(isset($options['restore-to']))BackupService::extractToEmptyDirectory($temp.'/backup.tar',$options['restore-to']);
    echo json_encode(['success'=>true,'verified_files'=>count($manifest['files']),'extracted'=>isset($options['restore-to']),'database_imported'=>false])."\n";
}catch(Throwable $e){$exitCode=1;fwrite(STDERR,$e->getMessage()."\n");}finally{@unlink($temp.'/backup.tar');@rmdir($temp);umask($oldMask);}exit($exitCode);
