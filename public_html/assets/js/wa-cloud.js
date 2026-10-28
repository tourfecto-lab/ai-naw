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
                + '<p class="p-cell-muted" style="margin:0;font-size:12.5px">' + L('تتابع من أي موبايل أو كمبيوتر: افتح الوارد من المتصفح أو اضغط «تثبيت التطبيق». ولفريقك: ضيفهم من <a href="/business-center">مركز الأعمال ← الفريق</a> بدور «عضو»، وكل واحد يدخل بحسابه ويرد من الوارد.', 'Follow from any phone or computer: open the inbox in the browser or tap “Install app”. For your team: invite them in <a href="/business-center">Business Center → Team</a> as “Member”; each person signs in with their own account and replies from the inbox.') + '</p>'
                + '<details style="margin-top:10px;padding:10px 12px;border:1px dashed var(--panel-border,#2a3247);border-radius:10px"><summary style="cursor:pointer;font-weight:600">🔄 ' + L('عايز الرقم يشتغل كمان على تطبيق واتساب بزنس وأجهزته المرتبطة؟ (الوضع المشترك)', 'Also use this number in the WhatsApp Business app and its linked devices? (shared mode)') + '</summary>'
                + '<p class="p-cell-muted" style="font-size:12.5px;margin:8px 0">' + L('في الوضع المشترك الرقم بيفضل على تطبيق واتساب بزنس في موبايلك (وتقدر تربط منه واتساب ويب وأجهزة تانية)، وكل الرسايل بتظهر هنا كمان والرد الآلي شغال. Meta بتوقف بعض مزايا التطبيق في الوضع ده، راجعها في صفحة Meta قبل ما تبدأ.', 'In shared mode the number stays on the WhatsApp Business app on your phone (and you can link WhatsApp Web and other devices from it), while every message also shows here and auto-replies keep working. Meta turns off some app features in this mode; check them on Meta’s page before you start.') + '</p>'
                + '<ol style="line-height:1.9;font-size:13px;margin:0;padding-inline-start:20px">'
                + '<li>' + L('اختار وقت هادي: الرقم ممكن يقف شوية وقت التحويل.', 'Pick a quiet time: the number can be unavailable for a while during the switch.') + '</li>'
                + '<li>' + L('اضغط «فصل الربط» تحت.', 'Click “Disconnect” below.') + '</li>'
                + '<li>' + L('في <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank" rel="noopener">WhatsApp Manager ← أرقام الهواتف</a> احذف الرقم من الحساب (لازم علشان التطبيق يقبله).', 'In <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank" rel="noopener">WhatsApp Manager → Phone numbers</a>, delete the number from the account (needed before the app accepts it).') + '</li>'
                + '<li>' + L('نزّل آخر نسخة من تطبيق واتساب بزنس وسجّل الرقم عليه.', 'Install the latest WhatsApp Business app and register the number in it.') + '</li>'
                + '<li>' + L('ارجع هنا واضغط «اربط رقم واتساب بزنس اللي على موبايلي» وامسح الكود من التطبيق.', 'Come back here, click “Connect my WhatsApp Business app number” and scan the code from the app.') + '</li>'
                + '<li>' + L('من التطبيق: الإعدادات ← الأجهزة المرتبطة، واربط واتساب ويب أو أي جهاز تاني.', 'In the app: Settings → Linked devices, then link WhatsApp Web or other devices.') + '</li>'
                + '</ol></details>';
        card.innerHTML = head(L('رقم شركتك الرسمي - الرسايل بتطلع باسمك', 'Your official company number - messages go out in your name'))
            + '<div class="alert alert-success">✔ ' + L('مربوط', 'Connected') + ': <b dir="ltr">' + esc(d.display_phone || d.phone_number_id) + '</b> ' + (d.verified_name ? '· ' + esc(d.verified_name) : '') + ' · ' + L('جودة الرقم', 'Number quality') + ': <b style="color:' + q[1] + '">' + esc(q[0]) + '</b></div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 12px"><a class="p-btn primary sm" href="/chat">📥 ' + L('افتح الوارد (رسايل العملاء)', 'Open inbox (customer messages)') + '</a><button class="p-btn outline sm" id="waProfileOpen">👤 ' + L('ملف الشركة على واتساب', 'WhatsApp business profile') + '</button><button class="p-btn outline sm" data-panel="waHealth">📊 ' + L('حالة الحساب', 'Account status') + '</button><button class="p-btn outline sm" data-panel="waCatalog">🛍 ' + L('الكتالوج', 'Catalog') + '</button><button class="p-btn outline sm" data-panel="waAuto">💬 ' + L('أسئلة البداية والأوامر', 'Starter questions & commands') + '</button><button class="p-btn outline sm" data-panel="waSecurity">🔐 ' + L('الأمان والحظر', 'Security & blocking') + '</button><button class="p-btn outline sm" id="waDiag">🔎 ' + L('افحص استقبال الرسايل', 'Check incoming messages') + '</button></div><div id="waDiagOut" style="display:none;margin:0 0 12px"></div>'
            + '<div id="waProfile" style="display:none;margin:0 0 14px;padding:12px;border:1px solid var(--panel-border,#2a3247);border-radius:10px"></div>'
            + ['waHealth', 'waCatalog', 'waAuto', 'waSecurity'].map(function (id) { return '<div id="' + id + '" class="wa-panel" style="display:none;margin:0 0 14px;padding:12px;border:1px solid var(--panel-border,#2a3247);border-radius:10px"></div>'; }).join('')
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
            + '<div style="display:flex;gap:12px;align-items:center;margin-bottom:8px;flex-wrap:wrap">' + (p.profile_picture_url ? '<img id="wpAvatar" src="' + esc(p.profile_picture_url) + '" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover" onerror="this.style.visibility=\'hidden\'">' : '<img id="wpAvatar" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;background:rgba(127,127,127,.2);visibility:hidden">') + '<div><input type="file" id="wpPhoto" accept="image/jpeg,image/png,image/webp" style="display:none"><button type="button" class="p-btn outline xs" id="wpPick">📷 ' + L(p.profile_picture_url ? 'غيّر الصورة' : 'ضيف صورة', p.profile_picture_url ? 'Change photo' : 'Add photo') + '</button> <span class="p-cell-muted" style="font-size:12px">' + L('اختار أي صورة وظبط مكانها في الدايرة', 'Pick any photo, then position it in the circle') + '</span>' + (p.profile_picture_url ? '' : '<div class="p-cell-muted" style="font-size:12px;margin-top:4px">' + L('مفيش صورة على الرقم لسه', 'No photo on this number yet') + '</div>') + '</div></div>'
            + '<div id="wpCrop" style="display:none;margin:0 0 14px;padding:10px;border:1px dashed var(--panel-border,#2a3247);border-radius:10px"></div>'
            + f('wpAbout', L('نبذة قصيرة (About)', 'About'), p.about || '', 139)
            + f('wpDesc', L('وصف الشركة', 'Description'), p.description || '', 512, 3)
            + f('wpAddr', L('العنوان', 'Address'), p.address || '', 256)
            + f('wpEmail', L('الإيميل', 'Email'), p.email || '', 128)
            + '<div class="form-group"><label class="form-label">' + L('المواقع (2 بالكتير)', 'Websites (max 2)') + '</label><input id="wpWeb1" class="form-control" dir="ltr" placeholder="https://" value="' + esc(w[0] || '') + '"><input id="wpWeb2" class="form-control" dir="ltr" placeholder="https://" style="margin-top:6px" value="' + esc(w[1] || '') + '"></div>'
            + '<div class="form-group"><label class="form-label">' + L('مجال النشاط', 'Business category') + '</label><select id="wpVertical" class="form-control">' + (r.data.verticals || []).map(function (v) { return '<option value="' + v + '"' + (v === (p.vertical || 'TRAVEL') ? ' selected' : '') + '>' + esc(VERT[v] || v) + '</option>'; }).join('') + '</select></div>'
            + '<button class="p-btn primary sm" id="waProfileSave">' + L('احفظ الملف', 'Save profile') + '</button>';
    }


    // ------------------------------------------------------------ حالة الحساب والكتالوج والتحكم
    function pill(text, color) { return '<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;background:' + color + '1a;color:' + color + '">' + esc(text) + '</span>'; }
    function kv(k, v, hint) { return '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:7px 0;border-bottom:1px solid var(--panel-border,#2a3247)"><span>' + k + (hint ? '<br><span class="p-cell-muted" style="font-size:12px">' + hint + '</span>' : '') + '</span><span style="text-align:end">' + v + '</span></div>'; }
    var GOOD = '#16a34a', WARN = '#d97706', BAD = '#dc2626', NEUTRAL = '#64748b';

    async function loadHealth(box) {
        box.innerHTML = '<div class="p-cell-muted">' + L('بنسأل Meta…', 'Asking Meta…') + '</div>';
        var r = await fetchJSON('/api/whatsapp-cloud/health?website_id=' + encodeURIComponent(site));
        if (!r.success) { box.innerHTML = '<div class="alert alert-danger">' + esc(r.error || '') + '</div>'; return; }
        var p = r.data.phone || {}, a = r.data.account || {}, out = '';
        var st = String(p.status || '').toUpperCase();
        var STATUS = { CONNECTED: [L('شغال', 'Active'), GOOD], PENDING: [L('مستني التفعيل', 'Pending'), WARN], FLAGGED: [L('عليه إنذار - الجودة نازلة', 'Flagged - quality dropped'), WARN], RESTRICTED: [L('مقيّد - ممنوع تبدأ محادثات', 'Restricted - cannot start chats'), BAD], RATE_LIMITED: [L('وصل لحد الرسايل مؤقتًا', 'Temporarily rate limited'), WARN], BANNED: [L('محظور من Meta', 'Banned by Meta'), BAD], DISCONNECTED: [L('مفصول', 'Disconnected'), BAD], MIGRATED: [L('اتنقل لحساب تاني', 'Migrated'), WARN], UNVERIFIED: [L('مش متوثق', 'Unverified'), WARN] };
        var s1 = STATUS[st] || [st || L('مش معروف', 'Unknown'), NEUTRAL];
        out += kv(L('حالة الرقم', 'Number status'), pill(s1[0], s1[1]));
        var QUAL = { GREEN: [L('عالية', 'High'), GOOD], YELLOW: [L('متوسطة', 'Medium'), WARN], RED: [L('ضعيفة', 'Low'), BAD] };
        var q = QUAL[String(p.quality_rating || '').toUpperCase()] || [L('لسه بتتحسب', 'Not rated yet'), NEUTRAL];
        out += kv(L('جودة الرقم', 'Quality rating'), pill(q[0], q[1]), L('بتتحسب من تقييم العملاء لرسايلك (بلوك/إبلاغ). لو نزلت، قلّل الرسايل التسويقية.', 'Based on how customers react (blocks/reports). If it drops, send fewer marketing messages.'));
        var tier = String(a.whatsapp_business_manager_messaging_limit || p.messaging_limit_tier || '');
        var TIERS = { TIER_50: '50', TIER_250: '250', TIER_1K: '1,000', TIER_2K: '2,000', TIER_10K: '10,000', TIER_100K: '100,000', TIER_UNLIMITED: L('بلا حد', 'Unlimited') };
        if (tier) out += kv(L('حد المحادثات اللي تبدأها', 'Business-initiated limit'), '<b>' + esc(TIERS[tier] || tier) + '</b> ' + L('عميل/24 ساعة', 'customers / 24h'), L('عدد العملاء المختلفين اللي تقدر تبعتلهم قالب في اليوم. الرد على اللي كلموك مش محسوب. بيزيد لوحده مع الاستخدام والجودة العالية.', 'How many different customers you can start a template chat with per day. Replies to customers who wrote first don’t count. It grows with use and good quality.'));
        var NAME = { APPROVED: [L('متوافق عليه', 'Approved'), GOOD], AVAILABLE_WITHOUT_REVIEW: [L('شغال من غير مراجعة', 'Available without review'), GOOD], PENDING_REVIEW: [L('تحت المراجعة', 'In review'), WARN], DECLINED: [L('اترفض', 'Declined'), BAD], EXPIRED: [L('انتهى', 'Expired'), BAD], NON_EXISTS: [L('مش موجود', 'Not set'), NEUTRAL], NONE: [L('مش موجود', 'Not set'), NEUTRAL] };
        var nm = NAME[String(p.name_status || '').toUpperCase()] || [String(p.name_status || '-'), NEUTRAL];
        out += kv(L('اسم العرض', 'Display name'), '<b>' + esc(p.verified_name || '-') + '</b> ' + pill(nm[0], nm[1]) + (p.new_name_status && p.new_name_status !== 'NONE' ? '<br><span class="p-cell-muted" style="font-size:12px">' + L('اسم جديد: ', 'New name: ') + esc(p.new_name_status) + '</span>' : ''), L('الاسم بيتغير من WhatsApp Manager وبيحتاج موافقة Meta.', 'Change it in WhatsApp Manager; Meta reviews it.'));
        if (p.code_verification_status) out += kv(L('توثيق الرقم', 'Number verification'), p.code_verification_status === 'VERIFIED' ? pill(L('متوثق', 'Verified'), GOOD) : pill(p.code_verification_status, WARN));
        if (p.account_mode) out += kv(L('وضع الحساب', 'Account mode'), p.account_mode === 'LIVE' ? pill(L('حقيقي (Live)', 'Live'), GOOD) : pill(L('تجريبي - بيبعت لأرقام الاختبار بس', 'Sandbox - test numbers only'), WARN));
        if (p.is_official_business_account !== undefined) out += kv(L('العلامة الخضرا (حساب رسمي)', 'Official business badge'), p.is_official_business_account ? pill(L('موجودة', 'Yes'), GOOD) : pill(L('مش موجودة', 'No'), NEUTRAL), p.is_official_business_account ? '' : L('بتتطلب من WhatsApp Manager بعد توثيق الشركة.', 'Request it in WhatsApp Manager after business verification.'));
        if (a.account_review_status) out += kv(L('مراجعة الحساب', 'Account review'), a.account_review_status === 'APPROVED' ? pill(L('متوافق عليه', 'Approved'), GOOD) : pill(a.account_review_status, a.account_review_status === 'REJECTED' ? BAD : WARN));
        if (a.business_verification_status) out += kv(L('توثيق الشركة', 'Business verification'), a.business_verification_status === 'verified' ? pill(L('متوثقة', 'Verified'), GOOD) : pill(({ not_verified: L('مش متوثقة', 'Not verified'), pending: L('تحت المراجعة', 'In review'), pending_submission: L('مستنية تقديم الطلب', 'Not submitted'), rejected: L('اترفضت', 'Rejected'), failed: L('فشلت', 'Failed') })[a.business_verification_status] || a.business_verification_status, a.business_verification_status === 'rejected' ? BAD : WARN), a.business_verification_status === 'verified' ? '' : L('من Business Settings ← Security Center في Meta. بيرفع الحدود ويفتح العلامة الخضرا.', 'From Meta Business Settings → Security Center. Raises limits and enables the badge.'));
        if (p.throughput && p.throughput.level) out += kv(L('سرعة الإرسال', 'Throughput'), esc(p.throughput.level));
        box.innerHTML = out + '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><button class="p-btn outline xs" data-reload="waHealth">' + L('تحديث', 'Refresh') + '</button><a class="p-btn outline xs" href="https://business.facebook.com/wa/manage/home/" target="_blank" rel="noopener">WhatsApp Manager ↗</a></div>';
    }

    async function loadCatalog(box, catalogId) {
        box.innerHTML = '<div class="p-cell-muted">…</div>';
        var r = await fetchJSON('/api/whatsapp-cloud/catalog?website_id=' + encodeURIComponent(site) + (catalogId ? '&catalog_id=' + encodeURIComponent(catalogId) : ''));
        if (!r.success) { box.innerHTML = '<div class="alert alert-danger">' + esc(r.error || '') + '</div>'; return; }
        var d = r.data, html = '<p class="p-cell-muted" style="margin:0 0 10px;font-size:12.5px">' + L('الكتالوج بيعرض رحلاتك وخدماتك بالصور والأسعار جوه واتساب: العميل يتصفحها من بروفايلك، وانت تبعتها من الشات بضغطة، ولو السلة شغالة يقدر يبعتلك طلب.', 'The catalog shows your trips and services with photos and prices inside WhatsApp: customers browse it from your profile, you send items from the chat in one tap, and with the cart on they can send you an order.') + '</p>';
        if (!d.catalogs.length) {
            html += '<div class="alert alert-warning">' + (d.catalogs_error
                ? L('مقدرناش نقرا الكتالوجات من Meta: ', 'Could not read catalogs from Meta: ') + esc(d.catalogs_error) + '<br>' + L('غالبًا الربط اتعمل من غير صلاحية الكتالوج (catalog_management). أعد الربط وفعّلها، أو اربط الكتالوج من Commerce Manager.', 'The connection was probably made without catalog access (catalog_management). Reconnect with it, or link the catalog in Commerce Manager.')
                : L('مفيش كتالوج مربوط برقمك. اعمله واربطه في 3 خطوات:', 'No catalog is linked to your number yet. Set one up in 3 steps:')) + '</div>'
                + '<ol style="line-height:1.9;font-size:13.5px;margin:6px 0;padding-inline-start:20px"><li>' + L('ادخل <a href="https://business.facebook.com/commerce" target="_blank" rel="noopener">Commerce Manager</a> واعمل كتالوج نوع «Travel / Other».', 'Open <a href="https://business.facebook.com/commerce" target="_blank" rel="noopener">Commerce Manager</a> and create a catalog.') + '</li><li>' + L('ضيف رحلاتك (اسم، صورة، سعر، رابط الحجز).', 'Add your trips (name, photo, price, booking link).') + '</li><li>' + L('من WhatsApp Manager ← Catalog: اختار الكتالوج واربطه برقمك، وبعدين ارجع هنا واضغط تحديث.', 'In WhatsApp Manager → Catalog, connect it to your number, then come back and refresh.') + '</li></ol>';
        } else {
            if (d.catalogs.length > 1) html += '<div class="form-group"><label class="form-label">' + L('الكتالوج المستخدم في الشات', 'Catalog used in the chat') + '</label><select id="waCatSel" class="form-control">' + d.catalogs.map(function (c) { return '<option value="' + esc(c.id) + '"' + (c.id === d.catalog_id ? ' selected' : '') + '>' + esc(c.name || c.id) + '</option>'; }).join('') + '</select></div>';
            else html += '<p style="margin:0 0 8px">' + L('الكتالوج المربوط: ', 'Linked catalog: ') + '<b>' + esc(d.catalogs[0].name || d.catalogs[0].id) + '</b></p>';
        }
        if (d.settings_ok) {
            html += '<label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="waCatVisible"' + (d.catalog_visible ? ' checked' : '') + '> ' + L('اعرض الكتالوج في بروفايل الرقم وفي الشات (أيقونة المتجر)', 'Show the catalog on the profile and in chats (shop icon)') + '</label>'
                + '<label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="waCatCart"' + (d.cart_enabled ? ' checked' : '') + '> ' + L('شغّل السلة (العميل يختار كذا رحلة ويبعتلك طلب يظهر في الوارد)', 'Turn on the cart (customers pick items and send you an order that shows in the inbox)') + '</label>'
                + '<button class="p-btn primary sm" id="waCatSave">' + L('احفظ', 'Save') + '</button>';
        }
        if (d.products.length) {
            html += '<p style="margin:14px 0 6px;font-weight:600">' + L('المنتجات', 'Products') + ' (' + d.products.length + ')</p><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px">' + d.products.map(function (p) {
                return '<div style="border:1px solid var(--panel-border,#2a3247);border-radius:8px;overflow:hidden">' + (p.image_url ? '<img src="' + esc(p.image_url) + '" alt="" loading="lazy" style="width:100%;height:90px;object-fit:cover;display:block">' : '') + '<div style="padding:6px 8px;font-size:12.5px"><b>' + esc(p.name) + '</b><br><span class="p-cell-muted">' + esc(p.price) + '</span>' + (p.availability && p.availability !== 'in stock' ? '<br>' + pill(p.availability, WARN) : '') + '</div></div>';
            }).join('') + '</div><p class="p-cell-muted" style="font-size:12px;margin-top:6px">' + L('تعديل المنتجات نفسها من Commerce Manager.', 'Edit products in Commerce Manager.') + '</p>';
        } else if (d.products_error) {
            html += '<p class="p-cell-muted" style="font-size:12.5px;margin-top:10px">' + L('مقدرناش نعرض المنتجات (', 'Could not list products (') + esc(d.products_error) + L('). تقدر تبعت الكتالوج كله من الشات برضه.', '). You can still send the whole catalog from the chat.') + '</p>';
        }
        box.innerHTML = html + '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><button class="p-btn outline xs" data-reload="waCatalog">' + L('تحديث', 'Refresh') + '</button><a class="p-btn outline xs" href="https://business.facebook.com/commerce" target="_blank" rel="noopener">Commerce Manager ↗</a></div>';
    }

    async function loadAuto(box) {
        box.innerHTML = '<div class="p-cell-muted">…</div>';
        var r = await fetchJSON('/api/whatsapp-cloud/automation?website_id=' + encodeURIComponent(site));
        if (!r.success) { box.innerHTML = '<div class="alert alert-danger">' + esc(r.error || '') + '</div>'; return; }
        var pr = r.data.prompts || [], cm = r.data.commands || [];
        var prompts = [0, 1, 2, 3].map(function (i) { return '<input class="form-control wa-prompt" dir="auto" maxlength="80" style="margin-bottom:6px" placeholder="' + esc([L('إيه الرحلات المتاحة الأسبوع ده؟', 'What trips are available this week?'), L('عايز أعرف الأسعار', 'I want to know the prices'), L('إزاي أحجز؟', 'How do I book?'), L('عايز أكلم موظف', 'I want to talk to an agent')][i]) + '" value="' + esc(pr[i] || '') + '">'; }).join('');
        var rowsHtml = function (list) { return list.map(function (c) { return '<div class="wa-cmd" style="display:grid;grid-template-columns:130px 1fr 30px;gap:6px;margin-bottom:6px"><input class="form-control wa-cmd-n" dir="ltr" maxlength="32" placeholder="prices" value="' + esc(c.command_name) + '"><input class="form-control wa-cmd-d" dir="auto" maxlength="256" placeholder="' + esc(L('اعرض الأسعار', 'Show prices')) + '" value="' + esc(c.command_description) + '"><button type="button" class="p-btn outline xs wa-cmd-del">✕</button></div>'; }).join(''); };
        box.innerHTML = '<p class="p-cell-muted" style="margin:0 0 10px;font-size:12.5px">' + L('أسئلة البداية بتظهر للعميل كأزرار أول ما يفتح شات جديد معاك، والأوامر بتظهر لما يكتب «/». اللي العميل يختاره بيوصلك رسالة عادية والرد الآلي بيرد عليها.', 'Starter questions show as buttons when a customer opens a new chat with you; commands show when they type “/”. Whatever they pick arrives as a normal message the auto-reply answers.') + '</p>'
            + '<p style="margin:0 0 6px;font-weight:600">' + L('أسئلة البداية (4 بالكتير، 80 حرف)', 'Starter questions (up to 4, 80 chars)') + '</p>' + prompts
            + '<p style="margin:12px 0 6px;font-weight:600">' + L('الأوامر', 'Commands') + ' <span class="p-cell-muted" style="font-weight:400;font-size:12px">' + L('(الاسم بالإنجليزي من غير /)', '(name in English, without /)') + '</span></p><div id="waCmds">' + rowsHtml(cm) + '</div>'
            + '<button type="button" class="p-btn outline xs" id="waCmdAdd">+ ' + L('أمر جديد', 'Add command') + '</button>'
            + '<div style="margin-top:12px"><button class="p-btn primary sm" id="waAutoSave">' + L('احفظ', 'Save') + '</button></div>';
    }

    async function loadSecurity(box) {
        box.innerHTML = '<div class="p-cell-muted">…</div>';
        var r = await fetchJSON('/api/whatsapp-cloud/blocked?website_id=' + encodeURIComponent(site));
        var list = r.success ? (r.data.users || []) : [];
        box.innerHTML = '<p style="margin:0 0 6px;font-weight:600">🔐 ' + L('رمز التحقق بخطوتين', 'Two-step verification PIN') + '</p>'
            + '<p class="p-cell-muted" style="margin:0 0 8px;font-size:12.5px">' + L('6 أرقام بتحمي رقمك من إن حد ينقله لحساب تاني. هتحتاجه لو نقلت الرقم أو سجّلته من جديد، فاحتفظ بيه.', 'Six digits that stop anyone from moving your number to another account. You need it to re-register or move the number, so keep it safe.') + '</p>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap"><input id="waPin" class="form-control" dir="ltr" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" placeholder="••••••" style="max-width:140px"><input id="waPin2" class="form-control" dir="ltr" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" placeholder="' + esc(L('أعد الكتابة', 'Repeat')) + '" style="max-width:140px"><button class="p-btn outline sm" id="waPinSave">' + L('غيّر الرمز', 'Change PIN') + '</button></div>'
            + '<p style="margin:16px 0 6px;font-weight:600">🚫 ' + L('العملاء المحظورين', 'Blocked customers') + '</p>'
            + '<p class="p-cell-muted" style="margin:0 0 8px;font-size:12.5px">' + L('المحظور مش هيقدر يبعتلك ولا انت تبعتله. Meta بتسمح تحظر بس اللي كلمك آخر 24 ساعة. تقدر كمان تحظر من جوه الشات.', 'Blocked customers can’t message you and you can’t message them. Meta only lets you block someone who wrote in the last 24 hours. You can also block from the chat.') + '</p>'
            + (!r.success ? '<div class="alert alert-warning">' + esc(r.error || '') + '</div>' : list.length ? '<div>' + list.map(function (u) { return '<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--panel-border,#2a3247)"><span dir="ltr">+' + esc(u) + '</span><button class="p-btn outline xs" data-unblock="' + esc(u) + '">' + L('فك الحظر', 'Unblock') + '</button></div>'; }).join('') + '</div>' : '<p class="p-cell-muted" style="font-size:13px">' + L('مفيش حد محظور.', 'Nobody is blocked.') + '</p>')
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><input id="waBlockPhone" class="form-control" dir="ltr" placeholder="2010xxxxxxxx" style="max-width:200px"><button class="p-btn outline sm" id="waBlockBtn">' + L('احظر الرقم', 'Block number') + '</button></div>';
    }
    var PANELS = { waHealth: loadHealth, waCatalog: loadCatalog, waAuto: loadAuto, waSecurity: loadSecurity };


    // ------------------------------------------------------------ ضبط صورة البروفايل (قص مربع 640×640)
    var crop = null;
    function openCropper(file) {
        var box = document.getElementById('wpCrop');
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toast(L('اختار صورة JPG أو PNG', 'Choose a JPG or PNG image'), 'error'); return; }
        if (file.size > 15 * 1024 * 1024) { toast(L('الصورة كبيرة قوي (15 ميجا بالكتير)', 'The photo is too large (max 15 MB)'), 'error'); return; }
        // Read as a data: URL - the panel's Content-Security-Policy allows data: images but not blob: URLs.
        var reader = new FileReader(), img = new Image(), url = '';
        img.onload = function () {
            if (img.naturalWidth < 192 || img.naturalHeight < 192) { toast(L('الصورة صغيرة قوي - لازم 192×192 على الأقل', 'The photo is too small - at least 192×192'), 'error'); return; }
            var V = 240, base = V / Math.min(img.naturalWidth, img.naturalHeight);
            crop = { img: img, url: url, V: V, base: base, zoom: 1, x: 0, y: 0 };
            box.style.display = 'block';
            box.innerHTML = '<p style="margin:0 0 8px;font-weight:600">' + L('ظبط الصورة', 'Adjust the photo') + ' <span class="p-cell-muted" style="font-weight:400;font-size:12px">' + L('اسحب الصورة لتحريكها، واستخدم الشريط للتكبير', 'Drag to move, use the slider to zoom') + '</span></p>'
                + '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><canvas id="wpCanvas" width="' + V + '" height="' + V + '" style="width:' + V + 'px;height:' + V + 'px;max-width:100%;border-radius:50%;cursor:grab;touch-action:none;background:#000"></canvas>'
                + '<div style="flex:1;min-width:160px"><label class="p-cell-muted" style="font-size:12px">' + L('تكبير', 'Zoom') + '</label><input type="range" id="wpZoom" min="1" max="4" step="0.01" value="1" style="width:100%">'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"><button type="button" class="p-btn primary sm" id="waPhotoSave">' + L('احفظ الصورة على واتساب', 'Save photo to WhatsApp') + '</button><button type="button" class="p-btn outline sm" id="wpCropCancel">' + L('إلغاء', 'Cancel') + '</button></div></div></div>';
            var cv = document.getElementById('wpCanvas'), drag = null;
            cv.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, ox: crop.x, oy: crop.y }; cv.setPointerCapture(e.pointerId); cv.style.cursor = 'grabbing'; });
            cv.addEventListener('pointermove', function (e) { if (!drag) return; var k = cv.width / cv.getBoundingClientRect().width; crop.x = drag.ox + (e.clientX - drag.x) * k; crop.y = drag.oy + (e.clientY - drag.y) * k; drawCrop(); });
            var end = function () { drag = null; cv.style.cursor = 'grab'; };
            cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
            cv.addEventListener('wheel', function (e) { e.preventDefault(); var z = document.getElementById('wpZoom'); z.value = Math.min(4, Math.max(1, crop.zoom - e.deltaY * 0.002)); z.dispatchEvent(new Event('input')); }, { passive: false });
            document.getElementById('wpZoom').addEventListener('input', function () { crop.zoom = parseFloat(this.value); drawCrop(); });
            drawCrop();
        };
        img.onerror = function () { toast(L('تعذر قراءة الصورة - جرّب صورة JPG أو PNG تانية', 'Could not read the image - try another JPG or PNG'), 'error'); };
        reader.onload = function () { url = String(reader.result || ''); img.src = url; };
        reader.onerror = img.onerror;
        reader.readAsDataURL(file);
    }
    // Keep the image covering the whole square so the saved photo never has empty edges.
    function clampCrop(size) {
        var s = crop.base * crop.zoom * size / crop.V, w = crop.img.naturalWidth * s, h = crop.img.naturalHeight * s;
        var maxX = (w - size) / 2 * crop.V / size, maxY = (h - size) / 2 * crop.V / size;
        crop.x = Math.max(-maxX, Math.min(maxX, crop.x)); crop.y = Math.max(-maxY, Math.min(maxY, crop.y));
    }
    function paintCrop(ctx, size) {
        clampCrop(size);
        var s = crop.base * crop.zoom * size / crop.V, w = crop.img.naturalWidth * s, h = crop.img.naturalHeight * s;
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(crop.img, (size - w) / 2 + crop.x * size / crop.V, (size - h) / 2 + crop.y * size / crop.V, w, h);
    }
    function drawCrop() { var cv = document.getElementById('wpCanvas'); if (cv && crop) paintCrop(cv.getContext('2d'), cv.width); }
    function closeCropper() { crop = null; var b = document.getElementById('wpCrop'); if (b) { b.style.display = 'none'; b.innerHTML = ''; } }
    function cropBlob() {
        return new Promise(function (resolve) {
            var out = document.createElement('canvas'); out.width = out.height = 640;
            paintCrop(out.getContext('2d'), 640);
            crop.preview = out.toDataURL('image/jpeg', 0.8);
            out.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.9);
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'wpPhoto' && e.target.files && e.target.files[0]) { openCropper(e.target.files[0]); e.target.value = ''; }
    });

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
        if (t.dataset.panel) {
            var pb = document.getElementById(t.dataset.panel);
            if (pb.style.display === 'none') { pb.style.display = 'block'; PANELS[t.dataset.panel](pb); } else pb.style.display = 'none';
            return;
        }
        if (t.dataset.reload) { PANELS[t.dataset.reload](document.getElementById(t.dataset.reload)); return; }
        if (t.dataset.unblock) {
            if (!confirm(L('فك الحظر عن الرقم ده؟', 'Unblock this number?'))) return;
            busy(t, true);
            var ub = await post('/api/whatsapp-cloud/block', { website_id: site, phone: t.dataset.unblock, block: false });
            busy(t, false);
            toast(ub.message || ub.error, ub.success ? 'success' : 'error');
            if (ub.success) loadSecurity(document.getElementById('waSecurity'));
            return;
        }
        if (t.classList.contains('wa-cmd-del')) { t.closest('.wa-cmd').remove(); return; }
        if (t.id === 'waCmdAdd') {
            var wrap = document.getElementById('waCmds');
            if (wrap.querySelectorAll('.wa-cmd').length >= 30) { toast(L('30 أمر بالكتير', 'Up to 30 commands'), 'error'); return; }
            wrap.insertAdjacentHTML('beforeend', '<div class="wa-cmd" style="display:grid;grid-template-columns:130px 1fr 30px;gap:6px;margin-bottom:6px"><input class="form-control wa-cmd-n" dir="ltr" maxlength="32" placeholder="prices"><input class="form-control wa-cmd-d" dir="auto" maxlength="256"><button type="button" class="p-btn outline xs wa-cmd-del">✕</button></div>');
            return;
        }
        if (t.id === 'waAutoSave') {
            var prompts = Array.prototype.map.call(document.querySelectorAll('#waAuto .wa-prompt'), function (i) { return i.value.trim(); }).filter(Boolean);
            var commands = Array.prototype.map.call(document.querySelectorAll('#waAuto .wa-cmd'), function (row) { return { command_name: row.querySelector('.wa-cmd-n').value.trim().replace(/^\//, ''), command_description: row.querySelector('.wa-cmd-d').value.trim() }; }).filter(function (c) { return c.command_name || c.command_description; });
            busy(t, true, L('بنحفظ…', 'Saving…'));
            var sa = await post('/api/whatsapp-cloud/automation', { website_id: site, prompts: prompts, commands: commands });
            busy(t, false);
            toast(sa.message || sa.error, sa.success ? 'success' : 'error');
            return;
        }
        if (t.id === 'waCatSave') {
            busy(t, true, L('بنحفظ…', 'Saving…'));
            var cs = await post('/api/whatsapp-cloud/catalog', { website_id: site, catalog_visible: document.getElementById('waCatVisible').checked, cart_enabled: document.getElementById('waCatCart').checked });
            busy(t, false);
            toast(cs.message || cs.error, cs.success ? 'success' : 'error');
            return;
        }
        if (t.id === 'waPinSave') {
            var p1 = document.getElementById('waPin').value, p2 = document.getElementById('waPin2').value;
            if (!/^[0-9]{6}$/.test(p1)) { toast(L('الرمز لازم يبقى 6 أرقام', 'The PIN must be 6 digits'), 'error'); return; }
            if (p1 !== p2) { toast(L('الرمزين مش زي بعض', 'The two PINs don’t match'), 'error'); return; }
            busy(t, true);
            var pn = await post('/api/whatsapp-cloud/pin', { website_id: site, pin: p1 });
            busy(t, false);
            toast(pn.message || pn.error, pn.success ? 'success' : 'error');
            if (pn.success) { document.getElementById('waPin').value = ''; document.getElementById('waPin2').value = ''; }
            return;
        }
        if (t.id === 'waBlockBtn') {
            var ph = document.getElementById('waBlockPhone').value.trim();
            if (!ph) return;
            if (!confirm(L('حظر الرقم ده؟ مش هيقدر يبعتلك تاني.', 'Block this number? They won’t be able to message you.'))) return;
            busy(t, true);
            var bl = await post('/api/whatsapp-cloud/block', { website_id: site, phone: ph, block: true });
            busy(t, false);
            toast(bl.message || bl.error, bl.success ? 'success' : 'error');
            if (bl.success) loadSecurity(document.getElementById('waSecurity'));
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
        } else if (t.id === 'wpPick') {
            document.getElementById('wpPhoto').click();
        } else if (t.id === 'wpCropCancel') {
            closeCropper();
        } else if (t.id === 'waPhotoSave') {
            if (!crop) { toast(L('اختار صورة الأول', 'Choose a photo first'), 'error'); return; }
            var blob = await cropBlob();
            if (!blob) { toast(L('تعذر تجهيز الصورة', 'Could not prepare the photo'), 'error'); return; }
            var fd = new FormData(); fd.append('website_id', site); fd.append('photo', blob, 'profile.jpg');
            busy(t, true, L('بنرفع…', 'Uploading…'));
            var ph = await fetchJSON('/api/whatsapp-cloud/profile/photo', { method: 'POST', body: fd });
            busy(t, false);
            toast(ph.message || ph.error, ph.success ? 'success' : 'error');
            if (ph.success) {
                // Meta can take a little while to serve the new photo; show it right away.
                var av = document.getElementById('wpAvatar');
                if (av && crop && crop.preview) { av.src = crop.preview; av.style.visibility = 'visible'; }
                closeCropper();
            }
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

    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'waCatSel') loadCatalog(document.getElementById('waCatalog'), e.target.value);
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
