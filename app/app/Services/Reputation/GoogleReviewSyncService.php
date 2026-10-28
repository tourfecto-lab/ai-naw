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

    private function syncConnection(PlatformConnection $connection): array
    {
        $accessToken = $this->getValidAccessToken($connection);

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $result = $api->getReviews(['limit' => 50]);

        if (!$result['success']) {
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', $result['error'] ?? 'خطأ غير معروف');
            $connection->save();
            throw new Exception($result['error'] ?? 'فشل جلب المراجعات');
        }

        $newCount = 0;
        $websiteId = (int) $connection->getAttribute('website_id');
        $userId = (int) $connection->getAttribute('user_id');

        foreach ($result['reviews'] as $review) {
            $reviewId = $review['id'] ?? null;
            if (!$reviewId || $this->reviewExists($websiteId, 'google_business', $reviewId)) {
                continue; // مراجعة موجودة بالفعل أو بدون معرف صالح
            }

            $processed = $this->reputationManager->processWebhook([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'platform' => 'google_business',
                'platform_review_id' => $reviewId,
                'reviewer_name' => $review['reviewer']['name'] ?? 'عميل Google',
                'review_text' => $review['text'] ?? '',
                'rating' => $review['rating'] ?? 0,
                'review_date' => $review['date'] ?? date('Y-m-d H:i:s'),
                'review_language' => ReviewReplyFallback::language((string) ($review['text'] ?? '')),
            ]);

            // GBP Automated Reply Rules (2026-08-15): بعد ما المراجعة تتسجّل،
            // نشغّل قواعد الرد التلقائي (BirdAI/Podium-style) عليها فورًا -
            // لو في قاعدة مطابقة مفعّلة، بتتولّد الرد وتتبعت (أو إشعار بس).
            // فشل صامت: القواعد تحسين ثانوي مش لازم يوقف المزامنة الأساسية.
            if (isset($processed['review_id']) && (int) $processed['review_id'] > 0 && class_exists('GbpReplyRuleService')) {
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

            $newCount++;
        }

        $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
        $connection->setAttribute('status', 'connected');
        $connection->setAttribute('last_error', null);
        $connection->save();

        if ($newCount > 0 && class_exists('Notification')) {
            Notification::notify(
                $userId,
                'review_received',
                $newCount === 1 ? 'مراجعة جديدة على Google' : "{$newCount} مراجعات جديدة على Google",
                'وصلتلك مراجعات جديدة من عملاء - راجعها ورد عليها.',
                '/reputation/reviews'
            );
        }

        return ['new_reviews' => $newCount];
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
