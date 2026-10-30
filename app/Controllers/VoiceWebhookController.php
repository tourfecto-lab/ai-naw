<?php

/**
 * Tourfecto - AI Voice Agent: public provider webhooks. No session: security is the provider signature,
 * verified against the credentials of the tenant named in the path. New paths only (/webhooks/voice/...).
 */
class VoiceWebhookController extends Controller
{
    /** POST /webhooks/voice/{provider}/{tenant}/status */
    public function status(array $params = []): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        $raw = (string) file_get_contents('php://input');
        [$code, $body] = (new VoiceWebhookService())->handle(strtolower((string) ($params['provider'] ?? '')), (int) ($params['tenant'] ?? 0), $url, $headers, $raw, $_POST);
        http_response_code($code);
        echo $body;
        exit;
    }
}
