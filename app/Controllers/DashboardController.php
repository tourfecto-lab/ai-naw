<?php

/**
 * Tourfecto - Dashboard Controller
 * متحكم لوحة التحكم والإحصائيات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class DashboardController extends Controller
{
    /**
     * @var SubscriptionValidator $subscription - مدقق الاشتراكات
     */
    private $subscription;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->subscription = new SubscriptionValidator();
    }

    /**
     * GET /api/dashboard/smart-insights - اقتراحات ذكية بتجمع بيانات من
     * كذا نظام مختلف (شات، محفظة، مواقع مولّدة، سمعة، اشتراك) في قائمة
     * أولويات واحدة، بدل ما تكون كل ميزة منفصلة عن التانية.
     */
    public function smartInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        $insights = [];

        // 1) رسائل شات محتاجة رد (في انتظار الموافقة) - نفس الدالة
        // الجاهزة والمُتحقّق منها المستخدمة في شارة الشات الأخرى بالفعل
        try {
            $count = class_exists('ChatMessage') ? ChatMessage::getUnreadCount($userId) : 0;
            if ($count > 0) {
                $insights[] = [
                    'icon' => '💬', 'priority' => 1,
                    'title' => "عندك {$count} رسالة شات محتاجة موافقة",
                    'action_url' => '/chat', 'action_label' => 'روح للشات',
                ];
            }
        } catch (Exception $e) {
        }

        // 2) رصيد محفظة منخفض
        try {
            if (class_exists('WalletService')) {
                $balance = (new WalletService())->getBalance($userId);
                if ($balance > 0 && $balance < 5) {
                    $insights[] = [
                        'icon' => '💰', 'priority' => 2,
                        'title' => 'رصيد محفظتك منخفض ($' . number_format($balance, 2) . ') - اشحن عشان الميزات متتوقفش',
                        'action_url' => '/subscription', 'action_label' => 'اشحن المحفظة',
                    ];
                }
            }
        } catch (Exception $e) {
        }

        // 3) مواقع مولّدة لسه مسوّدة (مش منشورة)
        try {
            if (class_exists('GeneratedWebsite')) {
                $drafts = (new GeneratedWebsite())->where(['user_id' => $userId, 'status' => 'draft']);
                if (!empty($drafts)) {
                    $insights[] = [
                        'icon' => '🏗️', 'priority' => 1,
                        'title' => (function_exists('current_lang') && !str_starts_with(current_lang(), 'ar'))
                            ? 'You have ' . count($drafts) . ' website' . (count($drafts) > 1 ? 's' : '') . ' still in draft - publish so your customers can find you'
                            : 'عندك ' . count($drafts) . ' موقع لسه مسوّدة - انشره عشان عملاءك يلاقوه',
                        'action_url' => '/website-builder', 'action_label' => 'شوف المواقع',
                    ];
                }
            }
        } catch (Exception $e) {
        }

        // 4) اشتراك قرّب ينتهي
        try {
            $sub = Subscription::activeSubscriptionRow($userId);
            if ($sub && ($sub['status'] ?? '') === 'trialing' && !empty($sub['trial_ends_at'])) {
                $hoursLeft = max(0, (int) ceil((strtotime((string) $sub['trial_ends_at']) - time()) / 3600));
                $ar = current_lang() === 'ar';
                $left = $hoursLeft >= 24 ? (int) ceil($hoursLeft / 24) : $hoursLeft;
                $unit = $hoursLeft >= 24 ? ($ar ? 'يوم' : ($left === 1 ? 'day' : 'days')) : ($ar ? 'ساعة' : ($left === 1 ? 'hour' : 'hours'));
                $insights[] = [
                    'icon' => '🎁', 'priority' => 1,
                    'title' => $ar
                        ? "باقي {$left} {$unit} على تجربتك المجانية - جرّب أهم خدمة لشركتك دلوقتي واختار باقتك قبل ما التجربة تخلص"
                        : "{$left} {$unit} left in your free trial - try the service that matters most to your business now, and pick your plan before the trial ends",
                    'action_url' => '/subscription', 'action_label' => $ar ? 'شوف باقتك' : 'See your plan',
                ];
            } elseif ($sub && ($sub['status'] ?? '') === 'past_due') {
                // بعد التجربة/الفترة: «هينتهي خلال 0 يوم» كانت مضللة - العميل في فترة سماح ومحتاج يفعّل
                $ar = current_lang() === 'ar';
                $end = strtotime((string) ($sub['current_period_end'] ?? $sub['expiry_date'] ?? 'now')) ?: time();
                $left = max(0, (int) ceil(($end + BillingRules::GRACE_PERIOD_DAYS * 86400 - time()) / 86400));
                $wasTrial = (float) ($sub['price'] ?? 0) <= 0 || !empty($sub['trial_ends_at']);
                $insights[] = [
                    'icon' => '⚠️', 'priority' => 0,
                    'title' => $ar
                        ? ($wasTrial ? "تجربتك المجانية خلصت - خدماتك شغالة {$left} يوم كمان لحد ما تفعّل باقتك" : "اشتراكك محتاج تجديد - خدماتك شغالة {$left} يوم كمان")
                        : ($wasTrial ? "Your free trial has ended - your services keep running for {$left} more " . ($left === 1 ? 'day' : 'days') . ' while you activate your plan' : "Your subscription needs renewal - your services keep running for {$left} more " . ($left === 1 ? 'day' : 'days')),
                    'action_url' => '/plans', 'action_label' => $ar ? ($wasTrial ? 'فعّل باقتك' : 'جدّد اشتراكك') : ($wasTrial ? 'Activate your plan' : 'Renew your subscription'),
                ];
            } elseif ($sub && !empty($sub['expiry_date'])) {
                $daysLeft = (int) ((strtotime($sub['expiry_date']) - time()) / 86400);
                if ($daysLeft >= 0 && $daysLeft <= 7) {
                    $insights[] = [
                        'icon' => '⏰', 'priority' => 1,
                        'title' => current_lang() === 'ar'
                            ? "اشتراكك هينتهي خلال {$daysLeft} يوم - جدّده عشان متفقدش أي ميزة"
                            : "Your subscription ends in {$daysLeft} " . ($daysLeft === 1 ? 'day' : 'days') . ' - renew it so you keep every feature',
                        'action_url' => '/subscription', 'action_label' => 'جدّد الاشتراك',
                    ];
                }
            } elseif (!$sub) {
                $trialOpen = false;
                try {
                    $trialOpen = (new PlanExtras())->trialEligible($userId);
                } catch (Throwable $e) {
                }
                $insights[] = $trialOpen ? [
                    'icon' => '🎁', 'priority' => 1,
                    'title' => 'ابدأ تجربتك المجانية وجرّب كل خدمات المنصة على شركتك',
                    'action_url' => '/subscription#trial', 'action_label' => 'ابدأ التجربة المجانية',
                ] : [
                    'icon' => '💳', 'priority' => 3,
                    'title' => 'معندكش اشتراك نشط دلوقتي - اشترك عشان تستخدم كل الميزات',
                    'action_url' => '/plans', 'action_label' => 'شوف الباقات',
                ];
            }
        } catch (Exception $e) {
        }

        // ترتيب حسب الأولوية (1 = الأهم)
        usort($insights, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $this->success(['insights' => array_slice($insights, 0, 4)]);
    }

    /**
     * الحصول على إحصائيات لوحة التحكم
     * GET /api/dashboard/stats
     * @param array $params
     * @return array
     */
    private static $sentimentColumnCache = null;

    /**
     * تصحيح مؤكد من سجل الأخطاء الفعلي: "Unknown column 'sentiment_label'"
     * في إحصائيات المراجعات - نفس نمط اختلاف الأعمدة اللي ظهر في جداول
     * تانية (users، subscriptions، websites). بنكتشف الاسم الحقيقي بدل
     * ما نخليها تكسر إحصائيات الداشبورد بالكامل.
     * @return string
     */
    private function sentimentColumn(): string
    {
        if (self::$sentimentColumnCache !== null) {
            return self::$sentimentColumnCache;
        }

        $candidates = ['sentiment_label', 'sentiment', 'sentiment_type'];

        try {
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews'
                    AND COLUMN_NAME IN ({$placeholders})";
            $result = $this->db->query($sql, $candidates);
            $found = array_map('strtolower', array_column($result, 'COLUMN_NAME'));

            foreach ($candidates as $c) {
                if (in_array(strtolower($c), $found, true)) {
                    self::$sentimentColumnCache = $c;
                    return $c;
                }
            }
        } catch (Exception $e) {
            // تجاهل - هنرجع '' تحت
        }

        self::$sentimentColumnCache = '';
        return '';
    }

    public function getStats(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            // إحصائيات المستخدم
            $user = new User();
            $userData = $user->find($this->user['id']);
            $userStats = $userData ? $userData->getStats() : [];

            // إحصائيات الاشتراك
            $subscription = $this->subscription->validateSubscription($this->user['id']);

            // إحصائيات المراجعات
            $reviewStats = $this->getReviewStats();

            // إحصائيات الشات
            $chatStats = $this->getChatStats();

            // إحصائيات التقارير
            $reportStats = $this->getReportStats();

            // إحصائيات الـ API
            $apiStats = ApiUsageLog::getTodayUsage($this->user['id']);

            // إحصائيات الموديولات المدموجة (2026-07-14)
            $modulesStats = $this->getModulesStats();

            $trial = null;
            if (empty($subscription['valid'])) {
                try {
                    $x = new PlanExtras();
                    $owner = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
                    $trial = ['eligible' => $x->trialEligible($owner), 'days' => (int) ($x->config()['trial_days'] ?? 3)];
                } catch (Throwable $e) {
                }
            }

            return $this->success([
                'user' => $userStats,
                'subscription' => $subscription,
                'trial' => $trial,
                'reviews' => $reviewStats,
                'chat' => $chatStats,
                'reports' => $reportStats,
                'api' => $apiStats,
                'modules' => $modulesStats,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            Logger::error('Dashboard Stats Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get dashboard stats', 500);
        }
    }

    /**
     * الحصول على إحصائيات المراجعات
     * @return array
     */
    private function getReviewStats(): array
    {
        $sentimentCol = $this->sentimentColumn();
        $sentimentSelect = $sentimentCol
            ? "SUM(CASE WHEN `{$sentimentCol}` = 'positive' THEN 1 ELSE 0 END) as positive,
                    SUM(CASE WHEN `{$sentimentCol}` = 'neutral' THEN 1 ELSE 0 END) as neutral,
                    SUM(CASE WHEN `{$sentimentCol}` = 'negative' THEN 1 ELSE 0 END) as negative,"
            : "0 as positive, 0 as neutral, 0 as negative,";

        $sql = "SELECT 
                    COUNT(*) as total,
                    {$sentimentSelect}
                    AVG(rating) as avg_rating,
                    COUNT(CASE WHEN reply_sent_at IS NULL AND ai_generated_reply IS NOT NULL THEN 1 END) as pending_replies
                FROM reviews 
                WHERE user_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";

        $result = $this->db->query($sql, [$this->user['id']]);

        if (empty($result)) {
            return [
                'total' => 0,
                'positive' => 0,
                'neutral' => 0,
                'negative' => 0,
                'avg_rating' => 0,
                'pending_replies' => 0
            ];
        }

        return [
            'total' => (int) ($result[0]['total'] ?? 0),
            'positive' => (int) ($result[0]['positive'] ?? 0),
            'neutral' => (int) ($result[0]['neutral'] ?? 0),
            'negative' => (int) ($result[0]['negative'] ?? 0),
            'avg_rating' => round((float) ($result[0]['avg_rating'] ?? 0), 2),
            'pending_replies' => (int) ($result[0]['pending_replies'] ?? 0)
        ];
    }

    /**
     * الحصول على إحصائيات الشات
     * @return array
     */
    private function getChatStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_messages,
                    SUM(CASE WHEN message_direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                    SUM(CASE WHEN message_direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
                    SUM(CASE WHEN bot_status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
                    SUM(CASE WHEN bot_status = 'sent' THEN 1 ELSE 0 END) as sent
                FROM chat_messages 
                WHERE user_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";

        $result = $this->db->query($sql, [$this->user['id']]);

        if (empty($result)) {
            return [
                'total_messages' => 0,
                'incoming' => 0,
                'outgoing' => 0,
                'pending_approval' => 0,
                'sent' => 0
            ];
        }

        return [
            'total_messages' => (int) ($result[0]['total_messages'] ?? 0),
            'incoming' => (int) ($result[0]['incoming'] ?? 0),
            'outgoing' => (int) ($result[0]['outgoing'] ?? 0),
            'pending_approval' => (int) ($result[0]['pending_approval'] ?? 0),
            'sent' => (int) ($result[0]['sent'] ?? 0)
        ];
    }

    /**
     * الحصول على إحصائيات التقارير
     * @return array
     */
    private function getReportStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN is_cached = 1 AND cached_until > NOW() THEN 1 ELSE 0 END) as cached
                FROM ai_reports 
                WHERE user_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";

        $result = $this->db->query($sql, [$this->user['id']]);

        if (empty($result)) {
            return [
                'total' => 0,
                'completed' => 0,
                'processing' => 0,
                'failed' => 0,
                'cached' => 0
            ];
        }

        return [
            'total' => (int) ($result[0]['total'] ?? 0),
            'completed' => (int) ($result[0]['completed'] ?? 0),
            'processing' => (int) ($result[0]['processing'] ?? 0),
            'failed' => (int) ($result[0]['failed'] ?? 0),
            'cached' => (int) ($result[0]['cached'] ?? 0)
        ];
    }

    /**
     * إحصائيات سريعة لكل الموديولات المدمجة (2026-07-14) للوحة العامة.
     * كل عداد بسيط وسريع قصدًا - مش تجميع (aggregation) ثقيل لأن الهدف هنا نظرة سريعة فقط.
     */
    private function getModulesStats(): array
    {
        $userId = $this->user['id'];

        try {
            $socialPosts = (int) $this->db->query(
                "SELECT COUNT(*) as c FROM social_posts WHERE user_id = ?",
                [$userId]
            )[0]['c'];
            $mediaItems = (int) $this->db->query(
                "SELECT COUNT(*) as c FROM media_items WHERE user_id = ?",
                [$userId]
            )[0]['c'];
            $adCampaigns = (int) $this->db->query(
                "SELECT COUNT(*) as c FROM ad_campaigns WHERE user_id = ?",
                [$userId]
            )[0]['c'];
            $agencies = (int) $this->db->query(
                "SELECT COUNT(*) as c FROM agencies WHERE owner_user_id = ?",
                [$userId]
            )[0]['c'];
            $assistantRuns = (int) $this->db->query(
                "SELECT COUNT(*) as c FROM ai_assistant_interactions WHERE user_id = ?",
                [$userId]
            )[0]['c'];

            return [
                'social_posts' => $socialPosts,
                'media_items' => $mediaItems,
                'ad_campaigns' => $adCampaigns,
                'agencies' => $agencies,
                'assistant_runs' => $assistantRuns,
            ];
        } catch (Exception $e) {
            Logger::error('getModulesStats Error', ['message' => $e->getMessage()]);
            return [
                'social_posts' => 0, 'media_items' => 0, 'ad_campaigns' => 0,
                'agencies' => 0, 'assistant_runs' => 0,
            ];
        }
    }

    /**
     * الحصول على بيانات الرسم البياني للمراجعات
     * GET /api/dashboard/chart/reviews
     * @param array $params
     * @return array
     */
    public function getReviewChart(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $days = (int) ($this->get('days', 30));
            $sentimentCol = $this->sentimentColumn();
            $sentimentSelect = $sentimentCol
                ? "SUM(CASE WHEN `{$sentimentCol}` = 'positive' THEN 1 ELSE 0 END) as positive,
                        SUM(CASE WHEN `{$sentimentCol}` = 'negative' THEN 1 ELSE 0 END) as negative"
                : "0 as positive, 0 as negative";

            $sql = "SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as total,
                        AVG(rating) as avg_rating,
                        {$sentimentSelect}
                    FROM reviews 
                    WHERE user_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC";

            $result = $this->db->query($sql, [$this->user['id'], $days]);

            return $this->success([
                'data' => $result,
                'days' => $days
            ]);

        } catch (Exception $e) {
            Logger::error('Review Chart Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get review chart data', 500);
        }
    }

    /**
     * الحصول على بيانات الرسم البياني للشات
     * GET /api/dashboard/chart/chat
     * @param array $params
     * @return array
     */
    public function getChatChart(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $days = (int) ($this->get('days', 30));

            $sql = "SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as total,
                        SUM(CASE WHEN message_direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                        SUM(CASE WHEN message_direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing
                    FROM chat_messages 
                    WHERE user_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC";

            $result = $this->db->query($sql, [$this->user['id'], $days]);

            return $this->success([
                'data' => $result,
                'days' => $days
            ]);

        } catch (Exception $e) {
            Logger::error('Chat Chart Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get chat chart data', 500);
        }
    }

    /**
     * الحصول على بيانات الرسم البياني للـ API
     * GET /api/dashboard/chart/api
     * @param array $params
     * @return array
     */
    public function getApiChart(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $days = (int) ($this->get('days', 30));

            $sql = "SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as total_requests,
                        SUM(cost_in_usd) as total_cost,
                        SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as success,
                        SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors
                    FROM api_usage_logs 
                    WHERE user_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC";

            $result = $this->db->query($sql, [$this->user['id'], $days]);

            // تنسيق البيانات
            $data = array_map(function ($row) {
                $row['total_cost'] = round((float) $row['total_cost'], 6);
                return $row;
            }, $result);

            return $this->success([
                'data' => $data,
                'days' => $days
            ]);

        } catch (Exception $e) {
            Logger::error('API Chart Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get API chart data', 500);
        }
    }

    // ============================================
    // الدوال التالية أُضيفت لاحقًا لأن app/routes/web.php و api.php
    // كانا يشيران إليها ولم تكن معرّفة أصلاً.
    // ============================================

    /** GET /dashboard */
    public function index(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboardPage('overview');
        exit;
    }

    /** GET /dashboard/overview */
    public function overview(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboardPage('overview');
        exit;
    }

    /** GET /dashboard/analytics */
    public function analytics(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboardPage('analytics');
        exit;
    }

    /** GET /dashboard/activity */
    public function activity(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboardPage('activity');
        exit;
    }

    /** GET /dashboard/executive */
    public function executive(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboardPage('executive');
        exit;
    }

    /** GET /dashboard/growth - Phase 17 (Dashboard UX): تاب جديد بيعرض Phase 12/14/15/16 */
    public function growth(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboardPage('growth');
        exit;
    }

    /** GET /api/dashboard/chart/ai */
    public function getAIChart(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $days = (int) ($this->get('days', 30));
            $sql = "SELECT DATE(created_at) as date, COUNT(*) as count
                    FROM ai_reports
                    WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC";
            $result = $this->db->query($sql, [$this->user['id'], $days]);
            return $this->success(['data' => $result, 'days' => $days]);
        } catch (Exception $e) {
            Logger::error('AI Chart Error', ['message' => $e->getMessage()]);
            return $this->error('Failed to get AI chart data', 500);
        }
    }

    /** GET /api/dashboard/activity */
    public function getRecentActivity(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20";
            $result = $this->db->query($sql, [$this->user['id']]);
            return $this->success(['activity' => $result]);
        } catch (Exception $e) {
            Logger::error('Recent Activity Error', ['message' => $e->getMessage()]);
            return $this->error('Failed to get recent activity', 500);
        }
    }

    /** GET /api/dashboard/notifications */
    public function getNotifications(array $params = []): array
    {
        // تم إضافة جدول notifications بتاريخ 2026-07-14 (لم يكن موجودًا من
        // قبل - شوف database/migrations/2026_07_14_000006_create_notifications_table.sql)
        // الفرونت إند (renderDashboardPage) كان بالفعل يتوقع notifications[].title
        // و notifications[].created_at من قبل ما يكون فيه جدول أصلًا، فهذا
        // الاستعلام موصول على طول بنفس الشكل من غير أي تعديل مطلوب في الـ JS.
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "SELECT id, type, title, body, link, read_at, created_at
                    FROM notifications
                    WHERE user_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20";
            $result = $this->db->query($sql, [$this->user['id']]);
            return $this->success(['notifications' => $result]);
        } catch (Exception $e) {
            Logger::error('Dashboard getNotifications Error', ['message' => $e->getMessage()]);
            return $this->success(['notifications' => []]);
        }
    }

    /** Mark one displayed group, scoped to the signed-in account; never accepts another account's rows. */
    public function markNotificationGroupRead(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized',401);
        if ($e=$this->verifyCsrf()) return $e;
        $ids=$this->get('ids');
        if (!is_array($ids)||!$ids||count($ids)>50) return $this->error('Invalid notification group',422);
        foreach ($ids as $id) if (!is_int($id)||$id<1) return $this->error('Invalid notification group',422);
        $ids=array_values(array_unique($ids));
        try {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->db->getConnection()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND id IN ($marks)");
            $q->execute(array_merge([(int)$this->user['id']],$ids));
            return $this->success(['marked_read'=>true]);
        } catch(Throwable $e) {return $this->error('Could not update notifications',503);}
    }

    /** POST /api/dashboard/notifications/{id}/read */
    public function markNotificationRead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $id = (int) ($params['id'] ?? 0);
            $notification = (new Notification())->find($id);

            if (!$notification || (int) $notification->getAttribute('user_id') !== (int) $this->user['id']) {
                return $this->error('الإشعار غير موجود', 404);
            }

            $notification->setAttribute('read_at', date('Y-m-d H:i:s'));
            $notification->save();

            return $this->success(['marked_read' => true]);
        } catch (Exception $e) {
            Logger::error('markNotificationRead Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث الإشعار', 500);
        }
    }

    /** GET /api/dashboard/login-history — سجل دخول العميل الحالي فقط */
    public function getLoginHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $limit = min(100, max(1, (int) ($this->get('limit', 30))));
            $sql = "SELECT id, status, ip_address, device_type, browser, platform,
                           country, city, is_impersonation, created_at
                    FROM login_history
                    WHERE user_id = ?
                    ORDER BY created_at DESC
                    LIMIT {$limit}";
            $result = $this->db->query($sql, [$this->user['id']]);
            return $this->success(['login_history' => $result]);
        } catch (Exception $e) {
            Logger::error('Dashboard getLoginHistory Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب سجل الدخول', 500);
        }
    }

    /**
     * توليد صفحة HTML احترافية للوحة تحكم العميل (Sidebar SaaS Layout).
     * التبويبات: overview, analytics, activity.
     * البيانات بتتحمّل عبر fetch() من /api/dashboard/* و/api/admin/impersonate/stop الشغالة بالفعل.
     *
     * @param string $tab 'overview' | 'analytics' | 'activity'
     * @return string
     */
    private function renderDashboardPage(string $tab): string
    {
        // ============================================
        // فرض معالج الإعداد السريع (2026-08-20): حساب جديد معملش أي
        // مواقع لسه (صفر مواقع = مش ممكن يشتغل على أي موديول في المنصة)
        // بيتحوّل مباشرة لـ /onboarding بدل ما يشوف لوحة فاضية مربكة.
        // بعد ما يضيف أول موقع، الـ checklist التوجيهي (buildOnboardingScript)
        // بياخد مكانه تلقائيًا. مش بنفرض على أي حد عنده مواقع فعلًا.
        // ============================================
        try {
            // الأدمن مالوش مواقع - بدل معالج إعداد العميل نوديه للوحة الإدارة
            // تصحيح: زرار "الرجوع للوحتي" في لوحة الأدمن كان بيرجّع لـ /admin تاني (دايرة).
            // الأدمن اللي اختار لوحة العميل (?view=customer) بيفضل فيها لحد ما يفتح /admin تاني.
            if (($_GET['view'] ?? '') === 'customer') {
                $_SESSION['admin_customer_view'] = true;
            }
            if (in_array($this->user['role'] ?? '', ['super_admin', 'admin'], true) && empty($_SESSION['admin_customer_view'])) {
                header('Location: /admin');
                exit;
            }
            // زرار "تخطّي للمتابعة" في المعالج كان بيرجّع العميل للمعالج تاني (دايرة).
            // التخطي بيتسجّل في الجلسة واللوحة بتحترمه.
            if (!empty($_GET['skip_onboarding'])) {
                $_SESSION['onboarding_skipped'] = true;
                // «معنديش موقع» في المعالج: يتخطّى ويروح على منشئ المواقع مباشرة
                $next = (string) ($_GET['next'] ?? '');
                if (in_array($next, ['/website-builder', '/websites'], true)) {
                    header('Location: ' . $next);
                    exit;
                }
            }
            if (!empty($this->user['id']) && empty($_SESSION['onboarding_skipped'])) {
                $websiteCount = (int) ($this->db->query(
                    "SELECT COUNT(*) AS c FROM websites WHERE user_id = ?",
                    [(int) $this->user['id']]
                )[0]['c'] ?? 0);
                $onboarded = (int) ($this->db->query(
                    "SELECT COUNT(*) AS c FROM websites WHERE user_id = ? AND onboarding_completed_at IS NOT NULL",
                    [(int) $this->user['id']]
                )[0]['c'] ?? 0);

                // اللي بنى موقعه من منشئ المواقع (معندوش موقع قديم) مايترجعش للمعالج كل مرة يدخل
                if ($websiteCount === 0) {
                    try {
                        $websiteCount = (int) ($this->db->query(
                            "SELECT COUNT(*) AS c FROM generated_websites WHERE user_id = ?",
                            [(int) $this->user['id']]
                        )[0]['c'] ?? 0);
                    } catch (Throwable $e) {
                    }
                }
                if ($websiteCount === 0 && $onboarded === 0) {
                    // Staff of another account have no website of their own: send them to the shared inbox,
                    // not to new-customer setup.
                    if (class_exists('TeamAccess') && TeamAccess::ownerFor($this->user) !== null) {
                        header('Location: /chat');
                        exit;
                    }
                    header('Location: /onboarding');
                    exit;
                }
            }
        } catch (Throwable $e) {
            Logger::error('Dashboard onboarding redirect check failed', ['message' => $e->getMessage()]);
        }

        $isImpersonating = !empty($_SESSION['impersonator_admin_id']);

        $titles = [
            'overview' => [$this->tr('dashboard.tab.overview'), $this->tr('dashboard.tab.overview_sub')],
            'analytics' => [$this->tr('dashboard.tab.analytics'), $this->tr('dashboard.tab.analytics_sub')],
            'activity' => [$this->tr('dashboard.tab.activity'), $this->tr('dashboard.tab.activity_sub')],
            'executive' => [$this->tr('dashboard.tab.executive'), $this->tr('dashboard.tab.executive_sub')],
            'growth' => [$this->tr('dashboard.tab.growth'), $this->tr('dashboard.tab.growth_sub')],
        ];
        $pageTitle = $titles[$tab][0] ?? $this->tr('sidebar.dashboard');
        $pageSubtitle = $titles[$tab][1] ?? '';

        $panelBody = $this->renderDashboardPanelBody($tab);

        $tImpersonationMsg = $this->tr('dashboard.impersonation_msg');
        $tBackToAdmin = $this->tr('dashboard.back_to_admin');
        $impersonationBanner = $isImpersonating
            ? '<div class="impersonation-banner">🔑 ' . $tImpersonationMsg . ' <button onclick="stopImpersonating()">' . $tBackToAdmin . '</button></div>'
            : '';

        $tEndSessionFailed = $this->trJs('dashboard.end_session_failed');
        $tFree = $this->trJs('dashboard.free_plan');
        $tStartTrial = json_encode(current_lang() === 'ar' ? 'ابدأ التجربة المجانية' : 'Start free trial', JSON_UNESCAPED_UNICODE);
        $tTrial = json_encode(current_lang() === 'ar' ? 'تجربة مجانية' : 'Free trial', JSON_UNESCAPED_UNICODE);
        $tPastDue = json_encode(current_lang() === 'ar' ? 'محتاج تفعيل' : 'Needs activation', JSON_UNESCAPED_UNICODE);
        $tLoadFailed = $this->trJs('dashboard.data_load_failed');
        $tRetry = $this->trJs('common.retry');
        $tNoReviews = $this->trJs('dashboard.no_reviews');
        $tNoActivity = $this->trJs('dashboard.no_activity');
        $tNoNotifications = $this->trJs('dashboard.no_notifications');
        $tNoLogins = $this->trJs('dashboard.no_logins');
        $tPositive = $this->trJs('dashboard.sentiment.positive');
        $tNeutral = $this->trJs('dashboard.sentiment.neutral');
        $tNegative = $this->trJs('dashboard.sentiment.negative');

        $script = <<<'JS'
(function () {
    const tab = "__TAB__";
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, timeAgo = P.timeAgo, formatDate = P.formatDate;
    let charts = {};
    function destroyChart(key) { if (charts[key]) { charts[key].destroy(); delete charts[key]; } }

    window.stopImpersonating = async function () {
        const res = await fetchJSON('/api/admin/impersonate/stop', { method: 'POST' });
        if (res.success) { window.location.href = '/admin'; }
        else { toast(res.error || __END_SESSION_FAILED__, 'error'); }
    };

    function planLabel(sub, trial) {
        // اشتراك منتهي/غير نشط كان بيظهر كلمة "inactive" الخام بالإنجليزي
        if (!sub || !sub.valid) return trial && trial.eligible ? __START_TRIAL__ : __FREE_PLAN__;
        // اسم الباقة بحرف كبير عشان الترجمة العربي تلقطه (enterprise ← Enterprise ← الريادة)
        const p = String(sub.plan_name || sub.plan || "").replace(/_(monthly|yearly)$/, '');
        let name = p ? p.charAt(0).toUpperCase() + p.slice(1) : __FREE_PLAN__;
        // مع لاحقة («· تجربة مجانية») القاموس العربي مش بيلقط اسم الباقة لوحده، فنترجمه هنا
        if (document.documentElement.lang.startsWith('ar') && window.TF_AR_DICT && window.TF_AR_DICT[name]) name = window.TF_AR_DICT[name];
        // التجربة المجانية باقة شغالة فعلًا - كانت بتظهر «مفيش باقة»
        if (sub.status === 'past_due') return name + ' · ' + __PAST_DUE__;
        return sub.status === 'trialing' ? name + ' · ' + __TRIAL__ : name;
    }

    function overviewError(scope) {
        return `<div class="dashboard-inline-state is-error" role="alert">
            <span>${__LOAD_FAILED__}</span>
            <button type="button" class="p-btn outline xs" onclick="window.retryOverviewSection('${scope}')">${__RETRY__}</button>
        </div>`;
    }

    function requireSuccess(result) {
        if (!result || result.success !== true) throw new Error('Dashboard request failed');
        return result.data || {};
    }

    function setDashboardStat(id, value) {
        const element = document.getElementById(id);
        element.textContent = value;
        element.classList.remove('dashboard-value-pending');
    }

    async function loadOverviewStats() {
        const data = requireSuccess(await fetchJSON('/api/dashboard/stats'));
        const reviews = data.reviews || {};
        document.getElementById('statsError').hidden = true;
        setDashboardStat('statReviews', reviews.total ?? 0);
        setDashboardStat('statChat', (data.chat && data.chat.total_messages) ?? 0);
        setDashboardStat('statReports', (data.reports && data.reports.total) ?? 0);
        setDashboardStat('statSubscription', planLabel(data.subscription, data.trial));
        // اسم الباقة + «تجربة مجانية» أطول من رقم: نصغّر الخط بدل ما يتكسر على سطرين كبار
        document.getElementById('statSubscription').style.fontSize = planLabel(data.subscription, data.trial).length > 12 ? '18px' : '';
        const planTile = document.getElementById('statSubscription').closest('.stat-tile');
        if (planTile && !planTile.dataset.linked) {
            planTile.dataset.linked = '1';
            planTile.style.cursor = 'pointer';
            planTile.addEventListener('click', () => { location.href = data.trial && data.trial.eligible ? '/subscription#trial' : '/subscription'; });
        }

        const donutCtx = document.getElementById('sentimentChart');
        const sentimentEmpty = document.getElementById('sentimentEmpty');
        if (!donutCtx) return;

        destroyChart('sentiment');
        const values = [reviews.positive || 0, reviews.neutral || 0, reviews.negative || 0];
        if (values.some(value => Number(value) > 0)) {
            donutCtx.hidden = false;
            sentimentEmpty.hidden = true;
            charts.sentiment = new Chart(donutCtx, {
                type: 'doughnut',
                data: {
                    labels: [__POSITIVE__, __NEUTRAL__, __NEGATIVE__],
                    datasets: [{ data: values, backgroundColor: ['#17a673', '#d9822b', '#e5484d'], borderWidth: 0 }],
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } } },
            });
        } else {
            donutCtx.hidden = true;
            sentimentEmpty.hidden = false;
            sentimentEmpty.textContent = __NO_REVIEWS__;
        }
    }

    async function loadOverviewActivity() {
        const data = requireSuccess(await fetchJSON('/api/dashboard/activity'));
        const tbody = document.querySelector('#activityTable tbody');
        // الفحص التلقائي بيتسجل كل نص ساعة ويغطي على أي نشاط مهم
        const rows = (Array.isArray(data.activity) ? data.activity : []).filter(row => (row.action || row.event) !== 'monitoring.cycle_completed');
        tbody.innerHTML = rows.length
            ? rows.slice(0, 6).map(row => {
                const label = Panel.activityLabel(row.action || row.event || row.description || '—');
                return `<tr><td>${esc(label)}</td><td class="p-cell-muted">${timeAgo(row.created_at)}</td></tr>`;
            }).join('')
            : `<tr><td colspan="2" class="p-cell-muted text-center">${esc(__NO_ACTIVITY__)}</td></tr>`;
    }

    async function loadOverviewNotifications() {
        const data = requireSuccess(await fetchJSON('/api/dashboard/notifications'));
        const box = document.getElementById('notifList');
        const notifications = Array.isArray(data.notifications) ? data.notifications : [];
        box.innerHTML = notifications.length
            ? notifications.slice(0, 6).map(item => `
                <div class="p-kv"><span class="k">${esc(item.title || item.message || '—')}</span><span class="v">${timeAgo(item.created_at)}</span></div>
            `).join('')
            : `<div class="p-empty dashboard-empty">${esc(__NO_NOTIFICATIONS__)}</div>`;
    }

    async function loadOverviewLogins() {
        const data = requireSuccess(await fetchJSON('/api/dashboard/login-history?limit=5'));
        const box = document.getElementById('loginMiniList');
        const history = Array.isArray(data.login_history) ? data.login_history : [];
        box.innerHTML = history.length
            ? history.map(item => `
                <div class="p-kv">
                    <span class="k">${item.status === 'success' ? '✓' : '×'} ${esc(item.device_type || '—')} · ${esc(item.city || item.country || '—')}</span>
                    <span class="v">${timeAgo(item.created_at)}</span>
                </div>`).join('')
            : `<div class="p-cell-muted dashboard-login-empty">${esc(__NO_LOGINS__)}</div>`;
    }

    async function loadOverviewInsights() {
        const data = requireSuccess(await fetchJSON('/api/dashboard/smart-insights'));
        const card = document.getElementById('smartInsightsCard');
        const list = document.getElementById('smartInsightsList');
        const insights = Array.isArray(data.insights) ? data.insights : [];
        if (!insights.length) {
            card.style.display = 'none';
            return;
        }

        list.innerHTML = insights.map(item => {
            const actionUrl = String(item.action_url || '');
            const safeUrl = actionUrl.startsWith('/') && !actionUrl.startsWith('//') ? actionUrl : '#';
            return `<div class="p-kv">
                <span class="k">${esc(item.icon || '')} ${esc(item.title || '')}</span>
                <span class="v"><a href="${esc(safeUrl)}" class="p-btn outline xs">${esc(item.action_label || '')}</a></span>
            </div>`;
        }).join('');
        card.style.display = 'block';
    }

    const overviewLoaders = {
        stats: loadOverviewStats,
        activity: loadOverviewActivity,
        notifications: loadOverviewNotifications,
        logins: loadOverviewLogins,
        insights: loadOverviewInsights,
    };

    function showOverviewError(scope) {
        if (scope === 'stats') {
            ['statReviews', 'statChat', 'statReports', 'statSubscription'].forEach(id => {
                document.getElementById(id).classList.remove('dashboard-value-pending');
            });
            const state = document.getElementById('statsError');
            state.innerHTML = overviewError(scope);
            state.hidden = false;
        } else if (scope === 'activity') {
            const tbody = document.querySelector('#activityTable tbody');
            tbody.innerHTML = `<tr><td colspan="2">${overviewError(scope)}</td></tr>`;
        } else if (scope === 'notifications') {
            const box = document.getElementById('notifList');
            box.innerHTML = overviewError(scope);
        } else if (scope === 'logins') {
            const box = document.getElementById('loginMiniList');
            box.innerHTML = overviewError(scope);
        } else if (scope === 'insights') {
            const card = document.getElementById('smartInsightsCard');
            document.getElementById('smartInsightsList').innerHTML = overviewError(scope);
            card.style.display = 'block';
        }
    }

    async function runOverviewSection(scope) {
        try {
            await overviewLoaders[scope]();
        } catch (error) {
            showOverviewError(scope);
        }
    }

    window.retryOverviewSection = function (scope) {
        if (Object.prototype.hasOwnProperty.call(overviewLoaders, scope)) {
            void runOverviewSection(scope);
        }
    };

    async function loadOverview() {
        await Promise.all(Object.keys(overviewLoaders).map(runOverviewSection));
    }

    function lineChart(canvasId, labels, datasets) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return; // مكتبة الرسوم متحملتش: نكمّل باقي الصفحة من غير رسالة خطأ
        const key = canvasId;
        destroyChart(key);
        charts[key] = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }

    async function loadAnalytics(days) {
        days = days || 30;
        const [reviewsRes, chatRes, apiRes, aiRes] = await Promise.all([
            fetchJSON('/api/dashboard/chart/reviews?days=' + days),
            fetchJSON('/api/dashboard/chart/chat?days=' + days),
            fetchJSON('/api/dashboard/chart/api?days=' + days),
            fetchJSON('/api/dashboard/chart/ai?days=' + days),
        ]);

        if (reviewsRes.success) {
            const rows = reviewsRes.data.data || [];
            lineChart('reviewsChart', rows.map(r => r.date), [
                { label: 'إجمالي المراجعات', data: rows.map(r => r.total), borderColor: '#0077be', backgroundColor: 'rgba(0,119,190,0.08)', tension: 0.35, fill: true, pointRadius: 0 },
                { label: 'إيجابية', data: rows.map(r => r.positive), borderColor: '#17a673', backgroundColor: 'rgba(23,166,115,0.06)', tension: 0.35, fill: true, pointRadius: 0 },
                { label: 'سلبية', data: rows.map(r => r.negative), borderColor: '#e5484d', backgroundColor: 'rgba(229,72,77,0.06)', tension: 0.35, fill: true, pointRadius: 0 },
            ]);
        }
        if (chatRes.success) {
            const rows = chatRes.data.data || [];
            lineChart('chatChart', rows.map(r => r.date), [
                { label: 'واردة', data: rows.map(r => r.incoming), borderColor: '#3d7ff2', backgroundColor: 'rgba(61,127,242,0.08)', tension: 0.35, fill: true, pointRadius: 0 },
                { label: 'صادرة', data: rows.map(r => r.outgoing), borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.06)', tension: 0.35, fill: true, pointRadius: 0 },
            ]);
        }
        if (apiRes.success) {
            const rows = apiRes.data.data || [];
            lineChart('apiChart', rows.map(r => r.date), [
                { label: 'طلبات ناجحة', data: rows.map(r => r.success), borderColor: '#17a673', backgroundColor: 'rgba(23,166,115,0.06)', tension: 0.35, fill: true, pointRadius: 0 },
                { label: 'أخطاء', data: rows.map(r => r.errors), borderColor: '#e5484d', backgroundColor: 'rgba(229,72,77,0.06)', tension: 0.35, fill: true, pointRadius: 0 },
            ]);
        }
        if (aiRes.success) {
            const rows = aiRes.data.data || [];
            lineChart('aiChart', rows.map(r => r.date), [
                { label: 'تقارير AI', data: rows.map(r => r.count), borderColor: '#d9822b', backgroundColor: 'rgba(217,130,43,0.08)', tension: 0.35, fill: true, pointRadius: 0 },
            ]);
        }
    }
    window.reloadAnalytics = function () {
        loadAnalytics(document.getElementById('analyticsDays').value);
    };

    async function loadActivity() {
        const [activityRes, loginRes] = await Promise.all([
            fetchJSON('/api/dashboard/activity'),
            fetchJSON('/api/dashboard/login-history?limit=50'),
        ]);

        const actBody = document.querySelector('#fullActivityTable tbody');
        if (activityRes.success && Array.isArray(activityRes.data.activity) && activityRes.data.activity.length) {
            actBody.innerHTML = activityRes.data.activity.map(row => {
                const label = Panel.activityLabel(row.action || row.event || row.description || 'نشاط');
                return `<tr><td>${esc(label)}</td><td class="p-cell-muted">${formatDate(row.created_at)}</td></tr>`;
            }).join('');
        } else {
            actBody.innerHTML = '<tr><td colspan="2" class="p-cell-muted text-center">لا يوجد نشاط حتى الآن</td></tr>';
        }

        const loginBody = document.querySelector('#fullLoginTable tbody');
        if (loginRes.success && Array.isArray(loginRes.data.login_history) && loginRes.data.login_history.length) {
            loginBody.innerHTML = loginRes.data.login_history.map(h => `
                <tr>
                    <td>${h.status === 'success' ? '<span class="pill green">✔ نجاح</span>' : '<span class="pill red">✖ فشل</span>'}${h.is_impersonation == 1 ? ' <span class="pill blue">دعم فني</span>' : ''}</td>
                    <td class="p-cell-muted">${esc(h.ip_address || '-')}</td>
                    <td class="p-cell-muted">${esc(h.device_type || '-')} / ${esc(h.browser || '-')} / ${esc(h.platform || '-')}</td>
                    <td class="p-cell-muted">${esc(h.country || '-')} ${h.city ? '· ' + esc(h.city) : ''}</td>
                    <td class="p-cell-muted">${formatDate(h.created_at)}</td>
                </tr>`).join('');
        } else {
            loginBody.innerHTML = '<tr><td colspan="5" class="p-cell-muted text-center">لا يوجد سجل دخول بعد</td></tr>';
        }
    }

    async function loadExecutive() {
        const [statsRes, stagesRes, dealsRes, leadsRes, adsRes, repRes, compRes, revRes] = await Promise.all([
            fetchJSON('/api/dashboard/stats'),
            fetchJSON('/api/crm/pipeline-stages'),
            fetchJSON('/api/crm/deals'),
            fetchJSON('/api/crm/leads'),
            fetchJSON('/api/ads/campaigns'),
            fetchJSON('/api/reputation/overview-data'),
            fetchJSON('/api/ai/competitors'),
            fetchJSON('/api/revenue/kpis?days=30'),
        ]);

        // بطاقات المؤشرات
        if (statsRes.success) {
            document.getElementById('execChats').textContent = (statsRes.data.chat && statsRes.data.chat.total_messages) || 0;
            document.getElementById('execReports').textContent = (statsRes.data.reports && statsRes.data.reports.total) || 0;
        }
        if (revRes.success) {
            const rk = revRes.data.kpis || {};
            document.getElementById('execRevenue').textContent = window.Panel.money(rk.revenue_total);
        }
        if (repRes.success) {
            const k = repRes.data.kpis || {};
            document.getElementById('execAvgRating').textContent = k.avg_rating ? (k.avg_rating + ' ⭐') : '-';
        }
        const deals = (dealsRes.success && Array.isArray(dealsRes.data.deals)) ? dealsRes.data.deals : [];
        const openDeals = deals.filter(d => !['won', 'lost'].includes((d.stage_name || '').toLowerCase()));
        document.getElementById('execDeals').textContent = openDeals.length;
        const leads = (leadsRes.success && Array.isArray(leadsRes.data.leads)) ? leadsRes.data.leads : [];
        document.getElementById('execLeads').textContent = leads.length;
        const campaigns = (adsRes.success && Array.isArray(adsRes.data.campaigns)) ? adsRes.data.campaigns : [];
        const totalSpend = campaigns.reduce((sum, c) => sum + (parseFloat(c.spend) || 0), 0);
        document.getElementById('execAdsSpend').textContent = window.Panel.money(totalSpend);
        const competitors = (compRes.success && Array.isArray(compRes.data.competitors)) ? compRes.data.competitors : [];
        document.getElementById('execCompetitors').textContent = competitors.length;

        // مسار المبيعات (Funnel)
        const stages = (stagesRes.success && Array.isArray(stagesRes.data.stages)) ? stagesRes.data.stages : [];
        if (stages.length && typeof Chart !== 'undefined') {
            const counts = stages.map(s => deals.filter(d => d.stage_id == s.id).length);
            new Chart(document.getElementById('execPipelineChart'), {
                type: 'bar',
                data: { labels: stages.map(s => s.name), datasets: [{ label: 'صفقات', data: counts, backgroundColor: '#5B9BD5' }] },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }

        // اتجاه تقييم السمعة
        if (repRes.success && Array.isArray(repRes.data.trend) && repRes.data.trend.length && typeof Chart !== 'undefined') {
            const trend = repRes.data.trend;
            const platforms = ['tripadvisor', 'google_business', 'booking', 'expedia', 'trustpilot', 'other'];
            const labels = { tripadvisor: 'TripAdvisor', google_business: 'Google Business', booking: 'Booking.com', expedia: 'Expedia', trustpilot: 'Trustpilot', other: 'أخرى' };
            const colors = { tripadvisor: '#3FA796', google_business: '#5B9BD5', booking: '#E2A03F', expedia: '#9A8CF5', trustpilot: '#8891A0', other: '#4A5261' };
            const datasets = platforms
                .filter(p => trend.some(w => w[p] !== null && w[p] !== undefined))
                .map(p => ({ label: labels[p], data: trend.map(w => w[p]), borderColor: colors[p], backgroundColor: 'transparent', tension: 0.3, spanGaps: true }));
            new Chart(document.getElementById('execReputationChart'), {
                type: 'line',
                data: { labels: trend.map(w => w.week), datasets },
                options: { responsive: true, scales: { y: { min: 0, max: 5 } } }
            });
        }

        // تنبيهات مجمّعة من كل موديول (بيانات حقيقية بس، مفيش أرقام وهمية)
        const alerts = [];
        if (repRes.success) {
            const negatives = (repRes.data.reviews || []).filter(r => r.sentiment_label === 'negative').slice(0, 3);
            negatives.forEach(r => alerts.push({ icon: '⭐', text: I18N['executive.alert.negative_review_prefix'] + ' ' + (r.platform || '-') + ': ' + (r.review_text || '').slice(0, 70) + '…', level: 'amber' }));
        }
        if (statsRes.success && statsRes.data.chat && statsRes.data.chat.pending_approval > 0) {
            alerts.push({ icon: '💬', text: statsRes.data.chat.pending_approval + ' ' + I18N['executive.alert.pending_replies_suffix'], level: 'amber' });
        }
        if (repRes.success && (repRes.data.kpis || {}).pending_reply > 0) {
            alerts.push({ icon: '⭐', text: repRes.data.kpis.pending_reply + ' ' + I18N['executive.alert.pending_replies_suffix'], level: 'amber' });
        }
        const uncontacted = leads.filter(l => l.status === 'new').length;
        if (uncontacted > 0) {
            alerts.push({ icon: '🧲', text: uncontacted + ' ' + I18N['executive.alert.uncontacted_lead_suffix'], level: 'amber' });
        }

        const alertsBox = document.getElementById('execAlerts');
        alertsBox.innerHTML = alerts.length
            ? alerts.map(a => `<div style="border-right:3px solid ${a.level === 'amber' ? '#E2A03F' : '#8891A0'};padding-right:10px;font-size:12.5px;">${a.icon} ${esc(a.text)}</div>`).join('')
            : `<div class="p-cell-muted">${I18N['executive.alerts.none']}</div>`;
    }

    window.execAddNote = async function () {
        const input = document.getElementById('execNoteInput');
        const note = input.value.trim();
        if (!note) return;
        const res = await fetchJSON('/api/executive/notes', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ note }) });
        if (res.success) { input.value = ''; toast('تم الحفظ', 'success'); loadExecutiveExtras(); }
        else toast(res.error || 'تعذر الحفظ', 'error');
    };

    window.execCompleteTask = async function (id) {
        const res = await fetchJSON('/api/executive/tasks/' + id + '/complete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) loadExecutiveExtras();
    };

    window.execMarkAlertRead = async function (id) {
        const res = await fetchJSON('/api/executive/alerts/' + id + '/read', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) loadExecutiveExtras();
    };

    async function loadExecutiveExtras() {
        const res = await fetchJSON('/api/executive/extras');
        if (!res.success) return;
        const d = res.data;

        const roBox = document.getElementById('execRisksOpportunities');
        const items = [
            ...d.risks.map(r => ({ icon: '⚠️', text: r.title, color: '#D64545' })),
            ...d.opportunities.map(o => ({ icon: '🎯', text: o.title, color: '#3FA796' })),
        ];
        roBox.innerHTML = items.length
            ? items.map(i => `<div style="border-right:3px solid ${i.color};padding-right:10px;font-size:12.5px;">${i.icon} ${esc(i.text)}</div>`).join('')
            : `<div class="p-cell-muted" style="padding:0 20px;">${I18N['executive.risks.none']}</div>`;

        const tasksBox = document.getElementById('execTasks');
        tasksBox.innerHTML = d.tasks.length
            ? d.tasks.map(t => `<div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;"><span>${esc(t.title)}</span><button class="p-btn outline xs" onclick="execCompleteTask(${t.id})">✔ ${I18N['executive.tasks.done']}</button></div>`).join('')
            : `<div class="p-cell-muted">${I18N['executive.tasks.none']}</div>`;

        // دمج تنبيهات cc_ai_alerts الثابتة مع تنبيهات السمعة/الشات المحسوبة (أعلى)
        if (d.alerts.length) {
            const alertsBox = document.getElementById('execAlerts');
            const extra = d.alerts.map(a => `<div style="border-right:3px solid #E2A03F;padding-right:10px;font-size:12.5px;display:flex;justify-content:space-between;align-items:center;"><span>🔔 ${esc(a.message)}</span><button class="p-btn outline xs" onclick="execMarkAlertRead(${a.id})">قرأت</button></div>`).join('');
            alertsBox.insertAdjacentHTML('beforeend', extra);
        }
    }

    // Phase 17 (Dashboard UX): تاب "النمو والذكاء الاصطناعي" - بيجمع نتائج
    // Phase 12 (Action Center) + Phase 15 (Executive Dashboard) + Phase 11
    // (CEO Advisor) في مكان واحد. مفيش Website Selector عام في الداشبورد
    // القديمة (كل التابات التانية account-wide)، فبنجيب قائمة المواقع هنا
    // ونخلي العميل يختار، ونحفظ الاختيار في sessionStorage عشان يفضل
    // متذكّره لو رجع للتاب تاني في نفس الجلسة.
    let growthWebsitesLoaded = false;

    async function ensureGrowthWebsiteList() {
        const sel = document.getElementById('growthWebsiteSelect');
        if (growthWebsitesLoaded || !sel) return;
        const res = await fetchJSON('/api/websites');
        if (!res.success) return;
        const sites = res.data.websites || [];
        sel.innerHTML = sites.map(s => `<option value="${s.id}">${esc(s.company_name || s.main_url)}</option>`).join('');
        let saved = null;
        try { saved = sessionStorage.getItem('growth_website_id'); } catch (error) {}
        if (saved && sites.some(s => String(s.id) === saved)) sel.value = saved;
        growthWebsitesLoaded = true;
    }

    let growthLoadVersion = 0;
    window.loadGrowth = loadGrowth;
    async function loadGrowth() {
        const version = ++growthLoadVersion;
        await ensureGrowthWebsiteList();
        if (version !== growthLoadVersion) return;
        const sel = document.getElementById('growthWebsiteSelect');
        const websiteId = sel ? sel.value : null;
        const noWebsiteBox = document.getElementById('growthNoWebsite');
        const contentBox = document.getElementById('growthContent');

        if (!websiteId) {
            if (noWebsiteBox) noWebsiteBox.style.display = 'block';
            if (contentBox) contentBox.style.display = 'none';
            return;
        }
        try { sessionStorage.setItem('growth_website_id', websiteId); } catch (error) {}
        if (noWebsiteBox) noWebsiteBox.style.display = 'none';
        if (contentBox) contentBox.style.display = 'block';

        const [dashRes, actionsRes] = await Promise.all([
            fetchJSON(`/api/executive-dashboard?website_id=${websiteId}`).catch(() => ({success:false})),
            fetchJSON(`/api/action-center?website_id=${websiteId}`).catch(() => ({success:false})),
        ]);
        if (version !== growthLoadVersion || (sel && sel.value !== websiteId)) return;

        if (dashRes.success && dashRes.data && dashRes.data.scores) {
            const s = dashRes.data.scores;
            // من غير بيانات: "—" في مكان الرقم وجملة صغيرة تحته (كانت الجملة بتتكتب بخط الرقم الكبير)
            const scoreTile = (icon, value, label) => {
                const empty = value === null || value === undefined;
                return `<div class="p-card stat-tile"><div class="stat-icon blue">${icon}</div><div class="stat-info"><div class="stat-value">${empty ? '—' : Math.round(value)}</div><div class="stat-label">${label}${empty ? `<div class="p-cell-muted" style="font-size:11px">${I18N['growth.scores.no_data']}</div>` : ''}</div></div></div>`;
            };
            document.getElementById('growthScores').innerHTML =
                scoreTile('🚀', s.overall_growth_score, I18N['growth.scores.overall']) +
                scoreTile('🔍', s.seo_score, I18N['growth.scores.seo']) +
                scoreTile('👁️', s.visibility_score, I18N['growth.scores.visibility']) +
                scoreTile('🕵️', s.competitor_score, I18N['growth.scores.competitor']) +
                scoreTile('⭐', s.reputation_score, I18N['growth.scores.reputation']) +
                scoreTile('📝', s.content_score, I18N['growth.scores.content']);

            const oppBox = document.getElementById('growthOpportunities');
            const opps = dashRes.data.top_opportunities || [];
            oppBox.innerHTML = opps.length
                ? opps.map(o => `<div style="border-right:3px solid #3FA796;padding-right:10px;font-size:12.5px;">🎯 ${esc(o.title)}</div>`).join('')
                : `<div class="p-cell-muted">${I18N['growth.opportunities.none']}</div>`;

            const probBox = document.getElementById('growthProblems');
            const probs = dashRes.data.top_problems || [];
            probBox.innerHTML = probs.length
                ? probs.map(p => `<div style="border-right:3px solid #D64545;padding-right:10px;font-size:12.5px;">⚠️ ${esc(p.title)}</div>`).join('')
                : `<div class="p-cell-muted">${I18N['growth.problems.none']}</div>`;
        }

        if (!dashRes.success || !dashRes.data || !dashRes.data.scores) {
            ['growthScores', 'growthOpportunities', 'growthProblems'].forEach(id => {
                const box = document.getElementById(id);
                if (box) box.innerHTML = '<div class="p-cell-muted" role="alert">تعذر تحميل تقرير النمو. <button class="p-btn outline xs" onclick="loadGrowth()">إعادة المحاولة</button></div>';
            });
        }
        const actionsBox = document.getElementById('growthActions');
        if (actionsRes.success) {
            const items = actionsRes.data.items || [];
            actionsBox.innerHTML = items.length
                ? items.map(i => `<div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;"><span>${esc(i.title)}</span><span class="p-badge">${esc(i.priority)}</span></div>`).join('')
                : `<div class="p-cell-muted">${I18N['growth.actions.none']}</div>`;
        } else {
            actionsBox.innerHTML = '<div class="p-cell-muted" role="alert">تعذر تحميل الإجراءات. <button class="p-btn outline xs" onclick="loadGrowth()">إعادة المحاولة</button></div>';
        }
    }

    // ---------- Action Center: طبقة التنفيذ (استعراض/تنفيذ) ----------
    window.actionCenterExec = async function (dryRun) {
        const box = document.getElementById('growthActionsResult');
        const sel = document.getElementById('growthWebsiteSelect');
        const websiteId = sel ? sel.value : null;
        const qs = websiteId ? `?website_id=${websiteId}` : '';
        box.innerHTML = dryRun ? 'جارِ استعراض الإجراءات القابلة للتنفيذ...' : 'جارِ التنفيذ...';
        const res = await fetchJSON(`/api/action-center/actions/execute${qs}`, {
            method: 'POST',
            body: JSON.stringify({ dry_run: dryRun ? 1 : 0 })
        });
        if (!res.success) { box.innerHTML = esc(res.error || 'فشل التنفيذ'); toast(res.error || 'فشل التنفيذ', 'error'); return; }
        const s = res.data.summary;
        let msg = dryRun
            ? `الاستعراض: ${s.planned} إجراء (${s.skipped} متكرر مسبقًا)`
            : `تم: ${s.tasks_created} مهمة جديدة · ${s.notifications_sent} إشعار · ${s.skipped} متكرر`;
        box.innerHTML = msg;
        toast(msg, dryRun ? 'success' : 'success');
        loadGrowth();
    };

    window.growthAskAdvisor = async function () {
        const input = document.getElementById('growthAdvisorInput');
        const btn = document.getElementById('growthAdvisorBtn');
        const answerBox = document.getElementById('growthAdvisorAnswer');
        const question = input.value.trim();
        if (!question) return;

        btn.disabled = true;
        btn.textContent = I18N['growth.advisor.asking'];
        answerBox.textContent = '';

        try {
            const res = await fetchJSON('/api/executive/ceo-advisor/ask', { method: 'POST', body: JSON.stringify({ question }) });
            answerBox.textContent = res.success ? res.data.answer : (res.error || I18N['growth.advisor.asking']);
        } finally {
            btn.disabled = false;
            btn.textContent = I18N['growth.advisor.ask'];
        }
    };

    async function boot() {
        if (tab === 'overview') {
            await loadOverview();
            return;
        }

        try {
            if (tab === 'analytics') await loadAnalytics(30);
            else if (tab === 'activity') await loadActivity();
            else if (tab === 'executive') { await loadExecutive(); await loadExecutiveExtras(); }
            else if (tab === 'growth') { await loadGrowth(); }
        } catch (e) {
            toast(__LOAD_FAILED__, 'error');
        } finally {
            const loading = document.getElementById('loadingMsg');
            const content = document.getElementById('dashboardContent');
            if (loading) loading.style.display = 'none';
            if (content) content.style.display = 'block';
        }
    }
    boot();
})();
JS;
        $script = str_replace(
            ['__TAB__', '__END_SESSION_FAILED__', '__START_TRIAL__', '__TRIAL__', '__PAST_DUE__', '__FREE_PLAN__', '__LOAD_FAILED__', '__RETRY__', '__NO_REVIEWS__', '__NO_ACTIVITY__', '__NO_NOTIFICATIONS__', '__NO_LOGINS__', '__POSITIVE__', '__NEUTRAL__', '__NEGATIVE__'],
            [$tab, $tEndSessionFailed, $tStartTrial, $tTrial, $tPastDue, $tFree, $tLoadFailed, $tRetry, $tNoReviews, $tNoActivity, $tNoNotifications, $tNoLogins, $tPositive, $tNeutral, $tNegative],
            $script
        );

        $onboardingScript = $tab === 'overview' ? $this->buildOnboardingScript() : '';

        // توحيد: بنمرر كل حاجة لـ renderPanelPage() المشترك في
        // app/Core/Controller.php عشان شِل اللوحة يفضل مصدرًا وحيدًا
        // عبر كل الأقسام (سايد بار موحد + شريط علوي بكرات/إشعارات/لغات).
        $loadingLabel = htmlspecialchars($this->tr('common.loading'), ENT_QUOTES, 'UTF-8');
        $progressiveOverview = $tab === 'overview';
        $bodyHtml = $impersonationBanner
            . ($progressiveOverview ? '' : '<div id="loadingMsg" class="p-empty" role="status"><div class="p-empty-icon">⏳</div>' . $loadingLabel . '</div>')
            . '<div id="dashboardContent"' . ($progressiveOverview ? '' : ' style="display:none;"') . '>' . $panelBody . '</div>';

        return $this->renderPanelPage($tab, $pageTitle, $pageSubtitle, $bodyHtml, $script . "\n" . $onboardingScript);
    }

    /**
     * دليل ترحيبي للعميل الجديد - شريط خطوات فوق الداشبورد الرئيسية،
     * بيختفي تلقائيًا لما العميل يخلّص كل الخطوات الأساسية. الحالة
     * محسوبة من بيانات حقيقية (مفيش عمود جديد في قاعدة البيانات).
     * السكريبت ده معزول تمامًا عن السكريبت الضخم في renderDashboardPage()
     * عشان نتجنب مخاطرة تعديل الملف الحساس ده.
     */
    /** كارت «خطة أول 3 أيام» (بيترسم مكان دليل أول حجز في #onboardingChecklist) */
    private function trialPlanScript(array $p): string
    {
        $ar = $p['lang'] === 'ar';
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $left = $p['hours_left'] >= 24 ? (int) ceil($p['hours_left'] / 24) : $p['hours_left'];
        $leftUnit = $p['hours_left'] >= 24 ? ($ar ? ($left === 1 ? 'يوم' : ($left === 2 ? 'يومين' : 'أيام')) : ($left === 1 ? 'day' : 'days')) : ($ar ? 'ساعة' : ($left === 1 ? 'hour' : 'hours'));
        $cols = '';
        foreach ($p['plan'] as $n => $day) {
            $state = $n < $p['day'] ? 'past' : ($n === $p['day'] ? 'today' : 'next');
            $tasks = '';
            foreach ($day['tasks'] as $task) {
                $tasks .= '<a class="tp-task' . ($task['done'] ? ' done' : '') . '" href="' . $e($task['link']) . '">'
                    . '<span class="tp-check" aria-hidden="true">' . ($task['done'] ? '✓' : '') . '</span>'
                    . '<span><b>' . $e($task['label']) . '</b><small>' . $e($task['why']) . '</small></span></a>';
            }
            $badge = $state === 'today' ? '<span class="tp-today">' . ($ar ? 'النهارده' : 'Today') . '</span>' : '';
            $cols .= '<div class="tp-day tp-s-' . $state . '"><div class="tp-day-head"><span>' . ($ar ? 'اليوم ' : 'Day ') . (int) $n . '</span>' . $badge . '</div><h4>' . $e($day['title']) . '</h4>' . $tasks . '</div>';
        }
        $value = '';
        foreach ($p['value'] as [$n, $label]) {
            $value .= '<span class="tp-val"><b dir="ltr">' . (int) $n . '</b> ' . $e($label) . '</span>';
        }
        $pct = $p['total'] > 0 ? (int) round($p['done'] / $p['total'] * 100) : 0;
        $html = '<style>'
            . '.tp-card{border:1px solid rgba(37,99,235,.35);border-radius:16px;padding:18px 18px 14px;background:var(--panel-card-bg,#fff)}'
            . '.tp-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.tp-top h3{margin:0;font-size:18px}.tp-top p{margin:4px 0 0;color:var(--panel-text-muted,#64748b);font-size:13.5px}'
            . '.tp-left{font-size:13px;padding:5px 12px;border-radius:999px;background:rgba(37,99,235,.08);color:var(--panel-accent,#2563eb);font-weight:700;white-space:nowrap}'
            . '.tp-bar{height:6px;border-radius:6px;background:var(--panel-border,#e5e7eb);margin:14px 0}.tp-bar>div{height:100%;border-radius:6px;background:linear-gradient(90deg,#16a34a,#2563eb)}'
            . '.tp-days{display:grid;grid-template-columns:repeat(' . count($p['plan']) . ',minmax(0,1fr));gap:12px}@media(max-width:900px){.tp-days{grid-template-columns:1fr}}'
            . '.tp-day{border:1px solid var(--panel-border,#e5e7eb);border-radius:12px;padding:12px}.tp-today{font-size:11px;background:#2563eb;color:#fff;border-radius:999px;padding:2px 8px}.tp-day.tp-s-today{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08)}.tp-day.tp-s-next{opacity:.75}'
            . '.tp-day-head{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--panel-text-muted,#64748b);font-weight:700}.tp-day h4{margin:4px 0 8px;font-size:15px}'
            . '.tp-task{display:flex;gap:9px;align-items:flex-start;padding:7px 0;text-decoration:none;color:inherit;border-top:1px dashed var(--panel-border,#e5e7eb)}.tp-task b{display:block;font-size:13.5px}.tp-task small{display:block;font-size:12px;color:var(--panel-text-muted,#64748b);line-height:1.6}'
            . '.tp-check{flex:0 0 20px;height:20px;border-radius:50%;border:2px solid var(--panel-border,#cbd5e1);display:grid;place-items:center;font-size:12px;color:#fff;margin-top:2px}.tp-task.done .tp-check{background:#16a34a;border-color:#16a34a}.tp-task.done b{text-decoration:line-through;opacity:.6}'
            . '.tp-values{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center;font-size:13px}.tp-val{background:rgba(22,163,74,.08);color:#15803d;border-radius:999px;padding:4px 10px}'
            . '</style>'
            . '<div class="tp-card"><div class="tp-top"><div><h3>🎯 ' . ($ar ? 'خطة تجربتك المجانية' : 'Your free trial plan') . '</h3><p>' . ($ar ? 'كل مهمة بتطلّعلك نتيجة حقيقية في حسابك، وبتتعلّم لوحدها لما تخلص.' : 'Each task gives you a real result in your account and ticks itself when done.') . '</p></div>'
            . '<span class="tp-left">⏳ ' . ($ar ? 'باقي' : '') . ' <span dir="ltr">' . $left . '</span> ' . $leftUnit . ($ar ? '' : ' left') . '</span></div>'
            . '<div class="tp-bar" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div style="width:' . $pct . '%"></div></div>'
            . '<div class="tp-days">' . $cols . '</div>'
            . ($value !== '' ? '<div class="tp-values"><b style="font-size:13px">' . ($ar ? 'اللي المنصة عملته لك لحد دلوقتي:' : 'What the platform has done for you so far:') . '</b>' . $value . '</div>' : '')
            . '</div>';
        $json = json_encode($html, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return "(function(){var el=document.getElementById('onboardingChecklist');if(el){el.innerHTML={$json};el.hidden=false;el.style.display='';}})();";
    }

    private function buildOnboardingScript(): string
    {
        try {
            $userId = (int) $this->user['id'];

            // في التجربة المجانية: «خطة أول 3 أيام» مكان «طريقك لأول حجز»
            if (class_exists('TrialPlanService')) {
                $trialPlan = (new TrialPlanService())->forUser($userId);
                if ($trialPlan) {
                    return $this->trialPlanScript($trialPlan);
                }
            }

            // طريق العميل لأول حجز (قبل كده الخطوات كانت SEO بس: تحليل/مقال/تكامل،
            // ومفيهاش ولا خطوة بتوصّل لحجز)
            $q = function (string $sql, array $p) {
                try {
                    return (int) ($this->db->query($sql, $p)[0]['c'] ?? 0);
                } catch (Throwable $e) {
                    return 0;
                }
            };
            $site = null;
            try {
                $site = $this->db->query("SELECT slug FROM generated_websites WHERE user_id = ? AND status = 'published' ORDER BY id LIMIT 1", [$userId])[0] ?? null;
            } catch (Throwable $e) {
            }
            $siteCount = $q("SELECT COUNT(*) AS c FROM generated_websites WHERE user_id = ?", [$userId]);
            $tours = $q("SELECT COUNT(*) AS c FROM crm_products WHERE user_id = ? AND is_active = 1 AND website_id IS NOT NULL AND tour_slug IS NOT NULL", [$userId]);
            $avail = $q("SELECT COUNT(*) AS c FROM inventory WHERE user_id = ? AND date >= CURDATE() AND capacity > 0", [$userId]);
            $pay = $q("SELECT COUNT(*) AS c FROM operator_payment_settings WHERE user_id = ? AND mode <> 'request'", [$userId])
                + (class_exists('OperatorPayments') && OperatorPayments::hasManualMethods($userId) ? 1 : 0);
            $kb = $q("SELECT COUNT(*) AS c FROM ai_knowledge_base k JOIN websites w ON w.id = k.website_id WHERE w.user_id = ? AND k.deleted_at IS NULL", [$userId]);
            $bookings = $q("SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?", [$userId]);
            $siteUrl = $site ? '/sites/' . rawurlencode((string) $site['slug']) : '/website-builder';
            $wizardDone = $q("SELECT COUNT(*) AS c FROM websites WHERE user_id = ? AND onboarding_completed_at IS NOT NULL", [$userId]) > 0;
            $firstProduct = $q("SELECT MIN(id) AS c FROM crm_products WHERE user_id = ? AND is_active = 1", [$userId]);

            $steps = [
                ['done' => $site !== null, 'icon' => '🌐', 'label' => $siteCount > 0 ? 'انشر موقع الحجز' : 'اعمل موقع الحجز', 'link' => '/website-builder', 'hint' => 'موقع جاهز فيه رحلاتك وزرار احجز - بيتعمل في دقايق بالذكاء الاصطناعي.'],
                ['done' => $tours > 0, 'icon' => '🧳', 'label' => 'ضيف رحلة قابلة للحجز', 'link' => '/website-builder', 'hint' => 'كل رحلة على موقعك بتبقى قابلة للحجز أونلاين لوحدها.'],
                ['done' => $avail > 0, 'icon' => '📅', 'label' => 'حدد الأماكن المتاحة', 'link' => '/bookings' . ($firstProduct > 0 ? '?product=' . $firstProduct : ''), 'hint' => 'قول كام حجز تقبل كل يوم عشان مايحصلش حجز زيادة.'],
                ['done' => $pay > 0, 'icon' => '💳', 'label' => 'اختار إزاي تستلم الفلوس', 'link' => '/bookings?pay=setup', 'hint' => 'PayPal أو InstaPay أو محفظة أو تحويل بنكي - السائح يدفع العربون من لينك بتبعتهوله.'],
                ['done' => $kb >= 3, 'icon' => '🤖', 'label' => 'علّم الشات بوت عن شركتك', 'link' => '/chat/knowledge-base', 'hint' => 'حط رابط صفحة "من نحن" أو بروشور PDF والبوت يتعلم منه لوحده.'],
                ['done' => $bookings > 0, 'icon' => '🎉', 'label' => 'شارك الرابط وخد أول حجز', 'link' => $siteUrl, 'hint' => 'ابعت رابط موقع الحجز على واتساب وفيسبوك وحطه في البايو.'],
            ];
            // An existing customer website does not require building another one.
            $externalSite = $this->db->query('SELECT * FROM websites WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId])[0] ?? null;
            if ($siteCount === 0 && $externalSite) {
                $ar = current_lang() === 'ar';
                // كلام بسيط وخطوة واضحة (كان: «راجع ربط الرحلات والقياس؛ الرحلات الخارجية لا تتحول تلقائيًا…»)
                $allTrips = $q('SELECT COUNT(*) AS c FROM crm_products WHERE user_id = ? AND is_active = 1', [$userId]);
                $steps[0] = ['done' => true, 'icon' => '🌐',
                    'label' => $ar ? 'موقعك مضاف' : 'Your website is added',
                    'link' => '/integrations', 'hint' => 'تقدر تتأكد إنه شغّال من «الربط والتكاملات» ← «اختبار الموقع».'];
                $steps[1] = ['done' => $allTrips > 0, 'icon' => '🧳',
                    'label' => $ar ? 'ضيف رحلاتك وأسعارها' : 'Add your trips and prices',
                    'link' => '/bookings?tab=trips', 'hint' => 'الشات الذكي بيبيعها لوحده بالسعر الصح، وتقدر تسجّل عليها حجوزات التليفون والواتساب.'];
                $steps[2]['label'] = $ar ? 'حدد الأماكن المتاحة (اختياري)' : 'Set available spots (optional)';
                $steps[2]['optional'] = true;
                $steps[2]['hint'] = 'لو بتسجّل حجوزاتك هنا: قول كام مكان متاح كل يوم عشان مايحصلش حجز زيادة.';
                $steps[3]['label'] = $ar ? 'استلام الفلوس أونلاين (اختياري)' : 'Get paid online (optional)';
                $steps[3]['optional'] = true;
                $steps[3]['hint'] = 'اكتب PayPal أو InstaPay أو بيانات التحويل، والسائح يدفع العربون من لينك بتبعتهوله.';
                $steps[5] = ['done' => $bookings > 0, 'icon' => '🎉',
                    'label' => $ar ? 'سجّل أول حجز' : 'Record your first booking',
                    'link' => '/bookings', 'hint' => 'حجز جالك بالتليفون أو الواتساب؟ سجّله من «➕ حجز جديد» ويوصل للسائح تذكير قبل الرحلة.'];
            }
            if ($bookings > 0) {
                return ''; // وصل لأول حجز - الدليل خلص دوره
            }

            $doneCount = count(array_filter($steps, fn ($s) => $s['done']));
            if ($doneCount >= count($steps)) {
                return ''; // خلّص كل الخطوات - مفيش داعي نعرض حاجة
            }

            $title = 'طريقك لأول حجز';
            $subtitle = 'خلّص الخطوات دي وموقعك يبدأ يستقبل حجوزات لوحده';
            $dismiss = $this->tr('onboarding.dismiss');

            $hintsEn = [
                '🌐' => 'A ready site with your tours and a Book button — built in minutes with AI.',
                '🧳' => 'Every tour on your site becomes bookable online on its own.',
                '📅' => 'Set how many bookings you accept per day so you never overbook.',
                '💳' => 'A booking request you follow up yourself, or online payment to your own Stripe account.',
                '🤖' => 'Add your About page link or a PDF brochure and the bot learns from it.',
                '🎉' => 'Share your booking site link on WhatsApp, Facebook and your bio.',
            ];
            if ($siteCount === 0 && $externalSite) {
                $hintsEn['🌐'] = 'You can check it works in Integrations → Test website.';
                $hintsEn['🧳'] = 'The AI chat sells them on its own at the right price, and you can record phone and WhatsApp bookings on them.';
                $hintsEn['📅'] = 'If you record bookings here: set how many spots you have each day so you never overbook.';
                $hintsEn['💳'] = 'If you want travellers to pay the deposit online from a link you send them.';
                $hintsEn['🎉'] = 'Got a booking by phone or WhatsApp? Record it with “➕ New booking” and the traveller gets a reminder before the trip.';
            }
            $itemsHtml = '';
            $nextHint = '';
            // «الخطوة الجاية» = أول خطوة أساسية مش خالصة؛ الاختياري بييجي في الآخر
            $nextIndex = null;
            foreach ($steps as $i => $st) {
                if (!$st['done'] && empty($st['optional'])) { $nextIndex = $i; break; }
            }
            if ($nextIndex === null) {
                foreach ($steps as $i => $st) {
                    if (!$st['done']) { $nextIndex = $i; break; }
                }
            }
            foreach ($steps as $i => $step) {
                $isNext = $i === $nextIndex;
                if ($isNext) {
                    // الجملة المركّبة (عنوان - شرح) مش في قاموس الترجمة فكانت بتظهر عربي في الواجهة الإنجليزي
                    $hint = current_lang() === 'ar' ? $step['hint'] : ($hintsEn[$step['icon']] ?? '');
                    $nextHint = '<div class="onboarding-next">👉 <b>' . (['ar' => 'الخطوة الجاية', 'fr' => 'Étape suivante', 'de' => 'Nächster Schritt'][current_lang()] ?? 'Next step') . ':</b> <span>' . htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') . '</span>' . ($hint !== '' ? ' — <span>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</span>' : '') . '</div>';
                }
                $doneClass = $step['done'] ? ' onboarding-done' : ($isNext ? ' onboarding-next-step' : '');
                $checkIcon = $step['done'] ? '✔' : $step['icon'];
                $itemsHtml .= '<a href="' . htmlspecialchars($step['link'], ENT_QUOTES, 'UTF-8') . '" class="onboarding-step' . $doneClass . '"><span class="oi">' . $checkIcon . '</span>' . htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            }

            // Phase 19: زرار "الإعداد السريع" لو المستخدم لسه مكمّلش الـWizard (7 خطوات)
            $wizardCta = '';
            if (!$wizardDone) {
                $wizardCta = '<a href="/onboarding" class="onboarding-wizard-cta">' . htmlspecialchars($this->tr('onboarding.wizard_cta'), ENT_QUOTES, 'UTF-8') . '</a>';
            }

            $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $subtitleEsc = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
            $dismissEsc = htmlspecialchars($dismiss, ENT_QUOTES, 'UTF-8');
            $progressPct = (int) round(($doneCount / count($steps)) * 100);
            $setupSummary = current_lang()==='ar' ? 'خطوات الإعداد' : 'Setup steps';
            $totalSteps = count($steps);

            return <<<JS
(function () {
    try { if (localStorage.getItem('onboarding_booking_dismissed') === '1') return; } catch (e) {}
    var el = document.getElementById('onboardingChecklist');
    if (!el) return;
    el.innerHTML = `
        <div class="p-card" style="margin-bottom:18px;border:1px solid var(--panel-accent);position:relative;">
            <button onclick="this.closest('.p-card').remove();try{localStorage.setItem('onboarding_booking_dismissed','1');}catch(e){}" style="position:absolute;top:12px;inset-inline-end:12px;background:none;border:none;color:var(--panel-text-muted);cursor:pointer;font-size:13px;">✕ {$dismissEsc}</button>
            <div class="p-card-head" style="padding-inline-end:80px"><h3>👋 {$titleEsc}</h3><span class="p-card-sub">{$subtitleEsc}</span></div>
            <div style="height:6px;background:var(--panel-border);border-radius:3px;overflow:hidden;margin-bottom:16px;">
                <div style="height:100%;width:{$progressPct}%;background:var(--panel-accent);"></div>
            </div>
            <details class="onboarding-mobile-steps"><summary>{$setupSummary} ({$doneCount}/{$totalSteps})</summary><div class="onboarding-step-list">{$itemsHtml}</div></details>
            {$nextHint}
            {$wizardCta}
        </div>
        <style>
            .onboarding-step-list{display:flex;gap:10px;flex-wrap:wrap}
            .onboarding-mobile-steps>summary{cursor:pointer;font-weight:600;margin-bottom:10px}
            @media(min-width:701px){.onboarding-mobile-steps>summary{display:none}}
            @media(max-width:700px){#onboardingChecklist .p-card-sub{display:none}#onboardingChecklist .p-card-head{display:block;padding-inline-end:65px}#onboardingChecklist .onboarding-wizard-cta{font-size:12px}}
            .onboarding-step { display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:20px;background:var(--panel-card-bg-2);color:var(--panel-text);text-decoration:none;font-size:12.5px;border:1px solid var(--panel-border); }
            .onboarding-step:hover { border-color:var(--panel-accent); }
            .onboarding-step.onboarding-done { opacity:.55;text-decoration:line-through; }
            .onboarding-step .oi { font-size:14px; }
            .onboarding-step.onboarding-next-step { border-color:var(--panel-accent);box-shadow:0 0 0 2px color-mix(in srgb, var(--panel-accent) 25%, transparent);font-weight:700; }
            .onboarding-next { margin-top:12px;font-size:13px;color:var(--panel-text); }
            .onboarding-wizard-cta { display:inline-block;margin-top:14px;padding:10px 18px;border-radius:22px;background:var(--panel-accent,#1F4FFF);color:#fff;font-weight:700;text-decoration:none;font-size:13px; }
            .onboarding-wizard-cta:hover { filter:brightness(1.1); }
        </style>`;
    var checklist = el.querySelector('.onboarding-mobile-steps');
    var desktopChecklist = window.matchMedia('(min-width:701px)');
    if (checklist) {
        var syncChecklist = function (e) { checklist.open = e.matches; };
        syncChecklist(desktopChecklist);
        desktopChecklist.addEventListener('change', syncChecklist);
    }

})();
JS;
        } catch (Exception $e) {
            Logger::error('buildOnboardingScript Error', ['message' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * جسم اللوحة حسب التبويب المختار
     * @param string $tab
     * @return string
     */
    private function renderDashboardPanelBody(string $tab): string
    {
        switch ($tab) {
            case 'analytics':
                return <<<HTML
                <div class="p-toolbar">
                    <select id="analyticsDays" class="p-select" onchange="reloadAnalytics()">
                        <option value="7">{$this->tr('analytics.range.7d')}</option>
                        <option value="30" selected>{$this->tr('analytics.range.30d')}</option>
                        <option value="90">{$this->tr('analytics.range.90d')}</option>
                    </select>
                </div>
                <div class="p-grid cols-2">
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('analytics.reviews.title')}</h3><span class="p-card-sub">{$this->tr('analytics.reviews.sub')}</span></div>
                        <div class="chart-wrap"><canvas id="reviewsChart"></canvas></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('analytics.chat.title')}</h3><span class="p-card-sub">{$this->tr('analytics.chat.sub')}</span></div>
                        <div class="chart-wrap"><canvas id="chatChart"></canvas></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('analytics.api.title')}</h3><span class="p-card-sub">{$this->tr('analytics.api.sub')}</span></div>
                        <div class="chart-wrap"><canvas id="apiChart"></canvas></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('analytics.ai.title')}</h3></div>
                        <div class="chart-wrap"><canvas id="aiChart"></canvas></div>
                    </div>
                </div>
HTML;

            case 'activity':
                return <<<HTML
                <div class="p-grid cols-2">
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('activity.log.title')}</h3></div>
                        <div class="p-table-scroll"><table class="p-table" id="fullActivityTable">
                            <thead><tr><th>{$this->tr('activity.col.event')}</th><th>{$this->tr('activity.col.date')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="2">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('activity.login_log.title')}</h3></div>
                        <div class="p-table-scroll"><table class="p-table" id="fullLoginTable">
                            <thead><tr><th>{$this->tr('activity.col.result')}</th><th>{$this->tr('activity.col.ip')}</th><th>{$this->tr('activity.col.device')}</th><th>{$this->tr('activity.col.location')}</th><th>{$this->tr('activity.col.date')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="5">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                </div>
HTML;

            case 'executive':
                return <<<HTML
                <div class="p-grid cols-4" id="execKpis">
                    <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value" id="execRevenue">$0</div><div class="stat-label">{$this->tr('executive.kpi.revenue')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon blue">⭐</div><div class="stat-info"><div class="stat-value" id="execAvgRating">-</div><div class="stat-label">{$this->tr('executive.kpi.avg_rating')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">🧾</div><div class="stat-info"><div class="stat-value" id="execDeals">0</div><div class="stat-label">{$this->tr('executive.kpi.open_deals')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">📣</div><div class="stat-info"><div class="stat-value" id="execAdsSpend">$0</div><div class="stat-label">{$this->tr('executive.kpi.ads_spend')}</div></div></div>
                </div>
                <div class="p-grid cols-4" style="margin-top:14px;">
                    <div class="p-card stat-tile"><div class="stat-icon orange">💬</div><div class="stat-info"><div class="stat-value" id="execChats">0</div><div class="stat-label">{$this->tr('executive.kpi.chats')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon blue">🧲</div><div class="stat-info"><div class="stat-value" id="execLeads">0</div><div class="stat-label">{$this->tr('executive.kpi.leads')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon green">🤖</div><div class="stat-info"><div class="stat-value" id="execReports">0</div><div class="stat-label">{$this->tr('executive.kpi.reports')}</div></div></div>
                    <div class="p-card stat-tile"><div class="stat-icon purple">🔎</div><div class="stat-info"><div class="stat-value" id="execCompetitors">0</div><div class="stat-label">{$this->tr('executive.kpi.competitors')}</div></div></div>
                </div>

                <div class="p-grid cols-2" style="margin-top:18px;align-items:start;">
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('executive.pipeline.title')}</h3><a href="/crm" class="p-card-sub">{$this->tr('executive.pipeline.open')}</a></div>
                        <div class="chart-wrap sm"><canvas id="execPipelineChart"></canvas></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('executive.reputation_trend.title')}</h3><a href="/reputation/overview" class="p-card-sub">{$this->tr('executive.reputation_trend.open')}</a></div>
                        <div class="chart-wrap sm"><canvas id="execReputationChart"></canvas></div>
                    </div>
                </div>

                <div class="p-grid cols-2" style="margin-top:18px;align-items:start;">
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('executive.alerts.title')}</h3></div>
                        <div id="execAlerts" style="display:flex;flex-direction:column;gap:8px;">
                            <div class="p-loading-row">{$this->tr('common.loading')}</div>
                        </div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>{$this->tr('executive.risks.title')}</h3></div>
                        <div id="execRisksOpportunities" style="display:flex;flex-direction:column;gap:10px;">
                            <div class="p-loading-row">{$this->tr('common.loading')}</div>
                        </div>
                        <div style="padding:0 20px 16px;display:flex;gap:6px;">
                            <input type="text" id="execNoteInput" class="p-input" placeholder="{$this->tr('executive.note.placeholder')}" style="flex:1;">
                            <button class="p-btn outline xs" onclick="execAddNote()">{$this->tr('executive.note.save')}</button>
                        </div>
                    </div>
                </div>

                <div class="p-grid cols-2" style="margin-top:18px;align-items:start;">
                    <div class="p-card"><p>حالة ربط Google والموقع تظهر في لوحة الجاهزية أعلاه. الربط المحفوظ لا يثبت وجود بيانات للفترة المختارة.</p><a href="/integrations">إدارة الاتصالات</a></div>
                    <div class="p-card no-pad">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('executive.tasks.title')}</h3></div>
                        <div id="execTasks" style="padding:0 20px 16px;display:flex;flex-direction:column;gap:8px;">
                            <div class="p-loading-row">{$this->tr('common.loading')}</div>
                        </div>
                    </div>
                </div>
HTML;

            case 'growth':
                return <<<HTML
                <div id="growthWebsitePicker" style="margin-bottom:16px;">
                    <select id="growthWebsiteSelect" class="p-input" style="max-width:320px;" onchange="loadGrowth()"></select>
                </div>
                <div id="growthNoWebsite" class="p-card" style="display:none;padding:20px;text-align:center;color:var(--muted);">
                    {$this->tr('growth.select_website')}
                </div>
                <div id="growthContent" style="display:none;">
                    <div class="p-grid cols-3" id="growthScores" style="margin-bottom:18px;">
                        <div class="p-loading-row">{$this->tr('common.loading')}</div>
                    </div>

                    <div class="p-grid cols-2" style="align-items:start;">
                        <div class="p-card no-pad">
                            <div class="p-card-head" style="padding:18px 20px 0;"><h3>🎯 {$this->tr('growth.opportunities.title')}</h3></div>
                            <div id="growthOpportunities" style="padding:0 20px 16px;display:flex;flex-direction:column;gap:8px;">
                                <div class="p-loading-row">{$this->tr('common.loading')}</div>
                            </div>
                        </div>
                        <div class="p-card no-pad">
                            <div class="p-card-head" style="padding:18px 20px 0;"><h3>⚠️ {$this->tr('growth.problems.title')}</h3></div>
                            <div id="growthProblems" style="padding:0 20px 16px;display:flex;flex-direction:column;gap:8px;">
                                <div class="p-loading-row">{$this->tr('common.loading')}</div>
                            </div>
                        </div>
                    </div>

                    <div class="p-card no-pad" style="margin-top:18px;">
                        <div class="p-card-head" style="padding:18px 20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                            <h3>✅ {$this->tr('growth.actions.title')}</h3>
                            <div style="display:flex;gap:6px;">
                                <button class="p-btn outline xs" onclick="actionCenterExec(1)">استعراض التنفيذ</button>
                                <button class="p-btn primary xs" onclick="actionCenterExec(0)">⚡ تنفيذ الإجراءات</button>
                            </div>
                        </div>
                        <div id="growthActions" style="padding:0 20px 16px;display:flex;flex-direction:column;gap:8px;">
                            <div class="p-loading-row">{$this->tr('common.loading')}</div>
                        </div>
                        <div id="growthActionsResult" style="padding:0 20px 16px;font-size:12.5px;white-space:pre-wrap;"></div>
                    </div>

                    <div class="p-card" style="margin-top:18px;">
                        <div class="p-card-head"><h3>🤖 {$this->tr('growth.advisor.title')}</h3></div>
                        <div style="padding:0 20px 20px;display:flex;flex-direction:column;gap:10px;">
                            <div style="display:flex;gap:8px;">
                                <input type="text" id="growthAdvisorInput" class="p-input" placeholder="{$this->tr('growth.advisor.placeholder')}" style="flex:1;">
                                <button class="p-btn primary xs" id="growthAdvisorBtn" onclick="growthAskAdvisor()">{$this->tr('growth.advisor.ask')}</button>
                            </div>
                            <div id="growthAdvisorAnswer" style="font-size:13px;line-height:1.7;white-space:pre-wrap;"></div>
                        </div>
                    </div>
                </div>
HTML;

            case 'overview':
            default:
                return <<<HTML
                <div id="onboardingChecklist"></div>

                <div class="p-card" style="margin-bottom:18px;">
                    <div class="p-card-head"><h3>{$this->tr('dashboard.quick_actions')}</h3></div>
                    <div class="p-grid cols-4">
                        <a href="/ai/analyze" class="quick-tile"><span class="qi">✨</span><span class="qt">{$this->tr('dashboard.action.new_seo_analysis')}</span><span class="qd">{$this->tr('dashboard.action.new_seo_analysis_sub')}</span></a>
                        <a href="/reputation/reviews" class="quick-tile"><span class="qi">⭐</span><span class="qt">{$this->tr('dashboard.action.review_ratings')}</span><span class="qd">{$this->tr('dashboard.action.review_ratings_sub')}</span></a>
                        <a href="/website-optimizer" class="quick-tile"><span class="qi">🛠️</span><span class="qt">{$this->tr('dashboard.action.audit_website')}</span><span class="qd">{$this->tr('dashboard.action.audit_website_sub')}</span></a>
                        <a href="/subscription" class="quick-tile"><span class="qi">💳</span><span class="qt">{$this->tr('dashboard.action.manage_subscription')}</span><span class="qd">{$this->tr('dashboard.action.manage_subscription_sub')}</span></a>
                    </div>
                </div>

                <div class="p-grid cols-4" style="margin-bottom:18px;">
                    <div class="p-card stat-tile orbit-glow"><div class="stat-icon blue">⭐</div><div class="stat-info"><div class="stat-value dashboard-value-pending" id="statReviews">—</div><div class="stat-label">{$this->tr('dashboard.kpi.reviews_30d')}</div></div></div>
                    <div class="p-card stat-tile orbit-glow"><div class="stat-icon green">💬</div><div class="stat-info"><div class="stat-value dashboard-value-pending" id="statChat">—</div><div class="stat-label">{$this->tr('dashboard.kpi.chat_messages')}</div></div></div>
                    <div class="p-card stat-tile orbit-glow"><div class="stat-icon purple">🤖</div><div class="stat-info"><div class="stat-value dashboard-value-pending" id="statReports">—</div><div class="stat-label">{$this->tr('dashboard.kpi.ai_reports')}</div></div></div>
                    <div class="p-card stat-tile orbit-glow"><div class="stat-icon orange">💳</div><div class="stat-info"><div class="stat-value dashboard-value-pending" id="statSubscription">—</div><div class="stat-label">{$this->tr('dashboard.kpi.subscription_plan')}</div></div></div>
                </div>
                <div id="statsError" class="dashboard-stats-error" hidden></div>

                <div class="p-card" id="smartInsightsCard" style="margin-bottom:18px;display:none;">
                    <div class="p-card-head"><h3>💡 {$this->tr('dashboard.smart_insights')}</h3><span class="p-card-sub">{$this->tr('dashboard.smart_insights_sub')}</span></div>
                    <div id="smartInsightsList"></div>
                </div>

                <details class="dash-more">
                    <summary>{$this->tr('dashboard.more_details')}</summary>
                    <div class="p-grid cols-3" style="margin-top:14px;">
                    <div class="p-card" style="grid-column: span 1;">
                        <div class="p-card-head"><h3>{$this->tr('dashboard.review_distribution')}</h3><span class="p-card-sub">{$this->tr('dashboard.last_30_days')}</span></div>
                        <div class="chart-wrap sm"><canvas id="sentimentChart"></canvas><div id="sentimentEmpty" class="p-empty dashboard-chart-empty" hidden></div></div>
                    </div>
                    <div class="p-card no-pad" style="grid-column: span 1;">
                        <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('dashboard.recent_activity')}</h3><a href="/dashboard/activity" class="p-card-sub">{$this->tr('dashboard.view_all')}</a></div>
                        <div class="p-table-scroll"><table class="p-table" id="activityTable">
                            <thead><tr><th>{$this->tr('dashboard.event')}</th><th>{$this->tr('dashboard.time')}</th></tr></thead>
                            <tbody><tr class="p-loading-row"><td colspan="2">{$this->tr('common.loading')}</td></tr></tbody>
                        </table></div>
                    </div>
                    <div class="p-card" style="grid-column: span 1;">
                        <div class="p-card-head"><h3>{$this->tr('dashboard.notifications')}</h3></div>
                        <div id="notifList"><div class="p-empty" style="padding:20px;">{$this->tr('common.loading')}</div></div>
                        <h4 style="margin:16px 0 8px;font-size:13px;color:var(--panel-text-muted);">{$this->tr('dashboard.recent_logins')}</h4>
                        <div id="loginMiniList"></div>
                    </div>
                </div>
                </details>

HTML;
        }
    }
}
