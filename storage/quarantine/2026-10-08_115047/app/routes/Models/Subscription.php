<?php
/**
 * Tourfecto - Subscription Model
 * نموذج الاشتراك مع إدارة الفوترة والاستخدام
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Subscription extends Model {
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'subscriptions';
    
    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'user_id',
        'plan_name',
        'plan_type',
        'status',
        'price',
        'currency',
        'ai_credits',
        'ai_credits_used',
        'chat_credits',
        'chat_credits_used',
        'review_credits',
        'review_credits_used',
        'competitor_analysis_limit',
        'competitor_analysis_used',
        'auto_pilot',
        'start_date',
        'expiry_date',
        'last_billed_at',
        'next_billing_at'
    ];

    /**
     * Constructor - نضيف اسم عمود الانتهاء الحقيقي لقائمة fillable ديناميكيًا
     * (انظر شرح expiryColumn() تحت) عشان mass-assignment يقبله حتى لو مختلف
     * عن 'expiry_date' الموجود في fillable الثابتة فوق.
     */
    public function __construct(array $attributes = []) {
        $col = self::expiryColumn();
        if ($col && !in_array($col, $this->fillable, true)) {
            $this->fillable[] = $col;
        }
        parent::__construct($attributes);
    }

    /**
     * @var string|null $expiryColumnCache - كاش ثابت لاسم عمود الانتهاء الحقيقي
     */
    private static $expiryColumnCache = null;

    /**
     * تصحيح: schema.sql وملفات الـ migrations بيقولوا اسم عمود انتهاء
     * الاشتراك هو expiry_date، لكن ده مش مطابق لقاعدة البيانات الفعلية
     * المنشورة (نفس مشكلة is_active/status القديمة في جدول users) — أي
     * استعلام أو حفظ كان بيستخدمه كان بيفشل بالكامل ("Unknown column").
     * بدل ما نخمّن اسم بديل من غير ما نتأكد، بنكتشف الاسم الحقيقي من
     * قاعدة البيانات نفسها (INFORMATION_SCHEMA) مرة واحدة بس ونعمل له
     * cache. المصدر الوحيد لده في كل المشروع — SubscriptionValidator
     * وUser::getActiveSubscription() وSubscriptionController كلهم
     * بيستخدموا الدالة دي بدل ما كل واحد يخمّن لوحده.
     * @return string اسم العمود الحقيقي، أو '' لو مفيش عمود انتهاء أصلاً
     */
    public static function expiryColumn(): string {
        if (self::$expiryColumnCache !== null) {
            return self::$expiryColumnCache;
        }

        $candidates = ['expiry_date', 'expires_at', 'expire_at', 'end_date', 'valid_until', 'subscription_end', 'ends_at', 'current_period_end'];

        try {
            $db = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions'
                    AND COLUMN_NAME IN ({$placeholders})";
            $result = $db->query($sql, $candidates);
            $found = array_map('strtolower', array_column($result, 'COLUMN_NAME'));

            foreach ($candidates as $c) {
                if (in_array(strtolower($c), $found, true)) {
                    self::$expiryColumnCache = $c;
                    return $c;
                }
            }
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::warning('Could not detect subscriptions expiry column', ['error' => $e->getMessage()]);
            }
        }

        self::$expiryColumnCache = '';
        return '';
    }
    
    /**
     * الحصول على خطط الاشتراك المتاحة
     * @return array
     */
    public static function getAvailablePlans(): array {
        return SUBSCRIPTION_PLANS;
    }
    
    /**
     * تصحيح جذري (2026-07-13): بعد ما شفنا بنية الجدول الحقيقية فعليًا في
     * phpMyAdmin، اتضح إن subscriptions مصمم بشكل مختلف تمامًا عن افتراض
     * الكود القديم:
     *   - مفيش plan_name نصي - فيه plan_id (مفتاح خارجي لجدول subscription_plans)
     *   - مفيش expiry_date - فيه current_period_start/current_period_end
     *     (نمط Stripe قياسي)
     *   - مفيش ai_credits/chat_credits/... منفصلين - الحدود في subscription_plans
     *     (ai_analysis_limit, ai_message_limit, review_auto_reply_limit)
     *     والاستهلاك عدّادات في subscriptions (usage_ai_analysis_count...)
     *   - فيه payment_gateway/gateway_subscription_id/gateway_customer_id
     *     (الجدول مبني أصلاً عشان يتكامل مع بوابة دفع حقيقية زي Stripe)
     *
     * الدالة دي هي المصدر الوحيد اللي بيحوّل الصف الحقيقي لنفس الأسماء
     * القديمة اللي SubscriptionValidator و SubscriptionController بيستخدموها،
     * عشان الكود التاني مايحتاجش يتغيّر خالص.
     *
     * ملحوظة: competitor_analysis_used مفيهوش عمود تتبع مخصص في الجدول
     * الحقيقي، فبنرجعها 0 دايمًا مؤقتًا (يعني الحد ده مش بيتطبّق فعليًا
     * لحد ما نضيف عمود مخصص له).
     *
     * @param int $userId
     * @return array|null
     */
    public static function activeSubscriptionRow(int $userId): ?array {
        try {
            $db = Database::getInstance();
            $sql = "SELECT 
                        s.*,
                        SUBSTRING_INDEX(sp.plan_code, '_', 1) AS plan_name,
                        sp.billing_cycle AS plan_type,
                        sp.price AS price,
                        sp.currency AS currency,
                        sp.ai_analysis_limit AS ai_credits,
                        s.usage_ai_analysis_count AS ai_credits_used,
                        sp.ai_message_limit AS chat_credits,
                        s.usage_ai_message_count AS chat_credits_used,
                        sp.review_auto_reply_limit AS review_credits,
                        s.usage_review_reply_count AS review_credits_used,
                        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(sp.features_json, '$.competitor_analysis')), 0) AS competitor_analysis_limit,
                        0 AS competitor_analysis_used,
                        COALESCE(JSON_EXTRACT(sp.features_json, '$.auto_pilot'), 0) AS auto_pilot,
                        s.current_period_start AS start_date,
                        s.current_period_end AS expiry_date
                    FROM subscriptions s
                    JOIN subscription_plans sp ON sp.id = s.plan_id
                    WHERE s.user_id = ? AND s.status = 'active' AND s.current_period_end > NOW()
                    ORDER BY s.id DESC LIMIT 1";

            $result = $db->query($sql, [$userId]);
            return $result[0] ?? null;
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('Subscription::activeSubscriptionRow failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /**
     * إنشاء اشتراك جديد
     * @param int $userId
     * @param string $planName
     * @param string $planType
     * @return Subscription|false
     */
    public static function createSubscription(int $userId, string $planName, string $planType = 'monthly'): ?Subscription {
        try {
            $db = Database::getInstance();
            $planCode = $planName . '_' . $planType;

            $planRows = $db->query(
                "SELECT * FROM subscription_plans WHERE plan_code = ? AND is_active = 1 LIMIT 1",
                [$planCode]
            );

            if (empty($planRows)) {
                Logger::error('Invalid plan code', ['plan_code' => $planCode]);
                return false;
            }

            $plan = $planRows[0];
            $startDate = date('Y-m-d H:i:s');
            $periodEnd = $planType === 'yearly'
                ? date('Y-m-d H:i:s', strtotime('+1 year'))
                : date('Y-m-d H:i:s', strtotime('+1 month'));

            $insertSql = "INSERT INTO subscriptions
                        (user_id, plan_id, status, trial_ends_at, current_period_start, current_period_end,
                         cancel_at_period_end, payment_gateway, gateway_subscription_id, gateway_customer_id,
                         usage_ai_analysis_count, usage_ai_message_count, usage_review_reply_count,
                         last_usage_reset_at, created_at, updated_at)
                    VALUES
                        (?, ?, 'active', NULL, ?, ?,
                         0, NULL, NULL, NULL,
                         0, 0, 0,
                         NOW(), NOW(), NOW())";

            $id = $db->query($insertSql, [$userId, $plan['id'], $startDate, $periodEnd]);

            if (!$id) {
                return false;
            }

            $instance = new static();
            return $instance->find($id);
        } catch (Exception $e) {
            Logger::error('Create Subscription Error', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @deprecated النسخة القديمة من createSubscription كانت بتستخدم أعمدة
     * وهمية (ai_credits, expiry_date...) مش موجودة في الجدول الحقيقي. باقية
     * هنا لغرض توثيقي بس - مش بتتنفذ.
     */
    private static function createSubscriptionLegacyUnused(int $userId, string $planName, string $planType = 'monthly'): ?Subscription {
        $plans = self::getAvailablePlans();
        
        if (!isset($plans[$planName])) {
            Logger::error('Invalid plan', ['plan' => $planName]);
            return false;
        }
        
        $plan = $plans[$planName];
        $features = $plan['features'];
        
        // حساب السعر
        $price = $planType === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
        
        // حساب تاريخ الانتهاء
        $startDate = date('Y-m-d H:i:s');
        $expiryDate = $planType === 'yearly' 
            ? date('Y-m-d H:i:s', strtotime('+1 year'))
            : date('Y-m-d H:i:s', strtotime('+1 month'));
        
        $data = [
            'user_id' => $userId,
            'plan_name' => $planName,
            'plan_type' => $planType,
            'status' => 'active',
            'price' => $price,
            'currency' => DEFAULT_CURRENCY,
            'ai_credits' => $features['ai_analysis'] ?? 0,
            'ai_credits_used' => 0,
            'chat_credits' => $features['chat_credits'] ?? 0,
            'chat_credits_used' => 0,
            'review_credits' => $features['review_credits'] ?? 0,
            'review_credits_used' => 0,
            'competitor_analysis_limit' => $features['competitor_analysis'] ?? 0,
            'competitor_analysis_used' => 0,
            'auto_pilot' => $features['auto_pilot'] ? 1 : 0,
            'start_date' => $startDate,
            'next_billing_at' => $expiryDate
        ];

        $expiryCol = self::expiryColumn();
        if ($expiryCol) {
            $data[$expiryCol] = $expiryDate;
        }
        
        $subscription = new static($data);
        $id = $subscription->save();
        
        if ($id) {
            return $subscription->find($id);
        }
        
        return false;
    }
    
    /**
     * تجديد الاشتراك
     * @return bool
     */
    public function renew(): bool {
        $expiryCol = self::expiryColumn();
        $currentExpiry = $expiryCol ? ($this->attributes[$expiryCol] ?? null) : null;

        if ($this->attributes['plan_type'] === 'yearly') {
            $newExpiry = date('Y-m-d H:i:s', strtotime('+1 year', $currentExpiry ? strtotime($currentExpiry) : time()));
        } else {
            $newExpiry = date('Y-m-d H:i:s', strtotime('+1 month', $currentExpiry ? strtotime($currentExpiry) : time()));
        }
        
        // إعادة تعيين الاستخدام
        $this->attributes['ai_credits_used'] = 0;
        $this->attributes['chat_credits_used'] = 0;
        $this->attributes['review_credits_used'] = 0;
        $this->attributes['competitor_analysis_used'] = 0;
        if ($expiryCol) {
            $this->attributes[$expiryCol] = $newExpiry;
        }
        $this->attributes['next_billing_at'] = $newExpiry;
        $this->attributes['last_billed_at'] = date('Y-m-d H:i:s');
        $this->attributes['status'] = 'active';
        
        return $this->save() !== false;
    }
    
    /**
     * إلغاء الاشتراك
     * @return bool
     */
    public function cancel(): bool {
        $this->attributes['status'] = 'cancelled';
        return $this->save() !== false;
    }
    
    /**
     * التحقق من صلاحية الاشتراك
     * @return bool
     */
    public function isValid(): bool {
        if ($this->attributes['status'] !== 'active') {
            return false;
        }
        
        $expiryCol = self::expiryColumn();
        $expiryDate = $expiryCol ? ($this->attributes[$expiryCol] ?? null) : null;
        if ($expiryDate && strtotime($expiryDate) < time()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * التحقق من وجود رصيد AI
     * @param int $required
     * @return bool
     */
    public function hasAICredits(int $required = 1): bool {
        if (!$this->isValid()) {
            return false;
        }
        
        $remaining = $this->attributes['ai_credits'] - $this->attributes['ai_credits_used'];
        return $remaining >= $required;
    }
    
    /**
     * استهلاك رصيد AI
     * @param int $amount
     * @return bool
     */
    public function consumeAICredits(int $amount = 1): bool {
        if (!$this->hasAICredits($amount)) {
            return false;
        }
        
        $this->attributes['ai_credits_used'] += $amount;
        return $this->save() !== false;
    }
    
    /**
     * الحصول على الرصيد المتبقي للـ AI
     * @return int
     */
    public function getRemainingAICredits(): int {
        return $this->attributes['ai_credits'] - $this->attributes['ai_credits_used'];
    }
    
    /**
     * الحصول على المستخدم
     * @return User|null
     */
    public function getUser(): ?User {
        $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['user_id']]);
        
        if (empty($result)) {
            return null;
        }
        
        return new User($result[0]);
    }
    
    /**
     * تحديث الباقة
     * @param string $newPlan
     * @return bool
     */
    public function upgrade(string $newPlan): bool {
        $plans = self::getAvailablePlans();
        
        if (!isset($plans[$newPlan])) {
            return false;
        }
        
        $plan = $plans[$newPlan];
        $features = $plan['features'];
        
        // تحديث الميزات
        $this->attributes['plan_name'] = $newPlan;
        $this->attributes['ai_credits'] = $features['ai_analysis'] ?? 0;
        $this->attributes['chat_credits'] = $features['chat_credits'] ?? 0;
        $this->attributes['review_credits'] = $features['review_credits'] ?? 0;
        $this->attributes['competitor_analysis_limit'] = $features['competitor_analysis'] ?? 0;
        $this->attributes['auto_pilot'] = $features['auto_pilot'] ? 1 : 0;
        
        // تحديث السعر
        $this->attributes['price'] = $this->attributes['plan_type'] === 'yearly' 
            ? $plan['price_yearly'] 
            : $plan['price_monthly'];
        
        return $this->save() !== false;
    }
    
    /**
     * الحصول على نسبة الاستخدام
     * @return array
     */
    public function getUsagePercentage(): array {
        return [
            'ai' => $this->attributes['ai_credits'] > 0 
                ? round(($this->attributes['ai_credits_used'] / $this->attributes['ai_credits']) * 100, 2)
                : 0,
            'chat' => $this->attributes['chat_credits'] > 0
                ? round(($this->attributes['chat_credits_used'] / $this->attributes['chat_credits']) * 100, 2)
                : 0,
            'review' => $this->attributes['review_credits'] > 0
                ? round(($this->attributes['review_credits_used'] / $this->attributes['review_credits']) * 100, 2)
                : 0,
            'competitor' => $this->attributes['competitor_analysis_limit'] > 0
                ? round(($this->attributes['competitor_analysis_used'] / $this->attributes['competitor_analysis_limit']) * 100, 2)
                : 0
        ];
    }
}