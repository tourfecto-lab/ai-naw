<?php

/**
 * Tourfecto - WordPress Publisher
 * نشر مقال جاهز مباشرة على موقع ووردبريس الخاص بالعميل عن طريق
 * WP REST API (wp-json/wp/v2/posts) باستخدام Application Passwords
 * (ميزة أصلية في ووردبريس من نسخة 5.6+ ، مش إضافة/plugin خارجي).
 * @version 1.0.0
 *
 * ليه Application Passwords تحديدًا مش OAuth؟ لأن مواقع العملاء هنا
 * مخصوصة/متفرقة (مش منصة موحدة زي Google) - مفيش OAuth client مركزي
 * ممكن نسجله. Application Passwords بديل رسمي من ووردبريس نفسه، عبارة
 * عن يوزر + باسورد تطبيق العميل بيولّده بنفسه من لوحة تحكم موقعه
 * (Users → Profile → Application Passwords) ويلزقه هنا مرة واحدة.
 */
class WordPressPublisher
{
    private int $timeout = 30;

    /** @var callable|null حقنة اختيارية للاختبارات - بتستقبل المصفوفة الكاملة للطلب وترجع رد محاكى */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * تجربة الاتصال (بنجيب بيانات المستخدم الحالي /users/me) للتأكد إن
     * الرابط + اليوزر + الباسورد صحيحين قبل ما نحفظهم.
     */
    public function testConnection(string $siteUrl, string $username, string $appPassword): array
    {
        $response = $this->request('GET', $siteUrl, '/wp-json/wp/v2/users/me', $username, $appPassword);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'user' => [
                'id' => $response['data']['id'] ?? null,
                'name' => $response['data']['name'] ?? null,
            ],
        ];
    }

    /**
     * نشر مقال جديد كـ post على ووردبريس.
     * @param string $status 'publish' (نشر مباشر) أو 'draft' (مسودة يراجعها العميل الأول)
     * @return array ['success'=>bool, 'post_id'=>?int, 'url'=>?string, 'error'=>?string]
     */
    public function createPost(string $siteUrl, string $username, string $appPassword, string $title, string $htmlContent, string $excerpt = '', string $status = 'publish', ?int $featuredMedia = null, ?string $slug = null): array
    {
        $payload = [
            'tourfecto_source' => 'content-quality-v1',
            'title' => $title,
            'content' => $htmlContent,
            'excerpt' => $excerpt,
            'status' => $status, // publish|draft
        ];
        // رابط نظيف بالكلمة المفتاحية (بدل ما ووردبريس يعمله من العنوان الطويل)
        if ($slug !== null && preg_match('/^[a-z0-9\x{0600}-\x{06FF}][a-z0-9\x{0600}-\x{06FF}-]{1,100}$/u', $slug)) {
            $payload['slug'] = $slug;
        }
        if ($featuredMedia !== null && $featuredMedia > 0) {
            $payload['featured_media'] = $featuredMedia;
        }
        $response = $this->request('POST', $siteUrl, '/wp-json/wp/v2/posts', $username, $appPassword, $payload);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'post_id' => $response['data']['id'] ?? null,
            'url' => $response['data']['link'] ?? null,
        ];
    }

    /**
     * رفع صورة لمكتبة وسائط WordPress (POST /wp/v2/media بجسم ثنائي + Content-Disposition)، وبعدها نحدّث النص البديل والعنوان.
     * محتاج صلاحية upload_files (كاتب أو أعلى). الفشل في تحديث النص البديل مش بيفشّل الرفع.
     * @return array{success:bool, media_id?:int, url?:string, error?:string}
     */
    public function uploadMedia(string $siteUrl, string $username, string $appPassword, string $binary, string $filename, string $mime, string $alt = '', string $title = ''): array
    {
        $safeName = preg_replace('/[^A-Za-z0-9._\-]+/', '-', $filename) ?: 'image.jpg';
        $response = $this->request('POST', $siteUrl, '/wp-json/wp/v2/media', $username, $appPassword, [], $binary, $mime, [
            'Content-Disposition: attachment; filename="' . $safeName . '"',
        ]);
        if (!$response['success']) {
            return $response;
        }
        $mediaId = (int) ($response['data']['id'] ?? 0);
        if ($mediaId <= 0) {
            return ['success' => false, 'error' => 'WordPress did not return a media id'];
        }
        $meta = array_filter(['alt_text' => $alt, 'title' => $title], function ($v) {
            return $v !== '';
        });
        if ($meta) {
            $this->request('POST', $siteUrl, '/wp-json/wp/v2/media/' . $mediaId, $username, $appPassword, $meta);
        }
        return ['success' => true, 'media_id' => $mediaId, 'url' => $response['data']['source_url'] ?? null];
    }

    /** يحط صورة موجودة في المكتبة كصورة بارزة لمقال منشور. */
    public function setFeaturedImage(string $siteUrl, string $username, string $appPassword, int $postId, int $mediaId): array
    {
        $response = $this->request('POST', $siteUrl, '/wp-json/wp/v2/posts/' . $postId, $username, $appPassword, ['featured_media' => $mediaId]);
        return $response['success'] ? ['success' => true, 'post_id' => $response['data']['id'] ?? $postId, 'url' => $response['data']['link'] ?? null] : $response;
    }

    /**
     * تحديث post موجود (لو المقال اتنشر قبل كده وعايزين نعيد النشر بعد تعديل).
     */
    public function updatePost(string $siteUrl, string $username, string $appPassword, int $postId, string $title, string $htmlContent, string $excerpt = '', ?int $featuredMedia = null): array
    {
        $payload = [
            'title' => $title,
            'content' => $htmlContent,
            'excerpt' => $excerpt,
        ];
        if ($featuredMedia !== null && $featuredMedia > 0) {
            $payload['featured_media'] = $featuredMedia;
        }
        $response = $this->request('POST', $siteUrl, '/wp-json/wp/v2/posts/' . $postId, $username, $appPassword, $payload);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'post_id' => $response['data']['id'] ?? null,
            'url' => $response['data']['link'] ?? null,
        ];
    }

    public function postUrl(string $siteUrl,string $username,string $appPassword,int $postId): array
    {
        $r=$this->request('GET',$siteUrl,'/wp-json/wp/v2/posts/'.$postId,$username,$appPassword);
        return $r['success'] && (int)($r['data']['id']??0)===$postId && is_string($r['data']['link']??null)
            ? ['success'=>true,'url'=>$r['data']['link']] : ['success'=>false];
    }

    /**
     * بيكتب وصف (alt) لصورة في مكتبة الوسائط، بس لو الصورة مالهاش وصف أصلًا.
     * الصورة بتتعرف من اسم الملف (من غير مقاس WordPress زي -1024x683).
     */
    public function setMediaAltBySource(string $siteUrl, string $username, string $appPassword, string $src, string $alt): array
    {
        $key = static fn(string $u) => strtolower(preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', basename((string) parse_url($u, PHP_URL_PATH))));
        $file = $key($src);
        $search = preg_replace('/\.[a-z0-9]+$/i', '', $file);
        if ($search === '' || trim($alt) === '') {
            return ['success' => false, 'state' => 'invalid'];
        }
        $found = $this->request('GET', $siteUrl, '/wp-json/wp/v2/media?per_page=20&_fields=id,source_url,alt_text&search=' . rawurlencode($search), $username, $appPassword);
        if (!$found['success'] || !is_array($found['data'])) {
            return ['success' => false, 'state' => 'unavailable'];
        }
        foreach ($found['data'] as $item) {
            if (!is_array($item) || $key((string) ($item['source_url'] ?? '')) !== $file) {
                continue;
            }
            if (trim((string) ($item['alt_text'] ?? '')) !== '') {
                return ['success' => true, 'state' => 'already_described'];
            }
            $saved = $this->request('POST', $siteUrl, '/wp-json/wp/v2/media/' . (int) $item['id'], $username, $appPassword, ['alt_text' => mb_substr(trim($alt), 0, 125)]);
            return ['success' => (bool) $saved['success'], 'state' => $saved['success'] ? 'described' : 'save_failed', 'media_id' => (int) $item['id']];
        }
        return ['success' => false, 'state' => 'not_in_media_library'];
    }

    /** Plugin signs a short-lived preview bound to the authenticated creator and post. */
    public function setSeoFields(string $siteUrl,string $username,string $appPassword,int $postId,array $fields): array
    {
        $preview=$this->request('POST',$siteUrl,'/wp-json/tourfecto-editor/v1/content-seo-preview',$username,$appPassword,['post_id'=>$postId,'fields'=>$fields]);
        if (!$preview['success']) return $preview;
        $token=$preview['data']['token']??null;
        if (!is_string($token) || $token==='') return ['success'=>false,'error'=>'Plugin did not return a signed SEO preview. Update Tourfecto Connect.'];
        $applied=$this->request('POST',$siteUrl,'/wp-json/tourfecto-editor/v1/content-seo-apply',$username,$appPassword,['token'=>$token]);
        if (!$applied['success']) return $applied;
        return !empty($applied['data']['success']) && (int)($applied['data']['post_id']??0)===$postId
            ? ['success'=>true,'post_id'=>$postId] : ['success'=>false,'error'=>'Plugin did not confirm SEO fields for this post.'];
    }

    private function request(string $method, string $siteUrl, string $path, string $username, string $appPassword, array $data = [], ?string $rawBody = null, ?string $rawContentType = null, array $extraHeaders = []): array
    {
        $url = rtrim($siteUrl, '/') . $path;

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($username . ':' . $appPassword),
        ];

        if ($rawBody !== null) {
            $headers[] = 'Content-Type: ' . ($rawContentType ?: 'application/octet-stream');
            foreach ($extraHeaders as $h) {
                $headers[] = $h;
            }
            $body = $rawBody;
        } else {
            if (!empty($data)) {
                $headers[] = 'Content-Type: application/json';
            }
            $body = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        }

        // الاختبارات تحقن transport وهمي (بدون curl) عبر الـ constructor
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]);
            return $this->buildResult($fake);
        }

        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $rawBody !== null ? max($this->timeout, 60) : $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الوصول لموقعك: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => 'بيانات الدخول غلط أو الحساب مالوش صلاحية نشر (تأكد من اليوزر و Application Password)'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['message'] ?? "خطأ غير متوقع (HTTP {$httpCode})";
            return ['success' => false, 'error' => "WordPress API Error: {$errorMessage}", 'http_code' => $httpCode];
        }

        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'رد غير متوقع من الموقع - تأكد إن الرابط صحيح وإن REST API مفعّل'];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * تحويل رد الـ transport الوهمي (نفس بنية رد curl) لنتيجة موحّدة.
     * @param array $fake ['body'=>string, 'http_code'=>int, 'error'=>?string]
     */
    private function buildResult(array $fake): array
    {
        $httpCode = (int) ($fake['http_code'] ?? 0);
        $curlError = $fake['error'] ?? null;

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الوصول لموقعك: ' . $curlError];
        }

        $decoded = json_decode((string) ($fake['body'] ?? ''), true);

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => 'بيانات الدخول غلط أو الحساب مالوش صلاحية نشر (تأكد من اليوزر و Application Password)'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['message'] ?? "خطأ غير متوقع (HTTP {$httpCode})";
            return ['success' => false, 'error' => "WordPress API Error: {$errorMessage}", 'http_code' => $httpCode];
        }

        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'رد غير متوقع من الموقع - تأكد إن الرابط صحيح وإن REST API مفعّل'];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * تحويل مبسّط من Markdown (الصيغة اللي بيتولد بيها المقال) لـ HTML
     * صالح للنشر على ووردبريس. مش parser كامل، بس كافي لعناوين/فقرات/Bold
     * اللي بيولدها الـ AI Engine.
     */
    public static function markdownToHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        $escaped = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $escaped);
        $escaped = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $escaped);
        $escaped = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $escaped);
        $escaped = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);

        $blocks = preg_split('/\n{2,}/', $escaped);
        $html = array_map(function ($block) {
            $block = trim($block);
            if ($block === '') {
                return '';
            }
            if (strpos($block, '<h') === 0) {
                return $block;
            }
            return '<p>' . nl2br($block) . '</p>';
        }, $blocks);

        return implode("\n", array_filter($html));
    }
}
