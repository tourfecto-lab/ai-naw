<?php
/** Pure-function tests for GrowthSignals (season, trip focus, post allocation). No DB or AI calls. */
require_once __DIR__.'/../app/Services/Growth/GrowthSignals.php';
function gsCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL; }
function gsReject(callable $fn,string $label): void { try{$fn();}catch(InvalidArgumentException $e){echo 'PASS '.$label.PHP_EOL;return;}throw new RuntimeException('Expected rejection: '.$label); }

// موسم شتوي: نوفمبر–فبراير عالي، الصيف هادي، سنتين بيانات
$profile=['01'=>30,'02'=>25,'03'=>18,'04'=>14,'05'=>6,'06'=>3,'07'=>3,'08'=>4,'09'=>8,'10'=>15,'11'=>28,'12'=>32];
$monthly=[];foreach([2024,2025] as $y)foreach($profile as $m=>$n)$monthly[]=['month'=>"$y-$m",'bookings'=>$n,'travelers'=>$n*2];
$peak=GrowthSignals::season($monthly,'2026-12');
gsCheck($peak['level']==='peak'&&$peak['index']>1.2,'December detected as peak season');
gsCheck(GrowthSignals::season($monthly,'2026-07')['level']==='low','July detected as low season');
gsCheck(GrowthSignals::season($monthly,'2026-04')['level']==='shoulder','April detected as shoulder season');
gsCheck(in_array('12',$peak['peak_months'],true)&&in_array('01',$peak['peak_months'],true)&&!in_array('07',$peak['peak_months'],true),'peak months listed as zero-padded strings');
gsCheck(GrowthSignals::season([['month'=>'2026-01','bookings'=>3]],'2026-02')['level']==='unknown','too few bookings stays unknown (no guessing)');
gsCheck(GrowthSignals::season(array_merge($monthly,[['month'=>'2026-12','bookings'=>999]]),'2026-12')['index']===$peak['index'],'target and future months are excluded from history');

// الرحلات
$trips=[
 ['id'=>1,'name'=>'Nile Cruise','recent'=>20,'previous'=>8,'upcoming'=>9],
 ['id'=>2,'name'=>'Luxor Day Tour','recent'=>12,'previous'=>14,'upcoming'=>10],
 ['id'=>3,'name'=>'Balloon','recent'=>1,'previous'=>2,'upcoming'=>0],
 ['id'=>4,'name'=>'New Desert Trip','recent'=>0,'previous'=>0,'upcoming'=>0],
];
$focus=GrowthSignals::focus($trips,'peak');$by=array_column($focus,null,'id');
gsCheck($by[1]['reason']==='rising_demand','growing trip flagged as rising demand');
gsCheck($by[2]['reason']==='top_seller','high but flat demand flagged as top seller');
gsCheck($by[3]['reason']==='needs_bookings_in_peak','weak trip in peak season needs bookings');
gsCheck($focus[0]['id']===1,'rising trip ranked first');
$alloc=GrowthSignals::allocate($focus,12);
gsCheck(array_sum($alloc)===8,'about 70% of 12 posts go to trips');
gsCheck(($alloc[1]??0)>=($alloc[2]??0),'rising trip gets at least as many posts as the steady seller');
gsCheck(GrowthSignals::allocate([],12)===[]&&GrowthSignals::allocate($focus,0)===[],'no trips or no posts allocates nothing');
gsCheck(array_sum(GrowthSignals::allocate($focus,4))===3,'small plans still allocate whole posts');

// مهلة الحجز
gsCheck(GrowthSignals::median([10,20,30,40,50])===30,'median lead time');
gsCheck(GrowthSignals::median([10,20])===null,'lead time needs at least five bookings');
gsCheck(GrowthSignals::median([-5,1000,10,20,30,40,50])===30,'invalid lead times are ignored');

// البناء الكامل
$lead=array_fill(0,20,45);
$g=GrowthSignals::build($monthly,$trips,$lead,'2026-11',12,['seasonal_factor'=>1.3],['countries'=>['GB']]);
gsCheck($g['season']['level']==='peak'&&$g['promote_departures_from']==='2026-12-16','build: season and promotion window');
gsCheck(array_sum(array_column($g['focus_trips'],'posts'))+$g['brand_posts']===12,'build: every post is assigned');
gsCheck(str_contains($g['goal'],'High season')&&str_contains($g['goal'],'Nile Cruise'),'build: goal names the season and lead trip');
gsCheck(count($g['evidence'])>=3&&$g['confidence']==='high','build: evidence lines and confidence');
$empty=GrowthSignals::build([],[],[],'2026-11',8);
gsCheck($empty['season']['level']==='unknown'&&$empty['focus_trips']===[]&&$empty['brand_posts']===8&&$empty['confidence']==='low','build: new account degrades safely');
gsReject(fn()=>GrowthSignals::build([],[],[],'2026-13'),'invalid month rejected');
echo 'All growth signal checks passed.'.PHP_EOL;
