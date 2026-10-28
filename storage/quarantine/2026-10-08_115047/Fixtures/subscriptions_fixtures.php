<?php

/**
 * Tourfecto - Subscriptions Fixtures
 * بيانات اشتراكات افتراضية للاختبار
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

return [
    // ============================================
    // 1. اشتراكات المستخدمين الأساسيين
    // ============================================
    'subscriptions' => [
        [
            'user_id' => 1, // admin@tourfecto.com
            'plan_name' => 'enterprise',
            'plan_type' => 'yearly',
            'status' => 'active',
            'price' => 2990.00,
            'currency' => 'USD',
            'ai_credits' => 1000,
            'ai_credits_used' => 50,
            'chat_credits' => 2000,
            'chat_credits_used' => 100,
            'review_credits' => 200,
            'review_credits_used' => 10,
            'competitor_analysis_limit' => 100,
            'competitor_analysis_used' => 5,
            'auto_pilot' => 1,
            'start_date' => '2026-01-01 00:00:00',
            'expiry_date' => '2027-01-01 00:00:00',
            'last_billed_at' => '2026-01-01 00:00:00',
            'next_billing_at' => '2027-01-01 00:00:00',
            'payment_method' => 'credit_card',
            'payment_gateway' => 'stripe'
        ],
        [
            'user_id' => 2, // system@tourfecto.com
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'status' => 'active',
            'price' => 99.00,
            'currency' => 'USD',
            'ai_credits' => 200,
            'ai_credits_used' => 30,
            'chat_credits' => 500,
            'chat_credits_used' => 50,
            'review_credits' => 50,
            'review_credits_used' => 5,
            'competitor_analysis_limit' => 20,
            'competitor_analysis_used' => 3,
            'auto_pilot' => 1,
            'start_date' => '2026-01-01 00:00:00',
            'expiry_date' => '2026-02-01 00:00:00',
            'last_billed_at' => '2026-01-01 00:00:00',
            'next_billing_at' => '2026-02-01 00:00:00',
            'payment_method' => 'credit_card',
            'payment_gateway' => 'stripe'
        ],
        [
            'user_id' => 3, // info@arabic-travel.com
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'status' => 'active',
            'price' => 99.00,
            'currency' => 'USD',
            'ai_credits' => 200,
            'ai_credits_used' => 15,
            'chat_credits' => 500,
            'chat_credits_used' => 20,
            'review_credits' => 50,
            'review_credits_used' => 2,
            'competitor_analysis_limit' => 20,
            'competitor_analysis_used' => 8,
            'auto_pilot' => 1,
            'start_date' => '2026-01-01 00:00:00',
            'expiry_date' => '2026-02-01 00:00:00',
            'last_billed_at' => '2026-01-01 00:00:00',
            'next_billing_at' => '2026-02-01 00:00:00',
            'payment_method' => 'credit_card',
            'payment_gateway' => 'paypal'
        ],
        [
            'user_id' => 4, // info@egypt-travel.com
            'plan_name' => 'starter',
            'plan_type' => 'monthly',
            'status' => 'active',
            'price' => 49.00,
            'currency' => 'USD',
            'ai_credits' => 50,
            'ai_credits_used' => 10,
            'chat_credits' => 100,
            'chat_credits_used' => 5,
            'review_credits' => 10,
            'review_credits_used' => 1,
            'competitor_analysis_limit' => 5,
            'competitor_analysis_used' => 2,
            'auto_pilot' => 0,
            'start_date' => '2026-01-01 00:00:00',
            'expiry_date' => '2026-02-01 00:00:00',
            'last_billed_at' => '2026-01-01 00:00:00',
            'next_billing_at' => '2026-02-01 00:00:00',
            'payment_method' => 'credit_card',
            'payment_gateway' => 'stripe'
        ],
        [
            'user_id' => 5, // info@europe-travel.com
            'plan_name' => 'enterprise',
            'plan_type' => 'yearly',
            'status' => 'active',
            'price' => 2990.00,
            'currency' => 'USD',
            'ai_credits' => 1000,
            'ai_credits_used' => 200,
            'chat_credits' => 2000,
            'chat_credits_used' => 300,
            'review_credits' => 200,
            'review_credits_used' => 25,
            'competitor_analysis_limit' => 100,
            'competitor_analysis_used' => 15,
            'auto_pilot' => 1,
            'start_date' => '2026-01-01 00:00:00',
            'expiry_date' => '2027-01-01 00:00:00',
            'last_billed_at' => '2026-01-01 00:00:00',
            'next_billing_at' => '2027-01-01 00:00:00',
            'payment_method' => 'bank_transfer',
            'payment_gateway' => 'bank'
        ]
    ],

    // ============================================
    // 2. اشتراكات منتهية للاختبار
    // ============================================
    'expired_subscriptions' => [
        [
            'user_id' => 6, // inactive@test.com
            'plan_name' => 'starter',
            'plan_type' => 'monthly',
            'status' => 'expired',
            'price' => 49.00,
            'currency' => 'USD',
            'ai_credits' => 50,
            'ai_credits_used' => 50,
            'chat_credits' => 100,
            'chat_credits_used' => 100,
            'review_credits' => 10,
            'review_credits_used' => 10,
            'competitor_analysis_limit' => 5,
            'competitor_analysis_used' => 5,
            'auto_pilot' => 0,
            'start_date' => '2025-11-01 00:00:00',
            'expiry_date' => '2025-12-01 00:00:00',
            'created_at' => '2025-11-01 00:00:00'
        ],
        [
            'user_id' => 6, // inactive@test.com
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'status' => 'cancelled',
            'price' => 99.00,
            'currency' => 'USD',
            'ai_credits' => 200,
            'ai_credits_used' => 100,
            'chat_credits' => 500,
            'chat_credits_used' => 200,
            'review_credits' => 50,
            'review_credits_used' => 20,
            'competitor_analysis_limit' => 20,
            'competitor_analysis_used' => 10,
            'auto_pilot' => 0,
            'start_date' => '2025-10-01 00:00:00',
            'expiry_date' => '2025-11-01 00:00:00',
            'cancelled_at' => '2025-10-15 00:00:00',
            'cancellation_reason' => 'غير راضٍ عن الخدمة',
            'created_at' => '2025-10-01 00:00:00'
        ]
    ],

    // ============================================
    // 3. خطط الاشتراك المتاحة
    // ============================================
    'plans' => [
        'starter' => [
            'id' => 'starter',
            'name' => 'الباقة الأساسية',
            'price_monthly' => 49.00,
            'price_yearly' => 490.00,
            'features' => [
                'ai_analysis' => 50,
                'competitor_analysis' => 5,
                'chat_credits' => 100,
                'review_credits' => 10,
                'whatsapp_bot' => true,
                'auto_pilot' => false,
                'multiple_websites' => 1,
                'advanced_analytics' => false
            ]
        ],
        'professional' => [
            'id' => 'professional',
            'name' => 'الباقة الاحترافية',
            'price_monthly' => 99.00,
            'price_yearly' => 990.00,
            'features' => [
                'ai_analysis' => 200,
                'competitor_analysis' => 20,
                'chat_credits' => 500,
                'review_credits' => 50,
                'whatsapp_bot' => true,
                'auto_pilot' => true,
                'multiple_websites' => 3,
                'advanced_analytics' => false
            ]
        ],
        'enterprise' => [
            'id' => 'enterprise',
            'name' => 'الباقة المؤسسية',
            'price_monthly' => 299.00,
            'price_yearly' => 2990.00,
            'features' => [
                'ai_analysis' => 1000,
                'competitor_analysis' => 100,
                'chat_credits' => 2000,
                'review_credits' => 200,
                'whatsapp_bot' => true,
                'auto_pilot' => true,
                'multiple_websites' => 10,
                'advanced_analytics' => true
            ]
        ]
    ],

    // ============================================
    // 4. فواتير للاختبار
    // ============================================
    'invoices' => [
        [
            'user_id' => 1,
            'invoice_number' => 'INV-20260101-001',
            'plan_name' => 'enterprise',
            'plan_type' => 'yearly',
            'amount' => 2990.00,
            'currency' => 'USD',
            'status' => 'paid',
            'payment_method' => 'credit_card',
            'transaction_id' => 'txn_stripe_001',
            'items' => '[{"description":"Enterprise Plan - Yearly","amount":2990,"quantity":1}]',
            'due_date' => '2026-01-08',
            'paid_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'user_id' => 2,
            'invoice_number' => 'INV-20260101-002',
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'amount' => 99.00,
            'currency' => 'USD',
            'status' => 'paid',
            'payment_method' => 'credit_card',
            'transaction_id' => 'txn_stripe_002',
            'items' => '[{"description":"Professional Plan - Monthly","amount":99,"quantity":1}]',
            'due_date' => '2026-01-08',
            'paid_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'user_id' => 3,
            'invoice_number' => 'INV-20260101-003',
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'amount' => 99.00,
            'currency' => 'USD',
            'status' => 'paid',
            'payment_method' => 'credit_card',
            'transaction_id' => 'txn_paypal_001',
            'items' => '[{"description":"Professional Plan - Monthly","amount":99,"quantity":1}]',
            'due_date' => '2026-01-08',
            'paid_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'user_id' => 4,
            'invoice_number' => 'INV-20260101-004',
            'plan_name' => 'starter',
            'plan_type' => 'monthly',
            'amount' => 49.00,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => null,
            'transaction_id' => null,
            'items' => '[{"description":"Starter Plan - Monthly","amount":49,"quantity":1}]',
            'due_date' => '2026-01-08',
            'paid_at' => null,
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'user_id' => 5,
            'invoice_number' => 'INV-20260101-005',
            'plan_name' => 'enterprise',
            'plan_type' => 'yearly',
            'amount' => 2990.00,
            'currency' => 'USD',
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
            'transaction_id' => 'txn_bank_001',
            'items' => '[{"description":"Enterprise Plan - Yearly","amount":2990,"quantity":1}]',
            'due_date' => '2026-01-08',
            'paid_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00'
        ]
    ],

    // ============================================
    // 5. معاملات دفع للاختبار
    // ============================================
    'transactions' => [
        [
            'invoice_id' => 1,
            'transaction_id' => 'txn_stripe_001',
            'data' => '{"status":"success","amount":2990,"currency":"USD"}',
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'invoice_id' => 2,
            'transaction_id' => 'txn_stripe_002',
            'data' => '{"status":"success","amount":99,"currency":"USD"}',
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'invoice_id' => 3,
            'transaction_id' => 'txn_paypal_001',
            'data' => '{"status":"success","amount":99,"currency":"USD"}',
            'created_at' => '2026-01-01 00:00:00'
        ]
    ]
];
