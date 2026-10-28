<?php
/** Pure-function tests for the unified services × plans registry. No DB, credentials or provider calls. */
require_once __DIR__.'/../app/Services/ServiceCenter/ServiceCenterCatalog.php';
require_once __DIR__.'/../app/Services/Marketing/MarketingContent.php';
require_once __DIR__.'/../app/Services/Subscription/BillingPlanCatalog.php';
require_once __DIR__.'/../app/Services/Subscription/PlanUsage.php';
require_once __DIR__.'/../app/Services/Platform/PlatformServiceRegistry.php';
function psrCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL; }
function psrReject(callable $fn,string $label): void { try{$fn();}catch(InvalidArgumentException $e){echo 'PASS '.$label.PHP_EOL;return;}throw new RuntimeException('Expected rejection: '.$label); }

// 1) الربط بين المصادر الثلاثة كامل ومفيش مفتاح يتيم
$usageKeys=array_keys((new ReflectionClassConstant('PlanUsage','FEATURES'))->getValue());
$errors=PlatformServiceRegistry::audit(array_keys(ServiceCenterCatalog::all()),array_keys(MarketingContent::features()),$usageKeys);
psrCheck($errors===[],'service center, marketing pages and usage meters are fully linked'.($errors?': '.implode('; ',$errors):''));

// 2) باقات حقيقية الشكل عبر BillingPlanCatalog (نفس مسار الإنتاج)
$row=fn(string $code,string $cycle,array $f,int $chat)=>['id'=>crc32($code),'plan_code'=>$code,'billing_cycle'=>$cycle,'name'=>ucfirst(strtok($code,'_')),'price'=>'10','currency'=>'USD','features_json'=>json_encode($f),'ai_analysis_limit'=>5,'ai_message_limit'=>$chat,'review_auto_reply_limit'=>0,'websites_limit'=>1];
$rows=[];
foreach(['starter'=>[['whatsapp_bot'=>false,'auto_pilot'=>false,'advanced_analytics'=>false],100],'professional'=>[['whatsapp_bot'=>true,'auto_pilot'=>true,'advanced_analytics'=>true],1000]] as $tier=>[$f,$chat]) foreach(['monthly','yearly'] as $c)$rows[]=$row($tier.'_'.$c,$c,$f,$chat);
$plans=BillingPlanCatalog::fromRows($rows,['starter'=>['sort_order'=>1,'id'=>1,'name'=>'Starter'],'professional'=>['sort_order'=>2,'id'=>2,'name'=>'Professional']]);
psrCheck(array_keys($plans)===['starter','professional'],'plans load in display order');

$m=PlatformServiceRegistry::matrix($plans);
psrCheck(count($m)===count(ServiceCenterCatalog::all()),'every service appears in the matrix');
psrCheck($m['crm']['plans']['starter']['state']==='core','service without entitlements is core');
psrCheck($m['whatsapp']['plans']['starter']['state']==='not_included','gated WhatsApp is not in starter');
psrCheck($m['whatsapp']['plans']['professional']['state']==='included','gated WhatsApp is included in professional');
psrCheck($m['whatsapp']['min_plan']==='professional','lowest plan for WhatsApp is professional');
psrCheck($m['video']['plans']['starter']['state']==='pay_per_use','zero video quota falls back to wallet');
psrCheck($m['video']['plans']['professional']['state']==='included','professional video quota is included');
psrCheck($m['articles']['plans']['starter']['state']==='included','starter article quota is included');
psrCheck($m['competitors']['plans']['starter']['state']==='not_included'&&$m['competitors']['min_plan']===null,'zero allowance without wallet fallback requires an upgrade');
psrCheck($m['analytics']['plans']['starter']['state']==='core','optional flag off keeps the core service available');

// 3) تجميع صفحات /features
$g=PlatformServiceRegistry::marketingMatrix($plans);
psrCheck(array_diff(array_keys(MarketingContent::features()),array_keys($g))===[],'every marketing page has plan coverage');
psrCheck($g['ai-chat']['plans']['starter']['best']==='core'&&$g['ai-chat']['plans']['starter']['worst']==='not_included','ai-chat is partial in starter');
psrCheck(in_array('whatsapp',$g['ai-chat']['plans']['starter']['upgrade'],true),'starter ai-chat lists WhatsApp as upgrade');

// 4) عرض حساب العميل
$usage=['media_video'=>['covered'=>false,'limit'=>0,'used'=>0,'remaining'=>0],'article_generation'=>['covered'=>false,'limit'=>10,'used'=>10,'remaining'=>0]];
$v=PlatformServiceRegistry::accountView($plans['starter']['features'],$usage,$plans,'starter_monthly');
psrCheck($v['whatsapp']['state']==='not_included'&&$v['whatsapp']['upgrade_plan']==='professional','starter account is offered the professional upgrade');
psrCheck($v['video']['upgrade_plan']==='professional','pay-per-use service offers an upgrade with included quota');
psrCheck(!empty($v['articles']['entitlements'][0]['exhausted']),'used-up allowance is flagged as exhausted');
psrCheck($v['crm']['upgrade_plan']===null,'core service never suggests an upgrade');
$top=PlatformServiceRegistry::accountView($plans['professional']['features'],[],$plans,'professional_yearly');
psrCheck($top['whatsapp']['state']==='included'&&$top['whatsapp']['upgrade_plan']===null,'top plan has no upgrade suggestion');
$none=PlatformServiceRegistry::accountView([],[],$plans,null);
psrCheck($none['whatsapp']['state']==='not_included'&&$none['whatsapp']['upgrade_plan']==='professional','no subscription: gated service points to its lowest plan');
psrCheck($none['articles']['state']==='pay_per_use'&&$none['crm']['state']==='core','no subscription: metered services use the wallet, core stays available');

// 5) قيم غير صالحة
psrCheck(PlatformServiceRegistry::normalize('-1','meter')===-1&&PlatformServiceRegistry::normalize('abc','limit')===0&&PlatformServiceRegistry::normalize('1','flag')===true,'value normalization');
psrReject(fn()=>PlatformServiceRegistry::entitlementMeta('does_not_exist'),'unknown entitlement is rejected');
psrCheck(PlatformServiceRegistry::audit(['crm','ghost'],['crm-revenue'],$usageKeys)!==[],'audit reports unmapped services and pages');
echo 'All platform service registry checks passed.'.PHP_EOL;
