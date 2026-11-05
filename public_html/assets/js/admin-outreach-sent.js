/* Tourfecto - المرسول لهم: كل شركة اتبعتلها واتساب، حسب اليوم. الموظف بيشوف اللي بعتهم هو، ومالك المنصة بيشوف الكل ومين بعت */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = n => Number(n || 0).toLocaleString('en');
    const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
    const KIND = { first: 'أول رسالة', followup: 'متابعة' };
    const ACTIONS = [
        ['contacted', '✅ بعتّ تاني'], ['replied', '💬 ردّوا'], ['interested', '🔥 مهتمين'], ['trial', '🧪 جرّبوا المنصة'], ['subscribed', '🎉 اشتركوا'],
        ['call_back', '⏰ أكلمهم تاني'], ['not_interested', '🚫 مش مهتمين'], ['wrong_number', '📵 الرقم غلط'],
    ];
    const RESULT_CLS = { replied: 'green', interested: 'green', trial: 'green', subscribed: 'green', not_interested: 'dim', wrong_number: 'dim' };
    const RESULT_LABEL = { contacted: 'اتبعتلها', replied: 'ردّت', interested: 'مهتمة', trial: 'جرّبت المنصة', subscribed: 'اشتركت', call_back: 'هنكلمها تاني', not_interested: 'مش مهتمة', wrong_number: 'الرقم غلط' };
    let P, root, data = null, busy = false, csrf = '', canManage = false, lostReasons = {}, openRow = null;
    const today = () => ymd(new Date());
    const f = { from: today(), to: today(), staff_id: '', country: '', q: '' };

    const api = async (url, body) => {
        const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: csrf }) } : undefined);
        if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
        return r.data;
    };
    const toast = (msg, bad) => {
        const el = root.querySelector('#snMsg'); if (!el) return;
        el.textContent = msg; el.className = 'dk-msg' + (bad ? ' bad' : ''); el.hidden = !msg;
        clearTimeout(toast.t); if (msg) toast.t = setTimeout(() => { el.hidden = true; }, 2600);
    };
    const dayLabel = d => {
        const t = new Date(d + 'T00:00:00'), diff = Math.round((new Date(today() + 'T00:00:00') - t) / 86400000);
        const name = t.toLocaleDateString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long' });
        return (diff === 0 ? 'اليوم · ' : diff === 1 ? 'أمس · ' : '') + name;
    };
    const presets = () => {
        const t = new Date();
        return [['اليوم', today(), today()], ['أمس', ymd(addDays(t, -1)), ymd(addDays(t, -1))], ['آخر 7 أيام', ymd(addDays(t, -6)), today()], ['آخر 30 يوم', ymd(addDays(t, -29)), today()]];
    };

    const actPanel = x => {
        const reasonOpts = Object.entries(lostReasons).map(([k, l]) => `<option value="${k}" ${x.reason === k ? 'selected' : ''}>${esc(l)}</option>`).join('');
        return `<div class="dk-result"><div class="dk-phone"><b dir="ltr">${esc(x.phone_intl || x.phone)}</b>${x.phone_intl ? `<a class="dk-btn green" href="https://wa.me/${esc(x.phone_intl.replace('+', ''))}" target="_blank" rel="noopener noreferrer">💬 افتح واتساب</a>` : ''}</div>
            <div class="dk-actions">${ACTIONS.map(([k, l]) => `<button class="dk-btn ${x.result === k ? 'on' : ''}" data-sn-act="${k}">${l}</button>`).join('')}</div>
            <select class="dk-reason" data-sn-reason ${x.result === 'not_interested' ? '' : 'hidden'}><option value="">سبب الرفض…</option>${reasonOpts}</select>
            <input class="dk-note" data-sn-note placeholder="ملاحظة" maxlength="500" value="${esc(x.note || '')}">
            <label class="dk-followup-l">📅 موعد متابعة مخصّص (اختياري)<input type="datetime-local" data-sn-followup value="${x.followup_at ? esc(String(x.followup_at).replace(' ', 'T').slice(0, 16)) : ''}"></label></div>`;
    };
    const dayTable = (day, all) => `<section class="dk-day"><h3>${esc(dayLabel(day.date))} <small>(${num(day.count)} رسالة)</small></h3>
        <div class="dk-scroll"><table class="dk-tbl"><thead><tr><th>الوقت</th><th>الشركة</th><th>البلد</th><th>الرقم</th>${all ? '<th>مين بعت</th>' : ''}<th>النوع</th><th>النتيجة</th><th>ملاحظة</th><th>تفاصيل</th><th></th></tr></thead><tbody>
        ${day.items.map(x => `<tr data-row="${x.lead_id}"><td data-l="الوقت">${esc(x.at)}</td><td data-l="الشركة"><b>${esc(x.company)}</b></td><td data-l="البلد">${esc([x.country, x.city].filter(Boolean).join(' · '))}</td><td data-l="الرقم" dir="ltr">${esc(x.phone)}</td>${all ? `<td data-l="مين بعت">${esc(x.staff)}</td>` : ''}
            <td data-l="النوع"><span class="dk-b ${x.kind === 'followup' ? 'hot' : ''}">${KIND[x.kind]}</span></td><td data-l="النتيجة">${x.result && x.result !== 'contacted' ? `<span class="dk-b ${RESULT_CLS[x.result] || ''}">${esc(x.result_label)}</span>` : '<span class="dk-muted">لسه من غير رد</span>'}</td>
            <td data-l="ملاحظة">${esc(x.note)}</td><td data-l="تفاصيل" class="dk-sm">${x.email_step > 0 ? 'اتبعتلها إيميل ' + x.email_step : 'من غير إيميل'}${x.report_viewed ? ' · فتحت التقرير' : ''} · <a href="${esc(x.report_url)}" target="_blank" rel="noopener noreferrer">التقرير ↗</a></td>
            <td><button class="dk-btn xs" data-sn-toggle="${x.lead_id}">▾ تسجيل نتيجة</button></td></tr>
            <tr class="dk-act-row" data-act-row="${x.lead_id}" ${openRow === x.lead_id ? '' : 'hidden'}><td colspan="20">${actPanel(x)}</td></tr>`).join('')}
        </tbody></table></div></section>`;

    const render = () => {
        const all = !!data.all, countries = [...new Set(data.days.flatMap(d => d.items.map(i => i.country)).filter(Boolean))].sort();
        const pre = presets().map(([l, a, b]) => `<button class="dk-tab ${f.from === a && f.to === b ? 'on' : ''}" data-preset="${a}|${b}">${l}</button>`).join('');
        const staffSel = all ? `<label>الموظف <select data-f="staff_id"><option value="">الكل</option>${data.staff.map(s => `<option value="${s.staff_id}" ${String(f.staff_id) === String(s.staff_id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select></label>` : '';
        const chips = all && data.by_staff.length > 1 ? `<div class="dk-fn-row">${data.by_staff.map(s => `<button class="dk-chip ${String(f.staff_id) === String(s.staff_id) ? 'on' : ''}" data-staff="${s.staff_id}">${esc(s.name)} <b>${num(s.count)}</b></button>`).join('')}</div>` : '';
        root.innerHTML = `<div id="snMsg" class="dk-msg bad" hidden></div>
            <section class="dk-top"><div class="dk-tip">${all ? 'كل رسايل الواتساب اللي اتبعتت من كل الموظفين، حسب اليوم.' : 'الشركات اللي بعتّها على واتساب، حسب اليوم.'} <a href="/admin/outreach-desk">⬅ رجوع لمكتب التواصل</a></div>
                <div class="dk-bar"><div class="dk-tabs">${pre}</div></div>
                <div class="dk-st-bar"><label>من <input type="date" data-f="from" value="${esc(f.from)}"></label><label>إلى <input type="date" data-f="to" value="${esc(f.to)}"></label>${staffSel}
                    <label>البلد <select data-f="country"><option value="">الكل</option>${countries.map(c => `<option value="${esc(c)}" ${f.country === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></label>
                    <label>بحث <input data-f="q" placeholder="اسم الشركة أو الرقم" value="${esc(f.q)}"></label>
                    ${all ? '<button class="dk-btn" data-export>⬇️ تصدير CSV</button>' : ''}</div>
                <div class="dk-fn-row"><div class="dk-fn"><b>${num(data.total)}</b><span>رسالة في الفترة دي</span></div><div class="dk-fn"><b>${num(data.days.length)}</b><span>يوم فيه إرسال</span></div></div>${chips}</section>
            ${data.truncated ? '<p class="dk-msg bad">النتايج كتير: بتظهر آخر 2000 رسالة. الفترة أو الموظف يتضيّقوا للتفاصيل الباقية.</p>' : ''}
            <section class="dk-list">${data.days.length ? data.days.map(d => dayTable(d, all)).join('') : '<p class="dk-empty">مفيش رسايل اتبعتت في الفترة دي.</p>'}</section>`;
    };

    const csv = () => {
        const rows = [['التاريخ', 'الوقت', 'الموظف', 'الشركة', 'البلد', 'المدينة', 'الرقم', 'النوع', 'النتيجة', 'ملاحظة']];
        data.days.forEach(d => d.items.forEach(x => rows.push([d.date, x.at, x.staff, x.company, x.country, x.city, x.phone, KIND[x.kind], x.result_label, x.note])));
        const text = rows.map(r => r.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + text], { type: 'text/csv;charset=utf-8' })); a.download = `sent-${f.from}_${f.to}.csv`; document.body.appendChild(a); a.click(); a.remove();
    };

    const load = async () => {
        if (busy) return; busy = true;
        try {
            const q = new URLSearchParams({ from: f.from, to: f.to, staff_id: f.staff_id, country: f.country, q: f.q });
            data = await api('/api/admin/outreach-desk/sent?' + q.toString());
            f.from = data.from; f.to = data.to; csrf = data.csrf_token || csrf; canManage = !!data.can_manage; lostReasons = data.lost_reasons || lostReasons;
            render();
        } catch (e) { const m = root.querySelector('#snMsg'); if (m) { m.textContent = e.message; m.hidden = false; } else root.innerHTML = `<p class="dk-msg bad">${esc(e.message)}</p>`; }
        finally { busy = false; }
    };

    const findItem = id => { for (const d of data.days) { const x = d.items.find(i => i.lead_id === id); if (x) return x; } return null; };
    const mark = async (id, action, note, reason, followupAt) => {
        const r = await api('/api/admin/outreach-desk/leads/' + id + '/action', { action, note, reason: reason || '', followup_at: followupAt || '' });
        const x = findItem(id); if (!x) return;
        x.result = r.action || ''; x.result_label = r.action ? (RESULT_LABEL[r.action] || r.action) : ''; x.note = r.note || ''; x.reason = r.reason || null; x.followup_at = r.followup_at || null;
        openRow = id; render();
        toast('اتسجّل ✅');
    };
    const bind = () => {
        root.addEventListener('click', async e => {
            const t = e.target.closest('button'); if (!t) return;
            if (t.dataset.snToggle !== undefined) { const id = Number(t.dataset.snToggle); openRow = openRow === id ? null : id; return render(); }
            if (t.dataset.snAct) {
                const action = t.dataset.snAct, row = t.closest('[data-act-row]'), id = Number(row.dataset.actRow);
                const note = row.querySelector('[data-sn-note]')?.value || '', followupAt = (row.querySelector('[data-sn-followup]')?.value || '').replace('T', ' ');
                let reason = '';
                if (action === 'not_interested') {
                    const sel = row.querySelector('[data-sn-reason]');
                    if (sel.hidden) { sel.hidden = false; toast('اختاري سبب الرفض وبعدين دوسي تاني', true); return; }
                    reason = sel.value;
                    if (!reason) { toast('لازم تختاري سبب الرفض', true); return; }
                }
                t.disabled = true;
                try { await mark(id, action, note, reason, followupAt); } catch (err) { toast(err.message, true); } finally { t.disabled = false; }
                return;
            }
            if (t.dataset.preset) { [f.from, f.to] = t.dataset.preset.split('|'); return load(); }
            if (t.dataset.staff !== undefined) { f.staff_id = String(f.staff_id) === t.dataset.staff ? '' : t.dataset.staff; return load(); }
            if (t.dataset.export !== undefined) return csv();
        });
        let timer;
        root.addEventListener('change', e => { const k = e.target.dataset.f; if (!k || k === 'q') return; f[k] = e.target.value; load(); });
        root.addEventListener('input', e => {
            if (e.target.dataset.f !== 'q') return;
            f.q = e.target.value.trim(); clearTimeout(timer);
            timer = setTimeout(async () => { await load(); const q = root.querySelector('[data-f="q"]'); if (q) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); } }, 350);
        });
    };

    window.OutreachSent = {
        init: async panel => {
            P = panel; root = document.getElementById('sentRoot'); if (!root || !P) return;
            bind(); await load();
            setInterval(() => { if (!document.hidden && f.to === today()) load(); }, 30000); // اليوم الحالي بيتحدّث لوحده
        },
    };
})();
