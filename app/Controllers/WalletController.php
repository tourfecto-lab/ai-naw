<?php

/**
 * Tourfecto - Wallet Controller
 * إيداع رصيد (تحويل بنكي/PayPal يدوي، تأكيد عن طريق واتساب)، وموافقة
 * الأدمن، والاشتراك التلقائي من الرصيد.
 * @version 1.0.0
 */
class WalletController extends Controller
{
    /** @var WalletService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new WalletService();
    }

    /** GET /api/wallet/balance */
    public function getBalance(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $snapshot=CreativeReservations::walletSnapshot((int)$this->user['id']);$balance=$snapshot['balance'];
            $settings = $this->service->getPaymentSettings();
            foreach (['iban', 'iban_bank_name', 'iban_account_name', 'paypal_email'] as $k) {
                if (WalletService::isPlaceholder((string) ($settings[$k] ?? ''))) {
                    $settings[$k] = '';
                }
            }

            return $this->success([
                'balance' => $balance,
                'reserved' => $snapshot['reserved'],
                'ledger_balance' => $snapshot['ledger_balance'],
                'payment_info' => [
                    'iban' => $settings['iban'] ?? '',
                    'iban_bank_name' => $settings['iban_bank_name'] ?? '',
                    'iban_account_name' => $settings['iban_account_name'] ?? '',
                    'paypal_email' => $settings['paypal_email'] ?? '',
                    'whatsapp_number' => $settings['whatsapp_number'] ?? '',
                    'methods' => $this->service->manualMethods(),
                    'instructions' => $this->service->payInstructions(),
                ],
            ]);
        } catch (Exception $e) {
            Logger::error('Wallet getBalance Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الرصيد', 500);
        }
    }

    /** GET /api/wallet/history */
    public function getHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $history = $this->service->getHistory((int) $this->user['id']);
            return $this->success(['transactions' => array_map(fn ($t) => $t->toArray(), $history)]);
        } catch (Exception $e) {
            Logger::error('Wallet getHistory Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب السجل', 500);
        }
    }

    /** POST /api/wallet/deposit */
    public function requestDeposit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        // تصحيح: عمود amount في قاعدة البيانات DECIMAL(10,2) (أقصى قيمة
        // 99999999.99). من غير هذا التحقق، مبلغ كبير زي 10000000000 كان
        // بيعدي التحقق الأساسي (numeric) ويوصل للـ DB فيرجع خطأ SQL خام
        // للمستخدم (SQLSTATE[22003] Numeric value out of range).
        if (!$this->validate(['amount' => 'required|numeric|min:1|max:99999999.99', 'payment_method' => 'required'])) {
            return $this->error($this->getErrors()['amount'][0] ?? 'بيانات ناقصة', 422);
        }

        try {
            $tx = $this->service->requestDeposit(
                (int) $this->user['id'],
                (float) $this->get('amount'),
                (string) $this->get('payment_method'),
                (string) $this->get('note', ''),
                $this->get('plan') !== null ? (string) $this->get('plan') : null
            );
            $msg = $tx->getAttribute('related_subscription_plan')
                ? 'تمام، وصلنا إشعار التحويل. أول ما نأكد استلامه اشتراكك هيتفعّل لوحده وهتوصلك رسالة.'
                : 'تم تسجيل طلب الإيداع - هيتراجع بعد تأكيد استلام التحويل';
            return $this->success(['transaction' => $tx->toArray()], $msg, 201);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /**
     * POST /api/wallet/subscribe - الاشتراك التلقائي الفوري من الرصيد
     * (بدون أي تدخل بشري لو الرصيد كافي).
     */
    /**
     * POST /api/wallet/coupon/validate {code, plan_key, plan_type}
     * بيحسب السعر بعد الخصم قبل ما العميل يأكد (من غير ما يستخدم الكود).
     */
    public function validateCoupon(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'coupon', 20, 60)) {
            return $rateError;
        }
        if (!class_exists('CouponService') || !class_exists('SubscriptionPlan')) {
            return $this->error('أكواد الخصم مش متاحة', 503);
        }
        $planKey = (string) $this->get('plan_key', '');
        $planType = (string) $this->get('plan_type', 'monthly');
        $plans = SubscriptionPlan::allAsLegacyArray();
        $plan = $plans[$planKey] ?? null;
        if (!$plan) {
            return $this->error('الباقة غير موجودة', 404);
        }
        if (!in_array($planType,['monthly','yearly'],true)) return $this->error('دورة دفع غير صحيحة',422);
        if (($plan['currency']??'')!=='USD') return $this->error('المحفظة تدعم الدولار فقط',422);
        SubscriptionPeriod::refreshQuota((int)$this->user['id']);
        $quote=WalletService::subscriptionQuote(Subscription::activeSubscriptionRow((int)$this->user['id']),$plan,$planType);
        if (!empty($quote['same'])) {
            $cur0 = Subscription::activeSubscriptionRow((int) $this->user['id']);
            if (!empty($cur0['scheduled_plan_code']) && $this->get('quote_only', false)) return $this->success(['price' => 0, 'ends_at' => $quote['end'], 'is_plan_change' => true, 'preserve_usage' => true, 'renewal_price' => (float) $plan['price_' . $planType], 'unschedule' => true]);
            return $this->error('أنت مشترك بالفعل في نفس الباقة ودورة الدفع',409);
        }
        $price=$quote['charge'];$isChange=$quote['change'];
        $cur = Subscription::activeSubscriptionRow((int) $this->user['id']);
        $scheduled = WalletService::isScheduledDowngrade($cur, $quote, (float) $plan['price_' . $planType]);
        if ($this->get('quote_only',false)) return $this->success(['price'=>$price,'ends_at'=>$quote['end'],'is_plan_change'=>$isChange,'preserve_usage'=>$quote['preserve'],'renewal_price'=>(float)$plan['price_'.$planType],'downgrade_refund'=>false,'scheduled'=>$scheduled,'effective_at'=>$scheduled ? substr((string) $cur['current_period_end'], 0, 10) : null]);
        $r = (new CouponService())->evaluate((string) $this->get('code', ''), (int) $this->user['id'], $planKey, $price, $isChange);
        if (empty($r['ok'])) {
            return $this->error($r['error'] ?? 'كود غير صالح', 422);
        }
        return $this->success(['code' => $r['code'], 'label' => $r['label'], 'price' => $price, 'discount' => $r['discount'], 'final' => $r['final']], 'تم تطبيق الخصم');
    }

    public function subscribeWithBalance(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!empty($this->user['owner_user_id'])) return $this->error('تغيير الاشتراك والدفع لصاحب الحساب بس - كلّمه يعمله من حسابه', 403);
        if (!$this->validate(['plan_key' => 'required', 'plan_type' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        // مفتاح idempotency اختياري من الفرونت (UUID مولّد وقت الضغطة) -
        // بيمنع تكرار الخصم لو نفس الطلب اتبعت مرتين (دبل-كليك/ريتراي شبكة).
        $idempotencyKey = $this->get('idempotency_key');
        $idempotencyKey = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null;

        $expected=$this->get('expected_charge');
        if ($expected!==null && (!is_scalar($expected) || !is_numeric($expected) || !is_finite((float)$expected) || (float)$expected<0)) return $this->error('مبلغ تأكيد غير صحيح',422);
        $result = $this->service->subscribeWithBalance(
            (int) $this->user['id'],
            (string) $this->get('plan_key'),
            (string) $this->get('plan_type'),
            $idempotencyKey,
            ($c = $this->get('coupon_code')) !== null && is_string($c) && trim($c) !== '' ? $c : null,
            $expected===null ? null : (float)$expected
        );

        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }

        $message = !empty($result['scheduled']) ? 'اتجدول ✔ هتفضل على باقتك لحد ' . $result['effective_at'] . '، وبعدها هتتجدد على "' . $result['plan_name'] . '"'
            : (!empty($result['unscheduled']) ? 'اتلغى التخفيض المجدول ✔ هتفضل على باقتك' : (!empty($result['unchanged']) ? 'انت مشترك بالفعل في الباقة دي' : ''));
        if ($message === '') $message = !empty($result['is_plan_change'])
            ? (($result['charged'] ?? 0) > 0
                ? 'تم تفعيل الباقة الجديدة وخصم فرق السعر $' . $result['charged'] . ' ✔'
                : 'تم تفعيل الباقة الجديدة ✔')
            : 'تم تفعيل الاشتراك فورًا من رصيدك ✔' . (!empty($result['discount']) ? ' (خصم $' . $result['discount'] . ')' : '');

        return $this->success($result, $message);
    }

    // ============================================
    // لوحة الأدمن
    // ============================================

    /** GET /api/admin/wallet/pending */
    public function listPendingDeposits(array $params = []): array
    {
        try {
            $pending = $this->service->getPendingDeposits();
            $result = [];
            foreach ($pending as $tx) {
                $user = (new User())->find((int) $tx->getAttribute('user_id'));
                $result[] = array_merge($tx->toArray(), [
                    'user_email' => $user ? $user->getAttribute('email') : '-',
                    'user_company' => $user ? $user->getAttribute('company_name') : '-',
                ]);
            }
            return $this->success(['deposits' => $result]);
        } catch (Exception $e) {
            Logger::error('Admin listPendingDeposits Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الطلبات', 500);
        }
    }

    /** GET /api/admin/wallet/stats */
    public function getAdminStats(array $params = []): array
    {
        try {
            return $this->success(['stats' => $this->service->getAdminStats()]);
        } catch (Exception $e) {
            Logger::error('Admin getAdminStats Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الإحصائيات', 500);
        }
    }

    /** GET /api/admin/wallet/mrr-trend?days=30 */
    public function getMrrTrend(array $params = []): array
    {
        try {
            $days = (int) $this->get('days', 30);
            $days = max(7, min(365, $days));
            return $this->success(['trend' => $this->service->getMrrTrend($days), 'days' => $days]);
        } catch (Exception $e) {
            Logger::error('Admin getMrrTrend Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب تاريخ الإيراد', 500);
        }
    }

    /**
     * GET /api/admin/wallet/usage-revenue?year=2026&month=8
     * تحليل تنافسي: "الإيراد لكل ميزة" من "ادفع حسب الاستخدام" الشهري.
     */
    public function getUsageRevenueBreakdown(array $params = []): array
    {
        try {
            $year = (int) $this->get('year', date('Y'));
            $month = (int) $this->get('month', date('n'));
            return $this->success(['usage_revenue' => $this->service->getUsageRevenueBreakdown($year, $month)]);
        } catch (Exception $e) {
            Logger::error('Admin getUsageRevenueBreakdown Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الإيراد لكل ميزة', 500);
        }
    }

    /** POST /api/admin/wallet/{id}/approve */
    public function approveDeposit(array $params = []): array
    {
        try {
            $tx = $this->service->approveDeposit((int) ($params['id'] ?? 0), (int) $this->user['id'], (string) $this->get('note', ''));
            $this->log('Admin Approved Deposit', ['transaction_id' => $tx->getAttribute('id')]);
            return $this->success(['transaction' => $tx->toArray()], 'تمت الموافقة على الإيداع');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/admin/wallet/{id}/reject */
    public function rejectDeposit(array $params = []): array
    {
        try {
            $tx = $this->service->rejectDeposit((int) ($params['id'] ?? 0), (int) $this->user['id'], (string) $this->get('note', ''));
            $this->log('Admin Rejected Deposit', ['transaction_id' => $tx->getAttribute('id')]);
            return $this->success(['transaction' => $tx->toArray()], 'تم رفض الطلب');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** GET /api/admin/wallet/settings */
    public function getPaymentSettingsAdmin(array $params = []): array
    {
        try {
            return $this->success(['settings' => $this->service->getPaymentSettings()]);
        } catch (Exception $e) {
            return $this->error('تعذر جلب الإعدادات', 500);
        }
    }

    /** PUT /api/admin/wallet/settings */
    public function updatePaymentSettingsAdmin(array $params = []): array
    {
        try {
            foreach (WalletService::PAYMENT_KEYS as $key) {
                if ($this->get($key) !== null) {
                    $this->service->updatePaymentSetting($key, mb_substr(trim((string) $this->get($key)), 0, $key === 'pay_instructions' ? 600 : 120));
                }
            }
            $this->log('Admin Updated Wallet Settings', []);
            return $this->success([], 'تم تحديث بيانات الدفع');
        } catch (Exception $e) {
            Logger::error('Admin updatePaymentSettingsAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** GET /api/admin/wallet/usage-pricing */
    public function listUsagePricingAdmin(array $params = []): array
    {
        try {
            return $this->success(['pricing' => $this->service->getAllUsagePricingForAdmin()]);
        } catch (Exception $e) {
            Logger::error('Admin listUsagePricingAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب التسعير', 500);
        }
    }

    /** PUT /api/admin/wallet/usage-pricing/{id} */
    public function updateUsagePricingAdmin(array $params = []): array
    {
        try {
            $this->service->updateUsagePricing(
                (int) ($params['id'] ?? 0),
                (float) $this->get('price', 0),
                (bool) $this->get('is_active', true)
            );
            $this->log('Admin Updated Usage Pricing', ['id' => $params['id'] ?? null]);
            return $this->success([], 'تم تحديث التسعير');
        } catch (Exception $e) {
            Logger::error('Admin updateUsagePricingAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    private function isWalletAdmin(): bool
    {
        return in_array($this->user['role'] ?? '', ['super_admin', 'admin'], true);
    }

    /** POST /api/admin/users/{id}/add-balance  (مبلغ موجب) */
    public function adminAddBalance(array $params = []): array
    {
        return $this->handleBalanceChange($params, 'add');
    }

    /** POST /api/admin/users/{id}/deduct-balance  (مبلغ موجب = قيمة السحب) */
    public function adminDeductBalance(array $params = []): array
    {
        return $this->handleBalanceChange($params, 'deduct');
    }

    private function handleBalanceChange(array $params, string $mode): array
    {
        if (!$this->isWalletAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        $userId = (int) ($params['id'] ?? 0);
        if (!$userId) {
            return $this->error('عميل غير موجود', 404);
        }

        $rawAmount = $this->get('amount', null);
        if (!is_numeric($rawAmount)) {
            return $this->error('المبلغ غير صالح', 422);
        }
        $amount = round((float) $rawAmount, 2);
        if ($amount <= 0) {
            return $this->error('المبلغ لازم يكون أكبر من صفر', 422);
        }
        if ($amount > 99999999.99) {
            return $this->error('المبلغ أكبر من الحد المسموح', 422);
        }
        $note = (string) $this->get('note', '');

        try {
            if ($mode === 'deduct') {
                // السماح برصيد سالب لازم يكون صريح ومن super_admin فقط
                $allowNegative = !empty($this->get('allow_negative', false)) && ($this->user['role'] ?? '') === 'super_admin';
                $tx = $this->service->adminDeductBalance($userId, $amount, (int) $this->user['id'], $note, $allowNegative);
                $msg = 'تم سحب الرصيد';
            } else {
                $tx = $this->service->adminAddBalance($userId, $amount, (int) $this->user['id'], $note);
                $msg = 'تم إضافة الرصيد';
            }
            $this->log($mode === 'deduct' ? 'Admin Deducted Balance' : 'Admin Added Balance', ['user_id' => $userId, 'amount' => $amount]);
            return $this->success(['transaction' => $tx->toArray(), 'new_balance' => $this->service->getBalance($userId)], $msg);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** GET /api/admin/wallet/platform-balance */
    public function getPlatformBalance(array $params = []): array
    {
        try {
            return $this->success(['platform_balance' => $this->service->getPlatformBalanceSummary()]);
        } catch (Exception $e) {
            Logger::error('Admin getPlatformBalance Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب رصيد المنصة', 500);
        }
    }

    /** POST /api/admin/wallet/platform-balance/reset - تصفير عدّاد رصيد المنصة (غير مدمّر) */
    public function resetPlatformBalance(array $params = []): array
    {
        if (!$this->isWalletAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        try {
            $summary = $this->service->resetPlatformBalance((int) $this->user['id']);
            $this->log('Admin Reset Platform Balance', ['baseline' => $summary['baseline']]);
            return $this->success(['platform_balance' => $summary], 'تم تصفير رصيد المنصة');
        } catch (Exception $e) {
            Logger::error('Admin resetPlatformBalance Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تصفير رصيد المنصة', 500);
        }
    }

    /** POST /api/admin/wallet/platform-balance/restore - الرجوع للإجمالي الحقيقي */
    public function restorePlatformBalance(array $params = []): array
    {
        if (!$this->isWalletAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        try {
            $summary = $this->service->restorePlatformBalance((int) $this->user['id']);
            $this->log('Admin Restored Platform Balance', []);
            return $this->success(['platform_balance' => $summary], 'تم الرجوع للرصيد الفعلي');
        } catch (Exception $e) {
            Logger::error('Admin restorePlatformBalance Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ العملية', 500);
        }
    }

    /**
     * POST /api/admin/wallet/zero-all-balances - ⚠️ تصفير فعلي لرصيد كل العميلات.
     * super_admin فقط + لازم confirm = "ZERO" صراحةً.
     */
    public function zeroAllBalances(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('تصفير أرصدة كل العملاء متاح للـ Super Admin فقط', 403);
        }
        if ((string) $this->get('confirm', '') !== 'ZERO') {
            return $this->error('لازم تأكيد العملية بكتابة ZERO', 422);
        }
        try {
            $result = $this->service->zeroAllCustomerBalances((int) $this->user['id'], (string) $this->get('note', ''));
            $this->log('Admin Zeroed All Balances', $result);
            return $this->success($result, 'تم تصفير أرصدة ' . $result['users_affected'] . ' عميل');
        } catch (Exception $e) {
            Logger::error('Admin zeroAllBalances Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تصفير الأرصدة - لم يتم تنفيذ أي تغيير', 500);
        }
    }

    /** POST /api/admin/wallet/cards/generate - توليد دفعة بطاقات شحن جديدة */
    public function generateCards(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin' && ($this->user['role'] ?? '') !== 'admin') {
            return $this->error('غير مصرح', 403);
        }
        try {
            $cards = $this->service->generateRechargeCards(
                (int) $this->get('count', 0),
                (float) $this->get('value', 0),
                (int) $this->user['id'],
                (string) $this->get('batch_label', '')
            );
            $this->log('Admin Generated Recharge Cards', ['count' => count($cards)]);
            return $this->success(['cards' => $cards], 'تم توليد ' . count($cards) . ' بطاقة');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** GET /api/admin/wallet/cards */
    public function listCards(array $params = []): array
    {
        try {
            $cards = (new WalletRechargeCard())->where([], ['created_at' => 'DESC'], 200);
            return $this->success(['cards' => array_map(fn ($c) => $c->toArray(), $cards)]);
        } catch (Exception $e) {
            Logger::error('Admin listCards Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب البطاقات', 500);
        }
    }

    /** POST /api/wallet/redeem-card - العميل يشحن بطاقة */
    public function redeemCard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['code' => 'required'])) {
            return $this->error('اكتب كود البطاقة', 422);
        }

        try {
            $result = $this->service->redeemCard((int) $this->user['id'], (string) $this->get('code'));
            $this->log('Wallet Card Redeemed', ['amount' => $result['value']]);
            return $this->success($result, 'تم شحن $' . number_format($result['value'], 2) . ' لرصيدك بنجاح 🎉');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    // ============================================
    // Refunds (Section 9) - Billing Admin بس (مش Billing Viewer)
    // ============================================

    /** GET /api/admin/refunds */
    public function listRefunds(array $params = []): array
    {
        try {
            $refundService = new RefundService();
            return $this->success(['refunds' => $refundService->listAll()]);
        } catch (Exception $e) {
            Logger::error('Admin listRefunds Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الاسترجاعات', 500);
        }
    }

    /** POST /api/admin/refunds */
    public function createRefund(array $params = []): array
    {
        if (!$this->validate(['payment_transaction_id' => 'required', 'amount' => 'required|numeric'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        try {
            $refundService = new RefundService();
            $result = $refundService->createRefund(
                (int) $this->get('payment_transaction_id'),
                (float) $this->get('amount'),
                (string) $this->get('reason', ''),
                (int) $this->user['id']
            );

            if (!empty($result['pending'])) {
                return ['success' => true, 'data' => $result, 'message' => 'الاسترجاع قيد المعالجة أو المصالحة؛ لا تكرر الطلب', 'code' => 202];
            }
            if (!$result['success']) {
                return $this->error($result['error'] ?? 'تعذر الاسترجاع', 422);
            }

            $this->log('Admin Created Refund', ['payment_transaction_id' => $this->get('payment_transaction_id'), 'amount' => $this->get('amount')]);
            return $this->success($result, 'تم تنفيذ الاسترجاع بنجاح');
        } catch (Exception $e) {
            Logger::error('Admin createRefund Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ الاسترجاع', 500);
        }
    }

    /** BillingAdminMiddleware protects the route; no new refund reservation is created. */
    public function reconcileRefund(array $params = []): array
    {
        try {
            $id=(int)($params['id']??0);
            $r=(new Refund())->find($id);
            $tx=$r?(new PaymentTransaction())->find((int)$r->getAttribute('payment_transaction_id')):null;
            $gateway=$tx?$tx->getAttribute('gateway'):'';
            $service=new RefundService();
            $result=$gateway==='wallet'?$service->reconcileWalletRefund($id):($gateway==='paymob'?$service->reconcilePaymobRefund($id):$service->reconcileStripeRefund($id));
            if (!empty($result['pending'])) { return ['success' => true, 'data' => $result, 'code' => 202]; }
            return !empty($result['success']) ? $this->success($result) : $this->error($result['error'] ?? 'تعذر المصالحة', 422);
        } catch (Throwable $e) { return $this->error('تعذر المصالحة', 503); }
    }

    // ============================================
    // Tax Rules (Section 12) - Billing Admin بس
    // ============================================

    /** GET /api/admin/tax-rules */
    public function listTaxRules(array $params = []): array
    {
        try {
            return $this->success(['rules' => (new TaxService())->listAll()]);
        } catch (Exception $e) {
            Logger::error('Admin listTaxRules Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب قواعد الضريبة', 500);
        }
    }

    /** POST /api/admin/tax-rules */
    public function upsertTaxRule(array $params = []): array
    {
        if (!$this->validate(['country_code' => 'required', 'tax_type' => 'required', 'tax_rate_percent' => 'required|numeric'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            (new TaxService())->upsertRule(
                (string) $this->get('country_code'),
                (string) $this->get('tax_type'),
                (float) $this->get('tax_rate_percent'),
                (bool) $this->get('is_active', true)
            );
            $this->log('Admin Upserted Tax Rule', ['country' => $this->get('country_code')]);
            return $this->success([], 'تم حفظ قاعدة الضريبة');
        } catch (Exception $e) {
            Logger::error('Admin upsertTaxRule Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }
}
