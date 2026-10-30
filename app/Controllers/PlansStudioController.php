<?php
require_once __DIR__ . '/../Services/Subscription/PlanExtras.php';
require_once __DIR__ . '/../Services/Payment/CurrencyService.php';

/**
 * Tourfecto - الباقات المرنة + العملات
 * العميل: التجربة المجانية، الإضافات، ابني باقتك، اختيار العملة
 * الأدمن: الباقات المقترحة، أسعار الإضافات والخدمات، أسعار الصرف
 */
class PlansStudioController extends Controller
{
    private function isAdmin(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    private function ar(): bool
    {
        // نفس لغة الصفحة: الكوكي لوحده كان بيرجّع عربي لزائر جديد (الموقع افتراضيًا إنجليزي) ولزوار الفرنساوي والألماني
        if (function_exists('current_lang')) {
            return current_lang() === 'ar';
        }
        $l = strtolower((string) ($_COOKIE['tf_lang'] ?? $this->user['language'] ?? 'ar'));
        return !str_starts_with($l, 'en');
    }

    /** أعضاء الفريق بيشتروا على حساب صاحب الشركة (زي PlanUsage) */
    private function owner(): int
    {
        $uid = (int) $this->user['id'];
        $r = $this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$uid]);
        $owner = (int) ($r[0]['owner_user_id'] ?? 0);
        return $owner > 0 ? $owner : $uid;
    }

    private function money(float $usd, string $cur): array
    {
        return ['usd' => round($usd, 2), 'local' => CurrencyService::fromUsd($usd, $cur), 'label' => CurrencyService::format($usd, $cur, $this->ar()), 'usd_label' => CurrencyService::format($usd, 'USD'), 'egp' => $cur === 'USD' ? CurrencyService::egpHint($usd, $this->ar()) : ''];
    }

    private function fail(Throwable $e): array
    {
        if ($e instanceof DomainException) {
            $d = json_decode($e->getMessage(), true) ?: [];
            $cur = CurrencyService::forViewer($this->user);
            $msg = $d['error'] ?? 'رصيد محفظتك مش كفاية.';
            if (isset($d['shortfall']) && $d['shortfall'] > 0) {
                $msg .= ' ناقص ' . CurrencyService::format((float) $d['shortfall'], $cur, $this->ar()) . ' - اشحن المحفظة وكمّل.';
            }
            return $this->error($msg, 402, ['action' => '/subscription#wallet'] + $d);
        }
        if ($e instanceof InvalidArgumentException) {
            return $this->error($e->getMessage(), 422);
        }
        Logger::error('Plans studio action failed', ['error' => $e->getMessage()]);
        return $this->error('حصلت مشكلة، جرّب تاني أو كلّم الدعم.', 500);
    }

    // ------------------------------------------------------------------ عام

    /** POST /api/currency - اختيار عملة العرض */
    /** GET /api/currency - زر العملة في كل صفحات الموقع ولوحة العميل */
    public function currencies(array $params = []): array
    {
        header('Cache-Control: private, max-age=300');
        $ar = $this->ar();
        return $this->success(['current' => CurrencyService::forViewer($this->user ?? null), 'list' => CurrencyService::publicList($ar)]);
    }

    public function setCurrency(array $params = []): array
    {
        $code = strtoupper((string) $this->get('code', ''));
        if (!CurrencyService::valid($code)) {
            return $this->error('عملة غير مدعومة', 422);
        }
        setcookie(CurrencyService::COOKIE, $code, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax']);
        $_COOKIE[CurrencyService::COOKIE] = $code;
        return $this->success(['currency' => $code]);
    }

    // ------------------------------------------------------------------ العميل

    /** GET /api/plans/overview */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        header('Cache-Control: private, no-store');
        try {
            $uid = $this->owner();
            $x = new PlanExtras();
            $cfg = $x->config();
            $cur = CurrencyService::forViewer($this->user);
            $ar = $this->ar();
            $plans = [];
            foreach (BillingPlanCatalog::read() as $key => $p) {
                $plans[] = ['key' => $key, 'name' => (string) $p['name'], 'monthly' => $this->money((float) $p['price_monthly'], $cur), 'yearly' => $this->money((float) $p['price_yearly'], $cur), 'features' => $p['features']];
            }
            $sub = Subscription::activeSubscriptionRow($uid) ?: null;
            $current = null;
            if ($sub) {
                $current = [
                    'id' => (int) $sub['id'], 'plan' => (string) ($sub['plan_name'] ?? ''), 'cycle' => (string) ($sub['plan_type'] ?? ''),
                    'status' => (string) ($sub['lifecycle_status'] ?? $sub['status'] ?? ''), 'period_end' => (string) ($sub['current_period_end'] ?? ''),
                    'trial_ends_at' => (string) ($sub['trial_ends_at'] ?? ''), 'price' => $this->money((float) ($sub['price'] ?? 0), $cur),
                    'custom' => $x->customFor((int) $sub['id']), 'extras' => $x->activeExtras((int) $sub['id']),
                    'auto_renew' => empty($sub['cancel_at_period_end']),
                    // التجديد بعد التجربة بيتخصم من المحفظة بس: من غير رصيد كفاية العميل لازم يعرف إنه هيفعّل الباقة معانا
                    'after_trial_covered' => ($sub['status'] ?? '') === 'trialing' ? (function () use ($sub, $uid) { $p = BillingPlanCatalog::read()[preg_replace('/_(monthly|yearly)$/', '', (string) $sub['plan_name'])] ?? null; return $p && (new WalletService())->getBalance($uid) >= (float) $p['price_monthly']; })() : null,
                    'after_trial' => ($sub['status'] ?? '') === 'trialing' && isset($plans) ? (function () use ($sub, $cur) { $p = BillingPlanCatalog::read()[preg_replace('/_(monthly|yearly)$/', '', (string) $sub['plan_name'])] ?? null; return $p ? $this->money((float) $p['price_monthly'], $cur) : null; })() : null,
                ];
            }
            $packs = [];
            foreach ($cfg['packs'] as $k => $p) {
                if (!$p['enabled'] || !$cfg['services'][$p['service']]['enabled']) {
                    continue;
                }
                $s = $cfg['services'][$p['service']];
                $packs[] = ['key' => $k, 'icon' => $p['icon'], 'qty' => $p['qty'], 'service' => $p['service'], 'label' => $ar ? $s['ar'] : $s['en'], 'price' => $this->money((float) $p['price'], $cur)];
            }
            $services = [];
            foreach ($cfg['services'] as $k => $s) {
                if ($s['enabled']) {
                    $services[] = ['key' => $k, 'label' => $ar ? $s['ar'] : $s['en'], 'unit' => $s['unit'], 'step' => $s['step'], 'max' => $s['max'], 'local_rate' => CurrencyService::rate($cur)];
                }
            }
            $balance = (new WalletService())->getBalance($uid);
            return $this->success([
                'currency' => $cur, 'currencies' => CurrencyService::publicList($ar), 'rate' => CurrencyService::rate($cur),
                'plans' => $plans, 'current' => $current, 'trial' => ['eligible' => ($trialOk = $x->trialEligible($uid)), 'blocked' => $trialOk && ($br = $x->trialBlockedReason($uid)) ? TrialGuard::reasonText($br) : null, 'days' => $cfg['trial_days'], 'plan' => $cfg['trial_plan'], 'needs_card' => $x->trialNeedsCard($uid), 'card_mode' => $cfg['trial_card_mode'], 'card_usd' => $x->trialCardAmount(), 'card_money' => $this->money($x->trialCardAmount(), $cur), 'caps' => $cfg['trial_caps']],
                'packs' => $packs, 'builder' => ['enabled' => $cfg['builder_enabled'], 'services' => $services],
                'wallet' => $this->money($balance, $cur), 'csrf_token' => Csrf::token(),
            ]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/plans/trial */
    public function trial(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_trial', 5, 3600))) {
            return $e;
        }
        try {
            $r = (new PlanExtras())->startTrial($this->owner());
            return $this->success($r, 'التجربة المجانية بدأت 🎉');
        } catch (DomainException $e) {
            $d = json_decode($e->getMessage(), true) ?: [];
            if (($d['error'] ?? '') === 'card_required') {
                require_once __DIR__ . '/../Services/Payment/XPayGateway.php';
                $ready = (new XPayGateway())->isReady();
                return $this->error($ready ? 'التجربة المجانية بتبدأ بعد تأكيد كارتك.' : 'التجربة المجانية محتاجة تأكيد كارت، والدفع بالكارت مش متاح دلوقتي. كلّمنا على واتساب نفعّلهالك.', 402, ['card_required' => true, 'card_ready' => $ready, 'card_usd' => (float) ($d['card_usd'] ?? 1)]);
            }
            return $this->fail($e);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/plans/trial/renewal {cancel: bool} - إلغاء/رجوع الاشتراك التلقائي بعد التجربة */
    public function trialRenewal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_trial_renewal', 10, 3600))) {
            return $e;
        }
        $cancel = (bool) $this->get('cancel', true);
        if (!(new PlanExtras())->cancelTrialRenewal($this->owner(), $cancel)) {
            return $this->error('مفيش تجربة شغالة دلوقتي.', 422);
        }
        return $this->success(['cancelled' => $cancel], $cancel ? 'اتلغى التجديد: التجربة هتكمل لآخرها ومفيش خصم، ورصيدك يفضل في محفظتك.' : 'رجعنا التجديد: الاشتراك هيتخصم تلقائي من محفظتك بعد التجربة.');
    }

    /** POST /api/plans/addon */
    public function addon(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_addon', 20, 300))) {
            return $e;
        }
        try {
            $key = $this->get('request_key');
            $r = (new PlanExtras())->buyPack($this->owner(), (string) $this->get('pack', ''), is_string($key) && strlen($key) >= 8 ? $key : null);
            return $this->success($r, 'الإضافة اتضافت لباقتك ✅');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    private function extrasInput(): array
    {
        $in = $this->get('extras', []);
        $out = [];
        if (is_array($in)) {
            foreach ($in as $k => $v) {
                if (is_string($k) && is_numeric($v)) {
                    $out[$k] = (int) $v;
                }
            }
        }
        return $out;
    }

    /** POST /api/plans/custom/quote */
    public function customQuote(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $q = (new PlanExtras())->quote((string) $this->get('base', ''), (string) $this->get('cycle', 'monthly'), $this->extrasInput());
            $cur = CurrencyService::forViewer($this->user);
            $q['base_money'] = $this->money($q['base_price'], $cur);
            $q['extras_money'] = $this->money($q['extras_price'], $cur);
            $q['total_money'] = $this->money($q['total'], $cur);
            return $this->success($q);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/plans/custom/buy */
    public function customBuy(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_custom', 10, 600))) {
            return $e;
        }
        try {
            $key = $this->get('request_key');
            $r = (new PlanExtras())->buyCustom($this->owner(), (string) $this->get('base', ''), (string) $this->get('cycle', 'monthly'), $this->extrasInput(), is_string($key) ? $key : null);
            return $this->success($r, 'باقتك المخصصة اشتغلت ✅');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    // ------------------------------------------------------------------ الأدمن

    /** GET /admin/plans-studio */
    public function adminPage(array $params = []): array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        $css = htmlspecialchars(asset_v('/assets/css/plans-studio.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/plans-studio-admin.js'), ENT_QUOTES, 'UTF-8');
        $xjs = htmlspecialchars(asset_v('/assets/js/xpay-admin.js'), ENT_QUOTES, 'UTF-8');
        $sjs = htmlspecialchars(asset_v('/assets/js/social-packages-admin.js'), ENT_QUOTES, 'UTF-8');
        // الصفحة كانت 8500 بيكسل: الأقسام التلاتة بقوا تبويبات
        $tjs = htmlspecialchars(asset_v('/assets/js/admin-section-tabs.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><div data-section-tabs="plans" data-section-selector=":scope > section" data-section-min="2"><section id="plansAdmin" class="ps" dir="rtl" data-st-title="الباقات والإضافات"><p>جاري التحميل…</p></section><section id="xpayAdmin" class="ps ps-box ps-adm" dir="rtl" data-st-title="الدفع والعملات (XPay)"><p>جاري التحميل…</p></section><section id="socialPkgAdmin" class="ps" dir="rtl" data-st-title="باقات السوشيال"><p>جاري التحميل…</p></section></div><script src="' . $js . '"></script><script src="' . $xjs . '"></script><script src="' . $sjs . '"></script><script src="' . $tjs . '"></script>';
        echo $this->renderPanelPage('plans-studio', 'الباقات والعملات والدفع', 'ربط XPay، باقات جاهزة، إضافات حسب الطلب، ابني باقتك، باقات السوشيال، وأسعار الصرف', $body, 'window.XPayAdmin&&window.XPayAdmin.init(window.Panel);window.PlansAdmin&&window.PlansAdmin.init(window.Panel);window.SocialPackagesAdmin&&window.SocialPackagesAdmin.init(window.Panel);');
        exit;
    }

    /** GET /api/admin/plans-studio */
    public function adminData(array $params = []): array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        header('Cache-Control: private, no-store');
        $x = new PlanExtras();
        try {
            $x->autoApplyPresetIfLegacy();
        } catch (Throwable $e) {
            Logger::error('Auto preset failed', ['error' => $e->getMessage()]);
        }
        return $this->success(['config' => $x->config(), 'preset' => PlanExtras::PRESET, 'trial_labels' => PlanExtras::TRIAL_LABELS, 'plans' => BillingPlanCatalog::read(), 'rates' => CurrencyService::rates(), 'currencies' => CurrencyService::CURRENCIES, 'auto_local' => (new SystemSettingsService())->get('fx_auto_local', '0') === '1', 'fx_auto' => CurrencyService::autoConfig(), 'csrf_token' => Csrf::token()]);
    }

    private function adminGuard(): ?array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        return $this->verifyCsrf() ?: $this->rateLimitGuard('user', 'plans_studio_admin', 30, 60);
    }

    /** POST /api/admin/plans-studio/config */
    public function adminSaveConfig(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        $in = $this->get('config', []);
        return $this->success(['config' => (new PlanExtras())->saveConfig(is_array($in) ? $in : [])], 'اتحفظ');
    }

    /** POST /api/admin/plans-studio/grant-trial {email} - تجربة مجانية لعميل من غير كارت */
    public function adminGrantTrial(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        $email = strtolower(trim((string) $this->get('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('اكتب إيميل العميل صح', 422);
        }
        $u = $this->db->query('SELECT id, owner_user_id, company_name FROM users WHERE LOWER(email) = ? LIMIT 1', [$email]);
        if (!$u) {
            return $this->error('مفيش حساب بالإيميل ده', 404);
        }
        $uid = (int) ($u[0]['owner_user_id'] ?: $u[0]['id']);
        try {
            $r = (new PlanExtras())->startTrial($uid, true);
            $this->log('Admin granted free trial without card', ['user_id' => $uid, 'days' => $r['days']]);
            return $this->success($r + ['company' => (string) $u[0]['company_name']], 'اتفعّلت التجربة المجانية لـ ' . ($u[0]['company_name'] ?: $email) . ' لحد ' . substr($r['ends_at'], 0, 10));
        } catch (InvalidArgumentException $e) {
            return $this->error('العميل ده عنده اشتراك أو تجربة شغالة بالفعل.', 422);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/admin/plans-studio/preset */
    public function adminPreset(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $done = (new PlanExtras())->applyPreset();
            $this->log('Admin applied recommended plans', ['plans' => $done]);
            return $this->success(['applied' => $done, 'plans' => BillingPlanCatalog::read()], 'اتطبقت الباقات المقترحة');
        } catch (Throwable $e) {
            Logger::error('Preset failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نطبق الباقات: ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/admin/currency/rates */
    public function adminSaveRates(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $in = $this->get('rates', []);
            if ($this->get('auto_local') !== null) {
                (new SystemSettingsService())->set('fx_auto_local', $this->get('auto_local') ? '1' : '0');
            }
            return $this->success(['rates' => CurrencyService::saveRates(is_array($in) ? $in : [])], 'اتحفظت أسعار الصرف');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/currency/refresh - تحديث دلوقتي من البنك (أو السوق) */
    public function adminRefreshRates(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $r = CurrencyService::autoRefresh(true);
            if (!empty($r['error'])) {
                return $this->error($r['error'], 502, ['fx_auto' => CurrencyService::autoConfig()]);
            }
            $src = ($r['source'] ?? '') === 'cbe' ? 'البنك المركزي المصري' : 'سعر السوق';
            return $this->success(['updated' => $r['updated'], 'rates' => CurrencyService::rates(), 'fx_auto' => CurrencyService::autoConfig()], $r['updated'] ? 'اتحدّثت من ' . $src . ': ' . implode('، ', $r['updated']) : 'كل العملات مقفولة على سعر يدوي');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }
    }

    /** POST /api/admin/currency/auto - الربط التلقائي بسعر البنك */
    public function adminSaveAuto(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        $in = $this->get('fx_auto', []);
        $c = CurrencyService::saveAutoConfig(is_array($in) ? $in : []);
        $r = $c['enabled'] ? CurrencyService::autoRefresh(true) : null;
        return $this->success(['fx_auto' => CurrencyService::autoConfig(), 'rates' => CurrencyService::rates(), 'refresh' => $r], $c['enabled'] ? 'الجنيه بقى مربوط بسعر البنك ويتحدّث كل ' . $c['every'] . ' دقيقة' : 'اتقفل الربط التلقائي');
    }
}
