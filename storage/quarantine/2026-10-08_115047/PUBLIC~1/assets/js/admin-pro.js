/**
 * Tourfecto - Admin Pro
 * طبقة تفاعلية فوق لوحة الأدمن: لوحة أوامر (Ctrl/⌘+K)، صفحة نظرة عامة بمؤشرات
 * مقارنة بالفترة السابقة، تنبيهات تحتاج تدخل، سجل نشاط، وأدوات جداول
 * (بحث/ترتيب/ترقيم/تصدير). بتتفعّل لوحدها على كل صفحات /admin.
 * @version 1.0.0
 */
(function () {
    'use strict';

    const P = window.Panel || {};
    const lang = (document.documentElement.lang || 'en').slice(0, 2);
    const isAr = lang === 'ar';
    const LOCALE = isAr ? 'ar-u-nu-latn' : 'en-US';   // أرقام لاتينية حتى في العربي (زي باقي اللوحة)
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
    const esc = P.esc || (v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
    const store = {
        get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* خصوصية/امتلاء: نتجاهل */ } },
    };
    const getJSON = async (url) => {
        try { return await (P.fetchJSON ? P.fetchJSON(url) : fetch(url, { credentials: 'same-origin' }).then(r => r.json())); }
        catch (e) { return { success: false }; }
    };

    // ------------------------------------------------------------------ نصوص
    const DICT = {
        en: {
            search: 'Search customers, sites, invoices…', go: 'Go to', actions: 'Actions', recent: 'Recent', results: 'Results',
            no_results: 'Nothing matches that search', navigate: 'navigate', open: 'open', close: 'close',
            'g.users': 'Customers', 'g.websites': 'Websites', 'g.invoices': 'Invoices', 'g.messages': 'Messages',
            export_users: 'Export customers (CSV)', export_subs: 'Export subscriptions (CSV)', refresh: 'Refresh data',
            density: 'Toggle compact tables', sidebar: 'Collapse sidebar', dashboard: 'Back to customer dashboard', site: 'Open main site',
            r7: '7 days', r30: '30 days', r90: '90 days', updated: 'Updated', just_now: 'just now', range_label: 'Time range',
            mrr: 'MRR', revenue: 'Cash in', signups: 'New customers', active_users: 'Active users', visitors: 'Real visitors',
            active_subs: 'Active subscriptions', arpu: 'ARPU', churn: 'Cancellations', ai_cost: 'AI cost', probes: 'Scanner bots', failed_logins: 'Failed logins',
            'tip.mrr': 'Monthly recurring revenue from active subscriptions. Yearly plans are divided by 12.',
            'tip.revenue': 'Completed wallet deposits in the period. Admin adjustments and recharge cards are excluded.',
            'tip.active_users': 'Distinct customers who signed in during the period.',
            'tip.visitors': 'Unique visitors on real pages. API calls and bot scans are excluded.',
            'tip.probes': 'Visitors that only probe paths like wp-admin or .env. They are not customers.',
            'tip.arpu': 'MRR divided by paying customers.',
            new: 'new', vs_prev: 'vs previous period',
            trend: 'Trend', m_revenue: 'Cash in', m_signups: 'Signups', m_uniques: 'Visitors', m_ai_cost: 'AI cost',
            attention: 'Needs attention', all_clear: 'All clear', all_clear_sub: 'Nothing needs your action right now.',
            'a.pending_deposits': '{n} deposit(s) waiting for approval', 'a.past_due': '{n} subscription(s) past due',
            'a.admins_no_2fa': '{n} admin account(s) without two-factor', 'a.failed_jobs': '{n} background job(s) failed in 24h',
            'a.suspicious_ips': '{n} IP(s) with 5+ failed logins in 24h', 'a.new_messages': '{n} unread contact message(s)',
            'a.expiring_soon': '{n} subscription(s) renew within 7 days', 'a.pending_refunds': '{n} refund(s) in progress',
            'a.active_blocks': '{n} active rate-limit block(s)', 'a.unverified_old': '{n} customer(s) never verified their email',
            activity: 'Recent activity', no_activity: 'No activity yet',
            'ev.signup': 'New customer', 'ev.subscription': 'Subscription', 'ev.wallet_pending': 'Deposit awaiting approval', 'ev.wallet_completed': 'Wallet transaction',
            'ev.wallet_rejected': 'Deposit rejected', 'ev.contact': 'Contact message', 'ev.impersonation': 'Admin impersonation', 'ev.audit_success': 'Audit', 'ev.audit_failed': 'Failed action',
            funnel: 'Signup funnel', plans: 'Recurring revenue by plan', no_plans: 'No active subscriptions',
            'f.signed': 'Signed up', 'f.verified': 'Verified email', 'f.has_site': 'Added a website', 'f.subscribed': 'Subscribed',
            traffic: 'Traffic quality', countries: 'Top countries', pages: 'Top pages', devices: 'Devices',
            probe_note: '{n} scanner visitors were excluded from these numbers. They only probed paths like wp-admin and are not customers.',
            ai: 'AI usage by feature', calls: 'calls', health: 'System health',
            'h.database': 'Database', 'h.queue': 'Job queue', 'h.job_failures': 'Job failures (24h)', 'h.ai_api': 'AI API errors (24h)', 'h.disk': 'Disk', 'h.storage_writable': 'Storage writable', 'h.php': 'PHP',
            load_failed: 'Could not load this section', retry: 'Retry',
            filter_rows: 'Filter rows…', rows: 'rows', of: 'of', page: 'Page', prev: 'Previous', next: 'Next', export_csv: 'Export CSV',
            sc: 'Shortcuts: G then O overview, U customers, S subscriptions, M messages',
            'map.title': 'Where your audience is', 'map.visitors': 'real visitors', 'map.in': 'from {n} countries', 'map.top': 'Top countries',
            'map.bubble': 'Visitors', 'map.pin': 'Customer locations', 'theme': 'Switch light / dark theme', 'map.tip': 'visitors',
        },
        ar: {
            search: 'ابحث عن عميل أو موقع أو فاتورة…', go: 'انتقال إلى', actions: 'إجراءات', recent: 'الأخيرة', results: 'النتائج',
            no_results: 'مفيش نتائج مطابقة', navigate: 'تنقّل', open: 'فتح', close: 'إغلاق',
            'g.users': 'العملاء', 'g.websites': 'المواقع', 'g.invoices': 'الفواتير', 'g.messages': 'الرسائل',
            export_users: 'تصدير العملاء (CSV)', export_subs: 'تصدير الاشتراكات (CSV)', refresh: 'تحديث البيانات',
            density: 'تبديل الجداول المضغوطة', sidebar: 'طيّ القائمة الجانبية', dashboard: 'الرجوع للوحة العميل', site: 'فتح الموقع الرئيسي',
            r7: '٧ أيام', r30: '٣٠ يوم', r90: '٩٠ يوم', updated: 'آخر تحديث', just_now: 'الآن', range_label: 'الفترة الزمنية',
            mrr: 'الإيراد الشهري المتكرر', revenue: 'الأموال الداخلة', signups: 'عملاء جدد', active_users: 'مستخدمون نشطون', visitors: 'زوار حقيقيون',
            active_subs: 'اشتراكات نشطة', arpu: 'متوسط الإيراد/عميل', churn: 'إلغاءات', ai_cost: 'تكلفة الذكاء الاصطناعي', probes: 'بوتات مسح', failed_logins: 'دخول فاشل',
            'tip.mrr': 'الإيراد الشهري المتكرر من الاشتراكات النشطة. الباقات السنوية بتتقسم على 12.',
            'tip.revenue': 'إيداعات المحفظة المكتملة في الفترة. تسويات الأدمن وكروت الشحن غير محسوبة.',
            'tip.active_users': 'عدد العملاء المختلفين اللي سجّلوا دخول في الفترة.',
            'tip.visitors': 'زوار فريدون على صفحات حقيقية. طلبات الـ API ومسح البوتات غير محسوبة.',
            'tip.probes': 'زوار بيجرّبوا مسارات زي wp-admin أو .env بس. مش عملاء.',
            'tip.arpu': 'الإيراد الشهري المتكرر مقسوم على العملاء الدافعين.',
            new: 'جديد', vs_prev: 'مقارنة بالفترة السابقة',
            trend: 'الاتجاه', m_revenue: 'الأموال الداخلة', m_signups: 'التسجيلات', m_uniques: 'الزوار', m_ai_cost: 'تكلفة AI',
            attention: 'محتاج تدخل', all_clear: 'كله تمام', all_clear_sub: 'مفيش حاجة محتاجة إجراء منك دلوقتي.',
            'a.pending_deposits': '{n} إيداع في انتظار الموافقة', 'a.past_due': '{n} اشتراك متأخر السداد',
            'a.admins_no_2fa': '{n} حساب أدمن من غير تحقق بخطوتين', 'a.failed_jobs': '{n} مهمة خلفية فشلت في آخر 24 ساعة',
            'a.suspicious_ips': '{n} عنوان IP فشل دخوله 5 مرات أو أكتر في 24 ساعة', 'a.new_messages': '{n} رسالة تواصل غير مقروءة',
            'a.expiring_soon': '{n} اشتراك بيتجدد خلال 7 أيام', 'a.pending_refunds': '{n} استرداد قيد التنفيذ',
            'a.active_blocks': '{n} حظر نشط بسبب الحد الأقصى للطلبات', 'a.unverified_old': '{n} عميل ما أكّدش بريده',
            activity: 'آخر النشاط', no_activity: 'مفيش نشاط لسه',
            'ev.signup': 'عميل جديد', 'ev.subscription': 'اشتراك', 'ev.wallet_pending': 'إيداع في انتظار الموافقة', 'ev.wallet_completed': 'معاملة محفظة',
            'ev.wallet_rejected': 'إيداع مرفوض', 'ev.contact': 'رسالة تواصل', 'ev.impersonation': 'انتحال حساب بواسطة أدمن', 'ev.audit_success': 'سجل تدقيق', 'ev.audit_failed': 'إجراء فاشل',
            funnel: 'مسار التسجيل', plans: 'الإيراد المتكرر حسب الباقة', no_plans: 'مفيش اشتراكات نشطة',
            'f.signed': 'سجّلوا', 'f.verified': 'أكّدوا البريد', 'f.has_site': 'أضافوا موقع', 'f.subscribed': 'اشتركوا',
            traffic: 'جودة الزيارات', countries: 'أعلى الدول', pages: 'أكثر الصفحات زيارة', devices: 'الأجهزة',
            probe_note: 'تم استبعاد {n} زائر من نوع بوت مسح من الأرقام دي. كانوا بيجرّبوا مسارات زي wp-admin بس، ومش عملاء.',
            ai: 'استخدام الذكاء الاصطناعي حسب الميزة', calls: 'طلب', health: 'صحة النظام',
            'h.database': 'قاعدة البيانات', 'h.queue': 'طابور المهام', 'h.job_failures': 'فشل المهام (24 ساعة)', 'h.ai_api': 'أخطاء AI API (24 ساعة)', 'h.disk': 'المساحة', 'h.storage_writable': 'التخزين قابل للكتابة', 'h.php': 'PHP',
            load_failed: 'تعذّر تحميل هذا القسم', retry: 'إعادة المحاولة',
            filter_rows: 'فلترة الصفوف…', rows: 'صف', of: 'من', page: 'صفحة', prev: 'السابق', next: 'التالي', export_csv: 'تصدير CSV',
            sc: 'اختصارات: G ثم O نظرة عامة، U العملاء، S الاشتراكات، M الرسائل',
            'map.title': 'أين جمهورك على الخريطة', 'map.visitors': 'زائر حقيقي', 'map.in': 'من {n} دولة', 'map.top': 'أعلى الدول',
            'map.bubble': 'الزوار', 'map.pin': 'مواقع العملاء', 'theme': 'تبديل الوضع الفاتح / الداكن', 'map.tip': 'زائر',
        },
    };
    const t = (k, vars) => {
        let s = (DICT[lang] && DICT[lang][k]) || DICT.en[k] || k;
        if (vars) Object.keys(vars).forEach(v => { s = s.replace('{' + v + '}', vars[v]); });
        return s;
    };

    // ------------------------------------------------------------------ أدوات
    const ICONS = {
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        refresh: '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
        user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        card: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        wallet: '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        list: '<path d="M4 6h16M4 12h16M4 18h16"/>',
        sidebar: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/>',
        download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
        home: '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        compass: '<circle cx="12" cy="12" r="10"/><path d="m16 8-2 6-6 2 2-6z"/>',
        globe: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
        file: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
    };
    const icon = (n) => '<svg viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[n] || ICONS.compass) + '</svg>';
    const NF = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
    const CF = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });
    const num = v => (Math.abs(v) >= 100000 ? CF.format(v) : NF.format(v));
    const money = v => '$' + (Math.abs(v) >= 100000 ? CF.format(v) : NF.format(Math.round(v * 100) / 100));
    const fmt = (v, f) => f === 'money' ? money(v) : f === 'usd' ? '$' + (v < 1 ? v.toFixed(4) : NF.format(v)) : f === 'pct' ? NF.format(v) + '%' : num(v);

    function parseUTC(s) { return s ? new Date(String(s).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z')) : null; }
    const RTF = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto', style: 'short' });
    function ago(s) {
        const d = parseUTC(s); if (!d || isNaN(d)) return '';
        const sec = Math.round((d.getTime() - Date.now()) / 1000), a = Math.abs(sec);
        if (a < 45) return t('just_now');
        if (a < 3600) return RTF.format(Math.round(sec / 60), 'minute');
        if (a < 86400) return RTF.format(Math.round(sec / 3600), 'hour');
        if (a < 2592000) return RTF.format(Math.round(sec / 86400), 'day');
        return d.toLocaleDateString(LOCALE, { year: 'numeric', month: 'short', day: 'numeric' });
    }
    const dayLabel = d => new Date(d + 'T00:00:00Z').toLocaleDateString(LOCALE, { month: 'short', day: 'numeric', timeZone: 'UTC' });

    function spark(values, tone) {
        if (!values || values.length < 2) return '';
        const w = 92, h = 28, max = Math.max.apply(null, values), min = Math.min.apply(null, values);
        const flat = max === min;
        const pts = values.map((v, i) => [i * (w / (values.length - 1)), flat ? h / 2 : h - 3 - ((v - min) / (max - min)) * (h - 6)]);
        const line = pts.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
        const color = tone === 'bad' ? 'var(--ap-bad)' : 'var(--ap-good)';
        return '<svg class="ap-spark" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true">' +
            (flat ? '<line x1="0" y1="' + h / 2 + '" x2="' + w + '" y2="' + h / 2 + '" stroke="var(--ap-line-strong)" stroke-dasharray="3 3"/>' :
                '<polygon points="0,' + h + ' ' + line + ' ' + w + ',' + h + '" fill="' + color + '" opacity=".12"/><polyline points="' + line + '" fill="none" stroke="' + color + '" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>') + '</svg>';
    }
    function delta(k) {
        if (k.delta === null || k.delta === undefined) return '<span class="ap-delta new" title="' + esc(t('vs_prev')) + '">' + esc(t('new')) + '</span>';
        if (k.delta === 0) return '<span class="ap-delta flat">0%</span>';
        const up = k.delta > 0, good = k.lower_is_better ? !up : up;
        return '<span class="ap-delta ' + (good ? 'good' : 'bad') + '" title="' + esc(t('vs_prev')) + '">' + (up ? '▲ ' : '▼ ') + Math.abs(k.delta) + '%</span>';
    }
    const toneOf = k => (k.delta === null || k.delta === 0) ? 'good' : ((k.delta > 0) !== !!k.lower_is_better ? 'good' : 'bad');

    // ------------------------------------------------------------------ لوحة الأوامر
    const Cmd = (function () {
        let bg, input, list, items = [], active = 0, opener = null, timer = null, token = 0;

        const staticItems = () => {
            const nav = $$('.panel-nav-link').map(a => ({
                group: t('go'), title: a.textContent.replace(/\s+/g, ' ').replace(/^\s*\d+\s*$/, '').trim().replace(/\s\d+$/, ''), url: a.getAttribute('href'), icon: 'compass',
            }));
            const acts = [
                { group: t('actions'), title: t('export_users'), url: '/admin/export/users', icon: 'download' },
                { group: t('actions'), title: t('export_subs'), url: '/admin/export/subscriptions', icon: 'download' },
                { group: t('actions'), title: t('refresh'), run: () => AdminPro.reload(), icon: 'refresh' },
                { group: t('actions'), title: t('density'), run: () => AdminPro.toggleDensity(), icon: 'list' },
                { group: t('actions'), title: t('sidebar'), run: () => AdminPro.toggleSidebar(), icon: 'sidebar' },
                { group: t('actions'), title: t('dashboard'), url: '/dashboard', icon: 'home' },
                { group: t('actions'), title: t('site'), url: '/', icon: 'globe' },
            ];
            return nav.concat(acts);
        };
        const match = (it, q) => {
            const hay = (it.title + ' ' + (it.sub || '')).toLowerCase();
            return q.toLowerCase().split(/\s+/).filter(Boolean).every(tok => hay.indexOf(tok) !== -1);
        };

        function build() {
            bg = document.createElement('div');
            bg.className = 'ap-cmd-bg';
            bg.innerHTML = '<div class="ap-cmd" role="dialog" aria-modal="true" aria-label="' + esc(t('search')) + '">' +
                '<div class="ap-cmd-input"><span class="ap-ico">' + icon('search') + '</span>' +
                '<input type="text" role="combobox" aria-expanded="true" aria-controls="apCmdList" autocomplete="off" spellcheck="false" placeholder="' + esc(t('search')) + '"><span class="ap-kbd">Esc</span></div>' +
                '<div class="ap-cmd-list" id="apCmdList" role="listbox"></div>' +
                '<div class="ap-cmd-foot"><span>↑↓ ' + esc(t('navigate')) + '</span><span>↵ ' + esc(t('open')) + '</span><span>' + esc(t('sc')) + '</span></div></div>';
            document.body.appendChild(bg);
            input = $('input', bg); list = $('.ap-cmd-list', bg);
            bg.addEventListener('mousedown', e => { if (e.target === bg) close(); });
            input.addEventListener('input', onInput);
            input.addEventListener('keydown', onKey);
            list.addEventListener('mousemove', e => { const el = e.target.closest('.ap-cmd-item'); if (el) setActive(+el.dataset.i, false); });
            list.addEventListener('click', e => { const el = e.target.closest('.ap-cmd-item'); if (el) run(items[+el.dataset.i]); });
        }

        function render(groups) {
            items = []; let html = '';
            groups.forEach(g => {
                if (!g.items.length) return;
                html += '<div class="ap-cmd-group">' + esc(g.label) + '</div>';
                g.items.forEach(it => {
                    html += '<div class="ap-cmd-item" role="option" id="apOpt' + items.length + '" data-i="' + items.length + '" aria-selected="false"><span class="ap-ico">' + icon(it.icon) + '</span><span class="t">' + esc(it.title) + '</span>' + (it.sub ? '<span class="s">' + esc(it.sub) + '</span>' : '') + '</div>';
                    items.push(it);
                });
            });
            list.innerHTML = html || '<div class="ap-cmd-empty">' + esc(t('no_results')) + '</div>';
            setActive(0, true);
        }
        function setActive(i, scroll) {
            active = Math.max(0, Math.min(items.length - 1, i));
            $$('.ap-cmd-item', list).forEach(el => el.setAttribute('aria-selected', +el.dataset.i === active ? 'true' : 'false'));
            const el = $('#apOpt' + active, list);
            if (el) { input.setAttribute('aria-activedescendant', el.id); if (scroll) el.scrollIntoView({ block: 'nearest' }); }
        }
        function base(q) {
            const all = staticItems();
            if (!q) {
                const rec = store.get('ap.recent', []).map(r => Object.assign({ group: t('recent'), icon: r.icon || 'compass' }, r));
                return [{ label: t('recent'), items: rec }, { label: t('go'), items: all.filter(i => i.group === t('go')) }, { label: t('actions'), items: all.filter(i => i.group === t('actions')) }];
            }
            const hit = all.filter(i => match(i, q));
            return [{ label: t('go'), items: hit.filter(i => i.group === t('go')) }, { label: t('actions'), items: hit.filter(i => i.group === t('actions')) }];
        }
        function onInput() {
            const q = input.value.trim();
            render(base(q));
            clearTimeout(timer);
            if (q.length < 2) return;
            const my = ++token;
            timer = setTimeout(async () => {
                const res = await getJSON('/api/admin/pro/search?q=' + encodeURIComponent(q));
                if (my !== token || !res.success) return;
                const groups = (res.data.groups || []).map(g => ({
                    label: t('g.' + g.type), items: g.items.map(i => ({ title: i.title, sub: i.sub, url: i.url, icon: g.type === 'users' ? 'user' : g.type === 'invoices' ? 'file' : g.type === 'messages' ? 'mail' : 'globe' })),
                }));
                render(groups.concat(base(q)));
            }, 180);
        }
        function onKey(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive((active + 1) % Math.max(1, items.length), true); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((active - 1 + items.length) % Math.max(1, items.length), true); }
            else if (e.key === 'Enter') { e.preventDefault(); if (items[active]) run(items[active]); }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'Tab') { e.preventDefault(); }   // الفوكس يفضل في الحقل
        }
        function run(it) {
            if (!it) return;
            if (it.url) {
                const rec = store.get('ap.recent', []).filter(r => r.url !== it.url);
                rec.unshift({ title: it.title, url: it.url, sub: it.sub, icon: it.icon });
                store.set('ap.recent', rec.slice(0, 5));
            }
            close();
            if (it.run) it.run(); else if (it.url) location.href = it.url;
        }
        function open() {
            if (!bg) build();
            opener = document.activeElement;
            bg.classList.add('open'); input.value = ''; render(base('')); input.focus();
        }
        function close() { if (!bg) return; bg.classList.remove('open'); if (opener && opener.focus) opener.focus(); }
        const isOpen = () => !!bg && bg.classList.contains('open');
        return { open, close, isOpen };
    })();

    // ------------------------------------------------------------------ أدوات الجداول
    function enhanceTable(table) {
        if (table.dataset.apEnh || table.closest('.p-modal') || table.id === 'latestUsersTable' || table.id === 'latestSubsTable') return;
        const tbody = table.tBodies[0]; if (!tbody) return;
        table.dataset.apEnh = '1';
        const st = { q: '', sort: -1, dir: 1, page: 1, size: 25, rows: [] };
        const scroll = table.closest('.p-table-scroll') || table;
        const card = scroll.parentElement;
        const ths = Array.from(table.tHead ? table.tHead.rows[0].cells : []);

        const tools = document.createElement('div');
        tools.className = 'ap-tools'; tools.hidden = true;
        tools.innerHTML = '<input type="search" placeholder="' + esc(t('filter_rows')) + '" aria-label="' + esc(t('filter_rows')) + '"><span class="count"></span><button type="button">' + esc(t('export_csv')) + '</button>';
        const pager = document.createElement('div');
        pager.className = 'ap-pager'; pager.hidden = true;
        pager.innerHTML = '<span class="info"></span><div class="btns"><button type="button" data-d="-1">' + esc(t('prev')) + '</button><button type="button" data-d="1">' + esc(t('next')) + '</button></div>';
        card.insertBefore(tools, scroll); scroll.after(pager);

        const isData = r => !r.querySelector('td[colspan]') && !r.classList.contains('p-loading-row');
        const isActionCol = i => { const rs = st.rows.slice(0, 5); return rs.length > 0 && rs.every(r => r.cells[i] && r.cells[i].querySelector('button, select')); };
        ths.forEach((th, i) => { if (th.textContent.trim()) { th.dataset.sortable = '1'; th.tabIndex = 0; th.setAttribute('role', 'columnheader'); } });
        const cellVal = (r, i) => { const c = r.cells[i]; return c ? (c.dataset.sort !== undefined ? c.dataset.sort : c.textContent).trim() : ''; };
        const NUM = /^[#$€£+-]?\s*[\d.,]+\s*%?$/;
        const cmp = (a, b) => {
            if (NUM.test(a) && NUM.test(b)) return parseFloat(a.replace(/[^\d.-]/g, '')) - parseFloat(b.replace(/[^\d.-]/g, ''));
            return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
        };
        const mo = new MutationObserver(() => { collect(); apply(); });

        function collect() { st.rows = Array.from(tbody.rows).filter(isData); }
        function filtered() {
            let rows = st.q ? st.rows.filter(r => r.textContent.toLowerCase().indexOf(st.q) !== -1) : st.rows.slice();
            if (st.sort >= 0) rows.sort((a, b) => st.dir * cmp(cellVal(a, st.sort), cellVal(b, st.sort)));
            return rows;
        }
        function apply() {
            mo.disconnect();
            const rows = filtered(), pages = Math.max(1, Math.ceil(rows.length / st.size));
            st.page = Math.min(st.page, pages);
            const from = (st.page - 1) * st.size, shown = new Set(rows.slice(from, from + st.size));
            if (st.sort >= 0) rows.forEach(r => tbody.appendChild(r));
            st.rows.forEach(r => { r.hidden = !shown.has(r); });
            const big = st.rows.length > 8 || st.q;
            tools.hidden = !big; pager.hidden = rows.length <= st.size;
            $('.count', tools).textContent = rows.length === st.rows.length ? st.rows.length + ' ' + t('rows') : rows.length + ' ' + t('of') + ' ' + st.rows.length;
            $('.info', pager).textContent = t('page') + ' ' + st.page + ' / ' + pages;
            $('[data-d="-1"]', pager).disabled = st.page <= 1; $('[data-d="1"]', pager).disabled = st.page >= pages;
            ths.forEach((th, i) => { if (i === st.sort) th.setAttribute('aria-sort', st.dir > 0 ? 'ascending' : 'descending'); else th.removeAttribute('aria-sort'); });
            mo.observe(tbody, { childList: true });
        }
        function exportCSV() {
            const skip = ths.map((_, i) => i === ths.length - 1 && isActionCol(i));
            const q = s => '"' + String(s).replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"';
            const lines = [ths.filter((_, i) => !skip[i]).map(th => q(th.textContent)).join(',')];
            filtered().forEach(r => lines.push(Array.from(r.cells).filter((_, i) => !skip[i]).map(c => q(c.dataset.sort !== undefined && /^\d{4}-\d\d-\d\d/.test(c.dataset.sort) ? c.dataset.sort : c.textContent)).join(',')));
            const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = (location.pathname.replace(/\W+/g, '-').replace(/^-|-$/g, '') || 'admin') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1500);
        }

        $('input', tools).addEventListener('input', e => { st.q = e.target.value.trim().toLowerCase(); st.page = 1; apply(); });
        $('button', tools).addEventListener('click', exportCSV);
        pager.addEventListener('click', e => { const b = e.target.closest('button[data-d]'); if (b) { st.page += +b.dataset.d; apply(); scroll.scrollTop = 0; } });
        const sortBy = th => { const i = ths.indexOf(th); if (i < 0 || !th.dataset.sortable) return; st.dir = st.sort === i ? -st.dir : 1; st.sort = i; apply(); };
        table.tHead && table.tHead.addEventListener('click', e => { const th = e.target.closest('th'); if (th) sortBy(th); });
        table.tHead && table.tHead.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { const th = e.target.closest('th'); if (th) { e.preventDefault(); sortBy(th); } } });

        collect(); apply();
    }

    // ------------------------------------------------------------------ صفحة النظرة العامة
    const S = { range: [7, 30, 90].indexOf(store.get('ap.range', 30)) >= 0 ? store.get('ap.range', 30) : 30, metric: 'revenue', data: null, chart: null, updatedAt: 0, timers: [] };
    const METRICS = [['revenue', 'm_revenue', 'money', '#1F4FFF'], ['signups', 'm_signups', 'int', '#0E9F6E'], ['uniques', 'm_uniques', 'int', '#6941C6'], ['ai_cost', 'm_ai_cost', 'usd', '#F5A524']];

    function section(id, title, sub, extra) {
        return '<section class="ap-panel" id="' + id + '"><div class="ap-panel-head"><h3>' + esc(title) + '</h3>' + (sub ? '<span class="ap-sub">' + esc(sub) + '</span>' : '') + (extra || '') + '</div><div class="ap-panel-body"></div></section>';
    }
    const body = id => $('#' + id + ' .ap-panel-body');
    const skel = (h) => '<div class="ap-skel" style="height:' + h + 'px"></div>';
    const failed = (id) => '<div class="ap-empty">' + esc(t('load_failed')) + ' · <a href="#" data-retry="' + id + '">' + esc(t('retry')) + '</a></div>';

    function shell(root) {
        const seg = [7, 30, 90].map(d => '<button type="button" data-range="' + d + '" aria-pressed="' + (d === S.range) + '">' + esc(t('r' + d)) + '</button>').join('');
        root.innerHTML =
            '<div class="ap-bar"><div class="ap-seg" role="group" aria-label="' + esc(t('range_label')) + '">' + seg + '</div><span class="ap-spacer"></span><span class="ap-updated" id="apUpdated"></span></div>' +
            '<section class="ap-panel ap-hero" id="apHero"><div class="ap-hero-side"><div><h2 class="ap-hero-title">' + esc(t('map.title')) + '</h2><div class="ap-hero-big ap-num" id="apHeroBig">&nbsp;</div><div class="ap-hero-sub" id="apHeroSub"></div></div><div class="ap-hero-list" id="apHeroList"></div><div class="ap-hero-legend" id="apHeroLegend"></div></div><div class="ap-map" id="apMap" role="img" aria-label="' + esc(t('map.title')) + '"></div></section>' +
            '<section class="ap-panel ap-pulse" id="apPulse" aria-live="polite">' + new Array(5).fill('<div class="ap-kpi">' + skel(76) + '</div>').join('') + '</section>' +
            '<section class="ap-panel ap-minis" id="apMinis">' + new Array(6).fill('<div class="ap-mini">' + skel(34) + '</div>').join('') + '</section>' +
            '<div class="ap-grid main">' +
            '<section class="ap-panel" id="apTrend"><div class="ap-panel-head"><h3>' + esc(t('trend')) + '</h3><div class="ap-tabs" role="tablist">' +
            METRICS.map(m => '<button type="button" role="tab" data-metric="' + m[0] + '" aria-selected="' + (m[0] === S.metric) + '">' + esc(t(m[1])) + '</button>').join('') + '</div></div>' +
            '<div class="ap-panel-body"><div class="ap-chart-total ap-num" id="apTrendTotal">&nbsp;</div><div class="ap-chart"><canvas id="apTrendCanvas" role="img"></canvas></div></div></section>' +
            '<section class="ap-panel ap-attn" id="apAttn"><div class="ap-panel-head"><h3>' + esc(t('attention')) + '</h3></div><div class="ap-panel-body">' + skel(150) + '</div></section></div>' +
            '<div class="ap-grid thirds">' + section('apFeed', t('activity')) + section('apFunnel', t('funnel')) + section('apHealth', t('health')) + '</div>' +
            '<div class="ap-grid halves">' + section('apTraffic', t('traffic')) + section('apAI', t('ai')) + '</div>';
        ['apFeed', 'apFunnel', 'apHealth', 'apTraffic', 'apAI'].forEach(id => { body(id).innerHTML = skel(120); });
        // الفيد والتنبيهات بيرسموا قوايمهم بـ padding خاص، فنصفّر padding الحاوية بعد أول رسم
        ['apFeed', 'apAttn'].forEach(id => { body(id).dataset.flush = '1'; });
    }

    function renderPulse(d) {
        const K = d.kpis, main = ['mrr', 'revenue', 'signups', 'active_users', 'visitors'];
        $('#apPulse').innerHTML = main.map(key => {
            const k = K[key];
            return '<div class="ap-kpi"><div class="ap-kpi-label">' + esc(t(key)) + (DICT.en['tip.' + key] ? '<i title="' + esc(t('tip.' + key)) + '">?</i>' : '') + '</div>' +
                '<div class="ap-kpi-value">' + esc(fmt(k.value, k.format)) + '</div>' +
                '<div class="ap-kpi-foot">' + delta(k) + (k.spark ? spark(k.spark, toneOf(k)) : '') + '</div></div>';
        }).join('');
        const minis = [['active_subs', K.active_subs], ['arpu', K.arpu], ['churn', K.churn], ['ai_cost', K.ai_cost], ['probes', K.probes], ['failed_logins', K.failed_logins]];
        $('#apMinis').innerHTML = minis.map(m => {
            const k = m[1];
            return '<div class="ap-mini"><div class="ap-mini-label" title="' + esc(DICT.en['tip.' + m[0]] ? t('tip.' + m[0]) : t(m[0])) + '">' + esc(t(m[0])) + '</div><div class="ap-mini-row"><span class="ap-mini-value">' + esc(fmt(k.value, k.format)) + '</span>' + (k.delta !== undefined ? delta(k) : '') + '</div></div>';
        }).join('');
    }

    function renderTrend() {
        const d = S.data; if (!d) return;
        const m = METRICS.filter(x => x[0] === S.metric)[0];
        const vals = d.series[S.metric] || [];
        const total = vals.reduce((a, b) => a + b, 0);
        $('#apTrendTotal').textContent = fmt(S.metric === 'uniques' ? d.kpis.visitors.value : total, m[2]);
        $$('#apTrend [data-metric]').forEach(b => b.setAttribute('aria-selected', b.dataset.metric === S.metric ? 'true' : 'false'));
        const canvas = $('#apTrendCanvas');
        if (!canvas) return;
        canvas.setAttribute('aria-label', t(m[1]) + ': ' + fmt(total, m[2]));
        if (!window.Chart) { canvas.replaceWith(Object.assign(document.createElement('div'), { className: 'ap-empty', textContent: '—' })); return; }
        const cfg = {
            labels: d.labels.map(dayLabel),
            datasets: [{ data: vals, borderColor: m[3], backgroundColor: m[3] + '1f', fill: true, tension: .35, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2 }],
        };
        if (S.chart) { S.chart.data = cfg; S.chart.options.plugins.tooltip.callbacks.label = c => ' ' + fmt(c.parsed.y, m[2]); S.chart.update(); return; }
        const css = getComputedStyle(document.documentElement);
        const muted = (css.getPropertyValue('--panel-text-muted') || '#667085').trim();
        const grid = (css.getPropertyValue('--ap-chart-grid') || 'rgba(0,0,0,.07)').trim();
        S.chart = new Chart(canvas, {
            type: 'line', data: cfg,
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, animation: { duration: 350 },
                plugins: { legend: { display: false }, tooltip: { displayColors: false, callbacks: { label: c => ' ' + fmt(c.parsed.y, m[2]) } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: muted, maxTicksLimit: window.innerWidth < 640 ? 4 : 8, autoSkip: true, maxRotation: 0, font: { family: 'Readex Pro' } } },
                    y: { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: muted, maxTicksLimit: 5, callback: v => (m[2] === 'money' ? '$' : '') + CF.format(v) } },
                },
            },
        });
    }

    function renderAttention(res) {
        const root = body('apAttn');
        if (!res || !res.success) { root.innerHTML = failed('attn'); return; }
        const items = res.data.items || [];
        root.style.padding = items.length ? '0' : '';
        root.innerHTML = items.length
            ? '<ul class="ap-attn-list">' + items.map(i => '<li><a href="' + esc(i.link) + '"><span class="ap-sev ' + esc(i.severity) + '"></span><span class="ap-attn-text">' + esc(t('a.' + i.key, { n: i.count })) + '</span><span class="ap-attn-count">' + i.count + '</span></a></li>').join('') + '</ul>'
            : '<div class="ap-clear"><b>' + esc(t('all_clear')) + '</b>' + esc(t('all_clear_sub')) + '</div>';
        applyBadges(items, res.data.total || 0);
    }

    const EV = {
        signup: ['user', 'info'], subscription: ['card', 'good'], wallet_pending: ['wallet', 'warn'], wallet_completed: ['wallet', 'good'], wallet_rejected: ['wallet', 'bad'],
        contact: ['mail', 'info'], impersonation: ['shield', 'warn'], audit_success: ['file', ''], audit_failed: ['alert', 'bad'],
    };
    function renderFeed(res) {
        const root = body('apFeed');
        if (!res || !res.success) { root.innerHTML = failed('feed'); return; }
        const it = res.data.items || [];
        root.style.padding = it.length ? '0' : '';
        root.innerHTML = it.length ? '<ul class="ap-feed">' + it.map(e => {
            const m = EV[e.type] || ['file', ''];
            return '<li><a href="' + esc(e.link) + '"><span class="ap-feed-ico ' + m[1] + '">' + icon(m[0]) + '</span><span class="ap-feed-main">' + esc(e.title) + '<small>' + esc(t('ev.' + e.type)) + (e.sub ? ' · ' + esc(e.sub) : '') + '</small></span><span class="ap-feed-time">' + esc(ago(e.ts)) + '</span></a></li>';
        }).join('') + '</ul>' : '<div class="ap-empty">' + esc(t('no_activity')) + '</div>';
    }

    function bars(rows, key, labelFn, valFn, cls) {
        const max = Math.max.apply(null, rows.map(r => +r[key]).concat([1]));
        return '<div class="ap-list">' + rows.map(r => '<div class="ap-row"><span class="name" title="' + esc(labelFn(r)) + '">' + esc(labelFn(r)) + '</span><span class="val">' + esc(valFn(r)) + '</span><span class="bar"><i class="' + (cls || '') + '" style="width:' + Math.max(2, Math.round(100 * r[key] / max)) + '%"></i></span></div>').join('') + '</div>';
    }
    function renderFunnel(d) {
        const f = d.funnel, top = Math.max(f[0].value, 1);
        let html = '<div class="ap-funnel">' + f.map((s, i) => '<div class="ap-fstep"><span>' + esc(t('f.' + s.key)) + '</span><span class="ap-num">' + (i ? '<em>' + Math.round(100 * s.value / Math.max(f[i - 1].value, 1)) + '%</em>' : '') + '<span>' + s.value + '</span></span><span class="bar"><i style="width:' + (s.value ? Math.max(3, Math.round(100 * s.value / top)) : 0) + '%"></i></span></div>').join('') + '</div>';
        html += '<div class="ap-sec-title">' + esc(t('plans')) + '</div>';
        html += d.plans.length ? bars(d.plans, 'mrr', p => p.name + ' · ' + p.subs, p => money(+p.mrr), 'gold') : '<div class="ap-empty">' + esc(t('no_plans')) + '</div>';
        body('apFunnel').innerHTML = html;
    }
    function renderTraffic(d) {
        const dev = d.devices, tot = dev.reduce((a, b) => a + +b.visitors, 0) || 1, pal = ['var(--panel-accent)', 'var(--panel-teal)', 'var(--ap-info)', 'var(--ap-bad)'];
        let html = '<div class="ap-sec-title">' + esc(t('devices')) + '</div><div class="ap-split">' + dev.map((x, i) => '<i style="width:' + (100 * x.visitors / tot) + '%;background:' + pal[i % 4] + '"></i>').join('') + '</div><div class="ap-legend">' + dev.map((x, i) => '<span><b style="background:' + pal[i % 4] + '"></b>' + esc(x.device) + ' ' + Math.round(100 * x.visitors / tot) + '%</span>').join('') + '</div>';
        html += '<div class="ap-sec-title">' + esc(t('pages')) + '</div>' + (d.pages.length ? bars(d.pages, 'views', p => p.page_url, p => num(+p.views), 'violet') : '<div class="ap-empty">—</div>');
        if (d.kpis.probes.value > 0) html += '<div class="ap-note">' + esc(t('probe_note', { n: num(d.kpis.probes.value) })) + '</div>';
        body('apTraffic').innerHTML = html;
    }
    function renderAI(d) {
        body('apAI').innerHTML = d.ai_features.length ? bars(d.ai_features, 'calls', f => f.feature, f => num(+f.calls) + ' ' + t('calls') + ' · $' + (+f.cost).toFixed(4), '') : '<div class="ap-empty">—</div>';
    }
    function renderMap(d) {
        const geo = window.TF_GEO, host = $('#apMap');
        if (!host) return;
        const all = (d.countries_all || []).filter(c => c.country && c.country !== 'Unknown');
        const total = d.kpis.visitors.value;
        $('#apHeroBig').textContent = num(total);
        $('#apHeroSub').innerHTML = delta(d.kpis.visitors).replace('ap-delta', 'ap-delta') + ' &nbsp;<span>' + esc(t('map.visitors')) + ' · ' + esc(t('map.in', { n: all.length })) + '</span>';
        const max = Math.max.apply(null, all.map(c => +c.visitors).concat([1]));
        $('#apHeroList').innerHTML = all.slice(0, 5).map(c => '<div class="ap-hero-row"><span class="name">' + esc(c.country) + '</span><span class="val">' + num(+c.visitors) + '</span><span class="bar"><i style="width:' + Math.max(3, Math.round(100 * c.visitors / max)) + '%"></i></span></div>').join('');
        const pins = d.pins || [];
        $('#apHeroLegend').innerHTML = '<span><b style="background:#6C8CFF"></b>' + esc(t('map.bubble')) + '</span>' + (pins.length ? '<span><b style="background:#F5A524"></b>' + esc(t('map.pin')) + ' · ' + pins.length + '</span>' : '');
        if (!geo) { host.innerHTML = ''; return; }
        const W = 1000, H = Math.round(W * 138 / 360), X = lon => (lon + 180) / 360 * W, Y = lat => (80 - lat) / 138 * H;
        let land = '';
        for (let i = 0; i < geo.dots.length; i += 2) land += 'M' + X(geo.dots[i]).toFixed(1) + ' ' + Y(geo.dots[i + 1]).toFixed(1) + 'h.01';
        let marks = '', first = true;
        all.slice().sort((a, b) => b.visitors - a.visitors).forEach(c => {
            const ll = geo.cent[c.country]; if (!ll) return;
            const r = 4 + 17 * Math.sqrt(c.visitors / max), x = X(ll[0]).toFixed(1), y = Y(ll[1]).toFixed(1);
            marks += '<g data-tip="' + esc(c.country) + '" data-n="' + num(+c.visitors) + '" data-x="' + x + '" data-y="' + y + '"><circle class="halo" cx="' + x + '" cy="' + y + '" r="' + (r * 1.9).toFixed(1) + '"/>' + (first ? '<circle class="ping" cx="' + x + '" cy="' + y + '" r="' + r.toFixed(1) + '"/>' : '') + '<circle class="bub" cx="' + x + '" cy="' + y + '" r="' + r.toFixed(1) + '"/></g>';
            first = false;
        });
        pins.forEach(p => {
            const x = X(p.lng).toFixed(1), y = Y(p.lat).toFixed(1);
            marks += '<g data-tip="' + esc(p.name || '') + '" data-n="" data-x="' + x + '" data-y="' + y + '"><circle class="pin-ring" cx="' + x + '" cy="' + y + '" r="9"/><circle class="pin" cx="' + x + '" cy="' + y + '" r="4.5"/></g>';
        });
        host.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><path class="land" stroke-width="3.1" d="' + land + '"/>' + marks + '</svg><div class="ap-map-tip"></div>';
        const tip = $('.ap-map-tip', host), svg = $('svg', host);
        host.onmousemove = e => {
            const g = e.target.closest('g[data-tip]');
            if (!g) { tip.style.display = 'none'; return; }
            const b = host.getBoundingClientRect(), sc = Math.min(b.width / W, b.height / H), ox = (b.width - W * sc) / 2, oy = (b.height - H * sc) / 2;
            tip.innerHTML = esc(g.dataset.tip) + (g.dataset.n ? '<small>' + esc(g.dataset.n) + ' ' + esc(t('map.tip')) + '</small>' : '');
            tip.style.left = (ox + g.dataset.x * sc) + 'px'; tip.style.top = (oy + g.dataset.y * sc) + 'px'; tip.style.display = 'block';
        };
        host.onmouseleave = () => { tip.style.display = 'none'; };
    }

    function renderHealth(res) {
        const root = body('apHealth');
        if (!res || !res.success) { root.innerHTML = failed('health'); return; }
        const sc = res.data.score, r = 38, c = 2 * Math.PI * r, col = sc >= 85 ? 'var(--ap-good)' : sc >= 60 ? 'var(--ap-warn)' : 'var(--ap-bad)';
        root.innerHTML = '<div class="ap-health"><div class="ap-ring"><svg viewBox="0 0 92 92"><circle cx="46" cy="46" r="' + r + '" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="7"/><circle cx="46" cy="46" r="' + r + '" fill="none" stroke="' + col + '" stroke-width="7" stroke-linecap="round" stroke-dasharray="' + (c * sc / 100).toFixed(1) + ' ' + c.toFixed(1) + '"/></svg><b>' + sc + '</b></div>' +
            '<div class="ap-checks">' + res.data.checks.map(k => '<div class="ap-check"><span class="ap-dot ' + (k.status === 'ok' ? '' : k.status) + '"></span><span>' + esc(t('h.' + k.key)) + '</span><span class="v">' + esc(k.value) + '</span></div>').join('') + '</div></div>';
    }

    function applyBadges(items, total) {
        $$('.ap-badge').forEach(b => b.remove());
        const by = {};
        items.forEach(i => { if (i.severity !== 'low') { const b = by[i.link] || (by[i.link] = { n: 0, sev: 'medium' }); b.n += i.count; if (i.severity === 'high') b.sev = 'high'; } });
        $$('.panel-nav-link').forEach(a => { const b = by[a.getAttribute('href')]; if (b) a.insertAdjacentHTML('beforeend', '<span class="ap-badge ' + b.sev + '">' + b.n + '</span>'); });
        const base = document.title.replace(/^\(\d+\)\s*/, '');
        document.title = total > 0 ? '(' + total + ') ' + base : base;
    }

    function tickUpdated() {
        const el = $('#apUpdated'); if (!el || !S.updatedAt) return;
        const s = Math.round((Date.now() - S.updatedAt) / 1000);
        const when = s < 45 ? t('just_now') : s < 3600 ? RTF.format(-Math.max(1, Math.round(s / 60)), 'minute') : RTF.format(-Math.round(s / 3600), 'hour');
        el.textContent = t('updated') + ' ' + when;
    }

    async function loadAll(only) {
        const refreshBtn = $('#apRefresh'); if (refreshBtn) refreshBtn.classList.add('spin');
        const want = n => !only || only === n;
        const [ov, at, ac, he] = await Promise.all([
            want('overview') ? getJSON('/api/admin/pro/overview?range=' + S.range) : null,
            want('attn') ? getJSON('/api/admin/pro/attention') : null,
            want('feed') ? getJSON('/api/admin/pro/activity?limit=20') : null,
            want('health') ? getJSON('/api/admin/pro/health') : null,
        ]);
        if (ov) {
            if (ov.success) {
                S.data = ov.data; renderMap(ov.data); renderPulse(ov.data); renderTrend(); renderFunnel(ov.data); renderTraffic(ov.data); renderAI(ov.data);
            } else { ['apPulse', 'apMinis'].forEach(id => { $('#' + id).innerHTML = '<div class="ap-kpi">' + failed('overview') + '</div>'; }); }
        }
        if (at) renderAttention(at);
        if (ac) renderFeed(ac);
        if (he) renderHealth(he);
        S.updatedAt = Date.now(); tickUpdated();
        if (refreshBtn) refreshBtn.classList.remove('spin');
    }

    function startTimers() {
        S.timers.forEach(clearInterval); S.timers = [];
        S.timers.push(setInterval(() => { if (!document.hidden) { loadAll('attn'); loadAll('feed'); } }, 60000));
        S.timers.push(setInterval(tickUpdated, 15000));
        document.addEventListener('visibilitychange', () => { if (!document.hidden && Date.now() - S.updatedAt > 120000) loadAll(); });
    }

    async function renderOverview() {
        const root = $('#proOverview'); if (!root) return;
        shell(root);
        root.addEventListener('click', e => {
            const r = e.target.closest('[data-range]');
            if (r) { S.range = +r.dataset.range; store.set('ap.range', S.range); $$('[data-range]', root).forEach(b => b.setAttribute('aria-pressed', b === r ? 'true' : 'false')); loadAll('overview'); return; }
            const m = e.target.closest('[data-metric]'); if (m) { S.metric = m.dataset.metric; renderTrend(); return; }
            const rt = e.target.closest('[data-retry]'); if (rt) { e.preventDefault(); loadAll(rt.dataset.retry); }
        });
        await loadAll();
        startTimers();
    }

    // ------------------------------------------------------------------ الهيكل العام
    function mountTopbar() {
        const bar = $('.panel-topbar-actions'); if (!bar || $('.ap-search-btn')) return;
        const mac = /Mac|iPhone|iPad/.test(navigator.platform || '');
        bar.insertAdjacentHTML('afterbegin',
            '<button type="button" class="ap-search-btn" id="apSearchBtn" aria-label="' + esc(t('search')) + '"><span class="ap-ico" style="display:grid">' + icon('search') + '</span><span>' + esc(t('search')) + '</span><kbd class="ap-kbd">' + (mac ? '⌘K' : 'Ctrl K') + '</kbd></button>' +
            (S_hasOverview() ? '<button type="button" class="ap-icon-btn" id="apRefresh" title="' + esc(t('refresh')) + '" aria-label="' + esc(t('refresh')) + '">' + icon('refresh') + '</button>' : '') +
            '<button type="button" class="ap-icon-btn ap-theme-btn" id="apTheme" title="' + esc(t('theme')) + '" aria-label="' + esc(t('theme')) + '"><span class="sun">' + icon('sun') + '</span><span class="moon">' + icon('moon') + '</span></button>' +
            '<button type="button" class="ap-icon-btn" id="apDensity" title="' + esc(t('density')) + '" aria-label="' + esc(t('density')) + '">' + icon('list') + '</button>' +
            '<button type="button" class="ap-icon-btn" id="apSide" title="' + esc(t('sidebar')) + '" aria-label="' + esc(t('sidebar')) + '">' + icon('sidebar') + '</button>');
        $('#apSearchBtn').addEventListener('click', Cmd.open);
        const rf = $('#apRefresh'); if (rf) rf.addEventListener('click', () => AdminPro.reload());
        $('#apTheme').addEventListener('click', () => AdminPro.toggleTheme());
        $('#apDensity').addEventListener('click', () => AdminPro.toggleDensity());
        $('#apSide').addEventListener('click', () => AdminPro.toggleSidebar());
    }
    const S_hasOverview = () => !!$('#proOverview');

    function bindKeys() {
        let seq = null;
        const typing = el => el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable);
        const GO = { o: '/admin', p: '/admin/platform', u: '/admin/users', s: '/admin/subscriptions', m: '/admin/contact-messages', v: '/admin/visitors', l: '/admin/login-history', y: '/admin/system', e: '/admin/settings' };
        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); Cmd.isOpen() ? Cmd.close() : Cmd.open(); return; }
            if (Cmd.isOpen() || typing(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
            if (e.key === '/') { e.preventDefault(); Cmd.open(); return; }
            if (seq && GO[e.key.toLowerCase()]) { e.preventDefault(); location.href = GO[e.key.toLowerCase()]; return; }
            seq = e.key.toLowerCase() === 'g' ? setTimeout(() => { seq = null; }, 1200) : null;
        });
    }

    const AdminPro = {
        renderOverview, ago,
        reload() { if (S_hasOverview()) loadAll(); else location.reload(); },
        toggleTheme() {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next; store.set('ap.theme', next);
            if (S.chart) { S.chart.destroy(); S.chart = null; renderTrend(); }
        },
        toggleDensity() { const on = document.body.classList.toggle('ap-compact'); store.set('ap.compact', on); },
        toggleSidebar() { const on = document.body.classList.toggle('ap-collapsed'); store.set('ap.collapsed', on); },
        search: Cmd.open,
    };
    window.AdminPro = AdminPro;

    function init() {
        document.body.classList.add('ap-theme');
        if (!document.documentElement.dataset.theme) document.documentElement.dataset.theme = store.get('ap.theme', window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (store.get('ap.compact', false)) document.body.classList.add('ap-compact');
        if (store.get('ap.collapsed', false)) document.body.classList.add('ap-collapsed');
        $$('.panel-nav-link').forEach(a => { if (!a.title) a.title = a.textContent.trim(); });
        mountTopbar(); bindKeys();
        $$('.p-table').forEach(enhanceTable);
        // ?user=ID (جاي من البحث الشامل) يفتح كارت العميل مباشرة
        const uid = new URLSearchParams(location.search).get('user');
        if (uid && /^\d+$/.test(uid)) window.addEventListener('load', () => { if (typeof window.viewUser === 'function') window.viewUser(+uid); });
        // شارات السايد بار في كل الصفحات (النظرة العامة بتحدّثها بنفسها)
        if (!S_hasOverview()) getJSON('/api/admin/pro/attention').then(r => { if (r.success) applyBadges(r.data.items || [], r.data.total || 0); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
