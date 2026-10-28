<?php
/** Versioned, server-only service policies. No provider secrets in policy storage or DTOs. */
class AiServiceRouting
{
    public const PROVIDERS=['gemini'=>'Google Gemini','anthropic'=>'Anthropic Claude','openai'=>'OpenAI','deepseek'=>'DeepSeek','kimi'=>'Kimi (Moonshot)','veo'=>'Google Veo'];
    public static function catalog(): array
    {
        $groups=[
          'content'=>['default'=>'الإعداد العام للنصوص','article_generation'=>'المقالات ومحتوى SEO','seo_keywords'=>'تحليل الكلمات المفتاحية','website_builder'=>'إنشاء صفحات المواقع','strategic_seo_plan'=>'خطة النمو وSEO','content_generation'=>'رسائل التواصل التجاري','article_image_prompt'=>'وصف صور المقالات'],
          'marketing'=>['social_content'=>'منشورات التواصل الاجتماعي','email_marketing'=>'محتوى التسويق بالبريد','marketing_assistant'=>'المساعد التسويقي','creative_plan'=>'الخطة الإبداعية','creative_prompt'=>'تحسين وصف الصور والفيديو','brand_learning'=>'تعلم تفضيلات الهوية','brand_dna'=>'إنشاء الهوية البصرية','video_script'=>'سكريبت الفيديو','ad_copy'=>'نصوص الإعلانات','ad_keywords'=>'استراتيجية كلمات الإعلانات','ad_market'=>'أبحاث سوق الإعلانات','ad_competitor'=>'تحليل منافسي الإعلانات','ads_copilot'=>'مساعد إدارة الإعلانات','landing_analysis'=>'تحليل صفحات الهبوط'],
          'business'=>['ceo_advisor'=>'مستشار المدير التنفيذي','competitor_analysis'=>'تحليل المنافسين','competitive_insights'=>'رؤى المنافسة','revenue_copilot'=>'مستشار الإيرادات','crm_assistant'=>'مساعد CRM','crm_summary'=>'تلخيص العملاء','gbp_insights'=>'تحليل Google Business','gbp_content'=>'محتوى Google Business','knowledge_import'=>'استيراد المعرفة','autofix_values'=>'اقتراح إصلاحات الموقع','ai_assistant'=>'المساعد العام'],
          'support'=>['chat_reply'=>'ردود المحادثات','reply_suggestions'=>'اقتراحات الردود','review_content'=>'محتوى طلبات التقييم','tourism_content'=>'المحتوى السياحي العام','review_reply'=>'الرد على التقييمات','website_analysis'=>'تحليل المواقع','sentiment'=>'تحليل المشاعر','translation'=>'الترجمة'],
          'media'=>['creative_studio_image'=>'توليد صور الاستوديو','creative_studio_edit'=>'تعديل الصور المرجعية','article_image'=>'توليد صور المقالات','website_image'=>'صور صفحات المواقع','creative_studio_video'=>'توليد الفيديو'],
        ];$out=[];
        foreach($groups as $group=>$tasks)foreach($tasks as $id=>$title){$kind=$group==='media'?($id==='creative_studio_video'?'video':'image'):'text';$supported=$kind==='video'?['veo']:($kind==='image'?($id==='creative_studio_edit'?['gemini']:['openai','gemini']):['gemini','anthropic','openai','deepseek','kimi']);$out[$id]=['id'=>$id,'title'=>$title,'group'=>$group,'kind'=>$kind,'providers'=>$supported];}
        return $out;
    }
    public static function apiKey(string $provider): string
    {
        if($provider==='veo')$provider='gemini';
        if(!isset(self::PROVIDERS[$provider]))return '';
        $constant=strtoupper($provider).'_API_KEY';$value=defined($constant)?(string)constant($constant):'';
        if(class_exists('SystemSettingsService')){
            $s=new SystemSettingsService();$v=$s->get('integration_'.strtolower($constant),'');
            if($v==='')$v=$s->get(strtolower($constant),'');
            if($v!=='')$value=$v;
        }
        return trim($value);
    }
    public static function defaultModel(string $provider,string $kind='text'): string
    {
        $key=$provider==='veo'?'VEO_MODEL':strtoupper($provider).($kind==='image'?'_IMAGE_MODEL':'_MODEL');
        $v=defined($key)?constant($key):(function_exists('env')?env($key):getenv($key));
        if(is_string($v)&&preg_match('/^[A-Za-z0-9._-]{1,120}$/D',$v))return $v;
        return ['veo'=>'veo-3.1-fast-generate-preview','gemini'=>$kind==='image'?'gemini-2.5-flash-image':'gemini-flash-latest','openai'=>$kind==='image'?'gpt-image-1':'gpt-4o-mini','anthropic'=>'claude-sonnet-4-5','deepseek'=>'deepseek-chat','kimi'=>'moonshot-v1-8k'][$provider]??'';
    }
    public static function state(): array
    {
        $pdo=Database::getInstance()->getConnection();
        try{$row=$pdo->query('SELECT revision,config_json,updated_by,updated_at FROM ai_service_routing WHERE id=1')->fetch(PDO::FETCH_ASSOC);}
        catch(PDOException $e){$missing=(string)$e->getCode()==='42S02'||($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: ai_service_routing');if($missing)return ['ready'=>false,'revision'=>0,'routes'=>[],'updated_by'=>null,'updated_at'=>null];throw $e;}
        if(!$row)return ['ready'=>false,'revision'=>0,'routes'=>[],'updated_by'=>null,'updated_at'=>null];
        $routes=json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR);if(!is_array($routes))throw new RuntimeException('Invalid routing state');
        return ['ready'=>true,'revision'=>(int)$row['revision'],'routes'=>$routes,'updated_by'=>$row['updated_by'],'updated_at'=>$row['updated_at']];
    }
    public static function policy(string $task): ?array
    {
        $state=self::state();$catalog=self::catalog();$id=isset($catalog[$task])?$task:'default';$route=$state['routes'][$id]??null;
        if(!$route && ($catalog[$id]['kind']??'text')==='text')$route=$state['routes']['default']??null;
        return is_array($route)?$route:null;
    }
    public static function validate(string $task,array $route,bool $checkKeys=true): array
    {
        $entry=self::catalog()[$task]??null;if(!$entry)throw new InvalidArgumentException('خدمة غير معروفة.');
        if(array_diff(array_keys($route),['enabled','chain','models']))throw new InvalidArgumentException('حقول إعداد غير معروفة.');
        if(!array_key_exists('enabled',$route)||!is_bool($route['enabled']))throw new InvalidArgumentException('حالة الخدمة غير صحيحة.');
        $chain=$route['chain']??null;$models=$route['models']??[];
        if(!is_array($chain)||array_keys($chain)!==range(0,count($chain)-1)||count($chain)<1||count($chain)>3||count(array_unique(array_filter($chain,'is_string'))) !== count($chain)||!is_array($models))throw new InvalidArgumentException('اختر مزودًا أساسيًا وبدائل مختلفة، بحد أقصى ثلاثة.');
        if($entry['kind']==='video'&&count($chain)!==1)throw new InvalidArgumentException('لا يوجد بديل فيديو مدمج حاليًا.');
        $clean=[];
        foreach($chain as $provider){
            if(!is_string($provider)||!in_array($provider,$entry['providers'],true))throw new InvalidArgumentException('المزود لا يدعم هذه الخدمة في المنصة.');
            $model=$models[$provider]??self::defaultModel($provider,$entry['kind']);
            if(!is_string($model)||!preg_match('/^[A-Za-z0-9._-]{1,120}$/D',$model))throw new InvalidArgumentException('معرف النموذج غير صالح؛ استخدم اسم النموذج فقط.');
            $match=$entry['kind']==='video'?str_starts_with($model,'veo-'):(($entry['kind']==='image')?($provider==='gemini'?str_starts_with($model,'gemini-')&&str_contains($model,'image'):(str_starts_with($model,'gpt-image-')||$model==='dall-e-3')):!preg_match('/(?:image|^veo-|^dall-e-|embedding|^whisper-)/i',$model));
            if(!$match)throw new InvalidArgumentException('نوع النموذج لا يتوافق مع الخدمة.');
            if($checkKeys&&$route['enabled']&&self::apiKey($provider)==='')throw new InvalidArgumentException('أضف مفتاح '.$provider.' في التكاملات قبل تفعيل المسار.');
            $clean[$provider]=$model;
        }
        if(array_diff(array_keys($models),$chain))throw new InvalidArgumentException('نماذج لمزود غير مختار.');
        return ['enabled'=>$route['enabled'],'chain'=>$chain,'models'=>$clean];
    }
    public static function save(array $changes,int $expected,int $userId): array
    {
        if(!$changes||count($changes)>count(self::catalog()))throw new InvalidArgumentException('لا توجد تغييرات صالحة.');$validated=[];
        foreach($changes as $task=>$route){if(!is_string($task)||!isset(self::catalog()[$task]))throw new InvalidArgumentException('خدمة غير معروفة.');if($route!==null&&!is_array($route))throw new InvalidArgumentException('إعداد الخدمة غير صالح.');$validated[$task]=$route===null?null:self::validate($task,$route);}
        $db=Database::getInstance();return $db->transaction(function()use($db,$expected,$userId,$validated){
            $pdo=$db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=$pdo->query('SELECT * FROM ai_service_routing WHERE id=1'.$lock)->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Routing migration required');if((int)$row['revision']!==$expected)throw new LogicException('Routing revision conflict');$old=json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR);$routes=$old;
            foreach($validated as $task=>$route){if($route===null)unset($routes[$task]);else $routes[$task]=$route;}
            $diff=[];foreach($validated as $task=>$route){if(($old[$task]??null)!==($routes[$task]??null))$diff[$task]=['before'=>$old[$task]??null,'after'=>$routes[$task]??null];}
            if(!$diff)return self::state();
            $revision=$expected+1;$now=date('Y-m-d H:i:s');$s=$pdo->prepare('UPDATE ai_service_routing SET revision=?,config_json=?,updated_by=?,updated_at=? WHERE id=1 AND revision=?');$s->execute([$revision,json_encode($routes,JSON_THROW_ON_ERROR),$userId,$now,$expected]);if($s->rowCount()!==1)throw new LogicException('Routing revision conflict');
            $s=$pdo->prepare('INSERT INTO ai_service_routing_audit(revision,user_id,changes_json,created_at) VALUES(?,?,?,?)');$s->execute([$revision,$userId,json_encode($diff,JSON_THROW_ON_ERROR),$now]);return self::state();
        });
    }
    public static function snapshot(string $task): ?array
    {
        $policy=self::policy($task);if(!$policy)return null;$clean=self::validate($task,$policy);if(!$clean['enabled'])throw new InvalidArgumentException('هذه الخدمة موقوفة مؤقتًا.');return $clean;
    }
    public static function mediaSnapshot(string $task): array
    {
        $custom=self::snapshot($task);if($custom!==null)return $custom;
        $entry=self::catalog()[$task]??null;if(!$entry||$entry['kind']==='text')throw new InvalidArgumentException('Invalid media task');
        $spec=$task==='creative_studio_video'?'veo':($task==='creative_studio_edit'?'gemini':(function_exists('env')?env($task==='article_image'?'ARTICLE_IMAGE_PROVIDERS':'MEDIA_IMAGE_PROVIDERS'):null));
        if(!is_string($spec)||$spec==='')$spec='openai>gemini';$chain=[];$models=[];
        foreach(explode('>',strtolower($spec)) as $name){$name=trim($name);if(in_array($name,$entry['providers'],true)&&!in_array($name,$chain,true)&&self::apiKey($name)!==''){$chain[]=$name;$models[$name]=self::defaultModel($name,$entry['kind']);}}
        if(!$chain)throw new InvalidArgumentException('المزود غير جاهز؛ راجع إعدادات التكاملات.');
        return ['enabled'=>true,'chain'=>$chain,'models'=>$models];
    }
    /** Media fallbacks are attempted only after a definitive response, never on an uncertain timeout. */
    public static function image(string $prompt,string $ratio,array $meta,array $policy,?callable $factory=null): array
    {
        if(!$policy['enabled'])return self::failure();$last=['success'=>false,'error'=>'Image provider unavailable','http_code'=>0];
        foreach($policy['chain'] as $provider){
            if(!in_array($provider,['gemini','openai'],true))throw new InvalidArgumentException('Invalid image provider');
            $model=$policy['models'][$provider];$options=$meta;$options['_routing_image_bypass']=true;$options['model']=$model;
            if($factory)$client=$factory($provider,$model);
            else $client=$provider==='gemini'?new GeminiClient():new OpenAIImageClient(null,null,$model);
            $result=$client->generateImage($prompt,$ratio,$options);
            if(!empty($result['success']))return $result+['provider'=>$provider];$last=$result;
            $http=(int)($result['http_code']??0);if($http===0||$http>=500)return $result;
        }
        return $last;
    }
    public static function failure(): array{return ['success'=>false,'error'=>'الخدمة غير متاحة مؤقتًا. حاول لاحقًا.','content'=>null,'tokens_total'=>0,'estimated_cost_usd'=>0,'fallback_used'=>false];}
    public static function describe(): array
    {
        $state=self::state();$providers=[];foreach(self::PROVIDERS as $id=>$name){$providers[$id]=['name'=>$name,'configured'=>self::apiKey($id)!=='','models'=>['text'=>self::defaultModel($id),'image'=>self::defaultModel($id,'image'),'video'=>self::defaultModel($id,'video')]];}
        $catalog=self::catalog();foreach($catalog as $id=>&$entry){$entry['route']=$state['routes'][$id]??null;$entry['effective']=$entry['route']??($entry['kind']==='text'?($state['routes']['default']??null):null);$entry['source']=$entry['route']?'custom':($entry['effective']?'default':'legacy');}unset($entry);
        $audit=[];if($state['ready'])$audit=Database::getInstance()->getConnection()->query('SELECT revision,user_id,changes_json,created_at FROM ai_service_routing_audit ORDER BY revision DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
        foreach($audit as &$record){$record['changes']=json_decode($record['changes_json'],true);unset($record['changes_json']);}unset($record);
        return $state+['catalog'=>array_values($catalog),'providers'=>$providers,'audit'=>$audit];
    }
}
