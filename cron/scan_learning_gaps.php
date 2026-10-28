<?php
/**
 * Tourfecto - فحص فجوات معرفة الشات بوت (يوميًا من المنظّم)
 *
 * قبل كده "الأسئلة اللي البوت ماعرفش يرد عليها" ماكانتش بتتجمّع غير لما العميل يدوس زرار الفحص.
 * دلوقتي بتتجمّع لوحدها كل يوم، وصاحب الموقع بيوصله تنبيه لو فيه أسئلة جديدة يضيف إجابتها.
 */
require_once __DIR__ . '/bootstrap.php';

$db = Database::getInstance();
$svc = new LearningLoopService();
$since = date('Y-m-d', strtotime('-2 days'));
$out = ['websites' => 0, 'new_gaps' => 0];

try {
    $sites = $db->query(
        "SELECT c.website_id, w.user_id FROM ai_conversations c JOIN websites w ON w.id = c.website_id
          WHERE c.handoff_at IS NOT NULL AND c.created_at >= ?
          GROUP BY c.website_id, w.user_id LIMIT 500",
        [$since]
    );
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]) . "\n";
    exit(0);
}

foreach ($sites as $s) {
    $out['websites']++;
    try {
        $n = $svc->scanKnowledgeGaps((int) $s['website_id'], $since, 100);
    } catch (Throwable $e) {
        continue;
    }
    if ($n > 0) {
        $out['new_gaps'] += $n;
        Notification::notify((int) $s['user_id'], 'chat_knowledge_gaps',
            'الشات بوت محتاج يتعلم ' . $n . ' إجابة جديدة',
            'فيه أسئلة من الزوار البوت ماعرفش يرد عليها وحوّلها لموظف. ضيف إجاباتها عشان يرد لوحده المرة الجاية.',
            '/chat/learning?website_id=' . (int) $s['website_id']);
    }
}
echo json_encode($out) . "\n";
