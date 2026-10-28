<?php
/** Public, illustrative service examples. No customer records or provider calls. */
class ServicePageDetails
{
    public static function tools(): array
    {
        return [
            'ai-website-builder'=>[['منشئ المواقع','AI website builder'],['جهّز صفحات الرحلات، راجع النصوص والصور ثم انشر.','Prepare tour pages, review text and photos, then publish.']],
            'website-optimizer'=>[['تحسين الموقع','Website optimisation'],['افحص المشاكل التقنية وراجع الإصلاحات المناسبة لموقعك.','Audit technical issues and review fixes appropriate to your site.']],
            'whatsapp-chatbot'=>[['مساعد واتساب','WhatsApp assistant'],['اربط قناة واتساب وأضف قاعدة المعرفة وحدد سياسة الرد.','Connect WhatsApp, add a knowledge base and choose reply rules.']],
            'ai-assistant'=>[['المساعد الذكي','AI assistant'],['اطلب أفكارًا ومسودات وراجعها قبل استخدامها مع العميل.','Request ideas and drafts, then review them before using them with guests.']],
            'reputation-management'=>[['إدارة السمعة','Reputation management'],['اجمع المراجعات المتاحة وراجع الردود المقترحة قبل اعتمادها.','Collect available reviews and check suggested replies before approval.']],
            'review-requests'=>[['طلبات التقييم','Review requests'],['أرسل رابط التقييم عبر قناة مهيأة وتابع حالة الإرسال.','Send a review link through a configured channel and track delivery status.']],
            'ai-seo-analysis'=>[['تحليل SEO','SEO audit'],['اعرض الملاحظات ورتّب الإصلاحات حسب الأولوية.','Review findings and prioritise fixes.']],
            'ai-keywords'=>[['الكلمات المفتاحية','Keyword research'],['اكتشف أفكار كلمات مرتبطة بخدماتك وراجع ملاءمتها لسوقك.','Explore keyword ideas relevant to your services and check market suitability.']],
            'google-business'=>[['Google Business','Google Business'],['اربط ملفك وراجع المحتوى المقترح ثم انشر أو جدول.','Connect your profile, review suggested content, then publish or schedule.']],
            'ai-articles'=>[['المقالات','Articles'],['جهّز مسودة مقال وعدّل الحقائق والتفاصيل قبل النشر.','Prepare an article draft and verify facts and details before publishing.']],
            'social-media'=>[['السوشيال ميديا','Social media'],['جهّز منشورات للقنوات المرتبطة وراجع الجدول وحالة النشر.','Prepare posts for connected channels and review scheduling and publication status.']],
            'creative-studio'=>[['استوديو التصميم','Creative studio'],['جهّز مواد إبداعية حسب المزوّد المتاح وراجع حقوق الاستخدام.','Prepare creative assets using available providers and review usage rights.']],
            'marketing-assistant'=>[['مساعد التسويق','Marketing assistant'],['استكشف أفكار عروض وحملات تناسب جمهورك وموسمك.','Explore offers and campaign ideas suited to your audience and season.']],
            'ads-management'=>[['إدارة الإعلانات','Ad management'],['اربط حسابات الإعلانات وراجع الأداء والتوصيات والميزانية.','Connect ad accounts and review performance, recommendations and budgets.']],
            'ai-competitors'=>[['تحليل المنافسين','Competitor analysis'],['قارن المصادر العامة المتاحة وراجع تاريخ النتائج.','Compare accessible public sources and check the date of findings.']],
            'competitor-monitoring'=>[['متابعة المنافسين','Competitor monitoring'],['تابع تغييرات المصادر المتاحة حسب إعدادات المتابعة.','Monitor accessible source changes according to monitoring settings.']],
            'crm'=>[['إدارة العملاء','CRM'],['نظّم العملاء والصفقات وحدّد المسؤول ومهمة المتابعة.','Organise contacts and deals, assigning ownership and follow-up tasks.']],
            'revenue-intelligence'=>[['تحليل الإيرادات','Revenue insights'],['راجع مؤشرات الإيرادات مع التفريق بينها وبين التحصيل الفعلي.','Review revenue indicators while distinguishing them from actual cash collection.']],
            'agency-white-label'=>[['الوكالات والهوية','Agency branding'],['أدِر العملاء والصلاحيات وأكد نطاق الهوية المتاح في باقتك.','Manage clients and permissions and confirm branding scope in your plan.']],
        ];
    }

    public static function all(): array
    {
        // Each field is [Arabic, English]; samples are explicitly illustrative.
        return [
            'bookings-payments' => [
                'path' => '/operations', 'related' => ['booking-website','trip-automation','crm-revenue'],
                'requirements' => ['أضف الرحلات والأسعار والتوفر. لاستلام الدفع أونلاين يلزم بوابة دفع مفعّلة وبيانات التاجر الصحيحة.', 'Add tours, prices and availability. Online collection requires an enabled payment gateway and valid merchant credentials.'],
                'limits' => ['العملات والرسوم وطرق الدفع حسب البوابة. الإلغاء يحرر المقاعد، لكنه لا يعيد الأموال تلقائيًا. الاسترداد يحتاج إجراء منفصل ومراجعة نتيجته.', 'Currencies, fees and payment methods depend on the gateway. Cancellation releases seats but does not automatically return money. Refunds require a separate action and outcome review.'],
                'example' => [['رحلة بحرية · مثال فقط','Boat tour · example only'],['بالغان × 50 + طفل × 25 + إضافة 10 = 135','2 adults × 50 + 1 child × 25 + extra 10 = 135'],['عربون 40 · متبقٍ 95 · المقاعد حسب إعداد احتساب الأطفال','Deposit 40 · balance 95 · seats follow the child-seat setting']],
                'faq' => [
                    [['هل الأطفال بيستهلكوا مقاعد؟','Do children use seats?'],['تحدد لكل رحلة هل التوفر بعدد الحجوزات أو المقاعد، وهل الطفل يستهلك مقعدًا. أسعار الأطفال والإضافات محفوظة مع الحجز.','Choose booking or seat capacity per tour and whether children use seats. Child prices and extras are saved with the booking.']],
                    [['هل التذكرة فيها QR؟','Does the ticket include a QR code?'],['يمكن إصدار تذكرة للحجز المؤكد. فتح QR يعرض التذكرة؛ تسجيل الحضور يتطلب موظفًا مسجّلًا وله صلاحية.','Confirmed bookings can receive a ticket. Opening the QR displays it; check-in requires an authorised signed-in staff member.']],
                ],
            ],
            'booking-website' => [
                'path'=>'/website-builder','related'=>['bookings-payments','ai-chat','google-visibility'],
                'requirements'=>['جهّز اسم شركتك وصورًا تملك حق استخدامها وتفاصيل الرحلات. راجع الموقع والأسعار قبل النشر. ربط الدومين الخاص يحتاج إعداد DNS ومساعدة الفريق.','Prepare your company name, licensed photos and tour details. Review the site and prices before publishing. Custom domains need DNS setup and team assistance.'],
                'limits'=>['توليد المحتوى لا يغني عن المراجعة. تكلفة الدومين والاستضافة أو الربط الخاص تُحدد حسب الإعداد؛ اطلب تأكيدًا قبل الشراء.','Generated content still needs review. Domain, hosting and custom connection costs depend on setup; request confirmation before purchasing.'],
                'example'=>[['رحلة سفاري · يوم واحد','Safari · one day'],['صور → برنامج الرحلة → ما يشمله السعر → التوفر','Photos → itinerary → inclusions → availability'],['اختيار يوم وأفراد وإضافات → مراجعة السعر → حجز','Choose a date, travellers and extras → review price → book']],
                'faq'=>[[['هل الموقع المنشور يحتاج مراجعة؟','Should I review the published site?'],['نعم؛ اختبر الروابط والصور والأسعار والحجز على الهاتف والكمبيوتر قبل مشاركة الموقع مع عملائك.','Yes. Test links, photos, prices and booking on mobile and desktop before sharing it with guests.']]],
            ],
            'ai-chat'=>[
                'path'=>'/chat','related'=>['booking-website','crm-revenue','bookings-payments'],
                'requirements'=>['أضف معلومات شركتك وأسعارك وسياساتك لقاعدة المعرفة. واتساب يحتاج حساب أعمال ورقمًا وربطًا وصلاحيات مناسبة للمزوّد.','Add business information, prices and policies to the knowledge base. WhatsApp requires a business account, number, connection and provider permissions.'],
                'limits'=>['عدد رسائل AI حسب الباقة. رسوم واتساب والمزوّد منفصلة عند تطبيقها. راجع إجابات الأسعار والسياسات وفعّل التحويل لموظف عند الحاجة.','AI message allowance follows your plan. WhatsApp and provider fees are separate where applicable. Review price and policy answers and enable staff handover when needed.'],
                'example'=>[['ضيف: هل عندكم سفاري للأطفال؟','Guest: Do you offer a safari for children?'],['مساعد: راجع العمر المسموح في تفاصيل الرحلة. ما تاريخ الرحلة وعدد الأفراد؟','Assistant: Check the tour’s age policy. What date and party size do you need?'],['لو المعلومة ناقصة: تحويل لموظف، بدون اختراع سعر','If information is missing: hand over to staff without inventing a price']],
                'faq'=>[[['هل الردود مضمونة الصحة؟','Are answers guaranteed correct?'],['لا؛ جودة الرد تعتمد على المعلومات المدخلة. راجع الردود الحساسة واستخدم المراجعة أو التحويل لموظف.','No. Quality depends on the supplied information. Review sensitive answers and use approval or staff handover.']]],
            ],
            'trip-automation'=>[
                'path'=>'/automation','related'=>['bookings-payments','reviews-reputation','crm-revenue'],
                'requirements'=>['فعّل التذكير واختر لغة الرسائل والمنطقة الزمنية والموعد. يلزم قناة إرسال مهيّأة وتشغيل المهام المجدولة على الاستضافة.','Enable reminders and select language, timezone and timing. A configured delivery channel and scheduled hosting jobs are required.'],
                'limits'=>['التسليم يعتمد على المزوّد وبيانات العميل. الفشل المؤكد يُعاد ضمن حد المحاولات؛ التسليم غير المعروف ينتظر مراجعة حتى لا تتكرر الرسالة.','Delivery depends on the provider and guest contact details. Definite failures retry within a limit; unknown delivery waits for review to prevent duplicate messages.'],
                'example'=>[['تذكير: رحلتك غدًا؛ راجع وقت ومكان اللقاء في تأكيد الحجز','Reminder: your trip is tomorrow; check meeting time and location in your confirmation'],['ملخص صباحي: رحلات اليوم · حجوزات جديدة · متابعة مطلوبة','Morning brief: today’s tours · new bookings · follow-up needed'],['حالة الإرسال: تم / فشل مؤكد / يحتاج مراجعة','Delivery state: sent / definite failure / review required']],
                'faq'=>[[['إيه اللي يحصل لو الرسالة فشلت؟','What if delivery fails?'],['راجع الحالة في مركز العمليات. لا تعِد إرسال الحالة الغامضة قبل التأكد من المزوّد.','Review the status in Operations. Do not resend an uncertain delivery before checking the provider.']]],
            ],
            'reviews-reputation'=>[
                'path'=>'/reviews','related'=>['trip-automation','google-visibility','ai-chat'],
                'requirements'=>['اربط ملف Google Business بالحساب الذي لديه صلاحية إدارته. أضف رابط التقييم وقناة الإرسال، واختَر سياسة اعتماد الردود.','Connect Google Business using an account authorised to manage it. Add the review link and delivery channel, then choose reply approval rules.'],
                'limits'=>['سحب ونشر المراجعات حسب صلاحيات المنصة الخارجية. طلب التقييم لا يضمن تقييمًا إيجابيًا، ولا يُستخدم لحذف أو إخفاء رأي العميل.','Review retrieval and publishing depend on external permissions. Requests do not guarantee positive ratings and are not a way to delete or hide guest feedback.'],
                'example'=>[['ضيف: الرحلة جميلة لكن الانتظار كان طويلًا','Guest: lovely tour, but the wait was long'],['مسودة: شكرًا لملاحظتك. نعتذر عن التأخير وسنراجع تنظيم وقت اللقاء.','Draft: thank you for your feedback. We apologise for the delay and will review meeting arrangements.'],['موظف يراجع الوقائع ويعتمد الرد قبل النشر','A staff member verifies the facts and approves the reply']],
                'faq'=>[[['هل ممكن أراجع الرد قبل النشر؟','Can I review replies before publishing?'],['نعم؛ استخدم وضع الموافقة وراجع خصوصًا الشكاوى والوعود بالتعويض.','Yes. Use approval mode, particularly for complaints and compensation promises.']]],
            ],
            'google-visibility'=>[
                'path'=>'/auto-seo','related'=>['content-marketing','booking-website','competitors'],
                'requirements'=>['أضف موقعك. تقارير Search Console وAnalytics تحتاج حسابًا لديه صلاحية للموقع أو الخاصية، وGoogle Business يحتاج صلاحية للملف.','Add your website. Search Console and Analytics reports require access to the site or property; Google Business requires profile permissions.'],
                'limits'=>['ظهور البيانات يعتمد على الربط وتوفرها لدى Google. التوصيات تحتاج تطبيقًا ومتابعة؛ لا يوجد ضمان ترتيب أو ترشيح من محركات الذكاء الاصطناعي.','Data depends on connection and availability at Google. Recommendations need implementation and follow-up; rankings or AI recommendations are not guaranteed.'],
                'example'=>[['مثال تقرير: صفحة رحلة بلا وصف بحث واضح','Example finding: a tour page lacks a clear search description'],['الأولوية: عالية · الإجراء: كتابة عنوان ووصف مناسبين','Priority: high · action: write a relevant title and description'],['المتابعة: ظهور ونقرات للفترة المختارة، عند توفر بيانات حقيقية','Follow-up: impressions and clicks for the selected period, when real data exists']],
                'faq'=>[[['هل هظهر أول نتيجة فورًا؟','Will I rank first immediately?'],['لا. النتائج تعتمد على المنافسة وجودة موقعك والتنفيذ والوقت؛ المنصة تعرض الأدلة والتوصيات ولا تضمن ترتيبًا.','No. Results depend on competition, site quality, implementation and time. The platform supplies evidence and recommendations, not a ranking guarantee.']]],
            ],
            'content-marketing'=>[
                'path'=>'/articles','related'=>['google-visibility','competitors','crm-revenue'],
                'requirements'=>['حدّد جمهورك ونبرة شركتك. النشر يحتاج ربط القنوات وصلاحياتها؛ الإيميل يحتاج قائمة مسموح التواصل معها وإعداد إرسال، والإعلانات تحتاج حسابًا وميزانية.','Define your audience and brand voice. Publishing needs connected channels and permissions; email needs a permitted contact list and delivery setup, and ads need an account and budget.'],
                'limits'=>['حدود التوليد حسب الباقة والمزوّد. ميزانية الإعلانات ورسوم إرسال الرسائل منفصلة. راجع الصور والأسعار والحقائق قبل النشر.','Generation limits depend on plan and provider. Ad spend and message delivery fees are separate. Review images, prices and facts before publishing.'],
                'example'=>[['مقال: ماذا تأخذ معك في رحلة سفاري؟','Article: what should you pack for a safari?'],['منشور: جهّز حذاءً مناسبًا وماءً، وراجع تعليمات رحلتك','Post: bring suitable shoes and water, and check your tour instructions'],['إيميل: برنامج الرحلة وتفاصيل اللقاء · إعلان: مسودة تحتاج مراجعة وميزانية','Email: itinerary and meeting details · ad: a draft requiring review and budget']],
                'faq'=>[[['هل الاشتراك يشمل ميزانية الإعلان؟','Does the subscription include ad spend?'],['ميزانية الإعلان تدفع لمزوّد الإعلان بشكل منفصل، إلا إذا اتفقت على عرض يشملها صراحةً.','Ad spend is paid separately to the ad provider unless an explicit offer includes it.']]],
            ],
            'competitors'=>[
                'path'=>'/competitor-intelligence','related'=>['google-visibility','content-marketing','crm-revenue'],
                'requirements'=>['أضف روابط المنافسين التي تريد تحليلها وحدّد سوقك وخدماتك للمقارنة.','Add competitor URLs and define your market and services for comparison.'],
                'limits'=>['التحليل يعتمد على المعلومات العامة المتاحة وما يمكن الوصول إليه. لا يعرض إيرادات المنافس أو حجوزاته الخاصة، وقد تتغير الأسعار والمحتوى بعد الفحص.','Analysis relies on accessible public information. It does not reveal private revenue or bookings; prices and content may change after the scan.'],
                'example'=>[['موقعك: برنامج الرحلة واضح · منافس: سياسة الإلغاء أوضح','Your site: clear itinerary · competitor: clearer cancellation policy'],['فرصة: إضافة سياسة إلغاء واضحة بجوار الحجز','Opportunity: place a clear cancellation policy beside booking'],['راجع مصدر المعلومة وتاريخ التحليل قبل اتخاذ القرار','Check the source and analysis date before deciding']],
                'faq'=>[
                    [['مصدر البيانات إيه؟','Where does the data come from?'],['من المصادر العامة المتاحة والصفحات القابلة للفحص؛ النتائج تقديرات وتحليلات وليست وصولًا لحسابات المنافس الداخلية.','From accessible public sources and scannable pages. Findings are analysis and estimates, not access to internal competitor accounts.']],
                    [['التحديث بيحصل كل قد إيه؟','How often is data refreshed?'],['حسب إعدادات المتابعة وتشغيل المهام وحدود الباقة؛ راجع تاريخ آخر تحليل ولا تعتبر التقرير بيانات لحظية.','It depends on monitoring settings, scheduled jobs and plan limits. Check the last analysis date; reports are not live data.']],
                ],
            ],
            'crm-revenue'=>[
                'path'=>'/crm','related'=>['bookings-payments','ai-chat','agency-teams'],
                'requirements'=>['أضف العملاء والصفقات وحدد مراحل البيع ومسؤول المتابعة. سجّل المدفوعات الصحيحة حتى تكون التقارير مفيدة.','Add contacts and deals, define sales stages and assign follow-up owners. Record accurate payments for useful reporting.'],
                'limits'=>['قيمة الحجز ليست نقدًا محصلًا. التحصيل والاسترداد الفعلي والاسترداد المعلّق منفصلون، والعملات تُعرض كل واحدة على حدة.','Booking value is not collected cash. Collection, completed refunds and pending refunds are separate; each currency is reported independently.'],
                'example'=>[['عميل مهتم → تواصل → عرض → حجز','Interested lead → contact → offer → booking'],['مثال مالي: محصل 100 USD · مسترد 20 USD · صافي 80 USD','Financial example: collected 100 USD · refunded 20 USD · net 80 USD'],['المبالغ المعلّقة منفصلة؛ لا تُجمع العملات المختلفة','Pending amounts stay separate; different currencies are not added together']],
                'faq'=>[
                    [['هل إلغاء الحجز يعني استرداد الفلوس؟','Does cancellation mean money was refunded?'],['لا؛ الإلغاء والاسترداد النقدي إجراءان منفصلان. راجع حالة الاسترداد والمبلغ الفعلي.','No. Cancellation and cash refund are separate actions. Check refund status and the actual amount.']],
                    [['مين يقدر يشوف المالية؟','Who can view finances?'],['حسب دور وصلاحيات مساحة العمل؛ الوصول المالي متاح للأدوار المخولة وليس لكل عضو.','Workspace roles and permissions control financial access; it is limited to authorised roles.']],
                ],
            ],
            'agency-teams'=>[
                'path'=>'/agency','related'=>['crm-revenue','content-marketing','bookings-payments'],
                'requirements'=>['حدّد العملاء وأعضاء الفريق وأدوارهم. اطلب تأكيد دعم عدد الحسابات والهوية المطلوبة ضمن الباقة قبل الشراء.','Define clients, team members and roles. Confirm account allowance and requested branding support in your plan before purchasing.'],
                'limits'=>['White Label لا يعني تلقائيًا تطبيقًا مستقلًا أو تخصيص كل شاشة أو بوابات دفع مستقلة. النطاق والدومين والصلاحيات تُحدد حسب الميزات والإعداد المتاح.','White Label does not automatically include a separate app, customisation of every screen or independent gateways. Branding, domains and permissions depend on available features and setup.'],
                'example'=>[['وكالة → مساحة عميل أ → مساحة عميل ب','Agency → client A workspace → client B workspace'],['مدير: إدارة مسموحة · موظف: مهامه · مشاهد: قراءة فقط','Manager: authorised management · staff: assigned work · viewer: read only'],['بيانات كل مساحة تُعرض للأعضاء المخوّلين فقط','Workspace data is visible only to authorised members']],
                'faq'=>[
                    [['هل كل الموظفين بيشوفوا كل العملاء؟','Can every member see every client?'],['لا؛ راجع العضوية والدور وصلاحيات كل مساحة عمل قبل دعوة الفريق.','No. Review membership, role and workspace permissions before inviting the team.']],
                    [['White Label يشمل إيه بالضبط؟','What exactly does White Label include?'],['اطلب عرضًا يحدد الشعار والألوان والدومين وعدد العملاء والميزات المشمولة؛ لا تفترض تخصيصًا غير مذكور.','Request an offer specifying logo, colours, domain, client count and included features. Do not assume unlisted customisation.']],
                ],
            ],
        ];
    }
}
