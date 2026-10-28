<?php
/**
 * Tourfecto - جدول الكرونات (المكان الوحيد اللي بتتحدد فيه المواعيد)
 *
 * cron/scheduler.php بيقرا الملف ده كل دقيقة ويشغّل اللي جه ميعاده.
 * يعني على السيرفر محتاج سطر Cron واحد بس (aaPanel ← Cron، كل 1 دقيقة):
 *   sudo -u www /www/server/php/83/bin/php /www/wwwroot/tourfecto.pro/cron/scheduler.php >/dev/null 2>&1
 *
 * كل سطر هنا:
 *   'file'     => اسم السكربت جوه فولدر cron
 *   'when'     => الميعاد بصيغة Cron العادية (دقيقة ساعة يوم شهر يوم-الأسبوع)
 *                 بتوقيت CRON_TIMEZONE في .env (الافتراضي Africa/Cairo)
 *   'group'    => المجموعة (للعرض بس)
 *   'title'    => وصف قصير بالعربي
 *   'enabled'  => شغّال افتراضيًا ولا لأ (تقدر تغيّره من غير ما تلمس الملف:
 *                 php cron/scheduler.php --disable=KEY  أو  --enable=KEY)
 *   'max_minutes' => لو السكربت فضل شغّال أكتر من كده يتقفل (عشان مايعلّقش)
 */

return [
    // ===== الأساس: لازم يشتغلوا =====
    'queue' => [
        'file' => 'process_queue.php', 'when' => '* * * * *', 'group' => 'core',
        'title' => 'طابور المهام: بيشغّل كل الشغل اللي في الخلفية', 'enabled' => true, 'max_minutes' => 10,
    ],
    'email_automations' => [
        'file' => 'process_email_automations.php', 'when' => '*/5 * * * *', 'group' => 'core',
        'title' => 'الإيميلات التلقائية', 'enabled' => true, 'max_minutes' => 10,
    ],
    'automations' => [
        'file' => 'run_automations.php', 'when' => '*/10 * * * *', 'group' => 'core',
        'title' => 'مركز الأتمتة: الرد على التقييمات وتنفيذ الموافقات', 'enabled' => true, 'max_minutes' => 20,
    ],
    'billing' => [
        'file' => 'run_billing_lifecycle.php', 'when' => '0 2 * * *', 'group' => 'core',
        'title' => 'الاشتراكات: التجديد والانتهاء', 'enabled' => true, 'max_minutes' => 30,
    ],

    // ===== التقييمات والسمعة =====
    'google_reviews' => [
        'file' => 'sync_google_reviews.php', 'when' => '*/15 * * * *', 'group' => 'reviews',
        'title' => 'سحب التقييمات الجديدة من Google', 'enabled' => true, 'max_minutes' => 14,
    ],
    'reply_rules' => [
        'file' => 'apply_reply_rules.php', 'when' => '*/15 * * * *', 'group' => 'reviews',
        'title' => 'قواعد الرد اللي العميل عاملها بنفسه', 'enabled' => true, 'max_minutes' => 15,
    ],
    'review_requests' => [
        'file' => 'process_review_requests.php', 'when' => '*/15 * * * *', 'group' => 'reviews',
        'title' => 'رسايل طلب التقييم من العملاء', 'enabled' => true, 'max_minutes' => 15,
    ],
    'tripadvisor' => [
        'file' => 'sync_tripadvisor_reviews.php', 'when' => '30 */6 * * *', 'group' => 'reviews',
        'title' => 'سحب تقييمات TripAdvisor (محتاج مفتاح TripAdvisor)', 'enabled' => false, 'max_minutes' => 30,
    ],

    // ===== الموقع والـ SEO =====
    'auto_seo' => [
        'file' => 'auto_seo_scheduler.php', 'when' => '0 * * * *', 'group' => 'seo',
        'title' => 'فحص المواقع المربوطة وتصليحها', 'enabled' => true, 'max_minutes' => 50,
    ],
    'seo_content' => [
        'file' => 'seo_content_engine.php', 'when' => '*/15 * * * *', 'group' => 'seo',
        'title' => 'كتابة المقالات ونشرها', 'enabled' => true, 'max_minutes' => 30,
    ],
    'seo_reports' => [
        'file' => 'seo_scheduled_reports.php', 'when' => '5 * * * *', 'group' => 'seo',
        'title' => 'تقارير SEO على الإيميل', 'enabled' => true, 'max_minutes' => 30,
    ],
    'seo_ranks' => [
        'file' => 'seo_rank_tracking.php', 'when' => '0 3 * * *', 'group' => 'seo',
        'title' => 'ترتيب كلمات العميل في جوجل', 'enabled' => true, 'max_minutes' => 60,
    ],
    'backlinks' => [
        'file' => 'monitor_backlinks.php', 'when' => '0 5 * * 1', 'group' => 'seo',
        'title' => 'فحص الباك لينكس (أسبوعي)', 'enabled' => true, 'max_minutes' => 60,
    ],
    'outreach' => [
        'file' => 'generate_outreach_followups.php', 'when' => '0 4 * * *', 'group' => 'seo',
        'title' => 'مسودات متابعة لطلبات اللينكات', 'enabled' => true, 'max_minutes' => 30,
    ],

    // ===== الإعلانات =====
    'ads_autopilot' => [
        'file' => 'run_ads_autopilot.php', 'when' => '15 * * * *', 'group' => 'ads',
        'title' => 'تحسين الإعلانات تلقائيًا', 'enabled' => true, 'max_minutes' => 50,
    ],
    'ads_alerts' => [
        'file' => 'run_ads_alerts.php', 'when' => '45 * * * *', 'group' => 'ads',
        'title' => 'تنبيهات الإعلانات', 'enabled' => true, 'max_minutes' => 30,
    ],
    'ads_search_terms' => [
        'file' => 'run_ads_search_terms.php', 'when' => '20 6 * * *', 'group' => 'ads',
        'title' => 'كلمات Google السلبية من مصطلحات البحث الحقيقية', 'enabled' => true, 'max_minutes' => 40,
    ],

    // ===== المنافسين =====
    'competitors' => [
        'file' => 'monitor_competitors.php', 'when' => '*/30 * * * *', 'group' => 'competitors',
        'title' => 'مراقبة المنافسين', 'enabled' => true, 'max_minutes' => 25,
    ],
    'ci_rankings' => [
        'file' => 'ci_keyword_rankings.php', 'when' => '0 5 * * *', 'group' => 'competitors',
        'title' => 'ترتيب المنافسين في جوجل', 'enabled' => true, 'max_minutes' => 60,
    ],
    'ci_digest' => [
        'file' => 'ci_weekly_digest.php', 'when' => '0 8 * * 1', 'group' => 'competitors',
        'title' => 'ملخص المنافسين الأسبوعي', 'enabled' => true, 'max_minutes' => 30,
    ],

    // ===== المتابعة والإيرادات =====
    'ai_followups' => [
        'file' => 'process_ai_followups.php', 'when' => '*/30 * * * *', 'group' => 'growth',
        'title' => 'رسايل المتابعة بالذكاء الاصطناعي', 'enabled' => true, 'max_minutes' => 25,
    ],
    'action_center' => [
        'file' => 'action_center_executor.php', 'when' => '*/20 * * * *', 'group' => 'growth',
        'title' => 'تنفيذ اقتراحات مركز الإجراءات', 'enabled' => true, 'max_minutes' => 18,
    ],
    'revenue_scan' => [
        'file' => 'revenue_intelligence_scan.php', 'when' => '0 6 * * *', 'group' => 'growth',
        'title' => 'فحص الإيرادات اليومي', 'enabled' => true, 'max_minutes' => 60,
    ],
    'revenue_actions' => [
        'file' => 'revenue_action_executor.php', 'when' => '10,30,50 * * * *', 'group' => 'growth',
        'title' => 'تنفيذ اقتراحات الإيرادات', 'enabled' => true, 'max_minutes' => 18,
    ],
    'revenue_benchmarks' => [
        'file' => 'revai_benchmarks_rebuild.php', 'when' => '0 4 * * 1', 'group' => 'growth',
        'title' => 'إعادة حساب متوسطات السوق (أسبوعي)', 'enabled' => true, 'max_minutes' => 60,
    ],
];
