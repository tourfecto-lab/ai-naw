<?php
class InternationalSeoController extends Controller
{
    public function index(array $p=[]): void {
        if(!$this->isAuthenticated()){header('Location: /login');exit;}
        // الواجهة كلها بتترسم من international-seo.js بلغة العميل؛ هنا الحاويات بس
        $css=htmlspecialchars(asset_v('/assets/css/international-seo.css'),ENT_QUOTES,'UTF-8');$js=htmlspecialchars(asset_v('/assets/js/international-seo.js'),ENT_QUOTES,'UTF-8');
        $csrf=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
        $body='<link rel="stylesheet" href="'.$css.'"><section id="internationalSeo" data-csrf="'.$csrf.'" aria-live="polite"></section><script defer src="'.$js.'"></script>';
        echo $this->renderPanelPage('seo_expert',current_lang()==='ar'?'اللغات والفهرسة':'Languages and indexing','ترجمات معتمدة وفهرسة على دومين العميل',$body);exit;
    }
    private function action(string $op): array {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        header('Cache-Control: no-store, private');$uid=(int)$this->user['id'];
        if(!in_array($op,['sites','data','sitemapData'],true)){
            if($e=$this->verifyCsrf())return $e;if($e=$this->rateLimitGuard('user','international_seo_write',6,60))return $e;
        }
        try{
            if($op==='sites')return $this->success(['builders'=>$this->db->query('SELECT id,slug,status FROM generated_websites WHERE user_id=? ORDER BY id DESC',[$uid]),'sites'=>$this->db->query('SELECT id,main_url FROM websites WHERE user_id=? ORDER BY id DESC',[$uid])]);
            if(in_array($op,['sitemapData','submit','disable'],true)){
                $wid=(int)$this->get('website_id');(new SeoMarketService($this->db))->site($wid,$uid);
                if($op==='submit')return $this->success(['result'=>(new SitemapSubmissionService($this->db))->run($uid,$wid,(string)$this->get('sitemap_url'),true)]);
                if($op==='disable')$this->db->exec('UPDATE seo_sitemap_permissions SET enabled=0,updated_at=UTC_TIMESTAMP() WHERE user_id=? AND website_id=?',[$uid,$wid]);
                $row=$this->db->query('SELECT enabled,sitemap_url,result_json,checked_at FROM seo_sitemap_permissions WHERE website_id=? AND user_id=?',[$wid,$uid])[0]??null;
                return $this->success(['permission'=>$row,'result'=>$row?json_decode($row['result_json']??'',true):['state'=>'permission_required']]);
            }
            $id=(int)$this->get('generated_website_id');$site=(new GeneratedWebsite())->find($id);
            if(!$site||(int)$site->getAttribute('user_id')!==$uid)return $this->error('Website unavailable',404);
            $source=$site->getContent();$service=new BuilderLanguageService($this->db);
            if($op==='data')return $this->success(['source'=>$source,'source_hash'=>BuilderLanguageService::hash($source),'fields'=>BuilderLanguageService::fields($source),'versions'=>$service->versions($id,$source)]);
            if($this->get('confirmed',false)!==true)throw new InvalidArgumentException('Confirm the source content first');
            $language=strtolower(trim((string)$this->get('language')));$hash=(string)$this->get('source_hash');
            if($op==='generate')return $this->success(['fields'=>$service->generate($id,$uid,$source,$language,$hash)]);
            $fields=$this->get('fields');if(!is_array($fields))throw new InvalidArgumentException('Provide translated fields');
            $review=$op==='publish';if($review&&$this->get('reviewed',false)!==true)throw new InvalidArgumentException('Human review required before publishing');
            $service->save($id,$uid,$source,$language,$fields,$hash,$review);return $this->success(['status'=>$review?'published':'draft']);
        }catch(InvalidArgumentException|JsonException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){Logger::warning('International SEO unavailable',['operation'=>$op]);return $this->error('راجع ترحيل Phase 6 والربط ومزوّد الترجمة',503);}
    }
    public function sites(array $p=[]): array{return $this->action('sites');}
    public function data(array $p=[]): array{return $this->action('data');}
    public function generate(array $p=[]): array{return $this->action('generate');}
    public function draft(array $p=[]): array{return $this->action('draft');}
    public function publish(array $p=[]): array{return $this->action('publish');}
    public function sitemapData(array $p=[]): array{return $this->action('sitemapData');}
    public function submit(array $p=[]): array{return $this->action('submit');}
    public function disable(array $p=[]): array{return $this->action('disable');}
}
