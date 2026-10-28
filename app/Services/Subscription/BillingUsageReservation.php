<?php
/** Durable prepaid usage. Unknown outcomes remain held until reconciliation. */
class BillingUsageReservation
{
    private const TYPES=[
        'ai'=>['usage_ai_analysis_count','ai_credits','ai_analysis'],
        'chat'=>['usage_ai_message_count','chat_credits','chat_message'],
        'review'=>['usage_review_reply_count','review_credits','review_reply'],
        'competitor'=>['competitor_analysis_used','competitor_analysis_limit','competitor_analysis'],
    ];
    private static function eligible(array $s): bool
    {
        $now=time();$end=strtotime($s['current_period_end']??'')?:0;
        return ($s['status']==='active' && $end>$now)
            || ($s['status']==='trialing' && (empty($s['trial_ends_at']) || strtotime($s['trial_ends_at'])>$now))
            || ($s['status']==='past_due' && $end>$now-7*86400);
    }
    public static function reserve(int $userId,string $feature,int $quantity=1): array
    {
        if (!isset(self::TYPES[$feature]) || $userId<1 || $quantity<1) return ['available'=>false,'message'=>'كمية استخدام غير صحيحة'];
        $db=Database::getInstance();
        try {
            return $db->transaction(function() use($db,$userId,$feature,$quantity) {
                $db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
                SubscriptionPeriod::refreshQuota($userId);
                $sub=Subscription::activeSubscriptionRow($userId);
                [$column,$limitKey,$walletKey]=self::TYPES[$feature];
                $token=bin2hex(random_bytes(24));$source='wallet';$amount=0;$subId=null;$anchor=null;
                if ($sub) {
                    $locked=$db->query('SELECT * FROM subscriptions WHERE id=? FOR UPDATE',[(int)$sub['id']]);
                    $s=$locked[0]??null;
                    if ($s && self::eligible($s) && (int)$s[$column]+$quantity <= (int)$sub[$limitKey]) {
                        $db->exec("UPDATE subscriptions SET {$column}={$column}+? WHERE id=?",[$quantity,(int)$s['id']]);
                        $source='subscription';$subId=(int)$s['id'];$anchor=$s['last_usage_reset_at']??$s['current_period_start'];
                    }
                }
                if ($source==='wallet') {
                    $wallet=new WalletService();$pricing=$wallet->getUsagePricing();$amount=round((float)($pricing[$walletKey]['price']??-1)*$quantity,2);
                    if ($amount<0 || $wallet->getBalance($userId)<$amount) return ['available'=>false,'message'=>'رصيدك غير كافٍ'];
                    if ($quantity!==1) throw new RuntimeException('Multi-unit wallet reservation unavailable');
                    if (!$wallet->chargeForUsage($userId,$walletKey,'حجز استخدام '.$feature,'usage_reserve_'.$token)) throw new RuntimeException('Usage debit failed');
                }
                $db->query('INSERT INTO billing_usage_reservations (token,user_id,subscription_id,feature,quantity,source,amount,quota_anchor,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',[$token,$userId,$subId,$feature,$quantity,$source,$amount,$anchor,'reserved']);
                return ['available'=>true,'source'=>$source,'token'=>$token,'quantity'=>$quantity,'feature'=>$feature,'user_id'=>$userId];
            });
        } catch (Throwable $e) {
            Logger::error('Usage reservation failed',['feature'=>$feature]);
            return ['available'=>false,'message'=>'تعذر تأكيد رصيد الاستخدام. حاول مرة أخرى'];
        }
    }
    public static function settle(string $token,string $state): bool
    {
        if (!in_array($state,['completed','released','uncertain'],true)) return false;
        $db=Database::getInstance();
        return (bool)$db->transaction(function() use($db,$token,$state) {
            $owner=$db->query('SELECT user_id FROM billing_usage_reservations WHERE token=?',[$token]);
            if (!$owner)return false;
            $db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$owner[0]['user_id']]);
            $rows=$db->query('SELECT * FROM billing_usage_reservations WHERE token=? FOR UPDATE',[$token]);
            $r=$rows[0]??null;if(!$r)return false;
            if ($r['state']===$state)return true;
            if (!in_array($r['state'],['reserved','uncertain'],true))return false;
            if ($state==='released') {
                $db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$r['user_id']]);
                if ($r['source']==='wallet' && (float)$r['amount']>0) {
                    $tx=new WalletTransaction();$tx->fill(['user_id'=>(int)$r['user_id'],'type'=>'refund','amount'=>(float)$r['amount'],'currency'=>'USD','status'=>'completed','reference_note'=>'إلغاء حجز استخدام لم ينجح','idempotency_key'=>'usage_release_'.$token,'feature_key'=>$r['feature']]);
                    if (!$tx->save())throw new RuntimeException('Usage refund failed');
                } elseif ($r['source']==='subscription') {
                    [$column]=self::TYPES[$r['feature']];
                    $db->exec("UPDATE subscriptions SET {$column}=GREATEST(0,{$column}-?) WHERE id=? AND COALESCE(last_usage_reset_at,current_period_start)=?",[(int)$r['quantity'],(int)$r['subscription_id'],$r['quota_anchor']]);
                }
            }
            $db->exec('UPDATE billing_usage_reservations SET state=?,updated_at=NOW() WHERE id=?',[$state,(int)$r['id']]);
            return true;
        });
    }
}
