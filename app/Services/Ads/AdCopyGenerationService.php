<?php

/**
 * Tourfecto - Enterprise Ad Copy & Campaign Brief Generation Service
 * @version 3.0.0
 *
 * المزايا التنافسية:
 * 1. توليد نسخ إعلانية بزوايا نفسية وتسويقية متمايزة (Value / Social Proof / Urgency) لنجاح اختبارات A/B.
 * 2. فرض صارم لحدود الأحرف القياسية (Meta & Google Ads Character Limits) بالكود.
 * 3. فاحص سياسات إعلانية (Ad Policy Linter) محدث لسياسات Meta/Google لعام 2026.
 * 4. سياق حقيقي للنشاط (ملف النشاط + تقييمات العملاء + صفحة الهبوط + المنافسين + الإعلانات الناجحة).
 * 5. مراجع جودة تاني (AI Reviewer) بيقيّم كل نسخة ويعيد كتابة الضعيف قبل ما العميل يشوفه.
 * 6. أصول Google منفصلة: 15 عنوان ≤30 حرف + 4 أوصاف ≤90 + Sitelinks حقيقية + Callouts + Snippets.
 */
class AdCopyGenerationService
{
    /** @var GeminiClient */
    private $ai;
    private string $contentLanguage = 'en';
    private ?AdContextBuilder $contextBuilder;

    /** أقل درجة جودة مقبولة من المراجع - أي نسخة أقل بتتعاد كتابتها */
    private const MIN_QUALITY_SCORE = 8;

    private const HEADLINE_RECOMMENDED = 27;
    private const HEADLINE_MAX = 40;
    private const DESCRIPTION_RECOMMENDED = 27;
    private const DESCRIPTION_MAX = 30;
    private const PRIMARY_TEXT_RECOMMENDED = 125;
    private const PRIMARY_TEXT_MAX = 220;

    /** عبارات شائعة تسبب رفض أو تقييد الإعلان من مراجعة Meta/Google */
    private const RISKY_PHRASES = [
        'مضمون 100%', 'مضمون100%', 'نتيجة مضمونة', 'نتائج مضمونة',
        'اربح فلوس بسرعة', 'اربح فلوس بسهولة', 'الأرخص في مصر', 'الأرخص على الإطلاق',
        'الأفضل في العالم', 'مفيش زيه', 'اضغط هنا فورًا', 'فرصة العمر', 'افرصة العمر',
        'كل يوم فلوس', 'مليونير', 'اكسب فلوس وانت نايم',
        '100% guaranteed', 'get rich quick', 'click here now', 'best in the world',
    ];

    public const OBJECTIVES = [
        'leads' => 'حجوزات واستفسارات (Leads)',
        'sales' => 'مبيعات وحجوزات مدفوعة على الموقع',
        'whatsapp' => 'محادثات واتساب مباشرة',
        'traffic' => 'زيارات للموقع',
        'engagement' => 'تفاعل ومتابعين على السوشيال ميديا',
        'awareness' => 'انتشار وشهرة العلامة التجارية',
        'calls' => 'مكالمات هاتفية مباشرة',
    ];

    private const AR_CTAS = ['احجز الآن', 'تواصل معنا', 'اعرف أكتر', 'اتصل الآن', 'تسوق الآن', 'سجّل الآن', 'راسلنا واتساب'];

    private const ALLOWED_CTAS = ['Book Now', 'Contact Us', 'Learn More', 'Call Now', 'Shop Now', 'Sign Up', 'Message Us'];

    public function __construct(?GeminiClient $ai = null, ?AdContextBuilder $contextBuilder = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->contextBuilder = $contextBuilder;
    }

    /** سياق النشاط الحقيقي - أي فشل هنا مش بيوقف التوليد (بنكمّل من غير سياق) */
    private function businessContext(int $userId, ?int $websiteId, ?AdCampaign $campaign = null, ?string $landingUrl = null): string
    {
        try {
            $builder = $this->contextBuilder ?? new AdContextBuilder();
            return (string) ($builder->build($userId, $websiteId, $campaign, $landingUrl)['text'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function allowedCtas(string $language = 'en'): array
    {
        return $language === 'ar' ? self::AR_CTAS : self::ALLOWED_CTAS;
    }

    /** @return AdCopy[] */
    public function generateCopies(AdCampaign $campaign, int $count = 3): array
    {
        $this->contentLanguage = ContentLanguageService::forUser(
            (int) $campaign->getAttribute('user_id'),
            (int) $campaign->getAttribute('website_id') ?: null
        );
        $product = $campaign->getAttribute('objective') . ' - ' . $campaign->getAttribute('name');
        if ($campaign->getAttribute('product_or_service')) {
            $product .= ' (' . $campaign->getAttribute('product_or_service') . ')';
        }

        $context = $this->businessContext(
            (int) $campaign->getAttribute('user_id'),
            (int) $campaign->getAttribute('website_id') ?: null,
            $campaign
        );
        $prompt = $this->buildCopiesOnlyPrompt($product, $count, $context, (string) $campaign->getAttribute('target_audience_brief'));

        $response = $this->ai->generateContent($prompt, [
            'task' => 'ad_copy',
            'user_id' => (int) $campaign->getAttribute('user_id'),
            'website_id' => (int) $campaign->getAttribute('website_id'),
            'output_language' => $this->contentLanguage,
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
        ]);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['copies'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي');
        }

        $reviewed = $this->reviewCopies(array_slice($parsed['copies'], 0, $count), $product, $context, [
            'user_id' => (int) $campaign->getAttribute('user_id'),
            'website_id' => (int) $campaign->getAttribute('website_id'),
        ]);

        $saved = [];
        $labels = ['A', 'B', 'C', 'D', 'E'];
        foreach ($reviewed as $i => $copy) {
            $safeCopy = $this->enforceLimits($copy);
            $model = new AdCopy([
                'campaign_id' => (int) $campaign->getAttribute('id'),
                'headline' => $safeCopy['headline'],
                'description' => $safeCopy['description'],
                'primary_text' => $safeCopy['primary_text'],
                'call_to_action' => $safeCopy['call_to_action'],
                'variant_label' => $labels[$i] ?? (string) ($i + 1),
                'ai_generated' => 1,
                'status' => 'pending_review',
            ]);
            $model->save();
            $saved[] = $model;
        }

        ActivityLog::record('ads', 'ad_copies.generated', [
            'subject_type' => 'ad_campaigns',
            'subject_id' => (int) $campaign->getAttribute('id'),
            'meta' => ['count' => count($saved)],
        ]);

        return $saved;
    }

    /**
     * الويزارد الاحترافي لتوليد حزمة حملة إعلانية كاملة بضغطة زر.
     */
    public function generateCampaignBrief(string $goalDescription, string $objectiveKey, ?float $dailyBudget = null, ?int $userId = null, ?int $websiteId = null): array
    {
        $this->contentLanguage = ContentLanguageService::forUser($userId ?? (int) ($_SESSION['user_id'] ?? 0), $websiteId);
        $objectiveLabel = self::OBJECTIVES[$objectiveKey] ?? $objectiveKey;
        $context = $this->businessContext($userId ?? (int) ($_SESSION['user_id'] ?? 0), $websiteId);
        $prompt = $this->buildFullBriefPrompt($goalDescription, $objectiveLabel, $dailyBudget, $context);

        $response = $this->ai->generateContent($prompt, [
            'task' => 'ad_copy',
            'user_id' => $userId ?? (int) ($_SESSION['user_id'] ?? 0),
            'website_id' => $websiteId,
            'output_language' => $this->contentLanguage,
            'maxOutputTokens' => 8192,
            'responseMimeType' => 'application/json',
        ]);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['copies']) || empty($parsed['campaign_name'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي - جرّب تاني بوصف أوضح شوية');
        }

        $audience = is_array($parsed['target_audience'] ?? null) ? $parsed['target_audience'] : [];
        $budgetRec = is_array($parsed['budget_recommendation'] ?? null) ? $parsed['budget_recommendation'] : [];

        $reviewed = $this->reviewCopies(array_slice($parsed['copies'], 0, 3), $goalDescription, $context, [
            'user_id' => $userId ?? (int) ($_SESSION['user_id'] ?? 0),
            'website_id' => $websiteId,
        ]);

        $labels = ['A', 'B', 'C', 'D', 'E'];
        $copies = [];
        foreach ($reviewed as $i => $copy) {
            $safeCopy = $this->enforceLimits($copy);
            $copies[] = array_merge($safeCopy, [
                'variant_label' => $labels[$i] ?? (string) ($i + 1),
                'quality_score' => isset($copy['quality_score']) ? (int) $copy['quality_score'] : null,
                'angle' => mb_substr(trim((string) ($copy['angle'] ?? '')), 0, 120),
                'char_status' => [
                    'headline' => $this->charStatus(mb_strlen($safeCopy['headline']), self::HEADLINE_RECOMMENDED, self::HEADLINE_MAX),
                    'description' => $this->charStatus(mb_strlen($safeCopy['description']), self::DESCRIPTION_RECOMMENDED, self::DESCRIPTION_MAX),
                    'primary_text' => $this->charStatus(mb_strlen($safeCopy['primary_text']), self::PRIMARY_TEXT_RECOMMENDED, self::PRIMARY_TEXT_MAX),
                ],
                'char_counts' => [
                    'headline' => mb_strlen($safeCopy['headline']),
                    'description' => mb_strlen($safeCopy['description']),
                    'primary_text' => mb_strlen($safeCopy['primary_text']),
                ],
                'policy_warnings' => array_values(array_unique(array_merge(
                    $this->lintPolicy($safeCopy['headline']),
                    $this->lintPolicy($safeCopy['description']),
                    $this->lintPolicy($safeCopy['primary_text'])
                ))),
            ]);
        }

        return [
            'campaign_name' => mb_substr(trim((string) $parsed['campaign_name']), 0, 255),
            'objective' => $objectiveKey,
            'product_or_service' => mb_substr($goalDescription, 0, 2000),
            'target_audience_brief' => trim((string) ($audience['summary'] ?? '')),
            'audience' => [
                'age_min' => $this->clampAge($audience['age_min'] ?? 18),
                'age_max' => $this->clampAge($audience['age_max'] ?? 65),
                'genders' => in_array($audience['genders'] ?? 'all', ['all', 'male', 'female'], true) ? ($audience['genders'] ?? 'all') : 'all',
                'locations' => $this->cleanStringList($audience['locations'] ?? []),
                'interests' => $this->cleanStringList($audience['interests'] ?? []),
            ],
            'budget_recommendation' => [
                'recommended_daily_budget' => is_numeric($budgetRec['recommended_daily_budget'] ?? null)
                    ? round((float) $budgetRec['recommended_daily_budget'], 2)
                    : ($dailyBudget ?? 10.0),
                'bid_strategy' => trim((string) ($budgetRec['bid_strategy'] ?? '')),
                'reasoning' => trim((string) ($budgetRec['reasoning'] ?? '')),
            ],
            'copies' => $copies,
            'keywords' => $this->cleanKeywordList($parsed['keywords'] ?? []),
        ];
    }

    private function buildCopiesOnlyPrompt(string $product, int $count, string $context = '', string $audienceBrief = ''): string
    {
        $contextBlock = $context !== '' ? "\nمعلومات حقيقية عن النشاط (اعتمد عليها في كل ادعاء):\n{$context}\n" : '';
        $audienceLine = trim($audienceBrief) !== '' ? "الجمهور المستهدف: {$audienceBrief}\n" : '';
        return <<<PROMPT
انت كاتب إعلانات (Direct Response Copywriter) متخصص في السفر والسياحة، شغلك بيتقاس بنسبة الحجز مش بالكلام الحلو.
اكتب {$count} نسخ إعلانية لحملة عن: "{$product}".
{$audienceLine}{$contextBlock}
قواعد الكتابة الاحترافية:
- أول 5-7 كلمات في primary_text هي الـHook: لازم توقف التمرير (سؤال للجمهور، صورة ذهنية للتجربة، أو رقم/ميزة محددة من المعلومات فوق).
- كل نسخة بزاوية مختلفة فعلًا:
  1) القيمة والمنفعة المحددة (إيه اللي هياخده بالظبط: المدة، اللي متضمن، المميز).
  2) الدليل الاجتماعي والثقة (من التقييمات الحقيقية لو موجودة - من غير اختراع أرقام).
  3) دعوة هادئة للحجز (موسم/أماكن محدودة بس لو ده حقيقي، غير كده راحة البال وسهولة الحجز).
- اتكلم عن تجربة العميل (هو هيحس بإيه/هيشوف إيه) مش عن الشركة.
- استخدم تفاصيل حقيقية من المعلومات فوق (أماكن، مدة، مميزات). ممنوع تخترع أسعار أو خصومات أو تقييمات أو أرقام مش مذكورة.
- headline: أقصى {$this->headlineMax()} حرف (الأفضل تحت {$this->headlineRecommended()}) - واضح ومحدد مش عام.
- description: أقصى {$this->descriptionMax()} حرف (الأفضل تحت {$this->descriptionRecommended()}).
- primary_text: أقصى {$this->primaryTextMax()} حرف (الأفضل تحت {$this->primaryTextRecommended()}) - جملتين أو تلاتة قصيرين، ممكن إيموجي واحد مناسب بالكتير.
- call_to_action: واحدة بالظبط من: {$this->allowedCtasList()}
- ممنوع: وعود مضمونة، "الأرخص/الأفضل في العالم"، كابيتال كامل، علامات تعجب متكررة، كلام يخاطب صفات شخصية حساسة ("انت تعبان؟"، "انت مطلّق؟").
رجّع JSON فقط: {"copies": [{"angle":"اسم الزاوية","headline":"...","description":"...","primary_text":"...","call_to_action":"..."}]}
PROMPT;
    }

    private function buildFullBriefPrompt(string $goalDescription, string $objectiveLabel, ?float $dailyBudget, string $context = ''): string
    {
        $budgetLine = $dailyBudget
            ? "الميزانية اليومية اللي العميل حاطط في دماغه: {$dailyBudget} دولار (اقترح تعديل عليها لو شايف إنها مش كافية أو زيادة عن اللازم، واشرح ليه)."
            : "العميل لسه محددش ميزانية - اقترح رقم واقعي بناءً على وصف العرض.";
        $contextBlock = $context !== '' ? "\nمعلومات حقيقية عن النشاط (اعتمد عليها في كل ادعاء، وممنوع تخترع غيرها):\n{$context}\n" : '';

        return <<<PROMPT
انت مدير حملات إعلانية (Performance Marketer) بخبرة 10 سنين في السياحة، بتجهّز حملة كاملة جاهزة للإطلاق لصاحب عمل سياحي، احترافية ومتوافقة مع سياسات Meta وGoogle من غير أي سبب للرفض.

وصف العميل لعرضه: "{$goalDescription}"
الهدف من الحملة: {$objectiveLabel}
{$budgetLine}
{$contextBlock}
جهّز حزمة حملة كاملة بلغة جمهور الحملة المحددة في سياسة المخرجات:
1) الجمهور: حدد الدول الأنسب فعلًا للعرض ده (أسواق المصدر اللي بيجي منها السياح للعرض ده، مش بلد الشركة بالضرورة لو العرض للأجانب)، والسن الواقعي، و5-8 اهتمامات بأسماء اهتمامات Meta الإنجليزية المعروفة (زي "Travel", "Adventure travel", "Luxury travel", "Egypt", "Scuba diving").
2) الميزانية: رقم واقعي يكفي إن خوارزمية المنصة تتعلم (على الأقل ما يكفي لـ50 ضغطة في الأسبوع).
3) 3 نسخ إعلانية بزوايا مختلفة فعلًا (القيمة المحددة / الدليل الاجتماعي من التقييمات الحقيقية / دعوة هادئة للحجز)، أول كلمات في primary_text Hook يوقف التمرير، وكل تفصيلة من المعلومات الحقيقية فوق.
4) 10-15 كلمة مفتاحية لجوجل بنية حجز واضحة (فيها "حجز/سعر/رحلة/tour/booking/price" أو اسم المكان + نوع الخدمة)، مع 3-5 كلمات سلبية.

حدود صارمة (أي حاجة أطول هتتقطع):
- headline: أقصى {$this->headlineMax()} حرف (الأفضل تحت {$this->headlineRecommended()})
- description: أقصى {$this->descriptionMax()} حرف (الأفضل تحت {$this->descriptionRecommended()})
- primary_text: أقصى {$this->primaryTextMax()} حرف (الأفضل تحت {$this->primaryTextRecommended()})
- call_to_action: واحدة بالظبط من: {$this->allowedCtasList()}
ممنوع تمامًا: وعود مضمونة، "الأرخص في مصر"/"الأفضل في العالم"، كابيتال كامل، علامات تعجب متكررة، أسعار أو خصومات أو أرقام مش موجودة في المعلومات فوق.

رجّع JSON فقط بالشكل ده بالظبط (من غير أي نص أو شرح خارج الـ JSON):
{
  "campaign_name": "اسم حملة قصير احترافي",
  "target_audience": {
    "age_min": 25, "age_max": 65, "genders": "all",
    "locations": ["Germany", "United Kingdom"], "interests": ["Travel", "..."],
    "summary": "وصف الجمهور المستهدف في جملة أو اتنين"
  },
  "budget_recommendation": {
    "recommended_daily_budget": 15,
    "bid_strategy": "استراتيجية مزايدة مقترحة بجملة قصيرة",
    "reasoning": "سبب مختصر للرقم ده"
  },
  "copies": [
    {"angle":"...","headline":"...","description":"...","primary_text":"...","call_to_action":"..."},
    {"angle":"...","headline":"...","description":"...","primary_text":"...","call_to_action":"..."},
    {"angle":"...","headline":"...","description":"...","primary_text":"...","call_to_action":"..."}
  ],
  "keywords": [
    {"keyword":"...","match_type":"phrase"},
    {"keyword":"...","match_type":"exact"},
    {"keyword":"...","match_type":"negative"}
  ]
}
PROMPT;
    }

    /**
     * المراجع التاني: مدير إبداعي بيقيّم كل نسخة من 10 ويعيد كتابة أي نسخة أقل من 8.
     * لو المراجعة فشلت لأي سبب بنرجّع النسخ الأصلية زي ما هي (المراجعة تحسين مش شرط).
     */
    private function reviewCopies(array $copies, string $product, string $context, array $aiOptions): array
    {
        $copies = array_values(array_filter($copies, 'is_array'));
        if (empty($copies)) {
            return $copies;
        }
        $json = json_encode(['copies' => $copies], JSON_UNESCAPED_UNICODE);
        $contextBlock = $context !== '' ? "\nالمعلومات الحقيقية المتاحة عن النشاط:\n" . mb_substr($context, 0, 3500) . "\n" : '';
        $prompt = <<<PROMPT
انت مدير إبداعي (Creative Director) صارم بتراجع إعلانات سياحية قبل ما تتنشر بفلوس العميل. العرض: "{$product}".
{$contextBlock}
النسخ المقترحة:
{$json}

قيّم كل نسخة من 10 على: قوة الـHook في أول سطر، التحديد (تفاصيل حقيقية مش كلام عام)، وضوح المنفعة للعميل، توافق الـCTA مع الهدف، الالتزام بسياسات Meta/Google، وعدم وجود أي ادعاء مش موجود في المعلومات الحقيقية.
- أي نسخة أقل من {$this->minQuality()}: أعد كتابتها بنفس الزاوية لحد ما توصل {$this->minQuality()}+.
- أي رقم/سعر/خصم/تقييم مش موجود في المعلومات الحقيقية: احذفه.
- حافظ على نفس اللغة ونفس الحدود: headline ≤ {$this->headlineMax()}، description ≤ {$this->descriptionMax()}، primary_text ≤ {$this->primaryTextMax()} حرف، والـcall_to_action من: {$this->allowedCtasList()}.
رجّع JSON فقط بنفس الترتيب: {"copies":[{"angle":"...","headline":"...","description":"...","primary_text":"...","call_to_action":"...","quality_score":9,"issues_fixed":["..."]}]}
PROMPT;
        try {
            $response = $this->ai->generateContent($prompt, array_merge($aiOptions, [
                'task' => 'ad_copy',
                'output_language' => $this->contentLanguage,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
            ]));
            if (!($response['success'] ?? false)) {
                return $copies;
            }
            $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
            $improved = is_array($parsed['copies'] ?? null) ? array_values(array_filter($parsed['copies'], 'is_array')) : [];
            if (count($improved) !== count($copies)) {
                return $copies;
            }
            foreach ($improved as $i => $copy) {
                if (trim((string) ($copy['headline'] ?? '')) === '' || trim((string) ($copy['primary_text'] ?? '')) === '') {
                    $improved[$i] = $copies[$i];
                }
            }
            return $improved;
        } catch (Throwable $e) {
            return $copies;
        }
    }

    /**
     * أصول حملة البحث على Google - مكتوبة مخصوص لحدود Google بدل قص نصوص Meta:
     * 15 عنوان (≤30) + 4 أوصاف (≤90) + مسارين للرابط + Sitelinks بروابط حقيقية من
     * الموقع + Callouts + Structured snippet. النتيجة بتتحفظ على الحملة (search_assets_json).
     */
    public function generateSearchAssets(AdCampaign $campaign, array $keywords = [], ?string $destinationUrl = null): array
    {
        $userId = (int) $campaign->getAttribute('user_id');
        $websiteId = (int) $campaign->getAttribute('website_id') ?: null;
        $this->contentLanguage = ContentLanguageService::forUser($userId, $websiteId);
        $destinationUrl = $destinationUrl ?: (string) $campaign->getAttribute('landing_page_url');

        $builder = $this->contextBuilder ?? new AdContextBuilder();
        $context = '';
        $links = [];
        try {
            $context = (string) ($builder->build($userId, $websiteId, $campaign, $destinationUrl)['text'] ?? '');
            $links = $destinationUrl !== '' ? $builder->internalLinks($destinationUrl) : [];
        } catch (Throwable $e) {
            // نكمّل بالمتاح
        }

        $product = trim($campaign->getAttribute('name') . ' - ' . (string) $campaign->getAttribute('product_or_service'));
        $kwList = implode(', ', array_slice(array_filter(array_map(fn ($k) => is_array($k) ? (string) ($k['keyword'] ?? '') : (string) $k, $keywords)), 0, 25));
        $linksList = $links ? implode("\n", array_map(fn ($l) => '- ' . $l['text'] . ' => ' . $l['url'], array_slice($links, 0, 20))) : '(مفيش روابط داخلية متاحة - رجّع sitelinks فاضية)';
        $headers = implode(', ', GoogleAdsAPI::SNIPPET_HEADERS);
        $contextBlock = $context !== '' ? "\nمعلومات حقيقية عن النشاط:\n" . mb_substr($context, 0, 4000) . "\n" : '';

        $prompt = <<<PROMPT
انت خبير Google Ads معتمد، بتكتب أصول إعلان بحث متجاوب (Responsive Search Ad) لعرض سياحي: "{$product}".
الكلمات المفتاحية: {$kwList}
{$contextBlock}
روابط حقيقية من الموقع (للـSitelinks استخدم منها بس):
{$linksList}

المطلوب (الحدود دي صارمة بالحرف - أي نص أطول هيتشال):
- headlines: 15 عنوان كل واحد ≤ 30 حرف. نوّعهم: 3-4 فيهم الكلمة المفتاحية الأساسية، 3 مميزات محددة، 2 دليل ثقة (من التقييمات الحقيقية)، 2 دعوة للحجز، 1 اسم البراند، والباقي منافع. من غير تكرار نفس المعنى.
- descriptions: 4 أوصاف كل واحد ≤ 90 حرف، جملة كاملة بتنتهي بدعوة لإجراء.
- paths: كلمتين قصيرين ≤ 15 حرف لمسار الرابط الظاهر (مثال: "luxor" و "day-tour").
- sitelinks: لحد 4، كل واحد {"text" ≤25, "description1" ≤35, "description2" ≤35, "url" من الروابط فوق بس}.
- callouts: 6 ميزات قصيرة كل واحدة ≤ 25 حرف (مثال: "Free Hotel Pickup" لو حقيقي فعلًا).
- snippet: {"header": واحد من [{$headers}], "values": 3-6 قيم كل واحدة ≤ 25 حرف}.
ممنوع: أسعار/خصومات/أرقام مش في المعلومات الحقيقية، علامات تعجب في العناوين، كابيتال كامل، "الأفضل/الأرخص".
رجّع JSON فقط:
{"headlines":["..."],"descriptions":["..."],"paths":["...","..."],"sitelinks":[{"text":"...","description1":"...","description2":"...","url":"..."}],"callouts":["..."],"snippet":{"header":"Destinations","values":["..."]}}
PROMPT;

        $response = $this->ai->generateContent($prompt, [
            'task' => 'ad_copy',
            'user_id' => $userId,
            'website_id' => $websiteId,
            'output_language' => $this->contentLanguage,
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
        ]);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }
        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['headlines'])) {
            throw new Exception('تعذّر تحليل أصول Google من رد الذكاء الاصطناعي');
        }

        $allowedUrls = array_column($links, 'url');
        $assets = self::sanitizeSearchAssets($parsed, $allowedUrls);
        foreach (['headlines', 'descriptions', 'callouts'] as $k) {
            $assets[$k] = array_values(array_filter($assets[$k], fn ($t) => empty($this->lintPolicy($t))));
        }

        try {
            Database::getInstance()->exec(
                'UPDATE ad_campaigns SET search_assets_json = ? WHERE id = ?',
                [json_encode($assets, JSON_UNESCAPED_UNICODE), (int) $campaign->getAttribute('id')]
            );
        } catch (Throwable $e) {
            // العمود بيتضاف بالميجريشن 2026_10_21_000001 - من غيره بنكمّل من غير حفظ
        }

        return $assets;
    }

    /**
     * تنضيف أصول Google بالحدود الرسمية من غير قص في نص الكلمة.
     * @param string[] $allowedUrls روابط الـSitelinks المسموحة (من الموقع فعلًا)
     */
    public static function sanitizeSearchAssets(array $raw, array $allowedUrls = []): array
    {
        $fit = fn ($t, $max) => GoogleAdsAPI::fitText(trim(str_replace(['!', '！'], '', (string) $t)), $max);
        $uniq = function (array $list): array {
            $out = [];
            foreach ($list as $t) {
                if ($t !== null && $t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                    $out[] = $t;
                }
            }
            return $out;
        };

        $sitelinks = [];
        foreach ((array) ($raw['sitelinks'] ?? []) as $s) {
            $url = trim((string) ($s['url'] ?? ''));
            $text = $fit($s['text'] ?? '', 25);
            if (!$text || !in_array($url, $allowedUrls, true)) {
                continue;
            }
            $sitelinks[] = ['text' => $text, 'description1' => $fit($s['description1'] ?? '', 35) ?? '', 'description2' => $fit($s['description2'] ?? '', 35) ?? '', 'url' => $url];
        }

        $snippet = (array) ($raw['snippet'] ?? []);
        return [
            'headlines' => array_slice($uniq(array_map(fn ($h) => $fit($h, 30), (array) ($raw['headlines'] ?? []))), 0, 15),
            'descriptions' => array_slice($uniq(array_map(fn ($d) => GoogleAdsAPI::fitText((string) $d, 90), (array) ($raw['descriptions'] ?? []))), 0, 4),
            'paths' => array_slice(array_values(array_filter(array_map(fn ($p) => $fit(preg_replace('/[\s\/]+/u', '-', (string) $p), 15), (array) ($raw['paths'] ?? [])))), 0, 2),
            'sitelinks' => array_slice($sitelinks, 0, 4),
            'callouts' => array_slice($uniq(array_map(fn ($c) => $fit($c, 25), (array) ($raw['callouts'] ?? []))), 0, 8),
            'snippet' => [
                'header' => in_array($snippet['header'] ?? '', GoogleAdsAPI::SNIPPET_HEADERS, true) ? $snippet['header'] : '',
                'values' => array_slice($uniq(array_map(fn ($v) => $fit($v, 25), (array) ($snippet['values'] ?? []))), 0, 10),
            ],
        ];
    }

    private function cleanKeywordList($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach (array_slice($list, 0, 30) as $k) {
            $text = trim((string) (is_array($k) ? ($k['keyword'] ?? '') : $k));
            if ($text === '') {
                continue;
            }
            $type = is_array($k) ? strtolower((string) ($k['match_type'] ?? 'phrase')) : 'phrase';
            $out[] = ['keyword' => mb_substr($text, 0, 80), 'match_type' => in_array($type, ['exact', 'phrase', 'broad', 'negative'], true) ? $type : 'phrase'];
        }
        return $out;
    }

    private function minQuality(): int
    {
        return self::MIN_QUALITY_SCORE;
    }

    private function parseJsonResponse(string $raw): ?array
    {
        $clean = preg_replace('/^```(json)?|```$/m', '', trim($raw));
        $parsed = json_decode(trim((string) $clean), true);
        return is_array($parsed) ? $parsed : null;
    }

    private function enforceLimits(array $copy): array
    {
        $headline = trim((string) ($copy['headline'] ?? ''));
        $description = trim((string) ($copy['description'] ?? ''));
        $primaryText = trim((string) ($copy['primary_text'] ?? ''));
        $cta = trim((string) ($copy['call_to_action'] ?? ''));

        if ($this->contentLanguage === 'en' && preg_match('/\p{Arabic}/u', $headline . ' ' . $description . ' ' . $primaryText)) {
            throw new RuntimeException('Generated ad copy did not follow the English audience policy');
        }

        if (!in_array($cta, self::allowedCtas($this->contentLanguage), true)) {
            $cta = self::allowedCtas($this->contentLanguage)[0];
        }

        return [
            'headline' => $this->truncate($headline, self::HEADLINE_MAX),
            'description' => $this->truncate($description, self::DESCRIPTION_MAX),
            'primary_text' => $this->truncate($primaryText, self::PRIMARY_TEXT_MAX),
            'call_to_action' => $cta,
        ];
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $truncated = mb_substr($text, 0, $max - 1);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($max * 0.5)) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return rtrim($truncated) . '…';
    }

    private function charStatus(int $length, int $recommended, int $max): string
    {
        if ($length <= $recommended) {
            return 'ok';
        }
        if ($length <= $max) {
            return 'warn';
        }
        return 'over';
    }

    private function lintPolicy(string $text): array
    {
        $warnings = [];
        $lower = mb_strtolower($text);

        foreach (self::RISKY_PHRASES as $phrase) {
            if (mb_strpos($lower, mb_strtolower($phrase)) !== false) {
                $warnings[] = "العبارة \"{$phrase}\" ممكن تسبب رفض الإعلان من مراجعة المنصة - جرّب صياغة أهدأ";
            }
        }

        if (preg_match('/[!?؟]{3,}/u', $text)) {
            $warnings[] = 'علامات تعجب/استفهام متكررة (!!! أو ؟؟؟) بتقلل مصداقية الإعلان في مراجعة بعض المنصات';
        }

        if (preg_match('/\p{Lu}{5,}/u', $text)) {
            $warnings[] = 'وجود كلمات بحروف كابيتال كاملة - بعض المنصات بتعتبرها صياغة عدوانية';
        }

        return $warnings;
    }

    private function clampAge($value): int
    {
        $age = (int) $value;
        if ($age < 13) {
            return 13;
        }
        if ($age > 65) {
            return 65;
        }
        return $age;
    }

    private function cleanStringList($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $clean = trim((string) $item);
            if ($clean !== '') {
                $out[] = mb_substr($clean, 0, 100);
            }
        }
        return array_slice($out, 0, 10);
    }

    private function headlineMax(): int
    {
        return self::HEADLINE_MAX;
    }
    private function headlineRecommended(): int
    {
        return self::HEADLINE_RECOMMENDED;
    }
    private function descriptionMax(): int
    {
        return self::DESCRIPTION_MAX;
    }
    private function descriptionRecommended(): int
    {
        return self::DESCRIPTION_RECOMMENDED;
    }
    private function primaryTextMax(): int
    {
        return self::PRIMARY_TEXT_MAX;
    }
    private function primaryTextRecommended(): int
    {
        return self::PRIMARY_TEXT_RECOMMENDED;
    }
    private function allowedCtasList(): string
    {
        return implode(', ', self::allowedCtas($this->contentLanguage));
    }
}