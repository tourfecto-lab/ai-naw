<?php
/** Pure-function tests. No provider calls, credentials, DB writes or publishing. */
require_once __DIR__.'/../app/Services/SocialMedia/SocialMonthlyPlanService.php';
function smpCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL; }
function smpReject(callable $fn,string $label): void { try{$fn();}catch(InvalidArgumentException|RuntimeException $e){echo 'PASS '.$label.PHP_EOL;return;}throw new Exception('Expected rejection: '.$label); }
$now=new DateTimeImmutable('2026-10-12T04:00:00+00:00');
$slots=SocialMonthlyPlanService::schedule('2026-11','Africa/Cairo','18:00',12,$now);
smpCheck(count($slots)===12&&count(array_unique($slots))===12,'twelve distinct UTC slots');
smpCheck($slots[0]==='2026-11-01 16:00:00','Cairo winter local time is converted to UTC');
smpCheck($slots[11]==='2026-11-30 16:00:00','last slot remains within the plan month');
$fall=SocialMonthlyPlanService::schedule('2026-10','Africa/Cairo','18:00',20,$now);
foreach($fall as $slot){$local=(new DateTimeImmutable($slot,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Cairo'));smpCheck($local->format('H:i')==='18:00','DST conversion preserves local publishing hour '.$slot);}
smpReject(fn()=>SocialMonthlyPlanService::schedule('2026-09','Africa/Cairo','18:00',12,$now),'past month');
smpReject(fn()=>SocialMonthlyPlanService::schedule('2026-13','Africa/Cairo','18:00',12,$now),'invalid month');
smpReject(fn()=>SocialMonthlyPlanService::schedule('2026-11','bad/timezone','18:00',12,$now),'invalid timezone');
smpReject(fn()=>SocialMonthlyPlanService::schedule('2026-10','UTC','18:00',8,new DateTimeImmutable('2026-10-29T20:00:00Z')),'insufficient remaining days');
$assets=[['id'=>1,'video'=>false],['id'=>2,'video'=>true]];
smpCheck(count(SocialMonthlyPlanService::compatible('facebook',$assets))===2,'Facebook permits images and video');
smpCheck(array_column(SocialMonthlyPlanService::compatible('youtube',$assets),'id')===[2],'YouTube selects video only');
smpCheck(SocialMonthlyPlanService::compatible('tiktok',[$assets[0]])===[],'TikTok rejects an image-only pool');
$json=json_encode(['strategy'=>'A factual campaign with useful travel content.','posts'=>[['theme'=>'Real yacht trip','objective'=>'Booking inquiries','visual_brief'=>'The selected real yacht photograph.','captions'=>['facebook'=>'Explore our yacht trips. Ask us for current availability.','instagram'=>'Discover our yacht experiences and contact our booking team.']]]]);
$parsed=SocialMonthlyPlanService::parseResult("```json\n".$json."\n```",['facebook','instagram'],1);
smpCheck(count($parsed['posts'])===1,'fenced provider JSON is accepted');
smpReject(fn()=>SocialMonthlyPlanService::parseResult($json,['facebook','youtube'],1),'missing platform-specific copy');
smpReject(fn()=>SocialMonthlyPlanService::parseResult($json,['facebook'],2),'incomplete plan');
smpReject(fn()=>SocialMonthlyPlanService::parseResult('not JSON',['facebook'],1),'invalid provider output');
smpCheck(SocialMonthlyPlanService::quoteHash(['source'=>'wallet','price'=>'1.50'])===SocialMonthlyPlanService::quoteHash(['source'=>'wallet','price'=>1.5]),'quote price normalization');
smpCheck(SocialMonthlyPlanService::quoteHash(['source'=>'wallet','price'=>1.5])!==SocialMonthlyPlanService::quoteHash(['source'=>'plan']),'quota-to-wallet change invalidates approval quote');
echo 'All pure-function checks passed.'.PHP_EOL;
