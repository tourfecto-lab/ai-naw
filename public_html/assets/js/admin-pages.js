/**
 * Tourfecto - Admin Pages (الفريق والصلاحيات، سجل التدقيق، استهلاك AI، المهام، الأمان، ملف العميل 360)
 * بيتحمّل بعد admin-pro.js ويضيف AdminPro.renderPage(tab) و AdminPro.openUser(id).
 * ADMIN_PERMS (من السيرفر) بتستخدم لإخفاء/تعطيل الأزرار؛ الحماية الفعلية على السيرفر.
 */
(function () {
    'use strict';
    if (!window.AdminPro) return;

    const P = window.Panel || {};
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
    const esc = P.esc || (v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
    const can = p => (window.ADMIN_PERMS || []).indexOf(p) !== -1;
    const toast = (m, t) => (P.toast ? P.toast(m, t) : alert(m));
    const NF = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });

    // ------------------------------------------------------------------ شبكة
    async function api(url, method, body) {
        const opt = { method: method || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
        try {
            const res = await fetch(url, opt);
            let j; try { j = await res.json(); } catch (e) { j = { success: false, error: 'استجابة غير صالحة من الخادم (' + res.status + ')' }; }
            if (res.status === 403 && !j.error) j.error = 'ليس لديك صلاحية لهذا الإجراء';
            return j;
        } catch (e) { return { success: false, error: 'تعذّر الاتصال بالخادم' }; }
    }
    async function act(url, body, okMsg, after) {
        const r = await api(url, 'POST', body || {});
        if (r.success) { toast(r.message || okMsg || 'تم', 'success'); if (after) await after(r); } else toast(r.error || 'حدث خطأ', 'error');
        return r;
    }

    // ------------------------------------------------------------------ أدوات عرض
    function ago(s) { return window.AdminPro.ago ? window.AdminPro.ago(s) : (s || ''); }
    const dt = s => s ? String(s).replace('T', ' ').slice(0, 16) : '—';
    const money = v => '$' + (Math.abs(v) < 1 && v !== 0 ? Number(v).toFixed(4) : NF.format(v));
    const pill = (txt, cls) => '<span class="pill ' + (cls || 'gray') + '">' + esc(txt) + '</span>';
    const skeleton = (n) => '<div class="ap-skel" style="height:' + (n || 120) + 'px"></div>';
    const failedBox = (msg) => '<div class="ap-empty">' + esc(msg || 'تعذّر تحميل البيانات') + ' · <a href="#" data-reload>إعادة المحاولة</a></div>';
    function head(title, sub, actions) {
        return '<div class="ap-bar"><div>' + (sub ? '<div class="ap-sub" style="max-width:720px">' + esc(sub) + '</div>' : '<span class="ap-sr">' + esc(title) + '</span>') + '</div><span class="ap-spacer"></span>' + (actions || '') + '</div>';
    }
    function table(cols, rows, empty) {
        if (!rows.length) return '<div class="ap-empty" style="padding:22px">' + esc(empty || 'لا توجد بيانات') + '</div>';
        return '<div class="p-table-scroll"><table class="p-table"><thead><tr>' + cols.map(c => '<th>' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
    }
    function stat(label, value, tone, hint, isMoney) {
        return '<div class="ap-mini"><div class="ap-mini-label">' + esc(label) + '</div><div class="ap-mini-row"><span class="ap-mini-value' + (tone ? ' ' + tone : '') + (isMoney ? ' is-money' : '') + '">' + esc(value) + '</span></div>' + (hint ? '<div class="ap-sub">' + esc(hint) + '</div>' : '') + '</div>';
    }
    function pager(page, per, total, key) {
        const pages = Math.max(1, Math.ceil(total / per));
        if (pages <= 1) return '';
        return '<div class="ap-pager"><span>صفحة ' + page + ' / ' + pages + ' · ' + total + ' سجل</span><div class="btns"><button type="button" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>السابق</button><button type="button" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '>التالي</button></div></div>';
    }
    function confirmBox(msg) { return window.confirm(msg); }

    // ================================================================== الفريق والصلاحيات
    const GROUP_LABEL = { analytics: 'الإحصائيات', users: 'العملاء', billing: 'الفوترة', support: 'الدعم', security: 'الأمان', system: 'النظام', team: 'الفريق' };

    async function pageTeam(root) {
        root.innerHTML = skeleton(220);
        const r = await api('/api/admin/ops/team');
        if (!r.success) { root.innerHTML = failedBox(r.error); return; }
        const d = r.data, roleLabel = k => (d.roles.find(x => x.key === k) || {}).label || k;
        const roleOpts = sel => d.roles.map(x => '<option value="' + x.key + '"' + (x.key === sel ? ' selected' : '') + '>' + esc(x.label) + '</option>').join('');
        const rows = d.members.map(m => '<tr data-id="' + m.id + '">' +
            '<td><span class="p-avatar-sm">' + esc((m.name || '?').slice(0, 2).toUpperCase()) + '</span><strong>' + esc(m.name) + '</strong>' + (m.is_self ? ' ' + pill('أنت', 'blue') : '') + '<br><span class="p-cell-muted">' + esc(m.email) + '</span></td>' +
            '<td>' + (d.can_manage && !m.is_self ? '<select class="p-select" data-role>' + roleOpts(m.staff_role) + '</select>' : pill(roleLabel(m.staff_role), m.staff_role === 'owner' ? 'purple' : 'blue')) + (m.extra_permissions.length ? '<div class="ap-sub">+ ' + m.extra_permissions.length + ' صلاحية إضافية</div>' : '') + '</td>' +
            '<td>' + (m.two_factor ? pill('مفعّل', 'green') : pill('غير مفعّل', 'red')) + '</td>' +
            '<td class="p-cell-muted" data-sort="' + esc(m.last_login_at || '') + '">' + (m.last_login_at ? esc(ago(m.last_login_at)) : '—') + '</td>' +
            '<td>' + (d.can_manage && !m.is_self ? '<button class="p-btn danger xs" data-remove>إزالة من الفريق</button>' : '') + '</td></tr>');

        const matrix = (() => {
            const groups = {};
            d.catalog.forEach(p => { (groups[p.group] = groups[p.group] || []).push(p); });
            const head = '<tr><th>الصلاحية</th>' + d.roles.map(x => '<th class="ap-c">' + esc(x.label.split(' ')[0]) + '</th>').join('') + '</tr>';
            const body = Object.keys(groups).map(g => '<tr class="ap-grp"><td colspan="' + (d.roles.length + 1) + '">' + esc(GROUP_LABEL[g] || g) + '</td></tr>' +
                groups[g].map(p => '<tr><td>' + esc(p.label) + '</td>' + d.roles.map(x => '<td class="ap-c">' + (x.permissions.indexOf(p.key) !== -1 ? '<span class="ap-yes">●</span>' : '<span class="ap-no">–</span>') + '</td>').join('') + '</tr>').join('')).join('');
            return '<div class="p-table-scroll"><table class="p-table ap-matrix"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
        })();

        root.innerHTML = head('الفريق والصلاحيات', 'كل عضو بيشوف ويعمل بس اللي دوره يسمح بيه. كل تغيير بيتسجّل في سجل التدقيق.', d.can_manage ? '<button class="p-btn primary" id="apInvite">+ إضافة عضو</button>' : '') +
            (!d.staff_table_ready ? '<div class="ap-note"><b>مطلوب خطوة واحدة:</b> شغّل ملف <span class="ap-mono">TOURFECTO_ADMIN_PRO.sql</span> على قاعدة البيانات عشان تقدر تضيف أعضاء وتغيّر أدوارهم. لحد ما تشغّله، كل الأدمن الحاليين بيشتغلوا بصلاحيات "مدير" والمالك (super_admin) بكل الصلاحيات.</div>' : '') +
            '<section class="ap-panel"><div class="ap-panel-head"><h3>أعضاء الفريق (' + d.members.length + ')</h3></div>' + '<div class="ap-panel-body" style="padding:0">' + table(['العضو', 'الدور', 'التحقق بخطوتين', 'آخر دخول', ''], rows) + '</div></section>' +
            '<section class="ap-panel" style="margin-top:14px"><div class="ap-panel-head"><h3>مصفوفة الصلاحيات</h3><span class="ap-sub">ما يقدر يعمله كل دور</span></div><div class="ap-panel-body" style="padding:0">' + matrix + '</div></section>';

        root.addEventListener('change', async e => {
            const sel = e.target.closest('select[data-role]'); if (!sel) return;
            const id = sel.closest('tr').dataset.id;
            if (!confirmBox('تغيير الدور هيُنهي جلسات العضو الحالية. متأكد؟')) { pageTeam(root); return; }
            await act('/api/admin/ops/team/' + id + '/role', { staff_role: sel.value }, 'تم تحديث الدور', () => pageTeam(root));
        });
        root.addEventListener('click', async e => {
            const rm = e.target.closest('[data-remove]');
            if (rm) { const tr = rm.closest('tr'); if (confirmBox('إزالة هذا العضو من فريق الأدمن؟ هيرجع لحساب عميل عادي وهتنتهي جلساته.')) await act('/api/admin/ops/team/' + tr.dataset.id + '/remove', {}, 'تمت الإزالة', () => pageTeam(root)); return; }
            if (e.target.closest('#apInvite')) openInvite(d, () => pageTeam(root));
            if (e.target.closest('[data-reload]')) { e.preventDefault(); pageTeam(root); }
        });
    }

    function openInvite(d, done) {
        const bg = document.createElement('div'); bg.className = 'ap-modal-bg';
        bg.innerHTML = '<div class="ap-modal" role="dialog" aria-modal="true"><h3>إضافة عضو لفريق الأدمن</h3><p class="ap-sub">المستخدم لازم يكون مسجّل في المنصة بالفعل. هيتحوّل لحساب أدمن بالدور اللي تختاره.</p>' +
            '<label class="ap-lbl">البريد الإلكتروني</label><input type="email" class="p-input" id="invEmail" placeholder="name@company.com" autocomplete="off">' +
            '<label class="ap-lbl">الدور</label><select class="p-select" id="invRole">' + d.roles.filter(r => r.key !== 'owner').map(r => '<option value="' + r.key + '"' + (r.key === 'support' ? ' selected' : '') + '>' + esc(r.label) + '</option>').join('') + '<option value="owner">مالك (كل الصلاحيات)</option></select>' +
            '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>إضافة</button></div></div>';
        document.body.appendChild(bg);
        const close = () => bg.remove();
        bg.addEventListener('mousedown', e => { if (e.target === bg) close(); });
        bg.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        $('[data-x]', bg).onclick = close;
        $('#invEmail', bg).focus();
        $('[data-ok]', bg).onclick = async () => {
            const role = $('#invRole', bg).value;
            if (role === 'owner' && !confirmBox('المالك له كل الصلاحيات بما فيها إدارة الفريق. متأكد؟')) return;
            const r = await act('/api/admin/ops/team/invite', { email: $('#invEmail', bg).value, staff_role: role }, 'تمت الإضافة', done);
            if (r.success) close();
        };
    }

    // ================================================================== سجل التدقيق
    async function pageAudit(root) {
        const st = { page: 1, q: '', result: '', actor: '', from: '', to: '' };
        root.innerHTML = head('سجل التدقيق', 'كل إجراء تعديل تم من لوحة الأدمن: مين نفّذه ومتى ونجح ولا فشل (بدون تسجيل قيم البيانات، أسماء الحقول بس).', '<a class="p-btn outline" id="apAuditExport" href="#">تصدير CSV</a>') +
            '<section class="ap-panel"><div class="ap-tools" id="apAuditTools"></div><div id="apAuditBody">' + skeleton(200) + '</div></section>';
        const qs = () => Object.keys(st).filter(k => st[k] !== '' && k !== 'page').map(k => k + '=' + encodeURIComponent(st[k])).join('&');
        async function load() {
            const r = await api('/api/admin/ops/audit?per=30&page=' + st.page + '&' + qs());
            const body = $('#apAuditBody', root);
            if (!r.success) { body.innerHTML = failedBox(r.error); return; }
            const d = r.data;
            if (!$('#apAuditTools input', root)) {
                $('#apAuditTools', root).innerHTML = '<input type="search" id="auQ" placeholder="بحث في الإجراء أو الكائن…">' +
                    '<select class="p-select" id="auRes"><option value="">كل النتائج</option><option value="success">نجح</option><option value="failed">فشل</option></select>' +
                    '<select class="p-select" id="auActor"><option value="">كل المنفّذين</option>' + d.actors.map(a => '<option value="' + a.id + '">' + esc(a.name || ('#' + a.id)) + '</option>').join('') + '</select>' +
                    '<input type="date" id="auFrom" title="من"><input type="date" id="auTo" title="إلى">';
            }
            body.innerHTML = table(['الوقت', 'المنفّذ', 'الإجراء', 'الكائن', 'النتيجة', 'IP'], d.items.map(i => '<tr><td class="p-cell-muted" title="' + esc(i.created_at) + '">' + esc(ago(i.created_at)) + '</td><td>' + esc(i.actor_name || ('#' + i.user_id)) + '</td><td><span class="ap-mono">' + esc(i.action) + '</span></td><td class="p-cell-muted">' + esc((i.object_type || '') + (i.object_id ? ' #' + i.object_id : '')) + '</td><td>' + (i.result === 'success' ? pill('نجح', 'green') : pill('فشل', 'red')) + '</td><td class="p-cell-muted ap-mono">' + esc(i.ip_address || '') + '</td></tr>'), 'لا توجد سجلات مطابقة. السجلات بتتسجّل تلقائيًا من أول إجراء تعديل بعد التحديث.') + pager(d.page, d.per, d.total);
        }
        let t;
        root.addEventListener('input', e => { const id = e.target.id; if (id === 'auQ') { clearTimeout(t); t = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 300); } });
        root.addEventListener('change', e => { const m = { auRes: 'result', auActor: 'actor', auFrom: 'from', auTo: 'to' }[e.target.id]; if (m) { st[m] = e.target.value; st.page = 1; load(); } });
        root.addEventListener('click', e => {
            const b = e.target.closest('.ap-pager [data-page]'); if (b && !b.disabled) { st.page = +b.dataset.page; load(); }
            if (e.target.closest('#apAuditExport')) { e.preventDefault(); location.href = '/api/admin/ops/audit/export?' + qs(); }
            if (e.target.closest('[data-reload]')) { e.preventDefault(); load(); }
        });
        await load();
    }

    // ================================================================== استهلاك AI
    async function pageAi(root) {
        let days = 30;
        async function load() {
            root.innerHTML = head('استهلاك الذكاء الاصطناعي', 'تكلفة وطلبات Gemini وغيره حسب الميزة والعميل والموديل.', '<div class="ap-seg">' + [7, 30, 90].map(d => '<button type="button" data-days="' + d + '" aria-pressed="' + (d === days) + '">' + d + ' يوم</button>').join('') + '</div>') + skeleton(260);
            const [r, rt] = await Promise.all([api('/api/admin/ops/ai-usage?days=' + days), api('/api/admin/ops/ai-routing')]);
            if (!r.success) { root.innerHTML = head('استهلاك الذكاء الاصطناعي') + failedBox(r.error); return; }
            const d = r.data, t = d.totals, maxC = Math.max.apply(null, d.series.map(x => x.cost).concat([0.000001]));
            const bars = d.series.map(x => '<i title="' + esc(x.d + ': ' + money(x.cost) + ' · ' + x.calls + ' طلب') + '" style="height:' + Math.max(2, Math.round(100 * x.cost / maxC)) + '%"></i>').join('');
            const list = (rows, label, val) => rows.length ? '<div class="ap-list">' + rows.map(x => '<div class="ap-row"><span class="name" title="' + esc(label(x)) + '">' + esc(label(x)) + '</span><span class="val">' + esc(val(x)) + '</span><span class="bar"><i style="width:' + Math.max(3, Math.round(100 * (+x.cost || 0) / (+rows[0].cost || 1))) + '%"></i></span></div>').join('') + '</div>' : '<div class="ap-empty">لا توجد بيانات</div>';
            root.innerHTML = head('استهلاك الذكاء الاصطناعي', 'تكلفة وطلبات Gemini وغيره حسب الميزة والعميل والموديل.', '<div class="ap-seg">' + [7, 30, 90].map(x => '<button type="button" data-days="' + x + '" aria-pressed="' + (x === days) + '">' + x + ' يوم</button>').join('') + '</div>') +
                '<section class="ap-panel ap-minis" style="grid-template-columns:repeat(5,minmax(0,1fr))">' + stat('إجمالي التكلفة', money(t.cost)) + stat('عدد الطلبات', NF.format(t.calls)) + stat('متوسط تكلفة الطلب', money(t.cost_per_call)) + stat('نسبة الأخطاء', t.error_rate + '%', t.error_rate > 10 ? 'bad' : '', t.errors + ' طلب فاشل') + stat('متوسط زمن الرد', NF.format(t.avg_ms) + ' ms') + '</section>' +
                '<section class="ap-panel" style="margin-top:14px"><div class="ap-panel-head"><h3>التكلفة اليومية</h3><span class="ap-sub">' + money(t.cost) + ' في ' + days + ' يوم</span></div><div class="ap-panel-body"><div class="ap-bars" dir="ltr">' + bars + '</div></div></section>' +
                '<div class="ap-grid halves"><section class="ap-panel"><div class="ap-panel-head"><h3>حسب الميزة</h3></div><div class="ap-panel-body">' + list(d.by_feature, x => x.feature || '—', x => NF.format(x.calls) + ' طلب · ' + money(+x.cost)) + '</div></section>' +
                '<section class="ap-panel"><div class="ap-panel-head"><h3>أكتر العملاء استهلاكًا</h3></div><div class="ap-panel-body">' + list(d.top_users, x => x.user_id ? (x.name || x.email || ('#' + x.user_id)) : 'استدعاءات النظام (بدون عميل)', x => NF.format(x.calls) + ' طلب · ' + money(+x.cost)) + '</div></section></div>' +
                (() => {
                    const PN = { gemini: 'Gemini (جوجل)', anthropic: 'Claude (Anthropic)', openai: 'OpenAI', deepseek: 'DeepSeek', kimi: 'Kimi', other: 'أخرى' };
                    const prov = (d.by_provider || []);
                    const provHtml = prov.length ? '<div class="ap-list">' + prov.map(x => '<div class="ap-row"><span class="name">' + esc(PN[x.provider] || x.provider) + '</span><span class="val">' + NF.format(x.calls) + ' طلب · ' + money(+x.cost) + ' · ' + NF.format(Math.round(x.avg_ms / 100) / 10) + ' ث</span><span class="bar"><i style="width:' + Math.max(3, Math.round(100 * (+x.cost || 0) / (+prov[0].cost || 1))) + '%"></i></span></div>').join('') + '</div>' : '<div class="ap-empty">لا توجد بيانات</div>';
                    let route = '<div class="ap-empty">تعذّر تحميل التوزيع</div>';
                    if (rt && rt.success) {
                        const R = rt.data, chip = (n, i) => '<span class="pill ' + (i === 0 ? 'blue' : 'gray') + '">' + esc((PN[n] || n).split(' ')[0]) + '</span>';
                        route = '<div class="p-table-scroll"><table class="p-table"><thead><tr><th>المهمة</th><th>الترتيب (الأساسي ثم البدائل)</th></tr></thead><tbody>' +
                            Object.keys(R.tasks).map(k => '<tr><td><span class="ap-mono">' + esc(k) + '</span></td><td>' + R.tasks[k].map((n, i) => chip(n, i)).join(' ← ') + '</td></tr>').join('') + '</tbody></table></div>' +
                            '<p class="ap-sub" style="padding:10px 14px 0">المزودين المتظبّطين: ' + Object.keys(R.providers).filter(k => R.providers[k].configured).map(k => esc(PN[k] || k)).join('، ') + (R.cn_allowed ? '' : ' · DeepSeek وKimi مقفولين (بيانات العملاء)') + '.</p>' +
                            '<p class="ap-sub" style="padding:4px 14px 12px">التغيير من <span class="ap-mono">AI_TASK_ROUTES</span> في ملف .env.</p>';
                    }
                    return '<div class="ap-grid halves"><section class="ap-panel"><div class="ap-panel-head"><h3>حسب المزود</h3><span class="ap-sub">قارن التكلفة والسرعة</span></div><div class="ap-panel-body">' + provHtml + '</div></section>' +
                        '<section class="ap-panel"><div class="ap-panel-head"><h3>توزيع المهام الحالي</h3></div><div class="ap-panel-body" style="padding:0">' + route + '</div></section></div>';
                })() +
                '<div class="ap-grid halves"><section class="ap-panel"><div class="ap-panel-head"><h3>حسب الموديل</h3></div><div class="ap-panel-body">' + list(d.by_model, x => x.model === 'unknown' ? 'غير محدّد' : x.model, x => NF.format(x.tokens) + ' توكن · ' + money(+x.cost)) + '</div></section>' +
                '<section class="ap-panel"><div class="ap-panel-head"><h3>آخر الأخطاء</h3></div><div class="ap-panel-body" style="padding:0">' + table(['الميزة', 'الموديل', 'الكود', 'الوقت'], d.recent_errors.map(x => '<tr><td>' + esc(x.feature) + '</td><td class="ap-mono">' + esc(x.model || '') + '</td><td>' + pill(String(x.status_code), 'red') + '</td><td class="p-cell-muted">' + esc(ago(x.created_at)) + '</td></tr>'), 'مفيش أخطاء ✓') + '</div></section></div>';
        }
        root.addEventListener('click', e => { const b = e.target.closest('[data-days]'); if (b) { days = +b.dataset.days; load(); } if (e.target.closest('[data-reload]')) { e.preventDefault(); load(); } });
        await load();
    }

    // ================================================================== المهام الخلفية
    async function pageJobs(root) {
        const st = { status: 'failed', page: 1 };
        async function load() {
            const r = await api('/api/admin/ops/jobs?status=' + st.status + '&page=' + st.page);
            if (!r.success) { root.innerHTML = head('المهام الخلفية') + failedBox(r.error); return; }
            const d = r.data, c = d.counts, manage = can('system.manage');
            const tab = (k, l, cls) => '<button type="button" data-status="' + k + '" aria-pressed="' + (st.status === k) + '">' + l + ' <b class="' + (cls || '') + '">' + c[k] + '</b></button>';
            root.innerHTML = head('المهام الخلفية', 'الطابور اللي بيشغّل الردود التلقائية والتقارير والحملات.', manage && c.failed ? '<button class="p-btn primary" id="apRetryAll">إعادة كل الفاشل (آخر 24 ساعة)</button>' : '') +
                (d.stuck ? '<div class="ap-note"><b>' + d.stuck + ' مهمة عالقة</b> في حالة "قيد التنفيذ" أكتر من 30 دقيقة. غالبًا عامل الطابور (cron) واقف.</div>' : '') +
                '<div class="ap-bar"><div class="ap-seg" role="group">' + tab('failed', 'فاشلة', c.failed ? 'bad' : '') + tab('pending', 'منتظرة') + tab('processing', 'قيد التنفيذ') + tab('completed', 'مكتملة') + '</div></div>' +
                '<section class="ap-panel">' + table(['#', 'المهمة', 'الطابور', 'المحاولات', 'آخر خطأ', 'الوقت', ''], d.items.map(j => '<tr data-id="' + j.id + '"><td class="p-cell-muted">' + j.id + '</td><td><span class="ap-mono">' + esc(j.job_class) + '</span></td><td>' + esc(j.queue) + '</td><td>' + j.attempts + '</td><td class="ap-err">' + esc(j.last_error || '') + '</td><td class="p-cell-muted">' + esc(ago(j.created_at)) + '</td><td>' + (manage && j.status === 'failed' ? '<button class="p-btn outline xs" data-retry>إعادة</button>' : (manage && j.status === 'pending' ? '<button class="p-btn danger xs" data-cancel>إلغاء</button>' : '')) + '</td></tr>'), 'مفيش مهام في الحالة دي ✓') + pager(d.page, d.per, d.total) + '</section>';
        }
        root.addEventListener('click', async e => {
            const s = e.target.closest('[data-status]'); if (s) { st.status = s.dataset.status; st.page = 1; return load(); }
            const pg = e.target.closest('.ap-pager [data-page]'); if (pg && !pg.disabled) { st.page = +pg.dataset.page; return load(); }
            const rt = e.target.closest('[data-retry]'); if (rt) return act('/api/admin/ops/jobs/' + rt.closest('tr').dataset.id + '/retry', {}, 'تمت الإعادة', load);
            const cn = e.target.closest('[data-cancel]'); if (cn) { if (confirmBox('إلغاء المهمة المنتظرة؟')) await act('/api/admin/ops/jobs/' + cn.closest('tr').dataset.id + '/cancel', {}, 'تم الإلغاء', load); return; }
            if (e.target.closest('#apRetryAll')) { if (confirmBox('إعادة كل المهام الفاشلة في آخر 24 ساعة؟')) await act('/api/admin/ops/jobs/retry-failed', {}, 'تمت الإعادة', load); return; }
            if (e.target.closest('[data-reload]')) { e.preventDefault(); load(); }
        });
        await load();
    }

    // ================================================================== مركز الأمان
    async function pageSecurity(root) {
        async function load() {
            root.innerHTML = head('مركز الأمان', 'محاولات الدخول المشبوهة والحظر الحالي وأمان حسابات الأدمن.') + skeleton(240);
            const r = await api('/api/admin/ops/security');
            if (!r.success) { root.innerHTML = head('مركز الأمان') + failedBox(r.error); return; }
            const d = r.data, s = d.summary, manage = can('security.manage');
            root.innerHTML = head('مركز الأمان', 'محاولات الدخول المشبوهة والحظر الحالي وأمان حسابات الأدمن.') +
                '<section class="ap-panel ap-minis" style="grid-template-columns:repeat(6,minmax(0,1fr))">' + stat('دخول فاشل (24 ساعة)', s.failed_24h, s.failed_24h > 20 ? 'bad' : '') + stat('دخول فاشل (7 أيام)', s.failed_7d) + stat('دخول ناجح (24 ساعة)', s.success_24h) + stat('حظر نشط', s.active_blocks) + stat('أدمن بدون 2FA', s.admins_no_2fa, s.admins_no_2fa ? 'bad' : '') + stat('حسابات موقوفة', s.suspended_users) + '</section>' +
                '<div class="ap-grid halves"><section class="ap-panel"><div class="ap-panel-head"><h3>عناوين IP مشبوهة</h3><span class="ap-sub">3 محاولات فاشلة أو أكتر في 7 أيام</span></div><div class="ap-panel-body" style="padding:0">' +
                table(['IP', 'المحاولات', 'إيميلات', 'الدولة', 'آخر ظهور'], d.ips.map(i => '<tr><td class="ap-mono">' + esc(i.ip_address) + '</td><td>' + pill(String(i.attempts), i.attempts >= 10 ? 'red' : 'orange') + '</td><td>' + i.emails + '</td><td>' + esc(i.country || '—') + '</td><td class="p-cell-muted">' + esc(ago(i.last_seen)) + '</td></tr>'), 'مفيش نشاط مشبوه ✓') + '</div></section>' +
                '<section class="ap-panel"><div class="ap-panel-head"><h3>الحظر النشط (Rate limit)</h3></div><div class="ap-panel-body" style="padding:0">' +
                table(['المعرّف', 'السبب', 'ينتهي', ''], d.blocks.map(b => '<tr data-id="' + b.id + '"><td class="ap-mono">' + esc(b.identifier) + '</td><td class="p-cell-muted">' + esc(b.reason) + '</td><td class="p-cell-muted">' + esc(dt(b.expires_at)) + '</td><td>' + (manage ? '<button class="p-btn outline xs" data-unblock>فك الحظر</button>' : '') + '</td></tr>'), 'مفيش حظر نشط') + '</div></section></div>' +
                '<div class="ap-grid halves"><section class="ap-panel"><div class="ap-panel-head"><h3>حسابات الأدمن</h3></div><div class="ap-panel-body" style="padding:0">' +
                table(['الحساب', '2FA', 'آخر دخول', ''], d.admins.map(a => '<tr data-uid="' + a.id + '"><td>' + esc(a.email) + '</td><td>' + (+a.two_factor_enabled ? pill('مفعّل', 'green') : pill('غير مفعّل', 'red')) + '</td><td class="p-cell-muted">' + (a.last_login_at ? esc(ago(a.last_login_at)) : '—') + '</td><td>' + (manage ? '<button class="p-btn outline xs" data-logout>إنهاء الجلسات</button>' : '') + '</td></tr>'), '') + '</div></section>' +
                '<section class="ap-panel"><div class="ap-panel-head"><h3>آخر عمليات "الدخول بحساب العميل"</h3></div><div class="ap-panel-body" style="padding:0">' +
                table(['الأدمن', 'العميل', 'السبب', 'الوقت'], d.impersonations.map(i => '<tr><td>' + esc(i.admin_email || ('#' + i.admin_id)) + '</td><td>' + esc(i.target_email || ('#' + i.target_user_id)) + '</td><td class="p-cell-muted">' + esc(i.reason || '—') + '</td><td class="p-cell-muted">' + esc(ago(i.started_at)) + '</td></tr>'), 'لم يتم استخدامها') + '</div></section></div>';
        }
        root.addEventListener('click', async e => {
            const ub = e.target.closest('[data-unblock]'); if (ub) return act('/api/admin/ops/security/unblock', { id: +ub.closest('tr').dataset.id }, 'تم فك الحظر', load);
            const lo = e.target.closest('[data-logout]'); if (lo) { if (confirmBox('إنهاء كل جلسات هذا الحساب؟')) await act('/api/admin/ops/security/force-logout', { user_id: +lo.closest('tr').dataset.uid }, 'تم', load); return; }
            if (e.target.closest('[data-reload]')) { e.preventDefault(); load(); }
        });
        await load();
    }

    // ================================================================== ملف العميل 360
    const FLAG = { unverified: ['البريد غير مؤكَّد', 'orange'], pending_deposit: ['إيداع منتظر', 'orange'], suspended: ['موقوف', 'red'], admin_no_2fa: ['أدمن بدون 2FA', 'red'] };

    async function openUser(id) {
        closeUser();
        const bg = document.createElement('div'); bg.className = 'ap-drawer-bg'; bg.id = 'apUserDrawer';
        bg.innerHTML = '<aside class="ap-drawer" role="dialog" aria-modal="true" aria-label="ملف العميل"><div class="ap-drawer-body">' + skeleton(300) + '</div></aside>';
        document.body.appendChild(bg);
        bg.addEventListener('mousedown', e => { if (e.target === bg) closeUser(); });
        document.addEventListener('keydown', escClose);
        await render(id);

        async function render(uid) {
            const body = $('.ap-drawer-body', bg);
            const r = await api('/api/admin/pro/user/' + uid);
            if (!r.success) { body.innerHTML = '<button class="ap-x" data-close aria-label="إغلاق">×</button>' + failedBox(r.error); return; }
            const d = r.data, u = d.user, c = d.can, name = u.company_name || u.display_name || u.email;
            body.innerHTML = '<button class="ap-x" data-close aria-label="إغلاق">×</button>' +
                '<div class="ap-uhead"><span class="p-avatar-sm lg">' + esc((name || '?').slice(0, 2).toUpperCase()) + '</span><div><h3>' + esc(name) + '</h3><div class="ap-sub">' + esc(u.email) + (u.phone ? ' · ' + esc(u.phone) : '') + '</div></div></div>' +
                '<div class="ap-flags">' + (u.is_active ? pill('نشط', 'green') : pill('موقوف', 'red')) + pill(u.role, 'gray') + (+u.two_factor_enabled ? pill('2FA', 'blue') : '') + d.flags.filter(f => f !== 'suspended').map(f => pill((FLAG[f] || [f])[0], (FLAG[f] || [0, 'gray'])[1])).join('') + '</div>' +
                '<div class="ap-ustats">' + stat('رصيد المحفظة', money(d.wallet.balance), '', d.wallet.transactions + ' عملية', true) + stat('المواقع', d.websites.length) + stat('استهلاك AI (30 يوم)', money(+d.ai_30d.cost || 0), '', (d.ai_30d.calls || 0) + ' طلب') + '</div>' +
                '<div class="ap-uactions">' +
                (c.suspend ? '<button class="p-btn ' + (u.is_active ? 'danger' : 'success') + ' xs" data-toggle>' + (u.is_active ? 'إيقاف الحساب' : 'تفعيل الحساب') + '</button>' : '') +
                (c.security ? '<button class="p-btn outline xs" data-logout>إنهاء كل الجلسات</button>' : '') +
                (c.impersonate && typeof window.impersonate === 'function' ? '<button class="p-btn outline xs" data-imp>دخول بحسابه</button>' : '') +
'</div>' +
                // الرصيد والمنحة جوه النافذة نفسها (الزرار القديم كان بيفتح نفس النافذة تاني في دايرة)
                (c.balance ? sec('💰 الرصيد وفتح الخدمات',
                    '<div class="ap-money">' +
                    '<div class="ap-mbox"><b>رصيد المحفظة</b><div class="ap-sub">يتضاف أو يتسحب من محفظة العميل فورًا، ويتسجّل في سجل العمليات.</div>' +
                    '<div class="ap-mrow"><input type="number" id="apBalAmt" min="0.01" step="0.01" placeholder="المبلغ $" dir="ltr"><input type="text" id="apBalNote" maxlength="190" placeholder="سبب (يظهر في سجل العميل)"></div>' +
                    '<div class="ap-mrow"><button class="p-btn primary xs" data-baladd>➕ إضافة رصيد</button><button class="p-btn outline xs" data-baldeduct>➖ سحب رصيد</button></div></div>' +
                    '<div class="ap-mbox" id="apGrant"><b>فتح كل الخدمات مجانًا</b><div class="ap-sub">…</div></div>' +
                    '</div>') : '') +
                sec('الاشتراكات', table(['الباقة', 'الحالة', 'ينتهي'], d.subscriptions.map(s => '<tr><td>' + esc(s.plan_name || '—') + '</td><td>' + pill(s.status, s.status === 'active' ? 'green' : 'gray') + '</td><td class="p-cell-muted">' + esc(dt(s.current_period_end)) + '</td></tr>'), 'مفيش اشتراكات')) +
                sec('المواقع', table(['الموقع', 'ربط SEO'], d.websites.map(w => '<tr><td>' + esc(w.name || w.main_url || '—') + '<br><span class="p-cell-muted ap-mono">' + esc(w.main_url || '') + '</span></td><td>' + (+w.is_connected ? pill('مربوط', 'blue') : pill('—', 'gray')) + '</td></tr>'), 'مفيش مواقع')) +
                sec('آخر عمليات الدخول', table(['الحالة', 'IP', 'المكان', 'الجهاز', 'الوقت'], d.logins.map(l => '<tr><td>' + (l.status === 'success' ? pill('نجح', 'green') : pill('فشل', 'red')) + (+l.is_impersonation ? ' ' + pill('انتحال', 'orange') : '') + '</td><td class="ap-mono">' + esc(l.ip_address || '') + '</td><td>' + esc([l.city, l.country].filter(Boolean).join('، ') || '—') + '</td><td class="p-cell-muted">' + esc([l.browser, l.platform].filter(Boolean).join(' · ')) + '</td><td class="p-cell-muted">' + esc(ago(l.created_at)) + '</td></tr>'), 'لا يوجد')) +
                sec('ملاحظات داخلية', (c.notes ? '<div class="ap-noteadd"><textarea id="apNote" rows="2" maxlength="2000" placeholder="اكتب ملاحظة يشوفها فريق الأدمن بس…"></textarea><div><label class="ap-chk"><input type="checkbox" id="apNotePin"> تثبيت</label><button class="p-btn primary xs" data-note>إضافة</button></div></div>' : '') +
                    (d.notes.length ? d.notes.map(n => '<div class="ap-note-item' + (+n.is_pinned ? ' pinned' : '') + '"><div>' + esc(n.note).replace(/\n/g, '<br>') + '</div><div class="ap-sub">' + esc(n.author || '') + ' · ' + esc(ago(n.created_at)) + (c.notes ? ' · <a href="#" data-delnote="' + n.id + '">حذف</a>' : '') + '</div></div>').join('') : '<div class="ap-empty">لا توجد ملاحظات</div>')) +
                sec('سجل الإجراءات على الحساب', d.timeline.length ? d.timeline.map(t => '<div class="ap-tl"><span class="ap-mono">' + esc(t.action) + '</span> <span class="ap-sub">' + esc(t.actor || '') + ' · ' + esc(ago(t.created_at)) + '</span></div>').join('') : '<div class="ap-empty">لا توجد إجراءات مسجّلة</div>');

            if (c.balance) loadGrant(uid, body);
            // إضافات من ملفات تانية (صحة العميل، التاجات ...)
            try { document.dispatchEvent(new CustomEvent('ap:user-rendered', { detail: { uid: uid, body: body } })); } catch (e2) { }

            body.onclick = async e => {
                if (e.target.closest('[data-close]')) return closeUser();
                if (e.target.closest('[data-toggle]')) { if (!confirmBox(u.is_active ? 'إيقاف هذا الحساب؟' : 'تفعيل هذا الحساب؟')) return; return act('/api/admin/users/' + uid + '/' + (u.is_active ? 'suspend' : 'activate'), {}, 'تم', () => render(uid)); }
                if (e.target.closest('[data-logout]')) { if (confirmBox('إنهاء كل جلسات العميل؟')) await act('/api/admin/ops/security/force-logout', { user_id: uid }, 'تم', () => render(uid)); return; }
                if (e.target.closest('[data-imp]')) { closeUser(); return window.impersonate(uid); }
                const bal = e.target.closest('[data-baladd],[data-baldeduct]');
                if (bal) {
                    const add = bal.hasAttribute('data-baladd'), amount = parseFloat($('#apBalAmt', body).value), note = $('#apBalNote', body).value.trim();
                    if (!(amount > 0)) return toast('اكتب مبلغ صحيح', 'error');
                    if (!confirmBox((add ? 'إضافة ' : 'سحب ') + money(amount) + (add ? ' لمحفظة ' : ' من محفظة ') + name + '؟')) return;
                    return act('/api/admin/users/' + uid + '/' + (add ? 'add-balance' : 'deduct-balance'), Object.assign({ amount, note }, note.length >= 5 ? { reason: note } : {}), add ? 'اتضاف الرصيد' : 'اتسحب الرصيد', () => render(uid));
                }
                if (e.target.closest('[data-grant]')) {
                    const plan = $('#apGrantPlan', body).value, days = parseInt($('#apGrantDays', body).value, 10);
                    if (!plan || !(days > 0)) return toast('اختار الباقة والمدة', 'error');
                    if (!confirmBox('فتح كل خدمات باقة «' + $('#apGrantPlan', body).selectedOptions[0].text + '» لـ ' + name + ' مجانًا لمدة ' + days + ' يوم؟\nمش هيتخصم منه أي فلوس، ولما المدة تخلص بتقف لوحدها من غير تجديد.')) return;
                    return act('/api/admin/users/' + uid + '/grant-plan', { plan, days }, 'اتفعّلت', () => render(uid));
                }
                const ta = e.target.closest('[data-testacc]');
                if (ta) {
                    const on = ta.checked, r = await api('/api/admin/insights/users/' + uid + '/tags' + (on ? '' : '?tag=' + encodeURIComponent('تجربة')), on ? 'POST' : 'DELETE', on ? { tag: 'تجربة' } : undefined);
                    if (r.success) { toast(on ? 'اتعلّم حساب تجربة — اتشال من الإحصائيات' : 'رجع حساب عادي — بيتحسب في الإحصائيات', 'success'); render(uid); } else { ta.checked = !on; toast(r.error || 'تعذّر الحفظ', 'error'); }
                    return;
                }
                if (e.target.closest('[data-grantend]')) { if (confirmBox('إيقاف المنحة دلوقتي؟ (الاشتراك المدفوع لو موجود مابيتلمسش)')) await act('/api/admin/users/' + uid + '/grant-plan/end', {}, 'اتوقفت', () => render(uid)); return; }
                if (e.target.closest('[data-note]')) { const t = $('#apNote', body).value; return act('/api/admin/pro/user/' + uid + '/notes', { note: t, pinned: $('#apNotePin', body).checked }, 'تمت الإضافة', () => render(uid)); }
                const dn = e.target.closest('[data-delnote]'); if (dn) { e.preventDefault(); if (confirmBox('حذف الملاحظة؟')) { const rr = await api('/api/admin/pro/user/' + uid + '/notes/' + dn.dataset.delnote, 'DELETE'); rr.success ? (toast('تم الحذف', 'success'), render(uid)) : toast(rr.error || 'تعذّر الحذف', 'error'); } return; }
                if (e.target.closest('[data-reload]')) { e.preventDefault(); render(uid); }
            };
        }
        function sec(title, html) { return '<section class="ap-usec"><h4>' + esc(title) + '</h4>' + html + '</section>'; }
        async function loadGrant(uid, body) {
            const box = $('#apGrant', body); if (!box) return;
            const r = await api('/api/admin/users/' + uid + '/grant-plan');
            if (!r.success) { box.innerHTML = '<b>فتح كل الخدمات مجانًا</b><div class="ap-sub">' + esc(r.error || 'غير متاح') + '</div>'; return; }
            const d = r.data, g = (d.grants || [])[0];
            const cur = d.active ? 'الحالي: ' + esc(d.active.plan) + ' (' + esc(d.active.status) + (d.active.ends_at ? ' لحد ' + esc(String(d.active.ends_at).slice(0, 10)) : '') + (g ? ' · منحة مجانية' : (+d.active.price > 0 ? ' · مدفوع' : '')) + ')' : 'مفيش اشتراك شغال دلوقتي';
            const testBox = '<label class="ap-testacc' + (d.is_test ? ' on' : '') + '"><input type="checkbox" data-testacc' + (d.is_test ? ' checked' : '') + '><span><b>🧪 حساب تجربة</b> — رصيده واشتراكاته مابتدخلش في إحصائيات المنصة (رصيد المنصة، الإيداعات، الإيراد الشهري، عدد العملاء). حسابه نفسه بيفضل شغال عادي.</span></label>';
            box.innerHTML = testBox + '<b>فتح كل الخدمات مجانًا</b><div class="ap-sub">العميل ياخد كل خدمات الباقة وحدودها من غير دفع ومن غير خصم من محفظته. لما المدة تخلص بتقف لوحدها، والتجديد مابيسحبش فلوس. الاشتراك المدفوع لو موجود مابيتلمسش.</div>' +
                '<div class="ap-sub"><b>' + cur + '</b></div>' +
                '<div class="ap-mrow"><select id="apGrantPlan">' + (d.plans || []).map(p => '<option value="' + esc(p.key) + '">' + esc(p.name) + ' ($' + esc(p.price) + '/شهر)</option>').join('') + '</select>' +
                '<select id="apGrantDays"><option value="7">أسبوع</option><option value="30" selected>شهر</option><option value="90">3 شهور</option><option value="180">6 شهور</option><option value="365">سنة</option><option value="3650">مفتوح (10 سنين)</option></select></div>' +
                '<div class="ap-mrow"><button class="p-btn success xs" data-grant>🎁 فتح الخدمات مجانًا</button>' + (g ? '<button class="p-btn danger xs" data-grantend>إيقاف المنحة (لحد ' + esc(String(g.ends_at).slice(0, 10)) + ')</button>' : '') + '</div>';
        }
    }
    function escClose(e) { if (e.key === 'Escape') closeUser(); }
    function closeUser() { const el = $('#apUserDrawer'); if (el) el.remove(); document.removeEventListener('keydown', escClose); }

    // ================================================================== المالية والضرائب
    async function pageFinance(root) {
        root.innerHTML = skeleton(260);
        const [st, rf, tx] = await Promise.all([
            api('/api/admin/wallet/stats'),
            api('/api/admin/refunds'),
            api('/api/admin/tax-rules'),
        ]);
        const deny = r => (r && r.error ? r.error : 'غير متاح');
        const s = (st.success && st.data && st.data.stats) || null;

        const statsHtml = s
            ? '<section class="ap-panel ap-minis" style="grid-template-columns:repeat(4,minmax(0,1fr))">' +
              stat('إيداعات هذا الشهر', money(+s.deposits_this_month || 0), '', (s.deposits_this_month_count || 0) + ' عملية', true) +
              stat('إيداعات معلّقة', NF.format(+s.pending_count || 0), (+s.pending_count || 0) > 0 ? 'bad' : '', money(+s.pending_total || 0) + ' بانتظار الاعتماد') +
              stat('رصيد المنصة (أرصدة العملاء)', money(+s.total_customer_balances || 0), '', '', true) +
              stat('استهلاك مخصوم هذا الشهر', money(+s.usage_charges_this_month || 0), '', (s.usage_charges_this_month_count || 0) + ' عملية', true) +
              '</section>'
            : '<div class="ap-note">مؤشرات المحفظة غير متاحة: ' + esc(deny(st)) + '</div>';

        // ---- الاسترداد
        let refundsHtml;
        if (rf.success) {
            const list = (rf.data && rf.data.refunds) || [];
            const stPill = v => pill(v || '—', v === 'succeeded' || v === 'completed' ? 'green' : (v === 'failed' ? 'red' : 'gray'));
            refundsHtml = table(['#', 'العميل', 'المبلغ', 'السبب', 'الحالة', 'التاريخ'], list.map(r =>
                '<tr><td class="ap-mono">' + esc(r.id) + '</td><td><strong>' + esc(r.company_name || '—') + '</strong><br><span class="p-cell-muted">' + esc(r.user_email || '') + '</span></td>' +
                '<td>' + esc(NF.format(+r.amount || 0)) + ' ' + esc(r.currency || 'USD') + '</td><td class="p-cell-muted">' + esc(r.reason || '—') + '</td>' +
                '<td>' + stPill(r.status) + '</td><td class="p-cell-muted">' + esc(dt(r.created_at)) + '</td></tr>'), 'لا توجد عمليات استرداد حتى الآن');
        } else {
            refundsHtml = '<div class="ap-empty" style="padding:22px">' + esc(deny(rf)) + '</div>';
        }
        const canRefund = rf.success;

        // ---- الضرائب
        let taxHtml;
        if (tx.success) {
            const list = (tx.data && tx.data.rules) || [];
            taxHtml = table(['الدولة', 'النوع', 'النسبة', 'الحالة'], list.map(r =>
                '<tr data-cc="' + esc(r.country_code) + '" data-type="' + esc(r.tax_type) + '" data-rate="' + esc(r.tax_rate_percent) + '" data-active="' + (+r.is_active ? 1 : 0) + '">' +
                '<td><strong>' + esc(r.country_code) + '</strong></td><td>' + esc(r.tax_type) + '</td><td>' + esc(r.tax_rate_percent) + '%</td>' +
                '<td>' + (+r.is_active ? pill('مفعّلة', 'green') : pill('متوقفة', 'gray')) + ' <button class="p-btn outline xs" data-edit-tax>تعديل</button></td></tr>'), 'لا توجد قواعد ضرائب. الفواتير بتتحسب من غير ضريبة لحد ما تضيف قاعدة.');
        } else {
            taxHtml = '<div class="ap-empty" style="padding:22px">' + esc(deny(tx)) + '</div>';
        }
        const canTax = tx.success;

        root.innerHTML = head('المالية والضرائب', 'الإيداعات المعلّقة وكروت الشحن وبيانات الدفع وتسعير الاستخدام موجودة في صفحة "الباقات والتسعير". هنا الاسترداد وقواعد الضرائب.') +
            statsHtml +
            '<section class="ap-panel" style="margin-top:14px"><div class="ap-panel-head"><h3>عمليات الاسترداد</h3>' + (canRefund ? '<button class="p-btn primary xs" id="apNewRefund">+ استرداد جديد</button>' : '') + '</div><div class="ap-panel-body" style="padding:0">' + refundsHtml + '</div></section>' +
            '<section class="ap-panel" style="margin-top:14px"><div class="ap-panel-head"><h3>قواعد الضرائب</h3>' + (canTax ? '<button class="p-btn primary xs" id="apNewTax">+ قاعدة جديدة</button>' : '') + '</div><div class="ap-panel-body" style="padding:0">' + taxHtml + '</div></section>';

        const reload = () => pageFinance(root);
        if (root._finBound) root.removeEventListener('click', root._finBound);
        root._finBound = e => {
            if (e.target.closest('#apNewRefund')) openRefund(reload);
            else if (e.target.closest('#apNewTax')) openTax(null, reload);
            else {
                const ed = e.target.closest('[data-edit-tax]');
                if (ed) { const tr = ed.closest('tr'); openTax({ cc: tr.dataset.cc, type: tr.dataset.type, rate: tr.dataset.rate, active: tr.dataset.active === '1' }, reload); }
            }
        };
        root.addEventListener('click', root._finBound);
    }

    function modal(html) {
        const bg = document.createElement('div'); bg.className = 'ap-modal-bg';
        bg.innerHTML = '<div class="ap-modal" role="dialog" aria-modal="true">' + html + '</div>';
        document.body.appendChild(bg);
        const close = () => bg.remove();
        bg.addEventListener('mousedown', e => { if (e.target === bg) close(); });
        bg.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        return { bg, close };
    }

    function openRefund(done) {
        const m = modal('<h3>استرداد جديد</h3><p class="ap-sub">الاسترداد بيتنفّذ فعليًا على المعاملة الأصلية ولا يمكن التراجع عنه. أقصى مبلغ هو المتبقي من قيمة المعاملة.</p>' +
            '<label class="ap-lbl">رقم معاملة الدفع</label><input type="number" min="1" step="1" class="p-input" id="rfTx" placeholder="مثال: 125">' +
            '<label class="ap-lbl">المبلغ (USD)</label><input type="number" min="0.01" step="0.01" class="p-input" id="rfAmt">' +
            '<label class="ap-lbl">السبب</label><input type="text" maxlength="200" class="p-input" id="rfReason">' +
            '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn danger" data-ok>تنفيذ الاسترداد</button></div>');
        $('[data-x]', m.bg).onclick = m.close;
        $('#rfTx', m.bg).focus();
        $('[data-ok]', m.bg).onclick = async () => {
            const tx = parseInt($('#rfTx', m.bg).value, 10), amt = parseFloat($('#rfAmt', m.bg).value);
            if (!(tx > 0)) { toast('اكتب رقم معاملة صحيح', 'error'); return; }
            if (!(amt > 0)) { toast('اكتب مبلغًا أكبر من صفر', 'error'); return; }
            if (!confirmBox('تأكيد استرداد ' + NF.format(amt) + ' USD من المعاملة #' + tx + '؟ لا يمكن التراجع.')) return;
            const r = await act('/api/admin/refunds', { payment_transaction_id: tx, amount: amt, reason: $('#rfReason', m.bg).value.trim() }, 'تم تنفيذ الاسترداد', done);
            if (r.success) m.close();
        };
    }

    function openTax(rule, done) {
        const edit = !!rule;
        const m = modal('<h3>' + (edit ? 'تعديل قاعدة ضريبة' : 'قاعدة ضريبة جديدة') + '</h3><p class="ap-sub">قاعدة واحدة لكل (دولة + نوع ضريبة). الحفظ على قاعدة موجودة بيحدّث نسبتها وحالتها.</p>' +
            '<label class="ap-lbl">رمز الدولة (حرفين)</label><input type="text" maxlength="2" class="p-input" id="txCc" style="text-transform:uppercase" value="' + esc(edit ? rule.cc : '') + '"' + (edit ? ' readonly' : '') + '>' +
            '<label class="ap-lbl">نوع الضريبة</label><input type="text" maxlength="20" class="p-input" id="txType" placeholder="VAT" value="' + esc(edit ? rule.type : 'VAT') + '"' + (edit ? ' readonly' : '') + '>' +
            '<label class="ap-lbl">النسبة %</label><input type="number" min="0" max="100" step="0.01" class="p-input" id="txRate" value="' + esc(edit ? rule.rate : '') + '">' +
            '<label class="ap-lbl"><input type="checkbox" id="txOn"' + (!edit || rule.active ? ' checked' : '') + '> مفعّلة</label>' +
            '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>');
        $('[data-x]', m.bg).onclick = m.close;
        $('[data-ok]', m.bg).onclick = async () => {
            const cc = $('#txCc', m.bg).value.trim().toUpperCase(), type = $('#txType', m.bg).value.trim().toUpperCase(), rate = parseFloat($('#txRate', m.bg).value);
            if (!/^[A-Z]{2}$/.test(cc)) { toast('رمز الدولة لازم يكون حرفين مثل EG أو GB', 'error'); return; }
            if (!type) { toast('اكتب نوع الضريبة', 'error'); return; }
            if (!(rate >= 0 && rate <= 100)) { toast('النسبة لازم تكون بين 0 و 100', 'error'); return; }
            const r = await act('/api/admin/tax-rules', { country_code: cc, tax_type: type, tax_rate_percent: rate, is_active: $('#txOn', m.bg).checked }, 'تم حفظ القاعدة', done);
            if (r.success) m.close();
        };
    }

    // ================================================================== مفاتيح الـ API (الشركاء)
    const PARTNER_SCOPES = [['reputation:read', 'قراءة ملخص السمعة (reputation:read)']];

    async function pageApiKeys(root) {
        root.innerHTML = skeleton(200);
        const r = await api('/api/admin/partner-keys');
        if (!r.success) { root.innerHTML = failedBox(r.error); return; }
        const keys = (r.data && r.data.keys) || [];
        const active = keys.filter(k => k.status === 'active').length;

        root.innerHTML = head('مفاتيح الـ API', 'مفاتيح للشركاء الخارجيين بتدخلهم على واجهة /api/partner. المفتاح الكامل بيظهر مرة واحدة بس وقت الإنشاء.', '<button class="p-btn primary" id="apNewKey">+ مفتاح جديد</button>') +
            '<section class="ap-panel ap-minis" style="grid-template-columns:repeat(3,minmax(0,1fr))">' + stat('إجمالي المفاتيح', keys.length) + stat('نشطة', active, 'good') + stat('ملغاة', keys.length - active) + '</section>' +
            '<section class="ap-panel" style="margin-top:14px"><div class="ap-panel-body" style="padding:0">' +
            table(['الشريك', 'المفتاح', 'الصلاحيات', 'الحد/دقيقة', 'الحالة', 'آخر استخدام', ''], keys.map(k =>
                '<tr data-id="' + esc(k.id) + '"><td><strong>' + esc(k.partner_name) + '</strong><br><span class="p-cell-muted">' + esc(k.contact_email || '') + '</span></td>' +
                '<td class="ap-mono">' + esc(k.key_preview) + '</td><td>' + (k.scopes || []).map(x => pill(x, 'blue')).join(' ') + '</td>' +
                '<td>' + esc(k.rate_limit_per_minute) + '</td><td>' + (k.status === 'active' ? pill('نشط', 'green') : pill('ملغى', 'red')) + '</td>' +
                '<td class="p-cell-muted">' + (k.last_used_at ? esc(ago(k.last_used_at)) : 'لم يُستخدم') + '</td>' +
                '<td>' + (k.status === 'active' ? '<button class="p-btn danger xs" data-revoke>إلغاء المفتاح</button>' : '') + '</td></tr>'), 'لا توجد مفاتيح. أنشئ أول مفتاح لشريكك.') +
            '</div></section>';

        const reload = () => pageApiKeys(root);
        if (root._keyBound) root.removeEventListener('click', root._keyBound);
        root._keyBound = async e => {
            if (e.target.closest('#apNewKey')) { openNewKey(reload); return; }
            const rv = e.target.closest('[data-revoke]');
            if (rv) {
                const tr = rv.closest('tr');
                if (!confirmBox('إلغاء المفتاح هيوقف أي نظام بيستخدمه فورًا ولا يمكن التراجع. متأكد؟')) return;
                const rr = await api('/api/admin/partner-keys/' + tr.dataset.id, 'DELETE');
                if (rr.success) { toast('تم إلغاء المفتاح', 'success'); reload(); } else toast(rr.error || 'تعذر إلغاء المفتاح', 'error');
            }
            if (e.target.closest('[data-reload]')) { e.preventDefault(); reload(); }
        };
        root.addEventListener('click', root._keyBound);
    }

    function openNewKey(done) {
        const m = modal('<h3>مفتاح API جديد</h3>' +
            '<label class="ap-lbl">اسم الشريك</label><input type="text" maxlength="100" class="p-input" id="pkName">' +
            '<label class="ap-lbl">بريد التواصل (اختياري)</label><input type="email" class="p-input" id="pkMail">' +
            '<label class="ap-lbl">الصلاحيات</label>' + PARTNER_SCOPES.map((s, i) => '<label style="display:block;margin:4px 0"><input type="checkbox" class="pkScope" value="' + esc(s[0]) + '"' + (i === 0 ? ' checked' : '') + '> ' + esc(s[1]) + '</label>').join('') +
            '<label class="ap-lbl">الحد الأقصى للطلبات في الدقيقة</label><input type="number" min="1" max="6000" step="1" class="p-input" id="pkRate" value="60">' +
            '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>إنشاء</button></div>');
        $('[data-x]', m.bg).onclick = m.close;
        $('#pkName', m.bg).focus();
        $('[data-ok]', m.bg).onclick = async () => {
            const name = $('#pkName', m.bg).value.trim();
            const scopes = $$('.pkScope', m.bg).filter(c => c.checked).map(c => c.value);
            const rate = parseInt($('#pkRate', m.bg).value, 10);
            if (!name) { toast('اكتب اسم الشريك', 'error'); return; }
            if (!scopes.length) { toast('اختار صلاحية واحدة على الأقل', 'error'); return; }
            if (!(rate >= 1 && rate <= 6000)) { toast('الحد لازم يكون بين 1 و 6000', 'error'); return; }
            const mail = $('#pkMail', m.bg).value.trim();
            const r = await api('/api/admin/partner-keys', 'POST', { partner_name: name, contact_email: mail || null, scopes: scopes, rate_limit_per_minute: rate });
            if (!r.success) { toast(r.error || 'تعذر إنشاء المفتاح', 'error'); return; }
            m.close();
            showRawKey(r.data && r.data.raw_key, done);
        };
    }

    function showRawKey(raw, done) {
        const m = modal('<h3>تم إنشاء المفتاح</h3><div class="ap-note" style="margin-top:8px"><b>انسخ المفتاح الآن.</b> مش هيظهر تاني بعد إغلاق النافذة، ولو ضاع لازم تنشئ مفتاح جديد.</div>' +
            '<input type="text" readonly class="p-input ap-mono" id="pkRaw" style="margin-top:14px;width:100%" value="' + esc(raw || '') + '">' +
            '<div class="ap-actions"><button class="p-btn outline" data-copy>نسخ</button><button class="p-btn primary" data-done>تم، حفظته</button></div>');
        const inp = $('#pkRaw', m.bg); inp.focus(); inp.select();
        $('[data-copy]', m.bg).onclick = async () => {
            try { await navigator.clipboard.writeText(inp.value); toast('تم النسخ', 'success'); }
            catch (e) { inp.select(); document.execCommand && document.execCommand('copy'); toast('تم النسخ', 'success'); }
        };
        $('[data-done]', m.bg).onclick = () => { m.close(); if (done) done(); };
    }

    // ================================================================== تقييد الأزرار حسب الصلاحية
    const GATES = [
        [/^(toggleUser)\(/, 'users.suspend'], [/^(saveUserRole)\(/, 'users.edit'], [/^(impersonate)\(/, 'users.impersonate'],
        [/^(addUserBalance|deductUserBalance)\(/, 'billing.manage'], [/^(resetPlatformBalance|restorePlatformBalance|zeroAllBalances)\(/, 'billing.settings'], [/^(approveDeposit|rejectDeposit)\(/, 'billing.manage'], [/^(cancelSub)\(/, 'subscriptions.manage'],
        [/^(openEditPlan)\(/, 'plans.manage'], [/^(saveUsagePricing)\(/, 'billing.settings'], [/^(markContactMessageRead)\(/, 'messages.manage'],
    ];
    function gate(root) {
        $$('[onclick]', root || document).forEach(el => {
            if (el.dataset.gated) return;
            const code = (el.getAttribute('onclick') || '').trim();
            for (const g of GATES) {
                if (g[0].test(code)) {
                    el.dataset.gated = '1';
                    if (!can(g[1])) { el.disabled = true; el.classList.add('ap-locked'); el.title = 'ليس لديك صلاحية لهذا الإجراء'; el.removeAttribute('onclick'); }
                    break;
                }
            }
        });
    }

    // ------------------------------------------------------------------ تسجيل
    const PAGES = { team: pageTeam, audit: pageAudit, 'ai-usage': pageAi, jobs: pageJobs, security: pageSecurity, finance: pageFinance, 'api-keys': pageApiKeys };
    window.AdminPro.renderPage = async function (tab) {
        const root = document.getElementById('proPage');
        if (!root || !PAGES[tab]) return;
        try { await PAGES[tab](root); } catch (e) { root.innerHTML = failedBox('حدث خطأ غير متوقع في عرض الصفحة'); if (window.console) console.error(e); }
    };
    window.AdminPro.openUser = openUser;
    // صفحات إضافية من ملفات تانية (admin-insights.js)
    window.AdminPro.registerPage = function (tab, fn) { PAGES[tab] = fn; };
    window.AdminPro.helpers = { api: api, act: act, esc: esc, pill: pill, skeleton: skeleton, failedBox: failedBox, head: head, table: table, stat: stat, pager: pager, modal: typeof modal === 'function' ? modal : null, money: money, dt: dt, ago: ago, can: can, toast: toast };
    window.AdminPro.reapplyGates = function () { $$('[data-gated]').forEach(el => { el.removeAttribute('data-gated'); }); gate(document); };

    function init() {
        // ملف العميل 360 بدل نافذة العرض القديمة (لو فشل التحميل بيرجع للقديمة)
        window.addEventListener('load', () => {
            const legacy = window.viewUser;
            if (can('users.view')) {
                window.viewUserLegacy = legacy;
                window.viewUser = id => openUser(id);
            }
            gate(document);
            new MutationObserver(() => gate(document)).observe(document.body, { childList: true, subtree: true });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
