<?php

/**
 * Tourfecto - Email List Hygiene (تنضيف القوائم قبل الدخول/الإرسال)
 * @version 1.0.0
 *
 * يقيّم عنوان بريد قبل ما يدخل القائمة (أو قبل الحملة) عشان يحمي السمعة:
 *   reject : صيغة غلط / دومين مؤقت (disposable) / دومين بلا MX (لو فُعّل فحص MX)
 *   risky  : حساب وظيفي (info@, admin@, noreply@...) أو دومين غالبًا مكتوب غلط (gmial.com)
 *   ok     : سليم
 * الدوال بلا DB. فحص MX اختياري ويقبل resolver مُحقَن (للاختبار/الكاش):
 *   fn(string $domain): ?bool   true = فيه MX/A، false = مفيش (NXDOMAIN)، null = مش متأكد (timeout)
 * عند null نعتبره سليم (لا نرفض بسبب عطل DNS مؤقت).
 */
class EmailListHygieneService
{
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'sharklasers.com', 'grr.la',
        '10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmailo.com',
        'throwawaymail.com', 'yopmail.com', 'yopmail.net', 'yopmail.fr', 'trashmail.com', 'trashmail.net',
        'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'mintemail.com',
        'fakeinbox.com', 'spamgourmet.com', 'mytemp.email', 'emailondeck.com', 'moakt.com', 'mohmal.com',
        'burnermail.io', 'discard.email', 'tmpmail.org', 'tmpmail.net', 'mail.tm', 'inboxkitten.com',
        'mailcatch.com', 'spambox.us', 'incognitomail.org', 'anonbox.net', 'harakirimail.com', 'jetable.org',
        'mailforspam.com', 'tempinbox.com', 'tempr.email', 'luxusmail.org', 'trbvm.com', 'dropmail.me',
    ];

    private const ROLE_LOCALS = [
        'info', 'admin', 'administrator', 'webmaster', 'postmaster', 'hostmaster', 'abuse', 'noreply', 'no-reply',
        'donotreply', 'do-not-reply', 'support', 'help', 'sales', 'contact', 'office', 'billing', 'accounts',
        'marketing', 'team', 'hello', 'mail', 'root', 'security', 'privacy', 'legal', 'jobs', 'hr',
    ];

    private const TYPO_DOMAINS = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmail.co' => 'gmail.com',
        'gnail.com' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gmai.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'hitmail.com' => 'hotmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yhoo.com' => 'yahoo.com',
        'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'outloook.com' => 'outlook.com',
        'iclod.com' => 'icloud.com', 'icloud.con' => 'icloud.com',
    ];

    /** @var callable|null */
    private $mxResolver;

    public function __construct(?callable $mxResolver = null)
    {
        $this->mxResolver = $mxResolver;
    }

    public static function isDisposableDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if (in_array($domain, self::DISPOSABLE, true)) {
            return true;
        }
        // subdomain of a disposable provider (x.mailinator.com)
        foreach (self::DISPOSABLE as $d) {
            if (substr($domain, -strlen($d) - 1) === '.' . $d) {
                return true;
            }
        }
        return false;
    }

    public static function isRoleAccount(string $local): bool
    {
        return in_array(strtolower($local), self::ROLE_LOCALS, true);
    }

    public static function suggestDomain(string $domain): ?string
    {
        $domain = strtolower($domain);
        return self::TYPO_DOMAINS[$domain] ?? null;
    }

    /**
     * @return array{email:string,verdict:string,reasons:string[],suggestion:?string}
     *   verdict: ok | risky | reject
     */
    public function assess(string $email, bool $checkMx = false): array
    {
        $email = strtolower(trim($email));
        $out = ['email' => $email, 'verdict' => 'ok', 'reasons' => [], 'suggestion' => null];

        $at = strrpos($email, '@');
        if ($at === false || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'verdict' => 'reject', 'reasons' => ['invalid_syntax'], 'suggestion' => null];
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if (strlen($local) > 64 || strpos($local, '..') !== false || strpos($domain, '.') === false) {
            return ['email' => $email, 'verdict' => 'reject', 'reasons' => ['invalid_syntax'], 'suggestion' => null];
        }

        if (self::isDisposableDomain($domain)) {
            return ['email' => $email, 'verdict' => 'reject', 'reasons' => ['disposable_domain'], 'suggestion' => null];
        }

        if ($checkMx) {
            $has = $this->hasMailRecords($domain);
            if ($has === false) {
                return ['email' => $email, 'verdict' => 'reject', 'reasons' => ['no_mx'], 'suggestion' => null];
            }
        }

        $typo = self::suggestDomain($domain);
        if ($typo !== null) {
            $out['verdict'] = 'risky';
            $out['reasons'][] = 'possible_typo';
            $out['suggestion'] = $local . '@' . $typo;
        }
        if (self::isRoleAccount($local)) {
            $out['verdict'] = 'risky';
            $out['reasons'][] = 'role_account';
        }
        return $out;
    }

    private function hasMailRecords(string $domain): ?bool
    {
        if ($this->mxResolver) {
            return call_user_func($this->mxResolver, $domain);
        }
        if (!function_exists('checkdnsrr')) {
            return null;
        }
        $host = rtrim($domain, '.') . '.';
        if (@checkdnsrr($host, 'MX')) {
            return true;
        }
        // RFC 5321: لو مفيش MX يتعامل مع A/AAAA كـ implicit MX
        if (@checkdnsrr($host, 'A') || @checkdnsrr($host, 'AAAA')) {
            return true;
        }
        // مفيش أي سجل: قد يكون NXDOMAIN أو عطل DNS — نرفض فقط لو الدومين نفسه مش بيحل
        return @gethostbyname($domain) === $domain ? false : true;
    }
}
