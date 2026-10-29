<?php
class LocalSeoController extends Controller
{
    public function index(array $p=[]): void {
        if(!$this->isAuthenticated()){header('Location: /login');exit;}
        $ar=current_lang()==='ar';
        $body='<section id="localSeo" dir="'.($ar?'rtl':'ltr').'"><h2>ظهور نشاطك وبياناته على الإنترنت</h2>
<p>راجع بيانات شركتك مرة واحدة، والمنصة هتقارنها بموقعك وبالأدلة اللي تختارها وتوريك أي اختلاف.</p>
<div class="local-toolbar"><label>الموقع <select id="localSite"></select></label><button id="localLoad">تحديث النتائج</button></div>
<p id="localStatus" role="status" aria-live="polite"></p>
<section class="local-step"><h3>1. راجع بيانات نشاطك</h3><p>هنقرا ملف Google Business المربوط بالموقع ده ونعرض بياناته هنا. التأكيد مجاني ومش بيبدأ أي قياس خرائط مدفوع.</p>
<div id="localProfilePreview" role="status" aria-live="polite"></div><a id="localConnect" href="/reputation/platforms" hidden>ربط Google Business أو مراجعة صلاحياته</a>
<label>روابط صفحات شركتك في الأدلة — اختياري<textarea id="localDirectories" rows="3" placeholder="https://directory.example/your-business"></textarea></label>
<label class="local-check"><input id="localConfirm" type="checkbox"> راجعت البيانات دي، وملف Google Business وروابط الأدلة تبع شركتي</label>
<button id="localProfile">تأكيد البيانات وفحصها</button><button id="localNap">إعادة مقارنة البيانات المحفوظة</button>
<p class="local-help">بعد ما تأكد، المنصة بتحفظ البيانات وتقارنها لوحدها. لو الفحص ماكملش، تقدر تعيد المقارنة من غير ما تكتب البيانات تاني.</p></section>
<section class="local-step"><h3>2. راجع نتيجة المقارنة</h3><p>هتشوف إيه اللي مختلف، والقيمة في Google قصاد القيمة في كل مصدر. اللي ماقدرناش نقراه بيفضل غير معروف.</p>
<div id="localComparison" aria-live="polite"></div><div id="localTasks"></div></section>
<section class="local-step"><h3>3. قياس ترتيب الخرائط — اختياري ومدفوع</h3><p>ده قياس إضافي حوالين مكان نشاطك المؤكد، ومش شرط لمقارنة البيانات. شبكة 5 في 5 لكلمتين أو تلاتة بتستخدم من 50 لـ 75 طلب قياس من حصة السيو في باقتك وبعدها من رصيدك. حد الشبكات الشهري مشترك بين كل مواقع حسابك.</p>
<p id="localMapsReady" class="local-help"></p><label>الكلمات اللي عملاءك بيدوّروا بيها — كلمتين أو تلاتة، كل كلمة في سطر<textarea id="localKeywords" rows="3"></textarea></label>
<details><summary>إعدادات نطاق القياس</summary><label>المسافة بين النقاط بالكيلومتر <input id="localSpacing" type="number" min="0.25" max="5" step="0.25" value="1"></label></details>
<label class="local-check"><input id="localPaid" type="checkbox"> موافق على استخدام لحد 75 طلب قياس مدفوع؛ وعارف إن عدم الظهور في نقطة مش معناه ترتيب صفر</label><button id="localRun">بدء قياس الخرائط المعتمد</button>
<label>السجل الشهري <select id="localHistory"></select></label><div id="localGrid"></div></section>
<section class="local-step"><h3>أدوات إضافية</h3><p>بحث فجوة الروابط بيستخدم مزوّد مدفوع لو مفيش نتايج حديثة محفوظة، وتشغيله اختياري.</p><button id="localLinks">بحث فجوة الروابط الحقيقي — مدفوع</button><div id="localLinkGap"></div>
<h3>تقريرك الشهري</h3><p>افتح التقرير وشوف البيانات وحالة الشغل والخطوات الجاية، أو احفظه PDF من نافذة الطباعة. عشان يوصلك بالإيميل فعّله من مركز السيو.</p><button id="localReport">افتح التقرير أو احفظه PDF</button></section></section><script defer src="'.htmlspecialchars(asset_v('/assets/js/local-seo.js'),ENT_QUOTES,'UTF-8').'"></script>';
        $css='<link rel="stylesheet" href="'.htmlspecialchars(asset_v('/assets/css/local-seo.css'),ENT_QUOTES,'UTF-8').'">';
        echo $this->renderPanelPage('seo_expert',$ar?'البحث المحلي والخرائط':'Local search and maps','بيانات واضحة، فحص تلقائي، ونتائج للمراجعة',$css.$body);exit;
    }
    private function action(string $op): array {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);header('Cache-Control: no-store, private');
        if(!in_array($op,['sites','data'],true)){if($e=$this->verifyCsrf())return $e;if($e=$this->rateLimitGuard('user','local_seo_write',10,60))return $e;}
        $uid=(int)$this->user['id'];$wid=(int)$this->get('website_id',0);
        try{
            if($op==='sites')return $this->success(['sites'=>$this->db->query('SELECT id,main_url FROM websites WHERE user_id=? ORDER BY id DESC',[$uid])]);
            $site=(new SeoMarketService($this->db))->site($wid,$uid);
            if($op==='data'){
                $row=$this->db->query('SELECT profile_json FROM seo_local_profiles WHERE website_id=? AND user_id=?',[$wid,$uid])[0]??null;
                $profile=$row?json_decode($row['profile_json'],true):null;$mapsReady=false;
                if(is_array($profile)){try{LocalRankGridService::gridForProfile($profile);$mapsReady=true;}catch(InvalidArgumentException $e){error_log('[LocalSeoController] ignored error: ' . $e->getMessage());}}
                $publicProfile=is_array($profile)?array_intersect_key($profile,array_flip(['name','phone','address','website','directory_profiles','confirmed_at'])):null;
                return $this->success(['profile'=>$publicProfile,'maps_grid_ready'=>$mapsReady,'runs'=>(new LocalRankGridService($this->db))->history($uid,$wid),'tasks'=>$this->db->query("SELECT source_url,evidence_json,status FROM seo_citation_tasks WHERE website_id=? AND user_id=? AND status='pending' ORDER BY id DESC LIMIT 50",[$wid,$uid])]);
            }
            if($op==='profile'){
                if($this->get('confirmed',false)!==true)throw new InvalidArgumentException('Confirm the business location and profiles');
                $connection=(new GbpSyncService())->findConnection($wid,$uid);
                // Review sync failures do not prove that profile access was revoked.
                // Retry recoverable records; token validation and the live Google read
                // below must both succeed before any customer facts are saved.
                if(!$connection||!in_array($connection->getAttribute('status'),['connected','error'],true))throw new InvalidArgumentException('Connect Google Business first');
                $token=(new GoogleReviewSyncService())->getValidAccessToken($connection);
                $r=(new GoogleBusinessAPI($token,$connection->getAttribute('external_account_id'),$connection->getAttribute('external_location_id')))->getLocation();
                if(empty($r['success']))throw new RuntimeException('GBP profile unavailable');$profile=$r['location'];
                // Name/phone/address checks do not require a customer-facing Maps pin.
                // A rank grid still validates coordinates separately before any paid work.
                try{LocalRankGridService::gridForProfile($profile);$mapsReady=true;}catch(InvalidArgumentException $e){$mapsReady=false;}
                $query=[];parse_str((string)parse_url($profile['maps_uri']??'',PHP_URL_QUERY),$query);$profile['cid']=isset($query['cid'])&&preg_match('/^[0-9]+$/D',(string)$query['cid'])?(string)$query['cid']:null;
                $dirs=$this->get('directory_profiles',[]);if(!is_array($dirs)||count($dirs)>15)throw new InvalidArgumentException('Maximum fifteen directory profiles');
                foreach($dirs as $url)if(!is_string($url)||strlen($url)>1800||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_PASS))throw new InvalidArgumentException('Use public HTTPS directory profile URLs without credentials');
                $profile['directory_profiles']=array_values(array_unique($dirs));$profile['confirmed_at']=gmdate('c');
                $this->db->exec('REPLACE INTO seo_local_profiles(website_id,user_id,profile_json,updated_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$wid,$uid,json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                return $this->success(['profile'=>$profile,'maps_grid_ready'=>$mapsReady]);
            }
            if($op==='run'){
                if($this->get('paid_confirmed',false)!==true)throw new InvalidArgumentException('Confirm paid Maps measurement first');
                $keywords=$this->get('keywords',[]);if(!is_array($keywords))throw new InvalidArgumentException('Provide keywords');
                return $this->success((new LocalRankGridService($this->db))->start($uid,$wid,$keywords,(float)$this->get('spacing_km',1)));
            }
            if($op==='nap')return $this->success((new NapConsistencyService($this->db))->check($uid,$wid));
            if($op==='links')return $this->success((new DataForSeoLinkGapService($this->db))->discover($uid,$wid));
            return $this->error('Unsupported operation',422);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){Logger::warning('Local SEO unavailable',['operation'=>$op,'website_id'=>$wid]);return $this->error('راجع ترحيل Phase 5 والربط والمزوّد وسقف الإنفاق',503);}
    }
    public function sites(array $p=[]): array{return $this->action('sites');} public function data(array $p=[]): array{return $this->action('data');}
    public function profile(array $p=[]): array{return $this->action('profile');} public function run(array $p=[]): array{return $this->action('run');}
    public function nap(array $p=[]): array{return $this->action('nap');} public function links(array $p=[]): array{return $this->action('links');}
    public function report(array $p=[]): void {
        if(!$this->isAuthenticated()){http_response_code(401);exit;}$uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');
        // اللينك ممكن يتعمل قبل ما الموقع يتحدد في الصفحة (website_id=0): نفتح تقرير أول موقع للعميل بدل صفحة خطأ
        if($wid<1)$wid=(int)($this->db->query('SELECT id FROM websites WHERE user_id = ?'.WebsiteSchema::alive().' ORDER BY id ASC LIMIT 1',[$uid])[0]['id']??0);
        try{$site=(new SeoMarketService($this->db))->site($wid,$uid);$data=(new SeoMonthlyReportService($this->db))->data($uid,$wid);header('Cache-Control: no-store, private');header('Content-Type: text/html; charset=utf-8');echo SeoMonthlyReportService::html($data,$site['main_url']);}catch(Throwable $e){http_response_code(503);echo 'التقرير غير متاح؛ راجع الربط والترحيل.';}
        // This is a complete HTML response; the API dispatcher must not replace its Content-Type or status.
        exit;
    }
}
