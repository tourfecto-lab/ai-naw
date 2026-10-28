<?php

/**
 * Tourfecto - TripAdvisor Review Sync Service
 * سحب أحدث المراجعات من كل حسابات TripAdvisor المربوطة
 * @version 1.0.0
 *
 * أبسط من Google لأن مفتاح API واحد بيغطي كل العملاء (مفيش OAuth ولا
 * تجديد توكن لكل عميل).
 */
class TripAdvisorReviewSyncService
{
    /** @var Database */
    private $db;
    private TripAdvisorAPI $api;
    private ReputationManager $reputationManager;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->api = new TripAdvisorAPI();
        $this->reputationManager = new ReputationManager();
    }

    public function syncAll(): array
    {
        $summary = ['synced' => 0, 'new_reviews' => 0, 'errors' => 0];

        if (!$this->api->isConfigured()) {
            return $summary;
        }

        try {
            $connections = (new PlatformConnection())->where([
                'platform' => 'tripadvisor',
                'status' => 'connected',
            ]);
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('TripAdvisorReviewSyncService: تعذر جلب الاتصالات', ['error' => $e->getMessage()]);
            }
            return $summary;
        }

        foreach ($connections as $connection) {
            try {
                $newCount = $this->syncConnection($connection);
                $summary['synced']++;
                $summary['new_reviews'] += $newCount;
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('TripAdvisor review sync failed', [
                        'connection_id' => $connection->getAttribute('id'),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }

    private function syncConnection(PlatformConnection $connection): int
    {
        $locationId = $connection->getAttribute('external_location_id');
        $result = $this->api->getReviews(['location_id' => $locationId]);

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
            if (!$reviewId || $this->reviewExists($websiteId, 'tripadvisor', $reviewId)) {
                continue;
            }

            $webhookResult = $this->reputationManager->processWebhook([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'platform' => 'tripadvisor',
                'platform_review_id' => $reviewId,
                'reviewer_name' => $review['reviewer']['name'] ?? 'مسافر TripAdvisor',
                'review_text' => trim(($review['title'] ?? '') . "\n" . ($review['text'] ?? '')),
                'rating' => $review['rating'] ?? 0,
                'review_date' => $review['date'] ?? date('Y-m-d H:i:s'),
                'review_language' => $review['language'] ?? ReviewReplyFallback::language((string) ($review['text'] ?? '')),
            ]);

            if (class_exists('ReviewRequestService')) {
                try {
                    $localReviewId = !empty($webhookResult['review_id']) ? (int) $webhookResult['review_id'] : null;
                    (new ReviewRequestService())->markReviewedIfMatching($websiteId, $review['reviewer']['name'] ?? '', $localReviewId);
                } catch (Exception $e) {
                    // فشل صامت - تحسين ثانوي مش لازم يوقف المزامنة الأساسية
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
                $newCount === 1 ? 'مراجعة جديدة على TripAdvisor' : "{$newCount} مراجعات جديدة على TripAdvisor",
                'وصلتلك مراجعات جديدة من عملاء - راجعها ورد عليها.',
                '/reputation/reviews'
            );
        }

        return $newCount;
    }

    private function reviewExists(int $websiteId, string $platform, string $platformReviewId): bool
    {
        try {
            $sql = "SELECT id FROM reviews WHERE website_id = ? AND source_platform = ? AND external_review_id = ? LIMIT 1";
            $result = $this->db->query($sql, [$websiteId, $platform, $platformReviewId]);
            return !empty($result);
        } catch (Exception $e) {
            return true; // في حالة الشك، اعتبرها موجودة عشان منكررش المراجعة
        }
    }
}
