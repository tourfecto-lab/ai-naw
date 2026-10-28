<?php

/**
 * Tourfecto - Google Review Sync Service
 * سحب المراجعات الجديدة من كل حسابات Google Business المربوطة
 * @version 1.0.0
 *
 * ملاحظة: Google مبيقدّمش webhook فوري للمراجعات الجديدة، فالطريقة
 * الرسمية الوحيدة هي polling دوري - السكريبت ده مصمم يتنده من Cron Job
 * (شوف cron/sync_google_reviews.php) كل فترة (مثلاً كل 6 ساعات).
 */
class GoogleReviewSyncService
{
    /** @var Database */
    private $db;
    /** @var Encryption */
    private $encryption;
    private GoogleOAuthClient $oauthClient;
    private ReputationManager $reputationManager;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->oauthClient = new GoogleOAuthClient();
        $this->reputationManager = new ReputationManager();
    }

    /**
     * مزامنة كل الاتصالات المربوطة (كل عملاء المنصة دفعة واحدة).
     * @return array ['synced'=>int, 'new_reviews'=>int, 'errors'=>int]
     */
    public function syncAll(): array
    {
        $summary = ['synced' => 0, 'new_reviews' => 0, 'errors' => 0];

        try {
            // الاتصالات اللي فشلت مرة (خطأ مؤقت من Google مثلًا) بنحاول
            // معاها تاني - قبل كده كانت بتقف للأبد بعد أول خطأ.
            $connections = array_merge(
                (new PlatformConnection())->where(['platform' => 'google_business', 'status' => 'connected']),
                (new PlatformConnection())->where(['platform' => 'google_business', 'status' => 'error'])
            );
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('GoogleReviewSyncService: تعذر جلب الاتصالات', ['error' => $e->getMessage()]);
            }
            return $summary;
        }

        foreach ($connections as $connection) {
            $wasConnected = $connection->getAttribute('status') === 'connected';
            try {
                $result = $this->syncConnection($connection);
                $summary['synced']++;
                $summary['new_reviews'] += $result['new_reviews'];
            } catch (Throwable $e) {
                $summary['errors']++;
                if ($wasConnected) {
                    self::notifyConnectionBroken((int) $connection->getAttribute('user_id'));
                }
                if (class_exists('Logger')) {
                    Logger::error('Google review sync failed for connection', [
                        'connection_id' => $connection->getAttribute('id'),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }

    /**
     * مزامنة اتصال واحد فورًا (Manual Sync من واجهة GBP Module) - غلاف عام
     * حوالين syncConnection() الموجودة بالفعل، من غير تكرار منطقها.
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function syncOne(PlatformConnection $connection): array
    {
        return $this->syncConnection($connection);
    }

    /** رابط كتابة تقييم من Place ID (رابط جوجل الرسمي اللي بيفتح نافذة التقييم على طول) */
    public static function reviewLinkFromPlaceId(?string $placeId): ?string
    {
        $placeId = trim((string) $placeId);
        return preg_match('/^[A-Za-z0-9_-]{10,200}$/', $placeId) ? 'https://search.google.com/local/writereview?placeid=' . $placeId : null;
    }

    private static function fillGoogleReviewLink(GoogleBusinessAPI $api, int $websiteId): void
    {
        if (!class_exists('ReviewRequestService')) {
            return;
        }
        try {
            $rr = new ReviewRequestService();
            $settings = $rr->getSettings($websiteId);
            if (!empty($settings['google_review_link'])) {
                return; // العميل حاطط رابطه بنفسه - مابنغيروش
            }
            $loc = $api->getLocation();
            $link = !empty($loc['success']) ? self::reviewLinkFromPlaceId($loc['location']['place_id'] ?? null) : null;
            if ($link) {
                // بنبعت الإعدادات كلها (مش الرابط بس) عشان لو مفيش صف لسه، الصف الجديد ياخد نفس القيم الافتراضية
                $keep = array_diff_key($settings, ['id' => 1, 'created_at' => 1, 'updated_at' => 1]);
                $rr->saveSettings($websiteId, array_merge($keep, ['google_review_link' => $link], empty($settings['default_review_link']) ? ['default_review_link' => $link] : []));
            }
        } catch (Throwable $e) {
            // تحسين ثانوي؛ مايوقفش المزامنة
        }
    }

    /** للاختبار: مصدر تقييمات بديل لـ Google */
    public ?Closure $apiFactory = null;

    private function storedCount(int $websiteId): int
    {
        try {
            return (int) ($this->db->query("SELECT COUNT(*) AS n FROM reviews WHERE website_id = ? AND source_platform = 'google_business'", [$websiteId])[0]['n'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** حالة الرد على تقييم موجود = اللي على جوجل فعلاً */
    private function reconcileReply(int $websiteId, string $reviewId, array $review): void
    {
        try {
            $db = $this->db;
            $row = $db->query("SELECT id, reply_sent_at, ai_generated_reply, updated_at FROM reviews WHERE website_id = ? AND source_platform = 'google_business' AND external_review_id = ? LIMIT 1", [$websiteId, $reviewId])[0] ?? null;
            if (!$row) return;
            $google = trim((string) ($review['response'] ?? ''));
            if ($google !== '') {
                if (empty($row['reply_sent_at']) || trim((string) $row['ai_generated_reply']) !== $google) {
                    (new ReputationManager())->markExistingReply((int) $row['id'], $google, (string) ($review['response_time'] ?? ''));
                }
            } elseif (!empty($row['reply_sent_at']) && strtotime((string) $row['reply_sent_at']) < time() - 600) {
                // متعلّم «تم الرد» عندنا ومفيش رد على جوجل: الرد ماوصلش (أو اتمسح) - يرجع مسودة عشان يترد عليه
                $db->query("UPDATE reviews SET reply_sent_at = NULL, reply_sent = 0, reply_status = 'pending', updated_at = NOW() WHERE id = ?", [(int) $row['id']]);
            }
        } catch (Throwable $e) {
        }
    }

    /** موقع موجود فعلًا ويخص صاحب الربط، وإلا أول موقع شغال عنده (ويتحفظ في الربط)، أو 0 لو مالوش مواقع */
    private function resolveWebsite(PlatformConnection $connection, int $websiteId, int $userId): int
    {
        if ($userId <= 0) return 0;
        // صاحب الربط اتمسح حسابه (الموقع والربط فضلوا): الحفظ هيفشل دايمًا، فنقفل الربط بدل خطأ كل 10 دقايق
        if (!$this->db->query('SELECT id FROM users WHERE id = ? LIMIT 1', [$userId])) {
            try {
                $connection->setAttribute('status', 'disconnected');
                $connection->setAttribute('last_error', 'owner account no longer exists');
                $connection->save();
            } catch (Throwable $e) {
            }
            if (class_exists('Logger')) Logger::warning('Google reviews: connection closed, owner account is gone', ['user_id' => $userId, 'website_id' => $websiteId]);
            return 0;
        }
        $alive = class_exists('WebsiteSchema') ? WebsiteSchema::alive() : '';
        if ($websiteId > 0 && $this->db->query("SELECT id FROM websites WHERE id = ? AND user_id = ?{$alive} LIMIT 1", [$websiteId, $userId])) {
            return $websiteId;
        }
        $row = $this->db->query("SELECT id FROM websites WHERE user_id = ?{$alive} ORDER BY id ASC LIMIT 1", [$userId])[0] ?? null;
        $newId = (int) ($row['id'] ?? 0);
        if ($newId > 0) {
            try {
                $connection->setAttribute('website_id', $newId);
                $connection->save();
            } catch (Throwable $e) {
            }
            if (class_exists('Logger')) Logger::info('Google reviews: connection moved to the customer\'s current website', ['user_id' => $userId, 'from' => $websiteId, 'to' => $newId]);
        }
        return $newId;
    }

    private function syncConnection(PlatformConnection $connection): array
    {
        $accessToken = $this->getValidAccessToken($connection);

        $api = $this->apiFactory ? ($this->apiFactory)($connection) : new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        // كل الصفحات (قبل كده كانت أول 50 تقييم بس، فالقديم ماكانش بيتسحب أبدًا)
        $newCount = 0;
        $failed = 0;
        $lastFailure = '';
        $websiteId = (int) $connection->getAttribute('website_id');
        $userId = (int) $connection->getAttribute('user_id');
        // الموقع المربوط اتمسح (أو الربط اتعمل قبل أي موقع): ننقل الربط لموقع العميل الحالي بدل خطأ كل 10 دقايق في الحفظ
        $websiteId = $this->resolveWebsite($connection, $websiteId, $userId);
        if (!$websiteId) {
            return ['new_reviews' => 0, 'failed' => 0, 'skipped' => 'no_website'];
        }
        $pageToken = null;
        $pages = 0;
        $firstSync = !$connection->getAttribute('last_synced_at');
        do {
        $result = $api->getReviews(['limit' => 50, 'page_token' => $pageToken]);

        if (!$result['success']) {
            if ($pages > 0) break; // صفحة متأخرة فشلت: اللي اتسحب يفضل، والمزامنة الجاية تكمّل
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', $result['error'] ?? 'خطأ غير معروف');
            $connection->save();
            throw new Exception($result['error'] ?? 'فشل جلب المراجعات');
        }
        $pageNew = 0;
        foreach ($result['reviews'] as $review) {
            $reviewId = $review['id'] ?? null;
            if (!$reviewId) {
                continue;
            }
            if ($this->reviewExists($websiteId, 'google_business', $reviewId)) {
                // تقييم قديم اتسحب قبل ما نحفظ الصور: نكمّل صورة صاحبه من غير ما نلمس أي حاجة تانية
                $this->backfillReviewerPhoto($websiteId, $reviewId, $review['reviewer']['photo'] ?? null);
                // حالة الرد من جوجل نفسه هي الحقيقة («تم الرد» كانت بتظهر على تقييمات مالهاش رد على جوجل)
                $this->reconcileReply($websiteId, $reviewId, $review);
                continue;
            }
            $pageNew++;
            $reviewTs = !empty($review['date']) ? strtotime((string) $review['date']) : time();

            $processed = $this->reputationManager->processWebhook([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'platform' => 'google_business',
                'platform_review_id' => $reviewId,
                'reviewer_name' => $review['reviewer']['name'] ?? 'عميل Google',
                'reviewer_photo' => $review['reviewer']['photo'] ?? null,
                'review_text' => $review['text'] ?? '',
                'rating' => $review['rating'] ?? 0,
                'review_date' => $review['date'] ?? date('Y-m-d H:i:s'),
                'review_language' => ReviewReplyFallback::language((string) ($review['text'] ?? '')),
                'existing_reply' => $review['response'] ?? null,
                'existing_reply_at' => $review['response_time'] ?? null,
                // أكتر من 14 يوم أو أول مزامنة لحساب جديد: نسجّله بس (من غير رد تلقائي ولا تنبيه ولا ذكاء صناعي)
                'historical' => $firstSync || ($reviewTs && $reviewTs < time() - 14 * 86400),
            ]);

            // GBP Automated Reply Rules (2026-08-15): بعد ما المراجعة تتسجّل،
            // نشغّل قواعد الرد التلقائي (BirdAI/Podium-style) عليها فورًا -
            // لو في قاعدة مطابقة مفعّلة، بتتولّد الرد وتتبعت (أو إشعار بس).
            // فشل صامت: القواعد تحسين ثانوي مش لازم يوقف المزامنة الأساسية.
            if (isset($processed['review_id']) && (int) $processed['review_id'] > 0 && class_exists('GbpReplyRuleService') && empty($review['response']) && !($firstSync || ($reviewTs && $reviewTs < time() - 14 * 86400))) {
                try {
                    (new GbpReplyRuleService())->applyRulesToReview((int) $processed['review_id']);
                } catch (Throwable $e) {
                    // تجاهل - يفضل مزامنة المراجعات الأساسية شغالة
                }
            }

            // ربط جديد: نفحص لو المراجعة الجديدة دي جت من ضيف بعتنالوه
            // طلب مراجعة قبل كده (اسم متطابق + لسه مفيش رد "قيّم فعلاً") -
            // لو كده، نعلّم الطلب "قيّم" تلقائيًا بدل ما يفضل عالق على
            // "اتبعتت" للأبد من غير ما حد يتابعها يدوي.
            if (class_exists('ReviewRequestService')) {
                try {
                    (new ReviewRequestService())->markReviewedIfMatching($websiteId, $review['reviewer']['name'] ?? '');
                } catch (Exception $e) {
                    // فشل صامت - مطابقة المراجعات تحسين ثانوي، مش لازم يوقف المزامنة الأساسية
                }
            }

            if (empty($processed['success'])) {
                // قبل كده كانت بتتعد "جديدة" حتى لو الحفظ فشل، فالعميل يشوف رقم ومايلاقيش التقييمات
                $failed++;
                $lastFailure = (string) ($processed['error'] ?? '');
                continue;
            }
            $newCount++;
        }

        $pageToken = $result['next_page_token'] ?? null;
        $pages++;
        // مزامنة عادية: صفحة كلها متسجلة قبل كده يبقى وصلنا للقديم المحفوظ (إلا لو لسه ناقص تقييمات)
        if (!$firstSync && $pageNew === 0 && $this->storedCount($websiteId) >= (int) ($result['total'] ?? 0)) break;
        } while ($pageToken && $pages < 40);

        // المشاعر اللي ماتتفقش مع النجوم (5 نجوم «محايد» / نجمة واحدة من غير مشاعر) بتتصلح من النجوم
        try {
            $this->db->query("UPDATE reviews SET sentiment = 'positive' WHERE website_id = ? AND rating >= 4 AND (sentiment IS NULL OR sentiment = 'neutral')", [$websiteId]);
            $this->db->query("UPDATE reviews SET sentiment = 'negative' WHERE website_id = ? AND rating BETWEEN 1 AND 2 AND (sentiment IS NULL OR sentiment = 'neutral')", [$websiteId]);
            $this->db->query("UPDATE reviews SET sentiment = 'neutral' WHERE website_id = ? AND rating = 3 AND sentiment IS NULL", [$websiteId]);
        } catch (Throwable $e) {
        }

        $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
        $connection->setAttribute('status', 'connected');
        $connection->setAttribute('last_error', null);
        $connection->save();

        // رابط "اكتب تقييم" على جوجل لطلبات المراجعة: لو العميل ماحطوش، بنجيبه من الـ Place ID بتاع الفرع
        self::fillGoogleReviewLink($api, $websiteId);

        if ($newCount > 0 && class_exists('Notification')) {
            Notification::notify(
                $userId,
                'review_received',
                $newCount === 1 ? 'مراجعة جديدة على Google' : "{$newCount} مراجعات جديدة على Google",
                'وصلتلك مراجعات جديدة من عملاء - راجعها ورد عليها.',
                '/reputation/reviews'
            );
        }

        if ($failed > 0 && class_exists('Logger')) {
            Logger::warning('Google reviews not saved', ['connection_id' => $connection->getAttribute('id'), 'failed' => $failed, 'error' => $lastFailure]);
        }

        return ['new_reviews' => $newCount, 'failed' => $failed, 'total_from_google' => (int) ($result['total'] ?? 0), 'stored' => $this->storedCount($websiteId), 'pages' => $pages];
    }

    /**
     * تنبيه العميل مرة واحدة لما الربط يقف (مش كل دورة): المنصة مش هتقدر
     * تسحب التقييمات ولا ترد عليها لحد ما يعيد الربط.
     */
    private static function notifyConnectionBroken(int $userId): void
    {
        if ($userId <= 0 || !class_exists('Notification')) {
            return;
        }
        try {
            Notification::notify(
                $userId,
                'integration_error',
                'ربط Google Business وقف',
                'مش قادرين نسحب تقييماتك من Google أو نرد عليها. ادخل صفحة الربط واضغط "إعادة الربط" جنب موقعك، وسجّل بنفس حساب Google اللي بتدير بيه النشاط.',
                '/integrations'
            );
        } catch (Throwable $e) {
            // التنبيه تحسين - مش لازم يوقف المزامنة
        }
    }

    /** صورة صاحب التقييم بتتخزن جوه webhook_raw_data (مفيش عمود ليها) - بنضيفها للتقييمات القديمة مرة واحدة */
    private function backfillReviewerPhoto(int $websiteId, string $platformReviewId, ?string $photo): void
    {
        if (!$photo || !preg_match('#^https://#', $photo)) {
            return;
        }
        try {
            $this->db->query(
                "UPDATE reviews SET webhook_raw_data = JSON_SET(COALESCE(NULLIF(webhook_raw_data, ''), '{}'), '$.reviewer_photo', ?)
                 WHERE website_id = ? AND source_platform = 'google_business' AND external_review_id = ?
                   AND JSON_VALID(COALESCE(NULLIF(webhook_raw_data, ''), '{}'))
                   AND JSON_EXTRACT(COALESCE(NULLIF(webhook_raw_data, ''), '{}'), '$.reviewer_photo') IS NULL",
                [$photo, $websiteId, $platformReviewId]
            );
        } catch (Throwable $e) {
            // تحسين شكلي؛ مايوقفش المزامنة
        }
    }

    private function reviewExists(int $websiteId, string $platform, string $platformReviewId): bool
    {
        try {
            $sql = "SELECT id FROM reviews WHERE website_id = ? AND source_platform = ? AND external_review_id = ? LIMIT 1";
            $result = $this->db->query($sql, [$websiteId, $platform, $platformReviewId]);
            return !empty($result);
        } catch (Exception $e) {
            // في حالة الشك، اعتبرها موجودة عشان منكررش المراجعة بالغلط
            return true;
        }
    }

    /**
     * يرجّع access_token صالح، وبيجدده تلقائيًا لو منتهي باستخدام refresh_token.
     * عام (مش private) عشان ReputationController يقدر يستخدمه وقت إرسال
     * رد فعلي على مراجعة، مش بس وقت المزامنة الدورية.
     */
    /**
     * Round 8 (2026-08-14 - Phase D): حماية حقيقية من Race Condition لما
     * أكتر من Job/Request يحاولوا يجدّدوا نفس التوكن في نفس الوقت (مثال
     * واقعي: مستخدم ضغط "مزامنة الآن" في نفس اللحظة اللي GbpBackgroundSyncJob
     * شغّال فيها لنفس الاتصال). المشكلة الحقيقية مش بس استدعاء Google
     * مرتين زيادة - المشكلة إن Model::save() بيكتب كل الـ attributes
     * الموجودة في الذاكرة، فلو Process B حمّل $connection قبل ما
     * Process A يحدّثها، Process B ممكن يكتب فوق تحديثات A (status,
     * last_synced_at...) بقيم قديمة لما يعمل save() بعد كده.
     *
     * الحل: SELECT ... FOR UPDATE جوه transaction حقيقي (Database class
     * بتدعمه فعلاً) - بيقفل الصف لحد ما نخلص. بعد ما ناخد القفل، بنعيد
     * فحص انتهاء الصلاحية تاني (Double-Checked Locking) - لو حد تاني
     * جدّد التوكن وإحنا مستنيين القفل، بنستخدم التوكن الجديد بتاعه بدل
     * ما نعمل تجديد تاني من غير داعي.
     */
    public function getValidAccessToken(PlatformConnection $connection): string
    {
        if (!$connection->isTokenExpired()) {
            return $this->encryption->decrypt((string) $connection->getAttribute('access_token'));
        }

        $connectionId = (int) $connection->getAttribute('id');
        if (!$connectionId) {
            // Model لسه معملوش save() قبل كده (مفيش id) - مينفعش نقفل صف مش موجود، رجّع لنفس السلوك القديم
            return $this->refreshAndPersist($connection);
        }

        $db = Database::getInstance();
        $lockedRow = null;

        try {
            $db->beginTransaction();

            $rows = $db->query(
                "SELECT access_token, token_expires_at, refresh_token FROM platform_connections WHERE id = ? FOR UPDATE",
                [$connectionId]
            );
            $lockedRow = $rows[0] ?? null;

            if ($lockedRow === null) {
                $db->rollback();
                throw new Exception('الاتصال غير موجود');
            }

            // إعادة فحص انتهاء الصلاحية بعد أخذ القفل - لو حد تاني جدّد
            // التوكن وإحنا مستنيين، منعملش تجديد تاني من غير داعي
            $expiresAt = $lockedRow['token_expires_at'] ?? null;
            $stillExpired = !$expiresAt || strtotime($expiresAt) <= time();

            if (!$stillExpired) {
                $db->commit();
                return $this->encryption->decrypt((string) $lockedRow['access_token']);
            }

            $refreshTokenEncrypted = $lockedRow['refresh_token'];
            if (!$refreshTokenEncrypted) {
                $db->query("UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?", [
                    'انتهت صلاحية التوكن ومفيش refresh token - محتاج يعيد الربط يدويًا', $connectionId,
                ]);
                $db->commit();
                throw new Exception('لا يوجد refresh_token صالح');
            }

            $refreshToken = $this->encryption->decrypt((string) $refreshTokenEncrypted);
            $result = $this->oauthClient->refreshAccessToken($refreshToken);

            if (!$result['success']) {
                $db->query("UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?", [
                    'فشل تجديد التوكن: ' . ($result['error'] ?? ''), $connectionId,
                ]);
                $db->commit();
                throw new Exception('فشل تجديد access_token');
            }

            $newAccessTokenEncrypted = $this->encryption->encrypt($result['access_token']);
            $newExpiresAt = date('Y-m-d H:i:s', time() + (int) $result['expires_in']);

            $db->query(
                "UPDATE platform_connections SET access_token = ?, token_expires_at = ? WHERE id = ?",
                [$newAccessTokenEncrypted, $newExpiresAt, $connectionId]
            );
            $db->commit();

            // نحدّث الـ Model في الذاكرة كمان عشان الاستدعاءات اللي بعد
            // كده في نفس الـ request تشوف القيم الجديدة (مش القديمة اللي
            // كانت متحمّلة قبل القفل)
            $connection->setAttribute('access_token', $newAccessTokenEncrypted);
            $connection->setAttribute('token_expires_at', $newExpiresAt);

            return $result['access_token'];
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
                // rollback على transaction مش مفتوحة أصلاً - نتجاهل
            }
            throw $e;
        }
    }

    /** المسار القديم (من غير قفل) - Fallback بس لو الاتصال لسه من غير id */
    private function refreshAndPersist(PlatformConnection $connection): string
    {
        $refreshTokenEncrypted = $connection->getAttribute('refresh_token');
        if (!$refreshTokenEncrypted) {
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', 'انتهت صلاحية التوكن ومفيش refresh token - محتاج يعيد الربط يدويًا');
            $connection->save();
            throw new Exception('لا يوجد refresh_token صالح');
        }

        $refreshToken = $this->encryption->decrypt((string) $refreshTokenEncrypted);
        $result = $this->oauthClient->refreshAccessToken($refreshToken);

        if (!$result['success']) {
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', 'فشل تجديد التوكن: ' . ($result['error'] ?? ''));
            $connection->save();
            throw new Exception('فشل تجديد access_token');
        }

        $connection->setAttribute('access_token', $this->encryption->encrypt($result['access_token']));
        $connection->setAttribute('token_expires_at', date('Y-m-d H:i:s', time() + (int) $result['expires_in']));
        $connection->save();

        return $result['access_token'];
    }
}
