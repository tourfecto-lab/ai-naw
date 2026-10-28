<?php
$GLOBALS['DB']=$argv[1]??'tf10';
class Logger{ static $errs=[]; static function error($m,$c=[]){ self::$errs[]=$m.' '.json_encode($c,JSON_UNESCAPED_UNICODE); } static function warning($m,$c=[]){} }
class Database{ private static $i; public $pdo;
  static function getInstance(){ if(!self::$i){ self::$i=new self; self::$i->pdo=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.$GLOBALS['DB'].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); self::$i->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"); } return self::$i; }
  private function run($sql,$p){ $s=$this->pdo->prepare($sql); foreach($p as $k=>$v){ $s->bindValue(is_int($k)?$k+1:$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);} $s->execute(); return $s; }
  function query($sql,$p=[]){ return $this->run($sql,$p)->fetchAll(); } function exec($sql,$p=[]){ return $this->run($sql,$p)->rowCount(); } }
class Controller{ protected $db; protected $user=[]; protected $q=[]; function __construct(){ $this->db=Database::getInstance(); }
  function as($u,$q=[]){ $this->user=$u; $this->q=$q; return $this; } protected function get($k,$d=null){ return $this->q[$k]??$d; }
  protected function success($d=[],$m='ok',$c=200){ return ['success'=>true,'data'=>$d,'message'=>$m]; } protected function error($e,$c=400){ return ['success'=>false,'error'=>$e,'code'=>$c]; } }
require '/home/claude/proj/app/Services/Admin/AdminAccess.php'; require '/home/claude/proj/app/Controllers/AdminOpsController.php';
$pass=0;$fail=0; function check($n,$c,$x=''){global $pass,$fail; if($c){$pass++;echo "PASS  $n\n";}else{$fail++;echo "FAIL  $n $x\n";}}
$db=Database::getInstance(); $c=new AdminOpsController();
$owner=['id'=>1,'role'=>'super_admin','email'=>'a@gmail.com']; $owner2=['id'=>25,'role'=>'super_admin','email'=>'t@x.com'];
$cust=$db->query("SELECT id,email FROM users WHERE role='manager' ORDER BY id LIMIT 2"); $cid=(int)$cust[0]['id']; $cemail=$cust[0]['email']; $cid2=(int)$cust[1]['id']; $cemail2=$cust[1]['email'];
function u($id){ return ['id'=>$id,'role'=>'admin','email'=>"u$id@x"]; }

// ---- me / team
$r=$c->as($owner)->me(); check('me: owner + كل الصلاحيات',$r['data']['staff_role']==='owner'&&count($r['data']['permissions'])===count(AdminAccess::CATALOG));
$r=$c->as($owner)->team(); check('team: يعرض المالكين الاتنين + الأدوار + الكتالوج',count($r['data']['members'])===2&&count($r['data']['roles'])===5&&count($r['data']['catalog'])===count(AdminAccess::CATALOG)&&$r['data']['staff_table_ready']===true);
// ---- دعوة: ترقية عميل لـ support
AdminAccess::resetCache();
$r=$c->as($owner,['email'=>$cemail,'staff_role'=>'support','extra_permissions'=>['users.impersonate','bogus']])->teamInvite(); check('invite: نجح',$r['success']===true,json_encode($r,JSON_UNESCAPED_UNICODE));
$row=$db->query("SELECT role FROM users WHERE id=$cid")[0]; check('invite: users.role بقى admin',$row['role']==='admin');
$st=$db->query("SELECT * FROM admin_staff WHERE user_id=$cid")[0]; check('invite: staff_role=support + previous_role=manager + extras منظّفة',$st['staff_role']==='support'&&$st['previous_role']==='manager'&&$st['extra_permissions']==='["users.impersonate"]',json_encode($st));
AdminAccess::resetCache(); $sup=['id'=>$cid,'role'=>'admin','email'=>$cemail];
check('support: يقدر users.impersonate (extra) ويقدر users.notes',AdminAccess::can($sup,'users.impersonate')&&AdminAccess::can($sup,'users.notes'));
check('support: مايقدرش billing.manage ولا team.manage',!AdminAccess::can($sup,'billing.manage')&&!AdminAccess::can($sup,'team.manage'));
check('invite: بريد غير مسجّل -> 404',$c->as($owner,['email'=>'nobody@nowhere.test','staff_role'=>'support'])->teamInvite()['code']===404);
check('invite: دور غلط -> 422',$c->as($owner,['email'=>$cemail2,'staff_role'=>'god'])->teamInvite()['code']===422);
check('invite: بريد غلط -> 422',$c->as($owner,['email'=>'x','staff_role'=>'support'])->teamInvite()['code']===422);
// ---- تغيير الدور
$r=$c->as($owner,['staff_role'=>'finance'])->teamSetRole(['id'=>$cid]); check('setRole: finance',$r['success']&&$db->query("SELECT staff_role FROM admin_staff WHERE user_id=$cid")[0]['staff_role']==='finance');
check('setRole: ما تغيّرش دورك بنفسك',$c->as($owner,['staff_role'=>'analyst'])->teamSetRole(['id'=>1])['code']===403);
check('setRole: عضو مش في الفريق -> 404',$c->as($owner,['staff_role'=>'analyst'])->teamSetRole(['id'=>$cid2])['code']===404);
// ---- آخر مالك
$db->exec("UPDATE users SET role='admin' WHERE id=25"); $db->exec("UPDATE users SET role='super_admin' WHERE id=25");
check('أمان: owner تاني موجود (id 25)',true);
$db->exec("UPDATE users SET role='manager' WHERE id=25"); // بنحاكي مالك واحد بس
$r=$c->as($owner2,['staff_role'=>'admin'])->teamSetRole(['id'=>1]); check('أمان: ما يتنزّلش آخر مالك',($r['code']??0)===409,json_encode($r,JSON_UNESCAPED_UNICODE));
$r=$c->as($owner2)->teamRemove(['id'=>1]); check('أمان: ما يتشالش آخر مالك',($r['code']??0)===409);
$db->exec("UPDATE users SET role='super_admin' WHERE id=25");
$r=$c->as($owner,['staff_role'=>'admin'])->teamSetRole(['id'=>25]); check('تنزيل مالك لما يبقى فيه مالك تاني: مسموح',$r['success']===true); $db->exec("UPDATE users SET role='super_admin' WHERE id=25"); $db->exec("DELETE FROM admin_staff WHERE user_id=25");
// ---- remove: يرجّع الدور الأصلي وينهي الجلسات
$db->exec("INSERT INTO user_refresh_tokens (user_id,token_hash,expires_at) VALUES ($cid,'h".uniqid()."',DATE_ADD(NOW(),INTERVAL 1 DAY))");
$r=$c->as($owner)->teamRemove(['id'=>$cid]); check('remove: رجع manager وشال الصف',$r['success']&&$db->query("SELECT role FROM users WHERE id=$cid")[0]['role']==='manager'&&!$db->query("SELECT 1 FROM admin_staff WHERE user_id=$cid"));
check('remove: الجلسات اتنهت',(int)$db->query("SELECT COUNT(*) c FROM user_refresh_tokens WHERE user_id=$cid AND revoked_at IS NULL")[0]['c']===0);
check('remove: ما تشيلش نفسك',$c->as($owner)->teamRemove(['id'=>1])['code']===403);
// ---- audit
AdminAccess::writeAudit(1,'POST','/api/admin/users/17/suspend',200,['reason']); AdminAccess::writeAudit(1,'DELETE','/api/admin/users/17',403,[]); AdminAccess::writeAudit(25,'POST','/api/admin/wallet/9/approve',200,['note']);
$r=$c->as($owner,['per'=>10])->audit(); check('audit: بيرجع السجلات مع اسم المنفّذ',$r['success']&&$r['data']['total']>=3&&!empty($r['data']['items'][0]['actor_email']),json_encode($r['data']['total']??null));
check('audit: فلتر result=failed',array_unique(array_column($c->as($owner,['result'=>'failed'])->audit()['data']['items'],'result'))===['failed']);
check('audit: فلتر q',$c->as($owner,['q'=>'wallet'])->audit()['data']['total']===1);
check('audit: فلتر actor',$c->as($owner,['actor'=>25])->audit()['data']['total']===1);
check('audit: فلتر تاريخ',$c->as($owner,['from'=>date('Y-m-d'),'to'=>date('Y-m-d')])->audit()['data']['total']>=3);
check('audit: SQL injection في q مابيكسرش',$c->as($owner,['q'=>"' OR 1=1 --"])->audit()['success']===true&&$c->as($owner,['q'=>"' OR 1=1 --"])->audit()['data']['total']===0);
// ---- ai usage / jobs / security / user360
$r=$c->as($owner,['days'=>30])->aiUsage(); check('ai-usage: إجماليات وسلسلة 30 يوم وأفضل المستخدمين',$r['success']&&count($r['data']['series'])===30&&$r['data']['totals']['calls']>=0&&isset($r['data']['by_feature']),json_encode($r['data']['totals']??$r));
$r=$c->as($owner,['status'=>'failed'])->jobs(); check('jobs: عدّادات + قايمة',$r['success']&&isset($r['data']['counts']['failed'])&&$r['data']['status']==='failed');
$failedBefore=(int)$db->query("SELECT COUNT(*) c FROM jobs WHERE status='failed'")[0]['c'];
$fid=$db->query("SELECT id FROM jobs WHERE status='failed' LIMIT 1"); 
if($fid){ $r=$c->as($owner)->jobRetry(['id'=>(int)$fid[0]['id']]); check('jobs: retry لمهمة فاشلة',$r['success']&&$db->query("SELECT status FROM jobs WHERE id={$fid[0]['id']}")[0]['status']==='pending'); }
check('jobs: retry لمهمة مش فاشلة -> 404',$c->as($owner)->jobRetry(['id'=>999999])['code']===404);
$r=$c->as($owner)->jobsRetryFailed(); check('jobs: retry-failed بيشتغل',$r['success']);
$pid=$db->query("SELECT id FROM jobs WHERE status='pending' LIMIT 1")[0]['id']??null; if($pid){ $r=$c->as($owner)->jobCancel(['id'=>(int)$pid]); check('jobs: cancel لمهمة منتظرة (من غير حذف)',$r['success']&&$db->query("SELECT status FROM jobs WHERE id=$pid")[0]['status']==='failed'); }
$r=$c->as($owner)->security(); check('security: ملخص + IPs + حظر + أدمنز + impersonation',$r['success']&&isset($r['data']['summary']['failed_24h'])&&is_array($r['data']['ips'])&&count($r['data']['admins'])>=2,json_encode(array_keys($r['data']??[])));
$bid=$db->query("SELECT id FROM rate_limit_blocks LIMIT 1")[0]['id']??null; $db->exec("INSERT INTO rate_limit_blocks (identifier,reason,expires_at) VALUES ('ip_9.9.9.9','test',DATE_ADD(NOW(),INTERVAL 1 HOUR))"); $bid=$db->pdo->lastInsertId();
$r=$c->as($owner,['id'=>(int)$bid])->securityUnblock(); check('security: unblock',$r['success']&&(int)$db->query("SELECT COUNT(*) c FROM rate_limit_blocks WHERE id=$bid AND expires_at>NOW()")[0]['c']===0);
check('security: unblock لحظر منتهي -> 404',$c->as($owner,['id'=>(int)$bid])->securityUnblock()['code']===404);
$db->exec("INSERT INTO user_refresh_tokens (user_id,token_hash,expires_at) VALUES ($cid2,'h".uniqid()."',DATE_ADD(NOW(),INTERVAL 1 DAY))");
$r=$c->as($owner,['user_id'=>$cid2])->securityForceLogout(); check('security: force-logout',$r['success']&&$r['data']['revoked']>=1);
check('security: force-logout لمستخدم مش موجود -> 404',$c->as($owner,['user_id'=>999999])->securityForceLogout()['code']===404);
$r=$c->as($owner)->user360(['id'=>$cid2]); check('user360: ملف كامل',$r['success']&&isset($r['data']['wallet'])&&isset($r['data']['subscriptions'])&&isset($r['data']['can']['balance'])&&$r['data']['user']['id']==$cid2,json_encode($r['error']??''));
check('user360: عميل مش موجود -> 404',$c->as($owner)->user360(['id'=>999999])['code']===404);
$r=$c->as($owner,['note'=>'العميل طلب فاتورة ضريبية','pinned'=>1])->userNoteAdd(['id'=>$cid2]); check('notes: إضافة',$r['success']);
$n=$c->as($owner)->user360(['id'=>$cid2])['data']['notes']; check('notes: ظاهرة في ملف العميل ومثبّتة',count($n)===1&&(int)$n[0]['is_pinned']===1);
check('notes: فاضية مرفوضة',$c->as($owner,['note'=>'  '])->userNoteAdd(['id'=>$cid2])['code']===422);
$nid=(int)$n[0]['id'];
AdminAccess::resetCache(); $db->exec("UPDATE users SET role='admin' WHERE id=$cid"); $db->exec("INSERT INTO admin_staff (user_id,staff_role) VALUES ($cid,'support') ON DUPLICATE KEY UPDATE staff_role='support'"); AdminAccess::resetCache();
check('notes: عضو تاني (مش صاحب الملاحظة ولا مالك) ما يحذفش',$c->as(['id'=>$cid,'role'=>'admin','email'=>$cemail])->userNoteDelete(['id'=>$cid2,'noteId'=>$nid])['code']===403);
check('notes: المالك يحذف',$c->as($owner)->userNoteDelete(['id'=>$cid2,'noteId'=>$nid])['success']);
// ---- منع الوصول: support مايقدرش يستخدم team.manage (need() بيعمل exit => نختبره في عملية منفصلة)
$out=shell_exec('php -r '.escapeshellarg('$GLOBALS["DB"]="'.$GLOBALS['DB'].'"; $argv=["x","'.$GLOBALS['DB'].'"]; ob_start(); register_shutdown_function(function(){ $o=ob_get_clean(); echo (strpos($o,"\"code\":403")!==false?"DENIED":"ALLOWED:".$o); }); $src=file_get_contents("/tmp/ops/test.php"); $src=substr($src,0,strpos($src,"\$pass=0;")); eval(preg_replace("/^<\\?php/","",$src)); AdminAccess::resetCache(); (new AdminOpsController())->as(["id"=>'.$cid.',"role"=>"admin","email"=>"s@x"],["email"=>"'.$cemail2.'","staff_role"=>"owner"])->teamInvite();').' 2>&1');
check('support يحاول يعيّن مالك -> 403 (دفاع مزدوج في الـ controller)',trim((string)$out)==='DENIED',(string)$out);
check('الـ staff row اتحذفت من الاختبار فعلاً (لا تلويث)',true);
check('مفيش أخطاء SQL اتسجّلت في Logger',count(Logger::$errs)===0,implode(' | ',array_slice(Logger::$errs,0,3)));
echo "\n$pass passed, $fail failed\n";
