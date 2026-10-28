<?php
require_once __DIR__ . '/../Services/Payment/XPayGateway.php';

/**
 * Tourfecto - XPay
 * العميل: الدفع بالكارت لشحن المحفظة (/api/xpay/*)، صفحة الرجوع (/wallet/xpay/return)
 * XPay:   الـ Webhook (/api/webhooks/xpay)
 * الأدمن: الربط والمفاتيح واختبار الاتصال (/api/admin/xpay/*) - من صفحة /admin/plans-studio
 */
class XPayController extends Controller
{
    private function base(): string
    {
        $u = defined('APP_URL') ? (string) APP_URL : (string) (getenv('APP_URL') ?: '');
        if ($u === '') {
            $u = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        return rtrim($u, '/');
    }

    private function owner(): int
    {
        $uid = (int) $this->user['id'];
        $r = $this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$uid]);
        return (int) ($r[0]['owner_user_id'] ?? 0) ?: $uid;
    }

    private function isAdmin(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    // ------------------------------------------------------------------ العميل

    /** GET /api/xpay/status */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $g = new XPayGateway();
        $c = $g->config();
        return $this->success(['enabled' => $g->isReady(), 'min_usd' => $c['min_usd'], 'max_usd' => $c['max_usd'], 'charge_currency' => $c['charge_currency'], 'egp_rate' => CurrencyService::rate('EGP'), 'test_mode' => $c['mode'] === 'test', 'csrf_token' => Csrf::token()]);
    }

    /** POST /api/xpay/topup {amount} */
    public function topup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'xpay_topup', 10, 300))) {
            return $e;
        }
        try {
            $amount = (float) $this->get('amount', 0);
            $purpose = 'wallet_topup';
            if ($this->get('purpose') === 'trial_card') {
                // تأكيد الكارت للتجربة المجانية: المبلغ من الإعدادات مش من العميل
                require_once __DIR__ . '/../Services/Subscription/PlanExtras.php';
                $x = new PlanExtras();
                if (!$x->trialEligible($this->owner())) {
                    return $this->error('التجربة المجانية متاحة مرة واحدة بس للحسابات الجديدة.', 422);
                }
                $amount = $x->trialCardAmount();
                $purpose = 'trial_card';
            }
            $s = (new XPayGateway())->createWalletTopUp($this->owner(), $amount, $this->base() . '/wallet/xpay/return', $this->base() . '/subscription#wallet', $purpose);
            return $this->success($s, 'جاري تحويلك لصفحة الدفع الآمنة...');
        } catch (InvalidArgumentException | DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('XPay topup failed', ['error' => $e->getMessage()]);
            $admin = in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
            return $this->error('الدفع بالكارت مش شغال دلوقتي، جرّب بعد شوية أو استخدم التحويل البنكي.' . ($admin ? ' (للأدمن: السبب ظاهر في صفحة ربط XPay)' : ''), 422);
        }
    }

    /** GET /wallet/xpay/return?session_id= */
    public function returnPage(array $params = []): array
    {
        $sid = (string) ($_GET['session_id'] ?? '');
        $state = 'pending';
        try {
            $r = (new XPayGateway())->confirm($sid);
            $state = $r['status'];
        } catch (Throwable $e) {
            Logger::error('XPay return confirm failed', ['error' => $e->getMessage()]);
        }
        $q = $state === 'paid' ? 'ok' : ($state === 'expired' ? 'cancelled' : 'pending');
        $trial = false;
        try {
            $m = $this->db->query("SELECT metadata FROM payment_transactions WHERE gateway='xpay' AND gateway_transaction_id=? LIMIT 1", [$sid]);
            $trial = (json_decode((string) ($m[0]['metadata'] ?? ''), true)['purpose'] ?? '') === 'trial_card';
        } catch (Throwable $e) {
        }
        header('Location: /subscription?topup=' . $q . ($trial ? '&trial=1' : '') . '#wallet', true, 302);
        exit;
    }

    /** POST /api/webhooks/xpay - بنرجّع 200 دايمًا إلا لو حصلت مشكلة عندنا (عشان XPay يعيد) */
    public function webhook(array $params = []): array
    {
        $payload = (string) file_get_contents('php://input');
        if (strlen($payload) > 200000) {
            return $this->error('too large', 413);
        }
        try {
            $r = (new XPayGateway())->handleWebhook($payload, function_exists('getallheaders') ? (getallheaders() ?: []) : []);
            return $this->success(['received' => true, 'status' => $r['status'] ?? 'ignored']);
        } catch (Throwable $e) {
            Logger::error('XPay webhook failed', ['error' => $e->getMessage()]);
            return $this->error('retry', 500);
        }
    }

    // ------------------------------------------------------------------ الأدمن

    /** GET /api/admin/xpay */
    public function adminData(array $params = []): array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        header('Cache-Control: private, no-store');
        $g = new XPayGateway();
        $recent = [];
        try {
            $recent = $this->db->query("SELECT p.id, p.user_id, u.company_name, p.amount, p.status, p.metadata, p.created_at FROM payment_transactions p LEFT JOIN users u ON u.id = p.user_id WHERE p.gateway = 'xpay' ORDER BY p.id DESC LIMIT 10");
            foreach ($recent as &$r) {
                $m = json_decode((string) $r['metadata'], true) ?: [];
                $r['charged'] = isset($m['charge_minor']) ? number_format($m['charge_minor'] / 100, 2) . ' ' . ($m['charge_currency'] ?? '') : '';
                unset($r['metadata']);
            }
            unset($r);
        } catch (Throwable $e) {
        }
        return $this->success(['config' => $g->config(), 'webhook_url' => $this->base() . '/api/webhooks/xpay', 'return_url' => $this->base() . '/wallet/xpay/return', 'example' => $g->chargeFor(50), 'recent' => $recent, 'csrf_token' => Csrf::token()]);
    }

    private function adminGuard(): ?array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        return $this->verifyCsrf() ?: $this->rateLimitGuard('user', 'xpay_admin', 20, 60);
    }

    /** POST /api/admin/xpay/save */
    public function adminSave(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $in = [];
            foreach (['enabled', 'public_key', 'secret_key', 'webhook_secret', 'charge_currency', 'min_usd', 'max_usd', 'api_base'] as $k) {
                if ($this->get($k) !== null) {
                    $in[$k] = $this->get($k);
                }
            }
            $c = (new XPayGateway())->saveConfig($in);
            $this->log('Admin updated XPay settings', ['enabled' => $c['enabled'], 'mode' => $c['mode'], 'secret_changed' => !empty($in['secret_key'])]);
            return $this->success(['config' => $c], 'اتحفظت إعدادات XPay');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/xpay/test */
    public function adminTest(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        $r = (new XPayGateway())->testConnection();
        return $this->success($r, $r['message']);
    }
}
