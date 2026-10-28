<?php
/** Authenticated streaming backups. Plaintext intermediates are always private and removed. */
class BackupService
{
    private const MAGIC="TOURFECTO-BACKUP-1\n";
    private static function key(string $encoded): string
    {
        if(!extension_loaded('sodium'))throw new RuntimeException('PHP sodium extension is required');
        $key=base64_decode($encoded,true);if($key===false||strlen($key)!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be a base64-encoded 32-byte key');return $key;
    }
    private static function write($stream,string $bytes): void
    {
        $offset=0;while($offset<strlen($bytes)){$n=fwrite($stream,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Backup disk write failed');$offset+=$n;}
    }
    private static function read($stream,int $length): string
    {
        $bytes='';while(strlen($bytes)<$length&&!feof($stream)){$part=fread($stream,$length-strlen($bytes));if($part===false)throw new RuntimeException('Backup read failed');$bytes.=$part;}if(strlen($bytes)!==$length)throw new RuntimeException('Truncated backup');return $bytes;
    }
    public static function encrypt(string $input,string $output,string $encodedKey): void
    {
        $key=self::key($encodedKey);[$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $in=fopen($input,'rb');$out=fopen($output,'xb');if(!$in||!$out)throw new RuntimeException('Cannot open backup files');chmod($output,0600);
        try{self::write($out,self::MAGIC.$header);while(!feof($in)){$chunk=fread($in,65536);if($chunk===false)throw new RuntimeException('Backup read failed');if($chunk==='')break;$cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,'',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);self::write($out,pack('N',strlen($cipher)).$cipher);}
            $final=sodium_crypto_secretstream_xchacha20poly1305_push($state,'','',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);self::write($out,pack('N',strlen($final)).$final);if(!fflush($out))throw new RuntimeException('Backup flush failed');
        }catch(Throwable $e){fclose($in);fclose($out);@unlink($output);throw $e;}fclose($in);fclose($out);sodium_memzero($key);
    }
    public static function decrypt(string $input,string $output,string $encodedKey): void
    {
        $key=self::key($encodedKey);$in=fopen($input,'rb');$out=fopen($output,'xb');if(!$in||!$out)throw new RuntimeException('Cannot open backup files');chmod($output,0600);
        try{if(self::read($in,strlen(self::MAGIC))!==self::MAGIC)throw new RuntimeException('Unsupported backup');$header=self::read($in,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);$state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);$final=false;
            while(!$final){$length=unpack('N',self::read($in,4))[1];if($length<17||$length>65553)throw new RuntimeException('Invalid backup chunk');$result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::read($in,$length),'');if($result===false)throw new RuntimeException('Backup authentication failed');[$plain,$tag]=$result;self::write($out,$plain);$final=$tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;}
            if(fread($in,1)!=='')throw new RuntimeException('Unexpected trailing backup data');if(!fflush($out))throw new RuntimeException('Restore flush failed');
        }catch(Throwable $e){fclose($in);fclose($out);@unlink($output);throw $e;}fclose($in);fclose($out);sodium_memzero($key);
    }
    public static function archive(string $root,string $sqlFile,string $tarPath): array
    {
        $root=realpath($root);if(!$root||!is_file($sqlFile))throw new RuntimeException('Backup source is missing');
        $tar=new PharData($tarPath);$manifest=['format'=>1,'created_at'=>gmdate('c'),'files'=>[]];
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$file){
            $path=$file->getPathname();$relative=substr($path,strlen($root)+1);
            if($file->isLink())throw new RuntimeException('Backup source contains a symlink: '.$relative);
            if(!$file->isFile()||preg_match('~^(?:vendor/|\.git/|tests/|storage/(?:cache|logs|quarantine|backups)/)~',$relative))continue;
            $name='files/'.$relative;$tar->addFile($path,$name);$manifest['files'][$name]=hash_file('sha256',$path);
        }
        $tar->addFile($sqlFile,'database.sql');$manifest['files']['database.sql']=hash_file('sha256',$sqlFile);
        $tar->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));unset($tar);chmod($tarPath,0600);return $manifest;
    }
    public static function verifyArchive(string $tarPath): array
    {
        $tar=new PharData($tarPath);$manifest=json_decode($tar['manifest.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
        if(($manifest['format']??null)!==1||empty($manifest['files']['database.sql']))throw new RuntimeException('Invalid backup manifest');
        $seen=[];foreach(new RecursiveIteratorIterator($tar)as$f){$name=substr($f->getPathname(),strlen('phar://'.$tarPath.'/'));if($f->isDir())continue;
            if($f->isLink()||str_contains($name,'\\')||preg_match('/[\x00-\x1f\x7f]/',$name)||(!str_starts_with($name,'files/')&&!in_array($name,['database.sql','manifest.json'],true))||array_intersect(explode('/',$name),['.','..','']))throw new RuntimeException('Unsafe archive entry');
            if($name==='manifest.json')continue;if(!isset($manifest['files'][$name])||!hash_equals($manifest['files'][$name],hash_file('sha256',$f->getPathname())))throw new RuntimeException('Backup checksum mismatch');$seen[$name]=true;
        }
        if(count($seen)!==count($manifest['files']))throw new RuntimeException('Missing backup files');return $manifest;
    }
    public static function extractToEmptyDirectory(string $tarPath,string $target): void
    {
        self::verifyArchive($tarPath);if(file_exists($target))throw new RuntimeException('Restore target must be a new directory');if(!mkdir($target,0700,true))throw new RuntimeException('Cannot create restore target');
        $tar=new PharData($tarPath);if(!$tar->extractTo($target,null,false))throw new RuntimeException('Cannot extract backup');
        // Secrets remain private until the operator places files into their deployment directory.
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())chmod($f->getPathname(),0600);
    }
}
