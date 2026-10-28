<?php
/** Stitch storyboard scene clips into one MP4 locally: no paid AI provider call and no wallet debit. */
class RenderStoryboardJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $id=(int)($payload['media_item_id']??0);if($id<1||!GenerationLifecycle::lock($id))return;$output=null;
        try{$item=(new MediaItem())->find($id);if(!$item||$item->getAttribute('status')!=='generating')return;if($item->getAttribute('file_path')&&$item->getAttribute('generation_stage')==='billing'){GenerationLifecycle::complete($item,'media_video');return;}
            $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];if(($meta['source']??'')!=='storyboard_render')throw new RuntimeException('Invalid storyboard job');
            $userId=(int)$item->getAttribute('user_id');$files=[];$durations=[];$audio=empty($meta['edit']['mute']);
            foreach((array)($meta['parents']??[]) as $parentId){$row=PrivateMedia::owned($userId,(int)$parentId);if($row['status']!=='completed'||$row['type']!=='short_video')throw new RuntimeException('Scene unavailable');$file=PrivateMedia::resolve((string)$row['file_path']);$probe=VideoEditing::probe($file);$files[]=$file;$durations[]=$probe['duration'];if(!isset($size))$size=$probe;if($audio&&!VideoEditing::hasAudio($file))$audio=false;}
            if(count($files)<2||!isset($size))throw new RuntimeException('Storyboard needs at least two scenes');
            GenerationLifecycle::stage($item,'processing');
            $output=PrivateMedia::directory().'/story_'.bin2hex(random_bytes(12)).'.mp4';
            $args=[VideoEditing::binary('ffmpeg'),'-nostdin','-v','error','-y'];
            foreach($files as $f)array_push($args,'-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-i',$f);
            array_push($args,'-filter_complex_threads','1','-filter_complex',VideoEditing::concatFilter($durations,$size['width'],$size['height'],$audio),'-map','[v]');
            if($audio)array_push($args,'-map','[a]','-c:a','aac','-b:a','160k');else $args[]='-an';
            array_push($args,'-c:v','libx264','-preset','veryfast','-crf','19','-threads','2','-movflags','+faststart',$output);
            VideoEditing::process($args,180);
            $bytes=file_get_contents($output);if($bytes===false)throw new RuntimeException('Rendered storyboard missing');
            $path=GeneratedMediaFiles::write($id,$bytes,'mp4');$item->setAttribute('file_path',$path);$item->setAttribute('width',$size['width']);$item->setAttribute('height',$size['height']);$item->setAttribute('duration_seconds',(int)ceil(array_sum($durations)));
            $poster=VideoEditing::poster(PrivateMedia::resolve($path),$id);if($poster)$item->setAttribute('thumbnail_path',$poster);
            GenerationLifecycle::stage($item,'billing');GenerationLifecycle::complete($item,'media_video');
        }catch(Throwable $e){if(isset($item)&&$item&&$item->getAttribute('status')!=='completed')GenerationLifecycle::fail($item,$e->getMessage());else throw $e;}
        finally{if($output&&is_file($output))@unlink($output);GenerationLifecycle::unlock($id);}
    }
}
