<?php

/**
 * Tourfecto - Revenue Intelligence Settings
 * @version 2.0.0
 *
 * إعدادات الموديول لكل حساب (revai_settings): عملة التقارير، قنوات
 * التنبيه الخارجية (Slack/Webhook/Email)، ونِسب عمولة القنوات (OTA).
 *
 * الأسرار (روابط Slack/Webhook وسر التوقيع) تُخزّن مشفّرة دائمًا عبر
 * Encryption ولا ترجع أبدًا في أي استجابة API - نرجّع فقط "configured".
 */
class RevenueSettingsService
{
    private const SALT = 'revai_settings_';

    /** @var array<int, array> */
    private static $memo = [];

    /** تطبيع نِسب العمولة: مفاتيح قنوات نظيفة + نسب 0..100 فقط. Pure. */
    public static function sanitizeCommissions($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $channel => $pct) {
            $channel = strtolower(trim((string) $channel));
            if ($channel === '' || !preg_match('/^[a-z0-9_:\-\.]{1,60}$|^ota:\*$/', $channel) || !is_numeric($pct)) {
                continue;
            }
            $pct = (float) $pct;
            if ($pct < 0 || $pct > 100) {
                continue;
            }
            $out[$channel] = round($pct, 2);
        }
        ksort($out);
        return $out;
    }

    /** العملة الأساسية: إعداد الموديول -> عملة النشاط -> USD. */
    public static function baseCurrency(int $userId): string
    {
        $s = self::raw($userId);
        if (!empty($s['base_currency']) && preg_match('/^[A-Z]{3}$/', (string) $s['base_currency'])) {
            return (string) $s['base_currency'];
        }
        try {
            $cur = Database::getInstance()->query(
                'SELECT default_currency FROM businesses WHERE owner_user_id = ? AND default_currency IS NOT NULL ORDER BY id LIMIT 1',
                [$userId]
            );
            $c = strtoupper((string) ($cur[0]['default_currency'] ?? ''));
            if (preg_match('/^[A-Z]{3}$/', $c)) {
                return $c;
            }
        } catch (Throwable $e) {
        }
        return 'USD';
    }

    public static function raw(int $userId): array
    {
        if (isset(self::$memo[$userId])) {
            return self::$memo[$userId];
        }
        try {
            $rows = Database::getInstance()->query('SELECT * FROM revai_settings WHERE user_id = ? LIMIT 1', [$userId]);
            return self::$memo[$userId] = $rows[0] ?? [];
        } catch (Throwable $e) {
            return self::$memo[$userId] = [];
        }
    }

    public static function commissions(int $userId): array
    {
        return self::sanitizeCommissions(self::raw($userId)['channel_commissions'] ?? null);
    }

    /** الإعدادات للعرض في الواجهة - بدون أي سر. */
    public static function publicView(int $userId): array
    {
        $s = self::raw($userId);
        return [
            'base_currency' => self::baseCurrency($userId),
            'base_currency_explicit' => !empty($s['base_currency']),
            'alert_min_severity' => $s['alert_min_severity'] ?? 'high',
            'alert_email' => !empty($s['alert_email']),
            'slack_configured' => !empty($s['slack_webhook_enc']),
            'webhook_configured' => !empty($s['alert_webhook_url_enc']),
            'channel_commissions' => self::commissions($userId),
            'fx_rates' => FxRateService::latestRates($userId),
        ];
    }

    /** يفك تشفير قنوات التنبيه (للاستخدام الداخلي فقط - لا يُعرض). */
    public static function alertChannels(int $userId): array
    {
        $s = self::raw($userId);
        $enc = class_exists('Encryption') ? new Encryption() : null;
        $dec = static function ($v) use ($enc, $userId) {
            if (!$enc || empty($v)) {
                return '';
            }
            try {
                return (string) $enc->decrypt((string) $v, self::SALT . $userId);
            } catch (Throwable $e) {
                return '';
            }
        };
        return [
            'min_severity' => $s['alert_min_severity'] ?? 'high',
            'email' => !empty($s['alert_email']),
            'slack_url' => $dec($s['slack_webhook_enc'] ?? ''),
            'webhook_url' => $dec($s['alert_webhook_url_enc'] ?? ''),
            'webhook_secret' => $dec($s['alert_webhook_secret_enc'] ?? ''),
        ];
    }

    /**
     * حفظ جزئي: أي حقل مش مبعوث يفضل زي ما هو. نص فاضي "" لرابط = مسح.
     * @return array{ok:bool,error?:string}
     */
    public static function save(int $userId, array $in): array
    {
        $current = self::raw($userId);
        $enc = new Encryption();
        $row = [
            'base_currency' => $current['base_currency'] ?? null,
            'alert_min_severity' => $current['alert_min_severity'] ?? 'high',
            'alert_email' => (int) ($current['alert_email'] ?? 0),
            'slack_webhook_enc' => $current['slack_webhook_enc'] ?? null,
            'alert_webhook_url_enc' => $current['alert_webhook_url_enc'] ?? null,
            'alert_webhook_secret_enc' => $current['alert_webhook_secret_enc'] ?? null,
            'channel_commissions' => $current['channel_commissions'] ?? null,
        ];

        if (array_key_exists('base_currency', $in)) {
            $c = strtoupper(trim((string) $in['base_currency']));
            if ($c !== '' && !preg_match('/^[A-Z]{3}$/', $c)) {
                return ['ok' => false, 'error' => 'Invalid currency code (ISO 4217, e.g. USD/EGP/EUR)'];
            }
            $row['base_currency'] = $c !== '' ? $c : null;
        }
        if (array_key_exists('alert_min_severity', $in)) {
            $row['alert_min_severity'] = $in['alert_min_severity'] === 'medium' ? 'medium' : 'high';
        }
        if (array_key_exists('alert_email', $in)) {
            $row['alert_email'] = !empty($in['alert_email']) && $in['alert_email'] !== 'false' ? 1 : 0;
        }
        foreach (['slack_url' => 'slack_webhook_enc', 'webhook_url' => 'alert_webhook_url_enc'] as $k => $col) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $url = trim((string) $in[$k]);
            if ($url === '') {
                $row[$col] = null;
                continue;
            }
            if (!preg_match('#^https://#i', $url) || (class_exists('SsrfGuard') && !SsrfGuard::isSafe($url))) {
                return ['ok' => false, 'error' => 'The alert URL must be a public https:// address'];
            }
            if ($k === 'slack_url' && !preg_match('#^https://hooks\.slack\.com/#i', $url)) {
                return ['ok' => false, 'error' => 'Slack URL must start with https://hooks.slack.com/'];
            }
            $row[$col] = $enc->encrypt($url, self::SALT . $userId);
        }
        if (array_key_exists('webhook_secret', $in)) {
            $secret = trim((string) $in['webhook_secret']);
            if ($secret !== '') {
                $row['alert_webhook_secret_enc'] = $enc->encrypt($secret, self::SALT . $userId);
            }
        }
        if (array_key_exists('channel_commissions', $in)) {
            $row['channel_commissions'] = json_encode(self::sanitizeCommissions($in['channel_commissions']), JSON_UNESCAPED_UNICODE);
        }

        Database::getInstance()->query(
            "INSERT INTO revai_settings (user_id, base_currency, alert_min_severity, alert_email, slack_webhook_enc, alert_webhook_url_enc, alert_webhook_secret_enc, channel_commissions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE base_currency = VALUES(base_currency), alert_min_severity = VALUES(alert_min_severity),
                alert_email = VALUES(alert_email), slack_webhook_enc = VALUES(slack_webhook_enc),
                alert_webhook_url_enc = VALUES(alert_webhook_url_enc), alert_webhook_secret_enc = VALUES(alert_webhook_secret_enc),
                channel_commissions = VALUES(channel_commissions)",
            [$userId, $row['base_currency'], $row['alert_min_severity'], $row['alert_email'], $row['slack_webhook_enc'],
             $row['alert_webhook_url_enc'], $row['alert_webhook_secret_enc'], $row['channel_commissions']]
        );
        unset(self::$memo[$userId]);
        return ['ok' => true];
    }

    public static function resetMemo(): void
    {
        self::$memo = [];
    }
}
