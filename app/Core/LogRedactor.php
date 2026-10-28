<?php

/**
 * Tourfecto - تنظيف اللوجات من البيانات الحساسة
 *
 * أي حاجة بتتكتب في اللوج بتعدّي من هنا الأول. بيشيل:
 *  - أي مفتاح اسمه فيه password / secret / token / api_key / authorization / cookie ...
 *  - أي قيمة شكلها هاش باسورد (bcrypt / argon2) أو توكن (hex طويل، JWT، مفاتيح sk- / AIza / ya29 ...)
 *    حتى لو جت من غير اسم (زي params بتاعة استعلام SQL فشل).
 *
 * ده اللي كان بيخلي هاش الباسورد والـ api_token يظهروا في app.log لما INSERT
 * في جدول users يفشل.
 */
class LogRedactor
{
    private const MASK = '[REDACTED]';

    /** أسماء مفاتيح حساسة */
    private const KEY_RE = '/(pass(word|wd)?|pwd|secret|token|api[_-]?key|apikey|authorization|cookie|private[_-]?key|recovery[_-]?codes?|(?:^|_)otp(?:_|$)|cvv|card[_-]?number|signature|credential|refresh)/i';

    /** مفاتيح شبهها لكن مش حساسة (تواريخ/عدّادات) */
    private const KEY_SAFE_RE = '/(_at|_count|_id|_expiry|_expires|_type|_used|_length|_status|tokens|_limit)$/i';

    /** قيم شكلها سر حتى من غير اسم */
    private const VALUE_PATTERNS = [
        '/\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}/',                 // bcrypt
        '/\$argon2(?:id|i|d)\$[^\s"\']+/',                       // argon2
        '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', // JWT
        '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/',          // Stripe
        '/\bsk-(?:proj-|ant-)?[A-Za-z0-9_-]{16,}/',               // OpenAI/Anthropic
        '/\bAIza[0-9A-Za-z_-]{30,}/',                             // Google API key
        '/\bya29\.[0-9A-Za-z_-]{20,}/',                           // Google OAuth access token
        '/\b1\/\/0[0-9A-Za-z_-]{20,}/',                           // Google refresh token
        '/\b(?:xox[abprs]|ghp|gho|github_pat)_?-?[A-Za-z0-9_-]{16,}/', // Slack / GitHub
        '/\bEAA[A-Za-z0-9]{40,}/',                                // Meta access token
        '/(?i:bearer)\s+[A-Za-z0-9._~+\/=-]{16,}/',               // Authorization: Bearer
        '/(?<![A-Za-z0-9])[a-f0-9]{40,}(?![A-Za-z0-9])/i',        // hex طويل (api_token / reset tokens)
        '/(?i:(?:password|passwd|pwd|secret|token|api_key)=)[^&\s"]+/', // في query string
    ];

    public static function context(array $context, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['_truncated' => true];
        }
        foreach ($context as $k => $v) {
            if (is_string($k) && self::isSensitiveKey($k) && $v !== null && $v !== '') {
                $context[$k] = self::MASK;
                continue;
            }
            if (is_array($v)) {
                $context[$k] = self::context($v, $depth + 1);
            } elseif (is_object($v)) {
                $context[$k] = self::context(self::objectToArray($v), $depth + 1);
            } elseif (is_string($v)) {
                $context[$k] = self::text($v);
            }
        }
        return $context;
    }

    public static function text(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $out = preg_replace(self::VALUE_PATTERNS, self::MASK, $s);
        return $out === null ? $s : $out;
    }

    public static function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(self::KEY_RE, $key) && !preg_match(self::KEY_SAFE_RE, $key);
    }

    private static function objectToArray($o): array
    {
        if ($o instanceof Throwable) {
            return ['exception' => get_class($o), 'message' => $o->getMessage()];
        }
        if ($o instanceof JsonSerializable) {
            $j = $o->jsonSerialize();
            return is_array($j) ? $j : ['value' => $j];
        }
        if (method_exists($o, 'toArray')) {
            try {
                $a = $o->toArray();
                return is_array($a) ? $a : [];
            } catch (Throwable $e) {
            }
        }
        return ['object' => get_class($o)];
    }

    /**
     * ينظّف ملف لوج موجود (للسطور القديمة اللي اتكتبت قبل الإصلاح).
     * @return int عدد السطور اللي اتعدّلت
     */
    public static function scrubFile(string $path): int
    {
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            return 0;
        }
        $in = fopen($path, 'rb');
        $tmp = $path . '.scrub.' . getmypid();
        $out = fopen($tmp, 'wb');
        if (!$in || !$out) {
            return 0;
        }
        $changed = 0;
        while (($line = fgets($in)) !== false) {
            $clean = self::scrubLine($line);
            if ($clean !== $line) {
                $changed++;
            }
            fwrite($out, $clean);
        }
        fclose($in);
        fclose($out);
        if ($changed > 0) {
            @chmod($tmp, fileperms($path) & 0777);
            rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
        return $changed;
    }

    private static function scrubLine(string $line): string
    {
        // سطور اللوج بتاعتنا: "[time] [level] message {json}"
        $pos = strpos($line, ' {');
        if ($pos !== false) {
            $json = rtrim(substr($line, $pos + 1));
            $data = json_decode($json, true);
            if (is_array($data)) {
                $redacted = self::context($data);
                $head = self::text(substr($line, 0, $pos));
                if ($redacted === $data && $head === substr($line, 0, $pos)) {
                    return $line; // مفيش حاجة حساسة - نسيب السطر زي ما هو
                }
                $clean = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($clean !== false) {
                    return $head . ' ' . $clean . "\n";
                }
            }
        }
        $eol = substr($line, -1) === "\n" ? "\n" : '';
        return self::text(rtrim($line, "\n")) . $eol;
    }
}
