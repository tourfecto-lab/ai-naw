<?php
/** Private originals with owner-scoped previews and expiring publish capabilities. */
class PrivateMedia
{
    public static function valid(string $path): bool{return (bool)preg_match('#^/uploads/media/[a-zA-Z0-9_-]+\.(png|jpg|jpeg|webp|mp4)$#D',$path);}
    public static function directory(): string
    {
        $dir=ROOT_PATH.'/storage/private/creative-media';
        foreach([ROOT_PATH.'/storage',ROOT_PATH.'/storage/private',$dir] as $p){if(is_link($p))throw new RuntimeException('Unsafe private storage');if(!is_dir($p)&&!mkdir($p,0700,true)&&!is_dir($p))throw new RuntimeException('Private storage unavailable');}
        return $dir;
    }
    public static function resolve(string $path): string
    {
        if(!self::valid($path))throw new InvalidArgumentException('Invalid asset path');$name=basename($path);
        foreach([self::directory(),ROOT_PATH.'/public_html/uploads/media'] as $dir){$full=$dir.'/'.$name;$base=realpath($dir);$actual=realpath($full);if($base&&$actual&&dirname($actual)===$base&&is_file($actual)&&!is_link($dir)&&!is_link($full))return $actual;}
        throw new RuntimeException('Asset unavailable');
    }
    public static function owned(int $userId,int $id,bool $includeTrash=false): array
    {
        $s=Database::getInstance()->getConnection()->prepare('SELECT * FROM media_items WHERE id=? AND user_id=?'.($includeTrash?'':' AND deleted_at IS NULL'));$s->execute([$id,$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new InvalidArgumentException('Asset unavailable');return $row;
    }
    public static function preview(int $id,bool $thumb=false): string{return '/api/creative-studio/media/'.$id.'/content'.($thumb?'?thumbnail=1':'');}
    private static function key(): string
    {
        $secret=defined('JWT_SECRET')?(string)JWT_SECRET:(defined('ENCRYPTION_KEY')?(string)ENCRYPTION_KEY:'');
        if(strlen($secret)<32)throw new RuntimeException('Publish signing secret unavailable');return hash_hmac('sha256','tourfecto_creative_publish_v1',$secret);
    }
    public static function publishUrl(MediaItem $item,int $ttl=86400): string
    {
        $id=(int)$item->getAttribute('id');$row=self::owned((int)$item->getAttribute('user_id'),$id);$path=(string)$row['file_path'];$version=(int)($row['publish_version']??0);if(!self::valid($path)||$row['status']!=='completed'||!empty($row['deleted_at']))throw new InvalidArgumentException('Asset unavailable');
        $expires=time()+min(86400,max(60,$ttl));$sig=hash_hmac('sha256',$id.'|'.$expires.'|'.$path.'|'.$version,self::key());$base=rtrim(defined('APP_URL')?(string)APP_URL:'','/');if(!preg_match('#^https://[^/]+#',$base))throw new RuntimeException('HTTPS publish base unavailable');return $base.'/api/creative-studio/publish-media/'.$id.'?expires='.$expires.'&signature='.$sig;
    }
    public static function verify(array $row,$expires,$signature): bool
    {
        if(!is_scalar($expires)||!ctype_digit((string)$expires)||!is_string($signature)||!preg_match('/^[a-f0-9]{64}$/D',$signature)||(int)$expires<time()||(int)$expires>time()+86400||($row['status']??'')!=='completed'||!empty($row['deleted_at'])||!self::valid((string)($row['file_path']??'')))return false;
        try{return hash_equals(hash_hmac('sha256',(int)$row['id'].'|'.(int)$expires.'|'.$row['file_path'].'|'.(int)($row['publish_version']??0),self::key()),$signature);}catch(Throwable $e){return false;}
    }
    public static function byteRange(?string $header,int $size): array
    {
        if($size<1)throw new RuntimeException('Empty asset');if(!$header)return [0,$size-1,200];
        if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$header,$m)||($m[1]===''&&$m[2]===''))throw new InvalidArgumentException('Invalid range');
        if($m[1]===''){$length=min($size,(int)$m[2]);if($length<1)throw new InvalidArgumentException('Invalid range');return [$size-$length,$size-1,206];}
        $start=(int)$m[1];$end=$m[2]===''?$size-1:min($size-1,(int)$m[2]);if($start>=$size||$end<$start)throw new InvalidArgumentException('Invalid range');return [$start,$end,206];
    }
    /** صور العميل اللي اترفعت أو اتسحبت من موقعه (/uploads/social/{user}/...) */
    public static function socialFile(string $path): ?string
    {
        if(!preg_match('#^/uploads/social/[1-9][0-9]*/[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|mp4|mov)$#D',$path))return null;
        $full=ROOT_PATH.'/public_html'.$path;return is_file($full)&&!is_link($full)?$full:null;
    }
    public static function stream(string $path,int $id,bool $download=false): void
    {
        $full=self::socialFile($path)??self::resolve($path);$size=filesize($full);if($size===false)throw new RuntimeException('Asset unavailable');
        try{[$start,$end,$status]=self::byteRange($_SERVER['HTTP_RANGE']??null,$size);}catch(InvalidArgumentException $e){http_response_code(416);header('Content-Range: bytes */'.$size);return;}
        $mime=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','mp4'=>'video/mp4','mov'=>'video/quicktime'][strtolower(pathinfo($full,PATHINFO_EXTENSION))];
        header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('Accept-Ranges: bytes');http_response_code($status);
        if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);header('Content-Length: '.($end-$start+1));if($download)header('Content-Disposition: attachment; filename="tourfecto-'.$id.'.'.pathinfo($full,PATHINFO_EXTENSION).'"');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return;
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();$handle=fopen($full,'rb');if(!$handle)throw new RuntimeException('Asset read failed');try{fseek($handle,$start);$left=$end-$start+1;while($left>0&&!feof($handle)&&!connection_aborted()){$bytes=fread($handle,min(65536,$left));if($bytes===false||$bytes==='')break;echo $bytes;$left-=strlen($bytes);}}finally{fclose($handle);}
    }
}
