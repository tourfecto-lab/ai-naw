<?php
class InternationalSeoController extends Controller
{
    public function index(array $p=[]): void {
        if(!$this->isAuthenticated()){header('Location: /login');exit;}
        $body=<<<'HTML'
<section id="internationalSeo"><h2>اللغات والفهرسة</h2><p><a href="/website-builder">منشئ المواقع</a> · الترجمات مسودات أولًا. أكّد صحة المحتوى الأصلي، راجع كل النصوص والادعاءات ثم انشر. الأرقام والأسعار وبيانات التواصل لا تُترجم آليًا. تغيير الأصل يتطلب مراجعة الترجمة مجددًا.</p>
<label>موقع المنشئ <select id="intlBuilder"></select></label><button id="intlLoad">تحميل المحتوى</button>
<label>اللغة <input id="intlLanguage" value="de" maxlength="20" pattern="[a-z]{2,3}(-[a-z0-9]{2,8})?"></label><label><input type="checkbox" id="intlConfirmed"> أؤكد صحة بيانات وأسعار وادعاءات المحتوى الأصلي المعروض</label>
<details><summary>المحتوى الأصلي للمقارنة</summary><pre id="intlSource" style="white-space:pre-wrap"></pre></details>
<p>توليد المسودة يستهلك حصة توليد موقع أو سعرها الحالي من المحفظة. يمكنك أيضًا كتابة الترجمة يدويًا بلا طلب توليد.</p><button id="intlGenerate">توليد مسودة ترجمة مدفوعة</button>
<label>حقول الترجمة JSON، حافظ على أسماء المفاتيح والأرقام<textarea id="intlFields" rows="15" dir="ltr" style="width:100%"></textarea></label>
<label><input type="checkbox" id="intlReviewed"> راجعت الترجمة كاملة وأؤكد أنها تعكس الحقائق والأسعار والخدمات الأصلية دون إضافات</label><button id="intlDraft">حفظ مسودة</button><button id="intlPublish">اعتماد ونشر اللغة</button><pre id="intlVersions" style="white-space:pre-wrap"></pre>
<h3>Search Console: السماح بإرسال خريطة الموقع</h3><p>اختياري: الربط المعتاد للقراءة فقط. إعادة الربط هنا تطلب webmasters من Google للسماح بإرسال الخرائط. اختر الموقع المؤكد نفسه بعد موافقة Google. لا يتم اعتبار إرسال الخريطة ضمانًا للفهرسة.</p>
<label>الموقع <select id="intlSite"></select></label><form id="intlReconnect" method="post"><input type="hidden" name="csrf_token" id="intlCsrf"><input type="hidden" name="return_to" value="/international-seo"><label><input type="checkbox" name="allow_sitemap_submit" value="1" required> Allow sitemap submit — أوافق على إعادة الربط بصلاحية إرسال الخرائط</label><button>إعادة الربط الاختياري مع Google</button></form>
<label>خريطة HTTPS على دومين موقعك <input id="intlSitemap" type="url" style="width:100%"></label><button id="intlSubmit">إرسال الخريطة بعد الموافقة</button><button id="intlSitemapLoad">عرض نتيجة الإرسال والأخطاء</button><button id="intlDisable">إيقاف الإرسال التلقائي</button><pre id="intlSitemapResult" style="white-space:pre-wrap"></pre><p id="intlStatus" role="status"></p></section>
<script defer src="/assets/js/international-seo.js"></script>
HTML;
        echo $this->renderPanelPage('seo_expert','International SEO','ترجمات معتمدة وفهرسة على دومين العميل',$body);exit;
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
