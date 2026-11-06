/**
 * Tourfecto - لوحة الأدمن الاحترافية
 * صحة المنصة · سجل البريد · أرقام SaaS · صحة العملاء · الدفع والكوبونات · التنبيهات · أمان الأدمن · صفحة الحالة
 * بيتحمّل بعد admin-pages.js وبيسجّل صفحاته عن طريق AdminPro.registerPage.
 * فيه كمان: سبب إجباري للإجراءات الحساسة (بيتسجّل في سجل التدقيق) + صحة العميل في ملف العميل 360.
 */
(function () {
    'use strict';
    if (!window.AdminPro || !window.AdminPro.registerPage) return;
    const H = window.AdminPro.helpers;
    const { api, esc, pill, skeleton, failedBox, table, pager, can, toast } = H;
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
    const NF = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
    const money = v => v === null || v === undefined ? '—' : (+v < 0 ? '−$' : '$') + NF.format(Math.abs(+v));
    const pct = v => v === null || v === undefined ? '—' : NF.format(+v) + '%';
    // التواريخ في قاعدة البيانات UTC (نفس admin-pro.js) - بنعرضها بتوقيت جهاز الأدمن
    const parseUTC = s => s ? new Date(String(s).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(s)) ? '' : 'Z')) : null;
    const pad = n => String(n).padStart(2, '0');
    const dt = s => { const d = parseUTC(s); return !d || isNaN(d) ? '—' : d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };
    const ago = s => {
        const d = parseUTC(s); if (!d || isNaN(d)) return '—';
        const sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
        if (sec < 90) return 'دلوقتي';
        if (sec < 5400) return 'من ' + Math.round(sec / 60) + ' دقيقة';
        if (sec < 129600) return 'من ' + Math.round(sec / 3600) + ' ساعة';
        return 'من ' + Math.round(sec / 86400) + ' يوم';
    };
    const ST = { ok: ['green', 'تمام'], warn: ['orange', 'محتاج نظرة'], fail: ['red', 'مشكلة'], pending: ['gray', 'لسه'], off: ['gray', 'موقوف'] };
    const dot = s => '<span class="ai-dot ' + esc(s) + '"></span>';

    function modal(html, wide) {
        const bg = document.createElement('div'); bg.className = 'ap-modal-bg';
        bg.innerHTML = '<div class="ap-modal' + (wide ? ' ai-wide' : '') + '" role="dialog" aria-modal="true">' + html + '</div>';
        document.body.appendChild(bg);
        const close = () => { bg.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        bg.addEventListener('mousedown', e => { if (e.target === bg) close(); });
        document.addEventListener('keydown', onKey);
        return { bg, close };
    }
    /** تأكيد شيك بدل confirm() */
    function confirmDialog(title, text, okLabel, danger) {
        return new Promise(res => {
            const m = modal('<h3>' + esc(title) + '</h3><p class="ap-sub" style="line-height:1.8">' + esc(text) + '</p><div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn ' + (danger ? 'danger' : 'primary') + '" data-ok>' + esc(okLabel || 'تأكيد') + '</button></div>');
            $('[data-x]', m.bg).onclick = () => { m.close(); res(false); };
            $('[data-ok]', m.bg).onclick = () => { m.close(); res(true); };
            $('[data-ok]', m.bg).focus();
        });
    }
    function delta(cur, prev, inverse, fmt) {
        if (prev === null || prev === undefined || cur === null || cur === undefined) return '';
        if (+prev === 0 && fmt !== 'pct') return +cur === 0 ? '<span class="ap-delta flat">—</span>' : '<span class="ap-delta new">جديد</span>';
        const d = fmt === 'pct' ? (+cur - +prev) : ((+cur - +prev) / Math.abs(+prev)) * 100;
        if (Math.abs(d) < 0.05) return '<span class="ap-delta flat">0%</span>';
        const good = inverse ? d < 0 : d > 0;
        return '<span class="ap-delta ' + (good ? 'good' : 'bad') + '">' + (d > 0 ? '↑' : '↓') + ' ' + NF.format(Math.abs(d)) + (fmt === 'pct' ? ' نقطة' : '%') + '</span>';
    }
    function fmtVal(v, f) { return f === 'money' ? money(v) : (f === 'pct' ? pct(v) : (v === null || v === undefined ? '—' : NF.format(+v))); }
    function kpi(label, k, hint) {
        return '<div class="ap-kpi"><div class="ap-kpi-label">' + esc(label) + (hint ? ' <i title="' + esc(hint) + '">?</i>' : '') + '</div><div class="ap-kpi-value' + (k.format === 'money' ? ' is-money' : '') + '">' + esc(fmtVal(k.value, k.format)) + '</div>' +
            '<div class="ap-kpi-foot"><span class="ap-sub">' + (k.prev !== null && k.prev !== undefined ? 'قبل 30 يوم: <bdi class="ap-mono">' + esc(fmtVal(k.prev, k.format)) + '</bdi>' : esc(k.hint || '')) + '</span>' + delta(k.value, k.prev, k.inverse, k.format) + '</div></div>';
    }
    function emptyState(icon, title, text, action) {
        return '<div class="ai-empty"><div class="ai-empty-ico">' + icon + '</div><b>' + esc(title) + '</b><p>' + esc(text) + '</p>' + (action || '') + '</div>';
    }
    function panel(title, sub, body, actions) {
        return '<section class="ap-panel ai-panel"><div class="ap-panel-head"><div><h3>' + esc(title) + '</h3>' + (sub ? '<div class="ap-sub">' + esc(sub) + '</div>' : '') + '</div>' + (actions || '') + '</div><div class="ap-panel-body">' + body + '</div></section>';
    }
    async function load(root, url, render) {
        root.innerHTML = skeleton(140) + skeleton(260);
        const r = await api(url);
        if (!r.success) { root.innerHTML = failedBox(r.error); const a = $('[data-reload]', root); if (a) a.onclick = e => { e.preventDefault(); load(root, url, render); }; return; }
        try { render(r.data); } catch (e) { root.innerHTML = failedBox('حدث خطأ في عرض الصفحة'); if (window.console) console.error(e); }
    }

    // ================================================================== صحة المنصة
    async function pageHealth(root) {
        load(root, '/api/admin/insights/health', d => {
            const banner = {
                ok: ['كل حاجة شغالة تمام', 'مفيش أي مشكلة محتاجة تدخلك دلوقتي.'],
                warn: ['فيه حاجات محتاجة نظرة', d.counts.warn + ' فحص محتاج متابعة - مش بيوقف المنصة بس ماتسيبهوش.'],
                fail: ['فيه مشاكل لازم تتحل', d.counts.fail + ' مشكلة ممكن تكون موقفة جزء من المنصة. الحل مكتوب جنب كل واحدة.'],
            }[d.overall];
            const secOrder = ['cron', 'outreach', 'operations_delivery', 'integrations', 'mail', 'queue', 'payments', 'errors', 'server', 'database'];
            const cards = secOrder.map(k => {
                const s = d.sections[k]; if (!s) return '';
                const worst = s.checks.some(c => c.status === 'fail') ? 'fail' : (s.checks.some(c => c.status === 'warn') ? 'warn' : 'ok');
                return '<div class="ai-hcard ' + worst + '"><div class="ai-hcard-head">' + dot(worst) + '<b>' + esc(s.title) + '</b></div>' +
                    s.checks.map(c => '<div class="ai-check">' + dot(c.status) + '<div><div class="ai-check-l">' + esc(c.label) + '</div><div class="ai-check-m">' + esc(c.message) + '</div>' +
                        (c.action && c.status !== 'ok' ? '<div class="ai-check-a">← ' + esc(c.action) + '</div>' : '') + '</div></div>').join('') + '</div>';
            }).join('');
            const jobs = (d.sections.cron.jobs || []).slice().sort((a, b) => ({ fail: 0, warn: 1, ok: 2, pending: 3, off: 4 }[a.status] - { fail: 0, warn: 1, ok: 2, pending: 3, off: 4 }[b.status]));
            const jobRows = jobs.map(j => '<tr><td>' + dot(j.status) + ' <b>' + esc(j.title) + '</b><br><span class="p-cell-muted ap-mono">' + esc(j.key) + '</span></td><td>' + esc(j.every) + '</td><td class="p-cell-muted">' + esc(ago(j.last_start)) + '</td><td>' + pill(ST[j.status][1], ST[j.status][0]) + '</td><td class="p-cell-muted">' + esc(j.message) + '</td></tr>');
            const ints = (d.sections.integrations.items || []).filter(i => i.configured || i.importance === 'required');
            const imp = { required: ['red', 'لازم'], important: ['orange', 'مهم'], optional: ['gray', 'اختياري'] };
            const intRows = ints.map(i => '<tr><td><b>' + esc(i.label) + '</b></td><td>' + pill(imp[i.importance][1], imp[i.importance][0]) + '</td><td>' + (i.configured ? pill('متظبط', 'blue') : pill('ناقص', 'red')) + '</td><td>' +
                (i.last_check_ok === null ? '<span class="p-cell-muted">لسه</span>' : (i.last_check_ok ? pill('شغال', 'green') : pill('بيفشل', 'red'))) + '</td><td class="p-cell-muted" style="max-width:340px">' + esc(i.last_check_message || '') + (i.last_checked_at ? '<br><small>' + esc(ago(i.last_checked_at)) + '</small>' : '') + '</td></tr>');
            root.innerHTML =
                '<div class="ai-banner ' + d.overall + '"><div class="ai-banner-ico">' + (d.overall === 'ok' ? '✓' : (d.overall === 'warn' ? '!' : '×')) + '</div><div><b>' + esc(banner[0]) + '</b><div>' + esc(banner[1]) + '</div></div>' +
                '<span class="ap-spacer"></span><div class="ai-banner-counts"><span>' + dot('ok') + d.counts.ok + '</span><span>' + dot('warn') + d.counts.warn + '</span><span>' + dot('fail') + d.counts.fail + '</span></div>' +
                (d.alerts && d.alerts.total ? '<a class="p-btn outline xs" href="/admin/alerts">' + d.alerts.total + ' تنبيه مفتوح</a>' : '') + '</div>' +
                '<div class="ap-bar"><span class="ap-sub">آخر تحديث: ' + esc(dt(d.generated_at)) + '</span><span class="ap-spacer"></span>' +
                (can('settings.manage') ? '<button class="p-btn outline xs" data-check>اختبر كل التكاملات دلوقتي</button>' : '') + '<button class="p-btn outline xs" data-refresh>تحديث</button></div>' +
                '<div class="ai-hgrid">' + cards + '</div>' +
                '<div class="ap-note">فحص تسويق المنصة للقراءة فقط: مفيش رسائل بتتبعت ومفيش إعدادات بتتغيّر. <a href="/admin/growth">افتح تسويق المنصة</a></div>' +
                panel('المهام المجدولة', 'بتشتغل من سطر Cron واحد (المنظّم). اللوج بتاع كل مهمة في storage/logs/cron/', table(['المهمة', 'كل', 'آخر تشغيل', 'الحالة', 'التفاصيل'], jobRows, 'ملف cron/schedule.php مش موجود')) +
                panel('التكاملات', 'المتظبطة والأساسية بس. بتتختبر لوحدها كل 6 ساعات.', table(['التكامل', 'الأهمية', 'الإعداد', 'آخر اختبار', 'النتيجة'], intRows), '<a class="p-btn outline xs" href="/admin/integrations">إدارة التكاملات</a>') +
                schemaPanel(d.sections.database && d.sections.database.detail) +
                (can('settings.manage') ? panel('تنظيف المنصة', 'بيمسح اللي مالوش لازمة بس: لوجات وكاش وجلسات منتهية، سجلات تشغيل قديمة، ونسخ ملفات مكررة. بيانات العملاء والفلوس مابتتلمسش.', '<div data-cleanup><div class="ap-empty">دوس "افحص" عشان تشوف اللي ممكن يتنضف وحجمه - مفيش حاجة بتتمسح من غير ما تختار.</div></div>', '<button class="p-btn outline xs" data-cl-scan>افحص</button>') : '');
            root.onclick = async e => {
                if (e.target.closest('[data-refresh]')) return pageHealth(root);
                const b = e.target.closest('[data-check]');
                if (b) { b.disabled = true; b.textContent = 'بنختبر…'; const r = await api('/api/admin/insights/health/check-integrations', 'POST', {}); toast(r.message || r.error, r.success ? 'success' : 'error'); pageHealth(root); }
                if (e.target.closest('[data-cl-scan]')) return cleanupScan(root);
                if (e.target.closest('[data-cl-run]')) return cleanupRun(root);
                const sb = e.target.closest('[data-schema]');
                if (sb) {
                    if (!await confirmDialog('تطبيق تحديثات قاعدة البيانات', 'هنضيف الجداول والأعمدة والقيم الناقصة بس - مفيش أي حذف. يُفضّل تاخد Backup من aaPanel الأول.', 'طبّق دلوقتي')) return;
                    sb.disabled = true; sb.textContent = 'بنطبّق…';
                    const r = await api('/api/admin/insights/health/apply-schema', 'POST', {});
                    toast(r.message || r.error, r.success && !(r.data && r.data.errors) ? 'success' : 'error');
                    if (r.success && r.data) showSchemaReport(r.data);
                    pageHealth(root);
                }
            };
        });
    }

    function schemaPanel(det) {
        if (!det) return '';
        const pend = det.files.filter(f => !f.applied);
        const body = det.up_to_date
            ? '<div class="ap-note">✓ قاعدة البيانات متوافقة مع الكود: كل ملفات التحديث متطبقة وقيم الأعمدة كاملة.</div>'
            : '<div class="ap-note"><b>فيه تحديثات ناقصة.</b> ' +
              (pend.length ? pend.length + ' ملف تحديث لسه ماتطبقش (' + pend.map(f => esc(f.file)).join('، ') + '). ' : '') +
              (det.enums.length ? det.enums.length + ' عمود ناقصه قيم: ' + det.enums.map(x => esc(x.table + '.' + x.column) + ' ← ' + esc(x.missing.join(', '))).join(' · ') + '. ' : '') +
              (det.pending_data ? det.pending_data + ' صف محتاج تحويل حالة. ' : '') +
              'التطبيق بيضيف بس (جداول/أعمدة/قيم) ومابيحذفش أي حاجة.</div>';
        const btn = can('settings.manage') && !det.up_to_date ? '<button class="p-btn xs" data-schema>طبّق تحديثات قاعدة البيانات</button>' : '';
        return panel('تحديثات قاعدة البيانات', 'ملفات database/migrations الجديدة + توسيع قيم ENUM اللي الكود محتاجها.', body, btn);
    }

    const human = b => { if (b === null || b === undefined) return '—'; const u = ['B', 'KB', 'MB', 'GB']; let i = 0; b = +b; while (b >= 1024 && i < 3) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; };
    let clLabels = {};
    const CL_GROUPS = { files: 'ملفات السيرفر', db: 'قاعدة البيانات', code: 'ملفات زيادة في المشروع' };

    async function cleanupScan(root) {
        const box = $('[data-cleanup]', root); if (!box) return;
        box.innerHTML = skeleton(160);
        const r = await api('/api/admin/insights/cleanup');
        if (!r.success) { box.innerHTML = failedBox(r.error); return; }
        const d = r.data;
        d.items.forEach(i => { clLabels[i.key] = i.label; });
        if (!d.items.length) { box.innerHTML = '<div class="ap-note">✓ مفيش حاجة محتاجة تتنضف دلوقتي.</div>'; return; }
        let html = '<div class="ap-bar" style="margin:0 0 10px"><span class="ap-sub">قاعدة البيانات: <b>' + human(d.disk.db_bytes) + '</b>' +
            (d.disk.free ? ' · المساحة الفاضية على السيرفر: <b>' + human(d.disk.free) + '</b> من ' + human(d.disk.total) : '') + '</span></div>';
        Object.keys(CL_GROUPS).forEach(g => {
            const its = d.items.filter(i => i.group === g); if (!its.length) return;
            html += '<h4 style="margin:14px 0 6px">' + esc(CL_GROUPS[g]) + '</h4>' + table(['', 'إيه', 'العدد', 'الحجم'], its.map(i =>
                '<tr><td style="width:34px"><input type="checkbox" data-cl-key="' + esc(i.key) + '"' + (i.default ? ' checked' : '') + '></td><td><b>' + esc(i.label) + '</b><div class="p-cell-muted">' + esc(i.desc) + '</div>' +
                (i.details && i.details.length ? '<details style="margin-top:4px"><summary class="p-cell-muted" style="cursor:pointer">عرض ' + (i.count > i.details.length ? 'أول ' + i.details.length : 'الكل') + '</summary><div class="ap-mono" style="font-size:12px;line-height:1.7;direction:ltr;text-align:left">' + i.details.map(esc).join('<br>') + '</div></details>' : '') +
                '</td><td class="ap-mono">' + NF.format(i.count) + '</td><td class="ap-mono">' + (i.bytes === null ? '—' : human(i.bytes)) + '</td></tr>'));
        });
        if (d.tables && d.tables.length) {
            html += '<details style="margin-top:12px"><summary class="p-cell-muted" style="cursor:pointer">أكبر الجداول في قاعدة البيانات</summary>' +
                table(['الجدول', 'صفوف (تقريبًا)', 'الحجم'], d.tables.map(t => '<tr><td class="ap-mono">' + esc(t.name) + '</td><td class="ap-mono">' + NF.format(+t.row_count || 0) + '</td><td class="ap-mono">' + human(t.bytes) + '</td></tr>')) + '</details>';
        }
        html += '<div class="ap-actions" style="justify-content:flex-start;margin-top:14px"><button class="p-btn primary" data-cl-run>نظّف المحدد</button></div>';
        box.innerHTML = html;
    }

    async function cleanupRun(root) {
        const keys = $$('[data-cl-key]:checked', root).map(x => x.dataset.clKey);
        if (!keys.length) return toast('اختار حاجة واحدة على الأقل', 'error');
        const hasCode = keys.indexOf('junk_code') !== -1, hasPurge = keys.indexOf('quarantine_purge') !== -1;
        const msg = 'هيتنضف ' + keys.length + ' بند. سجلات قاعدة البيانات اللي هتتمسح مابترجعش' +
            (hasCode ? '، والملفات الزيادة هتتنقل لـ storage/quarantine (تقدر ترجّعها)' : '') + (hasPurge ? '، والحجر القديم هيتمسح نهائي' : '') + '. يُفضّل Backup الأول.';
        if (!await confirmDialog('تنظيف المنصة', msg, 'نظّف دلوقتي', true)) return;
        const btn = $('[data-cl-run]', root); if (btn) { btn.disabled = true; btn.textContent = 'بننضف…'; }
        const r = await api('/api/admin/insights/cleanup', 'POST', { keys });
        toast(r.message || r.error, r.success ? 'success' : 'error');
        if (r.success) {
            const m = modal('<h3>نتيجة التنظيف</h3>' + table(['البند', 'النتيجة'], r.data.results.map(x => '<tr><td>' + esc(clLabels[x.key] || x.key) + '</td><td>' + (x.error ? pill('مشكلة', 'red') + ' ' : '') + esc(x.message) + '</td></tr>')) +
                '<div class="ap-actions"><button class="p-btn primary" data-x>تمام</button></div>');
            $('[data-x]', m.bg).onclick = () => m.close();
        }
        cleanupScan(root);
    }

    function showSchemaReport(r) {
        const rows = [];
        (r.files || []).forEach(f => rows.push('<tr><td class="ap-mono">' + esc(f.file) + '</td><td>' + f.ok + ' نُفذت · ' + f.already + ' موجودة أصلًا' + (f.warnings ? ' · ' + f.warnings + ' اتخطّت (جدول مش مستخدم)' : '') + '</td><td>' +
            (f.failed.length ? pill(f.failed.length + ' فشل', 'red') + f.failed.map(x => '<div class="ai-err">' + esc(x.error) + '</div>').join('') : pill('تمام', 'green')) + '</td></tr>'));
        (r.enums || []).forEach(x => rows.push('<tr><td class="ap-mono">' + esc(x.table + '.' + x.column) + '</td><td>' + (x.added && x.added.length ? 'اتضاف: ' + esc(x.added.join(', ')) : 'الافتراضي: ' + esc(x.default || '')) + '</td><td>' + (x.error ? pill('فشل', 'red') + '<div class="ai-err">' + esc(x.error) + '</div>' : pill('تمام', 'green')) + '</td></tr>'));
        (r.data || []).forEach(x => rows.push('<tr><td class="ap-mono">' + esc(x.table + '.' + x.column) + '</td><td>' + esc(x.from + ' → ' + x.to) + '</td><td>' + x.rows + ' صف</td></tr>'));
        const m = modal('<h3>نتيجة تحديث قاعدة البيانات</h3>' +
            (rows.length ? table(['العنصر', 'اللي حصل', 'النتيجة'], rows) : '<div class="ap-empty">مفيش حاجة كانت ناقصة.</div>') +
            '<div class="ap-actions"><button class="p-btn primary" data-x>تمام</button></div>', true);
        $('[data-x]', m.bg).onclick = () => m.close();
    }

    // ================================================================== سجل البريد
    const CTX = { password_reset: 'استعادة كلمة المرور', email_verification: 'تأكيد البريد', auth: 'الحساب', team_invite: 'دعوة فريق', review_request: 'طلب تقييم', seo_report: 'تقرير SEO', billing: 'الفواتير والتجديد', admin_alert: 'تنبيه أدمن', smtp_test: 'اختبار البريد', campaign: 'حملة بريدية', booking_confirmation: 'تأكيد حجز', competitor_alert: 'تنبيه منافسين', revenue_digest: 'ملخص الإيرادات', onboarding: 'الترحيب', outreach: 'طلب لينك', crm: 'CRM', chat_email: 'شات بالإيميل', customer_smtp: 'بريد العميل' };
    async function pageEmails(root) {
        const st = { status: '', context: '', q: '', page: 1 };
        async function draw() {
            const qs = new URLSearchParams({ status: st.status, context: st.context, q: st.q, page: st.page }).toString();
            const r = await api('/api/admin/insights/emails?' + qs);
            if (!r.success) { root.innerHTML = failedBox(r.error); return; }
            const d = r.data, s = d.stats;
            const rate = s.h24.total ? Math.round(100 * s.h24.sent / s.h24.total) : null;
            const rows = d.items.map(m => '<tr data-id="' + m.id + '" class="ai-click"><td class="p-cell-muted" title="' + esc(m.created_at) + '">' + esc(ago(m.created_at)) + '</td><td><b>' + esc(m.to_email) + '</b>' + (m.user_id ? '' : '') + '</td><td>' + esc(m.subject) + (m.resent_from_id ? ' ' + pill('إعادة إرسال', 'blue') : '') + '</td><td>' + (m.context ? pill(CTX[m.context] || m.context, 'gray') : '—') + '</td><td>' +
                (m.status === 'sent' ? pill('اتبعتت', 'green') : pill('فشلت', 'red') + '<div class="ai-err">' + esc((m.error || '').slice(0, 90)) + '</div>') + '</td></tr>');
            const ctxOpts = '<option value="">كل الأنواع</option>' + d.contexts.map(c => '<option value="' + esc(c.context) + '"' + (c.context === st.context ? ' selected' : '') + '>' + esc(CTX[c.context] || c.context) + ' (' + c.n + ')</option>').join('');
            root.innerHTML =
                '<div class="ap-panel ap-pulse ai-pulse4">' +
                kpi('اتبعتت (24 ساعة)', { value: s.h24.sent, format: 'int', hint: s.h24.last_sent_at ? 'آخر واحدة ' + ago(s.h24.last_sent_at) : 'مفيش' }) +
                kpi('فشلت (24 ساعة)', { value: s.h24.failed, format: 'int', hint: s.h24.last_error ? String(s.h24.last_error).slice(0, 60) : 'مفيش' }) +
                kpi('نسبة النجاح (24 ساعة)', { value: rate, format: 'pct', hint: s.h24.total + ' رسالة' }) +
                kpi('آخر 7 أيام', { value: s.d7.total, format: 'int', hint: s.d7.failed + ' فشلت' }) + '</div>' +
                (s.h24.failed ? '<div class="ap-note" style="margin:14px 0 0"><b>فيه رسايل فشلت.</b> افتح أي واحدة عشان تشوف الغلط بالظبط. لو الغلط "Username and Password not accepted" أو "Authentication" يبقى بيانات البريد في التكاملات ← البريد (SMTP) غلط.</div>' : '') +
                '<div class="ap-bar" style="margin-top:16px"><div class="ap-seg">' + [['', 'الكل'], ['sent', 'اتبعتت'], ['failed', 'فشلت']].map(o => '<button data-st="' + o[0] + '" aria-pressed="' + (st.status === o[0]) + '">' + o[1] + '</button>').join('') + '</div>' +
                '<select class="p-select" data-ctx style="max-width:220px">' + ctxOpts + '</select><input type="search" class="p-input" data-q placeholder="ابحث بالإيميل أو العنوان…" value="' + esc(st.q) + '" style="max-width:260px"><span class="ap-spacer"></span></div>' +
                '<section class="ap-panel">' + (d.items.length ? table(['الوقت', 'إلى', 'العنوان', 'النوع', 'الحالة'], rows) : emptyState('✉', 'مفيش رسايل', st.status || st.q || st.context ? 'مفيش رسايل بالفلتر ده.' : 'أول ما المنصة تبعت إيميل هيظهر هنا، سواء وصل أو فشل.')) + pager(d.page, d.per_page, d.total) + '</section>';
        }
        await draw();
        let t;
        root.oninput = e => { if (e.target.matches('[data-q]')) { clearTimeout(t); t = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; draw().then(() => { const q = $('[data-q]', root); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 350); } };
        root.onchange = e => { if (e.target.matches('[data-ctx]')) { st.context = e.target.value; st.page = 1; draw(); } };
        root.onclick = async e => {
            const s = e.target.closest('[data-st]'); if (s) { st.status = s.dataset.st; st.page = 1; return draw(); }
            const pg = e.target.closest('.ap-pager [data-page]'); if (pg && !pg.disabled) { st.page = +pg.dataset.page; return draw(); }
            const tr = e.target.closest('tr[data-id]'); if (tr) openEmail(+tr.dataset.id, draw);
        };
    }
    async function openEmail(id, after) {
        const r = await api('/api/admin/insights/emails/' + id);
        if (!r.success) return toast(r.error, 'error');
        const m = r.data.email;
        const md = modal('<h3>' + esc(m.subject) + '</h3><div class="ai-kv">' +
            '<span>إلى</span><b class="ap-mono">' + esc(m.to_email) + '</b><span>من</span><b class="ap-mono">' + esc(m.from_email || '—') + '</b><span>السيرفر</span><b class="ap-mono">' + esc(m.smtp_host || '—') + '</b>' +
            '<span>الوقت</span><b>' + esc(dt(m.created_at)) + (m.duration_ms ? ' · ' + m.duration_ms + 'ms' : '') + '</b><span>النوع</span><b>' + esc(CTX[m.context] || m.context || '—') + '</b>' +
            '<span>الحالة</span><b>' + (m.status === 'sent' ? pill('اتبعتت', 'green') : pill('فشلت', 'red')) + '</b></div>' +
            (m.error ? '<div class="ai-errbox">' + esc(m.error) + '</div>' : '') +
            (m.body_html ? '<iframe class="ai-preview" sandbox="" referrerpolicy="no-referrer"></iframe>' : '<div class="ap-empty">محتوى الرسالة مش متخزّن (حملة جماعية أو أقدم من 14 يوم)</div>') +
            '<div class="ap-actions"><button class="p-btn outline" data-x>قفل</button>' + (m.body_html && can('system.manage') ? '<button class="p-btn primary" data-resend>ابعتها تاني</button>' : '') + '</div>', true);
        const fr = $('.ai-preview', md.bg); if (fr) fr.srcdoc = m.body_html;
        $('[data-x]', md.bg).onclick = md.close;
        const rs = $('[data-resend]', md.bg);
        if (rs) rs.onclick = async () => { rs.disabled = true; rs.textContent = 'بنبعت…'; const x = await api('/api/admin/insights/emails/' + id + '/resend', 'POST', {}); toast(x.message || x.error, x.success ? 'success' : 'error'); if (x.success) { md.close(); after && after(); } else { rs.disabled = false; rs.textContent = 'ابعتها تاني'; } };
    }

    // ================================================================== أرقام SaaS
    function lineChart(series, key, color) {
        const W = 760, Hh = 230, P = 58, vals = series.map(s => +s[key] || 0), max = Math.max(1, ...vals) * 1.12;
        const x = i => P + i * ((W - P * 2) / Math.max(1, vals.length - 1)), y = v => Hh - 26 - (v / max) * (Hh - 50);
        const pts = vals.map((v, i) => x(i) + ',' + y(v)).join(' ');
        const area = 'M' + x(0) + ',' + (Hh - 26) + ' L' + vals.map((v, i) => x(i) + ',' + y(v)).join(' L') + ' L' + x(vals.length - 1) + ',' + (Hh - 26) + ' Z';
        const ticks = [0, .5, 1].map(f => '<line x1="' + P + '" x2="' + (W - P) + '" y1="' + y(max * f / 1.12) + '" y2="' + y(max * f / 1.12) + '" class="ai-grid"/><text x="' + (P - 6) + '" y="' + (y(max * f / 1.12) + 4) + '" class="ai-axis" text-anchor="end">' + esc(money(Math.round(max * f / 1.12))) + '</text>').join('');
        return '<svg viewBox="0 0 ' + W + ' ' + Hh + '" class="ai-chart" role="img" aria-label="MRR">' + ticks +
            '<path d="' + area + '" fill="' + color + '" opacity=".12"/><polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="2.5" stroke-linejoin="round"/>' +
            vals.map((v, i) => '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="3.5" fill="' + color + '"><title>' + esc(series[i].month + ': ' + money(v)) + '</title></circle>').join('') +
            series.map((s, i) => '<text x="' + x(i) + '" y="' + (Hh - 6) + '" class="ai-axis" text-anchor="middle">' + esc(s.month.slice(2)) + '</text>').join('') + '</svg>';
    }
    function movementChart(series) {
        const W = 1100, Hh = 220, P = 30, n = series.length, bw = Math.min(30, (W - P * 2) / n / 2.6);
        const max = Math.max(1, ...series.map(s => Math.max(+s.new_mrr, +s.churned_mrr))) * 1.15, mid = Hh / 2 - 6;
        const x = i => P + (i + .5) * ((W - P * 2) / n), h = v => (v / max) * (mid - 12);
        return '<svg viewBox="0 0 ' + W + ' ' + Hh + '" class="ai-chart" role="img" aria-label="حركة الإيراد"><line x1="' + P + '" x2="' + (W - P) + '" y1="' + mid + '" y2="' + mid + '" class="ai-grid"/>' +
            series.map((s, i) => '<rect x="' + (x(i) - bw / 2) + '" y="' + (mid - h(+s.new_mrr)) + '" width="' + bw + '" height="' + h(+s.new_mrr) + '" rx="3" class="ai-bar-good"><title>' + esc(s.month + ' جديد: ' + money(s.new_mrr) + ' (' + s.new_subs + ')') + '</title></rect>' +
                '<rect x="' + (x(i) - bw / 2) + '" y="' + mid + '" width="' + bw + '" height="' + h(+s.churned_mrr) + '" rx="3" class="ai-bar-bad"><title>' + esc(s.month + ' راح: ' + money(s.churned_mrr) + ' (' + s.churned_subs + ')') + '</title></rect>' +
                '<text x="' + x(i) + '" y="' + (Hh - 4) + '" class="ai-axis" text-anchor="middle">' + esc(s.month.slice(2)) + '</text>').join('') +
            '<text x="' + (W - P) + '" y="16" class="ai-axis" text-anchor="end">▲ إيراد جديد · ▼ إيراد راح بالإلغاء</text></svg>';
    }
    async function pageMetrics(root) {
        let months = 12;
        const draw = () => load(root, '/api/admin/insights/metrics?months=' + months, d => {
            const k = d.kpis, def = d.definitions;
            const cohortHead = '<tr><th>شهر أول اشتراك</th><th>العدد</th>' + Array.from({ length: d.cohorts.length }, (_, i) => '<th class="ap-c">' + (i === 0 ? 'أول شهر' : 'بعد ' + i) + '</th>').join('') + '</tr>';
            const cohortRows = d.cohorts.map(c => '<tr><td class="ap-mono">' + esc(c.month) + '</td><td class="ap-mono">' + c.size + '</td>' + Array.from({ length: d.cohorts.length }, (_, i) => {
                const v = c.retention[i]; if (v === undefined) return '<td></td>'; if (v === null || !c.size) return '<td class="ap-c p-cell-muted">—</td>';
                return '<td class="ap-c ai-heat" style="--h:' + (v / 100) + '">' + v + '%</td>';
            }).join('') + '</tr>').join('');
            const last = d.series[d.series.length - 1] || {};
            root.innerHTML =
                '<div class="ap-bar"><div class="ap-seg">' + [6, 12, 24].map(m => '<button data-m="' + m + '" aria-pressed="' + (m === months) + '">' + m + ' شهر</button>').join('') + '</div><span class="ap-spacer"></span><span class="ap-sub">مقارنة بقبل 30 يوم</span></div>' +
                '<div class="ap-panel ap-pulse">' + kpi('MRR', k.mrr, def.mrr) + kpi('ARR', k.arr, def.arr) + kpi('عملاء بيدفعوا', k.paying) + kpi('ARPU', k.arpu, def.arpu) + kpi('الإيراد اللي دخل (30 يوم)', k.cash_30d, def.cash_30d) + '</div>' +
                '<div class="ap-panel ap-pulse" style="margin-top:12px">' + kpi('الإلغاء (عملاء)', k.logo_churn, def.logo_churn) + kpi('الإلغاء (إيراد)', k.revenue_churn, def.revenue_churn) + kpi('قيمة العميل LTV', Object.assign({}, k.ltv, { hint: k.ltv.value === null ? 'محتاج إلغاءات أكتر عشان يتحسب' : '' }), def.ltv) + kpi('تحويل التجربة', k.trial_conversion, def.trial_conversion) +
                kpi('صافي الجديد (الشهر ده)', { value: (+last.new_mrr || 0) - (+last.churned_mrr || 0), format: 'money', hint: '+' + money(last.new_mrr) + ' / −' + money(last.churned_mrr) }) + '</div>' +
                '<div class="ap-grid main">' + panel('MRR شهر بشهر', 'الإيراد الشهري المتكرر في آخر كل شهر', lineChart(d.series, 'mrr', 'var(--panel-accent, #3b5bfd)')) +
                panel('حسب الباقة', '', d.plans.length ? table(['الباقة', 'مشتركين', 'MRR'], d.plans.map(p => '<tr><td>' + esc(p.plan) + '</td><td class="ap-mono">' + p.subs + '</td><td class="ap-mono is-money">' + money(p.mrr) + '</td></tr>')) : emptyState('◎', 'مفيش اشتراكات', 'أول ما حد يشترك هيظهر هنا.')) + '</div>' +
                panel('حركة الإيراد', 'الإيراد الجديد من اشتراكات جديدة مقابل اللي راح بالإلغاء', movementChart(d.series)) +
                panel('Cohorts: العملاء بيفضلوا قد إيه؟', 'كل صف = العملاء اللي اشتركوا أول مرة في الشهر ده، والأرقام = كام % منهم لسه بيدفع بعد كام شهر', '<div class="p-table-scroll"><table class="p-table ai-cohorts">' + cohortHead + cohortRows + '</table></div>') +
                panel('آخر الإلغاءات (60 يوم)', 'اعرف ليه بيمشوا', table(['العميل', 'الباقة', 'MRR', 'السبب', 'امتى'], d.churned_recent.map(c => '<tr class="ai-click" data-user="' + c.user_id + '"><td><b>' + esc(c.name) + '</b><br><span class="p-cell-muted">' + esc(c.email) + '</span></td><td>' + esc(c.plan || '—') + '</td><td class="ap-mono is-money">' + money(c.mrr) + '</td><td>' + esc(c.reason || '—') + '</td><td class="p-cell-muted">' + esc(ago(c.ended_at)) + '</td></tr>'), 'مفيش إلغاءات آخر 60 يوم 🎉'));
            root.onclick = e => {
                const b = e.target.closest('[data-m]'); if (b) { months = +b.dataset.m; return draw(); }
                const u = e.target.closest('[data-user]'); if (u && window.AdminPro.openUser) window.AdminPro.openUser(+u.dataset.user);
            };
        });
        draw();
    }

    // ================================================================== صحة العملاء
    const BAND = { healthy: ['green', 'كويس'], watch: ['orange', 'تحت المتابعة'], at_risk: ['red', 'في خطر'] };
    async function pageCustomers(root) {
        const st = { segment: 'at_risk', q: '' };
        const sel = new Set();
        let views = [];
        const loadViews = async () => { const r = await api('/api/admin/insights/views?page=customers'); views = r.success ? r.data.views : []; };
        async function draw() {
            const r = await api('/api/admin/insights/customers?' + new URLSearchParams(st));
            if (!r.success) { root.innerHTML = failedBox(r.error); return; }
            const d = r.data, c = d.counts;
            sel.forEach(id => { if (!d.items.some(i => i.id === id)) sel.delete(id); });
            const segs = [['at_risk', 'في خطر', d.at_risk_listed != null ? d.at_risk_listed : c.at_risk], ['watch', 'تحت المتابعة', c.watch], ['healthy', 'كويسين', c.healthy], ['unprofitable', 'بيخسّروا', null], ['paying', 'بيدفعوا', null], ['all', 'الكل', null]];
            const rows = d.items.map(i => '<tr data-user="' + i.id + '" class="ai-click"><td class="ai-sel"><input type="checkbox" data-sel="' + i.id + '"' + (sel.has(i.id) ? ' checked' : '') + ' aria-label="اختيار"></td>' +
                '<td><b>' + esc(i.name) + '</b><br><span class="p-cell-muted">' + esc(i.email) + '</span>' + (i.tags && i.tags.length ? '<div class="ai-tags">' + i.tags.map(t => '<span class="ai-tag">' + esc(t) + '</span>').join('') + '</div>' : '') + '</td>' +
                '<td><div class="ai-score"><div class="ai-score-bar ' + i.band + '" style="width:' + i.score + '%"></div></div><span class="ap-mono">' + i.score + '</span> ' + pill(BAND[i.band][1], BAND[i.band][0]) + '</td>' +
                '<td>' + esc(i.plan || 'مجاني') + (i.status === 'past_due' ? ' ' + pill('متأخر', 'red') : '') + '<br><span class="ap-mono is-money">' + (i.mrr ? money(i.mrr) + '/شهر' : '') + '</span></td>' +
                '<td class="p-cell-muted">' + esc(i.last_seen ? ago(i.last_seen) : 'ولا مرة') + '</td>' +
                '<td class="ai-reasons">' + (i.reasons.length ? i.reasons.map(x => '<span>' + esc(x) + '</span>').join('') : '<span class="p-cell-muted">—</span>') + '</td>' +
                '<td class="ap-mono"><span class="' + (i.margin_30d < 0 ? 'ai-neg' : '') + ' is-money">' + money(i.margin_30d) + '</span><br><small class="p-cell-muted">AI ' + money(i.ai_cost_30d) + '</small></td></tr>');
            const p = d.profit;
            root.innerHTML =
                (d.mrr_at_risk > 0 ? '<div class="ai-banner warn"><div class="ai-banner-ico">!</div><div><b>' + money(d.mrr_at_risk) + ' إيراد شهري في خطر</b><div>' + (d.at_risk_listed != null ? d.at_risk_listed : c.at_risk) + ' عميل درجتهم أقل من 40. كلّمهم قبل ما يلغوا - السبب مكتوب جنب كل واحد.</div></div></div>' : '') +
                '<div class="ap-bar"><div class="ap-seg">' + segs.map(s => '<button data-seg="' + s[0] + '" aria-pressed="' + (st.segment === s[0]) + '">' + s[1] + (s[2] !== null ? ' <span class="ap-mono">' + s[2] + '</span>' : '') + '</button>').join('') + '</div>' +
                '<input type="search" class="p-input" data-q placeholder="ابحث بالاسم أو الإيميل…" value="' + esc(st.q) + '" style="max-width:240px"><span class="ap-spacer"></span>' +
                views.map(v => '<span class="ai-view"><button class="p-btn outline xs" data-view="' + v.id + '">' + esc(v.name) + '</button><button class="ai-view-x" data-delview="' + v.id + '" aria-label="مسح">×</button></span>').join('') +
                '<button class="p-btn outline xs" data-saveview>+ احفظ الفلتر</button></div>' +
                '<div class="ai-bulk' + (sel.size ? ' on' : '') + '"><b>' + sel.size + ' مختار</b>' +
                (can('users.notes') ? '<button class="p-btn outline xs" data-bulk="tag">إضافة تاج</button><button class="p-btn outline xs" data-bulk="untag">شيل تاج</button>' : '') +
                (can('broadcast.send') ? '<button class="p-btn outline xs" data-bulk="notify">إشعار</button>' : '') +
                (can('users.suspend') ? '<button class="p-btn outline xs" data-bulk="activate">تفعيل</button><button class="p-btn danger xs" data-bulk="suspend">إيقاف</button>' : '') +
                '<button class="p-btn outline xs" data-bulk="export">تصدير CSV</button><span class="ap-spacer"></span><button class="ap-x" data-clearsel aria-label="إلغاء الاختيار" style="position:static">×</button></div>' +
                '<section class="ap-panel">' + (d.items.length ? table(['<input type="checkbox" data-selall aria-label="الكل">', 'العميل', 'الصحة', 'الباقة', 'آخر دخول', 'ليه', 'الربح (30 يوم)'], rows).replace('&lt;input type=&quot;checkbox&quot; data-selall aria-label=&quot;الكل&quot;&gt;', '<input type="checkbox" data-selall aria-label="الكل">') :
                    emptyState('✓', st.segment === 'at_risk' ? 'مفيش عملاء في خطر' : 'مفيش عملاء هنا', st.segment === 'at_risk' ? 'كل العملاء اللي بيدفعوا حالتهم كويسة دلوقتي.' : 'جرّب فلتر تاني.')) + '</section>' +
                panel('الربحية (آخر 30 يوم)', 'الإيراد اللي اتخصم من محافظ العملاء ناقص تكلفة الذكاء الاصطناعي اللي استهلكوها',
                    '<div class="ap-panel ap-pulse ai-pulse4" style="box-shadow:none">' + kpi('الإيراد', { value: p.revenue_30d, format: 'money' }) + kpi('تكلفة AI', { value: p.ai_cost_30d, format: 'money' }) + kpi('الربح', { value: p.margin_30d, format: 'money' }) + kpi('هامش الربح', { value: p.margin_pct, format: 'pct' }) + '</div>' +
                    table(['الباقة', 'عملاء', 'السعر الشهري', 'متوسط تكلفة AI للعميل', 'الهامش'], p.by_plan.map(b => '<tr><td>' + esc(b.plan) + '</td><td class="ap-mono">' + b.customers + '</td><td class="ap-mono is-money">' + money(b.price) + '</td><td class="ap-mono is-money">' + money(b.avg_ai_cost) + '</td><td>' + (b.margin_pct === null ? '—' : pill(pct(b.margin_pct), b.margin_pct < 30 ? 'red' : (b.margin_pct < 60 ? 'orange' : 'green'))) + '</td></tr>'), 'مفيش باقات مدفوعة'));
            const all = $('[data-selall]', root); if (all) all.checked = d.items.length > 0 && d.items.every(i => sel.has(i.id));
            root._items = d.items;
        }
        await loadViews(); await draw();
        let t;
        root.oninput = e => { if (e.target.matches('[data-q]')) { clearTimeout(t); t = setTimeout(() => { st.q = e.target.value.trim(); draw().then(() => { const q = $('[data-q]', root); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 350); } };
        root.onchange = e => {
            if (e.target.matches('[data-sel]')) { const id = +e.target.dataset.sel; e.target.checked ? sel.add(id) : sel.delete(id); draw(); }
            if (e.target.matches('[data-selall]')) { (root._items || []).forEach(i => e.target.checked ? sel.add(i.id) : sel.delete(i.id)); draw(); }
        };
        root.onclick = async e => {
            if (e.target.closest('.ai-sel') || e.target.matches('[data-selall]')) return;
            const sg = e.target.closest('[data-seg]'); if (sg) { st.segment = sg.dataset.seg; return draw(); }
            if (e.target.closest('[data-clearsel]')) { sel.clear(); return draw(); }
            const vw = e.target.closest('[data-view]'); if (vw) { const v = views.find(x => x.id == vw.dataset.view); if (v) { Object.assign(st, { segment: v.filters.segment || 'all', q: v.filters.q || '' }); return draw(); } }
            const dv = e.target.closest('[data-delview]'); if (dv) { await api('/api/admin/insights/views/' + dv.dataset.delview, 'DELETE'); await loadViews(); return draw(); }
            if (e.target.closest('[data-saveview]')) {
                const m = modal('<h3>احفظ الفلتر ده</h3><label class="ap-lbl">الاسم</label><input class="p-input" id="vwName" maxlength="80" placeholder="مثلاً: عملاء مهمين في خطر"><div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>');
                $('#vwName', m.bg).focus(); $('[data-x]', m.bg).onclick = m.close;
                $('[data-ok]', m.bg).onclick = async () => { const r = await api('/api/admin/insights/views', 'POST', { page: 'customers', name: $('#vwName', m.bg).value, filters: st }); if (r.success) { m.close(); await loadViews(); draw(); } else toast(r.error, 'error'); };
                return;
            }
            const bk = e.target.closest('[data-bulk]'); if (bk) return bulkAction(bk.dataset.bulk, [...sel], root._items || [], () => { if (bk.dataset.bulk === 'suspend' || bk.dataset.bulk === 'activate') sel.clear(); draw(); });
            const u = e.target.closest('[data-user]'); if (u && window.AdminPro.openUser) window.AdminPro.openUser(+u.dataset.user);
        };
    }
    // ================================================================ استخدام المميزات
    // كل عميل وصل لحد فين في طريق أول حجز + أنهي مميزات بيستخدمها. الضغط على عميل بيفتح ملفه.
    const STAGE_COLOR = { site: 'gray', tour: 'gray', availability: 'orange', payments: 'orange', first_booking: 'blue', booking: 'green' };
    const FEAT_ICON = { trip_reminder: '⏰', morning_brief: '☀️', review_auto: '⭐', payment_links: '💳', manual_payments: '💵', chat_bookings: '💬', kb: '🤖', online_payments: '🔐' };
    async function pageAdoption(root) {
        const st = { stage: '', q: '' };
        let featLabels = {};
        async function draw() {
            root.innerHTML = skeleton(140) + skeleton(260);
            const r = await api('/api/admin/insights/adoption?' + new URLSearchParams(st));
            if (!r.success) { root.innerHTML = failedBox(r.error); return; }
            const d = r.data;
            d.features.forEach(f => { featLabels[f.key] = f.label; });
            const maxF = Math.max(1, ...d.funnel.map(f => f.count));
            const funnel = d.funnel.map(f => '<button class="ai-adopt-step' + (st.stage === f.key ? ' on' : '') + '" data-stage="' + f.key + '">'
                + '<span class="ai-adopt-n ap-mono">' + f.count + '</span><span class="ai-adopt-l">' + esc(f.label) + '</span>'
                + '<span class="ai-adopt-bar"><i style="width:' + Math.round(f.count / maxF * 100) + '%"></i></span></button>').join('');
            const feats = d.features.map(f => '<div class="ai-adopt-feat"><span>' + (FEAT_ICON[f.key] || '') + ' ' + esc(f.label) + '</span><b class="ap-mono">' + f.count + '</b><small>' + f.pct + '%</small></div>').join('');
            const rows = d.items.map(i => '<tr data-user="' + i.id + '" class="ai-click">'
                + '<td><b>' + esc(i.name) + '</b><br><span class="p-cell-muted">' + esc(i.email) + '</span>' + (i.tags && i.tags.length ? '<div class="ai-tags">' + i.tags.map(t => '<span class="ai-tag">' + esc(t) + '</span>').join('') + '</div>' : '') + '</td>'
                + '<td>' + pill(i.stage_label, STAGE_COLOR[i.stage] || 'gray') + '</td>'
                + '<td class="ap-mono">' + i.bookings_30d + (i.bookings_total ? ' <small class="p-cell-muted">/ ' + i.bookings_total + '</small>' : '') + '<br><span class="is-money">' + (i.revenue_30d ? money(i.revenue_30d) : '') + '</span></td>'
                + '<td style="font-size:16px;letter-spacing:2px;">' + (i.features.length ? i.features.map(k => '<span title="' + esc(featLabels[k] || k) + '">' + (FEAT_ICON[k] || '•') + '</span>').join('') : '<span class="p-cell-muted" style="font-size:12px;letter-spacing:0;">ولا ميزة</span>') + '</td>'
                + '<td class="p-cell-muted">' + esc(i.last_login ? ago(i.last_login) : 'ولا مرة') + '<br><small>اشترك ' + esc(ago(i.created_at)) + '</small></td></tr>');
            root.innerHTML =
                '<style>.ai-adopt-funnel{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}'
                + '.ai-adopt-step{display:flex;flex-direction:column;gap:6px;text-align:start;padding:12px;border:1px solid var(--panel-border);border-radius:12px;background:var(--panel-card-bg);cursor:pointer;color:inherit;font:inherit}'
                + '.ai-adopt-step.on{border-color:var(--panel-accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--panel-accent) 25%,transparent)}'
                + '.ai-adopt-n{font-size:24px;font-weight:800}.ai-adopt-l{font-size:12.5px;color:var(--panel-text-muted)}'
                + '.ai-adopt-bar{height:5px;border-radius:3px;background:var(--panel-border);overflow:hidden}.ai-adopt-bar i{display:block;height:100%;background:var(--panel-accent)}'
                + '.ai-adopt-feats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}'
                + '.ai-adopt-feat{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--panel-border);border-radius:10px}.ai-adopt-feat span{flex:1;font-size:13px}.ai-adopt-feat small{color:var(--panel-text-muted)}</style>'
                + panel('طريق أول حجز', d.total + ' عميل - كل رقم = عملاء واقفين عند الخطوة دي. اضغط على خطوة تشوف العملاء دول بس.', '<div class="ai-adopt-funnel">' + funnel + '</div>',
                    st.stage ? '<button class="p-btn outline xs" data-stage="">عرض الكل</button>' : '')
                + panel('المميزات بتتستخدم قد إيه', 'عدد ونسبة العملاء اللي مفعّلين كل ميزة', '<div class="ai-adopt-feats">' + feats + '</div>')
                + '<div class="ap-bar"><input type="search" class="p-input" data-q placeholder="ابحث بالاسم أو الإيميل…" value="' + esc(st.q) + '" style="max-width:260px"><span class="ap-spacer"></span>'
                + '<button class="p-btn outline xs" data-export>تصدير CSV</button></div>'
                + '<section class="ap-panel">' + (d.items.length ? table(['العميل', 'وصل لحد', 'حجوزات 30 يوم', 'المميزات', 'آخر دخول'], rows)
                    : emptyState('✓', 'مفيش عملاء هنا', st.stage ? 'مفيش حد واقف عند الخطوة دي.' : 'لسه مفيش عملاء.')) + '</section>';
            root._items = d.items;
        }
        await draw();
        let t;
        root.oninput = e => { if (e.target.matches('[data-q]')) { clearTimeout(t); t = setTimeout(() => { st.q = e.target.value.trim(); draw().then(() => { const q = $('[data-q]', root); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 350); } };
        root.onclick = e => {
            const sg = e.target.closest('[data-stage]'); if (sg) { st.stage = st.stage === sg.dataset.stage ? '' : sg.dataset.stage; return draw(); }
            if (e.target.closest('[data-export]')) {
                const items = root._items || [];
                const head = ['id', 'name', 'email', 'stage', 'bookings_30d', 'bookings_total', 'revenue_30d', 'features', 'last_login', 'created_at'];
                const csvCell = v => '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"';
                const csv = [head.join(',')].concat(items.map(i => [i.id, i.name, i.email, i.stage_label, i.bookings_30d, i.bookings_total, i.revenue_30d, i.features.map(k => featLabels[k] || k).join(' | '), i.last_login || '', i.created_at].map(csvCell).join(','))).join('\n');
                const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' })); a.download = 'tourfecto-adoption.csv'; a.click();
                return;
            }
            const u = e.target.closest('[data-user]'); if (u && window.AdminPro.openUser) window.AdminPro.openUser(+u.dataset.user);
        };
    }

    // ================================================================ أخطاء النظام
    // الأخطاء من ملفات اللوج متجمّعة: نفس الغلط بأرقام مختلفة = سطر واحد. الضغط على سطر بيفتح آخر مثال.
    async function pageErrors(root) {
        const st = { hours: '24', level: 'error', source: '', q: '' };
        const open = new Set();
        let last = null;
        async function draw(refetch) {
            if (refetch !== false || !last) {
                root.innerHTML = skeleton(140) + skeleton(260);
                const r = await api('/api/admin/insights/errors?' + new URLSearchParams(st));
                if (!r.success) { root.innerHTML = failedBox(r.error); return; }
                last = r.data;
            }
            const d = last, t = d.totals;
            const maxH = Math.max(1, ...d.hourly.map(h => h.error + h.warning));
            const bars = d.hourly.map(h => {
                const lt = parseUTC(h.hour.replace(' ', 'T') + ':00Z');
                const lbl = lt && !isNaN(lt) ? pad(lt.getHours()) + ':00' : h.hour;
                return '<div class="ai-err-col" title="' + esc(lbl) + ' — ' + h.error + ' خطأ، ' + h.warning + ' تحذير">'
                    + '<i class="w" style="height:' + Math.round(h.warning / maxH * 100) + '%"></i><i class="e" style="height:' + Math.round(h.error / maxH * 100) + '%"></i></div>';
            }).join('');
            const rows = d.groups.map(g => '<tr class="ai-click" data-g="' + esc(g.key) + '">'
                + '<td>' + pill(g.level === 'error' ? 'خطأ' : 'تحذير', g.level === 'error' ? 'red' : 'orange') + (g.source === 'php' ? ' ' + pill('PHP', 'gray') : '') + '</td>'
                + '<td style="max-width:520px"><div class="ai-err-msg">' + esc(g.message) + '</div>'
                + (open.has(g.key) ? '<div class="ai-err-detail"><div class="ap-sub">آخر مثال:</div><pre>' + esc(g.sample) + (g.sample_context ? '\n\n' + esc(g.sample_context) : '') + '</pre></div>' : '') + '</td>'
                + '<td class="ap-mono"><b>' + g.count + '</b></td>'
                + '<td class="p-cell-muted" title="' + esc(dt(g.last_seen)) + '">' + esc(ago(g.last_seen)) + '<br><small>أول مرة ' + esc(ago(g.first_seen)) + '</small></td></tr>');
            const seg = (key, opts) => '<div class="ap-seg">' + opts.map(o => '<button data-k="' + key + '" data-v="' + o[0] + '" aria-pressed="' + (st[key] === o[0]) + '">' + o[1] + '</button>').join('') + '</div>';
            const big = d.files.filter(f => f.exists && f.size > f.scanned);
            root.innerHTML =
                '<style>.ai-err-chart{display:flex;align-items:flex-end;gap:3px;height:90px;padding-top:6px}'
                + '.ai-err-col{flex:1;display:flex;flex-direction:column-reverse;height:100%;min-width:4px;background:color-mix(in srgb,var(--panel-border) 40%,transparent);border-radius:3px;overflow:hidden}'
                + '.ai-err-col i{display:block;width:100%}.ai-err-col i.e{background:#e5484d}.ai-err-col i.w{background:#f5a524}'
                + '.ai-err-msg{font-family:ui-monospace,Menlo,Consolas,monospace,sans-serif;font-size:12.5px;line-height:1.6;word-break:break-word;unicode-bidi:plaintext;text-align:start}'
                + '.ai-err-detail{margin-top:8px}.ai-err-detail pre{unicode-bidi:plaintext;text-align:start;white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:260px;overflow:auto;padding:10px;border-radius:8px;background:color-mix(in srgb,var(--panel-border) 35%,transparent);margin:4px 0 0}</style>'
                + '<div class="ap-panel ap-pulse ai-pulse4">'
                + kpi('أخطاء آخر ساعة', { value: t.last_hour_errors, format: 'int', hint: t.last_hour_errors >= 20 ? 'كتير - بيوصلك تنبيه' : 'التنبيه بيشتغل من 20' })
                + kpi('أخطاء (' + d.hours + ' ساعة)', { value: t.error, format: 'int', hint: 'كل سطر خطأ في اللوج' })
                + kpi('تحذيرات (' + d.hours + ' ساعة)', { value: t.warning, format: 'int', hint: 'مش بتوقف حاجة غالبًا' })
                + kpi('أنواع مختلفة', { value: d.groups.length, format: 'int', hint: 'بعد تجميع المتشابه' }) + '</div>'
                + panel('آخر 24 ساعة', 'كل عمود = ساعة (أحمر خطأ، برتقالي تحذير) بتوقيت جهازك', '<div class="ai-err-chart">' + bars + '</div>')
                + '<div class="ap-bar" style="margin-top:16px">'
                + seg('level', [['error', 'أخطاء'], ['warning', 'تحذيرات'], ['', 'الكل']])
                + seg('hours', [['1', 'ساعة'], ['24', 'يوم'], ['168', 'أسبوع']])
                + seg('source', [['', 'كل الملفات'], ['app', 'app.log'], ['php', 'PHP']])
                + '<input type="search" class="p-input" data-q placeholder="ابحث في الرسالة…" value="' + esc(st.q) + '" style="max-width:240px"><span class="ap-spacer"></span>'
                + '<button class="p-btn outline xs" data-reload-errs>تحديث</button></div>'
                + (big.length ? '<div class="ap-note" style="margin:10px 0 0">بنقرا آخر جزء بس من ' + big.map(f => esc(f.name) + ' (' + Math.round(f.size / 1048576) + 'MB)').join(' و ') + ' - لو عايز تشوف أقدم من كده افتح الملف من السيرفر.</div>' : '')
                + '<section class="ap-panel" style="margin-top:12px">' + (d.groups.length ? table(['النوع', 'الرسالة (اضغط تشوف التفاصيل)', 'مرات', 'آخر مرة'], rows)
                    : emptyState('✓', 'مفيش أخطاء', 'مفيش حاجة متسجلة في الفترة دي بالفلتر ده.')) + '</section>';
        }
        await draw();
        let tm;
        root.oninput = e => { if (e.target.matches('[data-q]')) { clearTimeout(tm); tm = setTimeout(() => { st.q = e.target.value.trim(); draw().then(() => { const q = $('[data-q]', root); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 400); } };
        root.onclick = e => {
            const b = e.target.closest('[data-k]'); if (b) { st[b.dataset.k] = b.dataset.v; return draw(); }
            if (e.target.closest('[data-reload-errs]')) return draw();
            if (e.target.closest('pre')) return; // عشان تقدر تنسخ من غير ما يتقفل
            const g = e.target.closest('[data-g]'); if (g) { open.has(g.dataset.g) ? open.delete(g.dataset.g) : open.add(g.dataset.g); draw(false); }
        };
    }

    // ================================================================== المدونة
    // مقالات tourfecto.pro/blog: كل مقال بلغة واحدة (عربي أو إنجليزي) ومكتوب Markdown بسيط.
    async function pageBlog(root) {
        const st = { q: '', lang: '' };
        let items = [];
        async function draw(refetch) {
            if (refetch !== false) {
                root.innerHTML = skeleton(140) + skeleton(260);
                const r = await api('/api/admin/insights/blog');
                if (!r.success) { root.innerHTML = failedBox(r.error); return; }
                items = r.data.items || [];
            }
            const q = st.q.toLowerCase();
            const list = items.filter(p => (!st.lang || p.lang === st.lang) && (!q || (p.title + ' ' + p.slug + ' ' + (p.tags || '')).toLowerCase().includes(q)));
            const pub = items.filter(p => p.status === 'published').length;
            const views = items.reduce((a, p) => a + (+p.views || 0), 0);
            const rows = list.map(p => '<tr data-id="' + p.id + '" class="ai-click"><td><b>' + esc(p.title) + '</b><br><span class="p-cell-muted" dir="ltr">/blog/' + esc(p.slug) + '</span></td>'
                + '<td>' + pill(p.lang === 'en' ? 'English' : 'عربي', 'gray') + '</td>'
                + '<td>' + (p.status === 'published' ? pill(parseUTC(p.published_at) > new Date() ? 'مجدول' : 'منشور', parseUTC(p.published_at) > new Date() ? 'blue' : 'green') : pill('مسودة', 'orange')) + '</td>'
                + '<td class="p-cell-muted">' + esc(p.published_at ? dt(p.published_at) : '—') + '</td><td class="ap-mono">' + (+p.views || 0) + '</td>'
                + '<td>' + (p.status === 'published' ? '<a class="p-btn outline xs" href="/blog/' + encodeURIComponent(p.slug) + '?lang=' + p.lang + '" target="_blank" rel="noopener" data-stop>فتح ↗</a>' : '') + '</td></tr>');
            root.innerHTML = '<div class="ap-panel ap-pulse ai-pulse4">'
                + kpi('كل المقالات', { value: items.length, format: 'int', hint: 'منشور + مسودات' })
                + kpi('منشورة', { value: pub, format: 'int', hint: 'ظاهرة في /blog' })
                + kpi('مسودات', { value: items.length - pub, format: 'int', hint: 'مش ظاهرة للزوار' })
                + kpi('القراءات', { value: views, format: 'int', hint: 'مجموع فتح المقالات' }) + '</div>'
                + '<div class="ap-bar" style="margin-top:16px"><div class="ap-seg">' + [['', 'الكل'], ['ar', 'عربي'], ['en', 'English']].map(o => '<button data-lang="' + o[0] + '" aria-pressed="' + (st.lang === o[0]) + '">' + o[1] + '</button>').join('') + '</div>'
                + '<input type="search" class="p-input" data-q placeholder="ابحث في العناوين…" value="' + esc(st.q) + '" style="max-width:240px"><span class="ap-spacer"></span>'
                + '<a class="p-btn outline xs" href="/blog" target="_blank" rel="noopener">المدونة ↗</a><button class="p-btn primary" data-new>+ مقال جديد</button></div>'
                + '<section class="ap-panel" style="margin-top:12px">' + (list.length ? table(['المقال', 'اللغة', 'الحالة', 'النشر', 'قراءات', ''], rows)
                    : emptyState('✍️', items.length ? 'مفيش نتايج' : 'لسه مفيش مقالات', items.length ? 'غيّر البحث أو الفلتر.' : 'اكتب أول مقال - نصايح عملية لشركات الرحلات بتجيب زيارات من جوجل.', items.length ? '' : '<button class="p-btn primary" data-new>+ اكتب أول مقال</button>')) + '</section>';
        }
        await draw();
        let tm;
        root.oninput = e => { if (e.target.matches('[data-q]')) { clearTimeout(tm); tm = setTimeout(() => { st.q = e.target.value.trim(); draw(false).then(() => { const q = $('[data-q]', root); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 250); } };
        root.onclick = e => {
            if (e.target.closest('[data-stop]')) return;
            const l = e.target.closest('[data-lang]'); if (l) { st.lang = l.dataset.lang; return draw(false); }
            if (e.target.closest('[data-new]')) return blogModal(null, () => draw());
            const r = e.target.closest('tr[data-id]'); if (r) blogModal(items.find(p => +p.id === +r.dataset.id), () => draw());
        };
    }
    function blogModal(p, after) {
        p = p || { title: '', slug: '', lang: 'ar', status: 'draft', excerpt: '', body: '', cover_url: '', tags: '', meta_description: '', published_at: '' };
        const local = s => { const d = parseUTC(s); return d && !isNaN(d) ? d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) : ''; };
        const m = modal('<h3>' + (p.id ? 'تعديل المقال' : 'مقال جديد') + '</h3>'
            + '<label class="ap-lbl">العنوان</label><input class="p-input" id="bT" value="' + esc(p.title) + '" maxlength="220" placeholder="5 طرق تزوّد بيها حجوزات رحلاتك من جوجل">'
            + '<div class="ai-form2"><div><label class="ap-lbl">اللغة</label><select class="p-select" id="bL"><option value="ar"' + (p.lang !== 'en' ? ' selected' : '') + '>عربي</option><option value="en"' + (p.lang === 'en' ? ' selected' : '') + '>English</option></select></div>'
            + '<div><label class="ap-lbl">الحالة</label><select class="p-select" id="bS"><option value="draft"' + (p.status !== 'published' ? ' selected' : '') + '>مسودة</option><option value="published"' + (p.status === 'published' ? ' selected' : '') + '>منشور</option></select></div>'
            + '<div><label class="ap-lbl">ميعاد النشر <small>(فاضي = دلوقتي)</small></label><input type="datetime-local" class="p-input" id="bD" value="' + esc(local(p.published_at)) + '"></div></div>'
            + '<label class="ap-lbl">الرابط (slug) <small>- فاضي = من العنوان</small></label><input class="p-input" id="bU" dir="ltr" value="' + esc(p.slug) + '" maxlength="120" placeholder="more-bookings-from-google">'
            + '<label class="ap-lbl">ملخص قصير <small>(بيظهر في قايمة المقالات)</small></label><textarea class="p-input" id="bX" rows="2" maxlength="400">' + esc(p.excerpt || '') + '</textarea>'
            + '<div style="display:flex;gap:6px;margin:14px 0 6px" class="ap-seg" id="bTabs"><button type="button" data-tab="w" aria-pressed="true">كتابة</button><button type="button" data-tab="p" aria-pressed="false">معاينة</button></div>'
            + '<textarea class="p-input" id="bB" rows="14" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.7" placeholder="# عنوان فرعي&#10;&#10;فقرة عادية... **كلام عريض** و [لينك](https://...)&#10;&#10;- نقطة&#10;- نقطة تانية">' + esc(p.body || '') + '</textarea>'
            + '<div id="bPrev" class="ai-blog-prev" style="display:none;max-height:420px;overflow:auto;padding:14px 16px;border:1px solid var(--panel-border);border-radius:12px;line-height:1.9"></div>'
            + '<div class="p-cell-muted" style="font-size:12px;margin-top:6px"># عنوان · **عريض** · *مائل* · [نص](https://رابط) · ![وصف](https://صورة) · - قايمة · &gt; اقتباس</div>'
            + '<div class="ai-form2"><div><label class="ap-lbl">صورة الغلاف (رابط)</label><input class="p-input" id="bC" dir="ltr" value="' + esc(p.cover_url || '') + '" placeholder="https://…"></div>'
            + '<div><label class="ap-lbl">تاجات <small>(بفاصلة)</small></label><input class="p-input" id="bG" value="' + esc(p.tags || '') + '" maxlength="255" placeholder="حجوزات, SEO"></div></div>'
            + '<label class="ap-lbl">وصف جوجل <small>(اختياري - 160 حرف)</small></label><input class="p-input" id="bM" value="' + esc(p.meta_description || '') + '" maxlength="300">'
            + '<div class="ap-actions">' + (p.id ? '<button class="p-btn danger" data-del style="margin-inline-end:auto">مسح</button>' : '') + '<button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>', true);
        $('[data-x]', m.bg).onclick = m.close; $('#bT', m.bg).focus();
        $('#bTabs', m.bg).onclick = async e => {
            const b = e.target.closest('[data-tab]'); if (!b) return;
            $$('#bTabs button', m.bg).forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
            const prev = b.dataset.tab === 'p', pv = $('#bPrev', m.bg);
            $('#bB', m.bg).style.display = prev ? 'none' : ''; pv.style.display = prev ? '' : 'none';
            if (prev) { pv.dir = $('#bL', m.bg).value === 'en' ? 'ltr' : 'rtl'; pv.innerHTML = '<div class="p-cell-muted">…</div>'; const r = await api('/api/admin/insights/blog/preview', 'POST', { body: $('#bB', m.bg).value }); pv.innerHTML = r.success ? (r.data.html || '<div class="p-cell-muted">فاضي</div>') : esc(r.error || ''); }
        };
        $('[data-ok]', m.bg).onclick = async () => {
            const d = $('#bD', m.bg).value;
            const body = { title: $('#bT', m.bg).value, lang: $('#bL', m.bg).value, status: $('#bS', m.bg).value, slug: $('#bU', m.bg).value.trim(), excerpt: $('#bX', m.bg).value,
                body: $('#bB', m.bg).value, cover_url: $('#bC', m.bg).value.trim(), tags: $('#bG', m.bg).value, meta_description: $('#bM', m.bg).value,
                published_at: d ? new Date(d).toISOString().slice(0, 19).replace('T', ' ') : '' };
            const r = await api('/api/admin/insights/blog' + (p.id ? '/' + p.id : ''), p.id ? 'PUT' : 'POST', body);
            toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); }
        };
        const del = $('[data-del]', m.bg);
        if (del) del.onclick = async () => { if (!(await confirmDialog('مسح المقال؟', 'هيختفي من المدونة نهائيًا.', 'مسح', true))) return; const r = await api('/api/admin/insights/blog/' + p.id, 'DELETE'); toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); } };
    }

    async function bulkAction(action, ids, items, after) {
        if (!ids.length) return;
        if (action === 'export') {
            const rows = items.filter(i => ids.includes(i.id));
            const cols = ['id', 'name', 'email', 'plan', 'status', 'mrr', 'score', 'band', 'last_seen', 'revenue_30d', 'ai_cost_30d', 'margin_30d'];
            const csv = '﻿' + cols.join(',') + '\n' + rows.map(r => cols.map(c => '"' + String(r[c] === null || r[c] === undefined ? '' : r[c]).replace(/"/g, '""') + '"').join(',')).join('\n');
            const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'customers-' + new Date().toISOString().slice(0, 10) + '.csv'; a.click();
            return;
        }
        let body = { ids: ids, action: action };
        if (action === 'tag' || action === 'untag') {
            const tr = await api('/api/admin/insights/tags'); const known = tr.success ? tr.data.tags : [];
            const m = modal('<h3>' + (action === 'tag' ? 'إضافة تاج' : 'شيل تاج') + ' (' + ids.length + ' عميل)</h3><label class="ap-lbl">التاج</label><input class="p-input" id="tgName" maxlength="40" list="tgList" placeholder="VIP، شريك، محتاج متابعة…"><datalist id="tgList">' + known.map(k => '<option value="' + esc(k.tag) + '">').join('') + '</datalist>' +
                (known.length ? '<div class="ai-tags" style="margin-top:10px">' + known.slice(0, 12).map(k => '<button class="ai-tag" data-pick="' + esc(k.tag) + '">' + esc(k.tag) + ' · ' + k.n + '</button>').join('') + '</div>' : '') +
                '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>تطبيق</button></div>');
            $('#tgName', m.bg).focus(); $('[data-x]', m.bg).onclick = m.close;
            m.bg.addEventListener('click', e => { const p = e.target.closest('[data-pick]'); if (p) $('#tgName', m.bg).value = p.dataset.pick; });
            return new Promise(res => { $('[data-ok]', m.bg).onclick = async () => { body.tag = $('#tgName', m.bg).value; const r = await api('/api/admin/insights/users/bulk', 'POST', body); toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); } res(); }; });
        }
        if (action === 'notify') {
            const m = modal('<h3>إشعار لـ ' + ids.length + ' عميل</h3><p class="ap-sub">بيظهر في جرس الإشعارات جوه لوحة العميل.</p><label class="ap-lbl">العنوان</label><input class="p-input" id="ntT" maxlength="190"><label class="ap-lbl">النص</label><textarea class="p-input" id="ntB" rows="4" maxlength="2000"></textarea><label class="ap-lbl">رابط (اختياري)</label><input class="p-input" id="ntL" placeholder="/subscription" dir="ltr">' +
                '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>ابعت</button></div>');
            $('#ntT', m.bg).focus(); $('[data-x]', m.bg).onclick = m.close;
            return new Promise(res => { $('[data-ok]', m.bg).onclick = async () => { Object.assign(body, { title: $('#ntT', m.bg).value, body: $('#ntB', m.bg).value, link: $('#ntL', m.bg).value || null }); const r = await api('/api/admin/insights/users/bulk', 'POST', body); toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); } res(); }; });
        }
        const ok = await confirmDialog(action === 'suspend' ? 'إيقاف ' + ids.length + ' عميل؟' : 'تفعيل ' + ids.length + ' عميل؟', action === 'suspend' ? 'مش هيقدروا يدخلوا لحد ما تفعّلهم تاني. حسابات الأدمن مش بتتأثر.' : 'هيقدروا يدخلوا تاني عادي.', action === 'suspend' ? 'إيقاف' : 'تفعيل', action === 'suspend');
        if (!ok) return;
        const r = await api('/api/admin/insights/users/bulk', 'POST', body);
        toast(r.message || r.error, r.success ? 'success' : 'error');
        if (r.success) after();
    }

    // رحلة العميل: عمل إيه في المنصة، دخل أنهي صفحات، قعد قد إيه، ووصل لفين في التفعيل
    const cjDur = sec => { sec = Math.round(+sec || 0); if (sec < 60) return sec + ' ث'; const m = Math.floor(sec / 60); return m < 60 ? m + ' د' : Math.floor(m / 60) + ' س ' + (m % 60) + ' د'; };
    const CJ_STATE = { active: ['green', 'نشط'], idle: ['gray', 'ساكت 1-3 أيام'], dormant: ['red', 'خامل'], never_returned: ['red', 'دخل مرة ومرجعش'] };
    const CJ_LEVEL = { high: '#e5484d', medium: '#f5a524', low: '#8a8f98', info: '#3b82f6' };
    const cjStyle = () => {
        if (document.getElementById('cjStyle')) return;
        const st = document.createElement('style'); st.id = 'cjStyle';
        st.textContent = '.cj-box{margin:12px 0;padding:14px;border:1px solid var(--panel-border,#2a2f3a);border-radius:12px}.cj-box h4{margin:0 0 8px}.cj-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin:8px 0}.cj-grid div{padding:8px 10px;border:1px solid var(--panel-border,#2a2f3a);border-radius:10px}.cj-grid b{display:block;font-size:18px}.cj-ms{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.cj-ms span{padding:3px 10px;border-radius:999px;border:1px solid var(--panel-border,#2a2f3a);font-size:12px}.cj-ms .done{background:#1f9d5520;border-color:#1f9d55}.cj-next{margin:8px 0;padding:0;list-style:none}.cj-next li{padding:6px 10px;margin:4px 0;border-inline-start:3px solid;background:#ffffff08;border-radius:6px;font-size:13px}.cj-tbl{width:100%;border-collapse:collapse;font-size:13px}.cj-tbl th,.cj-tbl td{padding:4px 6px;text-align:start;border-bottom:1px solid var(--panel-border,#2a2f3a)}';
        document.head.appendChild(st);
    };
    document.addEventListener('ap:user-rendered', async e => {
        const { uid, body } = e.detail || {};
        if (!body || !can('users.view')) return;
        const r = await api('/api/admin/insights/customers/' + uid + '/journey');
        if (!r.success || !document.body.contains(body)) return;
        cjStyle();
        const j = r.data, st = CJ_STATE[j.state] || ['gray', j.state];
        const box = document.createElement('div');
        box.className = 'cj-box';
        box.innerHTML = '<h4>🧭 رحلة العميل في المنصة</h4>' +
            '<div>' + pill(st[1], st[0]) + ' <span class="ap-sub">آخر ظهور: ' + esc(dt(j.last_seen)) + ' (من ' + j.hours_since_last_seen + ' ساعة)</span>' +
            (j.plan ? ' · <span class="ap-sub">' + esc(j.plan.name || '') + ' (' + esc(j.plan.status) + ')' + (j.plan.trial_days_left !== null ? ' — باقي ' + Math.max(0, j.plan.trial_days_left) + ' يوم' : '') + '</span>' : '') + '</div>' +
            '<div class="cj-grid"><div><span class="ap-sub">زيارات صفحات</span><b>' + j.visits + '</b></div><div><span class="ap-sub">جلسات</span><b>' + j.session_count + '</b></div><div><span class="ap-sub">وقت نشط</span><b>' + cjDur(j.active_seconds) + '</b></div><div><span class="ap-sub">مرات الدخول</span><b>' + j.logins + '</b></div><div><span class="ap-sub">استدعاءات AI</span><b>' + j.ai_calls + '</b></div></div>' +
            '<div class="ap-sub">التفعيل: ' + j.activation.done + ' من ' + j.activation.total + '</div>' +
            '<div class="cj-ms">' + j.milestones.map(m => '<span class="' + (m.done ? 'done' : '') + '">' + (m.done ? '✓ ' : '○ ') + esc(m.label) + '</span>').join('') + '</div>' +
            (j.source ? '<div class="ap-sub">جه من حملة التسويق: ' + esc(j.source.company || '') + (j.source.emailed ? ' · اتبعتله إيميل' : '') + (j.source.whatsapp ? ' · اتكلم معاه واتساب' : '') + (j.source.report_viewed ? ' · فتح تقرير موقعه' : '') + '</div>' : '') +
            (j.next_steps.length ? '<ul class="cj-next">' + j.next_steps.map(n => '<li style="border-color:' + (CJ_LEVEL[n.level] || '#888') + '">' + esc(n.text) + '</li>').join('') + '</ul>' : '') +
            (j.contact.wa_url ? '<a class="p-btn primary xs" target="_blank" rel="noopener" href="' + esc(j.contact.wa_url) + '">💬 كلّمه على واتساب (الرسالة جاهزة)</a>' : '<span class="ap-sub">مفيش رقم تليفون متسجّل ليه.</span>') +
            (j.top_pages.length ? '<h4 style="margin-top:14px">أكتر الصفحات اللي دخلها</h4><table class="cj-tbl"><thead><tr><th>الصفحة</th><th>مرات</th><th>وقت تقريبي</th></tr></thead><tbody>' + j.top_pages.map(p => '<tr><td>' + esc(p.label) + (p.label !== p.path ? ' <span class="ap-sub">' + esc(p.path) + '</span>' : '') + '</td><td>' + p.views + '</td><td>' + (p.seconds ? cjDur(p.seconds) : '—') + '</td></tr>').join('') + '</tbody></table>' : '<div class="ap-sub" style="margin-top:10px">مفيش زيارات صفحات متسجّلة للعميل ده لسه.</div>') +
            (j.sessions.length ? '<h4 style="margin-top:14px">آخر الجلسات</h4><table class="cj-tbl"><thead><tr><th>البداية</th><th>صفحات</th><th>المدة</th><th>الجهاز</th></tr></thead><tbody>' + j.sessions.slice(0, 6).map(x => '<tr><td>' + esc(dt(x.start)) + '</td><td>' + x.pages + '</td><td>' + (x.seconds ? cjDur(x.seconds) : '—') + '</td><td>' + esc(x.device || '') + '</td></tr>').join('') + '</tbody></table><div class="ap-sub">وقت الصفحة تقديري: الفرق بين زيارتها والصفحة اللي بعدها (آخر صفحة في الجلسة مش بتتحسب).</div>' : '');
        const anchor = $('.ap-ustats', body); if (anchor) anchor.after(box); else body.appendChild(box);
    });

    // ملف العميل 360: الصحة والربحية والتاجات
    document.addEventListener('ap:user-rendered', async e => {
        const { uid, body } = e.detail || {};
        if (!body || !can('users.view')) return;
        const r = await api('/api/admin/insights/customers/' + uid);
        if (!r.success || !document.body.contains(body)) return;
        if (!r.data.health) {
            // عميل من غير اشتراك: زرار تأكيد البريد بس
            if (!r.data.email_pending || !can('users.edit')) return;
            const nb = document.createElement('div');
            nb.className = 'ap-note';
            nb.style.cssText = 'margin:10px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap';
            nb.innerHTML = '<span>✉️ العميل لسه ماأكّدش بريده الإلكتروني.</span><button class="p-btn outline xs" data-verify-email>أكّده يدويًا</button>';
            const an = $('.ap-ustats', body); if (an) an.after(nb); else body.appendChild(nb);
            nb.addEventListener('click', async ev => {
                if (!ev.target.closest('[data-verify-email]')) return;
                if (!await confirmDialog('تأكيد البريد يدويًا', 'استخدم ده بس لو اتأكدت إن الإيميل ده بتاع العميل فعلًا (مثلًا كلّمك منه).', 'أكّد البريد')) return;
                const x = await api('/api/admin/insights/users/' + uid + '/verify-email', 'POST', {});
                toast(x.message || x.error, x.success ? 'success' : 'error');
                if (x.success) nb.remove();
            });
            return;
        }
        const h = r.data.health;
        const box = document.createElement('div');
        box.className = 'ai-360';
        const drawBox = (tags) => {
            h.tags = tags;
            box.innerHTML = '<div class="ai-360-row"><div><div class="ap-sub">صحة العميل</div><div class="ai-360-score"><span class="ap-mono">' + h.score + '</span>/100 ' + pill(BAND[h.band][1], BAND[h.band][0]) + '</div></div>' +
                '<div><div class="ap-sub">الربح (30 يوم)</div><div class="ap-mono is-money ' + (h.margin_30d < 0 ? 'ai-neg' : '') + '">' + money(h.margin_30d) + '</div><small class="p-cell-muted">إيراد ' + money(h.revenue_30d) + ' − AI ' + money(h.ai_cost_30d) + '</small></div></div>' +
                (h.reasons.length ? '<div class="ai-reasons">' + h.reasons.map(x => '<span>' + esc(x) + '</span>').join('') + '</div>' : '') +
                (h.email_pending ? '<div class="ap-note" style="margin:10px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><span>✉️ العميل لسه ماأكّدش بريده الإلكتروني.</span>' + (can('users.edit') ? '<button class="p-btn outline xs" data-verify-email>أكّده يدويًا</button>' : '') + '</div>' : '') +
                '<div class="ai-tags" style="margin-top:10px">' + tags.map(t => '<span class="ai-tag">' + esc(t) + (can('users.notes') ? ' <button data-untag="' + esc(t) + '" aria-label="شيل">×</button>' : '') + '</span>').join('') +
                (can('users.notes') ? '<input class="ai-tag-in" data-addtag maxlength="40" placeholder="+ تاج">' : '') + '</div>';
        };
        drawBox(h.tags || []);
        const anchor = $('.ap-ustats', body); if (anchor) anchor.after(box); else body.appendChild(box);
        box.addEventListener('click', async ev => {
            const v = ev.target.closest('[data-verify-email]');
            if (v) {
                ev.stopPropagation();
                if (!await confirmDialog('تأكيد البريد يدويًا', 'استخدم ده بس لو اتأكدت إن الإيميل ده بتاع العميل فعلًا (مثلًا كلّمك منه).', 'أكّد البريد')) return;
                const x = await api('/api/admin/insights/users/' + uid + '/verify-email', 'POST', {});
                toast(x.message || x.error, x.success ? 'success' : 'error');
                if (x.success) { h.email_pending = false; drawBox(h.tags || []); }
                return;
            }
            const b = ev.target.closest('[data-untag]'); if (!b) return;
            ev.stopPropagation();
            const x = await api('/api/admin/insights/users/' + uid + '/tags?tag=' + encodeURIComponent(b.dataset.untag), 'DELETE'); if (x.success) drawBox(x.data.tags);
        });
        box.addEventListener('keydown', async ev => {
            if (ev.key !== 'Enter' || !ev.target.matches('[data-addtag]')) return;
            ev.preventDefault();
            const x = await api('/api/admin/insights/users/' + uid + '/tags', 'POST', { tag: ev.target.value }); if (x.success) { drawBox(x.data.tags); const i = $('[data-addtag]', box); i && i.focus(); } else toast(x.error, 'error');
        });
    });

    // ================================================================== التحكم في الحساب (تعديل / باسورد / حذف)
    const ROLE_AR = { super_admin: 'Super Admin', admin: 'أدمن', agency_owner: 'صاحب وكالة', manager: 'صاحب حساب', agent: 'موظف', client: 'عميل', user: 'مستخدم' };
    document.addEventListener('ap:user-rendered', async e => {
        const { uid, body } = e.detail || {};
        if (!body || !can('users.view')) return;
        const r = await api('/api/admin/insights/accounts/' + uid);
        if (!r.success || !document.body.contains(body)) return;
        const a = r.data.account, c = r.data.can;
        const box = document.createElement('section');
        box.className = 'ap-usec ai-acct';
        const btns = [];
        if (c.edit) btns.push('<button class="p-btn outline xs" data-ac-edit>تعديل البيانات</button>');
        if (c.password) btns.push('<button class="p-btn outline xs" data-ac-pw>الباسورد</button>');
        if (c.delete) btns.push('<button class="p-btn danger xs" data-ac-del>حذف الحساب</button>');
        box.innerHTML = '<h4>التحكم في الحساب ' + (a.is_primary_owner ? pill('المالك الأساسي للمنصة', 'blue') : '') + (r.data.actor_is_primary_owner && !a.is_self ? ' ' + pill('عندك صلاحية كاملة', 'green') : '') + '</h4>' +
            (btns.length ? '<div class="ap-uactions" style="margin:0">' + btns.join('') + '</div>' : '') +
            (!c.delete && r.data.blocked_reason ? '<div class="ap-sub" style="margin-top:8px">🔒 ' + esc(r.data.blocked_reason) + '</div>' : '');
        const anchor = $('.ap-uactions', body); if (anchor) anchor.after(box); else body.prepend(box);
        const reload = () => { if (window.AdminPro && window.AdminPro.openUser) window.AdminPro.openUser(uid); };

        box.addEventListener('click', async ev => {
            ev.stopPropagation();
            if (ev.target.closest('[data-ac-edit]')) {
                const roleSel = c.edit_role ? '<label class="ap-lbl">الدور</label><select class="p-select" id="acRole">' + r.data.roles.map(x => '<option value="' + x + '"' + (x === a.role ? ' selected' : '') + '>' + esc(ROLE_AR[x] || x) + '</option>').join('') + '</select>' +
                    (r.data.actor_is_primary_owner ? '' : '<div class="ap-sub">الترقية لأدمن للمالك الأساسي بس.</div>') : '';
                const m = modal('<h3>تعديل بيانات الحساب</h3><div class="ai-form2">' +
                    '<div><label class="ap-lbl">اسم الشركة</label><input class="p-input" id="acCo" maxlength="255" value="' + esc(a.company_name) + '"></div>' +
                    '<div><label class="ap-lbl">الاسم الظاهر</label><input class="p-input" id="acDn" maxlength="120" value="' + esc(a.display_name) + '"></div>' +
                    '<div><label class="ap-lbl">البريد الإلكتروني</label><input class="p-input" id="acEm" type="email" dir="ltr" maxlength="191" value="' + esc(a.email) + '"></div>' +
                    '<div><label class="ap-lbl">التليفون</label><input class="p-input" id="acPh" dir="ltr" maxlength="50" value="' + esc(a.phone) + '"></div></div>' + roleSel +
                    '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>');
                $('[data-x]', m.bg).onclick = () => m.close();
                $('[data-ok]', m.bg).onclick = async () => {
                    const payload = { company_name: $('#acCo', m.bg).value, display_name: $('#acDn', m.bg).value, email: $('#acEm', m.bg).value, phone: $('#acPh', m.bg).value };
                    if ($('#acRole', m.bg)) payload.role = $('#acRole', m.bg).value;
                    const x = await api('/api/admin/insights/accounts/' + uid + '/update', 'POST', payload);
                    toast(x.message || x.error, x.success ? 'success' : 'error');
                    if (x.success) { m.close(); reload(); }
                };
                return;
            }
            if (ev.target.closest('[data-ac-pw]')) {
                const gen = () => { const ch = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#'; let o = ''; const rnd = crypto.getRandomValues(new Uint32Array(14)); rnd.forEach(n => o += ch[n % ch.length]); return o; };
                const m = modal('<h3>الباسورد - ' + esc(a.email) + '</h3>' +
                    '<label class="ap-chk" style="display:block;margin:8px 0"><input type="radio" name="acPwM" value="link" checked> ابعتله رابط يعيّن منه باسورد جديد على إيميله (الأفضل)</label>' +
                    '<label class="ap-chk" style="display:block;margin:8px 0"><input type="radio" name="acPwM" value="set"> حط باسورد جديد بنفسك</label>' +
                    '<div id="acPwBox" style="display:none"><div style="display:flex;gap:8px"><input class="p-input ap-mono" id="acPw" dir="ltr" minlength="8" autocomplete="new-password"><button class="p-btn outline xs" data-gen type="button">توليد</button></div><div class="ap-sub">كل جلسات الحساب هتتقفل. ابعتله الباسورد بطريقة آمنة.</div></div>' +
                    '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>تنفيذ</button></div>');
                const mode = () => ($('input[name=acPwM]:checked', m.bg) || {}).value;
                m.bg.addEventListener('change', () => { $('#acPwBox', m.bg).style.display = mode() === 'set' ? 'block' : 'none'; if (mode() === 'set' && !$('#acPw', m.bg).value) $('#acPw', m.bg).value = gen(); });
                $('[data-gen]', m.bg).onclick = () => { $('#acPw', m.bg).value = gen(); };
                $('[data-x]', m.bg).onclick = () => m.close();
                $('[data-ok]', m.bg).onclick = async () => {
                    const x = await api('/api/admin/insights/accounts/' + uid + '/password', 'POST', { mode: mode(), password: $('#acPw', m.bg).value });
                    toast(x.message || x.error, x.success ? 'success' : 'error');
                    if (x.success) m.close();
                };
                return;
            }
            if (ev.target.closest('[data-ac-del]')) {
                const m = modal('<h3 style="color:var(--ap-red,#d92d20)">حذف الحساب</h3><p class="ap-sub" style="line-height:1.8"><b>' + esc(a.company_name || a.email) + '</b> · ' + esc(a.email) + (a.role ? ' · ' + esc(ROLE_AR[a.role] || a.role) : '') + '</p>' +
                    '<label class="ap-chk" style="display:block;margin:8px 0;line-height:1.7"><input type="radio" name="acDelM" value="soft" checked> <b>حذف الحساب</b> - بيتقفل ويختفي، الاشتراك بيتلغي، المواقع بتفصل، والإيميل بيتفك يقدر يسجّل بيه تاني. الفواتير وحركات المحفظة بتفضل للحسابات.</label>' +
                    (c.hard_delete ? '<label class="ap-chk" style="display:block;margin:8px 0;line-height:1.7"><input type="radio" name="acDelM" value="hard"> <b>حذف نهائي</b> - بيمسح الحساب وكل بياناته (مواقع، محادثات، فواتير، محفظة...) من قاعدة البيانات. <b>مابيرجعش.</b></label>' : '') +
                    (a.members ? '<label class="ap-chk" style="display:block;margin:8px 0"><input type="checkbox" id="acMem" checked> احذف كمان أعضاء فريقه (' + a.members + ')</label>' : '') +
                    '<label class="ap-lbl">اكتب إيميل الحساب للتأكيد</label><input class="p-input" id="acConf" dir="ltr" placeholder="' + esc(a.email) + '" autocomplete="off">' +
                    '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn danger" data-ok disabled>احذف</button></div>');
                const ok = $('[data-ok]', m.bg), conf = $('#acConf', m.bg);
                conf.addEventListener('input', () => { ok.disabled = conf.value.trim().toLowerCase() !== String(a.email).toLowerCase(); });
                m.bg.addEventListener('change', () => { ok.textContent = ($('input[name=acDelM]:checked', m.bg) || {}).value === 'hard' ? 'احذف نهائيًا' : 'احذف'; });
                $('[data-x]', m.bg).onclick = () => m.close();
                ok.onclick = async () => {
                    ok.disabled = true;
                    const x = await api('/api/admin/insights/accounts/' + uid + '/delete', 'POST', { mode: ($('input[name=acDelM]:checked', m.bg) || {}).value, confirm: conf.value, include_members: $('#acMem', m.bg) ? $('#acMem', m.bg).checked : false });
                    toast(x.message || x.error, x.success ? 'success' : 'error');
                    if (x.success) {
                        m.close();
                        const d = document.getElementById('apUserDrawer'); if (d) d.remove();
                        const row = document.querySelector('tr[data-user="' + uid + '"]'); if (row) row.remove();
                    } else ok.disabled = false;
                };
                setTimeout(() => conf.focus(), 50);
            }
        });
    });

    // ================================================================== الحسابات المحذوفة (استرجاع)
    function deletedAccountsButton() {
        const tbl = document.getElementById('usersTable');
        if (!tbl || document.getElementById('aiDeletedBtn') || !can('users.view')) return;
        const card = tbl.closest('.p-card') || tbl.parentElement;
        const bar = document.createElement('div');
        bar.className = 'ap-bar'; bar.style.margin = '0 0 10px';
        bar.innerHTML = '<span class="ap-spacer"></span><button class="p-btn outline xs" id="aiDeletedBtn">🗑 الحسابات المحذوفة</button>';
        card.parentNode.insertBefore(bar, card);
        $('#aiDeletedBtn').onclick = openDeleted;
    }
    async function openDeleted() {
        const r = await api('/api/admin/insights/accounts-deleted');
        if (!r.success) return toast(r.error, 'error');
        const list = r.data.accounts;
        const rows = list.map(a => '<tr data-id="' + a.id + '"><td><b>' + esc(a.company_name || '—') + '</b>' + (a.is_member ? ' ' + pill('عضو فريق', 'gray') : '') +
            '<br><span class="p-cell-muted ap-mono" dir="ltr">' + esc(a.original_email || 'الإيميل الأصلي مش معروف') + '</span>' + (a.email_taken ? '<br>' + pill('الإيميل اتسجّل بيه حساب تاني', 'orange') : '') + '</td>' +
            '<td class="p-cell-muted">' + esc(ago(a.deleted_at)) + (a.deleted_by ? '<br><small>' + esc(a.deleted_by) + '</small>' : '') + '</td>' +
            '<td><button class="p-btn outline xs" data-restore>استرجاع</button></td></tr>');
        const m = modal('<h3>الحسابات المحذوفة</h3><p class="ap-sub">دي الحسابات اللي اتحذفت "حذف عادي". الحذف النهائي مابيرجعش.</p>' +
            (list.length ? table(['الحساب', 'اتحذف', ''], rows) : '<div class="ap-empty">مفيش حسابات محذوفة</div>') +
            '<div class="ap-actions"><button class="p-btn outline" data-x>قفل</button></div>', true);
        $('[data-x]', m.bg).onclick = () => m.close();
        m.bg.addEventListener('click', async ev => {
            const b = ev.target.closest('[data-restore]'); if (!b) return;
            const tr = b.closest('tr'); const a = list.find(x => x.id === +tr.dataset.id);
            let email = a.original_email;
            if (!email || a.email_taken) {
                email = prompt('اكتب إيميل للحساب ده:', email || '');
                if (!email) return;
            } else if (!await confirmDialog('استرجاع الحساب', 'هيرجع الحساب بإيميل ' + email + '. الباسورد اتغيّر وقت الحذف فهتحتاج تبعتله رابط تعيين باسورد، والاشتراك محتاج يتجدد.', 'استرجع')) return;
            b.disabled = true;
            const x = await api('/api/admin/insights/accounts/' + a.id + '/restore', 'POST', { email });
            toast(x.message || x.error, x.success ? 'success' : 'error');
            if (x.success) { tr.remove(); if (typeof window.loadUsers === 'function') window.loadUsers(); } else b.disabled = false;
        });
    }
    deletedAccountsButton();
    document.addEventListener('DOMContentLoaded', deletedAccountsButton);

    // ================================================================== الدفع والكوبونات
    async function pageBilling(root) {
        load(root, '/api/admin/insights/billing', d => {
            const dun = {}; (d.dunning || []).forEach(x => { dun[x.type] = +x.n; });
            const coupons = d.coupons.map(c => '<tr data-coupon="' + c.id + '" class="ai-click"><td><b class="ap-mono">' + esc(c.code) + '</b><br><span class="p-cell-muted">' + esc(c.description || '') + '</span></td>' +
                '<td>' + (c.discount_type === 'percent' ? esc(NF.format(c.amount)) + '%' : money(c.amount)) + (c.first_payment_only ? '<br><small class="p-cell-muted">أول اشتراك بس</small>' : '') + '</td>' +
                '<td class="ap-mono">' + c.used + (c.max_redemptions ? ' / ' + c.max_redemptions : '') + '</td><td class="ap-mono is-money">' + money(c.total_discount) + '</td>' +
                '<td class="p-cell-muted">' + (c.valid_until ? 'لحد ' + esc(String(c.valid_until).slice(0, 10)) : 'مفتوح') + (c.plan_codes.length ? '<br>' + esc(c.plan_codes.join('، ')) : '') + '</td>' +
                '<td>' + (!c.is_active ? pill('موقوف', 'gray') : (c.expired ? pill('انتهى', 'gray') : pill('شغّال', 'green'))) + '</td></tr>');
            root.innerHTML =
                '<div class="ap-panel ap-pulse ai-pulse4">' + kpi('اشتراكات متأخرة', { value: d.past_due.length, format: 'int', hint: 'في فترة السماح' }) + kpi('إيراد شهري في خطر', { value: d.past_due_mrr, format: 'money' }) +
                kpi('دفعات فشلت (30 يوم)', { value: d.failed_payments.length, format: 'int' }) + kpi('رسايل تذكير اتبعتت (30 يوم)', { value: Object.values(dun).reduce((a, b) => a + b, 0), format: 'int', hint: 'المنصة بتبعتها لوحدها' }) + '</div>' +
                panel('اشتراكات متأخرة الدفع', 'المنصة بتبعتلهم تذكير لوحدها قبل ما الاشتراك يتلغي. مكالمة منك بترجّع ناس أكتر.',
                    d.past_due.length ? table(['العميل', 'الباقة', 'MRR', 'متأخر', 'رصيد المحفظة'], d.past_due.map(p => '<tr class="ai-click" data-user="' + p.user_id + '"><td><b>' + esc(p.name) + '</b><br><span class="p-cell-muted">' + esc(p.email) + '</span></td><td>' + esc(p.plan || '—') + '</td><td class="ap-mono is-money">' + money(p.mrr) + '</td><td>' + pill((+p.days_late > 0 ? p.days_late : 0) + ' يوم', +p.days_late > 5 ? 'red' : 'orange') + '</td><td class="ap-mono is-money">' + money(p.balance) + '</td></tr>'))
                        : emptyState('✓', 'مفيش اشتراكات متأخرة', 'كل اللي بيدفعوا دفعوا في ميعادهم.')) +
                panel('دفعات فشلت (30 يوم)', 'من بوابات الدفع (Paymob / Stripe / ...)',
                    d.failed_payments.length ? table(['العميل', 'المبلغ', 'البوابة', 'الحالة', 'امتى'], d.failed_payments.map(p => '<tr class="ai-click" data-user="' + p.user_id + '"><td><b>' + esc(p.name || '—') + '</b><br><span class="p-cell-muted">' + esc(p.email || '') + '</span></td><td class="ap-mono is-money">' + money(p.amount) + ' ' + esc(p.currency || '') + '</td><td>' + esc(p.gateway || '—') + '</td><td>' + pill({ failed: 'فشل', declined: 'اترفض', error: 'خطأ' }[p.status] || p.status, 'red') + '</td><td class="p-cell-muted">' + esc(ago(p.created_at)) + '</td></tr>'))
                        : emptyState('✓', 'مفيش دفعات فشلت', 'كل عمليات الدفع آخر 30 يوم نجحت.')) +
                panel('أكواد الخصم', 'العميل بيكتب الكود في نافذة الاشتراك وبيشوف السعر بعد الخصم قبل ما يأكد',
                    d.coupons.length ? table(['الكود', 'الخصم', 'اتستخدم', 'إجمالي الخصم', 'الصلاحية', 'الحالة'], coupons) : emptyState('%', 'مفيش أكواد خصم', 'اعمل كود للعروض أو للشركاء أو لعميل معيّن.'),
                    can('billing.settings') ? '<button class="p-btn primary xs" data-newcoupon>+ كود جديد</button>' : '');
            root.onclick = e => {
                if (e.target.closest('[data-newcoupon]')) return couponModal(null, d.plan_codes, () => pageBilling(root));
                const c = e.target.closest('[data-coupon]'); if (c && can('billing.settings')) return couponModal(d.coupons.find(x => x.id == c.dataset.coupon), d.plan_codes, () => pageBilling(root));
                const u = e.target.closest('[data-user]'); if (u && window.AdminPro.openUser) window.AdminPro.openUser(+u.dataset.user);
            };
        });
    }
    function couponModal(c, planCodes, after) {
        c = c || { code: '', description: '', discount_type: 'percent', amount: '', max_redemptions: '', per_user_limit: 1, valid_from: '', valid_until: '', plan_codes: [], first_payment_only: false, is_active: true };
        const m = modal('<h3>' + (c.id ? 'تعديل كود ' + esc(c.code) : 'كود خصم جديد') + '</h3>' +
            '<div class="ai-form2"><div><label class="ap-lbl">الكود</label><input class="p-input ap-mono" id="cpCode" value="' + esc(c.code) + '" maxlength="40" placeholder="RAMADAN25" style="text-transform:uppercase" dir="ltr"></div>' +
            '<div><label class="ap-lbl">النوع</label><select class="p-select" id="cpType"><option value="percent"' + (c.discount_type === 'percent' ? ' selected' : '') + '>نسبة %</option><option value="fixed"' + (c.discount_type === 'fixed' ? ' selected' : '') + '>مبلغ ثابت $</option></select></div>' +
            '<div><label class="ap-lbl">القيمة</label><input type="number" min="0.01" step="0.01" class="p-input" id="cpAmt" value="' + esc(c.amount) + '"></div>' +
            '<div><label class="ap-lbl">أقصى عدد استخدام</label><input type="number" min="1" class="p-input" id="cpMax" value="' + esc(c.max_redemptions || '') + '" placeholder="بدون حد"></div>' +
            '<div><label class="ap-lbl">يبدأ</label><input type="date" class="p-input" id="cpFrom" value="' + esc(String(c.valid_from || '').slice(0, 10)) + '"></div>' +
            '<div><label class="ap-lbl">ينتهي</label><input type="date" class="p-input" id="cpUntil" value="' + esc(String(c.valid_until || '').slice(0, 10)) + '"></div></div>' +
            '<label class="ap-lbl">وصف داخلي</label><input class="p-input" id="cpDesc" value="' + esc(c.description || '') + '" maxlength="255" placeholder="مثلاً: عرض رمضان لعملاء الفيسبوك">' +
            '<label class="ap-lbl">الباقات (فاضي = كلهم)</label><div class="ai-tags">' + planCodes.map(p => '<label class="ap-chk"><input type="checkbox" class="cpPlan" value="' + esc(p) + '"' + (c.plan_codes.includes(p) ? ' checked' : '') + '> ' + esc(p) + '</label>').join('') + '</div>' +
            '<label class="ap-chk" style="display:block;margin-top:12px"><input type="checkbox" id="cpFirst"' + (c.first_payment_only ? ' checked' : '') + '> لأول اشتراك بس (عملاء جداد)</label>' +
            '<label class="ap-chk" style="display:block;margin-top:6px"><input type="checkbox" id="cpActive"' + (c.is_active ? ' checked' : '') + '> شغّال</label>' +
            '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>', true);
        $('[data-x]', m.bg).onclick = m.close; $('#cpCode', m.bg).focus();
        $('[data-ok]', m.bg).onclick = async () => {
            const body = {
                code: $('#cpCode', m.bg).value, discount_type: $('#cpType', m.bg).value, amount: $('#cpAmt', m.bg).value, max_redemptions: $('#cpMax', m.bg).value,
                valid_from: $('#cpFrom', m.bg).value, valid_until: $('#cpUntil', m.bg).value, description: $('#cpDesc', m.bg).value,
                plan_codes: $$('.cpPlan', m.bg).filter(x => x.checked).map(x => x.value), first_payment_only: $('#cpFirst', m.bg).checked, is_active: $('#cpActive', m.bg).checked, per_user_limit: 1,
            };
            const r = await api('/api/admin/insights/coupons' + (c.id ? '/' + c.id : ''), c.id ? 'PUT' : 'POST', body);
            toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); }
        };
    }

    // ================================================================== التنبيهات
    async function pageAlerts(root) {
        let status = 'open';
        const SEV = { critical: ['red', 'حرج'], warning: ['orange', 'تحذير'], info: ['blue', 'معلومة'] };
        const draw = () => load(root, '/api/admin/insights/alerts?status=' + status, d => {
            const s = d.settings;
            const items = d.items.map(a => '<div class="ai-alert ' + a.severity + (a.status !== 'open' ? ' closed' : '') + '"><div class="ai-alert-main">' + pill(SEV[a.severity][1], SEV[a.severity][0]) + ' <b>' + esc(a.title) + '</b>' +
                '<div class="ai-alert-body">' + esc(a.body || '').replace(/\n/g, '<br>') + '</div><div class="ap-sub">أول مرة ' + esc(ago(a.first_seen_at)) + ' · آخر مرة ' + esc(ago(a.last_seen_at)) + (+a.occurrences > 1 ? ' · اتكرر ' + a.occurrences + ' مرة' : '') + (a.notified_at ? ' · اتبلّغت ' + esc(ago(a.notified_at)) : '') + (a.status !== 'open' ? ' · ' + (a.status === 'muted' ? 'اتكتم' : 'اتحل') + ' ' + esc(ago(a.resolved_at)) : '') + '</div></div>' +
                '<div class="ai-alert-act">' + (a.link ? '<a class="p-btn outline xs" href="' + esc(a.link) + '">افتح</a>' : '') + (a.status === 'open' ? '<button class="p-btn outline xs" data-resolve="' + a.id + '">اتحل</button><button class="p-btn outline xs" data-mute="' + a.id + '" title="مايتفتحش تاني 24 ساعة">كتم 24 ساعة</button>' : '') + '</div></div>').join('');
            root.innerHTML =
                '<div class="ap-bar"><div class="ap-seg">' + [['open', 'مفتوحة ' + d.counts.total], ['resolved', 'اتقفلت'], ['all', 'الكل']].map(o => '<button data-st="' + o[0] + '" aria-pressed="' + (status === o[0]) + '">' + o[1] + '</button>').join('') + '</div><span class="ap-spacer"></span>' +
                '<button class="p-btn outline xs" data-run>افحص دلوقتي</button></div>' +
                (d.items.length ? '<div class="ai-alerts">' + items + '</div>' : emptyState('✓', status === 'open' ? 'مفيش تنبيهات مفتوحة' : 'مفيش تنبيهات', 'المنصة بتفحص كل 10 دقايق، ولو حاجة وقعت هتوصلك هنا وعلى الإيميل.')) +
                panel('الإبلاغ', 'التنبيه بيوصلك أول ما يتفتح، والحرج بيتبعت تاني كل 24 ساعة لو لسه ماتحلش',
                    '<label class="ap-chk"><input type="checkbox" id="alEn"' + (s.enabled ? ' checked' : '') + '> ابعتلي التنبيهات</label>' +
                    '<div class="ai-form2"><div><label class="ap-lbl">إيميلات (افصل بفاصلة - فاضي = كل الأدمنز)</label><input class="p-input" id="alEm" value="' + esc(s.emails) + '" dir="ltr" placeholder="you@tourfecto.pro"></div>' +
                    '<div><label class="ap-lbl">واتساب (بيستخدم UltraMsg)</label><input class="p-input" id="alWa" value="' + esc(s.whatsapp) + '" dir="ltr" placeholder="+2010…"></div>' +
                    '<div><label class="ap-lbl">نبّهني لو عميل لغى واشتراكه ≥ ($/شهر)</label><input type="number" min="0" class="p-input" id="alMrr" value="' + esc(s.min_mrr) + '"></div></div>' +
                    '<div class="ap-actions" style="margin-top:14px">' + (can('settings.manage') ? '<button class="p-btn primary xs" data-save>حفظ</button>' : '') + '</div>');
            root.onclick = async e => {
                const b = e.target.closest('[data-st]'); if (b) { status = b.dataset.st; return draw(); }
                const rs = e.target.closest('[data-resolve],[data-mute]');
                if (rs) { const id = rs.dataset.resolve || rs.dataset.mute; const r = await api('/api/admin/insights/alerts/' + id + '/resolve', 'POST', { mute: !!rs.dataset.mute }); toast(r.message || r.error, r.success ? 'success' : 'error'); return draw(); }
                if (e.target.closest('[data-run]')) { const r = await api('/api/admin/insights/alerts/run', 'POST', {}); toast(r.message || r.error, r.success ? 'success' : 'error'); return draw(); }
                if (e.target.closest('[data-save]')) { const r = await api('/api/admin/insights/alerts/settings', 'PUT', { enabled: $('#alEn', root).checked, emails: $('#alEm', root).value, whatsapp: $('#alWa', root).value, min_mrr: $('#alMrr', root).value }); toast(r.message || r.error, r.success ? 'success' : 'error'); }
            };
        });
        draw();
    }

    // ================================================================== أمان الأدمن
    async function pageAdminSecurity(root) {
        load(root, '/api/admin/insights/security', d => {
            const s = d.settings, canEdit = can('security.manage');
            const missing = d.team.filter(t => !+t.two_factor_enabled);
            root.innerHTML =
                panel('التحقق بخطوتين (2FA) إجباري لكل الفريق', 'لو حد من الفريق اتسرق باسورده، مش هيقدر يدخل اللوحة من غير الموبايل بتاعه',
                    '<label class="ap-chk"><input type="checkbox" id="sc2fa"' + (s.require_2fa ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> اطلب 2FA من كل أعضاء الفريق قبل ما يدخلوا لوحة الأدمن</label>' +
                    (!d.me_2fa ? '<div class="ap-note"><b>انت نفسك لسه مافعّلتش 2FA.</b> فعّله الأول من <a href="/profile/settings">إعدادات حسابك</a>، وبعدين فعّل الإجبار.</div>' : '') +
                    (missing.length ? '<div class="ap-note" style="background:var(--panel-danger-light)"><b>' + missing.length + ' من الفريق من غير 2FA:</b> ' + esc(missing.map(m => m.name).join('، ')) + ' - لو فعّلت الإجبار هيتطلب منهم يفعّلوه قبل ما يدخلوا.</div>' : '') +
                    table(['العضو', 'الدور', '2FA', 'آخر دخول'], d.team.map(t => '<tr><td><b>' + esc(t.name) + '</b><br><span class="p-cell-muted">' + esc(t.email) + '</span></td><td>' + pill(t.role === 'super_admin' ? 'مالك' : 'أدمن', t.role === 'super_admin' ? 'purple' : 'blue') + '</td><td>' + (+t.two_factor_enabled ? pill('مفعّل', 'green') : pill('مش مفعّل', 'red')) + '</td><td class="p-cell-muted">' + esc(ago(t.last_login)) + '</td></tr>'))) +
                panel('عناوين IP المسموحة', 'لو حطّيت عناوين، لوحة الأدمن هتفتح منها بس. سيبها فاضية عشان تفتح من أي مكان.',
                    '<textarea class="p-input ap-mono" id="scIp" rows="3" dir="ltr" placeholder="92.113.26.170&#10;41.33.0.0/16"' + (canEdit ? '' : ' disabled') + ' style="width:100%">' + esc(s.ip_allowlist) + '</textarea>' +
                    '<div class="ap-sub" style="margin-top:6px">عنوانك الحالي: <b class="ap-mono">' + esc(d.my_ip) + '</b> ' + (canEdit ? '<a href="#" data-addip>ضيفه للقايمة</a>' : '') + ' · عنوان في كل سطر، وينفع نطاق زي 41.33.0.0/16. لو النت عندك IP بيتغيّر ماتستخدمهاش.</div>') +
                panel('سبب إجباري للإجراءات الحساسة', 'قبل الإجراءات دي اللوحة بتطلب سبب، والسبب بيتسجّل في سجل التدقيق مع اسم اللي عمل الإجراء',
                    '<label class="ap-chk"><input type="checkbox" id="scReason"' + (s.require_reason ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> اطلب سبب قبل:</label><div class="ai-tags" style="margin-top:8px">' + d.reason_actions.map(a => '<span class="ai-tag">' + esc(a) + '</span>').join('') + '</div>') +
                (canEdit ? '<div class="ap-actions"><button class="p-btn primary" data-save>حفظ إعدادات الأمان</button></div>' : '');
            root.onclick = async e => {
                if (e.target.closest('[data-addip]')) { e.preventDefault(); const t = $('#scIp', root); t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + d.my_ip; return; }
                if (e.target.closest('[data-save]')) {
                    const r = await api('/api/admin/insights/security', 'PUT', { require_2fa: $('#sc2fa', root).checked, ip_allowlist: $('#scIp', root).value, require_reason: $('#scReason', root).checked });
                    toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) pageAdminSecurity(root);
                }
            };
        });
    }

    // ================================================================== صفحة الحالة وسجل التحديثات
    async function pageStatus(root) {
        load(root, '/api/admin/insights/statuspage', d => {
            const ps = d.public_status, PS = { operational: ['green', 'شغّال'], degraded: ['orange', 'مشكلة جزئية'], outage: ['red', 'متوقف'], maintenance: ['blue', 'صيانة'] };
            const IST = { investigating: 'بنحقق', identified: 'عرفنا السبب', monitoring: 'بنتابع', resolved: 'اتحل', scheduled: 'صيانة مجدولة' };
            const CAT = { new: ['blue', 'جديد'], improved: ['green', 'تحسين'], fixed: ['orange', 'إصلاح'] };
            root.innerHTML =
                '<div class="ai-banner ' + (ps.overall === 'operational' ? 'ok' : (ps.overall === 'outage' ? 'fail' : 'warn')) + '"><div class="ai-banner-ico">' + (ps.overall === 'operational' ? '✓' : '!') + '</div><div><b>العملاء شايفين دلوقتي: ' + esc(PS[ps.overall][1]) + '</b><div>الصفحة بتتحدّث لوحدها من فحوصات المنصة + الحوادث اللي بتكتبها هنا.</div></div><span class="ap-spacer"></span>' +
                '<a class="p-btn outline xs" href="/status" target="_blank" rel="noopener">افتح صفحة الحالة</a><a class="p-btn outline xs" href="/changelog" target="_blank" rel="noopener">افتح إيه الجديد</a></div>' +
                '<div class="ap-grid halves">' +
                panel('الخدمات', 'زي ما العميل شايفها', Object.keys(ps.components).map(k => '<div class="ai-check">' + dot(ps.components[k] === 'operational' ? 'ok' : (ps.components[k] === 'outage' ? 'fail' : 'warn')) + '<div><div class="ai-check-l">' + esc(d.components[k]) + '</div><div class="ai-check-m">' + esc(PS[ps.components[k]][1]) + '</div></div></div>').join('')) +
                panel('الحوادث والصيانة', 'اكتب للعملاء لما يحصل عطل أو صيانة', d.incidents.length ? d.incidents.map(i => '<div class="ai-row ai-click" data-inc="' + i.id + '">' + pill(IST[i.status], i.status === 'resolved' ? 'green' : (i.severity === 'major' ? 'red' : 'orange')) + ' <b>' + esc(i.title) + '</b><div class="ap-sub">' + esc(d.components[i.component] || '') + ' · ' + esc(dt(i.started_at)) + '</div></div>').join('') : emptyState('✓', 'مفيش حوادث', 'لما يحصل عطل اكتب هنا عشان العملاء يعرفوا إنك شايفه.'), '<button class="p-btn primary xs" data-newinc>+ حادثة / صيانة</button>') + '</div>' +
                panel('سجل التحديثات (إيه الجديد)', 'اللي بتنشره بيظهر في tourfecto.pro/changelog', d.changelog.length ? d.changelog.map(c => '<div class="ai-row ai-click" data-cl="' + c.id + '">' + pill(CAT[c.category][1], CAT[c.category][0]) + ' <b>' + esc(c.title) + '</b> ' + (+c.is_published ? '' : pill('مسودة', 'gray')) + '<div class="ap-sub">' + esc(dt(c.published_at || c.created_at)) + '</div></div>').join('') : emptyState('★', 'لسه مفيش تحديثات', 'اكتب كل ميزة جديدة أو إصلاح عشان العملاء يحسوا إن المنصة بتتطور.'), '<button class="p-btn primary xs" data-newcl>+ تحديث</button>');
            root.onclick = e => {
                if (e.target.closest('[data-newinc]')) return incidentModal(null, d.components, () => pageStatus(root));
                const i = e.target.closest('[data-inc]'); if (i) return incidentModal(d.incidents.find(x => x.id == i.dataset.inc), d.components, () => pageStatus(root));
                if (e.target.closest('[data-newcl]')) return changelogModal(null, () => pageStatus(root));
                const c = e.target.closest('[data-cl]'); if (c) return changelogModal(d.changelog.find(x => x.id == c.dataset.cl), () => pageStatus(root));
            };
        });
    }
    function incidentModal(i, comps, after) {
        i = i || { title: '', body: '', component: 'platform', severity: 'minor', status: 'investigating' };
        const opt = (o, v) => Object.keys(o).map(k => '<option value="' + k + '"' + (k === v ? ' selected' : '') + '>' + esc(o[k]) + '</option>').join('');
        const m = modal('<h3>' + (i.id ? 'تحديث الحادثة' : 'حادثة / صيانة جديدة') + '</h3><label class="ap-lbl">العنوان (بيظهر للعملاء)</label><input class="p-input" id="inT" value="' + esc(i.title) + '" maxlength="255" placeholder="بطء في الرد على التقييمات">' +
            '<div class="ai-form2"><div><label class="ap-lbl">الخدمة</label><select class="p-select" id="inC">' + opt(comps, i.component) + '</select></div><div><label class="ap-lbl">النوع</label><select class="p-select" id="inS">' + opt({ minor: 'مشكلة جزئية', major: 'عطل كامل', maintenance: 'صيانة' }, i.severity) + '</select></div>' +
            '<div><label class="ap-lbl">الحالة</label><select class="p-select" id="inSt">' + opt({ investigating: 'بنحقق', identified: 'عرفنا السبب', monitoring: 'بنتابع بعد الإصلاح', resolved: 'اتحل', scheduled: 'صيانة مجدولة' }, i.status) + '</select></div></div>' +
            '<label class="ap-lbl">التفاصيل (للعملاء)</label><textarea class="p-input" id="inB" rows="4" maxlength="5000">' + esc(i.body || '') + '</textarea>' +
            '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>', true);
        $('[data-x]', m.bg).onclick = m.close; $('#inT', m.bg).focus();
        $('[data-ok]', m.bg).onclick = async () => {
            const r = await api('/api/admin/insights/incidents' + (i.id ? '/' + i.id : ''), i.id ? 'PUT' : 'POST', { title: $('#inT', m.bg).value, component: $('#inC', m.bg).value, severity: $('#inS', m.bg).value, status: $('#inSt', m.bg).value, body: $('#inB', m.bg).value });
            toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); }
        };
    }
    function changelogModal(c, after) {
        c = c || { title: '', body: '', category: 'new', is_published: 0 };
        const m = modal('<h3>' + (c.id ? 'تعديل التحديث' : 'تحديث جديد') + '</h3><label class="ap-lbl">العنوان</label><input class="p-input" id="clT" value="' + esc(c.title) + '" maxlength="255" placeholder="الرد على التقييمات بقى بأسلوبك">' +
            '<label class="ap-lbl">النوع</label><select class="p-select" id="clC">' + [['new', 'جديد'], ['improved', 'تحسين'], ['fixed', 'إصلاح']].map(o => '<option value="' + o[0] + '"' + (o[0] === c.category ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select>' +
            '<label class="ap-lbl">التفاصيل</label><textarea class="p-input" id="clB" rows="5" maxlength="8000">' + esc(c.body || '') + '</textarea>' +
            '<label class="ap-chk" style="display:block;margin-top:12px"><input type="checkbox" id="clP"' + (+c.is_published ? ' checked' : '') + '> انشره دلوقتي</label>' +
            '<div class="ap-actions">' + (c.id ? '<button class="p-btn danger" data-del style="margin-inline-end:auto">مسح</button>' : '') + '<button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>حفظ</button></div>', true);
        $('[data-x]', m.bg).onclick = m.close; $('#clT', m.bg).focus();
        $('[data-ok]', m.bg).onclick = async () => {
            const r = await api('/api/admin/insights/changelog' + (c.id ? '/' + c.id : ''), c.id ? 'PUT' : 'POST', { title: $('#clT', m.bg).value, category: $('#clC', m.bg).value, body: $('#clB', m.bg).value, is_published: $('#clP', m.bg).checked });
            toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); }
        };
        const del = $('[data-del]', m.bg);
        if (del) del.onclick = async () => { if (!(await confirmDialog('مسح التحديث؟', 'هيختفي من صفحة إيه الجديد.', 'مسح', true))) return; const r = await api('/api/admin/insights/changelog/' + c.id, 'DELETE'); toast(r.message || r.error, r.success ? 'success' : 'error'); if (r.success) { m.close(); after(); } };
    }

    // ================================================================== سبب إجباري للإجراءات الحساسة
    const REASON = [
        [/\/api\/admin\/users\/\d+\/impersonate$/, 'الدخول بحساب العميل'],
        [/\/api\/admin\/refunds$/, 'الاسترداد'],
        [/\/api\/admin\/users\/\d+\/(add-balance|deduct-balance)$/, 'تعديل الرصيد'],
        [/\/api\/admin\/users\/\d+\/grant-plan(\/end)?$/, 'فتح الخدمات مجانًا / إيقافها'],
        [/\/api\/admin\/wallet\/(platform-balance\/(reset|restore)|zero-all-balances)$/, 'تصفير الأرصدة'],
    ];
    function askReason(label) {
        return new Promise(res => {
            const m = modal('<h3>سبب ' + esc(label) + '</h3><p class="ap-sub">بيتسجّل في سجل التدقيق مع اسمك والوقت.</p><textarea class="p-input" id="rsTxt" rows="3" maxlength="500" placeholder="مثلاً: العميل طلب مساعدة في ربط جوجل (تذكرة #123)"></textarea>' +
                '<div class="ap-actions"><button class="p-btn outline" data-x>إلغاء</button><button class="p-btn primary" data-ok>متابعة</button></div>');
            const t = $('#rsTxt', m.bg); t.focus();
            $('[data-x]', m.bg).onclick = () => { m.close(); res(null); };
            $('[data-ok]', m.bg).onclick = () => { const v = t.value.trim(); if (v.length < 5) { t.focus(); toast('اكتب سبب واضح (5 حروف على الأقل)', 'error'); return; } m.close(); res(v); };
        });
    }
    const origFetch = window.fetch.bind(window);
    window.fetch = async function (input, init) {
        try {
            const url = typeof input === 'string' ? input : (input && input.url) || '';
            const method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
            const path = url.replace(/^https?:\/\/[^/]+/, '').split('?')[0];
            const rule = method !== 'GET' && REASON.find(r => r[0].test(path));
            if (rule) {
                let body = {};
                try { body = init && init.body ? JSON.parse(init.body) : {}; } catch (e) { body = null; }
                if (body && typeof body === 'object' && !(body.reason && String(body.reason).trim().length >= 5)) {
                    const reason = await askReason(rule[1]);
                    if (!reason) return new Response(JSON.stringify({ success: false, error: 'اتلغى' }), { status: 499, headers: { 'Content-Type': 'application/json' } });
                    body.reason = reason;
                    init = Object.assign({}, init || {}, { body: JSON.stringify(body) });
                    init.headers = Object.assign({}, (init.headers && !(init.headers instanceof Headers)) ? init.headers : {}, { 'Content-Type': 'application/json' });
                }
            }
        } catch (e) { /* ماتوقفش الطلب */ }
        return origFetch(input, init);
    };

    // ================================================================== تسجيل الصفحات + شارة التنبيهات
    window.AdminPro.registerPage('health', pageHealth);
    window.AdminPro.registerPage('emails', pageEmails);
    window.AdminPro.registerPage('metrics', pageMetrics);
    window.AdminPro.registerPage('customers-health', pageCustomers);

    // ================================================================ متابعة التجارب
    // مين تجربته قربت تخلص / خلصت ومااشتركش / سجّل ومابدأش - مع رسالة واتساب جاهزة بلغة العميل
    async function pageTrials(root) {
        const st = { tab: 'active' };
        let data = null;
        const left = h => h <= 0 ? 'خلصت' : (h < 24 ? 'فاضل ' + h + ' ساعة' : 'فاضل ' + Math.ceil(h / 24) + ' يوم');
        const MSG = {
            active: {
                ar: (n, x) => 'أهلًا ' + n + ' 👋 معاك فريق Tourfecto. تجربتك المجانية ' + (x.hours_left < 24 ? 'بتخلص النهارده' : 'فاضل عليها ' + Math.ceil(x.hours_left / 24) + ' يوم') + '.' + (x.progress && x.progress.next ? ' لو تحب أساعدك في «' + x.progress.next + '» في 10 دقايق،' : ' لو عندك أي سؤال،') + ' أنا موجود هنا.',
                en: (n, x) => 'Hi ' + n + ' 👋 This is the Tourfecto team. Your free trial ' + (x.hours_left < 24 ? 'ends today' : 'has ' + Math.ceil(x.hours_left / 24) + ' days left') + '. Happy to help you set things up in 10 minutes, or answer any question.'
            },
            ended: {
                ar: n => 'أهلًا ' + n + ' 👋 معاك فريق Tourfecto. تجربتك المجانية خلصت، وبياناتك وإعداداتك لسه محفوظة زي ما هي. لو حابب تكمّل أقدر أفعّلك الباقة النهارده، ولو محتاج وقت تجرّب أكتر أمدّلك التجربة كام يوم.',
                en: n => 'Hi ' + n + ' 👋 This is the Tourfecto team. Your free trial has ended - your data and settings are saved. I can activate your plan today, or extend your trial a few days if you need more time.'
            },
            never: {
                ar: n => 'أهلًا ' + n + ' 👋 معاك فريق Tourfecto. شفت إنك سجّلت ولسه مابدأتش التجربة المجانية (3 أيام من غير كارت). تحب أساعدك تبدأها وتجهّز رحلاتك في 10 دقايق؟',
                en: n => 'Hi ' + n + ' 👋 This is the Tourfecto team. You signed up but haven\'t started your free trial yet (3 days, no card). Want me to help you start and set up your trips in 10 minutes?'
            }
        };
        const msgFor = x => (MSG[x.group][x.lang === 'ar' ? 'ar' : 'en'])(x.name, x);
        function actions(x) {
            const m = msgFor(x);
            let h = '';
            if (x.phone) h += '<a class="p-btn primary xs" target="_blank" rel="noopener" href="https://wa.me/' + esc(x.phone) + '?text=' + encodeURIComponent(m) + '">واتساب</a>';
            h += '<a class="p-btn outline xs" href="mailto:' + esc(x.email) + '?subject=' + encodeURIComponent(x.lang === 'ar' ? 'تجربتك على Tourfecto' : 'Your Tourfecto trial') + '&body=' + encodeURIComponent(m) + '">إيميل</a>';
            h += '<button class="p-btn outline xs" data-copy="' + esc(m) + '">انسخ الرسالة</button>';
            if (x.sub_id && can('users.edit')) h += '<button class="p-btn outline xs" data-extend="' + x.sub_id + '">مدّ التجربة 3 أيام</button>';
            if (x.group === 'never' && can('users.edit')) h += '<button class="p-btn outline xs" data-grant="' + esc(x.email) + '">ابدأله التجربة</button>';
            if (x.group !== 'never') h += '<a class="p-btn outline xs" href="/admin/subscriptions?email=' + encodeURIComponent(x.email) + '">تفعيل باقة</a>';
            return '<div style="display:flex;gap:6px;flex-wrap:wrap">' + h + '</div>';
        }
        function prog(x) {
            const p = x.progress; if (!p) return '<span class="p-cell-muted">—</span>';
            const pct = Math.round(p.done / Math.max(1, p.total) * 100);
            return '<b class="ap-mono">' + p.done + '/' + p.total + '</b> <span style="display:inline-block;width:70px;height:5px;border-radius:3px;background:var(--panel-border);overflow:hidden;vertical-align:middle"><i style="display:block;height:100%;width:' + pct + '%;background:var(--panel-accent)"></i></span>'
                + (p.next ? '<br><small class="p-cell-muted">الجاية: ' + esc(p.next) + '</small>' : '')
                + (p.value && p.value.length ? '<br><small>' + p.value.map(v => esc(v[0] + ' ' + v[1])).join(' · ') + '</small>' : '');
        }
        function who(x) {
            return '<b data-user="' + x.id + '" class="ai-click">' + esc(x.name) + '</b><br><span class="p-cell-muted">' + esc(x.email) + '</span>'
                + (x.phone_raw ? '<br><span class="p-cell-muted" dir="ltr">' + esc(x.phone_raw) + '</span>' : '<br><small class="p-cell-muted">مفيش موبايل</small>')
                + (x.email_verified ? '' : ' ' + pill('البريد مش متأكد', 'orange'))
                + (x.plan && x.plan.length ? '<div class="ap-plan" style="margin-top:6px;display:grid;gap:2px;font-size:12px;line-height:1.6">' + x.plan.map(p => '<span><span class="p-cell-muted">' + esc(p[0]) + ':</span> ' + esc(p[1]) + '</span>').join('') + '</div>' : '');
        }
        function checkRows(list) {
            return list.map(x => '<tr><td><a href="https://' + esc(x.host) + '" target="_blank" rel="noopener" dir="ltr"><b>' + esc(x.host) + '</b></a>' + (x.times > 1 ? '<br><small class="p-cell-muted">فحص ' + x.times + ' مرات</small>' : '') + '</td>'
                + '<td>' + pill(String(x.score), x.score >= 75 ? 'green' : (x.score >= 50 ? 'orange' : 'red')) + '</td>'
                + '<td class="p-cell-muted">' + esc(ago(x.at)) + (x.lang ? ' · ' + (x.lang === 'ar' ? 'عربي' : 'English') : '') + '</td>'
                + '<td>' + (x.registered ? '<span data-user="' + x.user_id + '" class="ai-click">' + pill('سجّل ✓', 'green') + '</span>' : pill('لسه ماسجّلش', 'gray')) + '</td>'
                + '<td>' + (x.registered ? '' : '<a class="p-btn outline xs" href="https://' + esc(x.host) + '" target="_blank" rel="noopener">افتح موقعه ولاقي رقمه</a>') + '</td></tr>');
        }
        function rows(list) {
            if (st.tab === 'checks') return checkRows(list);
            if (st.tab === 'active') return list.map(x => '<tr><td>' + who(x) + '</td><td>' + pill(left(x.hours_left), x.hours_left <= 24 ? 'red' : 'blue') + '</td><td>' + prog(x) + '</td><td class="p-cell-muted">' + esc(x.last_login ? ago(x.last_login) : 'ولا مرة') + '</td><td>' + actions(x) + '</td></tr>');
            if (st.tab === 'ended') return list.map(x => '<tr><td>' + who(x) + '</td><td>' + pill(x.ended_days === 0 ? 'خلصت النهارده' : 'من ' + x.ended_days + ' يوم', 'orange') + '</td><td>' + prog(x) + '</td><td class="p-cell-muted">' + esc(x.last_login ? ago(x.last_login) : 'ولا مرة') + '</td><td>' + actions(x) + '</td></tr>');
            return list.map(x => '<tr><td>' + who(x) + '</td><td class="p-cell-muted">' + esc(ago(x.created_at)) + '</td><td>' + (x.sites ? x.sites + ' موقع' : '<span class="p-cell-muted">ماضافش موقع</span>') + '</td><td class="p-cell-muted">' + esc(x.last_login ? ago(x.last_login) : 'ولا مرة') + '</td><td>' + actions(x) + '</td></tr>');
        }
        function draw() {
            const s = data.stats, list = data[st.tab] || [];
            const tabs = [['active', 'تجربة شغّالة', s.active], ['ended', 'خلصت ومااشتركش', s.ended], ['never', 'سجّل ومابدأش', s.never], ['checks', 'فحصوا مواقعهم', s.checks || 0]];
            const heads = { active: ['العميل', 'باقي', 'خلّص من خطة التجربة', 'آخر دخول', ''], ended: ['العميل', 'خلصت', 'خلّص من خطة التجربة', 'آخر دخول', ''], never: ['العميل', 'سجّل', 'موقعه', 'آخر دخول', ''], checks: ['الموقع', 'النتيجة', 'إمتى', 'سجّل؟', ''] };
            const empty = { active: ['⏳', 'مفيش تجارب شغّالة دلوقتي', 'أول ما عميل يبدأ تجربته هيظهر هنا.'], ended: ['✓', 'مفيش تجارب خلصت من غير اشتراك', 'آخر 30 يوم.'], never: ['✓', 'كل اللي سجّلوا بدأوا تجربتهم', 'آخر 60 يوم.'], checks: ['🔎', 'لسه محدش فحص موقعه', 'أي شركة تفحص موقعها من الصفحة الرئيسية هتظهر هنا.'] }[st.tab];
            root.innerHTML = '<div class="ap-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">'
                + kpi('تجارب شغّالة', { value: s.active, hint: s.ending_24h ? s.ending_24h + ' بتخلص خلال 24 ساعة' : 'ولا واحدة بتخلص النهارده' })
                + kpi('خلصت ومااشتركوش', { value: s.ended, hint: 'آخر 30 يوم' })
                + kpi('سجّلوا ومابدأوش', { value: s.never, hint: 'آخر 60 يوم' })
                + kpi('تحويل التجربة لاشتراك', { value: s.conversion_pct, format: 'pct', hint: s.trials_90d ? s.converted_90d + ' من ' + s.trials_90d + ' تجربة - آخر 90 يوم' : 'لسه مفيش تجارب آخر 90 يوم' })
                + '</div>'
                + '<div class="ap-bar" style="gap:6px">' + tabs.map(t => '<button class="p-btn ' + (st.tab === t[0] ? 'primary' : 'outline') + ' xs" data-tab="' + t[0] + '">' + esc(t[1]) + ' <b class="ap-mono">' + t[2] + '</b></button>').join('') + '</div>'
                + '<p class="ap-sub" style="margin:8px 0 12px;line-height:1.8">' + (st.tab === 'checks' ? 'الشركات اللي فحصت موقعها من «افحص موقعك مجانًا» في الصفحة الرئيسية. اللي لسه ماسجّلش: افتح موقعه، هتلاقي رقمه أو الواتساب بتاعه، وكلّمه عن نتيجة الفحص.' : 'رسالة الواتساب جاهزة بلغة العميل وفيها أهم خطوة لسه ماعملهاش. اضغط على اسم العميل تشوف ملفه كامل.') + '</p>'
                + '<section class="ap-panel">' + (list.length ? table(heads[st.tab], rows(list)) : emptyState(empty[0], empty[1], empty[2])) + '</section>';
        }
        async function loadData() {
            root.innerHTML = skeleton(120) + skeleton(260);
            const r = await api('/api/admin/insights/trials');
            if (!r.success) { root.innerHTML = failedBox(r.error); return; }
            data = r.data; draw();
        }
        await loadData();
        root.onclick = async e => {
            const t = e.target.closest('[data-tab]'); if (t) { st.tab = t.dataset.tab; return draw(); }
            const c = e.target.closest('[data-copy]');
            if (c) { const v = c.getAttribute('data-copy'); const done = () => toast('اتنسخت الرسالة', 'success'); if (navigator.clipboard) navigator.clipboard.writeText(v).then(done, done); else done(); return; }
            const g = e.target.closest('[data-grant]');
            if (g) {
                if (!await confirmDialog('تبدأ التجربة المجانية للعميل؟', 'كل الخدمات هتتفتحله مدة التجربة من غير كارت، وهيبدأ يوصله إيميل خطة التجربة كل يوم.', 'ابدأ التجربة')) return;
                // المسار ده محتاج توكن CSRF - Panel.fetchJSON بيبعته في الهيدر
                const r = window.Panel && window.Panel.fetchJSON
                    ? await window.Panel.fetchJSON('/api/admin/plans-studio/grant-trial', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: g.dataset.grant }) })
                    : await api('/api/admin/plans-studio/grant-trial', 'POST', { email: g.dataset.grant });
                toast(r.message || r.error, r.success ? 'success' : 'error');
                if (r.success) loadData();
                return;
            }
            const x = e.target.closest('[data-extend]');
            if (x) {
                if (!await confirmDialog('مدّ التجربة 3 أيام', 'العميل هيقدر يستخدم كل الخدمات 3 أيام كمان، وهيوصله تنبيه بكده.', 'مدّ التجربة')) return;
                const r = await api('/api/admin/insights/trials/' + x.dataset.extend + '/extend', 'POST', { days: 3 });
                toast(r.message || r.error, r.success ? 'success' : 'error');
                if (r.success) loadData();
                return;
            }
            const u = e.target.closest('[data-user]'); if (u && window.AdminPro.openUser) window.AdminPro.openUser(+u.dataset.user);
        };
    }
    window.AdminPro.registerPage('adoption', pageAdoption);
    window.AdminPro.registerPage('trials', pageTrials);
    window.AdminPro.registerPage('errors', pageErrors);
    window.AdminPro.registerPage('blog', pageBlog);
    window.AdminPro.registerPage('billing-ops', pageBilling);
    window.AdminPro.registerPage('alerts', pageAlerts);
    window.AdminPro.registerPage('admin-security', pageAdminSecurity);
    window.AdminPro.registerPage('status-page', pageStatus);

    // شارة عدد التنبيهات المفتوحة في السايد بار
    // ADMIN_PERMS بيتعرّف بعد تحميل الملف ده، فبنستنى الصفحة تخلص
    const badge = () => {
        if (!can('system.view')) return;
        api('/api/admin/insights/alerts?status=open').then(r => {
            if (!r.success || !r.data.counts.total) return;
            const a = document.querySelector('.panel-nav-link[href="/admin/alerts"]');
            if (a && !a.querySelector('.ap-badge')) a.insertAdjacentHTML('beforeend', '<span class="ap-badge' + (r.data.counts.critical ? '' : ' medium') + '">' + r.data.counts.total + '</span>');
        });
    };
    if (document.readyState === 'complete') badge(); else window.addEventListener('load', badge);
})();
