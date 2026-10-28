<?php

/** Validated asynchronous image and video requests with durable request identity. */
class MediaGenerationService
{
    private const SUPPORTED_TYPES = [
        'social_image', 'marketing_image', 'instagram_post', 'facebook_cover',
        'youtube_thumbnail', 'story', 'reels_cover', 'short_video',
    ];

    /** نوع الوسائط -> نسبة الأبعاد الاحترافية الصحيحة لكل منصة (صور فقط) */
    private const IMAGE_ASPECT_RATIOS = [
        'social_image' => '1:1',
        'marketing_image' => '4:3',
        'instagram_post' => '1:1',
        'facebook_cover' => '16:9',
        'youtube_thumbnail' => '16:9',
        'story' => '9:16',
        'reels_cover' => '9:16',
    ];

    /** إضافات أسلوب احترافية بتتحط على وصف المستخدم قبل التوليد */
    private const STYLE_PRESETS = [
        'photo' => 'authentic editorial travel photography, candid moment, lifelike skin texture and subtle imperfections, believable hands and anatomy, physically consistent natural light and shadows, realistic fabric and water, restrained color grading, no CGI, no beauty filters or plastic skin',
        'cinematic' => 'cinematic lighting, dramatic composition, shallow depth of field, high-end commercial film look',
        'product' => 'clean studio background, professional product photography, soft shadows, advertising catalog style',
        'illustration' => 'modern flat illustration style, clean vector shapes, vibrant harmonious colors',
        'luxury' => 'premium editorial travel campaign, elegant composition, restrained color palette, refined cinematic light, realistic detail',
        'minimal' => 'minimalist design, plenty of negative space, elegant and simple composition',
    ];

    /** منصة الفيديو -> نسبة الأبعاد (فيديو بس بيدعم 16:9 أو 9:16) */
    private const VIDEO_ASPECT_RATIOS = [
        'tiktok' => '9:16',
        'instagram_reels' => '9:16',
        'youtube_shorts' => '9:16',
        'general_landscape' => '16:9',
    ];

    /** أقصى عدد طلبات جارية للعميل (مشاهد السكربت بتتحسب كطلب واحد) */
    public const MAX_ACTIVE=2;

    public function isSupportedType(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    public static function stylePresets(): array
    {
        return self::STYLE_PRESETS;
    }

    /**
     * توليد صورة تسويقية احترافية.
     * @param string $style مفتاح من STYLE_PRESETS (اختياري)
     */
    public function requestGeneration(int $userId, string $type, string $prompt, string $style = 'photo', ?BrandProfile $brand = null, string $headline = '', array $options = [], string $requestKey = '', array $billing = [], int $variant = 0): MediaItem
    {
        if (!in_array($type, self::SUPPORTED_TYPES, true) || $type === 'short_video') {
            throw new InvalidArgumentException("نوع وسائط غير مدعوم: {$type}");
        }

        $prompt=GenerationOptions::text($prompt,'prompt',2000,true);
        if(!isset(self::STYLE_PRESETS[$style]))throw new InvalidArgumentException('Invalid style');
        $options=GenerationOptions::normalize($options);
        if($options['reference_id']){ReferenceMedia::load($userId,$options['reference_id']);$key=defined('GEMINI_API_KEY')?GEMINI_API_KEY:'';if(class_exists('SystemSettingsService'))$key=(new SystemSettingsService())->get('gemini_api_key',$key);if(class_exists('AiServiceRouting'))$key=AiServiceRouting::apiKey('gemini');if(!is_string($key)||$key==='')throw new InvalidArgumentException('Reference editing requires Gemini');}
        $headline=GenerationOptions::text($headline,'headline',200);
        $aspectRatio = $options['aspect_ratio'] ?: self::IMAGE_ASPECT_RATIOS[$type];
        $finalPrompt = $brand
            ? BrandPromptBuilder::build($prompt, $style, $brand, $aspectRatio)
            : $this->buildImagePrompt($prompt, $style);
        $finalPrompt.=' '.GenerationOptions::direction($options).' Compose for a '.$aspectRatio.' output. Keep the essential subject inside the central safe area; edges may be cropped to the selected ratio. Preserve requested faces and hands in the frame.';
        $variantLine=GenerationOptions::variantDirection($variant);if($variantLine!=='')$finalPrompt.=' '.$variantLine;

        return $this->enqueue($userId, [
            'type'=>$type,'prompt'=>$prompt,'aspect_ratio'=>$aspectRatio,
            'brand_profile_id'=>$brand?(int)$brand->getAttribute('id'):null,
            'final_prompt'=>$finalPrompt,'headline'=>$headline?:null,
            'generation_meta'=>json_encode(array_filter(['style'=>$style,'options'=>$options,'billing'=>$billing?:null,'variant'=>$variant?:null]),JSON_THROW_ON_ERROR),
        ],GenerateMediaJob::class,$requestKey);
    }

    /**
     * توليد فيديو قصير احترافي (Veo) - نفس فكرة requestGeneration لكن
     * بمهلة أطول (عملية غير متزامنة) عبر GenerateVideoJob.
     * @param string $platform tiktok | instagram_reels | youtube_shorts | general_landscape
     * @param int    $durationSeconds 4 | 6 | 8
     * @param string $style مفتاح من STYLE_PRESETS
     */
    public function requestVideoGeneration(int $userId, string $prompt, string $platform = 'instagram_reels', int $durationSeconds = 8, string $style = 'cinematic', ?BrandProfile $brand = null, array $options = [], string $requestKey = '', array $billing = [], array $storyboard = []): MediaItem
    {
        $prompt=GenerationOptions::text($prompt,'prompt',2000,true);
        if(!isset(self::VIDEO_ASPECT_RATIOS[$platform])||!isset(self::STYLE_PRESETS[$style])||!in_array($durationSeconds,[4,6,8],true))throw new InvalidArgumentException('Invalid video settings');
        $options=GenerationOptions::normalize($options,true);
        if($options['reference_id'])ReferenceMedia::load($userId,$options['reference_id']);
        if(($options['reference_id']||$options['resolution']!=='720p')&&$durationSeconds!==8)throw new InvalidArgumentException('Eight seconds required');
        if(!VeoClient::supportsResolution($options['resolution'])||($options['resolution']!=='720p'&&!$billing))throw new InvalidArgumentException('Approved premium quote required');
        $aspectRatio=$options['aspect_ratio']?:self::VIDEO_ASPECT_RATIOS[$platform];
        // مشهد السكربت: العميل يشوف وصف المشهد بلغته، وتعليمات الموديل الداخلية تفضل في final_prompt بس
        $display=isset($storyboard['label'])&&is_string($storyboard['label'])&&trim($storyboard['label'])!==''?trim($storyboard['label']):$prompt;unset($storyboard['label']);
        $finalPrompt=$this->buildVideoPrompt($prompt,$style).' '.GenerationOptions::direction($options,true);
        if($brand){$colors=BrandPromptBuilder::paletteNames($brand);if($colors)$finalPrompt.=' Brand mood: subtle '.implode(', ',$colors).' color accents in wardrobe, props or light, keeping natural realism.';}
        return $this->enqueue($userId,[
            'type'=>'short_video','prompt'=>$display,'aspect_ratio'=>$aspectRatio,'duration_seconds'=>$durationSeconds,
            'brand_profile_id'=>$brand?(int)$brand->getAttribute('id'):null,'final_prompt'=>$finalPrompt,
            'generation_meta'=>json_encode(array_filter(['style'=>$style,'platform'=>$platform,'options'=>$options,'billing'=>$billing?:null,'storyboard'=>$storyboard?:null]),JSON_THROW_ON_ERROR),
        ],GenerateVideoJob::class,$requestKey,$storyboard?self::MAX_ACTIVE+CreativePricing::STORYBOARD_MAX_SCENES:self::MAX_ACTIVE);
    }

    public function requestBatch(int $userId,string $kind,array $data,?BrandProfile $brand,string $requestKey,string $quoteHash): array
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{16,70}$/D',$requestKey)||!preg_match('/^[a-f0-9]{64}$/D',$quoteHash))throw new InvalidArgumentException('Invalid confirmed request');
        $count=$data['count']??1;if(!in_array($count,[1,2],true)||($kind==='video'&&$count!==1))throw new InvalidArgumentException('Invalid count');
        return Database::getInstance()->transaction(function()use($userId,$kind,$data,$brand,$requestKey,$quoteHash,$count){
            $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn())throw new RuntimeException('User unavailable');
            $s=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key IN (?,?) ORDER BY id');$s->execute([$userId,$requestKey.'_1',$requestKey.'_2']);$existing=$s->fetchAll(PDO::FETCH_ASSOC);if($existing)return array_map(fn($row)=>new MediaItem($row),$existing);
            $options=GenerationOptions::normalize($data['options']??[],$kind==='video');$duration=$data['duration_seconds']??8;
            $quote=CreativePricing::quote($userId,$kind,$options,$count,$duration);if(!$quote['ok']||!hash_equals($quote['hash'],$quoteHash))throw new InvalidArgumentException('Quote changed or insufficient funds');
            $items=[];for($i=0;$i<$count;$i++){
                $prompt=GenerationOptions::text($data['prompt']??'','prompt',2000,true);$style=GenerationOptions::text($data['style']??($kind==='video'?'cinematic':'photo'),'style',30);
                if($kind==='video')$items[]=$this->requestVideoGeneration($userId,$prompt,GenerationOptions::text($data['platform']??'instagram_reels','platform',30),$duration,$style,$brand,$options,$requestKey.'_'.($i+1),$quote['units'][$i]);
                else $items[]=$this->requestGeneration($userId,GenerationOptions::text($data['type']??'social_image','type',40),$prompt,$style,$brand,'',$options,$requestKey.'_'.($i+1),$quote['units'][$i],$count>1?$i+1:0);
            }return $items;
        });
    }
    /**
     * سكربت → فيديو: لقطة Veo لكل مشهد بنفس الأسلوب والهوية والاتجاه، كلها بسعر واحد متوافق عليه.
     * بعد اكتمال اللقطات بيتجمعوا في فيديو واحد بـ RenderStoryboardJob (من غير تكلفة توليد).
     * @return MediaItem[]
     */
    public function requestStoryboard(int $userId,int $scriptId,array $data,?BrandProfile $brand,string $requestKey,string $quoteHash): array
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{16,70}$/D',$requestKey)||!preg_match('/^[a-f0-9]{64}$/D',$quoteHash))throw new InvalidArgumentException('Invalid confirmed request');
        $scenes=self::storyboardScenes($userId,$scriptId,$data['scenes']??null);
        $platform=GenerationOptions::text($data['platform']??'instagram_reels','platform',30);if(!isset(self::VIDEO_ASPECT_RATIOS[$platform]))throw new InvalidArgumentException('Invalid platform');
        $style=GenerationOptions::text($data['style']??'cinematic','style',30);if(!isset(self::STYLE_PRESETS[$style]))throw new InvalidArgumentException('Invalid style');
        $options=GenerationOptions::normalize($data['options']??[],true);
        if($options['reference_id'])throw new InvalidArgumentException('Storyboards use text-to-video scenes');
        $duration=$options['resolution']!=='720p'?8:(int)($data['duration_seconds']??8);if(!in_array($duration,[4,6,8],true))throw new InvalidArgumentException('Invalid duration');
        return Database::getInstance()->transaction(function()use($userId,$scriptId,$scenes,$platform,$style,$options,$duration,$brand,$requestKey,$quoteHash){
            $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn())throw new RuntimeException('User unavailable');
            $keys=[];foreach(array_keys($scenes) as $i)$keys[]=$requestKey.'_s'.($i+1);
            $s=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key IN ('.implode(',',array_fill(0,count($keys),'?')).') ORDER BY id');$s->execute(array_merge([$userId],$keys));$existing=$s->fetchAll(PDO::FETCH_ASSOC);if($existing)return array_map(fn($row)=>new MediaItem($row),$existing);
            $quote=CreativePricing::quote($userId,'video',$options,count($scenes),$duration,true);if(!$quote['ok']||!hash_equals($quote['hash'],$quoteHash))throw new InvalidArgumentException('Quote changed or insufficient funds');
            $group=substr(hash('sha256',$userId.'|'.$requestKey),0,24);$items=[];$total=count($scenes);
            foreach(array_values($scenes) as $i=>$scene){
                $prompt=$scene['prompt'].' This is shot '.($i+1).' of '.$total.' in one continuous tourism ad: keep the same visual style, color grade, time of day and location identity as the other shots.';
                $items[]=$this->requestVideoGeneration($userId,mb_substr($prompt,0,2000),$platform,$duration,$style,$brand,$options,$keys[$i],$quote['units'][$i],['group'=>$group,'script_id'=>$scriptId,'scene'=>$i+1,'total'=>$total,'label'=>$scene['label']]);
            }
            return $items;
        });
    }
    /** مشاهد السكربت المختارة كـ prompts إنجليزي جاهزة للتوليد */
    public static function storyboardScenes(int $userId,int $scriptId,$selected=null): array
    {
        $s=Database::getInstance()->getConnection()->prepare("SELECT scenes FROM video_scripts WHERE id=? AND user_id=? AND status='completed'");$s->execute([$scriptId,$userId]);$raw=$s->fetchColumn();
        if($raw===false)throw new InvalidArgumentException('Script unavailable');
        $scenes=VideoScriptService::cleanScenes(json_decode((string)$raw,true));if(!$scenes)throw new InvalidArgumentException('Script has no scenes');
        if($selected!==null){
            if(!is_array($selected)||!$selected||count($selected)>CreativePricing::STORYBOARD_MAX_SCENES)throw new InvalidArgumentException('Choose scenes');
            $pick=[];foreach($selected as $n){if(!is_scalar($n)||!ctype_digit((string)$n)||!isset($scenes[(int)$n-1]))throw new InvalidArgumentException('Invalid scene');$pick[(int)$n-1]=$scenes[(int)$n-1];}
            ksort($pick);$scenes=$pick;
        }else $scenes=array_slice($scenes,0,CreativePricing::STORYBOARD_MAX_SCENES,true);
        $out=[];foreach($scenes as $i=>$scene){$p=$scene['visual_prompt_en']!==''?$scene['visual_prompt_en']:$scene['visual'];if(mb_strlen($p)<8)throw new InvalidArgumentException('Scene description too short');$out[$i]=['prompt'=>$p,'label'=>mb_substr($scene['visual']!==''?$scene['visual']:$p,0,500),'voiceover'=>$scene['voiceover'],'on_screen_text'=>$scene['on_screen_text']];}
        return $out;
    }
    private function enqueue(int $userId,array $attributes,string $job,string $requestKey,int $maxActive=self::MAX_ACTIVE): MediaItem
    {
        if($userId<1||($requestKey!==''&&!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$requestKey)))throw new InvalidArgumentException('Invalid request identity');
        $db=Database::getInstance();
        return $db->transaction(function()use($db,$userId,$attributes,$job,$requestKey,$maxActive){
            $pdo=$db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $stmt=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$stmt->execute([$userId]);if(!$stmt->fetchColumn())throw new RuntimeException('User unavailable');
            if($requestKey!==''){$stmt=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key=?');$stmt->execute([$userId,$requestKey]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if($row)return new MediaItem($row);}
            // فيديو اتولّد ومستني إعادة التنزيل مش شغل جاري فعلًا: مايقفلش التوليد على العميل
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM media_items WHERE user_id=? AND status='generating' AND (generation_stage IS NULL OR generation_stage<>'download_failed')");$stmt->execute([$userId]);if((int)$stmt->fetchColumn()>=$maxActive)throw new InvalidArgumentException('انتظر اكتمال أحد الطلبين الجاريين قبل إضافة طلب جديد.');
            $queue=Container::getInstance()->make(QueueManager::class);if(!$queue->isReady())throw new RuntimeException('Generation queue unavailable');
            $key=defined('GEMINI_API_KEY')?GEMINI_API_KEY:'';if(class_exists('SystemSettingsService'))$key=(new SystemSettingsService())->get('gemini_api_key',$key);
            if(class_exists('AiServiceRouting'))$key=AiServiceRouting::apiKey('gemini');
            $configured=$job===GenerateVideoJob::class?is_string($key)&&$key!=='':((is_string($key)&&$key!=='')||(new OpenAIImageClient())->isConfigured());
            if(!$configured)throw new RuntimeException('Generation provider not configured');
            if(class_exists('PlanUsage')&&empty(json_decode($attributes['generation_meta'],true)['billing'])){$feature=$job===GenerateVideoJob::class?'media_video':'media_image';$check=PlanUsage::check($userId,$feature,$feature,'الرصيد أو الحصة غير كافيين للتوليد.');if(!$check['ok'])throw new InvalidArgumentException('الرصيد أو الحصة غير كافيين للتوليد.');}
            $meta=json_decode($attributes['generation_meta'],true)?:[];
            if(empty($meta['billing'])){$kind=$job===GenerateVideoJob::class?'video':'image';$quote=CreativePricing::quote($userId,$kind,$meta['options']??[],1,(int)($attributes['duration_seconds']??8));if(!$quote['ok'])throw new InvalidArgumentException('Reservation unavailable');
                // أي خصم من المحفظة لازم يكون بموافقة صريحة على السعر (quote_hash)؛ الطلب من غير موافقة يتغطى من الباقة بس
                if((float)($quote['units'][0]['amount']??0)>0)throw new InvalidArgumentException('Wallet charge requires an approved quote');
                $meta['billing']=$quote['units'][0];$attributes['generation_meta']=json_encode($meta,JSON_THROW_ON_ERROR);}
            if(class_exists('AiServiceRouting')){$task=$job===GenerateVideoJob::class?'creative_studio_video':(!empty($meta['options']['reference_id'])?'creative_studio_edit':'creative_studio_image');$meta['routing_snapshot']=AiServiceRouting::mediaSnapshot($task);}
            $meta['reservation_required']=true;$attributes['generation_meta']=json_encode($meta,JSON_THROW_ON_ERROR);
            $item=new MediaItem(array_merge($attributes,['user_id'=>$userId,'status'=>'generating','generation_stage'=>'queued','request_key'=>$requestKey?:null,'usage_charged'=>0]));GenerationLifecycle::save($item);CreativeReservations::reserve($item,$meta['billing']);
            $jobId=$queue->push($job,['media_item_id'=>(int)$item->getAttribute('id'),'final_prompt'=>$attributes['final_prompt']],'media');
            if(!$jobId)throw new RuntimeException('Could not enqueue generation');
            $item->setAttribute('job_id',(int)$jobId);GenerationLifecycle::save($item);return $item;
        });
    }

    private function buildImagePrompt(string $prompt, string $style): string
    {
        $styleDescriptor = self::STYLE_PRESETS[$style] ?? self::STYLE_PRESETS['photo'];
        return trim($prompt) . '. Style: ' . $styleDescriptor . '.';
    }

    private function buildVideoPrompt(string $prompt, string $style): string
    {
        $styleDescriptor = self::STYLE_PRESETS[$style] ?? self::STYLE_PRESETS['cinematic'];
        return trim($prompt) . '. Visual style: ' . $styleDescriptor . '. Professional social-media advertising video with stable, purposeful camera work, cinematic color and realistic motion.';
    }
}
