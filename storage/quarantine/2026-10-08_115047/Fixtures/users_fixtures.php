<?php

/**
 * Tourfecto - Users Fixtures
 * بيانات مستخدمين افتراضية للاختبار
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

return [
    // ============================================
    // 1. المستخدمون الأساسيون
    // ============================================
    'users' => [
        [
            'company_name' => 'Tourfecto Admin',
            'email' => 'admin@tourfecto.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+966500000001',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'super_admin',
            'is_active' => 1,
            'email_verified' => 1,
            'api_token' => 'admin_test_token_' . md5('admin'),
            'created_at' => '2026-01-01 00:00:00'
        ],
        [
            'company_name' => 'Tourfecto System',
            'email' => 'system@tourfecto.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+966500000002',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'admin',
            'is_active' => 1,
            'email_verified' => 1,
            'api_token' => 'system_test_token_' . md5('system'),
            'created_at' => '2026-01-01 00:00:01'
        ],
        [
            'company_name' => 'العربي للسياحة',
            'email' => 'info@arabic-travel.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+966500000003',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'manager',
            'is_active' => 1,
            'email_verified' => 1,
            'api_token' => 'arabic_test_token_' . md5('arabic'),
            'created_at' => '2026-01-01 00:00:02'
        ],
        [
            'company_name' => 'مصر للسياحة',
            'email' => 'info@egypt-travel.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+201000000001',
            'country' => 'EG',
            'language' => 'ar',
            'timezone' => 'Africa/Cairo',
            'role' => 'agent',
            'is_active' => 1,
            'email_verified' => 1,
            'api_token' => 'egypt_test_token_' . md5('egypt'),
            'created_at' => '2026-01-01 00:00:03'
        ],
        [
            'company_name' => 'European Travel Group',
            'email' => 'info@europe-travel.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+442000000001',
            'country' => 'GB',
            'language' => 'en',
            'timezone' => 'Europe/London',
            'role' => 'manager',
            'is_active' => 1,
            'email_verified' => 1,
            'api_token' => 'europe_test_token_' . md5('europe'),
            'created_at' => '2026-01-01 00:00:04'
        ],
        [
            'company_name' => 'شركة غير مفعلة',
            'email' => 'inactive@test.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+966500000004',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'user',
            'is_active' => 0,
            'email_verified' => 0,
            'api_token' => null,
            'created_at' => '2026-01-01 00:00:05'
        ],
        [
            'company_name' => 'شركة غير موثقة',
            'email' => 'unverified@test.com',
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'phone' => '+966500000005',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'user',
            'is_active' => 1,
            'email_verified' => 0,
            'api_token' => null,
            'created_at' => '2026-01-01 00:00:06'
        ]
    ],

    // ============================================
    // 2. مستخدمون إضافيون للاختبارات المتقدمة
    // ============================================
    'test_users' => [
        [
            'company_name' => 'Test User 1',
            'email' => 'test1@example.com',
            'password_hash' => password_hash('Test@123', PASSWORD_ARGON2ID),
            'phone' => '+966500000010',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'user',
            'is_active' => 1,
            'email_verified' => 1
        ],
        [
            'company_name' => 'Test User 2',
            'email' => 'test2@example.com',
            'password_hash' => password_hash('Test@123', PASSWORD_ARGON2ID),
            'phone' => '+966500000011',
            'country' => 'SA',
            'language' => 'en',
            'timezone' => 'Asia/Riyadh',
            'role' => 'agent',
            'is_active' => 1,
            'email_verified' => 1
        ],
        [
            'company_name' => 'Test User 3',
            'email' => 'test3@example.com',
            'password_hash' => password_hash('Test@123', PASSWORD_ARGON2ID),
            'phone' => '+966500000012',
            'country' => 'SA',
            'language' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'role' => 'manager',
            'is_active' => 1,
            'email_verified' => 1
        ]
    ],

    // ============================================
    // 3. مواقع إلكترونية للمستخدمين
    // ============================================
    'websites' => [
        [
            'user_id' => 1, // admin@tourfecto.com
            'main_url' => 'https://tourfecto.com',
            'company_name' => 'Tourfecto Main',
            'industry' => 'tourism',
            'target_language' => 'ar',
            'target_country' => 'SA',
            'meta_description' => 'منصة سياحية ذكية متكاملة',
            'is_verified' => 1
        ],
        [
            'user_id' => 3, // info@arabic-travel.com
            'main_url' => 'https://arabic-travel.com',
            'company_name' => 'العربي للسياحة',
            'industry' => 'tourism',
            'target_language' => 'ar',
            'target_country' => 'SA',
            'meta_description' => 'أفضل عروض السياحة في العالم العربي',
            'competitor_1_url' => 'https://competitor1.com',
            'competitor_2_url' => 'https://competitor2.com',
            'competitor_3_url' => 'https://competitor3.com',
            'is_verified' => 1
        ],
        [
            'user_id' => 4, // info@egypt-travel.com
            'main_url' => 'https://egypt-travel.com',
            'company_name' => 'مصر للسياحة',
            'industry' => 'tourism',
            'target_language' => 'ar',
            'target_country' => 'EG',
            'meta_description' => 'اكتشف جمال مصر مع أفضل العروض',
            'is_verified' => 1
        ],
        [
            'user_id' => 5, // info@europe-travel.com
            'main_url' => 'https://europe-travel.com',
            'company_name' => 'European Travel Group',
            'industry' => 'tourism',
            'target_language' => 'en',
            'target_country' => 'GB',
            'meta_description' => 'Discover Europe with the best travel deals',
            'is_verified' => 1
        ]
    ],

    // ============================================
    // 4. مواقع إضافية للاختبار
    // ============================================
    'test_websites' => [
        [
            'main_url' => 'https://test-site1.com',
            'company_name' => 'Test Site 1',
            'industry' => 'tourism',
            'target_language' => 'ar',
            'target_country' => 'SA'
        ],
        [
            'main_url' => 'https://test-site2.com',
            'company_name' => 'Test Site 2',
            'industry' => 'tourism',
            'target_language' => 'en',
            'target_country' => 'GB'
        ],
        [
            'main_url' => 'https://test-site3.com',
            'company_name' => 'Test Site 3',
            'industry' => 'tourism',
            'target_language' => 'ar',
            'target_country' => 'EG'
        ]
    ]
];
