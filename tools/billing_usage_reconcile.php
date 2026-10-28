<?php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/app/Services/Subscription/BillingUsageReservation.php';
$db=Database::getInstance();
if (($argv[1]??'list')==='list') {
    echo json_encode($db->query("SELECT token,user_id,feature,source,amount,state,created_at FROM billing_usage_reservations WHERE state IN ('reserved','uncertain') ORDER BY id"),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;exit;
}
if (count($argv)!==4 || $argv[1]!=='resolve' || !in_array($argv[3],['completed','released'],true)) {fwrite(STDERR,"Usage: php tools/billing_usage_reconcile.php list | resolve TOKEN completed|released\n");exit(2);}
if (!BillingUsageReservation::settle($argv[2],$argv[3])) {fwrite(STDERR,"Reservation cannot be resolved\n");exit(1);}
echo "Resolved\n";
