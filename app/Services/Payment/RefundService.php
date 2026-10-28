<?php

/**
 * Tourfecto - Refund Service
 * @version 1.0.0
 * @date 2026-08-12
 *
 * ينسّق عملية الاسترجاع: يتحقق من المبلغ ضد معاملة دفع حقيقية، يفوّض
 * التنفيذ الفعلي لأداة الدفع نفسها (Gateway Adapter) - أول تنفيذ حقيقي
 * هو WalletGatewayAdapter - وميعتبرش النجاح مضمون قبل ما يرجع رد فعلي.
 * دايمًا بيحدّث حالة refunds و payment_transactions من النتيجة
 * الحقيقية بس، مش افتراض.
 */
class RefundService
{
    /** @var Database */
    private $db;

    private $gatewayFactory;

    public function __construct(?Database $db = null, ?callable $gatewayFactory = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->gatewayFactory = $gatewayFactory;
    }

    /**
     * @param int $paymentTransactionId معاملة الدفع الأصلية المطلوب استرجاعها
     * @param float $amount المبلغ المطلوب استرجاعه (كامل أو جزء من قيمة المعاملة)
     */
    public function createRefund(int $paymentTransactionId, float $amount, string $reason, int $adminId): array
    {
        if (!is_finite($amount) || $amount <= 0) { return ['success' => false, 'error' => 'مبلغ غير صالح']; }
        try {
            $refund = $this->db->transaction(function () use ($paymentTransactionId, $amount, $reason, $adminId) {
                $row = $this->db->query('SELECT * FROM payment_transactions WHERE id = ? FOR UPDATE', [$paymentTransactionId])[0] ?? null;
                if (!$row || !in_array($row['status'], ['succeeded', 'partially_refunded'], true)) { throw new RuntimeException('المعاملة لا تسمح بالاسترجاع'); }
                if (!in_array($row['gateway'], ['wallet', 'stripe', 'stripe_operator', 'paymob'], true)) { throw new RuntimeException('بوابة الدفع غير مدعومة للاسترجاع'); }
                if($row['gateway']==='paymob' && !$this->gatewayFactory && !(new PaymobGateway($this->db))->isRefundConfigured())throw new RuntimeException('استرداد Paymob غير مفعّل؛ راجع إعدادات الدفع أولًا');
                if ($row['gateway']==='wallet' && strtoupper($row['currency'])!=='USD') throw new RuntimeException('محفظة المنصة تدعم USD فقط');
                StripeCheckoutService::minorUnits($amount, (string) $row['currency']);
                $reserved = $this->db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM refunds WHERE payment_transaction_id = ? AND status IN ('processing', 'succeeded')", [$paymentTransactionId]);
                $remaining = round((float) $row['amount'] - (float) ($reserved[0]['total'] ?? 0), 2);
                if ($amount > $remaining) { throw new RuntimeException('المبلغ يتجاوز الرصيد المتاح للاسترجاع: ' . $remaining . ' ' . $row['currency']); }
                $refund = new Refund();
                $refund->fill(['payment_transaction_id' => $paymentTransactionId, 'user_id' => (int) $row['user_id'],
                    'amount' => $amount, 'currency' => $row['currency'], 'reason' => $reason, 'status' => 'processing', 'created_by_admin_id' => $adminId]);
                if (!$refund->save()) { throw new RuntimeException('Refund reservation failed'); }
                if ($row['gateway']==='wallet') {
                    $marker='wallet_reserved:'.(int)$refund->getAttribute('id');
                    $this->db->query('UPDATE refunds SET gateway_refund_reference=? WHERE id=?',[$marker,(int)$refund->getAttribute('id')]);
                    $refund->setAttribute('gateway_refund_reference',$marker);
                }
                return $refund;
            });
        } catch (Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
        return $this->executeReservedRefund($refund);
    }

    /** Record an evidenced refund already paid outside the platform; this method transfers no money. */
    public function recordManualRefund(int $transactionId,float $amount,string $reason,int $actorId,string $receipt): array
    {
        $receipt=trim($receipt);
        if(!is_finite($amount)||$amount<=0||$receipt===''||mb_strlen($receipt)>150)return ['success'=>false,'error'=>'المبلغ ومرجع الإيصال مطلوبان'];
        try{return $this->db->transaction(function()use($transactionId,$amount,$reason,$actorId,$receipt){
            $tx=$this->db->query('SELECT * FROM payment_transactions WHERE id=? FOR UPDATE',[$transactionId])[0]??null;
            if(!$tx || $tx['gateway']!=='manual')throw new RuntimeException('هذه العملية للدفعات المسجلة خارج المنصة فقط');
            $reference='manual:'.$receipt;
            $old=$this->db->query('SELECT id,amount,status FROM refunds WHERE payment_transaction_id=? AND gateway_refund_reference=?',[$transactionId,$reference])[0]??null;
            if($old){if(abs((float)$old['amount']-$amount)>0.001)throw new RuntimeException('الإيصال مسجل بمبلغ مختلف');return ['success'=>$old['status']==='succeeded','already_recorded'=>true,'refund_id'=>(int)$old['id']];}
            if(!in_array($tx['status'],['succeeded','partially_refunded'],true))throw new RuntimeException('المعاملة لا تسمح باسترداد إضافي');
            StripeCheckoutService::minorUnits($amount,(string)$tx['currency']);
            $reserved=(float)($this->db->query("SELECT COALESCE(SUM(amount),0) total FROM refunds WHERE payment_transaction_id=? AND status IN ('processing','succeeded')",[$transactionId])[0]['total']??0);
            if($amount>round((float)$tx['amount']-$reserved,2)+0.001)throw new RuntimeException('المبلغ يتجاوز المتاح للاسترداد');
            $refund=new Refund();$refund->fill(['payment_transaction_id'=>$transactionId,'user_id'=>(int)$tx['user_id'],'amount'=>$amount,'currency'=>$tx['currency'],'reason'=>mb_substr('استرداد تم خارج المنصة: '.$reason,0,255),'status'=>'succeeded','gateway_refund_reference'=>$reference,'created_by_admin_id'=>$actorId]);
            if(!$refund->save())throw new RuntimeException('تعذر حفظ الاسترداد');
            $total=$this->totalRefundedFor($transactionId);$this->db->exec('UPDATE payment_transactions SET status=? WHERE id=?',[$total>=(float)$tx['amount']?'refunded':'partially_refunded',$transactionId]);
            if(class_exists('ActivityLog'))ActivityLog::record('billing','billing.manual_refund_recorded',['user_id'=>$actorId,'subject_type'=>'refunds','subject_id'=>(int)$refund->getAttribute('id'),'meta'=>['payment_transaction_id'=>$transactionId,'amount'=>$amount,'receipt'=>$receipt]]);
            return ['success'=>true,'recorded_only'=>true,'refund_id'=>(int)$refund->getAttribute('id')];
        });}catch(Throwable $e){return ['success'=>false,'error'=>$e->getMessage()];}
    }

    /** Safely reconcile an uncertain Stripe request using the SAME persisted idempotency key. */
    public function reconcileStripeRefund(int $refundId): array
    {
        $refund = (new Refund())->find($refundId);
        if (!$refund) { return ['success' => false, 'error' => 'الاسترجاع غير موجود']; }
        $tx = (new PaymentTransaction())->find((int) $refund->getAttribute('payment_transaction_id'));
        if (!$tx || !in_array($tx->getAttribute('gateway'), ['stripe', 'stripe_operator'], true)) { return ['success' => false, 'error' => 'المصالحة الآلية متاحة لـ Stripe فقط']; }
        if ($refund->getAttribute('status') !== 'processing') { return ['success' => $refund->getAttribute('status') === 'succeeded', 'refund' => $refund->toArray()]; }
        // Stripe only guarantees retention of keys for 24 hours. Older uncertain refunds require dashboard reconciliation.
        $created = $this->db->query('SELECT created_at FROM refunds WHERE id = ?', [$refundId])[0]['created_at'] ?? null;
        if ((!$created || strtotime((string) $created) < time() - 23 * 3600) && !$refund->getAttribute('gateway_refund_reference')) {
            return ['success' => false, 'pending' => true, 'error' => 'راجع Stripe يدويًا؛ انتهت نافذة إعادة المحاولة الآمنة'];
        }
        return $this->executeReservedRefund($refund);
    }

    public function reconcilePaymobRefund(int $refundId): array
    {
        $refund=(new Refund())->find($refundId);
        if (!$refund) { return ['success'=>false,'error'=>'الاسترجاع غير موجود']; }
        $tx=(new PaymentTransaction())->find((int)$refund->getAttribute('payment_transaction_id'));
        if (!$tx || $tx->getAttribute('gateway') !== 'paymob') { return ['success'=>false,'error'=>'بوابة غير متوافقة']; }
        if ($refund->getAttribute('status') !== 'processing') { return ['success'=>$refund->getAttribute('status')==='succeeded','refund'=>$refund->toArray()]; }
        if (!$refund->getAttribute('gateway_refund_reference')) { return ['success'=>false,'pending'=>true,'error'=>'راجع Paymob يدويًا؛ الطلب لن يتكرر تلقائيًا']; }
        return $this->executeReservedRefund($refund);
    }

    private function executeReservedRefund(Refund $refund): array
    {
        $txId = (int) $refund->getAttribute('payment_transaction_id');
        $tx = (new PaymentTransaction())->find($txId);
        $amount = (float) $refund->getAttribute('amount');
        try {
            if ($tx->getAttribute('gateway') === 'wallet') return $this->executeWalletRefund($refund);
            if ($tx->getAttribute('gateway') === 'paymob') {
                $gateway = $this->gatewayFactory ? ($this->gatewayFactory)($tx->toArray()) : new PaymobGateway();
                $reference=$refund->getAttribute('gateway_refund_reference');
                if ($reference) {
                    $result=$gateway->retrieveRefund((string)$reference,(string)$tx->getAttribute('gateway_transaction_id'),$amount,(string)$tx->getAttribute('currency'));
                } else {
                    $claimed=$this->db->query("UPDATE refunds SET gateway_request_started_at=NOW() WHERE id=? AND status='processing' AND gateway_request_started_at IS NULL",[(int)$refund->getAttribute('id')]);
                    if (!$claimed) { throw new RuntimeException('Refund requires manual reconciliation'); }
                    $result=$gateway->refundTransaction((string)$tx->getAttribute('gateway_transaction_id'),$amount,(string)$tx->getAttribute('currency'));
                }
                $status=$result['status'] ?? 'processing'; $reference=$result['id'] ?? null;
            } else {
                $stripe = $this->gatewayFactory ? ($this->gatewayFactory)($tx->toArray()) : ($tx->getAttribute('gateway') === 'stripe_operator' ? OperatorPayments::stripeFor((int) $tx->getAttribute('user_id')) : new StripeCheckoutService());
                if (!$stripe) { throw new RuntimeException('حساب Stripe غير متصل'); }
                $reference = $refund->getAttribute('gateway_refund_reference');
                $result = $reference ? $stripe->retrieveRefund((string) $reference) : $stripe->refundCheckout((string) $tx->getAttribute('gateway_transaction_id'), $amount, (string) $tx->getAttribute('currency'), (int) $refund->getAttribute('id'));
                $status = ($result['status'] ?? '') === 'succeeded' ? 'succeeded' : (in_array(($result['status'] ?? ''), ['failed', 'canceled'], true) ? 'failed' : 'processing');
                $reference = $result['id'] ?? null;
            }
            $changed = $this->db->transaction(function () use ($txId, $refund, $status, $reference, $tx) {
                $this->db->query('SELECT id FROM payment_transactions WHERE id = ? FOR UPDATE', [$txId]);
                $updated = $this->db->query('UPDATE refunds SET status = ?, gateway_refund_reference = ? WHERE id = ? AND status = ?', [$status, $reference, (int) $refund->getAttribute('id'), 'processing']);
                $total = $this->totalRefundedFor($txId);
                $newStatus = $total >= (float) $tx->getAttribute('amount') ? 'refunded' : ($total > 0 ? 'partially_refunded' : 'succeeded');
                $this->db->exec('UPDATE payment_transactions SET status = ? WHERE id = ?', [$newStatus, $txId]);
                return $updated;
            });
            if ($changed && $status === 'succeeded') {
                try {
                    if (class_exists('ActivityLog')) { ActivityLog::record('billing', 'billing.refund_succeeded', ['user_id' => (int) $refund->getAttribute('created_by_admin_id'), 'subject_type' => 'refunds', 'subject_id' => (int) $refund->getAttribute('id'), 'meta' => ['payment_transaction_id' => $txId, 'amount' => $amount]]); }
                    if (class_exists('Notification')) { Notification::notify((int) $tx->getAttribute('user_id'), 'refund_completed', 'تم استرجاع المبلغ', 'تم استرجاع ' . $amount . ' ' . $tx->getAttribute('currency') . ' إلى وسيلة الدفع الأصلية.', '/subscription'); }
                } catch (Throwable $ignored) { /* Payment outcome is authoritative despite notification failure. */ }
            }
            $fresh = (new Refund())->find((int) $refund->getAttribute('id'));
            return ['success' => $fresh->getAttribute('status') === 'succeeded', 'pending' => $fresh->getAttribute('status') === 'processing', 'refund' => $fresh->toArray()];
        } catch (Throwable $e) {
            // An ambiguous network/database error never releases the reserved amount.
            if (class_exists('Logger')) { Logger::warning('Refund requires reconciliation', ['refund_id' => $refund->getAttribute('id')]); }
            return ['success' => false, 'pending' => true, 'refund_id' => (int) $refund->getAttribute('id'), 'error' => 'حالة الاسترجاع تحتاج مصالحة مع البوابة؛ لا تكرر بطلب جديد'];
        }
    }

    public function reconcileWalletRefund(int $refundId): array
    {
        $refund=(new Refund())->find($refundId);
        if (!$refund) return ['success'=>false,'error'=>'الاسترجاع غير موجود'];
        return $this->executeWalletRefund($refund);
    }

    private function executeWalletRefund(Refund $refund): array
    {
        $id=(int)$refund->getAttribute('id');
        try {
            return $this->db->transaction(function()use($id){
                $identity=$this->db->query('SELECT user_id,payment_transaction_id FROM refunds WHERE id=?',[$id])[0]??null;
                if (!$identity) throw new RuntimeException('Refund missing');
                // Owner lock first: same order as wallet debits and usage settlements.
                $this->db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$identity['user_id']]);
                $tx=$this->db->query('SELECT * FROM payment_transactions WHERE id=? FOR UPDATE',[(int)$identity['payment_transaction_id']])[0]??null;
                $r=$this->db->query('SELECT * FROM refunds WHERE id=? FOR UPDATE',[$id])[0]??null;
                if (!$tx || $tx['gateway']!=='wallet' || !$r || (int)$r['user_id']!==(int)$tx['user_id']) throw new RuntimeException('Invalid wallet refund');
                if ($r['status']==='succeeded') return ['success'=>true,'already_applied'=>true,'refund'=>$r];
                if ($r['status']!=='processing') throw new RuntimeException('Refund is not pending');
                // Old uncertain refunds have no proof whether a wallet credit was already posted.
                if ($r['gateway_refund_reference']!=='wallet_reserved:'.$id) throw new RuntimeException('الاسترجاع القديم يحتاج مراجعة حركة المحفظة يدويًا قبل إضافة أي رصيد');
                if (strtoupper($tx['currency'])!=='USD') throw new RuntimeException('محفظة المنصة تدعم USD فقط؛ لا يتم تحويل العملة تلقائيًا');
                $key='wallet_refund_'.$id;
                $existing=$this->db->query('SELECT id,amount,user_id,currency,status FROM wallet_transactions WHERE idempotency_key=? LIMIT 1',[$key])[0]??null;
                if ($existing) {
                    if ((int)$existing['user_id']!==(int)$r['user_id'] || $existing['currency']!=='USD' || $existing['status']!=='completed' || abs((float)$existing['amount']-(float)$r['amount'])>0.001) throw new RuntimeException('Refund movement mismatch');
                    $movementId=(int)$existing['id'];
                } else {
                    $movement=new WalletTransaction();
                    $movement->fill(['user_id'=>(int)$r['user_id'],'type'=>'refund','amount'=>(float)$r['amount'],'currency'=>'USD','status'=>'completed','idempotency_key'=>$key,'reference_note'=>'استرجاع #'.$id,'approved_at'=>date('Y-m-d H:i:s'),'approved_by'=>(int)$r['created_by_admin_id']]);
                    if (!$movement->save()) throw new RuntimeException('Wallet credit failed');
                    $movementId=(int)$movement->getAttribute('id');
                }
                $this->db->query("UPDATE refunds SET status='succeeded',gateway_refund_reference=?,updated_at=NOW() WHERE id=?",[(string)$movementId,$id]);
                $total=$this->totalRefundedFor((int)$tx['id']);
                $this->db->query('UPDATE payment_transactions SET status=? WHERE id=?',[$total>=(float)$tx['amount']?'refunded':'partially_refunded',(int)$tx['id']]);
                $r['status']='succeeded'; $r['gateway_refund_reference']=(string)$movementId;
                return ['success'=>true,'pending'=>false,'refund'=>$r];
            });
        } catch(Throwable $e) {
            return ['success'=>false,'pending'=>true,'refund_id'=>$id,'error'=>$e instanceof RuntimeException?$e->getMessage():'تعذر تأكيد الاسترجاع؛ أعد المصالحة لنفس العملية'];
        }
    }

    private function totalRefundedFor(int $paymentTransactionId): float
    {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM refunds WHERE payment_transaction_id = ? AND status = 'succeeded'",
            [$paymentTransactionId]
        );
        return (float) ($rows[0]['total'] ?? 0);
    }

    private function resolveGatewayFor(string $gatewayKey): ?PaymentGatewayInterface
    {
        // حاليًا المحفظة بس مفعّلة فعليًا. أي بوابة حقيقية جديدة (Stripe
        // مثلاً) تتضاف هنا لما توجد Credentials حقيقية - من غير أي
        // تعديل على باقي RefundService.
        if ($gatewayKey === 'wallet') {
            return new WalletGatewayAdapter();
        }
        return null;
    }

    /** كل الاسترجاعات (لعرضها في لوحة الأدمن) */
    public function listAll(int $limit = 200): array
    {
        return $this->db->query(
            "SELECT r.*, u.email AS user_email, u.company_name
             FROM refunds r
             LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.created_at DESC LIMIT ?",
            [$limit]
        );
    }
}
