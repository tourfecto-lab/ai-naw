<?php

/**
 * Tourfecto - Subscription Middleware
 * التحقق من صلاحية الاشتراك والرصيد
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SubscriptionMiddleware
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var SubscriptionValidator $subscriptionValidator - مدقق الاشتراكات
     */
    private $subscriptionValidator;

    /**
     * @var array $requirements - متطلبات الاشتراك
     */
    private $requirements = [];

    /**
     * @var int $userId - معرف المستخدم
     */
    private $userId = 0;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->subscriptionValidator = new SubscriptionValidator();
    }

    /**
     * معالجة الطلب
     * @return array|null
     */
    public function handle(): ?array
    {
        // الحصول على معرف المستخدم من الطلب
        $this->userId = $this->getUserId();

        if (!$this->userId) {
            return $this->error('User not authenticated', 401);
        }

        // التحقق من الاشتراك
        $subscription = $this->subscriptionValidator->validateSubscription($this->userId);

        if (!$subscription['valid']) {
            // الرسالة الأصلية تقنية بالإنجليزي ("No active subscription found.") ومن غير
            // توجيه - دي أهم لحظة تحويل لعميل جديد، فبنقوله يعمل إيه ونديله الرابط.
            return $this->upgradeRequired('الأداة دي محتاجة اشتراك فعّال. اختار باقة واشترك من رصيد المحفظة أو بالتحويل، وابدأ على طول.');
        }

        // التحقق من المتطلبات
        if (!empty($this->requirements)) {
            $checkResult = $this->checkRequirements($subscription);

            if (!$checkResult['valid']) {
                return $this->upgradeRequired('خلص رصيد باقتك الشهري للأداة دي. رقّي باقتك أو اشحن المحفظة عشان تكمّل.');
            }
        }

        // إضافة بيانات الاشتراك إلى الطلب
        $_SERVER['subscription'] = $subscription;

        return null;
    }

    /**
     * اشتراط وجود رصيد AI
     * @param int $amount
     * @return SubscriptionMiddleware
     */
    public function requireAICredits(int $amount = 1): self
    {
        $this->requirements['ai_credits'] = $amount;
        return $this;
    }

    /**
     * اشتراط وجود رصيد شات
     * @param int $amount
     * @return SubscriptionMiddleware
     */
    public function requireChatCredits(int $amount = 1): self
    {
        $this->requirements['chat_credits'] = $amount;
        return $this;
    }

    /**
     * اشتراط وجود رصيد ردود على المراجعات
     * @param int $amount
     * @return SubscriptionMiddleware
     */
    public function requireReviewCredits(int $amount = 1): self
    {
        $this->requirements['review_credits'] = $amount;
        return $this;
    }

    /**
     * تطبيق شرط عبر اسمه النصي (مستخدم من الـ Router لصيغة 'ClassName:modifier')
     * @param string $modifier
     * @return SubscriptionMiddleware
     */
    public function applyModifier(string $modifier): self
    {
        switch ($modifier) {
            case 'require_ai_credits':
                $this->requireAICredits(1);
                break;
            case 'require_chat_credits':
                $this->requireChatCredits(1);
                break;
            case 'require_review_credits':
                $this->requireReviewCredits(1);
                break;
            case 'require_competitor_analysis':
                $this->requireCompetitorAnalysis();
                break;
        }
        return $this;
    }

    /**
     * اشتراط وجود رصيد تحليل منافسين
     * @return SubscriptionMiddleware
     */
    public function requireCompetitorAnalysis(): self
    {
        $this->requirements['competitor_analysis'] = 1;
        return $this;
    }

    /**
     * اشتراط وجود ميزة معينة
     * @param string $feature
     * @return SubscriptionMiddleware
     */
    public function requireFeature(string $feature): self
    {
        $this->requirements['feature'] = $feature;
        return $this;
    }

    /**
     * اشتراط خطة اشتراك معينة
     * @param string $plan
     * @return SubscriptionMiddleware
     */
    public function requirePlan(string $plan): self
    {
        $this->requirements['plan'] = $plan;
        return $this;
    }

    /**
     * التحقق من المتطلبات
     * @param array $subscription
     * @return array
     */
    private function checkRequirements(array $subscription): array
    {
        // التحقق من الخطة
        if (isset($this->requirements['plan'])) {
            if ($subscription['plan'] !== $this->requirements['plan']) {
                return [
                    'valid' => false,
                    'message' => "This feature requires the '{$this->requirements['plan']}' plan"
                ];
            }
        }

        // التحقق من الميزة
        if (isset($this->requirements['feature'])) {
            $feature = $this->requirements['feature'];
            $features = $subscription['plan_features'] ?? [];

            if (!isset($features[$feature]) || !$features[$feature]) {
                return [
                    'valid' => false,
                    'message' => "This feature is not available in your current plan"
                ];
            }
        }

        // التحقق من رصيد AI
        if (isset($this->requirements['ai_credits'])) {
            $required = $this->requirements['ai_credits'];
            $remaining = $subscription['ai_credits_remaining'] ?? 0;

            if ($remaining < $required) {
                return [
                    'valid' => false,
                    'message' => "Insufficient AI credits. Required: {$required}, Remaining: {$remaining}"
                ];
            }
        }

        // التحقق من رصيد الشات
        if (isset($this->requirements['chat_credits'])) {
            $required = $this->requirements['chat_credits'];
            $remaining = $subscription['chat_credits_remaining'] ?? 0;

            if ($remaining < $required) {
                return [
                    'valid' => false,
                    'message' => "Insufficient chat credits. Required: {$required}, Remaining: {$remaining}"
                ];
            }
        }

        // التحقق من رصيد الردود على المراجعات
        if (isset($this->requirements['review_credits'])) {
            $required = $this->requirements['review_credits'];
            $remaining = $subscription['review_credits_remaining'] ?? 0;

            if ($remaining < $required) {
                return [
                    'valid' => false,
                    'message' => "Insufficient review credits. Required: {$required}, Remaining: {$remaining}"
                ];
            }
        }

        // التحقق من رصيد تحليل المنافسين
        if (isset($this->requirements['competitor_analysis'])) {
            $remaining = $subscription['competitor_analysis_remaining'] ?? 0;

            if ($remaining <= 0) {
                return [
                    'valid' => false,
                    'message' => "Competitor analysis limit reached for this month"
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * الحصول على معرف المستخدم
     * @return int
     */
    private function getUserId(): int
    {
        // من المتغيرات البيئية (AuthMiddleware)
        if (isset($_SERVER['auth_user_id'])) {
            return (int) $_SERVER['auth_user_id'];
        }

        // من الجلسة
        if (isset($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        // من معاملات الطلب
        if (isset($_POST['user_id'])) {
            return (int) $_POST['user_id'];
        }

        if (isset($_GET['user_id'])) {
            return (int) $_GET['user_id'];
        }

        // من التوكن
        $token = $this->getTokenFromRequest();
        if ($token) {
            $user = $this->getUserByToken($token);
            if ($user) {
                return (int) $user['id'];
            }
        }

        return 0;
    }

    /**
     * الحصول على التوكن من الطلب
     * @return string|null
     */
    private function getTokenFromRequest(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        if (isset($_COOKIE['auth_token'])) {
            return $_COOKIE['auth_token'];
        }

        return null;
    }

    /**
     * الحصول على مستخدم من التوكن
     * @param string $token
     * @return array|null
     */
    private function getUserByToken(string $token): ?array
    {
        try {
            // تصحيح: لا يوجد عمود is_active، العمود الفعلي هو status='active'
            $sql = "SELECT id FROM users WHERE api_token = :token AND status = 'active' LIMIT 1";
            $result = $this->db->query($sql, [':token' => $token]);

            return !empty($result) ? $result[0] : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * إرجاع استجابة خطأ
     * @param string $message
     * @param int $code
     * @return array
     */
    /** 403 + رابط صفحة الباقات (panel.js بيعرض زرار "شوف الباقات" تلقائيًا لأي رد فيه upgrade_url) */
    private function upgradeRequired(string $message): array
    {
        $response = $this->error($message, 403);
        $response['upgrade_url'] = '/plans';
        return $response;
    }

    private function error(string $message, int $code = 403): array
    {
        http_response_code($code);
        return [
            'success' => false,
            'error' => $message,
            'code' => $code
        ];
    }
}
