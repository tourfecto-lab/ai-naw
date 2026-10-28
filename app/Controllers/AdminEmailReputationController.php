<?php

/**
 * Tourfecto - لوحة الأدمن: حماية سمعة التسويق بالبريد
 *
 * تحكم الأدمن في حملات/حسابات العملاء: نظرة عامة بمعدلات الارتداد والشكاوى،
 * إيقاف/استئناف حساب أو حملة، تعديل الحدود، موافقة أول حملة، الحظر العام،
 * وسجل التدقيق. كل المسارات تحت /api/admin/email-reputation/* (AuthMiddleware +
 * AdminMiddleware). كل إجراء بيتسجّل في email_reputation_events باسم الأدمن.
 * @version 1.0.0
 */
class AdminEmailReputationController extends Controller
{
    private function guard(): EmailReputationGuard
    {
        return new EmailReputationGuard();
    }

    private function actorId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    /** GET /admin/email-reputation - صفحة اللوحة (الواجهة في assets/js/admin-email-reputation.js) */
    public function page(array $params = []): array
    {
        // الصلاحية من AdminMiddleware على المسار
        $js = htmlspecialchars(asset_v('/assets/js/admin-email-reputation.js'), ENT_QUOTES, 'UTF-8');
        $body = '<section id="email-reputation-root" dir="rtl"><p>جاري التحميل…</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('email-reputation', 'سمعة التسويق بالبريد', 'معدلات الارتداد والشكاوى، إيقاف الحسابات والحملات، والحظر العام', $body, "window.EmailReputationAdmin&&window.EmailReputationAdmin.mount(document.getElementById('email-reputation-root'));");
        exit;
    }

    /** GET /api/admin/email-reputation/overview?days=30 */
    public function overview(array $params = []): array
    {
        $days = max(1, min(365, (int) $this->get('days', 30)));
        return $this->success(['days' => $days, 'accounts' => $this->guard()->adminOverview($days)]);
    }

    /** GET /api/admin/email-reputation/accounts/{id} */
    public function account(array $params = []): array
    {
        $userId = (int) ($params['id'] ?? 0);
        if ($userId <= 0) {
            return $this->error('عميل غير صالح', 422);
        }
        $g = $this->guard();
        return $this->success([
            'snapshot' => $g->snapshot($userId),
            'rates' => $g->accountCounts($userId),
            'events' => $g->events($userId, 50),
        ]);
    }

    /** POST /api/admin/email-reputation/accounts/{id}/pause  {reason, suspend?} */
    public function pauseAccount(array $params = []): array
    {
        $userId = (int) ($params['id'] ?? 0);
        $in = $this->input();
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($userId <= 0 || $reason === '') {
            return $this->error('السبب مطلوب', 422);
        }
        $ok = $this->guard()->pauseAccount($userId, $reason, $this->actorId(), !empty($in['suspend']) ? 'suspended' : 'paused');
        return $ok ? $this->success([], 'تم إيقاف الإرسال لهذا العميل') : $this->error('الحساب موقوف بالفعل', 409);
    }

    /** POST /api/admin/email-reputation/accounts/{id}/resume  {note?} */
    public function resumeAccount(array $params = []): array
    {
        $userId = (int) ($params['id'] ?? 0);
        $ok = $this->guard()->resumeAccount($userId, $this->actorId(), (string) ($this->input()['note'] ?? ''));
        return $ok ? $this->success([], 'تم استئناف الإرسال') : $this->error('الحساب غير موقوف', 409);
    }

    /** POST /api/admin/email-reputation/accounts/{id}/limits  {daily_limit?, monthly_limit?, ...} */
    public function setLimits(array $params = []): array
    {
        $userId = (int) ($params['id'] ?? 0);
        $r = $this->guard()->setLimits($userId, $this->input(), $this->actorId());
        return $r['success'] ? $this->success([], 'تم تحديث الحدود') : $this->error($r['error'], 422);
    }

    /** POST /api/admin/email-reputation/accounts/{id}/approve-review */
    public function approveReview(array $params = []): array
    {
        $this->guard()->approveReview((int) ($params['id'] ?? 0), $this->actorId());
        return $this->success([], 'تمت الموافقة على أول حملة');
    }

    /** POST /api/admin/email-reputation/campaigns/{id}/pause  {reason} */
    public function pauseCampaign(array $params = []): array
    {
        $c = $this->campaignRow((int) ($params['id'] ?? 0));
        if (!$c) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $reason = trim((string) ($this->input()['reason'] ?? 'إيقاف إداري'));
        $ok = $this->guard()->pauseCampaign((int) $c['user_id'], (int) $c['id'], $reason, $this->actorId());
        return $ok ? $this->success([], 'تم إيقاف الحملة') : $this->error('يمكن إيقاف الحملات قيد الإرسال فقط', 409);
    }

    /** POST /api/admin/email-reputation/campaigns/{id}/resume */
    public function resumeCampaign(array $params = []): array
    {
        $c = $this->campaignRow((int) ($params['id'] ?? 0));
        if (!$c) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $r = $this->guard()->resumeCampaign((int) $c['user_id'], (int) $c['id'], $this->actorId());
        return $r['success'] ? $this->success([], 'تم استئناف الحملة') : $this->error($r['error'], 409);
    }

    /** GET /api/admin/email-reputation/global-suppressions */
    public function globalSuppressions(array $params = []): array
    {
        return $this->success($this->guard()->globalSuppressions((int) $this->get('limit', 200)));
    }

    /** POST /api/admin/email-reputation/global-suppressions  {value, kind: email|domain, reason?} */
    public function addGlobalSuppression(array $params = []): array
    {
        $in = $this->input();
        $r = $this->guard()->addGlobalSuppression((string) ($in['value'] ?? ''), (string) ($in['kind'] ?? 'email'), isset($in['reason']) ? (string) $in['reason'] : null, $this->actorId());
        return $r['success'] ? $this->success($r, 'تمت الإضافة للحظر العام') : $this->error($r['error'], 422);
    }

    /** DELETE /api/admin/email-reputation/global-suppressions/{id} */
    public function removeGlobalSuppression(array $params = []): array
    {
        return $this->guard()->removeGlobalSuppression((int) ($params['id'] ?? 0), $this->actorId())
            ? $this->success([], 'تم الحذف')
            : $this->error('غير موجود', 404);
    }

    /** GET /api/admin/email-reputation/events?user_id=&limit= */
    public function events(array $params = []): array
    {
        return $this->success($this->guard()->events((int) $this->get('user_id', 0), (int) $this->get('limit', 100)));
    }

    private function campaignRow(int $id): ?array
    {
        $r = $this->db->query('SELECT id, user_id FROM email_campaigns WHERE id = ? LIMIT 1', [$id]);
        return $r[0] ?? null;
    }
}
