<?php

/**
 * Tourfecto - Gemini API Client
 * عميل متقدم للتعامل مع Gemini API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class GeminiClient
{
    /**
     * @var string $apiKey - مفتاح API
     */
    private $apiKey;

    /**
     * @var string $model - نموذج Gemini
     */
    private $model;
    private bool $exactModel = false;

    /**
     * @var string $apiUrl - رابط API
     */
    private $apiUrl;

    /**
     * @var array $config - إعدادات الطلب
     */
    private $config;

    /**
     * @var int $maxRetries - عدد محاولات إعادة الطلب
     */
    private $maxRetries = 3;

    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 60;

    /**
     * Constructor
     */
    public function __construct()
    {
        // تصحيح: بدل الاعتماد على GEMINI_API_KEY من .env بس (يحتاج
        // SSH/File Manager للتعديل)، بقى يقرا من إعدادات النظام القابلة
        // للتعديل من لوحة الأدمن الأول، ويرجع لـ .env كاحتياط آمن.
        $this->apiKey = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('gemini_api_key', GEMINI_API_KEY)
            : GEMINI_API_KEY;

        if(class_exists('AiServiceRouting'))$this->apiKey=AiServiceRouting::apiKey('gemini');
        $this->model = GEMINI_MODEL;
        // تصحيح: GEMINI_API_URL هو الأساس بس (https://.../v1beta) من غير
        // مسار الموديل - أي طلب كان بيروح لرابط ناقص وبيرجع 404 دايمًا،
        // مهما كان مفتاح الـ API صحيح. لازم نضيف /models/{model}:generateContent.
        $this->apiUrl = rtrim(GEMINI_API_URL, '/') . '/models/' . rawurlencode($this->model) . ':generateContent';

        $this->config = [
            'temperature' => GEMINI_TEMPERATURE,
            'maxOutputTokens' => GEMINI_MAX_TOKENS,
            'topP' => GEMINI_TOP_P,
            'topK' => GEMINI_TOP_K
        ];

        $this->maxRetries = GEMINI_MAX_RETRIES;
        $this->timeout = GEMINI_TIMEOUT;
    }

    /**
     * توليد محتوى باستخدام Gemini API
     * @param string $prompt - نص الطلب
     * @param array $options - خيارات إضافية
     * @return array
     */
    public function generateContent(string $prompt, array $options = []): array
    {
        $prompt = ContentLanguageService::apply($prompt, $options);
        if(empty($options['_routing_bypass'])&&class_exists('AiServiceRouting')){
            try{if(AiServiceRouting::policy((string)($options['task']??'default'))!==null)return (new AIOrchestrator())->generateContent($prompt,$options);}
            catch(Throwable $e){Logger::error('AI routing unavailable');return AiServiceRouting::failure();}
        }
        $priorModel=$this->model;$priorExact=$this->exactModel;
        if(!empty($options['_routing_exact'])&&isset($options['model'])&&preg_match('/^[A-Za-z0-9._-]{1,120}$/D',(string)$options['model'])){$this->model=$options['model'];$this->exactModel=true;}
        $startTime = microtime(true);

        try {
            // دمج الخيارات مع التكوين الأساسي
            // الـ options بتجمع إعدادات Gemini الحقيقية + بيانات تتبّع من الـ callers (task / user_id).
            // كان كله بيتبعت جوّه generationConfig فـ Google بترفض الطلب بـ HTTP 400 ("Unknown name task"):
            // ده كان بيكسر تحليل المنافسين، وخطة النمو في الـ onboarding، ومستشار الـ CEO، ورسائل الـ Outreach.
            $this->meta = [
                'task' => isset($options['task']) ? substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) $options['task']), 0, 60) : null,
                'user_id' => isset($options['user_id']) ? (int) $options['user_id'] : null,
            ];
            // الحوكمة المركزية: نسب الطلب للشركة صاحبته (حتى لو الاستدعاء ما بعتش user_id)
            // وطبّق حدود الاستخدام العادلة. أي خطأ هنا بيتجاهل (fail-open).
            try {
                if (!class_exists('AIGovernor') && is_file(__DIR__ . '/AIGovernor.php')) {
                    require_once __DIR__ . '/AIGovernor.php';
                }
                if (class_exists('AIGovernor')) {
                    $this->meta['user_id'] = AIGovernor::resolveUserId($this->meta['user_id']);
                    $blocked = AIGovernor::check($this->meta['user_id'], $this->meta['task']);
                    if ($blocked !== null) {
                        return [
                            'success' => false,
                            'error' => $blocked['message'],
                            'error_kind' => 'limit',
                            'diag_code' => 'GOV-' . strtoupper($blocked['reason']),
                            'http_code' => 429,
                        ];
                    }
                }
            } catch (Throwable $govError) {
                // نكمّل عادي
            }

            $config = $this->sanitizeGenerationConfig(array_merge($this->config, $options));

            // بناء جسم الطلب
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => $config,
                'safetySettings' => GEMINI_SAFETY_SETTINGS
            ];

            // محاولة إرسال الطلب مع إعادة المحاولة
            $response = $this->sendRequest($payload);

            $duration = (microtime(true) - $startTime) * 1000;

            // تسجيل الاستخدام
            $this->logUsage($response, $duration);

            return $response;

        } catch (Exception $e) {
            Logger::error('Gemini API Error', [
                'message' => $e->getMessage(),
                'prompt' => substr($prompt, 0, 200)
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {$this->model=$priorModel;$this->exactModel=$priorExact;}
    }

    /**
     * يوصف صورة (رؤية): بيبعت الصورة مع السؤال ويرجّع النص. بيستخدم نفس
     * مفتاح وموديلات النص (Gemini Flash بيفهم الصور).
     * @return array ['success'=>bool, 'data'=>?string, 'error'=>?string]
     */
    public function describeImage(string $binary, string $mime, string $prompt, array $options = []): array
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $binary === '' || strlen($binary) > 7000000) {
            return ['success' => false, 'error' => 'unsupported image'];
        }
        $this->meta = ['task' => 'media_vision', 'user_id' => isset($options['user_id']) ? (int) $options['user_id'] : null];
        try {
            $config = $this->sanitizeGenerationConfig(array_merge($this->config, ['temperature' => 0.2, 'maxOutputTokens' => 1024], $options));
            $response = $this->sendRequest([
                'contents' => [['parts' => [
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($binary)]],
                    ['text' => $prompt],
                ]]],
                'generationConfig' => $config,
                'safetySettings' => GEMINI_SAFETY_SETTINGS,
            ]);
            $this->logUsage($response, 0);
            return $response;
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * توليد صورة حقيقية من وصف نصي، بنفس مفتاح Gemini API المستخدم
     * للنصوص - مفيش حاجة تضاف في .env. بيستخدم موديل gemini-2.5-flash-image
     * ("Nano Banana")، فيه حصة مجانية سخية (لحد 500 طلب/يوم وقت كتابة
     * الكود ده) وبيدعم 1024x1024 كافي جدًا لصور سوشيال ميديا/GBP.
     * @param string $prompt وصف الصورة المطلوبة
     * @param string $aspectRatio '1:1' | '16:9' | '9:16' | '4:3' | '3:4'
     * @return array ['success'=>bool, 'image_base64'=>?string, 'mime_type'=>?string, 'error'=>?string]
     */
    public function generateImage(string $prompt, string $aspectRatio = '1:1', array $meta = []): array
    {
        if(empty($meta['_routing_image_bypass'])&&class_exists('AiServiceRouting')&&isset($meta['task'])){
            try{if(($policy=AiServiceRouting::policy((string)$meta['task']))!==null)return AiServiceRouting::image($prompt,$aspectRatio,$meta,$policy);}
            catch(Throwable $e){Logger::error('Image routing unavailable');return AiServiceRouting::failure();}
        }
        $startTime = microtime(true);
        $this->meta = [
            'task' => isset($meta['task']) ? substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) $meta['task']), 0, 60) : null,
            'user_id' => isset($meta['user_id']) ? (int) $meta['user_id'] : null,
        ];
        $payload = self::imagePayload($prompt,$aspectRatio,$meta['reference']??null);

        // أسماء موديلات الصور بتتغيّر وبتختلف من مفتاح لمفتاح: بنجرّب (المضبوط في .env ← آخر واحد نجح ← الافتراضي)،
        // ولو الكل رجّع 404 بنسأل جوجل نفسها (models.list: مجاني) عن موديل صور متاح لمفتاحك.
        $tried = [];
        $last = ['http' => 0, 'status' => '', 'error' => 'unknown'];
        foreach (isset($meta['model'])?[(string)$meta['model']]:$this->imageModelCandidates() as $model) {
            $tried[] = $model;
            $r = $this->imageRequest($model, $payload);
            if ($r['success']) {
                return $this->imageSuccess($r, $model, $startTime, empty($meta['model']));
            }
            $last = $r;
            if ($r['http'] !== 404) {
                break;      // الموديل موجود لكن فيه مشكلة (صلاحيات/حصة/ضغط): موديل تاني مش هيفرق
            }
        }
        if ($last['http'] === 404 && empty($meta['model'])) {
            $found = $this->discoverImageModel();
            if ($found !== null && !in_array($found, $tried, true)) {
                $r = $this->imageRequest($found, $payload);
                if ($r['success']) {
                    return $this->imageSuccess($r, $found, $startTime);
                }
                $last = $r;
            }
        }
        Logger::error('Gemini Image Generation Error', ['message' => $last['error'], 'http' => $last['http'], 'status' => $last['status'], 'models' => $tried]);
        return ['success' => false, 'error' => $last['error'], 'http_code' => $last['http'], 'api_status' => $last['status']];
    }

    public static function imagePayload(string $prompt,string $aspectRatio,?array $reference=null): array
    {
        if(!in_array($aspectRatio,GenerationOptions::IMAGE_RATIOS,true))throw new InvalidArgumentException('Invalid ratio');
        $parts=[['text'=>$prompt]];if($reference){ReferenceMedia::validateInline($reference);$parts[]=['inlineData'=>$reference];}
        return ['contents'=>[['parts'=>$parts]],'generationConfig'=>['responseModalities'=>['Image'],'imageConfig'=>['aspectRatio'=>$aspectRatio]],'safetySettings'=>defined('GEMINI_SAFETY_SETTINGS')?GEMINI_SAFETY_SETTINGS:[]];
    }

    private function imageSuccess(array $r, string $model, float $startTime,bool $remember=true): array
    {
        if($remember)$this->rememberImageModel($model);
        $this->logUsage([
            'success' => true, 'http_code' => 200, 'tokens_used' => $r['tokens'] ?? 0, 'model' => $model,
            'cost' => (float) (defined('GEMINI_IMAGE_COST_USD') ? GEMINI_IMAGE_COST_USD : 0.04),
        ], (microtime(true) - $startTime) * 1000);
        return ['success' => true, 'image_base64' => $r['image_base64'], 'mime_type' => $r['mime_type'], 'model' => $model];
    }

    /** طلب توليد صورة واحد لموديل واحد. @return array{success:bool, http:int, status:string, error:string, image_base64?:string, mime_type?:string, tokens?:int} */
    private function imageRequest(string $model, array $payload): array
    {
        // المفتاح في header مش في الرابط: الروابط بتتسجل في logs الـ proxy/السيرفر
        $url = GEMINI_API_URL . '/models/' . rawurlencode($model) . ':generateContent';
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-goog-api-key: ' . $this->apiKey],
                CURLOPT_TIMEOUT => 90,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Tourfecto/1.0',
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            return ['success' => false, 'http' => 0, 'status' => '', 'error' => 'cURL Error: ' . $e->getMessage()];
        }
        if ($curlError) {
            return ['success' => false, 'http' => 0, 'status' => 'NETWORK', 'error' => 'cURL Error: ' . $curlError];
        }
        $data = json_decode((string) $response, true);
        if ($httpCode !== 200) {
            $msg = is_array($data) ? ($data['error']['message'] ?? 'Unknown error') : 'Unknown error';
            return ['success' => false, 'http' => $httpCode, 'status' => (string) ($data['error']['status'] ?? ''), 'error' => "Gemini Image API Error (HTTP {$httpCode}): {$msg}"];
        }
        $parts = is_array($data) ? ($data['candidates'][0]['content']['parts'] ?? []) : [];
        foreach ($parts as $part) {
            if (isset($part['inlineData']['data'])) {
                return ['success' => true, 'http' => 200, 'status' => '', 'error' => '', 'image_base64' => $part['inlineData']['data'],
                    'mime_type' => $part['inlineData']['mimeType'] ?? 'image/png', 'tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0];
            }
        }
        // 200 من غير صورة: غالبًا حجب أمان أو الطلب مش مفهوم. بنوصّل السبب (finishReason / blockReason) بدل رسالة غامضة.
        $reason = (string) ($data['candidates'][0]['finishReason'] ?? $data['promptFeedback']['blockReason'] ?? '');
        $text = $parts[0]['text'] ?? '';
        return ['success' => false, 'http' => 200, 'status' => $reason !== '' ? $reason : 'NO_IMAGE',
            'error' => $text !== '' ? ('No image generated: ' . mb_substr($text, 0, 200)) : 'No image in Gemini response' . ($reason !== '' ? " ({$reason})" : '')];
    }

    // ------------------------------------------------------------------ اختيار موديل الصور

    /** @return string[] */
    public function imageModelCandidates(): array
    {
        $list = [];
        $cfg = function_exists('env') ? env('GEMINI_IMAGE_MODEL') : getenv('GEMINI_IMAGE_MODEL');
        if (is_string($cfg) && preg_match('/^[A-Za-z0-9._\-]+$/', $cfg)) {
            $list[] = $cfg;
        }
        $cached = $this->readImageModelCache();
        if ($cached !== null) {
            $list[] = $cached;
        }
        $list[] = 'gemini-2.5-flash-image';
        return array_values(array_unique($list));
    }

    private function imageModelCacheFile(): string
    {
        $base = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : sys_get_temp_dir();
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'gemini_image_model.json';
    }

    private function readImageModelCache(): ?string
    {
        $f = $this->imageModelCacheFile();
        $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($d) && !empty($d['model']) && ($d['at'] ?? 0) > time() - 86400 && preg_match('/^[A-Za-z0-9._\-]+$/', (string) $d['model'])) {
            return (string) $d['model'];
        }
        return null;
    }

    private function rememberImageModel(string $model): void
    {
        $f = $this->imageModelCacheFile();
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0775, true);
        }
        @file_put_contents($f, json_encode(['model' => $model, 'at' => time()]), LOCK_EX);
    }

    /**
     * بيسأل جوجل (models.list: مجاني، من غير توليد) عن موديلات الصور اللي مفتاحك يقدر يستخدمها.
     * بنفضّل الأحدث والمستقر (من غير preview/exp)، وبنستبعد imagen (بتشتغل بطريقة تانية).
     */
    public function discoverImageModel(): ?string
    {
        $found = [];
        $token = '';
        for ($page = 0; $page < 3; $page++) {
            $url = GEMINI_API_URL . '/models?pageSize=200' . ($token !== '' ? '&pageToken=' . rawurlencode($token) : '');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $this->apiKey], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Tourfecto/1.0']);
            $resp = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $d = json_decode((string) $resp, true);
            if ($http !== 200 || !is_array($d)) {
                break;
            }
            foreach (($d['models'] ?? []) as $m) {
                $name = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                $methods = $m['supportedGenerationMethods'] ?? [];
                if ($name !== '' && stripos($name, 'image') !== false && stripos($name, 'imagen') === false
                    && in_array('generateContent', $methods, true) && preg_match('/^[A-Za-z0-9._\-]+$/', $name)) {
                    $found[] = $name;
                }
            }
            $token = (string) ($d['nextPageToken'] ?? '');
            if ($token === '') {
                break;
            }
        }
        if (!$found) {
            return null;
        }
        usort($found, function ($a, $b) {
            $ua = (preg_match('/preview|exp|latest/i', $a) ? 1 : 0);
            $ub = (preg_match('/preview|exp|latest/i', $b) ? 1 : 0);
            if ($ua !== $ub) {
                return $ua <=> $ub;            // المستقر الأول
            }
            $fa = stripos($a, 'flash') !== false ? 0 : 1;
            $fb = stripos($b, 'flash') !== false ? 0 : 1;
            return $fa !== $fb ? $fa <=> $fb : strcmp($b, $a);   // flash الأول ثم الأحدث (ترتيب عكسي)
        });
        return $found[0];
    }

    /**
     * فحص مجاني (من غير توليد صورة): هل موديل الصور متاح لمفتاحك؟
     * @return array{ok:bool, model:?string, http:int, status:string, discovered:bool}
     */
    public function checkImageModel(): array
    {
        $out = ['ok' => false, 'model' => null, 'http' => 0, 'status' => '', 'discovered' => false];
        $last = null;
        foreach ($this->imageModelCandidates() as $model) {
            $ch = curl_init(GEMINI_API_URL . '/models/' . rawurlencode($model));
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $this->apiKey], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Tourfecto/1.0']);
            $resp = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $d = json_decode((string) $resp, true);
            $last = ['http' => $http, 'status' => (string) ($d['error']['status'] ?? '')];
            if ($http === 200) {
                return ['ok' => true, 'model' => $model, 'http' => 200, 'status' => '', 'discovered' => false];
            }
            if ($http !== 404) {
                break;
            }
        }
        if (($last['http'] ?? 0) === 404) {
            $found = $this->discoverImageModel();
            if ($found !== null) {
                return ['ok' => true, 'model' => $found, 'http' => 200, 'status' => '', 'discovered' => true];
            }
        }
        return ['ok' => false, 'model' => null, 'http' => $last['http'] ?? 0, 'status' => $last['status'] ?? '', 'discovered' => false];
    }

    /**
     * إرسال طلب إلى Gemini API مع إعادة محاولة ذكية وموديلات بديلة.
     *
     * الخطأ "503 This model is currently experiencing high demand" (وكذلك
     * 429/500/502/504 وانقطاع الاتصال) مؤقت وبيحصل على موديل بعينه. السلوك:
     *  1) نعيد المحاولة على نفس الموديل مرة واحدة بعد تأخير قصير عشوائي.
     *  2) لو لسه فاشل، ننتقل لموديل بديل (GEMINI_FALLBACK_MODELS في .env،
     *     والافتراضي gemini-2.5-flash ثم gemini-2.5-flash-lite).
     *  3) الأخطاء الدائمة (مفتاح غلط/طلب غلط) بتفشل فورًا من غير تكرار.
     *  4) إجمالي الانتظار محدود (GEMINI_RETRY_BUDGET ثانية) عشان الصفحة ما تعلقش.
     *  5) لو كله فشل، العميل يشوف رسالة واضحة بلغته، والخطأ التقني الخام
     *     بيفضل في اللوج وفي المفتاح technical_error.
     *
     * @param array $payload
     * @return array
     */
    private function sendRequest(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new Exception('مفتاح Gemini API غير مُهيأ — أضفه من الإعدادات (System Settings) أو متغير GEMINI_API_KEY في .env');
        }

        $models = $this->modelChain();
        $perModelAttempts = max(1, min(2, (int) $this->maxRetries));
        $budget = defined('GEMINI_RETRY_BUDGET') ? (float) GEMINI_RETRY_BUDGET : 40.0;
        $deadline = microtime(true) + $budget;

        $bumped = [];
        $lastError = null;      // آخر رسالة تقنية
        $lastCode = 0;
        $lastKind = 'unknown';  // busy | auth | bad_request | blocked | unknown

        foreach ($models as $mi => $model) {
            for ($attempt = 1; $attempt <= $perModelAttempts; $attempt++) {
                if ($mi > 0 || $attempt > 1) {
                    // لا نبدأ محاولة جديدة لو الميزانية الزمنية خلصت
                    if (microtime(true) >= $deadline) {
                        break 2;
                    }
                }

                [$httpCode, $body, $curlError] = $this->httpPost($this->urlFor($model), $payload);

                // ---- فشل شبكة / مهلة ----
                if ($curlError !== '') {
                    $lastError = 'cURL Error: ' . $curlError;
                    $lastCode = 0;
                    $lastKind = 'busy';
                    Logger::warning('Gemini transient network error', ['model' => $model, 'attempt' => $attempt, 'error' => $curlError]);
                    if ($attempt < $perModelAttempts) {
                        $this->pause($this->backoffMs($attempt), $deadline);
                    }
                    continue;
                }

                // ---- استجابة ناجحة ----
                if ($httpCode === 200) {
                    $data = json_decode((string) $body, true);
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                    if ($text === null) {
                        // 200 من غير نص: حجب أمان، أو MAX_TOKENS (الموديلات اللي بتفكّر بتصرف جزء من السقف على التفكير
                        // فمفيش نص يطلع). في الحالة التانية بنكرّر على نفس الموديل بسقف أكبر مرة واحدة.
                        $block = $data['promptFeedback']['blockReason'] ?? null;
                        $finish = $data['candidates'][0]['finishReason'] ?? null;
                        $curMax = (int) ($payload['generationConfig']['maxOutputTokens'] ?? 0);
                        if ($finish === 'MAX_TOKENS' && $curMax > 0 && $curMax < 16384 && empty($bumped[$model])) {
                            $bumped[$model] = true;
                            $payload['generationConfig']['maxOutputTokens'] = min(16384, $curMax * 4);
                            Logger::warning('Gemini MAX_TOKENS with no text: retrying with a higher limit', ['model' => $model, 'from' => $curMax, 'to' => $payload['generationConfig']['maxOutputTokens']]);
                            $attempt--;          // نفس المحاولة بسقف أكبر
                            continue;
                        }
                        $lastError = 'Invalid API response format' . ($block ? " (blockReason={$block})" : '') . ($finish ? " (finishReason={$finish})" : '');
                        $lastCode = 200;
                        $lastKind = $block ? 'blocked' : 'unknown';
                        Logger::warning('Gemini empty response', ['model' => $model, 'detail' => $lastError]);
                        // موديل تاني ممكن يرد، فنكمل على الموديل البديل بدل ما نوقف
                        break;
                    }

                    $tokensUsed = $data['usageMetadata']['totalTokenCount'] ?? 0;
                    $costPer1k = defined('GEMINI_COST_PER_1K_INPUT_TOKENS') ? GEMINI_COST_PER_1K_INPUT_TOKENS : 0.000125;

                    if ($mi > 0) {
                        Logger::warning('Gemini served by fallback model', ['primary' => $models[0], 'used' => $model]);
                    }

                    return [
                        'success' => true,
                        'data' => $text,
                        'tokens_used' => $tokensUsed,
                        'cost' => ($tokensUsed / 1000) * $costPer1k,
                        'http_code' => $httpCode,
                        'model' => $model,
                        'raw_response' => $data,
                    ];
                }

                // ---- خطأ HTTP ----
                $errorData = json_decode((string) $body, true);
                $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
                $lastError = "API Error (HTTP {$httpCode}): {$errorMessage}";
                $lastCode = $httpCode;

                if (in_array($httpCode, [429, 500, 502, 503, 504], true)) {
                    $lastKind = 'busy';
                    Logger::warning('Gemini transient API error', ['model' => $model, 'attempt' => $attempt, 'http' => $httpCode]);
                    if ($attempt < $perModelAttempts) {
                        $this->pause($this->backoffMs($attempt), $deadline);
                    }
                    continue; // نفس الموديل مرة كمان، وبعدها ننتقل للبديل
                }

                if ($httpCode === 404) {
                    // الموديل مش موجود/اتسحب: نجرّب البديل فورًا
                    $lastKind = 'bad_request';
                    Logger::warning('Gemini model not found', ['model' => $model]);
                    break;
                }

                // 400 / 401 / 403 ... أخطاء دائمة (مفتاح، صلاحيات، طلب غلط): مفيش فايدة من التكرار
                $lastKind = in_array($httpCode, [401, 403], true) ? 'auth'
                    : (($httpCode === 400 && stripos($errorMessage, 'API key') !== false) ? 'auth' : 'bad_request');
                Logger::error('Gemini permanent API error', ['model' => $model, 'http' => $httpCode, 'message' => $errorMessage]);
                break 2;
            }
        }

        Logger::error('Gemini request failed after retries', ['models' => $models, 'error' => $lastError, 'http' => $lastCode, 'kind' => $lastKind]);

        return [
            'success' => false,
            'error' => $this->friendlyError($lastKind),
            'diag_code' => $this->diagCode($lastKind, $lastCode, (string) $lastError),   // للسجلات والأدمن فقط، مش للعميل
            'error_kind' => $lastKind,
            'http_code' => $lastCode,
            'technical_error' => $lastError ?? 'Max retries exceeded',
        ];
    }

    /** كود قصير للدعم: GEM-400 / GEM-503 / GEM-EMPTY / GEM-NET (من غير أي بيانات حساسة) */
    private function diagCode(string $kind, int $http, string $technical): string
    {
        if ($http >= 400) {
            return 'GEM-' . $http;
        }
        if (stripos($technical, 'MAX_TOKENS') !== false) {
            return 'GEM-MAXTOK';
        }
        if ($kind === 'blocked') {
            return 'GEM-BLOCKED';
        }
        return $http === 200 ? 'GEM-EMPTY' : 'GEM-NET';
    }

    /** سلسلة الموديلات: الأساسي ثم البدائل (من غير تكرار) */
    private function modelChain(): array
    {
        if($this->exactModel)return [$this->model];
        $fallbacks = env('GEMINI_FALLBACK_MODELS');
        $list = ($fallbacks === null || $fallbacks === false || trim((string) $fallbacks) === '')
            // gemini-2.5-flash-lite اتقفل للحسابات الجديدة (Google بترجع 404)،
            // فبنستخدم الـ alias اللي Google بتحدّثه تلقائيًا لآخر نسخة lite.
            ? ['gemini-2.5-flash', 'gemini-flash-lite-latest']
            : array_map('trim', explode(',', (string) $fallbacks));

        $chain = [$this->model];
        foreach ($list as $m) {
            if ($m !== '' && !in_array($m, $chain, true) && preg_match('/^[A-Za-z0-9._-]+$/', $m)) {
                $chain[] = $m;
            }
        }
        return $chain;
    }

    private function urlFor(string $model): string
    {
        return GEMINI_API_URL . '/models/' . $model . ':generateContent';
    }

    /** تأخير بالمللي ثانية مع jitter عشان الطلبات المتزامنة ما تضربش في نفس اللحظة */
    private function backoffMs(int $attempt): int
    {
        $base = 1000 * (int) max(1, defined('GEMINI_RETRY_DELAY') ? GEMINI_RETRY_DELAY : 1);
        return (int) min(4000, $base * (2 ** ($attempt - 1)) + random_int(0, 700));
    }

    /** رسالة للعميل بلغته (الخطأ التقني الخام مش بيتعرض) */
    private function friendlyError(string $kind): string
    {
        $key = [
            'busy' => 'ai.error.busy',
            'auth' => 'ai.error.config',
            'blocked' => 'ai.error.blocked',
        ][$kind] ?? 'ai.error.generic';

        if (function_exists('t')) {
            $msg = t($key);
            if ($msg !== $key) {
                return $msg;
            }
        }
        return [
            'ai.error.busy' => 'AI is very busy right now. Please try again in a minute. You were not charged.',
            'ai.error.config' => 'The AI service is not available right now. Please contact support.',
            'ai.error.blocked' => 'The request could not be processed. Please rephrase and try again.',
            'ai.error.generic' => 'Something went wrong with the AI service. Please try again.',
        ][$key];
    }

    /** طلب HTTP واحد. protected عشان الاختبار يقدر يستبدله بمحاكاة. */
    protected function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-goog-api-key: ' . $this->apiKey],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);
        return [$code, $response === false ? '' : (string) $response, $err];
    }

    /** انتظار محدود بالميزانية الزمنية المتبقية */
    protected function pause(int $ms, float $deadline): void
    {
        $remaining = (int) (($deadline - microtime(true)) * 1000);
        if ($remaining > 0) {
            usleep(min($ms, $remaining) * 1000);
        }
    }

    /**
     * تسجيل استخدام الـ API
     * @param array $response
     * @param float $duration
     */
    /** @var array بيانات تتبّع للطلب الحالي (مش بتتبعت لـ Google) */
    private $meta = ['task' => null, 'user_id' => null];

    /** الحقول اللي Gemini بتقبلها في generationConfig. أي حاجة تانية بتتشال بدل ما تكسر الطلب. */
    private const GENERATION_CONFIG_KEYS = [
        'temperature', 'maxOutputTokens', 'topP', 'topK', 'candidateCount', 'stopSequences', 'responseMimeType',
        'responseSchema', 'responseJsonSchema', 'presencePenalty', 'frequencyPenalty', 'seed', 'responseLogprobs',
        'logprobs', 'thinkingConfig', 'responseModalities', 'speechConfig', 'mediaResolution',
    ];

    private function sanitizeGenerationConfig(array $config): array
    {
        $clean = array_intersect_key($config, array_flip(self::GENERATION_CONFIG_KEYS));
        $dropped = array_diff(array_keys($config), array_keys($clean));
        if ($dropped && !in_array('task', $dropped, true) && !in_array('user_id', $dropped, true)) {
            Logger::warning('Gemini: unsupported generationConfig keys dropped', ['keys' => array_values($dropped)]);
        }
        return $clean;
    }

    private function logUsage(array $response, float $duration): void
    {
        if (!$response['success']) {
            return;
        }

        try {
            $db = Database::getInstance();

            $sql = "INSERT INTO api_usage_logs (
                        user_id, api_type, feature, model, endpoint, status_code,
                        tokens_used, cost_in_usd, duration_ms
                    ) VALUES (
                        :user_id, 'gemini', :feature, :model, :endpoint, :status_code,
                        :tokens_used, :cost, :duration
                    )";

            $db->query($sql, [
                ':user_id' => $this->meta['user_id'] ?: null,
                ':feature' => $this->meta['task'] ?: null,
                ':model' => $response['model'] ?? $this->model,
                ':endpoint' => $response['model'] ?? $this->model,
                ':status_code' => $response['http_code'] ?? 200,
                ':tokens_used' => $response['tokens_used'] ?? 0,
                ':cost' => $response['cost'] ?? 0,
                ':duration' => $duration
            ]);

        } catch (Exception $e) {
            // تجاهل خطأ التسجيل
        }
    }

    /**
     * تغيير النموذج المستخدم
     * @param string $model
     */
    public function setModel(string $model): void
    {
        if (isset(GEMINI_AVAILABLE_MODELS[$model])) {
            $this->model = $model;
            // نفس تصحيح الـ constructor - كان بيعمل str_replace على نص
            // GEMINI_MODEL جوه GEMINI_API_URL، لكن GEMINI_API_URL أصلًا
            // مكانش فيه اسم موديل خالص، فكان بيرجع نفس الرابط الناقص.
            $this->apiUrl = GEMINI_API_URL . '/models/' . $model . ':generateContent';
        }
    }

    /**
     * تغيير مهلة الطلب
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * التحقق من صحة المفتاح
     * @return bool
     */
    public function validateApiKey(): bool
    {
        try {
            $testPrompt = "Hello, this is a test.";
            $response = $this->generateContent($testPrompt, ['_routing_bypass'=>true]);
            return $response['success'];

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * الحصول على قائمة النماذج المتاحة
     * @return array
     */
    public function getAvailableModels(): array
    {
        return GEMINI_AVAILABLE_MODELS;
    }

    /**
     * التحقق من صلاحية النموذج
     * @param string $model
     * @return bool
     */
    public function isModelValid(string $model): bool
    {
        return isset(GEMINI_AVAILABLE_MODELS[$model]);
    }
}
