<?php

/**
 * Tourfecto - Services Landing Pages Controller
 * صفحات هبوط تسويقية احترافية لكل خدمة من خدمات المنصة - قالب واحد
 * متسق، بيانات منفصلة لكل خدمة.
 * @version 1.0.0
 */
class ServicesController extends Controller
{
    /** كل خدمات المنصة - المصدر الوحيد للحقيقة لصفحات الهبوط والصفحة الرئيسية */
    public static function getServicesData(): array
    {
        return [
            'ai-seo-analysis' => [
                'icon' => '🤖', 'category' => 'الذكاء الاصطناعي',
                'title' => 'تحليل SEO/AEO/GEO بالذكاء الاصطناعي',
                'tagline' => 'اعرف بالظبط ليه موقعك مش ظاهر في نتائج البحث - وإزاي تصلحه خطوة بخطوة',
                'problem' => 'أغلب أصحاب الأعمال السياحية معندهمش فكرة ليه موقعهم مش بيظهر في نتائج جوجل، أو ليه محركات البحث بالذكاء الاصطناعي (زي ChatGPT وGemini) مش بترشّحهم لعملاء بيدوروا على خدماتهم.',
                'solution' => 'المنصة بتحلّل موقعك بالكامل - السرعة، البنية، المحتوى، وحتى مدى جاهزيته لمحركات البحث بالذكاء الاصطناعي (AEO/GEO) - وتديك تقرير واضح بالمشاكل والحلول بترتيب أولوية.',
                'benefits' => ['تحليل شامل خلال دقايق مش أيام', 'تقرير بترتيب أولوية (إيه الأهم تصلحه الأول)', 'يغطي SEO التقليدي وجاهزية الذكاء الاصطناعي', 'توصيات عملية قابلة للتنفيذ فورًا'],
                'how_it_works' => ['وصّل موقعك للمنصة', 'الذكاء الاصطناعي يحلّل السرعة والبنية والمحتوى', 'تقرير واضح بالمشاكل والحلول مرتّبة بالأولوية'],
            ],
            'ai-articles' => [
                'icon' => '✍️', 'category' => 'الذكاء الاصطناعي',
                'title' => 'المقالات التسويقية بالذكاء الاصطناعي',
                'tagline' => 'محتوى منتظم لموقعك من غير ما توظّف كاتب محتوى',
                'problem' => 'المحتوى المنتظم بيحسّن ترتيبك في البحث، لكن كتابة مقالات كل أسبوع محتاجة وقت وموارد مش كل شركة سياحية صغيرة عندها.',
                'solution' => 'وصّف الموضوع اللي عايزه، والذكاء الاصطناعي يولّدلك مقال كامل جاهز للنشر - بأسلوب مناسب لصناعة السياحة ومحسّن لمحركات البحث.',
                'benefits' => ['مقالات جاهزة خلال دقايق', 'محسّنة لمحركات البحث تلقائيًا', 'تقدر تعدّل وتخصّص قبل النشر', 'نشر مباشر لموقعك أو ووردبريس'],
                'how_it_works' => ['اكتب موضوع المقال اللي عايزه', 'الذكاء الاصطناعي يولّد مقال كامل محسّن لمحركات البحث', 'راجع وعدّل وانشر مباشرة لموقعك'],
            ],
            'ai-competitors' => [
                'icon' => '🏁', 'category' => 'الذكاء الاصطناعي',
                'title' => 'تحليل المنافسين بالذكاء الاصطناعي',
                'tagline' => 'اعرف منافسينك بيعملوا إيه بالظبط - وإزاي تتفوّق عليهم',
                'problem' => 'مراقبة المنافسين يدويًا بتاخد وقت كتير ومعلوماتها غالبًا ناقصة أو قديمة.',
                'solution' => 'حدّد منافسينك، والمنصة تحلّل مواقعهم واستراتيجياتهم وتديك مقارنة واضحة مع اقتراحات عملية تتفوّق بيها.',
                'benefits' => ['مقارنة مباشرة بينك وبين منافسينك', 'اكتشاف نقاط ضعفهم', 'متابعة مستمرة مش تحليل مرة واحدة', 'توصيات تنافسية عملية'],
                'how_it_works' => ['حدّد أسماء منافسينك', 'المنصة تحلّل مواقعهم واستراتيجياتهم', 'شوف مقارنة واضحة وتوصيات تنافسية'],
            ],
            'ai-keywords' => [
                'icon' => '🔑', 'category' => 'الذكاء الاصطناعي',
                'title' => 'أبحاث الكلمات المفتاحية بالذكاء الاصطناعي',
                'tagline' => 'اعرف بالظبط إيه اللي عملاؤك بيدوروا عليه في جوجل',
                'problem' => 'استهداف الكلمات الغلط معناه إنك بتضيّع وقتك في محتوى محدش بيدور عليه.',
                'solution' => 'المنصة بتكتشف الكلمات المفتاحية الحقيقية اللي عملاؤك المحتملين بيبحثوا بيها، مع تقدير لصعوبة المنافسة عليها.',
                'benefits' => ['كلمات مفتاحية مناسبة لصناعة السياحة تحديدًا', 'ترتيب حسب الفرصة والصعوبة', 'أفكار محتوى مبنية على الكلمات دي', 'تحديث مستمر مش قائمة ثابتة'],
                'how_it_works' => ['اكتب مجال نشاطك السياحي', 'الذكاء الاصطناعي يكتشف الكلمات اللي عملاؤك بيدوروا بيها', 'استخدمها في محتواك وإعلاناتك'],
            ],
            'whatsapp-chatbot' => [
                'icon' => '💬', 'category' => 'التواصل مع العملاء',
                'title' => 'شات واتساب ذكي يرد على عملائك 24 ساعة',
                'tagline' => 'محدش يستناك ترد عليه تاني - الذكاء الاصطناعي يرد فورًا',
                'problem' => 'عملاؤك بيراسلوك في أي وقت، وأي تأخير في الرد معناه احتمال يروحوا لمنافس بيرد أسرع.',
                'solution' => 'بوت ذكاء اصطناعي مربوط بواتساب بتاعك، بيرد على استفسارات عملائك فورًا وبأسلوب طبيعي - مع خيار المراجعة اليدوية لو حبيت تراقب الردود الأول.',
                'benefits' => ['رد فوري 24 ساعة على مدار اليوم', 'وضع "مراجعة يدوية" لو عايز تتحكم في الردود', 'يتعلّم من محتوى موقعك وخدماتك', 'كل المحادثات في مكان واحد'],
                'how_it_works' => ['اربط رقم واتساب بتاعك بالمنصة', 'الذكاء الاصطناعي يرد على استفسارات عملائك', 'راجع الردود أو خلّيها تلقائية بالكامل'],
            ],
            'crm' => [
                'icon' => '🧾', 'category' => 'التواصل مع العملاء',
                'title' => 'إدارة العملاء (CRM) مصمّمة للسياحة',
                'tagline' => 'كل عميل وصفقة في مكان واحد - مش موزّعين بين شاشات وتطبيقات',
                'problem' => 'من غير نظام واضح، سهل جدًا تنسى متابعة عميل مهتم أو تفقد تتبع مرحلة صفقة معيّنة.',
                'solution' => 'لوحة CRM بسيطة بمراحل واضحة (عميل محتمل، تفاوض، مكسوبة، خسرانة) تقدر تتابع بيها كل صفقاتك من مكان واحد.',
                'benefits' => ['مراحل صفقات واضحة وقابلة للتخصيص', 'ربط تلقائي مع محادثات الشات', 'تنبيهات لمتابعة العملاء', 'تقارير أداء المبيعات'],
                'how_it_works' => ['ضيف عملاءك المحتملين وصفقاتك', 'تابعهم عبر مراحل واضحة (تفاوض، مكسوبة، خسرانة)', 'كل حاجة في مكان واحد بدل شاشات متفرقة'],
            ],
            'review-requests' => [
                'icon' => '📨', 'category' => 'السمعة والتواجد',
                'title' => 'طلب مراجعات تلقائي بعد كل خدمة',
                'tagline' => 'معظم العملاء الراضيين بينسوا يكتبوا مراجعة - إلا لو فكّرتهم في التوقيت الصح',
                'problem' => 'المراجعات هي أقوى عامل يأثر في قرار حجز عميل جديد، لكن جمعها يدويًا صعب ومنسي غالبًا.',
                'solution' => 'ضيف بيانات ضيوفك، والنظام يبعتلهم رسالة واتساب تلقائية بعد انتهاء الخدمة تطلب منهم يقيّموك - مع تذكير واحد لطيف لو مردّوش.',
                'benefits' => ['إرسال تلقائي بتوقيت مثالي', 'تذكير واحد بس (مش إزعاج)', 'رابط تقييم مباشر لـ Google/TripAdvisor', 'متابعة معدل الاستجابة'],
                'how_it_works' => ['ضيف بيانات ضيوفك بعد انتهاء الخدمة', 'النظام يبعتلهم طلب تقييم تلقائي في التوقيت المثالي', 'تابع معدل الاستجابة والمراجعات الجديدة'],
            ],
            'reputation-management' => [
                'icon' => '⭐', 'category' => 'السمعة والتواجد',
                'title' => 'إدارة السمعة والرد الذكي على المراجعات',
                'tagline' => 'رد احترافي وسريع على كل مراجعة - بدون ما تقعد قدام الشاشة كل يوم',
                'problem' => 'الرد المتأخر أو الضعيف على مراجعة سلبية بيضاعف الضرر، والرد اليدوي على كل المراجعات بياخد وقت كبير.',
                'solution' => 'الذكاء الاصطناعي بيولّد رد مناسب لكل مراجعة (إيجابية أو سلبية) خلال ثواني - تراجعه وتعتمده أو تعدّله زي ما تحب.',
                'benefits' => ['ردود جاهزة خلال ثواني', 'نبرة مناسبة لكل نوع مراجعة', 'مراجعة واعتماد قبل النشر', 'تتبع كل المنصات في مكان واحد'],
                'how_it_works' => ['المنصة تجمّع مراجعاتك من كل المنصات', 'الذكاء الاصطناعي يولّد رد مناسب لكل مراجعة', 'راجع واعتمد الرد بضغطة واحدة'],
            ],
            'google-business' => [
                'icon' => '📍', 'category' => 'السمعة والتواجد',
                'title' => 'محتوى Google Business Profile تلقائي',
                'tagline' => 'ملفك على خرائط جوجل يفضل نشيط من غير مجهود يومي',
                'problem' => 'ملف Google Business النشط بيزوّد ظهورك في البحث المحلي، لكن معظم أصحاب الأعمال بينسوا يحدّثوه.',
                'solution' => 'المنصة تولّدلك منشورات جاهزة لملف Google Business بتاعك وتنشرها في مواعيد منتظمة تلقائيًا.',
                'benefits' => ['محتوى جاهز بضغطة واحدة', 'جدولة نشر تلقائية', 'يحافظ على نشاط ملفك باستمرار', 'يحسّن ظهورك في البحث المحلي'],
                'how_it_works' => ['اربط ملف Google Business بتاعك', 'المنصة تولّد منشورات جاهزة بانتظام', 'ينشر تلقائيًا حسب الجدول اللي تحدده'],
            ],
            'social-media' => [
                'icon' => '📱', 'category' => 'التسويق والنشر',
                'title' => 'إدارة ونشر السوشيال ميديا',
                'tagline' => 'محتوى جاهز لكل منصاتك من مكان واحد',
                'problem' => 'إدارة حسابات سوشيال ميديا متعددة (فيسبوك، انستجرام) بمحتوى مختلف لكل واحد بياخد وقت كبير.',
                'solution' => 'ولّد محتوى مناسب لكل منصة، وانشره أو جدوله من لوحة واحدة بدل ما تنقل بين تطبيقات مختلفة.',
                'benefits' => ['محتوى مخصص لكل منصة', 'جدولة النشر مقدمًا', 'كل الحسابات في مكان واحد', 'أفكار محتوى بالذكاء الاصطناعي'],
                'how_it_works' => ['اختار المنصة (فيسبوك/انستجرام)', 'ولّد محتوى مناسب لكل منصة', 'انشر فورًا أو جدوله لوقت لاحق'],
            ],
            'ads-management' => [
                'icon' => '📣', 'category' => 'التسويق والنشر',
                'title' => 'إدارة الإعلانات الممولة',
                'tagline' => 'نصوص إعلانية جاهزة تحوّل المشاهدين لعملاء',
                'problem' => 'كتابة نص إعلان يجذب ويحوّل مشاهدين لعملاء حقيقيين مهارة مش كل صاحب عمل عنده وقت يتقنها.',
                'solution' => 'ولّد نصوص إعلانية جاهزة لحملات Google وMeta بناءً على خدماتك وجمهورك المستهدف.',
                'benefits' => ['نصوص إعلانية جاهزة للنشر', 'أفكار متعددة تختار الأنسب منها', 'مناسبة لصناعة السياحة تحديدًا', 'توفير وقت كتابة الإعلانات'],
                'how_it_works' => ['حدّد الخدمة اللي عايز تعلن عنها', 'الذكاء الاصطناعي يولّد نصوص إعلانية متعددة', 'اختار الأنسب واستخدمه في حملتك'],
            ],
            'marketing-assistant' => [
                'icon' => '💡', 'category' => 'التسويق والنشر',
                'title' => 'المساعد التسويقي السريع',
                'tagline' => '6 أدوات تسويقية جاهزة في مكان واحد',
                'problem' => 'محتاج شعار، أو عنوان بريد إلكتروني جذاب، أو وصف منتج - مهام تسويقية صغيرة بس بتاخد وقت متراكم.',
                'solution' => 'أدوات سريعة ومركّزة (نص إعلان، شعار، عنوان بريد، نبذة سوشيال ميديا، وصف منتج، أفكار حملة) - كل واحدة جاهزة خلال ثواني.',
                'benefits' => ['6 أدوات تسويقية جاهزة', 'نتايج فورية خلال ثواني', 'مصمّمة لصناعة السياحة', 'من غير ما تحتاج خبرة تسويقية'],
                'how_it_works' => ['اختار الأداة اللي محتاجها (شعار، عنوان بريد...)', 'اكتب وصف بسيط لمنتجك أو مناسبتك', 'احصل على نص جاهز خلال ثواني'],
            ],
            'creative-studio' => [
                'icon' => '🎨', 'category' => 'التسويق والنشر',
                'title' => 'استوديو الإبداع - صور وفيديو بالذكاء الاصطناعي',
                'tagline' => 'محتوى بصري احترافي من غير مصوّر أو مصمم',
                'problem' => 'الصور والفيديوهات الاحترافية مكلفة، وبدونها صعب تجذب انتباه العملاء على السوشيال ميديا.',
                'solution' => 'ولّد صور تسويقية احترافية وسكربتات فيديو جاهزة بناءً على وصف بسيط تكتبه.',
                'benefits' => ['صور احترافية بالذكاء الاصطناعي', 'سكربتات فيديو جاهزة', 'مقاسات مناسبة لكل منصة', 'من غير تكلفة تصوير أو تصميم'],
                'how_it_works' => ['اختار نوع المحتوى (صورة أو سكربت فيديو)', 'اكتب وصف للمشهد اللي عايزه', 'حمّل النتيجة جاهزة للاستخدام'],
            ],
            'website-optimizer' => [
                'icon' => '🛠️', 'category' => 'الذكاء التجاري',
                'title' => 'تحسين أداء الموقع',
                'tagline' => 'موقع أسرع وأوضح = عملاء أكتر بيكملوا الحجز',
                'problem' => 'الموقع البطيء أو غير الواضح بيخسّرك عملاء قبل ما يوصلوا لصفحة التواصل أصلاً.',
                'solution' => 'فحص حقيقي لسرعة موقعك، أمانه (HTTPS)، ووضوح محتواه - مع توصيات عملية للتحسين.',
                'benefits' => ['فحص حقيقي مش تخمين', 'قياس السرعة والأمان', 'توصيات واضحة بالأولوية', 'متابعة التحسن بمرور الوقت'],
                'how_it_works' => ['وصّل رابط موقعك', 'فحص حقيقي للسرعة والأمان والمحتوى', 'توصيات عملية بالأولوية'],
            ],
            'competitor-monitoring' => [
                'icon' => '🕵️', 'category' => 'الذكاء التجاري',
                'title' => 'مراقبة المنافسين المستمرة',
                'tagline' => 'اعرف أي تغيير في استراتيجية منافسيك أول بأول',
                'problem' => 'منافسينك بيغيّروا أسعارهم وعروضهم باستمرار، ومن غير متابعة مستمرة صعب تواكب.',
                'solution' => 'متابعة مستمرة (مش تحليل مرة واحدة) لمنافسيك المحدّدين مع تنبيهات لأي تغيير مهم.',
                'benefits' => ['متابعة مستمرة تلقائية', 'تنبيهات عند أي تغيير', 'مقارنة تاريخية بمرور الوقت', 'قرارات تسعير أذكى'],
                'how_it_works' => ['حدّد منافسينك المستهدفين', 'المنصة تتابعهم باستمرار', 'تنبيه فوري عند أي تغيير مهم'],
            ],
            'revenue-intelligence' => [
                'icon' => '💰', 'category' => 'الذكاء التجاري',
                'title' => 'ذكاء الإيرادات',
                'tagline' => 'اعرف مصادر دخلك الحقيقية واتخذ قرارات مبنية على أرقام',
                'problem' => 'من غير رؤية واضحة لمصادر إيراداتك، صعب تعرف فين تركّز جهدك واستثمارك.',
                'solution' => 'لوحة تحليلية توضّحلك أداء إيراداتك بمرور الوقت، مع رؤى تساعدك تاخد قرارات مبنية على بيانات حقيقية.',
                'benefits' => ['رؤية واضحة لمصادر الدخل', 'تحليل الاتجاهات بمرور الوقت', 'قرارات مبنية على بيانات', 'تقارير قابلة للمشاركة'],
                'how_it_works' => ['اربط مصادر إيراداتك', 'المنصة تحلّل الأداء بمرور الوقت', 'اتخذ قرارات مبنية على أرقام حقيقية'],
            ],
            'agency-white-label' => [
                'icon' => '🏢', 'category' => 'حلول متقدمة',
                'title' => 'حلول الوكالات (White Label)',
                'tagline' => 'قدّم كل ميزات المنصة لعملائك بهويتك أنت',
                'problem' => 'الوكالات التسويقية محتاجة أدوات قوية تقدر تقدّمها لعملائها المتعددين تحت اسمها هي، مش اسم مزوّد خارجي.',
                'solution' => 'لوحة تحكم مخصصة للوكالات تقدر تدير بيها عملاء متعددين، وتقدّم لهم كل ميزات المنصة بهوية شركتك.',
                'benefits' => ['إدارة عملاء متعددين من مكان واحد', 'هوية بصرية بتاعتك أنت', 'صلاحيات منفصلة لكل عميل', 'تقارير مجمّعة لكل الحسابات'],
                'how_it_works' => ['فعّل وضع الوكالة من حسابك', 'ضيف عملاءك المتعددين', 'قدّم لهم كل الميزات بهويتك أنت'],
            ],
            'ai-assistant' => [
                'icon' => '✨', 'category' => 'حلول متقدمة',
                'title' => 'المساعد الذكي العام',
                'tagline' => 'مساعد ذكاء اصطناعي بهوية منصتك، جاهز يساعدك في أي حاجة',
                'problem' => 'محتاج إجابة سريعة أو مساعدة في كتابة حاجة، من غير ما تخرج من المنصة وتفتح أداة تانية.',
                'solution' => 'شات ذكاء اصطناعي متكامل جوه المنصة - يساعدك تكتب محتوى، يرد على استفساراتك، أو أي مساعدة تانية تحتاجها.',
                'benefits' => ['محادثات محفوظة تقدر ترجعلها', 'مبني في نفس المنصة - مفيش تنقل', 'يفهم سياق عملك السياحي', 'متاح وقت ما تحتاجه'],
                'how_it_works' => ['افتح محادثة جديدة', 'اسأل أو اطلب مساعدة في أي حاجة', 'احصل على رد فوري مبني على سياق عملك'],
            ],
            'ai-website-builder' => [
                'icon' => '🏗️', 'category' => 'حلول متقدمة',
                'title' => 'منشئ المواقع بالذكاء الاصطناعي',
                'tagline' => 'موقع سياحي احترافي متعدد الصفحات من مجرد محادثة',
                'problem' => 'بناء موقع سياحي احترافي (برامج رحلات، صور، تفاصيل حجز) عادة محتاج مطور أو مصمم وتكلفة كبيرة.',
                'solution' => 'اتكلم مع المساعد، احكيله عن نشاطك ورحلاتك، وهو يبنيلك موقع كامل بصفحة تفصيل منفصلة لكل رحلة - مع صور حقيقية مولّدة بالذكاء الاصطناعي.',
                'benefits' => ['موقع كامل خلال دقايق مش أسابيع', 'صفحة تفصيل منفصلة لكل رحلة ببرنامج يوم بيوم', 'صور حقيقية مولّدة بالذكاء الاصطناعي', 'لوحة تحكم تعدّل بيها أي حاجة بعدين'],
                'how_it_works' => ['اتكلم مع المساعد عن نشاطك السياحي', 'احكيله عن رحلاتك وبرامجك واحدة واحدة', 'عاين موقعك الكامل وانشره بضغطة واحدة'],
            ],
        ];
    }

    /**
     * GET /api/services/catalog - الخدمات × الباقات من السجل الموحّد (عام، من غير بيانات حساب).
     * الأسعار والحدود من صفوف الفوترة فقط؛ لو الفوترة مش متاحة بنرجّع الخدمات من غير باقات.
     */
    public function catalog(array $params = []): array
    {
        $plans = class_exists('SubscriptionPlan') ? SubscriptionPlan::allAsLegacyArray() : [];
        $planList = [];
        foreach ($plans as $key => $p) {
            $planList[] = ['key' => $key, 'name' => $p['name'], 'price_monthly' => $p['price_monthly'], 'price_yearly' => $p['price_yearly'], 'currency' => $p['currency']];
        }
        header('Cache-Control: public, max-age=300');
        return $this->success([
            'plans' => $planList,
            'states' => PlatformServiceRegistry::STATES,
            'services' => array_values(PlatformServiceRegistry::matrix($plans)),
            'features' => array_values(PlatformServiceRegistry::marketingMatrix($plans)),
        ]);
    }

    /** GET /services/{slug} */
    public function show(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        if (class_exists('MarketingContent')) {
            $target = self::legacyDestination($slug, $_GET['lang'] ?? null);
            if ($target !== null) {
                header('Location: ' . $target, true, 301);
                exit;
            }
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderNotFoundPage();
            exit;
        }
        $services = self::getServicesData();

        if (!isset($services[$slug])) {
            header('HTTP/1.1 404 Not Found');
            echo $this->renderNotFoundPage();
            exit;
        }

        $s = $services[$slug];
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderServicePage($slug, $s, $services);
        exit;
    }

    /** Only allowlisted language and slugs enter a redirect; unknown URLs remain 404. */
    public static function legacyDestination(string $slug, $language = null): ?string
    {
        $map = MarketingContent::legacyMap();
        $features = MarketingContent::features();
        $target = $map[$slug] ?? (isset($features[$slug]) ? $slug : null);
        if ($target === null) return null;
        $query = is_string($language) && in_array($language, ['ar','en'], true) ? '?lang=' . $language : '';
        return '/features/' . $target . $query . (isset($map[$slug]) ? '#service-' . rawurlencode($slug) : '');
    }

    private function renderNotFoundPage(): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الصفحة مش موجودة | {$appName}</title>
    <script src="/assets/js/tf-theme.js?v=20261016"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/compass.css">
</head>
<body class="compass">
<div class="stars"></div>
<div class="wrap" style="min-height:70vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:18px;padding:24px;">
    <h1 style="font-family:var(--font-display);font-size:28px;">الخدمة دي مش موجودة</h1>
    <a href="/" class="btn-primary">ارجع للصفحة الرئيسية</a>
</div>
</body>
</html>
HTML;
    }

    private function renderServicePage(string $slug, array $s, array $allServices): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $benefitsHtml = '';
        foreach ($s['benefits'] as $b) {
            $benefitsHtml .= '<div class="svc-benefit"><span class="svc-check">✓</span>' . $esc($b) . '</div>';
        }

        $stepsHtml = '';
        foreach (($s['how_it_works'] ?? []) as $i => $step) {
            $stepsHtml .= '<div class="svc-step"><div class="svc-step-num">' . ($i + 1) . '</div><p>' . $esc($step) . '</p></div>';
        }

        $whatsappNumber = support_whatsapp_number();
        $askMessage = rawurlencode('أهلاً، عندي سؤال عن خدمة "' . $s['title'] . '"');
        $whatsappAskLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$askMessage}" : '/help/contact';

        // خدمات تانية من نفس التصنيف (لتشجيع الاستكشاف - يقلل الإحساس بالانعزال بين الصفحات)
        $relatedHtml = '';
        $relatedCount = 0;
        foreach ($allServices as $otherSlug => $other) {
            if ($otherSlug === $slug || $other['category'] !== $s['category'] || $relatedCount >= 3) {
                continue;
            }
            $relatedHtml .= '<a href="/services/' . $esc($otherSlug) . '" class="svc-related-card"><span>' . $other['icon'] . '</span><strong>' . $esc($other['title']) . '</strong></a>';
            $relatedCount++;
        }

        $title = $esc($s['title']);
        $tagline = $esc($s['tagline']);
        $icon = $s['icon'];
        $category = $esc($s['category']);
        $problem = $esc($s['problem']);
        $solution = $esc($s['solution']);
        $brandHtml = site_brand_html();

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} | {$appName}</title>
    <meta name="description" content="{$tagline}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="/assets/js/tf-theme.js?v=20261016"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/compass.css">
    <link rel="stylesheet" href="/assets/css/services.css">
</head>
<body class="compass">
<div class="stars"></div>
<div class="wrap">
    <nav class="topnav">
        <a href="/" class="brand">{$brandHtml}</a>
        <a href="/register" class="btn-primary">ابدأ مجانًا</a>
    </nav>

    <header class="svc-hero">
        <a href="/#product" class="svc-back">← كل الخدمات</a>
        <div class="svc-icon">{$icon}</div>
        <div class="svc-category">{$category}</div>
        <h1>{$title}</h1>
        <p>{$tagline}</p>
        <a href="/register" class="btn-primary">جرّب الميزة دي مجانًا</a>
    </header>

    <section class="svc-section">
        <div class="svc-problem-solution">
            <div class="svc-ps-card">
                <h3>😕 المشكلة</h3>
                <p>{$problem}</p>
            </div>
            <div class="svc-ps-card highlight">
                <h3>✅ الحل</h3>
                <p>{$solution}</p>
            </div>
        </div>
    </section>

    <section class="svc-section svc-section-alt">
        <h2>ليه تستخدم الميزة دي</h2>
        <div class="svc-benefits-grid">{$benefitsHtml}</div>
    </section>

    <section class="svc-section">
        <h2>إزاي تشتغل</h2>
        <div class="svc-steps">{$stepsHtml}</div>
    </section>

    <section class="svc-section svc-cta-section">
        <h2>جاهز تجرّبها؟</h2>
        <p>ابدأ دلوقتي وشوف الفرق بنفسك - أو اسألنا أي سؤال قبل ما تبدأ</p>
        <div class="svc-cta-buttons">
            <a href="/register" class="btn-primary">ابدأ مجانًا</a>
            <a href="{$whatsappAskLink}" target="_blank" class="btn-outline">💬 اسأل عن الميزة دي</a>
        </div>
    </section>

    <section class="svc-section svc-section-alt">
        <h2>خدمات تانية ممكن تعجبك</h2>
        <div class="svc-related-grid">{$relatedHtml}</div>
    </section>

    <footer class="site-footer-full">
        <div class="footer-bottom">© {$appName}</div>
    </footer>
</div>
</body>
</html>
HTML;
    }
}
