<?php
/** Local editing only: never invokes a paid AI provider or creates a wallet debit. */
class RenderCreativeVideoJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $id=(int)($payload['media_item_id']??0);if($id<1||!GenerationLifecycle::lock($id))return;$output=null;$poster=null;$overlay=null;
        try{$item=(new MediaItem())->find($id);if(!$item||$item->getAttribute('status')!=='generating')return;if($item->getAttribute('file_path')&&$item->getAttribute('generation_stage')==='billing'){GenerationLifecycle::complete($item,'media_video');return;}
            $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];if(($meta['source']??'')!=='video_editor')throw new RuntimeException('Invalid editing job');$source=PrivateMedia::owned((int)$item->getAttribute('user_id'),(int)($meta['parent_id']??0));if($source['status']!=='completed')throw new RuntimeException('Source unavailable');$file=PrivateMedia::resolve((string)$source['file_path']);$probe=VideoEditing::probe($file);$settings=VideoEditing::settings($meta['edit']??[],$probe['duration']);$overlay=PrivateMedia::resolve((string)($meta['overlay']??''));$tag=bin2hex(random_bytes(12));$output=PrivateMedia::directory().'/render_'.$tag.'.mp4';$poster=PrivateMedia::directory().'/poster_'.$tag.'.jpg';GenerationLifecycle::stage($item,'processing');
            $filter='[0:v]scale='.$probe['width'].':'.$probe['height'].'[base];[1:v]scale='.$probe['width'].':'.$probe['height'].'[mark];[base][mark]overlay=0:0:format=auto,format=yuv420p[v]';
            $args=[VideoEditing::binary('ffmpeg'),'-nostdin','-v','error','-y','-ss',(string)$settings['start'],'-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-i',$file,'-loop','1','-i',$overlay,'-filter_complex_threads','1','-filter_complex',$filter,'-map','[v]'];if($settings['mute'])$args[]='-an';else array_push($args,'-map','0:a?','-c:a','aac','-b:a','128k');array_push($args,'-t',(string)($settings['end']-$settings['start']),'-c:v','libx264','-preset','veryfast','-crf','20','-threads','2','-movflags','+faststart',$output);VideoEditing::process($args,50);
            $bytes=file_get_contents($output);if($bytes===false)throw new RuntimeException('Rendered video missing');$path=GeneratedMediaFiles::write($id,$bytes,'mp4');$item->setAttribute('file_path',$path);$item->setAttribute('width',$probe['width']);$item->setAttribute('height',$probe['height']);
            try{VideoEditing::process([VideoEditing::binary('ffmpeg'),'-nostdin','-v','error','-y','-i',$output,'-frames:v','1','-vf','scale=480:-2','-threads','1',$poster],10);$thumb=file_get_contents($poster);if($thumb!==false)$item->setAttribute('thumbnail_path',GeneratedMediaFiles::write($id,$thumb,'jpg'));}catch(Throwable $e){Logger::warning('Video cover unavailable',['media_item_id'=>$id]);}
            GenerationLifecycle::stage($item,'billing');GenerationLifecycle::complete($item,'media_video');
        }catch(Throwable $e){if(isset($item)&&$item&&$item->getAttribute('status')!=='completed')GenerationLifecycle::fail($item,$e->getMessage());else throw $e;}finally{foreach([$output,$poster] as $p)if($p&&is_file($p))@unlink($p);if($overlay&&isset($item)&&$item&&$item->getAttribute('status')==='completed')@unlink($overlay);GenerationLifecycle::unlock($id);}
    }
}
