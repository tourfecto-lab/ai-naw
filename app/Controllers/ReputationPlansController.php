<?php

/**
 * Stand-alone reputation plans: the customer's plans page and API (wallet purchase). Everything is decided by the admin
 * in Admin > Reputation limits; nothing is on sale until the admin turns it on. JSON errors never use 404 (the production
 * web server replaces it with an HTML page).
 */
class ReputationPlansController extends Controller
{
    private function owner(): int
    {
        $uid = (int) ($this->user['id'] ?? 0);
        return (int) ($this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$uid])[0]['owner_user_id'] ?? 0) ?: $uid;
    }

    /** GET /api/reputation/plans */
    public function listApi(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            ReputationPlansRenewJob::ensureScheduled();
            $cfg = ReputationPlans::config();
            $owner = $this->owner();
            $sub = ReputationPlans::active($owner);
            $plans = [];
            foreach (ReputationPlans::KEYS as $k) {
                $p = $cfg['plans'][$k];
                if (empty($p['for_sale']) && !($sub && $sub['plan_key'] === $k)) {
                    continue;
                }
                $plans[] = ['key' => $k, 'name' => $p['name'], 'price_monthly' => $p['price_monthly'], 'price_yearly' => $p['price_yearly'], 'for_sale' => (bool) $p['for_sale'], 'limits' => $p['limits']];
            }
            return $this->success([
                'ready' => ReputationPlans::ready(),
                'selling' => (bool) $cfg['enabled'],
                'plans' => $plans,
                'is_owner' => $owner === (int) $this->user['id'],
                'subscription' => $sub ? ['plan' => $sub['plan_key'], 'cycle' => $sub['cycle'], 'price_paid' => (float) $sub['price_paid'], 'period_end' => $sub['period_end'], 'auto_renew' => (bool) $sub['auto_renew'], 'pending_plan' => $sub['pending_plan'], 'pending_cycle' => $sub['pending_cycle']] : null,
                'balance' => round((new WalletService())->getBalance($owner), 2),
                'labels' => ReputationLimits::LABELS,
                'summary' => ReputationLimits::summary((int) $this->user['id']),
            ]);
        } catch (Throwable $e) {
            Logger::error('Reputation plans list failed', ['message' => $e->getMessage()]);
            return $this->error('Could not load the plans', 500);
        }
    }

    /** POST /api/reputation/plans/subscribe  {plan, cycle, request_key} */
    public function subscribe(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        if ($rate = $this->rateLimitGuard('user', 'reputation_plan_buy', 10, 600)) {
            return $rate;
        }
        try {
            $res = ReputationPlans::subscribe((int) $this->user['id'], (string) $this->get('plan', ''), (string) $this->get('cycle', ''), (string) $this->get('request_key', ''));
            return $this->success($res, $res['action'] === 'scheduled' ? 'Your plan will change at the next renewal' : 'Done');
        } catch (DomainException $e) {
            $d = json_decode($e->getMessage(), true) ?: [];
            return $this->error('Your wallet balance is not enough: you need $' . number_format((float) ($d['required'] ?? 0), 2) . ' and have $' . number_format((float) ($d['balance'] ?? 0), 2) . '.', 402, $d);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Reputation plan purchase failed', ['message' => $e->getMessage()]);
            return $this->error('Could not complete the purchase. You were not charged twice: try again.', 500);
        }
    }

    /** POST /api/reputation/plans/auto-renew  {on} */
    public function autoRenew(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        if ($this->owner() !== (int) $this->user['id']) {
            return $this->error('Only the account owner can change the plan', 403);
        }
        ReputationPlans::setAutoRenew((int) $this->user['id'], filter_var($this->get('on', true), FILTER_VALIDATE_BOOLEAN));
        return $this->success([], 'Saved');
    }

    /** GET /reputation/plans */
    public function page(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/reputation/plans'));
            exit;
        }
        $body = '<div id="rpRoot"><p>Loading...</p></div>';
        $script = <<<'JS'
(function () {
    const P = window.Panel, esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const root = document.getElementById('rpRoot');
    const money = n => '$' + Number(n).toFixed(Number(n) % 1 ? 2 : 0);
    function limitLine(labels, k, v) {
        if (typeof v === 'boolean') return (v ? '✔ ' : '✖ ') + labels[k];
        if (v === 0) return '✖ ' + labels[k];
        return '✔ ' + labels[k] + ': ' + (v < 0 ? 'Unlimited' : v);
    }
    async function load() {
        const r = await fetchJSON('/api/reputation/plans').catch(() => ({ success: false }));
        if (!r.success) { root.innerHTML = '<div class="p-card">' + esc(r.error || 'Could not load the plans') + '</div>'; return; }
        const d = r.data;
        if (!d.ready) { root.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🛠️</div>Plans are not available yet: a database update is pending. Contact support.</div></div>'; return; }
        const sub = d.subscription;
        let cur = '';
        if (sub) {
            const name = (d.plans.find(p => p.key === sub.plan) || {}).name || sub.plan;
            cur = '<div class="p-card" style="margin-bottom:14px"><div class="p-card-head"><h3>Your plan: ' + esc(name) + ' (' + esc(sub.cycle) + ')</h3></div>'
                + '<p style="margin:0 0 8px">Paid ' + money(sub.price_paid) + '. Active until <b>' + esc(sub.period_end) + ' UTC</b>. '
                + (sub.auto_renew ? 'It renews automatically from your wallet at the price in force on that day.' : 'Auto-renew is off: it ends on that date.') + '</p>'
                + (sub.pending_plan ? '<p class="p-cell-muted">Scheduled for the next renewal: ' + esc(sub.pending_plan) + ' (' + esc(sub.pending_cycle) + ').</p>' : '')
                + (d.is_owner ? '<button class="p-btn outline xs" id="rpAuto">' + (sub.auto_renew ? 'Turn off auto-renew' : 'Turn on auto-renew') + '</button>' : '') + '</div>';
        }
        if (!d.selling && !sub) { root.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">📦</div>Stand-alone reputation plans are not on sale right now. Your platform plan already includes reputation tools.</div></div>'; return; }
        const cards = d.plans.map(p => '<div class="p-card" style="flex:1;min-width:230px"><div class="p-card-head"><h3>' + esc(p.name) + '</h3></div>'
            + '<div style="font-size:26px;font-weight:800">' + money(p.price_monthly) + '<span class="p-cell-muted" style="font-size:13px"> / month</span></div>'
            + '<div class="p-cell-muted" style="margin-bottom:8px">or ' + money(p.price_yearly) + ' / year</div>'
            + '<ul style="padding-inline-start:18px;margin:0 0 10px">' + Object.keys(p.limits).map(k => '<li>' + esc(limitLine(d.labels, k, p.limits[k])) + '</li>').join('') + '</ul>'
            + (d.is_owner && p.for_sale && d.selling ? '<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="p-btn primary xs" data-buy="' + p.key + '" data-cycle="monthly">Pay monthly</button><button class="p-btn outline xs" data-buy="' + p.key + '" data-cycle="yearly">Pay yearly</button></div>' : (p.for_sale ? '' : '<span class="p-cell-muted">No longer on sale</span>')) + '</div>').join('');
        root.innerHTML = cur + '<p class="p-cell-muted">Wallet balance: <b>' + money(d.balance) + '</b>. Plans are paid from your wallet; if you also have a platform plan you get the more generous limit of the two, never both added. AI replies are not part of these plans: they use your review credits or wallet usage prices as before.</p>'
            + '<div style="display:flex;gap:14px;flex-wrap:wrap">' + cards + '</div>';
        const auto = document.getElementById('rpAuto');
        if (auto) auto.onclick = async () => { const res = await fetchJSON('/api/reputation/plans/auto-renew', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ on: !sub.auto_renew }) }).catch(() => ({})); toast(res.success ? 'Saved' : (res.error || 'Could not save'), res.success ? 'success' : 'error'); load(); };
        root.querySelectorAll('[data-buy]').forEach(b => b.onclick = async () => {
            if (!confirm('Charge this plan from your wallet now?')) return;
            b.disabled = true;
            const key = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random());
            const res = await fetchJSON('/api/reputation/plans/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ plan: b.dataset.buy, cycle: b.dataset.cycle, request_key: key }) }).catch(() => ({ success: false, error: 'Unexpected answer from the server. Check your wallet before trying again.' }));
            toast(res.success ? (res.message || 'Done') : (res.error || 'Could not complete the purchase'), res.success ? 'success' : 'error');
            load();
        });
    }
    load();
})();
JS;
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', 'Reputation plans', 'Plans for the reputation tools, paid from your wallet', $body, $script);
        exit;
    }
}
