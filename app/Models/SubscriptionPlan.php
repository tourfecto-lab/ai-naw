<?php

/** Plan labels are stored separately; displayed price/limits come from billing rows. */
class SubscriptionPlan extends Model
{
    protected $table = 'plan_pricing_display';

    protected $fillable = [
        'plan_key', 'name', 'price_monthly', 'price_yearly', 'currency', 'currency_symbol',
        'ai_analysis', 'competitor_analysis', 'chat_credits', 'review_credits', 'multiple_websites',
        'whatsapp_bot', 'auto_pilot', 'advanced_analytics', 'sort_order', 'is_active',
    ];

    /**
     * كل الباقات بنفس شكل SUBSCRIPTION_PLANS القديمة (array مفتاحها
     * plan_key) عشان أي كود قديم يستخدمها من غير تعديل. بيرجع الباقات
     * النشطة بس، مرتّبة حسب sort_order.
     */
    public static function allAsLegacyArray(): array
    {
        require_once dirname(__DIR__) . '/Services/Subscription/BillingPlanCatalog.php';
        try { return BillingPlanCatalog::read(); }
        catch (Throwable $e) {
            if (class_exists('Logger')) Logger::error('Billing catalog unavailable', ['message' => $e->getMessage()]);
            return []; // Never advertise a marketing fallback that cannot be purchased.
        }
    }
}
