/* Tourfecto - مكتب التواصل (موظفة المبيعات): شركات كل دولة بأرقامها والرسالة جاهزة للنسخ */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const COUNTRY = {
        EG: 'مصر', TR: 'تركيا', AE: 'الإمارات', SA: 'السعودية', JO: 'الأردن', MA: 'المغرب', TN: 'تونس', OM: 'عُمان', QA: 'قطر', KW: 'الكويت', BH: 'البحرين', LB: 'لبنان', IQ: 'العراق',
        GR: 'اليونان', CY: 'قبرص', IT: 'إيطاليا', ES: 'إسبانيا', PT: 'البرتغال', FR: 'فرنسا', DE: 'ألمانيا', NL: 'هولندا', GB: 'بريطانيا', US: 'أمريكا', IN: 'الهند', TH: 'تايلاند', ZA: 'جنوب أفريقيا',
    };
    const flag = c => /^[A-Z]{2}$/.test(c) ? String.fromCodePoint(...[...c].map(x => 0x1F1E6 + x.charCodeAt(0) - 65)) : '🌐';
    const countryName = c => c === '?' || !c ? 'دولة مش معروفة' : (COUNTRY[c] || c);
    const LANG = { ar: 'عربي', en: 'English', tr: 'Türkçe' };
    const VIEWS = [['todo', 'لسه ماتواصلتش', 'todo'], ['contacted', 'اتواصلت', 'contacted'], ['hot', 'ردّوا / مهتمين', 'hot'], ['closed', 'اتقفلت', 'closed'], ['all', 'الكل', null]];
    const ACTIONS = [
        ['contacted', '✅ بعتّ على واتساب', 'تم الإرسال على واتساب'], ['replied', '💬 ردّوا', 'ردّوا'], ['interested', '🔥 مهتمين', 'مهتمين'],
        ['call_back', '⏰ أكلمهم تاني', 'هكلمهم تاني'], ['not_interested', '🚫 مش مهتمين', 'مش مهتمين'], ['wrong_number', '📵 الرقم غلط', 'الرقم غلط'],
    ];
    const ACTION_LABEL = Object.fromEntries(ACTIONS.map(([k, , l]) => [k, l]));
    const BUCKET = { contacted: 'contacted', call_back: 'contacted', replied: 'hot', interested: 'hot', not_interested: 'closed', wrong_number: 'closed' };
    const STATUS = { new: 'جديدة', no_email: 'من غير إيميل', contacted: 'اتبعتلها إيميل', finished: 'خلصت رسايل الإيميل', replied: 'ردّت', interested: 'مهتمة', signed_up: 'سجّلت في المنصة 🎉', unsubscribed: 'ألغت الاشتراك', bounced: 'الإيميل راجع', paused: 'متوقفة' };

    let P, root, csrf = '', state = { country: '', view: 'todo', q: '', page: 1 }, counts = [], rows = [], total = 0, pages = 1;

    const store = {
        get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* مش مهم */ } },
    };
    const api = async (url, body) => {
        const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: csrf }) } : undefined);
        if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
        return r.data;
    };
    const toast = (msg, bad) => {
        const el = root.querySelector('#dkMsg'); if (!el) return;
        el.textContent = msg; el.className = 'dk-msg' + (bad ? ' bad' : ''); el.hidden = !msg;
        clearTimeout(toast.t); if (msg) toast.t = setTimeout(() => { el.hidden = true; }, 2600);
    };
    const copy = async text => {
        try { await navigator.clipboard.writeText(text); return true; } catch (e) { /* بنجرّب الطريقة القديمة */ }
        const ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
        let ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; } ta.remove(); return ok;
    };
    const cur = () => counts.find(c => c.country === state.country) || { total: 0, todo: 0, contacted: 0, hot: 0, closed: 0 };

    const card = r => {
        const dir = r.language === 'ar' ? 'rtl' : 'ltr';
        const d = r.desk || {};
        const last = d.action ? `<div class="dk-last">آخر نتيجة: <b>${esc(ACTION_LABEL[d.action] || d.action)}</b> · ${esc(d.at || '')} · ${esc(d.by || '')}${d.note ? ' · ' + esc(d.note) : ''}</div>` : '';
        const emailInfo = r.email_step > 0 ? `اتبعتلها إيميل ${r.email_step} (${esc(r.email_sent_at || '')})${r.email_clicked ? ' · ضغطت على اللينك' : ''}${r.report_viewed ? ' · فتحت التقرير' : ''}` : 'ماتبعتلهاش إيميل لسه';
        const meta = [r.city, r.category && String(r.category).split(',')[0]].filter(Boolean).map(esc).join(' · ');
        const rating = r.rating ? `⭐ ${Number(r.rating).toFixed(1)}${r.reviews ? ' (' + Number(r.reviews).toLocaleString('en') + ')' : ''}` : '';
        const head = `<div class="dk-head"><div><h3>${esc(r.company)}</h3><div class="dk-sub">${meta}${rating ? ' · ' + rating : ''}</div></div>
            <div class="dk-badges"><span class="dk-b">${esc(LANG[r.language] || r.language)}</span><span class="dk-b ${r.status === 'signed_up' ? 'green' : ''}">${esc(STATUS[r.status] || r.status)}</span>${d.action ? `<span class="dk-b ${BUCKET[d.action] === 'hot' ? 'green' : ''}">${esc(ACTION_LABEL[d.action])}</span>` : ''}</div></div>`;
        if (r.blocked) return `<article class="dk-card blocked" data-id="${r.id}">${head}<div class="dk-block">🚫 الشركة دي ألغت الاشتراك: ممنوع التواصل معاها بأي وسيلة.</div></article>`;
        const phone = r.phone_intl || r.phone;
        // «اتبعتت على واتساب» + اسم الموظفة والوقت، جنب الزرار؛ وتراجع لو فتحت واتساب وماضغطتش إرسال
        const sentLabel = d.action === 'contacted' ? 'اتبعتت على واتساب' : (ACTION_LABEL[d.action] || '');
        const sent = d.action ? `<span class="dk-sent ${BUCKET[d.action] === 'hot' ? 'hot' : ''}">${d.action === 'contacted' ? '✅' : '•'} ${esc(sentLabel)} · <b>${esc(d.by || '')}</b> · ${esc(d.at || '')}</span>${d.action === 'contacted' || d.action === 'call_back' ? '<button class="dk-btn xs" data-undo title="لو فتحتي واتساب وماضغطتيش إرسال">↩ تراجع</button>' : ''}` : '';
        const details = `<div class="dk-col">
            <div class="dk-phone"><span class="dk-label">📞 الرقم</span><b dir="ltr">${esc(phone)}</b>
                <button class="dk-btn" data-copy-phone="${esc(phone)}">📋 نسخ الرقم</button>${r.wa_url ? `<a class="dk-btn green" data-wa href="${esc(r.wa_url)}" target="_blank" rel="noopener noreferrer">${r.wa_has_text ? '💬 افتح واتساب بالرسالة' : '💬 افتح واتساب'}</a>` : '<span class="dk-muted">(الرقم مش بصيغة دولية: انسخيه وافتحي واتساب بنفسك)</span>'}
                ${sent}</div>
            ${r.wa_url && !r.wa_has_text ? '<div class="dk-muted">الرسالة طويلة: هيفتح الشات فاضي، انسخي الرسالة من تحت والصقيها.</div>' : ''}
            <div class="dk-line"><span class="dk-label">✉️ الإيميل</span><span dir="ltr">${esc(r.email || '—')}</span>${r.email ? `<button class="dk-btn xs" data-copy-phone="${esc(r.email)}">نسخ</button>` : ''}</div>
            <div class="dk-line"><span class="dk-label">🌐 الموقع</span>${r.website ? `<a href="${esc(r.website)}" target="_blank" rel="noopener noreferrer" dir="ltr">${esc(r.website)}</a>` : '—'}</div>
            <div class="dk-line"><span class="dk-label">📊 الدرجة الرقمية</span><b>${r.score != null ? esc(r.score) + '/100' : '—'}</b></div>
            ${r.gaps.length ? `<div class="dk-gaps"><span class="dk-label">اللي لقيناه عندهم</span><ul>${r.gaps.map(g => `<li>${esc(g)}</li>`).join('')}</ul></div>` : (r.needs.length ? `<div class="dk-line"><span class="dk-label">احتياجهم</span>${r.needs.map(n => `<span class="dk-b">${esc(n)}</span>`).join(' ')}</div>` : '')}
            <div class="dk-muted">${emailInfo}</div></div>`;
        const msg = `<div class="dk-col"><div class="dk-label">💬 الرسالة (تقرير مبدئي + لينك التقرير الكامل)${r.has_report ? '' : ' <span class="dk-warn">الشركة لسه ماتفحصتش: الرسالة عامة</span>'}</div>
            <textarea class="dk-msgbox" readonly rows="10" dir="${dir}">${esc(r.message)}</textarea>
            <div class="dk-actions"><button class="dk-btn primary" data-copy-msg>📋 نسخ الرسالة</button><button class="dk-btn" data-copy-link="${esc(r.report_url)}">🔗 نسخ لينك التقرير</button><a class="dk-btn" href="${esc(r.report_preview_url)}" target="_blank" rel="noopener noreferrer">👁 معاينة التقرير</a></div></div>`;
        const acts = `<div class="dk-result"><div class="dk-actions">${ACTIONS.map(([k, l]) => `<button class="dk-btn ${d.action === k ? 'on' : ''}" data-act="${k}">${l}</button>`).join('')}</div>
            <input class="dk-note" placeholder="ملاحظة (اختياري): مين ردّ، هنكلمه إمتى..." maxlength="500" value="${esc(d.note || '')}">${last}</div>`;
        return `<article class="dk-card" data-id="${r.id}">${head}<div class="dk-body">${details}${msg}</div>${acts}</article>`;
    };

    const render = () => {
        const c = cur();
        const countries = counts.map(x => `<button class="dk-chip ${x.country === state.country ? 'on' : ''}" data-country="${esc(x.country)}">${flag(x.country)} ${esc(countryName(x.country))} <b>${x.total}</b>${x.todo ? `<small>${x.todo} لسه</small>` : ''}</button>`).join('');
        const tabs = VIEWS.map(([k, l, key]) => `<button class="dk-tab ${state.view === k ? 'on' : ''}" data-view="${k}">${l}${key ? ` <b>${c[key] || 0}</b>` : ` <b>${c.total || 0}</b>`}</button>`).join('');
        root.innerHTML = `<div id="dkMsg" class="dk-msg" hidden></div>
            <section class="dk-top"><div class="dk-tip">اختاري الدولة، وانسخي الرقم والرسالة وابعتيها على واتساب، وبعدها سجّلي النتيجة. اللي ردّ أو اهتم أو رفض الإيميلات بتقف عنده لوحدها.</div>
            <div class="dk-chips">${countries || '<span class="dk-muted">لسه مفيش شركات ليها أرقام.</span>'}</div>
            <div class="dk-bar"><div class="dk-tabs">${tabs}</div><input id="dkQ" class="dk-search" placeholder="دوّري باسم الشركة أو الرقم أو الإيميل" value="${esc(state.q)}"></div></section>
            <section class="dk-list">${rows.length ? rows.map(card).join('') : '<p class="dk-empty">مفيش شركات هنا بالفلتر ده.</p>'}</section>
            <div class="dk-pager"><span class="dk-muted">${total} شركة · صفحة ${state.page} من ${pages || 1}</span>${state.page > 1 ? '<button class="dk-btn" data-page="-1">السابق</button>' : ''}${state.page < pages ? '<button class="dk-btn" data-page="1">التالي</button>' : ''}</div>`;
    };

    const load = async (keepScroll) => {
        const q = new URLSearchParams({ country: state.country, view: state.view, q: state.q, page: state.page });
        const r = await api('/api/admin/outreach-desk/leads?' + q.toString());
        csrf = r.csrf_token || csrf; counts = r.counts || []; rows = r.rows || []; total = r.total || 0; pages = r.pages || 1;
        if (!state.country && counts.length) { state.country = counts[0].country; return load(keepScroll); }
        render();
        if (!keepScroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const mark = async (id, action, note) => {
        const row = rows.find(x => x.id === id); if (!row) return;
        const r = await api('/api/admin/outreach-desk/leads/' + id + '/action', { action, note });
        const c = cur(), prev = row.desk?.action ? BUCKET[row.desk.action] : 'todo', next = action === 'undo' ? 'todo' : BUCKET[action];
        if (prev !== next) { c[prev] = Math.max(0, (c[prev] || 0) - 1); c[next] = (c[next] || 0) + 1; }
        row.desk = r;
        const el = root.querySelector(`.dk-card[data-id="${id}"]`);
        if (el) { el.outerHTML = card(row); }
        const tabs = root.querySelector('.dk-tabs'); if (tabs) tabs.innerHTML = VIEWS.map(([k, l, key]) => `<button class="dk-tab ${state.view === k ? 'on' : ''}" data-view="${k}">${l} <b>${key ? (c[key] || 0) : (c.total || 0)}</b></button>`).join('');
        toast(action === 'undo' ? 'اتمسح تسجيل الإرسال ✅' : 'اتسجّل: ' + (ACTION_LABEL[action] || action) + ' ✅');
    };

    // دوست «افتح واتساب»: بنسجّل إنها بعتت باسمها (إلا لو الشركة عليها نتيجة أحسن زي «ردّوا»)، واللينك بيفتح الشات بالرسالة جاهزة
    const autoLogSend = async el => {
        const id = Number(el.dataset.id), row = rows.find(x => x.id === id);
        if (!row || ['contacted', 'replied', 'interested', 'not_interested', 'wrong_number'].includes(row.desk?.action)) return;
        try {
            await mark(id, 'contacted', el.querySelector('.dk-note')?.value || 'فتحت واتساب بالرسالة');
            toast('اتسجّل إنك بعتّي على واتساب ✅ لو ماضغطتيش إرسال اضغطي «تراجع»');
        } catch (err) { toast(err.message, true); }
    };

    const bind = () => {
        root.addEventListener('click', async e => {
            const wa = e.target.closest('a[data-wa]');
            if (wa) { autoLogSend(wa.closest('.dk-card')); return; } // اللينك نفسه بيفتح واتساب (من غير preventDefault)
            const t = e.target.closest('button'); if (!t) return;
            const cardEl = t.closest('.dk-card');
            try {
                if (t.dataset.undo !== undefined) { t.disabled = true; await mark(Number(cardEl.dataset.id), 'undo', ''); return; }
                if (t.dataset.country) { state.country = t.dataset.country; state.view = 'todo'; state.page = 1; store.set('dk-country', state.country); return await load(); }
                if (t.dataset.view) { state.view = t.dataset.view; state.page = 1; return await load(); }
                if (t.dataset.page) { state.page += Number(t.dataset.page); return await load(); }
                if (t.dataset.copyPhone !== undefined) { toast(await copy(t.dataset.copyPhone) ? 'اتنسخ ✅' : 'مقدرتش أنسخ: ظلّلي النص وانسخي بإيدك', false); return; }
                if (t.dataset.copyLink !== undefined) { toast(await copy(t.dataset.copyLink) ? 'اتنسخ لينك التقرير ✅' : 'مقدرتش أنسخ', false); return; }
                if (t.dataset.copyMsg !== undefined) { const box = cardEl.querySelector('.dk-msgbox'); toast(await copy(box.value) ? 'اتنسخت الرسالة ✅' : 'مقدرتش أنسخ: ظلّلي الرسالة وانسخيها بإيدك', false); return; }
                if (t.dataset.act) { t.disabled = true; await mark(Number(cardEl.dataset.id), t.dataset.act, cardEl.querySelector('.dk-note')?.value || ''); }
            } catch (err) { toast(err.message, true); if (t) t.disabled = false; }
        });
        let timer;
        root.addEventListener('input', e => {
            if (e.target.id !== 'dkQ') return;
            clearTimeout(timer); timer = setTimeout(async () => { state.q = e.target.value.trim(); state.page = 1; try { await load(true); } catch (err) { toast(err.message, true); } const q = root.querySelector('#dkQ'); if (q) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); } }, 350);
        });
    };

    window.OutreachDesk = {
        init: async panel => {
            P = panel; root = document.getElementById('deskRoot'); if (!root || !P) return;
            state.country = store.get('dk-country') || '';
            try { bind(); await load(true); } catch (e) { root.innerHTML = `<p class="dk-msg bad">${esc(e.message)}</p>`; }
        },
    };
})();
