<?php
require_once __DIR__.'/../app/Services/Integrations/OTA/OtaSyncPolicy.php';
require_once __DIR__.'/../app/Services/Integrations/OTA/OtaWebhookVerifier.php';
$checks=0;
function ok($v,$label){global $checks;if(!$v)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
$c=['status'=>'connected','verified_at'=>'2026-10-11 00:00:00','capabilities'=>['booking_update']];
OtaSyncPolicy::assertCapability($c,'booking_update'); ok(true,'verified connection can use granted capability');
try{OtaSyncPolicy::assertCapability($c,'review_reply');ok(false,'unganted capability blocked');}catch(RuntimeException $e){ok(true,'ungranted capability blocked');}
try{OtaSyncPolicy::assertCapability(['status'=>'connected','capabilities'=>['booking_update']],'booking_update');ok(false,'unverified connection blocked');}catch(RuntimeException $e){ok(true,'unverified connection blocked');}
try{OtaSyncPolicy::validateCallbackUrl('http://example.com/hook');ok(false,'insecure callback blocked');}catch(InvalidArgumentException $e){ok(true,'insecure callback blocked');}
ok(OtaSyncPolicy::validateCallbackUrl('https://example.com/hook')==='https://example.com/hook','HTTPS callback accepted');
$body='{"event":"booking.updated"}';$sig=hash_hmac('sha256',$body,'secret');ok(OtaWebhookVerifier::verifyHmacSha256($body,$sig,'secret'),'valid HMAC accepted');ok(!OtaWebhookVerifier::verifyHmacSha256($body,$sig,'wrong'),'invalid HMAC rejected');
ok(OtaSyncPolicy::webhookEventKey('Viator','a1','e1','booking.updated')===OtaSyncPolicy::webhookEventKey('viator','a1','e1','booking.updated'),'webhook event key stable');
echo "{$checks} checks passed\n";
