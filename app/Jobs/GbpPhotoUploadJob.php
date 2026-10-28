<?php

/**
 * Tourfecto - GBP Photo Upload Job
 * تنفيذ رفع صورة GBP فعليًا على Google في الخلفية - بيخلي المستخدم مش
 * مضطر يستنى رد Media API وهو واقف على الصفحة (بند "Performance" في
 * السبيك: "استخدم ... Background Jobs ... خصوصًا Photos").
 * @version 1.0.0
 * @since 2026-08-11 (GBP Module Upgrade - Round 6: Async Photo Upload)
 */
class GbpPhotoUploadJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $photoId = (int) ($payload['photo_id'] ?? 0);
        if (!$photoId) {
            throw new Exception('GbpPhotoUploadJob: photo_id مطلوب');
        }

        $service = new GbpPhotoService();
        $result = $service->processUpload($photoId);

        if (!$result['success']) {
            // منرميش Exception هنا عشان الـ Job منظامش يعيد المحاولة على
            // فشل محتاج تدخل بشري (زي Reconnect) - الخطأ اتسجل بالفعل في
            // عمود error_message جوه GbpPhotoService::markFailed().
            Logger::info('GBP Photo Upload Job finished with failure (no retry needed)', [
                'photo_id' => $photoId,
                'error' => $result['error'] ?? null,
            ]);
        }
    }
}
