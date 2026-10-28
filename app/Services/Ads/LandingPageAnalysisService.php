<?php

/**
 * Tourfecto - Enterprise Landing Page Analysis Service
 * @version 2.0.0
 *
 * جلب صفحة الهبوط وتحليل مدى توافقها مع وعد الإعلان عبر خوارزميات التحويل (CRO).
 */
class LandingPageAnalysisService
{
    private const FETCH_TIMEOUT = 15;
    private const MAX_TEXT_CHARS = 12000;

    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    public function analyze(string $url, ?string $productDescription = null): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $html = $this->fetchPage($url);
        if ($html === null) {
            return [
                'fetch_error' => 'تعذّر الوصول للصفحة - اتأكد من الرابط أو جرّب موقعًا تاني (بعض المواقع بتمنع السحب التلقائي).',
                'relevance' => '',
                'cta' => '',
                'message_match' => '',
                'recommendations' => [],
                'page_text' => '',
            ];
        }

        $pageText = $this->extractText($html);
        if (mb_strlen($pageText) > self::MAX_TEXT_CHARS) {
            $pageText = mb_substr($pageText, 0, self::MAX_TEXT_CHARS);
        }

        $productLine = $productDescription !== null && trim($productDescription) !== ''
            ? "وعد العرض الإعلاني اللي بيوعد بيه الإعلان: \"{$productDescription}\"."
            : 'مفيش وصف واضح للعرض - حلّل الصفحة بشكل عام مع تركيز على الوضوح والتحويل.';

        $prompt = <<<PROMPT
انت خبير تحويل (CRO) لإعلانات السفر والسياحة. حلّل نص صفحة الهبوط دي من منظور "هل الصفحة تكمّل وعد الإعلان وتحوّل الزائر؟".
{$productLine}

نص الصفحة:
"""
{$pageText}
"""

رجّع JSON فقط بالشكل ده بالظبط:
{
  "relevance": "تقييم قصير لمدى تطابق الصفحة مع وعد الإعلان (جملة أو اتنين بالعربي)",
  "cta": "ملاحظة عن وضوح/قوة الدعوة لاتخاذ إجراء في الصفحة",
  "message_match": "مدى تطابق الرسالة اللي وصلها الزائر من الإعلان مع اللي لاقاه في الصفحة",
  "recommendations": ["توصية 1", "توصية 2", "توصية 3", "توصية 4"]
}
قواعد: التوصيات واقعية وقابلة للتنفيذ، 3 إلى 5 توصيات كحد أقصى، كلها بالعربي.
PROMPT;

        $response = $this->ai->generateContent($prompt, [
            'task' => 'landing_analysis',
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
        ]);
        if (!($response['success'] ?? false)) {
            return [
                'fetch_error' => null,
                'relevance' => 'تعذر الاتصال بمحرك الذكاء الاصطناعي',
                'cta' => '',
                'message_match' => '',
                'recommendations' => ['جرّب التحليل مرة أخرى بعد قليل'],
                'page_text' => mb_substr($pageText, 0, 2000),
            ];
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed)) {
            $parsed = [];
        }

        $recs = is_array($parsed['recommendations'] ?? null) ? $parsed['recommendations'] : [];
        $cleanRecs = [];
        foreach (array_slice($recs, 0, 5) as $r) {
            $text = trim((string) $r);
            if ($text !== '') {
                $cleanRecs[] = mb_substr($text, 0, 500);
            }
        }

        return [
            'fetch_error' => null,
            'relevance' => mb_substr(trim((string) ($parsed['relevance'] ?? '')), 0, 500),
            'cta' => mb_substr(trim((string) ($parsed['cta'] ?? '')), 0, 500),
            'message_match' => mb_substr(trim((string) ($parsed['message_match'] ?? '')), 0, 500),
            'recommendations' => $cleanRecs,
            'page_text' => mb_substr($pageText, 0, 2000),
        ];
    }

    private function fetchPage(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0; +https://tourfecto.com/bot)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        return (string) $body;
    }

    private function extractText(string $html): string
    {
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        if ($html !== '' && class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            @$dom->loadHTML(mb_substr($html, 0, 300000));
            libxml_clear_errors();

            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body) {
                foreach (['script', 'style', 'noscript'] as $tag) {
                    $nodes = $body->getElementsByTagName($tag);
                    for ($i = $nodes->length - 1; $i >= 0; $i--) {
                        $node = $nodes->item($i);
                        if ($node && $node->parentNode) {
                            $node->parentNode->removeChild($node);
                        }
                    }
                }
                $text = trim(preg_replace('/\s+/u', ' ', (string) $body->textContent));
                if ($text !== '') {
                    return mb_substr($text, 0, self::MAX_TEXT_CHARS);
                }
            }
        }

        $stripped = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $stripped = strip_tags((string) $stripped);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $stripped));
    }

    private function parseJsonResponse(string $raw): ?array
    {
        $clean = preg_replace('/^```(json)?|```$/m', '', trim($raw));
        $parsed = json_decode(trim((string) $clean), true);
        return is_array($parsed) ? $parsed : null;
    }
}