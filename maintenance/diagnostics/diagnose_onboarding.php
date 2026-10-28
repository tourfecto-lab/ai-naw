<?php
/**
 * Tourfecto - سكريبت تشخيص Onboarding (للاستخدام مرة واحدة)
 * ==========================================================
 * بيفحص فقط (قراءة بس - مفيش أي تعديل أو حذف) كل جدول/عمود بيحتاجه
 * مسار "إنشاء حساب → Onboarding → إنشاء موقع"، وبيوريك آخر أخطاء
 * حقيقية من storage/logs/app.log.
 *
 * الاستخدام:
 *   1. ارفعه في public_html/diagnose_onboarding.php
 *   2. افتح: https://tourfecto.pro/diagnose_onboarding.php?key=tourfecto2026xyz
 *      (غيّر "tourfecto2026xyz" تحت في السطر اللي فيه $secretKey لأي حاجة
 *      يصعب تخمينها، وحط نفس القيمة في الرابط)
 *   3. بعد ما تخلّص منه، **امسحه من السيرفر** - بيكشف تفاصيل تقنية عن
 *      قاعدة البيانات، فمش المفروض يفضل موجود.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // منعرضش أي PHP warning خام في النص - بس النتائج المنظمة تحت

// ------------------------------------------------------------
// حماية بسيطة: لازم تعرف المفتاح ده عشان تشوف النتيجة
// ------------------------------------------------------------
$secretKey = 'tourfecto2026xyz';
if (!isset($_GET['key']) || !hash_equals($secretKey, (string) $_GET['key'])) {
    http_response_code(404);
    exit('Not Found');
}

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_ROOT', ROOT_PATH);
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');

$rows = [];   // نتائج فحص القاعدة
$fatal = null;

try {
    require_once ROOT_PATH . '/vendor/autoload.php';

    if (file_exists(ROOT_PATH . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    }

    require_once APP_PATH . '/Config/app.php';
    require_once APP_PATH . '/Config/constants.php';
    require_once APP_PATH . '/Config/database.php';

    $db = Database::getInstance();

    // ----------------------------------------------------
    // الجداول/الأعمدة اللي مسار Onboarding محتاجها فعليًا
    // كل سطر: [الجدول, العمود أو null لو بنفحص وجود الجدول بس, ملاحظة]
    // ----------------------------------------------------
    $checks = [
        ['websites', null, 'جدول المواقع'],
        ['websites', 'main_url', 'رابط الموقع'],
        ['websites', 'domain', 'عمود قديم - لازم يكون NULLABLE مش NOT NULL'],
        ['websites', 'onboarding_completed_at', ''],
        ['onboarding_drafts', null, 'مسودات الـWizard'],
        ['activity_logs', null, 'سجل النشاط'],
        ['activity_logs', 'module', ''],
        ['activity_logs', 'action', ''],
        ['activity_logs', 'agency_id', ''],
        ['competitors', null, 'جدول المنافسين'],
        ['competitors', 'competitor_domain', ''],
        ['competitors', 'competitor_name', ''],
        ['jobs', null, 'طابور المهام الخلفية (Queue) - لو ناقص، التحليل بيشتغل Sync بدل Async'],
        ['subscriptions', 'competitor_analysis_used', ''],
        ['generated_websites', 'views_count', ''],
        ['ai_reports', null, 'تقارير التحليل - بيتسجل فيه نتيجة تحليل الـOnboarding'],
        ['onboarding_competitor_snapshots', null, ''],
    ];

    foreach ($checks as [$table, $column, $note]) {
        $tableExists = (bool) $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );

        if (!$tableExists) {
            $rows[] = ['❌ الجدول مش موجود', $table, $column ?? '-', $note];
            continue;
        }

        if ($column === null) {
            $rows[] = ['✅ الجدول موجود', $table, '-', $note];
            continue;
        }

        $col = $db->query(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );

        if (empty($col)) {
            $rows[] = ['❌ العمود مش موجود', "$table.$column", '-', $note];
            continue;
        }

        $nullable = $col[0]['IS_NULLABLE'] === 'YES';
        $default = $col[0]['COLUMN_DEFAULT'];
        $status = ($column === 'domain')
            ? ($nullable ? '✅ Nullable (تمام)' : '❌ NOT NULL بدون default - ده هيكسر الحفظ')
            : '✅ موجود';

        $rows[] = [$status, "$table.$column", $col[0]['COLUMN_TYPE'] . ($nullable ? ', NULL' : ', NOT NULL') . ($default !== null ? ", default={$default}" : ''), $note];
    }

} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

// ------------------------------------------------------------
// آخر أخطاء حقيقية من app.log (فلترة على كلمات مفتاحية بس عشان
// النتيجة تبقى قصيرة ومركّزة)
// ------------------------------------------------------------
$logTail = [];
$logPath = TOURFECTO_STORAGE . '/logs/app.log';
if (is_file($logPath)) {
    $keywords = ['Onboarding', 'websites', 'onboarding_drafts', 'activity_logs', 'competitors', 'Query Error', 'Exec Error', 'Fatal Error'];
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES);
    if ($lines) {
        $lines = array_slice($lines, -800); // آخر 800 سطر بس عشان الأداء
        foreach ($lines as $line) {
            foreach ($keywords as $kw) {
                if (stripos($line, $kw) !== false) {
                    $logTail[] = $line;
                    break;
                }
            }
        }
        $logTail = array_slice($logTail, -25); // آخر 25 سطر مطابق
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تشخيص Onboarding</title>
<style>
    body { font-family: -apple-system, 'Tahoma', sans-serif; background:#0b0f19; color:#e6e9f0; padding:24px; }
    h1 { font-size:20px; } h2 { font-size:16px; margin-top:32px; color:#EFB05E; }
    table { border-collapse: collapse; width:100%; margin-top:12px; }
    td, th { border:1px solid #2a3245; padding:8px 10px; text-align:right; font-size:13px; }
    th { background:#151b2c; }
    tr:nth-child(even) { background:#111726; }
    pre { background:#111726; padding:12px; border-radius:8px; overflow-x:auto; font-size:12px; direction:ltr; text-align:left; white-space:pre-wrap; }
    .fatal { color:#FF6B5B; font-weight:bold; }
    .warn-title { color:#EFB05E; }
</style>
</head>
<body>
<h1>🔍 تشخيص مسار التسجيل → Onboarding</h1>

<?php if ($fatal): ?>
    <p class="fatal">تعذر الاتصال بقاعدة البيانات أو تحميل الإعدادات: <?= htmlspecialchars($fatal) ?></p>
<?php else: ?>
    <h2>1) حالة الجداول والأعمدة</h2>
    <table>
        <tr><th>الحالة</th><th>الجدول/العمود</th><th>تفاصيل</th><th>ملاحظة</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr><td><?= htmlspecialchars($r[0]) ?></td><td><code><?= htmlspecialchars($r[1]) ?></code></td><td><?= htmlspecialchars($r[2]) ?></td><td><?= htmlspecialchars($r[3]) ?></td></tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>2) آخر أخطاء متعلقة بالتسجيل/الـOnboarding من app.log</h2>
<?php if (empty($logTail)): ?>
    <p>مفيش أي سطر مطابق في آخر 800 سطر من اللوج. جرّب تعمل المحاولة (زرار "ابدأ التحليل") تاني، وبعدين حدّث الصفحة دي.</p>
<?php else: ?>
    <pre><?= htmlspecialchars(implode("\n", $logTail)) ?></pre>
<?php endif; ?>

<h2>3) الخطوة الجاية</h2>
<p>ابعت لي صورة أو نسخة من الجدولين فوق كاملين - هينفعوا يحددوا بالظبط فين المشكلة من غير ما تدوّر يدويًا.</p>
<p style="color:#FF6B5B;"><b>متنساش تمسح الملف ده من السيرفر بعد ما تخلّص - بيكشف تفاصيل قاعدة البيانات.</b></p>
</body>
</html>