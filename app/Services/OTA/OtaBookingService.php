<?php
/** Namespaced OTA revenue events, serialized refunds and explicit partial-refund identities. */
class OtaBookingService
{
    private static function key(int $userId,string $platform,string $reference,string $account): string{
        if($userId<1||!in_array($platform,['viator','getyourguide'],true)||trim($reference)===''||strlen($reference)>255||strlen($account)>190)throw new InvalidArgumentException('Invalid OTA identity');
        return hash('sha256',json_encode([$userId,$platform,$account,$reference],JSON_THROW_ON_ERROR));
    }
    public function recordBookingRevenue(int $userId,string $platform,string $bookingReference,float $amount,string $currency='USD',?string $productName=null,string $category='tours',string $accountId='default'): bool{
        if(!is_finite($amount)||$amount<=0||!preg_match('/^[A-Z]{3}$/D',$currency))return false;
        try{$key=self::key($userId,$platform,trim($bookingReference),$accountId);$db=Database::getInstance();$pdo=$db->getConnection();
            $result=$db->transaction(function()use($pdo,$key,$userId,$platform,$accountId,$bookingReference,$amount,$currency,$productName,$category){
                $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);if(!$q->fetchColumn())return false;
                $q=$pdo->prepare('SELECT booking_key FROM ota_revenue_bindings WHERE booking_key=?');$q->execute([$key]);if($q->fetchColumn())return false;
                // Unnamespaced historical rows need manual reconciliation, never add their value twice.
                $q=$pdo->prepare("SELECT id FROM rev_revenue_records WHERE user_id=? AND source='ota_booking' AND reference_id=?");$q->execute([$userId,$bookingReference]);if($q->fetchColumn())throw new RuntimeException('Historical OTA reference requires reconciliation');
                $q=$pdo->prepare('INSERT INTO ota_revenue_bindings(booking_key,user_id,platform,account_id,external_reference,amount,currency,refunded_amount) VALUES(?,?,?,?,?,?,?,0)');$q->execute([$key,$userId,$platform,$accountId,$bookingReference,round($amount,2),$currency]);
                $q=$pdo->prepare("INSERT INTO rev_revenue_records(user_id,source,product_name,category,reference_id,amount,currency,recorded_at,notes) VALUES(?,'ota_booking',?,?,?,?,?,NOW(),?)");$q->execute([$userId,$productName,$category,'ota:'.$key,round($amount,2),$currency,'Verified OTA booking: '.$platform]);return true;
            });if($result&&function_exists('event'))event('revenue.updated',['user_id'=>$userId]);return $result;
        }catch(Throwable $e){Logger::warning('OTA revenue needs review',['user_id'=>$userId]);return false;}
    }
    public function recordBookingRefund(int $userId,string $platform,string $bookingReference,float $amount,string $currency='USD',?string $externalRefundId=null,string $accountId='default'): bool{
        if(!is_finite($amount)||$amount<=0)return false;
        try{$key=self::key($userId,$platform,trim($bookingReference),$accountId);$db=Database::getInstance();$pdo=$db->getConnection();
            $result=$db->transaction(function()use($pdo,$key,$userId,$amount,$currency,$externalRefundId){
                $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);
                $q=$pdo->prepare('SELECT * FROM ota_revenue_bindings WHERE booking_key=? AND user_id=? FOR UPDATE');$q->execute([$key,$userId]);$b=$q->fetch(PDO::FETCH_ASSOC);if(!$b||$b['currency']!==$currency)return false;
                $amount=round($amount,2);$remaining=round((float)$b['amount']-(float)$b['refunded_amount'],2);
                if($amount>$remaining||$amount<=0)return false;
                if($externalRefundId===null&&$amount!==round((float)$b['amount'],2))throw new InvalidArgumentException('Partial refund needs a unique provider refund ID');
                $eventKey=hash('sha256',$key.'|'.($externalRefundId??'full'));$q=$pdo->prepare('SELECT event_key FROM ota_refund_events WHERE event_key=?');$q->execute([$eventKey]);if($q->fetchColumn())return false;
                $q=$pdo->prepare('INSERT INTO ota_refund_events(event_key,booking_key,amount) VALUES(?,?,?)');$q->execute([$eventKey,$key,$amount]);
                $q=$pdo->prepare('UPDATE ota_revenue_bindings SET refunded_amount=refunded_amount+? WHERE booking_key=?');$q->execute([$amount,$key]);
                $q=$pdo->prepare("INSERT INTO rev_revenue_records(user_id,source,category,reference_id,amount,currency,recorded_at,notes) VALUES(?,'ota_refund','tours',?,?,?,NOW(),?)");$q->execute([$userId,'ota-refund:'.$eventKey,-$amount,$currency,'Verified OTA refund; booking '.$key]);return true;
            });if($result&&function_exists('event'))event('revenue.updated',['user_id'=>$userId]);return $result;
        }catch(Throwable $e){Logger::warning('OTA refund needs review',['user_id'=>$userId]);return false;}
    }
}
