<?php

/**
 * Tourfecto - GBP Photos/Media Service
 * إدارة صور Google Business Profile عن طريق Media API الرسمي
 * (accounts.locations.media). الرفع الفعلي لجوجل بيحتاج sourceUrl عام،
 * فبنرفع الملف أولاً لتخزين المشروع (زي أي رفع صورة تاني في النظام)
 * ثم نبعت رابطها لجوجل - مفيش رفع بايتات وهمي أو محاكاة.
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpPhotoService
{
    /** @var Database */
    private $db;
    /** @var GbpSyncService */
    private $sync;
    /** @var GoogleReviewSyncService */
    private $reviewSync;

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB - نفس حد Google الرسمي الأدنى لجودة مقبولة والأقصى العملي للرفع من المتصفح
    private const ALLOWED_CATEGORIES = [
        'COVER', 'PROFILE', 'EXTERIOR', 'INTERIOR', 'PRODUCT', 'AT_WORK',
        'FOOD_AND_DRINK', 'MENU', 'COMMON_AREA', 'ROOMS', 'TEAMS', 'ADDITIONAL',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->sync = new GbpSyncService();
        $this->reviewSync = new GoogleReviewSyncService();
    }

    public function validateUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'فشل رفع الملف'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['valid' => false, 'error' => 'حجم الصورة أكبر من 5 ميجابايت'];
        }
        $mime = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : null;
        if ($mime && !in_array($mime, self::ALLOWED_MIME, true)) {
            return ['valid' => false, 'error' => 'نوع الملف غير مدعوم - JPEG/PNG/WEBP فقط'];
        }
        return ['valid' => true];
    }

    /**
     * Round 6 (2026-08-11): الرفع بقى Async حقيقي - الدالة دي بس بتحفظ
     * صف "uploading" محلي وترجع فورًا، والرفع الفعلي لجوجل (اللي بياخد
     * وقت، شبكة، وممكن يفشل) بيتم في الخلفية عن طريق GbpPhotoUploadJob.
     * الـ request بتاع المستخدم مبيستناش رد Google API.
     */
    public function queueUpload(int $websiteId, int $userId, string $publicSourceUrl, string $category): array
    {
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return ['success' => false, 'error' => 'تصنيف الصورة غير صحيح'];
        }

        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        try {
            $photoId = $this->db->query(
                "INSERT INTO gbp_photos (website_id, user_id, connection_id, google_media_name, category, source_url, uploaded_by_user_id, status, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, 'uploading', NOW())",
                [$websiteId, $userId, (int) $connection->getAttribute('id'), $category, $publicSourceUrl, $userId]
            );
        } catch (Throwable $e) {
            Logger::error('GBP queue photo upload: تعذر إنشاء صف uploading', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر بدء رفع الصورة'];
        }

        if (!is_int($photoId)) {
            return ['success' => false, 'error' => 'تعذر بدء رفع الصورة'];
        }

        $enqueued = false;
        if (function_exists('enqueue')) {
            $enqueued = (bool) enqueue('GbpPhotoUploadJob', ['photo_id' => $photoId], 'gbp_photos');
        }

        if (!$enqueued) {
            // لو نظام الطابور مش متاح لأي سبب، نرفع فورًا (Synchronous) بدل
            // ما نسيب صف "uploading" عالق للأبد من غير أي محاولة تنفيذ.
            $this->processUpload($photoId);
        }

        return ['success' => true, 'photo_id' => $photoId, 'queued' => $enqueued];
    }

    /**
     * التنفيذ الفعلي لرفع الصورة على Google - بينادى من GbpPhotoUploadJob
     * (أو مباشرة كـ fallback لو الطابور مش متاح - شوف queueUpload() فوق).
     */
    public function processUpload(int $photoId): array
    {
        $rows = $this->db->query("SELECT * FROM gbp_photos WHERE id = ? LIMIT 1", [$photoId]);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'صف الصورة غير موجود'];
        }
        $photo = $rows[0];

        $connection = $this->sync->findConnection((int) $photo['website_id'], (int) $photo['user_id']);
        if (!$connection) {
            $this->markFailed($photoId, 'الاتصال بـ Google Business Profile اتفصل قبل ما الرفع يخلص');
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            $this->markFailed($photoId, 'تعذر تجديد التوكن: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $result = $api->insertMedia(null, $photo['source_url'], $photo['category']);
        if (!$result['success']) {
            $this->markFailed($photoId, $result['error'] ?? 'فشل الرفع على Google');
            return $result;
        }

        try {
            $this->db->query(
                "UPDATE gbp_photos SET google_media_name = ?, thumbnail_url = ?, status = 'ready', error_message = NULL WHERE id = ?",
                [$result['media']['name'], $result['media']['thumbnail_url'] ?? null, $photoId]
            );
        } catch (Throwable $e) {
            Logger::error('GBP photo upload: تعذر تحديث الصف بعد نجاح الرفع', ['error' => $e->getMessage()]);
        }

        event('ProfileUpdated', ['website_id' => (int) $photo['website_id'], 'user_id' => (int) $photo['user_id'], 'type' => 'photo_uploaded', 'category' => $photo['category']]);
        GbpAuditLogger::log('photo_upload', (int) $photo['website_id'], (int) $photo['user_id'], 'success', ['category' => $photo['category']]);

        return ['success' => true, 'media' => $result['media']];
    }

    private function markFailed(int $photoId, string $error): void
    {
        try {
            $this->db->query("UPDATE gbp_photos SET status = 'failed', error_message = ? WHERE id = ?", [$error, $photoId]);
        } catch (Throwable $e) {
            Logger::error('GBP photo upload: تعذر تسجيل الفشل', ['error' => $e->getMessage()]);
        }
    }

    /**
     * "الصورة الرئيسية" هنا مفهوم محلي في Tourfecto فقط لترتيب العرض -
     * مفيش endpoint رسمي من Google بيسمح بتغيير تصنيف صورة اترفعت خلاص
     * (الـ category بيتحدد وقت insertMedia بس ومينفعش يتغيّر بعدين حسب
     * توثيق Google الحالي)، فمعملناش استدعاء وهمي لجوجل هنا.
     */
    public function setPrimary(int $websiteId, int $userId, int $photoId): array
    {
        $rows = $this->db->query("SELECT id FROM gbp_photos WHERE id = ? AND website_id = ? AND user_id = ? LIMIT 1", [$photoId, $websiteId, $userId]);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'الصورة غير موجودة'];
        }
        try {
            $this->db->query("UPDATE gbp_photos SET is_primary = 0 WHERE website_id = ? AND user_id = ?", [$websiteId, $userId]);
            $this->db->query("UPDATE gbp_photos SET is_primary = 1 WHERE id = ?", [$photoId]);
            return ['success' => true];
        } catch (Throwable $e) {
            Logger::error('GBP set primary photo failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر تحديث الصورة الرئيسية'];
        }
    }

    public function listPhotos(int $websiteId, int $userId, int $page = 1, int $limit = 24): array
    {
        try {
            $offset = max(0, ($page - 1) * $limit);
            $rows = $this->db->query(
                "SELECT id, google_media_name, category, source_url, thumbnail_url, is_primary, status, error_message, created_at
                 FROM gbp_photos WHERE website_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
                [$websiteId, $userId, $limit, $offset]
            );
            $countRows = $this->db->query("SELECT COUNT(*) AS cnt FROM gbp_photos WHERE website_id = ? AND user_id = ?", [$websiteId, $userId]);
            $total = (int) ($countRows[0]['cnt'] ?? 0);

            return ['success' => true, 'photos' => $rows, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'has_more' => ($offset + count($rows)) < $total]];
        } catch (Throwable $e) {
            Logger::error('GBP list photos failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر جلب الصور'];
        }
    }

    public function deletePhoto(int $websiteId, int $userId, int $photoId): array
    {
        $rows = $this->db->query(
            "SELECT * FROM gbp_photos WHERE id = ? AND website_id = ? AND user_id = ? LIMIT 1",
            [$photoId, $websiteId, $userId]
        );
        if (empty($rows)) {
            return ['success' => false, 'error' => 'الصورة غير موجودة'];
        }

        // لسه بترفع أو فشلت الرفع أصلاً على Google - مفيش حاجة نحذفها من
        // Google (لسه معملتش)، بنحذف الصف المحلي بس.
        if (empty($rows[0]['google_media_name'])) {
            $this->db->query("DELETE FROM gbp_photos WHERE id = ?", [$photoId]);
            return ['success' => true];
        }

        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر حذف الصورة - يحتاج إعادة ربط (Reconnect): ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $result = $api->deleteMedia($rows[0]['google_media_name']);
        if (!$result['success']) {
            return $result;
        }

        $this->db->query("DELETE FROM gbp_photos WHERE id = ?", [$photoId]);
        event('ProfileUpdated', ['website_id' => $websiteId, 'user_id' => $userId, 'type' => 'photo_deleted']);
        GbpAuditLogger::log('photo_delete', $websiteId, $userId, 'success', ['photo_id' => $photoId]);

        return ['success' => true];
    }
}
