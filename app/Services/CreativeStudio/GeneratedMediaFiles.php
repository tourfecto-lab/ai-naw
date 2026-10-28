<?php
class GeneratedMediaFiles
{
    public static function image(string $base64): array
    {
        if(strlen($base64)>45*1024*1024)throw new RuntimeException('Generated image exceeds size limit');
        $data=base64_decode($base64,true);$info=$data!==false?@getimagesizefromstring($data):false;
        if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp'],true)||$info[0]>8192||$info[1]>8192||$info[0]*$info[1]>20000000)throw new RuntimeException('Invalid generated image');
        return ['data'=>$data,'width'=>$info[0],'height'=>$info[1],'extension'=>['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$info['mime']]];
    }
    /** Exact output ratio, without stretching or upscaling. Uses a center crop. */
    public static function fitAspect(array $image, string $ratio): array
    {
        $ratios = ['1:1'=>[1,1], '4:5'=>[4,5], '4:3'=>[4,3], '3:4'=>[3,4], '16:9'=>[16,9], '9:16'=>[9,16]];
        if (!isset($ratios[$ratio])) throw new InvalidArgumentException('Invalid output ratio');
        [$a,$b] = $ratios[$ratio];
        $w = (int)$image['width']; $h = (int)$image['height'];
        if ($w*$b === $h*$a) return $image;
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('GD is required for exact image aspect ratios');
        $unit = (int)floor(min($w/$a,$h/$b));
        if ($unit < 1) throw new RuntimeException('Image too small for output ratio');
        $tw = $unit*$a; $th = $unit*$b;
        $src = @imagecreatefromstring($image['data']);
        if (!$src) throw new RuntimeException('Cannot decode image for ratio processing');
        $dst = null;
        try {
            $dst = imagecreatetruecolor($tw,$th);
            if (!$dst) throw new RuntimeException('Cannot allocate image output');
            imagealphablending($dst,false); imagesavealpha($dst,true);
            $transparent = imagecolorallocatealpha($dst,0,0,0,127);
            imagefill($dst,0,0,$transparent);
            if (!imagecopy($dst,$src,0,0,(int)floor(($w-$tw)/2),(int)floor(($h-$th)/2),$tw,$th)) throw new RuntimeException('Image crop failed');
            ob_start();
            try { $ok = imagepng($dst,null,6); $bytes = ob_get_contents(); }
            finally { ob_end_clean(); }
            if (!$ok || !$bytes) throw new RuntimeException('Image encoding failed');
            return self::image(base64_encode($bytes));
        } finally { if ($dst) imagedestroy($dst); imagedestroy($src); }
    }
    /** أقل عرض احترافي لكل نسبة (مقاسات المنصات: 1080 انستجرام/ستوري، 1440 تسويقية 4:3، 1920 غلاف/يوتيوب 16:9) */
    public const PLATFORM_MIN_WIDTH=['1:1'=>1080,'4:5'=>1080,'3:4'=>1080,'9:16'=>1080,'4:3'=>1440,'16:9'=>1920];
    /**
     * نسخة التسليم النهائية: نسبة دقيقة + تكبير لحد مقاس المنصة (بحد أقصى 2x مع شحذ خفيف)
     * + JPEG عالي الجودة (إنستجرام مابيقبلش PNG). الصور اللي فيها شفافية فعلية بتفضل PNG.
     * @return array{data:string,width:int,height:int,extension:string,upscaled:bool}
     */
    public static function deliver(array $image,string $ratio): array
    {
        $image=self::fitAspect($image,$ratio);
        $w=(int)$image['width'];$h=(int)$image['height'];
        $target=self::PLATFORM_MIN_WIDTH[$ratio]??1080;
        $scale=$w<$target?min(2.0,$target/$w):1.0;
        [$a,$b]=array_map('intval',explode(':',$ratio));
        $src=@imagecreatefromstring($image['data']);if(!$src)throw new RuntimeException('Cannot decode image for delivery');
        try{
            $alpha=self::hasTransparency($src);
            if($scale>1.0){
                $unit=(int)floor($w*$scale/$a);$tw=$unit*$a;$th=$unit*$b;
                $dst=imagecreatetruecolor($tw,$th);if(!$dst)throw new RuntimeException('Cannot allocate delivery image');
                imagealphablending($dst,false);imagesavealpha($dst,true);
                if(!imagecopyresampled($dst,$src,0,0,0,0,$tw,$th,$w,$h)){imagedestroy($dst);throw new RuntimeException('Upscale failed');}
                // شحذ خفيف يعوّض نعومة التكبير
                @imageconvolution($dst,[[0,-0.5,0],[-0.5,5,-0.5],[0,-0.5,0]],3,0);
                imagedestroy($src);$src=$dst;$w=$tw;$h=$th;
            }
            ob_start();
            try{
                if($alpha){imagesavealpha($src,true);$ok=imagepng($src,null,6);$ext='png';}
                else{$flat=imagecreatetruecolor($w,$h);imagefill($flat,0,0,imagecolorallocate($flat,255,255,255));imagecopy($flat,$src,0,0,0,0,$w,$h);imageinterlace($flat,true);$ok=imagejpeg($flat,null,92);imagedestroy($flat);$ext='jpg';}
                $bytes=ob_get_contents();
            }finally{ob_end_clean();}
            if(!$ok||!$bytes)throw new RuntimeException('Delivery encoding failed');
            $out=self::image(base64_encode($bytes));$out['upscaled']=$scale>1.0;
            if($out['width']*$b!==$out['height']*$a)throw new RuntimeException('Delivery ratio mismatch');
            return $out;
        }finally{if($src)imagedestroy($src);}
    }
    /** فحص شفافية بعينة شبكية (سريع حتى مع الصور الكبيرة) */
    public static function hasTransparency($gd): bool
    {
        if(!imageistruecolor($gd))return imagecolortransparent($gd)>=0;
        $w=imagesx($gd);$h=imagesy($gd);$step=max(1,(int)floor(min($w,$h)/48));
        for($y=0;$y<$h;$y+=$step)for($x=0;$x<$w;$x+=$step){if(((imagecolorat($gd,$x,$y)>>24)&0x7F)>0)return true;}
        foreach([[0,0],[$w-1,0],[0,$h-1],[$w-1,$h-1]] as [$x,$y])if(((imagecolorat($gd,$x,$y)>>24)&0x7F)>0)return true;
        return false;
    }
    public static function write(int $id,string $data,string $extension): string
    {
        if($id<1||!in_array($extension,['png','jpg','webp','mp4'],true)||$data==='')throw new InvalidArgumentException('Invalid media file');
        if($extension==='mp4'&&(strlen($data)>150*1024*1024||strlen($data)<12||substr($data,4,4)!=='ftyp'))throw new RuntimeException('Invalid MP4 response');
        $dir=PrivateMedia::directory();
        if(is_link($dir))throw new RuntimeException('Unsafe media directory');
        if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Media directory unavailable');
        $name='media_'.$id.'_'.bin2hex(random_bytes(12)).'.'.$extension;$tmp=$dir.'/'.$name.'.part';
        try{if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||!rename($tmp,$dir.'/'.$name))throw new RuntimeException('Media write failed');}finally{if(is_file($tmp))@unlink($tmp);}
        return '/uploads/media/'.$name;
    }
    public static function thumbnail(string $path,int $id): ?string
    {
        if(!preg_match('#^/uploads/media/[a-zA-Z0-9_-]+\.(png|jpg|webp)$#D',$path))return null;
        $data=@file_get_contents(PrivateMedia::resolve($path));$image=$data!==false?@imagecreatefromstring($data):false;if(!$image)return null;
        try{$w=imagesx($image);$h=imagesy($image);$scale=min(1,480/max($w,$h));$thumb=imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));$white=imagecolorallocate($thumb,255,255,255);imagefill($thumb,0,0,$white);imagecopyresampled($thumb,$image,0,0,0,0,imagesx($thumb),imagesy($thumb),$w,$h);ob_start();imagejpeg($thumb,null,82);$bytes=ob_get_clean();imagedestroy($thumb);return self::write($id,(string)$bytes,'jpg');}finally{imagedestroy($image);}
    }
}
