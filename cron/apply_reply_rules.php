<?php

/**
 * Tourfecto - تطبيق قواعد الرد التلقائي على المراجعات اللي لسه اتردتش
 * @version 1.0.0
 *
 * يُستدعى من Cron Job حقيقي. بيلاقي مراجعات Google حديثة (آخر 7 أيام)
 * مش عليها رد حتى الآن، وبينفّذ عليها أول قاعدة مطابقة (BirdAI-style).
 * الردود اللي اترد عليها فعلاً بتتخطى تلقائيًا جوه applyRulesToReview().
 *
 * إعداد Cron Job في cPanel (Hostinger):
 *   Common Settings: Every 15 minutes
 *   Command:
 *     php /home/USERNAME/domains/YOURSITE.com/cron/apply_reply_rules.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/reply_rules.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('GbpReplyRuleService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] GbpReplyRuleService مش متوفرة - شغّل migration: database/migrations/2026_08_15_000055_create_gbp_reply_rules.sql\n");
        exit(0);
    }

    $db = Database::getInstance();
    $candidates = [];
    try {
        $candidates = $db->query(
            "SELECT id FROM reviews
             WHERE source_platform = 'google_business'
               AND reply_sent_at IS NULL
               AND review_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY review_date ASC LIMIT 100"
        );
    } catch (Throwable $e) {
        // تجاهل - ممكن العمود مش متاح في بيئة قديمة
    }

    $applied = 0;
    $skipped = 0;
    $errors = 0;
    $service = new GbpReplyRuleService();

    foreach ($candidates as $row) {
        $reviewId = (int) ($row['id'] ?? 0);
        if (!$reviewId) {
            $skipped++;
            continue;
        }

        try {
            $result = $service->applyRulesToReview($reviewId);
            if (($result['success'] ?? false) && ($result['reply_sent'] ?? false)) {
                $applied++;
            } else {
                $skipped++; // no matching rule / already replied / rule only notifies
            }
        } catch (Throwable $e) {
            $errors++;
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Reply Rules run: %d رد اتبعته القواعد، %d متخطي، %d خطأ (%dms)\n",
        date('Y-m-d H:i:s'),
        $applied,
        $skipped,
        $errors,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Reply rules error: ' . $e->getMessage() . "\n");
    exit(1);
}
