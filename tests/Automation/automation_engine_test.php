<?php
/**
 * اختبار محرك الأتمتة على قاعدة بيانات تجريبية.
 * php tests/Automation/automation_engine_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';

$fails = 0; $n = 0;
function check($cond, $msg) { global $fails, $n; $n++; if ($cond) { echo "  ok  $msg\n"; } else { $fails++; echo "  FAIL $msg\n"; } }

$db = Database::getInstance();
$uid = 900001;
$db->exec('DELETE FROM automation_decisions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_policies WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_examples WHERE user_id = ?', [$uid]);
$e = new AutomationEngine($db);

echo "Defaults\n";
check($e->effectiveMode($uid, 'review_replies', 'positive') === 'auto', 'positive reviews default auto');
check($e->effectiveMode($uid, 'review_replies', 'negative') === 'approval', 'negative reviews default approval');
check($e->effectiveMode($uid, 'ads_optimizations', 'increase_budget') === 'approval', 'budget increase default approval');

echo "Gate in auto\n";
$g = $e->gate($uid, 1, 'review_replies', 'positive', 'review', 'r1', ['draft' => 'شكرا لزيارتك']);
check($g['action'] === 'execute' && $g['decision_id'], 'auto -> execute');
$e->complete($g['decision_id'], true);
$g2 = $e->gate($uid, 1, 'review_replies', 'positive', 'review', 'r1', ['draft' => 'x']);
check($g2['action'] === 'skip', 'same subject not executed twice');

echo "Gate in approval\n";
$g = $e->gate($uid, 1, 'review_replies', 'negative', 'review', 'r2', ['draft' => 'نأسف جدا', 'context' => 'تجربة سيئة']);
check($g['action'] === 'wait', 'negative -> wait');
check(count($e->pending($uid)) === 1, 'shows in pending inbox');
$d = $e->decide($uid, $g['decision_id'], 'approve', 'نأسف جدا يا أستاذ أحمد، كلمنا على واتساب', false);
check($d['status'] === 'approved_edited', 'edited approval recorded');
$g3 = $e->gate($uid, 1, 'review_replies', 'negative', 'review', 'r2', []);
check($g3['action'] === 'execute' && strpos($g3['final_text'], 'أستاذ أحمد') !== false, 'approved -> execute with edited text');
$e->complete($g3['decision_id'], true);
check($e->gate($uid, 1, 'review_replies', 'negative', 'review', 'r2', [])['action'] === 'skip', 'approved+executed not repeated');
check(strpos($e->styleBlock($uid, 'review_replies', 'negative'), 'أستاذ أحمد') !== false, 'edit becomes style example');

echo "Learning + graduation\n";
$e->setPolicy($uid, 'chat_replies', 'learning');
check($e->effectiveMode($uid, 'chat_replies', 'default') === 'learning', 'learning mode set');
for ($i = 1; $i <= 9; $i++) {
    $g = $e->gate($uid, 1, 'chat_replies', 'default', 'chat_message', "m$i", ['draft' => "أهلا بيك، الرحلة متاحة يوم الجمعة رقم $i", 'context' => 'في رحلة الجمعة؟']);
    $e->decide($uid, $g['decision_id'], 'approve', null, false);
}
$st = $e->learningStats($uid, 'chat_replies', 'default');
check($st['samples'] === 9 && $st['needed'] === 1 && !$st['graduated'], '9 approvals -> needs 1 more');
$g = $e->gate($uid, 1, 'chat_replies', 'default', 'chat_message', 'm10', ['draft' => 'أهلا بيك']);
$r = $e->decide($uid, $g['decision_id'], 'approve', null, false);
check($r['graduated'] === true, '10th approval graduates');
check($e->effectiveMode($uid, 'chat_replies', 'default') === 'auto', 'graduated bucket runs auto');
$g = $e->gate($uid, 1, 'chat_replies', 'default', 'chat_message', 'm11', ['draft' => 'تمام']);
check($g['action'] === 'execute', 'after graduation -> execute');
$e->complete($g['decision_id'], true);
$fb = $e->feedback($uid, $g['decision_id'], 'bad');
check($fb['demoted'] === true && $e->effectiveMode($uid, 'chat_replies', 'default') === 'learning', 'bad feedback demotes back to learning');

echo "Rejections block graduation\n";
$e->setPolicy($uid, 'website_fixes', 'learning');
for ($i = 1; $i <= 12; $i++) {
    $g = $e->gate($uid, 1, 'website_fixes', 'meta', 'wo_fix', "f$i", ['title' => 'fix']);
    $e->decide($uid, $g['decision_id'], $i % 3 === 0 ? 'reject' : 'approve', null, false);
}
$st = $e->learningStats($uid, 'website_fixes', 'meta');
check(!$st['graduated'] && $st['rate'] < 0.9, 'only ' . round($st['rate']*100) . '% approved -> stays learning');

echo "Manual mode + manual learning\n";
$e->setPolicy($uid, 'seo_content', 'manual');
check($e->gate($uid, 1, 'seo_content', 'default', 'seo_item', 's1', [])['action'] === 'skip', 'manual -> skip');
$e->recordManualAction($uid, 'review_replies', 'positive', 'review', 'r9', 'ألف شكر يا فندم، نورتونا ونستناكم تاني 🌴', 'رحلة جميلة');
check(strpos($e->styleBlock($uid, 'review_replies', 'positive'), 'نورتونا') !== false, 'manual reply learned as style');

echo "Similarity\n";
check(AutomationEngine::similarity('شكرًا لزيارتك يا أحمد', 'شكرا لزيارتك يا احمد') > 0.99, 'ignores diacritics/hamza');
check(AutomationEngine::similarity('thanks for visiting us', 'we are sorry for the bad trip') < 0.3, 'different texts low');

echo "Cleanup\n";
$db->exec('DELETE FROM automation_decisions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_policies WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_examples WHERE user_id = ?', [$uid]);
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
