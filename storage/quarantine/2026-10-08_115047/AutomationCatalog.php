<?php

/**
 * Tourfecto - كتالوج الخدمات اللي بتشتغل أوتوماتيك (مركز الأتمتة)
 *
 * كل خدمة هنا ليها:
 *  - name/desc: اللي العميل بيشوفه
 *  - buckets: فئات جوه الخدمة. التعلّم والتخرّج للأوتوماتيك بيحصل لكل فئة
 *    لوحدها (مثلاً الردود على التقييمات الإيجابية بتتعلم أسرع من السلبية).
 *  - learning: 'text' = المنصة بتتعلم من تعديلات العميل على النص نفسه
 *    (وبتحط أمثلة من أسلوبه في تعليمات الذكاء الاصطناعي)،
 *    'decision' = بتتعلم من الموافقة والرفض بس (إجراءات مش نصوص).
 *  - default_mode / default_bucket_modes: الوضع لو العميل ماختارش حاجة.
 *    الافتراضي أوتوماتيك لكل حاجة بعد الربط، مع استثناءات أمان قليلة
 *    مكتوبة هنا وظاهرة للعميل يقدر يغيّرها بضغطة.
 *  - handler: الكلاس اللي بينفّذ الإجراء بعد موافقة العميل.
 *
 * @version 1.0.0
 */
class AutomationCatalog
{
    public const MODES = ['auto', 'approval', 'learning', 'manual'];

    public static function all(): array
    {
        return [
            'review_replies' => [
                'icon' => 'star',
                'name' => ['ar' => 'الرد على التقييمات', 'en' => 'Review replies'],
                'desc' => [
                    'ar' => 'أول ما تقييم جديد ينزل على Google، المنصة بتكتب رد بأسلوبك وتنشره.',
                    'en' => 'When a new Google review arrives, a reply is written in your voice and posted.',
                ],
                'learning' => 'text',
                'buckets' => [
                    'positive' => ['ar' => 'تقييمات إيجابية (4-5 نجوم)', 'en' => 'Positive (4-5 stars)'],
                    'neutral' => ['ar' => 'تقييمات متوسطة (3 نجوم)', 'en' => 'Neutral (3 stars)'],
                    'negative' => ['ar' => 'تقييمات سلبية (1-2 نجمة)', 'en' => 'Negative (1-2 stars)'],
                ],
                'default_mode' => 'auto',
                // أمان: الرد على تقييم سلبي ممكن يعمل أزمة علنية، فبيستنى
                // موافقة العميل لحد ما يقرر غير كده بنفسه.
                'default_bucket_modes' => ['negative' => 'approval'],
                'handler' => 'ReviewReplyAutomationHandler',
                'requires' => 'google_business',
            ],
            'chat_replies' => [
                'icon' => 'message',
                'name' => ['ar' => 'الرد على رسائل العملاء', 'en' => 'Customer message replies'],
                'desc' => [
                    'ar' => 'الرد على واتساب والماسنجر وشات الموقع من قاعدة معرفتك.',
                    'en' => 'Answers WhatsApp, Messenger and site chat from your knowledge base.',
                ],
                'learning' => 'text',
                'buckets' => [
                    'default' => ['ar' => 'كل الرسائل', 'en' => 'All messages'],
                ],
                'default_mode' => 'auto',
                'default_bucket_modes' => [],
                'handler' => 'ChatReplyAutomationHandler',
                'requires' => 'chat_channel',
            ],
            'website_fixes' => [
                'icon' => 'tools',
                'name' => ['ar' => 'إصلاحات الموقع', 'en' => 'Website fixes'],
                'desc' => [
                    'ar' => 'تصليح مشاكل SEO اللي التدقيق بيلاقيها (العناوين، الوصف، البيانات المنظمة) على موقعك المربوط.',
                    'en' => 'Applies SEO fixes found by the audit on your connected site.',
                ],
                'learning' => 'decision',
                'buckets' => [
                    'meta' => ['ar' => 'العناوين والوصف', 'en' => 'Titles and descriptions'],
                    'structured_data' => ['ar' => 'البيانات المنظمة', 'en' => 'Structured data'],
                    'social' => ['ar' => 'مشاركة السوشيال (OG)', 'en' => 'Social sharing (OG)'],
                    'technical' => ['ar' => 'إصلاحات تقنية', 'en' => 'Technical fixes'],
                    'default' => ['ar' => 'باقي الإصلاحات', 'en' => 'Other fixes'],
                ],
                'default_mode' => 'auto',
                'default_bucket_modes' => [],
                'handler' => 'WebsiteFixAutomationHandler',
                'requires' => 'website',
            ],
            'seo_content' => [
                'icon' => 'edit',
                'name' => ['ar' => 'نشر المقالات', 'en' => 'Article publishing'],
                'desc' => [
                    'ar' => 'المقالات اللي محرك المحتوى بيكتبها بتتنشر وتتبعت لجوجل للفهرسة.',
                    'en' => 'Articles written by the content engine are published and sent for indexing.',
                ],
                'learning' => 'decision',
                'buckets' => [
                    'default' => ['ar' => 'كل المقالات', 'en' => 'All articles'],
                ],
                'default_mode' => 'auto',
                'default_bucket_modes' => [],
                'handler' => 'SeoContentAutomationHandler',
                'requires' => 'website',
            ],
            'review_requests' => [
                'icon' => 'send',
                'name' => ['ar' => 'طلب تقييم من العملاء', 'en' => 'Review requests'],
                'desc' => [
                    'ar' => 'بعد ما رحلة العميل تخلص، المنصة بتبعتله رسالة تطلب منه تقييم.',
                    'en' => 'After a trip ends, the guest gets a message asking for a review.',
                ],
                'learning' => 'decision',
                'buckets' => [
                    'whatsapp' => ['ar' => 'عبر واتساب', 'en' => 'Via WhatsApp'],
                    'email' => ['ar' => 'عبر الإيميل', 'en' => 'Via email'],
                    'sms' => ['ar' => 'عبر SMS', 'en' => 'Via SMS'],
                ],
                'default_mode' => 'auto',
                'default_bucket_modes' => [],
                'handler' => 'ReviewRequestAutomationHandler',
                'requires' => null,
            ],
            'ads_optimizations' => [
                'icon' => 'megaphone',
                'name' => ['ar' => 'تحسين الإعلانات', 'en' => 'Ad optimizations'],
                'desc' => [
                    'ar' => 'إيقاف الإعلانات الضعيفة، تعديل الميزانية، الكلمات السلبية، في حدود القواعد اللي حاططها.',
                    'en' => 'Pauses weak ads, adjusts budgets and negative keywords within your guardrails.',
                ],
                'learning' => 'decision',
                'buckets' => [
                    'increase_budget' => ['ar' => 'زيادة الميزانية', 'en' => 'Budget increases'],
                    'decrease_budget' => ['ar' => 'تقليل الميزانية', 'en' => 'Budget decreases'],
                    'pause_campaign' => ['ar' => 'إيقاف حملة', 'en' => 'Pausing campaigns'],
                    'default' => ['ar' => 'باقي التعديلات', 'en' => 'Other changes'],
                ],
                'default_mode' => 'auto',
                // أمان: صرف فلوس زيادة بيستنى موافقة لحد ما العميل يقرر.
                'default_bucket_modes' => ['increase_budget' => 'approval'],
                'handler' => 'AdActionAutomationHandler',
                'requires' => 'ads',
            ],
        ];
    }

    public static function get(string $featureKey): ?array
    {
        $all = self::all();
        return $all[$featureKey] ?? null;
    }

    public static function exists(string $featureKey): bool
    {
        return self::get($featureKey) !== null;
    }

    public static function label(array $pair): string
    {
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        return $pair[$lang] ?? $pair['en'] ?? $pair['ar'] ?? '';
    }

    /** فئة التقييم حسب عدد النجوم */
    public static function reviewBucket($rating): string
    {
        $r = (float) $rating;
        if ($r >= 4) {
            return 'positive';
        }
        if ($r >= 3) {
            return 'neutral';
        }
        return 'negative';
    }

    /** فئة إصلاح الموقع من اسم الحقل/التصنيف */
    public static function websiteFixBucket(array $finding): string
    {
        $f = strtolower((string) ($finding['field_name'] ?? $finding['check_key'] ?? ''));
        $c = strtolower((string) ($finding['category'] ?? ''));
        if (preg_match('/title|meta_desc|description|h1/', $f)) {
            return 'meta';
        }
        if (preg_match('/schema|json_ld|structured/', $f . ' ' . $c)) {
            return 'structured_data';
        }
        if (preg_match('/^og_|open_graph|twitter/', $f)) {
            return 'social';
        }
        if (preg_match('/canonical|robots|hreflang|viewport|lang|sitemap|perf|speed/', $f . ' ' . $c)) {
            return 'technical';
        }
        return 'default';
    }

    public static function adBucket(string $actionType): string
    {
        $known = ['increase_budget', 'decrease_budget', 'pause_campaign'];
        return in_array($actionType, $known, true) ? $actionType : 'default';
    }
}
