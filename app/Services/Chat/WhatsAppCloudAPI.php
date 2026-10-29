<?php

/**
 * Tourfecto - واتساب الرسمي (WhatsApp Business Cloud API من Meta) لكل شركة برقمها هي
 *
 * كل شركة بتربط: Phone Number ID + WhatsApp Business Account ID + توكن دائم (System User).
 * الربط محفوظ في platform_connections (platform = 'whatsapp_cloud'):
 *   external_account_id  = Phone Number ID
 *   external_location_id = WABA ID
 *   access_token         = التوكن (مشفّر)
 *   refresh_token        = App Secret (مشفّر، اختياري - للتحقق من توقيع الـ webhook)
 *   meta_json            = رقم العرض، الاسم، الجودة، وحالة القوالب
 *
 * قاعدة Meta المهمة: أي رسالة بتبدأها انت (تذكير، طلب تقييم، متابعة) لازم تكون "قالب" متوافق عليه.
 * الرسايل العادية مسموحة بس خلال 24 ساعة من آخر رسالة من العميل (الرد في الشات).
 */
class WhatsAppCloudAPI
{
    public const PLATFORM = 'whatsapp_cloud';
    /** أكواد Meta اللي معناها "برة نافذة الـ 24 ساعة - لازم قالب" */
    public const WINDOW_ERRORS = [131047, 470];

    private string $phoneNumberId;
    private string $token;
    private string $wabaId;
    private string $version;
    /** @var callable|null للاختبارات: fn(string $method, string $url, ?array $json): array{0:int,1:string} */
    private $transport;
    /** @var callable|null للاختبارات بس (CLI): بيتستخدم لو مفيش transport متمرر */
    public static $testTransport = null;

    public function __construct(string $phoneNumberId, string $token, string $wabaId = '', ?callable $transport = null)
    {
        $this->phoneNumberId = trim($phoneNumberId);
        $this->token = trim($token);
        $this->wabaId = trim($wabaId);
        $v = function_exists('env') ? (string) (env('WHATSAPP_API_VERSION') ?: env('META_API_VERSION') ?: '') : '';
        $this->version = preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v25.0';
        $this->transport = $transport ?: (PHP_SAPI === 'cli' ? self::$testTransport : null);
    }

    /** اتصال الموقع (أو null لو مش مربوط) */
    public static function connectionRow(int $websiteId): ?array
    {
        try {
            $r = Database::getInstance()->query(
                "SELECT * FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' AND status = 'connected' ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            return $r[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function forWebsite(int $websiteId, ?callable $transport = null): ?self
    {
        $row = self::connectionRow($websiteId);
        if (!$row) {
            return null;
        }
        try {
            $token = (new Encryption())->decrypt((string) $row['access_token']);
        } catch (Throwable $e) {
            return null;
        }
        if ($token === '' || empty($row['external_account_id'])) {
            return null;
        }
        return new self((string) $row['external_account_id'], $token, (string) $row['external_location_id'], $transport);
    }

    /**
     * رقم بصيغة دولية أرقام بس (Meta مش بتقبل + ولا مسافات).
     * 00 international prefix is accepted. Local numbers without a country code are rejected.
     */
    public static function normalizePhone(string $phone): string
    {
        return WhatsAppMarketingService::phone($phone);
    }

    /** Preserve provider IDs; normalize phone recipients before text delivery. */
    public static function recipientForText(string $recipient): string
    {
        $recipient = trim($recipient);
        if (preg_match('/^[A-Z]{2}\\.(?:ENT\\.)?[A-Za-z0-9._-]{8,128}$/D', $recipient)) {
            return $recipient;
        }
        return self::normalizePhone($recipient);
    }

    /** قيم القالب: Meta بترفض السطر الجديد والتاب و4 مسافات ورا بعض جوه المتغير، وبترفض المتغير الفاضي */
    public static function cleanParam(string $v): string
    {
        $v = preg_replace('/[\r\n\t]+/u', ' ', $v);
        $v = preg_replace('/ {4,}/', '   ', (string) $v);
        $v = trim((string) $v);
        return $v === '' ? '-' : mb_substr($v, 0, 1000);
    }

    // ------------------------------------------------------------------ HTTP

    /** @return array{ok:bool, code:int, data:array, error:?string, error_code:?int} */
    private function call(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $url = 'https://graph.facebook.com/' . $this->version . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        try {
            if ($this->transport) {
                [$code, $body] = ($this->transport)($method, $url, $json);
            } else {
                $ch = curl_init($url);
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
                ];
                if ($json !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
                }
                curl_setopt_array($ch, $opts);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($body === false) {
                    return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'تعذر الاتصال بـ Meta: ' . $err, 'error_code' => null];
                }
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 0, 'data' => [], 'error' => $e->getMessage(), 'error_code' => null];
        }
        $data = json_decode((string) $body, true);
        if(json_last_error()!==JSON_ERROR_NONE||!is_array($data))return ['ok'=>false,'code'=>(int)$code,'data'=>[],'error'=>'Invalid Meta response; check outcome before retrying','error_code'=>null];
        if ($code < 200 || $code >= 300 || isset($data['error'])) {
            $err = $data['error'] ?? [];
            $msg = (string) ($err['error_user_msg'] ?? $err['error_data']['details'] ?? $err['message'] ?? "Meta HTTP {$code}");
            return ['ok' => false, 'code' => (int) $code, 'data' => $data, 'error' => $msg, 'error_code' => isset($err['code']) ? (int) $err['code'] : null];
        }
        return ['ok' => true, 'code' => (int) $code, 'data' => $data, 'error' => null, 'error_code' => null];
    }

    // ------------------------------------------------------------------ الحساب

    /** بيانات الرقم (للتحقق إن البيانات صح وقت الربط) */
    public function phoneInfo(): array
    {
        $r = $this->call('GET', $this->phoneNumberId, null, ['fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status']);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        return ['ok' => true, 'display_phone' => (string) ($r['data']['display_phone_number'] ?? ''), 'verified_name' => (string) ($r['data']['verified_name'] ?? ''),
            'quality' => (string) ($r['data']['quality_rating'] ?? ''), 'name_status' => (string) ($r['data']['name_status'] ?? '')];
    }

    /** اشتراك تطبيق الشركة في حساب الواتساب عشان الرسايل الواردة توصل للـ webhook */
    public function subscribeApp(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'WABA ID ناقص'];
        }
        $r = $this->call('POST', $this->wabaId . '/subscribed_apps', []);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /** التطبيقات المشتركة في رسايل الـ WABA (لازم تطبيق المنصة يكون منهم وإلا الرسايل الواردة مش بتوصل) @return array{ok:bool, ids:string[], error:?string} */
    public function subscribedApps(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'ids' => [], 'error' => 'WABA ID ناقص'];
        }
        $r = $this->call('GET', $this->wabaId . '/subscribed_apps');
        $ids = [];
        foreach ((array) ($r['data']['data'] ?? []) as $app) {
            $id = (string) ($app['whatsapp_business_api_data']['id'] ?? $app['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return ['ok' => $r['ok'], 'ids' => $ids, 'error' => $r['error']];
    }

    /** تسجيل الرقم على Cloud API (مطلوب للأرقام الجديدة اللي جاية من الربط بضغطة) - pin = رمز التحقق بخطوتين */
    public function registerNumber(string $pin): array
    {
        $r = $this->call('POST', $this->phoneNumberId . '/register', ['messaging_product' => 'whatsapp', 'pin' => $pin]);
        // متسجل قبل كده = تمام
        $already = !$r['ok'] && preg_match('/already|registered/i', (string) $r['error']);
        return ['ok' => $r['ok'] || $already, 'error' => $r['ok'] || $already ? null : $r['error']];
    }

    /**
     * الربط بضغطة (Embedded Signup): تبديل الكود اللي راجع من Facebook JS SDK بتوكن الشركة
     * (Business Integration System User token - مابينتهيش).
     * @return array{ok:bool, token:?string, error:?string}
     */
    public static function exchangeCode(string $code, ?callable $transport = null): array
    {
        $appId = class_exists('MetaOAuthClient') ? MetaOAuthClient::setting('meta_app_id', 'META_APP_ID') : '';
        $secret = class_exists('MetaOAuthClient') ? MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET') : '';
        if ($appId === '' || $secret === '') {
            return ['ok' => false, 'token' => null, 'error' => 'Meta App ID / Secret مش متظبطين في إعدادات الأدمن'];
        }
        $v = class_exists('MetaOAuthClient') ? MetaOAuthClient::apiVersion() : 'v25.0';
        $url = "https://graph.facebook.com/{$v}/oauth/access_token?" . http_build_query(['client_id' => $appId, 'client_secret' => $secret, 'code' => $code]);
        $transport = $transport ?: (PHP_SAPI === 'cli' ? self::$testTransport : null);
        try {
            if ($transport) {
                [$code_, $body] = $transport('GET', $url, null);
            } else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
                $body = (string) curl_exec($ch);
                $code_ = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'token' => null, 'error' => $e->getMessage()];
        }
        $d = json_decode((string) $body, true) ?: [];
        if ((int) $code_ !== 200 || empty($d['access_token'])) {
            return ['ok' => false, 'token' => null, 'error' => (string) ($d['error']['message'] ?? 'تعذر تبديل الكود (HTTP ' . $code_ . ')')];
        }
        return ['ok' => true, 'token' => (string) $d['access_token'], 'error' => null];
    }

    // ------------------------------------------------------------------ الإرسال

    /** رسالة نصية: وجهة رقم هاتف أو BSUID محفوظ من Webhook موثوق. $replyToWamid = رسالة العميل اللي بنرد عليها (Reply). */
    public function sendText(string $to, string $text, ?string $replyToWamid = null): array
    {
        $body = self::textBody($to, $text, $replyToWamid);
        if ($body === null) {
            return ['ok'=>false, 'id'=>null, 'error'=>'Invalid WhatsApp recipient', 'error_code'=>null, 'http_code'=>0, 'window'=>false];
        }
        return $this->sendResult($this->call('POST', $this->phoneNumberId . '/messages', $body));
    }

    /** Reaction (emoji) on a customer message. An empty emoji removes the reaction. */
    public function sendReaction(string $to, string $wamid, string $emoji): array
    {
        $body = self::reactionBody($to, $wamid, $emoji);
        if ($body === null) {
            return ['ok'=>false, 'id'=>null, 'error'=>'Invalid WhatsApp reaction', 'error_code'=>null, 'http_code'=>0, 'window'=>false];
        }
        return $this->sendResult($this->call('POST', $this->phoneNumberId . '/messages', $body));
    }

    /** @return array|null request body, or null when the recipient is invalid (pure: no network). */
    public static function textBody(string $to, string $text, ?string $replyToWamid = null): ?array
    {
        $dest = self::destination($to);
        if ($dest === null) return null;
        [$destination, $isBsuid] = $dest;
        $body = [
            'messaging_product' => 'whatsapp',
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => mb_substr($text, 0, 4096)],
        ];
        if (self::validWamid($replyToWamid)) {
            $body['context'] = ['message_id' => $replyToWamid];
        }
        return $body + self::recipientFields($destination, $isBsuid);
    }

    /** @return array|null */
    public static function reactionBody(string $to, string $wamid, string $emoji): ?array
    {
        $dest = self::destination($to);
        if ($dest === null || !self::validWamid($wamid)) return null;
        // One emoji at most (an empty string clears the reaction); anything long is rejected rather than truncated.
        if ($emoji !== '' && (mb_strlen($emoji) > 8 || preg_match('/[\p{L}\p{N}\s<>&"\x27]/u', $emoji))) return null;
        [$destination, $isBsuid] = $dest;
        return ['messaging_product' => 'whatsapp', 'type' => 'reaction', 'reaction' => ['message_id' => $wamid, 'emoji' => $emoji]]
            + self::recipientFields($destination, $isBsuid);
    }

    private static function validWamid(?string $id): bool
    {
        return $id !== null && preg_match('/^[A-Za-z0-9._=+\/-]{10,255}$/D', $id) === 1;
    }

    /** @return array{0:string,1:bool}|null phone (digits) or BSUID */
    private static function destination(string $to): ?array
    {
        $to = trim($to);
        $isBsuid = preg_match('/^[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9._-]{8,128}$/D', $to) === 1;
        try {
            $destination = $isBsuid ? $to : self::normalizePhone($to);
        } catch (InvalidArgumentException $e) {
            return null;
        }
        if ($destination === '' || (!$isBsuid && preg_match('/^[1-9][0-9]{7,14}$/D', $destination) !== 1)) return null;
        return [$destination, $isBsuid];
    }

    /** For Meta Cloud API, a BSUID goes in "recipient" and a phone number in "to". */
    private static function recipientFields(string $destination, bool $isBsuid): array
    {
        return $isBsuid ? ['recipient' => $destination] : ['recipient_type' => 'individual', 'to' => $destination];
    }

    /**
     * Media message (image/video/audio/document) using a media ID from uploadMedia().
     * Same recipient rules as sendText(); only allowed inside the 24-hour window.
     */
    public function sendMedia(string $to, string $type, string $mediaId, string $caption = '', string $filename = ''): array
    {
        $to = trim($to);
        $isBsuid = preg_match('/^[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9._-]{8,128}$/D', $to) === 1;
        try {
            $destination = $isBsuid ? $to : self::normalizePhone($to);
        } catch (InvalidArgumentException $e) {
            $destination = '';
        }
        if ($destination === '' || !in_array($type, ['image', 'video', 'audio', 'document'], true) || !preg_match('/^[0-9]{1,64}$/D', $mediaId)) {
            return ['ok'=>false, 'id'=>null, 'error'=>'Invalid WhatsApp recipient or media', 'error_code'=>null, 'http_code'=>0, 'window'=>false];
        }
        $media = ['id' => $mediaId];
        // Meta rejects captions on audio messages.
        if ($caption !== '' && $type !== 'audio') $media['caption'] = mb_substr($caption, 0, 1024);
        if ($type === 'document' && $filename !== '') $media['filename'] = mb_substr($filename, 0, 240);
        $body = ['messaging_product' => 'whatsapp', 'type' => $type, $type => $media];
        if ($isBsuid) $body['recipient'] = $destination;
        else { $body['recipient_type'] = 'individual'; $body['to'] = $destination; }
        return $this->sendResult($this->call('POST', $this->phoneNumberId . '/messages', $body));
    }

    /**
     * Download a media file by its Meta media ID (incoming customer media, or media we uploaded).
     * Only Meta's own CDN hosts are fetched; redirects are not followed.
     * @return array{ok:bool, mime:?string, bytes:?string, error:?string}
     */
    public function downloadMedia(string $mediaId, int $maxBytes = 26214400): array
    {
        if (!preg_match('/^[0-9]{1,64}$/D', $mediaId)) {
            return ['ok' => false, 'mime' => null, 'bytes' => null, 'error' => 'Invalid media ID'];
        }
        $info = $this->call('GET', $mediaId);
        $url = (string) ($info['data']['url'] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!$info['ok'] || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || !preg_match('/(^|\.)(fbsbx\.com|facebook\.com|fbcdn\.net|whatsapp\.net)$/D', $host)) {
            return ['ok' => false, 'mime' => null, 'bytes' => null, 'error' => $info['error'] ?: 'Media is no longer available from WhatsApp'];
        }
        if ((int) ($info['data']['file_size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'mime' => null, 'bytes' => null, 'error' => 'Media file is too large to preview'];
        }
        $mime = (string) ($info['data']['mime_type'] ?? 'application/octet-stream');
        if ($this->transport) {
            [$code, $body] = ($this->transport)('GET', $url, null);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
                // Meta's CDN rejects requests without a User-Agent.
                CURLOPT_USERAGENT => 'Tourfecto/1.0',
                CURLOPT_MAXFILESIZE => $maxBytes,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if ($body === false || (int) $code !== 200 || strlen((string) $body) > $maxBytes) {
            return ['ok' => false, 'mime' => null, 'bytes' => null, 'error' => 'Could not download the media from WhatsApp'];
        }
        return ['ok' => true, 'mime' => $mime, 'bytes' => (string) $body, 'error' => null];
    }

    // ------------------------------------------------------------------ حالة الحساب والتحكم

    /**
     * Live number + account health from Meta. Optional fields are requested separately so an
     * API-version change in one of them never hides the rest.
     * @return array{ok:bool, phone:array, account:array, error:?string}
     */
    public function accountHealth(): array
    {
        $core = $this->call('GET', $this->phoneNumberId, null, ['fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status,status']);
        if (!$core['ok']) {
            return ['ok' => false, 'phone' => [], 'account' => [], 'error' => $core['error']];
        }
        $phone = $core['data'];
        foreach (['messaging_limit_tier', 'account_mode', 'is_official_business_account', 'throughput', 'new_name_status', 'platform_type'] as $f) {
            $r = $this->call('GET', $this->phoneNumberId, null, ['fields' => $f]);
            if ($r['ok'] && array_key_exists($f, $r['data'])) $phone[$f] = $r['data'][$f];
        }
        $account = [];
        if ($this->wabaId !== '') {
            foreach (['name', 'account_review_status', 'business_verification_status', 'whatsapp_business_manager_messaging_limit'] as $f) {
                $r = $this->call('GET', $this->wabaId, null, ['fields' => $f]);
                if ($r['ok'] && array_key_exists($f, $r['data'])) $account[$f] = $r['data'][$f];
            }
        }
        return ['ok' => true, 'phone' => $phone, 'account' => $account, 'error' => null];
    }

    /** Two-step verification PIN of the number (6 digits). */
    public function setPin(string $pin): array
    {
        if (!preg_match('/^[0-9]{6}$/D', $pin)) {
            return ['ok' => false, 'error' => 'PIN must be 6 digits'];
        }
        $r = $this->call('POST', $this->phoneNumberId, ['pin' => $pin]);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /** Welcome-screen suggestions (ice breakers) and "/" commands shown to customers. */
    public function conversationalAutomation(): array
    {
        $r = $this->call('GET', $this->phoneNumberId, null, ['fields' => 'conversational_automation']);
        $c = $r['data']['conversational_automation'] ?? [];
        return ['ok' => $r['ok'], 'prompts' => array_values(array_map('strval', (array) ($c['prompts'] ?? []))),
            'commands' => array_values(array_map(fn($x) => ['command_name' => (string) ($x['command_name'] ?? ''), 'command_description' => (string) ($x['command_description'] ?? '')], (array) ($c['commands'] ?? []))),
            'error' => $r['error']];
    }

    public function setConversationalAutomation(array $prompts, array $commands): array
    {
        $prompts = array_values(array_filter(array_map(fn($p) => mb_substr(trim((string) $p), 0, 80), $prompts), 'strlen'));
        if (count($prompts) > 4) throw new InvalidArgumentException('Up to 4 suggested questions');
        $clean = [];
        foreach ($commands as $c) {
            $name = strtolower(trim((string) ($c['command_name'] ?? ''), " /\t"));
            $desc = mb_substr(trim((string) ($c['command_description'] ?? '')), 0, 256);
            if ($name === '' && $desc === '') continue;
            if (!preg_match('/^[a-z0-9_]{1,32}$/D', $name) || $desc === '') throw new InvalidArgumentException('Commands use English letters, numbers or _ and need a description');
            $clean[] = ['command_name' => $name, 'command_description' => $desc];
        }
        if (count($clean) > 30) throw new InvalidArgumentException('Up to 30 commands');
        $r = $this->call('POST', $this->phoneNumberId . '/conversational_automation', [
            'enable_welcome_message' => false, 'prompts' => $prompts, 'commands' => $clean,
        ]);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /** Block (or unblock) a customer. Meta only allows blocking someone who messaged in the last 24 hours. */
    public function blockUser(string $to, bool $block = true): array
    {
        $r = $this->call($block ? 'POST' : 'DELETE', $this->phoneNumberId . '/block_users', [
            'messaging_product' => 'whatsapp', 'block_users' => [['user' => self::recipientForText($to)]],
        ]);
        $failed = $r['data']['block_users']['failed_users'] ?? $r['data']['failed_users'] ?? [];
        $ok = $r['ok'] && empty($failed);
        $err = $r['error'] ?? (($failed[0]['errors'][0]['message'] ?? null) ?: null);
        return ['ok' => $ok, 'error' => $ok ? null : ($err ?: 'Meta did not confirm the block'), 'error_code' => $r['error_code'] ?? null];
    }

    /** @return array{ok:bool, users:string[], error:?string} */
    public function blockedUsers(): array
    {
        $r = $this->call('GET', $this->phoneNumberId . '/block_users', null, ['limit' => 100]);
        $users = [];
        foreach ((array) ($r['data']['data'] ?? []) as $u) {
            $id = (string) ($u['wa_id'] ?? $u['user'] ?? '');
            if ($id !== '') $users[] = $id;
        }
        return ['ok' => $r['ok'], 'users' => $users, 'error' => $r['error']];
    }

    // ------------------------------------------------------------------ الكتالوج

    /** Whether the catalog shows on the WhatsApp profile and the cart is on. */
    public function commerceSettings(): array
    {
        $r = $this->call('GET', $this->phoneNumberId . '/whatsapp_commerce_settings');
        $d = $r['data']['data'][0] ?? [];
        return ['ok' => $r['ok'], 'catalog_visible' => !empty($d['is_catalog_visible']), 'cart_enabled' => !empty($d['is_cart_enabled']), 'error' => $r['error']];
    }

    public function setCommerceSettings(bool $catalogVisible, bool $cartEnabled): array
    {
        $r = $this->call('POST', $this->phoneNumberId . '/whatsapp_commerce_settings', [
            'is_catalog_visible' => $catalogVisible, 'is_cart_enabled' => $cartEnabled,
        ]);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /** Catalogs connected to the WhatsApp account (made in Meta Commerce Manager). Needs catalog_management access. */
    public function linkedCatalogs(): array
    {
        if ($this->wabaId === '') return ['ok' => false, 'items' => [], 'error' => 'WABA ID ناقص', 'error_code' => null];
        $r = $this->call('GET', $this->wabaId . '/product_catalogs', null, ['fields' => 'id,name']);
        return ['ok' => $r['ok'], 'items' => array_values((array) ($r['data']['data'] ?? [])), 'error' => $r['error'], 'error_code' => $r['error_code']];
    }

    public function catalogProducts(string $catalogId): array
    {
        if (!preg_match('/^[0-9]{1,32}$/D', $catalogId)) return ['ok' => false, 'items' => [], 'error' => 'Invalid catalog'];
        $r = $this->call('GET', $catalogId . '/products', null, ['fields' => 'id,retailer_id,name,price,image_url,availability,url', 'limit' => 100]);
        return ['ok' => $r['ok'], 'items' => array_values((array) ($r['data']['data'] ?? [])), 'error' => $r['error']];
    }

    /**
     * Interactive catalog message (opens the whole catalog), or a single product when $retailerId is given.
     * Session message: only inside the 24-hour window.
     */
    public function sendCatalog(string $to, string $bodyText, string $catalogId = '', string $retailerId = ''): array
    {
        $to = trim($to);
        $isBsuid = preg_match('/^[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9._-]{8,128}$/D', $to) === 1;
        try {
            $destination = $isBsuid ? $to : self::normalizePhone($to);
        } catch (InvalidArgumentException $e) {
            return ['ok'=>false, 'id'=>null, 'error'=>'Invalid WhatsApp recipient', 'error_code'=>null, 'http_code'=>0, 'window'=>false];
        }
        $bodyText = mb_substr(trim($bodyText), 0, 1024);
        if ($retailerId !== '') {
            $interactive = ['type' => 'product', 'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => mb_substr($retailerId, 0, 100)]];
            if ($bodyText !== '') $interactive['body'] = ['text' => $bodyText];
        } else {
            $interactive = ['type' => 'catalog_message', 'body' => ['text' => $bodyText !== '' ? $bodyText : 'Browse our trips and services'], 'action' => ['name' => 'catalog_message']];
        }
        $body = ['messaging_product' => 'whatsapp', 'type' => 'interactive', 'interactive' => $interactive];
        if ($isBsuid) $body['recipient'] = $destination;
        else { $body['recipient_type'] = 'individual'; $body['to'] = $destination; }
        return $this->sendResult($this->call('POST', $this->phoneNumberId . '/messages', $body));
    }

    /** رسالة قالب متوافق عليه - مسموحة في أي وقت */
    public function sendTemplate(string $to, string $name, string $lang, array $bodyParams = [],array $extraComponents=[]): array
    {
        $tpl = ['name' => $name, 'language' => ['code' => $lang]];
        if ($bodyParams) {
            $parameters=[];foreach($bodyParams as $key=>$value){$p=['type'=>'text','text'=>self::cleanParam((string)$value)];if(is_string($key))$p['parameter_name']=$key;$parameters[]=$p;}$tpl['components']=[['type'=>'body','parameters'=>$parameters]];
        }
        if($extraComponents)$tpl['components']=array_merge($tpl['components']??[],$extraComponents);
        $r = $this->call('POST', $this->phoneNumberId . '/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::normalizePhone($to),
            'type' => 'template',
            'template' => $tpl,
        ]);
        return $this->sendResult($r);
    }

    private function sendResult(array $r): array
    {
        if (!$r['ok']) {
            $window = in_array((int) $r['error_code'], self::WINDOW_ERRORS, true);
            return ['http_code'=>(int)$r['code'], 'ok' => false, 'id' => null, 'error' => $window ? 'العميل ماكلمكش آخر 24 ساعة - لازم قالب متوافق عليه' : self::friendlyError((int) $r['error_code'], (string) $r['error']), 'error_code' => $r['error_code'], 'window' => $window];
        }
        $id=$r['data']['messages'][0]['id']??null;
        if(!is_string($id)||$id==='')return ['ok'=>false,'id'=>null,'error'=>'Meta did not return a message ID; check the provider before retrying','http_code'=>(int)$r['code'],'error_code'=>null,'window'=>false];
        return ['ok'=>true,'id'=>$id,'http_code'=>(int)$r['code'],'message_status'=>(string)($r['data']['messages'][0]['message_status']??'accepted'),'error'=>null,'error_code'=>null,'window'=>false];
    }

    /** رسايل أخطاء Meta الشائعة بالعربي */
    public static function friendlyError(int $code, string $raw): string
    {
        $map = [
            190 => 'التوكن انتهى أو اتلغى - اعمل توكن دائم جديد (System User) واربط تاني',
            100 => 'بيانات غلط (رقم أو اسم قالب أو متغيرات) - ' . $raw,
            131030 => 'الرقم ده مش ضمن الأرقام المسموح بيها في وضع الاختبار - ضيفه في Meta أو فعّل الحساب',
            131026 => 'الرسالة ماوصلتش - الرقم مش عليه واتساب أو العميل عامل بلوك',
            131058 => 'تطبيق Meta لسه مش منشور، فـ Meta مش بتسمح بالإرسال غير من أرقام الاختبار بتاعتها. انشر التطبيق (Live) من developers.facebook.com وجرّب تاني',
            131056 => 'رسايل كتير لنفس الرقم في وقت قصير - استنى شوية',
            132000 => 'عدد متغيرات القالب مش مطابق',
            132001 => 'القالب مش موجود بالاسم واللغة دي (أو لسه ماتوافقش عليه)',
            132007 => 'القالب اترفض أو اتوقف من Meta',
            132015 => 'القالب متوقف بسبب جودة منخفضة',
            131042 => 'مشكلة في الدفع على حساب WhatsApp Business - راجع وسيلة الدفع في Meta',
            133010 => 'الرقم لسه مش متسجل على Cloud API',
            80007 => 'وصلت لحد الرسايل المسموح - استنى شوية',
        ];
        return $map[$code] ?? ($raw !== '' ? $raw : 'خطأ من Meta');
    }

    // ------------------------------------------------------------------ القوالب

    /** كل قوالب الحساب بحالاتها @return array{ok:bool, items:array, error:?string} */
    public function listTemplates(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'items' => [], 'error' => 'WABA ID ناقص'];
        }
        $r = $this->call('GET', $this->wabaId . '/message_templates', null, ['fields' => 'name,language,status,category,rejected_reason', 'limit' => 200]);
        if (!$r['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $r['error']];
        }
        return ['ok' => true, 'items' => $r['data']['data'] ?? [], 'error' => null];
    }

    /** Full positional template definitions; pagination uses a cursor on our fixed Meta host. */
    public function marketingTemplates(?string $after=null): array
    {
        if($this->wabaId==='')return ['ok'=>false,'items'=>[],'after'=>null];
        $query=['fields'=>'name,language,status,category,components,parameter_format','limit'=>100];
        if($after!==null){if(strlen($after)>4096)throw new InvalidArgumentException('Invalid template cursor');$query['after']=$after;}
        $r=$this->call('GET',$this->wabaId.'/message_templates',null,$query);
        return ['ok'=>$r['ok']&&isset($r['data']['data'])&&is_array($r['data']['data'])&&(!isset($r['data']['paging']['next'])||!empty($r['data']['paging']['cursors']['after'])),'items'=>$r['data']['data']??[],'after'=>!empty($r['data']['paging']['next'])?($r['data']['paging']['cursors']['after']??null):null];
    }

    /** Upload bytes only to Meta's fixed host; never follow provider redirects. */
    public function uploadMedia(string $file,string $mime,bool $templateExample=false): array
    {
        if(!is_file($file)||!is_readable($file))throw new InvalidArgumentException('Upload file unavailable');
        if(!$templateExample)return $this->binaryRequest($this->phoneNumberId.'/media',$file,$mime,true);
        $appId=class_exists('MetaOAuthClient')?MetaOAuthClient::setting('meta_app_id','META_APP_ID'):'';
        if(!preg_match('/^[0-9]{1,32}$/D',$appId))throw new InvalidArgumentException('Configure the Meta application before uploading template examples');
        // Resumable Upload API step 1: open a session (params in the body so the POST always has a Content-Length).
        $r=$this->call('POST',$appId.'/uploads',['file_name'=>'upload'.($mime==='image/png'?'.png':($mime==='image/jpeg'?'.jpg':'')),'file_length'=>filesize($file),'file_type'=>$mime]);
        $session=$r['data']['id']??'';
        // Session IDs are base64-like ("upload:MTph...?sig=AR..") and may contain + / = characters.
        if(!$r['ok']||!is_string($session)||!preg_match('#^upload:[A-Za-z0-9_?=&%:.+/-]{1,2000}$#D',$session))return ['ok'=>false,'value'=>null,'error'=>'Meta did not open the upload session'.($r['error']?': '.$r['error']:'')];
        return $this->binaryRequest($session,$file,$mime,false);
    }
    private function binaryRequest(string $path,string $file,string $mime,bool $multipart): array
    {
        $url='https://graph.facebook.com/'.$this->version.'/'.$path;
        try{
            if($this->transport){[$code,$body]=($this->transport)('POST',$url,['upload_kind'=>$multipart?'media':'template_example','mime'=>$mime,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)]);}
            else{$ch=curl_init($url);$headers=$multipart?['Authorization: Bearer '.$this->token]:['Authorization: OAuth '.$this->token,'file_offset: 0','Content-Type: '.$mime];$data=$multipart?['messaging_product'=>'whatsapp','type'=>$mime,'file'=>new CURLFile($file,$mime,basename($file))]:file_get_contents($file);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$data]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);}
            $d=json_decode((string)$body,true);$id=$d[$multipart?'id':'h']??null;$valid=is_string($id)&&($multipart?preg_match('/^[0-9]{1,64}$/D',$id):strlen($id)>0&&strlen($id)<4096);
            // Pass Meta's own reason through (no tokens are in it) so the user sees why an upload failed.
            $metaErr=is_array($d)?(string)($d['error']['error_user_msg']??$d['error']['message']??''):'';
            return ['ok'=>$code>=200&&$code<300&&$valid,'value'=>$valid?$id:null,'error'=>$code>=200&&$code<300&&$valid?null:('Meta did not confirm the media upload'.($metaErr!==''?': '.mb_substr($metaErr,0,300):' (HTTP '.(int)$code.')'))];
        }catch(Throwable $e){return ['ok'=>false,'value'=>null,'error'=>'Media upload outcome could not be confirmed'];}
    }

    public function createTemplate(array $def): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'error' => 'WABA ID ناقص'];
        }
        $r = $this->call('POST', $this->wabaId . '/message_templates', $def);
        return ['http_code'=>(int)$r['code'],'ok' => $r['ok']&&!empty($r['data']['id']), 'status' => (string) ($r['data']['status'] ?? ''), 'category' => (string) ($r['data']['category'] ?? ''), 'error' => $r['error'], 'error_code' => $r['error_code']];
    }

    // ------------------------------------------------------------------ ملف الشركة على واتساب

    /** مجالات النشاط اللي Meta بتقبلها في ملف الشركة */
    public const VERTICALS = ['TRAVEL', 'HOTEL', 'RESTAURANT', 'ENTERTAIN', 'EVENT_PLAN', 'PROF_SERVICES', 'RETAIL', 'AUTO', 'BEAUTY', 'APPAREL', 'EDU', 'FINANCE', 'GROCERY', 'HEALTH', 'NONPROFIT', 'OTHER'];

    /** اللي العملاء بيشوفوه لما يفتحوا بروفايل الرقم على واتساب */
    public function businessProfile(): array
    {
        $r = $this->call('GET', $this->phoneNumberId . '/whatsapp_business_profile', null, ['fields' => 'about,address,description,email,profile_picture_url,websites,vertical']);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $p = $r['data']['data'][0] ?? [];
        return ['ok' => true, 'profile' => [
            'about' => (string) ($p['about'] ?? ''), 'address' => (string) ($p['address'] ?? ''), 'description' => (string) ($p['description'] ?? ''),
            'email' => (string) ($p['email'] ?? ''), 'websites' => array_values(array_map('strval', (array) ($p['websites'] ?? []))),
            'vertical' => (string) ($p['vertical'] ?? ''), 'profile_picture_url' => (string) ($p['profile_picture_url'] ?? ''),
        ]];
    }

    /**
     * تعديل ملف الشركة. الحدود من Meta: about 139 حرف، address 256، description 512، email 128، موقعين بالكتير.
     * @param array $p الحقول اللي اتبعتت بس بتتغير؛ profile_picture_handle من uploadMedia(..., true)
     */
    public function updateBusinessProfile(array $p): array
    {
        $limits = ['about' => 139, 'address' => 256, 'description' => 512, 'email' => 128];
        $body = ['messaging_product' => 'whatsapp'];
        foreach ($limits as $k => $max) {
            if (!array_key_exists($k, $p)) {
                continue;
            }
            $v = trim((string) $p[$k]);
            if (mb_strlen($v) > $max) {
                throw new InvalidArgumentException($k . ': ' . $max . ' حرف بالكتير');
            }
            // Meta مابتقبلش نبذة فاضية: لو العميل سابها فاضية مابنبعتهاش (والباقي يتحفظ عادي)
            if ($k === 'about' && $v === '') {
                continue;
            }
            if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('الإيميل مش صحيح');
            }
            $body[$k] = $v;
        }
        if (array_key_exists('websites', $p)) {
            $sites = array_values(array_filter(array_map('trim', (array) $p['websites'])));
            if (count($sites) > 2) {
                throw new InvalidArgumentException('موقعين بالكتير');
            }
            foreach ($sites as $s) {
                if (!preg_match('#^https?://[^\s]{3,250}$#i', $s)) {
                    throw new InvalidArgumentException('رابط الموقع لازم يبدأ بـ https://');
                }
            }
            $body['websites'] = $sites;
        }
        if (!empty($p['vertical'])) {
            if (!in_array($p['vertical'], self::VERTICALS, true)) {
                throw new InvalidArgumentException('مجال النشاط مش مدعوم');
            }
            $body['vertical'] = $p['vertical'];
        }
        if (!empty($p['profile_picture_handle'])) {
            $body['profile_picture_handle'] = (string) $p['profile_picture_handle'];
        }
        $r = $this->call('POST', $this->phoneNumberId . '/whatsapp_business_profile', $body);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /**
     * رقم شغال على تطبيق واتساب بزنس ومتربط بالمنصة مع بعض (Coexistence): بنطلب من Meta تبعت
     * جهات الاتصال (smb_app_state_sync) وآخر 6 شهور محادثات (history) على الـ webhook.
     * لازم تتطلب خلال 24 ساعة من الربط.
     */
    public function syncPhoneApp(string $type): array
    {
        if (!in_array($type, ['smb_app_state_sync', 'history'], true)) {
            throw new InvalidArgumentException('Unknown sync type');
        }
        $r = $this->call('POST', $this->phoneNumberId . '/smb_app_data', ['messaging_product' => 'whatsapp', 'sync_type' => $type]);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }
}
