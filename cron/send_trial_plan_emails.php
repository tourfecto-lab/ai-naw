<?php
/**
 * Tourfecto - إيميل «خطة أول 3 أيام» للعملاء في التجربة المجانية (أول اليوم التاني والتالت، مرة واحدة لكل يوم)
 */
require_once __DIR__ . '/bootstrap.php';
echo json_encode((new TrialPlanService())->runDailyEmails()) . "\n";
