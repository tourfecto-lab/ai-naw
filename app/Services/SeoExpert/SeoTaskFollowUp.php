<?php
require_once __DIR__.'/SeoConnectedTaskVerifier.php';
/** Presentation of the next step; never changes persisted task status or asserts indexing. */
final class SeoTaskFollowUp
{
    public static function forTask(array $task,?int $now=null): array {
        $now??=time();$review=$task['recommendation_review']['state']??'';
        $evidence=$task['evidence']??json_decode((string)($task['evidence_json']??''),true)??[];
        if(($task['kind']??'')==='content'&&empty($task['is_active'])&&!empty($evidence['superseded_by_task_id']))return ['state'=>'historical','reason'=>'A newer publication revision is retained separately. / نسخة تاريخية؛ التصحيح الحالي محفوظ كمهمة مستقلة.'];
        if(in_array($review,['excluded','needs_evidence'],true))return ['state'=>'excluded','reason'=>'Retained proposal excluded from approval. / اقتراح محفوظ ومستبعد من الاعتماد.'];
        if(($task['status']??'')==='verified')return ['state'=>'verified','reason'=>'Dated verification is retained; it is not a ranking improvement claim. / تم الاحتفاظ بالتحقق المؤرخ؛ وليس إثباتًا لتحسن الترتيب.'];
        if(in_array($task['status']??'',['approved','active','publishing'],true))return ['state'=>'in_progress','reason'=>'Approved work or publication verification is in progress. / يجري تنفيذ أو التحقق من العمل المعتمد.'];
        if(($task['recommendation_review']['claim_review']['state']??'')==='needs_confirmation')return ['state'=>'needs_confirmation','reason'=>$task['recommendation_review']['claim_review']['reason']];
        $kind=SeoConnectedTaskVerifier::kind($task);
        if($kind==='indexing'){
            $e=$task['evidence']??json_decode((string)($task['evidence_json']??''),true)??[];
            $check=$e['latest_connected_attempt']??$e['check']??[];
            $date=!empty($check['checked_at'])?strtotime((string)$check['checked_at']):false;
            if(($check['kind']??'')!=='indexing'||($check['state']??'')==='not_measured'||!$date||$date>$now+300||$date<$now-86400)return ['state'=>'needs_check','reason'=>'Refresh the connected Google inspection before deciding the next step. / حدّث فحص Google المتصل قبل تحديد الخطوة التالية.'];
            $o=$check['observed']??[];
            if(($check['state']??'')==='needs_review'&&($check['reason_code']??'')==='discovered_not_crawled'&&($o['reason']??'')==='discovered_not_crawled'&&($o['indexed']??null)===false&&($o['verdict']??'')==='NEUTRAL'&&empty($o['last_crawl'])&&in_array($o['fetch']??'',['','SUCCESSFUL','PAGE_FETCH_STATE_UNSPECIFIED'],true)&&($o['robots']??'')!=='DISALLOWED')return ['state'=>'waiting_google','checked_at'=>$check['checked_at'],'basis'=>'google_index_inspection','reason'=>'Google reports this URL discovered but not yet crawled. Keep it accessible and linked, and monitor crawling. This does not confirm an indexing request or guarantee indexing. / Google اكتشف الرابط ولم يزحف إليه بعد. حافظ على إتاحته وروابطه وتابع الزحف؛ هذا لا يؤكد إرسال طلب ولا يضمن الفهرسة.'];
        }
        return ['state'=>'needs_review','reason'=>'Review the evidence and the required customer action. / راجع الدليل والإجراء المطلوب من العميل.'];
    }
}
