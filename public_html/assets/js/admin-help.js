/**
 * Tourfecto - Admin Help
 * زرار «؟» في أعلى كل صفحة أدمن: لما تدوس عليه بيفتح لوحة جانبية بتشرح
 * الصفحة دي بتعمل إيه وهي متصلة بإيه (صفحات تانية / كرونات / خدمات).
 * بيشتغل لوحده على أي مسار /admin/* من غير ما يغيّر أي رابط أو راوت.
 */
(function () {
    'use strict';
    var path = (location.pathname || '').replace(/\/+$/, '') || '/';
    if (path !== '/admin' && path.indexOf('/admin/') !== 0) return;

    // كل صفحة: [الاسم، بتعمل إيه، متصلة بإيه (قايمة)، صفحات مرتبطة (مسار => اسم)]
    var H = {
        '/admin': ['نظرة عامة', 'الشاشة الرئيسية للأدمن: أرقام المنصة النهارده (عملاء، اشتراكات، مواقع، زوار) ورسم الزوار وآخر المستخدمين والاشتراكات.', ['جداول المستخدمين والاشتراكات والزوار', 'نفس بيانات صفحات «المستخدمين» و«الاشتراكات» و«الزوار»'], { '/admin/users': 'المستخدمين', '/admin/subscriptions': 'الاشتراكات', '/admin/visitors': 'الزوار' }],
        '/admin/platform': ['نظرة المنصة', 'صورة شاملة لحالة المنصة وأقسامها في مكان واحد قبل ما تدخل أي قسم بالتفصيل.', ['الصحة والتنبيهات والنمو'], { '/admin/health': 'صحة المنصة', '/admin/alerts': 'التنبيهات' }],
        '/admin/metrics': ['أرقام SaaS', 'مؤشرات الشغل: MRR و ARR ونسبة الإلغاء وقيمة العميل وتحويل التجربة. بتقولك المنصة بتكبر ولا لأ.', ['الاشتراكات والدفعات والتجارب'], { '/admin/subscriptions': 'الاشتراكات', '/admin/finance': 'الماليات', '/admin/trials': 'التجارب' }],
        '/admin/ai-ceo': ['AI Mission Control', 'مدير تنفيذي بالذكاء الاصطناعي: بيجمع أرقام المنصة ويقترح مهام وقرارات ويتابع نتايجها.', ['العقل الاستراتيجي', 'بيانات النمو والتسويق والاشتراكات'], { '/admin/brain': 'العقل الاستراتيجي', '/admin/metrics': 'أرقام SaaS' }],
        '/admin/brain': ['العقل الاستراتيجي', 'صورة الشركة كلها وقراراتها المرتبة ونتيجة كل قرار، عشان تعرف إيه اللي نفع وإيه لأ.', ['AI Mission Control', 'بيانات العملاء والنمو'], { '/admin/ai-ceo': 'AI Mission Control' }],
        '/admin/ai-usage': ['استخدام الذكاء الاصطناعي', 'كل استدعاءات الـAI: مين استخدم إيه، كام توكن، وتكلفتها. بتساعدك تتحكم في المصاريف.', ['خدمات الـAI في المنصة (مقالات، شات، سوشيال...)', 'توزيع خدمات AI'], { '/admin/ai-routing': 'توزيع خدمات AI', '/admin/finance': 'الماليات' }],
        '/admin/ai-routing': ['توزيع خدمات AI', 'بتختار أي مزوّد/موديل يشغّل كل خدمة (مقالات، صور، ردود...) والبدائل لو واحد وقع.', ['كل ميزات الـAI عند العملاء', 'صفحة استخدام الذكاء الاصطناعي'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/creative-studio': ['استوديو الوسائط', 'مراقبة وإدارة توليد الصور والفيديو للعملاء: الطلبات والتكلفة والأخطاء.', ['استوديو الإبداع عند العملاء', 'استخدام الذكاء الاصطناعي'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/blog': ['المدونة', 'كتابة وتعديل مقالات مدونة الموقع العام (tourfecto.pro/blog) بالعربي والإنجليزي.', ['صفحات المدونة العامة', 'السيو'], { '/admin/seo-markets': 'الكلمات والأسواق' }],
        '/admin/hosted-blogs': ['المدونات المستضافة', 'مدونات العملاء اللي المنصة بتستضيفها: مراجعتها وإيقافها وتفعيلها.', ['مواقع العملاء', 'مدونة كل عميل'], { '/admin/users': 'المستخدمين' }],
        '/admin/seo-markets': ['الكلمات والأسواق (SEO)', 'إدارة أسواق الكلمات المفتاحية اللي بتتتبع لكل بلد/لغة وبيانات الترتيب.', ['DataForSEO', 'مركز السيو عند العملاء'], { '/admin/seo-data-costs': 'تكلفة DataForSEO' }],
        '/admin/seo-data-costs': ['تكلفة DataForSEO', 'كل عميل صرف كام الشهر ده على بيانات السيو، ومتوقع يقفل على كام. الميزانية اليومية بتتعدل من الإعدادات.', ['DataForSEO (تكلفة حقيقية)', 'الإعدادات'], { '/admin/settings': 'الإعدادات', '/admin/seo-markets': 'الكلمات والأسواق' }],
        '/admin/users': ['المستخدمين', 'كل العملاء: بحث وفلترة وتفاصيل كل حساب وتصدير CSV، وإجراءات زي الدخول كعميل أو الإيقاف.', ['الاشتراكات', 'تاريخ الدخول', 'صحة العملاء'], { '/admin/subscriptions': 'الاشتراكات', '/admin/login-history': 'سجل الدخول', '/admin/customers-health': 'صحة العملاء' }],
        '/admin/customers-health': ['صحة العملاء', 'مين على وشك يمشي ومين بيخسّرك فلوس وليه، بناءً على الاستخدام والدفع.', ['الاستخدام', 'الاشتراكات', 'تكلفة الـAI'], { '/admin/users': 'المستخدمين', '/admin/adoption': 'استخدام المميزات' }],
        '/admin/trials': ['متابعة التجارب', 'مين تجربته قربت تخلص أو خلصت ولسه ماشتركش، عشان تكلمه في الوقت الصح.', ['الاشتراكات', 'الإيميلات التلقائية'], { '/admin/subscriptions': 'الاشتراكات', '/admin/emails': 'سجل البريد' }],
        '/admin/adoption': ['استخدام المميزات', 'كل عميل وصل لحد فين في طريق أول حجز وبيستخدم أنهي مميزات.', ['Onboarding', 'صحة العملاء'], { '/admin/onboarding-funnel': 'قمع الإعداد', '/admin/customers-health': 'صحة العملاء' }],
        '/admin/onboarding-funnel': ['قمع الإعداد', 'خطوات التسجيل والإعداد: كام واحد بدأ وكام كمّل وفين بيقع.', ['التسجيل', 'الزوار'], { '/admin/visitors': 'الزوار', '/admin/adoption': 'استخدام المميزات' }],
        '/admin/growth': ['تسويق المنصة (إيميل)', 'حملات الإيميل البارد لشركات سياحة محتملة: القوائم، الإرسال، الردود، والـbounce. ده تسويق لمنصتك مش للعملاء.', ['صندوق البريد المرسل (SMTP/IMAP)', 'مكتب التواصل', 'سمعة البريد'], { '/admin/outreach-desk': 'مكتب التواصل', '/admin/outreach-sent': 'المرسول لهم', '/admin/email-reputation': 'سمعة البريد' }],
        '/admin/outreach-desk': ['مكتب التواصل (واتساب)', 'قوائم الشركات لفريق المبيعات: كل موظف بياخد شركة وينسخ الرسالة ويبعتها يدوي على واتساب.', ['نفس قاعدة شركات التسويق', 'صفحة المرسول لهم'], { '/admin/growth': 'تسويق المنصة', '/admin/outreach-sent': 'المرسول لهم' }],
        '/admin/outreach-sent': ['المرسول لهم (واتساب)', 'مين اتبعتله من فريق المبيعات وإمتى، يوم بيوم، ومين اللي استلم كل شركة.', ['مكتب التواصل', 'تسويق المنصة'], { '/admin/outreach-desk': 'مكتب التواصل', '/admin/growth': 'تسويق المنصة' }],
        '/admin/voice': ['مساعد الصوت (تحكم)', 'التحكم في مزوّدي المكالمات والأرقام والتكلفة لمساعد الصوت عند العملاء.', ['مزوّدو الاتصال', 'محفظة العملاء'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/email-reputation': ['سمعة التسويق بالبريد', 'صحة إرسال الإيميل: الـbounce والشكاوى وحدود الإرسال عشان الإيميلات ماتروحش سبام.', ['الإيميلات التسويقية للعملاء وللمنصة', 'حدود السمعة'], { '/admin/reputation-limits': 'حدود السمعة', '/admin/emails': 'سجل البريد' }],
        '/admin/reputation-limits': ['حدود السمعة والمراجعات', 'الحدود اللي بتمنع إساءة استخدام طلبات التقييم والمراجعات.', ['طلبات التقييم عند العملاء', 'سمعة البريد'], { '/admin/email-reputation': 'سمعة البريد' }],
        '/admin/whatsapp-marketing': ['تسويق واتساب', 'متابعة حملات واتساب اللي العملاء بيبعتوها: الأعداد والجودة والالتزام.', ['واتساب Cloud API', 'مراجعة الاستخدام المعلق'], { '/admin/usage-reservations': 'الاستخدام المعلق', '/admin/meta-review': 'مراجعة Meta' }],
        '/admin/support': ['تذاكر الدعم', 'المشاكل والأسئلة والشكاوى اللي العملاء فتحوها من /support وفريق الدعم بيرد عليها.', ['صفحة الدعم عند العميل', 'البريد'], { '/admin/contact-messages': 'رسائل التواصل', '/admin/users': 'المستخدمين' }],
        '/admin/contact-messages': ['رسائل التواصل', 'الرسائل اللي جت من نموذج «اتصل بنا» في الموقع العام.', ['الموقع العام', 'تذاكر الدعم'], { '/admin/support': 'تذاكر الدعم' }],
        '/admin/subscriptions': ['الاشتراكات', 'كل الاشتراكات: الباقة والحالة وتاريخ التجديد، وتصدير CSV.', ['الباقات', 'بوابة الدفع', 'المستخدمين'], { '/admin/plans': 'الباقات', '/admin/billing-ops': 'الدفع والكوبونات', '/admin/users': 'المستخدمين' }],
        '/admin/billing-ops': ['الدفع والكوبونات', 'الاشتراكات المتأخرة والدفعات اللي فشلت وأكواد الخصم.', ['بوابة الدفع', 'الاشتراكات', 'الاستخدام المعلق'], { '/admin/subscriptions': 'الاشتراكات', '/admin/usage-reservations': 'الاستخدام المعلق' }],
        '/admin/plans': ['الباقات', 'تعريف الباقات وحدودها ومميزاتها كما تظهر للعملاء.', ['صفحة الأسعار', 'الاشتراكات', 'استوديو الباقات'], { '/admin/plans-studio': 'استوديو الباقات', '/admin/subscriptions': 'الاشتراكات' }],
        '/admin/plans-studio': ['الباقات والعملات والدفع (XPay)', 'استوديو الأسعار: عملات وباقات وربط بوابة الدفع XPay.', ['XPay', 'صفحة الأسعار العامة', 'الباقات'], { '/admin/plans': 'الباقات', '/admin/billing-ops': 'الدفع والكوبونات' }],
        '/admin/finance': ['الماليات', 'الإيرادات والتكاليف والفواتير وتقارير الفلوس.', ['الاشتراكات', 'تكلفة الـAI وDataForSEO'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي', '/admin/seo-data-costs': 'تكلفة DataForSEO' }],
        '/admin/usage-reservations': ['الاستخدام المعلق', 'استخدام (واتساب/رصيد) اتحجز لكن ماتأكدش، وبتراجعه وتحسمه.', ['محفظة العميل', 'تسويق واتساب'], { '/admin/billing-ops': 'الدفع والكوبونات', '/admin/whatsapp-marketing': 'تسويق واتساب' }],
        '/admin/health': ['صحة المنصة', 'كل حاجة ممكن توقف المنصة: الكرونات والتكاملات والبريد والطابور والمدفوعات والسيرفر.', ['Cron', 'الطابور', 'التكاملات'], { '/admin/alerts': 'التنبيهات', '/admin/errors': 'أخطاء النظام', '/admin/jobs': 'المهام' }],
        '/admin/alerts': ['التنبيهات', 'المنصة بتبلغك لما حاجة تقع أو عميل مهم يلغي.', ['صحة المنصة', 'الاشتراكات'], { '/admin/health': 'صحة المنصة', '/admin/errors': 'أخطاء النظام' }],
        '/admin/errors': ['أخطاء النظام', 'الأخطاء المسجلة متجمعة: إيه اللي بيتكرر ومن إمتى وآخر مرة.', ['ملفات اللوج', 'صحة المنصة'], { '/admin/logs': 'السجلات', '/admin/health': 'صحة المنصة' }],
        '/admin/system': ['حالة النظام', 'معلومات السيرفر والإصدارات والموارد.', ['السيرفر وقاعدة البيانات'], { '/admin/health': 'صحة المنصة', '/admin/logs': 'السجلات' }],
        '/admin/logs': ['السجلات', 'قراءة ملفات اللوج لتشخيص أي مشكلة.', ['أخطاء النظام'], { '/admin/errors': 'أخطاء النظام' }],
        '/admin/jobs': ['المهام', 'المهام الخلفية (Jobs/Cron): الشغال والمتأخر والفاشل.', ['الكرونات', 'الطابور'], { '/admin/health': 'صحة المنصة' }],
        '/admin/emails': ['سجل البريد', 'كل إيميل المنصة بعتته: وصل ولا فشل وليه، مع إعادة الإرسال.', ['SMTP', 'سمعة البريد'], { '/admin/email-reputation': 'سمعة البريد' }],
        '/admin/status-page': ['صفحة الحالة والتحديثات', 'صفحة tourfecto.pro/status للعملاء، والحوادث، وسجل «إيه الجديد».', ['الصفحة العامة /status و/changelog', 'صحة المنصة'], { '/admin/health': 'صحة المنصة' }],
        '/admin/visitors': ['الزوار', 'زوار الموقع العام: كام واحد وجايين منين وبيعملوا إيه، وكمان سكانر البوتات.', ['الموقع العام', 'قمع الإعداد'], { '/admin/onboarding-funnel': 'قمع الإعداد', '/admin/security': 'الأمان' }],
        '/admin/login-history': ['سجل الدخول', 'كل محاولات الدخول (ناجحة وفاشلة) مع الـIP والجهاز.', ['الأمان', 'المستخدمين'], { '/admin/security': 'الأمان', '/admin/users': 'المستخدمين' }],
        '/admin/security': ['الأمان', 'أحداث أمنية: محاولات مشبوهة وحظر IP وفحوصات.', ['سجل الدخول', 'أمان الأدمن'], { '/admin/login-history': 'سجل الدخول', '/admin/admin-security': 'أمان الأدمن' }],
        '/admin/admin-security': ['أمان الأدمن', 'التحقق بخطوتين للفريق وعناوين IP المسموحة وسبب إجباري للإجراءات الحساسة.', ['فريق الأدمن', 'سجل المراجعة'], { '/admin/team': 'الفريق', '/admin/audit': 'سجل المراجعة' }],
        '/admin/audit': ['سجل المراجعة', 'مين من الفريق عمل إيه وإمتى في لوحة الأدمن.', ['فريق الأدمن', 'أمان الأدمن'], { '/admin/team': 'الفريق', '/admin/admin-security': 'أمان الأدمن' }],
        '/admin/team': ['الفريق', 'أعضاء الأدمن وأدوارهم وصلاحياتهم؛ والقايمة الجانبية بتتخفى حسب الصلاحية.', ['سجل المراجعة', 'أمان الأدمن'], { '/admin/audit': 'سجل المراجعة', '/admin/admin-security': 'أمان الأدمن' }],
        '/admin/settings': ['الإعدادات', 'إعدادات المنصة العامة (حدود وميزانيات وخيارات تشغيل).', ['كل أجزاء المنصة', 'تكلفة DataForSEO'], { '/admin/integrations': 'التكاملات', '/admin/seo-data-costs': 'تكلفة DataForSEO' }],
        '/admin/integrations': ['التكاملات', 'حالة الربط مع الخدمات الخارجية (Meta, Google, الدفع, الذكاء الاصطناعي...).', ['مفاتيح API', 'صحة المنصة'], { '/admin/api-keys': 'مفاتيح API', '/admin/health': 'صحة المنصة' }],
        '/admin/api-keys': ['مفاتيح API', 'إدارة مفاتيح المنصة للخدمات الخارجية.', ['التكاملات'], { '/admin/integrations': 'التكاملات' }],
        '/admin/meta-review': ['جاهزية مراجعة Meta', 'اللي جاهز واللي ناقص لمراجعة تطبيق Meta/واتساب، مع القيم ونصوص المراجعة.', ['تطبيق Meta', 'ربط واتساب وفيسبوك وإنستجرام'], { '/admin/whatsapp-marketing': 'تسويق واتساب', '/admin/integrations': 'التكاملات' }]
    };

    var key = path;
    if (!H[key]) {
        // صفحات فيها معرّف (زي /admin/users/12) ترجع لصفحتها الأم
        var m = path.match(/^(\/admin\/[a-z0-9-]+)\//);
        if (m && H[m[1]]) key = m[1];
    }
    var d = H[key];
    if (!d) d = ['صفحة أدمن', 'صفحة من صفحات لوحة الأدمن. استخدم القايمة الجانبية للتنقل بين الأقسام.', [], {}];

    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    var css = '.ah-btn{width:38px;height:38px;border-radius:10px;background:var(--panel-card-bg,#0F1A2C);border:1px solid var(--panel-border,rgba(255,255,255,.09));color:var(--panel-text-muted,#8996AC);font-size:17px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center}'
        + '.ah-btn:hover{color:var(--panel-accent,#EFB05E);border-color:var(--panel-accent,#EFB05E)}'
        + '.ah-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9998;display:none}'
        + '.ah-panel{position:fixed;top:0;bottom:0;inset-inline-end:0;width:min(400px,92vw);background:var(--panel-card-bg,#0F1A2C);color:var(--panel-text,#F2F4F8);border-inline-start:1px solid var(--panel-border,rgba(255,255,255,.09));z-index:9999;padding:22px 20px;overflow:auto;display:none;box-shadow:-8px 0 30px rgba(0,0,0,.35);line-height:1.8;font-size:14px}'
        + '.ah-panel h3{margin:0 0 4px;font-size:18px}.ah-panel h4{margin:18px 0 6px;font-size:13px;color:var(--panel-accent,#EFB05E)}'
        + '.ah-panel ul{margin:0;padding-inline-start:18px}.ah-panel a{color:var(--panel-accent,#EFB05E);text-decoration:none}.ah-panel a:hover{text-decoration:underline}'
        + '.ah-x{position:absolute;top:10px;inset-inline-start:12px;background:none;border:0;color:inherit;font-size:24px;cursor:pointer}.ah-open .ah-bg,.ah-open .ah-panel{display:block}';

    function build() {
        var bar = document.querySelector('.panel-topbar-actions');
        if (!bar || document.getElementById('ahBtn')) return;
        var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

        var btn = document.createElement('button');
        btn.type = 'button'; btn.id = 'ahBtn'; btn.className = 'ah-btn'; btn.textContent = '؟';
        btn.title = 'الصفحة دي بتعمل إيه؟'; btn.setAttribute('aria-label', 'شرح الصفحة');
        bar.insertBefore(btn, bar.firstChild);

        var links = Object.keys(d[3] || {}).map(function (h) { return '<li><a href="' + esc(h) + '">' + esc(d[3][h]) + '</a></li>'; }).join('');
        var conns = (d[2] || []).map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('');
        var wrap = document.createElement('div');
        wrap.innerHTML = '<div class="ah-bg"></div><aside class="ah-panel" role="dialog" aria-label="شرح الصفحة"><button class="ah-x" type="button" aria-label="إغلاق">×</button>'
            + '<h3>' + esc(d[0]) + '</h3><h4>الصفحة دي بتعمل إيه؟</h4><div>' + esc(d[1]) + '</div>'
            + (conns ? '<h4>متصلة بإيه؟</h4><ul>' + conns + '</ul>' : '')
            + (links ? '<h4>صفحات مرتبطة</h4><ul>' + links + '</ul>' : '') + '</aside>';
        document.body.appendChild(wrap);
        function set(o) { document.body.classList.toggle('ah-open', o); }
        btn.addEventListener('click', function () { set(true); });
        wrap.querySelector('.ah-bg').addEventListener('click', function () { set(false); });
        wrap.querySelector('.ah-x').addEventListener('click', function () { set(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
})();
