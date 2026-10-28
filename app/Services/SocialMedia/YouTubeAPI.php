<?php

/**
 * Tourfecto - YouTube Data API v3 Client (Shorts)
 * رفع فيديوهات قصيرة (Shorts) على YouTube عبر resumable upload.
 * يحدد الفيديو كـ Short تلقائيًا (9:16 + #Shorts في الوصف).
 * @version 1.0.0
 */
class YouTubeAPI
{
    private string $accessToken;

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب
     * ['method','url','headers','body'] وترجع رد محاكى
     * ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    public function __construct(string $accessToken, ?callable $transport = null)
    {
        $this->accessToken = $accessToken;
        $this->transport = $transport;
    }

    /**
     * رفع ونشر فيديو على YouTube كـ Short (resumable upload).
     *
     * @param string $videoPath مسار ملف الفيديو المحلي الكامل
     * @param string $title
     * @param string $description
     * @param array  $tags
     * @param string $privacy 'public' | 'unlisted' | 'private'
     * @return array ['success'=>bool, 'video_id'=>?string, 'post_url'=>?string, 'error'=>?string]
     */
    public function uploadShort(string $videoPath, string $title, string $description = '', array $tags = [], string $privacy = 'public'): array
    {
        if (!file_exists($videoPath)) {
            return ['success' => false, 'error' => 'ملف الفيديو غير موجود: ' . $videoPath];
        }

        $fileSize = filesize($videoPath);
        if ($fileSize === 0) {
            return ['success' => false, 'error' => 'ملف الفيديو فارغ'];
        }

        $metadata = [
            'snippet' => [
                'title'       => $title,
                'description' => $description . "\n\n#Shorts",
                'tags'        => $tags,
                'categoryId'  => '19', // Travel & Events (منصة سياحة)
            ],
            'status' => [
                'privacyStatus'             => $privacy,
                'selfDeclaredMadeForKids'   => false,
            ],
        ];

        // Step 1: Initiate resumable upload session
        $initUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';
        $initHeaders = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: video/*',
            'X-Upload-Content-Length: ' . $fileSize,
        ];

        $ch = curl_init($initUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($metadata),
            CURLOPT_HTTPHEADER     => $initHeaders,
            CURLOPT_HEADER         => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Init Error: ' . $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $body = substr($response, strpos($response, "\r\n\r\n") + 4);
            $decoded = json_decode($body, true);
            return ['success' => false, 'error' => $decoded['error']['message'] ?? "YouTube init upload error (HTTP {$httpCode})"];
        }

        preg_match('/Location:\s*(.+)/i', $response, $matches);
        $uploadUrl = trim($matches[1] ?? '');

        if (!$uploadUrl) {
            return ['success' => false, 'error' => 'YouTube لم يرجع رابط رفع (Location header)'];
        }

        // Step 2: Upload video bytes
        $videoData = file_get_contents($videoPath);
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $videoData,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: video/*',
                'Content-Length: ' . strlen($videoData),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Upload Error: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
            return [
                'success' => false,
                'error'   => $decoded['error']['message'] ?? "YouTube upload error (HTTP {$httpCode})",
            ];
        }

        $videoId = $decoded['id'] ?? null;
        if (!$videoId) {
            return ['success' => false, 'error' => 'YouTube رجّع رد غير متوقع (مفيش video_id)'];
        }

        return [
            'success'  => true,
            'video_id' => $videoId,
            'post_url' => 'https://youtube.com/shorts/' . $videoId,
        ];
    }

    /**
     * فحص حالة معالجة الفيديو.
     * @return array ['success'=>bool, 'status'=>?string, 'error'=>?string]
     */
    public function checkVideoStatus(string $videoId): array
    {
        $url = "https://www.googleapis.com/youtube/v3/videos?id={$videoId}&part=processingDetails,status";
        $result = $this->request('GET', $url);

        if (!$result['success']) {
            return $result;
        }

        $items = $result['data']['items'] ?? [];
        if (empty($items[0])) {
            return ['success' => false, 'error' => 'الفيديو غير موجود على YouTube'];
        }

        $item = $items[0];
        $uploadStatus = $item['status']['uploadStatus'] ?? 'unknown';
        $processingStatus = $item['processingDetails']['processingStatus'] ?? 'unknown';

        if ($uploadStatus === 'failed') {
            return ['success' => true, 'status' => 'ERROR', 'error' => $item['status']['rejectionReason'] ?? 'Upload failed'];
        }

        if ($uploadStatus === 'processed' || $processingStatus === 'succeeded') {
            return ['success' => true, 'status' => 'FINISHED'];
        }

        if ($processingStatus === 'inProgress' || $uploadStatus === 'uploaded') {
            return ['success' => true, 'status' => 'IN_PROGRESS'];
        }

        return ['success' => true, 'status' => 'IN_PROGRESS'];
    }

    protected function request(string $method, string $url, array $data = []): array
    {
        try {
            $headers = [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
            ];
            $body = null;
            if ($method === 'POST' && !empty($data)) {
                $headers[] = 'Content-Type: application/json';
                $body = json_encode($data);
            }

            $result = $this->httpRequest($method, $url, $headers, $body);

            if ($result['error']) {
                return ['success' => false, 'error' => 'cURL Error: ' . $result['error']];
            }

            $decoded = json_decode($result['body'], true);
            $httpCode = $result['http_code'];

            if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
                return [
                    'success' => false,
                    'error'   => $decoded['error']['message'] ?? "YouTube API error (HTTP {$httpCode})",
                ];
            }

            return ['success' => true, 'data' => $decoded];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('YouTube API request failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تنفيذ طلب HTTP عبر الـ transport الوهمي (لو محقون) أو curl العادي.
     * نفس سلوك curl السابق بالظبط - لا تغيير في الإنتاج.
     * @return array ['body'=>string,'http_code'=>int,'error'=>?string]
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : null,
            ];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body' => (string) $response,
            'http_code' => (int) $httpCode,
            'error' => $curlError ?: null,
        ];
    }
}
