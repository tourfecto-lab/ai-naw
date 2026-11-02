<?php

/**
 * Tourfecto - Subscription Controller
 * متحكم إدارة الاشتراكات والفوترة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SubscriptionController extends Controller
{
    /**
     * @var SubscriptionValidator $subscription - مدقق الاشتراكات
     */
    private $subscription;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->subscription = new SubscriptionValidator();
    }

    /**
     * التحقق من صلاحية الاشتراك
     * POST /api/subscription/validate
     * @param array $params
     * @return array
     */
    public function validateSubscriptionStatus(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $result = $this->subscription->validateSubscription($this->user['id']);

            return $this->success($result);

        } catch (Exception $e) {
            Logger::error('Validate Subscription Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Validation failed', 500);
        }
    }

    /**
     * الحصول على تفاصيل الاشتراك الحالي
     * GET /api/subscription/current
     * @param array $params
     * @return array
     */
    public function current(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            SubscriptionPeriod::refreshQuota((int)$this->user['id']);
            $subscription = Subscription::activeSubscriptionRow($this->user['id']);

            if (!$subscription) {
                return $this->success([
                    'has_subscription' => false
                ]);
            }

            $usage = $this->getUsageStats($this->user['id']);

            return $this->success([
                'has_subscription' => true,
                'subscription' => $subscription,
                'usage' => $usage,
                'features' => $this->getPlanFeatures($subscription['plan_name'])
            ]);

        } catch (Exception $e) {
            Logger::error('Get Current Subscription Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get subscription', 500);
        }
    }

    /**
     * إنشاء اشتراك جديد
     * POST /api/subscription/create
     * @param array $params
     * @return array
     */
    public function create(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            if ($e = $this->ownerOnly()) return $e;

            $planName = $this->get('plan_name');
            $planType = $this->get('plan_type', 'monthly');

            if (!$planName) {
                return $this->error('Plan name is required', 400);
            }

            // التحقق من وجود اشتراك نشط
            // تصحيح: expiry_date مش اسم العمود الحقيقي في القاعدة المنشورة
            $expiryCol = Subscription::expiryColumn();
            $expiryClause = $expiryCol ? "AND (`{$expiryCol}` IS NULL OR `{$expiryCol}` > NOW())" : '';
            $sql = "SELECT id FROM subscriptions 
                    WHERE user_id = ? 
                    AND status = 'active' 
                    {$expiryClause}
                    LIMIT 1";

            $existing = $this->db->query($sql, [$this->user['id']]);

            if (!empty($existing)) {
                return $this->error('You already have an active subscription', 400);
            }

            // ============================================
            // تصحيح (2026-08-20): منع الاشتراك المجاني.
            // كان create() بيعمل Subscription::createSubscription مباشرة
            // = اشتراك active من غير أي دفع - أي عميل يقدر يفعل باقة
            // enterprise بـ 0$ وبلا أي محاسبة. الاشتراك دلوقتي لازم يمر
            // عبر WalletService::subscribeWithBalance اللي بيخصم السعر
            // الفعلي للباقة من رصيد المحفظة (ويرفض لو الرصيد ناقص،
            // ويرجّع المبلغ المطلوب + العجز للفرونت إند يوجّه العميل
            // للإيداع). مسار تفعيل الأدمن اليدوي (AdminController) لسه
            // شغال زي ما هو - ده ميزة للأدمن مش للعميل.
            // ============================================
            if (!class_exists('WalletService')) {
                return $this->error('نظام الدفع غير متاح', 500);
            }
            $walletService = new WalletService();
            $paymentResult = $walletService->subscribeWithBalance(
                $this->user['id'],
                $planName,
                $planType
            );

            if (empty($paymentResult['success'])) {
                return $this->error(
                    (string) ($paymentResult['error'] ?? 'تعذر إتمام الدفع'),
                    402,
                    [
                        'balance' => (float) ($paymentResult['balance'] ?? 0),
                        'required' => (float) ($paymentResult['required'] ?? 0),
                        'shortfall' => (float) ($paymentResult['shortfall'] ?? 0),
                        'is_plan_change' => (bool) ($paymentResult['is_plan_change'] ?? false),
                        'action' => '/wallet',
                    ]
                );
            }

            $this->log('Subscription Created', [
                'plan' => $planName,
                'type' => $planType,
                'charged' => (float) ($paymentResult['charged'] ?? 0),
            ]);

            return $this->success([
                'subscription' => $paymentResult['subscription']->toArray(),
                'charged' => (float) ($paymentResult['charged'] ?? 0),
                'new_balance' => (float) ($paymentResult['new_balance'] ?? 0),
            ], 'تم تفعيل اشتراكك والخصم من محفظتك');

        } catch (Exception $e) {
            Logger::error('Create Subscription Error', [
                'message' => $e->getMessage()
            ]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'Failed to create subscription: ' . $e->getMessage()
                : 'Failed to create subscription';
            return $this->error($debugMsg, 500);
        }
    }

    /**
     * تجديد الاشتراك
     * POST /api/subscription/renew
     * @param array $params
     * @return array
     */
    public function renew(array $params = []): array
    {
        // 🔒 كان بيمدّ الاشتراك ويصفّر الاستخدام من غير أي خصم (ثغرة تجديد ببلاش).
        // دلوقتي بيمر على نفس منطق التجديد الحقيقي: بيخصم سعر الباقة من المحفظة،
        // وبيرفض لو الفترة لسه ماخلصتش.
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            if ($e = $this->ownerOnly()) return $e;
            if ($rateError = $this->rateLimitGuard('user', 'subscription_renew', 5, 60)) {
                return $rateError;
            }
            $rows = $this->db->query(
                "SELECT id FROM subscriptions WHERE user_id = ? AND status IN ('active','past_due') ORDER BY id DESC LIMIT 1",
                [(int) $this->user['id']]
            );
            if (empty($rows)) {
                return $this->error('مفيش اشتراك محتاج تجديد', 404);
            }
            $r = (new WalletService())->renewSubscriptionFromBalance((int) $rows[0]['id']);
            if (empty($r['success'])) {
                $msg = [
                    'not_due' => 'اشتراكك لسه ساري - التجديد بيحصل لوحده لما الفترة تخلص',
                    'cancel_at_period_end' => 'اشتراكك متلغي في آخر الفترة - اشترك من جديد من صفحة الباقات',
                    'insufficient_balance' => 'رصيد المحفظة مش كافي للتجديد - اشحن المحفظة الأول',
                ][$r['reason'] ?? ''] ?? 'تعذر تجديد الاشتراك';
                return $this->error($msg, ($r['reason'] ?? '') === 'insufficient_balance' ? 402 : 409, ['reason' => $r['reason'] ?? null]);
            }
            $this->log('Subscription Renewed', ['subscription_id' => (int) $rows[0]['id']]);
            return $this->success(['renewal' => $r], 'تم تجديد الاشتراك وخصم قيمته من المحفظة');
        } catch (Throwable $e) {
            Logger::error('Renew Subscription Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تجديد الاشتراك', 500);
        }
    }

    /**
     * إلغاء الاشتراك
     * POST /api/subscription/cancel
     * @param array $params
     * @return array
     */
    public function cancel(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            if ($e = $this->ownerOnly()) return $e;

            $result = $this->db->query(
                "SELECT * FROM subscriptions WHERE user_id = ? AND status IN ('active','trialing','past_due') ORDER BY id DESC LIMIT 1",
                [$this->user['id']]
            );
            if (empty($result)) {
                return $this->error('مفيش اشتراك شغّال تلغيه', 404);
            }
            $row = $result[0];
            $reason = mb_substr(trim((string) $this->get('reason', '')), 0, 500);
            $endRaw = $row['current_period_end'] ?: ($row['expiry_date'] ?? null);
            $endTs = $endRaw ? strtotime((string) $endRaw) : 0;

            // ✅ إلغاء في آخر الفترة: العميل دفع الفترة دي، فالخدمة تفضل شغّالة لحد ما تخلص
            //    ومفيش تجديد بعدها (SubscriptionLifecycleService بيقفله لوحده في ميعاده).
            if ($row['status'] === 'active' && $endTs > time()) {
                $this->db->exec(
                    'UPDATE subscriptions SET cancel_at_period_end = 1, cancelled_at = NOW(), cancellation_reason = ?, current_period_end = COALESCE(current_period_end, ?) WHERE id = ?',
                    [$reason ?: null, date('Y-m-d H:i:s', $endTs), (int) $row['id']]
                );
                ActivityLog::record('subscription', 'subscription.cancel_scheduled', [
                    'user_id' => (int) $this->user['id'], 'subject_type' => 'subscriptions', 'subject_id' => (int) $row['id'],
                ]);
                return $this->success(
                    ['cancel_at_period_end' => true, 'ends_at' => date('Y-m-d', $endTs)],
                    'تمام - اشتراكك هيفضل شغّال لحد ' . date('Y-m-d', $endTs) . ' ومش هيتجدد بعدها. تقدر ترجع في قرارك قبل الميعاد ده.'
                );
            }

            // تجربة/متأخر/فترة خلصت: إلغاء فوري
            $subscription = new Subscription($row);
            if (!$subscription->cancel()) {
                return $this->error('تعذر إلغاء الاشتراك', 500);
            }
            $this->db->exec('UPDATE subscriptions SET cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?', [$reason ?: null, (int) $row['id']]);
            $subscription->setKeyValue((int) $row['id']);

            $this->log('Subscription Cancelled', [
                'subscription_id' => $subscription->getAttribute('id')
            ]);

            ActivityLog::record('subscription', 'subscription.cancelled', [
                'user_id' => (int) $this->user['id'],
                'subject_type' => 'subscriptions',
                'subject_id' => (int) $subscription->getAttribute('id'),
            ]);

            return $this->success(['cancel_at_period_end' => false], 'تم إلغاء الاشتراك');

        } catch (Exception $e) {
            Logger::error('Cancel Subscription Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to cancel subscription', 500);
        }
    }

    /** تغيير الاشتراك والدفع لصاحب الحساب بس (عضو الفريق كان بيشترك/يلغي على حسابه هو بالغلط) */
    private function ownerOnly(): ?array
    {
        return !empty($this->user['owner_user_id']) ? $this->error('تغيير الاشتراك والدفع لصاحب الحساب بس - كلّمه يعمله من حسابه', 403) : null;
    }

    /** POST /api/subscription/scheduled-change/cancel - إلغاء تخفيض الباقة المجدول (العميل يفضل على باقته) */
    public function cancelScheduledChange(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->ownerOnly()) return $e;
        if ($e = $this->verifyCsrf()) return $e;
        $uid = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
        if (!WalletService::ensureScheduledColumn()) return $this->error('غير متاح', 503);
        $st = $this->db->getConnection()->prepare("UPDATE subscriptions SET scheduled_plan_code = NULL WHERE user_id = ? AND status = 'active' AND scheduled_plan_code IS NOT NULL");
        $st->execute([$uid]);
        return $st->rowCount() ? $this->success([], 'تمام - هتفضل على باقتك وهتتجدد بيها عادي') : $this->error('مفيش تغيير مجدول', 404);
    }

    /** POST /api/subscription/resume - التراجع عن الإلغاء قبل نهاية الفترة */
    public function resume(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->ownerOnly()) return $e;
        $rows = $this->db->query(
            "SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND cancel_at_period_end = 1 AND current_period_end > NOW() ORDER BY id DESC LIMIT 1",
            [(int) $this->user['id']]
        );
        if (!$rows) {
            return $this->error('مفيش إلغاء مجدول ترجع فيه', 404);
        }
        $this->db->exec('UPDATE subscriptions SET cancel_at_period_end = 0, cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?', [(int) $rows[0]['id']]);
        ActivityLog::record('subscription', 'subscription.resumed', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'subscriptions', 'subject_id' => (int) $rows[0]['id'],
        ]);
        return $this->success([], 'رجّعنا اشتراكك - هيتجدد عادي في ميعاده');
    }

    /**
     * ترقية الباقة
     * POST /api/subscription/upgrade
     * @param array $params
     * @return array
     */
    public function upgrade(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            if ($e = $this->ownerOnly()) return $e;

            $newPlan = $this->get('plan_name');
            if (!$newPlan) {
                return $this->error('Plan name is required', 400);
            }

            // تصحيح جذري (2026-08-09 / Phase 1): الكود القديم هنا كان بيكتب
            // على أعمدة قديمة (plan_name, ai_credits, price...) مش موجودة
            // في جدول subscriptions الحقيقي على السيرفر (اللي بيستخدم
            // plan_id + subscription_plans بدلها فعليًا - شوف تعليق
            // Subscription::activeSubscriptionRow() للتفاصيل الكاملة). يعني
            // كل استدعاء لـ upgrade() كان بيفشل فعليًا (UPDATE على عمود
            // مش موجود) ويرجّع 500 دايمًا في الإنتاج، ومكنش بيخصم أي حاجة
            // حتى لو نجح بالغلط - ثغرة ترقية مجانية لو كان فيه جدول قديم شغال.
            //
            // الإصلاح: نستخدم نفس المسار الحقيقي المضمون
            // (WalletService::subscribeWithBalance) اللي بيحسب فرق السعر
            // بس لو فيه اشتراك فعّال، يخصمه من المحفظة، ويلغي الاشتراك
            // القديم قبل ما ينشئ الجديد - بدل التكرار المنطقي، /api/wallet/subscribe
            // و /api/subscription/upgrade بقوا نفس المسار الفعلي تحت الغطاء.
            $planType = (string) $this->get('plan_type', '');
            if ($planType === '') {
                $currentRow = Subscription::activeSubscriptionRow((int) $this->user['id']);
                $planType = $currentRow['plan_type'] ?? 'monthly';
            }
            if (!in_array($planType, ['monthly', 'yearly'], true)) {
                $planType = 'monthly';
            }

            $idempotencyKey = $this->get('idempotency_key');
            $idempotencyKey = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null;

            $walletService = new WalletService();
            $result = $walletService->subscribeWithBalance((int) $this->user['id'], (string) $newPlan, $planType, $idempotencyKey);

            if (!$result['success']) {
                return $this->error($result['error'], 402, $result);
            }

            $this->log('Subscription Upgraded', [
                'new_plan' => $newPlan,
                'plan_type' => $planType,
                'charged' => $result['charged'] ?? 0,
            ]);

            $msg = !empty($result['scheduled']) ? 'هتفضل على باقتك الحالية لحد ' . $result['effective_at'] . '، وبعدها هتتجدد على الباقة الجديدة'
                : (!empty($result['unscheduled']) ? 'اتلغى التخفيض المجدول - هتفضل على باقتك' : (!empty($result['unchanged']) ? 'انت مشترك بالفعل في الباقة دي' : 'اتغيرت باقتك ✔'));
            return $this->success($result, $msg);

        } catch (Exception $e) {
            Logger::error('Upgrade Subscription Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to upgrade subscription', 500);
        }
    }

    /**
     * الحصول على خطط الاشتراك المتاحة
     * GET /api/subscription/plans
     * @param array $params
     * @return array
     */
    public function getPlans(array $params = []): array
    {
        try {
            $plans = Subscription::getAvailablePlans();

            return $this->success([
                'plans' => $plans
            ]);

        } catch (Exception $e) {
            Logger::error('Get Plans Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get plans', 500);
        }
    }

    /**
     * الحصول على إحصائيات الاستخدام
     * @param int $userId
     * @return array
     */
    private function getUsageStats(int $userId): array
    {
        $data = Subscription::activeSubscriptionRow($userId);

        if (!$data) {
            return [];
        }

        $usage = [
            'ai' => [
                'total' => (int) $data['ai_credits'],
                'used' => (int) $data['ai_credits_used'],
                'remaining' => max(0, (int) $data['ai_credits'] - (int) $data['ai_credits_used'])
            ],
            'chat' => [
                'total' => (int) $data['chat_credits'],
                'used' => (int) $data['chat_credits_used'],
                'remaining' => max(0, (int) $data['chat_credits'] - (int) $data['chat_credits_used'])
            ],
            'review' => [
                'total' => (int) $data['review_credits'],
                'used' => (int) $data['review_credits_used'],
                'remaining' => max(0, (int) $data['review_credits'] - (int) $data['review_credits_used'])
            ],
            'competitor' => [
                'total' => (int) $data['competitor_analysis_limit'],
                'used' => (int) $data['competitor_analysis_used'],
                'remaining' => max(0, (int) $data['competitor_analysis_limit'] - (int) $data['competitor_analysis_used'])
            ]
        ];

        // Phase 3: تنبيهات نسب الاستخدام (50/75/90/100%). period_key بيتغيّر
        // تلقائيًا كل ما الاشتراك يتجدد أو الباقة تتغيّر (id مختلف أو
        // expiry_date مختلف) فالتنبيهات بترجع تتصفّر لوحدها كل فترة فوترة.
        if (class_exists('UsageAlertService') && !empty($data['id'])) {
            $periodKey = $data['id'] . ':' . ($data['last_usage_reset_at'] ?? $data['expiry_date'] ?? '');
            (new UsageAlertService())->checkAndNotify($userId, $usage, $periodKey);
        }

        return $usage;
    }

    /**
     * الحصول على ميزات الباقة
     * @param string $planName
     * @return array
     */
    private function getPlanFeatures(string $planName): array
    {
        $plans = Subscription::getAvailablePlans();
        return $plans[$planName]['features'] ?? [];
    }

    // ============================================
    // الدوال التالية أُضيفت لاحقًا لأن app/routes/web.php و api.php
    // كانا يشيران إليها ولم تكن معرّفة، ما كان سيسبب خطأ
    // "Action not found in SubscriptionController" عند زيارتها.
    // ============================================

    /**
     * GET /api/subscription/billing-profile
     * بيانات الفوترة الرسمية للعميل الحالي (اسم قانوني، عنوان، رقم ضريبي).
     * Phase 4 - جدول جديد بالكامل، مفيش أي تعديل على منطق الفوترة الأساسي.
     */
    public function getBillingProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $profile = BillingProfile::forUser((int) $this->user['id']);
            return $this->success(['profile' => $profile ? $profile->toArray() : null]);
        } catch (Exception $e) {
            Logger::error('Get Billing Profile Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب بيانات الفوترة', 500);
        }
    }

    /** PUT /api/subscription/billing-profile */
    public function updateBillingProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        // كل الحقول اختيارية إلا الإيميل لازم يكون صيغة صحيحة لو اتبعت.
        $billingEmail = trim((string) $this->get('billing_email', ''));
        if ($billingEmail !== '' && !filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->error('صيغة إيميل الفوترة غير صحيحة', 422);
        }

        try {
            $userId = (int) $this->user['id'];
            $profile = BillingProfile::forUser($userId) ?? new BillingProfile();
            $profile->fill([
                'user_id' => $userId,
                'legal_name' => trim((string) $this->get('legal_name', '')) ?: null,
                'billing_email' => $billingEmail ?: null,
                'address_line1' => trim((string) $this->get('address_line1', '')) ?: null,
                'address_line2' => trim((string) $this->get('address_line2', '')) ?: null,
                'city' => trim((string) $this->get('city', '')) ?: null,
                'country' => trim((string) $this->get('country', '')) ?: null,
                'tax_id' => trim((string) $this->get('tax_id', '')) ?: null,
            ]);
            $profile->save();

            ActivityLog::record('subscription', 'subscription.billing_profile_updated', [
                'user_id' => $userId, 'subject_type' => 'billing_profiles',
                'subject_id' => (int) $profile->getAttribute('id'),
            ]);

            return $this->success(['profile' => $profile->toArray()], 'تم حفظ بيانات الفوترة');
        } catch (Exception $e) {
            Logger::error('Update Billing Profile Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ بيانات الفوترة', 500);
        }
    }

    /** GET /pricing (عام - بدون تسجيل دخول) */
    public function showPricing(array $params = []): array
    {
        return $this->renderPlansPage();
    }

    /** GET /subscription */
    public function showSubscription(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/subscription'));
            exit;
        }

        $current = $this->current($params);
        $data = $current['data'] ?? [];
        $hasSubscription = $data['has_subscription'] ?? false;

        if (!$hasSubscription) {
            $tNoActiveSub = $this->tr('subscription.no_active');
            $tSeePlans = $this->tr('subscription.see_plans');
            $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">💳</div>' . $tNoActiveSub . '<br><a href="/plans" class="p-btn primary" style="margin-top:14px;">' . $tSeePlans . ' ←</a></div></div>';
            $body .= self::plansStudioBlock();
            $body .= $this->renderWalletCardHtml();
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderPanelPage('_subscription', $this->tr('subscription.page_title'), $this->tr('subscription.page_subtitle'), $body, $this->buildWalletOnlyScript());
            exit;
        }

        $sub = $data['subscription'];
        $usage = $data['usage'] ?? [];
        $plansData = SubscriptionPlan::allAsLegacyArray();
        $planInfo = $plansData[$sub['plan_name']] ?? null;
        $planLabel = htmlspecialchars($planInfo['name'] ?? $sub['plan_name'], ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars((string) $sub['status'], ENT_QUOTES, 'UTF-8');
        // Section 7: خرائط كل حالات دورة الحياة الحقيقية (مؤكدة من الـ
        // ENUM الفعلي: active/trialing/past_due/cancelled/paused) - قبل
        // كده أي حالة غير 'active' كانت بتظهر بالكلمة الإنجليزية الخام
        // جوه Pill أخضر ثابت (✔) - مضلّل جدًا لعميل past_due (المفروض
        // يشوف تحذير مش علامة "تمام").
        $statusMeta = [
            'active' => ['label' => $this->tr('subscription.status.active'), 'pill' => 'green', 'icon' => '✔'],
            'trialing' => ['label' => 'فترة تجربة مجانية', 'pill' => 'blue', 'icon' => '🎁'],
            'past_due' => ['label' => 'متأخر - محتاج تجديد', 'pill' => 'orange', 'icon' => '⚠️'],
            'cancelled' => ['label' => 'ملغى', 'pill' => 'red', 'icon' => '✖'],
            'paused' => ['label' => 'موقوف مؤقتًا', 'pill' => 'gray', 'icon' => '⏸'],
        ];
        $meta = $statusMeta[$status] ?? ['label' => $status, 'pill' => 'gray', 'icon' => '•'];
        $statusLabel = $meta['label'];
        $statusPillColor = $meta['pill'];
        $statusIcon = $meta['icon'];

        // بانر تحذيري إضافي واضح لو الاشتراك في فترة سماح (past_due) -
        // عشان العميل يعرف بالظبط إيه اللي محتاج يعمله.
        $lifecycleBanner = '';
        if ($status === 'past_due') {
            // أيام السماح الفاضلة بعد نهاية الفترة، وهل ده كان تجربة مجانية (سعرها صفر) - العميل لازم يعرف يعمل إيه بالظبط
            $periodEnd = strtotime((string) ($sub['current_period_end'] ?? $sub['expiry_date'] ?? 'now')) ?: time();
            $graceLeft = max(0, (int) ceil(($periodEnd + BillingRules::GRACE_PERIOD_DAYS * 86400 - time()) / 86400));
            $wasTrial = (float) ($sub['price'] ?? 0) <= 0 || !empty($sub['trial_ends_at']);
            $lifecycleBanner = '<div class="alert alert-warning" style="margin-bottom:14px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;"><span>⚠️ '
                . ($wasTrial ? '<span>تجربتك المجانية خلصت. خدماتك وبياناتك لسه شغالة لفترة قصيرة عشان تلحق تفعّل باقتك</span>' : '<span>اشتراكك محتاج تجديد. خدماتك لسه شغالة لفترة قصيرة عشان تلحق تجدد</span>')
                . ' (<span>الأيام الفاضلة</span>: <b dir="ltr">' . $graceLeft . '</b>).</span>'
                . '<a class="p-btn primary sm" href="/plans">' . ($wasTrial ? 'فعّل باقتك' : 'جدّد اشتراكك') . '</a></div>';
        } elseif ($status === 'active' && !empty($sub['cancel_at_period_end'])) {
            $endsAt = htmlspecialchars(date('Y-m-d', strtotime((string) ($sub['current_period_end'] ?: $sub['expiry_date'] ?? 'now'))), ENT_QUOTES, 'UTF-8');
            $lifecycleBanner = '<div class="alert alert-warning" style="margin-bottom:14px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;">'
                . '<span>⏳ اشتراكك اتلغى وهيفضل شغّال لحد <b dir="ltr">' . $endsAt . '</b> - بعدها مش هيتجدد.</span>'
                . '<button class="p-btn primary sm" onclick="resumeSub()">↩️ رجّع الاشتراك</button></div>';
        } elseif ($status === 'active' && !empty($sub['scheduled_plan_code'])) {
            $sp = $this->db->query('SELECT name, price FROM subscription_plans WHERE plan_code = ? LIMIT 1', [(string) $sub['scheduled_plan_code']])[0] ?? null;
            if ($sp) {
                $scheduledRenewPrice = (float) $sp['price'];
                $endsAt = htmlspecialchars(date('Y-m-d', strtotime((string) ($sub['current_period_end'] ?: 'now'))), ENT_QUOTES, 'UTF-8');
                $lifecycleBanner = '<div class="alert alert-info" style="margin-bottom:14px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;">'
                    . '<span>📅 هتفضل على باقتك الحالية لحد <b dir="ltr">' . $endsAt . '</b>، وبعدها هتتجدد على باقة <b>«' . htmlspecialchars((string) $sp['name'], ENT_QUOTES, 'UTF-8') . '»</b> بسعر <b dir="ltr">$' . htmlspecialchars((string) (float) $sp['price'], ENT_QUOTES, 'UTF-8') . '</b>.</span>'
                    . '<button class="p-btn outline sm" onclick="keepPlan()">↩️ خليني على باقتي</button></div>';
            }
        } elseif ($status === 'trialing') {
            $lifecycleBanner = '<div class="alert alert-info" style="margin-bottom:14px;">🎁 انت لسه في فترة التجربة المجانية - تقدر تشترك فعليًا في أي وقت قبل ما التجربة تخلص.</div>';
        }
        $price = htmlspecialchars((string) $sub['price'], ENT_QUOTES, 'UTF-8');
        // تصحيح جذري: بدل ما نثق في $sub['currency'] المخزّنة (ممكن تكون
        // قديمة أو اتسجّلت غلط وقت الإنشاء زي "EGP" لسعر بالدولار فعليًا)،
        // بنجيب رمز العملة الصحيح من جدول الأسعار القابل للتعديل من
        // الأدمن - ده مصدر الحقيقة الوحيد لعرض الأسعار في كل الموقع.
        $currencySymbol = htmlspecialchars(strtoupper((string) ($sub['currency'] ?? '')) . ' ', ENT_QUOTES, 'UTF-8');
        $planType = $sub['plan_type'] === 'yearly' ? $this->tr('admin.yearly') : $this->tr('admin.monthly');
        $expiryLabel = !empty($sub['expiry_date']) ? date('Y-m-d', strtotime($sub['expiry_date'])) : '-';
        $expiryTimestamp = !empty($sub['expiry_date']) ? strtotime($sub['expiry_date']) : 0;
        $daysLeft = $expiryTimestamp ? max(0, (int) ceil(($expiryTimestamp - time()) / 86400)) : null;

        $usageMeta = [
            // أسماء الأرصدة زي ما العميل بيستخدمها (كان رصيد الشات مكتوب عليه "إعدادات الشات")
            'ai' => ['label' => (current_lang() === 'ar' ? 'تحليلات السيو' : 'SEO analyses'), 'icon' => '🤖'],
            'chat' => ['label' => (current_lang() === 'ar' ? 'رسايل الشات الذكي' : 'AI chat messages'), 'icon' => '💬'],
            'review' => ['label' => (current_lang() === 'ar' ? 'ردود التقييمات' : 'Review replies'), 'icon' => '⭐'],
            'competitor' => ['label' => (current_lang() === 'ar' ? 'تحليلات المنافسين' : 'Competitor analyses'), 'icon' => '🏁'],
        ];
        $tRemainingThisMonth = $this->tr('subscription.remaining_this_month');
        $usageRows = '';
        foreach ($usageMeta as $key => $meta) {
            $u = $usage[$key] ?? ['total' => 0, 'used' => 0, 'remaining' => 0];
            $total = max(1, (int) $u['total']);
            $used = (int) $u['used'];
            $percent = min(100, round(($used / $total) * 100));
            $remaining = max(0, (int) $u['total'] - $used);
            $barColor = $percent >= 90 ? 'var(--panel-danger)' : ($percent >= 70 ? 'var(--panel-warning)' : 'var(--panel-accent)');
            // dir="ltr" على الأرقام عشان نمنع مشكلة اختلاط اتجاهات الكتابة
            // (الأرقام LTR جوه سياق عربي RTL) اللي كانت بتخلّي النص يبان
            // متراكم ومقروء بترتيب غلط.
            $usageRows .= <<<HTML
            <div class="usage-metric-box">
                <div class="usage-metric-icon">{$meta['icon']}</div>
                <div class="usage-metric-body">
                    <div class="usage-metric-label">{$meta['label']}</div>
                    <div class="usage-metric-nums" dir="ltr">{$used} <span class="p-cell-muted">/ {$u['total']}</span></div>
                    <div class="usage-bar"><div style="width:{$percent}%;background:{$barColor};"></div></div>
                    <div class="usage-metric-foot">{$remaining} {$tRemainingThisMonth}</div>
                </div>
            </div>
HTML;
        }

        $tDaysLeft = $this->tr('subscription.days_left');
        $daysLeftBadge = $daysLeft !== null
            ? ($daysLeft <= 7 ? "<span class=\"pill orange\" style=\"margin-inline-start:8px;\">{$tDaysLeft} {$daysLeft}</span>" : '')
            : '';

        // مبلغ التجديد الجاي بوضوح (العميل بيتجدد بسعره اللي اشترك بيه) + تنبيه لو الرصيد مش هيكفي
        $renewRow = '';
        if ($status === 'active' && empty($sub['cancel_at_period_end']) && (float) ($sub['price'] ?? 0) > 0) {
            $renewAr = current_lang() === 'ar';
            $bal = 0.0;
            try {
                $ownerForBal = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
                $bal = (new WalletService())->getBalance($ownerForBal);
            } catch (Throwable $e) {
            }
            $renewPrice = isset($scheduledRenewPrice) ? $scheduledRenewPrice : (float) $sub['price'];
            $short = $bal < $renewPrice;
            $renewRow = '<div class="p-kv"><span class="k">' . ($renewAr ? 'مبلغ التجديد الجاي' : 'Next renewal amount') . '</span><span class="v"><b dir="ltr">' . $currencySymbol . htmlspecialchars((string) $renewPrice, ENT_QUOTES, 'UTF-8') . '</b> '
                . ($renewAr ? '<span class="p-cell-muted">بيتخصم من محفظتك يوم</span> ' : '<span class="p-cell-muted">charged from your wallet on</span> ') . '<span dir="ltr">' . $expiryLabel . '</span>'
                . ($short ? '<div style="margin-top:4px;font-size:12.5px;color:var(--panel-warning,#d97706);">' . ($renewAr ? 'رصيد محفظتك أقل من مبلغ التجديد - اشحن قبل الميعاد، أو كلّمنا على الواتساب ونجدّدلك يدوي.' : 'Your wallet balance is below the renewal amount - top up before then, or message us on WhatsApp and we will renew it manually.') . '</div>' : '')
                . '</span></div>';
        }

        $tStatus = $this->tr('chat.col.status');
        $tNextRenewal = $this->tr('subscription.next_renewal');
        $tUpgrade = $this->tr('subscription.upgrade');
        $tCancelSub = $this->tr('subscription.cancel');
        $cancelBtn = !empty($sub['cancel_at_period_end']) ? '' : '<button class="p-btn danger" onclick="cancelSub()">' . $tCancelSub . '</button>';
        $tUsageThisMonth = $this->tr('subscription.usage_this_month');
        $tUsageResetHint = $this->tr('subscription.usage_reset_hint');
        $tInvoices = $this->tr('subscription.invoices');
        $tInvoiceNum = $this->tr('subscription.invoice_num');
        $tAmount = $this->tr('subscription.amount');
        $tDate = $this->tr('admin.col.date');
        $tLoading = $this->tr('common.loading');

        $body = <<<HTML
        {$lifecycleBanner}
        <div class="p-card sub-plan-card">
            <div class="p-card-head">
                <h3>{$planLabel}</h3>
                <span class="p-card-sub">{$planType} · {$currencySymbol}{$price}</span>
            </div>
            <div class="p-kv"><span class="k">{$tStatus}</span><span class="v"><span class="pill {$statusPillColor}">{$statusIcon} {$statusLabel}</span></span></div>
            <div class="p-kv"><span class="k">{$tNextRenewal}</span><span class="v">{$expiryLabel}{$daysLeftBadge}</span></div>
            {$renewRow}
            <div style="display:flex;gap:10px;margin-top:16px;">
                <a href="/plans" class="p-btn outline">⬆️ {$tUpgrade}</a>
                {$cancelBtn}
            </div>
            <div id="subAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
        </div>

        <div class="p-card wallet-card" style="margin-top:16px;">
            <div class="p-card-head">
                <h3>💰 {$this->tr('wallet.title')}</h3>
                <span class="p-card-sub">{$this->tr('wallet.subtitle')}</span>
            </div>
            <div class="wallet-balance-box">
                <div class="wallet-balance-label">{$this->tr('wallet.current_balance')}</div>
                <div class="wallet-balance-amount" id="walletBalance" dir="ltr">$0.00</div>
                <div id="walletReservationNote" class="p-cell-muted" aria-live="polite"></div>
            </div>
            <button class="p-btn primary btn-block" style="margin-top:14px;" onclick="ManualPay.open()">➕ {$this->tr('wallet.deposit')}</button>

            <div style="display:flex;gap:6px;margin-top:10px;">
                <input type="text" id="rechargeCardCode" class="p-select xs" style="flex:1;" placeholder="{$this->tr('wallet.card.placeholder')}" dir="ltr">
                <button class="p-btn outline xs" onclick="redeemCard()">🎫 {$this->tr('wallet.card.redeem')}</button>
            </div>
            <div id="redeemCardAlert" class="alert alert-danger" style="display:none;margin-top:8px;font-size:12px;"></div>

            <div id="walletHistoryList" style="margin-top:16px;"></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div><h3>{$tUsageThisMonth}</h3><span class="p-card-sub">{$tUsageResetHint}</span></div>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" id="usageNotifyToggle" onchange="toggleUsageNotify(this.checked)" style="width:16px;height:16px;accent-color:var(--panel-accent);cursor:pointer;">
                    🔔 نبّهني عند 50% / 75% / 90% / 100% من أي رصيد
                </label>
            </div>
            <div class="usage-metrics-grid">{$usageRows}</div>
        </div>

        <div class="p-card no-pad" style="margin-top:16px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>🧾 {$tInvoices}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="invoicesTable">
                <thead><tr><th>{$tInvoiceNum}</th><th>{$tAmount}</th><th>{$tStatus}</th><th>{$tDate}</th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5">{$tLoading}</td></tr></tbody>

            </table></div>
        </div>

        <div class="p-card" id="billingProfileCard">
            <div class="p-card-head"><h3>🧾 بيانات الفوترة الرسمية</h3><span class="p-card-sub">اختياري - تظهر على فواتيرك لو محتاج فاتورة رسمية باسم شركتك</span></div>
            <div class="p-grid cols-2">
                <div><label class="form-label" for="bp_legal_name">الاسم القانوني / اسم الشركة</label><input type="text" id="bp_legal_name" class="p-input" style="width:100%;"></div>
                <div><label class="form-label" for="bp_billing_email">إيميل استلام الفواتير</label><input type="email" id="bp_billing_email" class="p-input" style="width:100%;"></div>
                <div><label class="form-label" for="bp_address_line1">العنوان (سطر 1)</label><input type="text" id="bp_address_line1" class="p-input" style="width:100%;"></div>
                <div><label class="form-label" for="bp_address_line2">العنوان (سطر 2)</label><input type="text" id="bp_address_line2" class="p-input" style="width:100%;"></div>
                <div><label class="form-label" for="bp_city">المدينة</label><input type="text" id="bp_city" class="p-input" style="width:100%;"></div>
                <div><label class="form-label" for="bp_country">الدولة</label><input type="text" id="bp_country" class="p-input" style="width:100%;"></div>
                <div><label class="form-label" for="bp_tax_id">الرقم الضريبي / VAT ID</label><input type="text" id="bp_tax_id" class="p-input" style="width:100%;" dir="ltr"></div>
            </div>
            <div id="billingProfileAlert" class="alert alert-danger" role="alert" aria-live="polite" style="display:none;margin-top:10px;"></div>
            <button class="p-btn primary" style="margin-top:14px;" onclick="saveBillingProfile()">💾 حفظ بيانات الفوترة</button>
        </div>

        {$this->depositModalHtml()}
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    // نافذة داخل الصفحة بدل confirm()/prompt(): المتصفحات المدمجة وبعض الإضافات بتلغيهم من غير ما تعرض حاجة فالزر كان "ميّت"
    function askCancel() {
        const ar = document.documentElement.lang.startsWith('ar');
        return new Promise(function (resolve) {
            const wrap = document.createElement('div');
            wrap.setAttribute('role', 'dialog'); wrap.setAttribute('aria-modal', 'true');
            wrap.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:16px';
            const box = document.createElement('div');
            box.className = 'p-card';
            box.style.cssText = 'max-width:440px;width:100%;padding:20px';
            box.dir = ar ? 'rtl' : 'ltr';
            const h = document.createElement('h3'); h.textContent = ar ? 'إلغاء الاشتراك؟' : 'Cancel subscription?';
            const p = document.createElement('p'); p.textContent = ar ? 'اشتراكك هيفضل شغّال لحد آخر فترة دفعتها ومش هيتجدد بعدها. تقدر ترجع في قرارك قبل الميعاد ده.' : 'Your subscription stays active until the end of the period you paid for and will not renew. You can change your mind before then.';
            const ta = document.createElement('textarea'); ta.className = 'p-input'; ta.maxLength = 500; ta.rows = 3; ta.style.cssText = 'width:100%;margin:10px 0';
            ta.placeholder = ar ? 'ممكن تقولنا السبب؟ (اختياري)' : 'Reason (optional)';
            const row = document.createElement('div'); row.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap';
            const keep = document.createElement('button'); keep.type = 'button'; keep.className = 'p-btn'; keep.textContent = ar ? 'لأ، خليني' : 'Keep my plan';
            const ok = document.createElement('button'); ok.type = 'button'; ok.className = 'p-btn danger'; ok.textContent = ar ? 'أيوه، ألغي' : 'Yes, cancel';
            const done = function (v) { document.removeEventListener('keydown', onKey); wrap.remove(); resolve(v); };
            const onKey = function (e) { if (e.key === 'Escape') done(null); };
            keep.onclick = function () { done(null); };
            ok.onclick = function () { done(ta.value.trim()); };
            document.addEventListener('keydown', onKey);
            row.append(keep, ok); box.append(h, p, ta, row); wrap.append(box); document.body.append(wrap); keep.focus();
        });
    }

    window.cancelSub = async function () {
        const reason = await askCancel();
        if (reason === null) return;
        const res = await fetchJSON('/api/subscription/cancel', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ reason }) });
        if (res.success) { toast(res.message || I18N['common.deleted'], 'success'); setTimeout(function () { window.location.reload(); }, 1200); }
        else { document.getElementById('subAlert').textContent = res.error || I18N['subscription.cancel_failed']; document.getElementById('subAlert').style.display = 'block'; }
    };

    window.keepPlan = async function () {
        const res = await fetchJSON('/api/subscription/scheduled-change/cancel', { method: 'POST' });
        if (res.success) { toast(res.message || 'تم', 'success'); setTimeout(() => location.reload(), 900); } else toast(res.error || 'تعذر الإلغاء', 'error');
    };
    window.resumeSub = async function () {
        const res = await fetchJSON('/api/subscription/resume', { method: 'POST' });
        if (res.success) { toast(res.message, 'success'); setTimeout(function () { window.location.reload(); }, 900); }
        else toast(res.error || 'تعذر', 'error');
    };

    let walletPaymentInfo = {};

    async function loadWallet() {
        const res = await fetchJSON('/api/wallet/balance');
        if (res.success) {
            document.getElementById('walletBalance').textContent = '$' + Number(res.data.balance).toFixed(2);
            const held=Number(res.data.reserved||0),heldNote=document.getElementById('walletReservationNote');if(heldNote)heldNote.textContent=document.documentElement.lang.startsWith('ar')?'الرصيد المعروض متاح للإنفاق · محجوز للطلبات الجارية: $'+held.toFixed(2)+' (ليس خصمًا بعد)':'Displayed balance is spendable · Reserved for active requests: $'+held.toFixed(2)+' (not charged yet)';
            walletPaymentInfo = res.data.payment_info || {};
            if (window.ManualPay) ManualPay.setInfo(walletPaymentInfo);
        }
        loadWalletHistory();
    }

    window.redeemCard = async function () {
        const input = document.getElementById('rechargeCardCode');
        const code = input.value.trim();
        const alertBox = document.getElementById('redeemCardAlert');
        alertBox.style.display = 'none';
        if (!code) return;

        const res = await fetchJSON('/api/wallet/redeem-card', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }),
        });

        if (res.success) {
            toast(res.message || I18N['wallet.card.success'], 'success');
            input.value = '';
            loadWallet();
        } else {
            alertBox.textContent = res.error || I18N['wallet.card.failed'];
            alertBox.style.display = 'block';
        }
    };

    window.loadWalletHistory = loadWalletHistory;
    async function loadWalletHistory() {
        const res = await fetchJSON('/api/wallet/history');
        const box = document.getElementById('walletHistoryList');
        if (!res.success || !res.data.transactions || !res.data.transactions.length) {
            box.innerHTML = '';
            return;
        }
        const typeLabels = { deposit: I18N['wallet.type.deposit'], subscription_charge: I18N['wallet.type.charge'], refund: I18N['wallet.type.refund'], admin_adjustment: I18N['wallet.type.adjustment'], card_redemption: I18N['wallet.type.card_redemption'] };
        const statusPills = { pending: '<span class="pill orange">' + I18N['wallet.status.pending'] + '</span>', completed: '<span class="pill green">' + I18N['wallet.status.completed'] + '</span>', rejected: '<span class="pill red">' + I18N['wallet.status.rejected'] + '</span>' };
        box.innerHTML = `<div class="p-cell-muted" style="font-size:12px;margin-bottom:8px;">${I18N['wallet.recent_activity']}</div>` +
            res.data.transactions.slice(0, 8).map(t => `
                <div class="wallet-tx-row">
                    <span>${esc(typeLabels[t.type] || t.type)}</span>
                    <span dir="ltr" style="font-weight:700;color:${t.amount >= 0 ? 'var(--panel-success)' : 'var(--panel-text)'};">${t.amount >= 0 ? '+' : ''}${esc(t.amount)}$</span>
                    ${statusPills[t.status] || ''}
                </div>`).join('');
    }


    loadWallet();

    let invoiceLoadVersion = 0;
    async function loadInvoices() {
        const version = ++invoiceLoadVersion;
        const tbody = document.querySelector('#invoicesTable tbody');
        tbody.innerHTML = '<tr><td colspan="5">جارِ تحميل الفواتير...</td></tr>';
        let res;
        try { res = await fetchJSON('/api/subscription/invoices'); } catch (error) { res = null; }
        if (version !== invoiceLoadVersion) return;
        if (!res || !res.success || !res.data || !Array.isArray(res.data.invoices)) {
            tbody.innerHTML = '<tr><td colspan="5" role="alert">تعذر تحميل الفواتير. <button class="p-btn outline xs" onclick="retryInvoices()">إعادة المحاولة</button></td></tr>';
            return;
        }
        if (res.success && Array.isArray(res.data.invoices) && res.data.invoices.length) {
            tbody.innerHTML = res.data.invoices.map(inv => `
                <tr>
                    <td>#${esc(inv.id)}</td>
                    <td>${esc(inv.amount || '-')} ${esc(inv.currency || '')}</td>
                    <td>${esc(({paid:'مدفوعة ✓',pending:'مستنية الدفع',unpaid:'مش مدفوعة',overdue:'متأخرة',refunded:'مسترجعة',void:'ملغية',cancelled:'ملغية'})[inv.status] || inv.status || '-')}</td>
                    <td class="p-cell-muted">${formatDate(inv.created_at)}</td>
                    <td><a href="/invoice/${inv.id}" class="p-btn outline xs">${I18N['common.view']}</a></td>
                </tr>`).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="5" class="p-cell-muted text-center" style="padding:30px 0;">📭 ${I18N['subscription.no_invoices']}</td></tr>`;
        }
    }
    window.retryInvoices = loadInvoices;
    loadInvoices();

    async function loadBillingProfile() {
        const res = await fetchJSON('/api/subscription/billing-profile');
        if (!res.success || !res.data.profile) return;
        const p = res.data.profile;
        const fields = ['legal_name', 'billing_email', 'address_line1', 'address_line2', 'city', 'country', 'tax_id'];
        fields.forEach(f => {
            const el = document.getElementById('bp_' + f);
            if (el && p[f]) el.value = p[f];
        });
    }
    loadBillingProfile();

    async function loadUsageNotifyPref() {
        const res = await fetchJSON('/api/settings/notifications');
        if (res.success) {
            document.getElementById('usageNotifyToggle').checked = !!res.data.billing_usage_notifications;
        }
    }
    loadUsageNotifyPref();

    window.toggleUsageNotify = async function (checked) {
        const res = await fetchJSON('/api/settings/notifications', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ billing_usage_notifications: checked }),
        });
        if (res.success) {
            toast(checked ? 'هيتم تنبيهك عند تجاوز نسب الاستخدام' : 'تم إيقاف تنبيهات الاستخدام', 'success');
        } else {
            toast(res.error || 'تعذر حفظ التفضيل', 'error');
        }
    };

    window.saveBillingProfile = async function () {
        const alertBox = document.getElementById('billingProfileAlert');
        alertBox.style.display = 'none';

        const payload = {
            legal_name: document.getElementById('bp_legal_name').value.trim(),
            billing_email: document.getElementById('bp_billing_email').value.trim(),
            address_line1: document.getElementById('bp_address_line1').value.trim(),
            address_line2: document.getElementById('bp_address_line2').value.trim(),
            city: document.getElementById('bp_city').value.trim(),
            country: document.getElementById('bp_country').value.trim(),
            tax_id: document.getElementById('bp_tax_id').value.trim(),
        };

        const res = await fetchJSON('/api/subscription/billing-profile', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        if (res.success) {
            toast(res.message || 'تم الحفظ ✔', 'success');
        } else {
            alertBox.textContent = res.error || 'تعذر حفظ بيانات الفوترة';
            alertBox.style.display = 'block';
        }
    };
})();
JS;

        $body .= self::plansStudioBlock();
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_subscription', $this->tr('subscription.page_title'), $this->tr('subscription.page_subtitle_active'), $body, $script);
        exit;
    }

    /** التجربة المجانية + الإضافات + ابني باقتك + العملة (assets/js/plans-studio.js) */
    private static function plansStudioBlock(): string
    {
        $css = htmlspecialchars(asset_v('/assets/css/plans-studio.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/plans-studio.js'), ENT_QUOTES, 'UTF-8');
        // باقات السوشيال ميديا جنب باقات المنصة (نفس المحفظة) - #social بيوصل لها من صفحة السوشيال
        $sCss = htmlspecialchars(asset_v('/assets/css/social-packages.css'), ENT_QUOTES, 'UTF-8');
        $sIcons = htmlspecialchars(asset_v('/assets/js/sp-icons.js'), ENT_QUOTES, 'UTF-8');
        $sJs = htmlspecialchars(asset_v('/assets/js/social-packages.js'), ENT_QUOTES, 'UTF-8');
        return '<link rel="stylesheet" href="' . $css . '"><section id="plansStudio" class="ps" aria-live="polite"></section><script defer src="' . $js . '"></script>'
            . '<link rel="stylesheet" href="' . $sCss . '"><div id="social"></div><section id="socialPackages" aria-live="polite"></section><script defer src="' . $sIcons . '"></script><script defer src="' . $sJs . '"></script>';
    }

    /** تحت السعر بالدولار: بعملة العميل لو اختار عملة، وإلا بالجنيه (شرط بنك الدفع) */
    private function localPrice(float $usd): string
    {
        require_once __DIR__ . '/../Services/Payment/CurrencyService.php';
        $cur = CurrencyService::forViewer($this->user ?? null);
        $label = $cur === 'USD' ? CurrencyService::egpHint($usd) : CurrencyService::format($usd, $cur);
        if ($label === '') {
            return '';
        }
        return '<div class="p-cell-muted" style="margin:-6px 0 10px;font-size:13px">≈ ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /** GET /plans (عام - بدون تسجيل دخول) */
    public function showPlans(array $params = []): array
    {
        return $this->renderPlansPage();
    }

    private function renderPlansPage(): array
    {
        // تصحيح: بقت الباقات قابلة للتعديل من لوحة الأدمن بدل الثابت الجامد.
        $plans = SubscriptionPlan::allAsLegacyArray();
        $cardsHtml = '';
        $walletBalance = null;
        if (!$plans) $cardsHtml = '<div class="p-card"><p>الباقات غير متاحة حاليًا. تواصل مع الدعم لتأكيد السعر والحدود قبل الدفع.</p><a href="/contact">تواصل معنا</a></div>';

        foreach ($plans as $key => $plan) {
            $name = htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8');
            $priceMonthly = number_format($plan['price_monthly'], 2);
            $localMonthly = $this->localPrice((float) $plan['price_monthly']);
            $localYearly = $this->localPrice((float) $plan['price_yearly']);
            $priceYearly = number_format($plan['price_yearly'], 2);
            // تصحيح باغ عملة حقيقي: كانت مكتوبة "جنيه" (EGP) بشكل ثابت
            // بجانب أسعار مقصود بيها أصلاً تكون بالدولار ($49، $99، $299)
            // - يعني العميل كان يشوف "49 جنيه" وهو المفروض يدفع 49 دولار.
            // دلوقتي الرمز بييجي من قاعدة البيانات (قابل للتعديل من الأدمن).
            $currencySymbol = htmlspecialchars($plan['currency_symbol'] ?? '$', ENT_QUOTES, 'UTF-8');
            $f = $plan['features'];
            $keySafe = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

            $featureItems = [
                "🤖 {$f['ai_analysis']} تحليل AI شهريًا",
                "🏁 {$f['competitor_analysis']} تحليل منافسين",
                "💬 {$f['chat_credits']} رسالة شات",
                "⭐ {$f['review_credits']} رد على مراجعات",
                "🌐 حتى {$f['multiple_websites']} " . ($f['multiple_websites'] > 1 ? 'مواقع' : 'موقع'),
                $f['auto_pilot'] ? "✔ إرسال تلقائي بدون مراجعة" : "○ يتطلب موافقتك على الردود",
                $f['advanced_analytics'] ? "✔ تحليلات متقدمة" : "○ تحليلات أساسية",
            ];
            $featuresHtml = implode('', array_map(fn ($i) => '<li>' . htmlspecialchars($i, ENT_QUOTES, 'UTF-8') . '</li>', $featureItems));

            $whatsappMonthly = $this->buildWhatsAppSubscribeLink($plan['name'], $priceMonthly, 'شهري', $currencySymbol);
            $whatsappYearly = $this->buildWhatsAppSubscribeLink($plan['name'], $priceYearly, 'سنوي', $currencySymbol);
            $emailMonthly = htmlspecialchars($this->buildEmailSubscribeLink($plan['name'], $priceMonthly, 'شهري', $currencySymbol), ENT_QUOTES, 'UTF-8');
            $emailYearly = htmlspecialchars($this->buildEmailSubscribeLink($plan['name'], $priceYearly, 'سنوي', $currencySymbol), ENT_QUOTES, 'UTF-8');
            $waBtnMonthly = $whatsappMonthly === '#' ? '' : '<a href="' . htmlspecialchars($whatsappMonthly, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" class="p-btn primary btn-block">💬 فعّل عبر واتساب</a>';
            $waBtnYearly = $whatsappYearly === '#' ? '' : '<a href="' . htmlspecialchars($whatsappYearly, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" class="p-btn primary btn-block">💬 فعّل عبر واتساب</a>';

            // نسبة التوفير الحقيقية بمقارنة السعر السنوي بسعر 12 شهر
            // مدفوعين شهريًا - محسوبة من أسعار حقيقية مخزّنة في قاعدة
            // البيانات، مش رقم ثابت مخترع.
            $monthlyAnnualized = (float) $plan['price_monthly'] * 12;
            $savingsPercent = $monthlyAnnualized > 0
                ? max(0, round((1 - ((float) $plan['price_yearly'] / $monthlyAnnualized)) * 100))
                : 0;
            $savingsBadge = $savingsPercent > 0 ? "<span class=\"pill green\">وفّر {$savingsPercent}%</span>" : '';

            // زرار "اشترك بالرصيد" يظهر للمستخدمين المسجّلين بس - بيخصم
            // فورًا من رصيد المحفظة ويفعّل الاشتراك من غير أي تدخل بشري
            // أو انتظار على واتساب.
            $walletButtonsMonthly = '';
            $walletButtonsYearly = '';
            // مفيش بوابة دفع حاليًا: زرار الرصيد بيظهر بس للي عنده رصيد (الأدمن ضافه يدوي)
            if ($walletBalance === null) {
                $walletBalance = 0.0;
                try { if ($this->isAuthenticated() && class_exists('WalletService')) $walletBalance = (new WalletService())->getBalance((int) $this->user['id']); } catch (Throwable $e) { error_log('[SubscriptionController] wallet balance failed: ' . $e->getMessage()); }
            }
            if ($this->isAuthenticated() && $walletBalance > 0 && ($plan['currency'] ?? '') === 'USD') {
                foreach (['monthly','yearly'] as $cycle) {
                    $arguments = [json_encode($key,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT), json_encode($cycle), json_encode($plan['name'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT), (string)(float)$plan['price_'.$cycle], json_encode($plan['currency'])];
                    $handler=htmlspecialchars('subscribeWithWallet('.implode(',',$arguments).')',ENT_QUOTES,'UTF-8');
                    $button='<button class="p-btn success btn-block" style="margin-top:8px;" onclick="'.$handler.'">💰 اشترك بالرصيد</button>';
                    if ($cycle==='monthly') $walletButtonsMonthly=$button; else $walletButtonsYearly=$button;
                }
            }

            // الدفع بالتحويل (بنك / PayPal / InstaPay / محفظة) - الباقة بتتفعّل لوحدها أول ما الأدمن يأكد التحويل
            $transferBtn = ['monthly' => '', 'yearly' => ''];
            if (($plan['currency'] ?? '') === 'USD') {
                foreach (['monthly', 'yearly'] as $cycle) {
                    $amount = (float) $plan['price_' . $cycle];
                    $transferBtn[$cycle] = $this->isAuthenticated()
                        ? '<button type="button" class="p-btn primary btn-block" data-manual-pay data-plan="' . $keySafe . '" data-cycle="' . $cycle . '" data-amount="' . $amount . '" data-name="' . $name . '">💸 ادفع بالتحويل وفعّل</button>'
                        : '<a href="/register" class="p-btn primary btn-block">ابدأ مجانًا</a>';
                }
                $waBtnMonthly = $waBtnMonthly ? str_replace('p-btn primary btn-block"', 'p-btn outline btn-block" style="margin-top:8px;"', $waBtnMonthly) : '';
                $waBtnYearly = $waBtnYearly ? str_replace('p-btn primary btn-block"', 'p-btn outline btn-block" style="margin-top:8px;"', $waBtnYearly) : '';
            }

            $cardsHtml .= <<<HTML
            <div class="p-card">
                <div class="p-card-head"><h3>{$name}</h3></div>

                <div class="plan-cycle-block" data-cycle-block="monthly">
                    <div style="font-size:32px;font-weight:700;margin:10px 0;">{$currencySymbol}{$priceMonthly} <span style="font-size:14px;font-weight:400;">/شهر</span></div>
                    {$localMonthly}
                    <ul style="margin:0 0 16px;padding-inline-start:20px;line-height:2;">{$featuresHtml}</ul>
                    {$transferBtn['monthly']}
                    {$waBtnMonthly}
                    <a href="{$emailMonthly}" class="p-btn outline btn-block" style="margin-top:8px;">✉️ فعّل بالإيميل</a>
                    {$walletButtonsMonthly}
                </div>

                <div class="plan-cycle-block" data-cycle-block="yearly" style="display:none;">
                    <div style="font-size:32px;font-weight:700;margin:10px 0;">{$currencySymbol}{$priceYearly} <span style="font-size:14px;font-weight:400;">/سنة</span> {$savingsBadge}</div>
                    {$localYearly}
                    <ul style="margin:0 0 16px;padding-inline-start:20px;line-height:2;">{$featuresHtml}</ul>
                    {$transferBtn['yearly']}
                    {$waBtnYearly}
                    <a href="{$emailYearly}" class="p-btn outline btn-block" style="margin-top:8px;">✉️ فعّل بالإيميل</a>
                    {$walletButtonsYearly}
                </div>
            </div>
HTML;
        }

        $isAuth = $this->isAuthenticated();
        $billingToggleHtml = <<<HTML
        <div class="p-tabs" id="billingCycleTabs" role="tablist" aria-label="دورة الفوترة" style="max-width:280px;margin:0 auto 22px;">
            <button type="button" class="p-tab active" role="tab" aria-selected="true" data-cycle="monthly">شهري</button>
            <button type="button" class="p-tab" role="tab" aria-selected="false" data-cycle="yearly">سنوي</button>
        </div>
HTML;
        $activateNote = '<div class="p-card" style="margin:0 auto 18px;max-width:820px;font-size:13.5px;line-height:1.9"><b>إزاي تفعّل الباقة:</b><ol style="margin:6px 0 0;padding-inline-start:20px"><li>اختار الباقة والمدة واضغط «ادفع بالتحويل وفعّل».</li><li>حوّل المبلغ على حسابنا البنكي أو PayPal أو أي طريقة ظاهرة لك، واضغط «حوّلت».</li><li>أول ما نأكد التحويل، الباقة بتتفعّل على حسابك لوحدها وبتوصلك رسالة وفاتورة.</li></ol></div>';
        $body = $activateNote . $billingToggleHtml . '<div class="p-grid cols-3">' . $cardsHtml . '</div>';
        if ($isAuth) {
            $body .= $this->depositModalHtml();
            $body .= self::plansStudioBlock();
        }
        // تحذير المالك (إعداد داخلي) - يظهر للأدمن بس، مش للزوار العاديين
        // أو العملاء، عشان يبقى الموقع نظيف أمام الجمهور.
        $isAdminUser = $isAuth && in_array($this->user['role'] ?? '', ['super_admin', 'admin'], true);
        if (!support_whatsapp_number() && $isAdminUser) {
            $body = '<div class="alert alert-warning" style="margin-bottom:16px;">⚠️ رقم واتساب صاحب المنصة مش متظبط بعد (من لوحة الأدمن أو .env) - زراير "اشترك" مش هتشتغل لحد ما يتظبط.</div>' . $body;
        }

        $cycleToggleScript = <<<'JS'
(function () {
    const tabs = document.querySelectorAll('#billingCycleTabs .p-tab');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const cycle = tab.getAttribute('data-cycle');
            tabs.forEach(function (t) {
                const isActive = t === tab;
                t.classList.toggle('active', isActive);
                t.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            document.querySelectorAll('[data-cycle-block]').forEach(function (block) {
                block.style.display = block.getAttribute('data-cycle-block') === cycle ? '' : 'none';
            });
        });
    });
})();
JS;

        $script = '';
        if ($isAuth) {
            $script = <<<'JS'
(function () {
    const P = window.Panel;
    const fetchJSON = P.fetchJSON, toast = P.toast;

    // نافذة تأكيد الاشتراك + كود الخصم (بدل confirm العادي)
    function checkoutDialog(planKey, planType, planName, price, currency, quote) {
        return new Promise(resolve => {
            const bg = document.createElement('div');
            bg.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:grid;place-items:center;padding:16px';
            bg.innerHTML = '<div style="background:var(--panel-card-bg,#151b2b);color:var(--panel-text,#e8ecf3);border:1px solid var(--panel-border,#2a3247);border-radius:16px;max-width:420px;width:100%;padding:22px">'
                + '<h3 style="margin:0 0 6px;font-size:17px;color:var(--panel-text,#e8ecf3);opacity:1">' + (quote.unschedule ? 'إلغاء التغيير المجدول' : quote.scheduled ? 'تأكيد تغيير الباقة' : 'تأكيد الاشتراك') + '</h3>'
                + '<p style="margin:0 0 14px;font-size:13px;opacity:.8">باقة "' + P.esc(planName) + '".</p>'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:12px"><span>السعر</span><b id="coPrice" style="direction:ltr">' + P.esc(currency) + ' ' + Number(price).toFixed(2) + '</b></div>'
                + '<p style="font-size:12px;line-height:1.8">نهاية الفترة: ' + P.esc(quote.ends_at) + '<br>سعر التجديد: ' + P.esc(currency) + ' ' + Number(quote.renewal_price).toFixed(2) + '<br>' + (quote.unschedule ? '<b>هتلغي تغيير الباقة المجدول</b> وتفضل على باقتك الحالية وتتجدد بيها عادي.' : quote.scheduled ? '<b>مش هيتخصم منك أي حاجة دلوقتي.</b> هتفضل على باقتك الحالية لحد ' + P.esc(quote.effective_at) + '، وبعدها هتتجدد على الباقة دي بسعرها. تقدر تلغي التغيير قبل الميعاد ده.' : (quote.preserve_usage ? 'الاستخدام السابق يفضل محسوبًا، وبتدفع فرق السعر عن الأيام الباقية بس.' : 'تبدأ فترة مدفوعة جديدة، والحصص تتجدد شهريًا.')) + (quote.scheduled || quote.unschedule ? '' : '<br>الخصم لأول فترة فقط، ولا يشمل التجديد أو تغيير الباقة.') + '</p>'
                + '<label style="font-size:12.5px;opacity:.8">عندك كود خصم؟</label>'
                + '<div style="display:flex;gap:8px;margin:6px 0 4px"><input id="coCode" class="p-input" style="flex:1;direction:ltr;text-transform:uppercase" placeholder="CODE" autocomplete="off">'
                + '<button type="button" class="p-btn outline" id="coApply">تطبيق</button></div>'
                + '<div id="coMsg" style="font-size:12.5px;min-height:18px"></div>'
                + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px"><button type="button" class="p-btn outline" id="coCancel">إلغاء</button><button type="button" class="p-btn primary" id="coOk">' + (quote.unschedule ? 'خليني على باقتي' : quote.scheduled ? 'جدول التغيير' : 'اشترك') + '</button></div></div>';
            document.body.appendChild(bg);
            // تخفيض مجدول / إلغاء تخفيض: مفيش دفع دلوقتي فمفيش كود خصم
            if (quote.scheduled || quote.unschedule) { const cc = bg.querySelector('#coCode'); cc.parentElement.style.display = 'none'; cc.parentElement.previousElementSibling.style.display = 'none'; }
            let finalCharge = Number(price);
            let applied = null, appliedInput = '', requestVersion = 0, busy = false, closed = false;
            const msg = (t, ok) => { const m = bg.querySelector('#coMsg'); m.textContent = t; m.style.color = ok ? 'var(--panel-success,#22c55e)' : 'var(--panel-danger,#ef4444)'; };
            const close = v => { closed = true; requestVersion++; bg.remove(); resolve(v); };
            bg.querySelector('#coCancel').onclick = () => close(null);
            bg.addEventListener('click', e => { if (e.target === bg) close(null); });
            const resetCoupon = () => { finalCharge = Number(price); applied = null; appliedInput = ''; requestVersion++; bg.querySelector('#coPrice').textContent = currency + ' ' + Number(price).toFixed(2); msg('',true); };
            bg.querySelector('#coCode').oninput = resetCoupon;
            bg.querySelector('#coApply').onclick = async () => {
                if (busy) return;
                const code = bg.querySelector('#coCode').value.trim();
                if (!code) { msg('اكتب الكود الأول', false); return; }
                const version = ++requestVersion; busy = true; bg.querySelector('#coApply').disabled = true; bg.querySelector('#coOk').disabled = true;
                let r; try { r = await fetchJSON('/api/wallet/coupon/validate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: code, plan_key: planKey, plan_type: planType }) }); } catch (e) { r = {success:false,error:'تعذر التحقق من الكود. حاول مرة أخرى'}; } finally { busy = false; if (!closed) { bg.querySelector('#coApply').disabled = false; bg.querySelector('#coOk').disabled = false; } }
                if (closed || version !== requestVersion) return;
                if (r.success && (!r.data || typeof r.data.code !== 'string' || !Number.isFinite(Number(r.data.final)) || Number(r.data.final)<0 || !Number.isFinite(Number(r.data.discount)) || !Number.isFinite(Number(r.data.price)))) r={success:false,error:'تعذر مراجعة الخصم. حاول مرة أخرى'};
                if (!r.success) { finalCharge = Number(price); applied = null; bg.querySelector('#coPrice').textContent = currency + ' ' + Number(price).toFixed(2); msg(r.error || 'كود غير صالح', false); return; }
                applied = r.data.code; appliedInput = code; finalCharge = Number(r.data.final);
                bg.querySelector('#coPrice').innerHTML = '<s style="opacity:.55">' + P.esc(currency) + ' ' + Number(r.data.price).toFixed(2) + '</s> ' + P.esc(currency) + ' ' + Number(r.data.final).toFixed(2);
                msg('تم تطبيق خصم ' + r.data.label + ' (وفّرت ' + currency + ' ' + Number(r.data.discount).toFixed(2) + ')', true);
            };
            bg.querySelector('#coOk').onclick = () => {
                const typed = bg.querySelector('#coCode').value.trim();
                if (busy) return;
                if (typed && (!applied || typed !== appliedInput)) { msg('دوس "تطبيق" الأول عشان نتأكد من الكود، أو امسحه', false); return; }
                close({ coupon: applied, expected_charge: finalCharge });
            };
            bg.querySelector('#coCode').focus();
        });
    }

    window.subscribeWithWallet = async function (planKey, planType, planName, price, currency = 'USD') {
        if (window.__billingCheckoutBusy) return;
        window.__billingCheckoutBusy = true;
        try {
        const quoted = await fetchJSON('/api/wallet/coupon/validate', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({plan_key:planKey,plan_type:planType,quote_only:true})});
        if (quoted.success && (!quoted.data || !Number.isFinite(Number(quoted.data.price)) || Number(quoted.data.price)<0 || typeof quoted.data.ends_at !== 'string')) {toast('تعذر مراجعة السعر','error');return;}
        if (!quoted.success) {toast(quoted.error || 'تعذر مراجعة السعر','error');return;}
        const choice = await checkoutDialog(planKey, planType, planName, quoted.data.price, currency, quoted.data);
        if (!choice) return;

        // مفتاح idempotency فريد لكل ضغطة - بيمنع تكرار الخصم لو الطلب
        // اتبعت مرتين (دبل-كليك أو مشكلة شبكة مؤقتة).
        const idempotencyKey = (window.crypto && window.crypto.randomUUID)
            ? window.crypto.randomUUID()
            : 'idem_' + Date.now() + '_' + Math.random().toString(36).slice(2);

        const res = await fetchJSON('/api/wallet/subscribe', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plan_key: planKey, plan_type: planType, idempotency_key: idempotencyKey, coupon_code: choice.coupon || null, expected_charge: choice.expected_charge }),
        });

        if (res.success) {
            const charged = res.data && typeof res.data.charged === 'number' ? res.data.charged : null;
            const isPlanChange = res.data && res.data.is_plan_change;
            const d = res.data || {};
            const msg = d.scheduled ? `اتجدول ✔ هتفضل على باقتك لحد ${d.effective_at}، وبعدها هتتجدد على "${d.plan_name}" بـ $${d.renewal_price}`
                : d.unscheduled ? 'اتلغى التخفيض المجدول ✔ هتفضل على باقتك'
                : d.unchanged ? 'انت مشترك بالفعل في الباقة دي'
                : isPlanChange
                ? (charged > 0 ? `تم تفعيل الباقة الجديدة وخصم فرق السعر $${charged} ✔` : 'تم تفعيل الباقة الجديدة ✔')
                : 'تم تفعيل الاشتراك فورًا من رصيدك ✔';
            toast(msg, 'success');
            setTimeout(() => window.location.href = '/subscription', 1200);
        } else {
            if (res.data && res.data.shortfall) {
                const label = res.data.is_plan_change ? 'فرق السعر' : 'رصيدك';
                toast(`رصيدك مش كافي لدفع ${label} - محتاج تودّع $${res.data.shortfall} إضافية`, 'error');
            } else {
                toast(res.error || 'تعذر تفعيل الاشتراك', 'error');
            }
        }
        } catch (e) { toast('تعذر إتمام العملية. راجع الاشتراك والمحفظة قبل إعادة الدفع','error'); } finally { window.__billingCheckoutBusy = false; }
    };
})();
JS;
        }

        header('Content-Type: text/html; charset=utf-8');

        if ($isAuth) {
            echo $this->renderPanelPage('_subscription', 'الباقات', 'اختر الباقة المناسبة لشركتك', $body, $cycleToggleScript . $script);
        } else {
            echo $this->renderPublicPlansPage($body, $cycleToggleScript);
        }
        exit;
    }

    /**
     * رابط واتساب جاهز برسالة معبّأة مسبقًا بتفاصيل الباقة المطلوبة،
     * عشان العميل يقدر يتواصل ويدفع يدويًا (مفيش بوابة دفع إلكتروني
     * مفعّلة حاليًا). لما تستقبل الرسالة وتتأكد من الدفع، فعّل الاشتراك
     * له من لوحة الأدمن (/admin/subscriptions).
     */
    /** كارت المحفظة الكامل (رصيد + إيداع + شحن كرت) - متاح للعميل بغض النظر عن حالة اشتراكه */
    private function renderWalletCardHtml(): string
    {
        return <<<HTML
        <div class="p-card wallet-card" style="margin-top:16px;">
            <div class="p-card-head">
                <h3>💰 {$this->tr('wallet.title')}</h3>
                <span class="p-card-sub">{$this->tr('wallet.subtitle')}</span>
            </div>
            <div class="wallet-balance-box">
                <div class="wallet-balance-label">{$this->tr('wallet.current_balance')}</div>
                <div class="wallet-balance-amount" id="walletBalance" dir="ltr">$0.00</div>
                <div id="walletReservationNote" class="p-cell-muted" aria-live="polite"></div>
            </div>
            <button class="p-btn primary btn-block" style="margin-top:14px;" onclick="ManualPay.open()">➕ {$this->tr('wallet.deposit')}</button>

            <div style="display:flex;gap:6px;margin-top:10px;">
                <input type="text" id="rechargeCardCode" class="p-select xs" style="flex:1;" placeholder="{$this->tr('wallet.card.placeholder')}" dir="ltr">
                <button class="p-btn outline xs" onclick="redeemCard()">🎫 {$this->tr('wallet.card.redeem')}</button>
            </div>
            <div id="redeemCardAlert" class="alert alert-danger" style="display:none;margin-top:8px;font-size:12px;"></div>

            <div id="walletHistoryList" style="margin-top:16px;"></div>
        </div>

        {$this->depositModalHtml()}
HTML;
    }

    /** نافذة الدفع اليدوي (بيانات التحويل + «حوّلت») - assets/js/manual-pay.js */
    private function depositModalHtml(): string
    {
        return self::manualPayModal();
    }

    /** نفس النافذة لأي صفحة تانية (منشئ المواقع مثلًا) */
    public static function manualPayModal(): string
    {
        $js = htmlspecialchars(asset_v('/assets/js/manual-pay.js'), ENT_QUOTES, 'UTF-8');
        $css = htmlspecialchars(asset_v('/assets/css/manual-pay.css'), ENT_QUOTES, 'UTF-8');
        $ar = str_starts_with(current_lang(), 'ar');
        $title = $ar ? 'ادفع بالتحويل' : 'Pay by transfer';
        $btn = $ar ? 'حوّلت ✓' : "I've paid ✓";
        return <<<HTML
        <link rel="stylesheet" href="{$css}">
        <div class="p-modal-overlay" id="depositModal">
            <div class="p-modal mp-modal">
                <div class="p-modal-head"><h3>💸 {$title}</h3><button class="p-modal-close" onclick="P.closeModal('depositModal')">×</button></div>
                <div class="p-modal-body" id="mpBody"></div>
                <div class="p-modal-foot"><button class="p-btn primary" id="mpSubmit" onclick="ManualPay.submit()">{$btn}</button></div>
            </div>
        </div>
        <script defer src="{$js}"></script>
HTML;
    }

    /** سكريبت المحفظة بس (من غير باقي منطق صفحة الاشتراك) - للعميل اللي معندوش اشتراك نشط */
    private function buildWalletOnlyScript(): string
    {
        return <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let walletPaymentInfo = {};

    async function loadWallet() {
        const res = await fetchJSON('/api/wallet/balance');
        if (res.success) {
            document.getElementById('walletBalance').textContent = '$' + Number(res.data.balance).toFixed(2);
            const held=Number(res.data.reserved||0),heldNote=document.getElementById('walletReservationNote');if(heldNote)heldNote.textContent=document.documentElement.lang.startsWith('ar')?'الرصيد المعروض متاح للإنفاق · محجوز للطلبات الجارية: $'+held.toFixed(2)+' (ليس خصمًا بعد)':'Displayed balance is spendable · Reserved for active requests: $'+held.toFixed(2)+' (not charged yet)';
            walletPaymentInfo = res.data.payment_info || {};
            if (window.ManualPay) ManualPay.setInfo(walletPaymentInfo);
        }
        loadWalletHistory();
    }

    window.redeemCard = async function () {
        const input = document.getElementById('rechargeCardCode');
        const code = input.value.trim();
        const alertBox = document.getElementById('redeemCardAlert');
        alertBox.style.display = 'none';
        if (!code) return;

        const res = await fetchJSON('/api/wallet/redeem-card', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }),
        });

        if (res.success) {
            toast(res.message || I18N['wallet.card.success'], 'success');
            input.value = '';
            loadWallet();
        } else {
            alertBox.textContent = res.error || I18N['wallet.card.failed'];
            alertBox.style.display = 'block';
        }
    };

    window.loadWalletHistory = loadWalletHistory;
    async function loadWalletHistory() {
        const res = await fetchJSON('/api/wallet/history');
        const box = document.getElementById('walletHistoryList');
        if (!res.success || !res.data.transactions || !res.data.transactions.length) {
            box.innerHTML = '';
            return;
        }
        const typeLabels = { deposit: I18N['wallet.type.deposit'], subscription_charge: I18N['wallet.type.charge'], refund: I18N['wallet.type.refund'], admin_adjustment: I18N['wallet.type.adjustment'], card_redemption: I18N['wallet.type.card_redemption'] };
        const statusPills = { pending: '<span class="pill orange">' + I18N['wallet.status.pending'] + '</span>', completed: '<span class="pill green">' + I18N['wallet.status.completed'] + '</span>', rejected: '<span class="pill red">' + I18N['wallet.status.rejected'] + '</span>' };
        box.innerHTML = `<div class="p-cell-muted" style="font-size:12px;margin-bottom:8px;">${I18N['wallet.recent_activity']}</div>` +
            res.data.transactions.slice(0, 8).map(t => `
                <div class="wallet-tx-row">
                    <span>${esc(typeLabels[t.type] || t.type)}</span>
                    <span dir="ltr" style="font-weight:700;color:${t.amount >= 0 ? 'var(--panel-success)' : 'var(--panel-text)'};">${t.amount >= 0 ? '+' : ''}${esc(t.amount)}$</span>
                    ${statusPills[t.status] || ''}
                </div>`).join('');
    }


    loadWallet();
})();
JS;
    }

    private function buildWhatsAppSubscribeLink(string $planLabel, string $price, string $cycle, string $currencySymbol = '$'): string
    {
        if (!support_whatsapp_number()) {
            return '#';
        }

        $userEmail = $this->isAuthenticated() ? ($this->user['email'] ?? '') : '';
        $emailLine = $userEmail ? " (بريدي المسجّل: {$userEmail})" : '';

        $message = "أهلاً، عايز أفعّل باقة {$planLabel} ({$cycle}) بسعر {$currencySymbol}{$price}. ابعتولي بيانات الدفع.{$emailLine}";

        return 'https://wa.me/' . preg_replace('/\D/', '', support_whatsapp_number()) . '?text=' . rawurlencode($message);
    }

    /** نفس طلب التفعيل بالإيميل - على إيميل الشركة من إعدادات الأدمن */
    private function buildEmailSubscribeLink(string $planLabel, string $price, string $cycle, string $currencySymbol = '$'): string
    {
        $to = class_exists('PublicLayout') ? PublicLayout::company()['email'] : 'info@tourfecto.pro';
        $userEmail = $this->isAuthenticated() ? ($this->user['email'] ?? '') : '';
        $body = "أهلاً، عايز أفعّل باقة {$planLabel} ({$cycle}) بسعر {$currencySymbol}{$price}. ابعتولي بيانات الدفع." . ($userEmail ? "\nإيميل حسابي: {$userEmail}" : '');
        return 'mailto:' . $to . '?subject=' . rawurlencode("تفعيل باقة {$planLabel} ({$cycle})") . '&body=' . rawurlencode($body);
    }

    /**
     * صفحة أسعار عامة (بدون تسجيل دخول) - نفس نظام تصميم الصفحة الرئيسية
     * (مش panel layout لأن الزائر لسه معندوش حساب).
     */
    private function renderPublicPlansPage(string $body, string $script): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        // تصحيح باغ فادح: {asset_v(...)} جوه heredoc مبيتفسّرش من PHP.
        $styleCssUrl = asset_v('/assets/css/style.css');
        $panelCssUrl = asset_v('/assets/css/panel.css');

        $lang = current_lang();
        $dir = current_dir();
        $labels = [
            'ar' => ['title' => 'الباقات والأسعار', 'sub' => 'اختر الباقة المناسبة لشركتك السياحية', 'login' => 'عندك حساب بالفعل؟ سجّل دخول', 'install' => 'تثبيت التطبيق'],
            'en' => ['title' => 'Plans & Pricing', 'sub' => 'Choose the right plan for your tourism business', 'login' => 'Already have an account? Sign in', 'install' => 'Install app'],
            'fr' => ['title' => 'Forfaits et tarifs', 'sub' => 'Choisissez le forfait adapté à votre entreprise touristique', 'login' => 'Vous avez déjà un compte ? Connectez-vous', 'install' => 'Installer l’application'],
            'de' => ['title' => 'Pakete & Preise', 'sub' => 'Wählen Sie den passenden Tarif für Ihr Tourismusunternehmen', 'login' => 'Sie haben bereits ein Konto? Anmelden', 'install' => 'App installieren'],
        ][$lang] ?? ['title' => 'Plans & Pricing', 'sub' => 'Choose the right plan for your tourism business', 'login' => 'Already have an account? Sign in', 'install' => 'Install app'];
        $pageTitle = htmlspecialchars($labels['title'], ENT_QUOTES, 'UTF-8');
        $pageSub = htmlspecialchars($labels['sub'], ENT_QUOTES, 'UTF-8');
        $loginLabel = htmlspecialchars($labels['login'], ENT_QUOTES, 'UTF-8');
        $installLabel = htmlspecialchars($labels['install'], ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#F4F5F8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{$pageTitle} | {$appName}</title>
    <link rel="stylesheet" href="{$styleCssUrl}">
    <link rel="stylesheet" href="{$panelCssUrl}">
    <link rel="stylesheet" href="/assets/css/tf-theme.css">
    <script src="/assets/js/tf-theme.js?v=20261016"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>body{padding:40px 20px;max-width:1100px;margin:0 auto;} h1{text-align:center;margin-bottom:8px;} .sub{text-align:center;color:var(--gray-500,#666);margin-bottom:30px;}</style>
</head>
<body class="ap-theme">
    <h1>{$pageTitle}</h1>
    <p class="sub">{$pageSub}</p>
    {$body}
    <p style="text-align:center;margin-top:30px;"><a href="/login">{$loginLabel}</a></p>
    <script>{$script}</script>
<button id="pwaInstallBtn" class="pwa-install-fab" type="button" aria-label="{$installLabel}" title="{$installLabel}">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span>{$installLabel}</span>
</button>
<style>
.pwa-install-fab {
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: 9999;
    display: none;
    align-items: center;
    gap: 8px;
    background: var(--primary-color, #0077be);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 12px 18px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0, 119, 190, .35);
    transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
}
.pwa-install-fab:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(0, 119, 190, .45);
}
.pwa-install-fab svg { flex-shrink: 0; }
@media (max-width: 480px) {
    .pwa-install-fab span { display: none; }
    .pwa-install-fab { padding: 14px; border-radius: 50%; bottom: 18px; left: 18px; }
}
</style>
<script>
(function () {
    var btn = document.getElementById('pwaInstallBtn');
    if (!btn) return;
    var deferredPrompt = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    if (isStandalone()) return;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        btn.style.display = 'flex';
    });

    btn.addEventListener('click', function () {
        if (!deferredPrompt) return;
        btn.style.display = 'none';
        var promptEvent = deferredPrompt;
        deferredPrompt = null;
        promptEvent.prompt();
        promptEvent.userChoice.then(function () {});
    });

    window.addEventListener('appinstalled', function () {
        btn.style.display = 'none';
        deferredPrompt = null;
    });
})();
</script>
</body>
</html>
HTML;
    }

    /** GET /invoice/{id} و GET /api/subscription/invoice/{id} */
    public function showInvoice(array $params): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/invoice/' . ($params['id'] ?? '')));
            exit;
        }

        $result = $this->getInvoice($params);
        if (!($result['success'] ?? false)) {
            $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🧾</div>الفاتورة غير موجودة</div></div>';
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderPanelPage('_subscription', 'الفاتورة', '', $body, '');
            exit;
        }

        $inv = $result['data']['invoice'];
        $invId = (int) ($inv['id'] ?? 0);
        $invNumber = htmlspecialchars((string) ($inv['invoice_number'] ?? ('#' . $invId)), ENT_QUOTES, 'UTF-8');
        $amount = (float) ($inv['amount'] ?? 0);
        $currency = htmlspecialchars((string) ($inv['currency'] ?? 'USD'), ENT_QUOTES, 'UTF-8');
        $status = (string) ($inv['status'] ?? '-');
        $statusPillClass = [
            'paid' => 'green', 'pending' => 'orange', 'failed' => 'red', 'cancelled' => 'red',
            'draft' => 'gray', 'issued' => 'blue', 'partially_paid' => 'orange', 'overdue' => 'red', 'refunded' => 'purple',
        ][$status] ?? 'gray';
        $statusLabelsAr = [
            'paid' => 'مدفوعة', 'pending' => 'قيد الانتظار', 'failed' => 'فشلت', 'cancelled' => 'ملغاة',
            'draft' => 'مسودة', 'issued' => 'صادرة', 'partially_paid' => 'مدفوعة جزئيًا', 'overdue' => 'متأخرة السداد', 'refunded' => 'مستردة',
        ];
        $statusLabel = htmlspecialchars($statusLabelsAr[$status] ?? $status, ENT_QUOTES, 'UTF-8');
        $createdDate = !empty($inv['created_at']) ? date('Y-m-d', strtotime($inv['created_at'])) : '-';
        $dueDate = !empty($inv['due_date']) ? date('Y-m-d', strtotime($inv['due_date'])) : '-';
        $paidDate = !empty($inv['paid_at']) ? date('Y-m-d', strtotime($inv['paid_at'])) : null;
        $planLabel = htmlspecialchars((string) ($inv['plan_name'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $cycleLabel = ($inv['plan_type'] ?? '') === 'yearly' ? $this->tr('admin.yearly') : $this->tr('admin.monthly');
        $paymentMethod = htmlspecialchars((string) ($inv['payment_method'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $transactionId = htmlspecialchars((string) ($inv['transaction_id'] ?? '-'), ENT_QUOTES, 'UTF-8');

        $itemsRows = '';
        foreach (($inv['items_resolved'] ?? []) as $item) {
            $desc = htmlspecialchars((string) ($item['description'] ?? '-'), ENT_QUOTES, 'UTF-8');
            $itemAmount = htmlspecialchars((string) ($item['amount'] ?? '-'), ENT_QUOTES, 'UTF-8');
            $itemsRows .= "<tr><td>{$desc}</td><td class=\"text-end\" dir=\"ltr\">{$itemAmount} {$currency}</td></tr>";
        }

        $paidRow = $paidDate ? "<div class=\"p-kv\"><span class=\"k\">تاريخ الدفع</span><span class=\"v\">{$paidDate}</span></div>" : '';

        // Section 12: صف ضريبة اختياري - يظهر بس لو فعليًا محسوب ومسجّل
        // (subtotal معبّى)، مش أي قيمة افتراضية أو "0%" مخترعة.
        $taxRow = '';
        if (!empty($inv['tax_amount']) && !empty($inv['tax_type'])) {
            $taxTypeSafe = htmlspecialchars((string) $inv['tax_type'], ENT_QUOTES, 'UTF-8');
            $taxAmountSafe = htmlspecialchars((string) $inv['tax_amount'], ENT_QUOTES, 'UTF-8');
            $taxRow = "<tr><td>{$taxTypeSafe}</td><td class=\"text-end\" dir=\"ltr\">{$taxAmountSafe} {$currency}</td></tr>";
        }

        $body = <<<HTML
        <div class="p-card invoice-detail-card" id="invoicePrintArea">
            <div class="p-card-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                <div>
                    <h3>فاتورة {$invNumber}</h3>
                    <span class="p-card-sub">{$planLabel} · {$cycleLabel}</span>
                </div>
                <span class="pill {$statusPillClass}">{$statusLabel}</span>
            </div>

            <div class="p-kv"><span class="k">تاريخ الإصدار</span><span class="v">{$createdDate}</span></div>
            <div class="p-kv"><span class="k">تاريخ الاستحقاق</span><span class="v">{$dueDate}</span></div>
            {$paidRow}
            <div class="p-kv"><span class="k">طريقة الدفع</span><span class="v">{$paymentMethod}</span></div>
            <div class="p-kv"><span class="k">رقم العملية</span><span class="v" dir="ltr">{$transactionId}</span></div>

            <div class="p-table-scroll" style="margin-top:16px;"><table class="p-table">
                <thead><tr><th>البند</th><th class="text-end">المبلغ</th></tr></thead>
                <tbody>{$itemsRows}{$taxRow}</tbody>
                <tfoot><tr><td style="font-weight:700;">الإجمالي</td><td class="text-end" style="font-weight:700;" dir="ltr">{$amount} {$currency}</td></tr></tfoot>
            </table></div>

            <div style="display:flex;gap:10px;margin-top:18px;" class="no-print">
                <button class="p-btn outline" onclick="window.print()">🖨️ طباعة / حفظ كـ PDF</button>
                <a href="/subscription" class="p-btn outline">← رجوع للاشتراك</a>
            </div>
        </div>
HTML;

        $style = <<<CSS
        <style>
        @media print {
            .no-print, .p-panel-sidebar, .p-panel-topbar { display: none !important; }
            #invoicePrintArea { box-shadow: none !important; border: none !important; }
        }
        .invoice-detail-card .p-table td, .invoice-detail-card .p-table th { padding: 10px 12px; }
        .invoice-detail-card .text-end { text-align: end; }
        </style>
CSS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_subscription', 'فاتورة ' . $invNumber, '', $style . $body, '');
        exit;
    }

    /** GET /api/subscription/invoices */
    public function getInvoices(array $params = []): array
    {
        try {
            $sql = "SELECT * FROM invoices WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100";
            // عضو الفريق بيشوف فواتير الحساب الأساسي
            $invoices = $this->db->query($sql, [!empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : ($this->user['id'] ?? ($_SESSION['user_id'] ?? 0))]);
            return $this->success(['invoices' => $invoices]);
        } catch (Exception $e) {
            Logger::error('Get Invoices Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الفواتير', 500);
        }
    }

    /** GET /api/subscription/invoice/{id} */
    public function getInvoice(array $params): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            // تصحيح أمني (2026-08-09 / Phase 2): الاستعلام القديم كان بيجيب
            // الفاتورة بالـ ID بس من غير أي تحقق من صاحبها - يعني أي مستخدم
            // مسجّل دخول يقدر يشوف فاتورة أي عميل تاني لو خمّن أو جرّب
            // الأرقام بالترتيب في /invoice/{id}. ده مخالف مباشر لعزل بيانات
            // المستخدمين (كل مستخدم يشوف بياناته هو بس). الأدمن لسه يقدر
            // يشوف أي فاتورة لأغراض الدعم الفني.
            $isAdmin = in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true);
            $invoiceId = (int) ($params['id'] ?? 0);

            if ($isAdmin) {
                $sql = "SELECT * FROM invoices WHERE id = ? LIMIT 1";
                $result = $this->db->query($sql, [$invoiceId]);
            } else {
                $sql = "SELECT * FROM invoices WHERE id = ? AND user_id = ? LIMIT 1";
                $result = $this->db->query($sql, [$invoiceId, (int) $this->user['id']]);
            }

            if (empty($result)) {
                return $this->error('الفاتورة غير موجودة', 404);
            }

            $invoice = $result[0];

            // بنود الفاتورة: لو عمود items معبّى فعليًا (JSON) بنستخدمه زي
            // ما هو. لو فاضي (الحالة الشائعة حاليًا - العمود موجود بالجدول
            // لكن مفيش أي كود بيعبّيه وقت إنشاء الفاتورة)، بنبني بند واحد
            // مشتق من بيانات الفاتورة الحقيقية نفسها (اسم الباقة + نوعها)
            // بدل ما نخترع بنود وهمية أو نسيب الواجهة فاضية بلا تفسير.
            $items = [];
            if (!empty($invoice['items'])) {
                $decoded = json_decode($invoice['items'], true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }
            if (empty($items)) {
                $planLabel = $invoice['plan_name'] ?? '-';
                $cycleLabel = ($invoice['plan_type'] ?? '') === 'yearly' ? $this->tr('admin.yearly') : $this->tr('admin.monthly');
                $items = [[
                    'description' => trim($planLabel . ' - ' . $cycleLabel),
                    'amount' => $invoice['amount'] ?? 0,
                ]];
            }
            $invoice['items_resolved'] = $items;

            return $this->success(['invoice' => $invoice]);
        } catch (Exception $e) {
            Logger::error('Get Invoice Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الفاتورة', 500);
        }
    }

    /** POST /api/subscription/payment */
    /** روابط الرجوع من Stripe لازم تكون على موقعنا (منع إعادة التوجيه لموقع تاني) */
    private function sameSiteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if ($url[0] === '/' && (strlen($url) < 2 || $url[1] !== '/')) {
            return rtrim(defined('APP_URL') ? APP_URL : '', '/') . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $own = strtolower((string) parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST));
        $req = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        return ($host !== '' && ($host === $own || $host === preg_replace('/:\d+$/', '', $req))) ? $url : '';
    }

    public function processPayment(array $params = []): array
    {
        // الدفع الإلكتروني للاشتراك: العميل بيشحن رصيد محفظته ببطاقة
        // عبر Stripe Checkout (إيداع فوري بلا موافقة أدمن)، وبعدها
        // الاشتراك نفسه بيتفعّل من الرصيد عبر subscribeWithBalance()
        // (نفس مسار create() الحالي). لو Stripe مش مُفعّل، نرد بالرصيد
        // الحالي والمبلغ المطلوب عشان الواجهة توجّهه للإيداع اليدوي.
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $amount = round((float) ($this->get('amount') ?? 0), 2);
            // 🔒 المحفظة بالدولار: العملة كانت بتيجي من الطلب، فدفع 100 جنيه كان بيتحسب 100 دولار.
            $currency = 'USD';
            $successUrl = $this->sameSiteUrl((string) ($this->get('success_url') ?: ''));
            $cancelUrl = $this->sameSiteUrl((string) ($this->get('cancel_url') ?: ''));

            if ($amount < 1 || $amount > 10000) {
                return $this->error('المبلغ لازم يكون بين 1 و 10000 دولار', 422);
            }

            if (!class_exists('StripeCheckoutService')) {
                return $this->error('نظام الدفع غير متاح', 500);
            }

            $stripe = new StripeCheckoutService();
            if (!$stripe->isConfigured()) {
                $balance = 0;
                if (class_exists('WalletService')) {
                    $balance = (new WalletService())->getBalance((int) $this->user['id']);
                }
                return $this->error('بوابة الدفع الإلكتروني غير مُفعّلة حاليًا - استخدم الإيداع اليدوي', 400, [
                    'balance' => $balance,
                    'action' => '/wallet',
                ]);
            }

            $session = $stripe->createWalletTopUpSession(
                (int) $this->user['id'],
                $amount,
                $currency,
                $successUrl !== '' ? $successUrl : rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/subscription',
                $cancelUrl !== '' ? $cancelUrl : rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/wallet'
            );

            return $this->success($session, 'تم إنشاء جلسة الدفع');
        } catch (Exception $e) {
            Logger::error('Subscription Payment Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر بدء عملية الدفع: ' . CustomerError::message($e->getMessage(), 400), 422);
        }
    }
}
