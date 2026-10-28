/**
 * Tourfecto - ذكاء الإيرادات: رحلات الموسم ← منشورات سوشيال جاهزة.
 * بيعرض الرحلات مرتبة حسب الطلب في الموسم، والعميل بيختار ويضغط زرار؛ المنشورات بتتجهز في الخلفية
 * (تحليل صورة الرحلة، صورة احترافية لو لازم، نص + هاشتاجات + رابط) وبتتفتح في محرر السوشيال للنشر.
 */
(function () {
    'use strict';
    const P = window.Panel;
    if (!P) return;
    const AR = (document.documentElement.lang || 'ar').slice(0, 2) === 'ar';
    const T = (a, e) => (AR ? a : e);
    const esc = P.esc;
    const IC = (n, s) => (window.SPI ? window.SPI.i(n, s || 15) : '');
    const nf = n => Number(n || 0).toLocaleString(AR ? 'ar-EG' : 'en-GB');
    const jsonPost = (url, body) => P.fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    const LEVEL = { high: ['hot', T('طلب عالي', 'High demand')], medium: ['warm', T('طلب متوسط', 'Medium demand')], low: ['cool', T('طلب قليل', 'Low demand')], none: ['none', T('مفيش إشارات طلب لسه', 'No demand signals yet')] };
    const monthName = ym => { try { return new Date(ym + '-01T00:00:00').toLocaleDateString(AR ? 'ar-EG' : 'en-GB', { month: 'long' }); } catch (e) { return ym; } };
    let root = null, D = null, selected = new Set(), batch = null, timer = null;

    function tripCard(t) {
        const d = t.demand || {}, lv = LEVEL[d.level] || LEVEL.none, on = selected.has(t.key);
        const img = t.image_url ? '<img src="' + esc(t.image_url) + '" alt="" loading="lazy" onerror="this.remove()">' : '';
        return '<label class="ssn-trip' + (on ? ' on' : '') + '"><input type="checkbox" data-trip="' + esc(t.key) + '"' + (on ? ' checked' : '') + '>'
            + '<div class="ssn-img">' + img + IC('image', 26) + '<span class="ssn-rank">#' + nf(d.rank) + '</span></div>'
            + '<div class="ssn-body"><div class="ssn-top"><span class="ssn-lv ' + lv[0] + '">' + (d.level === 'high' ? IC('trend', 13) + ' ' : '') + esc(lv[1]) + '</span><span class="ssn-ck">' + IC('check', 13) + '</span></div>'
            + '<b class="ssn-name" dir="auto" data-no-translate>' + esc(t.name) + '</b>'
            + '<div class="ssn-facts">' + (t.price ? '<span>' + IC('wallet', 13) + ' ' + esc(t.price) + '</span>' : '') + (t.duration ? '<span>' + IC('clock', 13) + ' ' + esc(t.duration) + '</span>' : '') + '</div>'
            + ((d.reasons || []).length ? '<ul class="ssn-why">' + d.reasons.map(r => '<li dir="auto">' + esc(r) + '</li>').join('') + '</ul>' : '')
            + (t.published ? '' : '<div class="ssn-warn">' + IC('alert', 13) + ' ' + T('الموقع لسه مش منشور - الرابط مش هيفتح للعملاء', 'Site not published yet — the link won\'t open for customers') + '</div>')
            + '</div></label>';
    }

    function postCard(p) {
        const busy = p.status === 'pending' || p.status === 'working';
        const mediaBusy = p.media && p.media.status !== 'completed' && p.media.status !== 'failed';
        const img = p.media && p.media.url ? '<img src="' + esc(p.media.url) + '" alt="">' : '<div class="ssn-ph">' + (busy || mediaBusy ? '<span class="ssn-spin"></span>' + T('بتتعمل صورة احترافية…', 'Creating a professional image…') : IC('image', 28)) + '</div>';
        const src = p.image_source === 'site_photo' ? '<span class="ssn-src ok">' + IC('check', 12) + ' ' + T('صورة موقعك', 'Your site photo') + '</span>'
            : p.image_source === 'ai_generated' ? '<span class="ssn-src ai">' + IC('sparkles', 12) + ' ' + T('صورة احترافية بالذكاء الصناعي', 'Professional AI image') + '</span>' : '';
        if (busy) {
            return '<article class="ssn-post busy"><div class="ssn-pimg"><div class="ssn-ph"><span class="ssn-spin"></span></div></div><div class="ssn-pbody"><b>' + esc(p.trip_name) + '</b><p class="p-cell-muted">'
                + T('بنحلل صورة الرحلة ونكتب المنشور…', 'Analysing the trip photo and writing the post…') + '</p><div class="ssn-skel"></div><div class="ssn-skel s"></div></div></article>';
        }
        if (p.status === 'failed') {
            return '<article class="ssn-post bad"><div class="ssn-pbody"><b>' + esc(p.trip_name) + '</b><div class="alert alert-danger sp-small">' + esc(p.error || T('حصلت مشكلة', 'Something went wrong')) + '</div></div></article>';
        }
        const ready = !(p.media && p.media.status !== 'completed');
        return '<article class="ssn-post' + (p.status === 'used' ? ' used' : '') + '"><div class="ssn-pimg">' + img + src + '</div><div class="ssn-pbody">'
            + '<div class="ssn-ptop"><b>' + esc(p.trip_name) + '</b>' + (p.status === 'used' ? '<span class="ssn-lv cool">' + IC('check', 12) + ' ' + T('اتنشر/اتجدول', 'Posted') + '</span>' : '') + '</div>'
            + (p.image_note ? '<div class="p-cell-muted ssn-note">' + IC('info', 12) + ' ' + esc(p.image_note) + '</div>' : '')
            + '<div class="ssn-text" dir="auto" data-no-translate>' + esc(p.content || '') + '</div>'
            + ((p.hashtags || []).length ? '<div class="ssn-tags" data-no-translate>' + p.hashtags.map(h => '<span>#' + esc(h) + '</span>').join('') + '</div>' : '')
            + (p.first_comment ? '<div class="ssn-fc" data-no-translate>' + IC('chat', 12) + ' ' + T('أول تعليق: ', 'First comment: ') + esc(p.first_comment) + '</div>' : '')
            + (p.trip_url ? '<a class="ssn-link" href="' + esc(p.trip_url) + '" target="_blank" rel="noopener">' + IC('link', 12) + ' ' + esc(p.trip_url) + '</a>' : '')
            + '<div class="ssn-acts">' + (ready ? '<a class="p-btn primary sm" href="/social?season=' + p.id + '">' + IC('send', 14) + ' ' + T('افتح في محرر النشر', 'Open in post editor') + '</a>' : '<span class="p-cell-muted sp-small"><span class="ssn-spin"></span> ' + T('الصورة بتخلص…', 'Image is finishing…') + '</span>')
            + '<button type="button" class="p-btn ghost sm" data-copy="' + p.id + '">' + IC('copy', 14) + ' ' + T('انسخ النص', 'Copy text') + '</button>'
            + '<button type="button" class="p-btn ghost sm" data-dismiss="' + p.id + '" title="' + esc(T('إخفاء', 'Hide')) + '">' + IC('x', 14) + '</button></div></div></article>';
    }

    function paintPosts(posts) {
        const box = root.querySelector('#ssnPosts');
        if (!box) return;
        box.innerHTML = posts.length ? '<div class="p-card-head"><h3>' + IC('posts') + ' ' + T('المنشورات الجاهزة', 'Ready posts') + '</h3><span class="p-card-sub">' + T('راجعها، وافتحها في المحرر تختار الصفحات والميعاد وتنشر', 'Review, then open in the editor to pick pages, time and publish') + '</span></div><div class="ssn-posts">' + posts.map(postCard).join('') + '</div>' : '';
        box.hidden = !posts.length;
        box.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
            const p = posts.find(x => String(x.id) === b.dataset.copy); if (!p) return;
            const text = (p.content || '') + ((p.hashtags || []).length ? '\n\n' + p.hashtags.map(h => '#' + h).join(' ') : '');
            (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => P.toast(T('اتنسخ ✓', 'Copied ✓'), 'success')).catch(() => P.toast(T('مقدرناش ننسخ', 'Copy failed'), 'error'));
        }));
        box.querySelectorAll('[data-dismiss]').forEach(b => b.addEventListener('click', async () => {
            await jsonPost('/api/revenue-intelligence/season/posts/' + b.dataset.dismiss + '/state', { state: 'dismissed' });
            const r = await P.fetchJSON('/api/revenue-intelligence/season/posts' + (batch ? '?batch=' + encodeURIComponent(batch) : ''));
            if (r.success) paintPosts(r.data.posts);
        }));
        const pending = posts.some(p => p.status === 'pending' || p.status === 'working' || (p.media && !['completed', 'failed'].includes(p.media.status) && p.status === 'ready'));
        clearTimeout(timer);
        if (pending) timer = setTimeout(poll, 4000);
    }

    async function poll() {
        if (!root || !document.body.contains(root)) return;
        const r = await P.fetchJSON('/api/revenue-intelligence/season/posts' + (batch ? '?batch=' + encodeURIComponent(batch) : ''));
        if (r.success) paintPosts(batch ? r.data.posts.concat((D.posts || []).filter(p => p.batch !== batch)) : r.data.posts);
    }

    function paintSelectBar() {
        const n = selected.size, btn = root.querySelector('#ssnGo');
        btn.disabled = !n;
        btn.innerHTML = IC('sparkles', 16) + ' ' + (n ? T('اعمل ' + nf(n) + ' منشور للرحلات المختارة', 'Create ' + n + ' post(s) for selected trips') : T('اختار رحلة', 'Pick a trip'));
    }

    function render() {
        const months = (D.season.months || []).map(monthName).join(' – ');
        const trips = D.trips || [];
        if (!selected.size) trips.filter(t => (t.demand || {}).score > 0).slice(0, 3).forEach(t => selected.add(t.key));
        root.innerHTML = '<div class="ssn">'
            + '<div class="p-card ssn-hero"><div><h3>' + IC('trend', 20) + ' ' + T('رحلات الموسم', 'Season trips') + '</h3><p>' + T('بنرتّب رحلاتك حسب الطلب الحقيقي في الموسم اللي داخلين عليه: حجوزاتك، والبحث في أسواقك، ورحلات منافسينك، وموسم كل وجهة (', 'We rank your trips by real demand for the coming season: your bookings, searches in your markets, competitor trips and each destination\'s season (') + '<b>' + esc(months) + '</b>'
            + T(')، ونعملها منشورات جاهزة: نص احترافي بالتفاصيل والسعر، هاشتاجات، رابط الحجز، وصورة الرحلة من موقعك - ولو مش احترافية بنعمل صورة احترافية بالذكاء الصناعي.', '), then turn them into ready posts: professional caption with details and price, hashtags, booking link and the trip photo from your site — or a professional AI image when the photo is not good enough.') + '</p></div></div>'
            + '<div class="p-card ssn-bar" id="ssnSyncBar"><div class="p-cell-muted">' + IC('link', 13) + ' ' + ((D.site_sync || {}).count ? T(nf(D.site_sync.count) + ' رحلة من موقعك · آخر تحديث ' + esc(String(D.site_sync.last || '').slice(0, 16)), D.site_sync.count + ' trips from your website · updated ' + esc(String(D.site_sync.last || '').slice(0, 16))) : T('الرحلات اللي على موقعك ممكن تتسحب هنا لوحدها.', 'The trips on your website can be pulled in here automatically.')) + '</div><div class="ssn-acts"><button type="button" class="p-btn outline" id="ssnSync">' + IC('trend', 14) + ' ' + T('اسحب الرحلات من موقعك', 'Pull trips from my website') + '</button>' + ((D.sources || {}).search_configured ? '<button type="button" class="p-btn outline" id="ssnVol">' + IC('search', 14) + ' ' + T('قيس البحث على كل رحلة', 'Measure searches per trip') + '</button>' : '') + '</div></div>'
            + (!D.installed ? '<div class="alert alert-warning">' + T('شغّل ملف قاعدة البيانات 2026_10_24_000001_season_posts.sql الأول.', 'Run the 2026_10_24_000001_season_posts.sql migration first.') + '</div>' : '')
            + (!trips.length ? '<div class="p-card"><div class="p-empty">' + IC('folder', 26) + '<div>' + T('مفيش رحلات لسه. اسحب رحلاتك من موقعك بالزرار اللي فوق، أو ضيفها من «الحجوزات والتوفر».', 'No trips yet. Pull your trips from your website with the button above, or add them under "Bookings & availability".') + '</div><a class="p-btn primary sm" href="/website-builder">' + T('ضيف رحلات', 'Add trips') + '</a></div></div>'
                : (!D.has_data ? '<div class="alert alert-info sp-small">' + IC('info') + ' ' + T('لسه مفيش إشارات طلب على الرحلات دي: مفيش حجوزات، ولا بحث في أسواقك، ولا منافسين بيبيعوا زيها. ضيف أسواقك وكلمات البحث من «الكلمات المفتاحية» وتابع منافسينك، والترتيب هيبقى أدق.', 'No demand signals for these trips yet: no bookings, searches in your markets or competitor trips. Add your markets and keywords under Keywords and track competitors to sharpen the ranking.') + '</div>' : '')
                + '<div class="ssn-trips">' + trips.map(tripCard).join('') + '</div>'
                + '<div class="p-card ssn-bar"><label class="ssn-lang">' + T('لغة المنشورات', 'Post language') + ' <select class="p-select" id="ssnLang"><option value="ar"' + (AR ? ' selected' : '') + '>العربية</option><option value="en"' + (AR ? '' : ' selected') + '>English</option></select></label>'
                + '<div class="ssn-cost p-cell-muted">' + IC('wallet', 13) + ' ' + T('استخدام واحد من أدوات التسويق للدفعة. الصور اللي بتتولد: ', 'One marketing-tool use per batch. Generated images: ') + esc(D.image_cost || '') + '</div>'
                + '<button type="button" class="p-btn primary" id="ssnGo"></button></div>')
            + '<section class="p-card" id="ssnPosts" hidden></section></div>';
        root.querySelectorAll('[data-trip]').forEach(cb => cb.addEventListener('change', () => {
            const id = cb.dataset.trip;
            if (cb.checked) {
                if (selected.size >= (D.max_trips || 5)) { cb.checked = false; P.toast(T('لحد ' + (D.max_trips || 5) + ' رحلات في المرة', 'Up to ' + (D.max_trips || 5) + ' trips at a time'), 'error'); return; }
                selected.add(id);
            } else selected.delete(id);
            cb.closest('.ssn-trip').classList.toggle('on', cb.checked);
            paintSelectBar();
        }));
        const sync = root.querySelector('#ssnSync');
        if (sync) sync.addEventListener('click', async () => {
            sync.disabled = true;
            sync.textContent = T('بنقرا موقعك… ممكن ياخد دقيقة', 'Reading your website… this can take a minute');
            const r = await jsonPost('/api/revenue-intelligence/season/sync', {});
            if (!r.success) { sync.disabled = false; sync.textContent = T('اسحب الرحلات من موقعك', 'Pull trips from my website'); P.toast(r.error || T('تعذر قراءة الموقع', 'Could not read the website'), 'error'); return; }
            const found = (r.data.sync || {}).total || 0, all = (r.data.site_sync || {}).count || 0, more = ((r.data.sync || {}).sites || []).some(x => !x.complete);
            P.toast(all ? T('عندك ' + nf(all) + ' رحلة من موقعك' + (found ? ' (اتحدّث ' + nf(found) + ')' : '') + (more ? '. اضغط تاني علشان نكمّل باقي الموقع.' : ''), all + ' trips from your website' + (found ? ' (' + found + ' updated)' : '') + (more ? '. Press again to continue with the rest of the site.' : '')) : T('ملقيناش صفحات رحلات في موقعك. اتأكد إن الموقع فيه sitemap أو لينكات للرحلات من الصفحة الرئيسية.', 'No trip pages found. Make sure the site has a sitemap or links to trips from the home page.'), all ? 'success' : 'error');
            D = r.data; selected.clear(); render();
            measure();
        });
        const vbtn = root.querySelector('#ssnVol');
        if (vbtn) vbtn.addEventListener('click', () => measure());
        const go = root.querySelector('#ssnGo');
        if (go) {
            paintSelectBar();
            go.addEventListener('click', async () => {
                go.disabled = true;
                const r = await jsonPost('/api/revenue-intelligence/season/posts', { trip_keys: [...selected], language: root.querySelector('#ssnLang').value, request_key: 'ssn' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) });
                go.disabled = false;
                if (!r.success) { P.toast(r.error || T('تعذر', 'Failed'), 'error'); return; }
                batch = r.data.batch;
                P.toast(T('بنجهّز المنشورات… هتظهر هنا أول ما تخلص', 'Preparing posts… they will appear here when ready'), 'success');
                paintPosts(r.data.posts.concat((D.posts || []).filter(p => p.batch !== batch)));
                root.querySelector('#ssnPosts').scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
        paintPosts(D.posts || []);
    }

    // حجم البحث الحقيقي لكل رحلة (DataForSEO) - بعد سحب الرحلات أو بالزرار
    async function measure() {
        const b = root.querySelector('#ssnVol');
        if (b) { b.disabled = true; b.textContent = T('بنقيس البحث على كل رحلة…', 'Measuring searches for each trip…'); }
        const r = await jsonPost('/api/revenue-intelligence/season/volumes', {});
        if (!r.success) { P.toast(r.error || T('تعذر قياس البحث', 'Could not measure searches'), 'error'); if (b) b.disabled = false; return; }
        const v = r.data.volumes || {};
        const why = { not_measured: T('DataForSEO مش متربط من الأدمن', 'DataForSEO is not connected in admin'), seo_monthly_budget_reached: T('ميزانية بيانات SEO الشهرية خلصت', 'Monthly SEO data budget reached'), seo_quota_and_wallet_exhausted: T('حصة بيانات SEO والمحفظة خلصوا', 'SEO data quota and wallet are used up'), no_website: T('ضيف موقعك الأول', 'Add your website first') };
        if (v.available === false) P.toast(why[v.reason] || T('القياس وقف: ', 'Measuring stopped: ') + (v.reason || ''), 'error');
        else if (v.measured) P.toast(T('اتقاس البحث على ' + nf(v.measured) + ' رحلة في ' + (v.market || ''), 'Measured searches for ' + v.measured + ' trips in ' + (v.market || '')), 'success');
        D = r.data; selected.clear(); render();
    }

    async function load(panel) {
        root = panel;
        root.innerHTML = '<div class="p-empty">' + T('جارِ تحليل الموسم…', 'Analysing the season…') + '</div>';
        const r = await P.fetchJSON('/api/revenue-intelligence/season');
        if (!r.success) { root.innerHTML = '<div class="alert alert-danger">' + esc(r.error || T('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        D = r.data;
        render();
    }

    window.TFSeasonPosts = { render: load };
})();
