<?php
require_once __DIR__.'/../SeoExpert/SeoTaskFollowUp.php';
require_once __DIR__.'/../SeoExpert/SeoRecommendationReview.php';
require_once __DIR__.'/../SeoExpert/SeoKeywordConflictReview.php';
/** Calendar-month, Arabic, print/PDF-friendly reports; absent sources stay unknown. */
class SeoMonthlyReportService
{
    public function __construct(private Database $db,private $searchFactory=null) {}
    public static function month(?string $now=null): array {
        $end=(new DateTimeImmutable($now??'now',new DateTimeZone('UTC')))->modify('first day of this month')->setTime(0,0);
        return ['start'=>$end->modify('-1 month')->format('Y-m-d'),'end'=>$end->modify('-1 day')->format('Y-m-d')];
    }
    private function has(string $table): bool {return (bool)$this->db->query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);}
    public static function assemble(array $sources,array $period): array {
        return ['period'=>$period,'gsc'=>$sources['gsc']??['state'=>'not_connected'],'rankings'=>$sources['rankings']??[],
            'ai_visibility'=>$sources['ai_visibility']??[],'core_web_vitals'=>$sources['core_web_vitals']??null,'tasks'=>$sources['tasks']??[],
            'impact'=>$sources['impact']??[],'next_plan'=>$sources['next_plan']??[],'google_follow_up'=>$sources['google_follow_up']??[],'excluded_proposals'=>$sources['excluded_proposals']??[],
            'note'=>'مقارنة قبل/بعد رصدية وليست إثباتًا أن التعديل سبب النتيجة. القيم غير المتاحة ليست صفرًا.'];
    }
    /** Current next-step plan, separate from the historical reporting-period metrics. */
    public static function followUpPlan(array $tasks,?int $now=null): array {
        $out=['next_plan'=>[],'google_follow_up'=>[],'excluded_proposals'=>[]];
        foreach($tasks as $task){
            $snapshot=json_decode((string)($task['snapshot_json']??''),true)?:[];
            if(empty($task['conflict_review']))$task=SeoKeywordConflictReview::forTask($task,$snapshot,true);
            $task['recommendation_review']=$task['recommendation_review']??SeoRecommendationReview::assess($task,$snapshot['pages']??[],SeoClaimEvidence::snapshotFacts($snapshot));
            $follow=SeoTaskFollowUp::forTask($task,$now);if($follow['state']==='historical')continue;
            $bucket=$follow['state']==='waiting_google'?'google_follow_up':($follow['state']==='excluded'?'excluded_proposals':'next_plan');
            if(count($out[$bucket])>=10)continue;
            $row=array_intersect_key($task,array_flip(['id','title','instructions','page_url']));
            $row['follow_up']=$follow;
            if($bucket==='excluded_proposals')$row['review_reason']=$task['recommendation_review']['reason'];
            $out[$bucket][]=$row;
        }
        return $out;
    }
    public function data(int $uid,int $wid,?string $now=null): array {
        (new SeoMarketService($this->db))->site($wid,$uid);$period=self::month($now);$start=$period['start'];$end=$period['end'].' 23:59:59';$sources=[];
        try{
            $client=$this->searchFactory?($this->searchFactory)($uid,$wid):SeoTrafficAlertService::client($uid,$wid);
            if($client){[$api,$property]=$client;$totals=$api->getSearchAnalytics($property,$start,$period['end'],['date'],31);$queries=$api->getSearchAnalytics($property,$start,$period['end'],['query'],10);
                $sources['gsc']=!empty($totals['success'])?['state'=>'measured','clicks'=>array_sum(array_column($totals['rows'],'clicks')),'impressions'=>array_sum(array_column($totals['rows'],'impressions')),'top_queries'=>!empty($queries['success'])?$queries['rows']:null,'property'=>$property]:['state'=>'unavailable'];
            }
        }catch(Throwable $e){$sources['gsc']=['state'=>'unavailable'];}
        if($this->has('seo_rank_tracking_history')&&$this->has('seo_markets')){
            $r=$this->db->query('SELECT h.keyword,h.position,h.checked_at,h.market_id,m.label,m.language_code FROM seo_rank_tracking_history h JOIN seo_markets m ON m.id=h.market_id AND m.website_id=h.website_id WHERE h.website_id=? AND h.user_id=? AND h.checked_at BETWEEN ? AND ? ORDER BY h.checked_at DESC,h.id DESC LIMIT 10000',[$wid,$uid,$start,$end]);$seen=[];$out=[];
            foreach($r as $row){$key=$row['market_id'].'|'.$row['keyword'];if(!isset($seen[$key])){$out[]=$row;$seen[$key]=true;}}$sources['rankings']=$out;
        }
        if($this->has('seo_ai_answers'))$sources['ai_visibility']=$this->db->query('SELECT provider,market_id,COUNT(*) samples,SUM(mentioned_brand)/COUNT(*) share_of_voice FROM seo_ai_answers WHERE website_id=? AND user_id=? AND checked_at BETWEEN ? AND ? GROUP BY provider,market_id',[$wid,$uid,$start,$end]);
        if($this->has('seo_expert_runs')){
            $r=$this->db->query('SELECT snapshot_json,updated_at FROM seo_expert_runs WHERE website_id=? AND user_id=? AND updated_at BETWEEN ? AND ? AND snapshot_json IS NOT NULL ORDER BY id DESC LIMIT 1',[$wid,$uid,$start,$end]);
            if($r){$snapshot=json_decode($r[0]['snapshot_json'],true)?:[];$sources['core_web_vitals']=['samples'=>$snapshot['tech']['speed']??[],'captured_at'=>$snapshot['tech']['checked_at']??$r[0]['updated_at']];}
        }
        if($this->has('seo_expert_tasks')){
            $sources['tasks']=$this->db->query("SELECT id,title,page_url,published_url,status,checked_at FROM seo_expert_tasks WHERE website_id=? AND user_id=? AND status IN ('active','verified') AND updated_at BETWEEN ? AND ? ORDER BY id",[$wid,$uid,$start,$end]);
            // These sections describe current follow-up, including work created after the
            // historical metric month. Keep the period boundary on measured results only.
            $candidates=$this->db->query("SELECT * FROM seo_expert_tasks WHERE website_id=? AND user_id=? AND status IN ('pending','needs_client') ORDER BY id DESC LIMIT 1000",[$wid,$uid]);
            // Include saved FAQ/description claims just as the interactive task review does.
            if(array_filter($candidates,static fn($task)=>$task['kind']==='content')&&$this->has('seo_content_quality')){
                $qualityRows=$this->db->query('SELECT source_key,description,faqs_json FROM seo_content_quality WHERE website_id=? AND user_id=? AND task_id IS NOT NULL',[$wid,$uid]);
                $byKey=array_column($qualityRows,null,'source_key');
                foreach($candidates as &$candidate)if($candidate['kind']==='content'&&isset($byKey['task:'.$candidate['id']])){
                    $quality=$byKey['task:'.$candidate['id']];$candidate['content_review']=['meta_description'=>$quality['description']??'','faqs'=>json_decode($quality['faqs_json'],true)?:[]];
                }unset($candidate);
            }
            // Fetch each owned run once; never multiply its potentially large snapshot per task row.
            $groups=[];foreach($candidates as $index=>$task)$groups[(int)$task['run_id']][]=$index;
            $hasRuns=$this->has('seo_expert_runs');
            foreach($groups as $runId=>$indices){
                $run=$hasRuns?$this->db->query('SELECT snapshot_json FROM seo_expert_runs WHERE id=? AND website_id=? AND user_id=? LIMIT 1',[$runId,$wid,$uid]):[];
                $snapshot=json_decode((string)($run[0]['snapshot_json']??''),true)?:[];
                foreach($indices as $index){
                    $candidates[$index]=SeoKeywordConflictReview::forTask($candidates[$index],$snapshot,true);
                    $candidates[$index]['recommendation_review']=SeoRecommendationReview::assess($candidates[$index],$snapshot['pages']??[],SeoClaimEvidence::snapshotFacts($snapshot));
                }
            }
            $sources=array_replace($sources,self::followUpPlan($candidates));
        }
        if($this->has('seo_impact_monitors')){
            $sources['impact']=$this->db->query('SELECT id,page_url,window_days,status,result_json,last_collected_at FROM seo_impact_monitors WHERE website_id=? AND user_id=? AND created_at<=? ORDER BY id DESC LIMIT 100',[$wid,$uid,$end]);
            foreach($sources['impact'] as &$i){$i['task_ids']=$this->has('seo_auto_impact_monitors')?array_column($this->db->query('SELECT task_id FROM seo_auto_impact_monitors WHERE website_id=? AND user_id=? AND monitor_id=?',[$wid,$uid,$i['id']]),'task_id'):[];$i['result']=!empty($i['last_collected_at'])&&$i['last_collected_at']<=$end?json_decode($i['result_json'],true):null;unset($i['result_json']);}unset($i);
        }
        return self::assemble($sources,$period);
    }
    public static function html(array $data,string $site): string {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$unknown='غير مقاس';$gsc=$data['gsc'];$measured=($gsc['state']??'')==='measured';
        $html='<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><style>body{font:16px/1.7 Arial,sans-serif;direction:rtl;color:#182333;max-width:900px;margin:auto;padding:24px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ddd;padding:8px;text-align:right}h2{margin-top:24px}tr,section{break-inside:avoid}@media print{body{padding:0;font-size:12pt}a{color:inherit}}</style></head><body>';
        $html.='<h1>تقرير SEO الشهري — '.$e($site).'</h1><p>'.$e($data['period']['start']).' — '.$e($data['period']['end']).'</p><h2>أداء بحث جوجل</h2><p>النقرات: '.$e($measured?$gsc['clicks']:$unknown).' · مرات الظهور: '.$e($measured?$gsc['impressions']:$unknown).'</p>';
        $html.='<table><tr><th>أهم استعلامات البحث</th><th>النقرات</th><th>الظهور</th></tr>';foreach($gsc['top_queries']??[] as $q)$html.='<tr><td>'.$e($q['query']).'</td><td>'.$e($q['clicks']).'</td><td>'.$e($q['impressions']).'</td></tr>';$html.='</table>';
        $html.='<h2>الترتيب حسب السوق — أحدث قياس في الشهر</h2><table><tr><th>السوق</th><th>الكلمة</th><th>الترتيب</th><th>تاريخ القياس</th></tr>';foreach($data['rankings'] as $r)$html.='<tr><td>'.$e($r['label']).'</td><td>'.$e($r['keyword']).'</td><td>'.$e($r['position']??'لم يظهر ضمن العمق المقاس').'</td><td>'.$e($r['checked_at']).'</td></tr>';$html.='</table>';
        $html.='<h2>الظهور في عينات إجابات الذكاء الاصطناعي</h2><ul>';foreach($data['ai_visibility'] as $a)$html.='<li>'.$e($a['provider']).' · السوق '.$e($a['market_id']).': '.$e(round((float)$a['share_of_voice']*100,1)).'% من '.$e($a['samples']).' عينة</li>';$html.='</ul><p>هذه عينات API وليست ترتيبًا في تطبيقات المستهلكين.</p>';
        $html.='<h2>Core Web Vitals</h2>';if(empty($data['core_web_vitals']['samples']))$html.='<p>غير مقاس في هذا الشهر</p>';foreach($data['core_web_vitals']['samples']??[] as $sample){$html.='<p>'.$e($sample['url']??'').' · '.$e($data['core_web_vitals']['captured_at']??'').'</p>';foreach(['field'=>'بيانات المستخدمين الحقيقية','lab'=>'قياس المختبر'] as $key=>$label){$html.='<p>'.$label.': ';$values=[];foreach($sample[$key]??[] as $k=>$v)if(is_scalar($v))$values[]=$e($k).': '.$e($v);$html.=($values?implode(' · ',$values):$unknown).'</p>';}}
        $html.='<h2>المهام المطبقة والتحقق</h2><ul>';foreach($data['tasks'] as $t)$html.='<li>'.$e($t['title']).' — '.$e($t['status']==='verified'?'تم التحقق':'مطبق؛ التحقق قيد الانتظار').'</li>';$html.='</ul><h2>الأثر المقاس — نوافذ 7/14/28 يومًا</h2><table><tr><th>الصفحة</th><th>النافذة</th><th>المهام المرتبطة</th><th>نقرات قبل / بعد</th><th>الحالة</th></tr>';
        foreach($data['impact'] as $i){$b=$i['result']['search']['before']['clicks']??null;$a=$i['result']['search']['after']['clicks']??null;$html.='<tr><td>'.$e($i['page_url']).'</td><td>'.$e($i['window_days']).'</td><td>'.$e(implode(', ',$i['task_ids']??[])).'</td><td>'.$e($b??$unknown).' / '.$e($a??$unknown).'</td><td>'.$e($i['status']).'</td></tr>';}$html.='</table><h2>متابعة زحف Google — الحالة وقت إعداد التقرير</h2><ul>';foreach($data['google_follow_up']??[] as $t)$html.='<li>'.$e($t['page_url']??$t['title']).' — '.$e($t['follow_up']['reason']).' — '.$e($t['follow_up']['checked_at']??'').'</li>';$html.='</ul><h2>اقتراحات مستبعدة من الاعتماد</h2><ul>';foreach($data['excluded_proposals']??[] as $t)$html.='<li>'.$e($t['title']).' — '.$e($t['review_reason']).'</li>';$html.='</ul>';
        $html.='<h2>خطوات المتابعة الحالية — خارج فترة القياسات التاريخية</h2><ul>';foreach($data['next_plan'] as $p)$html.='<li><strong>'.$e($p['title']).'</strong><p>'.$e($p['instructions']).'</p>'.(($p['follow_up']['state']??'')==='needs_confirmation'?'<p>'.$e($p['follow_up']['reason']).'</p>':'').'</li>';$html.='</ul><p>'.$e($data['note']).'</p></body></html>';return $html;
    }
}
