/* خطة السوشيال: حلّل صفحتي ← منافسينك ← خطة بوستات متجددة (بنفس محرك الخطة الشهرية). */
(function () {
    const root = document.getElementById('splan');
    if (!root || !window.Panel) return;
    const P = window.Panel;
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nf = new Intl.NumberFormat(ar ? 'ar-EG' : 'en-US');
    const num = n => (n == null ? '—' : nf.format(n));
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    let D = null, busy = '', pollT = null, intakeRes = null;

    const post = (path, data) => P.fetchJSON('/api/social-plan' + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data || {}) });
    const ICON = { facebook: '📘', instagram: '📸', tiktok: '🎵', youtube: '▶️' };
    const ACTION = { calendar: ['#splanPlan', t('خطة البوستات', 'Post plan')], inbox: ['/social#inbox', t('صندوق التعليقات', 'Comment inbox')] };
    const ST = { good: ['ok', '✓'], warn: ['mid', '!'], bad: ['bad', '✕'], unknown: ['na', '?'] };

    function ring(score) {
        const c = score >= 75 ? '#16a34a' : score >= 50 ? '#d97706' : '#dc2626';
        return `<div class="sp-ring" style="--v:${score};--c:${c}"><b>${nf.format(score)}</b><small>/100</small></div>`;
    }

    function auditCard(a) {
        const c = a.connection, au = a.audit;
        const head = `<div class="sp-acc">${c.avatar ? `<img src="${esc(c.avatar)}" alt="" referrerpolicy="no-referrer">` : `<span class="sp-av">${ICON[c.platform] || '•'}</span>`}
            <div><b>${esc(c.name)}</b><small>${ICON[c.platform] || ''} ${c.platform === 'instagram' ? 'Instagram' : 'Facebook'}${c.followers != null ? ' · ' + num(c.followers) + ' ' + t('متابع', 'followers') : ''}</small></div>
            <button type="button" class="p-btn ${au ? 'outline' : 'primary'} sm" data-audit="${c.id}" ${busy === 'a' + c.id ? 'disabled' : ''}>${busy === 'a' + c.id ? t('بنحلل…', 'Analysing…') : au ? t('حلّل تاني', 'Re-analyse') : t('حلّل الصفحة', 'Analyse page')}</button></div>`;
        if (!au) return `<div class="p-card sp-card">${head}<p class="sp-muted">${t('بنقرا آخر 30 بوست وتفاعلهم وبيانات الصفحة من Meta مباشرة، ونطلعلك درجة بأسباب واضحة.', 'We read the last 30 posts, their engagement and the page details straight from Meta and give you a score with clear reasons.')}</p></div>`;
        const diff = au.previous != null ? au.score - au.previous : null;
        const best = au.facts && au.facts.best_post;
        return `<div class="p-card sp-card">${head}
            <div class="sp-score">${ring(au.score)}<div><b>${au.score >= 75 ? t('صفحتك قوية', 'Strong page') : au.score >= 50 ? t('كويسة وفيها فرص واضحة', 'Good, with clear opportunities') : t('محتاجة شغل — وده اللي هيفرق', 'Needs work — here’s what will move it')}</b>
                <small>${diff ? (diff > 0 ? '▲ ' : '▼ ') + Math.abs(diff) + ' ' + t('عن آخر تحليل', 'since last analysis') + ' · ' : ''}${t('آخر تحليل', 'Analysed')} ${esc(String(au.at).slice(0, 16))} · ${num(au.facts.posts_analyzed)} ${t('بوست', 'posts')}</small></div></div>
            <ul class="sp-checks">${au.checks.map(k => `<li class="${ST[k.status][0]}"><i>${ST[k.status][1]}</i><div><b>${esc(k.title)}</b> <span>${esc(k.text)}</span>
                ${k.tip ? `<p>${esc(k.tip)}${k.action && ACTION[k.action] ? ` <a href="${ACTION[k.action][0]}">${ACTION[k.action][1]} ←</a>` : ''}</p>` : ''}</div>
                ${k.measured ? `<em>${k.points}/${k.max}</em>` : `<em class="na">${t('مش متقاس', 'n/a')}</em>`}</li>`).join('')}</ul>
            ${best ? `<div class="sp-best">🏆 ${t('أنجح بوست', 'Top post')}: <b>${num(best.engagement)}</b> ${t('تفاعل', 'engagements')} ${best.type === 'video' ? '· 🎬' : ''} — <span dir="auto">${esc(best.excerpt || '')}</span> ${best.permalink ? `<a href="${esc(best.permalink)}" target="_blank" rel="noopener">${t('افتحه', 'Open')} ↗</a>` : ''}</div>` : ''}
            <p class="sp-src">${t('كل الأرقام من Meta مباشرة. المقاييس اللي مفيهاش بيانات كفاية مابتتحسبش لا ليك ولا عليك.', 'All numbers come straight from Meta. Metrics without enough data don’t count for or against you.')}</p></div>`;
    }

    function intakeSection() {
        const k = D.intake;
        if (!k) return '';
        const row = (icon, name, status) => `<li dir="auto"><span>${icon}</span> <b dir="ltr">${esc(name)}</b> <small class="sp-muted">${status}</small></li>`;
        const own = k.own.map(o => row(ICON[o.platform] || '•', o.handle, o.connected ? t('مربوطة ✓', 'Connected ✓') : t('لسه مش مربوطة - اربطها من تبويب الحسابات', 'Not connected yet - connect it from the Accounts tab'))).join('');
        const comps = k.competitors.map(c => row(ICON[c.platform] || '•', c.handle, c.platform === 'instagram' ? (c.tracked ? t('بيتتابع ✓', 'Tracked ✓') : t('جاهز للإضافة', 'Ready to add')) : `<a href="${esc(c.ad_library)}" target="_blank" rel="noopener">${t('إعلاناته في مكتبة Meta ↗', 'His ads in the Meta Ad Library ↗')}</a>`)).join('');
        const rs = (intakeRes || []).map(r => `<li class="${r.status === 'done' ? 'ok' : r.status === 'failed' ? 'bad' : 'mid'}"><b dir="ltr">${esc(r.target)}</b> — ${esc(r.text)}${r.url ? ` <a href="${esc(r.url)}" target="_blank" rel="noopener">↗</a>` : ''}</li>`).join('');
        return `<section class="sp-sec"><div class="p-card sp-card"><div class="sp-h"><span>★</span><div><h2>${t('الفحص اللي بدأته', 'The check you started')}</h2><p>${t('الأرقام الحقيقية بتتقرا من Meta بعد ما صفحتك تبقى مربوطة. مفيش تقديرات.', 'Real numbers are read from Meta once your page is connected. No estimates.')}</p></div></div>
            <ul class="sp-checks">${own}${comps}</ul>${rs ? `<ul class="sp-checks">${rs}</ul>` : ''}
            <div class="sp-url"><button type="button" class="p-btn primary" data-intake-apply ${busy === 'intake' ? 'disabled' : ''}>${busy === 'intake' ? t('بننفّذ…', 'Running…') : t('نفّذ التحليل دلوقتي', 'Run the analysis now')}</button>
            <button type="button" class="p-btn outline" data-intake-dismiss>${t('إخفاء', 'Dismiss')}</button></div></div></section>`;
    }

    function auditSection() {
        const acc = D.accounts || [];
        return `<section class="sp-sec"><div class="sp-h"><span>1</span><div><h2>${t('حلّل صفحتي', 'Analyse my page')}</h2><p>${t('حط لينك صفحتك على فيسبوك أو إنستجرام', 'Paste your Facebook page or Instagram link')}</p></div></div>
            <div class="p-card sp-url"><input class="form-control" id="spUrl" dir="ltr" placeholder="https://www.facebook.com/yourpage"><button type="button" class="p-btn primary" data-audit-url ${busy === 'url' ? 'disabled' : ''}>${busy === 'url' ? t('بنحلل…', 'Analysing…') : t('حلّل', 'Analyse')}</button></div>
            ${acc.length ? `<div class="sp-grid">${acc.map(auditCard).join('')}</div>`
                : `<div class="p-card sp-empty">🔗 <b>${t('اربط صفحتك على فيسبوك أو إنستجرام', 'Connect your Facebook or Instagram page')}</b><p>${t('Meta مابتسمحش بقراية أرقام أي صفحة من غير إذن صاحبها — الربط بياخد دقيقة وبيشغّل التحليل والنشر التلقائي.', 'Meta doesn’t allow reading a page’s numbers without its owner’s permission — connecting takes a minute and powers the analysis and auto-publishing.')}</p><a class="p-btn primary" href="/social#accounts">${t('اربط الصفحة', 'Connect page')}</a></div>`}</section>`;
    }

    function compSection() {
        const c = D.competitors || { pages: [] };
        const cards = c.pages.map(p => {
            const m = p.metrics;
            return `<div class="p-card sp-comp"><div class="sp-comp-h"><img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(p.domain)}&sz=64" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"><div><b dir="auto">${esc(p.name || p.domain)}</b><small dir="ltr">${esc(p.domain)}</small></div></div>
                <div class="sp-links">${p.facebook ? `<a href="${esc(p.facebook)}" target="_blank" rel="noopener">📘 ${t('صفحة فيسبوك', 'Facebook page')} ↗</a>` : ''}${p.instagram ? `<a href="https://www.instagram.com/${esc(p.instagram)}/" target="_blank" rel="noopener">📸 @${esc(p.instagram)} ↗</a>` : ''}${p.ads_url ? `<a href="${esc(p.ads_url)}" target="_blank" rel="noopener" title="${esc(t('مكتبة إعلانات Meta الرسمية: كل إعلاناتهم الشغالة دلوقتي على فيسبوك وإنستجرام', 'Meta’s official Ad Library: all their ads running now on Facebook and Instagram'))}">📢 ${t('إعلاناتهم الشغالة', 'Their running ads')} ↗</a>` : ''}</div>
                ${m ? `<div class="sp-mets"><div><b>${num(m.followers)}</b><small>${t('متابع', 'followers')}${m.change_30d ? ` (${m.change_30d > 0 ? '+' : ''}${num(m.change_30d)})` : ''}</small></div><div><b>${m.posts_per_week ?? '—'}</b><small>${t('بوست/أسبوع', 'posts/week')}</small></div><div><b>${m.avg_engagement ?? '—'}</b><small>${t('تفاعل/بوست', 'eng./post')}</small></div></div>
                    ${(m.top || []).length ? `<div class="sp-top">${m.top.map(x => `<a href="${esc(x.permalink || '#')}" target="_blank" rel="noopener" dir="auto">🔥 ${esc(String(x.caption || '').slice(0, 80))}…</a>`).join('')}</div>` : ''}` : ''}
                ${p.note ? `<p class="sp-muted">${esc(p.note)}</p>` : ''}</div>`;
        }).join('');
        return `<section class="sp-sec"><div class="sp-h"><span>2</span><div><h2>${t('منافسينك على السوشيال', 'Your competitors on social')}</h2><p>${t('من مواقع منافسينك الحقيقيين — بنطلّع صفحاتهم لوحدنا', 'From your real competitors’ websites — we find their pages for you')}</p></div>
                <button type="button" class="p-btn outline sm" data-comp ${busy === 'comp' ? 'disabled' : ''}>${busy === 'comp' ? t('بندوّر…', 'Searching…') : t('دوّر تاني', 'Search again')}</button></div>
            ${(c.insights || []).length ? `<div class="p-card sp-ins">${c.insights.map(x => `<p>💡 ${esc(x)}</p>`).join('')}</div>` : ''}
            ${cards ? `<div class="sp-grid c3">${cards}</div>` : `<div class="p-card sp-empty">${c.has_competitors ? `<p>${t('دوس «دوّر تاني» ونطلّعلك صفحاتهم.', 'Press “Search again” and we’ll find their pages.')}</p>` : `<p>${t('مفيش منافسين متسجلين لسه.', 'No competitors saved yet.')}</p><p class="sp-muted">${t('نطلّعلك منافسينك الحقيقيين من بيانات جوجل لموقعك (اللي بيظهروا في نفس بحثك) ونجيب صفحاتهم على فيسبوك وإنستجرام. مفيش تخمين.', 'We find your real competitors from Google data for your site (the ones showing up for the same searches) and pull their Facebook and Instagram pages. No guessing.')}</p><div class="sp-url"><button type="button" class="p-btn primary" data-comp-find ${busy === 'find' || !D.can_manage ? 'disabled' : ''}>${busy === 'find' ? t('بندوّر على منافسينك…', 'Finding your competitors…') : t('اكتشف منافسيني الحقيقيين', 'Find my real competitors')}</button><a class="p-btn outline" href="/competitor-intelligence">${t('أو ضيفهم بإيدك', 'Or add them manually')}</a></div>`}</div>`}
            <p class="sp-src">${t('أرقام إنستجرام حقيقية من Meta (لازم تكون رابط إنستجرام بيزنس). فيسبوك مابيسمحش بقراية أرقام صفحات تانية، فبنعرض لينك الصفحة بس.', 'Instagram numbers are real, from Meta (requires a connected Instagram Business account). Facebook doesn’t allow reading other pages’ numbers, so we show the page link only.')}</p></section>`;
    }

    const PST = { draft: ['', t('مستني موافقتك', 'Awaiting approval')], scheduled: ['blue', t('مجدول', 'Scheduled')], publishing: ['blue', t('بيتنشر', 'Publishing')], published: ['green', t('اتنشر ✓', 'Published ✓')], failed: ['red', t('فشل', 'Failed')], uncertain: ['red', t('محتاج مراجعة', 'Check needed')] };

    function planSection() {
        const pl = D.plan, r = D.ready || {};
        // أحسن بوست اتنشر (تفاعل حقيقي من Meta) - عليه زرار «روّجه بإعلان»
        const best = pl ? (pl.items || []).filter(i => i.status === 'published' && i.post_id && (i.likes || 0) + (i.comments || 0) > 0).sort((a, b) => (b.likes + b.comments) - (a.likes + a.comments))[0] : null;
        let body;
        if (!pl) {
            const pages = (D.accounts || []).length;
            const checks = [[pages > 0, t('صفحة فيسبوك أو إنستجرام مربوطة', 'A connected Facebook or Instagram page'), '/social#accounts'],
                [r.profile === true, t('بيانات الشركة متأكدة (عشان البوستات ماتقولش معلومة غلط)', 'Confirmed company profile (so posts never state a wrong fact)'), '/business-hub'],
                [r.photos > 0, r.photos > 0 ? t('صور رحلاتك جاهزة', 'Your trip photos are ready') : t('صور رحلاتك — هنسحبها من موقعك تلقائي', 'Your trip photos — we’ll pull them from your site automatically'), null]];
            const ok = pages > 0 && r.profile === true;
            body = `<div class="p-card"><ul class="sp-ready">${checks.map(c => `<li class="${c[0] ? 'ok' : (c[2] ? 'bad' : 'mid')}"><i>${c[0] ? '✓' : (c[2] ? '✕' : '•')}</i> ${esc(c[1])} ${!c[0] && c[2] ? `<a href="${c[2]}">${t('كمّل', 'Fix')} ←</a>` : ''}</li>`).join('')}</ul>
                <div class="sp-start"><label>${t('لغة البوستات', 'Post language')} <select id="spLang" class="p-select">${(D.languages || []).map(l => `<option value="${esc(l.code)}" ${l.default ? 'selected' : ''}>${esc(l.name)}${l.market ? ' ★' : ''}${l.design ? '' : ' — ' + t('صور بس', 'photos only')}</option>`).join('')}</select></label><label>${t('عدد البوستات في الشهر لكل صفحة', 'Posts per month per page')} <select id="spCount" class="p-select">${[8, 12, 16, 20].map(n => `<option ${n === 12 ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
                    ${r.autopilot_allowed ? `<label class="sp-chk"><input type="checkbox" id="spAuto"> ${t('انشر لوحدها من غير ما أراجع (تقدر توقفها أي وقت)', 'Publish automatically without my review (pause anytime)')}</label>` : ''}
                    <button type="button" class="p-btn primary" data-start ${!ok || !D.can_manage || busy === 'start' ? 'disabled' : ''}>${busy === 'start' ? t('بنبدأ…', 'Starting…') : t('ابدأ خطة السوشيال', 'Start the social plan')}</button></div>
                <p class="sp-muted">${t('خطة بتبدأ من بكرة وبتتجدد لوحدها كل شهر من غير فجوات: كل بوست مربوط برحلة حقيقية وموسمها، وفيها بوستات لمقالاتك وتقييمات عملائك الحقيقية، في أحسن أوقات جمهورك — وتقدر تعدّل أي بوست قبل نشره.', 'A plan that starts tomorrow and renews itself monthly with no gaps: every post tied to a real trip and its season, plus posts for your articles and real customer reviews, at your audience’s best times — edit any post before it goes out.')}</p></div>`;
        } else {
            const gen = ['queued', 'generating'].includes(pl.status);
            const cnt = pl.counts || {};
            body = `<div class="p-card sp-plan-h"><div><b>${gen ? '⏳ ' + t('بنجهّز البوستات…', 'Preparing posts…') : pl.status === 'draft' ? '📝 ' + t('الخطة جاهزة — راجعها واعتمدها', 'Plan ready — review and approve') : pl.status === 'active' ? '✅ ' + t('الخطة شغالة', 'Plan running') : esc(pl.status)}</b>
                    <small>${pl.starts_on ? esc(pl.starts_on) + ' → ' + esc(pl.ends_on) : ''}${pl.renew ? ' · 🔁 ' + t('بتتجدد لوحدها', 'renews automatically') + (pl.renew_on ? ' (' + esc(pl.renew_on) + ')' : '') : ''}${pl.auto_approve ? ' · ⚡ ' + t('نشر تلقائي', 'auto-publish') : ''}</small></div>
                <div class="sp-acts">${pl.status === 'draft' && D.can_manage ? `<button type="button" class="p-btn primary sm" data-approve>${t('اعتمد وجدول النشر', 'Approve & schedule')}</button>` : ''}<a class="p-btn outline sm" href="/social#plan">${t('عدّل البوستات', 'Edit posts')}</a></div></div>
                ${pl.error ? `<div class="p-card sp-err">${esc(pl.error)}</div>` : ''}
                ${!pl.worker_ready ? `<div class="p-card sp-err">${t('النشر التلقائي محتاج كرون كل دقيقة على السيرفر — بلّغ الدعم.', 'Auto-publishing needs the every-minute server cron — contact support.')}</div>` : ''}
                ${pl.strategy ? `<div class="p-card sp-ins"><p>🎯 ${esc(pl.strategy)}</p></div>` : ''}
                ${!gen ? `<div class="sp-counts">${[['scheduled', t('مجدول', 'scheduled')], ['published', t('اتنشر', 'published')], ['failed', t('فشل', 'failed')]].map(k => `<span>${num(cnt[k[0]] || 0)} ${k[1]}</span>`).join('')}</div>
                <div class="sp-grid c3">${pl.items.map(i => `<div class="p-card sp-post">${i.image ? (i.format === 'reel' || /\.mp4($|\?)/i.test(i.image) ? `<video src="${esc(i.image)}" muted loop playsinline preload="metadata" controls></video>` : `<img src="${esc(i.image)}" alt="" loading="lazy">`) : '<span class="ph">🖼️</span>'}
                    <div class="b"><div class="r"><span>${ICON[i.platform] || ''} ${esc(String(i.at || '').replace('T', ' '))}</span><em class="${(PST[i.status] || [''])[0]}">${(PST[i.status] || ['', esc(i.status)])[1]}</em></div>
                    <b dir="auto">${esc(i.theme || '')}</b><p dir="auto">${esc(i.content)}</p>
                    ${i.likes != null ? `<small>❤️ ${num(i.likes)} · 💬 ${num(i.comments)}</small>` : ''}${best && i.id === best.id ? `<a class="sp-boost" href="/ads?from_post=${Number(i.post_id)}">🚀 ${t('أحسن بوست — روّجه بإعلان', 'Best post — boost it')}</a>` : ''}${i.error ? `<small class="e">${esc(i.error)}</small>` : ''}</div></div>`).join('')}</div>` : '<div class="obs-loading"><span></span><span></span><span></span></div>'}`;
        }
        return `<section class="sp-sec" id="splanPlan"><div class="sp-h"><span>3</span><div><h2>${t('خطة البوستات', 'Post plan')}</h2><p>${t('مربوطة برحلاتك ومواسمها ومقالاتك وتقييماتك — وبتتنشر لوحدها', 'Tied to your trips, seasons, articles and reviews — and publishes itself')}</p></div></div>${body}</section>`;
    }

    const money = (v, c) => (c ? new Intl.NumberFormat(ar ? 'ar-EG' : 'en-US', { style: 'currency', currency: c, maximumFractionDigits: 0 }).format(v || 0) : num(Math.round(v || 0)));

    function resultsSection() {
        const R = D.results || {}, I = D.inbox || {};
        const tiles = `<div class="sp-mets c4">
            <div><b>${num(R.bookings)}</b><small>${t('حجز من السوشيال', 'Bookings from social')}</small></div>
            <div><b>${money(R.revenue, R.currency)}</b><small>${t('إيراد مؤكد', 'Confirmed revenue')}</small></div>
            <div><b>${num(R.leads)}</b><small>${t('عميل محتمل من التعليقات', 'Leads from comments')}</small></div>
            <div><b>${num(R.clicks)}</b><small>${t('ضغطة على لينكاتك', 'Clicks on your links')}</small></div></div>`;
        const top = (R.top_posts || []).length ? `<div class="sp-top-posts"><b>${t('أحسن بوستات جابت حجوزات وزيارات', 'Posts that brought bookings and visits')}</b><ol>${R.top_posts.map(p => `<li><span dir="auto">${esc(p.text)}</span> <a class="sp-boost" href="/ads?from_post=${Number(p.id)}">🚀 ${t('روّجه بإعلان', 'Boost it')}</a><em>${p.bookings ? num(p.bookings) + ' ' + t('حجز', 'bookings') + (p.revenue ? ' · ' + money(p.revenue, p.currency) : '') + ' · ' : ''}${num(p.clicks)} ${t('ضغطة', 'clicks')}</em></li>`).join('')}</ol></div>`
            : `<p class="sp-muted">${R.tracking ? t('لسه مفيش ضغطات على لينكات بوستات الشهر ده. كل بوست فيه لينك لموقعك بيتحول للينك متتبَّع، فأي حجز بيجي منه بيظهر هنا.', 'No clicks on this month’s post links yet. Every link to your site in a post becomes a tracked link, so bookings it brings show up here.') : t('تتبّع الحجوزات من البوستات مش متفعّل على السيرفر لسه.', 'Booking tracking from posts isn’t set up on the server yet.')}</p>`;
        const trips = (R.lead_trips || []).length ? `<p class="sp-muted">${t('أكتر رحلات اتسأل عليها:', 'Most asked-about trips:')} ${R.lead_trips.map(x => `<b dir="auto">${esc(x.trip)}</b> (${num(x.n)})`).join(ar ? '، ' : ', ')}</p>` : '';
        const auto = !I.ready ? '' : `<div class="p-card sp-auto">
            <div><b>${t('رد تلقائي في الخاص', 'Automatic private reply')}</b>
            <p>${t('أي حد يعلّق يسأل عن السعر أو الحجز: يوصله في الخاص اسم الرحلة اللي البوست عنها وسعرها المؤكد من منتجاتك ولينك حجزها، ويتسجّل عندك عميل محتمل في الـCRM.', 'Anyone commenting about price or booking gets a private message with the trip the post is about, its confirmed price from your products and its booking link, and is saved as a CRM lead.')}</p>
            <small class="sp-muted">${t('اتبعت لوحده الشهر ده:', 'Sent automatically this month:')} ${num(R.auto_replies)} · <a href="/social#inbox">${t('عدّل نص الرسالة', 'Edit the message')}</a></small></div>
            ${I.allowed ? `<label class="sp-switch"><input type="checkbox" data-auto-reply ${I.on ? 'checked' : ''} ${busy === 'auto' || !D.can_manage ? 'disabled' : ''}><span></span></label>`
                : `<a class="p-btn outline sm" href="/pricing">${t('متاح في باقة أعلى', 'Available on a higher plan')}</a>`}</div>`;
        return `<section class="sp-sec"><div class="sp-h"><span>4</span><div><h2>${t('التعليقات بتبقى حجوزات', 'Comments become bookings')}</h2><p>${t('نتايج الشهر ده من بوستاتك — أرقام حقيقية من لينكاتك وحجوزاتك', 'This month’s results from your posts — real numbers from your links and bookings')}</p></div></div>
            ${auto}<div class="p-card sp-card">${tiles}${top}${trips}</div></section>`;
    }

    function render() {
        if (!D) return;
        root.innerHTML = intakeSection() + auditSection() + compSection() + planSection() + resultsSection();
        clearTimeout(pollT);
        if (D.plan && ['queued', 'generating'].includes(D.plan.status)) pollT = setTimeout(load, 10000);
    }

    async function load() {
        const res = await P.fetchJSON('/api/social-plan');
        if (!res || !res.success) { root.innerHTML = `<div class="p-card"><div class="p-empty">${esc((res && res.error) || t('تعذر التحميل', 'Could not load'))}</div></div>`; return; }
        D = res.data;
        render();
    }

    async function act(key, fn) {
        busy = key; render();
        const res = await fn();
        busy = '';
        if (res && res.success) { D = res.data; if (res.message) P.toast(res.message, 'success'); }
        else {
            P.toast((res && res.error) || t('حصل خطأ', 'Something went wrong'), 'error');
            if (res && res.details && res.details.connect) setTimeout(() => { if (confirm(t('تربط الصفحة دلوقتي؟', 'Connect the page now?'))) location.href = res.details.connect; }, 300);
        }
        render();
    }

    root.addEventListener('change', e => {
        const sw = e.target.closest('[data-auto-reply]');
        if (sw) act('auto', () => post('/auto-reply', { on: sw.checked }));
    });

    root.addEventListener('click', async e => {
        const a = e.target.closest('[data-audit]');
        if (a) return act('a' + a.dataset.audit, () => post('/audit', { connection_id: Number(a.dataset.audit) }));
        if (e.target.closest('[data-audit-url]')) {
            const url = (document.getElementById('spUrl').value || '').trim();
            if (!url) return P.toast(t('حط لينك الصفحة', 'Paste the page link'), 'error');
            return act('url', () => post('/audit', { url }));
        }
        if (e.target.closest('[data-intake-apply]')) return act('intake', async () => { const r = await post('/intake/apply'); if (r && r.success) intakeRes = r.intake_results; return r; });
        if (e.target.closest('[data-intake-dismiss]')) { const r = await post('/intake/dismiss'); if (r && r.success) { D.intake = null; intakeRes = null; render(); } return; }
        if (e.target.closest('[data-comp-find]')) return act('find', () => post('/competitors/find'));
        if (e.target.closest('[data-comp]')) return act('comp', () => post('/competitors/refresh'));
        if (e.target.closest('[data-start]')) {
            const auto = document.getElementById('spAuto');
            return act('start', () => post('/start', { post_count: Number(document.getElementById('spCount').value), auto_approve: !!(auto && auto.checked), language: (document.getElementById('spLang') || {}).value || '' }));
        }
        const ap = e.target.closest('[data-approve]');
        if (ap) {
            ap.disabled = true;
            const res = await P.fetchJSON('/api/social/monthly-plans/' + D.plan.id + '/approve', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ version: D.plan.version }) });
            if (res && res.success) { P.toast(t('اتعتمدت ✓ — البوستات اتجدولت', 'Approved ✓ — posts scheduled'), 'success'); load(); }
            else { ap.disabled = false; P.toast((res && res.error) || 'Error', 'error'); }
        }
    });
    load();
})();
