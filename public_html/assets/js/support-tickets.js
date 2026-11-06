/* Tourfecto - تذاكر الدعم: صفحة العميل (mode=customer) وصفحة فريق الدعم في الأدمن (mode=admin) */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const STATUS_CLS = { open: 'open', pending: 'pending', resolved: 'done', closed: 'done' };
    let P, root, mode = 'customer', csrf = '', meta = { categories: {}, statuses: {}, priorities: {} }, adminData = null;
    let f = { status: 'active', priority: '', category: '', assigned: '', q: '', page: 1 };

    const base = () => (mode === 'admin' ? '/api/admin/support/tickets' : '/api/support/tickets');
    const api = async (url, body) => {
        const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: csrf }) } : undefined);
        if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
        if (r.data?.csrf_token) csrf = r.data.csrf_token;
        return r.data;
    };
    const toast = (msg, bad) => {
        const el = root.querySelector('#stMsg'); if (!el) return;
        el.textContent = msg; el.className = 'st-msg' + (bad ? ' bad' : ''); el.hidden = !msg;
        clearTimeout(toast.t); if (msg) toast.t = setTimeout(() => { el.hidden = true; }, 3000);
    };
    const run = async (fn, okMsg) => { try { const r = await fn(); if (okMsg) toast(okMsg); return r; } catch (e) { toast(e.message, true); return null; } };
    const when = s => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T') + 'Z'); return isNaN(d) ? s : d.toLocaleString('ar-EG', { dateStyle: 'medium', timeStyle: 'short' }); };
    const pill = (txt, cls) => `<span class="st-pill ${cls || ''}">${esc(txt)}</span>`;
    const statusPill = s => pill(meta.statuses[s] || s, STATUS_CLS[s]);
    const prioPill = p => pill(meta.priorities[p] || p, 'p-' + p);
    const opts = (map, sel, all) => (all ? `<option value="">${esc(all)}</option>` : '') + Object.entries(map).map(([k, v]) => `<option value="${esc(k)}"${k === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');

    const shell = inner => { root.innerHTML = `<div id="stMsg" class="st-msg" hidden></div>${inner}`; };

    // ---------------------------------------------------------------- القائمة
    async function showList() {
        if (mode === 'admin') return showAdminList();
        shell('<p>جاري التحميل…</p>');
        const d = await run(() => api(base()));
        if (!d) return;
        csrf = d.csrf_token || csrf; meta = d;
        const rows = d.tickets.map(t => `<button class="st-row" data-open="${t.id}"><span class="st-id">#${t.id}</span><span class="st-subj">${esc(t.subject)}</span>${statusPill(t.status)}<span class="st-muted">${esc(meta.categories[t.category] || '')}</span><span class="st-muted">${esc(when(t.last_activity_at))}</span>${t.last_reply_by === 'staff' && t.status === 'pending' ? pill('في رد جديد من الدعم', 'new') : ''}</button>`).join('');
        shell(`<div class="st-bar"><button class="st-btn primary" data-new>+ تذكرة جديدة</button></div>
            <div class="st-list">${rows || '<div class="st-empty">مفيش تذاكر لسه. لو عندك أي مشكلة أو سؤال افتح تذكرة جديدة.</div>'}</div>`);
    }

    function newForm() {
        shell(`<button class="st-btn" data-back>← رجوع</button><form class="st-form" id="stNew">
            <h3>تذكرة جديدة</h3>
            <label>نوع الطلب<select name="category">${opts(meta.categories, 'problem')}</select></label>
            <label>الأهمية<select name="priority">${opts({ low: meta.priorities.low, normal: meta.priorities.normal, high: meta.priorities.high }, 'normal')}</select></label>
            <label>العنوان<input name="subject" maxlength="200" required placeholder="اكتب العنوان في جملة قصيرة"></label>
            <label>التفاصيل<textarea name="message" rows="7" maxlength="5000" required placeholder="اشرح المشكلة أو السؤال أو الشكوى بالتفصيل، ولو في خطوات حصلت اكتبها"></textarea></label>
            <button class="st-btn primary" type="submit">إرسال</button></form>`);
        root.querySelector('#stNew').onsubmit = async e => {
            e.preventDefault();
            const v = Object.fromEntries(new FormData(e.target));
            const r = await run(() => api(base(), v));
            if (r) openTicket(r.id, 'تم فتح التذكرة. هنرد عليك في أقرب وقت');
        };
    }

    // ---------------------------------------------------------------- التذكرة
    async function openTicket(id, msg) {
        shell('<p>جاري التحميل…</p>');
        const d = await run(() => api(`${base()}/${id}`));
        if (!d) return showList();
        csrf = d.csrf_token || csrf;
        const t = d.ticket, admin = mode === 'admin';
        const closed = t.status === 'closed' || t.status === 'resolved';
        const msgs = d.messages.map(m => `<div class="st-msg-b ${m.author_type}"><div class="st-who">${m.author_type === 'customer' ? (admin ? esc(t.customer_name || 'العميل') : 'أنت') : m.author_type === 'note' ? '🔒 ملاحظة داخلية' + (m.author_name ? ' · ' + esc(m.author_name) : '') : 'فريق الدعم'} <span class="st-muted">${esc(when(m.created_at))}</span></div><div class="st-body">${esc(m.body)}</div></div>`).join('');

        const adminBar = admin ? `<div class="st-admin-bar">
            <label>الحالة<select data-set="status">${opts(meta.statuses, t.status)}</select></label>
            <label>الأولوية<select data-set="priority">${opts(meta.priorities, t.priority)}</select></label>
            <label>مسندة لـ<select data-set="assign"><option value="">— محدش —</option>${adminData.staff.map(s => `<option value="${s.id}"${+t.assigned_to === s.id ? ' selected' : ''}>${esc(s.email)}</option>`).join('')}</select></label>
            <button class="st-btn" data-take>خدها أنا</button></div>
            <div class="st-muted">العميل: ${esc(t.customer_name || '')} · ${esc(t.customer_email || '')}</div>` : '';

        const rating = !admin && closed ? (t.rating
            ? `<div class="st-rate">تقييمك: ${'★'.repeat(t.rating)}${'☆'.repeat(5 - t.rating)}</div>`
            : `<div class="st-rate">قيّم الدعم: ${[1, 2, 3, 4, 5].map(n => `<button class="st-star" data-rate="${n}" aria-label="${n}">★</button>`).join('')}</div>`) : '';

        const replyBox = admin
            ? `<form class="st-form" id="stReply"><textarea name="message" rows="5" maxlength="5000" placeholder="اكتب الرد…" required></textarea>
                <div class="st-actions"><label><input type="checkbox" name="internal"> ملاحظة داخلية (العميل مش هيشوفها)</label>
                <select name="status"><option value="pending">رد وانتظر العميل</option><option value="resolved">رد وعلّمها «تم الحل»</option><option value="closed">رد واقفلها</option></select>
                <button class="st-btn primary" type="submit">إرسال</button></div></form>`
            : (t.status === 'closed'
                ? '<div class="st-actions"><button class="st-btn" data-reopen>إعادة فتح التذكرة</button></div>'
                : `<form class="st-form" id="stReply"><textarea name="message" rows="4" maxlength="5000" placeholder="اكتب ردك…" required></textarea><div class="st-actions"><button class="st-btn primary" type="submit">إرسال</button><button class="st-btn" type="button" data-close>اقفل التذكرة (اتحلت)</button></div></form>`);

        shell(`<button class="st-btn" data-back>← رجوع</button>
            <div class="st-head"><h3>#${t.id} · ${esc(t.subject)}</h3><div>${statusPill(t.status)} ${prioPill(t.priority)} ${pill(meta.categories[t.category] || t.category)}</div></div>
            ${adminBar}<div class="st-thread">${msgs}</div>${rating}${replyBox}`);
        if (msg) toast(msg);

        const reload = m => openTicket(id, m);
        root.querySelector('#stReply')?.addEventListener('submit', async e => {
            e.preventDefault();
            const v = Object.fromEntries(new FormData(e.target)); v.internal = !!v.internal;
            if (await run(() => api(`${base()}/${id}/reply`, v))) reload('تم');
        });
        root.querySelector('[data-close]')?.addEventListener('click', async () => { if (await run(() => api(`${base()}/${id}/close`, {}))) reload('تم قفل التذكرة'); });
        root.querySelector('[data-reopen]')?.addEventListener('click', async () => { if (await run(() => api(`${base()}/${id}/reopen`, {}))) reload('تم إعادة فتح التذكرة'); });
        root.querySelectorAll('[data-rate]').forEach(b => b.addEventListener('click', async () => { if (await run(() => api(`${base()}/${id}/rate`, { rating: +b.dataset.rate }))) reload('شكرًا على تقييمك'); }));
        root.querySelectorAll('[data-set]').forEach(sel => sel.addEventListener('change', async () => {
            const k = sel.dataset.set, body = k === 'assign' ? { staff_id: sel.value } : { [k]: sel.value };
            if (await run(() => api(`${base()}/${id}/${k}`, body))) reload('تم');
        }));
        root.querySelector('[data-take]')?.addEventListener('click', async () => { if (await run(() => api(`${base()}/${id}/assign`, { staff_id: adminData.me }))) reload('التذكرة عليك'); });
    }

    // ---------------------------------------------------------------- أدمن
    async function showAdminList() {
        const qs = Object.entries(f).filter(([, v]) => v !== '' && v != null).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
        const d = await run(() => api(`${base()}?${qs}`));
        if (!d) return;
        adminData = d; csrf = d.csrf_token || csrf; meta = d;
        const s = d.stats;
        const stat = (l, v, c) => `<div class="st-stat ${c || ''}"><b>${v}</b><span>${l}</span></div>`;
        const rows = d.items.map(t => `<tr data-open="${t.id}"><td>#${t.id}</td><td>${esc(t.subject)}<div class="st-muted">${esc(t.customer_name || '')} · ${esc(t.customer_email || '')}</div></td><td>${statusPill(t.status)}</td><td>${prioPill(t.priority)}</td><td class="st-muted">${esc(meta.categories[t.category] || '')}</td><td class="st-muted">${esc(t.assignee_email || '—')}</td><td class="st-muted">${esc(when(t.last_activity_at))}</td></tr>`).join('');
        const pages = Math.max(1, Math.ceil(d.total / d.per));
        shell(`<div class="st-stats">${stat('مفتوحة (محتاجة رد)', s.open, s.open ? 'warn' : '')}${stat('في انتظار العميل', s.pending)}${stat('من غير موظف', s.unassigned, s.unassigned ? 'warn' : '')}${stat('أولوية عالية', s.high_priority, s.high_priority ? 'bad' : '')}${stat('اتقفلت آخر 7 أيام', s.closed_7d)}${stat('متوسط التقييم', s.avg_rating == null ? '—' : s.avg_rating + ' / 5')}</div>
            <div class="st-filters">
              <select data-f="status">${opts({ active: 'النشطة (مفتوحة + في الانتظار)', ...d.statuses }, f.status, 'كل الحالات')}</select>
              <select data-f="priority">${opts(d.priorities, f.priority, 'كل الأولويات')}</select>
              <select data-f="category">${opts(d.categories, f.category, 'كل الأنواع')}</select>
              <select data-f="assigned"><option value="">كل الموظفين</option><option value="none"${f.assigned === 'none' ? ' selected' : ''}>من غير موظف</option>${d.staff.map(x => `<option value="${x.id}"${String(x.id) === f.assigned ? ' selected' : ''}>${esc(x.email)}</option>`).join('')}</select>
              <input type="search" data-f="q" value="${esc(f.q)}" placeholder="بحث بالعنوان أو إيميل العميل أو رقم التذكرة">
            </div>
            <div class="st-table-wrap"><table class="st-table"><thead><tr><th>#</th><th>التذكرة</th><th>الحالة</th><th>الأولوية</th><th>النوع</th><th>الموظف</th><th>آخر نشاط</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="st-empty">مفيش تذاكر بالفلتر ده</td></tr>'}</tbody></table></div>
            ${pages > 1 ? `<div class="st-bar"><button class="st-btn" data-page="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>السابق</button><span class="st-muted">صفحة ${d.page} / ${pages}</span><button class="st-btn" data-page="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>التالي</button></div>` : ''}`);
        const q = root.querySelector('[data-f="q"]');
        if (q) q.onkeydown = e => { if (e.key === 'Enter') { f.q = q.value.trim(); f.page = 1; showAdminList(); } };
        root.querySelectorAll('select[data-f]').forEach(sel => sel.onchange = () => { f[sel.dataset.f] = sel.value; f.page = 1; showAdminList(); });
        root.querySelectorAll('[data-page]').forEach(b => b.onclick = () => { f.page = +b.dataset.page; showAdminList(); });
    }

    window.SupportTickets = {
        init(panel, opt) {
            P = panel; mode = (opt && opt.mode) || 'customer';
            root = document.getElementById('supportRoot');
            root.addEventListener('click', e => {
                const o = e.target.closest('[data-open]'); if (o) return openTicket(+o.dataset.open);
                if (e.target.closest('[data-new]')) return newForm();
                if (e.target.closest('[data-back]')) return showList();
            });
            const id = +new URLSearchParams(location.search).get('ticket');
            if (mode === 'admin') showAdminList().then(() => { if (id) openTicket(id); }); else (id ? api(base()).then(d => { meta = d; openTicket(id); }) : showList());
        },
    };
})();
