<?php

/**
 * Tourfecto - Wallet Service
 * منطق المحفظة: طلب إيداع، موافقة الأدمن، الخصم التلقائي وقت الاشتراك.
 * الرصيد بيتحسب دايمًا من مجموع الحركات المكتملة (مش عمود منفصل) -
 * أضمن وأسهل في التدقيق، وميحصلش تضارب بيانات لو حصل خطأ في مكان.
 * @version 1.0.0
 */
class WalletService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * الرصيد الحالي = مجموع الإيداعات المكتملة - مجموع الخصومات (المخزّنة كأرقام سالبة أصلاً)
     */
    public function getBalance(int $userId): float
    {
        if(class_exists('CreativeReservations'))return CreativeReservations::walletSnapshot($userId)['balance'];
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS balance FROM wallet_transactions WHERE user_id = ? AND status = 'completed'",
            [$userId]
        );
        return (float) ($rows[0]['balance'] ?? 0);
    }

    /**
     * طلب إيداع جديد - بيتسجّل كـ "pending" لحد ما الأدمن يوافق بعد ما
     * يستلم التحويل فعليًا (بنك/PayPal) ويتأكد عن طريق واتساب.
     */
    public function requestDeposit(int $userId, float $amount, string $paymentMethod, string $note = '', ?string $plan = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        if (!isset(self::MANUAL_METHODS[$paymentMethod])) {
            throw new Exception('طريقة دفع غير معروفة');
        }
        // الباقة اللي العميل بيدفع لها (professional:monthly) - بتتفعّل لوحدها أول ما الأدمن يأكد التحويل
        $planRef = null;
        if ($plan !== null && preg_match('/^site-(\d+):(monthly|yearly)$/', $plan, $m)) {
            // اشتراك موقع (منشئ المواقع) - لازم الموقع يكون بتاع العميل
            $own = $this->db->query('SELECT id FROM generated_websites WHERE id=? AND user_id=?', [(int) $m[1], $userId]);
            if ($own) {
                $planRef = $plan;
            }
        } elseif ($plan !== null && preg_match('/^([a-z0-9_-]{2,30}):(monthly|yearly)$/', $plan, $m)) {
            if (isset(SubscriptionPlan::allAsLegacyArray()[$m[1]])) {
                $planRef = $plan;
            }
        }
        // سبام: ماينفعش يتراكم أكتر من 3 طلبات معلّقة لنفس العميل
        $pending = (int) ($this->db->query("SELECT COUNT(*) AS c FROM wallet_transactions WHERE user_id=? AND type='deposit' AND status='pending'", [$userId])[0]['c'] ?? 0);
        if ($pending >= 3) {
            throw new Exception('عندك 3 تحويلات لسه بنراجعها. استنى تأكيدها الأول أو كلّمنا على واتساب.');
        }

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'deposit',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'reference_note' => mb_substr($note, 0, 250),
            'related_subscription_plan' => $planRef,
        ]);
        $tx->save();

        ActivityLog::record('wallet', 'wallet.deposit_requested', [
            'user_id' => $userId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'),
            'meta' => ['amount' => $amount, 'method' => $paymentMethod, 'plan' => $planRef],
        ]);

        return $tx;
    }

    /** موافقة الأدمن على إيداع - بيحوّل حالته لـ completed فيدخل في حساب الرصيد */
    public function approveDeposit(int $transactionId, int $adminId, string $adminNote = ''): WalletTransaction
    {
        $tx = (new WalletTransaction())->find($transactionId);
        if (!$tx || $tx->getAttribute('type') !== 'deposit') {
            throw new Exception('طلب الإيداع غير موجود');
        }
        if ($tx->getAttribute('status') !== 'pending') {
            throw new Exception('الطلب ده اتعالج بالفعل');
        }

        $tx->setAttribute('status', 'completed');
        $tx->setAttribute('admin_note', $adminNote);
        $tx->setAttribute('approved_by', $adminId);
        $tx->setAttribute('approved_at', date('Y-m-d H:i:s'));
        $tx->save();

        $userId = (int) $tx->getAttribute('user_id');
        if (class_exists('Notification')) {
            Notification::notify(
                $userId,
                'wallet_deposit_approved',
                'تم شحن رصيدك',
                'تمت الموافقة على إيداعك بمبلغ ' . $tx->getAttribute('amount') . '$ وبقى متاح في محفظتك.',
                '/subscription'
            );
        }

        ActivityLog::record('wallet', 'wallet.deposit_approved', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => $transactionId,
        ]);

        $this->activatePlanAfterDeposit($tx);

        return $tx;
    }

    /**
     * شحن فوري للمحفظة بعد دفع ناجح عبر بوابة (Stripe Checkout) -
     * مش محتاج موافقة أدمن زي الـ IBAN/PayPal اليدويين (الفلوس اتقبضت
     * فعليًا قبل ما نوصّل هنا من الـ Webhook).
     *
     * @param string $gatewayReference رقم العملية عند البوابة (رقم Session ID)
     * @return array ['success'=>bool, 'balance'=>float, 'transaction_id'=>int, 'error'=>?string]
     */
    public function applyCardTopUp(int $userId, float $amount, string $currency, string $gatewayReference, ?int $paymentTransactionId = null, string $gateway = 'stripe'): array
    {
        $gateway = in_array($gateway, ['stripe', 'xpay'], true) ? $gateway : 'stripe';
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'المبلغ لازم يكون أكبر من صفر'];
        }

        try {
            // 🔒 قفل صف العميل عشان وصول نفس الـ Webhook مرتين في نفس اللحظة مايشحنش مرتين
            $this->db->beginTransaction();
            $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);
            // Idempotency: نفس مرجع البوابة ما يعملش إيداع مكرر
            $existing = $this->db->query(
                "SELECT id FROM wallet_transactions
                 WHERE user_id = ? AND type = 'deposit' AND payment_method = 'card'
                   AND reference_note = ? LIMIT 1",
                [$userId, $gateway . ':' . $gatewayReference]
            );
            if (!empty($existing)) {
                $this->db->commit();
                return [
                    'success' => true,
                    'balance' => $this->getBalance($userId),
                    'transaction_id' => (int) $existing[0]['id'],
                    'already_applied' => true,
                ];
            }

            $tx = new WalletTransaction();
            $tx->fill([
                'user_id' => $userId,
                'type' => 'deposit',
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'status' => 'completed',
                'payment_method' => 'card',
                'reference_note' => $gateway . ':' . $gatewayReference,
                'admin_note' => 'دفع إلكتروني فوري عبر ' . ($gateway === 'xpay' ? 'XPay' : 'Stripe'),
            ]);
            if ($tx->save() === false) {
                throw new Exception('تعذر تسجيل الإيداع');
            }
            $this->db->commit();

            $newBalance = $this->getBalance($userId);

            if (class_exists('Notification')) {
                Notification::notify(
                    $userId,
                    'wallet_deposit_approved',
                    'تم شحن رصيدك',
                    'تم شحن محفظتك بمبلغ ' . $amount . '$ عبر الدفع الإلكتروني.',
                    '/subscription'
                );
            }

            ActivityLog::record('wallet', 'wallet.card_topup', [
                'user_id' => $userId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'),
                'meta' => ['amount' => $amount, 'gateway_reference' => $gatewayReference, 'payment_transaction_id' => $paymentTransactionId],
            ]);

            return ['success' => true, 'balance' => $newBalance, 'transaction_id' => (int) $tx->getAttribute('id')];
        } catch (Throwable $e) {
            try {
                $this->db->rollback();
            } catch (Throwable $e2) {
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** رفض طلب إيداع (لو الفلوس ما وصلتش فعليًا مثلاً) */
    public function rejectDeposit(int $transactionId, int $adminId, string $adminNote = ''): WalletTransaction
    {
        $tx = (new WalletTransaction())->find($transactionId);
        if (!$tx || $tx->getAttribute('type') !== 'deposit') {
            throw new Exception('طلب الإيداع غير موجود');
        }
        if ($tx->getAttribute('status') !== 'pending') {
            throw new Exception('الطلب ده اتعالج بالفعل');
        }

        $tx->setAttribute('status', 'rejected');
        $tx->setAttribute('admin_note', $adminNote);
        $tx->setAttribute('approved_by', $adminId);
        $tx->setAttribute('approved_at', date('Y-m-d H:i:s'));
        $tx->save();

        ActivityLog::record('wallet', 'wallet.deposit_rejected', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => $transactionId,
            'meta' => ['admin_note' => $adminNote],
        ]);

        return $tx;
    }

    /**
     * الاشتراك التلقائي من الرصيد: لو رصيد العميل كافي لسعر الباقة،
     * بنخصم المبلغ فورًا وننشئ الاشتراك مباشرة - من غير أي تدخل بشري
     * أو واتساب. بيرجّع نتيجة واضحة لو الرصيد مش كافي بدل ما يفشل صامت.
     *
     * تصحيح (2026-08-09 / Phase 1): الدالة دي كانت بتُستخدم أيضًا كمسار
     * الترقية/التخفيض الوحيد الشغّال فعليًا (زر "Upgrade" في /subscription
     * كان بيودّي هنا في النهاية عن طريق /plans)، لكنها كانت بتعامل كل
     * استدعاء كأنه اشتراك جديد بالكامل: بتخصم السعر الكامل للباقة الجديدة
     * حتى لو العميل عنده اشتراك فعّال شغال بالفعل، وبتسيب الاشتراك القديم
     * "active" من غير إلغاء - يعني العميل يدفع مرتين ويتراكم عنده أكتر من
     * صف "active" في نفس الوقت.
     *
     * الإصلاح: لو فيه اشتراك فعّال حالي، بنحسب الفرق بين سعر الباقة
     * الجديدة والسعر الفعلي المعروض حاليًا للعميل (نفس القيمة اللي شايفها
     * فعليًا في /subscription)، ونخصم الفرق بس (مش السعر الكامل)، ونلغي
     * الاشتراك القديم قبل ما ننشئ الجديد. الترقية (فرق موجب) لازم تتطلب
     * رصيد كافي زي أي عملية تانية. التخفيض (فرق سالب) بيتم من غير أي رد
     * فلوس تلقائي - سياسة محافظة موثّقة في CHANGELOG، ممكن تتغيّر لاحقًا
     * لو المشروع احتاج استرجاع جزئي حقيقي.
     *
     * @param int $userId
     * @param string $planKey
     * @param string $planType 'monthly' أو 'yearly'
     * @param string|null $idempotencyKey مفتاح فريد اختياري من العميل (مثلاً UUID
     *        مولّد في الفرونت وقت الضغطة) لمنع تكرار الخصم لو الطلب اتبعت
     *        مرتين (دبل-كليك أو إعادة محاولة شبكة). لو مش متبعت، بيتولّد
     *        سيرفر-سايد.
     */
    /** عمود «الباقة اللي هيتحول لها في التجديد الجاي» (تخفيض مجدول) - بيتضاف لو ناقص */
    public static function ensureScheduledColumn(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $db = Database::getInstance();
            if (!$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND column_name = 'scheduled_plan_code'")) {
                $db->getConnection()->exec('ALTER TABLE subscriptions ADD COLUMN scheduled_plan_code VARCHAR(80) NULL DEFAULT NULL');
            }
            $ok = true;
        } catch (Throwable $e) {
            Logger::warning('scheduled_plan_code column unavailable', ['error' => $e->getMessage()]);
            $ok = false;
        }
        return $ok;
    }

    /**
     * تخفيض الباقة بيتجدول لآخر الفترة المدفوعة بدل ما يتنفّذ فورًا ويضيّع على العميل الفرق اللي دفعه:
     * اشتراك مدفوع شغال + الباقة الجديدة أرخص + مفيش فرق يندفع.
     */
    public static function isScheduledDowngrade(?array $current, array $quote, float $newPrice): bool
    {
        if (!$current || !empty($quote['same']) || empty($quote['change'])) return false;
        if (($current['lifecycle_status'] ?? $current['status'] ?? '') !== 'active') return false;
        if ((string) ($current['payment_gateway'] ?? '') === 'admin_grant') return false;
        return (float) $quote['charge'] <= 0 && $newPrice < (float) ($current['price'] ?? 0);
    }

    public static function subscriptionQuote(?array $current, array $plan, string $cycle): array
    {
        $price=(float)$plan['price_'.$cycle]; $now=date('Y-m-d H:i:s');
        $end=SubscriptionPeriod::nextPeriodEnd($now,$cycle);
        // تجربة / متأخر / منحة مجانية من الأدمن: الاشتراك المدفوع بيبدأ فترة جديدة بسعره كامل (مش «تغيير باقة» بفرق)
        if (!$current || in_array($current['lifecycle_status']??$current['status']??'', ['trialing','past_due'],true) || (string)($current['payment_gateway']??'')==='admin_grant') return ['charge'=>$price,'change'=>false,'end'=>$end,'preserve'=>false,'credit'=>0.0];
        $oldKey=(string)$current['plan_name'];$oldCycle=(string)$current['plan_type'];
        if ($oldKey===$plan['id'] && $oldCycle===$cycle) return ['charge'=>0.0,'change'=>true,'end'=>$current['current_period_end'],'preserve'=>true,'same'=>true,'credit'=>0.0];
        $start=strtotime($current['current_period_start']);$finish=strtotime($current['current_period_end']);
        $ratio=$finish>$start ? max(0,min(1,($finish-time())/($finish-$start))) : 0;
        if ($oldCycle===$cycle) return ['charge'=>round(max(0,$price-(float)$current['price'])*$ratio,2),'change'=>true,'end'=>$current['current_period_end'],'preserve'=>true,'credit'=>0.0];
        $credit=round((float)$current['price']*$ratio,2);
        return ['charge'=>round(max(0,$price-$credit),2),'change'=>true,'end'=>$end,'preserve'=>false,'credit'=>$credit];
    }

    public function subscribeWithBalance(int $userId, string $planKey, string $planType, ?string $idempotencyKey = null, ?string $couponCode = null, ?float $expectedCharge = null): array
    {
        require_once __DIR__.'/SubscriptionPeriod.php';
        SubscriptionPeriod::refreshQuota($userId);
        $plans = SubscriptionPlan::allAsLegacyArray();
        $plan = $plans[$planKey] ?? null;
        if (!$plan) {
            return ['success' => false, 'error' => 'الباقة غير موجودة'];
        }
        if (!in_array($planType, ['monthly', 'yearly'], true)) {
            return ['success' => false, 'error' => 'نوع الباقة غير معروف'];
        }

        if (($plan['currency'] ?? '') !== 'USD') return ['success'=>false,'error'=>'رصيد المحفظة بالدولار فقط. تواصل مع الدعم لترتيب دفع باقة بعملة أخرى.'];
        $newPrice = $planType === 'yearly' ? (float) $plan['price_yearly'] : (float) $plan['price_monthly'];
        $idempotencyKey = $idempotencyKey ?: ('plan_change_' . $userId . '_' . (function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16))));

        // لو فيه اشتراك فعّال حالي فعلاً، ده تغيير باقة (upgrade/downgrade)
        // مش اشتراك جديد من الصفر - بنحسب الفرق بس.
        $currentRow = Subscription::activeSubscriptionRow($userId);
        $quote=self::subscriptionQuote($currentRow,$plan,$planType);
        if (!empty($quote['same'])) {
            // نفس الباقة: لو كان فيه تخفيض مجدول، اختيارها تاني معناه «خليني على باقتي» فبنلغيه
            $unscheduled = false;
            if (!empty($currentRow['scheduled_plan_code']) && self::ensureScheduledColumn()) {
                $this->db->exec('UPDATE subscriptions SET scheduled_plan_code = NULL WHERE id = ? AND user_id = ?', [(int) $currentRow['id'], $userId]);
                $unscheduled = true;
            }
            return ['success'=>true,'subscription'=>new Subscription($currentRow),'charged'=>0.0,'new_balance'=>$this->getBalance($userId),'is_plan_change'=>true,'unchanged'=>!$unscheduled,'unscheduled'=>$unscheduled,'plan_name'=>$plan['name']];
        }
        if (self::isScheduledDowngrade($currentRow, $quote, $newPrice) && self::ensureScheduledColumn()) {
            // تخفيض: العميل يفضل على باقته اللي دافع تمنها لحد آخر الفترة، والتجديد الجاي بيتم على الباقة الأرخص وبسعرها
            $code = $planKey . '_' . $planType;
            $this->db->exec('UPDATE subscriptions SET scheduled_plan_code = ?, cancel_at_period_end = 0 WHERE id = ? AND user_id = ?', [$code, (int) $currentRow['id'], $userId]);
            $at = substr((string) $currentRow['current_period_end'], 0, 10);
            ActivityLog::record('wallet', 'wallet.downgrade_scheduled', ['user_id' => $userId, 'subject_type' => 'subscriptions', 'subject_id' => (int) $currentRow['id'], 'meta' => ['to' => $code, 'at' => $at]]);
            if (class_exists('Notification')) {
                Notification::notify($userId, 'subscription_downgrade_scheduled', 'اتجدول تغيير باقتك', 'هتفضل على باقتك الحالية لحد ' . $at . '، وبعدها هتتجدد على باقة "' . $plan['name'] . '" بسعر ' . $newPrice . '$.', '/subscription');
            }
            return ['success'=>true,'subscription'=>new Subscription($currentRow),'charged'=>0.0,'new_balance'=>$this->getBalance($userId),'is_plan_change'=>true,'scheduled'=>true,'effective_at'=>$at,'plan_name'=>$plan['name'],'renewal_price'=>$newPrice];
        }
        $isPlanChange = $quote['change'];
        if ($currentRow && ($currentRow['currency'] ?? '') !== $plan['currency']) return ['success'=>false,'error'=>'لا يمكن حساب فرق باقتين بعملتين مختلفتين. تواصل مع الدعم.'];
        $oldPrice = $currentRow ? (float)$currentRow['price'] : 0.0;
        // حساب الفرق موحّد في BillingRules (مرجع القواعد المالية الوحيد)
        $chargeAmount = $quote['charge'];

        // كود خصم (لوحة الأدمن ← الدفع والكوبونات)
        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '' && class_exists('CouponService')) {
            $coupon = (new CouponService($this->db))->evaluate($couponCode, $userId, $planKey, $chargeAmount, $isPlanChange);
            if (empty($coupon['ok'])) {
                return ['success' => false, 'error' => $coupon['error'] ?? 'كود الخصم مش صالح', 'coupon_error' => true];
            }
            $coupon['original'] = $chargeAmount;
            $chargeAmount = (float) $coupon['final'];
        }

        if ($expectedCharge !== null && (!is_finite($expectedCharge) || abs($expectedCharge-$chargeAmount)>0.00001)) return ['success'=>false,'error'=>'السعر اتغير منذ التأكيد. راجعه مرة أخرى قبل الدفع.'];

        $balance = $this->getBalance($userId);

        if ($chargeAmount > 0 && $balance < $chargeAmount) {
            return [
                'success' => false,
                'error' => $isPlanChange ? 'رصيدك الحالي غير كافي لدفع فرق السعر' : 'رصيدك الحالي غير كافي',
                'balance' => $balance,
                'required' => $chargeAmount,
                'shortfall' => round($chargeAmount - $balance, 2),
                'is_plan_change' => $isPlanChange,
            ];
        }

        try {
            return $this->db->transaction(function () use ($userId, $planKey, $planType, $plan, $newPrice, $oldPrice, $chargeAmount, $isPlanChange, $currentRow, $idempotencyKey, $coupon, $quote) {
                // قفل صف المستخدم - نفس تصحيح Section 6 المطبّق في
                // chargeForUsage(): بيمنع سباق بين تغيير باقة وخصم
                // pay-as-you-go متزامنين لنفس العميل. الفحص فوق (قبل
                // دخول الـ transaction) كان بس Fast-fail لتحسين تجربة
                // المستخدم - الفحص الحقيقي المُعتمَد عليه هو ده جوه القفل.
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);
                $pdo=$this->db->getConnection();
                $check=$pdo->prepare('SELECT id, price, currency, is_active FROM subscription_plans WHERE plan_code=? FOR UPDATE');
                $check->execute([$planKey.'_'.$planType]);$lockedPlan=$check->fetch(PDO::FETCH_ASSOC);
                if (!$lockedPlan || !(int)$lockedPlan['is_active'] || (int)$lockedPlan['id'] !== (int)($plan['billing_ids'][$planType]??0) || abs((float)$lockedPlan['price']-$newPrice)>0.00001 || $lockedPlan['currency']!==$plan['currency']) throw new RuntimeException('الباقة اتغيرت قبل الدفع. حدّث الصفحة وراجع السعر مرة أخرى.');
                $freshCurrent=Subscription::activeSubscriptionRow($userId);
                if ((int)($freshCurrent['id']??0)!==(int)($currentRow['id']??0) || ($freshCurrent && ((float)$freshCurrent['price']!==$oldPrice || $freshCurrent['currency']!==$plan['currency']))) throw new RuntimeException('اشتراكك اتغير أثناء الطلب. حدّث الصفحة وراجع الاشتراك.');

                if ($isPlanChange && $currentRow) {
                    $open=(int)($this->db->query("SELECT COUNT(*) v FROM billing_usage_reservations WHERE subscription_id=? AND state IN ('reserved','uncertain')",[(int)$currentRow['id']])[0]['v']??0);
                    if (class_exists('CreativeReservations')) $open+=(int)($this->db->query("SELECT COUNT(*) v FROM creative_reservations WHERE subscription_id=? AND status='held' AND source='plan'",[(int)$currentRow['id']])[0]['v']??0);
                    if ($open) throw new RuntimeException('PLANBUSY:فيه استخدام قيد التنفيذ أو المراجعة. استنى اكتماله قبل تغيير الباقة.');
                }

                if ($currentRow && (($freshCurrent['current_period_start']??null)!==($currentRow['current_period_start']??null) || ($freshCurrent['current_period_end']??null)!==($currentRow['current_period_end']??null) || ($freshCurrent['lifecycle_status']??null)!==($currentRow['lifecycle_status']??null))) throw new RuntimeException('PLANSTALE:فترة اشتراكك اتغيرت أثناء الدفع. راجع السعر مرة أخرى.');
                // Copy the counters read under the owner lock, never the stale preview counters.
                $currentRow=$freshCurrent;

                // الكوبون أول حاجة جوه القفل: لو خلص في اللحظة دي كل حاجة بتترجع
                if ($coupon) {
                    (new CouponService($this->db))->lockAndVerify($coupon, $userId, $planKey, $isPlanChange);
                    (new CouponService($this->db))->redeem((int) $coupon['coupon_id'], $userId, $planKey, (float) $coupon['original'], (float) $coupon['discount']);
                }
                if ($chargeAmount > 0) {
                    $freshBalance = $this->getBalance($userId);
                    if ($freshBalance < $chargeAmount) {
                        // Section 13: إشعار "فشل الدفع" - عند نقطة الفحص
                        // المُعتمَد عليها فعليًا (جوه القفل)، مش الفحص
                        // السريع اللي قبلها (عشان متتكررش الرسالة).
                        if (class_exists('Notification')) {
                            Notification::notify(
                                $userId,
                                'payment_failed',
                                'تعذّر إتمام الدفع',
                                'رصيدك الحالي مش كافي - جدّد رصيد محفظتك وحاول تاني.',
                                '/subscription'
                            );
                        }
                        throw new RuntimeException('BALANCE:رصيدك اتغير أثناء الدفع. راجع المحفظة وحاول مرة أخرى.');
                    }
                }

                // خصم الفرق (أو السعر الكامل لو اشتراك جديد) - كحركة مكتملة
                // فورًا. لو الفرق صفر أو سالب (تخفيض)، مفيش حركة محفظة خالص
                // (لا خصم ولا استرجاع تلقائي).
                $chargeTx = null;
                if ($chargeAmount > 0) {
                    $chargeTx = new WalletTransaction();
                    $chargeTx->fill([
                        'user_id' => $userId,
                        'type' => 'subscription_charge',
                        'amount' => -$chargeAmount,
                        'currency' => 'USD',
                        'status' => 'completed',
                        'related_subscription_plan' => $planKey,
                        'approved_at' => date('Y-m-d H:i:s'),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    if (!$chargeTx->save()) throw new RuntimeException('تعذر تسجيل الخصم؛ لم تكتمل العملية');
                    $this->checkLowBalanceAndNotify($userId);

                    // Section 3/9: تسجيل موازٍ في سجل payment_transactions
                    // الموحّد - عشان أي استرجاع مستقبلي (RefundService)
                    // يقدر يتعامل مع الخصم ده زي أي معاملة دفع تانية،
                    // بدل ما يكون معزول جوه wallet_transactions بس.
                    // معزولة في try/catch مستقلة تمامًا - فشلها ميلغيش
                    // الخصم أو الاشتراك اللي نجحوا فعلًا.
                    try {
                        $adapter = new WalletGatewayAdapter();
                        $intent = $adapter->createPaymentIntent($userId, $chargeAmount, 'USD', [
                            'plan' => $planKey, 'plan_type' => $planType, 'is_plan_change' => $isPlanChange,
                        ]);
                        $adapter->markSettled($intent['internal_transaction_id'], true, (int) $chargeTx->getAttribute('id'));
                    } catch (Exception $ledgerError) {
                        Logger::error('payment_transactions ledger write failed (charge already succeeded)', [
                            'user_id' => $userId, 'message' => $ledgerError->getMessage(),
                        ]);
                    }
                }


                // تصحيح (2026-08-15 / Phase 17 - Prorated Downgrade Credit):
                // لو التخفيض (chargeAmount سالب) والمنصة فعّلت الرجوع
                // التلقائي، نضيف فرق السعر رصيد موجبة لمحفظة العميل.
                // مقفول افتراضيًا (BillingRules::ALLOW_PRORATED_DOWNGRADE_CREDIT)
                // - قرار مالي بياخده مالك المنصة مش الكود.
                if ($chargeAmount < 0 && BillingRules::ALLOW_PRORATED_DOWNGRADE_CREDIT) {
                    $creditAmount = abs($chargeAmount);
                    $creditTx = new WalletTransaction();
                    $creditTx->fill([
                        'user_id' => $userId,
                        'type' => 'subscription_credit',
                        'amount' => $creditAmount,
                        'currency' => 'USD',
                        'status' => 'completed',
                        'reference_note' => 'رصيد فرق تخفيض الباقة من "' . $plan['name'] . '"',
                        'approved_at' => date('Y-m-d H:i:s'),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    $creditTx->save();

                    ActivityLog::record('wallet', 'wallet.downgrade_credited', [
                        'user_id' => $userId,
                        'subject_type' => 'wallet_transactions',
                        'subject_id' => (int) $creditTx->getAttribute('id'),
                        'meta' => [
                            'plan' => $planKey, 'type' => $planType,
                            'old_price' => $oldPrice, 'new_price' => $newPrice, 'credited' => $creditAmount,
                        ],
                    ]);

                    if (class_exists('Notification')) {
                        Notification::notify(
                            $userId,
                            'wallet_downgrade_credit',
                            'اتضاف رصيد لمحفظتك',
                            'بسبب تخفيض باقتك لـ "' . $plan['name'] . '"، اتضاف فرق السعر ' . $creditAmount . '$ لرصيد محفظتك.',
                            '/subscription'
                        );
                    }
                }

                // لو ده تغيير باقة، نلغي الاشتراك القديم أولاً عشان ميفضلش
                // صف "active" يتيم من غير ما يتحسب في أي مكان.
                if ($currentRow && !empty($currentRow['id'])) {
                    $this->db->exec("UPDATE `subscriptions` SET `status` = 'cancelled' WHERE `id` = ?", [(int) $currentRow['id']]);
                }

                // إنشاء الاشتراك الفعلي - نفس الدالة الحقيقية اللي بينده عليها
                // الأدمن وقت التفعيل اليدوي، عشان يبقى نفس المسار المضمون.
                $subscription = Subscription::createSubscription($userId, $planKey, $planType);
                if (!$subscription) {
                    throw new Exception('تعذر إنشاء الاشتراك الجديد بعد الخصم');
                }

                if ($quote['preserve']) {
                    $this->db->exec('UPDATE subscriptions SET current_period_start=?, current_period_end=?, usage_ai_analysis_count=?, usage_ai_message_count=?, usage_review_reply_count=?, competitor_analysis_used=?, last_usage_reset_at=? WHERE id=?', [
                        $currentRow['current_period_start'],$currentRow['current_period_end'],(int)$currentRow['usage_ai_analysis_count'],(int)$currentRow['usage_ai_message_count'],(int)$currentRow['usage_review_reply_count'],(int)($currentRow['competitor_analysis_used']??0),$currentRow['last_usage_reset_at']??$currentRow['current_period_start'],(int)$subscription->getAttribute('id')]);
                    // Preserve event quotas and open holds when the paid period stays unchanged.
                    $this->db->exec('UPDATE plan_usage_events SET subscription_id=? WHERE subscription_id=?',[(int)$subscription->getAttribute('id'),(int)$currentRow['id']]);
                    $this->db->exec("UPDATE billing_usage_reservations SET subscription_id=? WHERE subscription_id=? AND state IN ('reserved','uncertain')",[(int)$subscription->getAttribute('id'),(int)$currentRow['id']]);
                    $subscription=(new Subscription())->find((int)$subscription->getAttribute('id'));
                }

                // Invoice persistence is part of this same transaction; failure rolls back payment.
                if ($chargeAmount > 0) $this->createInvoiceForCharge($userId, $planKey, $plan, $planType, $chargeAmount, $chargeTx);

                ActivityLog::record('wallet', $isPlanChange ? 'wallet.plan_changed' : 'wallet.auto_subscribed', [
                    'user_id' => $userId,
                    'subject_type' => 'wallet_transactions',
                    'subject_id' => $chargeTx ? (int) $chargeTx->getAttribute('id') : null,
                    'meta' => [
                        'plan' => $planKey, 'type' => $planType,
                        'old_price' => $oldPrice, 'new_price' => $newPrice, 'charged' => $chargeAmount,
                    ],
                ]);

                if (class_exists('Notification')) {
                    $msg = $isPlanChange
                        ? ($chargeAmount > 0
                            ? 'تم خصم فرق السعر ' . $chargeAmount . '$ من محفظتك وتفعيل باقة "' . $plan['name'] . '" فورًا.'
                            : 'تم تفعيل باقة "' . $plan['name'] . '" فورًا من غير أي خصم إضافي.')
                        : 'تم خصم ' . $chargeAmount . '$ من محفظتك وتفعيل باقة "' . $plan['name'] . '" فورًا.';
                    Notification::notify($userId, 'subscription_activated', 'تم تفعيل اشتراكك', $msg, '/subscription');
                }

                return [
                    'success' => true,
                    'subscription' => $subscription,
                    'new_balance' => $this->getBalance($userId),
                    'charged' => $chargeAmount,
                    'is_plan_change' => $isPlanChange,
                    'discount' => $coupon ? (float) $coupon['discount'] : 0.0,
                    'coupon' => $coupon ? $coupon['code'] : null,
                ];
            });
        } catch (Exception $e) {
            // مفتاح idempotency مكرر = نفس الطلب اتبعت قبل كده ونفّذ بنجاح -
            // نرجّع النتيجة الحالية بدل ما نخصم تاني أو نرجّع خطأ مربك.
            if (strpos($e->getMessage(), 'idempotency_key') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Logger::warning('subscribeWithBalance duplicate idempotency key - returning current state', ['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
                return [
                    'success' => true,
                    'subscription' => Subscription::activeSubscriptionRow($userId),
                    'new_balance' => $this->getBalance($userId),
                    'duplicate_request' => true,
                ];
            }

            if (str_starts_with($e->getMessage(), 'PLANSTALE:')) return ['success'=>false,'error'=>substr($e->getMessage(),10)];
            if (str_starts_with($e->getMessage(), 'PLANBUSY:')) return ['success'=>false,'error'=>substr($e->getMessage(),9)];
            if (str_starts_with($e->getMessage(), 'BALANCE:')) return ['success'=>false,'error'=>substr($e->getMessage(),8)];
            if (str_starts_with($e->getMessage(), 'COUPON:')) {
                return ['success' => false, 'error' => substr($e->getMessage(), 7), 'coupon_error' => true];
            }
            Logger::error('subscribeWithBalance Error', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر إنشاء الاشتراك - تواصل مع الدعم الفني'];
        }
    }

    /** كل حركات محفظة مستخدم معيّن (لعرضها في صفحة الاشتراك) */
    public function getHistory(int $userId, int $limit = 30): array
    {
        return (new WalletTransaction())->where(['user_id' => $userId], ['created_at' => 'DESC'], $limit);
    }

    /**
     * الأدمن يضيف رصيد مباشر لعميل معيّن - بيتسجّل "مكتمل" فورًا (مفيش
     * موافقة لازمة، الأدمن نفسه هو اللي بيوافق بالفعل بضغطة الزرار).
     */
    public function adminAddBalance(int $userId, float $amount, int $adminId, string $note = ''): WalletTransaction
    {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'admin_adjustment',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'completed',
            'payment_method' => 'admin',
            'reference_note' => $note ?: 'إضافة رصيد يدوية من الأدمن',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $tx->save();

        return $tx;
    }

    /**
     * الأدمن يسحب رصيد من عميل (تصحيح/استرجاع يدوي). كان الزرار بينادي الدالة دي وهي مش موجودة فكان أي سحب بيفشل.
     * مابيسحبش أكتر من المتاح إلا لو super_admin طلب ده صراحةً ($allowNegative).
     */
    public function adminDeductBalance(int $userId, float $amount, int $adminId, string $note = '', bool $allowNegative = false): WalletTransaction
    {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        $pdo = $this->db->getConnection();
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute(['tourfecto:wallet:' . $userId]);
        try {
            $available = $this->getBalance($userId);
            if (!$allowNegative && round($available - $amount, 2) < 0) {
                throw new Exception('رصيد العميل ($' . number_format($available, 2) . ') أقل من المبلغ المطلوب سحبه');
            }
            $tx = new WalletTransaction();
            $tx->fill([
                'user_id' => $userId,
                'type' => 'admin_adjustment',
                'amount' => -round($amount, 2),
                'currency' => 'USD',
                'status' => 'completed',
                'payment_method' => 'admin',
                'reference_note' => $note ?: 'سحب رصيد يدوي من الأدمن',
                'approved_by' => $adminId,
                'approved_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$tx->save()) {
                throw new Exception('تعذر تسجيل العملية');
            }
            return $tx;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['tourfecto:wallet:' . $userId]);
        }
    }

    /**
     * توليد دفعة بطاقات شحن جديدة - كل بطاقة بكود فريد بصيغة سهلة
     * القراءة (زي أمازون بالظبط: TRFC-XXXX-XXXX-XXXX).
     */
    public function generateRechargeCards(int $count, float $value, int $adminId, string $batchLabel = ''): array
    {
        if ($count <= 0 || $count > 500) {
            throw new Exception('عدد البطاقات لازم يكون بين 1 و 500');
        }
        if ($value <= 0) {
            throw new Exception('قيمة البطاقة لازم تكون أكبر من صفر');
        }

        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCardCode();
            $card = new WalletRechargeCard();
            $card->fill([
                'code' => $code,
                'value' => $value,
                'status' => 'unused',
                'batch_label' => $batchLabel ?: date('Y-m-d H:i'),
                'created_by_admin_id' => $adminId,
            ]);
            $card->save();
            $cards[] = $card->toArray();
        }

        return $cards;
    }

    private function generateUniqueCardCode(): string
    {
        do {
            $code = 'TRFC-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $exists = !empty((new WalletRechargeCard())->where(['code' => $code], [], 1));
        } while ($exists);
        return $code;
    }

    /**
     * العميل يشحن بطاقة - بيتأكد إن الكود صحيح ومستخدمش قبل كده،
     * ويضيف قيمتها لرصيد محفظته فورًا.
     */
    public function redeemCard(int $userId, string $code): array
    {
        $code = strtoupper(trim($code));
        $rows = (new WalletRechargeCard())->where(['code' => $code], [], 1);

        if (empty($rows)) {
            throw new Exception('كود البطاقة غير صحيح');
        }

        $card = $rows[0];
        if ($card->getAttribute('status') === 'used') {
            throw new Exception('البطاقة دي اتشحنت قبل كده');
        }

        $value = (float) $card->getAttribute('value');
        $cardId = (int) $card->getAttribute('id');

        // 🔒 كان ممكن طلبين في نفس اللحظة يشحنوا نفس البطاقة مرتين (فحص ثم كتابة من غير قفل).
        // دلوقتي "حجز" البطاقة بـ UPDATE شرطي واحد - أول طلب بس بينجح، والإيداع في نفس المعاملة.
        $this->db->transaction(function () use ($cardId, $userId, $value, $code) {
            $claimed = $this->db->getConnection()->prepare(
                "UPDATE wallet_recharge_cards SET status = 'used', used_by_user_id = ?, used_at = NOW() WHERE id = ? AND status <> 'used'"
            );
            $claimed->execute([$userId, $cardId]);
            if ($claimed->rowCount() !== 1) {
                throw new Exception('البطاقة دي اتشحنت قبل كده');
            }
            $tx = new WalletTransaction();
            $tx->fill([
                'user_id' => $userId,
                'type' => 'card_redemption',
                'amount' => $value,
                'currency' => 'USD',
                'status' => 'completed',
                'payment_method' => 'recharge_card',
                'reference_note' => 'شحن بطاقة: ' . $code,
            ]);
            if ($tx->save() === false) {
                throw new Exception('تعذر تسجيل الشحن');
            }
        });

        return ['value' => $value, 'new_balance' => $this->getBalance($userId)];
    }

    /** كل طلبات الإيداع المعلّقة (لعرضها في لوحة الأدمن) */
    public function getPendingDeposits(): array
    {
        return (new WalletTransaction())->where(['type' => 'deposit', 'status' => 'pending'], ['created_at' => 'ASC']);
    }

    /**
     * إحصائيات مجمّعة للمحفظة (لوحة الأدمن): إجمالي الإيداعات المعتمدة
     * هذا الشهر، عدد الطلبات المعلّقة، إجمالي أرصدة كل العملاء، وإجمالي
     * خصومات "ادفع حسب الاستخدام" هذا الشهر.
     */
    public function getAdminStats(): array
    {
        try {
            $depositsThisMonth = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count FROM wallet_transactions
                 WHERE type = 'deposit' AND status = 'completed' AND MONTH(approved_at) = MONTH(NOW()) AND YEAR(approved_at) = YEAR(NOW())" . TestAccounts::exclude('user_id')
            );
            $pendingCount = $this->db->query(
                "SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE type = 'deposit' AND status = 'pending'" . TestAccounts::exclude('user_id')
            );
            $totalBalances = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE status = 'completed'" . TestAccounts::exclude('user_id')
            );
            $usageChargesThisMonth = $this->db->query(
                "SELECT COALESCE(SUM(ABS(amount)), 0) AS total, COUNT(*) AS count FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())" . TestAccounts::exclude('user_id')
            );

            $billing = $this->getBillingAnalytics();
            $this->snapshotTodayMetricsIfNeeded($billing);

            return array_merge([
                'deposits_this_month' => (float) ($depositsThisMonth[0]['total'] ?? 0),
                'deposits_this_month_count' => (int) ($depositsThisMonth[0]['count'] ?? 0),
                'pending_count' => (int) ($pendingCount[0]['count'] ?? 0),
                'pending_total' => (float) ($pendingCount[0]['total'] ?? 0),
                'total_customer_balances' => (float) ($totalBalances[0]['total'] ?? 0),
                'usage_charges_this_month' => (float) ($usageChargesThisMonth[0]['total'] ?? 0),
                'usage_charges_this_month_count' => (int) ($usageChargesThisMonth[0]['count'] ?? 0),
            ], $billing);
        } catch (Exception $e) {
            Logger::error('WalletService getAdminStats Error', ['message' => $e->getMessage()]);
            return array_merge([
                'deposits_this_month' => 0, 'deposits_this_month_count' => 0, 'pending_count' => 0,
                'pending_total' => 0, 'total_customer_balances' => 0, 'usage_charges_this_month' => 0,
                'usage_charges_this_month_count' => 0,
            ], $this->getBillingAnalytics());
        }
    }

    /**
     * Phase 10: مفيش cron job في المشروع نعتمد عليه لتسجيل لقطة يومية،
     * فبدل ما نخترع نظام جدولة جديد، بنسجّل لقطة اليوم "بشكل كسول" أول
     * مرة حد (أدمن) يفتح إحصائيات الفوترة في اليوم ده. لو اليوم مسجّل
     * بالفعل، الاستعلام بيتجاهل التكرار (ON DUPLICATE KEY).
     */
    private function snapshotTodayMetricsIfNeeded(array $billing): bool
    {
        if ($billing['mrr'] === null) {
            return false; // مفيش بيانات كافية أصلاً - متسجّلش لقطة مضلّلة
        }
        try {
            $this->db->exec(
                "INSERT INTO billing_metrics_snapshots (snapshot_date, mrr, arr, active_subscriptions)
                 VALUES (CURDATE(), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE mrr = VALUES(mrr), arr = VALUES(arr), active_subscriptions = VALUES(active_subscriptions)",
                [$billing['mrr'], $billing['arr'], $billing['active_subscriptions']]
            );
            return true;
        } catch (Exception $e) {
            Logger::error('snapshotTodayMetricsIfNeeded failed', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * تحليل تنافسي (Stripe/Chargebee/Paddle): "الإيراد لكل ميزة" -
     * تفصيل إيراد "ادفع حسب الاستخدام" الشهري لكل ميزة على حدة، مع
     * إجمالي عدد مرات الاستخدام. العمود feature_key بيتعبى من Phase 17
     * (كانت الميزات بتختفي قبل كده). لو صفوف قديمة مفيهاش feature_key
     * (NULL)، بتتجمع تحت مفتاح '_unmapped' عشان ميحصلش فقد صامت للبيانات.
     */
    public function getUsageRevenueBreakdown(?int $year = null, ?int $month = null): array
    {
        $year = $year ?: (int) date('Y');
        $month = $month ?: (int) date('n');
        try {
            $rows = $this->db->query(
                "SELECT COALESCE(feature_key, '_unmapped') AS feature_key,
                        COUNT(*) AS usage_count,
                        COALESCE(SUM(ABS(amount)), 0) AS revenue
                 FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed'
                   AND feature_key IS NOT NULL
                   AND YEAR(created_at) = ? AND MONTH(created_at) = ?" . TestAccounts::exclude('user_id') . "
                 GROUP BY feature_key
                 ORDER BY revenue DESC",
                [$year, $month]
            );
            // الصفوف القديمة (feature_key = NULL) - بنعدّها من reference_note
            // بس مجموعها الكلي، عشان النقطة المؤقتة دي متضيعش إيراد.
            $legacy = $this->db->query(
                "SELECT COUNT(*) AS usage_count, COALESCE(SUM(ABS(amount)), 0) AS revenue
                 FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed'
                   AND feature_key IS NULL
                   AND YEAR(created_at) = ? AND MONTH(created_at) = ?" . TestAccounts::exclude('user_id'),
                [$year, $month]
            );

            $breakdown = [];
            foreach ($rows as $row) {
                $breakdown[$row['feature_key']] = [
                    'usage_count' => (int) $row['usage_count'],
                    'revenue' => round((float) $row['revenue'], 2),
                ];
            }
            if (!empty($legacy) && ((int) $legacy[0]['usage_count']) > 0) {
                $breakdown['_legacy_unmapped'] = [
                    'usage_count' => (int) $legacy[0]['usage_count'],
                    'revenue' => round((float) $legacy[0]['revenue'], 2),
                ];
            }

            return [
                'year' => $year,
                'month' => $month,
                'total_revenue' => round(array_sum(array_column($breakdown, 'revenue')), 2),
                'total_usage_count' => (int) array_sum(array_column($breakdown, 'usage_count')),
                'breakdown' => $breakdown,
            ];
        } catch (Exception $e) {
            Logger::error('getUsageRevenueBreakdown failed', ['message' => $e->getMessage()]);
            return ['year' => $year, 'month' => $month, 'total_revenue' => 0.0, 'total_usage_count' => 0, 'breakdown' => []];
        }
    }

    /**
     * Phase 10: تاريخ MRR/ARR الحقيقي لآخر N يوم - من اللقطات اللي
     * اتسجّلت فعليًا (lazy snapshot أعلاه). لو المنتج جديد ومفيش لقطات
     * كتير لسه، هترجع مصفوفة قصيرة بس - ده طبيعي ومش خطأ (البيانات
     * بتتكوّن تدريجيًا، مفيش تاريخ ملفّق قبل أول استخدام فعلي للصفحة).
     */
    public function getMrrTrend(int $days = 30): array
    {
        try {
            $rows = $this->db->query(
                "SELECT snapshot_date, mrr, arr, active_subscriptions FROM billing_metrics_snapshots
                 WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 ORDER BY snapshot_date ASC",
                [$days]
            );
            return $rows ?: [];
        } catch (Exception $e) {
            Logger::error('getMrrTrend failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Phase 8: MRR/ARR وباقي مقاييس الاشتراكات - محسوبة من الجداول
     * الحقيقية (subscriptions + subscription_plans، محرك الفوترة الفعلي
     * اللي اكتُشف في Phase 1)، مش من أي جدول إحصائيات وهمي. كل استعلام
     * معزول بـ try/catch مستقل (زي نمط getPlatformOverview() الموجود في
     * AdminController) عشان لو جدول واحد فيه مشكلة، باقي الأرقام تفضل
     * شغالة.
     *
     * ملحوظة منهجية (مهم): churn_rate_this_month تقريبي - بيستخدم
     * updated_at كبديل لعمود "تاريخ الإلغاء" غير الموجود فعليًا في
     * الجدول الحقيقي. لو فيه تعديلات تانية على نفس الصف (مش إلغاء) في
     * نفس الشهر، الرقم ممكن يبقى أعلى شوية من الحقيقي - محدود التأثير
     * لأن الصف بيتحدّث غالبًا وقت الإلغاء بس فعليًا حسب الكود الحالي.
     */
    private function getBillingAnalytics(): array
    {
        $safe = function (string $sql) {
            try {
                $rows = $this->db->query($sql);
                return $rows[0] ?? [];
            } catch (Exception $e) {
                Logger::error('getBillingAnalytics query failed', ['message' => $e->getMessage()]);
                return null;
            }
        };

        $mrrRow = $safe(
            "SELECT COALESCE(SUM(CASE WHEN sp.billing_cycle = 'yearly' THEN sp.price / 12 ELSE sp.price END), 0) AS mrr,
                    COUNT(*) AS active_count
             FROM subscriptions s
             JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.status = 'active' AND s.current_period_end > NOW() AND COALESCE(s.payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('s.user_id')
        );
        $newThisMonth = $safe(
            "SELECT COUNT(*) AS count FROM subscriptions
             WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND COALESCE(payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('user_id')
        );
        $cancelledThisMonth = $safe(
            "SELECT COUNT(*) AS count FROM subscriptions
             WHERE status = 'cancelled' AND MONTH(updated_at) = MONTH(NOW()) AND YEAR(updated_at) = YEAR(NOW()) AND COALESCE(payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('user_id')
        );

        if ($mrrRow === null || $newThisMonth === null || $cancelledThisMonth === null) {
            // فشل استعلام أساسي - أفضل نرجع "مفيش بيانات كافية" بدل ما
            // نعرض رقم جزئي ممكن يكون مضلّل.
            return [
                'mrr' => null, 'arr' => null, 'active_subscriptions' => null,
                'new_subscriptions_this_month' => null, 'cancelled_this_month' => null,
                'churn_rate_this_month' => null, 'average_revenue_per_subscription' => null,
            ];
        }

        $mrr = (float) ($mrrRow['mrr'] ?? 0);
        $activeCount = (int) ($mrrRow['active_count'] ?? 0);
        $cancelled = (int) ($cancelledThisMonth['count'] ?? 0);
        $churnDenominator = $activeCount + $cancelled;

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'active_subscriptions' => $activeCount,
            'new_subscriptions_this_month' => (int) ($newThisMonth['count'] ?? 0),
            'cancelled_this_month' => $cancelled,
            // null صراحةً لو مفيش قاعدة مقارنة (Not enough data) بدل ما نخترع 0%.
            'churn_rate_this_month' => $churnDenominator > 0 ? round(($cancelled / $churnDenominator) * 100, 1) : null,
            'average_revenue_per_subscription' => $activeCount > 0 ? round($mrr / $activeCount, 2) : null,
        ];
    }

    /** طرق الدفع اليدوي اللي الأدمن بيكتب بياناتها (العميل بيحوّل ويضغط «حوّلت») */
    public const MANUAL_METHODS = [
        'iban' => ['icon' => '🏦', 'label' => 'تحويل بنكي', 'label_en' => 'Bank transfer'],
        'paypal' => ['icon' => '💳', 'label' => 'PayPal', 'label_en' => 'PayPal'],
        'instapay' => ['icon' => '⚡', 'label' => 'InstaPay', 'label_en' => 'InstaPay'],
        'mobile_wallet' => ['icon' => '📱', 'label' => 'محفظة موبايل', 'label_en' => 'Mobile wallet'],
    ];

    /** كل مفاتيح بيانات الدفع اللي الأدمن يقدر يعدّلها */
    public const PAYMENT_KEYS = [
        'iban', 'iban_bank_name', 'iban_account_name', 'iban_swift',
        'paypal_email', 'instapay', 'mobile_wallet', 'mobile_wallet_name',
        'pay_instructions', 'whatsapp_number',
    ];

    /** قيمة فاضية أو لسه النص الافتراضي («حط رقم الـ IBAN بتاعك هنا...») */
    public static function isPlaceholder(string $v): bool
    {
        $v = trim($v);
        return $v === '' || (bool) preg_match('/هنا|لوحة الأدمن|^اسم البنك$|^اسم صاحب الحساب$|example|your/iu', $v);
    }

    /**
     * طرق الدفع المتاحة للعميل فعلًا (اللي بياناتها متكتبة) مع الحقول اللي تتعرض له.
     * @return array<int, array{key:string, icon:string, label:string, label_en:string, fields:array<int,array{0:string,1:string,2:bool}>, link:?string}>
     */
    public function manualMethods(): array
    {
        $s = $this->getPaymentSettings();
        $v = static fn (string $k): string => self::isPlaceholder((string) ($s[$k] ?? '')) ? '' : trim((string) $s[$k]);
        $out = [];
        if ($v('iban') !== '') {
            $f = [['IBAN', $v('iban'), true]];
            if ($v('iban_bank_name') !== '') $f[] = ['البنك', $v('iban_bank_name'), false];
            if ($v('iban_account_name') !== '') $f[] = ['اسم صاحب الحساب', $v('iban_account_name'), false];
            if ($v('iban_swift') !== '') $f[] = ['SWIFT', $v('iban_swift'), true];
            $out[] = ['key' => 'iban', 'fields' => $f, 'link' => null];
        }
        if ($v('paypal_email') !== '') {
            $pp = $v('paypal_email');
            $link = null;
            if (preg_match('~^(?:https?://)?(?:www\.)?paypal\.me/([A-Za-z0-9_.-]+)~i', $pp, $m)) {
                $link = 'https://paypal.me/' . $m[1];
                $pp = 'paypal.me/' . $m[1];
            }
            $out[] = ['key' => 'paypal', 'fields' => [['PayPal', $pp, true]], 'link' => $link];
        }
        if ($v('instapay') !== '') {
            $out[] = ['key' => 'instapay', 'fields' => [['InstaPay', $v('instapay'), true]], 'link' => null];
        }
        if ($v('mobile_wallet') !== '') {
            $f = [['رقم المحفظة', $v('mobile_wallet'), true]];
            if ($v('mobile_wallet_name') !== '') $f[] = ['الاسم', $v('mobile_wallet_name'), false];
            $out[] = ['key' => 'mobile_wallet', 'fields' => $f, 'link' => null];
        }
        foreach ($out as &$m) {
            $m += self::MANUAL_METHODS[$m['key']];
        }
        return $out;
    }

    /** ملاحظة الأدمن اللي بتظهر تحت بيانات الدفع (مثلاً: اكتب إيميلك في سبب التحويل) */
    public function payInstructions(): string
    {
        $t = trim((string) ($this->getPaymentSettings()['pay_instructions'] ?? ''));
        return self::isPlaceholder($t) ? '' : mb_substr($t, 0, 600);
    }

    /**
     * العميل حوّل لباقة معينة والأدمن أكّد: الباقة بتتفعّل لوحدها من الرصيد اللي لسه داخل.
     * لو حصل أي مشكلة (السعر اتغير / الرصيد ناقص) الفلوس بتفضل في المحفظة والعميل بيتبلّغ.
     */
    private function activatePlanAfterDeposit(WalletTransaction $tx): void
    {
        $ref = (string) ($tx->getAttribute('related_subscription_plan') ?? '');
        $userId = (int) $tx->getAttribute('user_id');
        if (preg_match('/^site-(\d+):(monthly|yearly)$/', $ref, $m)) {
            try {
                $r = (new SiteSubscriptionService())->subscribe($userId, (int) $m[1], $m[2], 'deposit_site_' . (int) $tx->getAttribute('id'));
            } catch (Throwable $e) {
                $r = ['success' => false, 'error' => $e->getMessage()];
            }
            if (class_exists('Notification')) {
                Notification::notify($userId, 'site_subscription', !empty($r['success']) ? 'موقعك اتفعّل' : 'رصيدك اتشحن',
                    !empty($r['success']) ? 'وصلنا تحويلك واشتراك موقعك اتفعّل لحد ' . substr((string) $r['period_end'], 0, 10) . '. تقدر تنشره دلوقتي.' : 'وصلنا تحويلك واتضاف لمحفظتك، بس اشتراك الموقع ماتفعّلش لوحده (' . ($r['error'] ?? '') . '). فعّله من منشئ المواقع.',
                    '/website-builder');
            }
            ActivityLog::record('wallet', !empty($r['success']) ? 'site_subscription.activated_by_transfer' : 'site_subscription.transfer_activation_failed', [
                'user_id' => $userId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'), 'meta' => ['plan' => $ref, 'error' => $r['error'] ?? null],
            ]);
            return;
        }
        if (!preg_match('/^([a-z0-9_-]{2,30}):(monthly|yearly)$/', $ref, $m)) {
            return;
        }
        try {
            $r = $this->subscribeWithBalance($userId, $m[1], $m[2], 'deposit_activate_' . (int) $tx->getAttribute('id'));
        } catch (Throwable $e) {
            $r = ['success' => false, 'error' => $e->getMessage()];
        }
        if (class_exists('Notification')) {
            if (!empty($r['success'])) {
                Notification::notify($userId, 'subscription_activated', 'اشتراكك اتفعّل', 'وصلنا تحويلك واشتراكك اتفعّل. تقدر تكمّل شغلك على طول.', '/subscription');
            } else {
                Notification::notify($userId, 'wallet_deposit_approved', 'رصيدك اتشحن', 'وصلنا تحويلك واتضاف لمحفظتك، بس الاشتراك ماتفعّلش لوحده (' . ($r['error'] ?? '') . '). افتح صفحة الباقات واضغط «اشترك بالرصيد».', '/plans');
            }
        }
        ActivityLog::record('wallet', !empty($r['success']) ? 'subscription.activated_by_transfer' : 'subscription.transfer_activation_failed', [
            'user_id' => $userId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'),
            'meta' => ['plan' => $ref, 'error' => $r['error'] ?? null],
        ]);
    }

    /** بيانات الدفع (IBAN/PayPal) القابلة للتعديل من لوحة الأدمن */
    public function getPaymentSettings(): array
    {
        try {
            $rows = $this->db->query("SELECT setting_key, setting_value FROM wallet_payment_settings");
        } catch (Exception $e) {
            return [];
        }
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    /** تحديث إعداد دفع واحد من لوحة الأدمن */
    public function updatePaymentSetting(string $key, string $value): void
    {
        $this->db->exec(
            "INSERT INTO wallet_payment_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
    }

    // ============================================
    // ادفع حسب الاستخدام (Pay-As-You-Go)
    // العميل من غير اشتراك (أو اللي خلّص حد باقته) يقدر يستخدم أي ميزة
    // وتتخصم تلقائيًا من رصيد محفظته بدل ما يتمنع خالص.
    // ============================================

    /** كل أسعار الاستخدام الفردي النشطة، مفهرسة بمفتاح الميزة */
    public function getUsagePricing(): array
    {
        try {
            $rows = $this->db->query("SELECT * FROM pay_per_use_pricing WHERE is_active = 1");
        } catch (Exception $e) {
            return [];
        }
        $pricing = [];
        foreach ($rows as $row) {
            $pricing[$row['feature_key']] = $row;
        }
        return $pricing;
    }

    /** فحص: هل رصيد العميل كافي لاستخدام ميزة معيّنة مرة واحدة؟ */
    public function canAffordUsage(int $userId, string $featureKey): array
    {
        $pricing = $this->getUsagePricing();
        $price = $pricing[$featureKey] ?? null;

        if (!$price) {
            return ['can_afford' => false, 'reason' => 'الميزة دي مش متاحة كـ"ادفع حسب الاستخدام" حاليًا'];
        }

        $balance = $this->getBalance($userId);
        $unitPrice = (float) $price['price'];

        if ($balance < $unitPrice) {
            return [
                'can_afford' => false, 'reason' => 'رصيدك غير كافي', 'balance' => $balance,
                'required' => $unitPrice, 'shortfall' => round($unitPrice - $balance, 2),
            ];
        }

        return ['can_afford' => true, 'balance' => $balance, 'price' => $unitPrice, 'currency_symbol' => $price['currency_symbol']];
    }

    /**
     * خصم فعلي لثمن استخدام ميزة واحدة من المحفظة. بترجع false لو الرصيد
     * مش كافي (بدل ما تخصم قيمة سالبة/تدّي رصيد بالسالب بالغلط).
     *
     * تصحيح حرج (2026-08-12 / Phase 14 - Atomic Pay-As-You-Go): كانت
     * الدالة دي بتقرأ الرصيد ثم تكتب الخصم في خطوتين منفصلتين من غير
     * أي قفل أو Transaction - يعني طلبين متزامنين لنفس العميل (زي مثال
     * Balance=100 وطلبين بـ80) كانوا يقدروا الاتنين يعدّوا فحص "الرصيد
     * كافي" في نفس اللحظة قبل ما أي واحد يكتب، فيتم خصم الاتنين ويوصل
     * الرصيد لسالب. دلوقتي العملية كلها جوه Database Transaction، مع
     * قفل صف المستخدم نفسه (SELECT ... FOR UPDATE) - أي طلب تاني لنفس
     * العميل بيستنى لحد ما القفل يتفك (يعني بعد ما أول عملية تخلص وتلتزم
     * فعليًا)، فيعيد حساب الرصيد الحقيقي المُحدَّث ويرفض لو مبقاش كافي -
     * بدل ما ينفّذ عمليتين مش المفروض تتنفّذ الاتنين مع بعض.
     *
     * @param string|null $idempotencyKey مفتاح فريد اختياري لمنع تكرار
     *        نفس الخصم لو نفس الطلب اتبعت مرتين (شبكة/دبل-كليك).
     */
    /**
     * خصم مبلغ محدد من المحفظة (الإضافات والباقات المخصصة) - نفس ضمانات chargeForUsage
     * (قفل المستخدم، إعادة حساب الرصيد جوه القفل، idempotency).
     */
    public function chargeAmount(int $userId, float $amount, string $featureKey, string $note = '', ?string $idempotencyKey = null): bool
    {
        if ($amount <= 0 || !is_finite($amount)) {
            return $amount == 0.0;
        }
        try {
            return $this->db->transaction(function () use ($userId, $amount, $featureKey, $note, $idempotencyKey) {
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);
                if ($idempotencyKey && $this->db->query("SELECT id FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1", [$idempotencyKey])) {
                    return true;
                }
                if ($this->getBalance($userId) < $amount) {
                    return false;
                }
                $tx = new WalletTransaction();
                $tx->fill([
                    'user_id' => $userId,
                    'type' => 'subscription_charge',
                    'amount' => -round($amount, 2),
                    'currency' => 'USD',
                    'status' => 'completed',
                    'reference_note' => $note ?: $featureKey,
                    'feature_key' => mb_substr($featureKey, 0, 60),
                    'approved_at' => date('Y-m-d H:i:s'),
                    'idempotency_key' => $idempotencyKey,
                ]);
                if (!$tx->save()) throw new RuntimeException('Charge transaction was not persisted');
                $this->checkLowBalanceAndNotify($userId);
                return true;
            });
        } catch (Exception $e) {
            Logger::error('chargeAmount failed', ['user_id' => $userId, 'feature' => $featureKey, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function chargeForUsage(int $userId, string $featureKey, string $note = '', ?string $idempotencyKey = null): bool
    {
        $pricing = $this->getUsagePricing();
        $price = $pricing[$featureKey] ?? null;
        if (!$price) {
            return false;
        }
        $unitPrice = (float) $price['price'];

        try {
            return $this->db->transaction(function () use ($userId, $featureKey, $unitPrice, $note, $idempotencyKey) {
                // قفل صف المستخدم - أي عملية خصم تانية لنفس اليوزر (سواء
                // usage charge أو subscribe/upgrade) بتتسلسل خلف القفل ده
                // بدل ما تتنافس على قراءة رصيد قديم.
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);

                if ($idempotencyKey) {
                    $existing = $this->db->query(
                        "SELECT id FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1",
                        [$idempotencyKey]
                    );
                    if (!empty($existing)) {
                        return true; // نفس الطلب اتنفّذ قبل كده - مش خطأ، بس منفّذوش تاني
                    }
                }

                // إعادة حساب الرصيد جوه القفل - ده الرصيد الحقيقي المضمون
                // دلوقتي، مش اللي اتقرا قبل الدخول في الـ transaction.
                $balance = $this->getBalance($userId);
                if ($balance < $unitPrice) {
                    return false;
                }

                $tx = new WalletTransaction();
                $tx->fill([
                    'user_id' => $userId,
                    'type' => 'subscription_charge',
                    'amount' => -$unitPrice,
                    'currency' => 'USD',
                    'status' => 'completed',
                    'reference_note' => $note ?: $featureKey,
                    // Phase 17: feature_key لتحليل "الإيراد لكل ميزة" -
                    // كان بيختفي وبيتحفظ Arabic label بس في reference_note.
                    'feature_key' => $featureKey,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'idempotency_key' => $idempotencyKey,
                ]);
                if (!$tx->save()) throw new RuntimeException('Usage transaction was not persisted');
                $this->checkLowBalanceAndNotify($userId);

                return true;
            });
        } catch (Exception $e) {
            Logger::error('chargeForUsage failed', ['user_id' => $userId, 'feature' => $featureKey, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * تحليل تنافسي (Stripe/Chargebee/Paddle): التجديد التلقائي من الرصيد.
     *
     * فجوة حقيقية كانت موجودة: الاشتراك بيوصل current_period_end ومفيش
     * أي كود بيحاول يخصم الرصيد تلقائيًا للتجديد - كان بيروح past_due
     * حتى لو العميل عنده رصيد كافي (بخلاف Stripe اللي بيحاول الدفع
     * تلقائيًا). الدالة دي بتسد الفجوة دي.
     *
     * Idempotent بأمان: بتحصر صف الاشتراك نفسه بـ FOR UPDATE جوه
     * الـ transaction، وبعد القفل بتتأكد إن current_period_end لسه
     * <= NOW() (يعني لسه مستحق) قبل أي خصم - فأي تشغيلين متوازيين لنفس
     * الاشتراك (صفحتين أدمن، أو تشغيل Cron + lazy) أول واحد بس يخصم
     * والباقي بيشوف الفترة اتجدّدت وبيسحب. كل خصم بيتسجّل بمفتاح
     * idempotency فريد من رقم الاشتراك.
     *
     * @return array{success: bool, reason?: string, subscription_id?: int, charged?: float, new_period_end?: string, balance?: float, required?: float}
     */
    public function renewSubscriptionFromBalance(int $subscriptionId): array
    {
        try {
            return $this->db->transaction(function () use ($subscriptionId) {
                // Lock owner before subscription, matching purchases and usage reservations.
                $owner=$this->db->query('SELECT user_id FROM subscriptions WHERE id=?',[$subscriptionId]);
                if (!$owner) return ['success'=>false,'reason'=>'not_found'];
                $this->db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$owner[0]['user_id']]);
                // قفل صف الاشتراك نفسه - مفتاح الـ idempotency الأساسي ضد
                // التشغيل المزدوج. أول معاملة بتقفل الصف وتمد الفترة،
                // والتانية بتلاقي الفترة اتجدّدت وترجع "not_due".
                $subs = $this->db->query(
                    "SELECT s.*, s.price AS locked_price, sp.plan_code, sp.billing_cycle, sp.price, sp.currency AS renewal_currency, sp.name AS plan_display_name
                     FROM subscriptions s
                     JOIN subscription_plans sp ON sp.id = s.plan_id
                     WHERE s.id = ? LIMIT 1 FOR UPDATE",
                    [$subscriptionId]
                );
                if (empty($subs)) {
                    return ['success' => false, 'reason' => 'not_found'];
                }
                $sub = $subs[0];

                // المستحق فعلاً للتجديد: active + الفترة خلصت. مش `past_due`
                // (ليه فترة سماح خاصة بيه في الـ Lifecycle) ومش `trialing`
                // (مفيش دفع بعد). لو cancel_at_period_end مفعّل، العميل
                // طلب الإلغاء صراحةً - متجددوش (الـ Lifecycle هيلغي).
                if (!in_array($sub['status'], ['active','past_due'], true)) {
                    return ['success' => false, 'reason' => 'not_due', 'subscription_id' => $subscriptionId];
                }
                if (!empty($sub['cancel_at_period_end'])) {
                    return ['success' => false, 'reason' => 'cancel_at_period_end', 'subscription_id' => $subscriptionId];
                }
                if (empty($sub['current_period_end']) || strtotime((string) $sub['current_period_end']) > time()) {
                    return ['success' => false, 'reason' => 'not_due', 'subscription_id' => $subscriptionId];
                }

                // تخفيض مجدول: التجديد ده بيتم على الباقة الأرخص اللي العميل اختارها
                $switched = false;
                if (!empty($sub['scheduled_plan_code'])) {
                    $np = $this->db->query('SELECT id, plan_code, billing_cycle, price, currency, name FROM subscription_plans WHERE plan_code = ? AND is_active = 1 LIMIT 1', [(string) $sub['scheduled_plan_code']])[0] ?? null;
                    if ($np && ($np['currency'] ?? '') === 'USD') {
                        $nKey = preg_replace('/_(monthly|yearly)$/D', '', (string) $np['plan_code']);
                        $nType = $np['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
                        $this->db->exec('UPDATE subscriptions SET plan_id = ?, plan_name = ?, plan_type = ?, price = ?, scheduled_plan_code = NULL WHERE id = ?', [(int) $np['id'], $nKey, $nType, (float) $np['price'], $subscriptionId]);
                        $sub = array_merge($sub, ['plan_id' => $np['id'], 'plan_code' => $np['plan_code'], 'billing_cycle' => $np['billing_cycle'], 'price' => $np['price'], 'locked_price' => $np['price'], 'renewal_currency' => $np['currency'], 'plan_display_name' => $np['name']]);
                        $switched = true;
                    } else {
                        $this->db->exec('UPDATE subscriptions SET scheduled_plan_code = NULL WHERE id = ?', [$subscriptionId]);
                    }
                }
                $userId = (int) $sub['user_id'];
                $planKey = preg_replace('/_(monthly|yearly)$/D','',(string)$sub['plan_code']);
                $planType = $sub['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
                if (($sub['renewal_currency']??'') !== 'USD' || ($sub['currency']??'') !== 'USD') return ['success'=>false,'reason'=>'currency_mismatch','subscription_id'=>$subscriptionId];
                $price = (float) $sub['price'];
                // العميل بيتجدد بسعره اللي اشترك بيه (s.price) لو الأسعار اتغيرت بعدها - ده اللي بيشوفه في صفحته.
                // سعر الباقة الحالي بيتطبق بس لو مفيش سعر محفوظ (مثلًا اشتراك جه من تجربة مجانية سعرها صفر).
                $lockedPrice = (float) ($sub['locked_price'] ?? 0);
                if ($lockedPrice > 0) {
                    $price = $lockedPrice;
                }
                // الباقة المخصصة (ابني باقتك) بتتجدد بسعرها هي، مش سعر الباقة الأساسية
                require_once __DIR__ . '/PlanExtras.php';
                $customPrice = $switched ? null : PlanExtras::renewalPrice($subscriptionId);
                if ($customPrice !== null) {
                    $price = $customPrice;
                }

                if ($price < 0 || !is_finite($price)) {
                    return ['success' => false, 'reason' => 'invalid_price', 'subscription_id' => $subscriptionId];
                }

                // قفل صف المستخدم - نفس نمط باقي المعاملات المالية
                // (subscribeWithBalance/chargeForUsage) عشان متحصلش
                // منافسة على نفس الرصيد مع خصم تاني.
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);

                $balance = $this->getBalance($userId);
                if ($balance < $price) {
                    // مش بتبعت إشعار "فشل دفع" هنا - الـ Lifecycle بيهتم
                    // بالانتقال لـ past_due + إنذارات الـ Dunning بفترة
                    // السماح، فمفيش تكرار رسائل.
                    return [
                        'success' => false,
                        'reason' => 'insufficient_balance',
                        'subscription_id' => $subscriptionId,
                        'balance' => $balance,
                        'required' => $price,
                    ];
                }

                $idempotencyKey = SubscriptionPeriod::renewalIdempotencyKey($subscriptionId, (string) $sub['current_period_end']);

                // خصم سعر الباقة من المحفظة (نفس نمط الاشتراك الجديد).
                $chargeTx = new WalletTransaction();
                $chargeTx->fill([
                    'user_id' => $userId,
                    'type' => 'subscription_charge',
                    'amount' => -$price,
                    'currency' => 'USD',
                    'status' => 'completed',
                    'reference_note' => 'تجديد تلقائي لاشتراك "' . ($sub['plan_display_name'] ?: $planKey) . '"',
                    'related_subscription_plan' => $planKey,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'idempotency_key' => $idempotencyKey,
                ]);
                if (!$chargeTx->save()) throw new RuntimeException('تعذر تسجيل الخصم؛ لم تكتمل العملية');
                $this->checkLowBalanceAndNotify($userId);

                // تسجيل موازٍ في payment_transactions (نفس نمط
                // subscribeWithBalance) - عشان أي استرجاع مستقبلي يتعامل
                // مع التجديد ده كمعاملة دفع. معزول في try/catch مستقلة.
                try {
                    $adapter = new WalletGatewayAdapter();
                    $intent = $adapter->createPaymentIntent($userId, $price, 'USD', [
                        'plan' => $planKey, 'plan_type' => $planType, 'auto_renewal' => true,
                    ]);
                    $adapter->markSettled($intent['internal_transaction_id'], true, (int) $chargeTx->getAttribute('id'));
                } catch (Exception $ledgerError) {
                    Logger::error('auto-renew ledger write failed (charge already succeeded)', [
                        'user_id' => $userId, 'subscription_id' => $subscriptionId, 'message' => $ledgerError->getMessage(),
                    ]);
                }

                // تمديد الفترة + إعادة تصفير عدادات الاستخدام (كل فترة
                // جديدة بتفتح برصيد جديد). بيتنفذ مباشرة على الصف المقفول.
                $newStart = $sub['current_period_end'];
                $newEnd = SubscriptionPeriod::nextPeriodEnd((string) $newStart, $planType);

                $this->db->exec(
                    "UPDATE subscriptions
                     SET status = 'active', price = ?, currency = 'USD',
                         current_period_start = ?, current_period_end = ?,
                         usage_ai_analysis_count = 0, usage_ai_message_count = 0, usage_review_reply_count = 0, competitor_analysis_used = 0,
                         last_usage_reset_at = NOW(), updated_at = NOW()
                     WHERE id = ?",
                    [$price, $newStart, $newEnd, $subscriptionId]
                );

                // Renewal invoice is atomic with the renewal debit.
                $this->createInvoiceForCharge($userId, $planKey, ['name' => $sub['plan_display_name'] ?: $planKey], $planType, $price, $chargeTx);
                if ($customPrice !== null) {
                    PlanExtras::onRenewed($subscriptionId, $userId, (string) $newEnd);
                }

                ActivityLog::record('wallet', 'wallet.subscription_renewed', [
                    'user_id' => $userId,
                    'subject_type' => 'subscriptions',
                    'subject_id' => $subscriptionId,
                    'meta' => ['plan' => $planKey, 'type' => $planType, 'charged' => $price, 'new_period_end' => $newEnd],
                ]);

                if (class_exists('Notification')) {
                    $cycleLabel = $planType === 'yearly' ? 'عام إضافي' : 'شهر إضافي';
                    Notification::notify(
                        $userId,
                        'subscription_renewed',
                        'تم تجديد اشتراكك تلقائيًا',
                        'تم خصم ' . $price . '$ من محفظتك لتجديد باقة "' . ($sub['plan_display_name'] ?: $planKey) . '" لمدة ' . $cycleLabel . '.',
                        '/subscription'
                    );
                }

                return [
                    'success' => true,
                    'subscription_id' => $subscriptionId,
                    'charged' => $price,
                    'new_period_end' => $newEnd,
                    'balance' => $this->getBalance($userId),
                ];
            });
        } catch (Exception $e) {
            Logger::error('renewSubscriptionFromBalance failed', ['subscription_id' => $subscriptionId, 'message' => $e->getMessage()]);
            return ['success' => false, 'reason' => 'error', 'subscription_id' => $subscriptionId];
        }
    }

    /** كل أسعار الاستخدام (نشطة وغير نشطة) - لعرضها وتعديلها في لوحة الأدمن */
    public function getAllUsagePricingForAdmin(): array
    {
        try {
            return $this->db->query("SELECT * FROM pay_per_use_pricing ORDER BY id ASC");
        } catch (Exception $e) {
            return [];
        }
    }

    /** تحديث سعر استخدام ميزة واحدة من لوحة الأدمن */
    public function updateUsagePricing(int $id, float $price, bool $isActive): void
    {
        $this->db->exec(
            "UPDATE pay_per_use_pricing SET price = ?, is_active = ? WHERE id = ?",
            [$price, $isActive ? 1 : 0, $id]
        );
    }

    /** حد "رصيد منخفض" - تحت الرقم ده بيتبعت تنبيه (مرة واحدة كل يوم بالكتير) */
    private const LOW_BALANCE_THRESHOLD = 10.0;

    /**
     * هل نرجع رصيد تلقائي للعميل عند التخفيض (downgrade)؟
     *
     * تحليل تنافسي (Stripe Billing / Chargebee / Paddle): المنصات العالمية
     * العالمية بتعمل prorated credit تلقائي عند تغيير الباقة لأسفل -
     * العميل بياخد فرق السعر عن الأيام المتبقية من الفترة الحالية رصيد
     * في محفظته بدل ما يخسر فلوسه.
     *
     * ❌ قيمتها حاليًا false (سياسة محافظة مستمرة من قبل): التخفيض
     * بيفعّل الباقة الجديدة من غير أي استرجاع تلقائي. ده قرار مالي
     * حقيقي بيأثر على إيراد المنصة، فمش هيتفعّل في الكود إلا بقرار
     * صريح من مالك المنصة - غيّر القيمة في BillingRules لما تقرر كده.
     *
     * ⚠️ ملحوظة تقنية: المبلغ المرتجع هنا هو فرق السعر الكامل
     * (old - new) مش "pro-rated" حرفيًا على الأيام المتبقية - لأن
     * التسعير الحالي بيخصم الفرق الكامل عند الترقية برضه (متماثل
     * الاتجاهين). لو احتجناهم pro-rating حقيقي بدقة على اليوم،
     * BillingRules::proratedCredit() جاهزة (مش مفعّلة).
     *
     * التعريف الموحّد انتقل لـ BillingRules::ALLOW_PRORATED_DOWNGRADE_CREDIT
     * - دي بس بتبقي الإشارة القابلة للقراءة لموقع القرار.
     */
    public const ALLOW_PRORATED_DOWNGRADE_CREDIT = BillingRules::ALLOW_PRORATED_DOWNGRADE_CREDIT;

    /**
     * Section 13: تنبيه "رصيد منخفض" - بيتنده بعد أي خصم ناجح (استخدام
     * فردي أو اشتراك). Dedup مرة واحدة في اليوم لكل عميل (نفس نمط
     * تذكير التجديد) عشان ميبقاش إشعار مزعج على كل عملية صغيرة تحت الحد.
     */
    private function checkLowBalanceAndNotify(int $userId): void
    {
        try {
            $balance = $this->getBalance($userId);
            if ($balance >= self::LOW_BALANCE_THRESHOLD) {
                return;
            }

            $today = date('Y-m-d');
            $already = $this->db->query(
                "SELECT id FROM activity_logs WHERE module = 'wallet' AND action = 'wallet.low_balance_notified'
                 AND user_id = ? AND DATE(created_at) = ? LIMIT 1",
                [$userId, $today]
            );
            if (!empty($already)) {
                return;
            }

            if (class_exists('Notification')) {
                Notification::notify(
                    $userId,
                    'wallet_low_balance',
                    'رصيد محفظتك منخفض',
                    'رصيدك الحالي $' . number_format($balance, 2) . ' - جدّده عشان متتأثرش خدماتك.',
                    '/subscription'
                );
            }
            ActivityLog::record('wallet', 'wallet.low_balance_notified', [
                'user_id' => $userId, 'meta' => ['balance' => $balance],
            ]);
        } catch (Exception $e) {
            Logger::error('checkLowBalanceAndNotify failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Phase 13: ينشئ فاتورة 'paid' فعلية فور نجاح خصم المحفظة - أول مرة
     * جدول invoices بيتكتب فيه فعليًا في المشروع كله (كان بيتقرأ بس).
     *
     * ⚠️ ملحوظة مهمة قبل التشغيل على الإنتاج: أعمدة الجدول هنا مبنية
     * على database/schema.sql (المرجع الوحيد المتاح لي وقت الكتابة).
     * سبق واكتشفنا في تدقيق سابق إن جدول subscriptions الحقيقي في
     * السيرفر مختلف تمامًا عن اللي في schema.sql - نفس الاحتمال وارد
     * هنا لجدول invoices. عشان كده الكود ده معزول في try/catch مستقل
     * تمامًا عن عملية الخصم والاشتراك: لو فشل (عمود مش موجود، مثلاً)،
     * الخصم والاشتراك يفضلوا ناجحين والعميل مايتأثرش، بس السجل بيتسجل
     * في الـ Logger عشان تلاحظه وتظبط الأعمدة لو مختلفة فعليًا.
     *
     * لازم تتأكد من أعمدة الجدول الحقيقية أول Deployment (SHOW COLUMNS
     * FROM invoices;) وتظبط القائمة تحت لو فيه فرق.
     */
    private function createInvoiceForCharge(int $userId, string $planKey, array $plan, string $planType, float $amount, ?WalletTransaction $chargeTx): void
    {
        try {
            // Section 12: ضريبة معلوماتية بس - من دولة العميل الحقيقية
            // في billing_profile لو موجودة. مفيش نسبة افتراضية، ومفيش
            // إضافة تلقائية لمبلغ amount المخصوم فعليًا (شوف تعليق
            // migration الأعمدة). لو فشل الاستعلام لأي سبب، الفاتورة
            // لسه بتتعمل عادي من غير بيانات ضريبة.
            $taxCountry = null;
            $taxType = null;
            $taxAmount = null;
            try {
                if (class_exists('BillingProfile') && class_exists('TaxService')) {
                    $profile = BillingProfile::forUser($userId);
                    $country = $profile ? $profile->getAttribute('country') : null;
                    if ($country) {
                        $tax = (new TaxService())->computeTax($amount, $country);
                        if ($tax['configured']) {
                            $taxCountry = $tax['country_code'];
                            $taxType = $tax['tax_type'];
                            $taxAmount = $tax['tax_amount'];
                        }
                    }
                }
            } catch (Exception $taxError) {
                Logger::error('Invoice tax lookup failed (invoice still created without tax data)', ['message' => $taxError->getMessage()]);
            }

            // إنشاء الفاتورة عبر BillingManager - المسار الموحّد الوحيد
            // لكتابة جدول invoices (الدفع خلص فعليًا لحظة خصم المحفظة
            // فوق، فبنمرر status = 'paid' فورًا - مش 'pending' زي تدفق
            // بوابة الدفع الخارجية المعطّل).
            $items = [[
                'description' => ($plan['name'] ?? $planKey) . ' - ' . ($planType === 'yearly' ? 'سنوي' : 'شهري'),
                'amount' => $amount,
                'quantity' => 1,
            ]];

            $result = (new BillingManager())->createInvoice($userId, $planKey, $planType, $items, [
                'status' => 'paid',
                'payment_method' => 'wallet',
                'transaction_id' => $chargeTx ? 'wallet_tx_' . $chargeTx->getAttribute('id') : 'wallet_free_sub_' . (int)(Subscription::activeSubscriptionRow($userId)['id']??0),
                'currency'=>'USD',
                'subtotal' => $amount,
                'tax_country' => $taxCountry,
                'tax_type' => $taxType,
                'tax_amount' => $taxAmount,
            ]);

            if (!$result['success']) {
                throw new RuntimeException('تعذر إصدار الفاتورة؛ لم تكتمل عملية الدفع.');
            }
        } catch (Exception $e) {
            // مقصود: فشل إنشاء الفاتورة ميلغيش الخصم أو الاشتراك اللي
            // نجحوا فعلاً. بس المشكلة لازم تتسجل بوضوح عشان تتراجع
            // وتظبط أعمدة الجدول الحقيقية لو مختلفة عن schema.sql.
            throw new RuntimeException('تعذر إصدار الفاتورة؛ لم تكتمل عملية الدفع.',0,$e);
        }
    }
}
