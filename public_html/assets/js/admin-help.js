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
        '/admin': ['نظرة عامة', 'الشاشة الرئيسية: أرقام المنصة مع مقارنة بالفترة السابقة (7/30/90 يوم)، وحاجات محتاجة تدخل دلوقتي، وسجل نشاط موحّد من كل المصادر.', ['الاشتراكات والباقات والمستخدمين والمواقع وحركة المحفظة', 'زوار الموقع العام (visitor_logs) وسجل الدخول', 'المهام الخلفية (jobs) وفحص صحة النظام'], { '/admin/users': 'المستخدمين', '/admin/subscriptions': 'الاشتراكات', '/admin/visitors': 'الزوار', '/admin/health': 'صحة المنصة' }],
        '/admin/platform': ['نظرة المنصة', 'صورة سريعة لحالة المنصة وأقسامها قبل ما تدخل أي قسم بالتفصيل.', ['نفس بيانات المستخدمين والمواقع والاشتراكات'], { '/admin/health': 'صحة المنصة', '/admin/alerts': 'التنبيهات' }],
        '/admin/metrics': ['أرقام SaaS', 'مؤشرات الشغل: MRR و ARR ونسبة الإلغاء وقيمة العميل وتحويل التجارب.', ['الاشتراكات والباقات', 'حركة المحفظة (wallet_transactions)', 'المستخدمين'], { '/admin/subscriptions': 'الاشتراكات', '/admin/finance': 'الماليات', '/admin/trials': 'التجارب' }],
        '/admin/ai-ceo': ['AI Mission Control', 'مدير تنفيذي بالذكاء الاصطناعي: بيقرا لقطة من المنصة (أرقام وصحة العملاء) ويقترح مهام وقرارات ويتابع نتايجها.', ['صحة العملاء (بيستخدم عدّاد at_risk الكامل)', 'العقل الاستراتيجي', 'أرقام SaaS'], { '/admin/brain': 'العقل الاستراتيجي', '/admin/customers-health': 'صحة العملاء', '/admin/metrics': 'أرقام SaaS' }],
        '/admin/brain': ['العقل الاستراتيجي', 'صورة الشركة كلها وقراراتها المرتبة ونتيجة كل قرار، عشان تعرف إيه اللي نفع وإيه لأ.', ['بيانات العملاء والنمو', 'AI Mission Control'], { '/admin/ai-ceo': 'AI Mission Control' }],
        '/admin/ai-usage': ['استخدام الذكاء الاصطناعي', 'كل استدعاءات الـAI: مين استخدم إيه وكام استدعاء وتكلفتها، لتتحكم في المصاريف.', ['سجل api_usage_logs', 'توزيع خدمات AI (أي موديل شغّال لكل خدمة)'], { '/admin/ai-routing': 'توزيع خدمات AI', '/admin/finance': 'الماليات', '/admin/customers-health': 'صحة العملاء' }],
        '/admin/ai-routing': ['توزيع خدمات AI', 'بتختار أي مزوّد/موديل يشغّل كل خدمة (مقالات، صور، ردود...) والبدائل لو واحد وقع.', ['كل ميزات الـAI عند العملاء', 'استخدام الذكاء الاصطناعي'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/creative-studio': ['استوديو الوسائط', 'مراقبة توليد الصور والفيديو للعملاء: الطلبات والتكلفة والأخطاء.', ['استوديو الإبداع عند العملاء', 'استخدام الذكاء الاصطناعي'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/blog': ['المدونة', 'كتابة وتعديل مقالات مدونة الموقع العام (tourfecto.pro/blog) بالعربي والإنجليزي.', ['جدول blog_posts', 'صفحات المدونة العامة'], { '/admin/seo-markets': 'الكلمات والأسواق' }],
        '/admin/hosted-blogs': ['المدونات المستضافة', 'مدونات العملاء اللي المنصة بتستضيفها: مراجعتها وإيقافها وتفعيلها.', ['مواقع العملاء', 'مدونة كل عميل'], { '/admin/users': 'المستخدمين' }],
        '/admin/seo-markets': ['الكلمات والأسواق (SEO)', 'إدارة أسواق الكلمات المفتاحية اللي بتتتبع لكل بلد/لغة.', ['DataForSEO', 'مركز السيو عند العملاء'], { '/admin/seo-data-costs': 'تكلفة DataForSEO' }],
        '/admin/seo-data-costs': ['تكلفة DataForSEO', 'كل عميل صرف كام الشهر ده على بيانات السيو، ومتوقع يقفل على كام (من التكلفة الحقيقية اللي DataForSEO رجّعها).', ['DataForSEO', 'ميزانية يومية وحد كلمات لكل موقع من الإعدادات'], { '/admin/settings': 'الإعدادات', '/admin/seo-markets': 'الكلمات والأسواق' }],
        '/admin/users': ['المستخدمين', 'كل الحسابات: بحث وفلترة وتفاصيل كل حساب وتصدير CSV، وإجراءات زي الإيقاف والتفعيل وتغيير دور الفوترة.', ['الاشتراكات والمحفظة', 'سجل الدخول', 'وسوم وملاحظات الأدمن'], { '/admin/subscriptions': 'الاشتراكات', '/admin/login-history': 'سجل الدخول', '/admin/customers-health': 'صحة العملاء' }],
        '/admin/customers-health': ['صحة العملاء', 'درجة لكل عميل (كويس/تحت المتابعة/في خطر) وليه، والربحية بعد تكلفة الـAI. تبويب «في خطر» بيعرض اللي بيدفعوا أو متأخرين بس.', ['الاشتراكات والباقات والمواقع', 'حركة المحفظة واستخدام الـAI (api_usage_logs)', 'ربط المنصات (platform_connections)', 'AI Mission Control بيقرا نفس الدرجات'], { '/admin/users': 'المستخدمين', '/admin/adoption': 'استخدام المميزات', '/admin/ai-ceo': 'AI Mission Control' }],
        '/admin/trials': ['متابعة التجارب', 'مين تجربته شغّالة وقربت تخلص، ومين خلصت ومااشتركش، ومين سجّل ومابدأش، وإيه اللي خلّصه من خطة التجربة. فيها رسالة واتساب جاهزة ومدّ التجربة.', ['الاشتراكات (حالة trialing)', 'المستخدمين والمواقع', 'تفعيل باقة بيودّيك لصفحة الاشتراكات'], { '/admin/subscriptions': 'الاشتراكات', '/admin/adoption': 'استخدام المميزات', '/admin/plans-studio': 'استوديو الباقات' }],
        '/admin/adoption': ['استخدام المميزات', 'كل عميل وصل لحد فين في طريق أول حجز وبيستخدم أنهي مميزات (حجوزات، روابط دفع، طلبات تقييم، ملخص الصبح).', ['الحجوزات وروابط الدفع', 'إعدادات طلبات التقييم والملخص الصباحي', 'مواقع العملاء'], { '/admin/onboarding-funnel': 'قمع الإعداد', '/admin/customers-health': 'صحة العملاء' }],
        '/admin/onboarding-funnel': ['قمع الإعداد', 'خطوات التسجيل والإعداد: كام واحد بدأ وكام كمّل وفين بيقع.', ['المستخدمين الجدد', 'الزوار'], { '/admin/visitors': 'الزوار', '/admin/adoption': 'استخدام المميزات' }],
        '/admin/growth': ['تسويق المنصة (إيميل)', 'حملات الإيميل البارد لشركات سياحة محتملة: القوائم، الإرسال، الردود، فحص الإيميلات المرتدة (bounce) والمساعد الذكي لكل شركة. ده تسويق لمنصتك مش للعملاء.', ['جدولا platform_leads و platform_lead_messages', 'صندوق البريد المرسل (SMTP للإرسال، IMAP لقراءة الـbounce)', 'مكتب التواصل بيشتغل على نفس الشركات'], { '/admin/outreach-desk': 'مكتب التواصل', '/admin/outreach-sent': 'المرسول لهم', '/admin/email-reputation': 'سمعة البريد' }],
        '/admin/outreach-desk': ['مكتب التواصل (واتساب)', 'قوائم الشركات لفريق المبيعات: أول موظف يبعت لشركة تتحسب عليه وتختفي من قوايم الباقيين، وبينسخ الرسالة ويبعتها يدوي على واتساب.', ['نفس شركات تسويق المنصة (platform_leads)', 'صفحة المرسول لهم'], { '/admin/growth': 'تسويق المنصة', '/admin/outreach-sent': 'المرسول لهم' }],
        '/admin/outreach-sent': ['المرسول لهم (واتساب)', 'مين اتبعتله من فريق المبيعات وإمتى، يوم بيوم، ومين اللي استلم كل شركة.', ['مكتب التواصل', 'تسويق المنصة'], { '/admin/outreach-desk': 'مكتب التواصل', '/admin/growth': 'تسويق المنصة' }],
        '/admin/voice': ['مساعد الصوت (تحكم)', 'التحكم في مزوّدي المكالمات والأرقام لمساعد الصوت عند العملاء، واختبار كل مزوّد.', ['مزوّدو الاتصال', 'محفظة العملاء'], { '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/email-reputation': ['سمعة التسويق بالبريد', 'صحة إرسال الإيميل: الـbounce والشكاوى وحدود الإرسال، عشان الإيميلات ماتروحش سبام.', ['الإيميلات التسويقية', 'سجل البريد (mail_log)'], { '/admin/reputation-limits': 'حدود السمعة', '/admin/emails': 'سجل البريد', '/admin/growth': 'تسويق المنصة' }],
        '/admin/reputation-limits': ['حدود السمعة والمراجعات', 'حدود شهرية لكل باقة على طلبات التقييم والمراجعات، واستثناءات لعميل واحد. التغيير بيسري فورًا. الجدول عريض عن قصد (عمود لكل باقة).', ['الباقات (الأسعار من استوديو الباقات)', 'طلبات التقييم عند العملاء'], { '/admin/plans-studio': 'استوديو الباقات', '/admin/email-reputation': 'سمعة البريد' }],
        '/admin/whatsapp-marketing': ['تسويق واتساب', 'متابعة حملات واتساب اللي العملاء بيبعتوها ومراقبة الالتزام.', ['واتساب Cloud API', 'الاستخدام المعلق'], { '/admin/usage-reservations': 'الاستخدام المعلق', '/admin/meta-review': 'مراجعة Meta' }],
        '/admin/support': ['تذاكر الدعم', 'المشاكل والأسئلة والشكاوى اللي العملاء فتحوها من /support وفريق الدعم بيرد عليها.', ['صفحة الدعم عند العميل'], { '/admin/contact-messages': 'رسائل التواصل', '/admin/users': 'المستخدمين' }],
        '/admin/contact-messages': ['رسائل التواصل', 'الرسائل اللي جت من نموذج «اتصل بنا» في الموقع العام، وتقدر تحدّد حالتها.', ['الموقع العام'], { '/admin/support': 'تذاكر الدعم' }],
        '/admin/subscriptions': ['الاشتراكات', 'كل الاشتراكات (الحالية أو السجل الكامل) بباقتها وسعرها وحالتها وتاريخ التجديد، مع إلغاء اشتراك وتفعيل يدوي لعميل بيدفع بتحويل/واتساب.', ['الباقات والأسعار', 'المستخدمين', 'بوابات الدفع'], { '/admin/plans': 'الباقات', '/admin/billing-ops': 'الدفع والكوبونات', '/admin/trials': 'التجارب' }],
        '/admin/billing-ops': ['الدفع والكوبونات', 'الاشتراكات المتأخرة (والمنصة بتبعتلهم تذكير لوحدها)، الدفعات الفاشلة آخر 30 يوم، وأكواد الخصم وسجل استخدامها.', ['جداول coupons و coupon_redemptions', 'مدفوعات payment_transactions والاشتراكات', 'الاستخدام المعلق'], { '/admin/subscriptions': 'الاشتراكات', '/admin/usage-reservations': 'الاستخدام المعلق', '/admin/plans-studio': 'استوديو الباقات' }],
        '/admin/plans': ['الباقات', 'تعريف الباقات وحدودها وأسعارها (بتتحدّث فورًا في صفحة الأسعار العامة)، واقتصاديات كل باقة وتسعيرها مقابل المنافسين وسجل التعديلات، وكروت المحفظة المدفوعة مسبقًا.', ['صفحة الأسعار العامة', 'الاشتراكات', 'محفظة العملاء وتسعير الاستخدام'], { '/admin/plans-studio': 'استوديو الباقات', '/admin/subscriptions': 'الاشتراكات', '/admin/finance': 'الماليات' }],
        '/admin/plans-studio': ['الباقات والعملات والدفع (XPay)', 'استوديو الأسعار: عملات وباقات وربط بوابة الدفع XPay ومنح التجارب.', ['XPay', 'صفحة الأسعار العامة', 'الباقات'], { '/admin/plans': 'الباقات', '/admin/billing-ops': 'الدفع والكوبونات', '/admin/trials': 'التجارب' }],
        '/admin/finance': ['الماليات', 'المحفظة (أرصدة وإحصائيات)، والمرتجعات، وقواعد الضرايب.', ['حركة المحفظة', 'الاشتراكات والفواتير'], { '/admin/subscriptions': 'الاشتراكات', '/admin/billing-ops': 'الدفع والكوبونات', '/admin/ai-usage': 'استخدام الذكاء الاصطناعي' }],
        '/admin/usage-reservations': ['الاستخدام المعلق', 'استخدام (رصيد/واتساب) اتحجز لكن ماتأكدش، وبتراجعه وتحسمه. صلاحية الفوترة مطلوبة.', ['محفظة العميل', 'تسويق واتساب'], { '/admin/billing-ops': 'الدفع والكوبونات', '/admin/whatsapp-marketing': 'تسويق واتساب' }],
        '/admin/health': ['صحة المنصة', 'كل حاجة ممكن توقف المنصة: المهام والكرونات، التكاملات، البريد، المدفوعات، العملاء المحتملين، وتحديث هيكل قاعدة البيانات.', ['جدول jobs وتحققات التكاملات (integration_checks)', 'schema_migrations لتطبيق التحديثات'], { '/admin/alerts': 'التنبيهات', '/admin/errors': 'أخطاء النظام', '/admin/jobs': 'المهام', '/admin/integrations': 'التكاملات' }],
        '/admin/alerts': ['التنبيهات', 'المنصة بتبلغك لما حاجة تقع أو عميل مهم يلغي، وتقدر تضبط القواعد وتشغّلها يدوي.', ['جدول admin_alerts', 'الاشتراكات والإشعارات'], { '/admin/health': 'صحة المنصة', '/admin/errors': 'أخطاء النظام' }],
        '/admin/errors': ['أخطاء النظام', 'الأخطاء المسجلة متجمعة: إيه اللي بيتكرر ومن إمتى وآخر مرة.', ['ملفات اللوج على السيرفر'], { '/admin/logs': 'السجلات', '/admin/health': 'صحة المنصة' }],
        '/admin/system': ['حالة النظام', 'معلومات السيرفر وإصدارات PHP والموارد، ومسح الكاش.', ['السيرفر وقاعدة البيانات'], { '/admin/health': 'صحة المنصة', '/admin/logs': 'السجلات' }],
        '/admin/logs': ['السجلات', 'قراءة ملفات اللوج لتشخيص أي مشكلة.', ['أخطاء النظام'], { '/admin/errors': 'أخطاء النظام' }],
        '/admin/jobs': ['المهام', 'المهام الخلفية (جدول jobs): الشغال والمتأخر والفاشل، وإعادة محاولة الفاشل.', ['الكرونات اللي على السيرفر', 'صحة المنصة'], { '/admin/health': 'صحة المنصة' }],
        '/admin/emails': ['سجل البريد', 'كل إيميل المنصة بعتته (جدول mail_log): وصل ولا فشل وليه، مع إعادة الإرسال.', ['SMTP', 'سمعة البريد'], { '/admin/email-reputation': 'سمعة البريد' }],
        '/admin/status-page': ['صفحة الحالة والتحديثات', 'إدارة الحوادث (status_incidents) وسجل «إيه الجديد» (changelog_entries) اللي بيظهروا للعملاء.', ['الصفحتين العامتين /status و/changelog'], { '/admin/health': 'صحة المنصة' }],
        '/admin/visitors': ['الزوار', 'زوار الموقع العام: كام واحد وجايين منين وبيعملوا إيه، مع عزل بوتات المسح الآلي.', ['جدول visitor_logs', 'الموقع العام'], { '/admin/onboarding-funnel': 'قمع الإعداد', '/admin/security': 'الأمان' }],
        '/admin/login-history': ['سجل الدخول', 'كل محاولات الدخول (ناجحة وفاشلة) مع الـIP والجهاز.', ['جدول login_history', 'المستخدمين'], { '/admin/security': 'الأمان', '/admin/users': 'المستخدمين' }],
        '/admin/security': ['الأمان', 'محاولات مشبوهة وحظر IP (rate_limit_blocks)، مع فك الحظر وإجبار مستخدم على تسجيل الخروج.', ['سجل الدخول', 'أمان الأدمن'], { '/admin/login-history': 'سجل الدخول', '/admin/admin-security': 'أمان الأدمن' }],
        '/admin/admin-security': ['أمان الأدمن', 'التحقق بخطوتين للفريق وعناوين IP المسموحة وسبب إجباري للإجراءات الحساسة.', ['فريق الأدمن', 'سجل المراجعة'], { '/admin/team': 'الفريق', '/admin/audit': 'سجل المراجعة' }],
        '/admin/audit': ['سجل المراجعة', 'مين من الفريق عمل إيه وإمتى في لوحة الأدمن (جدول audit_logs)، مع تصدير.', ['فريق الأدمن', 'أمان الأدمن'], { '/admin/team': 'الفريق', '/admin/admin-security': 'أمان الأدمن' }],
        '/admin/team': ['الفريق', 'أعضاء الأدمن وأدوارهم وصلاحياتهم (admin_staff). القايمة الجانبية بتتخفى صفحات من غير صلاحية عليها.', ['سجل المراجعة', 'أمان الأدمن'], { '/admin/audit': 'سجل المراجعة', '/admin/admin-security': 'أمان الأدمن' }],
        '/admin/settings': ['الإعدادات', 'إعدادات المنصة: الهوية والشعار، الأسئلة الشائعة، المميزات، النصوص القانونية، البث للعملاء، وحدود تشغيل زي ميزانية DataForSEO.', ['system_settings', 'الصفحات العامة (الخصوصية والشروط والأسئلة)'], { '/admin/integrations': 'التكاملات', '/admin/seo-data-costs': 'تكلفة DataForSEO' }],
        '/admin/integrations': ['التكاملات', 'حالة الربط مع الخدمات الخارجية (Meta, Google, الدفع, الذكاء الاصطناعي...) واختبار كل ربط.', ['integration_checks', 'مفاتيح API', 'اختبار واتساب QR'], { '/admin/api-keys': 'مفاتيح API', '/admin/health': 'صحة المنصة' }],
        '/admin/api-keys': ['مفاتيح API', 'مفاتيح الشركاء (partner keys): إنشاؤها وإلغاؤها.', ['التكاملات'], { '/admin/integrations': 'التكاملات' }],
        '/admin/meta-review': ['جاهزية مراجعة Meta', 'اللي جاهز واللي ناقص لمراجعة تطبيق Meta/واتساب، مع القيم ونصوص المراجعة. (الصفحة للقراءة، ماتغيّرش ربط Meta من هنا.)', ['تطبيق Meta', 'ربط واتساب وفيسبوك وإنستجرام'], { '/admin/whatsapp-marketing': 'تسويق واتساب', '/admin/integrations': 'التكاملات' }]
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
    // ---- عرض فقط: إيميل الحساب المحذوف الداخلي (deleted+..@deleted.invalid) يتعرض «حساب محذوف» ----
    (function maskDeleted() {
        var re = /deleted\+[\w+.-]*@deleted\.invalid/gi, busy = false, timer = null;
        function run() {
            if (busy) return; busy = true;
            try {
                var w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null), n, list = [];
                while ((n = w.nextNode())) { if (n.nodeValue.indexOf('deleted.invalid') !== -1) list.push(n); }
                list.forEach(function (t) { t.nodeValue = t.nodeValue.replace(re, 'حساب محذوف'); });
            } catch (e) { /* عرض فقط - نتجاهل */ }
            busy = false;
        }
        function later() { clearTimeout(timer); timer = setTimeout(run, 150); }
        function start() { run(); new MutationObserver(later).observe(document.body, { childList: true, subtree: true, characterData: true }); }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    })();
})();
