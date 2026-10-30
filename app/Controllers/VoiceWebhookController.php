<?php

/**
 * Tourfecto - AI Voice Agent: public provider webhooks. No session: security is the provider signature,
 * verified against the credentials of the tenant named in the path. New paths only (/webhooks/voice/...).
 */
class VoiceWebhookController extends Controller
{
    private function request(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        return [$url, $headers, (string) file_get_contents('php://input')];
    }

    private function send(int $code, string $type, string $body): void
    {
        http_response_code($code);
        header('Content-Type: ' . $type);
        echo $body;
        exit;
    }

    /** POST /webhooks/voice/{provider}/{tenant}/status */
    public function status(array $params = []): array
    {
        [$url, $headers, $raw] = $this->request();
        [$code, $body] = (new VoiceWebhookService())->handle(strtolower((string) ($params['provider'] ?? '')), (int) ($params['tenant'] ?? 0), $url, $headers, $raw, $_POST);
        $this->send($code, 'text/plain', $body);
        return [];
    }

    /** POST /webhooks/voice/{provider}/{tenant}/inbound - a call arrived on one of the tenant's numbers */
    public function inbound(array $params = []): array
    {
        [$url, $headers, $raw] = $this->request();
        [$code, $type, $body] = (new VoiceInboundService())->handleInbound(strtolower((string) ($params['provider'] ?? '')), (int) ($params['tenant'] ?? 0), $url, $headers, $raw, $_POST);
        $this->send($code, $type, $body);
        return [];
    }

    /** POST /webhooks/voice/{provider}/{tenant}/turn?call=ID&n=SEQ - the caller said something */
    public function turn(array $params = []): array
    {
        [$url, $headers, $raw] = $this->request();
        [$code, $type, $body] = (new VoiceInboundService())->handleTurn(strtolower((string) ($params['provider'] ?? '')), (int) ($params['tenant'] ?? 0), $url, $headers, $raw, $_POST, (int) ($_GET['call'] ?? 0), (int) ($_GET['n'] ?? 0));
        $this->send($code, $type, $body);
        return [];
    }

    /** POST /webhooks/voice/{provider}/{tenant}/outbound?job=ID - someone answered a call we placed */
    public function outbound(array $params = []): array
    {
        [$url, $headers, $raw] = $this->request();
        [$code, $type, $body] = (new VoiceOutboundService())->handleAnswer(strtolower((string) ($params['provider'] ?? '')), (int) ($params['tenant'] ?? 0), $url, $headers, $raw, $_POST, (int) ($_GET['job'] ?? 0));
        $this->send($code, $type, $body);
        return [];
    }

    /** POST /webhooks/voice/{provider}/{tenant}/whisper?call=ID - the briefing the person hears when a call is handed over */
    public function whisper(array $params = []): array
    {
        [$url, $headers, $raw] = $this->request();
        [$code, $type, $body] = (new VoiceInboundService())->handleWhisper(strtolower((string) ($params['provider'] ?? '')), (int) ($params['tenant'] ?? 0), $url, $headers, $raw, $_POST, (int) ($_GET['call'] ?? 0));
        $this->send($code, $type, $body);
        return [];
    }
}
