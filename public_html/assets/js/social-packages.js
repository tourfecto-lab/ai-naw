/**
 * Tourfecto - باقات السوشيال ميديا (صفحة الاشتراكات /subscription + أي صفحة فيها #socialPackages)
 * الباقات الجاهزة، «فصّل باقتك»، الإضافات، والتجديد - كله من المحفظة.
 */
(function () {
    'use strict';
    const P = window.Panel;
    if (!P || !document.getElementById('socialPackages')) return;
    const AR = (document.documentElement.lang || 'ar').slice(0, 2) === 'ar';
    const T = (a, e) => (AR ? a : e);
    const $ = id => document.getElementById(id);
    const esc = P.esc || (s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
    const fetchJSON = P.fetchJSON;
    const jsonPost = (url, body) => fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    const nf = n => Number(n || 0).toLocaleString(AR ? 'ar-EG' : 'en-GB');
    const spLocal = s => (P.formatDate ? P.formatDate(s) : (s || '-'));
    const IC = (n, sz) => (window.SPI ? window.SPI.i(n, sz || 15) : '');
    const BR = (p, sz) => (window.SPI ? window.SPI.brand(p, sz || 16) : '');
    const PN = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube', google_business: 'Google Business', threads: 'Threads', linkedin: 'LinkedIn' };
    const PF = new Proxy({}, { get: (o, p) => BR(p) });
    // ================= الباقة =================
    const LIM_ICON = { accounts: '' + IC('link') + '', posts: '' + IC('calendar') + '', monthly_plans: '' + IC('planCheck') + '', competitors: '' + IC('target') + '', ai_captions: '' + IC('sparkles') + '' };
    let PKG = null, pkCycle = 'monthly';
    const lab = k => (PKG && PKG.labels && PKG.labels[k]) ? (AR ? PKG.labels[k][0] : PKG.labels[k][1]) : k;
    const limTxt = v => (v < 0 ? '∞' : nf(v));
    const reqKey = () => 'pk' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    async function fetchPackage() {
        const r = await fetchJSON('/api/social/package');
        if (r.success) { PKG = r.data; }
        return r;
    }
    function meter(k) {
        const lim = PKG.limits[k], used = PKG.usage[k] || 0;
        const pct = lim > 0 ? Math.min(100, Math.round(used * 100 / lim)) : 0;
        const cls = lim === 0 ? 'off' : (pct >= 100 ? 'full' : (pct >= 80 ? 'warn' : ''));
        return '<div class="pk-meter ' + cls + '"><div class="pk-m-h"><span>' + LIM_ICON[k] + ' ' + esc(lab(k)) + '</span><b>' + (lim === 0 ? T('مش مشمول', 'Not included') : nf(used) + ' / ' + limTxt(lim)) + '</b></div><div class="pk-bar"><i style="width:' + (lim < 0 ? 4 : pct) + '%"></i></div></div>';
    }
    function tierAction(t) {
        const cur = PKG.tier, curRank = (PKG.catalog.find(x => x.key === cur) || {}).rank || 0;
        const planRank = PKG.plan_tier ? ((PKG.catalog.find(x => x.key === PKG.plan_tier) || {}).rank || 0) : -1;
        const sub = PKG.subscription && PKG.subscription.status === 'active' ? PKG.subscription : null;
        if (t.key === 'free') return cur === 'free' ? '<span class="pill">' + T('باقتك الحالية', 'Current plan') + '</span>' : '';
        if (!t.for_sale) return '<span class="p-cell-muted sp-small">' + T('مش متاحة للبيع دلوقتي', 'Not available right now') + '</span>';
        if (t.rank <= planRank) return '<span class="pill green">' + T('مشمولة في باقة المنصة ✓', 'Included in your platform plan ✓') + '</span>';
        if (sub && sub.tier === t.key && sub.cycle === pkCycle && sub.auto_renew && !sub.pending_tier) return '<span class="pill green">' + T('باقتك الحالية', 'Current plan') + '</span>';
        if (!PKG.can_buy) return '<span class="p-cell-muted sp-small">' + T('الاشتراك من صلاحيات مدير الحساب', 'Only the account manager can subscribe') + '</span>';
        let label = T('اشترك', 'Subscribe');
        if (sub && sub.tier === t.key) label = sub.cycle === pkCycle ? T('رجّع التجديد', 'Resume renewal') : (pkCycle === 'yearly' ? T('حوّل لسنوي عند التجديد', 'Switch to yearly at renewal') : T('حوّل لشهري عند التجديد', 'Switch to monthly at renewal'));
        else if (sub && t.rank > curRank) label = T('رقّي دلوقتي (بفرق السعر)', 'Upgrade now (prorated)');
        else if (sub && t.rank < curRank) label = T('انزل لها عند التجديد', 'Downgrade at renewal');
        return '<button type="button" class="p-btn ' + (t.key === 'pro' ? 'primary' : 'outline') + ' sm" data-buy="' + esc(t.key) + '">' + esc(label) + '</button>';
    }
    function renderPackage() {
        const root = $('socialPackages'); if (!PKG) return;
        const src = { plan: T('من باقة المنصة', 'from your platform plan'), social: T('اشتراك سوشيال', 'social subscription'), free: T('الباقة المجانية', 'free tier') }[PKG.source] || '';
        const sub = PKG.subscription;
        let subTxt = '';
        if (sub && sub.status === 'active') {
            const end = spLocal(sub.period_end + ' UTC');
            subTxt = '<div class="sp-small">' + (sub.auto_renew ? T('بتتجدد تلقائي من المحفظة يوم ', 'Renews automatically from your wallet on ') : T('التجديد التلقائي مقفول - الباقة شغالة لحد ', 'Auto-renew is off — active until ')) + '<b>' + esc(end) + '</b>'
                + (sub.pending_tier ? ' · ' + T('عند التجديد هتتحول لـ ', 'At renewal it switches to ') + '<b>' + esc((PKG.catalog.find(x => x.key === sub.pending_tier) || {}).name || sub.pending_tier) + '</b> (' + (sub.pending_cycle === 'yearly' ? T('سنوي', 'yearly') : T('شهري', 'monthly')) + ')' : '')
                + (PKG.can_buy ? ' <button type="button" class="p-btn ghost xs" id="pkRenew">' + (sub.auto_renew ? T('إلغاء التجديد', 'Cancel renewal') : T('شغّل التجديد', 'Turn on renewal')) + '</button>' : '') + '</div>';
        }
        const lockedN = Object.keys(PKG.locked || {}).length;
        const head = '<div class="pk-title"><h2>' + IC('layers', 20) + ' ' + T('باقات السوشيال ميديا', 'Social media plans') + '</h2><a class="p-btn ghost sm" href="/social">' + T('افتح السوشيال', 'Open social hub') + ' ' + IC('external', 13) + '</a></div><div class="p-card pk-head"><div><div class="p-cell-muted sp-small">' + T('باقتك في السوشيال', 'Your social plan') + '</div><h3 class="pk-tier">' + IC('gem') + ' ' + esc(AR ? PKG.tier_name : (PKG.tier_name_en || PKG.tier_name)) + ' <small>· ' + esc(src) + '</small></h3>' + subTxt
            + (PKG.balance ? '<div class="sp-small p-cell-muted">' + T('رصيد المحفظة: ', 'Wallet balance: ') + '<b>' + esc(PKG.balance.label) + '</b> · <a href="/subscription#wallet">' + T('اشحن', 'Top up') + '</a></div>' : '') + '</div>'
            + '<div class="pk-meters">' + ['posts', 'accounts', 'ai_captions', 'monthly_plans', 'competitors'].map(meter).join('') + '</div></div>'
            + (lockedN ? '<div class="alert alert-warning sp-small">' + IC('lock') + ' ' + nf(lockedN) + T(' حساب مربوط مقفول للنشر بسبب حدود الباقة (الحسابات مش بتتمسح - بترجع تشتغل أول ما ترقّي أو تشتري إضافة).', ' connected account(s) locked for publishing by your plan limits (nothing is deleted — they unlock when you upgrade or buy an add-on).') + '</div>' : '')
            + (!PKG.ready ? '<div class="alert alert-warning sp-small">' + T('شغّل ملف قاعدة البيانات 2026_10_22_000001_social_packages.sql عشان الاشتراك والإضافات يشتغلوا.', 'Run the 2026_10_22_000001_social_packages.sql migration to enable subscriptions and add-ons.') + '</div>' : '');
        const feats = Object.keys(PKG.labels).filter(k => !(k in LIM_ICON));
        const cards = PKG.catalog.map(t => {
            const pr = pkCycle === 'yearly' ? t.yearly : t.monthly;
            const save = t.monthly.usd > 0 ? Math.round(100 - t.yearly.usd * 100 / (t.monthly.usd * 12)) : 0;
            return '<div class="pk-card' + (t.key === PKG.tier ? ' current' : '') + (t.key === 'pro' ? ' best' : '') + '">' + (t.key === 'pro' ? '<div class="pk-ribbon">' + T('الأكثر طلبًا', 'Most popular') + '</div>' : '')
                + '<h4>' + esc(t.name) + '</h4><div class="pk-price">' + (t.key === 'free' ? T('مجانًا', 'Free') : esc(pr.label) + '<small>/' + (pkCycle === 'yearly' ? T('سنة', 'year') : T('شهر', 'month')) + '</small>') + '</div>'
                + (pkCycle === 'yearly' && save > 0 ? '<div class="pk-save">' + T('وفّر ', 'Save ') + save + '%</div>' : '<div class="pk-save">&nbsp;</div>')
                + '<ul class="pk-list">' + ['posts', 'accounts', 'ai_captions', 'monthly_plans', 'competitors'].map(k => '<li class="' + (t.limits[k] === 0 ? 'no' : '') + '">' + LIM_ICON[k] + ' <b>' + (t.limits[k] === 0 ? '—' : limTxt(t.limits[k])) + '</b> ' + esc(lab(k)) + '</li>').join('')
                + '</ul><details class="pk-more"' + (window.innerWidth > 700 ? ' open' : '') + '><summary>' + T('المميزات', 'Features') + ' (' + feats.filter(k => t.features[k]).length + '/' + feats.length + ')</summary><ul class="pk-list">' + feats.map(k => '<li class="' + (t.features[k] ? '' : 'no') + '">' + (t.features[k] ? IC('check', 14) : IC('x', 14)) + ' ' + esc(lab(k)) + '</li>').join('') + '</ul></details>'
                + '<div class="pk-plats">' + t.platforms.map(p => '<span title="' + esc(PN[p] || p) + '">' + (PF[p] || '•') + '</span>').join('') + '</div>'
                + '<div class="pk-act">' + tierAction(t) + '</div></div>';
        }).join('');
        const map = PKG.plan_map || {};
        const mapTxt = Object.keys(map).length ? '<p class="p-cell-muted sp-small">' + T('مشترك في باقة المنصة؟ السوشيال مشمول: ', 'On a platform plan? Social is included: ') + Object.entries(map).map(([p, t]) => esc((PKG.plan_names || {})[p] || p) + ' ← ' + esc((PKG.catalog.find(x => x.key === t) || {}).name || t)).join(' · ') + ' · <a href="/subscription">' + T('باقات المنصة', 'Platform plans') + '</a></p>' : '';
        const addons = (PKG.addons || []).length ? '<div class="p-card"><div class="p-card-head"><h3>' + IC('plus') + ' ' + T('إضافات (صالحة 30 يوم)', 'Add-ons (valid 30 days)') + '</h3></div><div class="pk-addons">'
            + PKG.addons.map(a => '<div class="pk-addon"><span class="pk-ai">' + a.icon + '</span><div><b>+' + nf(a.qty) + ' ' + esc(lab(a.kind)) + '</b><div class="p-cell-muted sp-small">' + esc(a.price.label) + '</div></div>' + (PKG.can_buy ? '<button type="button" class="p-btn outline xs" data-addon="' + esc(a.key) + '">' + T('اشتري', 'Buy') + '</button>' : '') + '</div>').join('') + '</div>'
            + ((PKG.addons_active || []).length ? '<div class="sp-small p-cell-muted">' + T('شغالة دلوقتي: ', 'Active: ') + PKG.addons_active.map(a => '+' + nf(a.qty) + ' ' + esc(lab(a.kind)) + ' (' + T('لحد ', 'until ') + esc(spLocal(a.expires_at + ' UTC')) + ')').join(' · ') + '</div>' : '') + '</div>' : '';
        root.innerHTML = head
            + '<div class="p-card"><div class="p-card-head"><h3>' + IC('layers') + ' ' + T('باقات السوشيال', 'Social plans') + '</h3><div class="sp-seg pk-cycle"><label><input type="radio" name="pkCycle" value="monthly"' + (pkCycle === 'monthly' ? ' checked' : '') + '> ' + T('شهري', 'Monthly') + '</label><label><input type="radio" name="pkCycle" value="yearly"' + (pkCycle === 'yearly' ? ' checked' : '') + '> ' + T('سنوي (شهرين ببلاش)', 'Yearly (2 months free)') + '</label></div></div>'
            + '<div class="pk-grid">' + cards + '</div>' + mapTxt
            + '<p class="p-cell-muted sp-small">' + T('الدفع من رصيد المحفظة. الترقية فورية وبتدفع الفرق بس (الباقي من مدتك بيتحسب رصيد)، والتخفيض بيتطبق عند التجديد. كل منصة في المنشور بتتحسب منشور. كابشن AI بيتحسب من باقة المنصة الأول لو عندك.', 'Paid from your wallet balance. Upgrades are instant and you only pay the difference (remaining time is credited); downgrades apply at renewal. Each platform in a post counts as one post. AI captions use your platform plan quota first if you have one.') + '</p></div>'
            + (PKG.builder ? '<div class="p-card pk-builder" id="pkBuilder"></div>' : '')
            + addons;
        if (PKG.builder) renderBuilder();
        root.querySelectorAll('input[name=pkCycle]').forEach(r => r.addEventListener('change', () => { pkCycle = r.value; renderPackage(); }));
        root.querySelectorAll('[data-buy]').forEach(b => b.addEventListener('click', () => buyTier(b.dataset.buy, b)));
        root.querySelectorAll('[data-addon]').forEach(b => b.addEventListener('click', () => buyAddon(b.dataset.addon, b)));
        const rn = $('pkRenew'); if (rn) rn.addEventListener('click', toggleRenew);
    }
    // ---- فصّل باقتك
    let bld = null;
    const usd = n => '$' + (Math.round(n * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 });
    function bldInit() {
        const c = PKG.custom || (PKG.subscription && PKG.subscription.custom) || null;
        const en = PKG.platforms_enabled || [];
        bld = c ? JSON.parse(JSON.stringify(c)) : { channels: 2, platforms: en.filter(p => p === 'facebook' || p === 'instagram'), features: {}, posts_blocks: 0, ai_blocks: 0, competitor_blocks: 0, monthly_plans: 0 };
        bld.platforms = (bld.platforms || []).filter(p => en.includes(p));
        bld.features = bld.features || {};
    }
    function bldCalc() {
        const B = PKG.builder;
        if (bld.features.auto_private) bld.features.private_reply = true;
        bld.channels = Math.max(bld.channels, bld.platforms.length || 1, 1);
        bld.channels = Math.min(bld.channels, B.max_channels);
        let m = bld.channels * B.channel.usd + bld.posts_blocks * B.posts_block.price.usd + bld.ai_blocks * B.ai_block.price.usd + bld.competitor_blocks * B.competitors_block.price.usd + bld.monthly_plans * B.monthly_plan.usd;
        Object.keys(B.features).forEach(k => { if (bld.features[k]) m += B.features[k].usd; });
        return {
            monthly: m, yearly: m * B.yearly_months,
            posts: bld.channels * B.posts_per_channel + bld.posts_blocks * B.posts_block.qty,
            ai: bld.channels * B.ai_per_channel + bld.ai_blocks * B.ai_block.qty,
            competitors: bld.competitor_blocks * B.competitors_block.qty
        };
    }
    function renderBuilder() {
        const box = $('pkBuilder'); if (!box) return;
        if (!bld) bldInit();
        const B = PKG.builder, q = bldCalc();
        const step = (key, val, unit, price) => '<div class="pk-step"><div><b>' + esc(unit) + '</b><div class="p-cell-muted sp-small">' + esc(price) + '</div></div><div class="pk-stepper"><button type="button" class="p-btn ghost xs" data-st="' + key + '" data-d="-1" aria-label="-">−</button><span>' + nf(val) + '</span><button type="button" class="p-btn ghost xs" data-st="' + key + '" data-d="1" aria-label="+">+</button></div></div>';
        const sub = PKG.subscription && PKG.subscription.status === 'active' && PKG.subscription.tier === 'custom';
        box.innerHTML = '<div class="p-card-head"><h3>' + IC('layers') + ' ' + T('فصّل باقتك', 'Build your own plan') + '</h3><span class="p-card-sub">' + T('اختار المنصات والمميزات اللي محتاجها بس، وادفع على قدها', 'Pick only the platforms and features you need, and pay for just that') + '</span></div>'
            + '<div class="pk-b-grid"><div>'
            + '<h5>1) ' + T('المنصات اللي بتستخدمها', 'Platforms you use') + '</h5><div class="sp-chips">' + (PKG.platforms_enabled || []).map(p => '<label class="sp-tchip"><input type="checkbox" data-bp="' + p + '"' + (bld.platforms.includes(p) ? ' checked' : '') + '> ' + (PF[p] || '') + ' ' + esc(PN[p] || p) + '</label>').join('') + '</div>'
            + '<h5>2) ' + T('عدد الحسابات', 'Number of accounts') + '</h5>'
            + step('channels', bld.channels, T('حساب', 'accounts'), B.channel.label + T(' للحساب · معاه ', ' per account · includes ') + B.posts_per_channel + T(' منشور و', ' posts & ') + B.ai_per_channel + T(' كابشن AI', ' AI captions'))
            + '<h5>3) ' + T('المميزات', 'Features') + '</h5><div class="pk-feats">' + Object.keys(B.features).map(k => '<label class="sp-check"><input type="checkbox" data-bf="' + k + '"' + (bld.features[k] ? ' checked' : '') + '> ' + esc(lab(k)) + ' <span class="p-cell-muted">+' + esc(B.features[k].label) + '</span></label>').join('') + '</div>'
            + '<h5>4) ' + T('زيادات (اختياري)', 'Extras (optional)') + '</h5>'
            + step('posts_blocks', bld.posts_blocks * B.posts_block.qty, T('منشور زيادة', 'extra posts'), '+' + B.posts_block.qty + ' = ' + B.posts_block.price.label)
            + step('ai_blocks', bld.ai_blocks * B.ai_block.qty, T('كابشن AI زيادة', 'extra AI captions'), '+' + B.ai_block.qty + ' = ' + B.ai_block.price.label)
            + step('competitor_blocks', bld.competitor_blocks * B.competitors_block.qty, T('منافسين على إنستجرام', 'Instagram competitors'), '+' + B.competitors_block.qty + ' = ' + B.competitors_block.price.label)
            + step('monthly_plans', bld.monthly_plans, T('خطة شهرية بالذكاء الصناعي', 'AI monthly plans'), B.monthly_plan.label + T(' للخطة', ' per plan'))
            + '</div><aside class="pk-b-sum"><h5>' + T('باقتك', 'Your plan') + '</h5><ul class="pk-list">'
            + '<li>' + IC('link') + ' <b>' + nf(bld.channels) + '</b> ' + esc(lab('accounts')) + ' — ' + bld.platforms.map(p => PF[p] || '').join(' ') + '</li>'
            + '<li>' + IC('calendar') + ' <b>' + nf(q.posts) + '</b> ' + esc(lab('posts')) + '</li><li>' + IC('sparkles') + ' <b>' + nf(q.ai) + '</b> ' + esc(lab('ai_captions')) + '</li>'
            + (bld.monthly_plans ? '<li>' + IC('planCheck') + ' <b>' + nf(bld.monthly_plans) + '</b> ' + esc(lab('monthly_plans')) + '</li>' : '')
            + (q.competitors ? '<li>' + IC('target') + ' <b>' + nf(q.competitors) + '</b> ' + esc(lab('competitors')) + '</li>' : '')
            + Object.keys(B.features).filter(k => bld.features[k]).map(k => '<li>✓ ' + esc(lab(k)) + '</li>').join('') + '</ul>'
            + '<div class="pk-price">' + usd(pkCycle === 'yearly' ? q.yearly : q.monthly) + '<small>/' + (pkCycle === 'yearly' ? T('سنة', 'year') : T('شهر', 'month')) + '</small></div>'
            + (pkCycle === 'yearly' ? '<div class="pk-save">' + T('بدل ', 'instead of ') + usd(q.monthly * 12) + '</div>' : '<div class="pk-save">' + T('السنوي: ', 'Yearly: ') + usd(q.yearly) + '</div>')
            + (PKG.can_buy ? '<button type="button" class="p-btn primary" id="pkBuildBuy"' + (bld.platforms.length ? '' : ' disabled') + '>' + (sub ? T('حدّث باقتي', 'Update my plan') : T('اشترك في الباقة دي', 'Subscribe to this plan')) + '</button>' : '<span class="p-cell-muted sp-small">' + T('الاشتراك من صلاحيات مدير الحساب', 'Only the account manager can subscribe') + '</span>')
            + '<p class="p-cell-muted sp-small">' + T('الباقات الجاهزة فوق أوفر لو محتاج مميزات كتير.', 'The ready-made plans above are cheaper if you need many features.') + '</p></aside></div>';
        box.querySelectorAll('[data-bp]').forEach(cb => cb.addEventListener('change', () => { bld.platforms = [...box.querySelectorAll('[data-bp]:checked')].map(x => x.dataset.bp); renderBuilder(); }));
        box.querySelectorAll('[data-bf]').forEach(cb => cb.addEventListener('change', () => { bld.features[cb.dataset.bf] = cb.checked; if (cb.dataset.bf === 'private_reply' && !cb.checked) bld.features.auto_private = false; renderBuilder(); }));
        box.querySelectorAll('[data-st]').forEach(b => b.addEventListener('click', () => { const k = b.dataset.st; bld[k] = Math.max(k === 'channels' ? 1 : 0, (bld[k] || 0) + Number(b.dataset.d)); renderBuilder(); }));
        const buy = $('pkBuildBuy'); if (buy) buy.addEventListener('click', () => buyCustom(buy));
    }
    async function buyCustom(btn) {
        btn.disabled = true;
        const q = await jsonPost('/api/social/package/quote', { custom: bld });
        if (!q.success) { btn.disabled = false; P.toast(q.error || T('تعذر', 'Failed'), 'error'); return; }
        const pr = pkCycle === 'yearly' ? q.data.yearly_label : q.data.monthly_label;
        if (!confirm(T('تأكيد الباقة المفصّلة ', 'Confirm custom plan ') + (pkCycle === 'yearly' ? T('(سنوي)', '(yearly)') : T('(شهري)', '(monthly)')) + ' — ' + pr.label + T('\nالمبلغ (أو فرق السعر لو بتكبّر باقتك) هيتخصم من المحفظة. التصغير بيتطبق عند التجديد.', '\nThe amount (or the prorated difference when upgrading) is charged to your wallet. Downsizing applies at renewal.'))) { btn.disabled = false; return; }
        const r = await jsonPost('/api/social/package/subscribe', { tier: 'custom', cycle: pkCycle, custom: q.data.spec, request_key: reqKey() });
        btn.disabled = false;
        if (!r.success) { payFail(r); return; }
        P.toast(r.message || T('تم ✅', 'Done ✅'), 'success');
        bld = null;
        await loadPackage();
        loadTargetsSoft();
    }
    async function loadPackage() {
        const r = await fetchPackage();
        if (!r.success) { $('socialPackages').innerHTML = '<div class="alert alert-danger">' + esc(r.error || T('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        renderPackage();
    }
    function payFail(r) {
        const goWallet = r.details && r.details.action === '/subscription#wallet';
        P.toast(r.error || T('تعذر', 'Failed'), 'error');
        if (goWallet && confirm((r.error || '') + '\n\n' + T('تروح تشحن المحفظة دلوقتي؟', 'Go top up your wallet now?'))) { location.hash = 'wallet'; const w = document.getElementById('wallet'); if (w) w.scrollIntoView({ behavior: 'smooth' }); else location.href = '/subscription#wallet'; }
    }
    async function buyTier(tier, btn) {
        const t = PKG.catalog.find(x => x.key === tier); if (!t) return;
        const pr = pkCycle === 'yearly' ? t.yearly : t.monthly;
        if (!confirm(T('تأكيد: باقة «', 'Confirm: «') + t.name + '» ' + (pkCycle === 'yearly' ? T('سنوي', 'yearly') : T('شهري', 'monthly')) + ' — ' + pr.label + T('\nالمبلغ (أو فرق السعر في الترقية) هيتخصم من المحفظة.', '\nThe amount (or the prorated difference when upgrading) is charged to your wallet.'))) return;
        btn.disabled = true;
        const r = await jsonPost('/api/social/package/subscribe', { tier, cycle: pkCycle, request_key: reqKey() });
        btn.disabled = false;
        if (!r.success) { payFail(r); return; }
        P.toast(r.message || T('تم ✅', 'Done ✅'), 'success');
        await loadPackage();
        loadTargetsSoft();
    }
    async function buyAddon(key, btn) {
        const a = (PKG.addons || []).find(x => x.key === key); if (!a) return;
        if (!confirm(T('تأكيد شراء +', 'Confirm buying +') + a.qty + ' ' + lab(a.kind) + ' — ' + a.price.label + T(' من المحفظة (صالحة 30 يوم)', ' from your wallet (valid 30 days)'))) return;
        btn.disabled = true;
        const r = await jsonPost('/api/social/package/addon', { addon: key, request_key: reqKey() });
        btn.disabled = false;
        if (!r.success) { payFail(r); return; }
        P.toast(r.message || T('تم ✅', 'Done ✅'), 'success');
        await loadPackage();
        loadTargetsSoft();
    }
    async function toggleRenew() {
        const on = !(PKG.subscription && PKG.subscription.auto_renew);
        if (!on && !confirm(T('إلغاء التجديد التلقائي؟ الباقة هتفضل شغالة لآخر المدة المدفوعة.', 'Cancel auto-renew? The plan stays active until the end of the paid period.'))) return;
        const r = await jsonPost('/api/social/package/auto-renew', { on });
        if (!r.success) { P.toast(r.error || T('تعذر', 'Failed'), 'error'); return; }
        P.toast(r.message || T('تم', 'Done'), 'success');
        loadPackage();
    }
    // بعد الترقية: الحسابات المقفولة تتفتح في المحرر وتبويب الحسابات
    function loadTargetsSoft() { document.dispatchEvent(new CustomEvent('tf:social-package-changed')); }
    loadPackage();
})();
