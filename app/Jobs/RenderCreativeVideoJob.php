<?php
/** Local editing only: never invokes a paid AI provider or creates a wallet debit. */
class RenderCreativeVideoJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $id=(int)($payload['media_item_id']??0);if($id<1||!GenerationLifecycle::lock($id))return;$output=null;$poster=null;$overlay=null;$endCard=null;
        try{$item=(new MediaItem())->find($id);if(!$item||$item->getAttribute('status')!=='generating')return;if($item->getAttribute('file_path')&&$item->getAttribute('generation_stage')==='billing'){GenerationLifecycle::complete($item,'media_video');return;}
            $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];if(($meta['source']??'')!=='video_editor')throw new RuntimeException('Invalid editing job');$source=PrivateMedia::owned((int)$item->getAttribute('user_id'),(int)($meta['parent_id']??0));if($source['status']!=='completed')throw new RuntimeException('Source unavailable');$file=PrivateMedia::resolve((string)$source['file_path']);$probe=VideoEditing::probe($file);$settings=VideoEditing::settings($meta['edit']??[],$probe['duration']);$overlay=PrivateMedia::resolve((string)($meta['overlay']??''));$tag=bin2hex(random_bytes(12));$output=PrivateMedia::directory().'/render_'.$tag.'.mp4';$poster=PrivateMedia::directory().'/poster_'.$tag.'.jpg';GenerationLifecycle::stage($item,'processing');
            $endCard=null;if(!empty($meta['endcard'])){try{$endCard=PrivateMedia::resolve((string)$meta['endcard']);if(!is_file($endCard))$endCard=null;}catch(Throwable $e){$endCard=null;}}
            $clip=(float)($settings['end']-$settings['start']);$withAudio=!$settings['mute']&&VideoEditing::hasAudio($file);
            $build=function(?string $card)use($settings,$probe,$file,$overlay,$output,$clip,$withAudio){
                $size=$probe['width'].':'.$probe['height'];
                $in=[VideoEditing::binary('ffmpeg'),'-nostdin','-v','error','-y','-ss',(string)$settings['start'],'-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-i',$file,'-loop','1','-i',$overlay];
                if($card!==null){array_push($in,'-loop','1','-t',(string)VideoEditing::END_CARD_SECONDS,'-i',$card,'-filter_complex_threads','1','-filter_complex',VideoEditing::endCardFilter((int)$probe['width'],(int)$probe['height'],$clip,$withAudio),'-map','[v]');if($withAudio)array_push($in,'-map','[a]','-c:a','aac','-b:a','128k');else $in[]='-an';$len=$clip+VideoEditing::END_CARD_SECONDS;}
                else{$filter='[0:v]scale='.$size.'[base];[1:v]scale='.$size.'[mark];[base][mark]overlay=0:0:format=auto,format=yuv420p[v]';array_push($in,'-filter_complex_threads','1','-filter_complex',$filter,'-map','[v]');if($settings['mute'])$in[]='-an';else array_push($in,'-map','0:a?','-c:a','aac','-b:a','128k');$len=$clip;}
                array_push($in,'-t',(string)$len,'-c:v','libx264','-preset','veryfast','-crf','20','-threads','2','-movflags','+faststart',$output);return $in;
            };
            if($endCard!==null){try{VideoEditing::process($build($endCard),80);}catch(Throwable $e){Logger::warning('Brand end card skipped',['media_item_id'=>$id,'reason'=>mb_substr($e->getMessage(),0,120)]);@unlink($output);VideoEditing::process($build(null),50);}}
            else VideoEditing::process($build(null),50);
            $bytes=file_get_contents($output);if($bytes===false)throw new RuntimeException('Rendered video missing');$path=GeneratedMediaFiles::write($id,$bytes,'mp4');$item->setAttribute('file_path',$path);$item->setAttribute('width',$probe['width']);$item->setAttribute('height',$probe['height']);
            try{VideoEditing::process([VideoEditing::binary('ffmpeg'),'-nostdin','-v','error','-y','-i',$output,'-frames:v','1','-vf','scale=480:-2','-threads','1',$poster],10);$thumb=file_get_contents($poster);if($thumb!==false)$item->setAttribute('thumbnail_path',GeneratedMediaFiles::write($id,$thumb,'jpg'));}catch(Throwable $e){Logger::warning('Video cover unavailable',['media_item_id'=>$id]);}
            GenerationLifecycle::stage($item,'billing');GenerationLifecycle::complete($item,'media_video');
        }catch(Throwable $e){if(isset($item)&&$item&&$item->getAttribute('status')!=='completed')GenerationLifecycle::fail($item,$e->getMessage());else throw $e;}finally{foreach([$output,$poster] as $p)if($p&&is_file($p))@unlink($p);if(isset($item)&&$item&&$item->getAttribute('status')==='completed'){if($overlay)@unlink($overlay);if(!empty($endCard))@unlink($endCard);}GenerationLifecycle::unlock($id);}
    }
}
