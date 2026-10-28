<?php
/**
 * Tourfecto - ملخص الصبح لأصحاب الشركات (كل 15 دقيقة من المنظّم، وكل واحد بيتبعتله مرة في اليوم في ساعته)
 */
require_once __DIR__ . '/bootstrap.php';
echo json_encode((new MorningBriefService())->runDue()) . "\n";
