<?php
class GenerateMediaJob implements QueueJobInterface
{
    private $clientFactory;
    private $gate;
    public function __construct(?callable $clientFactory=null,?CreativeQualityGate $gate=null){$this->clientFactory=$clientFactory;$this->gate=$gate;}
    private function qualityGate(): CreativeQualityGate
    {
        // الاختبارات اللي بتحقن عميل وهمي مابتنادي موديل رؤية حقيقي
        return $this->gate??($this->clientFactory?new CreativeQualityGate(static fn()=>['success'=>false]):new CreativeQualityGate());
    }
    public function handle(array $payload): void
    {
        $id=(int)($payload['media_item_id']??0);
        if($id<1||!GenerationLifecycle::lock($id))return;
        try{
            $item=(new MediaItem())->find($id);if(!$item)throw new RuntimeException('Media item missing');
            if($item->getAttribute('status')!=='generating')return;
            if($item->getAttribute('file_path')&&$item->getAttribute('generation_stage')==='billing'){GenerationLifecycle::complete($item,'media_image');return;}
            if(in_array($item->getAttribute('generation_stage'),['starting','processing','generating'],true)){GenerationLifecycle::fail($item,'Interrupted image request: outcome uncertain; do not automatically pay for another provider request');return;}
            if(!function_exists('imagecreatefromstring'))throw new RuntimeException('Enable PHP GD before image generation: required for exact output ratios');
            GenerationLifecycle::stage($item,'starting');
            $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];
            $userId=(int)$item->getAttribute('user_id');
            $prompt=(string)($item->getAttribute('final_prompt')?:$payload['final_prompt']??$item->getAttribute('prompt'));
            $ratio=(string)($item->getAttribute('aspect_ratio')?:'1:1');
            $result=$this->generateWithFallback($prompt,$ratio,$userId,$meta['options']??[],$meta['routing_snapshot']??null);
            if(empty($result['success']))throw new RuntimeException((string)($result['error']??'Image provider failed'));
            GenerationLifecycle::stage($item,'processing');
            $image=GeneratedMediaFiles::image((string)($result['image_base64']??''));
            // فحص الجودة: عيب كبير = محاولة تصحيح واحدة على حساب المنصة قبل أي خصم على العميل
            $gate=$this->qualityGate();$review=$gate->review($image['data'],(string)$item->getAttribute('prompt'),$userId);$attempts=1;
            if($review['checked']&&!$review['passed']&&empty($meta['options']['reference_id'])){
                $retry=$this->generateWithFallback($prompt.' '.CreativeQualityGate::correction($review),$ratio,$userId,$meta['options']??[],$meta['routing_snapshot']??null);$attempts=2;
                if(!empty($retry['success'])){
                    try{$second=GeneratedMediaFiles::image((string)($retry['image_base64']??''));$secondReview=$gate->review($second['data'],(string)$item->getAttribute('prompt'),$userId);
                        if(!$secondReview['checked']||(int)$secondReview['score']>=(int)$review['score']){$image=$second;$review=$secondReview;$result=$retry+['provider'=>$retry['provider']??($result['provider']??null)];}
                    }catch(Throwable $e){Logger::warning('Creative QA retry unusable',['media_item_id'=>$id]);}
                }
            }
            if($review['checked']&&$review['reject'])throw new RuntimeException('CREATIVE_QA_REJECTED score '.$review['score'].': '.implode('; ',$review['issues']));
            $nativeWidth=$image['width'];$nativeHeight=$image['height'];
            $image=GeneratedMediaFiles::deliver($image,$ratio);
            $phash=CreativeAssetRegistry::fingerprint($image['data']);
            $similar=$phash?CreativeAssetRegistry::nearest(Database::getInstance()->getConnection(),$userId,$phash,$id,CreativeAssetRegistry::SIMILAR_BITS,600,$image['width']/$image['height']):null;
            $meta['output']=['requested_ratio'=>$ratio,'native_width'=>$nativeWidth,'native_height'=>$nativeHeight,'width'=>$image['width'],'height'=>$image['height'],'center_cropped'=>$nativeWidth*$image['height']!==$nativeHeight*$image['width'],'upscaled'=>$image['upscaled'],'format'=>$image['extension'],'model'=>$result['model']??null];
            $meta['qa']=['checked'=>$review['checked'],'score'=>$review['score'],'passed'=>$review['passed'],'issues'=>$review['issues'],'attempts'=>$attempts];
            if($phash)$meta['phash']=$phash;
            if($similar)$meta['similar_to']=$similar;else unset($meta['similar_to']);
            $item->setAttribute('generation_meta',json_encode($meta,JSON_THROW_ON_ERROR));
            $path=GeneratedMediaFiles::write($id,$image['data'],$image['extension']);
            $thumbnail=null;try{$thumbnail=GeneratedMediaFiles::thumbnail($path,$id);}catch(Throwable $e){Logger::warning('Creative thumbnail unavailable',['media_item_id'=>$id]);}
            $item->setAttribute('file_path',$path);$item->setAttribute('thumbnail_path',$thumbnail?:$path);$item->setAttribute('width',$image['width']);$item->setAttribute('height',$image['height']);$item->setAttribute('provider',$result['provider']??null);
            GenerationLifecycle::stage($item,'billing');GenerationLifecycle::complete($item,'media_image');
        }catch(Throwable $e){if(isset($item)&&$item->getAttribute('status')!=='completed')GenerationLifecycle::fail($item,$e->getMessage());else throw $e;}finally{GenerationLifecycle::unlock($id);}
    }

    private function generateWithFallback(string $prompt, string $aspectRatio, int $userId,array $options=[],?array $routing=null): array
    {
        // الاستوديو = تسليم نهائي للعميل: أعلى جودة متاحة من OpenAI (قابلة للتغيير بـ OPENAI_STUDIO_IMAGE_QUALITY)
        $quality=function_exists('env')?env('OPENAI_STUDIO_IMAGE_QUALITY'):null;
        $meta = ['task' => 'creative_studio_image', 'user_id' => $userId, 'quality' => is_string($quality)&&$quality!==''?$quality:'high'];
        if(!empty($options['reference_id']))$meta['reference']=ReferenceMedia::load($userId,(int)$options['reference_id']);
        if ($this->clientFactory) {
            return ($this->clientFactory)()->generateImage($prompt, $aspectRatio,$meta);
        }

        if($routing!==null&&class_exists('AiServiceRouting'))return AiServiceRouting::image($prompt,$aspectRatio,$meta,$routing);
        $spec = function_exists('env') ? env('MEDIA_IMAGE_PROVIDERS') : null;
        $spec = is_string($spec) && $spec !== '' ? $spec : 'openai>gemini';
        $chain = [];
        foreach (explode('>', strtolower($spec)) as $p) {
            $p = trim($p);
            if (in_array($p, ['openai', 'gemini'], true) && !in_array($p, $chain, true)) {
                $chain[] = $p;
            }
        }
        if (!$chain) {
            $chain = ['openai', 'gemini'];
        }

        if(isset($meta['reference']))$chain=['gemini'];
        $last = ['success' => false, 'error' => 'No image provider configured', 'http_code' => 0, 'api_status' => 'NOT_CONFIGURED'];
        foreach ($chain as $provider) {
            if ($provider === 'openai') {
                if (!class_exists('OpenAIImageClient')) {
                    continue;
                }
                $client = new OpenAIImageClient();
                if (!$client->isConfigured()) {
                    continue;
                }
            } else {
                if (!(defined('GEMINI_API_KEY') && (string)GEMINI_API_KEY!=='') && !(class_exists('SystemSettingsService') && (string)(new SystemSettingsService())->get('gemini_api_key','')!=='')) {
                    continue;
                }
                $client = new GeminiClient();
            }
            $r = $client->generateImage($prompt, $aspectRatio, $meta);
            if (!empty($r['success'])) {
                $r['provider'] = $provider;
                return $r;
            }
            $last = $r;
            if((int)($r['http_code']??0)===0||(int)($r['http_code']??0)>=500)return $r;
            Logger::warning('Creative Studio image provider failed', [
                'provider' => $provider, 'http' => $r['http_code'] ?? 0,
                'status' => $r['api_status'] ?? '', 'error' => substr((string) ($r['error'] ?? ''), 0, 200),
            ]);
        }
        return $last;
    }
}
