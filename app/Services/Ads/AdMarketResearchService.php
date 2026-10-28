<?php

/**
 * Tourfecto - Ad Market Research Service
 * ترشيح وترتيب الدول/الأسواق المناسبة لحملة إعلانية، مبنية على معرفة
 * الذكاء الاصطناعي بالسوق (توصية استشارية مش بيانات طلب بحث حقيقية -
 * يُوضّح ده صراحة للعميل). بيحفظ كل بحث في ad_market_research للرجوع
 * ليه في صفحة "بحث الأسواق".
 * @version 1.0.0
 */
class AdMarketResearchService
{
    private const MAX_COUNTRIES = 8;

    /** @var GeminiClient */
    private $ai;

    private Database $db;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->db = Database::getInstance();
    }

    /**
     * يحلل الأسواق الواعدة لعرض العميل ويحفظ النتيجة.
     *
     * @return array{countries: array, disclaimer: string}
     */
    public function research(int $userId, string $goalDescription, ?int $campaignId = null, ?int $websiteId = null): array
    {
        $prompt = <<<PROMPT
انت خبير تسويق دولي للسفر والسياحة. بناءً على العرض ده، رتّب الدول الأنسب لإطلاق حملة إعلانية، بحيث تكون التوصية استشارية واقعية (مش بيانات طلب بحث مقاسة).

عرض العميل: "{$goalDescription}"

رجّع JSON فقط بالشكل ده بالظبط (من غير أي نص خارج الـ JSON):
{
  "countries": [
    {"country":"اسم الدولة بالإنجليزية","opportunity":"high|medium|low","reasoning":"سبب مختصر بالعربي"}
  ]
}
قواعد:
- أقصى {$this->maxCountries()} دول.
- opportunity لازم تكون واحدة من: high, medium, low.
- رتّبها من الأكثر وعدًا للأقل. برر باختصار (موسم/قوة شرائية/منافسة/صلة بالسفر).
PROMPT;

        $response = $this->ai->generateContent($prompt, ['task'=>'ad_market', 'user_id'=>$userId, 'website_id'=>$websiteId, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json']);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['countries']) || !is_array($parsed['countries'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي - جرّب تاني بوصف أوضح');
        }

        $countries = [];
        foreach (array_slice($parsed['countries'], 0, $this->maxCountries()) as $c) {
            $name = trim((string) ($c['country'] ?? ''));
            if ($name === '') {
                continue;
            }
            $countries[] = [
                'country' => mb_substr($name, 0, 120),
                'opportunity' => in_array($c['opportunity'] ?? '', ['high', 'medium', 'low'], true) ? $c['opportunity'] : 'medium',
                'reasoning' => mb_substr(trim((string) ($c['reasoning'] ?? '')), 0, 500),
            ];
        }

        if (empty($countries)) {
            throw new Exception('تعذّر استخراج أسواق مقترحة من الرد');
        }

        $result = [
            'countries' => $countries,
            'disclaimer' => 'توصية استشارية بالذكاء الاصطناعي بناءً على معرفة السوق - مش بيانات طلب بحث حقيقية. استخدمها كنقطة بداية، وراجع المنصات الفعلية للبيانات المقاسة.',
        ];

        $this->db->exec(
            "INSERT INTO ad_market_research (user_id, campaign_id, goal_description, result_json) VALUES (?, ?, ?, ?)",
            [$userId, $campaignId, mb_substr($goalDescription, 0, 2000), json_encode($result, JSON_UNESCAPED_UNICODE)]
        );

        ActivityLog::record('ads', 'market_research.created', [
            'user_id' => $userId, 'subject_type' => 'ad_market_research',
            'meta' => ['campaign_id' => $campaignId, 'countries' => count($countries)],
        ]);

        return $result;
    }

    /** أرشيف أبحاث السوق لمستخدم - الأحدث أولًا (result_json خام جاهز للفك في الواجهة) */
    public function history(int $userId): array
    {
        return $this->db->query(
            "SELECT id, campaign_id, goal_description, result_json, created_at
             FROM ad_market_research WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
            [$userId]
        );
    }

    private function parseJsonResponse(string $raw): ?array
    {
        $clean = preg_replace('/^```(json)?|```$/m', '', trim($raw));
        $parsed = json_decode(trim((string) $clean), true);
        return is_array($parsed) ? $parsed : null;
    }

    private function maxCountries(): int
    {
        return self::MAX_COUNTRIES;
    }
}
