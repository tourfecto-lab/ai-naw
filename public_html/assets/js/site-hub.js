/* «ربط موقعك» في صفحة الربط والتكاملات: حاجة واحدة تتركّب حسب نوع الموقع وبعدها كل حاجة بتتشغّل من هنا.
 *   موقع PHP مبرمج خاص ← ملف tourfecto-connect.php جنب index.php (السيو من السيرفر + الشات + نشر المقالات على /guides)
 *   ووردبريس          ← إضافة Tourfecto (السيو + الشات) + النشر بضغطة (site-connect.js)
 *   أي نظام تاني       ← كود Tourfecto سطر واحد (السيو + الشات) + طريقة النشر المناسبة (site-connect-cms.js)
 * القيم من السيرفر بتعدّي على esc(). */
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

    // فين يتلصق الكود في الأنظمة الجاهزة (مرة واحدة)
    var WHERE = {
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
    var CUSTOM = L('الصق الكود قبل &lt;/head&gt; في كل صفحات الموقع (أو في الـ layout الأساسي)، أو ابعته للمبرمج بزرار واتساب.', 'Paste the code before &lt;/head&gt; on every page (or in the main layout), or send it to your developer.');
    var CMS = ['wordpress', 'shopify', 'wix', 'squarespace', 'webflow', 'ghost', 'framer', 'duda', 'godaddy', 'hostinger', 'weebly', 'blogger', 'joomla', 'drupal'];

    var state = { platform: null, code: null, status: null };

    function step(n, title, badge, body) {
        return '<div class="tf-hub-step"><div class="tf-hub-n">' + n + '</div><div class="tf-hub-b"><b>' + title + '</b> ' + (badge || '') + body + '</div></div>';
    }
    function muted(t) { return '<div class="p-cell-muted" style="font-size:12.5px;margin:4px 0 8px">' + t + '</div>'; }
    function toggle(attr, on, title, sub, disabled) {
        return '<label class="tf-hub-toggle"><input type="checkbox" ' + attr + (on ? ' checked' : '') + (disabled ? ' disabled' : '') + '><span><b>' + title + '</b><small>' + sub + '</small></span></label>';
    }
    // ملف الربط / إضافة ووردبريس: بيكلّمنا من الموقع وبيطبع meta tourfecto-connector
    function fileBadge(s) {
        var cn = s && s.connector;
        if (!cn) return '';
        if (cn.linked) return '<span class="pill green">' + L('مربوط وشغال ✓', 'Connected ✓') + '</span>';
        if (cn.present) return '<span class="pill yellow">' + L('فيه نسخة قديمة - بدّلها', 'Old copy found - replace it') + '</span>';
        return s.reachable === false ? '' : '<span class="pill gray">' + L('لسه مش متركّب', 'Not installed yet') + '</span>';
    }

    function render() {
        var pf = state.platform && state.platform.platform || null, c = state.code, s = state.status;
        var key = pf && pf.key || 'unknown';
        var mode = !pf ? 'code' : key === 'wordpress' || state.showWp ? 'wordpress' : CMS.indexOf(key) === -1 && (pf.php || pf.connector) ? 'php' : 'code';
        var conn = state.platform && state.platform.connection || null;
        var linked = !!(s && s.connector && s.connector.linked);
        var type = !state.platform ? L('بنفحص موقعك…', 'Checking your site…')
            : mode === 'php' ? L('موقعك مبرمج خاص بـ PHP', 'Custom PHP site')
            : pf && pf.label ? L('موقعك شغال على', 'Your site runs on') + ' <b>' + esc(pf.label) + '</b>' : L('موقعك مبرمج خاص (مالقيناش نظام جاهز)', 'Custom-built site (no known platform found)');
        var html = step(1, type, '', (pf && pf.evidence ? ' <small class="p-cell-muted" dir="ltr">(' + esc(pf.evidence) + ')</small>' : '')
            + muted(L('بنعرف نوع موقعك لوحدنا، وبتركّب حاجة واحدة بس، وبعدها كل حاجة بتتشغّل من هنا.', 'We detect your site type; you install one thing, then everything is switched on from here.')));

        if (mode === 'php' || mode === 'wordpress') {
            var php = mode === 'php';
            var cn = s && s.connector;
            var dl = php ? (cn ? cn.download : '/api/website-optimizer/edge/php-connector?website_id=' + id) : '/api/website-optimizer/edge/plugin?website_id=' + id;
            var install = '<a class="p-btn primary sm" href="' + esc(dl) + '">⬇️ ' + (php ? L('نزّل tourfecto-connect.php', 'Download tourfecto-connect.php') : L('نزّل إضافة Tourfecto (zip)', 'Download the Tourfecto plugin (zip)')) + '</a>'
                + (php
                    ? '<ol style="margin:10px 0;padding-inline-start:20px;line-height:1.9;font-size:13.5px"><li>' + L('ارفع الملف جنب index.php على الاستضافة (مكان القديم لو موجود).', 'Upload the file next to index.php on your hosting (replacing the old one if any).') + '</li>'
                        + '<li>' + L('أول مرة بس: حط السطر ده في أول index.php:', 'First time only: add this line at the top of index.php:') + '<code class="tf-hub-code" dir="ltr" style="margin-top:4px">require_once __DIR__ . \'/tourfecto-connect.php\';</code></li>'
                        + '<li>' + L('دوس «اتأكد إنه اتركّب».', 'Press “Check it is installed”.') + '</li></ol>'
                    : '<ol style="margin:10px 0;padding-inline-start:20px;line-height:1.9;font-size:13.5px"><li>' + L('من ووردبريس: Plugins ← Add New ← Upload Plugin ← اختار الملف ← Install Now ← Activate.', 'In WordPress: Plugins → Add New → Upload Plugin → choose the file → Install Now → Activate.') + '</li>'
                        + '<li>' + L('لو عندك إضافة Tourfecto قديمة: ووردبريس هيسألك «Replace current with uploaded» - وافق.', 'If an older Tourfecto plugin is installed, WordPress asks “Replace current with uploaded” - accept.') + '</li>'
                        + '<li>' + L('دوس «اتأكد إنه اتركّب».', 'Press “Check it is installed”.') + '</li></ol>')
                + '<button type="button" class="p-btn outline sm" data-hub-recheck>' + L('اتأكد إنه اتركّب', 'Check it is installed') + '</button>'
                + (php ? ftpBox() : '');
            // بعد ما يشتغل: خلاص مش محتاج خطوات - بس نسخة جديدة لو احتاجها بعدين
            html += step(2, php ? L('ملف Tourfecto جنب index.php', 'Tourfecto file next to index.php') : L('إضافة Tourfecto لووردبريس', 'Tourfecto plugin for WordPress'), fileBadge(s),
                linked
                    ? muted(L('شغال على موقعك ومش محتاج تعمل حاجة تاني. أي حاجة تشغّلها تحت بتوصل لموقعك لوحدها.', 'Running on your site; nothing else to do. Anything you switch on below reaches your site by itself.'))
                        + '<details><summary style="cursor:pointer;font-size:13px">' + L('نزّل نسخة جديدة (لو طلبنا منك تحدّثه)', 'Download a new copy (if we ask you to update)') + '</summary><div style="margin-top:8px">' + install + '</div></details>'
                    : muted(L('ملف واحد بيعمل كل حاجة: السيو من السيرفر، وشات الموقع، ونشر المقالات - من غير ما يبطّأ موقعك، ولو المنصة وقفت موقعك بيفضل شغال عادي.', 'One file does it all: SEO on the server, the website chat and article publishing - without slowing your site, which keeps working even if the platform is unreachable.'))
                        + (cn && cn.present ? '<div class="alert alert-warning" style="margin:0 0 8px;font-size:13px">' + L('موقعك عليه نسخة Tourfecto تبع نسخة أو حساب تاني. نزّل الجديدة وحطها مكان القديمة بنفس الاسم.', 'Your site has a Tourfecto copy from another copy or account. Download the new one and put it in place of the old one.') + '</div>' : '')
                        + install);

            // ③ الخدمات اللي الملف/الإضافة بيشغّلوها - من هنا من غير ما تلمس الموقع تاني
            var articlesOn = !!(conn && conn.kind === 'connector');
            html += step(3, L('الخدمات على موقعك', 'Services on your site'), '',
                muted(L('شغّل اللي عايزه من هنا، والملف بيطبّقه على موقعك لوحده:', 'Switch on what you want here; the file applies it on your site by itself:'))
                + '<div class="tf-hub-toggles">'
                + toggle('', true, L('إصلاحات السيو من السيرفر', 'SEO fixes on the server'), L('شغالة دايمًا مع الملف: العناوين والوصف والـ Schema والصور', 'Always on with the file: titles, descriptions, schema and images'), true)
                + (c ? toggle('data-hub-svc="chat"', c.chat, L('شات الموقع بالذكاء الاصطناعي', 'AI website chat'), L('السائح يكلمك من موقعك والرسايل في صندوق الوارد', 'Travellers chat from your site, messages land in your inbox')) : '')
                + (php ? toggle('data-hub-articles', articlesOn, L('نشر «مقالات SEO» على موقعك (‎/guides)', 'Publish “SEO articles” on your site (/guides)'),
                    !(linked || articlesOn) ? L('بيتفعّل بعد ما الملف يشتغل على موقعك', 'Available once the file runs on your site')
                        : conn && !articlesOn ? L('هيبقى بدل النشر الحالي', 'Replaces your current publishing') + ' (' + esc(conn.name) + ')'
                        : L('كل مقال بيتنشر على موقعك جوه تصميمه، ومركز السيو بينشر عليه كمان', 'Every article goes live inside your site design; the SEO center publishes there too')
                            + (articlesOn && conn.path ? ' <span dir="ltr">(' + esc(String(state.platform.url || '').replace(/^https?:\/\//, '').replace(/\/$/, '') + conn.path) + '…)</span>' : ''), !(linked || articlesOn)) : '')
                + '</div>'
                + (php && !articlesOn && !conn && !state.showOther ? '<button type="button" class="p-btn ghost xs" data-hub-other>' + L('عايز المقالات في مكان تاني؟', 'Publish articles somewhere else?') + '</button>' : ''));
            if (!php) {
                html += step(4, L('النشر بضغطة على ووردبريس', 'One-click publishing to WordPress'), s && s.publish && s.publish.connected ? '<span class="pill green">' + L('مربوط', 'Connected') + '</span>' : '',
                    muted(L('اربط تحت بضغطة، والمقالات بتنزل على موقعك كمقالات ووردبريس عادية.', 'Connect below in one click; articles go live as normal WordPress posts.')));
            }
        } else {
            // أي نظام تاني: سطر واحد
            html += step(2, L('كود Tourfecto (مرة واحدة)', 'Tourfecto code (once)'),
                s && s.seo_code ? (s.seo_code.installed === true ? '<span class="pill green">' + L('متركّب على موقعك ✓', 'Installed ✓') + '</span>' : s.reachable === false ? '' : '<span class="pill gray">' + L('لسه مش متركّب', 'Not installed yet') + '</span>') : '',
                muted(L('سطر واحد بيشغّل كل خدمات المنصة على موقعك. شغّل اللي عايزه من هنا من غير ما تلمس الكود تاني:', 'One line runs every platform service on your site. Turn services on here without touching the code again:'))
                + (!c ? '<div class="p-cell-muted">' + L('بنجهّز الكود…', 'Preparing your code…') + '</div>'
                    : '<div class="tf-hub-toggles">'
                        + toggle('data-hub-svc="seo"', c.seo, L('إصلاحات السيو التلقائية', 'Automatic SEO fixes'), L('العناوين والوصف والـ Schema بتتظبط لوحدها', 'Titles, descriptions and schema fixed automatically'))
                        + toggle('data-hub-svc="chat"', c.chat, L('شات الموقع بالذكاء الاصطناعي', 'AI website chat'), L('السائح يكلمك من موقعك والرسايل في صندوق الوارد', 'Travellers chat from your site, messages land in your inbox'))
                        + '</div><code class="tf-hub-code" dir="ltr">' + esc(c.code) + '</code>'
                        + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">'
                        + '<button type="button" class="p-btn primary sm" data-hub-copy>📋 ' + L('انسخ الكود', 'Copy the code') + '</button>'
                        + '<a class="p-btn outline sm" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(L('محتاجين نحط الكود ده قبل </head> في كل صفحات موقعنا ', 'Please add this code before </head> on every page of our site ') + (state.platform && state.platform.url || '') + ':\n\n' + c.code) + '">📤 ' + L('ابعته للمبرمج على واتساب', 'Send it to your developer on WhatsApp') + '</a>'
                        + '<button type="button" class="p-btn ghost sm" data-hub-recheck>' + L('اتأكد إنه اتركّب', 'Check it is installed') + '</button></div>'
                        + '<div class="alert alert-info" style="margin:0;font-size:13px;line-height:1.8">' + (WHERE[key] || CUSTOM) + '</div>'));
            html += step(3, L('النشر التلقائي على موقعك', 'Auto-publishing to your site'),
                s && s.publish ? (s.publish.connected ? '<span class="pill green">' + L('مربوط', 'Connected') + '</span> <small class="p-cell-muted">' + esc(s.publish.name) + '</small>' : '<span class="pill gray">' + L('مش مربوط', 'Not connected') + '</span>') : '',
                muted(['shopify', 'webflow', 'ghost'].indexOf(key) !== -1 ? L('اربطه تحت بالخطوات اللي ظاهرة لنظامك، والمقالات بتنزل عليه لوحدها.', 'Connect it below with the steps for your platform; articles publish on their own.')
                    : L('اختار تحت: مدونة جاهزة من غير مبرمج (الأسهل)، أو في مدونة موقعك بملف جاهز للمبرمج، أو تنسخ المقالات بنفسك.', 'Pick below: a ready blog with no developer (easiest), your own blog with a ready file for your developer, or copy articles yourself.'))
                + (state.platform ? '<button type="button" class="p-btn ghost xs" data-hub-wp>' + L('موقعي ووردبريس فعلًا', 'My site is WordPress') + '</button>' : ''));
        }
        root.innerHTML = html;
        // تحت الكارت: ويزارد ووردبريس لووردبريس بس، وطرق النشر التانية للأنظمة التانية (أو لو صاحب موقع PHP طلبها)
        var wp = document.getElementById('siteConnect'), other = document.getElementById('siteConnectCms');
        if (wp && state.platform) wp.style.display = mode === 'wordpress' ? '' : 'none';
        if (other && state.platform) other.style.display = mode === 'code' || (mode === 'php' && (state.showOther || conn) && !(conn && conn.kind === 'connector')) ? '' : 'none';
    }

    function ftpBox() {
        return '<details style="margin-top:10px"' + (state.ftpMsg ? ' open' : '') + '><summary style="cursor:pointer;font-weight:600">' + L('مش عايز ترفع ملفات؟ المنصة تركّبه لك ببيانات FTP', 'Rather not upload files? Let the platform install it with FTP details') + '</summary>'
            + muted(L('البيانات بتتستخدم مرة واحدة ومش بتتحفظ. بنعمل نسخة احتياطية من index.php، ولو الموقع اتأثر بنرجّعه زي ما كان.', 'Used once and never stored. We back up index.php and restore it if anything breaks.'))
            + '<div style="display:grid;gap:6px;max-width:420px"><input class="form-control" id="hubFtpHost" dir="ltr" placeholder="ftp.yoursite.com"><input class="form-control" id="hubFtpUser" dir="ltr" placeholder="' + L('اسم مستخدم FTP', 'FTP username') + '" autocomplete="off"><input class="form-control" id="hubFtpPass" type="password" dir="ltr" placeholder="' + L('كلمة سر FTP', 'FTP password') + '" autocomplete="new-password"><input class="form-control" id="hubFtpPath" dir="ltr" placeholder="' + L('فولدر الموقع (اختياري) مثلًا public_html', 'Site folder (optional) e.g. public_html') + '">'
            + '<button type="button" class="p-btn primary sm" data-hub-ftp>' + L('ركّبه لي', 'Install it for me') + '</button><div id="hubFtpMsg" class="p-cell-muted" style="font-size:13px' + (state.ftpMsg ? ';color:' + (state.ftpMsg.ok ? 'var(--panel-success,#16a34a)' : 'var(--panel-danger,#dc2626)') : '') + '">' + (state.ftpMsg ? esc(state.ftpMsg.text) : '') + '</div></div></details>';
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-hub-copy]');
        if (b && state.code) {
            (navigator.clipboard ? navigator.clipboard.writeText(state.code.code) : Promise.reject()).then(function () { b.textContent = '✔ ' + L('اتنسخ', 'Copied'); }).catch(function () {});
            return;
        }
        if (e.target.closest('[data-hub-recheck]')) { state.status = null; render(); loadStatus(); return; }
        if (e.target.closest('[data-hub-wp]')) { state.showWp = true; render(); return; }
        if (e.target.closest('[data-hub-other]')) { state.showOther = true; render(); return; }
        var ftp = e.target.closest('[data-hub-ftp]');
        if (ftp) {
            var v = function (i) { var el = document.getElementById(i); return el ? el.value.trim() : ''; };
            ftp.disabled = true; document.getElementById('hubFtpMsg').textContent = L('بنركّب الملف…', 'Installing…');
            api('/api/website-optimizer/edge/php-auto-install', { website_id: id, host: v('hubFtpHost'), username: v('hubFtpUser'), password: document.getElementById('hubFtpPass').value, path: v('hubFtpPath') }).then(function (r) {
                state.ftpMsg = { ok: !!(r && r.success), text: (r && (r.message || r.error)) || L('تعذر التركيب', 'Could not install') };
                render();
                if (r && r.success) loadStatus();
            });
        }
    });
    root.addEventListener('change', function (e) {
        var t = e.target.closest('[data-hub-svc]');
        if (t) {
            t.disabled = true;
            api('/api/websites/' + id + '/site-code', { service: t.getAttribute('data-hub-svc'), on: t.checked }).then(function (r) {
                t.disabled = false;
                if (r && r.success) { state.code = r.data; render(); }
                else { t.checked = !t.checked; alert((r && r.error) || L('تعذر الحفظ', 'Could not save')); }
            });
            return;
        }
        var a = e.target.closest('[data-hub-articles]');
        if (a) {
            a.disabled = true;
            api('/api/site-connect/' + id + (a.checked ? '/connector' : '/disconnect'), {}).then(function (r) {
                if (r && r.success) { state.platform.connection = a.checked ? (r.data && r.data.connection) || { kind: 'connector' } : null; render(); loadStatus(); }
                else { a.disabled = false; a.checked = !a.checked; alert((r && r.error) || L('تعذر الحفظ', 'Could not save')); }
            });
        }
    });

    function loadStatus() { api('/api/websites/' + id + '/connect-status?check=1').then(function (r) { if (r && r.success) { state.status = r.data; render(); } }); }
    render();
    api('/api/site-connect/' + id).then(function (r) { state.platform = r && r.success ? r.data : { platform: null }; render(); });
    api('/api/websites/' + id + '/site-code').then(function (r) { if (r && r.success) { state.code = r.data; render(); } });
    loadStatus();
})();
