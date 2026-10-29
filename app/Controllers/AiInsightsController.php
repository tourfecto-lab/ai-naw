<?php
/** AI insights: cited sources, brand sentiment and AI crawler visits, built on the AI visibility measurements. */
final class AiInsightsController extends Controller
{
    private const CLAUDE_MODELS=['claude-opus-5-5','claude-sonnet-5-5','claude-haiku-5-5'];
    private function isAdmin(): bool{return in_array($this->user['role']??'user',['admin','super_admin'],true);}
    private function fail(Throwable $e,string $action): array
    {
        if ($e instanceof InvalidArgumentException) return $this->error($e->getMessage(),422);
        Logger::error('AI insights operation unavailable',['action'=>$action,'user_id'=>(int)($this->user['id']??0),'exception'=>get_class($e),'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
        return $this->error('تعذر تنفيذ الطلب دلوقتي. جرّب مرة تانية أو تواصل مع الدعم.',503);
    }
    private function claudeStatus(): array
    {
        $s=new SystemSettingsService();
        // The installer registers Claude only when the server's provider base class matches; never load it otherwise.
        $registered=str_contains((string)@file_get_contents(dirname(__DIR__).'/Services/AiVisibility/AiVisibilityService.php'),'new ClaudeVisibilityProvider(');
        $p=$registered?new ClaudeVisibilityProvider():null;
        return ['registered'=>$registered,'enabled'=>$s->get('geo_claude_enabled')==='1','measuring'=>$p?$p->configured():false,'model'=>$p?$p->model():($s->get('geo_claude_model')?:'claude-opus-5-5'),'key_present'=>AiServiceRouting::apiKey('anthropic')!=='','geo_enabled'=>$s->get('geo_enabled')==='1'];
    }
    public function data(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{
            $uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');$mid=(int)$this->get('market_id');$provider=(string)$this->get('provider','');
            if(!in_array($provider,['','openai','gemini','perplexity','claude'],true))$provider='';
            AiInsightsSchema::ensureSchema($this->db);
            $markets=(new SeoMarketService($this->db))->markets($wid,$uid);
            $sentiment=['totals'=>[],'by_provider'=>[],'recent'=>[],'pending'=>0];
            try{$sentiment=(new AiSentimentService($this->db))->summary($uid,$wid,$mid);}catch(InvalidArgumentException $e){error_log('[AiInsightsController] ignored error: ' . $e->getMessage());}
            $out=['markets'=>array_map(fn($m)=>['id'=>(int)$m['id'],'label'=>(string)($m['label']??'')],$markets),
                'sources'=>(new AiCitationSourcesService($this->db))->forSite($uid,$wid,$mid,$provider),'sentiment'=>$sentiment,
                'crawlers'=>(new AiCrawlerService($this->db))->summary($uid,$wid),'is_admin'=>$this->isAdmin()];
            if($this->isAdmin())$out['claude']=$this->claudeStatus()+['models'=>self::CLAUDE_MODELS];
            return $this->success($out);
        }catch(Throwable $e){return $this->fail($e,'data');}
    }
    public function sentiment(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($err=$this->verifyCsrf())return $err;
        if($err=$this->rateLimitGuard('user','ai',10,60))return $err;
        try{return $this->success((new AiSentimentService($this->db))->analyzePending((int)$this->user['id'],(int)$this->get('website_id'),10));}
        catch(Throwable $e){return $this->fail($e,'sentiment');}
    }
    public function crawlerToken(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($err=$this->verifyCsrf())return $err;
        try{
            $svc=new AiCrawlerService($this->db);$token=$svc->rotateToken((int)$this->user['id'],(int)$this->get('website_id'));
            $endpoint=rtrim(defined('APP_URL')?APP_URL:'https://tourfecto.pro','/').'/api/public/ai-crawlers/ingest';
            return $this->success(['snippet'=>AiCrawlerService::snippet($token,$endpoint),'note'=>current_lang()==='ar'?'الكود ده بيظهر مرة واحدة. لو عملت كود جديد، القديم بيبطل يشتغل.':'This snippet is shown once. Creating a new one disables the old one.']);
        }catch(Throwable $e){return $this->fail($e,'crawler_token');}
    }
    public function crawlerLog(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($err=$this->verifyCsrf())return $err;
        if($err=$this->rateLimitGuard('user','ai_crawler_log',6,300))return $err;
        $f=$_FILES['log']??null;
        if(!$f||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))return $this->error('اختار ملف السجل الأول',422);
        try{return $this->success((new AiCrawlerService($this->db))->importLog((int)$this->user['id'],(int)$this->get('website_id'),$f['tmp_name']));}
        catch(Throwable $e){return $this->fail($e,'crawler_log');}
    }
    /** Public, token-authenticated hit from the customer's own server. Always answers fast and small. */
    public function ingest(array $params=[]): array
    {
        if($err=$this->rateLimitGuard('ip','ai_crawler_ingest',600,60))return $err;
        $token=(string)($_SERVER['HTTP_X_TOURFECTO_TOKEN']??'');
        try{$ok=(new AiCrawlerService($this->db))->ingest($token,(string)$this->get('ua',''),(string)$this->get('path','/'));}
        catch(Throwable $e){Logger::error('AI crawler ingest failed',['error'=>$e->getMessage()]);return $this->error('unavailable',503);}
        return $ok?$this->success([]):$this->error('invalid token',401);
    }
    public function claudeSettings(array $params=[]): array
    {
        if(!$this->isAuthenticated()||!$this->isAdmin())return $this->error('Forbidden',403);
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if($err=$this->verifyCsrf())return $err;
            $enabled=(string)$this->get('enabled');$model=(string)$this->get('model');
            if(!in_array($enabled,['0','1'],true)||!in_array($model,self::CLAUDE_MODELS,true))return $this->error('قيمة غير صالحة',422);
            $s=new SystemSettingsService();$s->set('geo_claude_enabled',$enabled);$s->set('geo_claude_model',$model);
        }
        return $this->success($this->claudeStatus()+['models'=>self::CLAUDE_MODELS]);
    }
    public function index(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        ob_start();require dirname(__DIR__).'/Views/AiInsights/index.php';$body=ob_get_clean();
        $ar=current_lang()==='ar';
        echo $this->renderPanelPage('ai_insights',$ar?'مصادر وانطباع الذكاء الاصطناعي':'AI sources and sentiment',$ar?'المواقع اللي محركات الـAI بتستشهد بيها، وإزاي بتوصف شركتك، ومين من بوتاتها بيزور موقعك':'The sites AI engines cite, how they describe your company, and which AI crawlers visit your site',$body);return [];
    }
}
