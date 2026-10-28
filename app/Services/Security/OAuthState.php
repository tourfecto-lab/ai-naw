<?php

/**
 * Tourfecto - OAuthState
 * "state" موقّع (HMAC) لربط حسابات OAuth (Google Business / GA4 / Search Console / Ads / TikTok / YouTube).
 *
 * المشكلة: التحقق كان معتمد بالكامل على nonce محفوظ في جلسة PHP. لو كوكي الجلسة ما رجعتش
 * مع الرجوع من Google (جلسة اتجددت، تاب تاني، كاش، متصفح بيحجب الكوكي...) العميل بيفضل مسجّل
 * دخول بالـ auth_token لكن الـ nonce بيضيع => "انتهت صلاحية الجلسة أو محاولة غير موثوقة" دايمًا.
 *
 * الحل: الـ state نفسه بيحمل البيانات + رقم المستخدم + وقت انتهاء، وموقّع بمفتاح السيرفر.
 * عند الرجوع: التوقيع لازم يكون سليم، ولسه ما انتهاش (20 دقيقة)، ولنفس المستخدم المسجّل دخول
 * => نفس حماية الـ CSRF من غير الاعتماد على الجلسة. الصيغة القديمة لسه مقبولة لو الجلسة موجودة.
 */
class OAuthState
{
    private const TTL = 1200;

    private static function key(): string
    {
        foreach (['ENCRYPTION_KEY', 'APP_KEY', 'JWT_SECRET'] as $k) {
            $v = function_exists('env') ? env($k) : getenv($k);
            if (is_string($v) && strlen($v) >= 16) {
                return $v;
            }
        }
        $dir = self::stateDirectory();
        $file = $dir . '/signing.key';
        $handle = fopen($file, 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new RuntimeException('OAuth key storage unavailable'); }
        try {
            $key = stream_get_contents($handle);
            if ($key === '') {
                $key = bin2hex(random_bytes(32));
                if (fwrite($handle, $key) !== strlen($key) || !fflush($handle)) { throw new RuntimeException('OAuth key write failed'); }
                chmod($file, 0600);
            }
            if (strlen($key) < 32) { throw new RuntimeException('Invalid OAuth key'); }
            return $key;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private static function stateDirectory(): string
    {
        $base = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : dirname(__DIR__, 3) . '/storage';
        $dir = $base . '/oauth-state';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('OAuth state storage unavailable'); }
        // Apache shared hosting: never serve signing keys or consumed state markers.
        if (!is_file($dir . '/.htaccess') && file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n") === false) { throw new RuntimeException('OAuth storage protection failed'); }
        return $dir;
    }

    private static function consume(string $state): bool
    {
        $file = self::stateDirectory() . '/' . hash('sha256', $state) . '.used';
        $handle = @fopen($file, 'x');
        if (!$handle) { return false; }
        fclose($handle);
        chmod($file, 0600);
        // Bounded opportunistic cleanup; expiry validation happens before this call.
        if (random_int(1, 100) === 1) {
            foreach (array_slice(glob(self::stateDirectory() . '/*.used') ?: [], 0, 500) as $old) {
                if (filemtime($old) < time() - self::TTL - 60) { @unlink($old); }
            }
        }
        return true;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s)
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function issue(array $payload, int $userId): string
    {
        $payload['nonce'] = bin2hex(random_bytes(24));
        $payload['uid'] = $userId;
        $payload['exp'] = time() + self::TTL;
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, self::key(), true));
    }

    /** @return array|null البيانات لو الـ state سليم، أو null */
    public static function verify(string $state, int $userId, ?string $sessionNonce = null, ?string $audience = null): ?array
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }

        if (strpos($state, '.') !== false) {
            [$body, $sig] = explode('.', $state, 2);
            $expected = self::b64(hash_hmac('sha256', $body, self::key(), true));
            if (!hash_equals($expected, $sig)) {
                return null;
            }
            $data = json_decode((string) self::unb64($body), true);
            if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
                return null;
            }
            if ($userId <= 0 || (int) ($data['uid'] ?? 0) !== $userId) {
                return null;
            }
            if ($audience !== null && ($data['aud'] ?? '') !== $audience) { return null; }
            return self::consume($state) ? $data : null;
        }

        // Unsigned legacy challenges must be reissued after upgrade.
        return null;
    }
}
