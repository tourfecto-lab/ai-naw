<?php
/**
 * Tourfecto - الطيار الآلي للسوشيال (كل ساعة من المنظّم)
 * بيجهّز خطة الشهر الجاي من الحجوزات والرحلات النشطة في اليوم اللي العميل اختاره،
 * وبعد التوليد يعتمدها (لو مسموح) أو يبعت للعميل يراجعها.
 */
require_once __DIR__ . '/bootstrap.php';
$ap = new SocialAutopilotService();
echo json_encode($ap->runDue() + ['auto_started' => $ap->autoStartDue()]) . "\n";
