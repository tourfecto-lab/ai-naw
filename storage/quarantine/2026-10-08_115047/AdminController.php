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

        if ($this->has('is_active')) {
            $user->setAttribute('status', ((int) $this->get('is_active')) ? 'active' : 'suspended');
        }

        foreach (['company_name', 'email', 'role'] as $field) {
            if ($this->has($field)) {
                // تصحيح أمني (2026-08-09 / Phase 9): كان أي admin عادي (مش
                // بس super_admin) يقدر يرفّع أي مستخدم - أو نفسه - لدور
                // admin أو super_admin عن طريق الـ endpoint ده من غير أي
                // تحقق، وده رفع صلاحيات مباشر (privilege escalation).
                // دلوقتي دوري admin/super_admin محصورين على super_admin بس.
                if ($field === 'role') {
                    $newRole = $this->get('role');
                    $currentActorRole = $this->user['role'] ?? 'user';
                    if (in_array($newRole, ['admin', 'super_admin'], true) && $currentActorRole !== 'super_admin') {
                        return $this->error('تعيين دور Admin/Super Admin متاح لـ Super Admin بس', 403);
                    }
                }
                $user->setAttribute($field, $this->get($field));
            }
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
            return $this->success(['settings' => $service->getAllForAdmin()]);
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
                'gemini_api_key', 'google_client_id', 'google_client_secret', 'google_maps_api_key',
                'meta_app_id', 'meta_app_secret', 'support_whatsapp_number',
                'mail_host', 'mail_port', 'mail_username', 'mail_password',
                'mail_encryption', 'mail_from_address', 'mail_from_name',
                'oauth_microsoft_client_id', 'oauth_microsoft_client_secret', 'oauth_microsoft_tenant',
                'oauth_apple_client_id', 'oauth_apple_team_id', 'oauth_apple_key_id', 'oauth_apple_private_key',
            ];
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

        if (!$user->delete()) {
            return $this->error('تعذر حذف المستخدم', 500);
        }

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

        // حماية: الأدمن ميقدرش يوقف حسابه ولا حساب Super Admin
        if ((int) $user->getAttribute('id') === (int) ($this->user['id'] ?? 0)) {
            return $this->error('لا يمكنك إيقاف حسابك الحالي', 403);
        }
        if ($user->getAttribute('role') === 'super_admin' && ($this->user['role'] ?? '') !== 'super_admin') {
            return $this->error('إيقاف Super Admin متاح لـ Super Admin فقط', 403);
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
                    ORDER BY s.id DESC LIMIT 200";
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

            $userId = (int) $matches[0]->getAttribute('id');

            $subscription = Subscription::createSubscription($userId, $planName, $planType);

            if (!$subscription) {
                return $this->error('تعذر تفعيل الاشتراك - تأكد إن العميل معندوش اشتراك نشط بالفعل', 500);
            }

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
            return $this->error('تعذر تفعيل الاشتراك: ' . $e->getMessage(), 500);
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
            return $this->success(['plans' => array_map(fn ($p) => $p->toArray(), $plans)]);
        } catch (Exception $e) {
            Logger::error('Admin listPlansAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الباقات', 500);
        }
    }

    /** PUT /api/admin/plans/{id} */
    public function updatePlan(array $params = []): array
    {
        try {
            $plan = (new SubscriptionPlan())->find((int) ($params['id'] ?? 0));
            if (!$plan) {
                return $this->error('الباقة غير موجودة', 404);
            }

            $fields = [
                'name', 'price_monthly', 'price_yearly', 'currency', 'currency_symbol',
                'ai_analysis', 'competitor_analysis', 'chat_credits', 'review_credits',
                'multiple_websites', 'whatsapp_bot', 'auto_pilot', 'advanced_analytics', 'is_active',
            ];
            foreach ($fields as $field) {
                if ($this->get($field) !== null) {
                    $plan->setAttribute($field, $this->get($field));
                }
            }
            $plan->save();

            $this->log('Admin Updated Plan', ['plan_id' => $plan->getAttribute('id'), 'plan_key' => $plan->getAttribute('plan_key')]);

            return $this->success(['plan' => $plan->toArray()], 'تم تحديث الباقة');
        } catch (Exception $e) {
            Logger::error('Admin updatePlan Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث الباقة', 500);
        }
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

            $totalVisits = $this->db->query(
                "SELECT COUNT(*) AS c FROM visitor_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            )[0]['c'] ?? 0;

            $uniqueVisitors = $this->db->query(
                "SELECT COUNT(DISTINCT visitor_id) AS c FROM visitor_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            )[0]['c'] ?? 0;

            $authenticatedVisits = $this->db->query(
                "SELECT COUNT(*) AS c FROM visitor_logs WHERE is_authenticated = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            )[0]['c'] ?? 0;

            $byDay = $this->db->query(
                "SELECT DATE(created_at) AS day, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS unique_visitors
                 FROM visitor_logs
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC"
            );

            $topPages = $this->db->query(
                "SELECT page_url, COUNT(*) AS visits
                 FROM visitor_logs
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY page_url
                 ORDER BY visits DESC
                 LIMIT 10"
            );

            $byCountry = $this->db->query(
                "SELECT COALESCE(country, 'غير معروف') AS country, COUNT(*) AS visits
                 FROM visitor_logs
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY country
                 ORDER BY visits DESC
                 LIMIT 10"
            );

            $byDevice = $this->db->query(
                "SELECT COALESCE(device_type, 'غير معروف') AS device_type, COUNT(*) AS visits
                 FROM visitor_logs
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
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
        ];
        $pageTitle = $titles[$tab][0] ?? $this->tr('admin.page_title');
        $pageSubtitle = $titles[$tab][1] ?? '';

        $navHtml = $this->renderAdminSidebar($tab);
        $panelBody = $this->renderAdminPanelBody($tab);

        $script = <<<'JS'
(function () {
    const tab = "__TAB__";
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, timeAgo = P.timeAgo, formatDate = P.formatDate, initials = P.initials, statusPill = P.statusPill;
    let usersCache = [];
    let charts = {};

    function destroyChart(key) {
        if (charts[key]) { charts[key].destroy(); delete charts[key]; }
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
                charts.visitors = new Chart(ctx, {
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
                charts.devices = new Chart(dctx, {
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
        const roleOptions = ['user', 'agency_owner', 'admin', 'super_admin'];

        document.getElementById('userModalTitle').textContent = u.company_name || I18N['admin.user_details'];
        document.getElementById('userModalBody').innerHTML = `
            <div class="p-kv"><span class="k">${I18N['settings.email']}</span><span class="v">${esc(u.email)}</span></div>
            <div class="p-kv">
                <span class="k">${I18N['settings.role']}</span>
                <span class="v">
                    <select id="userRoleSelect" class="p-select xs">
                        ${roleOptions.map(r => `<option value="${r}" ${r === u.role ? 'selected' : ''}>${roleLabel(r).replace(/<[^>]+>/g, '')}</option>`).join('')}
                    </select>
                    <button class="p-btn outline xs" onclick="saveUserRole(${u.id})" style="margin-inline-start:6px;">${I18N['common.save']}</button>
                </span>
            </div>
            <div class="p-kv"><span class="k">${I18N['chat.col.status']}</span><span class="v">${statusPill(u.is_active)}</span></div>
            <div class="p-kv"><span class="k">${I18N['admin.registration_date']}</span><span class="v">${formatDate(u.created_at)}</span></div>
            <div class="p-kv"><span class="k">${I18N['subscription.page_title']}</span><span class="v">${subHtml}</span></div>
            <div class="p-kv"><span class="k">💰 ${I18N['wallet.title']}</span><span class="v" style="font-weight:700;" dir="ltr">$${Number(walletBalance).toFixed(2)}</span></div>
            <div style="display:flex;gap:6px;margin-bottom:12px;">
                <input type="number" id="addBalanceAmount" class="p-select xs" style="flex:1;" placeholder="${I18N['admin.wallet.amount_placeholder']}" min="0.01" step="0.01">
                <button class="p-btn success xs" onclick="addUserBalance(${u.id})">+ ${I18N['admin.wallet.add_balance']}</button>
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

    window.addUserBalance = async function (userId) {
        const amount = parseFloat(document.getElementById('addBalanceAmount').value);
        if (!amount || amount <= 0) { toast(I18N['admin.wallet.enter_valid_amount'], 'error'); return; }
        if (!confirm(I18N['admin.wallet.add_balance_confirm'].replace('{amount}', amount))) return;

        const res = await fetchJSON('/api/admin/users/' + userId + '/add-balance', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ amount }),
        });
        if (res.success) { toast(I18N['admin.wallet.balance_added'], 'success'); viewUser(userId); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

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
                } else {
                    const el = document.getElementById('ss_' + item.key);
                    if (el && !item.is_secret) el.value = item.value || '';
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
            'gemini_api_key', 'google_client_id', 'google_client_secret', 'google_maps_api_key',
            'meta_app_id', 'meta_app_secret', 'support_whatsapp_number',
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
            ['gemini_api_key', 'google_client_secret', 'google_maps_api_key', 'meta_app_secret', 'mail_password', 'oauth_microsoft_client_secret', 'oauth_apple_private_key'].forEach(key => {
                const el = document.getElementById('ss_' + key);
                if (el) el.value = '';
            });
            loadSystemSettings();
        } else {
            alertBox.textContent = res.error || I18N['common.update_failed'];
            alertBox.style.display = 'block';
        }
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

    async function loadSubscriptions() {
        const res = await fetchJSON('/api/admin/subscriptions');
        const tbody = document.querySelector('#subsTable tbody');
        if (!res.success || !Array.isArray(res.data.subscriptions)) {
            tbody.innerHTML = '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.load_subs_failed'] + '</td></tr>';
            return;
        }
        const subs = res.data.subscriptions;
        tbody.innerHTML = subs.length ? subs.map(s => `
            <tr>
                <td>#${esc(s.id)}</td>
                <td><strong>${esc(s.company_name || s.email)}</strong>${s.company_name ? `<br><span class="p-cell-muted">${esc(s.email)}</span>` : ''}</td>
                <td>${esc(s.plan_display_name || s.plan || s.plan_name || '-')}</td>
                <td><span class="pill ${s.status === 'active' ? 'green' : (s.status === 'cancelled' ? 'red' : 'gray')}">${esc(s.status)}</span></td>
                <td class="p-cell-muted" data-sort="${esc(s.created_at || '')}">${formatDate(s.created_at)}</td>
                <td>${s.status === 'active' ? `<button class="p-btn danger xs" onclick="cancelSub(${s.id}, this)">${I18N['admin.cancel']}</button>` : ''}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="p-cell-muted text-center">' + I18N['admin.no_subs'] + '</td></tr>';
    }

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
                charts.vtrend = new Chart(ctx, {
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
            charts.ofTrend = new Chart(ctx, {
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
        if (!plansCache.length) {
            grid.innerHTML = `<div class="p-empty">${I18N['admin.plans.none']}</div>`;
            return;
        }
        grid.innerHTML = plansCache.map(p => `
            <div class="p-card${p.is_active == 0 ? ' orbit-glow' : ''}" style="${p.is_active == 0 ? 'opacity:.55;' : ''}">
                <div class="p-card-head"><h3>${esc(p.name)}</h3><span class="p-card-sub">${p.is_active == 1 ? I18N['admin.plans.active'] : I18N['admin.plans.inactive']}</span></div>
                <div style="font-size:24px;font-weight:700;margin:8px 0;">${esc(p.currency_symbol)}${esc(p.price_monthly)}<span style="font-size:12px;font-weight:400;">/${I18N['admin.monthly']}</span></div>
                <div class="p-cell-muted" style="font-size:12.5px;margin-bottom:10px;">${esc(p.currency_symbol)}${esc(p.price_yearly)}/${I18N['admin.yearly']}</div>
                <div class="p-kv"><span class="k">${I18N['admin.plans.ai_analysis']}</span><span class="v">${esc(p.ai_analysis)}</span></div>
                <div class="p-kv"><span class="k">${I18N['admin.plans.chat_credits']}</span><span class="v">${esc(p.chat_credits)}</span></div>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="openEditPlan(${p.id})">✏️ ${I18N['common.edit']}</button>
            </div>
        `).join('');
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
        };

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
                    <div class="p-cell-muted" style="font-size:11.5px;">${esc(d.payment_method === 'iban' ? 'IBAN' : 'PayPal')} · ${esc(d.reference_note || '')}</div>
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

    async function loadWalletSettings() {
        const res = await fetchJSON('/api/admin/wallet/settings');
        if (res.success) {
            const s = res.data.settings || {};
            document.getElementById('wsIban').value = s.iban || '';
            document.getElementById('wsIbanBank').value = s.iban_bank_name || '';
            document.getElementById('wsIbanAccount').value = s.iban_account_name || '';
            document.getElementById('wsPaypal').value = s.paypal_email || '';
            document.getElementById('wsWhatsapp').value = s.whatsapp_number || '';
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
                        <input type="checkbox" ${p.is_active == 1 ? 'checked' : ''} onchange="toggleUsagePricing(${p.id}, this.checked, ${p.price})"> ${I18N['admin.plans.is_active']}
                    </label>
                    <span dir="ltr" style="display:flex;align-items:center;gap:4px;">
                        $<input type="number" step="0.01" value="${esc(p.price)}" id="price-${p.id}" class="p-select xs" style="width:70px;">
                    </span>
                    <button class="p-btn outline xs" onclick="saveUsagePricing(${p.id})">${I18N['common.save']}</button>
                </span>
            </div>`).join('');
    }

    window.saveUsagePricing = async function (id) {
        const priceInput = document.getElementById('price-' + id);
        const price = parseFloat(priceInput.value);
        const res = await fetchJSON('/api/admin/wallet/usage-pricing/' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ price, is_active: 1 }),
        });
        if (res.success) toast(I18N['common.updated'], 'success');
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    window.toggleUsagePricing = async function (id, isActive, price) {
        const res = await fetchJSON('/api/admin/wallet/usage-pricing/' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ price, is_active: isActive ? 1 : 0 }),
        });
        if (res.success) toast(I18N['common.updated'], 'success');
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

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

    function integrationHint(i) {
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
        const filtered = intCache.filter(i => {
            const text = [(i.label || ''), (i.key || ''), (i.category || ''), (i.auth_type || ''), (i.env_keys || []).join(' ')].join(' ').toLowerCase();
            if (q && !text.includes(q)) return false;
            if (cat && i.category !== cat) return false;
            if (status === 'configured' && !i.configured) return false;
            if (status === 'missing' && i.configured) return false;
            return true;
        });

        if (!filtered.length) {
            box.innerHTML = '<div class="p-empty">' + I18N['admin.integrations.no_integrations'] + '</div>';
            return;
        }

        box.innerHTML = filtered.map(i => `
            <div style="padding:14px 0;border-bottom:1px solid var(--panel-border);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:start;">
                <div style="min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px;">
                        <strong style="font-size:14px;">${esc(i.label || i.key)}</strong>
                        <span class="pill gray">${esc(i.category || 'general')}</span>
                        <span class="pill gray">${esc(i.auth_type || 'api_key')}</span>
                        ${i.configured
                            ? '<span class="pill green">' + I18N['admin.integrations.configured'] + '</span>'
                            : '<span class="pill gray">' + I18N['admin.integrations.not_configured'] + '</span>'}
                    </div>
                    <div class="p-cell-muted" style="font-size:12px;line-height:1.7;">${esc(integrationHint(i))}</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
                        ${(i.env_keys || []).map(k => `<code style="font-size:11px;padding:3px 6px;border:1px solid var(--panel-border);border-radius:7px;direction:ltr;">${esc(k)}</code>`).join('')}
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
        document.getElementById('intModalKeys').innerHTML = (meta.env_keys || []).map(envName => `
            <div class="form-group">
                <label class="form-label" style="font-family:monospace;font-size:12px;direction:ltr;text-align:right;">${esc(envName)}</label>
                <input type="${envName.endsWith('_ENABLED') ? 'text' : 'password'}" class="form-control" data-env-key="${esc(envName)}" placeholder="${envName.endsWith('_ENABLED') ? 'true أو false' : I18N['admin.integrations.secret_placeholder']}" dir="ltr" autocomplete="off">
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
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ values }),
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
            const res = await fetchJSON('/api/integrations/' + intCurrentKey + '/test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
            toast(res.success ? I18N['admin.integrations.test_ok'] : (res.error || I18N['admin.integrations.test_fail']), res.success ? 'success' : 'error');
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
            else if (tab === 'plans') { await loadPlans(); await loadWalletStats(); await loadCards(); await loadPendingDeposits(); await loadWalletSettings(); await loadUsagePricing(); }
            else if (tab === 'system') await loadSystem();
            else if (tab === 'platform') await loadPlatform();
            else if (tab === 'settings') { await loadSystemSettings(); await loadFeaturesList(); await loadFaqList(); }
            else if (tab === 'integrations') await loadIntegrations();
            else if (['team', 'audit', 'ai-usage', 'jobs', 'security'].indexOf(tab) !== -1 && window.AdminPro) await AdminPro.renderPage(tab);
        } catch (e) {
            toast(I18N['admin.load_data_error'], 'error');
        } finally {
            document.getElementById('loadingMsg').style.display = 'none';
            document.getElementById('adminContent').style.display = 'block';
        }
    }
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
    <script>window.I18N = {$this->i18nJson()}; window.ADMIN_PERMS = {$adminPermsJson};</script>
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
    private function renderAdminSidebar(string $activeTab): string
    {
        $groups = [
            $this->tr('admin.nav.general') => [
                'overview' => [$this->tr('admin.nav.overview'), 'bar-chart-2', '/admin'],
                'platform' => [$this->tr('admin.nav.platform_overview'), 'compass', '/admin/platform'],
            ],
            $this->tr('admin.nav.customers') => [
                'users' => [$this->tr('admin.nav.users'), 'users', '/admin/users'],
                'subscriptions' => [$this->tr('admin.nav.subscriptions'), 'credit-card', '/admin/subscriptions'],
                'contact-messages' => [$this->tr('admin.nav.contact_messages'), 'mail', '/admin/contact-messages'],
                'plans' => [$this->tr('admin.nav.plans'), 'dollar', '/admin/plans'],
            ],
            $this->tr('admin.nav.security_tracking') => [
                'visitors' => [$this->tr('admin.nav.visitors'), 'eye', '/admin/visitors'],
                'login-history' => [$this->tr('admin.nav.login_history'), 'lock', '/admin/login-history'],
                'security' => [$this->tr('admin.nav.security'), 'alarm', '/admin/security'],
                'audit' => [$this->tr('admin.nav.audit'), 'clock', '/admin/audit'],
                'onboarding-funnel' => [$this->tr('admin.nav.onboarding_funnel'), 'flask', '/admin/onboarding-funnel'],
            ],
            $this->tr('admin.nav.system') => [
                'system' => [$this->tr('admin.nav.system_status'), 'monitor', '/admin/system'],
                'logs' => [$this->tr('admin.nav.logs'), 'file-text', '/admin/logs'],
                'jobs' => [$this->tr('admin.nav.jobs'), 'activity', '/admin/jobs'],
                'ai-usage' => [$this->tr('admin.nav.ai_usage'), 'brain', '/admin/ai-usage'],
                'team' => [$this->tr('admin.nav.team'), 'key', '/admin/team'],
                'settings' => [$this->tr('admin.nav.settings'), 'settings', '/admin/settings'],
                'integrations' => [$this->tr('admin.nav.integrations'), 'plug', '/admin/integrations'],
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
    private function renderAdminPanelBody(string $tab): string
    {
        switch ($tab) {
            case 'team':
            case 'audit':
            case 'ai-usage':
            case 'jobs':
            case 'security':
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
                            <label class="form-label">{$this->tr('admin.broadcast.audience')}</label>
                            <select id="broadcastAudience" class="form-control" style="margin-bottom:10px;">
                                <option value="all">{$this->tr('admin.broadcast.audience.all')}</option>
                                <option value="active_subscribers">{$this->tr('admin.broadcast.audience.subscribers')}</option>
                                <option value="no_subscription">{$this->tr('admin.broadcast.audience.no_sub')}</option>
                            </select>
                            <label class="form-label">{$this->tr('admin.broadcast.msg_title')}</label>
                            <input type="text" id="broadcastTitle" class="form-control" style="margin-bottom:10px;" maxlength="100">
                            <label class="form-label">{$this->tr('admin.broadcast.msg_body')}</label>
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
                <div class="p-card no-pad" style="margin-top:16px;">
                    <div class="p-table-scroll">
                        <table class="p-table" id="subsTable">
                            <thead><tr><th>#</th><th>{$this->tr('admin.customer')}</th><th>{$this->tr('admin.plan')}</th><th>{$this->tr('chat.col.status')}</th><th>{$this->tr('admin.created_date')}</th><th>{$this->tr('admin.col.actions')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="6">{$this->tr('common.loading')}</td></tr></tbody>
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
                return <<<HTML
                <p class="p-cell-muted" style="margin-bottom:16px;">{$this->tr('admin.plans.hint')}</p>
                <div id="plansGrid" class="p-grid cols-3"><div class="p-empty">{$this->tr('common.loading')}</div></div>

                <div class="p-modal-overlay" id="editPlanModal">
                    <div class="p-modal">
                        <div class="p-modal-head"><h3>{$this->tr('admin.plans.edit_title')}</h3><button class="p-modal-close" onclick="P.closeModal('editPlanModal')">×</button></div>
                        <div class="p-modal-body">
                            <input type="hidden" id="planId">
                            <label class="form-label">{$this->tr('admin.plans.name')}</label>
                            <input type="text" id="planName" class="form-control" style="margin-bottom:10px;">
                            <div class="p-grid cols-2">
                                <div>
                                    <label class="form-label">{$this->tr('admin.plans.price_monthly')}</label>
                                    <input type="number" step="0.01" id="planPriceMonthly" class="form-control" style="margin-bottom:10px;">
                                </div>
                                <div>
                                    <label class="form-label">{$this->tr('admin.plans.price_yearly')}</label>
                                    <input type="number" step="0.01" id="planPriceYearly" class="form-control" style="margin-bottom:10px;">
                                </div>
                            </div>
                            <div class="p-grid cols-2">
                                <div>
                                    <label class="form-label">{$this->tr('admin.plans.currency')}</label>
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
                                    <label class="form-label">{$this->tr('admin.plans.currency_symbol')}</label>
                                    <input type="text" id="planCurrencySymbol" class="form-control" style="margin-bottom:10px;" maxlength="10">
                                </div>
                            </div>
                            <hr style="border-color:var(--panel-border);margin:14px 0;">
                            <p class="p-cell-muted" style="margin-bottom:10px;">{$this->tr('admin.plans.limits_hint')}</p>
                            <div class="p-grid cols-2">
                                <div><label class="form-label">{$this->tr('admin.plans.ai_analysis')}</label><input type="number" id="planAiAnalysis" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label class="form-label">{$this->tr('admin.plans.competitor_analysis')}</label><input type="number" id="planCompetitorAnalysis" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label class="form-label">{$this->tr('admin.plans.chat_credits')}</label><input type="number" id="planChatCredits" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label class="form-label">{$this->tr('admin.plans.review_credits')}</label><input type="number" id="planReviewCredits" class="form-control" style="margin-bottom:10px;"></div>
                                <div><label class="form-label">{$this->tr('admin.plans.multiple_websites')}</label><input type="number" id="planMultipleWebsites" class="form-control" style="margin-bottom:10px;"></div>
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
                <div class="p-card" style="margin-top:12px;">
                    <div class="p-card-head"><h3>📈 تطوّر MRR</h3><span class="p-card-sub" id="mrrTrendSub">آخر 30 يوم - بيتسجّل تلقائيًا كل يوم تفتح فيه الصفحة دي</span></div>
                    <div id="mrrTrendChart" style="padding:10px 0;"></div>
                </div>

                <div class="p-card" style="margin-top:16px;">
                    <div class="p-card-head"><h3>🎫 {$this->tr('admin.cards.title')}</h3><span class="p-card-sub">{$this->tr('admin.cards.subtitle')}</span></div>
                    <div class="p-grid cols-3">
                        <div class="form-group"><label class="form-label">{$this->tr('admin.cards.count')}</label><input type="number" id="cardsCount" class="form-control" min="1" max="500" value="10"></div>
                        <div class="form-group"><label class="form-label">{$this->tr('admin.cards.value')}</label><input type="number" id="cardsValue" class="form-control" min="0.01" step="0.01" value="10"></div>
                        <div class="form-group"><label class="form-label">{$this->tr('admin.cards.batch_label')}</label><input type="text" id="cardsBatchLabel" class="form-control" placeholder="{$this->tr('admin.cards.batch_placeholder')}"></div>
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
                            <label class="form-label">IBAN</label>
                            <input type="text" id="wsIban" class="form-control" style="margin-bottom:10px;" dir="ltr">
                            <label class="form-label">{$this->tr('wallet.bank_name')}</label>
                            <input type="text" id="wsIbanBank" class="form-control" style="margin-bottom:10px;">
                            <label class="form-label">{$this->tr('wallet.account_name')}</label>
                            <input type="text" id="wsIbanAccount" class="form-control" style="margin-bottom:10px;">
                        </div>
                        <div>
                            <label class="form-label">PayPal Email</label>
                            <input type="email" id="wsPaypal" class="form-control" style="margin-bottom:10px;" dir="ltr">
                            <label class="form-label">{$this->tr('wallet.admin.whatsapp_number')}</label>
                            <input type="text" id="wsWhatsapp" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="201xxxxxxxxx">
                        </div>
                    </div>
                    <div id="walletSettingsAlert" class="alert alert-danger" style="display:none;"></div>
                    <button class="p-btn primary" onclick="saveWalletSettings()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-card" style="margin-top:16px;">
                    <div class="p-card-head"><h3>🎯 {$this->tr('wallet.admin.usage_pricing_title')}</h3><span class="p-card-sub">{$this->tr('wallet.admin.usage_pricing_sub')}</span></div>
                    <div id="usagePricingList"><div class="p-empty">{$this->tr('common.loading')}</div></div>
                </div>
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
                return <<<HTML
                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>🎨 {$this->tr('admin.brand.title')}</h3><span class="p-card-sub">{$this->tr('admin.brand.subtitle')}</span></div>

                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">{$this->tr('admin.brand.site_name')}</label><input type="text" id="br_site_name" class="form-control"></div>
                        <div class="form-group"><label class="form-label">{$this->tr('admin.brand.logo_height')}</label><input type="number" id="br_site_logo_height" class="form-control" min="16" max="120"></div>
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
                        <div class="form-group"><label class="form-label">{$this->tr('admin.brand.phone')}</label><input type="text" id="br_contact_phone" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">{$this->tr('admin.brand.email')}</label><input type="email" id="br_contact_email" class="form-control" dir="ltr"></div>
                    </div>
                    <div class="form-group"><label class="form-label">{$this->tr('admin.brand.address')}</label><input type="text" id="br_site_address" class="form-control"></div>

                    <div id="brandAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="saveBrandSettings()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-card" style="margin-bottom:20px;">
                    <div class="p-card-head"><h3>📜 {$this->tr('admin.legal.title')}</h3><span class="p-card-sub">{$this->tr('admin.legal.subtitle')}</span></div>
                    <label class="form-label">{$this->tr('admin.legal.terms')}</label>
                    <textarea id="legalTermsContent" class="form-control" rows="8" style="font-family:monospace;font-size:12px;margin-bottom:14px;" dir="ltr"></textarea>
                    <label class="form-label">{$this->tr('admin.legal.privacy')}</label>
                    <textarea id="legalPrivacyContent" class="form-control" rows="8" style="font-family:monospace;font-size:12px;margin-bottom:10px;" dir="ltr"></textarea>
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
                        <div class="form-group"><label class="form-label">Gemini API Key</label><input type="password" id="ss_gemini_api_key" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🔗 Google OAuth</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">لازم يتفعّل "Google Business Profile API" على نفس مشروع Google Cloud ده (وصول رسمي من Google، مش مجرد تفعيل) عشان ربط نشاط العميل التجاري (منشورات، مراجعات) يشتغل.</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">Client ID</label><input type="text" id="ss_google_client_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Client Secret</label><input type="password" id="ss_google_client_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🗺️ Google Maps / Places</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">مفتاح منفصل عن Google OAuth فوق - لازم يفعّل عليه Maps JavaScript API و Places API و Geocoding API من نفس مشروع Google Cloud، وتقيّده بـ HTTP referrers (دومين موقعك) من إعدادات المفتاح في Google Cloud Console. نفس المفتاح ده بيشغّل خريطة اختيار موقع النشاط في صفحة منشورات GBP، وكمان بيُستخدم لاكتشاف منافسين حقيقيين عبر Google Places في ذكاء المنافسين.</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">API Key</label><input type="password" id="ss_google_maps_api_key" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">📘 Meta (Facebook/Instagram)</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">App ID</label><input type="text" id="ss_meta_app_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">App Secret</label><input type="password" id="ss_meta_app_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">🔐 تسجيل الدخول الاجتماعي - Microsoft</h4>
                    <p class="p-cell-muted" style="font-size:11.5px;margin-bottom:8px;">Google وFacebook بيستخدموا نفس بيانات الاعتماد فوق (Google OAuth / Meta) - محتاجين بس تضيف رابط الـ Redirect الخاص بتسجيل الدخول في لوحة كل منصة.</p>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">Client ID</label><input type="text" id="ss_oauth_microsoft_client_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Client Secret</label><input type="password" id="ss_oauth_microsoft_client_secret" class="form-control" placeholder="••••••••" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Tenant</label><input type="text" id="ss_oauth_microsoft_tenant" class="form-control" dir="ltr" placeholder="common"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);"> تسجيل الدخول الاجتماعي - Apple</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">Services ID (client_id)</label><input type="text" id="ss_oauth_apple_client_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Team ID</label><input type="text" id="ss_oauth_apple_team_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Key ID</label><input type="text" id="ss_oauth_apple_key_id" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Private Key (.p8)</label><textarea id="ss_oauth_apple_private_key" class="form-control" dir="ltr" rows="4" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">💬 {$this->tr('admin.sys.cat.whatsapp')}</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">{$this->tr('admin.sys.support_number')}</label><input type="text" id="ss_support_whatsapp_number" class="form-control" dir="ltr" placeholder="201xxxxxxxxx"></div>
                    </div>

                    <h4 style="margin:18px 0 8px;font-size:13px;color:var(--panel-text-muted);">✉️ SMTP ({$this->tr('admin.sys.cat.mail')})</h4>
                    <div class="p-grid cols-2">
                        <div class="form-group"><label class="form-label">Host</label><input type="text" id="ss_mail_host" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Port</label><input type="text" id="ss_mail_port" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Username</label><input type="text" id="ss_mail_username" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Password</label><input type="password" id="ss_mail_password" class="form-control" placeholder="••••••••" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">Encryption</label><input type="text" id="ss_mail_encryption" class="form-control" dir="ltr" placeholder="tls"></div>
                        <div class="form-group"><label class="form-label">From Address</label><input type="email" id="ss_mail_from_address" class="form-control" dir="ltr"></div>
                        <div class="form-group"><label class="form-label">From Name</label><input type="text" id="ss_mail_from_name" class="form-control"></div>
                    </div>

                    <p class="p-cell-muted" style="font-size:11.5px;margin-top:14px;">{$this->tr('admin.sys.secret_hint')}</p>
                    <div id="sysSettingsAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="saveSystemSettings()">{$this->tr('common.save')}</button>
                </div>

                <div class="p-toolbar">
                    <button class="p-btn" onclick="openEditFaq(null)">+ {$this->tr('admin.faq.new')}</button>
                </div>
                <p class="p-cell-muted" style="margin-bottom:14px;">{$this->tr('admin.faq.hint')}</p>
                <div id="faqList"><div class="p-empty">{$this->tr('common.loading')}</div></div>

                <div class="p-modal-overlay" id="editFaqModal">
                    <div class="p-modal">
                        <div class="p-modal-head"><h3 id="faqModalTitle">{$this->tr('admin.faq.new')}</h3><button class="p-modal-close" onclick="P.closeModal('editFaqModal')">×</button></div>
                        <div class="p-modal-body">
                            <input type="hidden" id="faqId">
                            <label class="form-label">{$this->tr('admin.faq.question')}</label>
                            <input type="text" id="faqQuestion" class="form-control" style="margin-bottom:10px;">
                            <label class="form-label">{$this->tr('admin.faq.answer')}</label>
                            <textarea id="faqAnswer" class="form-control" rows="4" style="margin-bottom:10px;"></textarea>
                            <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="faqIsActive" checked> {$this->tr('admin.plans.is_active')}</label>
                            <div id="faqAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                        </div>
                        <div class="p-modal-foot"><button class="p-btn primary" onclick="saveFaq()">{$this->tr('common.save')}</button></div>
                    </div>
                </div>
HTML;

            case 'platform':
                return <<<'HTML'
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
                        <input type="text" id="intFilter" class="p-input" style="max-width:260px;" placeholder="ابحث بالاسم أو الفئة أو المفتاح..." oninput="renderIntegrationList()">
                        <select id="intCategory" class="p-input" style="max-width:190px;" onchange="renderIntegrationList()">
                            <option value="">كل الفئات</option>
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
