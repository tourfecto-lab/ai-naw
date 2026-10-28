<?php
/** Per-item provider guard, checked persistence and transactional usage finalization. */
class GenerationLifecycle
{
    public static function save(MediaItem $item): void
    {
        if(!$item->save())throw new RuntimeException('Media persistence failed');
    }
    public static function lock(int $id): bool
    {
        $pdo=Database::getInstance()->getConnection();
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return true;
        $s=$pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute(['tourfecto_media_'.$id]);return (int)$s->fetchColumn()===1;
    }
    public static function unlock(int $id): void
    {
        $pdo=Database::getInstance()->getConnection();
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$s=$pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute(['tourfecto_media_'.$id]);}
    }
    public static function stage(MediaItem $item,string $stage): void
    {
        if(!in_array($stage,GenerationOptions::STAGES,true))throw new InvalidArgumentException('Invalid stage');
        $item->setAttribute('generation_stage',$stage);self::save($item);
    }
    public static function fail(MediaItem $item,string $diagnostic): void
    {
        Logger::error('Creative generation failed',['media_item_id'=>$item->getAttribute('id'),'diagnostic'=>mb_substr($diagnostic,0,1000)]);
        $item->setAttribute('status','failed');$item->setAttribute('generation_stage','failed');
        Database::getInstance()->transaction(function()use($item,$diagnostic){$item->setAttribute('error_message',self::publicFailure($diagnostic));self::save($item);CreativeReservations::release((int)$item->getAttribute('id'));});
    }
    /** Fixed messages only: provider responses and credentials stay in private logs. */
    public const PUBLIC_MESSAGES=[
        'safety'=>'تم حجب النتيجة بواسطة فلاتر الأمان لدى مزود التوليد، ولم يُخصم أي رصيد. عدّل الوصف (تجنب الأشخاص المعروفين والقُصّر والعلامات التجارية والمحتوى الحساس) ثم أعد المحاولة.',
        'quota'=>'خدمة التوليد تجاوزت الحصة أو تحتاج تفعيل الفوترة، ولم يُخصم أي رصيد. تواصل مع الدعم برقم الطلب؛ لا يلزم تغيير الوصف.',
        'auth'=>'خدمة التوليد تحتاج مراجعة المفتاح أو صلاحيات الحساب من الإدارة، ولم يُخصم أي رصيد. تواصل مع الدعم برقم الطلب.',
        'model'=>'نموذج التوليد غير متاح حاليًا ولم يُخصم أي رصيد. تحتاج الإدارة مراجعة إعداد النموذج.',
        'uncertain'=>'تعذر تأكيد نتيجة طلب التوليد ولم يُخصم أي رصيد. تواصل مع الدعم برقم الطلب قبل إعادة المحاولة.',
        'stalled'=>'توقف الطلب لفترة طويلة فتم إلغاؤه وإرجاع الرصيد المحجوز بالكامل. يمكنك إعادة المحاولة الآن.',
        'video_expired'=>'تعذر تنزيل الفيديو من المزود قبل انتهاء صلاحية رابطه، ولم يُخصم أي رصيد. أعد إنشاء الطلب.',
        'quality'=>'النتيجة لم تجتز فحص الجودة التلقائي بعد محاولة تصحيح، ولم يُخصم أي رصيد. جرّب وصفًا أبسط بعنصر رئيسي واحد.',
        'generic'=>'لم يكتمل التوليد ولم يُخصم أي رصيد. تواصل مع الدعم برقم الطلب لمراجعة السبب.',
    ];
    public static function publicFailure(string $diagnostic): string
    {
        $m=self::PUBLIC_MESSAGES;
        if(preg_match('/CREATIVE_STALLED/',$diagnostic))return $m['stalled'];
        if(preg_match('/CREATIVE_VIDEO_EXPIRED/',$diagnostic))return $m['video_expired'];
        if(preg_match('/CREATIVE_QA_REJECTED/',$diagnostic))return $m['quality'];
        if(preg_match('/safety|blocked|moderation|content.policy|content_policy|raiMediaFiltered|فلاتر السلامة|PROHIBITED_CONTENT|IMAGE_SAFETY|responsible ai/i',$diagnostic))return $m['safety'];
        if(preg_match('/quota|billing|insufficient_quota|resource_exhausted|HTTP\s*429|rate.limit/i',$diagnostic))return $m['quota'];
        if(preg_match('/HTTP\s*(401|403)|permission.denied|unauthenticated|verification|not configured/i',$diagnostic))return $m['auth'];
        if(preg_match('/HTTP\s*404|model.*not.found/i',$diagnostic))return $m['model'];
        if(preg_match('/timeout|timed out|outcome uncertain|Interrupted.*request|Interrupted video start/i',$diagnostic))return $m['uncertain'];
        return $m['generic'];
    }
    public static function complete(MediaItem $item,string $feature): void
    {
        $db=Database::getInstance();
        $settled=$db->transaction(function()use($db,$item,$feature){
            $pdo=$db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $s=$pdo->prepare('SELECT status,usage_charged FROM media_items WHERE id=?'.$lock);$s->execute([(int)$item->getAttribute('id')]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Media row missing');
            if($row['status']==='completed')return false;
            if($row['status']!=='generating')throw new RuntimeException('Asset no longer active');
            if(!(int)$row['usage_charged']){
                $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];
                $source=isset($meta['billing'])?CreativePricing::settle($item,$meta['billing'],$feature):(class_exists('PlanUsage')?PlanUsage::charge((int)$item->getAttribute('user_id'),$feature,$feature,'Creative media #'.(int)$item->getAttribute('id')):'failed');
                if($source==='failed')throw new RuntimeException('Media usage settlement failed');
                $meta['billing']=($meta['billing']??['amount'=>$source==='plan'?0:null,'currency'=>'USD','key'=>$feature])+['source'=>$source];$meta['billing']['settled']=true;$item->setAttribute('generation_meta',json_encode($meta,JSON_THROW_ON_ERROR));
                $item->setAttribute('usage_charged',1);
            }
            $item->setAttribute('status','completed');$item->setAttribute('generation_stage','completed');$item->setAttribute('error_message',null);self::save($item);return true;
        });
        if(!$settled)return;
        // Notifications are ancillary: never change a completed asset into a failed generation.
        try{if(class_exists('Notification'))Notification::notify((int)$item->getAttribute('user_id'),'media_generated','التصميم جاهز','النتيجة جاهزة في الاستوديو الإبداعي.','/creative-studio');}catch(Throwable $e){Logger::warning('Creative notification failed',['media_item_id'=>$item->getAttribute('id')]);}
    }
}
