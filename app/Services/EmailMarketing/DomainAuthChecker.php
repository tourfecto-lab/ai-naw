<?php

/**
 * Tourfecto - Domain Authentication Checker (SPF / DKIM / DMARC)
 * @version 1.0.0
 *
 * فحص DNS لدومين الإرسال مع إرشادات عربية. مطلوب من Gmail/Yahoo للإرسال الجماعي.
 * الـ resolver مُحقَن للاختبار: fn(string $name): string[]  (نصوص TXT كاملة، أو [] لو مفيش).
 * الافتراضي dns_get_record(TXT).
 *
 * check() يرجع: ['domain', 'score' 0-100, 'spf'=>[...], 'dkim'=>[...], 'dmarc'=>[...], 'recommendations'=>[]]
 * كل قسم: ['status' => pass|warn|fail, 'detail' => ...]
 */
class DomainAuthChecker
{
    public const DEFAULT_SELECTORS = ['default', 'google', 'selector1', 'selector2', 's1', 's2', 'k1', 'mail', 'smtp', 'dkim'];

    /** @var callable */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?: [self::class, 'dnsTxt'];
    }

    public static function dnsTxt(string $name): array
    {
        $rows = @dns_get_record($name, DNS_TXT);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (isset($r['txt'])) {
                $out[] = (string) $r['txt'];
            } elseif (!empty($r['entries'])) {
                $out[] = implode('', $r['entries']);
            }
        }
        return $out;
    }

    public static function isValidDomain(string $d): bool
    {
        return (bool) preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', strtolower($d));
    }

    public function check(string $domain, array $selectors = []): array
    {
        $domain = strtolower(trim($domain));
        if (!self::isValidDomain($domain)) {
            return ['domain' => $domain, 'error' => 'دومين غير صالح'];
        }
        $selectors = $selectors ?: self::DEFAULT_SELECTORS;

        $spf = $this->checkSpf($domain);
        $dkim = $this->checkDkim($domain, $selectors);
        $dmarc = $this->checkDmarc($domain);

        $points = ['pass' => 1.0, 'warn' => 0.5, 'fail' => 0.0];
        $score = (int) round(($points[$spf['status']] * 35 + $points[$dkim['status']] * 35 + $points[$dmarc['status']] * 30));

        $rec = [];
        foreach (['spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc] as $r) {
            foreach ($r['recommendations'] as $x) {
                $rec[] = $x;
            }
        }
        return ['domain' => $domain, 'score' => $score, 'spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'recommendations' => $rec];
    }

    public function checkSpf(string $domain): array
    {
        $records = array_values(array_filter($this->txt($domain), fn ($t) => preg_match('/^\s*v=spf1(\s|$)/i', $t)));
        if (count($records) === 0) {
            return ['status' => 'fail', 'record' => null, 'detail' => 'لا يوجد سجل SPF',
                'recommendations' => ["أضف سجل TXT على {$domain}: \"v=spf1 include:<مزود الإرسال> ~all\" (استبدل المزود بقيمة مزودك، مثلًا include:_spf.google.com)."]];
        }
        if (count($records) > 1) {
            return ['status' => 'fail', 'record' => $records, 'detail' => 'أكثر من سجل SPF (يبطّل SPF بالكامل)',
                'recommendations' => ["ادمج سجلات SPF في سجل واحد فقط على {$domain}."]];
        }
        $rec = trim($records[0]);
        $rc = [];
        $status = 'pass';
        $detail = 'سجل SPF سليم';

        if (!preg_match('/\s([+\-~?]?)all\b/i', $rec, $m)) {
            $status = 'warn';
            $detail = 'السجل بلا آلية all في آخره';
            $rc[] = 'أنهِ سجل SPF بـ ~all أو -all.';
        } else {
            $q = $m[1] === '' ? '+' : $m[1];
            if ($q === '+' || $q === '?') {
                $status = 'fail';
                $detail = "الآلية {$q}all تسمح لأي سيرفر بالإرسال باسمك";
                $rc[] = 'غيّر ' . $q . 'all إلى ~all (أو -all بعد التأكد من كل المرسلين).';
            }
        }
        $lookups = preg_match_all('/(?:^|\s)[+\-~?]?(include:|a(?::|\/|\s|$)|mx(?::|\/|\s|$)|ptr|exists:|redirect=)/i', $rec);
        if ($lookups > 10) {
            $status = $status === 'fail' ? 'fail' : 'warn';
            $detail = "عدد الـ DNS lookups ≈ {$lookups} (الحد 10)";
            $rc[] = 'قلّل include/a/mx في SPF لأقل من 10 lookups (أو استخدم SPF flattening).';
        }
        if (preg_match('/(?:^|\s)\+?ptr\b/i', $rec)) {
            $status = $status === 'fail' ? 'fail' : 'warn';
            $rc[] = 'آلية ptr مهجورة، احذفها.';
        }
        return ['status' => $status, 'record' => $rec, 'lookups' => $lookups, 'detail' => $detail, 'recommendations' => $rc];
    }

    public function checkDkim(string $domain, array $selectors): array
    {
        $found = [];
        foreach ($selectors as $sel) {
            $sel = strtolower(trim((string) $sel));
            if (!preg_match('/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/', $sel)) {
                continue;
            }
            foreach ($this->txt($sel . '._domainkey.' . $domain) as $t) {
                if (preg_match('/v=DKIM1/i', $t) || preg_match('/(^|;)\s*p=/i', $t)) {
                    $revoked = (bool) preg_match('/(^|;)\s*p=\s*(;|$)/', $t);
                    $found[$sel] = $revoked ? 'revoked' : 'valid';
                }
            }
        }
        $valid = array_keys(array_filter($found, fn ($s) => $s === 'valid'));
        if ($valid) {
            return ['status' => 'pass', 'selectors' => $valid, 'detail' => 'DKIM موجود (selector: ' . implode(', ', $valid) . ')', 'recommendations' => []];
        }
        if ($found) {
            return ['status' => 'fail', 'selectors' => [], 'detail' => 'مفتاح DKIM ملغى (p فاضي)',
                'recommendations' => ['فعّل مفتاح DKIM جديد من مزود الإرسال وانشره في DNS.']];
        }
        return ['status' => 'fail', 'selectors' => [], 'detail' => 'لم يُعثر على DKIM بالـ selectors الشائعة',
            'recommendations' => ['فعّل DKIM من مزود الإرسال وانشر سجل TXT على <selector>._domainkey.' . $domain . ' — لو منشور بالفعل، مرّر اسم الـ selector في الفحص.']];
    }

    public function checkDmarc(string $domain): array
    {
        $records = array_values(array_filter($this->txt('_dmarc.' . $domain), fn ($t) => preg_match('/^\s*v=DMARC1\s*(;|$)/i', $t)));
        if (!$records) {
            return ['status' => 'fail', 'record' => null, 'policy' => null, 'detail' => 'لا يوجد سجل DMARC',
                'recommendations' => ["أضف TXT على _dmarc.{$domain}: \"v=DMARC1; p=none; rua=mailto:dmarc@{$domain}\" وبعد المراقبة ارفع السياسة إلى quarantine ثم reject."]];
        }
        $rec = trim($records[0]);
        $tags = [];
        foreach (explode(';', $rec) as $part) {
            if (strpos($part, '=') !== false) {
                [$k, $v] = array_map('trim', explode('=', $part, 2));
                $tags[strtolower($k)] = $v;
            }
        }
        $policy = strtolower($tags['p'] ?? '');
        $rc = [];
        $status = 'pass';
        $detail = "DMARC بسياسة {$policy}";
        if (!in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            return ['status' => 'fail', 'record' => $rec, 'policy' => null, 'detail' => 'سجل DMARC بلا سياسة p صالحة',
                'recommendations' => ['أضف p=none (أو quarantine/reject) لسجل DMARC.']];
        }
        if ($policy === 'none') {
            $status = 'warn';
            $rc[] = 'السياسة none للمراقبة فقط؛ بعد التأكد من SPF/DKIM ارفعها إلى quarantine ثم reject.';
        }
        if (empty($tags['rua'])) {
            $status = $status === 'pass' ? 'warn' : $status;
            $rc[] = 'أضف rua=mailto:... لاستلام تقارير DMARC.';
        }
        if (isset($tags['pct']) && (int) $tags['pct'] < 100 && $policy !== 'none') {
            $rc[] = 'pct أقل من 100: السياسة مطبقة على جزء من الرسائل فقط.';
        }
        return ['status' => $status, 'record' => $rec, 'policy' => $policy, 'detail' => $detail, 'recommendations' => $rc];
    }

    private function txt(string $name): array
    {
        try {
            return array_map('strval', (array) call_user_func($this->resolver, $name));
        } catch (\Throwable $e) {
            return [];
        }
    }
}
