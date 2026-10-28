<?php

/**
 * استلام فلوس الحجوزات (منشئ المواقع ← 💳 استلام الفلوس)
 *  GET    /api/operator-payments          الإعدادات الحالية
 *  POST   /api/operator-payments          {mode, stripe_secret_key?}
 *  POST   /api/operator-payments/methods  طرق دفع محلية (PayPal / InstaPay / محفظة / بنك)
 *  DELETE /api/operator-payments/key      شيل مفتاح Stripe
 */
class OperatorPaymentsController extends Controller
{
    private function ownerId(): ?int
    {
        if (empty($this->user['id'])) {
            return null;
        }
        // عضو الفريق مايغيّرش حساب استلام الفلوس - صاحب الحساب بس
        return empty($this->user['owner_user_id']) ? (int) $this->user['id'] : null;
    }

    public function show(array $params = []): array
    {
        $uid = (int) ($this->user['id'] ?? 0);
        if ($uid <= 0) {
            return $this->error('غير مسجّل', 401);
        }
        $owner = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : $uid;
        return $this->success(OperatorPayments::settings($owner) + ['can_edit' => $this->ownerId() !== null]);
    }

    public function save(array $params = []): array
    {
        $uid = $this->ownerId();
        if ($uid === null) {
            return $this->error('صاحب الحساب بس يقدر يغيّر طريقة استلام الفلوس', 403);
        }
        if ($rate = $this->rateLimitGuard('user', 'operator_payments', 10, 60)) {
            return $rate;
        }
        $key = $this->get('stripe_secret_key');
        $r = OperatorPayments::save($uid, (string) $this->get('mode', 'request'), $key !== null ? (string) $key : null);
        if (empty($r['success'])) {
            return $this->error($r['error'] ?? 'تعذر الحفظ', 422);
        }
        $this->log('operator_payments_updated', ['mode' => $this->get('mode'), 'key_changed' => $key !== null && $key !== '']);
        return $this->success(OperatorPayments::settings($uid), 'تم الحفظ');
    }

    /** POST /api/operator-payments/methods - طرق الدفع المحلية {paypal, instapay, wallet_name, wallet_number, bank, other} */
    public function saveMethods(array $params = []): array
    {
        $uid = $this->ownerId();
        if ($uid === null) {
            return $this->error('صاحب الحساب بس يقدر يغيّر طريقة استلام الفلوس', 403);
        }
        if ($rate = $this->rateLimitGuard('user', 'operator_payments', 10, 60)) {
            return $rate;
        }
        $in = [];
        foreach (['paypal', 'instapay', 'wallet_name', 'wallet_number', 'bank', 'other'] as $k) {
            $in[$k] = (string) $this->get($k, '');
        }
        $r = OperatorPayments::saveManualMethods($uid, $in);
        if (empty($r['success'])) {
            return $this->error($r['error'] ?? 'تعذر الحفظ', 422);
        }
        $this->log('operator_payment_methods_updated', ['filled' => array_keys(array_filter($in, fn ($v) => trim($v) !== ''))]);
        return $this->success(OperatorPayments::settings($uid) + ['can_edit' => true], 'اتحفظت طرق الدفع');
    }

    /** GET /payments/stripe/connect - تسجيل دخول Stripe وربط حساب صاحب النشاط */
    public function connectStripe(array $params = []): array
    {
        $uid = $this->ownerId();
        if ($uid === null) {
            header('Location: /website-builder?stripe=owner_only');
            exit;
        }
        if (!StripeConnectOAuth::configured()) {
            header('Location: /website-builder?stripe=unavailable');
            exit;
        }
        header('Location: ' . StripeConnectOAuth::authorizeUrl(OAuthState::issue(['aud' => 'stripe_connect'], $uid)));
        exit;
    }

    /** GET /payments/stripe/callback - Stripe بيرجّع العميل هنا بعد الموافقة */
    public function stripeCallback(array $params = []): array
    {
        $uid = $this->ownerId();
        $state = (string) $this->get('state', '');
        if ($uid === null || !OAuthState::verify($state, $uid, null, 'stripe_connect')) {
            header('Location: /website-builder?stripe=failed');
            exit;
        }
        if ($this->get('error')) {
            header('Location: /website-builder?stripe=cancelled');
            exit;
        }
        $t = (new StripeConnectOAuth())->exchange((string) $this->get('code', ''));
        if (empty($t['ok'])) {
            Logger::warning('Stripe Connect exchange failed', ['user_id' => $uid, 'error' => $t['error'] ?? '']);
            header('Location: /website-builder?stripe=failed');
            exit;
        }
        $r = OperatorPayments::saveConnected($uid, $t['access_token'], $t['account_id'], (bool) $t['livemode']);
        $this->log('operator_payments_stripe_connected', ['account' => substr($t['account_id'], -4)]);
        header('Location: /website-builder?stripe=' . (!empty($r['success']) ? 'connected' : 'failed'));
        exit;
    }

    public function removeKey(array $params = []): array
    {
        $uid = $this->ownerId();
        if ($uid === null) {
            return $this->error('صاحب الحساب بس يقدر يغيّر طريقة استلام الفلوس', 403);
        }
        OperatorPayments::removeKey($uid);
        return $this->success(OperatorPayments::settings($uid), 'اتشال مفتاح Stripe - الحجوزات بقت طلبات حجز من غير دفع أونلاين');
    }
}
