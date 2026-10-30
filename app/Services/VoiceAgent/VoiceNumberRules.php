<?php

/** Tourfecto - AI Voice Agent: pure rules for phone number purchase and connection (no DB, no network). */
class VoiceNumberRules
{
    public const TYPES = ['local', 'national', 'toll_free', 'mobile'];
    public const METHODS = ['provider_native', 'forward', 'sip', 'port'];
    public const QUOTE_TTL_SECONDS = 900;
    public const DEFAULT_MAX_NUMBERS = 3;

    public static function normalizeE164(string $n): string
    {
        $n = trim($n);
        if (!preg_match('/^\+[0-9\s\-().]{7,20}$/', $n)) {
            throw new InvalidArgumentException('Number must be in international format, e.g. +201001234567');
        }
        $d = preg_replace('/[^0-9]/', '', $n);
        if (strlen($d) < 8 || strlen($d) > 15 || $d[0] === '0') {
            throw new InvalidArgumentException('Invalid phone number');
        }
        return '+' . $d;
    }

    /** What the customer pays up front. Null when the provider published no monthly price (cannot be quoted honestly). */
    public static function firstCharge(?float $monthly, ?float $setup): ?float
    {
        return $monthly === null ? null : round($monthly + ($setup ?? 0.0), 2);
    }

    public static function approvalMatches(?float $quotedTotal, $accepted): bool
    {
        return $quotedTotal !== null && is_numeric($accepted) && abs(round((float) $accepted, 2) - $quotedTotal) < 0.005;
    }

    public static function quoteExpired(int $quotedAt, ?int $now = null): bool
    {
        return (($now ?? time()) - $quotedAt) > self::QUOTE_TTL_SECONDS;
    }

    /**
     * @param string $providerAddressReq what the provider search said ('none', 'any', 'local', 'foreign', 'unknown', ...)
     * @return array{level:string, needs_ack:bool}
     */
    public static function assessRequirements(string $iso, string $providerAddressReq): array
    {
        $level = VoiceMarkets::requirementLevel($iso);
        $p = strtolower($providerAddressReq);
        $needs = $level !== VoiceMarkets::NONE || !in_array($p, ['none', ''], true);
        if ($level === VoiceMarkets::NONE && $p === 'unknown') {
            $needs = true;
        }
        return ['level' => $level, 'needs_ack' => $needs];
    }

    public static function maxNumbers($featureValue): int
    {
        return is_numeric($featureValue) && (int) $featureValue >= 1 ? min(100, (int) $featureValue) : self::DEFAULT_MAX_NUMBERS;
    }

    public static function newVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function hashCode(string $code, int $numberId): string
    {
        return hash_hmac('sha256', $code, 'voice-verify:' . $numberId);
    }

    public static function codeMatches(string $hash, string $code, int $numberId): bool
    {
        return preg_match('/^[0-9]{6}$/', $code) === 1 && hash_equals($hash, self::hashCode($code, $numberId));
    }

    public static function verificationTwiml(string $code): string
    {
        $spoken = implode(' ', str_split($code));
        $say = 'Your Tourfecto verification code is ' . $spoken . '. Again, ' . $spoken . '. Goodbye.';
        return '<Response><Pause length="1"/><Say>' . htmlspecialchars($say, ENT_XML1) . '</Say></Response>';
    }

    /**
     * Customer-facing steps for connecting an existing number. $target = the Tourfecto/provider number or SIP URI calls must reach.
     * @return array{ar:string[], en:string[]}
     */
    public static function connectionSteps(string $method, string $number, ?string $target): array
    {
        switch ($method) {
            case 'provider_native':
                return [
                    'ar' => ["الرقم $number موجود في حسابك لدى المزوّد؛ نتحقق من الملكية تلقائيًا من حسابك.", 'لا حاجة لأي تحويل؛ سيتم ضبط الرقم ليرسل مكالماته إلى Tourfecto.'],
                    'en' => ["$number is in your own provider account; ownership is checked automatically from that account.", 'No forwarding needed; the number will be pointed at Tourfecto.'],
                ];
            case 'forward':
                $t = $target ?: '(a Tourfecto number)';
                return [
                    'ar' => ["أدخل كود التحقق الذي سنتصل بك به على $number.", "من شركة الاتصالات أو السنترال، فعّل تحويل المكالمات (دائم أو عند عدم الرد) من $number إلى $t.", 'اتصل بالرقم من هاتف آخر لاختبار الربط.'],
                    'en' => ["Enter the verification code we call $number with.", "At your carrier or PBX, enable call forwarding from $number to $t.", 'Call the number from another phone to test the link.'],
                ];
            case 'sip':
                $t = $target ?: '(SIP address not configured yet)';
                return [
                    'ar' => ["أدخل كود التحقق الذي سنتصل بك به على $number.", "في السنترال/مزوّد SIP، وجّه مكالمات $number إلى $t.", 'اتصل بالرقم لاختبار الربط.'],
                    'en' => ["Enter the verification code we call $number with.", "In your PBX/SIP provider, route calls for $number to $t.", 'Call the number to test the link.'],
                ];
            case 'port':
                return [
                    'ar' => ['النقل (Porting) يتم عبر المزوّد ويستغرق عادةً أيامًا أو أسابيع حسب الدولة والشركة الحالية.', 'جهّز آخر فاتورة، اسم صاحب الخط المطابق للعقد، ورقم الحساب/PIN إن وُجد.', 'يتواصل معك فريقنا بعد مراجعة الطلب؛ لا يتم النقل تلقائيًا.'],
                    'en' => ['Porting is handled by the provider and usually takes days to weeks depending on country and current carrier.', 'Have your latest bill, the exact account holder name, and the account number/PIN ready.', 'Our team contacts you after reviewing the request; nothing is ported automatically.'],
                ];
        }
        throw new InvalidArgumentException('Unknown connection method');
    }

    /** Real calls may only use a number that is active AND really owned: bought live, or ownership proven live (never by a sandbox code). */
    public static function liveUsable(array $row): bool
    {
        if (($row['status'] ?? '') !== 'active') {
            return false;
        }
        if (($row['source'] ?? '') === 'purchased') {
            return strpos((string) ($row['provider_number_id'] ?? ''), 'SIM-') !== 0 && ($row['provider_number_id'] ?? '') !== '';
        }
        return ($row['verification_mode'] ?? 'none') === 'live';
    }
}
