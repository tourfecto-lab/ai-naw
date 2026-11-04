/* Tourfecto - المرسول لهم: كل شركة اتبعتلها واتساب، حسب اليوم. الموظف بيشوف اللي بعتهم هو، ومالك المنصة بيشوف الكل ومين بعت */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = n => Number(n || 0).toLocaleString('en');
    const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
    const KIND = { first: 'أول رسالة', followup: 'متابعة' };
    const RESULT_CLS = { replied: 'green', interested: 'green', trial: 'green', subscribed: 'green', not_interested: 'dim', wrong_number: 'dim' };
    let P, root, data = null, busy = false;
    const today = () => ymd(new Date());
    const f = { from: today(), to: today(), staff_id: '', country: '', q: '' };

    const api = async url => {
        const r = await P.fetchJSON(url);
        if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
        return r.data;
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

    const dayTable = (day, all) => `<section class="dk-day"><h3>${esc(dayLabel(day.date))} <small>(${num(day.count)} رسالة)</small></h3>
        <div class="dk-scroll"><table class="dk-tbl"><thead><tr><th>الوقت</th><th>الشركة</th><th>البلد</th><th>الرقم</th>${all ? '<th>مين بعت</th>' : ''}<th>النوع</th><th>النتيجة</th><th>ملاحظة</th><th>تفاصيل</th></tr></thead><tbody>
        ${day.items.map(x => `<tr><td>${esc(x.at)}</td><td><b>${esc(x.company)}</b></td><td>${esc([x.country, x.city].filter(Boolean).join(' · '))}</td><td dir="ltr">${esc(x.phone)}</td>${all ? `<td>${esc(x.staff)}</td>` : ''}
            <td><span class="dk-b ${x.kind === 'followup' ? 'hot' : ''}">${KIND[x.kind]}</span></td><td>${x.result && x.result !== 'contacted' ? `<span class="dk-b ${RESULT_CLS[x.result] || ''}">${esc(x.result_label)}</span>` : '<span class="dk-muted">لسه من غير رد</span>'}</td>
            <td>${esc(x.note)}</td><td class="dk-sm">${x.email_step > 0 ? 'اتبعتلها إيميل ' + x.email_step : 'من غير إيميل'}${x.report_viewed ? ' · فتحت التقرير' : ''} · <a href="${esc(x.report_url)}" target="_blank" rel="noopener noreferrer">التقرير ↗</a></td></tr>`).join('')}
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
            f.from = data.from; f.to = data.to;
            render();
        } catch (e) { const m = root.querySelector('#snMsg'); if (m) { m.textContent = e.message; m.hidden = false; } else root.innerHTML = `<p class="dk-msg bad">${esc(e.message)}</p>`; }
        finally { busy = false; }
    };

    const bind = () => {
        root.addEventListener('click', async e => {
            const t = e.target.closest('button'); if (!t) return;
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
