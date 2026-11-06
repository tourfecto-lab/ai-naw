<?php

/**
 * Tourfecto - Admin Access (RBAC)
 *
 * نظام صلاحيات الأدمن. الدخول لـ /admin لسه بيعتمد على users.role = admin/super_admin
 * (AdminMiddleware)، وده الكلاس بيحدد "إيه اللي مسموح جوّه" حسب دور الموظف:
 *
 *   owner    كل حاجة (super_admin دايمًا owner)
 *   admin    كل حاجة ما عدا إدارة الفريق (الافتراضي لأي admin من غير سجل في admin_staff
 *            = مفيش حد بيتقفل برا بعد التحديث)
 *   finance  الفواتير والمحفظة والاشتراكات (قراءة/تنفيذ) + قراءة العملاء
 *   support  العملاء والرسائل والأمان (بدون فلوس وبدون إعدادات)
 *   analyst  قراءة فقط
 *
 * بالإضافة لـ extra_permissions (JSON) لكل موظف تتضاف فوق دوره.
 * أي مسار /api/admin/* غير معرّف: GET يحتاج analytics.view، وأي تعديل يحتاج system.manage
 * (يعني الـ admin/owner بس) — يعني الافتراضي رفض مش سماح.
 *
 * كل طلب تعديل (POST/PUT/DELETE) على /api/admin/* بيتسجّل تلقائيًا في audit_logs.
 *
 * @version 1.0.0
 */
class AdminAccess
{
    /** كل الصلاحيات: key => [مجموعة, وصف عربي] */
    public const CATALOG = [
        'analytics.view'     => ['analytics', 'عرض الإحصائيات ولوحة النظرة العامة'],
        'users.view'         => ['users', 'عرض العملاء'],
        'users.edit'         => ['users', 'تعديل بيانات العملاء'],
        'users.suspend'      => ['users', 'إيقاف وتفعيل العملاء'],
        'users.delete'       => ['users', 'حذف العملاء'],
        'users.impersonate'  => ['users', 'الدخول بحساب العميل'],
        'users.export'       => ['users', 'تصدير قائمة العملاء'],
        'users.notes'        => ['users', 'ملاحظات داخلية على العميل'],
        'users.reset_security' => ['users', 'إعادة ضبط 2FA وإنهاء الجلسات'],
        'subscriptions.view'   => ['billing', 'عرض الاشتراكات'],
        'subscriptions.manage' => ['billing', 'إدارة الاشتراكات (إلغاء/إنشاء)'],
        'plans.manage'       => ['billing', 'تعديل الباقات والأسعار'],
        'billing.view'       => ['billing', 'عرض المحفظة والإيرادات'],
        'billing.manage'     => ['billing', 'اعتماد الإيداعات والاسترداد وإضافة رصيد وكروت الشحن'],
        'billing.settings'   => ['billing', 'إعدادات الدفع والضرائب وتسعير الاستخدام'],
        'messages.view'      => ['support', 'عرض رسائل التواصل'],
        'messages.manage'    => ['support', 'معالجة رسائل التواصل'],
        'tickets.view'       => ['support', 'عرض تذاكر الدعم'],
        'tickets.manage'     => ['support', 'الرد على تذاكر الدعم وإسنادها وإقفالها'],
        'security.view'      => ['security', 'مركز الأمان وسجل الدخول'],
        'security.manage'    => ['security', 'فك الحظر وإنهاء الجلسات'],
        'system.view'        => ['system', 'حالة النظام والسجلات والمهام'],
        'system.manage'      => ['system', 'إدارة النظام (الكاش/المهام)'],
        'settings.manage'    => ['system', 'الإعدادات والتكاملات والهوية'],
        'broadcast.send'     => ['system', 'إرسال إشعار جماعي'],
        'ai_usage.view'      => ['system', 'مركز استهلاك الذكاء الاصطناعي'],
        'audit.view'         => ['security', 'عرض سجل التدقيق'],
        'team.view'          => ['team', 'عرض فريق الأدمن'],
        'team.manage'        => ['team', 'إدارة فريق الأدمن وأدوارهم'],
        'outreach.desk'      => ['sales', 'مكتب التواصل: شركات كل دولة وأرقامها والرسايل الجاهزة للنسخ'],
    ];

    /** الأدوار المعروضة (الترتيب = الأقوى أولاً) */
    public const ROLE_LABELS = [
        'owner'   => 'مالك (كل الصلاحيات)',
        'admin'   => 'مدير',
        'finance' => 'مالية',
        'support' => 'دعم',
        'support_lead' => 'مشرف دعم',
        'analyst' => 'محلل (قراءة فقط)',
        'sales'   => 'مبيعات (مكتب التواصل فقط)',
    ];

    private const ROLE_PERMS = [
        'finance' => [
            'analytics.view', 'users.view', 'subscriptions.view', 'subscriptions.manage',
            'billing.view', 'billing.manage', 'messages.view', 'audit.view', 'ai_usage.view', 'team.view',
        ],
        'support' => [
            'analytics.view', 'users.view', 'users.edit', 'users.notes', 'users.reset_security',
            'subscriptions.view', 'billing.view', 'messages.view', 'messages.manage', 'security.view', 'team.view',
            'tickets.view', 'tickets.manage',
        ],
        // مشرف الدعم: كل صلاحيات الدعم + إيقاف/تفعيل العملاء وتصدير قائمتهم + فك الحظر + متابعة سجل نشاط الفريق.
        // مفيش فلوس (billing.manage) ولا إعدادات ولا حذف ولا دخول بحساب العميل (ده بيتدّي كصلاحية إضافية بس).
        'support_lead' => [
            'analytics.view', 'users.view', 'users.edit', 'users.notes', 'users.reset_security', 'users.suspend', 'users.export',
            'subscriptions.view', 'billing.view', 'messages.view', 'messages.manage', 'security.view', 'security.manage',
            'audit.view', 'team.view', 'tickets.view', 'tickets.manage',
        ],
        'analyst' => [
            'analytics.view', 'users.view', 'subscriptions.view', 'billing.view', 'messages.view', 'tickets.view',
            'security.view', 'system.view', 'ai_usage.view', 'audit.view', 'team.view',
        ],
        // موظفة التواصل: مكتب التواصل بس (مفيش عملاء ولا فلوس ولا إعدادات)
        'sales' => ['outreach.desk'],
    ];

    /** @var array<int, array> كاش لكل طلب */
    private static $cache = [];

    // ---------------------------------------------------------------- الأدوار والصلاحيات

    public static function validRole(string $role): bool
    {
        return isset(self::ROLE_LABELS[$role]);
    }

    /** @return string[] صلاحيات دور معيّن (بدون extra) */
    public static function rolePermissions(string $role): array
    {
        if ($role === 'owner') {
            return array_keys(self::CATALOG);
        }
        if ($role === 'admin') {
            return array_values(array_diff(array_keys(self::CATALOG), ['team.manage']));
        }
        return self::ROLE_PERMS[$role] ?? [];
    }

    /**
     * دور الموظف الفعلي + صلاحياته الإضافية.
     * @return array{role:string, extra:string[]}
     */
    public static function staffProfile(array $user): array
    {
        $uid = (int) ($user['id'] ?? 0);
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }

        $role = ($user['role'] ?? '') === 'super_admin' ? 'owner' : 'admin';
        $extra = [];

        if ($uid > 0 && $role !== 'owner') {
            try {
                $rows = Database::getInstance()->query(
                    'SELECT staff_role, extra_permissions FROM admin_staff WHERE user_id = ? LIMIT 1',
                    [$uid]
                );
                if (!empty($rows[0]) && self::validRole((string) $rows[0]['staff_role'])) {
                    $role = (string) $rows[0]['staff_role'];
                    $decoded = json_decode((string) ($rows[0]['extra_permissions'] ?? ''), true);
                    if (is_array($decoded)) {
                        $extra = array_values(array_filter($decoded, static function ($p) {
                            return is_string($p) && isset(self::CATALOG[$p]);
                        }));
                    }
                }
            } catch (\Throwable $e) {
                // الجدول لسه ما اتعملش (قبل تشغيل SQL التحديث): نفضل على الافتراضي admin
            }
        }

        return self::$cache[$uid] = ['role' => $role, 'extra' => $extra];
    }

    /** @return string[] */
    public static function permissionsFor(array $user): array
    {
        $p = self::staffProfile($user);
        return array_values(array_unique(array_merge(self::rolePermissions($p['role']), $p['extra'])));
    }

    public static function can(array $user, string $permission): bool
    {
        // مستخدم من غير دور أدمن أصلًا: مفيش صلاحيات
        if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
            return false;
        }
        // موظف المالك خلّى حالته «موقوف» أو «ساب الشغل»: مفيش صلاحيات (المالك مابيتوقفش أبدًا)
        if (self::isSuspended($user)) {
            return false;
        }
        return in_array($permission, self::permissionsFor($user), true);
    }

    /** @var array<int,bool> */
    private static $suspended = [];

    /** حالة الموظف في ملفه (platform_staff_profile): «paused» أو «left» = موقوف. الجدول لسه ما اتعملش = مش موقوف */
    public static function isSuspended(array $user): bool
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0 || ($user['role'] ?? '') === 'super_admin') {
            return false;
        }
        if (isset(self::$suspended[$uid])) {
            return self::$suspended[$uid];
        }
        $out = false;
        try {
            $rows = Database::getInstance()->query('SELECT status FROM platform_staff_profile WHERE user_id = ? LIMIT 1', [$uid]);
            $out = !empty($rows[0]) && in_array((string) $rows[0]['status'], ['paused', 'left'], true);
        } catch (\Throwable $e) {
            $out = false;
        }
        return self::$suspended[$uid] = $out && !self::isPrimaryOwner($user);
    }

    /** @var int|null */
    private static $primaryOwner = null;

    /**
     * المالك الأساسي للمنصة: صاحب الصلاحية المطلقة (يحذف/يعدّل أي حد حتى الأدمن والـ Super Admin)،
     * ومحدش يقدر يحذفه أو يوقفه أو ينزّل دوره.
     * بيتحدد من PLATFORM_OWNER_EMAIL في .env، ولو مش متحدد = أقدم حساب Super Admin.
     */
    public static function primaryOwnerId(): int
    {
        if (self::$primaryOwner !== null) {
            return self::$primaryOwner;
        }
        $id = 0;
        try {
            $db = Database::getInstance();
            $email = function_exists('env') ? (string) (env('PLATFORM_OWNER_EMAIL') ?: '') : '';
            if ($email !== '') {
                $r = $db->query("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1", [strtolower(trim($email))]);
                $id = (int) ($r[0]['id'] ?? 0);
            }
            if ($id === 0) {
                $r = $db->query("SELECT id FROM users WHERE role = 'super_admin' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
                $id = (int) ($r[0]['id'] ?? 0);
            }
        } catch (\Throwable $e) {
        error_log('[AdminAccess] ignored error: ' . $e->getMessage());
        }
        return self::$primaryOwner = $id;
    }

    public static function isPrimaryOwner(array $user): bool
    {
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 && $id === self::primaryOwnerId();
    }

    public static function resetCache(): void
    {
        self::$cache = [];
        self::$suspended = [];
    }

    // ---------------------------------------------------------------- قواعد المسارات

    /**
     * الصلاحية المطلوبة لطلب معيّن. null = مش مسار أدمن (مش مسؤوليتنا).
     */
    public static function requiredFor(string $method, string $path): ?string
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $write = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        // ---- صفحات الأدمن
        if ($path === '/admin' || strpos($path, '/admin/') === 0) {
            $pages = [
                '#^/admin/?$#' => 'analytics.view',
                '#^/admin/platform$#' => 'analytics.view',
                '#^/admin/users(/\d+)?$#' => 'users.view',
                '#^/admin/subscriptions$#' => 'subscriptions.view',
                '#^/admin/contact-messages$#' => 'messages.view',
                '#^/admin/support$#' => 'tickets.view',
                '#^/admin/plans$#' => 'billing.view',
                '#^/admin/finance$#' => 'billing.view',
                '#^/admin/api-keys$#' => 'settings.manage',
                '#^/admin/visitors$#' => 'analytics.view',
                '#^/admin/onboarding-funnel$#' => 'analytics.view',
                '#^/admin/login-history$#' => 'security.view',
                '#^/admin/security$#' => 'security.view',
                '#^/admin/system$#' => 'system.view',
                '#^/admin/logs$#' => 'system.view',
                '#^/admin/errors$#' => 'system.view',
                '#^/admin/jobs$#' => 'system.view',
                '#^/admin/seo-markets$#' => 'settings.manage',
                '#^/admin/settings$#' => 'settings.manage',
                '#^/admin/integrations$#' => 'settings.manage',
                '#^/admin/export/users$#' => 'users.export',
                '#^/admin/export/subscriptions$#' => 'subscriptions.view',
                '#^/admin/team$#' => 'team.view',
                '#^/admin/audit$#' => 'audit.view',
                '#^/admin/ai-usage$#' => 'ai_usage.view',
                '#^/admin/seo-data-costs$#' => 'billing.view',
                '#^/admin/health$#' => 'system.view',
                '#^/admin/emails$#' => 'system.view',
                '#^/admin/growth$#' => 'settings.manage',
                '#^/admin/outreach-desk$#' => 'outreach.desk',
                '#^/admin/outreach-sent$#' => 'outreach.desk',
                '#^/admin/plans-studio$#' => 'billing.view',
                '#^/admin/metrics$#' => 'billing.view',
                '#^/admin/customers-health$#' => 'users.view',
                '#^/admin/adoption$#' => 'users.view',
                '#^/admin/billing-ops$#' => 'billing.view',
                '#^/admin/usage-reservations$#' => 'billing.view',
                '#^/admin/alerts$#' => 'system.view',
                '#^/admin/admin-security$#' => 'security.manage',
                '#^/admin/status-page$#' => 'settings.manage',
                '#^/admin/blog$#' => 'settings.manage',
            ];
            foreach ($pages as $re => $perm) {
                if (preg_match($re, $path)) {
                    return $perm;
                }
            }
            return 'system.manage'; // صفحة غير معروفة: owner/admin بس
        }

        if (strpos($path, '/api/admin/') !== 0 && $path !== '/api/admin') {
            return null;
        }
        $rel = substr($path, strlen('/api/admin'));
        if (preg_match('#^/seo-data/(sites|markets)$#D', $rel)) return 'settings.manage';

        // ---- لوحة الأدمن الاحترافية (AdminInsightsController)
        if (strpos($rel, '/insights/') === 0) {
            $map = [
                '#^/insights/health$#' => ['system.view', 'system.view'],
                '#^/insights/errors$#' => ['system.view', 'system.view'],
                '#^/insights/health/check-integrations$#' => ['settings.manage', 'settings.manage'],
                '#^/insights/health/apply-schema$#' => ['settings.manage', 'settings.manage'],
                '#^/insights/cleanup$#' => ['settings.manage', 'settings.manage'],
                '#^/insights/emails(/\d+)?$#' => ['system.view', 'system.manage'],
                '#^/insights/emails/\d+/resend$#' => ['system.manage', 'system.manage'],
                '#^/insights/metrics$#' => ['billing.view', 'billing.view'],
                '#^/insights/customers(/\d+)?$#' => ['users.view', 'users.view'],
                '#^/insights/adoption$#' => ['users.view', 'users.view'],
                '#^/insights/billing$#' => ['billing.view', 'billing.view'],
                '#^/insights/coupons(/\d+)?$#' => ['billing.view', 'billing.settings'],
                '#^/insights/alerts$#' => ['system.view', 'system.view'],
                '#^/insights/alerts/(run|\d+/resolve)$#' => ['system.view', 'system.view'],
                '#^/insights/alerts/settings$#' => ['settings.manage', 'settings.manage'],
                '#^/insights/security$#' => ['security.view', 'security.manage'],
                '#^/insights/tags$#' => ['users.view', 'users.notes'],
                '#^/insights/users/\d+/tags$#' => ['users.view', 'users.notes'],
                '#^/insights/users/\d+/verify-email$#' => ['users.edit', 'users.edit'],
                '#^/insights/accounts/\d+(/(update|password|delete|restore))?$#' => ['users.view', 'users.view'],
                '#^/insights/accounts-deleted$#' => ['users.view', 'users.view'], // الصلاحية الفعلية جوه AdminAccountsController
                '#^/insights/users/bulk$#' => ['users.view', 'users.view'], // الصلاحية الفعلية حسب نوع الإجراء جوه الكنترولر
                '#^/insights/views(/\d+)?$#' => ['analytics.view', 'analytics.view'],
                '#^/insights/(statuspage|incidents(/\d+)?|changelog(/\d+)?)$#' => ['settings.manage', 'settings.manage'],
                '#^/insights/blog(/\d+|/preview)?$#' => ['settings.manage', 'settings.manage'],
            ];
            foreach ($map as $re => [$read, $writePerm]) {
                if (preg_match($re, $rel)) {
                    return $write ? $writePerm : $read;
                }
            }
            return 'system.manage';
        }

        // ---- Admin Pro / Ops
        if (preg_match('#^/pro/(overview|attention|activity|health)$#', $rel)) return 'analytics.view';
        if ($rel === '/pro/search') return 'users.view';
        if (preg_match('#^/pro/user/\d+$#', $rel)) return 'users.view';
        if (preg_match('#^/pro/user/\d+/notes(/\d+)?$#', $rel)) return $write ? 'users.notes' : 'users.view';
        if (preg_match('#^/usage-reservations(?:/[a-f0-9]{48}/resolve)?$#',$rel)) return $write ? 'billing.manage' : 'billing.view';
        if ($rel === '/ops/me') return 'analytics.view';
        // نشاط موظف بعينه (إجراءات + دخول): لمن يملك سجل التدقيق
        if (preg_match('#^/ops/team/\d+/activity$#', $rel)) return 'audit.view';
        if ($rel === '/ops/team' || $rel === '/ops/team/roles') return $write ? 'team.manage' : 'team.view';
        if (strpos($rel, '/ops/team/') === 0) return $write ? 'team.manage' : 'team.view';
        if (strpos($rel, '/ops/audit') === 0) return 'audit.view';
        if (strpos($rel, '/ops/ai-usage') === 0 || strpos($rel, '/ops/ai-routing') === 0) return 'ai_usage.view';
        if (strpos($rel, '/ops/jobs') === 0) return $write ? 'system.manage' : 'system.view';
        if (strpos($rel, '/ops/security') === 0) return $write ? 'security.manage' : 'security.view';

        // ---- العملاء
        if (preg_match('#^/users/\d+/(suspend|activate|toggle)$#', $rel)) return 'users.suspend';
        if (preg_match('#^/users/\d+/reset-2fa$#', $rel)) return 'users.reset_security';
        if (preg_match('#^/users/\d+/(add-balance|deduct-balance)$#', $rel)) return 'billing.manage';
        if (preg_match('#^/users/\d+/grant-plan(/end)?$#', $rel)) return $write ? 'billing.manage' : 'billing.view';
        if (preg_match('#^/users/\d+/impersonate#', $rel)) return 'users.impersonate';
        if (preg_match('#^/users(/\d+)?$#', $rel)) {
            if ($method === 'DELETE') return 'users.delete';
            return $write ? 'users.edit' : 'users.view';
        }
        if ($rel === '/broadcast') return 'broadcast.send';

        // ---- الباقات المرنة والعملات
        if ($rel === '/plans-studio' || strpos($rel, '/plans-studio/') === 0 || strpos($rel, '/currency/') === 0) return $write ? 'billing.settings' : 'billing.view';
        if ($rel === '/xpay' || strpos($rel, '/xpay/') === 0) return 'billing.settings';

        // ---- تسويق المنصة بالإيميل
        if ($rel === '/growth' || strpos($rel, '/growth/') === 0) return 'settings.manage';
        // ---- مكتب التواصل (موظفة المبيعات)
        if ($rel === '/outreach-desk' || strpos($rel, '/outreach-desk/') === 0) return 'outreach.desk';

        // ---- مفاتيح الشركاء: حساسة، لصاحب إدارة الإعدادات فقط (قراءة وتعديل)
        if (strpos($rel, '/partner-keys') === 0) return 'settings.manage';

        // ---- الاشتراكات والفواتير
        if (preg_match('#^/subscriptions(/\d+)?$#', $rel) && !$write) return 'subscriptions.view';
        if (preg_match('#^/(subscriptions|invoices)/#', $rel) && $write) return 'subscriptions.manage';
        if ($rel === '/subscriptions' && $write) return 'subscriptions.manage';

        // ---- تذاكر الدعم
        if ($rel === '/support' || strpos($rel, '/support/') === 0) return $write ? 'tickets.manage' : 'tickets.view';

        // ---- الرسائل والباقات
        if ($rel === '/contact-messages' && !$write) return 'messages.view';
        if (preg_match('#^/contact-messages/#', $rel)) return 'messages.manage';
        if ($rel === '/plans' && !$write) return 'billing.view';
        if (preg_match('#^/plans/\d+$#', $rel)) return 'plans.manage';

        // ---- المحفظة
        if (strpos($rel, '/wallet/') === 0 || $rel === '/refunds' || $rel === '/tax-rules') {
            // التصفير عمليات حساسة: billing.settings (owner/admin) بدل billing.manage
            if (preg_match('#^/wallet/(platform-balance/(reset|restore)|zero-all-balances)$#', $rel)) return 'billing.settings';
            if ($rel === '/wallet/platform-balance' && !$write) return 'billing.view';
            if (preg_match('#^/wallet/(pending|stats|mrr-trend|usage-revenue|cards)$#', $rel) && !$write) {
                return $rel === '/wallet/cards' ? 'billing.manage' : 'billing.view';
            }
            if (preg_match('#^/wallet/\d+/(approve|reject)$#', $rel)) return 'billing.manage';
            if ($rel === '/wallet/cards/generate' || ($rel === '/refunds')) return 'billing.manage';
            if ($rel === '/wallet/settings' || $rel === '/tax-rules' || strpos($rel, '/wallet/usage-pricing') === 0) {
                return 'billing.settings';
            }
            return 'billing.manage';
        }

        // ---- النظام
        if (preg_match('#^/system/(health|logs|stats)$#', $rel)) return 'system.view';
        if ($rel === '/system/cache/clear') return 'system.manage';
        if ($rel === '/platform-overview' || $rel === '/new-features-stats') return 'analytics.view';
        if ($rel === '/ai-usage-stats') return 'ai_usage.view';
        if (preg_match('#^/(branding|legal-content|system-settings|faq|features)#', $rel)) return 'settings.manage';

        return $write ? 'system.manage' : 'analytics.view';
    }

    // ---------------------------------------------------------------- التنفيذ

    /**
     * يتفحص الطلب الحالي. يرجّع null لو مسموح، أو مصفوفة خطأ (للـ API) / يعرض صفحة 403 (للصفحات).
     * @return array|null
     */
    public static function enforceRequest(array $user): ?array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $required = self::requiredFor($method, $path);
        if ($required === null) {
            return null;
        }

        // أمان الأدمن: IP مسموح، 2FA إجباري، سبب للإجراءات الحساسة
        if (($gate = self::securityGate($user, $method, $path)) !== null) {
            return $gate;
        }

        self::registerAudit($user, $method, $path);

        if (self::can($user, $required)) {
            return null;
        }

        $isApi = strpos($path, '/api/') === 0 || strtoupper($method) !== 'GET';
        if (!$isApi && stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === false) {
            // موظف دوره مش فيه صلاحية الصفحة الرئيسية (مبيعات مثلًا): نوديه لأول صفحة مسموحة له بدل رسالة الرفض
            if (($land = self::landingPage($user, $path)) !== null) {
                header('Location: ' . $land, true, 302);
                exit;
            }
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo self::forbiddenPage($required);
            exit;
        }
        http_response_code(403);
        return [
            'success' => false,
            'error' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء (' . (self::CATALOG[$required][1] ?? $required) . ').',
            'code' => 403,
            'required_permission' => $required,
        ];
    }

    /** أول صفحة مسموحة للموظف لما يفتح /admin الرئيسية وهي مش مسموحة له؛ null = مفيش تحويل */
    public static function landingPage(array $user, string $path): ?string
    {
        if (!preg_match('#^/admin/?$#', $path)) {
            return null;
        }
        foreach (['outreach.desk' => '/admin/outreach-desk', 'tickets.view' => '/admin/support', 'users.view' => '/admin/users', 'messages.view' => '/admin/contact-messages', 'subscriptions.view' => '/admin/subscriptions'] as $perm => $page) {
            if (self::can($user, $perm)) {
                return $page;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- أمان الأدمن

    /** الإجراءات اللي لازم يتكتب لها سبب (بيتسجّل في سجل التدقيق) */
    public const REASON_REQUIRED = [
        '#^/api/admin/users/\d+/impersonate$#' => 'الدخول بحساب عميل',
        '#^/api/admin/refunds$#' => 'استرداد فلوس',
        '#^/api/admin/users/\d+/(add-balance|deduct-balance)$#' => 'تعديل رصيد عميل',
        '#^/api/admin/users/\d+/grant-plan(/end)?$#' => 'منحة باقة مجانية لعميل',
        '#^/api/admin/wallet/(platform-balance/(reset|restore)|zero-all-balances)$#' => 'تصفير الأرصدة',
    ];

    private static $sec = null;

    public static function securitySettings(): array
    {
        if (self::$sec !== null) {
            return self::$sec;
        }
        $s = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        $get = function ($k, $d) use ($s) {
            try {
                return $s ? $s->get($k, $d) : $d;
            } catch (Throwable $e) {
                return $d;
            }
        };
        return self::$sec = [
            'require_2fa' => $get('admin_require_2fa', '0') === '1',
            'ip_allowlist' => trim((string) $get('admin_ip_allowlist', '')),
            'require_reason' => $get('admin_require_reason', '1') !== '0',
        ];
    }

    public static function resetSecurityCache(): void
    {
        self::$sec = null;
    }

    public static function ipAllowed(string $ip, string $list): bool
    {
        if (trim($list) === '') {
            return true;
        }
        foreach (preg_split('/[\s,;]+/', $list) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (strpos($rule, '/') === false) {
                if ($rule === $ip) {
                    return true;
                }
                continue;
            }
            [$net, $bits] = explode('/', $rule, 2);
            $ipBin = @inet_pton($ip);
            $netBin = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function securityGate(array $user, string $method, string $path): ?array
    {
        $cfg = self::securitySettings();
        $isApi = strpos($path, '/api/') === 0;
        $deny = function (string $title, string $msg, ?string $link = null, ?string $linkText = null) use ($isApi) {
            http_response_code(403);
            if (!$isApi && stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === false) {
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
                    . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Readex Pro,Segoe UI,Tahoma,sans-serif;background:#0b1020;color:#e8ecf3}.b{max-width:460px;padding:40px 28px;text-align:center}h1{font-size:21px;margin:0 0 10px}p{color:#9aa4b8;line-height:1.8;margin:0 0 22px}a{display:inline-block;background:#3b5bfd;color:#fff;text-decoration:none;padding:11px 22px;border-radius:10px}</style></head>'
                    . '<body><div class="b"><h1>' . htmlspecialchars($title) . '</h1><p>' . $msg . '</p>' . ($link ? '<a href="' . htmlspecialchars($link) . '">' . htmlspecialchars((string) $linkText) . '</a>' : '') . '</div></body></html>';
                exit;
            }
            return ['success' => false, 'error' => $title . ': ' . strip_tags($msg), 'code' => 403];
        };

        if ($cfg['ip_allowlist'] !== '') {
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!self::ipAllowed($ip, $cfg['ip_allowlist'])) {
                return $deny('الدخول من الشبكة دي مش مسموح', 'لوحة الأدمن مقفولة على عناوين IP محددة، وعنوانك (' . htmlspecialchars($ip) . ') مش منهم.');
            }
        }

        if ($cfg['require_2fa'] && empty(self::fresh2fa((int) ($user['id'] ?? 0)))) {
            return $deny('فعّل التحقق بخطوتين الأول', 'لوحة الأدمن بتطلب التحقق بخطوتين (2FA) لكل الفريق. فعّله من إعدادات حسابك وارجع.', '/profile/settings', 'فعّل 2FA دلوقتي');
        }

        if ($cfg['require_reason'] && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            foreach (self::REASON_REQUIRED as $re => $label) {
                if (preg_match($re, $path)) {
                    $reason = self::requestReason();
                    if (mb_strlen(trim($reason)) < 5) {
                        http_response_code(422);
                        return ['success' => false, 'error' => "اكتب سبب ({$label}) - بيتسجّل في سجل التدقيق.", 'code' => 422, 'reason_required' => true];
                    }
                }
            }
        }
        return null;
    }

    private static function fresh2fa(int $uid): bool
    {
        try {
            $r = Database::getInstance()->query('SELECT two_factor_enabled FROM users WHERE id = ? LIMIT 1', [$uid]);
            return !empty($r[0]['two_factor_enabled']);
        } catch (Throwable $e) {
            return true; // لو مش قادرين نتأكد مانقفلش اللوحة
        }
    }

    private static function requestReason(): string
    {
        if (!empty($_POST['reason'])) {
            return (string) $_POST['reason'];
        }
        $raw = @file_get_contents('php://input');
        $j = $raw ? json_decode($raw, true) : null;
        return is_array($j) && isset($j['reason']) ? (string) $j['reason'] : '';
    }

    private static function forbiddenPage(string $perm): string
    {
        $label = htmlspecialchars(self::CATALOG[$perm][1] ?? $perm, ENT_QUOTES, 'UTF-8');
        $app = htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Tourfecto', ENT_QUOTES, 'UTF-8');
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>غير مصرّح | ' . $app . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Readex Pro,Segoe UI,Tahoma,sans-serif;background:#F4F5F8;color:#0F1B2D}'
            . '.b{max-width:440px;padding:40px 28px;text-align:center}h1{font-size:22px;margin:0 0 10px}p{color:#5D687C;line-height:1.8;margin:0 0 22px}'
            . 'a{display:inline-block;background:#1F4FFF;color:#fff;text-decoration:none;padding:11px 22px;border-radius:10px;font-weight:500}</style></head>'
            . '<body><div class="b"><h1>ليس لديك صلاحية لهذه الصفحة</h1><p>الصلاحية المطلوبة: ' . $label . '.<br>اطلب من مالك المنصة إضافتها لدورك.</p>'
            . '<a href="/admin">رجوع للوحة الأدمن</a></div></body></html>';
    }

    // ---------------------------------------------------------------- سجل التدقيق التلقائي

    private static $auditRegistered = false;

    /** يسجّل كل تعديل على /api/admin/* وكل تصدير بعد انتهاء الطلب (بالـ status الفعلي) */
    private static function registerAudit(array $user, string $method, string $path): void
    {
        $write = in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $isExport = strpos($path, '/admin/export/') === 0;
        if (self::$auditRegistered || (!$write && !$isExport) || strpos($path, '/ops/audit') !== false) {
            return;
        }
        self::$auditRegistered = true;
        $uid = (int) ($user['id'] ?? 0);
        $keys = array_keys(is_array($_POST) ? $_POST : []);
        $raw = @file_get_contents('php://input');
        if ($raw) {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $keys = array_values(array_unique(array_merge($keys, array_keys($j))));
            }
        }

        $reason = self::requestReason();
        register_shutdown_function(static function () use ($uid, $method, $path, $keys, $reason) {
            self::writeAudit($uid, $method, $path, (int) http_response_code(), $keys, $reason);
        });
    }

    /** @internal public عشان الاختبار */
    public static function writeAudit(int $uid, string $method, string $path, int $status, array $keys = [], string $reason = ''): void
    {
        try {
            $norm = preg_replace('#/\d+#', '/{id}', $path);
            $action = substr(strtoupper($method) . ' ' . $norm, 0, 60);
            $parts = array_values(array_filter(explode('/', $path)));
            $objType = $parts[2] ?? ($parts[1] ?? null);
            $objId = preg_match('#/(\d+)(/|$)#', $path, $m) ? $m[1] : null;
            $meta = json_encode(
                ['path' => $path, 'status' => $status, 'fields' => array_slice($keys, 0, 25)] + ($reason !== '' ? ['reason' => mb_substr($reason, 0, 500)] : []),
                JSON_UNESCAPED_UNICODE
            );
            Database::getInstance()->exec(
                'INSERT INTO audit_logs (user_id, action, object_type, object_id, result, meta, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $uid, $action, $objType, $objId, $status >= 400 ? 'failed' : 'success', $meta,
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('AdminAccess audit failed', ['message' => $e->getMessage()]);
            }
        }
    }
}
