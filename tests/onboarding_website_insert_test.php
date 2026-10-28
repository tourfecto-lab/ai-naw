<?php
$db=$argv[1]; $mode=$argv[2]??'';
class Logger{ static $e=[]; static function error($m,$c=[]){ self::$e[]=$m.' '.json_encode($c,JSON_UNESCAPED_UNICODE);} static function warning($m,$c=[]){} }
class Database{ private static $i; public $pdo; static function getInstance(){ global $db; if(!self::$i){ self::$i=new self; self::$i->pdo=new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$db;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); self::$i->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"); } return self::$i; }
  function query($sql,$p=[]){ $s=$this->pdo->prepare($sql); foreach($p as $k=>$v){ $s->bindValue(is_int($k)?$k+1:$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);} $s->execute();
     if(preg_match('/^\s*INSERT/i',$sql)) return (int)$this->pdo->lastInsertId(); return $s->fetchAll(); }
  function exec($sql,$p=[]){ $s=$this->pdo->prepare($sql); foreach($p as $k=>$v){ $s->bindValue(is_int($k)?$k+1:$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);} $s->execute(); return $s->rowCount(); } }
class Controller{ protected $db; protected $data=[]; protected $user=['id'=>33]; function __construct(){ $this->db=Database::getInstance(); } function setData($d){$this->data=$d;} protected function get($k,$d=null){ return $this->data[$k]??$d; } }
function current_lang(){ return 'ar'; }
$src=preg_replace('/^<\?php/','',file_get_contents('/home/claude/proj/app/Controllers/OnboardingController.php'),1);
eval($src);
$r=new ReflectionClass('OnboardingController'); $o=$r->newInstanceWithoutConstructor();
$p=new ReflectionProperty('Controller','db'); $p->setAccessible(true); $p->setValue($o,Database::getInstance());
$d=new ReflectionProperty('Controller','data'); $d->setAccessible(true); $d->setValue($o,['business_name'=>'Luxor Tours','industry'=>'tourism','target_language'=>'ar']);
$m=$r->getMethod('findOrCreateWebsite'); $m->setAccessible(true);
try{
  $id=$m->invoke($o,33,'https://onboard-test-'.uniqid().'.example.com',[]);
  $row=Database::getInstance()->query('SELECT id,main_url,industry_niche,domain FROM websites WHERE id=?',[$id])[0];
  echo "OK created website #$id industry_niche={$row['industry_niche']}\n";
  // تحديث موقع موجود (مسار UPDATE)
  $id2=$m->invoke($o,33,$row['main_url'],[]); echo ($id2===$id?"OK update path same id\n":"FAIL update created new id\n");
  Database::getInstance()->exec('DELETE FROM websites WHERE id=?',[$id]);
}catch(Throwable $e){ echo "FAIL ".get_class($e).": ".substr($e->getMessage(),0,170)."\n"; }
echo count(Logger::$e)?("LOGGER: ".implode(' | ',array_slice(Logger::$e,0,2))."\n"):'';
