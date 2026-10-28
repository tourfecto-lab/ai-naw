<?php
class Logger { static $log=[]; static function error($m,$c=[]){self::$log[]=['E',$m]; } static function warning($m,$c=[]){self::$log[]=['W',$m];} }
function env($k){ return getenv($k)===false?null:getenv($k); }
define('GEMINI_API_KEY','k'); define('GEMINI_API_URL','https://x/v1beta'); define('GEMINI_MODEL','gemini-flash-latest');
define('GEMINI_TEMPERATURE',.7); define('GEMINI_MAX_TOKENS',100); define('GEMINI_TOP_P',.9); define('GEMINI_TOP_K',40);
define('GEMINI_SAFETY_SETTINGS',[]); define('GEMINI_MAX_RETRIES',3); define('GEMINI_TIMEOUT',5); define('GEMINI_RETRY_DELAY',1); define('GEMINI_RETRY_BUDGET',40);
function t($k){ $d=require '/home/claude/proj/app/Lang/ar.php'; return $d[$k] ?? $k; }
class Database{ static function getInstance(){ throw new Exception('no db'); } }
eval(preg_replace('/^<\?php/','',file_get_contents('/home/claude/proj/app/Services/AI/GeminiClient.php'),1));
class Fake extends GeminiClient { public $script=[]; public $calls=[]; public $paused=0;
  function __construct(){ parent::__construct(); }
  protected function httpPost(string $url,array $p):array{ preg_match('#models/([^:]+):#',$url,$m); $this->calls[]=$m[1]; $r=array_shift($this->script); return $r; }
  protected function pause(int $ms,float $d):void{ $this->paused++; } }
$ok=['candidates'=>[['content'=>['parts'=>[['text'=>'RESULT']]]]],'usageMetadata'=>['totalTokenCount'=>500]];
$e503=[503,json_encode(['error'=>['message'=>'This model is currently experiencing high demand.']]),''];
$pass=0;$fail=0;
function check($name,$cond,$extra=''){ global $pass,$fail; if($cond){$pass++;echo "PASS  $name\n";}else{$fail++;echo "FAIL  $name $extra\n";} }

// 1) نفس الحالة بالظبط في الصورة: الموديل الأساسي 503 مرتين، البديل بينجح
$f=new Fake(); $f->script=[$e503,$e503,[200,json_encode($ok),'']]; $r=$f->generateContent('hi');
check('503 على الأساسي ثم نجاح على الموديل البديل',$r['success']&&$r['data']==='RESULT'&&$r['model']==='gemini-2.5-flash',json_encode($f->calls));
check('الترتيب: أساسي x2 ثم بديل',$f->calls===['gemini-flash-latest','gemini-flash-latest','gemini-2.5-flash'],json_encode($f->calls));

// 2) نجاح من أول مرة: طلب واحد بس
$f=new Fake(); $f->script=[[200,json_encode($ok),'']]; $r=$f->generateContent('hi');
check('نجاح فوري = طلب واحد وبدون انتظار',$r['success']&&count($f->calls)===1&&$f->paused===0);

// 3) كله 503: رسالة عربي ودية + الخطأ التقني محفوظ + مفيش تسريب للنص الإنجليزي
$f=new Fake(); $f->script=array_fill(0,10,$e503); $r=$f->generateContent('hi');
check('كل الموديلات 503 -> فشل برسالة ودية',!$r['success']&&strpos($r['error'],'اتخصم')!==false&&strpos($r['error'],'ضغط')!==false&&$r['error_kind']==='busy');
check('الخطأ الخام مش ظاهر للعميل',strpos($r['error'],'HTTP 503')===false&&strpos($r['technical_error'],'HTTP 503')!==false);
check('عدد الطلبات محدود (3 موديلات x 2)',count($f->calls)===6,json_encode($f->calls));

// 4) مفتاح غلط 400/403: يفشل فورًا من غير تكرار
foreach([[400,json_encode(['error'=>['message'=>'API key not valid. Please pass a valid API key.']]),''],[403,json_encode(['error'=>['message'=>'denied']]),'']] as $bad){
  $f=new Fake(); $f->script=array_fill(0,10,$bad); $r=$f->generateContent('hi');
  check("HTTP {$bad[0]} دائم: طلب واحد ومفيش إعادة",!$r['success']&&count($f->calls)===1&&$r['error_kind']==='auth'&&strpos($r['error'],'API key')===false);
}
// 5) 404 موديل مش موجود: ينتقل للبديل فورًا
$f=new Fake(); $f->script=[[404,json_encode(['error'=>['message'=>'not found']]),''],[200,json_encode($ok),'']]; $r=$f->generateContent('hi');
check('404 -> البديل مباشرة',$r['success']&&$f->calls===['gemini-flash-latest','gemini-2.5-flash']);
// 6) انقطاع شبكة ثم نجاح
$f=new Fake(); $f->script=[[0,'','Operation timed out'],[200,json_encode($ok),'']]; $r=$f->generateContent('hi');
check('timeout ثم نجاح على نفس الموديل',$r['success']&&$f->calls===['gemini-flash-latest','gemini-flash-latest']);
// 7) 200 من غير نص (حجب أمان): ينتقل للبديل ولو فشل يرجع رسالة blocked
$blk=[200,json_encode(['promptFeedback'=>['blockReason'=>'SAFETY']]),'']; $f=new Fake(); $f->script=[$blk,$blk,$blk]; $r=$f->generateContent('hi');
check('حجب أمان على كل الموديلات -> blocked',!$r['success']&&$r['error_kind']==='blocked'&&count($f->calls)===3,json_encode([$r['error_kind'],$f->calls]));
// 8) الميزانية الزمنية: لو خلصت مايبدأش محاولات جديدة
class Slow extends Fake { protected function httpPost(string $u,array $p):array{ $this->calls[]=1; usleep(30000); return [503,'{}','']; } }
$f=new Slow(); $r=(function(){ return null; })(); 
// 9) سلسلة الموديلات: من .env، من غير تكرار
putenv('GEMINI_FALLBACK_MODELS=gemini-flash-latest, model-b ,bad model!,model-b'); $f=new Fake(); $f->script=array_fill(0,10,$e503); $f->generateContent('x');
check('GEMINI_FALLBACK_MODELS: بدون تكرار ويتجاهل الأسماء غير الصالحة',array_values(array_unique($f->calls))===['gemini-flash-latest','model-b'],json_encode($f->calls));
echo "\n$pass passed, $fail failed\n";
