<?php
/** Reserves a PlanUsage event or wallet debit before service execution; settles exactly once. */
class ServiceUsageReservation
{
    public static function reserve(int $userId,string $feature,string $walletKey): string
    {
        $db=Database::getInstance();$pdo=$db->getConnection();
        return $db->transaction(function()use($db,$pdo,$userId,$feature,$walletKey){
            $q=$pdo->prepare('SELECT id,owner_user_id FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u)throw new RuntimeException('Owner unavailable');
            $owner=(int)($u['owner_user_id']?:$userId);if($owner!==$userId){$q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$owner]);if(!$q->fetchColumn())throw new RuntimeException('Owner unavailable');}
            $token=bin2hex(random_bytes(24));$note='service_reserve_'.$token;$source='wallet';$event=null;$amount=0;
            $quota=PlanUsage::quota($userId,$feature);
            if(!empty($quota['covered'])){
                if(!PlanUsage::consume($userId,$feature,$note))throw new RuntimeException('Reserved quota unavailable');
                $q=$pdo->prepare('SELECT id FROM plan_usage_events WHERE user_id=? AND feature=? AND note=?');$q->execute([$userId,$feature,$note]);$event=$q->fetchColumn();if(!$event)throw new RuntimeException('Usage reservation missing');$source='plan';
            }else{
                $wallet=new WalletService();$price=$wallet->getUsagePricing()[$walletKey]??null;
                if(!$price||!is_numeric($price['price'])||(float)$price['price']<0||($price['currency']??'USD')!=='USD')throw new RuntimeException('Pricing unavailable');
                $amount=round((float)$price['price'],2);
                if(!$wallet->chargeForUsage($owner,$walletKey,$note,'service_reserve_'.$token))throw new RuntimeException('Insufficient available balance');
            }
            $q=$pdo->prepare("INSERT INTO service_usage_reservations(token,user_id,owner_user_id,feature_key,source,amount,usage_event_id,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'reserved',NOW(),NOW())");$q->execute([$token,$userId,$owner,$feature,$source,$amount,$event]);return $token;
        });
    }
    public static function settle(string $token,string $state): bool
    {
        if(!in_array($state,['completed','released','uncertain'],true))throw new InvalidArgumentException('Invalid settlement');
        $db=Database::getInstance();$pdo=$db->getConnection();
        return $db->transaction(function()use($pdo,$token,$state){
            $q=$pdo->prepare('SELECT owner_user_id FROM service_usage_reservations WHERE token=?');$q->execute([$token]);$owner=$q->fetchColumn();if(!$owner)return false;
            $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$owner]);
            $q=$pdo->prepare('SELECT * FROM service_usage_reservations WHERE token=? FOR UPDATE');$q->execute([$token]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)return false;
            if($r['state']===$state)return true;if(!in_array($r['state'],['reserved','uncertain'],true))return false;
            if($state==='released'){
                if($r['source']==='plan'){$q=$pdo->prepare('DELETE FROM plan_usage_events WHERE id=? AND note=?');$q->execute([$r['usage_event_id'],'service_reserve_'.$token]);}
                elseif((float)$r['amount']>0){$t=new WalletTransaction(['user_id'=>(int)$r['owner_user_id'],'type'=>'refund','amount'=>(float)$r['amount'],'currency'=>'USD','status'=>'completed','feature_key'=>$r['feature_key'],'reference_note'=>'Released failed service reservation','idempotency_key'=>'service_release_'.$token]);if(!$t->save())throw new RuntimeException('Release persistence failed');}
            }
            $q=$pdo->prepare('UPDATE service_usage_reservations SET state=?,updated_at=NOW() WHERE token=?');$q->execute([$state,$token]);return true;
        });
    }
}
