<?php

/**
 * Tourfecto - TOTP Service (RFC 6238)
 * تنفيذ Time-based One-Time Password من غير أي مكتبة خارجية - نفس
 * الخوارزمية اللي بتستخدمها Google Authenticator / Authy / 1Password
 * (HMAC-SHA1، نافذة 30 ثانية، 6 أرقام).
 * @version 1.0.0
 */
class TotpService
{
    private const PERIOD = 30;      // ثانية لكل خطوة زمنية - المعيار الافتراضي
    private const DIGITS = 6;
    private const SECRET_BYTES = 20; // 160-bit، المعيار الموصى به في RFC 6238

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** توليد سر عشوائي جديد بترميز Base32 (قابل للعرض كنص/QR) */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /** حساب الكود الصحيح لسر معيّن في وقت معيّن (افتراضيًا: الآن) */
    public static function getCode(string $secretBase32, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::PERIOD);
        return self::hotp($secretBase32, $counter);
    }

    /**
     * التحقق من كود أدخله المستخدم، مع سماح بفارق ±خطوة واحدة (30 ثانية)
     * تعويضًا عن فرق بسيط في ساعة الموبايل - نفس تسامح كل تطبيقات
     * الـ TOTP المعروفة.
     */
    public static function verify(string $secretBase32, string $code, int $windowSteps = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $now = time();
        for ($i = -$windowSteps; $i <= $windowSteps; $i++) {
            $candidate = self::getCode($secretBase32, $now + ($i * self::PERIOD));
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }
        return false;
    }

    /** رابط otpauth:// عشان تطبيقات الـ Authenticator تقرأه كـ QR */
    public static function provisioningUri(string $secretBase32, string $accountEmail, string $issuer = 'Tourfecto'): string
    {
        $label = rawurlencode($issuer . ':' . $accountEmail);
        $params = http_build_query([
            'secret' => $secretBase32,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /** توليد أكواد طوارئ عشوائية (استخدام واحد لكل كود) - نص خام + hash */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // شكل قابل للقراءة والنسخ اليدوي: XXXX-XXXX
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    /** hash قائمة أكواد طوارئ للتخزين (نفس مبدأ password_hash - النص الخام ميتخزنش أبدًا) */
    public static function hashRecoveryCodes(array $rawCodes): array
    {
        return array_map(fn ($c) => password_hash($c, PASSWORD_DEFAULT), $rawCodes);
    }

    /**
     * التحقق من كود طوارئ مقابل قائمة hashes مخزّنة، وإرجاع index الكود
     * اللي اتطابق (عشان يتشال من القائمة - كل كود استخدام واحد بس)،
     * أو null لو مفيش تطابق.
     */
    public static function verifyRecoveryCode(array $hashedCodes, string $rawCode): ?int
    {
        $rawCode = strtoupper(trim($rawCode));
        foreach ($hashedCodes as $i => $hash) {
            if (password_verify($rawCode, $hash)) {
                return $i;
            }
        }
        return null;
    }

    // ============ HOTP / Base32 - تفاصيل داخلية ============

    private static function hotp(string $secretBase32, int $counter): string
    {
        $key = self::base32Decode($secretBase32);
        $counterBytes = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter

        $hash = hash_hmac('sha1', $counterBytes, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $code = $truncated % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $binaryString = '';
        foreach (str_split($data) as $byte) {
            $binaryString .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($binaryString, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }
        return $output;
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $data = strtoupper(rtrim($data, '='));

        $binaryString = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue; // نتجاهل أي حرف غير صالح بدل ما نفشل بالكامل
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($binaryString, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }
        return $output;
    }
}
