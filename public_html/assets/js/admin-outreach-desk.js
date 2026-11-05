/* Tourfecto - مكتب التواصل (فريق المبيعات): شركات كل دولة بأرقامها والرسالة جاهزة للنسخ */
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
    const VIEWS = [['todo', 'لسه ماتواصلتش', 'todo'], ['opened', '👀 فتحوا التقرير', 'opened'], ['followup', '⏰ محتاجة متابعة', 'followup'], ['contacted', 'اتواصلت', 'contacted'], ['hot', 'ردّوا / مهتمين', 'hot'], ['won', '🎉 جرّبوا / اشتركوا', 'won'], ['closed', 'اتقفلت', 'closed'], ['weak', 'رسالتها ضعيفة (مخفية)', 'weak'], ['all', 'الكل', null]];
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
    let P, root, csrf = '', state = { country: '', view: 'todo', tier: '', q: '', page: 1 }, counts = [], tiers = {}, rows = [], total = 0, pages = 1, funnel = null, funnelFor = null, me = {}, staffName = '';

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
        const controls = (net, cur) => `<div class="dk-seen"><span class="dk-muted">إيه اللي على الصفحة؟</span>${SEEN.map(([v, l]) => `<button class="dk-btn xs ${cur === v ? 'on' : ''}" data-seen="${net}:${v}">${l}</button>`).join('')}${cur ? `<button class="dk-btn xs" data-seen="${net}:">مسح</button>` : ''}</div>`;
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
    const cur = () => counts.find(c => c.country === state.country) || { total: 0, todo: 0, weak: 0, contacted: 0, opened: 0, followup: 0, hot: 0, won: 0, closed: 0 };

    const card = r => {
        const OPEN = window.matchMedia && window.matchMedia('(max-width:640px)').matches ? '' : ' open';
        const dir = r.language === 'ar' ? 'rtl' : 'ltr';
        const d = r.desk || {};
        const last = d.action ? `<div class="dk-last">آخر نتيجة: <b>${esc(ACTION_LABEL[d.action] || d.action)}</b> · ${esc(d.at || '')} · ${esc(d.by || '')}${d.note ? ' · ' + esc(d.note) : ''}${state.view === 'followup' ? ' · 💡 المتابعة بالرسالة التانية (الخدمات)' : ''}</div>` : '';
        const emailInfo = r.email_step > 0 ? `اتبعتلها إيميل ${r.email_step} (${esc(r.email_sent_at || '')})${r.email_clicked ? ' · ضغطت على اللينك' : ''}${r.report_viewed ? ' · فتحت التقرير' : ''}` : 'ماتبعتلهاش إيميل لسه';
        const meta = [r.city, r.category && String(r.category).split(',')[0]].filter(Boolean).map(esc).join(' · ');
        const rating = r.rating ? `⭐ ${Number(r.rating).toFixed(1)}${r.reviews ? ' (' + Number(r.reviews).toLocaleString('en') + ')' : ''}` : '';
        const head = `<div class="dk-head"><div><h3>${esc(r.company)}</h3><div class="dk-sub">${meta}${rating ? ' · ' + rating : ''}</div></div>
            <div class="dk-badges">${TIER_BADGE[r.tier] ? `<span class="dk-b prio ${TIER_BADGE[r.tier][1]}" title="أولوية الإرسال ${esc(r.priority)}/100: بتتحسب من نواقص الشركة وحجم شغلها">${TIER_BADGE[r.tier][0]}</span>` : ''}<span class="dk-b">${esc(LANG[r.language] || r.language)}</span><span class="dk-b ${r.status === 'signed_up' ? 'green' : ''}">${esc(STATUS[r.status] || r.status)}</span>${r.report_viewed ? `<span class="dk-b hot" title="أول فتح: ${esc(r.report_first_at || '')}">👀 فتحت التقرير${r.report_views > 1 ? ' ' + num(r.report_views) + ' مرات' : ''} · آخر مرة ${esc(r.report_last_at || '')}</span>` : ''}${d.action ? `<span class="dk-b ${BUCKET[d.action] === 'hot' ? 'green' : ''}">${esc(ACTION_LABEL[d.action])}</span>` : ''}${r.category_flag ? `<span class="dk-b prio" title="تريب أدفايزر مصنّفها تحت هذا التصنيف: ممكن تبقى جولة فعلًا وممكن محل أو مطعم أو نادي">⚠️ تصنيفها «${esc(r.category_flag)}»: التأكد إنها شركة سياحة قبل الإرسال</span>` : ''}</div></div>`;
        if (r.blocked) return `<article class="dk-card blocked" data-id="${r.id}">${head}<div class="dk-block">🚫 الشركة دي ألغت الاشتراك: ممنوع التواصل معاها بأي وسيلة.</div></article>`;
        const phone = r.phone_intl || r.phone;
        // «اتبعتت على واتساب» + اسم الموظفة والوقت، جنب الزرار؛ وتراجع لو فتحت واتساب وماضغطتش إرسال
        const sentLabel = d.action === 'contacted' ? 'اتبعتت على واتساب' : (ACTION_LABEL[d.action] || '');
        const sent = d.action ? `<span class="dk-sent ${BUCKET[d.action] === 'hot' ? 'hot' : ''}">${d.action === 'contacted' ? '✅' : '•'} ${esc(sentLabel)} · <b>${esc(d.by || '')}</b> · ${esc(d.at || '')}</span>${d.action === 'contacted' || d.action === 'call_back' ? '<button class="dk-btn xs" data-undo title="لو واتساب اتفتح ومحصلش إرسال">↩ تراجع</button>' : ''}` : '';
        const details = `<div class="dk-col">
            <div class="dk-phone"><span class="dk-label">📞 الرقم</span><b dir="ltr">${esc(phone)}</b>
                <button class="dk-btn" data-copy-phone="${esc(phone)}">📋 نسخ الرقم</button>${r.wa_url ? `<a class="dk-btn green" data-wa href="${esc(r.wa_url)}" target="_blank" rel="noopener noreferrer">${r.wa_has_text ? '💬 افتح واتساب بالرسالة' : '💬 افتح واتساب'}</a>` : '<span class="dk-muted">(الرقم مش بصيغة دولية: نسخ الرقم وفتح واتساب يدويًا)</span>'}
                ${sent}</div>
            ${r.wa_url && !r.wa_has_text ? '<div class="dk-muted">الرسالة طويلة: هيفتح الشات فاضي، والرسالة بتتنسخ من تحت وتتلصق.</div>' : ''}
            <div class="dk-line"><span class="dk-label">✉️ الإيميل</span><span dir="ltr">${esc(r.email || '—')}</span>${r.email ? `<button class="dk-btn xs" data-copy-phone="${esc(r.email)}">نسخ</button>` : ''}</div>
            <div class="dk-line"><span class="dk-label">🌐 الموقع</span>${r.website ? `<a href="${esc(r.website)}" target="_blank" rel="noopener noreferrer" dir="ltr">${esc(r.website)}</a>` : '—'}</div>
            <div class="dk-line"><span class="dk-label">📊 الدرجة الرقمية</span><b>${r.score != null ? esc(r.score) + '/100' : '—'}</b></div>
            <details class="dk-more"${OPEN}><summary>🔎 نتيجة الفحص</summary>${scorecard(r)}
            ${r.gaps.length ? `<div class="dk-gaps"><span class="dk-label">اللي لقيناه عندهم</span><ul>${r.gaps.map(g => `<li>${esc(g)}</li>`).join('')}</ul></div>` : (r.needs.length ? `<div class="dk-line"><span class="dk-label">احتياجهم</span>${r.needs.map(n => `<span class="dk-b">${esc(n)}</span>`).join(' ')}</div>` : '')}
            <div class="dk-muted">${emailInfo}</div></details></div>`;
        const msg = `<div class="dk-col"><div class="dk-label">💬 الرسالة (تقرير مبدئي + لينك التقرير الكامل)${r.has_report ? '' : ' <span class="dk-warn">الشركة لسه ماتفحصتش: الرسالة عامة</span>'}</div>
            <details class="dk-more"${OPEN}><summary>عرض نص الرسالة</summary><textarea class="dk-msgbox" readonly rows="10" dir="${dir}">${esc(r.message)}</textarea></details>
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
        const staff = (funnel.staff || []).map(s => `<span class="dk-b">${esc(s.name)}: إرسال <b>${num(s.sent)}</b> · ردّوا <b>${num(s.replied)}</b></span>`).join(' ');
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
        // الموقع مالوش تنبيه ولا زرار: بيتطلب مع إذن الكاميرا لما التصوير يفتح (openWizard)، عشان الموظف مايتفاجأش بإذنين منفصلين
        const due = counts.reduce((a, c) => a + (c.followup || 0), 0);
        const opened = counts.reduce((a, x) => a + (x.opened || 0), 0);
        const meBanner = (opened > 0 ? `<div class="dk-banner">👀 ${num(opened)} شركة فتحت التقرير ومفيش نتيجة لسه: الأنسب التواصل معاها دلوقتي وهي لسه شايفاه <button class="dk-btn primary" data-go-opened>شوف مين فتح</button></div>` : '')
            + (due > 0 ? `<div class="dk-banner">⏰ ${num(due)} شركة محتاجة متابعة (اتبعتلها واتساب من يومين أو أكتر من غير نتيجة) <button class="dk-btn primary" data-go-followup>فتح المتابعة</button></div>` : '')
            + (me.id_card === false ? `<div class="dk-banner">🪪 ${me.recapture ? 'مطلوب إعادة تصوير البطاقة والصورة الشخصية' : 'تصوير البطاقة والصورة الشخصية'} <button class="dk-btn primary" data-id-wizard-main>📷 تصوير</button></div>` : '')
            + (me.profile_missing > 0 && me.id_card ? `<div class="dk-banner">📋 بيانات الملف ناقصة (${me.profile_missing}) وبتتكمّل من «كشف الشغل».</div>` : '');
        root.innerHTML = `<div id="dkMsg" class="dk-msg" hidden></div>${meBanner}
            <section class="dk-top"><div class="dk-tip dk-hide-m">اختيار الدولة ← نسخ الرقم والرسالة وإرسالها على واتساب ← تسجيل النتيجة. اللي ردّ أو اهتم أو رفض الإيميلات بتقف عنده لوحدها.</div>
            <div class="dk-chips">${countries || '<span class="dk-muted">لسه مفيش شركات ليها أرقام.</span>'}</div>
            <div class="dk-sign"><label>✍️ اسمك في توقيع الرسالة <input id="dkSign" maxlength="40" placeholder="مثلًا: سلمى" value="${esc(staffName)}"></label><button class="dk-btn" data-sign-save>حفظ</button>${staffName ? '' : '<span class="dk-warn">مفيش اسم: الرسالة هتتوقّع باسم Tourfecto</span>'}</div>
            <div class="dk-links"><button class="dk-btn" data-staff-open>📋 كشف الشغل والعمولة والراتب</button> <a class="dk-btn" href="/admin/outreach-sent">📤 المرسول لهم (حسب اليوم)</a></div>
            <div class="dk-funnel-slot">${funnelFor === state.country ? funnelHtml() : ''}</div>
            <div class="dk-prio"><span class="dk-label">الأولوية:</span>${TIERS.map(([k, l]) => `<button class="dk-tab ${state.tier === k ? 'on' : ''}" data-tier="${k}">${l}${k ? ` <b>${tiers[k] || 0}</b>` : ''}</button>`).join('')}<span class="dk-muted dk-hide-m">الأولوية = الشركات اللي محتاجة فعلًا وشغّالة فعلًا. القايمة مترتّبة بيها.</span></div>
            <div class="dk-bar"><div class="dk-tabs">${tabs}</div><input id="dkQ" class="dk-search" placeholder="بحث باسم الشركة أو الرقم أو الإيميل" value="${esc(state.q)}"></div></section>
            <section class="dk-list">${rows.length ? rows.map(card).join('') : '<p class="dk-empty">مفيش شركات هنا بالفلتر ده.</p>'}</section>
            <div class="dk-pager"><span class="dk-muted">${total} شركة · صفحة ${state.page} من ${pages || 1}</span>${state.page > 1 ? '<button class="dk-btn" data-page="-1">السابق</button>' : ''}${state.page < pages ? '<button class="dk-btn" data-page="1">التالي</button>' : ''}</div>`;
    };

    const load = async (keepScroll) => {
        const q = new URLSearchParams({ country: state.country, view: state.view, tier: state.tier, q: state.q, page: state.page });
        const r = await api('/api/admin/outreach-desk/leads?' + q.toString());
        csrf = r.csrf_token || csrf; staffName = r.staff_name || ''; me = r.me || me; counts = r.counts || []; tiers = r.tiers || {}; rows = r.rows || []; total = r.total || 0; pages = r.pages || 1;
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

    const refreshTabs = () => { const c = cur(), tabs = root.querySelector('.dk-tabs'); if (tabs) tabs.innerHTML = VIEWS.map(([k, l, key]) => `<button class="dk-tab ${state.view === k ? 'on' : ''}" data-view="${k}">${l} <b>${key ? (c[key] || 0) : (c.total || 0)}</b></button>`).join(''); };
    // شركة اتبعتلها موظف تاني (أو ألغت الاشتراك): بتتشال من القايمة في لحظتها
    const dropRows = (ids, msg) => {
        const c = cur();
        ids.forEach(id => {
            const row = rows.find(x => x.id === id);
            if (row && !row.desk?.action) c.todo = Math.max(0, (c.todo || 0) - 1);
            rows = rows.filter(x => x.id !== id); total = Math.max(0, total - 1);
            const el = root.querySelector(`.dk-card[data-id="${id}"]`); if (el) el.remove();
        });
        refreshTabs(); if (msg) toast(msg, true);
    };
    // تحديث كل 8 ثواني: اللي اتبعتلها موظف تاني بيختفي من قايمتي
    const poll = async () => {
        if (document.hidden || !['todo', 'weak'].includes(state.view)) return;
        const ids = rows.filter(r => !r.desk?.action).map(r => r.id); if (!ids.length) return;
        try {
            const d = await api('/api/admin/outreach-desk/claimed?ids=' + ids.join(','));
            if ((d.claimed || []).length) dropRows(d.claimed.map(x => x.id), 'اتشالت ' + d.claimed.length + ' شركة من القايمة: اتبعتلها موظف تاني');
        } catch (e) { /* التحديث التلقائي مش حرج */ }
    };

    // دوس «افتح واتساب»: الشركة بتتحجز باسم الموظف في اللحظة دي (لو موظف تاني حجزها قبله الشات مابيفتحش وبتتشال من القايمة)، وبعدها الشات بيفتح بالرسالة جاهزة
    const claimAndOpen = async (a, e) => {
        const el = a.closest('.dk-card'), id = Number(el.dataset.id), row = rows.find(x => x.id === id);
        // في «محتاجة متابعة» فتح واتساب تاني = متابعة جديدة: بنسجّلها عشان المهلة تبدأ من جديد
        const skip = ['replied', 'interested', 'trial', 'subscribed', 'not_interested', 'wrong_number'].concat(state.view === 'followup' ? [] : ['contacted']);
        if (!row || skip.includes(row.desk?.action)) return; // اللينك بيفتح عادي
        e.preventDefault();
        const w = window.open('about:blank', '_blank'); // بنفتح النافذة في نفس الضغطة (عشان المتصفح مايمنعهاش) ونكمّل بعد الحجز
        try {
            await mark(id, 'contacted', el.querySelector('.dk-note')?.value || 'فتح واتساب بالرسالة');
            if (w) { w.opener = null; w.location.href = a.href; } else toast('المتصفح منع فتح النافذة: الزرار بيتضغط مرة تانية', true);
        } catch (err) {
            if (w) w.close();
            toast(err.message, true);
            if (/اتبعتلها/.test(err.message)) dropRows([id]);
        }
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
            ${inp('sign_name', 'الاسم في توقيع الرسايل')}${inp('full_name', 'الاسم بالكامل')}${inp('phone', 'رقم التليفون / واتساب', 'tel')}${inp('national_id', 'الرقم القومي أو جواز السفر')}${inp('address', 'العنوان')}
            ${inp('emergency_name', 'شخص للطوارئ')}${inp('emergency_phone', 'تليفون الطوارئ', 'tel')}
            <label>طريقة استلام المستحقات <select data-pf="pay_method"><option value="">—</option>${Object.entries(PAY).map(([k, l]) => `<option value="${k}" ${p.pay_method === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
            ${inp('pay_account', 'رقم المحفظة / الحساب')}${ownerFields}
            <button class="dk-btn primary" data-pf-save>حفظ البيانات</button>
            <small class="dk-muted">الرقم القومي ورقم الحساب بيتخزّنوا مشفّرين، ومايظهروش كاملين غير لصاحب الملف ولمالك المنصة (الباقي بيشوف آخر 4 أرقام).</small></div>`
            : `<div class="dk-comp"><b>بيانات الموظف:</b> ${esc(p.full_name || '—')} · ${esc(p.phone || '—')} · ${esc(p.job_title || '—')}${p.start_date ? ' · تعيين ' + esc(p.start_date) : ''} · ${esc(PAY[p.pay_method] || 'طريقة الاستلام غير مسجّلة')} ${esc(p.pay_account || '')}</div>`;
        const ic = r.id_card, loc = r.location, uid = r.staff.user_id, mgr = !!d.can_manage;
        const docUrl = side => `/api/admin/outreach-desk/staff-id-card?side=${side}&user_id=${uid}`;
        const CLIP = { front: 'fclip', back: 'bclip', photo: 'pclip' };
        const del = (attrs, text) => `<button class="dk-btn xs dk-del" ${attrs}>🗑 ${text}</button>`;
        const thumb = (side, label) => {
            const still = ic && ic[side], clip = ic && ic[CLIP[side]];
            if (!still && !clip) return `<div class="dk-doc none"><span>${label}: لسه</span></div>`;
            // الصورة دايمًا هي اللي بتبان أول حاجة (الوش واضح على طول يتأكّد منه نظرة واحدة)؛ الفيديو زرار منفصل جنبها، مش بيغطّيها بعلامة التشغيل
            const media = still
                ? `<a href="${docUrl(side)}" target="_blank" rel="noopener noreferrer"><img src="${docUrl(side)}" alt="${label}" loading="lazy"></a>`
                : `<video controls preload="none" playsinline src="${docUrl(CLIP[side])}"></video>`;
            return `<div class="dk-doc">${media}<span>${label} · ${esc(String(clip || still).slice(0, 10))}${clip && still ? ` · <a href="${docUrl(CLIP[side])}" target="_blank" rel="noopener noreferrer">🎥 الفيديو</a>` : ''}</span><span class="dk-delrow">${clip ? del(`data-del-side="${CLIP[side]}"`, 'الفيديو') : ''}${still ? del(`data-del-side="${side}"`, 'الصورة') : ''}</span></div>`;
        };
        // الصور (الشخصية ووش البطاقة وضهرها) للأدمن بس؛ الموظف بيشوف حالة الرفع من غير الصور
        const SIDE_LABEL = { front: 'وش البطاقة', back: 'ضهر البطاقة', photo: 'الصورة الشخصية', fclip: 'فيديو الوش', bclip: 'فيديو الضهر', pclip: 'فيديو الشخصية' };
        const hist = mgr && (r.id_history || []).length ? `<details class="dk-more"><summary>🗂️ النسخ القديمة (${r.id_history.length})</summary><div class="dk-hist">${r.id_history.map(h => `<a href="${docUrl(h.side)}&history_id=${h.id}" target="_blank" rel="noopener noreferrer">${esc(SIDE_LABEL[h.side] || h.side)} · ${esc(String(h.uploaded_at).slice(0, 16))}</a> ${del(`data-del-hist="${h.id}"`, '')}`).join('')}</div></details>` : '';
        const docs = mgr ? `<div class="dk-docs">${thumb('photo', 'الصورة الشخصية')}${thumb('front', 'وش البطاقة')}${thumb('back', 'ضهر البطاقة')}</div>${hist}${ic && (ic.front || ic.back || ic.photo || ic.fclip || ic.bclip || ic.pclip || (r.id_history || []).length) ? `<div>${del('data-del-all', 'حذف كل صور وفيديوهات الموظف (الحالي والقديم) نهائيًا')}</div>` : ''}${(r.doc_views || []).length ? `<small class="dk-muted">آخر اطلاع على الصور: ${r.doc_views.map(v => esc(v.viewer) + ' (' + esc(v.at) + ')').join(' · ')}</small>` : ''}` : '';
        const idStatus = ic ? (ic.front && ic.photo ? '✅ البطاقة والصورة الشخصية اتسجّلوا' : '⚠️ لسه ناقص: ' + [!ic.front && 'وش البطاقة', !ic.back && 'ضهر البطاقة', !ic.photo && 'الصورة الشخصية'].filter(Boolean).join('، ')) : '';
        const idBox = canEdit ? `<div class="dk-comp dk-id"><b>🪪 البطاقة والصورة الشخصية</b><span>${idStatus}</span>
            <button class="dk-btn primary" data-id-wizard>📷 ${ic && ic.front ? 'إعادة التصوير' : 'التصوير'}</button>
            ${mgr && !d.is_self ? `<button class="dk-btn" data-recapture>🔔 اطلب منه يعيد التصوير</button>${p.recapture_at ? `<small class="dk-muted">اتطلب ${esc(String(p.recapture_at).slice(0, 16))} ولسه ماصوّرش</small>` : ''}` : ''}
            <p class="dk-id-note"></p></div>` : '';
        // الموقع للأدمن بس (الإحداثيات والمسافة من العنوان)؛ الموظف بيشوف إنه اتسجّل ومش أكتر
        const locLine = !loc ? 'لسه ماتسجّلش' : `<b>موقع وقت التسجيل (قراءة واحدة، مش تتبّع مباشر)</b> · <a href="${esc(loc.first.maps_url)}" target="_blank" rel="noopener noreferrer">على الخريطة ↗</a> · اتسجّل ${esc(loc.first.at)}${loc.first.accuracy_m != null ? ' · دقة ±' + num(loc.first.accuracy_m) + ' متر' : ''}${loc.count > 1 ? ` · آخر تحديث ${esc(loc.latest.at)}` : ''}
            <br>${!loc.has_address ? '⚠️ مفيش عنوان في الملف عشان نقارنه' : (!loc.address_located ? '⚠️ ماقدرناش نحوّل العنوان لموقع على الخريطة: المقارنة تتعمل يدويًا' : (loc.far ? `🚨 الموقع بعيد <b>${num(loc.distance_km)} كم</b> عن العنوان المسجّل (الحد ${loc.far_km} كم)` : `✅ الموقع قريب من العنوان (${num(loc.distance_km)} كم)`))}`;
        const locBtn = ''; // مفيش زرار موقع: بيتسجّل مع إذن الكاميرا في تصوير البطاقة
        const locBox = mgr ? `<div class="dk-comp dk-loc"><b>📍 الموقع</b><span>${locLine}</span>${locBtn}</div>`
            : (d.is_self ? `<div class="dk-comp dk-loc"><b>📍 الموقع</b><span>${r.location_recorded ? '✅ تم تسجيل الموقع' : 'لسه ماتسجّلش'}</span>${locBtn}</div>` : '');
        const profBanner = (p.missing || []).length ? `<p class="dk-st-msg bad">⚠️ بيانات ناقصة: ${p.missing.map(esc).join('، ')}</p>` : '<p class="dk-st-msg">✅ بيانات الموظف كاملة</p>';
        const tile = (n, l) => `<div class="dk-fn"><b>${n}</b><span>${l}</span></div>`;
        const custs = r.customers.length ? `<table class="dk-tbl"><thead><tr><th>العميل</th><th>الباقة</th><th>اشترك من</th><th>الشهر ده</th><th>الدفع</th><th>قيمة الشهر</th><th>النسبة</th></tr></thead><tbody>${r.customers.map(x => `<tr><td>${esc(x.company)}</td><td>${esc(x.plan)} (${x.cycle === 'yearly' ? 'سنوي' : 'شهري'})</td><td>${esc(String(x.since).slice(0, 10))}</td><td>${x.active_in_month ? '✅ ساري' : '— مش ساري'}</td><td>${!x.active_in_month ? '—' : (x.paid_confirmed ? '✅ مؤكد' : '⏳ بانتظار فاتورة مدفوعة')}</td><td>${x.monthly_egp == null ? '؟' : egp(x.monthly_egp)}</td><td><b>${egp(x.commission_egp)}</b></td></tr>`).join('')}</tbody></table>` : '<p class="dk-muted">لسه مفيش عملاء مدفوعين منسوبين للموظف (العميل بيتنسب لأول موظف سجّل إرسال واتساب ليه).</p>';
        const log = r.log.length ? `<table class="dk-tbl"><thead><tr><th>الوقت</th><th>الشركة</th><th>اللي حصل</th><th>ملاحظة</th></tr></thead><tbody>${r.log.map(x => `<tr><td>${esc(x.at)}</td><td>${esc(x.company)}</td><td>${esc(x.label)}</td><td>${esc(x.note || '')}</td></tr>`).join('')}</tbody></table>` : '<p class="dk-muted">مفيش شغل مسجّل في الشهر ده.</p>';
        return `<div class="dk-modal-box"><button class="dk-btn" data-st-close>✕ إغلاق</button><div class="dk-st-head">${mgr && ic && ic.photo ? `<img class="dk-avatar" src="${docUrl('photo')}" alt="">` : ''}<h2>كشف ${esc(r.staff.name)}</h2></div><p class="dk-st-msg"></p>
            <div class="dk-st-bar">${picker}<label>الشهر <input type="month" data-st-month value="${esc(r.month)}"></label></div>
            <div class="dk-fn-row">${tile(num(s.sent), 'شركات بعتلها')}${tile(num(s.replied), 'ردّوا')}${tile(num(s.trial), 'جرّبوا')}${tile(num(s.subscribed), 'اشتركوا')}${tile(num(r.customers_active), 'عملاء مدفوعين سارين')}</div>
            ${docs}${profBanner}${idBox}${profile}${locBox}${compForm}
            <div class="dk-totals"><span>نسبة مؤكّد دفعها: <b>${egp(t.commission_confirmed_egp)}</b></span>${t.commission_pending_egp > 0 ? `<span class="dk-muted">بانتظار تأكيد الدفع: <b>${egp(t.commission_pending_egp)}</b></span>` : ''}<span>الراتب: <b>${egp(t.salary_egp)}</b></span><span class="tot">الإجمالي المستحق: <b>${egp(t.total_egp)}</b></span></div>
            <h3>العملاء اللي جابتهم</h3>${custs}<h3>سجل الشغل (${r.log.length})</h3><div class="dk-scroll">${log}</div>
            <ul class="dk-notes">${(r.notes || []).map(n => `<li>${esc(n)}</li>`).join('')}</ul></div>`;
    };
    const stMsg = (m, bad) => { const el = staffUi.el && staffUi.el.querySelector('.dk-st-msg'); if (el) { el.textContent = m; el.className = 'dk-st-msg' + (bad ? ' bad' : ''); el.scrollIntoView({ block: 'nearest' }); } };
    // الموقع: مرة واحدة بإذن الموظفة (المتصفح بيسألها)، ومفيش تتبّع مستمر
    const doLocation = () => new Promise((resolve, reject) => {
        if (!navigator.geolocation) return reject(new Error('المتصفح ده مش بيدعم تحديد الموقع'));
        navigator.geolocation.getCurrentPosition(async pos => {
            try { resolve(await api('/api/admin/outreach-desk/staff-location', { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy, consent: '1' })); } catch (e) { reject(e); }
        }, err => reject(new Error(err.code === 1 ? 'إذن الموقع اتمنع: بيتفعّل من القفل اللي جنب رابط الموقع وبعدها المحاولة تتعاد' : 'مقدرناش نحدد الموقع: المحاولة تتعاد')), { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
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
    // تصوير تلقائي: وش البطاقة ← (لما البيانات تتقرا صح) قلب البطاقة ← ضهرها ← الصورة الشخصية ← صفحة مراجعة بالبيانات (تتعدّل وتتحفظ).
    // مفيش زرار تصوير: الكاميرا بتصوّر لوحدها لما الصورة تبقى واضحة وثابتة، وبتكرر لحد ما القراءة تطلع صح
    const wiz = { el: null, stream: null, userId: 0, step: 'front', fields: null, partial: null, timer: null, busy: false, t0: 0, still: 0, prev: null, frontGray: null, fails: 0, mirror: false, best: 0, seen: 0 };
    const WIZ = { sharpMin: 90, sharpMinFace: 25, steadyMax: 8, flipMin: 14, graceMs: 9000, hardGraceMs: 18000, tickMs: 400, w: 320 };
    const wizStop = () => { clearInterval(wiz.timer); wiz.timer = null; clearInterval(wiz.recTimer); if (wiz.rec) { try { if (wiz.rec.mr.state !== 'inactive') wiz.rec.mr.stop(); } catch (e) { /* خلص */ } wiz.rec = null; } if (wiz.stream) { wiz.stream.getTracks().forEach(t => t.stop()); wiz.stream = null; } };
    const wizClose = () => { wizStop(); if (wiz.el) wiz.el.hidden = true; };
    // مقطع فيديو قصير لكل لقطة: بيتسجّل من أول الخطوة، وبيتجدّد كل 8 ثواني (بنحتفظ بآخر مقطع بس)، وبيكمّل ثانية وشوية بعد اللقطة الناجحة
    const wizRecStart = () => {
        clearInterval(wiz.recTimer); wiz.rec = null;
        if (!window.MediaRecorder || !wiz.stream) return;
        const mime = ['video/webm;codecs=vp8', 'video/webm', 'video/mp4'].find(m => MediaRecorder.isTypeSupported(m)); if (!mime) return;
        const begin = () => {
            const chunks = []; let mr; try { mr = new MediaRecorder(wiz.stream, { mimeType: mime, videoBitsPerSecond: 1000000 }); } catch (e) { return; }
            mr.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
            wiz.rec = { mr, done: new Promise(res => { mr.onstop = () => res(chunks.length ? new Blob(chunks, { type: mime.split(';')[0] }) : null); }) };
            try { mr.start(1000); } catch (e) { wiz.rec = null; }
        };
        begin();
        wiz.recTimer = setInterval(() => { const old = wiz.rec; if (!old || wiz.busy) return; old.mr.onstop = () => {}; try { old.mr.stop(); } catch (e) { /* خلص */ } begin(); }, 8000);
    };
    const wizClip = async () => {
        const r = wiz.rec; if (!r || r.mr.state === 'inactive') return null;
        await new Promise(res => setTimeout(res, 1200));
        try { r.mr.stop(); } catch (e) { return null; }
        return Promise.race([r.done, new Promise(res => setTimeout(() => res(null), 3000))]);
    };
    const wizUpload = async (side, blob, clip) => {
        const fd = new FormData(); fd.append('consent', '1'); fd.append('csrf_token', csrf); if (wiz.userId) fd.append('user_id', wiz.userId); fd.append(side, blob, side + '.jpg');
        if (clip && clip.size > 0 && clip.size < 6 * 1024 * 1024) fd.append(side + '_clip', clip, side + (clip.type === 'video/mp4' ? '.mp4' : '.webm'));
        const resp = await fetch('/api/admin/outreach-desk/staff-id-card', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
        const r = await resp.json().catch(() => null);
        if (!r?.success) throw new Error(r?.error || 'الرفع فشل');
        return r.data;
    };
    const wizMsg = (text, bad) => { const m = wiz.el && wiz.el.querySelector('.dk-cam-msg'); if (m) { m.textContent = text; m.classList.toggle('bad', !!bad); } };
    // المنطقة اللي جوه المربع بالنسبة لإحداثيات الفيديو (الفيديو معروض object-fit:cover، وممكن يكون معكوس في السيلفي)
    const wizRect = () => {
        const view = wiz.el.querySelector('.dk-cam-view'), v = view.querySelector('video'), fr = view.querySelector('.dk-cam-frame');
        if (!v.videoWidth) return null;
        const vr = view.getBoundingClientRect(), f = fr.getBoundingClientRect(), s = Math.max(vr.width / v.videoWidth, vr.height / v.videoHeight);
        const ox = (vr.width - v.videoWidth * s) / 2, oy = (vr.height - v.videoHeight * s) / 2;
        let sx = Math.max(0, (f.left - vr.left - ox) / s); const sy = Math.max(0, (f.top - vr.top - oy) / s), sw = f.width / s, sh = f.height / s;
        if (wiz.mirror) sx = Math.max(0, v.videoWidth - sx - sw);
        return { v, sx, sy, sw: Math.min(sw, v.videoWidth - sx), sh: Math.min(sh, v.videoHeight - sy) };
    };
    // صورة صغيرة رمادية للمنطقة: بنقيس بيها الوضوح (تباين لابلاس) والثبات (الفرق عن اللقطة اللي فاتت)
    const wizGray = () => {
        const r = wizRect(); if (!r) return null;
        const w = WIZ.w, h = Math.max(1, Math.round(w * r.sh / r.sw)), c = document.createElement('canvas'); c.width = w; c.height = h;
        const ctx = c.getContext('2d', { willReadFrequently: true }); ctx.drawImage(r.v, r.sx, r.sy, r.sw, r.sh, 0, 0, w, h);
        const d = ctx.getImageData(0, 0, w, h).data, g = new Float32Array(w * h);
        for (let i = 0; i < g.length; i++) g[i] = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
        return { g, w, h };
    };
    const wizSharp = ({ g, w, h }) => {
        let n = 0, sum = 0, sq = 0;
        for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) { const i = y * w + x, l = 4 * g[i] - g[i - 1] - g[i + 1] - g[i - w] - g[i + w]; sum += l; sq += l * l; n++; }
        const m = sum / n; return sq / n - m * m;
    };
    const wizDiff = (a, b) => { if (!a || !b || a.g.length !== b.g.length) return 255; let s = 0, n = 0; for (let i = 0; i < a.g.length; i += 5) { s += Math.abs(a.g[i] - b.g[i]); n++; } return s / n; };
    const wizBlob = () => {
        const r = wizRect(); if (!r) throw new Error('الكاميرا لسه بتفتح');
        const k = Math.min(1, 1600 / r.sw), c = document.createElement('canvas'); c.width = Math.round(r.sw * k); c.height = Math.round(r.sh * k);
        c.getContext('2d').drawImage(r.v, r.sx, r.sy, r.sw, r.sh, 0, 0, c.width, c.height);
        return new Promise((res, rej) => c.toBlob(b => b ? res(b) : rej(new Error('التصوير فشل')), 'image/jpeg', 0.9));
    };
    const STEPS = {
        front: { title: 'وش البطاقة', cam: 'environment', idle: 'وش البطاقة جوه المربع' },
        back: { title: 'ضهر البطاقة', cam: 'environment', idle: 'قلب البطاقة لضهرها جوه المربع' },
        photo: { title: 'صورة شخصية', cam: 'user', idle: 'الوش جوه الشكل البيضاوي' },
    };
    const wizStart = async (step, text, bad) => {
        wizStop(); wiz.step = step; wiz.busy = false; wiz.prev = null; wiz.still = 0; wiz.best = 0; wiz.seen = 0; wiz.t0 = Date.now(); wiz.mirror = STEPS[step].cam === 'user';
        const st = STEPS[step];
        wiz.el.innerHTML = `<div class="dk-cam-box"><button class="dk-btn" data-wz-close>✕ إغلاق</button><h3>${esc(st.title)}</h3>
            <div class="dk-cam-view ${wiz.mirror ? 'mirror' : ''}"><video autoplay playsinline muted></video><div class="dk-cam-frame ${step === 'photo' ? 'oval' : ''}"></div></div>
            <p class="dk-cam-msg ${bad ? 'bad' : ''}">${esc(text || st.idle)}</p><p class="dk-cam-hint"></p>
            <div class="dk-cam-actions">${step === 'photo' ? '' : `<label class="dk-btn">🖼️ اختيار صورة من الجهاز<input type="file" accept="image/*" capture="environment" hidden data-wz-file></label>`}
            ${step === 'front' ? '<button class="dk-btn" data-wz-manual>✍️ كتابة البيانات يدويًا</button>' : (step === 'back' ? '<button class="dk-btn" data-wz-skip>تخطّي</button>' : '')}</div></div>`;
        try {
            wiz.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: st.cam }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
            const v = wiz.el.querySelector('video'); v.srcObject = wiz.stream;
            try { await wiz.stream.getVideoTracks()[0].applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) { /* مش كل الكاميرات بتدعم ضبط الفوكس */ }
            wiz.timer = setInterval(wizTick, WIZ.tickMs); wizRecStart();
        } catch (e) {
            // السلفي لازم يكون كاميرا حية فعلًا: لو اتمنعت، مفيش بديل باختيار صورة
            wizMsg(step === 'photo' ? 'الكاميرا مش متاحة أو الإذن اتمنع: الصورة الشخصية لازم تتصوّر لايف، لازم تدّي إذن الكاميرا وتجرّب تاني' : 'الكاميرا مش متاحة أو الإذن اتمنع: اختيار صورة من الجهاز', true);
            wiz.el.querySelector('.dk-cam-view').hidden = step !== 'photo';
        }
    };
    const wizTick = async () => {
        if (wiz.busy || !wiz.el || wiz.el.hidden || Date.now() < wiz.t0) return;
        const cur = wizGray(); if (!cur) return;
        const hint = wiz.el.querySelector('.dk-cam-hint'), face = wiz.step === 'photo';
        const steady = wizDiff(cur, wiz.prev) < WIZ.steadyMax; wiz.prev = cur; wiz.still = steady ? wiz.still + 1 : 0;
        const sharp = wizSharp(cur), min = face ? WIZ.sharpMinFace : WIZ.sharpMin;
        const flipped = wiz.step !== 'back' || wizDiff(cur, wiz.frontGray) > WIZ.flipMin; // الضهر لازم يختلف عن الوش
        const waited = Date.now() - wiz.t0 > WIZ.graceMs;
        // السلفي مفيهوش تخطّي ولا اختيار من الجهاز (لازم كاميرا حية): لو كاميرا الموظف ضعيفة ومستحملتش توصل للوضوح المطلوب أبدًا، بعد مهلة أطول بنصوّر أيًا كان الوضوح
        // عشان الخطوة متقفلش للأبد، وفحص الوش بالذكاء الاصطناعي (بعد الرفع) هو اللي بيتأكد إن اللقطة فيها وش فعلًا
        const hardWait = face && Date.now() - wiz.t0 > WIZ.hardGraceMs;
        // الفوكس التلقائي بياخد ثواني: مابنصوّرش غير لما الوضوح يوصل قريب من أحسن وضوح شفناه في الخطوة دي
        wiz.seen++; wiz.best = Math.max(wiz.best, sharp);
        const focused = wiz.seen >= 4 && sharp >= 0.75 * wiz.best;
        if (!flipped) hint.textContent = '↩️ لسه على وش البطاقة';
        else if (!steady) hint.textContent = '⏳ الكاميرا بتتحرك';
        else if (sharp < min) hint.textContent = '🔍 الصورة لسه مش واضحة: تقريب أو تبعيد شوية';
        else hint.textContent = '✅ واضحة وثابتة… بنصوّر';
        // بنصوّر لما تبقى واضحة وثابتة، أو بعد مهلة لو الكاميرا نفسها مش بتديل أوضح من كده
        if (flipped && wiz.still >= 2 && ((sharp >= min && focused) || (waited && sharp >= min / 2.5) || hardWait)) await wizShoot(cur);
    };
    const wizShoot = async cur => {
        wiz.busy = true;
        try { const blob = await wizBlob(); wizMsg('📷 بنصوّر…'); const clip = await wizClip(); await wizProcess(blob, cur, clip); } catch (err) { wiz.busy = false; wizMsg(err.message, true); }
    };
    const wizReview = () => {
        wizStop(); const f = wiz.fields || wiz.partial || {};
        const extra = f.birth_date ? `ميلاد ${f.birth_date} · ${f.gender === 'male' ? 'ذكر' : 'أنثى'} · ${f.governorate}` : '';
        wiz.el.innerHTML = `<div class="dk-cam-box"><button class="dk-btn" data-wz-close>✕ إغلاق</button><h3>البيانات اللي اتقرت من البطاقة</h3>
            <p class="dk-cam-msg">${esc(extra)}</p>
            <div class="dk-comp"><label>الاسم بالكامل<input data-wz-f="full_name" value="${esc(f.full_name || '')}"></label>
            <label>الرقم القومي<input data-wz-f="national_id" inputmode="numeric" value="${esc(f.national_id || '')}"></label>
            <label>العنوان<input data-wz-f="address" value="${esc(f.address || '')}"></label>
            <label>رقم التليفون / واتساب<input data-wz-f="phone" type="tel" value=""></label>
            <label>شخص للطوارئ<input data-wz-f="emergency_name" value=""></label>
            <label>تليفون الطوارئ<input data-wz-f="emergency_phone" type="tel" value=""></label>
            <label>طريقة استلام المستحقات<select data-wz-f="pay_method"><option value="">—</option><option value="vodafone_cash">فودافون كاش</option><option value="instapay">إنستاباي</option><option value="bank">تحويل بنكي</option><option value="cash">كاش</option></select></label>
            <label>رقم المحفظة / الحساب<input data-wz-f="pay_account" value=""></label></div>
            <p class="dk-cam-msg bad" data-wz-err></p><div class="dk-cam-actions"><button class="dk-btn primary" data-wz-save>✅ حفظ</button></div></div>`;
    };
    const wizProcess = async (blob, gray, clip) => {
        const step = wiz.step; wiz.busy = true; wizMsg(step === 'front' ? '📖 بنقرا البطاقة…' : '⬆️ بيترفع…');
        try {
            const d = await wizUpload(step, blob, clip);
            if (step === 'photo') return wizReview();
            if (step === 'back') return wizStart('photo');
            const ex = d.extract, f = ex && ex.ok ? ex.fields : null;
            wiz.partial = f;
            if (f && f.full_name && f.national_id && !f.warning) { wiz.fields = f; wiz.frontGray = gray || null; await wizStart('back', '✅ اتقرت البطاقة صح. قلب البطاقة لضهرها'); wiz.t0 = Date.now() + 1500; }
            else { wiz.fails++; await wizStart('front', (ex && ex.error) || 'مقدرناش نقرا الاسم والرقم القومي صح: بنحاول تاني (الإضاءة كويسة ومن غير لمعان)', true); wiz.t0 = Date.now() + 2500; }
        } catch (err) { wiz.busy = false; wizMsg(err.message, true); wiz.t0 = Date.now() + 3000; }
    };
    const openWizard = userId => {
        if (!wiz.el) {
            wiz.el = document.createElement('div'); wiz.el.className = 'dk-modal dk-cam'; wiz.el.dir = 'rtl'; document.body.appendChild(wiz.el);
            wiz.el.addEventListener('click', async e => {
                const t = e.target.closest('button'); if (!t) return;
                try {
                    if (t.dataset.wzClose !== undefined) return wizClose();
                    if (t.dataset.wzManual !== undefined) return wizReview();
                    if (t.dataset.wzSkip !== undefined) return wiz.step === 'back' ? wizStart('photo') : wizReview();
                    if (t.dataset.wzSave !== undefined) {
                        const body = {}; if (wiz.userId) body.user_id = wiz.userId;
                        // الاسم والرقم القومي والعنوان بيتبعتوا دايمًا (اللي اتقرا من البطاقة)، والباقي بس لو اتكتب: عشان ما نمسحش بيانات محفوظة قبل كده
                        const core = ['full_name', 'national_id', 'address'];
                        wiz.el.querySelectorAll('[data-wz-f]').forEach(el => { if (core.includes(el.dataset.wzF) || el.value !== '') body[el.dataset.wzF] = el.value; });
                        t.disabled = true;
                        try { await api('/api/admin/outreach-desk/staff-profile', body); } catch (err) { t.disabled = false; wiz.el.querySelector('[data-wz-err]').textContent = err.message; return; }
                        wizClose(); toast('اتحفظت البيانات ✅');
                        if (staffUi.el && !staffUi.el.hidden) await loadStaff();
                        await load(true);
                    }
                } catch (err) { wizMsg(err.message, true); }
            });
            wiz.el.addEventListener('change', async e => {
                const f = e.target.files && e.target.files[0]; if (!f || e.target.dataset.wzFile === undefined) return;
                // اختيار صورة من الجهاز برضو بياخد المقطع اللي كان بيتسجّل من الكاميرا المفتوحة قدامه (نفس المعاينة الحية اللي شايفها)
                wiz.busy = true; try { const clip = await wizClip(); await wizProcess(await shrinkImage(f), null, clip); } catch (err) { wiz.busy = false; wizMsg(err.message, true); }
            });
        }
        wiz.userId = userId || 0; wiz.fields = null; wiz.partial = null; wiz.frontGray = null; wiz.fails = 0; wiz.el.hidden = false;
        // الموقع بيتطلب في نفس اللحظة مع الكاميرا (المتصفح بيعرض الإذنين مع بعض). للموظف نفسه بس، ومرة واحدة، وفشله مابيوقفش التصوير
        if (!wiz.userId && me.location_recorded !== true) doLocation().then(() => { me.location_recorded = true; }).catch(() => { /* الموقع اختياري هنا */ });
        wizStart('front');
    };
    const openStaff = async () => {
        if (!staffUi.el) {
            staffUi.el = document.createElement('div'); staffUi.el.className = 'dk-modal'; staffUi.el.dir = 'rtl'; document.body.appendChild(staffUi.el);
            staffUi.el.addEventListener('click', async e => {
                const t = e.target.closest('button'); if (!t) return;
                try {
                    if (t.dataset.stClose !== undefined) { staffUi.el.hidden = true; return; }
                    if (t.classList.contains('dk-del')) {
                        // ضغطتين عشان مايتحذفش بالغلط: الأولى بتجهّز والتانية بتحذف نهائيًا (من غير أي أثر)
                        if (!t.dataset.armed) { t.dataset.armed = '1'; t.dataset.txt = t.textContent; t.textContent = '⚠️ اضغط تاني للحذف النهائي'; setTimeout(() => { if (t.isConnected && t.dataset.armed) { delete t.dataset.armed; t.textContent = t.dataset.txt; } }, 4000); return; }
                        const body = { user_id: staffUi.userId };
                        if (t.dataset.delAll !== undefined) body.all = '1'; else if (t.dataset.delHist) body.history_id = t.dataset.delHist; else body.side = t.dataset.delSide;
                        t.disabled = true; await api('/api/admin/outreach-desk/staff-id-delete', body); await loadStaff(); stMsg('اتحذف نهائيًا ✅'); return;
                    }
                    if (t.dataset.recapture !== undefined) { t.disabled = true; t.textContent = '⏳ بيتبعت…'; try { await api('/api/admin/outreach-desk/staff-recapture', { user_id: staffUi.userId }); } catch (err) { t.disabled = false; t.textContent = '🔔 اطلب منه يعيد التصوير'; throw err; } await loadStaff(); const nb = staffUi.el.querySelector('[data-recapture]'); if (nb) { nb.textContent = '✅ اتطلب منه'; nb.disabled = true; } stMsg('اتطلب منه إعادة التصوير: التنبيه هيظهر له أول ما يفتح المكتب ✅'); return; }
                    if (t.dataset.idWizard !== undefined) { openWizard(staffUi.isSelf ? 0 : staffUi.userId); return; }
                    if (t.dataset.locSave !== undefined) { t.disabled = true; try { await doLocation(); me.location_recorded = true; await loadStaff(); stMsg('اتسجّل موقعك ✅'); } finally { t.disabled = false; } return; }
                    if (t.dataset.pfSave !== undefined) {
                        const body = { user_id: staffUi.userId };
                        staffUi.el.querySelectorAll('[data-pf]').forEach(el => { body[el.dataset.pf] = el.value; });
                        const saved = await api('/api/admin/outreach-desk/staff-profile', body); await loadStaff(); stMsg('اتحفظت البيانات ✅' + (saved.profile && saved.profile.released ? ` · رجعت ${saved.profile.released} شركة لقايمة «لسه ماتواصلتش» (الموظف ساب الشغل)` : '')); return;
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
        staffUi.userId = d.report.staff.user_id; staffUi.isSelf = !!d.is_self;
        staffUi.el.innerHTML = staffHtml(d);
    };

    const bind = () => {
        root.addEventListener('click', async e => {
            const wa = e.target.closest('a[data-wa]');
            if (wa) { claimAndOpen(wa, e); return; }
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
                if (t.dataset.idWizardMain !== undefined) { openWizard(0); return; }
                if (t.dataset.signSave !== undefined) { const v = (root.querySelector('#dkSign') || {}).value || ''; await api('/api/admin/outreach-desk/staff-profile', { sign_name: v }); toast('اتحفظ اسمك في التوقيع ✅'); return await load(true); }
                if (t.dataset.goOpened !== undefined) { const c = counts.find(x => x.opened > 0); if (c) { state.country = c.country; state.view = 'opened'; state.tier = ''; state.page = 1; store.set('dk-country', state.country); return await load(); } return; }
                if (t.dataset.goFollowup !== undefined) { const c = counts.find(x => x.followup > 0); if (c) { state.country = c.country; state.view = 'followup'; state.tier = ''; state.page = 1; store.set('dk-country', state.country); return await load(); } return; }
                if (t.dataset.locFirst !== undefined) { t.disabled = true; try { await doLocation(); me.location_recorded = true; render(); toast('اتسجّل موقعك ✅ شكرًا'); } finally { t.disabled = false; } return; }
                if (t.dataset.copyMsg2 !== undefined) { const box = cardEl.querySelector('.dk-msg2 .dk-msgbox'); toast(await copy(box.value) ? 'اتنسخت رسالة الخدمات ✅' : 'مقدرتش أنسخ', false); return; }
                if (t.dataset.undo !== undefined) { t.disabled = true; await mark(Number(cardEl.dataset.id), 'undo', ''); return; }
                if (t.dataset.country) { state.country = t.dataset.country; state.view = 'todo'; state.tier = ''; state.page = 1; store.set('dk-country', state.country); return await load(); }
                if (t.dataset.view) { state.view = t.dataset.view; state.page = 1; return await load(); }
                if (t.dataset.tier !== undefined) { state.tier = t.dataset.tier; state.page = 1; return await load(); }
                if (t.dataset.page) { state.page += Number(t.dataset.page); return await load(); }
                if (t.dataset.copyPhone !== undefined) { toast(await copy(t.dataset.copyPhone) ? 'اتنسخ ✅' : 'النسخ التلقائي مش متاح: النص بيتظلّل وبيتنسخ يدويًا', false); return; }
                if (t.dataset.copyLink !== undefined) { toast(await copy(t.dataset.copyLink) ? 'اتنسخ لينك التقرير ✅' : 'مقدرتش أنسخ', false); return; }
                if (t.dataset.copyMsg !== undefined) { const box = cardEl.querySelector('.dk-msgbox'); toast(await copy(box.value) ? 'اتنسخت الرسالة ✅' : 'النسخ التلقائي مش متاح: الرسالة بتتظلّل وبتتنسخ يدويًا', false); return; }
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
            try { bind(); await load(true); setInterval(poll, 8000); } catch (e) { root.innerHTML = `<p class="dk-msg bad">${esc(e.message)}</p>`; }
        },
    };
})();
