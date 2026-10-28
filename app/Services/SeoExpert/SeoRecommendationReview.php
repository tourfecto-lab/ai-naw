<?php
require_once __DIR__.'/SeoPagePolicy.php';
require_once __DIR__.'/SeoSchemaBuilder.php';
require_once __DIR__.'/SeoPlanQualityGate.php';
require_once __DIR__.'/SeoClaimEvidence.php';
/** A retained proposal can be unsuitable without being deleted or counted as executed. */
final class SeoRecommendationReview
{
    public static function applied(array $task): bool {
        return !empty($task['is_active'])||!empty($task['previous_task_id'])||!empty($task['external_post_id'])||!empty($task['published_url'])||in_array($task['status']??'',['approved','active','verified','publishing','uncertain','reverted'],true);
    }
    public static function decisionEligible(array $task): bool {
        if(self::applied($task)||!in_array($task['status']??'',['pending','needs_client'],true))return false;
        if(($task['kind']??'')==='metadata')return in_array($task['field_name']??'',['title','description'],true);
        return ($task['kind']??'')==='manual'
            &&!SeoPagePolicy::route((string)($task['page_url']??''))['eligible']
            &&(str_starts_with((string)($task['title']??''),'Not indexed: ')||str_starts_with((string)($task['title']??''),'مش ظاهرة في جوجل: '));
    }
    public static function assess(array $task,array $pages,array $facts=[]): array {
        $review=SeoClaimEvidence::task($task,$facts);$evidence=$task['evidence']??json_decode((string)($task['evidence_json']??''),true)??[];
        if(($task['kind']??'')==='content'&&empty($task['is_active'])&&!empty($evidence['superseded_by_task_id']))$review['state']='historical';
        return self::assessment($task,$pages)+['claim_review'=>$review];
    }
    private static function assessment(array $task,array $pages): array {
        $out=['state'=>'reviewable','code'=>'human_review','reason'=>'Review the proposal against current customer facts. / راجع الاقتراح وفق معلومات العميل الحالية.','basis'=>'stored_page_snapshot'];
        if(self::applied($task))return ['state'=>'retained','code'=>'previously_attempted','reason'=>'Existing execution history is retained. / تم الاحتفاظ بسجل التنفيذ.','basis'=>'task_history'];
        $e=$task['evidence']??json_decode((string)($task['evidence_json']??''),true)??[];
        if(self::decisionEligible($task)&&($e['review_decision']['decision']??'')==='keep_current')return array_replace($out,['state'=>'excluded','code'=>'client_kept_current','reason'=>'Reviewed: keep the current page. / تمت المراجعة: الاحتفاظ بالصفحة الحالية. '.(string)($e['review_decision']['reason']??''),'basis'=>'customer_review']);
        $kind=$task['kind']??'';
        $title=(string)($task['title']??'');
        if($kind==='manual'&&(str_starts_with($title,'Not indexed: ')||str_starts_with($title,'مش ظاهرة في جوجل: '))&&!SeoPagePolicy::route((string)($task['page_url']??''))['eligible'])return array_replace($out,['code'=>'indexing_intent_review','reason'=>'Decide whether this operational page should appear in search. No indexing restriction is changed automatically. / حدد هل الصفحة التشغيلية مطلوب ظهورها في البحث؛ لا نغيّر قرار الفهرسة تلقائيًا.']);
        if($kind==='manual'&&preg_match('/\b(?:schema|structured data|json-ld)\b/i',(string)($task['title']??'').' '.(string)($task['instructions']??''))){
            foreach($pages as $p)if(($p['url']??'')===($task['page_url']??'')){
                $candidate=['page_index'=>0,'title'=>$task['title']??'','instructions'=>$task['instructions']??''];
                if(!SeoPlanQualityGate::filterClientTasks([$candidate],[$p]))return array_replace($out,['state'=>'excluded','code'=>'unsupported_schema_task','reason'=>'The stored page evidence does not support this generic or redundant schema task. / بيانات الصفحة المحفوظة لا تبرر مهمة البيانات المنظمة العامة أو المكررة.']);
            }
        }
        if(!in_array($kind,['metadata','content'],true))return $out;
        $url=(string)($task['page_url']??'');$scope=SeoPagePolicy::route($url);
        if(!$scope['eligible'])return array_replace($out,['state'=>'excluded','code'=>'outside_marketing_scope','reason'=>'This operational or template page must not receive marketing edits. / صفحة تشغيلية أو قالب؛ مستبعدة من تعديلات التسويق.']);
        if($kind!=='metadata')return $out;
        $page=null;foreach($pages as $candidate)if(($candidate['url']??'')===$url){$page=$candidate;break;}
        if(!$page)return array_replace($out,['state'=>'needs_evidence','code'=>'missing_page_snapshot','reason'=>'Page evidence is missing; review the live page before approving. / بيانات الصفحة غير متاحة؛ راجعها قبل الاعتماد.']);
        if(!SeoPagePolicy::classify($page)['eligible'])return array_replace($out,['state'=>'excluded','code'=>'indexing_or_canonical_intent','reason'=>'This page needs an indexing/canonical intent decision before marketing edits. / الصفحة تحتاج قرارًا بخصوص الفهرسة أو الرابط الأساسي قبل تعديل التسويق.']);
        $field=(string)($task['field_name']??'');$value=(string)($task['expected_value']??'');
        if(!SeoSchemaBuilder::taskCompatible($page,$field,$value))return array_replace($out,['state'=>'excluded','code'=>'single_trip_mismatch','reason'=>'A collection or another page type cannot receive this single-trip proposal. Safe collection navigation remains supported. / الاقتراح مخصص لرحلة واحدة والصفحة ليست كذلك؛ مسار التنقل الآمن يظل متاحًا.']);
        if(in_array($field,['title','description'],true)){
            $now=(string)($page['values'][$field][0]??$page[$field]??'');$others=[];foreach($pages as $p)if(($p['url']??'')!==$url)$others[]=(string)($p['values'][$field][0]??$p[$field]??'');
            if(SeoPlanQualityGate::issue($field,$now,$others)===null)return array_replace($out,['code'=>'existing_metadata_no_detected_issue','reason'=>'No basic problem was detected in the existing metadata. Keep its factual details unless a specific improvement is reviewed. / لم تُرصد مشكلة أساسية في البيانات الحالية؛ حافظ على التفاصيل المؤكدة إلا بعد مراجعة تحسين محدد.']);
        }
        return $out;
    }
    public static function publicVerificationEligible(array $task): bool {
        $evidence=$task['evidence']??json_decode((string)($task['evidence_json']??''),true)??[];
        if(($task['kind']??'')==='content'&&empty($task['is_active'])&&!empty($evidence['superseded_by_task_id']))return false;
        // URL Inspection and sitemap listings require their own connected checks.
        if(!in_array($task['kind']??'',['metadata','content'],true))return false;
        return in_array($task['status']??'',['active','verified','reverted'],true)||(($task['status']??'')==='needs_client'&&!empty($task['is_active']));
    }
}
