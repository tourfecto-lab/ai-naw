<?php

/**
 * Tourfecto - العملات
 *
 * كل الأسعار والمحفظة متسجلة بالدولار (USD) زي ما هي. العملات التانية للعرض
 * والدفع بس: السعر بيتحسب = السعر بالدولار × سعر الصرف (+ هامش اختياري).
 *
 * سعر الصرف بيتحط من الأدمن (يدوي - مثلًا سعر البنك) أو بيتحدّث من السوق،
 * والسعر الثابت بيكسب دايمًا لو الأدمن قفله.
 */
class CurrencyService
{
    /** العملات المدعومة (نفس اللي XPay بيقبلها + الخليج) */
    public const CURRENCIES = [
        'USD' => ['ar' => 'دولار', 'en' => 'US Dollar', 'symbol' => '$', 'ar_symbol' => '$', 'decimals' => 0, 'fallback' => 1.0],
        'EGP' => ['ar' => 'جنيه مصري', 'en' => 'Egyptian Pound', 'symbol' => 'EGP', 'ar_symbol' => 'ج.م', 'decimals' => 0, 'fallback' => 50.0],
        'SAR' => ['ar' => 'ريال سعودي', 'en' => 'Saudi Riyal', 'symbol' => 'SAR', 'ar_symbol' => 'ر.س', 'decimals' => 0, 'fallback' => 3.75],
        'AED' => ['ar' => 'درهم إماراتي', 'en' => 'UAE Dirham', 'symbol' => 'AED', 'ar_symbol' => 'د.إ', 'decimals' => 0, 'fallback' => 3.6725],
        'KWD' => ['ar' => 'دينار كويتي', 'en' => 'Kuwaiti Dinar', 'symbol' => 'KWD', 'ar_symbol' => 'د.ك', 'decimals' => 1, 'fallback' => 0.307],
        'QAR' => ['ar' => 'ريال قطري', 'en' => 'Qatari Riyal', 'symbol' => 'QAR', 'ar_symbol' => 'ر.ق', 'decimals' => 0, 'fallback' => 3.64],
        'EUR' => ['ar' => 'يورو', 'en' => 'Euro', 'symbol' => '€', 'ar_symbol' => '€', 'decimals' => 0, 'fallback' => 0.92],
        'GBP' => ['ar' => 'جنيه إسترليني', 'en' => 'British Pound', 'symbol' => '£', 'ar_symbol' => '£', 'decimals' => 0, 'fallback' => 0.79],
    ];
    /** البلد => العملة الافتراضية */
    private const COUNTRY = ['EG' => 'EGP', 'SA' => 'SAR', 'AE' => 'AED', 'KW' => 'KWD', 'QA' => 'QAR', 'GB' => 'GBP', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'IE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR'];
    public const COOKIE = 'tf_currency';

    private static ?array $cache = null;

    private static function settings(): ?SystemSettingsService
    {
        try {
            return class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function valid(string $code): bool
    {
        return isset(self::CURRENCIES[$code]);
    }

    /**
     * أسعار الصرف (عدد وحدات العملة مقابل 1 دولار) بعد الهامش.
     * @return array<string, array{rate:float, base:float, margin:float, source:string, updated_at:string, locked:bool, enabled:bool}>
     */
    public static function rates(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $s = self::settings();
        $saved = $s ? json_decode($s->get('fx_rates', ''), true) : null;
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::CURRENCIES as $code => $meta) {
            $r = $saved[$code] ?? [];
            $base = isset($r['base']) && is_numeric($r['base']) && $r['base'] > 0 ? (float) $r['base'] : $meta['fallback'];
            $margin = isset($r['margin']) && is_numeric($r['margin']) ? max(0.0, min(20.0, (float) $r['margin'])) : 0.0;
            $out[$code] = [
                'rate' => $code === 'USD' ? 1.0 : round($base * (1 + $margin / 100), 6),
                'base' => $code === 'USD' ? 1.0 : $base,
                'margin' => $code === 'USD' ? 0.0 : $margin,
                'source' => $code === 'USD' ? 'base' : (string) ($r['source'] ?? 'default'),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
                'locked' => !empty($r['locked']),
                'enabled' => $code === 'USD' || $code === 'EGP' || !isset($r['enabled']) || !empty($r['enabled']),
            ];
        }
        return self::$cache = $out;
    }

    public static function rate(string $code): float
    {
        return self::rates()[$code]['rate'] ?? 1.0;
    }

    /** دولار => عملة */
    public static function fromUsd(float $usd, string $code): float
    {
        $c = self::CURRENCIES[$code] ?? self::CURRENCIES['USD'];
        $v = $usd * self::rate($code);
        // الأسعار الكبيرة بتتقرب لأقرب رقم مقبول (مثلًا 2,450 مش 2,447.35)
        if ($c['decimals'] === 0 && $v >= 100) {
            return round($v / 5) * 5;
        }
        return round($v, $c['decimals'] === 0 ? 0 : $c['decimals']);
    }

    /** عملة => دولار (للدفع: المبلغ اللي اتدفع بالجنيه يتسجل في المحفظة بالدولار) */
    public static function toUsd(float $amount, string $code): float
    {
        $r = self::rate($code);
        return $r > 0 ? round($amount / $r, 2) : 0.0;
    }

    public static function format(float $usd, string $code, bool $arabic = true): string
    {
        $c = self::CURRENCIES[$code] ?? self::CURRENCIES['USD'];
        $v = self::fromUsd($usd, $code);
        $num = number_format($v, $v == floor($v) ? 0 : ($c['decimals'] ?: 2));
        if ($code === 'USD') {
            return '$' . $num;
        }
        // «ج.م» و«ر.س» للواجهة العربي بس - العميل الأجنبي يشوف EGP / SAR
        $arabic = $arabic && (!function_exists('current_lang') || current_lang() === 'ar');
        return $arabic ? $num . ' ' . $c['ar_symbol'] : $c['symbol'] . ' ' . $num;
    }

    /** عملة الزائر: اختياره (كوكي/حسابه) ← بلده ← دولار */
    /**
     * عملة العرض: المنصة موجّهة للأجانب، فالدولار هو الأساس للكل.
     * العملة بتتغير بس لو الزائر اختارها بنفسه (الكوكي)، أو لو الأدمن فعّل
     * "العملة المحلية تلقائي حسب البلد".
     * (عملة حساب العميل في الإعدادات دي عملة حجوزاته هو، مش عملة اشتراكه في المنصة)
     */
    public static function forViewer(?array $user = null): string
    {
        $c = strtoupper((string) ($_COOKIE[self::COOKIE] ?? ''));
        if (self::valid($c) && self::rates()[$c]['enabled']) {
            return $c;
        }
        $s = self::settings();
        if ($s && $s->get('fx_auto_local', '0') === '1') {
            $country = strtoupper((string) ($user['country_code'] ?? $_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
            if (isset(self::COUNTRY[$country]) && self::rates()[self::COUNTRY[$country]]['enabled']) {
                return self::COUNTRY[$country];
            }
        }
        return 'USD';
    }

    /** السعر بالجنيه المصري جنب الدولار (شرط بنك الدفع المحلي) - فاضي لو الجنيه مقفول */
    public static function egpHint(float $usd, bool $arabic = true): string
    {
        if (!self::rates()['EGP']['enabled'] || $usd <= 0) {
            return '';
        }
        return self::format($usd, 'EGP', $arabic);
    }

    /** للواجهة: العملات المتاحة وأسعارها */
    public static function publicList(bool $arabic = true): array
    {
        $out = [];
        foreach (self::rates() as $code => $r) {
            if (!$r['enabled']) {
                continue;
            }
            $m = self::CURRENCIES[$code];
            $out[] = ['code' => $code, 'name' => $arabic ? $m['ar'] : $m['en'], 'symbol' => $arabic ? $m['ar_symbol'] : $m['symbol'], 'rate' => $r['rate'], 'decimals' => $m['decimals']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ الأدمن

    /** @param array<string, array{base?:float, margin?:float, locked?:bool, enabled?:bool}> $in */
    public static function saveRates(array $in): array
    {
        $s = self::settings();
        if (!$s) {
            throw new RuntimeException('Settings unavailable');
        }
        $saved = json_decode($s->get('fx_rates', ''), true);
        $saved = is_array($saved) ? $saved : [];
        foreach ($in as $code => $r) {
            if (!self::valid((string) $code) || $code === 'USD' || !is_array($r)) {
                continue;
            }
            $cur = $saved[$code] ?? [];
            if (isset($r['base'])) {
                if (!is_numeric($r['base']) || $r['base'] <= 0 || $r['base'] > 100000) {
                    throw new InvalidArgumentException('سعر صرف غير صالح لـ ' . $code);
                }
                if ((float) $r['base'] !== (float) ($cur['base'] ?? 0)) {
                    $cur['base'] = (float) $r['base'];
                    $cur['source'] = 'manual';
                    $cur['updated_at'] = date('Y-m-d H:i');
                }
            }
            if (isset($r['margin'])) {
                $cur['margin'] = max(0.0, min(20.0, (float) $r['margin']));
            }
            if (array_key_exists('locked', $r)) {
                $cur['locked'] = !empty($r['locked']);
            }
            if (array_key_exists('enabled', $r)) {
                $cur['enabled'] = !empty($r['enabled']);
            }
            $saved[$code] = $cur;
        }
        $s->set('fx_rates', json_encode($saved));
        self::$cache = null;
        return self::rates();
    }

    /**
     * تحديث من السوق (العملات اللي مش مقفولة بس).
     * @param callable|null $fetch للاختبار: fn():?array{code=>rate}
     */
    public static function refreshFromMarket(?callable $fetch = null): array
    {
        $fetch ??= static function (): ?array {
            $r = SafeHttp::exec('https://open.er-api.com/v6/latest/USD', [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true], 3);
            $d = is_string($r['response']) ? json_decode($r['response'], true) : null;
            return is_array($d['rates'] ?? null) ? $d['rates'] : null;
        };
        $market = $fetch();
        if (!$market) {
            throw new RuntimeException('مقدرناش نجيب أسعار الصرف دلوقتي');
        }
        $s = self::settings();
        $saved = json_decode($s->get('fx_rates', ''), true);
        $saved = is_array($saved) ? $saved : [];
        $updated = [];
        foreach (array_keys(self::CURRENCIES) as $code) {
            if ($code === 'USD' || !empty($saved[$code]['locked']) || empty($market[$code]) || !is_numeric($market[$code])) {
                continue;
            }
            $saved[$code] = array_merge($saved[$code] ?? [], ['base' => round((float) $market[$code], 4), 'source' => 'market', 'updated_at' => date('Y-m-d H:i')]);
            $updated[] = $code;
        }
        $s->set('fx_rates', json_encode($saved));
        self::$cache = null;
        return $updated;
    }

    // ------------------------------------------------------------------ الربط بسعر البنك

    /** صفحة أسعار البنك المركزي المصري (الجنيه مقابل كل عملة: شراء / بيع) */
    public const CBE_URL = 'https://www.cbe.org.eg/en/economic-research/statistics/cbe-exchange-rates';
    /** اسم العملة في جدول البنك المركزي */
    private const CBE_NAMES = ['USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'Pound Sterling', 'SAR' => 'Saudi Riyal', 'AED' => 'UAE Dirham', 'KWD' => 'Kuwaiti Dinar', 'QAR' => 'Qatari Riyal'];

    /** إعدادات الربط التلقائي */
    public static function autoConfig(): array
    {
        $s = self::settings();
        $c = $s ? json_decode($s->get('fx_auto', ''), true) : null;
        $c = is_array($c) ? $c : [];
        return [
            'enabled' => !empty($c['enabled']),
            'source' => in_array($c['source'] ?? 'cbe', ['cbe', 'market'], true) ? ($c['source'] ?? 'cbe') : 'cbe',
            'side' => in_array($c['side'] ?? 'sell', ['sell', 'buy', 'mid'], true) ? ($c['side'] ?? 'sell') : 'sell',
            'every' => in_array((int) ($c['every'] ?? 15), [5, 15, 30, 60, 180], true) ? (int) ($c['every'] ?? 15) : 15,
            'last_run' => (string) ($c['last_run'] ?? ''),
            'last_source' => (string) ($c['last_source'] ?? ''),
            'last_error' => (string) ($c['last_error'] ?? ''),
        ];
    }

    public static function saveAutoConfig(array $in): array
    {
        $s = self::settings();
        $c = $s ? json_decode($s->get('fx_auto', ''), true) : null;
        $c = is_array($c) ? $c : [];
        foreach (['enabled', 'source', 'side', 'every'] as $k) {
            if (array_key_exists($k, $in)) {
                $c[$k] = $k === 'enabled' ? !empty($in[$k]) : $in[$k];
            }
        }
        $s->set('fx_auto', json_encode($c));
        // الربط التلقائي معناه إن الجنيه مايبقاش مقفول على رقم يدوي
        if (!empty($c['enabled'])) {
            $saved = json_decode($s->get('fx_rates', ''), true) ?: [];
            if (!empty($saved['EGP']['locked'])) {
                $saved['EGP']['locked'] = false;
                $s->set('fx_rates', json_encode($saved));
                self::$cache = null;
            }
        }
        return self::autoConfig();
    }

    /**
     * يقرا جدول البنك المركزي: [code => ['buy'=>, 'sell'=>]] بالجنيه لكل وحدة.
     * الصفحة HTML، فبنحوّلها لنص ونقرا أول رقمين بعد اسم كل عملة.
     */
    public static function parseCbe(string $html): array
    {
        // كل تاج بيتحوّل لمسافة (عشان خانات الجدول ماتلزقش في بعض)
        $text = preg_replace('/<[^>]*>/', ' ', preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
        $out = [];
        foreach (self::CBE_NAMES as $code => $name) {
            if (preg_match('/' . preg_quote($name, '/') . '\s*(?:\([A-Z]{3}\))?\s*([0-9]{1,4}\.[0-9]{2,6})\s+([0-9]{1,4}\.[0-9]{2,6})/i', $text, $m)) {
                $a = (float) $m[1];
                $b = (float) $m[2];
                if ($a > 0 && $b > 0) {
                    $out[$code] = ['buy' => min($a, $b), 'sell' => max($a, $b)];
                }
            }
        }
        return $out;
    }

    /** عدد وحدات كل عملة مقابل 1 دولار من جدول البنك (الجنيه مباشر، والباقي محسوب من الجنيه) */
    public static function ratesFromCbe(array $table, string $side = 'sell'): array
    {
        $pick = fn ($r) => $side === 'mid' ? ($r['buy'] + $r['sell']) / 2 : $r[$side];
        if (empty($table['USD'])) {
            return [];
        }
        $usdEgp = $pick($table['USD']);
        $out = ['EGP' => round($usdEgp, 4)];
        foreach ($table as $code => $r) {
            if ($code !== 'USD' && isset(self::CURRENCIES[$code]) && $pick($r) > 0) {
                $out[$code] = round($usdEgp / $pick($r), 6);
            }
        }
        return $out;
    }

    /**
     * التحديث التلقائي (الكرون كل دقيقة بيندهها، وهي بتشتغل بس لما ييجي الميعاد).
     * البنك المركزي الأول، ولو موقعه مردّش: سعر السوق. والسعر الغريب (فرق كبير مرة واحدة) مابيتسجلش.
     * @param callable|null $fetch للاختبار: fn(string $source): ?string|array
     */
    public static function autoRefresh(bool $force = false, ?callable $fetch = null): array
    {
        $cfg = self::autoConfig();
        if (!$force && (!$cfg['enabled'] || ($cfg['last_run'] !== '' && strtotime($cfg['last_run']) > time() - $cfg['every'] * 60 + 30))) {
            return ['ran' => false];
        }
        $fetch ??= static function (string $source) {
            if ($source === 'cbe') {
                $r = SafeHttp::exec(self::CBE_URL, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (compatible; TourfectoRates/1.0)', 'Accept: text/html']], 3);
                return is_string($r['response'] ?? null) ? $r['response'] : null;
            }
            $r = SafeHttp::exec('https://open.er-api.com/v6/latest/USD', [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true], 3);
            $d = is_string($r['response'] ?? null) ? json_decode($r['response'], true) : null;
            return is_array($d['rates'] ?? null) ? $d['rates'] : null;
        };
        $order = $cfg['source'] === 'cbe' ? ['cbe', 'market'] : ['market'];
        $rates = [];
        $used = '';
        $errors = [];
        foreach ($order as $src) {
            try {
                $raw = $fetch($src);
                $rates = $src === 'cbe' ? (is_string($raw) ? self::ratesFromCbe(self::parseCbe($raw), $cfg['side']) : []) : (is_array($raw) ? $raw : []);
            } catch (Throwable $e) {
                $rates = [];
            }
            if (!empty($rates['EGP'])) {
                $used = $src;
                break;
            }
            $errors[] = $src === 'cbe' ? 'موقع البنك المركزي مردّش أو شكل الجدول اتغيّر' : 'سعر السوق مش متاح';
        }
        $s = self::settings();
        $c = json_decode($s->get('fx_auto', ''), true) ?: [];
        $c['last_run'] = date('Y-m-d H:i:s');
        if ($used === '') {
            $c['last_error'] = implode(' / ', $errors);
            $s->set('fx_auto', json_encode($c));
            return ['ran' => true, 'updated' => [], 'error' => $c['last_error']];
        }
        $saved = json_decode($s->get('fx_rates', ''), true) ?: [];
        $current = self::rates();
        $updated = [];
        $skipped = [];
        foreach ($rates as $code => $v) {
            if ($code === 'USD' || !isset(self::CURRENCIES[$code]) || !is_numeric($v) || $v <= 0 || !empty($saved[$code]['locked'])) {
                continue;
            }
            $v = (float) $v;
            $old = (float) ($current[$code]['base'] ?? 0);
            // حماية: لو الرقم اتغيّر أكتر من 15% مرة واحدة غالبًا قراية غلط - مانسجلوش
            if ($old > 0 && ($current[$code]['source'] ?? '') !== 'default' && abs($v - $old) / $old > 0.15 && !$force) {
                $skipped[] = $code;
                continue;
            }
            $saved[$code] = array_merge($saved[$code] ?? [], ['base' => round($v, $code === 'EGP' ? 4 : 6), 'source' => $used === 'cbe' ? 'cbe' : 'market', 'updated_at' => date('Y-m-d H:i')]);
            $updated[] = $code;
        }
        $s->set('fx_rates', json_encode($saved));
        self::$cache = null;
        $c['last_source'] = $used;
        $c['last_error'] = ($used !== $cfg['source'] ? implode(' / ', $errors) . ' - استخدمنا سعر السوق مؤقتًا. ' : '') . ($skipped ? 'اتجاهلنا تغيير كبير مفاجئ في: ' . implode('، ', $skipped) : '');
        $s->set('fx_auto', json_encode($c));
        return ['ran' => true, 'updated' => $updated, 'source' => $used, 'skipped' => $skipped];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
