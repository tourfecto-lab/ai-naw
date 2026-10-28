<?php

/**
 * Tourfecto - Revenue Alert Channels (Slack / Signed Webhook / Email)
 * @version 2.0.0
 *
 * التنبيهات كانت داخلية بس (جرس اللوحة). دلوقتي المخاطر/الشذوذ عالية
 * الخطورة بتوصل كمان على:
 *   - Slack Incoming Webhook
 *   - Webhook عام JSON موقّع HMAC-SHA256 (للربط مع Zapier/Make/n8n/أنظمة داخلية
 *     ومنها لواتساب أو أي قناة تانية)
 *   - البريد الإلكتروني
 *
 * الأمان: الروابط مشفّرة في revai_settings، ومفحوصة بـ SsrfGuard وقت الحفظ
 * ووقت الإرسال، ومفيش تتبع Redirects. منع التكرار عبر revai_alert_deliveries
 * (نفس التنبيه مايتبعتش مرتين على نفس القناة).
 */
class RevenueAlertChannelService
{
    /** هل التنبيه ده يستحق الإرسال حسب حد الخطورة المختار؟ Pure. */
    public static function passesSeverity(?string $severity, string $minSeverity): bool
    {
        if ($severity === 'high') {
            return true;
        }
        return $minSeverity === 'medium' && $severity === 'medium';
    }

    /** حمولة Slack. Pure. */
    public static function slackPayload(array $alert, string $appUrl = ''): array
    {
        $icon = ($alert['severity'] ?? '') === 'high' ? ':rotating_light:' : ':warning:';
        $text = "{$icon} *" . self::clean($alert['title'] ?? '') . "*\n" . self::clean($alert['finding'] ?? '');
        if (!empty($alert['recommended_action'])) {
            $text .= "\n:point_right: " . self::clean($alert['recommended_action']);
        }
        if ($appUrl !== '') {
            $text .= "\n<" . rtrim($appUrl, '/') . "/revenue/intelligence|Open Revenue Intelligence>";
        }
        return ['text' => $text];
    }

    /** حمولة Webhook عام. Pure. */
    public static function webhookPayload(int $userId, array $alert, string $sentAt): array
    {
        return [
            'event' => 'revenue_intelligence.alert',
            'account_id' => $userId,
            'type' => $alert['type'] ?? null,
            'category' => $alert['category'] ?? null,
            'severity' => $alert['severity'] ?? null,
            'title' => $alert['title'] ?? '',
            'finding' => $alert['finding'] ?? '',
            'recommended_action' => $alert['recommended_action'] ?? null,
            'estimated_impact' => $alert['estimated_impact'] ?? null,
            'sent_at' => $sentAt,
        ];
    }

    /**
     * توقيع بنفس صيغة Stripe عشان المستقبِل يتحقق بسهولة:
     * X-Tourfecto-Signature: t=<ts>,v1=HMAC_SHA256("<ts>.<body>", secret). Pure.
     */
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** مفتاح منع التكرار. Pure. */
    public static function dedupKey(array $alert): string
    {
        $basis = ($alert['type'] ?? '') . '|' . ($alert['category'] ?? '') . '|' . ($alert['title'] ?? '') . '|' . date('Y-m-d');
        return substr(hash('sha256', $basis), 0, 64);
    }

    private static function clean(string $s): string
    {
        // منع حقن روابط/منشن Slack من نصوص البيانات
        return str_replace(['<', '>', '@channel', '@here', '@everyone'], ['‹', '›', 'channel', 'here', 'everyone'], $s);
    }

    /**
     * يوزّع تنبيهات على القنوات المفعّلة (يضيفها للطابور). يرجّع عدد ما اتجدول.
     * @param array<int, array> $alerts Insights بالشكل القياسي
     */
    public static function dispatch(int $userId, array $alerts): int
    {
        if (!class_exists('RevenueSettingsService')) {
            return 0;
        }
        $channels = RevenueSettingsService::alertChannels($userId);
        $active = [];
        if ($channels['slack_url'] !== '') {
            $active[] = 'slack';
        }
        if ($channels['webhook_url'] !== '') {
            $active[] = 'webhook';
        }
        if ($channels['email']) {
            $active[] = 'email';
        }
        if (empty($active)) {
            return 0;
        }
        $queued = 0;
        foreach ($alerts as $alert) {
            if (!self::passesSeverity($alert['severity'] ?? null, $channels['min_severity'])) {
                continue;
            }
            $key = self::dedupKey($alert);
            foreach ($active as $channel) {
                if (!self::claim($userId, $channel, $key)) {
                    continue;
                }
                $payload = ['user_id' => $userId, 'channel' => $channel, 'dedup_key' => $key, 'alert' => $alert];
                if (function_exists('enqueue')) {
                    enqueue(SendRevenueAlertJob::class, $payload);
                } else {
                    (new SendRevenueAlertJob())->handle($payload);
                }
                $queued++;
            }
        }
        return $queued;
    }

    /** يحجز (user, channel, key) مرة واحدة فقط - INSERT IGNORE. */
    private static function claim(int $userId, string $channel, string $key): bool
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $q = $pdo->prepare("INSERT IGNORE INTO revai_alert_deliveries (user_id, channel, dedup_key, status) VALUES (?, ?, ?, 'queued')");
            $q->execute([$userId, $channel, $key]);
            return $q->rowCount() === 1;
        } catch (Throwable $e) {
            return false; // جدول غير مثبت -> لا نرسل (أفضل من الإزعاج المتكرر)
        }
    }

    public static function markDelivery(int $userId, string $channel, string $key, string $status, ?string $error = null): void
    {
        try {
            Database::getInstance()->query(
                'UPDATE revai_alert_deliveries SET status = ?, error = ? WHERE user_id = ? AND channel = ? AND dedup_key = ?',
                [$status, $error !== null ? mb_substr($error, 0, 500) : null, $userId, $channel, $key]
            );
        } catch (Throwable $e) {
        }
    }

    /** إرسال تجريبي فوري (زر "اختبر التنبيه" في الإعدادات) بدون منع تكرار. */
    public static function sendTest(int $userId): array
    {
        $alert = [
            'type' => 'risk', 'category' => 'test_alert', 'severity' => 'high',
            'title' => 'Tourfecto test alert', 'finding' => 'Your Revenue Intelligence alerts are connected.',
            'recommended_action' => 'No action needed.',
        ];
        $channels = RevenueSettingsService::alertChannels($userId);
        $results = [];
        foreach (['slack' => $channels['slack_url'] !== '', 'webhook' => $channels['webhook_url'] !== '', 'email' => $channels['email']] as $ch => $on) {
            if (!$on) {
                continue;
            }
            try {
                (new SendRevenueAlertJob())->deliver($userId, $ch, $alert);
                $results[$ch] = 'sent';
            } catch (Throwable $e) {
                $results[$ch] = 'failed: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        return $results;
    }
}
