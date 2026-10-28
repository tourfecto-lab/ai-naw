<?php

/**
 * Tourfecto - Veo Video Generation Client
 * توليد فيديوهات قصيرة حقيقية (Veo 3.1 Fast) بنفس GEMINI_API_KEY
 * المستخدم فعلاً لكل خدمات الذكاء الاصطناعي في المنصة - مفيش حساب أو
 * مفتاح API جديد مطلوب. التوليد عملية غير متزامنة (long-running
 * operation) بتستغرق من دقيقة لحد ~6 دقايق، فالتصميم هنا مبني على
 * "بدء + فحص حالة" منفصلين (بدل استنى/sleep داخل نفس الطلب) عشان يتوافق
 * مع طابور المهام الحالي (GenerateVideoJob بيعيد جدولة نفسه للفحص - شوف
 * app/Jobs/GenerateVideoJob.php)، ومحدود بمهلة تنفيذ PHP القصيرة على
 * استضافة مشتركة عادةً.
 * @version 1.0.0
 */
class VeoClient
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var string */
    private $baseUrl;

    public function __construct(?string $model=null)
    {
        $this->apiKey = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('gemini_api_key', defined('GEMINI_API_KEY')?GEMINI_API_KEY:'')
            : (defined('GEMINI_API_KEY')?GEMINI_API_KEY:'');
        if(class_exists('AiServiceRouting'))$this->apiKey=AiServiceRouting::apiKey('veo');
        $this->model = $model ?? (defined('VEO_MODEL')?VEO_MODEL:'veo-3.1-fast-generate-preview');
        $this->baseUrl = defined('GEMINI_API_URL')?rtrim(GEMINI_API_URL,'/'):'https://generativelanguage.googleapis.com/v1beta'; // https://generativelanguage.googleapis.com/v1beta
    }

    /**
     * بدء عملية توليد فيديو جديدة (نصّ فقط - text-to-video).
     * @param string $prompt وصف الفيديو (بعد إضافة الأسلوب/الأبعاد المطلوبة)
     * @param string $aspectRatio '16:9' | '9:16'
     * @param int    $durationSeconds 4 | 6 | 8
     * @return array ['success'=>bool, 'operation_name'=>?string, 'error'=>?string]
     */
    public static function supportsResolution(string $resolution,?string $model=null): bool
    {
        if($model===null&&class_exists('AiServiceRouting')){$policy=AiServiceRouting::policy('creative_studio_video');$model=$policy['models']['veo']??null;}
        $model=$model??(defined('VEO_MODEL')?VEO_MODEL:'veo-3.1-fast-generate-preview');
        return $resolution==='720p'||(str_starts_with($model,'veo-3.1-')&&!str_contains($model,'lite')&&in_array($resolution,['1080p','4k'],true));
    }
    public static function generationPayload(string $prompt,string $ratio,int $duration,array $settings=[]): array
    {
        $resolution=$settings['resolution']??'720p';$reference=$settings['reference']??null;
        if(!in_array($ratio,['16:9','9:16'],true)||!in_array($duration,[4,6,8],true)||!is_string($resolution)||!self::supportsResolution($resolution,$settings['model']??null)||(($resolution!=='720p'||$reference)&&$duration!==8))throw new InvalidArgumentException('Invalid video settings');
        $instance=['prompt'=>$prompt];if($reference){if(!is_array($reference))throw new InvalidArgumentException('Invalid reference');ReferenceMedia::validateInline($reference);$instance['image']=['inlineData'=>$reference];}
        $parameters=['aspectRatio'=>$ratio,'durationSeconds'=>$duration,'resolution'=>$resolution,'personGeneration'=>$reference?'allow_adult':'allow_all'];
        $negative=$settings['negative_prompt']??'';if(is_string($negative)&&trim($negative)!=='')$parameters['negativePrompt']=mb_substr(trim($negative),0,1000);
        return ['instances'=>[$instance],'parameters'=>$parameters];
    }
    public function startGeneration(string $prompt, string $aspectRatio = '16:9', int $durationSeconds = 8, array $settings = []): array
    {
        if($this->apiKey==='')return ['success'=>false,'error'=>'Video provider not configured'];
        if(!in_array($aspectRatio,['16:9','9:16'],true)||!in_array($durationSeconds,[4,6,8],true))return ['success'=>false,'error'=>'Invalid video settings'];
        $url = $this->baseUrl . '/models/' . $this->model . ':predictLongRunning';

        if(!isset($settings['model']))$settings['model']=$this->model;
        try{$payload=self::generationPayload($prompt,$aspectRatio,$durationSeconds,$settings);}catch(InvalidArgumentException $e){return ['success'=>false,'error'=>'Invalid video settings'];}

        $result = $this->request('POST', $url, $payload);

        if (!$result['success']) {
            return $result;
        }

        $operationName = $result['data']['name'] ?? null;
        if (!$operationName) {
            return ['success' => false, 'error' => 'رد غير متوقع من Veo - مفيش اسم عملية (operation name)'];
        }

        return ['success' => true, 'operation_name' => $operationName];
    }

    /**
     * فحص حالة عملية توليد الفيديو الحالية.
     * @param string $operationName الاسم اللي رجع من startGeneration()
     * @return array ['success'=>bool, 'done'=>bool, 'video_uri'=>?string, 'error'=>?string]
     */
    public function checkOperation(string $operationName): array
    {
        if(!preg_match('#^models/[A-Za-z0-9._-]+/operations/[A-Za-z0-9_-]+$#D',$operationName))return ['success'=>false,'done'=>true,'error'=>'Invalid operation reference'];
        // operationName بييجي بالشكل "models/xxx/operations/yyy" - كامل المسار
        $url = $this->baseUrl . '/' . ltrim($operationName, '/');

        $result = $this->request('GET', $url, null);

        if (!$result['success']) {
            return ['success' => false, 'done' => false, 'error' => $result['error']];
        }

        $data = $result['data'];
        $done = (bool) ($data['done'] ?? false);

        if (!$done) {
            return ['success' => true, 'done' => false];
        }

        // فيديو فشل بسبب سياسات السلامة أو خطأ من الموديل
        if (!empty($data['error'])) {
            return ['success' => false, 'done' => true, 'error' => $data['error']['message'] ?? 'فشل توليد الفيديو من Veo'];
        }

        $videoUri = $data['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;
        $filteredCount = $data['response']['generateVideoResponse']['raiMediaFilteredCount'] ?? 0;

        if (!$videoUri) {
            $reason = $data['response']['generateVideoResponse']['raiMediaFilteredReasons'][0] ?? null;
            $msg = $filteredCount > 0
                ? ('تم حجب الفيديو بواسطة فلاتر السلامة' . ($reason ? (": {$reason}") : ''))
                : 'اكتملت العملية لكن بدون رابط فيديو صالح';
            return ['success' => false, 'done' => true, 'error' => $msg];
        }

        return ['success' => true, 'done' => true, 'video_uri' => $videoUri];
    }

    /**
     * تحميل بيانات الفيديو الفعلية (bytes) من الرابط المؤقت اللي رجع من
     * checkOperation() بعد الاكتمال.
     * @return array ['success'=>bool, 'data'=>?string binary, 'error'=>?string]
     */
    public static function allowedDownloadUrl(string $url): bool
    {
        $p=parse_url($url);if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||(isset($p['port'])&&$p['port']!==443))return false;
        $host=strtolower($p['host']??'');
        return $host==='generativelanguage.googleapis.com'||$host==='storage.googleapis.com'||preg_match('/^[a-z0-9.-]+\.googleusercontent\.com$/D',$host)===1;
    }
    public function downloadVideo(string $videoUri): array
    {
        try{
            for($redirects=0;$redirects<=4;$redirects++){
                if(!self::allowedDownloadUrl($videoUri))throw new RuntimeException('Untrusted video download URL');
                $host=strtolower((string)parse_url($videoUri,PHP_URL_HOST));$body='';$location=null;
                $ch=curl_init($videoUri);
                curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>120,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_HTTPHEADER=>$host==='generativelanguage.googleapis.com'?['x-goog-api-key: '.$this->apiKey]:[],
                    CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){if(stripos($line,'location:')===0)$location=trim(substr($line,9));return strlen($line);},
                    CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>150*1024*1024)return 0;$body.=$chunk;return strlen($chunk);}
                ]);
                $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
                if($ok===false)throw new RuntimeException('Video transfer failed or exceeded size limit');
                if(in_array($http,[301,302,303,307,308],true)&&is_string($location)){
                    if(str_starts_with($location,'/'))$location='https://'.$host.$location;
                    $videoUri=$location;continue;
                }
                if($http!==200||$body==='')throw new RuntimeException('Video download failed');
                return ['success'=>true,'data'=>$body];
            }
            throw new RuntimeException('Video redirect limit exceeded');
        }catch(Throwable $e){Logger::error('Video transfer failed',['message'=>$e->getMessage()]);return ['success'=>false,'error'=>'Video transfer could not complete'];}
    }

    /** @return array ['success'=>bool, 'data'=>?array, 'error'=>?string] */
    private function request(string $method, string $url, ?array $payload): array
    {
        try {
            $ch = curl_init($url);
            $headers = ['x-goog-api-key: ' . $this->apiKey, 'Content-Type: application/json'];

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Tourfecto/1.0',
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($payload);
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('cURL Error: ' . $curlError);
            }

            $data = json_decode((string)$response, true);
            if(!is_array($data))throw new RuntimeException('Invalid video provider response');

            if ($httpCode < 200 || $httpCode >= 300) {
                $errorMessage = $data['error']['message'] ?? "Veo API Error (HTTP {$httpCode})";
                throw new Exception($errorMessage);
            }

            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            Logger::error('Veo API Error', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
