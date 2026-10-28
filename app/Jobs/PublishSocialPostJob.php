<?php

/**
 * Tourfecto - Publish Social Post Job
 * تنفيذ فعلي لنشر هدف واحد (social_post_targets) على منصته عبر
 * platform_connections. يُنفَّذ بواسطة cron/process_queue.php.
 * @version 5.0.0
 * v5: Reels على فيسبوك بتدفق async حقيقي، قرار النتيجة عبر PublishPolicy (success/retry_later/failed/uncertain)،
 *     إعادة تلقائية لحدود الاستخدام، pre-flight للوسائط، أول تعليق، سجل نشر للمراقبة.
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialLinkTracker.php';
require_once __DIR__ . '/../Services/SocialMedia/ThreadsAPI.php';
require_once __DIR__ . '/../Services/SocialMedia/LinkedInAPI.php';
if (!class_exists('SocialMonthlyPlanService', false)) {
    require_once __DIR__.'/../Services/SocialMedia/SocialMonthlyPlanService.php';
}
require_once __DIR__.'/../Services/SocialMedia/MetaApiError.php';
require_once __DIR__.'/../Services/SocialMedia/PublishPolicy.php';
require_once __DIR__.'/../Services/SocialMedia/MediaPreflight.php';
class PublishSocialPostJob implements QueueJobInterface
{
    private const MAX_POLL_ATTEMPTS = 30;
    private const POLL_DELAY_SECONDS = 15;
    /** الريلز ممكن تاخد وقت أطول في المعالجة: 60 × 15 ثانية = 15 دقيقة، وبعدها uncertain (مش failed) لأنها ممكن تخلص */
    private const REEL_MAX_POLL_ATTEMPTS = 60;

    /** @var callable|null حقن عميل Meta في الاختبارات: fn(string $token): MetaSocialAPI */
    public static $apiFactory = null;

    private float $startedAt = 0.0;
    private static ?bool $v2Schema = null;

    public function handle(array $payload): void
    {
        SocialMonthlyPlanService::guardTarget((int)($payload['social_post_target_id']??0),function()use($payload){$this->handleUnlocked($payload);});
    }

    private function handleUnlocked(array $payload): void
    {
        $key='social-target:'.(int)($payload['social_post_target_id']??0);
        if(!ExternalExecution::lock($key))return;
        try {
            $this->publish($payload);
        } finally {
            ExternalExecution::unlock($key);
            $tid = (int) ($payload['social_post_target_id'] ?? 0);
            $row = $tid ? Database::getInstance()->query('SELECT social_post_id FROM social_post_targets WHERE id = ?', [$tid]) : [];
            if ($row) {
                SocialPostService::refreshPostStatus((int) $row[0]['social_post_id']);
            }
        }
    }

    private function publish(array $payload): void
    {
        $targetId = (int) ($payload['social_post_target_id'] ?? 0);
        if (!$targetId) {
            throw new Exception('social_post_target_id مفقود في payload');
        }

        $target = (new SocialPostTarget())->find($targetId);
        if (!$target) {
            throw new Exception("SocialPostTarget #{$targetId} غير موجود");
        }

        if(in_array($target->getAttribute('status'),['published','uncertain'],true))return;
        $post = (new SocialPost())->find((int) $target->getAttribute('social_post_id'));
        $connection = (new PlatformConnection())->find((int) $target->getAttribute('platform_connection_id'));

        if (!$post || !$connection) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'المنشور أو اتصال المنصة غير موجود');
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            return;
        }

        // Revalidate entitlement at execution time: a customer may pause a page,
        // downgrade a subscription or lose ownership after scheduling the post.
        // Do not call assertPublish here: the quota was reserved at creation/approval,
        // and charging it again would double-count scheduled targets.
        $postOwner = (int) $post->getAttribute('user_id');
        $connectionId = (int) $connection->getAttribute('id');
        $connOwner = (int) $connection->getAttribute('user_id');
        $connWebsite = (int) $connection->getAttribute('website_id');
        if ($postOwner < 1 || ($connOwner !== $postOwner && !Database::getInstance()->query(
            'SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1',
            [$connWebsite, $postOwner]
        ))) {
            $this->fail($target, 'Publishing account no longer belongs to the post owner. Reconnect the correct account.');
            return;
        }
        if (class_exists('SocialPackages')) {
            try {
                SocialPackages::assertConnections($postOwner, [$connectionId]);
            } catch (SocialPackageLimit $e) {
                // Never send to the provider once the page is paused or outside the plan.
                // Keep the reason visible in the failed target so its owner can resolve it.
                $this->fail($target, 'Publishing blocked by current subscription or account selection: ' . $e->getMessage());
                return;
            }
        }

        if ($connection->getAttribute('status') !== 'connected') {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'اتصال المنصة غير فعّال (status != connected) - يحتاج إعادة ربط OAuth');
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            return;
        }

        $platform = (string) $connection->getAttribute('platform');

        if (!in_array($platform, ['facebook', 'instagram', 'tiktok', 'youtube', 'google_business', 'threads', 'linkedin'], true)) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', "النشر الفعلي على منصة {$platform} لسه مش متاح (المنصات المدعومة: فيسبوك، انستجرام، تيك توك، يوتيوب).");
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            return;
        }

        try {
            $encryption = new Encryption();
            // المحتوى + الهاشتاجات + تتبّع UTM لروابط موقع العميل (نفس التركيب اللي المصالحة بتطابق بيه)
            $message = SocialLinkTracker::finalMessage($post, $platform, $targetId);
            $isStory = (string) ($post->getAttribute('post_format') ?: 'feed') === 'story';

            $media = $this->resolveMedia((int) $post->getAttribute('media_item_id'));
            if($media&&(int)$media->getAttribute('user_id')!==(int)$post->getAttribute('user_id'))throw new RuntimeException('Media ownership mismatch');
            if($post->getAttribute('media_item_id')&&!$media)throw new RuntimeException('Publish media unavailable');
            $mediaUrl = $media && $platform !== 'youtube' ? (PrivateMedia::valid((string)$media->getAttribute('file_path'))?PrivateMedia::publishUrl($media):$this->toPublicUrl((string)$media->getAttribute('file_path'))) : null;
            $isVideo = $media && $media->getAttribute('type') === 'short_video';

            // ألبوم (صور متعددة): فيسبوك attached_media، إنستجرام كاروسيل. تيك توك/يوتيوب فيديو بس.
            $albumIds = $this->albumMediaIds((int) $post->getAttribute('id'));
            if ($albumIds && !in_array($platform, ['facebook', 'instagram'], true)) {
                $this->fail($target, 'الصور المتعددة متاحة لفيسبوك وإنستجرام بس.');
                return;
            }
            try {
                $albumUrls = $albumIds ? $this->albumUrls($albumIds, (int) $post->getAttribute('user_id')) : [];
            } catch (RuntimeException $e) {
                // لسه ما كلمناش Meta: فشل نهائي واضح مش uncertain
                $this->fail($target, 'إحدى صور الألبوم مش متاحة (اتمسحت أو لسه بتتجهّز). عدّل المنشور واختار الصور تاني.');
                return;
            }

            if ((in_array($platform, ['instagram', 'tiktok'], true) && !$mediaUrl) || ($platform === 'youtube' && !$media)) {
                $this->fail($target, "{$platform} محتاج صورة أو فيديو إجباريًا - المنشور ده مفيهوش وسائط. ارفع صورة أو فيديو من فورم المنشور، أو اختار واحدة من Creative Studio.");
                return;
            }

            if ($isStory) {
                $this->publishStory($target, $post, $platform, $connection, $encryption, $albumUrls, $mediaUrl, (bool) $isVideo);
                return;
            }

            if (in_array($platform, ['google_business', 'threads', 'linkedin'], true)) {
                $this->publishExtra($target, $post, $platform, $connection, $encryption, $message, $media, $albumUrls ? $albumUrls[0] : $mediaUrl, (bool) $isVideo);
                return;
            }

            if (in_array($platform, ['facebook', 'instagram'], true) && ((string) ($target->getAttribute('provider_ref') ?: '')) === '') {
                foreach ($albumUrls ?: [$mediaUrl] as $i => $u) {
                    // الكابشن بيتفحص مع أول صورة بس؛ باقي الصور وسائط بس
                    if (!$this->preflightOk($target, $post, $platform, $i === 0 ? $message : '', $u, (bool) $isVideo)) {
                        return;
                    }
                }
            }

            if ($platform === 'facebook') {
                $pageAccessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
                $api = $this->metaApi($pageAccessToken);
                $pageId = (string) $connection->getAttribute('external_location_id');
                $uid = (int) $post->getAttribute('user_id');

                // Reel قيد المعالجة عند فيسبوك: نتابع الحالة بس (من غير ما نرفع تاني)
                if (str_starts_with((string) ($target->getAttribute('provider_ref') ?: ''), 'reel:')) {
                    $this->pollFacebookReel($target, $post, $api, $pageAccessToken);
                    return;
                }

                $this->markAttempt($target);
                if ($albumUrls) {
                    $result = ExternalExecution::run($this->stepKey($target, 'step8'), $uid, fn () => $api->publishPhotosToFacebookPage($pageId, $pageAccessToken, $message, $albumUrls));
                } elseif ($isVideo) {
                    // الفيديو القصير بيتنشر كـ Reel (3 مراحل + متابعة المعالجة). لو فيسبوك رفضه رفض نهائي بسبب
                    // مقاس/مدة/وسائط نرجع لفيديو عادي. أخطاء التوكن/الصلاحية/حد الاستخدام ما بنعملش لها fallback (هتفشل بنفس الشكل).
                    $result = ExternalExecution::run($this->stepKey($target, 'step0'), $uid, fn () => $api->publishReelToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl));
                    if (!$result['success'] && PublishPolicy::classify($result) === 'failed' && in_array($result['category'] ?? MetaApiError::UNKNOWN, [MetaApiError::MEDIA, MetaApiError::PARAM, MetaApiError::UNKNOWN], true)) {
                        Logger::warning('Facebook reel failed - falling back to regular video', ['target_id' => $targetId, 'stage' => $result['stage'] ?? '', 'error' => $result['error'] ?? '']);
                        $result = ExternalExecution::run($this->stepKey($target, 'step1'), $uid, fn () => $api->publishVideoToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl));
                    } elseif ($result['success'] && !empty($result['processing'])) {
                        $target->setAttribute('provider_ref', 'reel:' . $result['video_id']);
                        $target->setAttribute('external_post_id', $result['video_id']);
                        $target->setAttribute('status', 'publishing');
                        if (!$target->save()) throw new RuntimeException('Target persistence failed');
                        $this->requeue((int) $target->getAttribute('id'));
                        return;
                    }
                } else {
                    $result = ExternalExecution::run($this->stepKey($target, 'step2'), $uid, fn () => $api->publishToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl));
                }

                $this->finishOrFail($target, $post, $platform, $result);
                return;
            }

            if ($platform === 'instagram') {
                $pageAccessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
                $api = $this->metaApi($pageAccessToken);
                $igUserId = (string) $connection->getAttribute('external_location_id');

                $this->markAttempt($target);
                if ($albumUrls) {
                    $this->handleInstagramVideo($target, $post, $api, $igUserId, $pageAccessToken, '', $message, fn () => $api->createInstagramCarouselContainer($igUserId, $pageAccessToken, $albumUrls, $message));
                } elseif ($isVideo) {
                    $this->handleInstagramVideo($target, $post, $api, $igUserId, $pageAccessToken, $mediaUrl, $message);
                } else {
                    $result = ExternalExecution::run($this->stepKey($target, 'step3'), (int) $post->getAttribute('user_id'), fn () => $api->publishToInstagram($igUserId, $pageAccessToken, $mediaUrl, $message));
                    $this->finishOrFail($target, $post, $platform, $result);
                }
                return;
            }

            if ($platform === 'tiktok') {
                // توكن تيك توك بيخلص كل 24 ساعة: بيتجدد هنا قبل النشر
                $api = TikTokAPI::fromConnection($connection, $encryption);

                if ($isVideo) {
                    $this->handleTikTokVideo($target, $post, $api, $mediaUrl, $message);
                } else {
                    $this->fail($target, 'TikTok يدعم نشر الفيديوهات فقط حاليًا.');
                }
                return;
            }

            if ($platform === 'youtube') {
                $accessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
                $api = new YouTubeAPI($accessToken);

                if ($isVideo) {
                    $this->handleYouTubeVideo($target, $post, $api, $media, $message);
                } else {
                    $this->fail($target, 'YouTube Shorts يدعم نشر الفيديوهات فقط.');
                }
                return;
            }

        } catch (Throwable $e) {
            if($target->getAttribute('status')==='published')throw $e;
            $target->setAttribute('status', 'uncertain');
            $target->setAttribute('last_error', self::friendlyError($e->getMessage()));
            $this->setV2($target, ['error_category' => MetaApiError::UNKNOWN]);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->logOutcome($target, $post ?? null, $platform ?? '', 'uncertain', ['category' => MetaApiError::UNKNOWN], mb_substr($e->getMessage(), 0, 290));

            Logger::error('Social Post Publish Exception', [
                'target_id' => $targetId,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    // --- Stories ---
    /**
     * ستوري فيسبوك/إنستجرام: صورة أو فيديو واحد من غير نص (الستوري ما لهاش كابشن).
     * إنستجرام بيعدّي بنفس دورة الـ container (إنشاء ← polling ← نشر). فيسبوك صورة = طلب واحد، فيديو = 3 مراحل.
     */
    private function publishStory(SocialPostTarget $target, SocialPost $post, string $platform, PlatformConnection $connection, Encryption $encryption, array $albumUrls, ?string $mediaUrl, bool $isVideo): void
    {
        if (!in_array($platform, ['facebook', 'instagram'], true)) {
            $this->fail($target, 'الستوري متاحة لفيسبوك وإنستجرام بس.');
            return;
        }
        if ($albumUrls) {
            $this->fail($target, 'الستوري بتاخد صورة أو فيديو واحد بس - شيل الصور المتعددة.');
            return;
        }
        if (!$mediaUrl) {
            $this->fail($target, 'الستوري محتاجة صورة أو فيديو.');
            return;
        }
        $token = $encryption->decrypt((string) $connection->getAttribute('access_token'));
        $api = $this->metaApi($token);
        $accountId = (string) $connection->getAttribute('external_location_id');
        $uid = (int) $post->getAttribute('user_id');

        if ($platform === 'instagram') {
            if ((string) ($target->getAttribute('provider_ref') ?: '') === '') {
                if (!$this->preflightOk($target, $post, $platform, '', $mediaUrl, $isVideo)) {
                    return;
                }
                $this->markAttempt($target);
            }
            $this->handleInstagramVideo($target, $post, $api, $accountId, $token, $mediaUrl, '', fn () => $api->createInstagramStoryContainer($accountId, $token, $mediaUrl, $isVideo));
            return;
        }

        if (!$this->preflightOk($target, $post, $platform, '', $mediaUrl, $isVideo)) {
            return;
        }
        $this->markAttempt($target);
        $result = $isVideo
            ? ExternalExecution::run($this->stepKey($target, 'step9'), $uid, fn () => $api->publishVideoStoryToFacebookPage($accountId, $token, $mediaUrl))
            : ExternalExecution::run($this->stepKey($target, 'step10'), $uid, fn () => $api->publishPhotoStoryToFacebookPage($accountId, $token, $mediaUrl));
        $this->finishOrFail($target, $post, $platform, $result);
    }

    // --- Google Business / Threads / LinkedIn ---
    /** @var callable|null حقن عملاء المنصات الإضافية في الاختبارات: fn(string $platform, PlatformConnection $c, string $token): object */
    public static $extraFactory = null;

    /**
     * نشر على Google Business (Local Post بصورة + زرار للموقع)، Threads (نص/صورة/فيديو)، LinkedIn (نص + صورة).
     * الصور المتعددة: بيتنشر الغلاف (أول صورة) بس على المنصات دي.
     */
    private function publishExtra(SocialPostTarget $target, SocialPost $post, string $platform, PlatformConnection $connection, Encryption $encryption, string $message, ?MediaItem $media, ?string $mediaUrl, bool $isVideo): void
    {
        $uid = (int) $post->getAttribute('user_id');
        $ref = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($platform === 'google_business') {
            if ($isVideo) {
                $this->fail($target, 'Google Business بيقبل صور بس من هنا - اختار صورة للمنشور ده أو شيل Google Business.');
                return;
            }
            $text = trim(preg_replace('/(?:\s*#[\p{L}\p{N}_]+)+\s*$/u', '', $message)); // Google ما بتستخدمش هاشتاجات
            if (mb_strlen($text) > 1500) {
                $this->fail($target, 'نص Google Business أقصاه 1500 حرف - اختصر النص.');
                return;
            }
            $site = Database::getInstance()->query('SELECT main_url, target_language FROM websites WHERE id = ? LIMIT 1', [(int) $post->getAttribute('website_id') ?: 0])[0] ?? null;
            $cta = $site ? (string) $site['main_url'] : null;
            $campaign = (string) ($post->getAttribute('utm_campaign') ?? '');
            if ($cta && $campaign !== '') {
                $cta = SocialLinkTracker::apply($cta, 'google_business', $campaign, SocialLinkTracker::hostsFor($uid, (int) $post->getAttribute('website_id') ?: null));
            }
            $this->markAttempt($target);
            $result = ExternalExecution::run($this->stepKey($target, 'step11'), $uid, function () use ($connection, $text, $site, $cta, $mediaUrl) {
                $api = self::$extraFactory ? (self::$extraFactory)('google_business', $connection, '') : new GoogleBusinessAPI((new GoogleReviewSyncService())->getValidAccessToken($connection), (string) $connection->getAttribute('external_account_id'), (string) $connection->getAttribute('external_location_id'));
                return $api->publishPost($text, ($site['target_language'] ?? '') ?: 'ar', $cta ?: null, ['media_url' => $mediaUrl]);
            });
            $this->finishOrFail($target, $post, $platform, self::extraOutcome($result));
            return;
        }

        $token = $encryption->decrypt((string) $connection->getAttribute('access_token'));

        if ($platform === 'threads') {
            if (mb_strlen($message) > ThreadsAPI::TEXT_LIMIT) {
                $this->fail($target, 'Threads حده ' . ThreadsAPI::TEXT_LIMIT . ' حرف (النص مع الهاشتاجات) - اختصر النص أو شيل Threads.');
                return;
            }
            // التوكن بيعيش 60 يوم: بنجدده لو فاضل أقل من 7 أيام
            $exp = (string) ($connection->getAttribute('token_expires_at') ?? '');
            if ($exp !== '' && strtotime($exp . ' UTC') < time() + 7 * 86400 && !self::$extraFactory) {
                $ref2 = ThreadsAPI::refresh($token);
                if (!empty($ref2['success'])) {
                    $token = $ref2['access_token'];
                    Database::getInstance()->query('UPDATE platform_connections SET access_token = ?, token_expires_at = ? WHERE id = ?', [$encryption->encrypt($token), gmdate('Y-m-d H:i:s', time() + (int) $ref2['expires_in']), (int) $connection->getAttribute('id')]);
                }
            }
            $api = self::$extraFactory ? (self::$extraFactory)('threads', $connection, $token) : new ThreadsAPI($token, (string) $connection->getAttribute('external_location_id'));
            if ($ref === '') {
                $this->markAttempt($target);
                $c = ExternalExecution::run($this->stepKey($target, 'step12'), $uid, fn () => $api->createContainer($message, $isVideo ? null : $mediaUrl, $isVideo ? $mediaUrl : null));
                if (empty($c['success'])) {
                    $this->finishOrFail($target, $post, $platform, self::extraOutcome($c));
                    return;
                }
                if (!$mediaUrl) {
                    $this->finishOrFail($target, $post, $platform, self::extraOutcome(ExternalExecution::run($this->stepKey($target, 'step13'), $uid, fn () => $api->publish((string) $c['container_id']))));
                    return;
                }
                // صورة/فيديو: Threads بتعالج الوسائط الأول - نستنى ونتابع
                $target->setAttribute('provider_ref', 'th:' . $c['container_id']);
                $target->setAttribute('status', 'publishing');
                if (!$target->save()) throw new RuntimeException('Target persistence failed');
                $this->requeue((int) $target->getAttribute('id'), 30);
                return;
            }
            $containerId = substr($ref, 3);
            $attempts = (int) $target->getAttribute('poll_attempts');
            if ($attempts >= self::MAX_POLL_ATTEMPTS) {
                $this->fail($target, 'Threads أخدت وقت طويل في معالجة الوسائط - حاول تاني.');
                return;
            }
            $st = $api->status($containerId);
            if (!empty($st['success']) && in_array($st['status'], ['ERROR', 'EXPIRED'], true)) {
                $this->fail($target, 'Threads رفضت الوسائط: ' . ($st['error'] ?? $st['status']));
                return;
            }
            if (empty($st['success']) || $st['status'] !== 'FINISHED') {
                $target->setAttribute('poll_attempts', $attempts + 1);
                if (!$target->save()) throw new RuntimeException('Target persistence failed');
                $this->requeue((int) $target->getAttribute('id'));
                return;
            }
            $this->finishOrFail($target, $post, $platform, self::extraOutcome(ExternalExecution::run($this->stepKey($target, 'step13'), $uid, fn () => $api->publish($containerId))));
            return;
        }

        // LinkedIn
        if ($isVideo) {
            $this->fail($target, 'LinkedIn: الفيديو لسه مش مدعوم من هنا - استخدم صورة، أو انشر الفيديو من LinkedIn مباشرة.');
            return;
        }
        $path = null;
        if ($media) {
            $fp = (string) $media->getAttribute('file_path');
            $path = PrivateMedia::valid($fp) ? PrivateMedia::resolve($fp) : ROOT_PATH . '/public_html/' . ltrim($fp, '/');
        }
        $api = self::$extraFactory ? (self::$extraFactory)('linkedin', $connection, $token) : new LinkedInAPI($token);
        $this->markAttempt($target);
        $result = ExternalExecution::run($this->stepKey($target, 'step14'), $uid, fn () => $api->publish((string) $connection->getAttribute('external_location_id'), $message, $path));
        $this->finishOrFail($target, $post, $platform, self::extraOutcome($result));
    }

    /** نتيجة المنصات الإضافية بنفس شكل قرار النشر: رفض واضح = فشل، غير كده = غامض (مصالحة يدوية) */
    private static function extraOutcome(array $r): array
    {
        if (!empty($r['success'])) {
            return $r;
        }
        $definitive = !empty($r['definitive']) || in_array((string) ($r['error_code'] ?? ''), ['AUTH_REQUIRED', 'TOKEN_EXPIRED', 'PERMISSION_DENIED', 'NOT_FOUND', 'INVALID_ARGUMENT', 'NOT_SUPPORTED'], true);
        return ['success' => false, 'error' => (string) ($r['error'] ?? 'المنصة رفضت المنشور'), 'outcome_state' => $definitive ? 'failed' : 'uncertain'];
    }

    // --- Instagram ---
    /** @param callable|null $create بديل إنشاء الـ container (الكاروسيل بيستخدم نفس دورة الفيديو: إنشاء ← polling ← نشر) */
    private function handleInstagramVideo(SocialPostTarget $target, SocialPost $post, MetaSocialAPI $api, string $igUserId, string $pageAccessToken, string $videoUrl, string $caption, ?callable $create = null): void
    {
        $containerId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($containerId === '') {
            $result = ExternalExecution::run($this->stepKey($target, 'step4'),(int)$post->getAttribute('user_id'),$create ?? fn()=>$api->createInstagramVideoContainer($igUserId, $pageAccessToken, $videoUrl, $caption));
            if (!$result['success']) {
                $this->applyFailure($target, $post, 'instagram', $result);
                return;
            }

            $target->setAttribute('provider_ref', $result['container_id']);
            $target->setAttribute('status', 'publishing');
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة معالجة فيديو انستجرام - حاول مرة أخرى');
            return;
        }

        $status = $api->checkInstagramContainerStatus($containerId, $pageAccessToken);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة معالجة الفيديو');
            return;
        }

        if ($status['status'] === 'ERROR' || $status['status'] === 'EXPIRED') {
            $this->fail($target, 'فشلت معالجة الفيديو في انستجرام (status: ' . $status['status'] . ($status['detail'] ?? null ? ' - ' . $status['detail'] : '') . ')');
            return;
        }

        if ($status['status'] !== 'FINISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $publishResult = ExternalExecution::run($this->stepKey($target, 'step5'),(int)$post->getAttribute('user_id'),fn()=>$api->publishInstagramContainer($igUserId, $pageAccessToken, $containerId));
        // نمرّر النتيجة كاملة (الأكواد/التصنيف) مش بس الـ success/error، عشان القرار يبقى على الكود
        $this->finishOrFail($target, $post, 'instagram', $publishResult + ['outcome_state' => $publishResult['outcome_state'] ?? 'uncertain']);
    }

    // --- TikTok ---
    private function handleTikTokVideo(SocialPostTarget $target, SocialPost $post, TikTokAPI $api, string $videoUrl, string $title): void
    {
        $publishId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($publishId === '') {
            $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step6',(int)$post->getAttribute('user_id'),fn()=>$api->publishVideo($videoUrl, $title, 'auto', TikTokAPI::optionsFor(Database::getInstance()->getConnection(), (int) $target->getAttribute('id'))));
            if (!$result['success']) {
                $this->fail($target, $result['error'] ?? 'تعذر بدء نشر الفيديو على TikTok',($result['outcome_state']??'uncertain')==='uncertain');
                return;
            }

            $target->setAttribute('provider_ref', $result['publish_id'] ?? 'unknown');
            $target->setAttribute('status', 'publishing');
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة نشر الفيديو على TikTok - حاول مرة أخرى');
            return;
        }

        $status = $api->checkPublishStatus($publishId);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة النشر على TikTok');
            return;
        }

        if ($status['status'] === 'FAILED') {
            $this->fail($target, 'فشل نشر الفيديو على TikTok: ' . ($status['error'] ?? 'سبب غير معروف'));
            return;
        }

        if ($status['status'] !== 'PUBLISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $this->finishOrFail($target, $post, 'tiktok', ['success' => true, 'post_id' => $publishId]);
    }

    // --- YouTube ---
    private function handleYouTubeVideo(SocialPostTarget $target, SocialPost $post, YouTubeAPI $api, MediaItem $media, string $title): void
    {
        $videoId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($videoId === '') {
            $localPath = (string) $media->getAttribute('file_path');
            $fullPath = PrivateMedia::valid($localPath)?PrivateMedia::resolve($localPath):ROOT_PATH . '/public_html/' . ltrim($localPath, '/');

            if (!file_exists($fullPath)) {
                $this->fail($target, 'ملف الفيديو غير موجود على السيرفر: ' . $localPath);
                return;
            }

            // يوتيوب: العنوان أقصاه 100 حرف ومن غير < > (كان النص كله بيتبعت عنوان فالرفع بيفشل مع أي كابشن طويل)
            [$ytTitle, $ytTags] = self::youTubeMeta($title);
            $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step7',(int)$post->getAttribute('user_id'),fn()=>$api->uploadShort($fullPath, $ytTitle, $title, $ytTags, 'public'));
            if (!$result['success']) {
                $this->fail($target, $result['error'] ?? 'تعذر رفع الفيديو على YouTube',($result['outcome_state']??'uncertain')==='uncertain');
                return;
            }

            $target->setAttribute('provider_ref', $result['video_id']);
            $target->setAttribute('status', 'publishing');
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة معالجة الفيديو على YouTube - حاول مرة أخرى');
            return;
        }

        $status = $api->checkVideoStatus($videoId);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة الفيديو على YouTube');
            return;
        }

        if ($status['status'] === 'ERROR') {
            $this->fail($target, 'فشلت معالجة الفيديو على YouTube: ' . ($status['error'] ?? ''));
            return;
        }

        if ($status['status'] !== 'FINISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $this->finishOrFail($target, $post, 'youtube', ['success' => true, 'post_id' => $videoId]);
    }

    /** عنوان يوتيوب (أول سطر من غير هاشتاجات، ≤100 حرف، من غير < >) + tags من الهاشتاجات - نقية */
    public static function youTubeMeta(string $message): array
    {
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $message, $m);
        $tags = array_slice(array_values(array_unique($m[1] ?? [])), 0, 15);
        $first = '';
        foreach (preg_split('/\R/u', $message) as $line) {
            $line = trim(preg_replace('/#[\p{L}\p{N}_]+/u', '', $line));
            if ($line !== '') {
                $first = $line;
                break;
            }
        }
        $first = trim(str_replace(['<', '>'], '', $first));
        if ($first === '') {
            $first = 'Shorts';
        }
        if (mb_strlen($first) > 100) {
            $first = rtrim(mb_substr($first, 0, 97)) . '...';
        }
        return [$first, $tags];
    }

    private function finishOrFail(SocialPostTarget $target, SocialPost $post, string $platform, array $result): void
    {
        $targetId = (int) $target->getAttribute('id');

        if (!$result['success']) {
            $this->applyFailure($target, $post, $platform, $result);
            Logger::error('Social Post Publish Failed', ['target_id' => $targetId, 'platform' => $platform, 'error' => $result['error'] ?? null, 'code' => $result['error_code'] ?? null, 'trace' => $result['fbtrace_id'] ?? null]);
            return;
        }

        $target->setAttribute('status', 'published');
        $target->setAttribute('external_post_id', $result['post_id'] ?? ($result['video_id'] ?? null));
        $target->setAttribute('published_at', date('Y-m-d H:i:s'));
        $target->setAttribute('last_error', null);
        $target->setAttribute('provider_ref', null);
        $this->setV2($target, ['error_code' => null, 'error_category' => null, 'fbtrace_id' => null, 'next_retry_at' => null, 'publish_ms' => $this->elapsedMs()]);
        if(!$target->save())throw new RuntimeException('Target persistence failed');
        $this->logOutcome($target, $post, $platform, 'published');
        $this->maybeFirstComment($target, $post, $platform);

        $platformName = match ($platform) {
            'facebook'  => 'فيسبوك',
            'instagram' => 'انستجرام',
            'tiktok'    => 'تيك توك',
            'youtube'   => 'يوتيوب',
            'google_business' => 'Google Business',
            'threads'   => 'Threads',
            'linkedin'  => 'LinkedIn',
            default     => $platform,
        };

        try {
        if (class_exists('Notification')) {
            Notification::notify(
                (int) $post->getAttribute('user_id'),
                'social_post_published',
                'تم نشر منشورك',
                "اتنشر منشورك على {$platformName} بنجاح.",
                '/social'
            );
        }

        }catch(Throwable $e){Logger::warning('Social publish notification failed',['target_id'=>$targetId]);}

        Logger::info('Social Post Published', ['target_id' => $targetId, 'platform' => $platform, 'post_id' => $result['post_id'] ?? ($result['video_id'] ?? null)]);
    }

    // ------------------------------------------------------------------
    // v5: قرار النتيجة، إعادة المحاولة، المتابعة، السجل
    // ------------------------------------------------------------------

    /** ids صور الألبوم بالترتيب (فاضي = منشور عادي أو الجدول لسه ما اتعملش) */
    private function albumMediaIds(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }
        try {
            $rows = Database::getInstance()->query('SELECT media_item_id FROM social_post_media WHERE social_post_id = ? ORDER BY position ASC', [$postId]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        return array_map(static fn ($r) => (int) $r['media_item_id'], $rows);
    }

    /** روابط عامة لصور الألبوم بنفس قواعد الصورة الواحدة (ملكية + جاهزة). أي صورة ناقصة = فشل بدل نشر ألبوم ناقص */
    private function albumUrls(array $ids, int $userId): array
    {
        $urls = [];
        foreach ($ids as $id) {
            $m = $this->resolveMedia($id);
            if (!$m || (int) $m->getAttribute('user_id') !== $userId) {
                throw new RuntimeException('Publish media unavailable');
            }
            $path = (string) $m->getAttribute('file_path');
            $url = PrivateMedia::valid($path) ? PrivateMedia::publishUrl($m) : $this->toPublicUrl($path);
            if (!$url) {
                throw new RuntimeException('Publish media unavailable');
            }
            $urls[] = $url;
        }
        return $urls;
    }

    private function metaApi(string $token): MetaSocialAPI
    {
        return self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
    }

    /** مفتاح خطوة ExternalExecution: بيتغيّر مع كل إعادة تلقائية عشان محاولة جديدة ما تتحسبش تكرار للقديمة */
    private function stepKey(SocialPostTarget $target, string $step): string
    {
        return 'social:' . $target->getAttribute('id') . ':' . $step . ':r' . (int) $target->getAttribute('retry_count');
    }

    /** الأعمدة الجديدة بتتكتب بس لو migration الموثوقية اتشغّلت - عشان النشر ما يوقعش لو الكود اتنشر قبلها */
    private function hasV2Schema(): bool
    {
        if (self::$v2Schema === null) {
            try {
                self::$v2Schema = (bool) Database::getInstance()->query("SHOW COLUMNS FROM social_post_targets LIKE 'error_code'");
            } catch (Throwable $e) {
                self::$v2Schema = false;
            }
        }
        return self::$v2Schema;
    }

    private function setV2(SocialPostTarget $target, array $attrs): void
    {
        if (!$this->hasV2Schema()) {
            return;
        }
        foreach ($attrs as $k => $v) {
            $target->setAttribute($k, $v);
        }
    }

    private function markAttempt(SocialPostTarget $target): void
    {
        $this->startedAt = microtime(true);
        $this->setV2($target, ['publish_attempt_at' => gmdate('Y-m-d H:i:s')]);
        if ($this->hasV2Schema() && !$target->save()) {
            throw new RuntimeException('Target persistence failed');
        }
    }

    private function elapsedMs(): ?int
    {
        return $this->startedAt > 0 ? (int) ((microtime(true) - $this->startedAt) * 1000) : null;
    }

    /** فحص ما قبل النشر: فشل نهائي بسبب واضح بدل ما Meta ترفض بعدين برسالة غامضة */
    private function preflightOk(SocialPostTarget $target, SocialPost $post, string $platform, string $message, ?string $mediaUrl, bool $isVideo): bool
    {
        if ((string) env('SOCIAL_PREFLIGHT', '1') === '0') {
            return true;
        }
        $pf = (new MediaPreflight())->check(['platform' => $platform, 'caption' => $message, 'media_url' => $mediaUrl, 'is_video' => $isVideo]);
        foreach ($pf['warnings'] as $w) {
            Logger::warning('Social preflight warning', ['target_id' => $target->getAttribute('id'), 'warning' => $w]);
        }
        if ($pf['ok']) {
            return true;
        }
        $target->setAttribute('status', 'failed');
        $target->setAttribute('last_error', 'فحص ما قبل النشر: ' . implode(' | ', $pf['errors']));
        $this->setV2($target, ['error_category' => MetaApiError::MEDIA]);
        if (!$target->save()) {
            throw new RuntimeException('Target persistence failed');
        }
        $this->logOutcome($target, $post, $platform, 'preflight_failed', ['category' => MetaApiError::MEDIA], mb_substr(implode(' | ', $pf['errors']), 0, 290));
        return false;
    }

    /** متابعة Reel فيسبوك لحد ما يخلص معالجة */
    private function pollFacebookReel(SocialPostTarget $target, SocialPost $post, MetaSocialAPI $api, string $pageAccessToken): void
    {
        $videoId = substr((string) $target->getAttribute('provider_ref'), 5);
        $attempts = (int) $target->getAttribute('poll_attempts');

        if ($attempts >= self::REEL_MAX_POLL_ATTEMPTS) {
            // الريل ممكن تكون لسه بتتعالج: uncertain (تتحسم بالمصالحة) مش failed عشان ما نكرّرش النشر
            $this->fail($target, 'المعالجة على فيسبوك أخدت وقت أطول من المتوقع. المنصة هتتحقق تلقائيًا وتحدّث الحالة.', true);
            $this->logOutcome($target, $post, 'facebook', 'uncertain', [], 'reel poll timeout');
            return;
        }

        $st = $api->getFacebookVideoStatus($videoId, $pageAccessToken);
        if (!$st['success']) {
            // فشل فحص الحالة مش معناه فشل النشر: نعدّ المحاولة ونكمّل (إلا لو توكن/صلاحية)
            // التوكن/الصلاحية هنا بتبقى uncertain مش failed: الريل اترفعت وممكن تكون اتنشرت، والإعادة هتكرّرها
            if (in_array($st['category'] ?? '', [MetaApiError::TOKEN, MetaApiError::PERMISSION], true)) {
                $this->applyFailure($target, $post, 'facebook', ['success' => false, 'definitive' => false] + $st);
                return;
            }
            $target->setAttribute('poll_attempts', $attempts + 1);
            if (!$target->save()) throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }
        if ($st['status'] === 'error') {
            $this->fail($target, 'فشلت معالجة الـ Reel على فيسبوك: ' . ($st['error'] ?? ''));
            $this->logOutcome($target, $post, 'facebook', 'failed', ['category' => MetaApiError::MEDIA], (string) ($st['error'] ?? ''));
            return;
        }
        if ($st['status'] !== 'ready') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if (!$target->save()) throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }
        $this->finishOrFail($target, $post, 'facebook', ['success' => true, 'post_id' => $videoId]);
    }

    /**
     * قرار فشل منظّم: retry_later (تأجيل تلقائي بـ backoff) | failed (نهائي) | uncertain (غامض - مصالحة).
     * النتائج اللي من غير أكواد (تيك توك/يوتيوب) بتتحوّل بنفس سلوك v4 عبر outcome_state.
     */
    private function applyFailure(SocialPostTarget $target, SocialPost $post, string $platform, array $result): void
    {
        $decision = PublishPolicy::classify($result);
        $msg = isset($result['friendly']) ? PublishPolicy::describe($result) : self::friendlyError((string) ($result['error'] ?? 'خطأ غير معروف من المنصة'));
        $this->setV2($target, [
            'error_code' => $result['error_code'] ?? null,
            'error_category' => $result['category'] ?? null,
            'fbtrace_id' => $result['fbtrace_id'] ?? null,
            'publish_ms' => $this->elapsedMs(),
        ]);

        if ($decision === 'retry_later' && !$this->hasV2Schema()) {
            // من غير أعمدة retry_count مش هنقدر نعدّ المحاولات: إعادة تلقائية = لوب لا نهائي. نفشل بوضوح بدل كده.
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'حد الاستخدام عند Meta - أعد المحاولة بعد شوية. (شغّل migration الموثوقية لتفعيل الإعادة التلقائية) — ' . $msg);
            if (!$target->save()) throw new RuntimeException('Target persistence failed');
            $this->logOutcome($target, $post, $platform, 'failed', $result, 'retry disabled: v2 schema missing');
            return;
        }

        if ($decision === 'retry_later') {
            $count = (int) $target->getAttribute('retry_count');
            $delay = PublishPolicy::retryDelay($count, isset($result['retry_after']) ? (int) $result['retry_after'] : null, isset($result['error_subcode']) ? (int) $result['error_subcode'] : null);
            if ($delay !== null) {
                $target->setAttribute('status', 'scheduled');
                $target->setAttribute('last_error', 'هنعيد المحاولة تلقائيًا بعد ' . max(1, (int) round($delay / 60)) . ' دقيقة — ' . $msg);
                $this->setV2($target, ['retry_count' => $count + 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay)]);
                if (!$target->save()) throw new RuntimeException('Target persistence failed');
                $this->requeue((int) $target->getAttribute('id'), $delay);
                $this->logOutcome($target, $post, $platform, 'retry_later', $result, 'attempt ' . ($count + 1) . ' in ' . $delay . 's');
                return;
            }
            $decision = 'failed';
            $msg = 'استنفدنا محاولات الإعادة التلقائية — ' . $msg;
        }

        if ($decision === 'uncertain') {
            $msg .= ' (المنصة هتتحقق تلقائيًا إن كان المنشور اتنشر فعلًا قبل أي إعادة)';
        }
        $target->setAttribute('status', $decision === 'uncertain' ? 'uncertain' : 'failed');
        $target->setAttribute('last_error', $msg);
        if (!$target->save()) throw new RuntimeException('Target persistence failed');
        $this->logOutcome($target, $post, $platform, $decision === 'uncertain' ? 'uncertain' : 'failed', $result);
    }

    /** سجل المحاولة لمراقبة معدل النجاح (بيتجاهل الخطأ لو الجدول لسه ما اتعملش) */
    private function logOutcome(SocialPostTarget $target, ?SocialPost $post, string $platform, string $outcome, array $result = [], ?string $note = null): void
    {
        try {
            Database::getInstance()->query(
                'INSERT INTO social_publish_log (target_id, user_id, platform_connection_id, platform, outcome, error_code, error_category, fbtrace_id, duration_ms, note) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [(int) $target->getAttribute('id'), $post ? (int) $post->getAttribute('user_id') : null, (int) $target->getAttribute('platform_connection_id'), $platform, $outcome,
                 $result['error_code'] ?? null, $result['category'] ?? null, $result['fbtrace_id'] ?? null, $this->elapsedMs(), $note]
            );
        } catch (Throwable $e) {
            // الجدول مش موجود قبل migration - مش سبب نوقف النشر
        }
    }

    /**
     * أول تعليق تلقائي (هاشتاجات/رابط). بيتنفذ مرة واحدة لحظة النشر، وفشله ما بيأثرش على المنشور.
     * ⚠️ محتاج صلاحية pages_manage_engagement (فيسبوك) / instagram_manage_comments (إنستجرام) - مش في الصلاحيات الافتراضية.
     */
    private function maybeFirstComment(SocialPostTarget $target, SocialPost $post, string $platform): void
    {
        $comment = trim((string) $post->getAttribute('first_comment'));
        $objectId = (string) $target->getAttribute('external_post_id');
        if ($comment === '' || $objectId === '' || !in_array($platform, ['facebook', 'instagram'], true) || (string) $post->getAttribute('post_format') === 'story') {
            return;
        }
        try {
            $connection = (new PlatformConnection())->find((int) $target->getAttribute('platform_connection_id'));
            $token = (new Encryption())->decrypt((string) $connection->getAttribute('access_token'));
            $r = $this->metaApi($token)->commentOnObject($objectId, $token, $comment);
            if (!$r['success']) {
                Logger::warning('Social first comment failed', ['target_id' => $target->getAttribute('id'), 'error' => $r['error'] ?? '', 'category' => $r['category'] ?? '']);
            }
        } catch (Throwable $e) {
            Logger::warning('Social first comment exception', ['target_id' => $target->getAttribute('id'), 'message' => $e->getMessage()]);
        }
    }

    /** رسالة Meta الخام + شرح بالعربي للأخطاء المشهورة عشان العميل يعرف يعمل إيه */
    public static function friendlyError(string $msg): string
    {
        $m = strtolower($msg);
        $hint = '';
        if (str_contains($m, 'aspect ratio')) {
            $hint = 'مقاس الصورة مش مقبول في إنستجرام (لازم بين 4:5 و 1.91:1). ارفعها تاني من المنصة وهي هتظبط المقاس.';
        } elseif (str_contains($m, 'only photo or video') || str_contains($m, 'image format') || str_contains($m, 'jpeg')) {
            $hint = 'إنستجرام بيقبل صور JPG بس. ارفع الصورة من فورم المنشور والمنصة هتحولها.';
        } elseif (str_contains($m, 'media download') || str_contains($m, 'could not be fetched') || str_contains($m, 'failed to download')) {
            $hint = 'Meta ماقدرتش تحمّل الصورة/الفيديو من موقعك. اتأكد إن الموقع شغال على https ومفيش حماية (WAF/Cloudflare) بتمنع فولدر /uploads.';
        } elseif (str_contains($m, '(#200)') || str_contains($m, '(#10)') || str_contains($m, 'permission') || str_contains($m, 'not authorized')) {
            $hint = 'صلاحية ناقصة من Meta. اربط فيسبوك وإنستجرام تاني من صفحة السوشيال ووافق على كل الصلاحيات.';
        } elseif (str_contains($m, 'session has expired') || str_contains($m, 'error validating access token') || str_contains($m, '(#190)') || str_contains($m, 'code 190')) {
            $hint = 'انتهت صلاحية الربط. اربط فيسبوك وإنستجرام تاني من صفحة السوشيال.';
        } elseif (str_contains($m, 'application does not have the capability') || str_contains($m, 'development mode')) {
            $hint = 'تطبيق Meta لسه في وضع التطوير. لازم الحساب اللي بتنشر بيه يكون Admin/Tester في التطبيق، أو التطبيق يتنشر (Live) بعد المراجعة.';
        } elseif (str_contains($m, 'rate limit') || str_contains($m, '(#4)') || str_contains($m, '(#17)') || str_contains($m, '(#32)') || str_contains($m, '(#613)')) {
            $hint = 'Meta موقفة الطلبات مؤقتًا (حد الاستخدام). جرّب تاني بعد شوية.';
        }
        return $hint ? $hint . ' — ' . $msg : $msg;
    }

    private function fail(SocialPostTarget $target, string $message,bool $uncertain=false): void
    {
        $target->setAttribute('status', $uncertain?'uncertain':'failed');
        $target->setAttribute('last_error', self::friendlyError($message));
        if(!$target->save())throw new RuntimeException('Target persistence failed');
    }

    private function requeue(int $targetId, int $delaySeconds = self::POLL_DELAY_SECONDS): void
    {
        $queue = Container::getInstance()->make(QueueManager::class);
        $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => $targetId], 'social', $delaySeconds);
    }

    private function resolveMedia(int $mediaItemId): ?MediaItem
    {
        if (!$mediaItemId) {
            return null;
        }
        $media = (new MediaItem())->find($mediaItemId);
        if (!$media || $media->getAttribute('deleted_at') || $media->getAttribute('status') !== 'completed' || !$media->getAttribute('file_path')) {
            return null;
        }
        return $media;
    }

    private function toPublicUrl(string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }
        if (strpos($filePath, 'uploads/') === 0 || strpos($filePath, '/uploads/') === 0) {
            $appUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/');
            return $appUrl . '/' . ltrim($filePath, '/');
        }
        return null;
    }
}
