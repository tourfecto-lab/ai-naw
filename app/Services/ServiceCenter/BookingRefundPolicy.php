<?php
/** Immutable booking policy snapshots, reviewed separately from cancellation and payment. */
class BookingRefundPolicy
{
    private PDO $pdo; private $executor;
    public function __construct(?PDO $pdo=null,?callable $executor=null){$this->pdo=$pdo??Database::getInstance()->getConnection();$this->executor=$executor;}
    private function q(string $sql,array $args=[]): PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    public static function calculate(array $booking,string $timezone='UTC',?int $at=null): array {
        $p=json_decode((string)($booking['pricing_json']??''),true);if(!is_array($p))$p=[];
        $hours=max(0,(int)($p['cancellation_hours']??24));$pct=max(0,min(100,(float)($p['refund_percent']??0)));
        $start=new DateTimeImmutable($booking['start_date'].' '.($booking['start_time']?:'00:00:00'),new DateTimeZone($timezone));
        $cutoff=$start->getTimestamp()-$hours*3600;$eligible=($at??time())<=$cutoff;
        return ['eligible'=>$eligible,'refund_percent'=>$eligible?$pct:0,'maximum_policy_refund'=>round((float)$booking['total_amount']*($eligible?$pct:0)/100,2),'cancellation_hours'=>$hours,'cutoff_at'=>gmdate('c',$cutoff),'timezone'=>$timezone,'requires_review'=>true,'note'=>$eligible?'Policy refund requires review of actual collected payments.':'The cancellation deadline has passed. An exception must be reviewed separately.'];
    }
    public function quote(int $uid,int $bookingId): array {
        $b=$this->q('SELECT * FROM bookings WHERE id=? AND user_id=?',[$bookingId,$uid])->fetch(PDO::FETCH_ASSOC);if(!$b)throw new RuntimeException('Booking not found',404);
        $tz=(new BookingOperationsService())->timezone($uid);$cancelledAt=null;
        if($b['status']==='cancelled'){$value=$this->q("SELECT created_at FROM booking_status_history WHERE booking_id=? AND to_status='cancelled' ORDER BY id DESC LIMIT 1",[$bookingId])->fetchColumn();if($value)$cancelledAt=strtotime($value.' UTC');}
        $policy=self::calculate($b,$tz,$cancelledAt);$txs=$this->q("SELECT t.id,t.amount,t.currency,t.gateway,t.status,COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_transaction_id=t.id AND r.status IN ('processing','succeeded')),0) reserved_refunds FROM payment_transactions t WHERE t.booking_id=? AND t.user_id=? AND t.status IN ('succeeded','partially_refunded','refunded') ORDER BY t.id",[$bookingId,$uid])->fetchAll(PDO::FETCH_ASSOC);
        $allocated=0;$paid=0;
        foreach($txs as &$t){if($t['currency']!==$b['currency']){$t['remaining']=0;$t['currency_mismatch']=true;continue;}$paid+=(float)$t['amount'];$allocated+=(float)$t['reserved_refunds'];$pending=(float)$this->q("SELECT COALESCE(SUM(amount),0) FROM booking_refund_requests WHERE user_id=? AND transaction_id=? AND state IN ('review','processing','uncertain') AND refund_id IS NULL",[$uid,$t['id']])->fetchColumn();$allocated+=$pending;$t['remaining']=max(0,round((float)$t['amount']-(float)$t['reserved_refunds']-$pending,2));}unset($t);
        $policy['remaining_policy_refund']=max(0,round(min($paid,$policy['maximum_policy_refund'])-$allocated,2));
        return ['booking'=>['id'=>$bookingId,'reference'=>$b['booking_reference'],'status'=>$b['status'],'total'=>$b['total_amount'],'currency'=>$b['currency']],'policy'=>$policy,'transactions'=>$txs];
    }
    public function request(int $uid,int $actor,array $input): array {
        $bid=(int)($input['booking_id']??0);$tx=(int)($input['transaction_id']??0);$amount=$input['amount']??null;$reason=trim((string)($input['reason']??''));$key=(string)($input['request_key']??'');
        if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||mb_strlen($reason)<5||mb_strlen($reason)>500||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Provide a positive amount, reason and request key');
        $hash=hash('sha256',$uid.':'.$key);$lock='booking-policy:'.$uid.':'.$bid;if(!ExternalExecution::lock($lock))throw new RuntimeException('Booking refund is busy');
        try{$old=$this->q('SELECT * FROM booking_refund_requests WHERE request_key=? AND user_id=?',[$hash,$uid])->fetch(PDO::FETCH_ASSOC);if($old){if((int)$old['booking_id']!==$bid||(int)$old['transaction_id']!==$tx||abs((float)$old['amount']-(float)$amount)>.001||$old['reason']!==$reason)throw new RuntimeException('Request key was used for different details');return $old;}
            $q=$this->quote($uid,$bid);if($q['booking']['status']!=='cancelled')throw new RuntimeException('Cancel the booking before requesting a policy refund. Cancellation does not move money.');
            $t=null;foreach($q['transactions'] as $v)if((int)$v['id']===$tx)$t=$v;
            if(!$t||$t['currency']!==$q['booking']['currency']||!in_array($t['gateway'],['wallet','stripe','stripe_operator','paymob'],true))throw new RuntimeException('Choose a supported collected payment; manual payments are recorded after external repayment in Operations.');
            if((float)$amount>min($t['remaining'],$q['policy']['remaining_policy_refund'])+.001)throw new RuntimeException('Amount exceeds the remaining policy entitlement or collected payment');
            StripeCheckoutService::minorUnits((float)$amount,$t['currency']);
            $this->q("INSERT INTO booking_refund_requests(user_id,booking_id,transaction_id,request_key,amount,currency,policy_json,reason,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())",[$uid,$bid,$tx,$hash,(float)$amount,$t['currency'],json_encode($q['policy'],JSON_THROW_ON_ERROR),$reason,$actor]);return $this->q('SELECT * FROM booking_refund_requests WHERE id=?',[(int)$this->pdo->lastInsertId()])->fetch(PDO::FETCH_ASSOC);
        }finally{ExternalExecution::unlock($lock);}
    }
    public function review(int $uid,int $actor,int $id,bool $approve): array {
        $r=$this->q('SELECT * FROM booking_refund_requests WHERE id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Refund request not found',404);
        $lock='booking-policy:'.$uid.':'.$r['booking_id'];if(!ExternalExecution::lock($lock))throw new RuntimeException('Refund review is busy');
        try{$r=$this->q('SELECT * FROM booking_refund_requests WHERE id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);
            if($r['state']!=='review')return ['state'=>$r['state'],'refund_id'=>$r['refund_id'],'already_reviewed'=>true];
            if(!$approve){$this->q("UPDATE booking_refund_requests SET state='rejected',reviewed_by=?,updated_at=NOW() WHERE id=? AND user_id=?",[$actor,$id,$uid]);return ['state'=>'rejected'];}
            if(!$this->q("SELECT id FROM bookings WHERE id=? AND user_id=? AND status='cancelled'",[$r['booking_id'],$uid])->fetchColumn())throw new RuntimeException('Booking is no longer cancelled');
            $this->q("UPDATE booking_refund_requests SET state='processing',reviewed_by=?,updated_at=NOW() WHERE id=? AND user_id=?",[$actor,$id,$uid]);
            try{$result=$this->executor?($this->executor)($r,$actor):(new RefundService())->createRefund((int)$r['transaction_id'],(float)$r['amount'],'Policy request #'.$id.': '.$r['reason'],$actor);
                $rid=(int)($result['refund_id']??$result['refund']['id']??0);$state=!empty($result['success'])?'succeeded':(!empty($result['pending'])?'uncertain':'failed');
                $this->q('UPDATE booking_refund_requests SET state=?,refund_id=?,updated_at=NOW() WHERE id=? AND user_id=?',[$state,$rid?:null,$id,$uid]);return ['state'=>$state,'refund_id'=>$rid?:null,'result'=>$result];
            }catch(Throwable $e){$this->q("UPDATE booking_refund_requests SET state='uncertain',updated_at=NOW() WHERE id=? AND user_id=?",[$id,$uid]);throw new RuntimeException('Refund outcome needs gateway reconciliation; do not create another request.');}
        }finally{ExternalExecution::unlock($lock);}
    }
    public function listing(int $uid): array {
        // Refresh only from persisted gateway outcomes, never repeat a payment operation.
        $this->q("UPDATE booking_refund_requests q JOIN refunds r ON r.id=q.refund_id AND r.user_id=q.user_id SET q.state=CASE WHEN r.status='succeeded' THEN 'succeeded' WHEN r.status='failed' THEN 'failed' ELSE 'uncertain' END,q.updated_at=NOW() WHERE q.user_id=? AND q.state IN ('processing','uncertain')",[$uid]);
        return $this->q('SELECT q.*,b.booking_reference FROM booking_refund_requests q JOIN bookings b ON b.id=q.booking_id AND b.user_id=q.user_id WHERE q.user_id=? ORDER BY q.id DESC LIMIT 100',[$uid])->fetchAll(PDO::FETCH_ASSOC);
    }
}
