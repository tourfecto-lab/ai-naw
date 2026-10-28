<?php
require_once __DIR__.'/../Services/SeoExpert/SeoExpertService.php';
require_once __DIR__.'/../Jobs/SeoExpertCycleJob.php';
require_once __DIR__.'/../Jobs/SeoExpertExecuteJob.php';
class SeoExpertController extends Controller
{
    /** رسايل الخدمة مكتوبة "English / عربي"؛ العميل يشوف لغة واجهته بس */
    protected function success(array $data=[],string $message='Success',int $code=200): array{return parent::success(self::oneLanguage($data),self::oneLanguage($message),$code);}
    protected function error(string $error,int $code=400,array $details=[]): array{return parent::error(self::oneLanguage($error),$code,self::oneLanguage($details));}
    private static function oneLanguage($value)
    {
        if(is_array($value)){foreach($value as $k=>$v)$value[$k]=self::oneLanguage($v);return $value;}
        if(!is_string($value)||!str_contains($value,' / ')||!preg_match('/^(.*?[A-Za-z][^\x{0600}-\x{06FF}]*?) \/ ([\x{0600}-\x{06FF}].*)$/su',$value,$m))return $value;
        // الجزء الإنجليزي ممكن يكمل بعد العربي (مثلًا "... / ... Review the draft topic: X")
        $ar=current_lang()==='ar';$arabic=$m[2];$tail='';
        if(preg_match('/^(.*[\x{0600}-\x{06FF}][^A-Za-z]*?)\s+([A-Z][A-Za-z].*)$/su',$arabic,$t)){$arabic=$t[1];$tail=' '.$t[2];}
        return $ar?trim($arabic).$tail:trim($m[1]).$tail;
    }
    public function index(array $params=[]): void{
        if(!$this->isAuthenticated()){header('Location: /login');exit;}
        $css=htmlspecialchars(asset_v('/assets/css/seo-expert.css'),ENT_QUOTES,'UTF-8');$js=htmlspecialchars(asset_v('/assets/js/seo-expert.js'),ENT_QUOTES,'UTF-8');$editorJs=htmlspecialchars(asset_v('/assets/js/seo-page-editor.js'),ENT_QUOTES,'UTF-8');$impactJs=htmlspecialchars(asset_v('/assets/js/seo-impact.js'),ENT_QUOTES,'UTF-8');$workspaceJs=htmlspecialchars(asset_v('/assets/js/seo-workspace.js'),ENT_QUOTES,'UTF-8');$workspaceCss=htmlspecialchars(asset_v('/assets/css/seo-workspace.css'),ENT_QUOTES,'UTF-8');$uid=(int)$this->user['id'];
        $readinessJs=htmlspecialchars(asset_v('/assets/js/seo-service-readiness.js'),ENT_QUOTES,'UTF-8');
        $guideJs=htmlspecialchars(asset_v('/assets/js/seo-guide.js'),ENT_QUOTES,'UTF-8');$guideCss=htmlspecialchars(asset_v('/assets/css/seo-guide.css'),ENT_QUOTES,'UTF-8');
        $body='<link rel="stylesheet" href="'.$css.'"><link rel="stylesheet" href="'.$workspaceCss.'"><section id="seoExpertRoot" data-user-id="'.$uid.'"><p>Loading SEO Expert… / جارٍ تحميل خبير SEO…</p></section><script defer src="'.$js.'"></script><script defer src="'.$editorJs.'"></script><script defer src="'.$impactJs.'"></script><script defer src="'.$workspaceJs.'"></script><script defer src="'.$readinessJs.'"></script>';
        $links=['ar'=>['اللغات والفهرسة','البحث المحلي والروابط والتقرير الشهري'],'fr'=>['Langues et indexation','SEO local, liens et rapport mensuel'],'de'=>['Sprachen und Indexierung','Lokales SEO, Links und Monatsbericht']][current_lang()]??['Languages and indexing','Local search, links and monthly report'];
        // الملخص البسيط هو أول حاجة العميل بيشوفها؛ صفحات الخبير بتتفتح من تبويبات مركز السيو (?page=)
        $body='<link rel="stylesheet" href="'.$guideCss.'"><section id="seoGuideRoot" aria-live="polite" data-email="'.htmlspecialchars((string)($this->user['email']??''),ENT_QUOTES,'UTF-8').'"></section><div id="seoAdvanced" hidden><p><a href="/international-seo">'.$links[0].'</a> · <a href="/local-seo">'.$links[1].'</a></p>'.$body.'</div><script defer src="'.$guideJs.'"></script>';
        $subtitle=['ar'=>'خطوات بسيطة، والمنصة بتكمل الباقي لوحدها','fr'=>'Quelques étapes simples, la plateforme fait le reste','de'=>'Ein paar einfache Schritte, den Rest erledigt die Plattform'][current_lang()]??'A few simple steps, then the platform does the rest';
        echo $this->renderPanelPage('seo_expert',current_lang()==='ar'?'مركز السيو':'SEO hub',$subtitle,$body);exit;
    }
    private function action(string $action,array $params=[]): array{
        header('Cache-Control: no-store, private');header('Pragma: no-cache');
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);$write=!in_array($action,['setup','overview','detail','readiness'],true);if($write&&($e=$this->verifyCsrf()))return $e;
        if($write&&($e=$this->rateLimitGuard('user','seo_expert_write',20,60)))return $e;
        $uid=(int)$this->user['id'];$wid=(int)($params['website_id']??$this->get('website_id',0));$id=(int)($params['id']??0);$s=new SeoExpertService();
        try{
            if($action==='setup'){$r=$s->setup($uid);$r['csrf_token']=Csrf::token();return $this->success($r);}
            if($action==='overview')return $this->success($s->overview($uid,$wid));
            if($action==='readiness')return $this->success((new SeoServiceReadiness($this->db))->inspect($uid,$wid));
            if($action==='recheck')return $this->success($s->recheck($uid,$id));
            if($action==='detail')return $this->success($s->detail($uid,$id));
            if($action==='configure')return $this->success($s->configure($uid,$wid,$this->data));
            if($action==='importBrief')return $this->success($s->importBrief($uid,$wid,(string)$this->get('language','en')));
            if($action==='probe')return $this->success($s->probe($uid,$wid));
            if($action==='reviewScope')return $this->success(($this->data['operation']??'')==='recover_crawl'?$s->recoverCrawl($uid,$id,$this->data):$s->reviewScope($uid,$id,(int)($this->data['version']??0)));
            if($action==='start')return $this->success($s->start($uid,$wid,$this->data),'Cycle queued',201);
            if($action==='approve')return $this->success($s->approve($uid,$id,$this->data));
            if($action==='edit')return $this->success($s->editTask($uid,$id,$this->data));
            if($action==='done'){
                if(isset($this->data['review_decision']))return $this->success($s->reviewDecision($uid,$id,$this->data));
                return $this->success((($this->data['connected_check']??false)===true?$s->recheckConnectedTask($uid,$id,$this->data):$s->clientDone($uid,$id,(string)$this->get('evidence',''))));
            }
            if($action==='rollback')return $this->success($s->rollback($uid,$id));
            return $this->success($s->state($uid,$wid,$action));
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){Logger::error('SEO Expert operation unavailable',['action'=>$action,'website_id'=>$wid,'user_id'=>$uid,'error'=>$e->getMessage()]);return $this->error('تعذر تنفيذ الطلب. راجع الربط وSQL ومهام السيرفر ثم حدّث الحالة / Could not complete the request. Check connections, migration and worker, then refresh status.',503);}
    }
    public function setup(array $p=[]): array{return $this->action('setup',$p);}
    public function overview(array $p=[]): array{return $this->action('overview',$p);}
    public function readiness(array $p=[]): array{return $this->action('readiness',$p);}
    public function recheck(array $p=[]): array{return $this->action('recheck',$p);}
    public function detail(array $p=[]): array{return $this->action('detail',$p);}
    public function configure(array $p=[]): array{return $this->action('configure',$p);}
    public function importBrief(array $p=[]): array{return $this->action('importBrief',$p);}
    public function probe(array $p=[]): array{return $this->action('probe',$p);}
    public function reviewScope(array $p=[]): array{return $this->action('reviewScope',$p);}
    public function start(array $p=[]): array{return $this->action('start',$p);}
    public function approve(array $p=[]): array{return $this->action('approve',$p);}
    public function edit(array $p=[]): array{return $this->action('edit',$p);}
    public function done(array $p=[]): array{return $this->action('done',$p);}
    public function rollback(array $p=[]): array{return $this->action('rollback',$p);}
    public function pause(array $p=[]): array{return $this->action('pause',$p);}
    public function resume(array $p=[]): array{return $this->action('resume',$p);}
}
