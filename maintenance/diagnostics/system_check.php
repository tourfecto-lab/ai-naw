<?php
/**
 * Tourfecto - فحص شامل لصحة الموقع
 * ⚠️ ملف تشخيص مؤقت. ارفعه في public_html (بجانب index.php)، افتحه من
 * المتصفح، وبعد ما تخلص افحص/إصلاح احذفه فورًا من السيرفر - بيكشف
 * معلومات حساسة (حالة مفاتيح الـ API، أسماء الجداول، آخر الأخطاء).
 *
 * الاستخدام: /system_check.php?key=CHANGE_ME
 * غيّر SECRET_KEY تحت قبل ما ترفعه، عشان محدش تاني يقدر يفتحه لو
 * لقى اسم الملف بالصدفة.
 *
 * فحوصات الملف ده:
 *   1) تحميل .env و composer autoload
 *   2) الاتصال الفعلي بقاعدة البيانات
 *   3) لكل Model في app/Models: مقارنة الحقول (fillable) بأعمدة
 *      الجدول الحقيقية في قاعدة البيانات - عشان نمسك أي "Unknown column"
 *      قبل ما يحصل فعليًا لأي مستخدم (نفس نوع مشكلة main_url اللي
 *      حصلت في websites، بس على كل الجداول مرة واحدة)
 *   4) فحص مفاتيح الـ API (.env + أي قيمة متسجلة في لوحة الأدمن
 *      system_settings بتاعت GeminiClient) وهل شكلها Placeholder
 *      ولو طلبت, اختبار حي لمفتاح Gemini فعليًا
 *   5) فحص Syntax لكل ملفات PHP (لو shell_exec متاح على الاستضافة)
 *   6) فحص AI Chat Platform (ملفات + جداول + عمود next_recommended_action)
 *   7) آخر الأخطاء المسجلة في storage/logs/app.log
 */

// ============================================
// 0. حماية بسيطة
// ============================================
const SECRET_KEY = 'CHANGE_ME_1234';
if (($_GET['key'] ?? '') !== SECRET_KEY) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(120);

$root = dirname(__DIR__);
define('ROOT_PATH', $root);
define('APP_PATH', $root . '/app');
define('TOURFECTO_ROOT', $root);
define('TOURFECTO_STORAGE', $root . '/storage');

$report = []; // كل قسم بيضيف نتايجه هنا: ['section' => ..., 'status' => ok|warn|fail, 'lines' => [...]]

function add_section(array &$report, string $title, string $status, array $lines): void
{
    $report[] = ['title' => $title, 'status' => $status, 'lines' => $lines];
}

// ============================================
// 1. Composer Autoload + .env
// ============================================
$lines = [];
$autoloadOk = file_exists($root . '/vendor/autoload.php');
$lines[] = $autoloadOk ? '✅ vendor/autoload.php موجود' : '❌ vendor/autoload.php غير موجود - الموقع كله واقع';
if ($autoloadOk) {
    require_once $root . '/vendor/autoload.php';
}

$envOk = file_exists($root . '/.env');
$lines[] = $envOk ? '✅ ملف .env موجود' : '❌ ملف .env غير موجود';

if ($envOk && class_exists('Dotenv\\Dotenv')) {
    try {
        Dotenv\Dotenv::createImmutable($root)->load();
        $lines[] = '✅ تم تحميل .env بنجاح';
    } catch (Throwable $e) {
        $lines[] = '❌ فشل تحميل .env: ' . $e->getMessage();
    }
}

add_section($report, '1) الإعداد الأساسي (Autoload / .env)', $autoloadOk && $envOk ? 'ok' : 'fail', $lines);

// تحميل باقي الكونفيج بنفس ترتيب public_html/index.php
foreach (['/Config/app.php', '/Config/constants.php', '/Config/database.php', '/Config/encryption.php'] as $cfg) {
    if (file_exists(APP_PATH . $cfg)) {
        try {
            require_once APP_PATH . $cfg;
        } catch (Throwable $e) { /* هيتلقط في قسم الأخطاء تحت */
        }
    }
}
foreach (['/Config/gemini.php', '/Config/whatsapp.php', '/Config/integrations.PHP', '/Config/openai.php', '/Config/deepseek.php', '/Config/kimi.php'] as $cfg) {
    if (file_exists(APP_PATH . $cfg)) {
        try {
            require_once APP_PATH . $cfg;
        } catch (Throwable $e) {
        }
    }
}

// تحميل باقي كلاسات الأساس يدويًا لو مفيش classmap محدّث (نفس فكرة index.php)
foreach (glob(APP_PATH . '/Core/*.php') as $f) {
    require_once $f;
}
foreach (glob(APP_PATH . '/Core/Contracts/*.php') as $f) {
    require_once $f;
}
foreach (glob(APP_PATH . '/Core/Repository/*.php') as $f) {
    require_once $f;
}
foreach (glob(APP_PATH . '/Models/*.php') as $f) {
    require_once $f;
}
foreach (glob(APP_PATH . '/Repositories/*.php') as $f) {
    require_once $f;
}
if (file_exists(APP_PATH . '/Services/System/SystemSettingsService.php')) {
    require_once APP_PATH . '/Services/System/SystemSettingsService.php';
}

// ============================================
// 2. الاتصال بقاعدة البيانات
// ============================================
$lines = [];
$dbOk = false;
$pdo = null;
try {
    if (!defined('DB_HOST')) {
        throw new Exception('ثوابت قاعدة البيانات (DB_HOST...) مش معرّفة - Config/database.php ماتحملش صح');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET ?? 'utf8mb4');
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbOk = true;
    $lines[] = '✅ الاتصال بقاعدة البيانات ناجح (DB: ' . DB_NAME . ')';
    $tableCount = (int) $pdo->query('SHOW TABLES')->rowCount();
    $lines[] = "ℹ️ عدد الجداول: {$tableCount}";
} catch (Throwable $e) {
    $lines[] = '❌ فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
}
add_section($report, '2) قاعدة البيانات', $dbOk ? 'ok' : 'fail', $lines);

// ============================================
// 3. مقارنة كل Model بأعمدة جدوله الحقيقية
// ============================================
$lines = [];
$modelStatus = 'ok';
if ($dbOk) {
    $modelFiles = glob(APP_PATH . '/Models/*.php');
    sort($modelFiles);

    foreach ($modelFiles as $file) {
        $class = basename($file, '.php');
        if (!class_exists($class)) {
            $lines[] = "⚠️ {$class}: الكلاس مش متحمّل (اسم ملف مختلف عن اسم الكلاس؟)";
            $modelStatus = 'warn';
            continue;
        }

        try {
            $ref = new ReflectionClass($class);
            if (!$ref->isSubclassOf('Model') || $ref->isAbstract()) {
                continue;
            }

            $tableProp = $ref->getProperty('table');
            $tableProp->setAccessible(true);
            $fillableProp = $ref->getProperty('fillable');
            $fillableProp->setAccessible(true);

            $defaults = $ref->getDefaultProperties();
            $table = $defaults['table'] ?? null;
            $fillable = $defaults['fillable'] ?? [];

            if (!$table) {
                continue;
            }

            // هل الجدول نفسه موجود؟
            $tblCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $tblCheck->execute([$table]);
            if ((int) $tblCheck->fetchColumn() === 0) {
                $lines[] = "❌ {$class}: الجدول `{$table}` غير موجود في قاعدة البيانات أصلاً";
                $modelStatus = 'fail';
                continue;
            }

            // أعمدة الجدول الحقيقية
            $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $colStmt->execute([$table]);
            $realCols = array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN));

            // لو الموديل بيعمل auto-detect لأسماء الأعمدة (زي Website)، نجرب
            // نبني نسخة فعلية منه عشان ناخد columnAliases/unmappableFields
            // الحقيقية بعد الاكتشاف، مش الأسماء الخام في fillable بس.
            $columnAliases = [];
            $unmappable = [];
            try {
                $instance = $ref->newInstance();
                if ($ref->hasProperty('columnAliases')) {
                    $p = $ref->getProperty('columnAliases');
                    $p->setAccessible(true);
                    $columnAliases = $p->getValue($instance) ?: [];
                }
                if ($ref->hasProperty('unmappableFields')) {
                    $p = $ref->getProperty('unmappableFields');
                    $p->setAccessible(true);
                    $unmappable = $p->getValue($instance) ?: [];
                }
            } catch (Throwable $e) {
                // بعض الـ Models constructor بتاعها محتاج parameters - تجاهل، هنكتفي بفحص fillable الخام
            }

            $missing = [];
            foreach ($fillable as $field) {
                if (in_array(strtolower($field), $realCols, true)) {
                    continue; // العمود موجود بنفس الاسم
                }
                if (isset($columnAliases[$field]) && in_array(strtolower($columnAliases[$field]), $realCols, true)) {
                    continue; // متطابق عن طريق alias مكتشف تلقائيًا - سليم
                }
                if (in_array($field, $unmappable, true)) {
                    // الموديل نفسه عارف إن العمود ده مش موجود وبيشيله من أي SQL -
                    // مش خطر فوري، بس نبلغ عنه كمعلومة (فيه ميزة مش شغالة كاملة)
                    $lines[] = "ℹ️ {$class} (`{$table}`): الحقل `{$field}` مش موجود كعمود حقيقي، والموديل بيتعامل معاه (يشيله من الاستعلامات) - المزايا اللي بتعتمد عليه ناقصة بيانات";
                    continue;
                }
                $missing[] = $field;
            }

            if (!empty($missing)) {
                $lines[] = "❌ {$class} (`{$table}`): الحقول دي في \$fillable بس مفيش عمود مطابق ليها في الجدول ولا alias مكتشف: " . implode(', ', $missing) . " -- أي كود بيستخدمهم في SQL خام هيدي 'Unknown column'";
                $modelStatus = 'fail';
            }
        } catch (Throwable $e) {
            $lines[] = "⚠️ {$class}: تعذر الفحص - " . $e->getMessage();
            $modelStatus = ($modelStatus === 'fail') ? 'fail' : 'warn';
        }
    }

    if (empty($lines)) {
        $lines[] = '✅ كل الـ Models متطابقة مع أعمدة قاعدة البيانات الحقيقية';
    }
} else {
    $lines[] = '⏭️ اتخطى - قاعدة البيانات مش متصلة';
    $modelStatus = 'warn';
}
add_section($report, '3) تطابق Models مع أعمدة قاعدة البيانات (فحص كل الجداول دفعة واحدة)', $modelStatus, $lines);

// ============================================
// 4. مفاتيح الـ API
// ============================================
$lines = [];
$apiStatus = 'ok';

function looks_like_placeholder(?string $v): bool
{
    if ($v === null || $v === '') {
        return true;
    }
    $v = strtolower($v);
    return (strpos($v, 'your-') !== false) || strpos($v, 'xxxxx') !== false || $v === 'null';
}

$keysToCheck = [
    'GEMINI_API_KEY'        => 'Gemini (تحليل SEO/AEO/GEO، الشات بوت)',
    'TRIPADVISOR_API_KEY'   => 'TripAdvisor (مزامنة المراجعات)',
    'GOOGLE_CLIENT_ID'      => 'Google OAuth (Business Profile / Search Console)',
    'GOOGLE_CLIENT_SECRET'  => 'Google OAuth Secret',
    'META_APP_ID'           => 'Meta Ads',
    'META_APP_SECRET'       => 'Meta Ads Secret',
    'STRIPE_API_KEY'        => 'Stripe (لو مفعّل)',
    'MAIL_PASSWORD'         => 'SMTP / إرسال إيميلات',
];

foreach ($keysToCheck as $const => $label) {
    $envVal = getenv($const) ?: ($_ENV[$const] ?? null);
    if ($envVal === null || $envVal === false) {
        $lines[] = "❌ {$const} ({$label}): مش موجود في .env أصلاً";
        $apiStatus = 'fail';
    } elseif (looks_like_placeholder($envVal)) {
        $lines[] = "❌ {$const} ({$label}): لسه القيمة الافتراضية (placeholder) - محتاج تحط المفتاح الحقيقي";
        $apiStatus = 'fail';
    } else {
        $lines[] = "✅ {$const} ({$label}): موجود في .env (طول القيمة: " . strlen($envVal) . " حرف)";
    }
}

// تحديدًا لمفتاح Gemini: فيه مصدر تاني ممكن يجاوب فوق قيمة .env - إعدادات
// لوحة الأدمن (system_settings) زي ما GeminiClient بيعمل بالظبط
if ($dbOk && class_exists('SystemSettingsService')) {
    try {
        $svc = new SystemSettingsService();
        $effectiveKey = $svc->get('gemini_api_key', defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
        $envKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

        if ($effectiveKey === '') {
            $lines[] = '❌ GEMINI_API_KEY الفعلي (بعد لوحة الأدمن): فاضي تمامًا - هيفشل أي طلب Gemini بـ "API key not valid"';
            $apiStatus = 'fail';
        } elseif ($effectiveKey !== $envKey) {
            $lines[] = '⚠️ فيه مفتاح Gemini متسجل في لوحة الأدمن (system_settings) بيبقى له الأولوية على .env - لو الخطأ لسه ظاهر، راجع القيمة دي من لوحة الأدمن مش من .env بس (آخر 6 حروف من المفتاح الفعّال: ...' . substr($effectiveKey, -6) . ')';
        } else {
            $lines[] = 'ℹ️ مفتاح Gemini الفعّال هو نفسه القيمة في .env (مفيش override من لوحة الأدمن)';
        }

        // اختبار حي اختياري: ?live_test_gemini=1 (بيستهلك من الكوتة بتاعتك)
        if (($_GET['live_test_gemini'] ?? '') === '1' && $effectiveKey !== '') {
            $testUrl = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($effectiveKey);
            $ch = curl_init($testUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $lines[] = '✅ اختبار حي: المفتاح شغال فعليًا مع Google (HTTP 200)';
            } else {
                $bodyPreview = is_string($resp) ? substr($resp, 0, 300) : '';
                $lines[] = "❌ اختبار حي: Google رجّع HTTP {$code} - {$bodyPreview}";
                $apiStatus = 'fail';
            }
        } else {
            $lines[] = 'ℹ️ لاختبار المفتاح فعليًا مع Google (بيستهلك كوتة صغيرة): افتح نفس الرابط ده وضيف &live_test_gemini=1';
        }
    } catch (Throwable $e) {
        $lines[] = '⚠️ تعذر فحص SystemSettingsService: ' . $e->getMessage();
    }
}

add_section($report, '4) مفاتيح الـ API', $apiStatus, $lines);

// ============================================
// 5. فحص Syntax لملفات PHP
// ============================================
$lines = [];
$syntaxStatus = 'ok';
$canExec = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

if (!$canExec) {
    $lines[] = 'ℹ️ shell_exec متعطّل على الاستضافة دي - اتخطينا فحص الـ Syntax (استضافات shared كتير بتقفله لأسباب أمنية)';
} else {
    $dirs = [APP_PATH, $root . '/cron', __DIR__];
    $checked = 0;
    $errors = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            $out = shell_exec('php -l ' . escapeshellarg($f->getPathname()) . ' 2>&1');
            if ($out && stripos($out, 'No syntax errors detected') === false) {
                $errors[] = $f->getPathname() . ': ' . trim($out);
            }
        }
    }
    $lines[] = "ℹ️ تم فحص {$checked} ملف PHP";
    if (empty($errors)) {
        $lines[] = '✅ مفيش أخطاء Syntax';
    } else {
        $syntaxStatus = 'fail';
        foreach ($errors as $e) {
            $lines[] = '❌ ' . $e;
        }
    }
}
add_section($report, '5) فحص Syntax لملفات PHP', $syntaxStatus, $lines);

// ============================================
// 6. AI Chat Platform - وجود الملفات والجداول والعمود الجديد
// ============================================
$lines = [];
$chatStatus = 'ok';
$aiChatClasses = [
    'Models/AiKnowledgeBase.php',
    'Models/AiChatConversation.php',
    'Models/AiCustomerMemory.php',
    'Models/AiLead.php',
    'Models/AiFollowup.php',
    'Models/AiFollowupRule.php',
    'Models/AiCustomTag.php',
    'Models/AiUsageLog.php',
    'Services/AI/Providers/AIProviderInterface.php',
    'Services/AI/Providers/AIProviderManager.php',
    'Services/AI/KnowledgeBaseService.php',
    'Services/AI/AIConversationEngine.php',
    'Services/AI/LeadScoringService.php',
    'Services/AI/BusinessHoursService.php',
    'Services/AI/FollowUpAutomationService.php',
    'Services/AI/AiAnalyticsService.php',
    'Services/AI/AiReplySuggestionsService.php',
    'Services/Chat/UnifiedInboxService.php',
    'Services/Chat/ChatManager.php',
    'Services/Chat/AutoReplyEngine.php',
    'Controllers/AiKnowledgeBaseController.php',
    'Controllers/ChatInboxController.php',
    'Controllers/AiLeadController.php',
    'Controllers/AiFollowupSettingsController.php',
    'Controllers/AiAnalyticsController.php',
    'Controllers/AiCustomTagController.php',
    'Controllers/ChatController.php',
];
foreach ($aiChatClasses as $rel) {
    $path = APP_PATH . '/' . $rel;
    if (!file_exists($path)) {
        $lines[] = '❌ ملف ناقص: ' . $rel;
        $chatStatus = 'fail';
    } elseif (!class_exists(basename($rel, '.php'))) {
        $lines[] = '⚠️ الملف موجود لكن الكلاس مش متحمّل: ' . $rel . ' (لازم يتسجّل في $optionalNewClassFiles بـ public_html/index.php)';
        if ($chatStatus !== 'fail') {
            $chatStatus = 'warn';
        }
    }
}
if ($dbOk) {
    foreach (['ai_knowledge_base', 'ai_conversations', 'ai_customer_memory', 'ai_leads', 'ai_followup_rules', 'ai_followups', 'ai_custom_tags', 'ai_usage_logs'] as $tbl) {
        $tchk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $tchk->execute([$tbl]);
        if ((int) $tchk->fetchColumn() === 0) {
            $lines[] = '❌ جدول ناقص: `' . $tbl . '` (شغّل migration 2026_08_08_000001_create_ai_chat_platform_tables.sql)';
            $chatStatus = 'fail';
        }
    }
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversations' AND COLUMN_NAME = 'next_recommended_action'");
    $colChk->execute();
    if ((int) $colChk->fetchColumn() === 0) {
        $lines[] = '❌ عمود next_recommended_action ناقص على ai_conversations (شغّل migration 2026_08_15_000002_add_next_action_to_ai_conversations.sql)';
        $chatStatus = 'fail';
    }
}
if (empty($lines)) {
    $lines[] = '✅ كل ملفات AI Chat موجودة والكلاسات متحمّلة، وكل جداول/عمود الموديول موجودة في قاعدة البيانات';
}
add_section($report, '6) AI Chat Platform (ملفات + جداول + عمود next_recommended_action)', $chatStatus, $lines);

// ============================================
// 7. آخر الأخطاء المسجلة
// ============================================
$lines = [];
$logPath = TOURFECTO_STORAGE . '/logs/app.log';
if (file_exists($logPath)) {
    $all = file($logPath);
    $tail = array_slice($all, -40);
    $lines[] = 'ℹ️ آخر 40 سطر من ' . $logPath . ':';
    $lines[] = '<pre style="white-space:pre-wrap;font-size:12px;background:#0b0f1a;color:#c8d0e0;padding:12px;border-radius:8px;max-height:400px;overflow:auto;">' . htmlspecialchars(implode('', $tail), ENT_QUOTES, 'UTF-8') . '</pre>';
} else {
    $lines[] = 'ℹ️ ملف اللوج مش موجود لسه (' . $logPath . ')';
}
add_section($report, '7) آخر الأخطاء المسجلة (Logger)', 'ok', $lines);

// ============================================
// طباعة التقرير
// ============================================
$statusColor = ['ok' => '#1f9d55', 'warn' => '#c9a227', 'fail' => '#d64545'];
$statusLabel = ['ok' => '✅ سليم', 'warn' => '⚠️ يستاهل مراجعة', 'fail' => '❌ فيه مشكلة'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>فحص صحة الموقع - Tourfecto</title>
<style>
  body{font-family:Tahoma,'IBM Plex Sans Arabic',sans-serif;background:#060A13;color:#F2F4F8;margin:0;padding:24px;}
  h1{font-size:20px;margin-bottom:24px;}
  .section{background:#0f1524;border:1px solid #1c2438;border-radius:10px;padding:18px 20px;margin-bottom:16px;}
  .section h2{font-size:15px;margin:0 0 10px;display:flex;justify-content:space-between;align-items:center;}
  .badge{font-size:12px;padding:3px 10px;border-radius:20px;color:#0b0f1a;font-weight:bold;}
  ul{margin:0;padding-inline-start:20px;line-height:1.9;font-size:13px;}
  li{margin-bottom:4px;}
</style>
</head>
<body>
<h1>🩺 فحص صحة الموقع - <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></h1>
<?php foreach ($report as $sec): ?>
  <div class="section">
    <h2>
      <?= htmlspecialchars($sec['title'], ENT_QUOTES, 'UTF-8') ?>
      <span class="badge" style="background:<?= $statusColor[$sec['status']] ?>"><?= $statusLabel[$sec['status']] ?></span>
    </h2>
    <ul>
      <?php foreach ($sec['lines'] as $l): ?>
        <li><?= $l ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>
<p style="color:#8996AC;font-size:12px;">⚠️ احذف الملف ده من السيرفر بعد ما تخلص - بيكشف معلومات حساسة عن قاعدة البيانات ومفاتيح الـ API.</p>
</body>
</html>
