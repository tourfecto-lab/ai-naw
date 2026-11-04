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
    const VIEWS = [['todo', 'لسه ماتواصلتش', 'todo'], ['followup', '⏰ محتاجة متابعة', 'followup'], ['contacted', 'اتواصلت', 'contacted'], ['hot', 'ردّوا / مهتمين', 'hot'], ['won', '🎉 جرّبوا / اشتركوا', 'won'], ['closed', 'اتقفلت', 'closed'], ['weak', 'رسالتها ضعيفة (مخفية)', 'weak'], ['all', 'الكل', null]];
    const ACTIONS = [
        ['contacted', '✅ بعتّ على واتساب', 'تم الإرسال على واتساب'], ['replied', '💬 ردّوا', 'ردّوا'], ['interested', '🔥 مهتمين', 'مهتمين'],
        ['trial', '🧪 جرّبوا المنصة', 'جرّبوا المنصة'], ['subscribed', '🎉 اشتركوا', 'اشتركوا'],
        ['call_back', '⏰ أكلمهم تاني', 'هكلمهم تاني'], ['not_interested', '🚫 مش مهتمين', 'مش مهتمين'], ['wrong_number', '📵 الرقم غلط', 'الرقم غلط'],
    ];
    const ACTION_LABEL = Object.fromEntries(ACTIONS.map(([k, , l]) => [k, l]));
    const BUCKET = { contacted: 'contacted', call_back: 'contacted', replied: 'hot', interested: 'hot', trial: 'hot', subscribed: 'hot', not_interested: 'closed', wrong_number: 'closed' };
    const STATUS = { new: 'جديدة', no_email: 'من غير إيميل', contacted: 'اتبعتلها إيميل', finished: 'خلصت رسايل الإيميل', replied: 'ردّت', interested: 'مهتمة', signed_up: 'سجّلت في المنصة 🎉', unsubscribed: 'ألغت الاشتراك', bounced: 'الإيميل راجع', paused: 'متوقفة' };

    const TIERS = [['', 'كل الأولويات'], ['high', '🔥 عالية'], ['medium', 'متوسطة'], ['low', 'منخفضة']];
    const TIER_BADGE = { high: ['🔥 أولوية عالية', 'hot'], medium: ['أولوية متوسطة', ''], low: ['أولوية منخفضة', 'dim'] };
    const num = n => Number(n || 0).toLocaleString('en');
    let P, root, csrf = '', state = { country: '', view: 'todo', tier: '', q: '', page: 1 }, counts = [], tiers = {}, rows = [], total = 0, pages = 1, funnel = null, funnelFor = null, me = {};

    // بطاقة الفحص: الأسطر المش معروفة بتبان «لسه ماتفحصش» عشان الموظفة تفرّق بين «مفيش» و«ماعرفناش»
    const scorecard = r => {
        const sc = r.scorecard || {}, out = [];
        const g = sc.google || {}, a = sc.tripadvisor || {}, w = sc.website || {};
        out.push(['🗺️', 'جوجل ماب', g.status === 'found' && g.rating != null ? `⭐ ${Number(g.rating).toFixed(1)} (${num(g.reviews)} تقييم)${g.hours === false ? ' · مواعيد العمل مش مكتوبة' : ''}` : (g.status === 'missing' ? '❌ مالقيناش ملف للشركة' : null), g.status === 'missing' ? 'bad' : '']);
        out.push(['✈️', 'تريب أدفايزر', a.status === 'found' && a.rating != null ? `⭐ ${Number(a.rating).toFixed(1)} (${num(a.reviews)} تقييم)${a.ranking ? ' · ' + esc(a.ranking) : ''}${a.award ? ' 🏆' : ''}` : (a.status === 'linked' ? '✅ صفحتهم مربوطة بالموقع (التقييم مش معروف)' : (a.status === 'missing' ? 'مش مربوطة بالموقع' : null)), '', a.url]);
        [['facebook', '📘', 'فيسبوك'], ['instagram', '📸', 'إنستجرام']].forEach(([k, icon, name]) => {
            const s = sc[k] || {};
            out.push([icon, name, s.status === 'linked' ? '✅ مربوط بالموقع' : (s.status === 'missing' ? '❌ مش مربوط بالموقع' : (s.status === 'placeholder' ? '⚠️ الرابط على موقعهم افتراضي (مش صفحتهم)' : null)), s.status === 'missing' || s.status === 'placeholder' ? 'bad' : '', s.url, k, s.activity]);
        });
        const webTxt = w.status === 'ok' ? `شغّال${w.score != null ? ' · الدرجة ' + w.score + '/100' : ''}${w.https === false ? ' · من غير HTTPS' : ''}${w.mobile === false ? ' · مش متوافق مع الموبايل' : ''}` : (w.status === 'down' ? '❌ مش بيفتح' : (w.status === 'none' ? '❌ مفيش موقع' : null));
        out.push(['🌐', 'الموقع', webTxt, w.status === 'down' || w.status === 'none' ? 'bad' : '']);
        // السيو: «ناقص» بس لو الفحص أثبت إنه ناقص (مش بنقول مفيش سيو لموقع عليه سيو)
        const seo = r.seo || {}, parts = { title: 'عنوان الصفحة', description: 'وصف جوجل', h1: 'H1', schema: 'Schema', sitemap: 'خريطة الموقع', blog: 'مدونة', alt: 'وصف الصور' };
        const seoTxt = seo.state === 'good' ? '✅ الأساسيات موجودة' : (seo.state === 'partial' ? `⚠️ أساسيات موجودة، ناقص: ${(seo.fails || []).map(k => parts[k] || k).join('، ')}` : (seo.state === 'missing' ? `❌ شبه معدوم، ناقص: ${(seo.fails || []).map(k => parts[k] || k).join('، ')}` : null));
        out.splice(4, 0, ['🔎', 'السيو', seoTxt, seo.state === 'missing' ? 'bad' : '']);
        // نشاط فيسبوك وإنستجرام مش بيتقري آليًا: الموظفة بتفتح الصفحة وتسجّل اللي شافته، وده اللي بيدخل الرسالة
        const SEEN = [['active', 'نشيطة'], ['stale', 'آخر منشور قديم'], ['empty', 'مفيهاش منشورات'], ['notfound', 'مش موجودة']];
        const controls = (net, cur) => `<div class="dk-seen"><span class="dk-muted">شفتي إيه على الصفحة؟</span>${SEEN.map(([v, l]) => `<button class="dk-btn xs ${cur === v ? 'on' : ''}" data-seen="${net}:${v}">${l}</button>`).join('')}${cur ? `<button class="dk-btn xs" data-seen="${net}:">مسح</button>` : ''}</div>`;
        return `<div class="dk-sc">${out.map(([icon, name, txt, cls, url, net, cur]) => `<div class="dk-sc-row ${cls || ''}"><span>${icon} <b>${name}</b></span><span>${txt == null ? '<i class="dk-muted">لسه ماتفحصش</i>' : txt}${cur ? ` · <b>${esc((SEEN.find(x => x[0] === cur) || [0, cur])[1])}</b>` : ''}${url && /^https?:\/\//i.test(url) ? ` <a href="${esc(url)}" target="_blank" rel="noopener noreferrer">افتح ↗</a>` : ''}</span>${net ? controls(net, cur) : ''}</div>`).join('')}</div>`;
    };

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
    const cur = () => counts.find(c => c.country === state.country) || { total: 0, todo: 0, weak: 0, contacted: 0, followup: 0, hot: 0, won: 0, closed: 0 };

    const card = r => {
        const dir = r.language === 'ar' ? 'rtl' : 'ltr';
        const d = r.desk || {};
        const last = d.action ? `<div class="dk-last">آخر نتيجة: <b>${esc(ACTION_LABEL[d.action] || d.action)}</b> · ${esc(d.at || '')} · ${esc(d.by || '')}${d.note ? ' · ' + esc(d.note) : ''}${state.view === 'followup' ? ' · 💡 تابعي بالرسالة التانية (الخدمات)' : ''}</div>` : '';
        const emailInfo = r.email_step > 0 ? `اتبعتلها إيميل ${r.email_step} (${esc(r.email_sent_at || '')})${r.email_clicked ? ' · ضغطت على اللينك' : ''}${r.report_viewed ? ' · فتحت التقرير' : ''}` : 'ماتبعتلهاش إيميل لسه';
        const meta = [r.city, r.category && String(r.category).split(',')[0]].filter(Boolean).map(esc).join(' · ');
        const rating = r.rating ? `⭐ ${Number(r.rating).toFixed(1)}${r.reviews ? ' (' + Number(r.reviews).toLocaleString('en') + ')' : ''}` : '';
        const head = `<div class="dk-head"><div><h3>${esc(r.company)}</h3><div class="dk-sub">${meta}${rating ? ' · ' + rating : ''}</div></div>
            <div class="dk-badges">${TIER_BADGE[r.tier] ? `<span class="dk-b prio ${TIER_BADGE[r.tier][1]}" title="أولوية الإرسال ${esc(r.priority)}/100: بتتحسب من نواقص الشركة وحجم شغلها">${TIER_BADGE[r.tier][0]}</span>` : ''}<span class="dk-b">${esc(LANG[r.language] || r.language)}</span><span class="dk-b ${r.status === 'signed_up' ? 'green' : ''}">${esc(STATUS[r.status] || r.status)}</span>${d.action ? `<span class="dk-b ${BUCKET[d.action] === 'hot' ? 'green' : ''}">${esc(ACTION_LABEL[d.action])}</span>` : ''}</div></div>`;
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
            ${scorecard(r)}
            ${r.gaps.length ? `<div class="dk-gaps"><span class="dk-label">اللي لقيناه عندهم</span><ul>${r.gaps.map(g => `<li>${esc(g)}</li>`).join('')}</ul></div>` : (r.needs.length ? `<div class="dk-line"><span class="dk-label">احتياجهم</span>${r.needs.map(n => `<span class="dk-b">${esc(n)}</span>`).join(' ')}</div>` : '')}
            <div class="dk-muted">${emailInfo}</div></div>`;
        const msg = `<div class="dk-col"><div class="dk-label">💬 الرسالة (تقرير مبدئي + لينك التقرير الكامل)${r.has_report ? '' : ' <span class="dk-warn">الشركة لسه ماتفحصتش: الرسالة عامة</span>'}</div>
            <textarea class="dk-msgbox" readonly rows="10" dir="${dir}">${esc(r.message)}</textarea>
            <div class="dk-actions"><button class="dk-btn primary" data-copy-msg>📋 نسخ الرسالة</button><button class="dk-btn" data-copy-link="${esc(r.report_url)}">🔗 نسخ لينك التقرير</button><a class="dk-btn" href="${esc(r.report_preview_url)}" target="_blank" rel="noopener noreferrer">👁 معاينة التقرير</a></div>
            ${r.message2 ? `<details class="dk-msg2"><summary>📨 رسالة 2 (بعد ما يردّوا): خدمات المنصة ولينك التسجيل</summary><textarea class="dk-msgbox" readonly rows="9" dir="${dir}">${esc(r.message2)}</textarea><div class="dk-actions"><button class="dk-btn primary" data-copy-msg2>📋 نسخ رسالة الخدمات</button>${r.wa_url2 ? `<a class="dk-btn green" href="${esc(r.wa_url2)}" target="_blank" rel="noopener noreferrer">💬 افتح واتساب بالرسالة 2</a>` : ''}</div></details>` : ''}</div>`;
        const acts = `<div class="dk-result"><div class="dk-actions">${ACTIONS.map(([k, l]) => `<button class="dk-btn ${d.action === k ? 'on' : ''}" data-act="${k}">${l}</button>`).join('')}</div>
            <input class="dk-note" placeholder="ملاحظة (اختياري): مين ردّ، هنكلمه إمتى..." maxlength="500" value="${esc(d.note || '')}">${last}</div>`;
        return `<article class="dk-card" data-id="${r.id}">${head}<div class="dk-body">${details}${msg}</div>${acts}</article>`;
    };

    // نتايج التواصل للدولة المختارة: واتساب (اتبعتلهم ← ردّوا ← جرّبوا ← اشتركوا) وإيميل، وأداء كل موظفة آخر 7 أيام
    const funnelHtml = () => {
        if (!funnel) return '';
        const w = funnel.whatsapp || {}, e = funnel.email || {};
        const step = (n, l, hint) => `<div class="dk-fn"><b>${num(n)}</b><span>${l}</span>${hint ? `<small>${hint}</small>` : ''}</div>`;
        const staff = (funnel.staff || []).map(s => `<span class="dk-b">${esc(s.name)}: بعتت <b>${num(s.sent)}</b> · ردّوا <b>${num(s.replied)}</b></span>`).join(' ');
        return `<div class="dk-funnel"><div class="dk-fn-row"><span class="dk-label">واتساب</span>${step(w.sent, 'اتبعتلهم', w.sent_today ? 'النهارده ' + num(w.sent_today) : '')}${step(w.replied, 'ردّوا', w.reply_rate !== null && w.reply_rate !== undefined ? w.reply_rate + '% من اللي اتبعتلهم' : '')}${step(w.trial, 'جرّبوا المنصة')}${step(w.subscribed, 'اشتركوا')}</div>
            <div class="dk-fn-row"><span class="dk-label">إيميل</span>${step(e.sent, 'اتبعتلهم')}${step(e.report_viewed, 'فتحوا التقرير')}${step(e.signed_up, 'سجّلوا في المنصة')}</div>
            ${staff ? `<div class="dk-fn-row"><span class="dk-label">آخر 7 أيام</span>${staff}</div>` : ''}</div>`;
    };
    const loadFunnel = async () => {
        if (funnelFor === state.country) return;
        funnelFor = state.country;
        try { funnel = (await api('/api/admin/outreach-desk/funnel?country=' + encodeURIComponent(state.country))) || null; } catch (e) { funnel = null; }
        const el = root.querySelector('.dk-funnel-slot'); if (el) el.innerHTML = funnelHtml();
    };

    const render = () => {
        const c = cur();
        const countries = counts.map(x => `<button class="dk-chip ${x.country === state.country ? 'on' : ''}" data-country="${esc(x.country)}">${flag(x.country)} ${esc(countryName(x.country))} <b>${x.total}</b>${x.todo ? `<small>${x.todo} لسه</small>` : ''}</button>`).join('');
        const tabs = VIEWS.map(([k, l, key]) => `<button class="dk-tab ${state.view === k ? 'on' : ''}" data-view="${k}">${l}${key ? ` <b>${c[key] || 0}</b>` : ` <b>${c.total || 0}</b>`}</button>`).join('');
        // أول دخول: موقعها مرة واحدة بإذنها (عشان نتأكد من العنوان)، وتنبيه لو بياناتها ناقصة
        const meBanner = (me.location_recorded === false ? `<div class="dk-banner">📍 محتاجين موقعك <b>مرة واحدة</b> عشان نتأكد من عنوانك. المتصفح هيسألك عن الإذن، ومفيش تتبّع مستمر.
                <button class="dk-btn primary" data-loc-first>اسمحي وسجّلي موقعي</button></div>` : '')
            + (me.profile_missing > 0 ? `<div class="dk-banner">📋 بيانات ملفك ناقصة (${me.profile_missing}). افتحي «كشف الشغل» وكمّلي بياناتك وارفعي صورة البطاقة.</div>` : '');
        root.innerHTML = `<div id="dkMsg" class="dk-msg" hidden></div>${meBanner}
            <section class="dk-top"><div class="dk-tip">اختاري الدولة، وانسخي الرقم والرسالة وابعتيها على واتساب، وبعدها سجّلي النتيجة. اللي ردّ أو اهتم أو رفض الإيميلات بتقف عنده لوحدها.</div>
            <div class="dk-chips">${countries || '<span class="dk-muted">لسه مفيش شركات ليها أرقام.</span>'}</div>
            <div><button class="dk-btn" data-staff-open>📋 كشف الشغل والعمولة والراتب</button></div>
            <div class="dk-funnel-slot">${funnelFor === state.country ? funnelHtml() : ''}</div>
            <div class="dk-prio"><span class="dk-label">ابدئي بالأولوية:</span>${TIERS.map(([k, l]) => `<button class="dk-tab ${state.tier === k ? 'on' : ''}" data-tier="${k}">${l}${k ? ` <b>${tiers[k] || 0}</b>` : ''}</button>`).join('')}<span class="dk-muted">الأولوية = الشركات اللي محتاجة فعلًا وشغّالة فعلًا. القايمة مترتّبة بيها.</span></div>
            <div class="dk-bar"><div class="dk-tabs">${tabs}</div><input id="dkQ" class="dk-search" placeholder="دوّري باسم الشركة أو الرقم أو الإيميل" value="${esc(state.q)}"></div></section>
            <section class="dk-list">${rows.length ? rows.map(card).join('') : '<p class="dk-empty">مفيش شركات هنا بالفلتر ده.</p>'}</section>
            <div class="dk-pager"><span class="dk-muted">${total} شركة · صفحة ${state.page} من ${pages || 1}</span>${state.page > 1 ? '<button class="dk-btn" data-page="-1">السابق</button>' : ''}${state.page < pages ? '<button class="dk-btn" data-page="1">التالي</button>' : ''}</div>`;
    };

    const load = async (keepScroll) => {
        const q = new URLSearchParams({ country: state.country, view: state.view, tier: state.tier, q: state.q, page: state.page });
        const r = await api('/api/admin/outreach-desk/leads?' + q.toString());
        csrf = r.csrf_token || csrf; me = r.me || me; counts = r.counts || []; tiers = r.tiers || {}; rows = r.rows || []; total = r.total || 0; pages = r.pages || 1;
        if (!state.country && counts.length) { state.country = counts[0].country; return load(keepScroll); }
        render();
        loadFunnel();
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
        funnelFor = null; loadFunnel();
    };

    // دوست «افتح واتساب»: بنسجّل إنها بعتت باسمها (إلا لو الشركة عليها نتيجة أحسن زي «ردّوا»)، واللينك بيفتح الشات بالرسالة جاهزة
    const autoLogSend = async el => {
        const id = Number(el.dataset.id), row = rows.find(x => x.id === id);
        // في «محتاجة متابعة» فتح واتساب تاني = متابعة جديدة: بنسجّلها عشان المهلة تبدأ من جديد
        const skip = ['replied', 'interested', 'trial', 'subscribed', 'not_interested', 'wrong_number'].concat(state.view === 'followup' ? [] : ['contacted']);
        if (!row || skip.includes(row.desk?.action)) return;
        try {
            await mark(id, 'contacted', el.querySelector('.dk-note')?.value || 'فتحت واتساب بالرسالة');
            toast('اتسجّل إنك بعتّي على واتساب ✅ لو ماضغطتيش إرسال اضغطي «تراجع»');
        } catch (err) { toast(err.message, true); }
    };

    // كشف الموظفة: شغلها بالتاريخ والتفاصيل، والعملاء اللي جابتهم ونسبتها الشهرية، والراتب، وإجمالي الشهر
    const COMP = { none: 'غير محدد', commission: 'نسبة على العملاء', salary: 'راتب شهري ثابت', both: 'راتب + نسبة' };
    const egp = n => num(Math.round(Number(n || 0))) + ' جنيه';
    const staffUi = { userId: 0, month: new Date().toISOString().slice(0, 7), el: null };
    const staffHtml = d => {
        const r = d.report, c = r.comp, t = r.totals, s = r.summary;
        const picker = (d.staff || []).length ? `<label>الموظف <select data-st-user>${d.staff.map(x => `<option value="${x.user_id}" ${x.user_id === r.staff.user_id ? 'selected' : ''}>${esc(x.name)} (${esc(COMP[x.comp_type])}) · ${x.actions} إجراء${x.profile_missing ? ' · بياناته ناقصة' : ''}</option>`).join('')}</select></label>` : '';
        const compForm = d.can_manage ? `<div class="dk-comp"><b>تعويض ${esc(r.staff.name)}</b>
            <label>النوع <select data-st-type>${Object.entries(COMP).map(([k, l]) => `<option value="${k}" ${c.comp_type === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
            <label>النسبة % من الاشتراك الشهري <input type="number" min="0" max="100" step="0.5" data-st-pct value="${esc(c.commission_pct)}"></label>
            <label>الراتب الشهري (جنيه) <input type="number" min="0" step="50" data-st-salary value="${esc(c.monthly_salary)}"></label>
            <input data-st-note placeholder="ملاحظة (اختياري)" maxlength="255" value="${esc(c.note || '')}">
            <button class="dk-btn primary" data-st-save>حفظ</button>${c.updated_by ? `<small class="dk-muted">آخر تعديل: ${esc(c.updated_by)} · ${esc(c.updated_at || '')}</small>` : ''}</div>`
            : `<div class="dk-comp"><b>تعويضك:</b> ${esc(COMP[c.comp_type])}${c.commission_pct ? ' · ' + esc(c.commission_pct) + '% من كل اشتراك شهريًا' : ''}${c.monthly_salary ? ' · راتب ' + egp(c.monthly_salary) : ''}</div>`;
        const p = r.profile || {}, canEdit = d.is_self || d.can_manage;
        const PAY = { vodafone_cash: 'فودافون كاش', instapay: 'إنستاباي', bank: 'تحويل بنكي', cash: 'كاش' };
        const inp = (k, l, type) => `<label>${l}<input data-pf="${k}" type="${type || 'text'}" value="${esc(p[k] || '')}"></label>`;
        const ownerFields = d.can_manage ? inp('job_title', 'المسمى الوظيفي') + inp('start_date', 'تاريخ التعيين', 'date')
            + `<label>الحالة <select data-pf="status">${[['active', 'شغّال'], ['paused', 'موقوف مؤقتًا'], ['left', 'ساب الشغل']].map(([k, l]) => `<option value="${k}" ${p.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>` + inp('internal_note', 'ملاحظة داخلية (للمالك بس)') : '';
        const profile = canEdit ? `<div class="dk-comp dk-prof"><b>ملف الموظف</b>
            ${inp('full_name', 'الاسم بالكامل')}${inp('phone', 'رقم التليفون / واتساب', 'tel')}${inp('national_id', 'الرقم القومي أو جواز السفر')}${inp('address', 'العنوان')}
            ${inp('emergency_name', 'شخص للطوارئ')}${inp('emergency_phone', 'تليفون الطوارئ', 'tel')}
            <label>طريقة استلام المستحقات <select data-pf="pay_method"><option value="">—</option>${Object.entries(PAY).map(([k, l]) => `<option value="${k}" ${p.pay_method === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
            ${inp('pay_account', 'رقم المحفظة / الحساب')}${ownerFields}
            <button class="dk-btn primary" data-pf-save>حفظ البيانات</button>
            <small class="dk-muted">الرقم القومي ورقم الحساب بيتخزّنوا مشفّرين، ومايظهروش كاملين غير لصاحب الملف ولمالك المنصة (الباقي بيشوف آخر 4 أرقام).</small></div>`
            : `<div class="dk-comp"><b>بيانات الموظف:</b> ${esc(p.full_name || '—')} · ${esc(p.phone || '—')} · ${esc(p.job_title || '—')}${p.start_date ? ' · تعيين ' + esc(p.start_date) : ''} · ${esc(PAY[p.pay_method] || 'طريقة الاستلام غير مسجّلة')} ${esc(p.pay_account || '')}</div>`;
        const ic = r.id_card, loc = r.location, uid = r.staff.user_id;
        const idView = side => ic && ic[side] ? ` · <a href="/api/admin/outreach-desk/staff-id-card?side=${side}&user_id=${uid}" target="_blank" rel="noopener noreferrer">عرض</a> (${esc(String(ic[side]).slice(0, 10))})` : '';
        const idBox = canEdit ? `<div class="dk-comp dk-id"><b>🪪 صورة البطاقة</b>
            <label>الوش${idView('front')}<input type="file" accept="image/*" capture="environment" data-id-file="front"></label>
            <label>الضهر${idView('back')}<input type="file" accept="image/*" capture="environment" data-id-file="back"></label>
            <label class="dk-consent"><input type="checkbox" data-id-consent> أوافق على رفع صورة البطاقة وقراءة بياناتها بالذكاء الاصطناعي (Google Gemini) وتخزينها مشفّرة، ومايشوفهاش غير مالك المنصة.</label>
            <button class="dk-btn primary" data-id-upload>ارفع واقرا البيانات</button>
            <small class="dk-muted">البيانات اللي بتتقرا بتتحط في الخانات تحت كاقتراح: راجعيها وصحّحيها واضغطي «حفظ البيانات». مفيش حاجة بتتحفظ لوحدها.</small>
            <p class="dk-id-note"></p></div>` : '';
        const locLine = !loc ? 'لسه ماتسجّلش' : `<a href="${esc(loc.first.maps_url)}" target="_blank" rel="noopener noreferrer">أول موقع على الخريطة ↗</a> · ${esc(loc.first.at)}${loc.first.accuracy_m != null ? ' · دقة ±' + num(loc.first.accuracy_m) + ' متر' : ''}${loc.count > 1 ? ` · آخر تحديث ${esc(loc.latest.at)}` : ''}
            <br>${!loc.has_address ? '⚠️ مفيش عنوان في الملف عشان نقارنه' : (!loc.address_located ? '⚠️ ماقدرناش نحوّل العنوان لموقع على الخريطة: قارنيه بإيدك' : (loc.far ? `🚨 الموقع بعيد <b>${num(loc.distance_km)} كم</b> عن العنوان المسجّل (الحد ${loc.far_km} كم)` : `✅ الموقع قريب من العنوان (${num(loc.distance_km)} كم)`))}`;
        const locBox = loc !== null || d.is_self ? `<div class="dk-comp dk-loc"><b>📍 الموقع</b><span>${locLine}</span>
            ${d.is_self ? '<button class="dk-btn" data-loc-save>' + (loc ? 'حدّثي موقعي' : 'سجّلي موقعي (بإذنك)') + '</button><small class="dk-muted">بيتسجّل موقعك مرة واحدة وقت ما تضغطي، ومفيش تتبّع مستمر. المتصفح هيسألك عن الإذن.</small>' : ''}</div>` : '';
        const profBanner = (p.missing || []).length ? `<p class="dk-st-msg bad">⚠️ بيانات ناقصة: ${p.missing.map(esc).join('، ')}</p>` : '<p class="dk-st-msg">✅ بيانات الموظف كاملة</p>';
        const tile = (n, l) => `<div class="dk-fn"><b>${n}</b><span>${l}</span></div>`;
        const custs = r.customers.length ? `<table class="dk-tbl"><thead><tr><th>العميل</th><th>الباقة</th><th>اشترك من</th><th>الشهر ده</th><th>قيمة الشهر</th><th>النسبة</th></tr></thead><tbody>${r.customers.map(x => `<tr><td>${esc(x.company)}</td><td>${esc(x.plan)} (${x.cycle === 'yearly' ? 'سنوي' : 'شهري'})</td><td>${esc(String(x.since).slice(0, 10))}</td><td>${x.active_in_month ? '✅ ساري' : '— مش ساري'}</td><td>${x.monthly_egp == null ? '؟' : egp(x.monthly_egp)}</td><td><b>${egp(x.commission_egp)}</b></td></tr>`).join('')}</tbody></table>` : '<p class="dk-muted">لسه مفيش عملاء مدفوعين منسوبين لها (العميل بيتنسب لأول موظفة سجّلت إرسال واتساب ليه).</p>';
        const log = r.log.length ? `<table class="dk-tbl"><thead><tr><th>الوقت</th><th>الشركة</th><th>اللي حصل</th><th>ملاحظة</th></tr></thead><tbody>${r.log.map(x => `<tr><td>${esc(x.at)}</td><td>${esc(x.company)}</td><td>${esc(x.label)}</td><td>${esc(x.note || '')}</td></tr>`).join('')}</tbody></table>` : '<p class="dk-muted">مفيش شغل مسجّل في الشهر ده.</p>';
        return `<div class="dk-modal-box"><button class="dk-btn" data-st-close>✕ إغلاق</button><h2>كشف ${esc(r.staff.name)}</h2><p class="dk-st-msg"></p>
            <div class="dk-st-bar">${picker}<label>الشهر <input type="month" data-st-month value="${esc(r.month)}"></label></div>
            <div class="dk-fn-row">${tile(num(s.sent), 'شركات بعتلها')}${tile(num(s.replied), 'ردّوا')}${tile(num(s.trial), 'جرّبوا')}${tile(num(s.subscribed), 'اشتركوا')}${tile(num(r.customers_active), 'عملاء مدفوعين سارين')}</div>
            ${profBanner}${idBox}${profile}${locBox}${compForm}
            <div class="dk-totals"><span>نسبة الشهر: <b>${egp(t.commission_egp)}</b></span><span>الراتب: <b>${egp(t.salary_egp)}</b></span><span class="tot">الإجمالي المستحق: <b>${egp(t.total_egp)}</b></span></div>
            <h3>العملاء اللي جابتهم</h3>${custs}<h3>سجل الشغل (${r.log.length})</h3><div class="dk-scroll">${log}</div>
            <ul class="dk-notes">${(r.notes || []).map(n => `<li>${esc(n)}</li>`).join('')}</ul></div>`;
    };
    const stMsg = (m, bad) => { const el = staffUi.el && staffUi.el.querySelector('.dk-st-msg'); if (el) { el.textContent = m; el.className = 'dk-st-msg' + (bad ? ' bad' : ''); } };
    // الموقع: مرة واحدة بإذن الموظفة (المتصفح بيسألها)، ومفيش تتبّع مستمر
    const doLocation = () => new Promise((resolve, reject) => {
        if (!navigator.geolocation) return reject(new Error('المتصفح ده مش بيدعم تحديد الموقع'));
        navigator.geolocation.getCurrentPosition(async pos => {
            try { resolve(await api('/api/admin/outreach-desk/staff-location', { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy, consent: '1' })); } catch (e) { reject(e); }
        }, err => reject(new Error(err.code === 1 ? 'إذن الموقع اتمنع: فعّليه من القفل اللي جنب رابط الموقع وجرّبي تاني' : 'مقدرناش نحدد موقعك: جرّبي تاني')), { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
    // الصورة بتتصغّر في المتصفح قبل الرفع (أسرع وأخف على الموبايل)، والسيرفر بيعيد ترميزها كمان
    const shrinkImage = file => new Promise((res, rej) => {
        const img = new Image(), url = URL.createObjectURL(file);
        img.onload = () => {
            const k = Math.min(1, 1600 / Math.max(img.width, img.height)), c = document.createElement('canvas');
            c.width = Math.round(img.width * k); c.height = Math.round(img.height * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
            c.toBlob(b => b ? res(b) : rej(new Error('الصورة مش مقروءة')), 'image/jpeg', 0.85);
        };
        img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('الصورة مش مقروءة')); };
        img.src = url;
    });
    const uploadIdCard = async () => {
        const q = s => staffUi.el.querySelector(s), note = q('.dk-id-note');
        if (!q('[data-id-consent]').checked) throw new Error('وافقي الأول على رفع البطاقة وقراءتها وتخزينها');
        const fd = new FormData(); fd.append('consent', '1'); fd.append('csrf_token', csrf); fd.append('user_id', staffUi.userId);
        let n = 0;
        for (const side of ['front', 'back']) { const f = q(`[data-id-file="${side}"]`).files[0]; if (f) { fd.append(side, await shrinkImage(f), side + '.jpg'); n++; } }
        if (!n) throw new Error('اختاري صورة وش البطاقة (وضهرها لو عايزة)');
        note.textContent = 'بيترفع ويتقرا… (ممكن ياخد 10 ثواني)';
        const resp = await fetch('/api/admin/outreach-desk/staff-id-card', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
        const r = await resp.json().catch(() => null);
        if (!r?.success) throw new Error(r?.error || 'الرفع فشل');
        const ex = r.data.extract;
        if (ex && ex.ok) {
            const f = ex.fields;
            [['full_name', f.full_name], ['national_id', f.national_id], ['address', f.address]].forEach(([k, v]) => { const el = q(`[data-pf="${k}"]`); if (el && v) el.value = v; });
            const extra = f.birth_date ? ` · ميلاد ${f.birth_date} · ${f.gender === 'male' ? 'ذكر' : 'أنثى'} · ${f.governorate}` : '';
            note.innerHTML = '✅ اتقرت البطاقة وبياناتها اتحطت في الخانات تحت' + esc(extra) + (f.warning ? '<br>⚠️ ' + esc(f.warning) : '') + '<br><b>راجعيها بعينك وصحّحي أي غلط، وبعدين اضغطي «حفظ البيانات».</b>';
        } else {
            note.textContent = n && ex ? '✅ الصورة اتحفظت. ' + (ex.error || '') : '✅ الصورة اتحفظت';
        }
    };
    const openStaff = async () => {
        if (!staffUi.el) {
            staffUi.el = document.createElement('div'); staffUi.el.className = 'dk-modal'; staffUi.el.dir = 'rtl'; document.body.appendChild(staffUi.el);
            staffUi.el.addEventListener('click', async e => {
                const t = e.target.closest('button'); if (!t) return;
                try {
                    if (t.dataset.stClose !== undefined) { staffUi.el.hidden = true; return; }
                    if (t.dataset.idUpload !== undefined) { t.disabled = true; try { await uploadIdCard(); } finally { t.disabled = false; } return; }
                    if (t.dataset.locSave !== undefined) { t.disabled = true; try { await doLocation(); me.location_recorded = true; await loadStaff(); stMsg('اتسجّل موقعك ✅'); } finally { t.disabled = false; } return; }
                    if (t.dataset.pfSave !== undefined) {
                        const body = { user_id: staffUi.userId };
                        staffUi.el.querySelectorAll('[data-pf]').forEach(el => { body[el.dataset.pf] = el.value; });
                        await api('/api/admin/outreach-desk/staff-profile', body); await loadStaff(); stMsg('اتحفظت البيانات ✅'); return;
                    }
                    if (t.dataset.stSave !== undefined) {
                        const q = s => staffUi.el.querySelector(s);
                        await api('/api/admin/outreach-desk/staff-comp', { user_id: staffUi.userId, comp_type: q('[data-st-type]').value, commission_pct: q('[data-st-pct]').value, monthly_salary: q('[data-st-salary]').value, note: q('[data-st-note]').value });
                        await loadStaff(); stMsg('اتحفظ التعويض ✅');
                    }
                } catch (err) { stMsg(err.message, true); }
            });
            staffUi.el.addEventListener('change', async e => {
                try {
                    if (e.target.dataset.stUser !== undefined) { staffUi.userId = Number(e.target.value); await loadStaff(); }
                    if (e.target.dataset.stMonth !== undefined && e.target.value) { staffUi.month = e.target.value; await loadStaff(); }
                } catch (err) { stMsg(err.message, true); }
            });
        }
        staffUi.el.hidden = false; staffUi.el.innerHTML = '<div class="dk-modal-box"><p>جاري التحميل…</p></div>';
        try { await loadStaff(); } catch (err) { staffUi.el.innerHTML = `<div class="dk-modal-box"><button class="dk-btn" data-st-close>✕ إغلاق</button><p class="dk-msg bad">${esc(err.message)}</p></div>`; }
    };
    const loadStaff = async () => {
        const d = await api('/api/admin/outreach-desk/staff-report?month=' + encodeURIComponent(staffUi.month) + (staffUi.userId ? '&user_id=' + staffUi.userId : ''));
        staffUi.userId = d.report.staff.user_id;
        staffUi.el.innerHTML = staffHtml(d);
    };

    const bind = () => {
        root.addEventListener('click', async e => {
            const wa = e.target.closest('a[data-wa]');
            if (wa) { autoLogSend(wa.closest('.dk-card')); return; } // اللينك نفسه بيفتح واتساب (من غير preventDefault)
            const t = e.target.closest('button'); if (!t) return;
            const cardEl = t.closest('.dk-card');
            try {
                if (t.dataset.seen !== undefined) {
                    const [net, state] = t.dataset.seen.split(':');
                    t.disabled = true;
                    await api('/api/admin/outreach-desk/leads/' + cardEl.dataset.id + '/social', { network: net, state });
                    const y = window.scrollY; await load(true); window.scrollTo(0, y); // الرسالة بتتغيّر على حسب اللي شافته
                    toast('اتسجّل، والرسالة اتحدّثت ✅'); return;
                }
                if (t.dataset.staffOpen !== undefined) { staffUi.userId = 0; return await openStaff(); }
                if (t.dataset.locFirst !== undefined) { t.disabled = true; try { await doLocation(); me.location_recorded = true; render(); toast('اتسجّل موقعك ✅ شكرًا'); } finally { t.disabled = false; } return; }
                if (t.dataset.copyMsg2 !== undefined) { const box = cardEl.querySelector('.dk-msg2 .dk-msgbox'); toast(await copy(box.value) ? 'اتنسخت رسالة الخدمات ✅' : 'مقدرتش أنسخ', false); return; }
                if (t.dataset.undo !== undefined) { t.disabled = true; await mark(Number(cardEl.dataset.id), 'undo', ''); return; }
                if (t.dataset.country) { state.country = t.dataset.country; state.view = 'todo'; state.tier = ''; state.page = 1; store.set('dk-country', state.country); return await load(); }
                if (t.dataset.view) { state.view = t.dataset.view; state.page = 1; return await load(); }
                if (t.dataset.tier !== undefined) { state.tier = t.dataset.tier; state.page = 1; return await load(); }
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
