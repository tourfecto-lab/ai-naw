<?php
class Logger{static function error($m,$c=[]){} static function warning($m,$c=[]){}}
class Cache{ function get($k){return null;} function set($k,$v,$t){} }
function env($k){ return getenv($k)===false?null:getenv($k); }
class Database{ public $sites=[]; public $rows=[];
  function query($sql,$b=[]){ if(stripos($sql,'is_connected = 1')!==false) return $this->sites; if(stripos($sql,'SELECT main_url FROM websites')!==false) return $this->rows; return []; }
  function exec($sql,$b=[]){ $this->ran=true; } public $ran=false; }
putenv('APP_URL=https://tourfecto.pro'); $_SERVER['SERVER_NAME']='tourfecto.pro';
$pass=0;$fail=0; function check($n,$c,$x=''){global $pass,$fail; if($c){$pass++;echo "PASS  $n\n";}else{$fail++;echo "FAIL  $n $x\n";}}
class Controller{ protected $db; function __construct(){ $this->db=$GLOBALS['db']; } }
$which=$argv[1]; // new | old
eval(preg_replace('/^<\?php/','',file_get_contents($which==='old'?'/tmp/orig/app/Services/Seo/SeoProxyService.php':'/home/claude/proj/app/Services/Seo/SeoProxyService.php'),1));
$db=new Database();
$db->sites=[['id'=>11,'main_url'=>'https://tourfecto.pro','company_name'=>'Tourfecto ai','auto_pilot_mode'=>'conservative','indexnow_key'=>null],
            ['id'=>20,'main_url'=>'https://hotel-example.com','company_name'=>'Hotel','auto_pilot_mode'=>'conservative','indexnow_key'=>null]];
$svc=new SeoProxyService($db);
if($which==='old'){
  echo "== السلوك القديم (الملف الأصلي):\n";
  check('Host=tourfecto.pro بيتمسك بالـ proxy (ده سبب الـ timeout)', $svc->findByHost('tourfecto.pro')!==null);
  exit(0);
}
echo "== بعد الإصلاح:\n";
foreach(['tourfecto.pro','TOURFECTO.PRO:443','www.tourfecto.pro','app.tourfecto.pro'] as $h) check("المنصة نفسها ($h) ما تتمسكش", $svc->findByHost($h)===null);
check('دومين عميل حقيقي لسه بيتمسك', ($svc->findByHost('hotel-example.com')['id']??0)===20);
check('subdomain للعميل لسه بيتمسك', ($svc->findByHost('blog.hotel-example.com')['id']??0)===20);
check('www للعميل لسه بيتمسك', ($svc->findByHost('www.hotel-example.com')['id']??0)===20);
$t=microtime(true); $r=$svc->renderSite(['id'=>11,'main_url'=>'https://tourfecto.pro','indexnow_key'=>null],'/integrations'); $ms=(microtime(true)-$t)*1000;
check('origin = المنصة نفسها -> رد فوري 421 بدل 20 ثانية timeout', $r['status']===421 && $ms<200, round($ms).'ms');
// ---- ربط دومين المنصة مرفوض، وموقع عميل عادي مسموح
require '/home/claude/proj/app/Services/AutoSeo/AutoSeoEmbedService.php';
$db->rows=[['main_url'=>'https://tourfecto.pro']];
$e=new AutoSeoEmbedService($db); $threw=false; $msg='';
try{ $e->connectWebsite(31,11); }catch(Exception $ex){ $threw=true; $msg=$ex->getMessage(); }
check('ربط https://tourfecto.pro بيترفض برسالة واضحة ومفيش UPDATE اتنفّذ', $threw && !$db->ran && mb_strpos($msg,'دومين المنصة')!==false);
$db->rows=[['main_url'=>'https://hotel-example.com']]; $db->ran=false;
try{ $r=$e->connectWebsite(31,20); $okc=!empty($r['embed_token']); }catch(Throwable $ex){ $okc=false; echo $ex->getMessage()."\n"; }
check('ربط موقع عميل عادي لسه شغّال', $okc && $db->ran);
// ---- هيدر قطع الحلقات + المنصة
require '/home/claude/proj/app/Controllers/SeoProxyController.php';
$_SERVER['HTTP_HOST']='tourfecto.pro'; unset($_SERVER['HTTP_X_TOURFECTO_PROXY']); $c=new SeoProxyController();
check('Host المنصة -> shouldHandleCname = false', $c->shouldHandleCname()===false);
$_SERVER['HTTP_HOST']='hotel-example.com'; check('Host عميل -> shouldHandleCname = true', $c->shouldHandleCname()===true);
$_SERVER['HTTP_X_TOURFECTO_PROXY']='1'; check('طلب جاي من proxy تاني -> false (قطع الحلقة)', $c->shouldHandleCname()===false);
echo "\n$pass passed, $fail failed\n";
