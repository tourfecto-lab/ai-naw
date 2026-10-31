<?php

/**
 * Admin: WhatsApp marketing. (1) The hub of the platform's own WhatsApp marketing, which runs on the customer campaign
 * engine under the admin's own account; (2) oversight of every customer's campaigns: delivery numbers, quality flags,
 * pause or cancel a campaign, suspend or resume an account, and the history of those actions.
 * JSON errors never use 404 (the production web server replaces it with an HTML page).
 */
class AdminWhatsAppMarketingController extends Controller
{
    private function actor(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    /** GET /admin/whatsapp-marketing */
    public function page(array $params = []): array
    {
        $js = htmlspecialchars(asset_v('/assets/js/admin-whatsapp-marketing.js'), ENT_QUOTES, 'UTF-8');
        $body = '<section id="wa-admin-root"><p>Loading...</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('whatsapp-marketing-admin', 'WhatsApp marketing', 'The platform\'s own campaigns and oversight of customer campaigns', $body);
        exit;
    }

    /** GET /api/admin/whatsapp-marketing?website_id= */
    public function data(array $params = []): array
    {
        try {
            $tenant = (new CrmPermissionService())->resolveTenantId($this->actor());
            $ws = (int) $this->get('website_id', 0);
            return $this->success([
                'kpis' => WhatsAppOversight::kpis(),
                'hub' => WhatsAppOversight::hub($tenant, $ws > 0 ? $ws : null),
                'suspended' => WhatsAppOversight::suspendedList(),
                'audit' => WhatsAppOversight::auditLog(30),
                'min_sent_for_flags' => WhatsAppOversight::MIN_SENT_FOR_FLAGS,
                'failure_flag_pct' => (int) round(WhatsAppOversight::FAILURE_FLAG * 100),
                'csrf_token' => class_exists('Csrf') ? Csrf::token() : '',
            ]);
        } catch (Throwable $e) {
            Logger::error('Admin WhatsApp marketing data failed', ['message' => $e->getMessage()]);
            return $this->error('Could not load WhatsApp marketing', 500);
        }
    }

    /** GET /api/admin/whatsapp-marketing/campaigns?state=&q=&page= */
    public function campaigns(array $params = []): array
    {
        try {
            return $this->success(WhatsAppOversight::campaigns((string) $this->get('state', ''), trim((string) $this->get('q', '')), (int) $this->get('page', 1)));
        } catch (Throwable $e) {
            Logger::error('Admin WhatsApp campaigns failed', ['message' => $e->getMessage()]);
            return $this->error('Could not load the campaigns', 500);
        }
    }

    /** POST /api/admin/whatsapp-marketing/campaigns/{id}/action  {action: pause|cancel, reason} */
    public function campaignAction(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        try {
            $res = WhatsAppOversight::campaignAction((int) ($params['id'] ?? 0), (string) $this->get('action', ''), (string) $this->get('reason', ''), $this->actor());
            return $this->success($res, 'Saved');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin WhatsApp campaign action failed', ['message' => $e->getMessage()]);
            return $this->error('Could not complete the action', 500);
        }
    }

    /** POST /api/admin/whatsapp-marketing/suspend  {email, reason}  |  /resume  {email} */
    public function suspend(array $params = []): array
    {
        return $this->accountAction(true);
    }

    public function resume(array $params = []): array
    {
        return $this->accountAction(false);
    }

    private function accountAction(bool $suspend): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $email = trim((string) $this->get('email', ''));
        $user = $email === '' ? null : ($this->db->query('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])[0] ?? null);
        if (!$user) {
            return $this->error('No customer with this e-mail', 422);
        }
        try {
            $res = $suspend
                ? WhatsAppOversight::suspend((int) $user['id'], (string) $this->get('reason', ''), $this->actor())
                : WhatsAppOversight::resume((int) $user['id'], $this->actor());
            $this->log($suspend ? 'Admin Suspended WhatsApp Marketing' : 'Admin Resumed WhatsApp Marketing', ['user_id' => (int) $user['id']]);
            return $this->success($res, 'Saved');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin WhatsApp account action failed', ['message' => $e->getMessage()]);
            return $this->error('Could not complete the action', 500);
        }
    }
}
