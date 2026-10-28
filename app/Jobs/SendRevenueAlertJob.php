<?php

/**
 * Tourfecto - Send Revenue Alert Job (Slack / Signed Webhook / Email)
 * @version 2.0.0
 *
 * بيوصّل تنبيه واحد لقناة واحدة. الروابط بتتفك من revai_settings وقت التنفيذ
 * (مش محفوظة في الطابور) وبيتطبق عليها SsrfGuard من جديد قبل أي طلب شبكة.
 */
class SendRevenueAlertJob implements QueueJobInterface
{
    private const TIMEOUT_SECONDS = 8;

    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $channel = (string) ($payload['channel'] ?? '');
        $key = (string) ($payload['dedup_key'] ?? '');
        $alert = (array) ($payload['alert'] ?? []);
        if ($userId <= 0 || $channel === '') {
            throw new InvalidArgumentException('SendRevenueAlertJob: invalid payload');
        }
        try {
            $this->deliver($userId, $channel, $alert);
            RevenueAlertChannelService::markDelivery($userId, $channel, $key, 'sent');
        } catch (Throwable $e) {
            RevenueAlertChannelService::markDelivery($userId, $channel, $key, 'failed', $e->getMessage());
            throw $e;
        }
    }

    public function deliver(int $userId, string $channel, array $alert): void
    {
        $channels = RevenueSettingsService::alertChannels($userId);
        $appUrl = function_exists('env') ? (string) env('APP_URL', '') : '';

        switch ($channel) {
            case 'slack':
                $this->post($channels['slack_url'], json_encode(RevenueAlertChannelService::slackPayload($alert, $appUrl), JSON_UNESCAPED_UNICODE), []);
                return;
            case 'webhook':
                $body = json_encode(RevenueAlertChannelService::webhookPayload($userId, $alert, date('c')), JSON_UNESCAPED_UNICODE);
                $headers = [];
                if ($channels['webhook_secret'] !== '') {
                    $headers[] = 'X-Tourfecto-Signature: ' . RevenueAlertChannelService::signature($body, $channels['webhook_secret'], time());
                }
                $this->post($channels['webhook_url'], $body, $headers);
                return;
            case 'email':
                $this->email($userId, $alert, $appUrl);
                return;
        }
        throw new InvalidArgumentException('SendRevenueAlertJob: unknown channel ' . $channel);
    }

    private function post(string $url, string $body, array $headers): void
    {
        if ($url === '') {
            throw new RuntimeException('Channel is not configured');
        }
        if (class_exists('SsrfGuard')) {
            $check = SsrfGuard::validateUrl($url);
            if (!$check['safe']) {
                throw new RuntimeException('Blocked by SSRF guard: ' . $check['reason']);
            }
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension missing');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'User-Agent: Tourfecto-Revenue-Alerts/2.0'], $headers),
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
        if ($error !== null || $status < 200 || $status >= 300) {
            throw new RuntimeException("Delivery failed (status={$status}" . ($error ? ", error={$error}" : '') . ')');
        }
    }

    private function email(int $userId, array $alert, string $appUrl): void
    {
        $user = (new User())->find($userId);
        if (!$user || empty($user->getAttribute('email'))) {
            throw new RuntimeException('Account has no email');
        }
        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            throw new RuntimeException('Mailer not configured');
        }
        $e = static function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $color = ($alert['severity'] ?? '') === 'high' ? '#EF4444' : '#F59E0B';
        $link = $appUrl !== '' ? '<p><a href="' . $e(rtrim($appUrl, '/') . '/revenue/intelligence') . '">افتح ذكاء الإيرادات</a></p>' : '';
        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;max-width:560px;border-inline-start:4px solid ' . $color . ';padding:12px 16px;">'
            . '<h2 style="margin:0 0 8px;">' . $e($alert['title'] ?? '') . '</h2>'
            . '<p>' . $e($alert['finding'] ?? '') . '</p>'
            . (!empty($alert['recommended_action']) ? '<p><b>الإجراء المقترح:</b> ' . $e($alert['recommended_action']) . '</p>' : '')
            . $link . '</div>';
        $result = $mailer->send((string) $user->getAttribute('email'), (string) ($user->getAttribute('company_name') ?: ''), 'تنبيه إيرادات: ' . mb_substr((string) ($alert['title'] ?? ''), 0, 120), $html);
        if (!($result['success'] ?? false)) {
            throw new RuntimeException('Mail send failed: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
