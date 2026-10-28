<?php
require_once __DIR__.'/bootstrap.php';
$pdo=Database::getInstance()->getConnection();
$q=$pdo->query("SELECT id,user_id FROM crm_sequence_enrollments WHERE status='active' AND next_run_at<=NOW() ORDER BY next_run_at,id LIMIT 100");
$service=new CrmSequenceService();$failed=0;
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){try{$e=(new CrmSequenceEnrollment())->findOwned((int)$row['user_id'],(int)$row['id']);if($e)$service->processEnrollment((int)$row['user_id'],$e);}catch(Throwable $e){$failed++;Logger::warning('CRM sequence step needs review',['enrollment_id'=>$row['id']]);}}
fwrite(STDOUT,'CRM sequences checked; review failures: '.$failed.PHP_EOL);
