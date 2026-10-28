<?php
require_once __DIR__.'/SeoExpertService.php';
/** Stored evidence only: no remote calls, billing, implicit setup or invented readiness score. */
final class SeoServiceReadiness
{
    private array $tables=[];
    public function __construct(private Database $db) {}
    private function rows(string $sql,array $args=[]): array {$s=$this->db->getConnection()->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function has(string $name): bool{return in_array($name,$this->tables,true);}
    public function inspect(int $uid,int $wid): array
    {
        $site=$this->rows('SELECT id,main_url FROM websites WHERE id=? AND user_id=?',[$wid,$uid])[0]??null;
        if(!$site)throw new InvalidArgumentException('الموقع غير متاح في حسابك');
        $this->tables=array_column($this->rows('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'),'name');
        $checks=[];$add=static function($id,$state,$ar,$en,$hint,$page,$required=false,$extra=[])use(&$checks,$wid){$checks[]=array_merge(['id'=>$id,'state'=>$state,'label_ar'=>$ar,'label_en'=>$en,'hint'=>$hint,'href'=>str_replace('{site}',(string)$wid,$page),'required'=>$required],$extra);};
        $prerequisites=['pay_per_use_pricing','billing_usage_reservations','service_usage_reservations','external_execution_attempts'];
        $add('platform',array_diff($prerequisites,$this->tables)?'blocked':'recorded','تجهيزات المنصة','Platform setup','فيه تجهيز ناقص من ناحيتنا. فريق الدعم هيتابعه، ومش مطلوب منك أي حاجة على موقعك.','/seo-expert?website_id={site}&page=settings',true);
        $profile=null;try{$profile=(new BusinessHubService($this->db))->profile($uid,$wid);}catch(Throwable $e){}
        $confirmed=$profile&&!empty($profile['confirmed_at'])&&empty($profile['needs_reconfirmation']);
        $add('facts',$confirmed?'recorded':'action','بيانات نشاط مؤكدة','Confirmed business facts','أكد خدماتك وعروضك وجمهورك. المنصة مابتنشرش أي معلومة عن شركتك من غير ما تأكدها.','/business-hub?website_id={site}',true);
        $config=$this->has('seo_expert_sites')?($this->rows('SELECT brief_json,policy_json,status FROM seo_expert_sites WHERE website_id=? AND user_id=?',[$wid,$uid])[0]??null):null;
        $configured=false;if($config&&$confirmed){$brief=json_decode($config['brief_json'],true)?:[];try{(new BusinessHubService($this->db))->assertRevision($uid,$wid,$brief);$configured=BusinessHubService::matchesProfile(['brief'=>$brief],$profile,$site['main_url']);}catch(Throwable $e){}}
        $add('setup',$configured?'recorded':'action','صلاحيات العمل وحدود الدورة','Work permissions and cycle budget','حدد المنصة تعدل إيه في موقعك وأقصى ميزانية. مراجعة خطة موجودة مجانية.','/seo-expert?website_id={site}&page=settings',true);
        $worker=SeoExpertService::worker();$add('worker',$worker?'observed':'blocked','التنفيذ التلقائي','Automatic execution',$worker?'الخدمة شغالة وبتنفذ المهام اللي وافقت عليها تلقائيًا.':'التنفيذ التلقائي متوقف مؤقتًا وفريق الدعم اتبلغ. مش محتاج تشتري دورة جديدة.','/seo-expert?website_id={site}&page=results',true);
        $add('connector','check','صلاحية تعديل موقعك','Website editing connection','اضغط فحص الربط عشان نتأكد إن المنصة تقدر تعدل صفحات موقعك.','/seo-expert?website_id={site}&page=settings',true);
        $connections=$this->has('platform_connections')?$this->rows("SELECT platform,MAX(status='connected') AS saved FROM platform_connections WHERE website_id=? AND user_id=? GROUP BY platform",[$wid,$uid]):[];$connections=array_column($connections,'saved','platform');
        foreach(['google_search_console'=>['search','قياس بحث Google','Google Search measurement'],'google_analytics'=>['analytics','قياس الزيارات والأحداث','Traffic and event measurement']] as $platform=>$labels)$add($labels[0],!empty($connections[$platform])?'saved':'action',$labels[1],$labels[2],'الحساب مربوط. اضغط فحص الربط عشان نتأكد إن البيانات بتوصل.','/seo-expert?website_id={site}&page=settings');
        $research=['markets'=>0,'tracked'=>0,'measured'=>0,'ranked'=>0,'invalid'=>0];$markets=$this->has('seo_markets')?$this->rows('SELECT id,label,is_primary FROM seo_markets WHERE website_id=? ORDER BY is_primary DESC,id',[$wid]):[];$research['markets']=count($markets);
        if($this->has('tracked_keywords')){
            $words=$this->rows('SELECT id,keyword,last_checked_at FROM tracked_keywords WHERE website_id=? AND user_id=?',[$wid,$uid]);$research['tracked']=count($words);
            foreach($words as $word){if(DataForSeoRankingSource::keywordPhrase($word['keyword'])!==trim($word['keyword']))$research['invalid']++;if(!empty($word['last_checked_at'])&&strtotime($word['last_checked_at'].' UTC')>=time()-30*86400&&strtotime($word['last_checked_at'].' UTC')<=time()+300)$research['ranked']++;}
        }
        if($this->has('seo_keyword_research')&&$this->has('seo_markets'))$research['measured']=(int)($this->rows("SELECT COUNT(*) AS n FROM seo_keyword_research r JOIN seo_markets m ON m.id=r.market_id AND m.website_id=r.website_id WHERE r.website_id=? AND r.source='dataforseo' AND r.volume IS NOT NULL AND r.fetched_at BETWEEN DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)",[$wid])[0]['n']??0);
        $add('research',$research['measured']&&!$research['invalid']?'measured':'action','السوق وبيانات الكلمات','Market and keyword demand','اختار بلد ولغة عملائك، وبعدها قيس الكلمات عشان تعرف عدد عمليات البحث الحقيقي.','/ai/keywords?website_id={site}',false,['counts'=>$research,'markets'=>$markets]);
        $add('rankings',$research['ranked']?'measured':'action','ترتيب الكلمات المقاس','Measured keyword rankings','افحص ترتيب كلماتك في جوجل. الكلمة اللي لسه مااتفحصتش مش معناها إنك مش ظاهر.','/ai/keywords?website_id={site}');
        $activeReports=$this->has('seo_report_schedules')?(int)($this->rows('SELECT COUNT(*) AS n FROM seo_report_schedules WHERE website_id=? AND user_id=? AND is_active=1',[$wid,$uid])[0]['n']??0):0;
        $add('reports',$activeReports?'scheduled':'action','التقرير الدوري للعميل','Scheduled customer reports','اختار الإيميل اللي هيستلم التقرير ووافق على الإرسال.','/seo-expert?website_id={site}&page=results');
        $impact=['collected'=>0,'waiting'=>0,'next_collection_at'=>null];if($this->has('seo_impact_monitors')){$impact=$this->rows("SELECT SUM(status='reported' AND result_json IS NOT NULL) AS collected,SUM(status IN ('queued','waiting_period','waiting_data')) AS waiting,MIN(CASE WHEN status IN ('queued','waiting_period','waiting_data') THEN COALESCE(next_due_at,TIMESTAMP(DATE_ADD(change_date,INTERVAL (window_days+3) DAY),'04:00:00')) END) AS next_collection_at FROM seo_impact_monitors WHERE website_id=? AND user_id=? AND status<>'superseded'",[$wid,$uid])[0]??$impact;}
        $add('impact',(int)$impact['collected']?'measured':((int)$impact['waiting']?'waiting':'action'),'أثر التعديلات بعد 7 و14 و28 يومًا','Change impact after 7, 14 and 28 days','بنقارن الزيارات قبل وبعد كل تعديل بعد 7 و14 و28 يوم.','/seo-expert?website_id={site}&page=results',false,['counts'=>$impact]);
        $pending=0;if($this->has('seo_expert_tasks'))$pending=(int)($this->rows("SELECT COUNT(*) AS n FROM seo_expert_tasks WHERE website_id=? AND user_id=? AND status='pending'",[$wid,$uid])[0]['n']??0);
        $add('plan',$pending?'action':'check','مراجعة الخطة قبل التنفيذ','Review the plan before execution','راجع التعديلات المقترحة ووافق على اللي يناسبك. مفيش حاجة بتتنشر من غير موافقتك.','/seo-expert?website_id={site}&page=plan',false,['pending_count'=>$pending]);
        return ['website_id'=>$wid,'observed_at'=>gmdate('c'),'basis'=>'stored_records','checks'=>$checks,'required_attention'=>count(array_filter($checks,static fn($c)=>$c['required']&&!in_array($c['state'],['recorded','observed'],true))),'note'=>'هذه قائمة تشغيل ومصادر أدلة، وليست درجة SEO أو اعتمادًا شاملًا لجاهزية البيع أو ضمان نتائج.'];
    }
}
