<?php
/** Owner-scoped local references; never accepts remote URLs or arbitrary paths. */
class ReferenceMedia
{
    public const MAX_BYTES=10485760;
    public static function validateInline(array $reference): void
    {
        if(!in_array($reference['mimeType']??null,['image/png','image/jpeg','image/webp'],true)||!is_string($reference['data']??null)||strlen($reference['data'])>self::MAX_BYTES*1.4)throw new InvalidArgumentException('Invalid reference payload');
        $image=GeneratedMediaFiles::image($reference['data']);$mime=$image['extension']==='jpg'?'image/jpeg':'image/'.$image['extension'];if($mime!==$reference['mimeType'])throw new InvalidArgumentException('Reference MIME mismatch');
    }
    public static function load(int $userId,int $id): array
    {
        $pdo=Database::getInstance()->getConnection();$s=$pdo->prepare("SELECT * FROM media_items WHERE id=? AND user_id=? AND status='completed' AND type<>'short_video' AND deleted_at IS NULL");$s->execute([$id,$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new InvalidArgumentException('Reference unavailable');
        $full=PrivateMedia::resolve((string)$row['file_path']);if(filesize($full)>self::MAX_BYTES)throw new InvalidArgumentException('Reference unavailable');
        $bytes=file_get_contents($full);if($bytes===false)throw new RuntimeException('Reference read failed');$image=GeneratedMediaFiles::image(base64_encode($bytes));
        return ['mimeType'=>$image['extension']==='jpg'?'image/jpeg':'image/'.$image['extension'],'data'=>base64_encode($image['data'])];
    }
    public static function store(int $userId,string $bytes,int $parentId=0): MediaItem
    {
        if($userId<1||strlen($bytes)>self::MAX_BYTES)throw new InvalidArgumentException('Upload too large');
        if($parentId)self::load($userId,$parentId);
        $image=GeneratedMediaFiles::image(base64_encode($bytes));if($image['width']*$image['height']>8000000)throw new InvalidArgumentException('Upload dimensions too large');
        // Decode/re-encode to remove metadata, appended payloads and untrusted source file names.
        // JPEG للصور المعتمة (إنستجرام مابيقبلش PNG)، وPNG بس لو فيه شفافية فعلية
        $gd=@imagecreatefromstring($image['data']);if(!$gd)throw new InvalidArgumentException('Invalid upload');$alpha=GeneratedMediaFiles::hasTransparency($gd);ob_start();
        if($alpha){imagealphablending($gd,false);imagesavealpha($gd,true);$encoded=@imagepng($gd);$ext='png';}
        else{$flat=imagecreatetruecolor($image['width'],$image['height']);imagefill($flat,0,0,imagecolorallocate($flat,255,255,255));imagecopy($flat,$gd,0,0,0,0,$image['width'],$image['height']);$encoded=@imagejpeg($flat,null,92);imagedestroy($flat);$ext='jpg';}
        $clean=ob_get_clean();imagedestroy($gd);if(!$encoded||!is_string($clean)||strlen($clean)>self::MAX_BYTES)throw new RuntimeException('Image normalization failed');
        $phash=CreativeAssetRegistry::fingerprint($clean);
        // نفس الصورة اترفعت قبل كده: نرجّع الموجودة بدل ما نكررها في المكتبة
        if($phash&&!$parentId&&($dup=CreativeAssetRegistry::nearest(Database::getInstance()->getConnection(),$userId,$phash,0,CreativeAssetRegistry::DUPLICATE_BITS,600,$image['width']/$image['height']))){$existing=(new MediaItem())->find($dup['id']);if($existing&&$existing->getAttribute('type')!=='short_video')return $existing;}
        $path=null;$thumb=null;
        try{return Database::getInstance()->transaction(function()use($userId,$parentId,$image,$clean,$ext,$phash,&$path,&$thumb){
            $item=new MediaItem(['user_id'=>$userId,'type'=>'marketing_image','prompt'=>$parentId?'Brand layout — image #'.$parentId:'Uploaded reference image','aspect_ratio'=>CreativeAssetRegistry::closestRatio($image['width'],$image['height']),'status'=>'completed','generation_stage'=>'completed','usage_charged'=>1,'generation_meta'=>json_encode(array_filter(['source'=>$parentId?'brand_editor':'upload','parent_id'=>$parentId,'phash'=>$phash,'billing'=>['source'=>'upload','amount'=>0,'currency'=>'USD','settled'=>true]],static fn($v)=>$v!==null),JSON_THROW_ON_ERROR)]);GenerationLifecycle::save($item);
            $path=GeneratedMediaFiles::write((int)$item->getAttribute('id'),$clean,$ext);$thumb=GeneratedMediaFiles::thumbnail($path,(int)$item->getAttribute('id'));
            $item->setAttribute('file_path',$path);$item->setAttribute('thumbnail_path',$thumb?:$path);$item->setAttribute('width',$image['width']);$item->setAttribute('height',$image['height']);GenerationLifecycle::save($item);return $item;
        });}catch(Throwable $e){foreach([$path,$thumb] as $p)if($p&&preg_match('#^/uploads/media/[a-zA-Z0-9_-]+\.(png|jpg)$#D',$p))@unlink(PrivateMedia::resolve($p));throw $e;}
    }
}
