<?php
/**
 * اختبار تدفق الرد التلقائي على التقييمات من أوله لآخره، مع Google وهمي.
 * php tests/Automation/review_automation_flow_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
// Google وهمي: بيسجّل الردود بدل ما يبعتها
class FakeGoogle { public static $sent = []; }
ReviewAutoReplyRunner::$transport = function ($review, $text) { FakeGoogle::$sent[$review['external_review_id']] = $text; return ['success' => true]; };
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

$db = Database::getInstance();
$db->exec("DELETE FROM users WHERE email = 'automation-test@test.local'");
$uid = (int) $db->query("INSERT INTO users (email, password_hash, company_name, role, status) VALUES ('automation-test@test.local', 'x', 'Automation Test', 'manager', 'active')");
$wid = (int) $db->query("INSERT INTO websites (user_id, main_url, company_name) VALUES (?, 'https://example.com', 'Automation Test')", [$uid]);
$db->exec('DELETE FROM automation_decisions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_policies WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_examples WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM reviews WHERE user_id = ?', [$uid]);
$db->exec("DELETE FROM platform_connections WHERE user_id = ?", [$uid]);
$db->query("INSERT INTO platform_connections (website_id, user_id, platform, status, external_account_id, external_location_id) VALUES (?, ?, 'google_business', 'connected', 'acc', 'loc')", [$wid, $uid]);
$mk = function ($ext, $rating, $text) use ($db, $uid, $wid) {
    return $db->query("INSERT INTO reviews (website_id, user_id, source_platform, platform, external_review_id, reviewer_name, review_text, rating, review_date) VALUES (?, ?, 'google_business', 'google_business', ?, 'Anna', ?, ?, NOW())", [$wid, $uid, $ext, $text, $rating]);
};
$r5 = $mk('g-5', 5, 'Amazing Nile cruise, thank you!');
$r3 = $mk('g-3', 3, 'OK trip but the bus was late');
$r1 = $mk('g-1', 1, 'Terrible guide, never again');

// رصيد محفظة عشان رصيد الردود (0.5$ للرد)
$db->query("INSERT INTO wallet_transactions (user_id, type, amount, currency, status, reference_note, approved_at) VALUES (?, 'admin_adjustment', 50, 'USD', 'completed', 'test', NOW())", [$uid]);
$engine = new AutomationEngine($db);
AutomationEngine::setInstance($engine);
$drafts = 0;
$runner = new ReviewAutoReplyRunner($db, $engine, function ($review, $u) use (&$drafts) { $drafts++; return 'شكرا يا ' . $review['reviewer_name'] . ' على تقييمك'; });

echo "Default policy (positive/neutral auto, negative approval)\n";
// اشتراك وهمي عشان رصيد الردود
$out = $runner->runForWebsite($uid, $wid);
check($out['sent'] === 2 && $out['pending'] === 1, 'sent 2 automatically, 1 negative waiting (' . json_encode($out) . ')');
check(isset(FakeGoogle::$sent['g-5']) && isset(FakeGoogle::$sent['g-3']) && !isset(FakeGoogle::$sent['g-1']), 'only positive+neutral posted to Google');
$row = $db->query('SELECT reply_status, reply_sent_at FROM reviews WHERE id = ?', [$r5])[0];
check($row['reply_status'] === 'auto_sent' && $row['reply_sent_at'], 'review marked auto_sent');
$neg = $db->query('SELECT reply_status, ai_generated_reply FROM reviews WHERE id = ?', [$r1])[0];
check($neg['reply_status'] === 'pending' && $neg['ai_generated_reply'] !== '', 'negative review has draft waiting');

echo "Second run does nothing new\n";
$out = $runner->runForWebsite($uid, $wid);
check($out['sent'] === 0 && $drafts === 3, 'no duplicate replies or drafts (' . json_encode($out) . ')');

echo "Customer approves the negative one with an edit (from Automation Center)\n";
$p = $engine->pending($uid);
$res = $engine->decide($uid, (int) $p[0]['id'], 'approve', 'آسفين جدًا يا Anna، كلّمينا على واتساب نعوّضك', true);
check(!empty($res['execution']['success']), 'edited reply executed');
check((FakeGoogle::$sent['g-1'] ?? '') === 'آسفين جدًا يا Anna، كلّمينا على واتساب نعوّضك', 'Google got the edited text');
check(strpos($engine->styleBlock($uid, 'review_replies', 'negative'), 'نعوّضك') !== false, 'edit saved as style for negative reviews');

echo "Manual mode stops everything\n";
$engine->setPolicy($uid, 'review_replies', 'manual', ['negative' => 'manual']);
$r4 = $mk('g-4', 4, 'Nice');
$out = $runner->runForWebsite($uid, $wid);
check($out['sent'] === 0 && $out['pending'] === 0 && !isset(FakeGoogle::$sent['g-4']), 'manual: nothing posted, no AI spent');

echo "Learning mode then graduation\n";
$engine->setPolicy($uid, 'review_replies', 'learning', []);
for ($i = 0; $i < 10; $i++) { $mk("gl-$i", 5, "Great $i"); }
$out = $runner->runForWebsite($uid, $wid);
check($out['pending'] === 11 && $out['sent'] === 0, 'learning: 11 drafts waiting (' . json_encode($out) . ')');
$pend = $engine->pending($uid);
$grad = false;
foreach ($pend as $d) { $r = $engine->decide($uid, (int) $d['id'], 'approve', null, true); $grad = $grad || !empty($r['graduated']); }
check($grad && $engine->effectiveMode($uid, 'review_replies', 'positive') === 'auto', 'after 10 approvals positive reviews graduate to auto');
$mk('g-new', 5, 'Loved it');
$out = $runner->runForWebsite($uid, $wid);
check($out['sent'] === 1 && isset(FakeGoogle::$sent['g-new']), 'new positive review now replied automatically');

// تنظيف
$db->exec('DELETE FROM automation_decisions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_policies WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_examples WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM reviews WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM platform_connections WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM wallet_transactions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM websites WHERE id = ?', [$wid]);
$db->exec('DELETE FROM users WHERE id = ?', [$uid]);
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
