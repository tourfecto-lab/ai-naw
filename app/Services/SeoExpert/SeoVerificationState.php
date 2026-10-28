<?php
/** A stored verification is a dated observation, never a promise of current availability. */
final class SeoVerificationState
{
    public static function content(string $text,bool $articleSource=false): string {
        if($articleSource){require_once __DIR__.'/../Seo/GuideContent.php';$text=GuideContent::html($text);}
        $text=html_entity_decode(strip_tags(preg_replace('~</(?:p|div|h[1-6]|li|section)>|<br\s*/?>~i',' ',$text)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text)));
    }
    public static function forTask(array $task,?int $now=null): array {
        $now??=time();$at=!empty($task['checked_at'])?strtotime($task['checked_at'].' UTC'):false;
        if(($task['status']??'')!=='verified')return ['state'=>'not_verified','checked_at'=>$task['checked_at']??null];
        return ['state'=>$at&&$at<=$now&&$at>=$now-86400?'verified_recently':'historical','checked_at'=>$task['checked_at']??null];
    }
}
