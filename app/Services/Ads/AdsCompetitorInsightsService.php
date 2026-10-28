<?php

/**
 * Tourfecto - Enterprise Ads Competitor Insights Service
 * @version 2.0.0
 *
 * تحليل استشاري بالذكاء الاصطناعي لتموضع المنافسين من منظور إعلاني مع الإفصاح الشفاف.
 */
class AdsCompetitorInsightsService
{
    /** @var GeminiClient */
    private $ai;
    private Database $db;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->db = Database::getInstance();
    }

    public function analyzeForAds(Competitor $competitor, string $offerDescription): array
    {
        $domain = (string) $competitor->getAttribute('competitor_domain');
        $name = (string) $competitor->getAttribute('competitor_name');
        $label = $name !== '' ? $name : $domain;

        $siteText = $this->tryFetchSiteText($domain);
        $siteLine = $siteText !== null
            ? "محتوى مأخوذ فعليًا من موقع المنافس:\n\"\"\"{$siteText}\"\"\""
            : '(تعذّر الوصول لموقع المنافس تلقائيًا - حلّل بناءً على اسمه/نشاطه وطبيعة سوق السفر والسياحة.)';

        $prompt = <<<PROMPT
انت محلّل منافسين إعلاني خبير في سوق السفر والسياحة. المنافس: "{$label}".
{$siteLine}

عرض العميل اللي هينافس بيه: "{$offerDescription}"

من منظور الإعلانات المدفوعة تحديدًا (نصوص/زوايا/استهداف/تموضع)، قدّم توصيات عملية للعميل.
رجّع JSON فقط بالشكل ده بالظبط (من غير أي نص خارج الـ JSON):
{
  "recommendations": [
    {"priority":"high|medium|low","text":"توصية بالعربي"}
  ]
}
قواعد: 4 إلى 8 توصيات، كل واحدة ب priority واحدة من high/medium/low، نص واقعي قابل للتنفيذ.
PROMPT;

        $response = $this->ai->generateContent($prompt, [
            'task' => 'ad_competitor',
            'user_id' => (int) $competitor->getAttribute('user_id'),
            'website_id' => (int) $competitor->getAttribute('website_id'),
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
        ]);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['recommendations']) || !is_array($parsed['recommendations'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي - جرّب تاني');
        }

        $recommendations = [];
        foreach (array_slice($parsed['recommendations'], 0, 8) as $r) {
            $text = trim((string) ($r['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $recommendations[] = [
                'priority' => in_array($r['priority'] ?? '', ['high', 'medium', 'low'], true) ? $r['priority'] : 'medium',
                'text' => mb_substr($text, 0, 600),
            ];
        }

        if (empty($recommendations)) {
            throw new Exception('تعذّر استخراج توصيات من الرد');
        }

        $result = [
            'recommendations' => $recommendations,
            'disclaimer' => 'تحليل استشاري بالذكاء الاصطناعي بناءً على معرفة عامة بالمنافس ومحتوى موقعه (لو متاح) - مش بيانات إعلانات حقيقية مسحوبة من أرشيف إعلانات المنافس.',
        ];

        $this->db->exec(
            "INSERT INTO ad_competitor_insights (website_id, competitor_id, offer_description, insights_json) VALUES (?, ?, ?, ?)",
            [(int) $competitor->getAttribute('website_id'), (int) $competitor->getAttribute('id'), mb_substr($offerDescription, 0, 2000), json_encode($result, JSON_UNESCAPED_UNICODE)]
        );

        ActivityLog::record('ads', 'competitor_insights.created', [
            'subject_type' => 'competitors',
            'subject_id' => (int) $competitor->getAttribute('id'),
            'meta' => ['website_id' => (int) $competitor->getAttribute('website_id')],
        ]);

        return $result;
    }

    public function listForWebsite(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT competitor_id, offer_description, insights_json, created_at
             FROM ad_competitor_insights WHERE website_id = ? ORDER BY created_at DESC LIMIT 10",
            [$websiteId]
        );

        return array_map(function ($r) {
            $r['insights_json'] = json_decode((string) $r['insights_json'], true);
            return $r;
        }, $rows);
    }

    private function tryFetchSiteText(string $domain): ?string
    {
        if ($domain === '' || !function_exists('curl_init')) {
            return null;
        }

        $url = $domain;
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0; +https://tourfecto.com/bot)',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        $stripped = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', (string) $body);
        $stripped = strip_tags((string) $stripped);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $stripped));

        return $text !== '' ? mb_substr($text, 0, 6000) : null;
    }

    private function parseJsonResponse(string $raw): ?array
    {
        $clean = preg_replace('/^```(json)?|```$/m', '', trim($raw));
        $parsed = json_decode(trim((string) $clean), true);
        return is_array($parsed) ? $parsed : null;
    }
}