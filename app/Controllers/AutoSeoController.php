<?php

/**
 * Tourfecto - Auto SEO Controller
 * ربط المواقع الخارجية بالمنصة + تشغيل التنفيذ التلقائي (Auto-Pilot) عليها
 * + سجل التغييرات والـRollback + تقديم embed.js العام.
 *
 * بيكمّل على WebsiteOptimizerController: الأخير بيعمل التدقيق ويطلّع
 * wo_audit_findings + wo_fixes، وده بياخد النتايج دي وينفّذها فعليًا.
 *
 * @version 1.0.0
 */
class AutoSeoController extends Controller
{
    /** @var AutoSeoEmbedService */
    private $embed;

    /** @var SubscriptionValidator */
    private $subscription;

    public function __construct()
    {
        parent::__construct();
        $this->embed = new AutoSeoEmbedService($this->db);
        $this->subscription = new SubscriptionValidator();
    }

    /**
     * GET /auto-seo (صفحة لوحة التنفيذ التلقائي)
     * ثلاث تبويبات: الربط والتنفيذ + الفهرسة الفورية (IndexNow) + تجارب SEO A/B.
     */
    public function index(array $params = []): void
    {
        $proxyHost = (defined('SEO_PROXY_HOST') && SEO_PROXY_HOST) ? SEO_PROXY_HOST : ($_SERVER['HTTP_HOST'] ?? '');
        $proxyHostEsc = htmlspecialchars((string) $proxyHost, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
        <style>
        .aseo-tabs { display: flex; gap: 6px; flex-wrap: wrap; padding: 14px 20px 0; }
        .aseo-tab { border: 1px solid var(--panel-border); background: transparent; color: var(--panel-text-muted); font-size: 12.5px; font-weight: 700; padding: 6px 13px; border-radius: 999px; cursor: pointer; transition: .12s; }
        .aseo-tab:hover { border-color: var(--panel-accent); color: var(--panel-text); }
        .aseo-tab.active { background: var(--panel-accent); border-color: var(--panel-accent); color: #1a1206; }
        .aseo-panel { display: none; }
        .aseo-panel.active { display: block; }
        .aseo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .aseo-code { position: relative; margin-top: 10px; }
        .aseo-code pre { background: #060A13; border: 1px solid var(--panel-border); border-radius: 8px; padding: 12px 14px; font-family: 'JetBrains Mono', monospace; font-size: 11.5px; line-height: 1.7; overflow-x: auto; direction: ltr; text-align: left; color: #C9D4E4; margin: 0; max-height: 220px; }
        .aseo-copy { position: absolute; top: 8px; inset-inline-end: 8px; }
        .aseo-kv { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--panel-border); font-size: 12.5px; }
        .aseo-kv:last-child { border-bottom: none; }
        .aseo-kv .k { color: var(--panel-text-muted); font-weight: 700; }
        .aseo-kv .v { font-family: 'JetBrains Mono', monospace; direction: ltr; text-align: left; word-break: break-all; }
        .aseo-pill { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
        .aseo-pill.green { background: var(--panel-accent-light); color: var(--panel-accent); }
        .aseo-pill.gray { background: rgba(255,255,255,.08); color: var(--panel-text-muted); }
        .aseo-pill.orange { background: rgba(239,176,94,.18); color: var(--panel-warning); }
        .aseo-test { background: var(--panel-card-bg-2); border: 1px solid var(--panel-border); border-radius: var(--panel-radius-sm); padding: 14px 16px; margin-bottom: 12px; }
        .aseo-test-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .aseo-test-name { font-weight: 800; font-size: 13.5px; }
        .aseo-variant { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px dashed var(--panel-border); font-size: 12px; }
        .aseo-variant:last-child { border-bottom: none; }
        @media (max-width: 720px) { .aseo-grid { grid-template-columns: 1fr; } }

        /* -- لوحة النتائج المبسّطة -- */
        .aseo-summary { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; padding: 18px 20px; }
        .aseo-score-ring { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-direction: column; border: 6px solid var(--panel-border); flex-shrink: 0; }
        .aseo-score-ring .n { font-size: 22px; font-weight: 900; line-height: 1; }
        .aseo-score-ring .l { font-size: 9px; color: var(--panel-text-muted); font-weight: 700; }
        .aseo-score-ring.good { border-color: #4CAF7D; }
        .aseo-score-ring.mid { border-color: var(--panel-warning); }
        .aseo-score-ring.bad { border-color: #E05B5B; }
        .aseo-status-pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; }
        .aseo-status-pill.on { background: var(--panel-accent-light); color: var(--panel-accent); }
        .aseo-status-pill.off { background: rgba(255,255,255,.08); color: var(--panel-text-muted); }
        .aseo-issues { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
        .aseo-issues li { font-size: 12.5px; display: flex; gap: 8px; align-items: flex-start; }
        .aseo-issues .dot { width: 7px; height: 7px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
        .aseo-issues .dot.critical, .aseo-issues .dot.high { background: #E05B5B; }
        .aseo-issues .dot.medium { background: var(--panel-warning); }
        .aseo-issues .dot.low { background: var(--panel-text-muted); }
        .aseo-steps { display: flex; flex-direction: column; gap: 14px; }
        .aseo-step { display: flex; gap: 12px; }
        .aseo-step-num { width: 26px; height: 26px; border-radius: 50%; background: var(--panel-accent-light); color: var(--panel-accent); font-weight: 800; font-size: 12.5px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .aseo-mode-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px; }
        .aseo-mode-card { border: 2px solid var(--panel-border); border-radius: var(--panel-radius-sm); padding: 10px; cursor: pointer; text-align: center; transition: .12s; }
        .aseo-mode-card:hover { border-color: var(--panel-accent); }
        .aseo-mode-card.selected { border-color: var(--panel-accent); background: var(--panel-accent-light); }
        .aseo-mode-card .emoji { font-size: 18px; }
        .aseo-mode-card .name { font-size: 12px; font-weight: 800; margin-top: 2px; }
        .aseo-mode-card .badge { font-size: 9.5px; color: var(--panel-accent); font-weight: 700; }
        @media (max-width: 560px) { .aseo-mode-cards { grid-template-columns: 1fr; } }
        details.aseo-advanced { margin-top: 14px; }
        details.aseo-advanced > summary { cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--panel-text-muted); padding: 10px 0; list-style: none; }
        details.aseo-advanced > summary::-webkit-details-marker { display: none; }
        details.aseo-advanced > summary::before { content: '⚙️ '; }
        details.aseo-advanced[open] > summary { color: var(--panel-text); }
        .aseo-report-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px}
        .aseo-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .aseo-metric{padding:18px;border:1px solid var(--panel-border);border-radius:14px;background:var(--panel-card-bg-2);min-width:0}
        .aseo-metric small{display:block;color:var(--panel-text-muted);font-size:12px;margin-bottom:10px}
        .aseo-metric strong{display:block;font-size:28px;font-weight:800;color:var(--panel-text)}
        .aseo-report-section{margin:18px 0 10px;font-size:13px;font-weight:700;color:var(--panel-accent)}
        @media(max-width:650px){.aseo-report-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.aseo-metric{padding:14px}.aseo-metric strong{font-size:24px}}
        </style>

        <div class="p-toolbar">
            <select id="aseoWebsiteSelect" class="p-select"><option value="">اختر موقعًا</option></select>
            <span class="p-cell-muted" id="aseoConnStatus" style="font-size:12px;"></span>
        </div>

        <!-- ملخص الأداء المبسّط -->
        <div class="p-card no-pad" style="margin-top:14px;" id="aseoSummaryCard">
            <div class="aseo-summary" id="aseoSummaryBody">
                <span class="p-cell-muted" style="font-size:12.5px;">اختر موقعًا لعرض ملخص الأداء.</span>
            </div>
        </div>

        <div class="p-card" style="margin-top:14px;" id="aseoReportCard">
            <div class="aseo-report-head">
                <div><h3 style="margin:0 0 6px;">تقرير الأداء</h3><div class="p-cell-muted" style="font-size:12px;">زيارات موقعك ونتائج البحث في مكان واحد</div></div>
                <button class="p-btn primary" onclick="aseoReport()">توليد التقرير</button>
            </div>
            <div id="aseoReportResult"><div class="p-cell-muted">اختار موقعك واضغط توليد التقرير لعرض البيانات.</div></div>
        </div>

        <div class="p-card no-pad" style="margin-top:14px;">
            <div class="aseo-tabs" id="aseoTabs">
                <button class="aseo-tab active" data-tab="connect">الربط والتنفيذ</button>
                <button class="aseo-tab" data-tab="indexnow">الفهرسة الفورية</button>
                <button class="aseo-tab" data-tab="ab">اختبار الأفكار (A/B)</button>
            </div>

            <!-- تبويب الربط والتنفيذ -->
            <div class="aseo-panel active" data-panel="connect" style="padding:16px 20px 20px;">
                <div class="p-card" style="margin:0;">
                    <div class="aseo-steps">
                        <div class="aseo-step">
                            <div class="aseo-step-num">1</div>
                            <div style="flex:1;">
                                <h3 style="margin:0 0 4px;">اربط موقعك</h3>
                                <p class="p-cell-muted" style="font-size:12.5px;margin:0 0 8px;">اختار نوع موقعك، وهنجهّزلك كود جاهز تنسخه وتلصقه - مفيش تعديل يدوي في موقعك مطلوب.</p>
                                <div class="p-toolbar" style="padding:0;border:none;">
                                    <select id="aseoMethod" class="p-select" style="width:auto;">
                                        <option value="wordpress">WordPress</option>
                                        <option value="shopify">Shopify</option>
                                        <option value="script">موقع HTML عادي</option>
                                        <option value="api">API (للمطورين)</option>
                                    </select>
                                    <button class="p-btn primary" id="aseoConnectBtn" onclick="aseoConnect()">ربط الموقع</button>
                                </div>
                                <div id="aseoConnectResult" style="margin-top:12px;"></div>
                            </div>
                        </div>

                        <div class="aseo-step">
                            <div class="aseo-step-num">2</div>
                            <div style="flex:1;">
                                <h3 style="margin:0 0 4px;">اختار سرعة التحسين</h3>
                                <p class="p-cell-muted" style="font-size:12.5px;margin:0 0 8px;">إمتى ننفّذ التحسينات المكتشفة تلقائيًا على موقعك؟ تقدر تغيّرها في أي وقت.</p>
                                <input type="hidden" id="aseoMode" value="balanced">
                                <div class="aseo-mode-cards" id="aseoModeCards">
                                    <div class="aseo-mode-card" data-mode="conservative">
                                        <div class="emoji">🐢</div>
                                        <div class="name">آمن وبطيء</div>
                                    </div>
                                    <div class="aseo-mode-card selected" data-mode="balanced">
                                        <div class="emoji">⚖️</div>
                                        <div class="name">متوازن</div>
                                        <div class="badge">موصى به</div>
                                    </div>
                                    <div class="aseo-mode-card" data-mode="aggressive">
                                        <div class="emoji">🚀</div>
                                        <div class="name">سريع وجريء</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="aseo-step">
                            <div class="aseo-step-num">3</div>
                            <div style="flex:1;">
                                <h3 style="margin:0 0 4px;">ابدأ التحسين التلقائي</h3>
                                <p class="p-cell-muted" style="font-size:12.5px;margin:0 0 8px;">هيطبّق التحسينات المكتشفة فورًا على موقعك، وهيبلّغ محركات البحث في نفس اللحظة.</p>
                                <button class="p-btn success" id="aseoApplyBtn" onclick="aseoApply()">ابدأ التحسين التلقائي الآن</button>
                                <span id="aseoApplyResult" style="font-size:12px;margin-inline-start:10px;"></span>
                            </div>
                        </div>
                    </div>

                    <details class="aseo-advanced">
                        <summary>إعدادات متقدمة (للمطورين)</summary>

                        <div class="p-card" style="margin-top:10px;">
                            <h3 style="margin-top:0;font-size:13px;">ربط CNAME (دومينك الخاص)</h3>
                            <p class="p-cell-muted" style="font-size:12px;">عشان التنفيذ يشتغل server-side على دومينك الحقيقي (مش مجرد embed.js)، أشّر DNS بتاعك ناحية سيرفرنا:</p>
                            <div class="aseo-kv"><span class="k">نوع السجل</span><span class="v">CNAME</span></div>
                            <div class="aseo-kv"><span class="k">الاسم (Host)</span><span class="v">www</span></div>
                            <div class="aseo-kv"><span class="k">القيمة (Points to)</span><span class="v" id="aseoCnameTarget">{$proxyHostEsc}</span></div>
                            <p class="p-cell-muted" style="font-size:11.5px;">للاختبار من غير تغيير DNS استخدم رابط المعاينة <span dir="ltr">/s/{embed_token}</span>.</p>
                        </div>

                        <div class="p-card" style="margin-top:10px;">
                            <h3 style="margin-top:0;font-size:13px;">معاينة قبل التطبيق</h3>
                            <p class="p-cell-muted" style="font-size:12px;">شوف الفرق في العنوان والوصف قبل ما يتطبّق فعليًا (من غير أي كتابة على الموقع).</p>
                            <div class="p-toolbar" style="padding:0;border:none;flex-wrap:wrap;">
                                <input type="text" id="aseoPreviewTitle" class="p-select" style="flex:1;min-width:160px;" placeholder="عنوان مقترح">
                                <input type="text" id="aseoPreviewDesc" class="p-select" style="flex:1;min-width:160px;" placeholder="وصف مقترح">
                                <button class="p-btn primary" onclick="aseoPreview()">معاينة</button>
                            </div>
                            <div id="aseoPreviewResult" style="margin-top:12px;"></div>
                        </div>

                        <div class="p-card" style="margin-top:10px;">
                            <h3 style="margin-top:0;font-size:13px;">سجل التغييرات</h3>
                            <div id="aseoLogs" class="p-cell-muted" style="font-size:12px;">لم يُسجّل أي تغيير بعد.</div>
                        </div>
                    </details>
                </div>
            </div>

            <!-- تبويب الفهرسة الفورية -->
            <div class="aseo-panel" data-panel="indexnow" style="padding:16px 20px 20px;">
                <div class="p-card" style="margin:0;">
                    <h3 style="margin-top:0;">IndexNow</h3>
                    <p class="p-cell-muted" style="font-size:12.5px;">إبلاغ Bing/Yandex/Seznam/Naver بصفحات موقعك فورًا بعد أي تعديل - بدل انتظار الزحف الطبيعي أسابيع.</p>
                    <div class="p-toolbar" style="padding:0;border:none;">
                        <button class="p-btn primary" id="aseoGenKeyBtn" onclick="aseoGenerateKey()">توليد مفتاح</button>
                        <button class="p-btn outline" id="aseoToggleBtn" onclick="aseoToggleIndexNow()">تفعيل/إيقاف</button>
                    </div>
                    <div id="aseoIndexNowStatus" style="margin-top:12px;"></div>
                </div>

                <div class="p-card" style="margin-top:14px;">
                    <h3 style="margin-top:0;">إرسال روابط يدوي</h3>
                    <div class="p-toolbar" style="padding:0;border:none;">
                        <input type="text" id="aseoUrlsInput" class="p-select" style="flex:1;width:auto;" placeholder="https://example.com/page1, https://example.com/page2">
                        <button class="p-btn success" onclick="aseoSubmitUrls()">إرسال للفهرسة</button>
                    </div>
                    <span id="aseoSubmitResult" style="font-size:12px;"></span>
                </div>
            </div>

            <!-- تبويب تجارب SEO A/B -->
            <div class="aseo-panel" data-panel="ab" style="padding:16px 20px 20px;">
                <div class="p-card" style="margin:0;">
                    <h3 style="margin-top:0;">تجربة جديدة</h3>
                    <div class="p-toolbar" style="padding:0;border:none;flex-wrap:wrap;">
                        <input type="text" id="aseoTestName" class="p-select" style="flex:1;min-width:140px;" placeholder="اسم التجربة">
                        <select id="aseoTestField" class="p-select" style="width:auto;">
                            <option value="seo_title">العنوان (Title)</option>
                            <option value="seo_description">الوصف (Meta Description)</option>
                            <option value="canonical_url">Canonical</option>
                            <option value="json_ld">JSON-LD</option>
                            <option value="faq_schema">FAQ Schema</option>
                            <option value="speakable">Speakable</option>
                        </select>
                        <input type="text" id="aseoTestPath" class="p-select" style="width:auto;" placeholder="مسار (اختياري)">
                        <button class="p-btn primary" onclick="aseoCreateTest()">إنشاء</button>
                    </div>
                </div>
                <div id="aseoTestsList" style="margin-top:14px;" class="p-cell-muted">مفيش تجارب بعد.</div>
            </div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentWebsiteId = null;

    // تبديل التبويبات
    document.querySelectorAll('.aseo-tab').forEach(btn => {
        btn.onclick = () => {
            document.querySelectorAll('.aseo-tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.aseo-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.querySelector('[data-panel="' + btn.dataset.tab + '"]').classList.add('active');
            if (btn.dataset.tab === 'indexnow') loadIndexNow();
            if (btn.dataset.tab === 'ab') loadTests();
            if (btn.dataset.tab === 'connect') loadLogs();
        };
    });

    async function loadWebsites() {
        const res = await fetchJSON('/api/website-optimizer/websites');
        const sel = document.getElementById('aseoWebsiteSelect');
        if (res.success && res.data.websites.length) {
            sel.innerHTML = '<option value="">اختر موقعًا</option>' + res.data.websites.map(w => `<option value="${w.id}">${esc(w.main_url)}</option>`).join('');
        } else {
            sel.innerHTML = '<option value="">لا توجد مواقع</option>';
        }
        sel.onchange = () => { currentWebsiteId = sel.value ? parseInt(sel.value, 10) : null; document.getElementById('aseoReportResult').textContent = 'اضغط توليد التقرير لعرض بيانات الموقع المحدد.'; refreshAll(); };
    }

    function refreshAll() {
        if (!currentWebsiteId) return;
        loadIndexNow(); loadTests(); loadLogs(); loadSummary();
        document.getElementById('aseoConnStatus').textContent = 'موقع محدد: #' + currentWebsiteId;
    }

    // ---------- ملخص الأداء المبسّط ----------
    function scoreClass(score) {
        if (score === null || score === undefined) return 'mid';
        return score >= 80 ? 'good' : (score >= 50 ? 'mid' : 'bad');
    }

    const MODE_LABELS = { off: 'متوقف', conservative: 'آمن وبطيء', balanced: 'متوازن', aggressive: 'سريع وجريء' };

    async function loadSummary() {
        if (!currentWebsiteId) return;
        const box = document.getElementById('aseoSummaryBody');
        const res = await fetchJSON('/api/auto-seo/report?website_id=' + currentWebsiteId);
        if (!res.success) { box.innerHTML = '<span class="p-cell-muted" style="font-size:12.5px;">تعذّر تحميل الملخص.</span>'; return; }
        const d = res.data;
        const score = (d.audits && d.audits.length) ? d.audits[0].overall_score : null;
        const site = d.website || {};
        const connected = !!(site && site.is_connected == 1);
        const mode = site.auto_pilot_mode || 'off';

        const issuesHtml = (d.top_issues && d.top_issues.length)
            ? d.top_issues.map(i => `<li><span class="dot ${esc(i.severity)}"></span><span>${esc(i.title)}</span></li>`).join('')
            : `<li><span class="dot low"></span><span>${connected ? 'مفيش مشاكل مفتوحة حاليًا - تمام 👍' : 'اربط موقعك وشغّل تدقيق عشان نعرضلك أهم الحاجات اللي محتاجة تتصلح.'}</span></li>`;

        box.innerHTML = `
            <div class="aseo-score-ring ${scoreClass(score)}">
                <span class="n">${score !== null ? score : '-'}</span>
                <span class="l">من 100</span>
            </div>
            <div style="flex:1;min-width:200px;">
                <span class="aseo-status-pill ${connected ? 'on' : 'off'}">${connected ? '● التحسين شغال (' + esc(MODE_LABELS[mode] || mode) + ')' : '○ الموقع مش مربوط لسه'}</span>
                <ul class="aseo-issues">${issuesHtml}</ul>
            </div>`;

        // مزامنة كروت السرعة مع الوضع المحفوظ فعليًا للموقع
        if (mode && mode !== 'off') {
            document.getElementById('aseoMode').value = mode;
            document.querySelectorAll('.aseo-mode-card').forEach(c => c.classList.toggle('selected', c.dataset.mode === mode));
        }
    }

    window.aseoConnect = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const method = document.getElementById('aseoMethod').value;
        const res = await fetchJSON('/api/auto-seo/connect', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, method })
        });
        if (!res.success) { toast(res.error || 'فشل الربط', 'error'); return; }
        document.getElementById('aseoConnectResult').innerHTML = `
            <p class="p-cell-muted" style="font-size:12px;">انسخ الكود ده والصقه قبل <code dir="ltr">&lt;/head&gt;</code> في موقعك (أو استخدم إضافة "Insert Headers" لو WordPress):</p>
            <div class="aseo-code">
                <button class="p-btn xs outline aseo-copy" onclick="navigator.clipboard.writeText(document.getElementById('aseoEmbedCode').textContent).then(()=>toast('تم النسخ','success'))">نسخ</button>
                <pre><code id="aseoEmbedCode">${esc(res.data.embed_code)}</code></pre>
            </div>
            <details class="aseo-advanced" style="margin-top:8px;">
                <summary>بيانات الربط التقنية</summary>
                <div class="aseo-kv"><span class="k">توكن الـ Embed</span><span class="v">${esc(res.data.embed_token)}</span></div>
                <div class="aseo-kv"><span class="k">مفتاح API</span><span class="v">${esc(res.data.api_key)}</span></div>
            </details>`;
        toast('تم ربط الموقع بنجاح', 'success');
        // نفعّل الوضع الموصى به (متوازن) تلقائيًا عشان العميل ميحتاجش خطوة إضافية
        await aseoSetMode(true);
        loadLogs();
        loadSummary();
    };

    window.aseoSetMode = async function (silent) {
        if (!currentWebsiteId) { if (!silent) toast('اختر موقعًا أولاً', 'error'); return; }
        const mode = document.getElementById('aseoMode').value;
        const res = await fetchJSON('/api/auto-seo/mode', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, mode })
        });
        if (!res.success) { if (!silent) toast(res.error || 'فشل حفظ الوضع', 'error'); return; }
        if (!silent) toast('تم تحديث سرعة التحسين', 'success');
        loadSummary();
    };

    // اختيار سرعة التحسين بالكروت بدل select تقني - يحفظ فورًا
    document.querySelectorAll('.aseo-mode-card').forEach(card => {
        card.onclick = () => {
            document.querySelectorAll('.aseo-mode-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            document.getElementById('aseoMode').value = card.dataset.mode;
            aseoSetMode();
        };
    });

    window.aseoApply = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        if (!confirm('سيتم تطبيق الإصلاحات المكتشفة على موقعك مباشرة (العنوان والوصف وغيرهما). متابعة؟')) return;
        const btn = document.getElementById('aseoApplyBtn'); btn.disabled = true;
        btn.textContent = 'جارِ التطبيق...';
        try {
            const res = await fetchJSON('/api/auto-seo/apply', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ website_id: currentWebsiteId })
            });
            if (!res.success) { toast(res.error || 'فشل التطبيق', 'error'); return; }
            let msg = res.data.applied_count + ' إصلاحات اتطبقت';
            if (res.data.indexnow && res.data.indexnow.success) msg += ' + تم إبلاغ محركات البحث (IndexNow)';
            document.getElementById('aseoApplyResult').textContent = msg;
            toast(msg, 'success');
            loadLogs();
        } finally {
            btn.disabled = false;
            btn.textContent = 'تطبيق الإصلاحات الآن';
        }
    };

    async function loadLogs() {
        if (!currentWebsiteId) return;
        const res = await fetchJSON('/api/auto-seo/logs?website_id=' + currentWebsiteId);
        const box = document.getElementById('aseoLogs');
        if (!res.success || !res.data.logs.length) { box.innerHTML = 'لم يُسجّل أي تغيير بعد.'; return; }
        box.innerHTML = res.data.logs.map(l => `
            <div class="aseo-kv">
                <span class="k">${esc(l.field_name)} ${esc(l.trigger || '')}</span>
                <span class="v">${esc(l.new_value || '').slice(0, 80)}</span>
            </div>`).join('');
    }

    // ---------- معاينة قبل التطبيق + تقرير قبل/بعد ----------
    window.aseoPreview = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const title = document.getElementById('aseoPreviewTitle').value.trim();
        const desc = document.getElementById('aseoPreviewDesc').value.trim();
        const changes = {};
        if (title) changes.seo_title = title;
        if (desc) changes.seo_description = desc;
        if (!Object.keys(changes).length) { toast('اكتب عنوانًا أو وصفًا مقترحًا', 'error'); return; }
        const box = document.getElementById('aseoPreviewResult');
        box.innerHTML = '<span class="p-cell-muted" style="font-size:12px;">جاري تجهيز المعاينة...</span>';
        const res = await fetchJSON('/api/auto-seo/preview', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, changes })
        });
        if (!res.success) { box.innerHTML = '<span style="color:var(--panel-danger);font-size:12px;">' + esc(res.error || 'فشل المعاينة') + '</span>'; return; }
        const d = res.data;
        const row = (label, before, after) => `
            <div class="aseo-kv">
                <span class="k">${label}</span>
                <span class="v">قبل: ${esc(before || '-')}<br>بعد: ${esc(after || '-')}</span>
            </div>`;
        box.innerHTML = row('العنوان', d.before.title, d.after.title) + row('الوصف', d.before.description, d.after.description);
        toast('جاهزة المعاينة', 'success');
    };

    window.aseoReport = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const box = document.getElementById('aseoReportResult');
        box.innerHTML = '<span class="p-cell-muted" style="font-size:12px;">جاري توليد التقرير...</span>';
        const res = await fetchJSON('/api/auto-seo/report?website_id=' + currentWebsiteId);
        if (!res.success) { box.innerHTML = '<span style="color:var(--panel-danger);font-size:12px;">' + esc(res.error || 'فشل التقرير') + '</span>'; return; }
        const d = res.data;
        const gsc = d.gsc || {};
        const lastAudit = (d.audits && d.audits.length) ? d.audits[0] : null;
        const metric = (label, value) => `<div class="aseo-metric"><small>${esc(label)}</small><strong>${esc(String(value ?? '—'))}</strong></div>`;
        let ga4 = '<div class="p-cell-muted">اربط Google Analytics من صفحة التكاملات لعرض زيارات موقعك.</div>';
        if (d.ga4 && !d.ga4.error) {
            ga4 = '<div class="aseo-report-grid">' + metric('الجلسات', d.ga4.sessions) + metric('المستخدمون', d.ga4.total_users) + metric('التحويلات', d.ga4.conversions) + '</div>';
        } else if (d.ga4 && d.ga4.error) {
            ga4 = '<div class="p-cell-muted">تعذر تحميل بيانات Analytics: ' + esc(String(d.ga4.error)) + '</div>';
        }
        box.innerHTML = '<div class="aseo-report-section">Google Analytics · آخر 28 يوم</div>' + ga4
            + '<div class="aseo-report-section">Google Search Console</div><div class="aseo-report-grid">'
            + metric('النقرات', gsc.clicks ?? 0) + metric('الظهور', gsc.impressions ?? 0) + metric('نسبة النقر CTR', String(gsc.ctr ?? 0) + '%') + '</div>'
            + '<div class="aseo-report-section">تحسين الموقع</div><div class="aseo-report-grid">'
            + metric('درجة التدقيق / 100', lastAudit ? lastAudit.overall_score : null) + metric('الإصلاحات النشطة', d.active_fixes) + metric('اللقطات المسجلة', (d.history || []).length) + '</div>';
        toast('تم توليد التقرير', 'success');
    };

    // ---------- IndexNow ----------
    window.aseoGenerateKey = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const res = await fetchJSON('/api/indexnow/generate-key', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId })
        });
        if (!res.success) { toast(res.error || 'فشل توليد المفتاح', 'error'); return; }
        toast('تم توليد مفتاح IndexNow', 'success');
        loadIndexNow();
    };

    window.aseoToggleIndexNow = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const cur = await fetchJSON('/api/indexnow/status?website_id=' + currentWebsiteId);
        const enabled = cur.success && cur.data.indexnow_enabled ? 0 : 1;
        await fetchJSON('/api/indexnow/toggle', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, enabled })
        });
        loadIndexNow();
    };

    async function loadIndexNow() {
        if (!currentWebsiteId) return;
        const res = await fetchJSON('/api/indexnow/status?website_id=' + currentWebsiteId);
        const box = document.getElementById('aseoIndexNowStatus');
        if (!res.success) { box.innerHTML = ''; return; }
        const d = res.data;
        box.innerHTML = `
            <div class="aseo-kv"><span class="k">الحالة</span><span class="aseo-pill ${d.indexnow_enabled ? 'green' : 'gray'}">${d.indexnow_enabled ? 'مفعّل' : 'متوقف'}</span></div>
            <div class="aseo-kv"><span class="k">المفتاح</span><span class="v">${d.has_key ? 'موجود' : 'غير مُولّد بعد'}</span></div>`;
    }

    window.aseoSubmitUrls = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const raw = document.getElementById('aseoUrlsInput').value;
        const urls = raw.split(',').map(s => s.trim()).filter(Boolean);
        if (!urls.length) { toast('اكتب رابطًا واحدًا على الأقل', 'error'); return; }
        const res = await fetchJSON('/api/indexnow/submit', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, urls })
        });
        if (!res.success) { toast(res.error || 'فشل الإرسال', 'error'); return; }
        document.getElementById('aseoSubmitResult').textContent = 'قُبل إرسال ' + res.data.submitted + ' رابط، واستُبعد ' + (res.data.skipped || []).length + ' رابط غير متحقق منه (HTTP ' + res.data.status + ')';
        toast('قُبل الإشعار؛ الإرسال لا يضمن الفهرسة', 'success');
    };

    // ---------- SEO A/B ----------
    window.aseoCreateTest = async function () {
        if (!currentWebsiteId) { toast('اختر موقعًا أولاً', 'error'); return; }
        const name = document.getElementById('aseoTestName').value.trim();
        const targetField = document.getElementById('aseoTestField').value;
        const targetPath = document.getElementById('aseoTestPath').value.trim();
        if (!name) { toast('اكتب اسم التجربة', 'error'); return; }
        const res = await fetchJSON('/api/seo-ab-tests', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, name, target_field: targetField, target_path: targetPath || null })
        });
        if (!res.success) { toast(res.error || 'فشل الإنشاء', 'error'); return; }
        document.getElementById('aseoTestName').value = '';
        document.getElementById('aseoTestPath').value = '';
        toast('تم إنشاء التجربة - أضف النسخ الآن', 'success');
        loadTests();
    };

    async function loadTests() {
        if (!currentWebsiteId) return;
        const res = await fetchJSON('/api/seo-ab-tests?website_id=' + currentWebsiteId);
        const box = document.getElementById('aseoTestsList');
        if (!res.success || !res.data.tests.length) { box.innerHTML = '<div class="p-cell-muted">مفيش تجارب بعد.</div>'; return; }
        box.innerHTML = res.data.tests.map(t => {
            const variants = (t.variants || []).map(v => `
                <div class="aseo-variant">
                    <span class="aseo-pill ${v.is_control == 1 ? 'gray' : 'orange'}">${esc(v.name)}</span>
                    <span class="p-cell-muted" style="flex:1;">${esc(v.value).slice(0, 90)}</span>
                    <span class="p-cell-muted">${v.served_count} عرض</span>
                </div>`).join('');
            const actions = (t.status === 'running' || t.status === 'completed')
                ? `<button class="p-btn xs outline" onclick="aseoMeasureResults(${t.id})">قياس النتائج (GSC)</button>` + (t.status === 'running'
                    ? `<button class="p-btn xs success" onclick="aseoCompleteTest(${t.id})">إنهاء</button>`
                    : `<span class="aseo-pill green">انتهت</span>`)
                : (t.status === 'draft'
                    ? `<button class="p-btn xs primary" onclick="aseoStartTest(${t.id})">بدء</button>`
                    : `<span class="aseo-pill green">انتهت</span>`);
            return `
                <div class="aseo-test" data-test-id="${t.id}">
                    <div class="aseo-test-head">
                        <div>
                            <span class="aseo-test-name">${esc(t.name)}</span>
                            <span class="p-cell-muted" style="font-size:11px;"> · ${esc(t.target_field)}</span>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <span class="aseo-pill ${t.status === 'running' ? 'green' : (t.status === 'completed' ? 'gray' : 'orange')}">${esc(t.status)}</span>
                            ${actions}
                        </div>
                    </div>
                    <div style="margin-top:8px;">${variants}</div>
                    <div id="aseoResults${t.id}" style="margin-top:10px;"></div>
                    ${t.status === 'draft' ? `
                    <div class="p-toolbar" style="padding:8px 0 0;border:none;flex-wrap:wrap;">
                        <input type="text" id="aseoVName${t.id}" class="p-select" style="width:auto;" placeholder="اسم النسخة">
                        <input type="text" id="aseoVValue${t.id}" class="p-select" style="flex:1;min-width:160px;" placeholder="القيمة">
                        <label style="font-size:11px;color:var(--panel-text-muted);"><input type="checkbox" id="aseoVControl${t.id}"> نسخة أصلية</label>
                        <button class="p-btn xs primary" onclick="aseoAddVariant(${t.id})">إضافة نسخة</button>
                    </div>` : ''}
                </div>`;
        }).join('');
    }

    window.aseoAddVariant = async function (testId) {
        const name = document.getElementById('aseoVName' + testId).value.trim();
        const value = document.getElementById('aseoVValue' + testId).value.trim();
        const isControl = document.getElementById('aseoVControl' + testId).checked;
        if (!name || !value) { toast('اكتب اسم النسخة وقيمتها', 'error'); return; }
        const res = await fetchJSON('/api/seo-ab-tests/' + testId + '/variants', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, value, is_control: isControl, weight: 50 })
        });
        if (!res.success) { toast(res.error || 'فشل الإضافة', 'error'); return; }
        loadTests();
    };

    window.aseoStartTest = async function (testId) {
        const res = await fetchJSON('/api/seo-ab-tests/' + testId + '/start', { method: 'POST' });
        if (!res.success) { toast(res.error || 'فشل البدء', 'error'); return; }
        toast('بدأت التجربة', 'success');
        loadTests();
    };

    window.aseoCompleteTest = async function (testId) {
        // جرّب قياس CTR الفعلي من GSC الأول - لو فيه فائز مقترح نستخدمه
        let winner = null;
        try {
            const r = await fetchJSON('/api/seo-ab-tests/' + testId + '/results');
            if (r.success && r.data.gsc_connected && r.data.suggested_winner_variant_id) {
                winner = (r.data.variants || []).find(v => v.id === r.data.suggested_winner_variant_id) || null;
            }
        } catch (e) {}

        // fallback: مفيش GSC مرتبط أو مفيش بيانات كافية => served_count
        if (!winner) {
            const tests = await fetchJSON('/api/seo-ab-tests?website_id=' + currentWebsiteId);
            const t = (tests.data.tests || []).find(x => x.id === testId);
            if (!t) return;
            const variants = t.variants || [];
            if (!variants.length) { toast('لا توجد نسخ', 'error'); return; }
            winner = variants.reduce((a, b) => (a.served_count >= b.served_count ? a : b));
        }

        const res = await fetchJSON('/api/seo-ab-tests/' + testId + '/complete', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ winner_variant_id: winner.id })
        });
        if (!res.success) { toast(res.error || 'فشل الإنهاء', 'error'); return; }
        toast('انتهت التجربة - الفائز: ' + winner.name, 'success');
        loadTests();
    };

    window.aseoMeasureResults = async function (testId) {
        const box = document.getElementById('aseoResults' + testId);
        box.innerHTML = '<span class="p-cell-muted" style="font-size:12px;">جاري جلب بيانات Search Console...</span>';
        const res = await fetchJSON('/api/seo-ab-tests/' + testId + '/results');
        if (!res.success) { box.innerHTML = '<span style="color:var(--panel-danger);font-size:12px;">' + esc(res.error || 'فشل القياس') + '</span>'; return; }
        const d = res.data;
        if (!d.gsc_connected) {
            box.innerHTML = '<span class="p-cell-muted" style="font-size:12px;">' + esc(d.message || '') + '</span>';
            return;
        }
        const rows = (d.variants || []).map(v => `
            <div class="aseo-kv">
                <span class="k">${esc(v.name)}${v.is_control == 1 ? ' (أصلية)' : ''}</span>
                <span class="v">CTR ${v.ctr}% · ${v.impressions} ظهور · ${v.clicks} نقرة · ترتيب ${v.avg_position}</span>
            </div>`).join('');
        const winner = (d.variants || []).find(v => v.id === d.suggested_winner_variant_id);
        box.innerHTML = rows + (winner
            ? `<div class="aseo-pill green" style="margin-top:8px;">الفائز المقترح: ${esc(winner.name)} (CTR ${winner.ctr}%)</div>`
            : `<div class="p-cell-muted" style="font-size:11.5px;margin-top:8px;">لسه مفيش بيانات كافية (عتبة ${d.min_impressions_threshold} ظهور لكل نسخة)</div>`);
    };

    loadWebsites();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('auto_seo', 'Auto SEO - التنفيذ التلقائي', 'ربط وتنفيذ وفهرسة فورية وتجارب A/B على مواقعك', $body, $script);
        exit;
    }

    /** POST /api/auto-seo/connect  { website_id, method } */
    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $method = (string) $this->get('method', 'script');

        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!in_array($method, ['script', 'api', 'wordpress', 'shopify'], true)) {
            return $this->error('طريقة ربط غير مدعومة', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        try {
            $result = $this->embed->connectWebsite((int) $this->user['id'], $websiteId, $method);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
        $this->log('Auto SEO Website Connected', ['website_id' => $websiteId, 'method' => $method]);

        return $this->success([
            'embed_token'   => $result['embed_token'],
            'api_key'       => $result['api_key'],
            'embed_code'    => $result['embed_code'],
            'install_steps' => [
                'انسخ كود الـEmbed',
                'حطّه قبل </head> في كل صفحات موقعك (أو في القالب الرئيسي)',
                'لو WordPress: استخدم wp_head hook أو إضافة Insert Headers',
                'شغّل تدقيق من Website Optimizer - الإصلاحات هتتطبق تلقائيًا',
            ],
        ], 'تم ربط الموقع بنجاح');
    }

    /** DELETE /api/auto-seo/connect?website_id=X */
    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $this->embed->disconnectWebsite((int) $this->user['id'], $websiteId);
        $this->log('Auto SEO Website Disconnected', ['website_id' => $websiteId]);

        return $this->success(['website_id' => $websiteId], 'تم فصل الموقع وإيقاف الحقن');
    }

    /** POST /api/auto-seo/mode  { website_id, mode } */
    public function setMode(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $mode = (string) $this->get('mode', 'conservative');

        if (!in_array($mode, ['off', 'conservative', 'balanced', 'aggressive'], true)) {
            return $this->error('وضع غير صالح', 422);
        }
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $this->db->exec(
            "UPDATE websites SET auto_pilot_mode = ?, auto_fix_enabled = ?, last_sync_at = NOW() WHERE id = ?",
            [$mode, $mode === 'off' ? 0 : 1, $websiteId]
        );

        return $this->success(['website_id' => $websiteId, 'mode' => $mode], 'تم تحديث وضع Auto-Pilot');
    }

    /**
     * POST /api/auto-seo/apply  { website_id, finding_id? }
     * من غير finding_id بيطبّق كل الإصلاحات المؤهلة من آخر تدقيق.
     */
    public function apply(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $findingId = (int) $this->get('finding_id', 0);

        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $site = $this->db->query("SELECT * FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        if (empty($site) || (int) $site[0]['is_connected'] !== 1) {
            return $this->error('الموقع غير مربوط - اربطه الأول عشان التنفيذ التلقائي يشتغل', 422);
        }

        $mode = $site[0]['auto_pilot_mode'] ?? 'conservative';

        $findings = $findingId
            ? $this->db->query(
                "SELECT f.*, a.id AS audit_id FROM wo_audit_findings f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE f.id = ? AND a.website_id = ? LIMIT 1",
                [$findingId, $websiteId]
            )
            : $this->db->query(
                "SELECT f.*, a.id AS audit_id FROM wo_audit_findings f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE a.website_id = ? AND a.status = 'completed'
                   AND f.status IN ('fail','warn')
                 ORDER BY a.completed_at DESC, FIELD(f.severity,'critical','high','medium','low')
                 LIMIT 30",
                [$websiteId]
            );

        if (empty($findings)) {
            return $this->error('مفيش نتائج تدقيق قابلة للتطبيق - شغّل تدقيق الأول', 404);
        }

        $applied = [];
        foreach ($findings as $finding) {
            // الضغط اليدوي بيتجاوز شرط الوضع، التلقائي لأ
            $isManual = $findingId > 0;
            if (!$isManual && !$this->embed->shouldAutoApply($finding, $mode)) {
                continue;
            }

            $res = $this->embed->applyFix(
                (int) $this->user['id'],
                $websiteId,
                $finding,
                $isManual ? 'manual_click' : 'audit_auto_pilot',
                $mode
            );

            if ($res['success']) {
                $applied[] = ['finding_id' => $finding['id'], 'field' => $finding['field_name'] ?? null, 'log_id' => $res['log_id']];
            }
        }

        $this->log('Auto SEO Fixes Applied', ['website_id' => $websiteId, 'count' => count($applied)]);

        // IndexNow: بعد التطبيق، بلّغ محركات البحث فورًا بالصفحات المتأثرة
        // عشان التعديلات تتزحف وتتفهرس في دقائق مش أسابيع.
        $indexNowResult = null;
        if (!empty($applied)) {
            $indexNowResult = $this->submitToIndexNow($websiteId, $findings);
        }

        // لقطة قبل/بعد في التقارير (best-effort) عشان العميل يشوف التحسن مع الوقت
        try {
            $perf = new SeoPerformanceService($this->db);
            $latestAudit = $this->db->query(
                "SELECT id, overall_score FROM wo_audits WHERE website_id = ? ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            $perf->snapshot(
                $websiteId,
                (int) $this->user['id'],
                (int) ($latestAudit[0]['id'] ?? 0),
                (float) ($latestAudit[0]['overall_score'] ?? 0),
                count($findings),
                count($applied),
                [],
                'manual'
            );
        } catch (Exception $e) {
            // لقطة اختيارية - لا تكسر مسار التطبيق
        }

        return $this->success([
            'applied_count' => count($applied),
            'applied'       => $applied,
            'indexnow'      => $indexNowResult,
        ], count($applied) . ' إصلاحات اتطبقت فعليًا على موقعك');
    }

    /** GET /api/auto-seo/logs?website_id=X */
    public function logs(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $logs = $this->db->query(
            "SELECT * FROM auto_seo_change_log WHERE website_id = ? ORDER BY id DESC LIMIT 50",
            [$websiteId]
        );

        return $this->success(['logs' => $logs]);
    }

    /** POST /api/auto-seo/rollback/{id} */
    public function rollback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $logId = (int) ($params['id'] ?? 0);
        if (!$logId) {
            return $this->error('معرف السجل مطلوب', 422);
        }

        $res = $this->embed->rollback((int) $this->user['id'], $logId);
        if (!$res['success']) {
            return $this->error($res['error'], 422);
        }

        $this->log('Auto SEO Rollback', ['log_id' => $logId]);

        return $this->success(['log_id' => $logId], 'تم التراجع - الحقن اتوقف فورًا');
    }

    /**
     * POST /api/auto-seo/preview  { website_id, changes: {field: value} }
     * معاينة التغييرات المقترحة قبل التطبيق الفعلي (من غير أي كتابة في DB).
     */
    public function preview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $changes = $this->get('changes', []);

        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        if (!is_array($changes) || empty($changes)) {
            return $this->error('أدخل قيمة مقترحة واحدة على الأقل', 422);
        }

        $allowed = ['seo_title', 'seo_description', 'canonical_url', 'viewport', 'robots_meta', 'json_ld', 'faq_schema', 'speakable', 'og_tags'];
        $changes = array_intersect_key($changes, array_flip($allowed));
        if (empty($changes)) {
            return $this->error('مفيش حقول مدعومة للمعاينة', 422);
        }

        $service = new SeoProxyService($this->db);
        $result = $service->previewChanges($websiteId, $changes);
        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'تعذر المعاينة', 502);
        }

        return $this->success($result, 'تم تجهيز المعاينة');
    }

    /**
     * GET /api/auto-seo/report?website_id=X
     * تقرير قبل/بعد: لقطات سابقة + سجل درجات التدقيق + مقاييس GSC + (GA4 لو مربوط).
     */
    public function report(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $perf = new SeoPerformanceService($this->db);

        $audits = $this->db->query(
            "SELECT overall_score, completed_at FROM wo_audits
              WHERE website_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 30",
            [$websiteId]
        );

        $fixRow = $this->db->query(
            "SELECT COUNT(*) AS c FROM auto_seo_applied_fixes WHERE website_id = ? AND is_active = 1",
            [$websiteId]
        );

        $siteRow = $this->db->query(
            "SELECT main_url, is_connected, auto_pilot_mode FROM websites WHERE id = ? LIMIT 1",
            [$websiteId]
        );

        return $this->success([
            'website'      => $siteRow[0] ?? null,
            'history'      => $perf->history($websiteId, 30),
            'audits'       => $audits,
            'top_issues'   => $this->topIssues($websiteId),
            'gsc'          => $perf->cachedSummary($websiteId),
            'active_fixes' => (int) ($fixRow[0]['c'] ?? 0),
            'ga4'          => $this->ga4Summary($websiteId),
        ]);
    }

    /**
     * أهم 3 مشاكل مفتوحة من آخر تدقيق مكتمل، بلغة بسيطة (بدون أي مصطلحات
     * تقنية) عشان تتعرض في "ملخص الأداء" للعميل غير التقني. بنرتب حسب
     * الخطورة (critical -> high -> medium) وناخد أول 3 بس.
     */
    private function topIssues(int $websiteId): array
    {
        $lastAudit = $this->db->query(
            "SELECT id FROM wo_audits WHERE website_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1",
            [$websiteId]
        );
        if (empty($lastAudit)) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT title, message, severity FROM wo_audit_findings
              WHERE audit_id = ? AND status != 'pass'
              ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low') LIMIT 3",
            [$lastAudit[0]['id']]
        );

        return array_map(fn ($r) => [
            'title'    => $r['title'],
            'message'  => $r['message'],
            'severity' => $r['severity'],
        ], $rows);
    }

    /** ملخص GA4 (best-effort) لو في حساب Google Analytics مربوط بالموقع */
    private function ga4Summary(int $websiteId): ?array
    {
        try {
            $connections = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'google_analytics',
                'status' => 'connected',
            ], [], 1);
            if (empty($connections)) {
                return null;
            }

            $connection = $connections[0];
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($connection->getAttribute('access_token'));
            $propertyId = (string) ($connection->getAttribute('external_location_id') ?: '');

            if ($propertyId === '') {
                return null;
            }

            $api = new GoogleAnalyticsAPI($accessToken);
            $summary = $api->getSummary($propertyId, 28);
            return $summary['success'] ? $summary['summary'] : ['error' => $summary['error'] ?? 'GA4 fetch failed'];
        } catch (Exception $e) {
            Logger::error('AutoSeo GA4 summary error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * GET /embed.js?token=xxx  (عام - من غير AuthMiddleware)
     * ده اللي بيتحمّل على موقع العميل فعليًا.
     */
    public function embedScript(array $params = []): void
    {
        $token = (string) ($_GET['token'] ?? '');

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');

        if ($token === '' || !preg_match('/^emb_[a-f0-9]{24}$/', $token)) {
            echo "// Tourfecto: invalid token\n";
            exit;
        }

        echo $this->embed->buildEmbedJavaScript($token);
        exit;
    }

    /** POST /api/auto-seo/hreflang { website_id, languages[] } */
    public function updateHreflang(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $languages = (array) $this->get('languages', []);

        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $clean = [];
        foreach ($languages as $lang) {
            $code = is_array($lang) ? ($lang['code'] ?? '') : $lang;
            $code = strtolower(trim((string) $code));
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) {
                $clean[] = ['code' => $code];
            }
        }

        $this->db->exec(
            "UPDATE websites SET target_languages = ? WHERE id = ?",
            [json_encode($clean, JSON_UNESCAPED_UNICODE), $websiteId]
        );

        $this->db->exec(
            "INSERT INTO auto_seo_applied_fixes
             (website_id, user_id, category, field_name, injected_code, is_active)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE injected_code = VALUES(injected_code), is_active = 1",
            [
                $websiteId,
                (int) $this->user['id'],
                'seo',
                'hreflang_tags',
                json_encode($clean, JSON_UNESCAPED_UNICODE),
            ]
        );

        return $this->success(['languages' => $clean], 'تم تحديث لغات الاستهداف');
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }

    /**
     * إبلاغ محركات البحث فورًا (IndexNow) بالصفحات اللي اتعدلت بعد التطبيق.
     * Best-effort: لو الموقع مش مفعّل IndexNow أو مفيش مفتاح، بنرجع null
     * من غير ما نكسر مسار التطبيق نفسه.
     */
    private function submitToIndexNow(int $websiteId, array $findings): ?array
    {
        try {
            $site = $this->db->query(
                "SELECT main_url, indexnow_key, indexnow_enabled FROM websites WHERE id = ? LIMIT 1",
                [$websiteId]
            );
            if (empty($site) || empty($site[0]['indexnow_key']) || (int) $site[0]['indexnow_enabled'] !== 1) {
                return null;
            }

            $host = parse_url($site[0]['main_url'] ?? '', PHP_URL_HOST) ?? '';
            if ($host === '') {
                return null;
            }

            $urls = [rtrim((string) $site[0]['main_url'], '/') . '/'];
            foreach ($findings as $f) {
                if (!empty($f['page_url'])) {
                    $urls[] = (string) $f['page_url'];
                }
            }
            $urls = array_values(array_unique($urls));

            $service = new IndexNowService();
            $result = $service->submitUrls($host, (string) $site[0]['indexnow_key'], $urls);

            return ['submitted' => $result['submitted'] ?? count($urls), 'success' => (bool) $result['success']];
        } catch (Exception $e) {
            Logger::error('IndexNow Auto Submit Error', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
