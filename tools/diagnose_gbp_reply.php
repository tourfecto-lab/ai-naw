<?php

/**
 * Why did a Google review reply fail? Read-only diagnosis for one review: the Google Business connection,
 * the access token refresh, the token's scopes, and whether Google still returns that review.
 * It never sends a reply and never prints a token or key.
 *   sudo -u www /www/server/php/83/bin/php tools/diagnose_gbp_reply.php 104
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../cron/bootstrap.php';

$reviewId = (int) ($argv[1] ?? 0);
if ($reviewId <= 0) {
    exit("Usage: php tools/diagnose_gbp_reply.php <review_id>\n");
}
$db = Database::getInstance();
$say = static function (string $label, string $value): void { echo str_pad($label, 26) . ': ' . $value . "\n"; };

$review = $db->query('SELECT id, user_id, website_id, source_platform, external_review_id, rating, reply_status, reply_sent_at FROM reviews WHERE id = ? LIMIT 1', [$reviewId])[0] ?? null;
if (!$review) {
    exit("Review {$reviewId} not found\n");
}
$say('Review', '#' . $review['id'] . ' · ' . $review['source_platform'] . ' · ' . $review['rating'] . ' stars · website ' . $review['website_id']);
$say('Google review id', $review['external_review_id'] ? 'present' : 'MISSING (cannot reply on Google)');
$say('Reply status', ($review['reply_status'] ?? 'null') . ($review['reply_sent_at'] ? ' · sent ' . $review['reply_sent_at'] : ''));

$conn = (new PlatformConnection())->where(['website_id' => (int) $review['website_id'], 'platform' => 'google_business', 'status' => 'connected'], [], 1)[0] ?? null;
if (!$conn) {
    $other = $db->query("SELECT status FROM platform_connections WHERE website_id = ? AND platform = 'google_business' LIMIT 1", [(int) $review['website_id']])[0]['status'] ?? 'none';
    exit(str_pad('Connection', 26) . ": NOT CONNECTED (status: {$other}) - reconnect Google Business for this website\n");
}
$say('Connection', 'connected');
$say('Account / location ids', ($conn->getAttribute('external_account_id') ? 'account ok' : 'account MISSING') . ' · ' . ($conn->getAttribute('external_location_id') ? 'location ok' : 'location MISSING'));
$say('Token expired (stored)', $conn->isTokenExpired() ? 'yes (will refresh)' : 'no');

try {
    $token = (new GoogleReviewSyncService())->getValidAccessToken($conn);
    $say('Token refresh', 'ok');
} catch (Throwable $e) {
    exit(str_pad('Token refresh', 26) . ': FAILED - ' . mb_substr(preg_replace('/ya29\.[\w.-]+/', '[token]', $e->getMessage()), 0, 300) . "\n");
}

$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($token));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$info = json_decode((string) curl_exec($ch), true) ?: [];
curl_close($ch);
$scopes = preg_split('/\s+/', (string) ($info['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
$say('Token scopes', $scopes ? implode(', ', array_map(static fn ($s) => preg_replace('#^https://www\.googleapis\.com/auth/#', '', $s), $scopes)) : 'unknown (' . ($info['error_description'] ?? $info['error'] ?? 'no answer') . ')');
$say('business.manage scope', in_array('https://www.googleapis.com/auth/business.manage', $scopes, true) ? 'yes' : 'NO - replies need this scope');

$api = new GoogleBusinessAPI($token, $conn->getAttribute('external_account_id'), $conn->getAttribute('external_location_id'));
$loc = $api->getLocation();
$say('Connected Google location', !empty($loc['success']) ? (string) ($loc['location']['name'] ?? '?') : 'could not read (' . mb_substr((string) ($loc['error'] ?? ''), 0, 120) . ')');
$say('Website in the platform', (string) ($db->query('SELECT company_name FROM websites WHERE id = ?', [(int) $review['website_id']])[0]['company_name'] ?? '?'));

// كل أرقام التقييمات على الموقع المربوط (لحد 1000)
$googleIds = [];
$page = null;
for ($i = 0; $i < 20; $i++) {
    $r = $api->getReviews(['limit' => 50, 'page_token' => $page]);
    if (empty($r['success'])) break;
    foreach ($r['reviews'] ?? [] as $g) if (!empty($g['id'])) $googleIds[(string) $g['id']] = true;
    $page = $r['next_page_token'] ?? null;
    if (!$page) break;
}
$say('Reviews on Google', count($googleIds) . ($page ? '+ (stopped at 1000)' : ''));
$neg = $db->query("SELECT external_review_id FROM reviews WHERE website_id = ? AND source_platform = 'google_business' AND rating <= 3 AND reply_sent_at IS NULL", [(int) $review['website_id']]) ?: [];
$onGoogle = count(array_filter($neg, static fn ($x) => isset($googleIds[(string) $x['external_review_id']])));
$say('Unanswered negatives here', count($neg) . ' in the platform · ' . $onGoogle . ' still on Google · ' . (count($neg) - $onGoogle) . ' not on Google');

$found = null;
$page = null;
for ($i = 0; $i < 5 && $found === null; $i++) {
    $r = $api->getReviews(['limit' => 50, 'page_token' => $page]);
    if (empty($r['success'])) {
        exit(str_pad('Read reviews from Google', 26) . ': FAILED - ' . mb_substr((string) ($r['error'] ?? ''), 0, 300) . ' ' . ($r['error_code'] ?? '') . "\n");
    }
    foreach ($r['reviews'] ?? [] as $g) {
        if ((string) ($g['id'] ?? '') === (string) $review['external_review_id']) {
            $found = $g;
            break;
        }
    }
    $page = $r['next_page_token'] ?? null;
    if (!$page) break;
}
$say('Read reviews from Google', 'ok');
$say('This review on Google', $found ? 'found' . (!empty($found['response']) ? ' (already has a reply)' : '') : 'NOT FOUND in the latest 250 (deleted on Google, or a different location)');
echo "\nNo reply was sent. If everything above is ok, the reply was rejected by Google itself; check the reply length and content.\n";
