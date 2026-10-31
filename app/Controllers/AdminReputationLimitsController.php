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
                'audit' => $audit,
                'usage' => ReputationLimits::usageReport(30),
                'csrf_token' => class_exists('Csrf') ? Csrf::token() : '',
            ]);
        } catch (Throwable $e) {
            Logger::error('Reputation limits data failed', ['message' => $e->getMessage()]);
            return $this->error('Could not load the limits', 500);
        }
    }

    /** POST /api/admin/reputation-limits  {limits: {tier: {key: value}}} */
    public function save(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $limits = $this->get('limits');
        if (!is_array($limits) || !$limits) {
            return $this->error('Nothing to save', 422);
        }
        try {
            $res = ReputationLimits::save($limits, (int) ($this->user['id'] ?? 0));
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
