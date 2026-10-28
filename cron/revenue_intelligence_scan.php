<?php

/**
 * Tourfecto - AI Revenue Intelligence: Daily Proactive Scan
 * @version 1.0.0
 *
 * Section 18 (Background Jobs) + Section 25 (Events) - تكملة:
 *
 * الـ Job الأساسي (RecomputeRevenueInsightsJob) بيتشغّل تفاعليًا بس -
 * لما صفقة تتقفل (crm.deal.won/lost). ده كويس لكن مش كافي لوحده: مخاطر
 * زي "تراجع إيراد تدريجي" أو "شذوذ في الإيراد اليومي" ممكن تحصل من غير
 * أي صفقة تتقفل خالص، فمحتاجين فحص استباقي دوري (يومي) لكل الحسابات
 * النشطة - مش بس رد فعل على حدث.
 *
 * السكريبت ده بسيط عمدًا: بيلاقي كل المستخدمين اللي عندهم بيانات إيراد
 * حقيقية (سجل إيراد أو صفقة CRM حديثة)، وبيجدول Job إعادة حساب لكل
 * واحد فيهم (نفس الـ Job اللي بيتشغّل من الأحداث - كود واحد، مسار
 * تنفيذ واحد، بدل ما نكرر منطق "احسب واكتشف وابعت إشعار" في مكانين).
 *
 * الجدولة (مش موجودة تلقائيًا - المستضيف مفيهوش SSH لعمل crontab جديد
 * تلقائيًا، لازم تتضاف يدويًا من لوحة تحكم الاستضافة):
 *   0 6 * * *  php /path/to/project/cron/revenue_intelligence_scan.php
 * (مرة واحدة يوميًا الساعة 6 صباحًا مثلاً - وقت مناسب قبل ما صاحب
 * الشركة يفتح الداشبورد بداية اليوم).
 *
 * ملاحظة: السكريبت ده بس "بيجدول" Jobs في جدول jobs الموجود فعلاً -
 * التنفيذ الفعلي بيحصل بعدين عن طريق cron/process_queue.php الموجود
 * أصلاً في المشروع (نفس اللي بيشغّل أي Job تاني). لو مفيش worker بيشغّل
 * process_queue.php بشكل دوري، الـ Jobs دي هتقعد في الطابور من غير ما
 * تتنفذ - نفس الاعتماد الموجود بالفعل لأي Job تاني في المشروع.
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');

require_once ROOT_PATH . '/vendor/autoload.php';

// نفس منطق public_html/index.php: كلاسات الموديول لسه مش في classmap
// بتاع composer على هذا المستضيف (لا يوجد SSH لتشغيل composer dump-autoload).
$requiredClassFiles = [
    APP_PATH . '/Jobs/RecomputeRevenueInsightsJob.php',
    APP_PATH . '/Jobs/SendRevenueDigestJob.php',
];
foreach ($requiredClassFiles as $classFile) {
    if (file_exists($classFile)) {
        require_once $classFile;
    }
}

if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        error_log('Failed to load .env: ' . $e->getMessage());
    }
}

require_once APP_PATH . '/Config/app.php';
require_once APP_PATH . '/Config/constants.php';
require_once APP_PATH . '/Config/database.php';
require_once APP_PATH . '/Config/encryption.php';

if (!function_exists('enqueue') || !class_exists('QueueManager')) {
    fwrite(STDERR, "revenue_intelligence_scan: queue system not available, aborting.\n");
    exit(1);
}

try {
    $db = Database::getInstance();

    // مستخدمين نشطين حقيقيًا: عندهم سجل إيراد أو صفقة CRM خلال آخر 60 يوم.
    // ده يوفّر جدولة Jobs لحسابات فاضية/متوقفة تمامًا، من غير ما نفوّت
    // أي حساب فيه نشاط حقيقي محتاج مراقبة.
    $userIds = $db->query(
        "SELECT DISTINCT user_id FROM (
            SELECT user_id FROM rev_revenue_records WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            UNION
            SELECT owner_user_id AS user_id FROM crm_deals WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        ) AS active_revenue_users"
    );

    $count = 0;
    $digestCount = 0;
    foreach ($userIds as $row) {
        $userId = (int) $row['user_id'];
        if ($userId <= 0) {
            continue;
        }
        enqueue(RecomputeRevenueInsightsJob::class, ['user_id' => $userId]);
        $count++;
        // v1.4.0: نفس الـ Scan اليومي يجدول الـ Revenue Digest برضه
        // (نفس قائمة المستخدمين النشطين الحقيقية) - إيميل ملخص يومي واحد
        // للأرقام الحقيقية. الـ Job نفسه بيتحمل من الـ Mailer/لا-بيانات
        // بيسكت بأمان من غير فشل دائم.
        enqueue(SendRevenueDigestJob::class, ['user_id' => $userId], 'default', 60);
        $digestCount++;
    }

    $message = "revenue_intelligence_scan: enqueued {$count} user(s) for recompute and {$digestCount} revenue digest(s).";
    echo $message . "\n";
    if (class_exists('Logger')) {
        Logger::info($message);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'revenue_intelligence_scan failed: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('revenue_intelligence_scan failed', ['message' => $e->getMessage()]);
    }
    exit(1);
}
