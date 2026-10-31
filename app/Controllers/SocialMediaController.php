<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialMonthlyPlanService.php';
require_once __DIR__ . '/../Jobs/GenerateSocialMonthlyPlanJob.php';
require_once __DIR__ . '/../Jobs/RefreshSocialPlanMetricsJob.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialPostReconciler.php';
require_once __DIR__ . '/../Services/SocialMedia/PublishHealthReport.php';
require_once __DIR__ . '/../Services/SocialMedia/MediaPreflight.php';
require_once __DIR__ . '/../Services/SocialMedia/MetaSocialAPI.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialLinkTracker.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialAttribution.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialCommentInbox.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialBestTimes.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialReport.php';
require_once __DIR__ . '/../Jobs/SyncSocialCommentsJob.php';
require_once __DIR__ . '/../Jobs/SocialDailyInsightsJob.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialGrowth.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialCompetitors.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialPlanShare.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialTeam.php';
require_once __DIR__ . '/../Services/SocialMedia/ThreadsAPI.php';
require_once __DIR__ . '/../Services/SocialMedia/LinkedInAPI.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialPackages.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialAccounts.php';
require_once __DIR__ . '/../Jobs/SocialPackagesRenewJob.php';
require_once __DIR__ . '/../Jobs/SocialPlanRenewJob.php';
require_once __DIR__ . '/../Services/Payment/CurrencyService.php';
require_once __DIR__ . '/../Services/Subscription/BillingPlanCatalog.php';
require_once __DIR__ . '/../Jobs/ReconcileUncertainPostsJob.php';
require_once __DIR__ . '/../Jobs/CheckSocialConnectionsHealthJob.php';
require_once __DIR__ . '/../Jobs/SyncHistoricalMetaPostsJob.php';

/**
 * Tourfecto - Social Media Controller
 * لوحة إدارة السوشيال ميديا (من ai-marketing-automation-hub، مُعاد بناؤها
 * فوق platform_connections/social_posts/social_post_targets الموحّدة)
 * @version 1.0.0
 */
class SocialMediaController extends Controller
{
    /** @var SocialPostService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SocialPostService();
    }

    /** @var array|null سياق الفريق (الحساب اللي بنشتغل عليه + الدور) */
    private ?array $teamCtx = null;

    private function team(): array
    {
        return $this->teamCtx ??= SocialTeam::context((int) ($this->user['id'] ?? 0));
    }

    /** الحساب اللي بيانات السوشيال بتاعته (صاحب الشركة حتى لو اللي داخل عضو في فريقه) */
    private function uid(): int
    {
        return (int) $this->team()['account_id'];
    }

    /** null = مسموح، أو خطأ 403 لو دور العضو في الفريق ما يسمحش */
    private function guard(string $permission): ?array
    {
        if (!SocialTeam::can($this->team(), $permission)) {
            return $this->error('دورك في فريق الحساب ما بيسمحش بالخطوة دي - اطلبها من مدير الحساب', 403);
        }
        // فريق العمل في السوشيال ميزة في الباقة: العضو مايشتغلش على حساب باقته مش بتشملها
        if (!empty($this->team()['member']) && !SocialPackages::has($this->uid(), 'team')) {
            return $this->error('فريق العمل في السوشيال مش ضمن باقة الحساب - صاحب الحساب يقدر يرقّي من صفحة الاشتراكات', 403, ['upgrade' => true, 'feature' => 'team']);
        }
        return null;
    }

    /** رد موحّد لحدود الباقة: 402 + رابط تبويب الباقة */
    private function limitError(SocialPackageLimit $e): array
    {
        return $this->error($e->getMessage(), 402, ['upgrade' => true, 'feature' => $e->feature, 'action' => '/subscription#social']);
    }

    /** GET /social */
    public function index(array $params = []): array
    {
        // نضمن إن مهام المصالحة وفحص الصحة الدورية شغّالة (بتتأكد من وجود job معلّق بس - خفيفة)
        try {
            ReconcileUncertainPostsJob::ensureScheduled();
            CheckSocialConnectionsHealthJob::ensureScheduled();
            SyncHistoricalMetaPostsJob::ensureScheduled();
            SyncSocialCommentsJob::ensureScheduled();
            SocialDailyInsightsJob::ensureScheduled();
            SocialPackages::ensureSchema();
            SocialAccounts::ensureSchema();
            SocialPackagesRenewJob::ensureScheduled();
            (new SocialMonthlyPlanService())->ensurePeriodSchema();
            SocialPlanRenewJob::ensureScheduled();
        } catch (Throwable $e) {
        }
        $ar = current_lang() === 'ar';
        $asset = static fn (string $path): string => htmlspecialchars(asset_v($path), ENT_QUOTES, 'UTF-8');
        $view = static function (array $vars): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            include __DIR__ . '/../Views/social/index.php';
            return (string) ob_get_clean();
        };
        $body = $view([
            'ar' => $ar,
            'uid' => $this->uid(),
            'team' => $this->team(),
            'cssUrl' => $asset('/assets/css/social-pro.css'),
            'jsUrl' => $asset('/assets/js/social-pro.js'),
            'iconsJs' => $asset('/assets/js/sp-icons.js'),
            'deployWarning' => $this->deployWarning(),
            // العميل بيتنقل بين أقسام السوشيال من القايمة اللي بتنزل من زرار الخدمة في الهيدر؛ الأدمن (من غير الهيدر ده) بيفضل شايف التبويبات
            'headerNav' => false,
            'monthlyCss' => $asset('/assets/css/social-monthly-plans.css'),
            'monthlyJs' => $asset('/assets/js/social-monthly-plans.js'),
            'pickerJs' => $asset('/assets/js/site-photo-picker.js'),
        ]);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage(
            'social',
            $ar ? 'السوشيال ميديا' : 'Social media',
            $ar ? 'انشر وجدول ورد على عملائك وتابع النتائج على كل المنصات' : 'Publish, schedule, answer customers and track results across all platforms',
            $body,
            ''
        );
        exit;
    }

    /**
     * فحص رفع ناقص (للأدمن بس): لو ملفات الواجهة اترفعت وملف المسارات لأ، كل التبويبات الجديدة بتطلع "تعذر التحميل".
     * @return string[] الملفات القديمة على السيرفر
     */
    private function deployWarning(): array
    {
        if (!in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true)) {
            return [];
        }
        $root = dirname(__DIR__, 2);
        $need = [
            '/app/routes/api.php' => '/api/social/connections/{id}/active',
            '/app/routes/web.php' => '/review/{token}',
            '/app/Services/SocialMedia/SocialPackages.php' => 'quoteCustom',
            '/app/Services/SocialMedia/SocialAccounts.php' => 'refreshStale',
        ];
        $stale = [];
        foreach ($need as $file => $marker) {
            $src = @file_get_contents($root . $file);
            if ($src === false || strpos($src, $marker) === false) {
                $stale[] = ltrim($file, '/');
            }
        }
        return $stale;
    }

    /** Monthly-plan endpoints share the same owner scope and delivery service. */
    private function monthlyRequest(string $action,array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        $write=in_array($action,['create','edit','live','renew','approve','pause','resume','cancel'],true);
        if($write&&($denied=$this->guard('manage')))return $denied;
        if($write&&($error=$this->verifyCsrf()))return $error;
        if($write&&($error=$this->rateLimitGuard('user',$action==='create'?'social_monthly_ai':'social_monthly_write',$action==='create'?4:40,60)))return $error;
        $uid=$this->uid();$id=(int)($params['id']??0);$service=new SocialMonthlyPlanService();
        try {
            if($action==='setup'){$result=$service->setup($uid);$result['csrf_token']=Csrf::token();return $this->success($result);}
            if($action==='list')return $this->success(['plans'=>$service->listPlans($uid)]);
            if($action==='detail')return $this->success($service->detail($uid,$id));
            if($action==='create'){
                SocialPackages::assertMonthlyPlan($uid,array_map('intval',(array)($this->data['connection_ids']??[])));
                $key=is_string($this->data['request_key']??null)?$this->data['request_key']:'';
                $isNew=!$this->db->query('SELECT id FROM social_monthly_plans WHERE user_id=? AND request_key=? LIMIT 1',[$uid,$key]);
                $out=$service->create($uid,$this->data);
                if($isNew)SocialPackages::record($uid,'monthly_plans');
                return $this->success($out,'Monthly generation queued',201);
            }
            if($action==='edit')return $this->success($service->edit($uid,$id,(int)($params['item_id']??0),$this->data));
            if($action==='live')return $this->success($service->liveEdit($uid,$id,(int)($params['item_id']??0),$this->data));
            if($action==='renew')return $this->success($service->setRenew($uid,$id,!empty($this->data['renew']),!empty($this->data['auto_approve'])));
            if($action==='approve'&&$this->get('reviewed')!==true)return $this->error('Confirm review before automatic publishing.',422);
            if($action==='approve')return $this->success($service->approve($uid,$id,(int)$this->get('version',0),is_array($this->get('tiktok'))?$this->get('tiktok'):null));
            return $this->success($service->changeState($uid,$id,$action));
        }catch(SocialPackageLimit $e){return $this->limitError($e);}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(ProviderRequestFailure $e){return $this->error('تعذر إعداد الخطة. حدّث قائمة الخطط قبل المحاولة مجددًا / Could not prepare the plan. Refresh the list before retrying.',502);}
        catch(Throwable $e){Logger::error('Monthly social plan action failed',['action'=>$action,'plan_id'=>$id,'user_id'=>$uid,'error'=>$e->getMessage()]);return $this->error('تعذر تنفيذ الطلب. تحقق من تركيب SQL وتشغيل مهام النشر ثم حدّث قائمة الخطط / Could not complete the request. Check migration and publishing worker, then refresh the plan list.',503);}
    }
    public function monthlySetup(array $params=[]): array{return $this->monthlyRequest('setup',$params);}
    public function monthlyList(array $params=[]): array{return $this->monthlyRequest('list',$params);}
    public function monthlyDetail(array $params=[]): array{return $this->monthlyRequest('detail',$params);}
    public function monthlyCreate(array $params=[]): array{return $this->monthlyRequest('create',$params);}
    public function monthlyEdit(array $params=[]): array{return $this->monthlyRequest('edit',$params);}
    public function monthlyLiveEdit(array $params=[]): array{return $this->monthlyRequest('live',$params);}
    public function monthlyRenew(array $params=[]): array{return $this->monthlyRequest('renew',$params);}
    public function monthlyApprove(array $params=[]): array{return $this->monthlyRequest('approve',$params);}
    public function monthlyPause(array $params=[]): array{return $this->monthlyRequest('pause',$params);}
    public function monthlyResume(array $params=[]): array{return $this->monthlyRequest('resume',$params);}
    public function monthlyCancel(array $params=[]): array{return $this->monthlyRequest('cancel',$params);}

    /** GET /api/social/autopilot?website_id= - إعدادات الطيار الآلي + إشارات الشهر الجاي (الموسم والرحلات) */
    public function autopilotGet(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{return $this->success((new SocialAutopilotService())->overview((int)$this->user['id'],(int)$this->get('website_id'))+['csrf_token'=>Csrf::token()]);}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){Logger::error('Social autopilot overview failed',['error'=>$e->getMessage()]);return $this->error('Autopilot is unavailable right now.',503);}
    }

    /** POST /api/social/autopilot - تفعيل/تعديل الطيار الآلي لموقع */
    public function autopilotSave(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($error=$this->verifyCsrf())return $error;
        if($error=$this->rateLimitGuard('user','social_autopilot',20,60))return $error;
        try{return $this->success((new SocialAutopilotService())->save((int)$this->user['id'],(int)($this->data['website_id']??0),$this->data),'Autopilot saved');}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){Logger::error('Social autopilot save failed',['error'=>$e->getMessage()]);return $this->error('Autopilot could not be saved. Check the SQL update.',503);}
    }

    /** POST /api/social/media - رفع صورة/فيديو من الجهاز للمنشور */
    public function uploadMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfDenied = $this->verifyCsrf()) {
            return $csrfDenied;
        }
        if ($denied = $this->guard('draft')) {
            return $denied;
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_upload', 30, 60)) {
            return $rateError;
        }
        $r = SocialMediaUpload::handle($this->uid(), $_FILES['file'] ?? null);
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        return $this->success(['item' => $r['item'], 'duplicate' => !empty($r['duplicate'])], !empty($r['duplicate']) ? 'الصورة دي موجودة في مكتبتك بالفعل' : 'اترفع', 201);
    }

    /** POST /api/social/brand-kit - لوجو وألوان الشركة اللي بتتحط على التصميمات */
    public function saveBrandKit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_brand_kit', 20, 60))) {
            return $error;
        }
        try {
            $kit = (new SocialBrandStudio())->saveKit($this->uid(), (int) ($_POST['website_id'] ?? $this->get('website_id', 0)), $_FILES['logo'] ?? null, (string) ($_POST['primary'] ?? $this->get('primary', '')), (string) ($_POST['accent'] ?? $this->get('accent', '')));
            return $this->success(['brand' => $kit], 'اتحفظت هوية الشركة');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brand kit save failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نحفظ الهوية دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/import-site - سحب صور الرحلات والفنادق من موقع العميل لمكتبته */
    public function importSiteImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_import', 4, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        try {
            $uid = $this->uid();
            $wid = (int) $this->get('website_id', 0);
            $importer = new SiteImageImporter();
            if ($wid > 0) {
                $r = $importer->import($uid, $wid, 20);
                return $this->success($r, $r['message']);
            }
            // من غير موقع محدد (مكتبة الاستوديو): كل مواقع العميل المربوطة
            $sites = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 3', [$uid]) ?: [];
            if (!$sites) {
                return $this->error('اربط موقعك الأول من "مواقعي" عشان نسحب صوره', 422);
            }
            $importer->budgetSeconds = (int) floor(38 / count($sites));
            $added = [];
            $found = 0;
            foreach ($sites as $site) {
                $r = $importer->import($uid, (int) $site['id'], max(5, (int) floor(20 / count($sites))));
                $added = array_merge($added, $r['added']);
                $found += $r['found'];
            }
            $msg = $added ? 'سحبنا ' . count($added) . ' صورة من موقعك.' : ($found ? 'كل صور موقعك المناسبة موجودة في مكتبتك بالفعل.' : 'مالقيناش صور كبيرة كفاية على موقعك. ارفع صورك من جهازك.');
            return $this->success(['added' => $added, 'found' => $found, 'message' => $msg], $msg);
        } catch (InvalidArgumentException $e) {
            return $this->error('اختار الموقع', 422);
        } catch (Throwable $e) {
            Logger::error('Site image import failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نسحب الصور من موقعك دلوقتي، جرّب تاني أو ارفع صورك من جهازك', 500);
        }
    }

    /** POST /api/social/media/site-scan - يدوّر على صور الموقع (أو روابط رحلات) ويعرضها للاختيار */
    public function scanSiteImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_scan', 10, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        $uid = $this->uid();
        $wid = (int) $this->get('website_id', 0);
        if ($wid < 1) {
            $row = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
            $wid = (int) ($row[0]['id'] ?? 0);
        }
        $urls = $this->get('urls', []);
        $urls = is_array($urls) ? array_values(array_filter($urls, 'is_string')) : [];
        if (!$urls && $wid < 1) {
            return $this->error('اربط موقعك الأول، أو حط روابط الرحلات اللي عاوز صورها', 422);
        }
        try {
            $r = (new SiteImageImporter())->scan($uid, $wid, $urls);
            if (!$r['total']) {
                return $this->error($urls ? 'مالقيناش صور في الروابط دي. اتأكد إن الروابط بتفتح.' : 'مالقيناش صور على موقعك. حط روابط صفحات الرحلات بنفسك.', 422);
            }
            return $this->success($r);
        } catch (InvalidArgumentException $e) {
            return $this->error('اختار الموقع', 422);
        } catch (Throwable $e) {
            Logger::error('Site image scan failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نفتح الموقع دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/site-import-selected - يسحب الصور اللي العميل اختارها بس */
    public function importSelectedImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_pick', 60, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        $items = $this->get('items', []);
        if (!is_array($items) || !$items || count($items) > 25) {
            return $this->error('اختار من 1 لـ 25 صورة في المرة', 422);
        }
        $clean = [];
        foreach ($items as $it) {
            if (is_array($it) && is_string($it['src'] ?? null)) {
                $clean[] = ['src' => $it['src'], 'alt' => is_string($it['alt'] ?? null) ? $it['alt'] : '', 'page' => is_string($it['page'] ?? null) ? $it['page'] : '', 'title' => is_string($it['title'] ?? null) ? $it['title'] : ''];
            }
        }
        try {
            return $this->success((new SiteImageImporter())->importItems($this->uid(), $clean, $this->get('folders') === true));
        } catch (Throwable $e) {
            Logger::error('Selected image import failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نسحب الصور دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/{id}/learn - الذكاء الصناعي يشوف الصورة ويفهمها */
    public function learnMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('draft')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_learn', 40, 60))) {
            return $error;
        }
        try {
            $v = (new SocialBrandStudio())->learn($this->uid(), (int) ($params['id'] ?? 0));
            return $this->success(['vision' => $v]);
        } catch (InvalidArgumentException $e) {
            return $this->error('الصورة مش موجودة', 404);
        } catch (Throwable $e) {
            return $this->success(['vision' => null]);
        }
    }

    /** POST /api/social/design - تصميم بوست واحد بهوية الشركة من صورة العميل (مجانًا - مفيش توليد صور) */
    public function designPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('draft')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_design', 20, 60))) {
            return $error;
        }
        $headline = trim((string) $this->get('headline', ''));
        if (mb_strlen($headline) < 3 || mb_strlen($headline) > 90) {
            return $this->error('اكتب عنوان قصير للتصميم (من 3 لـ 90 حرف)', 422);
        }
        $uid = $this->uid();
        $mediaId = (int) $this->get('media_id', 0);
        try {
            $studio = new SocialBrandStudio();
            $id = $studio->design($uid, (int) $this->get('website_id', 0), (string) $this->get('format', 'design'), $headline, (string) $this->get('subline', ''), $mediaId > 0 ? $mediaId : null, (int) $this->get('variant', 0));
            $row = PrivateMedia::owned($uid, $id);
            return $this->success(['id' => $id, 'preview_url' => $row['file_path']], 'التصميم جاهز', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage() === 'Website unavailable' ? 'اختار الموقع' : 'الصورة مش موجودة', 422);
        } catch (Throwable $e) {
            Logger::error('Design post failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نعمل التصميم دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/posts/{id}/retry - إعادة نشر الأهداف اللي فشلت */
    public function retryPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($denied = $this->guard('publish')) || ($denied = $this->verifyCsrf()) || ($denied = $this->rateLimitGuard('user', 'social_post_retry', 30, 60))) {
            return $denied;
        }
        $uid = $this->uid();
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $failed = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'failed'", [$postId]);
        if (!$failed) {
            return $this->error('مفيش حاجة فاشلة في المنشور ده', 422);
        }
        $queue = Container::getInstance()->make(QueueManager::class);
        $jobs = [];
        foreach ($failed as $t) {
            $this->db->query("UPDATE social_post_targets SET status = 'scheduled', last_error = NULL, provider_ref = NULL, poll_attempts = 0, scheduled_at = NULL WHERE id = ?", [(int) $t['id']]);
            $id = $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => (int) $t['id']], 'social', 0);
            if ($id) {
                $jobs[] = (int) $id;
            }
        }
        SocialPostService::runNow($jobs);
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId)]);
    }

    /**
     * POST /api/social/posts/{id}/delete  body: {remote: bool}
     * remote=true: يمسح المنشور من فيسبوك نفسه كمان (إنستجرام ما بيدعمش الحذف عبر الـ API - بيتبلّغ العميل).
     * لو الحذف من فيسبوك فشل ما بنمسحش محليًا عشان ما نفقدش الربط بالمنشور - العميل يقدر يعيد المحاولة.
     */
    public function deletePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('publish')) {
            return $denied;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $this->uid()])) {
            return $this->error('المنشور مش موجود', 404);
        }
        if ($this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'publishing' LIMIT 1", [$postId])) {
            return $this->error('المنشور بيتنشر دلوقتي، استنى لما يخلص', 422);
        }
        $remoteNote = [];
        if ((bool) $this->get('remote', false)) {
            if ($csrfError = $this->verifyCsrf()) {
                return $csrfError; // الحذف من فيسبوك إجراء خارجي لا رجعة فيه
            }
            $rows = $this->db->query(
                "SELECT t.id, t.external_post_id, c.platform, c.access_token FROM social_post_targets t
                   JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE t.social_post_id = ? AND t.status = 'published' AND t.external_post_id IS NOT NULL AND t.external_post_id <> ''",
                [$postId]
            ) ?: [];
            $enc = new Encryption();
            $failures = [];
            foreach ($rows as $r) {
                if ($r['platform'] === 'instagram') {
                    $remoteNote[] = 'إنستجرام: الحذف من التطبيق نفسه (Meta ما بتسمحش بحذف منشورات إنستجرام عبر الـ API).';
                    continue;
                }
                if ($r['platform'] !== 'facebook') {
                    continue;
                }
                try {
                    $token = $enc->decrypt((string) $r['access_token']);
                    $res = (new MetaSocialAPI($token))->deleteFacebookPost((string) $r['external_post_id'], $token);
                } catch (Throwable $e) {
                    $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
                }
                if (!$res['success']) {
                    $failures[] = $res['friendly'] ?? $res['error'] ?? 'فشل الحذف من فيسبوك';
                }
            }
            if ($failures) {
                return $this->error('تعذر الحذف من فيسبوك: ' . implode(' | ', array_unique($failures)) . ' — المنشور لسه موجود عندنا، حاول تاني أو امسحه من المنصة بس.', 422);
            }
        }
        $this->db->query('DELETE FROM social_post_targets WHERE social_post_id = ?', [$postId]);
        $this->db->query('DELETE FROM social_posts WHERE id = ?', [$postId]);
        return $this->success(['notes' => $remoteNote], 'اتمسح');
    }

    /** POST /api/social/posts/{id}/reconcile - تحقق فوري من Meta: هل المنشور uncertain اتنشر فعلًا */
    public function reconcilePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('publish')) {
            return $denied;
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $this->uid()])) {
            return $this->error('المنشور مش موجود', 404);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_reconcile', 10, 60)) {
            return $rateError;
        }
        $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'uncertain'", [$postId]) ?: [];
        if (!$targets) {
            return $this->error('مفيش حاجة محتاجة تحقق في المنشور ده', 422);
        }
        @set_time_limit(60);
        $results = [];
        foreach ($targets as $t) {
            $results[] = SocialPostReconciler::reconcileTarget((int) $t['id']);
        }
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId), 'results' => $results]);
    }

    /** POST /api/social/posts/{id}/resolve  body: {resolution: published|not_published} - حسم يدوي لو المصالحة التلقائية مش كفاية */
    public function resolvePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('publish')) {
            return $denied;
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $this->uid()])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $resolution = (string) $this->get('resolution', '');
        if (!in_array($resolution, ['published', 'not_published'], true)) {
            return $this->error('القرار لازم يكون published أو not_published', 422);
        }
        $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'uncertain'", [$postId]) ?: [];
        if (!$targets) {
            return $this->error('مفيش حاجة محتاجة حسم في المنشور ده', 422);
        }
        foreach ($targets as $t) {
            SocialPostReconciler::resolveManually((int) $t['id'], $resolution);
        }
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId)]);
    }

    /** POST /api/social/posts/{id}/edit-published  body: {content} - تعديل نص المنشور على فيسبوك بعد النشر (فيسبوك بس) */
    public function editPublishedPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('publish')) {
            return $denied;
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $this->uid()])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $content = trim((string) $this->get('content', ''));
        if ($content === '') {
            return $this->error('Post text is required', 422);
        }
        if (MediaPreflight::checkCaption('facebook', $content)) {
            return $this->error('النص أطول من الحد المسموح في فيسبوك', 422);
        }
        $rows = $this->db->query(
            "SELECT t.external_post_id, c.access_token FROM social_post_targets t JOIN platform_connections c ON c.id = t.platform_connection_id
              WHERE t.social_post_id = ? AND t.status = 'published' AND c.platform = 'facebook' AND t.external_post_id <> ''",
            [$postId]
        ) ?: [];
        if (!$rows) {
            return $this->error('التعديل بعد النشر متاح لمنشورات فيسبوك بس (إنستجرام وتيك توك ما بيسمحوش بتعديل النص عبر الـ API).', 422);
        }
        $enc = new Encryption();
        $errors = [];
        foreach ($rows as $r) {
            try {
                $token = $enc->decrypt((string) $r['access_token']);
                $res = (new MetaSocialAPI($token))->editFacebookPost((string) $r['external_post_id'], $token, $content);
            } catch (Throwable $e) {
                $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
            }
            if (!$res['success']) {
                $errors[] = SocialCommentInbox::readableError($res, 'تعذر الوصول لفيسبوك دلوقتي (عطل مؤقت أو مشكلة اتصال). النص ما اتغيّرش، جرّب تاني بعد شوية.');
            }
        }
        if ($errors) {
            return $this->error('تعذر التعديل على فيسبوك: ' . implode(' | ', array_unique($errors)), 422);
        }
        $this->db->query('UPDATE social_posts SET content = ? WHERE id = ?', [$content, $postId]);
        return $this->success([], 'اتعدّل على فيسبوك');
    }

    /**
     * GET /api/social/health?days=30
     * لوحة مراقبة: معدل نجاح النشر لكل صفحة، متوسط الزمن، أكتر الأخطاء، وحالة صحة الربط.
     */
    public function publishHealth(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $days = max(1, min(90, (int) $this->get('days', 30)));
        try {
            $rows = $this->db->query(
                "SELECT l.platform_connection_id AS connection_id, c.platform, c.external_location_name AS name, l.outcome, l.error_category, COUNT(*) AS n, AVG(l.duration_ms) AS avg_ms
                   FROM social_publish_log l LEFT JOIN platform_connections c ON c.id = l.platform_connection_id
                  WHERE l.user_id = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                  GROUP BY l.platform_connection_id, c.platform, c.external_location_name, l.outcome, l.error_category",
                [$uid]
            ) ?: [];
            $health = $this->db->query(
                "SELECT h.platform_connection_id AS connection_id, c.platform AS platform, c.external_location_name AS name, h.state, h.token_expires_at, h.data_access_expires_at, h.missing_scopes, h.last_error, h.checked_at
                   FROM social_connection_health h JOIN platform_connections c ON c.id = h.platform_connection_id
                  WHERE c.user_id = ? OR c.website_id IN (SELECT id FROM websites WHERE user_id = ?)",
                [$uid, $uid]
            ) ?: [];
        } catch (Throwable $e) {
            return $this->error('شغّل migration الموثوقية الأول (2026_10_18_000001_social_publishing_reliability.sql).', 500);
        }
        $pages = PublishHealthReport::build($rows, $health);
        return $this->success(['days' => $days, 'pages' => $pages]);
    }

    // ------------------------------------------------------------------
    // صندوق التعليقات
    // ------------------------------------------------------------------

    /** GET /api/social/inbox?status=open|new|replied|hidden|done|all&intent=&page={connection_id} */
    public function inboxList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $inbox = new SocialCommentInbox();
        if (!$inbox->ready()) {
            return $this->success(['installed' => false, 'comments' => [], 'counts' => ['open' => 0, 'hot' => 0]]);
        }
        try {
            $inbox->ensurePagesSchema();
            $r = $inbox->list($this->uid(), (string) $this->get('status', 'open'), (string) $this->get('intent', ''), 60, (int) $this->get('page', 0));
            // الصفحات اللي الصندوق بيسحب منها ويرد عليها (مع سبب أي صفحة مش شغالة)
            return $this->success(['installed' => true, 'pages' => $inbox->pages($this->uid())] + $r);
        } catch (Throwable $e) {
            Logger::error('Social inbox list failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل التعليقات', 500);
        }
    }

    /** POST /api/social/inbox/sync - سحب التعليقات الجديدة دلوقتي */
    public function inboxSync(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('inbox')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_sync', 6, 300))) {
            return $error;
        }
        $inbox = new SocialCommentInbox();
        if (!$inbox->ready()) {
            return $this->error('شغّل migration السوشيال الاحترافي (2026_10_20_000001_social_pro.sql) الأول', 422);
        }
        @set_time_limit(120);
        try {
            return $this->success($inbox->sync($this->uid(), 15));
        } catch (Throwable $e) {
            Logger::error('Social inbox sync failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر سحب التعليقات من Meta دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/inbox/{id}/suggest - رد مقترح بالذكاء الاصطناعي */
    public function inboxSuggest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('inbox')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'ai', 30, 60))) {
            return $error;
        }
        try {
            $uid = $this->uid();
            return $this->success((new SocialCommentInbox())->suggest($uid, (int) ($params['id'] ?? 0), ContentLanguageService::forUser($uid, null)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (Throwable $e) {
            Logger::warning('Social inbox suggest failed', ['message' => $e->getMessage()]);
            return $this->error('مقدرناش نجهّز رد دلوقتي، اكتب ردك أو جرّب تاني', 502);
        }
    }

    /** POST /api/social/inbox/{id}/reply  body: {text} */
    public function inboxReply(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('inbox')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_reply', 60, 60))) {
            return $error;
        }
        try {
            return $this->success((new SocialCommentInbox())->reply($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('text', '')), 'اتبعت الرد');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error(CustomerError::message($e->getMessage(), 502), 502);
        }
    }

    /** POST /api/social/inbox/{id}/hide - إخفاء تعليق سبام/مسيء */
    public function inboxHide(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('inbox')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_reply', 60, 60))) {
            return $error;
        }
        try {
            return $this->success((new SocialCommentInbox())->hide($this->uid(), (int) ($params['id'] ?? 0)), 'اتخفى التعليق');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error(CustomerError::message($e->getMessage(), 502), 502);
        }
    }

    /** POST /api/social/inbox/{id}/done - تم التعامل معاه (من غير رد علني) */
    public function inboxDone(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('inbox')) {
            return $denied;
        }
        if ($error = $this->verifyCsrf()) {
            return $error;
        }
        try {
            return $this->success((new SocialCommentInbox())->done($this->uid(), (int) ($params['id'] ?? 0)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /** POST /api/social/inbox/{id}/private  body: {text?} - رد خاص (ماسنجر/دايركت) لصاحب التعليق */
    public function inboxPrivate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('inbox')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_reply', 60, 60))) {
            return $error;
        }
        try {
            SocialPackages::assertFeature($this->uid(), 'private_reply');
            $text = $this->get('text');
            return $this->success((new SocialCommentInbox())->privateReply($this->uid(), (int) ($params['id'] ?? 0), is_string($text) ? $text : null, (bool) $this->get('ack', false)), 'اتبعت الرد الخاص');
        } catch (SocialPackageLimit $e) {
            return $this->limitError($e);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error(CustomerError::message($e->getMessage(), 502), 502);
        }
    }

    /** GET /api/social/inbox-settings */
    public function inboxSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $inbox = new SocialCommentInbox();
        $inbox->ensurePagesSchema();
        // allowed = الباقة فيها الرد التلقائي (لو مش فيها الإعداد بيفضل محفوظ بس مش بيشتغل)
        return $this->success(['installed' => $inbox->privateReady(), 'allowed' => SocialPackages::has($this->uid(), 'auto_private'), 'pages' => $inbox->ready() ? $inbox->pages($this->uid()) : []] + $inbox->settings($this->uid()));
    }

    /** POST /api/social/inbox-settings  body: {auto_private, auto_intents[], private_template, public_ack, auto_pages[]?} */
    public function inboxSettingsSave(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_settings', 20, 60))) {
            return $error;
        }
        $inbox = new SocialCommentInbox();
        if (!$inbox->privateReady()) {
            return $this->error('شغّل migration السوشيال (2026_10_21_000001_social_growth.sql) الأول', 422);
        }
        try {
            if (!empty($this->data['auto_private'])) {
                SocialPackages::assertFeature($this->uid(), 'auto_private');
            }
            $saved = $inbox->saveSettings($this->uid(), $this->data);
            if (isset($this->data['auto_pages']) && is_array($this->data['auto_pages'])) {
                $inbox->setAutoPages($this->uid(), $this->data['auto_pages']);
            }
            return $this->success($saved + ['pages' => $inbox->pages($this->uid())], 'اتحفظت إعدادات الرد');
        } catch (SocialPackageLimit $e) {
            return $this->limitError($e);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------
    // رابط موافقة العميل على الخطة الشهرية
    // ------------------------------------------------------------------

    /** GET /api/social/monthly-plans/{id}/share - الروابط + ملاحظات العميل */
    public function planShareList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $share = new SocialPlanShare();
        try {
            $uid = $this->uid();
            $id = (int) ($params['id'] ?? 0);
            return $this->success(['installed' => $share->ready(), 'links' => $share->links($uid, $id), 'feedback' => $share->feedback($uid, $id)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /** POST /api/social/monthly-plans/{id}/share  body: {can_approve, client_name, days} */
    public function planShareCreate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_plan_share', 10, 300))) {
            return $error;
        }
        try {
            SocialPackages::assertFeature($this->uid(), 'client_review');
            return $this->success((new SocialPlanShare())->create($this->uid(), (int) ($params['id'] ?? 0), (bool) $this->get('can_approve', true), (string) $this->get('client_name', ''), (int) $this->get('days', 14)), 'اتعمل الرابط', 201);
        } catch (SocialPackageLimit $e) {
            return $this->limitError($e);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/social/monthly-plans/{id}/share/{link_id}/revoke */
    public function planShareRevoke(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if ($error = $this->verifyCsrf()) {
            return $error;
        }
        try {
            return $this->success(['links' => (new SocialPlanShare())->revoke($this->uid(), (int) ($params['id'] ?? 0), (int) ($params['link_id'] ?? 0))], 'اتلغى الرابط');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /**
     * GET /api/social/onboarding - خط سير العميل الجديد لتشغيل الخدمة (كل خطوة: اتعملت ولا لأ + تروح فين).
     * كل فحص لوحده جوه try: جدول ناقص مايوقعش القايمة كلها.
     */
    public function onboarding(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $n = function (string $sql, array $args = []): int {
            try {
                return (int) (($this->db->query($sql, $args)[0]['n'] ?? 0));
            } catch (Throwable $e) {
                return 0;
            }
        };
        $own = '(user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?))';
        $accounts = $n("SELECT COUNT(*) AS n FROM platform_connections WHERE {$own} AND platform IN ('facebook','instagram','tiktok','youtube','google_business','threads','linkedin') AND status = 'connected'", [$uid, $uid]);
        $sites = $n('SELECT COUNT(*) AS n FROM websites WHERE user_id = ?', [$uid]) + $n('SELECT COUNT(*) AS n FROM generated_websites WHERE user_id = ?', [$uid]);
        $trips = $n("SELECT COUNT(*) AS n FROM crm_products WHERE user_id = ? AND is_active = 1 AND tour_slug IS NOT NULL AND tour_slug <> ''", [$uid]);
        $brand = $n("SELECT COUNT(*) AS n FROM brand_profiles WHERE user_id = ? AND logo_path IS NOT NULL AND logo_path <> ''", [$uid]);
        $media = $n("SELECT COUNT(*) AS n FROM media_items WHERE user_id = ? AND status = 'completed' AND deleted_at IS NULL", [$uid]);
        $posts = $n('SELECT COUNT(*) AS n FROM social_posts WHERE user_id = ?', [$uid]);
        $plans = $n("SELECT COUNT(*) AS n FROM social_monthly_plans WHERE user_id = ? AND status IN ('draft','active','paused','completed')", [$uid]);
        $inbox = $n('SELECT COUNT(*) AS n FROM social_inbox_settings WHERE user_id = ?', [$uid]);
        $season = $n('SELECT COUNT(*) AS n FROM season_post_drafts WHERE user_id = ?', [$uid]);
        $ar = current_lang() === 'ar';
        $L = static fn (string $a, string $e) => $ar ? $a : $e;
        $steps = [
            ['key' => 'accounts', 'done' => $accounts > 0, 'icon' => 'link', 'title' => $L('اربط صفحاتك', 'Connect your pages'),
                'text' => $L('اربط فيسبوك وإنستجرام (وتيك توك ويوتيوب لو بتستخدمهم)، واختار الصفحات اللي هتنشر عليها.', 'Connect Facebook & Instagram (and TikTok/YouTube if you use them), then pick the pages you publish to.'),
                'href' => '/social#accounts', 'cta' => $L('اربط الحسابات', 'Connect accounts')],
            ['key' => 'site', 'done' => $sites > 0, 'icon' => 'external', 'title' => $L('ضيف موقع شركتك ورحلاتك', 'Add your website & trips'),
                'text' => $L('الموقع والرحلات بيخلّوا المنشورات فيها روابط حجز حقيقية، وبيشغّلوا «رحلات الموسم» والخطة الشهرية.', 'Your site and trips put real booking links in posts and power Season trips and the monthly plan.'),
                'href' => $sites > 0 ? '/website-builder' : '/websites', 'cta' => $L('ضيف الموقع', 'Add website')],
            ['key' => 'brand', 'done' => $brand > 0 && $media > 0, 'icon' => 'images', 'title' => $L('لوجو شركتك وصورك', 'Your logo & photos'),
                'text' => $L('ارفع اللوجو وصور رحلاتك (أو اسحبها من موقعك) عشان التصميمات تطلع بهوية شركتك.', 'Upload your logo and trip photos (or pull them from your site) so designs carry your brand.'),
                'href' => '/social#plan', 'cta' => $L('ارفع اللوجو والصور', 'Upload logo & photos')],
            ['key' => 'post', 'done' => $posts > 0, 'icon' => 'send', 'title' => $L('أول منشور', 'Your first post'),
                'text' => $L('اكتب موضوع والذكاء الصناعي يكتبلك 3 نسخ بالهاشتاجات، واختار الصفحات وانشر أو جدول.', 'Type a topic and AI writes 3 versions with hashtags; pick pages and publish or schedule.'),
                'action' => 'new_post', 'cta' => $L('اعمل منشور', 'Create a post')],
            ['key' => 'plan', 'done' => $plans > 0, 'icon' => 'planCheck', 'title' => $L('خطة الشهر كاملة', 'A full monthly plan'),
                'text' => $L('خطة منشورات للشهر كله بالصور والمواعيد، تراجعها مرة واحدة وتتنشر لوحدها.', 'A whole month of posts with images and times — review once and it publishes itself.'),
                'href' => '/social#plan', 'cta' => $L('جهّز الخطة', 'Prepare the plan')],
            ['key' => 'inbox', 'done' => $inbox > 0, 'icon' => 'chat', 'title' => $L('الرد على التعليقات', 'Answer comments'),
                'text' => $L('كل التعليقات في مكان واحد، وفعّل الرد الخاص التلقائي على اللي بيسأل عن السعر.', 'All comments in one place — turn on auto private replies for price questions.'),
                'href' => '/social#inbox', 'cta' => $L('افتح صندوق التعليقات', 'Open the inbox')],
            ['key' => 'season', 'done' => $season > 0, 'icon' => 'trend', 'title' => $L('رحلات الموسم', 'Season trips'),
                'text' => $L('بنشوف من حجوزاتك الرحلات المطلوبة في الموسم ونعملها منشورات جاهزة.', 'We spot the trips in demand from your bookings and turn them into ready posts.'),
                'href' => '/revenue/intelligence#season', 'cta' => $L('شوف رحلات الموسم', 'See season trips'), 'needs' => $trips > 0 ? null : $L('محتاجة رحلات على موقعك', 'Needs trips on your site')],
        ];
        $done = count(array_filter($steps, static fn ($s) => $s['done']));
        return $this->success(['steps' => $steps, 'done' => $done, 'total' => count($steps), 'next' => array_values(array_filter($steps, static fn ($s) => !$s['done']))[0]['key'] ?? null]);
    }

    /** GET /api/social/me - دور المستخدم في فريق الحساب (للواجهة) */
    public function me(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $t = $this->team();
        $ent = SocialPackages::entitlements($this->uid());
        return $this->success(['role' => $t['role'], 'member' => $t['member'], 'can' => $t['can'], 'team_locked' => $t['member'] && empty($ent['features']['team']),
            'package' => ['tier' => $ent['tier'], 'name' => $ent['tier_name'], 'features' => $ent['features'], 'platforms' => $ent['platforms']]]);
    }

    /** GET /api/social/growth?days=30 - نمو المتابعين لكل حساب (لقطة يومية) */
    public function growth(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        if (!SocialGrowth::ready()) {
            return $this->success(['installed' => false, 'accounts' => []]);
        }
        if (!SocialPackages::has($uid, 'growth')) {
            return $this->success(['installed' => true, 'locked' => true, 'accounts' => []]);
        }
        // أول مرة النهارده: ناخد لقطة لحسابات العميل فورًا بدل ما يستنى المهمة اليومية (حساباته بس - خفيفة)
        try {
            SocialGrowth::snapshot($uid, 10);
        } catch (Throwable $e) {
        }
        return $this->success(['installed' => true, 'accounts' => SocialGrowth::series($uid, (int) $this->get('days', 30))]);
    }

    /** GET /api/social/competitors */
    public function competitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success((new SocialCompetitors())->list($this->uid()));
    }

    /** POST /api/social/competitors  body: {username} */
    public function competitorAdd(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_competitors', 10, 300))) {
            return $error;
        }
        try {
            SocialPackages::assertCompetitorSlot($this->uid());
            return $this->success((new SocialCompetitors())->add($this->uid(), (string) $this->get('username', '')), 'اتضاف المنافس');
        } catch (SocialPackageLimit $e) {
            return $this->limitError($e);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::warning('Competitor add failed', ['message' => $e->getMessage()]);
            return $this->error('مقدرناش نوصل لإنستجرام دلوقتي، جرّب تاني بعد شوية', 502);
        }
    }

    /** POST /api/social/competitors/refresh */
    public function competitorRefresh(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_competitors_refresh', 3, 600))) {
            return $error;
        }
        @set_time_limit(90);
        $svc = new SocialCompetitors();
        $svc->refreshUser($this->uid());
        return $this->success($svc->list($this->uid()));
    }

    /** POST /api/social/competitors/{id}/delete */
    public function competitorDelete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('manage')) {
            return $denied;
        }
        if ($error = $this->verifyCsrf()) {
            return $error;
        }
        $svc = new SocialCompetitors();
        $svc->remove($this->uid(), (int) ($params['id'] ?? 0));
        return $this->success($svc->list($this->uid()));
    }

    /** GET /api/social/best-times?connection_id= - أفضل مواعيد النشر بتوقيت حساب العميل */
    public function bestTimes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $cid = (int) $this->get('connection_id', 0) ?: null;
        $tz = (string) (($this->db->query('SELECT timezone FROM users WHERE id = ?', [$uid])[0]['timezone'] ?? '') ?: 'UTC');
        $samples = SocialBestTimes::samples($uid, $cid);
        $prefs = SocialBestTimes::preferencesFrom($samples, $tz);
        return $this->success([
            'timezone' => $tz,
            'source' => $prefs['source'],
            'measured' => $prefs['measured'],
            'min_measured' => SocialBestTimes::MIN_MEASURED,
            'by_day' => $prefs['times'],
            'top' => $prefs['source'] === 'measured' ? SocialBestTimes::rank($samples, $tz, 3) : [],
        ]);
    }

    /** GET /social/report?month=YYYY-MM - تقرير أداء شهري جاهز للطباعة / الحفظ PDF (بهوية الشركة) */
    public function report(array $params = []): void
    {
        $uid = $this->uid();
        $month = (string) ($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            $month = gmdate('Y-m', strtotime('first day of last month'));
        }
        $data = SocialReport::build($uid, $month);
        header('Content-Type: text/html; charset=utf-8');
        echo SocialReport::render($data, current_lang() === 'ar');
        exit;
    }

    private static array $colCache = [];

    /** فحص وجود عمود (بيتكاش) - عشان الكود ما يقعش لو الـ migration لسه ما اتشغّلتش */
    // ------------------------------------------------------------------
    // باقات السوشيال (صفحة الاشتراكات) + إعدادات الأدمن
    // ------------------------------------------------------------------

    private function priceLabels(float $usd, string $cur, bool $ar): array
    {
        // المبلغ اللي بيتخصم بالدولار بالظبط (format بيقرّب الأرقام الكبيرة لأقرب 5 - ينفع للعرض المحلي بس)
        $exact = '$' . (floor($usd) == $usd ? number_format($usd, 0) : number_format($usd, 2));
        return ['usd' => round($usd, 2), 'label' => $cur === 'USD' ? $exact : '≈ ' . CurrencyService::format($usd, $cur, $ar) . ' (' . $exact . ')', 'usd_label' => $exact];
    }

    /** GET /api/social/package - الباقة الحالية + الاستخدام + الكتالوج بأسعار عملة العميل */
    public function package(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        SocialPackages::ensureSchema();
        $ar = current_lang() === 'ar';
        $cur = CurrencyService::forViewer($this->user);
        $cfg = SocialPackages::config();
        $tiers = [];
        $viewerTier = (string) (SocialPackages::entitlements($this->uid())['tier'] ?? '');
        foreach ($cfg['tiers'] as $k => $t) {
            if ($k === 'enterprise' && empty($t['for_sale']) && $viewerTier !== 'enterprise') {
                continue; // مستوى المؤسسات مخفي عن العملاء لحد ما الأدمن يفتحه للبيع
            }
            $tiers[] = ['key' => $k, 'name' => $ar ? $t['name'] : $t['name_en'], 'for_sale' => (bool) $t['for_sale'], 'rank' => SocialPackages::RANK[$k],
                'monthly' => $this->priceLabels((float) $t['price_monthly'], $cur, $ar), 'yearly' => $this->priceLabels((float) $t['price_yearly'], $cur, $ar),
                'limits' => array_intersect_key($t, array_flip(SocialPackages::LIMIT_KEYS)), 'platforms' => array_values(array_intersect($cfg['platforms_enabled'], $t['platforms'])),
                'features' => array_map('boolval', array_intersect_key($t, array_flip(SocialPackages::FEATURE_KEYS)))];
        }
        $addons = [];
        foreach ($cfg['addons'] as $k => $a) {
            if ($a['enabled']) {
                $addons[] = ['key' => $k, 'kind' => $a['kind'], 'qty' => $a['qty'], 'icon' => $a['icon'], 'price' => $this->priceLabels((float) $a['price'], $cur, $ar)];
            }
        }
        // أسماء باقات المنصة للعرض بدل المفاتيح (starter/professional...)، والباقات المتاحة بس
        $planNames = [];
        $planMap = [];
        try {
            foreach (BillingPlanCatalog::read() as $k => $pl) {
                $planNames[$k] = (string) $pl['name'];
                if (isset($cfg['plan_map'][$k])) {
                    $planMap[$k] = $cfg['plan_map'][$k];
                }
            }
        } catch (Throwable $e) {
            $planMap = $cfg['plan_map'];
        }
        $balance = null;
        try {
            $balance = $this->priceLabels((new WalletService())->getBalance(SocialPackages::owner($this->uid())), $cur, $ar);
        } catch (Throwable $e) {
        }
        $b = $cfg['builder'];
        $builder = $b['enabled'] ? ['channel' => $this->priceLabels((float) $b['channel'], $cur, $ar), 'posts_per_channel' => $b['posts_per_channel'], 'ai_per_channel' => $b['ai_per_channel'], 'max_channels' => $b['max_channels'],
            'posts_block' => ['qty' => $b['posts_block']['qty'], 'price' => $this->priceLabels((float) $b['posts_block']['price'], $cur, $ar)],
            'ai_block' => ['qty' => $b['ai_block']['qty'], 'price' => $this->priceLabels((float) $b['ai_block']['price'], $cur, $ar)],
            'competitors_block' => ['qty' => $b['competitors_block']['qty'], 'price' => $this->priceLabels((float) $b['competitors_block']['price'], $cur, $ar)],
            'monthly_plan' => $this->priceLabels((float) $b['monthly_plan'], $cur, $ar),
            'features' => array_map(fn ($v) => $this->priceLabels((float) $v, $cur, $ar), $b['features']), 'yearly_months' => $b['yearly_months']] : null;
        return $this->success(SocialPackages::summary($this->uid()) + [
            'builder' => $builder, 'platforms_enabled' => $cfg['platforms_enabled'],
            'catalog' => $tiers, 'addons' => $addons, 'plan_map' => $planMap, 'plan_names' => $planNames, 'labels' => SocialPackages::LABELS, 'platform_names' => SocialPackages::PLATFORM_NAMES,
            'balance' => $balance, 'can_buy' => SocialTeam::can($this->team(), 'manage'), 'csrf_token' => Csrf::token(),
        ]);
    }

    private function packageFail(Throwable $e): array
    {
        if ($e instanceof DomainException) {
            $d = json_decode($e->getMessage(), true) ?: [];
            $cur = CurrencyService::forViewer($this->user);
            $msg = 'رصيد محفظتك مش كفاية.';
            if (isset($d['shortfall']) && $d['shortfall'] > 0) {
                $msg .= ' ناقص ' . CurrencyService::format((float) $d['shortfall'], $cur, current_lang() === 'ar') . ' - اشحن المحفظة وكمّل.';
            }
            return $this->error($msg, 402, ['action' => '/subscription#wallet', 'shortfall' => $d['shortfall'] ?? null]);
        }
        if ($e instanceof InvalidArgumentException) {
            return $this->error($e->getMessage(), 422);
        }
        Logger::error('Social package action failed', ['error' => $e->getMessage()]);
        return $this->error('حصلت مشكلة، جرّب تاني أو كلّم الدعم.', 500);
    }

    /** POST /api/social/package/subscribe  body: {tier, cycle, request_key} - الدفع من المحفظة */
    public function packageSubscribe(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($denied = $this->guardOwner()) || ($denied = $this->verifyCsrf()) || ($denied = $this->rateLimitGuard('user', 'social_package_buy', 10, 300))) {
            return $denied;
        }
        try {
            $key = $this->get('request_key');
            $custom = $this->get('custom');
            $r = SocialPackages::subscribe(SocialPackages::owner($this->uid()), (string) $this->get('tier', ''), (string) $this->get('cycle', 'monthly'), is_string($key) && strlen($key) >= 8 ? $key : null, is_array($custom) ? $custom : null, is_string($this->get('promo_code')) ? $this->get('promo_code') : null);
            $msg = ['subscribed' => 'اشتركت في الباقة ✅', 'upgraded' => 'اترقّت باقتك ✅', 'scheduled' => 'التغيير هيتطبق عند التجديد', 'resumed' => 'رجع التجديد التلقائي ✅', 'already' => 'اتنفذ قبل كده'][$r['action']] ?? 'تم';
            return $this->success($r + ['package' => SocialPackages::summary($this->uid())], $msg);
        } catch (Throwable $e) {
            return $this->packageFail($e);
        }
    }

    /** POST /api/social/package/quote  body: {custom} - سعر «فصّل باقتك» من السيرفر (هو المرجع، مش حساب المتصفح) */
    public function packageQuote(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->rateLimitGuard('user', 'social_package_quote', 120, 60)) {
            return $e;
        }
        try {
            $q = SocialPackages::quoteCustom(is_array($this->get('custom')) ? $this->get('custom') : []);
            $ar = current_lang() === 'ar';
            $cur = CurrencyService::forViewer($this->user);
            $q['monthly_label'] = $this->priceLabels($q['monthly'], $cur, $ar);
            $q['yearly_label'] = $this->priceLabels($q['yearly'], $cur, $ar);
            return $this->success($q);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/social/package/promo  body: {tier, cycle, promo_code, custom?} - معاينة السعر بعد الخصم من غير ما يتخصم حاجة */
    public function packagePromo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->rateLimitGuard('user', 'social_package_promo', 30, 60)) {
            return $e;
        }
        try {
            $tier = (string) $this->get('tier', '');
            $cycle = (string) $this->get('cycle', 'monthly');
            $cfg = SocialPackages::config();
            if ($tier === 'custom') {
                $q = SocialPackages::quoteCustom(is_array($this->get('custom')) ? $this->get('custom') : []);
                $price = (float) ($q[$cycle === 'yearly' ? 'yearly' : 'monthly']);
            } elseif (!empty($cfg['tiers'][$tier]['for_sale'])) {
                $price = (float) $cfg['tiers'][$tier]['price_' . ($cycle === 'yearly' ? 'yearly' : 'monthly')];
            } else {
                return $this->error('الباقة دي مش متاحة', 422);
            }
            return $this->success(['original' => $price] + SocialPackages::checkPromo(SocialPackages::owner($this->uid()), (string) $this->get('promo_code', ''), $tier, $cycle, $price));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/social/package/auto-renew  body: {on} */
    public function packageAutoRenew(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($denied = $this->guardOwner()) || ($denied = $this->verifyCsrf())) {
            return $denied;
        }
        try {
            $on = (bool) $this->get('on', false);
            return $this->success(SocialPackages::setAutoRenew(SocialPackages::owner($this->uid()), $on), $on ? 'التجديد التلقائي اشتغل' : 'اتلغى التجديد - الباقة شغالة لآخر المدة');
        } catch (Throwable $e) {
            return $this->packageFail($e);
        }
    }

    /** POST /api/social/package/addon  body: {addon, request_key} */
    public function packageAddon(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($denied = $this->guardOwner()) || ($denied = $this->verifyCsrf()) || ($denied = $this->rateLimitGuard('user', 'social_package_addon', 20, 300))) {
            return $denied;
        }
        try {
            $key = $this->get('request_key');
            return $this->success(SocialPackages::buyAddon(SocialPackages::owner($this->uid()), (string) $this->get('addon', ''), is_string($key) && strlen($key) >= 8 ? $key : null), 'الإضافة اتضافت ✅');
        } catch (Throwable $e) {
            return $this->packageFail($e);
        }
    }

    /** الدفع من محفظة صاحب الحساب: صاحب الحساب أو مدير في فريقه بس */
    private function guardOwner(): ?array
    {
        return SocialTeam::can($this->team(), 'manage') ? null : $this->error('الاشتراك والإضافات من صلاحيات مدير الحساب', 403);
    }

    private function isAdmin(): bool
    {
        return in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    /** GET /api/admin/social-packages */
    public function adminPackages(array $params = []): array
    {
        if (!$this->isAuthenticated() || !$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        header('Cache-Control: private, no-store');
        SocialPackages::ensureSchema();
        $stats = ['active' => 0, 'mrr' => 0.0, 'by_tier' => []];
        try {
            foreach ($this->db->query("SELECT tier, cycle, COUNT(*) AS n, SUM(price_paid) AS s FROM social_subscriptions WHERE status = 'active' AND period_end > UTC_TIMESTAMP() GROUP BY tier, cycle") ?: [] as $r) {
                $stats['active'] += (int) $r['n'];
                $stats['by_tier'][$r['tier']] = ($stats['by_tier'][$r['tier']] ?? 0) + (int) $r['n'];
                $stats['mrr'] += $r['cycle'] === 'yearly' ? (float) $r['s'] / 12 : (float) $r['s'];
            }
        } catch (Throwable $e) {
        }
        $stats['mrr'] = round($stats['mrr'], 2);
        $plans = [];
        try {
            foreach ($this->db->query("SELECT DISTINCT SUBSTRING_INDEX(plan_code, '_', 1) AS k, name FROM subscription_plans WHERE is_active = 1") ?: [] as $r) {
                $plans[$r['k']] = $r['name'];
            }
        } catch (Throwable $e) {
        }
        return $this->success(['config' => SocialPackages::config(), 'defaults' => SocialPackages::DEFAULTS, 'labels' => SocialPackages::LABELS, 'platform_names' => SocialPackages::PLATFORM_NAMES,
            'limit_keys' => SocialPackages::LIMIT_KEYS, 'feature_keys' => SocialPackages::FEATURE_KEYS, 'platform_plans' => $plans, 'stats' => $stats, 'ready' => SocialPackages::ready(), 'economics' => SocialPackages::economics(), 'audit' => SocialPackages::auditLog(30), 'usage_report' => SocialPackages::usageReport(30), 'intel' => (new SocialIntelService())->insights(), 'csrf_token' => Csrf::token()]);
    }

    /** POST /api/admin/social-packages  body: {config} */
    public function adminPackagesSave(array $params = []): array
    {
        if (!$this->isAuthenticated() || !$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'social_packages_admin', 30, 60))) {
            return $e;
        }
        $in = $this->get('config', []);
        return $this->success(['config' => SocialPackages::saveConfig(is_array($in) ? $in : [], $this->uid()), 'economics' => SocialPackages::economics(), 'audit' => SocialPackages::auditLog(30)], 'اتحفظت باقات السوشيال');
    }

    private function hasColumn(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset(self::$colCache[$k])) {
            try {
                // أسماء داخلية ثابتة: بننقّيها ونحطها literal (SHOW ... LIKE ? مش مضمون مع كل إعدادات PDO)
                $t = preg_replace('/[^a-z0-9_]/i', '', $table);
                $c = preg_replace('/[^a-z0-9_]/i', '', $col);
                self::$colCache[$k] = (bool) $this->db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
            } catch (Throwable $e) {
                self::$colCache[$k] = false;
            }
        }
        return self::$colCache[$k];
    }

    /** هاشتاجات من الواجهة: array أو نص مفصول بمسافات/فواصل -> array نضيف (حد أقصى 30) أو null */
    private function cleanHashtags($raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $list = is_array($raw) ? $raw : preg_split('/[\s,،]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];
        foreach ((array) $list as $h) {
            $h = ltrim(trim((string) $h), '#');
            if ($h !== '' && preg_match('/^[\p{L}\p{N}_]{1,60}$/u', $h)) {
                $clean[$h] = $h;
            }
        }
        return $clean ? array_slice(array_values($clean), 0, 30) : null;
    }

    private function cleanFirstComment($raw): ?string
    {
        $c = trim((string) ($raw ?? ''));
        return $c === '' ? null : mb_substr($c, 0, 2000);
    }

    /** GET /api/social/connections - الحسابات المتصلة للنشر عليها */
    public function listConnections(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // كل الاتصالات (حتى اللي فاصلة) عشان العميل يشوف السبب بدل "مفيش صفحات"
            $all = $this->db->query(
                "SELECT id, platform, external_location_id, external_location_name AS name, status, last_error
                 FROM platform_connections
                 WHERE (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND platform IN ('facebook', 'instagram', 'tiktok', 'youtube', 'google_business', 'threads', 'linkedin')
                 ORDER BY (status = 'connected') DESC, id DESC",
                [$this->uid(), $this->uid()]
            );
            $rows = [];
            $broken = [];
            $seen = [];
            // منصة مقفولة على المنصة كلها (زي LinkedIn دلوقتي): حساباتها ما بتظهرش خالص
            $enabled = SocialPackages::config()['platforms_enabled'];
            $all = array_values(array_filter($all ?: [], static fn ($r) => in_array($r['platform'], $enabled, true)));
            foreach ($all as $r) {
                $k = $r['platform'] . ':' . ($r['external_location_id'] ?: 'connection-' . $r['id']);
                if (isset($seen[$k])) {
                    continue; // نفس الصفحة متسجلة أكتر من مرة (موقعين مثلًا) - نعرضها مرة واحدة
                }
                $seen[$k] = true;
                if ($r['status'] === 'connected') {
                    $rows[] = $r;
                } else {
                    $broken[] = ['platform' => $r['platform'], 'name' => $r['name'], 'status' => $r['status'], 'last_error' => $r['last_error'] ? mb_substr((string) $r['last_error'], 0, 300) : null];
                }
            }
            // صور الصفحات وأسماؤها: بنحدّث القديم بس (لحد 4 في الطلب - الباقي بيتحدث في المهمة اليومية)
            try {
                SocialAccounts::refreshStale($this->uid(), 4);
            } catch (Throwable $e) {
            }
            $locked = SocialPackages::locked($this->uid());
            $prof = SocialAccounts::profiles(array_column($rows, 'id'));
            foreach ($rows as &$r0) {
                unset($r0['external_location_id'], $r0['status'], $r0['last_error']);
                $pf = $prof[(int) $r0['id']] ?? [];
                $r0['locked'] = $locked[(int) $r0['id']] ?? null;
                $r0['active'] = !isset($pf['is_active']) || (int) $pf['is_active'] === 1;
                $r0['avatar'] = !empty($pf['avatar_url']) ? (string) $pf['avatar_url'] : null;
                $r0['username'] = $pf['username'] ?? null;
                $r0['followers'] = isset($pf['followers']) ? (int) $pf['followers'] : null;
            }
            unset($r0);
            // Schema-safe: platform_connections in the supplied SQL has no meta_json column.
            // Permission diagnostics belong to the OAuth callback; never assume extra DB fields.
            $missing = [];
            $out = ['connections' => $rows, 'disconnected' => $broken, 'missing_features' => array_values($missing), 'connected_count' => count($rows)];
            // تشخيص: آخر محاولة ربط لاقت كام صفحة (بيتسجل في الكول باك) - بنرجّعه دايمًا
            // دلوقتي (مش بس لما مفيش اتصالات) عشان نعرف Meta رجّعت كام صفحة فعلًا.
            $out['last_connect'] = $_SESSION['meta_last_connect'] ?? null;
            return $this->success($out);
        } catch (Throwable $e) {
            Logger::error('listConnections Error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر جلب الصفحات المتصلة. حاول تحديث الصفحة، وإذا استمرت المشكلة تواصل مع الدعم.', 500);
        }
    }

    /** POST /api/social/connections/{id}/active  body: {active} - العميل بيختار الصفحات اللي بيشتغل عليها */
    public function connectionActive(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($denied = $this->guard('manage')) || ($denied = $this->verifyCsrf()) || ($denied = $this->rateLimitGuard('user', 'social_conn_active', 60, 60))) {
            return $denied;
        }
        $uid = $this->uid();
        $id = (int) ($params['id'] ?? 0);
        $on = (bool) $this->get('active', true);
        try {
            if ($on) {
                $ent = SocialPackages::entitlements($uid);
                $max = $ent['limits']['accounts'];
                $usage = SocialPackages::usage($uid)['accounts'];
                $conn = $this->db->query('SELECT platform FROM platform_connections WHERE id = ? LIMIT 1', [$id])[0] ?? null;
                if ($ent['enforced'] && $max >= 0 && $usage >= $max && $conn && in_array($conn['platform'], $ent['platforms'], true)) {
                    return $this->limitError(new SocialPackageLimit('باقتك فيها ' . $max . ' حسابات وكلهم شغالين - وقّف حساب تاني الأول أو زوّد الحسابات من صفحة الاشتراكات.', 'accounts'));
                }
            }
            SocialAccounts::setActive($uid, $id, $on);
            SocialPackages::flush();
            return $this->success(['active' => $on, 'locked' => SocialPackages::locked($uid)], $on ? 'الحساب اشتغل' : 'الحساب اتوقف من النشر');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /**
     * GET /api/social/history?connection_id=123&limit=30
     * Read-only first batch of existing Meta posts. No publishing, no database writes.
     * Deliberately separate from local posts until historical import storage is migrated.
     */
    public function history(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_history_read', 12, 60)) {
            return $rateError;
        }
        $cid = (int) $this->get('connection_id', 0);
        $limit = max(1, min(50, (int) $this->get('limit', 30)));
        if ($cid < 1) {
            return $this->error('Select a connected Facebook or Instagram account.', 422);
        }
        try {
            $rows = $this->db->query(
                "SELECT id, platform, external_location_id, external_location_name, access_token
                   FROM platform_connections
                  WHERE id = ? AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?))
                    AND status = 'connected' AND platform IN ('facebook','instagram') LIMIT 1",
                [$cid, $this->uid(), $this->uid()]
            );
            $conn = $rows[0] ?? null;
            if (!$conn) {
                return $this->error('Connected account not found or access denied.', 404);
            }
            $externalId = (string) ($conn['external_location_id'] ?? '');
            if ($externalId === '') {
                return $this->error('Account external ID is missing; reconnect this account.', 422);
            }
            $token = (new Encryption())->decrypt((string) $conn['access_token']);
            $api = new MetaSocialAPI($token);
            $platform = (string) $conn['platform'];
            $result = $platform === 'facebook'
                ? $api->getFacebookPostsWithStats($externalId, $token, $limit)
                : $api->getInstagramMediaWithStats($externalId, $token, $limit);
            if (empty($result['success'])) {
                Logger::warning('Historical social read failed', ['connection_id' => $cid, 'platform' => $platform, 'error' => $result['error'] ?? 'unknown']);
                return $this->error('Meta could not return historical posts for this account; check permissions and connection.', 502);
            }
            $posts = [];
            foreach ((array) ($result['posts'] ?? []) as $post) {
                $posts[] = [
                    'external_post_id' => (string) ($post['id'] ?? ''),
                    'text' => (string) ($post['message'] ?? ''),
                    'published_at' => $post['created_time'] ?? null,
                    'permalink' => $post['permalink'] ?? null,
                    'media_type' => $post['type'] ?? 'text',
                    'media_url' => $post['media_url'] ?? null,
                    'likes' => (int) ($post['likes'] ?? 0),
                    'comments' => (int) ($post['comments'] ?? 0),
                    'shares' => $platform === 'facebook' ? (int) ($post['shares'] ?? 0) : null,
                    'source' => 'meta',
                    'read_only' => true,
                ];
            }
            return $this->success([
                'platform' => $platform,
                'connection_id' => $cid,
                'account_name' => (string) $conn['external_location_name'],
                'posts' => $posts,
                'count' => count($posts),
                'scope' => 'recent_batch_only',
                'has_more' => count($posts) >= $limit,
                'note' => 'Read-only preview. Older pages, durable import and dashboard aggregation are not enabled yet.',
            ]);
        } catch (Throwable $e) {
            Logger::error('Historical social read error', ['connection_id' => $cid, 'message' => $e->getMessage()]);
            return $this->error('Historical posts could not be loaded.', 500);
        }
    }

    /** POST /api/social/history/import?connection_id=... - only save external records, never publish. */
    public function importHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->guard('manage')) return $denied;
        if ($limitError = $this->rateLimitGuard('user', 'social_history_import', 6, 60)) return $limitError;
        $preview = $this->history();
        if (empty($preview['success'])) return $preview;
        $data = (array) ($preview['data'] ?? []);
        $accountId = (int) ($data['connection_id'] ?? 0);
        $platform = (string) ($data['platform'] ?? '');
        $count = 0;
        try {
            // Requires RUN_ONCE_SOCIAL_META_HISTORY.sql; fail safely if not installed.
            foreach ((array) ($data['posts'] ?? []) as $post) {
                $externalId = (string) ($post['external_post_id'] ?? '');
                if ($externalId === '') continue;
                $this->db->query(
                    "INSERT INTO social_meta_history (user_id, connection_id, platform, external_post_id, content, media_type, media_url, permalink, published_at, likes, comments, shares, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE content=VALUES(content), media_type=VALUES(media_type), media_url=VALUES(media_url), permalink=VALUES(permalink), likes=VALUES(likes), comments=VALUES(comments), shares=VALUES(shares), fetched_at=UTC_TIMESTAMP()",
                    [$this->uid(), $accountId, $platform, $externalId, (string) ($post['text'] ?? ''), (string) ($post['media_type'] ?? 'text'), (string) ($post['media_url'] ?? ''), (string) ($post['permalink'] ?? ''), !empty($post['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $post['published_at'])) : null, $post['likes'] ?? null, $post['comments'] ?? null, $post['shares'] ?? null]
                );
                $count++;
            }
            return $this->success(['processed' => $count, 'account_id' => $accountId, 'note' => 'Safe upsert of visible batch. No republication.']);
        } catch (Throwable $e) {
            Logger::error('Social meta history import failed', ['connection_id' => $accountId, 'message' => $e->getMessage()]);
            return $this->error('Could not save history. Install RUN_ONCE_SOCIAL_META_HISTORY.sql first.', 500);
        }
    }

    /** GET /api/social/history/saved?connection_id=... */
    public function savedHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $cid = (int) $this->get('connection_id', 0);
        if ($cid <= 0) return $this->error('Select an account', 422);
        try {
            $rows = $this->db->query('SELECT h.platform, h.external_post_id, h.content AS text, h.media_type, h.media_url, h.permalink, h.published_at, h.likes, h.comments, h.shares, h.fetched_at FROM social_meta_history h INNER JOIN platform_connections c ON c.id=h.connection_id WHERE h.user_id=? AND h.connection_id=? AND (c.user_id=? OR c.website_id IN (SELECT id FROM websites WHERE user_id=?)) ORDER BY h.published_at DESC LIMIT 200', [$this->uid(), $cid, $this->uid(), $this->uid()]);
            return $this->success(['posts' => $rows ?: [], 'count' => count($rows ?: [])]);
        } catch (Throwable $e) {
            return $this->error('Could not read saved history. Install migration first.', 500);
        }
    }

    /** Saved Meta history reporting. Deliberately separate from local publishing KPIs. */
    public function historySummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $days = max(1, min(365, (int) $this->get('days', 30)));
        try {
            // Select only records authorized to the logged-in account and deduplicate any
            // duplicated Meta connection records by platform + external post ID.
            $rows = $this->db->query(
                "SELECT h.platform, h.external_post_id, h.connection_id, h.content, h.permalink, h.media_url, h.published_at, h.likes, h.comments, h.shares, h.fetched_at
                 FROM social_meta_history h
                 INNER JOIN platform_connections c ON c.id = h.connection_id
                 WHERE h.user_id = ?
                   AND (c.user_id = ? OR c.website_id IN (SELECT id FROM websites WHERE user_id = ?))
                   AND h.published_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
                 ORDER BY h.fetched_at DESC, h.published_at DESC LIMIT 5000",
                [$this->uid(), $this->uid(), $this->uid(), $days]
            );
            $unique = [];
            foreach ((array) $rows as $row) {
                $key = (string) $row['platform'] . ':' . (string) $row['external_post_id'];
                if (!isset($unique[$key])) $unique[$key] = $row;
            }
            $totals = ['posts' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];
            $available = ['likes' => 0, 'comments' => 0, 'shares' => 0];
            $platforms = [];
            $calendar = [];
            foreach ($unique as $row) {
                $platform = (string) $row['platform'];
                if (!isset($platforms[$platform])) $platforms[$platform] = ['posts' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];
                $totals['posts']++;
                $platforms[$platform]['posts']++;
                foreach (['likes', 'comments', 'shares'] as $metric) {
                    if ($row[$metric] !== null) {
                        $n = max(0, (int) $row[$metric]);
                        $available[$metric]++;
                        $totals[$metric] += $n;
                        $platforms[$platform][$metric] += $n;
                    }
                }
                $day = substr((string) $row['published_at'], 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                    if (!isset($calendar[$day])) $calendar[$day] = ['date' => $day, 'count' => 0, 'posts' => []];
                    $calendar[$day]['count']++;
                    if (count($calendar[$day]['posts']) < 10) {
                        $calendar[$day]['posts'][] = [
                            'platform' => $platform,
                            'text' => mb_substr((string) ($row['content'] ?? ''), 0, 140),
                            'permalink' => $row['permalink'],
                            'published_at' => $row['published_at'],
                        ];
                    }
                }
            }
            krsort($calendar);
            return $this->success([
                'days' => $days, 'totals' => $totals, 'available_counts' => $available,
                'platforms' => $platforms, 'calendar' => array_values($calendar),
                'truncated' => count((array) $rows) >= 5000,
                'note' => 'Saved Meta history only. Separate from locally published posts. Reach is not available here.',
            ]);
        } catch (Throwable $e) {
            Logger::error('Historical social summary failed', ['user_id' => $this->uid(), 'message' => $e->getMessage()]);
            return $this->error('Could not load saved history summary; verify the history table is installed.', 500);
        }
    }

    /** GET /api/social/posts - كل منشور ومعاه نتيجة كل صفحة (اتنشر/فشل + السبب + الإحصائيات) - فلاتر: status, platform, q */
    public function listPosts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $this->catchUpOverdue($this->uid());

        $where = ['sp.user_id = ?'];
        $args = [$this->uid()];
        $status = (string) $this->get('status', '');
        if (in_array($status, ['draft', 'scheduled', 'publishing', 'published', 'failed', 'partial', 'uncertain'], true)) {
            $where[] = 'sp.status = ?';
            $args[] = $status;
        }
        $platform = (string) $this->get('platform', '');
        if (in_array($platform, ['facebook', 'instagram', 'tiktok', 'youtube', 'google_business', 'threads', 'linkedin'], true)) {
            $where[] = 'EXISTS (SELECT 1 FROM social_post_targets tt JOIN platform_connections cc ON cc.id = tt.platform_connection_id WHERE tt.social_post_id = sp.id AND cc.platform = ?)';
            $args[] = $platform;
        }
        $q = trim((string) $this->get('q', ''));
        if ($q !== '') {
            $where[] = 'sp.content LIKE ?';
            $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        }
        $limit = max(10, min(100, (int) $this->get('limit', 50)));

        $posts = $this->db->query(
            'SELECT sp.id, sp.content, sp.status, sp.media_item_id, sp.created_at, ' . (SocialPostService::proSchema() ? 'sp.post_format' : "'feed' AS post_format") . (SocialPostService::growthSchema() ? ', sp.approval_state, sp.review_note, sp.created_by, (SELECT COALESCE(NULLIF(u.display_name, \'\'), u.first_name, u.email) FROM users u WHERE u.id = sp.created_by) AS author' : '') . ', m.file_path AS media_path, m.type AS media_type, m.user_id AS media_owner, m.deleted_at AS media_deleted, m.status AS media_status
               FROM social_posts sp LEFT JOIN media_items m ON m.id = sp.media_item_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY sp.created_at DESC, sp.id DESC LIMIT ' . $limit,
            $args
        );
        foreach ($posts as &$mediaPost) {
            if ((int)($mediaPost['media_owner'] ?? 0) !== $this->uid() || !empty($mediaPost['media_deleted']) || ($mediaPost['media_status'] ?? '') !== 'completed') {
                $mediaPost['media_path'] = null;
            } elseif (PrivateMedia::valid((string)($mediaPost['media_path'] ?? ''))) {
                $mediaPost['media_path'] = PrivateMedia::preview((int)$mediaPost['media_item_id']);
            }
            unset($mediaPost['media_owner'], $mediaPost['media_deleted'], $mediaPost['media_status']);
        }
        unset($mediaPost);
        $ids = array_map(fn ($p) => (int) $p['id'], $posts);
        $byPost = [];
        if ($ids) {
            $extraT = $this->hasColumn('social_post_targets', 'error_category') ? ', t.error_category, t.error_code, t.retry_count, t.next_retry_at, t.fbtrace_id' : '';
            $extraM = ($this->hasColumn('social_post_metrics', 'views') ? ', pm.views, pm.insights_note' : '') . ($this->hasColumn('social_post_metrics', 'saves') ? ', pm.saves' : '');
            $rows = $this->db->query(
                'SELECT t.id AS target_id, t.social_post_id, t.status, t.last_error, t.scheduled_at, t.published_at, t.external_post_id' . $extraT . ',
                        c.platform, c.external_location_name AS name,
                        pm.likes, pm.comments, pm.shares, pm.reach, pm.state AS metrics_state, pm.note AS metrics_note, pm.permalink AS metrics_permalink, pm.fetched_at AS metrics_at' . $extraM . '
                   FROM social_post_targets t
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id
                  WHERE t.social_post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.id',
                $ids
            );
            foreach ($rows as $r) {
                $r['permalink'] = !empty($r['metrics_permalink']) ? $r['metrics_permalink'] : self::permalink((string) $r['platform'], (string) ($r['external_post_id'] ?? ''));
                unset($r['external_post_id'], $r['metrics_permalink']);
                $byPost[(int) $r['social_post_id']][] = $r;
            }
        }
        $attr = SocialAttribution::forPosts($this->uid(), $ids);
        foreach ($posts as &$p) {
            $p['targets'] = $byPost[(int) $p['id']] ?? [];
            $p['attribution'] = $attr[(int) $p['id']] ?? null;
            if ($p['targets'] && $p['status'] === 'scheduled') {
                // منشورات قديمة: الحالة ماكانتش بتتحدث - نحسبها من الأهداف
                $p['status'] = SocialPostService::refreshPostStatus((int) $p['id']);
            }
        }
        unset($p);
        return $this->success(['posts' => $posts]);
    }

    /**
     * GET /api/social/stats?days=30
     * أرقام الفترة: عدد المنشورات حسب الحالة والمنصة، النشر اليومي، إجمالي التفاعل (من الكاش)،
     * أفضل المنشورات، وعينات أوقات النشر (الواجهة بتحسب منها أفضل ميعاد بتوقيت العميل).
     */
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $days = max(7, min(90, (int) $this->get('days', 30)));

        try {
            $byStatus = ['draft' => 0, 'scheduled' => 0, 'publishing' => 0, 'published' => 0, 'failed' => 0];
            foreach ($this->db->query('SELECT status, COUNT(*) AS c FROM social_posts WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY status', [$uid, $days]) as $r) {
                $byStatus[(string) $r['status']] = (int) $r['c'];
            }

            $byPlatform = [];
            foreach ($this->db->query(
                "SELECT c.platform, t.status, COUNT(*) AS c
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE sp.user_id = ? AND sp.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY c.platform, t.status",
                [$uid, $days]
            ) as $r) {
                $k = (string) ($r['platform'] ?: 'other');
                $byPlatform[$k] = $byPlatform[$k] ?? ['published' => 0, 'failed' => 0, 'scheduled' => 0, 'total' => 0];
                $bucket = in_array($r['status'], ['published', 'failed'], true) ? $r['status'] : 'scheduled';
                $byPlatform[$k][$bucket] += (int) $r['c'];
                $byPlatform[$k]['total'] += (int) $r['c'];
            }

            $daily = [];
            foreach ($this->db->query(
                "SELECT DATE(t.published_at) AS d, COUNT(*) AS c
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY DATE(t.published_at)",
                [$uid, $days]
            ) as $r) {
                $daily[(string) $r['d']] = (int) $r['c'];
            }
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} day"));
                $series[] = ['date' => $d, 'count' => $daily[$d] ?? 0];
            }

            $eng = $this->db->query(
                "SELECT COALESCE(SUM(pm.likes),0) AS likes, COALESCE(SUM(pm.comments),0) AS comments, COALESCE(SUM(pm.shares),0) AS shares,
                        COALESCE(SUM(pm.reach),0) AS reach, COUNT(pm.target_id) AS measured, MAX(pm.fetched_at) AS last_fetch
                   FROM social_post_metrics pm
                   JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
                  WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$uid, $days]
            )[0] ?? [];

            $top = $this->db->query(
                "SELECT sp.id, sp.content, c.platform, c.external_location_name AS name, pm.likes, pm.comments, pm.shares, pm.reach,
                        (pm.likes + pm.comments * 2 + pm.shares * 3) AS score
                   FROM social_post_metrics pm
                   JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  ORDER BY score DESC, sp.id DESC LIMIT 5",
                [$uid, $days]
            );
            foreach ($top as &$t0) {
                $t0['content'] = mb_substr((string) $t0['content'], 0, 90);
            }
            unset($t0);

            // عينات للأوقات (UTC) + وزن التفاعل لو متاح - آخر 300 نشر
            $samples = $this->db->query(
                "SELECT t.published_at AS at, COALESCE(pm.likes + pm.comments * 2 + pm.shares * 3, 0) AS score
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id AND pm.state = 'ok'
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at IS NOT NULL
                  ORDER BY t.published_at DESC LIMIT 300",
                [$uid]
            );

            $published = (int) ($byStatus['published'] ?? 0);
            $failed = (int) ($byStatus['failed'] ?? 0);
            $tPub = 0;
            $tFail = 0;
            foreach ($byPlatform as $v) {
                $tPub += $v['published'];
                $tFail += $v['failed'];
            }
            return $this->success([
                'days' => $days,
                'by_status' => $byStatus,
                'total_posts' => array_sum($byStatus),
                'success_rate' => ($tPub + $tFail) > 0 ? round($tPub / ($tPub + $tFail) * 100) : null,
                'by_platform' => $byPlatform,
                'daily' => $series,
                'engagement' => [
                    'likes' => (int) ($eng['likes'] ?? 0),
                    'comments' => (int) ($eng['comments'] ?? 0),
                    'shares' => (int) ($eng['shares'] ?? 0),
                    'reach' => (int) ($eng['reach'] ?? 0),
                    'measured' => (int) ($eng['measured'] ?? 0),
                    'last_fetch' => $eng['last_fetch'] ?? null,
                ],
                'top_posts' => $top,
                'samples' => $samples,
                'bookings' => SocialAttribution::totals($uid, gmdate('Y-m-d H:i:s', time() - $days * 86400), gmdate('Y-m-d H:i:s', time() + 60)),
            ]);
        } catch (Throwable $e) {
            Logger::error('Social stats error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر تحميل الإحصائيات. تأكد إن آخر migration اتشغّلت (social_post_metrics).', 500);
        }
    }

    /**
     * POST /api/social/metrics/refresh - يسحب إحصائيات المنشورات المنشورة على فيسبوك/إنستجرام من Meta ويخزّنها.
     * بحد أقصى 15 هدف في المرة، ومبيعيدش سحب اللي اتحدّث من أقل من 30 دقيقة (إلا لو force=1).
     */
    public function refreshMetrics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfDenied = $this->verifyCsrf()) {
            return $csrfDenied;
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_metrics', 6, 60)) {
            return $rateError;
        }
        $uid = $this->uid();
        $force = (bool) $this->get('force', false);
        @set_time_limit(90);

        try {
            $rows = $this->db->query(
                "SELECT t.id, t.external_post_id, c.platform, c.access_token
                   FROM social_post_targets t
                   JOIN social_posts sp ON sp.id = t.social_post_id
                   JOIN platform_connections c ON c.id = t.platform_connection_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.external_post_id IS NOT NULL AND t.external_post_id <> ''
                    AND c.platform IN ('facebook','instagram') AND c.status = 'connected'
                    " . ($force ? '' : "AND (pm.fetched_at IS NULL OR pm.fetched_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))") . "
                  ORDER BY t.published_at DESC LIMIT 15",
                [$uid]
            );
            $encryption = new Encryption();
            $ok = 0;
            $unavailable = 0;
            foreach ($rows as $r) {
                $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
                try {
                    $token = $encryption->decrypt((string) $r['access_token']);
                    $res = (new MetaSocialAPI($token))->getPostMetrics((string) $r['platform'], (string) $r['external_post_id'], $token);
                } catch (Throwable $e) {
                    $res['error'] = CustomerError::message($e->getMessage(), 500);
                }
                if ($res['success']) {
                    $ok++;
                    if ($this->hasColumn('social_post_metrics', 'saves')) {
                        $this->db->query(
                            "INSERT INTO social_post_metrics (target_id, likes, comments, shares, reach, views, saves, permalink, state, note, insights_note, fetched_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ok', NULL, ?, NOW())
                             ON DUPLICATE KEY UPDATE likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares),
                                 reach = VALUES(reach), views = VALUES(views), saves = VALUES(saves), permalink = VALUES(permalink), state = 'ok', note = NULL,
                                 insights_note = VALUES(insights_note), fetched_at = NOW()",
                            [(int) $r['id'], $res['likes'], $res['comments'], $res['shares'], $res['reach'], $res['views'] ?? null, $res['saves'] ?? null, $res['permalink'], isset($res['insights_error']) ? mb_substr((string) $res['insights_error'], 0, 290) : null]
                        );
                    } elseif ($this->hasColumn('social_post_metrics', 'views')) {
                        // reach هنا = مشاهدون فريدون (post_total_media_view_unique)؛ insights_note بيشرح ليه الرقم فاضي بدل null صامت
                        $this->db->query(
                            "INSERT INTO social_post_metrics (target_id, likes, comments, shares, reach, views, permalink, state, note, insights_note, fetched_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'ok', NULL, ?, NOW())
                             ON DUPLICATE KEY UPDATE likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares),
                                 reach = VALUES(reach), views = VALUES(views), permalink = VALUES(permalink), state = 'ok', note = NULL,
                                 insights_note = VALUES(insights_note), fetched_at = NOW()",
                            [(int) $r['id'], $res['likes'], $res['comments'], $res['shares'], $res['reach'], $res['views'] ?? null, $res['permalink'], isset($res['insights_error']) ? mb_substr((string) $res['insights_error'], 0, 290) : null]
                        );
                    } else {
                        $this->db->query(
                            "INSERT INTO social_post_metrics (target_id, likes, comments, shares, reach, permalink, state, note, fetched_at)
                             VALUES (?, ?, ?, ?, ?, ?, 'ok', NULL, NOW())
                             ON DUPLICATE KEY UPDATE likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares),
                                 reach = VALUES(reach), permalink = VALUES(permalink), state = 'ok', note = NULL, fetched_at = NOW()",
                            [(int) $r['id'], $res['likes'], $res['comments'], $res['shares'], $res['reach'], $res['permalink']]
                        );
                    }
                } else {
                    $unavailable++;
                    $this->db->query(
                        "INSERT INTO social_post_metrics (target_id, state, note, fetched_at) VALUES (?, 'unavailable', ?, NOW())
                         ON DUPLICATE KEY UPDATE state = 'unavailable', note = VALUES(note), fetched_at = NOW()",
                        [(int) $r['id'], mb_substr((string) ($res['error'] ?? ''), 0, 290)]
                    );
                }
            }
            return $this->success(['updated' => $ok, 'unavailable' => $unavailable, 'checked' => count($rows)]);
        } catch (Throwable $e) {
            Logger::error('Social metrics refresh error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر تحديث الإحصائيات. تأكد إن migration الإحصائيات اتشغّلت.', 500);
        }
    }

    /** POST /api/social/posts/{id}/duplicate - نسخة مسودة من منشور (نفس النص والوسائط) */
    public function duplicatePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfDenied = $this->verifyCsrf()) {
            return $csrfDenied;
        }
        if ($denied = $this->guard('draft')) {
            return $denied;
        }
        $uid = $this->uid();
        $postId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query('SELECT content, media_item_id, website_id, hashtags FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid]);
        if (!$rows) {
            return $this->error('المنشور مش موجود', 404);
        }
        $src = $rows[0];
        $hashtags = $src['hashtags'] ? json_decode((string) $src['hashtags'], true) : null;
        try {
            $post = $this->service->createPost($uid, [
                'content' => $src['content'],
                'website_id' => $src['website_id'],
                'media_item_id' => $src['media_item_id'] ?: null,
                'hashtags' => is_array($hashtags) ? $hashtags : null,
            ], []);
            return $this->success(['post' => $post->toArray()], 'اتعمل نسخة كمسودة', 201);
        } catch (Throwable $e) {
            Logger::error('duplicatePost error', ['message' => $e->getMessage()]);
            return $this->error('تعذر عمل نسخة من المنشور', 500);
        }
    }

    /**
     * POST /api/social/posts/{id}/update - تعديل نص منشور لسه ما اتنشرش (مسودة/مجدول)، وتغيير موعد الأهداف المجدولة.
     * body: {content?, scheduled_at?}  (scheduled_at بـ UTC ISO، أو "" لعدم التغيير)
     */
    public function updatePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfDenied = $this->verifyCsrf()) {
            return $csrfDenied;
        }
        if ($denied = $this->guard('publish')) {
            return $denied;
        }
        $uid = $this->uid();
        $postId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query('SELECT id, status FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid]);
        if (!$rows) {
            return $this->error('المنشور مش موجود', 404);
        }
        if (!in_array($rows[0]['status'], ['draft', 'scheduled', 'failed'], true)) {
            return $this->error('مينفعش تعدّل منشور اتنشر أو بيتنشر دلوقتي', 422);
        }
        $content = trim((string) $this->get('content', ''));
        if ($content === '') {
            return $this->error('Post text is required', 422);
        }
        $at = null;
        $atRaw = trim((string) $this->get('scheduled_at', ''));
        if ($atRaw !== '') {
            try{$ts=InternationalSettingsService::scheduledTimestamp($this->uid(),$atRaw);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
            if ($ts === false || $ts < time() - 60 || $ts > time() + 366 * 86400) {
                return $this->error('موعد النشر لازم يكون في المستقبل (وخلال سنة)', 422);
            }
            $at = gmdate('Y-m-d H:i:s', $ts);
        }
        try {
            $this->db->query('UPDATE social_posts SET content = ? WHERE id = ?', [$content, $postId]);
            if ($at !== null) {
                $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'scheduled'", [$postId]);
                $queue = Container::getInstance()->make(QueueManager::class);
                foreach ($targets as $t) {
                    $this->db->query('UPDATE social_post_targets SET scheduled_at = ? WHERE id = ?', [$at, (int) $t['id']]);
                    // نمسح مهمة النشر القديمة (المعلّقة) الأول، وإلا هتنشر بالموعد القديم ويتنشر المنشور مرتين
                    $this->db->query(
                        "DELETE FROM jobs WHERE job_class = 'PublishSocialPostJob' AND status = 'pending' AND payload LIKE ?",
                        ['%"social_post_target_id":' . (int) $t['id'] . '}%']
                    );
                    $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => (int) $t['id']], 'social', max(0, strtotime($at . ' UTC') - time()));
                }
            }
            return $this->success([], 'اتحفظ التعديل');
        } catch (Throwable $e) {
            Logger::error('updatePost error', ['message' => $e->getMessage()]);
            return $this->error('Could not Save edit', 500);
        }
    }

    /**
     * لو كرون الطابور مش شغال على السيرفر، المنشورات المجدولة كانت بتفضل "مجدول" للأبد.
     * لما العميل يفتح الصفحة بننفّذ اللي ميعاده عدّى (بحد أقصى 3 في المرة).
     */
    private function catchUpOverdue(int $uid): void
    {
        try {
            $due = $this->db->query(
                "SELECT t.id FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id
                  WHERE p.user_id = ? AND t.status = 'scheduled' AND (t.scheduled_at IS NULL OR t.scheduled_at <= UTC_TIMESTAMP() - INTERVAL 2 MINUTE)
                    AND t.created_at <= NOW() - INTERVAL 2 MINUTE
                  ORDER BY t.id LIMIT 3",
                [$uid]
            );
            $jobs = [];
            foreach ($due as $t) {
                $j = $this->db->query(
                    "SELECT id FROM jobs WHERE job_class = 'PublishSocialPostJob' AND status = 'pending' AND payload LIKE ? ORDER BY id LIMIT 1",
                    ['%"social_post_target_id":' . (int) $t['id'] . '}%']
                );
                if ($j) {
                    $jobs[] = (int) $j[0]['id'];
                }
            }
            SocialPostService::runNow($jobs);
        } catch (Throwable $e) {
            Logger::warning('social catch-up skipped', ['error' => CustomerError::message($e->getMessage(), 500)]);
        }
    }

    private static function permalink(string $platform, string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        if ($platform === 'facebook') {
            return 'https://www.facebook.com/' . rawurlencode($id);
        }
        return null;
    }

    /** POST /api/social/posts */
    public function createPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfDenied = $this->verifyCsrf()) {
            return $csrfDenied;
        }
        if ($denied = $this->guard('draft')) {
            return $denied;
        }
        // الستوري ما لهاش نص على المنصة: النص هنا ملاحظة داخلية اختيارية
        if ((string) $this->get('post_format', '') === 'story' && trim((string) $this->get('content', '')) === '') {
            $this->data['content'] = 'Story';
        }
        if (!$this->validate(['content' => 'required'])) {
            return $this->error('Missing data', 422, $this->getErrors());
        }

        $uid = $this->uid();
        // 🔒 الصفحات لازم تكون بتاعة العميل نفسه، والموعد بيتحفظ UTC
        $targets = [];
        foreach ((array) $this->get('targets', []) as $t) {
            $cid = (int) ($t['platform_connection_id'] ?? 0);
            $ok = $cid > 0 && $this->db->query(
                "SELECT id FROM platform_connections WHERE id = ? AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND platform IN ('facebook','instagram','tiktok','youtube','google_business','threads','linkedin') AND status = 'connected' LIMIT 1",
                [$cid, $uid, $uid]
            );
            if (!$ok) {
                return $this->error('This publishing page doesn\'t exist or isn\'t connected', 422);
            }
            $at = null;
            if (!empty($t['scheduled_at'])) {
                try{$ts=InternationalSettingsService::scheduledTimestamp($uid,(string)$t['scheduled_at']);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
                if ($ts === false) {
                    return $this->error('Invalid publish time', 422);
                }
                if ($ts < time() - 60) {
                    return $this->error('Publish time must be in the future', 422);
                }
                if ($ts > time() + 366 * 86400) {
                    return $this->error('Publish time is too far ahead (max 1 year)', 422);
                }
                $at = gmdate('Y-m-d H:i:s', $ts);
            }
            $targets[] = ['platform_connection_id' => $cid, 'scheduled_at' => $at];
        }
        // تيك توك: العميل لازم يختار الخصوصية والإعدادات بنفسه من شاشة النشر (شرط من تيك توك)
        $tiktokIds = $targets ? array_column($this->db->query("SELECT id FROM platform_connections WHERE platform = 'tiktok' AND id IN (" . implode(',', array_map('intval', array_column($targets, 'platform_connection_id'))) . ")") ?: [], 'id') : [];
        $tiktokOpts = null;
        if ($tiktokIds) {
            $tiktokOpts = TikTokAPI::cleanOptions($this->get('tiktok'));
            if (isset($tiktokOpts['error'])) {
                return $this->error($tiktokOpts['error'], 422);
            }
        }
        $rawMediaId = $this->get('media_item_id', 0);
        if ($rawMediaId !== null && (!is_scalar($rawMediaId) || !preg_match('/^[0-9]+$/', (string)$rawMediaId))) {
            return $this->error('Image/video isn\'t ready or doesn\'t exist', 422);
        }
        $mediaId = (int) $rawMediaId;
        if ($mediaId > 0 && !$this->db->query("SELECT id FROM media_items WHERE id = ? AND user_id = ? AND status = 'completed' AND deleted_at IS NULL LIMIT 1", [$mediaId, $uid])) {
            return $this->error('Image/video isn\'t ready or doesn\'t exist', 422);
        }
        // صور متعددة (2-10): ألبوم فيسبوك / كاروسيل إنستجرام. تيك توك ويوتيوب فيديو بس.
        $albumIds = [];
        try {
            $albumIds = SocialPostService::normalizeAlbumIds($this->get('media_item_ids'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        if ($albumIds) {
            $in = implode(',', array_map('intval', $albumIds));
            $okCount = (int) (($this->db->query("SELECT COUNT(*) AS n FROM media_items WHERE id IN ({$in}) AND user_id = ? AND status = 'completed' AND deleted_at IS NULL AND type <> 'short_video'", [$uid])[0]['n'] ?? 0));
            if ($okCount !== count($albumIds)) {
                return $this->error('إحدى الصور غير جاهزة أو مش موجودة، أو فيه فيديو وسط الصور', 422);
            }
            if ($targets) {
                $cids = implode(',', array_map('intval', array_column($targets, 'platform_connection_id')));
                if ($this->db->query("SELECT id FROM platform_connections WHERE id IN ({$cids}) AND platform IN ('tiktok','youtube') LIMIT 1")) {
                    return $this->error('الصور المتعددة متاحة لفيسبوك وإنستجرام بس (تيك توك ويوتيوب فيديو فقط)', 422);
                }
            }
            $mediaId = $albumIds[0];
        }

        // الستوري: صورة/فيديو واحد على فيسبوك وإنستجرام بس
        $format = (string) $this->get('post_format', 'feed') === 'story' ? 'story' : 'feed';
        if ($format === 'story') {
            if ($mediaId <= 0 || $albumIds) {
                return $this->error('الستوري محتاجة صورة أو فيديو واحد بالظبط', 422);
            }
            if ($targets) {
                $cids = implode(',', array_map('intval', array_column($targets, 'platform_connection_id')));
                if ($this->db->query("SELECT id FROM platform_connections WHERE id IN ({$cids}) AND platform NOT IN ('facebook','instagram') LIMIT 1")) {
                    return $this->error('الستوري متاحة لفيسبوك وإنستجرام بس', 422);
                }
            }
        }
        $websiteId = (int) $this->get('website_id', 0);
        if ($websiteId > 0 && !$this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $uid])) {
            $websiteId = 0;
        }
        $utm = $this->get('utm_campaign', '');
        $utm = is_string($utm) ? SocialLinkTracker::slug($utm) : '';

        // عضو الفريق اللي ما يقدرش ينشر (مبيعات/دعم): المنشور بيتحفظ مسودة "منتظر الاعتماد" والمدير ينشره بنقرة
        $needsApproval = $targets && !SocialTeam::can($this->team(), 'publish');
        if ($needsApproval && !SocialPostService::growthSchema()) {
            return $this->error('شغّل migration السوشيال (2026_10_21_000001_social_growth.sql) عشان أعضاء الفريق يبعتوا منشورات للاعتماد', 422);
        }
        $approvalData = $needsApproval
            ? ['approval_state' => 'pending', 'requested_targets_json' => json_encode(['targets' => $targets, 'tiktok' => $tiktokOpts]), 'created_by' => (int) ($this->user['id'] ?? 0)]
            : (SocialPostService::growthSchema() ? ['created_by' => (int) ($this->user['id'] ?? 0)] : []);

        if ($this->get('track_links', false) && $format !== 'story' && !SocialPackages::has($uid, 'attribution')) {
            return $this->limitError(new SocialPackageLimit('«' . SocialPackages::LABELS['attribution'][0] . '» مش ضمن باقتك - شيل علامة "روابط قصيرة لتتبع الحجوزات" أو رقّي من صفحة الاشتراكات.', 'attribution'));
        }
        try {
            $cids = array_column($targets, 'platform_connection_id');
            // عضو الفريق: نتأكد بس إن الحسابات مش مقفولة (المنشورات بتتحسب لما المدير يعتمد)
            $needsApproval ? SocialPackages::assertConnections($uid, $cids) : SocialPackages::assertPublish($uid, $cids, count($targets));
        } catch (SocialPackageLimit $e) {
            return $this->limitError($e);
        }

        try {
            $post = $this->service->createPost(
                $uid,
                // كان media_item_id بيتشال هنا، فاللي جاي من Creative Studio كان بيتنشر نص بس
                ['content' => $this->get('content'), 'website_id' => $websiteId ?: null, 'media_item_id' => $mediaId ?: null,
                 'media_item_ids' => $albumIds ?: null,
                 'hashtags' => $this->cleanHashtags($this->get('hashtags')), 'first_comment' => $this->cleanFirstComment($this->get('first_comment')),
                 'post_format' => $format, 'utm_campaign' => $utm, 'track_links' => $format !== 'story' && (bool) $this->get('track_links', false)] + $approvalData,
                $needsApproval ? [] : $targets
            );
            if ($needsApproval) {
                foreach (SocialTeam::approvers($uid) as $approver) {
                    try {
                        Notification::notify($approver, 'social_post_approval', 'منشور محتاج اعتمادك', ($this->user['name'] ?? $this->user['email'] ?? 'عضو في الفريق') . ': «' . mb_substr((string) $this->get('content'), 0, 80) . '»', '/social#posts');
                    } catch (Throwable $e) {
                    }
                }
                $out = $post->toArray();
                $out['approval_state'] = 'pending';
                return $this->success(['post' => $out, 'pending_approval' => true], 'اتبعت للمدير يعتمده', 201);
            }
            if ($tiktokOpts) {
                $pdo = $this->db->getConnection();
                foreach ($this->db->query('SELECT id FROM social_post_targets WHERE social_post_id = ? AND platform_connection_id IN (' . implode(',', array_map('intval', $tiktokIds)) . ')', [(int) $post->getAttribute('id')]) ?: [] as $row) {
                    TikTokAPI::saveOptions($pdo, (int) $row['id'], $tiktokOpts);
                }
            }
            SocialPackages::record($uid, 'posts', count($targets));
            // النشر الفوري بيتنفذ في نفس الطلب (مش مستني الكرون)
            SocialPostService::runNow($this->service->immediateJobIds);
            $postId = (int) $post->getAttribute('id');
            $out = $post->toArray();
            if ($targets) {
                $out['status'] = SocialPostService::refreshPostStatus($postId);
            }
            return $this->success(['post' => $out], 'Post saved', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            Logger::error('createPost Error', ['message' => $e->getMessage()]);
            return $this->error('Could not create the post', 500);
        }
    }

    /**
     * POST /api/social/posts/{id}/review  body: {decision: approve|reject, note}
     * المدير بيعتمد منشور عضو الفريق (بيتنشر/يتجدول بنفس الصفحات والمواعيد اللي اختارها العضو) أو يرفضه بملاحظة.
     */
    public function reviewPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->guard('publish')) {
            return $denied;
        }
        if ($error = $this->verifyCsrf()) {
            return $error;
        }
        $uid = $this->uid();
        $postId = (int) ($params['id'] ?? 0);
        if (!SocialPostService::growthSchema()) {
            return $this->error('شغّل migration السوشيال (2026_10_21_000001_social_growth.sql) الأول', 422);
        }
        $row = $this->db->query("SELECT id, approval_state, requested_targets_json, created_by, content FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1", [$postId, $uid])[0] ?? null;
        if (!$row || $row['approval_state'] !== 'pending') {
            return $this->error('المنشور مش منتظر اعتماد', 422);
        }
        $decision = (string) $this->get('decision', '');
        $note = mb_substr(trim((string) $this->get('note', '')), 0, 500);
        $reviewer = (int) ($this->user['id'] ?? 0);
        if ($decision === 'reject') {
            $this->db->query("UPDATE social_posts SET approval_state = 'rejected', reviewed_by = ?, review_note = ? WHERE id = ?", [$reviewer, $note ?: null, $postId]);
            if ((int) $row['created_by']) {
                try {
                    Notification::notify((int) $row['created_by'], 'social_post_rejected', 'منشورك محتاج تعديل', ($note ?: 'المدير رجّع المنشور للتعديل') . ' — «' . mb_substr((string) $row['content'], 0, 60) . '»', '/social#posts');
                } catch (Throwable $e) {
                }
            }
            return $this->success(['approval_state' => 'rejected']);
        }
        if ($decision !== 'approve') {
            return $this->error('القرار لازم يكون approve أو reject', 422);
        }
        $req = json_decode((string) $row['requested_targets_json'], true) ?: [];
        $targets = [];
        foreach ((array) ($req['targets'] ?? []) as $t) {
            $cid = (int) ($t['platform_connection_id'] ?? 0);
            if (!$cid || !$this->db->query("SELECT id FROM platform_connections WHERE id = ? AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND status = 'connected' LIMIT 1", [$cid, $uid, $uid])) {
                return $this->error('صفحة من الصفحات المختارة اتفصلت - اربطها تاني أو عدّل المنشور', 422);
            }
            $at = $t['scheduled_at'] ?? null;
            // الميعاد اللي عدّى وقت ما المدير بيعتمد: يتنشر دلوقتي بدل ما يضيع
            $targets[] = ['platform_connection_id' => $cid, 'scheduled_at' => ($at && strtotime($at . ' UTC') > time() + 60) ? $at : null];
        }
        if (!$targets) {
            return $this->error('المنشور مالوش صفحات نشر', 422);
        }
        try {
            SocialPackages::assertPublish($uid, array_column($targets, 'platform_connection_id'), count($targets));
        } catch (SocialPackageLimit $e) {
            return $this->limitError($e);
        }
        try {
            $this->service->addTargets($postId, $targets);
            SocialPackages::record($uid, 'posts', count($targets));
            if (!empty($req['tiktok'])) {
                foreach ($this->db->query("SELECT t.id FROM social_post_targets t JOIN platform_connections c ON c.id = t.platform_connection_id WHERE t.social_post_id = ? AND c.platform = 'tiktok'", [$postId]) ?: [] as $tt) {
                    TikTokAPI::saveOptions($this->db->getConnection(), (int) $tt['id'], $req['tiktok']);
                }
            }
            $this->db->query("UPDATE social_posts SET approval_state = 'approved', reviewed_by = ?, review_note = ? WHERE id = ?", [$reviewer, $note ?: null, $postId]);
            SocialPostService::runNow($this->service->immediateJobIds);
        } catch (Throwable $e) {
            Logger::error('reviewPost approve failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر اعتماد المنشور', 500);
        }
        if ((int) $row['created_by'] && (int) $row['created_by'] !== $reviewer) {
            try {
                Notification::notify((int) $row['created_by'], 'social_post_approved', 'منشورك اتعتمد ✅', '«' . mb_substr((string) $row['content'], 0, 60) . '»', '/social#posts');
            } catch (Throwable $e) {
            }
        }
        return $this->success(['approval_state' => 'approved', 'status' => SocialPostService::refreshPostStatus($postId)]);
    }

    /**
     * GET /api/social/calendar
     * تقويم محتوى حقيقي مبني على social_posts + social_post_targets
     * الموجودين بالفعل (عمود scheduled_at). ده بديل عن جدول
     * content_calendar المنفصل اللي كان في موديول content-studio -
     * مفيش داعي لتكراره لأن البيانات موجودة أصلًا هنا.
     */
    public function getCalendar(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        require_once __DIR__ . '/../Services/SocialMedia/SocialCalendarWindow.php';
        $window = SocialCalendarWindow::bounds();
        try {
            $rows = $this->db->query(
                "SELECT sp.id, sp.content, COALESCE(spt.scheduled_at, spt.published_at) AS scheduled_at, spt.status, spt.published_at, pc.platform
                 FROM social_posts sp
                 JOIN social_post_targets spt ON spt.social_post_id = sp.id
                 LEFT JOIN platform_connections pc ON pc.id = spt.platform_connection_id
                 WHERE sp.user_id = ? AND COALESCE(spt.scheduled_at, spt.published_at) IS NOT NULL
                 AND COALESCE(spt.scheduled_at, spt.published_at) >= ? AND COALESCE(spt.scheduled_at, spt.published_at) < ?
                 ORDER BY COALESCE(spt.scheduled_at, spt.published_at) ASC",
                [$this->uid(), $window['from'], $window['until']]
            );
            return $this->success(['items' => $rows, 'window' => $window]);
        } catch (Exception $e) {
            Logger::error('Social Calendar Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load the calendar', 500);
        }
    }

    /** POST /api/social/generate-caption */
    public function generateCaption(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfDenied = $this->verifyCsrf()) {
            return $csrfDenied;
        }
        if ($denied = $this->guard('draft')) {
            return $denied;
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['topic' => 'required'])) {
            return $this->error('Topic is required', 422);
        }

        $uid = $this->uid();
        $wid = (int) $this->get('website_id', 0) ?: null;
        if ($wid && !$this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $uid])) {
            $wid = null;
        }
        $platforms = $this->get('platforms', []);
        $platforms = is_array($platforms) ? array_values(array_filter($platforms, 'is_string')) : [];
        if (!$platforms && is_string($this->get('platform'))) {
            $platforms = [(string) $this->get('platform')];
        }
        $language = (string) $this->get('language', '');
        if (!in_array($language, ['ar', 'en'], true)) {
            $language = ContentLanguageService::forUser($uid, $wid);
        }
        $result = $this->service->generateCaptionPro(
            $uid,
            $wid,
            mb_substr((string) $this->get('topic'), 0, 400),
            $platforms,
            $language,
            (string) $this->get('tone', 'friendly'),
            (string) $this->get('goal', 'engagement'),
            (string) $this->get('format', 'feed') === 'story' ? 'story' : 'feed'
        );

        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** GET /api/social/tiktok/creator-info?connection_id= - بيانات شاشة النشر (اسم الحساب والخصوصيات المسموحة) */
    public function tikTokCreatorInfo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'tiktok_creator_info', 30, 60)) {
            return $rateError;
        }
        $uid = $this->uid();
        $cid = (int) $this->get('connection_id', 0);
        $own = $this->db->query("SELECT id FROM platform_connections WHERE id = ? AND platform = 'tiktok' AND status = 'connected' AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) LIMIT 1", [$cid, $uid, $uid]);
        $conn = $own ? (new PlatformConnection())->where(['id' => $cid], [], 1) : [];
        if (!$conn) {
            return $this->error('حساب تيك توك ده مش متربط', 404);
        }
        try {
            $api = TikTokAPI::fromConnection($conn[0]);
            $info = $api->creatorInfo();
            if (empty($info['success'])) {
                return $this->error((string) ($info['error'] ?? 'تيك توك مارد'), 502);
            }
            $d = $info['data'];
            return $this->success([
                'nickname' => (string) ($d['creator_nickname'] ?? $d['creator_username'] ?? $conn[0]->getAttribute('external_location_name')),
                'username' => (string) ($d['creator_username'] ?? ''),
                'avatar' => (string) ($d['creator_avatar_url'] ?? ''),
                'privacy_level_options' => array_values((array) ($d['privacy_level_options'] ?? [])),
                'comment_disabled' => !empty($d['comment_disabled']),
                'duet_disabled' => !empty($d['duet_disabled']),
                'stitch_disabled' => !empty($d['stitch_disabled']),
                'max_video_post_duration_sec' => (int) ($d['max_video_post_duration_sec'] ?? 0),
            ]);
        } catch (Throwable $e) {
            Logger::error('TikTok creator info failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نوصل لتيك توك دلوقتي، جرّب تاني', 502);
        }
    }

    /** GET /social/connect/tiktok */
    public function connectTikTok(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }
        if (!SocialTeam::can($this->team(), 'manage')) {
            $this->renderOAuthError('ربط الحسابات متاح لمدير الحساب بس.');
            exit;
        }
        try {
            SocialPackages::assertConnect($this->uid(), 'tiktok');
        } catch (SocialPackageLimit $e) {
            $this->renderOAuthError($e->getMessage());
            exit;
        }

        $clientKey = MetaOAuthClient::setting('tiktok_client_key', 'TIKTOK_CLIENT_KEY');
        $clientSecret = MetaOAuthClient::setting('tiktok_client_secret', 'TIKTOK_CLIENT_SECRET');
        if (!$clientKey || !$clientSecret) {
            $this->renderOAuthError('TikTok connection is not enabled yet (TIKTOK_CLIENT_KEY/SECRET details are missing).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['tiktok_oauth_nonce'] = $nonce;

        $redirectUri = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');
        $state = OAuthState::issue(['aud' => 'tiktok', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0));

        $url = 'https://www.tiktok.com/v2/auth/authorize/?'
            . http_build_query([
                'client_key'    => $clientKey,
                'response_type' => 'code',
                'scope'         => TikTokAPI::SCOPES,
                'redirect_uri'  => $redirectUri,
                'state'         => $state,
            ]);

        header('Location: ' . $url);
        exit;
    }

    /** GET /social/connect/tiktok/callback */
    public function tikTokOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('The customer declined consent or an error came from TikTok: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('Incomplete response from TikTok');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['tiktok_oauth_nonce'] ?? null, 'tiktok');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('The session expired or the attempt is not trusted');
            exit;
        }

        $clientKey    = MetaOAuthClient::setting('tiktok_client_key', 'TIKTOK_CLIENT_KEY');
        $clientSecret = MetaOAuthClient::setting('tiktok_client_secret', 'TIKTOK_CLIENT_SECRET');
        $redirectUri  = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');

        $tok = TikTokAPI::exchangeCode((string) $clientKey, (string) $clientSecret, (string) $code, $redirectUri);
        if (empty($tok['success'])) {
            $this->renderOAuthError('Token exchange failed with TikTok: ' . htmlspecialchars((string) ($tok['error'] ?? 'unknown error'), ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (strpos((string) ($tok['scope'] ?? TikTokAPI::SCOPES), 'video.publish') === false) {
            $this->renderOAuthError('TikTok: وافق على صلاحية نشر الفيديوهات (video.publish) عشان المنصة تقدر تنشر بدالك. اربط تاني ووافق على كل الصلاحيات.');
            exit;
        }

        $accessToken  = (string) $tok['access_token'];
        $refreshToken = (string) ($tok['refresh_token'] ?? '');
        $openId       = (string) ($tok['open_id'] ?? '');
        $expiresIn    = (int) ($tok['expires_in'] ?? 86400);
        $displayName  = 'TikTok Account';
        $me = (new TikTokAPI($accessToken, $openId))->userInfo();
        if (!empty($me['success']) && !empty($me['user']['display_name'])) {
            $displayName = mb_substr((string) $me['user']['display_name'], 0, 120);
        }

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => $this->uid(),
            'platform' => 'tiktok',
        ], [], 1);

        $connData = [
            'user_id'               => $this->uid(),
            'platform'              => 'tiktok',
            'status'                => 'connected',
            'access_token'          => $encryption->encrypt($accessToken),
            'refresh_token'         => $refreshToken ? $encryption->encrypt($refreshToken) : null,
            'external_location_id'  => $openId,
            'external_location_name' => $displayName,
            'token_expires_at'      => gmdate('Y-m-d H:i:s', time() + (int) $expiresIn),
            'last_error'            => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['tiktok_oauth_nonce']);
        header('Location: /social?connected=tiktok');
        exit;
    }

    /** حفظ/تحديث اتصال على مستوى الحساب (Threads/LinkedIn): نفس الحساب الخارجي = نفس الصف */
    private function saveAccountConnection(string $platform, string $externalId, string $name, string $token, int $expiresIn, ?string $refresh = null): void
    {
        $enc = new Encryption();
        $uid = $this->uid();
        $row = $this->db->query('SELECT id FROM platform_connections WHERE user_id = ? AND platform = ? AND external_location_id = ? LIMIT 1', [$uid, $platform, $externalId])[0] ?? null;
        $vals = [$enc->encrypt($token), $refresh ? $enc->encrypt($refresh) : null, mb_substr($name, 0, 120), gmdate('Y-m-d H:i:s', time() + max(3600, $expiresIn))];
        if ($row) {
            $this->db->query("UPDATE platform_connections SET access_token = ?, refresh_token = ?, external_location_name = ?, token_expires_at = ?, status = 'connected', last_error = NULL, updated_at = NOW() WHERE id = ?", array_merge($vals, [(int) $row['id']]));
        } else {
            $this->db->query("INSERT INTO platform_connections (user_id, platform, access_token, refresh_token, external_location_name, token_expires_at, external_location_id, status, connected_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?, 'connected', NOW(), NOW(), NOW())", array_merge([$uid, $platform], $vals, [$externalId]));
        }
    }

    /** GET /social/connect/threads */
    public function connectThreads(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }
        if (!SocialTeam::can($this->team(), 'manage')) {
            $this->renderOAuthError('ربط الحسابات متاح لمدير الحساب بس.');
            exit;
        }
        try {
            SocialPackages::assertConnect($this->uid(), 'threads');
        } catch (SocialPackageLimit $e) {
            $this->renderOAuthError($e->getMessage());
            exit;
        }
        if (!ThreadsAPI::configured()) {
            $this->renderOAuthError('ربط Threads لسه مش مفعّل على المنصة (THREADS_APP_ID / THREADS_APP_SECRET ناقصين).');
            exit;
        }
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['threads_oauth_nonce'] = $nonce;
        header('Location: ' . ThreadsAPI::authorizeUrl(OAuthState::issue(['aud' => 'threads', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0))));
        exit;
    }

    /** GET /social/connect/threads/callback */
    public function threadsCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        if ($this->get('error') || !$this->get('code')) {
            $this->renderOAuthError('Threads: الربط اتلغى أو رجع من غير موافقة.');
            exit;
        }
        if (!OAuthState::verify((string) $this->get('state'), (int) ($this->user['id'] ?? 0), $_SESSION['threads_oauth_nonce'] ?? null, 'threads')) {
            $this->renderOAuthError('الجلسة انتهت أو المحاولة مش موثوقة - جرّب تاني.');
            exit;
        }
        $tok = ThreadsAPI::exchangeCode((string) $this->get('code'));
        if (empty($tok['success'])) {
            $this->renderOAuthError('Threads: ' . htmlspecialchars((string) ($tok['error'] ?? 'تعذر الربط'), ENT_QUOTES, 'UTF-8'));
            exit;
        }
        $me = (new ThreadsAPI($tok['access_token']))->me();
        $id = (string) (($me['id'] ?? '') ?: $tok['user_id']);
        $this->saveAccountConnection('threads', $id, '@' . (string) (($me['username'] ?? '') ?: 'threads'), $tok['access_token'], (int) $tok['expires_in']);
        unset($_SESSION['threads_oauth_nonce']);
        header('Location: /social?connected=threads#accounts');
        exit;
    }

    /** GET /social/connect/linkedin */
    public function connectLinkedIn(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }
        if (!SocialTeam::can($this->team(), 'manage')) {
            $this->renderOAuthError('ربط الحسابات متاح لمدير الحساب بس.');
            exit;
        }
        try {
            SocialPackages::assertConnect($this->uid(), 'linkedin');
        } catch (SocialPackageLimit $e) {
            $this->renderOAuthError($e->getMessage());
            exit;
        }
        if (!LinkedInAPI::configured()) {
            $this->renderOAuthError('ربط LinkedIn لسه مش مفعّل على المنصة (LINKEDIN_CLIENT_ID / LINKEDIN_CLIENT_SECRET ناقصين).');
            exit;
        }
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['linkedin_oauth_nonce'] = $nonce;
        header('Location: ' . LinkedInAPI::authorizeUrl(OAuthState::issue(['aud' => 'linkedin', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0))));
        exit;
    }

    /** GET /social/connect/linkedin/callback - بيربط البروفايل الشخصي + أي صفحة شركة العميل أدمن عليها */
    public function linkedInCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        if ($this->get('error') || !$this->get('code')) {
            $this->renderOAuthError('LinkedIn: الربط اتلغى أو رجع من غير موافقة.');
            exit;
        }
        if (!OAuthState::verify((string) $this->get('state'), (int) ($this->user['id'] ?? 0), $_SESSION['linkedin_oauth_nonce'] ?? null, 'linkedin')) {
            $this->renderOAuthError('الجلسة انتهت أو المحاولة مش موثوقة - جرّب تاني.');
            exit;
        }
        $tok = LinkedInAPI::exchangeCode((string) $this->get('code'));
        if (empty($tok['success'])) {
            $this->renderOAuthError(htmlspecialchars((string) ($tok['error'] ?? 'تعذر الربط'), ENT_QUOTES, 'UTF-8'));
            exit;
        }
        $api = new LinkedInAPI($tok['access_token']);
        $me = $api->me();
        if (!empty($me['success'])) {
            $this->saveAccountConnection('linkedin', $me['urn'], $me['name'] . ' (LinkedIn)', $tok['access_token'], (int) $tok['expires_in'], $tok['refresh_token']);
        }
        foreach ($api->organizations() as $org) {
            $this->saveAccountConnection('linkedin', $org['urn'], $org['name'], $tok['access_token'], (int) $tok['expires_in'], $tok['refresh_token']);
        }
        unset($_SESSION['linkedin_oauth_nonce']);
        header('Location: /social?connected=linkedin#accounts');
        exit;
    }

    /** GET /social/connect/youtube */
    public function connectYouTube(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }
        if (!SocialTeam::can($this->team(), 'manage')) {
            $this->renderOAuthError('ربط الحسابات متاح لمدير الحساب بس.');
            exit;
        }
        try {
            SocialPackages::assertConnect($this->uid(), 'youtube');
        } catch (SocialPackageLimit $e) {
            $this->renderOAuthError($e->getMessage());
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('YouTube connection is not enabled yet (GOOGLE_CLIENT_ID/SECRET or YOUTUBE_OAUTH_REDIRECT_URI is missing).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['youtube_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['aud' => 'youtube', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /social/connect/youtube/callback */
    public function youTubeOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('The customer declined consent or an error came from Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('Incomplete response from Google');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['youtube_oauth_nonce'] ?? null, 'youtube');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('The session expired or the attempt is not trusted');
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('Token exchange failed with Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (empty($tokenResult['refresh_token'])) {
            $this->renderOAuthError('Google did not return a refresh_token. Disconnect any previous connection from your Google settings and try again.');
            exit;
        }

        $accessToken  = $tokenResult['access_token'];
        $refreshToken = $tokenResult['refresh_token'];

        $ch = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $channelResp = curl_exec($ch);
        curl_close($ch);
        $channelData = json_decode((string) $channelResp, true);
        $channelId   = $channelData['items'][0]['id'] ?? null;
        $channelTitle = $channelData['items'][0]['snippet']['title'] ?? 'YouTube Channel';

        if (!$channelId) {
            $this->renderOAuthError('We could not get the channel ID of YouTube.');
            exit;
        }

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => $this->uid(),
            'platform' => 'youtube',
        ], [], 1);

        $connData = [
            'user_id'                => $this->uid(),
            'platform'               => 'youtube',
            'status'                 => 'connected',
            'access_token'           => $encryption->encrypt($accessToken),
            'refresh_token'          => $encryption->encrypt($refreshToken),
            'external_location_id'   => $channelId,
            'external_location_name' => $channelTitle,
            'token_expires_at'       => date('Y-m-d H:i:s', time() + (int) ($tokenResult['expires_in'] ?? 3600)),
            'last_error'             => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['youtube_oauth_nonce']);
        header('Location: /social?connected=youtube');
        exit;
    }

    private function renderOAuthError(string $message): void
    {
        $message = CustomerError::oauthMessage($message);
        echo '<div class="p-card" style="max-width:600px;margin:40px auto;text-align:center;">'
            . '<h2>⚠️ Error connecting the account</h2>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a href="/social" class="p-btn primary">Back to social media management</a>'
            . '</div>';
        exit;
    }
}
