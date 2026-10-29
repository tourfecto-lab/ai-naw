<?php

/**
 * Pictures, voice notes, videos and files sent from the inbox to Messenger / Instagram. Meta's Send API only accepts a
 * public https URL for an attachment, so an uploaded file is kept for two days under an unguessable name in private
 * storage and served by GET /media/out/{name} (OutboundMediaController). Nothing else is ever served from that path.
 */
final class MetaDmMedia
{
    public const TTL = 172800;

    private const TYPES = [
        'image/jpeg' => ['image', 8, 'jpg'], 'image/png' => ['image', 8, 'png'], 'image/gif' => ['image', 8, 'gif'],
        'audio/mpeg' => ['audio', 25, 'mp3'], 'audio/mp4' => ['audio', 25, 'm4a'], 'audio/aac' => ['audio', 25, 'aac'],
        'audio/wav' => ['audio', 25, 'wav'], 'audio/x-wav' => ['audio', 25, 'wav'], 'audio/ogg' => ['audio', 25, 'ogg'],
        'video/mp4' => ['video', 25, 'mp4'], 'video/quicktime' => ['video', 25, 'mov'], 'video/webm' => ['video', 25, 'webm'],
        'application/pdf' => ['file', 25, 'pdf'], 'text/plain' => ['file', 25, 'txt'],
        'application/msword' => ['file', 25, 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['file', 25, 'docx'],
        'application/vnd.ms-excel' => ['file', 25, 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['file', 25, 'xlsx'],
    ];

    /** @return array{0:string,1:int,2:string}|null [Meta attachment type, max MB, extension] (Instagram DMs take no files) */
    public static function classify(string $mime, string $channel): ?array
    {
        $t = self::TYPES[$mime] ?? null;
        if ($t === null || ($channel === 'instagram' && $t[0] === 'file')) {
            return null;
        }
        return $t;
    }

    public static function mimeForExt(string $ext): ?string
    {
        foreach (self::TYPES as $mime => $t) {
            if ($t[2] === $ext && $mime !== 'audio/x-wav') {
                return $mime;
            }
        }
        return null;
    }

    private static function dir(): string
    {
        return TOURFECTO_STORAGE . '/private/outbound-media';
    }

    /** Keep a copy of an uploaded file; returns its public name ("<32 hex>.<ext>") or null. */
    public static function store(string $tmpPath, string $ext): ?string
    {
        if (!preg_match('/^[a-z0-9]{2,5}$/', $ext) || !is_file($tmpPath)) {
            return null;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        self::sweep();
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        return @copy($tmpPath, $dir . '/' . $name) ? $name : null;
    }

    /** Absolute path of a stored file when the name is well formed and still fresh, else null. */
    public static function path(string $name): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) && (time() - (int) @filemtime($p)) <= self::TTL ? $p : null;
    }

    public static function url(string $name): string
    {
        return MetaOAuthClient::baseUrl() . '/media/out/' . $name;
    }

    /**
     * Send one attachment through the Page (Messenger and Instagram DMs use the same endpoint and page token).
     * @param callable|null $post fn(string $url, array $payload): array{0:int,1:string} [http code, body] (tests inject a fake)
     * @return array{ok:bool,id:string,error:string}
     */
    public static function send(string $token, string $recipientId, string $type, string $url, string $channel, ?callable $post = null): array
    {
        if ($token === '' || !preg_match('/^\d{5,25}$/', $recipientId) || !in_array($type, ['image', 'audio', 'video', 'file'], true) || !self::isOurs($url)) {
            return ['ok' => false, 'id' => '', 'error' => 'invalid_request'];
        }
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => $type, 'payload' => ['url' => $url]]]];
        if ($channel === 'messenger') {
            $payload['messaging_type'] = 'RESPONSE';
        }
        $endpoint = 'https://graph.facebook.com/' . MetaOAuthClient::apiVersion() . '/me/messages?access_token=' . urlencode($token);
        if ($post) {
            [$code, $body] = $post($endpoint, $payload);
        } else {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        $d = json_decode((string) $body, true);
        if ($code === 200 && is_array($d) && !empty($d['message_id'])) {
            return ['ok' => true, 'id' => (string) $d['message_id'], 'error' => ''];
        }
        $err = is_array($d) && isset($d['error']['message']) ? mb_substr((string) $d['error']['message'], 0, 160) : 'http_' . $code;
        Logger::warning('Meta attachment send failed', ['channel' => $channel, 'http_code' => $code, 'error' => $err]);
        return ['ok' => false, 'id' => '', 'error' => $err];
    }

    /** True for a URL we issued ourselves (the inbox may show it). */
    public static function isOurs(string $url): bool
    {
        $prefix = MetaOAuthClient::baseUrl() . '/media/out/';
        return strncmp($url, $prefix, strlen($prefix)) === 0 && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', substr($url, strlen($prefix))) === 1;
    }

    private static function sweep(): void
    {
        foreach ((array) @glob(self::dir() . '/*') as $f) {
            if (is_file($f) && (time() - (int) @filemtime($f)) > self::TTL + 86400) {
                @unlink($f);
            }
        }
    }
}
