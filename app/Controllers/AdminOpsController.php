<?php

/**
 * Tourfecto - Admin Ops Controller
 * الصفحات التشغيلية الاحترافية للأدمن: صلاحيات الفريق، سجل التدقيق، استهلاك الذكاء الاصطناعي،
 * مراقبة المهام، مركز الأمان، وملف العميل 360 مع الملاحظات الداخلية.
 *
 * الصلاحيات بتتفرض في AdminMiddleware (AdminAccess::requiredFor) وبنتحقق منها تاني جوّه كل
 * دالة حساسة (دفاع مزدوج). كل استعلام معزول: لو جدول ناقص (قبل تشغيل SQL التحديث) الصفحة
 * بتعرض بيانات فاضية ورسالة بدل خطأ 500.
 *
 * @version 1.0.0
 */
class AdminOpsController extends Controller
{
    // ================================================================== هويتي وصلاحياتي

    /** GET /api/admin/ops/me */
    public function me(array $params = []): array
    {
        $p = AdminAccess::staffProfile($this->user);
        return $this->success([
            'id' => (int) $this->user['id'],
            'email' => $this->user['email'] ?? '',
            'staff_role' => $p['role'],
            'staff_role_label' => AdminAccess::ROLE_LABELS[$p['role']] ?? $p['role'],
            'permissions' => AdminAccess::permissionsFor($this->user),
        ]);
    }

    // ================================================================== الفريق والأدوار

    /** GET /api/admin/ops/team */
    public function team(array $params = []): array
    {
        $this->need('team.view');
        // قبل تشغيل TOURFECTO_ADMIN_PRO.sql مفيش جدول admin_staff: نعرض الفريق من users بس (من غير JOIN)
        $hasStaff = $this->tableExists('admin_staff');
        $rows = $this->rows(
            "SELECT u.id, u.email, COALESCE(NULLIF(u.display_name,''), NULLIF(u.company_name,''), u.email) AS name,
                    u.role, u.status, u.two_factor_enabled, u.created_at,
                    " . ($hasStaff ? 's.staff_role, s.extra_permissions' : 'NULL AS staff_role, NULL AS extra_permissions') . ",
                    (SELECT MAX(lh.created_at) FROM login_history lh WHERE lh.user_id = u.id AND lh.status = 'success') AS last_login_at
               FROM users u " . ($hasStaff ? 'LEFT JOIN admin_staff s ON s.user_id = u.id' : '') . "
              WHERE u.role IN ('admin','super_admin') AND u.deleted_at IS NULL
           ORDER BY (u.role = 'super_admin') DESC, u.created_at ASC"
        );
        $members = array_map(function ($r) {
            $role = $r['role'] === 'super_admin' ? 'owner' : (AdminAccess::validRole((string) $r['staff_role']) ? $r['staff_role'] : 'admin');
            $extra = json_decode((string) ($r['extra_permissions'] ?? ''), true);
            return [
                'id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'status' => $r['status'],
                'staff_role' => $role, 'extra_permissions' => is_array($extra) ? $extra : [],
                'two_factor' => (int) $r['two_factor_enabled'] === 1, 'last_login_at' => $r['last_login_at'],
                'created_at' => $r['created_at'], 'is_self' => (int) $r['id'] === (int) $this->user['id'],
            ];
        }, $rows);

        $roles = [];
        foreach (AdminAccess::ROLE_LABELS as $key => $label) {
            $roles[] = ['key' => $key, 'label' => $label, 'permissions' => AdminAccess::rolePermissions($key)];
        }
        $catalog = [];
        foreach (AdminAccess::CATALOG as $key => $meta) {
            $catalog[] = ['key' => $key, 'group' => $meta[0], 'label' => $meta[1]];
        }
        return $this->success([
            'members' => $members, 'roles' => $roles, 'catalog' => $catalog,
            'can_manage' => AdminAccess::can($this->user, 'team.manage'),
            'staff_table_ready' => $hasStaff,
        ]);
    }

    /** POST /api/admin/ops/team/invite  {email, staff_role, extra_permissions[]} */
    public function teamInvite(array $params = []): array
    {
        $this->need('team.manage');
        $email = strtolower(trim((string) $this->get('email', '')));
        $role = (string) $this->get('staff_role', 'support');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('البريد الإلكتروني غير صالح', 422);
        }
        if (!AdminAccess::validRole($role)) {
            return $this->error('الدور غير معروف', 422);
        }
        if (!$this->tableExists('admin_staff')) {
            return $this->error('شغّل ملف TOURFECTO_ADMIN_PRO.sql على قاعدة البيانات أولاً', 409);
        }
        $u = $this->rows('SELECT id, role, status FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', [$email]);
        if (!$u) {
            return $this->error('مفيش مستخدم بالبريد ده. المستخدم لازم يكون مسجّل في المنصة الأول.', 404);
        }
        $target = $u[0];
        if ($target['status'] !== 'active') {
            return $this->error('حساب المستخدم غير نشط', 422);
        }
        return $this->applyRole((int) $target['id'], (string) $target['role'], $role, $this->cleanExtra($this->get('extra_permissions', [])), true);
    }

    /** POST /api/admin/ops/team/{id}/role  {staff_role, extra_permissions[]} */
    public function teamSetRole(array $params = []): array
    {
        $this->need('team.manage');
        $id = (int) ($params['id'] ?? 0);
        $role = (string) $this->get('staff_role', '');
        if (!AdminAccess::validRole($role)) {
            return $this->error('الدور غير معروف', 422);
        }
        if ($id === (int) $this->user['id']) {
            return $this->error('لا يمكنك تغيير دورك بنفسك', 403);
        }
        $u = $this->rows("SELECT id, role FROM users WHERE id = ? AND role IN ('admin','super_admin') AND deleted_at IS NULL LIMIT 1", [$id]);
        if (!$u) {
            return $this->error('العضو غير موجود في فريق الأدمن', 404);
        }
        return $this->applyRole($id, (string) $u[0]['role'], $role, $this->cleanExtra($this->get('extra_permissions', [])), false);
    }

    /** POST /api/admin/ops/team/{id}/remove */
    public function teamRemove(array $params = []): array
    {
        $this->need('team.manage');
        $id = (int) ($params['id'] ?? 0);
        if ($id === (int) $this->user['id']) {
            return $this->error('لا يمكنك إزالة نفسك من الفريق', 403);
        }
        $u = $this->rows("SELECT id, role FROM users WHERE id = ? AND role IN ('admin','super_admin') AND deleted_at IS NULL LIMIT 1", [$id]);
        if (!$u) {
            return $this->error('العضو غير موجود في فريق الأدمن', 404);
        }
        if ($u[0]['role'] === 'super_admin' && $this->ownerCount() <= 1) {
            return $this->error('لا يمكن إزالة آخر مالك للمنصة', 409);
        }
        $prev = $this->rows('SELECT previous_role FROM admin_staff WHERE user_id = ? LIMIT 1', [$id]);
        $back = in_array($prev[0]['previous_role'] ?? '', ['manager', 'client', 'agent', 'agency_owner'], true) ? $prev[0]['previous_role'] : 'manager';
        $this->db->exec("UPDATE users SET role = ? WHERE id = ?", [$back, $id]);
        $this->db->exec('DELETE FROM admin_staff WHERE user_id = ?', [$id]);
        $this->revokeSessions($id);
        AdminAccess::resetCache();
        return $this->success(['id' => $id, 'role' => $back], 'تمت إزالة العضو من فريق الأدمن وإنهاء جلساته');
    }

    private function applyRole(int $id, string $currentUserRole, string $staffRole, array $extra, bool $promote): array
    {
        // ترقية لمالك = users.role = super_admin | غيره = admin
        $newUserRole = $staffRole === 'owner' ? 'super_admin' : 'admin';
        if ($currentUserRole === 'super_admin' && $newUserRole !== 'super_admin' && $this->ownerCount() <= 1) {
            return $this->error('لا يمكن تنزيل آخر مالك للمنصة', 409);
        }
        $prevRole = in_array($currentUserRole, ['admin', 'super_admin'], true) ? null : $currentUserRole;
        if ($currentUserRole !== $newUserRole) {
            $this->db->exec('UPDATE users SET role = ? WHERE id = ?', [$newUserRole, $id]);
        }
        $this->db->exec(
            "INSERT INTO admin_staff (user_id, staff_role, extra_permissions, previous_role, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE staff_role = VALUES(staff_role), extra_permissions = VALUES(extra_permissions),
                                     previous_role = COALESCE(admin_staff.previous_role, VALUES(previous_role))",
            [$id, $staffRole, json_encode($extra), $prevRole, (int) $this->user['id']]
        );
        // أي تغيير في الصلاحيات = جلسات جديدة (أمان)
        if (!$promote) {
            $this->revokeSessions($id);
        }
        AdminAccess::resetCache();
        return $this->success(['id' => $id, 'staff_role' => $staffRole], $promote ? 'تمت إضافة العضو لفريق الأدمن' : 'تم تحديث دور العضو');
    }

    private function ownerCount(): int
    {
        return (int) ($this->rows("SELECT COUNT(*) c FROM users WHERE role = 'super_admin' AND deleted_at IS NULL")[0]['c'] ?? 0);
    }

    private function cleanExtra($extra): array
    {
        if (!is_array($extra)) {
            return [];
        }
        return array_values(array_filter(array_unique($extra), static function ($p) {
            return is_string($p) && isset(AdminAccess::CATALOG[$p]);
        }));
    }

    /**
     * ملاحظة مهمة: Database::exec() بترجّع bool بس. عدد الصفوف المتأثرة (UPDATE/DELETE) بييجي من
     * Database::query() — عشان كده كل العدّادات هنا بتستخدم query().
     */
    private function revokeSessions(int $userId): int
    {
        try {
            return (int) $this->db->query('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ================================================================== سجل التدقيق

    /** GET /api/admin/ops/audit?q=&result=&actor=&from=&to=&page=&per= */
    public function audit(array $params = []): array
    {
        $this->need('audit.view');
        [$where, $bind] = $this->auditFilter();
        $per = max(10, min(100, (int) $this->get('per', 30)));
        $page = max(1, (int) $this->get('page', 1));
        $total = (int) ($this->rows("SELECT COUNT(*) c FROM audit_logs a WHERE $where", $bind)[0]['c'] ?? 0);
        $items = $this->rows(
            "SELECT a.id, a.user_id, a.action, a.object_type, a.object_id, a.result, a.meta, a.ip_address, a.created_at,
                    COALESCE(NULLIF(u.display_name,''), NULLIF(u.company_name,''), u.email) AS actor_name, u.email AS actor_email
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
              WHERE $where ORDER BY a.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $bind
        );
        $actors = $this->rows(
            "SELECT DISTINCT a.user_id id, COALESCE(NULLIF(u.display_name,''), NULLIF(u.company_name,''), u.email) name
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY name LIMIT 100"
        );
        return $this->success(['items' => $items, 'total' => $total, 'page' => $page, 'per' => $per, 'actors' => $actors]);
    }

    /** GET /api/admin/ops/audit/export - CSV (حد أقصى 5000 سطر) */
    public function auditExport(array $params = []): void
    {
        if (!AdminAccess::can($this->user, 'audit.view')) {
            http_response_code(403);
            echo 'forbidden';
            exit;
        }
        [$where, $bind] = $this->auditFilter();
        $rows = $this->rows(
            "SELECT a.id, a.created_at, u.email AS actor, a.action, a.object_type, a.object_id, a.result, a.ip_address
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT 5000",
            $bind
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['#', 'التاريخ (UTC)', 'المنفّذ', 'الإجراء', 'النوع', 'المعرّف', 'النتيجة', 'IP']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['created_at'], $r['actor'], $r['action'], $r['object_type'], $r['object_id'], $r['result'], $r['ip_address']]);
        }
        fclose($out);
        exit;
    }

    private function auditFilter(): array
    {
        $w = ['1 = 1'];
        $b = [];
        if (($q = trim((string) $this->get('q', ''))) !== '') {
            $w[] = '(a.action LIKE ? OR a.object_type LIKE ? OR a.object_id = ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($b, $like, $like, $q);
        }
        if (in_array($this->get('result'), ['success', 'failed'], true)) {
            $w[] = 'a.result = ?';
            $b[] = $this->get('result');
        }
        if ((int) $this->get('actor', 0) > 0) {
            $w[] = 'a.user_id = ?';
            $b[] = (int) $this->get('actor');
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = (string) $this->get($k, '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $w[] = "a.created_at $op ?";
                $b[] = $d . ($k === 'from' ? ' 00:00:00' : ' 23:59:59');
            }
        }
        return [implode(' AND ', $w), $b];
    }

    // ================================================================== استهلاك الذكاء الاصطناعي

    /** GET /api/admin/ops/ai-usage?days=30 */
    public function aiUsage(array $params = []): array
    {
        $this->need('ai_usage.view');
        $days = in_array((int) $this->get('days', 30), [7, 30, 90], true) ? (int) $this->get('days', 30) : 30;
        $from = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' day'));
        $b = [':f' => $from];

        $tot = $this->rows(
            'SELECT COUNT(*) calls, COALESCE(SUM(cost_in_usd),0) cost, COALESCE(SUM(tokens_used),0) tokens,
                    SUM(status_code >= 400) errors, COALESCE(AVG(duration_ms),0) avg_ms
               FROM api_usage_logs WHERE created_at >= :f', $b
        )[0] ?? [];
        $byFeature = $this->rows(
            "SELECT COALESCE(NULLIF(feature,''), api_type) feature, COUNT(*) calls, COALESCE(SUM(cost_in_usd),0) cost,
                    COALESCE(SUM(tokens_used),0) tokens, SUM(status_code >= 400) errors
               FROM api_usage_logs WHERE created_at >= :f GROUP BY 1 ORDER BY cost DESC, calls DESC LIMIT 15", $b
        );
        // حسب المزود: لو api_type = other وفي endpoint اسم المزود (قبل تشغيل TOURFECTO_FIX_AI_USAGE_ENUM.sql) نطلّعه من endpoint
        $byProvider = $this->rows(
            "SELECT CASE WHEN api_type = 'other' AND endpoint LIKE '%:%' THEN SUBSTRING_INDEX(endpoint, ':', 1) ELSE api_type END provider,
                    COUNT(*) calls, COALESCE(SUM(cost_in_usd),0) cost, COALESCE(SUM(tokens_used),0) tokens, SUM(status_code >= 400) errors,
                    COALESCE(AVG(duration_ms),0) avg_ms
               FROM api_usage_logs WHERE created_at >= :f GROUP BY 1 ORDER BY cost DESC, calls DESC", $b
        );
        $byModel = $this->rows(
            "SELECT COALESCE(NULLIF(model,''), 'unknown') model, COUNT(*) calls, COALESCE(SUM(cost_in_usd),0) cost, COALESCE(SUM(tokens_used),0) tokens
               FROM api_usage_logs WHERE created_at >= :f GROUP BY 1 ORDER BY cost DESC LIMIT 10", $b
        );
        $topUsers = $this->rows(
            "SELECT l.user_id, COALESCE(NULLIF(u.company_name,''), NULLIF(u.display_name,''), u.email, CONCAT('#', l.user_id)) name, u.email,
                    COUNT(*) calls, COALESCE(SUM(l.cost_in_usd),0) cost, COALESCE(SUM(l.tokens_used),0) tokens
               FROM api_usage_logs l LEFT JOIN users u ON u.id = l.user_id
              WHERE l.created_at >= :f GROUP BY l.user_id, name, u.email ORDER BY cost DESC, calls DESC LIMIT 10", $b
        );
        $daily = [];
        foreach ($this->rows('SELECT DATE(created_at) d, COUNT(*) calls, COALESCE(SUM(cost_in_usd),0) cost FROM api_usage_logs WHERE created_at >= :f GROUP BY d', $b) as $r) {
            $daily[$r['d']] = $r;
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-$i day"));
            $series[] = ['d' => $d, 'calls' => (int) ($daily[$d]['calls'] ?? 0), 'cost' => (float) ($daily[$d]['cost'] ?? 0)];
        }
        $recentErrors = $this->rows(
            "SELECT id, COALESCE(NULLIF(feature,''), api_type) feature, model, status_code, created_at
               FROM api_usage_logs WHERE status_code >= 400 ORDER BY id DESC LIMIT 8"
        );
        $calls = (int) ($tot['calls'] ?? 0);
        return $this->success([
            'days' => $days,
            'totals' => [
                'calls' => $calls, 'cost' => round((float) ($tot['cost'] ?? 0), 4), 'tokens' => (int) ($tot['tokens'] ?? 0),
                'errors' => (int) ($tot['errors'] ?? 0), 'error_rate' => $calls ? round(100 * (int) $tot['errors'] / $calls, 1) : 0,
                'avg_ms' => (int) round((float) ($tot['avg_ms'] ?? 0)), 'cost_per_call' => $calls ? round((float) $tot['cost'] / $calls, 5) : 0,
            ],
            'by_feature' => $byFeature, 'by_provider' => $byProvider, 'by_model' => $byModel, 'top_users' => $topUsers, 'series' => $series, 'recent_errors' => $recentErrors,
        ]);
    }

    /** GET /api/admin/ops/ai-routing - التوزيع الحالي للمهام على المزودين (من غير أي مفاتيح) */
    public function aiRouting(array $params = []): array
    {
        $this->need('ai_usage.view');
        if (!class_exists('AIOrchestrator')) {
            return $this->error('AIOrchestrator غير موجود', 404);
        }
        return $this->success((new AIOrchestrator())->describe());
    }

    // ================================================================== مراقبة المهام

    /** GET /api/admin/ops/jobs?status=&page= */
    public function jobs(array $params = []): array
    {
        $this->need('system.view');
        $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($this->rows('SELECT status, COUNT(*) c FROM jobs GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $status = (string) $this->get('status', 'failed');
        $where = isset($counts[$status]) ? 'status = ?' : '1 = 1';
        $bind = isset($counts[$status]) ? [$status] : [];
        $per = 25;
        $page = max(1, (int) $this->get('page', 1));
        $total = (int) ($this->rows("SELECT COUNT(*) c FROM jobs WHERE $where", $bind)[0]['c'] ?? 0);
        $items = $this->rows(
            "SELECT id, queue, job_class, status, attempts, LEFT(COALESCE(last_error,''), 300) last_error, available_at, reserved_at, completed_at, created_at,
                    TIMESTAMPDIFF(MINUTE, available_at, NOW()) AS waiting_min
               FROM jobs WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (int) (($page - 1) * $per), $bind
        );
        // مهام "processing" عالقة أكتر من 30 دقيقة = غالبًا العامل وقع
        $stuck = (int) ($this->rows("SELECT COUNT(*) c FROM jobs WHERE status = 'processing' AND reserved_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")[0]['c'] ?? 0);
        return $this->success(['counts' => $counts, 'items' => $items, 'total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'stuck' => $stuck]);
    }

    /** POST /api/admin/ops/jobs/{id}/retry */
    public function jobRetry(array $params = []): array
    {
        $this->need('system.manage');
        $n = (int) $this->db->query(
            "UPDATE jobs SET status = 'pending', attempts = 0, available_at = NOW(), reserved_at = NULL WHERE id = ? AND status = 'failed'",
            [(int) ($params['id'] ?? 0)]
        );
        return $n > 0 ? $this->success(['retried' => $n], 'اتحوّلت المهمة لطابور الانتظار') : $this->error('المهمة مش فاشلة أو مش موجودة', 404);
    }

    /** POST /api/admin/ops/jobs/{id}/cancel - بتعلّم المهمة المنتظرة كفاشلة (من غير حذف) */
    public function jobCancel(array $params = []): array
    {
        $this->need('system.manage');
        $n = (int) $this->db->query(
            "UPDATE jobs SET status = 'failed', last_error = 'أُلغيت يدويًا بواسطة الأدمن' WHERE id = ? AND status = 'pending'",
            [(int) ($params['id'] ?? 0)]
        );
        return $n > 0 ? $this->success(['cancelled' => $n], 'تم إلغاء المهمة') : $this->error('المهمة مش منتظرة أو مش موجودة', 404);
    }

    /** POST /api/admin/ops/jobs/retry-failed - إعادة كل المهام الفاشلة في آخر 24 ساعة (حد أقصى 200) */
    public function jobsRetryFailed(array $params = []): array
    {
        $this->need('system.manage');
        $n = (int) $this->db->query(
            "UPDATE jobs SET status = 'pending', attempts = 0, available_at = NOW(), reserved_at = NULL
              WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 200"
        );
        return $this->success(['retried' => $n], $n . ' مهمة اتحوّلت لطابور الانتظار');
    }

    // ================================================================== مركز الأمان

    /** GET /api/admin/ops/security */
    public function security(array $params = []): array
    {
        $this->need('security.view');
        $one = function (string $sql, array $b = []) {
            return (int) ($this->rows($sql, $b)[0]['c'] ?? 0);
        };
        $summary = [
            'failed_24h' => $one("SELECT COUNT(*) c FROM login_history WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
            'failed_7d' => $one("SELECT COUNT(*) c FROM login_history WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'success_24h' => $one("SELECT COUNT(*) c FROM login_history WHERE status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
            'active_blocks' => $one('SELECT COUNT(*) c FROM rate_limit_blocks WHERE expires_at > NOW()'),
            'admins_no_2fa' => $one("SELECT COUNT(*) c FROM users WHERE role IN ('admin','super_admin') AND two_factor_enabled = 0 AND deleted_at IS NULL"),
            'suspended_users' => $one("SELECT COUNT(*) c FROM users WHERE status = 'suspended' AND deleted_at IS NULL"),
        ];
        $ips = $this->rows(
            "SELECT ip_address, COUNT(*) attempts, COUNT(DISTINCT email_attempted) emails, MAX(created_at) last_seen, MAX(country) country
               FROM login_history WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           GROUP BY ip_address HAVING attempts >= 3 ORDER BY attempts DESC LIMIT 15"
        );
        $blocks = $this->rows(
            'SELECT id, identifier, LEFT(COALESCE(reason, \'\'), 120) reason, expires_at, created_at
               FROM rate_limit_blocks WHERE expires_at > NOW() ORDER BY id DESC LIMIT 20'
        );
        $admins = $this->rows(
            "SELECT u.id, u.email, u.two_factor_enabled,
                    (SELECT MAX(created_at) FROM login_history lh WHERE lh.user_id = u.id AND lh.status = 'success') last_login_at
               FROM users u WHERE u.role IN ('admin','super_admin') AND u.deleted_at IS NULL ORDER BY u.two_factor_enabled ASC, u.id"
        );
        $impersonations = $this->rows(
            "SELECT i.id, i.admin_id, i.target_user_id, i.reason, i.started_at, i.ended_at, a.email admin_email, t.email target_email
               FROM impersonation_logs i LEFT JOIN users a ON a.id = i.admin_id LEFT JOIN users t ON t.id = i.target_user_id
           ORDER BY i.id DESC LIMIT 10"
        );
        return $this->success(compact('summary', 'ips', 'blocks', 'admins', 'impersonations'));
    }

    /** POST /api/admin/ops/security/unblock {id} */
    public function securityUnblock(array $params = []): array
    {
        $this->need('security.manage');
        $n = (int) $this->db->query('UPDATE rate_limit_blocks SET expires_at = NOW() WHERE id = ? AND expires_at > NOW()', [(int) $this->get('id', 0)]);
        return $n > 0 ? $this->success(['unblocked' => $n], 'تم فك الحظر') : $this->error('الحظر منتهي أو غير موجود', 404);
    }

    /** POST /api/admin/ops/security/force-logout {user_id} */
    public function securityForceLogout(array $params = []): array
    {
        $this->need('security.manage');
        $id = (int) $this->get('user_id', 0);
        if ($id <= 0 || !$this->rows('SELECT id FROM users WHERE id = ? LIMIT 1', [$id])) {
            return $this->error('المستخدم غير موجود', 404);
        }
        $n = $this->revokeSessions($id);
        return $this->success(['revoked' => $n], 'تم إنهاء ' . $n . ' جلسة. قد تفضل الجلسة الحالية شغّالة لحد انتهاء صلاحيتها القصيرة.');
    }

    // ================================================================== ملف العميل 360

    /** GET /api/admin/pro/user/{id} */
    public function user360(array $params = []): array
    {
        $this->need('users.view');
        $id = (int) ($params['id'] ?? 0);
        $u = $this->rows(
            "SELECT id, email, company_name, display_name, first_name, last_name, phone, role, status, country_code, timezone, language,
                    two_factor_enabled, email_verified_at, created_at, updated_at
               FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$id]
        );
        if (!$u) {
            return $this->error('العميل غير موجود', 404);
        }
        $user = $u[0];
        $user['is_active'] = $user['status'] === 'active' ? 1 : 0;

        $subs = $this->rows(
            "SELECT s.id, s.status, s.current_period_end, s.created_at, sp.name plan_name, sp.price, sp.currency, sp.billing_cycle
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 5", [$id]
        );
        $wallet = $this->rows(
            "SELECT COALESCE(SUM(amount),0) balance, COUNT(*) tx FROM wallet_transactions WHERE user_id = ? AND status = 'completed'", [$id]
        )[0] ?? ['balance' => 0, 'tx' => 0];
        $pending = (int) ($this->rows("SELECT COUNT(*) c FROM wallet_transactions WHERE user_id = ? AND status = 'pending'", [$id])[0]['c'] ?? 0);
        $sites = $this->rows('SELECT id, COALESCE(NULLIF(brand_name,\'\'), NULLIF(company_name,\'\'), domain) name, main_url, is_connected, created_at FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$id]);
        $logins = $this->rows(
            "SELECT status, ip_address, country, city, browser, platform, is_impersonation, created_at FROM login_history WHERE user_id = ? OR (user_id IS NULL AND email_attempted = ?) ORDER BY id DESC LIMIT 8",
            [$id, $user['email']]
        );
        $ai = $this->rows(
            'SELECT COUNT(*) calls, COALESCE(SUM(cost_in_usd),0) cost, COALESCE(SUM(tokens_used),0) tokens FROM api_usage_logs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [$id]
        )[0] ?? [];
        $notes = $this->tableExists('admin_notes') ? $this->rows(
            "SELECT n.id, n.note, n.is_pinned, n.created_at, n.author_id, COALESCE(NULLIF(a.display_name,''), a.email) author
               FROM admin_notes n LEFT JOIN users a ON a.id = n.author_id WHERE n.user_id = ? ORDER BY n.is_pinned DESC, n.id DESC LIMIT 30", [$id]
        ) : [];
        $timeline = $this->rows(
            "SELECT a.action, a.result, a.created_at, COALESCE(NULLIF(u.display_name,''), u.email) actor
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
              WHERE a.object_type = 'users' AND a.object_id = ? ORDER BY a.id DESC LIMIT 10", [(string) $id]
        );
        $flags = [];
        if (!$user['email_verified_at']) { $flags[] = 'unverified'; }
        if ($pending > 0) { $flags[] = 'pending_deposit'; }
        if ($user['status'] !== 'active') { $flags[] = 'suspended'; }
        if (in_array($user['role'], ['admin', 'super_admin'], true) && !(int) $user['two_factor_enabled']) { $flags[] = 'admin_no_2fa'; }

        return $this->success([
            'user' => $user, 'subscriptions' => $subs,
            'wallet' => ['balance' => (float) $wallet['balance'], 'transactions' => (int) $wallet['tx'], 'pending' => $pending],
            'websites' => $sites, 'logins' => $logins, 'ai_30d' => $ai, 'notes' => $notes, 'timeline' => $timeline, 'flags' => $flags,
            'can' => [
                'edit' => AdminAccess::can($this->user, 'users.edit'), 'suspend' => AdminAccess::can($this->user, 'users.suspend'),
                'notes' => AdminAccess::can($this->user, 'users.notes'), 'security' => AdminAccess::can($this->user, 'users.reset_security'),
                'impersonate' => AdminAccess::can($this->user, 'users.impersonate'), 'balance' => AdminAccess::can($this->user, 'billing.manage'),
            ],
        ]);
    }

    /** POST /api/admin/pro/user/{id}/notes {note, pinned} */
    public function userNoteAdd(array $params = []): array
    {
        $this->need('users.notes');
        $id = (int) ($params['id'] ?? 0);
        $note = trim((string) $this->get('note', ''));
        if ($note === '' || mb_strlen($note) > 2000) {
            return $this->error('اكتب ملاحظة (حتى 2000 حرف)', 422);
        }
        if (!$this->tableExists('admin_notes')) {
            return $this->error('شغّل ملف TOURFECTO_ADMIN_PRO.sql على قاعدة البيانات أولاً', 409);
        }
        if (!$this->rows('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id])) {
            return $this->error('العميل غير موجود', 404);
        }
        $this->db->exec('INSERT INTO admin_notes (user_id, author_id, note, is_pinned) VALUES (?, ?, ?, ?)', [$id, (int) $this->user['id'], $note, $this->get('pinned') ? 1 : 0]);
        return $this->success(['ok' => true], 'تمت إضافة الملاحظة');
    }

    /** DELETE /api/admin/pro/user/{id}/notes/{noteId} - صاحب الملاحظة أو المالك */
    public function userNoteDelete(array $params = []): array
    {
        $this->need('users.notes');
        $row = $this->rows('SELECT id, author_id FROM admin_notes WHERE id = ? AND user_id = ? LIMIT 1', [(int) ($params['noteId'] ?? 0), (int) ($params['id'] ?? 0)]);
        if (!$row) {
            return $this->error('الملاحظة غير موجودة', 404);
        }
        $isOwner = AdminAccess::staffProfile($this->user)['role'] === 'owner';
        if ((int) $row[0]['author_id'] !== (int) $this->user['id'] && !$isOwner) {
            return $this->error('تقدر تحذف ملاحظاتك بس', 403);
        }
        $this->db->exec('DELETE FROM admin_notes WHERE id = ?', [(int) $row[0]['id']]);
        return $this->success(['ok' => true], 'تم حذف الملاحظة');
    }

    // ================================================================== مساعدات

    /** دفاع مزدوج: حتى لو middleware اتعدّل بالغلط */
    private function need(string $permission): void
    {
        if (!AdminAccess::can($this->user, $permission)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'ليس لديك صلاحية: ' . (AdminAccess::CATALOG[$permission][1] ?? $permission), 'code' => 403], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return !empty($this->db->query('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1', [$table]));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function rows(string $sql, array $bind = []): array
    {
        try {
            $r = $this->db->query($sql, $bind);
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            Logger::error('AdminOps query failed', ['message' => $e->getMessage(), 'sql' => substr($sql, 0, 160)]);
            return [];
        }
    }
}
