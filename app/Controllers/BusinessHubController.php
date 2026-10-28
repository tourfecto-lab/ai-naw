<?php
require_once __DIR__.'/../Services/BusinessHub/BusinessHubService.php';
require_once __DIR__.'/../Services/BusinessHub/SocialRecurringService.php';
require_once __DIR__.'/../Services/SeoExpert/SeoExpertService.php';
class BusinessHubController extends Controller
{
    public function index(array $p=[]): void{if(!$this->isAuthenticated()){header('Location: /login');exit;}$css=htmlspecialchars(asset_v('/assets/css/business-hub.css'),ENT_QUOTES,'UTF-8');$js=htmlspecialchars(asset_v('/assets/js/business-hub.js'),ENT_QUOTES,'UTF-8');echo $this->renderPanelPage('business_hub',(current_lang()==='ar'?'بيانات الشركة':'Company details'),(current_lang()==='ar'?'معلومات شركتك والربط والخطط - المنصة بتعتمد عليها في كل الخدمات':'Your company facts, connections and plans - every service uses them'),'<link rel="stylesheet" href="'.$css.'"><section id="businessHubRoot">'.(current_lang()==='ar'?'جارٍ التحميل…':'Loading…').'</section><script defer src="'.$js.'"></script>');exit;}
    private function action(string $action,array $p): array{
        header('Cache-Control: no-store, private');if(!$this->isAuthenticated())return $this->error('Unauthorized',401);if(in_array($action,['save','import','recurring'],true)){if($e=$this->verifyCsrf())return $e;if($e=$this->rateLimitGuard('user','business_hub_write',10,60))return $e;}
        try{$uid=(int)$this->user['id'];$wid=(int)($p['website_id']??0);$s=new BusinessHubService();if($action==='setup')return $this->success($s->setup($uid));if($action==='profile')return $this->success(['profile'=>$s->profile($uid,$wid)]);if($action==='recurring')return $this->success((new SocialRecurringService())->configure($uid,$wid,$this->data));if($action==='save')return $this->success(['profile'=>$s->save($uid,$wid,$this->data)]);if($action==='import'&&$this->get('mode','')==='competitors'){require_once __DIR__.'/../Services/BusinessHub/CompetitorBlueprintService.php';return $this->success((new CompetitorBlueprintService())->scan($uid,$wid,$this->data));}if($action==='import')return $this->success((new SeoExpertService())->importBrief($uid,$wid,(string)$this->get('language','en')));return $this->success($s->dashboard($uid,$wid));}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){Logger::error('Business Hub unavailable',['action'=>$action,'error'=>$e->getMessage()]);return $this->error('تعذر تحميل مركز الشركة؛ راجع تركيب التحديث / Business Hub unavailable',503);}
    }
    public function recurring(array $p=[]): array{return $this->action('recurring',$p);}
    public function setup(array $p=[]): array{return $this->action('setup',$p);}public function dashboard(array $p=[]): array{return $this->action('dashboard',$p);}public function profile(array $p=[]): array{return $this->action('profile',$p);}public function save(array $p=[]): array{return $this->action('save',$p);}public function import(array $p=[]): array{return $this->action('import',$p);}
}
