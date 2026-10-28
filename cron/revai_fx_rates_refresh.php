<?php

/**
 * Tourfecto - Revenue Intelligence: Daily FX rates refresh
 * @version 2.0.0
 *
 * بيجيب أسعار الصرف اليومية (كل العملات مقابل الدولار) ويحفظها في
 * revai_fx_rates (user_id=0) عشان ذكاء الإيرادات يوحّد الإيرادات متعددة
 * العملات (سياح بيدفعوا يورو/دولار/إسترليني/ريال...) في عملة تقارير واحدة.
 *
 * المصدر: open.er-api.com (نفس المزود اللي CurrencyService بيستخدمه في المنصة)
 * والاحتياطي frankfurter.app (البنك المركزي الأوروبي). لو الاتنين فشلوا
 * السكريبت بيخرج من غير ما يكتب أي رقم (الأسعار القديمة بتفضل كما هي).
 *
 * الجدولة: مرة يوميًا (متضافة في cron/schedule.php باسم revenue_fx_rates).
 */
require_once __DIR__ . '/bootstrap.php';

$sources = [
    'open.er-api.com' => 'https://open.er-api.com/v6/latest/USD',
    'frankfurter.app' => 'https://api.frankfurter.app/latest?from=USD',
];

$saved = 0;
$usedSource = null;
foreach ($sources as $name => $url) {
    try {
        $r = SafeHttp::exec($url, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true], 3);
        $json = is_string($r['response'] ?? null) ? json_decode($r['response'], true) : null;
        if (!is_array($json)) {
            continue;
        }
        $rates = FxRateService::parseProviderRates($json);
        if (count($rates) < 5) {
            continue;
        }
        foreach ($rates as $cur => $rate) {
            if ($cur === 'USD') {
                continue;
            }
            if (FxRateService::saveRate(0, $cur, $rate, $name)) {
                $saved++;
            }
        }
        $usedSource = $name;
        break;
    } catch (Throwable $e) {
        Logger::warning('revai_fx_rates_refresh: source failed', ['source' => $name, 'error' => $e->getMessage()]);
    }
}

if ($usedSource === null) {
    fwrite(STDERR, "revai_fx_rates_refresh: all FX sources failed; keeping previous rates.\n");
    exit(1);
}
echo "revai_fx_rates_refresh: saved {$saved} rate(s) from {$usedSource}.\n";
