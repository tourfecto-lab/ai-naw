<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';

/**
 * Tourfecto - Ads Controller (إدارة الإعلانات)
 * @version 1.0.0
 */
class AdsController extends Controller
{
    /** @var AdCampaignService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AdCampaignService();
    }

    /** GET /ads */
    public function index(array $params = []): array
    {
        $objectiveOptionsHtml = '';
        foreach (AdCopyGenerationService::OBJECTIVES as $key => $label) {
            $keyEsc = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $objectiveOptionsHtml .= "<option value=\"{$keyEsc}\">{$labelEsc}</option>";
        }

        $ctasJson = htmlspecialchars(
            json_encode(AdCopyGenerationService::allowedCtas(), JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );

        $tabsHtml = $this->adsTabsHtml('dashboard');

        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" id="dashboardFilters" style="margin-bottom:16px;">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                <div>
                    <label class="p-cell-muted" style="font-size:12px;">الفترة</label><br>
                    <select id="dashPeriod" class="p-select" onchange="loadDashboardSummary()">
                        <option value="daily">آخر يوم</option>
                        <option value="weekly" selected>آخر 7 أيام</option>
                        <option value="monthly">آخر 30 يوم</option>
                    </select>
                </div>
                <div>
                    <label class="p-cell-muted" style="font-size:12px;">المنصة</label><br>
                    <select id="dashPlatform" class="p-select" onchange="loadDashboardSummary()">
                        <option value="">كل المنصات</option>
                        <option value="meta_ads">Meta Ads</option>
                        <option value="google_ads">Google Ads</option>
                    </select>
                </div>
                <div>
                    <label class="p-cell-muted" style="font-size:12px;">الحالة</label><br>
                    <select id="dashStatus" class="p-select" onchange="loadDashboardSummary()">
                        <option value="">كل الحالات</option>
                        <option value="active">نشطة</option>
                        <option value="paused">متوقفة</option>
                    </select>
                </div>
            </div>
        </div>

        <div id="dashboardKpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;">
            <div class="p-loading-row">جارِ التحميل...</div>
        </div>

        <div class="p-card" id="dashboardRecommendationsCard" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>💡 توصيات الذكاء الاصطناعي</h3><span class="p-card-sub">مبنية على أداء حسابك الفعلي - راجع صفحة Autopilot لتفاصيل كل توصية</span></div>
            <div id="dashboardRecommendations"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="metaConnectCard" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>Meta Ads (Facebook / Instagram)</h3><span class="p-card-sub">اربط حساب إعلاناتك عشان تسحب حملات وإنفاق حقيقي</span></div>
            <div id="metaConnectionStatus"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="googleAdsConnectCard" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>Google Ads</h3><span class="p-card-sub">اربط حساب Google Ads عشان تسحب حملات وإنفاق حقيقي</span></div>
            <div id="googleAdsConnectionStatus"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-modal-overlay" id="campaignToolsModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3>🛠 أدوات الحملة: <span id="toolsCampaignName"></span></h3>
                    <button class="p-modal-close" onclick="document.getElementById('campaignToolsModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <div class="p-card" style="margin-bottom:14px;">
                        <div class="p-card-head"><h3>🔑 كلمات مفتاحية (AI Keyword Strategist)</h3></div>
                        <textarea id="kwGoalDesc" class="p-select" style="width:100%;min-height:60px;" placeholder="وصف مختصر للعرض (لو فاضي هيستخدم product_or_service المسجّل بالفعل)"></textarea>
                        <button class="p-btn primary xs" style="margin-top:8px;" onclick="generateCampaignKeywords()">توليد الكلمات المفتاحية</button>
                        <div id="kwResults" style="margin-top:10px;font-size:13px;"></div>
                    </div>

                    <div class="p-card" style="margin-bottom:14px;">
                        <div class="p-card-head"><h3>🎯 تحليل صفحة الهبوط</h3></div>
                        <input type="text" id="lpUrl" class="p-select" style="width:100%;" placeholder="https://example.com/landing-page">
                        <button class="p-btn primary xs" style="margin-top:8px;" onclick="analyzeCampaignLandingPage()">تحليل الصفحة</button>
                        <div id="lpResults" style="margin-top:10px;font-size:13px;"></div>
                    </div>

                    <div class="p-card">
                        <div class="p-card-head"><h3>🔗 رابط UTM جديد</h3></div>
                        <input type="text" id="utmDest" class="p-select" style="width:100%;margin-bottom:6px;" placeholder="رابط الوجهة (صفحة الهبوط)">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <input type="text" id="utmSource" class="p-select" placeholder="utm_source (مثال: google)" value="google">
                            <input type="text" id="utmMedium" class="p-select" placeholder="utm_medium (مثال: cpc)" value="cpc">
                        </div>
                        <button class="p-btn primary xs" style="margin-top:8px;" onclick="createCampaignUtmLink()">إنشاء الرابط</button>
                        <div id="utmResults" style="margin-top:10px;font-size:13px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="adsWizardConfig" data-ctas="{$ctasJson}" style="display:none;"></div>

        <div class="p-toolbar" style="gap:10px;flex-wrap:wrap;">
            <button class="p-btn primary" onclick="openAiWizard()">✨ حملة إعلانية بالذكاء الاصطناعي</button>
            <button class="p-btn outline" onclick="document.getElementById('newCampaignModal').classList.add('open')">+ حملة يدوية</button>
        </div>

        <div class="p-toolbar" style="gap:10px;flex-wrap:wrap;margin-top:10px;">
            <input type="text" id="campaignSearch" class="p-select" style="flex:1;min-width:180px;" placeholder="ابحث باسم الحملة...">
            <select id="campaignStatusFilter" class="p-select" style="width:auto;">
                <option value="">كل الحالات</option>
                <option value="active">نشطة</option>
                <option value="paused">متوقفة</option>
                <option value="draft">مسودة</option>
            </select>
            <select id="campaignSort" class="p-select" style="width:auto;">
                <option value="created_at">الأحدث</option>
                <option value="name">الاسم</option>
                <option value="spend">الإنفاق</option>
                <option value="daily_budget">الميزانية</option>
            </select>
        </div>

        <div id="bulkActionBar" style="display:none;background:var(--card-bg, #fff);border:1px solid var(--border-color, #eee);border-radius:8px;padding:10px;margin-top:10px;align-items:center;gap:10px;">
            <span id="bulkSelectedCount" class="p-cell-muted"></span>
            <button class="p-btn outline xs" onclick="bulkUpdateStatus('active')">▶ استئناف المحدّد</button>
            <button class="p-btn outline xs" onclick="bulkUpdateStatus('paused')">⏸ إيقاف المحدّد</button>
        </div>

        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="campaignsTable">
                <thead><tr><th><input type="checkbox" id="selectAllCampaigns" onchange="toggleSelectAll()"></th><th>الاسم</th><th>الميزانية اليومية</th><th>الحالة</th><th>الإنفاق</th><th>النصوص الإعلانية</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="6">جارِ التحميل...</td></tr></tbody>
            </table></div>
            <div id="campaignsPagination" style="display:flex;justify-content:space-between;align-items:center;padding:10px;"></div>
        </div>

        <div class="p-modal-overlay" id="newCampaignModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>حملة إعلانية جديدة (يدوي)</h3><button class="p-modal-close" onclick="document.getElementById('newCampaignModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>اسم الحملة</label>
                    <input type="text" id="campaignName" class="p-select" style="width:100%;margin-bottom:10px;">
                    <label>الميزانية اليومية (USD)</label>
                    <input type="number" id="campaignBudget" class="p-select" style="width:100%;">
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="createCampaign()">إنشاء</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="aiWizardModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3>✨ حملة إعلانية بالذكاء الاصطناعي</h3>
                    <button class="p-modal-close" onclick="closeAiWizard()">×</button>
                </div>
                <div class="p-modal-body">
                    <div id="aiWizardStep1">
                        <label>الهدف من الحملة</label>
                        <select id="aiObjective" class="p-select" style="width:100%;margin-bottom:14px;">{$objectiveOptionsHtml}</select>

                        <label>وصف مختصر لعرضك</label>
                        <textarea id="aiGoalDescription" class="p-select" rows="3" style="width:100%;margin-bottom:14px;" placeholder="مثال: رحلة الغردقة 3 أيام 2 ليلة شاملة الإقامة والإفطار بـ 5000 جنيه للفرد" maxlength="2000"></textarea>

                        <label>الميزانية اليومية المتوقعة (USD) - اختياري</label>
                        <input type="number" id="aiDailyBudget" class="p-select" style="width:100%;margin-bottom:6px;" min="1" step="0.5">
                        <div class="p-cell-muted" style="font-size:11.5px;margin-bottom:16px;">سيب الحقل ده فاضي لو عايز الذكاء الاصطناعي يقترحلك رقم مناسب</div>

                        <button class="p-btn primary btn-block" id="aiGenerateBtn" onclick="generateAiBrief()">توليد الحملة بالذكاء الاصطناعي ✨</button>
                        <div class="p-cell-muted" style="font-size:11px;text-align:center;margin-top:8px;">هيتم خصم سعر التوليد من رصيد محفظتك عند نجاح التوليد بس</div>
                        <div id="aiWizardError" class="alert alert-danger" style="display:none;margin-top:12px;"></div>
                    </div>

                    <div id="aiWizardStep2" style="display:none;"></div>
                </div>
                <div class="p-modal-foot" id="aiWizardFoot" style="display:none;">
                    <button class="p-btn outline" onclick="backToAiStep1()">‹ رجوع للتعديل</button>
                    <button class="p-btn primary" id="aiConfirmCreateBtn" onclick="confirmCreateAiCampaign()">إنشاء الحملة ✅</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const ALLOWED_CTAS = JSON.parse(document.getElementById('adsWizardConfig').dataset.ctas || '[]');
    const LIMITS = {
        headline: { recommended: 27, max: 40 },
        description: { recommended: 27, max: 30 },
        primary_text: { recommended: 125, max: 220 },
    };
    let currentBrief = null;

    async function loadMetaStatus() {
        const res = await fetchJSON('/api/ads/meta/status');
        const box = document.getElementById('metaConnectionStatus');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحقق من حالة الربط</div>'; return; }

        if (!res.data.configured) {
            box.innerHTML = '<div class="p-cell-muted">ربط Meta Ads لسه مش مفعّل من إدارة النظام (بيانات App ID/Secret ناقصة في إعدادات السيرفر).</div>';
            return;
        }

        if (res.data.connected) {
            box.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span><span class="pill green">✔ مربوط</span> ${esc(res.data.account_name || res.data.external_account_id || '')}</span>
                    <div style="display:flex;gap:8px;">
                        <button class="p-btn outline xs" onclick="syncMetaCampaigns()">🔄 مزامنة الحملات الآن</button>
                        <button class="p-btn danger xs" onclick="disconnectMeta()">فصل الربط</button>
                    </div>
                </div>`;
        } else {
            box.innerHTML = `<a href="/ads/connect/meta" class="p-btn primary xs">🔗 ربط حساب Meta Ads</a>`;
        }
    }

    window.syncMetaCampaigns = async function () {
        P.toast('جارِ سحب الحملات من Meta...', 'success');
        const res = await fetchJSON('/api/ads/meta/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); load(); }
        else P.toast(res.error || 'تعذرت المزامنة', 'error');
    };

    window.disconnectMeta = async function () {
        if (!confirm('متأكد من فصل ربط Meta Ads؟')) return;
        const res = await fetchJSON('/api/ads/meta/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم فصل الربط', 'success'); loadMetaStatus(); }
        else P.toast(res.error || 'تعذر الفصل', 'error');
    };

    async function loadGoogleAdsStatus() {
        const res = await fetchJSON('/api/ads/google/status');
        const box = document.getElementById('googleAdsConnectionStatus');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحقق من حالة الربط</div>'; return; }

        if (!res.data.configured) {
            box.innerHTML = '<div class="p-cell-muted">ربط Google Ads لسه مش مفعّل من إدارة النظام (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_ADS_OAUTH_REDIRECT_URI ناقصة).</div>';
            return;
        }

        if (res.data.connected) {
            box.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span><span class="pill green">✔ مربوط</span> ${esc(res.data.external_account_id || '')}</span>
                    <div style="display:flex;gap:8px;">
                        <button class="p-btn outline xs" onclick="syncGoogleAdsCampaigns()">🔄 مزامنة الحملات الآن</button>
                        <button class="p-btn danger xs" onclick="disconnectGoogleAds()">فصل الربط</button>
                    </div>
                </div>`;
        } else {
            box.innerHTML = `<a href="/ads/connect/google" class="p-btn primary xs">🔗 ربط حساب Google Ads</a>`;
        }
    }

    window.syncGoogleAdsCampaigns = async function () {
        P.toast('جارِ سحب الحملات من Google Ads...', 'success');
        const res = await fetchJSON('/api/ads/google/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); load(); }
        else P.toast(res.error || 'تعذرت المزامنة', 'error');
    };

    window.disconnectGoogleAds = async function () {
        if (!confirm('متأكد من فصل ربط Google Ads؟')) return;
        const res = await fetchJSON('/api/ads/google/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم فصل الربط', 'success'); loadGoogleAdsStatus(); }
        else P.toast(res.error || 'تعذر الفصل', 'error');
    };

    let currentPage = 1;
    let searchDebounceTimer = null;

    async function load() {
        const tbody = document.querySelector('#campaignsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="6">جارِ التحميل...</td></tr>';
        document.getElementById('selectAllCampaigns').checked = false;
        updateBulkBar();

        const qs = new URLSearchParams({
            q: document.getElementById('campaignSearch').value.trim(),
            status: document.getElementById('campaignStatusFilter').value,
            sort: document.getElementById('campaignSort').value,
            dir: 'desc',
            page: currentPage,
            per_page: 20,
        });

        const res = await fetchJSON('/api/ads/campaigns/search?' + qs.toString());
        if (res.success && res.data.campaigns && res.data.campaigns.length) {
            tbody.innerHTML = res.data.campaigns.map(c => `
                <tr>
                    <td><input type="checkbox" class="campaign-select" value="${c.id}" onchange="updateBulkBar()"></td>
                    <td>
                        ${esc(c.name)}
                        ${c.ai_generated ? '<span class="pill blue xs" style="margin-inline-start:6px;">✨ ذكاء اصطناعي</span>' : ''}
                        ${c.target_audience_brief ? '<div class="p-cell-muted" style="font-size:11px;margin-top:3px;">🎯 ' + esc(c.target_audience_brief) + '</div>' : ''}
                    </td>
                    <td>${esc(c.daily_budget || '-')} ${esc(c.currency)}</td>
                    <td>${esc(c.status)}</td>
                    <td>${esc(c.spend)} ${esc(c.currency)}</td>
                    <td>
                        <a href="/ads/campaigns/${c.id}" class="p-btn outline xs" style="text-decoration:none;">📋 التفاصيل</a>
                        <button class="p-btn outline xs" onclick="generateCopies(${c.id})">توليد ✨</button>
                        <button class="p-btn outline xs" data-campaign-id="${c.id}" data-campaign-name="${esc(c.name)}" onclick="openCampaignTools(this)">🛠 أدوات</button>
                        <div id="copies-${c.id}" style="margin-top:6px;"></div>
                    </td>
                </tr>
            `).join('');
            res.data.campaigns.forEach(c => { if (c.id) loadCopiesInline(c.id); });
            renderPagination(res.data.total, res.data.page, res.data.per_page);
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="p-empty">لا يوجد حملات مطابقة</td></tr>';
            document.getElementById('campaignsPagination').innerHTML = '';
        }
    }

    function renderPagination(total, page, perPage) {
        const box = document.getElementById('campaignsPagination');
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (total === 0) { box.innerHTML = ''; return; }
        box.innerHTML = `
            <span class="p-cell-muted">${total} حملة - صفحة ${page} من ${totalPages}</span>
            <div style="display:flex;gap:6px;">
                <button class="p-btn outline xs" ${page <= 1 ? 'disabled' : ''} onclick="goToPage(${page - 1})">السابق</button>
                <button class="p-btn outline xs" ${page >= totalPages ? 'disabled' : ''} onclick="goToPage(${page + 1})">التالي</button>
            </div>`;
    }

    window.goToPage = function (page) { currentPage = page; load(); };

    window.toggleSelectAll = function () {
        const checked = document.getElementById('selectAllCampaigns').checked;
        document.querySelectorAll('.campaign-select').forEach(cb => { cb.checked = checked; });
        updateBulkBar();
    };

    window.updateBulkBar = function () {
        const selected = document.querySelectorAll('.campaign-select:checked');
        const bar = document.getElementById('bulkActionBar');
        if (selected.length > 0) {
            bar.style.display = 'flex';
            document.getElementById('bulkSelectedCount').textContent = selected.length + ' حملة محدّدة';
        } else {
            bar.style.display = 'none';
        }
    };

    window.bulkUpdateStatus = async function (newStatus) {
        const ids = Array.from(document.querySelectorAll('.campaign-select:checked')).map(cb => parseInt(cb.value, 10));
        if (!ids.length) return;
        const actionLabel = newStatus === 'paused' ? 'إيقاف' : 'استئناف';
        if (!confirm('متأكد من ' + actionLabel + ' ' + ids.length + ' حملة؟')) return;

        const res = await fetchJSON('/api/ads/campaigns/bulk-status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campaign_ids: ids, status: newStatus }),
        });
        if (res.success) {
            const failed = res.data.results.filter(r => !r.success);
            if (failed.length) P.toast(failed.length + ' حملة فشلت (راجع التفاصيل)', 'error');
            else P.toast('تم ' + actionLabel + ' الحملات المحدّدة', 'success');
            load();
        } else {
            P.toast(res.error || 'تعذّر تنفيذ الإجراء الجماعي', 'error');
        }
    };

    document.getElementById('campaignSearch').addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => { currentPage = 1; load(); }, 400);
    });
    document.getElementById('campaignStatusFilter').addEventListener('change', () => { currentPage = 1; load(); });
    document.getElementById('campaignSort').addEventListener('change', () => { currentPage = 1; load(); });

    async function loadCopiesInline(campaignId) {
        const box = document.getElementById('copies-' + campaignId);
        if (!box) return;
        const res = await fetchJSON('/api/ads/campaigns/' + campaignId + '/copies');
        if (res.success && res.data.copies && res.data.copies.length) {
            renderCopiesList(box, res.data.copies);
        }
    }

    function renderCopiesList(box, copies) {
        box.innerHTML = copies.map(c => `
            <div class="ads-copy-mini ${c.status === 'approved' ? 'approved' : ''} ${c.status === 'rejected' ? 'rejected' : ''}">
                <div><strong>[${esc(c.variant_label)}]</strong> ${esc(c.headline)}</div>
                <div class="p-cell-muted" style="font-size:11px;">${esc(c.description || '')}</div>
                <div class="ads-copy-mini-actions">
                    ${c.status === 'approved'
                        ? '<span class="pill green xs">✔ معتمدة</span>'
                        : `<button class="p-btn outline xs" onclick="approveCopy(${c.id})">اعتماد</button>
                           <button class="p-btn ghost xs" onclick="rejectCopy(${c.id})">استبعاد</button>`}
                </div>
            </div>
        `).join('');
    }

    window.generateCopies = async function (id) {
        const box = document.getElementById('copies-' + id);
        box.innerHTML = '<div class="p-cell-muted">جارِ التوليد...</div>';
        const res = await fetchJSON('/api/ads/campaigns/' + id + '/generate-copies', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success && res.data.copies) {
            renderCopiesList(box, res.data.copies);
        } else {
            box.innerHTML = '<span class="p-cell-muted">' + esc(res.error || 'فشل التوليد') + '</span>';
        }
    };

    window.approveCopy = async function (id) {
        const res = await fetchJSON('/api/ads/copies/' + id + '/approve', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم اعتماد النسخة', 'success'); load(); }
        else P.toast(res.error || 'تعذر الاعتماد', 'error');
    };

    window.rejectCopy = async function (id) {
        const res = await fetchJSON('/api/ads/copies/' + id + '/reject', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم استبعاد النسخة', 'success'); load(); }
        else P.toast(res.error || 'تعذر الاستبعاد', 'error');
    };

    window.createCampaign = async function () {
        const name = document.getElementById('campaignName').value.trim();
        const daily_budget = document.getElementById('campaignBudget').value;
        if (!name) return;
        const res = await fetchJSON('/api/ads/campaigns', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, daily_budget }) });
        document.getElementById('newCampaignModal').classList.remove('open');
        document.getElementById('campaignName').value = '';
        document.getElementById('campaignBudget').value = '';
        if (res.success) { P.toast('تم إنشاء الحملة (مسودة)', 'success'); load(); }
        else P.toast(res.error || 'فشل الإنشاء', 'error');
    };

    // ============ ويزارد الحملة بالذكاء الاصطناعي ============
    window.openAiWizard = function () {
        currentBrief = null;
        document.getElementById('aiWizardError').style.display = 'none';
        document.getElementById('aiWizardStep1').style.display = 'block';
        document.getElementById('aiWizardStep2').style.display = 'none';
        document.getElementById('aiWizardStep2').innerHTML = '';
        document.getElementById('aiWizardFoot').style.display = 'none';
        document.getElementById('aiWizardModal').classList.add('open');
    };

    window.closeAiWizard = function () {
        document.getElementById('aiWizardModal').classList.remove('open');
    };

    window.backToAiStep1 = function () {
        document.getElementById('aiWizardStep1').style.display = 'block';
        document.getElementById('aiWizardStep2').style.display = 'none';
        document.getElementById('aiWizardFoot').style.display = 'none';
    };

    window.generateAiBrief = async function () {
        const objective = document.getElementById('aiObjective').value;
        const goalDescription = document.getElementById('aiGoalDescription').value.trim();
        const dailyBudget = document.getElementById('aiDailyBudget').value;
        const errBox = document.getElementById('aiWizardError');
        errBox.style.display = 'none';

        if (!goalDescription) {
            errBox.textContent = 'اكتب وصف مختصر لعرضك الأول';
            errBox.style.display = 'block';
            return;
        }

        const btn = document.getElementById('aiGenerateBtn');
        const originalLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'جارِ التوليد بالذكاء الاصطناعي...';

        const payload = { objective: objective, goal_description: goalDescription };
        if (dailyBudget) payload.daily_budget = dailyBudget;

        let res;
        try {
            res = await fetchJSON('/api/ads/campaigns/ai-generate', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
        } catch (e) {
            res = { success: false, error: 'تعذر الاتصال بالسيرفر' };
        }

        btn.disabled = false;
        btn.textContent = originalLabel;

        if (res.success) {
            currentBrief = res.data.brief;
            renderAiReview(currentBrief);
            document.getElementById('aiWizardStep1').style.display = 'none';
            document.getElementById('aiWizardStep2').style.display = 'block';
            document.getElementById('aiWizardFoot').style.display = 'flex';
        } else {
            if (res.data && res.data.shortfall) {
                errBox.textContent = 'رصيدك في المحفظة مش كافي - محتاج تودّع $' + res.data.shortfall + ' إضافية';
            } else {
                errBox.textContent = res.error || 'تعذر توليد الحملة، جرّب تاني';
            }
            errBox.style.display = 'block';
        }
    };

    function ctaOptionsHtml(selected) {
        return ALLOWED_CTAS.map(function (c) {
            return '<option value="' + esc(c) + '"' + (c === selected ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
    }

    function badgeClass(len, limitObj) {
        return len <= limitObj.recommended ? 'ok' : 'warn';
    }

    function renderCopyCard(c, i) {
        const headline = c.headline || '';
        const description = c.description || '';
        const primaryText = c.primary_text || '';
        const hLen = headline.length, dLen = description.length, pLen = primaryText.length;

        let html = '<div class="ads-copy-card">';
        html += '<div class="ads-copy-card-head">نسخة ' + esc(c.variant_label || String.fromCharCode(65 + i)) + '</div>';

        html += '<label>العنوان (Headline)</label>';
        html += '<div class="ads-field-row"><input type="text" class="p-select ads-cc-headline" data-idx="' + i + '" style="width:100%;" maxlength="' + LIMITS.headline.max + '" value="' + esc(headline) + '">';
        html += '<span class="ads-char-badge ' + badgeClass(hLen, LIMITS.headline) + '" id="badge-headline-' + i + '">' + hLen + '/' + LIMITS.headline.max + '</span></div>';

        html += '<label>الوصف (Description)</label>';
        html += '<div class="ads-field-row"><input type="text" class="p-select ads-cc-description" data-idx="' + i + '" style="width:100%;" maxlength="' + LIMITS.description.max + '" value="' + esc(description) + '">';
        html += '<span class="ads-char-badge ' + badgeClass(dLen, LIMITS.description) + '" id="badge-description-' + i + '">' + dLen + '/' + LIMITS.description.max + '</span></div>';

        html += '<label>النص الأساسي (Primary Text)</label>';
        html += '<div class="ads-field-row"><textarea class="p-select ads-cc-primary_text" data-idx="' + i + '" style="width:100%;" rows="2" maxlength="' + LIMITS.primary_text.max + '">' + esc(primaryText) + '</textarea>';
        html += '<span class="ads-char-badge ' + badgeClass(pLen, LIMITS.primary_text) + '" id="badge-primary_text-' + i + '">' + pLen + '/' + LIMITS.primary_text.max + '</span></div>';

        html += '<label>دعوة لاتخاذ إجراء (CTA)</label>';
        html += '<select class="p-select ads-cc-cta" data-idx="' + i + '" style="width:100%;">' + ctaOptionsHtml(c.call_to_action) + '</select>';

        const warnings = c.policy_warnings || [];
        if (warnings.length) {
            html += '<div class="ads-policy-warnings">' + warnings.map(function (w) {
                return '<div class="ads-policy-warning">⚠️ ' + esc(w) + '</div>';
            }).join('') + '</div>';
        }

        html += '</div>';
        return html;
    }

    function bindCopyCardEvents(count) {
        for (let i = 0; i < count; i++) {
            bindField('headline', i, LIMITS.headline);
            bindField('description', i, LIMITS.description);
            bindField('primary_text', i, LIMITS.primary_text);
        }
    }

    function bindField(field, i, limitObj) {
        const el = document.querySelector('.ads-cc-' + field + '[data-idx="' + i + '"]');
        const badge = document.getElementById('badge-' + field + '-' + i);
        if (!el || !badge) return;
        el.addEventListener('input', function () {
            const len = el.value.length;
            badge.textContent = len + '/' + limitObj.max;
            badge.classList.remove('ok', 'warn');
            badge.classList.add(badgeClass(len, limitObj));
        });
    }

    function renderAiReview(brief) {
        const step2 = document.getElementById('aiWizardStep2');
        const a = brief.audience || {};
        const b = brief.budget_recommendation || {};
        let html = '';

        html += '<label>اسم الحملة</label>';
        html += '<input type="text" id="reviewCampaignName" class="p-select" style="width:100%;margin-bottom:16px;" maxlength="255" value="' + esc(brief.campaign_name || '') + '">';

        html += '<div class="p-card" style="margin-bottom:14px;padding:14px;">';
        html += '<div style="font-weight:800;font-size:13.5px;margin-bottom:10px;">🎯 الجمهور المستهدف</div>';
        html += '<div class="ads-grid-2">';
        html += '<div><label>أقل عمر</label><input type="number" id="reviewAgeMin" class="p-select" style="width:100%;" value="' + (a.age_min != null ? a.age_min : 18) + '" min="13" max="65"></div>';
        html += '<div><label>أكبر عمر</label><input type="number" id="reviewAgeMax" class="p-select" style="width:100%;" value="' + (a.age_max != null ? a.age_max : 65) + '" min="13" max="65"></div>';
        html += '</div>';
        html += '<label style="margin-top:10px;display:block;">الجنس</label>';
        html += '<select id="reviewGenders" class="p-select" style="width:100%;">';
        html += '<option value="all"' + (a.genders === 'all' ? ' selected' : '') + '>الكل</option>';
        html += '<option value="male"' + (a.genders === 'male' ? ' selected' : '') + '>ذكور</option>';
        html += '<option value="female"' + (a.genders === 'female' ? ' selected' : '') + '>إناث</option>';
        html += '</select>';
        html += '<label style="margin-top:10px;display:block;">المواقع الجغرافية (افصل بفاصلة)</label>';
        html += '<input type="text" id="reviewLocations" class="p-select" style="width:100%;" value="' + esc((a.locations || []).join('، ')) + '">';
        html += '<label style="margin-top:10px;display:block;">الاهتمامات (افصل بفاصلة)</label>';
        html += '<input type="text" id="reviewInterests" class="p-select" style="width:100%;" value="' + esc((a.interests || []).join('، ')) + '">';
        if (brief.target_audience_brief) {
            html += '<div class="p-cell-muted" style="margin-top:10px;font-size:12px;">💡 ' + esc(brief.target_audience_brief) + '</div>';
        }
        html += '</div>';

        html += '<div class="p-card" style="margin-bottom:14px;padding:14px;">';
        html += '<div style="font-weight:800;font-size:13.5px;margin-bottom:10px;">💰 توصية الميزانية</div>';
        html += '<label>الميزانية اليومية المقترحة (USD)</label>';
        html += '<input type="number" id="reviewBudget" class="p-select" style="width:100%;margin-bottom:8px;" min="1" step="0.5" value="' + (b.recommended_daily_budget != null ? b.recommended_daily_budget : 10) + '">';
        if (b.bid_strategy) html += '<div class="p-cell-muted" style="font-size:12px;"><strong>استراتيجية المزايدة:</strong> ' + esc(b.bid_strategy) + '</div>';
        if (b.reasoning) html += '<div class="p-cell-muted" style="font-size:12px;margin-top:4px;">💡 ' + esc(b.reasoning) + '</div>';
        html += '</div>';

        html += '<div style="font-size:13px;font-weight:800;margin:14px 0 8px;">✍️ النصوص الإعلانية (اتفحص العدّادات وعدّل اللي يعجبك)</div>';
        const copies = brief.copies || [];
        copies.forEach(function (c, i) { html += renderCopyCard(c, i); });

        step2.innerHTML = html;
        bindCopyCardEvents(copies.length);
    }

    function collectReviewData() {
        const locations = document.getElementById('reviewLocations').value.split(/[,،]/).map(s => s.trim()).filter(Boolean);
        const interests = document.getElementById('reviewInterests').value.split(/[,،]/).map(s => s.trim()).filter(Boolean);
        const copyCount = (currentBrief && currentBrief.copies ? currentBrief.copies.length : 0);
        const copies = [];
        for (let i = 0; i < copyCount; i++) {
            const headlineEl = document.querySelector('.ads-cc-headline[data-idx="' + i + '"]');
            if (!headlineEl) continue;
            copies.push({
                headline: headlineEl.value.trim(),
                description: document.querySelector('.ads-cc-description[data-idx="' + i + '"]').value.trim(),
                primary_text: document.querySelector('.ads-cc-primary_text[data-idx="' + i + '"]').value.trim(),
                call_to_action: document.querySelector('.ads-cc-cta[data-idx="' + i + '"]').value,
                variant_label: (currentBrief.copies[i] && currentBrief.copies[i].variant_label) || String.fromCharCode(65 + i),
            });
        }

        return {
            name: document.getElementById('reviewCampaignName').value.trim(),
            objective: currentBrief.objective,
            product_or_service: currentBrief.product_or_service,
            target_audience_brief: currentBrief.target_audience_brief,
            daily_budget: document.getElementById('reviewBudget').value,
            ai_generated: true,
            audience: {
                age_min: document.getElementById('reviewAgeMin').value,
                age_max: document.getElementById('reviewAgeMax').value,
                genders: document.getElementById('reviewGenders').value,
                locations: locations,
                interests: interests,
            },
            budget_recommendation: {
                recommended_daily_budget: document.getElementById('reviewBudget').value,
                bid_strategy: (currentBrief.budget_recommendation || {}).bid_strategy || '',
                reasoning: (currentBrief.budget_recommendation || {}).reasoning || '',
            },
            copies: copies,
        };
    }

    window.confirmCreateAiCampaign = async function () {
        if (!currentBrief) return;
        const payload = collectReviewData();
        if (!payload.name) {
            payload.name = 'حملة بالذكاء الاصطناعي';
        }

        const btn = document.getElementById('aiConfirmCreateBtn');
        btn.disabled = true;
        btn.textContent = 'جارِ الإنشاء...';

        const res = await fetchJSON('/api/ads/campaigns', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        btn.disabled = false;
        btn.textContent = 'إنشاء الحملة ✅';

        if (res.success) {
            P.toast('تم إنشاء الحملة بنجاح', 'success');
            closeAiWizard();
            load();
        } else {
            P.toast(res.error || 'تعذر إنشاء الحملة', 'error');
        }
    };

    let currentToolsCampaignId = null;

    window.openCampaignTools = function (btn) {
        currentToolsCampaignId = btn.getAttribute('data-campaign-id') || currentToolsCampaignId;
        document.getElementById('toolsCampaignName').textContent = btn.getAttribute('data-campaign-name') || '';
        document.getElementById('kwResults').innerHTML = '';
        document.getElementById('lpResults').innerHTML = '';
        document.getElementById('utmResults').innerHTML = '';
        document.getElementById('campaignToolsModal').classList.add('open');
    };

    window.generateCampaignKeywords = async function () {
        const box = document.getElementById('kwResults');
        box.innerHTML = 'جارِ التحليل...';
        const goalDescription = document.getElementById('kwGoalDesc').value.trim();
        const res = await fetchJSON(`/api/ads/campaigns/${currentToolsCampaignId}/keywords/generate`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ goal_description: goalDescription }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التوليد')}</span>`; return; }

        const groups = ['high_intent', 'commercial', 'long_tail', 'local', 'negative'];
        const labels = { high_intent: 'نية شراء عالية', commercial: 'تجارية عامة', long_tail: 'عبارات طويلة', local: 'محلية', negative: 'سلبية (استبعاد)' };
        box.innerHTML = groups.map(g => (res.data[g] && res.data[g].length) ? `
            <div style="margin-bottom:8px;"><b>${labels[g]}:</b> ${res.data[g].map(k => `<span class="pill xs" style="margin:2px;">${esc(k.keyword)}</span>`).join('')}</div>
        ` : '').join('') + `<div class="p-cell-muted" style="font-size:11px;">${esc(res.data.disclaimer || '')}</div>`;
    };

    window.analyzeCampaignLandingPage = async function () {
        const box = document.getElementById('lpResults');
        box.innerHTML = 'جارِ التحليل...';
        const url = document.getElementById('lpUrl').value.trim();
        const res = await fetchJSON(`/api/ads/campaigns/${currentToolsCampaignId}/landing-page/analyze`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }
        if (res.data.fetch_error) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.data.fetch_error)}</span>`; return; }

        box.innerHTML = `
            <div><b>Relevance:</b> ${esc(res.data.relevance || '-')}</div>
            <div><b>CTA:</b> ${esc(res.data.cta || '-')}</div>
            <div><b>Message Match:</b> ${esc(res.data.message_match || '-')}</div>
            <div style="margin-top:6px;"><b>التوصيات:</b><ul>${(res.data.recommendations || []).map(r => `<li>${esc(r)}</li>`).join('')}</ul></div>
        `;
    };

    window.createCampaignUtmLink = async function () {
        const box = document.getElementById('utmResults');
        box.innerHTML = 'جارِ الإنشاء...';
        const payload = {
            destination_url: document.getElementById('utmDest').value.trim(),
            utm_source: document.getElementById('utmSource').value.trim() || 'google',
            utm_medium: document.getElementById('utmMedium').value.trim() || 'cpc',
        };
        const res = await fetchJSON(`/api/ads/campaigns/${currentToolsCampaignId}/utm-links`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر الإنشاء')}</span>`; return; }
        box.innerHTML = `<div>رابط التتبع القصير: <a href="${esc(res.data.short_redirect_url)}" target="_blank">${esc(res.data.short_redirect_url)}</a></div>`;
    };

    async function loadDashboardSummary() {
        const box = document.getElementById('dashboardKpis');
        const period = document.getElementById('dashPeriod').value;
        const platform = document.getElementById('dashPlatform').value;
        const status = document.getElementById('dashStatus').value;

        const qs = new URLSearchParams({ period });
        if (platform) qs.set('platform', platform);
        if (status) qs.set('status', status);

        const res = await fetchJSON('/api/ads/dashboard/summary?' + qs.toString());
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل الملخص</div>'; return; }
        const d = res.data;

        const kpi = (label, value) => `
            <div class="p-card" style="padding:14px;">
                <div class="p-cell-muted" style="font-size:11.5px;">${label}</div>
                <div style="font-size:20px;font-weight:800;margin-top:4px;">${value === null || value === undefined ? '<span class="p-cell-muted" style="font-size:13px;">لا توجد بيانات كافية</span>' : esc(String(value))}</div>
            </div>`;

        box.innerHTML =
            kpi('إجمالي الإنفاق', d.spend) +
            kpi('التحويلات', d.conversions) +
            kpi('CTR', d.ctr !== null ? d.ctr + '%' : null) +
            kpi('CPC', d.cpc) +
            kpi('CPM', d.cpm) +
            kpi('ROAS', d.roas !== null ? d.roas + 'x' : null) +
            kpi('حملات نشطة', d.active_campaigns) +
            kpi('حملات متوقفة', d.paused_campaigns) +
            kpi('استخدام الميزانية', d.budget_utilization_pct !== null ? d.budget_utilization_pct + '%' : null);
    }

    async function loadDashboardRecommendations() {
        const box = document.getElementById('dashboardRecommendations');
        const res = await fetchJSON('/api/ads/autopilot/pending');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        if (!res.data.length) { box.innerHTML = '<div class="p-cell-muted">مفيش توصيات جديدة حاليًا - كل حملاتك ضمن النطاق الطبيعي، أو الوضع الحالي "يدوي" وبيسجّل توصيات في سجل Autopilot بدل طابور الموافقة.</div>'; return; }
        box.innerHTML = res.data.slice(0, 3).map(a => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <b>${esc(a.action_type)}</b> - حملة #${a.campaign_id}
                <div class="p-cell-muted" style="font-size:12px;">${esc(a.reasoning)}</div>
            </div>`).join('') + `<a href="/ads/autopilot" class="p-btn outline xs" style="margin-top:8px;">مراجعة كل التوصيات</a>`;
    }

    loadDashboardSummary();
    loadDashboardRecommendations();
    loadMetaStatus();
    loadGoogleAdsStatus();
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'إدارة الإعلانات', 'حملاتك الإعلانية عبر كل المنصات المربوطة', $body, $script);
        exit;
    }

    /** GET /api/ads/campaigns (?owner_id= لعرض حساب فريق تانٍ إنت عضو فيه) */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $campaigns = $this->service->listForUser($access['owner_id']);
        return $this->success(['campaigns' => array_map(fn ($c) => $c->toArray(), $campaigns), 'your_role' => $access['role']]);
    }

    /**
     * GET /api/ads/campaigns/search?q=&status=&sort=&dir=&page=&per_page=&owner_id=
     * نسخة Server-side مع بحث/فلترة/ترتيب/Pagination حقيقي - endpoint
     * منفصل عن list() القديمة عشان أي استدعاء موجود ليها يفضل شغال بالظبط
     * زي ما هو، مفيش أي Breaking change.
     */
    public function searchCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $result = $this->service->listForUserPaginated($access['owner_id'], [
            'search' => $this->get('q', ''),
            'status' => $this->get('status', ''),
            'sort' => $this->get('sort', 'created_at'),
            'dir' => $this->get('dir', 'desc'),
            'page' => (int) $this->get('page', 1),
            'per_page' => (int) $this->get('per_page', 20),
        ]);
        $result['your_role'] = $access['role'];

        return $this->success($result);
    }

    /** GET /api/ads/campaigns/{id} - تفاصيل حملة واحدة + الجمهور المرتبط بيها */
    public function getCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $access = $this->resolveCampaignAccess($campaign, 'viewer');
        if (!$access) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $audiences = (new AdAudience())->where(['campaign_id' => (int) $campaign->getAttribute('id')], [], 1);
        $audience = !empty($audiences) ? $audiences[0]->toArray() : null;
        if ($audience) {
            $audience['locations'] = json_decode((string) ($audience['locations_json'] ?? 'null'), true);
            $audience['interests'] = json_decode((string) ($audience['interests_json'] ?? 'null'), true);
        }

        $data = $campaign->toArray();
        $data['landing_page_last_analysis'] = $data['landing_page_last_analysis'] ? json_decode((string) $data['landing_page_last_analysis'], true) : null;

        return $this->success(['campaign' => $data, 'audience' => $audience, 'your_role' => $access['role']]);
    }

    /**
     * POST /api/ads/campaigns
     * بيقبل إنشاء يدوي بسيط (اسم + ميزانية بس، زي الأول)، أو إنشاء كامل
     * من ويزارد الذكاء الاصطناعي (لما يبعت objective/product_or_service/
     * target_audience_brief/audience/budget_recommendation/copies بعد
     * ما العميل يراجع معاينة /api/ads/campaigns/ai-generate ويأكّدها).
     */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم الحملة مطلوب', 422);
        }

        try {
            $campaign = $this->service->create($access['owner_id'], [
                'name' => $this->get('name'),
                'objective' => $this->get('objective'),
                'platform' => $this->get('platform'),
                'product_or_service' => $this->get('product_or_service'),
                'target_audience_brief' => $this->get('target_audience_brief'),
                'target_countries_json' => $this->get('target_countries_json'),
                'landing_page_url' => $this->get('landing_page_url'),
                'daily_budget' => $this->get('daily_budget'),
                'budget_total' => $this->get('budget_total'),
                'currency' => $this->get('currency'),
                'status' => $this->get('status'),
                'start_date' => $this->get('start_date'),
                'end_date' => $this->get('end_date'),
                'ai_generated' => $this->get('ai_generated'),
                'website_id' => $this->get('website_id'),
                'audience' => $this->get('audience'),
                'budget_recommendation' => $this->get('budget_recommendation'),
                'copies' => $this->get('copies'),
                'keywords' => $this->get('keywords'),
            ]);
            return $this->success(['campaign' => $campaign->toArray()], 'تم إنشاء الحملة كمسودة', 201);
        } catch (Exception $e) {
            Logger::error('createCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/ai-generate
     * ويزارد الحملة الاحترافي: من وصف بسيط لعرض العميل، الذكاء الاصطناعي
     * بيجهّز حزمة حملة كاملة (اسم + جمهور مستهدف + توصية ميزانية + 3
     * نصوص إعلانية مطابقة لحدود المنصات فعليًا). دي "معاينة" فقط - محفظتش
     * حاجة في قاعدة البيانات لحد ما العميل يراجعها ويأكّد الإنشاء عبر
     * POST /api/ads/campaigns العادي.
     */
    public function aiGenerateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }
        if (!$this->validate(['goal_description' => 'required', 'objective' => 'required'])) {
            return $this->error('اكتب وصف مختصر لعرضك واختار هدف الحملة', 422);
        }

        $objective = (string) $this->get('objective');
        if (!array_key_exists($objective, AdCopyGenerationService::OBJECTIVES)) {
            return $this->error('هدف الحملة غير معروف', 422);
        }

        $walletService = new WalletService();
        $priceCheck = $walletService->canAffordUsage($access['owner_id'], 'ai_ad_campaign_generation');
        if (!$priceCheck['can_afford']) {
            return $this->error('رصيدك في المحفظة مش كافي لتوليد حملة بالذكاء الاصطناعي', 402, [
                'shortfall' => $priceCheck['shortfall'] ?? null,
            ]);
        }

        try {
            $goalDescription = (string) $this->get('goal_description');
            $dailyBudget = $this->get('daily_budget');

            $service = new AdCopyGenerationService();
            $brief = $service->generateCampaignBrief($goalDescription, $objective, $dailyBudget !== null && $dailyBudget !== '' ? (float) $dailyBudget : null);

            $walletService->chargeForUsage($access['owner_id'], 'ai_ad_campaign_generation', 'توليد حملة إعلانية بالذكاء الاصطناعي');

            return $this->success([
                'brief' => $brief,
                'new_balance' => $walletService->getBalance($access['owner_id']),
            ]);
        } catch (Exception $e) {
            Logger::error('aiGenerateCampaign Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/campaigns/{id}/copies */
    public function listCopies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $items = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);
        return $this->success(['copies' => array_map(fn ($c) => $c->toArray(), $items)]);
    }

    /**
     * توليد نصوص إعلانية بالذكاء الاصطناعي لحملة موجودة.
     * POST /api/ads/campaigns/{id}/generate-copies
     *
     * ملحوظة: كانت دي الـ endpoint اللي زرار "توليد ✨" في صفحة الإعلانات
     * بينده عليها من غير ما تكون موجودة أصلاً - يعني كل ضغطة على الزرار
     * كانت بتسبب خطأ فادح فوري. الـ Service الحقيقي (AdCopyGenerationService)
     * كان مبني ومفعّل وحقيقي (Gemini فعلي) من زمان، بس مربوطش بأي controller
     * method أو route.
     */
    public function generateCopies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        try {
            $service = new AdCopyGenerationService();
            $copies = $service->generateCopies($campaign, 3);
            return $this->success(['copies' => array_map(fn ($c) => $c->toArray(), $copies)], 'تم توليد النصوص الإعلانية', 201);
        } catch (Exception $e) {
            Logger::error('generateCopies Error', ['campaign_id' => $params['id'] ?? null, 'message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** PATCH /api/ads/copies/{id}/approve - اعتماد نسخة إعلانية معيّنة كالنسخة المستخدمة فعليًا */
    public function approveCopy(array $params = []): array
    {
        return $this->updateCopyStatus($params, 'approved');
    }

    /** PATCH /api/ads/copies/{id}/reject - استبعاد نسخة إعلانية */
    public function rejectCopy(array $params = []): array
    {
        return $this->updateCopyStatus($params, 'rejected');
    }

    private function updateCopyStatus(array $params, string $status): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $copy = (new AdCopy())->find((int) ($params['id'] ?? 0));
        if (!$copy) {
            return $this->error('النسخة الإعلانية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $copy->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $copy->fill(['status' => $status]);
            $copy->save();
            return $this->success(['copy' => $copy->toArray()], $status === 'approved' ? 'تم اعتماد النسخة' : 'تم استبعاد النسخة');
        } catch (Exception $e) {
            Logger::error('updateCopyStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث حالة النسخة', 500);
        }
    }

    // ============================================
    // Meta Ads OAuth - ربط ومزامنة حقيقية مع Meta Marketing API
    // ============================================

    /** GET /ads/connect/meta */
    public function connectMeta(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $oauth = new MetaOAuthClient();
        if (!$oauth->isConfigured()) {
            $this->renderAdsOAuthError('ربط Meta Ads لسه مش مفعّل من إدارة النظام (بيانات META_APP_ID/META_APP_SECRET ناقصة في إعدادات السيرفر).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['meta_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['nonce' => $nonce], (int) ($this->user['id'] ?? 0));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /ads/connect/meta/callback */
    public function metaOAuthCallback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderAdsOAuthError('العميل رفض الموافقة أو حصل خطأ من Meta: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderAdsOAuthError('رد غير مكتمل من Meta');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['meta_oauth_nonce'] ?? null);
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderAdsOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $oauth = new MetaOAuthClient();
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderAdsOAuthError('فشل تبادل التوكن مع Meta: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $_SESSION['meta_oauth_temp'] = [
            'access_token' => $tokenResult['access_token'],
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['meta_oauth_nonce']);

        header('Location: /ads/connect/meta/choose');
        exit;
    }

    /** GET /ads/connect/meta/choose - يختار العميل حساب الإعلانات بتاعه */
    public function showMetaAdAccountPicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['meta_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /ads');
            exit;
        }

        $api = new MetaAdsAPI($temp['access_token']);
        $accountsResult = $api->listAdAccounts();

        if (!$accountsResult['success'] || empty($accountsResult['accounts'])) {
            $this->renderAdsOAuthError('مفيش حسابات إعلانات Meta مرتبطة بالحساب ده. تأكد إنك مسجّل دخول بنفس حساب Facebook اللي عليه صلاحية على حساب الإعلانات في Business Manager.<br><br>تفاصيل تقنية: ' . htmlspecialchars($accountsResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsHtml = '';
        foreach ($accountsResult['accounts'] as $acc) {
            $id = htmlspecialchars($acc['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8');
            $currency = htmlspecialchars($acc['currency'], ENT_QUOTES, 'UTF-8');
            $optionsHtml .= "<button class=\"p-btn outline\" style=\"width:100%;text-align:start;margin-bottom:8px;\" onclick=\"chooseAccount('{$id}')\">{$name} <span class=\"p-cell-muted\">({$currency})</span></button>";
        }

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>اختار حساب الإعلانات</h3><span class="p-card-sub">هنربط حملاتك الحقيقية من الحساب ده</span></div>
            <div id="accountOptions">{$optionsHtml}</div>
        </div>
HTML;

        $script = <<<'JS'
window.chooseAccount = async function (accountId) {
    const res = await window.Panel.fetchJSON('/api/ads/meta/choose-account', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
    });
    if (res.success) { window.location.href = '/ads'; }
    else { window.Panel.toast(res.error || 'تعذر الربط', 'error'); }
};
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'اختيار حساب Meta Ads', '', $body, $script);
        exit;
    }

    /** POST /api/ads/meta/choose-account */
    public function chooseMetaAdAccount(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $temp = $_SESSION['meta_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت الجلسة، ابدأ الربط تاني', 400);
        }

        $accountId = $this->get('account_id');
        if (!$accountId) {
            return $this->error('account_id مطلوب', 422);
        }

        try {
            $website = $this->firstWebsiteForUser((int) $this->user['id']);
            if (!$website) {
                return $this->error('لازم يكون عندك موقع مضاف الأول من صفحة "المواقع"', 422);
            }

            $encryption = new Encryption();
            // تصحيح أمان: التوكن كان بيتخزن كنص صريح في قاعدة البيانات من غير
            // تشفير، خلاف كل باقي التكاملات (Google, TripAdvisor...) اللي
            // بتشفّر التوكن دايمًا. اتصلح هنا ليتطابق مع باقي النظام.
            $encryptedToken = $encryption->encrypt($temp['access_token']);
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $temp['expires_in']);

            $existing = $this->db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'meta_ads' LIMIT 1",
                [$website['id']]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE platform_connections SET access_token = ?, token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                    [$encryptedToken, $expiresAt, $accountId, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO platform_connections (website_id, user_id, platform, access_token, token_expires_at, external_account_id, status)
                     VALUES (?, ?, 'meta_ads', ?, ?, ?, 'connected')",
                    [$website['id'], $this->user['id'], $encryptedToken, $expiresAt, $accountId]
                );
            }

            // ربط تلقائي لأي صفحات فيسبوك (وحسابات انستجرام بيزنس المرتبطة
            // بيها) متاحة لنفس حساب Meta ده، عشان تبقى جاهزة للنشر عليها
            // فورًا من "السوشيال ميديا" من غير خطوة ربط منفصلة تانية.
            $this->autoConnectMetaSocialPages($website['id'], (int) $this->user['id'], $temp['access_token'], $encryption);

            unset($_SESSION['meta_oauth_temp']);
            return $this->success([], 'تم ربط حساب Meta Ads والصفحات المتاحة');
        } catch (Exception $e) {
            Logger::error('chooseMetaAdAccount Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/publish
     * النشر الفعلي: بياخد الحملة المحفوظة محليًا (مسودة) والنصوص
     * المعتمدة، ويبعتها فعليًا لـ Meta Ads أو Google Ads عشان تتعمل
     * كحملة حقيقية هناك - دايمًا بحالة متوقفة (Paused) كإجراء أمان،
     * العميل لازم يراجعها ويفعّلها بنفسه من داخل حساب المنصة الرسمي.
     */
    public function publishCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $access = $this->resolveCampaignAccess($campaign, 'manager');
        if (!$access) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $platform = $campaign->getAttribute('platform');
        if (!in_array($platform, ['meta_ads', 'google_ads'], true)) {
            return $this->error('الحملة دي يدوية (تتبع فقط) - مفيش منصة إعلانات مرتبطة بيها للنشر عليها', 422);
        }

        if (!empty($campaign->getAttribute('external_campaign_id'))) {
            return $this->error('الحملة دي منشورة بالفعل على المنصة', 422);
        }

        $approvedCopies = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id'), 'status' => 'approved']);
        if (empty($approvedCopies)) {
            return $this->error('لازم تعتمد نسخة إعلانية واحدة على الأقل قبل النشر (زرار "اعتماد" تحت النصوص)', 422);
        }
        $copiesData = array_map(fn ($c) => $c->toArray(), $approvedCopies);

        $website = (new Website())->find((int) $campaign->getAttribute('website_id'));
        $destinationUrl = $website ? trim((string) $website->getAttribute('main_url')) : '';
        if ($destinationUrl === '') {
            return $this->error('محتاج رابط موقع صحيح مربوط بحسابك عشان الإعلان يوصّل الزوار له', 422);
        }
        if (!preg_match('#^https?://#i', $destinationUrl)) {
            $destinationUrl = 'https://' . $destinationUrl;
        }

        try {
            $connection = $this->db->query(
                "SELECT * FROM platform_connections WHERE user_id = ? AND platform = ? AND status = 'connected' LIMIT 1",
                [(int) $campaign->getAttribute('user_id'), $platform]
            );
            if (empty($connection)) {
                return $this->error('لازم تربط حساب ' . ($platform === 'meta_ads' ? 'Meta Ads' : 'Google Ads') . ' الأول من أعلى الصفحة', 422);
            }
            $conn = $connection[0];
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            $campaignPayload = [
                'name' => $campaign->getAttribute('name'),
                'objective' => $campaign->getAttribute('objective') ?: 'traffic',
                'daily_budget' => $campaign->getAttribute('daily_budget') ?: 10,
                'start_date' => $campaign->getAttribute('start_date'),
                'end_date' => $campaign->getAttribute('end_date'),
            ];

            if ($platform === 'meta_ads') {
                $pages = $this->db->query(
                    "SELECT external_location_id, external_location_name FROM platform_connections
                     WHERE website_id = ? AND platform = 'facebook' AND status = 'connected'",
                    [$conn['website_id']]
                );
                if (empty($pages)) {
                    return $this->error('محتاج صفحة فيسبوك مربوطة عشان تظهر عليها الإعلانات - اتأكد إن عندك صفحة فيسبوك أدمن عليها وأعد ربط Meta Ads من جديد', 422);
                }

                $pageId = $this->get('page_id');
                if (!$pageId) {
                    if (count($pages) === 1) {
                        $pageId = $pages[0]['external_location_id'];
                    } else {
                        // أكتر من صفحة - محتاجين العميل يختار، بنرجّع القائمة عشان الواجهة تعرضها
                        return $this->error('عندك أكتر من صفحة فيسبوك - اختار واحدة للنشر عليها', 409, [
                            'pages' => array_map(fn ($p) => ['id' => $p['external_location_id'], 'name' => $p['external_location_name']], $pages),
                        ]);
                    }
                }

                $audienceRows = (new AdAudience())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $audienceRow = !empty($audienceRows) ? $audienceRows[0]->toArray() : [];
                $audience = [
                    'age_min' => $audienceRow['age_min'] ?? 18,
                    'age_max' => $audienceRow['age_max'] ?? 65,
                    'genders' => $audienceRow['genders'] ?? 'all',
                    'locations' => !empty($audienceRow['locations_json']) ? (json_decode($audienceRow['locations_json'], true) ?: []) : [],
                ];

                $api = new MetaAdsAPI($accessToken);
                $imageUrl = $api->fetchOgImageFromWebsite($destinationUrl); // best-effort - ممكن ترجع null وده مقبول
                $result = $api->createCampaign($conn['external_account_id'], $pageId, $campaignPayload, $audience, $copiesData, $destinationUrl, $imageUrl);
            } else {
                $keywordRows = (new AdKeyword())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $keywords = array_map(fn ($k) => ['keyword' => $k->getAttribute('keyword'), 'match_type' => $k->getAttribute('match_type')], $keywordRows);

                $budgetRecRows = (new AdBudgetRecommendation())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $bidStrategyHint = !empty($budgetRecRows) ? (string) $budgetRecRows[0]->getAttribute('bid_strategy') : '';

                $api = new GoogleAdsAPI($accessToken);
                $result = $api->createSearchCampaign($conn['external_account_id'], $campaignPayload, $copiesData, $keywords, $destinationUrl, $bidStrategyHint);
            }

            if (!($result['success'] ?? false)) {
                // لو اتعمل جزء من الحملة على المنصة (external_campaign_id راجع) بنسجّله برضه، عشان العميل يلاقيها ويكمّلها يدويًا بدل ما تتوه
                if (!empty($result['external_campaign_id'])) {
                    $campaign->fill([
                        'external_campaign_id' => $result['external_campaign_id'],
                        'external_adset_id' => $result['external_adset_id'] ?? null,
                        'external_budget_resource' => $result['external_budget_resource'] ?? null,
                        'platform_connection_id' => $conn['id'],
                    ]);
                    $campaign->save();
                }
                return $this->error($result['error'] ?? 'فشل النشر على المنصة', 502);
            }

            $campaign->fill([
                'external_campaign_id' => $result['external_campaign_id'],
                'external_adset_id' => $result['external_adset_id'] ?? null,
                'external_budget_resource' => $result['external_budget_resource'] ?? null,
                'platform_connection_id' => $conn['id'],
                'status' => 'paused',
                'published_at' => date('Y-m-d H:i:s'),
            ]);
            $campaign->save();

            ActivityLog::record('ads', 'ad_campaign.published', [
                'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                'meta' => ['platform' => $platform, 'external_campaign_id' => $result['external_campaign_id']],
            ]);

            return $this->success([
                'campaign' => $campaign->toArray(),
            ], 'تم إنشاء الحملة فعليًا على ' . ($platform === 'meta_ads' ? 'Meta Ads' : 'Google Ads') . ' بحالة متوقفة - راجعها وفعّلها من حسابك الرسمي هناك');
        } catch (Exception $e) {
            Logger::error('publishCampaign Error', ['campaign_id' => $params['id'] ?? null, 'message' => $e->getMessage()]);
            return $this->error('تعذر النشر: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/toggle-status
     * تشغيل/إيقاف حملة منشورة فعليًا على المنصة (Meta أو Google) - بيغيّر
     * الحالة هناك مباشرة، مش بس محليًا، عشان الإنفاق الفعلي يتأثر فورًا.
     */
    public function toggleCampaignStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) {
            return $err;
        }

        $newStatus = $campaign->getAttribute('status') === 'active' ? 'paused' : 'active';

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $api->updateCampaignStatus((string) $campaign->getAttribute('external_campaign_id'), $newStatus === 'active' ? 'ACTIVE' : 'PAUSED');
            } else {
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->updateCampaignStatus($conn['external_account_id'], (string) $campaign->getAttribute('external_campaign_id'), $newStatus === 'active' ? 'ENABLED' : 'PAUSED');
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل تعديل حالة الحملة على المنصة', 502);
            }

            $campaign->fill(['status' => $newStatus]);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], $newStatus === 'active' ? 'تم تشغيل الحملة' : 'تم إيقاف الحملة');
        } catch (Exception $e) {
            Logger::error('toggleCampaignStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الحالة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/cancel
     * إلغاء حملة منشورة نهائيًا على المنصة (أرشفة على Meta، أو status=REMOVED على Google).
     */
    public function cancelCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) {
            return $err;
        }

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $api->deleteCampaign((string) $campaign->getAttribute('external_campaign_id'));
            } else {
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->deleteCampaign($conn['external_account_id'], (string) $campaign->getAttribute('external_campaign_id'));
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل إلغاء الحملة على المنصة', 502);
            }

            $campaign->fill(['status' => 'removed']);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], 'تم إلغاء الحملة');
        } catch (Exception $e) {
            Logger::error('cancelCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إلغاء الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/update-budget
     * تعديل الميزانية اليومية لحملة منشورة بالفعل - محتاج البيانات المحفوظة
     * وقت النشر (external_adset_id لـ Meta، external_budget_resource لـ Google).
     */
    public function updateCampaignBudget(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) {
            return $err;
        }

        $newBudget = (float) $this->get('daily_budget');
        if ($newBudget <= 0) {
            return $this->error('الميزانية لازم تكون أكبر من صفر', 422);
        }

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                if (empty($campaign->getAttribute('external_adset_id'))) {
                    return $this->error('مفيش معرّف مجموعة إعلانية محفوظ لهذه الحملة - راجعها يدويًا من Meta Ads Manager', 422);
                }
                $api = new MetaAdsAPI($accessToken);
                $result = $api->updateAdSetBudget((string) $campaign->getAttribute('external_adset_id'), $newBudget);
            } else {
                if (empty($campaign->getAttribute('external_budget_resource'))) {
                    return $this->error('مفيش معرّف ميزانية محفوظ لهذه الحملة - راجعها يدويًا من Google Ads', 422);
                }
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->updateBudget((string) $campaign->getAttribute('external_budget_resource'), $newBudget);
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل تعديل الميزانية على المنصة', 502);
            }

            $campaign->fill(['daily_budget' => $newBudget]);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], 'تم تعديل الميزانية اليومية');
        } catch (Exception $e) {
            Logger::error('updateCampaignBudget Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الميزانية', 500);
        }
    }

    /**
     * يحمّل حملة منشورة فعليًا مع بيانات ربطها للتعامل الإداري (إيقاف/تشغيل/إلغاء/تعديل ميزانية).
     * @return array{0: ?AdCampaign, 1: ?array, 2: ?array} [الحملة, صف الربط, رد خطأ لو فيه مشكلة]
     */
    private function loadPublishedCampaignForManagement(int $campaignId): array
    {
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign) {
            return [null, null, $this->error('الحملة غير موجودة', 404)];
        }
        $access = $this->resolveCampaignAccess($campaign, 'manager');
        if (!$access) {
            return [null, null, $this->error('الحملة غير موجودة', 404)];
        }

        if (empty($campaign->getAttribute('external_campaign_id'))) {
            return [null, null, $this->error('الحملة دي لسه مسودة محلية - مش منشورة على أي منصة', 422)];
        }

        $connRows = $this->db->query(
            "SELECT * FROM platform_connections WHERE id = ? AND status = 'connected' LIMIT 1",
            [$campaign->getAttribute('platform_connection_id')]
        );
        if (empty($connRows)) {
            return [null, null, $this->error('الربط بالمنصة اتفصل - أعد الربط الأول', 422)];
        }

        return [$campaign, $connRows[0], null];
    }

    /**
     * يجيب كل صفحات فيسبوك (وانستجرام المرتبط بيها) المتاحة لتوكن
     * المستخدم، ويحفظهم كاتصالات منصة جاهزة للنشر (platform='facebook'
     * لكل صفحة، وplatform='instagram' لو فيها حساب بيزنس مرتبط).
     */
    private function autoConnectMetaSocialPages(int $websiteId, int $userId, string $userAccessToken, Encryption $encryption): void
    {
        try {
            $api = new MetaSocialAPI($userAccessToken);
            $pagesResult = $api->listPages();

            if (!$pagesResult['success']) {
                Logger::warning('Auto-connect Meta pages skipped', ['error' => $pagesResult['error'] ?? '']);
                return;
            }

            foreach ($pagesResult['pages'] as $page) {
                if (empty($page['access_token'])) {
                    continue;
                }
                $encryptedPageToken = $encryption->encrypt($page['access_token']);

                // صفحة الفيسبوك نفسها
                $this->upsertSocialConnection($websiteId, $userId, 'facebook', $page['id'], $page['name'], $encryptedPageToken);

                // حساب انستجرام بيزنس المرتبط بالصفحة (لو موجود)
                if (!empty($page['instagram_id'])) {
                    $this->upsertSocialConnection(
                        $websiteId,
                        $userId,
                        'instagram',
                        $page['instagram_id'],
                        $page['instagram_username'] ?? $page['name'],
                        $encryptedPageToken
                    );
                }
            }
        } catch (Exception $e) {
            Logger::error('autoConnectMetaSocialPages Error', ['message' => $e->getMessage()]);
        }
    }

    private function upsertSocialConnection(int $websiteId, int $userId, string $platform, string $externalId, string $name, string $encryptedToken): void
    {
        $existing = $this->db->query(
            "SELECT id FROM platform_connections WHERE website_id = ? AND platform = ? AND external_location_id = ? LIMIT 1",
            [$websiteId, $platform, $externalId]
        );

        if (!empty($existing)) {
            $this->db->exec(
                "UPDATE platform_connections SET access_token = ?, external_location_name = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                [$encryptedToken, $name, $existing[0]['id']]
            );
        } else {
            $this->db->exec(
                "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_location_id, external_location_name, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'connected')",
                [$websiteId, $userId, $platform, $encryptedToken, $externalId, $name]
            );
        }
    }

    /** GET /api/ads/meta/status */
    public function getMetaConnectionStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $oauth = new MetaOAuthClient();
        if (!$oauth->isConfigured()) {
            return $this->success(['configured' => false, 'connected' => false]);
        }

        try {
            $row = $this->db->query(
                "SELECT external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'meta_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($row)) {
                return $this->success(['configured' => true, 'connected' => false]);
            }

            return $this->success([
                'configured' => true,
                'connected' => true,
                'external_account_id' => $row[0]['external_account_id'],
            ]);
        } catch (Exception $e) {
            Logger::error('getMetaConnectionStatus Error', ['message' => $e->getMessage()]);
            return $this->success(['configured' => true, 'connected' => false]);
        }
    }

    /** POST /api/ads/meta/sync - سحب حملات حقيقية من Meta وتحديث ad_campaigns */
    public function syncMetaCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $connection = $this->db->query(
                "SELECT id, website_id, access_token, external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'meta_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($connection)) {
                return $this->error('مفيش حساب Meta Ads مربوط', 400);
            }

            $conn = $connection[0];
            $decryptedToken = (new Encryption())->decrypt($conn['access_token']);
            $api = new MetaAdsAPI($decryptedToken);
            $result = $api->listCampaignsWithInsights($conn['external_account_id']);

            if (!$result['success']) {
                $this->db->exec(
                    "UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?",
                    [$result['error'] ?? 'unknown error', $conn['id']]
                );
                return $this->error('تعذرت المزامنة مع Meta: ' . ($result['error'] ?? ''), 502);
            }

            $synced = 0;
            foreach ($result['campaigns'] as $c) {
                $existing = $this->db->query(
                    "SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1",
                    [$access['owner_id'], $c['external_campaign_id']]
                );

                if (!empty($existing)) {
                    $this->db->exec(
                        "UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = ?, clicks = ?, spend = ?, started_at = ?, ended_at = ?, updated_at = NOW()
                         WHERE id = ?",
                        [$c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at'], $existing[0]['id']]
                    );
                } else {
                    $this->db->exec(
                        "INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, name, objective, daily_budget, status, external_campaign_id, impressions, clicks, spend, started_at, ended_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$access['owner_id'], $conn['website_id'], $conn['id'], $c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['external_campaign_id'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at']]
                    );
                }
                $synced++;
            }

            $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);

            return $this->success(['synced' => $synced]);
        } catch (Exception $e) {
            Logger::error('syncMetaCampaigns Error', ['message' => $e->getMessage()]);
            return $this->error('تعذرت المزامنة', 500);
        }
    }

    /** POST /api/ads/meta/disconnect */
    public function disconnectMeta(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $this->db->exec(
                "UPDATE platform_connections SET status = 'disconnected', access_token = NULL WHERE user_id = ? AND platform = 'meta_ads'",
                [$access['owner_id']]
            );
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('disconnectMeta Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الفصل', 500);
        }
    }

    /** GET /ads/connect/google */
    public function connectGoogleAds(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, $this->googleAdsRedirectUri());
        if (!$oauth->isConfigured()) {
            $this->renderAdsOAuthError('ربط Google Ads لسه مش مفعّل من إدارة النظام (بيانات GOOGLE_CLIENT_ID/SECRET أو GOOGLE_ADS_OAUTH_REDIRECT_URI ناقصة في إعدادات السيرفر).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['google_ads_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['nonce' => $nonce], (int) ($this->user['id'] ?? 0));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /ads/connect/google/callback */
    public function googleAdsOAuthCallback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderAdsOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderAdsOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['google_ads_oauth_nonce'] ?? null);
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderAdsOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, $this->googleAdsRedirectUri());
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderAdsOAuthError('فشل تبادل التوكن مع Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (empty($tokenResult['refresh_token'])) {
            $this->renderAdsOAuthError('Google ما رجعش refresh_token (محتاج تفصل أي ربط سابق لنفس الحساب من "Third-party apps & services" في إعدادات جوجل بتاعتك، ثم تحاول الربط تاني).');
            exit;
        }

        $_SESSION['google_ads_oauth_temp'] = [
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'],
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['google_ads_oauth_nonce']);

        header('Location: /ads/connect/google/choose');
        exit;
    }

    /** GET /ads/connect/google/choose - يختار العميل حساب Google Ads بتاعه */
    public function showGoogleAdsAccountPicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['google_ads_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /ads');
            exit;
        }

        $api = new GoogleAdsAPI($temp['access_token']);
        $accountsResult = $api->listAdAccounts();
        if (!$accountsResult['success'] || empty($accountsResult['accounts'])) {
            $this->renderAdsOAuthError('مفيش حسابات Google Ads متاحة للحساب ده. تأكد إن عندك صلاحية على حساب إعلانات Google Ads بنفس الإيميل ده.<br><br>تفاصيل تقنية: ' . htmlspecialchars($accountsResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsHtml = '';
        foreach ($accountsResult['accounts'] as $acc) {
            $id = htmlspecialchars($acc['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8');
            $currency = htmlspecialchars($acc['currency'], ENT_QUOTES, 'UTF-8');
            $optionsHtml .= "<button class=\"p-btn outline\" style=\"width:100%;text-align:start;margin-bottom:8px;\" onclick=\"chooseGoogleAdsAccountBtn('{$id}')\">{$name} <span class=\"p-cell-muted\">({$currency})</span></button>";
        }

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>اختار حساب Google Ads</h3><span class="p-card-sub">هنربط حملاتك الحقيقية من الحساب ده</span></div>
            <div id="accountOptions">{$optionsHtml}</div>
        </div>
HTML;

        $script = <<<'JS'
window.chooseGoogleAdsAccountBtn = async function (accountId) {
    const res = await window.Panel.fetchJSON('/api/ads/google/choose-account', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
    });
    if (res.success) { window.location.href = '/ads'; }
    else { window.Panel.toast(res.error || 'تعذر الربط', 'error'); }
};
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'اختيار حساب Google Ads', '', $body, $script);
        exit;
    }

    /** POST /api/ads/google/choose-account */
    public function chooseGoogleAdsAccount(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $temp = $_SESSION['google_ads_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت الجلسة، ابدأ الربط تاني', 400);
        }

        $accountId = $this->get('account_id');
        if (!$accountId) {
            return $this->error('account_id مطلوب', 422);
        }

        try {
            $website = $this->firstWebsiteForUser((int) $this->user['id']);
            if (!$website) {
                return $this->error('لازم يكون عندك موقع مضاف الأول من صفحة "المواقع"', 422);
            }

            $encryption = new Encryption();
            $encryptedAccess = $encryption->encrypt($temp['access_token']);
            $encryptedRefresh = $encryption->encrypt($temp['refresh_token']);
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $temp['expires_in']);

            $existing = $this->db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'google_ads' LIMIT 1",
                [$website['id']]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE platform_connections SET access_token = ?, refresh_token = ?, token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                    [$encryptedAccess, $encryptedRefresh, $expiresAt, $accountId, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO platform_connections (website_id, user_id, platform, access_token, refresh_token, token_expires_at, external_account_id, status)
                     VALUES (?, ?, 'google_ads', ?, ?, ?, ?, 'connected')",
                    [$website['id'], $this->user['id'], $encryptedAccess, $encryptedRefresh, $expiresAt, $accountId]
                );
            }

            unset($_SESSION['google_ads_oauth_temp']);
            return $this->success([], 'تم ربط حساب Google Ads');
        } catch (Exception $e) {
            Logger::error('chooseGoogleAdsAccount Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** GET /api/ads/google/status */
    public function getGoogleAdsConnectionStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $configured = (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, $this->googleAdsRedirectUri()))->isConfigured()
            && $this->googleAdsRedirectUri() !== '';

        if (!$configured) {
            return $this->success(['configured' => false, 'connected' => false]);
        }

        try {
            $row = $this->db->query(
                "SELECT external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'google_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($row)) {
                return $this->success(['configured' => true, 'connected' => false]);
            }

            return $this->success([
                'configured' => true,
                'connected' => true,
                'external_account_id' => $row[0]['external_account_id'],
            ]);
        } catch (Exception $e) {
            Logger::error('getGoogleAdsConnectionStatus Error', ['message' => $e->getMessage()]);
            return $this->success(['configured' => true, 'connected' => false]);
        }
    }

    /**
     * بيرجّع access_token صالح (يجدّده عبر refresh_token المخزّن لو قرب
     * ينتهي)، ويحدّث platform_connections لو حصل تجديد. Google Ads access
     * token عمره ساعة تقريبًا، على عكس Meta اللي بيدي توكن طويل العمر
     * (60 يوم) - عشان كده Meta مش محتاجة نفس منطق التجديد ده حاليًا.
     */
    private function getValidGoogleAdsAccessToken(array $conn, Encryption $encryption): ?string
    {
        $expiresAt = $conn['token_expires_at'] ?? null;
        $stillValid = $expiresAt && strtotime($expiresAt) > (time() + 120);

        $accessToken = $encryption->decrypt((string) $conn['access_token']);
        if ($stillValid) {
            return $accessToken;
        }

        $refreshToken = $encryption->decrypt((string) $conn['refresh_token']);
        if ($refreshToken === '') {
            return null;
        }

        $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, $this->googleAdsRedirectUri());
        $refreshed = $oauth->refreshAccessToken($refreshToken);
        if (!$refreshed['success']) {
            $this->db->exec("UPDATE platform_connections SET status = 'token_expired', last_error = ? WHERE id = ?", [$refreshed['error'] ?? 'refresh failed', $conn['id']]);
            return null;
        }

        $newAccessToken = $refreshed['access_token'];
        $newExpiresAt = date('Y-m-d H:i:s', time() + (int) $refreshed['expires_in']);
        $this->db->exec(
            "UPDATE platform_connections SET access_token = ?, token_expires_at = ? WHERE id = ?",
            [$encryption->encrypt($newAccessToken), $newExpiresAt, $conn['id']]
        );

        return $newAccessToken;
    }

    /** POST /api/ads/google/sync - سحب حملات حقيقية من Google Ads وتحديث ad_campaigns */
    public function syncGoogleAdsCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $connection = $this->db->query(
                "SELECT id, website_id, access_token, refresh_token, token_expires_at, external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'google_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($connection)) {
                return $this->error('مفيش حساب Google Ads مربوط', 400);
            }

            $conn = $connection[0];
            $encryption = new Encryption();
            $accessToken = $this->getValidGoogleAdsAccessToken($conn, $encryption);
            if (!$accessToken) {
                return $this->error('انتهت صلاحية الربط، محتاج تربط حساب Google Ads تاني', 400);
            }

            $api = new GoogleAdsAPI($accessToken);
            $result = $api->listCampaignsWithInsights($conn['external_account_id']);
            if (!$result['success']) {
                $this->db->exec("UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?", [$result['error'] ?? 'unknown error', $conn['id']]);
                if (class_exists('Notification')) {
                    Notification::notify($access['owner_id'], 'ads_integration_error', 'تعذّرت مزامنة Google Ads', (string) ($result['error'] ?? ''), '/ads/connections');
                }
                return $this->error('تعذرت المزامنة مع Google Ads: ' . ($result['error'] ?? ''), 502);
            }

            $synced = 0;
            foreach ($result['campaigns'] as $c) {
                $existing = $this->db->query(
                    "SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1",
                    [$access['owner_id'], $c['external_campaign_id']]
                );

                if (!empty($existing)) {
                    $this->db->exec(
                        "UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = ?, clicks = ?, spend = ?, external_budget_resource_name = ?, updated_at = NOW() WHERE id = ?",
                        [$c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['impressions'], $c['clicks'], $c['spend'], $c['budget_resource_name'], $existing[0]['id']]
                    );
                } else {
                    $this->db->exec(
                        "INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, name, objective, daily_budget, status, external_campaign_id, external_budget_resource_name, impressions, clicks, spend)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$access['owner_id'], $conn['website_id'], $conn['id'], $c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['external_campaign_id'], $c['budget_resource_name'], $c['impressions'], $c['clicks'], $c['spend']]
                    );
                }
                $synced++;
            }

            $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);

            return $this->success(['synced' => $synced]);
        } catch (Exception $e) {
            Logger::error('syncGoogleAdsCampaigns Error', ['message' => $e->getMessage()]);
            return $this->error('تعذرت المزامنة', 500);
        }
    }

    /** POST /api/ads/google/disconnect */
    public function disconnectGoogleAds(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $this->db->exec(
                "UPDATE platform_connections SET status = 'disconnected', access_token = NULL, refresh_token = NULL WHERE user_id = ? AND platform = 'google_ads'",
                [$access['owner_id']]
            );
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('disconnectGoogleAds Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الفصل', 500);
        }
    }

    // ================================================================
    // AI Ads Autopilot - Guardrails / Pending Approvals / Log / Rollback
    // ================================================================

    /** GET /api/ads/autopilot/settings */
    public function getAutopilotSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $settings = $engine->getSettings($access['owner_id']);

        return $this->success([
            'optimization_mode' => $settings->getAttribute('optimization_mode'),
            'max_daily_budget' => $settings->getAttribute('max_daily_budget'),
            'max_budget_increase_pct' => $settings->getAttribute('max_budget_increase_pct'),
            'max_budget_decrease_pct' => $settings->getAttribute('max_budget_decrease_pct'),
            'max_allowed_cpa' => $settings->getAttribute('max_allowed_cpa'),
            'min_required_roas' => $settings->getAttribute('min_required_roas'),
            'max_changes_per_day' => $settings->getAttribute('max_changes_per_day'),
        ]);
    }

    /** POST /api/ads/autopilot/settings */
    public function saveAutopilotSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('محتاج صلاحية Admin لتعديل إعدادات Autopilot (بيتحكم في إنفاق تلقائي حقيقي)', 403);
        }

        try {
            $engine = new AdAutopilotEngine();
            $engine->saveSettings($access['owner_id'], $this->all());
            return $this->success([], 'تم حفظ الإعدادات');
        } catch (Exception $e) {
            Logger::error('saveAutopilotSettings Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** GET /api/ads/autopilot/pending */
    public function listPendingActions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $rows = AdPendingAction::pendingForUser($access['owner_id']);
        return $this->success(array_map(fn ($p) => $p->toArray(), $rows));
    }

    /** POST /api/ads/autopilot/pending/{id}/approve */
    public function approvePendingAction(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $result = $engine->approvePendingAction($access['owner_id'], (int) $params['id']);

        if (($result['status'] ?? '') === 'not_found') {
            return $this->error('القرار غير موجود أو تم اتخاذ قرار بشأنه بالفعل', 404);
        }
        if (($result['status'] ?? '') === 'executed') {
            return $this->success($result, 'تم التنفيذ فعليًا');
        }
        return $this->success($result, 'تم تسجيل القرار');
    }

    /** POST /api/ads/autopilot/pending/{id}/reject */
    public function rejectPendingAction(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $ok = $engine->rejectPendingAction($access['owner_id'], (int) $params['id']);

        return $ok ? $this->success([], 'تم الرفض') : $this->error('القرار غير موجود أو تم اتخاذ قرار بشأنه بالفعل', 404);
    }

    /** GET /api/ads/autopilot/logs */
    public function listOptimizationLogs(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $campaignId = $this->get('campaign_id');
        if ($campaignId) {
            $rows = (new AdOptimizationLog())->where(['user_id' => $access['owner_id'], 'campaign_id' => (int) $campaignId], ['created_at' => 'DESC'], 50);
        } else {
            $rows = AdOptimizationLog::forUser($access['owner_id'], 50);
        }
        return $this->success(array_map(fn ($l) => $l->toArray(), $rows));
    }

    /** POST /api/ads/autopilot/logs/{id}/rollback */
    public function rollbackOptimizationLog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $result = $engine->rollback($access['owner_id'], (int) $params['id']);

        if (($result['status'] ?? '') === 'not_found') {
            return $this->error('السجل غير موجود', 404);
        }
        if (($result['status'] ?? '') === 'not_rollbackable') {
            return $this->error('التغيير ده مش قابل للتراجع (إما مش منفّذ فعليًا أو اتراجع عنه قبل كده)', 422);
        }
        if (($result['status'] ?? '') === 'executed') {
            return $this->success($result, 'تم التراجع بنجاح');
        }

        return $this->error('تعذر التراجع: ' . ($result['error'] ?? 'خطأ غير معروف'), 502);
    }

    /** POST /api/ads/autopilot/run - تشغيل يدوي فوري (نفس اللي بيحصل من الـ cron الدوري) */
    public function runAutopilotNow(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $campaigns = (new AdCampaign())->where(['user_id' => $access['owner_id'], 'status' => 'active', 'auto_optimize' => 1]);

        $results = [];
        foreach ($campaigns as $campaign) {
            $results[] = ['campaign_id' => $campaign->getAttribute('id'), 'result' => $engine->processCampaign($access['owner_id'], $campaign)];
        }

        return $this->success($results);
    }

    // ================================================================
    // Proactive Alerts (تنبيهات استباقية)
    // ================================================================

    /** GET /api/ads/alerts/rules */
    public function getAlertRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAlertService();
        return $this->success(['rules' => $service->getRules($access['owner_id'])]);
    }

    /** POST /api/ads/alerts/rules */
    public function saveAlertRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $service = new AdAlertService();
            $rules = $service->saveRules($access['owner_id'], $this->all());
            return $this->success(['rules' => $rules], 'تم حفظ قواعد التنبيهات');
        } catch (Exception $e) {
            Logger::error('saveAlertRules Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** GET /api/ads/alerts */
    public function listAlerts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $limit = max(1, min(200, (int) $this->get('limit', 50)));
        $unreadOnly = (bool) $this->get('unread_only', false);

        $service = new AdAlertService();
        return $this->success([
            'alerts' => $service->listForUser($access['owner_id'], $limit, $unreadOnly),
            'unread_count' => $service->unreadCount($access['owner_id']),
        ]);
    }

    /** POST /api/ads/alerts/run - تقييم فوري لكل الحملات النشطة */
    public function runAlertsNow(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $service = new AdAlertService();
            $result = $service->evaluateForUser($access['owner_id']);
            return $this->success($result, 'تم التقييم');
        } catch (Exception $e) {
            Logger::error('runAlertsNow Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التقييم', 500);
        }
    }

    /** POST /api/ads/alerts/read-all */
    public function markAllAlertsRead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAlertService();
        $service->markAllRead($access['owner_id']);
        return $this->success([], 'تم تعليم الكل كمقروء');
    }

    /** POST /api/ads/alerts/{id}/dismiss */
    public function dismissAlert(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAlertService();
        $ok = $service->dismiss($access['owner_id'], (int) ($params['id'] ?? 0));
        return $ok ? $this->success([], 'تم تجاهل التنبيه') : $this->error('التنبيه غير موجود', 404);
    }

    // ================================================================
    // AI Marketing Copilot
    // ================================================================

    /** POST /api/ads/copilot/ask */
    public function askCopilot(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $message = trim((string) $this->get('message', ''));
        if ($message === '') {
            return $this->error('اكتب سؤال أو طلب الأول', 422);
        }

        try {
            $copilot = new AdsCopilotService();
            $result = $copilot->ask($access['owner_id'], $message);
            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('askCopilot Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر معالجة الطلب', 500);
        }
    }

    // ================================================================
    // AI Keyword Strategist (البند 6)
    // ================================================================

    /** POST /api/ads/campaigns/{id}/keywords/generate */
    public function generateKeywords(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $goalDescription = trim((string) $this->get('goal_description', (string) $campaign->getAttribute('product_or_service')));
        if ($goalDescription === '') {
            return $this->error('اكتب وصف مختصر للعرض الأول', 422);
        }

        try {
            $service = new AdKeywordStrategistService();
            $result = $service->generateForCampaign($campaign, $goalDescription, $this->get('target_country'));
            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('generateKeywords Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/campaigns/{id}/keywords */
    public function listKeywords(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $keywords = (new AdKeyword())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);
        return $this->success(array_map(fn ($k) => $k->toArray(), $keywords));
    }

    // ================================================================
    // Ad Groups (البند 6) - تنظيم محلي داخل Tourfecto، راجع ملحوظة
    // migration 2026_08_11_000044 عن النطاق (مش مزامنة حقيقية مع Ad
    // Set/Ad Group على Meta/Google - العميل عنده حرية التنظيم الداخلي بس)
    // ================================================================

    /** POST /api/ads/campaigns/{id}/ad-groups */
    public function createAdGroup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $name = trim((string) $this->get('name', ''));
        if ($name === '') {
            return $this->error('اسم المجموعة الإعلانية مطلوب', 422);
        }

        $budgetPct = $this->get('budget_allocation_pct');

        $group = new AdAdGroup([
            'campaign_id' => (int) $campaign->getAttribute('id'),
            'name' => mb_substr($name, 0, 255),
            'status' => 'active',
            'budget_allocation_pct' => ($budgetPct !== null && $budgetPct !== '') ? (float) $budgetPct : null,
        ]);
        $group->save();

        ActivityLog::record('ads_autopilot', 'ad_group.created', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_ad_groups', 'subject_id' => (int) $group->getAttribute('id'),
            'meta' => ['campaign_id' => (int) $campaign->getAttribute('id')],
        ]);

        return $this->success($group->toArray(), 'تم إنشاء المجموعة الإعلانية');
    }

    /** GET /api/ads/campaigns/{id}/ad-groups - مع عدد الكلمات/الإعلانات المرتبطة بكل مجموعة */
    public function listAdGroups(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $groups = (new AdAdGroup())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);

        $result = array_map(function ($g) {
            $groupId = (int) $g->getAttribute('id');
            $keywordCount = count((new AdKeyword())->where(['ad_group_id' => $groupId]));
            $copyCount = count((new AdCopy())->where(['ad_group_id' => $groupId]));
            $data = $g->toArray();
            $data['keywords_count'] = $keywordCount;
            $data['ads_count'] = $copyCount;
            return $data;
        }, $groups);

        // ملحوظة صراحة: مفيش "Performance" مستوى المجموعة - ad_performance_reports
        // بيانات على مستوى الحملة بس من المزامنة الحالية، مش مقسّمة لكل Ad Group.
        return $this->success(['ad_groups' => $result, 'performance_note' => 'بيانات الأداء متاحة على مستوى الحملة بس حاليًا، مش مقسّمة لكل مجموعة إعلانية']);
    }

    /** POST /api/ads/ad-groups/{id}/status */
    public function updateAdGroupStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $group = (new AdAdGroup())->find((int) ($params['id'] ?? 0));
        if (!$group) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $group->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $status = $this->get('status');
        if (!in_array($status, ['active', 'paused'], true)) {
            return $this->error('status لازم يكون active أو paused', 422);
        }

        $group->setAttribute('status', $status);
        $group->save();

        return $this->success($group->toArray());
    }

    /** DELETE /api/ads/ad-groups/{id} - الكلمات/الإعلانات المرتبطة بترجع ad_group_id=NULL (مش بتتحذف) */
    public function deleteAdGroup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $group = (new AdAdGroup())->find((int) ($params['id'] ?? 0));
        if (!$group) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $group->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $this->db->exec("DELETE FROM ad_ad_groups WHERE id = ?", [(int) $group->getAttribute('id')]);

        return $this->success([], 'تم حذف المجموعة الإعلانية');
    }

    /** POST /api/ads/keywords/{id}/assign-group - ربط/فك ربط كلمة مفتاحية بمجموعة إعلانية */
    public function assignKeywordToGroup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $keyword = (new AdKeyword())->find((int) ($params['id'] ?? 0));
        if (!$keyword) {
            return $this->error('الكلمة المفتاحية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $keyword->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الكلمة المفتاحية غير موجودة', 404);
        }

        $adGroupId = $this->get('ad_group_id');
        if ($adGroupId) {
            $group = (new AdAdGroup())->find((int) $adGroupId);
            if (!$group || (int) $group->getAttribute('campaign_id') !== (int) $campaign->getAttribute('id')) {
                return $this->error('المجموعة الإعلانية غير موجودة أو مش تابعة لنفس الحملة', 422);
            }
        }

        $keyword->setAttribute('ad_group_id', $adGroupId ?: null);
        $keyword->save();

        return $this->success($keyword->toArray());
    }

    // ================================================================
    // AI Market / Country Research (البند 5)
    // ================================================================

    /** POST /api/ads/market-research */
    public function marketResearch(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $goalDescription = trim((string) $this->get('goal_description', ''));
        if ($goalDescription === '') {
            return $this->error('اكتب وصف مختصر لعرضك الأول', 422);
        }

        $campaignId = $this->get('campaign_id');
        if ($campaignId) {
            $campaign = (new AdCampaign())->find((int) $campaignId);
            if (!$campaign || (int) $campaign->getAttribute('user_id') !== $access['owner_id']) {
                return $this->error('الحملة غير موجودة', 404);
            }
        }

        try {
            $service = new AdMarketResearchService();
            $result = $service->research($access['owner_id'], $goalDescription, $campaignId ? (int) $campaignId : null);
            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('marketResearch Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/market-research/history */
    public function marketResearchHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdMarketResearchService();
        $rows = $service->history($access['owner_id']);

        return $this->success(array_map(function ($r) {
            $r['result_json'] = json_decode((string) $r['result_json'], true);
            return $r;
        }, $rows));
    }

    // ================================================================
    // Landing Page Analysis (البند 17)
    // ================================================================

    /**
     * POST /api/ads/campaigns/{id}/status - إيقاف/استئناف يدوي مباشر من
     * العميل نفسه. مُختلف عن AdAutopilotEngine::execute() عمدًا: ده فعل
     * بشري صريح على حملة العميل نفسه، مش قرار AI - فمش بيمرّ عبر Guardrails
     * (الحدود دي مصمّمة تحمي من قرارات AI خاطئة، مش من إرادة العميل
     * المباشرة على حملته هو). بيسجّل نفس Audit Trail بالظبط عشان يظهر في
     * سجل النشاط وميزة الـRollback زي أي تغيير تاني.
     */
    public function updateCampaignStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('محتاج صلاحية Manager أو أعلى لتغيير حالة الحملة', 403);
        }

        $newStatus = $this->get('status');
        if (!in_array($newStatus, ['active', 'paused'], true)) {
            return $this->error('status لازم يكون active أو paused', 422);
        }
        if ($campaign->getAttribute('status') === $newStatus) {
            return $this->success([], 'الحملة بالفعل في هذه الحالة');
        }

        $connId = $campaign->getAttribute('platform_connection_id');
        $externalId = (string) $campaign->getAttribute('external_campaign_id');
        if (!$connId || $externalId === '') {
            return $this->error('الحملة دي لسه مش متزامنة مع منصة إعلانية حقيقية', 422);
        }

        try {
            $apiResult = $this->executeCampaignStatusOnPlatform($campaign, $newStatus);

            if (!$apiResult['success']) {
                return $this->error('تعذّر تنفيذ الإجراء على المنصة: ' . ($apiResult['error'] ?? 'خطأ غير معروف'), 502);
            }

            $previousStatus = (string) $campaign->getAttribute('status');
            $campaign->setAttribute('status', $newStatus);
            $campaign->save();

            $log = new AdOptimizationLog([
                'campaign_id' => (int) $campaign->getAttribute('id'),
                'user_id' => (int) $this->user['id'],
                'action_type' => $newStatus === 'paused' ? 'pause_campaign' : 'resume_campaign',
                'mode' => 'manual',
                'description' => 'إيقاف/استئناف يدوي مباشر من العميل عبر لوحة التحكم',
                'before_value' => $previousStatus,
                'after_value' => $newStatus,
                'ai_confidence' => null,
                'applied_automatically' => 1,
                'external_result' => 'success',
                'can_rollback' => 1,
            ]);
            $log->save();

            ActivityLog::record('ads_autopilot', 'campaign.status_changed_manually', [
                'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                'meta' => ['before' => $previousStatus, 'after' => $newStatus],
            ]);

            return $this->success(['status' => $newStatus], 'تم تحديث حالة الحملة');
        } catch (Exception $e) {
            Logger::error('updateCampaignStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذّر تنفيذ الإجراء', 500);
        }
    }

    /**
     * DELETE /api/ads/campaigns/{id} - بند 3 "Delete إذا الـBackend يسمح".
     * ملحوظة صراحة: Meta/Google Ads API مفيهمش حذف نهائي حقيقي للحملة على
     * المنصة - أقصى حاجة ممكنة تقنيًا هي PAUSED/REMOVED. فـ"الحذف" هنا
     * Soft Delete (إخفاء من قوائم Tourfecto مع الحفاظ الكامل على بيانات
     * الأداء/السجل التاريخية)، + إيقاف فعلي على المنصة الحقيقية أولًا لو
     * كانت الحملة شغّالة (أمان إضافي - نفس منطق updateCampaignStatus).
     */
    public function deleteCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || $campaign->getAttribute('deleted_at')) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('محتاج صلاحية Manager أو أعلى لحذف الحملة', 403);
        }

        try {
            // لو الحملة شغّالة وفعليًا متزامنة مع منصة حقيقية - نوقفها هناك
            // الأول قبل ما نخفيها من واجهة العميل (منعًا لاستمرار إنفاق
            // فعلي على حملة اختفت من لوحة تحكمه).
            if ($campaign->getAttribute('status') === 'active' && $campaign->getAttribute('platform_connection_id') && $campaign->getAttribute('external_campaign_id')) {
                $pauseResult = $this->pauseCampaignOnPlatform($campaign);
                if (!$pauseResult['success']) {
                    return $this->error('تعذّر إيقاف الحملة على المنصة قبل حذفها: ' . ($pauseResult['error'] ?? 'خطأ غير معروف') . ' - جرّب توقفها يدويًا الأول', 502);
                }
                $campaign->setAttribute('status', 'paused');
            }

            $campaign->setAttribute('deleted_at', date('Y-m-d H:i:s'));
            $campaign->save();

            ActivityLog::record('ads_autopilot', 'campaign.deleted', [
                'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            ]);

            return $this->success([], 'تم حذف الحملة (بياناتها التاريخية محفوظة، والحملة الحقيقية على المنصة أُوقفت لو كانت شغّالة)');
        } catch (Exception $e) {
            Logger::error('deleteCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذّر حذف الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/bulk-status - بند 3 "Bulk Selection إذا
     * الـBackend يسمح". إيقاف/استئناف عدة حملات مرة واحدة - كل حملة بتتفحص
     * ملكيتها لوحدها وبتتنفذ عليها نفس منطق updateCampaignStatus بالظبط،
     * مفيش أي تجاوز أمان جماعي.
     */
    public function bulkUpdateCampaignStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $ids = $this->get('campaign_ids');
        $newStatus = $this->get('status');
        if (!is_array($ids) || empty($ids)) {
            return $this->error('campaign_ids مطلوبة (مصفوفة)', 422);
        }
        if (!in_array($newStatus, ['active', 'paused'], true)) {
            return $this->error('status لازم يكون active أو paused', 422);
        }

        $results = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            $campaign = (new AdCampaign())->find((int) $id);
            if (!$campaign || $campaign->getAttribute('deleted_at') || !$this->resolveCampaignAccess($campaign, 'manager')) {
                $results[] = ['campaign_id' => $id, 'success' => false, 'error' => 'غير موجودة'];
                continue;
            }
            if ($campaign->getAttribute('status') === $newStatus) {
                $results[] = ['campaign_id' => $id, 'success' => true, 'note' => 'already in this status'];
                continue;
            }

            $connId = $campaign->getAttribute('platform_connection_id');
            $externalId = (string) $campaign->getAttribute('external_campaign_id');
            if (!$connId || $externalId === '') {
                $results[] = ['campaign_id' => $id, 'success' => false, 'error' => 'لسه مش متزامنة مع منصة حقيقية'];
                continue;
            }

            $apiResult = $newStatus === 'paused' ? $this->pauseCampaignOnPlatform($campaign) : $this->resumeCampaignOnPlatform($campaign);
            if (!$apiResult['success']) {
                $results[] = ['campaign_id' => $id, 'success' => false, 'error' => $apiResult['error'] ?? 'خطأ غير معروف'];
                continue;
            }

            $campaign->setAttribute('status', $newStatus);
            $campaign->save();

            $log = new AdOptimizationLog([
                'campaign_id' => (int) $campaign->getAttribute('id'), 'user_id' => (int) $this->user['id'],
                'action_type' => $newStatus === 'paused' ? 'pause_campaign' : 'resume_campaign', 'mode' => 'manual',
                'description' => 'إجراء جماعي (Bulk) من العميل', 'applied_automatically' => 1, 'external_result' => 'success',
            ]);
            $log->save();

            $results[] = ['campaign_id' => $id, 'success' => true];
        }

        return $this->success(['results' => $results]);
    }

    /** يستخدم نفس منطق التنفيذ في updateCampaignStatus - مستخرج كـhelper عشان يُستخدم من deleteCampaign() وbulkUpdateCampaignStatus() كمان */
    private function pauseCampaignOnPlatform(AdCampaign $campaign): array
    {
        return $this->executeCampaignStatusOnPlatform($campaign, 'paused');
    }

    private function resumeCampaignOnPlatform(AdCampaign $campaign): array
    {
        return $this->executeCampaignStatusOnPlatform($campaign, 'active');
    }

    private function executeCampaignStatusOnPlatform(AdCampaign $campaign, string $newStatus): array
    {
        $connId = $campaign->getAttribute('platform_connection_id');
        $externalId = (string) $campaign->getAttribute('external_campaign_id');
        if (!$connId || $externalId === '') {
            return ['success' => false, 'error' => 'الحملة مش متزامنة مع منصة حقيقية'];
        }

        $conn = (new PlatformConnection())->find((int) $connId);
        if (!$conn || $conn->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الربط بالمنصة غير متاح'];
        }

        $encryption = new Encryption();
        $accessToken = $encryption->decrypt((string) $conn->getAttribute('access_token'));
        $platform = (string) $conn->getAttribute('platform');

        if ($platform === 'meta_ads') {
            $api = new MetaAdsAPI($accessToken);
            return $newStatus === 'paused' ? $api->pauseCampaign($externalId) : $api->resumeCampaign($externalId);
        }
        if ($platform === 'google_ads') {
            $api = new GoogleAdsAPI($accessToken);
            $customerId = (string) $conn->getAttribute('external_account_id');
            $campaignResourceName = "customers/{$customerId}/campaigns/{$externalId}";
            return $newStatus === 'paused' ? $api->pauseCampaign($customerId, $campaignResourceName) : $api->resumeCampaign($customerId, $campaignResourceName);
        }
        return ['success' => false, 'error' => "منصة غير مدعومة: {$platform}"];
    }

    /** POST /api/ads/campaigns/{id}/landing-page/analyze */
    public function analyzeLandingPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $url = trim((string) $this->get('url', (string) $campaign->getAttribute('landing_page_url')));
        if ($url === '') {
            return $this->error('حدد رابط صفحة الهبوط الأول', 422);
        }

        try {
            $service = new LandingPageAnalysisService();
            $result = $service->analyze($url, (string) $campaign->getAttribute('product_or_service'));

            if ($result['fetch_error'] === null) {
                $campaign->setAttribute('landing_page_url', $url);
                $campaign->setAttribute('landing_page_last_analysis', json_encode($result, JSON_UNESCAPED_UNICODE));
                $campaign->setAttribute('landing_page_analyzed_at', date('Y-m-d H:i:s'));
                $campaign->save();

                ActivityLog::record('ads_autopilot', 'landing_page.analyzed', [
                    'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                ]);
            }

            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('analyzeLandingPage Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحليل الصفحة', 500);
        }
    }

    // ================================================================
    // UTM Tracking (البند 18)
    // ================================================================

    /** POST /api/ads/campaigns/{id}/utm-links */
    public function createUtmLink(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $destinationUrl = trim((string) $this->get('destination_url', ''));
        $utmSource = trim((string) $this->get('utm_source', 'google'));
        $utmMedium = trim((string) $this->get('utm_medium', 'cpc'));

        if ($destinationUrl === '') {
            return $this->error('رابط الوجهة مطلوب', 422);
        }

        try {
            $service = new AdTrackingService();
            $result = $service->buildLink(
                (int) $campaign->getAttribute('user_id'),
                $campaign,
                $destinationUrl,
                $utmSource,
                $utmMedium,
                $this->get('utm_content'),
                $this->get('utm_term')
            );
            return $this->success($result);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            Logger::error('createUtmLink Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الرابط', 500);
        }
    }

    /** GET /api/ads/campaigns/{id}/utm-links */
    public function listUtmLinks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $service = new AdTrackingService();
        return $this->success($service->listForCampaign((int) $campaign->getAttribute('id')));
    }

    /**
     * GET /r/{code} - رابط عام (بدون تسجيل دخول) بيسجّل نقرة حقيقية على
     * رابط UTM ثم يحوّل الزائر لصفحة الهبوط الفعلية. مقصود إنه ما يستخدمش
     * isAuthenticated() هنا لأن اللي بيضغط عليه زائر من إعلان، مش عميل
     * مسجّل دخول بالضرورة.
     */
    public function redirectUtmClick(array $params = []): array
    {
        $code = (string) ($params['code'] ?? '');
        $service = new AdTrackingService();
        $tracked = $service->resolveAndTrackClick($code);

        if (!$tracked) {
            http_response_code(404);
            echo 'الرابط غير صالح أو منتهي';
            exit;
        }

        // نخزّن إسناد النقرة (رابط UTM + المنصة) لمدة 30 يوم قبل التحويل -
        // أي حجز قادم من الزائر ده هيتم تنسبه تلقائيًا للرابط الإعلاني
        // (البيانات المخزنة معرّف الرابط بس، مش أي بيانات شخصية).
        $service->storeAttribution($tracked['utm_link_id'], $tracked['platform']);

        header('Location: ' . $tracked['destination'], true, 302);
        exit;
    }

    // ================================================================
    // Automated Reports (البند 21)
    // ================================================================

    /** GET /api/ads/dashboard/summary?period=&platform=&status= */
    public function getDashboardSummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = in_array($this->get('period'), ['daily', 'weekly', 'monthly'], true) ? $this->get('period') : 'weekly';
        $platform = $this->get('platform') ?: null;
        $status = $this->get('status') ?: null;

        $service = new AdReportService();
        return $this->success($service->dashboardSummary($access['owner_id'], $period, $platform, $status));
    }

    /** GET /api/ads/reports/trend?days=&campaign_id= */
    public function getReportTrend(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $days = max(1, min(90, (int) ($this->get('days', 30))));
        $campaignId = $this->get('campaign_id') ? (int) $this->get('campaign_id') : null;
        if ($campaignId !== null) {
            $campaign = (new AdCampaign())->find($campaignId);
            if (!$campaign || (int) $campaign->getAttribute('user_id') !== $access['owner_id']) {
                return $this->error('الحملة غير موجودة', 404);
            }
        }
        $service = new AdReportService();
        return $this->success($service->dailyTrend($access['owner_id'], $days, $campaignId));
    }

    /** GET /api/ads/reports/comparison?period= */
    public function getCampaignComparison(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = in_array($this->get('period'), ['daily', 'weekly', 'monthly'], true) ? $this->get('period') : 'weekly';
        $service = new AdReportService();
        return $this->success($service->campaignComparison($access['owner_id'], $period));
    }

    /** GET /api/ads/reports?period=daily|weekly|monthly */
    public function getReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = in_array($this->get('period'), ['daily', 'weekly', 'monthly'], true) ? $this->get('period') : 'weekly';

        $service = new AdReportService();
        return $this->success($service->generate($access['owner_id'], $period));
    }

    // ================================================================
    // Ads Competitor Insights (البند 16)
    // ================================================================

    /** POST /api/ads/competitors/{id}/analyze */
    public function analyzeAdsCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $competitor = (new Competitor())->find((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('المنافس غير موجود', 404);
        }

        $access = $this->resolveAdsAccessForOwner((int) $competitor->getAttribute('user_id'), 'manager');
        if (!$access) {
            return $this->error('المنافس غير موجود', 404);
        }

        $offerDescription = trim((string) $this->get('offer_description', ''));
        if ($offerDescription === '') {
            return $this->error('اكتب وصف مختصر لعرضك الأول', 422);
        }

        try {
            $service = new AdsCompetitorInsightsService();
            return $this->success($service->analyzeForAds($competitor, $offerDescription));
        } catch (Exception $e) {
            Logger::error('analyzeAdsCompetitor Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/competitors/{id}/insights */
    public function listAdsCompetitorInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $competitor = (new Competitor())->find((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('المنافس غير موجود', 404);
        }

        if (!$this->resolveAdsAccessForOwner((int) $competitor->getAttribute('user_id'), 'viewer')) {
            return $this->error('المنافس غير موجود', 404);
        }

        $service = new AdsCompetitorInsightsService();
        return $this->success($service->listForWebsite((int) $competitor->getAttribute('website_id')));
    }

    /** GET /api/ads/competitors - قائمة المنافسين المسجّلين لهذا العميل (لملء قائمة الاختيار في صفحة المنافسين) */
    public function listMyCompetitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $rows = (new Competitor())->where(['user_id' => $access['owner_id'], 'is_active' => 1], ['created_at' => 'DESC']);
        return $this->success(array_map(fn ($c) => $c->toArray(), $rows));
    }

    // ================================================================
    // Team Permissions (البند 27) - Viewer/Manager/Admin
    // إدارة الفريق نفسها متاحة لصاحب الحساب أو Admin بس (Manager/Viewer
    // ماينفعش يضيفوا/يشيلوا أعضاء تانيين - ده تحكّم على مستوى الحساب نفسه)
    // ================================================================

    /** GET /api/ads/team - قائمة أعضاء الفريق على حسابي (لو أنا Owner) */
    public function listTeamMembers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $perm = new AdPermissionService();
        $members = $perm->listMembers($access['owner_id']);
        $accountsIBelongTo = $perm->accountsUserBelongsTo((int) $this->user['id']);

        return $this->success(['members' => $members, 'accounts_i_belong_to' => $accountsIBelongTo]);
    }

    /** POST /api/ads/team - إضافة عضو (بإيميله - لازم يكون له حساب Tourfecto بالفعل) */
    public function addTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $email = trim((string) $this->get('email', ''));
        $role = $this->get('role', 'viewer');
        if ($email === '') {
            return $this->error('اكتب إيميل العضو', 422);
        }

        $perm = new AdPermissionService();
        $result = $perm->addMemberByEmail($access['owner_id'], $email, $role, (int) $this->user['id']);

        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }

        ActivityLog::record('ads_autopilot', 'team.member_added', [
            'user_id' => (int) $this->user['id'], 'meta' => ['email' => $email, 'role' => $role],
        ]);

        return $this->success([], 'تم إضافة العضو');
    }

    /** POST /api/ads/team/{id}/role */
    public function updateTeamMemberRole(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $newRole = $this->get('role');
        $perm = new AdPermissionService();
        $ok = $perm->updateMemberRole($access['owner_id'], (int) ($params['id'] ?? 0), (string) $newRole);

        return $ok ? $this->success([], 'تم تحديث الدور') : $this->error('تعذّر التحديث - تأكد من الدور والعضو', 422);
    }

    /** POST /api/ads/team/{id}/remove */
    public function removeTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $perm = new AdPermissionService();
        $ok = $perm->removeMember($access['owner_id'], (int) ($params['id'] ?? 0));

        return $ok ? $this->success([], 'تم إزالة العضو') : $this->error('تعذّرت الإزالة', 422);
    }

    /** GET /ads/team */
    public function showTeamPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/team'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('team');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>👥 إدارة الفريق</h3><span class="p-card-sub">أضف زملاء عندهم حساب Tourfecto بالفعل، وحدّد صلاحياتهم على موديول الإعلانات بتاعك</span></div>
            <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:8px;margin-bottom:14px;">
                <input type="email" id="newMemberEmail" class="p-select" placeholder="إيميل العضو (لازم يكون مسجّل في Tourfecto)">
                <select id="newMemberRole" class="p-select">
                    <option value="viewer">Viewer - عرض فقط</option>
                    <option value="manager">Manager - إدارة الحملات</option>
                    <option value="admin">Admin - كل الصلاحيات</option>
                </select>
                <button class="p-btn primary" onclick="addTeamMember()">+ إضافة</button>
            </div>
            <div id="teamMembersBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="belongToCard" style="display:none;">
            <div class="p-card-head"><h3>🔗 حسابات أنا عضو فيها</h3></div>
            <div id="belongToBox"></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const roleLabels = { viewer: 'Viewer - عرض فقط', manager: 'Manager - إدارة الحملات', admin: 'Admin - كل الصلاحيات' };

    async function loadTeam() {
        const res = await fetchJSON('/api/ads/team');
        const box = document.getElementById('teamMembersBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }

        if (!res.data.members.length) {
            box.innerHTML = '<div class="p-cell-muted">لسه مفيش أعضاء فريق مضافين - إنت الـOwner الوحيد على الحساب ده حاليًا</div>';
        } else {
            box.innerHTML = res.data.members.map(m => `
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                    <div><b>${esc(m.company_name)}</b> <span class="p-cell-muted" style="font-size:12px;">${esc(m.email)}</span></div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select class="p-select xs" onchange="updateMemberRole(${m.id}, this.value)">
                            ${Object.entries(roleLabels).map(([k, l]) => `<option value="${k}" ${m.role === k ? 'selected' : ''}>${l}</option>`).join('')}
                        </select>
                        <button class="p-btn danger xs" onclick="removeTeamMember(${m.id})">إزالة</button>
                    </div>
                </div>`).join('');
        }

        if (res.data.accounts_i_belong_to.length) {
            document.getElementById('belongToCard').style.display = 'block';
            document.getElementById('belongToBox').innerHTML = res.data.accounts_i_belong_to.map(a => `
                <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                    <b>${esc(a.company_name)}</b> - دورك: <span class="pill xs">${esc(roleLabels[a.role] || a.role)}</span>
                    <div class="p-cell-muted" style="font-size:11px;">استخدم <code>?owner_id=${a.owner_user_id}</code> في الروابط للوصول لهذا الحساب حاليًا</div>
                </div>`).join('');
        }
    }

    window.addTeamMember = async function () {
        const email = document.getElementById('newMemberEmail').value.trim();
        const role = document.getElementById('newMemberRole').value;
        if (!email) { P.toast('اكتب إيميل العضو', 'error'); return; }

        const res = await fetchJSON('/api/ads/team', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, role }),
        });
        if (res.success) { P.toast('تم إضافة العضو', 'success'); document.getElementById('newMemberEmail').value = ''; loadTeam(); }
        else P.toast(res.error || 'تعذّرت الإضافة', 'error');
    };

    window.updateMemberRole = async function (id, role) {
        const res = await fetchJSON('/api/ads/team/' + id + '/role', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role }),
        });
        if (res.success) P.toast('تم تحديث الدور', 'success'); else P.toast(res.error || 'تعذّر التحديث', 'error');
    };

    window.removeTeamMember = async function (id) {
        if (!confirm('متأكد من إزالة العضو ده؟')) return;
        const res = await fetchJSON('/api/ads/team/' + id + '/remove', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم الإزالة', 'success'); loadTeam(); } else P.toast(res.error || 'تعذّرت الإزالة', 'error');
    };

    loadTeam();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'فريق العمل', 'إدارة أعضاء الفريق وصلاحياتهم على موديول الإعلانات', $body, $script);
        exit;
    }

    /**
     * شريط تنقّل فرعي داخلي لكل صفحات الإعلانات (نفس نمط crmTabsHtml في
     * CrmController.php بالظبط - مفيش عنصر Sidebar عام جديد، الـ"ads"
     * الموجود بالفعل في القائمة الجانبية بيفضل نشط لكل الصفحات دي).
     * لينكات #anchor بترجع لنفس صفحة /ads (الأقسام لسه هناك، مجمّعة في
     * صفحة واحدة لتقليل مخاطر فصلها) - باقي اللينكات صفحات مستقلة فعلية.
     */
    /** GET /ads/reports */
    public function showReportsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/reports'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('reports');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📈 اتجاه الأداء اليومي</h3>
                <select id="trendDays" class="p-select" style="width:auto;" onchange="loadTrendChart()">
                    <option value="7">آخر 7 أيام</option>
                    <option value="30" selected>آخر 30 يوم</option>
                    <option value="90">آخر 90 يوم</option>
                </select>
            </div>
            <div id="trendChartEmpty" class="p-cell-muted" style="display:none;">لا توجد بيانات كافية بعد لعرض الاتجاه</div>
            <canvas id="trendChart" height="90"></canvas>
        </div>

        <div class="p-card" id="reportsCard" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📊 تقرير الأداء الآلي</h3>
                <select id="reportPeriod" class="p-select" style="width:auto;" onchange="loadAdsReport()">
                    <option value="daily">يومي</option>
                    <option value="weekly" selected>أسبوعي</option>
                    <option value="monthly">شهري</option>
                </select>
            </div>
            <div id="reportBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="attributionCard" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🔗 الإسناد (Attribution) - روابط UTM</h3><span class="p-card-sub">نقرات حقيقية مسجّلة لكل رابط تتبّع أنشأته لحملاتك</span></div>
            <div id="attributionBox"><div class="p-cell-muted">اختار حملة من صفحة "الحملات" لعرض روابط الـUTM بتاعتها وأداء النقرات.</div></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    let trendChartInstance = null;

    window.loadTrendChart = async function () {
        const days = document.getElementById('trendDays').value;
        const res = await fetchJSON('/api/ads/reports/trend?days=' + days);
        const emptyBox = document.getElementById('trendChartEmpty');
        const canvas = document.getElementById('trendChart');

        if (!res.success || !res.data.length) {
            emptyBox.style.display = 'block';
            canvas.style.display = 'none';
            return;
        }
        emptyBox.style.display = 'none';
        canvas.style.display = 'block';

        const labels = res.data.map(r => r.date);
        const spend = res.data.map(r => r.spend);
        const conversions = res.data.map(r => r.conversions);

        if (trendChartInstance) trendChartInstance.destroy();
        trendChartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [
                    { label: 'الإنفاق', data: spend, borderColor: '#0077be', tension: 0.3, yAxisID: 'y' },
                    { label: 'التحويلات', data: conversions, borderColor: '#22c55e', tension: 0.3, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { type: 'linear', position: 'left', title: { display: true, text: 'الإنفاق' } },
                    y1: { type: 'linear', position: 'right', title: { display: true, text: 'التحويلات' }, grid: { drawOnChartArea: false } },
                },
            },
        });
    };

    window.loadAdsReport = async function () {
        const box = document.getElementById('reportBox');
        box.innerHTML = '<div class="p-loading-row">جارِ التحميل...</div>';
        const period = document.getElementById('reportPeriod').value;
        const res = await fetchJSON('/api/ads/reports?period=' + period);
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل التقرير</div>'; return; }
        const d = res.data;
        if (!d.has_data) { box.innerHTML = `<div class="p-cell-muted">${esc(d.note || 'مفيش بيانات كافية للفترة دي بعد')}</div>`; return; }

        box.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;">
                <div><div class="p-cell-muted" style="font-size:11px;">الإنفاق</div><div><b>${esc(d.totals.spend)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">النقرات</div><div><b>${esc(d.totals.clicks)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">CPA</div><div><b>${d.totals.cpa ?? '-'}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">ROAS</div><div><b>${d.totals.roas ?? '-'}</b></div></div>
            </div>
            ${d.best_campaign ? `<div>🏆 أفضل حملة: <b>${esc(d.best_campaign.name)}</b> (ROAS: ${d.best_campaign.roas ?? '-'})</div>` : ''}
            ${d.worst_campaign ? `<div>⚠️ أضعف حملة: <b>${esc(d.worst_campaign.name)}</b> (ROAS: ${d.worst_campaign.roas ?? '-'})</div>` : ''}
            ${d.best_creative ? `<div>✨ أفضل إعلان: <b>${esc(d.best_creative.headline)}</b></div>` : ''}
            ${d.actions_taken.length ? `<div style="margin-top:8px;"><b>إجراءات اتخذها Autopilot:</b><ul>${d.actions_taken.slice(0, 5).map(a => `<li>${esc(a.action_type)} - ${esc(a.description)}</li>`).join('')}</ul></div>` : ''}
        `;
    };

    loadTrendChart();
    loadAdsReport();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'تقارير الأداء', 'اتجاه الأداء اليومي والتقارير الدورية والإسناد', $body, $script);
        exit;
    }

    /** GET /ads/budget */
    public function showBudgetPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/budget'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('budget');
        $body = <<<HTML
        {$tabsHtml}

        <div id="budgetKpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;">
            <div class="p-loading-row">جارِ التحميل...</div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📉 اتجاه الإنفاق مقابل التحويلات</h3></div>
            <div id="budgetTrendEmpty" class="p-cell-muted" style="display:none;">لا توجد بيانات كافية بعد</div>
            <canvas id="budgetTrendChart" height="90"></canvas>
        </div>

        <div class="p-card">
            <div class="p-card-head">
                <h3>⚖️ مقارنة الحملات</h3>
                <select id="cmpPeriod" class="p-select" style="width:auto;" onchange="loadComparisonChart()">
                    <option value="weekly" selected>آخر 7 أيام</option>
                    <option value="monthly">آخر 30 يوم</option>
                </select>
            </div>
            <div id="comparisonEmpty" class="p-cell-muted" style="display:none;">لا توجد بيانات كافية بعد</div>
            <canvas id="comparisonChart" height="100"></canvas>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    let budgetTrendChart = null, comparisonChart = null;

    async function loadBudgetKpis() {
        const box = document.getElementById('budgetKpis');
        const res = await fetchJSON('/api/ads/dashboard/summary?period=monthly');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        const d = res.data;
        const kpi = (label, value) => `
            <div class="p-card" style="padding:14px;">
                <div class="p-cell-muted" style="font-size:11.5px;">${label}</div>
                <div style="font-size:20px;font-weight:800;margin-top:4px;">${value === null || value === undefined ? '<span class="p-cell-muted" style="font-size:13px;">لا توجد بيانات كافية</span>' : esc(String(value))}</div>
            </div>`;
        box.innerHTML = kpi('الإنفاق (آخر 30 يوم)', d.spend) + kpi('استخدام الميزانية', d.budget_utilization_pct !== null ? d.budget_utilization_pct + '%' : null) + kpi('حملات نشطة', d.active_campaigns) + kpi('حملات متوقفة', d.paused_campaigns);
    }

    window.loadComparisonChart = async function () {
        const period = document.getElementById('cmpPeriod').value;
        const res = await fetchJSON('/api/ads/reports/comparison?period=' + period);
        const emptyBox = document.getElementById('comparisonEmpty');
        const canvas = document.getElementById('comparisonChart');

        if (!res.success || !res.data.length) { emptyBox.style.display = 'block'; canvas.style.display = 'none'; return; }
        emptyBox.style.display = 'none'; canvas.style.display = 'block';

        if (comparisonChart) comparisonChart.destroy();
        comparisonChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: res.data.map(c => c.name),
                datasets: [{ label: 'الإنفاق', data: res.data.map(c => c.spend), backgroundColor: '#0077be' }],
            },
            options: { responsive: true },
        });
    };

    async function loadBudgetTrend() {
        const res = await fetchJSON('/api/ads/reports/trend?days=30');
        const emptyBox = document.getElementById('budgetTrendEmpty');
        const canvas = document.getElementById('budgetTrendChart');
        if (!res.success || !res.data.length) { emptyBox.style.display = 'block'; canvas.style.display = 'none'; return; }
        emptyBox.style.display = 'none'; canvas.style.display = 'block';

        if (budgetTrendChart) budgetTrendChart.destroy();
        budgetTrendChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: res.data.map(r => r.date),
                datasets: [
                    { label: 'الإنفاق', data: res.data.map(r => r.spend), borderColor: '#0077be', tension: 0.3 },
                    { label: 'التحويلات', data: res.data.map(r => r.conversions), borderColor: '#22c55e', tension: 0.3 },
                ],
            },
            options: { responsive: true },
        });
    }

    loadBudgetKpis();
    loadBudgetTrend();
    loadComparisonChart();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'الميزانية والإنفاق', 'اتجاه الإنفاق ومقارنة أداء الحملات', $body, $script);
        exit;
    }

    /** GET /ads/campaigns/{id} */
    public function showCampaignDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'viewer')) {
            header('Location: /ads');
            exit;
        }

        $nameSafe = htmlspecialchars((string) $campaign->getAttribute('name'), ENT_QUOTES, 'UTF-8');
        $tabsHtml = $this->adsTabsHtml('campaigns');

        $body = <<<HTML
        {$tabsHtml}
        <div style="margin-bottom:12px;"><a href="/ads#campaignsTable" class="p-cell-muted" style="text-decoration:none;">← رجوع لقائمة الحملات</a></div>

        <div class="p-card" id="campaignOverviewCard" style="margin-bottom:16px;">
            <div class="p-loading-row">جارِ التحميل...</div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 أداء الحملة</h3></div>
            <div id="campaignTrendEmpty" class="p-cell-muted" style="display:none;">لا توجد بيانات كافية بعد</div>
            <canvas id="campaignTrendChart" height="90"></canvas>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" id="campaignTwoCol">
            <div class="p-card">
                <div class="p-card-head"><h3>🎯 الاستهداف والجمهور</h3></div>
                <div id="campaignAudienceBox"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>🌐 صفحة الهبوط</h3></div>
                <div id="campaignLandingPageBox">
                    <input type="text" id="lpUrl" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="https://example.com/landing-page">
                    <button class="p-btn primary xs" onclick="analyzeCampaignLandingPage()">تحليل الصفحة</button>
                    <div id="lpResults" style="margin-top:10px;font-size:13px;"></div>
                </div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;" id="adGroupsCard">
            <div class="p-card-head">
                <h3>📁 المجموعات الإعلانية (Ad Groups)</h3>
                <span class="p-card-sub">تنظيم محلي للكلمات/الإعلانات - مش مزامنة حقيقية مع Ad Set على المنصة</span>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:10px;">
                <input type="text" id="newAdGroupName" class="p-select" style="flex:1;" placeholder="اسم مجموعة إعلانية جديدة">
                <button class="p-btn primary xs" onclick="createAdGroup()">+ إضافة</button>
            </div>
            <div id="adGroupsBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>✍️ الإعلانات (Creatives)</h3></div>
            <div id="campaignCopiesBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🔑 الكلمات المفتاحية</h3>
                <button class="p-btn outline xs" onclick="generateCampaignKeywords()">توليد بالذكاء الاصطناعي</button>
            </div>
            <textarea id="kwGoalDesc" class="p-select" style="width:100%;min-height:50px;margin-bottom:8px;display:none;" placeholder="وصف مختصر للعرض (اختياري)"></textarea>
            <div id="kwResults"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🔗 روابط UTM</h3></div>
            <input type="text" id="utmDest" class="p-select" style="width:100%;margin-bottom:6px;" placeholder="رابط الوجهة (صفحة الهبوط)">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <input type="text" id="utmSource" class="p-select" placeholder="utm_source" value="google">
                <input type="text" id="utmMedium" class="p-select" placeholder="utm_medium" value="cpc">
            </div>
            <button class="p-btn primary xs" style="margin-top:8px;" onclick="createCampaignUtmLink()">إنشاء رابط</button>
            <div id="utmResults" style="margin-top:10px;font-size:13px;"></div>
            <div id="utmListBox" style="margin-top:10px;"></div>
        </div>

        <div id="campaignDetailsConfig" data-campaign-id="{$campaignId}" style="display:none;"></div>

        <div class="p-card">
            <div class="p-card-head"><h3>📜 سجل النشاط والقرارات</h3></div>
            <div id="campaignLogBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const CAMPAIGN_ID = document.getElementById('campaignDetailsConfig').dataset.campaignId;
    let trendChart = null;

    async function loadOverview() {
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID);
        const box = document.getElementById('campaignOverviewCard');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل بيانات الحملة</div>'; return; }
        const c = res.data.campaign;
        const statusPill = c.status === 'active' ? 'green' : (c.status === 'paused' ? 'gray' : 'yellow');
        box.innerHTML = `
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:center;">
                <div>
                    <h2 style="margin:0;">${esc(c.name || '')}</h2>
                    <div class="p-cell-muted">${esc(c.objective || '')} · <span class="pill ${statusPill}">${esc(c.status)}</span></div>
                </div>
                <div style="display:flex;gap:8px;">
                    ${c.status === 'active' ? `<button class="p-btn outline xs" onclick="toggleCampaignStatus('paused')">⏸ إيقاف</button>` : ''}
                    ${c.status === 'paused' ? `<button class="p-btn outline xs" onclick="toggleCampaignStatus('active')">▶ استئناف</button>` : ''}
                    <button class="p-btn danger xs" onclick="deleteCampaign()">🗑 حذف الحملة</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:16px;">
                <div><div class="p-cell-muted" style="font-size:11px;">الميزانية اليومية</div><div><b>${esc(c.daily_budget ?? '-')}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">الإنفاق الكلي</div><div><b>${esc(c.spend ?? 0)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">النقرات</div><div><b>${esc(c.clicks ?? 0)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">الظهور</div><div><b>${esc(c.impressions ?? 0)}</b></div></div>
            </div>
        `;

        renderAudience(res.data.audience);
        if (c.landing_page_url) document.getElementById('lpUrl').value = c.landing_page_url;
        if (c.landing_page_last_analysis) renderLandingPageResult(c.landing_page_last_analysis);
        document.getElementById('utmDest').value = c.landing_page_url || '';
    }

    window.toggleCampaignStatus = async function (newStatus) {
        const actionLabel = newStatus === 'paused' ? 'إيقاف' : 'استئناف';
        if (!confirm('متأكد من ' + actionLabel + ' هذه الحملة على المنصة الإعلانية الحقيقية؟')) return;

        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: newStatus }),
        });
        if (res.success) {
            P.toast('تم ' + actionLabel + ' الحملة بنجاح', 'success');
            loadOverview();
            loadLog();
        } else {
            P.toast(res.error || 'تعذّر تنفيذ الإجراء', 'error');
        }
    };

    window.deleteCampaign = async function () {
        if (!confirm('متأكد من حذف هذه الحملة؟\n\nملحوظة: Meta/Google Ads مفيهمش حذف نهائي حقيقي - الحملة هتتوقف على المنصة (لو كانت شغّالة) وتتخفي من قوائم Tourfecto، لكن كل بيانات الأداء التاريخية هتفضل محفوظة.')) return;

        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID, { method: 'DELETE' });
        if (res.success) {
            P.toast('تم حذف الحملة', 'success');
            setTimeout(() => { window.location.href = '/ads'; }, 800);
        } else {
            P.toast(res.error || 'تعذّر الحذف', 'error');
        }
    };

    function renderAudience(a) {
        const box = document.getElementById('campaignAudienceBox');
        if (!a) { box.innerHTML = '<div class="p-cell-muted">لا يوجد جمهور محدد لهذه الحملة</div>'; return; }
        box.innerHTML = `
            <div><b>الفئة العمرية:</b> ${esc(a.age_min || '-')} - ${esc(a.age_max || '-')}</div>
            <div><b>الجنس:</b> ${esc(a.genders || 'الكل')}</div>
            <div><b>الدول:</b> ${(a.locations || []).map(l => `<span class="pill xs">${esc(l)}</span>`).join(' ') || '-'}</div>
            <div style="margin-top:6px;"><b>الاهتمامات:</b> ${(a.interests || []).map(i => `<span class="pill xs">${esc(i)}</span>`).join(' ') || '-'}</div>
        `;
    }

    function renderLandingPageResult(d) {
        const box = document.getElementById('lpResults');
        if (d.fetch_error) { box.innerHTML = `<span style="color:#b91c1c;">${esc(d.fetch_error)}</span>`; return; }
        box.innerHTML = `
            <div><b>Relevance:</b> ${esc(d.relevance || '-')}</div>
            <div><b>CTA:</b> ${esc(d.cta || '-')}</div>
            <div style="margin-top:6px;"><b>التوصيات:</b><ul>${(d.recommendations || []).map(r => `<li>${esc(r)}</li>`).join('')}</ul></div>
        `;
    }

    window.analyzeCampaignLandingPage = async function () {
        const box = document.getElementById('lpResults');
        box.innerHTML = 'جارِ التحليل...';
        const url = document.getElementById('lpUrl').value.trim();
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/landing-page/analyze', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }
        renderLandingPageResult(res.data);
    };

    async function loadAdGroups() {
        const box = document.getElementById('adGroupsBox');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/ad-groups');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل المجموعات الإعلانية</div>'; return; }
        if (!res.data.ad_groups.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد مجموعات إعلانية بعد - أضف واحدة لتنظيم كلماتك/إعلاناتك</div>'; return; }

        box.innerHTML = res.data.ad_groups.map(g => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div>
                    <b>${esc(g.name)}</b> <span class="pill xs ${g.status === 'active' ? 'green' : 'gray'}">${esc(g.status)}</span>
                    <div class="p-cell-muted" style="font-size:11px;">🔑 ${g.keywords_count} كلمة مفتاحية · ✍️ ${g.ads_count} إعلان</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="p-btn outline xs" onclick="toggleAdGroupStatus(${g.id}, '${g.status === 'active' ? 'paused' : 'active'}')">${g.status === 'active' ? '⏸ إيقاف' : '▶ استئناف'}</button>
                    <button class="p-btn danger xs" onclick="deleteAdGroup(${g.id})">🗑</button>
                </div>
            </div>`).join('') + `<div class="p-cell-muted" style="font-size:11px;margin-top:8px;">${esc(res.data.performance_note)}</div>`;
    }

    window.createAdGroup = async function () {
        const input = document.getElementById('newAdGroupName');
        const name = input.value.trim();
        if (!name) { P.toast('اكتب اسم المجموعة الأول', 'error'); return; }

        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/ad-groups', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name }),
        });
        if (res.success) { input.value = ''; P.toast('تم إنشاء المجموعة الإعلانية', 'success'); loadAdGroups(); }
        else P.toast(res.error || 'تعذر الإنشاء', 'error');
    };

    window.toggleAdGroupStatus = async function (id, newStatus) {
        const res = await fetchJSON('/api/ads/ad-groups/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: newStatus }),
        });
        if (res.success) loadAdGroups(); else P.toast(res.error || 'تعذر التحديث', 'error');
    };

    window.deleteAdGroup = async function (id) {
        if (!confirm('متأكد من حذف المجموعة دي؟ (الكلمات/الإعلانات المرتبطة هتفضل موجودة بس تنفصل عن المجموعة)')) return;
        const res = await fetchJSON('/api/ads/ad-groups/' + id, { method: 'DELETE' });
        if (res.success) { P.toast('تم الحذف', 'success'); loadAdGroups(); } else P.toast(res.error || 'تعذر الحذف', 'error');
    };

    async function loadCopies() {
        const box = document.getElementById('campaignCopiesBox');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/copies');
        if (!res.success || !res.data.copies || !res.data.copies.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد إعلانات مُولَّدة لهذه الحملة بعد</div>'; return; }
        box.innerHTML = res.data.copies.map(c => `
            <div style="padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div><b>${esc(c.headline || '')}</b> <span class="pill xs">${esc(c.variant_label || '')}</span> <span class="pill xs">${esc(c.status || '')}</span></div>
                <div class="p-cell-muted" style="font-size:13px;">${esc(c.description || '')}</div>
                ${c.performance_score !== null && c.performance_score !== undefined ? `<div class="p-cell-muted" style="font-size:11px;">نقاط الأداء: ${esc(c.performance_score)}</div>` : ''}
            </div>`).join('');
    }

    window.generateCampaignKeywords = async function () {
        const box = document.getElementById('kwResults');
        box.innerHTML = 'جارِ التوليد...';
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/keywords/generate', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التوليد')}</span>`; return; }
        renderKeywords(res.data);
    };

    function renderKeywords(data) {
        const box = document.getElementById('kwResults');
        const groups = ['high_intent', 'commercial', 'long_tail', 'local', 'negative'];
        const labels = { high_intent: 'نية شراء عالية', commercial: 'تجارية عامة', long_tail: 'عبارات طويلة', local: 'محلية', negative: 'سلبية (استبعاد)' };
        const any = groups.some(g => data[g] && data[g].length);
        if (!any) { box.innerHTML = '<div class="p-cell-muted">لا توجد كلمات مفتاحية مُولَّدة بعد - اضغط "توليد بالذكاء الاصطناعي"</div>'; return; }
        box.innerHTML = groups.map(g => (data[g] && data[g].length) ? `
            <div style="margin-bottom:8px;"><b>${labels[g]}:</b> ${data[g].map(k => `<span class="pill xs" style="margin:2px;">${esc(k.keyword)}</span>`).join('')}</div>
        ` : '').join('') + (data.disclaimer ? `<div class="p-cell-muted" style="font-size:11px;">${esc(data.disclaimer)}</div>` : '');
    }

    async function loadKeywords() {
        const box = document.getElementById('kwResults');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/keywords');
        if (!res.success || !res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد كلمات مفتاحية مُولَّدة بعد - اضغط "توليد بالذكاء الاصطناعي"</div>'; return; }
        // ملحوظة: match_type (exact/phrase/broad/negative) هو اللي بيتخزّن في
        // ad_keywords - تصنيف الـintent (high_intent/commercial/...) بيتولّد
        // لحظيًا وقت التوليد بس ومش عمود مُخزَّن، فعرض الكلمات المحفوظة
        // بيبقى List بسيط بدل تجميع Intent وهمي.
        box.innerHTML = '<table class="p-table"><thead><tr><th>الكلمة</th><th>النوع</th><th>الملاءمة</th><th>حجم بحث تقديري</th><th>CPC تقديري</th></tr></thead><tbody>' +
            res.data.map(k => `<tr><td>${esc(k.keyword)}</td><td>${esc(k.match_type)}</td><td>${k.ai_relevance_score ?? '-'}</td><td>${k.estimated_search_volume ?? '-'}</td><td>${k.estimated_cpc ?? '-'}</td></tr>`).join('') +
            '</tbody></table><div class="p-cell-muted" style="font-size:11px;margin-top:6px;">الأرقام تقديرات ذكاء اصطناعي، مش بيانات بحث حقيقية مقاسة.</div>';
    }

    window.createCampaignUtmLink = async function () {
        const box = document.getElementById('utmResults');
        box.innerHTML = 'جارِ الإنشاء...';
        const payload = {
            destination_url: document.getElementById('utmDest').value.trim(),
            utm_source: document.getElementById('utmSource').value.trim() || 'google',
            utm_medium: document.getElementById('utmMedium').value.trim() || 'cpc',
        };
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/utm-links', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر الإنشاء')}</span>`; return; }
        box.innerHTML = `<div>رابط التتبع: <a href="${esc(res.data.short_redirect_url)}" target="_blank">${esc(res.data.short_redirect_url)}</a></div>`;
        loadUtmLinks();
    };

    async function loadUtmLinks() {
        const box = document.getElementById('utmListBox');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/utm-links');
        if (!res.success || !res.data.length) { box.innerHTML = ''; return; }
        box.innerHTML = '<table class="p-table"><thead><tr><th>المصدر</th><th>الوسيط</th><th>نقرات</th></tr></thead><tbody>' +
            res.data.map(l => `<tr><td>${esc(l.utm_source)}</td><td>${esc(l.utm_medium)}</td><td>${esc(l.clicks)}</td></tr>`).join('') + '</tbody></table>';
    }

    async function loadTrend() {
        const res = await fetchJSON('/api/ads/reports/trend?days=30&campaign_id=' + CAMPAIGN_ID);
        const emptyBox = document.getElementById('campaignTrendEmpty');
        const canvas = document.getElementById('campaignTrendChart');
        if (!res.success || !res.data.length) { emptyBox.style.display = 'block'; canvas.style.display = 'none'; return; }
        emptyBox.style.display = 'none'; canvas.style.display = 'block';
        if (trendChart) trendChart.destroy();
        trendChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: res.data.map(r => r.date), datasets: [
                { label: 'الإنفاق', data: res.data.map(r => r.spend), borderColor: '#0077be', tension: 0.3 },
                { label: 'التحويلات', data: res.data.map(r => r.conversions), borderColor: '#22c55e', tension: 0.3 },
            ] },
            options: { responsive: true },
        });
    }

    async function loadLog() {
        const box = document.getElementById('campaignLogBox');
        const res = await fetchJSON('/api/ads/autopilot/logs?campaign_id=' + CAMPAIGN_ID);
        if (!res.success || !res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد سجل نشاط لهذه الحملة بعد</div>'; return; }
        box.innerHTML = res.data.map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <b>${esc(l.action_type)}</b> <span class="p-cell-muted" style="font-size:11px;">(${esc(l.mode)})</span>
                <div class="p-cell-muted" style="font-size:12px;">${esc(l.description)}</div>
            </div>`).join('');
    }

    loadOverview();
    loadAdGroups();
    loadCopies();
    loadKeywords();
    loadUtmLinks();
    loadTrend();
    loadLog();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'تفاصيل الحملة', 'نظرة شاملة على أداء واستهداف وإعدادات الحملة', $body, $script);
        exit;
    }

    /** GET /ads/competitors */
    public function showCompetitorsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/competitors'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('competitors');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🕵️ تحليل منافس من منظور إعلاني</h3><span class="p-card-sub">تحليل استشاري بالذكاء الاصطناعي - مش بيانات إعلانات حقيقية مسحوبة من المنافس</span></div>
            <div id="competitorSelectorBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="competitorInsightsCard" style="display:none;">
            <div class="p-card-head"><h3>💡 التوصيات</h3></div>
            <div id="competitorInsightsBox"></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    let selectedCompetitorId = null;

    async function loadCompetitors() {
        const box = document.getElementById('competitorSelectorBox');
        const res = await fetchJSON('/api/ads/competitors');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل قائمة المنافسين</div>'; return; }
        if (!res.data.length) {
            box.innerHTML = '<div class="p-cell-muted">لا يوجد منافسون مسجّلون بعد - أضف منافس أولًا من صفحة "مراقبة المنافسين".</div>';
            return;
        }

        box.innerHTML = `
            <select id="competitorSelect" class="p-select" style="width:100%;margin-bottom:8px;">
                ${res.data.map(c => `<option value="${c.id}">${esc(c.competitor_name || c.competitor_domain)}</option>`).join('')}
            </select>
            <textarea id="offerDesc" class="p-select" style="width:100%;min-height:60px;margin-bottom:8px;" placeholder="وصف مختصر لعرضك الإعلاني الحالي"></textarea>
            <button class="p-btn primary xs" onclick="analyzeCompetitor()">تحليل</button>
        `;
    }

    window.analyzeCompetitor = async function () {
        const competitorId = document.getElementById('competitorSelect').value;
        const offerDescription = document.getElementById('offerDesc').value.trim();
        if (!offerDescription) { P.toast('اكتب وصف العرض الأول', 'error'); return; }

        const card = document.getElementById('competitorInsightsCard');
        const box = document.getElementById('competitorInsightsBox');
        card.style.display = 'block';
        box.innerHTML = '<div class="p-loading-row">جارِ التحليل...</div>';

        const res = await fetchJSON('/api/ads/competitors/' + competitorId + '/analyze', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ offer_description: offerDescription }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }

        renderInsights(res.data.recommendations, res.data.disclaimer);
    };

    function renderInsights(recs, disclaimer) {
        const box = document.getElementById('competitorInsightsBox');
        if (!recs || !recs.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد توصيات</div>'; return; }
        box.innerHTML = recs.map(r => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <span class="pill ${r.priority === 'high' ? 'red' : (r.priority === 'medium' ? 'yellow' : 'gray')}">${esc(r.priority)}</span>
                ${esc(r.text)}
            </div>`).join('') + (disclaimer ? `<div class="p-cell-muted" style="font-size:11px;margin-top:8px;">${esc(disclaimer)}</div>` : '');
    }

    loadCompetitors();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'المنافسون', 'تحليل رسائل وتموضع المنافسين من منظور إعلاني', $body, $script);
        exit;
    }

    /** GET /api/ads/connections/status - تفاصيل كاملة لحالة ربط Google Ads وMeta Ads معًا (Connection Center) */
    public function getConnectionsStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $rows = $this->db->query(
            "SELECT platform, status, external_account_id, last_error, last_synced_at, token_expires_at
             FROM platform_connections WHERE user_id = ? AND platform IN ('meta_ads','google_ads')",
            [$access['owner_id']]
        );

        $byPlatform = ['meta_ads' => null, 'google_ads' => null];
        foreach ($rows as $r) {
            // لو فيه أكتر من صف لنفس المنصة (نادر) ناخد الأحدث حسب last_synced_at
            if ($byPlatform[$r['platform']] === null || ($r['last_synced_at'] ?? '') > ($byPlatform[$r['platform']]['last_synced_at'] ?? '')) {
                $byPlatform[$r['platform']] = $r;
            }
        }

        $metaConfigured = (new MetaOAuthClient())->isConfigured();
        $googleConfigured = (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, $this->googleAdsRedirectUri()))->isConfigured()
            && $this->googleAdsRedirectUri() !== '';

        return $this->success([
            'meta_ads' => ['configured' => $metaConfigured, 'connection' => $byPlatform['meta_ads']],
            'google_ads' => ['configured' => $googleConfigured, 'connection' => $byPlatform['google_ads']],
        ]);
    }

    /** GET /ads/connections */
    public function showConnectionsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/connections'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('connections');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>Google Ads</h3></div>
            <div id="ccGoogleAds"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>Meta Ads (Facebook / Instagram)</h3></div>
            <div id="ccMetaAds"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    const STATUS_LABELS = {
        connected: ['✔ مربوط', 'green'],
        disconnected: ['غير مربوط', 'gray'],
        error: ['⚠ خطأ', 'red'],
        token_expired: ['⏰ انتهت الصلاحية - محتاج إعادة ربط', 'yellow'],
    };

    function renderProvider(boxId, data, connectUrl, syncFn, disconnectFn) {
        const box = document.getElementById(boxId);
        if (!data.configured) { box.innerHTML = '<div class="p-cell-muted">لسه مش مفعّل من إدارة النظام (بيانات الربط ناقصة في إعدادات السيرفر) - Setup Required</div>'; return; }

        const conn = data.connection;
        if (!conn) { box.innerHTML = `<a href="${connectUrl}" class="p-btn primary xs">🔗 ربط الحساب</a>`; return; }

        const [label, color] = STATUS_LABELS[conn.status] || [esc(conn.status), 'gray'];
        box.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div>
                    <span class="pill ${color}">${label}</span> ${esc(conn.external_account_id || '')}
                    <div class="p-cell-muted" style="font-size:12px;margin-top:4px;">آخر مزامنة: ${conn.last_synced_at ? esc(conn.last_synced_at) : 'لم تتم بعد'}</div>
                    ${conn.last_error ? `<div style="color:#b91c1c;font-size:12px;margin-top:2px;">آخر خطأ: ${esc(conn.last_error)}</div>` : ''}
                </div>
                <div style="display:flex;gap:8px;">
                    ${conn.status === 'connected' ? `<button class="p-btn outline xs" onclick="${syncFn}()">🔄 مزامنة الآن</button>` : `<a href="${connectUrl}" class="p-btn outline xs">🔗 إعادة الربط</a>`}
                    <button class="p-btn danger xs" onclick="${disconnectFn}()">فصل الربط</button>
                </div>
            </div>`;
    }

    window.ccSyncGoogle = async function () {
        P.toast('جارِ المزامنة...', 'success');
        const res = await fetchJSON('/api/ads/google/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); else P.toast(res.error || 'تعذرت المزامنة', 'error');
        loadStatus();
    };
    window.ccDisconnectGoogle = async function () {
        if (!confirm('متأكد من فصل ربط Google Ads؟')) return;
        await fetchJSON('/api/ads/google/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        loadStatus();
    };
    window.ccSyncMeta = async function () {
        P.toast('جارِ المزامنة...', 'success');
        const res = await fetchJSON('/api/ads/meta/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) P.toast('تمت المزامنة', 'success'); else P.toast(res.error || 'تعذرت المزامنة', 'error');
        loadStatus();
    };
    window.ccDisconnectMeta = async function () {
        if (!confirm('متأكد من فصل ربط Meta Ads؟')) return;
        await fetchJSON('/api/ads/meta/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        loadStatus();
    };

    async function loadStatus() {
        const res = await fetchJSON('/api/ads/connections/status');
        if (!res.success) return;
        renderProvider('ccGoogleAds', res.data.google_ads, '/ads/connect/google', 'ccSyncGoogle', 'ccDisconnectGoogle');
        renderProvider('ccMetaAds', res.data.meta_ads, '/ads/connect/meta', 'ccSyncMeta', 'ccDisconnectMeta');
    }

    loadStatus();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'ربط المنصات', 'حالة ربط Google Ads وMeta Ads والمزامنة', $body, $script);
        exit;
    }

    /** GET /ads/alerts */
    public function showAlertsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/alerts'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('alerts');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🔔 التنبيهات الاستباقية</h3><span class="p-card-sub">قواعد آلية تراقب أداء حملاتك الحقيقي (إنفاق/CPC/CTR/صفحة هبوط) وتنبهك عند حدوث مشكلة</span></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                <button class="p-btn primary" onclick="runAlertsNow()">تقييم فوري الآن</button>
                <button class="p-btn" onclick="markAllRead()">تعليم الكل كمقروء</button>
            </div>
            <div id="alertsList"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>⚙️ إعدادات القواعد</h3><span class="p-card-sub">فعّل/عطّل كل قاعدة واضبط حدّها</span></div>
            <div id="rulesBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    const RULE_LABELS = {
        budget_exhausted: 'نفاد الميزانية اليومية',
        cpc_spike: 'ارتفاع تكلفة النقرة',
        ctr_drop: 'انخفاض نسبة النقر',
        landing_page_down: 'صفحة هبوط معطّلة',
        budget_pacing: 'إنفاق أبطأ من المتوقع',
    };
    const RULE_HINTS = {
        budget_exhausted: 'أشعرني لما يصرف % من ميزانيته اليومية',
        cpc_spike: 'نسبة زيادة عن متوسط الأسبوع السابق',
        ctr_drop: 'نسبة انخفاض عن متوسط الأسبوع السابق',
        landing_page_down: 'الفحص بدون حد نسبة',
        budget_pacing: 'نسبة اليوم المنقضي',
    };

    async function loadAlerts() {
        const res = await fetchJSON('/api/ads/alerts');
        const box = document.getElementById('alertsList');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }

        const severityIcon = { info: 'ℹ️', warning: '⚠️', critical: '🚨' };
        const severityColor = { info: 'var(--info-color, #1890ff)', warning: 'var(--warning-color, #fa8c16)', critical: 'var(--danger-color, #f5222d)' };

        if (!res.data.alerts.length) {
            box.innerHTML = '<div class="p-cell-muted">مفيش تنبيهات حالياً - كل الحملات داخل الحدود الطبيعية 🎉</div>';
            return;
        }
        box.innerHTML = res.data.alerts.map(a => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div style="flex:1;">
                    <div style="color:${severityColor[a.severity] || '#666'};font-weight:bold;">${severityIcon[a.severity] || 'ℹ️'} ${esc(a.title)} <span class="p-cell-muted" style="font-weight:normal;font-size:11px;">${esc(RULE_LABELS[a.rule_type] || a.rule_type)}</span></div>
                    <div class="p-cell-muted" style="font-size:12px;margin-top:2px;">${esc(a.body || '')}</div>
                </div>
                <button class="p-btn xs" onclick="dismissAlert(${a.id})">تجاهل</button>
            </div>`).join('');
    }

    async function loadRules() {
        const res = await fetchJSON('/api/ads/alerts/rules');
        const box = document.getElementById('rulesBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }

        box.innerHTML = Object.entries(res.data.rules).map(([type, rule]) => `
            <div style="display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <input type="checkbox" id="rule-${type}" ${rule.is_enabled ? 'checked' : ''} onchange="saveRules()" style="transform:scale(1.2);">
                <div>
                    <div><b>${esc(RULE_LABELS[type] || type)}</b></div>
                    <div class="p-cell-muted" style="font-size:11px;">${esc(RULE_HINTS[type] || '')}</div>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="number" id="rule-val-${type}" class="p-select xs" style="width:90px;" value="${rule.threshold_value ?? ''}" min="1" max="999" step="1" placeholder="—" onchange="saveRules()">
                    <span class="p-cell-muted" style="font-size:11px;">%</span>
                </div>
            </div>`).join('');
        box.innerHTML += '<div style="margin-top:10px;"><button class="p-btn primary" onclick="saveRules()">حفظ القواعد</button></div>';
    }

    window.runAlertsNow = async function () {
        const res = await fetchJSON('/api/ads/alerts/run', { method: 'POST' });
        if (res.success) { P.toast('تم التقييم - ' + res.data.generated + ' تنبيه جديد', 'success'); loadAlerts(); }
        else P.toast(res.error || 'تعذر التقييم', 'error');
    };

    window.markAllRead = async function () {
        const res = await fetchJSON('/api/ads/alerts/read-all', { method: 'POST' });
        if (res.success) P.toast('تم تعليم الكل كمقروء', 'success');
    };

    window.dismissAlert = async function (id) {
        const res = await fetchJSON('/api/ads/alerts/' + id + '/dismiss', { method: 'POST' });
        if (res.success) { P.toast('تم تجاهل التنبيه', 'success'); loadAlerts(); }
        else P.toast(res.error || 'تعذر التجاهل', 'error');
    };

    window.saveRules = async function () {
        const types = Object.keys(RULE_LABELS);
        const rules = {};
        types.forEach(t => {
            rules[t] = {
                is_enabled: document.getElementById('rule-' + t).checked ? 1 : 0,
                threshold_value: document.getElementById('rule-val-' + t).value || null,
            };
        });
        const res = await fetchJSON('/api/ads/alerts/rules', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rules }),
        });
        if (res.success) P.toast('تم حفظ القواعد', 'success');
        else P.toast(res.error || 'تعذر الحفظ', 'error');
    };

    loadAlerts();
    loadRules();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'التنبيهات الاستباقية', 'مراقبة تلقائية لصحة الحملات الإعلانية', $body, $script);
        exit;
    }

    /** GET /ads/autopilot */
    public function showAutopilotPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/autopilot'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('autopilot');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" id="autopilotCard" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🤖 AI Ads Autopilot</h3><span class="p-card-sub">وضع التشغيل وحدود الأمان - الذكاء الاصطناعي بيقترح، وينفّذ بس داخل الحدود دي</span></div>
            <div id="autopilotSettingsBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="pendingActionsCard" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>⏳ قرارات بانتظار الموافقة</h3><span class="p-card-sub">توصيات الذكاء الاصطناعي اللي محتاجة موافقتك قبل التنفيذ الفعلي</span></div>
            <div id="pendingActionsBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card" id="optimizationLogCard">
            <div class="p-card-head"><h3>📜 سجل قرارات التحسين</h3><span class="p-card-sub">كل تغيير نفّذه النظام - إمتى، ليه، وقابلية التراجع</span></div>
            <div id="optimizationLogBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    async function loadAutopilotSettings() {
        const res = await fetchJSON('/api/ads/autopilot/settings');
        const box = document.getElementById('autopilotSettingsBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل الإعدادات</div>'; return; }
        const s = res.data;
        const modeLabels = { manual: 'يدوي - توصيات فقط', approval: 'موافقة - تنفيذ بعد موافقتك', autopilot: 'تلقائي - تنفيذ داخل الحدود' };
        box.innerHTML = `
            <label>وضع التشغيل</label>
            <select id="apMode" class="p-select" style="width:100%;margin-bottom:12px;">
                ${Object.entries(modeLabels).map(([k, l]) => `<option value="${k}" ${s.optimization_mode === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}
            </select>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                <div><label>أقصى ميزانية يومية إجمالية</label><input type="number" id="apMaxDaily" class="p-select" style="width:100%;" value="${s.max_daily_budget ?? ''}"></div>
                <div><label>أقصى تكلفة اكتساب (CPA) مقبولة</label><input type="number" id="apMaxCpa" class="p-select" style="width:100%;" value="${s.max_allowed_cpa ?? ''}"></div>
                <div><label>أقل ROAS مقبول</label><input type="number" id="apMinRoas" class="p-select" style="width:100%;" value="${s.min_required_roas ?? ''}"></div>
                <div><label>أقصى عدد تغييرات تلقائية/يوم</label><input type="number" id="apMaxChanges" class="p-select" style="width:100%;" value="${s.max_changes_per_day}"></div>
                <div><label>أقصى نسبة زيادة ميزانية لكل قرار %</label><input type="number" id="apIncPct" class="p-select" style="width:100%;" value="${s.max_budget_increase_pct}"></div>
                <div><label>أقصى نسبة تخفيض ميزانية لكل قرار %</label><input type="number" id="apDecPct" class="p-select" style="width:100%;" value="${s.max_budget_decrease_pct}"></div>
            </div>
            <div class="p-cell-muted" style="font-size:11.5px;margin-bottom:10px;">في وضع "تلقائي": أي قرار يتجاوز الحدود دي ما بيتنفذش تلقائيًا أبدًا - بيتحوّل لقسم "قرارات بانتظار الموافقة" فوق.</div>
            <button class="p-btn primary" onclick="saveAutopilotSettings()">حفظ الإعدادات</button>
        `;
    }

    window.saveAutopilotSettings = async function () {
        const payload = {
            optimization_mode: document.getElementById('apMode').value,
            max_daily_budget: document.getElementById('apMaxDaily').value || null,
            max_allowed_cpa: document.getElementById('apMaxCpa').value || null,
            min_required_roas: document.getElementById('apMinRoas').value || null,
            max_changes_per_day: document.getElementById('apMaxChanges').value,
            max_budget_increase_pct: document.getElementById('apIncPct').value,
            max_budget_decrease_pct: document.getElementById('apDecPct').value,
        };
        const res = await fetchJSON('/api/ads/autopilot/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (res.success) P.toast('تم حفظ إعدادات Autopilot', 'success');
        else P.toast(res.error || 'تعذر الحفظ', 'error');
    };

    async function loadPendingActions() {
        const res = await fetchJSON('/api/ads/autopilot/pending');
        const box = document.getElementById('pendingActionsBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        if (!res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد قرارات معلّقة حاليًا</div>'; return; }
        box.innerHTML = res.data.map(a => `
            <div class="p-card" style="margin-bottom:8px;padding:10px;">
                <div><b>${esc(a.action_type)}</b> - <a href="/ads/campaigns/${a.campaign_id}">حملة #${a.campaign_id}</a> ${a.before_value ? `(${esc(a.before_value)} ← ${esc(a.after_value)})` : ''}</div>
                <div class="p-cell-muted" style="font-size:12px;margin:4px 0;">${esc(a.reasoning)}</div>
                ${a.blocked_reason ? `<div class="p-cell-muted" style="font-size:11px;color:#b45309;">⚠ ${esc(a.blocked_reason)}</div>` : ''}
                <div style="display:flex;gap:8px;margin-top:6px;">
                    <button class="p-btn primary xs" onclick="decidePendingAction(${a.id}, 'approve')">✅ موافقة وتنفيذ</button>
                    <button class="p-btn outline xs" onclick="decidePendingAction(${a.id}, 'reject')">❌ رفض</button>
                </div>
            </div>`).join('');
    }

    window.decidePendingAction = async function (id, decision) {
        const res = await fetchJSON(`/api/ads/autopilot/pending/${id}/${decision}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast(decision === 'approve' ? 'تم التنفيذ' : 'تم الرفض', 'success'); loadPendingActions(); loadOptimizationLog(); }
        else P.toast(res.error || 'تعذر تنفيذ القرار', 'error');
    };

    async function loadOptimizationLog() {
        const res = await fetchJSON('/api/ads/autopilot/logs');
        const box = document.getElementById('optimizationLogBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        if (!res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد سجل بعد</div>'; return; }
        box.innerHTML = res.data.map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div><b>${esc(l.action_type)}</b> <span class="p-cell-muted" style="font-size:11px;">(${esc(l.mode)} - ${l.applied_automatically == 1 ? 'نُفّذ فعليًا' : 'توصية فقط'})</span></div>
                ${l.before_value ? `<div class="p-cell-muted" style="font-size:12px;">${esc(l.before_value)} ← ${esc(l.after_value || '')}</div>` : ''}
                <div class="p-cell-muted" style="font-size:12px;">${esc(l.description)}</div>
                ${(l.can_rollback == 1 && !l.rolled_back_at) ? `<button class="p-btn outline xs" style="margin-top:4px;" onclick="rollbackLog(${l.id})">↩ تراجع</button>` : ''}
                ${l.rolled_back_at ? `<span class="p-cell-muted" style="font-size:11px;">تم التراجع عنه</span>` : ''}
            </div>`).join('');
    }

    window.rollbackLog = async function (id) {
        if (!confirm('متأكد من التراجع عن التغيير ده؟')) return;
        const res = await fetchJSON(`/api/ads/autopilot/logs/${id}/rollback`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم التراجع', 'success'); loadOptimizationLog(); }
        else P.toast(res.error || 'تعذر التراجع', 'error');
    };

    loadAutopilotSettings();
    loadPendingActions();
    loadOptimizationLog();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'AI Ads Autopilot', 'وضع التشغيل، حدود الأمان، القرارات المعلّقة، وسجل التحسين', $body, $script);
        exit;
    }

    /** GET /ads/copilot */
    public function showCopilotPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/copilot'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('copilot');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" id="copilotCard">
            <div class="p-card-head"><h3>💬 AI Marketing Copilot</h3><span class="p-card-sub">اسأل عن أداء حسابك، أو اطلب تعديل مباشر (هيمر عبر نفس وضع التشغيل وحدود الأمان)</span></div>
            <div id="copilotMessages" style="max-height:420px;overflow-y:auto;margin-bottom:10px;"></div>
            <div style="display:flex;gap:8px;">
                <input type="text" id="copilotInput" class="p-select" style="flex:1;" placeholder="مثال: ليه تكلفة العميل زادت؟">
                <button class="p-btn primary" onclick="sendCopilotMessage()">إرسال</button>
            </div>
            <div class="p-cell-muted" style="font-size:11px;margin-top:8px;">أمثلة: "أنهي حملة محتاجة انتباه؟" · "ليه الأداء قلّ؟" · "فين بضيّع ميزانية؟" · "أنهي حملة أرشحها للتوسّع؟"</div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    window.sendCopilotMessage = async function () {
        const input = document.getElementById('copilotInput');
        const msg = input.value.trim();
        if (!msg) return;
        const box = document.getElementById('copilotMessages');
        box.innerHTML += `<div style="text-align:end;margin-bottom:6px;"><span class="pill">${esc(msg)}</span></div>`;
        input.value = '';
        box.scrollTop = box.scrollHeight;

        const res = await fetchJSON('/api/ads/copilot/ask', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: msg }) });
        const reply = res.success ? res.data.reply : (res.error || 'تعذر الرد حاليًا');
        box.innerHTML += `<div style="margin-bottom:6px;"><span class="p-cell-muted">${esc(reply)}</span></div>`;
        box.scrollTop = box.scrollHeight;
    };

    document.getElementById('copilotInput').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') window.sendCopilotMessage();
    });
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'AI Copilot', 'اسأل عن أداء حسابك أو اطلب تعديل مباشر', $body, $script);
        exit;
    }

    /** GET /ads/market-research */
    public function showMarketResearchPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/market-research'));
            exit;
        }

        $tabsHtml = $this->adsTabsHtml('market_research');
        $body = <<<HTML
        {$tabsHtml}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🌍 بحث الأسواق والدول (AI)</h3><span class="p-card-sub">توصية استشارية مبنية على معرفة السوق - مش بيانات طلب بحث حقيقية</span></div>
            <textarea id="mrGoalDesc" class="p-select" style="width:100%;min-height:60px;" placeholder="مثال: عايز أجيب حجوزات سياحية من أوروبا لمصر"></textarea>
            <button class="p-btn primary xs" style="margin-top:8px;" onclick="runMarketResearch()">تحليل الأسواق</button>
            <div id="marketResearchResults" style="margin-top:10px;"></div>
        </div>

        <div class="p-card" id="mrHistoryCard">
            <div class="p-card-head"><h3>📜 أرشيف التحليلات السابقة</h3></div>
            <div id="mrHistoryBox"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const colors = { high: 'green', medium: 'yellow', low: 'gray' };

    function renderCountries(countries, disclaimer) {
        return countries.map(c => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <span class="pill ${colors[c.opportunity] || ''}">${esc(c.opportunity)}</span> <b>${esc(c.country)}</b>
                <div class="p-cell-muted" style="font-size:12px;">${esc(c.reasoning)}</div>
            </div>
        `).join('') + `<div class="p-cell-muted" style="font-size:11px;margin-top:6px;">${esc(disclaimer)}</div>`;
    }

    window.runMarketResearch = async function () {
        const box = document.getElementById('marketResearchResults');
        box.innerHTML = 'جارِ التحليل...';
        const goalDescription = document.getElementById('mrGoalDesc').value.trim();
        if (!goalDescription) { box.innerHTML = '<span style="color:#b91c1c;">اكتب وصف العرض الأول</span>'; return; }

        const res = await fetchJSON('/api/ads/market-research', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ goal_description: goalDescription }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }
        box.innerHTML = renderCountries(res.data.countries, res.data.disclaimer);
        loadHistory();
    };

    async function loadHistory() {
        const box = document.getElementById('mrHistoryBox');
        const res = await fetchJSON('/api/ads/market-research/history');
        if (!res.success || !res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد تحليلات سابقة بعد</div>'; return; }
        box.innerHTML = res.data.map(h => `
            <div style="padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div class="p-cell-muted" style="font-size:11px;">${esc(h.created_at)}</div>
                <div style="margin:4px 0;">${esc(h.goal_description)}</div>
                ${h.result_json && h.result_json.countries ? renderCountries(h.result_json.countries, h.result_json.disclaimer) : ''}
            </div>`).join('');
    }

    loadHistory();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'بحث الأسواق', 'ترشيح وترتيب الدول المناسبة لحملتك القادمة', $body, $script);
        exit;
    }

    /**
     * بيحدد "مين صاحب حساب الإعلانات اللي المستخدم الحالي بيشتغل عليه
     * دلوقتي" ويتحقق إن دوره كافي. المشروع مالوش مفهوم "Workspace Switcher"
     * جاهز، فبنستخدم `owner_id` اختياري في الطلب: لو موجود وعنده صلاحية
     * عليه (كعضو فريق)، بيشتغل عليه؛ لو مش موجود، بيشتغل على حسابه هو
     * (السلوك الافتراضي القديم زي ما هو تمامًا - Backward-compatible 100%
     * لأي عميل مفعّلش الفريق أصلًا).
     *
     * @return array{owner_id:int, role:string}|null null يعني ممنوع (403)
     */
    /**
     * زي resolveAdsAccess() لكن بيحدد صاحب الحساب من الحملة نفسها مباشرة
     * (مش من `owner_id` في الطلب) - يُستخدم في أي endpoint شغّال على
     * campaign_id محدّد أصلًا (زي /campaigns/{id}/status) حيث معرفة
     * المالك مش محتاجة يحددها الطالب، هي معروفة من الحملة نفسها.
     * @return array{owner_id:int, role:string}|null
     */
    /**
     * زي resolveCampaignAccess() لكن لأي مورد تاني معروف صاحبه مباشرة (زي
     * Competitor) - نفس منطق الفحص بالظبط، مجرّد من الحاجة لكائن AdCampaign.
     * @return array{owner_id:int, role:string}|null
     */
    private function resolveAdsAccessForOwner(int $resourceOwnerUserId, string $minRole = 'viewer'): ?array
    {
        $currentUserId = (int) $this->user['id'];
        if ($resourceOwnerUserId === $currentUserId) {
            return ['owner_id' => $resourceOwnerUserId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $resourceOwnerUserId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $resourceOwnerUserId, 'role' => $access['role']];
    }

    private function resolveCampaignAccess(AdCampaign $campaign, string $minRole = 'viewer'): ?array
    {
        // Soft Delete: الحملة المحذوفة غير قابلة للوصول نهائيًا حتى بالرابط المباشر
        if ($campaign->getAttribute('deleted_at')) {
            return null;
        }

        $ownerId = (int) $campaign->getAttribute('user_id');
        $currentUserId = (int) $this->user['id'];

        if ($ownerId === $currentUserId) {
            return ['owner_id' => $ownerId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $ownerId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $ownerId, 'role' => $access['role']];
    }

    private function resolveAdsAccess(string $minRole = 'viewer'): ?array
    {
        $currentUserId = (int) $this->user['id'];
        $requestedOwnerId = $this->get('owner_id') ? (int) $this->get('owner_id') : $currentUserId;

        if ($requestedOwnerId === $currentUserId) {
            return ['owner_id' => $currentUserId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $requestedOwnerId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $requestedOwnerId, 'role' => $access['role']];
    }

    private function adsTabsHtml(string $active): string
    {
        $tabs = [
            'dashboard' => ['نظرة عامة', '/ads'],
            'campaigns' => ['الحملات', '/ads#campaignsTable'],
            'reports' => ['التقارير', '/ads/reports'],
            'budget' => ['الميزانية والإنفاق', '/ads/budget'],
            'market_research' => ['بحث الأسواق', '/ads/market-research'],
            'competitors' => ['المنافسون', '/ads/competitors'],
            'autopilot' => ['Autopilot', '/ads/autopilot'],
            'copilot' => ['AI Copilot', '/ads/copilot'],
            'alerts' => ['التنبيهات', '/ads/alerts'],
            'connections' => ['ربط المنصات', '/ads/connections'],
            'team' => ['فريق العمل', '/ads/team'],
        ];
        $html = '<div class="p-tabs" style="margin-bottom:18px;flex-wrap:wrap;">';
        foreach ($tabs as $key => [$label, $url]) {
            $activeClass = $key === $active ? ' active' : '';
            $html .= "<a href=\"{$url}\" class=\"p-tab{$activeClass}\" style=\"text-decoration:none;\">{$label}</a>";
        }
        return $html . '</div>';
    }

    private function firstWebsiteForUser(int $userId): ?array
    {
        $rows = $this->db->query("SELECT id FROM websites WHERE user_id = ? ORDER BY created_at ASC LIMIT 1", [$userId]);
        return $rows[0] ?? null;
    }

    private function googleAdsRedirectUri(): string
    {
        $envValue = (string) (env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: '');
        if (class_exists('SystemSettingsService')) {
            try {
                $settings = new SystemSettingsService();
                $value = $settings->get('google_ads_oauth_redirect_uri', '');
                if ($value !== '') {
                    return $value;
                }
                $legacyValue = $settings->get('integration_google_ads_oauth_redirect_uri', '');
                if ($legacyValue !== '') {
                    return $legacyValue;
                }
            } catch (Throwable $e) {
                // نكمل على .env لو جدول الإعدادات غير متاح مؤقتًا
            }
        }
        if ($envValue !== '') {
            return $envValue;
        }
        // احتياطي: لو الرابط مش متسجل في أي مكان نستخدم الافتراضي بناءً على APP_URL
        $base = rtrim((string) (env('APP_URL') ?: ''), '/');
        return $base !== '' ? $base . '/ads/connect/google/callback' : '';
    }

    private function renderAdsOAuthError(string $message): void
    {
        $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>' . $message . '<br><br><a href="/ads" class="p-btn primary">الرجوع لصفحة الإعلانات</a></div></div>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'تعذر الربط', 'Meta Ads', $body, '');
    }
}
