<?php
class Logger{static function error($m,$c=[]){}}
class Database{ static $i; public $staff=[]; public $audit=[];
  static function getInstance(){ return self::$i ??= new self; }
  function query($sql,$b=[]){ if(strpos($sql,'admin_staff')!==false){ $r=$this->staff[$b[0]]??null; return $r?[$r]:[]; } return []; }
  function exec($sql,$b=[]){ $this->audit[]=$b; return 1; } }
require '/home/claude/proj/app/Services/Admin/AdminAccess.php';
require '/home/claude/proj/app/Middleware/AdminMiddleware.php';
require '/home/claude/proj/app/Middleware/BillingAdminMiddleware.php';
require '/home/claude/proj/app/Middleware/BillingViewerMiddleware.php';
$pass=0;$fail=0; function check($n,$c,$x=''){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "FAIL  $n $x\n";} }
$db=Database::getInstance();
$owner=['id'=>1,'role'=>'super_admin']; $adm=['id'=>2,'role'=>'admin']; $fin=['id'=>3,'role'=>'admin']; $sup=['id'=>4,'role'=>'admin']; $ana=['id'=>5,'role'=>'admin']; $cust=['id'=>9,'role'=>'manager'];
$db->staff=[3=>['staff_role'=>'finance','extra_permissions'=>null],4=>['staff_role'=>'support','extra_permissions'=>'["users.impersonate","bogus.perm"]'],5=>['staff_role'=>'analyst','extra_permissions'=>'[]']];
// ---- مصفوفة الصلاحيات
$M=[
 'owner'=>[$owner,['team.manage'=>1,'billing.manage'=>1,'settings.manage'=>1,'users.delete'=>1]],
 'admin (بدون سجل = متوافق مع القديم)'=>[$adm,['team.manage'=>0,'billing.manage'=>1,'settings.manage'=>1,'users.suspend'=>1,'team.view'=>1]],
 'finance'=>[$fin,['billing.manage'=>1,'subscriptions.manage'=>1,'users.view'=>1,'users.edit'=>0,'users.suspend'=>0,'settings.manage'=>0,'system.manage'=>0,'team.manage'=>0,'billing.settings'=>0]],
 'support'=>[$sup,['users.edit'=>1,'users.notes'=>1,'messages.manage'=>1,'users.impersonate'=>1,'billing.manage'=>0,'users.delete'=>0,'settings.manage'=>0,'users.suspend'=>0]],
 'analyst'=>[$ana,['analytics.view'=>1,'users.view'=>1,'users.edit'=>0,'billing.manage'=>0,'system.manage'=>0,'messages.manage'=>0,'security.manage'=>0]],
 'عميل عادي (role=manager)'=>[$cust,['analytics.view'=>0,'billing.manage'=>0,'users.view'=>0]],
];
foreach($M as $name=>[$u,$exp]) foreach($exp as $perm=>$want) check("$name -> $perm",AdminAccess::can($u,$perm)===(bool)$want);
check('extra_permissions غير الصالحة بتتجاهل',!in_array('bogus.perm',AdminAccess::permissionsFor($sup)));
// ---- قواعد المسارات
$R=[
 ['GET','/admin','analytics.view'],['GET','/admin/users','users.view'],['GET','/admin/plans','billing.view'],['GET','/admin/settings','settings.manage'],['GET','/admin/team','team.view'],['GET','/admin/audit','audit.view'],
 ['GET','/api/admin/users','users.view'],['PUT','/api/admin/users/5','users.edit'],['DELETE','/api/admin/users/5','users.delete'],['POST','/api/admin/users/5/suspend','users.suspend'],['POST','/api/admin/users/5/reset-2fa','users.reset_security'],
 ['POST','/api/admin/users/5/add-balance','billing.manage'],['POST','/api/admin/wallet/7/approve','billing.manage'],['POST','/api/admin/wallet/cards/generate','billing.manage'],['GET','/api/admin/wallet/cards','billing.manage'],
 ['GET','/api/admin/wallet/stats','billing.view'],['PUT','/api/admin/wallet/settings','billing.settings'],['GET','/api/admin/wallet/settings','billing.settings'],['PUT','/api/admin/wallet/usage-pricing/3','billing.settings'],
 ['POST','/api/admin/subscriptions/4/cancel','subscriptions.manage'],['GET','/api/admin/subscriptions','subscriptions.view'],['PUT','/api/admin/plans/2','plans.manage'],
 ['PUT','/api/admin/system-settings','settings.manage'],['GET','/api/admin/system-settings','settings.manage'],['POST','/api/admin/broadcast','broadcast.send'],['POST','/api/admin/system/cache/clear','system.manage'],
 ['POST','/api/admin/ops/team/6/role','team.manage'],['GET','/api/admin/ops/team','team.view'],['POST','/api/admin/ops/jobs/3/retry','system.manage'],['POST','/api/admin/ops/security/unblock','security.manage'],
 ['POST','/api/admin/pro/user/5/notes','users.notes'],['POST','/api/admin/something-new','system.manage'],['GET','/api/admin/something-new','analytics.view'],['GET','/dashboard',null],['GET','/api/ai/analyze',null],
];
foreach($R as [$m,$p,$exp]) check("$m $p",AdminAccess::requiredFor($m,$p)===$exp,var_export(AdminAccess::requiredFor($m,$p),true));
// ---- كل مسارات /api/admin في المشروع لازم يكون ليها صلاحية معروفة (مفيش مسار بيعدّي بدون قاعدة)
$api=file_get_contents('/home/claude/proj/app/routes/api.php'); $web=file_get_contents('/home/claude/proj/app/routes/web.php');
preg_match_all("#\\\$router->(get|post|put|delete)\\('([^']*)'#",substr($api,strpos($api,"group('/api/admin'")),$mm,PREG_SET_ORDER);
$cnt=0;$unk=[];
foreach($mm as $x){ $p='/api/admin'.$x[2]; if($x[2]==='' )continue; $p=preg_replace('#\{[a-z]+\}#','5',$p); $r=AdminAccess::requiredFor($x[1],$p); $cnt++; if($r===null||!isset(AdminAccess::CATALOG[$r])) $unk[]="$x[1] $p"; }
check("كل $cnt مسار /api/admin له صلاحية معرّفة",!$unk,implode(', ',$unk));
// ---- Middleware: العميل ممنوع، الفوترة مقفولة على manager
$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/api/admin/wallet/7/approve'; $_SERVER['HTTP_ACCEPT']='application/json';
foreach(['BillingAdminMiddleware','BillingViewerMiddleware'] as $mw){ foreach(['manager','agent','client'] as $role){ $_SESSION['user']=['id'=>9,'role'=>$role]; $o=new $mw; $r=$o->handle(); check("$mw يرفض role=$role",is_array($r)&&($r['code']??0)===403); } }
$_SESSION['user']=$owner; $r=(new BillingAdminMiddleware)->handle(); check('BillingAdmin يسمح للـ owner',$r===null);
$_SESSION['user']=$sup; $r=(new BillingAdminMiddleware)->handle(); check('BillingAdmin يرفض support (ما عندوش billing.manage)',is_array($r)&&$r['code']===403&&$r['required_permission']==='billing.manage');
$_SESSION['user']=$fin; $r=(new BillingAdminMiddleware)->handle(); check('BillingAdmin يسمح لـ finance',$r===null);
// ---- Audit
$db->audit=[]; AdminAccess::writeAudit(2,'POST','/api/admin/users/17/suspend',200,['reason']); AdminAccess::writeAudit(2,'DELETE','/api/admin/users/17',403,[]);
check('audit: سطرين',count($db->audit)===2); check('audit: action طبّع id',$db->audit[0][1]==='POST /api/admin/users/{id}/suspend'); check('audit: object_id',$db->audit[0][3]==='17'&&$db->audit[0][2]==='users'); check('audit: failed على 403',$db->audit[1][4]==='failed'&&$db->audit[0][4]==='success');
check('audit: ميتسجلش قيم الحقول (أسماء بس)',strpos($db->audit[0][5],'"fields":["reason"]')!==false);
echo "\n$pass passed, $fail failed\n";
