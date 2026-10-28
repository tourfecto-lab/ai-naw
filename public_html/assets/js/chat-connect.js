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
                + '<button class="p-btn outline xs" id="waQrDisc">' + L('فصل الربط', 'Disconnect') + '</button>';
            document.getElementById('waQrDisc').onclick = disconnectQr;
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
            + '<button class="p-btn primary" id="waQrStart">' + L('اربط واتساب', 'Connect WhatsApp') + '</button><div id="waQrAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>';
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
        if (!confirm(L('فصل رقم واتساب؟ البوت هيبطّل يرد عليه.', 'Disconnect this WhatsApp number? The bot will stop replying.'))) return;
        var r = await post('/api/whatsapp-qr/disconnect', { website_id: wid });
        if (r.success) { toast(L('اتفصل', 'Disconnected'), 'success'); renderQr(r.data); } else toast(r.error || L('تعذر الفصل', 'Could not disconnect'), 'error');
    }

    // ------------------------------------------------------------------ ماسنجر / إنستجرام
    function renderMeta(d) {
        var c = document.getElementById('metaChatCard'); if (!c) return;
        c.style.display = 'block';
        var head = '<div class="p-card-head"><h3>📘 ' + L('رسايل فيسبوك وإنستجرام', 'Facebook & Instagram messages') + '</h3><span class="p-card-sub">' + L('بتسجيل الدخول بفيسبوك', 'With Facebook sign-in') + '</span></div>';
        if (!d.meta_ready) { c.innerHTML = head + '<p class="p-cell-muted">' + L('الربط بفيسبوك لسه بيتجهز من المنصة.', 'Facebook connection is being prepared by the platform.') + '</p>'; return; }
        var signIn = '<a class="p-btn ' + (d.pages.length ? 'outline xs' : 'primary') + '" href="' + esc(d.connect_url) + '"><b>f</b> ' + (d.pages.length ? L('سجّل دخول تاني / ضيف صفحات', 'Sign in again / add pages') : L('سجّل دخول بفيسبوك', 'Sign in with Facebook')) + '</a>';
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
    if (/[?&]meta=connected/.test(location.search)) toast(L('اتربطت صفحاتك من فيسبوك. اختار الصفحة اللي الشات بوت يرد عليها.', 'Your Facebook pages are connected. Choose the page the bot should answer.'), 'success');
})();
