<?php
/** Read-only by default; --apply moves only allowlisted database assets after verified copying. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/cron/bootstrap.php';
$apply=in_array('--apply',$argv,true);$moved=0;$pending=0;$failed=0;
try{$pdo=Database::getInstance()->getConnection();$stmt=$pdo->query('SELECT file_path,thumbnail_path FROM media_items');$seen=[];$dir=PrivateMedia::directory();$legacy=ROOT_PATH.'/public_html/uploads/media';
    while($row=$stmt->fetch(PDO::FETCH_ASSOC))foreach(['file_path','thumbnail_path'] as $key){$path=(string)($row[$key]??'');if(!PrivateMedia::valid($path)||isset($seen[$path]))continue;$seen[$path]=true;$source=$legacy.'/'.basename($path);$target=$dir.'/'.basename($path);if(!is_file($source))continue;$pending++;
        if(!$apply)continue;
        try{$base=realpath($legacy);$full=realpath($source);if(!$base||!$full||dirname($full)!==$base||is_link($legacy)||is_link($source))throw new RuntimeException('Unsafe legacy asset');$hash=hash_file('sha256',$source);if($hash===false)throw new RuntimeException('Asset unreadable');
            if(is_file($target)){if(is_link($target)||!hash_equals($hash,(string)hash_file('sha256',$target)))throw new RuntimeException('Target content mismatch');}
            else{$temp=$target.'.'.bin2hex(random_bytes(6)).'.part';try{if(!copy($source,$temp)||!hash_equals($hash,(string)hash_file('sha256',$temp))||!chmod($temp,0600)||!rename($temp,$target))throw new RuntimeException('Verified copy failed');}finally{if(is_file($temp))@unlink($temp);}}
            if(!hash_equals($hash,(string)hash_file('sha256',$target))||!unlink($source))throw new RuntimeException('Source cleanup failed');$moved++;
        }catch(Throwable $e){$failed++;Logger::error('Creative private migration asset failed',['asset'=>basename($path),'message'=>$e->getMessage()]);}
    }
    echo json_encode(['mode'=>$apply?'apply':'read-only','legacy_assets_found'=>$pending,'moved'=>$moved,'failed'=>$failed,'note'=>'Stop generation and publishing workers during apply. Verify old public URLs return 403/404.'],JSON_PRETTY_PRINT)."\n";exit($failed?1:0);
}catch(Throwable $e){Logger::error('Creative private migration failed',['message'=>$e->getMessage()]);echo "{\"ok\":false,\"message\":\"Check private logs and storage permissions.\"}\n";exit(1);}
