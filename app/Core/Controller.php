<?php

/**
 * Tourfecto - Base Controller Class
 * كلاس التحكم الأساسي لجميع المتحكمات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

abstract class Controller
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    protected $db;

    /**
     * @var array $data - بيانات الطلب
     */
    protected $data = [];

    /**
     * @var array $input - بيانات الإدخال (JSON)
     */
    protected $input = [];

    /**
     * @var array $errors - أخطاء التحقق
     */
    protected $errors = [];

    /**
     * @var bool $authenticated - حالة المصادقة
     */
    protected $authenticated = false;

    /**
     * @var array $user - بيانات المستخدم الحالي
     */
    protected $user = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->parseInput();
        $this->loadAuthenticatedUser();
    }

    /**
     * ربط حالة المصادقة التي يضبطها AuthMiddleware (عبر $_SESSION أو $_SERVER)
     * بخصائص الكلاس $user / $authenticated.
     *
     * ملاحظة: هذه الدالة أُضيفت لأن AuthMiddleware وConstroller::authenticate()
     * كانا منفصلين تمامًا؛ AuthMiddleware يتحقق من التوكن ويخزّن المستخدم في
     * $_SESSION['user']، لكن لا شيء كان يستدعي $this->authenticate() داخل
     * المتحكمات، فكانت $this->isAuthenticated() ترجع false دائمًا حتى مع
     * تسجيل دخول صحيح، ما يعطّل كل مسارات AI/Chat/Dashboard/Reputation/Subscription.
     */
    protected function loadAuthenticatedUser(): void
    {
        $user = $_SESSION['user'] ?? ($_SERVER['auth_user'] ?? null);

        if (is_array($user) && !empty($user)) {
            $this->user = $user;
            $this->authenticated = true;
        }
    }

    /**
     * تحليل بيانات الإدخال
     */
    protected function parseInput(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $decoded = json_decode($input, true);
            $this->input = is_array($decoded) ? $decoded : [];
        } else {
            $this->input = $_POST;
        }

        // دمج مع بيانات GET
        $this->data = array_merge($_GET, $this->input);
    }

    /**
     * إرسال استجابة ناجحة
     * @param array $data
     * @param string $message
     * @param int $code
     * @return array
     */
    protected function success(array $data = [], string $message = 'Success', int $code = 200): array
    {
        if (class_exists('VendorMask')) {
            $message = VendorMask::isStaff($this->user ?? null) ? VendorMask::scrubSecrets($message) : VendorMask::scrub($message);
        }
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $code
        ];
    }

    /**
     * إرسال استجابة خطأ
     * @param string $error
     * @param int $code
     * @param array $details
     * @return array
     */
    protected function error(string $error, int $code = 400, array $details = []): array
    {
        if (class_exists('CustomerError')) {
            $error = CustomerError::message($error, $code);
            $details = CustomerError::details($details);
        }
        // العميل ما يشوفش أي اسم مزوّد AI ولا موقعه ولا كود تقني. الأدمن بيشوف التفاصيل (ومفاتيح الـ API بتتخفي عن الكل).
        if (class_exists('VendorMask')) {
            if (VendorMask::isStaff($this->user ?? null)) {
                $error = VendorMask::scrubSecrets($error);
            } else {
                $error = VendorMask::scrub($error);
                $details = VendorMask::scrubDetails($details);
            }
        }
        return [
            'success' => false,
            'error' => $error,
            'code' => $code,
            'details' => $details
        ];
    }

    /**
     * فحص CSRF اختياري (Opt-in) - بيتنادى من أي Controller/Method بيقرر
     * يستخدمه بنفسه صراحة. بيستثني عملاء الـ Bearer Token (API Keys
     * الشخصية، JWT، مفاتيح الشركاء) لأنهم مش معتمدين على كوكي الجلسة.
     * @return array|null يرجع مصفوفة خطأ (419) لو فشل الفحص، أو null لو ناجح
     */
    protected function verifyCsrf(): ?array
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (!empty($headers['Authorization']) || !empty($headers['authorization'])) {
            return null;
        }

        $submitted = (string) $this->get('csrf_token');
        // صفحات كتير كانت بتبعت الطلب من غير التوكن في البيانات (فالعميل يطلع له "انتهت الجلسة"
        // وهو لسه داخل). Panel.fetchJSON بقى يبعته في الهيدر مع كل طلب.
        if ($submitted === '') {
            $submitted = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }
        if (!class_exists('Csrf') || !Csrf::verify($submitted)) {
            return $this->error('انتهت صلاحية الجلسة، حدّث الصفحة وحاول تاني', 419);
        }
        return null;
    }

    /**
     * حارس معدل الطلبات (Rate Limit Guard) - طبقة دقيقة فوق RateLimiter
     * الموجود. يعيد null لو الطلب مسموح (أو لو فشل الفحص لأي سبب -
     * fail-open عشان الحماية مش تعطّل الاستخدام العادي)، أو مصفوفة خطأ 429
     * بالعربي لو تجاوز الحد.
     *
     * النطاقات (Tiers):
     *   - 'user': بمعرّف المستخدم الحالي (user:{id}) - للحدود الصارمة لكل
     *     مستخدم على نقط النهايات المكلفة (AI). لو مفيش مستخدم موثّق
     *     نرجّع null ونعتمد على الطبقة العامة (middleware) بالـ IP.
     *   - 'ip': بمعرّف عنوان IP - للحدود المبنية على العنوان (login/auth).
     *
     * كل نقط النهايات اللي بتستخدم نفس الـ scope لنفس المستخدم/الـ IP
     * بتشارك عداد واحد - يعني حد AI شامل لكل مستخدم مش حد لكل نقطة.
     *
     * @param string $tier 'user' أو 'ip'
     * @param string $scope نوع الحصة (ai, auth_ip, ...) - يحدد مفتاح الكاش
     * @param int $max الحد الأقصى للطلبات خلال النافذة
     * @param int $window نافذة الوقت بالثواني
     * @return array|null مصفوفة خطأ 429 لو تجاوز، أو null لو مسموح
     */
    protected function rateLimitGuard(string $tier, string $scope, int $max, int $window = 60): ?array
    {
        try {
            $limiter = new RateLimiter();

            if ($tier === 'user') {
                $userId = (int) ($this->user['id'] ?? 0);
                if ($userId <= 0) {
                    return null;
                }
                $identifier = 'user:' . $userId;
            } elseif ($tier === 'ip') {
                $identifier = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            } else {
                return null;
            }

            $result = $limiter->checkWithDetails($identifier, $scope, $max, $window);

            if (!$result['allowed']) {
                return $this->error(
                    'طلبات كتير أوي - من فضلك انتظر لحظة وحاول تاني',
                    429,
                    [
                        'retry_after' => (int) ($result['reset_in'] ?? $window),
                        'limit' => $max,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // لو فشل الفحص لأي سبب (DB/كاش)، منمنعش الاستخدام العادي بسببه
            Logger::error('Rate Limit Guard Error', ['tier' => $tier, 'scope' => $scope, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * الحصول على قيمة من الإدخال
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * الحصول على جميع قيم الإدخال
     * @return array
     */
    protected function all(): array
    {
        return $this->data;
    }

    /**
     * التحقق من وجود قيمة في الإدخال
     * @param string $key
     * @return bool
     */
    protected function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * التحقق من البيانات
     * @param array $rules
     * @return bool
     */
    protected function validate(array $rules): bool
    {
        $validator = new Validator();
        $result = $validator->validate($this->data, $rules);

        if (!$result['valid']) {
            $this->errors = $result['errors'];
            return false;
        }

        return true;
    }

    /**
     * الحصول على أخطاء التحقق
     * @return array
     */
    protected function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * مصادقة المستخدم
     * @param string $token
     * @return bool
     */
    protected function authenticate(string $token): bool
    {
        try {
            // التحقق من التوكن في قاعدة البيانات
            // تصحيح: لا يوجد عمود is_active، العمود الفعلي هو status='active'
            $sql = "SELECT * FROM users WHERE api_token = :token AND status = 'active' LIMIT 1";
            $result = $this->db->query($sql, [':token' => $token]);

            if (empty($result)) {
                return false;
            }

            $this->user = $result[0];
            $this->authenticated = true;
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * التحقق من المصادقة
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * الحصول على بيانات المستخدم الحالي
     * @return array
     */
    protected function getUser(): array
    {
        return $this->user;
    }

    /**
     * التحقق من صلاحية المستخدم
     * @param string $permission
     * @return bool
     */
    protected function hasPermission(string $permission): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        // التحقق من صلاحيات المستخدم
        // يمكن توسيع هذا حسب نظام الصلاحيات
        return true;
    }

    /**
     * تسجيل نشاط
     * @param string $action
     * @param array $data
     */
    protected function log(string $action, array $data = []): void
    {
        Logger::info('User Action: ' . $action, [
            'user_id' => $this->user['id'] ?? null,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    // ============================================
    // Panel Layout المشترك (نفس الشكل المستخدم في DashboardController)
    // ============================================
    // ملاحظة: تم نقل توليد الـ Sidebar/الشِل هنا بدل ما يتكرر في كل
    // Controller (AIController, ReputationController, ChatController...)
    // كل واحدة منهم أصلاً كانت بترجع JSON فاضي بدل صفحة HTML حقيقية.

    /**
     * سايد بار لوحة العميل - نفس القائمة المستخدمة في /dashboard
     * @param string $activeTab
     * @return string
     */
    /**
     * اختصار t() + htmlspecialchars للاستخدام الآمن جوه heredoc HTML.
     * @param string $key
     * @return string
     */
    /**
     * زي tr() بس بترجع الترجمة كـ JS string literal جاهز للحقن المباشر
     * جوه <script> (مع quotes وescaping صحيح) - مختلفة عن tr() اللي
     * بتعمل htmlspecialchars للاستخدام جوه HTML.
     * @param string $key
     * @return string
     */
    protected function trJs(string $key): string
    {
        return json_encode(t($key), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
    }

    protected function tr(string $key): string
    {
        return htmlspecialchars(t($key), ENT_QUOTES, 'UTF-8');
    }

    /**
     * قاموس الترجمة الحالي كـ JSON، يتحقن في window.I18N جوه الصفحة
     * عشان الـ JS بتاع كل صفحة (اللي مكتوب بـ nowdoc <<<'JS' عشان
     * template literals ${} تشتغل صح) يقدر يستخدم I18N['key'] من غير
     * ما نحتاج نحوّل أي nowdoc لـ heredoc (خطر حقيقي لأن ${} ممكن PHP
     * يفهمها غلط كمتغير).
     * @return string
     */
    protected function i18nJson(): string
    {
        $dict = load_translations(current_lang());
        // نصوص الأدمن التقنية (أسماء مزوّدين ومتغيّرات إعدادات وأكواد) ما تتبعتش لمتصفح العميل خالص (كانت بتظهر في view-source)
        if (class_exists('VendorMask') && !VendorMask::isStaff($this->user ?? null)) {
            foreach (array_keys($dict) as $k) {
                if (strpos($k, 'ai.articles.image_err.') === 0 || strpos($k, 'ai.articles.imgchk.') === 0) {
                    unset($dict[$k]);
                }
            }
        }
        return json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
    }

    protected function renderPanelSidebar(string $activeTab): string
    {
        // صفحات المنافسين التلاتة بقوا تبويبات في مدخل واحد
        if (in_array($activeTab, ['competitor_monitoring', 'ai_competitors'], true)) {
            $activeTab = 'competitor_intelligence';
        }
        // كل صفحات السيو بقت تبويبات جوه "مركز السيو"
        if ($this->seoHubActive() !== null) {
            $activeTab = 'seo_expert';
        }
        $isAdmin = in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true);
        // صفحة أدمن (تحت /admin) معمولة بنفس الشِل: تاخد قايمة الأدمن مش قايمة العميل
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($isAdmin && preg_match('#^/admin(/|$)#', $path) && empty($_SESSION['admin_customer_view']) && class_exists('AdminController')) {
            try {
                return AdminController::sidebarFor($this->user, trim(substr($path, 7), '/'));
            } catch (Throwable $e) {
            }
        }
        $ar = current_lang() === 'ar';
        // الصفحات اللي بقت تبويبات: القائمة بتنوّر الصفحة الأم بتاعتها
        $activeTab = self::NAV_PARENT[$activeTab] ?? $activeTab;

        $L = function (string $arText, string $enText) use ($ar): string { return $ar ? $arText : $enText; };

        // ============================================================
        // تبسيط (2026-10-ج): "رحلة" مرتّبة بدل "كل الأدوات (31)".
        // العميل بيمشي خطوة بخطوة: موقعه ← ظهوره على جوجل ← المحتوى ← التقييمات
        // ← العملاء ← المنافسين ← النتائج. كل خطوة بتفتح أدواتها تحتها بس لما
        // يكون فيها، والخطوة اللي خلصت عليها علامة ✓ والتالية عليها "التالي".
        // نفس الصفحات ونفس مفاتيح الـ feature flags بالظبط - مفيش ميزة اتحذفت.
        // ============================================================
        // تنظيم (2026-10-24): كل خطوة فيها الصفحات الأساسية بس (2-6)، والصفحات
        // المتفرعة (إحصائيات، تحليلات، متابعة، معرفة...) بقت تبويبات جوه الصفحة نفسها
        // (sectionTabsHtml) - نفس الروابط ونفس الـ feature flags، مفيش صفحة اتشالت.
        $home = [
            'overview' => [t('sidebar.overview'), 'bar-chart-2', '/dashboard'],
            'ai_assistant' => [t('sidebar.ai_assistant'), 'sparkles', '/ai-assistant'],
            'automation' => [$L('مركز الأتمتة', 'Automation Center'), 'zap', '/automation'],
        ];
        $steps = [
            'site' => [$L('موقعك والحجوزات', 'Website & bookings'), $L('موقعك ورحلاتك والحجوزات', 'Your site, tours and bookings'), [
                '_websites' => [t('sidebar.websites'), 'globe', '/websites'],
                'website_builder' => [t('sidebar.website_builder'), 'layout', '/website-builder'],
                'bookings' => [$L('الحجوزات والتوفر', 'Bookings & availability'), 'calendar', '/bookings'],
                'operations' => [$L('تشغيل الرحلات', 'Trip operations'), 'calendar', '/operations'],
            ]],
            'google' => [$L('ظهورك على جوجل', 'Get found on Google'), $L('SEO وكلمات البحث وملفك على الخريطة', 'SEO, keywords and Maps profile'), [
                // مدخل واحد للسيو كله (الفحص والكلمات والمحتوى والظهور في الذكاء الاصطناعي) - التبويبات جوه الصفحة (seoHubTabsHtml)
                'seo_expert' => [$L('مركز السيو', 'SEO hub'), 'search', '/seo-expert'],
                'gbp_content' => [t('sidebar.gbp_content'), 'map-pin', '/gbp-content'],
            ]],
            'content' => [$L('المحتوى والتسويق', 'Content & marketing'), $L('مقالات وسوشيال وإيميل وإعلانات', 'Articles, social, email, ads'), [
                'ai_articles' => [t('sidebar.ai_articles'), 'edit', '/ai/articles'],
                'social_plan' => [$L('خطة السوشيال', 'Social plan'), 'calendar', '/social-plan'],
                'social' => [t('sidebar.social'), 'smartphone', '/social'],
                'whatsapp_marketing' => [$L('تسويق واتساب', 'WhatsApp Marketing'), 'message', '/whatsapp-marketing'],
                'email_marketing' => [t('sidebar.email_marketing'), 'mail', '/email-marketing'],
                'ads' => [t('sidebar.ads'), 'megaphone', '/ads'],
                'creative_studio' => [t('sidebar.creative_studio'), 'palette', '/creative-studio'],
            ]],
            'reviews' => [$L('التقييمات والسمعة', 'Reviews & reputation'), $L('اجمع تقييمات ورد عليها', 'Collect and answer reviews'), [
                'reputation' => [t('sidebar.reputation'), 'star', '/reputation/reviews'],
                'review_requests' => [t('sidebar.review_requests'), 'send', '/review-requests'],
                'reputation_overview' => [$L('تحليل السمعة', 'Reputation analysis'), 'bar-chart', '/reputation/overview'],
            ]],
            'customers' => [$L('العملاء والرسايل', 'Customers & inbox'), $L('رسايل العملاء ومتابعتهم لحد الحجز', 'Inbox, follow-ups, deals'), [
                'ai_chat_inbox' => [t('sidebar.ai_chat_inbox'), 'message', '/chat'],
                'crm' => [t('sidebar.crm'), 'file-text', '/crm'],
                'ai_chat_leads' => [t('sidebar.ai_chat_leads'), 'target', '/chat/leads'],
                'ai_chat_settings' => [$L('إعدادات الشات وواتساب', 'Chat & WhatsApp settings'), 'settings', '/chat/settings'],
            ]],
            // 3 صفحات منافسين كانت متداخلة في القائمة - بقوا مدخل واحد وجواه تبويبات (competitorTabsHtml)
            'competitors' => [$L('المنافسين', 'Competitors'), $L('راقب أسعارهم وعروضهم', 'Watch their prices and offers'), [
                'competitor_intelligence' => [$L('مركز المنافسين', 'Competitor hub'), 'users', '/competitor-intelligence'],
            ]],
            'results' => [$L('النتائج والأرباح', 'Results & revenue'), $L('شوف إيه اللي بيجيب فلوس', 'See what brings revenue'), [
                'executive' => [t('sidebar.executive'), 'compass', '/dashboard/executive'],
                'revenue' => [t('sidebar.revenue'), 'dollar', '/revenue'],
                'growth' => [t('sidebar.growth'), 'trending-up', '/dashboard/growth'],
            ]],
        ];
        $account = [
            'business_hub' => [$L('بيانات الشركة', 'Company details'), 'briefcase', '/business-hub'],
            '_integrations' => [t('sidebar.integrations'), 'link', '/integrations'],
            '_subscription' => [t('sidebar.subscription'), 'credit-card', '/subscription'],
            '_profile' => [t('sidebar.profile'), 'user', '/profile/settings'],
            'agency' => [t('sidebar.agency'), 'building', '/agency'],
        ];
        if ($isAdmin) {
            $account['_admin'] = [t('sidebar.admin'), 'settings', '/admin'];
        }

        $featureCheck = class_exists('FeatureFlagService') && !empty($this->user['id']) ? new FeatureFlagService() : null;
        $enabledMap = $featureCheck !== null ? $featureCheck->getEnabledMap((int) $this->user['id']) : [];
        $isEnabled = function (string $key) use ($enabledMap): bool {
            return strpos($key, '_') === 0 || !isset($enabledMap[$key]) || (bool) $enabledMap[$key];
        };
        // لو خبير السيو مقفول للعميل ده، مدخل "مركز السيو" يفتح أول تبويب متاح ليه
        if (!$isEnabled('seo_expert')) {
            $hubItem = $steps['google'][2]['seo_expert'];
            unset($steps['google'][2]['seo_expert']);
            foreach ($this->seoHubTabs() as $tab) {
                foreach ($tab[2] as [$href, , $feature]) {
                    if ($feature !== 'seo_expert' && $isEnabled($feature)) {
                        $hubItem[2] = $href;
                        $steps['google'][2] = [$feature => $hubItem] + $steps['google'][2];
                        if ($activeTab === 'seo_expert') {
                            $activeTab = $feature;
                        }
                        break 2;
                    }
                }
            }
        }
        // الخدمات اللي مش في باقة العميل عليها قفل صغير في القائمة
        $lockedServices = [];
        try {
            require_once dirname(__DIR__) . '/Services/Subscription/PlanLock.php';
            $lockedServices = PlanLock::states($this->user ?? []);
        } catch (Throwable $e) {
        }
        $renderLink = function (string $key, array $item, string $groupTitle) use ($activeTab, $lockedServices): string {
            [$label, $icon, $href] = $item;
            $active = $key === $activeTab ? ' active' : '';
            $searchKey = htmlspecialchars($label . ' ' . $groupTitle, ENT_QUOTES, 'UTF-8');
            $iconHtml = icon_svg($icon);
            $svc = $lockedServices ? PlanLock::serviceForPath((string) $href) : null;
            $lock = $svc !== null && isset($lockedServices[$svc]) ? '<span class="nav-lock" title="مش في باقتك" style="margin-inline-start:auto;font-size:11px;opacity:.75">🔒</span>' : '';
            return "<a href=\"{$href}\" class=\"panel-nav-link{$active}\" data-search=\"{$searchKey}\"><span class=\"ic\">{$iconHtml}</span><span class=\"lbl\">{$label}</span>{$lock}</a>";
        };
        $esc = function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

        $progress = $this->journeyProgress();

        $html = '<style>
.tf-step .panel-nav-group-title{display:flex;align-items:center;gap:10px;text-transform:none;letter-spacing:0;font-size:14px;font-weight:600;color:var(--panel-sidebar-text);padding:9px 10px;border-radius:10px}
.tf-step .panel-nav-group-title:hover{background:rgba(127,127,127,.08)}
.tf-step.has-active .panel-nav-group-title{background:rgba(99,102,241,.12)}
.tf-num{flex:0 0 24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:1.5px solid currentColor;opacity:.8}
.tf-step.done .tf-num{background:#22c55e;border-color:#22c55e;color:#fff;opacity:1}
.tf-step.next .tf-num{background:var(--panel-accent,#6366f1);border-color:var(--panel-accent,#6366f1);color:#fff;opacity:1}
.tf-step-txt{display:flex;flex-direction:column;flex:1;min-width:0;line-height:1.25}
.tf-step-sub{font-size:11px;font-weight:400;opacity:.6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tf-next-pill{font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;background:var(--panel-accent,#6366f1);color:#fff}
.tf-step .panel-nav-group-body{padding-inline-start:18px;border-inline-start:1px dashed rgba(127,127,127,.3);margin-inline-start:21px}
.tf-step{margin-bottom:4px}
.tf-progress{margin:4px 10px 12px;font-size:12px;opacity:.85}
.tf-progress-bar{height:6px;border-radius:999px;background:rgba(127,127,127,.2);overflow:hidden;margin-top:6px}
.tf-progress-bar span{display:block;height:100%;background:linear-gradient(90deg,#22c55e,var(--panel-accent,#6366f1))}
</style>';

        // الرئيسية والمساعد (دايمًا فوق)
        $homeHtml = '';
        foreach ($home as $key => $item) {
            if ($isEnabled($key)) {
                $homeHtml .= $renderLink($key, $item, '');
            }
        }
        $html .= '<div class="panel-nav-essentials">' . $homeHtml . '</div>';

        // شريط التقدّم
        $countable = array_filter(array_keys($steps), function ($k) use ($progress) { return array_key_exists($k, $progress); });
        $doneCount = count(array_filter($countable, function ($k) use ($progress) { return !empty($progress[$k]); }));
        $total = count($countable);
        if ($total > 0) {
            $pct = (int) round($doneCount / $total * 100);
            $html .= '<div class="tf-progress">' . $esc($L("جاهزية حسابك: {$doneCount} من {$total} خطوات", "Setup: {$doneCount} of {$total} steps"))
                . '<div class="tf-progress-bar"><span style="width:' . $pct . '%"></span></div></div>';
        }

        $n = 0;
        $nextMarked = false;
        foreach ($steps as $stepKey => [$title, $subtitle, $items]) {
            $body = '';
            $hasActive = false;
            foreach ($items as $key => $item) {
                if (!$isEnabled($key)) {
                    continue;
                }
                $body .= $renderLink($key, $item, $title);
                if ($key === $activeTab) {
                    $hasActive = true;
                }
            }
            if ($body === '') {
                continue; // كل أدوات الخطوة متقفلة للعميل ده
            }
            $n++;
            $done = !empty($progress[$stepKey]);
            $isNext = !$done && !$nextMarked && array_key_exists($stepKey, $progress);
            if ($isNext) {
                $nextMarked = true;
            }
            $cls = 'panel-nav-group tf-step' . ($done ? ' done' : '') . ($isNext ? ' next' : '') . ($hasActive ? ' has-active' : '');
            $html .= '<div class="' . $cls . '" data-group-idx="step-' . $stepKey . '">'
                . '<div class="panel-nav-group-title" role="button" tabindex="0" aria-expanded="true">'
                . '<span class="tf-num">' . ($done ? '✓' : $n) . '</span>'
                . '<span class="tf-step-txt"><span>' . $esc($title) . '</span><span class="tf-step-sub">' . $esc($subtitle) . '</span></span>'
                . ($isNext ? '<span class="tf-next-pill">' . $esc($L('التالي', 'Next')) . '</span>' : '')
                . '<span class="panel-nav-caret">▾</span>'
                . '</div>'
                . '<div class="panel-nav-group-body">' . $body . '</div></div>';
        }

        $accHtml = '';
        foreach ($account as $key => $item) {
            if ($isEnabled($key)) {
                $accHtml .= $renderLink($key, $item, '');
            }
        }
        $html .= '<div class="panel-nav-essentials panel-nav-account">' . $accHtml . '</div>';

        return $html;
    }

    /**
     * هل العميل خلّص كل خطوة؟ (بيانات حقيقية، كاش 5 دقايق في الجلسة عشان
     * ما نعملش استعلامات مع كل صفحة). خطوة مش موجودة في النتيجة = مالهاش ✓.
     * @return array<string,bool>
     */
    protected function journeyProgress(): array
    {
        $uid = (int) ($this->user['id'] ?? 0);
        if ($uid <= 0 || !$this->db) {
            return [];
        }
        $cacheKey = 'tf_journey_' . $uid;
        if (isset($_SESSION[$cacheKey]) && ($_SESSION[$cacheKey]['at'] ?? 0) > time() - 300) {
            return $_SESSION[$cacheKey]['v'];
        }
        $has = function (string $sql) use ($uid): bool {
            try {
                $r = $this->db->query($sql, [$uid]);
                return !empty($r) && (int) (array_values($r[0])[0] ?? 0) > 0;
            } catch (\Throwable $e) {
                return false;
            }
        };
        $v = [
            'site' => $has('SELECT COUNT(*) FROM wo_audits WHERE user_id = ?') || false,
            'google' => $has("SELECT COUNT(*) FROM platform_connections WHERE user_id = ? AND status = 'connected' AND platform LIKE 'google%'"),
            'content' => $has('SELECT COUNT(*) FROM ai_articles WHERE user_id = ?'),
            'reviews' => $has('SELECT (SELECT COUNT(*) FROM reviews WHERE user_id = ?) > 0'),
            'customers' => $has('SELECT COUNT(*) FROM crm_contacts WHERE user_id = ?'),
            'competitors' => $has('SELECT COUNT(*) FROM competitors WHERE user_id = ?'),
        ];
        // "موقعك" بيتحسب خلصان لو عنده موقع + اتعمله تدقيق
        $v['site'] = $v['site'] && $has('SELECT COUNT(*) FROM websites WHERE user_id = ?');
        $_SESSION[$cacheKey] = ['at' => time(), 'v' => $v];
        return $v;
    }

    /**
     * الشِل الكامل لأي صفحة داخل لوحة العميل (نفس تصميم /dashboard تمامًا).
     * الصفحات (AI, السمعة, الشات...) بتمرر بس عنوانها ومحتواها وجافاسكريبت
     * التفاعل بتاعها، وده بيتجنب تكرار كود الـ layout في كل Controller.
     *
     * @param string $activeTab مفتاح العنصر النشط في السايد بار
     * @param string $pageTitle
     * @param string $pageSubtitle
     * @param string $bodyHtml محتوى panel-content (HTML كامل)
     * @param string $scriptJs كود JS بيتنفذ بعد تحميل الصفحة (من غير <script> tags)
     * @return string
     */
    /** صفحات بقت تبويبات جوه صفحة أم (القائمة بتنوّر الأم) */
    private const NAV_PARENT = [
        'ai_chat_followup' => 'ai_chat_inbox', 'ai_chat_knowledge' => 'ai_chat_inbox', 'ai_chat_learning' => 'ai_chat_inbox', 'ai_chat_analytics' => 'ai_chat_inbox',
        'reputation_stats' => 'reputation_overview', 'reputation_intelligence' => 'reputation_overview',
        'revenue_intelligence' => 'revenue', 'analytics' => 'executive', 'activity' => 'executive',
        'marketing_assistant' => 'ai_assistant', 'business_center' => 'business_hub', 'service_center' => '_subscription',
    ];

    /**
     * مجموعات الصفحات المترابطة: شريط تبويبات واحد فوق كل صفحة فيها، بدل ما كل صفحة تبقى مدخل لوحدها في القائمة.
     * @return array<string,array<int,array{0:string,1:string,2:string}>> مجموعة => [[المسار, العنوان, مفتاح الميزة]]
     */
    protected function sectionTabs(): array
    {
        $ar = (function_exists('current_lang') ? current_lang() : 'ar') === 'ar';
        $L = static fn (string $a, string $e): string => $ar ? $a : $e;
        return [
            'reviews' => [
                ['/reputation/reviews', $L('⭐ التقييمات والردود', '⭐ Reviews & replies'), 'reputation'],
                ['/reputation/overview', $L('📊 الملخص', '📊 Summary'), 'reputation_overview'],
                ['/reputation/stats', $L('📈 الإحصائيات', '📈 Statistics'), 'reputation_stats'],
                ['/reputation/intelligence', $L('🧠 التحليل المتقدم', '🧠 Advanced analysis'), 'reputation_intelligence'],
                ['/review-requests', $L('📨 اطلب تقييمات', '📨 Ask for reviews'), 'review_requests'],
            ],
            'chat' => [
                ['/chat', $L('💬 الرسايل', '💬 Inbox'), 'ai_chat_inbox'],
                ['/chat/leads', $L('🎯 العملاء المحتملين', '🎯 Leads'), 'ai_chat_leads'],
                ['/chat/followup-settings', $L('⏰ المتابعة التلقائية', '⏰ Auto follow-up'), 'ai_chat_followup'],
                ['/chat/knowledge-base', $L('📚 معلومات المساعد', '📚 Assistant knowledge'), 'ai_chat_knowledge'],
                ['/chat/learning', $L('✨ تحسين الردود', '✨ Improve replies'), 'ai_chat_learning'],
                ['/chat/analytics', $L('📊 أداء الشات', '📊 Chat performance'), 'ai_chat_analytics'],
                ['/whatsapp-marketing', $L('📣 حملات واتساب', '📣 WhatsApp campaigns'), 'whatsapp_marketing'],
                ['/chat/settings', $L('⚙️ الإعدادات والربط', '⚙️ Settings'), 'ai_chat_settings'],
            ],
            'results' => [
                ['/dashboard/executive', $L('🧭 الملخص التنفيذي', '🧭 Executive summary'), 'executive'],
                ['/revenue', $L('💰 الإيرادات', '💰 Revenue'), 'revenue'],
                ['/revenue/intelligence', $L('🔮 توقعات الإيرادات', '🔮 Revenue forecast'), 'revenue_intelligence'],
                ['/dashboard/growth', $L('🚀 النمو', '🚀 Growth'), 'growth'],
                ['/dashboard/analytics', $L('📈 المؤشرات', '📈 Metrics'), 'analytics'],
                ['/dashboard/activity', $L('🕒 سجل النشاط', '🕒 Activity log'), 'activity'],
            ],
            'assistant' => [
                ['/ai-assistant', $L('✨ المساعد الذكي', '✨ AI assistant'), 'ai_assistant'],
                ['/marketing-assistant', $L('📝 نصوص تسويقية سريعة', '📝 Quick marketing copy'), 'marketing_assistant'],
            ],
            'company' => [
                ['/business-hub', $L('🏢 بيانات الشركة', '🏢 Company details'), 'business_hub'],
                ['/business-center', $L('👥 الفريق ومفاتيح الربط', '👥 Team & API keys'), 'business_center'],
                ['/service-center', $L('🧩 حالة الخدمات', '🧩 Service status'), 'service_center'],
            ],
        ];
    }

    protected function sectionTabsHtml(): string
    {
        $path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $path = $path === '/chat/pending' ? '/chat' : $path;
        $flags = class_exists('FeatureFlagService') && !empty($this->user['id']) ? (new FeatureFlagService())->getEnabledMap((int) $this->user['id']) : [];
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        foreach ($this->sectionTabs() as $tabs) {
            if (!in_array($path, array_column($tabs, 0), true)) {
                continue;
            }
            $tabs = array_values(array_filter($tabs, static fn ($t) => !isset($flags[$t[2]]) || (bool) $flags[$t[2]]));
            if (count($tabs) < 2) {
                return '';
            }
            $h = '<nav class="tf-sec-tabs" aria-label="sections" style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid rgba(127,127,127,.22)">';
            foreach ($tabs as [$href, $label]) {
                $on = $href === $path;
                $h .= '<a href="' . $esc($href) . '" class="p-btn ' . ($on ? 'primary' : 'outline') . ' xs"' . ($on ? ' aria-current="page"' : '') . '>' . $esc($label) . '</a>';
            }
            return $h . '</nav>';
        }
        return '';
    }

    /** تبويبات موحّدة لصفحات المنافسين التلاتة (كانوا 3 مداخل منفصلة ومتداخلة في القائمة) */
    protected function competitorTabsHtml(string $active): string
    {
        $ar = (function_exists('current_lang') ? current_lang() : 'ar') === 'ar';
        $tabs = [
            'competitor_intelligence' => ['/competitor-intelligence', $ar ? '🛰 المراقبة التلقائية' : '🛰 Auto monitoring', $ar ? 'تغييرات المواقع والأسعار والترتيب - بتتجدد لوحدها' : 'Site, price and ranking changes - updated automatically'],
            'ai_competitors' => ['/ai/competitors', $ar ? '🤖 تحليل بالذكاء الاصطناعي' : '🤖 AI analysis', $ar ? 'نقاط قوتهم وضعفهم ومقارنتهم بيك' : 'Their strengths, weaknesses and how you compare'],
            'competitor_monitoring' => ['/competitor-monitoring', $ar ? '🏷 أسعار وعروض (يدوي)' : '🏷 Prices & offers (manual)', $ar ? 'سجّل أسعارهم بنفسك لما تشوفها' : 'Log their prices yourself'],
        ];
        $h = '<div class="ci-hub-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px">';
        foreach ($tabs as $k => [$url, $label, $hint]) {
            $on = $k === $active;
            $h .= '<a href="' . $url . '" title="' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '" class="p-btn ' . ($on ? 'primary' : 'outline') . ' xs"' . ($on ? ' aria-current="page"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        return $h . '</div>';
    }

    /**
     * مركز السيو: 13 صفحة كانت متفرقة في القائمة بقوا 5 تبويبات، وكل تبويب جواه روابط صفحاته.
     * @return array<string,array{0:string,1:string,2:array<int,array{0:string,1:string,2:string}>}> مفتاح => [أيقونة, عنوان, [[رابط, عنوان, مفتاح الميزة]]]
     */
    protected function seoHubTabs(): array
    {
        $ar = (function_exists('current_lang') ? current_lang() : 'ar') === 'ar';
        $L = static fn (string $a, string $e): string => $ar ? $a : $e;
        return [
            'overview' => ['🏠', $L('نظرة عامة', 'Overview'), [
                ['/seo-expert', $L('الملخص والمطلوب منك', 'Summary and your to-dos'), 'seo_expert'],
                ['/seo-expert?page=results', $L('النتايج (قبل وبعد)', 'Results (before and after)'), 'seo_expert'],
            ]],
            'audit' => ['🔍', $L('الفحص والخطة', 'Audit and plan'), [
                ['/seo-expert?page=plan', $L('خطة التحسين', 'Improvement plan'), 'seo_expert'],
                ['/seo-expert?page=scan', $L('ابدأ فحص جديد', 'Start a new audit'), 'seo_expert'],
                ['/website-optimizer', $L('فحص سريع للموقع', 'Quick site audit'), 'website_optimizer'],
                ['/ai/analyze', $L('تحليل صفحة واحدة', 'Analyze one page'), 'ai_analyze'],
                ['/ai/reports', $L('التقارير السابقة', 'Past reports'), 'ai_reports'],
            ]],
            'keywords' => ['🔑', $L('الكلمات والمنافسين', 'Keywords and competitors'), [
                ['/ai/keywords', $L('الكلمات وترتيبك', 'Keywords and rankings'), 'ai_keywords'],
                ['/outreach', $L('الروابط الخارجية (باك لينك)', 'Backlinks'), 'outreach'],
                ['/competitor-intelligence', $L('مركز المنافسين', 'Competitor hub'), 'competitor_intelligence'],
            ]],
            'content' => ['✍️', $L('المحتوى والتنفيذ', 'Content and publishing'), [
                ['/content-calendar', $L('خطة المقالات', 'Article plan'), 'content_calendar'],
                ['/seo-content', $L('حملات المحتوى', 'Content campaigns'), 'seo_content'],
                ['/ai/articles', $L('المقالات', 'Articles'), 'ai_articles'],
                ['/auto-seo', $L('التنفيذ التلقائي على الموقع', 'Auto publishing to your site'), 'auto_seo'],
                ['/seo-expert?page=execute', $L('تعديل صفحات ووردبريس', 'Edit WordPress pages'), 'seo_expert'],
            ]],
            // البحث المحلي واللغات كانوا بيتفتحوا من غير تبويبات المركز فالعميل مكانش عارف يرجع
            'local' => ['📍', $L('المحلي واللغات', 'Local and languages'), [
                ['/local-seo', $L('بياناتك والخرائط', 'Listings and maps'), 'seo_expert'],
                ['/international-seo', $L('اللغات والفهرسة', 'Languages and indexing'), 'seo_expert'],
            ]],
            'ai' => ['✨', $L('الظهور في الذكاء الاصطناعي', 'AI visibility'), [
                ['/ai-visibility', $L('ظهورك في الإجابات', 'Your visibility in answers'), 'ai_visibility'],
                ['/ai-insights', $L('المصادر والانطباع', 'Sources and sentiment'), 'ai_insights'],
            ]],
        ];
    }

    /** @return array{0:string,1:string}|null [التبويب, الرابط] لو الصفحة الحالية من صفحات مركز السيو */
    protected function seoHubActive(): ?array
    {
        $path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($path === '/seo-expert') {
            $page = (string) ($_GET['page'] ?? '');
            $page = in_array($page, ['results', 'plan', 'scan', 'execute', 'tours', 'settings'], true) ? $page : '';
            if ($page === 'tours') {
                return ['content', '/seo-expert?page=tours'];
            }
            if ($page === 'settings') {
                return ['settings', '/seo-expert?page=settings'];
            }
            $path .= $page !== '' ? '?page=' . $page : '';
        } elseif (preg_match('#^/ai/report/\d+#', $path)) {
            $path = '/ai/reports';
        } elseif (preg_match('#^/ai/keywords#', $path)) {
            $path = '/ai/keywords';
        }
        // المقالات والمنافسين ليهم مكانهم في القائمة؛ بيظهروا في المركز كروابط بس
        if (in_array($path, ['/ai/articles', '/competitor-intelligence'], true)) {
            return null;
        }
        foreach ($this->seoHubTabs() as $key => $tab) {
            foreach ($tab[2] as $link) {
                if ($link[0] === $path) {
                    return [$key, $path];
                }
            }
        }
        return null;
    }

    /** شريط تبويبات مركز السيو (فوق كل صفحة سيو) + روابط التبويب المفتوح + زرار الإعدادات */
    protected function seoHubTabsHtml(): string
    {
        $active = $this->seoHubActive();
        if ($active === null) {
            return '';
        }
        $ar = (function_exists('current_lang') ? current_lang() : 'ar') === 'ar';
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $flags = class_exists('FeatureFlagService') && !empty($this->user['id']) ? (new FeatureFlagService())->getEnabledMap((int) $this->user['id']) : [];
        $on = static fn (string $k): bool => !isset($flags[$k]) || (bool) $flags[$k];
        $site = (int) ($_GET['website_id'] ?? 0);
        $withSite = static function (string $href) use ($site): string {
            if ($site <= 0 || !preg_match('#^/(seo-expert|ai/keywords|ai-visibility|ai-insights|local-seo)#', $href)) {
                return $href;
            }
            return $href . (str_contains($href, '?') ? '&' : '?') . 'website_id=' . $site;
        };
        $main = '';
        $sub = '';
        foreach ($this->seoHubTabs() as $key => [$icon, $label, $links]) {
            $links = array_values(array_filter($links, static fn ($l) => $on($l[2])));
            if (!$links) {
                continue;
            }
            $isOn = $key === $active[0];
            $main .= '<a href="' . $esc($withSite($links[0][0])) . '" class="seo-hub-tab' . ($isOn ? ' on' : '') . '"' . ($isOn ? ' aria-current="page"' : '') . '><span aria-hidden="true">' . $icon . '</span> ' . $esc($label) . '</a>';
            if ($isOn && count($links) > 1) {
                foreach ($links as [$href, $text]) {
                    $here = $href === $active[1];
                    $sub .= '<a href="' . $esc($withSite($href)) . '" class="p-btn xs ' . ($here ? 'primary' : 'outline') . '"' . ($here ? ' aria-current="page"' : '') . '>' . $esc($text) . '</a>';
                }
            }
        }
        $gearOn = $active[0] === 'settings';
        $gear = $on('seo_expert') ? '<a href="' . $esc($withSite('/seo-expert?page=settings')) . '" class="seo-hub-tab seo-hub-gear' . ($gearOn ? ' on' : '') . '" title="' . $esc($ar ? 'ربط الموقع وجوجل وبيانات الشركة' : 'Website, Google and company settings') . '"><span aria-hidden="true">⚙️</span> ' . $esc($ar ? 'الإعدادات' : 'Settings') . '</a>' : '';
        return '<style>.seo-hub{margin:0 0 18px}.seo-hub-main{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid rgba(127,127,127,.25);padding-bottom:8px}'
            . '.seo-hub-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border-radius:10px;font-weight:600;font-size:14px;color:inherit;text-decoration:none;opacity:.8}'
            . '.seo-hub-tab:hover{background:rgba(127,127,127,.1);opacity:1}.seo-hub-tab.on{background:var(--panel-accent,#6366f1);color:#fff;opacity:1}'
            . '.seo-hub-gear{margin-inline-start:auto}.seo-hub-sub{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}'
            . '@media(max-width:700px){.seo-hub-main{flex-wrap:nowrap;overflow-x:auto}.seo-hub-tab{white-space:nowrap}.seo-hub-gear{margin-inline-start:0}}</style>'
            . '<nav class="seo-hub" aria-label="' . $esc($ar ? 'مركز السيو' : 'SEO hub') . '"><div class="seo-hub-main">' . $main . $gear . '</div>'
            . ($sub !== '' ? '<div class="seo-hub-sub">' . $sub . '</div>' : '') . '</nav>';
    }

    protected function renderPanelPage(string $activeTab, string $pageTitle, string $pageSubtitle, string $bodyHtml, string $scriptJs = ''): string
    {
        // تصحيح مهم: الصفحات دي فيها بيانات مستخدم شخصية (شات/تقارير/إعدادات)
        // ومحتاجة تفضل ديناميكية دايمًا. من غير الهيدرز دي، أي كاش على
        // مستوى السيرفر (زي LiteSpeed LSCache اللي شفناه في اللوج) ممكن
        // يخزّن نسخة قديمة أو حتى (في أسوأ الحالات) يوريها لمستخدم تاني.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
            header('Pragma: no-cache');
            header('X-LiteSpeed-Cache-Control: no-cache');
        }

        // التحكم في الميزات: فحص مركزي واحد هنا بيغطي كل صفحات اللوحة
        // تلقائيًا - لو الأدمن عطّل الميزة دي (عام أو لعميل بعينه)،
        // نستبدل المحتوى برسالة واضحة بدل ما نعرض الصفحة، مع الحفاظ
        // على القائمة الجانبية والشريط العلوي عاديين.
        if (!empty($this->user['id']) && class_exists('FeatureFlagService')) {
            $featureCheck = new FeatureFlagService();
            if (!$featureCheck->isEnabled($activeTab, (int) $this->user['id'])) {
                $bodyHtml = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🔒</div>'
                    . $this->tr('feature.disabled_message') . '</div></div>';
                $scriptJs = '';
            }
            // رابط تسويق البريد في الشريط العلوي: بيظهر لكل المشتركين
            // طالما الميزة مفعّلة (افتراضيًا متاحة لو مش مسجّلة في feature_flags).
            $emailMarketingHeader = $featureCheck->isEnabled('email_marketing', (int) $this->user['id']);
        } else {
            $emailMarketingHeader = true;
        }

        // الخدمة مش في باقة العميل: الصفحة بتظهر متغبّشة وعليها «اشترك دلوقتي»
        if (!empty($this->user['id'])) {
            try {
                require_once dirname(__DIR__) . '/Services/Subscription/PlanLock.php';
                $planLock = PlanLock::check($this->user, (string) ($_SERVER['REQUEST_URI'] ?? ''));
                if ($planLock !== null) {
                    $bodyHtml = PlanLock::wrap($bodyHtml, $planLock, html_entity_decode($pageTitle, ENT_QUOTES, 'UTF-8'));
                }
            } catch (Throwable $e) {
            }
        }

        $customerReadinessAssets = '';
        if (!in_array($this->user['role'] ?? '', ['admin','super_admin'], true) && in_array($activeTab, ['overview','executive','business_hub','business_center','_integrations','service_center','automation'], true)) {
            $full = $activeTab === 'service_center' ? 'false' : 'true';
            $bodyHtml = '<section class="customer-readiness" data-customer-readiness data-compact="'.$full.'" aria-label="Website readiness" aria-live="polite">Loading readiness…</section>'.$bodyHtml;
            $customerReadinessAssets = '<link rel="stylesheet" href="'.asset_v('/assets/css/customer-readiness.css').'"><script src="'.asset_v('/assets/js/customer-readiness.js').'"></script>';
        }
        // خدمات بتشتغل على موقع العميل: من غير موقع كانت بتقول «اختار موقع من القائمة اللي فوق» ومفيش قائمة.
        // كارت واحد واضح بيقول هتعمل إيه (ضيف رابط موقعك أو ابنِ موقع حجز) بدل رسايل مختلفة في كل صفحة.
        if (!empty($this->user['id']) && !in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true) && empty($planLock)) {
            $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
            $needsSite = ['/seo-expert', '/gbp-content', '/ai/articles', '/ai/analyze', '/website-optimizer', '/chat', '/business-hub', '/competitor-intelligence', '/reputation', '/review-requests', '/social', '/dashboard/growth', '/auto-seo', '/seo-'];
            $match = false;
            foreach ($needsSite as $prefix) {
                if ($path === $prefix || strpos($path, rtrim($prefix, '-') === $prefix ? $prefix . '/' : $prefix) === 0) {
                    $match = true;
                    break;
                }
            }
            if ($match) {
                try {
                    $owner = class_exists('TeamAccess') ? TeamAccess::billingOwner($this->user) : (!empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id']);
                    $db = Database::getInstance();
                    $sites = (int) ($db->query('SELECT COUNT(*) AS c FROM websites WHERE user_id = ?', [$owner])[0]['c'] ?? 0);
                    if ($sites === 0) {
                        try {
                            $sites = (int) ($db->query('SELECT COUNT(*) AS c FROM generated_websites WHERE user_id = ?', [$owner])[0]['c'] ?? 0);
                        } catch (Throwable $e) {
                        }
                    }
                    if ($sites === 0) {
                        $bodyHtml = '<div class="p-card tf-need-site" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:16px;border:1px solid rgba(37,99,235,.35)">'
                            . '<div style="font-size:30px" aria-hidden="true">🌐</div>'
                            . '<div style="flex:1;min-width:240px"><b style="font-size:15px">الخدمة دي بتشتغل على موقعك</b>'
                            . '<div class="p-cell-muted" style="margin-top:4px;line-height:1.7">ضيف رابط موقعك الحالي، أو لو معندكش موقع ابنِ موقع حجز جاهز. أول ما الموقع يتضاف، الخدمة دي بتشتغل عليه على طول.</div></div>'
                            . '<div style="display:flex;gap:8px;flex-wrap:wrap"><a class="p-btn primary" href="/websites">ضيف رابط موقعك</a><a class="p-btn outline" href="/website-builder">ابنِ موقع حجز</a></div>'
                            . '</div>' . $bodyHtml;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        // شريط "أكّد بريدك الإلكتروني" (أو رسالة نجاح بعد التأكيد)
        $verifyBanner = '';
        if (!empty($this->user['id']) && class_exists('EmailVerification')) {
            try {
                $verifyBanner = EmailVerification::bannerHtml($this->user);
            } catch (Throwable $e) {
                $verifyBanner = '';
            }
        }
        if ($verifyBanner === '' && ($_GET['verified'] ?? '') === '1') {
            $verifyBanner = '<div role="status" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;background:rgba(14,159,110,.12);border:1px solid rgba(14,159,110,.4);font-size:14px">✅ تم تأكيد بريدك الإلكتروني بنجاح.</div>';
        }
        $bodyHtml = $verifyBanner . $bodyHtml;

        // عملة النشاط (مركز الأعمال) للأرقام المالية في الصفحات - بدل $ ثابتة
        $tfCurrency = 'USD';
        if (!empty($this->user['id'])) {
            try {
                $ownerId = class_exists('TeamAccess') ? TeamAccess::billingOwner($this->user) : (!empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id']);
                $cur = Database::getInstance()->query('SELECT default_currency FROM businesses WHERE owner_user_id = ? AND default_currency IS NOT NULL ORDER BY id LIMIT 1', [$ownerId]);
                if (!empty($cur[0]['default_currency']) && preg_match('/^[A-Z]{3}$/', strtoupper((string) $cur[0]['default_currency']))) {
                    $tfCurrency = strtoupper((string) $cur[0]['default_currency']);
                }
            } catch (Throwable $e) {
            }
        }
        $bodyHtml = '<script>window.TF_CURRENCY=' . json_encode($tfCurrency) . ';</script>' . $bodyHtml;

        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $companyName = htmlspecialchars($this->user['company_name'] ?? t('sidebar.profile'), ENT_QUOTES, 'UTF-8');
        $userEmail = htmlspecialchars($this->user['email'] ?? '', ENT_QUOTES, 'UTF-8');
        $userInitial = htmlspecialchars(mb_substr($companyName, 0, 1), ENT_QUOTES, 'UTF-8');
        $navHtml = $this->renderPanelSidebar($activeTab);
        if (in_array($activeTab, ['competitor_intelligence', 'competitor_monitoring', 'ai_competitors'], true)) {
            $bodyHtml = $this->competitorTabsHtml($activeTab) . $bodyHtml;
        }
        $bodyHtml = $this->seoHubTabsHtml() . $this->sectionTabsHtml() . $bodyHtml;
        // بعض الصفحات بتبعت العنوان متعمله escape بالفعل (tr())، فكان بيظهر
        // "Today&#039;s" - بنفك الأول عشان الـ escape يحصل مرة واحدة بس.
        $pageTitleSafe = htmlspecialchars(html_entity_decode($pageTitle, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $pageSubtitleSafe = htmlspecialchars(html_entity_decode($pageSubtitle, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $lang = current_lang();
        $dir = current_dir();

        $langMenu = '';
        foreach (language_switcher_links() as $l) {
            $activeClass = $l['active'] ? ' on' : '';
            $langMenu .= "<a href=\"{$l['url']}\" class=\"{$activeClass}\">{$l['label']}</a>";
        }

        // تصحيح باغ فادح: {asset_v('/path')} جوه heredoc مبيتفسّرش من PHP
        // خالص (الصياغة دي بتشتغل بس لمتغيرات زي {$var}، مش لاستدعاء
        // دالة مباشر) - كان بيطبع النص حرفيًا فيكسر كل روابط CSS/JS في
        // الموقع كله. الحل: نحسب القيمة في متغير الأول ونستخدم المتغير.
        $styleCssUrl = asset_v('/assets/css/style.css');
        $panelCssUrl = asset_v('/assets/css/panel.css');
        $panelJsUrl = asset_v('/assets/js/panel.js');
        // الواجهة العربية: ترجمة النصوص الإنجليزي اللي بتترسم بالـ JavaScript
        // وباقي اللغات: العكس - النصوص العربي اللي في ملفات الجافاسكريبت وبيانات الـ API (السيرفر بيترجم الصفحة نفسها بس)
        $arTranslateTags = (function_exists('current_lang') && current_lang() === 'ar')
            ? '    <script src="' . asset_v('/assets/i18n/ar-dict.js') . '"></script>' . "\n"
                . (is_file(dirname(__DIR__, 2) . '/public_html/assets/i18n/ar-dict-extra.js') ? '    <script src="' . asset_v('/assets/i18n/ar-dict-extra.js') . '"></script>' . "\n" : '')
                . '    <script src="' . asset_v('/assets/js/ar-translate.js') . '"></script>' . "\n"
            : (is_file(dirname(__DIR__, 2) . '/public_html/assets/i18n/en-dict.js')
                ? '    <script src="' . asset_v('/assets/i18n/en-dict.js') . '"></script>' . "\n" . '    <script src="' . asset_v('/assets/js/en-translate.js') . '"></script>' . "\n"
                : '');
        $tfThemeCssUrl = asset_v('/assets/css/tf-theme.css');
        $tfThemeJsUrl = asset_v('/assets/js/tf-theme.js');
        // وحدة الشات: طبقة مكوّنات احترافية (chat.css + chat-panel.js)
        // بتتحقن بس لما الصفحة بتاعة الشات - باقي اللوحة مبيتأثرش بيها.
        $chatAssetsHead = '';
        $chatAssetsFoot = '';
        if ($activeTab === 'chat') {
            $chatAssetsHead = '    <link rel="stylesheet" href="' . asset_v('/assets/css/chat.css') . '">' . "\n";
            $chatAssetsFoot = '    <script src="' . asset_v('/assets/js/chat-panel.js') . '"></script>' . "\n";
        }
        $isChatModule = $activeTab === 'chat' || strpos((string) $activeTab, 'ai_chat') === 0 || $activeTab === 'whatsapp_marketing';
        $bodyTabClass = $isChatModule ? ' tab-chat' : '';
        if ($isChatModule) {
            $chatAssetsHead .= '    <link rel="stylesheet" href="' . asset_v('/assets/css/chat-wa.css') . '">' . "
";
            $chatAssetsFoot .= '    <script src="' . asset_v('/assets/js/ogg-opus.js') . '"></script>' . "
";
            $chatAssetsFoot .= '    <script src="' . asset_v('/assets/js/chat-wa.js') . '"></script>' . "
";
        }
        // نفس باغ asset_v بالظبط - site_brand_html() لازم يتحسب في متغير
        $brandHtml = site_brand_html();
        // تخصيص الوكالات (White-Label): حقن ألوان براندنج الوكالة كـ CSS
        // variables على :root (بتورّث على --primary-color في style.css و
        // --panel-accent في panel.css فبتلوّن كل عناصر اللوحة) + أي
        // custom_css مخزّن للوكالة. واي فافيكون مخصّص للوكالة.
        $brandingCss = '';
        $faviconHtml = site_favicon_html();
        if (function_exists('current_user_agency_branding')) {
            $agencyBranding = current_user_agency_branding();
            if ($agencyBranding) {
                $cssVars = '';
                if (!empty($agencyBranding['primary_color'])) {
                    $primary = htmlspecialchars($agencyBranding['primary_color'], ENT_QUOTES, 'UTF-8');
                    $cssVars .= "--primary-color:{$primary};--panel-accent:{$primary};--agency-accent:{$primary};";
                }
                if (!empty($agencyBranding['secondary_color'])) {
                    $secondary = htmlspecialchars($agencyBranding['secondary_color'], ENT_QUOTES, 'UTF-8');
                    $cssVars .= "--secondary-color:{$secondary};";
                }
                if ($cssVars !== '') {
                    $brandingCss = '<style>:root{' . $cssVars . '}</style>';
                }
                if (!empty($agencyBranding['custom_css'])) {
                    $brandingCss .= "\n<style>" . $agencyBranding['custom_css'] . '</style>';
                }
            }
        }
        // Snippets خدمات الطرف الثالث اللي بتشتغل في المتصفح (Hotjar/
        // Contentsquare، Mixpanel، OneSignal، Calendly) - بتتحقن بس للمفعّل
        // منها. متحسبة في متغير قبل الـ heredoc (نفس درس asset_v).
        $thirdPartyHead = class_exists('ThirdPartyHead') ? ThirdPartyHead::render((int) ($this->user['id'] ?? 0)) : '';
        // رابط تسويق البريد في الشريط العلوي (مبني قبل الـ heredoc لأن
        // صيغة {if} مش بتتفسّر جواه - نفس درس asset_v).
        $emailMarketingLinkHtml = $emailMarketingHeader
            ? '<a href="/email-marketing" class="icon-btn" title="' . $this->tr('sidebar.email_marketing') . '">' . icon_svg('mail') . '</a>'
            : '';
        // نفس درس asset_v: استدعاءات icon_svg() جوه الـ heredoc مبيتفسّرش
        // من PHP (الصياغة {$var} بتشتغل لمتغيرات بس، مش لاستدعاءات دوال)
        // - لازم نحسب الـ SVG في متغيرات الأول وبعدين نستخدمها.
        $iconLogout = icon_svg('log-out');
        $iconMenu = icon_svg('menu');
        // صفحة أدمن بنفس الشِل: الشريط العلوي من غير أدوات العميل (المحفظة، الرصيد، الموقع، تحليل جديد)
        $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $topbarClass = (in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true) && preg_match('#^/admin(/|$)#', $reqPath) && empty($_SESSION['admin_customer_view'])) ? 'panel-topbar tf-admin-top' : 'panel-topbar';
        $iconDollar = icon_svg('dollar', 15);
        $iconZap = icon_svg('zap', 15);
        $iconBell = icon_svg('bell');
        $iconGlobe = icon_svg('globe');
        $iconSparkles = icon_svg('sparkles');
        $iconUser = icon_svg('user');

        // زرار «محتاج مساعدة؟» عائم: بيفتح واتساب دعم Tourfecto ومعاه اسم الصفحة اللي العميل فيها (لو رقم الدعم متسجّل)
        $helpFab = '';
        $supportWa = function_exists('support_whatsapp_number') ? preg_replace('/\D/', '', (string) support_whatsapp_number()) : '';
        if ($supportWa !== '' && empty($_SESSION['admin_customer_view'])) {
            $isAr = str_starts_with((string) $lang, 'ar');
            $helpMsg = ($isAr ? 'أهلاً، محتاج مساعدة في صفحة «' : 'Hi, I need help with the "') . (!$isAr && function_exists('tf_autotranslate_text') ? tf_autotranslate_text(strip_tags($pageTitle)) : strip_tags($pageTitle)) . ($isAr ? '» على Tourfecto' : '" page on Tourfecto');
            $helpFab = '<a class="tf-help-fab" href="https://wa.me/' . $supportWa . '?text=' . rawurlencode($helpMsg) . '" target="_blank" rel="noopener" aria-label="' . ($isAr ? 'محتاج مساعدة؟' : 'Need help?') . '">'
                . '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2.1 1-2.4c.3-.3.6-.3.8-.3h.6c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .6l-.4.6-.4.5c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.7-.1l1-1.2c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.5.4.1.1.1.8-.1 1.4Z"/></svg>'
                . '<span>' . ($isAr ? 'محتاج مساعدة؟' : 'Need help?') . '</span></a>'
                . '<style>.tf-help-fab{position:fixed;bottom:18px;inset-inline-end:18px;z-index:900;display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:999px;background:#16a34a;color:#fff!important;font-weight:700;font-size:14px;text-decoration:none;box-shadow:0 8px 24px rgba(0,0,0,.18);transition:transform .15s}.tf-help-fab:hover{transform:translateY(-2px)}@media(max-width:640px){.tf-help-fab span{display:none}.tf-help-fab{padding:12px;bottom:76px}}@media print{.tf-help-fab{display:none}}#mainContent{padding-bottom:84px}</style>';
        }
        $internationalLocaleJson=json_encode(['language'=>$lang,'timezone'=>$_SESSION['user']['timezone']??'UTC','currency'=>$_SESSION['user']['currency']??'USD'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $csrfMeta = class_exists('Csrf') ? htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') : '';
        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{$csrfMeta}">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#F4F5F8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{$pageTitleSafe} | {$appName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script>(function(){try{var t=JSON.parse(localStorage.getItem('ap.theme'));if(t!=='dark'&&t!=='light'){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    {$faviconHtml}
    <link rel="stylesheet" href="{$styleCssUrl}">
    <link rel="stylesheet" href="{$panelCssUrl}">
    <link rel="stylesheet" href="{$tfThemeCssUrl}">
{$brandingCss}{$thirdPartyHead}{$chatAssetsHead}</head>
<body class="ap-theme{$bodyTabClass}">
    <div class="panel-shell">
        <div class="panel-overlay-bg"></div>
        <aside class="panel-sidebar">
            <div class="panel-brand">
                {$brandHtml}
            </div>
            <div class="panel-user-mini">
                <div class="avatar">{$userInitial}</div>
                <div class="info">
                    <div class="name">{$companyName}</div>
                    <div class="role">{$userEmail}</div>
                </div>
            </div>
            <nav class="panel-nav">
                <input type="search" class="panel-nav-search" id="panelNavSearch" placeholder="بحث في القائمة" autocomplete="off" aria-label="بحث في القائمة">
                {$navHtml}
            </nav>
            <div class="panel-sidebar-footer">
                <a href="/logout">{$iconLogout} {$this->tr('nav.logout')}</a>
            </div>
        </aside>

        <div class="panel-main">
            <header class="{$topbarClass}">
                <button class="panel-menu-toggle" id="panelMenuToggle" type="button" aria-label="قائمة التنقل" aria-expanded="false">{$iconMenu}</button>
                <div>
                    <h1>{$pageTitleSafe}</h1>
                    <div class="subtitle">{$pageSubtitleSafe}</div>
                </div>
                <div class="panel-topbar-spacer"></div>
                <div class="panel-topbar-actions">
                    <select id="panelWebsiteSelect" class="panel-website-select" style="display:none;" title="{$this->tr('website_context.tooltip')}"></select>
                    <a href="/subscription" id="panelWalletWrap" class="panel-credits" style="display:none;" title="{$this->tr('wallet.tooltip')}">
                        <span>{$iconDollar}</span>
                        <span id="panelWalletText"></span>
                    </a>
                    <a href="/subscription" id="panelCreditsWrap" class="panel-credits" style="display:none;" title="{$this->tr('credits.tooltip')}">
                        <span id="panelCreditsIcon">{$iconZap}</span>
                        <span id="panelCreditsText"></span>
                    </a>
                    <div class="panel-notif" id="panelNotifWrap">
                        <button class="icon-btn" id="panelNotifBtn" title="الإشعارات" style="position:relative;">
                            {$iconBell}<span id="panelNotifBadge" class="panel-notif-badge" style="display:none;">0</span>
                        </button>
                        <div class="panel-notif-menu" id="panelNotifMenu" style="display:none;"></div>
                    </div>
                    <details class="panel-langsel">
                        <summary class="icon-btn" title="{$this->tr('lang.switch')}">{$iconGlobe}</summary>
                        <div class="panel-langsel-menu">{$langMenu}</div>
                    </details>
                    <a href="/ai/analyze" class="icon-btn" title="{$this->tr('dashboard.action.new_seo_analysis')}">{$iconSparkles}</a>
                    {$emailMarketingLinkHtml}
                    <a href="/profile/settings" class="icon-btn" title="{$this->tr('sidebar.profile')}">{$iconUser}</a>
                </div>
            </header>

            <main class="panel-content" id="mainContent">
                {$bodyHtml}
            </main>
        </div>
    </div>

    <div id="toastStack"></div>

    <script>window.I18N = {$this->i18nJson()};</script>
    <script src="/assets/vendor/chart-4.4.0.umd.js"></script>
    <script src="{$panelJsUrl}"></script>
{$customerReadinessAssets}
    <script src="{$tfThemeJsUrl}"></script>
{$chatAssetsFoot}    <script>{$scriptJs}</script>
{$arTranslateTags}
<style>.tf-admin-top #panelWalletWrap,.tf-admin-top #panelCreditsWrap,.tf-admin-top #panelWebsiteSelect,.tf-admin-top a[href="/ai/analyze"],.tf-admin-top a[href="/email-marketing"],.tf-admin-top #panelNotifWrap{display:none!important}</style>
<button id="pwaInstallBtn" class="pwa-install-fab" type="button" aria-label="تثبيت التطبيق" title="تثبيت التطبيق">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span>تثبيت التطبيق</span>
</button>
<style>
.pwa-install-fab {
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: 9999;
    display: none;
    align-items: center;
    gap: 8px;
    background: var(--primary-color, #0077be);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 12px 18px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0, 119, 190, .35);
    transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
}
.pwa-install-fab:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(0, 119, 190, .45);
}
.pwa-install-fab svg { flex-shrink: 0; }
@media (max-width: 480px) {
    .pwa-install-fab span { display: none; }
    .pwa-install-fab { padding: 14px; border-radius: 50%; bottom: 18px; left: 18px; }
}
</style>
<script>
(function () {
    var btn = document.getElementById('pwaInstallBtn');
    if (!btn) return;
    var deferredPrompt = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    if (isStandalone()) return;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        btn.style.display = 'flex';
    });

    btn.addEventListener('click', function () {
        if (!deferredPrompt) return;
        btn.style.display = 'none';
        var promptEvent = deferredPrompt;
        deferredPrompt = null;
        promptEvent.prompt();
        promptEvent.userChoice.then(function () {});
    });

    window.addEventListener('appinstalled', function () {
        btn.style.display = 'none';
        deferredPrompt = null;
    });
})();
</script>
{$helpFab}
<script>window.TourfectoLocale={$internationalLocaleJson};</script>
<script src="/assets/js/international-settings.js"></script>
</body>
</html>
HTML;
    }
}
