<?php
/** Bounded FFmpeg jobs with argv execution, validated local files and raster brand overlays. */
class VideoEditing
{
    public static function binary(string $name): string
    {
        $value=function_exists('env')?env($name==='ffmpeg'?'MEDIA_FFMPEG_BIN':'MEDIA_FFPROBE_BIN'):null;return is_string($value)&&$value!==''?$value:'/usr/bin/'.$name;
    }
    public static function available(): bool
    {
        return function_exists('proc_open')&&is_executable(self::binary('ffmpeg'))&&is_executable(self::binary('ffprobe'));
    }
    public static function process(array $argv,int $timeout=50): string
    {
        if(!function_exists('proc_open'))throw new RuntimeException('Video editing unavailable');$pipes=[];$proc=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($proc))throw new RuntimeException('Video process unavailable');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$deadline=microtime(true)+$timeout;$exit=-1;
        try{do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);if(strlen($out)>1048576||strlen($err)>2097152)throw new RuntimeException('Video process output exceeded limit');$state=proc_get_status($proc);if(!$state['running']){$exit=$state['exitcode'];break;}if(microtime(true)>$deadline){proc_terminate($proc,9);throw new RuntimeException('Video processing timeout');}usleep(50000);}while(true);$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);if($exit!==0)throw new RuntimeException('Video processing failed');return $out;}finally{if(proc_get_status($proc)['running'])@proc_terminate($proc,9);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($proc);}
    }
    public static function probe(string $file): array
    {
        $json=self::process([self::binary('ffprobe'),'-v','error','-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-select_streams','v:0','-show_entries','stream=width,height:format=duration','-of','json',$file],10);$data=json_decode($json,true);$stream=$data['streams'][0]??[];$width=(int)($stream['width']??0);$height=(int)($stream['height']??0);$duration=(float)($data['format']['duration']??0);
        if($width<2||$height<2||$width>8192||$height>8192||$width*$height>34000000||$duration<=0||!is_finite($duration))throw new InvalidArgumentException('Invalid source video');$scale=min(1,1280/max($width,$height));return ['width'=>max(2,(int)floor($width*$scale/2)*2),'height'=>max(2,(int)floor($height*$scale/2)*2),'duration'=>$duration];
    }
    /** أبعاد Veo الفعلية حسب الجودة والاتجاه (بدون الاعتماد على ffprobe) */
    public static function nominalSize(string $ratio,string $resolution): array
    {
        $long=['720p'=>1280,'1080p'=>1920,'4k'=>3840][$resolution]??1280;$short=(int)round($long*9/16);
        return $ratio==='9:16'?['width'=>$short,'height'=>$long]:['width'=>$long,'height'=>$short];
    }
    /** صورة غلاف للفيديو (أول ثانية) عشان المكتبة والنشر يعرضوا معاينة؛ null لو FFmpeg مش متاح */
    public static function poster(string $file,int $id): ?string
    {
        if(!self::available()||!is_file($file))return null;
        $tmp=PrivateMedia::directory().'/poster_'.bin2hex(random_bytes(10)).'.jpg';
        try{
            self::process([self::binary('ffmpeg'),'-nostdin','-v','error','-y','-ss','1','-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-i',$file,'-frames:v','1','-vf','scale=480:-2','-threads','1',$tmp],15);
            $bytes=@file_get_contents($tmp);return is_string($bytes)&&$bytes!==''?GeneratedMediaFiles::write($id,$bytes,'jpg'):null;
        }catch(Throwable $e){return null;}finally{if(is_file($tmp))@unlink($tmp);}
    }
    public static function settings(array $input,float $duration): array
    {
        $start=$input['start']??0;$end=$input['end']??min(8,$duration);$mute=$input['mute']??false;
        if(!is_numeric($start)||!is_numeric($end)||!in_array($mute,[true,false,0,1,'0','1'],true))throw new InvalidArgumentException('Invalid editing options');$start=(float)$start;$end=(float)$end;
        if(!is_finite($start)||!is_finite($end)||$start<0||$end>$duration+.05||$end-$start<.5||$end-$start>30)throw new InvalidArgumentException('Invalid trim');return ['start'=>$start,'end'=>$end,'mute'=>(bool)$mute];
    }
    public static function enqueue(int $userId,int $sourceId,string $overlay,array $input,string $key): MediaItem
    {
        if(!self::available()||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Video editing unavailable');$row=PrivateMedia::owned($userId,$sourceId);if($row['type']!=='short_video'||$row['status']!=='completed')throw new InvalidArgumentException('Video unavailable');$probe=self::probe(PrivateMedia::resolve((string)$row['file_path']));$settings=self::settings($input,$probe['duration']);if(strlen($overlay)>ReferenceMedia::MAX_BYTES)throw new InvalidArgumentException('Overlay too large');$image=GeneratedMediaFiles::image(base64_encode($overlay));if($image['extension']!=='png'||$image['width']>1280||$image['height']>1280)throw new InvalidArgumentException('Invalid overlay');
        $gd=@imagecreatefromstring($image['data']);if(!$gd)throw new InvalidArgumentException('Invalid overlay');imagealphablending($gd,false);imagesavealpha($gd,true);ob_start();imagepng($gd);$clean=ob_get_clean();imagedestroy($gd);if(!is_string($clean))throw new RuntimeException('Overlay read failed');$overlayPath=null;
        try{return Database::getInstance()->transaction(function()use($userId,$sourceId,$row,$probe,$settings,$key,$clean,&$overlayPath){
            $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn())throw new RuntimeException('Owner unavailable');$s=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key=?');$s->execute([$userId,$key]);$prior=$s->fetch(PDO::FETCH_ASSOC);if($prior)return new MediaItem($prior);
            $s=$pdo->prepare("SELECT COUNT(*) FROM media_items WHERE user_id=? AND status='generating' AND (generation_stage IS NULL OR generation_stage<>'download_failed')");$s->execute([$userId]);if((int)$s->fetchColumn()>=2)throw new InvalidArgumentException('Active job limit reached');$queue=Container::getInstance()->make(QueueManager::class);if(!$queue->isReady())throw new RuntimeException('Editing queue unavailable');
            $meta=['source'=>'video_editor','parent_id'=>$sourceId,'edit'=>$settings,'billing'=>['source'=>'edit','amount'=>0,'currency'=>'USD','settled'=>true]];$item=new MediaItem(['user_id'=>$userId,'type'=>'short_video','prompt'=>'Edited video — #'.$sourceId,'aspect_ratio'=>$row['aspect_ratio'],'duration_seconds'=>(int)ceil($settings['end']-$settings['start']),'width'=>$probe['width'],'height'=>$probe['height'],'status'=>'generating','generation_stage'=>'queued','usage_charged'=>1,'request_key'=>$key,'generation_meta'=>json_encode($meta,JSON_THROW_ON_ERROR)]);GenerationLifecycle::save($item);
            $overlayPath=GeneratedMediaFiles::write((int)$item->getAttribute('id'),$clean,'png');$meta['overlay']=$overlayPath;$item->setAttribute('generation_meta',json_encode($meta,JSON_THROW_ON_ERROR));$job=$queue->push(RenderCreativeVideoJob::class,['media_item_id'=>(int)$item->getAttribute('id')],'media');if(!$job)throw new RuntimeException('Editing queue rejected');$item->setAttribute('job_id',(int)$job);GenerationLifecycle::save($item);return $item;
        });}catch(Throwable $e){if($overlayPath)try{@unlink(PrivateMedia::resolve($overlayPath));}catch(Throwable $ignored){}throw $e;}
    }
    public static function hasAudio(string $file): bool
    {
        try{$json=self::process([self::binary('ffprobe'),'-v','error','-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-select_streams','a','-show_entries','stream=index','-of','json',$file],10);$d=json_decode($json,true);return !empty($d['streams']);}catch(Throwable $e){return false;}
    }
    /** لقطات مشروع السكربت بالترتيب (لازم كلها جاهزة ولنفس العميل) */
    public static function storyboardClips(int $userId,string $group): array
    {
        if(!preg_match('/^[a-f0-9]{24}$/D',$group))throw new InvalidArgumentException('Invalid storyboard');
        $s=Database::getInstance()->getConnection()->prepare("SELECT * FROM media_items WHERE user_id=? AND type='short_video' AND deleted_at IS NULL AND generation_meta LIKE ?");$s->execute([$userId,'%"group":"'.$group.'"%']);
        $clips=[];$total=0;
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$m=json_decode((string)$row['generation_meta'],true)['storyboard']??null;if(!is_array($m)||($m['group']??'')!==$group)continue;$clips[(int)$m['scene']]=$row;$total=(int)$m['total'];}
        ksort($clips);
        return ['clips'=>array_values($clips),'total'=>$total];
    }
    /** تجميع لقطات السكربت في فيديو واحد (مونتاج محلي من غير خصم توليد) */
    public static function enqueueStoryboard(int $userId,string $group,bool $mute,string $key): MediaItem
    {
        if(!self::available()||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Video editing unavailable');
        $set=self::storyboardClips($userId,$group);$clips=$set['clips'];
        if(count($clips)<2)throw new InvalidArgumentException('At least two finished scenes are required');
        foreach($clips as $c){if($c['status']!=='completed'||!PrivateMedia::valid((string)$c['file_path']))throw new InvalidArgumentException('Wait until every scene is ready');}
        $ratio=$clips[0]['aspect_ratio'];foreach($clips as $c)if($c['aspect_ratio']!==$ratio)throw new InvalidArgumentException('Scenes must share one orientation');
        return Database::getInstance()->transaction(function()use($userId,$group,$mute,$key,$clips,$ratio){
            $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn())throw new RuntimeException('Owner unavailable');
            $s=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key=?');$s->execute([$userId,$key]);$prior=$s->fetch(PDO::FETCH_ASSOC);if($prior)return new MediaItem($prior);
            $s=$pdo->prepare("SELECT COUNT(*) FROM media_items WHERE user_id=? AND status='generating' AND (generation_stage IS NULL OR generation_stage<>'download_failed')");$s->execute([$userId]);if((int)$s->fetchColumn()>=MediaGenerationService::MAX_ACTIVE)throw new InvalidArgumentException('Active job limit reached');
            $queue=Container::getInstance()->make(QueueManager::class);if(!$queue->isReady())throw new RuntimeException('Editing queue unavailable');
            $ids=array_map(fn($c)=>(int)$c['id'],$clips);$seconds=0;foreach($clips as $c)$seconds+=(int)($c['duration_seconds']?:8);
            $meta=['source'=>'storyboard_render','group'=>$group,'parents'=>$ids,'edit'=>['mute'=>$mute],'billing'=>['source'=>'edit','amount'=>0,'currency'=>'USD','settled'=>true]];
            $scriptId=(int)(json_decode((string)$clips[0]['generation_meta'],true)['storyboard']['script_id']??0);$s=$pdo->prepare('SELECT topic FROM video_scripts WHERE id=? AND user_id=?');$s->execute([$scriptId,$userId]);$topic=trim((string)$s->fetchColumn());
            $item=new MediaItem(['user_id'=>$userId,'type'=>'short_video','prompt'=>mb_substr('فيديو مجمّع'.($topic!==''?': '.$topic:'').' ('.count($ids).' مشاهد)',0,500),'aspect_ratio'=>$ratio,'duration_seconds'=>$seconds,'status'=>'generating','generation_stage'=>'queued','usage_charged'=>1,'request_key'=>$key,'generation_meta'=>json_encode($meta,JSON_THROW_ON_ERROR)]);GenerationLifecycle::save($item);
            $job=$queue->push(RenderStoryboardJob::class,['media_item_id'=>(int)$item->getAttribute('id')],'media');if(!$job)throw new RuntimeException('Editing queue rejected');$item->setAttribute('job_id',(int)$job);GenerationLifecycle::save($item);return $item;
        });
    }
    /** filter_complex للتجميع: توحيد المقاس والفريمات + fade خفيف بين اللقطات */
    public static function concatFilter(array $durations,int $width,int $height,bool $audio): string
    {
        $parts=[];$labels='';$fade=0.25;
        foreach(array_values($durations) as $i=>$d){
            $d=max(0.6,(float)$d);$out=number_format($d-$fade,3,'.','');
            $parts[]="[{$i}:v]scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30,format=yuv420p,fade=t=in:st=0:d={$fade},fade=t=out:st={$out}:d={$fade}[v{$i}]";
            if($audio)$parts[]="[{$i}:a]aresample=48000,aformat=channel_layouts=stereo,afade=t=in:st=0:d={$fade},afade=t=out:st={$out}:d={$fade}[a{$i}]";
            $labels.="[v{$i}]".($audio?"[a{$i}]":'');
        }
        $parts[]=$labels.'concat=n='.count($durations).':v=1:a='.($audio?1:0).'[v]'.($audio?'[a]':'');
        return implode(';',$parts);
    }
}
