<?php

/**
 * Tourfecto - Custom API Publisher
 * نشر المقال على أي موقع، حتى لو مبني ببرمجة خاصة (مش ووردبريس ولا أي
 * CMS معروف). الفكرة: مبرمج موقع العميل بيعمل عنده "نقطة استقبال" واحدة
 * (endpoint) بسيطة بتستقبل المقال بصيغة JSON، وإحنا بس بنبعتله عليها.
 * @version 1.0.0
 *
 * ده الحل الواقعي الوحيد لأي موقع مخصوص، لأن مفيش API عام موحّد لكل
 * المواقع زي ما بيحصل مع ووردبريس. العميل (أو مبرمجه) هو اللي بيقرر
 * شكل الـ endpoint، إحنا بس بنلتزم بعقد بسيط وثابت (شوف
 * docs/CUSTOM_PUBLISHING_INTEGRATION.md اللي بيتبعت لمبرمج العميل).
 */
class CustomApiPublisher
{
    private int $timeout = 30;

    /** @var callable|null حقنة اختيارية للاختبارات - بتحاكي رد الـ endpoint */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * إرسال المقال لنقطة الاستقبال بتاعة موقع العميل.
     * @param string $endpointUrl الرابط اللي مبرمج العميل جهّزه
     * @param string $authToken توكن/مفتاح سري (بيتبعت في Authorization header) - ممكن يكون فاضي لو الـ endpoint مفتوح
     * @param array $article بيانات المقال (title, content_html, content_markdown, meta_description, slug, keywords, article_id)
     * @param bool $isTest لو true، بنبعت is_test=true عشان مبرمج العميل يقدر يتأكد من الربط من غير ما ينشر حاجة فعليًا
     */
    public function publish(string $endpointUrl, string $authToken, array $article, bool $isTest = false): array
    {
        // Shopify / Webflow / Ghost: الربط بالـAPI الرسمي لكل نظام (بيتخزن كـ custom_api بعنوان داخلي)
        if (class_exists('CmsPublisher') && CmsPublisher::isCmsEndpoint($endpointUrl)) {
            return (new CmsPublisher())->publish($endpointUrl, $authToken, $article, $isTest);
        }
        $payload = array_merge($article, ['is_test' => $isTest, 'source' => 'tourfecto']);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($authToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $authToken;
            $headers[] = 'X-Tourfecto-Secret: ' . $authToken; // بديل لمبرمجين مبيقروش Authorization header بسهولة
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // الاختبارات تحقن transport وهمي (بدون curl) عبر الـ constructor
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => 'POST',
                'url' => $endpointUrl,
                'headers' => $headers,
                'body' => $body,
            ]);
            return $this->buildResult($fake);
        }

        $ch = curl_init($endpointUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الوصول لنقطة الاستقبال: ' . $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "موقعك رفض الطلب (HTTP {$httpCode}). راجع مبرمج الموقع.", 'http_code' => $httpCode, 'raw' => substr((string) $response, 0, 500)];
        }

        $decoded = json_decode((string) $response, true);
        $publishedUrl = is_array($decoded) ? ($decoded['url'] ?? $decoded['published_url'] ?? null) : null;

        return ['success' => true, 'url' => $publishedUrl, 'http_code' => $httpCode];
    }

    /**
     * تحويل رد الـ transport الوهمي لنتيجة موحّدة (نفس بنية رد curl).
     * @param array $fake ['body'=>string, 'http_code'=>int, 'error'=>?string]
     */
    private function buildResult(array $fake): array
    {
        $httpCode = (int) ($fake['http_code'] ?? 0);
        $curlError = $fake['error'] ?? null;
        $response = (string) ($fake['body'] ?? '');

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الوصول لنقطة الاستقبال: ' . $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "موقعك رفض الطلب (HTTP {$httpCode}). راجع مبرمج الموقع.", 'http_code' => $httpCode, 'raw' => substr($response, 0, 500)];
        }

        $decoded = json_decode($response, true);
        $publishedUrl = is_array($decoded) ? ($decoded['url'] ?? $decoded['published_url'] ?? null) : null;

        return ['success' => true, 'url' => $publishedUrl, 'http_code' => $httpCode];
    }
}
