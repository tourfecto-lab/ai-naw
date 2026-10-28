<?php
/**
 * Tourfecto - حارس طلبات التوليد العالقة
 * أي طلب وقف (worker مات، طابور اتعطل، رابط فيديو انتهى) كان بيفضل "قيد التوليد" للأبد:
 * الرصيد محجوز والعميل ممنوع من طلبات جديدة. الحارس بيكمّل اللي اتولّد فعلًا ومستني الخصم،
 * وبيقفل الباقي كفشل بدون خصم ويرجّع الحجز. (cron/creative_media_watchdog.php كل 10 دقايق)
 */
class CreativeGenerationWatchdog
{
    /** حدود الانتظار بالدقايق لكل مرحلة قبل اعتبار الطلب عالق */
    public const LIMITS=['queued'=>120,'image_active'=>45,'video_active'=>240,'edit_active'=>45,'download_failed'=>2160];

    /** @return string none | resume | stalled | video_expired */
    public static function decide(array $row,int $now): string
    {
        if(($row['status']??'')!=='generating')return 'none';
        // العمر محسوب في MySQL نفسها (age_minutes) عشان فرق المنطقة الزمنية بين PHP وقاعدة البيانات مايأثرش
        if(isset($row['age_minutes'])&&is_numeric($row['age_minutes']))$age=(float)$row['age_minutes'];
        else{$updated=strtotime((string)($row['updated_at']??$row['created_at']??''));if(!$updated)return 'none';$age=($now-$updated)/60;}$stage=(string)($row['generation_stage']??'queued');
        $meta=json_decode((string)($row['generation_meta']??''),true)?:[];
        $video=($row['type']??'')==='short_video';$edit=in_array($meta['source']??'',['video_editor','storyboard_render'],true);
        // الملف اتكتب ومستني الخصم بس: نكمّله بدل ما نضيّعه
        if($stage==='billing'&&!empty($row['file_path']))return $age>=10?'resume':'none';
        if($stage==='download_failed')return $age>=self::LIMITS['download_failed']?'video_expired':'none';
        if($stage==='queued'||$stage==='')return $age>=self::LIMITS['queued']?'stalled':'none';
        $limit=$edit?self::LIMITS['edit_active']:($video?self::LIMITS['video_active']:self::LIMITS['image_active']);
        return $age>=$limit?'stalled':'none';
    }

    /** @return array{checked:int,resumed:int,failed:int,errors:int} */
    public static function run(int $limit=200): array
    {
        $out=['checked'=>0,'resumed'=>0,'failed'=>0,'errors'=>0];
        $pdo=Database::getInstance()->getConnection();
        $rows=$pdo->query("SELECT id,user_id,type,status,generation_stage,generation_meta,file_path,created_at,updated_at,TIMESTAMPDIFF(MINUTE,COALESCE(updated_at,created_at),NOW()) AS age_minutes FROM media_items WHERE status='generating' ORDER BY id LIMIT ".max(1,min(1000,$limit)))->fetchAll(PDO::FETCH_ASSOC);
        $now=time();
        foreach($rows as $row){
            $out['checked']++;$action=self::decide($row,$now);if($action==='none')continue;
            $id=(int)$row['id'];if(!GenerationLifecycle::lock($id))continue;
            try{
                $item=(new MediaItem())->find($id);if(!$item||$item->getAttribute('status')!=='generating')continue;
                if($action==='resume'){
                    $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];
                    $feature=$item->getAttribute('type')==='short_video'?'media_video':'media_image';
                    GenerationLifecycle::complete($item,$feature);$out['resumed']++;
                    if(($meta['source']??'')==='video_editor'&&!empty($meta['overlay']))try{@unlink(PrivateMedia::resolve((string)$meta['overlay']));}catch(Throwable $e){}
                }else{
                    GenerationLifecycle::fail($item,$action==='video_expired'?'CREATIVE_VIDEO_EXPIRED download window elapsed':'CREATIVE_STALLED no progress at stage '.$item->getAttribute('generation_stage'));$out['failed']++;
                }
            }catch(Throwable $e){$out['errors']++;Logger::warning('Creative watchdog item failed',['media_item_id'=>$id,'error'=>mb_substr($e->getMessage(),0,200)]);}
            finally{GenerationLifecycle::unlock($id);}
        }
        return $out;
    }
}
