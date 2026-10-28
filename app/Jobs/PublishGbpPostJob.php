<?php

/**
 * Tourfecto - Publish Google Business Profile Post Job
 * @version 2.0.0
 *
 * تم التفعيل الكامل: GoogleBusinessAPI::publishPost() بقت موجودة فعليًا
 * (Local Posts API الرسمي)، فالـ Job ده بقى بينفّذ النشر الحقيقي بدل ما
 * يقف عند حالة "processing" بس.
 */
class PublishGbpPostJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $id = (int) ($payload['gbp_scheduled_post_id'] ?? 0);
        $scheduled = (new GbpScheduledPost())->find($id);

        if (!$scheduled) {
            throw new Exception("GbpScheduledPost #{$id} غير موجود");
        }

        // Published posts are idempotent. Unresolved remote outcomes require reconciliation.
        $currentStatus = $scheduled->getAttribute('status');
        if ($currentStatus === 'published') {
            Logger::info('GBP Post Publish: already published, skipping (idempotency guard)', ['scheduled_post_id' => $id]);
            return;
        }
        if ($currentStatus === 'processing') {
            Logger::info('GBP Post Publish: unresolved processing, reconciliation required', ['scheduled_post_id' => $id]);
            throw new RuntimeException('GBP publish outcome unresolved: reconcile with Google before retry');
        }

        $content = (new GbpContent())->find((int) $scheduled->getAttribute('gbp_content_id'));
        $connection = (new PlatformConnection())->find((int) $scheduled->getAttribute('platform_connection_id'));

        if (!$content || !$connection) {
            $scheduled->setAttribute('status', 'failed');
            $scheduled->setAttribute('error_message', 'المحتوى أو الاتصال غير موجود');
            $scheduled->save();
            throw new RuntimeException('GBP content or connection missing');
        }

        Database::getInstance()->transaction(function () use ($id, $scheduled) {
            $rows = Database::getInstance()->query('SELECT status FROM gbp_scheduled_posts WHERE id = ? FOR UPDATE', [$id]);
            if (!$rows || in_array($rows[0]['status'], ['processing', 'published'], true)) {
                throw new RuntimeException('GBP publishing claimed by another worker');
            }
            $scheduled->setAttribute('status', 'processing');
            if (!$scheduled->save()) { throw new RuntimeException('GBP claim persistence failed'); }
        });
        $requestStarted = false;
        $publishedPersisted = false;

        try {
            $syncService = new GoogleReviewSyncService();
            $accessToken = $syncService->getValidAccessToken($connection);

            $api = new GoogleBusinessAPI(
                $accessToken,
                $connection->getAttribute('external_account_id'),
                $connection->getAttribute('external_location_id')
            );

            $website = (new Website())->find((int) $content->getAttribute('website_id'));
            $language = $website ? (string) $website->getAttribute('target_language') : 'ar';
            $ctaUrl = $website ? (string) $website->getAttribute('main_url') : null;

            $requestStarted = true;
            $result = $api->publishPost(
                (string) $content->getAttribute('generated_text'),
                $language ?: 'ar',
                $ctaUrl ?: null,
                [
                    'type' => (string) $content->getAttribute('type'),
                    'title' => $content->getAttribute('post_title'),
                    'start' => $content->getAttribute('event_start'),
                    'end' => $content->getAttribute('event_end'),
                    'coupon_code' => $content->getAttribute('coupon_code'),
                    'terms' => $content->getAttribute('offer_terms'),
                    'cta_type' => $content->getAttribute('cta_type'),
                    'cta_url' => $content->getAttribute('cta_url'),
                ]
            );

            if (!$result['success']) {
                Logger::error('GBP Post Publish Failed', [
                    'scheduled_post_id' => $id,
                    'error' => $result['error'] ?? null,
                ]);
                if (class_exists('GbpAuditLogger')) {
                    GbpAuditLogger::log('post_publish', (int) $content->getAttribute('website_id'), (int) $content->getAttribute('user_id'), 'failed', ['scheduled_post_id' => $id, 'error_code' => $result['error_code'] ?? null]);
                }
                throw new RuntimeException($result['error'] ?? 'Google publishing rejected');
            }

            $scheduled->setAttribute('status', 'published');
            $scheduled->setAttribute('google_post_id', $result['post_id'] ?? null);
            $scheduled->setAttribute('published_at', date('Y-m-d H:i:s'));
            $scheduled->setAttribute('error_message', null);
            if (!$scheduled->save()) { throw new RuntimeException('Published post persistence failed'); }
            $publishedPersisted = true;

            // (قبل كده status = 'published' مكانتش في الـ ENUM فالحفظ بيضرب Exception بعد ما المنشور
            //  اتنشر فعلاً، والجدولة تتقلب "فشل". التحديث بيوسّع الـ ENUM، وده احتياطي لو لسه ماتطبقش)
            try {
                $content->setAttribute('status', 'published');
                $content->save();
            } catch (Throwable $statusError) {
                Logger::warning('gbp_content published status not saved', ['error' => $statusError->getMessage()]);
            }

            if (class_exists('Notification')) {
                Notification::notify(
                    (int) $content->getAttribute('user_id'),
                    'gbp_post_published',
                    'تم نشر منشور Google Business',
                    'منشورك اتنشر بنجاح على Google Business Profile.',
                    '/gbp-content'
                );
            }

            Logger::info('GBP Post Published', ['scheduled_post_id' => $id, 'google_post_id' => $result['post_id'] ?? null]);

            // GBP Module Upgrade (2026-08-09): PostPublished event مطلوب صراحة بالسبيك ومكانش بيتبعت
            if (function_exists('event')) {
                event('PostPublished', [
                    'website_id' => (int) $content->getAttribute('website_id'),
                    'user_id' => (int) $content->getAttribute('user_id'),
                    'scheduled_post_id' => $id,
                    'google_post_id' => $result['post_id'] ?? null,
                ]);
            }
            if (class_exists('GbpAuditLogger')) {
                GbpAuditLogger::log('post_publish', (int) $content->getAttribute('website_id'), (int) $content->getAttribute('user_id'), 'success', ['scheduled_post_id' => $id, 'google_post_id' => $result['post_id'] ?? null]);
            }

        } catch (Throwable $e) {
            if ($publishedPersisted) {
                // A notification/audit failure must never resubmit a published post.
                Logger::error('GBP post published; follow-up failed', ['scheduled_post_id' => $id]);
                throw $e;
            }
            $scheduled->setAttribute('status', $requestStarted ? 'processing' : 'failed');
            $scheduled->setAttribute('error_message', ($requestStarted ? 'MANUAL_RECONCILIATION_REQUIRED: ' : '') . $e->getMessage());
            $scheduled->setAttribute('attempts', (int) $scheduled->getAttribute('attempts') + 1);
            $scheduled->save();

            Logger::error('GBP Post Publish Exception', [
                'scheduled_post_id' => $id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
