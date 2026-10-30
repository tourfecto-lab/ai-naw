<?php

/**
 * Tourfecto - Notification Model
 * @version 1.0.0
 */
class Notification extends Model
{
    protected $table = 'notifications';
    protected $fillable = ['user_id', 'type', 'title', 'body', 'link', 'read_at'];

    /**
     * تصنيف كل "type" حقيقي موجود بالفعل في نداءات Notification::notify()
     * في الكود لفئة إعدادات واحدة يقدر المستخدم يتحكم فيها من
     * Settings > Notifications (Settings Center - Phase 4).
     *
     * الفئات دي هي الفئات اللي عندها إشعار حقيقي بيتبعت فعليًا في الكود
     * دلوقتي - مفيش toggle وهمي لفئات مالهاش إشعار فعلي.
     */
    private const TYPE_CATEGORY_MAP = [
        'review_received' => 'reviews',
        'article_published' => 'content_publishing',
        'post_failed' => 'content_publishing',
        'gbp_post_published' => 'content_publishing',
        'social_post_published' => 'content_publishing',
        'media_generated' => 'content_publishing',
        'chat_pending_approval' => 'leads',
        'site_lead_new' => 'leads',
        'ai_chat_new_message' => 'leads',
        'ai_chat_channel_error' => 'system',
        'admin_broadcast' => 'system',
        'wallet_deposit_approved' => 'system',
        'subscription_activated' => 'system',
        'commission_paid_on_cancelled_booking' => 'system',
    ];

    /** الفئات كلها متاحة افتراضيًا (true) لو المستخدم لسه معندوش تفضيل محفوظ */
    public static function defaultPreferences(): array
    {
        return [
            'reviews' => true,
            'content_publishing' => true,
            'leads' => true,
            'system' => true,
            // Phase 16C: ملخصات البريد الدورية (Daily/Weekly Digest).
            // مفعّلة افتراضيًا عشان مفيش حد يفقد ملخصه من غير ما يطلب،
            // وتقدر تتقفل من Settings > Notifications زي GitHub.
            'digest_daily' => true,
            'digest_weekly' => true,
        ];
    }

    /**
     * هل ملخص بريد دوري معيّن مفعّل للمستخدم؟ أي digest غير معروف
     * بيتعامل معاه كمفعّل (feature جديدة متتقفلش بالغلط).
     */
    public static function digestEnabledFor(User $user, string $digestType): bool
    {
        $prefs = self::preferencesFor($user);
        return (bool) ($prefs[$digestType] ?? true);
    }

    /** يرجّع تفضيلات مستخدم معيّن، بعد الدمج مع الافتراضي لأي فئة ناقصة */
    public static function preferencesFor(User $user): array
    {
        $raw = $user->getAttribute('notification_preferences');
        $saved = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return array_merge(self::defaultPreferences(), is_array($saved) ? $saved : []);
    }

    /**
     * هل الفئة اللي بيتبعها الـ type ده مفعّلة لهذا المستخدم؟
     * أي type مش موجود في التصنيف أعلاه بيتعامل معاه كـ"مفعّل افتراضيًا"
     * عشان feature جديدة متتقفلش بالغلط.
     */
    public static function isEnabledFor(User $user, string $type): bool
    {
        $category = self::TYPE_CATEGORY_MAP[$type] ?? null;
        if ($category === null) {
            return true;
        }
        $prefs = self::preferencesFor($user);
        return (bool) ($prefs[$category] ?? true);
    }

    public static function notify(int $userId, string $type, string $title, string $body = '', string $link = ''): void
    {
        try {
            // نقطة تحكم مركزية واحدة: بنتحقق من تفضيل المستخدم هنا في
            // المكان الوحيد اللي كل الإشعارات بتعدي منه فعليًا.
            $userModel = new User();
            $user = $userModel->find($userId);
            if ($user && !self::isEnabledFor($user, $type)) {
                return;
            }

            $n = new self([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body ?: null,
                'link' => $link ?: null,
            ]);
            $n->save();
            self::push($userId, $title, $body, $link);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Notification::notify failed: ' . $e->getMessage());
            }
        }
    }

    /** نفس الإشعار على موبايل/متصفح العميل (OneSignal) لو مفعّل - فشله مايأثرش على إشعار الجرس */
    private static function push(int $userId, string $title, string $body, string $link): void
    {
        static $service = null;
        if ($service === null) {
            $service = false;
            if (class_exists('OneSignalService')) {
                $s = new OneSignalService();
                $enabled = class_exists('SystemSettingsService')
                    ? filter_var((new SystemSettingsService())->get('integration_onesignal_enabled', defined('ONESIGNAL_ENABLED') && ONESIGNAL_ENABLED ? 'true' : ''), FILTER_VALIDATE_BOOLEAN)
                    : (defined('ONESIGNAL_ENABLED') && ONESIGNAL_ENABLED);
                if ($enabled && $s->isConfigured()) {
                    $service = $s;
                }
            }
        }
        if (!$service) {
            return;
        }
        try {
            $r = $service->pushToUser($userId, $title, $body, $link);
            if (empty($r['success']) && class_exists('Logger')) {
                Logger::warning('OneSignal push failed', ['user_id' => $userId, 'error' => $r['error'] ?? null, 'http' => $r['http_code'] ?? null]);
            }
        } catch (\Throwable $e) {
        }
    }
}
