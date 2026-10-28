<?php
/** Pure tests for Stripe Connect sign-in token handling (stubbed transport, no network). */
require_once __DIR__.'/../app/Services/Payment/StripeConnectOAuth.php';
function scCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL; }
$ok=StripeConnectOAuth::parseToken(['code'=>200,'body'=>json_encode(['access_token'=>'sk_live_abc123','stripe_user_id'=>'acct_1Xyz','livemode'=>true,'refresh_token'=>'rt_x'])]);
scCheck($ok['ok']&&$ok['access_token']==='sk_live_abc123'&&$ok['account_id']==='acct_1Xyz'&&$ok['livemode']===true,'valid token response accepted');
scCheck(!StripeConnectOAuth::parseToken(['code'=>400,'body'=>'{"error":"invalid_grant","error_description":"Authorization code expired"}'])['ok'],'expired code rejected');
scCheck(StripeConnectOAuth::parseToken(['code'=>400,'body'=>'{"error":"invalid_grant","error_description":"Authorization code expired"}'])['error']==='Authorization code expired','Stripe error text kept for logs');
scCheck(!StripeConnectOAuth::parseToken(['code'=>200,'body'=>'{"access_token":"pk_live_x","stripe_user_id":"acct_1"}'])['ok'],'publishable key never stored as secret');
scCheck(!StripeConnectOAuth::parseToken(['code'=>200,'body'=>'{"access_token":"sk_live_x","stripe_user_id":"evil"}'])['ok'],'malformed account id rejected');
scCheck(!StripeConnectOAuth::parseToken(['code'=>0,'body'=>''])['ok'],'unreachable Stripe handled');
$sent=[];$c=new StripeConnectOAuth(function($u,$f,$k)use(&$sent){$sent[]=[$u,$f];return ['code'=>200,'body'=>'{"access_token":"sk_test_t1","stripe_user_id":"acct_9","livemode":false}'];});
scCheck(!$c->exchange('javascript:x')['ok']&&!$sent,'non-Stripe code rejected before any request');
$r=$c->exchange('ac_ABC123');
scCheck($r['ok']&&$sent[0][0]==='https://connect.stripe.com/oauth/token'&&$sent[0][1]===['grant_type'=>'authorization_code','code'=>'ac_ABC123'],'code exchanged at Stripe token endpoint');
scCheck(!$c->deauthorize('bad')&&$c->deauthorize('acct_9'),'deauthorize validates account id');
echo 'All Stripe Connect checks passed.'.PHP_EOL;
