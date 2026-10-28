<?php

/**
 * Tourfecto - GBP Sync Engine
 * مزامنة يدوية/خلفية لبيانات Google Business Profile (بروفايل + توكن).
 * مزامنة المراجعات الفعلية موجودة أصلاً في GoogleReviewSyncService
 * (Reputation Module) وبنستدعيها من هنا، مش بنعيد بناءها.
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpSyncService
{
    /** @var Database */
    private $db;
    /** @var GoogleReviewSyncService - بنعيد استخدام getValidAccessToken() الموجودة فعلاً هناك بدل تكرارها */
    private $reviewSync;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->reviewSync = new GoogleReviewSyncService();
    }

    /**
     * مزامنة فورية (Manual Sync) لموقع معيّن: تجدّد التوكن لو قرب ينتهي،
     * تجيب أحدث بيانات البروفايل من Google، وتحدّث last_synced_at.
     * الاستدعاء الثقيل (المراجعات) بيتحط في الطابور بدل ما يبطّئ الصفحة.
     */
    public function syncWebsite(int $websiteId, int $userId): array
    {
        $connection = $this->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'الموقع ده مش مربوط بـ Google Business Profile'];
        }

        $logId = $this->startLog($websiteId, $userId, (int) $connection->getAttribute('id'), 'manual_sync');
        event('GBPSyncStarted', ['website_id' => $websiteId, 'user_id' => $userId]);

        try {
            // بيستخدم getValidAccessToken() + منطق المزامنة الموجود فعلاً في
            // GoogleReviewSyncService (نفس اللي بيشتغل كل 6 ساعات عن طريق
            // الـ Cron) - بيسحب مراجعات جديدة ويحدّث last_synced_at/status.
            try {
                $reviewResult = $this->reviewSync->syncOne($connection);
                if (!empty($reviewResult['new_reviews'])) {
                    event('ReviewReceived', ['website_id' => $websiteId, 'user_id' => $userId, 'count' => $reviewResult['new_reviews']]);
                }
            } catch (Throwable $tokenError) {
                $this->finishLog($logId, 'failed', $tokenError->getMessage());
                event('GBPSyncFailed', ['website_id' => $websiteId, 'user_id' => $userId, 'error' => $tokenError->getMessage()]);
                GbpAuditLogger::log('sync', $websiteId, $userId, 'failed', ['reason' => 'token_refresh_failed']);
                return ['success' => false, 'error' => 'تعذرت المزامنة - يحتاج إعادة ربط (Reconnect): ' . $tokenError->getMessage()];
            }

            // نجيب أحدث توكن (اتجدد لو لزم الأمر جوه syncOne) عشان نجيب لقطة بروفايل حديثة كمان
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
            $api = new GoogleBusinessAPI(
                $accessToken,
                $connection->getAttribute('external_account_id'),
                $connection->getAttribute('external_location_id')
            );
            $locationResult = $api->getLocation();

            $this->finishLog($logId, 'success', null);
            event('GBPSyncCompleted', ['website_id' => $websiteId, 'user_id' => $userId, 'new_reviews' => $reviewResult['new_reviews'] ?? 0]);
            GbpAuditLogger::log('sync', $websiteId, $userId, 'success', ['new_reviews' => $reviewResult['new_reviews'] ?? 0]);

            return [
                'success' => true,
                'location' => $locationResult['success'] ? $locationResult['location'] : null,
                'new_reviews' => $reviewResult['new_reviews'] ?? 0,
                'synced_at' => $connection->getAttribute('last_synced_at'),
            ];
        } catch (Throwable $e) {
            Logger::error('GBP Sync Error', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
            $this->finishLog($logId, 'failed', $e->getMessage());
            event('GBPSyncFailed', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
            GbpAuditLogger::log('sync', $websiteId, $userId, 'failed', ['reason' => 'exception']);
            return ['success' => false, 'error' => 'تعذرت المزامنة: ' . $e->getMessage()];
        }
    }

    public function findConnection(int $websiteId, int $userId): ?PlatformConnection
    {
        $conditions = [
            'website_id' => $websiteId,
            'user_id' => $userId,
            'platform' => 'google_business',
        ];
        $model = new PlatformConnection();
        // Reconnects can leave older failed records. Prefer the latest working
        // connection, while retaining the latest failure for reconnect guidance.
        $rows = $model->where($conditions + ['status' => 'connected'], ['id' => 'DESC'], 1);
        if (!$rows) {
            $rows = $model->where($conditions, ['id' => 'DESC'], 1);
        }

        return !empty($rows) ? $rows[0] : null;
    }

    private function startLog(int $websiteId, int $userId, int $connectionId, string $type): ?int
    {
        try {
            $insertId = $this->db->query(
                "INSERT INTO gbp_sync_logs (website_id, user_id, connection_id, sync_type, status, started_at)
                 VALUES (?, ?, ?, ?, 'running', NOW())",
                [$websiteId, $userId, $connectionId, $type]
            );
            return is_int($insertId) ? $insertId : null;
        } catch (Throwable $e) {
            // لو جدول الـ logs لسه مطبقش الـ migration عليه، منمنعش المزامنة نفسها من الاستمرار
            Logger::error('GBP sync log start failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function finishLog(?int $logId, string $status, ?string $message): void
    {
        if (!$logId) {
            return;
        }
        try {
            $this->db->query(
                "UPDATE gbp_sync_logs SET status = ?, message = ?, finished_at = NOW() WHERE id = ?",
                [$status, $message, $logId]
            );
        } catch (Throwable $e) {
            Logger::error('GBP sync log finish failed', ['error' => $e->getMessage()]);
        }
    }
}
