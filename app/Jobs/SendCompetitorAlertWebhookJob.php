<?php

/**
 * Tourfecto - Competitor Intelligence: Send Alert Webhook Job
 * @version 1.0.0
 *
 * يبعت تنبيه Competitor Intelligence لـ Webhook عام (JSON) أو Slack
 * Incoming Webhook - الرابط مُدخَل من المستخدم نفسه (Settings tab)،
 * فبيتطبق عليه SsrfGuard زي أي URL خارجي تاني في الموديول ده (نفس
 * الحماية المُستخدمة في WebsiteSnapshotFetcher لمنع SSRF).
 */
class SendCompetitorAlertWebhookJob implements QueueJobInterface
{
    private const TIMEOUT_SECONDS = 8;

    public function handle(array $payload): void
    {
        $url = (string) ($payload['url'] ?? '');
        $format = (string) ($payload['format'] ?? 'generic'); // 'generic' | 'slack'
        $title = (string) ($payload['title'] ?? '');
        $message = (string) ($payload['message'] ?? '');
        $severity = (string) ($payload['severity'] ?? '');
        $competitorName = (string) ($payload['competitor_name'] ?? '');

        if ($url === '') {
            throw new InvalidArgumentException('SendCompetitorAlertWebhookJob: missing url');
        }

        $check = SsrfGuard::validateUrl($url);
        if (!$check['safe']) {
            // رابط غير آمن (خاص/داخلي) - ما نحاولش نطلبه أبدًا حتى لو
            // المستخدم أدخله بنفسه، ونفشل الـ Job برسالة واضحة بدل ما
            // نسيب السيرفر يطلب من شبكته الداخلية.
            throw new RuntimeException('SendCompetitorAlertWebhookJob: blocked by SSRF guard - ' . $check['reason']);
        }

        $body = $format === 'slack'
            ? ['text' => "*{$title}*\n{$message}\n_Severity: {$severity} · Competitor: {$competitorName}_"]
            : ['event' => 'competitor_intelligence.alert', 'title' => $title, 'message' => $message, 'severity' => $severity, 'competitor' => $competitorName, 'sent_at' => date('c')];

        if (!function_exists('curl_init')) {
            throw new RuntimeException('SendCompetitorAlertWebhookJob: curl extension missing');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false, // مفيش داعي نتبع redirects لـ webhook إشعار بسيط
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // الاتصال الفعلي لازم يطابق فحص SSRF (نفس مبدئ WebsiteSnapshotFetcher)
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        if ($error !== null || $status >= 400) {
            throw new RuntimeException("SendCompetitorAlertWebhookJob: delivery failed (status={$status}, error={$error})");
        }
    }
}
