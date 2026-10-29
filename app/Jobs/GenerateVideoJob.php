<?php
class GenerateVideoJob implements QueueJobInterface
{
    private const MAX_POLL_ATTEMPTS=40;
    private $clientFactory;
    private $fallbackFactory;
    public function __construct(?callable $clientFactory=null,?callable $fallbackFactory=null){$this->clientFactory=$clientFactory;$this->fallbackFactory=$fallbackFactory;}
    public function handle(array $payload): void
    {
        $id=(int)($payload['media_item_id']??0);if($id<1||!GenerationLifecycle::lock($id))return;
        try{
            $item=(new MediaItem())->find($id);if(!$item)throw new RuntimeException('Media item missing');
            if($item->getAttribute('status')!=='generating')return;
            if(isset($payload['poll_attempt'])&&(int)$payload['poll_attempt']!==(int)$item->getAttribute('poll_attempts'))return;
            if($item->getAttribute('file_path')&&$item->getAttribute('generation_stage')==='billing'){GenerationLifecycle::complete($item,'media_video');return;}
            $client=$this->clientFactory?($this->clientFactory)():GeminiOmniClient::forModel(json_decode((string)$item->getAttribute('generation_meta'),true)['routing_snapshot']['models']['veo']??null);
            $operation=(string)$item->getAttribute('provider_ref');
            if($operation===''){
                if($item->getAttribute('generation_stage')==='starting')throw new RuntimeException('Interrupted video start: outcome uncertain, automatic duplicate request blocked');
                GenerationLifecycle::stage($item,'starting');
                $prompt=(string)($item->getAttribute('final_prompt')?:$payload['final_prompt']??$item->getAttribute('prompt'));$ratio=(string)$item->getAttribute('aspect_ratio');$secs=(int)$item->getAttribute('duration_seconds');
                $r=$client->startGeneration($prompt,$ratio,$secs,$this->providerSettings($item));
                // The stored route still names a Veo model Google switched off: continue with Gemini Omni (video moved to the Interactions API).
                if(empty($r['success'])&&!($client instanceof GeminiOmniClient)&&VeoClient::isRetiredModelError((string)($r['error']??''))){
                    Logger::warning('Veo model retired, using Gemini Omni',['media_item_id'=>$id]);
                    $client=$this->fallbackFactory?($this->fallbackFactory)():new GeminiOmniClient(GeminiOmniClient::DEFAULT_MODEL);
                    $r=$client->startGeneration($prompt,$ratio,$secs,$this->providerSettings($item));
                }
                if(empty($r['success'])||empty($r['operation_name']))throw new RuntimeException((string)($r['error']??'Video start failed'));
                $item->setAttribute('provider_ref',$r['operation_name']);$item->setAttribute('provider','veo');
                if(empty($r['video_data'])&&empty($r['video_uri'])){GenerationLifecycle::stage($item,'generating');$this->requeue($item);return;}
                // Synchronous provider (Gemini Omni): the clip came back with the start answer, finish in this run.
                GenerationLifecycle::stage($item,'processing');
                $r=['success'=>true,'done'=>true,'video_uri'=>(string)($r['video_uri']??''),'video_data'=>$r['video_data']??null];
            }else{
                $attempt=(int)$item->getAttribute('poll_attempts');if($attempt>=self::MAX_POLL_ATTEMPTS && $item->getAttribute('generation_stage')!=='download_failed')throw new RuntimeException('Video polling deadline exceeded');
                $r=$client->checkOperation($operation);
                if(empty($r['done'])){$item->setAttribute('poll_attempts',$attempt+1);GenerationLifecycle::save($item);$this->requeue($item);return;}
                if(empty($r['success']))throw new RuntimeException((string)($r['error']??'Video generation failed'));
                GenerationLifecycle::stage($item,'processing');
            }
            $providerCompleted=true;
            $download=!empty($r['video_data'])?['success'=>true,'data'=>(string)$r['video_data']]:$client->downloadVideo((string)($r['video_uri']??''));if(empty($download['success']))throw new RuntimeException((string)($download['error']??'Video download failed'));
            $path=GeneratedMediaFiles::write($id,(string)($download['data']??''),'mp4');$item->setAttribute('file_path',$path);
            $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];$size=VideoEditing::nominalSize((string)$item->getAttribute('aspect_ratio'),(string)($meta['options']['resolution']??'720p'));
            $item->setAttribute('width',$size['width']);$item->setAttribute('height',$size['height']);
            try{$poster=VideoEditing::poster(PrivateMedia::resolve($path),$id);if($poster)$item->setAttribute('thumbnail_path',$poster);}catch(Throwable $e){Logger::warning('Video poster unavailable',['media_item_id'=>$id]);}
            GenerationLifecycle::stage($item,'billing');GenerationLifecycle::complete($item,'media_video');
            if(class_exists('BrandVideoFinisher'))BrandVideoFinisher::queue($item);
        }catch(Throwable $e){
            if(isset($item)&&$item->getAttribute('status')!=='completed'&&!empty($providerCompleted)) {
                $item->setAttribute('error_message','Video created; local download/storage requires retry');GenerationLifecycle::stage($item,$item->getAttribute('file_path')?'billing':'download_failed');
                $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];$tries=(int)($meta['download_retries']??0)+1;$meta['download_retries']=$tries;$item->setAttribute('generation_meta',json_encode($meta));GenerationLifecycle::save($item);
                if($tries<=3)$this->requeue($item);
            } elseif(isset($item)&&$item->getAttribute('status')!=='completed')GenerationLifecycle::fail($item,$e->getMessage());else throw $e;
        }finally{GenerationLifecycle::unlock($id);}
    }
    private function providerSettings(MediaItem $item): array
    {
        $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];$options=$meta['options']??[];$settings=['resolution'=>$options['resolution']??'720p','negative_prompt'=>GenerationOptions::videoNegativePrompt(['avoid'=>(string)($options['avoid']??'')])];if(isset($meta['routing_snapshot']['models']['veo']))$settings['model']=$meta['routing_snapshot']['models']['veo'];
        if(!empty($options['reference_id']))$settings['reference']=ReferenceMedia::load((int)$item->getAttribute('user_id'),(int)$options['reference_id']);return $settings;
    }
    private function requeue(MediaItem $item): void
    {
        $queue=Container::getInstance()->make(QueueManager::class);
        $id=$queue->push(self::class,['media_item_id'=>(int)$item->getAttribute('id'),'poll_attempt'=>(int)$item->getAttribute('poll_attempts')],'media',20);
        if(!$id)throw new RuntimeException('Video continuation queue unavailable');
        $item->setAttribute('job_id',(int)$id);GenerationLifecycle::save($item);
    }
}
