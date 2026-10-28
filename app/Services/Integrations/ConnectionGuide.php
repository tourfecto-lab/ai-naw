<?php

/**
 * Tourfecto - Connection Guide
 *
 * دليل الربط لكل منصة خارجية في صفحة "الربط والتكاملات":
 *   - auto:   المنصة هتعمل إيه لوحدها بعد ما العميل يربط.
 *   - manual: الحاجات اللي المنصة مش هتقدر تعملها وعلى العميل يعملها بنفسه.
 *   - steps:  خطوات الربط للعميل خطوة بخطوة.
 *   - before: حاجات لازم تكون جاهزة عند العميل قبل الربط.
 *   - requires: مفاتيح لازم الأدمن يحطها مرة واحدة على مستوى المنصة
 *     (من صفحة إدارة التكاملات). لو ناقصة، زرار الربط هيفشل للعميل،
 *     فبنعرض "جاري التجهيز" بدله. روابط الـ redirect مش هنا لأن
 *     الكود بيبنيها من APP_URL لو مش متحددة.
 *
 * منطق خالص بدون DB عشان الاختبارات تشتغل offline.
 */
class ConnectionGuide
{
    public static function all(): array
    {
        return [
            'google_business' => [
                'requires' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
                'before' => [
                    'يكون عندك نشاط على Google Maps متأكَّد (Verified).',
                    'تكون مالك أو مدير على النشاط في Google Business Profile.',
                ],
                'steps' => [
                    'اضغط "ربط الحساب".',
                    'اختار حساب Google اللي بتدير بيه النشاط.',
                    'وافق على الصلاحيات اللي Google هيطلبها.',
                    'اختار الفرع/النشاط من القائمة واضغط حفظ.',
                ],
                'auto' => [
                    'سحب التقييمات الجديدة أول بأول.',
                    'كتابة ونشر الرد على كل تقييم تلقائيًا (أو بعد موافقتك لو فعّلت المراجعة).',
                    'نشر بوستات وعروض على صفحة النشاط في مواعيد ثابتة.',
                ],
                'manual' => [
                    'تأكيد ملكية النشاط (Google بيبعت كود بالبريد أو بالتليفون) - دي لازم تعملها بنفسك من google.com/business.',
                ],
            ],
            'tripadvisor' => [
                'requires' => ['TRIPADVISOR_API_KEY'],
                'before' => [
                    'يكون نشاطك ظاهر على TripAdvisor.',
                ],
                'steps' => [
                    'اضغط "ربط الحساب" واختار موقعك.',
                    'اكتب اسم النشاط زي ما هو مكتوب في TripAdvisor واضغط بحث.',
                    'اختار نشاطك من النتايج.',
                ],
                'auto' => [
                    'سحب التقييمات الجديدة ومتابعة متوسط التقييم.',
                    'كتابة رد احترافي جاهز لكل تقييم.',
                ],
                'manual' => [
                    'TripAdvisor مش بيسمح لأي برنامج ينشر الرد بالنيابة عنك: انسخ الرد الجاهز من المنصة والصقه في صفحتك على TripAdvisor (Management Center).',
                ],
            ],
            'google_search_console' => [
                'requires' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
                'before' => [
                    'يكون موقعك مضاف ومتأكَّد في Google Search Console.',
                ],
                'steps' => [
                    'اضغط "ربط الحساب".',
                    'اختار حساب Google اللي عليه الموقع ووافق على الصلاحيات.',
                    'اختار موقعك من القائمة.',
                ],
                'auto' => [
                    'متابعة ظهور موقعك في بحث Google والكلمات اللي بتجيب زيارات.',
                    'اقتراح وكتابة مقالات وتحسينات SEO بناءً على البيانات الحقيقية.',
                ],
                'manual' => [
                    'لو موقعك مش متأكَّد في Search Console: ادخل search.google.com/search-console واضف الموقع وأكّده (أسهل طريقة: سجل DNS من عند شركة الدومين).',
                ],
            ],
            'google_analytics' => [
                'requires' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
                'before' => [
                    'يكون عندك حساب Google Analytics 4 مركّب على موقعك.',
                ],
                'steps' => [
                    'اضغط "ربط Google Analytics" جنب الموقع.',
                    'اختار حساب Google ووافق على الصلاحيات.',
                    'اختار الـ Property الخاصة بموقعك.',
                ],
                'auto' => [
                    'تقارير الزيارات ومصادرها والحجوزات في لوحة واحدة.',
                ],
                'manual' => [],
            ],
            'google_ads' => [
                'requires' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_ADS_DEVELOPER_TOKEN'],
                'before' => [
                    'يكون عندك حساب Google Ads.',
                    'وسيلة دفع مضافة في حساب Google Ads (المنصة مش بتدفع بالنيابة عنك).',
                ],
                'steps' => [
                    'اضغط "ربط الحساب".',
                    'اختار حساب Google ووافق على الصلاحيات.',
                    'اختار حساب الإعلانات من القائمة.',
                ],
                'auto' => [
                    'مزامنة الحملات والنتايج يوميًا.',
                    'تنبيهك لو حملة بتصرف من غير نتيجة، واقتراح أو تطبيق التعديلات (حسب إعدادات الطيار الآلي).',
                ],
                'manual' => [
                    'إضافة وسيلة الدفع وتحديد الميزانية القصوى في حساب Google Ads.',
                ],
            ],
            'meta_ads' => [
                'requires' => ['META_APP_ID', 'META_APP_SECRET'],
                'before' => [
                    'يكون عندك حساب إعلانات على Facebook (Ad Account) وصفحة فيسبوك.',
                    'وسيلة دفع مضافة في حساب الإعلانات.',
                ],
                'steps' => [
                    'اضغط "ربط الحساب".',
                    'سجّل دخول فيسبوك ووافق على كل الصلاحيات المطلوبة (متشيلش أي علامة).',
                    'اختار حساب الإعلانات من القائمة.',
                ],
                'auto' => [
                    'مزامنة حملات فيسبوك وانستجرام ونتايجها.',
                    'تنبيهات واقتراحات تحسين الحملات تلقائيًا.',
                ],
                'manual' => [
                    'إضافة وسيلة الدفع في Meta Business Suite.',
                    'لو فيسبوك طلب تأكيد هوية أو تأكيد النشاط التجاري، لازم تعمله بنفسك من business.facebook.com.',
                ],
            ],
            'tiktok' => [
                'requires' => ['TIKTOK_CLIENT_KEY', 'TIKTOK_CLIENT_SECRET'],
                'before' => [
                    'حساب TikTok (يفضل Business).',
                ],
                'steps' => [
                    'اضغط "ربط الحساب".',
                    'سجّل دخول TikTok ووافق على الصلاحيات.',
                ],
                'auto' => [
                    'نشر الفيديوهات اللي المنصة بتجهزها على حسابك.',
                ],
                'manual' => [],
            ],
            'youtube' => [
                'requires' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
                'before' => [
                    'قناة YouTube على حساب Google.',
                ],
                'steps' => [
                    'اضغط "ربط الحساب".',
                    'اختار حساب Google والقناة ووافق على الصلاحيات.',
                ],
                'auto' => [
                    'نشر الفيديوهات القصيرة (Shorts) على قناتك.',
                ],
                'manual' => [],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * مفاتيح المنصة الناقصة لربط الخدمة دي (فاضية = جاهزة للعملاء).
     *
     * @param callable(string):string $lookup بيرجّع قيمة المفتاح أو ''
     * @return string[]
     */
    public static function missingKeys(string $key, callable $lookup): array
    {
        $missing = [];
        foreach (self::get($key)['requires'] ?? [] as $envKey) {
            $value = trim((string) $lookup($envKey));
            if ($value === '' || strpos($value, 'your-') === 0) {
                $missing[] = $envKey;
            }
        }
        return $missing;
    }

    /**
     * HTML للدليل (قابل للطي) - كل النصوص بتتعمل لها escape.
     */
    public static function renderHtml(string $key): string
    {
        $guide = self::get($key);
        if ($guide === null) {
            return '';
        }

        $sections = [
            'before' => ['title' => 'قبل ما تربط تأكد من', 'tag' => 'ul'],
            'steps' => ['title' => 'خطوات الربط', 'tag' => 'ol'],
            'auto' => ['title' => 'بعد الربط المنصة هتعمل لوحدها', 'tag' => 'ul'],
            'manual' => ['title' => 'حاجات لازم تعملها بنفسك (المنصة مش مسموح لها تعملها)', 'tag' => 'ul'],
        ];

        $html = '';
        foreach ($sections as $section => $meta) {
            $items = $guide[$section] ?? [];
            if (empty($items)) {
                continue;
            }
            $html .= '<div style="margin-top:8px;"><strong style="font-size:13px;">' . $meta['title'] . '</strong>'
                . '<' . $meta['tag'] . ' style="margin:4px 0;padding-inline-start:20px;font-size:13px;line-height:1.8;">';
            foreach ($items as $item) {
                $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '</' . $meta['tag'] . '></div>';
        }

        return '<details style="margin-top:8px;"><summary style="cursor:pointer;font-size:13px;">إزاي أربط؟ والمنصة هتعمل إيه؟</summary>'
            . $html . '</details>';
    }
}
