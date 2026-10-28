<?php

/**
 * Tourfecto - Usage Alert Service
 * تنبيهات تجاوز نسب الاستخدام (50% / 75% / 90% / 100%) لكل ميزة في
 * الباقة الحالية. بيستخدم نفس قنوات الإشعارات الموجودة فعليًا في
 * المشروع (Notification + ActivityLog) - مفيش أي مزوّد إشعارات جديد.
 *
 * الفكرة: بدل ما نحاول نلقط كل نقطة في الكود بتزوّد عداد استهلاك
 * (منتشرة في كذا Controller/Service)، بنفحص النسبة الحالية في اللحظة
 * اللي بيتحسب فيها استخدام الباقة أصلاً (getUsageStats في
 * SubscriptionController) - نقطة مركزية واحدة موجودة بالفعل، فبنضمن
 * إننا مش هنفوّت أي زيادة استهلاك حتى لو حصلت في مكان تاني من الكود.
 *
 * @version 1.0.0
 * @date 2026-08-09
 */
class UsageAlertService
{
    /** @var Database */
    private $db;

    /** النسب اللي بنبعت عندها إشعار - مرتبة تصاعديًا */
    private const THRESHOLDS = [50, 75, 90, 100];

    private const METRIC_LABELS = [
        'ai' => 'رصيد الذكاء الاصطناعي',
        'chat' => 'رصيد الشات',
        'review' => 'رصيد المراجعات',
        'competitor' => 'حد تحليل المنافسين',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * بتفحص كل مقاييس الاستخدام الممرّرة، وتبعت إشعار واحد بس لكل حد
     * (50/75/90/100%) جديد اتعدّى لأول مرة في الفترة الحالية.
     *
     * @param int $userId
     * @param array $usage نفس شكل getUsageStats(): ['ai' => ['total'=>.., 'used'=>..], ...]
     * @param string $periodKey مفتاح فريد للفترة الحالية (مثلاً "{subscription_id}:{expiry_date}")
     *        بيتغيّر تلقائيًا كل ما الاشتراك يتجدد أو الباقة تتغيّر، فالتنبيهات
     *        بترجع تتصفّر لوحدها كل فترة فوترة جديدة.
     */
    public function checkAndNotify(int $userId, array $usage, string $periodKey): void
    {
        if ($userId <= 0 || $periodKey === '') {
            return;
        }

        foreach ($usage as $metricKey => $data) {
            $total = (int) ($data['total'] ?? 0);
            $used = (int) ($data['used'] ?? 0);
            if ($total <= 0) {
                continue; // مفيش حد محدد أصلاً (Not configured) - مفيش نسبة تتحسب
            }

            $percent = min(100, (int) floor(($used / $total) * 100));
            $crossedThreshold = 0;
            foreach (self::THRESHOLDS as $t) {
                if ($percent >= $t) {
                    $crossedThreshold = $t;
                }
            }
            if ($crossedThreshold === 0) {
                continue;
            }

            try {
                $this->notifyIfNewThreshold($userId, (string) $metricKey, $periodKey, $crossedThreshold, $total, $used);
            } catch (Exception $e) {
                // فشل التنبيه لميزة واحدة ميوقفش عرض باقي الصفحة أبدًا.
                if (class_exists('Logger')) {
                    Logger::error('UsageAlertService::checkAndNotify failed', [
                        'user_id' => $userId, 'metric' => $metricKey, 'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function notifyIfNewThreshold(int $userId, string $metricKey, string $periodKey, int $crossedThreshold, int $total, int $used): void
    {
        $existing = $this->db->query(
            "SELECT * FROM usage_alert_state WHERE user_id = ? AND metric_key = ? AND period_key = ? LIMIT 1",
            [$userId, $metricKey, $periodKey]
        );
        $alreadyNotified = $existing[0]['highest_threshold_notified'] ?? 0;

        if ($crossedThreshold <= (int) $alreadyNotified) {
            return; // الإشعار ده اتبعت قبل كده للفترة دي
        }

        // upsert: نضمن صف واحد بس لكل (user, metric, period) - بنستخدم
        // نفس UNIQUE KEY المُعرّف في الـ migration.
        $this->db->exec(
            "INSERT INTO usage_alert_state (user_id, metric_key, period_key, highest_threshold_notified)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE highest_threshold_notified = VALUES(highest_threshold_notified)",
            [$userId, $metricKey, $periodKey, $crossedThreshold]
        );

        $label = self::METRIC_LABELS[$metricKey] ?? $metricKey;
        $title = $crossedThreshold >= 100 ? 'استهلكت رصيدك بالكامل' : 'اقتربت من حد الاستخدام';
        $body = $crossedThreshold >= 100
            ? "استهلكت 100% من \"{$label}\" ({$used}/{$total}) لباقتك الحالية."
            : "استهلكت {$crossedThreshold}% من \"{$label}\" ({$used}/{$total}) لباقتك الحالية.";

        // نحترم تفضيل المستخدم (notify_billing_usage - نفس نمط
        // notify_email/notify_chat/notify_reviews الموجود بالفعل في
        // الإعدادات). لو معطّل، السجل بيتسجّل برضه في usage_alert_state
        // وactivity_logs (عشان الدعم الفني يقدر يشوف إيه اللي حصل) لكن
        // الإشعار الفعلي مبيتبعتش.
        $notifyEnabled = true;
        if (class_exists('User')) {
            $userRow = (new User())->find($userId);
            if ($userRow) {
                $notifyEnabled = (bool) $userRow->getAttribute('notify_billing_usage');
            }
        }

        if ($notifyEnabled && class_exists('Notification')) {
            Notification::notify($userId, 'usage_threshold_' . $crossedThreshold, $title, $body, '/subscription');
        }

        ActivityLog::record('subscription', 'subscription.usage_threshold_reached', [
            'user_id' => $userId,
            'meta' => ['metric' => $metricKey, 'threshold' => $crossedThreshold, 'used' => $used, 'total' => $total, 'notified' => $notifyEnabled],
        ]);
    }
}
