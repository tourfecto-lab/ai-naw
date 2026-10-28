<?php

/**
 * Tourfecto - OpenAI Image Client (صور ChatGPT)
 *
 * بيولّد الصورة من OpenAI Images API. الإعدادات من .env:
 *   OPENAI_API_KEY            (موجود أصلاً)
 *   OPENAI_API_BASE_URL       (افتراضي https://api.openai.com/v1)
 *   OPENAI_IMAGE_MODEL        (افتراضي gpt-image-1، وبيدعم dall-e-3 كمان)
 *   OPENAI_IMAGE_QUALITY      (low | medium | high، افتراضي medium. gpt-image-1 بس)
 *   OPENAI_IMAGE_COST_USD     (تكلفة تقديرية للصورة تظهر في استهلاك AI، افتراضي 0.06)
 *
 * ملحوظات من واجهة OpenAI (حسب معرفتي، ممكن تتغيّر):
 *   - gpt-image-1: بيرجّع base64 دايمًا (b64_json) ومابيقبلش response_format. المقاسات: 1024x1024 / 1536x1024 / 1024x1536.
 *   - dall-e-3: بيحتاج response_format=b64_json عشان نتفادى تحميل رابط. المقاسات: 1024x1024 / 1792x1024 / 1024x1792.
 *   - gpt-image-1 أحيانًا بيحتاج توثيق المنظمة (Organization Verification) من حساب OpenAI: بيرجّع 403.
 *
 * أي خطأ بيرجع منظّم (http_code + api_status + error) للأدمن والسجلات فقط. العميل ما بيشوفش حاجة منه (VendorMask).
 *
 * @version 1.0.0
 */
class OpenAIImageClient
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $model;
    /** @var int */
    private $timeout;
    /** @var array{task:?string,user_id:?int} */
    private $meta = ['task' => null, 'user_id' => null];

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, ?string $model = null, int $timeout = 120)
    {
        if($apiKey===null&&class_exists('AiServiceRouting'))$apiKey=AiServiceRouting::apiKey('openai');
        $this->apiKey = (string) ($apiKey ?? self::setting('OPENAI_API_KEY', defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ''));
        $base = (string) ($baseUrl ?? self::setting('OPENAI_API_BASE_URL', defined('OPENAI_API_BASE_URL') ? OPENAI_API_BASE_URL : 'https://api.openai.com/v1'));
        $this->baseUrl = rtrim($base !== '' ? $base : 'https://api.openai.com/v1', '/');
        $m = (string) ($model ?? self::setting('OPENAI_IMAGE_MODEL', 'gpt-image-1'));
        $this->model = preg_match('/^[A-Za-z0-9._\-]+$/', $m) ? $m : 'gpt-image-1';
        $this->timeout = $timeout;
    }

    private static function setting(string $key, $default)
    {
        if (function_exists('env')) {
            $v = env($key);
            if ($v !== null && $v !== '') {
                return is_bool($v) ? ($v ? 'true' : 'false') : $v;
            }
        }
        return $default;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @return array{size:string, payload_extra:array} */
    private function shape(string $aspect, ?string $quality = null): array
    {
        $isDalle = stripos($this->model, 'dall-e') === 0;
        $wide = in_array($aspect, ['16:9', '3:2', '4:3', '21:9'], true);
        $tall = in_array($aspect, ['9:16', '2:3', '3:4', '4:5'], true);
        if ($isDalle) {
            return ['size' => $wide ? '1792x1024' : ($tall ? '1024x1792' : '1024x1024'), 'extra' => ['response_format' => 'b64_json']];
        }
        $q = strtolower((string) ($quality ?? self::setting('OPENAI_IMAGE_QUALITY', 'medium')));
        return ['size' => $wide ? '1536x1024' : ($tall ? '1024x1536' : '1024x1024'), 'extra' => ['quality' => in_array($q, ['low', 'medium', 'high', 'auto'], true) ? $q : 'medium']];
    }

    /**
     * @return array{success:bool, image_base64?:string, mime_type?:string, model?:string, error?:string, http_code?:int, api_status?:string}
     */
    public function generateImage(string $prompt, string $aspectRatio = '1:1', array $meta = []): array
    {
        $started = microtime(true);
        $this->meta = [
            'task' => isset($meta['task']) ? substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) $meta['task']), 0, 60) : null,
            'user_id' => isset($meta['user_id']) ? (int) $meta['user_id'] : null,
        ];
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'OpenAI key not configured', 'http_code' => 0, 'api_status' => 'NOT_CONFIGURED'];
        }
        $shape = $this->shape($aspectRatio, isset($meta['quality']) && is_string($meta['quality']) ? $meta['quality'] : null);
        $payload = array_merge(['model' => $this->model, 'prompt' => mb_substr($prompt, 0, 4000), 'n' => 1, 'size' => $shape['size']], $shape['extra']);

        $r = $this->post('/images/generations', $payload);
        if (!$r['ok']) {
            return ['success' => false, 'error' => $r['error'], 'http_code' => $r['http'], 'api_status' => $r['status']];
        }
        $item = $r['data']['data'][0] ?? [];
        $b64 = '';
        if (!empty($item['b64_json']) && is_string($item['b64_json'])) {
            $b64 = $item['b64_json'];
        } elseif (!empty($item['url']) && is_string($item['url'])) {
            $bin = $this->download($item['url']);
            if ($bin !== null) {
                $b64 = base64_encode($bin);
            }
        }
        if ($b64 === '') {
            return ['success' => false, 'error' => 'OpenAI returned no image', 'http_code' => 200, 'api_status' => 'NO_IMAGE'];
        }
        $this->logUsage((int) (($r['data']['usage']['total_tokens'] ?? 0)), (microtime(true) - $started) * 1000);
        return ['success' => true, 'image_base64' => $b64, 'mime_type' => 'image/png', 'model' => $this->model];
    }

    /** فحص مجاني: هل الموديل متاح للمفتاح؟ @return array{ok:bool, model:string, http:int, status:string} */
    public function checkModel(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'model' => $this->model, 'http' => 0, 'status' => 'NOT_CONFIGURED'];
        }
        $r = $this->request('GET', '/models/' . rawurlencode($this->model), null);
        return ['ok' => $r['ok'], 'model' => $this->model, 'http' => $r['http'], 'status' => $r['status']];
    }

    // ------------------------------------------------------------------ HTTP

    /** @return array{ok:bool, http:int, status:string, error:string, data:array} */
    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    protected function request(string $method, string $path, ?array $payload): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($curlErr !== '') {
            return ['ok' => false, 'http' => 0, 'status' => 'NETWORK', 'error' => 'cURL Error: ' . $curlErr, 'data' => []];
        }
        $data = json_decode((string) $resp, true);
        $data = is_array($data) ? $data : [];
        if ($http < 200 || $http >= 300) {
            $err = is_array($data['error'] ?? null) ? $data['error'] : [];
            $status = (string) ($err['code'] ?? $err['type'] ?? '');
            return ['ok' => false, 'http' => $http, 'status' => $status, 'error' => 'Image API Error (HTTP ' . $http . '): ' . (string) ($err['message'] ?? 'Unknown error'), 'data' => $data];
        }
        return ['ok' => true, 'http' => $http, 'status' => '', 'error' => '', 'data' => $data];
    }

    /** تحميل صورة من رابط (للموديلات اللي بترجّع url): https بس، أقصى 15MB، من دومينات OpenAI المعروفة */
    protected function download(string $url): ?string
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || $host === ''
            || !(preg_match('/(^|\.)openai\.com$/', $host) || preg_match('/\.blob\.core\.windows\.net$/', $host))) {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > 15000000 ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($http === 200 && $body !== '') ? $body : null;
    }

    // ------------------------------------------------------------------ تتبّع الاستخدام

    private function logUsage(int $tokens, float $durationMs): void
    {
        $sql = 'INSERT INTO api_usage_logs (user_id, api_type, feature, model, endpoint, status_code, tokens_used, cost_in_usd, duration_ms)
                VALUES (:u, :t, :f, :m, :e, 200, :k, :c, :d)';
        $row = [':u' => $this->meta['user_id'] ?: null, ':f' => $this->meta['task'] ?: null, ':m' => $this->model, ':e' => 'images/generations',
            ':k' => $tokens, ':c' => (float) self::setting('OPENAI_IMAGE_COST_USD', 0.06), ':d' => (int) round($durationMs)];
        try {
            Database::getInstance()->query($sql, $row + [':t' => 'openai']);
        } catch (\Throwable $e) {
            // التتبّع مش لازم يكسر الصورة
        }
    }
}
