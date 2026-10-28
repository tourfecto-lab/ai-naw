<?php

/**
 * Tourfecto - Auto Reply Engine
 * محرك توليد الردود التلقائية الذكية
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AutoReplyEngine
{
    /**
     * @var TourfectoAIEngine $aiEngine - محرك الذكاء الاصطناعي
     */
    private $aiEngine;

    /**
     * @var array $replyTemplates - قوالب الردود
     */
    private $replyTemplates = [];

    /**
     * @var array $fallbackReplies - ردود احتياطية
     */
    private $fallbackReplies = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->aiEngine = new TourfectoAIEngine();
        $this->loadTemplates();
        $this->loadFallbackReplies();
    }

    /**
     * توليد رد تلقائي
     * @param string $message - رسالة العميل
     * @param int $userId - معرف المستخدم
     * @param array $context - سياق المحادثة
     * @param array $botSettings - إعدادات البوت
     * @param int|null $websiteId - معرف الموقع (اختياري - يفعّل AI Chat
     *   Platform الجديد: Knowledge Base + Customer Memory + Confidence +
     *   Human Handoff عبر AIConversationEngine بدل المسار العام القديم)
     * @param int|null $conversationId - معرف المحادثة الموحدة (Unified Inbox)
     * @return string|null
     */
    public function generateReply(
        string $message,
        int $userId,
        array $context = [],
        array $botSettings = [],
        ?int $websiteId = null,
        ?int $conversationId = null
    ): ?string {
        try {
            // المسار الجديد: AI Chat Platform (Knowledge Base + Memory +
            // Confidence + Human Handoff) - يُستخدم فقط لو الرسالة مرتبطة
            // بمحادثة Unified Inbox فعلية. لو فشل لأي سبب، نكمل بالمسار
            // القديم كاحتياط (بند 24: لا انهيار عند الخطأ).
            if ($websiteId && $conversationId) {
                try {
                    $engine = new AIConversationEngine();
                    $decision = $engine->handleIncomingMessage($websiteId, $userId, $conversationId, $message);

                    // لو المحرك الجديد قرر إن الأتمتة يجب أن تتوقف (محادثة
                    // محوَّلة لموظف بالفعل، أو Stop Condition)، لا رد إطلاقًا -
                    // ولا نلجأ للمسار القديم لأن هذا قرار متعمَّد وليس خطأ.
                    if ($decision['error'] === null) {
                        return $decision['reply']; // قد تكون null عمدًا
                    }
                } catch (Exception $engineError) {
                    Logger::warning('AIConversationEngine failed, falling back to legacy reply path', [
                        'error' => $engineError->getMessage(),
                    ]);
                }
            }

            // 1. التحقق من وجود سياق سابق
            $hasContext = !empty($context);

            // 2. تحليل الرسالة
            $processed = $this->analyzeMessage($message);

            // 3. محاولة استخدام AI
            $aiReply = $this->aiEngine->generateChatReply(
                $message,
                $userId,
                $context
            );

            if ($aiReply) {
                return $this->postProcessReply($aiReply, $processed);
            }

            // 4. استخدام القوالب الذكية
            $templateReply = $this->generateFromTemplate($processed, $context);
            if ($templateReply) {
                return $templateReply;
            }

            // 5. استخدام ردود احتياطية
            return $this->getFallbackReply($processed['intent']);

        } catch (Exception $e) {
            Logger::error('Auto Reply Generation Error', [
                'message' => $e->getMessage()
            ]);
            return $this->getFallbackReply('general');
        }
    }

    /**
     * تحليل الرسالة
     * @param string $message
     * @return array
     */
    private function analyzeMessage(string $message): array
    {
        $messageProcessor = new MessageProcessor();

        return [
            'cleaned' => $messageProcessor->cleanMessage($message),
            'intent' => $messageProcessor->detectIntent($message),
            'sentiment' => $messageProcessor->analyzeSentiment($message),
            'entities' => $messageProcessor->extractEntities($message),
            'language' => $messageProcessor->detectLanguage($message)
        ];
    }

    /**
     * توليد رد من قالب
     * @param array $processed - البيانات المحللة
     * @param array $context - السياق
     * @return string|null
     */
    private function generateFromTemplate(array $processed, array $context): ?string
    {
        $intent = $processed['intent']['primary'] ?? 'general';
        $sentiment = $processed['sentiment']['label'] ?? 'neutral';

        $templates = $this->replyTemplates[$intent] ?? $this->replyTemplates['general'];
        $template = $templates[$sentiment] ?? $templates['neutral'] ?? $templates['default'] ?? null;

        if (!$template) {
            return null;
        }

        return $this->fillTemplate($template, $processed, $context);
    }

    /**
     * ملء القالب بالبيانات
     * @param string $template
     * @param array $processed
     * @param array $context
     * @return string
     */
    private function fillTemplate(string $template, array $processed, array $context): string
    {
        $placeholders = [
            '{name}' => $context['customer_name'] ?? 'عميلنا العزيز',
            '{intent}' => $processed['intent']['primary'] ?? 'الاستفسار',
            '{entity_date}' => $processed['entities']['date'] ?? '',
            '{entity_location}' => $processed['entities']['locations'][0] ?? '',
            '{entity_number}' => $processed['entities']['numbers'][0] ?? '',
            '{sentiment}' => $processed['sentiment']['label'] ?? 'محايد'
        ];

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $template
        );
    }

    /**
     * معالجة الرد بعد التوليد
     * @param string $reply
     * @param array $processed
     * @return string
     */
    private function postProcessReply(string $reply, array $processed): string
    {
        $reply = trim($reply);
        $reply = preg_replace('/\s+/', ' ', $reply);

        if (!preg_match('/[.!?…]$/', $reply)) {
            $reply .= '.';
        }

        $sentiment = $processed['sentiment']['label'] ?? 'neutral';
        $friendlyPhrases = [
            'positive' => ['نتمنى لك يوماً سعيداً!', 'شكراً لتواصلك معنا!', 'يسعدنا خدمتك!'],
            'negative' => ['نحن هنا لمساعدتك!', 'سنتواصل معك قريباً!', 'نأسف لأي إزعاج!'],
            'neutral' => ['نحن في خدمتك!', 'شكراً لتواصلك!', 'نتطلع لخدمتك!']
        ];

        $phrases = $friendlyPhrases[$sentiment] ?? $friendlyPhrases['neutral'];
        $reply .= ' ' . $phrases[array_rand($phrases)];

        return $reply;
    }

    /**
     * الحصول على رد احتياطي
     * @param string $intent
     * @return string
     */
    private function getFallbackReply(string $intent): string
    {
        $replies = $this->fallbackReplies[$intent] ?? $this->fallbackReplies['general'];
        return $replies[array_rand($replies)];
    }

    /**
     * تحميل القوالب
     */
    private function loadTemplates(): void
    {
        $this->replyTemplates = [
            'booking' => [
                'positive' => 'يسعدنا جداً أنك ترغب في الحجز معنا {name}! سيكون من دواعي سرورنا خدمتك. هل لديك تاريخ محدد تفضله؟',
                'neutral' => 'شكراً لاهتمامك بالحجز معنا {name}. نود معرفة المزيد من التفاصيل لتقديم أفضل عرض لك.',
                'negative' => 'نأسف لأي صعوبة في الحجز {name}. فريق الدعم لدينا مستعد لمساعدتك في إتمام عملية الحجز بكل سهولة.',
                'default' => 'شكراً لاهتمامك بالحجز معنا {name}! يرجى إخبارنا بالتفاصيل المطلوبة.'
            ],
            'inquiry' => [
                'positive' => 'سعيدون باهتمامك واستفسارك {name}! نحن هنا للإجابة على جميع أسئلتك.',
                'neutral' => 'شكراً على استفسارك {name}. سنزودك بجميع المعلومات التي تحتاجها.',
                'negative' => 'نأسف إذا كان هناك أي التباس {name}. دعنا نوضح لك كل التفاصيل.',
                'default' => 'شكراً لاستفسارك {name}. كيف يمكننا مساعدتك؟'
            ],
            'complaint' => [
                'positive' => 'نقدر أنك شاركتنا رأيك {name}! هذا يساعدنا على التحسين المستمر.',
                'neutral' => 'نشكرك على إبلاغنا {name}. سنتعامل مع مشكلتك بكل جدية.',
                'negative' => 'نأسف جداً لتجربتك غير المرضية {name}. نعدك بالتحقيق الفوري في مشكلتك.',
                'default' => 'نأسف لأي إزعاج {name}. فريق الدعم لدينا سيتواصل معك قريباً.'
            ],
            'pricing' => [
                'positive' => 'يسعدنا أنك مهتم بعروضنا {name}! لدينا باقات تناسب جميع الميزانيات.',
                'neutral' => 'شكراً لاستفسارك عن الأسعار {name}. سنزودك بقائمة الأسعار الكاملة.',
                'negative' => 'نأسف إذا كانت الأسعار غير مناسبة {name}. لدينا عروض خاصة قد تهمك.',
                'default' => 'شكراً لاهتمامك بمعرفة أسعارنا {name}. ما هي الخدمة التي تهمك تحديداً؟'
            ],
            'support' => [
                'positive' => 'نحن هنا لمساعدتك {name}! كيف يمكننا أن نكون عند حسن ظنك؟',
                'neutral' => 'فريق الدعم جاهز لخدمتك {name}. أخبرنا كيف يمكننا مساعدتك.',
                'negative' => 'نأسف لأي إزعاج {name}. فريق الدعم المتخصص سيتعامل مع مشكلتك فوراً.',
                'default' => 'نحن في خدمتك {name}! ما هو الدعم الذي تحتاجه؟'
            ],
            'general' => [
                'positive' => 'أهلاً بك {name}! يسعدنا تواصلك معنا. كيف يمكننا مساعدتك اليوم؟',
                'neutral' => 'مرحباً {name}! نحن هنا لخدمتك. كيف يمكننا أن نكون مفيدين لك؟',
                'negative' => 'نأسف لأي إزعاج {name}. نحن هنا لمساعدتك في أي وقت.',
                'default' => 'أهلاً وسهلاً بك {name}! كيف يمكننا خدمتك اليوم؟'
            ]
        ];
    }

    /**
     * تحميل الردود الاحتياطية
     */
    private function loadFallbackReplies(): void
    {
        $this->fallbackReplies = [
            'booking' => [
                'شكراً لتواصلك معنا بخصوص الحجز. أحد ممثلي خدمة العملاء سيتواصل معك قريباً.',
                'نقدر اهتمامك بالحجز معنا. سنقوم بتزويدك بكل المعلومات اللازمة قريباً.',
                'تم استلام طلب الحجز الخاص بك. سنتواصل معك لتأكيد التفاصيل.'
            ],
            'inquiry' => [
                'شكراً على استفسارك. سنقوم بالرد عليك في أقرب وقت ممكن.',
                'تم استلام سؤالك. فريقنا يدرس إجابتك الآن.',
                'نقدر اهتمامك. سنقدم لك إجابة شاملة قريباً.'
            ],
            'complaint' => [
                'نأسف لأي إزعاج. فريق الدعم لدينا سيتواصل معك قريباً لحل المشكلة.',
                'تم استلام شكواك. نعدك بالتعامل معها بكل جدية وسرعة.',
                'نقدر إخبارنا عن مشكلتك. سنعمل على حلها فوراً.'
            ],
            'pricing' => [
                'شكراً لاستفسارك عن الأسعار. سنزودك بقائمة الأسعار الكاملة قريباً.',
                'يسعدنا إعلامك بأسعارنا. فريق المبيعات سيتواصل معك.',
                'لدينا عروض متنوعة. سنرسل لك التفاصيل الكاملة.'
            ],
            'support' => [
                'نحن هنا لمساعدتك. كيف يمكننا أن نكون عند حسن ظنك؟',
                'فريق الدعم جاهز لخدمتك. أخبرنا كيف يمكننا مساعدتك.',
                'نحن في خدمتك دائماً. تواصل معنا في أي وقت.'
            ],
            'general' => [
                'شكراً لتواصلك معنا. كيف يمكننا مساعدتك اليوم؟',
                'أهلاً بك! نحن هنا لخدمتك. ما الذي تحتاجه؟',
                'يسعدنا التواصل معك. أخبرنا كيف يمكننا أن نكون مفيدين.'
            ]
        ];
    }
}
