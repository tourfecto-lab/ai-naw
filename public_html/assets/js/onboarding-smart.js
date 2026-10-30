/* الإعداد الذكي: العميل يكتب رابط موقعه بس → بنقرا الموقع ونستخرج كل حاجة → يراجع ويدوس «التالي».
 * في الآخر بنملا حقول الـWizard القديم وبننادي obSubmit() (نفس التجهيز اللي كان شغال). */
(function () {
    const host = document.getElementById('obSmart');
    if (!host) return;
    // register: قبل إنشاء الحساب (صفحة التسجيل) | onboarding: بعد التسجيل (الـWizard)
    const REG = host.getAttribute('data-mode') === 'register';
    const card = REG ? document.querySelector('.auth-card') : document.querySelector('.ob-card');
    if (!card || (!REG && typeof window.obSubmit !== 'function')) return;
    const API = REG ? '/api/public/smart/' : '/api/onboarding/';

    const lang = (document.documentElement.lang || 'ar').slice(0, 2);
    const ar = lang === 'ar';
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let nf = null;
    try { nf = new Intl.NumberFormat(lang, { notation: 'compact', maximumFractionDigits: 1 }); } catch (e) { }
    const num = n => (nf ? nf.format(Number(n) || 0) : String(n));
    const LANG_SHORT = { en: 'EN', ar: 'AR', de: 'DE', fr: 'FR', es: 'ES', it: 'IT', ru: 'RU', pl: 'PL', nl: 'NL', pt: 'PT', zh: 'ZH', ja: 'JA', tr: 'TR', cs: 'CS', sv: 'SV', ro: 'RO', hu: 'HU', uk: 'UK', ko: 'KO' };
    let csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || (document.querySelector('input[name="csrf_token"]') || {}).value || '';
    // الصفحة اتسابت مفتوحة والجلسة انتهت: نجيب رمز جديد من نفس الصفحة بهدوء بدل «اعمل refresh»
    async function freshCsrf() {
        try {
            const html = await (await fetch(location.pathname, { credentials: 'same-origin', cache: 'no-store' })).text();
            const m = html.match(/name="csrf-token" content="([^"]+)"/) || html.match(/name="csrf_token" value="([^"]+)"/);
            if (m) csrf = m[1];
            return !!m;
        } catch (e) { return false; }
    }
    let regionNames = null;
    try { regionNames = new Intl.DisplayNames([lang], { type: 'region' }); } catch (e) { }
    const countryName = c => { try { return (regionNames && regionNames.of(c)) || c; } catch (e) { return c; } };
    const LANGS = { en: 'English', ar: 'العربية', fr: 'Français', de: 'Deutsch', es: 'Español', it: 'Italiano', pt: 'Português', ru: 'Русский', zh: '中文', ja: '日本語', nl: 'Nederlands', pl: 'Polski', tr: 'Türkçe' };
    const MARKETS = ['US', 'GB', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'CH', 'AT', 'PL', 'CZ', 'RU', 'UA', 'SE', 'NO', 'DK', 'FI', 'IE', 'PT', 'CA', 'AU', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'EG', 'JO', 'LB', 'MA', 'TR', 'IN', 'CN', 'JP', 'KR', 'BR', 'MX'];
    const MAX_COMP = 3;

    const S = { url: '', profile: null, competitors: [], source: '', picked: [], analyzeFailed: false, answers: { booking: [], season: null, goal: null }, kwPick: null, articles: null, trialArticles: 2 };

    async function post(url, body, ms, retried) {
        const ctl = new AbortController();
        const timer = setTimeout(() => ctl.abort(), ms || 60000);
        try {
            const r = await fetch(url, { method: 'POST', credentials: 'same-origin', signal: ctl.signal, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' }, body: JSON.stringify(Object.assign({ csrf_token: csrf }, body)) });
            if (r.status === 419 && !retried && await freshCsrf()) { clearTimeout(timer); return post(url, body, ms, true); }
            return await r.json();
        } catch (e) { if (window.console) console.warn('obs request failed', url, e && e.message); return { success: false }; } finally { clearTimeout(timer); }
    }

    function stepper(n) {
        const labels = [t('نشاطك', 'Business'), t('السوق', 'Market'), t('الكلمات', 'Keywords'), t('المنافسين', 'Competitors'), t('خطتك', 'Your plan')];
        return `<div class="obs-stepper">${labels.map((l, i) => `<span class="${i + 1 < n ? 'done' : i + 1 === n ? 'on' : ''}"><b>${i + 1 < n ? '✓' : i + 1}</b>${esc(l)}</span>`).join('')}</div>`;
    }
    const nav = (back, nextLabel) => `<div class="obs-nav">${back ? `<button type="button" class="ob-btn ghost" data-obs="${back}">${t('رجوع', 'Back')}</button>` : '<span></span>'}<button type="button" class="ob-btn primary" data-obs-next>${nextLabel}</button></div>`;

    // ---------------------------------------------------------------- الشاشات
    function viewUrl() {
        host.innerHTML = `
            <div class="obs-hero">
                <span class="obs-badge">✓ ${t('أقل من دقيقة · من غير كارت', 'Under a minute · No credit card')}</span>
                <h2 class="ob-h">${t('اكتب رابط موقعك… والباقي علينا', 'Enter your website… we handle the rest')}</h2>
                <p class="ob-sub">${t('هنقرا موقعك ونعرف خدماتك وأسواقك ومنافسينك لوحدنا، وإنت بس تراجع وتأكد.', 'We read your site and detect your services, markets and competitors automatically — you just review and confirm.')}</p>
            </div>
            <div class="ob-field">
                <label for="obsUrl">${t('رابط موقعك', 'Your website URL')}</label>
                <div class="obs-url"><span>🌐</span><input type="text" id="obsUrl" inputmode="url" maxlength="300" placeholder="yourcompany.com" value="${esc(S.url)}" autocomplete="url"></div>
            </div>
            <button type="button" class="ob-btn primary obs-go" data-obs-analyze>✨ ${t('حلّل موقعي', 'Analyze my website')}</button>
            <div class="obs-links">
                ${REG ? `<button type="button" class="obs-link" data-obs-manual>${t('معنديش موقع ← اعمل حسابك ونبنيلك موقع', "No website yet? Create your account and we'll build one")}</button><a href="/login">${t('عندك حساب؟ سجّل دخول', 'Have an account? Log in')}</a>`
                : `<a href="/dashboard?skip_onboarding=1&amp;next=%2Fwebsite-builder">${t('معنديش موقع ← اعمل موقع احترافي', "I don't have a website → build one")}</a>
                <button type="button" class="obs-link" data-obs-manual>${t('أدخل بياناتي يدوي', 'Enter my details manually')}</button>`}
            </div>`;
        const inp = document.getElementById('obsUrl');
        inp.focus();
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); analyze(); } });
    }

    function viewWorking(stage, note) {
        const st = [t('بنفتح موقعك ونقرا محتواه', 'Opening your website and reading it'), t('بنحدد خدماتك وأسواقك', 'Detecting your services and markets'), t('بندوّر على منافسينك', 'Finding your competitors')];
        host.innerHTML = `
            <div class="obs-working">
                <div class="obs-orb"><span></span></div>
                <h2 class="ob-h">${t('بنحلّل موقعك…', 'Analyzing your website…')}</h2>
                <p class="ob-sub" dir="ltr">${esc(S.url.replace(/^https?:\/\//, ''))}</p>
                <ul class="obs-stages">${st.map((l, i) => `<li class="${i < stage ? 'done' : i === stage ? 'on' : ''}"><i></i>${esc(l)}</li>`).join('')}</ul>
                ${note ? `<p class="obs-found">${note}</p>` : ''}
            </div>`;
    }

    // رسالة لكل سبب فشل + زرار «جرّب تاني» للأسباب المؤقتة
    function failText() {
        const why = {
            refused: esc(S.analyzeMsg || ''),
            fetch_failed: t('موقعك مافتحش لسيرفرنا (ممكن يكون واقع دلوقتي أو قافل على الزوار الآليين). اكتب بياناتك هنا، أو جرّب تاني بعد شوية.', "Your website didn't open for our server (it may be down or block automated visitors). Fill in your details here, or try again shortly."),
            empty: t('الصفحة الرئيسية لموقعك مفيهاش كلام كفاية نفهم منه نشاطك. اكتب بياناتك هنا وهنكمّل الباقي.', "Your homepage doesn't have enough text for us to understand your business. Fill in your details and we'll handle the rest."),
            ai_failed: t('التحليل اتأخر المرة دي. جرّب تاني، وغالبًا هيشتغل على طول.', 'The analysis took too long this time. Try again — it usually works right away.'),
            network: t('الاتصال فصل أثناء التحليل. جرّب تاني.', 'The connection dropped during the analysis. Please try again.'),
            error: t('حصل خطأ عندنا أثناء التحليل. جرّب تاني.', 'Something went wrong on our side. Please try again.'),
        }[S.analyzeReason] || t('ماقدرناش نقرا موقعك بالكامل — كمّل البيانات دي وهنظبط الباقي.', "We couldn't fully read your site — fill these in and we'll handle the rest.");
        const retry = ['fetch_failed', 'ai_failed', 'network', 'error'].includes(S.analyzeReason) && S.url
            ? ` <button type="button" class="ob-btn ghost obs-retry" data-obs-retry>${t('↻ جرّب تاني', '↻ Try again')}</button>` : '';
        return why + retry;
    }

    function viewBusiness() {
        const p = S.profile;
        host.innerHTML = stepper(1) + `
            <h2 class="ob-h">${t('اتأكد من بيانات نشاطك', 'Check your business details')}</h2>
            <p class="ob-sub">${S.analyzeFailed ? failText() : t('ده اللي فهمناه من موقعك. عدّل أي حاجة لو مش مظبوطة.', 'This is what we understood from your website. Edit anything that is off.')}</p>
            <div class="obs-box">
                <div class="ob-field"><label for="obsName">${t('اسم الشركة', 'Business name')}</label><input type="text" id="obsName" dir="auto" maxlength="120" value="${esc(p.business_name)}"></div>
                <div class="ob-field"><label for="obsDesc">${t('وصف النشاط', 'Business description')}</label><textarea id="obsDesc" dir="auto" rows="4" maxlength="600">${esc(p.description)}</textarea><small class="obs-count"><span id="obsDescN">${(p.description || '').length}</span>/600</small></div>
                <div class="ob-field"><label for="obsIndustry">${t('نوع النشاط', 'Business type')}</label><select id="obsIndustry">
                    ${[['tours', t('رحلات وجولات سياحية', 'Tours & activities')], ['travel_agency', t('شركة سياحة', 'Travel agency')], ['hotel', t('فندق', 'Hotel')], ['other', t('نشاط تاني', 'Other')]].map(([v, l]) => `<option value="${v}"${p.industry === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
            </div>
            ${platformCard()}
            <div class="obs-box">
                <label class="obs-label">${t('اللي بتبيعه', 'What you sell')} <small>${t('(الأهم الأول)', '(most important first)')}</small></label>
                <div class="obs-chips" id="obsServices">${p.services.map((s, i) => chip(s, i)).join('')}</div>
                <div class="obs-add"><input type="text" id="obsServiceNew" dir="auto" maxlength="60" placeholder="${t('ضيف خدمة…', 'Add a service…')}"><button type="button" class="ob-btn ghost" data-obs-add-service>+</button></div>
            </div>
            ${nav('url', t('التالي', 'Next'))}`;
        document.getElementById('obsDesc').addEventListener('input', e => { document.getElementById('obsDescN').textContent = e.target.value.length; });
        document.getElementById('obsServiceNew').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addService(); } });
    }
    // النظام اللي اكتشفناه للموقع (بدل ما العميل يختار) + الخطوة اللي جاية على حسبه
    function platformCard() {
        const pf = S.profile && S.profile.platform;
        if (!pf || (!pf.label && !(pf.booking || []).length)) return '';
        const next = pf.publish === 'wordpress'
            ? t('هننشر المقالات على موقعك تلقائي — بعد التسجيل بتربطه بضغطة.', "We'll publish articles to your site automatically — connect it in one click after sign-up.")
            : pf.publish === 'api'
                ? t('موقعك مبرمج خاص: المبرمج بتاعك يقدر يربطه بالـAPI بتاعنا للنشر التلقائي، أو المقالات توصلك جاهزة تنسخها.', 'Custom-built site: your developer can connect our API for auto-publishing, or articles arrive ready to copy.')
                : t('المقالات بتوصلك جاهزة بالصور والتنسيق، تنسخها وتنزلها على موقعك في ثواني.', 'Articles arrive ready with images and formatting — copy them to your site in seconds.');
        const booking = (pf.booking || []).filter(b => b !== 'WhatsApp');
        return `<div class="obs-box obs-pf">
            <div class="obs-pf-h"><span class="obs-pf-ic">🔍</span><div><b>${pf.label ? t('موقعك شغال على', 'Your site runs on') + ' ' + esc(pf.label) : t('مقدرناش نحدد نظام موقعك', "We couldn't identify your site's platform")}</b>
                ${pf.evidence ? `<small dir="ltr">${t('لقينا', 'found')}: ${esc(pf.evidence)}</small>` : ''}</div></div>
            ${booking.length ? `<div class="obs-pf-row">🎟️ ${t('نظام الحجز عندك', 'Your booking system')}: <b>${booking.map(esc).join(' · ')}</b></div>` : ''}
            ${(pf.booking || []).includes('WhatsApp') ? `<div class="obs-pf-row">💬 ${t('زرار واتساب موجود على موقعك', 'WhatsApp button found on your site')}</div>` : ''}
            <div class="obs-pf-next">${next}</div>
        </div>`;
    }
    const chip = (s, i) => `<span class="obs-chip" dir="auto">${i < 3 ? '<em>★</em>' : ''}${esc(s)}<button type="button" data-obs-del-service="${i}" aria-label="${t('حذف', 'Remove')}">×</button></span>`;
    function saveBusiness() {
        const p = S.profile;
        p.business_name = document.getElementById('obsName').value.trim();
        p.description = document.getElementById('obsDesc').value.trim();
        p.industry = document.getElementById('obsIndustry').value;
    }
    function addService() {
        const inp = document.getElementById('obsServiceNew');
        const v = inp.value.trim();
        if (v && !S.profile.services.includes(v)) S.profile.services.push(v);
        saveBusiness(); viewBusiness();
        document.getElementById('obsServiceNew').focus();
    }

    function viewMarket() {
        const p = S.profile;
        const langOpts = Object.entries(LANGS).map(([c, l]) => `<option value="${c}"${(p.site_language || 'en') === c ? ' selected' : ''}>${esc(l)}</option>`).join('');
        const avail = MARKETS.filter(c => !p.target_countries.includes(c)).map(c => [c, countryName(c)]).sort((a, b) => a[1].localeCompare(b[1]));
        host.innerHTML = stepper(2) + `
            <h2 class="ob-h">${t('مين عملاءك ومنين؟', 'Who are your customers and where from?')}</h2>
            <p class="ob-sub">${t('هنكتب المحتوى ونستهدف البحث على أساس الاختيارات دي.', 'We write content and target search based on these choices.')}</p>
            <div class="obs-box">
                <div class="ob-field"><label for="obsLang">${t('لغة المحتوى', 'Content language')}</label><select id="obsLang">${langOpts}</select></div>
            </div>
            <div class="obs-box">
                <label class="obs-label">${t('الدول اللي عملاءك جايين منها', 'Countries your customers come from')}</label>
                <div class="obs-chips" id="obsCountries">${p.target_countries.length ? p.target_countries.map(c => `<span class="obs-chip">${esc(countryName(c))}<button type="button" data-obs-del-country="${c}" aria-label="${t('حذف', 'Remove')}">×</button></span>`).join('') : `<span class="obs-empty">${t('اختار دولة واحدة على الأقل', 'Pick at least one country')}</span>`}</div>
                <div class="obs-add"><select id="obsCountryNew"><option value="">${t('+ ضيف دولة', '+ Add a country')}</option>${avail.map(([c, n]) => `<option value="${c}">${esc(n)}</option>`).join('')}</select></div>
            </div>
            <div class="obs-box obs-two">
                <div class="ob-field"><label for="obsCurrency">${t('عملة الأسعار', 'Price currency')}</label><input type="text" id="obsCurrency" maxlength="3" value="${esc(p.currency || 'USD')}" dir="ltr"></div>
                <div class="ob-field"><label for="obsCustomers">${t('عملاءك (اختياري)', 'Your customers (optional)')}</label><input type="text" id="obsCustomers" dir="auto" maxlength="200" value="${esc(p.target_customers)}" placeholder="${t('مثلًا: عائلات وأزواج من أوروبا', 'e.g. families and couples from Europe')}"></div>
            </div>
            ${nav('business', t('التالي', 'Next'))}`;
        document.getElementById('obsCountryNew').addEventListener('change', e => {
            if (e.target.value && S.profile.target_countries.length < 10) { saveMarket(); S.profile.target_countries.push(e.target.value); viewMarket(); }
        });
    }
    function saveMarket() {
        const p = S.profile;
        p.site_language = document.getElementById('obsLang').value;
        p.currency = (document.getElementById('obsCurrency').value || 'USD').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3) || 'USD';
        p.target_customers = document.getElementById('obsCustomers').value.trim();
    }

    function siteCard() {
        const st = S.site;
        if (!st) return '';
        const mk = S.market && S.market.country ? countryName(S.market.country) : '';
        return `<div class="obs-site">
            <div class="obs-site-h"><img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(S.url.replace(/^https?:\/\//, ''))}&sz=64" alt="" onerror="this.style.visibility='hidden'"><b dir="ltr">${esc(S.url.replace(/^https?:\/\//, '').replace(/\/$/, ''))}</b><span class="obs-you">${t('إنت', 'YOU')}</span>${st.rank ? `<span class="obs-rankpill">${t('ترتيبك', 'You rank')} #${st.rank} ${t('من', 'of')} ${st.of}</span>` : ''}</div>
            <div class="obs-stats">
                <div><b>${num(st.keywords)}</b><span>${t('كلمة ظاهر بيها في جوجل', 'keywords you rank for')}</span></div>
                ${st.top10 != null ? `<div><b>${num(st.top10)}</b><span>${t('في أول صفحة', 'on page 1')}</span></div>` : ''}
                <div><b>~${num(st.traffic)}</b><span>${t('زيارة شهريًا (تقديري)', 'visits / month (est.)')}</span></div>
            </div>
            ${mk ? `<small class="obs-src">${t('بيانات جوجل الحقيقية لسوق', 'Real Google data for')} ${esc(mk)} · DataForSEO</small>` : ''}
        </div>`;
    }

    function viewKeywords() {
        const kws = S.keywords || [];
        const p = S.profile;
        const targets = [...(p.search_queries || []), ...(p.services || []).slice(0, 5)];
        host.innerHTML = stepper(3) + `
            <h2 class="ob-h">${kws.length ? t('الكلمات اللي بتظهر بيها في جوجل', 'Keywords you rank for on Google') : t('الكلمات اللي هنستهدفها', "Keywords we'll target")}</h2>
            <p class="ob-sub">${kws.length ? t('كلمات الحجز الأول (اللي بيكتبها السائح لما يكون عايز يحجز)، وبعدها الأكتر بحثًا.', 'Booking keywords first (what travellers type when they are ready to book), then the most searched.') : t('موقعك لسه مش ظاهر بكلمات كفاية في جوجل — دي الكلمات اللي هنبدأ نشتغل عليها.', "Your site doesn't rank for enough keywords yet — these are the ones we'll start with.")}</p>
            ${siteCard()}
            ${kws.length ? `<div class="obs-kw" id="obsKw">
                <div class="obs-kw-h"><span>${t('الكلمة', 'Keyword')}</span><span>${t('ترتيبك', 'Your rank')}</span><span>${t('بحث/شهر', 'Searches/mo')}</span></div>
                ${kws.map(k => `<div class="obs-kw-r"><span dir="auto">${esc(k.keyword)}</span><span class="${k.position && k.position <= 10 ? 'good' : k.position && k.position <= 30 ? 'mid' : 'low'}">${k.position ? '#' + k.position : '—'}</span><span>${num(k.volume)}</span></div>`).join('')}
            </div>` : `<div class="obs-chips" id="obsKw">${targets.map(k => `<span class="obs-chip" dir="auto">🔎 ${esc(k)}</span>`).join('')}</div>`}
            ${nav('market', t('التالي', 'Next'))}`;
    }

    function compMetrics(c) {
        const m = [];
        if (c.shared_keywords) m.push(`🔑 ${num(c.shared_keywords)} ${t('كلمة مشتركة معاك', 'shared keywords')}`);
        if (c.traffic) m.push(`📈 ~${num(c.traffic)} ${t('زيارة/شهر', 'visits/mo')}`);
        if (c.keywords) m.push(`🔍 ${num(c.keywords)} ${t('كلمة في جوجل', 'Google keywords')}`);
        if (c.position) m.push(`🏆 #${c.position} ${t('في جوجل', 'on Google')}${c.query ? ` <i dir="auto">«${esc(c.query)}»</i>` : ''}`);
        if (c.languages && c.languages.length > 1) m.push(`<span title="${esc(t('لغات موقعه = الأسواق اللي بيستهدفها', 'Site languages = markets they target'))}">🌍 ${c.languages.map(l => LANG_SHORT[l] || l.toUpperCase()).join(' · ')}</span>`);
        return m.length ? `<span class="obs-metrics">${m.map(x => `<em>${x}</em>`).join('')}</span>` : '';
    }

    // مقارنة بمتوسط المنافسين + توقع واقعي (أهداف، مش وعود) - من أرقام جوجل المقاسة بس
    function median(arr) { const a = arr.filter(x => x > 0).sort((x, y) => x - y); if (!a.length) return 0; const m = Math.floor(a.length / 2); return a.length % 2 ? a[m] : Math.round((a[m - 1] + a[m]) / 2); }
    // ترتيبك: اللي فوقك على طول + إنت + اللي تحتك على طول (حسب زيارات جوجل التقديرية)
    function ladder() {
        const L = S.site && S.site.ladder;
        if (!L || L.length < 2) return '';
        return `<div class="obs-ladder">
            <div class="obs-bench-h"><b>${t('ترتيبك بين منافسينك', 'Your rank among competitors')}</b>${S.site.rank ? `<span class="obs-rankpill">#${S.site.rank} ${t('من', 'of')} ${S.site.of}</span>` : ''}</div>
            ${L.map(r => `<div class="obs-lrow${r.you ? ' you' : ''}">
                <span class="n">#${r.rank}</span>
                <img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(r.domain)}&sz=64" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                <b dir="ltr">${esc(r.domain)}</b>${r.you ? `<span class="obs-you">${t('إنت', 'YOU')}</span>` : ''}
                <span class="m">~${num(r.traffic)} <small>${t('زيارة/شهر', 'visits/mo')}</small></span>
            </div>`).join('')}
            <small class="obs-src">${t('مرتّبين بزيارات جوجل التقديرية (DataForSEO)', 'Ranked by estimated Google visits (DataForSEO)')}</small>
        </div>`;
    }

    function benchmark() {
        const st = S.site;
        const comps = (S.competitors || []).filter(c => c.measured && (c.traffic || c.keywords));
        if (!st || comps.length < 2) return '';
        const mk = median(comps.map(c => c.keywords || 0));
        const mt = median(comps.map(c => c.traffic || 0));
        if (!mk && !mt) return '';
        const gap = mt && st.traffic ? mt / Math.max(1, st.traffic) : (mk ? mk / Math.max(1, st.keywords) : 0);
        const behind = gap > 1.05;
        const kwGap = Math.max(0, mk - st.keywords);
        const g = x => (x >= 10 ? Math.round(x) : Math.round(x * 10) / 10);
        const ms = behind ? [
            [t('3 شهور', '3 months'), t('نبني الأساس: مقالات على كلماتك، صفحات رحلاتك متظبطة، وأول كلمات تبدأ تطلع في أول صفحة', 'Foundation: articles on your keywords, tour pages optimised, first keywords reach page 1')],
            [t('6 شهور', '6 months'), t('هدفنا نقفل نص الفجوة: حوالي', 'Goal: close half the gap: about') + ` <b>+${num(Math.round(kwGap / 2))}</b> ` + t('كلمة في جوجل', 'Google keywords')],
            [t('12 شهر', '12 months'), t('هدفنا توصل لمتوسط منافسينك:', 'Goal: reach your competitors’ median:') + ` <b>${num(mk)}</b> ` + t('كلمة و', 'keywords and') + ` <b>~${num(mt)}</b> ` + t('زيارة شهريًا', 'visits/month')],
        ] : [
            [t('3 شهور', '3 months'), t('نحمي ترتيبك ونكبّر الكلمات اللي بتجيب حجوزات', 'Protect your rankings and grow keywords that bring bookings')],
            [t('6–12 شهر', '6–12 months'), t('نوسّع لأسواق ولغات جديدة ونسبق أقوى منافس', 'Expand to new markets and languages and overtake the strongest competitor')],
        ];
        return `<div class="obs-bench">
            <div class="obs-bench-h"><b>${t('إنت فين من منافسينك؟', 'Where you stand vs competitors')}</b><span class="obs-pill ${behind ? 'warn' : 'ok'}">${behind ? t('ورا متوسط المنافسين', 'Behind competitor median') : t('قدام متوسط المنافسين', 'Ahead of competitor median')}</span></div>
            <div class="obs-stats">
                <div><b>${num(st.keywords)}</b><span>${t('كلماتك في جوجل', 'Your Google keywords')}</span></div>
                <div><b>${num(mk)}</b><span>${t('متوسط المنافسين', 'Competitor median')}</span></div>
                <div><b>${behind ? '×' + g(gap) : '✓'}</b><span>${t('الفجوة الحالية', 'Current gap')}</span></div>
            </div>
            <div class="obs-bench-t"><b>${t('التوقع الواقعي', 'Realistic outlook')}</b><ul>${ms.map(([w, x]) => `<li><em>${w}</em><span>${x}</span></li>`).join('')}</ul>
            <small>${t('أهداف مبنية على أرقام جوجل الحقيقية لمنافسينك — النتايج بتختلف حسب المنافسة والتنفيذ.', 'Goals based on your competitors’ real Google data — results vary with competition and execution.')}</small></div>
        </div>`;
    }

    function viewCompetitors(loading) {
        const list = S.competitors;
        const mk = S.market && S.market.country ? countryName(S.market.country) : '';
        const srcNote = S.source === 'google_data'
            ? t('أرقام حقيقية من بيانات جوجل' + (mk ? ' لسوق ' + mk : '') + ' (الزيارات تقديرية). اختار لحد 3 نتابعهم.', 'Real Google data' + (mk ? ' for ' + mk : '') + ' (visits are estimates). Pick up to 3 to monitor.')
            : S.source === 'google'
                ? t('دول اللي ظاهرين في نتايج جوجل الحقيقية لخدماتك. اختار لحد 3 نتابعهم.', 'These rank in real Google results for your services. Pick up to 3 to monitor.')
                : t('اقترحناهم من نشاطك وفتحنا كل موقع واتأكدنا إنه شغال وإنه سياحي. اختار لحد 3.', 'Suggested from your business — we opened each site and confirmed it is live and in travel. Pick up to 3.');
        host.innerHTML = stepper(4) + `
            <h2 class="ob-h">${t('منافسينك', 'Your competitors')}</h2>
            <p class="ob-sub">${loading ? t('لسه بندوّر…', 'Still searching…') : list.length ? srcNote : t('مالقيناش منافسين متأكدين منهم — ضيف اللي تعرفهم (اختياري).', "We couldn't confirm competitors automatically — add the ones you know (optional).")}</p>
            ${loading ? '<div class="obs-loading"><span></span><span></span><span></span></div>' : ladder() + benchmark()}
            <div class="obs-comp">${list.map((c, i) => `
                <label class="obs-comp-row${S.picked.includes(c.domain) ? ' on' : ''}">
                    <input type="checkbox" data-obs-pick="${esc(c.domain)}"${S.picked.includes(c.domain) ? ' checked' : ''}>
                    <span class="n">${i + 1}</span>
                    <img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(c.domain)}&sz=64" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                    <span class="d"><b dir="ltr">${esc(c.domain)}</b><small>${esc(c.title || '')}</small>${compMetrics(c)}</span>
                </label>`).join('')}</div>
            <div class="obs-add"><input type="text" id="obsCompNew" maxlength="120" placeholder="${t('ضيف منافس: competitor.com', 'Add a competitor: competitor.com')}" dir="ltr"><button type="button" class="ob-btn ghost" data-obs-add-comp>+</button></div>
            <p class="obs-hint">${t('هنتابع مواقع المنافسين دول ونبلّغك بالتغييرات المهمة فيها.', "We'll monitor these websites and alert you to important changes.")}</p>
            ${nav('keywords', t('التالي', 'Next'))}`;
        const inp = document.getElementById('obsCompNew');
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addCompetitor(); } });
    }
    // ---------------------------------------------------------------- خطتك: 3 أسئلة سياحية + خطة 4 أسابيع
    const Q = {
        booking: { multi: true, title: t('عملاءك بيحجزوا إزاي دلوقتي؟', 'How do customers book you today?'), opts: [['whatsapp', t('واتساب', 'WhatsApp')], ['website', t('من موقعي', 'My website')], ['otas', t('Booking / TripAdvisor / Viator', 'Booking / TripAdvisor / Viator')], ['agents', t('مكاتب ووكلاء', 'Agents & partners')], ['social', t('فيسبوك وإنستجرام', 'Facebook & Instagram')]] },
        season: { title: t('موسمك الأقوى؟', 'Your busiest season?'), opts: [['winter', t('الشتا (أكتوبر – أبريل)', 'Winter (Oct – Apr)')], ['summer', t('الصيف', 'Summer')], ['all_year', t('طول السنة', 'All year')]] },
        goal: { title: t('أهم حاجة عايزها؟', 'What matters most?'), opts: [['direct_bookings', t('حجوزات مباشرة أكتر وعمولة أقل', 'More direct bookings, less commission')], ['google', t('أظهر أعلى في جوجل', 'Rank higher on Google')], ['reviews', t('تقييمات أكتر', 'More reviews')], ['beat_competitors', t('أسبق منافسيني', 'Beat my competitors')]] },
    };
    function viewQuestions() {
        const A = S.answers;
        host.innerHTML = stepper(5) + `
            <h2 class="ob-h">${t('3 أسئلة وخطتك تبقى جاهزة', '3 quick questions and your plan is ready')}</h2>
            <p class="ob-sub">${t('عشان نرتّب الأولويات على حسب طريقة شغلك — مش هتاخد دقيقة.', 'So we prioritise around how you work — takes under a minute.')}</p>
            ${Object.entries(Q).map(([k, q]) => `<div class="obs-box"><label class="obs-label">${esc(q.title)}${q.multi ? ` <small>${t('(اختار أكتر من واحد)', '(pick any)')}</small>` : ''}</label>
                <div class="obs-opts">${q.opts.map(([v, l]) => { const on = q.multi ? A[k].includes(v) : A[k] === v; return `<button type="button" class="obs-opt${on ? ' on' : ''}" data-obs-q="${k}" data-v="${v}" aria-pressed="${on}">${esc(l)}</button>`; }).join('')}</div></div>`).join('')}
            <div class="obs-nav"><button type="button" class="ob-btn ghost" data-obs="competitors">${t('رجوع', 'Back')}</button><button type="button" class="ob-btn primary" data-obs-plan>${t('اعرض خطتي', 'Show my plan')} →</button></div>`;
    }

    function planKeywords() {
        const real = (S.keywords || []).map(k => ({ keyword: k.keyword, volume: k.volume }));
        const extra = [...(S.profile.search_queries || [])].map(q => ({ keyword: q, volume: null }));
        const seen = new Set();
        return [...real, ...extra].filter(k => { const key = k.keyword.toLowerCase(); if (seen.has(key)) return false; seen.add(key); return true; }).slice(0, 10);
    }
    async function loadTitles() {
        const kws = planKeywords().filter(k => S.kwPick.includes(k.keyword));
        const res = await post(API + 'titles', { profile: S.profile, keywords: kws, answers: S.answers }, 60000);
        const d = (res && res.success && res.data) || {};
        S.articles = Array.isArray(d.articles) ? d.articles : [];
        if (d.trial_articles) S.trialArticles = d.trial_articles;
    }
    function weeks() {
        const a = S.articles || [];
        const A = S.answers;
        const comp = S.picked.slice(0, 2).join(t(' و', ' & '));
        const art = i => a[i] ? `${t('مقال', 'Article')}: «${esc(a[i].title)}»` : '';
        const w = [
            [t('نجهّز حسابك ونراجع موقعك', 'We set up your account and audit your site'), art(0), A.goal === 'direct_bookings' || A.booking.includes('otas') ? t('نضبط زرار الحجز والواتساب عشان الحجز يجيلك مباشرة', 'We tune your booking & WhatsApp buttons for direct bookings') : ''],
            [art(1), t('منشورات على جوجل بيزنس لخدماتك', 'Google Business posts for your services'), A.season === 'winter' ? t('نجهّز لموسم الشتا قبل ما يبدأ', 'We prepare for your winter season early') : ''],
            [art(2), t('نطلب تقييمات من عملاءك ونرد عليها', 'We request reviews from your customers and reply to them')],
            [art(3) || art(4), comp ? t('تقرير عن منافسينك', 'Competitor report') + ` (${esc(comp)})` : '', t('نقيس ترتيبك في كلماتك ونوريك التقدم', 'We measure your rankings and show progress')],
        ];
        return w.map((items, i) => `<li><b>${t('الأسبوع', 'Week')} ${i + 1}</b><span>${items.filter(Boolean).join('<br>')}</span></li>`).join('');
    }
    function viewPlan(loading) {
        const kws = planKeywords();
        if (!S.kwPick) S.kwPick = kws.slice(0, 6).map(k => k.keyword);
        const arts = S.articles || [];
        host.innerHTML = stepper(5) + `
            <h2 class="ob-h">${t('خطة أول 4 أسابيع لـ', 'Your first 4 weeks,')} ${esc(S.profile.business_name || '')}</h2>
            <p class="ob-sub">${t('راجعها وعدّل اللي عايزه — هنبدأ تنفيذها أول ما تعمل حسابك.', 'Review and tweak it — we start as soon as you create your account.')}</p>
            <div class="obs-box"><label class="obs-label">${t('الكلمات اللي هنستهدفها', 'Keywords we’ll target')} <small>${t('(دوس عشان تشيل أو ترجّع)', '(tap to toggle)')}</small></label>
                <div class="obs-opts">${kws.map(k => { const on = S.kwPick.includes(k.keyword); return `<button type="button" class="obs-opt${on ? ' on' : ''}" data-obs-kw="${esc(k.keyword)}" dir="auto">${on ? '✓ ' : ''}${esc(k.keyword)}${k.volume ? ` <i>${num(k.volume)}/${t('شهر', 'mo')}</i>` : ''}</button>`; }).join('') || `<span class="obs-empty">${t('هنحددها من خدماتك بعد التسجيل', "We'll set these from your services after sign-up")}</span>`}</div></div>
            <div class="obs-box"><label class="obs-label">${t('أول مقالات على موقعك', 'Your first articles')} <small>${t('(دوس على العنوان عشان تعدّله)', '(tap a title to edit)')}</small></label>
                ${loading ? '<div class="obs-loading"><span></span><span></span><span></span></div>' : arts.length ? `<ol class="obs-arts">${arts.map((a, i) => `<li><input type="text" value="${esc(a.title)}" data-obs-art="${i}" maxlength="150" dir="auto"><button type="button" data-obs-del-art="${i}" aria-label="${t('حذف', 'Remove')}">×</button>${a.keyword ? `<small dir="auto">🔎 ${esc(a.keyword)}</small>` : ''}</li>`).join('')}</ol>
                <p class="obs-hint">${t('خلال التجربة المجانية بنكتبلك أول ' + S.trialArticles + ' مقال، والباقي بيتكتب لوحده أول ما تشترك.', 'During the free trial we write your first ' + S.trialArticles + ' articles; the rest start automatically when you subscribe.')}</p>`
                : `<span class="obs-empty">${t('هنقترح العناوين بعد التسجيل.', "We'll suggest titles after sign-up.")}</span>`}
            </div>
            <div class="obs-box"><label class="obs-label">${t('هنعمل إيه كل أسبوع', 'What happens each week')}</label><ol class="obs-weeks">${weeks()}</ol></div>
            ${nav('questions', REG ? t('تمام، كمّل', 'Looks good, continue') + ' →' : '🚀 ' + t('جهّز حسابي', 'Set up my account'))}`;
        host.querySelectorAll('[data-obs-art]').forEach(inp => inp.addEventListener('input', () => { S.articles[+inp.getAttribute('data-obs-art')].title = inp.value; }));
    }
    async function goPlan() {
        S.kwPick = S.kwPick || null;
        viewPlan(true);
        if (S.articles === null) { await loadTitles(); }
        viewPlan(false);
    }

    function addCompetitor() {
        const v = document.getElementById('obsCompNew').value.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').split('/')[0];
        if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(v)) return;
        if (!S.competitors.some(c => c.domain === v)) S.competitors.push({ domain: v, title: t('أضفته إنت', 'Added by you'), position: null });
        if (!S.picked.includes(v)) { if (S.picked.length >= MAX_COMP) S.picked.shift(); S.picked.push(v); }
        viewCompetitors(false);
    }

    // ---------------------------------------------------------------- المنطق
    function emptyProfile(fb) {
        return Object.assign({ business_name: '', description: '', industry: 'tours', services: [], site_language: ar ? 'ar' : 'en', target_countries: [], currency: 'USD', location: '', target_customers: '', search_queries: [], competitor_candidates: [] }, fb || {});
    }

    async function analyze(retryUrl) {
        const raw = (typeof retryUrl === 'string' && retryUrl) || (document.getElementById('obsUrl') || {}).value || '';
        const url = raw.trim();
        if (!/^(https?:\/\/)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(\/\S*)?$/i.test(url)) {
            const inp = document.getElementById('obsUrl');
            if (!inp) return;
            inp.classList.add('obs-bad'); inp.focus();
            return;
        }
        S.url = /^https?:\/\//i.test(url) ? url : 'https://' + url;
        const main = document.getElementById('obMainUrl');
        if (main) main.value = S.url;
        viewWorking(0);
        const slow = setTimeout(() => viewWorking(1), 3500);
        const res = await post(API + 'analyze', { url: S.url }, 100000);
        clearTimeout(slow);
        const d = (res && res.success && res.data) || {};
        if (d.url) S.url = d.url;
        if (d.ok && d.profile) {
            S.profile = emptyProfile(d.profile);
            S.analyzeFailed = false;
            const n = S.profile.services.length;
            viewWorking(2, `${t('لقينا', 'Found')} <b>${esc(S.profile.business_name || '')}</b>${n ? ' · ' + n + ' ' + t('خدمة', n === 1 ? 'service' : 'services') : ''}`);
            await findCompetitors();
        } else {
            S.profile = emptyProfile(d.fallback || {});
            S.analyzeFailed = true;
            // رسالة السيرفر (زي «جرّبت كتير الساعة دي») أوضح من «ماقدرناش نقرا موقعك»
            S.analyzeMsg = (res && res.success === false && (res.error || res.message)) ? String(res.error || res.message) : '';
            // سبب الفشل بالظبط (عشان العميل يعرف يعمل إيه، وإحنا نعرف نصلّح إيه)
            S.analyzeReason = S.analyzeMsg ? 'refused' : (res && res.success ? (d.reason || 'error') : 'network');
            S.competitors = []; S.picked = [];
        }
        viewBusiness();
    }

    async function findCompetitors() {
        const p = S.profile;
        const res = await post(API + 'competitors', { url: S.url, queries: p.search_queries, candidates: p.competitor_candidates, language: p.site_language || 'en', countries: p.target_countries }, 90000);
        const d = (res && res.success && res.data) || {};
        S.competitors = Array.isArray(d.competitors) ? d.competitors : [];
        S.source = d.source || '';
        S.site = d.site || null;
        S.keywords = Array.isArray(d.keywords) ? d.keywords : [];
        S.market = d.market || null;
        S.picked = S.competitors.slice(0, MAX_COMP).map(c => c.domain);
    }

    function payload() {
        const p = S.profile;
        return {
            business_name: p.business_name, main_url: S.url, industry: p.industry,
            target_country: p.target_countries.join(','), target_language: p.site_language || 'en',
            selling_currency: p.currency || 'USD', target_customers: p.target_customers,
            main_services: (p.services.join(', ') + (p.description ? '\n\n' + p.description : '')).slice(0, 2000),
            growth_plan: { answers: Object.assign({}, S.answers, { platform: (p.platform || {}).key || null, booking_engines: (p.platform || {}).booking || [] }), keywords: planKeywords().filter(k => (S.kwPick || []).includes(k.keyword)), articles: (S.articles || []).filter(a => (a.title || '').trim()) },
            competitors: S.picked.slice(0, MAX_COMP).map(dom => { const c = S.competitors.find(x => x.domain === dom) || {}; return { name: (c.title || dom).split(/\s[|\-–—:]\s/)[0].slice(0, 80), domain: dom }; }),
        };
    }

    // بيملا حقول الـWizard القديم وبيبدأ التجهيز (نفس obSubmit اللي كان شغال)
    function fillWizardAndSubmit(d) {
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v == null ? '' : v; };
        set('obBusinessName', d.business_name);
        set('obMainUrl', d.main_url);
        const ind = document.getElementById('obIndustry');
        if (ind && [...ind.options].some(o => o.value === d.industry)) ind.value = d.industry;
        set('obTargetCountry', d.target_country);
        const al = document.getElementById('obAudienceLanguage');
        if (al) al.value = [...al.options].some(o => o.value === d.target_language) ? d.target_language : 'en';
        set('obSellingCurrency', d.selling_currency || 'USD');
        set('obTargetCustomers', d.target_customers);
        set('obMainServices', d.main_services);
        const rows = document.getElementById('obCompetitorRows');
        if (rows) {
            rows.innerHTML = '';
            (d.competitors || []).slice(0, MAX_COMP).forEach(c => {
                if (typeof window.obAddCompetitor === 'function') window.obAddCompetitor();
                const row = rows.querySelector('.ob-competitor-row:last-child');
                if (row) { row.querySelector('.ob-comp-name').value = c.name || c.domain; row.querySelector('.ob-comp-domain').value = c.domain; }
            });
        }
        window.OB_GROWTH_PLAN = d.growth_plan || null;
        card.classList.remove('smart-on');
        host.innerHTML = '';
        window.obSubmit();
    }

    async function finish() {
        const p = S.profile;
        if (!p.business_name) { viewBusiness(); const n = document.getElementById('obsName'); n.classList.add('obs-bad'); n.focus(); return; }
        const d = payload();
        if (!REG) { fillWizardAndSubmit(d); return; }
        const btn = host.querySelector('[data-obs-next]');
        if (btn) btn.disabled = true;
        await post(API + 'save', { url: S.url, payload: d }, 20000);
        showAccountForm(d);
    }

    // صفحة التسجيل: آخر خطوة = الحساب (الاسم مكتوب جاهز) والإعداد بيكمّل لوحده بعدها
    function showAccountForm(d) {
        card.classList.remove('smart-on');
        host.innerHTML = '';
        const cn = document.getElementById('company_name');
        if (cn && d && d.business_name && !cn.value) cn.value = d.business_name;
        const h1 = card.querySelector('h1');
        if (d && h1) {
            h1.textContent = t('آخر خطوة: اعمل حسابك', 'Last step: create your account');
            const sum = document.createElement('div');
            sum.className = 'obs-summary';
            sum.innerHTML = `<b>✓ ${t('خطتك جاهزة', 'Your plan is ready')}</b><span dir="ltr">${esc(S.url.replace(/^https?:\/\//, ''))}</span><span>${S.profile.services.length} ${t('خدمة', 'services')} · ${S.profile.target_countries.length} ${t('دولة', 'countries')} · ${S.picked.length} ${t('منافسين', 'competitors')}</span><small>${t('اعمل حسابك وهنكمّل تجهيز كل حاجة لوحدنا.', "Create your account and we'll finish setting everything up.")}</small>`;
            h1.insertAdjacentElement('afterend', sum);
        }
        const em = document.getElementById('email');
        if (em) setTimeout(() => em.focus(), 60);
    }

    function manual() {
        if (REG) {
            // «معنديش موقع»: بعد التسجيل على منشئ المواقع على طول، من غير ما المعالج يسأله عن رابط موقعه تاني
            window.OB_NO_SITE = true;
            showAccountForm(null);
            const h1 = card.querySelector('h1');
            if (h1) h1.textContent = t('اعمل حسابك ونبنيلك موقع حجز', "Create your account and we'll build your booking site");
            return;
        }
        card.classList.remove('smart-on');
        host.innerHTML = '';
        if (S.url) { const m = document.getElementById('obMainUrl'); if (m && !m.value) m.value = S.url; }
    }

    host.addEventListener('click', function (e) {
        if (e.target.closest('[data-obs-retry]')) { analyze(S.url); return; }
        const go = e.target.closest('[data-obs]');
        if (go) {
            const v = go.getAttribute('data-obs');
            if (v === 'url') viewUrl(); else if (v === 'business') { saveMarket(); viewBusiness(); } else if (v === 'market') viewMarket(); else if (v === 'keywords') viewKeywords(); else if (v === 'competitors') viewCompetitors(false); else if (v === 'questions') viewQuestions();
            return;
        }
        if (e.target.closest('[data-obs-analyze]')) { analyze(); return; }
        const qo = e.target.closest('[data-obs-q]');
        if (qo) {
            const k = qo.getAttribute('data-obs-q'), v = qo.getAttribute('data-v');
            if (Q[k].multi) S.answers[k] = S.answers[k].includes(v) ? S.answers[k].filter(x => x !== v) : [...S.answers[k], v];
            else S.answers[k] = S.answers[k] === v ? null : v;
            viewQuestions(); return;
        }
        if (e.target.closest('[data-obs-plan]')) { goPlan(); return; }
        const kb = e.target.closest('[data-obs-kw]');
        if (kb) { const k = kb.getAttribute('data-obs-kw'); S.kwPick = S.kwPick.includes(k) ? S.kwPick.filter(x => x !== k) : [...S.kwPick, k]; viewPlan(false); return; }
        const da = e.target.closest('[data-obs-del-art]');
        if (da) { S.articles.splice(+da.getAttribute('data-obs-del-art'), 1); viewPlan(false); return; }
        if (e.target.closest('[data-obs-manual]')) { manual(); return; }
        if (e.target.closest('[data-obs-add-service]')) { addService(); return; }
        if (e.target.closest('[data-obs-add-comp]')) { addCompetitor(); return; }
        const ds = e.target.closest('[data-obs-del-service]');
        if (ds) { saveBusiness(); S.profile.services.splice(Number(ds.getAttribute('data-obs-del-service')), 1); viewBusiness(); return; }
        const dc = e.target.closest('[data-obs-del-country]');
        if (dc) { saveMarket(); S.profile.target_countries = S.profile.target_countries.filter(c => c !== dc.getAttribute('data-obs-del-country')); viewMarket(); return; }
        if (e.target.closest('[data-obs-next]')) {
            if (document.getElementById('obsName')) {
                saveBusiness();
                if (!S.profile.business_name) { const n = document.getElementById('obsName'); n.classList.add('obs-bad'); n.focus(); return; }
                viewMarket();
            } else if (document.getElementById('obsLang')) {
                saveMarket();
                if (!S.profile.target_countries.length) { document.getElementById('obsCountryNew').classList.add('obs-bad'); return; }
                viewKeywords();
            } else if (host.querySelector('.obs-comp')) {
                viewQuestions();
            } else if (document.getElementById('obsKw')) {
                if (!S.competitors.length && !S.analyzeFailed && S.profile.competitor_candidates.length && !S.searched) {
                    S.searched = true; viewCompetitors(true); findCompetitors().then(() => viewCompetitors(false));
                } else viewCompetitors(false);
            } else finish();
        }
    });
    host.addEventListener('change', function (e) {
        const pk = e.target.closest('[data-obs-pick]');
        if (!pk) return;
        const dom = pk.getAttribute('data-obs-pick');
        if (pk.checked) {
            if (S.picked.length >= MAX_COMP) { pk.checked = false; return; }
            S.picked.push(dom);
        } else S.picked = S.picked.filter(x => x !== dom);
        pk.closest('.obs-comp-row').classList.toggle('on', pk.checked);
    });

    // البداية
    if (!REG && window.OB_SMART_READY && window.OB_SMART_READY.main_url) {
        // جاي من التسجيل الذكي: البيانات اتراجعت قبل الحساب، فالتجهيز بيبدأ على طول
        fillWizardAndSubmit(window.OB_SMART_READY);
        return;
    }
    const siteParam = (location.search.match(/[?&]site=([^&]+)/) || [])[1];
    const pre = REG ? (siteParam ? decodeURIComponent(siteParam) : '') : ((document.getElementById('obMainUrl') || {}).value || '');
    S.url = pre;
    card.classList.add('smart-on');
    viewUrl();
    if (pre && siteParam) analyze();
})();
