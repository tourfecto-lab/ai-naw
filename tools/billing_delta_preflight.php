<?php
/** Read-only: confirms the existing billing/export schema and reports unavailable offers. */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once dirname(__DIR__) . '/cron/bootstrap.php';
try {
    require_once APP_PATH . '/Services/Subscription/BillingPlanCatalog.php';
    $pdo=Database::getInstance()->getConnection();
    $rows=$pdo->query('SELECT * FROM subscription_plans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $catalog=BillingPlanCatalog::fromRows(array_filter($rows,fn($r)=>(int)$r['is_active']===1));
    $groups=[];
    foreach($rows as $r)if((int)$r['is_active']===1&&preg_match('/^(.+)_(monthly|yearly)$/D',$r['plan_code'],$m))$groups[$m[1]]=true;
    $unavailable=array_values(array_diff(array_keys($groups),array_keys($catalog)));
    $pdo->query('SELECT id, user_id, expires_at, file_path, status FROM data_export_requests LIMIT 0');
    $pdo->query('SELECT reminder_status, reminder_attempts FROM bookings LIMIT 0');
    $pdo->query('SELECT price, currency FROM subscriptions LIMIT 0');
    echo json_encode(['ready'=>!$unavailable && (bool)$catalog,'published_billing_plans'=>array_keys($catalog),'needs_configuration_review'=>$unavailable,'schema'=>'available','new_sql_required'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    exit($unavailable||!$catalog?1:0);
}catch(Throwable $e){echo json_encode(['ready'=>false,'schema'=>'unavailable','message'=>'Check existing billing, export and reminder schema in private logs.'])."\n";if(class_exists('Logger'))Logger::error('Billing delta preflight failed',['message'=>$e->getMessage()]);exit(1);}
