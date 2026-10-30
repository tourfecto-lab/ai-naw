/* «ربط موقعك» في صفحة الربط والتكاملات: نوع الموقع (اكتشاف تلقائي) ← كود Tourfecto الواحد (بخطوات تركيب حسب النظام + الخدمات اللي بيشغّلها)
 * ← النشر التلقائي (ويزارد ووردبريس + Shopify/Webflow/Ghost/API من site-connect.js و site-connect-cms.js). القيم من السيرفر بتعدّي على esc(). */
(function () {
    'use strict';
    var root = document.getElementById('tfSiteHub');
    if (!root) return;
    var id = root.getAttribute('data-site');
    var AR = (document.documentElement.lang || 'ar').indexOf('ar') === 0;
    function L(a, e) { return AR ? a : e; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function api(url, body) {
        var o = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = csrf(); o.body = JSON.stringify(body); }
        return fetch(url, o).then(function (r) { return r.json(); }).catch(function () { return { success: false }; });
    }

    // فين يتلصق الكود في كل نظام (مرة واحدة)
    var WHERE = {
        wordpress: L('من لوحة ووردبريس: Plugins ← Add New ← ركّب «WPCode» ← Code Snippets ← Header & Footer ← الصق الكود في «Header» ← Save.', 'In WordPress: Plugins → Add New → install “WPCode” → Code Snippets → Header & Footer → paste it in “Header” → Save.'),
        shopify: L('من Shopify: Online Store ← Themes ← … ← Edit code ← theme.liquid ← الصق الكود قبل &lt;/head&gt; ← Save.', 'In Shopify: Online Store → Themes → … → Edit code → theme.liquid → paste before &lt;/head&gt; → Save.'),
        wix: L('من Wix: Settings ← Custom Code ← + Add Custom Code ← الصق الكود ← All pages ← Head ← Apply.', 'In Wix: Settings → Custom Code → + Add Custom Code → paste → All pages → Head → Apply.'),
        squarespace: L('من Squarespace: Settings ← Advanced ← Code Injection ← الصق الكود في «Header» ← Save.', 'In Squarespace: Settings → Advanced → Code Injection → paste in “Header” → Save.'),
        webflow: L('من Webflow: Site settings ← Custom code ← Head code ← الصق الكود ← Save ← Publish.', 'In Webflow: Site settings → Custom code → Head code → paste → Save → Publish.'),
        ghost: L('من Ghost: Settings ← Code injection ← Site Header ← الصق الكود ← Save.', 'In Ghost: Settings → Code injection → Site Header → paste → Save.'),
        framer: L('من Framer: Site Settings ← General ← Custom Code ← End of &lt;head&gt; tag ← الصق الكود ← Publish.', 'In Framer: Site Settings → General → Custom Code → End of &lt;head&gt; tag → paste → Publish.'),
        duda: L('من Duda: Site Settings ← Head HTML ← الصق الكود ← Save ← Republish.', 'In Duda: Site Settings → Head HTML → paste → Save → Republish.'),
        godaddy: L('من GoDaddy: Settings ← Site-wide code ← الصق الكود ← Publish.', 'In GoDaddy: Settings → Site-wide code → paste → Publish.'),
        hostinger: L('من Hostinger Website Builder: Settings ← Integrations ← Custom code ← الصق الكود ← Publish.', 'In Hostinger Website Builder: Settings → Integrations → Custom code → paste → Publish.'),
        weebly: L('من Weebly: Settings ← SEO ← Header Code ← الصق الكود ← Publish.', 'In Weebly: Settings → SEO → Header Code → paste → Publish.'),
        blogger: L('من Blogger: Theme ← Edit HTML ← الصق الكود قبل &lt;/head&gt; ← Save.', 'In Blogger: Theme → Edit HTML → paste before &lt;/head&gt; → Save.')
    };
    var CUSTOM = L('موقعك مبرمج خاص: الصق الكود قبل &lt;/head&gt; في كل صفحات الموقع (أو في الـ layout الأساسي)، أو ابعته للمبرمج بزرار واتساب.', 'Custom-built site: paste the code before &lt;/head&gt; on every page (or in the main layout), or send it to your developer.');

    var state = { platform: null, code: null, status: null };

    function render() {
        var pf = state.platform && state.platform.platform || null, c = state.code, s = state.status;
        var key = pf && pf.key || 'unknown';
        var type = !state.platform ? L('بنفحص موقعك…', 'Checking your site…')
            : pf && pf.label ? L('موقعك شغال على', 'Your site runs on') + ' <b>' + esc(pf.label) + '</b>' : L('موقعك مبرمج خاص (مالقيناش نظام جاهز)', 'Custom-built site (no known platform found)');
        var html = '<div class="tf-hub-step"><div class="tf-hub-n">1</div><div class="tf-hub-b"><b>' + type + '</b>'
            + (pf && pf.evidence ? ' <small class="p-cell-muted" dir="ltr">(' + esc(pf.evidence) + ')</small>' : '')
            + '<div class="p-cell-muted" style="font-size:12.5px">' + L('بنعرف نوع موقعك لوحدنا ونوريك أسهل طريقة تربطه.', 'We detect your site type and show the easiest way to connect it.') + '</div></div></div>';

        // ② موقع PHP مبرمج خاص: ملف واحد جنب index.php بيعمل السيو من السيرفر وبينشر المقالات على /guides
        var CMS = ['wordpress', 'shopify', 'wix', 'squarespace', 'webflow', 'ghost', 'framer', 'duda', 'godaddy', 'hostinger', 'weebly', 'blogger', 'joomla', 'drupal'];
        var phpSite = !!pf && CMS.indexOf(key) === -1 && (pf.php || pf.connector);
        if (phpSite) {
            var cn = s && s.connector;
            var badge = !cn ? '' : cn.linked ? '<span class="pill green">' + L('مربوط وشغال ✓', 'Connected ✓') + '</span>'
                : cn.present ? '<span class="pill yellow">' + L('فيه ملف قديم - بدّله', 'Old file found - replace it') + '</span>'
                : s.reachable === false ? '' : '<span class="pill gray">' + L('لسه مش متركّب', 'Not installed yet') + '</span>';
            html += '<div class="tf-hub-step"><div class="tf-hub-n">2</div><div class="tf-hub-b"><b>' + L('ملف Tourfecto جنب index.php', 'Tourfecto file next to index.php') + '</b> ' + badge
                + '<div class="p-cell-muted" style="font-size:12.5px;margin:4px 0 8px">' + L('ملف واحد بيطبّق إصلاحات السيو من السيرفر وبينشر المقالات على موقعك في ‎/guides - من غير ما يبطّأ الموقع، ولو المنصة وقفت موقعك بيفضل شغال عادي.', 'One file applies SEO fixes on the server and publishes articles on your site under /guides - without slowing it down, and your site keeps working even if the platform is unreachable.') + '</div>'
                + (cn && cn.present && !cn.linked ? '<div class="alert alert-warning" style="margin:0 0 8px;font-size:13px">' + L('موقعك عليه ملف Tourfecto بس تبع نسخة أو حساب تاني. نزّل الملف الجديد وحطه مكان القديم بنفس الاسم.', 'Your site has a Tourfecto file from another copy or account. Download the new one and put it in place of the old one with the same name.') + '</div>' : '')
                + '<a class="p-btn primary sm" href="' + esc(cn ? cn.download : '/api/website-optimizer/edge/php-connector?website_id=' + id) + '">⬇️ ' + L('نزّل tourfecto-connect.php', 'Download tourfecto-connect.php') + '</a>'
                + '<ol style="margin:10px 0;padding-inline-start:20px;line-height:1.9;font-size:13.5px"><li>' + L('ارفع الملف جنب index.php على الاستضافة (مكان القديم لو موجود).', 'Upload the file next to index.php on your hosting (replacing the old one if any).') + '</li>'
                + '<li>' + L('أول مرة بس: حط السطر ده في أول index.php:', 'First time only: add this line at the top of index.php:') + '<code class="tf-hub-code" dir="ltr" style="margin-top:4px">require_once __DIR__ . \'/tourfecto-connect.php\';</code></li>'
                + '<li>' + L('دوس «اتأكد إنه اتركّب».', 'Press “Check it is installed”.') + '</li></ol>'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="p-btn outline sm" data-hub-recheck>' + L('اتأكد إنه اتركّب', 'Check it is installed') + '</button></div>'
                + '<details style="margin-top:10px"' + (state.ftpMsg ? ' open' : '') + '><summary style="cursor:pointer;font-weight:600">' + L('مش عايز ترفع ملفات؟ المنصة تركّبه لك ببيانات FTP', 'Rather not upload files? Let the platform install it with FTP details') + '</summary>'
                + '<div class="p-cell-muted" style="font-size:12.5px;margin:6px 0">' + L('البيانات بتتستخدم مرة واحدة ومش بتتحفظ. بنعمل نسخة احتياطية من index.php، ولو الموقع اتأثر بنرجّعه زي ما كان.', 'Used once and never stored. We back up index.php and restore it if anything breaks.') + '</div>'
                + '<div style="display:grid;gap:6px;max-width:420px"><input class="form-control" id="hubFtpHost" dir="ltr" placeholder="ftp.yoursite.com"><input class="form-control" id="hubFtpUser" dir="ltr" placeholder="' + L('اسم مستخدم FTP', 'FTP username') + '" autocomplete="off"><input class="form-control" id="hubFtpPass" type="password" dir="ltr" placeholder="' + L('كلمة سر FTP', 'FTP password') + '" autocomplete="new-password"><input class="form-control" id="hubFtpPath" dir="ltr" placeholder="' + L('فولدر الموقع (اختياري) مثلًا public_html', 'Site folder (optional) e.g. public_html') + '">'
                + '<button type="button" class="p-btn primary sm" data-hub-ftp>' + L('ركّبه لي', 'Install it for me') + '</button><div id="hubFtpMsg" class="p-cell-muted" style="font-size:13px' + (state.ftpMsg ? ';color:' + (state.ftpMsg.ok ? 'var(--panel-success,#16a34a)' : 'var(--panel-danger,#dc2626)') : '') + '">' + (state.ftpMsg ? esc(state.ftpMsg.text) : '') + '</div></div></details></div></div>';
        }

        // ② (أو ③ لموقع PHP) الكود الواحد - في موقع PHP بيبقى للشات بس
        html += '<div class="tf-hub-step"><div class="tf-hub-n">' + (phpSite ? '3' : '2') + '</div><div class="tf-hub-b"><b>' + (phpSite ? L('شات الموقع (سطر واحد - اختياري)', 'Website chat (one line - optional)') : L('كود Tourfecto (مرة واحدة)', 'Tourfecto code (once)')) + '</b> '
            + (s && s.seo_code ? (s.seo_code.installed === true ? '<span class="pill green">' + L('متركّب على موقعك ✓', 'Installed ✓') + '</span>' : s.reachable === false ? '' : '<span class="pill gray">' + L('لسه مش متركّب', 'Not installed yet') + '</span>') : '')
            + '<div class="p-cell-muted" style="font-size:12.5px;margin:4px 0 8px">' + (phpSite ? L('لو عايز السياح يكلموك من موقعك: سطر واحد قبل &lt;/head&gt;، والشات بيرد بالذكاء الاصطناعي.', 'Want travellers to chat from your site? One line before &lt;/head&gt; and the AI chat answers.') : L('سطر واحد بيشغّل كل خدمات المنصة على موقعك. شغّل اللي عايزه من هنا من غير ما تلمس الكود تاني:', 'One line runs every platform service on your site. Turn services on here without touching the code again:')) + '</div>';
        if (!c) {
            html += '<div class="p-cell-muted">' + L('بنجهّز الكود…', 'Preparing your code…') + '</div>';
        } else {
            html += '<div class="tf-hub-toggles">'
                + (phpSite ? '' : toggle('seo', c.seo, L('إصلاحات السيو التلقائية', 'Automatic SEO fixes'), L('العناوين والوصف والـ Schema بتتظبط لوحدها', 'Titles, descriptions and schema fixed automatically')))
                + toggle('chat', c.chat, L('شات الموقع بالذكاء الاصطناعي', 'AI website chat'), L('السائح يكلمك من موقعك والرسايل في صندوق الوارد', 'Travellers chat from your site, messages land in your inbox'))
                + '</div>'
                + '<code class="tf-hub-code" dir="ltr">' + esc(c.code) + '</code>'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">'
                + '<button type="button" class="p-btn primary sm" data-hub-copy>📋 ' + L('انسخ الكود', 'Copy the code') + '</button>'
                + '<a class="p-btn outline sm" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(L('محتاجين نحط الكود ده قبل </head> في كل صفحات موقعنا ', 'Please add this code before </head> on every page of our site ') + (state.platform && state.platform.url || '') + ':\n\n' + c.code) + '">📤 ' + L('ابعته للمبرمج على واتساب', 'Send it to your developer on WhatsApp') + '</a>'
                + '<button type="button" class="p-btn ghost sm" data-hub-recheck>' + L('اتأكد إنه اتركّب', 'Check it is installed') + '</button></div>'
                + '<div class="alert alert-info" style="margin:0;font-size:13px;line-height:1.8">' + (WHERE[key] || CUSTOM) + '</div>';
        }
        html += '</div></div>';

        // ③ النشر - الويزارد اللي تحت (site-connect.js / site-connect-cms.js)
        html += '<div class="tf-hub-step"><div class="tf-hub-n">' + (phpSite ? '4' : '3') + '</div><div class="tf-hub-b"><b>' + L('النشر التلقائي على موقعك', 'Auto-publishing to your site') + '</b> '
            + (s && s.publish ? (s.publish.connected ? '<span class="pill green">' + L('مربوط', 'Connected') + '</span> <small class="p-cell-muted">' + esc(s.publish.name) + '</small>' : '<span class="pill gray">' + L('مش مربوط', 'Not connected') + '</span>') : '')
            + '<div class="p-cell-muted" style="font-size:12.5px">' + (key === 'wordpress' ? L('موقعك ووردبريس: اربطه بضغطة تحت، والمقالات والإصلاحات بتنزل عليه لوحدها.', 'WordPress: connect it in one click below and articles and fixes go live on their own.')
                : ['shopify', 'webflow', 'ghost'].indexOf(key) !== -1 ? L('اربطه تحت بالخطوات اللي ظاهرة لنظامك، والمقالات بتنزل عليه لوحدها.', 'Connect it below with the steps for your platform; articles publish on their own.')
                : phpSite ? L('مقالات مركز السيو بتتنشر على موقعك من الملف (‎/guides) لوحدها. ولمقالات «مقالات SEO» اختار تحت.', 'SEO-center articles publish on your site through the file (/guides) on their own. For “SEO articles”, pick below.')
                : L('اختار تحت: مدونة جاهزة من غير مبرمج (الأسهل)، أو في مدونة موقعك بملف جاهز للمبرمج، أو تنسخ المقالات بنفسك.', 'Pick below: a ready blog with no developer (easiest), your own blog with a ready file for your developer, or copy articles yourself.'))
            + (state.platform && key !== 'wordpress' && !state.showWp ? ' <button type="button" class="p-btn ghost xs" data-hub-wp>' + L('موقعي ووردبريس فعلًا', 'My site is WordPress') + '</button>' : '') + '</div></div></div>';
        root.innerHTML = html;
        // ويزارد ووردبريس بيظهر بس لموقع ووردبريس (أو لو العميل قال إن موقعه ووردبريس والاكتشاف ماشافهوش)
        var wp = document.getElementById('siteConnect');
        if (wp && state.platform) wp.style.display = key === 'wordpress' || state.showWp ? '' : 'none';
    }
    function toggle(svc, on, title, sub) {
        return '<label class="tf-hub-toggle"><input type="checkbox" data-hub-svc="' + svc + '"' + (on ? ' checked' : '') + '><span><b>' + title + '</b><small>' + sub + '</small></span></label>';
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-hub-copy]');
        if (b && state.code) {
            (navigator.clipboard ? navigator.clipboard.writeText(state.code.code) : Promise.reject()).then(function () { b.textContent = '✔ ' + L('اتنسخ', 'Copied'); }).catch(function () {});
            return;
        }
        if (e.target.closest('[data-hub-recheck]')) { state.status = null; render(); loadStatus(); }
        if (e.target.closest('[data-hub-wp]')) { state.showWp = true; render(); }
        var ftp = e.target.closest('[data-hub-ftp]');
        if (ftp) {
            var v = function (i) { var el = document.getElementById(i); return el ? el.value.trim() : ''; };
            var out = document.getElementById('hubFtpMsg');
            ftp.disabled = true; out.textContent = L('بنركّب الملف…', 'Installing…');
            api('/api/website-optimizer/edge/php-auto-install', { website_id: id, host: v('hubFtpHost'), username: v('hubFtpUser'), password: document.getElementById('hubFtpPass').value, path: v('hubFtpPath') }).then(function (r) {
                ftp.disabled = false;
                state.ftpMsg = { ok: !!(r && r.success), text: (r && (r.message || r.error)) || L('تعذر التركيب', 'Could not install') };
                render();
                if (r && r.success) loadStatus();
            });
        }
    });
    root.addEventListener('change', function (e) {
        var t = e.target.closest('[data-hub-svc]'); if (!t) return;
        t.disabled = true;
        api('/api/websites/' + id + '/site-code', { service: t.getAttribute('data-hub-svc'), on: t.checked }).then(function (r) {
            t.disabled = false;
            if (r && r.success) { state.code = r.data; render(); }
            else { t.checked = !t.checked; alert((r && r.error) || L('تعذر الحفظ', 'Could not save')); }
        });
    });

    function loadStatus() { api('/api/websites/' + id + '/connect-status?check=1').then(function (r) { if (r && r.success) { state.status = r.data; render(); } }); }
    render();
    api('/api/site-connect/' + id).then(function (r) { state.platform = r && r.success ? r.data : { platform: null }; render(); });
    api('/api/websites/' + id + '/site-code').then(function (r) { if (r && r.success) { state.code = r.data; render(); } });
    loadStatus();
})();
