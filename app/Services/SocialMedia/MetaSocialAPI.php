<?php

/**
 * Tourfecto - Meta Social Publishing API Client
 * نشر منشورات حقيقية على صفحة فيسبوك وحساب انستجرام العميل عن طريق
 * Meta Graph API الرسمي. نفس تطبيق Meta المستخدم لـ Meta Ads (نفس
 * META_APP_ID/META_APP_SECRET)، بس بصلاحيات إضافية (pages_manage_posts,
 * instagram_content_publish) اتضافت في MetaOAuthClient.
 *
 * v2.0 - تطوير الموثوقية:
 *  - أخطاء منظّمة (code/subcode/fbtrace_id/category/transient/definitive) بدل النص بس
 *  - Retry بـ exponential backoff + jitter للقراءات والأخطاء المؤقتة فقط
 *    (طلبات النشر POST ما بتتعادش لو النتيجة غامضة عشان ما نكرّرش منشور)
 *  - التوكن في Authorization header (مش في الـ URL) + appsecret_proof
 *  - قراءة X-App-Usage / X-Business-Use-Case-Usage للتهدئة قبل الحظر
 *  - publishReelToFacebookPage (3 مراحل) + getFacebookVideoStatus
 *  - مقياس الوصول الجديد post_total_media_view_unique (بدل post_impressions_unique المحذوف)
 *  - صور متعددة، Stories، تعليق أول، تعديل/حذف، مصالحة المنشورات، فحص التوكن
 * @version 2.0.0
 */
require_once __DIR__ . '/MetaApiError.php';
class MetaSocialAPI
{
    /** curl errno اللي معناها إن الطلب ما وصلش لـ Meta أصلًا (آمن نعيده حتى لو POST) */
    private const NOT_SENT_ERRNOS = [6, 7, 35];
    private const MAX_READ_ATTEMPTS = 3;

    private string $apiVersion;
    private string $accessToken;
    private ?string $appSecret;

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب
     * ['method','url','headers','body','timeout'] وترجع رد محاكى
     * ['body'=>string,'http_code'=>int,'error'=>?string,'errno'=>?int,'headers'=>array]
     */
    private $transport;

    /** @var callable دالة النوم (بتتحقن في الاختبارات عشان ما نستناش فعلًا) */
    private $sleeper;

    /** @var array آخر استهلاك شفناه من هيدرز Meta */
    private array $usage = ['call_count' => 0, 'total_time' => 0, 'total_cputime' => 0, 'regain_minutes' => 0];

    public function __construct(string $accessToken, ?callable $transport = null, ?callable $sleeper = null, ?string $appSecret = null)
    {
        $this->apiVersion = env('META_API_VERSION') ?: 'v25.0';
        $this->accessToken = $accessToken;
        $this->transport = $transport;
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        if ($appSecret === null) {
            $appSecret = class_exists('MetaOAuthClient')
                ? MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET')
                : (string) env('META_APP_SECRET', '');
        }
        $this->appSecret = $appSecret !== '' ? $appSecret : null;
    }

    /** نسخة بنفس الإعدادات لكن بتوكن تاني (مثلًا توكن الصفحة) */
    private function withToken(string $token): self
    {
        return new self($token, $this->transport, $this->sleeper, $this->appSecret ?? '');
    }

    /**
     * آخر استهلاك قرأناه من Meta. app_pct = أعلى نسبة استخدام (0-100)،
     * regain_minutes = الوقت المقدّر لاسترجاع الوصول لو اتحظرنا.
     * @return array{app_pct:int,regain_minutes:int,throttle:bool}
     */
    public function usage(): array
    {
        $pct = max((int) $this->usage['call_count'], (int) $this->usage['total_time'], (int) $this->usage['total_cputime']);
        return ['app_pct' => $pct, 'regain_minutes' => (int) $this->usage['regain_minutes'], 'throttle' => $pct >= 90];
    }

    /**
     * قائمة صفحات فيسبوك اللي صاحب التوكن أدمن عليها، ومعاها حساب
     * انستجرام بيزنس المربوط بيها لو موجود. كل صفحة برجع معاها
     * page_access_token خاص بيها (مختلف عن توكن المستخدم، وده اللي
     * بيتستخدم فعليًا للنشر).
     * @return array ['success'=>bool, 'pages'=>[['id','name','access_token','instagram_id']], 'error'=>?]
     */
    public function listPages(): array
    {
        $fields = 'id,name,access_token,instagram_business_account{id,username}';

        // 1) الصفحات المباشرة (me/accounts) - مع التصفّح (pagination). قبل كده كنا بناخد
        //    أول دفعة بس (25 صفحة تقريبًا)، فباقي الصفحات كانت بتختفي من قايمة النشر.
        $first = $this->get('me/accounts', ['fields' => $fields, 'limit' => 100]);
        if (!$first['success']) {
            return $first;
        }

        $byId = [];
        $this->collectPages($first, $byId);
        $this->followPaging('me/accounts', ['fields' => $fields, 'limit' => 100], $first, $byId);

        // 2) صفحات الـ Business Portfolio (Business Manager): ما بتظهرش في me/accounts
        //    لو الصفحة مملوكة لبورتفوليو. بنجيبها لو صلاحية business_management موجودة -
        //    ولو مش موجودة بنكمّل بالنتيجة اللي فوق من غير ما نفشل.
        $biz = $this->get('me/businesses', ['fields' => 'id,name', 'limit' => 50]);
        if ($biz['success']) {
            foreach (($biz['data']['data'] ?? []) as $b) {
                $bizId = $b['id'] ?? null;
                if (!$bizId) {
                    continue;
                }
                foreach (['owned_pages', 'client_pages'] as $edge) {
                    $q = ['fields' => $fields, 'limit' => 100];
                    $res = $this->get("{$bizId}/{$edge}", $q);
                    if (!$res['success']) {
                        continue;
                    }
                    $this->collectPages($res, $byId);
                    $this->followPaging("{$bizId}/{$edge}", $q, $res, $byId);
                }
            }
        }

        // 3) أي صفحة ملهاش page token لسه: نحاول نجيبه مباشرة بتوكن المستخدم
        foreach ($byId as $id => $page) {
            if ($page['access_token'] === '') {
                $tok = $this->get((string) $id, ['fields' => 'access_token']);
                if ($tok['success'] && !empty($tok['data']['access_token'])) {
                    $byId[$id]['access_token'] = (string) $tok['data']['access_token'];
                }
            }
        }

        return ['success' => true, 'pages' => array_values($byId)];
    }

    /** يضيف صفحات رد Graph API لمصفوفة الصفحات (من غير تكرار حسب الـ id) */
    private function collectPages(array $result, array &$byId): void
    {
        foreach (($result['data']['data'] ?? []) as $p) {
            $id = $p['id'] ?? null;
            if (!$id) {
                continue;
            }
            $row = [
                'id' => $id,
                'name' => $p['name'] ?? $id,
                'access_token' => $p['access_token'] ?? '',
                'instagram_id' => $p['instagram_business_account']['id'] ?? null,
                'instagram_username' => $p['instagram_business_account']['username'] ?? null,
            ];
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            // ندمج: نحتفظ بالتوكن وحساب انستجرام لو الدفعة الجديدة فيها حاجة ناقصة
            foreach (['access_token', 'instagram_id', 'instagram_username'] as $f) {
                if (empty($byId[$id][$f]) && !empty($row[$f])) {
                    $byId[$id][$f] = $row[$f];
                }
            }
        }
    }

    /** يكمّل صفحات النتائج (paging.next) لحد الآخر بحد أقصى 20 دفعة */
    private function followPaging(string $path, array $query, array $last, array &$byId): void
    {
        $guard = 0;
        while (!empty($last['data']['paging']['next']) && !empty($last['data']['paging']['cursors']['after']) && $guard++ < 20) {
            $query['after'] = $last['data']['paging']['cursors']['after'];
            $last = $this->get($path, $query);
            if (!$last['success']) {
                return;
            }
            $this->collectPages($last, $byId);
        }
    }

    // ------------------------------------------------------------------
    // فيسبوك - النشر
    // ------------------------------------------------------------------

    /**
     * نشر منشور نصي (مع صورة اختيارية) على صفحة فيسبوك.
     * @param string|null $imageUrl رابط صورة عام (https)
     * @param array $options ['scheduled_publish_time'=>int unix] جدولة أصلية عند فيسبوك (تشتغل حتى لو الكرون وقع)
     * @return array ['success'=>bool, 'post_id'=>?string, 'post_url'=>?string, 'error'=>?string, ...]
     */
    public function publishToFacebookPage(string $pageId, string $pageAccessToken, string $message, ?string $imageUrl = null, array $options = []): array
    {
        $endpoint = $imageUrl ? "{$pageId}/photos" : "{$pageId}/feed";
        $payload = $imageUrl
            ? ['url' => $imageUrl, 'caption' => $message]
            : ['message' => $message];
        if (!empty($options['alt_text']) && $imageUrl) {
            $payload['alt_text_custom'] = mb_substr((string) $options['alt_text'], 0, 1000);
        }
        $scheduled = $this->applySchedule($payload, $options);
        if ($scheduled !== null) {
            return $scheduled;
        }

        $result = $this->post($endpoint, $payload, $pageAccessToken);
        if (!$result['success']) {
            return $result;
        }

        $postId = $result['data']['post_id'] ?? $result['data']['id'] ?? null;
        return [
            'success' => true,
            'post_id' => $postId,
            'post_url' => $postId ? "https://www.facebook.com/{$postId}" : null,
            'scheduled' => isset($payload['scheduled_publish_time']),
        ];
    }

    /** منشور برابط (Preview تلقائي من فيسبوك) */
    public function publishLinkToFacebookPage(string $pageId, string $pageAccessToken, string $message, string $link, array $options = []): array
    {
        $payload = ['message' => $message, 'link' => $link];
        $scheduled = $this->applySchedule($payload, $options);
        if ($scheduled !== null) {
            return $scheduled;
        }
        $result = $this->post("{$pageId}/feed", $payload, $pageAccessToken);
        if (!$result['success']) {
            return $result;
        }
        $postId = $result['data']['id'] ?? null;
        return ['success' => true, 'post_id' => $postId, 'post_url' => $postId ? "https://www.facebook.com/{$postId}" : null];
    }

    /**
     * منشور بعدة صور (attached_media): كل صورة بتترفع unpublished وبعدين منشور واحد بيربطهم.
     * لو فشلت صورة في النص: الصور غير المنشورة اللي اترفعت ما بتظهرش لأي حد (آمنة) ونرجّع الخطأ.
     * @param string[] $imageUrls من 2 لـ 10
     */
    public function publishPhotosToFacebookPage(string $pageId, string $pageAccessToken, string $message, array $imageUrls, array $options = []): array
    {
        $imageUrls = array_values(array_filter($imageUrls));
        if (count($imageUrls) < 2 || count($imageUrls) > 10) {
            return $this->localError('منشور الصور المتعددة محتاج من 2 لـ 10 صور', MetaApiError::PARAM);
        }
        $attached = [];
        foreach ($imageUrls as $url) {
            $up = $this->post("{$pageId}/photos", ['url' => $url, 'published' => 'false', 'temporary' => 'true'], $pageAccessToken);
            if (!$up['success']) {
                return $up;
            }
            $attached[] = ['media_fbid' => (string) ($up['data']['id'] ?? '')];
        }
        $payload = ['message' => $message, 'attached_media' => json_encode($attached)];
        $scheduled = $this->applySchedule($payload, $options);
        if ($scheduled !== null) {
            return $scheduled;
        }
        $result = $this->post("{$pageId}/feed", $payload, $pageAccessToken);
        if (!$result['success']) {
            return $result;
        }
        $postId = $result['data']['id'] ?? null;
        return ['success' => true, 'post_id' => $postId, 'post_url' => $postId ? "https://www.facebook.com/{$postId}" : null];
    }

    /** Story بصورة على صفحة فيسبوك (photo_stories) */
    public function publishPhotoStoryToFacebookPage(string $pageId, string $pageAccessToken, string $imageUrl): array
    {
        $up = $this->post("{$pageId}/photos", ['url' => $imageUrl, 'published' => 'false'], $pageAccessToken);
        if (!$up['success']) {
            return $up;
        }
        $photoId = (string) ($up['data']['id'] ?? '');
        if ($photoId === '') {
            return $this->localError('تعذر رفع صورة الـ Story', MetaApiError::UNKNOWN);
        }
        $story = $this->post("{$pageId}/photo_stories", ['photo_id' => $photoId], $pageAccessToken);
        if (!$story['success']) {
            return $story;
        }
        return ['success' => true, 'post_id' => (string) ($story['data']['post_id'] ?? $photoId), 'post_url' => null];
    }

    /**
     * Story بفيديو على صفحة فيسبوك (video_stories) - نفس مراحل الـ Reel: start ← رفع من رابط ← finish.
     * فيسبوك بيعالج الفيديو بعد finish؛ الرد فيه post_id لما يتقبل.
     */
    public function publishVideoStoryToFacebookPage(string $pageId, string $pageAccessToken, string $videoUrl): array
    {
        $start = $this->post("{$pageId}/video_stories", ['upload_phase' => 'start'], $pageAccessToken);
        if (!$start['success']) {
            return $start + ['stage' => 'start'];
        }
        $videoId = (string) ($start['data']['video_id'] ?? '');
        $uploadUrl = (string) ($start['data']['upload_url'] ?? '');
        if ($videoId === '' || $uploadUrl === '') {
            return $this->localError('بدء رفع فيديو الـ Story فشل: فيسبوك ما رجّعش video_id/upload_url', MetaApiError::UNKNOWN) + ['stage' => 'start'];
        }
        $host = strtolower((string) parse_url($uploadUrl, PHP_URL_HOST));
        if (parse_url($uploadUrl, PHP_URL_SCHEME) !== 'https' || !($host === 'facebook.com' || str_ends_with($host, '.facebook.com'))) {
            return $this->localError('upload_url غير موثوق: ' . $host, MetaApiError::PARAM) + ['stage' => 'start'];
        }
        $upload = $this->request('POST', '', [], [], [
            'url' => $uploadUrl,
            'headers' => ['Authorization: OAuth ' . $pageAccessToken, 'file_url: ' . $videoUrl],
            'raw_token' => true,
            'timeout' => 120,
        ]);
        if (!$upload['success']) {
            return $upload + ['stage' => 'upload', 'video_id' => $videoId];
        }
        $finish = $this->post("{$pageId}/video_stories", ['upload_phase' => 'finish', 'video_id' => $videoId], $pageAccessToken);
        if (!$finish['success']) {
            return $finish + ['stage' => 'finish', 'video_id' => $videoId];
        }
        return ['success' => true, 'video_id' => $videoId, 'post_id' => (string) ($finish['data']['post_id'] ?? $videoId), 'post_url' => null];
    }

    /**
     * نشر فيديو على صفحة فيسبوك عن طريق رابط عام (file_url) - فيسبوك
     * بيسحب الفيديو بنفسه ويعالجه، مفيش رفع bytes مباشر مطلوب هنا.
     */
    public function publishVideoToFacebookPage(string $pageId, string $pageAccessToken, string $message, string $videoUrl): array
    {
        $result = $this->post("{$pageId}/videos", [
            'file_url' => $videoUrl,
            'description' => $message,
        ], $pageAccessToken, ['timeout' => 120]);

        if (!$result['success']) {
            return $result;
        }

        $postId = $result['data']['id'] ?? null;
        return [
            'success' => true,
            'post_id' => $postId,
            'post_url' => $postId ? "https://www.facebook.com/{$pageId}/videos/{$postId}" : null,
        ];
    }

    /**
     * نشر Reel على صفحة فيسبوك - 3 مراحل حسب Reels Publishing API:
     *  1) start  : POST /{page}/video_reels  (upload_phase=start)     -> video_id + upload_url
     *  2) upload : POST {upload_url} على rupload.facebook.com بهيدر file_url و Authorization: OAuth
     *  3) finish : POST /{page}/video_reels  (upload_phase=finish, video_state=PUBLISHED)
     * المعالجة غير متزامنة: النتيجة 'processing'=>true ولازم تتابع getFacebookVideoStatus($videoId).
     * حد فيسبوك: 30 Reel عن طريق الـ API كل 24 ساعة لكل صفحة.
     * ⚠️ لازم يتجرب على صفحة اختبار حقيقية قبل الإنتاج.
     * @return array ['success'=>bool,'video_id'=>?string,'post_id'=>?string,'processing'=>bool,'stage'=>?string,...]
     */
    public function publishReelToFacebookPage(string $pageId, string $pageAccessToken, string $message, string $videoUrl): array
    {
        $start = $this->post("{$pageId}/video_reels", ['upload_phase' => 'start'], $pageAccessToken);
        if (!$start['success']) {
            return $start + ['stage' => 'start'];
        }
        $videoId = (string) ($start['data']['video_id'] ?? '');
        $uploadUrl = (string) ($start['data']['upload_url'] ?? '');
        if ($videoId === '' || $uploadUrl === '') {
            return $this->localError('بدء رفع الـ Reel فشل: فيسبوك ما رجّعش video_id/upload_url', MetaApiError::UNKNOWN) + ['stage' => 'start'];
        }
        // حماية: ما نبعتش التوكن لأي host غير فيسبوك حتى لو upload_url اتغيّر
        $host = strtolower((string) parse_url($uploadUrl, PHP_URL_HOST));
        if (parse_url($uploadUrl, PHP_URL_SCHEME) !== 'https' || !($host === 'facebook.com' || str_ends_with($host, '.facebook.com'))) {
            return $this->localError('upload_url غير موثوق: ' . $host, MetaApiError::PARAM) + ['stage' => 'start'];
        }

        $upload = $this->request('POST', '', [], [], [
            'url' => $uploadUrl,
            'headers' => ['Authorization: OAuth ' . $pageAccessToken, 'file_url: ' . $videoUrl],
            'raw_token' => true,
            'timeout' => 120,
        ]);
        if (!$upload['success']) {
            return $upload + ['stage' => 'upload', 'video_id' => $videoId];
        }
        if (isset($upload['data']['success']) && $upload['data']['success'] === false) {
            return $this->localError('فيسبوك رفض سحب الفيديو من الرابط (تأكد إن robots.txt وWAF مش بيمنعوه)', MetaApiError::MEDIA) + ['stage' => 'upload', 'video_id' => $videoId];
        }

        $finish = $this->post("{$pageId}/video_reels", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $message,
        ], $pageAccessToken);
        if (!$finish['success']) {
            return $finish + ['stage' => 'finish', 'video_id' => $videoId];
        }

        return [
            'success' => true,
            'video_id' => $videoId,
            'post_id' => $videoId,
            'post_url' => "https://www.facebook.com/reel/{$videoId}",
            'processing' => true,
        ];
    }

    /**
     * حالة معالجة فيديو/Reel في فيسبوك.
     * @return array ['success'=>bool,'status'=>'processing'|'ready'|'error','error'=>?string]
     */
    public function getFacebookVideoStatus(string $videoId, string $pageAccessToken): array
    {
        $r = $this->withToken($pageAccessToken)->get($videoId, ['fields' => 'status']);
        if (!$r['success']) {
            return $r;
        }
        $st = (array) ($r['data']['status'] ?? []);
        $raw = $r['data']; // للتشخيص (أداة الـ smoke test بتطبعه عشان نتأكد من شكل الرد الفعلي)
        $video = strtolower((string) ($st['video_status'] ?? ''));
        $publishing = (array) ($st['publishing_phase'] ?? []);
        $publishStatus = strtolower((string) ($publishing['publish_status'] ?? ''));

        if (in_array($video, ['error', 'expired'], true)) {
            $msg = (string) ($st['processing_phase']['errors'][0]['message'] ?? $st['uploading_phase']['errors'][0]['message'] ?? $st['publishing_phase']['errors'][0]['message'] ?? "video_status={$video}");
            return ['success' => true, 'status' => 'error', 'error' => $msg, 'raw' => $raw];
        }
        if ($video === 'ready' && ($publishStatus === '' || $publishStatus === 'published')) {
            return ['success' => true, 'status' => 'ready', 'raw' => $raw];
        }
        return ['success' => true, 'status' => 'processing', 'raw' => $raw];
    }

    // ------------------------------------------------------------------
    // إنستجرام - النشر
    // ------------------------------------------------------------------

    /**
     * نشر صورة على انستجرام: container ثم media_publish.
     * @param string $pageAccessToken انستجرام بيستخدم توكن الصفحة المرتبطة
     */
    public function publishToInstagram(string $igUserId, string $pageAccessToken, string $imageUrl, string $caption = ''): array
    {
        $containerResult = $this->post("{$igUserId}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ], $pageAccessToken);

        if (!$containerResult['success']) {
            return $containerResult;
        }

        $containerId = $containerResult['data']['id'] ?? null;
        if (!$containerId) {
            return $this->localError('تعذر إنشاء container الصورة في انستجرام', MetaApiError::UNKNOWN);
        }

        $publishResult = $this->post("{$igUserId}/media_publish", ['creation_id' => $containerId], $pageAccessToken);
        if (!$publishResult['success']) {
            return $publishResult;
        }

        return ['success' => true, 'post_id' => $publishResult['data']['id'] ?? null, 'post_url' => null];
    }

    /**
     * إنشاء container فيديو Reels في انستجرام - مرحلة أولى فقط (المعالجة غير متزامنة).
     * @return array ['success'=>bool, 'container_id'=>?string, 'error'=>?string]
     */
    public function createInstagramVideoContainer(string $igUserId, string $pageAccessToken, string $videoUrl, string $caption = ''): array
    {
        $result = $this->post("{$igUserId}/media", [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => $caption,
        ], $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $containerId = $result['data']['id'] ?? null;
        if (!$containerId) {
            return $this->localError('تعذر إنشاء container الفيديو في انستجرام', MetaApiError::UNKNOWN);
        }

        return ['success' => true, 'container_id' => $containerId];
    }

    /**
     * container ستوري إنستجرام (صورة أو فيديو). الستوري ما لهاش كابشن. بعده polling ثم publishInstagramContainer.
     * @return array ['success'=>bool, 'container_id'=>?string]
     */
    public function createInstagramStoryContainer(string $igUserId, string $pageAccessToken, string $mediaUrl, bool $isVideo): array
    {
        $result = $this->post("{$igUserId}/media", ['media_type' => 'STORIES', $isVideo ? 'video_url' : 'image_url' => $mediaUrl], $pageAccessToken);
        if (!$result['success']) {
            return $result;
        }
        $containerId = $result['data']['id'] ?? null;
        if (!$containerId) {
            return $this->localError('تعذر إنشاء container الستوري في انستجرام', MetaApiError::UNKNOWN);
        }
        return ['success' => true, 'container_id' => $containerId];
    }

    /**
     * كاروسيل إنستجرام (2-10 صور): children ثم container الكاروسيل. بعده لازم polling ثم publishInstagramContainer.
     * @param string[] $imageUrls
     */
    public function createInstagramCarouselContainer(string $igUserId, string $pageAccessToken, array $imageUrls, string $caption = ''): array
    {
        $imageUrls = array_values(array_filter($imageUrls));
        if (count($imageUrls) < 2 || count($imageUrls) > 10) {
            return $this->localError('الكاروسيل محتاج من 2 لـ 10 صور', MetaApiError::PARAM);
        }
        $children = [];
        foreach ($imageUrls as $url) {
            $c = $this->post("{$igUserId}/media", ['image_url' => $url, 'is_carousel_item' => 'true'], $pageAccessToken);
            if (!$c['success']) {
                return $c;
            }
            $children[] = (string) ($c['data']['id'] ?? '');
        }
        $parent = $this->post("{$igUserId}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $caption,
        ], $pageAccessToken);
        if (!$parent['success']) {
            return $parent;
        }
        return ['success' => true, 'container_id' => $parent['data']['id'] ?? null, 'children' => $children];
    }

    /**
     * فحص حالة معالجة container في انستجرام.
     * @return array ['success'=>bool, 'status'=>?string (IN_PROGRESS|FINISHED|ERROR|EXPIRED|PUBLISHED), 'error'=>?string]
     */
    public function checkInstagramContainerStatus(string $containerId, string $pageAccessToken): array
    {
        $result = $this->withToken($pageAccessToken)->get($containerId, ['fields' => 'status_code,status']);
        if (!$result['success']) {
            return $result;
        }
        return [
            'success' => true,
            'status' => $result['data']['status_code'] ?? 'IN_PROGRESS',
            'detail' => isset($result['data']['status']) ? (string) $result['data']['status'] : null,
        ];
    }

    /** نشر container (صورة أو فيديو) بعد التأكد إنه جاهز */
    public function publishInstagramContainer(string $igUserId, string $pageAccessToken, string $containerId): array
    {
        $publishResult = $this->post("{$igUserId}/media_publish", ['creation_id' => $containerId], $pageAccessToken);
        if (!$publishResult['success']) {
            return $publishResult;
        }
        return ['success' => true, 'post_id' => $publishResult['data']['id'] ?? null];
    }

    /**
     * الحصة اليومية للنشر على إنستجرام (حد 100 منشور/24 ساعة).
     * @return array ['success'=>bool,'used'=>int,'total'=>int,'remaining'=>int]
     */
    public function getInstagramPublishingLimit(string $igUserId, string $pageAccessToken): array
    {
        $r = $this->withToken($pageAccessToken)->get("{$igUserId}/content_publishing_limit", ['fields' => 'quota_usage,config']);
        if (!$r['success']) {
            return $r;
        }
        $row = $r['data']['data'][0] ?? [];
        $used = (int) ($row['quota_usage'] ?? 0);
        $total = (int) ($row['config']['quota_total'] ?? 100);
        return ['success' => true, 'used' => $used, 'total' => $total, 'remaining' => max(0, $total - $used)];
    }

    // ------------------------------------------------------------------
    // التفاعل: تعليق / تعديل / حذف / مصالحة
    // ------------------------------------------------------------------

    /** تعليق على منشور فيسبوك أو وسائط إنستجرام (أول تعليق تلقائي للهاشتاجات/الرابط) */
    public function commentOnObject(string $objectId, string $pageAccessToken, string $message): array
    {
        $r = $this->post("{$objectId}/comments", ['message' => $message], $pageAccessToken);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => true, 'comment_id' => $r['data']['id'] ?? null];
    }

    /**
     * تعليقات منشور (أول مستوى، الأحدث أولًا) بشكل موحّد للمنصتين.
     * فيسبوك: pages_read_engagement + pages_read_user_content. إنستجرام: instagram_manage_comments.
     * @return array ['success'=>bool,'comments'=>[['id','message','author_name','author_id','created_time','like_count','hidden','reply_count']]]
     */
    public function getObjectComments(string $platform, string $objectId, string $pageAccessToken, int $limit = 50): array
    {
        $api = $this->withToken($pageAccessToken);
        $limit = max(1, min(100, $limit));
        $r = $platform === 'instagram'
            ? $api->get("{$objectId}/comments", ['fields' => 'id,text,username,from{id,username},timestamp,like_count,hidden,replies.limit(25){from{id}}', 'limit' => $limit])
            : $api->get("{$objectId}/comments", ['fields' => 'id,message,from{id,name},created_time,like_count,is_hidden,comment_count,comments.limit(25){from{id}}', 'filter' => 'toplevel', 'order' => 'reverse_chronological', 'limit' => $limit]);
        if (!$r['success']) {
            return $r;
        }
        $out = [];
        foreach ((array) ($r['data']['data'] ?? []) as $c) {
            if (empty($c['id'])) {
                continue;
            }
            $out[] = $platform === 'instagram'
                ? ['id' => (string) $c['id'], 'message' => (string) ($c['text'] ?? ''), 'author_name' => (string) ($c['username'] ?? $c['from']['username'] ?? ''), 'author_id' => (string) ($c['from']['id'] ?? ''),
                   'created_time' => $c['timestamp'] ?? null, 'like_count' => (int) ($c['like_count'] ?? 0), 'hidden' => !empty($c['hidden']), 'reply_count' => count((array) ($c['replies']['data'] ?? [])),
                   'reply_author_ids' => array_values(array_filter(array_map(static fn ($x) => (string) ($x['from']['id'] ?? ''), (array) ($c['replies']['data'] ?? []))))]
                : ['id' => (string) $c['id'], 'message' => (string) ($c['message'] ?? ''), 'author_name' => (string) ($c['from']['name'] ?? ''), 'author_id' => (string) ($c['from']['id'] ?? ''),
                   'created_time' => $c['created_time'] ?? null, 'like_count' => (int) ($c['like_count'] ?? 0), 'hidden' => !empty($c['is_hidden']), 'reply_count' => (int) ($c['comment_count'] ?? 0),
                   'reply_author_ids' => array_values(array_filter(array_map(static fn ($x) => (string) ($x['from']['id'] ?? ''), (array) ($c['comments']['data'] ?? []))))];
        }
        return ['success' => true, 'comments' => $out];
    }

    /** رد على تعليق: فيسبوك /{comment}/comments - إنستجرام /{comment}/replies */
    public function replyToComment(string $platform, string $commentId, string $pageAccessToken, string $message): array
    {
        $r = $this->post($platform === 'instagram' ? "{$commentId}/replies" : "{$commentId}/comments", ['message' => $message], $pageAccessToken);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => true, 'reply_id' => $r['data']['id'] ?? null];
    }

    /**
     * رد خاص (Private Reply) على تعليق: رسالة واحدة في الماسنجر/دايركت إنستجرام لصاحب التعليق.
     * بيتبعت عن طريق الصفحة نفسها (me/messages بتوكن الصفحة) لفيسبوك وإنستجرام الاتنين.
     * قيود Meta: رسالة واحدة بس لكل تعليق، وخلال 7 أيام من التعليق. محتاج pages_messaging (+ instagram_manage_messages).
     */
    public function sendPrivateReply(string $commentId, string $pageAccessToken, string $text): array
    {
        $r = $this->post('me/messages', [
            'recipient' => json_encode(['comment_id' => $commentId]),
            'message' => json_encode(['text' => mb_substr($text, 0, 1900)], JSON_UNESCAPED_UNICODE),
            'messaging_type' => 'RESPONSE',
        ], $pageAccessToken);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => true, 'message_id' => $r['data']['message_id'] ?? null];
    }

    /** إخفاء تعليق (سبام/إساءة) - بيفضل ظاهر لصاحبه بس */
    public function hideComment(string $platform, string $commentId, string $pageAccessToken, bool $hide = true): array
    {
        $r = $this->post($commentId, $platform === 'instagram' ? ['hide' => $hide ? 'true' : 'false'] : ['is_hidden' => $hide ? 'true' : 'false'], $pageAccessToken);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => true];
    }

    /** تعديل نص منشور فيسبوك بعد النشر */
    public function editFacebookPost(string $postId, string $pageAccessToken, string $message): array
    {
        $r = $this->post($postId, ['message' => $message], $pageAccessToken);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => !empty($r['data']['success']) || isset($r['data']['id']), 'data' => $r['data']];
    }

    /**
     * حذف منشور من فيسبوك. (إنستجرام ما بيدعمش حذف الوسائط عبر الـ API.)
     * "غير موجود أصلًا" بيتعامل معاه كنجاح لأن النتيجة المطلوبة اتحققت.
     */
    public function deleteFacebookPost(string $postId, string $pageAccessToken): array
    {
        $r = $this->request('DELETE', $postId, [], [], ['token' => $pageAccessToken]);
        if (!$r['success']) {
            // كود 100/33 بيظهر كمان مع مشاكل الصلاحيات، فنتأكد من نص "does not exist" قبل ما نعتبره اتحذف
            $gone = ($r['error_code'] ?? null) === 100 && ($r['error_subcode'] ?? null) === 33
                && stripos((string) ($r['error'] ?? ''), 'does not exist') !== false;
            if ($gone) {
                return ['success' => true, 'already_deleted' => true];
            }
            return $r;
        }
        return ['success' => true];
    }

    /**
     * آخر المنشورات المنشورة على الصفحة - أساس مصالحة المنشورات uncertain.
     * @return array ['success'=>bool,'posts'=>[['id','message','created_time','permalink_url']]]
     */
    public function getFacebookPublishedPosts(string $pageId, string $pageAccessToken, int $limit = 25): array
    {
        $r = $this->withToken($pageAccessToken)->get("{$pageId}/published_posts", [
            'fields' => 'id,message,created_time,permalink_url',
            'limit' => max(1, min(100, $limit)),
        ]);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => true, 'posts' => array_values((array) ($r['data']['data'] ?? []))];
    }

    /**
     * آخر وسائط حساب إنستجرام (للمصالحة). بنحوّل الحقول لنفس شكل منشورات فيسبوك عشان نفس الـ matcher يخدم الاتنين.
     * @return array ['success'=>bool,'posts'=>[['id','message','created_time','permalink_url']]]
     */
    public function getInstagramRecentMedia(string $igUserId, string $pageAccessToken, int $limit = 25): array
    {
        $r = $this->withToken($pageAccessToken)->get("{$igUserId}/media", [
            'fields' => 'id,caption,timestamp,permalink',
            'limit' => max(1, min(100, $limit)),
        ]);
        if (!$r['success']) {
            return $r;
        }
        $posts = [];
        foreach ((array) ($r['data']['data'] ?? []) as $m) {
            $posts[] = ['id' => $m['id'] ?? null, 'message' => $m['caption'] ?? '', 'created_time' => $m['timestamp'] ?? null, 'permalink_url' => $m['permalink'] ?? null];
        }
        return ['success' => true, 'posts' => $posts];
    }

    /**
     * إحصائيات تفاعل منشور (لايكات/تعليقات/مشاركات/وصول).
     * الوصول في فيسبوك = post_total_media_view_unique (مشاهدون فريدون). المقياس القديم
     * post_impressions_unique اتشال من Meta فكان بيرجع invalid metric ويفضل reach=null بصمت.
     * القيمة بمنهجية مختلفة عن الـ reach القديم - اعرضها في الواجهة كـ "مشاهدون فريدون".
     * @return array ['success'=>bool,'likes'=>int,'comments'=>int,'shares'=>int,'reach'=>?int,'views'=>?int,'permalink'=>?string,'insights_error'=>?string,'error'=>?string]
     */
    public function getPostMetrics(string $platform, string $postId, string $pageAccessToken): array
    {
        $api = $this->withToken($pageAccessToken);
        $out = ['success' => false, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => null, 'views' => null, 'saves' => null, 'permalink' => null, 'insights_error' => null];

        if ($platform === 'instagram') {
            $r = $api->get($postId, ['fields' => 'like_count,comments_count,permalink']);
            if (!$r['success']) {
                return $out + ['error' => $r['error'] ?? 'تعذر جلب إحصائيات إنستجرام'] + self::errorFields($r);
            }
            $d = $r['data'];
            $out['likes'] = (int) ($d['like_count'] ?? 0);
            $out['comments'] = (int) ($d['comments_count'] ?? 0);
            $out['permalink'] = $d['permalink'] ?? null;
            // مقاييس إنستجرام بتختلف حسب نوع الوسائط والإصدار: بنجرّب الأشمل الأول وبعدين الأبسط
            $ins = ['success' => false];
            foreach (['reach,shares,saved,views', 'reach,shares,saved', 'reach,shares'] as $metrics) {
                $ins = $api->get($postId . '/insights', ['metric' => $metrics]);
                if ($ins['success']) {
                    break;
                }
            }
            if ($ins['success']) {
                foreach (($ins['data']['data'] ?? []) as $m) {
                    $v = $m['values'][0]['value'] ?? ($m['total_value']['value'] ?? null);
                    if ($v === null || is_array($v)) {
                        continue;
                    }
                    $name = (string) ($m['name'] ?? '');
                    if ($name === 'reach') {
                        $out['reach'] = (int) $v;
                    } elseif ($name === 'shares') {
                        $out['shares'] = (int) $v;
                    } elseif ($name === 'saved') {
                        $out['saves'] = (int) $v;
                    } elseif ($name === 'views') {
                        $out['views'] = (int) $v;
                    }
                }
            } else {
                $out['insights_error'] = $ins['error'] ?? 'insights unavailable';
            }
            $out['success'] = true;
            return $out;
        }

        // facebook
        $r = $api->get($postId, ['fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0),shares,permalink_url']);
        if (!$r['success']) {
            return $out + ['error' => $r['error'] ?? 'تعذر جلب إحصائيات فيسبوك'] + self::errorFields($r);
        }
        $d = $r['data'];
        $out['likes'] = (int) ($d['likes']['summary']['total_count'] ?? 0);
        $out['comments'] = (int) ($d['comments']['summary']['total_count'] ?? 0);
        $out['shares'] = (int) ($d['shares']['count'] ?? 0);
        $out['permalink'] = $d['permalink_url'] ?? null;

        $ins = $api->get($postId . '/insights', ['metric' => 'post_total_media_view_unique,post_media_views']);
        if (!$ins['success']) {
            // لو post_media_views مش مدعوم على النوع ده من المنشورات نجرب المقياس الأساسي لوحده
            $ins = $api->get($postId . '/insights', ['metric' => 'post_total_media_view_unique']);
        }
        if ($ins['success']) {
            foreach (($ins['data']['data'] ?? []) as $m) {
                $v = $m['values'][0]['value'] ?? null;
                if ($v === null || is_array($v)) {
                    continue;
                }
                if (($m['name'] ?? '') === 'post_total_media_view_unique') {
                    $out['reach'] = (int) $v;
                } elseif (($m['name'] ?? '') === 'post_media_views') {
                    $out['views'] = (int) $v;
                }
            }
        } else {
            $out['insights_error'] = $ins['error'] ?? 'insights unavailable';
        }
        $out['success'] = true;
        return $out;
    }

    /**
     * عدد متابعين الحساب ومنشوراته (لمنحنى نمو المتابعين اليومي).
     * @return array ['success'=>bool,'followers'=>?int,'posts_count'=>?int]
     */
    public function getAccountStats(string $platform, string $accountId, string $pageAccessToken): array
    {
        $api = $this->withToken($pageAccessToken);
        $r = $platform === 'instagram'
            ? $api->get($accountId, ['fields' => 'followers_count,media_count'])
            : $api->get($accountId, ['fields' => 'followers_count,fan_count']);
        if (!$r['success']) {
            return $r;
        }
        $d = $r['data'];
        $followers = $d['followers_count'] ?? ($d['fan_count'] ?? null);
        return ['success' => true, 'followers' => $followers === null ? null : (int) $followers, 'posts_count' => isset($d['media_count']) ? (int) $d['media_count'] : null];
    }

    /**
     * صورة واسم الصفحة/الحساب (لكروت الحسابات واختيار الصفحات في المحرر).
     * @return array ['success'=>bool,'avatar'=>?string,'username'=>?string,'name'=>?string,'followers'=>?int]
     */
    public function getProfile(string $platform, string $accountId, string $pageAccessToken): array
    {
        $api = $this->withToken($pageAccessToken);
        $r = $platform === 'instagram'
            ? $api->get($accountId, ['fields' => 'username,name,profile_picture_url,followers_count'])
            : $api->get($accountId, ['fields' => 'name,username,picture.type(large){url},followers_count,fan_count']);
        if (!$r['success']) {
            return $r;
        }
        $d = $r['data'];
        $avatar = $platform === 'instagram' ? ($d['profile_picture_url'] ?? null) : ($d['picture']['data']['url'] ?? null);
        $followers = $d['followers_count'] ?? ($d['fan_count'] ?? null);
        return ['success' => true, 'avatar' => is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
            'username' => isset($d['username']) ? (string) $d['username'] : null, 'name' => isset($d['name']) ? (string) $d['name'] : null,
            'followers' => $followers === null ? null : (int) $followers];
    }

    /**
     * بيانات الصفحة لتحليل اكتمالها (الموقع/التليفون/واتساب/النبذة). الحقول الإضافية بتتجاب لوحدها
     * عشان لو Meta رفض حقل منهم (صلاحية/إصدار) مايبوّظش الباقي.
     * @return array ['success'=>bool,'page'=>array]
     */
    public function getPageDetails(string $pageId, string $pageAccessToken): array
    {
        $api = $this->withToken($pageAccessToken);
        $r = $api->get($pageId, ['fields' => 'name,username,link,category,about,website,phone,emails,fan_count,followers_count']);
        if (!$r['success']) {
            return $r;
        }
        $page = (array) $r['data'];
        $x = $api->get($pageId, ['fields' => 'whatsapp_number']);
        if ($x['success']) {
            $page['whatsapp_number'] = $x['data']['whatsapp_number'] ?? null;
        }
        return ['success' => true, 'page' => $page];
    }

    /**
     * آخر منشورات صفحة فيسبوك ومعاها التفاعل في طلب واحد (للتحليل).
     * @return array ['success'=>bool,'posts'=>[['id','message','created_time','permalink','type','likes','comments','shares','has_link']]]
     */
    public function getFacebookPostsWithStats(string $pageId, string $pageAccessToken, int $limit = 30): array
    {
        $r = $this->withToken($pageAccessToken)->get("{$pageId}/published_posts", [
            'fields' => 'id,message,created_time,permalink_url,full_picture,attachments{media_type,type,unshimmed_url,url,media{image{src}}},likes.summary(true).limit(0),comments.summary(true).limit(0),shares',
            'limit' => max(1, min(50, $limit)),
        ]);
        if (!$r['success']) {
            return $r;
        }
        $posts = [];
        foreach ((array) ($r['data']['data'] ?? []) as $p) {
            $att = $p['attachments']['data'][0] ?? [];
            $type = strtolower((string) ($att['media_type'] ?? $att['type'] ?? 'status'));
            $url = (string) ($att['unshimmed_url'] ?? $att['url'] ?? '');
            $posts[] = [
                'id' => $p['id'] ?? null, 'message' => (string) ($p['message'] ?? ''), 'created_time' => $p['created_time'] ?? null, 'permalink' => $p['permalink_url'] ?? null,
                'type' => str_contains($type, 'video') ? 'video' : (str_contains($type, 'album') ? 'carousel' : (str_contains($type, 'photo') ? 'photo' : ($type === 'share' || $type === 'link' ? 'link' : 'text'))),
                'likes' => (int) ($p['likes']['summary']['total_count'] ?? 0), 'comments' => (int) ($p['comments']['summary']['total_count'] ?? 0), 'shares' => (int) ($p['shares']['count'] ?? 0),
                'media_url' => (string) ($p['full_picture'] ?? $att['media']['image']['src'] ?? ''), 'has_link' => (bool) preg_match('~https?://~i', (string) ($p['message'] ?? '')) || ($url !== '' && !str_contains($url, 'facebook.com')),
            ];
        }
        return ['success' => true, 'posts' => $posts];
    }

    /**
     * آخر وسائط إنستجرام ومعاها التفاعل (للتحليل).
     * @return array ['success'=>bool,'posts'=>[['id','message','created_time','permalink','type','likes','comments','shares','has_link']]]
     */
    public function getInstagramMediaWithStats(string $igUserId, string $pageAccessToken, int $limit = 30): array
    {
        $r = $this->withToken($pageAccessToken)->get("{$igUserId}/media", [
            'fields' => 'id,caption,timestamp,permalink,media_type,media_product_type,media_url,thumbnail_url,like_count,comments_count',
            'limit' => max(1, min(50, $limit)),
        ]);
        if (!$r['success']) {
            return $r;
        }
        $posts = [];
        foreach ((array) ($r['data']['data'] ?? []) as $m) {
            $mt = strtoupper((string) ($m['media_type'] ?? ''));
            $posts[] = [
                'id' => $m['id'] ?? null, 'message' => (string) ($m['caption'] ?? ''), 'created_time' => $m['timestamp'] ?? null, 'permalink' => $m['permalink'] ?? null,
                'type' => ($m['media_product_type'] ?? '') === 'REELS' || $mt === 'VIDEO' ? 'video' : ($mt === 'CAROUSEL_ALBUM' ? 'carousel' : 'photo'),
                'media_url' => (string) ($m['thumbnail_url'] ?? $m['media_url'] ?? ''), 'likes' => (int) ($m['like_count'] ?? 0), 'comments' => (int) ($m['comments_count'] ?? 0), 'shares' => 0, 'has_link' => false,
            ];
        }
        return ['success' => true, 'posts' => $posts];
    }

    /**
     * بيانات حساب إنستجرام بيزنس تاني (منافس) عن طريق Business Discovery - متاح بصلاحية instagram_basic.
     * @return array ['success'=>bool,'profile'=>['username','name','followers','media_count'],'media'=>[['caption','likes','comments','timestamp','type','permalink']]]
     */
    public function businessDiscovery(string $igUserId, string $pageAccessToken, string $username, int $limit = 12): array
    {
        $username = ltrim(trim($username), '@');
        if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $username)) {
            return $this->localError('اسم حساب إنستجرام مش صالح', MetaApiError::PARAM);
        }
        $fields = 'business_discovery.username(' . $username . '){username,name,followers_count,media_count,media.limit(' . max(1, min(25, $limit)) . '){caption,like_count,comments_count,timestamp,media_type,permalink}}';
        $r = $this->withToken($pageAccessToken)->get($igUserId, ['fields' => $fields]);
        if (!$r['success']) {
            return $r;
        }
        $bd = (array) ($r['data']['business_discovery'] ?? []);
        if (!$bd) {
            return $this->localError('الحساب ده مش حساب بيزنس/كريتور أو مش موجود', MetaApiError::PARAM);
        }
        $media = [];
        foreach ((array) ($bd['media']['data'] ?? []) as $m) {
            $media[] = ['caption' => mb_substr((string) ($m['caption'] ?? ''), 0, 300), 'likes' => (int) ($m['like_count'] ?? 0), 'comments' => (int) ($m['comments_count'] ?? 0),
                'timestamp' => $m['timestamp'] ?? null, 'type' => (string) ($m['media_type'] ?? ''), 'permalink' => $m['permalink'] ?? null];
        }
        return ['success' => true, 'profile' => ['username' => (string) ($bd['username'] ?? $username), 'name' => (string) ($bd['name'] ?? ''), 'followers' => isset($bd['followers_count']) ? (int) $bd['followers_count'] : null, 'media_count' => isset($bd['media_count']) ? (int) $bd['media_count'] : null], 'media' => $media];
    }

    // ------------------------------------------------------------------
    // صحة الربط
    // ------------------------------------------------------------------

    /**
     * فحص توكن (debug_token) - بيستخدم app token = app_id|app_secret.
     * @return array ['success'=>bool,'is_valid'=>bool,'expires_at'=>?int (0/null = لا ينتهي),'data_access_expires_at'=>?int,'scopes'=>string[],'error'=>?string]
     */
    public function debugToken(string $inputToken, string $appId, string $appSecret): array
    {
        $r = (new self($appId . '|' . $appSecret, $this->transport, $this->sleeper, ''))->get('debug_token', ['input_token' => $inputToken]);
        if (!$r['success']) {
            return $r;
        }
        $d = (array) ($r['data']['data'] ?? []);
        $exp = isset($d['expires_at']) ? (int) $d['expires_at'] : null;
        $dataExp = isset($d['data_access_expires_at']) ? (int) $d['data_access_expires_at'] : null;
        return [
            'success' => true,
            'is_valid' => !empty($d['is_valid']),
            'expires_at' => $exp === 0 ? null : $exp,
            'data_access_expires_at' => $dataExp === 0 ? null : $dataExp,
            'scopes' => array_values((array) ($d['scopes'] ?? [])),
            'user_id' => isset($d['user_id']) ? (string) $d['user_id'] : null,
            'error' => isset($d['error']['message']) ? (string) $d['error']['message'] : null,
        ];
    }

    /** الصلاحيات الممنوحة فعليًا لتوكن المستخدم (me/permissions) */
    public function getGrantedPermissions(): array
    {
        $r = $this->get('me/permissions', ['limit' => 100]);
        if (!$r['success']) {
            return $r;
        }
        $granted = [];
        $declined = [];
        foreach ((array) ($r['data']['data'] ?? []) as $p) {
            if (($p['status'] ?? '') === 'granted') {
                $granted[] = (string) $p['permission'];
            } else {
                $declined[] = (string) ($p['permission'] ?? '');
            }
        }
        return ['success' => true, 'granted' => $granted, 'declined' => $declined];
    }

    /**
     * يشترك بالصفحة في تطبيق المنصة عشان رسائل Messenger وInstagram توصل
     * على الـ webhook العام. بيحتاج صلاحية pages_messaging.
     */
    public function subscribeMessaging(string $pageId, string $pageAccessToken): array
    {
        return $this->post($pageId . '/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks'], $pageAccessToken);
    }

    /** إلغاء اشتراك الصفحة: رسائلها مش هتوصل للمنصة تاني */
    public function unsubscribeMessaging(string $pageId, string $pageAccessToken): array
    {
        return $this->request('DELETE', $pageId . '/subscribed_apps', [], [], ['token' => $pageAccessToken]);
    }

    // ------------------------------------------------------------------
    // الطبقة المنخفضة: request / retry / usage
    // ------------------------------------------------------------------

    /** جدولة أصلية: published=false + scheduled_publish_time (بين 10 دقايق وحد Meta الأقصى) */
    private function applySchedule(array &$payload, array $options): ?array
    {
        if (empty($options['scheduled_publish_time'])) {
            return null;
        }
        $ts = (int) $options['scheduled_publish_time'];
        if ($ts < time() + 600) {
            return $this->localError('الجدولة الأصلية على فيسبوك لازم تكون بعد 10 دقايق على الأقل من دلوقتي', MetaApiError::PARAM);
        }
        $payload['published'] = 'false';
        $payload['scheduled_publish_time'] = $ts;
        return null;
    }

    /** حقول الخطأ المنظّمة من نتيجة فاشلة (بتتنسخ لنتائج الميثودز الأعلى) */
    private static function errorFields(array $r): array
    {
        $out = [];
        foreach (['error_code', 'error_subcode', 'error_type', 'fbtrace_id', 'category', 'transient', 'definitive', 'http_code', 'retry_after', 'friendly'] as $k) {
            if (array_key_exists($k, $r)) {
                $out[$k] = $r[$k];
            }
        }
        return $out;
    }

    /** خطأ محلي (قبل أي نداء لـ Meta) - definitive لأن مفيش حاجة اتبعتت */
    private function localError(string $message, string $category): array
    {
        return ['success' => false, 'error' => $message, 'category' => $category, 'transient' => false, 'definitive' => true, 'error_code' => null, 'error_subcode' => null, 'error_type' => null, 'fbtrace_id' => null, 'http_code' => 0, 'retry_after' => null, 'friendly' => ''];
    }

    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    private function post(string $path, array $data, ?string $tokenOverride = null, array $opts = []): array
    {
        if ($tokenOverride !== null) {
            $opts['token'] = $tokenOverride;
        }
        return $this->request('POST', $path, [], $data, $opts);
    }

    /**
     * ينفّذ طلب Graph مع retry ذكي.
     * سياسة الإعادة:
     *  - GET: نعيد على الأخطاء المؤقتة وفشل الشبكة (3 محاولات، backoff أُسّي + jitter). حد الاستخدام ما بنعيدوش هنا (بيتأجّل من الـ Job).
     *  - POST/DELETE: ما بنعيدش إلا لو الطلب ما وصلش لـ Meta أصلًا (DNS/connect) - غير كده ممكن يتنشر مرتين.
     * @param array $opts token, timeout, url, headers, raw_token
     */
    private function request(string $method, string $path, array $query = [], array $data = [], array $opts = []): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $res = $this->requestOnce($method, $path, $query, $data, $opts);
            if ($res['success'] || $attempt >= self::MAX_READ_ATTEMPTS || !$this->shouldRetry($method, $res)) {
                if (!$res['success'] && $attempt > 1) {
                    $res['attempts'] = $attempt;
                }
                return $res;
            }
            $base = 400 * (2 ** ($attempt - 1));
            ($this->sleeper)($base + random_int(0, (int) ($base / 2)));
        }
    }

    private function shouldRetry(string $method, array $res): bool
    {
        if (($res['category'] ?? '') === MetaApiError::RATE_LIMIT) {
            return false;
        }
        if (!empty($res['not_sent'])) {
            return true;
        }
        if ($method === 'GET') {
            return !empty($res['transient']) || ($res['http_code'] ?? 0) === 0;
        }
        return false;
    }

    private function requestOnce(string $method, string $path, array $query, array $data, array $opts): array
    {
        try {
            $token = $opts['token'] ?? $this->accessToken;
            $url = $opts['url'] ?? "https://graph.facebook.com/{$this->apiVersion}/{$path}";

            $headers = $opts['headers'] ?? [];
            $useHeaderToken = empty($opts['raw_token']) && !$this->tokenInQuery();
            if (empty($opts['raw_token'])) {
                if ($useHeaderToken) {
                    $headers[] = 'Authorization: Bearer ' . $token;
                } elseif ($method === 'POST') {
                    $data['access_token'] = $token;
                } else {
                    $query['access_token'] = $token;
                }
                if ($this->appSecret !== null && $token !== '') {
                    $proof = hash_hmac('sha256', $token, $this->appSecret);
                    if ($method === 'POST') {
                        $data['appsecret_proof'] = $proof;
                    } else {
                        $query['appsecret_proof'] = $proof;
                    }
                }
            }

            if (!empty($query)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
            }

            $body = null;
            if ($method === 'POST' && !empty($data)) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $body = http_build_query($data);
            }

            $result = $this->httpRequest($method, $url, $headers, $body, (int) ($opts['timeout'] ?? 30));
            $this->recordUsage($result['headers'] ?? []);

            if ($result['error']) {
                $notSent = in_array($result['errno'] ?? 0, self::NOT_SENT_ERRNOS, true);
                return [
                    'success' => false,
                    'error' => 'cURL Error: ' . $result['error'],
                    'category' => MetaApiError::TRANSIENT,
                    'transient' => true,
                    // لو الطلب وصل ومعرفناش النتيجة (timeout) = غامض (مش definitive)
                    'definitive' => $notSent,
                    'not_sent' => $notSent,
                    'error_code' => null, 'error_subcode' => null, 'error_type' => null, 'fbtrace_id' => null,
                    'http_code' => 0, 'retry_after' => null, 'friendly' => MetaApiError::hint(MetaApiError::TRANSIENT),
                ];
            }

            $decoded = json_decode($result['body'], true);
            $httpCode = $result['http_code'];

            if ($httpCode < 200 || $httpCode >= 300 || (is_array($decoded) && isset($decoded['error']))) {
                $e = MetaApiError::parse(is_array($decoded) ? $decoded : null, $httpCode);
                return [
                    'success' => false,
                    'error' => $e['message'],
                    'user_message' => $e['user_message'],
                    'error_code' => $e['code'],
                    'error_subcode' => $e['subcode'],
                    'error_type' => $e['type'],
                    'fbtrace_id' => $e['fbtrace_id'],
                    'category' => $e['category'],
                    'transient' => $e['transient'],
                    // رد خطأ صريح من Meta بتصنيف معروف = اترفض فعلًا. 1/2/5xx/غير معروف = غامض.
                    'definitive' => MetaApiError::isDefinitiveRejection($e['category'], $e['code']),
                    'http_code' => $httpCode,
                    'retry_after' => $e['category'] === MetaApiError::RATE_LIMIT ? $this->retryAfterSeconds() : null,
                    'friendly' => MetaApiError::hint($e['category'], $e['code'], $e['subcode']),
                ];
            }

            return ['success' => true, 'data' => is_array($decoded) ? $decoded : ['raw' => (string) $result['body']]];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta Social API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
            return $this->localError($e->getMessage(), MetaApiError::UNKNOWN) + ['definitive' => false];
        }
    }

    private function tokenInQuery(): bool
    {
        return (string) env('META_SEND_TOKEN_IN_QUERY', '') === '1';
    }

    /** يقرأ X-App-Usage / X-Business-Use-Case-Usage / X-Page-Usage (case-insensitive) */
    private function recordUsage(array $headers): void
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = is_array($v) ? (string) end($v) : (string) $v;
        }
        $merge = function (array $row): void {
            foreach (['call_count', 'total_time', 'total_cputime'] as $f) {
                if (isset($row[$f])) {
                    $this->usage[$f] = max((int) $this->usage[$f], (int) $row[$f]);
                }
            }
            if (isset($row['estimated_time_to_regain_access'])) {
                $this->usage['regain_minutes'] = max((int) $this->usage['regain_minutes'], (int) $row['estimated_time_to_regain_access']);
            }
        };
        foreach (['x-app-usage', 'x-page-usage'] as $key) {
            if (!empty($h[$key]) && is_array($j = json_decode($h[$key], true))) {
                $merge($j);
            }
        }
        if (!empty($h['x-business-use-case-usage']) && is_array($j = json_decode($h['x-business-use-case-usage'], true))) {
            foreach ($j as $rows) {
                foreach ((array) $rows as $row) {
                    if (is_array($row)) {
                        $merge($row);
                    }
                }
            }
        }
    }

    private function retryAfterSeconds(): int
    {
        $min = (int) $this->usage['regain_minutes'];
        return $min > 0 ? $min * 60 : 0;
    }

    /**
     * تنفيذ طلب HTTP عبر الـ transport الوهمي (لو محقون) أو curl العادي.
     * @return array ['body'=>string,'http_code'=>int,'error'=>?string,'errno'=>int,'headers'=>array]
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeout,
            ]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : null,
                'errno' => (int) ($fake['errno'] ?? 0),
                'headers' => (array) ($fake['headers'] ?? []),
            ];
        }

        $respHeaders = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[trim($p[0])] = trim($p[1]);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        return [
            'body' => (string) $response,
            'http_code' => (int) $httpCode,
            'error' => $curlError ?: null,
            'errno' => $errno,
            'headers' => $respHeaders,
        ];
    }
}
