<?php
/** Validated creative direction; no unsupported provider quality promises. */
class GenerationOptions
{
    public const IMAGE_RATIOS=['1:1','4:5','4:3','3:4','16:9','9:16'];
    public const STAGES=['queued','starting','generating','processing','download_failed','billing','completed','failed'];
    public static function text($value,string $name,int $max,bool $required=false): string
    {
        if(!is_string($value))throw new InvalidArgumentException('Invalid '.$name);
        $value=trim($value);
        if(mb_strlen($value)>$max||($required&&mb_strlen($value)<8))throw new InvalidArgumentException('Invalid '.$name);
        return $value;
    }
    public static function normalize(array $input,bool $video=false): array
    {
        $out=[];
        foreach(['subject'=>300,'location'=>200,'audience'=>200,'avoid'=>400] as $key=>$max)$out[$key]=self::text($input[$key]??'',$key,$max);
        // 'auto' (الافتراضي) = منضيفش توجيه: وصف العميل هو اللي يحدد الإضاءة/الحركة بدل ما نفرض قيمة متعارضة معاه
        $lists=['composition'=>['auto','balanced','negative_space','closeup','wide'],'lighting'=>['auto','natural','golden_hour','studio','night'],'camera'=>['auto','steady','dolly','orbit','aerial'],'audio'=>['auto','ambient','music','silent']];
        foreach($lists as $key=>$values){$v=$input[$key]??$values[0];if(!is_string($v)||!in_array($v,$values,true))throw new InvalidArgumentException('Invalid '.$key);$out[$key]=$v;}
        $ratio=$input['aspect_ratio']??'';
        if(!is_string($ratio)||($ratio!==''&&!in_array($ratio,$video?['16:9','9:16']:self::IMAGE_RATIOS,true)))throw new InvalidArgumentException('Invalid aspect ratio');
        $out['aspect_ratio']=$ratio;
        $ref=$input['reference_id']??0;if(!is_scalar($ref)||!ctype_digit((string)$ref)||strlen((string)$ref)>10)throw new InvalidArgumentException('Invalid reference');$out['reference_id']=(int)$ref;
        $resolution=$input['resolution']??'720p';if(!is_string($resolution)||!in_array($resolution,['720p','1080p','4k'],true)||(!$video&&$resolution!=='720p'))throw new InvalidArgumentException('Invalid resolution');$out['resolution']=$resolution;
        $scene=$input['scene']??'custom';if(!is_string($scene)||!in_array($scene,['custom','arrival','room','experience','destination'],true))throw new InvalidArgumentException('Invalid scene');$out['scene']=$scene;
        return $out;
    }
    public static function direction(array $o,bool $video=false): string
    {
        $parts=[];
        foreach(['subject'=>'Main subject','location'=>'Setting','audience'=>'Intended audience'] as $k=>$label)if($o[$k]!=='')$parts[]=$label.': '.$o[$k].'.';
        $composition=['balanced'=>'Balanced composition with one clear focal point','negative_space'=>'Keep clean negative space for text to be added later','closeup'=>'Detailed close-up of the main subject','wide'=>'Wide establishing composition with environmental context'];
        $light=['natural'=>'Soft natural light','golden_hour'=>'Warm golden-hour light','studio'=>'Controlled soft studio light','night'=>'Atmospheric night lighting with readable subject detail'];
        if(isset($composition[$o['composition']]))$parts[]=$composition[$o['composition']].'.';
        if(isset($light[$o['lighting']]))$parts[]=$light[$o['lighting']].'.';
        if($video&&($o['scene']??'custom')!=='custom'){$scenes=['arrival'=>'A single welcoming arrival shot with a slow reveal of the entrance','room'=>'A single smooth interior reveal highlighting space and natural materials','experience'=>'A single coherent activity shot with restrained subject motion','destination'=>'A single establishing destination reveal with gentle forward camera movement'];$parts[]=$scenes[$o['scene']].'.';}
        if($video){$camera=['steady'=>'Steady locked-off camera','dolly'=>'Slow smooth dolly movement','orbit'=>'Gentle orbit around the subject','aerial'=>'Smooth aerial establishing movement'];$audio=['ambient'=>'Natural ambient sound without dialogue','music'=>'Subtle instrumental background music without dialogue','silent'=>'No music, speech or deliberate sound effects'];if(isset($camera[$o['camera']]))$parts[]=$camera[$o['camera']].'.';if(isset($audio[$o['audio']]))$parts[]=$audio[$o['audio']].'.';$parts[]='Keep one coherent scene, stable anatomy and temporal continuity.';}
        // الفيديو بياخد العناصر المرفوضة في negativePrompt منفصل (كتابتها جوه الوصف بتخلي الموديل يرسمها أحيانًا)
        if(!$video&&$o['avoid']!=='')$parts[]='Avoid these elements: '.$o['avoid'].'.';
        $parts[]='Do not invent prices, travel guarantees, awards or factual claims. Do not render logos, watermarks or embedded text. Final branding and text can be added separately.';
        return implode(' ',$parts);
    }
    /** Veo negativePrompt: العناصر المرفوضة + عيوب الفيديو الشائعة */
    public static function videoNegativePrompt(array $o): string
    {
        $base='on-screen text, captions, subtitles, watermark, logo, distorted faces, extra limbs, warped hands, flickering, jittery camera, morphing objects, low quality';
        return $o['avoid']!==''?mb_substr($o['avoid'],0,400).', '.$base:$base;
    }
    /** كل نسخة في نفس الطلب بتاخد زاوية/تكوين مختلف عشان النسختين مايطلعوش شبه بعض */
    public const VARIANT_DIRECTIONS=[
        1=>'',
        2=>'Alternative take for this variant: clearly different from the most obvious version of this brief. Use a different camera position and focal length (for example closer and lower if a wide view is natural, or wider with more environment if a close-up is natural), rearrange the subject placement and choose a different moment of action, while keeping the same place, brief, lighting intent and brand.',
    ];
    public static function variantDirection(int $variant): string
    {
        return self::VARIANT_DIRECTIONS[$variant]??'';
    }
    /** رسائل الفشل اللي ممكن تظهر للعميل (مولّدة من GenerationLifecycle::publicFailure فقط) */
    public static function publicError(?string $stored): string
    {
        $stored=(string)$stored;
        return in_array($stored,GenerationLifecycle::PUBLIC_MESSAGES,true)?$stored:GenerationLifecycle::PUBLIC_MESSAGES['generic'];
    }
    public static function publicItem(array $row): array
    {
        $keys=['id','type','prompt','aspect_ratio','duration_seconds','file_path','thumbnail_path','width','height','status','rating','created_at','brand_profile_id','generation_stage','folder_id','is_favorite','deleted_at'];
        $out=array_intersect_key($row,array_flip($keys));
        $out['id']=(int)($out['id']??0);$out['rating']=(int)($out['rating']??0);
        $meta=json_decode((string)($row['generation_meta']??'{}'),true);$out['settings']=is_array($meta)?array_intersect_key($meta,array_flip(['style','platform','options','output','qa','similar_to','storyboard','source'])):[];
        $billing=is_array($meta)?($meta['billing']??null):null;
        $out['usage']=is_array($billing)?array_intersect_key($billing,array_flip(['source','amount','currency','key','settled'])):null;
        foreach(['file_path','thumbnail_path'] as $key){$p=$out[$key]??null;$out[$key]=is_string($p)&&(PrivateMedia::valid($p)||PrivateMedia::socialFile($p))?PrivateMedia::preview($out['id'],$key==='thumbnail_path'):null;}
        if(($out['status']??'')!=='completed'||!empty($out['deleted_at'])){$out['file_path']=null;$out['thumbnail_path']=null;}
        $out['is_favorite']=(bool)($out['is_favorite']??false);
        $out['error_message']=($out['status']??'')==='failed'?self::publicError($row['error_message']??null):null;
        // نوع السبب عشان الواجهة الإنجليزي تعرض نفس الرسالة بلغتها
        $out['error_code']=$out['error_message']!==null?(array_search($out['error_message'],GenerationLifecycle::PUBLIC_MESSAGES,true)?:'generic'):null;
        if(!in_array($out['generation_stage']??'',self::STAGES,true))$out['generation_stage']=($out['status']??'')==='generating'?'generating':($out['status']??'failed');
        return $out;
    }
}
