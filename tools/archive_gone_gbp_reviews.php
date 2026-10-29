<?php

/**
 * Unanswered Google reviews that are no longer on the website's connected Google location (deleted on Google, or
 * synced from an older location) can never be answered: Google rejects the reply. This marks them "ignored"
 * (reply_status = 'rejected', the same state as ignoring a review in the platform) so the reputation pages and the
 * strategic brain stop counting them. Nothing is deleted, and --undo restores them.
 *
 *   sudo -u www /www/server/php/83/bin/php tools/archive_gone_gbp_reviews.php <website_id>           # dry run: lists, changes nothing
 *   sudo -u www /www/server/php/83/bin/php tools/archive_gone_gbp_reviews.php <website_id> --apply   # marks them ignored, saves an undo file
 *   sudo -u www /www/server/php/83/bin/php tools/archive_gone_gbp_reviews.php <website_id> --undo    # puts them back to pending
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../cron/bootstrap.php';

$websiteId = (int) ($argv[1] ?? 0);
$mode = $argv[2] ?? '--dry-run';
if ($websiteId <= 0 || !in_array($mode, ['--dry-run', '--apply', '--undo'], true)) {
    exit("Usage: php tools/archive_gone_gbp_reviews.php <website_id> [--apply|--undo]\n");
}
$db = Database::getInstance();
$update = static function (string $sql, array $params) use ($db): int {
    $st = $db->getConnection()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
};
$undoFile = TOURFECTO_STORAGE . '/logs/archive_gone_gbp_reviews_' . $websiteId . '.json';

if ($mode === '--undo') {
    $ids = is_file($undoFile) ? (json_decode((string) file_get_contents($undoFile), true) ?: []) : [];
    if (!$ids) exit("Nothing to undo for website {$websiteId}\n");
    $n = 0;
    foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $n += $update("UPDATE reviews SET reply_status = 'pending' WHERE website_id = ? AND reply_status = 'rejected' AND reply_sent_at IS NULL AND id IN ({$in})", array_merge([$websiteId], $chunk));
    }
    rename($undoFile, $undoFile . '.undone-' . gmdate('YmdHis'));
    exit("Restored {$n} reviews to pending\n");
}

$conn = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'google_business', 'status' => 'connected'], [], 1)[0] ?? null;
if (!$conn) exit("Website {$websiteId} is not connected to Google Business; nothing to compare with\n");
$api = new GoogleBusinessAPI((new GoogleReviewSyncService())->getValidAccessToken($conn), $conn->getAttribute('external_account_id'), $conn->getAttribute('external_location_id'));
$loc = $api->getLocation();

$googleIds = [];
$page = null;
for ($i = 0; $i < 40; $i++) {
    $r = $api->getReviews(['limit' => 50, 'page_token' => $page]);
    if (empty($r['success'])) exit('Google did not answer (' . mb_substr((string) ($r['error'] ?? ''), 0, 200) . "); nothing changed\n");
    foreach ($r['reviews'] ?? [] as $g) if (!empty($g['id'])) $googleIds[(string) $g['id']] = true;
    $page = $r['next_page_token'] ?? null;
    if (!$page) break;
}
// القايمة لازم تكون كاملة: لو فيه صفحات لسه، ماينفعش نحكم إن تقييم "مش موجود"
if ($page) exit("Google has more than 2000 reviews for this location; the list is incomplete, nothing changed\n");

$rows = $db->query("SELECT id, external_review_id, rating, reviewer_name, COALESCE(review_date, created_at) AS d FROM reviews
    WHERE website_id = ? AND source_platform = 'google_business' AND reply_sent_at IS NULL AND COALESCE(reply_status, '') <> 'rejected'
      AND external_review_id IS NOT NULL AND external_review_id <> ''", [$websiteId]) ?: [];
$gone = array_values(array_filter($rows, static fn ($r) => !isset($googleIds[(string) $r['external_review_id']])));

echo 'Website            : ' . ($db->query('SELECT company_name FROM websites WHERE id = ?', [$websiteId])[0]['company_name'] ?? '?') . " (#{$websiteId})\n";
echo 'Connected location : ' . (!empty($loc['success']) ? ($loc['location']['name'] ?? '?') : '?') . "\n";
echo 'Reviews on Google  : ' . count($googleIds) . "\n";
echo 'Unanswered here    : ' . count($rows) . ' · not on Google: ' . count($gone) . "\n";
foreach (array_slice($gone, 0, 10) as $r) echo '  #' . $r['id'] . ' · ' . $r['rating'] . '★ · ' . mb_substr((string) $r['reviewer_name'], 0, 30) . ' · ' . $r['d'] . "\n";
if (count($gone) > 10) echo '  … and ' . (count($gone) - 10) . " more\n";

if ($mode !== '--apply') exit("\nDry run: nothing changed. Run again with --apply to mark them ignored.\n");
if (!$gone) exit("Nothing to archive\n");
if (count($googleIds) === 0) exit("Google returned no reviews at all for this location; refusing to archive everything. Nothing changed.\n");

$ids = array_map(static fn ($r) => (int) $r['id'], $gone);
$prev = is_file($undoFile) ? (json_decode((string) file_get_contents($undoFile), true) ?: []) : [];
file_put_contents($undoFile, json_encode(array_values(array_unique(array_merge($prev, $ids)))));
$n = 0;
foreach (array_chunk($ids, 200) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $n += $update("UPDATE reviews SET reply_status = 'rejected' WHERE website_id = ? AND reply_sent_at IS NULL AND id IN ({$in})", array_merge([$websiteId], $chunk));
}
echo "\nMarked {$n} reviews as ignored. Undo: php tools/archive_gone_gbp_reviews.php {$websiteId} --undo\n";
