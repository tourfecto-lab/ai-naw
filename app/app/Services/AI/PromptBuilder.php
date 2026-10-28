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
        $prompt .= "قم بإخراج النتيجة بصيغة JSON فقط، مع التأكد من صحة الـ JSON وصياغته بشكل علمي ودقيق.\n\n";
        $prompt .= "يجب أن يحتوي الـ JSON على الأقسام التالية:\n";
        $prompt .= "1. seo: الكلمات المفتاحية، اقتراحات العناوين، اقتراحات الميتا، فجوات المحتوى\n";
        $prompt .= "2. aeo: الإجابات المباشرة، إشارات الثقة، استراتيجية التموضع\n";
        $prompt .= "3. geo: مخطط FAQ، الأسئلة المولدة، تكامل الخرائط، اقتراحات التحسين\n";
        $prompt .= "4. score: درجة التحليل (0-100)\n";

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
        if (!empty($context)) {
            $prompt .= "سياق المحادثة السابقة:\n";
            foreach ($context as $item) {
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
