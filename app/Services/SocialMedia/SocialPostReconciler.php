<?php

/**
 * Tourfecto - مصالحة منشورات uncertain.
 *
 * uncertain = طلب النشر وصل لـ Meta ومعرفناش النتيجة. من غير مصالحة كان المنشور بيفضل معلّق للأبد
 * (retryPost بيتعامل مع failed بس) أو العميل يعيد النشر يدويًا فيتكرر.
 *
 * الخطوات: نقرا من Meta اللي اتنشر فعلًا ونطابق (نص + نافذة زمنية):
 *  - لقينا      -> published (من غير إعادة نشر)
 *  - ما لقيناش وعدّى وقت كفاية -> failed (آمن العميل يعيد النشر)
 *  - غامض (منشورين متطابقين) أو لسه بدري -> يفضل uncertain ويتحسم يدويًا
 *  - Reel قيد المعالجة (reel:ID) -> نسأل عن حالة الفيديو بالـ ID مباشرة
 * @version 1.0.0
 */
require_once __DIR__ . '/FacebookPostMatcher.php';
require_once __DIR__ . '/SocialLinkTracker.php';
require_once __DIR__ . '/PublishPolicy.php';
require_once __DIR__ . '/MetaSocialAPI.php';

class SocialPostReconciler
{
    /** أقل وقت نستنى فيه بعد محاولة النشر قبل ما نحكم إن المنشور ماتنشرش (فيسبوك ممكن يتأخر في إظهاره) */
    public const SETTLE_SECONDS = 1800;
    private const WINDOW_BEFORE = 180;
    private const WINDOW_AFTER = 5400;

    /** @var callable|null fn(string $token): MetaSocialAPI - للاختبارات */
    public static $apiFactory = null;

    private static function api(string $token): MetaSocialAPI
    {
        return self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
    }

    /**
     * @return array ['outcome'=>published|not_published|still_uncertain|skipped,'message'=>string,'external_post_id'=>?string]
     */
    public static function reconcileTarget(int $targetId, ?int $now = null): array
    {
        $now ??= time();
        $target = (new SocialPostTarget())->find($targetId);
        if (!$target) {
            return self::res('skipped', 'الهدف غير موجود');
        }
        if ($target->getAttribute('status') !== 'uncertain') {
            return self::res('skipped', 'الحالة مش uncertain');
        }
        $post = (new SocialPost())->find((int) $target->getAttribute('social_post_id'));
        $connection = (new PlatformConnection())->find((int) $target->getAttribute('platform_connection_id'));
        if (!$post || !$connection) {
            return self::res('skipped', 'المنشور أو الاتصال مش موجود');
        }
        $platform = (string) $connection->getAttribute('platform');
        if (!in_array($platform, ['facebook', 'instagram'], true)) {
            return self::res('skipped', 'المصالحة التلقائية متاحة لفيسبوك وإنستجرام بس - احسمها يدويًا.');
        }

        try {
            $token = (new Encryption())->decrypt((string) $connection->getAttribute('access_token'));
            $api = self::api($token);
            $accountId = (string) $connection->getAttribute('external_location_id');
            $ref = (string) ($target->getAttribute('provider_ref') ?: '');

            // 1) Reel بدأ رفعه: نسأل عن الفيديو بالـ ID
            if ($platform === 'facebook' && str_starts_with($ref, 'reel:')) {
                $videoId = substr($ref, 5);
                $st = $api->getFacebookVideoStatus($videoId, $token);
                if ($st['success'] && $st['status'] === 'ready') {
                    return self::markPublished($target, $post, $platform, $videoId, null, 'reel ready');
                }
                if ($st['success'] && $st['status'] === 'error') {
                    return self::markNotPublished($target, $post, $platform, 'فشلت معالجة الـ Reel على فيسبوك: ' . ($st['error'] ?? ''));
                }
                return self::res('still_uncertain', 'الـ Reel لسه بيتعالج عند فيسبوك.');
            }

            // الستوري ما بتظهرش في منشورات الصفحة: مفيش حاجة نطابق بيها - الحسم يدوي عشان ما نكرّرش ستوري
            if ((string) $post->getAttribute('post_format') === 'story') {
                return self::res('still_uncertain', 'الستوري ما بتظهرش في قائمة منشورات الصفحة - افتح الصفحة واتأكد بنفسك ثم احسمها يدويًا.');
            }

            // 2) مطابقة بآخر منشورات الصفحة/الحساب
            $attempt = self::attemptTimestamp($target, $now);
            $list = $platform === 'facebook'
                ? $api->getFacebookPublishedPosts($accountId, $token, 50)
                : $api->getInstagramRecentMedia($accountId, $token, 50);
            if (!$list['success']) {
                return self::res('still_uncertain', 'تعذر قراءة منشورات الصفحة من Meta: ' . ($list['error'] ?? ''));
            }
            $expected = SocialLinkTracker::finalMessage($post, $platform, (int) $target->getAttribute('id'));
            $m = FacebookPostMatcher::match($list['posts'], $expected, $attempt - self::WINDOW_BEFORE, $attempt + self::WINDOW_AFTER);

            if ($m['status'] === FacebookPostMatcher::STATUS_MATCH) {
                $p = $m['post'];
                return self::markPublished($target, $post, $platform, (string) $p['id'], isset($p['created_time']) ? (int) strtotime((string) $p['created_time']) : null, 'matched:' . $m['confidence']);
            }
            if ($m['status'] === FacebookPostMatcher::STATUS_AMBIGUOUS) {
                return self::res('still_uncertain', 'لقينا أكتر من منشور مطابق في نفس الوقت - احسمها يدويًا.');
            }
            if ($now - $attempt < self::SETTLE_SECONDS) {
                return self::res('still_uncertain', 'لسه بدري نحكم (بنستنى فيسبوك يظهر المنشور).');
            }
            return self::markNotPublished($target, $post, $platform, 'اتأكدنا إن المنشور ماتنشرش على ' . ($platform === 'facebook' ? 'فيسبوك' : 'إنستجرام') . ' - تقدر تعيد النشر بأمان.');
        } catch (Throwable $e) {
            Logger::warning('Social reconcile failed', ['target_id' => $targetId, 'message' => $e->getMessage()]);
            return self::res('still_uncertain', 'تعذرت المصالحة مؤقتًا.');
        }
    }

    /**
     * حسم يدوي من العميل (لما المصالحة التلقائية مش كفاية).
     * @param string $resolution published | not_published
     */
    public static function resolveManually(int $targetId, string $resolution): array
    {
        $target = (new SocialPostTarget())->find($targetId);
        if (!$target || $target->getAttribute('status') !== 'uncertain') {
            return self::res('skipped', 'الحالة مش uncertain');
        }
        $post = (new SocialPost())->find((int) $target->getAttribute('social_post_id'));
        $conn = (new PlatformConnection())->find((int) $target->getAttribute('platform_connection_id'));
        $platform = $conn ? (string) $conn->getAttribute('platform') : '';
        if ($resolution === 'published') {
            return self::markPublished($target, $post, $platform, null, null, 'manual');
        }
        if ($resolution === 'not_published') {
            return self::markNotPublished($target, $post, $platform, 'اتحسمت يدويًا: المنشور ماتنشرش.', 'reconciled_not_published');
        }
        return self::res('skipped', 'قرار غير صالح');
    }

    private static function attemptTimestamp(SocialPostTarget $t, int $now): int
    {
        foreach (['publish_attempt_at', 'scheduled_at'] as $f) {
            $v = $t->getAttribute($f);
            if ($v) {
                $ts = strtotime($v . ' UTC');
                if ($ts !== false) {
                    return $ts;
                }
            }
        }
        return $now - 86400; // مفيش وقت محاولة مسجّل (قبل الـ migration): نوسّع النافذة لآخر 24 ساعة
    }

    private static function markPublished(SocialPostTarget $t, ?SocialPost $post, string $platform, ?string $externalId, ?int $publishedTs, string $note): array
    {
        $t->setAttribute('status', 'published');
        if ($externalId !== null) {
            $t->setAttribute('external_post_id', $externalId);
        }
        $t->setAttribute('published_at', gmdate('Y-m-d H:i:s', $publishedTs ?? time()));
        $t->setAttribute('last_error', null);
        $t->setAttribute('provider_ref', null);
        self::setIfV2($t, ['reconciled_at' => gmdate('Y-m-d H:i:s'), 'error_code' => null, 'error_category' => null]);
        $t->save();
        self::log($t, $post, $platform, 'reconciled_published', $note);
        self::refresh($t);
        return self::res('published', 'اتأكدنا إن المنشور اتنشر فعلًا.', $externalId);
    }

    private static function markNotPublished(SocialPostTarget $t, ?SocialPost $post, string $platform, string $msg, string $outcome = 'reconciled_not_published'): array
    {
        $t->setAttribute('status', 'failed');
        $t->setAttribute('last_error', $msg);
        $t->setAttribute('provider_ref', null);
        self::setIfV2($t, ['reconciled_at' => gmdate('Y-m-d H:i:s')]);
        $t->save();
        self::log($t, $post, $platform, $outcome, null);
        self::refresh($t);
        return self::res('not_published', $msg);
    }

    private static function setIfV2(SocialPostTarget $t, array $attrs): void
    {
        try {
            if (!Database::getInstance()->query("SHOW COLUMNS FROM social_post_targets LIKE 'reconciled_at'")) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }
        foreach ($attrs as $k => $v) {
            $t->setAttribute($k, $v);
        }
    }

    private static function refresh(SocialPostTarget $t): void
    {
        if (class_exists('SocialPostService')) {
            SocialPostService::refreshPostStatus((int) $t->getAttribute('social_post_id'));
        }
    }

    private static function log(SocialPostTarget $t, ?SocialPost $post, string $platform, string $outcome, ?string $note): void
    {
        try {
            Database::getInstance()->query(
                'INSERT INTO social_publish_log (target_id, user_id, platform_connection_id, platform, outcome, error_code, error_category, fbtrace_id, duration_ms, note) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [(int) $t->getAttribute('id'), $post ? (int) $post->getAttribute('user_id') : null, (int) $t->getAttribute('platform_connection_id'), $platform, $outcome, null, null, null, null, $note]
            );
        } catch (Throwable $e) {
        }
    }

    private static function res(string $outcome, string $message, ?string $externalId = null): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'external_post_id' => $externalId];
    }
}
