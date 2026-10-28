<?php
/**
 * Tourfecto - ربط واتساب بمسح QR (بوابة Evolution API متعددة الأرقام)
 *
 * الأدمن بيحط بيانات البوابة مرة واحدة من لوحة الأدمن (الرابط + المفتاح العام).
 * كل موقع عميل ليه جلسة (instance) خاصة بيه باسم ثابت مش متوقَّع؛ العميل بيضغط
 * "ربط" ويمسح الكود من واتساب ← الأجهزة المرتبطة. مفيش أي مفاتيح عند العميل.
 *
 * الدوال الاستاتيك (parse*) نقية ومتختبرة من غير شبكة.
 */
final class WhatsAppQrGateway
{
    private string $base;
    private string $key;
    /** @var callable|null fn(string $method, string $url, ?array $body, array $headers): array{code:int, body:string} */
    private $transport;

    public function __construct(?string $base = null, ?string $key = null, ?callable $transport = null)
    {
        $s = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        $this->base = rtrim((string) ($base ?? ($s ? $s->get('wa_qr_base_url', '') : '')), '/');
        $this->key = (string) ($key ?? ($s ? $s->get('wa_qr_api_key', '') : ''));
        $this->transport = $transport;
    }

    public static function enabled(): bool
    {
        try {
            $s = new SystemSettingsService();
            return $s->get('wa_qr_enabled', '0') === '1' && $s->get('wa_qr_base_url', '') !== '' && $s->get('wa_qr_api_key', '') !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** اسم ثابت لكل موقع، مش ممكن تخمينه من رقم الموقع */
    public static function instanceName(int $websiteId): string
    {
        $key = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto';
        return 'tf' . $websiteId . '_' . substr(hash_hmac('sha256', 'wa-qr:' . $websiteId, $key), 0, 12);
    }

    public static function webhookSecret(int $websiteId): string
    {
        $key = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto';
        return substr(hash_hmac('sha256', 'wa-qr-webhook:' . $websiteId, $key), 0, 32);
    }

    // ------------------------------------------------------------------ عمليات البوابة

    /** بيعمل الجلسة لو مش موجودة ويربطها بالـ webhook. وجودها قبل كده مش خطأ. */
    public function ensureInstance(string $name, string $webhookUrl): array
    {
        $r = $this->call('POST', '/instance/create', [
            'instanceName' => $name, 'qrcode' => true, 'integration' => 'WHATSAPP-BAILEYS',
            'webhook' => ['url' => $webhookUrl, 'byEvents' => false, 'base64' => false, 'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE']],
        ]);
        $exists = in_array($r['code'], [403, 409], true) || stripos($r['body'], 'already in use') !== false || stripos($r['body'], 'already exists') !== false;
        if (!$r['ok'] && !$exists) return ['ok' => false, 'error' => self::error($r)];
        // تحديث الـ webhook دايمًا (لو الدومين اتغيّر أو الجلسة اتعملت قبل كده)
        $this->call('POST', '/webhook/set/' . rawurlencode($name), ['webhook' => ['enabled' => true, 'url' => $webhookUrl, 'webhookByEvents' => false, 'webhookBase64' => false, 'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE']]]);
        return ['ok' => true, 'created' => !$exists];
    }

    /** كود QR جديد (صورة base64) + كود الربط بالرقم لو البوابة بترجعه */
    public function qr(string $name): array
    {
        $r = $this->call('GET', '/instance/connect/' . rawurlencode($name));
        if (!$r['ok']) return ['ok' => false, 'error' => self::error($r)];
        return ['ok' => true] + self::parseQr(json_decode($r['body'], true) ?: []);
    }

    public function state(string $name): array
    {
        $r = $this->call('GET', '/instance/connectionState/' . rawurlencode($name));
        if ($r['code'] === 404) return ['ok' => true, 'state' => 'missing'];
        if (!$r['ok']) return ['ok' => false, 'error' => self::error($r)];
        return ['ok' => true, 'state' => self::parseState(json_decode($r['body'], true) ?: [])];
    }

    /** الرقم واسم الحساب بعد الربط */
    public function profile(string $name): array
    {
        $r = $this->call('GET', '/instance/fetchInstances?instanceName=' . rawurlencode($name));
        if (!$r['ok']) return [];
        return self::parseProfile(json_decode($r['body'], true) ?: []);
    }

    public function logout(string $name): bool
    {
        $a = $this->call('DELETE', '/instance/logout/' . rawurlencode($name));
        $b = $this->call('DELETE', '/instance/delete/' . rawurlencode($name));
        return $a['ok'] || $b['ok'] || $b['code'] === 404;
    }

    public function sendText(string $name, string $phone, string $text): array
    {
        $number = preg_replace('/\D/', '', $phone);
        if (strlen($number) < 7) return ['ok' => false, 'error' => 'invalid_number'];
        $r = $this->call('POST', '/message/sendText/' . rawurlencode($name), ['number' => $number, 'text' => $text]);
        return $r['ok'] ? ['ok' => true, 'id' => (json_decode($r['body'], true)['key']['id'] ?? null)] : ['ok' => false, 'error' => self::error($r)];
    }

    /** فحص البوابة من لوحة الأدمن */
    public function ping(): array
    {
        if ($this->base === '' || $this->key === '') return ['ok' => false, 'error' => 'Gateway URL and API key are required'];
        $r = $this->call('GET', '/instance/fetchInstances');
        return $r['ok'] ? ['ok' => true] : ['ok' => false, 'error' => self::error($r)];
    }

    // ------------------------------------------------------------------ تحليل الردود (نقي)

    public static function parseQr(array $j): array
    {
        $img = (string) ($j['base64'] ?? $j['qrcode']['base64'] ?? '');
        if ($img !== '' && !str_starts_with($img, 'data:image/')) $img = 'data:image/png;base64,' . $img;
        if ($img !== '' && !preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $img)) $img = '';
        $pairing = (string) ($j['pairingCode'] ?? $j['qrcode']['pairingCode'] ?? '');
        $state = self::parseState($j);
        return ['qr' => $img ?: null, 'pairing_code' => preg_match('/^[A-Z0-9-]{4,16}$/i', $pairing) ? $pairing : null, 'state' => $state];
    }

    /** open = متربط، connecting = مستني المسح، close/missing = مش متربط */
    public static function parseState(array $j): string
    {
        $s = strtolower((string) ($j['instance']['state'] ?? $j['state'] ?? $j['instance']['status'] ?? ''));
        return match (true) {
            $s === 'open', $s === 'connected' => 'open',
            $s === 'connecting', $s === 'qr', $s === 'qrcode' => 'connecting',
            $s === '' => 'connecting',
            default => 'close',
        };
    }

    public static function parseProfile(array $j): array
    {
        $row = isset($j[0]) ? $j[0] : $j;
        $row = $row['instance'] ?? $row;
        $owner = (string) ($row['ownerJid'] ?? $row['owner'] ?? '');
        $phone = preg_replace('/\D/', '', explode('@', $owner)[0]);
        return ['phone' => $phone !== '' ? $phone : null, 'name' => isset($row['profileName']) ? mb_substr((string) $row['profileName'], 0, 80) : null];
    }

    /**
     * رسالة واردة من الـ webhook. بيرجع null لأي حدث مش رسالة نصية من عميل
     * (رسايلنا إحنا، الجروبات، الحالات، الأحداث التانية).
     * @return array{id:string, phone:string, text:string, name:?string}|null
     */
    public static function parseIncoming(array $p): ?array
    {
        $event = strtolower(str_replace('_', '.', (string) ($p['event'] ?? '')));
        if ($event !== 'messages.upsert') return null;
        $d = $p['data'] ?? [];
        if (isset($d[0])) $d = $d[0];
        $key = $d['key'] ?? [];
        if (!empty($key['fromMe'])) return null;
        $jid = (string) ($key['remoteJid'] ?? '');
        if (!str_ends_with($jid, '@s.whatsapp.net') && !str_ends_with($jid, '@c.us')) return null; // جروبات/حالات/قنوات
        $m = $d['message'] ?? [];
        $text = (string) ($m['conversation'] ?? $m['extendedTextMessage']['text'] ?? $m['imageMessage']['caption'] ?? $m['videoMessage']['caption'] ?? '');
        $text = trim($text);
        if ($text === '') return null;
        $phone = preg_replace('/\D/', '', explode('@', $jid)[0]);
        if (strlen($phone) < 7) return null;
        return ['id' => (string) ($key['id'] ?? ''), 'phone' => $phone, 'text' => mb_substr($text, 0, 4000), 'name' => isset($d['pushName']) ? mb_substr((string) $d['pushName'], 0, 80) : null];
    }

    /** حدث تغيّر حالة الربط من الـ webhook (open/close) أو null */
    public static function parseConnectionEvent(array $p): ?string
    {
        $event = strtolower(str_replace('_', '.', (string) ($p['event'] ?? '')));
        return $event === 'connection.update' ? self::parseState(['state' => $p['data']['state'] ?? '']) : null;
    }

    // ------------------------------------------------------------------ HTTP

    private function call(string $method, string $path, ?array $body = null): array
    {
        if ($this->base === '' || $this->key === '') return ['ok' => false, 'code' => 0, 'body' => 'not_configured'];
        $headers = ['apikey: ' . $this->key, 'Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        if ($this->transport) {
            $r = ($this->transport)($method, $this->base . $path, $body, $headers);
        } else {
            $ch = curl_init($this->base . $path);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            $out = curl_exec($ch);
            $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($out) ? $out : (string) curl_error($ch)];
            curl_close($ch);
        }
        $r['ok'] = $r['code'] >= 200 && $r['code'] < 300;
        return $r;
    }

    private static function error(array $r): string
    {
        if ($r['code'] === 0) return 'WhatsApp gateway unreachable';
        if ($r['code'] === 401 || $r['code'] === 403) return 'WhatsApp gateway rejected the platform key';
        $j = json_decode((string) $r['body'], true);
        $msg = is_array($j) ? ($j['response']['message'][0] ?? $j['message'] ?? $j['error'] ?? '') : '';
        return 'WhatsApp gateway error (' . $r['code'] . ')' . (is_string($msg) && $msg !== '' ? ': ' . mb_substr($msg, 0, 160) : '');
    }
}
