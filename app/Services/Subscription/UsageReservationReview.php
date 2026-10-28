<?php
require_once __DIR__.'/BillingUsageReservation.php';
/** Explicit, evidenced reconciliation. Elapsed time alone never causes a refund. */
class UsageReservationReview
{
    public static function resolve(string $token, string $outcome, string $evidence, int $adminId): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$token) || !in_array($outcome,['completed','released'],true)
            || mb_strlen(trim($evidence))<12 || mb_strlen($evidence)>1000 || $adminId<1) {
            throw new InvalidArgumentException('حدد النتيجة وأدخل دليل المراجعة (12–1000 حرف)');
        }
        $db=Database::getInstance();
        return $db->transaction(function()use($db,$token,$outcome,$evidence,$adminId){
            $owner=$db->query('SELECT user_id FROM billing_usage_reservations WHERE token=?',[$token])[0]??null;
            if (!$owner) throw new RuntimeException('الاستخدام غير موجود');
            $db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$owner['user_id']]);
            $r=$db->query('SELECT * FROM billing_usage_reservations WHERE token=? FOR UPDATE',[$token])[0];
            if (!in_array($r['state'],['reserved','uncertain'],true)) {
                if ($r['state']===$outcome) return ['state'=>$outcome,'already_resolved'=>true];
                throw new RuntimeException('تمت تسوية الاستخدام بنتيجة مختلفة');
            }
            if (strtotime($r['updated_at'])>time()-1800) throw new RuntimeException('الاستخدام حديث؛ انتظر 30 دقيقة من آخر تحديث وتأكد أن العامل توقف');
            if (!BillingUsageReservation::settle($token,$outcome)) throw new RuntimeException('تعذر تسوية الاستخدام');
            $db->query('INSERT INTO billing_usage_reviews (reservation_token,admin_id,previous_state,outcome,evidence,created_at) VALUES (?,?,?,?,?,NOW())',[$token,$adminId,$r['state'],$outcome,trim($evidence)]);
            return ['state'=>$outcome,'user_id'=>(int)$r['user_id'],'source'=>$r['source'],'amount'=>(float)$r['amount']];
        });
    }

    public static function markStale(): int
    {
        // Preserve both the debit and quota. A human must verify the original provider outcome.
        return (int)Database::getInstance()->query("UPDATE billing_usage_reservations SET state='uncertain',updated_at=NOW() WHERE state='reserved' AND updated_at<DATE_SUB(NOW(),INTERVAL 60 MINUTE)");
    }

    public static function resolveService(string $token,string $outcome,string $evidence,int $adminId): array{
        if(!preg_match('/^[a-f0-9]{48}$/D',$token)||!in_array($outcome,['completed','released'],true)||mb_strlen(trim($evidence))<12||mb_strlen($evidence)>1000||$adminId<1)throw new InvalidArgumentException('حدد النتيجة ودليل المراجعة');
        $db=Database::getInstance();$pdo=$db->getConnection();
        return $db->transaction(function()use($pdo,$token,$outcome,$evidence,$adminId){
            $q=$pdo->prepare('SELECT owner_user_id FROM service_usage_reservations WHERE token=?');$q->execute([$token]);$owner=$q->fetchColumn();if(!$owner)throw new RuntimeException('الاستخدام غير موجود');
            $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$owner]);
            $q=$pdo->prepare('SELECT * FROM service_usage_reservations WHERE token=? FOR UPDATE');$q->execute([$token]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!in_array($r['state'],['reserved','uncertain'],true)){if($r['state']===$outcome)return ['state'=>$outcome,'already_resolved'=>true];throw new RuntimeException('تمت التسوية بنتيجة مختلفة');}
            if(strtotime($r['updated_at'])>time()-1800)throw new RuntimeException('انتظر 30 دقيقة وتأكد أن الطلب الأصلي توقف');
            if(!ServiceUsageReservation::settle($token,$outcome))throw new RuntimeException('تعذر التسوية');
            $q=$pdo->prepare('INSERT INTO service_usage_reviews(reservation_token,admin_id,previous_state,outcome,evidence) VALUES(?,?,?,?,?)');$q->execute([$token,$adminId,$r['state'],$outcome,trim($evidence)]);
            return ['state'=>$outcome,'user_id'=>$r['user_id']];
        });
    }

}
