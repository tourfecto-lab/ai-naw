/**
 * Tourfecto - كارت ربط واتساب الرسمي (Cloud API) في إعدادات الشات - عربي + إنجليزي
 * - "اربط بضغطة" (Embedded Signup من Meta) لو الأدمن ظبط Config ID
 * - ربط يدوي (Phone Number ID + WABA ID + توكن دائم) لأي حد عنده تطبيق Meta بتاعه
 */
(function () {
    'use strict';
    var P = window.Panel || {};
    var AR = ((document.documentElement.lang || 'ar').slice(0, 2) === 'ar');
    var L = function (ar, en) { return AR ? ar : en; };
    var esc = P.esc || function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var toast = P.toast || function (m) { alert(m); };
    var fetchJSON = P.fetchJSON || function (u, o) { return fetch(u, o).then(function (r) { return r.json(); }); };
    var card, site = '', embedCfg = null, esData = {};

    var ST = {
        APPROVED: [L('اتوافق عليه', 'Approved'), '#16a34a'], PENDING: [L('مستني موافقة Meta', 'Pending review'), '#d97706'], IN_APPEAL: [L('في الاستئناف', 'In appeal'), '#d97706'],
        REJECTED: [L('اترفض', 'Rejected'), '#dc2626'], PAUSED: [L('متوقف', 'Paused'), '#dc2626'], DISABLED: [L('متوقف', 'Disabled'), '#dc2626']
    };
    var TPL_EN = { tf_trip_reminder: 'Trip reminder', tf_review_request: 'Post-trip review request', tf_follow_up: 'Follow-up for guests who didn’t book' };
    function badge(s) {
        if (!s) return '<span style="color:var(--panel-text-muted,#888)">' + L('مش موجود', 'Not created') + '</span>';
        var x = ST[s] || [s, '#64748b'];
        return '<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;background:' + x[1] + '1a;color:' + x[1] + '">' + esc(x[0]) + '</span>';
    }
    function copyBtn(id) { return '<button type="button" class="p-btn outline xs" data-copy="' + id + '">' + L('نسخ', 'Copy') + '</button>'; }
    function code(id, v) { return '<code id="' + id + '" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:9px 11px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;margin:4px 0 8px;word-break:break-all">' + esc(v) + '</code>'; }
    function head(sub) { return '<div class="p-card-head"><h3>✅ ' + L('واتساب الرسمي', 'Official WhatsApp') + ' (WhatsApp Business API)</h3><span class="p-card-sub">' + sub + '</span></div>'; }

    function guide() {
        return '<details style="margin:8px 0 14px"><summary style="cursor:pointer;font-weight:600">' + L('إزاي أجيب البيانات دي؟ (مرة واحدة - حوالي 15 دقيقة)', 'Where do I get these? (one time - about 15 minutes)') + '</summary>'
            + '<ol style="line-height:2;margin:10px 0 0;padding-inline-start:20px;font-size:13.5px">'
            + '<li>' + L('ادخل <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> واعمل تطبيق نوع <b>Business</b>، وضيفله منتج <b>WhatsApp</b>.', 'Go to <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a>, create a <b>Business</b> app and add the <b>WhatsApp</b> product.') + '</li>'
            + '<li>' + L('من <b>WhatsApp ← API Setup</b>: ضيف رقمك ووثّقه، وانسخ <b>Phone Number ID</b> و <b>WhatsApp Business Account ID</b>.', 'In <b>WhatsApp → API Setup</b>: add and verify your number, then copy the <b>Phone Number ID</b> and <b>WhatsApp Business Account ID</b>.') + '</li>'
            + '<li>' + L('من <a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener">Business Settings ← System Users</a>: اعمل System User (Admin)، اديله التطبيق وحساب الواتساب، واعمل <b>توكن دائم</b> بالصلاحيات:', 'In <a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener">Business Settings → System Users</a>: create an Admin system user, assign it the app and WhatsApp account, and generate a <b>permanent token</b> with:') + ' <code>whatsapp_business_messaging</code> ' + L('و', 'and') + ' <code>whatsapp_business_management</code>.</li>'
            + '<li>' + L('ضيف وسيلة دفع في حساب WhatsApp Business (Meta بتحاسب على الرسايل اللي انت بتبدأها).', 'Add a payment method to the WhatsApp Business account (Meta charges for messages you start).') + '</li>'
            + '<li>' + L('بعد الربط هنا: حط <b>Callback URL</b> و <b>Verify token</b> اللي هتظهرلك في التطبيق ← WhatsApp ← Configuration، واشترك في <b>messages</b>.', 'After connecting here: paste the <b>Callback URL</b> and <b>Verify token</b> shown below into your app → WhatsApp → Configuration, and subscribe to <b>messages</b>.') + '</li>'
            + '</ol></details>';
    }

    function manualForm(d) {
        return guide()
            + '<div class="form-group"><label class="form-label">Phone Number ID</label><input id="waPn" class="form-control" dir="ltr" inputmode="numeric" placeholder="1234567890123456"></div>'
            + '<div class="form-group"><label class="form-label">WhatsApp Business Account ID</label><input id="waWaba" class="form-control" dir="ltr" inputmode="numeric" placeholder="1029384756"></div>'
            + '<div class="form-group"><label class="form-label">' + L('التوكن الدائم (System User)', 'Permanent token (System User)') + '</label><input id="waTok" class="form-control" dir="ltr" type="password" autocomplete="off" placeholder="EAAG…"></div>'
            + '<div class="form-group"><label class="form-label">App Secret <span class="p-cell-muted">' + L('(مطلوب لحماية الرسائل الواردة)', '(required to verify incoming messages)') + '</span></label><input id="waSec" class="form-control" dir="ltr" type="password" autocomplete="off" required minlength="32" maxlength="32"></div>'
            + '<div id="waAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>'
            + '<button class="p-btn ' + (embedCfg ? 'outline' : 'primary') + '" id="waConnect" style="margin-top:10px">' + L('ربط الرقم', 'Connect number') + '</button>';
    }

    function renderDisconnected(d) {
        var html = head(L('رقم شركتك الرسمي من Meta - مافيش خطر حظر، والرسايل باسم شركتك', 'Your company’s official number from Meta - no ban risk, messages in your name'));
        if (embedCfg) {
            html += '<p style="margin:6px 0 10px;font-size:14px">' + L('أسهل طريقة: سجّل دخول بفيسبوك واختار رقمك (أو اعمل رقم جديد) - وكل حاجة بتتظبط لوحدها.', 'Easiest: log in with Facebook and pick (or create) your number - everything else is set up for you.') + '</p>'
                + '<button class="p-btn primary" id="waEmbed" style="display:inline-flex;gap:8px;align-items:center"><span style="font-weight:700">f</span> ' + L('اربط واتساب بضغطة', 'Connect WhatsApp in one click') + '</button>'
                + '<div style="margin-top:14px;padding:12px;border:1px dashed var(--panel-border,#2a3247);border-radius:10px"><p style="margin:0 0 6px;font-size:14px;font-weight:600">📱 ' + L('رقمك شغال على تطبيق واتساب بزنس في الموبايل؟', 'Is your number already on the WhatsApp Business app?') + '</p>'
                + '<p class="p-cell-muted" style="margin:0 0 8px;font-size:12.5px">' + L('اربطه بالطريقة دي عشان يفضل شغال على موبايلك وعلى المنصة مع بعض: هتكمّل ترد من الموبايل عادي، وكل الرسايل هتظهر هنا في الوارد. Meta بتطلب تطبيق واتساب بزنس محدّث، وهيظهرلك كود QR تمسحه من التطبيق.', 'Connect it this way to keep using it on your phone and on the platform together: keep replying from the phone as usual, and every message also shows in the inbox here. Meta needs an up-to-date WhatsApp Business app and will show a QR code to scan from it.') + '</p>'
                + '<button class="p-btn outline" id="waEmbedApp">' + L('اربط رقم واتساب بزنس اللي على موبايلي', 'Connect my WhatsApp Business app number') + '</button></div>'
                + '<p class="p-cell-muted" style="margin:10px 0 0;font-size:12px">' + L('ممكن Meta تطلب وسيلة دفع وانت بتربط. ده مش إجباري للربط، وMeta بتحاسب بس على القوالب والرسايل التسويقية. الرد على عملاء كلموك خلال 24 ساعة ببلاش.', 'Meta may ask for a payment method while connecting. It is not required to connect; Meta only charges for templates and marketing messages. Replying to customers within 24 hours is free.') + '</p>'
                + '<div id="waEmbedAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>'
;
        } else {
            // الربط بضغطة لسه بيتجهز من المنصة: منبدأش العميل بخانات تقنية (Phone Number ID / توكن)
            // العميل مايدخلش أي مفاتيح: الربط الرسمي بتسجيل دخول فيسبوك بس (لما الأدمن يفعّله)، ولحد كده ربط QR تحت
            html += '<p style="margin:6px 0 10px;font-size:14px">' + L('الربط الرسمي بتسجيل دخول فيسبوك هيتفعّل قريب. دلوقتي تقدر تربط رقمك بمسح كود QR تحت.', 'Official connection with Facebook sign-in is coming soon. For now, connect your number by scanning the QR code below.') + '</p>';
        }
        if (d.ultramsg_connected) html += '<p class="p-cell-muted" style="margin-top:10px;font-size:12.5px">' + L('UltraMsg مربوط حاليًا - بعد ربط الرسمي هيبقى هو الأساسي، وUltraMsg احتياطي لو الرسمي فشل.', 'UltraMsg is connected - once official WhatsApp is linked it becomes primary, with UltraMsg as a fallback.') + '</p>';
        card.innerHTML = html;
    }

    function renderConnected(d) {
        var rows = (d.templates || []).map(function (t) {
            var rej = t.rejected ? Object.keys(t.rejected).map(function (l) { return l + ': ' + t.rejected[l]; }).join(' · ') : '';
            return '<tr><td><b>' + esc(AR ? t.label : (TPL_EN[t.name] || t.label)) + '</b><br><span class="p-cell-muted" dir="ltr">' + esc(t.name) + ' · ' + esc(t.category) + '</span>' + (rej ? '<br><span style="color:#dc2626;font-size:12px">' + esc(rej) + '</span>' : '') + '</td><td>' + badge(t.ar) + '</td><td>' + badge(t.en) + '</td></tr>';
        }).join('');
        var anyMissing = (d.templates || []).some(function (t) { return !t.ar || !t.en || t.ar === 'REJECTED' || t.en === 'REJECTED'; });
        var allOk = (d.templates || []).every(function (t) { return t.ar === 'APPROVED' || t.en === 'APPROVED'; });
        var webhook = d.embedded
            ? '<p class="p-cell-muted" style="font-size:13px;margin:0">' + L('الرسايل الواردة متظبطة تلقائيًا (اتربطت من خلال فيسبوك).', 'Incoming messages are set up automatically (connected through Facebook).') + (d.last_webhook_at ? ' <span style="color:#16a34a">✓ ' + L('آخر رسالة وصلت', 'Last message received') + ': ' + esc(d.last_webhook_at) + ' UTC</span>' : '') + '</p>'
                + (d.register_error ? '<div class="alert alert-warning" style="margin-top:8px">' + L('تسجيل الرقم على Meta: ', 'Number registration: ') + esc(d.register_error) + '</div>' : '')
            : '<p class="p-cell-muted" style="font-size:13px;margin:0">' + L('في تطبيقك على Meta ← WhatsApp ← Configuration ← Webhook: حط القيم دي، واشترك في <b>messages</b>.', 'In your Meta app → WhatsApp → Configuration → Webhook: paste these values and subscribe to <b>messages</b>.') + (d.last_webhook_at ? ' <span style="color:#16a34a">✓ ' + L('آخر رسالة وصلت', 'Last message received') + ': ' + esc(d.last_webhook_at) + ' UTC</span>' : '') + '</p>'
                + '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px"><span class="p-cell-muted" style="font-size:12.5px">Callback URL</span>' + copyBtn('waUrl') + '</div>' + code('waUrl', d.webhook_url)
                + '<div style="display:flex;justify-content:space-between;align-items:center"><span class="p-cell-muted" style="font-size:12.5px">Verify token</span>' + copyBtn('waVt') + '</div>' + code('waVt', d.verify_token);
        // جودة الرقم من Meta بكلام مفهوم (رقم جديد بيبقى UNKNOWN لحد ما يبعت رسايل كفاية)
        var QUALITY = { GREEN: [L('عالية', 'High'), '#16a34a'], YELLOW: [L('متوسطة - راجع رسايلك', 'Medium - review your messages'), '#d97706'], RED: [L('ضعيفة - Meta ممكن تحدّ رسايلك', 'Low - Meta may limit your messages'), '#dc2626'] };
        var q = QUALITY[String(d.quality || '').toUpperCase()] || [L('لسه بتتحسب (رقم جديد)', 'Not rated yet (new number)'), '#64748b'];
        var phone = d.coexistence
            ? '<p style="margin:0;font-size:13.5px">✅ ' + L('الرقم شغال على موبايلك وعلى المنصة مع بعض. ردودك من تطبيق واتساب بزنس بتظهر في الوارد، والرد الآلي بيسكت في أي محادثة رديت فيها من الموبايل.', 'This number works on your phone and on the platform together. Replies from the WhatsApp Business app show in the inbox, and auto-replies stop in any chat you answer from the phone.') + (d.last_echo_at ? ' <span class="p-cell-muted">(' + L('آخر رد من الموبايل', 'Last phone reply') + ': ' + esc(d.last_echo_at) + ' UTC)</span>' : '') + '</p>'
                + ((d.sync_errors || []).length ? '<div class="alert alert-warning" style="margin-top:8px">' + L('سحب المحادثات القديمة من الموبايل ماكملش: ', 'Importing older phone chats did not finish: ') + esc(d.sync_errors.join(' · ')) + '</div>' : '')
            : '<p style="margin:0 0 6px;font-size:13.5px">' + L('الرقم ده شغال على المنصة بس، ومش هيفتح في تطبيق واتساب العادي على الموبايل.', 'This number runs on the platform only and will not open in the regular WhatsApp app.') + '</p>'
                + '<p class="p-cell-muted" style="margin:0;font-size:12.5px">' + L('تتابع من الموبايل إزاي: اضغط «تثبيت التطبيق» تحت وافتح الوارد منه زي أي تطبيق. ولو عايز نفس الرقم يشتغل على تطبيق واتساب بزنس كمان: افصل الربط، ونزّل الرقم على تطبيق واتساب بزنس، واربطه تاني من زرار «اربط رقم واتساب بزنس اللي على موبايلي».', 'Follow from your phone: tap “Install app” below and open the inbox from it like any app. To also use this number in the WhatsApp Business app: disconnect, set the number up in the WhatsApp Business app, then reconnect with “Connect my WhatsApp Business app number”.') + '</p>';
        card.innerHTML = head(L('رقم شركتك الرسمي - الرسايل بتطلع باسمك', 'Your official company number - messages go out in your name'))
            + '<div class="alert alert-success">✔ ' + L('مربوط', 'Connected') + ': <b dir="ltr">' + esc(d.display_phone || d.phone_number_id) + '</b> ' + (d.verified_name ? '· ' + esc(d.verified_name) : '') + ' · ' + L('جودة الرقم', 'Number quality') + ': <b style="color:' + q[1] + '">' + esc(q[0]) + '</b></div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 12px"><a class="p-btn primary sm" href="/chat">📥 ' + L('افتح الوارد (رسايل العملاء)', 'Open inbox (customer messages)') + '</a><button class="p-btn outline sm" id="waProfileOpen">👤 ' + L('ملف الشركة على واتساب', 'WhatsApp business profile') + '</button><button class="p-btn outline sm" id="waDiag">🔎 ' + L('افحص استقبال الرسايل', 'Check incoming messages') + '</button></div><div id="waDiagOut" style="display:none;margin:0 0 12px"></div>'
            + '<div id="waProfile" style="display:none;margin:0 0 14px;padding:12px;border:1px solid var(--panel-border,#2a3247);border-radius:10px"></div>'
            + '<p style="margin:0 0 4px;font-weight:600">📱 ' + L('على الموبايل', 'On your phone') + '</p>' + phone
            + (!d.webhook_secure ? '<div class="alert alert-danger">' + L('استقبال الرسائل متوقف لحد إضافة App Secret: افصل الربط وأعد ربطه بالسر الصحيح.', 'Incoming messages are blocked until App Secret is configured. Reconnect with the correct secret.') + '</div>' : '')
            + '<div id="waDeliveryStatuses" class="p-cell-muted"></div>'
            + '<p style="margin:12px 0 2px;font-weight:600">1) ' + L('الرسايل الواردة', 'Incoming messages') + '</p>' + webhook
            + '<p style="margin:16px 0 6px;font-weight:600">2) ' + L('القوالب', 'Templates') + ' <span class="p-cell-muted" style="font-weight:400;font-size:12.5px">- ' + L('Meta بتشترط قالب متوافق عليه لأي رسالة انت بتبدأها (تذكير، طلب تقييم، متابعة)', 'Meta requires an approved template for any message you start (reminders, review requests, follow-ups)') + '</span></p>'
            + '<div style="overflow-x:auto"><table class="p-table" style="width:100%"><thead><tr><th>' + L('القالب', 'Template') + '</th><th>' + L('عربي', 'Arabic') + '</th><th>English</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' + (anyMissing ? '<button class="p-btn primary sm" id="waCreate">' + L('اعمل القوالب في حسابي', 'Create templates in my account') + '</button>' : '') + '<button class="p-btn outline sm" id="waSync">' + L('تحديث الحالة', 'Refresh status') + '</button></div>'
            + (allOk ? '' : '<p class="p-cell-muted" style="font-size:12.5px;margin-top:6px">' + L('لحد ما القوالب تتوافق، التذكير وطلب التقييم بيروحوا بالإيميل', 'Until templates are approved, reminders and review requests go by email') + (d.ultramsg_connected ? L(' أو UltraMsg', ' or UltraMsg') : '') + L('. الرد على الشات شغال من دلوقتي.', '. Chat replies work right away.') + '</p>')
            + '<p style="margin:16px 0 6px;font-weight:600">3) ' + L('جرّب', 'Test') + '</p>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap"><input id="waTestPhone" class="form-control" dir="ltr" style="max-width:220px" placeholder="2010xxxxxxxx"><button class="p-btn outline sm" id="waTest">' + L('ابعت رسالة تجربة', 'Send a test message') + '</button></div>'
            + '<div style="margin-top:16px;border-top:1px solid var(--panel-border,#2a3247);padding-top:12px"><button class="p-btn outline xs" id="waDisc">' + L('فصل الربط', 'Disconnect') + '</button></div>';
    }

    async function load() {
        site = (document.getElementById('websiteSelect') || {}).value || '';
        if (!card) return;
        if (!site) { card.style.display = 'none'; return; }
        card.style.display = 'block';
        card.innerHTML = '<div class="p-cell-muted">…</div>';
        if (embedCfg === null) {
            var c = await fetchJSON('/api/whatsapp-cloud/embedded-config');
            embedCfg = c.success && c.data.enabled ? c.data : false;
        }
        var r = await fetchJSON('/api/whatsapp-cloud/status?website_id=' + encodeURIComponent(site));
        if (!r.success) { card.innerHTML = '<div class="alert alert-danger">' + esc(r.error || L('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        (r.data.connected ? renderConnected : renderDisconnected)(r.data);
        if (r.data.connected) {
            var ds = await fetchJSON('/api/whatsapp-cloud/delivery-statuses?website_id=' + encodeURIComponent(site));
            var el = document.getElementById('waDeliveryStatuses');
            if (el && ds.success) {
                var labels = {sent:L('تم الإرسال','Sent'), delivered:L('تم التسليم','Delivered'), read:L('تمت القراءة','Read'), failed:L('فشل الإرسال','Failed')};
                el.innerHTML = '<details><summary>' + L('آخر حالات تسليم الرسائل','Recent message delivery statuses') + '</summary>' + (ds.data.statuses || []).map(function(s){return '<p dir="ltr">' + esc(s.wamid) + ' · ' + esc(labels[s.status] || s.status) + (s.error_message ? ' · ' + esc(s.error_message) : '') + '</p>';}).join('') + '</details>';
            }
        }
    }

    // ملف الشركة اللي العميل بيشوفه لما يفتح بروفايل الرقم على واتساب
    var VERT = { TRAVEL: L('سفر وسياحة', 'Travel'), HOTEL: L('فنادق وإقامة', 'Hotel & lodging'), RESTAURANT: L('مطاعم', 'Restaurant'), ENTERTAIN: L('ترفيه', 'Entertainment'), EVENT_PLAN: L('تنظيم مناسبات', 'Event planning'), PROF_SERVICES: L('خدمات مهنية', 'Professional services'), RETAIL: L('تجارة', 'Retail'), AUTO: L('سيارات', 'Automotive'), BEAUTY: L('تجميل', 'Beauty'), APPAREL: L('ملابس', 'Apparel'), EDU: L('تعليم', 'Education'), FINANCE: L('مالية', 'Finance'), GROCERY: L('بقالة', 'Grocery'), HEALTH: L('صحة', 'Health'), NONPROFIT: L('غير ربحي', 'Non-profit'), OTHER: L('أخرى', 'Other') };
    async function loadProfile(box) {
        box.innerHTML = '<div class="p-cell-muted">…</div>';
        var r = await fetchJSON('/api/whatsapp-cloud/profile?website_id=' + encodeURIComponent(site));
        if (!r.success) { box.innerHTML = '<div class="alert alert-danger">' + esc(r.error || L('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        var p = r.data.profile || {}, w = p.websites || [];
        var f = function (id, label, val, max, rows) { return '<div class="form-group"><label class="form-label">' + label + ' <span class="p-cell-muted">(' + max + ' ' + L('حرف', 'chars') + ')</span></label>' + (rows ? '<textarea id="' + id + '" class="form-control" dir="auto" rows="' + rows + '" maxlength="' + max + '">' + esc(val) + '</textarea>' : '<input id="' + id + '" class="form-control" dir="auto" maxlength="' + max + '" value="' + esc(val) + '">') + '</div>'; };
        box.innerHTML = '<p class="p-cell-muted" style="margin:0 0 10px;font-size:12.5px">' + L('ده اللي عملاءك بيشوفوه لما يفتحوا بروفايل رقمك على واتساب. اسم الشركة نفسه بيتغير من Meta (WhatsApp Manager) وبيحتاج موافقتهم.', 'This is what customers see when they open your number’s profile on WhatsApp. The display name itself is changed in WhatsApp Manager and needs Meta approval.') + '</p>'
            + '<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">' + (p.profile_picture_url ? '<img src="' + esc(p.profile_picture_url) + '" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover">' : '<div style="width:64px;height:64px;border-radius:50%;background:rgba(127,127,127,.2)"></div>') + '<div><input type="file" id="wpPhoto" accept="image/jpeg,image/png"><div style="margin-top:6px"><button class="p-btn outline xs" id="waPhotoSave">' + L('غيّر الصورة', 'Change photo') + '</button> <span class="p-cell-muted" style="font-size:12px">' + L('JPG أو PNG مربعة، 640×640 أحسن', 'Square JPG or PNG, 640×640 is best') + '</span></div></div></div>'
            + f('wpAbout', L('نبذة قصيرة (About)', 'About'), p.about || '', 139)
            + f('wpDesc', L('وصف الشركة', 'Description'), p.description || '', 512, 3)
            + f('wpAddr', L('العنوان', 'Address'), p.address || '', 256)
            + f('wpEmail', L('الإيميل', 'Email'), p.email || '', 128)
            + '<div class="form-group"><label class="form-label">' + L('المواقع (2 بالكتير)', 'Websites (max 2)') + '</label><input id="wpWeb1" class="form-control" dir="ltr" placeholder="https://" value="' + esc(w[0] || '') + '"><input id="wpWeb2" class="form-control" dir="ltr" placeholder="https://" style="margin-top:6px" value="' + esc(w[1] || '') + '"></div>'
            + '<div class="form-group"><label class="form-label">' + L('مجال النشاط', 'Business category') + '</label><select id="wpVertical" class="form-control">' + (r.data.verticals || []).map(function (v) { return '<option value="' + v + '"' + (v === (p.vertical || 'TRAVEL') ? ' selected' : '') + '>' + esc(VERT[v] || v) + '</option>'; }).join('') + '</select></div>'
            + '<button class="p-btn primary sm" id="waProfileSave">' + L('احفظ الملف', 'Save profile') + '</button>';
    }

    async function post(url, body) {
        return fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    }
    function busy(btn, on, label) { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.l = btn.innerHTML; btn.textContent = label || '…'; } else if (btn.dataset.l) btn.innerHTML = btn.dataset.l; }

    // ------------------------------------------------------------ الربط بضغطة (Embedded Signup)
    function loadFb(cfg) {
        return new Promise(function (resolve, reject) {
            if (window.FB) { resolve(); return; }
            window.fbAsyncInit = function () { window.FB.init({ appId: cfg.app_id, autoLogAppEvents: true, xfbml: false, version: cfg.version }); resolve(); };
            var s = document.createElement('script');
            s.async = true; s.defer = true; s.crossOrigin = 'anonymous';
            s.src = 'https://connect.facebook.net/' + (AR ? 'ar_AR' : 'en_US') + '/sdk.js';
            s.onerror = function () { reject(new Error(L('تعذر تحميل فيسبوك - جرّب تاني أو اقفل مانع الإعلانات', 'Could not load Facebook - try again or disable your ad blocker'))); };
            document.body.appendChild(s);
        });
    }
    window.addEventListener('message', function (ev) {
        var host = '';
        try { host = new URL(ev.origin).hostname; } catch (x) { return; }
        if (!/(^|\.)facebook\.com$/.test(host)) return;
        try {
            var d = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data;
            if (d && d.type === 'WA_EMBEDDED_SIGNUP') {
                if (d.event === 'FINISH' || d.event === 'FINISH_ONLY_WABA' || d.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') { esData = d.data || {}; esData.phone_app = d.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'; }
                else if (d.event === 'CANCEL') esData = { cancelled: true, step: (d.data || {}).current_step };
            }
        } catch (x) { /* رسايل تانية من فيسبوك */ }
    });
    async function embeddedSignup(btn, coexist) {
        var al = document.getElementById('waEmbedAlert'); al.style.display = 'none';
        busy(btn, true, L('بنفتح فيسبوك…', 'Opening Facebook…'));
        try { await loadFb(embedCfg); } catch (e) { busy(btn, false); al.textContent = e.message; al.style.display = 'block'; return; }
        esData = {};
        window.FB.login(function (resp) {
            (async function () {
                if (!resp || !resp.authResponse || !resp.authResponse.code) {
                    busy(btn, false);
                    if (esData.cancelled) { al.textContent = L('اتقفلت النافذة قبل ما الربط يخلص', 'The window was closed before setup finished') + (esData.step ? ' (' + esData.step + ')' : ''); al.style.display = 'block'; }
                    return;
                }
                busy(btn, true, L('بنكمّل الربط…', 'Finishing setup…'));
                // بيانات الرقم بتيجي في رسالة منفصلة من نافذة Meta - نستناها ثواني لو اتأخرت
                for (var i = 0; i < 20 && !esData.phone_number_id; i++) await new Promise(function (r) { setTimeout(r, 150); });
                var r = await post('/api/whatsapp-cloud/embedded', { website_id: site, code: resp.authResponse.code, waba_id: esData.waba_id || '', phone_number_id: esData.phone_number_id || '', coexistence: !!(coexist || esData.phone_app) });
                busy(btn, false);
                if (!r.success) { al.textContent = r.error || L('تعذر الربط', 'Could not connect'); al.style.display = 'block'; return; }
                toast(r.message || L('اتربط', 'Connected'), 'success');
                (r.data && r.data.warnings || []).forEach(function (w) { toast(w, 'error'); });
                load();
            })();
        }, { config_id: embedCfg.config_id, response_type: 'code', override_default_response_type: true, extras: { setup: {}, featureType: coexist ? 'whatsapp_business_app_onboarding' : '', sessionInfoVersion: '3' } });
    }

    document.addEventListener('click', async function (e) {
        if (!card || !card.contains(e.target)) return;
        var t = e.target.closest('button') || e.target;
        if (t.dataset.copy) {
            var el = document.getElementById(t.dataset.copy);
            try { await navigator.clipboard.writeText(el.textContent); toast(L('اتنسخ', 'Copied'), 'success'); } catch (x) { }
            return;
        }
        if (t.id === 'waEmbed' || t.id === 'waEmbedApp') {
            embeddedSignup(t, t.id === 'waEmbedApp');
        } else if (t.id === 'waDiag') {
            busy(t, true, L('بنسأل Meta…', 'Asking Meta…'));
            var dg = await post('/api/whatsapp-cloud/diagnose', { website_id: site });
            busy(t, false);
            var out = document.getElementById('waDiagOut');
            out.style.display = 'block';
            if (!dg.success) { out.innerHTML = '<div class="alert alert-danger">' + esc(dg.error || '') + '</div>'; return; }
            var c = dg.data.checks || {}, row = function (ok, a, e) { return '<li>' + (ok ? '✅ ' : '❌ ') + esc(L(a, e)) + '</li>'; };
            out.innerHTML = '<ul style="margin:0 0 6px;padding-inline-start:18px;line-height:1.9;font-size:13.5px">'
                + row(c.app_subscribed, 'تطبيق المنصة مشترك في رسايل الرقم', 'The platform app is subscribed to this number') 
                + row(c.number_ok, 'الرقم شغال على Meta', 'The number is active on Meta')
                + row(!!c.webhook_verified_at, 'Meta اتحققت من رابط الاستقبال', 'Meta verified the webhook URL')
                + row(!!c.this_number_last_event_at, 'وصلت رسايل للرقم ده', 'Messages reached this number') + '</ul>'
                + (dg.data.hints || []).map(function (h) { return '<div class="alert alert-warning" style="margin:6px 0">' + esc(h) + '</div>'; }).join('');
        } else if (t.id === 'waProfileOpen') {
            var box = document.getElementById('waProfile');
            if (box.style.display === 'none') { box.style.display = 'block'; loadProfile(box); } else box.style.display = 'none';
        } else if (t.id === 'waProfileSave') {
            busy(t, true, L('بنحفظ…', 'Saving…'));
            var g = function (id) { return document.getElementById(id).value; };
            var sv = await post('/api/whatsapp-cloud/profile', { website_id: site, about: g('wpAbout'), description: g('wpDesc'), address: g('wpAddr'), email: g('wpEmail'), vertical: g('wpVertical'), websites: [g('wpWeb1'), g('wpWeb2')] });
            busy(t, false);
            toast(sv.message || sv.error, sv.success ? 'success' : 'error');
        } else if (t.id === 'waPhotoSave') {
            var fi = document.getElementById('wpPhoto');
            if (!fi.files || !fi.files[0]) { toast(L('اختار صورة الأول', 'Choose a photo first'), 'error'); return; }
            var fd = new FormData(); fd.append('website_id', site); fd.append('photo', fi.files[0]);
            busy(t, true, L('بنرفع…', 'Uploading…'));
            var ph = await fetchJSON('/api/whatsapp-cloud/profile/photo', { method: 'POST', body: fd });
            busy(t, false);
            toast(ph.message || ph.error, ph.success ? 'success' : 'error');
            if (ph.success) loadProfile(document.getElementById('waProfile'));
        } else if (t.id === 'waConnect') {
            var al = document.getElementById('waAlert'); al.style.display = 'none';
            busy(t, true, L('بنتأكد من البيانات مع Meta…', 'Checking with Meta…'));
            var r = await post('/api/whatsapp-cloud/connect', { website_id: site, phone_number_id: document.getElementById('waPn').value, waba_id: document.getElementById('waWaba').value, access_token: document.getElementById('waTok').value, app_secret: document.getElementById('waSec').value });
            busy(t, false);
            if (!r.success) { al.textContent = r.error || L('تعذر الربط', 'Could not connect'); al.style.display = 'block'; return; }
            toast(r.message || L('اتربط', 'Connected'), 'success');
            if (r.data && r.data.subscribe_error) toast(L('اتربط، بس الاشتراك في الرسايل الواردة فشل: ', 'Connected, but subscribing to incoming messages failed: ') + r.data.subscribe_error, 'error');
            load();
        } else if (t.id === 'waCreate') {
            busy(t, true, L('بنبعت القوالب لـ Meta…', 'Submitting templates to Meta…'));
            var c = await post('/api/whatsapp-cloud/templates', { website_id: site });
            busy(t, false);
            toast(c.message || c.error, c.success ? 'success' : 'error');
            load();
        } else if (t.id === 'waSync') {
            busy(t, true);
            var s = await fetchJSON('/api/whatsapp-cloud/templates?website_id=' + encodeURIComponent(site));
            busy(t, false);
            if (!s.success) toast(s.error, 'error');
            load();
        } else if (t.id === 'waTest') {
            busy(t, true, L('بنبعت…', 'Sending…'));
            var x = await post('/api/whatsapp-cloud/test', { website_id: site, phone: document.getElementById('waTestPhone').value });
            busy(t, false);
            toast(x.message || x.error, x.success ? 'success' : 'error');
        } else if (t.id === 'waDisc') {
            if (!confirm(L('فصل واتساب الرسمي؟ التذكير والشات هيرجعوا لـ UltraMsg (لو مربوط) أو الإيميل.', 'Disconnect official WhatsApp? Reminders and chat will fall back to UltraMsg (if connected) or email.'))) return;
            var d = await post('/api/whatsapp-cloud/disconnect/' + encodeURIComponent(site));
            toast(d.message || d.error, d.success ? 'success' : 'error');
            load();
        }
    });

    function init() {
        card = document.getElementById('waCloudCard');
        var sel = document.getElementById('websiteSelect');
        if (!card || !sel) return;
        sel.addEventListener('change', load);
        var tries = 0, iv = setInterval(function () { tries++; if (sel.value || tries > 40) { clearInterval(iv); if (sel.value) load(); } }, 250);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
