<?php

/**
 * Tourfecto - Plan Features
 * تعريف ميزات الباقات والصلاحيات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class PlanFeature
{
    /**
     * @var array $plans - خطط الاشتراك
     */
    private $plans = [];

    /**
     * @var array $features - قائمة الميزات المتاحة
     */
    private $features = [];

    /**
     * @var array $featureCategories - تصنيفات الميزات
     */
    private $featureCategories = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadPlans();
        $this->loadFeatures();
        $this->loadCategories();
    }

    /**
     * الحصول على جميع الباقات
     * @return array
     */
    public function getAllPlans(): array
    {
        return $this->plans;
    }

    /**
     * الحصول على باقة معينة
     * @param string $planName
     * @return array|null
     */
    public function getPlan(string $planName): ?array
    {
        return $this->plans[$planName] ?? null;
    }

    /**
     * الحصول على ميزات باقة معينة
     * @param string $planName
     * @return array
     */
    public function getPlanFeatures(string $planName): array
    {
        $plan = $this->getPlan($planName);
        return $plan ? $plan['features'] : [];
    }

    /**
     * التحقق من وجود ميزة في باقة
     * @param string $planName
     * @param string $feature
     * @return bool
     */
    public function hasFeature(string $planName, string $feature): bool
    {
        $features = $this->getPlanFeatures($planName);
        return isset($features[$feature]) && $features[$feature];
    }

    /**
     * الحصول على قيمة ميزة
     * @param string $planName
     * @param string $feature
     * @return mixed
     */
    public function getFeatureValue(string $planName, string $feature)
    {
        $features = $this->getPlanFeatures($planName);
        return $features[$feature] ?? null;
    }

    /**
     * مقارنة بين باقات
     * @param array $planNames
     * @return array
     */
    public function comparePlans(array $planNames): array
    {
        $comparison = [];

        foreach ($this->features as $featureKey => $feature) {
            $comparison[$featureKey] = [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'category' => $feature['category'],
                'plans' => []
            ];

            foreach ($planNames as $planName) {
                $plan = $this->getPlan($planName);
                if ($plan) {
                    $value = $plan['features'][$featureKey] ?? false;
                    $comparison[$featureKey]['plans'][$planName] = $value;
                }
            }
        }

        return $comparison;
    }

    /**
     * الحصول على ميزات حسب التصنيف
     * @param string $category
     * @return array
     */
    public function getFeaturesByCategory(string $category): array
    {
        $result = [];

        foreach ($this->features as $key => $feature) {
            if ($feature['category'] === $category) {
                $result[$key] = $feature;
            }
        }

        return $result;
    }

    /**
     * الحصول على جميع التصنيفات
     * @return array
     */
    public function getCategories(): array
    {
        return $this->featureCategories;
    }

    /**
     * تحميل خطط الاشتراك
     */
    private function loadPlans(): void
    {
        $this->plans = SubscriptionPlan::allAsLegacyArray();
    }

    /**
     * تحميل قائمة الميزات
     */
    private function loadFeatures(): void
    {
        $this->features = [
            'ai_analysis' => [
                'name' => 'تحليل الذكاء الاصطناعي',
                'description' => 'عدد تحليلات الذكاء الاصطناعي المتاحة شهرياً',
                'category' => 'ai',
                'type' => 'number'
            ],
            'competitor_analysis' => [
                'name' => 'تحليل المنافسين',
                'description' => 'عدد تحليلات المنافسين المتاحة شهرياً',
                'category' => 'ai',
                'type' => 'number'
            ],
            'chat_credits' => [
                'name' => 'رصيد المحادثات',
                'description' => 'عدد رسائل الشات المتاحة شهرياً',
                'category' => 'chat',
                'type' => 'number'
            ],
            'review_credits' => [
                'name' => 'رصيد المراجعات',
                'description' => 'عدد المراجعات المتاحة شهرياً',
                'category' => 'reputation',
                'type' => 'number'
            ],
            'whatsapp_bot' => [
                'name' => 'بوت واتساب',
                'description' => 'تفعيل بوت واتساب الذكي',
                'category' => 'chat',
                'type' => 'boolean'
            ],
            'auto_pilot' => [
                'name' => 'الطيار الآلي',
                'description' => 'تفعيل الردود التلقائية بدون موافقة',
                'category' => 'chat',
                'type' => 'boolean'
            ],
            'multiple_websites' => [
                'name' => 'مواقع متعددة',
                'description' => 'عدد المواقع التي يمكن إضافتها',
                'category' => 'general',
                'type' => 'number'
            ],
            'advanced_analytics' => [
                'name' => 'تحليلات متقدمة',
                'description' => 'تفعيل لوحات التحكم المتقدمة والتقارير المفصلة',
                'category' => 'analytics',
                'type' => 'boolean'
            ],
            'api_access' => [
                'name' => 'الوصول إلى API',
                'description' => 'تفعيل الوصول إلى واجهة API',
                'category' => 'general',
                'type' => 'boolean'
            ],
            'priority_support' => [
                'name' => 'دعم أولوية',
                'description' => 'الحصول على دعم ذو أولوية عالية',
                'category' => 'support',
                'type' => 'boolean'
            ],
            'white_label' => [
                'name' => 'العلامة البيضاء',
                'description' => 'إزالة علامة Tourfecto من الواجهة',
                'category' => 'general',
                'type' => 'boolean'
            ],
            'team_members' => [
                'name' => 'أعضاء الفريق',
                'description' => 'عدد أعضاء الفريق المسموح بهم',
                'category' => 'general',
                'type' => 'number'
            ]
        ];
    }

    /**
     * تحميل تصنيفات الميزات
     */
    private function loadCategories(): void
    {
        $this->featureCategories = [
            'ai' => [
                'name' => 'الذكاء الاصطناعي',
                'icon' => 'brain',
                'description' => 'ميزات الذكاء الاصطناعي والتحليلات'
            ],
            'chat' => [
                'name' => 'المحادثات',
                'icon' => 'message',
                'description' => 'ميزات الشات والتواصل'
            ],
            'reputation' => [
                'name' => 'السمعة',
                'icon' => 'star',
                'description' => 'ميزات إدارة السمعة والمراجعات'
            ],
            'analytics' => [
                'name' => 'التحليلات',
                'icon' => 'chart',
                'description' => 'ميزات التحليلات والتقارير'
            ],
            'general' => [
                'name' => 'عام',
                'icon' => 'settings',
                'description' => 'ميزات عامة وإدارية'
            ],
            'support' => [
                'name' => 'الدعم',
                'icon' => 'support',
                'description' => 'ميزات الدعم والمساعدة'
            ]
        ];
    }

    /**
     * الحصول على سعر الباقة
     * @param string $planName
     * @param string $type
     * @return float|null
     */
    public function getPrice(string $planName, string $type = 'monthly'): ?float
    {
        $plan = $this->getPlan($planName);

        if (!$plan) {
            return null;
        }

        return $type === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
    }

    /**
     * الحصول على السعر المنسق
     * @param string $planName
     * @param string $type
     * @param string $currency
     * @return string
     */
    public function getFormattedPrice(string $planName, string $type = 'monthly', string $currency = 'USD'): string
    {
        $price = $this->getPrice($planName, $type);

        if ($price === null) {
            return 'N/A';
        }

        $symbols = CURRENCY_SYMBOLS;
        $symbol = $symbols[$currency] ?? '$';

        return $symbol . number_format($price, 2);
    }

    /**
     * الحصول على توصية الباقة المناسبة
     * @param array $requirements
     * @return string
     */
    public function recommendPlan(array $requirements): string
    {
        $score = [
            'starter' => 0,
            'professional' => 0,
            'enterprise' => 0
        ];

        foreach ($this->plans as $planName => $plan) {
            $features = $plan['features'];

            foreach ($requirements as $feature => $required) {
                if (isset($features[$feature])) {
                    if ($features[$feature] >= $required) {
                        $score[$planName] += 10;
                    } elseif ($features[$feature] > 0 && $required > 0) {
                        $score[$planName] += 5;
                    }
                }
            }
        }

        $bestPlan = array_search(max($score), $score);

        return $bestPlan ?: 'starter';
    }
}
