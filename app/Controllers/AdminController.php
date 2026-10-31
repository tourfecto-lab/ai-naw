<?php

/**
 * Tourfecto - Admin Controller
 * إدارة المستخدمين والاشتراكات والنظام (محمي بـ AuthMiddleware + AdminMiddleware)
 * @version 1.0.0
 */

class AdminController extends Controller
{
    /** GET /admin */
    public function index(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('overview');
        exit;
    }

    /** GET /admin/platform - نظرة شاملة على كل خدمات وعملاء المنصة */
    public function platform(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('platform');
        exit;
    }

    /** GET /admin/users و GET /api/admin/users */
    public function users(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('users');
        exit;
    }

    public function getUsers(array $params = []): array
    {
        try {
            // جدول users مفيهوش عمود is_active - الحالة الحقيقية في عمود status
            // (active/suspended/pending/cancelled). كنا بنعتمد على is_active
            // فكل العملاء كانوا بيظهروا "موقوف". دلوقتي بنحسبها من status،
            // ومعاها بيانات تشغيلية (آخر دخول، الباقة، المواقع، 2FA) في استعلام واحد.
            $users = $this->db->query(
                "SELECT u.id, u.email, u.company_name, u.display_name, u.role, u.status, u.country_code,
                        u.two_factor_enabled, u.email_verified_at, u.created_at,
                        (u.status = 'active') AS is_active,
                        (SELECT MAX(lh.created_at) FROM login_history lh WHERE lh.user_id = u.id AND lh.status = 'success') AS last_login_at,
                        (SELECT sp.name FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
                          WHERE s.user_id = u.id AND s.status IN ('active','trialing','past_due') ORDER BY s.id DESC LIMIT 1) AS plan_name,
                        (SELECT COUNT(*) FROM websites w WHERE w.user_id = u.id) AS websites_count
                 FROM users u WHERE u.deleted_at IS NULL ORDER BY u.created_at DESC LIMIT 500"
            );
            foreach ($users as &$row) {
                $row['is_active'] = (int) $row['is_active'];
            }
            unset($row);
            return $this->success(['users' => $users]);
        } catch (Exception $e) {
            Logger::error('Admin getUsers Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المستخدمين', 500);
        }
    }

    /**
     * GET /admin/export/users - تصدير قائمة كل العملاء لملف Excel (CSV)
     * حقيقي قابل للتحميل المباشر.
     */
    public function exportUsers(array $params = []): array
    {
        if (!$this->isAuthenticated() || !in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(403);
            echo 'غير مصرح';
            exit;
        }

        try {
            $rows = $this->db->query(
                "SELECT id, company_name, email, role, (status = 'active') AS is_active, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC"
            );

            $output = fopen('php://temp', 'r+');
            fputcsv($output, ['#', 'اسم الشركة', 'البريد الإلكتروني', 'الدور', 'الحالة', 'تاريخ التسجيل']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['id'], $row['company_name'], $row['email'], $row['role'],
                    $row['is_active'] ? 'نشط' : 'موقوف', $row['created_at'],
                ]);
            }
            rewind($output);
            $csv = "\xEF\xBB\xBF" . stream_get_contents($output);
            fclose($output);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tourfecto-customers-' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            echo $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('Admin exportUsers Error', ['message' => $e->getMessage()]);
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
            echo 'تعذر التصدير';
            exit;
        }
    }

    /**
     * GET /admin/export/subscriptions - تصدير قائمة كل الاشتراكات لملف Excel (CSV).
     */
    public function exportSubscriptions(array $params = []): array
    {
        if (!$this->isAuthenticated() || !in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(403);
            echo 'غير مصرح';
            exit;
        }

        try {
            $rows = $this->db->query(
                "SELECT s.id, u.company_name, u.email, sp.name AS plan_name, sp.billing_cycle AS plan_type, sp.price, sp.currency, s.status, s.current_period_end AS expiry_date, s.created_at
                 FROM subscriptions s
                 LEFT JOIN users u ON u.id = s.user_id
                 LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
                 ORDER BY s.created_at DESC"
            );

            $output = fopen('php://temp', 'r+');
            fputcsv($output, ['#', 'اسم الشركة', 'البريد الإلكتروني', 'الباقة', 'النوع', 'السعر', 'العملة', 'الحالة', 'تاريخ الانتهاء', 'تاريخ الإنشاء']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['id'], $row['company_name'], $row['email'], $row['plan_name'], $row['plan_type'],
                    $row['price'], $row['currency'], $row['status'], $row['expiry_date'], $row['created_at'],
                ]);
            }
            rewind($output);
            $csv = "\xEF\xBB\xBF" . stream_get_contents($output);
            fclose($output);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tourfecto-subscriptions-' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            echo $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('Admin exportSubscriptions Error', ['message' => $e->getMessage()]);
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
            echo 'تعذر التصدير';
            exit;
        }
    }

    /** GET /admin/users/{id} و GET /api/admin/users/{id} */
    public function userDetail(array $params): array
    {
        return $this->getUserById($params);
    }

    /**
     * تصحيح: كانت هذه الدالة اسمها getUser(array $params) وده بيعمل Override
     * لـ Controller::getUser(): array (بدون أي معاملات) بتوقيع غير متوافق،
     * وده PHP Fatal Error وقت تحميل الكلاس (Declaration ... must be compatible)
     * يوقف صفحة /admin بالكامل. الحل: اسم مختلف تمامًا عن دالة الأب.
     */
    public function getUserById(array $params): array
    {
        $userModel = new User();
        $user = $userModel->find((int) ($params['id'] ?? 0));

        if (!$user) {
            return $this->error('المستخدم غير موجود', 404);
        }

        $userId = (int) $user->getAttribute('id');

        // تصحيح: كانت الاستجابة بترجع بيانات المستخدم الأساسية بس من
        // غير أي رؤية حقيقية لنشاطه الفعلي - الأدمن معندوش أي فكرة عن
        // مواقعه، اشتراكه، أو رصيد محفظته من غير ما يفتح كذا صفحة
        // منفصلة. دلوقتي كل حاجة في مكان واحد.
        try {
            $websites = (new Website())->where(['user_id' => $userId], ['created_at' => 'DESC']);
            $websitesData = array_map(fn ($w) => [
                'id' => $w->getAttribute('id'),
                'main_url' => $w->getAttribute('main_url'),
                'company_name' => $w->getAttribute('company_name'),
                'is_verified' => $w->getAttribute('is_verified'),
            ], $websites);
        } catch (Exception $e) {
            $websitesData = [];
        }

        try {
            $subscription = Subscription::activeSubscriptionRow($userId);
        } catch (Exception $e) {
            $subscription = null;
        }

        try {
            $walletBalance = (new WalletService())->getBalance($userId);
        } catch (Exception $e) {
            $walletBalance = 0;
        }

        // خط سير العميل: نشاطه الفعلي بالترتيب الزمني من activity_logs
        // (السجل الموحّد لكل الموديولات) - آخر 30 حدث.
        try {
            $journeyRows = $this->db->query(
                "SELECT module, action, subject_type, meta, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 30",
                [$userId]
            );
        } catch (Exception $e) {
            $journeyRows = [];
        }

        // استثناءات الميزات الخاصة بالعميل ده (لو فيه)
        try {
            $featureOverrides = (new FeatureFlagService())->getUserOverrides($userId);
        } catch (Exception $e) {
            $featureOverrides = [];
        }

        $userArray = $user->toArray();
        $userArray['is_active'] = (($userArray['status'] ?? 'active') === 'active') ? 1 : 0;

        return $this->success([
            'user' => $userArray,
            'websites' => $websitesData,
            'subscription' => $subscription,
            'wallet_balance' => $walletBalance,
            'journey' => $journeyRows,
            'feature_overrides' => $featureOverrides,
        ]);
    }

    /** PUT /api/admin/users/{id} */
    public function updateUser(array $params): array
    {
        $userModel = new User();
        $user = $userModel->find((int) ($params['id'] ?? 0));

        if (!$user) {
            return $this->error('المستخدم غير موجود', 404);
        }

        $actorId = (int) ($this->user['id'] ?? 0);
        $actorIsSuper = ($this->user['role'] ?? '') === 'super_admin';
        $targetId = (int) $user->getAttribute('id');
        $targetRole = (string) $user->getAttribute('role');

        if (class_exists('AdminAccess') && AdminAccess::isPrimaryOwner($user->toArray() + ['id' => $targetId]) && $targetId !== $actorId) {
            return $this->error('ده حساب المالك الأساسي للمنصة - محدش يقدر يعدّله', 403);
        }
        // حماية: أي admin عادي ميقدرش يعدّل حساب admin/super_admin تاني (تغيير الإيميل
        // + "نسيت كلمة المرور" = استيلاء على الحساب)، ولا يغيّر دور نفسه.
        if (in_array($targetRole, ['admin', 'super_admin'], true) && !$actorIsSuper && $targetId !== $actorId) {
            return $this->error('تعديل حسابات الأدمن متاح لـ Super Admin فقط', 403);
        }
        if ($this->has('role') && $targetId === $actorId && $this->get('role') !== $targetRole) {
            return $this->error('لا يمكنك تغيير دور حسابك بنفسك', 403);
        }

        if ($this->has('is_active')) {
            if ($targetId === $actorId && !((int) $this->get('is_active'))) {
                return $this->error('لا يمكنك إيقاف حسابك الحالي', 403);
            }
            $user->setAttribute('status', ((int) $this->get('is_active')) ? 'active' : 'suspended');
        }

        $allowedRoles = ['super_admin', 'admin', 'agency_owner', 'manager', 'agent', 'client', 'user'];

        foreach (['company_name', 'email', 'role'] as $field) {
            if (!$this->has($field)) {
                continue;
            }
            $value = $this->get($field);

            if ($field === 'role') {
                if (!is_string($value) || !in_array($value, $allowedRoles, true)) {
                    return $this->error('الدور غير صالح', 422);
                }
                // رفع الصلاحيات لـ admin/super_admin (أو تنزيل أدمن) لـ super_admin بس
                if ((in_array($value, ['admin', 'super_admin'], true) || in_array($targetRole, ['admin', 'super_admin'], true)) && !$actorIsSuper) {
                    return $this->error('تعيين/تغيير دور Admin/Super Admin متاح لـ Super Admin بس', 403);
                }
            } elseif ($field === 'email') {
                $value = strtolower(trim((string) $value));
                if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 191) {
                    return $this->error('البريد الإلكتروني غير صالح', 422);
                }
                if ($value !== strtolower((string) $user->getAttribute('email'))) {
                    $dup = $this->db->query('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$value, $targetId]);
                    if (!empty($dup)) {
                        return $this->error('البريد الإلكتروني مستخدم بالفعل', 409);
                    }
                }
            } else {
                $value = trim((string) $value);
                if (mb_strlen($value) > 255) {
                    return $this->error('الاسم طويل جدًا', 422);
                }
            }
            $user->setAttribute($field, $value);
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث المستخدم', 500);
        }

        ActivityLog::record('admin', 'admin.user_updated', [
            'user_id' => (int) ($this->user['id'] ?? 0),
            'subject_type' => 'users', 'subject_id' => (int) $user->getAttribute('id'),
            'meta' => ['fields' => array_intersect(['company_name', 'email', 'role', 'is_active'], array_keys($this->data ?? [])) ],
        ]);

        return $this->success(['user' => $user->toArray()], 'تم تحديث المستخدم');
    }

    /** GET /api/admin/faq */
    /** PUT /api/admin/branding - super_admin بس */
    public function updateBranding(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('الصفحة دي متاحة للـ Super Admin بس', 403);
        }
        try {
            $service = new SystemSettingsService();
            $allowedKeys = ['site_name', 'site_logo_height', 'contact_phone', 'contact_email', 'site_address'];
            foreach ($allowedKeys as $key) {
                if ($this->get($key) !== null) {
                    $service->set($key, (string) $this->get($key));
                }
            }
            $this->log('Admin Updated Branding', []);
            return $this->success([], 'تم تحديث هوية الموقع');
        } catch (Exception $e) {
            Logger::error('Admin updateBranding Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** POST /api/admin/branding/logo - رفع لوجو الموقع - super_admin بس */
    public function uploadLogo(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('الصفحة دي متاحة للـ Super Admin بس', 403);
        }
        try {
            $service = new SystemSettingsService();
            $oldLogo = $service->get('site_logo_url', '');

            $handler = new SiteLogoUploadHandler();
            $result = $handler->upload($_FILES['logo'] ?? [], $oldLogo ?: null);

            if (!$result['success']) {
                return $this->error($result['error'], 422);
            }

            $service->set('site_logo_url', $result['url']);
            $this->log('Admin Uploaded Site Logo', []);

            return $this->success(['url' => $result['url']], 'تم رفع اللوجو');
        } catch (Exception $e) {
            Logger::error('Admin uploadLogo Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر رفع اللوجو', 500);
        }
    }

    /**
     * POST /api/admin/branding/favicon - رفع أيقونة تبويب المتصفح (Favicon) - super_admin بس
     * نفس منطق uploadLogo بالظبط، بس بيخزّن في site_favicon_url وبيتفرض
     * png/ico/svg فقط (jpg/webp مش مدعومين رسميًا كـ favicon في كل المتصفحات).
     */
    public function uploadFavicon(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('الصفحة دي متاحة للـ Super Admin بس', 403);
        }
        try {
            $service = new SystemSettingsService();
            $oldFavicon = $service->get('site_favicon_url', '');

            $handler = new SiteLogoUploadHandler();
            $result = $handler->upload($_FILES['favicon'] ?? [], $oldFavicon ?: null);

            if (!$result['success']) {
                return $this->error($result['error'], 422);
            }

            $service->set('site_favicon_url', $result['url']);
            $this->log('Admin Uploaded Site Favicon', []);

            return $this->success(['url' => $result['url']], 'تم رفع أيقونة التبويب');
        } catch (Exception $e) {
            Logger::error('Admin uploadFavicon Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر رفع أيقونة التبويب', 500);
        }
    }

    /** GET /api/admin/users/{id}/grant-plan: الباقات المتاحة للمنحة + اشتراك العميل الحالي */
    public function grantPlanOptions(array $params = []): array
    {
        try {
            $g = new ComplimentaryGrant($this->db);
            $uid = (int) ($params['id'] ?? 0);
            return $this->success(['plans' => $g->plans(), 'is_test' => TestAccounts::isTest($uid)] + $g->status($uid));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin grant options failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر التحميل', 500);
        }
    }

    /** POST /api/admin/users/{id}/grant-plan {plan, days}: يفتح كل خدمات الباقة مجانًا للمدة دي */
    public function grantPlan(array $params = []): array
    {
        try {
            $uid = (int) ($params['id'] ?? 0);
            $r = (new ComplimentaryGrant($this->db))->grant($uid, (string) $this->get('plan', ''), (int) $this->get('days', 30), (int) ($this->user['id'] ?? 0));
            $this->log('Admin granted complimentary plan', ['user_id' => $uid, 'reason' => mb_substr(trim((string) $this->get('reason', '')), 0, 500)] + $r);
            return $this->success($r, 'اتفعّلت الباقة مجانًا لحد ' . substr($r['ends_at'], 0, 10));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin grant plan failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر تفعيل المنحة', 500);
        }
    }

    /** POST /api/admin/users/{id}/grant-plan/end: يوقف المنحة (الاشتراك المدفوع مابيتلمسش) */
    public function endGrantPlan(array $params = []): array
    {
        try {
            $uid = (int) ($params['id'] ?? 0);
            $n = (new ComplimentaryGrant($this->db))->endGrants($uid);
            $this->log('Admin ended complimentary plan', ['user_id' => $uid, 'ended' => $n]);
            return $this->success(['ended' => $n], $n ? 'اتوقفت المنحة' : 'مفيش منحة شغالة');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('تعذر الإيقاف', 500);
        }
    }

    /** POST /api/admin/users/{id}/feature-overrides */
    public function addUserFeatureOverride(array $params = []): array
    {
        try {
            $userId = (int) ($params['id'] ?? 0);
            $featureKey = (string) $this->get('feature_key', '');
            if (!$userId || !$featureKey) {
                return $this->error('بيانات ناقصة', 422);
            }
            $service = new FeatureFlagService();
            $service->setUserOverride($userId, $featureKey, (bool) $this->get('is_enabled', true));
            $this->log('Admin Set User Feature Override', ['user_id' => $userId, 'feature_key' => $featureKey]);
            return $this->success([], 'تم الحفظ');
        } catch (Exception $e) {
            Logger::error('Admin addUserFeatureOverride Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** DELETE /api/admin/users/{id}/feature-overrides/{key} */
    public function removeUserFeatureOverride(array $params = []): array
    {
        try {
            $userId = (int) ($params['id'] ?? 0);
            $featureKey = (string) ($params['key'] ?? '');
            (new FeatureFlagService())->removeUserOverride($userId, $featureKey);
            $this->log('Admin Removed User Feature Override', ['user_id' => $userId, 'feature_key' => $featureKey]);
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            Logger::error('Admin removeUserFeatureOverride Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحذف', 500);
        }
    }

    /** GET /api/admin/new-features-stats */
    public function newFeaturesStats(array $params = []): array
    {
        try {
            $stats = ['sites_total' => 0, 'sites_published' => 0, 'sites_draft' => 0,
                      'ai_conversations' => 0, 'ai_messages' => 0,
                      'review_requests_total' => 0, 'review_requests_sent' => 0, 'review_requests_reviewed' => 0];

            if (class_exists('GeneratedWebsite')) {
                $rows = $this->db->query("SELECT status, COUNT(*) AS c FROM generated_websites GROUP BY status");
                foreach ($rows as $row) {
                    $stats['sites_total'] += (int) $row['c'];
                    if ($row['status'] === 'published') {
                        $stats['sites_published'] = (int) $row['c'];
                    }
                    if ($row['status'] === 'draft') {
                        $stats['sites_draft'] = (int) $row['c'];
                    }
                }
            }

            try {
                $stats['ai_conversations'] = (int) ($this->db->query("SELECT COUNT(*) AS c FROM ai_assistant_conversations")[0]['c'] ?? 0);
                $stats['ai_messages'] = (int) ($this->db->query("SELECT COUNT(*) AS c FROM ai_assistant_messages")[0]['c'] ?? 0);
            } catch (Exception $e) {
            error_log('[AdminController] ignored error: ' . $e->getMessage());
            }

            try {
                $rrRows = $this->db->query("SELECT status, COUNT(*) AS c FROM review_requests GROUP BY status");
                foreach ($rrRows as $row) {
                    $stats['review_requests_total'] += (int) $row['c'];
                    if (in_array($row['status'], ['sent', 'reminded', 'reviewed'], true)) {
                        $stats['review_requests_sent'] += (int) $row['c'];
                    }
                    if ($row['status'] === 'reviewed') {
                        $stats['review_requests_reviewed'] = (int) $row['c'];
                    }
                }
            } catch (Exception $e) {
            error_log('[AdminController] ignored error: ' . $e->getMessage());
            }

            return $this->success(['stats' => $stats]);
        } catch (Exception $e) {
            Logger::error('Admin newFeaturesStats Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الإحصائيات', 500);
        }
    }

    /** GET /api/admin/ai-usage-stats */
    public function aiUsageStats(array $params = []): array
    {
        try {
            $stats = [
                'total_requests' => 0, 'total_tokens_input' => 0, 'total_tokens_output' => 0,
                'total_tokens' => 0, 'total_cost_usd' => 0, 'success_count' => 0, 'failed_count' => 0,
                'success_rate' => 0, 'by_feature' => [], 'by_provider' => [],
            ];

            try {
                $summary = $this->db->query(
                    "SELECT COUNT(*) AS total,
                            COALESCE(SUM(tokens_input), 0) AS tokens_input,
                            COALESCE(SUM(tokens_output), 0) AS tokens_output,
                            COALESCE(SUM(tokens_total), 0) AS tokens_total,
                            COALESCE(SUM(estimated_cost_usd), 0) AS cost,
                            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS ok,
                            SUM(CASE WHEN status != 'success' THEN 1 ELSE 0 END) AS bad
                     FROM ai_usage_logs"
                )[0] ?? [];

                $stats['total_requests'] = (int) ($summary['total'] ?? 0);
                $stats['total_tokens_input'] = (int) ($summary['tokens_input'] ?? 0);
                $stats['total_tokens_output'] = (int) ($summary['tokens_output'] ?? 0);
                $stats['total_tokens'] = (int) ($summary['tokens_total'] ?? 0);
                $stats['total_cost_usd'] = round((float) ($summary['cost'] ?? 0), 4);
                $stats['success_count'] = (int) ($summary['ok'] ?? 0);
                $stats['failed_count'] = (int) ($summary['bad'] ?? 0);
                $total = $stats['total_requests'];
                $stats['success_rate'] = $total > 0 ? round(($stats['success_count'] / $total) * 100, 1) : 0;

                $stats['by_feature'] = $this->db->query(
                    "SELECT feature, COUNT(*) AS count, COALESCE(SUM(tokens_total), 0) AS tokens
                     FROM ai_usage_logs GROUP BY feature ORDER BY count DESC LIMIT 20"
                );
                $stats['by_provider'] = $this->db->query(
                    "SELECT provider, COUNT(*) AS count, COALESCE(SUM(tokens_total), 0) AS tokens
                     FROM ai_usage_logs GROUP BY provider ORDER BY count DESC LIMIT 10"
                );
            } catch (Exception $e) {
                Logger::error('Admin aiUsageStats usage_logs query failed', ['message' => $e->getMessage()]);
            }

            try {
                $credits = $this->db->query(
                    "SELECT COALESCE(SUM(usage_ai_analysis_count), 0) AS ai_analysis,
                            COALESCE(SUM(usage_ai_message_count), 0) AS ai_messages,
                            COALESCE(SUM(usage_review_reply_count), 0) AS review_replies
                     FROM subscriptions"
                )[0] ?? [];
                $stats['subscription_usage'] = [
                    'ai_analysis' => (int) ($credits['ai_analysis'] ?? 0),
                    'ai_messages' => (int) ($credits['ai_messages'] ?? 0),
                    'review_replies' => (int) ($credits['review_replies'] ?? 0),
                ];
            } catch (Exception $e) {
                $stats['subscription_usage'] = ['ai_analysis' => 0, 'ai_messages' => 0, 'review_replies' => 0];
            }

            return $this->success(['stats' => $stats]);
        } catch (Exception $e) {
            Logger::error('Admin aiUsageStats Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب إحصائيات الاستخدام', 500);
        }
    }

    /** GET /api/admin/features */
    public function listFeatures(array $params = []): array
    {
        try {
            $service = new FeatureFlagService();
            return $this->success(['features' => $service->getAllGlobal()]);
        } catch (Exception $e) {
            Logger::error('Admin listFeatures Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الميزات', 500);
        }
    }

    /** PUT /api/admin/features/{key} */
    public function updateFeature(array $params = []): array
    {
        try {
            $service = new FeatureFlagService();
            $service->setGlobal((string) ($params['key'] ?? ''), (bool) $this->get('is_enabled', true));
            $this->log('Admin Toggled Feature', ['feature_key' => $params['key'] ?? null]);
            return $this->success([], 'تم التحديث');
        } catch (Exception $e) {
            Logger::error('Admin updateFeature Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** PUT /api/admin/legal-content - super_admin بس */
    public function updateLegalContent(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('الصفحة دي متاحة للـ Super Admin بس', 403);
        }
        try {
            $service = new SystemSettingsService();
            if ($this->get('terms_content') !== null) {
                $service->set('terms_content', (string) $this->get('terms_content'));
            }
            if ($this->get('privacy_content') !== null) {
                $service->set('privacy_content', (string) $this->get('privacy_content'));
            }
            if ($this->get('refund_policy_content') !== null) {
                $service->set('refund_policy_content', (string) $this->get('refund_policy_content'));
            }
            $this->log('Admin Updated Legal Content', []);
            return $this->success([], 'تم الحفظ');
        } catch (Exception $e) {
            Logger::error('Admin updateLegalContent Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** GET /api/admin/system-settings - super_admin بس، مش أي أدمن عادي (بيانات حساسة جدًا) */
    public function getSystemSettings(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('الصفحة دي متاحة للـ Super Admin بس', 403);
        }
        try {
            $service = new SystemSettingsService();
            return $this->success(['settings' => $service->getAllForAdmin(),'seo_provider_budget'=>(new DataForSeoClient())->budgetSummary($this->db)]);
        } catch (Exception $e) {
            Logger::error('Admin getSystemSettings Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الإعدادات', 500);
        }
    }

    /** PUT /api/admin/system-settings - super_admin بس */
    public function updateSystemSettings(array $params = []): array
    {
        if (($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('الصفحة دي متاحة للـ Super Admin بس', 403);
        }
        try {
            $service = new SystemSettingsService();
            $allowedKeys = [
                'serp_local_enabled', 'serp_backlinks_enabled', 'serp_login', 'serp_password', 'serp_enabled', 'serp_monthly_budget_usd', 'serp_signup_daily_usd', 'serp_device',
                'geo_enabled', 'geo_monthly_budget_usd', 'geo_openai_enabled', 'geo_openai_api_key', 'geo_gemini_enabled', 'geo_gemini_api_key', 'geo_perplexity_enabled', 'geo_perplexity_api_key',
                'gemini_api_key', 'google_client_id', 'google_client_secret', 'google_maps_api_key',
                'meta_app_id', 'meta_app_secret', 'support_whatsapp_number',
                'meta_login_config_id', 'meta_signin_config_id', 'meta_wa_config_id', 'meta_oauth_scopes',
                'wa_qr_enabled', 'wa_qr_base_url', 'wa_qr_api_key', 'tiktok_client_key', 'tiktok_client_secret', 'stripe_connect_client_id', 'stripe_platform_secret_key',
                'mail_host', 'mail_port', 'mail_username', 'mail_password',
                'mail_encryption', 'mail_from_address', 'mail_from_name',
                'oauth_microsoft_client_id', 'oauth_microsoft_client_secret', 'oauth_microsoft_tenant',
                'oauth_apple_client_id', 'oauth_apple_team_id', 'oauth_apple_key_id', 'oauth_apple_private_key',
            ];
            foreach(['geo_enabled','geo_openai_enabled','geo_gemini_enabled','geo_perplexity_enabled'] as $key)if($this->get($key)!==null&&!in_array((string)$this->get($key),['0','1'],true))return $this->error('قيمة تفعيل AI غير صالحة',422);
            $geoBudget=$this->get('geo_monthly_budget_usd');
            if($geoBudget!==null&&(!is_numeric($geoBudget)||!is_finite((float)$geoBudget)||(float)$geoBudget<0||(float)$geoBudget>1000000))return $this->error('ميزانية AI غير صالحة',422);
            foreach(['serp_local_enabled','serp_backlinks_enabled'] as $switch) if($this->get($switch)!==null&&!in_array((string)$this->get($switch),['0','1'],true))return $this->error('اختار تفعيل أو إيقاف المصدر',422);
            if ($this->get('serp_enabled')!==null && !in_array((string)$this->get('serp_enabled'),['0','1'],true))
                return $this->error('اختار تفعيل أو إيقاف DataForSEO',422);
            if ($this->get('serp_device')!==null && !in_array((string)$this->get('serp_device'),['desktop','mobile'],true))
                return $this->error('اختار desktop أو mobile',422);
            $signupDaily=$this->get('serp_signup_daily_usd');
            if ($signupDaily!==null && $signupDaily!=='' && (!is_numeric($signupDaily) || (float)$signupDaily<0 || (float)$signupDaily>1000)) return $this->error('السقف اليومي لازم يكون رقم من 0 لـ 1000', 422);
            $seoBudget=$this->get('serp_monthly_budget_usd');
            if ($seoBudget!==null && (!is_numeric($seoBudget) || !is_finite((float)$seoBudget) || (float)$seoBudget<0 || (float)$seoBudget>1000000))
                return $this->error('راجع سقف DataForSEO بالدولار: من 0 لمليون',422);
            if ($this->get('wa_qr_enabled') !== null && !in_array((string) $this->get('wa_qr_enabled'), ['0', '1'], true)) {
                return $this->error('اختار تفعيل أو إيقاف ربط واتساب بـ QR', 422);
            }
            if ($this->get('stripe_connect_client_id') !== null && $this->get('stripe_connect_client_id') !== '' && !preg_match('/^ca_[A-Za-z0-9]+$/', (string) $this->get('stripe_connect_client_id'))) {
                return $this->error('Stripe Connect Client ID لازم يبدأ بـ ca_', 422);
            }
            if ($this->get('stripe_platform_secret_key') !== null && $this->get('stripe_platform_secret_key') !== '' && !preg_match('/^sk_(live|test)_[A-Za-z0-9]+$/', (string) $this->get('stripe_platform_secret_key'))) {
                return $this->error('مفتاح Stripe بتاع المنصة لازم يبدأ بـ sk_live_ أو sk_test_', 422);
            }
            $waUrl = trim((string) $this->get('wa_qr_base_url', ''));
            if ($waUrl !== '' && (!filter_var($waUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($waUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                return $this->error('رابط بوابة واتساب غير صالح - اكتب الرابط كامل بالـ https', 422);
            }
            foreach ($allowedKeys as $key) {
                if ($this->get($key) !== null && $this->get($key) !== '') {
                    $service->set($key, (string) $this->get($key));
                }
            }
            $this->log('Admin Updated System Settings', ['keys' => $allowedKeys]);
            return $this->success([], 'تم تحديث الإعدادات');
        } catch (Exception $e) {
            Logger::error('Admin updateSystemSettings Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** POST /api/admin/whatsapp-qr/test - فحص بوابة واتساب QR بالقيم المكتوبة أو المحفوظة */
    public function testWhatsAppQrGateway(array $params = []): array
    {
        $url = trim((string) $this->get('base_url', ''));
        $key = trim((string) $this->get('api_key', ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->error('رابط البوابة غير صالح', 422);
        }
        $r = (new WhatsAppQrGateway($url !== '' ? $url : null, $key !== '' ? $key : null))->ping();
        if (!empty($r['ok'])) {
            return $this->success([], 'البوابة شغالة');
        }
        $why = (string) ($r['error'] ?? '');
        $msg = str_contains($why, 'rejected') ? 'البوابة رفضت المفتاح - راجع AUTHENTICATION_API_KEY'
            : (str_contains($why, 'unreachable') ? 'مش قادرين نوصل للبوابة - راجع الرابط وإن السيرفر شغال'
            : (str_contains($why, 'required') ? 'اكتب رابط البوابة والمفتاح' : 'البوابة ردّت بخطأ - راجع الإعدادات وسجل البوابة'));
        return $this->error($msg, 422);
    }

    public function listFaqAdmin(array $params = []): array
    {
        try {
            $items = (new FaqItem())->where([], ['sort_order' => 'ASC']);
            return $this->success(['items' => array_map(fn ($f) => $f->toArray(), $items)]);
        } catch (Exception $e) {
            Logger::error('Admin listFaqAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الأسئلة', 500);
        }
    }

    /** POST /api/admin/faq */
    public function createFaq(array $params = []): array
    {
        if (!$this->validate(['question' => 'required', 'answer' => 'required'])) {
            return $this->error('البيانات ناقصة', 422);
        }
        try {
            $maxOrder = $this->db->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM faq_items");
            $faq = new FaqItem();
            $faq->fill([
                'question' => (string) $this->get('question'),
                'answer' => (string) $this->get('answer'),
                'sort_order' => (int) ($maxOrder[0]['m'] ?? 0) + 1,
                'is_active' => (int) $this->get('is_active', 1),
            ]);
            $faq->save();
            $this->log('Admin Created FAQ', ['id' => $faq->getAttribute('id')]);
            return $this->success(['item' => $faq->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            Logger::error('Admin createFaq Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الإضافة', 500);
        }
    }

    /** PUT /api/admin/faq/{id} */
    public function updateFaq(array $params = []): array
    {
        try {
            $faq = (new FaqItem())->find((int) ($params['id'] ?? 0));
            if (!$faq) {
                return $this->error('السؤال غير موجود', 404);
            }

            foreach (['question', 'answer', 'is_active'] as $field) {
                if ($this->get($field) !== null) {
                    $faq->setAttribute($field, $this->get($field));
                }
            }
            $faq->save();
            $this->log('Admin Updated FAQ', ['id' => $faq->getAttribute('id')]);
            return $this->success(['item' => $faq->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            Logger::error('Admin updateFaq Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** DELETE /api/admin/faq/{id} */
    public function deleteFaq(array $params = []): array
    {
        try {
            $faq = (new FaqItem())->find((int) ($params['id'] ?? 0));
            if (!$faq) {
                return $this->error('السؤال غير موجود', 404);
            }
            $faq->delete();
            $this->log('Admin Deleted FAQ', ['id' => $params['id'] ?? null]);
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            Logger::error('Admin deleteFaq Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحذف', 500);
        }
    }

    /**
     * POST /api/admin/broadcast - إرسال إشعار جماعي لكل العملاء (أو
     * مجموعة مفلترة منهم) دفعة واحدة.
     */
    public function broadcast(array $params = []): array
    {
        if (!$this->validate(['title' => 'required'])) {
            return $this->error('العنوان مطلوب', 422);
        }

        $audience = (string) $this->get('audience', 'all');
        $title = (string) $this->get('title');
        $body = (string) $this->get('body', '');

        try {
            $sql = "SELECT id FROM users WHERE role NOT IN ('admin', 'super_admin')";
            if ($audience === 'active_subscribers') {
                $sql = "SELECT DISTINCT u.id FROM users u
                        INNER JOIN subscriptions s ON s.user_id = u.id
                        WHERE u.role NOT IN ('admin', 'super_admin') AND s.status = 'active'";
            } elseif ($audience === 'no_subscription') {
                $sql = "SELECT u.id FROM users u
                        WHERE u.role NOT IN ('admin', 'super_admin')
                        AND u.id NOT IN (SELECT user_id FROM subscriptions WHERE status = 'active')";
            }

            $userIds = $this->db->query($sql);
            $sentCount = 0;

            foreach ($userIds as $row) {
                if (class_exists('Notification')) {
                    Notification::notify((int) $row['id'], 'admin_broadcast', $title, $body, '/dashboard');
                    $sentCount++;
                }
            }

            $this->log('Admin Sent Broadcast', ['audience' => $audience, 'sent_count' => $sentCount, 'title' => $title]);

            return $this->success(['sent_count' => $sentCount], 'تم الإرسال');
        } catch (Exception $e) {
            Logger::error('Admin broadcast Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إرسال الإشعار', 500);
        }
    }

    /** DELETE /api/admin/users/{id} */
    public function deleteUser(array $params): array
    {
        $userModel = new User();
        $user = $userModel->find((int) ($params['id'] ?? 0));

        if (!$user) {
            return $this->error('المستخدم غير موجود', 404);
        }

        if (class_exists('AdminAccess') && AdminAccess::isPrimaryOwner(['id' => (int) $user->getAttribute('id')])) {
            return $this->error('ده حساب المالك الأساسي للمنصة - محدش يقدر يحذفه', 403);
        }
        // حماية: مينفعش الأدمن يحذف نفسه، ولا يحذف admin/super_admin إلا Super Admin
        if ((int) $user->getAttribute('id') === (int) ($this->user['id'] ?? 0)) {
            return $this->error('لا يمكنك حذف حسابك الحالي', 403);
        }
        if (in_array($user->getAttribute('role'), ['admin', 'super_admin'], true) && ($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('حذف حسابات الأدمن متاح لـ Super Admin فقط', 403);
        }

        $deletedId = (int) $user->getAttribute('id');
        if (!$user->delete()) {
            return $this->error('تعذر حذف المستخدم', 500);
        }

        ActivityLog::record('admin', 'admin.user_deleted', [
            'user_id' => (int) ($this->user['id'] ?? 0), 'subject_type' => 'users', 'subject_id' => $deletedId,
        ]);

        return $this->success([], 'تم حذف المستخدم');
    }

    /** POST /api/admin/users/{id}/suspend */
    public function suspendUser(array $params): array
    {
        return $this->toggleActive($params, 0, 'تم إيقاف المستخدم');
    }

    /** POST /api/admin/users/{id}/activate */
    public function activateUser(array $params): array
    {
        return $this->toggleActive($params, 1, 'تم تفعيل المستخدم');
    }

    private function toggleActive(array $params, int $active, string $message): array
    {
        $userModel = new User();
        $user = $userModel->find((int) ($params['id'] ?? 0));

        if (!$user) {
            return $this->error('المستخدم غير موجود', 404);
        }

        if (class_exists('AdminAccess') && AdminAccess::isPrimaryOwner(['id' => (int) $user->getAttribute('id')])) {
            return $this->error('ده حساب المالك الأساسي للمنصة - محدش يقدر يوقفه', 403);
        }
        // حماية: الأدمن ميقدرش يوقف حسابه ولا حساب Super Admin
        if ((int) $user->getAttribute('id') === (int) ($this->user['id'] ?? 0)) {
            return $this->error('لا يمكنك إيقاف حسابك الحالي', 403);
        }
        if (in_array($user->getAttribute('role'), ['admin', 'super_admin'], true) && ($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('إيقاف حسابات الأدمن متاح لـ Super Admin فقط', 403);
        }

        // العمود الحقيقي هو status (مش is_active)
        $user->setAttribute('status', $active ? 'active' : 'suspended');

        if ($user->save() === false) {
            return $this->error('تعذر تحديث حالة المستخدم', 500);
        }

        ActivityLog::record('admin', $active ? 'admin.user_activated' : 'admin.user_suspended', [
            'user_id' => (int) ($this->user['id'] ?? 0),
            'subject_type' => 'users', 'subject_id' => (int) $user->getAttribute('id'),
        ]);

        $out = $user->toArray();
        $out['is_active'] = $active;
        return $this->success(['user' => $out], $message);
    }

    /** GET /admin/subscriptions و GET /api/admin/subscriptions */
    public function subscriptions(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('subscriptions');
        exit;
    }

    public function getSubscriptions(array $params = []): array
    {
        try {
            // Section 7/15: نفس نمط MRR Snapshot الكسول - مفيش Cron حقيقي
            // في المشروع، فبنشغّل فحص دورة حياة الاشتراكات (past_due،
            // انتهاء فترة السماح، تذكيرات التجديد) أول مرة أدمن يفتح
            // الصفحة دي. لو فيه Cron/Job runner حقيقي متاح مستقبلًا،
            // الأفضل يستدعي /api/admin/subscriptions/run-lifecycle-checks
            // بشكل دوري بدل الاعتماد على فتح الصفحة.
            if (class_exists('SubscriptionLifecycleService')) {
                try {
                    (new SubscriptionLifecycleService())->runLifecycleChecks();
                } catch (Exception $lifecycleError) {
                    Logger::error('Lazy lifecycle check failed', ['message' => $lifecycleError->getMessage()]);
                }
            }
            if (class_exists('InvoiceLifecycleService')) {
                try {
                    (new InvoiceLifecycleService())->runLifecycleChecks();
                } catch (Exception $invoiceLifecycleError) {
                    Logger::error('Lazy invoice lifecycle check failed', ['message' => $invoiceLifecycleError->getMessage()]);
                }
            }

            // تصحيح: subscriptions مفيهاش عمود plan_name - لازم JOIN مع
            // subscription_plans عشان نعرف اسم الباقة الحقيقي
            $sql = "SELECT s.*, u.email, u.company_name,
                        SUBSTRING_INDEX(sp.plan_code, '_', 1) AS plan_name,
                        sp.name AS plan_display_name
                    FROM subscriptions s
                    JOIN users u ON u.id = s.user_id
                    LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
                    ORDER BY s.id DESC LIMIT 1000";
            $subs = $this->db->query($sql);
            return $this->success(['subscriptions' => $subs]);
        } catch (Exception $e) {
            Logger::error('Admin getSubscriptions Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الاشتراكات', 500);
        }
    }

    /**
     * POST /api/admin/subscriptions/run-lifecycle-checks
     * تشغيل يدوي/مجدول لفحوصات دورة حياة الاشتراكات (Section 7/15) -
     * لو فيه Cron حقيقي هيتضاف مستقبلًا، ده الـ endpoint اللي المفروض
     * يستدعيه بدل الاعتماد على فتح صفحة الأدمن.
     */
    public function runSubscriptionLifecycleChecks(array $params = []): array
    {
        try {
            $result = (new SubscriptionLifecycleService())->runLifecycleChecks();
            $this->log('Admin Ran Subscription Lifecycle Checks', $result);
            return $this->success($result, 'تم تشغيل فحوصات دورة حياة الاشتراكات');
        } catch (Exception $e) {
            Logger::error('Admin runSubscriptionLifecycleChecks Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تشغيل الفحوصات', 500);
        }
    }

    /** POST /api/admin/invoices/run-lifecycle-checks */
    public function runInvoiceLifecycleChecks(array $params = []): array
    {
        try {
            $result = (new InvoiceLifecycleService())->runLifecycleChecks();
            $this->log('Admin Ran Invoice Lifecycle Checks', $result);
            return $this->success($result, 'تم تشغيل فحوصات دورة حياة الفواتير');
        } catch (Exception $e) {
            Logger::error('Admin runInvoiceLifecycleChecks Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تشغيل الفحوصات', 500);
        }
    }

    /** GET /api/admin/subscriptions/{id} */
    public function getSubscription(array $params): array
    {
        try {
            $sql = "SELECT * FROM subscriptions WHERE id = ? LIMIT 1";
            $result = $this->db->query($sql, [(int) ($params['id'] ?? 0)]);

            if (empty($result)) {
                return $this->error('الاشتراك غير موجود', 404);
            }

            return $this->success(['subscription' => $result[0]]);
        } catch (Exception $e) {
            return $this->error('تعذر جلب الاشتراك', 500);
        }
    }

    /** POST /api/admin/subscriptions/{id}/cancel */
    public function cancelSubscription(array $params): array
    {
        try {
            // تصحيح: cancelled_at مش عمود حقيقي في الجدول (الأعمدة الحقيقية:
            // status, updated_at, cancel_at_period_end فقط)
            $sql = "UPDATE subscriptions SET status = 'cancelled', updated_at = NOW() WHERE id = ?";
            $this->db->exec($sql, [(int) ($params['id'] ?? 0)]);
            return $this->success([], 'تم إلغاء الاشتراك');
        } catch (Exception $e) {
            Logger::error('Admin Cancel Subscription Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إلغاء الاشتراك', 500);
        }
    }

    /**
     * POST /api/admin/subscriptions/activate
     * تفعيل اشتراك يدوي لعميل دفع خارج المنصة (واتساب/تحويل بنكي...)
     * بدل ما ننتظر بوابة دفع إلكتروني مش مفعّلة لسه.
     */
    public function activateSubscription(array $params = []): array
    {
        $email = trim((string) $this->get('email', ''));
        $planName = $this->get('plan_name', 'starter');
        $planType = $this->get('plan_type', 'monthly');

        if (!$email) {
            return $this->error('بريد العميل مطلوب', 422);
        }

        try {
            $userModel = new User();
            $matches = $userModel->where(['email' => $email], [], 1);

            if (empty($matches)) {
                return $this->error('مفيش مستخدم مسجّل بالبريد ده', 404);
            }

            // لو الإيميل لعضو فريق، الاشتراك بيتفعّل لصاحب الحساب (هو اللي الباقة بتتحسب عليه)
            $userId = (int) ($matches[0]->getAttribute('owner_user_id') ?: $matches[0]->getAttribute('id'));

            $subscription = Subscription::createSubscription($userId, $planName, $planType);

            if (!$subscription) {
                return $this->error('تعذر تفعيل الاشتراك - تأكد إن العميل معندوش اشتراك نشط بالفعل', 500);
            }

            // التجربة اللي خلصت (past_due) أو أي اشتراك قديم مفتوح كان بيفضل جنب الجديد، وبعد فترة السماح
            // دورة الفوترة بتلغيه وتبعت للعميل «اشتراكك بقى غير فعّال» رغم إنه لسه دافع - نقفل القديم هنا
            Database::getInstance()->query(
                "UPDATE subscriptions SET status = 'expired', updated_at = NOW() WHERE user_id = ? AND id <> ? AND status IN ('trialing', 'past_due', 'active', 'pending')",
                [$userId, (int) $subscription->getAttribute('id')]
            );

            $this->log('Admin Manually Activated Subscription', [
                'admin_id' => $this->user['id'],
                'target_user_id' => $userId,
                'plan' => $planName,
                'plan_type' => $planType,
            ]);

            // Section 13/19: كان المسار ده الوحيد اللي بينشئ اشتراك من
            // غير أي إشعار للعميل أو سجل تدقيق حقيقي (كان بس $this->log()
            // اللي بيكتب في ملف اللوج العادي مش activity_logs).
            if (class_exists('Notification')) {
                Notification::notify(
                    $userId,
                    'subscription_created',
                    'تم تفعيل اشتراكك',
                    'تم تفعيل باقتك بنجاح من فريق الدعم.',
                    '/subscription'
                );
            }
            ActivityLog::record('subscription', 'subscription.created_by_admin', [
                'user_id' => (int) $this->user['id'],
                'subject_type' => 'subscriptions',
                'subject_id' => (int) $subscription->getAttribute('id'),
                'meta' => ['target_user_id' => $userId, 'plan' => $planName, 'plan_type' => $planType],
            ]);

            return $this->success(['subscription' => $subscription->toArray()], 'تم تفعيل الاشتراك بنجاح');
        } catch (Exception $e) {
            Logger::error('Admin Activate Subscription Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تفعيل الاشتراك: ' . CustomerError::message($e->getMessage(), 500), 500);
        }
    }

    /** GET /admin/system */
    public function system(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('system');
        exit;
    }

    /** GET /api/admin/system/health */
    public function systemHealth(array $params = []): array
    {
        $health = new HealthController();
        return $health->detailed($params);
    }

    /** GET /admin/logs و GET /api/admin/system/logs */
    public function logs(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('logs');
        exit;
    }

    public function getLogs(array $params = []): array
    {
        try {
            $sql = "SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 100";
            $logs = $this->db->query($sql);
            return $this->success(['logs' => $logs]);
        } catch (Exception $e) {
            Logger::error('Admin getLogs Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب السجلات', 500);
        }
    }

    /** GET /admin/contact-messages */
    public function contactMessages(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('contact-messages');
        exit;
    }

    /** GET /api/admin/contact-messages */
    public function getContactMessages(array $params = []): array
    {
        try {
            $messages = (new ContactSubmission())->where([], ['created_at' => 'DESC'], 200);
            return $this->success(['messages' => array_map(fn ($m) => $m->toArray(), $messages)]);
        } catch (Exception $e) {
            Logger::error('Admin getContactMessages Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الرسائل', 500);
        }
    }

    /** POST /api/admin/contact-messages/{id}/read */
    public function markContactMessageRead(array $params = []): array
    {
        try {
            $message = (new ContactSubmission())->find((int) ($params['id'] ?? 0));
            if (!$message) {
                return $this->error('الرسالة غير موجودة', 404);
            }
            $message->setAttribute('status', 'read');
            $message->save();
            return $this->success([], 'تم التحديث');
        } catch (Exception $e) {
            Logger::error('Admin markContactMessageRead Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** GET /admin/plans */
    public function plans(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('plans');
        exit;
    }

    /** GET /api/admin/plans */
    public function listPlansAdmin(array $params = []): array
    {
        try {
            $plans = (new SubscriptionPlan())->where([], ['sort_order' => 'ASC']);
            require_once APP_PATH . '/Services/Subscription/BillingPlanCatalog.php';
            $billingRows=$this->db->getConnection()->query('SELECT * FROM subscription_plans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $billing=BillingPlanCatalog::fromRows($billingRows);
            $out = [];
            foreach ($plans as $p) {
                $a = $p->toArray();
                $key=(string)($a['plan_key']??'');
                $a['billing_ready']=isset($billing[$key]);
                if (isset($billing[$key])) {
                    foreach (['price_monthly','price_yearly','currency','currency_symbol'] as $field) $a[$field]=$billing[$key][$field];
                    foreach ($billing[$key]['features'] as $field=>$value) $a[$field]=$value;
                    $a['is_active']=1;
                    $a['custom']=!empty($billing[$key]['features']['custom']);
                    foreach ($billingRows as $row) if (in_array($row['plan_code'],[$key.'_monthly',$key.'_yearly'],true)&&!(int)$row['is_active']) $a['is_active']=0;
                }
                $a += $this->planQuotas($key);
                $out[] = $a;
            }
            return $this->success(['plans' => $out]);
        } catch (Exception $e) {
            Logger::error('Admin listPlansAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الباقات', 500);
        }
    }

    /** POST /api/admin/plans/create - باقة جديدة منسوخة من باقة موجودة، مقفولة للبيع */
    public function createPlan(array $params = []): array
    {
        if ($error = $this->verifyCsrf()) return $error;
        try {
            require_once APP_PATH . '/Services/Subscription/BillingPlanCatalog.php';
            $key = BillingPlanCatalog::createCustomPlan($this->db, strtolower(trim((string) $this->get('plan_key', ''))), (string) $this->get('name', ''), strtolower(trim((string) $this->get('clone_from', ''))), (int) ($this->user['id'] ?? 0) ?: null);
            $this->log('Admin Created Plan', ['plan_key' => $key]);
            return $this->success(['plan_key' => $key], 'اتعملت الباقة مقفولة للبيع. عدّل حدودها وأسعارها وبعدين فعّلها.');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin createPlan Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الباقة', 500);
        }
    }

    /** POST /api/admin/plans/{id}/delete - حذف باقة جديدة معندهاش اشتراكات */
    public function deletePlan(array $params = []): array
    {
        if ($error = $this->verifyCsrf()) return $error;
        try {
            require_once APP_PATH . '/Services/Subscription/BillingPlanCatalog.php';
            $st = $this->db->getConnection()->prepare('SELECT plan_key FROM plan_pricing_display WHERE id = ?');
            $st->execute([(int) ($params['id'] ?? 0)]);
            $key = (string) $st->fetchColumn();
            if ($key === '') return $this->error('الباقة غير موجودة', 404);
            BillingPlanCatalog::deleteCustomPlan($this->db, $key, (int) ($this->user['id'] ?? 0) ?: null);
            $this->log('Admin Deleted Plan', ['plan_key' => $key]);
            return $this->success([], 'اتحذفت الباقة');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin deletePlan Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حذف الباقة', 500);
        }
    }

    /** GET /api/admin/plans-economics - إيراد وتكلفة وهامش كل باقة من أرقام فعلية */
    public function planEconomics(array $params = []): array
    {
        try {
            return $this->success(PlanEconomics::report($this->db, 30));
        } catch (Throwable $e) {
            Logger::error('Admin planEconomics Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حساب اقتصاديات الباقات', 500);
        }
    }

    /** GET /api/admin/plans-positioning - «% أقل من المنافسين» والأسعار المرجعية وأعلى سعر مسموح لكل باقة */
    public function planPositioning(array $params = []): array
    {
        try {
            $cfg = PlanPositioning::config($this->db);
            return $this->success(['config' => $cfg, 'limits' => PlanPositioning::limits($cfg)]);
        } catch (Throwable $e) {
            Logger::error('Admin planPositioning Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب إعدادات التسعير', 500);
        }
    }

    /** POST /api/admin/plans-positioning */
    public function planPositioningSave(array $params = []): array
    {
        if ($error = $this->verifyCsrf()) return $error;
        try {
            $in = $this->get('config', []);
            $cfg = PlanPositioning::save($this->db, is_array($in) ? $in : [], (int) ($this->user['id'] ?? 0) ?: null);
            $this->log('Admin Saved Plan Positioning', ['below_pct' => $cfg['below_pct']]);
            return $this->success(['config' => $cfg, 'limits' => PlanPositioning::limits($cfg)], 'اتحفظ');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Admin planPositioningSave Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** GET /api/admin/plans-audit - آخر تعديلات الباقات (مين غيّر إيه، قبل وبعد) */
    public function planChanges(array $params = []): array
    {
        try {
            require_once APP_PATH . '/Services/Subscription/BillingPlanCatalog.php';
            return $this->success(['changes' => BillingPlanCatalog::recentChanges($this->db, 40)]);
        } catch (Throwable $e) {
            Logger::error('Admin planChanges Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب سجل التعديلات', 500);
        }
    }

    /** PUT /api/admin/plans/{id} */
    public function updatePlan(array $params = []): array
    {
        try {
            require_once APP_PATH . '/Services/Subscription/BillingPlanCatalog.php';
            $id=(int)($params['id']??0);
            $fields=['name','price_monthly','price_yearly','currency','ai_analysis','competitor_analysis','chat_credits','review_credits','multiple_websites','whatsapp_bot','auto_pilot','advanced_analytics','is_active'];
            $patch=[];$extra=[];
            foreach($fields as $field)if($this->get($field)!==null)$patch[$field]=$this->get($field);
            foreach(array_merge(['ai_assistant_messages','website_generations','website_items','whatsapp_marketing_messages'],array_keys(BillingPlanCatalog::SEO_LIMITS)) as $field)if($this->get($field)!==null&&$this->get($field)!=='')$extra[$field]=$this->get($field);
            BillingPlanCatalog::ensureAuditTable($this->db);
            $actor=(int)($this->user['id']??0)?:null;
            $plan=$this->db->transaction(function()use($id,$fields,$patch,$extra,$actor){
                $pdo=$this->db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $stmt=$pdo->prepare('SELECT * FROM plan_pricing_display WHERE id=?'.$lock);$stmt->execute([$id]);$plan=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$plan)return null;
                $key=(string)$plan['plan_key'];
                $stmt=$pdo->prepare('SELECT * FROM subscription_plans WHERE plan_code IN (?,?)'.$lock);$stmt->execute([$key.'_monthly',$key.'_yearly']);
                $lockedRows=$stmt->fetchAll(PDO::FETCH_ASSOC);
                $billing=BillingPlanCatalog::fromRows($lockedRows);
                if(count($lockedRows)===2)$plan['is_active']=(int)((bool)$lockedRows[0]['is_active']&&(bool)$lockedRows[1]['is_active']);
                if(isset($billing[$key])){
                    foreach(['price_monthly','price_yearly','currency'] as $field)$plan[$field]=$billing[$key][$field];
                    foreach($billing[$key]['features'] as $field=>$value)$plan[$field]=$value;
                }
                $before=$plan;
                $plan=array_replace($plan,$patch);
                if(!is_string($plan['name'])||trim($plan['name'])===''||mb_strlen($plan['name'])>150)throw new InvalidArgumentException('Invalid plan name');
                BillingPlanCatalog::syncDisplay($this->db,$plan,$extra);
                $plan['currency_symbol']=$plan['currency'].' ';
                $persistFields=array_merge($fields,['currency_symbol']);
                $sets=implode(',',array_map(fn($field)=>'`'.$field.'`=?',$persistFields));
                $values=array_map(fn($field)=>is_bool($plan[$field])?(int)$plan[$field]:$plan[$field],$persistFields);$values[]=$id;
                $stmt=$pdo->prepare('UPDATE plan_pricing_display SET '.$sets.' WHERE id=?');$stmt->execute($values);
                BillingPlanCatalog::recordChange($this->db,$key,$actor,$before,array_replace($plan,$extra));
                return $plan;
            });
            if($plan===null)return $this->error('الباقة غير موجودة',404);
            $this->log('Admin Updated Plan',['plan_id'=>$id,'plan_key'=>$plan['plan_key']]);
            return $this->success(['plan'=>$plan],'تم تحديث الباقة');
        }catch(InvalidArgumentException $e){return $this->error('راجع الاسم والأسعار والعملة والحدود: استخدم قيمًا صحيحة وغير سالبة.',422);}
        catch(Throwable $e){Logger::error('Admin updatePlan Error',['message'=>$e->getMessage()]);return $this->error('تعذر تحديث الباقة بالكامل. راجع إعداد دورتي الفوترة ثم حاول مرة أخرى.',500);}
    }

    /** الحصص الإضافية (المساعد/المواقع) من subscription_plans.features_json */
    private function planQuotas(string $planKey): array
    {
        $defaults = [
            'website_generations' => ['starter' => 3, 'professional' => 10, 'enterprise' => 30],
            'website_items' => ['starter' => 30, 'professional' => 150, 'enterprise' => 1000],
        ];
        $out = ['ai_assistant_messages' => null, 'website_generations' => $defaults['website_generations'][$planKey] ?? 3, 'website_items' => $defaults['website_items'][$planKey] ?? 30];
        try {
            $r = $this->db->query("SELECT features_json, ai_message_limit FROM subscription_plans WHERE plan_code LIKE ? ORDER BY id LIMIT 1", [$planKey . '\\_%']);
            if ($r) {
                $f = json_decode((string) ($r[0]['features_json'] ?? ''), true) ?: [];
                $out['ai_assistant_messages'] = array_key_exists('ai_assistant_messages', $f) ? (int) $f['ai_assistant_messages'] : (int) $r[0]['ai_message_limit'];
                foreach (['website_generations', 'website_items'] as $k) {
                    if (array_key_exists($k, $f)) {
                        $out[$k] = (int) $f[$k];
                    }
                }
            }
        } catch (Throwable $e) {
        error_log('[AdminController] ignored error: ' . $e->getMessage());
        }
        return $out;
    }

    /** POST /api/admin/system/cache/clear */
    public function clearCache(array $params = []): array
    {
        try {
            if (class_exists('Cache')) {
                $cache = new Cache();
                if (method_exists($cache, 'clear')) {
                    $cache->clear();
                }
            }
            return $this->success([], 'تم مسح الكاش');
        } catch (Exception $e) {
            return $this->error('تعذر مسح الكاش', 500);
        }
    }

    /** GET /api/admin/system/stats */
    public function getSystemStats(array $params = []): array
    {
        try {
            $usersCount = $this->db->query("SELECT COUNT(*) AS c FROM users")[0]['c'] ?? 0;
            $activeSubs = $this->db->query("SELECT COUNT(*) AS c FROM subscriptions WHERE status = 'active'")[0]['c'] ?? 0;
            $websitesCount = $this->db->query("SELECT COUNT(*) AS c FROM websites")[0]['c'] ?? 0;

            return $this->success([
                'users_count' => (int) $usersCount,
                'active_subscriptions' => (int) $activeSubs,
                'websites_count' => (int) $websitesCount,
                'php_version' => phpversion(),
            ]);
        } catch (Exception $e) {
            Logger::error('Admin getSystemStats Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب إحصائيات النظام', 500);
        }
    }

    /**
     * GET /api/admin/platform-overview
     * نظرة شاملة حقيقية على كل الخدمات وكل العملاء مجمّعة سوا - مش
     * بس عدد مستخدمين، ده أداء كل موديول فعليًا عبر كل العملاء.
     * كل استعلام معزول بـ try/catch مستقل عشان لو جدول واحد فيه مشكلة
     * (زي مشاكل أسامي الأعمدة اللي اكتشفناها قبل كده) الصفحة كلها
     * منقعش، بس الرقم ده بيرجع صفر/فاضي بدل ما يوقف كل الطلب.
     */
    public function getPlatformOverview(array $params = []): array
    {
        $safe = function (string $sql, array $bindings = []) {
            try {
                $rows = $this->db->query($sql, $bindings);
                return $rows[0] ?? [];
            } catch (Exception $e) {
                Logger::error('Platform Overview Query Failed', ['sql' => $sql, 'message' => $e->getMessage()]);
                return [];
            }
        };

        $revenue = $safe("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as records FROM rev_revenue_records");
        $reviews = $safe("SELECT COUNT(*) as total, ROUND(AVG(rating), 2) as avg_rating,
                                  SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative
                           FROM reviews");
        $crm = $safe("SELECT COUNT(*) as total_deals, COALESCE(SUM(value), 0) as pipeline_value FROM crm_deals");
        $leads = $safe("SELECT COUNT(*) as total FROM crm_leads");
        $competitors = $safe("SELECT COUNT(*) as total FROM competitors WHERE is_active = 1");
        $priceAlerts = $safe("SELECT COUNT(*) as total FROM cm_alerts WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $adSpend = $safe("SELECT COALESCE(SUM(spend), 0) as total, COUNT(*) as campaigns FROM ad_campaigns");
        $socialPosts = $safe("SELECT COUNT(*) as total FROM social_posts");
        $articles = $safe("SELECT COUNT(*) as total FROM ai_articles");
        $chats = $safe("SELECT COUNT(*) as total FROM chat_messages WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $audits = $safe("SELECT COUNT(*) as total, ROUND(AVG(overall_score), 1) as avg_score FROM wo_audits");

        $planBreakdown = [];
        try {
            $planBreakdown = $this->db->query(
                "SELECT p.name as plan_name, COUNT(s.id) as count
                 FROM subscriptions s
                 JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.status = 'active'
                 GROUP BY p.id, p.name
                 ORDER BY count DESC"
            );
        } catch (Exception $e) {
            Logger::error('Platform Overview Plan Breakdown Failed', ['message' => $e->getMessage()]);
        }

        return $this->success([
            'revenue' => ['total' => (float) ($revenue['total'] ?? 0), 'records' => (int) ($revenue['records'] ?? 0)],
            'reviews' => [
                'total' => (int) ($reviews['total'] ?? 0),
                'avg_rating' => (float) ($reviews['avg_rating'] ?? 0),
                'negative' => (int) ($reviews['negative'] ?? 0),
            ],
            'crm' => ['deals' => (int) ($crm['total_deals'] ?? 0), 'pipeline_value' => (float) ($crm['pipeline_value'] ?? 0), 'leads' => (int) ($leads['total'] ?? 0)],
            'competitors' => ['tracked' => (int) ($competitors['total'] ?? 0), 'alerts_30d' => (int) ($priceAlerts['total'] ?? 0)],
            'ads' => ['spend' => (float) ($adSpend['total'] ?? 0), 'campaigns' => (int) ($adSpend['campaigns'] ?? 0)],
            'content' => ['social_posts' => (int) ($socialPosts['total'] ?? 0), 'articles' => (int) ($articles['total'] ?? 0)],
            'chat' => ['messages_30d' => (int) ($chats['total'] ?? 0)],
            'website_optimizer' => ['audits' => (int) ($audits['total'] ?? 0), 'avg_score' => (float) ($audits['avg_score'] ?? 0)],
            'plans' => $planBreakdown,
        ]);
    }

    /** GET /admin/settings */
    public function settings(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('settings');
        exit;
    }

    /** GET /admin/integrations - إدارة مفاتيح التكاملات الخارجية */
    public function team(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('team');
        exit;
    }

    public function audit(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('audit');
        exit;
    }

    /** GET /admin/seo-data-costs */
    public function seoDataCosts(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('seo-data-costs');
        exit;
    }

    public function aiUsage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('ai-usage');
        exit;
    }

    public function jobs(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('jobs');
        exit;
    }

    public function security(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('security');
        exit;
    }

    /** GET /admin/finance - المالية: الإيداعات المعلّقة، الاسترداد، الضرائب، كروت الشحن، بيانات الدفع */
    public function finance(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('finance');
        exit;
    }

    /** GET /admin/health */
    public function healthPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('health');
        exit;
    }

    /** GET /admin/emails */
    public function emailsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('emails');
        exit;
    }

    /** GET /admin/metrics */
    public function metricsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('metrics');
        exit;
    }

    /** GET /admin/errors - أخطاء النظام من ملفات اللوج */
    public function errorsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('errors');
        exit;
    }

    /** GET /admin/adoption - العملاء بيستخدموا أنهي مميزات ووصلوا لحد فين */
    public function adoptionPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('adoption');
        exit;
    }

    /** GET /admin/trials - متابعة التجارب المجانية عشان تتحول اشتراكات */
    public function trialsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('trials');
        exit;
    }

    /** GET /admin/customers-health */
    public function customersHealthPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('customers-health');
        exit;
    }

    /** GET /admin/billing-ops */
    public function billingOpsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('billing-ops');
        exit;
    }

    /** GET /admin/alerts */
    public function alertsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('alerts');
        exit;
    }

    /** GET /admin/admin-security */
    public function adminSecurityPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('admin-security');
        exit;
    }

    /** GET /admin/blog - مقالات مدونة الموقع العام */
    public function blogAdminPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('blog');
        exit;
    }

    /** GET /admin/status-page */
    public function statusPageAdmin(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('status-page');
        exit;
    }

    /** GET /admin/api-keys - مفاتيح API الخاصة بالشركاء */
    public function apiKeys(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('api-keys');
        exit;
    }

    public function integrations(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('integrations');
        exit;
    }

    /** GET /admin/login-history */
    public function loginHistory(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('login-history');
        exit;
    }

    /** GET /admin/visitors */
    public function visitorStatsPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('visitors');
        exit;
    }

    /** GET /api/admin/login-history — سجل دخول كل الحسابات */
    public function getAllLoginHistory(array $params = []): array
    {
        try {
            $limit = min(500, max(1, (int) ($this->get('limit', 100))));
            $sql = "SELECT lh.*, u.company_name, u.email AS user_email
                    FROM login_history lh
                    LEFT JOIN users u ON u.id = lh.user_id
                    ORDER BY lh.created_at DESC
                    LIMIT {$limit}";
            $result = $this->db->query($sql);
            return $this->success(['login_history' => $result]);
        } catch (Exception $e) {
            Logger::error('Admin getAllLoginHistory Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب سجل الدخول', 500);
        }
    }

    /**
     * GET /api/admin/onboarding-funnel
     * لوحة الفونيل: من شاهد الـOnboarding → وصل لخطوة N → سابها → قدم → خلص.
     * بيجمع بين activity_logs (viewed/submitted) و onboarding_drafts (أقصى
     * خطوة لكل مستخدم - تسرب حقيقي لكل خطوة) و websites (الاكتمال الفعلي).
     * أي جدول لسه مش متعمل على السيرفر بنتجاهله بصمت.
     */
    public function onboardingFunnel(array $params = []): array
    {
        try {
            $views = 0;
            $submitted = 0;
            $completed = 0;
            $avgMinutes = null;
            $stepRows = [];
            $trendRows = [];

            // أحداث الفونيل من activity_logs (لو موجود)
            try {
                $views = (int) ($this->db->query("SELECT COUNT(*) AS c FROM activity_logs WHERE module = 'onboarding' AND action = 'onboarding.viewed'")[0]['c'] ?? 0);
                $submitted = (int) ($this->db->query("SELECT COUNT(*) AS c FROM activity_logs WHERE module = 'onboarding' AND action = 'onboarding.submitted'")[0]['c'] ?? 0);
            } catch (Throwable $e) {
                // جدول activity_logs لسه مش متعمل
            }

            // الاكتمال الفعلي من مواقع خلصت الـOnboarding
            try {
                $completed = (int) ($this->db->query("SELECT COUNT(*) AS c FROM websites WHERE onboarding_completed_at IS NOT NULL")[0]['c'] ?? 0);
                $avgRow = $this->db->query(
                    "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, onboarding_completed_at)) AS a
                     FROM websites WHERE onboarding_completed_at IS NOT NULL AND created_at IS NOT NULL AND created_at <> onboarding_completed_at"
                );
                if (!empty($avgRow) && $avgRow[0]['a'] !== null) {
                    $avgMinutes = (int) round((float) $avgRow[0]['a']);
                }
            } catch (Throwable $e) {
                // الجدول/الأعمدة مش موجودة لسه
            }

            // توزيع "أقصى خطوة" لكل مستخدم من المسودات (لو الجدول موجود)
            try {
                $stepRows = $this->db->query(
                    "SELECT step, COUNT(*) AS c FROM onboarding_drafts GROUP BY step ORDER BY step ASC"
                );
            } catch (Throwable $e) {
                $stepRows = [];
            }

            // اتجاه 14 يوم (views vs submitted) - لو النشاط مسجّل
            try {
                $trendRows = $this->db->query(
                    "SELECT DATE(created_at) AS d,
                            SUM(action = 'onboarding.viewed') AS views,
                            SUM(action = 'onboarding.submitted') AS submitted
                     FROM activity_logs
                     WHERE module = 'onboarding' AND action IN ('onboarding.viewed','onboarding.submitted')
                       AND created_at >= NOW() - INTERVAL 14 DAY
                     GROUP BY DATE(created_at)
                     ORDER BY d ASC"
                );
            } catch (Throwable $e) {
                $trendRows = [];
            }

            // تحويل أقصى خطوة إلى قناة تراكمية: "كم مستخدم وصل خطوة N فأكثر"
            $stepMax = [];
            $totalDrafted = 0;
            foreach ($stepRows as $r) {
                $stepMax[(int) $r['step']] = (int) $r['c'];
                $totalDrafted += (int) $r['c'];
            }
            $stepCumulative = [];
            for ($i = 1; $i <= 7; $i++) {
                $acc = 0;
                foreach ($stepMax as $s => $c) {
                    if ($s >= $i) {
                        $acc += $c;
                    }
                }
                $stepCumulative[] = ['step' => $i, 'users' => $acc];
            }

            return $this->success([
                'funnel' => [
                    'total_views' => $views,
                    'started' => $totalDrafted,
                    'submitted' => $submitted,
                    'completed' => $completed,
                    'view_to_submit_pct' => $views > 0 ? round(($submitted / $views) * 100, 1) : 0,
                    'view_to_complete_pct' => $views > 0 ? round(($completed / $views) * 100, 1) : 0,
                ],
                'steps' => $stepCumulative,
                'avg_completion_minutes' => $avgMinutes,
                'trend' => $trendRows,
            ]);
        } catch (Exception $e) {
            Logger::error('Admin onboardingFunnel Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب بيانات الفونيل', 500);
        }
    }

    /** GET /admin/onboarding-funnel — صفحة لوحة الفونيل */
    public function onboardingFunnelPage(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAdminPage('onboarding-funnel');
        exit;
    }

    /** GET /api/admin/users/{id}/login-history — سجل دخول حساب معيّن */
    public function getUserLoginHistory(array $params): array
    {
        try {
            $userId = (int) ($params['id'] ?? 0);
            $sql = "SELECT * FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 100";
            $result = $this->db->query($sql, [$userId]);
            return $this->success(['login_history' => $result]);
        } catch (Exception $e) {
            Logger::error('Admin getUserLoginHistory Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب سجل دخول المستخدم', 500);
        }
    }

    /**
     * POST /api/admin/users/{id}/impersonate
     * الدخول بحساب عميل لأغراض الدعم الفني. يُمنع انتحال أدمن آخر أو حساب موقوف،
     * وتُسجَّل العملية بالكامل في impersonation_logs و login_history.
     */
    public function impersonateUser(array $params): array
    {
        try {
            $targetId = (int) ($params['id'] ?? 0);
            $userModel = new User();
            $target = $userModel->find($targetId);

            if (!$target) {
                return $this->error('المستخدم غير موجود', 404);
            }

            $targetData = $target->toArray();

            if (in_array($targetData['role'] ?? 'user', ['admin', 'super_admin'], true)) {
                return $this->error('لا يمكن انتحال حساب أدمن آخر', 403);
            }

            if (($targetData['status'] ?? 'active') !== 'active') {
                return $this->error('لا يمكن انتحال حساب موقوف', 403);
            }

            $adminId = (int) $this->user['id'];
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

            // تسجيل بداية جلسة الانتحال
            $this->db->exec(
                "INSERT INTO impersonation_logs (admin_id, target_user_id, ip_address, started_at) VALUES (?, ?, ?, NOW())",
                [$adminId, $targetId, $ip]
            );
            $impersonationLogId = (int) $this->db->query('SELECT LAST_INSERT_ID() AS id')[0]['id'];

            // حفظ هوية الأدمن الأصلية عشان نقدر نرجعله
            $_SESSION['impersonator_admin_id'] = $adminId;
            $_SESSION['impersonator_admin_user'] = $this->user;
            $_SESSION['impersonation_log_id'] = $impersonationLogId;

            // تبديل الجلسة لحساب العميل
            $_SESSION['user_id'] = $targetData['id'];
            $_SESSION['user'] = $targetData;

            $this->recordLoginHistoryForImpersonation($targetId, $targetData['email'] ?? '', true);

            return $this->success(['user' => $targetData], 'تم الدخول بحساب العميل');
        } catch (Exception $e) {
            Logger::error('Admin impersonateUser Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر بدء جلسة الانتحال', 500);
        }
    }

    /** POST /api/admin/impersonate/stop — الرجوع لحساب الأدمن الأصلي */
    public function stopImpersonation(array $params = []): array
    {
        try {
            if (empty($_SESSION['impersonator_admin_id']) || empty($_SESSION['impersonator_admin_user'])) {
                return $this->error('لا توجد جلسة انتحال حالية', 400);
            }

            $logId = (int) ($_SESSION['impersonation_log_id'] ?? 0);
            if ($logId > 0) {
                $this->db->exec(
                    "UPDATE impersonation_logs SET ended_at = NOW() WHERE id = ?",
                    [$logId]
                );
            }

            $adminUser = $_SESSION['impersonator_admin_user'];

            unset($_SESSION['impersonator_admin_id'], $_SESSION['impersonator_admin_user'], $_SESSION['impersonation_log_id']);

            $_SESSION['user_id'] = $adminUser['id'];
            $_SESSION['user'] = $adminUser;

            return $this->success(['user' => $adminUser], 'تم الرجوع لحساب الأدمن');
        } catch (Exception $e) {
            Logger::error('Admin stopImpersonation Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنهاء جلسة الانتحال', 500);
        }
    }

    /**
     * POST /api/admin/users/{id}/reset-2fa
     * Profile Center Phase 5: مسار طوارئ إداري - لو مستخدم فقد جهازه وكل
     * الـRecovery Codes بتاعته، كان مفيش أي طريقة يرجع بيها لحسابه خالص.
     * ده بيلغي 2FA تمامًا عن المستخدم (زي لو هو نفسه عمل Disable)، مش
     * بيعمل Bypass لتسجيل الدخول ولا بيشوف الـsecret القديم. المستخدم
     * هيحتاج يفعّل 2FA من جديد كامل بعد ما يرجع يدخل بباسوردته العادية.
     * لأن ده صلاحية حساسة جدًا، بنطلب تأكيد كلمة مرور الأدمن نفسه ونسجّل
     * العملية في اللوج بمين عمل إيه ولمين - Audit Trail حقيقي.
     */
    public function resetUserTwoFactor(array $params): array
    {
        if (empty($_SESSION['user_id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate(['password' => 'required'])) {
            return $this->error('يجب تأكيد كلمة مرورك أولًا', 422, $this->getErrors());
        }

        $adminModel = new User();
        $admin = $adminModel->find((int) $_SESSION['user_id']);
        if (!$admin || !$admin->verifyPassword((string) $this->get('password'))) {
            return $this->error('كلمة المرور غير صحيحة', 401);
        }

        $userModel = new User();
        $user = $userModel->find((int) ($params['id'] ?? 0));
        if (!$user) {
            return $this->error('المستخدم غير موجود', 404);
        }

        if (!(bool) $user->getAttribute('two_factor_enabled')) {
            return $this->error('التحقق بخطوتين مش مُفعّل أصلًا لهذا المستخدم', 422);
        }

        $user->setAttribute('two_factor_enabled', 0);
        $user->setAttribute('two_factor_secret', null);
        $user->setAttribute('two_factor_recovery_codes', null);
        $user->setAttribute('two_factor_confirmed_at', null);

        if ($user->save() === false) {
            return $this->error('تعذر إلغاء التحقق بخطوتين لهذا المستخدم', 500);
        }

        $this->log('Admin Reset User 2FA', [
            'admin_id' => (int) $admin->getAttribute('id'),
            'target_user_id' => (int) $user->getAttribute('id'),
        ]);

        return $this->success([], 'تم إلغاء التحقق بخطوتين لهذا المستخدم - سيحتاج لإعادة تفعيله من جديد');
    }

    /**
     * تسجيل دخول عبر الانتحال في login_history (نسخة مبسطة بدون الاعتماد على AuthController)
     */
    private function recordLoginHistoryForImpersonation(int $userId, string $email, bool $isImpersonation): void
    {
        try {
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $userAgent = function_exists('get_user_agent') ? get_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
            $device = class_exists('DeviceDetector')
                ? DeviceDetector::parse($userAgent)
                : ['device_type' => null, 'browser' => null, 'platform' => null];

            $this->db->exec(
                "INSERT INTO login_history
                    (user_id, email_attempted, status, ip_address, user_agent, device_type, browser, platform,
                     session_id, is_impersonation, created_at)
                 VALUES (?, ?, 'success', ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId, mb_substr($email, 0, 255), $ip, $userAgent,
                    $device['device_type'] ?? null, $device['browser'] ?? null, $device['platform'] ?? null,
                    session_id() ?: null, $isImpersonation ? 1 : 0,
                ]
            );
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Impersonation login_history insert failed', ['message' => $e->getMessage()]);
            }
        }
    }

    /** GET /api/admin/visitors/stats?days=30 */
    public function visitorStats(array $params = []): array
    {
        try {
            $days = min(90, max(1, (int) ($this->get('days', 30))));
            // زيارات الصفحات بس: طلبات الـ API ومحاولات المسح الآلي مابتتحسبش زيارة
            $clean = "page_url NOT LIKE '/api/%' AND page_url NOT REGEXP '(wp-admin|wp-login|wp-content|wp-includes|xmlrpc|phpmyadmin|/[.]env|/[.]git|[.]php$|cgi-bin|/vendor/|/actuator)'";

            $totalVisits = $this->db->query(
                "SELECT COUNT(*) AS c FROM visitor_logs WHERE {$clean} AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            )[0]['c'] ?? 0;

            $uniqueVisitors = $this->db->query(
                "SELECT COUNT(DISTINCT visitor_id) AS c FROM visitor_logs WHERE {$clean} AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            )[0]['c'] ?? 0;

            $authenticatedVisits = $this->db->query(
                "SELECT COUNT(*) AS c FROM visitor_logs WHERE {$clean} AND is_authenticated = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            )[0]['c'] ?? 0;

            $byDay = $this->db->query(
                "SELECT DATE(created_at) AS day, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS unique_visitors
                 FROM visitor_logs
                 WHERE {$clean} AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC"
            );

            $topPages = $this->db->query(
                "SELECT page_url, COUNT(*) AS visits
                 FROM visitor_logs
                 WHERE {$clean} AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY page_url
                 ORDER BY visits DESC
                 LIMIT 10"
            );

            $byCountry = $this->db->query(
                "SELECT COALESCE(country, 'غير معروف') AS country, COUNT(*) AS visits
                 FROM visitor_logs
                 WHERE {$clean} AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY country
                 ORDER BY visits DESC
                 LIMIT 10"
            );

            $byDevice = $this->db->query(
                "SELECT COALESCE(device_type, 'غير معروف') AS device_type, COUNT(*) AS visits
                 FROM visitor_logs
                 WHERE {$clean} AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY device_type
                 ORDER BY visits DESC"
            );

            return $this->success([
                'total_visits' => (int) $totalVisits,
                'unique_visitors' => (int) $uniqueVisitors,
                'authenticated_visits' => (int) $authenticatedVisits,
                'by_day' => $byDay,
                'top_pages' => $topPages,
                'by_country' => $byCountry,
                'by_device' => $byDevice,
                'days' => $days,
            ]);
        } catch (Exception $e) {
            Logger::error('Admin visitorStats Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب إحصائيات الزوار', 500);
        }
    }

    /** GET /api/admin/visitors/log */
    public function visitorLog(array $params = []): array
    {
        try {
            $limit = min(500, max(1, (int) ($this->get('limit', 100))));
            $sql = "SELECT vl.*, u.company_name, u.email AS user_email
                    FROM visitor_logs vl
                    LEFT JOIN users u ON u.id = vl.user_id
                    WHERE vl.page_url NOT LIKE '/api/%'
                    ORDER BY vl.created_at DESC
                    LIMIT {$limit}";
            $result = $this->db->query($sql);
            return $this->success(['visitors' => $result]);
        } catch (Exception $e) {
            Logger::error('Admin visitorLog Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب سجل الزوار', 500);
        }
    }

    /**
     * توليد صفحة HTML احترافية للوحة تحكم الأدمن (Sidebar SaaS Layout).
     * التبويبات: overview, users, subscriptions, visitors, login-history, system, logs, settings.
     * كل البيانات بتتحمّل عبر fetch() من /api/admin/* الشغالة بالفعل.
     *
     * @param string $tab
     * @return string
     */
    private function seoDataCostsHtml(): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $usd = fn ($v) => '$' . number_format((float) $v, 2);
        try {
            $r = (new SeoDataCostReport($this->db))->build();
        } catch (Throwable $ex) {
            Logger::error('SEO data cost report failed', ['error' => $ex->getMessage()]);
            return '<div class="p-card"><div class="p-empty">تعذر تحميل التقرير — راجع اللوج.</div></div>';
        }
        if (!$r['available']) return '<div class="p-card"><div class="p-empty">DataForSEO لسه ماتسجلش عليه أي مكالمة.</div></div>';
        $cap = 0.0;
        try { $cap = (float) ((new DataForSeoClient())->budgetSummary($this->db)['cap'] ?? 0); } catch (Throwable $ex) { error_log('[AdminController] DataForSEO budget summary failed: ' . $ex->getMessage()); }
        $t = $r['totals'];
        // طلبات بحث جوجل اللي اتخدمت من الكاش المشترك الشهر ده بدل ما تتدفع (الكاش بيتحسب hits كل مرة)
        $saved = ['hits' => 0, 'usd' => 0.0, 'avg' => 0.0];
        try {
            $hits = (int) ($this->db->query("SELECT COALESCE(SUM(hits), 0) AS h FROM seo_serp_cache WHERE fetched_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')")[0]['h'] ?? 0);
            $avg = (float) ($this->db->query("SELECT COALESCE(AVG(cost), 0) AS a FROM dataforseo_calls WHERE endpoint = 'serp/google/organic/live/advanced' AND cost > 0 AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)")[0]['a'] ?? 0);
            $saved = ['hits' => $hits, 'avg' => $avg, 'usd' => round($hits * $avg, 2)];
        } catch (Throwable $ex) {
            // الجدول لسه ما اتعملش = مفيش كاش لسه
        }
        $tile = fn ($label, $value, $sub = '') => '<div class="p-card" style="flex:1;min-width:170px"><small style="color:var(--panel-text-muted)">' . $label . '</small><div style="font-size:24px;font-weight:800;margin-top:4px">' . $value . '</div>' . ($sub ? '<small style="color:var(--panel-text-muted)">' . $sub . '</small>' : '') . '</div>';
        $warn = $cap > 0 && $t['projected'] > $cap ? '<span style="color:#dc2626">بالمعدل ده هتعدّي الميزانية</span>' : '';
        $html = '<div dir="rtl" style="display:flex;flex-direction:column;gap:16px">'
            . '<div style="display:flex;gap:12px;flex-wrap:wrap">'
            . $tile('اتصرف الشهر ده', $usd($t['cost']), $t['calls'] . ' مكالمة' . ($t['held'] > 0 ? ' · محجوز ' . $usd($t['held']) : ''))
            . $tile('متوقع آخر الشهر', $usd($t['projected']), $warn)
            . $tile('ميزانية الشهر', $cap > 0 ? $usd($cap) : 'مش متحددة', '<a href="/admin/integrations">تعديلها من التكاملات</a>')
            . $tile('الشهر اللي فات', $usd($r['last_month']['cost']), $r['last_month']['calls'] . ' مكالمة')
            . $tile('اتوفّر من الكاش المشترك', $saved['hits'] . ' طلب', $saved['usd'] > 0 ? '≈ ' . $usd($saved['usd']) . ' (بمتوسط ' . $usd($saved['avg']) . ' للطلب)' : 'لسه مفيش')
            . '</div>';
        $cats = '';
        foreach ($r['categories'] as $cat => $c) $cats .= '<tr><td>' . $e(SeoDataCostReport::categoryLabel($cat)) . '</td><td dir="ltr">' . $usd($c) . '</td><td>' . ($t['cost'] > 0 ? round($c / $t['cost'] * 100) . '%' : '—') . '</td></tr>';
        if ($r['platform']) $cats .= '<tr><td>التحليل الذكي قبل التسجيل (على المنصة)</td><td dir="ltr">' . $usd($r['platform']['cost']) . '</td><td>' . $r['platform']['calls'] . ' مكالمة</td></tr>';
        $html .= '<div class="p-card"><div class="p-card-head"><h3>الفلوس رايحة فين</h3><span class="p-card-sub">فحص الترتيب: الكلمات المهمة (أولوية عالية أو في أول 10) كل يوم، والمراكز 11-20 كل 3 أيام، والباقي مرة في الأسبوع، على أول 30 نتيجة.</span></div>'
            . ($cats ? '<table class="p-table"><thead><tr><th>البند</th><th>التكلفة</th><th>النسبة</th></tr></thead><tbody>' . $cats . '</tbody></table>' : '<div class="p-empty">مفيش صرف الشهر ده.</div>') . '</div>';
        // تفصيل لكل خدمة ولكل يوم: عشان نعرف الرصيد بيخلص ليه وإمتى
        $eps = '';
        foreach ($r['endpoints'] as $ep) $eps .= '<tr><td dir="ltr"><code>' . $e($ep['endpoint']) . '</code><br><small>' . $e(SeoDataCostReport::categoryLabel($ep['category'])) . '</small></td><td>' . (int) $ep['calls'] . '</td><td dir="ltr">' . $usd($ep['cost']) . '</td><td dir="ltr">$' . number_format($ep['avg'], 4) . '</td><td dir="ltr">' . ($ep['held'] > 0 ? $usd($ep['held']) : '—') . '</td></tr>';
        $days = '';
        foreach ($r['days'] as $d) $days .= '<tr><td dir="ltr">' . $e($d['day']) . '</td><td>' . (int) $d['calls'] . ($d['platform_calls'] ? ' <small>(' . (int) $d['platform_calls'] . ' قبل التسجيل)</small>' : '') . '</td><td dir="ltr">' . $usd($d['cost']) . '</td></tr>';
        $st = $r['statuses'];
        $stTxt = implode(' · ', array_map(static fn ($k, $v) => $k . ' ' . $v['calls'] . ($v['held'] > 0 ? ' (محجوز $' . number_format($v['held'], 2) . ')' : ''), array_keys($st), $st));
        $html .= '<div class="p-card"><div class="p-card-head"><h3>كل خدمة بتكلّف كام</h3><span class="p-card-sub">المتوسط لكل طلب بيبيّن لو فيه خدمة غالية. «محجوز» = طلب ماوصلش رده لسه؛ بعد ساعة بيتسجل بمتوسط تكلفة نفس الخدمة (estimated) بدل ما يحجز السقف طول الشهر.</span></div>'
            . ($eps ? '<div style="overflow-x:auto"><table class="p-table"><thead><tr><th>الخدمة</th><th>طلبات</th><th>التكلفة</th><th>متوسط الطلب</th><th>محجوز</th></tr></thead><tbody>' . $eps . '</tbody></table></div><p class="p-cell-muted" style="margin-top:8px" dir="ltr">' . $e($stTxt) . '</p>' : '<div class="p-empty">مفيش طلبات الشهر ده.</div>')
            . '</div>';
        $html .= '<div class="p-card"><div class="p-card-head"><h3>كل يوم (آخر 30 يوم)</h3><span class="p-card-sub">يوم فيه طلبات كتير فجأة = تجربة أو تحليل اتعمل كذا مرة.</span></div>'
            . ($days ? '<div style="overflow-x:auto;max-height:420px"><table class="p-table"><thead><tr><th>اليوم</th><th>طلبات</th><th>التكلفة</th></tr></thead><tbody>' . $days . '</tbody></table></div>' : '<div class="p-empty">مفيش طلبات.</div>')
            . '</div>';
        $rows = '';
        foreach ($r['customers'] as $c) {
            $q = $c['quota'] ? $c['quota']['used'] . ' / ' . ($c['quota']['limit'] > 0 ? $c['quota']['limit'] : '—') : '—';
            $rows .= '<tr><td><b>' . $e($c['email'] ?: ('#' . $c['user_id'])) . '</b><br><small dir="ltr">' . $e($c['url']) . '</small></td><td dir="ltr">' . $usd($c['cost']) . '</td><td dir="ltr">' . $usd($c['projected']) . '</td><td>' . (int) $c['calls'] . '</td><td>' . (int) $c['tracked_keywords'] . '</td><td dir="ltr">' . $q . '</td><td>' . $e(SeoDataCostReport::categoryLabel((string) $c['top'])) . '</td></tr>';
        }
        $html .= '<div class="p-card"><div class="p-card-head"><h3>العملاء</h3><span class="p-card-sub">«الوحدات» = اللي اتخصم من رصيد seo_data في باقته الشهر ده.</span></div>'
            . ($rows ? '<div style="overflow-x:auto"><table class="p-table"><thead><tr><th>العميل</th><th>الشهر ده</th><th>متوقع</th><th>مكالمات</th><th>كلمات متابَعة</th><th>الوحدات</th><th>أكبر بند</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<div class="p-empty">مفيش عميل صرف الشهر ده.</div>') . '</div>';
        return $html . '</div>';
    }

    private function renderAdminPage(string $tab): string
    {
        // دخول لوحة الأدمن بيلغي وضع "لوحة العميل" - /dashboard بعدها يرجّع للأدمن زي الأول
        unset($_SESSION['admin_customer_view']);
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $panelBrandHtml = site_brand_html();
        $adminName = htmlspecialchars($this->user['company_name'] ?? $this->tr('admin.default_name'), ENT_QUOTES, 'UTF-8');
        $adminEmail = htmlspecialchars($this->user['email'] ?? '', ENT_QUOTES, 'UTF-8');
        $adminInitial = htmlspecialchars(mb_substr($adminName, 0, 1), ENT_QUOTES, 'UTF-8');
        $staffProfile = class_exists('AdminAccess') ? AdminAccess::staffProfile($this->user) : ['role' => 'admin'];
        $roleLabel = htmlspecialchars(trim(explode('(', (class_exists('AdminAccess') ? (AdminAccess::ROLE_LABELS[$staffProfile['role']] ?? '') : ''))[0]) ?: $this->tr('admin.role.admin'), ENT_QUOTES, 'UTF-8');
        $adminPermsJson = json_encode(class_exists('AdminAccess') ? AdminAccess::permissionsFor($this->user) : []);
        $adminRoleJson = json_encode((string) ($this->user['role'] ?? ''));

        $titles = [
            'overview' => [$this->tr('admin.tab.overview'), $this->tr('admin.tab.overview_sub')],
            'users' => [$this->tr('admin.tab.users'), $this->tr('admin.tab.users_sub')],
            'subscriptions' => [$this->tr('admin.tab.subscriptions'), $this->tr('admin.tab.subscriptions_sub')],
            'contact-messages' => [$this->tr('admin.tab.contact_messages'), $this->tr('admin.tab.contact_messages_sub')],
            'plans' => [$this->tr('admin.tab.plans'), $this->tr('admin.tab.plans_sub')],
            'visitors' => [$this->tr('admin.tab.visitors'), $this->tr('admin.tab.visitors_sub')],
            'login-history' => [$this->tr('admin.tab.login_history'), $this->tr('admin.tab.login_history_sub')],
            'onboarding-funnel' => [$this->tr('admin.tab.onboarding_funnel'), $this->tr('admin.tab.onboarding_funnel_sub')],
            'system' => [$this->tr('admin.tab.system'), $this->tr('admin.tab.system_sub')],
            'logs' => [$this->tr('admin.tab.logs'), $this->tr('admin.tab.logs_sub')],
            'settings' => [$this->tr('admin.tab.settings'), $this->tr('admin.tab.settings_sub')],
            'integrations' => [$this->tr('admin.tab.integrations'), $this->tr('admin.tab.integrations_sub')],
            'team' => [$this->tr('admin.tab.team'), $this->tr('admin.tab.team_sub')],
            'audit' => [$this->tr('admin.tab.audit'), $this->tr('admin.tab.audit_sub')],
            'ai-usage' => [$this->tr('admin.tab.ai_usage'), $this->tr('admin.tab.ai_usage_sub')],
            'jobs' => [$this->tr('admin.tab.jobs'), $this->tr('admin.tab.jobs_sub')],
            'security' => [$this->tr('admin.tab.security'), $this->tr('admin.tab.security_sub')],
            'finance' => [$this->tr('admin.tab.finance'), $this->tr('admin.tab.finance_sub')],
            'api-keys' => [$this->tr('admin.tab.api_keys'), $this->tr('admin.tab.api_keys_sub')],
            'health' => ['صحة المنصة', 'كل حاجة ممكن توقف المنصة في مكان واحد: الكرونات، التكاملات، البريد، الطابور، المدفوعات، والسيرفر'],
            'emails' => ['سجل البريد', 'كل إيميل المنصة بعتته: وصل ولا فشل وليه، مع إعادة الإرسال'],
            'metrics' => ['أرقام SaaS', 'MRR و ARR ونسبة الإلغاء وقيمة العميل وتحويل التجربة والـ Cohorts'],
            'seo-data-costs' => ['تكلفة DataForSEO', 'كل عميل صرف كام الشهر ده وفي إيه، ومتوقع يقفل الشهر على كام — من التكلفة الحقيقية اللي DataForSEO رجّعها'],
            'customers-health' => ['صحة العملاء', 'مين على وشك يمشي، ومين بيخسّرك فلوس، وليه'],
            'errors' => ['أخطاء النظام', 'الأخطاء اللي بتتسجل في اللوج متجمّعة: إيه اللي بيتكرر، من إمتى، وآخر مرة حصل'],
            'trials' => ['متابعة التجارب', 'مين تجربته قربت تخلص، ومين خلصت ولسه مااشتركش، ومين سجّل ومابدأش - كلّمهم في الوقت الصح'],
            'adoption' => ['استخدام المميزات', 'كل عميل وصل لحد فين في طريق أول حجز، وبيستخدم أنهي مميزات - عشان تعرف تكلّم مين وتبني إيه'],
            'billing-ops' => ['الدفع والكوبونات', 'الاشتراكات المتأخرة، الدفعات اللي فشلت، وأكواد الخصم'],
            'alerts' => ['التنبيهات', 'المنصة بتبلغك لما حاجة تقع أو عميل مهم يلغي'],
            'admin-security' => ['أمان الأدمن', 'التحقق بخطوتين للفريق، عناوين IP المسموحة، وسبب إجباري للإجراءات الحساسة'],
            'blog' => ['المدونة', 'مقالات مدونة الموقع العام (tourfecto.pro/blog) بالعربي والإنجليزي'],
            'status-page' => ['صفحة الحالة والتحديثات', 'صفحة tourfecto.pro/status للعملاء، والحوادث، وسجل "إيه الجديد"'],
        ];
        $pageTitle = $titles[$tab][0] ?? $this->tr('admin.page_title');
        $pageSubtitle = $titles[$tab][1] ?? '';

        $navHtml = $this->renderAdminSidebar($tab);
        $panelBody = $this->renderAdminPanelBody($tab);
        if($tab==='integrations')$panelBody.='<input type="hidden" id="intCsrf" value="'.htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8').'">';
        // Panel.fetchJSON بيقرا التوكن من هنا ويبعته مع كل طلب (المسارات اللي عليها CSRF زي «ابدأله التجربة» كانت بترجع 419)
        if (class_exists('Csrf')) { $panelBody .= '<meta name="csrf-token" content="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '">'; }
        if ($tab==='billing-ops') $panelBody='<p><a class="p-btn outline" href="/admin/usage-reservations">مراجعة الاستخدام المعلق ونتائج واتساب</a></p>'.$panelBody;

        $script = <<<'JS'
(function () {
    const tab = "__TAB__";
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, timeAgo = P.timeAgo, formatDate = P.formatDate, initials = P.initials, statusPill = P.statusPill;
    let usersCache = [];
    let charts = {};
    const T = (k, fb) => (window.I18N && window.I18N[k]) || fb;

    function destroyChart(key) {
        if (charts[key]) { charts[key].destroy(); delete charts[key]; }
    }
    // لو مكتبة الرسوم ماحمّلتش، الجداول والأرقام تفضل تظهر عادي
    function makeChart(ctx, cfg) {
        if (typeof Chart === 'undefined') return null;
        try { return new Chart(ctx, cfg); } catch (e) { return null; }
    }

    function roleLabel(role) {
        if (role === 'super_admin') return '<span class="pill blue">' + I18N['admin.role.super_admin'] + '</span>';
        if (role === 'admin') return '<span class="pill blue">' + I18N['admin.role.admin'] + '</span>';
        if (role === 'agency_owner') return '<span class="pill purple">' + I18N['admin.role.agency_owner'] + '</span>';
        // Phase 9: قبل كده role='manager' كان بيقع في الحالة الافتراضية
        // (نفس شكل عميل عادي) - يعني مفيش أي طريقة تفرّق بصريًا بين مدير
        // فوترة وعميل عادي في جدول المستخدمين.
        if (role === 'manager') return '<span class="pill orange">🧾 مدير فوترة</span>';
        if (role === 'agent') return '<span class="pill gray">👁️ اطّلاع فوترة</span>';
        return '<span class="pill gray">' + I18N['admin.role.client'] + '</span>';
    }

    async function loadOverview() {
        const [statsRes, visitorsRes, usersRes, subsRes] = await Promise.all([
            fetchJSON('/api/admin/system/stats'),
            fetchJSON('/api/admin/visitors/stats?days=30'),
            fetchJSON('/api/admin/users'),
            fetchJSON('/api/admin/subscriptions'),
        ]);

        if (statsRes.success) {
            const d = statsRes.data || {};
            document.getElementById('statUsers').textContent = d.users_count ?? 0;
            document.getElementById('statSubs').textContent = d.active_subscriptions ?? 0;
            document.getElementById('statWebsites').textContent = d.websites_count ?? 0;
        }

        if (visitorsRes.success) {
            const d = visitorsRes.data || {};
            document.getElementById('statVisitors').textContent = d.unique_visitors ?? 0;

            const byDay = d.by_day || [];
            const ctx = document.getElementById('visitorsChart');
            if (ctx) {
                destroyChart('visitors');
                charts.visitors = makeChart(ctx, {
                    type: 'line',
                    data: {
                        labels: byDay.map(r => r.day),
                        datasets: [{
                            label: I18N['admin.chart.visits'],
                            data: byDay.map(r => r.visits),
                            borderColor: '#0077be',
                            backgroundColor: 'rgba(0,119,190,0.08)',
                            tension: 0.35, fill: true, pointRadius: 0,
                        }, {
                            label: I18N['admin.chart.unique_visitors'],
                            data: byDay.map(r => r.unique_visitors),
                            borderColor: '#17a673',
                            backgroundColor: 'rgba(23,166,115,0.06)',
                            tension: 0.35, fill: true, pointRadius: 0,
                        }],
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } }, scales: { y: { beginAtZero: true } } },
                });
            }

            const devices = d.by_device || [];
            const dctx = document.getElementById('devicesChart');
            if (dctx) {
                destroyChart('devices');
                const colors = ['#0077be', '#17a673', '#d9822b', '#7c3aed', '#e5484d'];
                charts.devices = makeChart(dctx, {
                    type: 'doughnut',
                    data: {
                        labels: devices.map(r => r.device_type || I18N['admin.unknown']),
                        datasets: [{ data: devices.map(r => r.visits), backgroundColor: colors, borderWidth: 0 }],
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } } },
                });
            }
        }

        if (usersRes.success && Array.isArray(usersRes.data.users)) {
            const latest = usersRes.data.users.slice(0, 5);
            const tbody = document.querySelector('#latestUsersTable tbody');
            tbody.innerHTML = latest.length ? latest.map(u => `
                <tr>
                    <td><span class="p-avatar-sm">${esc(initials(u.company_name || u.display_name || u.email))}</span>${esc(u.company_name || u.display_name || u.email)}</td>
                    <td class="p-cell-muted">${esc(u.email)}</td>
                    <td>${statusPill(u.is_active)}</td>
                </tr>`).join('') : '<tr><td colspan="3" class="p-cell-muted text-center">' + I18N['admin.no_users'] + '</td></tr>';
        }

        if (subsRes.success && Array.isArray(subsRes.data.subscriptions)) {
            const latest = subsRes.data.subscriptions.slice(0, 5);
            const tbody = document.querySelector('#latestSubsTable tbody');
            tbody.innerHTML = latest.length ? latest.map(s => `
                <tr>
                    <td>${esc(s.company_name || s.email)}</td>
                    <td>${esc(s.plan_display_name || s.plan_name || '-')}</td>
                    <td><span class="pill ${s.status === 'active' ? 'green' : 'gray'}">${esc(s.status)}</span></td>
                </tr>`).join('') : '<tr><td colspan="3" class="p-cell-muted text-center">' + I18N['admin.no_subs'] + '</td></tr>';
        }
    }

    async function loadUsers() {
        const res = await fetchJSON('/api/admin/users');
        const tbody = document.querySelector('#usersTable tbody');
        if (!res.success || !Array.isArray(res.data.users)) {
            tbody.innerHTML = '<tr><td colspan="8" class="p-cell-muted text-center">' + I18N['admin.load_users_failed'] + '</td></tr>';
            return;
        }
        usersCache = res.data.users;
        renderUsersTable(usersCache);
    }

    function renderUsersTable(list) {
        const tbody = document.querySelector('#usersTable tbody');
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="p-cell-muted text-center">' + I18N['admin.no_matching_users'] + '</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(u => `
            <tr>
                <td><span class="p-avatar-sm">${esc(initials(u.company_name || u.display_name || u.email))}</span><strong>${esc(u.company_name || u.display_name || u.email)}</strong></td>
                <td class="p-cell-muted">${esc(u.email)}</td>
                <td>${roleLabel(u.role)}</td>
                <td class="p-cell-muted">${u.plan_name ? esc(u.plan_name) : '—'}</td>
                <td>${statusPill(u.is_active)}${u.two_factor_enabled == 1 ? ' <span class="pill blue" title="2FA">2FA</span>' : ''}</td>
                <td class="p-cell-muted" data-sort="${esc(u.last_login_at || '')}">${u.last_login_at ? (window.AdminPro ? AdminPro.ago(u.last_login_at) : timeAgo(u.last_login_at)) : '—'}</td>
                <td class="p-cell-muted" data-sort="${esc(u.created_at || '')}">${formatDate(u.created_at)}</td>
                <td>
                    <button class="p-btn outline xs" onclick="viewUser(${u.id})">${I18N['common.view']}</button>
                    <button class="p-btn ${u.is_active == 1 ? 'danger' : 'success'} xs" onclick="toggleUser(${u.id}, ${u.is_active == 1 ? 0 : 1}, this)">
                        ${u.is_active == 1 ? I18N['admin.suspend'] : I18N['admin.activate']}
                    </button>
                    ${(u.role === 'user' || u.role === 'manager' || u.role === 'agent') ? `
                    <select class="p-select xs" onchange="changeBillingRole(${u.id}, this.value, this)" style="width:auto;display:inline-block;">
                        <option value="user" ${u.role === 'user' ? 'selected' : ''}>عميل عادي</option>
                        <option value="agent" ${u.role === 'agent' ? 'selected' : ''}>👁️ اطّلاع فوترة</option>
                        <option value="manager" ${u.role === 'manager' ? 'selected' : ''}>🧾 مدير فوترة</option>
                    </select>` : ''}
                </td>
            </tr>`).join('');
    }

    function applyUserFilters() {
        const q = (document.getElementById('userSearch').value || '').trim().toLowerCase();
        const role = document.getElementById('userRoleFilter').value;
        const status = document.getElementById('userStatusFilter').value;
        let list = usersCache.filter(u => {
            if (q && !(String(u.company_name || '').toLowerCase().includes(q) || String(u.display_name || '').toLowerCase().includes(q) || String(u.email).toLowerCase().includes(q))) return false;
            if (role && u.role !== role) return false;
            if (status === 'active' && u.is_active != 1) return false;
            if (status === 'inactive' && u.is_active == 1) return false;
            return true;
        });
        renderUsersTable(list);
    }
    window.applyUserFilters = applyUserFilters;

    window.toggleUser = async function (id, activate, btn) {
        btn.disabled = true;
        const endpoint = activate ? `/api/admin/users/${id}/activate` : `/api/admin/users/${id}/suspend`;
        try {
            const res = await fetchJSON(endpoint, { method: 'POST' });
            if (res.success) {
                toast(activate ? I18N['admin.account_activated'] : I18N['admin.account_suspended'], 'success');
                loadUsers();
            } else {
                toast(res.error || I18N['admin.generic_error'], 'error');
                btn.disabled = false;
            }
        } catch (e) {
            toast(I18N['admin.connection_error'], 'error');
            btn.disabled = false;
        }
    };

    // Phase 9/11: تغيير دور الفوترة (user / agent-اطّلاع / manager-مدير
    // فوترة) - بيستخدم endpoint تحديث المستخدم الموجود بالفعل. الدروب
    // داون ده مش هيظهر أصلاً لصفوف admin/super_admin (شوف renderUsersTable).
    window.changeBillingRole = async function (id, newRole, select) {
        select.disabled = true;
        try {
            const res = await fetchJSON(`/api/admin/users/${id}`, {
                method: 'PUT', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ role: newRole }),
            });
            if (res.success) {
                const labels = { user: 'عميل عادي', agent: 'اطّلاع فوترة', manager: 'مدير فوترة' };
                toast(`تم تغيير الدور إلى: ${labels[newRole] || newRole} ✔`, 'success');
                loadUsers();
            } else {
                toast(res.error || I18N['admin.generic_error'], 'error');
            }
        } catch (e) {
            toast(I18N['admin.connection_error'], 'error');
        } finally {
            select.disabled = false;
        }
    };

    window.viewUser = async function (id) {
        P.openModal('userModal');
        document.getElementById('userModalBody').innerHTML = `<div class="p-empty">${I18N['common.loading']}</div>`;
        const [userRes, historyRes] = await Promise.all([
            fetchJSON(`/api/admin/users/${id}`),
            fetchJSON(`/api/admin/users/${id}/login-history`),
            ensureFeatureKeysCache(),
        ]);
        if (!userRes.success) {
            document.getElementById('userModalBody').innerHTML = `<div class="p-empty">${I18N['admin.load_user_failed']}</div>`;
            return;
        }
        const u = userRes.data.user;
        const websites = userRes.data.websites || [];
        const subscription = userRes.data.subscription;
        const walletBalance = userRes.data.wallet_balance || 0;
        const journey = userRes.data.journey || [];
        const featureOverrides = userRes.data.feature_overrides || [];
        const history = (historyRes.success && Array.isArray(historyRes.data.login_history)) ? historyRes.data.login_history.slice(0, 5) : [];

        const historyHtml = history.length ? history.map(h => `
            <div class="p-kv">
                <span class="k">${h.status === 'success' ? '✅' : '❌'} ${esc(h.ip_address || '-')} · ${esc(h.device_type || '-')} / ${esc(h.browser || '-')}</span>
                <span class="v" style="font-weight:600;">${timeAgo(h.created_at)}</span>
            </div>`).join('') : '<div class="p-cell-muted" style="padding:8px 0;">' + I18N['admin.no_login_history'] + '</div>';

        const websitesHtml = websites.length ? websites.map(w => `
            <div class="p-kv">
                <span class="k">${w.is_verified == 1 ? '✅' : '⏳'} ${esc(w.company_name || w.main_url)}</span>
                <span class="v p-cell-muted" style="font-size:11px;" dir="ltr">${esc(w.main_url)}</span>
            </div>`).join('') : '<div class="p-cell-muted" style="padding:8px 0;">' + I18N['admin.customer_no_websites'] + '</div>';

        const subHtml = subscription
            ? `<span class="pill green">${esc(subscription.plan_name || '-')} · ${esc(subscription.plan_type === 'yearly' ? I18N['admin.yearly'] : I18N['admin.monthly'])}</span>`
            : `<span class="pill gray">${I18N['admin.customer_no_subscription']}</span>`;

        const journeyHtml = journey.length ? journey.map(j => `
            <div class="p-kv">
                <span class="k">${esc(j.module)} · ${esc(j.action)}</span>
                <span class="v p-cell-muted" style="font-size:11px;">${timeAgo(j.created_at)}</span>
            </div>`).join('') : '<div class="p-cell-muted" style="padding:8px 0;">' + I18N['admin.journey.empty'] + '</div>';

        const overridesHtml = featureOverrides.length ? featureOverrides.map(o => `
            <div class="p-kv">
                <span class="k">${esc(o.feature_key)} ${o.is_enabled == 1 ? '✅' : '🚫'}</span>
                <span class="v"><button class="p-btn outline xs" onclick="removeFeatureOverride(${u.id}, '${o.feature_key}')">${I18N['common.cancel']}</button></span>
            </div>`).join('') : `<div class="p-cell-muted" style="padding:4px 0;font-size:12px;">${I18N['admin.features.no_overrides']}</div>`;

        const canImpersonate = !['admin', 'super_admin'].includes(u.role) && u.is_active == 1;
        // تصحيح: القائمة كانت ناقصة (manager/agent/client) فأي عميل دوره manager
        // كان بيظهر "user" في القائمة، وضغطة "حفظ" بتنزّل دوره بالغلط.
        const isSuperActor = window.ADMIN_ROLE === 'super_admin';
        const roleOptions = ['user', 'client', 'agent', 'manager', 'agency_owner']
            .concat(isSuperActor || ['admin', 'super_admin'].includes(u.role) ? ['admin', 'super_admin'] : []);
        if (!roleOptions.includes(u.role)) roleOptions.unshift(u.role);
        const roleLocked = ['admin', 'super_admin'].includes(u.role) && !isSuperActor;
        const roleOptLabel = r => roleLabel(r).replace(/<[^>]+>/g, '') + (r === 'user' || r === 'client' ? ' (' + r + ')' : '');

        document.getElementById('userModalTitle').textContent = u.company_name || I18N['admin.user_details'];
        document.getElementById('userModalBody').innerHTML = `
            <div class="p-kv"><span class="k">${I18N['settings.email']}</span><span class="v">${esc(u.email)}</span></div>
            <div class="p-kv">
                <span class="k">${I18N['settings.role']}</span>
                <span class="v">
                    <select id="userRoleSelect" class="p-select xs" ${roleLocked ? 'disabled' : ''}>
                        ${roleOptions.map(r => `<option value="${esc(r)}" ${r === u.role ? 'selected' : ''}>${esc(roleOptLabel(r))}</option>`).join('')}
                    </select>
                    ${roleLocked ? '' : `<button class="p-btn outline xs" onclick="saveUserRole(${u.id})" style="margin-inline-start:6px;">${I18N['common.save']}</button>`}
                </span>
            </div>
            <div class="p-kv"><span class="k">${I18N['chat.col.status']}</span><span class="v">${statusPill(u.is_active)}</span></div>
            <div class="p-kv"><span class="k">${I18N['admin.registration_date']}</span><span class="v">${formatDate(u.created_at)}</span></div>
            <div class="p-kv"><span class="k">${I18N['subscription.page_title']}</span><span class="v">${subHtml}</span></div>
            <div class="p-kv"><span class="k">💰 ${I18N['wallet.title']}</span><span class="v is-money" style="font-weight:700;" dir="ltr">$${Number(walletBalance).toFixed(2)}</span></div>
            <div style="display:flex;gap:6px;margin-bottom:6px;">
                <input type="number" id="addBalanceAmount" class="p-select xs" style="flex:1;" placeholder="${I18N['admin.wallet.amount_placeholder']}" min="0.01" step="0.01">
                <input type="text" id="balanceNote" class="p-select xs" style="flex:2;" maxlength="200" placeholder="${T('admin.wallet.note_placeholder', 'ملاحظة (اختياري)')}">
            </div>
            <div style="display:flex;gap:6px;margin-bottom:12px;">
                <button class="p-btn success xs" style="flex:1;" onclick="addUserBalance(${u.id})">+ ${I18N['admin.wallet.add_balance']}</button>
                <button class="p-btn danger xs" style="flex:1;" onclick="deductUserBalance(${u.id})">− ${T('admin.wallet.deduct_balance', 'سحب رصيد')}</button>
            </div>
            <h4 style="margin:16px 0 8px;font-size:13.5px;">🌐 ${I18N['sidebar.websites']} (${websites.length})</h4>
            ${websitesHtml}
            <h4 style="margin:16px 0 8px;font-size:13.5px;">🎛️ ${I18N['admin.features.title']}</h4>
            <div style="display:flex;gap:6px;margin-bottom:8px;">
                <select id="overrideFeatureKey" class="p-select xs" style="flex:1;">
                    ${Object.keys(FEATURE_KEYS_CACHE).map(k => `<option value="${k}">${esc(FEATURE_KEYS_CACHE[k])}</option>`).join('')}
                </select>
                <button class="p-btn success xs" onclick="addFeatureOverride(${u.id}, true)">✔ ${I18N['admin.features.force_on']}</button>
                <button class="p-btn danger xs" onclick="addFeatureOverride(${u.id}, false)">🚫 ${I18N['admin.features.force_off']}</button>
            </div>
            ${overridesHtml}
            <h4 style="margin:16px 0 8px;font-size:13.5px;">🧭 ${I18N['admin.journey.title']}</h4>
            <div style="max-height:220px;overflow-y:auto;">${journeyHtml}</div>
            <h4 style="margin:16px 0 8px;font-size:13.5px;">${I18N['admin.last_login_attempts']}</h4>
            ${historyHtml}
        `;

        const footer = document.getElementById('userModalFooter');
        footer.innerHTML = `
            <button class="p-btn outline" onclick="P.closeModal('userModal')">${I18N['admin.close']}</button>
            ${canImpersonate ? `<button class="p-btn primary" onclick="impersonate(${u.id})">🔑 ${I18N['admin.impersonate']}</button>` : ''}
        `;
    };

    window.saveUserRole = async function (id) {
        const role = document.getElementById('userRoleSelect').value;
        if (!confirm(I18N['admin.change_role_confirm'])) return;
        const res = await fetchJSON(`/api/admin/users/${id}`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role }),
        });
        if (res.success) { toast(I18N['common.updated'], 'success'); viewUser(id); loadUsers(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    async function changeUserBalance(userId, mode) {
        const amount = parseFloat(document.getElementById('addBalanceAmount').value);
        if (!amount || amount <= 0) { toast(I18N['admin.wallet.enter_valid_amount'], 'error'); return; }
        const note = (document.getElementById('balanceNote') || {}).value || '';
        const msg = mode === 'deduct'
            ? T('admin.wallet.deduct_balance_confirm', 'متأكد من سحب ${amount}$ من رصيد العميل ده؟')
            : I18N['admin.wallet.add_balance_confirm'];
        if (!confirm(msg.replace('{amount}', amount))) return;

        const res = await fetchJSON('/api/admin/users/' + userId + '/' + (mode === 'deduct' ? 'deduct-balance' : 'add-balance'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ amount, note }),
        });
        if (res.success) {
            toast(mode === 'deduct' ? T('admin.wallet.balance_deducted', 'تم سحب الرصيد') : I18N['admin.wallet.balance_added'], 'success');
            viewUser(userId);
        } else toast(res.error || I18N['common.update_failed'], 'error');
    }
    window.addUserBalance = function (userId) { return changeUserBalance(userId, 'add'); };
    window.deductUserBalance = function (userId) { return changeUserBalance(userId, 'deduct'); };

    // ---- رصيد المنصة: عرض + تصفير
    function renderPlatformBalance(s) {
        const box = document.getElementById('platformBalanceBox');
        if (!box) return;
        const perms = window.ADMIN_PERMS || [];
        const canReset = perms.includes('billing.settings');
        const isSuper = window.ADMIN_ROLE === 'super_admin';
        const baseline = Number(s.platform_balance_baseline || 0);
        const raw = Number(s.total_customer_balances_raw !== undefined ? s.total_customer_balances_raw : s.total_customer_balances);
        box.innerHTML = `
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>🏦 ${T('admin.platform_balance.title', 'رصيد المنصة')}</h3>
                    <span class="p-card-sub">${T('admin.platform_balance.sub', 'إجمالي أرصدة العملاء في المحافظ')}</span></div>
                <div class="p-kv"><span class="k">${T('admin.platform_balance.displayed', 'المعروض حاليًا')}</span><span class="v is-money" dir="ltr" style="font-weight:700;">$${Number(s.total_customer_balances).toFixed(2)}</span></div>
                <div class="p-kv"><span class="k">${T('admin.platform_balance.actual', 'الإجمالي الفعلي لأرصدة العملاء')}</span><span class="v is-money" dir="ltr">$${raw.toFixed(2)}</span></div>
                ${s.platform_balance_baseline_at ? `<div class="p-kv"><span class="k">${T('admin.platform_balance.reset_at', 'آخر تصفير للعدّاد')}</span><span class="v p-cell-muted">${esc(s.platform_balance_baseline_at)}</span></div>` : ''}
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                    ${canReset ? `<button class="p-btn outline xs" onclick="resetPlatformBalance()">♻️ ${T('admin.platform_balance.reset', 'تصفير رصيد المنصة')}</button>` : ''}
                    ${canReset && baseline !== 0 ? `<button class="p-btn outline xs" onclick="restorePlatformBalance()">↩️ ${T('admin.platform_balance.restore', 'رجوع للرصيد الفعلي')}</button>` : ''}
                    ${isSuper ? `<button class="p-btn danger xs" onclick="zeroAllBalances()">⚠️ ${T('admin.platform_balance.zero_all', 'تصفير أرصدة كل العملاء')}</button>` : ''}
                </div>
                <div class="p-cell-muted" style="font-size:11.5px;margin-top:8px;line-height:1.7;">
                    ${T('admin.platform_balance.hint', '«تصفير رصيد المنصة» بيصفّر العدّاد المعروض هنا فقط ومبيمسّش أرصدة العملاء. «تصفير أرصدة كل العملاء» بيخصم فعليًا رصيد كل عميل (حركات مسجّلة في السجل) ومينفعش يتراجع عنه.')}
                </div>
            </div>`;
    }

    window.resetPlatformBalance = async function () {
        if (!confirm(T('admin.platform_balance.reset_confirm', 'تصفير عدّاد رصيد المنصة؟ (مبيغيّرش أرصدة العملاء وتقدر ترجع له)'))) return;
        const res = await fetchJSON('/api/admin/wallet/platform-balance/reset', { method: 'POST' });
        if (res.success) { toast(T('admin.platform_balance.reset_done', 'تم تصفير رصيد المنصة'), 'success'); loadWalletStats(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };
    window.restorePlatformBalance = async function () {
        const res = await fetchJSON('/api/admin/wallet/platform-balance/restore', { method: 'POST' });
        if (res.success) { toast(T('admin.platform_balance.restored', 'تم الرجوع للرصيد الفعلي'), 'success'); loadWalletStats(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };
    window.zeroAllBalances = async function () {
        const typed = prompt(T('admin.platform_balance.zero_all_prompt', '⚠️ ده هيخصم رصيد كل العملاء فعليًا ومينفعش يتراجع عنه.\nاكتب ZERO للتأكيد:'));
        if (typed === null) return;
        if (typed.trim() !== 'ZERO') { toast(T('admin.platform_balance.zero_all_cancel', 'اتلغت العملية - التأكيد غير صحيح'), 'error'); return; }
        const res = await fetchJSON('/api/admin/wallet/zero-all-balances', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ confirm: 'ZERO' }),
        });
        if (res.success) { toast(res.message || T('admin.platform_balance.zero_all_done', 'تم تصفير الأرصدة'), 'success'); loadWalletStats(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    // ---- إخفاء/إظهار الأرصدة (للعرض فقط في المتصفح ده)
    window.toggleBalances = function () {
        const hide = !document.body.classList.contains('hide-balances');
        document.body.classList.toggle('hide-balances', hide);
        try { localStorage.setItem('tf_hide_balances', hide ? '1' : '0'); } catch (e) {}
        const b = document.getElementById('balToggle');
        if (b) { b.textContent = hide ? '🙈' : '👁️'; b.title = hide ? T('admin.balances.show', 'إظهار الأرصدة') : T('admin.balances.hide', 'إخفاء الأرصدة'); }
    };
    (function initBalanceMask() {
        let hide = false;
        try { hide = localStorage.getItem('tf_hide_balances') === '1'; } catch (e) {}
        document.body.classList.toggle('hide-balances', hide);
        const b = document.getElementById('balToggle');
        if (b) { b.textContent = hide ? '🙈' : '👁️'; b.title = hide ? T('admin.balances.show', 'إظهار الأرصدة') : T('admin.balances.hide', 'إخفاء الأرصدة'); }
    })();

    window.addFeatureOverride = async function (userId, isEnabled) {
        const featureKey = document.getElementById('overrideFeatureKey').value;
        const res = await fetchJSON(`/api/admin/users/${userId}/feature-overrides`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ feature_key: featureKey, is_enabled: isEnabled ? 1 : 0 }),
        });
        if (res.success) { toast(I18N['common.updated'], 'success'); viewUser(userId); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    window.removeFeatureOverride = async function (userId, featureKey) {
        const res = await fetchJSON(`/api/admin/users/${userId}/feature-overrides/${featureKey}`, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.deleted'], 'success'); viewUser(userId); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    let faqCache = [];

    async function ensureFeatureKeysCache() {
        if (Object.keys(FEATURE_KEYS_CACHE).length > 0) return;
        const res = await fetchJSON('/api/admin/features');
        if (res.success && res.data.features) {
            res.data.features.forEach(f => { FEATURE_KEYS_CACHE[f.feature_key] = f.label; });
        }
    }

    async function loadFeaturesList() {
        const res = await fetchJSON('/api/admin/features');
        const box = document.getElementById('featuresList');
        if (!res.success || !res.data.features || !res.data.features.length) {
            if (box) box.innerHTML = `<div class="p-empty">${I18N['common.loading']}</div>`;
            return;
        }
        res.data.features.forEach(f => { FEATURE_KEYS_CACHE[f.feature_key] = f.label; });
        if (!box) return;
        box.innerHTML = res.data.features.map(f => `
            <div class="p-kv">
                <span class="k">${esc(f.label)}</span>
                <span class="v">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" ${f.is_enabled == 1 ? 'checked' : ''} onchange="toggleFeature('${f.feature_key}', this.checked)">
                        ${f.is_enabled == 1 ? I18N['admin.status.active'] : I18N['admin.status.suspended']}
                    </label>
                </span>
            </div>`).join('');
    }

    window.toggleFeature = async function (key, isEnabled) {
        const res = await fetchJSON('/api/admin/features/' + key, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ is_enabled: isEnabled ? 1 : 0 }),
        });
        if (res.success) toast(I18N['common.updated'], 'success');
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    async function loadSystemSettings() {
        const res = await fetchJSON('/api/admin/system-settings');
        if (!res.success) return;
        const settings = res.data.settings || {};
        const budgetBox=document.getElementById('ss_serp_budget_status'),budget=res.data.seo_provider_budget;
        if(budgetBox){const money=n=>Number(n||0).toFixed(6);budgetBox.textContent=budget?.stats_available?
            'شهر '+budget.period_utc+' (UTC) · السقف: '+money(budget.cap)+' USD · تكلفة مسجلة: '+money(budget.actual_cost)+' USD · محجوز لطلبات سابقة: '+money(budget.held_cost)+' USD · المتبقي: '+money(budget.remaining)+' USD. طلبات بنتيجة غير مؤكدة: '+Number(budget.unknown_calls)+' · معلقة: '+Number(budget.pending_calls)+'. '+(budget.available?'السقف يسمح بحجز طلب بحث جديد.':'القياس متوقف؛ راجع التفعيل والسقف والمستهلك والمحجوز قبل تغييره.'):
            'تعذر قراءة سجل ميزانية المزود؛ لا نفترض أن المتبقي صفر أو نغيّر السقف.';}


        Object.keys(settings).forEach(category => {
            settings[category].forEach(item => {
                if (category === 'branding') {
                    const el = document.getElementById('br_' + item.key);
                    if (el) el.value = item.value || '';
                    if (item.key === 'site_logo_url' && item.value) {
                        document.getElementById('brLogoPreview').innerHTML = `<img src="${item.value}" style="max-width:100%;max-height:100%;object-fit:contain;">`;
                    }
                    if (item.key === 'site_favicon_url' && item.value) {
                        document.getElementById('brFaviconPreview').innerHTML = `<img src="${item.value}" style="max-width:100%;max-height:100%;object-fit:contain;">`;
                    }
                } else if (category === 'legal') {
                    if (item.key === 'terms_content') document.getElementById('legalTermsContent').value = item.value || '';
                    if (item.key === 'privacy_content') document.getElementById('legalPrivacyContent').value = item.value || '';
                    if (item.key === 'refund_policy_content' && document.getElementById('legalRefundContent')) document.getElementById('legalRefundContent').value = item.value || '';
                } else {
                    const el = document.getElementById('ss_' + item.key);
                    if (el && !item.is_secret) el.value = item.value || (item.key.endsWith('_enabled') ? '0' : (item.key === 'serp_device' ? 'desktop' : ''));
                    if (el && item.is_secret && item.is_set) el.placeholder = '•••• (مسجّل - اكتب قيمة جديدة عشان تغيّرها)';
                }
            });
        });
    }

    window.saveLegalContent = async function () {
        const alertBox = document.getElementById('legalAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/admin/legal-content', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                terms_content: document.getElementById('legalTermsContent').value,
                privacy_content: document.getElementById('legalPrivacyContent').value,
                refund_policy_content: document.getElementById('legalRefundContent') ? document.getElementById('legalRefundContent').value : null,
            }),
        });

        if (res.success) toast(I18N['common.updated'], 'success');
        else { alertBox.textContent = res.error || I18N['common.update_failed']; alertBox.style.display = 'block'; }
    };

    window.uploadSiteLogo = async function (input) {
        if (!input.files || !input.files[0]) return;
        const formData = new FormData();
        formData.append('logo', input.files[0]);

        const res = await fetch('/api/admin/branding/logo', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            document.getElementById('brLogoPreview').innerHTML = `<img src="${data.data.url}" style="max-width:100%;max-height:100%;object-fit:contain;">`;
            toast(I18N['admin.brand.logo_uploaded'], 'success');
        } else {
            toast(data.error || I18N['admin.brand.upload_failed'], 'error');
        }
    };

    window.uploadSiteFavicon = async function (input) {
        if (!input.files || !input.files[0]) return;
        const formData = new FormData();
        formData.append('favicon', input.files[0]);

        const res = await fetch('/api/admin/branding/favicon', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            document.getElementById('brFaviconPreview').innerHTML = `<img src="${data.data.url}" style="max-width:100%;max-height:100%;object-fit:contain;">`;
            toast(I18N['admin.brand.logo_uploaded'], 'success');
        } else {
            toast(data.error || I18N['admin.brand.upload_failed'], 'error');
        }
    };

    window.saveBrandSettings = async function () {
        const alertBox = document.getElementById('brandAlert');
        alertBox.style.display = 'none';

        const payload = {
            site_name: document.getElementById('br_site_name').value.trim(),
            site_logo_height: document.getElementById('br_site_logo_height').value,
            contact_phone: document.getElementById('br_contact_phone').value.trim(),
            contact_email: document.getElementById('br_contact_email').value.trim(),
            site_address: document.getElementById('br_site_address').value.trim(),
        };

        const res = await fetchJSON('/api/admin/branding', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        if (res.success) toast(I18N['common.updated'], 'success');
        else { alertBox.textContent = res.error || I18N['common.update_failed']; alertBox.style.display = 'block'; }
    };

    window.saveSystemSettings = async function () {
        const alertBox = document.getElementById('sysSettingsAlert');
        alertBox.style.display = 'none';

        const keys = [
            'serp_local_enabled', 'serp_backlinks_enabled', 'serp_login', 'serp_password', 'serp_enabled', 'serp_monthly_budget_usd', 'serp_signup_daily_usd', 'serp_device',
            'geo_enabled', 'geo_monthly_budget_usd', 'geo_openai_enabled', 'geo_openai_api_key', 'geo_gemini_enabled', 'geo_gemini_api_key', 'geo_perplexity_enabled', 'geo_perplexity_api_key',
            'gemini_api_key', 'google_client_id', 'google_client_secret', 'google_maps_api_key',
            'meta_app_id', 'meta_app_secret', 'support_whatsapp_number',
            'meta_login_config_id', 'meta_signin_config_id', 'meta_wa_config_id', 'meta_oauth_scopes',
            'wa_qr_enabled', 'wa_qr_base_url', 'wa_qr_api_key', 'tiktok_client_key', 'tiktok_client_secret', 'stripe_connect_client_id', 'stripe_platform_secret_key',
            'mail_host', 'mail_port', 'mail_username', 'mail_password',
            'mail_encryption', 'mail_from_address', 'mail_from_name',
            'oauth_microsoft_client_id', 'oauth_microsoft_client_secret', 'oauth_microsoft_tenant',
            'oauth_apple_client_id', 'oauth_apple_team_id', 'oauth_apple_key_id', 'oauth_apple_private_key',
        ];

        const payload = {};
        keys.forEach(key => {
            const el = document.getElementById('ss_' + key);
            if (el && el.value.trim() !== '') payload[key] = el.value.trim();
        });

        const res = await fetchJSON('/api/admin/system-settings', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        if (res.success) {
            toast(I18N['common.updated'], 'success');
            // نفضّي حقول القيم الحساسة اللي اتبعتت عشان محدش يشوفها معروضة تاني
            ['wa_qr_api_key','tiktok_client_secret','stripe_platform_secret_key','geo_openai_api_key','geo_gemini_api_key','geo_perplexity_api_key','serp_login', 'serp_password', 'gemini_api_key', 'google_client_secret', 'google_maps_api_key', 'meta_app_secret', 'mail_password', 'oauth_microsoft_client_secret', 'oauth_apple_private_key'].forEach(key => {
                const el = document.getElementById('ss_' + key);
                if (el) el.value = '';
            });
            loadSystemSettings();
        } else {
            alertBox.textContent = res.error || I18N['common.update_failed'];
            alertBox.style.display = 'block';
        }
    };

    window.testWaQrGateway = async function (btn) {
        const url = (document.getElementById('ss_wa_qr_base_url') || {}).value || '';
        const key = (document.getElementById('ss_wa_qr_api_key') || {}).value || '';
        btn.disabled = true;
        try {
            const res = await fetchJSON('/api/admin/whatsapp-qr/test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ base_url: url.trim(), api_key: key.trim() }) });
            toast(res.success ? '✅ البوابة شغالة والمفتاح صحيح' : ('❌ ' + (res.error || 'تعذر الاتصال')), res.success ? 'success' : 'error');
        } finally { btn.disabled = false; }
    };

    async function loadFaqList() {
        const res = await fetchJSON('/api/admin/faq');
        const box = document.getElementById('faqList');
        if (!box) return;
        if (!res.success || !res.data.items || !res.data.items.length) {
            box.innerHTML = `<div class="p-empty">${I18N['admin.faq.none']}</div>`;
            return;
        }
        faqCache = res.data.items;
        box.innerHTML = faqCache.map(f => `
            <div class="p-card" style="margin-bottom:10px;${f.is_active == 0 ? 'opacity:.55;' : ''}">
                <div class="p-card-head">
                    <h3 style="font-size:14px;">${esc(f.question)}</h3>
                    <span style="display:flex;gap:6px;">
                        <button class="p-btn outline xs" onclick="openEditFaq(${f.id})">✏️</button>
                        <button class="p-btn danger xs" onclick="deleteFaq(${f.id})">🗑️</button>
                    </span>
                </div>
                <p class="p-cell-muted" style="font-size:12.5px;margin:0;">${esc((f.answer || '').slice(0, 150))}</p>
            </div>`).join('');
    }

    window.openEditFaq = function (id) {
        const f = id ? faqCache.find(x => x.id == id) : null;
        document.getElementById('faqModalTitle').textContent = f ? I18N['admin.faq.edit'] : I18N['admin.faq.new'];
        document.getElementById('faqId').value = f ? f.id : '';
        document.getElementById('faqQuestion').value = f ? f.question : '';
        document.getElementById('faqAnswer').value = f ? f.answer : '';
        document.getElementById('faqIsActive').checked = !f || f.is_active == 1;
        document.getElementById('faqAlert').style.display = 'none';
        P.openModal('editFaqModal');
    };

    window.saveFaq = async function () {
        const alertBox = document.getElementById('faqAlert');
        alertBox.style.display = 'none';
        const id = document.getElementById('faqId').value;
        const payload = {
            question: document.getElementById('faqQuestion').value.trim(),
            answer: document.getElementById('faqAnswer').value.trim(),
            is_active: document.getElementById('faqIsActive').checked ? 1 : 0,
        };
        if (!payload.question || !payload.answer) {
            alertBox.textContent = I18N['admin.faq.fields_required'];
            alertBox.style.display = 'block';
            return;
        }

        const res = await fetchJSON(id ? '/api/admin/faq/' + id : '/api/admin/faq', {
            method: id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        if (res.success) {
            toast(I18N['common.updated'], 'success');
            P.closeModal('editFaqModal');
            loadFaqList();
        } else {
            alertBox.textContent = res.error || I18N['common.update_failed'];
            alertBox.style.display = 'block';
        }
    };

    window.deleteFaq = async function (id) {
        if (!confirm(I18N['admin.faq.delete_confirm'])) return;
        const res = await fetchJSON('/api/admin/faq/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.deleted'], 'success'); loadFaqList(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    window.sendBroadcast = async function () {
        const alertBox = document.getElementById('broadcastAlert');
        alertBox.style.display = 'none';
        const audience = document.getElementById('broadcastAudience').value;
        const title = document.getElementById('broadcastTitle').value.trim();
        const body = document.getElementById('broadcastBody').value.trim();

        if (!title) {
            alertBox.textContent = I18N['admin.broadcast.title_required'];
            alertBox.style.display = 'block';
            return;
        }
        if (!confirm(I18N['admin.broadcast.confirm'])) return;

        const res = await fetchJSON('/api/admin/broadcast', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ audience, title, body }),
        });

        if (res.success) {
            toast(I18N['admin.broadcast.sent'].replace('{count}', res.data.sent_count), 'success');
            P.closeModal('broadcastModal');
            document.getElementById('broadcastTitle').value = '';
            document.getElementById('broadcastBody').value = '';
        } else {
            alertBox.textContent = res.error || I18N['common.update_failed'];
            alertBox.style.display = 'block';
        }
    };

    window.impersonate = async function (id) {
        if (!confirm(I18N['admin.impersonate_confirm'])) return;
        const res = await fetchJSON(`/api/admin/users/${id}/impersonate`, { method: 'POST' });
        if (res.success) {
            window.location.href = '/dashboard';
        } else {
            toast(res.error || I18N['admin.impersonate_failed'], 'error');
        }
    };

    window.activateManualSubscription = async function () {
        const email = document.getElementById('manualUserEmail').value.trim();
        const planName = document.getElementById('manualPlanName').value;
        const planType = document.getElementById('manualPlanType').value;
        const alertBox = document.getElementById('manualActivateAlert');
        alertBox.style.display = 'none';

        if (!email) { toast(I18N['admin.write_customer_email'], 'error'); return; }

        const res = await fetchJSON('/api/admin/subscriptions/activate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, plan_name: planName, plan_type: planType }),
        });

        if (res.success) {
            toast(I18N['admin.subscription_activated'], 'success');
            document.getElementById('manualUserEmail').value = '';
            loadSubscriptions();
        } else {
            alertBox.textContent = res.error || I18N['admin.activate_sub_failed'];
            alertBox.style.display = 'block';
        }
    };

    // أسماء الباقات زي ما العميل بيشوفها (الانطلاقة / النمو / الريادة) مع السعر، بدل أسماء ثابتة مختلفة
    async function loadManualPlans() {
        const sel = document.getElementById('manualPlanName');
        if (!sel || sel.dataset.loaded) return;
        const r = await fetchJSON('/api/admin/plans');
        const plans = (r.success && r.data && r.data.plans) || [];
        if (!plans.length) return;
        sel.dataset.loaded = '1';
        sel.innerHTML = plans.filter(p => p.is_active !== 0 && p.is_active !== '0').map(p => '<option value="' + esc(p.plan_key) + '">' + esc(p.name) + ' · ' + esc(p.currency || 'USD') + ' ' + Number(p.price_monthly || 0) + ' / ' + Number(p.price_yearly || 0) + '</option>').join('');
        const em = new URLSearchParams(location.search).get('email');
        if (em) document.getElementById('manualUserEmail').value = em;
    }

    let subsAll = [], subsView = 'current';
    async function loadSubscriptions() {
        loadManualPlans();
        const res = await fetchJSON('/api/admin/subscriptions');
        const tbody = document.querySelector('#subsTable tbody');
        if (!res.success || !Array.isArray(res.data.subscriptions)) {
            tbody.innerHTML = '<tr><td colspan="8" class="p-cell-muted text-center">' + I18N['admin.load_subs_failed'] + '</td></tr>';
            return;
        }
        subsAll = res.data.subscriptions;
        renderSubs();
    }
    // الحالية = اللي شغالة/تجربة/متأخرة (كل تغيير باقة بيعمل صف جديد، فالسجل الكامل بيبقى مزحوم بالملغي)
    function renderSubs() {
        const tbody = document.querySelector('#subsTable tbody');
        const live = ['active', 'trialing', 'past_due', 'pending'];
        const subs = subsView === 'current' ? subsAll.filter(s => live.includes(s.status)) : subsAll;
        document.querySelectorAll('[data-subsview]').forEach(b => { const on = b.dataset.subsview === subsView; b.classList.toggle('primary', on); b.classList.toggle('outline', !on); });
        const cnt = document.getElementById('subsCounts'); if (cnt) cnt.textContent = subsAll.filter(s => live.includes(s.status)).length + ' حالي · ' + subsAll.length + ' في السجل';
        const ST = {active: 'شغّال', trialing: 'تجربة مجانية', past_due: 'متأخر في الدفع', cancelled: 'ملغي', expired: 'منتهي', pending: 'معلّق'};
        tbody.innerHTML = subs.length ? subs.map(s => {
            const grant = s.payment_gateway === 'admin_grant';
            const price = grant ? '<span class="pill blue">🎁 منحة</span>' : (+s.price > 0 ? '<span dir="ltr">$' + esc(Number(s.price).toFixed(2)) + '</span> <span class="p-cell-muted">/ ' + (s.plan_type === 'yearly' ? 'سنة' : 'شهر') + '</span>' : '<span class="p-cell-muted">—</span>');
            const end = s.current_period_end ? formatDate(s.current_period_end) : '—';
            const endNote = !live.includes(s.status) ? '' : (+s.cancel_at_period_end ? ' <span class="pill orange xs">ينتهي ومش هيتجدد</span>' : (s.scheduled_plan_code ? ' <span class="pill blue xs">هيتغير لـ ' + esc(String(s.scheduled_plan_code).replace(/_(monthly|yearly)$/, '')) + '</span>' : ''));
            return `
            <tr>
                <td>#${esc(s.id)}</td>
                <td><strong>${esc(s.company_name || s.email)}</strong>${s.company_name ? `<br><span class="p-cell-muted">${esc(s.email)}</span>` : ''}</td>
                <td>${esc(s.plan_display_name || s.plan || s.plan_name || '-')}</td>
                <td>${price}</td>
                <td><span class="pill ${s.status === 'active' ? 'green' : (s.status === 'cancelled' ? 'red' : (s.status === 'trialing' ? 'blue' : (s.status === 'past_due' ? 'orange' : 'gray')))}">${esc(ST[s.status] || s.status)}</span></td>
                <td class="p-cell-muted" data-sort="${esc(s.current_period_end || '')}">${end}${endNote}</td>
                <td class="p-cell-muted" data-sort="${esc(s.created_at || '')}">${formatDate(s.created_at)}</td>
                <td>${s.status === 'active' ? `<button class="p-btn danger xs" onclick="cancelSub(${s.id}, this)">${I18N['admin.cancel']}</button>` : ''}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="8" class="p-cell-muted text-center">' + I18N['admin.no_subs'] + '</td></tr>';
    }
    document.addEventListener('click', e => { const b = e.target.closest('[data-subsview]'); if (b) { subsView = b.dataset.subsview; renderSubs(); } });

    window.cancelSub = async function (id, btn) {
        if (!confirm(I18N['admin.cancel_sub_confirm'])) return;
        btn.disabled = true;
        const res = await fetchJSON(`/api/admin/subscriptions/${id}/cancel`, { method: 'POST' });
        if (res.success) { toast(I18N['admin.sub_cancelled'], 'success'); loadSubscriptions(); }
        else { toast(res.error || I18N['admin.cancel_failed'], 'error'); btn.disabled = false; }
    };

    async function loadVisitors(days) {
        days = days || 30;
        const [statsRes, logRes] = await Promise.all([
            fetchJSON('/api/admin/visitors/stats?days=' + days),
            fetchJSON('/api/admin/visitors/log?limit=60'),
        ]);

        if (statsRes.success) {
            const d = statsRes.data || {};
            document.getElementById('vTotal').textContent = d.total_visits ?? 0;
            document.getElementById('vUnique').textContent = d.unique_visitors ?? 0;
            document.getElementById('vAuth').textContent = d.authenticated_visits ?? 0;

            const ctx = document.getElementById('visitorsTrendChart');
            if (ctx) {
                destroyChart('vtrend');
                charts.vtrend = makeChart(ctx, {
                    type: 'bar',
                    data: {
                        labels: (d.by_day || []).map(r => r.day),
                        datasets: [{ label: I18N['admin.chart.visits'], data: (d.by_day || []).map(r => r.visits), backgroundColor: '#0077be', borderRadius: 6 }],
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
                });
            }

            const pagesBody = document.querySelector('#topPagesTable tbody');
            const pages = d.top_pages || [];
            pagesBody.innerHTML = pages.length ? pages.map(p => `<tr><td class="p-cell-muted">${esc(p.page_url)}</td><td><strong>${esc(p.visits)}</strong></td></tr>`).join('') : '<tr><td colspan="2" class="p-cell-muted text-center">لا يوجد بيانات</td></tr>';

            const countryBody = document.querySelector('#topCountriesTable tbody');
            const countries = d.by_country || [];
            countryBody.innerHTML = countries.length ? countries.map(c => `<tr><td>${esc(c.country)}</td><td><strong>${esc(c.visits)}</strong></td></tr>`).join('') : '<tr><td colspan="2" class="p-cell-muted text-center">لا يوجد بيانات</td></tr>';
        }

        if (logRes.success && Array.isArray(logRes.data.visitors)) {
            const tbody = document.querySelector('#visitorLogTable tbody');
            const rows = logRes.data.visitors;
            tbody.innerHTML = rows.length ? rows.map(v => `
                <tr>
                    <td class="p-cell-muted">${esc(v.page_url)}</td>
                    <td>${v.company_name ? esc(v.company_name) : '<span class="p-cell-muted">' + I18N['admin.visitor'] + '</span>'}</td>
                    <td class="p-cell-muted">${esc(v.ip_address)}</td>
                    <td>${esc(v.device_type || '-')} / ${esc(v.browser || '-')}</td>
                    <td class="p-cell-muted">${esc(v.country || '-')} ${v.city ? '· ' + esc(v.city) : ''}</td>
                    <td class="p-cell-muted">${timeAgo(v.created_at)}</td>
                </tr>`).join('') : '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.no_visits'] + '</td></tr>';
        }
    }
    window.reloadVisitors = function () {
        const days = document.getElementById('visitorsDays').value;
        loadVisitors(days);
    };

    async function loadLoginHistory() {
        const res = await fetchJSON('/api/admin/login-history?limit=150');
        const tbody = document.querySelector('#loginHistoryTable tbody');
        if (!res.success || !Array.isArray(res.data.login_history)) {
            tbody.innerHTML = '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.load_log_failed'] + '</td></tr>';
            return;
        }
        window.__loginHistoryCache = res.data.login_history;
        renderLoginHistory(window.__loginHistoryCache);
    }

    function renderLoginHistory(list) {
        const tbody = document.querySelector('#loginHistoryTable tbody');
        tbody.innerHTML = list.length ? list.map(h => `
            <tr>
                <td>${h.company_name ? esc(h.company_name) : esc(h.email_attempted || '-')}</td>
                <td>${h.status === 'success' ? '<span class="pill green">✔ ' + I18N['admin.success_word'] + '</span>' : '<span class="pill red">✖ ' + I18N['admin.failed_word'] + '</span>'}${h.is_impersonation == 1 ? ' <span class="pill blue">انتحال</span>' : ''}</td>
                <td class="p-cell-muted">${esc(h.ip_address || '-')}</td>
                <td class="p-cell-muted">${esc(h.device_type || '-')} / ${esc(h.browser || '-')} / ${esc(h.platform || '-')}</td>
                <td class="p-cell-muted">${esc(h.country || '-')} ${h.city ? '· ' + esc(h.city) : ''}</td>
                <td class="p-cell-muted">${formatDate(h.created_at)}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.no_logs'] + '</td></tr>';
    }

    window.filterLoginHistory = function () {
        const status = document.getElementById('loginStatusFilter').value;
        const list = (window.__loginHistoryCache || []).filter(h => !status || h.status === status);
        renderLoginHistory(list);
    };

    async function loadOnboardingFunnel() {
        const res = await fetchJSON('/api/admin/onboarding-funnel');
        if (!res.success || !res.data) return;
        const f = res.data.funnel || {};
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        set('ofViews', f.total_views ?? 0);
        set('ofSubmitted', f.submitted ?? 0);
        set('ofCompleted', f.completed ?? 0);
        set('ofSubmitPct', (f.view_to_submit_pct ?? 0) + '%');
        set('ofCompletePct', (f.view_to_complete_pct ?? 0) + '%');
        set('ofAvgMin', res.data.avg_completion_minutes != null ? res.data.avg_completion_minutes + ' ' + (I18N['admin.onboarding_funnel.minutes'] || '') : '—');

        const tbody = document.querySelector('#ofStepsTable tbody');
        const steps = Array.isArray(res.data.steps) ? res.data.steps : [];
        const maxUsers = steps.length ? Math.max(1, ...steps.map(s => s.users)) : 1;
        tbody.innerHTML = steps.length ? steps.map(s => {
            const pct = Math.round((s.users / maxUsers) * 100);
            const drop = s.step > 1 && steps[s.step - 2] && steps[s.step - 2].users > 0
                ? Math.round(((steps[s.step - 2].users - s.users) / steps[s.step - 2].users) * 100) + '%'
                : (s.step > 1 ? (100 - Math.round((s.users / maxUsers) * 100)) + '%' : '—');
            return `<tr><td><strong>${s.step}</strong></td><td>${s.users}</td><td><div class="p-progress" style="height:6px;background:rgba(255,255,255,.08);border-radius:4px;overflow:hidden;margin-top:6px;"><span style="display:block;height:100%;width:${pct}%;background:linear-gradient(90deg,#0077be,#17a673);border-radius:4px;"></span></div></td></tr>`;
        }).join('') : '<tr><td colspan="3" class="p-cell-muted text-center">' + I18N['admin.no_data'] + '</td></tr>';

        const ctx = document.getElementById('ofTrendChart');
        if (ctx) {
            destroyChart('ofTrend');
            const trend = Array.isArray(res.data.trend) ? res.data.trend : [];
            charts.ofTrend = makeChart(ctx, {
                type: 'line',
                data: {
                    labels: trend.map(r => r.d),
                    datasets: [
                        { label: I18N['admin.onboarding_funnel.views'] || 'Views', data: trend.map(r => Number(r.views) || 0), borderColor: '#0077be', backgroundColor: 'rgba(0,119,190,0.08)', tension: 0.35, fill: true, pointRadius: 0 },
                        { label: I18N['admin.onboarding_funnel.submitted'] || 'Submitted', data: trend.map(r => Number(r.submitted) || 0), borderColor: '#17a673', backgroundColor: 'rgba(23,166,115,0.06)', tension: 0.35, fill: true, pointRadius: 0 },
                    ],
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } }, scales: { y: { beginAtZero: true } } },
            });
        }
    }

    async function loadLogs() {
        const res = await fetchJSON('/api/admin/system/logs');
        const tbody = document.querySelector('#logsTable tbody');
        if (!res.success || !Array.isArray(res.data.logs)) {
            tbody.innerHTML = '<tr><td colspan="3" class="p-cell-muted text-center">' + I18N['admin.load_logs_failed'] + '</td></tr>';
            return;
        }
        const logs = res.data.logs;
        tbody.innerHTML = logs.length ? logs.map(l => `
            <tr>
                <td>${esc(l.action || l.event || '-')}</td>
                <td class="p-cell-muted">${esc(l.user_id || '-')}</td>
                <td class="p-cell-muted">${formatDate(l.created_at)}</td>
            </tr>`).join('') : '<tr><td colspan="3" class="p-cell-muted text-center">' + I18N['admin.no_logs'] + '</td></tr>';
    }

    async function loadContactMessages() {
        const res = await fetchJSON('/api/admin/contact-messages');
        const tbody = document.querySelector('#contactMessagesTable tbody');
        if (!res.success || !Array.isArray(res.data.messages)) {
            tbody.innerHTML = '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.load_messages_failed'] + '</td></tr>';
            return;
        }
        const msgs = res.data.messages;
        const statusPills = { new: '<span class="pill blue">' + I18N['admin.msg.new'] + '</span>', read: '<span class="pill gray">' + I18N['admin.msg.read'] + '</span>', replied: '<span class="pill green">' + I18N['admin.msg.replied'] + '</span>' };
        tbody.innerHTML = msgs.length ? msgs.map(m => `
            <tr>
                <td>${esc(m.name)}</td>
                <td style="direction:ltr;text-align:left;">${esc(m.email)}</td>
                <td class="p-cell-muted" style="max-width:280px;white-space:normal;">${esc(m.message)}</td>
                <td>${statusPills[m.status] || esc(m.status)}</td>
                <td class="p-cell-muted">${formatDate(m.created_at)}</td>
                <td>${m.status === 'new' ? `<button class="p-btn outline xs" onclick="markContactMessageRead(${m.id})">${I18N['admin.mark_read']}</button>` : ''}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.no_messages'] + '</td></tr>';
    }

    window.markContactMessageRead = async function (id) {
        const res = await fetchJSON('/api/admin/contact-messages/' + id + '/read', { method: 'POST' });
        if (res.success) loadContactMessages();
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    let plansCache = [];
    let FEATURE_KEYS_CACHE = {};

    async function loadPlans() {
        const res = await fetchJSON('/api/admin/plans');
        const grid = document.getElementById('plansGrid');
        if (!res.success || !Array.isArray(res.data.plans)) {
            grid.innerHTML = `<div class="p-empty">${I18N['admin.load_failed']}</div>`;
            return;
        }
        plansCache = res.data.plans;
        bindNewPlan();
        loadPlanPositioning();
        loadPlanAudit();
        loadPlanEconomics();
        if (!plansCache.length) {
            grid.innerHTML = `<div class="p-empty">${I18N['admin.plans.none']}</div>`;
            return;
        }
        grid.innerHTML = plansCache.map(p => `
            <div class="p-card${p.is_active == 0 ? ' orbit-glow' : ''}" style="${p.is_active == 0 ? 'opacity:.55;' : ''}">
                <div class="p-card-head"><h3>${esc(p.name)}</h3><span class="p-card-sub">${p.is_active == 1 ? I18N['admin.plans.active'] : I18N['admin.plans.inactive']}</span></div>
                ${p.billing_ready === false ? '<div class="alert alert-warning">الباقة تحتاج مراجعة إعداد دورتي الفوترة قبل عرضها للبيع.</div>' : ''}
            <div style="font-size:24px;font-weight:700;margin:8px 0;">${esc(p.currency_symbol)}${esc(p.price_monthly)}<span style="font-size:12px;font-weight:400;">/${I18N['admin.monthly']}</span></div>
                <div class="p-cell-muted" style="font-size:12.5px;margin-bottom:10px;">${esc(p.currency_symbol)}${esc(p.price_yearly)}/${I18N['admin.yearly']}</div>
                <div class="p-kv"><span class="k">${I18N['admin.plans.ai_analysis']}</span><span class="v">${esc(p.ai_analysis)}</span></div>
                <div class="p-kv"><span class="k">${I18N['admin.plans.chat_credits']}</span><span class="v">${esc(p.chat_credits)}</span></div>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="openEditPlan(${p.id})">✏️ ${I18N['common.edit']}</button>${p.custom ? ` <button class="p-btn outline xs" style="margin-top:10px;" onclick="deletePlan(${p.id})">🗑 حذف</button>` : ''}
            </div>
        `).join('');
    }

    const AUDIT_LABELS = { name: 'الاسم', price_monthly: 'السعر الشهري', price_yearly: 'السعر السنوي', currency: 'العملة', is_active: 'مفعّلة', seo_cycles: 'فحوصات السيو/شهر', seo_max_pages: 'صفحات الفحص', seo_markets: 'الأسواق', ai_visibility: 'قياسات الذكاء الاصطناعي', seo_data: 'وحدات البيانات', articles: 'المقالات', ai_analysis: 'تحليلات AI', competitor_analysis: 'تحليلات المنافسين', chat_credits: 'رسائل الشات', review_credits: 'ردود التقييمات', multiple_websites: 'المواقع' };
    window.deletePlan = async function (id) {
        const p = plansCache.find(x => x.id == id);
        if (!p || !confirm('تحذف الباقة «' + p.name + '»؟ لو عليها اشتراكات مش هتتحذف.')) return;
        const res = await fetchJSON('/api/admin/plans/' + id + '/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        toast(res.success ? 'اتحذفت الباقة' : (res.error || 'تعذر الحذف'), res.success ? 'success' : 'error');
        if (res.success) loadPlans();
    };
    function bindNewPlan() {
        const sel = document.getElementById('newPlanClone');
        if (sel) sel.innerHTML = plansCache.map(p => '<option value="' + esc(p.plan_key) + '">' + esc(p.name) + '</option>').join('');
        const btn = document.getElementById('newPlanAdd');
        if (!btn || btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', async () => {
            const msg = document.getElementById('newPlanMsg');
            const body = { plan_key: document.getElementById('newPlanKey').value.trim(), name: document.getElementById('newPlanName').value.trim(), clone_from: sel.value };
            const res = await fetchJSON('/api/admin/plans/create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            msg.textContent = res.success ? 'اتعملت الباقة مقفولة للبيع. عدّل حدودها وأسعارها وبعدين فعّلها.' : (res.error || 'تعذر إنشاء الباقة');
            if (res.success) { document.getElementById('newPlanKey').value = ''; document.getElementById('newPlanName').value = ''; loadPlans(); }
        });
    }
    const SEO_DIMS = ['seo_cycles', 'seo_max_pages', 'seo_markets', 'ai_visibility', 'seo_data', 'articles'];
    const effPages = n => (Number(n) >= 500 ? 500 : (Number(n) >= 300 ? 300 : 100));
    const dimVal = (p, k) => (k === 'seo_max_pages' ? effPages(p[k]) : Number(p[k] || 0));
    async function loadPlanPositioning() {
        const box = document.getElementById('planPosBox');
        if (!box) return;
        const res = await fetchJSON('/api/admin/plans-positioning');
        if (!res.success) { box.textContent = 'تعذر تحميل الإعدادات'; return; }
        const cfg = res.data.config, lim = res.data.limits;
        const rows = plansCache.map(p => {
            const r = cfg.refs[p.plan_key] || {}, l = lim[p.plan_key];
            const usd = p.currency === 'USD';
            let status = '<span class="p-cell-muted">من غير مرجع</span>', apply = '';
            if (l && usd) {
                const cur = Number(p.price_monthly);
                if (cur <= l.max_price) status = '<span style="color:#27ae60">✓ ضمن الحد (' + Math.round((1 - cur / l.ref_price) * 100) + '% أقل)</span>';
                else { status = '<span style="color:#c0392b">⚠ أعلى بـ $' + (cur - l.max_price) + '</span>'; apply = '<button type="button" class="p-btn outline xs" data-pos-apply="' + p.id + '" data-max="' + l.max_price + '">طبّق $' + l.max_price + '</button>'; }
            } else if (l) status = '<span class="p-cell-muted">عملة الباقة مش دولار</span>';
            return '<tr><td>' + esc(p.name) + '<div class="p-cell-muted" dir="ltr">' + esc(p.plan_key) + '</div></td><td dir="ltr">' + esc(p.currency) + ' ' + esc(p.price_monthly) + '</td><td><input class="form-control" data-pos-name="' + esc(p.plan_key) + '" value="' + esc(r.name || '') + '" placeholder="اسم المنافس" style="min-width:120px"></td><td><input class="form-control" type="number" min="0" step="1" data-pos-price="' + esc(p.plan_key) + '" value="' + esc(r.price || '') + '" style="max-width:100px"></td><td dir="ltr">' + (l ? '$' + l.max_price : '-') + '</td><td>' + status + '</td><td>' + apply + '</td></tr>';
        }).join('');
        // تحذير: باقة سيو فقط أغلى من باقة شاملة حدود السيو فيها مساوية أو أكبر في 4 من 6 أبعاد
        const warns = [];
        plansCache.filter(a => String(a.plan_key).startsWith('seo-') && a.currency === 'USD').forEach(a => {
            plansCache.filter(b => !String(b.plan_key).startsWith('seo-') && b.currency === 'USD' && Number(b.price_monthly) < Number(a.price_monthly)).forEach(b => {
                const n = SEO_DIMS.filter(k => dimVal(b, k) >= dimVal(a, k)).length;
                if (n >= 4) warns.push('«' + esc(a.name) + '» ($' + a.price_monthly + ') أغلى من «' + esc(b.name) + '» ($' + b.price_monthly + ') اللي فيها كمان شات وسوشيال وغيره، وحدود السيو فيها مساوية أو أكبر في ' + n + ' من 6 أبعاد.');
            });
        });
        box.innerHTML = '<label style="display:flex;gap:8px;align-items:center;margin-bottom:10px">أقل من المنافسين بـ <input class="form-control" id="posPct" type="number" min="0" max="90" step="1" value="' + esc(cfg.below_pct) + '" style="max-width:80px"> %</label>'
            + (warns.length ? '<div class="alert alert-warning">' + warns.join('<br>') + '</div>' : '')
            + '<div style="overflow-x:auto"><table class="p-table" style="width:100%"><thead><tr><th>الباقة</th><th>السعر الحالي</th><th>المنافس المرجعي</th><th>سعره $</th><th>أعلى سعر مسموح</th><th>الحالة</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            + '<p class="p-cell-muted" style="margin-top:8px">الأسعار المرجعية الافتراضية للسيو من صفحات المنافسين الرسمية (تحقق 2026-10-31). الباقات الشاملة من غير مرجع لحد ما تحط لها واحد. السنوي بيتحسب 10 شهور زي باقي الباقات. تأثير السعر الجديد على المشتركين الحاليين عند التجديد: راجعه قبل التطبيق.</p>'
            + '<button type="button" class="p-btn" id="posSave">حفظ المراجع والنسبة</button> <span id="posMsg" class="p-cell-muted"></span>';
        document.getElementById('posSave').addEventListener('click', async () => {
            const refs = {};
            plansCache.forEach(p => { refs[p.plan_key] = { name: box.querySelector('[data-pos-name="' + p.plan_key + '"]').value, price: box.querySelector('[data-pos-price="' + p.plan_key + '"]').value, checked: (cfg.refs[p.plan_key] || {}).checked }; });
            const r = await fetchJSON('/api/admin/plans-positioning', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ config: { below_pct: document.getElementById('posPct').value, refs } }) });
            document.getElementById('posMsg').textContent = r.success ? 'اتحفظ' : (r.error || 'تعذر الحفظ');
            if (r.success) { loadPlanPositioning(); loadPlanAudit(); }
        });
        box.querySelectorAll('[data-pos-apply]').forEach(b => b.addEventListener('click', async () => {
            const p = plansCache.find(x => x.id == b.dataset.posApply), max = Number(b.dataset.max);
            if (!confirm('تغيّر سعر «' + p.name + '» من $' + p.price_monthly + ' إلى $' + max + ' شهريًا ($' + (max * 10) + ' سنويًا)؟')) return;
            const r = await fetchJSON('/api/admin/plans/' + p.id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ price_monthly: max, price_yearly: max * 10 }) });
            toast(r.success ? 'اتغيّر السعر' : (r.error || 'تعذر التغيير'), r.success ? 'success' : 'error');
            if (r.success) loadPlans();
        }));
    }
    async function loadPlanEconomics() {
        const box = document.getElementById('planEconBox');
        if (!box) return;
        const res = await fetchJSON('/api/admin/plans-economics');
        if (!res.success) { box.textContent = 'تعذر حساب الأرقام'; return; }
        if (!res.data.plans.length) { box.textContent = res.data.note; return; }
        box.innerHTML = '<div class="p-grid cols-3">' + res.data.plans.map(p => '<div class="p-card"><b>' + esc(p.plan_key) + '</b><div class="p-kv"><span class="k">مشتركين</span><span class="v">' + p.subscribers + '</span></div><div class="p-kv"><span class="k">إيراد شهري</span><span class="v">$' + p.mrr_usd + '</span></div><div class="p-kv"><span class="k">تكلفة (DataForSEO + AI)</span><span class="v">$' + p.cost_usd + '</span></div><div class="p-kv"><span class="k">تكلفة/مشترك</span><span class="v">$' + p.cost_per_subscriber_usd + '</span></div><div class="p-kv"><span class="k">الهامش</span><span class="v" style="' + (p.low_margin ? 'color:#c0392b;font-weight:700' : '') + '">' + (p.margin_pct === null ? '-' : p.margin_pct + '%') + (p.low_margin ? ' ⚠ تحت 40%' : '') + '</span></div></div>').join('') + '</div><p class="p-cell-muted" style="margin-top:8px">' + esc(res.data.note) + '</p>';
    }
    async function loadPlanAudit() {
        const box = document.getElementById('planAuditBox');
        if (!box) return;
        const res = await fetchJSON('/api/admin/plans-audit');
        if (!res.success || !res.data.changes.length) { box.textContent = res.success ? 'مفيش تعديلات متسجّلة لسه.' : 'تعذر تحميل السجل'; return; }
        box.innerHTML = res.data.changes.map(c => '<div style="padding:6px 0;border-bottom:1px solid var(--panel-border)"><b>' + esc(c.plan_key) + '</b> <span class="p-cell-muted" dir="ltr">' + esc(c.created_at) + ' · ' + esc(c.actor_email || ('#' + c.actor_id)) + '</span><div>' + Object.entries(c.changes).map(([k, v]) => esc(AUDIT_LABELS[k] || k) + ': <s>' + esc(v.from ?? '-') + '</s> ← <b>' + esc(v.to ?? '-') + '</b>').join(' · ') + '</div></div>').join('');
    }

    window.openEditPlan = function (id) {
        const p = plansCache.find(x => x.id == id);
        if (!p) return;
        document.getElementById('planId').value = p.id;
        document.getElementById('planName').value = p.name;
        document.getElementById('planPriceMonthly').value = p.price_monthly;
        document.getElementById('planPriceYearly').value = p.price_yearly;
        document.getElementById('planCurrency').value = p.currency;
        document.getElementById('planCurrencySymbol').value = p.currency_symbol;
        document.getElementById('planAiAnalysis').value = p.ai_analysis;
        document.getElementById('planCompetitorAnalysis').value = p.competitor_analysis;
        document.getElementById('planChatCredits').value = p.chat_credits;
        document.getElementById('planReviewCredits').value = p.review_credits;
        document.getElementById('planMultipleWebsites').value = p.multiple_websites;
        document.getElementById('planWhatsappBot').checked = p.whatsapp_bot == 1;
        document.getElementById('planAutoPilot').checked = p.auto_pilot == 1;
        document.getElementById('planAdvancedAnalytics').checked = p.advanced_analytics == 1;
        document.getElementById('planIsActive').checked = p.is_active == 1;
        document.getElementById('planAssistantMsgs').value = p.ai_assistant_messages ?? '';
        document.getElementById('planWebsiteGens').value = p.website_generations ?? '';
        document.getElementById('planWebsiteItems').value = p.website_items ?? '';
        ['seo_cycles','seo_max_pages','seo_markets','ai_visibility','seo_data','articles'].forEach(k => { document.getElementById('plan_' + k).value = p[k] ?? ''; });
        // الفحص بيشتغل على 100/300/500 صفحة بس: القيم القديمة (80 / 200 / 400) كانت بتتقرّب في صمت، فبنعرض القيمة الفعلية
        if (p.seo_max_pages) { const n = Number(p.seo_max_pages); document.getElementById('plan_seo_max_pages').value = n >= 500 ? '500' : (n >= 300 ? '300' : '100'); }
        document.getElementById('planWhatsAppMarketing').value = p.whatsapp_marketing_messages ?? ({starter:500,professional:2000,enterprise:10000}[p.plan_key] ?? 0);
        document.getElementById('editPlanAlert').style.display = 'none';
        P.openModal('editPlanModal');
    };

    window.savePlan = async function () {
        const alertBox = document.getElementById('editPlanAlert');
        alertBox.style.display = 'none';
        const id = document.getElementById('planId').value;

        const payload = {
            name: document.getElementById('planName').value.trim(),
            price_monthly: document.getElementById('planPriceMonthly').value,
            price_yearly: document.getElementById('planPriceYearly').value,
            currency: document.getElementById('planCurrency').value,
            currency_symbol: document.getElementById('planCurrencySymbol').value.trim(),
            ai_analysis: document.getElementById('planAiAnalysis').value,
            competitor_analysis: document.getElementById('planCompetitorAnalysis').value,
            chat_credits: document.getElementById('planChatCredits').value,
            review_credits: document.getElementById('planReviewCredits').value,
            multiple_websites: document.getElementById('planMultipleWebsites').value,
            whatsapp_bot: document.getElementById('planWhatsappBot').checked ? 1 : 0,
            auto_pilot: document.getElementById('planAutoPilot').checked ? 1 : 0,
            advanced_analytics: document.getElementById('planAdvancedAnalytics').checked ? 1 : 0,
            is_active: document.getElementById('planIsActive').checked ? 1 : 0,
            ai_assistant_messages: document.getElementById('planAssistantMsgs').value,
            website_generations: document.getElementById('planWebsiteGens').value,
            website_items: document.getElementById('planWebsiteItems').value,
            whatsapp_marketing_messages: document.getElementById('planWhatsAppMarketing').value,
        };
        ['seo_cycles','seo_max_pages','seo_markets','ai_visibility','seo_data','articles'].forEach(k => { payload[k] = document.getElementById('plan_' + k).value; });

        const res = await fetchJSON('/api/admin/plans/' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        if (res.success) {
            toast(I18N['common.updated'], 'success');
            P.closeModal('editPlanModal');
            loadPlans();
        } else {
            alertBox.textContent = res.error || I18N['common.update_failed'];
            alertBox.style.display = 'block';
        }
    };

    async function loadWalletStats() {
        const res = await fetchJSON('/api/admin/wallet/stats');
        if (!res.success) return;
        const s = res.data.stats;
        const el1 = document.getElementById('wsDepositsMonth');
        const el2 = document.getElementById('wsPendingTotal');
        const el3 = document.getElementById('wsTotalBalances');
        const el4 = document.getElementById('wsUsageMonth');
        [el1, el2, el3, el4].forEach(el => { if (el) el.classList.add('is-money'); });
        renderPlatformBalance(s);
        if (el1) el1.textContent = '$' + Number(s.deposits_this_month).toFixed(2);
        if (el2) el2.textContent = '$' + Number(s.pending_total).toFixed(2);
        if (el3) el3.textContent = '$' + Number(s.total_customer_balances).toFixed(2);
        if (el4) el4.textContent = '$' + Number(s.usage_charges_this_month).toFixed(2);

        // Phase 8: مقاييس MRR/ARR/اشتراكات - null صراحةً معناها "مفيش
        // بيانات كافية" بدل ما نعرض 0 مضلّل.
        const notEnough = 'لا توجد بيانات كافية';
        const wsMRR = document.getElementById('wsMRR');
        const wsARR = document.getElementById('wsARR');
        const wsActiveSubs = document.getElementById('wsActiveSubs');
        const wsChurn = document.getElementById('wsChurn');
        if (wsMRR) wsMRR.textContent = s.mrr === null || s.mrr === undefined ? notEnough : '$' + Number(s.mrr).toFixed(2);
        if (wsARR) wsARR.textContent = s.arr === null || s.arr === undefined ? notEnough : '$' + Number(s.arr).toFixed(2);
        if (wsActiveSubs) wsActiveSubs.textContent = s.active_subscriptions === null || s.active_subscriptions === undefined ? notEnough : s.active_subscriptions;
        if (wsChurn) wsChurn.textContent = s.churn_rate_this_month === null || s.churn_rate_this_month === undefined ? notEnough : Number(s.churn_rate_this_month).toFixed(1) + '%';

        loadMrrTrend();
    }

    async function loadMrrTrend() {
        const container = document.getElementById('mrrTrendChart');
        if (!container) return;
        const res = await fetchJSON('/api/admin/wallet/mrr-trend?days=30');
        if (!res.success || !res.data.trend || res.data.trend.length < 2) {
            container.innerHTML = '<div class="p-empty" style="padding:20px 0;"><div class="p-empty-icon">📊</div>مفيش بيانات كافية لعرض تطوّر الإيراد لسه - هتتراكم تلقائيًا يوم بيوم</div>';
            return;
        }

        const trend = res.data.trend;
        const values = trend.map(r => Number(r.mrr));
        const max = Math.max(...values, 1);
        const min = Math.min(...values, 0);
        const w = 600, h = 140, pad = 8;
        const range = (max - min) || 1;
        const points = values.map((v, i) => {
            const x = pad + (i / (values.length - 1)) * (w - pad * 2);
            const y = h - pad - ((v - min) / range) * (h - pad * 2);
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');

        const firstDate = trend[0].snapshot_date, lastDate = trend[trend.length - 1].snapshot_date;
        const lastMrr = values[values.length - 1].toFixed(2);

        container.innerHTML = `
            <svg viewBox="0 0 ${w} ${h}" style="width:100%;height:140px;" preserveAspectRatio="none">
                <polyline points="${points}" fill="none" stroke="var(--panel-accent, #4a6cf7)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
            </svg>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--panel-text-muted,#888);margin-top:4px;">
                <span>${esc(firstDate)} ($${values[0].toFixed(2)})</span>
                <span>${esc(lastDate)} ($${lastMrr})</span>
            </div>`;
    }

    async function loadCards() {
        const res = await fetchJSON('/api/admin/wallet/cards');
        const tbody = document.querySelector('#cardsTable tbody');
        if (!tbody) return;
        if (!res.success || !res.data.cards || !res.data.cards.length) {
            tbody.innerHTML = `<tr><td colspan="4" class="p-empty">${I18N['admin.cards.no_cards']}</td></tr>`;
            return;
        }
        tbody.innerHTML = res.data.cards.map(c => `
            <tr>
                <td dir="ltr" style="font-family:monospace;font-size:12px;">${esc(c.code)}</td>
                <td dir="ltr">$${Number(c.value).toFixed(2)}</td>
                <td>${c.status === 'used' ? '<span class="pill gray">' + I18N['admin.cards.status.used'] + '</span>' : '<span class="pill green">' + I18N['admin.cards.status.unused'] + '</span>'}</td>
                <td class="p-cell-muted">${c.used_by_user_id ? '#' + c.used_by_user_id : '-'}</td>
            </tr>`).join('');
    }

    window.generateCards = async function () {
        const alertBox = document.getElementById('cardsAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/admin/wallet/cards/generate', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                count: document.getElementById('cardsCount').value,
                value: document.getElementById('cardsValue').value,
                batch_label: document.getElementById('cardsBatchLabel').value,
            }),
        });

        if (res.success) {
            const codes = res.data.cards.map(c => c.code).join('\n');
            document.getElementById('newCardsResult').innerHTML = `
                <div class="alert" style="background:rgba(76,175,130,.1);border:1px solid rgba(76,175,130,.3);padding:14px;border-radius:10px;">
                    <strong>${I18N['admin.cards.generated_success'].replace('{count}', res.data.cards.length)}</strong>
                    <textarea readonly style="width:100%;margin-top:10px;font-family:monospace;font-size:12px;" rows="6" dir="ltr">${esc(codes)}</textarea>
                    <p class="p-cell-muted" style="font-size:11px;margin-top:6px;">${I18N['admin.cards.copy_hint']}</p>
                </div>`;
            toast(I18N['common.updated'], 'success');
            loadCards();
        } else {
            alertBox.textContent = res.error || I18N['common.update_failed'];
            alertBox.style.display = 'block';
        }
    };

    async function loadPendingDeposits() {
        const res = await fetchJSON('/api/admin/wallet/pending');
        const box = document.getElementById('pendingDepositsList');
        if (!box) return;
        if (!res.success || !res.data.deposits || !res.data.deposits.length) {
            box.innerHTML = `<div class="p-empty" style="padding:20px 0;">${I18N['wallet.admin.no_pending']}</div>`;
            return;
        }
        box.innerHTML = res.data.deposits.map(d => `
            <div class="p-kv" style="align-items:center;">
                <span class="k">
                    <strong>${esc(d.user_company || d.user_email)}</strong>
                    <div class="p-cell-muted" style="font-size:11.5px;">${esc(({iban:'تحويل بنكي',paypal:'PayPal',instapay:'InstaPay',mobile_wallet:'محفظة موبايل'})[d.payment_method] || d.payment_method)}${d.created_at ? ' · ' + esc(d.created_at) : ''}${d.reference_note ? ' · ' + esc(d.reference_note) : ''}</div>
                    ${d.related_subscription_plan ? `<div style="font-size:11.5px;margin-top:3px;"><span class="pill green">${/^site-/.test(d.related_subscription_plan) ? 'اشتراك موقع #' + esc(String(d.related_subscription_plan).replace(/^site-/, '').replace(':monthly',' · شهري').replace(':yearly',' · سنوي')) : 'باقة ' + esc(String(d.related_subscription_plan).replace(':monthly',' · شهري').replace(':yearly',' · سنوي'))}</span> <span class="p-cell-muted">بتتفعّل لوحدها بعد الموافقة</span></div>` : ''}
                </span>
                <span class="v" style="display:flex;align-items:center;gap:8px;">
                    <strong dir="ltr">$${esc(d.amount)}</strong>
                    <button class="p-btn success xs" onclick="approveDeposit(${d.id})">✔ ${I18N['wallet.admin.approve']}</button>
                    <button class="p-btn danger xs" onclick="rejectDeposit(${d.id})">✖ ${I18N['wallet.admin.reject']}</button>
                </span>
            </div>`).join('');
    }

    window.approveDeposit = async function (id) {
        if (!confirm(I18N['wallet.admin.approve_confirm'])) return;
        const res = await fetchJSON('/api/admin/wallet/' + id + '/approve', { method: 'POST' });
        if (res.success) { toast(I18N['wallet.admin.approved'], 'success'); loadPendingDeposits(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    window.rejectDeposit = async function (id) {
        if (!confirm(I18N['wallet.admin.reject_confirm'])) return;
        const res = await fetchJSON('/api/admin/wallet/' + id + '/reject', { method: 'POST' });
        if (res.success) { toast(I18N['wallet.admin.rejected'], 'success'); loadPendingDeposits(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    async function loadSiteSubs() {
        const box = document.getElementById('siteSubsList');
        if (!box) return;
        const res = await fetchJSON('/api/admin/site-subscriptions');
        if (!res.success) { box.innerHTML = '<div class="p-empty">' + esc(res.error || '') + '</div>'; return; }
        const c = res.data.config;
        document.getElementById('ssMonthly').value = c.monthly; document.getElementById('ssYearly').value = c.yearly;
        document.getElementById('ssDiscount').value = c.platform_discount; document.getElementById('ssGrace').value = c.grace_days;
        document.getElementById('ssEnabled').checked = !!c.enabled;
        const pills = { active: ['green', 'شغال'], grace: ['orange', 'فترة سماح'], expired: ['red', 'متوقف'], none: ['gray', 'من غير اشتراك'] };
        const src = { wallet: 'مدفوع', admin: 'هدية أدمن', grandfathered: 'شهر مجاني (قديم)' };
        const sites = res.data.sites || [];
        if (!sites.length) { box.innerHTML = '<div class="p-empty" style="padding:16px 0">لسه مفيش مواقع</div>'; return; }
        box.innerHTML = '<div class="p-table-scroll"><table class="p-table"><thead><tr><th>الموقع</th><th>العميل</th><th>الحالة</th><th>لحد</th><th>المدة / السعر</th><th></th></tr></thead><tbody>'
            + sites.map(s => {
                const p = pills[s.state] || pills.none;
                return `<tr><td><a href="/sites/${encodeURIComponent(s.slug)}" target="_blank" rel="noopener" dir="ltr">${esc(s.slug)}</a>${s.site_status === 'published' ? '' : ' <span class="p-cell-muted">(مسودة)</span>'}</td>
                    <td>${esc(s.company_name || s.email)}</td>
                    <td><span class="pill ${p[0]}">${p[1]}</span></td>
                    <td dir="ltr">${esc((s.period_end || '').slice(0, 10)) || '-'}</td>
                    <td>${s.cycle ? (s.cycle === 'yearly' ? 'سنوي' : 'شهري') + ' · $' + esc(s.price) + (src[s.source] ? ' · ' + src[s.source] : '') : '-'}</td>
                    <td><button class="p-btn outline xs" onclick="grantSiteDays(${s.website_id})">🎁 أيام مجانية</button></td></tr>`;
            }).join('') + '</tbody></table></div>';
    }

    window.saveSiteSubsConfig = async function () {
        const res = await fetchJSON('/api/admin/site-subscriptions/config', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            enabled: document.getElementById('ssEnabled').checked, monthly: document.getElementById('ssMonthly').value, yearly: document.getElementById('ssYearly').value,
            platform_discount: document.getElementById('ssDiscount').value, grace_days: document.getElementById('ssGrace').value }) });
        toast(res.success ? (res.message || I18N['common.updated']) : (res.error || I18N['common.update_failed']), res.success ? 'success' : 'error');
        if (res.success) loadSiteSubs();
    };

    window.grantSiteDays = async function (id) {
        const days = prompt('كام يوم مجاني للموقع ده؟', '30');
        if (!days) return;
        const res = await fetchJSON('/api/admin/site-subscriptions/' + id + '/grant', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ days: Number(days) }) });
        toast(res.success ? res.message : (res.error || I18N['common.update_failed']), res.success ? 'success' : 'error');
        if (res.success) loadSiteSubs();
    };

    async function loadWalletSettings() {
        const res = await fetchJSON('/api/admin/wallet/settings');
        if (res.success) {
            const s = res.data.settings || {};
            document.getElementById('wsIban').value = s.iban || '';
            document.getElementById('wsIbanBank').value = s.iban_bank_name || '';
            document.getElementById('wsIbanAccount').value = s.iban_account_name || '';
            document.getElementById('wsPaypal').value = s.paypal_email || '';
            document.getElementById('wsWhatsapp').value = s.whatsapp_number || '';
            const clean = v => /هنا|لوحة الأدمن|^اسم البنك$|^اسم صاحب الحساب$/.test(String(v || '')) ? '' : (v || '');
            ['wsIban', 'wsIbanBank', 'wsIbanAccount', 'wsPaypal'].forEach(id => { const el = document.getElementById(id); el.value = clean(el.value); });
            document.getElementById('wsInstapay').value = s.instapay || '';
            document.getElementById('wsMobileWallet').value = s.mobile_wallet || '';
            document.getElementById('wsMobileWalletName').value = s.mobile_wallet_name || '';
            document.getElementById('wsSwift').value = s.iban_swift || '';
            document.getElementById('wsInstructions').value = s.pay_instructions || '';
        }
    }

    window.saveWalletSettings = async function () {
        const alertBox = document.getElementById('walletSettingsAlert');
        alertBox.style.display = 'none';
        const payload = {
            iban: document.getElementById('wsIban').value.trim(),
            iban_bank_name: document.getElementById('wsIbanBank').value.trim(),
            iban_account_name: document.getElementById('wsIbanAccount').value.trim(),
            paypal_email: document.getElementById('wsPaypal').value.trim(),
            whatsapp_number: document.getElementById('wsWhatsapp').value.trim(),
            instapay: document.getElementById('wsInstapay').value.trim(),
            mobile_wallet: document.getElementById('wsMobileWallet').value.trim(),
            mobile_wallet_name: document.getElementById('wsMobileWalletName').value.trim(),
            iban_swift: document.getElementById('wsSwift').value.trim(),
            pay_instructions: document.getElementById('wsInstructions').value.trim(),
        };
        const res = await fetchJSON('/api/admin/wallet/settings', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (res.success) toast(I18N['common.updated'], 'success');
        else { alertBox.textContent = res.error || I18N['common.update_failed']; alertBox.style.display = 'block'; }
    };

    async function loadUsagePricing() {
        const res = await fetchJSON('/api/admin/wallet/usage-pricing');
        const box = document.getElementById('usagePricingList');
        if (!box) return;
        if (!res.success || !res.data.pricing || !res.data.pricing.length) {
            box.innerHTML = `<div class="p-empty">${I18N['common.loading']}</div>`;
            return;
        }
        box.innerHTML = res.data.pricing.map(p => `
            <div class="p-kv" style="align-items:center;">
                <span class="k">${esc(p.label)}</span>
                <span class="v" style="display:flex;align-items:center;gap:10px;">
                    <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;">
                        <input type="checkbox" id="usage-active-${p.id}" data-active="${p.is_active == 1 ? '1' : '0'}" ${p.is_active == 1 ? 'checked' : ''} onchange="toggleUsagePricing(${p.id})"> ${I18N['admin.plans.is_active']}
                    </label>
                    <span dir="ltr" style="display:flex;align-items:center;gap:4px;">
                        $<input type="number" step="0.01" value="${esc(p.price)}" id="price-${p.id}" class="p-select xs" style="width:70px;">
                    </span>
                    <button id="usage-save-${p.id}" class="p-btn outline xs" onclick="saveUsagePricing(${p.id})">${I18N['common.save']}</button>
                </span>
            </div>`).join('');
    }

    const usagePricingPending = new Set();
    async function updateUsagePricingRow(id) {
        if (usagePricingPending.has(id)) return;
        const input = document.getElementById('price-' + id);
        const active = document.getElementById('usage-active-' + id);
        const button = document.getElementById('usage-save-' + id);
        if (!input || !active) return;
        const price = Number(input.value);
        const wasActive = active.dataset.active === '1';
        if (!input.value.trim() || !Number.isFinite(price) || price < 0) {
            active.checked = wasActive;
            toast('أدخل سعرًا صحيحًا لا يقل عن صفر', 'error');
            return;
        }
        const isActive = active.checked;
        usagePricingPending.add(id);
        input.disabled = active.disabled = true;
        if (button) button.disabled = true;
        try {
            const res = await fetchJSON('/api/admin/wallet/usage-pricing/' + id, {
                method: 'PUT', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ price, is_active: isActive ? 1 : 0 }),
            });
            if (!res || !res.success) throw new Error('save');
            active.dataset.active = isActive ? '1' : '0';
            toast(I18N['common.updated'], 'success');
        } catch (error) {
            active.checked = wasActive;
            toast('تعذر حفظ التسعير. أعد المحاولة.', 'error');
        } finally {
            usagePricingPending.delete(id);
            input.disabled = active.disabled = false;
            if (button) button.disabled = false;
        }
    }
    window.saveUsagePricing = updateUsagePricingRow;
    window.toggleUsagePricing = updateUsagePricingRow;

    // ============ التكاملات الخارجية (Integrations Center) ============
    let intCache = [];
    let intCurrentKey = null;

    async function loadIntegrations() {
        const res = await fetchJSON('/api/integrations');
        const box = document.getElementById('integrationsList');
        if (!box) return;
        if (!res.success) {
            box.innerHTML = '<div class="p-empty">' + (res.error || I18N['admin.load_data_error']) + '</div>';
            return;
        }
        intCache = res.data.integrations || [];
        renderIntegrationSummary(res.data || {});
        renderIntegrationCategoryOptions(res.data.categories || []);
        renderIntegrationList();
    }

    function renderIntegrationSummary(data) {
        const box = document.getElementById('integrationsSummary');
        if (!box) return;
        const total = Number(data.total_count || intCache.length || 0);
        const configured = Number(data.configured_count || 0);
        const missing = Math.max(total - configured, 0);
        const pct = total > 0 ? Math.round((configured / total) * 100) : 0;

        box.innerHTML = `
            <div class="p-kv"><span class="k">كل التكاملات</span><span class="v">${total}</span></div>
            <div class="p-kv"><span class="k">مفعلة</span><span class="v"><span class="pill green">${configured}</span></span></div>
            <div class="p-kv"><span class="k">تحتاج إعداد</span><span class="v"><span class="pill gray">${missing}</span></span></div>
            <div class="p-kv"><span class="k">نسبة الجاهزية</span><span class="v">${pct}%</span></div>
        `;
    }

    function renderIntegrationCategoryOptions(categories) {
        const select = document.getElementById('intCategory');
        if (!select || select.dataset.loaded === '1') return;
        select.innerHTML = '<option value="">كل الفئات</option>' + categories.map(cat =>
            `<option value="${esc(cat.key)}">${esc(cat.key)} (${Number(cat.count || 0)})</option>`
        ).join('');
        select.dataset.loaded = '1';
    }

    const IMPORTANCE = {
        required: ['red', 'لازم قبل الإطلاق', 0],
        important: ['orange', 'مهم', 1],
        optional: ['gray', 'اختياري', 2],
    };
    function importancePill(i) {
        const m = IMPORTANCE[i.importance] || IMPORTANCE.optional;
        const style = m[0] === 'red' ? 'background:rgba(239,68,68,.15);color:#f87171;border-color:rgba(239,68,68,.35)'
            : (m[0] === 'orange' ? 'background:rgba(245,158,11,.15);color:#fbbf24;border-color:rgba(245,158,11,.35)' : '');
        return '<span class="pill gray" style="' + style + '">' + m[1] + '</span>';
    }

    function integrationHint(i) {
        if (i.description) return i.description;
        const key = i.key || '';
        if (key.indexOf('google_') === 0) return 'يستخدم Google OAuth من لوحة الأدمن، وتحتاج تضيف Redirect URI الصحيح داخل Google Cloud.';
        if (key === 'gemini') return 'مفتاح Gemini المستخدم في أدوات الذكاء الاصطناعي داخل المنصة.';
        if (key === 'stripe' || key === 'paypal') return 'لا تفعله للعملاء قبل اختبار الدفع و Webhooks على الدومين الحقيقي.';
        if (key === 'whatsapp') return 'تأكد من رقم الهاتف وتوكن WhatsApp قبل تشغيل الإرسال التلقائي.';
        return 'احفظ المفاتيح المطلوبة ثم اختبر الاتصال عند توفر API فعلي.';
    }

    function renderIntegrationList() {
        const box = document.getElementById('integrationsList');
        if (!box) return;
        const q = (document.getElementById('intFilter')?.value || '').trim().toLowerCase();
        const cat = document.getElementById('intCategory')?.value || '';
        const status = document.getElementById('intStatus')?.value || '';
        // الصفحة كانت 41 تكامل مرة واحدة (5000 بيكسل): بتفتح على الأساسية، والبحث بيدوّر في الكل
        const imp = q ? '' : (document.getElementById('intImportance')?.value ?? 'core');
        const filtered = intCache.filter(i => {
            const text = [(i.label || ''), (i.key || ''), (i.category || ''), (i.auth_type || ''), (i.env_keys || []).join(' ')].join(' ').toLowerCase();
            if (q && !text.includes(q)) return false;
            if (cat && i.category !== cat) return false;
            if (status === 'configured' && !i.configured) return false;
            if (status === 'missing' && i.configured) return false;
            if (imp === 'core' && !['required', 'important'].includes(i.importance)) return false;
            if (imp === 'optional' && ['required', 'important'].includes(i.importance)) return false;
            return true;
        });

        if (!filtered.length) {
            box.innerHTML = '<div class="p-empty">' + I18N['admin.integrations.no_integrations'] + '</div>';
            return;
        }
        // الأهم الأول، وجوه كل مستوى: الناقص قبل المهيأ
        filtered.sort((a, b) => ((IMPORTANCE[a.importance] || IMPORTANCE.optional)[2] - (IMPORTANCE[b.importance] || IMPORTANCE.optional)[2]) || (a.configured - b.configured));

        box.innerHTML = filtered.map(i => `
            <div style="padding:14px 0;border-bottom:1px solid var(--panel-border);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:start;">
                <div style="min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px;">
                        <strong style="font-size:14px;">${esc(i.label || i.key)}</strong>
                        ${importancePill(i)}
                        <span class="pill gray">${esc(i.category || 'general')}</span>
                        <span class="pill gray">${esc(i.auth_type || 'api_key')}</span>
                        ${i.configured
                            ? '<span class="pill green">' + I18N['admin.integrations.configured'] + '</span>'
                            : '<span class="pill gray">' + I18N['admin.integrations.not_configured'] + '</span>'}
                    </div>
                    <div class="p-cell-muted" style="font-size:12px;line-height:1.7;">${esc(integrationHint(i))}</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
                        ${(i.env_keys || []).map(k => `<code style="font-size:11px;padding:3px 6px;border:1px solid var(--panel-border);border-radius:7px;direction:ltr;${((i.optional_keys || []).includes(k) || k.endsWith('_REDIRECT_URI')) ? 'opacity:.55;' : ''}">${esc(k)}</code>`).join('')}
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                    <button class="p-btn primary xs" onclick="openIntModal('${esc(i.key)}')">إعداد المفاتيح</button>
                    <button class="p-btn outline xs" onclick="quickTestIntegration('${esc(i.key)}')">اختبار</button>
                </div>
            </div>
        `).join('');
    }

    window.openIntModal = async function (key) {
        const res = await fetchJSON('/api/integrations/' + key + '/status');
        if (!res.success) { toast(res.error || I18N['admin.load_data_error'], 'error'); return; }
        const meta = res.data;
        intCurrentKey = key;
        document.getElementById('intModalTitle').textContent = '⚙️ ' + (meta.label || key);
        document.getElementById('intModalStatus').innerHTML =
            '<span class="pill ' + (meta.configured ? 'green' : 'gray') + '">' + (meta.configured ? I18N['admin.integrations.configured'] : I18N['admin.integrations.not_configured']) + '</span>'
            + ' <span class="pill gray">' + esc(meta.category || 'general') + '</span>'
            + ' <span class="pill gray">' + esc(meta.auth_type || 'api_key') + '</span>';
        const optionalKeys = meta.optional_keys || [];
        document.getElementById('intModalKeys').innerHTML = (meta.description ? '<p class="p-cell-muted" style="font-size:12.5px;line-height:1.7;margin:0 0 12px;">' + esc(meta.description) + '</p>' : '') + (meta.env_keys || []).map(envName => `
            <div class="form-group">
                <label class="form-label" style="font-family:monospace;font-size:12px;direction:ltr;text-align:right;">${esc(envName)}${(optionalKeys.includes(envName) || envName.endsWith('_REDIRECT_URI')) ? ' <span class="pill gray" style="font-family:inherit">اختياري</span>' : ''}${(meta.env_keys_set || {})[envName] ? ' <span class="pill green" style="font-family:inherit">✓</span>' : ''}</label>
                ${envName.endsWith('_JSON') ? `<textarea rows="4" class="form-control${/(_ENABLED|_REDIRECT_URI|_CLIENT_ID|_APP_ID|_INSTANCE_ID|_PHONE_ID|_PIXEL_ID|_TAG_ID|_INTEGRATION_ID|_IFRAME_ID|_MODEL|_MODE|_BASE_URL|_API_URL|_HOST|_PORT|_ENCRYPTION|_FROM_ADDRESS|_FROM_NAME|_USERNAME|_ACCOUNT_ID|_CUSTOMER_ID|_CLIENT_KEY|_VENDOR_ID|_ENVIRONMENT|_APPROVAL_REFERENCE|_MACHINE_ACCOUNT_ID)$/.test(envName) ? '' : ' int-secret'}" data-env-key="${esc(envName)}" name="int-${esc(envName.toLowerCase())}-${Date.now()}" placeholder="${envName.endsWith('_ENABLED') ? 'true أو false' : (envName.endsWith('_REDIRECT_URI') ? 'اختياري - سيبه فاضي وهيتعمل لوحده' : (optionalKeys.includes(envName) ? 'اختياري' : I18N['admin.integrations.secret_placeholder']))}" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore data-form-type="other" style="font-family:monospace;font-size:11.5px;resize:vertical;"></textarea>` : `<input type="text" class="form-control${/(_ENABLED|_REDIRECT_URI|_CLIENT_ID|_APP_ID|_INSTANCE_ID|_PHONE_ID|_PIXEL_ID|_TAG_ID|_INTEGRATION_ID|_IFRAME_ID|_MODEL|_MODE|_BASE_URL|_API_URL|_HOST|_PORT|_ENCRYPTION|_FROM_ADDRESS|_FROM_NAME|_USERNAME|_ACCOUNT_ID|_CUSTOMER_ID|_CLIENT_KEY|_VENDOR_ID|_ENVIRONMENT|_APPROVAL_REFERENCE|_MACHINE_ACCOUNT_ID)$/.test(envName) ? '' : ' int-secret'}" data-env-key="${esc(envName)}" name="int-${esc(envName.toLowerCase())}-${Date.now()}" placeholder="${envName.endsWith('_ENABLED') ? 'true أو false' : (envName.endsWith('_REDIRECT_URI') ? 'اختياري - سيبه فاضي وهيتعمل لوحده' : (optionalKeys.includes(envName) ? 'اختياري' : I18N['admin.integrations.secret_placeholder']))}" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore data-form-type="other">`}
            </div>
        `).join('');
        document.getElementById('intModalAlert').style.display = 'none';
        document.getElementById('intModalOverlay').style.display = 'flex';
    };

    window.closeIntModal = function () {
        document.getElementById('intModalOverlay').style.display = 'none';
        intCurrentKey = null;
    };

    window.saveIntegrationKeys = async function () {
        if (!intCurrentKey) return;
        const values = {};
        document.querySelectorAll('#intModalKeys [data-env-key]').forEach(inp => {
            const envKey = inp.getAttribute('data-env-key');
            if (inp.value.trim() !== '') values[envKey] = inp.value.trim();
        });
        const btn = document.getElementById('intSaveBtn');
        btn.disabled = true;
        try {
            const res = await fetchJSON('/api/integrations/' + intCurrentKey + '/save', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ values, csrf_token: document.getElementById('intCsrf')?.value || '' }),
            });
            toast(res.success ? I18N['admin.integrations.keys_saved'] : (res.error || I18N['common.update_failed']), res.success ? 'success' : 'error');
            if (res.success) {
                document.querySelectorAll('#intModalKeys [data-env-key]').forEach(inp => inp.value = '');
                await loadIntegrations();
                await openIntModal(intCurrentKey);
            }
        } finally {
            btn.disabled = false;
        }
    };

    window.quickTestIntegration = async function (key) {
        intCurrentKey = key;
        await testIntegrationConnection();
        intCurrentKey = null;
    };

    window.testIntegrationConnection = async function () {
        if (!intCurrentKey) return;
        const btn = document.getElementById('intTestBtn');
        btn.disabled = true;
        btn.textContent = I18N['admin.integrations.testing'];
        try {
            const res = await fetchJSON('/api/integrations/' + intCurrentKey + '/test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({csrf_token:document.getElementById('intCsrf')?.value||''}) });
            const msg = res.success ? (res.message && res.message !== 'Success' ? res.message : I18N['admin.integrations.test_ok']) : (res.error || I18N['admin.integrations.test_fail']);
            toast(msg.length > 120 ? msg.slice(0, 117) + '…' : msg, res.success ? 'success' : 'error');
            // الرسالة كاملة جوه النافذة (فيها تفاصيل زي تحذيرات DNS)
            const alertBox = document.getElementById('intModalAlert');
            if (alertBox && document.getElementById('intModalOverlay').style.display !== 'none') {
                alertBox.className = 'alert ' + (res.success && msg.indexOf('⚠️') === -1 ? 'alert-success' : (res.success ? 'alert-warning' : 'alert-danger'));
                alertBox.style.cssText = 'display:block;margin-bottom:10px;white-space:pre-line;line-height:1.8;font-size:13px;';
                alertBox.textContent = msg.replace(/ ⚠️/g, '\n⚠️');
            }
        } finally {
            btn.disabled = false;
            btn.textContent = I18N['admin.integrations.test_connection'];
        }
    };

    async function loadSystem() {
        const statsRes = await fetchJSON('/api/admin/system/stats');
        if (statsRes.success) {
            const d = statsRes.data || {};
            document.getElementById('statUsers').textContent = d.users_count ?? 0;
            document.getElementById('statSubs').textContent = d.active_subscriptions ?? 0;
            document.getElementById('statWebsites').textContent = d.websites_count ?? 0;
            document.getElementById('statPhp').textContent = d.php_version ?? '-';
        }
        const healthRes = await fetchJSON('/api/admin/system/health');
        const box = document.getElementById('systemHealthBox');
        if (box) {
            box.innerHTML = healthRes.success
                ? '<span class="pill green">● ' + I18N['admin.system_healthy'] + '</span>'
                : '<span class="pill red">● ' + I18N['admin.system_status_failed'] + '</span>';
        }

        const nfRes = await fetchJSON('/api/admin/new-features-stats');
        if (nfRes.success) {
            const nf = nfRes.data.stats;
            document.getElementById('statWbSites').textContent = nf.sites_total;
            document.getElementById('statAiConversations').textContent = nf.ai_conversations;
            document.getElementById('statReviewRequests').textContent = nf.review_requests_sent;
            document.getElementById('newFeaturesDetail').innerHTML = `
                <div class="p-kv"><span class="k">🏗️ ${I18N['admin.stat.sites_published']}</span><span class="v">${nf.sites_published} / ${nf.sites_total}</span></div>
                <div class="p-kv"><span class="k">✨ ${I18N['admin.stat.ai_messages']}</span><span class="v">${nf.ai_messages}</span></div>
                <div class="p-kv"><span class="k">📨 ${I18N['admin.stat.reviews_collected']}</span><span class="v">${nf.review_requests_reviewed} / ${nf.review_requests_total}</span></div>
            `;
        }
    }
    window.clearAdminCache = async function (btn) {
        btn.disabled = true;
        const res = await fetchJSON('/api/admin/system/cache/clear', { method: 'POST' });
        toast(res.success ? I18N['admin.cache_cleared'] : (res.error || I18N['admin.cache_clear_failed']), res.success ? 'success' : 'error');
        btn.disabled = false;
    };

    async function loadPlatform() {
        const res = await fetchJSON('/api/admin/platform-overview');
        if (!res.success) { toast(res.error || I18N['admin.load_failed'], 'error'); return; }
        const d = res.data;

        const fmt = (n) => '$' + (parseFloat(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

        document.getElementById('platRevenue').textContent = fmt(d.revenue.total);
        document.getElementById('platReviews').textContent = d.reviews.total;
        document.getElementById('platDeals').textContent = d.crm.deals;
        document.getElementById('platCompetitors').textContent = d.competitors.tracked;
        document.getElementById('platAdSpend').textContent = fmt(d.ads.spend);
        document.getElementById('platArticles').textContent = d.content.articles;
        document.getElementById('platChats').textContent = d.chat.messages_30d;
        document.getElementById('platAudits').textContent = d.website_optimizer.audits + (d.website_optimizer.avg_score ? ' (' + d.website_optimizer.avg_score + '/100)' : '');

        const planBox = document.getElementById('planBreakdown');
        planBox.innerHTML = (d.plans && d.plans.length)
            ? d.plans.map(p => `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed var(--panel-border);"><span>${esc(p.plan_name)}</span><strong>${esc(p.count)}</strong></div>`).join('')
            : `<div class="p-cell-muted">${I18N['admin.no_active_subs']}</div>`;

        document.getElementById('extraMetrics').innerHTML = `
            <div style="display:flex;justify-content:space-between;"><span>${I18N['admin.avg_reputation']}</span><strong>${d.reviews.avg_rating || '-'} ⭐</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>${I18N['admin.negative_reviews']}</span><strong>${d.reviews.negative}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>${I18N['admin.crm_pipeline_value']}</span><strong>${fmt(d.crm.pipeline_value)}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>${I18N['admin.leads']}</span><strong>${d.crm.leads}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>${I18N['admin.competitor_alerts']}</span><strong>${d.competitors.alerts_30d}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>${I18N['admin.social_posts']}</span><strong>${d.content.social_posts}</strong></div>
        `;
    }

    async function boot() {
        try {
            if (tab === 'overview') {
                if (window.AdminPro && document.getElementById('proOverview')) {
                    await AdminPro.renderOverview();
                } else {
                    // لو admin-pro.js ما اتحمّلش لأي سبب: نرجع للعرض القديم بدل صفحة فاضية
                    const legacy = document.getElementById('legacyOverview');
                    if (legacy) legacy.style.display = '';
                    await loadOverview();
                }
            }
            else if (tab === 'users') await loadUsers();
            else if (tab === 'subscriptions') await loadSubscriptions();
            else if (tab === 'visitors') await loadVisitors(30);
            else if (tab === 'login-history') await loadLoginHistory();
            else if (tab === 'onboarding-funnel') await loadOnboardingFunnel();
            else if (tab === 'logs') await loadLogs();
            else if (tab === 'contact-messages') await loadContactMessages();
            else if (tab === 'plans') { await loadPlans(); await loadWalletStats(); await loadCards(); await loadPendingDeposits(); await loadWalletSettings(); await loadSiteSubs(); await loadUsagePricing(); }
            else if (tab === 'system') await loadSystem();
            else if (tab === 'platform') await loadPlatform();
            else if (tab === 'settings') { await loadSystemSettings(); await loadFeaturesList(); await loadFaqList(); }
            else if (tab === 'integrations') {
                // Chrome ساعات بيحط الإيميل في خانة البحث لوحده، فالقايمة تطلع فاضية
                const f = document.getElementById('intFilter');
                if (f && f.value.includes('@')) f.value = '';
                await loadIntegrations();
                setTimeout(() => { if (f && f.value.includes('@')) { f.value = ''; renderIntegrationList(); } }, 1000);
            }
            else if (['team', 'audit', 'ai-usage', 'jobs', 'security', 'finance', 'api-keys', 'health', 'emails', 'metrics', 'customers-health', 'adoption', 'trials', 'errors', 'billing-ops', 'alerts', 'admin-security', 'status-page', 'blog'].indexOf(tab) !== -1 && window.AdminPro) await AdminPro.renderPage(tab);
        } catch (e) {
            toast(I18N['admin.load_data_error'], 'error');
        } finally {
            document.getElementById('loadingMsg').style.display = 'none';
            document.getElementById('adminContent').style.display = 'block';
        }
    }
    window.renderIntegrationList = renderIntegrationList;
    window.loadIntegrations = loadIntegrations;
    boot();
})();
JS;
        $script = str_replace('__TAB__', $tab, $script);

        // تصحيح باغ فادح: {asset_v(...)} جوه heredoc مبيتفسّرش من PHP -
        // لازم نحسبه في متغير الأول.
        $styleCssUrl = asset_v('/assets/css/style.css');
        $panelCssUrl = asset_v('/assets/css/panel.css');
        $panelJsUrl = asset_v('/assets/js/panel.js');
        $adminProCssUrl = asset_v('/assets/css/admin-pro.css');
        $tfThemeCssUrl = asset_v('/assets/css/tf-theme.css');
        $adminProJsUrl = asset_v('/assets/js/admin-pro.js');
        $adminPagesJsUrl = asset_v('/assets/js/admin-pages.js');
        $adminInsightsJsUrl = asset_v('/assets/js/admin-insights.js');
        // بيانات خريطة العالم (27KB) بتتحمّل في النظرة العامة بس
        $adminGeoTag = $tab === 'overview' ? '<script src="' . asset_v('/assets/js/admin-geo.js') . '"></script>' : '';
        // اللغة والاتجاه الديناميكي بدل hardcode عربي (كان lang="ar" dir="rtl"
        // حتى لو الأدمن بيستخدم الإنجليزي/الفرنسي/الألماني فتترجم كل الصفحة RTL).
        $adminLang = current_lang();
        $adminDir = current_dir();
        // نفس درس asset_v: icon_svg() جوه الـ heredoc مبيتفسّرش - نحسب
        // الـ SVG في متغيرات الأول.
        $iconBack = icon_svg('arrow-left');
        $iconAdminLogout = icon_svg('log-out');
        $iconAdminMenu = icon_svg('menu');
        $iconHome = icon_svg('home');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$adminLang}" dir="{$adminDir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$pageTitle} | {$this->tr('admin.page_title')} | {$appName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script>(function(){try{var t=JSON.parse(localStorage.getItem('ap.theme'));if(t!=='dark'&&t!=='light'){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{$styleCssUrl}">
    <link rel="stylesheet" href="{$panelCssUrl}">
    <link rel="stylesheet" href="{$tfThemeCssUrl}">
    <link rel="stylesheet" href="{$adminProCssUrl}">
</head>
<body class="ap-theme">
    <div class="panel-shell">
        <div class="panel-overlay-bg"></div>
        <aside class="panel-sidebar">
            <div class="panel-brand">
                <span class="brand-emoji">{$panelBrandHtml}</span>
                <span class="brand-tag">{$roleLabel}</span>
            </div>
            <div class="panel-user-mini">
                <div class="avatar">{$adminInitial}</div>
                <div class="info">
                    <div class="name">{$adminName}</div>
                    <div class="role">{$adminEmail}</div>
                </div>
            </div>
            <nav class="panel-nav">{$navHtml}</nav>
            <div class="panel-sidebar-footer">
                <a href="/dashboard?view=customer">{$iconBack} {$this->tr('admin.back_to_dashboard')}</a><br><br>
                <a href="/logout">{$iconAdminLogout} {$this->tr('admin.logout')}</a>
            </div>
        </aside>

        <div class="panel-main">
            <header class="panel-topbar">
                <button class="panel-menu-toggle" id="panelMenuToggle">{$iconAdminMenu}</button>
                <div>
                    <h1>{$pageTitle}</h1>
                    <div class="subtitle">{$pageSubtitle}</div>
                </div>
                <div class="panel-topbar-spacer"></div>
                <div class="panel-topbar-actions">
                    <button type="button" class="icon-btn" id="balToggle" onclick="toggleBalances()" title="">👁️</button>
                    <a href="/" class="icon-btn" title="{$this->tr('admin.main_site')}">{$iconHome}</a>
                </div>
            </header>

            <div class="panel-content">
                <div id="loadingMsg" class="p-empty">
                    <div class="p-empty-icon">⏳</div>
                    {$this->tr('admin.loading_data')}
                </div>
                <div id="adminContent" style="display:none;">
                    {$panelBody}
                </div>
            </div>
        </div>
    </div>

    <div class="p-modal-overlay" id="userModal">
        <div class="p-modal">
            <div class="p-modal-head">
                <h3 id="userModalTitle">{$this->tr('admin.user_details')}</h3>
                <button class="p-modal-close" onclick="Panel.closeModal('userModal')">×</button>
            </div>
            <div class="p-modal-body" id="userModalBody"></div>
            <div class="p-modal-foot" id="userModalFooter"></div>
        </div>
    </div>
    <div id="toastStack"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script src="{$panelJsUrl}"></script>
    {$adminGeoTag}
    <script src="{$adminProJsUrl}"></script>
    <script src="{$adminPagesJsUrl}"></script>
    <script src="{$adminInsightsJsUrl}"></script>
    <style>body.hide-balances .is-money{filter:blur(8px);user-select:none;pointer-events:none;transition:filter .15s}</style>
    {$this->i18nScriptTag()}
    <script>window.ADMIN_PERMS = {$adminPermsJson}; window.ADMIN_ROLE = {$adminRoleJson};</script>
    <script>{$script}</script>
</body>
</html>
HTML;
    }

    /**
     * سايد بار لوحة الأدمن (روابط + تبويب فعّال)
     * @param string $activeTab
     * @return string
     */
    /**
     * قايمة الأدمن لصفحات أدمن متعمولة في كنترولرز تانية (سمعة البريد، الباقات، المدونات المستضافة...) - كانت بتفتح
     * جوه قايمة العميل فالأدمن مايلاقيش طريق يرجع للوحة الأدمن.
     */
    public static function sidebarFor(array $user, string $activeTab): string
    {
        $c = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $c->user = $user;
        $c->db = Database::getInstance();
        return $c->renderAdminSidebar($activeTab);
    }

    private function renderAdminSidebar(string $activeTab): string
    {
        // المجموعات بالترتيب: عام، الذكاء الاصطناعي، العملاء والمبيعات، الفلوس، التشغيل، الأمان، الإعدادات
        $L = static fn (string $ar, string $en): string => (function_exists('current_lang') ? current_lang() : 'ar') === 'ar' ? $ar : $en;
        $groups = [
            $this->tr('admin.nav.general') => [
                'overview' => [$this->tr('admin.nav.overview'), 'bar-chart-2', '/admin'],
                'platform' => [$this->tr('admin.nav.platform_overview'), 'compass', '/admin/platform'],
                'metrics' => [$L('أرقام SaaS', 'SaaS metrics'), 'trending-up', '/admin/metrics'],
            ],
            $L('AI CEO والذكاء الاصطناعي', 'AI CEO & AI') => [
                'ai-ceo' => ['AI Mission Control', 'brain', '/admin/ai-ceo'],
                'admin-brain' => [$L('العقل الاستراتيجي', 'Strategic brain'), 'brain', '/admin/brain'],
                'ai-usage' => [$this->tr('admin.nav.ai_usage'), 'brain', '/admin/ai-usage'],
                'ai-service-routing' => [$L('توزيع خدمات AI', 'AI service routing'), 'settings', '/admin/ai-routing'],
                'creative-studio-ops' => [$L('استوديو الوسائط', 'Media studio'), 'image', '/admin/creative-studio'],
            ],
            $L('المحتوى والسيو', 'Content & SEO') => [
                'blog' => [$L('المدونة', 'Blog'), 'edit', '/admin/blog'],
                'hosted-blogs' => [$L('المدونات المستضافة', 'Hosted blogs'), 'globe', '/admin/hosted-blogs'],
                'seo-markets' => [$L('الكلمات والأسواق (SEO)', 'Keywords & markets'), 'globe', '/admin/seo-markets'],
                'seo-data-costs' => [$L('تكلفة DataForSEO', 'DataForSEO cost'), 'dollar', '/admin/seo-data-costs'],
            ],
            $L('العملاء والمبيعات', 'Customers & sales') => [
                'users' => [$this->tr('admin.nav.users'), 'users', '/admin/users'],
                'customers-health' => [$L('صحة العملاء', 'Customer health'), 'target', '/admin/customers-health'],
                'trials' => [$L('متابعة التجارب', 'Trials'), 'clock', '/admin/trials'],
                'adoption' => [$L('استخدام المميزات', 'Feature adoption'), 'activity', '/admin/adoption'],
                'onboarding-funnel' => [$this->tr('admin.nav.onboarding_funnel'), 'flask', '/admin/onboarding-funnel'],
                'platform-growth' => [$L('تسويق المنصة (إيميل)', 'Platform outreach (email)'), 'send', '/admin/growth'],
                'voice' => [$L('مساعد الصوت (تحكم)', 'AI Voice control'), 'phone', '/admin/voice'],
                'email-reputation' => [$L('سمعة التسويق بالبريد', 'Email reputation'), 'mail', '/admin/email-reputation'],
                'reputation-limits' => [$L('حدود السمعة والمراجعات', 'Reputation limits'), 'star', '/admin/reputation-limits'],
                'whatsapp-marketing-admin' => [$L('تسويق واتساب', 'WhatsApp marketing'), 'send', '/admin/whatsapp-marketing'],
                'contact-messages' => [$this->tr('admin.nav.contact_messages'), 'mail', '/admin/contact-messages'],
            ],
            $L('الاشتراكات والفلوس', 'Subscriptions & money') => [
                'subscriptions' => [$this->tr('admin.nav.subscriptions'), 'credit-card', '/admin/subscriptions'],
                'billing-ops' => [$L('الدفع والكوبونات', 'Payments & coupons'), 'credit-card', '/admin/billing-ops'],
                'plans' => [$this->tr('admin.nav.plans'), 'dollar', '/admin/plans'],
                'plans-studio' => [$L('الباقات والعملات والدفع (XPay)', 'Plans studio & XPay'), 'credit-card', '/admin/plans-studio'],
                'finance' => [$this->tr('admin.nav.finance'), 'credit-card', '/admin/finance'],
                'usage-reservations' => [$L('مراجعة الاستخدام المعلق', 'Pending usage review'), 'clock', '/admin/usage-reservations'],
            ],
            $L('التشغيل وصحة المنصة', 'Operations & health') => [
                'health' => [$L('صحة المنصة', 'Platform health'), 'activity', '/admin/health'],
                'alerts' => [$L('التنبيهات', 'Alerts'), 'bell', '/admin/alerts'],
                'errors' => [$L('أخطاء النظام', 'System errors'), 'flag', '/admin/errors'],
                'system' => [$this->tr('admin.nav.system_status'), 'monitor', '/admin/system'],
                'logs' => [$this->tr('admin.nav.logs'), 'file-text', '/admin/logs'],
                'jobs' => [$this->tr('admin.nav.jobs'), 'activity', '/admin/jobs'],
                'emails' => [$L('سجل البريد', 'Email log'), 'mail', '/admin/emails'],
                'status-page' => [$L('صفحة الحالة والتحديثات', 'Status page & updates'), 'globe', '/admin/status-page'],
            ],
            $this->tr('admin.nav.security_tracking') => [
                'visitors' => [$this->tr('admin.nav.visitors'), 'eye', '/admin/visitors'],
                'login-history' => [$this->tr('admin.nav.login_history'), 'lock', '/admin/login-history'],
                'security' => [$this->tr('admin.nav.security'), 'alarm', '/admin/security'],
                'admin-security' => [$L('أمان الأدمن', 'Admin security'), 'lock', '/admin/admin-security'],
                'audit' => [$this->tr('admin.nav.audit'), 'clock', '/admin/audit'],
            ],
            $this->tr('admin.nav.system') => [
                'team' => [$this->tr('admin.nav.team'), 'key', '/admin/team'],
                'settings' => [$this->tr('admin.nav.settings'), 'settings', '/admin/settings'],
                'integrations' => [$this->tr('admin.nav.integrations'), 'plug', '/admin/integrations'],
                'api-keys' => [$this->tr('admin.nav.api_keys'), 'key', '/admin/api-keys'],
                'meta-review' => [$L('جاهزية مراجعة Meta', 'Meta review readiness'), 'flag', '/admin/meta-review'],
            ],
        ];

        $html = '';
        foreach ($groups as $groupTitle => $items) {
            // الصلاحيات: نخفي من القائمة أي صفحة العضو مالوش صلاحية عليها
            if (class_exists('AdminAccess')) {
                $items = array_filter($items, function ($item) {
                    $need = AdminAccess::requiredFor('GET', $item[2]);
                    return $need === null || AdminAccess::can($this->user, $need);
                });
            }
            if (!$items) {
                continue;
            }
            $html .= '<div class="panel-nav-group"><div class="panel-nav-group-title">' . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . '</div>';
            foreach ($items as $key => $item) {
                [$label, $icon, $href] = $item;
                $active = $key === $activeTab ? ' active' : '';
                $html .= "<a href=\"{$href}\" class=\"panel-nav-link{$active}\"><span class=\"ic\">" . icon_svg($icon) . "</span>{$label}</a>";
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * جسم اللوحة حسب التبويب المختار (بدون السايد بار/الهيدر)
     * @param string $tab
     * @return string
     */
    /** القيم اللي لازم تتحط في تطبيق Meta بالظبط (محسوبة من دومين المنصة) - مع زرار نسخ */
    private function metaSetupBox(): string
    {
        $base = class_exists('MetaOAuthClient') ? MetaOAuthClient::baseUrl() : rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $row = function (string $label, string $value, string $hint = '') use ($e) {
            $id = 'mv' . substr(md5($label . $value), 0, 8);
            return '<div style="margin:8px 0"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><span style="font-size:12.5px;font-weight:600">' . $e($label) . '</span>'
                . '<button type="button" class="p-btn outline xs" onclick="navigator.clipboard.writeText(document.getElementById(\'' . $id . '\').textContent).then(()=>window.Panel&&Panel.toast(\'اتنسخ\',\'success\'))">نسخ</button></div>'
                . '<code id="' . $id . '" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:7px 10px;border-radius:8px;direction:ltr;text-align:left;font-size:12px;word-break:break-all;margin-top:4px">' . $e($value) . '</code>'
                . ($hint !== '' ? '<div class="p-cell-muted" style="font-size:11.5px;margin-top:3px">' . $e($hint) . '</div>' : '') . '</div>';
        };
        $verify = class_exists('WhatsAppCloudController') ? WhatsAppCloudController::platformVerifyToken() : '';
        return '<p style="margin:6px 0"><a class="p-btn outline xs" href="/admin/meta-review">✅ جاهزية مراجعة Meta لواتساب (اللي جاهز واللي ناقص + نصوص المراجعة)</a></p>'
            . '<details style="margin:6px 0 14px;border:1px solid var(--panel-border);border-radius:12px;padding:10px 14px"><summary style="cursor:pointer;font-weight:600">📋 القيم اللي تتحط في تطبيق Meta (developers.facebook.com)</summary>'
            . '<p class="p-cell-muted" style="font-size:12px;margin:10px 0 4px">تسجيل الدخول (Facebook Login / Login for Business) ← الإعدادات:</p>'
            . $row('Valid OAuth Redirect URIs (1)', $base . '/ads/connect/meta/callback', 'ربط الإعلانات وصفحات فيسبوك وإنستجرام')
            . $row('Valid OAuth Redirect URIs (2)', $base . '/auth/facebook/callback', 'تسجيل الدخول بفيسبوك')
            . $row('Allowed Domains for the JavaScript SDK', $host, 'وفعّل "Login with the JavaScript SDK" - مطلوب لربط واتساب بضغطة')
            . '<p class="p-cell-muted" style="font-size:12px;margin:12px 0 4px">إعدادات التطبيق ← Basic:</p>'
            . $row('App Domains', $host)
            . $row('Privacy Policy URL', $base . '/privacy')
            . $row('Terms of Service URL', $base . '/terms')
            . $row('User data deletion URL', $base . '/data-deletion')
            . '<p class="p-cell-muted" style="font-size:12px;margin:12px 0 4px">WhatsApp ← Configuration (للشركات اللي بتربط بضغطة من خلال تطبيقك):</p>'
            . $row('Callback URL', $base . '/api/whatsapp-cloud/webhook')
            . $row('Verify token', $verify, 'واشترك في: messages')
            . '<p class="p-cell-muted" style="font-size:12px;margin:12px 0 4px">Webhooks ← Page وكمان Webhooks ← Instagram (رسائل ماسنجر وإنستجرام لكل الشركات):</p>'
            . $row('Callback URL', $base . '/api/meta/webhook')
            . $row('Verify token', class_exists('MetaWebhookController') ? MetaWebhookController::verifyToken() : '', 'واشترك في: messages و messaging_postbacks')
            . '<p class="p-cell-muted" style="font-size:11.5px;margin:10px 0 0">لو تطبيقك نوع Business: اعمل Configuration من Facebook Login for Business بالصلاحيات (pages_show_list, pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish, pages_messaging, instagram_manage_messages, pages_manage_metadata, ads_management, ads_read, business_management) وحط الـ Config ID فوق.</p>'
            . '</details>';
    }

    private function renderAdminPanelBody(string $tab): string
    {
        switch ($tab) {
            case 'seo-data-costs':
                return $this->seoDataCostsHtml();
            case 'team':
            case 'audit':
            case 'ai-usage':
            case 'jobs':
            case 'security':
            case 'finance':
            case 'api-keys':
            case 'health':
            case 'emails':
            case 'metrics':
            case 'customers-health':
            case 'adoption':
            case 'trials':
            case 'errors':
            case 'billing-ops':
            case 'alerts':
            case 'admin-security':
            case 'status-page':
            case 'blog':
                return '<div id="proPage" data-page="' . htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') . '"></div>';
            case 'users':
                return <<<HTML
                <div class="p-toolbar">
                    <input type="text" id="userSearch" class="p-input search" placeholder="🔍 {$this->tr('admin.search_name_email')}" oninput="applyUserFilters()">
                    <select id="userRoleFilter" class="p-select" onchange="applyUserFilters()">
                        <option value="">{$this->tr('admin.all_roles')}</option>
                        <option value="user">{$this->tr('admin.role.client')}</option>
                        <option value="admin">{$this->tr('admin.role.admin')}</option>
                        <option value="super_admin">{$this->tr('admin.role.super_admin')}</option>
                    </select>
                    <select id="userStatusFilter" class="p-select" onchange="applyUserFilters()">
                        <option value="">{$this->tr('chat.all_statuses')}</option>
                        <option value="active">{$this->tr('admin.status.active')}</option>
                        <option value="inactive">{$this->tr('admin.status.suspended')}</option>
                    </select>
                    <button class="p-btn outline" onclick="P.openModal('broadcastModal')">📢 {$this->tr('admin.broadcast.button')}</button>
                    <a href="/admin/export/users" class="p-btn outline">📊 {$this->tr('admin.export.customers')}</a>
                </div>

                <div class="p-modal-overlay" id="broadcastModal">
                    <div class="p-modal">
                        <div class="p-modal-head"><h3>📢 {$this->tr('admin.broadcast.title')}</h3><button class="p-modal-close" onclick="P.closeModal('broadcastModal')">×</button></div>
                        <div class="p-modal-body">
                            <p class="p-cell-muted" style="margin-bottom:14px;">{$this->tr('admin.broadcast.hint')}</p>
                            <label for="broadcastAudience" class="form-label">{$this->tr('admin.broadcast.audience')}</label>
                            <select id="broadcastAudience" class="form-control" style="margin-bottom:10px;">
                                <option value="all">{$this->tr('admin.broadcast.audience.all')}</option>
                                <option value="active_subscribers">{$this->tr('admin.broadcast.audience.subscribers')}</option>
                                <option value="no_subscription">{$this->tr('admin.broadcast.audience.no_sub')}</option>
                            </select>
                            <label for="broadcastTitle" class="form-label">{$this->tr('admin.broadcast.msg_title')}</label>
                            <input type="text" id="broadcastTitle" class="form-control" style="margin-bottom:10px;" maxlength="100">
                            <label for="broadcastBody" class="form-label">{$this->tr('admin.broadcast.msg_body')}</label>
                            <textarea id="broadcastBody" class="form-control" rows="3" maxlength="300"></textarea>
                            <div id="broadcastAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                        </div>
                        <div class="p-modal-foot"><button class="p-btn primary" onclick="sendBroadcast()">{$this->tr('admin.broadcast.send')}</button></div>
                    </div>
                </div>

                <div class="p-card no-pad">
                    <div class="p-table-scroll">
                        <table class="p-table" id="usersTable">
                            <thead><tr><th>{$this->tr('admin.col.company')}</th><th>{$this->tr('admin.col.email')}</th><th>{$this->tr('settings.role')}</th><th>{$this->tr('admin.plan')}</th><th>{$this->tr('chat.col.status')}</th><th>{$this->tr('admin.col.last_login')}</th><th>{$this->tr('admin.registration_date')}</th><th>{$this->tr('admin.col.actions')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="8">{$this->tr('common.loading')}</td></tr></tbody>
                        </table>
                    </div>
                </div>
HTML;

            case 'subscriptions':
                return <<<HTML
                <div class="p-toolbar" style="justify-content:flex-end;">
                    <a href="/admin/export/subscriptions" class="p-btn outline">📊 {$this->tr('admin.export.subscriptions')}</a>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>➕ {$this->tr('admin.manual_activate_title')}</h3><span class="p-card-sub">{$this->tr('admin.manual_activate_sub')}</span></div>
                    <div class="p-grid cols-3">
                        <div class="form-group">
                            <label class="form-label" for="manualUserEmail">{$this->tr('admin.customer_email')}</label>
                            <input type="email" id="manualUserEmail" class="form-control" placeholder="client@example.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="manualPlanName">{$this->tr('admin.plan')}</label>
                            <select id="manualPlanName" class="form-control">
                                <option value="starter">{$this->tr('admin.plan.starter')}</option>
                                <option value="professional">{$this->tr('admin.plan.professional')}</option>
                                <option value="enterprise">{$this->tr('admin.plan.enterprise')}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="manualPlanType">{$this->tr('admin.billing_cycle')}</label>
                            <select id="manualPlanType" class="form-control">
                                <option value="monthly">{$this->tr('admin.monthly')}</option>
                                <option value="yearly">{$this->tr('admin.yearly')}</option>
                            </select>
                        </div>
                    </div>
                    <div id="manualActivateAlert" class="alert alert-danger" style="display:none;"></div>
                    <button class="p-btn primary" onclick="activateManualSubscription()">{$this->tr('admin.activate_subscription')}</button>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-top:16px;flex-wrap:wrap"><button class="p-btn xs primary" data-subsview="current">الاشتراكات الحالية</button><button class="p-btn xs outline" data-subsview="all">السجل الكامل</button><span class="p-cell-muted" id="subsCounts" style="font-size:12.5px"></span></div>
                <div class="p-card no-pad" style="margin-top:10px;">
                    <div class="p-table-scroll">
                        <table class="p-table" id="subsTable">
                            <thead><tr><th>#</th><th>{$this->tr('admin.customer')}</th><th>{$this->tr('admin.plan')}</th><th>السعر</th><th>{$this->tr('chat.col.status')}</th><th>التجديد / الانتهاء</th><th>{$this->tr('admin.created_date')}</th><th></th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="8">{$this->tr('common.loading')}</td></tr></tbody>
                        </table>
                    </div>
                </div>
HTML;

            case 'visitors':
                return <<<HTML
                <div class="p-toolbar">
                    <select id="visitorsDays" class="p-select" onchange="reloadVisitors()">
                        <option value="7">{$this->tr('admin.last_7_days')}</option>
                        <option value="30" selected>{$this->tr('admin.last_30_days')}</option>
                        <option value="90">{$this->tr('admin.last_90_days')}</option>
                    </select>
                </div>
                <div class="p-grid cols-3">
                    <div class="p-card stat-tile"><div class="stat-icon blue">🧭</div><div class="stat-info"><div class="stat-value" id="vTotal">0</div><div class="stat-label">{$this->tr('admin.total_visits')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">🙋</div><div class="stat-info"><div class="stat-value" id="vUnique">0</div><div class="stat-label">{$this->tr('admin.unique_visitors')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">🔓</div><div class="stat-info"><div class="stat-value" id="vAuth">0</div><div class="stat-label">{$this->tr('admin.logged_in_visits')}</div></div></div>
                </div>
                <div class="p-card" style="margin-top:18px;">
                    <div class="p-card-head"><h3>{$this->tr('admin.visits_over_time')}</h3></div>
                    <div class="chart-wrap"><canvas id="visitorsTrendChart"></canvas></div>
                </div>
                <div class="p-grid cols-2" style="margin-top:18px;">
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('admin.top_pages')}</h3></div>
                        <div class="p-table-scroll"><table class="p-table" id="topPagesTable">
                            <thead><tr><th>{$this->tr('admin.page')}</th><th>{$this->tr('admin.chart.visits')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="2">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('admin.top_countries')}</h3></div>
                        <div class="p-table-scroll"><table class="p-table" id="topCountriesTable">
                            <thead><tr><th>{$this->tr('admin.country')}</th><th>{$this->tr('admin.chart.visits')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="2">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                </div>
                <div class="p-card no-pad" style="margin-top:18px;">
                    <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('admin.recent_visits_log')}</h3></div>
                    <div class="p-table-scroll"><table class="p-table" id="visitorLogTable">
                        <thead><tr><th>{$this->tr('admin.page')}</th><th>{$this->tr('admin.user')}</th><th>IP</th><th>{$this->tr('admin.device')}</th><th>{$this->tr('admin.location')}</th><th>{$this->tr('admin.time')}</th></tr></thead>
                        <tbody><tr class="p-loading-row"><td colspan="6">{$this->tr('common.loading')}</td></tr></tbody>
                    </table></div>
                </div>
HTML;

            case 'login-history':
                return <<<HTML
                <div class="p-toolbar">
                    <select id="loginStatusFilter" class="p-select" onchange="filterLoginHistory()">
                        <option value="">{$this->tr('admin.all_attempts')}</option>
                        <option value="success">{$this->tr('admin.successful_only')}</option>
                        <option value="failed">{$this->tr('admin.failed_only')}</option>
                    </select>
                </div>
                <div class="p-card no-pad">
                    <div class="p-table-scroll">
                        <table class="p-table" id="loginHistoryTable">
                            <thead><tr><th>{$this->tr('admin.account')}</th><th>{$this->tr('admin.result')}</th><th>IP</th><th>{$this->tr('admin.device')}</th><th>{$this->tr('admin.location')}</th><th>{$this->tr('admin.col.date')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="6">{$this->tr('common.loading')}</td></tr></tbody>
                        </table>
                    </div>
                </div>
HTML;

            case 'onboarding-funnel':
                return <<<HTML
                <div class="p-toolbar">
                    <span class="p-cell-muted" style="font-size:12.5px;">{$this->tr('admin.onboarding_funnel.hint')}</span>
                </div>
                <div class="p-grid cols-3">
                    <div class="p-card stat-tile"><div class="stat-icon blue">👀</div><div class="stat-info"><div class="stat-value" id="ofViews">0</div><div class="stat-label">{$this->tr('admin.onboarding_funnel.views')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">📝</div><div class="stat-info"><div class="stat-value" id="ofSubmitted">0</div><div class="stat-label">{$this->tr('admin.onboarding_funnel.submitted')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value" id="ofCompleted">0</div><div class="stat-label">{$this->tr('admin.onboarding_funnel.completed')}</div></div></div>
                </div>
                <div class="p-grid cols-3" style="margin-top:14px;">
                    <div class="p-card stat-tile"><div class="stat-icon amber">🎯</div><div class="stat-info"><div class="stat-value" id="ofSubmitPct">0%</div><div class="stat-label">{$this->tr('admin.onboarding_funnel.view_to_submit')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon gold">🏁</div><div class="stat-info"><div class="stat-value" id="ofCompletePct">0%</div><div class="stat-label">{$this->tr('admin.onboarding_funnel.view_to_complete')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon cyan">⏱️</div><div class="stat-info"><div class="stat-value" id="ofAvgMin">—</div><div class="stat-label">{$this->tr('admin.onboarding_funnel.avg_completion')}</div></div></div>
                </div>
                <div class="p-grid cols-2" style="margin-top:18px;">
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('admin.onboarding_funnel.steps_title')}</h3></div>
                        <div class="p-table-scroll"><table class="p-table" id="ofStepsTable">
                            <thead><tr><th>{$this->tr('admin.onboarding_funnel.step')}</th><th>{$this->tr('admin.onboarding_funnel.users_reached')}</th><th style="width:40%;">{$this->tr('admin.onboarding_funnel.dropoff')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                    <div class="p-card" style="margin-top:0;">
                        <div class="p-card-head"><h3>{$this->tr('admin.onboarding_funnel.trend_title')}</h3></div>
                        <div class="chart-wrap"><canvas id="ofTrendChart"></canvas></div>
                    </div>
                </div>
HTML;

            case 'logs':
                return <<<HTML
                <div class="p-card no-pad">
                    <div class="p-table-scroll">
                        <table class="p-table" id="logsTable">
                            <thead><tr><th>{$this->tr('admin.event')}</th><th>{$this->tr('admin.user')}</th><th>{$this->tr('admin.col.date')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
                        </table>
                    </div>
                </div>
HTML;

            case 'contact-messages':
                return <<<HTML
                <div class="p-card no-pad">
                    <div class="p-table-scroll">
                        <table class="p-table" id="contactMessagesTable">
                            <thead><tr><th>{$this->tr('crm.leads.col.name')}</th><th>{$this->tr('admin.col.email')}</th><th>{$this->tr('admin.message')}</th><th>{$this->tr('chat.col.status')}</th><th>{$this->tr('admin.col.date')}</th><th></th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="6">{$this->tr('common.loading')}</td></tr></tbody>
                        </table>
                    </div>
                </div>
HTML;

            case 'plans':
                // الصفحة كانت 4800 بيكسل و7 مواضيع: الباقات والأرقام فوق، والباقي (MRR، كروت الشحن، الإيداعات، بيانات الدفع، اشتراك المواقع، التسعير بالاستخدام) تبويبات
                $tabsJs = htmlspecialchars(asset_v('/assets/js/admin-section-tabs.js'), ENT_QUOTES, 'UTF-8');
                return <<<HTML
                <p class="p-cell-muted" style="margin-bottom:16px;">{$this->tr('admin.plans.hint')}</p>
                <div class="p-card" style="margin-bottom:16px"><div class="p-card-head"><h3>➕ باقة جديدة</h3><span class="p-card-sub">بتتنسخ من باقة موجودة وبتتعمل مقفولة للبيع لحد ما تفعّلها. ابدأ المفتاح بـ seo- عشان تظهر في تبويب السيو.</span></div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                <label style="display:flex;flex-direction:column;gap:4px"><span class="p-cell-muted">المفتاح (إنجليزي)</span><input class="form-control" id="newPlanKey" dir="ltr" maxlength="30" placeholder="seo-local" style="max-width:170px"></label>
                <label style="display:flex;flex-direction:column;gap:4px"><span class="p-cell-muted">الاسم</span><input class="form-control" id="newPlanName" maxlength="150" style="max-width:200px"></label>
                <label style="display:flex;flex-direction:column;gap:4px"><span class="p-cell-muted">انسخ من</span><select class="form-control" id="newPlanClone" style="max-width:200px"></select></label>
                <button type="button" class="p-btn" id="newPlanAdd">➕ أضف الباقة</button></div><div id="newPlanMsg" class="p-cell-muted" style="margin-top:8px"></div></div>
                <div id="plansGrid" class="p-grid cols-3"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                <div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>إيراد وتكلفة وهامش كل باقة</h3><span class="p-card-sub">فعلي لآخر 30 يوم</span></div><div id="planEconBox" class="p-cell-muted">...</div></div>
                <div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>تسعير مقابل المنافسين</h3><span class="p-card-sub">أعلى سعر مسموح = سعر المنافس × (100 − النسبة). مفيش سعر بيتغيّر لوحده: التطبيق بزرار.</span></div><div id="planPosBox" class="p-cell-muted">...</div></div>
                <div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>سجل تعديل الباقات</h3><span class="p-card-sub">مين غيّر إيه وإمتى (القيمة قبل وبعد)</span></div><div id="planAuditBox" class="p-cell-muted">...</div></div>

                <div class="p-modal-overlay" id="editPlanModal">
                    <div class="p-modal">
                        <div class="p-modal-head"><h3>{$this->tr('admin.plans.edit_title')}</h3><button class="p-modal-close" onclick="P.closeModal('editPlanModal')">×</button></div>
                        <div class="p-modal-body">
                            <input type="hidden" id="planId">
                            <label for="planName" class="form-label">{$this->tr('admin.plans.name')}</label>
                            <input type="text" id="planName" class="form-control" style="margin-bottom:10px;">
                            <div class="p-grid cols-2">
                                <div>
                                    <label for="planPriceMonthly" class="form-label">{$this->tr('admin.plans.price_monthly')}</label>
                                    <input type="number" step="0.01" id="planPriceMonthly" class="form-control" style="margin-bottom:10px;">
                                </div>
                                <div>
                                    <label for="planPriceYearly" class="form-label">{$this->tr('admin.plans.price_yearly')}</label>
                                    <input type="number" step="0.01" id="planPriceYearly" class="form-control" style="margin-bottom:10px;">
                                </div>
                            </div>
                            <div class="p-grid cols-2">
                                <div>
                                    <label for="planCurrency" class="form-label">{$this->tr('admin.plans.currency')}</label>
                                    <select id="planCurrency" class="form-control" style="margin-bottom:10px;">
                                        <option value="USD">USD</option>
                                        <option value="EGP">EGP</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                        <option value="SAR">SAR</option>
                                        <option value="AED">AED</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="planCurrencySymbol" class="form-label">{$this->tr('admin.plans.currency_symbol')}</label>
                                    <input type="text" id="planCurrencySymbol" class="form-control" style="margin-bottom:10px;" maxlength="10">
                                </div>
                            </div>
                            <hr style="border-color:var(--panel-border);margin:14px 0;">
                            <p class="p-cell-muted" style="margin-bottom:10px;">{$this->tr('admin.plans.limits_hint')}</p>
                            <div class="p-grid cols-2">
                                <div><label for="planAiAnalysis" class="form-label">{$this->tr('admin.plans.ai_analysis')}</label><input type="number" id="planAiAnalysis" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planCompetitorAnalysis" class="form-label">{$this->tr('admin.plans.competitor_analysis')}</label><input type="number" id="planCompetitorAnalysis" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planChatCredits" class="form-label">{$this->tr('admin.plans.chat_credits')}</label><input type="number" id="planChatCredits" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planReviewCredits" class="form-label">{$this->tr('admin.plans.review_credits')}</label><input type="number" id="planReviewCredits" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planMultipleWebsites" class="form-label">{$this->tr('admin.plans.multiple_websites')}</label><input type="number" id="planMultipleWebsites" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planAssistantMsgs" class="form-label">رسائل المساعد الذكي (لكل فترة)</label><input type="number" min="-1" id="planAssistantMsgs" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planWebsiteGens" class="form-label">توليد موقع كامل (لكل فترة)</label><input type="number" min="-1" id="planWebsiteGens" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planWebsiteItems" class="form-label">إضافة رحلة/غرفة لموقع (لكل فترة)</label><input type="number" min="-1" id="planWebsiteItems" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="planWhatsAppMarketing" class="form-label">WhatsApp marketing recipients per subscription period (0 disables, -1 unlimited)</label><input type="number" min="-1" id="planWhatsAppMarketing" class="form-control" style="margin-bottom:10px;"></div>
                                <div class="p-cell-muted" style="grid-column:1/-1;font-size:12px;margin:-4px 0 10px">-1 = غير محدود، 0 = مش مشمولة (بتتخصم من المحفظة). أي حاجة بعد ما الحصة تخلص بتتخصم من المحفظة.</div>
                                <div class="p-cell-muted" style="grid-column:1/-1;font-weight:600">حدود خدمة السيو والظهور في الذكاء الاصطناعي</div>
                                <div><label for="plan_seo_cycles" class="form-label">فحوصات وتحسين تلقائي في الشهر (1-4)</label><input type="number" min="1" max="4" id="plan_seo_cycles" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="plan_seo_max_pages" class="form-label">صفحات في كل فحص (100 أو 300 أو 500)</label><select id="plan_seo_max_pages" class="form-control" style="margin-bottom:10px;"><option value="100">100</option><option value="300">300</option><option value="500">500</option></select></div>
                                <div><label for="plan_seo_markets" class="form-label">أسواق ولغات لكل موقع (1-12)</label><input type="number" min="1" max="12" id="plan_seo_markets" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="plan_ai_visibility" class="form-label">قياسات الظهور في ChatGPT/Gemini/Perplexity في الشهر</label><input type="number" min="0" id="plan_ai_visibility" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="plan_seo_data" class="form-label">وحدات بيانات الكلمات والمنافسين في الشهر</label><input type="number" min="0" id="plan_seo_data" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label for="plan_articles" class="form-label">مقالات في الشهر</label><input type="number" min="0" id="plan_articles" class="form-control" style="margin-bottom:10px;"></div>
                            </div>
                            <label style="display:flex;align-items:center;gap:8px;margin:8px 0;"><input type="checkbox" id="planWhatsappBot"> {$this->tr('admin.plans.whatsapp_bot')}</label>
                            <label style="display:flex;align-items:center;gap:8px;margin:8px 0;"><input type="checkbox" id="planAutoPilot"> {$this->tr('admin.plans.auto_pilot')}</label>
                            <label style="display:flex;align-items:center;gap:8px;margin:8px 0;"><input type="checkbox" id="planAdvancedAnalytics"> {$this->tr('admin.plans.advanced_analytics')}</label>
                            <label style="display:flex;align-items:center;gap:8px;margin:8px 0;"><input type="checkbox" id="planIsActive"> {$this->tr('admin.plans.is_active')}</label>
                            <div id="editPlanAlert" class="alert alert-danger" style="display:none;"></div>
                        </div>
                        <div class="p-modal-foot"><button class="p-btn primary" onclick="savePlan()">{$this->tr('common.save')}</button></div>
                    </div>
                </div>

                <div class="p-grid cols-4" id="walletStatsGrid" style="margin-top:20px;">
                    <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value" id="wsDepositsMonth">$0</div><div class="stat-label">{$this->tr('wallet.admin.stat.deposits_month')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon orange">⏳</div><div class="stat-info"><div class="stat-value" id="wsPendingTotal">$0</div><div class="stat-label">{$this->tr('wallet.admin.stat.pending_total')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon blue">🏦</div><div class="stat-info"><div class="stat-value" id="wsTotalBalances">$0</div><div class="stat-label">{$this->tr('wallet.admin.stat.total_balances')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">🎯</div><div class="stat-info"><div class="stat-value" id="wsUsageMonth">$0</div><div class="stat-label">{$this->tr('wallet.admin.stat.usage_month')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">📈</div><div class="stat-info"><div class="stat-value" id="wsMRR">-</div><div class="stat-label">MRR (إيراد شهري متكرر)</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">📅</div><div class="stat-info"><div class="stat-value" id="wsARR">-</div><div class="stat-label">ARR (إيراد سنوي متكرر)</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon blue">👥</div><div class="stat-info"><div class="stat-value" id="wsActiveSubs">-</div><div class="stat-label">اشتراكات فعّالة</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon orange">📉</div><div class="stat-info"><div class="stat-value" id="wsChurn">-</div><div class="stat-label">معدّل التسرّب هذا الشهر (تقريبي)</div></div></div>
                </div>
                <div id="platformBalanceBox"></div>
                <div data-section-tabs="plans-admin" data-section-selector=":scope > .p-card">
                <div class="p-card" style="margin-top:12px;">
                    <div class="p-card-head"><h3>📈 تطوّر MRR</h3><span class="p-card-sub" id="mrrTrendSub">آخر 30 يوم - بيتسجّل تلقائيًا كل يوم تفتح فيه الصفحة دي</span></div>
                    <div id="mrrTrendChart" style="padding:10px 0;"></div>
                </div>

                <div class="p-card" style="margin-top:16px;">
                    <div class="p-card-head"><h3>🎫 {$this->tr('admin.cards.title')}</h3><span class="p-card-sub">{$this->tr('admin.cards.subtitle')}</span></div>
                    <div class="p-grid cols-3">
                        <div class="form-group"><label for="cardsCount" class="form-label">{$this->tr('admin.cards.count')}</label><input type="number" id="cardsCount" class="form-control" min="1" max="500" value="10"></div>
                        <div class="form-group"><label for="cardsValue" class="form-label">{$this->tr('admin.cards.value')}</label><input type="number" id="cardsValue" class="form-control" min="0.01" step="0.01" value="10"></div>
                        <div class="form-group"><label for="cardsBatchLabel" class="form-label">{$this->tr('admin.cards.batch_label')}</label><input type="text" id="cardsBatchLabel" class="form-control" placeholder="{$this->tr('admin.cards.batch_placeholder')}"></div>
                    </div>
                    <button class="p-btn primary" onclick="generateCards()">🎫 {$this->tr('admin.cards.generate')}</button>
                    <div id="cardsAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                    <div id="newCardsResult" style="margin-top:14px;"></div>
                    <h4 style="margin:20px 0 10px;font-size:13.5px;">{$this->tr('admin.cards.all_cards')}</h4>
                    <div class="p-table-scroll">
                        <table class="p-table" id="cardsTable">
                            <thead><tr><th>{$this->tr('admin.cards.col.code')}</th><th>{$this->tr('admin.cards.col.value')}</th><th>{$this->tr('chat.col.status')}</th><th>{$this->tr('admin.cards.col.used_by')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
                        </table>
                    </div>
                </div>

                <div class="p-card" style="margin-top:16px;">
                    <div class="p-card-head"><h3>💰 {$this->tr('wallet.admin.pending_title')}</h3><span class="p-card-sub">{$this->tr('wallet.admin.pending_sub')}</span></div>
                    <div id="pendingDepositsList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>

                <div class="p-card" style="margin-top:16px;">
                    <div class="p-card-head"><h3>⚙️ {$this->tr('wallet.admin.settings_title')}</h3><span class="p-card-sub">{$this->tr('wallet.admin.settings_sub')}</span></div>
                    <div class="p-grid cols-2">
                        <div>
                            <label for="wsIban" class="form-label">IBAN</label>
                            <input type="text" id="wsIban" class="form-control" style="margin-bottom:10px;" dir="ltr">
                            <label for="wsIbanBank" class="form-label">{$this->tr('wallet.bank_name')}</label>
                            <input type="text" id="wsIbanBank" class="form-control" style="margin-bottom:10px;">
                            <label for="wsIbanAccount" class="form-label">{$this->tr('wallet.account_name')}</label>
                            <input type="text" id="wsIbanAccount" class="form-control" style="margin-bottom:10px;">
                        </div>
                        <div>
                            <label for="wsPaypal" class="form-label">PayPal Email</label>
                            <input type="email" id="wsPaypal" class="form-control" style="margin-bottom:10px;" dir="ltr">
                            <label for="wsInstapay" class="form-label">InstaPay</label>
                            <input type="text" id="wsInstapay" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="name@instapay">
                            <label for="wsMobileWallet" class="form-label">رقم محفظة موبايل (فودافون كاش / غيره)</label>
                            <input type="text" id="wsMobileWallet" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="01xxxxxxxxx">
                            <label for="wsMobileWalletName" class="form-label">اسم صاحب المحفظة</label>
                            <input type="text" id="wsMobileWalletName" class="form-control" style="margin-bottom:10px;">
                            <label for="wsWhatsapp" class="form-label">{$this->tr('wallet.admin.whatsapp_number')}</label>
                            <input type="text" id="wsWhatsapp" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="201xxxxxxxxx">
                        </div>
                    </div>
                    <label for="wsSwift" class="form-label">SWIFT / BIC (للتحويلات الدولية - اختياري)</label>
                    <input type="text" id="wsSwift" class="form-control" style="margin-bottom:10px;max-width:320px;" dir="ltr">
                    <label for="wsInstructions" class="form-label">ملاحظة للعميل تحت بيانات الدفع (اختياري)</label>
                    <textarea id="wsInstructions" class="form-control" rows="2" style="margin-bottom:10px;" placeholder="مثال: اكتب إيميل حسابك في سبب التحويل"></textarea>
                    <div class="p-cell-muted" style="font-size:12px;margin-bottom:10px;">أي طريقة تسيبها فاضية مش هتظهر للعميل. PayPal: اكتب الإيميل أو رابط paypal.me/اسمك. العميل بيحوّل ويضغط «حوّلت»، وطلبه بيظهر فوق في «طلبات الإيداع» - أول ما توافق، الباقة اللي اختارها بتتفعّل لوحدها.</div>
                    <div id="walletSettingsAlert" class="alert alert-danger" style="display:none;"></div>
                    <button class="p-btn primary" onclick="saveWalletSettings()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-card" style="margin-top:16px;" id="siteSubsCard">
                    <div class="p-card-head"><h3>🌐 اشتراك المواقع (منشئ المواقع)</h3><span class="p-card-sub">الموقع منتج لوحده: العميل بيعمل معاينة مجانًا، والنشر محتاج اشتراك موقع. مشتركين المنصة بياخدوا خصم.</span></div>
                    <div class="p-grid cols-4">
                        <div><label for="ssMonthly" class="form-label">السعر الشهري ($)</label><input type="number" id="ssMonthly" class="form-control" min="1" step="0.01" dir="ltr"></div>
                        <div><label for="ssYearly" class="form-label">السعر السنوي ($)</label><input type="number" id="ssYearly" class="form-control" min="1" step="0.01" dir="ltr"></div>
                        <div><label for="ssDiscount" class="form-label">خصم مشتركين المنصة (%)</label><input type="number" id="ssDiscount" class="form-control" min="0" max="90" dir="ltr"></div>
                        <div><label for="ssGrace" class="form-label">أيام السماح بعد انتهاء الاشتراك</label><input type="number" id="ssGrace" class="form-control" min="0" max="30" dir="ltr"></div>
                    </div>
                    <label style="display:flex;gap:8px;align-items:center;margin:10px 0;font-size:13px"><input type="checkbox" id="ssEnabled"> تفعيل اشتراك المواقع (لو قفلته: كل المواقع المنشورة شغالة ببلاش زي الأول)</label>
                    <button class="p-btn primary" onclick="saveSiteSubsConfig()">حفظ</button>
                    <h4 style="margin:20px 0 10px;font-size:13.5px;">كل المواقع</h4>
                    <div id="siteSubsList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>

                <div class="p-card" style="margin-top:16px;">
                    <div class="p-card-head"><h3>🎯 {$this->tr('wallet.admin.usage_pricing_title')}</h3><span class="p-card-sub">{$this->tr('wallet.admin.usage_pricing_sub')}</span></div>
                    <div id="usagePricingList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>
                </div>
                <script src="{$tabsJs}"></script>
HTML;

            case 'system':
                return <<<HTML
                <div class="p-grid cols-4">
                    <div class="p-card stat-tile"><div class="stat-icon blue">👥</div><div class="stat-info"><div class="stat-value" id="statUsers">0</div><div class="stat-label">{$this->tr('sidebar.admin_users')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">💳</div><div class="stat-info"><div class="stat-value" id="statSubs">0</div><div class="stat-label">{$this->tr('admin.active_subs')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon orange">🌐</div><div class="stat-info"><div class="stat-value" id="statWebsites">0</div><div class="stat-label">{$this->tr('sidebar.websites')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">🖥️</div><div class="stat-info"><div class="stat-value" id="statPhp">-</div><div class="stat-label">{$this->tr('admin.php_version')}</div></div></div>
                </div>
                <div class="p-card" style="margin-top:18px;">
                    <div class="p-card-head">
                        <h3>{$this->tr('admin.system_status')}</h3>
                        <button class="p-btn outline xs" onclick="clearAdminCache(this)">🧹 {$this->tr('admin.clear_cache')}</button>
                    </div>
                    <div id="systemHealthBox">{$this->tr('admin.checking_system')}</div>
                </div>

                <div class="p-card" style="margin-top:18px;">
                    <div class="p-card-head"><h3>🚀 {$this->tr('admin.new_features_title')}</h3><span class="p-card-sub">{$this->tr('admin.new_features_sub')}</span></div>
                    <div class="p-grid cols-3" id="newFeaturesStatsGrid">
                        <div class="p-card stat-tile"><div class="stat-icon gold">🏗️</div><div class="stat-info"><div class="stat-value" id="statWbSites">0</div><div class="stat-label">{$this->tr('admin.stat.generated_sites')}</div></div></div>
                        <div class="p-card stat-tile"><div class="stat-icon teal">✨</div><div class="stat-info"><div class="stat-value" id="statAiConversations">0</div><div class="stat-label">{$this->tr('admin.stat.ai_conversations')}</div></div></div>
                        <div class="p-card stat-tile"><div class="stat-icon coral">📨</div><div class="stat-info"><div class="stat-value" id="statReviewRequests">0</div><div class="stat-label">{$this->tr('admin.stat.review_requests_sent')}</div></div></div>
                    </div>
                    <div id="newFeaturesDetail" style="margin-top:14px;"></div>
                </div>
HTML;

            case 'settings':
                $metaSetupBox = $this->metaSetupBox();
                $stripeRedirect = htmlspecialchars(rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/payments/stripe/callback', ENT_QUOTES, 'UTF-8');
                $tiktokRedirect = htmlspecialchars(rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/social/connect/tiktok/callback', ENT_QUOTES, 'UTF-8');
                // الصفحة كانت 9500 بيكسل: كل كارت (الهوية، القانوني، المميزات، النظام، الأسئلة الشائعة) بقى تبويب
                $tabsJs = htmlspecialchars(asset_v('/assets/js/admin-section-tabs.js'), ENT_QUOTES, 'UTF-8');
                return <<<HTML
                <div data-section-tabs="settings" data-section-selector=".p-card">
                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>🎨 {$this->tr('admin.brand.title')}</h3><span class="p-card-sub">{$this->tr('admin.brand.subtitle')}</span></div>

                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="br_site_name" class="form-label">{$this->tr('admin.brand.site_name')}</label><input type="text" id="br_site_name" class="form-control"></div>
                        <div class="form-group"><label for="br_site_logo_height" class="form-label">{$this->tr('admin.brand.logo_height')}</label><input type="number" id="br_site_logo_height" class="form-control" min="16" max="120"></div>
                    </div>

                    <label class="form-label" style="margin-top:10px;display:block;">{$this->tr('admin.brand.logo')}</label>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">
                        <div id="brLogoPreview" style="width:80px;height:80px;border-radius:10px;background:var(--panel-card-bg-2);display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            <span class="p-cell-muted" style="font-size:11px;">{$this->tr('admin.brand.no_logo')}</span>
                        </div>
                        <div>
                            <input type="file" id="brLogoFile" accept=".jpg,.jpeg,.png,.webp,.svg" style="display:none;" onchange="uploadSiteLogo(this)">
                            <button class="p-btn outline xs" onclick="document.getElementById('brLogoFile').click()">📤 {$this->tr('admin.brand.upload_logo')}</button>
                            <p class="p-cell-muted" style="font-size:11px;margin-top:6px;">JPG, PNG, WEBP, SVG - حتى 2 ميجا</p>
                        </div>
                    </div>

                    <label class="form-label" style="margin-top:10px;display:block;">أيقونة تبويب المتصفح (Favicon)</label>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">
                        <div id="brFaviconPreview" style="width:44px;height:44px;border-radius:8px;background:var(--panel-card-bg-2);display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            <span class="p-cell-muted" style="font-size:10px;">لا يوجد</span>
                        </div>
                        <div>
                            <input type="file" id="brFaviconFile" accept=".png,.svg" style="display:none;" onchange="uploadSiteFavicon(this)">
                            <button class="p-btn outline xs" onclick="document.getElementById('brFaviconFile').click()">📤 رفع أيقونة التبويب</button>
                            <p class="p-cell-muted" style="font-size:11px;margin-top:6px;">مربّعة الشكل يفضّل (PNG أو SVG) - حتى 2 ميجا</p>
                        </div>
                    </div>

                    <div class="p-grid cols-2" style="margin-top:10px;">
                        <div class="form-group"><label for="br_contact_phone" class="form-label">{$this->tr('admin.brand.phone')}</label><input type="text" id="br_contact_phone" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="br_contact_email" class="form-label">{$this->tr('admin.brand.email')}</label><input type="email" id="br_contact_email" class="form-control" dir="ltr"></div>
                    </div>
                    <div class="form-group"><label for="br_site_address" class="form-label">{$this->tr('admin.brand.address')}</label><input type="text" id="br_site_address" class="form-control"></div>

                    <div id="brandAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="saveBrandSettings()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>📜 {$this->tr('admin.legal.title')}</h3><span class="p-card-sub">{$this->tr('admin.legal.subtitle')}</span></div>
                    <label for="legalTermsContent" class="form-label">{$this->tr('admin.legal.terms')}</label>
                    <textarea id="legalTermsContent" class="form-control" rows="8" style="font-family:monospace;font-size:12px;margin-bottom:14px;" dir="ltr"></textarea>
                    <label for="legalPrivacyContent" class="form-label">{$this->tr('admin.legal.privacy')}</label>
                    <textarea id="legalPrivacyContent" class="form-control" rows="8" style="font-family:monospace;font-size:12px;margin-bottom:14px;" dir="ltr"></textarea>
                    <label for="legalRefundContent" class="form-label">سياسة الاسترجاع (/refund-policy) - سيبها فاضية عشان تستخدم النص الافتراضي بالعربي والإنجليزي</label>
                    <textarea id="legalRefundContent" class="form-control" rows="8" style="font-family:monospace;font-size:12px;margin-bottom:10px;" dir="ltr"></textarea>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:10px;">{$this->tr('admin.legal.hint')}</p>
                    <div id="legalAlert" class="alert alert-danger" style="display:none;margin-bottom:10px;"></div>
                    <button class="p-btn primary" onclick="saveLegalContent()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>🎛️ {$this->tr('admin.features.title')}</h3><span class="p-card-sub">{$this->tr('admin.features.subtitle')}</span></div>
                    <div id="featuresList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>

                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>🔌 {$this->tr('admin.sys.title')}</h3><span class="p-card-sub">{$this->tr('admin.sys.subtitle')}</span></div>

                    <h4 style="margin:14px 0 8px;font-size:13px;color:var(--panel-text-muted);">🤖 {$this->tr('admin.sys.cat.ai')}</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_gemini_api_key" class="form-label">Gemini API Key</label><input type="password" id="ss_gemini_api_key" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h3>قياس ظهور AI</h3><p>قياس أسبوعي مدفوع. 0 في الميزانية يوقف الطلبات. المفاتيح بتتشفّر ومش بترجع للمتصفح. النماذج: gpt-4.1-mini / gemini-2.5-flash / sonar. راجع أسعار المزودين قبل التفعيل. Perplexity: مسار Sonar التوافقي ممكن يتحوّل إلى Agent API؛ التكلفة تتسجل من رد المزود فقط.</p>
                    <div class="p-grid cols-2">
                    <div class="form-group"><label class="form-label" for="ss_geo_enabled">تفعيل قياس ظهور AI المدفوع</label><select id="ss_geo_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_monthly_budget_usd">ميزانية قياس AI الشهرية بالدولار (0 يوقف الطلبات)</label><input type="number" id="ss_geo_monthly_budget_usd" class="form-control" min="0" max="1000000" step="0.01" value="0" dir="ltr"></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_openai_enabled">تفعيل openai لقياس الظهور</label><select id="ss_geo_openai_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_openai_api_key">openai GEO API Key</label><input type="password" id="ss_geo_openai_api_key" class="form-control" autocomplete="new-password" dir="ltr"></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_gemini_enabled">تفعيل gemini لقياس الظهور</label><select id="ss_geo_gemini_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_gemini_api_key">gemini GEO API Key</label><input type="password" id="ss_geo_gemini_api_key" class="form-control" autocomplete="new-password" dir="ltr"></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_perplexity_enabled">تفعيل perplexity لقياس الظهور</label><select id="ss_geo_perplexity_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                    <div class="form-group"><label class="form-label" for="ss_geo_perplexity_api_key">perplexity GEO API Key</label><input type="password" id="ss_geo_perplexity_api_key" class="form-control" autocomplete="new-password" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">DataForSEO — بحث الكلمات والترتيب</h4>
                    <p class="p-cell-muted">استخدم API Login وAPI Password من حساب DataForSEO. سقف الميزانية لكل المنصة بالدولار في الشهر؛ 0 بيوقف الطلبات المدفوعة. <a href="/admin/seo-markets">إدارة أسواق المواقع</a></p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label" for="ss_serp_login">DataForSEO API Login</label><input type="password" id="ss_serp_login" class="form-control" autocomplete="new-password" dir="ltr"></div>
                        <div class="form-group"><label class="form-label" for="ss_serp_password">DataForSEO API Password</label><input type="password" id="ss_serp_password" class="form-control" autocomplete="new-password" dir="ltr"></div>
                        <div class="form-group"><label class="form-label" for="ss_serp_enabled">تفعيل الطلبات المدفوعة</label><select id="ss_serp_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                        <div class="form-group"><label class="form-label" for="ss_serp_monthly_budget_usd">سقف الميزانية الشهري بالدولار</label><input type="number" id="ss_serp_monthly_budget_usd" class="form-control" min="0" max="1000000" step="0.01" value="0" dir="ltr"></div><div class="form-group"><label class="form-label" for="ss_serp_signup_daily_usd">سقف يومي لتحليل المنافسين في التسجيل (دولار)</label><input type="number" id="ss_serp_signup_daily_usd" class="form-control" min="0" max="1000" step="0.01" placeholder="1" dir="ltr"><small class="form-text">كل تحليل في صفحة التسجيل بيكلّف تقريبًا 0.03$ من ميزانية DataForSEO.</small></div>
                        <div class="form-group"><label class="form-label" for="ss_serp_local_enabled">قياسات Google Maps المدفوعة</label><select id="ss_serp_local_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                        <div class="form-group"><label class="form-label" for="ss_serp_backlinks_enabled">بيانات Backlinks المدفوعة (تحتاج اشتراك المزوّد)</label><select id="ss_serp_backlinks_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                        <div class="form-group"><label class="form-label" for="ss_serp_device">جهاز البحث</label><select id="ss_serp_device" class="form-control"><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></div>
                    </div>

                    <p id="ss_serp_budget_status" class="p-cell-muted" role="status">جارٍ قراءة ميزانية بيانات البحث…</p><p class="p-cell-muted">الحصة ومحفظة العميل منفصلتان عن ميزانية المزود لكل المنصة. الحجوزات غير المؤكدة لا تُحذف أو تُصفّر قبل مراجعة حساب المزود. هذه الأرقام تُقرأ فقط ولا تعيد محاولة طلب مدفوع.</p>
                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🔗 Google OAuth</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">لازم يتفعّل "Google Business Profile API" على نفس مشروع Google Cloud ده (وصول رسمي من Google، مش مجرد تفعيل) عشان ربط نشاط العميل التجاري (منشورات، مراجعات) يشتغل.</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_google_client_id" class="form-label">Client ID</label><input type="text" id="ss_google_client_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_google_client_secret" class="form-label">Client Secret</label><input type="password" id="ss_google_client_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🗺️ Google Maps / Places</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">مفتاح منفصل عن Google OAuth فوق - لازم يفعّل عليه Maps JavaScript API و Places API و Geocoding API من نفس مشروع Google Cloud. قيّده بالـ APIs دي بس (API restrictions)، وسيب Application restrictions على None: السيرفر بيستخدمه كمان لـ Places وGeocoding، وقيد HTTP referrers بيخلّي جوجل يرفض طلبات السيرفر. نفس المفتاح ده بيشغّل خريطة اختيار موقع النشاط في صفحة منشورات GBP، وكمان بيُستخدم لاكتشاف منافسين حقيقيين عبر Google Places في ذكاء المنافسين.</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_google_maps_api_key" class="form-label">API Key</label><input type="password" id="ss_google_maps_api_key" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">📘 Meta (Facebook/Instagram/WhatsApp)</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_meta_app_id" class="form-label">App ID</label><input type="text" id="ss_meta_app_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_meta_app_secret" class="form-label">App Secret</label><input type="password" id="ss_meta_app_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                        <div class="form-group"><label for="ss_meta_login_config_id" class="form-label">Login for Business Config ID <span class="p-cell-muted">(ربط الإعلانات والصفحات)</span></label><input type="text" id="ss_meta_login_config_id" class="form-control" dir="ltr" inputmode="numeric"></div>
                        <div class="form-group"><label for="ss_meta_signin_config_id" class="form-label">Config ID للدخول بفيسبوك <span class="p-cell-muted">(اختياري)</span></label><input type="text" id="ss_meta_signin_config_id" class="form-control" dir="ltr" inputmode="numeric"></div>
                        <div class="form-group"><label for="ss_meta_wa_config_id" class="form-label">WhatsApp Embedded Signup Config ID <span class="p-cell-muted">(ربط واتساب بضغطة)</span></label><input type="text" id="ss_meta_wa_config_id" class="form-control" dir="ltr" inputmode="numeric"></div>
                        <div class="form-group"><label for="ss_meta_oauth_scopes" class="form-label">الصلاحيات <span class="p-cell-muted">(لو مش Login for Business - فاضي = الكل)</span></label><input type="text" id="ss_meta_oauth_scopes" class="form-control" dir="ltr" placeholder="pages_show_list,pages_manage_posts"></div>
                    </div>
                    {$metaSetupBox}

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">💬 ربط واتساب بمسح QR (للعملاء)</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">بوابة <b>Evolution API</b> (مفتوحة المصدر) على سيرفر عندك. كل عميل بيضغط "ربط واتساب" ويمسح الكود من موبايله - من غير أي مفاتيح. الربط ده غير رسمي من واتساب: للأرقام اللي بتبعت كميات كبيرة الأفضل الربط الرسمي (Embedded Signup فوق).</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label" for="ss_wa_qr_enabled">التفعيل للعملاء</label><select id="ss_wa_qr_enabled" class="form-control"><option value="0">متوقف</option><option value="1">مفعّل</option></select></div>
                        <div class="form-group"><label for="ss_wa_qr_base_url" class="form-label">رابط البوابة</label><input type="url" id="ss_wa_qr_base_url" class="form-control" dir="ltr" placeholder="https://wa.yourdomain.com"></div>
                        <div class="form-group"><label for="ss_wa_qr_api_key" class="form-label">المفتاح العام (AUTHENTICATION_API_KEY)</label><input type="password" id="ss_wa_qr_api_key" class="form-control" placeholder="••••••••" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">&nbsp;</label><button type="button" class="p-btn outline" onclick="testWaQrGateway(this)">🔌 اختبار الاتصال بالبوابة</button></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">💳 Stripe Connect (العملاء بيستلموا فلوس الحجوزات على حسابهم بتسجيل الدخول)</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">من Stripe ← Settings ← Connect ← Onboarding options ← OAuth: فعّل OAuth وضيف رابط الرجوع: <code dir="ltr">{$stripeRedirect}</code></p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_stripe_connect_client_id" class="form-label">Connect Client ID</label><input type="text" id="ss_stripe_connect_client_id" class="form-control" dir="ltr" placeholder="ca_..."></div>
                        <div class="form-group"><label for="ss_stripe_platform_secret_key" class="form-label">Secret key بتاع المنصة</label><input type="password" id="ss_stripe_platform_secret_key" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🎵 TikTok (ربط حسابات العملاء بتسجيل الدخول)</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">من TikTok for Developers ← تطبيقك. ضيف رابط الرجوع: <code dir="ltr">{$tiktokRedirect}</code></p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_tiktok_client_key" class="form-label">Client Key</label><input type="text" id="ss_tiktok_client_key" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_tiktok_client_secret" class="form-label">Client Secret</label><input type="password" id="ss_tiktok_client_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🔐 تسجيل الدخول الاجتماعي - Microsoft</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">Google وFacebook بيستخدموا نفس بيانات الاعتماد فوق (Google OAuth / Meta) - محتاجين بس تضيف رابط الـ Redirect الخاص بتسجيل الدخول في لوحة كل منصة.</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_oauth_microsoft_client_id" class="form-label">Client ID</label><input type="text" id="ss_oauth_microsoft_client_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_oauth_microsoft_client_secret" class="form-label">Client Secret</label><input type="password" id="ss_oauth_microsoft_client_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                        <div class="form-group"><label for="ss_oauth_microsoft_tenant" class="form-label">Tenant</label><input type="text" id="ss_oauth_microsoft_tenant" class="form-control" dir="ltr" placeholder="common"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);"> تسجيل الدخول الاجتماعي - Apple</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_oauth_apple_client_id" class="form-label">Services ID (client_id)</label><input type="text" id="ss_oauth_apple_client_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_oauth_apple_team_id" class="form-label">Team ID</label><input type="text" id="ss_oauth_apple_team_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_oauth_apple_key_id" class="form-label">Key ID</label><input type="text" id="ss_oauth_apple_key_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_oauth_apple_private_key" class="form-label">Private Key (.p8)</label><textarea id="ss_oauth_apple_private_key" class="form-control" dir="ltr" rows="4" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">💬 {$this->tr('admin.sys.cat.whatsapp')}</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_support_whatsapp_number" class="form-label">{$this->tr('admin.sys.support_number')}</label><input type="text" id="ss_support_whatsapp_number" class="form-control" dir="ltr" placeholder="201xxxxxxxxx"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">✉️ SMTP ({$this->tr('admin.sys.cat.mail')})</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label for="ss_mail_host" class="form-label">Host</label><input type="text" id="ss_mail_host" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_mail_port" class="form-label">Port</label><input type="text" id="ss_mail_port" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_mail_username" class="form-label">Username</label><input type="text" id="ss_mail_username" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_mail_password" class="form-label">Password</label><input type="password" id="ss_mail_password" class="form-control" placeholder="••••••••" dir="ltr"></div>
                        <div class="form-group"><label for="ss_mail_encryption" class="form-label">Encryption</label><input type="text" id="ss_mail_encryption" class="form-control" dir="ltr" placeholder="tls"></div>
                        <div class="form-group"><label for="ss_mail_from_address" class="form-label">From Address</label><input type="email" id="ss_mail_from_address" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label for="ss_mail_from_name" class="form-label">From Name</label><input type="text" id="ss_mail_from_name" class="form-control"></div>
                    </div>

                    <p class="p-cell-muted" style="font-size:11.5px;margin-top:14px;">{$this->tr('admin.sys.secret_hint')}</p>
                    <div id="sysSettingsAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="saveSystemSettings()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-card">
                <div class="p-card-head"><h3>❓ FAQ</h3></div>
                <div class="p-toolbar">
                    <button class="p-btn" onclick="openEditFaq(null)">+ {$this->tr('admin.faq.new')}</button>
                </div>
                <p class="p-cell-muted" style="margin-bottom:14px;">{$this->tr('admin.faq.hint')}</p>
                <div id="faqList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>
                </div>
                <script src="{$tabsJs}"></script>

                <div class="p-modal-overlay" id="editFaqModal">
                    <div class="p-modal">
                        <div class="p-modal-head"><h3 id="faqModalTitle">{$this->tr('admin.faq.new')}</h3><button class="p-modal-close" onclick="P.closeModal('editFaqModal')">×</button></div>
                        <div class="p-modal-body">
                            <input type="hidden" id="faqId">
                            <label for="faqQuestion" class="form-label">{$this->tr('admin.faq.question')}</label>
                            <input type="text" id="faqQuestion" class="form-control" style="margin-bottom:10px;">
                            <label for="faqAnswer" class="form-label">{$this->tr('admin.faq.answer')}</label>
                            <textarea id="faqAnswer" class="form-control" rows="4" style="margin-bottom:10px;"></textarea>
                            <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="faqIsActive" checked> {$this->tr('admin.plans.is_active')}</label>
                            <div id="faqAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                        </div>
                        <div class="p-modal-foot"><button class="p-btn primary" onclick="saveFaq()">{$this->tr('common.save')}</button></div>
                    </div>
                </div>
HTML;

            case 'platform':
                return <<<HTML
                <div class="p-grid cols-4" id="platKpis1">
                    <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value" id="platRevenue">$0</div><div class="stat-label">{$this->tr('admin.total_revenue')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon blue">⭐</div><div class="stat-info"><div class="stat-value" id="platReviews">0</div><div class="stat-label">{$this->tr('admin.total_reviews')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">🧾</div><div class="stat-info"><div class="stat-value" id="platDeals">0</div><div class="stat-label">{$this->tr('admin.crm_deals_all')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon orange">🕵️</div><div class="stat-info"><div class="stat-value" id="platCompetitors">0</div><div class="stat-label">{$this->tr('admin.tracked_competitors')}</div></div></div>
                </div>

                <div class="p-grid cols-4" style="margin-top:14px;">
                    <div class="p-card stat-tile"><div class="stat-icon blue">📣</div><div class="stat-info"><div class="stat-value" id="platAdSpend">$0</div><div class="stat-label">{$this->tr('admin.total_ad_spend')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">✍️</div><div class="stat-info"><div class="stat-value" id="platArticles">0</div><div class="stat-label">{$this->tr('admin.ai_articles_generated')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">💬</div><div class="stat-info"><div class="stat-value" id="platChats">0</div><div class="stat-label">{$this->tr('admin.chat_messages_30d')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon orange">🛠️</div><div class="stat-info"><div class="stat-value" id="platAudits">0</div><div class="stat-label">{$this->tr('admin.website_audits')}</div></div></div>
                </div>

                <div class="p-grid cols-2" style="margin-top:18px;">
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('admin.plan_distribution')}</h3></div>
                        <div id="planBreakdown"><div class="p-loading-row">{$this->tr('common.loading')}</div></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('admin.extra_metrics')}</h3></div>
                        <div id="extraMetrics" style="display:flex;flex-direction:column;gap:10px;">
                            <div class="p-loading-row">{$this->tr('common.loading')}</div>
                        </div>
                    </div>
                </div>

                <div class="p-card" style="margin-top:18px;">
                    <div class="p-card-head"><h3>{$this->tr('admin.note')}</h3></div>
                    <p style="color:var(--panel-text-muted);font-size:13px;line-height:1.8;margin:0;">
                        {$this->tr('admin.platform_note')}
                    </p>
                </div>
HTML;

            case 'integrations':
                return <<<HTML
                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>🔌 {$this->tr('admin.tab.integrations')}</h3><span class="p-card-sub">{$this->tr('admin.tab.integrations_sub')}</span></div>
                    <div id="integrationsSummary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;">
                        <div class="p-empty">{$this->tr('common.loading')}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
                        <input type="search" id="intFilter" name="int-filter-q" class="p-input" style="max-width:260px;" placeholder="ابحث بالاسم أو الفئة أو المفتاح..." oninput="renderIntegrationList()" autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other">
                        <style>.int-secret{-webkit-text-security:disc;text-security:disc;font-family:monospace}</style>
                        <select id="intCategory" class="p-input" style="max-width:190px;" onchange="renderIntegrationList()">
                            <option value="">كل الفئات</option>
                        </select>
                        <select id="intImportance" class="p-input" style="max-width:190px;" onchange="renderIntegrationList()" aria-label="الأهمية">
                            <option value="core" selected>الأساسية (لازم + مهم)</option>
                            <option value="">كل التكاملات</option>
                            <option value="optional">الاختيارية بس</option>
                        </select>
                        <select id="intStatus" class="p-input" style="max-width:170px;" onchange="renderIntegrationList()">
                            <option value="">كل الحالات</option>
                            <option value="configured">مفعلة</option>
                            <option value="missing">تحتاج إعداد</option>
                        </select>
                        <button class="p-btn outline xs" onclick="loadIntegrations()">↻ تحديث</button>
                    </div>
                    <div id="integrationsList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>

                <div id="intModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;">
                    <div class="p-card" style="max-width:560px;width:92%;max-height:86vh;overflow:auto;">
                        <div class="p-card-head" style="display:flex;justify-content:space-between;align-items:center;">
                            <h3 id="intModalTitle">—</h3>
                            <button class="p-btn outline xs" onclick="closeIntModal()">✕</button>
                        </div>
                        <p class="p-cell-muted" id="intModalStatus" style="margin-bottom:12px;"></p>
                        <div id="intModalKeys" style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px;"></div>
                        <div id="intModalAlert" class="alert alert-danger" style="display:none;margin-bottom:10px;"></div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="intSaveBtn" onclick="saveIntegrationKeys()">{$this->tr('admin.integrations.save_keys')}</button>
                            <button class="p-btn outline" id="intTestBtn" onclick="testIntegrationConnection()">{$this->tr('admin.integrations.test_connection')}</button>
                        </div>
                    </div>
                </div>
HTML;

            default:
                return <<<HTML
                <div id="proOverview"></div>
                <div id="legacyOverview" style="display:none;">
                <div class="p-grid cols-4">
                    <div class="p-card stat-tile"><div class="stat-icon blue">👥</div><div class="stat-info"><div class="stat-value" id="statUsers">0</div><div class="stat-label">{$this->tr('admin.total_users')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">💳</div><div class="stat-info"><div class="stat-value" id="statSubs">0</div><div class="stat-label">{$this->tr('admin.active_subs')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon orange">🌐</div><div class="stat-info"><div class="stat-value" id="statWebsites">0</div><div class="stat-label">{$this->tr('admin.registered_websites')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">🧭</div><div class="stat-info"><div class="stat-value" id="statVisitors">0</div><div class="stat-label">{$this->tr('admin.unique_visitors_30d')}</div></div></div>
                </div>
                <div class="p-grid cols-2" style="margin-top:18px;">
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('admin.visitors_growth')}</h3><span class="p-card-sub">{$this->tr('admin.last_30_days')}</span></div>
                        <div class="chart-wrap"><canvas id="visitorsChart"></canvas></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('admin.device_breakdown')}</h3></div>
                        <div class="chart-wrap"><canvas id="devicesChart"></canvas></div>
                    </div>
                </div>
                <div class="p-grid cols-2" style="margin-top:18px;">
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('admin.latest_users')}</h3><a href="/admin/users" class="p-card-sub">{$this->tr('admin.view_all')}</a></div>
                        <div class="p-table-scroll"><table class="p-table" id="latestUsersTable">
                            <thead><tr><th>{$this->tr('admin.col.company')}</th><th>{$this->tr('admin.col.email')}</th><th>{$this->tr('chat.col.status')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('admin.latest_subs')}</h3><a href="/admin/subscriptions" class="p-card-sub">{$this->tr('admin.view_all')}</a></div>
                        <div class="p-table-scroll"><table class="p-table" id="latestSubsTable">
                            <thead><tr><th>{$this->tr('admin.customer')}</th><th>{$this->tr('admin.plan')}</th><th>{$this->tr('chat.col.status')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                </div>
                </div>
HTML;
        }
    }
}
