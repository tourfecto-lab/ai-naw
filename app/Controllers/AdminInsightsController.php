<?php

/**
 * Tourfecto - لوحة الأدمن الاحترافية (APIs الصفحات الجديدة)
 *
 * صحة المنصة، سجل البريد، أرقام SaaS، صحة العملاء وربحيتهم، الدفع والكوبونات،
 * التنبيهات، أمان الأدمن، تاجات العملاء والإجراءات الجماعية والفلاتر المحفوظة،
 * وصفحة الحالة العامة وسجل التحديثات.
 *
 * كل المسارات تحت /api/admin/insights/* (محمية بـ AuthMiddleware + AdminMiddleware،
 * والصلاحيات في AdminAccess::requiredFor). الصفحات العامة /status و /changelog من غير تسجيل.
 *
 * @version 1.0.0
 */
class AdminInsightsController extends Controller
{
    // ================================================================== صحة المنصة

    /** GET /api/admin/insights/health */
    public function health(array $params = []): array
    {
        $report = (new PlatformHealthService())->report();
        $report['alerts'] = (new AdminAlertService())->openCount();
        return $this->success($report);
    }

    /** POST /api/admin/insights/health/check-integrations - اختبار كل التكاملات المتظبطة دلوقتي */
    public function checkIntegrations(array $params = []): array
    {
        @set_time_limit(120);
        $ctrl = new IntegrationsController();
        $registry = require APP_PATH . '/Config/integrations.php';
        $done = [];
        foreach (array_keys($registry) as $key) {
            $r = $ctrl->runAutomatedCheck($key);
            if ($r !== null) {
                $done[$key] = $r;
            }
        }
        return $this->success(['checked' => count($done), 'results' => $done], count($done) ? 'اتختبروا ' . count($done) . ' تكامل' : 'مفيش تكاملات متظبطة تتختبر');
    }

    /** POST /api/admin/insights/health/apply-schema - تطبيق تحديثات قاعدة البيانات الناقصة */
    public function applySchema(array $params = []): array
    {
        try {
            $r = (new SchemaUpdater())->apply();
        } catch (Throwable $e) {
            Logger::error('applySchema failed', ['message' => $e->getMessage()]);
            return $this->error('فشل التطبيق: ' . CustomerError::message($e->getMessage(), 500), 500);
        }
        $files = count($r['files']);
        $enums = count(array_filter($r['enums'], fn($x) => empty($x['error'])));
        $msg = $r['errors']
            ? 'اتطبق مع ' . $r['errors'] . ' خطأ - التفاصيل تحت'
            : ($files || $enums || $r['data'] ? 'تمام: ' . $files . ' ملف تحديث، ' . $enums . ' عمود اتوسّع' : 'قاعدة البيانات محدّثة أصلًا');
        return $this->success($r, $msg);
    }

    /** GET /api/admin/insights/cleanup - معاينة اللي ممكن يتنضف (مابيمسحش حاجة) */
    public function cleanupPreview(array $params = []): array
    {
        @set_time_limit(120);
        return $this->success((new CleanupService())->preview());
    }

    /** POST /api/admin/insights/cleanup {keys: [...]} - تنفيذ التنظيف للحاجات المختارة بس */
    public function cleanupRun(array $params = []): array
    {
        $keys = $this->get('keys');
        if (!is_array($keys) || !$keys) {
            return $this->error('اختار حاجة واحدة على الأقل', 422);
        }
        $keys = array_values(array_filter(array_map('strval', $keys), fn($k) => preg_match('/^[a-z_]{2,40}$/', $k)));
        $r = (new CleanupService())->run($keys);
        $errors = count(array_filter($r['results'], fn($x) => !empty($x['error'])));
        return $this->success($r, $errors ? 'التنظيف خلص مع ' . $errors . ' مشكلة - التفاصيل تحت' : 'تم التنظيف · اتوفّر ' . $r['freed']);
    }

    // ================================================================== سجل البريد

    /** GET /api/admin/insights/emails?status=&q=&context=&page= */
    public function emails(array $params = []): array
    {
        $page = max(1, (int) $this->get('page', 1));
        $per = 30;
        $where = ['1=1'];
        $p = [];
        $status = (string) $this->get('status', '');
        if (in_array($status, ['sent', 'failed'], true)) {
            $where[] = 'status = ?';
            $p[] = $status;
        }
        $ctx = (string) $this->get('context', '');
        if ($ctx !== '') {
            $where[] = 'context = ?';
            $p[] = $ctx;
        }
        $q = trim((string) $this->get('q', ''));
        if ($q !== '') {
            $where[] = '(to_email LIKE ? OR subject LIKE ?)';
            $p[] = '%' . $q . '%';
            $p[] = '%' . $q . '%';
        }
        $w = implode(' AND ', $where);
        try {
            $total = (int) ($this->db->query("SELECT COUNT(*) v FROM mail_log WHERE $w", $p)[0]['v'] ?? 0);
            $rows = $this->db->query(
                "SELECT id, to_email, to_name, subject, status, error, context, smtp_host, from_email, user_id, resent_from_id, duration_ms, created_at, body_html IS NOT NULL has_body
                   FROM mail_log WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
                $p
            );
            $contexts = $this->db->query('SELECT context, COUNT(*) n FROM mail_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND context IS NOT NULL GROUP BY context ORDER BY n DESC');
        } catch (Throwable $e) {
            return $this->error('سجل البريد مش متاح - شغّل ملف قاعدة البيانات 2026_10_08_000003_admin_insights.sql', 503);
        }
        return $this->success([
            'items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per,
            'stats' => ['h24' => MailLog::stats(24), 'd7' => MailLog::stats(168)],
            'contexts' => $contexts,
        ]);
    }

    /** GET /api/admin/insights/emails/{id} */
    public function email(array $params = []): array
    {
        $r = $this->db->query('SELECT * FROM mail_log WHERE id = ? LIMIT 1', [(int) ($params['id'] ?? 0)]);
        if (!$r) {
            return $this->error('الرسالة مش موجودة', 404);
        }
        return $this->success(['email' => $r[0]]);
    }

    /** POST /api/admin/insights/emails/{id}/resend */
    public function resendEmail(array $params = []): array
    {
        $r = $this->db->query('SELECT * FROM mail_log WHERE id = ? LIMIT 1', [(int) ($params['id'] ?? 0)]);
        if (!$r) {
            return $this->error('الرسالة مش موجودة', 404);
        }
        $m = $r[0];
        if ($m['body_html'] === null || $m['body_html'] === '') {
            return $this->error('محتوى الرسالة مش متخزّن (رسالة حملة أو أقدم من ' . MailLog::BODY_DAYS . ' يوم)', 422);
        }
        if (in_array($m['context'], ['password_reset', 'auth'], true) && strtotime($m['created_at']) < time() - 3600) {
            return $this->error('رابط استعادة كلمة المرور في الرسالة دي انتهى. خلّي العميل يطلب رابط جديد.', 422);
        }
        $res = (new Mailer())->context((string) ($m['context'] ?: 'resend'), $m['user_id'] ? (int) $m['user_id'] : null)
            ->resentFrom((int) $m['id'])
            ->send($m['to_email'], (string) $m['to_name'], $m['subject'], $m['body_html']);
        return !empty($res['success']) ? $this->success([], 'اتبعتت تاني ✓') : $this->error('فشل الإرسال: ' . ($res['error'] ?? ''), 422);
    }

    // ================================================================== الأرقام والعملاء

    /** GET /api/admin/insights/metrics?months= */
    public function metrics(array $params = []): array
    {
        return $this->success((new SaasMetricsService())->summary((int) $this->get('months', 12)));
    }

    /** GET /api/admin/insights/customers?segment=&q= */
    public function customers(array $params = []): array
    {
        $seg = (string) $this->get('segment', 'at_risk');
        $data = (new CustomerHealthService())->list($seg, trim((string) $this->get('q', '')));
        $ids = array_column($data['items'], 'id');
        $tags = $this->tagsFor($ids);
        foreach ($data['items'] as &$i) {
            $i['tags'] = $tags[$i['id']] ?? [];
        }
        return $this->success($data);
    }

    /** GET /api/admin/insights/adoption?stage=&q= - العملاء وصلوا لحد فين وبيستخدموا أنهي مميزات */
    public function adoption(array $params = []): array
    {
        $data = (new FeatureAdoptionService())->report((string) $this->get('stage', ''), mb_substr(trim((string) $this->get('q', '')), 0, 80));
        $ids = array_column($data['items'], 'id');
        $tags = $this->tagsFor($ids);
        foreach ($data['items'] as &$i) {
            $i['tags'] = $tags[$i['id']] ?? [];
        }
        return $this->success($data);
    }

    /** GET /api/admin/insights/errors - أخطاء النظام متجمّعة من ملفات اللوج */
    public function errors(array $params = []): array
    {
        $level = (string) $this->get('level', '');
        $source = (string) $this->get('source', '');
        $data = (new ErrorLogService())->report(
            (int) ($this->get('hours', 24) ?: 24),
            in_array($level, ['error', 'warning'], true) ? $level : '',
            mb_substr(trim((string) $this->get('q', '')), 0, 120),
            in_array($source, ['app', 'php'], true) ? $source : ''
        );
        return $this->success($data);
    }

    /** GET /api/admin/insights/customers/{id} - الصحة والربحية والتاجات لملف العميل */
    public function customer(array $params = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        $u = Database::getInstance()->query('SELECT id, role, owner_user_id, email_verified, email_verified_at, created_at FROM users WHERE id = ? LIMIT 1', [$id])[0] ?? [];
        $pending = class_exists('EmailVerification') && $u && EmailVerification::isPending($u);
        $h = (new CustomerHealthService())->forUser($id);
        if (!$h) {
            // عميل جديد من غير اشتراك مالوش «صحة» لسه - بس لازم زرار «أكّده يدويًا» يظهر (ده بالظبط وقت ما الإيميل مابيوصلش)
            return $u ? $this->success(['health' => null, 'email_pending' => $pending]) : $this->error('العميل مش موجود', 404);
        }
        $h['tags'] = $this->tagsFor([$id])[$id] ?? [];
        $h['email_pending'] = $pending;
        if (class_exists('PlanUsage')) {
            $h['plan_usage'] = PlanUsage::summary($id);
        }
        return $this->success(['health' => $h]);
    }

    /** GET /api/admin/insights/trials - التجارب الشغّالة واللي خلصت واللي مابدأوش */
    public function trials(array $params = []): array
    {
        return $this->success((new TrialFollowUpService())->report());
    }

    /** POST /api/admin/insights/trials/{id}/extend {days} - مدّ تجربة عميل (id = رقم الاشتراك) */
    public function extendTrial(array $params = []): array
    {
        $r = (new TrialFollowUpService())->extend((int) ($params['id'] ?? 0), (int) $this->get('days', 3), (int) ($this->user['id'] ?? 0));
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        return $this->success($r, 'اتمدّت التجربة لحد ' . substr($r['ends_at'], 0, 16));
    }

    /** POST /api/admin/insights/users/{id}/verify-email - تأكيد بريد العميل يدويًا (لما الإيميل مايوصلوش) */
    public function verifyUserEmail(array $params = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0 || empty(Database::getInstance()->query('SELECT id FROM users WHERE id = ?', [$id]))) {
            return $this->error('العميل مش موجود', 404);
        }
        EmailVerification::markVerified($id);
        return $this->success([], 'تم تأكيد بريد العميل');
    }

    // ================================================================== الدفع والكوبونات

    /** GET /api/admin/insights/billing */
    public function billing(array $params = []): array
    {
        $monthly = "(CASE WHEN COALESCE(sp.billing_cycle, s.plan_type) IN ('yearly','annual') THEN COALESCE(sp.price, s.price) / 12 ELSE COALESCE(sp.price, s.price) END)";
        $pastDue = $this->q(
            "SELECT s.id, s.user_id, u.email, COALESCE(NULLIF(u.company_name,''), u.email) name, COALESCE(sp.name, s.plan_name) plan,
                    ROUND($monthly, 2) mrr, s.current_period_end, s.updated_at,
                    DATEDIFF(NOW(), s.current_period_end) days_late,
                    (SELECT COALESCE(SUM(t.amount), 0) FROM wallet_transactions t WHERE t.user_id = s.user_id AND t.status = 'completed') balance
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id JOIN users u ON u.id = s.user_id
              WHERE s.status = 'past_due' ORDER BY mrr DESC"
        );
        $failed = $this->q(
            "SELECT p.id, p.user_id, u.email, COALESCE(NULLIF(u.company_name,''), u.email) name, p.amount, p.currency, p.gateway, p.payment_method, p.status, p.created_at
               FROM payment_transactions p LEFT JOIN users u ON u.id = p.user_id
              WHERE p.status IN ('failed','declined','error') AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY p.id DESC LIMIT 100"
        );
        $dunning = $this->q(
            "SELECT type, COUNT(*) n FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND type IN ('payment_failed','subscription_renewal_reminder','subscription_past_due','dunning_final_notice','subscription_expiring','renewal_reminder')
              GROUP BY type"
        );
        $coupons = [];
        try {
            $coupons = (new CouponService())->all();
        } catch (Throwable $e) {
        error_log('[AdminInsightsController] ignored error: ' . $e->getMessage());
        }
        $plans = $this->q('SELECT DISTINCT plan_name FROM subscriptions WHERE plan_name IS NOT NULL');
        return $this->success([
            'past_due' => $pastDue,
            'past_due_mrr' => round(array_sum(array_column($pastDue, 'mrr')), 2),
            'failed_payments' => $failed,
            'dunning' => $dunning,
            'coupons' => $coupons,
            'plan_codes' => array_values(array_filter(array_column($plans, 'plan_name'))) ?: ['starter', 'professional', 'enterprise'],
        ]);
    }

    /** POST /api/admin/insights/coupons  |  PUT /api/admin/insights/coupons/{id} */
    public function saveCoupon(array $params = []): array
    {
        $in = $this->input();
        $r = (new CouponService())->save($in, isset($params['id']) ? (int) $params['id'] : null, (int) $this->user['id']);
        return $r['ok'] ? $this->success(['id' => $r['id']], 'اتحفظ الكود') : $this->error($r['error'], 422);
    }

    // ================================================================== التنبيهات

    /** GET /api/admin/insights/alerts?status=open|resolved|all */
    public function alerts(array $params = []): array
    {
        $svc = new AdminAlertService();
        return $this->success(['items' => $svc->list((string) $this->get('status', 'open')), 'counts' => $svc->openCount(), 'settings' => $svc->settings()]);
    }

    /** POST /api/admin/insights/alerts/{id}/resolve {mute} */
    public function resolveAlert(array $params = []): array
    {
        (new AdminAlertService())->resolve((int) ($params['id'] ?? 0), (int) $this->user['id'], !empty($this->input()['mute']));
        return $this->success([], 'اتقفل');
    }

    /** POST /api/admin/insights/alerts/run - فحص دلوقتي */
    public function runAlerts(array $params = []): array
    {
        $r = (new AdminAlertService())->run();
        return $this->success($r, "اتفحص: {$r['opened']} جديد، {$r['resolved']} اتحل");
    }

    /** PUT /api/admin/insights/alerts/settings */
    public function saveAlertSettings(array $params = []): array
    {
        $r = (new AdminAlertService())->saveSettings($this->input());
        return $r['ok'] ? $this->success($r['settings'], 'اتحفظت الإعدادات') : $this->error($r['error'], 422);
    }

    // ================================================================== أمان الأدمن

    /** GET /api/admin/insights/security */
    public function security(array $params = []): array
    {
        AdminAccess::resetSecurityCache();
        $team = $this->q("SELECT id, email, COALESCE(NULLIF(company_name,''), email) name, role, two_factor_enabled, last_login FROM users WHERE role IN ('admin','super_admin') AND deleted_at IS NULL ORDER BY role DESC, id");
        return $this->success([
            'settings' => AdminAccess::securitySettings(),
            'my_ip' => function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            'me_2fa' => !empty($this->q('SELECT two_factor_enabled FROM users WHERE id = ?', [(int) $this->user['id']])[0]['two_factor_enabled']),
            'team' => $team,
            'reason_actions' => array_values(AdminAccess::REASON_REQUIRED),
        ]);
    }

    /** PUT /api/admin/insights/security */
    public function saveSecurity(array $params = []): array
    {
        $in = $this->input();
        $myIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $list = trim((string) ($in['ip_allowlist'] ?? ''));
        // ماينفعش الأدمن يقفل نفسه برّه
        if ($list !== '' && !AdminAccess::ipAllowed($myIp, $list)) {
            return $this->error("عنوانك الحالي ({$myIp}) مش في القايمة - ضيفه الأول عشان ماتتقفلش برّه اللوحة.", 422);
        }
        foreach (preg_split('/[\s,;]+/', $list) as $rule) {
            if ($rule !== '' && @inet_pton(explode('/', $rule)[0]) === false) {
                return $this->error("العنوان ده مش صحيح: {$rule}", 422);
            }
        }
        $require2fa = !empty($in['require_2fa']);
        if ($require2fa && empty($this->q('SELECT two_factor_enabled FROM users WHERE id = ?', [(int) $this->user['id']])[0]['two_factor_enabled'])) {
            return $this->error('فعّل التحقق بخطوتين على حسابك انت الأول (من إعدادات حسابك)، وبعدين اطلبه من الفريق.', 422);
        }
        $s = new SystemSettingsService();
        $s->set('admin_require_2fa', $require2fa ? '1' : '0');
        $s->set('admin_ip_allowlist', $list);
        $s->set('admin_require_reason', !empty($in['require_reason']) ? '1' : '0');
        AdminAccess::resetSecurityCache();
        return $this->success(AdminAccess::securitySettings(), 'اتحفظت إعدادات الأمان');
    }

    // ================================================================== التاجات والإجراءات الجماعية والفلاتر

    /** GET /api/admin/insights/tags */
    public function tags(array $params = []): array
    {
        return $this->success(['tags' => $this->q('SELECT tag, COUNT(*) n FROM admin_user_tags GROUP BY tag ORDER BY n DESC, tag')]);
    }

    /** POST /api/admin/insights/users/{id}/tags {tag}  |  DELETE ...?tag= */
    public function userTag(array $params = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        $tag = self::cleanTag((string) ($this->input()['tag'] ?? $this->get('tag', '')));
        if ($tag === '') {
            return $this->error('اكتب التاج', 422);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'DELETE') {
            $this->db->query('DELETE FROM admin_user_tags WHERE user_id = ? AND tag = ?', [$id, $tag]);
        } else {
            $this->db->query('INSERT IGNORE INTO admin_user_tags (user_id, tag, created_by) VALUES (?, ?, ?)', [$id, $tag, (int) $this->user['id']]);
        }
        return $this->success(['tags' => $this->tagsFor([$id])[$id] ?? []]);
    }

    /** POST /api/admin/insights/users/bulk {ids[], action, tag?, title?, body?} */
    public function bulk(array $params = []): array
    {
        $in = $this->input();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
        if (!$ids || count($ids) > 500) {
            return $this->error('اختار من 1 لـ 500 عميل', 422);
        }
        $action = (string) ($in['action'] ?? '');
        $perm = ['tag' => 'users.notes', 'untag' => 'users.notes', 'suspend' => 'users.suspend', 'activate' => 'users.suspend', 'notify' => 'broadcast.send'][$action] ?? null;
        if ($perm === null) {
            return $this->error('إجراء مش معروف', 422);
        }
        if (!AdminAccess::can($this->user, $perm)) {
            return $this->error('ليس لديك صلاحية لهذا الإجراء', 403);
        }
        $in_ = implode(',', $ids);
        // مانلمسش حسابات الأدمن ولا حسابك
        $safe = array_map('intval', array_column($this->q("SELECT id FROM users WHERE id IN ($in_) AND role NOT IN ('admin','super_admin') AND id <> ?", [(int) $this->user['id']]), 'id'));
        if (!$safe) {
            return $this->error('مفيش عملاء ينفع يتطبق عليهم الإجراء', 422);
        }
        $list = implode(',', $safe);
        $n = count($safe);
        switch ($action) {
            case 'tag':
            case 'untag':
                $tag = self::cleanTag((string) ($in['tag'] ?? ''));
                if ($tag === '') {
                    return $this->error('اكتب التاج', 422);
                }
                foreach ($safe as $uid) {
                    $action === 'tag'
                        ? $this->db->query('INSERT IGNORE INTO admin_user_tags (user_id, tag, created_by) VALUES (?, ?, ?)', [$uid, $tag, (int) $this->user['id']])
                        : $this->db->query('DELETE FROM admin_user_tags WHERE user_id = ? AND tag = ?', [$uid, $tag]);
                }
                $msg = ($action === 'tag' ? 'اتضاف' : 'اتشال') . " التاج لـ {$n} عميل";
                break;
            case 'suspend':
            case 'activate':
                $this->db->exec("UPDATE users SET status = ? WHERE id IN ($list)", [$action === 'suspend' ? 'suspended' : 'active']);
                $msg = ($action === 'suspend' ? 'اتوقف' : 'اتفعّل') . " {$n} عميل";
                break;
            case 'notify':
                $title = trim((string) ($in['title'] ?? ''));
                $body = trim((string) ($in['body'] ?? ''));
                if ($title === '' || $body === '') {
                    return $this->error('اكتب عنوان ونص الإشعار', 422);
                }
                foreach ($safe as $uid) {
                    $this->db->query('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
                        [$uid, 'admin_message', mb_substr($title, 0, 190), mb_substr($body, 0, 2000), $in['link'] ?? null]);
                }
                $msg = "اتبعت الإشعار لـ {$n} عميل";
                break;
        }
        if (class_exists('ActivityLog')) {
            try {
                ActivityLog::record('admin', 'admin.bulk_' . $action, ['user_id' => (int) $this->user['id'], 'subject_type' => 'users', 'subject_id' => 0, 'count' => $n]);
            } catch (Throwable $e) {
            error_log('[AdminInsightsController] ignored error: ' . $e->getMessage());
            }
        }
        return $this->success(['affected' => $n, 'skipped' => count($ids) - $n], $msg ?? 'تم');
    }

    /** GET /api/admin/insights/views?page=  |  POST {page,name,filters}  |  DELETE /views/{id} */
    public function views(array $params = []): array
    {
        $me = (int) $this->user['id'];
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'DELETE') {
            $this->db->query('DELETE FROM admin_saved_views WHERE id = ? AND admin_id = ?', [(int) ($params['id'] ?? 0), $me]);
            return $this->success([], 'اتمسح');
        }
        if ($method === 'POST') {
            $in = $this->input();
            $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
            $page = preg_replace('/[^a-z0-9_-]/', '', (string) ($in['page'] ?? ''));
            if ($name === '' || $page === '') {
                return $this->error('اكتب اسم للفلتر', 422);
            }
            $id = $this->db->query('INSERT INTO admin_saved_views (admin_id, page, name, filters) VALUES (?, ?, ?, ?)', [$me, $page, $name, json_encode($in['filters'] ?? new stdClass(), JSON_UNESCAPED_UNICODE)]);
            return $this->success(['id' => (int) $id], 'اتحفظ الفلتر');
        }
        $rows = $this->q('SELECT id, page, name, filters FROM admin_saved_views WHERE admin_id = ? AND page = ? ORDER BY id', [$me, (string) $this->get('page', '')]);
        foreach ($rows as &$r) {
            $r['filters'] = json_decode((string) $r['filters'], true) ?: [];
        }
        return $this->success(['views' => $rows]);
    }

    // ================================================================== الحالة العامة وسجل التحديثات (للأدمن)

    /** GET /api/admin/insights/statuspage */
    public function statusAdmin(array $params = []): array
    {
        return $this->success([
            'incidents' => $this->q('SELECT * FROM status_incidents ORDER BY status = \'resolved\', started_at DESC LIMIT 50'),
            'changelog' => $this->q('SELECT * FROM changelog_entries ORDER BY COALESCE(published_at, created_at) DESC LIMIT 100'),
            'components' => self::components(),
            'public_status' => self::publicStatus(),
        ]);
    }

    /** POST /api/admin/insights/incidents | PUT /incidents/{id} */
    public function saveIncident(array $params = []): array
    {
        $in = $this->input();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 255);
        if ($title === '') {
            return $this->error('اكتب عنوان', 422);
        }
        $status = in_array($in['status'] ?? '', ['investigating', 'identified', 'monitoring', 'resolved', 'scheduled'], true) ? $in['status'] : 'investigating';
        $sev = in_array($in['severity'] ?? '', ['minor', 'major', 'maintenance'], true) ? $in['severity'] : 'minor';
        $comp = array_key_exists($in['component'] ?? '', self::components()) ? $in['component'] : 'platform';
        $body = mb_substr((string) ($in['body'] ?? ''), 0, 5000);
        $id = (int) ($params['id'] ?? 0);
        if ($id) {
            $this->db->query("UPDATE status_incidents SET title=?, body=?, component=?, severity=?, status=?, resolved_at = IF(? = 'resolved', COALESCE(resolved_at, NOW()), NULL) WHERE id=?",
                [$title, $body, $comp, $sev, $status, $status, $id]);
        } else {
            $start = !empty($in['started_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['started_at'])) : date('Y-m-d H:i:s');
            $id = (int) $this->db->query('INSERT INTO status_incidents (title, body, component, severity, status, started_at, resolved_at, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$title, $body, $comp, $sev, $status, $start, $status === 'resolved' ? date('Y-m-d H:i:s') : null, (int) $this->user['id']]);
        }
        return $this->success(['id' => $id], 'اتحفظ');
    }

    /** POST /api/admin/insights/changelog | PUT /changelog/{id} | DELETE /changelog/{id} */
    public function saveChangelog(array $params = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
            $this->db->query('DELETE FROM changelog_entries WHERE id = ?', [$id]);
            return $this->success([], 'اتمسح');
        }
        $in = $this->input();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 255);
        if ($title === '') {
            return $this->error('اكتب عنوان', 422);
        }
        $cat = in_array($in['category'] ?? '', ['new', 'improved', 'fixed'], true) ? $in['category'] : 'new';
        $pub = !empty($in['is_published']) ? 1 : 0;
        $body = mb_substr((string) ($in['body'] ?? ''), 0, 8000);
        if ($id) {
            $this->db->query('UPDATE changelog_entries SET title=?, body=?, category=?, is_published=?, published_at = IF(? = 1, COALESCE(published_at, NOW()), NULL) WHERE id=?',
                [$title, $body, $cat, $pub, $pub, $id]);
        } else {
            $id = (int) $this->db->query('INSERT INTO changelog_entries (title, body, category, is_published, published_at, created_by) VALUES (?,?,?,?,?,?)',
                [$title, $body, $cat, $pub, $pub ? date('Y-m-d H:i:s') : null, (int) $this->user['id']]);
        }
        return $this->success(['id' => $id], $pub ? 'اتنشر' : 'اتحفظ كمسودة');
    }

    // ================================================================== المدونة

    /** GET /api/admin/insights/blog - كل المقالات (مسودات + منشورة) */
    public function blogList(array $params = []): array
    {
        try {
            $rows = $this->db->query('SELECT id, slug, lang, title, excerpt, body, cover_url, tags, meta_description, status, published_at, views, updated_at FROM blog_posts ORDER BY COALESCE(published_at, updated_at) DESC LIMIT 500');
        } catch (Throwable $e) {
            return $this->error('جدول المدونة لسه ماتعملش - اضغط "طبّق تحديثات قاعدة البيانات" من صفحة صحة المنصة', 409);
        }
        return $this->success(['items' => $rows]);
    }

    public static function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\p{M}+/u', '', $s); // التشكيل (الشدة/الفتحة) مش فاصل
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) $s);
        $s = trim((string) $s, '-');
        return mb_substr($s !== '' ? $s : 'post-' . date('YmdHis'), 0, 120);
    }

    /** POST /api/admin/insights/blog · PUT /insights/blog/{id} · DELETE /insights/blog/{id} */
    public function blogSave(array $params = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
            $this->db->query('DELETE FROM blog_posts WHERE id = ?', [$id]);
            return $this->success([], 'اتمسح');
        }
        $in = $this->input();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 220);
        if ($title === '') {
            return $this->error('اكتب عنوان المقال', 422);
        }
        $lang = ($in['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $slug = self::slugify((string) (($in['slug'] ?? '') !== '' ? $in['slug'] : $title));
        $cover = trim((string) ($in['cover_url'] ?? ''));
        if ($cover !== '' && !MarkdownLite::safeUrl($cover)) {
            return $this->error('رابط الصورة لازم يبدأ بـ https:// أو /', 422);
        }
        $status = ($in['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $pubAt = trim((string) ($in['published_at'] ?? ''));
        $pubAt = $pubAt !== '' && strtotime($pubAt) ? date('Y-m-d H:i:s', strtotime($pubAt)) : null;
        $dup = $this->db->query('SELECT id FROM blog_posts WHERE slug = ? AND lang = ? AND id <> ? LIMIT 1', [$slug, $lang, $id]);
        if ($dup) {
            return $this->error('فيه مقال تاني بنفس الرابط (slug) ونفس اللغة - غيّر الرابط', 422);
        }
        $vals = [
            $slug, $lang, $title,
            mb_substr(trim((string) ($in['excerpt'] ?? '')), 0, 400) ?: null,
            mb_substr((string) ($in['body'] ?? ''), 0, 200000),
            $cover ?: null,
            mb_substr(trim((string) ($in['tags'] ?? '')), 0, 255) ?: null,
            mb_substr(trim((string) ($in['meta_description'] ?? '')), 0, 300) ?: null,
            $status,
        ];
        if ($id) {
            $this->db->query('UPDATE blog_posts SET slug=?, lang=?, title=?, excerpt=?, body=?, cover_url=?, tags=?, meta_description=?, status=?, published_at = IF(? = \'published\', COALESCE(?, published_at, NOW()), published_at) WHERE id=?',
                array_merge($vals, [$status, $pubAt, $id]));
        } else {
            $id = (int) $this->db->query('INSERT INTO blog_posts (slug, lang, title, excerpt, body, cover_url, tags, meta_description, status, published_at, author_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                array_merge($vals, [$status === 'published' ? ($pubAt ?: date('Y-m-d H:i:s')) : $pubAt, (int) ($this->user['id'] ?? 0) ?: null]));
        }
        return $this->success(['id' => $id, 'slug' => $slug, 'url' => '/blog/' . $slug], $status === 'published' ? 'اتنشر' : 'اتحفظ كمسودة');
    }

    /** POST /api/admin/insights/blog/preview - معاينة الـ Markdown */
    public function blogPreview(array $params = []): array
    {
        $in = $this->input();
        return $this->success(['html' => MarkdownLite::toHtml(mb_substr((string) ($in['body'] ?? ''), 0, 200000))]);
    }

    // ================================================================== صفحات عامة

    public static function components(): array
    {
        return [
            'platform' => 'المنصة ولوحة التحكم',
            'ai' => 'الذكاء الاصطناعي',
            'reviews' => 'التقييمات (Google)',
            'messaging' => 'الشات وواتساب',
            'email' => 'البريد',
            'payments' => 'المدفوعات',
            'automation' => 'الأتمتة والمهام المجدولة',
        ];
    }

    /** حالة كل جزء للصفحة العامة (من غير أي تفاصيل داخلية) */
    public static function publicStatus(): array
    {
        $db = Database::getInstance();
        $comp = array_fill_keys(array_keys(self::components()), 'operational');
        try {
            $h = (new PlatformHealthService($db))->report()['sections'];
            $map = ['cron' => 'automation', 'mail' => 'email', 'queue' => 'automation', 'payments' => 'payments', 'server' => 'platform'];
            foreach ($map as $sec => $c) {
                foreach ($h[$sec]['checks'] ?? [] as $chk) {
                    // بنعرض بس الأعطال الحقيقية اللي العميل هيحس بيها
                    if ($chk['status'] === 'fail' && in_array($chk['key'], ['scheduler', 'mail_delivery', 'queue_lag', 'database', 'disk'], true)) {
                        $comp[$c] = 'degraded';
                    }
                }
            }
            foreach ($h['integrations']['items'] ?? [] as $i) {
                if ($i['configured'] && $i['last_check_ok'] === false && $i['fails_in_row'] >= 2) {
                    $c = in_array($i['key'], ['gemini', 'openai', 'anthropic', 'deepseek', 'kimi'], true) ? 'ai'
                        : (in_array($i['key'], ['google_business'], true) ? 'reviews'
                        : (in_array($i['key'], ['ultramsg', 'whatsapp', 'wati', 'meta_ads'], true) ? 'messaging'
                        : (in_array($i['key'], ['paymob', 'stripe', 'paypal'], true) ? 'payments' : (in_array($i['key'], ['smtp'], true) ? 'email' : null))));
                    if ($c) {
                        $comp[$c] = 'degraded';
                    }
                }
            }
        } catch (Throwable $e) {
        error_log('[AdminInsightsController] ignored error: ' . $e->getMessage());
        }
        $active = [];
        try {
            $active = $db->query("SELECT id, title, body, component, severity, status, started_at FROM status_incidents WHERE status <> 'resolved' ORDER BY started_at DESC");
            foreach ($active as $i) {
                if ($i['status'] === 'scheduled') {
                    continue;
                }
                $comp[$i['component']] = $i['severity'] === 'major' ? 'outage' : ($i['severity'] === 'maintenance' ? 'maintenance' : 'degraded');
            }
        } catch (Throwable $e) {
        error_log('[AdminInsightsController] ignored error: ' . $e->getMessage());
        }
        $worst = in_array('outage', $comp, true) ? 'outage' : (in_array('degraded', $comp, true) ? 'degraded' : (in_array('maintenance', $comp, true) ? 'maintenance' : 'operational'));
        return ['overall' => $worst, 'components' => $comp, 'active_incidents' => $active];
    }

    /** GET /api/public/status */
    public function publicStatusJson(array $params = []): array
    {
        header('Cache-Control: public, max-age=60');
        $s = self::publicStatus();
        $s['components'] = array_map(fn ($k, $v) => ['key' => $k, 'name' => self::components()[$k], 'status' => $v], array_keys($s['components']), $s['components']);
        return $this->success($s);
    }

    /** GET /status */
    public function statusPage(array $params = []): array
    {
        $s = self::publicStatus();
        $past = [];
        try {
            $past = $this->db->query("SELECT * FROM status_incidents WHERE status = 'resolved' AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY started_at DESC LIMIT 20");
        } catch (Throwable $e) {
        error_log('[AdminInsightsController] ignored error: ' . $e->getMessage());
        }
        $labels = ['operational' => ['شغّال', '#16a34a'], 'degraded' => ['بطء أو مشكلة جزئية', '#d97706'], 'outage' => ['متوقف', '#dc2626'], 'maintenance' => ['صيانة', '#2563eb']];
        $head = ['operational' => 'كل الخدمات شغّالة', 'degraded' => 'فيه مشكلة جزئية بنشتغل عليها', 'outage' => 'فيه عطل بنشتغل عليه دلوقتي', 'maintenance' => 'فيه صيانة جارية'];
        $statusWord = ['investigating' => 'بنحقق', 'identified' => 'عرفنا السبب', 'monitoring' => 'بنتابع بعد الإصلاح', 'resolved' => 'اتحل', 'scheduled' => 'صيانة مجدولة'];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        [$ow, $oc] = [$head[$s['overall']], $labels[$s['overall']][1]];
        $rows = '';
        foreach ($s['components'] as $k => $v) {
            $rows .= '<div class="row"><span>' . $e(self::components()[$k]) . '</span><span class="st" style="color:' . $labels[$v][1] . '">● ' . $e($labels[$v][0]) . '</span></div>';
        }
        $inc = '';
        foreach (array_merge($s['active_incidents'], $past) as $i) {
            $inc .= '<div class="inc"><b>' . $e($i['title']) . '</b><small>' . $e($statusWord[$i['status']] ?? $i['status']) . ' · ' . $e(substr((string) $i['started_at'], 0, 16)) . ' · ' . $e(self::components()[$i['component']] ?? '') . '</small>'
                . ($i['body'] ? '<p>' . nl2br($e($i['body'])) . '</p>' : '') . '</div>';
        }
        $app = $e(defined('APP_NAME') ? APP_NAME : 'Tourfecto');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        echo self::publicShell("حالة الخدمة | {$app}", '<header><a href="/" class="brand">' . $app . '</a><a href="/changelog">إيه الجديد</a></header>'
            . '<h1 class="hero" style="margin:0;border-color:' . $oc . '"><span class="dot" style="background:' . $oc . '"></span>' . $e($ow) . '</h1>'
            . '<section><h2>الخدمات</h2>' . $rows . '</section>'
            . '<section><h2>الحوادث (آخر 30 يوم)</h2>' . ($inc ?: '<p class="muted">مفيش حوادث آخر 30 يوم ✓</p>') . '</section>'
            . '<footer>آخر تحديث: ' . date('Y-m-d H:i') . ' · بيتحدّث كل دقيقة</footer>');
        exit;
    }

    /** GET /changelog */
    public function changelogPage(array $params = []): array
    {
        $rows = [];
        try {
            $rows = $this->db->query('SELECT * FROM changelog_entries WHERE is_published = 1 ORDER BY published_at DESC LIMIT 100');
        } catch (Throwable $e) {
        error_log('[AdminInsightsController] ignored error: ' . $e->getMessage());
        }
        $cat = ['new' => ['جديد', '#2563eb'], 'improved' => ['تحسين', '#16a34a'], 'fixed' => ['إصلاح', '#d97706']];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '';
        $month = '';
        foreach ($rows as $r) {
            $m = substr((string) $r['published_at'], 0, 7);
            if ($m !== $month) {
                $html .= '<h2>' . $e($m) . '</h2>';
                $month = $m;
            }
            [$l, $c] = $cat[$r['category']] ?? $cat['new'];
            $html .= '<div class="inc"><span class="tag" style="background:' . $c . '1a;color:' . $c . '">' . $l . '</span> <b>' . $e($r['title']) . '</b><small>' . $e(substr((string) $r['published_at'], 0, 10)) . '</small>'
                . ($r['body'] ? '<p>' . nl2br($e($r['body'])) . '</p>' : '') . '</div>';
        }
        $app = $e(defined('APP_NAME') ? APP_NAME : 'Tourfecto');
        header('Content-Type: text/html; charset=utf-8');
        echo self::publicShell("إيه الجديد | {$app}", '<header><a href="/" class="brand">' . $app . '</a><a href="/status">حالة الخدمة</a></header>'
            . '<h1 class="hero" style="margin:0"><span>إيه الجديد في</span> ' . $app . '</h1>' . ($html ?: '<p class="muted">لسه مفيش تحديثات منشورة.</p>'));
        exit;
    }

    private static function publicShell(string $title, string $body): string
    {
        // الصفحتين عامتين لزوار من كل الدول: الاتجاه واللغة حسب لغة الزائر (النصوص بتتترجم بالقاموس)
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        return '<!DOCTYPE html><html lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '" dir="' . $dir . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title><meta name="description" content="' . htmlspecialchars($title) . '">'
            . '<style>:root{--bg:#f6f7fb;--card:#fff;--tx:#0f172a;--mu:#64748b;--ln:#e2e8f0}@media(prefers-color-scheme:dark){:root{--bg:#0b1020;--card:#121a2e;--tx:#e8ecf3;--mu:#94a3b8;--ln:#24304a}}'
            . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font-family:"Readex Pro","Segoe UI",Tahoma,sans-serif;line-height:1.7}'
            . '.w{max-width:760px;margin:0 auto;padding:24px 16px 48px}header{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px}header a{color:var(--mu);text-decoration:none;font-size:14px}'
            . '.brand{font-weight:700;font-size:20px!important;color:var(--tx)!important}.hero{background:var(--card);border:1px solid var(--ln);border-inline-start:5px solid #3b5bfd;border-radius:14px;padding:18px 20px;font-size:18px;font-weight:600;display:flex;align-items:center;gap:10px}'
            . '.dot{width:12px;height:12px;border-radius:50%;display:inline-block}section{background:var(--card);border:1px solid var(--ln);border-radius:14px;padding:6px 20px 14px;margin-top:16px}'
            . 'h2{font-size:15px;margin:16px 0 8px}.row{display:flex;justify-content:space-between;padding:11px 0;border-top:1px solid var(--ln);font-size:14.5px}.row:first-of-type{border-top:0}.st{font-size:13.5px;font-weight:600}'
            . '.inc{background:var(--card);border:1px solid var(--ln);border-radius:12px;padding:12px 16px;margin:10px 0}section .inc{border:0;border-top:1px solid var(--ln);border-radius:0;padding:12px 0;margin:0}'
            . '.inc small{display:block;color:var(--mu);font-size:12.5px}.inc p{margin:6px 0 0;font-size:14px}.tag{font-size:12px;padding:2px 8px;border-radius:6px;font-weight:600}.muted{color:var(--mu)}footer{color:var(--mu);font-size:12px;text-align:center;margin-top:22px}</style>'
            . '</head><body><div class="w">' . $body . '</div></body></html>';
    }

    // ================================================================== أدوات

    private function input(): array
    {
        $raw = @file_get_contents('php://input');
        $j = $raw ? json_decode($raw, true) : null;
        return is_array($j) ? $j : (is_array($_POST) ? $_POST : []);
    }

    private function q(string $sql, array $p = []): array
    {
        try {
            return $this->db->query($sql, $p) ?: [];
        } catch (Throwable $e) {
            Logger::warning('AdminInsights query failed', ['error' => CustomerError::message($e->getMessage(), 500)]);
            return [];
        }
    }

    private function tagsFor(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->q('SELECT user_id, tag FROM admin_user_tags WHERE user_id IN (' . implode(',', $ids) . ') ORDER BY tag') as $r) {
            $out[(int) $r['user_id']][] = $r['tag'];
        }
        return $out;
    }

    private static function cleanTag(string $t): string
    {
        return mb_substr(trim(preg_replace('/[<>"\'\\\\]/u', '', $t)), 0, 40);
    }
}
