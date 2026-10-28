<?php

/**
 * Tourfecto - Reports Fixtures
 * بيانات تقارير افتراضية للاختبار
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

return [
    // ============================================
    // 1. تقارير SEO
    // ============================================
    'seo_reports' => [
        [
            'website_id' => 1,
            'user_id' => 1,
            'report_type' => 'seo',
            'target_url' => 'https://tourfecto.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'seo_keywords' => '[
                {"keyword":"سياحة السعودية","volume":1200,"difficulty":65},
                {"keyword":"حجز فنادق","volume":850,"difficulty":55},
                {"keyword":"عروض سياحية","volume":950,"difficulty":60}
            ]',
            'seo_title_suggestions' => '[
                "أفضل عروض السياحة في السعودية 2026",
                "دليلك الشامل لحجز الفنادق بأسعار مخفضة",
                "رحلات عائلية لا تنسى في المملكة"
            ]',
            'seo_meta_suggestions' => '[
                "اكتشف أفضل عروض السياحة في السعودية مع Tourfecto. حجز فنادق، تذاكر طيران، رحلات سياحية بأسعار مميزة.",
                "خطط لرحلتك القادمة مع Tourfecto. عروض حصرية على الفنادق والطيران والرحلات السياحية."
            ]',
            'seo_content_gaps' => '[
                "عدم وجود محتوى عن السياحة الشتوية",
                "نقص في المعلومات عن الفعاليات الثقافية",
                "عدم تغطية كافية للمناطق السياحية الجديدة"
            ]',
            'full_report_json' => '{
                "seo": {
                    "keywords": [
                        {"keyword":"سياحة السعودية","volume":1200,"difficulty":65},
                        {"keyword":"حجز فنادق","volume":850,"difficulty":55}
                    ],
                    "title_suggestions": [
                        "أفضل عروض السياحة في السعودية 2026",
                        "دليلك الشامل لحجز الفنادق"
                    ],
                    "meta_suggestions": [
                        "اكتشف أفضل عروض السياحة في السعودية"
                    ],
                    "content_gaps": [
                        "عدم وجود محتوى عن السياحة الشتوية"
                    ]
                },
                "score": 85
            }',
            'analysis_score' => 85,
            'keywords_found' => 10,
            'competitors_analyzed' => 3,
            'is_cached' => 1,
            'cached_until' => '2026-01-16 00:00:00',
            'status' => 'completed',
            'tokens_used' => 1500,
            'cost_in_usd' => 0.0001875,
            'created_at' => '2026-01-09 00:00:00'
        ],
        [
            'website_id' => 2,
            'user_id' => 3,
            'report_type' => 'seo',
            'target_url' => 'https://arabic-travel.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'seo_keywords' => '[
                {"keyword":"سياحة الرياض","volume":800,"difficulty":55},
                {"keyword":"حجز فنادق الرياض","volume":600,"difficulty":45},
                {"keyword":"رحلات سياحية","volume":700,"difficulty":50}
            ]',
            'seo_title_suggestions' => '[
                "أفضل الفنادق في الرياض 2026",
                "دليل السياحة في الرياض",
                "رحلات سياحية في الرياض"
            ]',
            'full_report_json' => '{
                "seo": {
                    "keywords": [
                        {"keyword":"سياحة الرياض","volume":800,"difficulty":55}
                    ],
                    "score": 75
                }
            }',
            'analysis_score' => 75,
            'keywords_found' => 8,
            'competitors_analyzed' => 3,
            'is_cached' => 1,
            'cached_until' => '2026-01-16 00:00:00',
            'status' => 'completed',
            'tokens_used' => 1200,
            'cost_in_usd' => 0.00015,
            'created_at' => '2026-01-09 00:00:00'
        ]
    ],

    // ============================================
    // 2. تقارير AEO
    // ============================================
    'aeo_reports' => [
        [
            'website_id' => 1,
            'user_id' => 1,
            'report_type' => 'aeo',
            'target_url' => 'https://tourfecto.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'aeo_direct_answers' => '[
                {
                    "question": "ما هي أفضل وجهة سياحية في السعودية؟",
                    "answer": "تعتبر الرياض وجدة والعلا من أفضل الوجهات السياحية في السعودية."
                },
                {
                    "question": "كيف أحجز رحلة سياحية؟",
                    "answer": "يمكنك حجز رحلة سياحية عبر موقع Tourfecto بسهولة."
                }
            ]',
            'aeo_trust_signals' => '[
                "شهادات من عملاء سابقين",
                "تقييمات عالية على TripAdvisor",
                "شراكات مع فنادق معروفة"
            ]',
            'aeo_positioning_strategy' => 'التركيز على تقديم محتوى مفيد وشامل عن السياحة في المملكة، مع إبراز التجارب الحقيقية للعملاء.',
            'full_report_json' => '{
                "aeo": {
                    "direct_answers": [
                        {"question":"ما هي أفضل وجهة سياحية في السعودية؟","answer":"تعتبر الرياض وجدة والعلا من أفضل الوجهات."}
                    ],
                    "trust_signals": ["شهادات من عملاء سابقين", "تقييمات عالية"],
                    "positioning_strategy": "التركيز على تقديم محتوى مفيد وشامل"
                },
                "score": 80
            }',
            'analysis_score' => 80,
            'keywords_found' => 5,
            'competitors_analyzed' => 3,
            'is_cached' => 1,
            'cached_until' => '2026-01-16 00:00:00',
            'status' => 'completed',
            'tokens_used' => 1000,
            'cost_in_usd' => 0.000125,
            'created_at' => '2026-01-09 00:00:00'
        ]
    ],

    // ============================================
    // 3. تقارير GEO
    // ============================================
    'geo_reports' => [
        [
            'website_id' => 1,
            'user_id' => 1,
            'report_type' => 'geo',
            'target_url' => 'https://tourfecto.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'geo_faq_schema' => '[
                {
                    "question": "ما هي أفضل الوجهات السياحية في السعودية؟",
                    "answer": "تضم السعودية العديد من الوجهات السياحية الرائعة مثل الرياض، جدة، مكة المكرمة، المدينة المنورة، والعلا."
                },
                {
                    "question": "كيف يمكنني حجز رحلة سياحية عبر Tourfecto؟",
                    "answer": "يمكنك حجز رحلة سياحية بسهولة عبر موقعنا الإلكتروني أو تطبيق الجوال."
                }
            ]',
            'geo_questions_generated' => '[
                "أفضل وقت لزيارة الرياض؟",
                "كيف أصل إلى جدة من المطار؟",
                "ما هي أفضل فنادق مكة المكرمة؟"
            ]',
            'geo_map_integration' => '[
                {"name":"الرياض","lat":24.7136,"lng":46.6753},
                {"name":"جدة","lat":21.4858,"lng":39.1925},
                {"name":"مكة المكرمة","lat":21.3891,"lng":39.8579}
            ]',
            'geo_improvement_suggestions' => 'إضافة خرائط تفاعلية للوجهات السياحية وتضمين مراجعات وآراء المسافرين السابقين.',
            'full_report_json' => '{
                "geo": {
                    "faq_schema": [
                        {"question":"ما هي أفضل الوجهات السياحية في السعودية؟","answer":"تضم السعودية العديد من الوجهات السياحية"}
                    ],
                    "questions_generated": ["أفضل وقت لزيارة الرياض؟"],
                    "map_integration": [
                        {"name":"الرياض","lat":24.7136,"lng":46.6753}
                    ],
                    "improvement_suggestions": "إضافة خرائط تفاعلية"
                },
                "score": 82
            }',
            'analysis_score' => 82,
            'keywords_found' => 6,
            'competitors_analyzed' => 3,
            'is_cached' => 1,
            'cached_until' => '2026-01-16 00:00:00',
            'status' => 'completed',
            'tokens_used' => 1100,
            'cost_in_usd' => 0.0001375,
            'created_at' => '2026-01-09 00:00:00'
        ]
    ],

    // ============================================
    // 4. تقارير كاملة (Full Reports)
    // ============================================
    'full_reports' => [
        [
            'website_id' => 1,
            'user_id' => 1,
            'report_type' => 'full',
            'target_url' => 'https://tourfecto.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'seo_keywords' => '[
                {"keyword":"سياحة السعودية","volume":1200,"difficulty":65},
                {"keyword":"حجز فنادق","volume":850,"difficulty":55}
            ]',
            'aeo_direct_answers' => '[
                {"question":"ما هي أفضل وجهة سياحية في السعودية؟","answer":"تعتبر الرياض وجدة والعلا من أفضل الوجهات."}
            ]',
            'geo_faq_schema' => '[
                {"question":"ما هي أفضل الوجهات السياحية في السعودية؟","answer":"تضم السعودية العديد من الوجهات السياحية الرائعة."}
            ]',
            'full_report_json' => '{
                "seo": {
                    "keywords": [
                        {"keyword":"سياحة السعودية","volume":1200,"difficulty":65}
                    ],
                    "score": 85
                },
                "aeo": {
                    "direct_answers": [
                        {"question":"ما هي أفضل وجهة سياحية في السعودية؟","answer":"تعتبر الرياض وجدة والعلا من أفضل الوجهات."}
                    ],
                    "score": 80
                },
                "geo": {
                    "faq_schema": [
                        {"question":"ما هي أفضل الوجهات السياحية في السعودية؟","answer":"تضم السعودية العديد من الوجهات السياحية الرائعة."}
                    ],
                    "score": 82
                },
                "overall_score": 82.3
            }',
            'analysis_score' => 82,
            'keywords_found' => 15,
            'competitors_analyzed' => 3,
            'is_cached' => 1,
            'cached_until' => '2026-01-16 00:00:00',
            'status' => 'completed',
            'tokens_used' => 2500,
            'cost_in_usd' => 0.0003125,
            'created_at' => '2026-01-09 00:00:00'
        ],
        [
            'website_id' => 3,
            'user_id' => 4,
            'report_type' => 'full',
            'target_url' => 'https://egypt-travel.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'seo_keywords' => '[
                {"keyword":"سياحة مصر","volume":1500,"difficulty":70},
                {"keyword":"حجز فنادق القاهرة","volume":900,"difficulty":60}
            ]',
            'aeo_direct_answers' => '[
                {"question":"ما هي أفضل وجهة سياحية في مصر؟","answer":"تعتبر القاهرة والأقصر وأسوان من أفضل الوجهات السياحية في مصر."}
            ]',
            'geo_faq_schema' => '[
                {"question":"ما هي أفضل الوجهات السياحية في مصر؟","answer":"تضم مصر العديد من الوجهات السياحية الرائعة مثل الأهرامات."}
            ]',
            'full_report_json' => '{
                "seo": {
                    "keywords": [
                        {"keyword":"سياحة مصر","volume":1500,"difficulty":70}
                    ],
                    "score": 78
                },
                "aeo": {
                    "direct_answers": [
                        {"question":"ما هي أفضل وجهة سياحية في مصر؟","answer":"تعتبر القاهرة والأقصر وأسوان من أفضل الوجهات."}
                    ],
                    "score": 75
                },
                "geo": {
                    "faq_schema": [
                        {"question":"ما هي أفضل الوجهات السياحية في مصر؟","answer":"تضم مصر العديد من الوجهات السياحية الرائعة."}
                    ],
                    "score": 80
                },
                "overall_score": 77.7
            }',
            'analysis_score' => 78,
            'keywords_found' => 12,
            'competitors_analyzed' => 3,
            'is_cached' => 1,
            'cached_until' => '2026-01-16 00:00:00',
            'status' => 'completed',
            'tokens_used' => 2200,
            'cost_in_usd' => 0.000275,
            'created_at' => '2026-01-09 00:00:00'
        ]
    ],

    // ============================================
    // 5. تقارير قيد المعالجة
    // ============================================
    'processing_reports' => [
        [
            'website_id' => 1,
            'user_id' => 1,
            'report_type' => 'full',
            'target_url' => 'https://tourfecto.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'full_report_json' => '{}',
            'analysis_score' => 0,
            'keywords_found' => 0,
            'competitors_analyzed' => 0,
            'is_cached' => 0,
            'cached_until' => null,
            'status' => 'processing',
            'tokens_used' => 0,
            'cost_in_usd' => 0,
            'created_at' => '2026-01-09 00:00:00'
        ]
    ],

    // ============================================
    // 6. تقارير فاشلة
    // ============================================
    'failed_reports' => [
        [
            'website_id' => 1,
            'user_id' => 1,
            'report_type' => 'full',
            'target_url' => 'https://tourfecto.com',
            'competitor_urls' => '["https://competitor1.com","https://competitor2.com","https://competitor3.com"]',
            'target_language' => 'ar',
            'full_report_json' => '{}',
            'analysis_score' => 0,
            'keywords_found' => 0,
            'competitors_analyzed' => 0,
            'is_cached' => 0,
            'cached_until' => null,
            'status' => 'failed',
            'error_message' => 'فشل الاتصال بخدمة الذكاء الاصطناعي',
            'tokens_used' => 0,
            'cost_in_usd' => 0,
            'created_at' => '2026-01-09 00:00:00'
        ]
    ]
];
