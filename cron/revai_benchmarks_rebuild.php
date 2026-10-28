<?php

/**
 * Tourfecto - AI Revenue Intelligence: Benchmarks Rebuild (Cron)
 * @version 1.1.0
 *
 * v1.5.0 (Section C): إعادة بناء جدول `revai_benchmarks` من بيانات المنصة
 * الحقيقية المجهولة - بدون أي أرقام مخترعة.
 *
 * v1.1.0 (2026-08-29, G6): توسيع اتساع المقاييس - كان السكريبت ينتج مقياسًا
 * واحدًا فقط (growth_percent_monthly). الآن ينتج 4 مقاييس مستقلة، لكل منها
 * حدّه الأدنى من الحسابات وتُكتب كصف منفصل (upsert على uniq_metric_asof):
 *   1. growth_percent_monthly  - نمو الإيراد الشهري (كان موجودًا)
 *   2. win_rate_percent        - نسبة فوز الصفقات (won/(won+lost)) آخر 90 يوم
 *   3. avg_deal_value          - متوسط قيمة الصفقة المكسوبة آخر 90 يوم
 *   4. revenue_monthly_avg     - متوسط الإيراد الشهري (آخر 3 شهور كاملة)
 * لو عدد الحسابات المؤهلة لمقياس أقل من الحد الأدنى، نتخطّى هذا المقياس فقط
 * ونكتب رسالة - لا نكتب أي رقم مخترع ("Not enough data" محافظ عليه).
 *
 * القاعدة الثابتة في الموديول: "الـAI يعتمد على بيانات المشروع الحقيقية
 * فقط؛ لا يخترع أرقامًا؛ إن لم تكن البيانات كافية: Not enough data".
 *
 * السكريبت لا يلمس البيانات الشخصية: يعمل مجاميع (دوال تجميعية) فقط
 * ولا يقرأ/يكتب أي بيانات هوية مالك الحساب. صفوف الـbenchmarks لا
 * تحمل user_id إطلاقًا - بيانات منصية مجهولة صرفة.
 *
 * الجدولة (تُضاف يدويًا من لوحة التحكم، لا SSH):
 *   0 4 * * 1  php /path/to/project/cron/revai_benchmarks_rebuild.php
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');

require_once ROOT_PATH . '/vendor/autoload.php';

// كلاسات الموديول - إضافة فقط (no-op لو مش موجودة).
$requiredClassFiles = [
    APP_PATH . '/Services/RevenueIntelligence/RevenueDataGateway.php',
];
foreach ($requiredClassFiles as $classFile) {
    if (file_exists($classFile)) {
        require_once $classFile;
    }
}

if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        error_log('Failed to load .env: ' . $e->getMessage());
    }
}

require_once APP_PATH . '/Config/app.php';
require_once APP_PATH . '/Config/constants.php';
require_once APP_PATH . '/Config/database.php';
require_once APP_PATH . '/Config/encryption.php';

/** كم عدد الحسابات الأدنى لتكوين benchmark منصي موثوق؟ */
const REVAI_BENCH_MIN_ACCOUNTS = 10;

/**
 * يحسب p25/p50/p75 لمصفوفة قيم ويُكتب صف benchmark (upsert) أو يكتفي
 * برسالة عند قلة البيانات. لا يكتب أي رقم لو العينة أقل من الحد الأدنى.
 *
 * @param Database $db
 * @param string   $metricKey
 * @param string   $metricLabel
 * @param float[]  $values
 * @param string   $asOf
 */
function revai_write_metric($db, string $metricKey, string $metricLabel, array $values, string $asOf): void
{
    $count = count($values);
    if ($count < REVAI_BENCH_MIN_ACCOUNTS) {
        fwrite(STDOUT, "revai_benchmarks_rebuild: {$metricKey} skipped - only {$count} qualified accounts (< " . REVAI_BENCH_MIN_ACCOUNTS . "). No invented numbers.\n");
        return;
    }

    sort($values, SORT_NUMERIC);
    $percentile = static function (array $sorted, float $p): float {
        $index = (int) ceil(($p / 100) * count($sorted)) - 1;
        $index = max(0, min(count($sorted) - 1, $index));
        return $sorted[$index];
    };

    $db->query(
        "INSERT INTO revai_benchmarks
            (metric_key, metric_label, p25, p50, p75, basis, sample_size, as_of_date)
         VALUES (?, ?, ?, ?, ?, 'platform', ?, ?)
         ON DUPLICATE KEY UPDATE p25 = VALUES(p25), p50 = VALUES(p50),
             p75 = VALUES(p75), sample_size = VALUES(sample_size), basis = 'platform'",
        [
            $metricKey,
            $metricLabel,
            $percentile($values, 25),
            $percentile($values, 50),
            $percentile($values, 75),
            $count,
            $asOf,
        ]
    );

    fwrite(STDOUT, sprintf(
        "revai_benchmarks_rebuild: wrote %s for %s (p25=%.4f, p50=%.4f, p75=%.4f, n=%d).\n",
        $metricKey,
        $asOf,
        $percentile($values, 25),
        $percentile($values, 50),
        $percentile($values, 75),
        $count
    ));
}

try {
    $db = Database::getInstance();
    $asOf = gmdate('Y-m-d');

    // ============================================================
    // 1) growth_percent_monthly - نمو الإيراد الشهري (كان موجودًا)
    // ============================================================
    $accounts = $db->query(
        "SELECT user_id,
                SUM(CASE WHEN MONTH(recorded_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) THEN amount END) AS last_month,
                SUM(CASE WHEN MONTH(recorded_at) = MONTH(NOW()) THEN amount END) AS this_month
         FROM rev_revenue_records
         WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
         GROUP BY user_id
         HAVING last_month IS NOT NULL AND last_month > 0"
    );

    $growthRates = [];
    if (is_array($accounts)) {
        foreach ($accounts as $acc) {
            $last = (float) ($acc['last_month'] ?? 0);
            $current = (float) ($acc['this_month'] ?? 0);
            if ($last <= 0) {
                continue;
            }
            $growthRates[] = round((($current - $last) / $last) * 100, 4);
        }
    }
    revai_write_metric($db, 'growth_percent_monthly', 'Monthly Revenue Growth % (real platform aggregation)', $growthRates, $asOf);

    // ============================================================
    // 2) win_rate_percent - نسبة فوز الصفقات (won/(won+lost)) آخر 90 يوم
    // ============================================================
    $dealStats = $db->query(
        "SELECT owner_user_id AS user_id,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost
         FROM crm_deals
         WHERE closed_at IS NOT NULL AND closed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND status IN ('won', 'lost')
         GROUP BY owner_user_id"
    );
    $winRates = [];
    if (is_array($dealStats)) {
        foreach ($dealStats as $acc) {
            $won = (int) ($acc['won'] ?? 0);
            $lost = (int) ($acc['lost'] ?? 0);
            $closed = $won + $lost;
            if ($closed <= 0) {
                continue;
            }
            $winRates[] = round(($won / $closed) * 100, 4);
        }
    }
    revai_write_metric($db, 'win_rate_percent', 'Deal Win Rate % (last 90 days, real platform aggregation)', $winRates, $asOf);

    // ============================================================
    // 3) avg_deal_value - متوسط قيمة الصفقة المكسوبة (won) آخر 90 يوم
    // ============================================================
    $wonByAccount = $db->query(
        "SELECT owner_user_id AS user_id, AVG(value) AS avg_value
         FROM crm_deals
         WHERE status = 'won' AND closed_at IS NOT NULL AND closed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
         GROUP BY owner_user_id"
    );
    $avgDealValues = [];
    if (is_array($wonByAccount)) {
        foreach ($wonByAccount as $acc) {
            $avg = (float) ($acc['avg_value'] ?? 0);
            if ($avg <= 0) {
                continue;
            }
            $avgDealValues[] = round($avg, 2);
        }
    }
    revai_write_metric($db, 'avg_deal_value', 'Avg Won Deal Value $ (last 90 days, real platform aggregation)', $avgDealValues, $asOf);

    // ============================================================
    // 4) revenue_monthly_avg - متوسط الإيراد الشهري (آخر 3 شهور كاملة)
    // ============================================================
    $monthlyByAccount = $db->query(
        "SELECT user_id, month, SUM(amount) AS month_total
         FROM (
             SELECT user_id, DATE_FORMAT(recorded_at, '%Y-%m') AS month, amount
             FROM rev_revenue_records
             WHERE recorded_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 3 MONTH)
               AND recorded_at < DATE_FORMAT(NOW(), '%Y-%m-01')
         ) m
         GROUP BY user_id, month"
    );
    $monthlyTotals = [];
    if (is_array($monthlyByAccount)) {
        foreach ($monthlyByAccount as $acc) {
            $userId = (string) $acc['user_id'];
            $monthTotal = (float) ($acc['month_total'] ?? 0);
            if ($monthTotal <= 0) {
                continue;
            }
            if (!isset($monthlyTotals[$userId])) {
                $monthlyTotals[$userId] = ['sum' => 0.0, 'months' => 0];
            }
            $monthlyTotals[$userId]['sum'] += $monthTotal;
            $monthlyTotals[$userId]['months']++;
        }
    }
    $monthlyAverages = [];
    foreach ($monthlyTotals as $agg) {
        if ($agg['months'] < 2) {
            continue; // شهر واحد بس لا يكفي لمتوسط موثوق
        }
        $monthlyAverages[] = round($agg['sum'] / $agg['months'], 2);
    }
    revai_write_metric($db, 'revenue_monthly_avg', 'Avg Monthly Revenue $ (last 3 full months, real platform aggregation)', $monthlyAverages, $asOf);

    fwrite(STDOUT, "revai_benchmarks_rebuild: completed for {$asOf}.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'revai_benchmarks_rebuild error: ' . $e->getMessage() . "\n");
    exit(1);
}
