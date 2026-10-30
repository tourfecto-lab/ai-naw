/* «اربط موقعك» حسب النظام اللي اكتشفناه: Shopify / Webflow / Ghost / Webhook / نسخ جاهز.
 * ووردبريس ليه الويزارد بتاعه (site-connect.js) فوق - الموديول ده بيظهر بس لو الموقع مش ووردبريس أو مربوط بطريقة تانية. */
(function () {
    const root = document.getElementById('siteConnectCms');
    if (!root || !window.Panel) return;
    const P = window.Panel;
    const id = root.getAttribute('data-site');
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const API = '/api/site-connect/' + encodeURIComponent(id);
    let D = null;
    let method = null;

    const METHODS = {
        shopify: { icon: '🛍️', name: 'Shopify', sub: t('مدونة المتجر', 'Store blog') },
        webflow: { icon: '🧩', name: 'Webflow', sub: t('CMS المدونة', 'Blog CMS') },
        ghost: { icon: '👻', name: 'Ghost', sub: t('Admin API', 'Admin API') },
        webhook: { icon: '🔗', name: t('موقع مبرمج خاص', 'Custom-built site'), sub: 'Webhook / API' },
        hosted: { icon: '📰', name: t('مدونة جاهزة من Tourfecto', 'Hosted blog by Tourfecto'), sub: t('من غير أي ربط', 'No setup needed') },
        copy: { icon: '📋', name: t('نسخ جاهز', 'Ready to copy'), sub: t('أي نظام', 'Any platform') },
    };
    const HB = '/api/hosted-blog/' + encodeURIComponent(id);
    let H = null;
    const STEPS = {
        shopify: [t('في لوحة Shopify: Settings ← Apps and sales channels ← Develop apps', 'In Shopify admin: Settings → Apps and sales channels → Develop apps'),
            t('Create an app ← سمّيه Tourfecto ← Configure Admin API scopes', 'Create an app → name it Tourfecto → Configure Admin API scopes'),
            t('علّم على read_content و write_content واحفظ، وبعدين Install app', 'Tick read_content and write_content, save, then Install app'),
            t('انسخ «Admin API access token» (بيبدأ بـ shpat_) والصقه هنا', 'Copy the “Admin API access token” (starts with shpat_) and paste it here')],
        webflow: [t('في Webflow: Site settings ← Apps & integrations ← API access', 'In Webflow: Site settings → Apps & integrations → API access'),
            t('Generate API token واختار CMS: Read and write', 'Generate API token with CMS: Read and write'),
            t('الصق التوكن هنا واختار مجموعة المدونة (Blog Posts)', 'Paste the token here and pick your Blog Posts collection')],
        ghost: [t('في Ghost: Settings ← Integrations ← Add custom integration', 'In Ghost: Settings → Integrations → Add custom integration'),
            t('سمّيها Tourfecto وانسخ «Admin API key»', 'Name it Tourfecto and copy the “Admin API key”'),
            t('الصق رابط موقع Ghost والمفتاح هنا', 'Paste your Ghost site URL and the key here')],
    };

    async function call(path, body) {
        return P.fetchJSON(API + path, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
    }

    function header() {
        const pf = D.platform || {};
        const booking = (pf.booking || []).filter(b => b !== 'WhatsApp');
        return `<div class="scc-detect"><span class="scc-ic">🔍</span><div>
            <b>${pf.label ? t('موقعك شغال على', 'Your site runs on') + ' ' + esc(pf.label) : t('مقدرناش نحدد نظام موقعك تلقائي', "We couldn't identify your site's platform")}</b>
            ${pf.evidence ? `<small dir="ltr">${t('لقينا', 'found')}: ${esc(pf.evidence)}</small>` : ''}
            ${booking.length ? `<small>🎟️ ${t('نظام الحجز', 'Booking system')}: ${booking.map(esc).join(' · ')}</small>` : ''}
        </div><button type="button" class="p-btn outline xs" data-scc-refresh>${t('افحص تاني', 'Re-check')}</button></div>`;
    }

    function form(m) {
        const steps = STEPS[m] ? `<ol class="scc-steps">${STEPS[m].map(s => `<li>${esc(s)}</li>`).join('')}</ol>` : '';
        if (m === 'shopify') return steps + `<label class="form-label">${t('اسم المتجر', 'Store address')}</label><input class="form-control" id="sccShop" dir="ltr" placeholder="yourstore.myshopify.com">
            <label class="form-label">Admin API access token</label><input class="form-control" id="sccToken" dir="ltr" placeholder="shpat_…" autocomplete="off">
            <div id="sccPick"></div><button type="button" class="p-btn primary" data-scc-go="shopify">${t('اربط المتجر', 'Connect store')}</button>`;
        if (m === 'webflow') return steps + `<label class="form-label">API token</label><input class="form-control" id="sccToken" dir="ltr" autocomplete="off">
            <div id="sccPick"></div><button type="button" class="p-btn primary" data-scc-go="webflow">${t('اربط Webflow', 'Connect Webflow')}</button>`;
        if (m === 'ghost') return steps + `<label class="form-label">${t('رابط موقع Ghost', 'Ghost site URL')}</label><input class="form-control" id="sccUrl" dir="ltr" placeholder="https://blog.yoursite.com">
            <label class="form-label">Admin API key</label><input class="form-control" id="sccKey" dir="ltr" placeholder="id:secret" autocomplete="off">
            <button type="button" class="p-btn primary" data-scc-go="ghost">${t('اربط Ghost', 'Connect Ghost')}</button>`;
        if (m === 'webhook') {
            const sample = JSON.stringify({ title: 'Luxor Day Tours: …', content_html: '<p>…</p>', content_markdown: '…', meta_description: '…', slug: 'luxor-day-tours', suggested_keywords: ['luxor day tours'], article_id: 123, is_test: false, source: 'tourfecto' }, null, 2);
            const mail = 'mailto:?subject=' + encodeURIComponent(t('ربط موقعنا بـ Tourfecto', 'Connect our site to Tourfecto')) + '&body=' + encodeURIComponent(t('محتاجين endpoint (POST, https) بيستقبل JSON زي ده وينشر المقال ويرجع 200 و {"url":"رابط المقال"}:\n\n', 'We need an HTTPS POST endpoint that accepts JSON like this, publishes the article and returns 200 with {"url":"article URL"}:\n\n') + sample + t('\n\nالطلب فيه Authorization: Bearer <SECRET> للتأكد إنه من Tourfecto. لما is_test=true ماتنشروش.', '\n\nRequests carry Authorization: Bearer <SECRET>. When is_test=true, do not publish.'));
            // ملف جاهز بدل ما المبرمج يكتب من الصفر: يحطه على الموقع ويعدّل سطر الحفظ في مدونتهم
            const receiver = "<?php\n// tourfecto-receive.php - Tourfecto بيبعت كل مقال هنا\n$secret = 'PUT_THE_SECRET_HERE';\n$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';\nif (!hash_equals('Bearer ' . $secret, $auth)) { http_response_code(401); exit; }\n$a = json_decode(file_get_contents('php://input'), true) ?: [];\nheader('Content-Type: application/json');\nif (!empty($a['is_test'])) { echo json_encode(['ok' => true]); exit; }\n// TODO: save to your blog: $a['title'], $a['content_html'], $a['slug'], $a['meta_description']\n$url = 'https://' . $_SERVER['HTTP_HOST'] . '/blog/' . rawurlencode($a['slug'] ?? '');\necho json_encode(['url' => $url]);\n";
            const wa = 'https://wa.me/?text=' + encodeURIComponent(t('محتاجين نربط مدونة موقعنا بـ Tourfecto عشان المقالات تتنشر لوحدها. ده ملف جاهز: حطه على الموقع (مثلًا /api/tourfecto-receive.php) وكمّل سطر الحفظ في مدونتنا، وابعتلي الرابط:\n\n', 'Please connect our site blog to Tourfecto so articles publish automatically. Here is a ready file: put it on the site (e.g. /api/tourfecto-receive.php), finish the save line for our blog, and send me the URL:\n\n') + receiver);
            return `<p class="scc-p">${t('المبرمج بتاعك يحط ملف واحد على الموقع (جاهز تحت) ويكمّل سطر الحفظ — وإحنا بنبعتله كل مقال لوحده.', 'Your developer puts one ready file on the site (below) and finishes the save line — we send every article to it automatically.')}</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><a class="p-btn primary xs" href="${wa}" target="_blank" rel="noopener">📤 ${t('ابعته للمبرمج على واتساب', 'Send it to your developer on WhatsApp')}</a>
                <a class="p-btn outline xs" href="${mail}">✉️ ${t('أو بالإيميل', 'Or by email')}</a></div>
                <details class="scc-dev"><summary>${t('الملف الجاهز (PHP)', 'Ready file (PHP)')}</summary><pre dir="ltr">${esc(receiver)}</pre></details>
                <details class="scc-dev"><summary>${t('شكل البيانات اللي بتتبعت', 'Payload format')}</summary><pre dir="ltr">${esc(sample)}</pre></details>
                <label class="form-label" style="margin-top:12px">${t('رابط الاستقبال (https)', 'Endpoint URL (https)')}</label><input class="form-control" id="sccEndpoint" dir="ltr" placeholder="https://yoursite.com/api/tourfecto">
                <label class="form-label">${t('المفتاح السري (اختياري — هنعمله لو فاضي)', 'Secret (optional — we generate one)')}</label><input class="form-control" id="sccSecret" dir="ltr" autocomplete="off">
                <button type="button" class="p-btn primary" data-scc-go="webhook">${t('اختبر واربط', 'Test & connect')}</button>`;
        }
        if (m === 'hosted') {
            const pf = D.platform || {};
            return `<p class="scc-p">${pf.label ? esc(pf.label) + ' ' + t('مش بيسمح بالنشر التلقائي، فبنعملك مدونة احترافية شغالة فورًا والمقالات بتتنشر عليها لوحدها:', "doesn't allow auto-publishing, so we give you a professional blog that's live instantly and articles publish to it automatically:") : t('مدونة احترافية شغالة فورًا والمقالات بتتنشر عليها لوحدها:', 'A professional blog that is live instantly, with articles publishing to it automatically:')}</p>
                <ul class="scc-steps"><li>${t('بلوجو واسم شركتك، ولينك «احجز» لموقعك في كل مقال', 'Your logo and name, with a “Book” link to your site in every article')}</li>
                <li>${t('جاهزة لجوجل: خريطة موقع وبيانات منظمة وروابط canonical', 'Google-ready: sitemap, structured data and canonical links')}</li>
                <li>${t('ممكن بعدين تخليها على دومينك (blog.موقعك.com)', 'Optionally move it to your own domain later (blog.yoursite.com)')}</li></ul>
                <button type="button" class="p-btn primary" data-hb-activate>${t('فعّل المدونة', 'Activate the blog')}</button>`;
        }
        const pf = D.platform || {};
        return `<p class="scc-p">${pf.label ? esc(pf.label) + ' ' + t('مش بيسمح بالنشر التلقائي من منصات تانية بشكل مضمون، فبنجهزلك كل مقال كامل:', "doesn't reliably allow auto-publishing from other platforms, so every article arrives complete:") : t('كل مقال بيوصلك كامل:', 'Every article arrives complete:')}</p>
            <ol class="scc-steps"><li>${t('افتح المقال من «مقالات SEO»', 'Open the article in “SEO articles”')}</li><li>${t('اضغط «نسخ» — العنوان والفقرات والعناوين الفرعية بتتنسخ زي ما هي', 'Tap “Copy” — title, paragraphs and headings copy as they are')}</li><li>${t('الصقه في محرر المدونة عندك واضغط نشر', 'Paste it into your blog editor and publish')}</li></ol>
            <a class="p-btn primary" href="/ai/articles">${t('افتح المقالات', 'Open articles')}</a>`;
    }

    const DOMAIN_STATE = {
        pending_dns: ['#d97706', t('مستني سجلات الـDNS', 'Waiting for DNS records')],
        verified: ['#2563eb', t('اتأكد ✓ — فريقنا بيفعّله خلال يوم عمل', 'Verified ✓ — our team switches it on within one business day')],
        live: ['#16a34a', t('شغال على دومينك ✓', 'Live on your domain ✓')],
    };
    function hostedPanel() {
        if (!H) return `<div class="obs-loading">…</div>`;
        const st = DOMAIN_STATE[H.domain_state];
        return `<div class="scc-ok">✅ <b>${t('مدونتك شغالة', 'Your blog is live')}</b> · 📰 <a href="${esc(H.url)}" target="_blank" rel="noopener" dir="ltr">${esc(H.url.replace(/^https?:\/\//, ''))}</a>
                <small>${H.posts} ${t('مقال منشور · المقالات الجديدة بتتنشر عليها تلقائي', 'published · new articles publish here automatically')}</small></div>
            ${H.ready_articles ? `<div class="scc-p" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">📝 ${t('عندك', 'You have')} <b>${H.ready_articles}</b> ${t('مقال جاهز لسه ماتنشرش', 'ready articles not yet published')}
                <button type="button" class="p-btn primary xs" data-hb-ready>${t('انشرهم على المدونة', 'Publish them to the blog')}</button>
                <small style="width:100%;opacity:.75">${t('لو كنت نسختهم على موقعك قبل كده، ماتنشرهمش تاني عشان جوجل مايشوفش محتوى مكرر.', 'If you already copied them to your site, skip this so Google doesn’t see duplicate content.')}</small></div>` : ''}
            <details class="scc-others"${H.domain ? ' open' : ''}><summary>🌐 ${t('خليها على دومينك (اختياري) — blog.موقعك.com', 'Use your own domain (optional) — blog.yoursite.com')}</summary>
                <div style="padding-top:10px">
                    <div style="display:flex;gap:8px"><input class="form-control" id="hbDomain" dir="ltr" placeholder="blog.yoursite.com" value="${esc(H.domain || '')}"><button type="button" class="p-btn outline" data-hb-domain>${t('حفظ', 'Save')}</button></div>
                    ${st ? `<p style="margin:10px 0 6px;font-weight:600;color:${st[0]}">● ${st[1]}</p>` : ''}
                    ${H.domain_state === 'pending_dns' ? `<p class="scc-p">${t('ضيف السجلين دول عند مزوّد الدومين (GoDaddy / Namecheap / Cloudflare…):', 'Add these two records at your domain provider (GoDaddy / Namecheap / Cloudflare…):')}</p>
                        <table class="p-table" dir="ltr" style="font-size:12.5px"><tr><th>Type</th><th>Name</th><th>Value</th></tr>${H.dns.map(r => `<tr><td>${r.type}</td><td><code>${esc(r.name)}</code></td><td><code>${esc(r.value)}</code></td></tr>`).join('')}</table>
                        <button type="button" class="p-btn primary xs" style="margin-top:10px" data-hb-verify>${t('اتأكد دلوقتي', 'Check now')}</button>
                        <small style="display:block;opacity:.75;margin-top:6px">${t('لو Cloudflare: خلّي السحابة رمادي (DNS only) للـCNAME.', 'On Cloudflare: set the CNAME to grey cloud (DNS only).')}</small>` : ''}
                </div></details>`;
    }

    function render() {
        const c = D.connection;
        if (c && c.kind === 'hosted') {
            const own = D.platform && D.platform.blog_url ? `<div class="scc-p" style="padding:10px 12px;border-radius:10px;background:rgba(234,179,8,.12)">⚠️ ${t('موقعك عنده مدونته الخاصة', 'Your site has its own blog')} (<a href="${esc(D.platform.blog_url)}" target="_blank" rel="noopener" dir="ltr">${esc(D.platform.blog_url.replace(/^https?:\/\//, ''))}</a>) — ${t('المقالات دلوقتي بتتنشر على مدونة Tourfecto المنفصلة. لو عايزها تظهر في مدونة موقعك: «وقف النشر على المدونة» تحت، واختار «في مدونة موقعك الحالية» وابعت الملف الجاهز للمبرمج.', 'articles currently publish to the separate Tourfecto blog. To show them on your own blog: “Stop publishing to the blog” below, choose “In your current site blog” and send the ready file to your developer.')}</div>` : '';
            root.innerHTML = `<div class="p-card scc">${header()}${own}${hostedPanel()}<div id="sccMsg" class="scc-msg" hidden></div>
                <button type="button" class="p-btn danger xs" data-scc-disc>${t('وقف النشر على المدونة', 'Stop publishing to the blog')}</button></div>`;
            if (!H) loadHosted();
            return;
        }
        if (c && c.kind !== 'wordpress') {
            const m = METHODS[c.kind] || METHODS.webhook;
            root.innerHTML = `<div class="p-card scc">${header()}<div class="scc-ok">✅ <b>${t('موقعك مربوط', 'Your site is connected')}</b> · ${m.icon} ${esc(c.name)}
                <small>${t('المقالات بتتنشر عليه تلقائي', 'Articles publish to it automatically')}${c.last_error ? ' · ⚠️ ' + esc(c.last_error) : ''}</small></div>
                <button type="button" class="p-btn danger xs" data-scc-disc>${t('إلغاء الربط', 'Disconnect')}</button></div>`;
            return;
        }
        if (D.method === 'wordpress' && !method) { root.innerHTML = ''; return; }
        const hasBlog = !!(D.platform && D.platform.blog_url);
        // موقع مبرمج خاص (أو نظام مش معروف): كان بيبدأ بـ«المبرمج يعمل رابط استقبال» وده أصعب حاجة.
        // دلوقتي العميل يختار، والأسهل (من غير مبرمج) الأول وعليه قد إيه بياخد مجهود.
        if (!method && D.method === 'copy') {
            const opt = (k, title, sub, badge) => `<button type="button" class="scc-opt" data-scc-method="${k}"><span>${METHODS[k].icon}</span><b>${title}</b><small>${sub}</small>${badge ? `<em class="pill ${badge[0]}" style="margin-top:6px">${badge[1]}</em>` : ''}</button>`;
            root.innerHTML = `<div class="p-card scc">${header()}
                <h4 class="scc-h">${t('المقالات تتنشر فين؟ اختار الأسهل ليك', 'Where should articles publish? Pick what is easiest for you')}</h4>
                <div class="scc-grid">
                    ${opt('hosted', t('مدونة جاهزة - من غير مبرمج', 'Ready blog - no developer'), t('شغالة في دقيقة بلوجو شركتك، وممكن تبقى على blog.موقعك.com', 'Live in a minute with your logo, can sit on blog.yoursite.com'), ['green', t('الأسهل · دقيقة', 'Easiest · 1 minute')])}
                    ${opt('webhook', t('في مدونة موقعك الحالية', 'In your current site blog'), hasBlog ? t('على', 'On') + ' ' + esc(D.platform.blog_url.replace(/^https?:\/\//, '')) + ' - ' + t('المبرمج يضيف ملف واحد مرة واحدة', 'your developer adds one file, once') : t('المبرمج يضيف ملف واحد مرة واحدة', 'Your developer adds one file, once'), ['gray', t('محتاج المبرمج مرة', 'Developer once')])}
                    ${opt('copy', t('أنسخها بنفسي', 'I copy them myself'), t('كل مقال بيوصلك جاهز تلصقه في محرر موقعك', 'Every article arrives ready to paste in your editor'), null)}
                </div></div>`;
            return;
        }
        const m = method || (D.method === 'copy' ? (hasBlog ? 'webhook' : 'hosted') : D.method);
        const others = Object.keys(METHODS).filter(k => k !== m);
        root.innerHTML = `<div class="p-card scc">${header()}
            <h4 class="scc-h">${METHODS[m].icon} ${t('أنسب طريقة لموقعك', 'Best way to connect your site')}: ${esc(METHODS[m].name)}</h4>
            ${hasBlog && (m === 'webhook' || m === 'hosted') ? `<div class="scc-p" style="padding:10px 12px;border-radius:10px;background:rgba(234,179,8,.12)">📝 ${t('موقعك فيه مدونة بالفعل:', 'Your site already has a blog:')} <a href="${esc(D.platform.blog_url)}" target="_blank" rel="noopener" dir="ltr">${esc(D.platform.blog_url.replace(/^https?:\/\//, ''))}</a> — ${m === 'hosted' ? t('مدونة Tourfecto هتبقى منفصلة عنها ومقالاتها مش هتظهر في مدونة موقعك.', 'a Tourfecto blog would be separate from it and its articles won’t appear on your site’s blog.') : t('الأفضل المقالات تتنشر فيها هي: المبرمج بتاعك يعمل رابط استقبال واحد (الخطوات تحت).', 'articles should publish there: your developer adds one receiving endpoint (steps below).')}</div>` : ''}
            <div class="scc-form">${form(m)}</div><div id="sccMsg" class="scc-msg" hidden></div>
            <details class="scc-others"><summary>${t('موقعك على نظام تاني؟ اختار طريقة الربط', 'Different platform? Choose another method')}</summary>
                <div class="scc-grid">${others.map(k => `<button type="button" class="scc-opt" data-scc-method="${k}"><span>${METHODS[k].icon}</span><b>${esc(METHODS[k].name)}</b><small>${esc(METHODS[k].sub)}</small></button>`).join('')}</div></details>
        </div>`;
    }

    function msg(text, ok) {
        const el = document.getElementById('sccMsg');
        if (!el) return;
        el.hidden = false;
        el.className = 'scc-msg ' + (ok ? 'ok' : 'bad');
        el.textContent = text;
    }

    async function go(kind, btn) {
        const v = sel => ((document.getElementById(sel) || {}).value || '').trim();
        let body = {};
        if (kind === 'shopify') body = { shop: v('sccShop'), token: v('sccToken'), blog_id: v('sccPickSel') || 0 };
        if (kind === 'webflow') body = { token: v('sccToken'), collection_id: v('sccPickSel') };
        if (kind === 'ghost') body = { url: v('sccUrl'), key: v('sccKey') };
        if (kind === 'webhook') body = { endpoint: v('sccEndpoint'), secret: v('sccSecret') };
        btn.disabled = true;
        const res = await call('/' + kind, body);
        btn.disabled = false;
        if (!res || !res.success) { msg((res && res.error) || t('تعذر الربط', 'Could not connect'), false); return; }
        const d = res.data || {};
        if (d.step === 'pick_blog' || d.step === 'pick_collection') {
            const list = d.blogs || d.collections || [];
            document.getElementById('sccPick').innerHTML = `<label class="form-label">${d.step === 'pick_blog' ? t('المدونة اللي هننشر فيها', 'Blog to publish into') : t('مجموعة المدونة', 'Blog collection')}</label>
                <select class="form-control" id="sccPickSel">${list.map(x => `<option value="${esc(x.id)}">${esc(x.title)}</option>`).join('')}</select>`;
            msg(t('اتصلنا بنجاح — اختار وكمّل', 'Connected — choose and continue'), true);
            return;
        }
        if (d.secret && kind === 'webhook') msg(t('المفتاح السري (احفظه عند المبرمج): ', 'Secret (give it to your developer): ') + d.secret, true);
        P.toast(res.message || t('اتربط', 'Connected'), 'success');
        await load();
    }

    async function loadHosted() {
        const res = await P.fetchJSON(HB);
        if (res && res.success) { H = res.data; render(); }
    }
    async function hbCall(path, body, btn) {
        if (btn) btn.disabled = true;
        const res = await P.fetchJSON(HB + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        if (btn) btn.disabled = false;
        if (!res || !res.success) { msg((res && res.error) || t('حصل خطأ', 'Something went wrong'), false); return null; }
        H = res.data;
        if (res.message) P.toast(res.message, 'success');
        return res;
    }

    root.addEventListener('click', async e => {
        const ha = e.target.closest('[data-hb-activate]');
        if (ha) { if (await hbCall('/activate', {}, ha)) await load(); return; }
        const hr = e.target.closest('[data-hb-ready]');
        if (hr) { if (await hbCall('/publish-ready', {}, hr)) render(); return; }
        const hd = e.target.closest('[data-hb-domain]');
        if (hd) { if (await hbCall('/domain', { domain: ((document.getElementById('hbDomain') || {}).value || '').trim() }, hd)) render(); return; }
        const hv = e.target.closest('[data-hb-verify]');
        if (hv) { if (await hbCall('/verify', {}, hv)) render(); return; }
        const g = e.target.closest('[data-scc-go]');
        if (g) { go(g.getAttribute('data-scc-go'), g); return; }
        const m = e.target.closest('[data-scc-method]');
        if (m) { method = m.getAttribute('data-scc-method'); render(); return; }
        if (e.target.closest('[data-scc-refresh]')) { root.querySelector('.scc') && root.querySelector('.scc').classList.add('busy'); await load(true); return; }
        const disc = e.target.closest('[data-scc-disc]');
        if (disc) {
            // تأكيد جوه الصفحة بدل confirm(): بعض المتصفحات (والمتصفح المدمج) بتقفل نافذة التأكيد فالزرار مكانش بيعمل حاجة
            if (!disc.dataset.armed) {
                disc.dataset.armed = '1';
                disc.dataset.label = disc.textContent;
                disc.textContent = t('متأكد؟ المقالات هتبطّل تتنشر لوحدها - دوس تاني للتأكيد', 'Sure? Articles stop auto-publishing - click again to confirm');
                setTimeout(() => { if (disc.isConnected && disc.dataset.armed) { delete disc.dataset.armed; disc.textContent = disc.dataset.label; } }, 5000);
                return;
            }
            disc.disabled = true;
            const r = await call('/disconnect', {});
            if (!r || !r.success) { disc.disabled = false; delete disc.dataset.armed; disc.textContent = disc.dataset.label; msg((r && r.error) || t('تعذر الإيقاف', 'Could not stop'), false); return; }
            H = null;
            await load();
        }
    });

    async function load(refresh) {
        const res = await P.fetchJSON(API + (refresh ? '?refresh=1' : ''));
        if (!res || !res.success) return;
        D = res.data;
        render();
    }
    load();
})();
