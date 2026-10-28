<?php

/**
 * Tourfecto - Ad Keyword Strategist Service
 * يولّد حزمة كلمات مفتاحية احترافية (مجموعة حسب نية البحث) لحملة، ويحفظها
 * في ad_keywords (مع match_type وai_relevance_score وتقديرات حجم بحث/CPC).
 *
 * الأرقام التقديرية (حجم البحث/CPC) تقديرات ذكاء اصطناعي للاسترشاد بس -
 * مش بيانات بحث حقيقية مقاسة من Google Keyword Planner (يُوضّح ده صراحة
 * للعميل في الواجهة، راجع الـDisclaimer في الرد).
 * @version 1.0.0
 */
class AdKeywordStrategistService
{
    private const MAX_PER_GROUP = 10;
    private const MAX_TOTAL = 40;

    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    /**
     * يولّد الكلمات المفتاحية لحملة (باستخدام وصف العرض + دولة الاستهداف)
     * ويحفظها، ويرجّع النتيجة مجمّعة حسب نية البحث للعرض.
     *
     * @return array{high_intent: array, commercial: array, long_tail: array, local: array, negative: array, disclaimer: string}
     */
    public function generateForCampaign(AdCampaign $campaign, string $goalDescription, ?string $targetCountry = null): array
    {
        $campaignName = (string) $campaign->getAttribute('name');
        $platform = (string) $campaign->getAttribute('platform');
        $product = (string) $campaign->getAttribute('product_or_service');
        $context = $campaignName !== '' ? $campaignName : $goalDescription;

        $country = $targetCountry;
        if (!$country) {
            $raw = $campaign->getAttribute('target_countries_json');
            $list = $raw ? json_decode((string) $raw, true) : null;
            if (is_array($list) && !empty($list)) {
                $country = (string) $list[0];
            }
        }

        $countryLine = $country ? "السوق المستهدف: {$country} (الكتابة باللغة العربية الفصحى المفهومة في هذا السوق)." : 'السوق المستهدف: عام (الكتابة بالعربية مع كلمات إنجليزية شائعة في البحث المدفوع).';
        $platformLine = $platform === 'google_ads'
            ? 'الحملة هتتقدم على Google Ads (بحث) - لوّن الكلمات لتتناسب مع نية البحث الحقيقية.'
            : 'الحملة هتتقدم على Meta Ads - ركّز على كلمات اهتمام/جمهور بجانب كلمات البحث التقليدية.';

        $prompt = <<<PROMPT
انت خبير Keywords استراتيجي لمحترفي إعلانات السفر والسياحة. جهّز حزمة كلمات مفتاحية شاملة لحملة عن: "{$context}".
الوصف التفصيلي للعرض: "{$goalDescription}"
{$platformLine}
{$countryLine}

رجّع JSON فقط بالشكل ده بالظبط (من غير أي نص خارج الـ JSON):
{
  "high_intent": [{"keyword":"...","match_type":"exact|phrase","ai_relevance_score":85,"estimated_search_volume":1200,"estimated_cpc":1.5}],
  "commercial":   [{"keyword":"...","match_type":"phrase|broad","ai_relevance_score":70,"estimated_search_volume":2000,"estimated_cpc":0.9}],
  "long_tail":    [{"keyword":"...","match_type":"phrase","ai_relevance_score":60,"estimated_search_volume":300,"estimated_cpc":0.4}],
  "local":        [{"keyword":"...","match_type":"broad","ai_relevance_score":55,"estimated_search_volume":800,"estimated_cpc":0.6}],
  "negative":     [{"keyword":"..."}]
}
قواعد صارمة:
- كل مجموعة أقصى {$this->maxPerGroup()} كلمات، والمجموع الكلي أقصى {$this->maxTotal()}.
- keyword مفيش علامات ترقيم غريبة، أقصى 255 حرف، من غير علامات اقتباس.
- ai_relevance_score من 0 إلى 100. estimated_search_volume عدد صحيح. estimated_cpc رقم عشري.
- negative = كلمات استبعاد فعليًا (من غير match_type أو بمعنى استبعاد).
- ارفض الكلمات العامة جدًا أو الضعيفة جدًا من الناحية التجارية.
PROMPT;

        $response = $this->ai->generateContent($prompt, ['task'=>'ad_keywords', 'user_id'=>(int)$campaign->getAttribute('user_id'), 'output_language'=>ContentLanguageService::forUser((int)$campaign->getAttribute('user_id'),(int)$campaign->getAttribute('website_id')?:null), 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json']);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed)) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي - جرّب تاني بوصف أوضح');
        }

        $groups = ['high_intent', 'commercial', 'long_tail', 'local', 'negative'];
        $result = [];
        $savedCount = 0;
        $campaignId = (int) $campaign->getAttribute('id');

        foreach ($groups as $group) {
            $items = is_array($parsed[$group] ?? null) ? array_slice($parsed[$group], 0, $this->maxPerGroup()) : [];
            $clean = [];
            foreach ($items as $k) {
                $keywordText = trim((string) ($k['keyword'] ?? ''));
                if ($keywordText === '') {
                    continue;
                }
                $keywordText = mb_substr($keywordText, 0, 255);

                $matchType = $this->cleanMatchType($k['match_type'] ?? ($group === 'negative' ? 'negative' : 'phrase'));
                if ($group === 'negative') {
                    $matchType = 'negative';
                }

                $relevance = $this->clampInt($k['ai_relevance_score'] ?? null, 0, 100, 50);
                $volume = $this->clampInt($k['estimated_search_volume'] ?? null, 0, 1000000, null);
                $cpc = is_numeric($k['estimated_cpc'] ?? null) ? round((float) $k['estimated_cpc'], 2) : null;

                // حفظ في ad_keywords عشان يظهر في قائمة كلمات الحملة المحفوظة
                if ($savedCount < $this->maxTotal()) {
                    $model = new AdKeyword([
                        'campaign_id' => $campaignId,
                        'keyword' => $keywordText,
                        'match_type' => $matchType,
                        'ai_relevance_score' => $relevance,
                        'estimated_search_volume' => $volume,
                        'estimated_cpc' => $cpc,
                        'ai_generated' => 1,
                    ]);
                    $model->save();
                    $savedCount++;
                }

                $clean[] = [
                    'keyword' => $keywordText,
                    'match_type' => $matchType,
                    'ai_relevance_score' => $relevance,
                    'estimated_search_volume' => $volume,
                    'estimated_cpc' => $cpc,
                ];
            }
            $result[$group] = $clean;
        }

        $result['disclaimer'] = 'حجم البحث وCPC تقديرات ذكاء اصطناعي للاسترشاد بها فقط - مش بيانات Keyword Planner حقيقية مقاسة. الكلمات المحفوظة تظهر في قائمة كلمات الحملة.';

        ActivityLog::record('ads', 'keywords.generated', [
            'subject_type' => 'ad_campaigns', 'subject_id' => $campaignId,
            'meta' => ['saved' => $savedCount],
        ]);

        return $result;
    }

    private function parseJsonResponse(string $raw): ?array
    {
        $clean = preg_replace('/^```(json)?|```$/m', '', trim($raw));
        $parsed = json_decode(trim((string) $clean), true);
        return is_array($parsed) ? $parsed : null;
    }

    private function cleanMatchType(string $matchType): string
    {
        return in_array($matchType, ['exact', 'phrase', 'broad', 'negative'], true) ? $matchType : 'phrase';
    }

    private function clampInt($value, int $min, int $max, ?int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $n = (int) $value;
        if ($n < $min) {
            return $min;
        }
        if ($n > $max) {
            return $max;
        }
        return $n;
    }

    private function maxPerGroup(): int
    {
        return self::MAX_PER_GROUP;
    }
    private function maxTotal(): int
    {
        return self::MAX_TOTAL;
    }
}
