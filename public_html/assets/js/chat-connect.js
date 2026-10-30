/* Chat connections without keys: WhatsApp by QR scan, Messenger/Instagram by Facebook sign-in. Server values go through esc()/textContent. */
(function () {
    'use strict';
    var P = window.Panel; if (!P) return;
    var esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    var AR = (document.documentElement.lang || '').toLowerCase().indexOf('ar') === 0;
    function L(a, e) { return AR ? a : e; }
    var wid = null, timer = null, deadline = 0;

    function stopPolling() { if (timer) clearTimeout(timer); timer = null; }
    function post(url, body) { return fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); }

    // ------------------------------------------------------------------ واتساب QR
    var qrCard = function () { return document.getElementById('waQrCard'); };
    function qrHead() {
        return '<div class="p-card-head"><h3>📱 ' + L('واتساب برقمك (مسح QR)', 'WhatsApp with your number (scan QR)') + '</h3><span class="p-card-sub">' + L('من غير أي مفاتيح أو حسابات خارجية', 'No keys or external accounts') + '</span></div>';
    }
    function renderQr(d) {
        var c = qrCard(); if (!c) return;
        c.style.display = 'block';
        if (!d.available && !d.connected) {
            c.innerHTML = qrHead() + '<p class="p-cell-muted">' + L('ربط واتساب بالـ QR لسه بيتجهز من المنصة. هيظهر هنا أول ما يتفعّل.', 'WhatsApp QR connection is being prepared by the platform and will appear here once enabled.') + '</p>';
            return;
        }
        if (d.connected) {
            stopPolling();
            c.innerHTML = qrHead() + '<div class="alert alert-success">✔ ' + L('واتساب مربوط', 'WhatsApp connected') + (d.phone ? ': <b dir="ltr">+' + esc(d.phone) + '</b>' : '') + (d.name ? ' · ' + esc(d.name) : '') + '</div>'
                + '<p class="p-cell-muted" style="font-size:13px">' + L('الشات بوت بيرد على الرسايل اللي بتوصل الرقم ده، والتذكير وطلبات التقييم بتطلع منه. سيب الموبايل متصل بالإنترنت.', 'The chat bot replies to messages on this number, and reminders and review requests are sent from it. Keep the phone online.') + '</p>'
                + '<p class="p-cell-muted" style="font-size:13px">' + L('زي واتساب ويب بالظبط: تقدر تفصل الرقم في أي وقت من هنا، أو من الموبايل (واتساب ← الأجهزة المرتبطة ← اختار الجهاز ← تسجيل الخروج)، وترجع تربطه أو تربط رقم تاني بنفس الخطوات.', 'Just like WhatsApp Web: disconnect any time here or from the phone (WhatsApp → Linked devices → tap the device → Log out), then link it again or link another number the same way.') + '</p>'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="p-btn outline xs" id="waQrSwap">🔄 ' + L('اربط رقم تاني', 'Link another number') + '</button>'
                + '<button class="p-btn outline xs" id="waQrDisc">' + L('فصل الربط', 'Disconnect') + '</button></div>';
            document.getElementById('waQrDisc').onclick = disconnectQr;
            document.getElementById('waQrSwap').onclick = swapNumber;
            return;
        }
        var steps = '<ol style="margin:8px 0 12px;padding-inline-start:20px;line-height:1.9;font-size:13.5px">'
            + '<li>' + L('افتح واتساب على موبايل الشركة', 'Open WhatsApp on the company phone') + '</li>'
            + '<li>' + L('الإعدادات ← الأجهزة المرتبطة ← ربط جهاز', 'Settings → Linked devices → Link a device') + '</li>'
            + '<li>' + L('وجّه الكاميرا على الكود', 'Point the camera at the code') + '</li></ol>';
        if (d.qr) {
            c.innerHTML = qrHead() + steps
                + '<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center"><img id="waQrImg" alt="' + L('كود QR لربط واتساب', 'WhatsApp QR code') + '" style="width:240px;height:240px;background:#fff;padding:8px;border-radius:12px">'
                + '<div style="min-width:200px"><p class="p-cell-muted" style="font-size:13px">' + L('الكود بيتجدد لوحده. أول ما تمسحه الصفحة هتتحدث.', 'The code refreshes automatically. This page updates once you scan it.') + '</p>'
                + (d.pairing_code ? '<p style="font-size:13px">' + L('أو اربط برقم الموبايل بالكود:', 'Or link with phone number using code:') + ' <b dir="ltr" style="letter-spacing:2px">' + esc(d.pairing_code) + '</b></p>' : '')
                + '<button class="p-btn outline xs" id="waQrCancel">' + L('إلغاء', 'Cancel') + '</button></div></div>';
            document.getElementById('waQrImg').src = d.qr; // data:image مضمون من السيرفر
            document.getElementById('waQrCancel').onclick = function () { stopPolling(); loadQr(); };
            return;
        }
        c.innerHTML = qrHead() + steps + (d.last_error ? '<div class="alert alert-warning">' + esc(d.last_error) + '</div>' : '')
            + '<button class="p-btn primary" id="waQrStart">' + (d.last_error ? L('اربط واتساب تاني', 'Connect WhatsApp again') : L('اربط واتساب', 'Connect WhatsApp')) + '</button><div id="waQrAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>';
        document.getElementById('waQrStart').onclick = startQr;
    }
    async function loadQr() {
        if (!wid) return;
        var r = await fetchJSON('/api/whatsapp-qr/status?website_id=' + encodeURIComponent(wid));
        if (r.success) renderQr(r.data);
    }
    async function startQr() {
        var b = document.getElementById('waQrStart'); if (b) b.disabled = true;
        var r = await post('/api/whatsapp-qr/start', { website_id: wid });
        if (!r.success) {
            var a = document.getElementById('waQrAlert'); if (a) { a.textContent = r.error || L('تعذر بدء الربط', 'Could not start'); a.style.display = 'block'; }
            if (b) b.disabled = false; return;
        }
        deadline = Date.now() + 3 * 60 * 1000;
        renderQr(r.data); schedule();
    }
    function schedule() {
        stopPolling();
        timer = setTimeout(async function () {
            if (Date.now() > deadline) { stopPolling(); toast(L('الكود انتهى. اضغط اربط واتساب تاني.', 'The code expired. Press Connect WhatsApp again.'), 'error'); loadQr(); return; }
            var r = await fetchJSON('/api/whatsapp-qr/poll?website_id=' + encodeURIComponent(wid));
            if (r.success) {
                renderQr(r.data);
                if (r.data.connected) { toast(L('تم ربط واتساب ✔', 'WhatsApp connected ✔'), 'success'); return; }
            }
            schedule();
        }, 4000);
    }
    async function disconnectQr() {
        if (!confirm(L('فصل رقم واتساب؟ البوت هيبطّل يرد عليه، وتقدر تربطه تاني في أي وقت.', 'Disconnect this WhatsApp number? The bot will stop replying, and you can link it again any time.'))) return;
        var r = await post('/api/whatsapp-qr/disconnect', { website_id: wid });
        if (r.success) { toast(L('اتفصل', 'Disconnected'), 'success'); renderQr(r.data); } else toast(r.error || L('تعذر الفصل', 'Could not disconnect'), 'error');
    }
    // تغيير الرقم في خطوة واحدة: فصل الحالي وكود QR جديد على طول
    async function swapNumber() {
        if (!confirm(L('هنفصل الرقم الحالي ونطلعلك كود جديد تمسحه من موبايل الرقم التاني. نكمّل؟', 'We will disconnect the current number and show a new code to scan from the other phone. Continue?'))) return;
        var r = await post('/api/whatsapp-qr/disconnect', { website_id: wid });
        if (!r.success) { toast(r.error || L('تعذر الفصل', 'Could not disconnect'), 'error'); return; }
        renderQr(r.data);
        startQr();
    }

    // ------------------------------------------------------------------ ماسنجر / إنستجرام
    function renderMeta(d) {
        var c = document.getElementById('metaChatCard'); if (!c) return;
        c.style.display = 'block';
        var head = '<div class="p-card-head"><h3>📘 ' + L('رسايل فيسبوك وإنستجرام', 'Facebook & Instagram messages') + '</h3><span class="p-card-sub">' + L('بتسجيل الدخول بفيسبوك', 'With Facebook sign-in') + '</span></div>';
        // A channel whose Facebook token stopped working: say so and offer the way back.
        var warn = (d.health || []).map(function (h) {
            var re = d.messaging_app && d.messaging_app.configured ? d.messaging_app.connect_url : d.connect_url;
            return '<div class="p-alert" style="margin:0 0 10px;padding:10px 12px;border-radius:10px;background:rgba(220,53,69,.14);border:1px solid rgba(220,53,69,.4)"><b>' + (h.channel === 'instagram' ? 'Instagram' : 'Messenger') + '</b> — '
                + L('فيسبوك مبقاش بيقبل الربط، فالردود مش بتتسلّم. سجّل دخول تاني.', 'Facebook no longer accepts the connection, so replies are not delivered. Sign in again.')
                + ' <a class="p-btn primary xs" href="' + esc(re) + '">' + L('إعادة الربط', 'Reconnect') + '</a></div>';
        }).join('');
        head += warn;
        if (!d.meta_ready) { c.innerHTML = head + '<p class="p-cell-muted">' + L('الربط بفيسبوك لسه بيتجهز من المنصة.', 'Facebook connection is being prepared by the platform.') + '</p>'; return; }
        var signIn = '<a class="p-btn ' + (d.pages.length ? 'outline xs' : 'primary') + '" href="' + esc(d.connect_url) + '"><b>f</b> ' + (d.pages.length ? L('سجّل دخول تاني / ضيف صفحات', 'Sign in again / add pages') : L('سجّل دخول بفيسبوك', 'Sign in with Facebook')) + '</a>';
        // The separate messaging-only Meta app (test/dev app): shown only once the platform has its credentials.
        var msgApp = d.messaging_app && d.messaging_app.configured ? '<a class="p-btn outline xs" href="' + esc(d.messaging_app.connect_url) + '"><b>f</b> ' + L('ربط الرسايل عبر تطبيق الرسائل (تجريبي)', 'Connect messages via the messaging app (test)') + '</a>' : '';
        signIn += msgApp;
        if (!d.pages.length) {
            c.innerHTML = head + '<p style="font-size:14px">' + L('سجّل دخول بحساب فيسبوك اللي بيدير صفحة الشركة، ووافق على الصلاحيات. الشات بوت هيرد على رسايل الصفحة وإنستجرام المربوط بيها.', 'Sign in with the Facebook account that manages your company page and approve the permissions. The bot will reply to the page and its linked Instagram.') + '</p>' + signIn;
            return;
        }
        var rows = d.pages.map(function (p) {
            return '<label class="p-check" style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--panel-border,#2a3247);border-radius:10px;margin-bottom:8px;cursor:pointer">'
                + '<input type="radio" name="metaChatPage" value="' + esc(String(p.id)) + '"' + (p.messaging ? ' checked' : '') + '>'
                + '<span><b>' + esc(p.name) + '</b>' + (p.instagram ? ' <span class="p-cell-muted">+ Instagram @' + esc(p.instagram) + '</span>' : '') + (p.messaging ? ' <span class="pill green">' + L('بيرد على الرسايل', 'Replying to messages') + '</span>' : '') + '</span></label>';
        }).join('');
        var anyOn = d.pages.some(function (p) { return p.messaging; });
        c.innerHTML = head + '<p class="p-cell-muted" style="font-size:13px">' + L('اختار الصفحة اللي الشات بوت يرد على رسايلها (ورسايل إنستجرام المربوط بيها):', 'Choose the page whose messages the bot answers (and its linked Instagram):') + '</p>'
            + rows + (d.missing_permissions ? '<div class="alert alert-warning">' + L('فيسبوك ماادّاش صلاحية الرسايل. سجّل دخول تاني ووافق على "إدارة محادثات الصفحة".', 'Facebook did not grant message access. Sign in again and approve "Manage Page conversations".') + '</div>' : '')
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px"><button class="p-btn primary sm" id="metaChatSave">' + L('شغّل الرد على الصفحة دي', 'Reply on this page') + '</button>'
            + (anyOn ? '<button class="p-btn outline sm" id="metaChatOff">' + L('إيقاف الرد', 'Stop replying') + '</button>' : '') + signIn + '</div>';
        document.getElementById('metaChatSave').onclick = function () {
            var sel = c.querySelector('input[name=metaChatPage]:checked');
            if (!sel) { toast(L('اختار صفحة', 'Choose a page'), 'error'); return; }
            toggleMeta(Number(sel.value), true, this);
        };
        var off = document.getElementById('metaChatOff');
        if (off) off.onclick = function () { var on = d.pages.filter(function (p) { return p.messaging; })[0]; if (on) toggleMeta(on.id, false, this); };
    }
    async function toggleMeta(id, enabled, btn) {
        btn.disabled = true;
        var r = await post('/api/chat/meta-pages/messaging', { website_id: wid, connection_id: id, enabled: enabled });
        btn.disabled = false;
        if (r.success) { toast(enabled ? L('الشات بوت بقى بيرد على الصفحة ✔', 'The bot now replies on this page ✔') : L('اتوقف', 'Stopped'), 'success'); renderMeta(r.data); }
        else toast(r.error || L('تعذر الحفظ', 'Could not save'), 'error');
    }
    async function loadMeta() {
        if (!wid) return;
        var r = await fetchJSON('/api/chat/meta-pages?website_id=' + encodeURIComponent(wid));
        if (r.success) renderMeta(r.data);
    }

    window.ChatConnect = {
        load: function (websiteId) {
            stopPolling(); wid = websiteId || null;
            ['waQrCard', 'metaChatCard'].forEach(function (id) { var e = document.getElementById(id); if (e && !wid) e.style.display = 'none'; });
            if (wid) { loadQr(); loadMeta(); }
        }
    };
    // لو الصفحة حمّلت الموقع قبل السكربت ده
    var sel = document.getElementById('websiteSelect');
    if (sel && sel.value) window.ChatConnect.load(sel.value);
    (function () {
        var m = /[?&]meta_msg=([a-z]+)/.exec(location.search);
        if (!m) return;
        var msg = {
            connected: [L('اتربطت صفحاتك عبر تطبيق الرسائل. اختار الصفحة اللي الشات بوت يرد عليها.', 'Your pages are connected through the messaging app. Choose the page the bot answers.'), 'success'],
            nopages: [L('ما لقيناش صفحات على الحساب ده.', 'No pages were found on this account.') + (/[?&]detail=([^&]*)/.test(location.search) ? ' (' + decodeURIComponent(/[?&]detail=([^&]*)/.exec(location.search)[1]) + ')' : ''), 'error'],
            denied: [L('رفضت الموافقة على فيسبوك.', 'Facebook permission was declined.'), 'error'],
            state: [L('انتهت صلاحية الطلب، جرّب تاني.', 'The request expired, try again.'), 'error'],
            exchange: [L('فيسبوك رفض الربط. اتأكد من إعدادات تطبيق الرسائل.', 'Facebook refused the connection. Check the messaging app settings.'), 'error'],
            pages: [L('تعذر قراءة الصفحات من فيسبوك.', 'Could not read your pages from Facebook.'), 'error'],
            notconfigured: [L('تطبيق الرسائل لسه ما اتفعّلش على السيرفر (META_MSG_APP_ID / META_MSG_APP_SECRET).', 'The messaging app is not set up on the server yet (META_MSG_APP_ID / META_MSG_APP_SECRET).'), 'error'],
            server: [L('حصل خطأ في السيرفر أثناء حفظ الصفحات.', 'A server error happened while saving your pages.') + (/[?&]detail=([^&]*)/.test(location.search) ? ' (' + decodeURIComponent(/[?&]detail=([^&]*)/.exec(location.search)[1]) + ')' : ''), 'error'],
            website: [L('الموقع غير موجود.', 'Website not found.'), 'error']
        }[m[1]];
        if (msg) toast(msg[0], msg[1]);
    })();
    if (/[?&]meta=connected/.test(location.search)) toast(L('اتربطت صفحاتك من فيسبوك. اختار الصفحة اللي الشات بوت يرد عليها.', 'Your Facebook pages are connected. Choose the page the bot should answer.'), 'success');
})();

/*
 * In-page navigation for the (long) settings page: one chip per section, each with a status dot where the section is a
 * channel (green = connected / replying), smooth scroll, and the chip of the section in view is highlighted.
 */
(function () {
    'use strict';
    var main = document.getElementById('mainContent');
    if (!main || !document.getElementById('settingsFormCard')) return;
    var ar = /^ar/i.test(document.documentElement.lang || '');
    var SECTIONS = [
        ['waCloudCard', ar ? 'واتساب الرسمي' : 'WhatsApp', true],
        ['waQrCard', ar ? 'واتساب برقمك' : 'WhatsApp (QR)', true],
        ['metaChatCard', ar ? 'ماسنجر وإنستجرام' : 'Messenger & Instagram', true],
        ['ultramsgCard', 'UltraMsg', true],
        ['settingsFormCard', ar ? 'إعدادات البوت' : 'Bot settings', false]
    ];
    var nav = document.createElement('nav');
    nav.className = 'tf-set-nav';
    nav.setAttribute('aria-label', ar ? 'أقسام الإعدادات' : 'Settings sections');
    nav.style.cssText = 'position:sticky;top:0;z-index:30;display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 14px;padding:8px 0;background:var(--wa-page,var(--panel-bg,#0b141a));';
    var chips = {};
    SECTIONS.forEach(function (s) {
        var a = document.createElement('a');
        a.href = '#' + s[0];
        a.className = 'p-btn outline xs';
        a.style.display = 'none';
        a.innerHTML = (s[2] ? '<i class="tf-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#8696a0;margin-inline-end:6px"></i>' : '') + '<span></span>';
        a.lastChild.textContent = s[1];
        a.addEventListener('click', function (e) {
            var el = document.getElementById(s[0]);
            if (!el) return;
            e.preventDefault();
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        nav.appendChild(a);
        chips[s[0]] = a;
    });
    var kb = document.createElement('a');
    kb.href = '/chat/knowledge-base';
    kb.className = 'p-btn outline xs';
    kb.style.marginInlineStart = 'auto';
    kb.textContent = '📚 ' + (ar ? 'معلومات المساعد' : 'Assistant knowledge');
    nav.appendChild(kb);

    function refresh() {
        SECTIONS.forEach(function (s) {
            var el = document.getElementById(s[0]);
            var chip = chips[s[0]];
            var shown = !!el && el.style.display !== 'none' && el.offsetParent !== null;
            chip.style.display = shown ? '' : 'none';
            var dot = chip.querySelector('.tf-dot');
            if (shown && dot) {
                var t = el.innerText || '';
                var on = /Connected|Replying to messages|بيرد على الرسايل|مربوط/.test(t);
                dot.style.background = on ? '#25d366' : '#8696a0';
            }
        });
    }
    var tabsBar = main.querySelector('.tf-sec-tabs');
    main.insertBefore(nav, tabsBar ? tabsBar.nextSibling : main.firstElementChild);
    refresh();
    // the cards fill in after their data loads
    [600, 1500, 3500].forEach(function (ms) { setTimeout(refresh, ms); });
    new MutationObserver(function () { clearTimeout(refresh.t); refresh.t = setTimeout(refresh, 200); }).observe(main, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                var chip = chips[en.target.id];
                if (chip) chip.classList.toggle('primary', en.isIntersecting), chip.classList.toggle('outline', !en.isIntersecting);
            });
        }, { rootMargin: '-20% 0px -60% 0px' });
        SECTIONS.forEach(function (s) { var el = document.getElementById(s[0]); if (el) io.observe(el); });
    }
})();
