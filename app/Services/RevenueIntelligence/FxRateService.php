<?php

/**
 * Tourfecto - Revenue Intelligence FX Rate Service
 * @version 2.0.0
 *
 * توحيد الإيرادات متعددة العملات في عملة تقارير واحدة (base currency).
 *
 * قبل الإصدار ده: أي فترة فيها أكتر من عملة كانت بترجع "عملات مختلطة"
 * وكل التحليلات (Forecast/Anomaly/Sources...) بتفضى. دلوقتي لو أسعار
 * الصرف متاحة لكل العملات الموجودة، الإيراد يتحوّل لعملة التقارير
 * ويشتغل كل شيء طبيعي. لو عملة واحدة ناقصة سعرها -> نرجع لنفس السلوك
 * القديم الآمن (لا نجمع أرقام بعملات مختلفة أبدًا = لا أرقام مخترعة).
 *
 * مصادر الأسعار (الأعلى أولوية آخرًا):
 *   1) CurrencyService (أسعار المنصة اللي الأدمن بيحدثها من البنك المركزي)
 *   2) revai_fx_rates user_id=0 (أسعار سوق يومية من cron/revai_fx_rates_refresh.php)
 *   3) revai_fx_rates user_id=N (سعر يدوي يحدده صاحب الحساب - الأولوية الأعلى)
 *
 * التحويل يستخدم أحدث سعر متاح (وليس سعر يوم كل معاملة) ويُفصح عن ذلك
 * صراحة في المخرجات (fx_basis='latest').
 */
class FxRateService
{
    /** @var array<int, array<string, float>> كاش داخل نفس الطلب */
    private static $memo = [];

    /**
     * يحوّل مبلغ بين عملتين باستخدام خريطة units_per_usd. Pure.
     * @param array<string, float> $unitsPerUsd ['USD'=>1,'EUR'=>0.92,'EGP'=>48.5]
     */
    public static function convert(float $amount, string $from, string $to, array $unitsPerUsd): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === $to) {
            return $amount;
        }
        $rFrom = $from === 'USD' ? 1.0 : ($unitsPerUsd[$from] ?? null);
        $rTo = $to === 'USD' ? 1.0 : ($unitsPerUsd[$to] ?? null);
        if (!$rFrom || !$rTo || $rFrom <= 0 || $rTo <= 0) {
            return null;
        }
        return ($amount / $rFrom) * $rTo;
    }

    /**
     * معامل التحويل (اضرب فيه المبلغ) من كل عملة إلى العملة الأساسية.
     * يرجع null لو أي عملة من المطلوبة مالهاش سعر (fail-closed). Pure.
     *
     * @param string[] $currencies
     * @return array<string, float>|null
     */
    public static function factorsTo(string $base, array $currencies, array $unitsPerUsd): ?array
    {
        $factors = [];
        foreach (array_unique(array_map('strtoupper', $currencies)) as $cur) {
            if ($cur === '') {
                return null;
            }
            $f = self::convert(1.0, $cur, $base, $unitsPerUsd);
            if ($f === null) {
                return null;
            }
            $factors[$cur] = $f;
        }
        return $factors;
    }

    /**
     * يبني تعبير SQL آمن (بمعاملات مربوطة) يحوّل عمود المبلغ للعملة
     * الأساسية: amount * CASE currency WHEN ? THEN ? ... END. Pure.
     *
     * @param array<string, float> $factors
     * @return array{0:string,1:array}
     */
    public static function buildAmountSql(array $factors, string $amountCol = 'amount', string $currencyCol = 'currency'): array
    {
        if (empty($factors)) {
            return [$amountCol, []];
        }
        $sql = "({$amountCol} * CASE UPPER({$currencyCol})";
        $params = [];
        foreach ($factors as $cur => $factor) {
            $sql .= ' WHEN ? THEN ?';
            $params[] = (string) $cur;
            $params[] = round((float) $factor, 10);
        }
        $sql .= ' END)';
        return [$sql, $params];
    }

    /** تطبيع رد مزود أسعار JSON ({"rates":{"EUR":0.92,...}}) لخريطة صالحة فقط. Pure. */
    public static function parseProviderRates(array $json): array
    {
        $rates = $json['rates'] ?? $json['conversion_rates'] ?? [];
        $out = [];
        if (!is_array($rates)) {
            return $out;
        }
        foreach ($rates as $cur => $rate) {
            $cur = strtoupper((string) $cur);
            if (preg_match('/^[A-Z]{3}$/', $cur) && is_numeric($rate) && (float) $rate > 0) {
                $out[$cur] = (float) $rate;
            }
        }
        $out['USD'] = 1.0;
        return $out;
    }

    /**
     * أحدث سعر لكل عملة (اليدوي للحساب أولًا ثم العام). يرجع خريطة
     * units_per_usd. أي فشل/جدول غير موجود -> [] (= لا تحويل، السلوك القديم).
     */
    public static function latestRates(int $userId): array
    {
        if (isset(self::$memo[$userId])) {
            return self::$memo[$userId];
        }
        $rates = ['USD' => 1.0];
        // الطبقة 1 (الأقل أولوية): أسعار المنصة اللي الأدمن بيحدثها (CurrencyService) -
        // السعر "base" بدون هامش التسعير، وبس لو اتحدث فعلًا (مش القيمة الافتراضية).
        if (class_exists('CurrencyService')) {
            try {
                foreach (CurrencyService::rates() as $code => $r) {
                    if (($r['source'] ?? 'default') !== 'default' && (float) ($r['base'] ?? 0) > 0) {
                        $rates[strtoupper($code)] = (float) $r['base'];
                    }
                }
            } catch (Throwable $e) {
            }
        }
        // الطبقة 2: أسعار السوق اليومية (user_id=0)، الطبقة 3: أسعار الحساب اليدوية (user_id=N)
        try {
            $rows = Database::getInstance()->query(
                "SELECT r.user_id, r.currency, r.units_per_usd
                 FROM revai_fx_rates r
                 JOIN (SELECT user_id, currency, MAX(rate_date) d FROM revai_fx_rates WHERE user_id IN (0, ?) GROUP BY user_id, currency) m
                   ON m.user_id = r.user_id AND m.currency = r.currency AND m.d = r.rate_date
                 WHERE r.user_id IN (0, ?)
                 ORDER BY r.user_id ASC",
                [$userId, $userId]
            );
            // ترتيب user_id تصاعدي => السعر اليدوي (user_id=N) يكتب فوق العام (0)
            foreach ($rows as $r) {
                if ((float) $r['units_per_usd'] > 0) {
                    $rates[strtoupper((string) $r['currency'])] = (float) $r['units_per_usd'];
                }
            }
        } catch (Throwable $e) {
            // جدول غير مثبت -> نكتفي بأسعار المنصة
        }
        return self::$memo[$userId] = $rates;
    }

    /** حفظ سعر (عام أو يدوي). */
    public static function saveRate(int $userId, string $currency, float $unitsPerUsd, string $source = 'manual', ?string $date = null): bool
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency) || $unitsPerUsd <= 0) {
            return false;
        }
        Database::getInstance()->query(
            "INSERT INTO revai_fx_rates (user_id, currency, units_per_usd, rate_date, source)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE units_per_usd = VALUES(units_per_usd), source = VALUES(source)",
            [$userId, $currency, $unitsPerUsd, $date ?? date('Y-m-d'), mb_substr($source, 0, 40)]
        );
        unset(self::$memo[$userId]);
        if ($userId === 0) {
            self::$memo = [];
        }
        return true;
    }

    public static function resetMemo(): void
    {
        self::$memo = [];
    }
}
