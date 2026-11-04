<?php
final class AiVisibilityController extends Controller
{
    public function data(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($err=$this->verifyCsrf()))return $err;
        try{$uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');$svc=new AiVisibilityService($this->db);$action=$params['action']??'overview';
            $mid=(int)$this->get('market_id');
            $result=match($action){
                'generate'=>$this->generateDraft($svc,$uid,$wid,$mid),
                'question'=>$this->saveQuestion($svc,$uid,$wid,$mid,(int)$this->get('id'),trim((string)$this->get('question')),filter_var($this->get('approved',false),FILTER_VALIDATE_BOOLEAN)),
                'questions'=>$this->saveQuestions($svc,$uid,$wid,$mid,(array)$this->get('questions',[]),(int)$this->get('version'),filter_var($this->get('approved',false),FILTER_VALIDATE_BOOLEAN)),
                'identity'=>$this->saveIdentity($svc,$uid,$wid,(array)$this->get('aliases',[]),(array)$this->get('profile_urls',[])),
                default=>null
            };return $this->success(AiVisibilityPresentation::customer($this->view($svc,$uid,$wid,$mid)));
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){
            Logger::error('AI Visibility operation unavailable',[
                'action'=>$params['action']??'overview','website_id'=>(int)$this->get('website_id'),
                'user_id'=>(int)$this->user['id'],'exception'=>get_class($e),
                'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()
            ]);
            return $this->error('تعذر تحميل النتائج دلوقتي. جرّب مرة تانية أو تواصل مع الدعم.',503);
        }
    }
    /**
     * الصفحة بتشتغل بأسئلة مرقّمة وموافقة لكل سؤال، والخدمة بتخزّن مجموعة أسئلة واحدة لكل سوق
     * بموافقة واحدة. الدوال دي بتترجم بين الشكلين من غير ما تغيّر قواعد الخدمة (10–30 سؤال، النسخة، الهوية).
     */
    private function view(AiVisibilityService $svc,int $uid,int $wid,int $mid): array
    {
        $markets=[];foreach((new SeoMarketService($this->db))->markets($wid,$uid) as $m)$markets[]=['id'=>(int)$m['id'],'label'=>(string)($m['label']?:strtoupper((string)$m['language_code']).' · '.$m['location_code'])];
        if(!$mid&&$markets)$mid=$markets[0]['id'];
        $o=$svc->overview($uid,$wid,$mid);$marketId=(int)($o['market']['id']??$mid);
        $set=$o['prompt_set']??null;$questions=[];
        foreach(($set['questions']??[]) as $i=>$q)$questions[]=['id'=>$i+1,'market_id'=>$marketId,'question'=>(string)$q,'approved'=>!empty($set['approved_at'])?1:0];
        $metrics=[];$weeks=[];
        foreach(($o['metrics']['providers']??[]) as $provider=>$m){
            if(($m['state']??'')==='measured_sample')$metrics[]=['provider'=>$provider,'market_id'=>$marketId,'questions'=>$m['questions'],'citation_questions'=>$m['citation_questions'],'share_of_voice'=>$m['share_of_voice'],'citation_rate'=>$m['citation_rate'],'competitors'=>$m['top_competitors']];
            foreach(($m['trend']??[]) as $t)if($t['questions']>0)$weeks[$t['week']][]=['provider'=>$provider,'market_id'=>$marketId,'questions'=>$t['questions'],'share_of_voice'=>$t['share_of_voice'],'citation_rate'=>$t['citation_rate']];
        }
        $trend=[];ksort($weeks);foreach($weeks as $week=>$providers)$trend[]=['week'=>$week,'providers'=>$providers];
        $answers=array_map(fn($a)=>$a+['market_id'=>$marketId],$o['answers']??[]);
        $entity=$o['entities']??null;
        $brand='';try{$brand=(string)($svc->context($uid,$wid,$marketId)['brand']['name']??'');}catch(Throwable $e){error_log('[AiVisibilityController] ignored error: ' . $e->getMessage());}
        return ['state'=>$o['state']??'not_measured','markets'=>$markets,'questions'=>$questions,'metrics'=>$metrics,'trend'=>$trend,'answers'=>$answers,'tasks'=>$o['tasks']??[],
            'providers'=>$o['providers']??[],'aliases'=>array_values(array_merge([$brand],(array)($entity['aliases']??[]))),'profile_urls'=>array_values((array)($entity['profiles']??[])),'position_basis'=>$o['metrics']['position_basis']??null,
            'question_sets'=>$set?[['market_id'=>$marketId,'version'=>(int)($set['version']??0),'approved'=>!empty($set['approved_at'])?1:0]]:[]];
    }
    private function setVersion(AiVisibilityService $svc,int $uid,int $wid,int $mid): array
    {
        $market=(new SeoMarketService($this->db))->resolve($wid,$uid,$mid);$set=$svc->set($uid,$wid,(int)$market['id']);
        return [(int)$market['id'],$set,$set?(int)$set['version']:0];
    }
    private function generateDraft(AiVisibilityService $svc,int $uid,int $wid,int $mid): array
    {
        [$mid,,$version]=$this->setVersion($svc,$uid,$wid,$mid);$draft=$svc->generate($uid,$wid,$mid);
        // المسودة بتتحفظ من غير موافقة: الأسئلة مش بتدخل القياس لحد ما العميل يراجعها ويوافق.
        return $svc->save($uid,$wid,$mid,$draft['questions'],$version,false);
    }
    private function saveQuestion(AiVisibilityService $svc,int $uid,int $wid,int $mid,int $id,string $text,bool $approve): array
    {
        [$mid,$set,$version]=$this->setVersion($svc,$uid,$wid,$mid);$questions=array_values($set['questions']??[]);
        if($id<1||$id>count($questions))throw new InvalidArgumentException('السؤال مش موجود؛ حدّث الصفحة');
        $questions[$id-1]=$text;
        // الموافقة على مستوى السوق: القياس الأسبوعي بيستخدم المجموعة المعتمدة كلها.
        return $svc->save($uid,$wid,$mid,$questions,$version,$approve);
    }
    /** الصفحة بتحفظ الأسئلة كلها مرة واحدة (كانت بتبعت لمسار مش موجود فالموافقة عمرها ما اتحفظت) */
    private function saveQuestions(AiVisibilityService $svc,int $uid,int $wid,int $mid,array $questions,int $version,bool $approve): array
    {
        $mid=(int)(new SeoMarketService($this->db))->resolve($wid,$uid,$mid)['id'];
        $questions=array_values(array_filter(array_map(static fn($q)=>is_string($q)?trim($q):'',$questions),'strlen'));
        return $svc->save($uid,$wid,$mid,$questions,$version,$approve);
    }
    private function saveIdentity(AiVisibilityService $svc,int $uid,int $wid,array $aliases,array $profiles): array
    {
        $row=$this->db->query('SELECT version,address FROM seo_ai_entities WHERE website_id=? AND user_id=?',[$wid,$uid])[0]??null;
        $aliases=array_values(array_filter(array_map(fn($a)=>trim((string)$a),$aliases),'strlen'));$profiles=array_values(array_filter(array_map(fn($u)=>trim((string)$u),$profiles),'strlen'));
        return $svc->entities($uid,$wid,$aliases,$profiles,(string)($row['address']??''),$row?(int)$row['version']:0,true);
    }
    public function generate(array $params=[]): array {return $this->data(['action'=>'generate']);}
    public function question(array $params=[]): array {return $this->data(['action'=>'question']);}
    public function identity(array $params=[]): array {return $this->data(['action'=>'identity']);}
    public function questions(array $params=[]): array {return $this->data(['action'=>'questions']);}
    public function llms(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($err=$this->verifyCsrf())return $err;
        if($err=$this->rateLimitGuard('user','ai_visibility_llms',20,3600))return $err;
        try{$site=(new SeoMarketService($this->db))->site((int)$this->get('website_id'),(int)$this->user['id']);$ctx=(new SeoExpertPageContext())->fresh($site['main_url']);if(empty($ctx['ok']))throw new RuntimeException('page_unavailable');return $this->success(['draft'=>(new GeoLlmsBuilder())->build($site['main_url']),'status'=>'draft_requires_review','note'=>'راجع البيانات؛ النشر من خلال دورة الموصل الحالية مع موافقة وتحقق وإمكانية رجوع. مفيش ضمان ترتيب.']);}catch(RuntimeException $e){return $this->error($e->getMessage()==='page_unavailable'?'تعذر قراءة الصفحات العامة':$e->getMessage(),422);}catch(Throwable $e){return $this->error('تعذر قراءة الصفحات العامة',422);}
    }
    /** Compatibility with deployed web routes that still dispatch to index. */
    public function index(array $params=[]): array {return $this->page($params);}
    public function page(array $params=[]): array {if(!$this->isAuthenticated())return $this->error('Unauthorized',401);ob_start();require dirname(__DIR__).'/Views/AiVisibility/index.php';$body=ob_get_clean();echo $this->renderPanelPage('ai_analyze',current_lang()==='ar'?'ظهورك في الذكاء الاصطناعي':'Your visibility in AI','قياسات فعلية للأسئلة والمصادر، بدون تقديرات',$body);exit;}
}
