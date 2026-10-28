<?php

/**
 * Tourfecto - Email Marketing Controller
 * @version 1.0.0
 *
 * موديول تسويق البريد الاحترافي — منافس لـ Brevo/Mailchimp مبني بالكامل على
 * بنية Tourfecto (إرسال SMTP + تتبع فتح/كليك/إلغاء على جداول خاصة) من غير
 * أي اعتماد على خدمة خارجية. يضم:
 *   - لوحة تحكم (KPIs + أحدث الحملات + حالة بوابة الإرسال)
 *   - إدارة الجمهور (قوائم + مشتركين + استيراد + إلغاء اشتراك)
 *   - القوالب (متغيرات تخصيص + معاينة)
 *   - الحملات (إنشاء/جدولة/إرسال فوري/إلغاء/تقرير تفاعلي)
 *   - التتبع العام (بكسل فتح + كليك + إلغاء اشتراك) - من غير Auth
 *
 * العزل: كل استعلام مربوط بـ $this->uid() (user_id) - نفس نمط باقي
 * الموديولات (Tenant Isolation).
 */
class EmailMarketingController extends Controller
{
    /** @var EmailListService */
    private $listService;

    /** @var EmailCampaignService */
    private $campaignService;

    /** @var EmailTrackingService */
    private $trackingService;

    public function __construct()
    {
        parent::__construct();
        $this->listService = new EmailListService();
        $this->campaignService = new EmailCampaignService();
        $this->trackingService = new EmailTrackingService();
    }

    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    // ============================================================
    //  Pages (web routes)
    // ============================================================

    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $tabs = $this->emailTabsHtml('dashboard');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📬 تسويق البريد الاحترافي</h3>
                <span class="p-card-sub">منصة تسويق بريد مدمجة في Tourfecto — حملات بتتبع فتح/كليك وإلغاء اشتراك، من بياناتك وعلى بنيتك</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;" id="emKpis">
                <div class="p-loading-row">جارِ التحميل...</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" id="emQuickWrap">
            <div class="p-card">
                <div class="p-card-head"><h3>🚀 إجراء سريع</h3></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="p-btn primary" onclick="window.location.href='/email-marketing/campaigns?new=1'">+ حملة جديدة</button>
                    <button class="p-btn" onclick="window.location.href='/email-marketing/lists'">إدارة القوائم</button>
                    <button class="p-btn" onclick="window.location.href='/email-marketing/templates'">القوالب</button>
                </div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>📡 بوابة الإرسال</h3><span class="p-card-sub">عبر سيرفر البريد (SMTP)</span></div>
                <div id="emDeliveryStatus"><div class="p-loading-row">جارِ الفحص...</div></div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📨 أحدث الحملات</h3></div>
            <div id="emRecentCampaigns"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>👥 القوائم الأعلى</h3></div>
            <div id="emTopLists"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = $this->indexJs();
        echo $this->renderPanelPage('email_marketing', 'تسويق البريد', 'حملات بريد احترافية بتتبع كامل', $body, $script);
        return [];
    }

    public function showListsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('lists');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>👥 قوائم الجمهور</h3>
                <button class="p-btn primary xs" onclick="openListModal()">+ قائمة جديدة</button>
            </div>
            <div id="emListsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head">
                <h3>📇 المشتركون <span id="emSubCountLabel"></span></h3>
                <div style="display:flex;gap:8px;">
                    <select id="emSubListFilter" class="p-select" onchange="loadSubscribers()">
                        <option value="0">كل القوائم</option>
                    </select>
                    <button class="p-btn primary xs" onclick="openSubscriberModal()">+ إضافة مشترك</button>
                    <button class="p-btn xs" onclick="openImportModal()">📥 استيراد</button>
                </div>
            </div>
            <div id="emSubscribersTable"><div class="p-loading-row">جارِ التحميل...</div></div>
            <div id="emSubscribersPager" style="margin-top:10px;"></div>
        </div>

        <!-- List modal -->
        <div class="p-modal-overlay" id="listModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3 id="listModalTitle">قائمة جديدة</h3>
                    <button class="p-modal-close" onclick="document.getElementById('listModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="listId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">اسم القائمة *</label>
                    <input type="text" id="listName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: عملاء العروض الصيفية">
                    <label class="p-cell-muted" style="font-size:12px;">وصف (اختياري)</label>
                    <input type="text" id="listDesc" class="p-select" style="width:100%;margin-bottom:10px;" placeholder="وصف مختصر">
                    <button class="p-btn primary" onclick="saveList()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Subscriber modal -->
        <div class="p-modal-overlay" id="subscriberModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>إضافة مشترك</h3>
                    <button class="p-modal-close" onclick="document.getElementById('subscriberModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">البريد الإلكتروني *</label>
                    <input type="email" id="subEmail" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="client@example.com">
                    <label class="p-cell-muted" style="font-size:12px;">الاسم</label>
                    <input type="text" id="subName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="الاسم الكامل">
                    <label class="p-cell-muted" style="font-size:12px;">القائمة</label>
                    <select id="subList" class="p-select" style="width:100%;margin-bottom:10px;"></select>
                    <button class="p-btn primary" onclick="saveSubscriber()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Import modal -->
        <div class="p-modal-overlay" id="importModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>📥 استيراد مشتركين</h3>
                    <button class="p-modal-close" onclick="document.getElementById('importModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">القائمة المستهدفة</label>
                    <select id="importList" class="p-select" style="width:100%;margin-bottom:8px;"></select>
                    <label class="p-cell-muted" style="font-size:12px;">البيانات (صيغة JSON أو CSV: email,name في كل سطر)</label>
                    <textarea id="importData" class="p-select" style="width:100%;min-height:120px;font-family:monospace;font-size:12px;"
                        placeholder='[{&quot;email&quot;:&quot;a@example.com&quot;,&quot;name&quot;:&quot;أحمد&quot;},{&quot;email&quot;:&quot;b@example.com&quot;,&quot;name&quot;:&quot;سارة&quot;}]'></textarea>
                    <button class="p-btn primary xs" style="margin-top:8px;" onclick="importSubscribers()">استيراد</button>
                    <div id="importResult" style="margin-top:10px;font-size:13px;"></div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->listsJs();
        echo $this->renderPanelPage('email_marketing', 'قوائم الجمهور', 'إدارة المشتركين والقوائم', $body, $script);
        return [];
    }

    public function showContactsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('contacts');

        $body = <<<HTML
        {$tabs}

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;" id="emContactSubTabs">
            <button class="p-btn primary xs" data-ctab="overview" onclick="switchContactTab('overview')">📇 نظرة عامة</button>
            <button class="p-btn xs" data-ctab="fields" onclick="switchContactTab('fields')">🏷️ الحقول المخصصة</button>
            <button class="p-btn xs" data-ctab="tags" onclick="switchContactTab('tags')">📛 الوسوم</button>
            <button class="p-btn xs" data-ctab="segments" onclick="switchContactTab('segments')">🧩 الشرائح</button>
            <button class="p-btn xs" data-ctab="suppressions" onclick="switchContactTab('suppressions')">🚫 الممنوعون</button>
        </div>

        <!-- Overview -->
        <div id="emContactOverview">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head"><h3>📇 نظرة عامة على جهات الاتصال</h3></div>
                <div id="emContactStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                    <div class="p-loading-row">جارِ التحميل...</div>
                </div>
            </div>
            <div class="p-card">
                <div class="p-card-head">
                    <h3>👥 جهات الاتصال <span id="emCSubCountLabel"></span></h3>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <select id="emCListFilter" class="p-select" onchange="loadContactSubscribers()"></select>
                        <select id="emCStatusFilter" class="p-select" onchange="loadContactSubscribers()">
                            <option value="">كل الحالات</option>
                            <option value="subscribed">مشترك</option>
                            <option value="unsubscribed">ملغي</option>
                            <option value="bounced">مرتد</option>
                        </select>
                        <input type="text" id="emCSearch" class="p-select" placeholder="بحث..." style="max-width:180px;" onkeyup="if(event.key==='Enter')loadContactSubscribers()">
                        <button class="p-btn xs" onclick="loadContactSubscribers()">🔍</button>
                        <button class="p-btn primary xs" onclick="exportContacts()">📥 تصدير</button>
                    </div>
                </div>
                <div id="emContactsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
                <div id="emContactsPager" style="margin-top:10px;"></div>
            </div>
        </div>

        <!-- Custom fields -->
        <div id="emContactFields" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head">
                    <h3>🏷️ الحقول المخصصة</h3>
                    <button class="p-btn primary xs" onclick="openFieldModal()">+ حقل جديد</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">استخدم الحقول المخصصة لتخزين أي بيانات عن جهات الاتصال (الشركة، المدينة، تاريخ الميلاد...) وتخصيص الحملات بها عبر {{custom.field_name}}.</p>
                <div id="emFieldsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
        </div>

        <!-- Tags -->
        <div id="emContactTags" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head">
                    <h3>📛 الوسوم</h3>
                    <button class="p-btn primary xs" onclick="openTagModal()">+ وسم جديد</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">نظّم جهات الاتصال بوسوم (VIP، عملاء محتملون، مهتمون بالعروض...).</p>
                <div id="emTagsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
        </div>

        <!-- Segments -->
        <div id="emContactSegments" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head">
                    <h3>🧩 الشرائح</h3>
                    <button class="p-btn primary xs" onclick="openSegmentModal()">+ شريحة جديدة</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">شرائح ديناميكية تُحسب لحظيًا حسب الشروط (الحالة، الوسم، القائمة، حقل مخصص، التفاعل).</p>
                <div id="emSegmentsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
        </div>

        <!-- Suppressions -->
        <div id="emContactSuppressions" style="display:none;">
            <div class="p-card">
                <div class="p-card-head">
                    <h3>🚫 قائمة الممنوعين</h3>
                    <button class="p-btn primary xs" onclick="openSuppressionModal()">+ إضافة عنوان</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">العناوين هنا لا تتلقى أي حملات (ارتدادات، شكاوى، إلغاءات). تُستثنى تلقائيًا من كل الإرسال.</p>
                <div id="emSuppressionsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
        </div>

        <!-- Field modal -->
        <div class="p-modal-overlay" id="fieldModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3 id="fieldModalTitle">حقل جديد</h3>
                    <button class="p-modal-close" onclick="document.getElementById('fieldModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="fieldId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">الاسم البرمجي * (snake_case)</label>
                    <input type="text" id="fieldName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="company_name">
                    <label class="p-cell-muted" style="font-size:12px;">التسمية الظاهرة *</label>
                    <input type="text" id="fieldLabel" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="اسم الشركة">
                    <label class="p-cell-muted" style="font-size:12px;">النوع</label>
                    <select id="fieldType" class="p-select" style="width:100%;margin-bottom:8px;" onchange="toggleFieldOptions()">
                        <option value="text">نص</option>
                        <option value="number">رقم</option>
                        <option value="date">تاريخ</option>
                        <option value="boolean">نعم/لا</option>
                        <option value="select">قائمة اختيار</option>
                        <option value="multi_select">قائمة متعددة</option>
                    </select>
                    <div id="fieldOptionsWrap" style="display:none;margin-bottom:8px;">
                        <label class="p-cell-muted" style="font-size:12px;">الخيارات (مفصولة بفاصلة)</label>
                        <input type="text" id="fieldOptions" class="p-select" style="width:100%;" placeholder="أ، ب، ج">
                    </div>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:12px;">
                        <input type="checkbox" id="fieldRequired"> حقل مطلوب
                    </label>
                    <button class="p-btn primary" onclick="saveField()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Tag modal -->
        <div class="p-modal-overlay" id="tagModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>وسم جديد</h3>
                    <button class="p-modal-close" onclick="document.getElementById('tagModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="tagId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">اسم الوسم *</label>
                    <input type="text" id="tagName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="VIP">
                    <label class="p-cell-muted" style="font-size:12px;">اللون (اختياري)</label>
                    <input type="color" id="tagColor" class="p-select" style="width:100%;height:38px;margin-bottom:12px;" value="#0077be">
                    <button class="p-btn primary" onclick="saveTag()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Segment modal -->
        <div class="p-modal-overlay" id="segmentModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>شريحة جديدة</h3>
                    <button class="p-modal-close" onclick="document.getElementById('segmentModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="segmentId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">اسم الشريحة *</label>
                    <input type="text" id="segmentName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="عملاء الرياض">
                    <label class="p-cell-muted" style="font-size:12px;">الوصف</label>
                    <input type="text" id="segmentDesc" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="وصف مختصر">
                    <label class="p-cell-muted" style="font-size:12px;">منطق الشروط</label>
                    <select id="segmentMatchAll" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="1">كل الشروط (AND)</option>
                        <option value="0">أي شرط (OR)</option>
                    </select>
                    <div id="segmentConditions"></div>
                    <button class="p-btn xs" onclick="addSegmentCondition()" style="margin-bottom:10px;">+ إضافة شرط</button>
                    <div style="margin-bottom:10px;" id="segmentLiveCount" class="p-cell-muted" style="font-size:13px;"></div>
                    <button class="p-btn primary" onclick="saveSegment()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Suppression modal -->
        <div class="p-modal-overlay" id="suppressionModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>إضافة إلى الممنوعين</h3>
                    <button class="p-modal-close" onclick="document.getElementById('suppressionModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">البريد الإلكتروني *</label>
                    <input type="email" id="supEmail" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="bounce@example.com">
                    <label class="p-cell-muted" style="font-size:12px;">السبب</label>
                    <select id="supType" class="p-select" style="width:100%;margin-bottom:8px;">
                        <option value="manual">إلغاء يدوي</option>
                        <option value="bounce">ارتداد</option>
                        <option value="complaint">شكوى</option>
                        <option value="spam">تبليغ سبام</option>
                    </select>
                    <label class="p-cell-muted" style="font-size:12px;">ملاحظات</label>
                    <input type="text" id="supReason" class="p-select" style="width:100%;margin-bottom:12px;" placeholder="اختياري">
                    <button class="p-btn primary" onclick="saveSuppression()">حفظ</button>
                </div>
            </div>
        </div>

        <style>
        .em-condition-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:6px;margin-bottom:8px;align-items:center;}
        .em-tag-pill{display:inline-block;background:#0b2436;color:#7dd3fc;border-radius:999px;padding:2px 10px;font-size:12px;margin:2px;}
        </style>
        HTML;

        $script = $this->contactsJs();
        echo $this->renderPanelPage('email_marketing', 'إدارة جهات الاتصال', 'الحقول والوسوم والشرائح والممنوعين', $body, $script);
        return [];
    }

    public function showSubscriberDetailPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('contacts');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3 id="subDetailName">👤 تفاصيل جهة الاتصال</h3>
                <button class="p-btn xs" onclick="window.location.href='/email-marketing/contacts'">← رجوع</button>
            </div>
            <div id="subDetailBody"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = $this->subscriberDetailJs((int) ($params['id'] ?? 0));
        echo $this->renderPanelPage('email_marketing', 'تفاصيل جهة الاتصال', 'ملف جهة الاتصال الكامل', $body, $script);
        return [];
    }

    public function showTemplatesPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('templates');

        $body = <<<HTML
        {$tabs}

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <button class="p-btn primary xs" onclick="switchTemplatesTab('mine')">🗂️ قوالبك</button>
            <button class="p-btn xs" onclick="switchTemplatesTab('gallery')">🖼️ معرض القوالب</button>
            <span style="flex:1;"></span>
            <button class="p-btn xs" onclick="window.location.href='/email-marketing/templates/builder?new=1'">🎨 محرر بصري</button>
            <button class="p-btn primary xs" onclick="openTemplateModal()">+ قالب جديد</button>
        </div>

        <div id="emTemplatesGrid"><div class="p-loading-row">جارِ التحميل...</div></div>
        <div id="emGalleryGrid" style="display:none;"><div class="p-loading-row">جارِ تحميل المعرض...</div></div>

        <!-- Template modal -->
        <div class="p-modal-overlay" id="templateModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3 id="templateModalTitle">قالب جديد</h3>
                    <button class="p-modal-close" onclick="document.getElementById('templateModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="templateId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">اسم القالب *</label>
                    <input type="text" id="templateName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: نشرة العروض الأسبوعية">
                    <label class="p-cell-muted" style="font-size:12px;">الموضوع الافتراضي (subject)</label>
                    <input type="text" id="templateSubject" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: عروض هذا الأسبوع {{first_name}}">
                    <label class="p-cell-muted" style="font-size:12px;">محتوى HTML</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;">إدراج متغير:</span>
                        <button class="p-btn xs" onclick="insertVar('{{first_name}}')">{{first_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{email}}')">{{email}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{company_name}}')">{{company_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{campaign_name}}')">{{campaign_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{unsubscribe_url}}')">{{unsubscribe_url}}</button>
                    </div>
                    <textarea id="templateHtml" class="p-select" style="width:100%;min-height:260px;font-family:monospace;font-size:12px;" placeholder="<p>مرحبًا {{first_name}}،</p>"></textarea>
                    <div style="display:flex;gap:8px;margin-top:10px;">
                        <button class="p-btn primary" onclick="saveTemplate()">حفظ</button>
                        <button class="p-btn" onclick="previewTemplateInline()">👁 معاينة</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview modal -->
        <div class="p-modal-overlay" id="previewModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3>معاينة القالب</h3>
                    <button class="p-modal-close" onclick="document.getElementById('previewModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body" id="previewBody" style="background:#f3f4f6;"></div>
            </div>
        </div>

        <!-- Share modal -->
        <div class="p-modal-overlay" id="shareModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>🔗 مشاركة القالب</h3>
                    <button class="p-modal-close" onclick="document.getElementById('shareModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <p class="p-cell-muted" style="margin-top:0;">شارك القالب برابط عام، يمكن لأي شخص معاينته واستيراده إلى حسابه.</p>
                    <input type="text" id="shareUrl" class="p-select" style="width:100%;" readonly>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button class="p-btn primary" onclick="copyShareUrl()">📋 نسخ الرابط</button>
                        <button class="p-btn danger" onclick="stopSharing()">إيقاف المشاركة</button>
                    </div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->templatesJs();
        echo $this->renderPanelPage('email_marketing', 'قوالب البريد', 'قوالب جاهزة بمتغيرات التخصيص ومحرر بصري', $body, $script);
        return [];
    }

    public function showTemplateBuilderPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $templateId = (int) $this->get('template_id', 0);
        $galleryKey = (string) $this->get('gallery', '');
        $isNew = (int) $this->get('new', 0) === 1;

        $templateName = '';
        $templateSubject = '';
        $initialBlocks = '[]';
        $saveTarget = 0;

        if ($templateId > 0) {
            $template = (new EmailTemplate())->find($templateId);
            if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
                return $this->error('القالب غير موجود', 404);
            }
            $templateName = (string) $template->getAttribute('name');
            $templateSubject = (string) $template->getAttribute('subject');
            $initialBlocks = (string) $template->getAttribute('blocks');
            if ($initialBlocks === '' || $initialBlocks === null) {
                $initialBlocks = '[]';
            }
            $saveTarget = $templateId;
        } elseif ($galleryKey !== '') {
            $catalog = (new EmailTemplateEditorService())->catalog();
            if (!isset($catalog[$galleryKey])) {
                return $this->error('القالب غير موجود في المعرض', 404);
            }
            $item = $catalog[$galleryKey];
            $templateName = $item['name'];
            $templateSubject = $item['subject'];
            $initialBlocks = json_encode($item['blocks'], JSON_UNESCAPED_UNICODE);
        }

        $body = <<<HTML
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
            <a href="/email-marketing/templates" class="p-btn xs">→ القوالب</a>
            <input type="text" id="bdName" class="p-select" style="max-width:280px;" placeholder="اسم القالب *" value="{$this->safeAttr($templateName)}">
            <input type="text" id="bdSubject" class="p-select" style="max-width:360px;flex:1;min-width:200px;" placeholder="الموضوع الافتراضي (subject)" value="{$this->safeAttr($templateSubject)}">
            <span style="flex:1;"></span>
            <button class="p-btn xs" onclick="previewBuilder()">👁 معاينة</button>
            <button class="p-btn primary" onclick="saveBuilder()">💾 حفظ القالب</button>
        </div>

        <div style="display:grid;grid-template-columns:210px 1fr 260px;gap:14px;" id="bdLayout">
            <!-- Palette -->
            <div class="p-card" style="align-self:start;">
                <div class="p-card-head"><h3 style="font-size:14px;">🧩 البلوكات</h3></div>
                <div id="bdPalette" style="display:flex;flex-direction:column;gap:6px;"></div>
            </div>

            <!-- Canvas -->
            <div class="p-card" style="background:#1f2937;">
                <div class="p-card-head">
                    <h3 style="font-size:14px;">🖥️ المعاينة المباشرة</h3>
                    <div style="display:flex;gap:6px;">
                        <button class="p-btn xs" onclick="renderBlocks()">↻ تحديث</button>
                    </div>
                </div>
                <div id="bdCanvasWrap" style="background:repeating-linear-gradient(45deg,#111827,#111827 8px,#0b1220 8px,#0b1220 16px);border-radius:10px;padding:14px;">
                    <div id="bdCanvas" style="max-width:660px;margin:0 auto;"></div>
                </div>
                <div id="bdBlocksBar" style="margin-top:12px;display:flex;flex-direction:column;gap:6px;"></div>
            </div>

            <!-- Inspector -->
            <div class="p-card" style="align-self:start;">
                <div class="p-card-head"><h3 style="font-size:14px;">⚙️ إعدادات البلوك</h3></div>
                <div id="bdInspector"><p class="p-cell-muted">اختر بلوكًا من القائمة للتحرير.</p></div>
            </div>
        </div>
        HTML;

        $script = $this->builderJs($initialBlocks, $saveTarget);
        echo $this->renderPanelPage('email_marketing', 'المحرر البصري', 'بناء قالب إيميل بالبلوكات', $body, $script);
        return [];
    }

    /** صفحة عامة (بدون تسجيل) لعرض قالب مشترك واستيراده. */
    public function showSharedTemplatePage(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $editor = new EmailTemplateEditorService();
        $shared = $editor->byShareToken($token);
        if (!$shared) {
            http_response_code(404);
            echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>قالب غير موجود</title>'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#374151;}</style>'
                . '</head><body><div style="text-align:center;padding:24px;"><h2>القالب المشترك غير موجود أو تم إيقاف مشاركته</h2>'
                . '<a href="/" style="color:#2563eb;">العودة للرئيسية</a></div></body></html>';
            exit;
        }

        $name = htmlspecialchars((string) $shared['name'], ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars((string) $shared['subject'], ENT_QUOTES, 'UTF-8');
        $html = (string) $shared['html_body'];
        $tokenEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$name} — قالب مشترك</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; background:#f3f4f6; margin:0; padding:0; color:#111827; }
            .top { background:#111827; color:#fff; padding:14px 20px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
            .top h1 { font-size:17px; margin:0; }
            .top .sub { font-size:12px; color:#9ca3af; }
            .wrap { max-width:700px; margin:24px auto; padding:0 16px; }
            .card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
            .card .hd { padding:14px 20px; border-bottom:1px solid #e5e7eb; font-size:13px; color:#374151; }
            .frame { padding:18px; background:#f9fafb; }
            iframe { width:100%; min-height:480px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
            .btn { display:inline-block; background:#2563eb; color:#fff; text-decoration:none; padding:10px 22px; border-radius:8px; font-weight:700; border:0; cursor:pointer; font-size:14px; }
            .btn.ghost { background:#fff; color:#111827; border:1px solid #e5e7eb; margin-right:8px; }
            .meta { font-size:12px; color:#6b7280; padding:10px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        </style>
        </head>
        <body>
        <div class="top">
            <div>
                <h1>📨 {$name}</h1>
                <div class="sub">قالب بريد مشترك عبر منصة Tourfecto للتسويق</div>
            </div>
        </div>
        <div class="wrap">
            <div class="card">
                <div class="hd">الموضوع: <b>{$subject}</b></div>
                <div class="frame"><iframe sandbox="" srcdoc="{$this->attrEncode($html)}"></iframe></div>
                <div class="meta">
                    <button class="btn" id="importBtn">استخدم هذا القالب</button>
                    <span>استخدم القالب لبدء بناء حملتك الخاصة بسرعة.</span>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('importBtn').addEventListener('click', async () => {
            const res = await fetch('/api/email-marketing/templates/shared/{$tokenEsc}/import', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'});
            const data = await res.json();
            if (data.success) { window.location.href = '/email-marketing/templates?imported=1'; }
            else if (res.status === 401) { window.location.href = '/login?next=/email-marketing/templates/shared/{$tokenEsc}'; }
            else { notify(data.error || 'تعذر الاستيراد'); }
        });
        </script>
        </body>
        </html>
        HTML;
        exit;
    }

    private function safeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function attrEncode(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function showCampaignsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('campaigns');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🚀 الحملات</h3>
                <button class="p-btn primary xs" onclick="openCampaignModal()">+ حملة جديدة</button>
            </div>
            <div id="emCampaignsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <!-- Campaign modal -->
        <div class="p-modal-overlay" id="campaignModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3 id="campaignModalTitle">حملة جديدة</h3>
                    <button class="p-modal-close" onclick="document.getElementById('campaignModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="campaignId" value="">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">اسم الحملة *</label>
                            <input type="text" id="campaignName" class="p-select" style="width:100%;" placeholder="مثال: إطلاق عرض الصيف">
                        </div>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">الجمهور (قائمة)</label>
                            <select id="campaignList" class="p-select" style="width:100%;"></select>
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <label class="p-cell-muted" style="font-size:12px;">أو استهدف شريحة ديناميكية (تغلب على القائمة)</label>
                        <select id="campaignSegment" class="p-select" style="width:100%;">
                            <option value="0">— بدون شريحة —</option>
                        </select>
                    </div>
                    <label class="p-cell-muted" style="font-size:12px;margin-top:8px;display:block;">الموضوع (subject) *</label>
                    <input type="text" id="campaignSubject" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: عرض خاص لأول 50 عميل {{first_name}}">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">من (الاسم)</label>
                            <input type="text" id="campaignFromName" class="p-select" style="width:100%;" placeholder="شركتك">
                        </div>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">من (البريد)</label>
                            <input type="text" id="campaignFromEmail" class="p-select" style="width:100%;" placeholder="noreply@yourdomain.com">
                        </div>
                    </div>
                    <label class="p-cell-muted" style="font-size:12px;margin-top:8px;display:block;">محتوى HTML</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;">إدراج متغير:</span>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{first_name}}')">{{first_name}}</button>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{email}}')">{{email}}</button>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{unsubscribe_url}}')">{{unsubscribe_url}}</button>
                    </div>
                    <textarea id="campaignHtml" class="p-select" style="width:100%;min-height:220px;font-family:monospace;font-size:12px;" placeholder="<p>مرحبًا {{first_name}}،</p><p>عرض هذا الأسبوع...</p>"></textarea>
                    <div id="campaignScheduleWrap" style="margin-top:10px;display:none;">
                        <label class="p-cell-muted" style="font-size:12px;">جدولة (تاريخ ووقت)</label>
                        <input type="datetime-local" id="campaignScheduledAt" class="p-select" style="width:100%;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button class="p-btn primary" onclick="saveCampaign(false)">حفظ كمسودة</button>
                        <button class="p-btn" onclick="saveCampaign(true)">📅 جدولة</button>
                        <button class="p-btn danger" onclick="document.getElementById('campaignModal').classList.remove('open')">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->campaignsJs();
        echo $this->renderPanelPage('email_marketing', 'الحملات', 'إنشاء وجدولة حملات تسويق البريد', $body, $script);
        return [];
    }

    public function showCampaignDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = $this->campaignService->get($this->uid(), $campaignId);
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $tabs = $this->emailTabsHtml('campaigns');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📊 تقرير الحملة: <span id="crName"></span></h3>
                <div style="display:flex;gap:8px;">
                    <button class="p-btn xs" onclick="refreshCampaignReport()">↻ تحديث</button>
                    <button class="p-btn xs danger" id="crCancelBtn" onclick="cancelCampaign()" style="display:none;">إلغاء الجدولة</button>
                    <button class="p-btn xs primary" id="crRetryBtn" onclick="retryFailedCampaign()" style="display:none;">↻ إعادة المحاولة</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;" id="crKpis">
                <div class="p-loading-row">جارِ التحميل...</div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 معدلات التفاعل</h3></div>
            <div id="crRates"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📨 المستلمون</h3></div>
            <div id="crRecipients"><div class="p-loading-row">جارِ التحميل...</div></div>
            <div id="crRecipientsPager" style="margin-top:10px;"></div>
        </div>
        HTML;

        $script = $this->campaignReportJs($campaignId);
        echo $this->renderPanelPage('email_marketing', 'تقرير الحملة', 'إحصائيات وتفاعل الحملة', $body, $script);
        return [];
    }

    public function showReportsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('reports');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 إحصائيات تسويق البريد</h3><span class="p-card-sub">معدلات الفتح والكليك عبر كل حملاتك</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;" id="emStatsKpis">
                <div class="p-loading-row">جارِ التحميل...</div>
            </div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>🗂 أداء كل حملة</h3></div>
            <div id="emStatsCampaigns"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>⚡ رسائل المعاملات</h3><span class="p-card-sub">إرسال الرسائل الآلية (ترحيب/كلمة مرور/إشعارات)</span></div>
            <div id="emStatsTransactional"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>🧪 اختبارات أ/ب</h3><span class="p-card-sub">مقارنة أداء متغيرات الحملات (الفتح/الكليك)</span></div>
            <div id="emStatsAbTests"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = $this->reportsJs();
        echo $this->renderPanelPage('email_marketing', 'التقارير', 'إحصائيات تفاعل حملاتك', $body, $script);
        return [];
    }

    public function showAutomationsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('automations');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>⚙️ سير العمل التلقائي</h3>
                <span class="p-card-sub">أتمتة مثل Brevo: اشتراك / وسم / فتح / نقر / بعد مدة — مع خطوات انتظار وبريد ووسوم وقوائم</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="openAutomationModal()">+ سير عمل جديد</button>
                <button class="p-btn" onclick="runAutomationsDue(this)">تشغيل المستحقات الآن</button>
            </div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📋 سير العمل</h3></div>
            <div id="emAutomationsList"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div id="emAutomationModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:860px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 id="emAutoModalTitle" style="margin:0;">سير عمل جديد</h3>
                    <button class="p-btn xs" onclick="closeAutomationModal()">✕ إغلاق</button>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div>
                        <label class="p-label">الاسم</label>
                        <input class="p-input" id="emAutoName" placeholder="مثال: ترحيب بالمشتركين الجدد" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">الوصف</label>
                        <input class="p-input" id="emAutoDesc" placeholder="وصف اختياري" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">المشغل</label>
                        <select class="p-input" id="emAutoTrigger" style="width:100%;"></select>
                    </div>
                    <div id="emAutoTriggerValueWrap">
                        <label class="p-label" id="emAutoTriggerValueLabel">قائمة (اختياري)</label>
                        <select class="p-input" id="emAutoTriggerValue" style="width:100%;"></select>
                    </div>
                    <div>
                        <label class="p-label">قوائم الدخول المؤهلة (اختياري)</label>
                        <select class="p-input" id="emAutoEntryAudience" multiple style="width:100%;height:70px;"></select>
                    </div>
                    <div>
                        <label class="p-label">قوائم الخروج (اختياري)</label>
                        <select class="p-input" id="emAutoExitAudience" multiple style="width:100%;height:70px;"></select>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <label class="p-label" style="margin:0;">الخطوات</label>
                        <button class="p-btn xs" onclick="addAutomationStep()">+ إضافة خطوة</button>
                    </div>
                    <div id="emAutoSteps" style="display:flex;flex-direction:column;gap:8px;"></div>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                    <button class="p-btn" onclick="closeAutomationModal()">إلغاء</button>
                    <button class="p-btn primary" id="emAutoSaveBtn" onclick="saveAutomation()">💾 حفظ سير العمل</button>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->automationsJs();
        echo $this->renderPanelPage('email_marketing', 'سير العمل التلقائي', 'أتمتة تسويق البريد', $body, $script);
        return [];
    }

    public function showEmailSettingsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('settings');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🔌 إعدادات الإرسال (SMTP)</h3>
                <span class="p-card-sub">ربط سيرفر البريد الخاص بك — مثل Brevo، الإرسال يتم عبر بنيتك الخاصة لا عبر طرف ثالث</span>
            </div>
            <div id="emSmtpStatus" style="margin-top:8px;"></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>⚙️ بيانات الخادم</h3></div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:10px;">
                <div>
                    <label class="p-label">المضيف (host)</label>
                    <input class="p-input" id="emSmtpHost" placeholder="smtp.example.com" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">المنفذ (port)</label>
                    <input class="p-input" id="emSmtpPort" type="number" value="587" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">التشفير</label>
                    <select class="p-input" id="emSmtpEncryption" style="width:100%;">
                        <option value="tls">STARTTLS</option>
                        <option value="ssl">SSL/TLS</option>
                        <option value="">بدون تشفير</option>
                    </select>
                </div>
                <div>
                    <label class="p-label">اسم المستخدم</label>
                    <input class="p-input" id="emSmtpUsername" placeholder="you@example.com" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">كلمة المرور / App Password</label>
                    <input class="p-input" id="emSmtpPassword" type="password" placeholder="••••••••" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">المرسل (from email)</label>
                    <input class="p-input" id="emSmtpFromEmail" placeholder="noreply@example.com" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">اسم المرسل</label>
                    <input class="p-input" id="emSmtpFromName" placeholder="شركتي" style="width:100%;"/>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button class="p-btn primary" onclick="saveSmtpSettings()">💾 حفظ الإعدادات</button>
                <button class="p-btn" onclick="testSmtpSettings()">🧪 اختبار الاتصال</button>
                <button class="p-btn" id="emSmtpToggleBtn" onclick="toggleSmtpSettings()">⏻ تفعيل</button>
                <button class="p-btn danger" onclick="deleteSmtpSettings()">🗑 حذف الإعدادات</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:14px;" id="emSmtpStatusRow">
                <input type="checkbox" id="emSmtpActive" onchange="toggleSmtpSettings()">
                <label for="emSmtpActive">تفعيل الإرسال عبر هذه الإعدادات</label>
            </div>
            <p class="p-cell-muted" style="margin-top:12px;">الإعدادات الخاصة بك تتجاوز إعدادات .env العامة. كلمة المرور لا تُظهر مرة أخرى بعد الحفظ.</p>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head">
                <h3>🔔 Webhook تتبع التسليم</h3>
                <span class="p-card-sub">تُعلَم تلقائيًا بالارتدادات (bounce) والشكاوى (complaint) من مزوّد البريد — مثال SendGrid/Mailgun/Postmark</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap;">
                <input type="checkbox" id="emDeliveryWebhookEnabled" onchange="toggleDeliveryWebhook()" style="transform:scale(1.1);">
                <label for="emDeliveryWebhookEnabled">تفعيل استقبال webhook التسليم</label>
                <button class="p-btn" onclick="regenerateDeliveryWebhookSecret()">🔑 توليد مفتاح جديد</button>
            </div>
            <div style="margin-top:12px;font-size:13px;">
                <label class="p-label">رابط الـ webhook (ضعه في إعدادات مزوّد البريد)</label>
                <code id="emDeliveryWebhookUrl" style="display:block;background:#0f172a;color:#7dd3fc;padding:8px 12px;border-radius:8px;margin-top:6px;direction:ltr;text-align:left;word-break:break-all;"></code>
            </div>
            <div style="margin-top:10px;font-size:13px;">
                <label class="p-label">المفتاح السري (يُرسَل في header التوقيع — ادعمنا Mailgun/SendGrid/Postmark)</label>
                <code id="emDeliveryWebhookSecret" style="display:block;background:#0f172a;color:#a5f3fc;padding:8px 12px;border-radius:8px;margin-top:6px;direction:ltr;text-align:left;word-break:break-all;"></code>
            </div>
            <p class="p-cell-muted" style="margin-top:10px;">التحقق من التوقيع: مفتاح المستخدم الخاص أولًا، ثم مفتاح .env العام
                (EMAIL_DELIVERY_WEBHOOK_SECRET) كحد أدنى. النوع غير المعروف يُتجاهل بأمان
                (bounce/complaint/spam فقط). ملاحظة: مع SMTP الخام (البنية الحالية) لا توجد
                webhooks رسمية من السيرفر نفسه — تسجيل المشاكل يدويًا عبر هذا الـ endpoint أو
                من لوحة تحكم المزوّد حتى وجود تكامل API تلقائي.</p>
        </div>
        HTML;

        $script = $this->smtpSettingsJs();
        echo $this->renderPanelPage('email_marketing', 'إعدادات الإرسال', 'SMTP / Deliverability', $body, $script);
        return [];
    }

    public function showTransactionalPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('transactional');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📨 رسائل المعاملات</h3>
                <span class="p-card-sub">رسائل تأكيد التسجيل، استعادة كلمة المرور، الفواتير — بتتبع فتح/كليك من غير إلغاء اشتراك</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="openTransactionalTemplateModal()">+ قالب معاملات جديد</button>
                <button class="p-btn" onclick="loadTransactionalLogs()">سجل الإرسال</button>
            </div>
            <div id="emTxStats" style="display:flex;gap:12px;margin-top:14px;"></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>🗂️ قوالب المعاملات</h3></div>
            <div id="emTxTemplates"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>📋 سجل الإرسال</h3></div>
            <div id="emTxLogs"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div id="emTxTemplateModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:760px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 id="emTxModalTitle" style="margin:0;">قالب معاملات جديد</h3>
                    <button class="p-btn xs" onclick="closeTransactionalTemplateModal()">✕ إغلاق</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
                    <div>
                        <label class="p-label">الاسم</label>
                        <input class="p-input" id="emTxName" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">القيمة المختصرة (slug)</label>
                        <input class="p-input" id="emTxSlug" placeholder="welcome (اختياري)" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">الموضوع (subject)</label>
                        <input class="p-input" id="emTxSubject" style="width:100%;"/>
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <label class="p-label">المحتوى (HTML — يدعم {{variables}})</label>
                    <textarea class="p-input" id="emTxHtml" rows="10" style="width:100%;font-family:monospace;font-size:13px;"></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button class="p-btn" onclick="closeTransactionalTemplateModal()">إلغاء</button>
                    <button class="p-btn primary" onclick="saveTransactionalTemplate()">💾 حفظ القالب</button>
                </div>
            </div>
        </div>

        <div id="emTxSendModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:520px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 style="margin:0;">إرسال رسالة معاملات</h3>
                    <button class="p-btn xs" onclick="closeTransactionalSendModal()">✕ إغلاق</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr;gap:12px;">
                    <div>
                        <label class="p-label">البريد المستلم</label>
                        <input class="p-input" id="emTxSendEmail" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">اسم المستلم (اختياري)</label>
                        <input class="p-input" id="emTxSendName" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">البيانات (JSON للـ variables)</label>
                        <textarea class="p-input" id="emTxSendData" rows="3" placeholder='{"first_name":"أحمد","company_name":"شركتي"}' style="width:100%;font-family:monospace;font-size:13px;"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button class="p-btn" onclick="closeTransactionalSendModal()">إلغاء</button>
                    <button class="p-btn primary" onclick="sendTransactionalNow()">📤 إرسال الآن</button>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->transactionalJs();
        echo $this->renderPanelPage('email_marketing', 'رسائل المعاملات', 'Transactional Email', $body, $script);
        return [];
    }

    public function showAbTestsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('ab_tests');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🧪 اختبار أ/ب</h3>
                <span class="p-card-sub">قسّم جمهورك بين نسختين (عنوان أو محتوى) وحدد المتغير الفائز — مثل Brevo/Mailchimp</span>
            </div>
            <button class="p-btn primary" onclick="openAbTestModal()">+ اختبار أ/ب جديد</button>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📋 الاختبارات</h3></div>
            <div id="emAbTestsList"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div id="emAbTestModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:640px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 style="margin:0;">اختبار أ/ب جديد</h3>
                    <button class="p-btn xs" onclick="closeAbTestModal()">✕ إغلاق</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr;gap:12px;">
                    <div>
                        <label class="p-label">الاسم</label>
                        <input class="p-input" id="emAbName" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">الحملة الأساسية (الجمهور)</label>
                        <select class="p-input" id="emAbCampaign" style="width:100%;"></select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label class="p-label">نسبة المتغير ب %</label>
                            <input class="p-input" id="emAbSplit" type="number" value="50" min="5" max="95" style="width:100%;"/>
                        </div>
                        <div>
                            <label class="p-label">مقياس الفوز</label>
                            <select class="p-input" id="emAbMetric" style="width:100%;">
                                <option value="open">معدل الفتح</option>
                                <option value="click">معدل الكليك</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button class="p-btn" onclick="closeAbTestModal()">إلغاء</button>
                    <button class="p-btn primary" onclick="createAbTest()">إنشاء الاختبار</button>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->abTestsJs();
        echo $this->renderPanelPage('email_marketing', 'اختبار أ/ب', 'A/B Testing', $body, $script);
        return [];
    }

    public function showAbTestDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $ab = $this->abTestService()->get($this->uid(), $id);
        if (!$ab) {
            echo $this->renderPanelPage(
                'email_marketing',
                'اختبار أ/ب',
                'A/B Testing',
                '<p class="p-cell-muted" style="padding:20px;">الاختبار غير موجود.</p>'
            );
            return [];
        }
        $tabs = $this->emailTabsHtml('ab_tests');
        $va = $ab['variant_a'] ?? [];
        $vb = $ab['variant_b'] ?? [];
        $vaTotal = (int) ($va['total_recipients'] ?? 0);
        $vbTotal = (int) ($vb['total_recipients'] ?? 0);
        $vaSubject = htmlspecialchars((string) ($va['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vaHtml = htmlspecialchars((string) ($va['html_body'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vbSubject = htmlspecialchars((string) ($vb['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vbHtml = htmlspecialchars((string) ($vb['html_body'] ?? ''), ENT_QUOTES, 'UTF-8');
        $abStatus = htmlspecialchars((string) $ab['status'], ENT_QUOTES, 'UTF-8');
        $abMetric = htmlspecialchars((string) $ab['metric'], ENT_QUOTES, 'UTF-8');
        $abName = htmlspecialchars((string) $ab['name'], ENT_QUOTES, 'UTF-8');
        $abSplit = (int) $ab['split_percent'];

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🧪 {$abName}</h3>
                <span class="p-card-sub">الحالة: {$abStatus} — المقياس: {$abMetric} — نسبة ب: {$abSplit}%</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="runAbTest(this)">🚀 تشغيل وتقسيم الجمهور</button>
                <button class="p-btn" onclick="sendAbBatch(this)">📤 إرسال دفعة</button>
                <button class="p-btn" onclick="loadAbReport()">📈 التقرير</button>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;background:var(--panel-card-bg-2,#f9fafb);padding:10px;border-radius:10px;">
                <span style="font-size:13px;font-weight:600;">✉️ إرسال اختبار:</span>
                <select id="emAbTestVariant" class="p-select" style="max-width:150px;">
                    <option value="all">كل المتغيرات</option>
                    <option value="a">المتغير أ</option>
                    <option value="b">المتغير ب</option>
                </select>
                <input type="email" id="emAbTestEmail" class="p-select" style="flex:1;min-width:200px;" placeholder="your-email@example.com">
                <button class="p-btn xs primary" onclick="sendAbTestEmail(this)">📬 إرسال</button>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="p-card">
                <div class="p-card-head"><h3>🅰️ المتغير أ — {$vaTotal} مستلم</h3></div>
                <label class="p-label">الموضوع</label>
                <input class="p-input" id="emAbASubject" value="{$vaSubject}" style="width:100%;"/>
                <label class="p-label" style="margin-top:10px;">المحتوى</label>
                <textarea class="p-input" id="emAbAHtml" rows="10" style="width:100%;font-family:monospace;font-size:13px;">{$vaHtml}</textarea>
                <button class="p-btn xs" style="margin-top:10px;" onclick="saveAbVariant('a')">💾 حفظ المتغير أ</button>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>🅱️ المتغير ب — {$vbTotal} مستلم</h3></div>
                <label class="p-label">الموضوع</label>
                <input class="p-input" id="emAbBSubject" value="{$vbSubject}" style="width:100%;"/>
                <label class="p-label" style="margin-top:10px;">المحتوى</label>
                <textarea class="p-input" id="emAbBHtml" rows="10" style="width:100%;font-family:monospace;font-size:13px;">{$vbHtml}</textarea>
                <button class="p-btn xs" style="margin-top:10px;" onclick="saveAbVariant('b')">💾 حفظ المتغير ب</button>
            </div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>📊 التقرير</h3></div>
            <div id="emAbReport"><div class="p-loading-row">اطلب التقرير لعرض النتائج</div></div>
        </div>
        HTML;

        $script = $this->abTestDetailsJs($id);
        echo $this->renderPanelPage('email_marketing', 'اختبار أ/ب', 'A/B Testing', $body, $script);
        return [];
    }

    // ============================================================
    //  API: Dashboard
    // ============================================================

    public function dashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->uid();

        $subscribers = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers WHERE user_id = ? AND status = 'subscribed'",
            [$userId]
        )[0]['total'];

        $lists = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_lists WHERE user_id = ?",
            [$userId]
        )[0]['total'];

        $campaigns = $this->db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(opened_count),0) AS opened, COALESCE(SUM(clicked_count),0) AS clicked
             FROM email_campaigns WHERE user_id = ?",
            [$userId]
        )[0];

        $sentTotal = (int) ($campaigns['sent'] ?? 0);
        $openRate = $sentTotal > 0 ? round((int) $campaigns['opened'] / $sentTotal * 100, 1) : 0;
        $clickRate = $sentTotal > 0 ? round((int) $campaigns['clicked'] / $sentTotal * 100, 1) : 0;

        $txStats = $this->transactionalService()->stats($userId);
        $abCount = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_ab_tests WHERE user_id = ?",
            [$userId]
        )[0]['total'];

        return $this->success([
            'kpis' => [
                ['label' => 'المشتركون النشطون', 'value' => $subscribers, 'icon' => '👥'],
                ['label' => 'القوائم', 'value' => $lists, 'icon' => '🗂'],
                ['label' => 'الحملات', 'value' => (int) $campaigns['total'], 'icon' => '🚀'],
                ['label' => 'معدل الفتح', 'value' => $openRate . '%', 'icon' => '👁'],
                ['label' => 'معدل الكليك', 'value' => $clickRate . '%', 'icon' => '🖱'],
                ['label' => 'رسائل معاملات', 'value' => (int) ($txStats['total'] ?? 0), 'icon' => '⚡'],
                ['label' => 'اختبارات أ/ب', 'value' => $abCount, 'icon' => '🧪'],
            ],
            'delivery' => $this->deliveryStatus(),
            'recent_campaigns' => $this->campaignService->list($userId),
            'top_lists' => $this->listService->lists($userId),
        ], 'Email marketing dashboard');
    }

    // ============================================================
    //  API: Lists & Subscribers
    // ============================================================

    public function lists(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['lists' => $this->listService->lists($this->uid())]);
    }

    public function createList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->createList($this->uid(), (string) $this->get('name'), $this->get('description'));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء القائمة')
            : $this->error($result['error'], 422);
    }

    public function updateList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->updateList($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث القائمة')
            : $this->error($result['error'], 404);
    }

    public function deleteList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->deleteList($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف القائمة')
            : $this->error($result['error'], 404);
    }

    public function listSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) ($params['id'] ?? 0);
        $result = $this->listService->subscribers(
            $this->uid(),
            $listId,
            ['status' => $this->get('status'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function allSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->subscribers(
            $this->uid(),
            (int) $this->get('list_id', 0),
            ['status' => $this->get('status'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function createSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) $this->get('list_id', 0);
        $result = $this->listService->subscribe(
            $this->uid(),
            (string) $this->get('email'),
            ['name' => $this->get('name'), 'source' => 'manual'],
            $listId
        );
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تمت إضافة المشترك')
            : $this->error($result['error'], 422);
    }

    // ============================================================
    //  Public: Double Opt-In (بند 2) — بدون AuthMiddleware عمدًا
    //  (النموذج العام + رابط التأكيد في البريد). حماية الإساءة عبر
    //  RateLimitMiddleware العام + التحقق من صحة الإيميل فقط.
    // ============================================================

    /**
     * POST /webhooks/email/subscribe — اشتراك عام (يبدأ pending_optin).
     * البريد الإلكتروني مطلوب؛ قائمة اختيارية (تُربط بها عند التأكيد).
     * @param array $params body أو form: email, name, list_id
     */
    public function publicSubscribe(array $params = []): array
    {
        $email = strtolower(trim((string) $this->get('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('بريد إلكتروني غير صالح', 422);
        }

        // الاشتراك العام يتطلب تأكيد (Double Opt-In) — معرفة المالك (user_id)
        // من القائمة المختارة إن وُجدت، وإلا نرفض (لا يمكن إسناد الاشتراك
        // لمستخدم بدون سياق قائمة). الـ endpoint العام يفترض إرسال list_id
        // من نموذج الموقع/الوصلة العامة.
        $listId = (int) $this->get('list_id', 0);
        $list = $listId > 0 ? (new EmailList())->find($listId) : null;
        if (!$list) {
            return $this->error('قائمة الاشتراك غير صالحة', 422);
        }
        $userId = (int) $list->getAttribute('user_id');

        $result = $this->listService->subscribe(
            $userId,
            $email,
            ['name' => (string) $this->get('name'), 'source' => 'form', 'require_optin' => true],
            $listId
        );
        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }
        return $this->success([
            'id' => $result['id'],
            'pending_optin' => (bool) ($result['pending_optin'] ?? false),
            'status' => $result['status'] ?? 'subscribed',
        ], 'تم استلام اشتراكك — تحقق من بريدك وأكّد اشتراكك خلال دقائق');
    }

    /**
     * GET /webhooks/email/confirm/{token} — تفعيل الاشتراك من رابط البريد.
     * يعرض صفحة HTML بسيطة بنتيجة التأكيد (نجاح/فشل) ولا يحتاج Auth.
     */
    public function publicConfirmOptin(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $result = $this->listService->confirmOptin($token, $ip);

        $success = (bool) ($result['success'] ?? false);
        $emoji = $success ? '✅' : '⚠️';
        $title = $success ? 'تم تأكيد اشتراكك' : 'رابط غير صالح';
        $body = $success
            ? 'شكرًا لك! اشتراكك مؤكّد الآن وستصلك رسائلنا.'
            : 'هذا الرابط غير صالح أو منتهٍ. يمكنك إعادة الاشتراك من النموذج العام.';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
.box{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);padding:40px;max-width:420px;width:100%;text-align:center;}
.ico{font-size:48px;margin-bottom:12px;}
h1{font-size:22px;color:#0f172a;margin:0 0 10px;}
p{color:#475569;line-height:1.9;margin:0;}
</style>
</head>
<body><div class="box"><div class="ico">{$emoji}</div><h1>{$title}</h1><p>{$body}</p></div></body>
</html>
HTML;
        exit;
    }

    public function importSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) $this->get('list_id', 0);
        $raw = (string) $this->get('data');
        $rows = $this->parseImportData($raw);
        if (empty($rows)) {
            return $this->error('لا توجد بيانات صالحة للاستيراد', 422);
        }
        $result = $this->listService->import($this->uid(), $rows, $listId);
        return $this->success($result, 'تم الاستيراد');
    }

    public function unsubscribeSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        // API (إدارة): إلغاء اشتراك مشترك محدد
        $id = (int) ($params['id'] ?? 0);
        $rows = $this->db->query(
            "SELECT unsubscribe_token FROM email_subscribers WHERE id = ? AND user_id = ?",
            [$id, $this->uid()]
        );
        if (empty($rows)) {
            return $this->error('المشترك غير موجود', 404);
        }
        $ok = $this->listService->unsubscribeByToken($rows[0]['unsubscribe_token']);
        return $ok ? $this->success([], 'تم إلغاء اشتراك المستخدم') : $this->error('تعذر الإلغاء', 422);
    }

    public function deleteSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->deleteSubscriber($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف المشترك')
            : $this->error($result['error'], 404);
    }

    // ============================================================
    //  Contact Management APIs (Phase 1) — fields / tags / segments / suppressions
    // ============================================================

    private function contacts(): ContactManagementService
    {
        return new ContactManagementService();
    }

    // ----- Subscriber detail & advanced import/export -----

    public function getSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $data = $this->contacts()->subscriberDetail($this->uid(), (int) ($params['id'] ?? 0));
        return $data ? $this->success(['subscriber' => $data]) : $this->error('المشترك غير موجود', 404);
    }

    public function updateSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $svc = $this->contacts();
        $body = $this->all();

        if (isset($body['name'])) {
            $sub = (new EmailSubscriber())->find($id);
            if (!$sub || (int) $sub->getAttribute('user_id') !== $this->uid()) {
                return $this->error('المشترك غير موجود', 404);
            }
            $sub->setAttribute('name', trim((string) $body['name']));
            $sub->save();
        }
        if (!empty($body['status'])) {
            $result = $svc->updateSubscriberStatus($this->uid(), $id, (string) $body['status']);
            if (!$result['success']) {
                return $this->error($result['error'], 422);
            }
        }
        if (!empty($body['custom_values']) && is_array($body['custom_values'])) {
            $svc->saveCustomValues($this->uid(), $id, $body['custom_values']);
        }
        return $this->success([], 'تم تحديث المشترك');
    }

    public function importContactsAdvanced(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $raw = (string) $this->get('data');
        $rows = $this->parseImportData($raw);
        if (empty($rows)) {
            return $this->error('لا توجد بيانات صالحة للاستيراد', 422);
        }
        $options = [
            'list_id' => (int) $this->get('list_id', 0),
            'tags' => is_array($this->get('tags')) ? array_map('strval', $this->get('tags')) : [],
            'field_map' => is_array($this->get('field_map')) ? $this->get('field_map') : [],
        ];
        $result = $this->contacts()->importContacts($this->uid(), $rows, $options);
        return $this->success($result, 'تم الاستيراد');
    }

    public function exportSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $filters = [
            'list_id' => (int) $this->get('list_id', 0),
            'status' => $this->get('status') ? (string) $this->get('status') : null,
            'segment_id' => (int) $this->get('segment_id', 0),
        ];
        $rows = $this->contacts()->exportSubscribers($this->uid(), $filters);
        $format = $this->get('format') === 'csv' ? 'csv' : 'json';
        if ($format === 'csv') {
            return $this->success(['data' => $this->toCsv($rows)]);
        }
        return $this->success(['data' => $rows]);
    }

    private function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $row));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    // ----- Custom fields -----

    public function customFields(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['fields' => $this->contacts()->customFields($this->uid())]);
    }

    public function createCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->createCustomField($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء الحقل')
            : $this->error($result['error'], 422);
    }

    public function updateCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->updateCustomField($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث الحقل')
            : $this->error($result['error'], 422);
    }

    public function deleteCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->deleteCustomField($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف الحقل')
            : $this->error($result['error'], 422);
    }

    // ----- Tags -----

    public function tags(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['tags' => $this->contacts()->tags($this->uid())]);
    }

    public function createTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->createTag($this->uid(), (string) $this->get('name'), $this->get('color'));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء الوسم')
            : $this->error($result['error'], 422);
    }

    public function updateTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->updateTag($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث الوسم')
            : $this->error($result['error'], 422);
    }

    public function deleteTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->deleteTag($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف الوسم')
            : $this->error($result['error'], 422);
    }

    public function assignSubscriberTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->assignTag($this->uid(), (int) ($params['id'] ?? 0), (int) $this->get('tag_id'));
        return $result['success']
            ? $this->success([], 'تم إضافة الوسم')
            : $this->error($result['error'], 422);
    }

    public function removeSubscriberTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->contacts()->removeTag($this->uid(), (int) ($params['id'] ?? 0), (int) ($params['tagId'] ?? 0));
        return $this->success([], 'تمت إزالة الوسم');
    }

    // ----- Segments -----

    public function segments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $list = $this->contacts()->segments($this->uid());
        foreach ($list as &$seg) {
            $seg['conditions'] = json_decode((string) ($seg['conditions'] ?? '[]'), true) ?: [];
        }
        return $this->success(['segments' => $list]);
    }

    public function createSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->createSegment($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء الشريحة')
            : $this->error($result['error'], 422);
    }

    public function updateSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->updateSegment($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث الشريحة')
            : $this->error($result['error'], 422);
    }

    public function deleteSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->deleteSegment($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف الشريحة')
            : $this->error($result['error'], 422);
    }

    public function segmentPreview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->evaluateSegment($this->uid(), (int) ($params['id'] ?? 0), [], 10);
        return $this->success([
            'count' => $result['count'] ?? 0,
            'preview' => array_slice($result['data'] ?? [], 0, 10),
        ]);
    }

    // ----- Suppressions -----

    public function suppressions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->suppressions(
            $this->uid(),
            ['type' => $this->get('type'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function addSuppression(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->addSuppression(
            $this->uid(),
            (string) $this->get('email'),
            (string) $this->get('type', 'manual'),
            $this->get('reason')
        );
        return $result['success']
            ? $this->success([], 'تمت إضافة العنوان إلى قائمة الممنوعين')
            : $this->error($result['error'], 422);
    }

    public function removeSuppression(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->contacts()->removeSuppression($this->uid(), (int) ($params['id'] ?? 0));
        return $this->success([], 'تمت إزالة العنوان من قائمة الممنوعين');
    }

    // ============================================================
    //  API: Templates
    // ============================================================

    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'templates' => (new EmailTemplate())->where(['user_id' => $this->uid()], ['created_at' => 'DESC']),
            'variables' => EmailRenderer::variables(),
            'categories' => (new EmailTemplateEditorService())->categories(),
        ]);
    }

    /** معرض القوالب المدمجة + أنواع البلوكات للمحرر المرئي. */
    public function templateCatalog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $editor = new EmailTemplateEditorService();
        return $this->success([
            'catalog' => $editor->catalog(),
            'categories' => $editor->categories(),
            'block_types' => $editor->blockTypes(),
        ]);
    }

    /** إنشاء قالب من معرض القوالب المدمجة. */
    public function createTemplateFromGallery(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->createFromCatalog($this->uid(), (string) $this->get('catalog_key', ''));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إضافة القالب إلى قوالبك')
            : $this->error($result['error'], 422);
    }

    /** تحويل بلوكات JSON إلى HTML (يستخدمه المحرر المرئي للمعاينة). */
    public function renderBlocks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $raw = (string) $this->get('blocks', '[]');
        $blocks = json_decode($raw, true);
        if (!is_array($blocks)) {
            return $this->error('بنية البلوكات غير صالحة', 422);
        }
        $html = (new EmailTemplateEditorService())->blocksToHtml($blocks);
        return $this->success(['html' => $html]);
    }

    /** نسخ قالب مملوك. */
    public function duplicateTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->duplicateTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم نسخ القالب')
            : $this->error($result['error'], 404);
    }

    /** تفعيل/إلغاء المشاركة العامة لقالب (body: {enabled: true/false}). */
    public function shareTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $enabled = filter_var($this->get('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $result = (new EmailTemplateEditorService())->setShared($this->uid(), (int) ($params['id'] ?? 0), $enabled);
        if (!$result['success']) {
            return $this->error($result['error'], 404);
        }
        $url = '';
        if ($result['share_token']) {
            $base = rtrim(defined('APP_URL') ? APP_URL : 'https://tourfecto.com', '/');
            $url = $base . '/email-marketing/templates/shared/' . rawurlencode((string) $result['share_token']);
        }
        return $this->success(['share_token' => $result['share_token'], 'share_url' => $url], $enabled ? 'تم تفعيل المشاركة' : 'تم إيقاف المشاركة');
    }

    /** جلب قالب مشترك عامًا (بدون تحقق ملكية). */
    public function getSharedTemplate(array $params = []): array
    {
        $shared = (new EmailTemplateEditorService())->byShareToken((string) ($params['token'] ?? ''));
        if (!$shared) {
            return $this->error('القالب المشترك غير موجود', 404);
        }
        return $this->success([
            'name' => $shared['name'],
            'subject' => $shared['subject'],
            'category' => $shared['category'],
            'blocks' => $shared['blocks'],
            'html' => $shared['html_body'],
        ]);
    }

    /** استيراد قالب مشترك إلى حساب المستخدم. */
    public function importSharedTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->importShared($this->uid(), (string) ($params['token'] ?? ''));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم استيراد القالب إلى حسابك')
            : $this->error($result['error'], 404);
    }

    /** نسخ حملة إلى مسودة جديدة. */
    public function duplicateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->duplicateCampaign($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم نسخ الحملة')
            : $this->error($result['error'], 404);
    }

    public function createTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (trim((string) $this->get('name')) === '') {
            return $this->error('اسم القالب مطلوب', 422);
        }
        $template = new EmailTemplate([
            'user_id' => $this->uid(),
            'name' => trim((string) $this->get('name')),
            'subject' => (string) $this->get('subject', ''),
            'category' => $this->get('category') ? (string) $this->get('category') : null,
            'html_body' => (string) $this->get('html_body', ''),
            'blocks' => $this->get('blocks') !== null ? (string) $this->get('blocks') : null,
        ]);
        $id = $template->save();
        return $id ? $this->success(['id' => (int) $id], 'تم إنشاء القالب') : $this->error('تعذر الحفظ', 422);
    }

    public function getTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        $row = $template->toArray();
        $row['blocks'] = json_decode((string) ($row['blocks'] ?? 'null'), true) ?: [];
        return $this->success($row);
    }

    public function updateTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        foreach (['name', 'subject', 'html_body', 'blocks', 'category'] as $field) {
            if ($this->get($field) !== null) {
                $template->setAttribute($field, (string) $this->get($field));
            }
        }
        $template->save();
        return $this->success([], 'تم تحديث القالب');
    }

    public function deleteTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        $template->delete();
        return $this->success([], 'تم حذف القالب');
    }

    public function previewTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        $renderer = new EmailRenderer();
        $html = $renderer->personalize(
            (string) $template->getAttribute('html_body'),
            ['name' => 'مثال المستخدم', 'first_name' => 'مثال', 'email' => 'client@example.com', 'company_name' => 'شركتك', 'campaign_name' => 'حملة تجريبية']
        );
        return $this->success(['html' => $html, 'subject' => (string) $template->getAttribute('subject')]);
    }

    /** معاينة HTML عارض (قبل الحفظ) - يخصص المتغيرات فقط من غير تتبع. */
    public function previewHtml(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $renderer = new EmailRenderer();
        $html = $renderer->personalize(
            (string) $this->get('html', ''),
            ['name' => 'مثال المستخدم', 'first_name' => 'مثال', 'email' => 'client@example.com', 'company_name' => 'شركتك', 'campaign_name' => 'حملة تجريبية']
        );
        return $this->success(['html' => $html]);
    }

    // ============================================================
    //  API: Campaigns
    // ============================================================

    public function campaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'campaigns' => $this->campaignService->list($this->uid()),
            'lists' => $this->listService->lists($this->uid()),
            'segments' => $this->contacts()->segments($this->uid()),
            'templates' => (new EmailTemplate())->where(['user_id' => $this->uid()], ['created_at' => 'DESC']),
        ]);
    }

    public function createCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء الحملة')
            : $this->error($result['error'], 422);
    }

    public function updateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->update($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث الحملة')
            : $this->error($result['error'], 404);
    }

    public function deleteCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف الحملة')
            : $this->error($result['error'], 404);
    }

    public function getCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaign = $this->campaignService->get($this->uid(), (int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        return $this->success($campaign);
    }

    /**
     * إرسال فوري: يجهّز المستلمين ثم يدفع مهمة في طابور الإرسال.
     */
    public function sendCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = $this->campaignService->get($this->uid(), $campaignId);
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!in_array($campaign['status'], ['draft', 'scheduled', 'sending', 'failed'], true)) {
            return $this->error('لا يمكن إرسال حملة بهذه الحالة', 422);
        }

        // حملة فشلت بالكامل: إعادة تعيين المستلمين الفاشلين إلى pending
        if ($campaign['status'] === 'failed') {
            $retried = $this->campaignService->retryFailed($this->uid(), $campaignId);
            if (!$retried['success']) {
                return $this->error($retried['error'], 422);
            }
        }

        $prepared = $this->campaignService->prepareRecipients($this->uid(), $campaignId);
        if (!$prepared['success']) {
            return $this->error($prepared['error'], 422);
        }
        if ($prepared['total'] <= 0) {
            return $this->error('لا يوجد مشتركون نشطون في الجمهور المستهدف', 422);
        }

        if (!class_exists('QueueManager')) {
            return $this->error('نظام الطوابير غير متاح', 500);
        }
        $queue = new QueueManager();
        $queue->push(SendEmailCampaignBatchJob::class, [
            'user_id' => $this->uid(),
            'campaign_id' => $campaignId,
        ], 'email');

        $this->db->query(
            "UPDATE email_campaigns SET status = 'sending' WHERE id = ?",
            [$campaignId]
        );

        return $this->success(['total' => $prepared['total']], 'بدأ إرسال الحملة (' . $prepared['total'] . ' مستلم) — سيتم الإرسال عبر cron');
    }

    public function sendTestEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $toEmail = (string) $this->get('email');
        $result = $this->campaignService->sendTest($this->uid(), (int) ($params['id'] ?? 0), $toEmail);
        return $result['success']
            ? $this->success([], 'تم إرسال بريد الاختبار')
            : $this->error($result['error'] ?? 'تعذر الإرسال', 422);
    }

    public function scheduleCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->schedule($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('scheduled_at'));
        return $result['success']
            ? $this->success([], 'تمت جدولة الحملة')
            : $this->error($result['error'], 422);
    }

    public function cancelCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->cancel($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم إلغاء الحملة المجدولة')
            : $this->error($result['error'], 404);
    }

    public function campaignReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $report = $this->campaignService->report($this->uid(), (int) ($params['id'] ?? 0));
        if (!$report) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $report['recipients'] = $this->db->query(
            "SELECT r.id, r.email, r.name, r.status, r.opened_at, r.clicked_at, r.open_count, r.click_count, r.error_message,
                    s.unsubscribe_token
             FROM email_campaign_recipients r
             LEFT JOIN email_subscribers s ON s.id = r.subscriber_id
             WHERE r.campaign_id = ?
             ORDER BY r.id ASC
             LIMIT 200",
            [$report['id']]
        );
        return $this->success($report);
    }

    // ============================================================
    //  API: Automations (المرحلة 3)
    // ============================================================

    private function automationService(): EmailAutomationService
    {
        return new EmailAutomationService();
    }

    private function smtpSettingsService(): SmtpSettingsService
    {
        return new SmtpSettingsService();
    }

    private function transactionalService(): TransactionalEmailService
    {
        return new TransactionalEmailService();
    }

    private function abTestService(): AbTestService
    {
        return new AbTestService();
    }

    public function automations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'automations' => $this->automationService()->list($this->uid()),
            'triggers' => EmailAutomation::triggers(),
            'step_types' => EmailAutomationStep::types(),
        ]);
    }

    public function createAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء سير العمل')
            : $this->error($result['error'], 422);
    }

    public function getAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $automation = $this->automationService()->get($this->uid(), (int) ($params['id'] ?? 0));
        if (!$automation) {
            return $this->error('سير العمل غير موجود', 404);
        }
        return $this->success($automation);
    }

    public function updateAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->update($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث سير العمل')
            : $this->error($result['error'], 422);
    }

    public function deleteAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف سير العمل')
            : $this->error($result['error'], 422);
    }

    public function setAutomationSteps(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->setSteps($this->uid(), (int) ($params['id'] ?? 0), (array) $this->get('steps', []));
        return $result['success']
            ? $this->success([], 'تم حفظ الخطوات')
            : $this->error($result['error'], 422);
    }

    public function setAutomationStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->setStatus($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('status'));
        return $result['success']
            ? $this->success([], 'تم تحديث الحالة')
            : $this->error($result['error'], 422);
    }

    public function runAutomationsDue(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->processDue();
        return $this->success($result, 'تمت معالجة السير القادمة');
    }

    // ============================================================
    //  API: SMTP Settings
    // ============================================================

    public function smtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $svc = $this->smtpSettingsService();
        $settings = $svc->get($this->uid());
        if ($settings) {
            unset($settings['password']);
        }
        return $this->success([
            'settings' => $settings,
            'effective' => $this->safeEffectiveSettings($svc),
        ]);
    }

    public function saveSmtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->smtpSettingsService()->save($this->uid(), $this->all());
        return $result['success']
            ? $this->success([], 'تم حفظ إعدادات SMTP')
            : $this->error($result['error'], 422);
    }

    // ============================================================
    //  API: SMTP Delivery Webhook (بند 1 - تتبع الارتدادات/الشكاوى)
    // ============================================================

    /**
     * GET /api/email-marketing/smtp/webhook
     * حالة + رابط webhook تتبع التسليم (المفتاح يعرض مقنّعًا - النص الكامل
     * بيتكشف لحظة التوليد/التجديد بس).
     */
    public function deliveryWebhookSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $settings = $this->smtpSettingsService()->get($uid);
        $enabled = (bool) ($settings['delivery_webhook_enabled'] ?? false);
        $secret = trim((string) ($settings['delivery_webhook_secret'] ?? ''));
        $url = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/webhooks/email/delivery-status/' . $uid;

        return $this->success([
            'enabled' => $enabled,
            'url' => $url,
            'has_secret' => $secret !== '',
            'secret_masked' => $this->maskWebhookSecret($secret),
        ]);
    }

    /**
     * POST /api/email-marketing/smtp/webhook
     * تفعيل/تعطيل الـ webhook + توليد/تجديد المفتاح السري (regenerate).
     */
    public function saveDeliveryWebhook(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->smtpSettingsService()->saveDeliveryWebhook($this->uid(), $this->all());
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر حفظ إعدادات الـ webhook', 422);
        }
        return $this->success([
            'enabled' => (bool) $result['enabled'],
            'secret' => $result['secret'] ?? '',
            'secret_masked' => $this->maskWebhookSecret((string) ($result['secret'] ?? '')),
        ], 'تم حفظ إعدادات webhook تتبع التسليم');
    }

    /** إخفاء المفتاح السري (أول 4 + آخر 4) — يظهر كاملًا لحظة التوليد فقط. */
    private function maskWebhookSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return strlen($secret) > 8
            ? substr($secret, 0, 4) . '…' . substr($secret, -4)
            : '••••••';
    }

    public function testSmtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $data = $this->all();
        $result = $this->smtpSettingsService()->test($this->uid(), $data ?: null);
        return $result['success']
            ? $this->success([], 'تم الاتصال والمصادقة بنجاح')
            : $this->error('فشل الاختبار: ' . ($result['error'] ?? 'خطأ غير معروف'), 422);
    }

    public function deleteSmtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->smtpSettingsService()->delete($this->uid());
        return $result['success']
            ? $this->success([], $result['deleted'] ? 'تم حذف إعدادات SMTP' : 'لا توجد إعدادات محفوظة')
            : $this->error('تعذر حذف الإعدادات', 422);
    }

    // ============================================================
    //  API: Transactional Emails
    // ============================================================

    public function transactionalTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'templates' => $this->transactionalService()->listTemplates($this->uid()),
            'variables' => EmailRenderer::variables(),
        ]);
    }

    public function createTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->createTemplate($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء قالب المعاملات')
            : $this->error($result['error'], 422);
    }

    public function getTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = $this->transactionalService()->getTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $template
            ? $this->success(['template' => $template])
            : $this->error('القالب غير موجود', 404);
    }

    public function updateTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->updateTemplate($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث القالب')
            : $this->error($result['error'], 422);
    }

    public function deleteTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->deleteTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف القالب')
            : $this->error($result['error'], 422);
    }

    public function duplicateTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->duplicateTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم نسخ القالب')
            : $this->error($result['error'], 422);
    }

    public function sendTransactionalEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $body = $this->all();
        $result = $this->transactionalService()->send(
            $this->uid(),
            (int) ($body['template_id'] ?? 0),
            (string) ($body['to_email'] ?? ''),
            (array) ($body['data'] ?? []),
            (array) ($body['options'] ?? [])
        );
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إرسال رسالة المعاملات')
            : $this->error('فشل الإرسال: ' . ($result['error'] ?? 'خطأ غير معروف'), 422);
    }

    public function transactionalLogs(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $filters = array_filter([
            'status' => $this->get('status'),
            'template_id' => $this->get('template_id'),
            'email' => $this->get('email'),
            'limit' => $this->get('limit'),
        ], fn ($v) => $v !== null && $v !== '');
        return $this->success(['logs' => $this->transactionalService()->logs($this->uid(), $filters)]);
    }

    public function transactionalStats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['stats' => $this->transactionalService()->stats($this->uid())]);
    }

    // ============================================================
    //  API: A/B Tests
    // ============================================================

    public function abTests(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'ab_tests' => $this->abTestService()->list($this->uid()),
            'campaigns' => $this->campaignService->list($this->uid()),
        ]);
    }

    public function createAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء اختبار أ/ب')
            : $this->error($result['error'], 422);
    }

    public function getAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $ab = $this->abTestService()->get($this->uid(), (int) ($params['id'] ?? 0));
        return $ab
            ? $this->success(['ab_test' => $ab])
            : $this->error('الاختبار غير موجود', 404);
    }

    public function deleteAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف الاختبار')
            : $this->error($result['error'], 422);
    }

    public function setAbTestVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $body = $this->all();
        $result = $this->abTestService()->setVariantContent(
            $this->uid(),
            (int) ($params['id'] ?? 0),
            (string) ($body['variant'] ?? ''),
            (array) $body
        );
        return $result['success']
            ? $this->success([], 'تم تحديث المتغير')
            : $this->error($result['error'], 422);
    }

    public function startAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->start($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([
                'a' => $result['a'],
                'b' => $result['b'],
                'total' => $result['total'],
            ], 'تم تشغيل الاختبار وتقسيم الجمهور')
            : $this->error($result['error'], 422);
    }

    public function sendAbTestBatch(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $abTestId = (int) ($params['id'] ?? 0);
        $test = $this->abTestService()->get($this->uid(), $abTestId);
        if (!$test) {
            return $this->error('الاختبار غير موجود', 404);
        }
        if ($test['status'] !== 'running') {
            return $this->error('الاختبار غير قيد التشغيل', 422);
        }

        if (!class_exists('QueueManager')) {
            return $this->error('نظام الطوابير غير متاح', 500);
        }
        $queue = new QueueManager();
        $queue->push(SendAbTestBatchJob::class, [
            'user_id' => $this->uid(),
            'ab_test_id' => $abTestId,
        ], 'email');

        return $this->success(['queued' => true], 'بدأ إرسال دفعة اختبار أ/ب — سيتم الإرسال عبر cron');
    }

    /**
     * إرسال بريد اختبار لأحد متغيرات اختبار أ/ب (أو كلاهما) لمعاينة الشكل
     * قبل الإرسال الفعلي — دون التأثير على الجمهور.
     */
    public function sendAbTestTestEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $abTestId = (int) ($params['id'] ?? 0);
        $variant = (string) ($this->data['variant'] ?? 'all');
        $toEmail = trim((string) ($this->data['email'] ?? ''));
        $result = $this->abTestService()->sendTest($this->uid(), $abTestId, $variant, $toEmail);
        return $result['success']
            ? $this->success(['sent' => $result['sent']], 'تم إرسال رسائل الاختبار بنجاح')
            : $this->error($result['error'] ?? 'فشل إرسال رسائل الاختبار', 422);
    }

    public function abTestReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $report = $this->abTestService()->report($this->uid(), (int) ($params['id'] ?? 0));
        return $report
            ? $this->success(['report' => $report])
            : $this->error('الاختبار غير موجود', 404);
    }

    public function declareAbTestWinner(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->declareWinner(
            $this->uid(),
            (int) ($params['id'] ?? 0),
            (string) $this->get('winner', '')
        );
        return $result['success']
            ? $this->success([], 'تم إعلان الفائز')
            : $this->error($result['error'], 422);
    }

    public function applyAbTestWinner(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->applyWinnerToBase($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم نسخ الفائز إلى الحملة الأساسية')
            : $this->error($result['error'], 422);
    }

    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->uid();
        $campaigns = $this->db->query(
            "SELECT c.id, c.name, c.status, c.total_recipients, c.sent_count, c.opened_count, c.clicked_count,
                    c.unsubscribed_count, c.bounced_count, c.created_at, c.sent_at
             FROM email_campaigns c WHERE c.user_id = ?
             ORDER BY c.created_at DESC",
            [$userId]
        );

        $sentTotal = 0;
        $openedTotal = 0;
        $clickedTotal = 0;
        $unsubTotal = 0;
        foreach ($campaigns as $c) {
            $sentTotal += (int) $c['sent_count'];
            $openedTotal += (int) $c['opened_count'];
            $clickedTotal += (int) $c['clicked_count'];
            $unsubTotal += (int) $c['unsubscribed_count'];
        }

        $subscribers = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers WHERE user_id = ? AND status = 'subscribed'",
            [$userId]
        )[0]['total'];

        return $this->success([
            'kpis' => [
                ['label' => 'إجمالي المرسل', 'value' => $sentTotal, 'icon' => '📨'],
                ['label' => 'مرات الفتح', 'value' => $openedTotal, 'icon' => '👁'],
                ['label' => 'الكليكات', 'value' => $clickedTotal, 'icon' => '🖱'],
                ['label' => 'إلغاء الاشتراك', 'value' => $unsubTotal, 'icon' => '🚫'],
                ['label' => 'المشتركون النشطون', 'value' => $subscribers, 'icon' => '👥'],
            ],
            'open_rate' => $sentTotal > 0 ? round($openedTotal / $sentTotal * 100, 1) : 0,
            'click_rate' => $sentTotal > 0 ? round($clickedTotal / $sentTotal * 100, 1) : 0,
            'campaigns' => $campaigns,
        ]);
    }

    // ============================================================
    //  Public tracking endpoints (من غير Auth - بيطلبها عملاء البريد)
    // ============================================================

    public function trackOpen(array $params = []): array
    {
        $raw = (string) ($params['token'] ?? '');
        $token = preg_replace('/\.gif$/', '', $raw);

        if ($token === '') {
            $this->gif();
            exit;
        }
        $this->trackingService->recordOpen($token);
        $this->trackingService->recordTransactionalOpen($token);
        $this->trackingService->recordAutomationOpen($token);
        $this->gif();
        exit;
    }

    public function trackClick(array $params = []): array
    {
        $clickToken = (string) ($params['click_token'] ?? '');
        $encoded = (string) $this->get('u', '');
        $url = $this->trackingService->recordClick($clickToken, $encoded);
        if ($url === null) {
            $url = $this->trackingService->recordTransactionalClick($clickToken, $encoded);
        }
        if ($url === null) {
            $url = $this->trackingService->recordAutomationClick($clickToken, $encoded);
        }

        if ($url === null) {
            http_response_code(404);
            echo 'Invalid tracking link.';
            exit;
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    public function unsubscribeLink(array $params = []): array
    {
        $token = (string) ($params['unsubscribe_token'] ?? '');
        $this->trackingService->unsubscribe($token);

        // RFC 8058 one-click: عملاء البريد (Gmail/Yahoo) يرسلون POST
        // ويتوقعون استجابة 2xx بسيطة بجسم قصير - لا صفحة HTML كاملة.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '1';
            exit;
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>إلغاء الاشتراك</title>'
            . '<body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif;background:#f9fafb;color:#111827;">'
            . '<div style="text-align:center;padding:32px;max-width:420px;background:#fff;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);">'
            . '<div style="font-size:44px;">🙏</div>'
            . '<h2 style="margin:16px 0 8px;">تم إلغاء اشتراكك بنجاح</h2>'
            . '<p style="color:#6b7280;font-size:14px;">لن تستلم رسائل تسويقية من هذه القائمة بعد الآن. إذا أخطأت في الإلغاء يمكنك إعادة الاشتراك في أي وقت.</p>'
            . '</div></body></html>';
        exit;
    }

    // ============================================================
    //  Helpers
    // ============================================================

    private function deliveryStatus(): array
    {
        $svc = new SmtpSettingsService();
        $ready = $svc->isReady($this->uid());
        $effective = $svc->settingsForUser($this->uid());
        $from = $effective['from_email'] ?: '—';
        return [
            'provider' => 'smtp',
            'smtp' => $ready,
            'from_email' => $from,
            'label' => $ready
                ? "إرسال عبر SMTP ({$effective['host']} — من {$from})"
                : 'غير مكوّن — اضبط إعدادات SMTP من تبويب الإعدادات',
        ];
    }

    private function safeEffectiveSettings(SmtpSettingsService $svc): array
    {
        $settings = $svc->settingsForUser($this->uid());
        $settings['password'] = $settings['password'] !== ''
            ? str_repeat('•', min(10, strlen($settings['password'])))
            : '';
        $settings['ready'] = $svc->isReady($this->uid());
        return $settings;
    }

    private function parseImportData(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // JSON array أولًا
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // غير كده فسر كـ CSV: email,name في كل سطر
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line);
            if (isset($parts[0])) {
                $rows[] = ['email' => $parts[0], 'name' => $parts[1] ?? ''];
            }
        }
        return $rows;
    }

    private function gif(): void
    {
        header('Content-Type: image/gif');
        header('Content-Length: 43');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    private function emailTabsHtml(string $active): string
    {
        $tabs = [
            'dashboard' => ['/email-marketing', '📊 لوحة التحكم'],
            'lists' => ['/email-marketing/lists', '👥 الجمهور'],
            'contacts' => ['/email-marketing/contacts', '📇 جهات الاتصال'],
            'templates' => ['/email-marketing/templates', '🎨 القوالب'],
            'campaigns' => ['/email-marketing/campaigns', '🚀 الحملات'],
            'automations' => ['/email-marketing/automations', '⚙️ الأتمتة'],
            'ab_tests' => ['/email-marketing/ab-tests', '🧪 اختبار أ/ب'],
            'transactional' => ['/email-marketing/transactional', '📨 المعاملات'],
            'settings' => ['/email-marketing/settings', '🔌 الإعدادات'],
            'reports' => ['/email-marketing/reports', '📈 التقارير'],
        ];
        $html = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">';
        foreach ($tabs as $key => [$href, $label]) {
            $activeClass = $key === $active ? 'p-btn primary xs' : 'p-btn xs';
            $html .= "<a href=\"{$href}\" class=\"{$activeClass}\">{$label}</a>";
        }
        $html .= '</div>';
        return $html;
    }

    // ============================================================
    //  Page scripts
    // ============================================================

    private function indexJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadDashboard() {
            const r = await emApi('/dashboard');
            if (!r.success) return;
            const kpis = r.data.kpis.map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:26px;">${k.icon}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k.value}</div>
                    <div style="font-size:12px;color:#6b7280;">${k.label}</div>
                </div>`).join('');
            document.getElementById('emKpis').innerHTML = kpis;

            const d = r.data.delivery;
            document.getElementById('emDeliveryStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">${d.smtp ? '📧' : '⚠️'}</span>
                    <span style="font-size:14px;">${d.label}</span>
                </div>`;

            const cs = r.data.recent_campaigns || [];
            document.getElementById('emRecentCampaigns').innerHTML = cs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الحملة</th><th>الحالة</th><th>المرسل</th><th>الفتح</th><th>الكليك</th><th></th></tr></thead>
                    <tbody>${cs.map(c => `
                        <tr>
                            <td>${c.name}</td>
                            <td>${statusBadge(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${c.clicked_count}</td>
                            <td><a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">تقرير</a></td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد حملات بعد — أنشئ حملتك الأولى من تبويب الحملات.</p>';

            const ls = r.data.top_lists || [];
            document.getElementById('emTopLists').innerHTML = ls.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>القائمة</th><th>المشتركون</th><th></th></tr></thead>
                    <tbody>${ls.map(l => `
                        <tr><td>${l.name}</td><td>${l.actual_count}</td>
                        <td><a class="p-btn xs" href="/email-marketing/lists">إدارة</a></td></tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">أنشئ أول قائمة جمهور من تبويب الجمهور.</p>';
        }
        function statusBadge(s) {
            const map = {draft:'مسودة', scheduled:'مجدولة', sending:'قيد الإرسال', sent:'أُرسلت', cancelled:'ملغاة', failed:'فشلت'};
            return `<span style="background:#f3f4f6;padding:2px 10px;border-radius:20px;font-size:12px;">${map[s] || s}</span>`;
        }
        loadDashboard();
        JS;
    }

    private function listsJs(): string
    {
        return <<<'JS'
        let emLists = [];
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadLists() {
            const r = await emApi('/lists');
            if (!r.success) return;
            emLists = r.data.lists;
            const sel = document.getElementById('emSubListFilter');
            sel.innerHTML = '<option value="0">كل القوائم</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name} (${l.actual_count})</option>`).join('');
            const sl = document.getElementById('subList');
            sl.innerHTML = '<option value="0">بدون قائمة</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name}</option>`).join('');
            const il = document.getElementById('importList');
            il.innerHTML = '<option value="0">بدون قائمة</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name}</option>`).join('');
            const cl = document.getElementById('campaignList');
            if (cl) cl.innerHTML = '<option value="0">اختر قائمة</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name} (${l.actual_count})</option>`).join('');

            document.getElementById('emListsTable').innerHTML = emLists.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الاسم</th><th>الوصف</th><th>المشتركون</th><th>أُنشئت</th><th></th></tr></thead>
                    <tbody>${emLists.map(l => `
                        <tr>
                            <td><b>${l.name}</b></td>
                            <td style="color:#6b7280;">${l.description || '—'}</td>
                            <td>${l.actual_count}</td>
                            <td style="color:#6b7280;">${(l.created_at || '').slice(0,10)}</td>
                            <td style="text-align:left;white-space:nowrap;">
                                <button class="p-btn xs" onclick="editList(${l.id}, ${JSON.stringify(l.name)}, ${JSON.stringify(l.description || '')})">تعديل</button>
                                <button class="p-btn xs danger" onclick="removeList(${l.id})">حذف</button>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد قوائم بعد.</p>';
            loadSubscribers();
        }
        function openListModal(id, name, desc) {
            document.getElementById('listModalTitle').textContent = id ? 'تعديل القائمة' : 'قائمة جديدة';
            document.getElementById('listId').value = id || '';
            document.getElementById('listName').value = name || '';
            document.getElementById('listDesc').value = desc || '';
            document.getElementById('listModal').classList.add('open');
        }
        function editList(id, name, desc) { openListModal(id, name, desc); }
        async function saveList() {
            const id = document.getElementById('listId').value;
            const name = document.getElementById('listName').value.trim();
            if (!name) { notify('اسم القائمة مطلوب'); return; }
            const path = id ? '/lists/' + id : '/lists';
            const opts = {
                method: id ? 'PATCH' : 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({name, description: document.getElementById('listDesc').value})
            };
            const r = await emApi(path, opts);
            if (r.success) { document.getElementById('listModal').classList.remove('open'); loadLists(); }
            else notify(r.error || 'خطأ');
        }
        async function removeList(id) {
            if (!confirm('حذف هذه القائمة؟ (المشتركون أنفسهم لا يُحذفون)')) return;
            const r = await emApi('/lists/' + id, {method: 'DELETE'});
            if (r.success) loadLists(); else notify(r.error);
        }
        let emSubPage = 1;
        async function loadSubscribers() {
            const listId = document.getElementById('emSubListFilter').value;
            const r = await emApi('/subscribers?list_id=' + listId + '&page=' + emSubPage + '&per_page=50');
            if (!r.success) return;
            const d = r.data;
            document.getElementById('emSubCountLabel').textContent = '(' + d.total + ')';
            const rows = (d.data || []).map(s => `
                <tr>
                    <td>${s.email}</td>
                    <td>${s.name || '—'}</td>
                    <td>${subStatusBadge(s.status)}</td>
                    <td>${s.list_count} قوائم</td>
                    <td style="text-align:left;white-space:nowrap;">
                        ${s.status === 'subscribed'
                            ? `<button class="p-btn xs" onclick="unsubSub(${s.id})">إلغاء اشتراك</button>` : ''}
                        <button class="p-btn xs danger" onclick="delSub(${s.id})">حذف</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emSubscribersTable').innerHTML = rows
                ? `<table class="p-table" style="width:100%;"><thead><tr><th>البريد</th><th>الاسم</th><th>الحالة</th><th>القوائم</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا يوجد مشتركون.</p>';
            const pages = Math.max(1, Math.ceil(d.total / d.per_page));
            document.getElementById('emSubscribersPager').innerHTML = `
                <button class="p-btn xs" ${emSubPage <= 1 ? 'disabled' : ''} onclick="emSubPage--;loadSubscribers()">السابق</button>
                <span style="margin:0 10px;font-size:13px;">صفحة ${emSubPage} من ${pages}</span>
                <button class="p-btn xs" ${emSubPage >= pages ? 'disabled' : ''} onclick="emSubPage++;loadSubscribers()">التالي</button>`;
        }
        function subStatusBadge(s) {
            const map = {subscribed:'نشط', unsubscribed:'أُلغي اشتراكه', bounced:'ارتداد'};
            return `<span style="background:${s==='subscribed'?'#dcfce7':s==='unsubscribed'?'#fee2e2':'#fef3c7'};padding:2px 10px;border-radius:20px;font-size:12px;">${map[s]||s}</span>`;
        }
        function openSubscriberModal() {
            document.getElementById('subEmail').value = '';
            document.getElementById('subName').value = '';
            document.getElementById('subscriberModal').classList.add('open');
        }
        async function saveSubscriber() {
            const body = {
                email: document.getElementById('subEmail').value.trim(),
                name: document.getElementById('subName').value.trim(),
                list_id: document.getElementById('subList').value
            };
            const r = await emApi('/subscribers', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            if (r.success) { document.getElementById('subscriberModal').classList.remove('open'); loadSubscribers(); }
            else notify(r.error);
        }
        function openImportModal() { document.getElementById('importData').value = ''; document.getElementById('importResult').innerHTML=''; document.getElementById('importModal').classList.add('open'); }
        async function importSubscribers() {
            const body = {list_id: document.getElementById('importList').value, data: document.getElementById('importData').value};
            const r = await emApi('/subscribers/import', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            document.getElementById('importResult').innerHTML = r.success
                ? `✅ تم الاستيراد: ${r.data.added} جديد، ${r.data.updated} محدث، ${r.data.invalid} غير صالح`
                : '❌ ' + (r.error || 'خطأ');
            if (r.success) setTimeout(loadSubscribers, 600);
        }
        async function unsubSub(id) {
            if (!confirm('إلغاء اشتراك هذا المشترك نهائيًا؟')) return;
            const r = await emApi('/subscribers/' + id + '/unsubscribe', {method:'POST'});
            if (r.success) loadSubscribers(); else notify(r.error);
        }
        async function delSub(id) {
            if (!confirm('حذف هذا المشترك نهائيًا؟')) return;
            const r = await emApi('/subscribers/' + id, {method:'DELETE'});
            if (r.success) loadSubscribers(); else notify(r.error);
        }
        loadLists();
        JS;
    }

    private function templatesJs(): string
    {
        return <<<'JS'
        let activeTemplatesTab = 'mine';
        const CATEGORY_LABELS = {'welcome':'ترحيب','newsletter':'نشرة إخبارية','promo':'ترويجي','event':'أحداث','transactional':'معاملات','holiday':'مناسبات'};
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadTemplates() {
            const r = await emApi('/templates');
            if (!r.success) return;
            const list = r.data.templates || [];
            document.getElementById('emTemplatesGrid').innerHTML = list.length ? `
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;">
                    ${list.map(t => `
                        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:6px;">
                            <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                                ${t.name}
                                ${t.category ? `<span class="em-tag-pill" style="font-size:10px;">${CATEGORY_LABELS[t.category] || t.category}</span>` : ''}
                                ${t.share_token ? '<span class="em-tag-pill" style="font-size:10px;background:#064e3b;">مُشارك</span>' : ''}
                            </div>
                            <div style="font-size:12px;color:#6b7280;">${t.subject || 'بدون موضوع'}</div>
                            <div style="font-size:11px;color:#9ca3af;">${(t.html_body || '').length} حرف${t.blocks ? ' · بلوكات' : ''}</div>
                            <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                                <button class="p-btn xs" onclick="window.location.href='/email-marketing/templates/builder?template_id=${t.id}'">🎨 تحرير</button>
                                <button class="p-btn xs" onclick="previewT(${t.id})">👁 معاينة</button>
                                <button class="p-btn xs" onclick="duplicateTemplate(${t.id})">⧉ نسخ</button>
                                <button class="p-btn xs" onclick="shareTemplate(${t.id})">🔗</button>
                                <button class="p-btn xs danger" onclick="removeTemplate(${t.id})">حذف</button>
                            </div>
                        </div>`).join('')}
                </div>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد قوالب — استخدم المعرض أو المحرر البصري لبناء أول قالب.</p>';
        }
        async function loadGallery() {
            const r = await emApi('/templates/catalog');
            if (!r.success) return;
            const catalog = Object.entries(r.data.catalog || {});
            const cats = [['all','الكل'], ...Object.entries(r.data.categories || {})];
            let filter = 'all';
            document.getElementById('emGalleryGrid').innerHTML = `
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                    ${cats.map(([k,v]) => `<button class="p-btn xs ${filter===k?'primary':''}" data-cat="${k}" onclick="filterGallery('${k}')">${v}</button>`).join('')}
                </div>
                <div id="emGalleryCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;">
                    ${catalog.map(([key,item]) => `
                        <div class="gallery-card" data-cat="${item.category}" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;">
                            <div style="height:120px;background:linear-gradient(135deg,#1e3a8a,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;padding:10px;text-align:center;">${item.name}</div>
                            <div style="padding:12px;display:flex;flex-direction:column;gap:6px;flex:1;">
                                <div style="font-size:12px;color:#6b7280;">${item.description}</div>
                                <div style="font-size:11px;color:#9ca3af;">${CATEGORY_LABELS[item.category] || item.category}</div>
                                <div style="margin-top:auto;display:flex;gap:6px;">
                                    <button class="p-btn primary xs" onclick="useGallery('${key}')">استخدم القالب</button>
                                    <button class="p-btn xs" onclick="window.location.href='/email-marketing/templates/builder?gallery=${key}'">🎨 تخصيص</button>
                                </div>
                            </div>
                        </div>`).join('')}
                </div>`;
            window.galleryCat = 'all';
        }
        function filterGallery(cat) {
            window.galleryCat = cat;
            document.querySelectorAll('.gallery-card').forEach(c => {
                c.style.display = (cat === 'all' || c.dataset.cat === cat) ? '' : 'none';
            });
            document.querySelectorAll('#emGalleryGrid [data-cat]').forEach(b => b.classList.toggle('primary', b.dataset.cat === cat));
        }
        function switchTemplatesTab(tab) {
            activeTemplatesTab = tab;
            document.getElementById('emTemplatesGrid').style.display = tab === 'mine' ? '' : 'none';
            document.getElementById('emGalleryGrid').style.display = tab === 'gallery' ? '' : 'none';
            if (tab === 'gallery' && !window.galleryLoaded) { window.galleryLoaded = true; loadGallery(); }
            const btns = document.querySelectorAll('button[onclick^="switchTemplatesTab"]');
            btns.forEach(b => b.classList.toggle('primary', b.textContent.includes(tab === 'mine' ? 'قوالبك' : 'المعرض')));
        }
        async function useGallery(key) {
            const r = await emApi('/templates/from-gallery', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({catalog_key: key})});
            if (r.success) { notify('تم إضافة القالب إلى قوالبك'); loadTemplates(); switchTemplatesTab('mine'); }
            else notify(r.error);
        }
        async function duplicateTemplate(id) {
            const r = await emApi('/templates/' + id + '/duplicate', {method:'POST'});
            if (r.success) loadTemplates(); else notify(r.error);
        }
        let currentShareId = null;
        async function shareTemplate(id) {
            const r = await emApi('/templates/' + id + '/share', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({enabled:true})});
            if (r.success) {
                currentShareId = id;
                document.getElementById('shareUrl').value = window.location.origin + '/email-marketing/templates/shared/' + (r.data.share_token || '');
                document.getElementById('shareModal').classList.add('open');
                loadTemplates();
            } else notify(r.error);
        }
        async function stopSharing() {
            if (!currentShareId) return;
            const r = await emApi('/templates/' + currentShareId + '/share', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({enabled:false})});
            if (r.success) { document.getElementById('shareModal').classList.remove('open'); loadTemplates(); }
            else notify(r.error);
        }
        function copyShareUrl() {
            const el = document.getElementById('shareUrl');
            el.select(); document.execCommand('copy');
            notify('تم نسخ الرابط');
        }
        function openTemplateModal(id, name, subject, html) {
            document.getElementById('templateModalTitle').textContent = id ? 'تعديل القالب' : 'قالب جديد';
            document.getElementById('templateId').value = id || '';
            document.getElementById('templateName').value = name || '';
            document.getElementById('templateSubject').value = subject || '';
            document.getElementById('templateHtml').value = html || '';
            document.getElementById('templateModal').classList.add('open');
        }
        function editTemplate(id) {
            fetch('/api/email-marketing/templates/' + id).then(r=>r.json()).then(r => {
                if (r.success) openTemplateModal(r.data.id, r.data.name, r.data.subject, r.data.html_body);
            });
        }
        function insertVar(v) {
            const ta = document.getElementById('templateHtml');
            ta.value += v;
            ta.focus();
        }
        async function saveTemplate() {
            const id = document.getElementById('templateId').value;
            const body = {
                name: document.getElementById('templateName').value.trim(),
                subject: document.getElementById('templateSubject').value,
                html_body: document.getElementById('templateHtml').value
            };
            const path = id ? '/templates/' + id : '/templates';
            const opts = {method: id ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)};
            const r = await emApi(path, opts);
            if (r.success) { document.getElementById('templateModal').classList.remove('open'); loadTemplates(); }
            else notify(r.error);
        }
        async function previewT(id) {
            const r = await emApi('/templates/' + id + '/preview', {method:'POST'});
            if (!r.success) return;
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <div style="padding:12px 20px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">الموضوع: <b>${r.data.subject}</b></div>
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(r.data.html)}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function previewTemplateInline() {
            const r = await emApi('/templates/preview-html', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({html: document.getElementById('templateHtml').value})});
            if (!r.success) return;
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(r.data.html)}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function removeTemplate(id) {
            if (!confirm('حذف هذا القالب؟')) return;
            const r = await emApi('/templates/' + id, {method:'DELETE'});
            if (r.success) loadTemplates(); else notify(r.error);
        }
        loadTemplates();
        JS;
    }

    private function builderJs(string $initialBlocks, int $saveTarget): string
    {
        $blocks = str_replace('</', '<\\/', (string) $initialBlocks);
        $target = $saveTarget;
        $js = <<<'JS'
        const INITIAL_BLOCKS = __INITIAL_BLOCKS__;
        const SAVE_TARGET = __SAVE_TARGET__;
        let bdBlocks = [];
        let bdSelected = -1;
        const BLOCK_TYPES = [
            {type:'text', label:'نص', icon:'📝'},
            {type:'heading', label:'عنوان', icon:'🅷'},
            {type:'image', label:'صورة', icon:'🖼'},
            {type:'button', label:'زر', icon:'🔘'},
            {type:'divider', label:'فاصل', icon:'➖'},
            {type:'spacer', label:'مسافة', icon:'⬜'},
            {type:'social', label:'سوشيال', icon:'🌐'},
            {type:'html', label:'كود HTML', icon:'💻'}
        ];
        const FIELD_DEFS = {
            text: [ {k:'content', label:'المحتوى', type:'textarea'} ],
            heading: [ {k:'text', label:'النص', type:'text'}, {k:'level', label:'الحجم', type:'select', opts:[['h1','عنوان 1'],['h2','عنوان 2'],['h3','عنوان 3'],['h4','عنوان 4']]}, {k:'align', label:'المحاذاة', type:'select', opts:[['right','يمين'],['center','وسط'],['left','يسار']]} ],
            image: [ {k:'src', label:'رابط الصورة', type:'text'}, {k:'alt', label:'نص بديل', type:'text'}, {k:'width', label:'العرض (px)', type:'number'}, {k:'url', label:'رابط عند النقر', type:'text'} ],
            button: [ {k:'text', label:'نص الزر', type:'text'}, {k:'url', label:'الرابط', type:'text'}, {k:'bg', label:'لون الخلفية', type:'color'}, {k:'color', label:'لون النص', type:'color'} ],
            divider: [ {k:'color', label:'اللون', type:'color'}, {k:'thickness', label:'السماكة (px)', type:'number'} ],
            spacer: [ {k:'height', label:'الارتفاع (px)', type:'number'} ],
            social: [ {k:'networks', label:'الشبكات', type:'checkboxes', opts:['facebook','twitter','instagram','linkedin','youtube','whatsapp']} ],
            html: [ {k:'html', label:'كود HTML', type:'textarea'} ]
        };
        const NET_LABELS = {facebook:'فيسبوك', twitter:'إكس', instagram:'إنستغرام', linkedin:'لينكدإن', youtube:'يوتيوب', whatsapp:'واتساب'};

        async function emApiBuilder(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        function defaultBlock(type) {
            const base = {
                text: {type:'text', content:'<p>اكتب نصك هنا. يمكنك إدراج المتغيرات مثل {{first_name}}.</p>'},
                heading: {type:'heading', text:'عنوان رئيسي', level:'h2', align:'right'},
                image: {type:'image', src:'', alt:'', width:'600', url:''},
                button: {type:'button', text:'اضغط هنا', url:'https://example.com', bg:'#2563eb', color:'#ffffff'},
                divider: {type:'divider', color:'#e5e7eb', thickness:'1'},
                spacer: {type:'spacer', height:'24'},
                social: {type:'social', networks:['facebook','twitter','instagram','linkedin']},
                html: {type:'html', html:'<div style="background:#f3f4f6;padding:16px;border-radius:8px;">كود مخصص</div>'}
            };
            return JSON.parse(JSON.stringify(base[type] || base.text));
        }
        function renderPalette() {
            document.getElementById('bdPalette').innerHTML = BLOCK_TYPES.map(bt =>
                `<button class="p-btn xs" style="justify-content:flex-start;text-align:right;" onclick="addBlock('${bt.type}')">${bt.icon} ${bt.label}</button>`
            ).join('');
        }
        function addBlock(type) {
            bdBlocks.push(defaultBlock(type));
            bdSelected = bdBlocks.length - 1;
            renderAll();
        }
        function moveBlock(i, dir) {
            const j = i + dir;
            if (j < 0 || j >= bdBlocks.length) return;
            [bdBlocks[i], bdBlocks[j]] = [bdBlocks[j], bdBlocks[i]];
            bdSelected = j;
            renderAll();
        }
        function removeBlock(i) {
            bdBlocks.splice(i, 1);
            if (bdSelected >= bdBlocks.length) bdSelected = bdBlocks.length - 1;
            renderAll();
        }
        function selectBlock(i) {
            bdSelected = i;
            renderAll();
        }
        function renderAll() {
            renderBlocksList();
            renderBlocks();
            renderInspector();
        }
        function renderBlocksList() {
            const wrap = document.getElementById('bdBlocksBar');
            if (!bdBlocks.length) { wrap.innerHTML = '<p class="p-cell-muted" style="padding:8px;">أضف بلوكات من القائمة الجانبية.</p>'; return; }
            wrap.innerHTML = bdBlocks.map((b, i) => {
                const def = BLOCK_TYPES.find(t => t.type === b.type) || {label:b.type, icon:'🧩'};
                const sel = i === bdSelected ? 'background:#0b2436;border-color:#3b82f6;' : '';
                return `<div style="display:flex;align-items:center;gap:8px;background:#0b2436;border:1px solid ${i===bdSelected?'#3b82f6':'#1f2937'};border-radius:8px;padding:6px 10px;cursor:pointer;${sel}" onclick="selectBlock(${i})">
                    <span>${def.icon}</span>
                    <span style="flex:1;font-size:13px;">${def.label} #${i+1}</span>
                    <button class="p-btn xs" onclick="event.stopPropagation();moveBlock(${i},-1)">↑</button>
                    <button class="p-btn xs" onclick="event.stopPropagation();moveBlock(${i},1)">↓</button>
                    <button class="p-btn xs danger" onclick="event.stopPropagation();removeBlock(${i})">×</button>
                </div>`;
            }).join('');
        }
        async function renderBlocks() {
            const r = await emApiBuilder('/templates/blocks/render', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({blocks: JSON.stringify(bdBlocks)})});
            const html = r.success ? r.data.html : '<div style="color:#f87171;padding:12px;">تعذر عرض المعاينة</div>';
            document.getElementById('bdCanvas').innerHTML = `<iframe sandbox="" style="width:100%;min-height:520px;border:none;border-radius:8px;background:#fff;" srcdoc="${encodeURI(html)}"></iframe>`;
        }
        function renderInspector() {
            const el = document.getElementById('bdInspector');
            if (bdSelected < 0 || bdSelected >= bdBlocks.length) { el.innerHTML = '<p class="p-cell-muted">اختر بلوكًا من القائمة للتحرير.</p>'; return; }
            const b = bdBlocks[bdSelected];
            const defs = FIELD_DEFS[b.type] || [];
            el.innerHTML = defs.map(f => {
                let val = b[f.k];
                if (f.type === 'checkboxes') {
                    const list = Array.isArray(val) ? val : [];
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label>` + f.opts.map(o =>
                        `<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:4px;"><input type="checkbox" value="${o}" ${list.includes(o)?'checked':''} onchange="setField(${bdSelected},'${f.k}',Array.from(document.querySelectorAll('#bdInspector input[type=checkbox]:checked')).map(x=>x.value))"> ${NET_LABELS[o]||o}</label>`).join('');
                }
                if (f.type === 'select') {
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><select class="p-select" style="width:100%;margin-bottom:10px;" onchange="setField(${bdSelected},'${f.k}',this.value)">` + f.opts.map(o => `<option value="${o[0]}" ${String(val)===o[0]?'selected':''}>${o[1]}</option>`).join('') + '</select>';
                }
                if (f.type === 'color') {
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><input type="color" value="${val||'#000000'}" class="p-select" style="width:100%;height:36px;margin-bottom:10px;padding:2px;" onchange="setField(${bdSelected},'${f.k}',this.value)">`;
                }
                if (f.type === 'textarea') {
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><textarea class="p-select" style="width:100%;min-height:110px;margin-bottom:10px;font-family:monospace;font-size:12px;" oninput="setField(${bdSelected},'${f.k}',this.value)">${String(val||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</textarea>`;
                }
                return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><input type="${f.type}" value="${String(val||'').replace(/"/g,'&quot;')}" class="p-select" style="width:100%;margin-bottom:10px;" oninput="setField(${bdSelected},'${f.k}',this.value)">`;
            }).join('') + `<button class="p-btn xs danger" style="margin-top:6px;" onclick="removeBlock(${bdSelected})">حذف البلوك</button>`;
        }
        function setField(i, k, v) {
            if (i < 0 || i >= bdBlocks.length) return;
            bdBlocks[i][k] = v;
            renderBlocks();
        }
        async function previewBuilder() {
            const r = await emApiBuilder('/templates/blocks/render', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({blocks: JSON.stringify(bdBlocks)})});
            if (!r.success) return;
            const name = document.getElementById('bdName').value;
            const subject = document.getElementById('bdSubject').value;
            window.open('', '_blank').document.write('<html><head><title>' + name + '</title></head><body>' + r.data.html + '</body></html>');
        }
        async function saveBuilder() {
            const name = document.getElementById('bdName').value.trim();
            if (!name) { notify('اسم القالب مطلوب'); return; }
            const subject = document.getElementById('bdSubject').value;
            const r1 = await emApiBuilder('/templates/blocks/render', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({blocks: JSON.stringify(bdBlocks)})});
            if (!r1.success) { notify('تعذر توليد HTML'); return; }
            const body = { name: name, subject: subject, blocks: JSON.stringify(bdBlocks), html_body: r1.data.html };
            const path = SAVE_TARGET ? '/templates/' + SAVE_TARGET : '/templates';
            const opts = {method: SAVE_TARGET ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)};
            const r = await emApiBuilder(path, opts);
            if (r.success) { notify('تم حفظ القالب'); window.location.href = '/email-marketing/templates'; }
            else notify(r.error);
        }
        try { bdBlocks = JSON.parse(INITIAL_BLOCKS || '[]'); if (!Array.isArray(bdBlocks)) bdBlocks = []; } catch(e) { bdBlocks = []; }
        renderPalette();
        renderAll();
        JS;
        return str_replace(
            ['__INITIAL_BLOCKS__', '__SAVE_TARGET__'],
            [$blocks, $target],
            $js
        );
    }

    private function campaignsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadCampaigns() {
            const r = await emApi('/campaigns');
            if (!r.success) return;
            const cl = document.getElementById('campaignList');
            if (cl) cl.innerHTML = '<option value="0">اختر قائمة</option>' + (r.data.lists || []).map(l =>
                `<option value="${l.id}">${l.name} (${l.actual_count})</option>`).join('');
            const cs = document.getElementById('campaignSegment');
            if (cs) cs.innerHTML = '<option value="0">— بدون شريحة —</option>' + (r.data.segments || []).map(s =>
                `<option value="${s.id}">${s.name} (${s.subscriber_count || 0})</option>`).join('');

            const list = r.data.campaigns || [];
            document.getElementById('emCampaignsTable').innerHTML = list.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الاسم</th><th>الجمهور</th><th>الحالة</th><th>المرسل</th><th>الفتح</th><th>الكليك</th><th>موعد الإرسال</th><th></th></tr></thead>
                    <tbody>${list.map(c => `
                        <tr>
                            <td><b>${c.name}</b><div style="font-size:11px;color:#6b7280;">${c.subject}</div></td>
                            <td style="font-size:12px;">${c.segment_id ? ('🧩 ' + (c.segment_name || 'شريحة')) : (c.list_name || '—')}</td>
                            <td>${cStatusBadge(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${c.clicked_count}</td>
                            <td style="font-size:12px;color:#6b7280;">${c.scheduled_at || '—'}</td>
                            <td style="text-align:left;white-space:nowrap;">
                                ${canAct(c.status) ? `<button class="p-btn xs primary" onclick="sendNow(${c.id}, this)">${c.status === 'failed' ? '↻ إعادة المحاولة' : 'إرسال'}</button>` : ''}
                                ${c.status === 'scheduled' ? `<button class="p-btn xs danger" onclick="cancelC(${c.id})">إلغاء</button>` : ''}
                                ${['draft','scheduled','cancelled'].includes(c.status) ? `<button class="p-btn xs" onclick="editCampaign(${c.id})">تعديل</button>` : ''}
                                ${['draft','scheduled','cancelled'].includes(c.status) ? `<button class="p-btn xs" onclick="duplicateC(${c.id})">⧉ نسخ</button>` : ''}
                                ${['draft','cancelled'].includes(c.status) ? `<button class="p-btn xs danger" onclick="deleteC(${c.id})">حذف</button>` : ''}
                                <a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">تقرير</a>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد حملات — أنشئ حملتك الأولى الآن.</p>';
        }
        function cStatusBadge(s) {
            const map = {draft:'مسودة', scheduled:'مجدولة', sending:'قيد الإرسال', sent:'أُرسلت', cancelled:'ملغاة', failed:'فشلت'};
            const colors = {draft:'#f3f4f6', scheduled:'#fef3c7', sending:'#dbeafe', sent:'#dcfce7', cancelled:'#fee2e2', failed:'#fee2e2'};
            return `<span style="background:${colors[s]||'#f3f4f6'};padding:2px 10px;border-radius:20px;font-size:12px;">${map[s]||s}</span>`;
        }
        function canAct(s) { return ['draft','scheduled','failed'].includes(s); }
        function openCampaignModal() {
            document.getElementById('campaignModalTitle').textContent = 'حملة جديدة';
            ['campaignId','campaignName','campaignSubject','campaignFromName','campaignFromEmail','campaignHtml','campaignScheduledAt'].forEach(id => document.getElementById(id).value='');
            document.getElementById('campaignList').value = '0';
            document.getElementById('campaignSegment').value = '0';
            document.getElementById('campaignScheduleWrap').style.display = 'none';
            document.getElementById('campaignModal').classList.add('open');
        }
        async function editCampaign(id) {
            const r = await emApi('/campaigns/' + id);
            if (!r.success) return;
            const c = r.data;
            document.getElementById('campaignModalTitle').textContent = 'تعديل الحملة';
            document.getElementById('campaignId').value = c.id;
            document.getElementById('campaignName').value = c.name;
            document.getElementById('campaignSubject').value = c.subject;
            document.getElementById('campaignFromName').value = c.from_name || '';
            document.getElementById('campaignFromEmail').value = c.from_email || '';
            document.getElementById('campaignHtml').value = c.html_body || '';
            document.getElementById('campaignList').value = c.list_id || '0';
            const cs = document.getElementById('campaignSegment');
            if (cs) cs.value = c.segment_id ? String(c.segment_id) : '0';
            document.getElementById('campaignScheduleWrap').style.display = c.status === 'scheduled' ? 'block' : 'none';
            document.getElementById('campaignScheduledAt').value = c.scheduled_at ? c.scheduled_at.slice(0,16) : '';
            document.getElementById('campaignModal').classList.add('open');
        }
        function insertVarCampaign(v) {
            const ta = document.getElementById('campaignHtml');
            ta.value += v;
            ta.focus();
        }
        async function saveCampaign(schedule) {
            const id = document.getElementById('campaignId').value;
            const body = {
                name: document.getElementById('campaignName').value.trim(),
                subject: document.getElementById('campaignSubject').value.trim(),
                from_name: document.getElementById('campaignFromName').value.trim(),
                from_email: document.getElementById('campaignFromEmail').value.trim(),
                list_id: document.getElementById('campaignList').value,
                segment_id: document.getElementById('campaignSegment').value,
                html_body: document.getElementById('campaignHtml').value,
                scheduled_at: schedule ? document.getElementById('campaignScheduledAt').value : null
            };
            if (!body.name || !body.subject || !body.html_body) { notify('املأ اسم الحملة والموضوع والمحتوى'); return; }
            const path = id ? '/campaigns/' + id : '/campaigns';
            const opts = {method: id ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)};
            const r = await emApi(path, opts);
            if (!r.success) { notify(r.error); return; }
            const cid = r.data.id || id;
            if (schedule) {
                const s = await emApi('/campaigns/' + cid + '/schedule', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({scheduled_at: document.getElementById('campaignScheduledAt').value})});
                if (!s.success) { notify(s.error); return; }
            }
            document.getElementById('campaignModal').classList.remove('open');
            loadCampaigns();
        }
        async function sendNow(id, btn) {
            if (!confirm('بدء إرسال الحملة لكل المشتركين النشطين في الجمهور؟')) return;
            btnBusy(btn, true);
            try {
                const r = await emApi('/campaigns/' + id + '/send', {method:'POST'});
                notify(r.success ? r.message : (r.error || 'خطأ'));
                if (r.success) loadCampaigns();
            } finally { btnBusy(btn, false); }
        }
        async function cancelC(id) {
            if (!confirm('إلغاء هذه الحملة المجدولة؟')) return;
            const r = await emApi('/campaigns/' + id + '/cancel', {method:'POST'});
            if (r.success) loadCampaigns(); else notify(r.error);
        }
        async function duplicateC(id) {
            const r = await emApi('/campaigns/' + id + '/duplicate', {method:'POST'});
            if (r.success) loadCampaigns(); else notify(r.error);
        }
        async function deleteC(id) {
            if (!confirm('حذف هذه الحملة نهائيًا؟')) return;
            const r = await emApi('/campaigns/' + id, {method:'DELETE'});
            if (r.success) loadCampaigns(); else notify(r.error);
        }
        const params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1') { openCampaignModal(); history.replaceState({}, '', '/email-marketing/campaigns'); }
        loadCampaigns();
        JS;
    }

    private function campaignReportJs(int $campaignId): string
    {
        $cid = (int) $campaignId;
        $js = <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadReport() {
            const r = await emApi('/campaigns/__CID__/report');
            if (!r.success) return;
            const c = r.data;
            document.getElementById('crName').textContent = c.name + ' — ' + c.subject;
            document.getElementById('crCancelBtn').style.display = c.status === 'scheduled' ? 'inline-block' : 'none';
            document.getElementById('crRetryBtn').style.display = c.status === 'failed' ? 'inline-block' : 'none';
            const kpis = [
                ['📨', c.sent_count, 'أُرسل'],
                ['👁', c.opened_count, 'فُتح'],
                ['🖱', c.clicked_count, 'كليك'],
                ['🚫', c.unsubscribed_count, 'إلغاء'],
                ['↩️', c.bounced_count, 'ارتداد'],
                ['⏳', (c.total_recipients - c.sent_count), 'معلق']
            ].map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:24px;">${k[0]}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k[1]}</div>
                    <div style="font-size:12px;color:#6b7280;">${k[2]}</div>
                </div>`).join('');
            document.getElementById('crKpis').innerHTML = kpis;

            const rates = [
                ['معدل الفتح', c.open_rate + '%'],
                ['معدل الكليك', c.click_rate + '%'],
                ['كليك/فتح', c.click_to_open_rate + '%'],
                ['معدل الإلغاء', c.unsubscribe_rate + '%']
            ].map(rr => `
                <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;text-align:center;">
                    <div style="font-size:20px;font-weight:700;">${rr[1]}</div>
                    <div style="font-size:12px;color:#6b7280;">${rr[0]}</div>
                </div>`).join('');
            document.getElementById('crRates').innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">' + rates + '</div>';

            const rows = (c.recipients || []).map(rc => `
                <tr>
                    <td>${rc.email}</td>
                    <td>${rc.name || '—'}</td>
                    <td>${rBadge(rc.status)}</td>
                    <td>${rc.open_count || 0} / ${rc.click_count || 0}</td>
                    <td style="font-size:12px;color:#6b7280;">${rc.opened_at || '—'}</td>
                    <td style="font-size:12px;color:#6b7280;">${rc.clicked_at || '—'}</td>
                </tr>`).join('');
            document.getElementById('crRecipients').innerHTML = rows
                ? `<table class="p-table" style="width:100%;"><thead><tr><th>البريد</th><th>الاسم</th><th>الحالة</th><th>فتح/كليك</th><th>أول فتح</th><th>أول كليك</th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا يوجد مستلمون — شغّل إرسال الحملة أولًا.</p>';
        }
        function rBadge(s) {
            const map = {pending:'⏳', sent:'📤', opened:'👁', clicked:'🖱', unsubscribed:'🚫', bounced:'↩️', failed:'❌'};
            return map[s] || s;
        }
        async function refreshCampaignReport() { loadReport(); }
        async function cancelCampaign() {
            if (!confirm('إلغاء جدولة الحملة؟')) return;
            const r = await emApi('/campaigns/__CID__/cancel', {method:'POST'});
            if (r.success) loadReport(); else notify(r.error);
        }
        async function retryFailedCampaign() {
            const r = await emApi('/campaigns/__CID__/send', {method:'POST'});
            if (r.success) notify('أُعيد تعيين ' + r.data.total + ' مستلم — بدأ الإرسال عبر cron.'); else notify(r.error);
            if (r.success) loadReport();
        }
        loadReport();
        JS;
        return str_replace('__CID__', (string) $cid, $js);
    }

    private function reportsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadStats() {
            const r = await emApi('/stats');
            if (!r.success) return;
            const kpis = r.data.kpis.map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:26px;">${k.icon}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k.value}</div>
                    <div style="font-size:12px;color:#6b7280;">${k.label}</div>
                </div>`).join('');
            document.getElementById('emStatsKpis').innerHTML = kpis;

            const cs = r.data.campaigns || [];
            document.getElementById('emStatsCampaigns').innerHTML = cs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الحملة</th><th>الحالة</th><th>المرسل</th><th>الفتح</th><th>نسبة الفتح</th><th>الكليك</th><th>نسبة الكليك</th><th>إلغاء</th><th></th></tr></thead>
                    <tbody>${cs.map(c => {
                        const base = Math.max(1, c.sent_count);
                        const openRate = Math.round(c.opened_count / base * 1000) / 10;
                        const clickRate = Math.round(c.clicked_count / base * 1000) / 10;
                        return `
                        <tr>
                            <td><b>${c.name}</b></td>
                            <td>${c.status}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${openRate}%</td>
                            <td>${c.clicked_count}</td>
                            <td>${clickRate}%</td>
                            <td>${c.unsubscribed_count}</td>
                            <td><a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">تقرير</a></td>
                        </tr>`;}).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد حملات مُرسلة بعد.</p>';
        }
        async function loadTransactionalStats() {
            const r = await emApi('/transactional/stats');
            if (!r.success) return;
            const s = r.data.stats || {};
            const st = [
                ['المرسل', s.sent || 0],
                ['المفتوح', s.opened || 0],
                ['الكليك', s.clicked || 0],
                ['الفاشل', s.failed || 0],
            ].map(([l, v]) => `
                <div class="p-cell" style="text-align:center;padding:14px 10px;">
                    <div style="font-size:20px;font-weight:700;">${v}</div>
                    <div style="font-size:12px;color:#6b7280;">${l}</div>
                </div>`).join('');
            const recent = (s.recent || []).slice(0, 5);
            const rows = recent.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>القالب</th><th>البريد</th><th>الحالة</th><th>الفتح</th><th>الكليك</th><th>التاريخ</th></tr></thead>
                    <tbody>${recent.map(l => `
                        <tr>
                            <td>${l.template_name || '-'}</td>
                            <td>${l.to_email}</td>
                            <td>${l.status}</td>
                            <td>${l.opened_at ? 'نعم' : '-'}</td>
                            <td>${l.clicked_at ? 'نعم' : '-'}</td>
                            <td>${l.created_at}</td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:12px;">لا توجد رسائل معاملات بعد.</p>';
            document.getElementById('emStatsTransactional').innerHTML =
                `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">${st}</div>${rows}`;
        }
        async function loadAbTestsStats() {
            const r = await emApi('/ab-tests');
            if (!r.success) return;
            const ts = r.data.ab_tests || [];
            const reports = await Promise.all(ts.map(t => emApi('/ab-tests/' + t.id + '/report')));
            const rows = reports.filter(x => x.success && x.data.report).map(x => x.data.report);
            document.getElementById('emStatsAbTests').innerHTML = rows.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الاختبار</th><th>الحالة</th><th>المقياس</th><th>أ (فتح/كليك)</th><th>ب (فتح/كليك)</th><th>الفائز</th><th></th></tr></thead>
                    <tbody>${rows.map(t => {
                        const a = t.variant_a || {}, b = t.variant_b || {};
                        const aR = Math.max(1, a.sent_count);
                        const bR = Math.max(1, b.sent_count);
                        return `
                        <tr>
                            <td><b>${t.name}</b></td>
                            <td>${t.status}</td>
                            <td>${t.metric_label || '-'}</td>
                            <td>${Math.round(a.open_rate * 100) / 100}% / ${Math.round(a.click_rate * 100) / 100}%</td>
                            <td>${Math.round(b.open_rate * 100) / 100}% / ${Math.round(b.click_rate * 100) / 100}%</td>
                            <td>${t.declared_winner || t.winner || '-'}</td>
                            <td><a class="p-btn xs" href="/email-marketing/ab-tests/${t.id}">تقرير</a></td>
                        </tr>`;}).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:12px;">لا توجد اختبارات أ/ب بعد.</p>';
        }
        loadStats();
        loadTransactionalStats();
        loadAbTestsStats();
        JS;
    }

    private function contactsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            // تصحيح: الـ API بيرجّع {success, data:{...}} والصفحة دي بتقرا الحقول مباشرة (r.total / r.tags / r.data[])
            // فكانت بتقع بـ "map is not a function" والأرقام كلها صفر. بنفك الغلاف هنا بس.
            const j = await res.json();
            if (j && j.data && typeof j.data === 'object' && !Array.isArray(j.data)) {
                return Object.assign({ success: j.success, message: j.message, error: j.error, code: j.code }, j.data);
            }
            return j;
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            const headers = { 'Content-Type': 'application/json' };
            return emApi(path, { method: 'POST', headers, body: JSON.stringify(body) });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        let emContactPage = 1;
        let emCFields = [];
        let emCTags = [];
        let emCStatus = 'subscribed';
        let emCListId = 0;
        let emCQ = '';

        function switchContactTab(tab) {
            document.querySelectorAll('#emContactSubTabs .p-btn').forEach(b => {
                b.classList.toggle('primary', b.dataset.ctab === tab);
            });
            document.getElementById('emContactOverview').style.display = tab === 'overview' ? '' : 'none';
            document.getElementById('emContactFields').style.display = tab === 'fields' ? '' : 'none';
            document.getElementById('emContactTags').style.display = tab === 'tags' ? '' : 'none';
            document.getElementById('emContactSegments').style.display = tab === 'segments' ? '' : 'none';
            document.getElementById('emContactSuppressions').style.display = tab === 'suppressions' ? '' : 'none';
            if (tab === 'overview') { loadContactOverview(); loadContactSubscribers(); }
            if (tab === 'fields') loadFields();
            if (tab === 'tags') loadTags();
            if (tab === 'segments') loadSegments();
            if (tab === 'suppressions') loadSuppressions();
        }

        async function loadContactOverview() {
            const [subs, lists, tags, segs, supps, custom] = await Promise.all([
                emApi('/subscribers?per_page=1'),
                emApi('/lists'),
                emApi('/contacts/tags'),
                emApi('/contacts/segments'),
                emApi('/contacts/suppressions?per_page=1'),
                emApi('/contacts/custom-fields'),
            ]);
            emCFields = custom.fields || [];
            emCTags = tags.tags || [];
            let html = '';
            const cards = [
                ['👥', subs.total ?? 0, 'إجمالي جهات الاتصال'],
                ['✅', subs.data.filter(s => s.status === 'subscribed').length, 'نشطون الآن'],
                ['🚫', supps.total ?? 0, 'في قائمة الممنوعين'],
                ['🧩', (segs.segments || []).length, 'شريحة'],
                ['🏷️', emCFields.length, 'حقل مخصص'],
                ['📛', emCTags.length, 'وسم'],
            ];
            html = cards.map(([ic, val, lbl]) => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:24px;">${ic}</div>
                    <div style="font-size:20px;font-weight:700;margin-top:4px;">${val}</div>
                    <div style="font-size:12px;color:#6b7280;">${esc(lbl)}</div>
                </div>`).join('');
            document.getElementById('emContactStats').innerHTML = html;

            const listSel = document.getElementById('emCListFilter');
            listSel.innerHTML = '<option value="0">كل القوائم</option>' + (lists.lists || []).map(l =>
                `<option value="${l.id}">${esc(l.name)} (${l.actual_count})</option>`).join('');
            listSel.value = emCListId;
        }

        async function loadContactSubscribers() {
            emCListId = parseInt(document.getElementById('emCListFilter').value || '0');
            emCStatus = document.getElementById('emCStatusFilter').value || '';
            emCQ = document.getElementById('emCSearch').value || '';
            const q = `per_page=20&page=${emContactPage}&list_id=${emCListId}&status=${encodeURIComponent(emCStatus)}&q=${encodeURIComponent(emCQ)}`;
            const r = await emApi('/subscribers?' + q);
            const rows = (r.data || []).map(s => `
                <tr>
                    <td><b>${esc(s.email)}</b></td>
                    <td>${esc(s.name || '-')}</td>
                    <td>${s.status === 'subscribed' ? '✅' : s.status === 'unsubscribed' ? '🚫' : '⚠️'} ${esc(s.status)}</td>
                    <td>${s.list_count || 0}</td>
                    <td>${s.engagement_score || 0}</td>
                    <td>${esc((s.created_at || '').slice(0, 10))}</td>
                    <td><a class="p-btn xs" href="/email-marketing/contacts/${s.id}">عرض</a></td>
                </tr>`).join('');
            document.getElementById('emContactsTable').innerHTML = r.data.length
                ? `<table class="p-table"><thead><tr><th>البريد</th><th>الاسم</th><th>الحالة</th><th>القوائم</th><th>التفاعل</th><th>تاريخ الإضافة</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا توجد جهات اتصال.</p>';
            document.getElementById('emCSubCountLabel').textContent = `(${r.total})`;
            const pages = Math.max(1, Math.ceil((r.total || 0) / 20));
            document.getElementById('emContactsPager').innerHTML =
                `<button class="p-btn xs" onclick="emContactPage=Math.max(1,emContactPage-1);loadContactSubscribers()">‹</button>
                 <span style="margin:0 10px;">${emContactPage} / ${pages}</span>
                 <button class="p-btn xs" onclick="emContactPage=Math.min(${pages},emContactPage+1);loadContactSubscribers()">›</button>`;
        }

        async function exportContacts() {
            const list = parseInt(document.getElementById('emCListFilter').value || '0');
            const q = `format=csv&list_id=${list}&status=${encodeURIComponent(emCStatus)}`;
            const r = await emApi('/subscribers/export?' + q);
            if (!r.data) return;
            const blob = new Blob([r.data], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'contacts.csv';
            a.click();
        }

        // ----- Custom fields -----
        async function loadFields() {
            const r = await emApi('/contacts/custom-fields');
            const rows = (r.fields || []).map(f => `
                <tr>
                    <td><code>{{custom.${esc(f.name)}}}</code></td>
                    <td>${esc(f.label)}</td>
                    <td>${esc(f.field_type)}</td>
                    <td>${f.is_system ? 'نظامي' : f.is_required ? 'مطلوب' : '-'}</td>
                    <td style="text-align:left;">
                        ${f.is_system ? '' : `<button class="p-btn xs" onclick="openFieldModal(${f.id})">تعديل</button>
                        <button class="p-btn xs" onclick="deleteField(${f.id})">حذف</button>`}
                    </td>
                </tr>`).join('');
            document.getElementById('emFieldsTable').innerHTML = r.fields.length
                ? `<table class="p-table"><thead><tr><th>المتغير</th><th>التسمية</th><th>النوع</th><th>الخاصية</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا توجد حقول مخصصة.</p>';
        }
        function openFieldModal(id) {
            document.getElementById('fieldId').value = id || '';
            if (id) {
                const f = emCFields.find(x => x.id === id);
                if (f) {
                    document.getElementById('fieldName').value = f.name;
                    document.getElementById('fieldLabel').value = f.label;
                    document.getElementById('fieldType').value = f.field_type;
                    document.getElementById('fieldOptions').value = (f.options || []).join(', ');
                    toggleFieldOptions();
                }
            } else {
                ['fieldName', 'fieldLabel', 'fieldOptions'].forEach(i => document.getElementById(i).value = '');
                document.getElementById('fieldType').value = 'text';
                toggleFieldOptions();
            }
            document.getElementById('fieldModal').classList.add('open');
        }
        function toggleFieldOptions() {
            const t = document.getElementById('fieldType').value;
            document.getElementById('fieldOptionsWrap').style.display = ['select', 'multi_select'].includes(t) ? '' : 'none';
        }
        async function saveField() {
            const id = document.getElementById('fieldId').value;
            const body = {
                name: document.getElementById('fieldName').value,
                label: document.getElementById('fieldLabel').value,
                field_type: document.getElementById('fieldType').value,
                options: document.getElementById('fieldOptions').value,
                is_required: document.getElementById('fieldRequired').checked,
            };
            const r = id ? await emApi('/contacts/custom-fields/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                          : await emPost('/contacts/custom-fields', body);
            if (r.success) { document.getElementById('fieldModal').classList.remove('open'); loadFields(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function deleteField(id) {
            if (!confirm('حذف الحقل؟')) return;
            const r = await emApi('/contacts/custom-fields/' + id, { method: 'DELETE' });
            if (r.success) loadFields();
        }

        // ----- Tags -----
        async function loadTags() {
            const r = await emApi('/contacts/tags');
            const rows = (r.tags || []).map(t => `
                <tr>
                    <td><span class="em-tag-pill" style="background:${t.color || '#0b2436'};">${esc(t.name)}</span></td>
                    <td>${t.subscriber_count || 0} مشترك</td>
                    <td style="text-align:left;">
                        <button class="p-btn xs" onclick="openTagModal(${t.id})">تعديل</button>
                        <button class="p-btn xs" onclick="deleteTag(${t.id})">حذف</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emTagsTable').innerHTML = r.tags.length
                ? `<table class="p-table"><thead><tr><th>الوسم</th><th>المشتركون</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا توجد وسوم.</p>';
        }
        function openTagModal(id) {
            document.getElementById('tagId').value = id || '';
            if (id) {
                const t = emCTags.find(x => x.id === id);
                if (t) {
                    document.getElementById('tagName').value = t.name;
                    document.getElementById('tagColor').value = t.color || '#0077be';
                }
            } else {
                document.getElementById('tagName').value = '';
                document.getElementById('tagColor').value = '#0077be';
            }
            document.getElementById('tagModal').classList.add('open');
        }
        async function saveTag() {
            const id = document.getElementById('tagId').value;
            const body = { name: document.getElementById('tagName').value, color: document.getElementById('tagColor').value };
            const r = id ? await emApi('/contacts/tags/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                          : await emPost('/contacts/tags', body);
            if (r.success) { document.getElementById('tagModal').classList.remove('open'); loadTags(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function deleteTag(id) {
            if (!confirm('حذف الوسم؟')) return;
            const r = await emApi('/contacts/tags/' + id, { method: 'DELETE' });
            if (r.success) loadTags();
        }

        // ----- Segments -----
        async function loadSegments() {
            const r = await emApi('/contacts/segments');
            const rows = (r.segments || []).map(s => `
                <tr>
                    <td><b>${esc(s.name)}</b></td>
                    <td>${esc(s.description || '-')}</td>
                    <td><span class="em-seg-badge">${s.subscriber_count} جهة</span></td>
                    <td>${(s.conditions || []).length} شرط</td>
                    <td style="text-align:left;">
                        <button class="p-btn xs" onclick="openSegmentModal(${s.id})">تعديل</button>
                        <button class="p-btn xs" onclick="deleteSegment(${s.id})">حذف</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emSegmentsTable').innerHTML = r.segments.length
                ? `<table class="p-table"><thead><tr><th>الاسم</th><th>الوصف</th><th>النتيجة</th><th>الشروط</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا توجد شرائح. أنشئ شريحة لاستهداف شرائح محددة من جمهورك.</p>';
        }
        function openSegmentModal(id) {
            document.getElementById('segmentId').value = id || '';
            const seg = id ? (emSegs || []).find(x => x.id === id) : null;
            document.getElementById('segmentName').value = seg ? seg.name : '';
            document.getElementById('segmentDesc').value = seg ? (seg.description || '') : '';
            document.getElementById('segmentMatchAll').value = seg ? (seg.match_all ? '1' : '0') : '1';
            document.getElementById('segmentConditions').innerHTML = '';
            const conds = seg && seg.conditions ? seg.conditions : [{ field: 'status', operator: 'is', value: 'subscribed' }];
            conds.forEach(c => addSegmentCondition(c));
            document.getElementById('segmentModal').classList.add('open');
            updateSegmentLive();
        }
        function segmentFieldOptions() {
            let opts = '<option value="status">الحالة</option><option value="email">البريد</option><option value="name">الاسم</option>';
            opts += '<option value="created_at">تاريخ الإضافة</option><option value="engagement_score">درجة التفاعل</option>';
            opts += '<option value="language">اللغة</option><option value="has_tag">يملك وسم</option><option value="not_has_tag">لا يملك وسم</option>';
            opts += '<option value="in_list">في قائمة</option><option value="not_in_list">ليس في قائمة</option>';
            opts += '<option value="opened">فتح أي بريد</option><option value="not_opened">لم يفتح</option><option value="clicked">نقر أي بريد</option><option value="not_clicked">لم ينقر</option>';
            (emCFields || []).forEach(f => opts += `<option value="custom:${esc(f.name)}">حقل: ${esc(f.label)}</option>`);
            return opts;
        }
        function addSegmentCondition(cond) {
            cond = cond || { field: 'status', operator: 'is', value: '' };
            const div = document.createElement('div');
            div.className = 'em-condition-row';
            div.innerHTML = `
                <select class="p-select" onchange="updateSegmentLive()">${segmentFieldOptions().replace(`value="${esc(cond.field)}"`, `value="${esc(cond.field)}" selected`)}</select>
                <select class="p-select" onchange="updateSegmentLive()">
                    <option value="is" ${cond.operator==='is'?'selected':''}>يساوي</option>
                    <option value="is_not" ${cond.operator==='is_not'?'selected':''}>لا يساوي</option>
                    <option value="contains" ${cond.operator==='contains'?'selected':''}>يحتوي</option>
                    <option value="starts_with" ${cond.operator==='starts_with'?'selected':''}>يبدأ بـ</option>
                    <option value="ends_with" ${cond.operator==='ends_with'?'selected':''}>ينتهي بـ</option>
                    <option value="greater_than" ${cond.operator==='greater_than'?'selected':''}>></option>
                    <option value="less_than" ${cond.operator==='less_than'?'selected':''}><</option>
                    <option value="is_empty" ${cond.operator==='is_empty'?'selected':''}>فارغ</option>
                    <option value="is_not_empty" ${cond.operator==='is_not_empty'?'selected':''}>غير فارغ</option>
                </select>
                <input type="text" class="p-select" value="${esc(cond.value)}" placeholder="القيمة" onkeyup="updateSegmentLive()">
                <button class="p-btn xs" onclick="this.parentNode.remove();updateSegmentLive()">×</button>`;
            document.getElementById('segmentConditions').appendChild(div);
        }
        async function updateSegmentLive() {
            const conditions = [];
            document.querySelectorAll('#segmentConditions .em-condition-row').forEach(row => {
                conditions.push({ field: row.children[0].value, operator: row.children[1].value, value: row.children[2].value });
            });
            document.getElementById('segmentLiveCount').textContent = `جارٍ حساب النتيجة...`;
            if (!document.getElementById('segmentId').value) return;
            const r = await emApi(`/contacts/segments/${document.getElementById('segmentId').value}/preview`);
            if (r.success) document.getElementById('segmentLiveCount').textContent = `النتيجة الحالية: ${r.count} جهة اتصال`;
        }
        async function saveSegment() {
            const id = document.getElementById('segmentId').value;
            const conditions = [];
            document.querySelectorAll('#segmentConditions .em-condition-row').forEach(row => {
                conditions.push({ field: row.children[0].value, operator: row.children[1].value, value: row.children[2].value });
            });
            const body = {
                name: document.getElementById('segmentName').value,
                description: document.getElementById('segmentDesc').value,
                match_all: document.getElementById('segmentMatchAll').value === '1',
                conditions,
            };
            const r = id ? await emApi('/contacts/segments/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                          : await emPost('/contacts/segments', body);
            if (r.success) { document.getElementById('segmentModal').classList.remove('open'); loadSegments(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function deleteSegment(id) {
            if (!confirm('حذف الشريحة؟')) return;
            const r = await emApi('/contacts/segments/' + id, { method: 'DELETE' });
            if (r.success) loadSegments();
        }

        // ----- Suppressions -----
        function openSuppressionModal() {
            document.getElementById('supEmail').value = '';
            document.getElementById('supType').value = 'manual';
            document.getElementById('supReason').value = '';
            document.getElementById('suppressionModal').classList.add('open');
            document.getElementById('supEmail').focus();
        }
        let suppressionLoadVersion = 0;
        async function loadSuppressions() {
            const version = ++suppressionLoadVersion;
            const box = document.getElementById('emSuppressionsTable');
            box.innerHTML = '<div class="p-loading-row">جارِ التحميل...</div>';
            try {
                const r = await emApi('/contacts/suppressions');
                if (version !== suppressionLoadVersion) return;
                if (!r || !r.success || !Array.isArray(r.data)) throw new Error('load');
                const data = r.data;
                const rows = data.map(s => `
                    <tr>
                        <td><b>${esc(s.email)}</b></td>
                        <td>${s.type === 'bounce' ? '⚠️ ارتداد' : s.type === 'complaint' ? '🚫 شكوى' : s.type === 'spam' ? '📧 سبام' : '✋ يدوي'}</td>
                        <td>${esc(s.reason || '-')}</td>
                        <td>${esc(String(s.suppressed_at || '').slice(0, 10))}</td>
                        <td style="text-align:left;"><button class="p-btn xs" onclick="deleteSuppression(${Number(s.id)})">إزالة</button></td>
                    </tr>`).join('');
                box.innerHTML = data.length
                    ? `<table class="p-table"><thead><tr><th>البريد</th><th>السبب</th><th>الملاحظات</th><th>التاريخ</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                    : '<p class="p-cell-muted" style="padding:16px;">قائمة الممنوعين فارغة.</p>';
            } catch (error) {
                if (version !== suppressionLoadVersion) return;
                box.innerHTML = '<div class="p-cell-muted" role="alert">تعذر تحميل قائمة الممنوعين. <button type="button" class="p-btn outline xs" onclick="loadSuppressions()">إعادة المحاولة</button></div>';
            }
        }
        async function saveSuppression() {
            const r = await emPost('/contacts/suppressions', {
                email: document.getElementById('supEmail').value,
                type: document.getElementById('supType').value,
                reason: document.getElementById('supReason').value,
            });
            if (r.success) { document.getElementById('suppressionModal').classList.remove('open'); loadSuppressions(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function deleteSuppression(id) {
            if (!confirm('إزالة العنوان من قائمة الممنوعين؟')) return;
            const r = await emApi('/contacts/suppressions/' + id, { method: 'DELETE' });
            if (r.success) loadSuppressions();
        }

        emContactPage = 1;
        loadContactOverview();
        loadContactSubscribers();
        JS;
    }

    private function subscriberDetailJs(int $subscriberId): string
    {
        $js = <<<'JS'
        const SUB_ID = __SUB_ID__;
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        let subDetail = null;
        let subAllTags = [];

        async function loadSubscriberDetail() {
            const r = await emApi('/subscribers/' + SUB_ID);
            if (!r.success) { document.getElementById('subDetailBody').innerHTML = '<p class="p-cell-muted" style="padding:16px;">جهة الاتصال غير موجودة.</p>'; return; }
            subDetail = r.subscriber;
            const s = subDetail;
            document.getElementById('subDetailName').textContent = '👤 ' + (s.name || s.email);
            const tags = (s.tags || []).map(t => `<span class="em-tag-pill" style="background:${t.color || '#0b2436'};">${esc(t.name)}</span>`).join('') || '-';
            const lists = (s.lists || []).map(l => `<span class="em-seg-badge info">${esc(l.name)}</span>`).join('') || '-';
            const statusBadge = s.status === 'subscribed' ? '✅ مشترك' : s.status === 'unsubscribed' ? '🚫 ملغي' : '⚠️ مرتد';
            const cvs = Object.entries(s.custom_values || {}).map(([fid, v]) =>
                `<tr><td><code>${esc(v.name)}</code></td><td>${esc(v.value)}</td></tr>`).join('');

            document.getElementById('subDetailBody').innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">البريد</div><b>${esc(s.email)}</b></div>
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">الحالة</div>${statusBadge}</div>
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">درجة التفاعل</div><b>${s.engagement_score || 0}/100</b></div>
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">أُضيف</div>${esc((s.created_at || '').slice(0, 10))}</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="p-card">
                    <div class="p-card-head"><h3>📛 الوسوم</h3><button class="p-btn xs" onclick="toggleTagPicker()">+ إضافة</button></div>
                    <div style="margin-bottom:8px;">${tags}</div>
                    <div id="tagPicker" style="display:none;margin-top:8px;">
                        <select id="tagPickSel" class="p-select" style="width:100%;margin-bottom:6px;"></select>
                        <button class="p-btn primary xs" onclick="addTagToSub()">إضافة</button>
                    </div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>🗂️ القوائم</h3></div>
                    <div>${lists}</div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>🏷️ الحقول المخصصة</h3></div>
                    <table class="p-table"><tbody>${cvs || '<tr><td class="p-cell-muted">لا توجد قيم.</td></tr>'}</tbody></table>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>📊 النشاط</h3></div>
                    ${(s.activity || []).map(a => `
                        <div style="padding:8px 0;border-bottom:1px solid #1f2937;">
                            <b>${esc(a.campaign_name)}</b><br>
                            <span style="font-size:12px;color:#6b7280;">${esc(a.status)} · فتح: ${a.open_count} · نقر: ${a.click_count} · ${esc((a.opened_at || a.created_at || '').slice(0, 16))}</span>
                        </div>`).join('') || '<p class="p-cell-muted">لا نشاط بعد.</p>'}
                </div>
            </div>`;
            loadSubTagsPicker();
        }
        async function loadSubTagsPicker() {
            const r = await emApi('/contacts/tags');
            subAllTags = r.tags || [];
            const have = new Set((subDetail.tags || []).map(t => t.id));
            document.getElementById('tagPickSel').innerHTML = subAllTags.filter(t => !have.has(t.id))
                .map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        }
        function toggleTagPicker() {
            const el = document.getElementById('tagPicker');
            el.style.display = el.style.display === 'none' ? '' : 'none';
            if (el.style.display !== 'none') loadSubTagsPicker();
        }
        async function addTagToSub() {
            const tagId = document.getElementById('tagPickSel').value;
            if (!tagId) return;
            const r = await emApi('/contacts/subscribers/' + SUB_ID + '/tags', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tag_id: tagId })
            });
            if (r.success) loadSubscriberDetail();
        }
        loadSubscriberDetail();
        JS;
        return str_replace('__SUB_ID__', (string) $subscriberId, $js);
    }

    private function automationsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            const headers = { 'Content-Type': 'application/json' };
            return emApi(path, { method: 'POST', headers, body: JSON.stringify(body) });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        let emAutomations = [];
        let emAutoMeta = { triggers: {}, step_types: {} };
        let emAutoLists = [];
        let emAutoTemplates = [];
        let emAutoCampaigns = [];
        let emAutoEditingId = null;
        let emAutoStepCount = 0;

        const EM_AUTO_TRIGGER_OPTIONS = {
            subscribed: { label: 'قائمة (اختياري)', type: 'list' },
            tag_added: { label: 'اسم الوسم (اختياري)', type: 'text' },
            campaign_opened: { label: 'الحملة (اختياري)', type: 'campaign' },
            campaign_clicked: { label: 'الحملة (اختياري)', type: 'campaign' },
            date_after: { label: 'عدد الأيام بعد الاشتراك', type: 'days' },
        };

        async function loadAutomations() {
            const r = await emApi('/automations');
            if (!r.success) return;
            emAutomations = r.data.automations || [];
            emAutoMeta = { triggers: r.data.triggers || {}, step_types: r.data.step_types || {} };
            const [listsR, tmplR, campR] = await Promise.all([emApi('/lists'), emApi('/templates'), emApi('/campaigns')]);
            emAutoLists = listsR.lists || [];
            emAutoTemplates = (tmplR.templates || []).filter(t => t.status === 'ready' || !t.status);
            emAutoCampaigns = campR.campaigns || [];

            const rows = emAutomations.map(a => {
                const trigLabel = emAutoMeta.triggers[a.trigger_type] || a.trigger_type;
                const steps = (a.steps_count ?? 0);
                const active = a.active_entries ?? 0;
                return `
                <tr>
                    <td><b>${esc(a.name)}</b>${a.description ? `<div class="p-cell-muted" style="font-size:12px;">${esc(a.description)}</div>` : ''}</td>
                    <td>${esc(trigLabel)}</td>
                    <td>${steps} خطوة</td>
                    <td>${active} نشط</td>
                    <td>${a.status === 'active' ? '<span style="color:#16a34a;font-weight:700;">مفعّل</span>' : '<span style="color:#9ca3af;font-weight:700;">متوقف</span>'}</td>
                    <td style="text-align:left;white-space:nowrap;">
                        <button class="p-btn xs" onclick="toggleAutomationStatus(${a.id}, ${a.status === 'active' ? 0 : 1})">${a.status === 'active' ? 'إيقاف' : 'تشغيل'}</button>
                        <button class="p-btn xs" onclick="openAutomationModal(${a.id})">تعديل</button>
                        <button class="p-btn xs" onclick="deleteAutomation(${a.id})">حذف</button>
                    </td>
                </tr>`;
            }).join('');

            document.getElementById('emAutomationsList').innerHTML = emAutomations.length
                ? `<table class="p-table"><thead><tr><th>سير العمل</th><th>المشغل</th><th>الخطوات</th><th>نشط</th><th>الحالة</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا توجد سير عمل بعد. أنشئ أول سير عمل تلقائي.</p>';

            const triggerSel = document.getElementById('emAutoTrigger');
            if (!triggerSel.options.length) {
                triggerSel.innerHTML = Object.entries(emAutoMeta.triggers).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
            }
            const entrySel = document.getElementById('emAutoEntryAudience');
            if (!entrySel.options.length) {
                const opts = emAutoLists.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
                entrySel.innerHTML = opts;
                document.getElementById('emAutoExitAudience').innerHTML = opts;
            }
        }

        function automationTriggerValueHtml(type) {
            if (type === 'list') {
                const opts = emAutoLists.map(l => `<option value="">كل القوائم</option><option value="${l.id}">${esc(l.name)}</option>`).join('');
                return `<select class="p-input" id="emAutoTriggerValue" style="width:100%;">${opts}</select>`;
            }
            if (type === 'campaign') {
                const opts = emAutoCampaigns.map(c => `<option value="">كل الحملات</option><option value="${c.id}">${esc(c.name)}</option>`).join('');
                return `<select class="p-input" id="emAutoTriggerValue" style="width:100%;">${opts}</select>`;
            }
            if (type === 'days') {
                return `<input class="p-input" id="emAutoTriggerValue" type="number" min="1" value="7" style="width:100%;"/>`;
            }
            return `<input class="p-input" id="emAutoTriggerValue" placeholder="مثال: vip" style="width:100%;"/>`;
        }

        function onAutomationTriggerChange() {
            const type = EM_AUTO_TRIGGER_OPTIONS[document.getElementById('emAutoTrigger').value] || { label: '', type: 'text' };
            document.getElementById('emAutoTriggerValueLabel').textContent = type.label;
            const wrap = document.getElementById('emAutoTriggerValueWrap');
            wrap.innerHTML = '<label class="p-label" id="emAutoTriggerValueLabel">' + esc(type.label) + '</label>' + automationTriggerValueHtml(type.type);
        }

        function openAutomationModal(id) {
            emAutoEditingId = id || null;
            document.getElementById('emAutoModalTitle').textContent = id ? 'تعديل سير العمل' : 'سير عمل جديد';
            document.getElementById('emAutoName').value = '';
            document.getElementById('emAutoDesc').value = '';
            document.getElementById('emAutoEntryAudience').value = '';
            document.getElementById('emAutoExitAudience').value = '';
            emAutoStepCount = 0;
            document.getElementById('emAutoSteps').innerHTML = '';
            document.getElementById('emAutoTrigger').value = 'subscribed';
            onAutomationTriggerChange();
            document.getElementById('emAutomationModal').style.display = 'flex';

            if (id) {
                emApi('/automations/' + id).then(r => {
                    if (!r.success) return;
                    const a = r.data;
                    document.getElementById('emAutoName').value = a.name || '';
                    document.getElementById('emAutoDesc').value = a.description || '';
                    document.getElementById('emAutoTrigger').value = a.trigger_type || 'subscribed';
                    onAutomationTriggerChange();
                    const tv = a.trigger_value || {};
                    const tvEl = document.getElementById('emAutoTriggerValue');
                    if (tvEl) {
                        if (a.trigger_type === 'subscribed' || a.trigger_type === 'campaign_opened' || a.trigger_type === 'campaign_clicked') {
                            tvEl.value = String(tv.list_id || tv.campaign_id || '');
                        } else if (a.trigger_type === 'tag_added') {
                            tvEl.value = tv.tag || '';
                        } else if (a.trigger_type === 'date_after') {
                            tvEl.value = tv.days || 7;
                        }
                    }
                    if ((a.entry_audience_ids || []).length) document.getElementById('emAutoEntryAudience').value = a.entry_audience_ids;
                    if ((a.exit_audience_ids || []).length) document.getElementById('emAutoExitAudience').value = a.exit_audience_ids;
                    (a.steps || []).forEach(s => {
                        const value = s.step_value || {};
                        addAutomationStep(s.step_type, {
                            days: value.days, hours: value.hours, minutes: value.minutes,
                            subject: value.subject, html: value.html, template_id: value.template_id,
                            tag: value.tag, list_id: value.list_id,
                        });
                    });
                    if (!(a.steps || []).length) addAutomationStep();
                });
            } else {
                addAutomationStep();
            }
        }

        function closeAutomationModal() {
            document.getElementById('emAutomationModal').style.display = 'none';
        }

        function addAutomationStep(stepType, value) {
            stepType = stepType || 'wait';
            value = value || {};
            const idx = emAutoStepCount++;
            const options = Object.entries(emAutoMeta.step_types).map(([k, v]) => {
                const sel = k === stepType ? ' selected' : '';
                return `<option value="${k}"${sel}>${esc(v)}</option>`;
            }).join('');
            const row = document.createElement('div');
            row.className = 'em-auto-step-row';
            row.style.cssText = 'border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;background:#f9fafb;';
            row.dataset.idx = idx;
            row.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="font-weight:700;color:#6b7280;">#${idx + 1}</span>
                    <select class="p-input" style="flex:1;" onchange="renderAutomationStepBody(this, ${idx})">
                        ${options}
                    </select>
                    <button class="p-btn xs" onclick="moveAutomationStep(${idx}, -1)">↑</button>
                    <button class="p-btn xs" onclick="moveAutomationStep(${idx}, 1)">↓</button>
                    <button class="p-btn xs" onclick="document.querySelectorAll('[data-idx]').forEach(r=>{ if(parseInt(r.dataset.idx)===${idx}) r.remove(); })">✕</button>
                </div>
                <div class="em-auto-step-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;"></div>`;
            document.getElementById('emAutoSteps').appendChild(row);
            const sel = row.querySelector('select');
            renderAutomationStepBody(sel, idx, value);
        }

        function moveAutomationStep(idx, dir) {
            const rows = [...document.querySelectorAll('.em-auto-step-row')];
            const i = rows.findIndex(r => parseInt(r.dataset.idx) === idx);
            const j = i + dir;
            if (i < 0 || j < 0 || j >= rows.length) return;
            const parent = rows[i].parentNode;
            parent.insertBefore(rows[i], dir < 0 ? rows[j] : rows[j].nextSibling);
        }

        function renderAutomationStepBody(sel, idx, value) {
            value = value || {};
            const body = sel.closest ? sel.closest('.em-auto-step-row').querySelector('.em-auto-step-body') : null;
            if (!body) return;
            const type = sel.value;
            if (type === 'wait') {
                body.innerHTML = `
                    <input class="p-input" placeholder="أيام" type="number" min="0" data-step-k="days" value="${value.days || 0}"/>
                    <input class="p-input" placeholder="ساعات" type="number" min="0" data-step-k="hours" value="${value.hours || 0}"/>
                    <input class="p-input" placeholder="دقائق" type="number" min="0" data-step-k="minutes" value="${value.minutes || 0}"/>`;
            } else if (type === 'send_email') {
                body.innerHTML = `
                    <input class="p-input" placeholder="الموضوع" data-step-k="subject" value="${esc(value.subject || '')}" style="grid-column:1/-1;"/>
                    <input class="p-input" placeholder="نص الإيميل (HTML أو نص)" data-step-k="html" value="${esc(value.html || '')}" style="grid-column:1/-1;"/>
                    <select class="p-input" data-step-k="template_id"><option value="">بدون قالب</option>
                        ${emAutoTemplates.map(t => `<option value="${t.id}"${String(value.template_id) === String(t.id) ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}
                    </select>`;
            } else if (type === 'add_tag' || type === 'remove_tag') {
                body.innerHTML = `<input class="p-input" placeholder="اسم الوسم" data-step-k="tag" value="${esc(value.tag || '')}" style="grid-column:1/-1;"/>`;
            } else if (type === 'add_to_list' || type === 'remove_from_list') {
                body.innerHTML = `<select class="p-input" data-step-k="list_id" style="grid-column:1/-1;"><option value="">اختر قائمة...</option>
                    ${emAutoLists.map(l => `<option value="${l.id}"${String(value.list_id) === String(l.id) ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}
                </select>`;
            } else {
                body.innerHTML = '';
            }
        }

        function collectAutomationSteps() {
            const steps = [];
            document.querySelectorAll('.em-auto-step-row').forEach((row, position) => {
                const type = row.querySelector('select').value;
                const value = {};
                row.querySelectorAll('[data-step-k]').forEach(inp => {
                    const k = inp.dataset.stepK;
                    let v = inp.value;
                    if (k === 'days' || k === 'hours' || k === 'minutes' || k === 'list_id' || k === 'template_id') {
                        v = v === '' ? 0 : parseInt(v, 10) || 0;
                    }
                    value[k] = v;
                });
                if (type === 'send_email' && !value.subject && !value.html && !value.template_id) return;
                if ((type === 'add_tag' || type === 'remove_tag') && !value.tag) return;
                steps.push({ position, step_type: type, step_value: value });
            });
            return steps;
        }

        async function saveAutomation() {
            const name = document.getElementById('emAutoName').value.trim();
            if (!name) { notify('اسم سير العمل مطلوب'); return; }
            const triggerType = document.getElementById('emAutoTrigger').value;
            const tvEl = document.getElementById('emAutoTriggerValue');
            const tvRaw = tvEl ? tvEl.value : '';
            let triggerValue = {};
            if (triggerType === 'subscribed') triggerValue = { list_id: parseInt(tvRaw, 10) || 0 };
            else if (triggerType === 'campaign_opened' || triggerType === 'campaign_clicked') triggerValue = { campaign_id: parseInt(tvRaw, 10) || 0 };
            else if (triggerType === 'tag_added') triggerValue = { tag: tvRaw || '' };
            else if (triggerType === 'date_after') triggerValue = { days: parseInt(tvRaw, 10) || 7 };

            const body = {
                name,
                description: document.getElementById('emAutoDesc').value.trim(),
                trigger_type: triggerType,
                trigger_value: triggerValue,
                entry_audience_ids: [...document.getElementById('emAutoEntryAudience').selectedOptions].map(o => parseInt(o.value, 10)).filter(v => v > 0),
                exit_audience_ids: [...document.getElementById('emAutoExitAudience').selectedOptions].map(o => parseInt(o.value, 10)).filter(v => v > 0),
            };

            const steps = collectAutomationSteps();
            const url = emAutoEditingId ? '/automations/' + emAutoEditingId : '/automations';
            const r = emAutoEditingId
                ? await emApi(url, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                : await emPost(url, body);
            if (!r.success) { notify(r.error || 'حدث خطأ'); return; }
            const autoId = r.data.id || emAutoEditingId;
            const stepsR = await emPost('/automations/' + autoId + '/steps', { steps });
            if (!stepsR.success) { notify(stepsR.error || 'فشل حفظ الخطوات'); return; }
            closeAutomationModal();
            loadAutomations();
        }

        async function toggleAutomationStatus(id, active) {
            const r = await emPost('/automations/' + id + '/status', { status: active ? 'active' : 'paused' });
            if (r.success) loadAutomations();
        }

        async function deleteAutomation(id) {
            if (!confirm('حذف سير العمل؟')) return;
            const r = await emApi('/automations/' + id, { method: 'DELETE' });
            if (r.success) loadAutomations();
        }

        async function runAutomationsDue(btn) {
            btnBusy(btn, true);
            try {
                const r = await emPost('/automations/run-due', {});
                if (r.success) {
                    notify(`تمت المعالجة: ${r.data.processed || 0} مشاركة، اكتمل ${r.data.completed || 0}`);
                    loadAutomations();
                }
            } finally { btnBusy(btn, false); }
        }

        loadAutomations();
        JS;
    }

    private function smtpSettingsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        async function loadSmtpSettings() {
            const r = await emApi('/smtp-settings');
            if (!r.success) return;
            const s = r.data.settings || {};
            if (s.host) document.getElementById('emSmtpHost').value = s.host;
            if (s.port) document.getElementById('emSmtpPort').value = s.port;
            if (s.encryption) document.getElementById('emSmtpEncryption').value = s.encryption;
            if (s.username) document.getElementById('emSmtpUsername').value = s.username;
            if (s.from_email) document.getElementById('emSmtpFromEmail').value = s.from_email;
            if (s.from_name) document.getElementById('emSmtpFromName').value = s.from_name;
            const active = parseInt(s.is_active) === 1;
            document.getElementById('emSmtpActive').checked = active;
            document.getElementById('emSmtpToggleBtn').textContent = active ? '⏻ تعطيل' : '⏻ تفعيل';
            const e = r.data.effective || {};
            const ready = e.ready ? '✅ جاهز للإرسال' : '⚠️ غير مكتمل — أضف بيانات SMTP';
            document.getElementById('emSmtpStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:#f8fafc;border:1px solid #e5e7eb;">
                    <span style="font-size:20px;">${e.ready ? '📧' : '⚠️'}</span>
                    <span style="font-size:14px;">${ready} — المضيف: ${e.host || '—'}، المرسل: ${e.from_email || '—'}</span>
                </div>`;
        }
        async function saveSmtpSettings() {
            const body = {
                host: document.getElementById('emSmtpHost').value,
                port: parseInt(document.getElementById('emSmtpPort').value || '587', 10),
                encryption: document.getElementById('emSmtpEncryption').value,
                username: document.getElementById('emSmtpUsername').value,
                password: document.getElementById('emSmtpPassword').value,
                from_email: document.getElementById('emSmtpFromEmail').value,
                from_name: document.getElementById('emSmtpFromName').value,
                is_active: 1,
            };
            const r = await emPost('/smtp-settings', body);
            if (r.success) { notify('تم حفظ الإعدادات'); loadSmtpSettings(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function toggleSmtpSettings() {
            const target = !document.getElementById('emSmtpActive').checked;
            const r = await emPost('/smtp-settings', { is_active: target ? 1 : 0 });
            if (r.success) loadSmtpSettings();
            else notify(r.error || 'حدث خطأ');
        }
        async function deleteSmtpSettings() {
            if (!confirm('حذف إعدادات SMTP؟ سترجع لاستخدام إعدادات .env العامة.')) return;
            const r = await emApi('/smtp-settings', { method: 'DELETE' });
            if (r.success) { notify('تم حذف الإعدادات'); ['emSmtpHost','emSmtpPort','emSmtpEncryption','emSmtpUsername','emSmtpPassword','emSmtpFromEmail','emSmtpFromName'].forEach(id => document.getElementById(id).value = id === 'emSmtpPort' ? '587' : id === 'emSmtpEncryption' ? 'tls' : ''); loadSmtpSettings(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function testSmtpSettings() {
            const body = {
                host: document.getElementById('emSmtpHost').value,
                port: parseInt(document.getElementById('emSmtpPort').value || '587', 10),
                encryption: document.getElementById('emSmtpEncryption').value,
                username: document.getElementById('emSmtpUsername').value,
                password: document.getElementById('emSmtpPassword').value,
                from_email: document.getElementById('emSmtpFromEmail').value,
                from_name: document.getElementById('emSmtpFromName').value,
            };
            const r = await emPost('/smtp-settings/test', body);
            if (r.success) notify('✅ تم الاتصال والمصادقة بنجاح');
            else notify('❌ ' + (r.error || 'فشل الاختبار'));
        }
        async function loadDeliveryWebhook() {
            const r = await emApi('/smtp-settings/webhook');
            if (!r.success) return;
            const d = r.data || {};
            document.getElementById('emDeliveryWebhookEnabled').checked = !!d.enabled;
            document.getElementById('emDeliveryWebhookUrl').textContent = d.url || '—';
            document.getElementById('emDeliveryWebhookSecret').textContent = d.has_secret ? (d.secret_masked || '••••') : 'لا يوجد مفتاح بعد — اضغط "توليد مفتاح جديد"';
        }
        async function toggleDeliveryWebhook() {
            const enabled = document.getElementById('emDeliveryWebhookEnabled').checked;
            const r = await emPost('/smtp-settings/webhook', { enabled: enabled ? 1 : 0 });
            if (r.success) { notify(enabled ? 'تم تفعيل webhook تتبع التسليم' : 'تم تعطيل webhook تتبع التسليم'); loadDeliveryWebhook(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function regenerateDeliveryWebhookSecret() {
            const r = await emPost('/smtp-settings/webhook', { regenerate: 1, enabled: document.getElementById('emDeliveryWebhookEnabled').checked ? 1 : 0 });
            if (!r.success) { notify(r.error || 'حدث خطأ'); return; }
            notify('تم توليد مفتاح جديد — انسخه الآن، لن يظهر كاملًا بعد ذلك');
            document.getElementById('emDeliveryWebhookSecret').textContent = r.data.secret || '••••';
        }
        loadSmtpSettings();
        loadDeliveryWebhook();
        JS;
    }

    private function transactionalJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        let txEditingId = 0;
        let txSendingTemplateId = 0;
        async function loadTransactionalTemplates() {
            const r = await emApi('/transactional/templates');
            if (!r.success) return;
            const list = document.getElementById('emTxTemplates');
            list.innerHTML = (r.data.templates || []).length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>القالب</th><th>الموضوع</th><th>مرات الإرسال</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>${r.data.templates.map(t => `
                        <tr>
                            <td>${t.name} <span class="p-cell-muted">(${t.slug})</span></td>
                            <td>${t.subject}</td>
                            <td>${t.send_count || 0}</td>
                            <td>${parseInt(t.is_active) ? '✅ مفعّل' : '⛔ معطّل'}</td>
                            <td>
                                <button class="p-btn xs" onclick="previewTransactionalTemplate(${t.id})">👁 معاينة</button>
                                <button class="p-btn xs" onclick="openSendTransactional(${t.id})">📤 إرسال</button>
                                <button class="p-btn xs" onclick="editTransactionalTemplate(${t.id})">تعديل</button>
                                <button class="p-btn xs" onclick="duplicateTransactionalTemplate(${t.id})">نسخ</button>
                                <button class="p-btn xs danger" onclick="deleteTransactionalTemplate(${t.id})">حذف</button>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد قوالب معاملات بعد — أنشئ أول قالب.</p>';
            document.getElementById('emTxStats').innerHTML = renderTxStats(r.data);
        }
        function renderTxStats(r) {
            const k = r.templates || [];
            return `<div class="p-cell" style="flex:1;text-align:center;"><div style="font-size:20px;font-weight:700;">${k.length}</div><div style="font-size:12px;color:#6b7280;">القوالب</div></div>`;
        }
        async function openTransactionalTemplateModal() {
            txEditingId = 0;
            document.getElementById('emTxModalTitle').textContent = 'قالب معاملات جديد';
            document.getElementById('emTxName').value = '';
            document.getElementById('emTxSlug').value = '';
            document.getElementById('emTxSubject').value = '';
            document.getElementById('emTxHtml').value = '<h2>مرحبًا {{first_name}}!</h2><p>شكرًا لتواصلك معنا.</p>';
            document.getElementById('emTxTemplateModal').style.display = 'flex';
        }
        function closeTransactionalTemplateModal() {
            document.getElementById('emTxTemplateModal').style.display = 'none';
        }
        async function editTransactionalTemplate(id) {
            const r = await emApi('/transactional/templates/' + id);
            if (!r.success) return;
            const t = r.data.template;
            txEditingId = id;
            document.getElementById('emTxModalTitle').textContent = 'تعديل القالب';
            document.getElementById('emTxName').value = t.name;
            document.getElementById('emTxSlug').value = t.slug;
            document.getElementById('emTxSubject').value = t.subject;
            document.getElementById('emTxHtml').value = t.html_body;
            document.getElementById('emTxTemplateModal').style.display = 'flex';
        }
        async function saveTransactionalTemplate() {
            const body = {
                name: document.getElementById('emTxName').value,
                slug: document.getElementById('emTxSlug').value,
                subject: document.getElementById('emTxSubject').value,
                html_body: document.getElementById('emTxHtml').value,
                is_active: 1,
            };
            const path = txEditingId ? '/transactional/templates/' + txEditingId : '/transactional/templates';
            const opts = txEditingId
                ? { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
                : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
            const r = await emApi(path, opts);
            if (r.success) { closeTransactionalTemplateModal(); loadTransactionalTemplates(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function deleteTransactionalTemplate(id) {
            if (!confirm('حذف القالب؟')) return;
            const r = await emApi('/transactional/templates/' + id, { method: 'DELETE' });
            if (r.success) loadTransactionalTemplates();
        }
        async function previewTransactionalTemplate(id) {
            const r = await emApi('/transactional/templates/' + id);
            if (!r.success) return;
            const t = r.data.template || {};
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <div style="padding:12px 20px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">الموضوع: <b>${esc(t.subject || '')}</b></div>
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(t.html_body || '')}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function duplicateTransactionalTemplate(id) {
            const r = await emApi('/transactional/templates/' + id + '/duplicate', { method: 'POST' });
            if (r.success) loadTransactionalTemplates();
            else notify(r.error || 'فشل النسخ');
        }
        async function openSendTransactional(id) {
            txSendingTemplateId = id;
            document.getElementById('emTxSendEmail').value = '';
            document.getElementById('emTxSendName').value = '';
            document.getElementById('emTxSendData').value = '{"first_name":"أحمد"}';
            document.getElementById('emTxSendModal').style.display = 'flex';
        }
        function closeTransactionalSendModal() {
            document.getElementById('emTxSendModal').style.display = 'none';
        }
        async function sendTransactionalNow() {
            let data = {};
            try { data = JSON.parse(document.getElementById('emTxSendData').value || '{}'); }
            catch (e) { notify('بيانات JSON غير صالحة'); return; }
            const r = await emPost('/transactional/send', {
                template_id: txSendingTemplateId,
                to_email: document.getElementById('emTxSendEmail').value,
                data: Object.assign({ to_name: document.getElementById('emTxSendName').value }, data),
            });
            if (r.success) { notify('تم الإرسال'); closeTransactionalSendModal(); loadTransactionalLogs(); }
            else notify('❌ ' + (r.error || 'فشل الإرسال'));
        }
        async function loadTransactionalLogs() {
            const r = await emApi('/transactional/logs?limit=20');
            if (!r.success) return;
            const logs = r.data.logs || [];
            document.getElementById('emTxLogs').innerHTML = logs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>البريد</th><th>الموضوع</th><th>الحالة</th><th>فتح</th><th>كليك</th><th>التاريخ</th></tr></thead>
                    <tbody>${logs.map(l => `
                        <tr>
                            <td>${l.to_email}</td>
                            <td>${l.subject}</td>
                            <td>${l.status === 'sent' ? '✅ مرسل' : '❌ فشل'}</td>
                            <td>${l.open_count}</td>
                            <td>${l.click_count}</td>
                            <td>${l.created_at}</td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا سجل إرسال بعد.</p>';
        }
        loadTransactionalTemplates();
        loadTransactionalLogs();
        JS;
    }

    private function abTestsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        async function loadAbTests() {
            const r = await emApi('/ab-tests');
            if (!r.success) return;
            document.getElementById('emAbCampaign').innerHTML = (r.data.campaigns || []).map(c =>
                `<option value="${c.id}">${c.name}</option>`).join('');
            const list = document.getElementById('emAbTestsList');
            list.innerHTML = (r.data.ab_tests || []).length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الاختبار</th><th>الحملة</th><th>النسبة</th><th>المقياس</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>${r.data.ab_tests.map(t => `
                        <tr>
                            <td>${t.name}</td>
                            <td>${t.base_name || '—'}</td>
                            <td>أ ${100 - parseInt(t.split_percent)}% / ب ${t.split_percent}%</td>
                            <td>${t.metric === 'click' ? 'كليك' : 'فتح'}</td>
                            <td>${t.status_label || t.status}</td>
                            <td><a class="p-btn xs" href="/email-marketing/ab-tests/${t.id}">إدارة</a></td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد اختبارات بعد — أنشئ أول اختبار أ/ب.</p>';
        }
        function openAbTestModal() {
            document.getElementById('emAbName').value = '';
            document.getElementById('emAbSplit').value = '50';
            document.getElementById('emAbMetric').value = 'open';
            document.getElementById('emAbTestModal').style.display = 'flex';
        }
        function closeAbTestModal() {
            document.getElementById('emAbTestModal').style.display = 'none';
        }
        async function createAbTest() {
            const r = await emPost('/ab-tests', {
                name: document.getElementById('emAbName').value,
                base_campaign_id: parseInt(document.getElementById('emAbCampaign').value || '0', 10),
                split_percent: parseInt(document.getElementById('emAbSplit').value || '50', 10),
                metric: document.getElementById('emAbMetric').value,
            });
            if (r.success) { closeAbTestModal(); loadAbTests(); window.location = '/email-marketing/ab-tests/' + r.data.id; }
            else notify(r.error || 'حدث خطأ');
        }
        loadAbTests();
        JS;
    }

    private function abTestDetailsJs(int $id): string
    {
        $id = (int) $id;
        $js = <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(تم|تمت|أُعيد|✅|نجح|حُفظ)/.test(m) || m.indexOf('نجاح') !== -1) type = 'success';
                else if (/^(❌|لا يوجد|تعذر|فشل|خطأ)/.test(m) || m.indexOf('خطأ') !== -1 || m.indexOf('مطلوب') !== -1 || m.indexOf('غير موجود') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">جارٍ...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        async function saveAbVariant(variant) {
            const body = {
                variant,
                subject: document.getElementById('emAb' + (variant === 'a' ? 'A' : 'B') + 'Subject').value,
                html_body: document.getElementById('emAb' + (variant === 'a' ? 'A' : 'B') + 'Html').value,
            };
            const r = await emPost('/ab-tests/__AB_ID__/variant', body);
            if (r.success) notify('تم حفظ المتغير ' + (variant === 'a' ? 'أ' : 'ب'));
            else notify(r.error || 'حدث خطأ');
        }
        async function runAbTest(btn) {
            btnBusy(btn, true);
            try {
                const r = await emPost('/ab-tests/__AB_ID__/start', {});
                if (r.success) notify('تم التشغيل: أ = ' + r.data.a + '، ب = ' + r.data.b + ' مستلم');
                else notify(r.error || 'حدث خطأ');
            } finally { btnBusy(btn, false); }
        }
        async function sendAbBatch(btn) {
            btnBusy(btn, true);
            try {
                const r = await emPost('/ab-tests/__AB_ID__/send-batch', {});
                if (r.success) {
                    notify('تمت جدولة إرسال دفعة اختبار أ/ب — سيُرسل عبر cron');
                } else notify(r.error || 'حدث خطأ');
            } finally { btnBusy(btn, false); }
        }
        async function sendAbTestEmail(btn) {
            const email = (document.getElementById('emAbTestEmail') || {}).value || '';
            if (!email || email.indexOf('@') === -1) { notify('أدخل بريدًا صالحًا لإرسال الاختبار'); return; }
            const variant = (document.getElementById('emAbTestVariant') || {}).value || 'all';
            btnBusy(btn, true);
            try {
                const r = await emPost('/ab-tests/__AB_ID__/send-test', { email, variant });
                if (r.success) notify('تم إرسال رسائل الاختبار بنجاح إلى ' + email);
                else notify(r.error || 'حدث خطأ');
            } finally { btnBusy(btn, false); }
        }
        async function loadAbReport() {
            const r = await emApi('/ab-tests/__AB_ID__/report');
            if (!r.success) return;
            const rep = r.data.report;
            const va = rep.variant_a || {}, vb = rep.variant_b || {};
            const isClick = rep.metric === 'click';
            const rk = isClick ? 'click_rate' : 'open_rate';
            const sg = rep.significance || {};
            const conf = Number(sg.confidence || 0);
            const confColor = sg.significant ? '#059669' : (conf >= 80 ? '#d97706' : '#6b7280');
            const headline = rep.winner ? '🏆 ' + (rep.winner === 'a' ? 'المتغير أ' : 'المتغير ب')
                : (rep.leader ? '⏳ ' + (rep.leader === 'a' ? 'أ' : 'ب') + ' متقدم' : 'متعادل');
            document.getElementById('emAbReport').innerHTML = `
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
                    <div class="p-cell" style="text-align:center;"><div style="font-size:20px;font-weight:700;">🅰️ ${va[rk] || 0}%</div><div style="font-size:12px;color:#6b7280;">${isClick ? 'كليك' : 'فتح'} أ</div></div>
                    <div class="p-cell" style="text-align:center;"><div style="font-size:20px;font-weight:700;">🅱️ ${vb[rk] || 0}%</div><div style="font-size:12px;color:#6b7280;">${isClick ? 'كليك' : 'فتح'} ب</div></div>
                    <div class="p-cell" style="text-align:center;"><div style="font-size:20px;font-weight:700;color:${confColor};">${conf}%</div><div style="font-size:12px;color:#6b7280;">الثقة الإحصائية (لازم 95%)</div></div>
                    <div class="p-cell" style="text-align:center;${rep.winner ? 'background:#ecfdf5;' : ''}"><div style="font-size:20px;font-weight:700;">${headline}</div></div>
                </div>
                <div class="p-cell" style="margin-bottom:14px;font-size:13px;">💡 ${rep.recommendation || ''}${!isClick ? '<br><span style="color:#6b7280;font-size:12px;">ملحوظة: نسبة الفتح بتتأثر بخاصية حماية الخصوصية في آيفون (بتعلّم الإيميل مفتوح لوحده) - الكليك مقياس أدق.</span>' : ''}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <table class="p-table"><thead><tr><th>مقياس</th><th>أ</th><th>ب</th></tr></thead><tbody>
                        <tr><td>مرسل</td><td>${va.sent_count || 0}</td><td>${vb.sent_count || 0}</td></tr>
                        <tr><td>فتح</td><td>${va.opened_count || 0}</td><td>${vb.opened_count || 0}</td></tr>
                        <tr><td>كليك</td><td>${va.clicked_count || 0}</td><td>${vb.clicked_count || 0}</td></tr>
                        <tr><td>معدل الفتح</td><td>${va.open_rate || 0}%</td><td>${vb.open_rate || 0}%</td></tr>
                        <tr><td>معدل الكليك</td><td>${va.click_rate || 0}%</td><td>${vb.click_rate || 0}%</td></tr>
                    </tbody></table>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <button class="p-btn primary" onclick="declareWinner('a')">🏆 إعلان المتغير أ فائزًا</button>
                        <button class="p-btn primary" onclick="declareWinner('b')">🏆 إعلان المتغير ب فائزًا</button>
                        <button class="p-btn" onclick="applyWinner()">📋 نسخ الفائز للحملة الأساسية</button>
                    </div>
                </div>`;
        }
        async function declareWinner(winner) {
            const r = await emPost('/ab-tests/__AB_ID__/winner', { winner });
            if (r.success) { notify('تم إعلان الفائز'); loadAbReport(); }
            else notify(r.error || 'حدث خطأ');
        }
        async function applyWinner() {
            const r = await emPost('/ab-tests/__AB_ID__/apply-winner', {});
            if (r.success) notify('تم نسخ الفائز للحملة الأساسية');
            else notify(r.error || 'حدث خطأ');
        }
        loadAbReport();
        JS;
        return str_replace('__AB_ID__', (string) $id, $js);
    }
}
