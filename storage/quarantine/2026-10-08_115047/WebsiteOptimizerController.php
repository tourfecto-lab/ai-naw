<?php

/**
 * Tourfecto - AI Website Optimizer Controller
 * تدقيق تقني حقيقي (مش نتائج وهمية) على مواقع المستخدم الموجودة فعليًا
 * (جدول websites) - بيعمل طلبات HTTP فعلية للموقع (الصفحة الرئيسية +
 * robots.txt + llms.txt + sitemap) ويفحص SEO الكلاسيكي + AEO (تجهيز
 * المحتوى لمحركات الإجابة زي Google/Alexa/Siri) + GEO (تجهيز الموقع
 * عشان روبوتات الذكاء الاصطناعي التوليدي زي ChatGPT/Perplexity/Claude
 * تقدر تقرأه وتستشهد بيه) + السرعة + الأمان + الموبايل + إتاحة الوصول،
 * وبيحفظ النتائج كـ findings حقيقية، وبيولّد إصلاحات جاهزة (code
 * snippets حقيقية جاهزة للنسخ واللصق) لكل مشكلة - مش مجرد تشخيص.
 * @version 2.0.0 - BATCH6 Pro (SEO + AEO + GEO engine + auto-fixes)
 */
class WebsiteOptimizerController extends Controller
{
    /** ترتيب عرض فئات الفحص في الواجهة */
    private const CATEGORY_ORDER = ['seo', 'aeo', 'geo', 'speed', 'security', 'mobile', 'accessibility', 'availability', 'broken_links'];

    /** روبوتات الذكاء الاصطناعي الرئيسية اللي بتتفحص إمكانية وصولها في robots.txt (GEO) */
    private const AI_CRAWLER_BOTS = ['GPTBot', 'ChatGPT-User', 'Google-Extended', 'PerplexityBot', 'ClaudeBot', 'anthropic-ai', 'CCBot', 'Applebot-Extended'];

    /** GET /website-optimizer */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <style>
        .wo-hero { display: grid; grid-template-columns: 220px 1fr; gap: 22px; align-items: center; }
        .wo-ring-wrap { display: flex; align-items: center; justify-content: center; position: relative; }
        .wo-ring-score { position: absolute; text-align: center; }
        .wo-ring-score .n { font-family: var(--font-display); font-size: 34px; font-weight: 700; line-height: 1; }
        .wo-ring-score .l { font-size: 11px; color: var(--panel-text-muted); font-weight: 600; margin-top: 4px; }
        .wo-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 10px; }
        .wo-cat-card { background: var(--panel-card-bg-2); border: 1px solid var(--panel-border); border-radius: var(--panel-radius-sm); padding: 12px 14px; }
        .wo-cat-card .wo-cat-name { font-size: 12px; color: var(--panel-text-muted); font-weight: 700; margin-bottom: 6px; }
        .wo-cat-card .wo-cat-score { font-family: var(--font-display); font-size: 20px; font-weight: 700; }
        .wo-cat-bar { height: 5px; border-radius: 4px; background: rgba(255,255,255,.08); margin-top: 8px; overflow: hidden; }
        .wo-cat-bar > span { display: block; height: 100%; border-radius: 4px; }
        .wo-tabs { display: flex; gap: 6px; flex-wrap: wrap; padding: 14px 20px 0; }
        .wo-tab { border: 1px solid var(--panel-border); background: transparent; color: var(--panel-text-muted); font-size: 12.5px; font-weight: 700; padding: 6px 13px; border-radius: 999px; cursor: pointer; transition: .12s; }
        .wo-tab:hover { border-color: var(--panel-accent); color: var(--panel-text); }
        .wo-tab.active { background: var(--panel-accent); border-color: var(--panel-accent); color: #1a1206; }
        .wo-fixes-grid { display: grid; gap: 12px; }
        .wo-fix-card { background: var(--panel-card-bg-2); border: 1px solid var(--panel-border); border-radius: var(--panel-radius-sm); padding: 14px 16px; }
        .wo-fix-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .wo-fix-title { font-weight: 800; font-size: 13.5px; }
        .wo-fix-desc { font-size: 12.5px; color: var(--panel-text-muted); margin-top: 4px; line-height: 1.6; }
        .wo-fix-code { position: relative; margin-top: 10px; }
        .wo-fix-code pre { background: #060A13; border: 1px solid var(--panel-border); border-radius: 8px; padding: 12px 14px; font-family: 'JetBrains Mono', monospace; font-size: 11.5px; line-height: 1.7; overflow-x: auto; direction: ltr; text-align: left; color: #C9D4E4; margin: 0; max-height: 260px; }
        .wo-fix-copy { position: absolute; top: 8px; inset-inline-end: 8px; }
        .wo-fix-target { font-size: 11px; color: var(--panel-text-muted); font-family: 'JetBrains Mono', monospace; margin-top: 8px; }
        .wo-fix-actions { display: flex; gap: 8px; margin-top: 10px; }
        .wo-history-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--panel-border); font-size: 12.5px; }
        .wo-history-row:last-child { border-bottom: none; }
        .wo-history-score { font-weight: 800; font-family: 'JetBrains Mono', monospace; }
        @media (max-width: 720px) { .wo-hero { grid-template-columns: 1fr; } }
        </style>

        <div class="p-toolbar">
            <select id="woWebsiteSelect" class="p-select"><option value="">{$this->tr('wo.select_website')}</option></select>
            <button class="p-btn primary" id="woRunBtn" onclick="woRunAudit()">{$this->tr('wo.run_audit')}</button>
            <span class="p-cell-muted" id="woLastRun" style="font-size:12px;"></span>
        </div>

        <div class="p-card" id="woHeroCard" style="margin-top:14px;display:none;">
            <div class="wo-hero">
                <div class="wo-ring-wrap">
                    <svg width="180" height="180" viewBox="0 0 180 180">
                        <circle cx="90" cy="90" r="78" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="14"/>
                        <circle id="woRingProgress" cx="90" cy="90" r="78" fill="none" stroke="var(--panel-success)" stroke-width="14" stroke-linecap="round" stroke-dasharray="490" stroke-dashoffset="490" transform="rotate(-90 90 90)"/>
                    </svg>
                    <div class="wo-ring-score"><div class="n" id="woScore">-</div><div class="l">{$this->tr('wo.kpi.score')}</div></div>
                </div>
                <div class="wo-cats" id="woCatScores"></div>
            </div>
        </div>

        <div class="p-grid cols-4" id="woScoreCards" style="margin-top:14px;display:none;">
            <div class="p-card stat-tile"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value" id="woPasses">0</div><div class="stat-label">{$this->tr('wo.kpi.passes')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon red">❌</div><div class="stat-info"><div class="stat-value" id="woFails">0</div><div class="stat-label">{$this->tr('wo.kpi.fails')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">⚠️</div><div class="stat-info"><div class="stat-value" id="woWarns">0</div><div class="stat-label">{$this->tr('wo.kpi.warns')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon blue">🔗</div><div class="stat-info"><div class="stat-value" id="woBrokenLinks">0</div><div class="stat-label">{$this->tr('wo.kpi.broken_links')}</div></div></div>
        </div>

        <div class="p-card no-pad" style="margin-top:18px;" id="woFixesCard" style="display:none;">
            <div class="p-card-head" style="padding:18px 20px 6px;">
                <h3>🛠️ {$this->tr('wo.fixes.title')}</h3>
                <span class="p-card-sub">{$this->tr('wo.fixes.subtitle')}</span>
            </div>
            <div class="wo-fixes-grid" id="woFixesList" style="padding:6px 20px 20px;"></div>
            <script src="/assets/js/autofix.js?v=20261004" defer></script>
        </div>

        <div class="p-card no-pad" style="margin-top:18px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('wo.results.title')}</h3></div>
            <div class="wo-tabs" id="woTabs"></div>
            <div class="p-table-scroll"><table class="p-table" id="woFindingsTable">
                <thead><tr><th>{$this->tr('wo.col.category')}</th><th>{$this->tr('wo.col.check')}</th><th>{$this->tr('wo.col.status')}</th><th>{$this->tr('wo.col.severity')}</th><th>{$this->tr('wo.col.details')}</th></tr></thead>
                <tbody><tr><td colspan="5" class="p-cell-muted">{$this->tr('wo.empty_hint')}</td></tr></tbody>
            </table></div>
        </div>

        <div class="p-card no-pad" style="margin-top:18px;" id="woHistoryCard">
            <div class="p-card-head" style="padding:18px 20px;"><h3>📈 {$this->tr('wo.history.title')}</h3></div>
            <div style="padding:0 20px 18px;" id="woHistoryList"><div class="p-cell-muted">{$this->tr('wo.history.empty')}</div></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentWebsiteId = null;
    let lastFindings = [];
    let activeTab = 'all';

    const statusPill = { pass: `<span class="pill green">✔ ${I18N['wo.status.pass']}</span>`, warn: `<span class="pill orange">⚠ ${I18N['wo.status.warn']}</span>`, fail: `<span class="pill red">✕ ${I18N['wo.status.fail']}</span>`, info: `<span class="pill">ℹ ${I18N['wo.status.info']}</span>` };
    const severityLabel = { critical: I18N['wo.severity.critical'], high: I18N['wo.severity.high'], medium: I18N['wo.severity.medium'], low: I18N['wo.severity.low'], info: I18N['wo.severity.info'] };
    const categoryLabel = { seo: I18N['wo.cat.seo'], aeo: I18N['wo.cat.aeo'], geo: I18N['wo.cat.geo'], speed: I18N['wo.cat.speed'], security: I18N['wo.cat.security'], mobile: I18N['wo.cat.mobile'], accessibility: I18N['wo.cat.accessibility'], availability: I18N['wo.cat.availability'], broken_links: I18N['wo.cat.broken_links'] };
    const CATEGORY_ORDER = ['seo', 'aeo', 'geo', 'speed', 'security', 'mobile', 'accessibility', 'availability', 'broken_links'];

    function scoreColor(score) {
        if (score >= 80) return 'var(--panel-success)';
        if (score >= 50) return 'var(--panel-warning)';
        return 'var(--panel-danger)';
    }

    async function loadWebsites() {
        const res = await fetchJSON('/api/website-optimizer/websites');
        const sel = document.getElementById('woWebsiteSelect');
        if (res.success && res.data.websites.length) {
            sel.innerHTML = `<option value="">${I18N['wo.select_website']}</option>` + res.data.websites.map(w => `<option value="${w.id}">${esc(w.main_url)}</option>`).join('');
        } else {
            sel.innerHTML = `<option value="">${I18N['wo.no_websites']}</option>`;
        }
        sel.onchange = () => { currentWebsiteId = sel.value; if (currentWebsiteId) loadHistory(currentWebsiteId); };

        // Deep-link (من quick-wins في الـOnboarding): ?website_id=X&category=Y
        // بيختار الموقع ويحمّل سجلّه وبيحدّد فئة الملاحظات اللي المستخدم جي ليها.
        const params = new URLSearchParams(window.location.search);
        const deepId = params.get('website_id');
        if (deepId && Array.from(sel.options).some(o => o.value === deepId)) {
            sel.value = deepId;
            currentWebsiteId = deepId;
            loadHistory(deepId);
            const cat = params.get('category');
            if (cat) {
                try {
                    const ar = await fetchJSON('/api/website-optimizer/fixes?website_id=' + encodeURIComponent(deepId));
                    const fixes = (ar.success && ar.data && ar.data.fixes) || [];
                    const hit = fixes.find(f => f.category === cat);
                    if (hit) {
                        const rows = Array.from(document.querySelectorAll('.wo-fix-card'));
                        const target = rows.find(r => r.dataset && r.dataset.category === cat);
                        if (target) {
                            rows.forEach(r => r.style.boxShadow = '');
                            target.style.boxShadow = '0 0 0 2px var(--panel-accent)';
                            setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 500);
                        }
                    }
                } catch (e) { /* الـdeep-link اختياري */ }
            }
        }
    }

    window.woRunAudit = async function () {
        const websiteId = document.getElementById('woWebsiteSelect').value;
        if (!websiteId) { toast(I18N['wo.select_first'], 'error'); return; }
        currentWebsiteId = websiteId;

        const btn = document.getElementById('woRunBtn');
        btn.disabled = true;
        toast(I18N['wo.running'], 'success');
        const res = await fetchJSON('/api/website-optimizer/audit', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId })
        });
        btn.disabled = false;
        if (!res.success) { toast(res.error || I18N['wo.audit_failed'], 'error'); return; }
        renderResults(res.data);
        loadHistory(websiteId);
        toast(I18N['wo.done'], 'success');
    };

    function renderResults(data) {
        lastFindings = data.findings || [];
        activeTab = 'all';

        document.getElementById('woHeroCard').style.display = '';
        document.getElementById('woScoreCards').style.display = '';
        document.getElementById('woFixesCard').style.display = (data.fixes && data.fixes.length) ? '' : 'none';
        document.getElementById('woLastRun').textContent = data.audit.completed_at ? `${I18N['wo.last_run']}: ${data.audit.completed_at}` : '';

        const score = data.audit.overall_score ?? 0;
        document.getElementById('woScore').textContent = score;
        const circumference = 490;
        const offset = circumference - (Math.max(0, Math.min(100, score)) / 100) * circumference;
        const ring = document.getElementById('woRingProgress');
        ring.style.stroke = scoreColor(score);
        ring.style.transition = 'stroke-dashoffset .8s ease';
        setTimeout(() => { ring.style.strokeDashoffset = offset; }, 60);

        const catScores = data.category_scores || {};
        document.getElementById('woCatScores').innerHTML = CATEGORY_ORDER
            .filter(c => catScores[c] !== undefined && catScores[c] !== null)
            .map(c => `
                <div class="wo-cat-card">
                    <div class="wo-cat-name">${categoryLabel[c] || esc(c)}</div>
                    <div class="wo-cat-score" style="color:${scoreColor(catScores[c])}">${catScores[c]}</div>
                    <div class="wo-cat-bar"><span style="width:${Math.max(0,Math.min(100,catScores[c]))}%;background:${scoreColor(catScores[c])};"></span></div>
                </div>`).join('');

        document.getElementById('woPasses').textContent = lastFindings.filter(f => f.status === 'pass').length;
        document.getElementById('woFails').textContent = lastFindings.filter(f => f.status === 'fail').length;
        document.getElementById('woWarns').textContent = lastFindings.filter(f => f.status === 'warn').length;
        document.getElementById('woBrokenLinks').textContent = (data.broken_links || []).length;

        renderTabs();
        renderFindingsTable();
        renderFixes(data.fixes || []);
    }

    function renderTabs() {
        const present = CATEGORY_ORDER.filter(c => lastFindings.some(f => f.category === c));
        const tabs = ['all', ...present];
        document.getElementById('woTabs').innerHTML = tabs.map(t => `
            <button class="wo-tab ${t === activeTab ? 'active' : ''}" data-tab="${t}">${t === 'all' ? I18N['wo.tab.all'] : (categoryLabel[t] || esc(t))}</button>
        `).join('');
        document.querySelectorAll('.wo-tab').forEach(btn => {
            btn.onclick = () => { activeTab = btn.dataset.tab; renderTabs(); renderFindingsTable(); };
        });
    }

    function renderFindingsTable() {
        const tbody = document.querySelector('#woFindingsTable tbody');
        const rows = activeTab === 'all' ? lastFindings : lastFindings.filter(f => f.category === activeTab);
        tbody.innerHTML = rows.length ? rows.map(f => `
            <tr>
                <td><span class="wo-cat-badge">${categoryLabel[f.category] || esc(f.category)}</span></td>
                <td>${esc(f.title)}</td>
                <td>${statusPill[f.status] || esc(f.status)}</td>
                <td>${severityLabel[f.severity] || esc(f.severity)}</td>
                <td class="p-cell-muted">${esc(f.message)}</td>
            </tr>`).join('') : `<tr><td colspan="5" class="p-cell-muted">${I18N['wo.no_results']}</td></tr>`;
    }

    function renderFixes(fixes) {
        const list = document.getElementById('woFixesList');
        if (!fixes.length) { list.innerHTML = ''; return; }
        list.innerHTML = fixes.map(fx => `
            <div class="wo-fix-card" data-fix-id="${fx.id}" data-category="${esc(fx.category)}" data-check-key="${esc(fx.check_key || '')}" data-audit-id="${fx.audit_id || ''}" data-status="${esc(fx.status)}" data-applied-by="${esc(fx.applied_by || '')}">
                <div class="wo-fix-head">
                    <div>
                        <span class="wo-cat-badge">${categoryLabel[fx.category] || esc(fx.category)}</span>
                        <div class="wo-fix-title">${esc(fx.title)}</div>
                    </div>
                    <span class="pill ${fx.status === 'applied' ? 'green' : (fx.status === 'dismissed' ? 'gray' : 'orange')}" data-fix-status>${I18N['wo.fix.status.' + fx.status] || esc(fx.status)}</span>
                </div>
                <div class="wo-fix-desc">${esc(fx.description)}</div>
                ${fx.code_snippet ? `
                <div class="wo-fix-code">
                    <button class="p-btn xs outline wo-fix-copy" onclick="woCopyFix(this)">${I18N['wo.fix.copy']}</button>
                    <pre><code>${esc(fx.code_snippet)}</code></pre>
                </div>` : ''}
                ${fx.target_file ? `<div class="wo-fix-target">📄 ${esc(fx.target_file)}</div>` : ''}
                <div class="wo-fix-actions">
                    <button class="p-btn xs success" onclick="woSetFixStatus(${fx.id}, 'applied', this)" ${fx.status === 'applied' ? 'disabled' : ''}>${I18N['wo.fix.mark_applied']}</button>
                    <button class="p-btn xs outline" onclick="woSetFixStatus(${fx.id}, 'dismissed', this)" ${fx.status === 'dismissed' ? 'disabled' : ''}>${I18N['wo.fix.dismiss']}</button>
                </div>
            </div>`).join('');
    }

    window.woCopyFix = function (btn) {
        const code = btn.closest('.wo-fix-code').querySelector('code').textContent;
        navigator.clipboard.writeText(code).then(() => toast(I18N['wo.fix.copied'], 'success')).catch(() => toast(I18N['wo.fix.copy_failed'], 'error'));
    };

    window.woSetFixStatus = async function (fixId, status, btn) {
        const res = await fetchJSON(`/api/website-optimizer/fixes/${fixId}/status`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status })
        });
        if (!res.success) { toast(res.error || I18N['wo.fix.update_failed'], 'error'); return; }
        const card = btn.closest('.wo-fix-card');
        card.querySelector('[data-fix-status]').outerHTML = `<span class="pill ${status === 'applied' ? 'green' : 'gray'}" data-fix-status>${I18N['wo.fix.status.' + status]}</span>`;
        card.querySelectorAll('.wo-fix-actions button').forEach(b => b.disabled = false);
        btn.disabled = true;
        toast(I18N['wo.fix.updated'], 'success');
    };

    async function loadHistory(websiteId) {
        const res = await fetchJSON(`/api/website-optimizer/history?website_id=${websiteId}`);
        const box = document.getElementById('woHistoryList');
        if (!res.success || !res.data.history.length) { box.innerHTML = `<div class="p-cell-muted">${I18N['wo.history.empty']}</div>`; return; }
        box.innerHTML = res.data.history.map(h => `
            <div class="wo-history-row">
                <span>${esc(h.completed_at || h.started_at)}</span>
                <span class="wo-history-score" style="color:${scoreColor(h.overall_score || 0)}">${h.overall_score ?? '-'} /100</span>
            </div>`).join('');
    }

    loadWebsites();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('website_optimizer', $this->tr('wo.page.title'), $this->tr('wo.page.subtitle'), $body, $script);
        exit;
    }

    /** GET /api/website-optimizer/websites */
    public function listWebsites(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $urlCol = Website::urlColumn();
            $websites = $this->db->query(
                "SELECT id, {$urlCol} AS main_url FROM websites WHERE user_id = ? ORDER BY created_at DESC",
                [$this->user['id']]
            );
            return $this->success(['websites' => $websites]);
        } catch (Exception $e) {
            Logger::error('Website Optimizer List Websites Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المواقع', 500);
        }
    }

    /** GET /api/website-optimizer/history?website_id= */
    public function history(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        try {
            $owns = $this->db->query("SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, $this->user['id']]);
            if (empty($owns)) {
                return $this->error('الموقع غير موجود', 404);
            }

            $rows = $this->db->query(
                "SELECT id, overall_score, status, started_at, completed_at
                 FROM wo_audits WHERE website_id = ? AND user_id = ? ORDER BY id DESC LIMIT 10",
                [$websiteId, $this->user['id']]
            );
            return $this->success(['history' => $rows]);
        } catch (Exception $e) {
            Logger::error('Website Optimizer History Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب السجل', 500);
        }
    }

    /** POST /api/website-optimizer/audit */
    public function runAudit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $auditId = null;
        try {
            // ميزانية زمنية للتدقيق كله (ثواني) + منع قتل العملية بحد التنفيذ الافتراضي على الاستضافة
            $this->auditDeadline = microtime(true) + 45;
            $this->auditSkipped = 0;
            @set_time_limit(180);

            $urlCol = Website::urlColumn();
            $rows = $this->db->query("SELECT id, {$urlCol} AS main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, $this->user['id']]);
            if (empty($rows)) {
                return $this->error('الموقع غير موجود', 404);
            }

            $url = $rows[0]['main_url'];

            // Phase 5 (Auto-Apply): لو الرابط ده بيتبع موقع اتعمل بالـ Website
            // Builder بتاعنا، عندنا صلاحية كتابة فعلية عليه (عكس أي موقع خارجي
            // تاني) - نكتشف ده هنا عشان نقدر نعرض Auto-Apply حقيقي على الإصلاحات
            // المناسبة، مش بس كود جاهز للنسخ زي باقي المواقع.
            $linkedGeneratedWebsite = $this->detectLinkedGeneratedWebsite($url, (int) $this->user['id']);

            // تصحيح: exec() بيرجّع bool مش الـ id الحقيقي (زي ما بتعمل query() مع
            // INSERT)، فكان $auditId دايمًا true (=1) وكل الأودتات كانت بتتكتب
            // فوق بعض في صف id=1. استخدام query() هنا بيرجّع lastInsertId فعلي.
            $auditId = $this->db->query(
                "INSERT INTO wo_audits (website_id, generated_website_id, user_id, status, started_at) VALUES (?, ?, ?, 'running', NOW())",
                [$websiteId, $linkedGeneratedWebsite['id'] ?? null, $this->user['id']]
            );

            $result = $this->performAudit($url);
            $findings = $result['findings'];
            $brokenLinks = $result['broken_links'];
            $context = $result['context'];
            if ($linkedGeneratedWebsite) {
                $context['generated_website_content'] = $linkedGeneratedWebsite['content'];
                $context['generated_website_id'] = $linkedGeneratedWebsite['id'];
            }

            foreach ($findings as $f) {
                $this->db->exec(
                    "INSERT INTO wo_audit_findings (audit_id, category, check_key, title, status, severity, message)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$auditId, $f['category'], $f['check_key'], $f['title'], $f['status'], $f['severity'], $f['message']]
                );
            }
            foreach ($brokenLinks as $bl) {
                $this->db->exec(
                    "INSERT INTO wo_broken_links (audit_id, source_url, target_url, link_type, status_code, error)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$auditId, $url, $bl['target_url'], $bl['link_type'], $bl['status_code'], $bl['error']]
                );
            }

            $score = $this->calculateScore($findings);
            $categoryScores = $this->calculateCategoryScores($findings);

            $fixes = $this->generateFixes($findings, $context);
            $savedFixes = [];
            foreach ($fixes as $fx) {
                $fixId = $this->db->query(
                    "INSERT INTO wo_fixes (audit_id, category, title, description, fix_type, code_snippet, target_file, suggested_value, check_key, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [$auditId, $fx['category'], $fx['title'], $fx['description'], $fx['fix_type'], $fx['code_snippet'], $fx['target_file'], $fx['suggested_value'] ?? null, $fx['check_key'] ?? null]
                );
                $fx['id'] = $fixId;
                $fx['audit_id'] = $auditId; // تصحيح: من غيره زرار "نفّذ الآمنة" كان بيقف بصمت بعد التدقيق الجديد
                $fx['status'] = 'pending';
                $fx['can_auto_apply'] = !empty($fx['suggested_value']);
                $savedFixes[] = $fx;
            }

            // Phase 13 (Auto Pilot): لو الموقع ده Website Builder بتاعنا وفي
            // وضع balanced/aggressive، نطبّق تلقائيًا أي إصلاح مدعوم للتطبيق
            // الآلي (حاليًا: title_tag/meta_description بس - نفس النطاق
            // المدعوم يدويًا من Phase 5، ملهوش معنى نطبّق تلقائيًا حاجة
            // النظام أصلًا مش قادر يطبّقها بأمان). في وضع conservative
            // (الافتراضي) محدث حاجة تلقائي خالص - نفس سلوك النظام قبل الـPhase دي.
            if ($linkedGeneratedWebsite) {
                try {
                    $modeRow = $this->db->query("SELECT auto_pilot_mode FROM generated_websites WHERE id = ?", [$linkedGeneratedWebsite['id']]);
                    $mode = $modeRow[0]['auto_pilot_mode'] ?? 'conservative';
                } catch (Exception $e) {
                    $mode = 'conservative'; // الـMigration لسه ما اتطبقتش - نفضل بأمان على السلوك القديم
                }

                // مركز الأتمتة: لو العميل مش مختار "يدوي"، كل إصلاح قابل للتطبيق
                // بيعدّي على البوابة (يتطبق أو يستنى موافقته).
                $engine = class_exists('AutomationEngine') ? AutomationEngine::instance() : null;
                if ($engine && $engine->getPolicy((int) $this->user['id'], 'website_fixes')['mode'] !== 'manual') {
                    $mode = $mode === 'aggressive' ? 'aggressive' : 'balanced';
                }
                if (in_array($mode, ['balanced', 'aggressive'], true)) {
                    foreach ($savedFixes as &$fx) {
                        if (empty($fx['suggested_value'])) {
                            continue;
                        }
                        $gate = null;
                        if ($engine) {
                            $gate = $engine->gate(
                                (int) $this->user['id'],
                                (int) $websiteId,
                                'website_fixes',
                                AutomationCatalog::websiteFixBucket(['field_name' => $fx['check_key'] ?? '', 'category' => $fx['category'] ?? '']),
                                'wo_fix',
                                (string) $fx['id'],
                                [
                                    'title' => (string) $fx['title'],
                                    'context' => (string) ($fx['description'] ?? ''),
                                    'draft' => (string) $fx['suggested_value'],
                                    'payload' => ['kind' => 'builder', 'fix_id' => (int) $fx['id'], 'generated_website_id' => (int) $linkedGeneratedWebsite['id']],
                                ]
                            );
                            if ($gate['action'] !== 'execute') {
                                continue;
                            }
                        }
                        $fixForApply = ['id' => $fx['id'], 'check_key' => $fx['check_key'] ?? null, 'status' => 'pending', 'suggested_value' => $fx['suggested_value']];
                        $applyResult = $this->applyFixInternal($fixForApply, (int) $linkedGeneratedWebsite['id'], 'audit_auto_pilot');
                        if ($gate) {
                            $engine->complete($gate['decision_id'] ?? null, !empty($applyResult['success']), $applyResult['error'] ?? null);
                        }
                        if ($applyResult['success']) {
                            $fx['status'] = 'applied';
                            $fx['applied_by'] = 'auto_pilot';
                        }
                    }
                    unset($fx);
                }
            }

            $this->db->exec(
                "UPDATE wo_audits SET status = 'completed', overall_score = ?, completed_at = NOW() WHERE id = ?",
                [$score, $auditId]
            );
            $completedRow = $this->db->query("SELECT completed_at FROM wo_audits WHERE id = ? LIMIT 1", [$auditId]);

            return $this->success([
                'audit' => [
                    'id' => $auditId,
                    'overall_score' => $score,
                    'completed_at' => $completedRow[0]['completed_at'] ?? null,
                ],
                'category_scores' => $categoryScores,
                'findings' => $findings,
                'broken_links' => $brokenLinks,
                'fixes' => $savedFixes,
                // Phase 5: بيوضح للواجهة إن الموقع ده Website Builder بتاعنا،
                // فممكن تعرض زرار "طبّق تلقائيًا" الحقيقي بدل بس "انسخ الكود"
                'is_own_website' => (bool) $linkedGeneratedWebsite,
            ]);
        } catch (Throwable $e) {
            Logger::error('Website Optimizer Audit Error', ['message' => $e->getMessage()]);
            // أي فشل بعد إنشاء صف التدقيق: نعلّمه failed بدل ما يفضل "running" للأبد (الواجهة كانت بتستنى عليه)
            if ($auditId) {
                try {
                    $this->db->query("UPDATE wo_audits SET status = 'failed', completed_at = NOW() WHERE id = ? AND status = 'running'", [(int) $auditId]);
                } catch (Throwable $ignored) {
                }
            }
            return $this->error($this->tr('wo.error.audit_run_failed'), 500);
        } finally {
            $this->auditDeadline = null;
        }
    }

    /** GET /api/website-optimizer/fixes?audit_id= */
    public function listFixes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $auditId = (int) $this->get('audit_id');
        // Deep-link من الـOnboarding: لو جاله website_id بدون audit_id بيجيب
        // آخر تدقيق مكتمل للموقع تلقائيًا (المستخدم بيوصل من quick-wins).
        if (!$auditId && $this->get('website_id')) {
            try {
                $latest = $this->db->query(
                    "SELECT id FROM wo_audits
                     WHERE website_id = ? AND user_id = ? AND status = 'completed'
                     ORDER BY id DESC LIMIT 1",
                    [(int) $this->get('website_id'), (int) $this->user['id']]
                );
                if (!empty($latest)) {
                    $auditId = (int) $latest[0]['id'];
                }
            } catch (Exception $e) {
                $auditId = 0;
            }
        }
        if (!$auditId) {
            return $this->error('audit_id مطلوب', 422);
        }

        try {
            $owns = $this->db->query(
                "SELECT a.id FROM wo_audits a WHERE a.id = ? AND a.user_id = ? LIMIT 1",
                [$auditId, $this->user['id']]
            );
            if (empty($owns)) {
                return $this->error('التدقيق غير موجود', 404);
            }

            $fixes = $this->db->query(
                "SELECT id, audit_id, category, title, description, fix_type, code_snippet, target_file, status, check_key, applied_by
                 FROM wo_fixes WHERE audit_id = ? ORDER BY id ASC",
                [$auditId]
            );
            // Never leak Arabic hard-coded legacy fix text into an English UI.
            $fixes = array_map(fn(array $fix): array => $this->localizeOptimizerFix($fix), $fixes);
            return $this->success(['fixes' => $fixes]);
        } catch (Exception $e) {
            Logger::error('Website Optimizer List Fixes Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الإصلاحات', 500);
        }
    }

    /** POST /api/website-optimizer/fixes/{id}/status */
    public function updateFixStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $fixId = (int) ($params['id'] ?? 0);
        $status = (string) $this->get('status');
        if (!$fixId || !in_array($status, ['applied', 'dismissed', 'pending'], true)) {
            return $this->error('بيانات غير صالحة', 422);
        }

        try {
            $owns = $this->db->query(
                "SELECT f.id FROM wo_fixes f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE f.id = ? AND a.user_id = ? LIMIT 1",
                [$fixId, $this->user['id']]
            );
            if (empty($owns)) {
                return $this->error('الإصلاح غير موجود', 404);
            }

            $appliedAt = $status === 'applied' ? date('Y-m-d H:i:s') : null;
            $this->db->exec(
                "UPDATE wo_fixes SET status = ?, applied_at = ? WHERE id = ?",
                [$status, $appliedAt, $fixId]
            );

            // مركز الأتمتة: تطبيق أو تجاهل إصلاح بإيد العميل بيعلّم المنصة
            if (class_exists('AutomationEngine')) {
                $engine = AutomationEngine::instance();
                if ($status === 'dismissed') {
                    $engine->recordManualRejection((int) $this->user['id'], 'website_fixes', 'wo_fix', (string) $fixId);
                } elseif ($status === 'applied') {
                    $pendingFix = $engine->findBySubject('website_fixes', 'wo_fix', (string) $fixId);
                    if ($pendingFix && $pendingFix['status'] === 'pending') {
                        $engine->recordManualAction((int) $this->user['id'], 'website_fixes', $pendingFix['bucket'], 'wo_fix', (string) $fixId, (string) ($pendingFix['draft_text'] ?? ''), null, (string) ($pendingFix['draft_text'] ?? ''));
                    }
                }
            }

            return $this->success(['id' => $fixId, 'status' => $status]);
        } catch (Exception $e) {
            Logger::error('Website Optimizer Update Fix Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث الإصلاح', 500);
        }
    }

    /**
     * POST /api/website-optimizer/fixes/{id}/apply-auto
     * Phase 5 (Auto-Apply): تطبيق فعلي حقيقي - بيكتب على generated_websites
     * مباشرة (مش بس يعلّم الإصلاح كـ applied). متاح فقط لما:
     * 1) الإصلاح ده جزء من Audit اتربط بموقع Website Builder بتاع نفس العميل
     *    (عندنا صلاحية كتابة حقيقية عليه - عكس أي موقع خارجي).
     * 2) نوع الإصلاح من النوعين المدعومين حاليًا (title_tag / meta_description)
     *    واللي ليهم suggested_value محسوب فعليًا من محتوى الموقع نفسه.
     * أي إصلاح تاني (schema/OG/إلخ) لسه محتاج تطبيق يدوي زي ما هو دلوقتي -
     * مفيش أي وعد بتطبيق حاجة النظام مش قادر فعليًا يطبقها.
     */
    public function applyFixAutomatically(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $fixId = (int) ($params['id'] ?? 0);
        if (!$fixId) {
            return $this->error('بيانات غير صالحة', 422);
        }

        try {
            $rows = $this->db->query(
                "SELECT f.id, f.check_key, f.status, f.suggested_value, a.generated_website_id, a.user_id
                 FROM wo_fixes f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE f.id = ? AND a.user_id = ? LIMIT 1",
                [$fixId, $this->user['id']]
            );
            if (empty($rows)) {
                return $this->error('الإصلاح غير موجود', 404);
            }
            $fix = $rows[0];

            if (empty($fix['generated_website_id'])) {
                return $this->error('التطبيق التلقائي متاح بس لمواقع Website Builder بتاعتنا - الموقع ده خارجي، طبّق الكود يدويًا.', 422);
            }

            // تأكيد ملكية إضافي على مستوى generated_websites نفسها (Defense in depth)
            $owns = $this->db->query(
                "SELECT id FROM generated_websites WHERE id = ? AND user_id = ? LIMIT 1",
                [$fix['generated_website_id'], $this->user['id']]
            );
            if (empty($owns)) {
                return $this->error('الموقع غير موجود', 404);
            }

            $result = $this->applyFixInternal($fix, (int) $fix['generated_website_id'], 'manual_click');
            if (!$result['success']) {
                return $this->error($result['error'], 422);
            }

            if (class_exists('AutomationEngine')) {
                $engine = AutomationEngine::instance();
                $pendingFix = $engine->findBySubject('website_fixes', 'wo_fix', (string) $fixId);
                if ($pendingFix && $pendingFix['status'] === 'pending') {
                    $engine->recordManualAction((int) $this->user['id'], 'website_fixes', $pendingFix['bucket'], 'wo_fix', (string) $fixId, (string) ($pendingFix['draft_text'] ?? ''), null, (string) ($pendingFix['draft_text'] ?? ''));
                }
            }

            return $this->success([
                'id' => $fixId,
                'status' => 'applied',
                'applied_by' => 'auto_pilot',
                'applied_value' => $result['new_value'],
            ]);
        } catch (Exception $e) {
            Logger::error('Website Optimizer Auto-Apply Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التطبيق التلقائي', 500);
        }
    }

    /**
     * Phase 13 (Auto Pilot): المنطق الفعلي المشترك اللي بيطبّق إصلاح على
     * generated_websites + يسجّله في auto_pilot_change_log - مستخدم من
     * مكانين: applyFixAutomatically() (العميل ضغط بنفسه) و runAudit()
     * (تلقائيًا وقت الفحص لو الموقع في وضع balanced/aggressive). منطق واحد
     * بس، مفيش تكرار يقدر يختلف سلوكه بين الحالتين بالغلط.
     *
     * @param array $fix لازم يحتوي: id, check_key, status, suggested_value
     * @return array{success:bool, new_value?:string, old_value?:string, error?:string}
     */
    private function applyFixInternal(array $fix, int $generatedWebsiteId, string $trigger): array
    {
        if (empty($fix['suggested_value'])) {
            return ['success' => false, 'error' => 'مفيش قيمة جاهزة للتطبيق التلقائي على الإصلاح ده'];
        }
        if (($fix['status'] ?? '') === 'applied') {
            return ['success' => false, 'error' => 'الإصلاح ده متطبق بالفعل'];
        }

        $columnMap = ['title_tag' => 'seo_title', 'meta_description' => 'seo_description'];
        $column = $columnMap[$fix['check_key']] ?? null;
        if (!$column) {
            return ['success' => false, 'error' => 'نوع الإصلاح ده مش مدعوم للتطبيق التلقائي حاليًا'];
        }

        // نسجّل القيمة القديمة قبل ما نكتب فوقها - ده أساس الـRollback
        $before = $this->db->query("SELECT {$column} AS val FROM generated_websites WHERE id = ?", [$generatedWebsiteId]);
        $oldValue = $before[0]['val'] ?? null;

        $this->db->exec("UPDATE generated_websites SET {$column} = ? WHERE id = ?", [$fix['suggested_value'], $generatedWebsiteId]);
        $this->db->exec("UPDATE wo_fixes SET status = 'applied', applied_at = NOW(), applied_by = 'auto_pilot' WHERE id = ?", [$fix['id']]);

        try {
            $this->db->exec(
                "INSERT INTO auto_pilot_change_log (generated_website_id, fix_id, field_name, old_value, new_value, `trigger`) VALUES (?, ?, ?, ?, ?, ?)",
                [$generatedWebsiteId, $fix['id'], $column, $oldValue, $fix['suggested_value'], $trigger]
            );
        } catch (Exception $e) {
            // Best-effort: لو الـMigration لسه ما اتطبقتش، التطبيق نفسه لسه
            // بينجح - بس من غير Rollback متاح لحد ما الجدول يتعمل.
        }

        return ['success' => true, 'new_value' => $fix['suggested_value'], 'old_value' => $oldValue];
    }

    /** POST /api/website-optimizer/auto-pilot-mode  { generated_website_id, mode } */
    public function setAutoPilotMode(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $siteId = (int) $this->get('generated_website_id');
        $mode = (string) $this->get('mode', '');
        if (!$siteId || !in_array($mode, ['conservative', 'balanced', 'aggressive'], true)) {
            return $this->error('بيانات غير صالحة', 422);
        }

        $owns = $this->db->query("SELECT id FROM generated_websites WHERE id = ? AND user_id = ? LIMIT 1", [$siteId, $this->user['id']]);
        if (empty($owns)) {
            return $this->error('الموقع غير موجود', 404);
        }

        try {
            $this->db->exec("UPDATE generated_websites SET auto_pilot_mode = ? WHERE id = ?", [$mode, $siteId]);
        } catch (Exception $e) {
            Logger::error('Set Auto Pilot Mode Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث الوضع - تأكد إن Migration Phase 13 اتطبقت', 500);
        }

        return $this->success(['generated_website_id' => $siteId, 'mode' => $mode], 'تم تحديث وضع Auto Pilot');
    }

    /** GET /api/website-optimizer/auto-pilot-log?generated_website_id=X */
    public function getAutoPilotLog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $siteId = (int) $this->get('generated_website_id');
        if (!$siteId) {
            return $this->error('generated_website_id مطلوب', 422);
        }

        $owns = $this->db->query("SELECT id FROM generated_websites WHERE id = ? AND user_id = ? LIMIT 1", [$siteId, $this->user['id']]);
        if (empty($owns)) {
            return $this->error('الموقع غير موجود', 404);
        }

        try {
            $log = $this->db->query(
                "SELECT * FROM auto_pilot_change_log WHERE generated_website_id = ? ORDER BY id DESC LIMIT 50",
                [$siteId]
            );
        } catch (Exception $e) {
            return $this->success(['log' => []]); // الجدول لسه ما اتعملش - سجل فاضي بدل خطأ
        }

        return $this->success(['log' => $log]);
    }

    /** POST /api/website-optimizer/auto-pilot-log/{id}/rollback */
    public function rollbackChange(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $logId = (int) ($params['id'] ?? 0);
        if (!$logId) {
            return $this->error('بيانات غير صالحة', 422);
        }

        try {
            $rows = $this->db->query(
                "SELECT l.*, g.user_id FROM auto_pilot_change_log l
                 INNER JOIN generated_websites g ON g.id = l.generated_website_id
                 WHERE l.id = ? LIMIT 1",
                [$logId]
            );
            if (empty($rows)) {
                return $this->error('السجل غير موجود', 404);
            }
            $log = $rows[0];

            if ((int) $log['user_id'] !== (int) $this->user['id']) {
                return $this->error('السجل غير موجود', 404);
            }
            if ($log['rolled_back_at'] !== null) {
                return $this->error('اتعمله Rollback بالفعل', 422);
            }

            $field = $log['field_name'];
            // whitelist صريحة لأسماء الأعمدة المسموح نكتب فيها SQL ديناميكي -
            // مينفعش نثق في field_name من الجدول من غير تحقق، حتى لو إحنا
            // اللي كتبناه أصلًا (دفاع إضافي ضد أي تلاعب مستقبلي في البيانات).
            if (!in_array($field, ['seo_title', 'seo_description'], true)) {
                return $this->error('نوع الحقل ده مش مدعوم للـRollback', 422);
            }

            $this->db->exec("UPDATE generated_websites SET {$field} = ? WHERE id = ?", [$log['old_value'], $log['generated_website_id']]);
            $this->db->exec("UPDATE auto_pilot_change_log SET rolled_back_at = NOW() WHERE id = ?", [$logId]);
            if (!empty($log['fix_id'])) {
                $this->db->exec("UPDATE wo_fixes SET status = 'pending' WHERE id = ?", [$log['fix_id']]);
            }

            return $this->success(['restored_value' => $log['old_value']], 'تم التراجع عن التعديل');
        } catch (Exception $e) {
            Logger::error('Rollback Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التراجع عن التعديل', 500);
        }
    }

    // =========================================================================
    // محرك التدقيق - Audit Engine
    // =========================================================================

    /**
     * Phase 5 (Auto-Apply): بيكتشف لو الرابط ده بيتبع موقع اتعمل بالـ Website
     * Builder بتاعنا (يبقى عندنا صلاحية كتابة فعلية عليه، عكس أي موقع خارجي).
     * بيتحقق من نفس الـuser_id عشان محدش يقدر يطبّق تعديلات على موقع مش بتاعه.
     * @return array{id:int, content:array}|null
     */
    private function detectLinkedGeneratedWebsite(string $url, int $userId): ?array
    {
        if (!class_exists('GeneratedWebsite')) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $appHost = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?? '';

        $slug = null;
        if ($appHost !== '' && strcasecmp($host, $appHost) === 0 && preg_match('#^/sites/([^/]+)#', $path, $m)) {
            // رابط من شكل https://tourfecto.com/sites/{slug}
            $slug = $m[1];
        }

        try {
            if ($slug !== null) {
                $rows = $this->db->query(
                    "SELECT id, content_json FROM generated_websites WHERE slug = ? AND user_id = ? LIMIT 1",
                    [$slug, $userId]
                );
            } else {
                // أو دومين مخصص مربوط بموقع Website Builder بتاع نفس المستخدم
                $rows = $this->db->query(
                    "SELECT id, content_json FROM generated_websites WHERE custom_domain = ? AND user_id = ? LIMIT 1",
                    [$host, $userId]
                );
            }
            if (empty($rows)) {
                return null;
            }
            $decoded = json_decode((string) ($rows[0]['content_json'] ?? ''), true);
            return ['id' => (int) $rows[0]['id'], 'content' => is_array($decoded) ? $decoded : []];
        } catch (Exception $e) {
            // لو أي مشكلة في الاكتشاف (مثلاً الجدول مش موجود لسه)، منكسرش
            // الـAudit العادي - نرجع null ونكمل الفحص العادي بس من غير Auto-Apply
            return null;
        }
    }

    /**
     * تدقيق تقني حقيقي شامل: بيعمل طلبات HTTP فعلية (الصفحة الرئيسية +
     * robots.txt + llms.txt) ويفحص:
     * - SEO كلاسيكي (title/description/canonical/headings/schema/OG)
     * - AEO: تجهيز المحتوى عشان يترشح كـ Featured Snippet / إجابة صوتية
     *   (FAQ schema, HowTo schema, بنية أسئلة وأجوبة، Speakable)
     * - GEO: تجهيز الموقع عشان روبوتات الذكاء الاصطناعي التوليدي (GPTBot,
     *   ClaudeBot, PerplexityBot..) تقدر تزحف عليه وتفهمه وتستشهد بيه
     *   (llms.txt, صلاحية الزحف, بيانات الكيان/المؤلف, حداثة المحتوى,
     *   الاعتماد على JS في عرض المحتوى)
     * - السرعة، الأمان، الموبايل، وإتاحة الوصول
     * مش بديل لأداة زي Lighthouse الكاملة، لكنه فحص حقيقي مباشر من بيانات
     * الصفحة الفعلية - مش نتائج ثابتة أو عشوائية.
     * @return array{findings: array, broken_links: array, context: array}
     */
    /** POST /api/website-optimizer/crawl  { website_id, max_urls?, max_depth? } - زحف متعدد الصفحات (G1) */
    public function crawl(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        try {
            $crawler = new SeoCrawlerService();
            $result = $crawler->crawl($this->db, $websiteId, (int) $this->user['id'], [
                'max_urls' => (int) $this->get('max_urls', 25),
                'max_depth' => (int) $this->get('max_depth', 3),
            ]);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('SEO crawl failed', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
            }
            return $this->error('فشل الزحف: ' . $e->getMessage(), 500);
        }

        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'فشل الزحف', 422);
        }

        return $this->success([
            'crawl_id' => $result['crawl_id'],
            'website' => $result['website'],
            'summary' => $result['summary'],
            'pages' => $result['pages'],
        ]);
    }

    /** GET /api/website-optimizer/crawl?website_id=X - آخر زحف محفوظ (G1) */
    public function crawlResults(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $last = (new SeoCrawlerService())->lastCrawl($this->db, $websiteId, (int) $this->user['id']);
        if ($last === null) {
            return $this->success(['crawl' => null]);
        }
        return $this->success(['crawl' => $last]);
    }

    private function performAudit(string $url): array
    {
        $findings = [];
        $brokenLinks = [];

        $main = $this->httpGet($url, 15, false, true);

        if ($main['error'] || $main['body'] === null) {
            $findings[] = $this->finding('availability', 'reachable', 'وصول الموقع', 'fail', 'critical', 'تعذر الوصول للموقع: ' . ($main['error'] ?: "HTTP {$main['code']}"));
            return ['findings' => $findings, 'broken_links' => $brokenLinks, 'context' => ['url' => $url, 'html' => '', 'headers' => []]];
        }

        $html = $main['body'];
        $headers = $main['headers'];
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $urlPort = parse_url($url, PHP_URL_PORT);
        $origin = (stripos($url, 'https://') === 0 ? 'https://' : 'http://') . $host . ($urlPort ? ":{$urlPort}" : '');

        // -------------------- AVAILABILITY --------------------
        $findings[] = $this->finding('availability', 'reachable', 'وصول الموقع', $main['code'] < 400 ? 'pass' : 'fail', $main['code'] < 400 ? 'info' : 'critical', "HTTP {$main['code']}");

        // -------------------- SECURITY --------------------
        $isHttps = stripos($url, 'https://') === 0;
        $findings[] = $this->finding('security', 'https', 'اتصال HTTPS', $isHttps ? 'pass' : 'fail', $isHttps ? 'info' : 'high', $isHttps ? 'الموقع بيستخدم HTTPS' : 'الموقع مش بيستخدم HTTPS - ده بيأثر على الثقة والـ SEO وترتيبه في جوجل');

        $hasHsts = isset($headers['strict-transport-security']);
        $findings[] = $this->finding('security', 'hsts', 'ترويسة HSTS', $hasHsts ? 'pass' : 'warn', $hasHsts ? 'info' : 'medium', $hasHsts ? 'موجودة - بتجبر المتصفح يستخدم HTTPS دايمًا' : 'مفيش Strict-Transport-Security header - ينصح بإضافتها لحماية أفضل');

        $mixedContent = $isHttps && preg_match('/(?:src|href)\s*=\s*["\']http:\/\/(?!(?:www\.)?w3\.org)/i', $html);
        $findings[] = $this->finding('security', 'mixed_content', 'محتوى مختلط (Mixed Content)', $mixedContent ? 'warn' : 'pass', $mixedContent ? 'medium' : 'info', $mixedContent ? 'في موارد بتتحمل عبر HTTP جوه صفحة HTTPS - ده بيسبب تحذيرات أمان في المتصفح' : 'مفيش محتوى مختلط ظاهر');

        // -------------------- SPEED --------------------
        $speedStatus = $main['time'] < 1.5 ? 'pass' : ($main['time'] < 3 ? 'warn' : 'fail');
        $findings[] = $this->finding('speed', 'response_time', 'زمن استجابة الصفحة', $speedStatus, $speedStatus === 'fail' ? 'high' : ($speedStatus === 'warn' ? 'medium' : 'info'), round($main['time'], 2) . ' ثانية');

        $htmlSizeKb = round(strlen($html) / 1024, 1);
        $sizeStatus = $htmlSizeKb < 150 ? 'pass' : ($htmlSizeKb < 300 ? 'warn' : 'fail');
        $findings[] = $this->finding('speed', 'html_size', 'حجم كود HTML', $sizeStatus, $sizeStatus === 'fail' ? 'medium' : ($sizeStatus === 'warn' ? 'low' : 'info'), "{$htmlSizeKb} KB");

        preg_match_all('/<script\b[^>]*\ssrc=/i', $html, $scriptTags);
        preg_match_all('/<link\b[^>]*\srel=["\']stylesheet["\']/i', $html, $cssTags);
        $externalAssets = count($scriptTags[0]) + count($cssTags[0]);
        $assetsStatus = $externalAssets <= 12 ? 'pass' : ($externalAssets <= 25 ? 'warn' : 'fail');
        $findings[] = $this->finding('speed', 'external_assets', 'عدد ملفات JS/CSS الخارجية', $assetsStatus, $assetsStatus === 'fail' ? 'medium' : 'info', "{$externalAssets} ملف - كل ملف زيادة بيبطّئ تحميل الصفحة");

        // -------------------- SEO --------------------
        $hasTitle = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $titleMatch) && trim(html_entity_decode($titleMatch[1])) !== '';
        $titleText = $hasTitle ? trim(html_entity_decode(strip_tags($titleMatch[1]))) : '';
        $titleLen = mb_strlen($titleText);
        $titleStatus = !$hasTitle ? 'fail' : ($titleLen > 60 || $titleLen < 10 ? 'warn' : 'pass');
        $findings[] = $this->finding('seo', 'title_tag', 'وسم العنوان (Title)', $titleStatus, !$hasTitle ? 'critical' : ($titleStatus === 'warn' ? 'medium' : 'info'), $hasTitle ? "الطول: {$titleLen} حرف (المثالي 50-60)" : 'مفيش وسم title في الصفحة');

        $hasDesc = preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $html, $descMatch) && trim($descMatch[1]) !== '';
        $descLen = $hasDesc ? mb_strlen(trim($descMatch[1])) : 0;
        $descStatus = !$hasDesc ? 'fail' : ($descLen < 70 || $descLen > 160 ? 'warn' : 'pass');
        $findings[] = $this->finding('seo', 'meta_description', 'الوصف التعريفي (Meta Description)', $descStatus, !$hasDesc ? 'high' : ($descStatus === 'warn' ? 'low' : 'info'), $hasDesc ? "الطول: {$descLen} حرف (المثالي 120-160)" : 'مفيش meta description - ده بيأثر على ظهور الموقع في نتائج البحث');

        $hasCanonical = preg_match('/<link\s+[^>]*rel=["\']canonical["\'][^>]*>/i', $html);
        $findings[] = $this->finding('seo', 'canonical_tag', 'الرابط الأساسي (Canonical)', $hasCanonical ? 'pass' : 'warn', $hasCanonical ? 'info' : 'medium', $hasCanonical ? 'موجود' : 'مفيش canonical tag - ممكن يسبب مشاكل محتوى مكرر');

        $noindex = preg_match('/<meta\s+[^>]*name=["\']robots["\'][^>]*content=["\'][^"\']*noindex/i', $html);
        $findings[] = $this->finding('seo', 'robots_meta', 'وسم Robots (فهرسة)', $noindex ? 'fail' : 'pass', $noindex ? 'critical' : 'info', $noindex ? 'الصفحة فيها noindex - جوجل مش هيفهرسها خالص!' : 'الصفحة قابلة للفهرسة');

        preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $h1Matches);
        $h1Count = count($h1Matches[0]);
        $h1Status = $h1Count === 1 ? 'pass' : ($h1Count === 0 ? 'fail' : 'warn');
        $findings[] = $this->finding('seo', 'h1_tag', 'وسم العنوان الرئيسي (H1)', $h1Status, $h1Count === 0 ? 'high' : ($h1Count > 1 ? 'medium' : 'info'), $h1Count === 0 ? 'مفيش H1 في الصفحة' : ($h1Count === 1 ? 'موجود H1 واحد (الأفضل)' : "في {$h1Count} وسم H1 - يفضل يكون واحد بس"));

        $h2Count = preg_match_all('/<h2\b[^>]*>/i', $html);
        $findings[] = $this->finding('seo', 'heading_structure', 'التسلسل الهرمي للعناوين', $h2Count > 0 ? 'pass' : 'warn', $h2Count > 0 ? 'info' : 'low', $h2Count > 0 ? "في {$h2Count} وسم H2 - بنية واضحة" : 'مفيش وسوم H2 - المحتوى ممكن يبقى صعب على جوجل يفهم تقسيمه');

        $htmlLangMatch = null;
        $hasHtmlLang = preg_match('/<html\s+[^>]*lang=["\']([a-zA-Z-]+)["\']/i', $html, $htmlLangMatch);
        $findings[] = $this->finding('seo', 'html_lang', 'وسم اللغة (html lang)', $hasHtmlLang ? 'pass' : 'warn', $hasHtmlLang ? 'info' : 'medium', $hasHtmlLang ? 'اللغة المحددة: ' . $htmlLangMatch[1] : 'مفيش lang attribute على وسم html - بيأثر على SEO وقارئات الشاشة');

        $jsonLdBlocks = $this->extractJsonLd($html);
        $schemaTypes = [];
        foreach ($jsonLdBlocks as $block) {
            $types = $this->collectSchemaTypes($block);
            $schemaTypes = array_merge($schemaTypes, $types);
        }
        $schemaTypes = array_unique($schemaTypes);

        // عدّ البلوكات غير السليمة + الاحتفاظ بالنص الخام لفحوصات GEO المتقدمة
        $invalidJsonLd = 0;
        $jsonLdRawText = '';
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $rawLd)) {
            foreach ($rawLd[1] as $raw) {
                $jsonLdRawText .= $raw . "\n";
                if (json_decode(trim($raw), true) === null) {
                    $invalidJsonLd++;
                }
            }
        }
        $hasStructuredData = !empty($schemaTypes);
        $findings[] = $this->finding('seo', 'structured_data', 'بيانات منظّمة (Schema.org / JSON-LD)', $hasStructuredData ? 'pass' : 'fail', $hasStructuredData ? 'info' : 'high', $hasStructuredData ? 'الأنواع الموجودة: ' . implode(', ', $schemaTypes) : 'مفيش أي JSON-LD schema - ده بيقلل فرصة ظهور الموقع بشكل غني في نتائج البحث');

        $hasOg = preg_match('/<meta\s+[^>]*property=["\']og:title["\']/i', $html) && preg_match('/<meta\s+[^>]*property=["\']og:description["\']/i', $html);
        $hasOgImage = preg_match('/<meta\s+[^>]*property=["\']og:image["\']/i', $html);
        $ogStatus = ($hasOg && $hasOgImage) ? 'pass' : ($hasOg || $hasOgImage ? 'warn' : 'fail');
        $findings[] = $this->finding('seo', 'open_graph', 'وسوم Open Graph (مشاركة السوشيال ميديا)', $ogStatus, $ogStatus === 'fail' ? 'medium' : ($ogStatus === 'warn' ? 'low' : 'info'), $ogStatus === 'pass' ? 'og:title و og:description و og:image كلهم موجودين' : 'ناقص وسوم Open Graph - هيأثر على شكل الرابط لما يتشارك على فيسبوك/واتساب');

        preg_match_all('/<img\s+[^>]*>/i', $html, $imgTags);
        $totalImages = count($imgTags[0]);
        $imagesWithoutAlt = 0;
        foreach ($imgTags[0] as $tag) {
            if (!preg_match('/alt\s*=\s*["\'][^"\']+["\']/i', $tag)) {
                $imagesWithoutAlt++;
            }
        }
        if ($totalImages > 0) {
            $ratio = $imagesWithoutAlt / $totalImages;
            $findings[] = $this->finding('accessibility', 'image_alt', 'نص بديل للصور (Alt Text)', $ratio === 0.0 ? 'pass' : ($ratio < 0.5 ? 'warn' : 'fail'), $ratio > 0.5 ? 'medium' : 'low', "{$imagesWithoutAlt} من أصل {$totalImages} صورة من غير alt text");
        }

        // -------------------- MOBILE --------------------
        $hasViewport = preg_match('/<meta\s+[^>]*name=["\']viewport["\'][^>]*>/i', $html, $vpMatch);
        $goodViewport = $hasViewport && stripos($vpMatch[0], 'width=device-width') !== false;
        $findings[] = $this->finding('mobile', 'viewport', 'إعداد العرض للموبايل (Viewport)', $goodViewport ? 'pass' : ($hasViewport ? 'warn' : 'fail'), $goodViewport ? 'info' : 'high', $goodViewport ? 'موجود وصحيح (width=device-width)' : ($hasViewport ? 'موجود لكن مش بالإعداد المثالي' : 'مفيش meta viewport - الموقع ممكن ميظهرش صح على الموبايل'));

        $hasTouchIcon = preg_match('/<link\s+[^>]*rel=["\']apple-touch-icon["\']/i', $html) || preg_match('/<link\s+[^>]*rel=["\']icon["\']/i', $html);
        $findings[] = $this->finding('mobile', 'favicon', 'أيقونة الموقع (Favicon)', $hasTouchIcon ? 'pass' : 'warn', $hasTouchIcon ? 'info' : 'low', $hasTouchIcon ? 'موجودة' : 'مفيش favicon محدد - بيأثر على شكل الموقع في التابات والمفضلة');

        // -------------------- AEO (Answer Engine Optimization) --------------------
        $hasFaqSchema = in_array('FAQPage', $schemaTypes, true);
        $findings[] = $this->finding('aeo', 'faq_schema', 'بيانات FAQ منظّمة (FAQPage Schema)', $hasFaqSchema ? 'pass' : 'warn', $hasFaqSchema ? 'info' : 'medium', $hasFaqSchema ? 'موجودة - فرصة أعلى للظهور في Featured Snippets والإجابات الصوتية' : 'مفيش FAQPage schema - لو عندك أسئلة شائعة في الموقع، إضافتها بتزود فرصة الظهور كإجابة مباشرة في جوجل وسيري وأليكسا');

        $hasHowToSchema = in_array('HowTo', $schemaTypes, true);
        $findings[] = $this->finding('aeo', 'howto_schema', 'بيانات خطوات منظّمة (HowTo Schema)', $hasHowToSchema ? 'pass' : 'info', 'info', $hasHowToSchema ? 'موجودة' : 'مش مطبقة - مفيدة لو المحتوى بيشرح خطوات (زي "إزاي تحجز رحلة")');

        $questionHeadings = preg_match_all('/<h[2-4][^>]*>[^<]*[؟?][^<]*<\/h[2-4]>/iu', $html);
        $findings[] = $this->finding('aeo', 'question_headings', 'عناوين بصيغة سؤال', $questionHeadings > 0 ? 'pass' : 'warn', $questionHeadings > 0 ? 'info' : 'low', $questionHeadings > 0 ? "في {$questionHeadings} عنوان بصيغة سؤال - ده بيساعد محركات الإجابة تفهم المحتوى كإجابة مباشرة" : 'مفيش عناوين بصيغة سؤال - صياغة أقسام كـ "إزاي..؟"/"إيه هو..؟" بتزود فرصة الظهور كإجابة مباشرة');

        $hasSpeakable = in_array('SpeakableSpecification', $schemaTypes, true) || stripos($html, 'speakable') !== false;
        $findings[] = $this->finding('aeo', 'speakable', 'محتوى قابل للقراءة الصوتية (Speakable)', $hasSpeakable ? 'pass' : 'info', 'info', $hasSpeakable ? 'موجود' : 'مش مطبق - مفيد لو الموقع بيستهدف المساعدات الصوتية زي جوجل أسيستنت');

        // -------------------- GEO (Generative Engine Optimization) --------------------
        $robots = $this->fetchAuxFile($origin, '/robots.txt');
        $llms = $this->fetchAuxFile($origin, '/llms.txt');

        $hasLlmsTxt = $llms['exists'];
        $findings[] = $this->finding('geo', 'llms_txt', 'ملف llms.txt', $hasLlmsTxt ? 'pass' : 'warn', $hasLlmsTxt ? 'info' : 'medium', $hasLlmsTxt ? 'موجود - بيساعد نماذج الذكاء الاصطناعي (ChatGPT/Claude/Perplexity) تفهم محتوى الموقع بسرعة' : 'مفيش ملف llms.txt - ده معيار جديد بيوجّه روبوتات الذكاء الاصطناعي التوليدي لأهم صفحات موقعك، ومعظم المواقع لسه ملهاش، فوجوده ميزة تنافسية حقيقية');

        $blockedBots = [];
        if ($robots['exists']) {
            foreach (self::AI_CRAWLER_BOTS as $bot) {
                if (preg_match('/User-agent:\s*' . preg_quote($bot, '/') . '\s*\n(?:\s*\n)*\s*Disallow:\s*\/\s*(?:$|\n)/im', $robots['body'])) {
                    $blockedBots[] = $bot;
                }
            }
        }
        $findings[] = $this->finding('geo', 'ai_crawler_access', 'صلاحية وصول روبوتات الذكاء الاصطناعي', empty($blockedBots) ? 'pass' : 'warn', empty($blockedBots) ? 'info' : 'medium', empty($blockedBots) ? 'مفيش حظر صريح لروبوتات AI الرئيسية في robots.txt - المحتوى متاح ليها تقرأه وتستشهد بيه' : 'الروبوتات دي محظورة في robots.txt: ' . implode(', ', $blockedBots) . ' - يعني موقعك مش هيظهر في إجابات هذه الأدوات');

        $hasOrgSchema = in_array('Organization', $schemaTypes, true) || in_array('LocalBusiness', $schemaTypes, true) || in_array('WebSite', $schemaTypes, true);
        $findings[] = $this->finding('geo', 'entity_schema', 'بيانات الكيان (Organization/WebSite Schema)', $hasOrgSchema ? 'pass' : 'fail', $hasOrgSchema ? 'info' : 'high', $hasOrgSchema ? 'موجودة - بتساعد جوجل ونماذج الذكاء الاصطناعي يفهموا مين صاحب الموقع بدقة' : 'مفيش schema من نوع Organization أو WebSite - نماذج الذكاء الاصطناعي بتعتمد على البيانات دي عشان تفهم هوية الموقع قبل ما تستشهد بيه');

        $hasAuthorSignal = preg_match('/<meta\s+[^>]*name=["\']author["\']/i', $html) || in_array('Person', $schemaTypes, true) || stripos($html, 'rel="author"') !== false;
        $findings[] = $this->finding('geo', 'author_signal', 'إشارات المصداقية (Author / E-E-A-T)', $hasAuthorSignal ? 'pass' : 'warn', $hasAuthorSignal ? 'info' : 'low', $hasAuthorSignal ? 'موجودة' : 'مفيش إشارة واضحة لكاتب/مصدر المحتوى - إشارات المصداقية (E-E-A-T) بقت عامل مهم في اختيار جوجل والأدوات التوليدية لمصادرها');

        $hasFreshness = preg_match('/<meta\s+[^>]*property=["\']article:modified_time["\']/i', $html) || preg_match('/<time\b[^>]*datetime=/i', $html);
        $findings[] = $this->finding('geo', 'freshness_signal', 'إشارة حداثة المحتوى', $hasFreshness ? 'pass' : 'warn', $hasFreshness ? 'info' : 'low', $hasFreshness ? 'موجودة' : 'مفيش تاريخ تحديث واضح - المحتوى المؤرّخ بيتفضّل عند نماذج الذكاء الاصطناعي التوليدي');

        $semanticTags = preg_match_all('/<(main|article|nav|section|header|footer)\b/i', $html);
        $findings[] = $this->finding('geo', 'semantic_html', 'وسوم HTML5 الدلالية (Semantic HTML)', $semanticTags >= 3 ? 'pass' : 'warn', $semanticTags >= 3 ? 'info' : 'low', $semanticTags >= 3 ? "في {$semanticTags} وسم دلالي (main/article/section..) - بنية واضحة" : 'استخدام محدود لوسوم HTML الدلالية - بنية واضحة (main/article/section) بتسهّل على أدوات الذكاء الاصطناعي تفهم وتستخلص المحتوى بدقة');

        $bodyMatch = preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $bm);
        $textContent = $bodyMatch ? trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $bm[1])))) : '';
        $textLen = mb_strlen($textContent);
        $htmlLen = max(1, strlen($html));
        $textRatio = $textLen > 0 ? min(1, ($textLen * 1.0) / $htmlLen) : 0;
        $lowTextContent = $textLen < 200;
        $findings[] = $this->finding('geo', 'js_render_risk', 'اعتمادية عرض المحتوى على JavaScript', $lowTextContent ? 'warn' : 'pass', $lowTextContent ? 'medium' : 'info', $lowTextContent ? "النص الظاهر في الـ HTML الخام قليل جدًا ({$textLen} حرف تقريبًا) - لو المحتوى الحقيقي بيتحمّل بجافاسكريبت بعد التحميل، روبوتات كتير (خصوصًا AI crawlers) ممكن تشوف صفحة شبه فاضية" : "المحتوى النصي ظاهر مباشرة في الـ HTML ({$textLen} حرف تقريبًا) - سهل على أي روبوت يقرأه من غير تنفيذ JS");

        $sitemapExists = $this->sitemapExists($origin, $robots);
        $findings[] = $this->finding('seo', 'sitemap', 'خريطة الموقع (Sitemap)', $sitemapExists ? 'pass' : 'warn', $sitemapExists ? 'info' : 'medium', $sitemapExists ? 'موجودة' : 'مفيش sitemap.xml ظاهر - بيبطّئ اكتشاف جوجل (وروبوتات AI) لصفحات موقعك الجديدة');

        // -------------------- BROKEN LINKS --------------------
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $html, $linkMatches);
        $links = array_slice(array_unique($linkMatches[1]), 0, 10);
        foreach ($links as $link) {
            if (stripos($link, 'mailto:') === 0 || stripos($link, 'tel:') === 0 || stripos($link, '#') === 0 || trim($link) === '') {
                continue;
            }
            $target = $this->resolveUrl($url, $link);
            $linkType = stripos($target, $host) !== false ? 'internal' : 'external';

            $linkCheck = $this->httpGet($target, 6, true);
            if ($linkCheck['code'] >= 400 || $linkCheck['code'] === 0) {
                $brokenLinks[] = ['target_url' => $target, 'link_type' => $linkType, 'status_code' => $linkCheck['code'], 'error' => $linkCheck['error'] ?: "HTTP {$linkCheck['code']}"];
            }
        }
        $findings[] = $this->finding('broken_links', 'link_check', 'فحص الروابط', empty($brokenLinks) ? 'pass' : 'warn', empty($brokenLinks) ? 'info' : 'medium', empty($brokenLinks) ? 'مفيش روابط مكسورة في العينة اللي اتفحصت' : count($brokenLinks) . ' رابط مكسور من عينة ' . count($links));

        // -------------------- ADVANCED CHECKS (AuditChecksService) --------------------
        $advancedCtx = [
            'url' => $url,
            'origin' => $origin,
            'host' => $host,
            'html' => $html,
            'headers' => $headers,
            'robots_exists' => $robots['exists'],
            'robots_body' => $robots['body'],
            'sitemap_exists' => $sitemapExists,
            'schema_types' => $schemaTypes,
            'invalid_json_ld' => $invalidJsonLd,
            'json_ld_raw' => $jsonLdRawText,
            'main_redirects' => $main['redirects'],
            'body_text' => $textContent,
        ];
        if (class_exists('AuditChecksService')) {
            $advancedService = new AuditChecksService();
            if ($this->auditDeadline !== null) {
                $advancedService->setDeadline($this->auditDeadline);
            }
            $advanced = $advancedService->run($advancedCtx);
            $findings = array_merge($findings, $advanced['findings']);
            $brokenLinks = array_merge($brokenLinks, $advanced['broken_links']);
            $this->auditSkipped += $advancedService->skippedRequests();
        }

        // لو الميزانية الزمنية خلصت: نقول للمستخدم صراحة إن بعض الفحوص ما اتنفّذتش (الموقع بطيء) بدل ما تظهر كأنها فشلت
        if ($this->auditSkipped > 0) {
            $findings[] = $this->finding(
                'availability', 'audit_time_budget', 'Audit completeness', 'warn', 'info',
                'The site responded slowly, so ' . $this->auditSkipped . ' network check(s) were skipped to finish on time. Some results (links, sitemap, redirects) may be incomplete. Re-run the audit later for a full report.'
            );
        }

        return [
            'findings' => $findings,
            'broken_links' => $brokenLinks,
            'context' => [
                'url' => $url,
                'origin' => $origin,
                'html' => $html,
                'title' => $titleText,
                'has_title' => $hasTitle,
                'has_desc' => $hasDesc,
                'schema_types' => $schemaTypes,
                'robots_exists' => $robots['exists'],
                'robots_body' => $robots['body'],
                'llms_exists' => $hasLlmsTxt,
                'blocked_ai_bots' => $blockedBots,
                'has_org_schema' => $hasOrgSchema,
                'sitemap_exists' => $sitemapExists,
            ],
        ];
    }

    /** طلب HTTP فعلي لصفحة، مع إمكانية إرجاع الـ headers ووقت الاستجابة */
    /** سقف زمني (timestamp) للتدقيق الحالي. null = من غير سقف (الاستخدام العادي من اللوحة). */
    private $auditDeadline = null;

    /** عدد الطلبات اللي اتخطّت بسبب انتهاء الميزانية (بنعرضها للمستخدم كملاحظة بدل نتايج مضلّلة) */
    private $auditSkipped = 0;

    private function httpGet(string $url, int $timeout = 15, bool $headOnly = false, bool $withHeaders = false): array
    {
        $start = microtime(true);
        // التدقيق بيعمل ~15 طلب ورا بعض (الصفحة، robots، llms، sitemap، 10 روابط) وكل واحد ممكن يستنى 15 ثانية:
        // موقع بطيء أو مش بيرد على السيرفر نفسه كان بيوصّل التدقيق لأكتر من 3 دقايق ويتقطع في النص.
        // دلوقتي بعد انتهاء الميزانية الزمنية بنخلّص الباقي فورًا ونكمل بالنتايج اللي اتجمعت.
        if ($this->auditDeadline !== null) {
            $remaining = $this->auditDeadline - $start;
            if ($remaining < 1.5) {
                $this->auditSkipped++;
                return ['body' => null, 'code' => 0, 'error' => 'audit time budget exceeded', 'time' => 0.0, 'headers' => [], 'redirects' => 0];
            }
            $timeout = (int) max(2, min($timeout, (int) ceil($remaining)));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TourfectoWebsiteOptimizer/2.0 (+SEO/AEO/GEO audit bot)',
            CURLOPT_NOBODY => $headOnly,
            CURLOPT_HEADER => $withHeaders,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $time = microtime(true) - $start;
        $redirects = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);

        $body = null;
        $headers = [];
        if ($response !== false && $response !== null) {
            if ($withHeaders) {
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $rawHeaders = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);
                foreach (explode("\r\n", $rawHeaders) as $line) {
                    if (strpos($line, ':') !== false) {
                        [$k, $v] = explode(':', $line, 2);
                        $headers[strtolower(trim($k))] = trim($v);
                    }
                }
            } else {
                $body = $response;
            }
        }
        curl_close($ch);

        return ['body' => $body, 'code' => $code, 'error' => $error, 'time' => $time, 'headers' => $headers, 'redirects' => $redirects];
    }

    /** جلب ملف مساعد زي robots.txt أو llms.txt من جذر الدومين */
    private function fetchAuxFile(string $origin, string $path): array
    {
        $res = $this->httpGet(rtrim($origin, '/') . $path, 6, false);
        $exists = $res['code'] >= 200 && $res['code'] < 400 && !empty($res['body']);
        return ['exists' => $exists, 'body' => $exists ? $res['body'] : ''];
    }

    /** فحص وجود sitemap: أولًا من robots.txt (Sitemap:)، ولو مش موجود يجرب /sitemap.xml مباشرة */
    private function sitemapExists(string $origin, array $robots): bool
    {
        if ($robots['exists'] && stripos($robots['body'], 'Sitemap:') !== false) {
            return true;
        }
        $res = $this->httpGet(rtrim($origin, '/') . '/sitemap.xml', 6, true);
        return $res['code'] >= 200 && $res['code'] < 400;
    }

    /** استخراج كل بلوكات JSON-LD من الصفحة وفكّها كمصفوفات */
    private function extractJsonLd(string $html): array
    {
        $blocks = [];
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    $blocks[] = $decoded;
                }
            }
        }
        return $blocks;
    }

    /** تجميع كل قيم @type من بلوك JSON-LD (بيدعم @graph وblocks متداخلة) */
    private function collectSchemaTypes(array $block): array
    {
        $types = [];
        $walk = function ($node) use (&$walk, &$types) {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['@type'])) {
                $t = $node['@type'];
                if (is_array($t)) {
                    foreach ($t as $tt) {
                        $types[] = $tt;
                    }
                } else {
                    $types[] = $t;
                }
            }
            if (isset($node['@graph']) && is_array($node['@graph'])) {
                foreach ($node['@graph'] as $child) {
                    $walk($child);
                }
            }
            foreach ($node as $k => $v) {
                if ($k !== '@graph' && is_array($v) && isset($v[0]) && is_array($v[0])) {
                    foreach ($v as $child) {
                        $walk($child);
                    }
                }
            }
        };
        $walk($block);
        return array_values(array_unique($types));
    }

    private function finding(string $category, string $checkKey, string $title, string $status, string $severity, string $message): array
    {
        return ['category' => $category, 'check_key' => $checkKey, 'title' => $title, 'status' => $status, 'severity' => $severity, 'message' => $message];
    }

    private function resolveUrl(string $base, string $link): string
    {
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        // تصحيح: كان بيتجاهل رقم البورت (لو مختلف عن 80/443)، فأي موقع
        // staging أو تجريبي شغال على بورت مخصص كان بيطلع كل روابطه
        // الداخلية "مكسورة" غلط لإنه بيحاول يوصل لبورت 80 الافتراضي.
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        if (strpos($link, '/') === 0) {
            return "{$scheme}://{$host}{$port}{$link}";
        }
        return rtrim($base, '/') . '/' . ltrim($link, '/');
    }

    private function calculateScore(array $findings): float
    {
        if (empty($findings)) {
            return 0;
        }
        $weights = ['pass' => 100, 'warn' => 60, 'fail' => 0, 'info' => 100];
        $total = 0;
        foreach ($findings as $f) {
            $total += $weights[$f['status']] ?? 50;
        }
        return round($total / count($findings), 1);
    }

    /** حساب نتيجة منفصلة لكل فئة (SEO/AEO/GEO/سرعة/أمان..) عشان لوحة تحكم احترافية */
    private function calculateCategoryScores(array $findings): array
    {
        $weights = ['pass' => 100, 'warn' => 60, 'fail' => 0, 'info' => 100];
        $byCategory = [];
        foreach ($findings as $f) {
            $byCategory[$f['category']][] = $weights[$f['status']] ?? 50;
        }
        $scores = [];
        foreach (self::CATEGORY_ORDER as $cat) {
            if (empty($byCategory[$cat])) {
                continue;
            }
            $scores[$cat] = round(array_sum($byCategory[$cat]) / count($byCategory[$cat]), 1);
        }
        return $scores;
    }

    // =========================================================================
    // مولّد الإصلاحات التلقائي - Auto-Fix Generator
    // كل finding فاشل أو فيه تحذير بيتحول (لو أمكن) لإصلاح جاهز: كود حقيقي
    // جاهز للنسخ واللصق، مش مجرد "لازم تصلح الحاجة دي". ده أقرب حاجة ممكنة
    // لتحسين تلقائي حقيقي بدون صلاحية كتابة مباشرة على سيرفر الموقع نفسه
    // (اللي مش متاحة تقنيًا لموقع خارجي بنفحصه عبر HTTP بس).
    // =========================================================================

    private function generateFixes(array $findings, array $context): array
    {
        $fixes = [];
        foreach ($findings as $f) {
            if (!in_array($f['status'], ['fail', 'warn'], true)) {
                continue;
            }
            $fix = $this->buildFix($f, $context);
            if ($fix !== null) {
                // Phase 5 (Auto-Apply): wo_fixes الأصلي ماكانش بيخزّن check_key
                // خالص (كان موجود بس على wo_audit_findings) - بنضيفه هنا عشان
                // applyFixAutomatically() تعرف تحدد نوع كل إصلاح لاحقًا.
                $fix['check_key'] = $f['check_key'];
                $fixes[] = $fix;
            }
        }
        return $fixes;
    }

    private function buildFix(array $f, array $context): ?array
    {
        $host = parse_url($context['url'] ?? '', PHP_URL_HOST) ?? 'example.com';
        // Phase 5 (Auto-Apply): لو الموقع ده Website Builder بتاعنا، نحسب
        // suggested_value حقيقي من محتوى الموقع الفعلي (مش placeholder) -
        // ده اللي بيتكتب فعليًا في generated_websites لما العميل يضغط
        // "طبّق تلقائيًا". لأي موقع خارجي تاني، القيمة دي بتفضل null
        // (زي ما كانت الحالة قبل الـPhase دي بالظبط) وبيتعرض الكود الجاهز
        // بس زي الأول.
        $genContent = $context['generated_website_content'] ?? null;
        $suggestedTitle = null;
        $suggestedDescription = null;
        if (is_array($genContent)) {
            $businessName = trim((string) ($genContent['business_name'] ?? ''));
            $tagline = trim((string) ($genContent['tagline'] ?? ''));
            $aboutText = trim((string) ($genContent['about_text'] ?? ''));
            if ($businessName !== '') {
                $suggestedTitle = $tagline !== '' ? mb_substr("{$businessName} | {$tagline}", 0, 60) : mb_substr($businessName, 0, 60);
            }
            if ($aboutText !== '') {
                $suggestedDescription = mb_substr(preg_replace('/\s+/', ' ', $aboutText), 0, 160);
            } elseif ($businessName !== '' && $tagline !== '') {
                $suggestedDescription = mb_substr("{$businessName} - {$tagline}", 0, 160);
            }
        }

        switch ($f['check_key']) {
            case 'title_tag':
                $fix = $this->fix(
                    'seo',
                    'إصلاح وسم العنوان (Title)',
                    'اكتب عنوان فريد وواضح لكل صفحة بطول 50-60 حرف، يتضمن الكلمة المفتاحية الأساسية واسم البراند، بدون تكرار كلمات.',
                    'code_snippet',
                    "<title>الكلمة المفتاحية الأساسية | اسم البراند</title>",
                    '<head> في كل صفحة'
                );
                if ($suggestedTitle) {
                    $fix['suggested_value'] = $suggestedTitle;
                }
                return $fix;

            case 'meta_description':
                $fix = $this->fix(
                    'seo',
                    'إضافة/تحسين Meta Description',
                    'وصف تسويقي جذاب بطول 120-160 حرف يشجع المستخدم يضغط من نتائج البحث، ويحتوي الكلمة المفتاحية بشكل طبيعي.',
                    'code_snippet',
                    "<meta name=\"description\" content=\"وصف مختصر وجذاب للصفحة (120-160 حرف) يوضح القيمة اللي هيلاقيها الزائر.\">",
                    '<head> في كل صفحة'
                );
                if ($suggestedDescription) {
                    $fix['suggested_value'] = $suggestedDescription;
                }
                return $fix;

            case 'canonical_tag':
                return $this->fix(
                    'seo',
                    'إضافة Canonical Tag',
                    'يمنع مشاكل المحتوى المكرر ويوضح لجوجل النسخة الأساسية من الصفحة.',
                    'code_snippet',
                    "<link rel=\"canonical\" href=\"https://{$host}" . (parse_url($context['url'] ?? '', PHP_URL_PATH) ?: '/') . "\">",
                    '<head> في كل صفحة'
                );

            case 'robots_meta':
                return $this->fix(
                    'seo',
                    'إزالة noindex عن الصفحة',
                    'الصفحة حاليًا فيها noindex فجوجل مش بيفهرسها. لو ده مقصود اسيبها، لو غلط شيل الوسم ده أو استبدله بده:',
                    'code_snippet',
                    "<meta name=\"robots\" content=\"index, follow\">",
                    '<head> في الصفحة المتأثرة'
                );

            case 'h1_tag':
                return $this->fix(
                    'seo',
                    'ضبط وسم H1',
                    'لازم يكون في H1 واحد بالظبط في الصفحة، يوضح موضوعها الرئيسي (مش نفس الـ title بالحرف، لكن قريب منه في المعنى).',
                    'code_snippet',
                    "<h1>العنوان الرئيسي الواضح للصفحة</h1>",
                    'أول عنصر في <body> غالبًا'
                );

            case 'open_graph':
                return $this->fix(
                    'seo',
                    'إضافة وسوم Open Graph كاملة',
                    'عشان الرابط يظهر بشكل احترافي (صورة + عنوان + وصف) لما يتشارك على فيسبوك/واتساب/لينكدإن.',
                    'code_snippet',
                    "<meta property=\"og:title\" content=\"عنوان الصفحة\">\n<meta property=\"og:description\" content=\"وصف مختصر وجذاب\">\n<meta property=\"og:image\" content=\"https://{$host}/images/share-preview.jpg\">\n<meta property=\"og:url\" content=\"https://{$host}" . (parse_url($context['url'] ?? '', PHP_URL_PATH) ?: '/') . "\">\n<meta property=\"og:type\" content=\"website\">\n<meta name=\"twitter:card\" content=\"summary_large_image\">",
                    '<head> في كل صفحة'
                );

            case 'structured_data':
                return $this->fix(
                    'seo',
                    'إضافة بيانات منظّمة أساسية (JSON-LD)',
                    'schema.org من نوع Organization بيوضح هوية صاحب الموقع لجوجل ولنماذج الذكاء الاصطناعي، وبيزود فرصة الظهور بشكل غني في نتائج البحث.',
                    'code_snippet',
                    "<script type=\"application/ld+json\">\n{\n  \"@context\": \"https://schema.org\",\n  \"@type\": \"Organization\",\n  \"name\": \"اسم الشركة/البراند\",\n  \"url\": \"https://{$host}\",\n  \"logo\": \"https://{$host}/logo.png\",\n  \"sameAs\": [\n    \"https://facebook.com/yourpage\",\n    \"https://instagram.com/yourpage\"\n  ]\n}\n</script>",
                    '<head> أو نهاية <body> في الصفحة الرئيسية'
                );

            case 'html_lang':
                return $this->fix(
                    'seo',
                    'إضافة lang attribute',
                    'يوضح لغة الصفحة لمحركات البحث وقارئات الشاشة.',
                    'code_snippet',
                    "<html lang=\"ar\" dir=\"rtl\">",
                    'وسم <html> الرئيسي'
                );

            case 'sitemap':
                return $this->fix(
                    'seo',
                    'إنشاء sitemap.xml وربطه بـ robots.txt',
                    'خريطة موقع بسيطة تسهّل على جوجل وروبوتات الذكاء الاصطناعي اكتشاف كل صفحاتك بسرعة.',
                    'code_snippet',
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n  <url>\n    <loc>https://{$host}/</loc>\n    <changefreq>weekly</changefreq>\n    <priority>1.0</priority>\n  </url>\n  <!-- أضف باقي صفحاتك هنا -->\n</urlset>\n\n# ثم أضف السطر ده في robots.txt:\nSitemap: https://{$host}/sitemap.xml",
                    '/sitemap.xml + /robots.txt'
                );

            case 'viewport':
                return $this->fix(
                    'mobile',
                    'إصلاح إعداد Viewport',
                    'بدون الإعداد ده صح، الموقع هيظهر مصغّر أو مقطوع على الموبايل.',
                    'code_snippet',
                    "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">",
                    '<head> في كل صفحة'
                );

            case 'favicon':
                return $this->fix(
                    'mobile',
                    'إضافة Favicon',
                    'أيقونة صغيرة بتظهر في تاب المتصفح وقائمة المفضلة، بتدي انطباع احترافي.',
                    'code_snippet',
                    "<link rel=\"icon\" type=\"image/png\" href=\"/favicon.png\">\n<link rel=\"apple-touch-icon\" href=\"/apple-touch-icon.png\">",
                    '<head> في كل صفحة'
                );

            case 'https':
                return $this->fix(
                    'security',
                    'تفعيل HTTPS على الموقع',
                    'الموقع حاليًا شغال من غير HTTPS، وده بيأثر على ثقة الزوار وترتيب الموقع في جوجل (وبيمنعه من الظهور كمصدر موثوق لنماذج الذكاء الاصطناعي). الخطوات:',
                    'config',
                    "1) فعّل شهادة SSL مجانية (Let's Encrypt) من لوحة تحكم الاستضافة أو عبر السيرفر.\n2) بعد التفعيل، حوّل كل زيارات HTTP لـ HTTPS تلقائيًا:\n\n# Apache (.htaccess)\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n\n# Nginx\nserver {\n  listen 80;\n  return 301 https://\$host\$request_uri;\n}\n\n3) حدّث كل الروابط الداخلية والـ canonical وOpen Graph عشان تبقى https:// بدل http://.",
                    '.htaccess/إعدادات Nginx + شهادة SSL من الاستضافة'
                );

            case 'response_time':
                return $this->fix(
                    'speed',
                    'تسريع زمن استجابة الصفحة',
                    'زمن الاستجابة الحالي بطيء وبيأثر على تجربة المستخدم وترتيب الموقع. أكتر أسباب شائعة وحلولها:',
                    'config',
                    "1) فعّل الكاش على مستوى السيرفر أو استخدم CDN (Cloudflare مجانًا كخطوة أولى).\n2) فعّل ضغط الاستجابة (Gzip/Brotli):\n\n# Apache (.htaccess)\n<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json\n</IfModule>\n\n3) لو الموقع بيعتمد على قاعدة بيانات، فعّل كاش الاستعلامات المتكررة (object cache / query cache).\n4) راجع استضافتك - لو shared hosting بطيء، فكّر تنقل لـ VPS أو استضافة أسرع.",
                    '.htaccess / إعدادات السيرفر / لوحة تحكم CDN'
                );

            case 'heading_structure':
                return $this->fix(
                    'seo',
                    'إضافة عناوين فرعية (H2) للصفحة',
                    'قسّم محتوى الصفحة لأقسام واضحة بعناوين H2 (وH3 لو محتاج تفريع أكتر) - ده بيسهّل على الزوار وعلى جوجل ونماذج الذكاء الاصطناعي فهم بنية المحتوى.',
                    'content',
                    "مثال على بنية عناوين واضحة:\n\n<h1>العنوان الرئيسي للصفحة</h1>\n<h2>القسم الأول</h2>\n<p>محتوى القسم الأول...</p>\n<h2>القسم الثاني</h2>\n<h3>تفصيلة فرعية</h3>\n<p>محتوى...</p>",
                    'محتوى الصفحة - بعد H1 مباشرة'
                );

            case 'link_check':
                return $this->fix(
                    'broken_links',
                    'إصلاح الروابط المكسورة',
                    'راجع الروابط اللي ظهرت في نتيجة الفحص (قسم "الروابط المكسورة" بالأسفل) وطبّق واحد من الحلول دي لكل رابط:',
                    'content',
                    "1) لو الصفحة الهدف اتنقلت لمكان تاني: حدّث الرابط للمسار الجديد.\n2) لو الصفحة اتشالت نهائيًا: اعمل 301 redirect للصفحة الأقرب في المعنى بدل حذفها بدون توجيه.\n3) لو رابط خارجي مبقاش موجود: شيل الرابط أو استبدله بمصدر بديل.\n\n# مثال 301 redirect في .htaccess\nRedirect 301 /old-page https://{$host}/new-page",
                    'الصفحات اللي فيها الروابط المكسورة (مذكورة في تفاصيل الفحص)'
                );

            case 'hsts':
                return $this->fix(
                    'security',
                    'تفعيل HSTS',
                    'بيجبر المتصفح يستخدم HTTPS دايمًا مع موقعك، حتى لو المستخدم كتب http:// بالغلط. أضف السطر ده على مستوى السيرفر:',
                    'config',
                    "# Apache (.htaccess)\nHeader always set Strict-Transport-Security \"max-age=31536000; includeSubDomains\"\n\n# Nginx\nadd_header Strict-Transport-Security \"max-age=31536000; includeSubDomains\" always;",
                    '.htaccess أو ملف إعدادات Nginx'
                );

            case 'mixed_content':
                return $this->fix(
                    'security',
                    'إصلاح المحتوى المختلط',
                    'استبدل أي رابط بيبدأ بـ http:// بروابط https://، أو استخدم روابط نسبية (بدون تحديد البروتوكول):',
                    'code_snippet',
                    "<!-- بدل ده -->\n<img src=\"http://example.com/image.jpg\">\n\n<!-- استخدم ده -->\n<img src=\"https://example.com/image.jpg\">\n<!-- أو الأفضل: رابط نسبي -->\n<img src=\"//example.com/image.jpg\">",
                    'كل ملفات HTML/CSS اللي فيها روابط http://'
                );

            case 'image_alt':
                return $this->fix(
                    'accessibility',
                    'إضافة Alt Text للصور',
                    'نص بديل قصير وواضح يوصف الصورة - مهم لمحركات البحث ولقارئات الشاشة لذوي الإعاقة البصرية.',
                    'code_snippet',
                    "<img src=\"tour-photo.jpg\" alt=\"وصف مختصر ودقيق للصورة، مثلاً: رحلة سفاري في الصحراء الغربية\">",
                    'كل وسوم <img> في الصفحة'
                );

            case 'faq_schema':
                return $this->fix(
                    'aeo',
                    'إضافة FAQ Schema',
                    'لو عندك قسم أسئلة شائعة، البيانات المنظّمة دي بتزود فرصة ظهورك كإجابة مباشرة (Featured Snippet) في جوجل وفي المساعدات الصوتية.',
                    'code_snippet',
                    "<script type=\"application/ld+json\">\n{\n  \"@context\": \"https://schema.org\",\n  \"@type\": \"FAQPage\",\n  \"mainEntity\": [\n    {\n      \"@type\": \"Question\",\n      \"name\": \"سؤال شائع هنا؟\",\n      \"acceptedAnswer\": {\n        \"@type\": \"Answer\",\n        \"text\": \"إجابة مختصرة وواضحة على السؤال.\"\n      }\n    }\n  ]\n}\n</script>",
                    'صفحة الأسئلة الشائعة'
                );

            case 'question_headings':
                return $this->fix(
                    'aeo',
                    'إعادة صياغة بعض العناوين كأسئلة',
                    'صياغة أقسام رئيسية كأسئلة (زي "إزاي تحجز الرحلة؟") بتخلّي المحتوى مرشّح أقوى للظهور كإجابة مباشرة في نتائج البحث والمساعدات الصوتية.',
                    'content',
                    "أمثلة:\n<h2>إزاي تحجز رحلتك معانا؟</h2>\n<h2>إيه المستندات المطلوبة للسفر؟</h2>\n<h2>هل في إلغاء مجاني للحجز؟</h2>\n\nابدأ كل قسم بإجابة مباشرة ومختصرة في أول جملتين، بعدين وسّع في التفاصيل.",
                    'عناوين H2/H3 في الصفحة'
                );

            case 'llms_txt':
                return $this->fix(
                    'geo',
                    'إنشاء ملف llms.txt',
                    'معيار ناشئ بيوجّه نماذج الذكاء الاصطناعي التوليدي (ChatGPT, Claude, Perplexity..) لأهم صفحات موقعك وملخص واضح عن نشاطك، بشكل يسهّل عليها فهم واستشهاد الموقع.',
                    'content',
                    "# " . ($context['title'] ?: 'اسم الموقع') . "\n\n> وصف مختصر وواضح لنشاط الشركة (سطر أو اتنين).\n\n## أهم الصفحات\n- [الصفحة الرئيسية](https://{$host}/): وصف مختصر\n- [عن الشركة](https://{$host}/about): وصف مختصر\n- [الخدمات](https://{$host}/services): وصف مختصر\n- [تواصل معنا](https://{$host}/contact): وصف مختصر",
                    '/llms.txt (في جذر الدومين)'
                );

            case 'ai_crawler_access':
                if (empty($context['blocked_ai_bots'])) {
                    return null;
                }
                $allowLines = implode("\n\n", array_map(fn ($b) => "User-agent: {$b}\nAllow: /", $context['blocked_ai_bots']));
                return $this->fix(
                    'geo',
                    'السماح لروبوتات الذكاء الاصطناعي بالزحف',
                    'موقعك حاليًا بيمنع الروبوتات دي من قراءة محتواك، يعني مش هيظهر في إجابات ChatGPT أو Claude أو Perplexity. لو عايز تظهر فيهم، عدّل robots.txt:',
                    'config',
                    $allowLines,
                    '/robots.txt (في جذر الدومين)'
                );

            case 'entity_schema':
                $orgName = $suggestedTitle ?? trim((string) ($context['title'] ?? ''));
                $orgName = $orgName !== '' ? mb_substr($orgName, 0, 120) : 'اسم الشركة';
                $orgDesc = $suggestedDescription ?? '';
                if ($orgDesc === '') {
                    $orgDesc = trim((string) ($context['title'] ?? ''));
                }
                $orgDesc = $orgDesc !== '' ? mb_substr(preg_replace('/\s+/', ' ', $orgDesc), 0, 160) : 'وصف مختصر لنشاط الشركة';
                return $this->fix(
                    'geo',
                    'إضافة بيانات الكيان (Organization Schema)',
                    'أهم إشارة بتساعد نماذج الذكاء الاصطناعي تتعرف على هوية موقعك بدقة قبل ما تستشهد بيه في إجاباتها.',
                    'code_snippet',
                    "<script type=\"application/ld+json\">\n{\n  \"@context\": \"https://schema.org\",\n  \"@type\": \"Organization\",\n  \"name\": \"" . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') . "\",\n  \"url\": \"https://{$host}\",\n  \"logo\": \"https://{$host}/logo.png\",\n  \"description\": \"" . htmlspecialchars($orgDesc, ENT_QUOTES, 'UTF-8') . "\",\n  \"contactPoint\": {\n    \"@type\": \"ContactPoint\",\n    \"telephone\": \"+20-XXX-XXX-XXXX\",\n    \"contactType\": \"customer service\"\n  }\n}\n</script>",
                    '<head> أو نهاية <body> في الصفحة الرئيسية'
                );

            case 'author_signal':
                return $this->fix(
                    'geo',
                    'إضافة إشارات مصداقية (Author/E-E-A-T)',
                    'وضّح مين وراء المحتوى - ده بقى عامل مهم في اختيار جوجل ونماذج الذكاء الاصطناعي لمصادرها الموثوقة.',
                    'code_snippet',
                    "<meta name=\"author\" content=\"اسم الكاتب أو الفريق\">\n\n<!-- أو schema كامل -->\n<script type=\"application/ld+json\">\n{\n  \"@context\": \"https://schema.org\",\n  \"@type\": \"Person\",\n  \"name\": \"اسم الكاتب\",\n  \"jobTitle\": \"المسمى الوظيفي\"\n}\n</script>",
                    '<head> في صفحات المحتوى/المقالات'
                );

            case 'freshness_signal':
                return $this->fix(
                    'geo',
                    'إضافة تاريخ آخر تحديث',
                    'محتوى مؤرَّخ بيتفضّل عند جوجل ونماذج الذكاء الاصطناعي، خصوصًا للمواضيع اللي بتتغير زي الأسعار والعروض.',
                    'code_snippet',
                    "<meta property=\"article:modified_time\" content=\"" . date('c') . "\">\n\n<!-- أو ظاهر للزائر -->\n<time datetime=\"" . date('Y-m-d') . "\">آخر تحديث: " . date('Y-m-d') . "</time>",
                    '<head> + مكان ظاهر في الصفحة'
                );

            case 'semantic_html':
                return $this->fix(
                    'geo',
                    'استخدام وسوم HTML5 الدلالية',
                    'بنية واضحة بـ main/article/section/nav بتسهّل جدًا على أدوات الذكاء الاصطناعي تحليل المحتوى واستخلاص الأجزاء المهمة منه بدقة.',
                    'code_snippet',
                    "<body>\n  <header>...</header>\n  <nav>...</nav>\n  <main>\n    <article>\n      <section>المحتوى الرئيسي هنا</section>\n    </article>\n  </main>\n  <footer>...</footer>\n</body>",
                    'هيكل <body> العام للصفحة'
                );

            case 'js_render_risk':
                return $this->fix(
                    'geo',
                    'ضمان توفر محتوى نصي مباشر في HTML',
                    'لو موقعك SPA (React/Vue/Angular) والمحتوى بيتحمّل بجافاسكريبت بس، فعّل Server-Side Rendering أو Static Generation عشان المحتوى يبقى موجود في الـ HTML الخام من أول تحميل - ده بيفرق جدًا مع روبوتات AI اللي غالبًا مش بتنفذ JS.',
                    'config',
                    "// Next.js مثال: استخدم getServerSideProps أو getStaticProps\n// بدل الاعتماد على useEffect لجلب المحتوى بعد التحميل\n\nexport async function getStaticProps() {\n  const data = await fetchContent();\n  return { props: { data } };\n}",
                    'إعدادات الفريمورك (Next.js/Nuxt/إلخ)'
                );

            case 'html_size':
                return $this->fix(
                    'speed',
                    'تقليل حجم HTML',
                    'ضغط الكود (minify) وإزالة الكومنتات والمسافات الزيادة، ونقل الأنماط المتكررة لملفات CSS خارجية بدل inline styles.',
                    'config',
                    "# فعّل الضغط على مستوى السيرفر (Gzip/Brotli)\n# Apache (.htaccess)\n<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE text/html text/css application/javascript\n</IfModule>",
                    '.htaccess أو إعدادات السيرفر'
                );

            case 'external_assets':
                return $this->fix(
                    'speed',
                    'تقليل عدد ملفات JS/CSS الخارجية',
                    'ادمج ملفات CSS/JS المتشابهة في ملف واحد (bundling)، واستخدم defer/async للسكريبتات اللي مش لازمة فورًا.',
                    'code_snippet',
                    "<!-- بدل تحميل عادي -->\n<script src=\"script.js\"></script>\n\n<!-- استخدم defer عشان ميعطلش عرض الصفحة -->\n<script src=\"script.js\" defer></script>",
                    '<head>/<body> - وسوم <script>'
                );

            default:
                return null;
        }
    }

    /**
     * Normalize legacy Website Optimizer fix copy to the active interface language.
     * Older audits stored Arabic presentation copy directly in wo_fixes.  That copy
     * must not leak into an English dashboard.  Arabic accounts keep the original
     * text; English accounts receive deterministic English UI copy, including for
     * already-stored audits (no database rewrite required).
     */
    private function localizeOptimizerFix(array $fix): array
    {
        $lang = strtolower((string) ($this->user['language'] ?? ($_SESSION['language'] ?? 'en')));
        if (str_starts_with($lang, 'ar')) {
            return $fix;
        }

        $key = (string) ($fix['check_key'] ?? '');
        $titles = [
            'title_tag'=>'Fix the page title (Title)', 'meta_description'=>'Fix the meta description',
            'canonical_tag'=>'Add a canonical URL', 'robots_meta'=>'Remove the noindex directive',
            'h1_tag'=>'Fix the H1 heading', 'open_graph'=>'Add complete Open Graph tags',
            'structured_data'=>'Add basic structured data (JSON-LD)', 'html_lang'=>'Set the HTML language attribute',
            'sitemap'=>'Create and link sitemap.xml', 'viewport'=>'Fix the viewport configuration',
            'favicon'=>'Add a favicon', 'https'=>'Enable HTTPS', 'response_time'=>'Improve page response time',
            'heading_structure'=>'Improve heading structure', 'link_check'=>'Fix broken links',
            'hsts'=>'Enable HSTS', 'mixed_content'=>'Fix mixed content', 'image_alt'=>'Add image alt text',
            'faq_schema'=>'Add FAQ structured data', 'question_headings'=>'Use question-based headings',
            'llms_txt'=>'Add llms.txt guidance', 'ai_crawler_access'=>'Allow appropriate AI crawlers',
            'entity_schema'=>'Improve entity structured data', 'author_signal'=>'Strengthen author signals',
            'freshness_signal'=>'Add content freshness signals', 'semantic_html'=>'Improve semantic HTML',
            'js_render_risk'=>'Reduce JavaScript rendering risk', 'html_size'=>'Reduce HTML document size',
            'external_assets'=>'Reduce external JS/CSS requests',
        ];
        if (isset($titles[$key])) {
            $fix['title'] = $titles[$key];
        } elseif ($this->containsArabic((string)($fix['title'] ?? ''))) {
            $fix['title'] = 'Recommended website fix';
        }

        if ($this->containsArabic((string)($fix['description'] ?? ''))) {
            $fix['description'] = 'Apply this recommended change to resolve the detected issue and improve the website audit result. Review the generated code before applying it to production.';
        }
        if ($this->containsArabic((string)($fix['target_file'] ?? ''))) {
            $fix['target_file'] = 'Relevant page or server configuration';
        }
        if ($this->containsArabic((string)($fix['code_snippet'] ?? ''))) {
            // Keep executable markup/config intact while replacing only Arabic runs
            // used in comments, examples and placeholders.
            $fix['code_snippet'] = preg_replace('/[\x{0600}-\x{06FF}][\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\s،؛؟ـًٌٍَُِّْ]+/u', 'English content', (string)$fix['code_snippet']);
        }
        return $fix;
    }

    private function containsArabic(string $value): bool
    {
        return $value !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1;
    }

    private function fix(string $category, string $title, string $description, string $fixType, ?string $codeSnippet, ?string $targetFile): array
    {
        return [
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'fix_type' => $fixType,
            'code_snippet' => $codeSnippet,
            'target_file' => $targetFile,
        ];
    }

    /** التحقق إن الموقع (جدول websites) ملك المستخدم الحالي - بيستخدمه crawl/crawlResults */
    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }
}
