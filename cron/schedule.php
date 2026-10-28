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
    'crm_sequences'=>['file'=>'process_crm_sequences.php','when'=>'* * * * *','group'=>'crm','title'=>'خطوات تسلسلات المبيعات المستحقة','enabled'=>true,'max_minutes'=>5],
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
    'admin_watchdog' => [
        'file' => 'admin_watchdog.php', 'when' => '*/10 * * * *', 'group' => 'core',
        'title' => 'حارس الأدمن: يختبر التكاملات ويبعتلك تنبيه لو حاجة وقعت', 'enabled' => true, 'max_minutes' => 9,
    ],
    'scrub_logs' => [
        'file' => 'scrub_logs.php', 'when' => '40 3 * * *', 'group' => 'core',
        'title' => 'تنظيف اللوجات من الباسوردات والتوكنات', 'enabled' => true, 'max_minutes' => 20,
    ],
    'operator_payments' => [
        'file' => 'verify_operator_payments.php', 'when' => '*/10 * * * *', 'group' => 'core',
        'title' => 'تأكيد حجوزات اتدفعت على حساب Stripe بتاع العميل', 'enabled' => true, 'max_minutes' => 9,
    ],
    'expire_unpaid_bookings' => [
        'file' => 'expire_unpaid_bookings.php', 'when' => '*/15 * * * *', 'group' => 'core',
        'title' => 'إلغاء الحجوزات اللي ماتدفعتش ورجوع أماكنها للتوفر', 'enabled' => true, 'max_minutes' => 10,
    ],
    'learning_gaps' => [
        'file' => 'scan_learning_gaps.php', 'when' => '10 5 * * *', 'group' => 'core',
        'title' => 'تجميع أسئلة الزوار اللي الشات بوت ماعرفش يرد عليها', 'enabled' => true, 'max_minutes' => 20,
    ],
    'trial_plan_emails' => [
        'file' => 'send_trial_plan_emails.php', 'when' => '7 * * * *', 'group' => 'core',
        'title' => 'إيميل خطة أول 3 أيام للعملاء في التجربة المجانية', 'enabled' => true, 'max_minutes' => 10,
    ],
    'trip_reminders' => [
        'file' => 'send_trip_reminders.php', 'when' => '*/15 * * * *', 'group' => 'core',
        'title' => 'تذكير العملاء برحلات بكرة (واتساب/إيميل)', 'enabled' => true, 'max_minutes' => 10,
    ],
    'social_autopilot' => [
        'file' => 'social_autopilot.php', 'when' => '25 * * * *', 'group' => 'marketing',
        'title' => 'الطيار الآلي للسوشيال: خطة الشهر الجاي من الحجوزات والرحلات النشطة', 'enabled' => true, 'max_minutes' => 20,
    ],
    'morning_brief' => [
        'file' => 'send_morning_brief.php', 'when' => '*/15 5-12 * * *', 'group' => 'core',
        'title' => 'ملخص الصبح لأصحاب الشركات (واتساب/إيميل)', 'enabled' => true, 'max_minutes' => 10,
    ],
    'billing' => [
        'file' => 'run_billing_lifecycle.php', 'when' => '0 2 * * *', 'group' => 'core',
        'title' => 'الاشتراكات: التجديد والانتهاء', 'enabled' => true, 'max_minutes' => 30,
    ],

    'cleanup_exports' => [
        'file'=>'cleanup_data_exports.php', 'when'=>'25 * * * *', 'group'=>'core',
        'title'=>'حذف ملفات تصدير البيانات المنتهية فقط', 'enabled'=>true, 'max_minutes'=>10,
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
    'gbp_background_sync' => ['file'=>'gbp_enqueue_background_sync.php','when'=>'0 */6 * * *','group'=>'reputation','title'=>'GBP background sync','enabled'=>true,'max_minutes'=>5],
    'seo_sitemap_sync' => ['file'=>'seo_sitemap_sync.php','when'=>'40 * * * *','group'=>'seo','title'=>'Opted-in Search Console sitemap sync','enabled'=>true,'max_minutes'=>15],
    'seo_auto_impact' => ['file'=>'seo_auto_impact.php','when'=>'*/10 * * * *','group'=>'seo','title'=>'Automatic SEO task impact monitors','enabled'=>true,'max_minutes'=>5],
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
    'seo_traffic_alerts' => [
        'file' => 'seo_traffic_alerts.php', 'when' => '20 7 * * *', 'group' => 'seo',
        'title' => 'تنبيه لو زيارات جوجل لموقع العميل وقعت (Search Console)', 'enabled' => true, 'max_minutes' => 20,
    ],
    'fx_refresh' => [
        'file' => 'fx_refresh.php', 'when' => '*/5 * * * *', 'group' => 'core',
        'title' => 'سعر الصرف مربوط بالبنك المركزي (حسب الميعاد اللي في الأدمن)', 'enabled' => true, 'max_minutes' => 3,
    ],
    'platform_outreach' => [
        'file' => 'platform_outreach.php', 'when' => '*/5 * * * *', 'group' => 'growth',
        'title' => 'تسويق المنصة بالإيميل (دفعات صغيرة في مواعيد العمل)', 'enabled' => true, 'max_minutes' => 4,
    ],
    'outreach' => [
        'file' => 'generate_outreach_followups.php', 'when' => '0 4 * * *', 'group' => 'seo',
        'title' => 'مسودات متابعة لطلبات اللينكات', 'enabled' => true, 'max_minutes' => 30,
    ],

    // ===== الإعلانات =====
    'ads_sync' => [
        'file' => 'sync_ads.php', 'when' => '5 */2 * * *', 'group' => 'ads',
        'title' => 'مزامنة حملات Meta و Google وأدائها اليومي', 'enabled' => true, 'max_minutes' => 30,
    ],
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

    // ===== العملاء (CRM) =====
    'crm_sequences' => [
        'file' => 'process_crm_sequences.php', 'when' => '*/15 * * * *', 'group' => 'growth',
        'title' => 'خطوات تسلسلات المتابعة في الـ CRM', 'enabled' => true, 'max_minutes' => 14,
    ],

    // ===== المنافسين =====
    'competitors' => [
        'file' => 'monitor_competitors.php', 'when' => '*/30 * * * *', 'group' => 'competitors',
        'title' => 'مراقبة المنافسين', 'enabled' => true, 'max_minutes' => 25,
    ],
    'seo_content_decay' => ['file'=>'seo_content_decay.php','when'=>'0 7 * * 1','group'=>'seo','title'=>'Weekly content refresh review','enabled'=>true,'max_minutes'=>10],
    'seo_ai_visibility' => ['file'=>'seo_ai_visibility.php','when'=>'0 6 * * 1','group'=>'seo','title'=>'قياس ظهور AI الأسبوعي','enabled'=>true,'max_minutes'=>10],
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
    'ota_supplier_sync' => ['file'=>'ota_supplier_sync.php','when'=>'*/5 * * * *','group'=>'ota','title'=>'OTA supplier read-only sync scheduler','enabled'=>true,'max_minutes'=>3],
    'whatsapp_marketing' => ['file'=>'whatsapp_marketing.php','when'=>'* * * * *','group'=>'whatsapp','title'=>'Opt-in WhatsApp marketing campaigns','enabled'=>true,'max_minutes'=>3],
];
