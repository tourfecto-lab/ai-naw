<?php

/**
 * Tourfecto - Prompt Builder
 * بناء الـ Prompts المخصصة لمختلف المهام
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class PromptBuilder
{
    /**
     * @var array $systemPrompts - تعليمات النظام
     */
    private $systemPrompts;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->systemPrompts = GEMINI_SYSTEM_PROMPTS;
    }

    /**
     * بناء Prompt لتحليل SEO/AEO/GEO
     * @param string $targetUrl
     * @param array $competitorUrls
     * @param string $language
     * @return string
     */
    public function buildAnalysisPrompt(
        string $targetUrl,
        array $competitorUrls,
        string $language = 'en',
        string $pageFacts = ''
    ): string {
        $systemPrompt = $this->systemPrompts['seo_analysis'];

        $competitorList = '';
        foreach ($competitorUrls as $index => $url) {
            $competitorList .= ($index + 1) . ". {$url}\n";
        }

        $prompt = "{$systemPrompt}\n\n";
        $prompt .= "المطلوب: تحليل الموقع التالي:\n";
        $prompt .= "الموقع المستهدف: {$targetUrl}\n";
        $prompt .= "المنافسون:\n{$competitorList}\n";
        $prompt .= "اللغة المستهدفة: {$language}\n\n";
        if ($pageFacts !== '') {
            // بيانات حقيقية اتجابت من الصفحات - التحليل لازم يعتمد عليها مش على التخمين
            $prompt .= "## بيانات حقيقية مستخرجة من الصفحات الآن (اعتمد عليها في كل ملاحظة واذكر الدليل منها):\n{$pageFacts}\n";
            $prompt .= "قواعد: أي ملاحظة لازم تكون مبنية على البيانات دي (مثلًا: العنوان طوله X، مفيش H1، مفيش FAQ Schema). لو بيانات صفحة ناقصة قول كده صراحة ومتخمّنش. الدرجة (score) تعكس الفجوات الفعلية اللي ظاهرة في البيانات.\n\n";
        }
        $prompt .= <<<'RULES'
## التعريفات (التزم بيها حرفيًا)
- SEO: الظهور في نتائج جوجل وبينج العادية (العناوين، الوصف، العناوين الداخلية H1/H2، الكلمات المفتاحية، الروابط الداخلية، Schema، السيو المحلي وخرائط جوجل).
- AEO (Answer Engine Optimization): إن الموقع يبقى هو الإجابة المختارة في المقتطفات المميزة، و"أسئلة يطرحها الآخرون"، والبحث الصوتي، وملخصات جوجل بالذكاء الاصطناعي (AI Overviews).
- GEO (Generative Engine Optimization): إن الشركة تتذكر ويتم الاستشهاد بموقعها في إجابات ChatGPT وGemini وPerplexity وClaude وGoogle AI Mode.
  GEO مش معناها "جغرافيا" ولا خرائط ولا إحداثيات. أي توصية عن الخرائط أو Google Business Profile أو "near me" مكانها حقل geo.map_integration بس (السيو المحلي)، ومش في geo.improvement_suggestions.

## قواعد الدقة (أهم من أي حاجة تانية)
1. كل ملاحظة لازم تعتمد على البيانات المستخرجة فوق، واذكر الدليل (مثلًا: "العنوان 72 حرف"، "مفيش H1"، "المنافس 1 عنده 2,061 كلمة").
2. ممنوع تخترع أرقام: حجم بحث، عدد تقييمات، جوايز، سنين خبرة، أسعار، نسب.
3. ممنوع تكتب ادعاءات عن الشركة كأنها حقيقة (مرشدين مرخصين، إلغاء مجاني، دعم 24/7، أفضل سعر، سنين خبرة) إلا لو مكتوبة حرفيًا في بيانات الموقع المستهدف. لو مش موجودة، اكتبها كاقتراح مشروط: "لو بتقدم إلغاء مجاني، اكتب شروطه بوضوح...".
4. الادعاءات اللي مش موجودة في بيانات الموقع ممنوعة في title_suggestions وmeta_suggestions وdirect_answers وquestions_generated.
5. لو بيانات صفحة ناقصة أو الصفحة ماتفتحتش، قول كده صراحة ومتخمّنش.

## Schema المناسب لشركات السياحة
- الشركة: TravelAgency أو TourOperator (أنواع فرعية من LocalBusiness) + Organization مع sameAs لحسابات TripAdvisor وGoogle Business والسوشيال.
- صفحة الرحلة: TouristTrip (itinerary, touristType) + Offer (price, priceCurrency, availability) + AggregateRating بس لو التقييمات ظاهرة فعلًا على الصفحة.
- صفحات الأسئلة: FAQPage. والتنقل: BreadcrumbList.
- ممنوع تقترح TouristInformationCenter أو TouristAttraction لشركة بتبيع رحلات.

## مواصفات كل حقل
- seo.keywords: 8–12 عبارة بحث حقيقية بلغة السوق، بترتيب الأولوية التجارية، من غير اسم البراند. العبارة نفسها بس، من غير تعليقات.
- seo.title_suggestions: 5 عناوين، كل عنوان من 50 لـ 60 حرف، فيه الكلمة الأساسية في الأول، ومختلف عن التاني.
- seo.meta_suggestions: 5 أوصاف، كل وصف من 140 لـ 155 حرف، فيه الكلمة الأساسية، وميزة حقيقية من بيانات الموقع، ودعوة لإجراء (احجز، شوف الأسعار...).
- seo.content_gaps: 5–8 فجوات. كل فجوة بالشكل ده: "الفجوة — الدليل من البيانات — المطلوب تنفيذه بالظبط".
- aeo.direct_answers: 5–8. كل عنصر "سؤال زي ما المسافر بيكتبه — إجابة مباشرة من 40 لـ 60 كلمة" مبنية على معلومات الموقع بس.
- aeo.trust_signals: 5 إشارات ثقة ناقصة. كل واحدة: إيه الناقص، وفين يتحط في الموقع، ومثال للنص.
- aeo.positioning_strategy: فقرة واحدة عن تميّز الشركة قدام المنافسين، مبنية على الفروق الظاهرة في البيانات.
- geo.faq_schema: كائن FAQPage صالح، من 5 أسئلة وإجابات مبنية على معلومات الموقع بس.
- geo.questions_generated: 6–8 أسئلة بيكتبها المسافرين فعلًا في ChatGPT أو Perplexity (من غير اسم البراند)، وكل سؤال معاه المحتوى اللي لازم الموقع ينشره عشان يتذكر في الإجابة. الشكل: "السؤال — المحتوى المطلوب نشره".
- geo.map_integration: 3–5 توصيات للسيو المحلي (Google Business Profile، توحيد الاسم والعنوان والتليفون NAP، الخرائط).
- geo.improvement_suggestions: خطوات GEO مرقمة في نص واحد (1) 2) 3)...) من الأولويات دي حسب البيانات: وضوح هوية الشركة وتوحيد اسمها، sameAs، حقائق قابلة للاقتباس (جداول أسعار ومدد وما يشمله السعر)، ملف llms.txt، السماح لروبوتات الذكاء الاصطناعي في robots.txt (GPTBot، ClaudeBot، PerplexityBot، Google-Extended)، الظهور في مواقع خارجية موثوقة، تواريخ التحديث، صفحات المرشدين والخبراء.
- score_breakdown: درجات جزئية: technical_onpage من 30، content_depth من 25، aeo من 20، geo من 15، trust من 10.
- score: مجموع score_breakdown بالظبط (من 0 لـ 100).

RULES;
        $prompt .= "\nاكتب النتيجة JSON صالح فقط، من غير أي نص قبله أو بعده، وبأسماء الحقول دي بالظبط (ممنوع تغيير أي اسم). كل القوائم نصوص عادية:\n";
        $prompt .= <<<'JSONSPEC'
{
  "seo": {
    "keywords": ["..."],
    "title_suggestions": ["..."],
    "meta_suggestions": ["..."],
    "content_gaps": ["..."]
  },
  "aeo": {
    "direct_answers": ["..."],
    "trust_signals": ["..."],
    "positioning_strategy": "..."
  },
  "geo": {
    "faq_schema": {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": []},
    "questions_generated": ["..."],
    "map_integration": ["..."],
    "improvement_suggestions": "..."
  },
  "score_breakdown": {"technical_onpage": 0, "content_depth": 0, "aeo": 0, "geo": 0, "trust": 0},
  "score": 0
}

JSONSPEC;
        $prompt .= "اكتب كل القيم بلغة {$language}.\n";

        return $prompt;
    }

    /**
     * بناء Prompt لردود المراجعات
     * @param string $reviewText
     * @param array $sentiment
     * @param string $platform
     * @return string
     */
    public function buildReviewReplyPrompt(
        string $reviewText,
        array $sentiment,
        string $platform
    ): string {
        $systemPrompt = $this->systemPrompts['reply_generation'];

        $sentimentText = $sentiment['label'] === 'positive' ? 'إيجابية' :
                         ($sentiment['label'] === 'negative' ? 'سلبية' : 'محايدة');

        $prompt = "{$systemPrompt}\n\n";
        $prompt .= "قم بصياغة رد احترافي ولبق على المراجعة التالية من منصة {$platform}.\n\n";
        $prompt .= "المراجعة: {$reviewText}\n\n";
        $prompt .= "نبرة المراجعة: {$sentimentText} (درجة الثقة: " . ($sentiment['confidence'] ?? 0.7) . ")\n\n";
        $prompt .= "المطلوب:\n";
        $prompt .= "1. Reply in the customer review language, regardless of dashboard language\n";
        $prompt .= "2. شكر العميل على تقييمه\n";
        $prompt .= "3. إذا كانت المراجعة سلبية، الاعتذار والعرض لحل المشكلة\n";
        $prompt .= "4. إذا كانت إيجابية، التعبير عن السعادة\n";
        $prompt .= "5. اذكر اسم الرحلة أو الخدمة لو العميل ذكرها، بشكل طبيعي ومن غير حشو كلمات مفتاحية\n";
        $prompt .= "6. دعوة العميل للعودة أو تجربة خدمات أخرى\n";
        $prompt .= "7. الرد قصير ومباشر: من 40 لـ 120 كلمة، ومن غير ما تخترع عروض أو أرقام تليفون أو معلومات مش موجودة\n";
        $prompt .= "8. استخدم أسلوباً مهنياً دافئاً\n\n";
        $prompt .= "الرد:";

        return $prompt . ContentLanguageService::instruction(preg_match('/\p{Arabic}/u',$reviewText)?'ar':'en');
    }

    /**
     * بناء Prompt لردود الشات
     * @param string $message
     * @param array $context
     * @return string
     */
    public function buildChatReplyPrompt(string $message, array $context = []): string
    {
        $systemPrompt = $this->systemPrompts['chat_assistant'];

        $prompt = "{$systemPrompt}\n\n";
        $prompt .= "قم بالرد على استفسار العميل التالي:\n\n";

        // إضافة سياق المحادثة إذا وجد
        // السياق ممكن يكون تاريخ محادثة (role/message) أو تحليل الرسالة من MessageProcessor (intent/entities...) -
        // كان بيعامل الاتنين كتاريخ فيقع بـFatal ومفيش رد يتبعت
        $history = array_filter(array_is_list($context) ? $context : (array) ($context['history'] ?? []), fn($i) => is_array($i) && isset($i['role'], $i['message']));
        if ($history) {
            $prompt .= "سياق المحادثة السابقة:\n";
            foreach ($history as $item) {
                $prompt .= "- {$item['role']}: {$item['message']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "العميل: {$message}\n\n";
        $prompt .= "التعليمات:\n";
        $prompt .= "1. Reply in the customer message language, regardless of dashboard language\n";
        $prompt .= "2. كن مهنياً ودوداً ومفيداً\n";
        $prompt .= "3. قدم معلومات سياحية مفيدة واقتراحات\n";
        $prompt .= "4. إذا طلب العميل حجزاً، اطلب معلومات إضافية (عدد الأشخاص، التاريخ، الوجهة)\n";
        $prompt .= "5. إذا كان السؤال خارج نطاق السياحة، اعتذر بلطف ووجّه للاستفسارات الأخرى\n";
        $prompt .= "6. لا تذكر أنك روبوت أو ذكاء اصطناعي\n";
        $prompt .= "7. الرد لا يتجاوز 200 كلمة\n\n";
        $prompt .= "الرد:";

        return $prompt . ContentLanguageService::instruction(preg_match('/\p{Arabic}/u',$message)?'ar':'en');
    }

    /**
     * بناء Prompt لتحليل المشاعر
     * @param string $text
     * @return string
     */
    public function buildSentimentAnalysisPrompt(string $text): string
    {
        $systemPrompt = $this->systemPrompts['sentiment_analysis'];

        $prompt = "{$systemPrompt}\n\n";
        $prompt .= "حلل المشاعر في النص التالي وحدد إذا كان إيجابياً أو محايداً أو سلبياً، مع إعطاء درجة ثقة من 0 إلى 1.\n\n";
        $prompt .= "النص: {$text}\n\n";
        $prompt .= "قم بإخراج النتيجة بصيغة JSON بالشكل التالي:\n";
        $prompt .= '{"label": "positive|neutral|negative", "score": 0.85, "confidence": 0.92}';

        return $prompt;
    }

    /**
     * بناء Prompt للترجمة
     * @param string $text
     * @param string $targetLanguage
     * @return string
     */
    public function buildTranslationPrompt(string $text, string $targetLanguage): string
    {
        $systemPrompt = $this->systemPrompts['translation'];

        $languageMap = [
            'ar' => 'العربية',
            'en' => 'الإنجليزية',
            'fr' => 'الفرنسية',
            'es' => 'الإسبانية',
            'de' => 'الألمانية',
            'it' => 'الإيطالية',
            'ru' => 'الروسية',
            'zh' => 'الصينية',
            'ja' => 'اليابانية'
        ];

        $languageName = $languageMap[$targetLanguage] ?? $targetLanguage;

        $prompt = "{$systemPrompt}\n\n";
        $prompt .= "قم بترجمة النص التالي إلى اللغة {$languageName}:\n\n";
        $prompt .= "النص الأصلي: {$text}\n\n";
        $prompt .= "الترجمة:";

        return $prompt;
    }

    /**
     * بناء Prompt لتوليد أسئلة FAQ
     * @param string $topic
     * @param int $count
     * @param string $language
     * @return string
     */
    public function buildFAQPrompt(string $topic, int $count = 10, string $language = 'en'): string
    {
        $prompt = "قم بتوليد {$count} سؤال شائع مع إجاباتها في مجال السياحة والسفر حول الموضوع التالي:\n\n";
        $prompt .= "الموضوع: {$topic}\n\n";
        $prompt .= "المطلوب:\n";
        $prompt .= "1. الأسئلة باللغة {$language}\n";
        $prompt .= "2. إجابات واضحة ومفيدة\n";
        $prompt .= "3. تغطية جوانب مختلفة من الموضوع\n";
        $prompt .= "4. صياغة احترافية\n\n";
        $prompt .= "قم بإخراج النتيجة بصيغة JSON بالشكل التالي:\n";
        $prompt .= '[{"question": "...", "answer": "..."}]';

        return $prompt;
    }

    /**
     * بناء Prompt لتحليل الكلمات المفتاحية
     * @param string $url
     * @param string $topic
     * @param string $language
     * @return string
     */
    public function buildKeywordAnalysisPrompt(string $url, string $topic, string $language = 'en'): string
    {
        $prompt = "قم بتحليل الكلمات المفتاحية المناسبة للموقع التالي في مجال السياحة:\n\n";
        $prompt .= "الموقع: {$url}\n";
        $prompt .= "الموضوع: {$topic}\n";
        $prompt .= "اللغة: {$language}\n\n";
        $prompt .= "المطلوب:\n";
        $prompt .= "1. قائمة بالكلمات المفتاحية الرئيسية (10-15 كلمة)\n";
        $prompt .= "2. قائمة بالكلمات المفتاحية الطويلة (Long-tail) (5-10 كلمات)\n";
        $prompt .= "3. تقييم صعوبة الكلمات\n";
        $prompt .= "4. اقتراحات لتحسين المحتوى\n\n";
        $prompt .= "قم بإخراج النتيجة بصيغة JSON.";

        return $prompt;
    }

    /**
     * بناء Prompt لتحليل المنافسين
     * @param string $targetUrl
     * @param array $competitorUrls
     * @param string $language
     * @return string
     */
    public function buildCompetitorAnalysisPrompt(
        string $targetUrl,
        array $competitorUrls,
        string $language = 'en'
    ): string {
        $competitorList = '';
        foreach ($competitorUrls as $index => $url) {
            $competitorList .= ($index + 1) . ". {$url}\n";
        }

        $prompt = "قم بتحليل المنافسين للموقع التالي في مجال السياحة:\n\n";
        $prompt .= "الموقع المستهدف: {$targetUrl}\n";
        $prompt .= "المنافسون:\n{$competitorList}\n";
        $prompt .= "اللغة: {$language}\n\n";
        $prompt .= "المطلوب:\n";
        $prompt .= "1. نقاط قوة كل منافس\n";
        $prompt .= "2. نقاط ضعف كل منافس\n";
        $prompt .= "3. الفرص المتاحة للموقع المستهدف\n";
        $prompt .= "4. التهديدات من المنافسين\n";
        $prompt .= "5. استراتيجية مقترحة للتفوق على المنافسين\n\n";
        $prompt .= "قم بإخراج النتيجة بصيغة JSON.";

        return $prompt;
    }

    /**
     * الحصول على نظام Prompt مخصص
     * @param string $type
     * @return string|null
     */
    public function getSystemPrompt(string $type): ?string
    {
        return $this->systemPrompts[$type] ?? null;
    }

    /**
     * إضافة نظام Prompt مخصص
     * @param string $type
     * @param string $prompt
     */
    public function addSystemPrompt(string $type, string $prompt): void
    {
        $this->systemPrompts[$type] = $prompt;
    }
}
