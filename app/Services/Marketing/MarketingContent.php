<?php

/**
 * Tourfecto - محتوى الموقع العام (عربي + إنجليزي) من مكان واحد
 *
 * صفحات المميزات، الرئيسية، الأسعار، من نحن، الاسترجاع كلها بتقرا من هنا.
 * كل نص مكتوب [عربي, إنجليزي]. أي لغة تانية (فرنساوي/ألماني) بتاخد الإنجليزي.
 *
 * ⚠️ أي ميزة مكتوبة هنا لازم تكون موجودة فعلاً في المنصة - مفيش وعود مش حقيقية.
 */
class MarketingContent
{
    /** النص باللغة الحالية من زوج [ar, en] */
    public static function t($pair): string
    {
        if (!is_array($pair)) {
            return (string) $pair;
        }
        return (string) (self::lang() === 'ar' ? ($pair[0] ?? '') : ($pair[1] ?? $pair[0] ?? ''));
    }

    public static function lang(): string
    {
        $l = function_exists('current_lang') ? current_lang() : 'ar';
        return $l === 'ar' ? 'ar' : 'en';
    }

    public static function isAr(): bool
    {
        return self::lang() === 'ar';
    }

    // ================================================================== المميزات

    /**
     * كل مجموعات المميزات - كل واحدة ليها صفحة /features/{slug}
     * legacy = روابط /services/{slug} القديمة اللي بتتحوّل للصفحة دي (301)
     */
    public static function features(): array
    {
        $features = [
            'bookings-payments' => [
                'icon' => 'calendar', 'tone' => 'accent',
                'legacy' => [],
                'title' => ['الحجوزات والتوفر والدفع', 'Bookings, availability & payments'],
                'short' => ['كل الحجوزات في مكان واحد، والتوفر محدّث لوحده، والفلوس توصلك أونلاين.', 'Every booking in one place, availability that updates itself, and payments collected online.'],
                'tagline' => ['بطّل تتابع الحجوزات على الورق والواتساب', 'Stop tracking bookings on paper and WhatsApp'],
                'problem' => ['الحجوزات جاية من الموقع والواتساب والتليفون، والتوفر في كراسة، والعربون متسجّل في ذاكرتك. النتيجة: حجز مكرر، مكان ضاع، وفلوس مش عارف وصلت ولا لأ.', 'Bookings come from your site, WhatsApp and the phone; availability lives in a notebook; deposits live in your head. The result: double bookings, lost seats and payments you can’t track.'],
                'solution' => ['المنصة بتجمع كل الحجوزات في شاشة واحدة، بتخصم الأماكن من التوفر أول ما الحجز يتأكد، وبتبعت للعميل لينك دفع يدفع منه في دقيقة.', 'Tourfecto puts every booking on one screen, deducts seats the moment a booking is confirmed, and sends your guest a payment link they can pay in a minute.'],
                'capabilities' => [
                    [['تقويم توفر لكل رحلة', 'Availability calendar per tour'], ['حدد عدد الأماكن لكل يوم، أو عدّل شهر كامل مرة واحدة.', 'Set seats per day, or update a whole month in one go.']],
                    [['كل الحجوزات في مكان واحد', 'All bookings in one place'], ['من الموقع، من الشات، أو بتسجلها بنفسك - بحالاتها: مستني، مؤكد، اتلغى، خلص.', 'From your site, the chat, or entered by you - with clear statuses: pending, confirmed, cancelled, completed.']],
                    [['لينكات دفع على الواتساب', 'Payment links over WhatsApp'], ['ابعت للعميل لينك يدفع منه بالكارت - والحجز يتعلّم مدفوع لوحده.', 'Send a link your guest pays by card - and the booking marks itself as paid.']],
                    [['عربون ودفعات يدوية', 'Deposits & manual payments'], ['سجّل الكاش والتحويل والدفع الجزئي، واعرف الباقي على كل عميل.', 'Record cash, transfers and partial payments, and see the balance due on every booking.']],
                    [['الفلوس على حسابك أنت', 'Money goes to your account'], ['اربط حساب Stripe بتاعك، أو استلم عن طريق المنصة لو لسه ماعندكش.', 'Connect your own Stripe account, or collect through the platform if you don’t have one yet.']],
                    [['إلغاء الحجز اللي ماتدفعش', 'Auto-release unpaid bookings'], ['لو العميل مادفعش في الوقت المحدد، الحجز يتلغي والمكان يرجع للتوفر.', 'If a guest doesn’t pay in time, the booking is released and the seats go back on sale.']],
                ],
                'steps' => [
                    ['ضيف رحلاتك وحدد التوفر', 'Add your tours and set availability'],
                    ['استقبل الحجوزات من الموقع والشات أو سجّلها بنفسك', 'Take bookings from your site and chat, or add them yourself'],
                    ['ابعت لينك الدفع وتابع مين دفع ومين لسه', 'Send payment links and see who has paid'],
                ],
                'faq' => [
                    [['محتاج حساب Stripe؟', 'Do I need a Stripe account?'], ['لأ. تقدر تسجل الدفعات يدوي (كاش/تحويل)، أو تستلم أونلاين عن طريق المنصة، أو تربط Stripe بتاعك لما تحب.', 'No. You can record payments manually (cash/transfer), collect online through the platform, or connect your own Stripe whenever you like.']],
                    [['العميل يقدر يدفع جزء بس؟', 'Can a guest pay only part of the price?'], ['أيوة، تقدر تبعت لينك بمبلغ العربون، وتسجل الباقي لما يدفعه.', 'Yes. Send a link for the deposit amount and record the rest when it’s paid.']],
                ],
            ],

            'booking-website' => [
                'icon' => 'layout', 'tone' => 'violet',
                'legacy' => ['ai-website-builder', 'website-optimizer'],
                'title' => ['موقع حجز جاهز لشركتك', 'A booking website for your company'],
                'short' => ['موقع برحلاتك وأسعارك يستقبل حجوزات ودفع أونلاين - من غير مبرمج.', 'A site with your tours and prices that takes bookings and payments - no developer needed.'],
                'tagline' => ['موقعك يبيع وانت نايم', 'A website that sells while you sleep'],
                'problem' => ['أغلب شركات الرحلات عندها صفحة فيسبوك أو موقع قديم مفيهوش حجز. العميل لازم يراسلك ويستنى الرد - وكتير منهم بيروح لغيرك.', 'Most tour companies have a Facebook page or an old site with no booking. Guests have to message you and wait - and many book elsewhere.'],
                'solution' => ['المنصة بتعملك موقع حجز بصفحة لكل رحلة، بالتوفر الحقيقي والدفع أونلاين، ومعاه شات ذكي يرد على الزوار. وعندك موقع أصلاً؟ اربطه وخلّي المنصة تصلّح مشاكله.', 'Tourfecto builds you a booking site with a page for every tour, live availability and online payment, plus an AI chat that answers visitors. Already have a site? Connect it and let Tourfecto fix its issues.'],
                'capabilities' => [
                    [['صفحة لكل رحلة', 'A page for every tour'], ['صور، وصف، سعر، ومدة - وزرار حجز بيقرا التوفر الحقيقي.', 'Photos, description, price and duration - with a book button that reads live availability.']],
                    [['حجز ودفع من الموقع', 'Book and pay on the site'], ['العميل يختار اليوم وعدد الأفراد ويدفع، والحجز يوصلك على طول.', 'Guests pick a date and party size and pay; the booking lands in your dashboard instantly.']],
                    [['شات ذكي مدمج', 'Built-in AI chat'], ['يرد على أسئلة الزوار ويساعدهم يحجزوا.', 'Answers visitors’ questions and helps them book.']],
                    [['تصليح موقعك الحالي', 'Fix your existing site'], ['فحص دوري للسرعة والمشاكل التقنية مع إصلاحات جاهزة تطبقها.', 'Regular checks for speed and technical issues, with ready fixes you can apply.']],
                    [['جاهز لمحركات البحث', 'Search-engine ready'], ['عناوين ووصف وبيانات منظمة لكل صفحة عشان جوجل يفهمها.', 'Titles, descriptions and structured data on every page so Google understands it.']],
                ],
                'steps' => [
                    ['اكتب بيانات شركتك ورحلاتك', 'Enter your company and tour details'],
                    ['المنصة تبني الموقع وتراجعه انت', 'Tourfecto builds the site; you review it'],
                    ['انشر وابدأ استقبل حجوزات', 'Publish and start taking bookings'],
                ],
                'faq' => [
                    [['أقدر أستخدم الدومين بتاعي؟', 'Can I use my own domain?'], ['تواصل معانا ونظبطلك الربط بالدومين بتاعك.', 'Contact us and we’ll help you connect your own domain.']],
                    [['عندي موقع ووردبريس، أعمل إيه؟', 'I have a WordPress site - what then?'], ['اربطه بالمنصة: هتقدر تنشر عليه مقالات وتصلّح مشاكله، وتستخدم الشات والحجز جنبه.', 'Connect it: you can publish articles to it, fix its issues, and use the chat and booking alongside it.']],
                ],
            ],

            'ai-chat' => [
                'icon' => 'message', 'tone' => 'teal',
                'legacy' => ['whatsapp-chatbot', 'ai-assistant'],
                'title' => ['شات ذكي يبيع على الموقع والواتساب', 'AI chat that sells on your site and WhatsApp'],
                'short' => ['رد فوري 24 ساعة بلغة العميل، بيعرف رحلاتك وأسعارك، وبيحجز من جوه المحادثة.', 'Instant 24/7 replies in the guest’s language - it knows your tours and prices and books inside the chat.'],
                'tagline' => ['محدش يستناك ترد عليه تاني', 'No guest waits for a reply again'],
                'problem' => ['العميل بيسأل الساعة 2 بالليل عن سعر رحلة. لو الرد اتأخر للصبح، غالبًا حجز مع حد تاني.', 'A guest asks about a tour price at 2am. If the answer waits until morning, they’ve probably booked elsewhere.'],
                'solution' => ['مساعد ذكاء اصطناعي بيتعلم من معلومات شركتك، بيرد فورًا بلغة العميل، بيجمع بيانات المهتمين، وبيكمّل الحجز معاهم - وانت تقدر تراجع الردود قبل ما تتبعت لو حبيت.', 'An AI assistant that learns your business, replies instantly in the guest’s language, captures leads and completes the booking with them - and you can review replies before they’re sent if you prefer.'],
                'capabilities' => [
                    [['بيتعلم من معلوماتك', 'Learns from your information'], ['ارفع ملفات PDF أو اكتب الأسئلة المتكررة والأسعار والسياسات.', 'Upload PDFs or add your FAQs, prices and policies.']],
                    [['حجز من جوه الشات', 'Books inside the chat'], ['يسأل عن اليوم وعدد الأفراد ويسجل الحجز في لوحتك.', 'Asks for the date and party size and records the booking in your dashboard.']],
                    [['جمع العملاء المهتمين', 'Lead capture'], ['كل زائر مهتم يتسجل باسمه ورقمه وإيه اللي سأل عليه.', 'Every interested visitor is saved with their name, number and what they asked about.']],
                    [['متابعة تلقائية', 'Automatic follow-ups'], ['رسالة متابعة للي سأل وماحجزش - في الوقت اللي تحدده.', 'A follow-up message to people who asked but didn’t book - on your schedule.']],
                    [['بيتحسن مع الوقت', 'Gets better over time'], ['بيجمعلك الأسئلة اللي ماعرفش يرد عليها عشان تضيف إجابتها مرة واحدة.', 'Collects the questions it couldn’t answer so you can add the answer once.']],
                    [['انت المتحكم', 'You stay in control'], ['شغّله تلقائي بالكامل، أو خلّيه يقترح الرد وانت توافق.', 'Run it fully automatic, or let it suggest replies for your approval.']],
                ],
                'steps' => [
                    ['ضيف معلومات شركتك ورحلاتك', 'Add your business and tour information'],
                    ['اربط الشات بموقعك ورقم الواتساب', 'Connect the chat to your site and WhatsApp number'],
                    ['تابع المحادثات والحجوزات من مكان واحد', 'Follow conversations and bookings in one inbox'],
                ],
                'faq' => [
                    [['بيرد بأنهي لغة؟', 'Which languages does it reply in?'], ['بيرد بلغة العميل اللي كتب بيها.', 'It replies in the language the guest writes in.']],
                    [['ممكن يقول معلومة غلط؟', 'Could it give wrong information?'], ['بيرد من المعلومات اللي انت ضايفها بس. ولو مش متأكد، تقدر تخليه يحوّل المحادثة ليك أو تفعّل المراجعة قبل الإرسال.', 'It answers from the information you added. If you want extra safety, have it hand over to you or turn on review-before-send.']],
                ],
            ],

            'trip-automation' => [
                'icon' => 'zap', 'tone' => 'amber',
                'legacy' => [],
                'title' => ['أتمتة يومك: تذكير الرحلات وملخص الصبح', 'Automate your day: trip reminders & morning brief'],
                'short' => ['العميل يفتكر رحلته، وانت تعرف يومك من رسالة واحدة الصبح.', 'Guests remember their trip, and you know your day from one morning message.'],
                'tagline' => ['الشغل المتكرر يتعمل لوحده', 'The repetitive work runs itself'],
                'problem' => ['كل يوم بتبعت نفس رسايل التذكير يدوي، وبتلف على الحجوزات عشان تعرف مين جاي النهارده ومين لسه مادفعش.', 'Every day you send the same reminders by hand and dig through bookings to see who’s coming today and who hasn’t paid.'],
                'solution' => ['المنصة بتبعت تذكير لكل عميل قبل رحلته، وبتبعتلك انت ملخص كل صبح بالرحلات والحجوزات الجديدة، وبعد الرحلة بتطلب من العميل تقييم.', 'Tourfecto reminds every guest before their trip, sends you a summary each morning with today’s trips and new bookings, and asks guests for a review after the trip.'],
                'capabilities' => [
                    [['تذكير قبل الرحلة', 'Pre-trip reminders'], ['واتساب أو إيميل قبل الرحلة بيوم، بلغة العميل، وفيه تفاصيل الرحلة والميعاد.', 'WhatsApp or email the day before, in the guest’s language, with the trip details and time.']],
                    [['ملخص الصبح ليك', 'Your morning brief'], ['رحلات النهارده، الحجوزات الجديدة، واللي محتاج متابعة - في رسالة واحدة.', 'Today’s trips, new bookings and what needs your attention - in one message.']],
                    [['طلب تقييم بعد الرحلة', 'Post-trip review requests'], ['رسالة شكر فيها لينك التقييم بعد ما الرحلة تخلص.', 'A thank-you message with your review link once the trip is done.']],
                    [['مركز الأتمتة', 'Automation center'], ['المنصة تقترح إجراءات (رد على تقييم، متابعة عميل) وتنفذها بموافقتك.', 'Tourfecto suggests actions (reply to a review, follow up a lead) and runs them with your approval.']],
                ],
                'steps' => [
                    ['فعّل التذكير وملخص الصبح من الإعدادات', 'Turn on reminders and the morning brief'],
                    ['اختار القناة (واتساب أو إيميل) والميعاد', 'Choose the channel (WhatsApp or email) and timing'],
                    ['المنصة تبعت كل يوم لوحدها', 'Tourfecto sends them every day on its own'],
                ],
                'faq' => [
                    [['لو العميل مالوش رقم واتساب؟', 'What if a guest has no WhatsApp number?'], ['التذكير بيروح على الإيميل بدل الواتساب.', 'The reminder goes by email instead.']],
                ],
            ],

            'reviews-reputation' => [
                'icon' => 'star', 'tone' => 'amber',
                'legacy' => ['reputation-management', 'review-requests'],
                'title' => ['التقييمات والسمعة', 'Reviews & reputation'],
                'short' => ['تقييمات أكتر على جوجل، ورد احترافي على كل تقييم في ثواني.', 'More Google reviews, and a professional reply to every review in seconds.'],
                'tagline' => ['السمعة الكويسة بتجيب حجوزات', 'A good reputation brings bookings'],
                'problem' => ['العملاء المبسوطين نادرًا بيكتبوا تقييم، والتقييم السلبي اللي مالوش رد بيفضل قدام كل زائر جديد.', 'Happy guests rarely leave reviews, and an unanswered negative review sits in front of every new visitor.'],
                'solution' => ['المنصة بتطلب التقييم من العميل بعد رحلته، بتسحب تقييماتك من جوجل، وبتكتب رد مناسب بأسلوبك - توافق عليه أو تخليه يتبعت لوحده.', 'Tourfecto asks guests for a review after their trip, pulls in your Google reviews and drafts a reply in your voice - approve it or let it post automatically.'],
                'capabilities' => [
                    [['طلبات التقييم', 'Review requests'], ['واتساب أو إيميل للعميل بلينك التقييم المباشر.', 'WhatsApp or email to the guest with a direct review link.']],
                    [['ردود بالذكاء الاصطناعي', 'AI replies'], ['رد مخصص لكل تقييم، بنبرة شركتك، ومعالجة هادية للتقييم السلبي.', 'A tailored reply to each review in your brand’s tone, handling negative reviews calmly.']],
                    [['قواعد رد خاصة بيك', 'Your own reply rules'], ['مثلاً: التقييمات 5 نجوم ترد لوحدها، والسلبية تستنى موافقتك.', 'For example: 5-star reviews reply automatically, negative ones wait for you.']],
                    [['تحليل السمعة', 'Reputation insights'], ['إيه اللي العملاء بيمدحوه وإيه اللي بيشتكوا منه، وتطور تقييمك مع الوقت.', 'What guests praise, what they complain about, and how your rating moves over time.']],
                ],
                'steps' => [
                    ['اربط ملفك على Google Business', 'Connect your Google Business Profile'],
                    ['فعّل طلبات التقييم بعد الرحلات', 'Turn on post-trip review requests'],
                    ['راجع الردود المقترحة أو خليها تلقائية', 'Review suggested replies or make them automatic'],
                ],
                'faq' => [
                    [['بيدعم TripAdvisor؟', 'Does it support TripAdvisor?'], ['المتابعة الأساسية على Google. سحب تقييمات TripAdvisor متاح لما يتفعّل مفتاح TripAdvisor.', 'Google is fully supported. TripAdvisor review sync is available once a TripAdvisor key is configured.']],
                ],
            ],

            'google-visibility' => [
                'icon' => 'search', 'tone' => 'accent',
                'legacy' => ['ai-seo-analysis', 'ai-keywords', 'google-business'],
                'title' => ['ظهورك على جوجل', 'Get found on Google'],
                'short' => ['SEO تلقائي، كلمات بحث، بوستات Google Business، وتقرير أداء من Search Console.', 'Automatic SEO, keywords, Google Business posts and a Search Console performance report.'],
                'tagline' => ['اظهر للناس اللي بتدور على رحلتك', 'Show up for people searching for your tours'],
                'problem' => ['السياح بيدوروا على جوجل وعلى الخريطة وبيسألوا ChatGPT. لو موقعك ضعيف وملفك على الخريطة مهمل، مش هتظهرلهم.', 'Travellers search Google, check Maps and ask ChatGPT. If your site is weak and your Maps profile neglected, they won’t find you.'],
                'solution' => ['المنصة بتحلل موقعك، بتصلح المشاكل، بتقترح الكلمات اللي عملاءك بيدوروا بيها، بتنشر على ملفك في Google Business، وبتوريك النتايج من Search Console و Analytics.', 'Tourfecto audits your site, fixes issues, suggests the keywords your guests search for, posts to your Google Business Profile and shows results from Search Console and Analytics.'],
                'capabilities' => [
                    [['تحليل SEO و AEO و GEO', 'SEO, AEO & GEO audit'], ['جاهزية موقعك لجوجل ولمحركات البحث بالذكاء الاصطناعي، مع حلول مرتبة بالأهمية.', 'How ready your site is for Google and AI search engines, with fixes ranked by priority.']],
                    [['كلمات البحث وترتيبك', 'Keywords & rankings'], ['الكلمات اللي عملاءك بيدوروا بيها، ومتابعة ترتيبك عليها.', 'The keywords your guests use, and tracking where you rank for them.']],
                    [['بوستات Google Business', 'Google Business posts'], ['محتوى جاهز لملفك على الخريطة يتنشر في مواعيده.', 'Ready content for your Maps profile, published on schedule.']],
                    [['تقرير الأداء', 'Performance report'], ['الظهور والنقرات من Search Console والزيارات من Google Analytics في مكان واحد.', 'Impressions and clicks from Search Console and visits from Google Analytics in one place.']],
                    [['تقارير على الإيميل', 'Email reports'], ['تقرير SEO دوري يوصلك لوحده.', 'A regular SEO report delivered to your inbox.']],
                ],
                'steps' => [
                    ['ضيف موقعك واربط Google', 'Add your site and connect Google'],
                    ['المنصة تحلل وتقترح الإصلاحات والكلمات', 'Tourfecto audits and suggests fixes and keywords'],
                    ['تابع ترتيبك وزياراتك وهي بتزيد', 'Watch your rankings and visits grow'],
                ],
                'faq' => [
                    [['يعني إيه AEO و GEO؟', 'What are AEO and GEO?'], ['تحسين موقعك عشان محركات البحث بالذكاء الاصطناعي (زي ChatGPT و Gemini) ترشحك لما حد يسألها عن رحلة.', 'Optimising your site so AI search engines like ChatGPT and Gemini recommend you when someone asks about a trip.']],
                ],
            ],

            'content-marketing' => [
                'icon' => 'edit', 'tone' => 'violet',
                'legacy' => ['ai-articles', 'social-media', 'creative-studio', 'marketing-assistant', 'ads-management'],
                'title' => ['المحتوى والتسويق', 'Content & marketing'],
                'short' => ['مقالات، بوستات سوشيال، حملات إيميل، وإعلانات Meta و Google - من مكان واحد.', 'Articles, social posts, email campaigns, and Meta & Google ads - from one place.'],
                'tagline' => ['تسويق منتظم من غير فريق كبير', 'Consistent marketing without a big team'],
                'problem' => ['التسويق محتاج محتوى كل أسبوع على أكتر من منصة، وأغلب الشركات الصغيرة ماعندهاش وقت ولا فريق.', 'Marketing needs fresh content every week on several channels, and most small companies have neither the time nor the team.'],
                'solution' => ['الذكاء الاصطناعي بيكتب المقالات والبوستات، بيجهز الصور والفيديوهات القصيرة، بينشر في المواعيد، وبيتابع إعلاناتك ويقترح تحسينات.', 'AI writes your articles and posts, prepares images and short videos, publishes on schedule, and monitors your ads with suggested improvements.'],
                'capabilities' => [
                    [['مقالات للموقع', 'Articles for your site'], ['مقالات محسّنة لجوجل تتنشر على موقعك أو ووردبريس.', 'Search-optimised articles published to your site or WordPress.']],
                    [['السوشيال ميديا', 'Social media'], ['بوستات جاهزة وجدولة نشر.', 'Ready-made posts and scheduled publishing.']],
                    [['استوديو التصميم', 'Creative studio'], ['صور وفيديوهات قصيرة وسكربتات فيديو بالذكاء الاصطناعي.', 'AI images, short videos and video scripts.']],
                    [['الإيميل ماركتنج', 'Email marketing'], ['قوائم عملاء وحملات ورسايل تلقائية.', 'Contact lists, campaigns and automated emails.']],
                    [['الإعلانات', 'Ads'], ['اربط حساب Google Ads أو Meta بتاعك وتابع أداء حملاتك يوميًا، مع تنبيهات واقتراحات تحسين. إنشاء الحملات أو إيقافها أو تغيير الميزانية بيتم بموافقتك بس، والحجوزات اللي جاية من إعلاناتك بتتسجّل كتحويلات في حسابك.', 'Connect your own Google Ads or Meta account and track campaign performance daily, with alerts and optimisation suggestions. Creating or pausing campaigns and changing budgets happens only with your approval, and bookings that come from your ads are recorded as conversions in your own account.']],
                    [['مساعد التسويق', 'Marketing assistant'], ['اسأله عن أفكار عروض وحملات مناسبة لموسمك.', 'Ask it for offer and campaign ideas that suit your season.']],
                ],
                'steps' => [
                    ['قول للمنصة عن شركتك وجمهورك', 'Tell Tourfecto about your company and audience'],
                    ['راجع المحتوى المقترح وعدّل عليه', 'Review and edit the suggested content'],
                    ['انشر أو جدول، وتابع النتايج', 'Publish or schedule, then track results'],
                ],
                'faq' => [
                    [['المحتوى بيتنشر من غير ما أشوفه؟', 'Is content published without my review?'], ['انت اللي بتقرر: تراجع وتوافق، أو تجدول النشر التلقائي.', 'You decide: review and approve, or schedule automatic publishing.']],
                ],
            ],

            'competitors' => [
                'icon' => 'target', 'tone' => 'coral',
                'legacy' => ['ai-competitors', 'competitor-monitoring'],
                'title' => ['مراقبة المنافسين', 'Competitor monitoring'],
                'short' => ['اعرف لما منافس يغيّر سعره أو عرضه، وقارن ترتيبك بترتيبهم على جوجل.', 'Know when a competitor changes prices or offers, and compare your Google rankings with theirs.'],
                'tagline' => ['خليك دايمًا خطوة قدام', 'Always stay one step ahead'],
                'problem' => ['متابعة مواقع المنافسين وأسعارهم يدوي بتاخد ساعات، ولما تعرف إنهم نزّلوا السعر بيكون فات الأوان.', 'Checking competitors’ sites and prices by hand takes hours, and by the time you notice a price drop it’s too late.'],
                'solution' => ['المنصة بتراقب منافسينك باستمرار، بتنبهك لما سعر أو عرض يتغير، وبتبعتلك ملخص أسبوعي بإيه اللي حصل وإيه اللي تعمله.', 'Tourfecto watches your competitors continuously, alerts you when a price or offer changes, and sends a weekly digest of what happened and what to do.'],
                'capabilities' => [
                    [['تنبيهات الأسعار', 'Price alerts'], ['تنبيه لما منافس يغيّر سعر رحلة شبه رحلتك.', 'An alert when a competitor changes the price of a tour like yours.']],
                    [['ترتيبهم على جوجل', 'Their Google rankings'], ['قارن ترتيبك بترتيبهم على نفس الكلمات.', 'Compare your rankings with theirs on the same keywords.']],
                    [['تحليل مواقعهم', 'Website analysis'], ['الذكاء الاصطناعي يحلل استراتيجيتهم ويطلّع نقاط ضعفهم.', 'AI analyses their strategy and points out their weak spots.']],
                    [['ملخص أسبوعي', 'Weekly digest'], ['أهم التغييرات عند منافسينك، مع اقتراحات عملية.', 'The most important changes at your competitors, with practical suggestions.']],
                ],
                'steps' => [
                    ['ضيف مواقع منافسينك', 'Add your competitors’ websites'],
                    ['المنصة تراقب الأسعار والعروض والترتيب', 'Tourfecto tracks prices, offers and rankings'],
                    ['استلم التنبيهات والملخص الأسبوعي', 'Get alerts and the weekly digest'],
                ],
                'faq' => [],
            ],

            'crm-revenue' => [
                'icon' => 'trending-up', 'tone' => 'teal',
                'legacy' => ['crm', 'revenue-intelligence'],
                'title' => ['العملاء والإيرادات', 'Customers & revenue'],
                'short' => ['CRM لعملائك ومتابعتهم، وأرقام واضحة بتوضحلك إيه اللي بيجيب فلوس.', 'A CRM for your customers and follow-ups, and clear numbers that show what brings in money.'],
                'tagline' => ['اعرف عملاءك وفلوسك بالأرقام', 'Know your customers and your money in numbers'],
                'problem' => ['بيانات العملاء متفرقة بين الواتساب والإكسل، ومش واضح أنهي قناة بتجيب حجوزات فعلاً وأنهي بتصرف فلوس على الفاضي.', 'Customer data is scattered across WhatsApp and spreadsheets, and it’s unclear which channel actually brings bookings and which just spends money.'],
                'solution' => ['كل عميل ليه ملف فيه محادثاته وحجوزاته، والمنصة بتربط الإيرادات بمصدرها وبتقترح خطوات تزوّد الدخل.', 'Every customer gets a profile with their conversations and bookings, and Tourfecto links revenue to its source and suggests steps to grow it.'],
                'capabilities' => [
                    [['ملف لكل عميل', 'A profile for every customer'], ['المحادثات والحجوزات والملاحظات في مكان واحد.', 'Conversations, bookings and notes in one place.']],
                    [['صفقات ومتابعة', 'Deals & follow-ups'], ['مراحل البيع، وتسلسلات متابعة تلقائية.', 'Sales stages and automated follow-up sequences.']],
                    [['لوحة الإيرادات', 'Revenue dashboard'], ['الإيرادات حسب المصدر والفترة والرحلة.', 'Revenue by source, period and tour.']],
                    [['اقتراحات لزيادة الدخل', 'Revenue suggestions'], ['فحص يومي يطلّعلك فرص زي عملاء يرجعوا أو رحلات محتاجة تسعير.', 'A daily scan that surfaces opportunities, like returning customers or tours that need repricing.']],
                    [['لوحة المدير', 'Executive dashboard'], ['أهم الأرقام في شاشة واحدة.', 'The key numbers on one screen.']],
                ],
                'steps' => [
                    ['عملاءك بيتسجلوا لوحدهم من الحجوزات والشات', 'Customers are added automatically from bookings and chat'],
                    ['تابع كل عميل وصفقته', 'Follow every customer and deal'],
                    ['شوف إيه اللي بيجيب فلوس وركّز عليه', 'See what brings in money and focus on it'],
                ],
                'faq' => [],
            ],

            'agency-teams' => [
                'icon' => 'building', 'tone' => 'accent',
                'legacy' => ['agency-white-label'],
                'title' => ['للوكالات والفرق', 'For agencies & teams'],
                'short' => ['أدِر أكتر من شركة وموقع، وضيف فريقك بصلاحيات محددة، وقدّم الخدمة باسمك.', 'Manage several companies and sites, add your team with set permissions, and offer the service under your brand.'],
                'tagline' => ['كبّر شغلك من غير ما تكبّر الفوضى', 'Grow without growing the chaos'],
                'problem' => ['الوكالة اللي بتخدم كذا شركة سياحة بتلف بين حسابات وأدوات كتير، وكل عميل ليه تقرير مختلف.', 'An agency serving several tour companies juggles many accounts and tools, with a different report for every client.'],
                'solution' => ['من حساب واحد تدير كل عملاءك ومواقعهم، تضيف فريقك بصلاحيات، وتقدم المنصة لعملاءك بهويتك أنت.', 'From one account you manage every client and site, add your team with permissions, and offer the platform to clients under your own brand.'],
                'capabilities' => [
                    [['أكتر من موقع', 'Multiple sites'], ['كل عميل أو فرع بموقعه وبياناته المنفصلة.', 'Each client or branch with its own site and data.']],
                    [['فريق بصلاحيات', 'Team with permissions'], ['ضيف موظفين وحدد كل واحد يشوف ويعمل إيه.', 'Add staff and choose what each person can see and do.']],
                    [['بهويتك (White label)', 'White label'], ['شعارك وألوانك قدام عملاءك.', 'Your logo and colours in front of your clients.']],
                    [['API للمطورين', 'Developer API'], ['اربط المنصة بأنظمتك عن طريق الـ API.', 'Connect Tourfecto to your systems via the API.']],
                ],
                'steps' => [
                    ['فعّل حساب الوكالة', 'Activate your agency account'],
                    ['ضيف عملاءك وفريقك', 'Add your clients and team'],
                    ['أدِر الكل من لوحة واحدة', 'Manage everything from one dashboard'],
                ],
                'faq' => [],
            ],
        ];
        require_once __DIR__ . '/ServicePageDetails.php';
        foreach (ServicePageDetails::all() as $slug => $details) {
            $features[$slug]['details'] = $details;
            $features[$slug]['faq'] = array_merge($features[$slug]['faq'], $details['faq']);
        }
        $features['bookings-payments']['solution'] = ['المنصة تجمع الحجوزات وتحجز وحدات التوفر عند إنشاء الحجز، وتحررها عند الإلغاء. تابع الدفعات والإضافات وأسعار الأطفال والتذاكر من مكان واحد.', 'Tourfecto collects bookings and reserves availability units when a booking is created, releasing them on cancellation. Track payments, extras, child prices and tickets in one place.'];
        $features['google-visibility']['steps'][2] = ['تابع الأداء الفعلي وطبّق التوصيات؛ النتائج تختلف ولا تُضمن', 'Track actual performance and implement recommendations; results vary and are not guaranteed'];
        $features['trip-automation']['faq'][0][1] = ['يمكن إرسال التذكير بالإيميل عند توفر عنوان صحيح وقناة مهيأة؛ التسليم يعتمد على المزوّد.', 'Email reminders can be sent when a valid address and configured channel are available; delivery depends on the provider.'];
        return $features;
    }

    /** /services/{old} → /features/{new} */
    public static function legacyMap(): array
    {
        $map = [];
        foreach (self::features() as $slug => $f) {
            foreach ($f['legacy'] as $old) {
                $map[$old] = $slug;
            }
        }
        return $map;
    }

    // ================================================================== الأسعار

    /** اسم الباقة باللغة الحالية (الاسم في قاعدة البيانات عربي) */
    public static function planName(string $key, string $dbName): string
    {
        $names = [
            'starter' => ['الباقة الأساسية', 'Starter'],
            'professional' => ['الباقة الاحترافية', 'Professional'],
            'enterprise' => ['الباقة المؤسسية', 'Enterprise'],
            'seo-essential' => ['سيو أساسي', 'SEO Essential'],
            'seo-growth' => ['سيو النمو', 'SEO Growth'],
            'seo-agency' => ['سيو الوكالات', 'SEO Agency'],
        ];
        if (self::isAr()) {
            return $dbName;
        }
        return isset($names[$key]) ? $names[$key][1] : $dbName;
    }

    public static function planBlurb(string $key): string
    {
        $b = [
            'starter' => ['لشركة صغيرة بتبدأ تستقبل حجوزات أونلاين.', 'For a small company starting to take bookings online.'],
            'professional' => ['لشركة بتكبر وعايزة الأتمتة تشتغل لوحدها.', 'For a growing company that wants automation to run on its own.'],
            'enterprise' => ['للشركات الكبيرة والوكالات اللي بتدير أكتر من موقع.', 'For larger companies and agencies running several sites.'],
            'seo-essential' => ['لشركة عايزة تظهر في جوجل والذكاء الاصطناعي في سوق واحد.', 'For a company that wants to rank in Google and AI answers in one market.'],
            'seo-growth' => ['لشركة بتستهدف أكتر من سوق ولغة، وعايزة تحسين كل أسبوع.', 'For a company targeting several markets and languages, improved every week.'],
            'seo-agency' => ['للوكالات اللي بتدير السيو لأكتر من عميل.', 'For agencies managing SEO for several clients.'],
        ];
        return isset($b[$key]) ? self::t($b[$key]) : '';
    }

    /** مميزات موجودة في كل الباقات (مش محدودة بالباقة في المنصة) */
    public static function includedEverywhere(): array
    {
        return [
            ['الحجوزات وتقويم التوفر', 'Bookings & availability calendar'],
            ['لينكات الدفع والدفعات اليدوية', 'Payment links & manual payments'],
            ['موقع حجز برحلاتك', 'Booking website with your tours'],
            ['تذكير الرحلات وملخص الصبح', 'Trip reminders & morning brief'],
            ['طلبات التقييم بعد الرحلة', 'Post-trip review requests'],
            ['CRM لعملائك', 'Customer CRM'],
            ['تقرير Search Console و Analytics', 'Search Console & Analytics report'],
            ['عربي وإنجليزي', 'Arabic & English'],
        ];
    }

    public static function pricingFaq(): array
    {
        return [
            [['أقدر أجرب قبل ما أدفع؟', 'Can I try before I pay?'], ['أيوة، اعمل حساب مجاني وجرّب المنصة، وبعدين اختار الباقة المناسبة لحجم شغلك.', 'Yes. Create a free account, try the platform, then choose the plan that fits your business.']],
            [['إيه اللي بيحصل لو خلّصت حصة الشهر؟', 'What happens if I use up my monthly allowance?'], ['استهلاكك ظاهر في صفحة الاشتراك وبنبعتلك تنبيه قبل ما يخلص. تقدر ترقّي لباقة أكبر وتدفع فرق السعر بس، أو تكلمنا نزودلك الحصة.', 'Your usage is shown on the subscription page and we alert you before it runs out. Upgrade and pay only the difference, or contact us to add more.']],
            [['أقدر ألغي في أي وقت؟', 'Can I cancel any time?'], ['أيوة، من صفحة الاشتراك بضغطة واحدة، والاشتراك يفضل شغال لحد آخر المدة المدفوعة.', 'Yes, in one click from the subscription page. Your plan stays active until the end of the paid period.']],
            [['بدفع إزاي؟', 'How do I pay?'], ['ابعتلنا على الواتساب أو الإيميل باسم الباقة، وإحنا بنرد عليك بالإيميل/الحساب اللي تحوّل عليه والمبلغ بالظبط. أول ما التحويل يوصل بنفعّل الباقة ونبعتلك فاتورة.', 'Message us on WhatsApp or by email with the plan you want. We reply with the email/account to pay to and the exact amount. Once the payment arrives we activate your plan and send an invoice.']],
            [['التفعيل بياخد قد إيه؟', 'How long does activation take?'], ['عادةً في نفس اليوم بعد ما التحويل يوصل، وتقدر تستخدم التجربة المجانية لحد ما يتفعّل.', 'Usually the same day once the payment arrives - and you can keep using the free trial until then.']],
            [['السنوي أوفر؟', 'Is yearly cheaper?'], ['أيوة، السعر السنوي أقل من 12 شهر مدفوعين شهري - النسبة مكتوبة على كل باقة.', 'Yes. The yearly price is lower than 12 monthly payments - the saving is shown on each plan.']],
        ];
    }

    // ================================================================== من نحن

    public static function about(): array
    {
        return [
            'title' => ['عن Tourfecto', 'About Tourfecto'],
            'lead' => ['بنبني المنصة اللي كنا نتمنى تكون موجودة لشركات الرحلات والسياحة: مكان واحد للحجوزات والعملاء والتسويق والسمعة، بالذكاء الاصطناعي، وبالعربي.', 'We’re building the platform we wished existed for tour and travel companies: one place for bookings, customers, marketing and reputation - powered by AI, and built Arabic-first.'],
            'story' => [
                ['شركات الرحلات الصغيرة والمتوسطة بتشتغل في سوق صعب: العميل بيقارن أونلاين، والمنافس على بعد ضغطة، والشغل اليومي (الرد على الرسايل، تأكيد الحجوزات، التذكير، التقييمات) بياكل اليوم كله.', 'Small and mid-sized tour companies work in a tough market: guests compare online, competitors are one click away, and the daily work - answering messages, confirming bookings, reminders, reviews - eats the whole day.'],
                ['الأدوات الموجودة يا إما معمولة لفنادق كبيرة وغالية، يا إما أداة لكل حاجة لوحدها. فبتلاقي الشركة شغالة على واتساب وإكسل وفيسبوك وموقع قديم في نفس الوقت.', 'Existing tools are either built for large, expensive hotel chains, or each solves one thing. So companies end up running on WhatsApp, spreadsheets, Facebook and an old website all at once.'],
                ['Tourfecto بيجمع كل ده في منصة واحدة، والذكاء الاصطناعي بيعمل الشغل المتكرر - عشان صاحب الشركة يركز على الرحلات والعملاء.', 'Tourfecto brings it all into one platform, and AI does the repetitive work - so owners can focus on their trips and their guests.'],
            ],
            'values' => [
                [['الأرقام قبل الكلام', 'Numbers over noise'], ['كل ميزة لازم توصّل لحجز أكتر أو وقت أقل - ونوريك ده بالأرقام.', 'Every feature must lead to more bookings or less time spent - and we show you that in numbers.']],
                [['انت المتحكم', 'You stay in control'], ['الذكاء الاصطناعي بيقترح ويساعد، والقرار في الآخر قرارك.', 'AI suggests and assists; the final decision is always yours.']],
                [['بياناتك ملكك', 'Your data is yours'], ['التوكنات والاتصالات بتتشفر، ومش بنبيع أو نشارك بياناتك.', 'Tokens and connections are encrypted, and we never sell or share your data.']],
                [['عربي من الأول', 'Arabic-first'], ['المنصة مبنية بالعربي وللسوق العربي، ومعاها إنجليزي كامل.', 'Built in Arabic for the Arab market, with full English support.']],
            ],
        ];
    }

    // ================================================================== الرئيسية

    public static function home(): array
    {
        return [
            'meta_title' => ['منصة شركات الرحلات والسياحة: حجوزات، شات ذكي، تقييمات وتسويق', 'The all-in-one platform for tour companies: bookings, AI chat, reviews & marketing'],
            'meta_desc' => ['Tourfecto منصة واحدة لشركات الرحلات: موقع حجز ودفع أونلاين، شات ذكي على الواتساب، تذكير الرحلات، تقييمات جوجل، SEO ومراقبة المنافسين - بالذكاء الاصطناعي.', 'Tourfecto is one platform for tour companies: a booking site with online payments, AI chat on WhatsApp, trip reminders, Google reviews, SEO and competitor monitoring - powered by AI.'],
            'eyebrow' => ['منصة شركات الرحلات والسياحة', 'Built for tour & travel companies'],
            'title' => [['شركة سياحتك في', 'مدار', 'واحد، مش عشرين تطبيق'], ['Your tour business in', 'one orbit,', 'not twenty apps']],
            'sub' => ['الحجوزات والدفع، الشات مع العملاء، التقييمات، التسويق والمنافسين - كله من مكان واحد، والذكاء الاصطناعي بيعمل الشغل المتكرر.', 'Bookings and payments, guest chat, reviews, marketing and competitors - all in one place, with AI doing the repetitive work.'],
            'cta1' => ['ابدأ مجانًا', 'Start free'],
            'cta2' => ['اطلب عرض توضيحي', 'Book a demo'],
            'cards' => [['الإيرادات', 'Revenue'], ['متوسط التقييم', 'Avg. rating'], ['حجوزات الشهر', 'Bookings this month']],
            'ticker' => [
                ['حجوزات ودفع أونلاين', 'Bookings & online payments'],
                ['شات ذكي 24 ساعة على الموقع والواتساب', '24/7 AI chat on your site and WhatsApp'],
                ['تذكير تلقائي قبل كل رحلة', 'Automatic reminder before every trip'],
                ['ردود ذكية على تقييمات جوجل', 'Smart replies to Google reviews'],
                ['تنبيه لما منافس يغيّر سعره', 'Alerts when a competitor changes prices'],
                ['عربي وإنجليزي', 'Arabic & English'],
            ],
            'feat_title' => ['كل اللي شركتك محتاجاه في منصة واحدة', 'Everything your company needs, in one platform'],
            'feat_sub' => ['من أول ما العميل يسأل لحد ما يكتب تقييم بعد الرحلة.', 'From the guest’s first question to their review after the trip.'],
            'feat_all' => ['شوف كل المميزات بالتفصيل', 'See every feature in detail'],
            'journey_title' => ['رحلة العميل كلها متغطية', 'The whole guest journey, covered'],
            'journey' => [
                [['يلاقيك', 'Finds you'], ['SEO، بوستات Google Business، ومقالات بتجيب زيارات.', 'SEO, Google Business posts and articles that bring visits.']],
                [['يسأل', 'Asks'], ['الشات الذكي يرد فورًا على الموقع والواتساب.', 'The AI chat answers instantly on your site and WhatsApp.']],
                [['يحجز ويدفع', 'Books & pays'], ['حجز بالتوفر الحقيقي ولينك دفع في دقيقة.', 'Booking against live availability, and a payment link in a minute.']],
                [['يسافر', 'Travels'], ['تذكير قبل الرحلة، وانت عندك ملخص يومك الصبح.', 'A reminder before the trip, and your day summarised each morning.']],
                [['يقيّم', 'Reviews'], ['طلب تقييم بعد الرحلة ورد احترافي على كل تقييم.', 'A review request after the trip and a professional reply to every review.']],
            ],
            'facts' => [
                [['24/7', '24/7'], ['الشات بيرد على عملاءك في أي وقت', 'The chat answers your guests at any hour']],
                [['+30', '30+'], ['مهمة تلقائية بتشتغل في الخلفية', 'Automated jobs running in the background']],
                [['AR · EN', 'AR · EN'], ['المنصة والرسايل بالعربي والإنجليزي', 'Platform and messages in Arabic and English']],
                [['0', '0'], ['مبرمجين محتاجهم عشان تبدأ', 'Developers needed to get started']],
            ],
            'how_title' => ['من التسجيل لأول حجز', 'From sign-up to first booking'],
            'how_sub' => ['خطوات بسيطة، ومساعدة جوه المنصة في كل خطوة.', 'Simple steps, with in-app guidance at each one.'],
            'how' => [
                [['سجّل وضيف رحلاتك', 'Sign up and add your tours'], ['اعمل حساب مجاني وضيف رحلاتك وأسعارك.', 'Create a free account and add your tours and prices.']],
                [['حدد التوفر وطريقة الدفع', 'Set availability and payments'], ['الأماكن المتاحة لكل يوم، وإزاي هتستلم فلوسك.', 'Seats per day, and how you’ll get paid.']],
                [['اربط الواتساب وجوجل', 'Connect WhatsApp and Google'], ['الشات الذكي، وتقييماتك، وملفك على الخريطة.', 'The AI chat, your reviews and your Maps profile.']],
                [['خلّي الأتمتة تشتغل', 'Let automation run'], ['تذكير، ملخص الصبح، طلبات تقييم، وتنبيهات منافسين.', 'Reminders, morning brief, review requests and competitor alerts.']],
            ],
            'ex_title' => ['إزاي المنصة ممكن تساعد شركات زيك', 'How the platform could help companies like yours'],
            'ex_note' => ['السيناريوهات دي أمثلة توضيحية لشرح الفكرة - مش عملاء حقيقيين ولا نتائج موثّقة.', 'These scenarios are illustrations to explain the idea - not real customers or documented results.'],
            'ex_badge' => ['مثال توضيحي', 'Illustrative example'],
            'examples' => [
                ['briefcase', ['شركة رحلات يومية', 'Day-tour operator'], ['الحجوزات جاية من الواتساب والتليفون، والتوفر متسجل في كراسة، وفيه حجوزات بتتكرر.', 'Bookings arrive by WhatsApp and phone, availability lives in a notebook, and double bookings happen.'], ['التوفر بقى بيتخصم لوحده، والعميل بيدفع من لينك، والتذكير بيتبعت قبل الرحلة من غير ما حد يفتكر.', 'Seats are deducted automatically, guests pay by link, and reminders go out before every trip without anyone remembering.']],
                ['building', ['فندق صغير', 'Small hotel'], ['تقييمات يومية على Google وصاحب الفندق مش لاقي وقت يرد عليها.', 'Daily Google reviews and an owner with no time to reply.'], ['الردود بتبقى جاهزة في دقايق بدل أيام، وصاحب الفندق يوافق عليها بضغطة.', 'Replies are ready in minutes instead of days, approved with one click.']],
                ['compass', ['وكالة سفريات', 'Travel agency'], ['عملاء كتير على الواتساب في نفس الوقت، وناس بتسأل وماحدش بيتابعها.', 'Lots of guests on WhatsApp at once, and enquiries that nobody follows up.'], ['الشات بيرد ويجمع بيانات المهتمين، والمتابعة التلقائية بترجّع اللي ماحجزش.', 'The chat replies and captures leads, and automatic follow-ups bring back those who didn’t book.']],
            ],
            'faq' => [
                [['محتاج خبرة تقنية؟', 'Do I need technical skills?'], ['لأ خالص. المنصة معمولة لأصحاب شركات الرحلات مش للمبرمجين - كل حاجة بخطوات واضحة.', 'Not at all. Tourfecto is built for tour company owners, not developers - everything has clear steps.']],
                [['فيه تجربة مجانية؟', 'Is there a free trial?'], ['تقدر تعمل حساب مجاني وتجرّب المنصة من غير التزام، وبعدين تختار الباقة المناسبة.', 'You can create a free account and try the platform with no commitment, then choose a plan.']],
                [['العميل بيدفع إزاي؟', 'How do guests pay?'], ['بالكارت من لينك دفع أو من موقع الحجز، أو تسجل انت الكاش والتحويل يدوي.', 'By card through a payment link or your booking site - or you record cash and transfers manually.']],
                [['بياناتي آمنة؟', 'Is my data secure?'], ['أيوة - كل التوكنات والاتصالات الحساسة (Google، واتساب، Meta، Stripe) بتتشفر، ومش بنشارك بياناتك مع حد.', 'Yes - every sensitive token and connection (Google, WhatsApp, Meta, Stripe) is encrypted, and we never share your data.']],
                [['أقدر ألغي في أي وقت؟', 'Can I cancel any time?'], ['أيوة، من صفحة الاشتراك بضغطة واحدة.', 'Yes, with one click from the subscription page.']],
            ],
        ];
    }

    // ================================================================== التنقل

    public static function nav(): array
    {
        return [
            ['/features', ['المميزات', 'Features'], 'features'],
            ['/pricing', ['الأسعار', 'Pricing'], 'pricing'],
            ['/blog', ['المدونة', 'Blog'], 'blog'],
            ['/about', ['من نحن', 'About'], 'about'],
            ['/contact', ['تواصل معنا', 'Contact'], 'contact'],
        ];
    }
}
