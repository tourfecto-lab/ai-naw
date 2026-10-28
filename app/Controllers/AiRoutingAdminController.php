<?php
class AiRoutingAdminController extends Controller
{
    private function allowed(): bool{return $this->isAuthenticated()&&in_array($this->user['role']??'',['admin','super_admin'],true);}
    public function index(array $params=[]): array
    {
        if(!$this->allowed())return $this->error('غير مصرح',403);
        $css=htmlspecialchars(asset_v('/assets/css/admin-ai-routing.css'),ENT_QUOTES,'UTF-8');$js=htmlspecialchars(asset_v('/assets/js/admin-ai-routing.js'),ENT_QUOTES,'UTF-8');
        $body='<link rel="stylesheet" href="'.$css.'"><section id="aiRouting" class="air" dir="rtl"><header class="air-hero"><div><span class="air-eyebrow">TOURFECTO / AI CONTROL</span><h1>كل خدمة، على المزود المناسب</h1><p>تحكم في الشركة والنموذج والبدائل لكل خدمة من مكان واحد.</p></div><div class="air-links"><a href="/admin/integrations" class="p-btn outline">مفاتيح التكاملات ↗</a><a href="/admin" class="p-btn outline">لوحة الإدارة</a></div></header><div id="airSummary" class="air-summary" aria-live="polite"></div><div id="airProviders" class="air-providers"></div><div class="air-note">وجود مفتاح يعني أن الإعداد موجود، ولا يؤكد الرصيد أو صلاحية النموذج. اختبارات الاتصال في صفحة التكاملات. تغيير المزود لا يغير سعر بيع الخدمة للعميل.</div><div class="air-toolbar"><label class="air-search">بحث عن خدمة <input id="airSearch" type="search" placeholder="مقالات، صور، ردود، إعلانات…"></label><label>حالة المسار <select id="airStatus"><option value="all">كل الحالات</option><option value="custom">مخصص</option><option value="inherited">إعداد سابق / عام</option><option value="disabled">متوقف</option><option value="missing">مفتاح ناقص</option><option value="dirty">تغييرات غير محفوظة</option></select></label><button id="airReload" type="button" class="p-btn outline">تحديث</button></div><nav id="airGroups" class="air-groups" aria-label="فئات الخدمات"></nav><details class="air-bulk"><summary>تطبيق مزود على الخدمات النصية الظاهرة</summary><p>يعدل المسودة فقط. راجع الخدمات والنماذج ثم احفظ. الصور والفيديو لا يتأثران.</p><label>المزود <select id="airBulkProvider"></select></label><button id="airBulkApply" class="p-btn outline" type="button">تطبيق على المسودة</button></details><p id="airCount" class="air-count"></p><div id="airCards" class="air-grid" aria-busy="true"><div class="air-empty">جاري تحميل إعدادات الخدمات…</div></div><details class="air-audit"><summary>سجل آخر تغييرات الإعدادات</summary><div id="airAudit"></div></details><div class="air-savebar"><div><strong id="airDirty">لا توجد تغييرات</strong><p id="airMessage" role="status" aria-live="polite"></p></div><div><button id="airDiscard" type="button" class="p-btn outline" disabled>تراجع عن المسودة</button><button id="airReview" type="button" class="p-btn" disabled>مراجعة وحفظ</button></div></div><dialog id="airDialog"><div class="air-dialog-body"><h2>مراجعة التغييرات</h2><p>تطبق على الاستدعاءات الجديدة بعد الحفظ. طلبات الصور والفيديو المحجوزة تحتفظ بمسارها. البديل قد يزيد تكلفة المزود على المنصة.</p><div id="airDiff"></div><p id="airDialogMessage" role="status"></p><div class="air-dialog-actions"><button id="airDialogClose" class="p-btn outline" type="button">رجوع</button><button id="airSave" class="p-btn" type="button">حفظ الإعدادات</button></div></div></dialog></section><script src="'.$js.'"></script>';
        echo $this->renderPanelPage('ai-routing','توزيع خدمات الذكاء الاصطناعي','المزودون والنماذج والبدائل',$body,'window.AdminAiRouting.init(window.Panel);');exit;
    }
    public function listing(array $params=[]): array
    {
        if(!$this->allowed())return $this->error('غير مصرح',403);header('Cache-Control: private, no-store');
        try{$data=AiServiceRouting::describe();$data['csrf_token']=Csrf::token();return $this->success($data);}catch(Throwable $e){Logger::error('AI routing admin listing failed',['message'=>$e->getMessage()]);return $this->error('تعذر قراءة الإعدادات. راجع قاعدة البيانات وملف SQL الخاص بالتحديث.',503);}
    }
    private function guard(): ?array
    {
        if(!$this->allowed())return $this->error('غير مصرح',403);
        $token=$this->get('csrf_token');if(!is_string($token)||!Csrf::verify($token))return $this->error('انتهت صلاحية الجلسة، حدّث الصفحة.',419);
        return $this->rateLimitGuard('user','ai_routing_admin',30,60);
    }
    public function check(array $params=[]): array
    {
        if($e=$this->guard())return $e;header('Cache-Control: private, no-store');
        try{$task=$this->get('task');$route=$this->get('route');if(!is_string($task)||!is_array($route))throw new InvalidArgumentException('إعداد غير صالح.');$clean=AiServiceRouting::validate($task,$route);return $this->success(['route'=>$clean,'note'=>'فحص محلي للإعداد والمفتاح فقط؛ لم يتم إرسال طلب للمزود.']);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){Logger::error('AI routing validation unavailable');return $this->error('تعذر فحص الإعداد.',503);}
    }
    public function save(array $params=[]): array
    {
        if($e=$this->guard())return $e;header('Cache-Control: private, no-store');
        try{$changes=$this->get('changes');$revision=$this->get('revision');if(!is_array($changes)||!is_int($revision)||$revision<0)throw new InvalidArgumentException('بيانات الحفظ غير صالحة.');$state=AiServiceRouting::save($changes,$revision,(int)$this->user['id']);return $this->success(['revision'=>$state['revision'],'updated_at'=>$state['updated_at']],'تم حفظ توزيع الخدمات.');}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(LogicException $e){return $this->error('إعدادات الصفحة تغيرت من جلسة أخرى. حدّث الصفحة وراجع التغييرات قبل الحفظ.',409);}catch(Throwable $e){Logger::error('AI routing admin save failed',['message'=>$e->getMessage()]);return $this->error('تعذر تأكيد الحفظ. حدّث البيانات قبل إعادة المحاولة.',503);}
    }
}
