<?php

/**
 * Tourfecto - Business Center Controller
 * Business Control Center Phase 23 - Frontend UX
 * @version 1.0.0
 *
 * لوحة موحدة لمركز إدارة الـBusiness. الصفحة Frontend-Only: بتستهلك
 * الـAPIs الجاهزة (overview/team/api-keys/audit) اللي كلها AuthMiddleware-
 * protected وبتفحص الصلاحية عبر BusinessAccessService جوه الـControllers.
 *
 * ملاحظة أمان: الصفحة نفسها مفيش فيها أي منطق وصول - كل قرارات الصلاحية
 * (من يشوف/يعدّل مين) بتتحل جوه الـAPI اللي الصفحة بتنادي عليه، فمفيش
 * طريقة لتجاوز الـRBAC من الواجهة.
 */
class BusinessCenterController extends Controller
{
    /** GET /business-center */
    public function index(array $params = []): array
    {
        $body = $this->buildBody();
        $script = $this->buildScript();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage(
            'business_center',
            $this->tr('business_center.page.title'),
            $this->tr('business_center.page.subtitle'),
            $body,
            $script
        );
        exit;
    }

    private function buildBody(): string
    {
        $tOverview = $this->tr('business_center.tab.overview');
        $tTeam = $this->tr('business_center.tab.team');
        $tKeys = $this->tr('business_center.tab.keys');
        $tAudit = $this->tr('business_center.tab.audit');
        $tNoBusiness = $this->tr('business_center.empty.no_business');

        $businessTypeOptions = '';
        if (class_exists('Business')) {
            foreach (Business::allowedBusinessTypes() as $value => $label) {
                $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
                $businessTypeOptions .= '<option value="' . $safeValue . '">' . $safeLabel . '</option>';
            }
        }

        return <<<HTML
<div class="p-toolbar">
    <div class="p-tabs" id="bcTabs">
        <button type="button" class="p-tab active" data-bc-tab="overview">{$tOverview}</button>
        <button type="button" class="p-tab" data-bc-tab="team">{$tTeam}</button>
        <button type="button" class="p-tab" data-bc-tab="keys">{$tKeys}</button>
        <button type="button" class="p-tab" data-bc-tab="audit">{$tAudit}</button>
    </div>
</div>

<!-- ============ Overview ============ -->
<section class="bc-pane" data-bc-pane="overview">
    <div id="bcNoBusiness" class="p-card" style="display:none;">
        <div class="p-empty"><div class="p-empty-icon">🏢</div>
            <h3>{$this->tr('business_center.empty.title')}</h3>
            <p>{$tNoBusiness}</p>
            <button class="p-btn primary" onclick="bcShow('profileForm')">{$this->tr('business_center.empty.create_btn')}</button>
        </div>
    </div>

    <div id="bcBusinessWrap" style="display:none;">
        <div class="p-grid cols-4" id="bcStatTiles">
            <div class="p-card stat-tile"><div class="stat-icon green">📍</div><div class="stat-info"><div class="stat-value" id="bcStatLocations">0</div><div class="stat-label">{$this->tr('business_center.stat.locations')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon blue">🧩</div><div class="stat-info"><div class="stat-value" id="bcStatServices">0</div><div class="stat-label">{$this->tr('business_center.stat.services')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">🎯</div><div class="stat-info"><div class="stat-value" id="bcStatMarkets">0</div><div class="stat-label">{$this->tr('business_center.stat.markets')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">🤖</div><div class="stat-info"><div class="stat-value" id="bcStatAi">0</div><div class="stat-label">{$this->tr('business_center.stat.ai')}</div></div></div>
        </div>

        <div class="p-grid cols-2" style="margin-top:18px;align-items:start;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$this->tr('business_center.readiness.title')}</h3><span class="p-card-sub">{$this->tr('business_center.readiness.sub')}</span></div>
                <div style="display:flex;align-items:center;gap:20px;padding:10px 4px;">
                    <div class="bc-ring" id="bcReadinessRing"><span id="bcReadinessScore">0%</span><b id="bcReadinessGrade">-</b></div>
                    <div style="flex:1;display:flex;flex-direction:column;gap:8px;" id="bcCategoryBars"></div>
                </div>
            </div>
            <div class="p-card no-pad">
                <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('business_center.next_steps.title')}</h3></div>
                <div class="p-table-scroll"><table class="p-table"><tbody id="bcNextSteps"></tbody></table></div>
            </div>
        </div>
    </div>
</section>

<!-- ============ Business Profile ============ -->
<section class="bc-pane" data-bc-pane="profileForm" style="display:none;">
    <div class="p-card">
        <div class="p-card-head"><h3 id="bcProfileTitle">{$this->tr('business_center.profile.title')}</h3></div>
        <div style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
            <input type="text" id="bcLegalName" class="p-input" placeholder="{$this->tr('business_center.profile.legal_name')} *">
            <input type="text" id="bcTradeName" class="p-input" placeholder="{$this->tr('business_center.profile.trade_name')}">
            <textarea id="bcDescription" class="p-input" rows="3" placeholder="{$this->tr('business_center.profile.description')}"></textarea>
            <input type="text" id="bcWebsite" class="p-input" placeholder="{$this->tr('business_center.profile.website')}">
            <input type="text" id="bcEmail" class="p-input" placeholder="{$this->tr('business_center.profile.email')}">
            <input type="text" id="bcPhone" class="p-input" placeholder="{$this->tr('business_center.profile.phone')}">
            <div class="p-grid cols-2">
                <select id="bcBusinessType" class="p-select"><option value="">{$this->tr('business_center.profile.business_type')}</option>{$businessTypeOptions}</select>
                <input type="text" id="bcCountry" class="p-input" placeholder="{$this->tr('business_center.profile.country_code')}">
            </div>
            <div class="p-grid cols-2">
                <input type="text" id="bcCity" class="p-input" placeholder="{$this->tr('business_center.profile.city')}">
                <input type="text" id="bcCurrency" class="p-input" placeholder="{$this->tr('business_center.profile.currency')}">
            </div>
            <div style="display:flex;gap:10px;">
                <button class="p-btn primary" onclick="bcSaveProfile()">{$this->tr('common.save')}</button>
                <button class="p-btn outline" onclick="bcGo('overview')">{$this->tr('common.cancel')}</button>
            </div>
        </div>
    </div>
</section>

<!-- ============ Team ============ -->
<section class="bc-pane" data-bc-pane="team" style="display:none;">
    <div class="p-card no-pad">
        <div class="p-card-head" style="padding:18px 20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h3>{$this->tr('business_center.team.title')}</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="email" id="bcInviteEmail" class="p-input" style="max-width:220px;" placeholder="{$this->tr('business_center.team.invite_email')}">
                <select id="bcInviteRole" class="p-select"><option value="member">{$this->tr('business_center.team.role.member')}</option><option value="viewer">{$this->tr('business_center.team.role.viewer')}</option><option value="admin">{$this->tr('business_center.team.role.admin')}</option></select>
                <button class="p-btn primary" onclick="bcInvite()">{$this->tr('business_center.team.invite_btn')}</button>
            </div>
        </div>
        <div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>{$this->tr('business_center.team.col.member')}</th><th>{$this->tr('business_center.team.col.role')}</th><th>{$this->tr('business_center.team.col.status')}</th><th>{$this->tr('business_center.team.col.actions')}</th></tr></thead>
            <tbody id="bcTeamBody"><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
        </table></div>
    </div>
</section>

<!-- ============ API Keys ============ -->
<section class="bc-pane" data-bc-pane="keys" style="display:none;">
    <div class="p-card no-pad">
        <div class="p-card-head" style="padding:18px 20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h3>{$this->tr('business_center.keys.title')}</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="bcKeyName" class="p-input" style="max-width:200px;" placeholder="{$this->tr('business_center.keys.name')}">
                <select id="bcKeyScope" class="p-select"><option value="full_access">{$this->tr('business_center.keys.scope.full')}</option><option value="read_only">{$this->tr('business_center.keys.scope.read')}</option></select>
                <button class="p-btn primary" onclick="bcCreateKey()">{$this->tr('business_center.keys.create_btn')}</button>
            </div>
        </div>
        <div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>{$this->tr('business_center.keys.col.name')}</th><th>{$this->tr('business_center.keys.col.scope')}</th><th>{$this->tr('business_center.keys.col.prefix')}</th><th>{$this->tr('business_center.keys.col.last_used')}</th><th>{$this->tr('business_center.keys.col.status')}</th><th>{$this->tr('business_center.keys.col.actions')}</th></tr></thead>
            <tbody id="bcKeysBody"><tr class="p-loading-row"><td colspan="6">{$this->tr('common.loading')}</td></tr></tbody>
        </table></div>
        <div id="bcKeyCreated" class="p-alert" style="display:none;margin:16px 20px;"></div>
    </div>
</section>

<!-- ============ Audit Log ============ -->
<section class="bc-pane" data-bc-pane="audit" style="display:none;">
    <div class="p-card no-pad">
        <div class="p-card-head" style="padding:18px 20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h3>{$this->tr('business_center.audit.title')}</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="bcAuditSearch" class="p-input" style="max-width:200px;" placeholder="{$this->tr('business_center.audit.search')}">
                <button class="p-btn outline" onclick="loadAudit(1)">{$this->tr('common.search')}</button>
            </div>
        </div>
        <div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>{$this->tr('business_center.audit.col.action')}</th><th>{$this->tr('business_center.audit.col.actor')}</th><th>{$this->tr('business_center.audit.col.object')}</th><th>{$this->tr('business_center.audit.col.result')}</th><th>{$this->tr('business_center.audit.col.date')}</th></tr></thead>
            <tbody id="bcAuditBody"><tr class="p-loading-row"><td colspan="5">{$this->tr('common.loading')}</td></tr></tbody>
        </table></div>
        <div class="p-card-head" style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;">
            <span id="bcAuditInfo"></span>
            <div style="display:flex;gap:8px;">
                <button class="p-btn outline xs" id="bcAuditPrev" onclick="loadAudit(bcAuditPage - 1)">←</button>
                <button class="p-btn outline xs" id="bcAuditNext" onclick="loadAudit(bcAuditPage + 1)">→</button>
            </div>
        </div>
    </div>
</section>
HTML;
    }

    private function buildScript(): string
    {
        return <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let bcBusinessId = null;
    window.bcAuditPage = 1;

    // ===== Tab switching =====
    function switchTab(name) {
        document.querySelectorAll('.p-tab').forEach(b => b.classList.toggle('active', b.dataset.bcTab === name));
        document.querySelectorAll('.bc-pane').forEach(s => {
            s.style.display = (s.dataset.bcPane === name) ? '' : 'none';
        });
    }
    window.bcGo = switchTab;

    document.querySelectorAll('.p-tab').forEach(b => {
        b.addEventListener('click', function () {
            const name = b.dataset.bcTab;
            switchTab(name);
            if (name === 'team') loadTeam();
            if (name === 'keys') loadKeys();
            if (name === 'audit') { bcAuditPage = 1; loadAudit(1); }
        });
    });

    // التبويبات دي كانت بتفضل "جاري التحميل" للأبد لو لسه مفيش ملف نشاط تجاري
    function bcNeedBusiness(bodyId, cols) {
        const tb = document.getElementById(bodyId);
        if (!tb) return;
        tb.innerHTML = '<tr><td colspan="' + cols + '" class="p-cell-muted" style="padding:18px;text-align:center;">'
            + 'لازم تعمل ملف النشاط التجاري الأول. '
            + '<button class="p-btn primary xs" onclick="bcShow(\'profileForm\')">إنشاء ملف النشاط التجاري</button></td></tr>';
    }

    // ===== Overview =====
    async function loadOverview() {
        const res = await fetchJSON('/api/business/overview');
        if (!res.success) { toast(res.error || I18N['common.load_failed'], 'error'); return; }
        const d = res.data || {};
        if (!d.business) {
            document.getElementById('bcNoBusiness').style.display = '';
            document.getElementById('bcBusinessWrap').style.display = 'none';
            bcNeedBusiness('bcTeamBody', 4); bcNeedBusiness('bcKeysBody', 6); bcNeedBusiness('bcAuditBody', 5);
            return;
        }
        bcBusinessId = d.business.id;
        document.getElementById('bcNoBusiness').style.display = 'none';
        document.getElementById('bcBusinessWrap').style.display = '';

        const stats = d.stats || {};
        document.getElementById('bcStatLocations').textContent = stats.locations_count || 0;
        document.getElementById('bcStatServices').textContent = stats.active_services_count || 0;
        document.getElementById('bcStatMarkets').textContent = (stats.target_countries_count || 0) + (stats.target_cities_count || 0);
        document.getElementById('bcStatAi').textContent = (stats.has_ai_context ? 1 : 0) + (stats.has_brand_settings ? 1 : 0) + '/2';

        // Readiness
        const r = d.readiness || {};
        const score = r.total || 0;
        document.getElementById('bcReadinessScore').textContent = score + '%';
        document.getElementById('bcReadinessGrade').textContent = r.grade || '-';
        const ring = document.getElementById('bcReadinessRing');
        ring.style.setProperty('--bc-p', score);

        const cats = r.categories || {};
        document.getElementById('bcCategoryBars').innerHTML = Object.values(cats).map(c => `
            <div class="bc-cat">
                <span class="bc-cat-label">${esc(c.label)}</span>
                <span class="bc-cat-track"><i style="width:${c.score}%"></i></span>
                <span class="bc-cat-score">${c.score}%</span>
            </div>`).join('');

        // Next steps
        const steps = d.next_steps || [];
        const tb = document.getElementById('bcNextSteps');
        tb.innerHTML = steps.length ? steps.map(s => `
            <tr><td style="padding:10px 20px;border-bottom:1px solid var(--border-color, #eee);">
                <span class="pill ${s.priority === 'high' ? 'red' : 'green'}">${esc(s.priority)}</span>
                <span style="margin-inline-start:8px;">${esc(s.message)}</span>
            </td></tr>`).join('') : `<tr><td class="p-cell-muted" style="padding:14px 20px;">${I18N['common.no_records_yet']}</td></tr>`;
    }

    // ===== Business profile =====
    async function loadProfileIntoForm() {
        const res = await fetchJSON('/api/business');
        if (!res.success || !res.data || !res.data.business) return;
        const b = res.data.business;
        document.getElementById('bcLegalName').value = b.legal_name || '';
        document.getElementById('bcTradeName').value = b.trade_name || '';
        document.getElementById('bcDescription').value = b.description || '';
        document.getElementById('bcWebsite').value = b.website_url || '';
        document.getElementById('bcEmail').value = b.business_email || '';
        document.getElementById('bcPhone').value = b.business_phone || '';
        document.getElementById('bcBusinessType').value = b.business_type || '';
        document.getElementById('bcCountry').value = b.country_code || '';
        document.getElementById('bcCity').value = b.city || '';
        document.getElementById('bcCurrency').value = b.default_currency || '';
    }

    window.bcShow = function (pane) {
        if (pane === 'profileForm') loadProfileIntoForm();
        switchTab(pane);
    };

    window.bcSaveProfile = async function () {
        const payload = {
            legal_name: document.getElementById('bcLegalName').value.trim(),
            trade_name: document.getElementById('bcTradeName').value.trim(),
            description: document.getElementById('bcDescription').value.trim(),
            website_url: document.getElementById('bcWebsite').value.trim(),
            business_email: document.getElementById('bcEmail').value.trim(),
            business_phone: document.getElementById('bcPhone').value.trim(),
            business_type: document.getElementById('bcBusinessType').value,
            country_code: document.getElementById('bcCountry').value.trim(),
            city: document.getElementById('bcCity').value.trim(),
            default_currency: document.getElementById('bcCurrency').value.trim(),
        };
        if (!payload.legal_name) { toast(I18N['business_center.profile.legal_name_required'] || 'اسم النشاط القانوني مطلوب', 'error'); return; }

        const url = bcBusinessId ? '/api/business/' + bcBusinessId : '/api/business';
        const res = await fetchJSON(url, {
            method: bcBusinessId ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (res.success) { toast(I18N['common.saved'] || 'تم الحفظ', 'success'); switchTab('overview'); loadOverview(); }
        else { toast(res.error || I18N['common.save_failed'] || 'تعذر الحفظ', 'error'); }
    };

    // ===== Team =====
    window.loadTeam = async function () {
        if (!bcBusinessId) { bcNeedBusiness('bcTeamBody', 4); return; }
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/team');
        const tb = document.getElementById('bcTeamBody');
        if (!res.success) { tb.innerHTML = `<tr><td colspan="4" class="p-cell-muted">${esc(res.error || '')}</td></tr>`; return; }
        const team = res.data.team || [];
        tb.innerHTML = team.length ? team.map(m => `
            <tr>
                <td><strong>${esc(m.name)}</strong><br><small class="p-cell-muted">${esc(m.email)}</small></td>
                <td>${esc(m.role)}</td>
                <td>${m.status === 'active' ? '<span class="pill green">● نشط</span>' : '<span class="pill red">● دعوة</span>'}</td>
                <td>${m.status === 'pending' ? `<button class="p-btn outline xs" onclick="bcResendInvite(${m.member_id})">إعادة إرسال</button>` : ''}</td>
            </tr>`).join('') : `<tr><td colspan="4" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`;
    };

    window.bcInvite = async function () {
        if (!bcBusinessId) return;
        const email = document.getElementById('bcInviteEmail').value.trim();
        const role = document.getElementById('bcInviteRole').value;
        if (!email) { toast(I18N['business_center.team.invite_email_required'] || 'البريد الإلكتروني مطلوب', 'error'); return; }
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/team/invite', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, role })
        });
        if (res.success) { toast(res.message || 'تمت الدعوة', 'success'); document.getElementById('bcInviteEmail').value = ''; loadTeam(); }
        else { toast(res.error || 'تعذرت الدعوة', 'error'); }
    };

    window.bcResendInvite = async function (memberId) {
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/team/invite/resend', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ member_id: memberId })
        });
        toast(res.success ? (res.message || 'أُعيد إرسال الدعوة') : (res.error || 'تعذر إعادة الإرسال'), res.success ? 'success' : 'error');
    };

    // ===== API Keys =====
    window.loadKeys = async function () {
        if (!bcBusinessId) { bcNeedBusiness('bcKeysBody', 6); return; }
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/api-keys');
        const tb = document.getElementById('bcKeysBody');
        document.getElementById('bcKeyCreated').style.display = 'none';
        if (!res.success) { tb.innerHTML = `<tr><td colspan="6" class="p-cell-muted">${esc(res.error || '')}</td></tr>`; return; }
        const keys = res.data.keys || [];
        tb.innerHTML = keys.length ? keys.map(k => `
            <tr>
                <td><strong>${esc(k.name)}</strong></td>
                <td>${esc(k.scope)}</td>
                <td><code>${esc(k.key_prefix)}••••</code></td>
                <td>${k.last_used_at ? formatDate(k.last_used_at) : '-'}</td>
                <td>${k.revoked ? '<span class="pill red">موقوف</span>' : '<span class="pill green">نشط</span>'}</td>
                <td>${!k.revoked ? `<button class="p-btn outline xs" onclick="bcRevokeKey(${k.id})">إيقاف</button>` : ''}</td>
            </tr>`).join('') : `<tr><td colspan="6" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`;
    };

    window.bcCreateKey = async function () {
        if (!bcBusinessId) return;
        const name = document.getElementById('bcKeyName').value.trim();
        const scope = document.getElementById('bcKeyScope').value;
        if (!name) { toast(I18N['business_center.keys.name_required'] || 'اسم المفتاح مطلوب', 'error'); return; }
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/api-keys', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, scope })
        });
        if (res.success) {
            toast(res.message || 'تم إنشاء المفتاح', 'success');
            document.getElementById('bcKeyName').value = '';
            if (res.data && res.data.raw_key) {
                const box = document.getElementById('bcKeyCreated');
                box.innerHTML = '<strong>المفتاح (يظهر مرة واحدة):</strong> <code>' + esc(res.data.raw_key) + '</code>';
                box.style.display = 'block';
            }
            loadKeys();
        } else { toast(res.error || 'تعذر إنشاء المفتاح', 'error'); }
    };

    window.bcRevokeKey = async function (keyId) {
        if (!confirm('إيقاف هذا المفتاح؟')) return;
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/api-keys/' + keyId, { method: 'DELETE' });
        toast(res.success ? (res.message || 'أُوقف المفتاح') : (res.error || 'تعذر الإيقاف'), res.success ? 'success' : 'error');
        if (res.success) loadKeys();
    };

    // ===== Audit =====
    window.loadAudit = async function (page) {
        if (!bcBusinessId) { bcNeedBusiness('bcAuditBody', 5); return; }
        const search = document.getElementById('bcAuditSearch').value.trim();
        const qs = new URLSearchParams({ page: String(page), per_page: '20' });
        if (search) qs.set('search', search);
        const res = await fetchJSON('/api/business/' + bcBusinessId + '/audit-log?' + qs.toString());
        const tb = document.getElementById('bcAuditBody');
        if (!res.success) { tb.innerHTML = `<tr><td colspan="5" class="p-cell-muted">${esc(res.error || '')}</td></tr>`; return; }
        const rows = res.data.rows || [];
        bcAuditPage = res.data.page || page;
        tb.innerHTML = rows.length ? rows.map(r => `
            <tr>
                <td>${esc(r.action)}</td>
                <td>${esc(r.actor_name || r.actor_user_id || '-')}</td>
                <td>${esc(r.object_type || '-')}${r.object_id ? ' #' + esc(r.object_id) : ''}</td>
                <td>${r.result === 'success' ? '<span class="pill green">ناجح</span>' : '<span class="pill red">فشل</span>'}</td>
                <td>${formatDate(r.created_at)}</td>
            </tr>`).join('') : `<tr><td colspan="5" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`;
        document.getElementById('bcAuditInfo').textContent = res.data.total ? (res.data.total + ' سجل') : '';
        document.getElementById('bcAuditPrev').disabled = bcAuditPage <= 1;
        document.getElementById('bcAuditNext').disabled = bcAuditPage * 20 >= (res.data.total || 0);
    };

    loadOverview();
})();
JS;
    }
}
