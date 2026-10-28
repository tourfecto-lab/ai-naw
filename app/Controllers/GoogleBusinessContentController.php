<?php

/**
 * Tourfecto - Google Business Profile Content Controller
 * القيمة الجديدة من دمج ai-google-business-hub: توليد وجدولة منشورات
 * GBP. الاتصال/المراجعات نفسها مُدارة فعليًا من ReputationController.
 * @version 1.0.0
 */
class GoogleBusinessContentController extends Controller
{
    /** @var GbpContentService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new GbpContentService();
    }

    /** GET /gbp-content */
    public function index(array $params = []): array
    {
        // تصحيح: بيقرا من إعدادات النظام القابلة للتعديل من لوحة الأدمن الأول
        // (زي GoogleOAuthClient بالظبط) قبل ما يرجع لـ .env كاحتياط، عشان
        // الأدمن يقدر يضبط المفتاح من اللوحة من غير ما يلمس السيرفر.
        $envMapsKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        $mapsApiKey = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_maps_api_key', $envMapsKey)
            : $envMapsKey;
        $mapsApiKey = is_real_secret($mapsApiKey) ? $mapsApiKey : '';
        $mapsConfigured = $mapsApiKey !== '';
        $mapsKeySafe = htmlspecialchars($mapsApiKey, ENT_QUOTES, 'UTF-8');
        $isAdminViewer = in_array((string) ($this->user['role'] ?? ''), ['admin', 'super_admin'], true);
        // تفاصيل المفتاح وملف .env تخص الأدمن بس - العميل يشوف رسالة مفهومة من غير مصطلحات سيرفر
        $mapsMissingText = $isAdminViewer
            ? 'خريطة Google غير مفعّلة - ضيف <code>Google Maps API Key</code> من لوحة الأدمن (الإعدادات ← النظام ← Google Maps) أو <code>GOOGLE_MAPS_API_KEY</code> في .env، وفعّل Maps JavaScript API و Places API و Geocoding API على نفس مشروع Google Cloud.'
            : 'الخريطة هتظهر هنا أول ما فريق المنصة يفعّلها، وساعتها تقدر تحدد مكان شركتك على خرائط Google. مش محتاج تعمل حاجة دلوقتي.';
        // لغة الخريطة حسب لغة العميل (المنصة عالمية) بدل عربي/مصر ثابت
        $mapsLang = function_exists('current_lang') ? current_lang() : 'en';

        // تحميل مكتبة Google Maps JS فقط لو المفتاح متضبط - لو مش متضبط
        // منعرضش خريطة معطوبة، بنستبدلها برسالة واضحة للأدمن بدل التحميل
        // من غير مفتاح (اللي بيطلع أخطاء Console وعلامة "For development
        // purposes only" على الخريطة).
        $mapScripts = $mapsConfigured
            ? "<script src=\"https://maps.googleapis.com/maps/api/js?key={$mapsKeySafe}&libraries=places&language={$mapsLang}\"></script>"
            : '';

        $mapCard = $mapsConfigured ? <<<HTML
        <style>
            #gbpLocMap { position: relative; z-index: 1; }
            .pill.orange { animation: gbpPulse 1.2s ease-in-out infinite; }
            @keyframes gbpPulse { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
            /* تنسيق قائمة اقتراحات العناوين من Google Places على شكل اللوحة الداكن */
            .pac-container {
                background: var(--panel-card-bg-2, #152238);
                border: 1px solid var(--panel-border, rgba(255,255,255,.09));
                border-radius: 10px; margin-top: 6px; z-index: 10000;
                box-shadow: 0 10px 24px -12px rgba(0,0,0,.6); font-family: inherit;
            }
            .pac-item { color: #F2F4F8; border-top-color: rgba(255,255,255,.08); padding: 8px 12px; }
            .pac-item:hover { background: rgba(255,255,255,.06); }
            .pac-item-query { color: #F2F4F8; }
            .pac-matched { color: var(--panel-accent, #EFB05E); }
            .pac-icon { filter: invert(1) grayscale(1) brightness(1.6); }
        </style>

        <div class="p-card no-pad" id="gbpLocationCard" style="margin-bottom:20px;overflow:hidden;">
            <div class="p-card-head" style="align-items:flex-start;padding:18px 18px 0;margin-bottom:12px;">
                <div>
                    <h3>📍 موقع النشاط على خرائط Google</h3>
                    <span class="p-card-sub" id="gbpLocAddress">حرّك الدبوس أو ابحث بالعنوان لتحديد موقع نشاطك بدقة</span>
                </div>
                <div style="display:flex;gap:8px;align-items:center"><span class="pill gray" id="gbpLocStatus" style="white-space:nowrap;">غير محدد</span><button class="p-btn outline xs" id="gbpLocEditBtn" type="button">✏️ عدّل يدوي</button></div>
            </div>

            <div id="gbpLocEditor" hidden>
            <div style="display:flex;gap:8px;padding:0 18px 14px;flex-wrap:wrap;">
                <input type="text" id="gbpLocSearch" class="p-select" placeholder="🔍 ابحث بالعنوان... مثال: القاهرة، مصر" style="flex:1;min-width:220px;" autocomplete="off">
                <button class="p-btn outline" id="gbpLocSearchBtn" type="button">بحث</button>
                <button class="p-btn outline" id="gbpLocGeoBtn" type="button" title="استخدام موقعي الحالي">📡 موقعي الحالي</button>
                <button class="p-btn" id="gbpLocSaveBtn" type="button">💾 حفظ الموقع</button>
            </div>

            <div id="gbpLocMap" style="width:100%;height:380px;background:#0A1220;"></div>
            </div>
            <div class="p-cell-muted" style="padding:10px 18px 16px;">
                💡 الموقع ده بيتاخد تلقائي من بروفايل Google Business المربوط، وبتستخدمه المنصة في <b>شبكة الترتيب المحلي</b> (ترتيبك على الخرايط من نقط حوالين مكانك) و<b>مقارنة المنافسين القريبين</b>. تعديله هنا <b>مابيغيّرش</b> موقعك على جوجل نفسه.
            </div>
        </div>
        HTML
            : <<<HTML
        <div class="p-card" id="gbpLocationCard" style="margin-bottom:20px;">
            <div class="p-card-head"><h3>📍 موقع النشاط على الخريطة</h3></div>
            <div class="p-empty">
                <div class="p-empty-icon">🗺️</div>
                {$mapsMissingText}
            </div>
        </div>
        HTML;

        $body = <<<HTML
        {$mapScripts}

        <style>
        .gbp-skel { background: linear-gradient(90deg, rgba(255,255,255,.06) 25%, rgba(255,255,255,.14) 37%, rgba(255,255,255,.06) 63%); background-size: 400% 100%; animation: gbpShimmer 1.4s ease infinite; border-radius: 8px; }
        @keyframes gbpShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
        </style>

        <!-- ==================== Setup Wizard ==================== -->
        <div class="p-card" id="gbpSetupWizard" style="margin-bottom:20px;">
            <div class="p-card-head"><h3>⚙️ حالة الإعداد (Setup Wizard)</h3><span class="p-card-sub">حالة كل متطلبات تشغيل Google Business Profile فعليًا</span></div>
            <div id="gbpSetupGrid" class="p-grid cols-3" style="gap:12px;padding:0 18px 18px;">
                <div class="gbp-skel" style="height:64px;"></div><div class="gbp-skel" style="height:64px;"></div><div class="gbp-skel" style="height:64px;"></div>
            </div>
        </div>

        <!-- ==================== Connection Center ==================== -->
        <div class="p-card" id="gbpConnectionCenter" style="margin-bottom:20px;">
            <div class="p-card-head">
                <h3>🔌 مركز الاتصال (Connection Center)</h3>
                <div style="display:flex;gap:8px;">
                    <span class="p-card-sub">اربط، أعد الربط، أو زامن كل موقع على حدة</span>
                    <button class="p-btn outline xs" onclick="document.getElementById('gbpAddLocationModal').classList.add('open')">+ إضافة موقع (Location)</button>
                </div>
            </div>
            <div id="gbpConnectionsList"><div class="gbp-skel" style="height:56px;margin:12px 18px;"></div><div class="gbp-skel" style="height:56px;margin:12px 18px;"></div></div>
        </div>

        <div class="p-modal-overlay" id="gbpAddLocationModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>إضافة موقع جديد (Location)</h3><button class="p-modal-close" onclick="document.getElementById('gbpAddLocationModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>اسم النشاط</label>
                    <input type="text" id="gbpNewLocationName" class="p-select" style="width:100%;margin-bottom:10px;" placeholder="مثال: فرع المعادي">
                    <label>رابط الموقع الإلكتروني</label>
                    <input type="text" id="gbpNewLocationUrl" class="p-select" style="width:100%;margin-bottom:6px;" placeholder="https://example.com">
                    <div class="p-cell-muted" style="font-size:11px;">بعد إضافة الموقع، هتقدر تربطه بـ Google Business Profile فورًا من قائمة الاتصالات فوق.</div>
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="addGbpLocation()">إضافة</button></div>
            </div>
        </div>

        {$mapCard}

        <!-- ==================== Location Dashboard ==================== -->
        <div class="p-card" id="gbpLocationDashboard" style="margin-bottom:20px;">
            <div class="p-card-head">
                <h3>📍 لوحة معلومات الموقع</h3>
                <button class="p-btn outline xs" onclick="gbpReloadDashboard()">🔄 تحديث البيانات</button>
            </div>
            <div id="gbpDashboardBody"><div class="p-empty">اختر موقعًا مربوطًا لعرض بياناته</div></div>
        </div>

        <!-- ==================== Profile Management ==================== -->
        <div class="p-card" id="gbpProfileCard" style="margin-bottom:20px;">
            <div class="p-card-head">
                <h3>🏢 إدارة بروفايل النشاط</h3>
                <span class="p-card-sub" id="gbpProfileUnsaved" style="display:none;color:var(--panel-accent,#EFB05E);">تغييرات غير محفوظة</span>
            </div>
            <div id="gbpProfileBody"><div class="p-empty">اختر موقعًا مربوطًا بـ Google Business Profile لعرض بياناته</div></div>
        </div>

        <!-- ==================== Photos ==================== -->
        <div class="p-card" id="gbpPhotosCard" style="margin-bottom:20px;">
            <div class="p-card-head">
                <h3>🖼️ الصور</h3>
                <label class="p-btn outline xs" style="cursor:pointer;">
                    + رفع صورة
                    <input type="file" id="gbpPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
                </label>
                <button type="button" class="p-btn outline xs" id="gbpFromLibraryBtn">🎨 من مكتبة الاستوديو</button>
            </div>
            <div id="gbpLibraryPicker" style="display:none;padding:0 18px 12px;">
                <div class="p-cell-muted" style="font-size:12.5px;margin-bottom:8px;">اختار صورة من الاستوديو الإبداعي (المتولدة أو صورك الحقيقية) وهتترفع على Google بالتصنيف المختار تحت.</div>
                <div id="gbpLibraryGrid" class="p-grid cols-4" style="gap:8px;"></div>
            </div>
            <div style="padding:0 18px 10px;">
                <select id="gbpPhotoCategory" class="p-select" style="max-width:260px;">
                    <option value="COVER">صورة الغلاف</option>
                    <option value="PROFILE">صورة البروفايل</option>
                    <option value="EXTERIOR">واجهة خارجية</option>
                    <option value="INTERIOR">من الداخل</option>
                    <option value="PRODUCT">منتج</option>
                    <option value="AT_WORK">أثناء العمل</option>
                    <option value="FOOD_AND_DRINK">طعام/شراب</option>
                    <option value="MENU">قائمة الطعام</option>
                    <option value="COMMON_AREA">منطقة مشتركة</option>
                    <option value="TEAMS">الفريق</option>
                    <option value="ADDITIONAL" selected>إضافي</option>
                </select>
            </div>
            <div id="gbpPhotosGrid" class="p-grid cols-4" style="gap:10px;padding:0 18px 18px;"><div class="gbp-skel" style="height:100px;"></div><div class="gbp-skel" style="height:100px;"></div><div class="gbp-skel" style="height:100px;"></div><div class="gbp-skel" style="height:100px;"></div></div>
        </div>

        <!-- ==================== Insights & Analytics ==================== -->
        <div class="p-card" id="gbpInsightsCard" style="margin-bottom:20px;">
            <div class="p-card-head">
                <h3>📊 الأداء والتحليلات</h3>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <button class="p-btn outline xs gbp-range-btn" data-days="7" type="button">7 أيام</button>
                    <button class="p-btn outline xs gbp-range-btn active" data-days="30" type="button">30 يوم</button>
                    <button class="p-btn outline xs gbp-range-btn" data-days="90" type="button">90 يوم</button>
                    <input type="date" id="gbpCustomFrom" class="p-select" style="width:130px;">
                    <span>—</span>
                    <input type="date" id="gbpCustomTo" class="p-select" style="width:130px;">
                    <button class="p-btn outline xs" onclick="applyGbpCustomRange()">تطبيق</button>
                </div>
            </div>
            <div id="gbpInsightsKpis" class="p-grid cols-4" style="gap:12px;padding:0 18px 10px;"><div class="gbp-skel" style="height:52px;"></div><div class="gbp-skel" style="height:52px;"></div><div class="gbp-skel" style="height:52px;"></div><div class="gbp-skel" style="height:52px;"></div></div>
            <div style="padding:0 18px 18px;"><canvas id="gbpInsightsChart" height="90"></canvas></div>
            <div id="gbpInsightsEmpty" class="p-empty" style="display:none;">Not enough data / Not available from Google API</div>
        </div>

        <!-- ==================== AI Insights & Recommendations ==================== -->
        <div class="p-grid cols-2" style="gap:20px;margin-bottom:20px;">
            <div class="p-card">
                <div class="p-card-head"><h3>🤖 تحليلات AI</h3></div>
                <div id="gbpAiSummary" class="p-cell-muted" style="padding:0 18px 6px;"></div>
                <div id="gbpAiInsightsList" style="padding:0 18px 18px;"><div class="gbp-skel" style="height:18px;margin-bottom:6px;"></div><div class="gbp-skel" style="height:18px;width:70%;"></div></div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>💡 التوصيات المقترحة</h3></div>
                <div id="gbpRecommendationsList" style="padding:0 18px 18px;"><div class="gbp-skel" style="height:18px;margin-bottom:6px;"></div><div class="gbp-skel" style="height:18px;width:70%;"></div></div>
            </div>
        </div>

        <!-- ==================== Posts (موجود مسبقًا) ==================== -->
        <div class="p-toolbar">
            <h3 style="margin:0;">منشورات GBP</h3>
            <button class="p-btn" onclick="document.getElementById('gbpModal').classList.add('open')">+ منشور جديد</button>
        </div>
        <div class="p-grid cols-2" id="gbpGrid"><div class="p-empty">جارِ التحميل...</div></div>

        <div class="p-modal-overlay" id="gbpModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>منشور Google Business Profile</h3><button class="p-modal-close" onclick="document.getElementById('gbpModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>الموقع</label>
                    <select id="gbpWebsiteId" class="p-select" style="width:100%;margin-bottom:10px;"></select>
                    <label>النوع</label>
                    <select id="gbpType" class="p-select" style="width:100%;margin-bottom:6px;">
                        <option value="update">تحديث عام</option>
                        <option value="offer">عرض خاص</option>
                        <option value="event">فعالية</option>
                    </select>
                    <div id="gbpOfferEventFields" style="display:none;border:1px solid var(--panel-border);border-radius:10px;padding:10px;margin:6px 0 10px;">
                        <label id="gbpTitleLabel">عنوان العرض</label>
                        <input id="gbpPostTitle" class="p-select" maxlength="58" style="width:100%;margin-bottom:8px;" placeholder="مثلاً: خصم 20% على رحلات الأقصر">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;">
                            <div style="min-width:0;"><label>من</label><input type="datetime-local" id="gbpStart" class="p-select" style="width:100%;min-width:0;box-sizing:border-box;"></div>
                            <div style="min-width:0;"><label>لحد</label><input type="datetime-local" id="gbpEnd" class="p-select" style="width:100%;min-width:0;box-sizing:border-box;"></div>
                        </div>
                        <div id="gbpOfferOnly" style="margin-top:8px;">
                            <label>كود الخصم (اختياري)</label>
                            <input id="gbpCoupon" class="p-select" maxlength="58" style="width:100%;margin-bottom:8px;" dir="ltr">
                            <label>الشروط (اختياري)</label>
                            <input id="gbpTerms" class="p-select" maxlength="500" style="width:100%;">
                        </div>
                    </div>
                    <div id="gbpCtaWrap" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:10px;">
                        <div style="min-width:0;"><label>زرار المنشور</label>
                            <select id="gbpCtaType" class="p-select" style="width:100%;">
                                <option value="LEARN_MORE">اعرف أكتر</option>
                                <option value="BOOK">احجز</option>
                                <option value="ORDER">اطلب</option>
                                <option value="SHOP">تسوّق</option>
                                <option value="SIGN_UP">سجّل</option>
                                <option value="CALL">اتصل</option>
                            </select></div>
                        <div style="min-width:0;"><label>رابط الزرار (اختياري)</label><input id="gbpCtaUrl" class="p-select" style="width:100%;box-sizing:border-box;" dir="ltr" placeholder="رابط موقعك افتراضيًا"></div>
                    </div>
                    <label>الوصف</label>
                    <textarea id="gbpPrompt" rows="3" style="width:100%;" class="p-select"></textarea>
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="generateGbp()">توليد</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    function formatBusinessAddress(address) {
        if (typeof address === 'string') return address;
        if (!address || typeof address !== 'object') return '';
        const lines = Array.isArray(address.addressLines) ? address.addressLines : [];
        const parts = [...lines, address.sublocality, address.locality, address.administrativeArea, address.postalCode, address.regionCode];
        return [...new Set(parts.filter(x => typeof x === 'string' && x.trim()).map(x => x.trim()))].join('، ');
    }

    const jsonHeaders = { 'Content-Type': 'application/json' };

    async function loadWebsites() {
        const res = await fetchJSON('/api/websites');
        const sel = document.getElementById('gbpWebsiteId');
        if (res.success && res.data.websites) {
            sel.innerHTML = res.data.websites.map(w => `<option value="${w.id}">${esc(w.company_name || w.main_url)}</option>`).join('');
            P.syncWebsiteSelect('gbpWebsiteId');
        }
    }

    async function load() {
        const res = await fetchJSON('/api/gbp/content');
        const grid = document.getElementById('gbpGrid');
        if (res.success && res.data.items && res.data.items.length) {
            grid.innerHTML = res.data.items.map(c => {
                const sched = c.latest_schedule;
                const canEdit = (c.status === 'draft' || c.status === 'ready') && (!sched || sched.status === 'cancelled');
                const canDelete = canEdit;
                const canCancel = sched && sched.status === 'pending';
                return `
                <div class="p-card" id="gbpCard${c.id}">
                    <div class="p-card-head"><h3>${esc(c.type)}</h3><span class="p-card-sub">${statusLabel(sched ? sched.status : c.status)}</span></div>
                    <div id="gbpText${c.id}" class="p-cell-muted">${esc((c.generated_text || '').slice(0, 300))}</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
                        ${canEdit ? `<button class="p-btn outline xs" onclick="publishGbp(${c.id}, ${c.website_id})">🚀 نشر الآن على Google Business</button>` : ''}
                        ${canEdit ? `<button class="p-btn outline xs" onclick="startEditGbp(${c.id})">✏️ تعديل</button>` : ''}
                        ${canDelete ? `<button class="p-btn outline xs" style="color:#e05656;" onclick="deleteGbpContent(${c.id})">🗑️ حذف</button>` : ''}
                        ${canCancel ? `<button class="p-btn outline xs" style="color:#e05656;" onclick="cancelGbpSchedule(${c.id}, ${sched.id})">⛔ إلغاء الجدولة</button>` : ''}
                    </div>
                </div>
            `;
            }).join('');
        } else {
            grid.innerHTML = '<div class="p-empty"><div class="p-empty-icon">📍</div>لا يوجد منشورات بعد</div>';
        }
    }

    function statusLabel(status) {
        return { draft: 'مسودة', ready: 'جاهز', pending: 'مجدول', scheduled: 'مجدول', processing: 'جارِ النشر', published: '✔ منشور', failed: '✖ فشل', cancelled: 'ملغى' }[status] || status;
    }

    window.startEditGbp = function (contentId) {
        const textBox = document.getElementById('gbpText' + contentId);
        const currentText = textBox.textContent;
        textBox.innerHTML = `
            <textarea id="gbpEditArea${contentId}" class="p-select" rows="4" style="width:100%;">${esc(currentText)}</textarea>
            <div style="display:flex;gap:6px;margin-top:6px;">
                <button class="p-btn xs" onclick="saveEditGbp(${contentId})">💾 حفظ</button>
                <button class="p-btn outline xs" onclick="gbpReloadContent()">إلغاء</button>
            </div>`;
    };

    window.saveEditGbp = async function (contentId) {
        const newText = document.getElementById('gbpEditArea' + contentId).value.trim();
        if (!newText) { P.toast('نص المنشور لا يمكن أن يكون فارغًا', 'error'); return; }

        const res = await fetchJSON('/api/gbp/content/' + contentId, { method: 'PUT', headers: jsonHeaders, body: JSON.stringify({ generated_text: newText }) });
        if (res.success) { P.toast('تم تعديل المنشور', 'success'); load(); }
        else P.toast(res.error || 'فشل التعديل', 'error');
    };

    window.deleteGbpContent = async function (contentId) {
        if (!confirm('حذف المسودة دي نهائيًا؟')) return;
        const res = await fetchJSON('/api/gbp/content/' + contentId, { method: 'DELETE' });
        if (res.success) { P.toast('تم الحذف', 'success'); load(); }
        else P.toast(res.error || 'فشل الحذف', 'error');
    };

    window.cancelGbpSchedule = async function (contentId, scheduleId) {
        if (!confirm('إلغاء جدولة النشر دي؟')) return;
        const res = await fetchJSON('/api/gbp/content/' + contentId + '/schedule/' + scheduleId + '/cancel', { method: 'POST', headers: jsonHeaders });
        if (res.success) { P.toast('تم إلغاء الجدولة', 'success'); load(); }
        else P.toast(res.error || 'فشل الإلغاء', 'error');
    };

    window.publishGbp = async function (contentId, websiteId) {
        const statusRes = await fetchJSON('/api/reputation/platforms?website_id=' + websiteId);

        if (!statusRes.success || !statusRes.data.google_connected || !statusRes.data.google_connection_id) {
            P.toast('الموقع ده لسه مش مربوط بـ Google Business Profile - اربطه الأول من صفحة "إدارة السمعة"', 'error');
            return;
        }

        if (!confirm('هيتم نشر المنشور ده فورًا على Google Business Profile الحقيقي بتاعك. متابعة؟')) return;

        const res = await fetchJSON('/api/gbp/content/' + contentId + '/schedule', {
            method: 'POST', headers: jsonHeaders,
            body: JSON.stringify({ platform_connection_id: statusRes.data.google_connection_id, scheduled_at: new Date().toISOString() }),
        });

        if (res.success) { P.toast('اتجدول للنشر - هيظهر على Google خلال دقايق', 'success'); load(); }
        else P.toast(res.error || 'تعذر الجدولة', 'error');
    };

    function syncGbpTypeFields() {
        const t = document.getElementById('gbpType').value;
        const box = document.getElementById('gbpOfferEventFields');
        if (!box) return;
        box.style.display = (t === 'offer' || t === 'event') ? 'block' : 'none';
        document.getElementById('gbpOfferOnly').style.display = t === 'offer' ? 'block' : 'none';
        document.getElementById('gbpTitleLabel').textContent = t === 'event' ? 'اسم الفعالية' : 'عنوان العرض';
        document.getElementById('gbpCtaWrap').style.display = t === 'offer' ? 'none' : 'grid';
    }
    document.addEventListener('change', function (e) { if (e.target && e.target.id === 'gbpType') syncGbpTypeFields(); });

    window.generateGbp = async function () {
        const website_id = document.getElementById('gbpWebsiteId').value;
        const type = document.getElementById('gbpType').value;
        const prompt = document.getElementById('gbpPrompt').value.trim();
        if (!website_id || !prompt) return;
        const v = id => (document.getElementById(id) || {}).value || '';
        const body = { website_id, type, prompt, cta_type: v('gbpCtaType'), cta_url: v('gbpCtaUrl').trim() };
        if (type === 'offer' || type === 'event') {
            Object.assign(body, { post_title: v('gbpPostTitle').trim(), event_start: v('gbpStart'), event_end: v('gbpEnd') });
            if (type === 'offer') Object.assign(body, { coupon_code: v('gbpCoupon').trim(), offer_terms: v('gbpTerms').trim() });
        }
        const res = await fetchJSON('/api/gbp/content', { method: 'POST', headers: jsonHeaders, body: JSON.stringify(body) });
        if (!res.success && res.code === 422) { P.toast(res.error, 'error'); return; }
        document.getElementById('gbpModal').classList.remove('open');
        if (res.success) { P.toast('تم توليد المحتوى', 'success'); load(); }
        else P.toast(res.error || 'فشل التوليد', 'error');
    };

    // ============ خريطة موقع النشاط على Google Maps (لحظية) ============
    let gbpMap = null, gbpMarker = null, gbpGeocoder = null, gbpAutocomplete = null, gbpSaveTimer = null;
    const GBP_DEFAULT_CENTER = { lat: 30.0444, lng: 31.2357 }; // القاهرة كنقطة افتراضية

    function setGbpStatus(text, cls) {
        const el = document.getElementById('gbpLocStatus');
        if (!el) return;
        el.textContent = text;
        el.className = 'pill ' + (cls || 'gray');
    }

    function gbpMapsReady() {
        return typeof google !== 'undefined' && google.maps;
    }

    function initGbpMap() {
        if (gbpMap || !document.getElementById('gbpLocMap') || !gbpMapsReady()) return;

        gbpMap = new google.maps.Map(document.getElementById('gbpLocMap'), {
            center: GBP_DEFAULT_CENTER,
            zoom: 6,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            clickableIcons: false,
        });

        gbpGeocoder = new google.maps.Geocoder();

        gbpMarker = new google.maps.Marker({
            position: GBP_DEFAULT_CENTER,
            map: gbpMap,
            draggable: true,
        });

        gbpMarker.addListener('dragend', onGbpMarkerMoved);
        gbpMap.addListener('click', (e) => {
            gbpMarker.setPosition(e.latLng);
            onGbpMarkerMoved();
        });

        // اقتراحات عناوين لحظية أثناء الكتابة (Google Places Autocomplete)
        const searchInput = document.getElementById('gbpLocSearch');
        if (searchInput && google.maps.places) {
            gbpAutocomplete = new google.maps.places.Autocomplete(searchInput, {
                fields: ['geometry', 'formatted_address', 'name'],
            });
            gbpAutocomplete.addListener('place_changed', () => {
                const place = gbpAutocomplete.getPlace();
                if (!place.geometry || !place.geometry.location) return;
                const loc = place.geometry.location;
                gbpMarker.setPosition(loc);
                gbpMap.setCenter(loc);
                gbpMap.setZoom(16);
                const address = place.formatted_address || place.name || '';
                document.getElementById('gbpLocAddress').textContent = address;
                clearTimeout(gbpSaveTimer);
                saveGbpLocation(loc.lat(), loc.lng(), address);
            });
        }
    }

    function reverseGeocodeGbp(lat, lng) {
        return new Promise((resolve) => {
            if (!gbpGeocoder) { resolve(''); return; }
            gbpGeocoder.geocode({ location: { lat, lng } }, (results, status) => {
                resolve((status === 'OK' && results && results[0]) ? results[0].formatted_address : '');
            });
        });
    }

    async function onGbpMarkerMoved() {
        const pos = gbpMarker.getPosition();
        const lat = pos.lat(), lng = pos.lng();
        setGbpStatus('جارِ التحديث...', 'orange');
        const address = await reverseGeocodeGbp(lat, lng);
        const addrEl = document.getElementById('gbpLocAddress');
        if (addrEl) addrEl.textContent = address || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;

        // مابنحفظش مع كل سحبة (سحبة غلط كانت بتتحفظ لوحدها) - الحفظ بزرار «حفظ الموقع»
        setGbpStatus('اضغط «حفظ الموقع»', 'orange');
    }

    async function saveGbpLocation(lat, lng, address) {
        const websiteId = P.getCurrentWebsiteId();
        if (!websiteId) { setGbpStatus('اختر موقعًا أولًا', 'gray'); return; }

        setGbpStatus('جارِ الحفظ...', 'orange');
        const res = await fetchJSON('/api/gbp/location', {
            method: 'POST', headers: jsonHeaders,
            body: JSON.stringify({ website_id: websiteId, latitude: lat, longitude: lng, formatted_address: address || '' }),
        });

        if (res.success) {
            setGbpStatus('✔ تم الحفظ', 'green');
        } else {
            setGbpStatus('✖ فشل الحفظ', 'red');
            P.toast(res.error || 'تعذر حفظ الموقع', 'error');
        }
    }

    async function loadGbpLocation() {
        const websiteId = P.getCurrentWebsiteId();
        if (!websiteId) return;

        initGbpMap();
        if (!gbpMap) return;
        setGbpStatus('جارِ التحميل...', 'gray');

        const res = await fetchJSON('/api/gbp/location?website_id=' + websiteId);
        const addrEl = document.getElementById('gbpLocAddress');

        if (res.success && res.data.location && res.data.location.latitude !== null) {
            const loc = res.data.location;
            const pos = { lat: loc.latitude, lng: loc.longitude };
            gbpMarker.setPosition(pos);
            gbpMap.setCenter(pos);
            gbpMap.setZoom(15);
            if (addrEl) addrEl.textContent = loc.formatted_address || `${loc.latitude.toFixed(5)}, ${loc.longitude.toFixed(5)}`;
            setGbpStatus('✔ محدد', 'green');
        } else if (!(await gbpLocationFromProfile(websiteId))) {
            gbpMarker.setPosition(GBP_DEFAULT_CENTER);
            gbpMap.setCenter(GBP_DEFAULT_CENTER);
            gbpMap.setZoom(6);
            if (addrEl) addrEl.textContent = 'مالقيناش عنوان في بروفايل جوجل - حدّد مكان نشاطك يدوي';
            setGbpStatus('غير محدد', 'gray');
            gbpShowEditor(true);
        }
    }

    // مفيش موقع محفوظ: ناخده من بروفايل Google Business المربوط (إحداثياته، وإلا عنوانه) ونحفظه
    async function gbpLocationFromProfile(websiteId) {
        const pr = await fetchJSON('/api/gbp/profile?website_id=' + websiteId);
        const p = pr && pr.success ? (pr.data.profile || {}) : {};
        const addr = p.address ? formatBusinessAddress(p.address) : '';
        const apply = (lat, lng, label) => {
            const pos = { lat: Number(lat), lng: Number(lng) };
            gbpMarker.setPosition(pos); gbpMap.setCenter(pos); gbpMap.setZoom(15);
            const addrEl = document.getElementById('gbpLocAddress'); if (addrEl) addrEl.textContent = label || addr || (pos.lat.toFixed(5) + ', ' + pos.lng.toFixed(5));
            saveGbpLocation(pos.lat, pos.lng, label || addr).then(() => setGbpStatus('✔ من بروفايل جوجل', 'green'));
        };
        if (p.latitude != null && p.longitude != null && isFinite(p.latitude) && isFinite(p.longitude)) { apply(p.latitude, p.longitude, addr); return true; }
        if (addr && gbpGeocoder) {
            return await new Promise(res => gbpGeocoder.geocode({ address: addr }, (results, status) => {
                if (status === 'OK' && results && results[0]) { const l = results[0].geometry.location; apply(l.lat(), l.lng(), results[0].formatted_address || addr); res(true); } else res(false);
            }));
        }
        return false;
    }

    function gbpShowEditor(on) {
        const ed = document.getElementById('gbpLocEditor'), b = document.getElementById('gbpLocEditBtn');
        if (ed) ed.hidden = !on;
        if (b) b.textContent = on ? 'إخفاء الخريطة' : '✏️ عدّل يدوي';
        if (on && gbpMap && gbpMarker) setTimeout(() => { google.maps.event.trigger(gbpMap, 'resize'); gbpMap.setCenter(gbpMarker.getPosition()); }, 50);
    }
    const gbpEditBtn = document.getElementById('gbpLocEditBtn');
    if (gbpEditBtn) gbpEditBtn.addEventListener('click', () => gbpShowEditor(document.getElementById('gbpLocEditor').hidden));

    function searchGbpAddress() {
        const input = document.getElementById('gbpLocSearch');
        const q = input.value.trim();
        if (!q || !gbpGeocoder) return;

        setGbpStatus('جارِ البحث...', 'orange');
        gbpGeocoder.geocode({ address: q }, (results, status) => {
            if (status === 'OK' && results && results[0]) {
                const loc = results[0].geometry.location;
                initGbpMap();
                gbpMarker.setPosition(loc);
                gbpMap.setCenter(loc);
                gbpMap.setZoom(16);
                document.getElementById('gbpLocAddress').textContent = results[0].formatted_address;
                clearTimeout(gbpSaveTimer);
                saveGbpLocation(loc.lat(), loc.lng(), results[0].formatted_address);
            } else {
                setGbpStatus('غير محدد', 'gray');
                P.toast('لم يتم العثور على العنوان', 'error');
            }
        });
    }

    function useMyLocationGbp() {
        if (!navigator.geolocation) { P.toast('المتصفح لا يدعم تحديد الموقع', 'error'); return; }
        setGbpStatus('جارِ التحديد...', 'orange');
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                initGbpMap();
                const latlng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                gbpMarker.setPosition(latlng);
                gbpMap.setCenter(latlng);
                gbpMap.setZoom(16);
                onGbpMarkerMoved();
            },
            () => { setGbpStatus('غير محدد', 'gray'); P.toast('تعذر الوصول لموقعك - تأكد من صلاحية الموقع', 'error'); },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    const gbpSearchBtn = document.getElementById('gbpLocSearchBtn');
    const gbpGeoBtn = document.getElementById('gbpLocGeoBtn');
    const gbpSaveBtn = document.getElementById('gbpLocSaveBtn');
    const gbpSearchInput = document.getElementById('gbpLocSearch');

    if (gbpSearchBtn) gbpSearchBtn.addEventListener('click', searchGbpAddress);
    if (gbpSearchInput) gbpSearchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); searchGbpAddress(); }
    });
    if (gbpGeoBtn) gbpGeoBtn.addEventListener('click', useMyLocationGbp);
    if (gbpSaveBtn) gbpSaveBtn.addEventListener('click', () => {
        if (!gbpMarker) return;
        const pos = gbpMarker.getPosition();
        clearTimeout(gbpSaveTimer);
        saveGbpLocation(pos.lat(), pos.lng(), document.getElementById('gbpLocAddress').textContent);
    });

    window.addEventListener('tourfecto:website-changed', loadGbpLocation);

    // ==================== Setup Wizard ====================
    async function loadSetupWizard() {
        const grid = document.getElementById('gbpSetupGrid');
        const res = await fetchJSON('/api/gbp/status');
        if (!res.success) { grid.innerHTML = `<div class="p-empty">${esc(res.error || 'تعذر فحص الإعداد')}</div>`; return; }

        const statusMeta = {
            connected: { label: 'متصل', cls: 'green', icon: '✅' },
            missing: { label: 'غير مضبوط', cls: 'red', icon: '⛔' },
            action_required: { label: 'يتطلب إجراء', cls: 'orange', icon: '⚠️' },
            error: { label: 'خطأ', cls: 'red', icon: '⛔' },
        };

        const items = Object.values(res.data.system || {});
        // المنصة جاهزة ومفيش حاجة تخص العميل هنا - نخفي الكارت
        document.getElementById('gbpSetupWizard').style.display = items.length ? '' : 'none';
        grid.innerHTML = items.map(item => {
            const meta = statusMeta[item.status] || statusMeta.missing;
            return `<div class="p-card no-pad" style="padding:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <strong style="font-size:13px;">${meta.icon} ${esc(item.label)}</strong>
                    <span class="pill ${meta.cls}">${meta.label}</span>
                </div>
                <div class="p-cell-muted" style="font-size:12px;">${esc(item.detail)}</div>
            </div>`;
        }).join('');

        window._gbpWebsitesState = res.data.websites || [];
        window._gbpConnectionsState = res.data.connections || [];
        renderConnectionCenter();
    }

    // ==================== Connection Center ====================
    function renderConnectionCenter() {
        const box = document.getElementById('gbpConnectionsList');
        const websites = window._gbpWebsitesState || [];
        if (!websites.length) {
            box.innerHTML = '<div class="p-empty">لا يوجد مواقع مضافة بعد - أضف موقعًا أولاً من صفحة "المواقع"</div>';
            return;
        }

        const connByWebsite = {};
        (window._gbpConnectionsState || []).forEach(c => { connByWebsite[c.website_id] = c; });

        box.innerHTML = websites.map(w => {
            const c = connByWebsite[w.website_id];
            const isConnected = !!c && c.status === 'connected';
            const statusPill = !c
                ? '<span class="pill gray">غير مربوط</span>'
                : c.status === 'connected'
                    ? (c.token_expired ? '<span class="pill orange">متصل - يحتاج تجديد</span>' : '<span class="pill green">متصل</span>')
                    : `<span class="pill red">${esc(c.status)}</span>`;

            const lastSync = c && c.last_synced_at ? P.timeAgo(c.last_synced_at) : 'لم تتم المزامنة بعد';

            return `<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 18px;border-top:1px solid var(--panel-border,rgba(255,255,255,.07));">
                <div>
                    <strong style="font-size:13px;">${esc(w.website_name)}</strong>
                    <div class="p-cell-muted" style="font-size:11.5px;">${statusPill} · آخر مزامنة: ${esc(lastSync)}${c && c.location_name ? ' · ' + esc(c.location_name) : ''}</div>
                    ${c && c.last_error ? `<div style="color:#e05656;font-size:11px;margin-top:2px;">${esc(c.last_error)}</div>` : ''}
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    ${!c ? `<button class="p-btn xs" onclick="connectGbp(${w.website_id})">ربط Google Business</button>` : ''}
                    ${isConnected ? `<button class="p-btn outline xs" onclick="syncGbp(${w.website_id})">🔄 مزامنة الآن</button>` : ''}
                    ${c ? `<button class="p-btn outline xs" onclick="connectGbp(${w.website_id})">إعادة الربط</button>` : ''}
                    ${c ? `<button class="p-btn outline xs" style="color:#e05656;" onclick="disconnectGbp(${w.website_id})">قطع الاتصال</button>` : ''}
                </div>
            </div>`;
        }).join('');
    }

    window.connectGbp = async function (websiteId) {
        // بيستخدم نفس OAuth flow الموجود فعلاً في وحدة إدارة السمعة - مفيش تكرار
        // (تصحيح: المسار الصحيح /reputation/connect/google/{website_id}، مش /api/reputation/google-business/connect)
        window.location.href = '/reputation/connect/google/' + websiteId;
    };

    window.addGbpLocation = async function () {
        const name = document.getElementById('gbpNewLocationName').value.trim();
        const url = document.getElementById('gbpNewLocationUrl').value.trim();
        if (!url) { P.toast('رابط الموقع مطلوب', 'error'); return; }

        const res = await fetchJSON('/api/websites', {
            method: 'POST', headers: jsonHeaders,
            body: JSON.stringify({ main_url: url, company_name: name || url }),
        });

        document.getElementById('gbpAddLocationModal').classList.remove('open');
        if (res.success) {
            P.toast('تمت إضافة الموقع - اربطه الآن بـ Google Business Profile', 'success');
            const newId = res.data && res.data.website && res.data.website.id;
            loadSetupWizard();
            loadWebsites();
            if (newId) P.setCurrentWebsiteId(String(newId));
        } else {
            P.toast(res.error || 'تعذرت إضافة الموقع', 'error');
        }
    };

    window.disconnectGbp = async function (websiteId) {
        if (!confirm('هيتم قطع اتصال Google Business Profile عن الموقع ده. متابعة؟')) return;
        // تصحيح: المسار الصحيح POST /api/reputation/disconnect/google/{website_id} (website_id في الـ URL مش الـ body)
        const res = await fetchJSON('/api/reputation/disconnect/google/' + websiteId, { method: 'POST', headers: jsonHeaders });
        if (res.success) { P.toast('تم قطع الاتصال', 'success'); loadSetupWizard(); }
        else P.toast(res.error || 'تعذر قطع الاتصال', 'error');
    };

    window.syncGbp = async function (websiteId) {
        P.toast('جارِ المزامنة...', 'info');
        const res = await fetchJSON('/api/gbp/sync/' + websiteId, { method: 'POST', headers: jsonHeaders });
        if (res.success) {
            P.toast('تمت المزامنة بنجاح' + (res.data.new_reviews ? ` (${res.data.new_reviews} مراجعة جديدة)` : ''), 'success');
            loadSetupWizard();
            if (String(websiteId) === P.getCurrentWebsiteId()) { loadGbpProfile(); loadGbpInsights(); }
        } else {
            P.toast(res.error || 'فشلت المزامنة', 'error');
        }
    };

    // ==================== Location Dashboard ====================
    async function loadGbpDashboard() {
        const websiteId = P.getCurrentWebsiteId();
        const box = document.getElementById('gbpDashboardBody');
        if (!websiteId) { box.innerHTML = '<div class="p-empty">اختر موقعًا من القائمة العلوية</div>'; return; }

        const res = await fetchJSON('/api/gbp/profile?website_id=' + websiteId);
        if (!res.success) { box.innerHTML = `<div class="p-empty">${esc(res.error)}</div>`; return; }

        const p = res.data.profile || {};
        const stars = p.google_rating ? '⭐'.repeat(Math.round(p.google_rating)) : '';
        const verifyPill = p.is_published === true
            ? '<span class="pill green">✔ موثّق ومنشور</span>'
            : p.is_published === false
                ? '<span class="pill orange">بانتظار التوثيق</span> <a href="https://business.google.com/" target="_blank" rel="noopener" style="font-size:11px;">أكمل التوثيق ←</a>'
                : '<span class="pill gray">غير معروف</span>';

        box.innerHTML = `
            <div class="p-grid cols-4" style="gap:12px;padding:0 18px 14px;">
                <div class="p-card no-pad" style="padding:12px;">
                    <div class="p-cell-muted" style="font-size:11px;">تقييم Google</div>
                    <div style="font-size:18px;font-weight:700;">${p.google_rating ?? '—'} ${stars}</div>
                </div>
                <div class="p-card no-pad" style="padding:12px;">
                    <div class="p-cell-muted" style="font-size:11px;">عدد المراجعات</div>
                    <div style="font-size:18px;font-weight:700;">${p.review_count ?? 0}</div>
                </div>
                <div class="p-card no-pad" style="padding:12px;">
                    <div class="p-cell-muted" style="font-size:11px;">حالة التوثيق</div>
                    <div style="margin-top:4px;">${verifyPill}</div>
                </div>
                <div class="p-card no-pad" style="padding:12px;">
                    <div class="p-cell-muted" style="font-size:11px;">التصنيف الأساسي</div>
                    <div style="font-size:13px;font-weight:600;">${esc(p.primary_category || 'غير محدد')}</div>
                </div>
            </div>
            <div style="padding:0 18px 14px;font-size:13px;line-height:1.9;">
                <div><strong>الاسم:</strong> ${esc(p.name || '-')}</div>
                <div><strong>الهاتف:</strong> ${esc(p.phone || 'غير متاح')}</div>
                <div><strong>الموقع الإلكتروني:</strong> ${p.website ? `<a href="${esc(p.website)}" target="_blank" rel="noopener">${esc(p.website)}</a>` : 'غير متاح'}</div>
                <div><strong>العنوان:</strong> ${p.address ? esc(formatBusinessAddress(p.address)) : 'غير متاح'}</div>
            </div>
            <div style="padding:0 18px 18px;display:flex;gap:8px;flex-wrap:wrap;">
                ${p.maps_uri ? `<a class="p-btn outline xs" href="${esc(p.maps_uri)}" target="_blank" rel="noopener">📍 فتح على Google Maps</a>` : ''}
                ${p.new_review_uri ? `<a class="p-btn outline xs" href="${esc(p.new_review_uri)}" target="_blank" rel="noopener">🔗 فتح على Google Business</a>` : ''}
            </div>`;
    }

    // ==================== Profile Management ====================
    let gbpProfileDirty = false, gbpProfileOriginal = {};

    async function loadGbpProfile() {
        const websiteId = P.getCurrentWebsiteId();
        const box = document.getElementById('gbpProfileBody');
        if (!websiteId) { box.innerHTML = '<div class="p-empty">اختر موقعًا من القائمة العلوية</div>'; return; }

        const res = await fetchJSON('/api/gbp/profile?website_id=' + websiteId);
        if (!res.success) { box.innerHTML = `<div class="p-empty">${esc(res.error)}</div>`; return; }

        const p = res.data.profile || {};
        const c = res.data.completeness || { score: 0, missing: [] };
        gbpProfileOriginal = { description: p.description || '', phone: p.phone || '', website: p.website || '' };
        gbpProfileDirty = false;
        document.getElementById('gbpProfileUnsaved').style.display = 'none';

        box.innerHTML = `
            <div style="padding:0 18px 14px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                    <div style="flex:1;background:rgba(255,255,255,.08);border-radius:8px;height:8px;overflow:hidden;">
                        <div style="width:${c.score}%;height:100%;background:linear-gradient(90deg,#4CAF7D,#8BD9AE);"></div>
                    </div>
                    <strong style="font-size:13px;">اكتمال البروفايل: ${c.score}%</strong>
                </div>
                <label class="p-cell-muted">الاسم</label>
                <input class="p-select" style="width:100%;margin-bottom:10px;" value="${esc(p.name || '')}" disabled>
                <label class="p-cell-muted">الوصف</label>
                <textarea id="gbpProfDesc" class="p-select" rows="3" style="width:100%;margin-bottom:10px;">${esc(p.description || '')}</textarea>
                <label class="p-cell-muted">الهاتف</label>
                <input id="gbpProfPhone" class="p-select" style="width:100%;margin-bottom:10px;" value="${esc(p.phone || '')}">
                <label class="p-cell-muted">الموقع الإلكتروني</label>
                <input id="gbpProfWebsite" class="p-select" style="width:100%;margin-bottom:10px;" value="${esc(p.website || '')}">
                <label class="p-cell-muted">ساعات العمل</label>
                <div id="gbpHoursEditor" style="margin-bottom:10px;font-size:12.5px;"></div>
                <label class="p-cell-muted">الخصائص (Attributes)</label>
                <div id="gbpAttributesEditor" style="margin-bottom:10px;font-size:12.5px;"><div class="p-cell-muted">جارِ التحميل...</div></div>
                <div class="p-cell-muted" style="font-size:11.5px;margin-bottom:10px;">التصنيف: ${esc(p.primary_category || 'غير محدد')} · العنوان: ${p.address ? '✔ موجود' : 'غير متاح'}</div>
                <div style="display:flex;gap:8px;">
                    <button class="p-btn xs" onclick="saveGbpProfile()">💾 حفظ التغييرات</button>
                    <button class="p-btn outline xs" onclick="gbpReloadProfile()">إلغاء</button>
                </div>
            </div>`;

        renderGbpHoursEditor(p.regular_hours || []);
        loadGbpAttributes();

        ['gbpProfDesc', 'gbpProfPhone', 'gbpProfWebsite'].forEach(id => {
            document.getElementById(id).addEventListener('input', () => {
                gbpProfileDirty = true;
                document.getElementById('gbpProfileUnsaved').style.display = 'inline';
            });
        });
    }

    const GBP_DAYS = [
        { key: 'SATURDAY', label: 'السبت' }, { key: 'SUNDAY', label: 'الأحد' }, { key: 'MONDAY', label: 'الإثنين' },
        { key: 'TUESDAY', label: 'الثلاثاء' }, { key: 'WEDNESDAY', label: 'الأربعاء' }, { key: 'THURSDAY', label: 'الخميس' }, { key: 'FRIDAY', label: 'الجمعة' },
    ];

    function renderGbpHoursEditor(periods) {
        const byDay = {};
        (periods || []).forEach(p => { if (p && p.openDay) byDay[p.openDay] = p; });

        const box = document.getElementById('gbpHoursEditor');
        box.innerHTML = GBP_DAYS.map(d => {
            const period = byDay[d.key];
            const isOpen = !!period;
            const openTime = period ? `${String(period.openTime?.hours ?? 9).padStart(2, '0')}:${String(period.openTime?.minutes ?? 0).padStart(2, '0')}` : '09:00';
            const closeTime = period ? `${String(period.closeTime?.hours ?? 17).padStart(2, '0')}:${String(period.closeTime?.minutes ?? 0).padStart(2, '0')}` : '17:00';
            return `<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-top:1px solid var(--panel-border,rgba(255,255,255,.06));">
                <label style="width:70px;display:flex;align-items:center;gap:4px;">
                    <input type="checkbox" class="gbp-day-toggle" data-day="${d.key}" ${isOpen ? 'checked' : ''}> ${d.label}
                </label>
                <input type="time" class="gbp-day-open p-select" data-day="${d.key}" value="${openTime}" style="width:110px;" ${isOpen ? '' : 'disabled'}>
                <span>—</span>
                <input type="time" class="gbp-day-close p-select" data-day="${d.key}" value="${closeTime}" style="width:110px;" ${isOpen ? '' : 'disabled'}>
            </div>`;
        }).join('');

        box.querySelectorAll('.gbp-day-toggle').forEach(cb => {
            cb.addEventListener('change', () => {
                const day = cb.dataset.day;
                box.querySelector(`.gbp-day-open[data-day="${day}"]`).disabled = !cb.checked;
                box.querySelector(`.gbp-day-close[data-day="${day}"]`).disabled = !cb.checked;
                gbpProfileDirty = true;
                document.getElementById('gbpProfileUnsaved').style.display = 'inline';
            });
        });
        box.querySelectorAll('input[type="time"]').forEach(inp => {
            inp.addEventListener('change', () => {
                gbpProfileDirty = true;
                document.getElementById('gbpProfileUnsaved').style.display = 'inline';
            });
        });
    }

    function collectGbpHours() {
        const box = document.getElementById('gbpHoursEditor');
        const periods = [];
        box.querySelectorAll('.gbp-day-toggle:checked').forEach(cb => {
            const day = cb.dataset.day;
            const openVal = box.querySelector(`.gbp-day-open[data-day="${day}"]`).value || '09:00';
            const closeVal = box.querySelector(`.gbp-day-close[data-day="${day}"]`).value || '17:00';
            const [oh, om] = openVal.split(':').map(Number);
            const [ch, cm] = closeVal.split(':').map(Number);
            periods.push({
                openDay: day, closeDay: day,
                openTime: { hours: oh, minutes: om },
                closeTime: { hours: ch, minutes: cm },
            });
        });
        return periods;
    }

    // ==================== Attributes ====================
    // Round 7 (2026-08-14): بقت تدعم BOOL (checkbox) + REPEATED_ENUM
    // (checkboxes متعددة لكل خيار حقيقي من Google) + URL (input نص) -
    // مش BOOL بس زي الأول. كل الخيارات جايّة فعليًا من attributes.list.
    async function loadGbpAttributes() {
        const websiteId = P.getCurrentWebsiteId();
        const box = document.getElementById('gbpAttributesEditor');
        if (!websiteId) return;

        const res = await fetchJSON('/api/gbp/attributes?website_id=' + websiteId);
        if (!res.success) { box.innerHTML = `<div class="p-cell-muted">${esc(res.error)}</div>`; return; }

        const attrs = res.data.attributes || [];
        if (!attrs.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد خصائص متاحة لهذا التصنيف من النشاط</div>'; return; }

        box.innerHTML = attrs.map(a => {
            if (a.value_type === 'BOOL') {
                return `<label style="display:inline-flex;align-items:center;gap:5px;margin:2px 10px 2px 0;">
                    <input type="checkbox" class="gbp-attr-bool" data-id="${esc(a.attribute_id)}" ${a.current_value ? 'checked' : ''}> ${esc(a.label)}
                </label>`;
            }
            if (a.value_type === 'REPEATED_ENUM') {
                const setValues = (a.current_value && a.current_value.set) || [];
                const optionsHtml = (a.options || []).map(o => `
                    <label style="display:inline-flex;align-items:center;gap:4px;margin:2px 8px 2px 0;font-size:11.5px;">
                        <input type="checkbox" class="gbp-attr-enum-opt" data-parent="${esc(a.attribute_id)}" data-value="${esc(o.value)}" ${setValues.includes(o.value) ? 'checked' : ''}> ${esc(o.display_name)}
                    </label>`).join('');
                return `<div style="margin:6px 0;"><strong style="font-size:12px;">${esc(a.label)}</strong><div>${optionsHtml}</div></div>`;
            }
            if (a.value_type === 'URL') {
                const currentUrl = (a.current_value && a.current_value[0]) || '';
                return `<div style="margin:6px 0;">
                    <label style="font-size:12px;">${esc(a.label)}</label>
                    <input type="text" class="p-select gbp-attr-url" data-id="${esc(a.attribute_id)}" value="${esc(currentUrl)}" placeholder="https://..." style="width:100%;max-width:320px;">
                </div>`;
            }
            return ''; // نوع مش معروف - نتجاهله بدل ما نعرض حاجة غلط
        }).join('') +
            '<div style="margin-top:8px;"><button class="p-btn outline xs" onclick="saveGbpAttributes()">💾 حفظ الخصائص</button></div>';

        box.querySelectorAll('.gbp-attr-bool, .gbp-attr-enum-opt, .gbp-attr-url').forEach(el => {
            el.addEventListener('change', markGbpProfileDirty);
            el.addEventListener('input', markGbpProfileDirty);
        });
    }

    function markGbpProfileDirty() {
        gbpProfileDirty = true;
        document.getElementById('gbpProfileUnsaved').style.display = 'inline';
    }

    window.saveGbpAttributes = async function () {
        const websiteId = P.getCurrentWebsiteId();
        const changes = {};

        document.querySelectorAll('.gbp-attr-bool').forEach(cb => {
            changes[cb.dataset.id] = { type: 'BOOL', value: cb.checked };
        });

        const enumGroups = {};
        document.querySelectorAll('.gbp-attr-enum-opt').forEach(cb => {
            const parent = cb.dataset.parent;
            if (!enumGroups[parent]) enumGroups[parent] = { set: [], unset: [] };
            (cb.checked ? enumGroups[parent].set : enumGroups[parent].unset).push(cb.dataset.value);
        });
        Object.keys(enumGroups).forEach(id => { changes[id] = { type: 'REPEATED_ENUM', ...enumGroups[id] }; });

        document.querySelectorAll('.gbp-attr-url').forEach(inp => {
            const val = inp.value.trim();
            if (val) changes[inp.dataset.id] = { type: 'URL', values: [val] };
        });

        if (!Object.keys(changes).length) return;

        const res = await fetchJSON('/api/gbp/attributes', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ website_id: websiteId, changes }) });
        if (res.success) { P.toast('تم تحديث الخصائص', 'success'); loadGbpAttributes(); }
        else P.toast(res.error || 'فشل تحديث الخصائص', 'error');
    };

    window.saveGbpProfile = async function () {
        const websiteId = P.getCurrentWebsiteId();
        const body = {
            website_id: websiteId,
            description: document.getElementById('gbpProfDesc').value.trim(),
            phone: document.getElementById('gbpProfPhone').value.trim(),
            website: document.getElementById('gbpProfWebsite').value.trim(),
            regular_hours: collectGbpHours(),
        };
        const res = await fetchJSON('/api/gbp/profile', { method: 'POST', headers: jsonHeaders, body: JSON.stringify(body) });
        if (res.success) { P.toast('تم الحفظ بنجاح', 'success'); loadGbpProfile(); }
        else P.toast(res.error || 'فشل الحفظ', 'error');
    };

    // ==================== Photos ====================
    let gbpPhotosPage = 1;

    async function loadGbpPhotos(append) {
        const websiteId = P.getCurrentWebsiteId();
        const grid = document.getElementById('gbpPhotosGrid');
        if (!websiteId) { grid.innerHTML = '<div class="p-empty">اختر موقعًا من القائمة العلوية</div>'; return; }

        if (!append) gbpPhotosPage = 1;

        const res = await fetchJSON('/api/gbp/photos?website_id=' + websiteId + '&page=' + gbpPhotosPage + '&limit=24');
        if (!res.success) { grid.innerHTML = `<div class="p-empty">${esc(res.error)}</div>`; return; }

        const photos = res.data.photos || [];
        if (!append && !photos.length) { grid.innerHTML = '<div class="p-empty">لا يوجد صور بعد</div>'; return; }

        const cardsHtml = photos.map(ph => `
            <div style="position:relative;border-radius:8px;overflow:hidden;background:rgba(255,255,255,.05);${ph.is_primary == 1 ? 'outline:2px solid #4CAF7D;' : ''}">
                ${ph.status === 'uploading'
                    ? `<div class="gbp-skel" style="height:100px;display:flex;align-items:center;justify-content:center;font-size:11px;">جارِ الرفع على Google...</div>`
                    : `<img src="${esc(ph.thumbnail_url || ph.source_url || '')}" style="width:100%;height:100px;object-fit:cover;display:block;" loading="lazy">`}
                <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.55);padding:3px 6px;font-size:10px;color:#fff;display:flex;justify-content:space-between;">
                    <span>${esc(ph.category)}</span>
                    ${ph.status === 'ready' && ph.is_primary != 1 ? `<span style="cursor:pointer;" onclick="setPrimaryGbpPhoto(${ph.id})" title="اجعلها رئيسية">☆</span>` : ''}
                    ${ph.is_primary == 1 ? '<span>★ رئيسية</span>' : ''}
                </div>
                ${ph.status === 'failed' ? `<div style="position:absolute;top:4px;left:4px;background:#e05656;color:#fff;font-size:9.5px;padding:2px 6px;border-radius:4px;" title="${esc(ph.error_message || '')}">فشل الرفع</div>` : ''}
                <button onclick="deleteGbpPhoto(${ph.id})" title="حذف" style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);border:none;color:#fff;border-radius:5px;width:22px;height:22px;cursor:pointer;">×</button>
            </div>`).join('');

        if (append) {
            const loadMoreBtn = document.getElementById('gbpPhotosLoadMore');
            if (loadMoreBtn) loadMoreBtn.remove();
            grid.insertAdjacentHTML('beforeend', cardsHtml);
        } else {
            grid.innerHTML = cardsHtml;
        }

        if (res.data.pagination && res.data.pagination.has_more) {
            grid.insertAdjacentHTML('afterend', '<div id="gbpPhotosLoadMoreWrap" style="padding:0 18px 10px;"><button id="gbpPhotosLoadMore" class="p-btn outline xs" onclick="loadMoreGbpPhotos()">تحميل المزيد</button></div>');
        } else {
            const wrap = document.getElementById('gbpPhotosLoadMoreWrap');
            if (wrap) wrap.remove();
        }
    }

    window.loadMoreGbpPhotos = function () {
        gbpPhotosPage++;
        loadGbpPhotos(true);
    };

    document.getElementById('gbpPhotoInput').addEventListener('change', async function (e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        const websiteId = P.getCurrentWebsiteId();
        if (!websiteId) { P.toast('اختر موقعًا أولاً', 'error'); return; }

        const fd = new FormData();
        fd.append('photo', file);
        fd.append('website_id', websiteId);
        fd.append('category', document.getElementById('gbpPhotoCategory').value);

        P.toast('جارِ رفع الصورة...', 'info');
        try {
            const r = await fetch('/api/gbp/photos', { method: 'POST', body: fd });
            const res = await r.json();
            if (res.success) {
                P.toast('الصورة بترفع على Google في الخلفية...', 'success');
                loadGbpPhotos();
                // الرفع بقى Async - نعمل تحديث خفيف بعد شوية عشان نلحق نعرض
                // حالة "ready"/"failed" النهائية بدل ما تفضل "uploading" على الشاشة
                setTimeout(loadGbpPhotos, 6000);
                setTimeout(loadGbpPhotos, 15000);
            } else {
                P.toast(res.error || 'فشل الرفع', 'error');
            }
        } catch (err) { P.toast('تعذر رفع الصورة', 'error'); }
    });

    // صور الاستوديو الإبداعي → Google Business (من غير تنزيل ورفع يدوي)
    document.getElementById('gbpFromLibraryBtn').addEventListener('click', async function () {
        const box = document.getElementById('gbpLibraryPicker');
        const grid = document.getElementById('gbpLibraryGrid');
        if (box.style.display === 'block') { box.style.display = 'none'; return; }
        box.style.display = 'block';
        grid.innerHTML = '<div class="p-cell-muted">جارِ التحميل...</div>';
        const res = await fetchJSON('/api/creative-studio/media?filter=images');
        const items = res.success && res.data && Array.isArray(res.data.items) ? res.data.items.filter(m => m.status === 'completed' && m.thumbnail_path) : [];
        if (!items.length) { grid.innerHTML = '<div class="p-cell-muted">مفيش صور جاهزة في الاستوديو لسه. <a href="/creative-studio">افتح الاستوديو</a></div>'; return; }
        grid.innerHTML = items.map(m => '<button type="button" class="p-card no-pad" data-library-id="' + Number(m.id) + '" style="padding:0;border:0;cursor:pointer;overflow:hidden;aspect-ratio:1"><img src="' + P.esc(m.thumbnail_path) + '" alt="' + P.esc((m.prompt || '').slice(0, 120)) + '" loading="lazy" style="width:100%;height:100%;object-fit:cover"></button>').join('');
    });
    document.getElementById('gbpLibraryGrid').addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-library-id]');
        if (!btn) return;
        const websiteId = P.getCurrentWebsiteId();
        if (!websiteId) { P.toast('اختر موقعًا أولاً', 'error'); return; }
        const fd = new FormData();
        fd.append('media_item_id', btn.dataset.libraryId);
        fd.append('website_id', websiteId);
        fd.append('category', document.getElementById('gbpPhotoCategory').value);
        btn.disabled = true;
        try {
            const res = await (await fetch('/api/gbp/photos', { method: 'POST', body: fd })).json();
            if (res.success) {
                P.toast('الصورة بترفع على Google في الخلفية...', 'success');
                document.getElementById('gbpLibraryPicker').style.display = 'none';
                loadGbpPhotos(); setTimeout(loadGbpPhotos, 6000); setTimeout(loadGbpPhotos, 15000);
            } else { P.toast(res.error || 'فشل الرفع', 'error'); }
        } catch (err) { P.toast('تعذر رفع الصورة', 'error'); }
        btn.disabled = false;
    });

    window.deleteGbpPhoto = async function (photoId) {
        const websiteId = P.getCurrentWebsiteId();
        if (!confirm('حذف هذه الصورة من Google Business Profile؟')) return;
        const res = await fetchJSON('/api/gbp/photos/' + photoId + '?website_id=' + websiteId, { method: 'DELETE' });
        if (res.success) { P.toast('تم الحذف', 'success'); loadGbpPhotos(); }
        else P.toast(res.error || 'فشل الحذف', 'error');
    };

    window.setPrimaryGbpPhoto = async function (photoId) {
        const websiteId = P.getCurrentWebsiteId();
        const res = await fetchJSON('/api/gbp/photos/' + photoId + '/primary?website_id=' + websiteId, { method: 'POST', headers: jsonHeaders });
        if (res.success) { P.toast('تم التحديد كصورة رئيسية (في لوحة Tourfecto)', 'success'); loadGbpPhotos(); }
        else P.toast(res.error || 'فشل التحديد', 'error');
    };

    // ==================== Insights & Analytics ====================
    let gbpChart = null, gbpCurrentDays = 30, gbpCustomRange = null;

    async function loadGbpInsights(days) {
        gbpCurrentDays = days || gbpCurrentDays;
        const websiteId = P.getCurrentWebsiteId();
        const kpiBox = document.getElementById('gbpInsightsKpis');
        const emptyBox = document.getElementById('gbpInsightsEmpty');
        const canvas = document.getElementById('gbpInsightsChart');
        if (!websiteId) { kpiBox.innerHTML = ''; emptyBox.style.display = 'block'; emptyBox.textContent = 'اختر موقعًا من القائمة العلوية'; canvas.style.display = 'none'; return; }

        let url = '/api/gbp/insights?website_id=' + websiteId;
        if (gbpCustomRange) url += '&date_from=' + gbpCustomRange.from + '&date_to=' + gbpCustomRange.to;
        else url += '&days=' + gbpCurrentDays;

        const res = await fetchJSON(url);
        if (!res.success) {
            kpiBox.innerHTML = '';
            canvas.style.display = 'none';
            emptyBox.style.display = 'block';
            emptyBox.textContent = res.error || 'Not enough data / Not available from Google API';
            return;
        }

        canvas.style.display = 'block';
        emptyBox.style.display = 'none';

        const t = res.data.totals || {};
        const change = (res.data.previous_period && res.data.previous_period.change_percent) || {};
        const kpis = [
            { key: 'views', label: 'المشاهدات' },
            { key: 'searches', label: 'مرات الظهور بالبحث' },
            { key: 'website_clicks', label: 'نقرات الموقع' },
            { key: 'phone_calls', label: 'مكالمات الهاتف' },
        ];
        kpiBox.innerHTML = kpis.map(k => {
            const chg = change[k.key];
            const chgHtml = (chg !== undefined) ? `<span style="font-size:11px;color:${chg >= 0 ? '#4CAF7D' : '#e05656'};">${chg >= 0 ? '▲' : '▼'} ${Math.abs(chg)}%</span>` : '';
            return `<div class="p-card no-pad" style="padding:12px;">
                <div class="p-cell-muted" style="font-size:11px;">${k.label}</div>
                <div style="font-size:20px;font-weight:700;">${t[k.key] ?? 0}</div>
                ${chgHtml}
            </div>`;
        }).join('');

        renderGbpChart(res.data.metrics || {});
    }

    function renderGbpChart(metrics) {
        const ctx = document.getElementById('gbpInsightsChart');
        if (!ctx || typeof Chart === 'undefined') return;
        if (gbpChart) gbpChart.destroy();

        const labelMap = { WEBSITE_CLICKS: 'نقرات الموقع', CALL_CLICKS: 'مكالمات', BUSINESS_DIRECTION_REQUESTS: 'طلبات الاتجاهات', BUSINESS_CONVERSATIONS: 'محادثات' };
        const colorMap = { WEBSITE_CLICKS: '#5B9BD5', CALL_CLICKS: '#4CAF7D', BUSINESS_DIRECTION_REQUESTS: '#EFB05E', BUSINESS_CONVERSATIONS: '#9A8CF5' };
        const anyKey = Object.keys(metrics)[0];
        const labels = anyKey ? metrics[anyKey].map(p => p.date) : [];

        const datasets = Object.keys(labelMap)
            .filter(k => metrics[k])
            .map(k => ({ label: labelMap[k], data: metrics[k].map(p => p.value), borderColor: colorMap[k], backgroundColor: 'transparent', tension: 0.3 }));

        gbpChart = new Chart(ctx, { type: 'line', data: { labels, datasets }, options: { responsive: true, plugins: { legend: { display: true } } } });
    }

    document.querySelectorAll('.gbp-range-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.gbp-range-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            gbpCustomRange = null;
            document.getElementById('gbpCustomFrom').value = '';
            document.getElementById('gbpCustomTo').value = '';
            loadGbpInsights(parseInt(btn.dataset.days, 10));
        });
    });

    window.applyGbpCustomRange = function () {
        const from = document.getElementById('gbpCustomFrom').value;
        const to = document.getElementById('gbpCustomTo').value;
        if (!from || !to) { P.toast('اختر تاريخ البداية والنهاية', 'error'); return; }
        document.querySelectorAll('.gbp-range-btn').forEach(b => b.classList.remove('active'));
        gbpCustomRange = { from, to };
        loadGbpInsights();
    };

    // ==================== AI Insights & Recommendations ====================
    async function loadGbpAiInsights() {
        const websiteId = P.getCurrentWebsiteId();
        const summaryBox = document.getElementById('gbpAiSummary');
        const listBox = document.getElementById('gbpAiInsightsList');
        if (!websiteId) { listBox.innerHTML = '<div class="p-empty">اختر موقعًا</div>'; return; }

        const res = await fetchJSON('/api/gbp/ai-insights?website_id=' + websiteId);
        if (!res.success) { listBox.innerHTML = `<div class="p-empty">${esc(res.error)}</div>`; return; }

        summaryBox.textContent = res.data.ai_summary || '';
        const items = res.data.insights || [];
        listBox.innerHTML = items.map(i => `
            <div style="padding:8px 0;border-top:1px solid var(--panel-border,rgba(255,255,255,.07));">
                <div style="font-size:12.5px;">${esc(i.insight_text || i.evidence)}</div>
                <div class="p-cell-muted" style="font-size:11px;">🎯 ${esc(i.recommended_action || '')} · ثقة: ${esc(i.confidence)}</div>
            </div>`).join('') || '<div class="p-empty">لا توجد بيانات كافية للتحليل بعد</div>';
    }

    async function loadGbpRecommendations() {
        const websiteId = P.getCurrentWebsiteId();
        const box = document.getElementById('gbpRecommendationsList');
        if (!websiteId) { box.innerHTML = '<div class="p-empty">اختر موقعًا</div>'; return; }

        const res = await fetchJSON('/api/gbp/recommendations?website_id=' + websiteId);
        if (!res.success) { box.innerHTML = `<div class="p-empty">${esc(res.error)}</div>`; return; }

        const items = res.data.recommendations || [];
        const priorityLabel = { high: 'عالية', medium: 'متوسطة', low: 'منخفضة' };
        box.innerHTML = items.map(r => `
            <div style="padding:8px 0;border-top:1px solid var(--panel-border,rgba(255,255,255,.07));">
                <strong style="font-size:12.5px;">${esc(r.title)}</strong>
                <span class="pill ${r.priority === 'high' ? 'orange' : 'gray'}" style="font-size:9.5px;">${priorityLabel[r.priority] || r.priority}</span>
                <div class="p-cell-muted" style="font-size:11.5px;">${esc(r.reason)}</div>
                ${r.link ? `<a href="${esc(r.link)}" class="p-btn outline xs" style="margin-top:6px;">فتح</a>` : ''}
            </div>`).join('') || '<div class="p-empty">لا يوجد توصيات حاليًا</div>';
    }

    function loadGbpModuleForCurrentWebsite() {
        loadGbpDashboard();
        loadGbpProfile();
        loadGbpPhotos();
        loadGbpInsights(gbpCurrentDays);
        loadGbpAiInsights();
        loadGbpRecommendations();
    }
    window.addEventListener('tourfecto:website-changed', loadGbpModuleForCurrentWebsite);

    window.gbpReloadDashboard = loadGbpDashboard;
    window.gbpReloadProfile = loadGbpProfile;
    window.gbpReloadContent = load;
    loadSetupWizard();
    loadGbpModuleForCurrentWebsite();
    loadWebsites();
    load();
    loadGbpLocation();
})();
JS;

        // الأقسام الكتير بقت 4 تبويبات (المكان، البيانات والصور، البوستات، الأداء)
        $body .= '<script defer src="' . htmlspecialchars(asset_v('/assets/js/gbp-tabs.js'), ENT_QUOTES, 'UTF-8') . '"></script>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('gbp_content', 'محتوى Google Business Profile', 'وليّد وجدول منشورات GBP بالذكاء الاصطناعي', $body, $script);
        exit;
    }

    /** GET /api/gbp/content */
    public function listContent(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $items = (new GbpContent())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC'], 30);

        // Round 7 (2026-08-14 - Production Finalization): كانت دي N+1
        // query حقيقية (استعلام منفصل لكل منشور في الـ loop) - بند
        // "Performance" بالسبيك بيطلب صراحة نتجنبها. بدّلناها باستعلام
        // واحد بـ IN(...) وبنجمّع أحدث جدولة لكل منشور في PHP بدل SQL
        // (window functions زي ROW_NUMBER() مش مضمون توفرها في كل نسخ
        // MySQL المستخدمة، فده الحل الأكثر توافقًا).
        $contentIds = array_map(fn ($i) => (int) $i->getAttribute('id'), $items);
        $latestScheduleByContentId = [];

        if (!empty($contentIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
                $rows = Database::getInstance()->query(
                    "SELECT * FROM gbp_scheduled_posts WHERE gbp_content_id IN ({$placeholders}) ORDER BY id DESC",
                    $contentIds
                );
                foreach ($rows as $row) {
                    $cid = (int) $row['gbp_content_id'];
                    if (!isset($latestScheduleByContentId[$cid])) {
                        $latestScheduleByContentId[$cid] = $row; // أول ظهور = الأحدث (بسبب ORDER BY id DESC)
                    }
                }
            } catch (Throwable $e) {
                Logger::error('GBP listContent: تعذر جلب الجدولة', ['error' => CustomerError::message($e->getMessage(), 500)]);
            }
        }

        $data = array_map(function ($item) use ($latestScheduleByContentId) {
            $arr = $item->toArray();
            $arr['latest_schedule'] = $latestScheduleByContentId[(int) $arr['id']] ?? null;
            return $arr;
        }, $items);

        return $this->success(['items' => $data]);
    }

    /** POST /api/gbp/content */
    public function generate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'prompt' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        if (!$this->canAccessWebsite((int) $this->get('website_id'))) {
            return $this->error('الموقع غير موجود', 404);
        }

        $type = (string) $this->get('type', 'update');
        if (!in_array($type, ['update', 'offer', 'event', 'product'], true)) {
            return $this->error('نوع منشور مش مدعوم', 422);
        }
        $details = $this->postDetailsInput();
        try {
            // نتأكد من بيانات العرض/الفعالية قبل ما نصرف على التوليد
            $this->service->applyDetails(new GbpContent(['type' => $type]), $details);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }

        try {
            $content = $this->service->generate(
                (int) $this->user['id'],
                (int) $this->get('website_id'),
                $type,
                (string) $this->get('prompt')
            );
            try {
                $this->service->applyDetails($content, $details);
                $content->save();
            } catch (Throwable $e) {
                Logger::warning('gbp details not saved', ['error' => CustomerError::message($e->getMessage(), 500)]);
            }
            return $this->success(['content' => $content->toArray()], 'تم التوليد', 201);
        } catch (Exception $e) {
            Logger::error('Gbp generate Error', ['message' => $e->getMessage()]);
            return $this->error(CustomerError::message($e->getMessage(), 500), 502);
        }
    }

    /** الحقول الإضافية للمنشور اللي اتبعتت فعلاً في الطلب بس */
    private function postDetailsInput(): array
    {
        $out = [];
        foreach (['post_title', 'event_start', 'event_end', 'coupon_code', 'offer_terms', 'cta_type', 'cta_url'] as $k) {
            if ($this->has($k)) {
                $out[$k] = (string) $this->get($k);
            }
        }
        return $out;
    }

    /** GET /api/gbp/location?website_id= — بيانات لوكيشن النشاط الحالية على الخريطة */
    public function getLocation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        $lat = $website->getAttribute('latitude');
        $lng = $website->getAttribute('longitude');

        return $this->success([
            'location' => [
                'website_id' => $websiteId,
                'latitude' => $lat !== null ? (float) $lat : null,
                'longitude' => $lng !== null ? (float) $lng : null,
                'formatted_address' => $website->getAttribute('formatted_address'),
                'updated_at' => $website->getAttribute('location_updated_at'),
                'company_name' => $website->getAttribute('company_name'),
            ],
        ]);
    }

    /** POST /api/gbp/location — حفظ/تحديث لوكيشن النشاط لحظيًا من الخريطة */
    public function saveLocation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'latitude' => 'required', 'longitude' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        $websiteId = (int) $this->get('website_id');
        $lat = (float) $this->get('latitude');
        $lng = (float) $this->get('longitude');

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return $this->error('إحداثيات غير صحيحة', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        $website->setAttribute('latitude', $lat);
        $website->setAttribute('longitude', $lng);
        $website->setAttribute('formatted_address', (string) $this->get('formatted_address', ''));
        $website->setAttribute('location_updated_at', date('Y-m-d H:i:s'));
        $website->save();

        return $this->success([
            'location' => [
                'website_id' => $websiteId,
                'latitude' => $lat,
                'longitude' => $lng,
                'formatted_address' => $website->getAttribute('formatted_address'),
                'updated_at' => $website->getAttribute('location_updated_at'),
            ],
        ], 'تم تحديث موقع النشاط بنجاح');
    }

    /** POST /api/gbp/content/{id}/schedule */
    public function schedule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['platform_connection_id' => 'required', 'scheduled_at' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        if ($deny = $this->canUseContentAndConnection((int) ($params['id'] ?? 0), (int) $this->get('platform_connection_id'))) {
            return $this->error($deny, 404);
        }

        try {
            $scheduled = $this->service->schedule(
                (int) ($params['id'] ?? 0),
                (int) $this->get('platform_connection_id'),
                (string) $this->get('scheduled_at')
            );
            return $this->success(['scheduled' => $scheduled->toArray()], 'تمت الجدولة', 201);
        } catch (Exception $e) {
            Logger::error('Gbp schedule Error', ['message' => $e->getMessage()]);
            return $this->error(CustomerError::message($e->getMessage(), 500), 500);
        }
    }

    /**
     * PUT /api/gbp/content/{id} - تعديل نص منشور قبل ما يتنشر
     * @since 2026-08-11 (GBP Module Upgrade - Round 6: Posts Edit/Delete)
     */
    public function updateContent(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['generated_text' => 'required'])) {
            return $this->error('نص المنشور مطلوب', 422);
        }

        try {
            $details = $this->postDetailsInput();
            $content = $this->service->editContent(
                (int) ($params['id'] ?? 0),
                (int) $this->user['id'],
                (string) $this->get('generated_text'),
                $details ?: null
            );
            return $this->success(['content' => $content->toArray()], 'تم تعديل المنشور بنجاح');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /**
     * DELETE /api/gbp/content/{id} - حذف مسودة منشور لسه مجدولة/منشورش
     * @since 2026-08-11 (GBP Module Upgrade - Round 6: Posts Edit/Delete)
     */
    public function deleteContent(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $this->service->deleteContent((int) ($params['id'] ?? 0), (int) $this->user['id']);
            return $this->success([], 'تم حذف المنشور');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /**
     * POST /api/gbp/content/{id}/schedule/{scheduleId}/cancel - إلغاء جدولة قبل النشر
     * @since 2026-08-11 (GBP Module Upgrade - Round 6: Posts Edit/Delete)
     */
    public function cancelSchedule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $scheduled = $this->service->cancelScheduled((int) ($params['scheduleId'] ?? 0), (int) $this->user['id']);
            return $this->success(['scheduled' => $scheduled->toArray()], 'تم إلغاء الجدولة');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** 🔒 الموقع ده بتاع المستخدم الحالي (أو صاحب الحساب لو هو عضو في فريقه)؟ */
    private function canAccessWebsite(int $websiteId): bool
    {
        if ($websiteId <= 0 || empty($this->user['id'])) {
            return false;
        }
        $ids = [(int) $this->user['id']];
        if (!empty($this->user['owner_user_id'])) {
            $ids[] = (int) $this->user['owner_user_id'];
        }
        $rows = $this->db->query(
            'SELECT id FROM websites WHERE id = ? AND user_id IN (' . implode(',', $ids) . ') LIMIT 1',
            [$websiteId]
        );
        return !empty($rows);
    }

    /** 🔒 المحتوى واتصال Google بتوع المستخدم الحالي؟ */
    private function canUseContentAndConnection(int $contentId, int $connectionId): ?string
    {
        $c = $this->db->query('SELECT website_id, user_id FROM gbp_content WHERE id = ? LIMIT 1', [$contentId]);
        if (!$c || !$this->canAccessWebsite((int) $c[0]['website_id'])) {
            return 'المنشور غير موجود';
        }
        $pc = $this->db->query("SELECT website_id, user_id FROM platform_connections WHERE id = ? AND platform = 'google_business' LIMIT 1", [$connectionId]);
        if (!$pc || !$this->canAccessWebsite((int) $pc[0]['website_id'])) {
            return 'اتصال Google Business غير موجود';
        }
        if ((int) $pc[0]['website_id'] !== (int) $c[0]['website_id']) {
            return 'المنشور والاتصال لمواقع مختلفة';
        }
        return null;
    }
}
