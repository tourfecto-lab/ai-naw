<?php

/**
 * Tourfecto - Competitor Intelligence: Keyword Rankings Scheduler (G1)
 * @version 1.0.0
 *
 * يفحص ترتيبات SERP للكلمات المفتاحية المربوطة بالمنافسين عبر مصدر
 * KeywordRankingSourceInterface مهيأ (لو موجود). الافتراضي
 * (NullKeywordRankingSource) بيرجع isConfigured()=false وبالتالي الدور
 * بيسجل "غير متاح" بدون اختلاق أي ترتيبات - طبقًا لقاعدة NO FAKE DATA.
 *
 * الكلمات المفحوصة = من جدول competitor_keywords (الجدول القديم اللي
 * كان مهملًا - دلوقتي بقى له استهلاك فعلي) للمنافسين النشطين.
 *
 * Common Settings: كل 24 ساعة
 * Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *   php /home/USERNAME/domains/YOURSITE.com/cron/ci_keyword_rankings.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ci_keyword_rankings.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();

    SeoDataSchema::ensureSchema($db);
    $research = new DataForSeoResearchService($db);
    if (!$research->configured()) { fwrite(STDOUT, "DataForSEO: not measured\n"); exit(0); }
    foreach ($db->query("SELECT DISTINCT website_id,user_id FROM competitors WHERE is_active=1 AND monitoring_paused=0 LIMIT 50") as $site) {
        try { $research->competitors((int)$site['website_id'], (int)$site['user_id']); }
        catch (Throwable $e) { fwrite(STDERR, "Competitor research unavailable for site ".(int)$site['website_id']."\n"); }
    }
    // المنافسون النشطون اللي عندهم كلمات مفتاحية مرصودة
    $rows = $db->query(
        "SELECT DISTINCT c.id AS competitor_id, c.competitor_domain,c.website_id,c.user_id
         FROM competitors c
         JOIN competitor_keywords k ON k.competitor_id = c.id
         JOIN seo_markets m ON m.id=k.market_id AND m.website_id=c.website_id AND m.is_primary=1
         WHERE c.is_active = 1 AND c.monitoring_paused = 0
         LIMIT 200"
    );

    $service = new KeywordRankingService();
    $checked = 0;
    $recorded = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $competitorId = (int) $row['competitor_id'];
        $domain = CompetitorDomain::normalizeSafe((string) $row['competitor_domain']);
        if ($domain === null) {
            $skipped++;
            continue;
        }

        $market=(new SeoMarketService($db))->resolve((int)$row['website_id'],(int)$row['user_id']);
        $service=new KeywordRankingService((new DataForSeoRankingSource())->forMarket($market,(int)$row['website_id']));
        $keywords = array_map(
            static fn ($k) => (string) $k['keyword'],
            $db->query("SELECT keyword FROM competitor_keywords WHERE competitor_id = ? AND market_id = ? LIMIT 100", [$competitorId,$market['id']])
        );
        if (empty($keywords)) {
            $skipped++;
            continue;
        }

        try {
            $result = $service->runScheduledCheck($competitorId, $domain, $keywords);
            if ($result['available']) {
                $checked++;
                $recorded += $result['recorded'];
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            $skipped++;
            if (class_exists('Logger')) {
                Logger::warning('Keyword rankings check failed', ['competitor_id' => $competitorId, 'error' => $e->getMessage()]);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Keyword Rankings Scheduler: %d فحص، %d ترتيب اتسجل، %d تخطى (غير متاح/فشل) (%dms)\n",
        date('Y-m-d H:i:s'),
        $checked,
        $recorded,
        $skipped,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Keyword rankings scheduler error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Keyword rankings scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
