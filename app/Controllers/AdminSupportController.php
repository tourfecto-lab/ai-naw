<?php

/**
 * Admin: the support desk. Permissions come from AdminAccess (tickets.view to read, tickets.manage to act);
 * AdminMiddleware enforces them by path and every action re-checks here.
 */
class AdminSupportController extends Controller
{
    private function need(string $perm): ?array
    {
        return AdminAccess::can($this->user, $perm) ? null : $this->error('ليس لديك صلاحية لتنفيذ هذا الإجراء', 403);
    }

    private function write(): ?array
    {
        return $this->need('tickets.manage') ?: $this->verifyCsrf() ?: $this->rateLimitGuard('user', 'support_ticket_admin', 120, 60);
    }

    /** GET /admin/support */
    public function page(array $params = []): array
    {
        $css = htmlspecialchars(asset_v('/assets/css/support-tickets.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/support-tickets.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="supportRoot" class="st" dir="rtl"><p>جاري التحميل…</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('support-admin', 'تذاكر الدعم', 'مشاكل وأسئلة وشكاوى العملاء: رد، أسند، وأقفل', $body, 'window.SupportTickets&&window.SupportTickets.init(window.Panel,{mode:"admin"});');
        exit;
    }

    /** GET /api/admin/support/tickets?status=&priority=&category=&assigned=&q=&page= */
    public function index(array $params = []): array
    {
        if ($e = $this->need('tickets.view')) {
            return $e;
        }
        header('Cache-Control: private, no-store');
        $list = SupportTicketService::adminList([
            'status' => $this->get('status', 'active'), 'priority' => $this->get('priority', ''), 'category' => $this->get('category', ''),
            'assigned' => $this->get('assigned', ''), 'q' => $this->get('q', ''),
        ], (int) $this->get('page', 1));
        return $this->success($list + [
            'stats' => SupportTicketService::stats(), 'staff' => SupportTicketService::assignableStaff(), 'me' => (int) ($this->user['id'] ?? 0),
            'can_manage' => AdminAccess::can($this->user, 'tickets.manage'),
            'categories' => SupportTicketService::CATEGORIES, 'statuses' => SupportTicketService::STATUSES, 'priorities' => SupportTicketService::PRIORITIES,
            'csrf_token' => Csrf::token(),
        ]);
    }

    /** GET /api/admin/support/tickets/{id} */
    public function show(array $params = []): array
    {
        if ($e = $this->need('tickets.view')) {
            return $e;
        }
        header('Cache-Control: private, no-store');
        $d = SupportTicketService::adminGet((int) ($params['id'] ?? 0));
        return $d ? $this->success($d + ['csrf_token' => Csrf::token()]) : $this->error('التذكرة مش موجودة', 422);
    }

    /** POST /api/admin/support/tickets/{id}/reply {message, internal?, status?} */
    public function reply(array $params = []): array
    {
        if ($e = $this->write()) {
            return $e;
        }
        try {
            SupportTicketService::staffReply((int) $this->user['id'], (int) ($params['id'] ?? 0), (string) $this->get('message', ''), (bool) $this->get('internal', false), (string) $this->get('status', '') ?: null);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([], 'تم');
    }

    /** POST /api/admin/support/tickets/{id}/status {status} */
    public function status(array $params = []): array
    {
        if ($e = $this->write()) {
            return $e;
        }
        try {
            SupportTicketService::setStatus((int) ($params['id'] ?? 0), (string) $this->get('status', ''));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([], 'تم تحديث الحالة');
    }

    /** POST /api/admin/support/tickets/{id}/priority {priority} */
    public function priority(array $params = []): array
    {
        if ($e = $this->write()) {
            return $e;
        }
        try {
            SupportTicketService::setPriority((int) ($params['id'] ?? 0), (string) $this->get('priority', ''));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([], 'تم تحديث الأولوية');
    }

    /** POST /api/admin/support/tickets/{id}/assign {staff_id|null} */
    public function assign(array $params = []): array
    {
        if ($e = $this->write()) {
            return $e;
        }
        $sid = (int) $this->get('staff_id', 0);
        try {
            SupportTicketService::assign((int) ($params['id'] ?? 0), $sid > 0 ? $sid : null);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([], 'تم الإسناد');
    }
}
