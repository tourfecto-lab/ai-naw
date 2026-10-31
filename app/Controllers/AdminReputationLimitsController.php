<?php

/**
 * Admin: reputation limits per platform plan (review requests per month, connected locations, team assignment,
 * CSV import), the audit trail, and this month's consumption per account. Prices and AI reply credits stay in
 * Plans Studio; nothing here changes what a customer pays.
 */
class AdminReputationLimitsController extends Controller
{
    /** GET /admin/reputation-limits */
    public function page(array $params = []): array
    {
        $js = htmlspecialchars(asset_v('/assets/js/admin-reputation-limits.js'), ENT_QUOTES, 'UTF-8');
        $body = '<section id="rep-limits-root"><p>Loading...</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('reputation-limits', 'Reputation limits', 'Limits per plan, change history and monthly usage', $body);
        exit;
    }

    /** GET /api/admin/reputation-limits */
    public function data(array $params = []): array
    {
        try {
            $audit = ReputationLimits::auditLog(50);
            $ids = array_values(array_unique(array_map('intval', array_column($audit, 'actor_id'))));
            $names = [];
            if ($ids) {
                foreach ($this->db->query('SELECT id, email FROM users WHERE id IN (' . implode(',', $ids) . ')') as $u) {
                    $names[(int) $u['id']] = $u['email'];
                }
            }
            foreach ($audit as &$a) {
                $a['actor'] = $names[(int) ($a['actor_id'] ?? 0)] ?? ('#' . (int) ($a['actor_id'] ?? 0));
            }
            return $this->success([
                'tiers' => ReputationLimits::TIERS,
                'number_keys' => ReputationLimits::NUMBER_KEYS,
                'bool_keys' => ReputationLimits::BOOL_KEYS,
                'labels' => ReputationLimits::LABELS,
                'config' => ReputationLimits::config(),
                'sources' => ReviewSources::view(),
                'overrides' => ReputationLimits::overridesView(),
                'standalone' => ReputationPlans::adminView(),
                'audit' => $audit,
                'usage' => ReputationLimits::usageReport(30),
                'csrf_token' => class_exists('Csrf') ? Csrf::token() : '',
            ]);
        } catch (Throwable $e) {
            Logger::error('Reputation limits data failed', ['message' => $e->getMessage()]);
            return $this->error('Could not load the limits', 500);
        }
    }

    /** POST /api/admin/reputation-limits/override  {email, values: {key: number|bool|null}}  (null removes the key) */
    public function override(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $email = trim((string) $this->get('email', ''));
        $values = $this->get('values');
        if ($email === '' || !is_array($values) || !$values) {
            return $this->error('Enter the customer e-mail and at least one value', 422);
        }
        $user = $this->db->query('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])[0] ?? null;
        if (!$user) {
            return $this->error('No customer with this e-mail', 422); // never 404 on a JSON API: the production web server replaces it with an HTML page
        }
        try {
            $res = ReputationLimits::saveOverride((int) $user['id'], $values, (int) ($this->user['id'] ?? 0));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Reputation override failed', ['message' => $e->getMessage()]);
            return $this->error('Could not save the exception', 500);
        }
        $this->log('Admin Set Reputation Limit Exception', ['user_id' => (int) $user['id'], 'changes' => count($res['changes'])]);
        return $this->success(['changes' => $res['changes']], $res['changes'] ? 'Saved' : 'No changes');
    }

    /** POST /api/admin/reputation-limits  {limits: {tier: {key: value}}} */
    public function save(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $limits = $this->get('limits');
        $sources = $this->get('sources');
        $standalone = $this->get('standalone');
        if ((!is_array($limits) || !$limits) && (!is_array($sources) || !$sources) && (!is_array($standalone) || !$standalone)) {
            return $this->error('Nothing to save', 422);
        }
        try {
            $actor = (int) ($this->user['id'] ?? 0);
            $res = is_array($limits) && $limits ? ReputationLimits::save($limits, $actor) : ['config' => ReputationLimits::config(), 'changes' => []];
            if (is_array($standalone) && $standalone) {
                $pl = ReputationPlans::save($standalone, $actor);
                $res['changes'] = array_merge($res['changes'], $pl['changes']);
                ReputationPlansRenewJob::ensureScheduled();
            }
            if (is_array($sources) && $sources) {
                $s = ReviewSources::save($sources, $actor);
                $res['changes'] = array_merge($res['changes'], $s['changes']);
            }
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Reputation limits save failed', ['message' => $e->getMessage()]);
            return $this->error('Could not save the limits', 500);
        }
        $this->log('Admin Updated Reputation Limits', ['changes' => count($res['changes'])]);
        return $this->success(['config' => $res['config'], 'changes' => $res['changes']], $res['changes'] ? 'Saved' : 'No changes');
    }
}
