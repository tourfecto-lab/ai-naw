<?php
/** Pure checks only. Run with PHP 8.1+ and mbstring; no provider or database calls. */
interface QueueJobInterface{public function handle(array $payload): void;}
require_once __DIR__.'/../app/Services/BusinessHub/SocialRecurringService.php';
function bhCheck(bool $ok,string $name): void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name.PHP_EOL;}
$input=['business_name'=>'Real Travel','business_facts'=>'Confirmed private tours','audience'=>'Families','goal'=>'Relevant bookings','offers'=>'','cta'=>'https://travel.test/','language'=>'en','timezone'=>'Africa/Cairo','confirmed'=>true];
bhCheck(BusinessHubService::validate($input)['business_name']==='Real Travel','confirmed company facts accepted');
foreach(['confirmed'=>false,'timezone'=>'invalid/timezone','language'=>'xx','business_facts'=>''] as $key=>$value){$bad=$input;$bad[$key]=$value;$rejected=false;try{BusinessHubService::validate($bad);}catch(InvalidArgumentException $e){$rejected=true;}bhCheck($rejected,'invalid '.$key.' rejected');}
$now=new DateTimeImmutable('2026-12-22T12:00:00+02:00');
bhCheck(SocialRecurringService::nextMonth('2026-12','Africa/Cairo',$now)==='2027-01','December rolls into January');
bhCheck(SocialRecurringService::nextMonth('2025-01','Africa/Cairo',$now)==='2027-01','old source skips elapsed months');
bhCheck(SocialRecurringService::nextMonth('2027-03','Africa/Cairo',$now)==='2027-04','future source advances one month');
