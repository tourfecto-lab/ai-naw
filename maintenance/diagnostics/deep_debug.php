<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<div style='font-family: monospace; padding: 20px; background: #0f172a; color: #f8fafc;'>";
echo "<h2>🔍 فحص استدعاء /api/integrations المباشر</h2>";

$baseDir = dirname(__DIR__);
if (file_exists($baseDir . '/vendor/autoload.php')) require_once $baseDir . '/vendor/autoload.php';
if (file_exists($baseDir . '/config.php')) require_once $baseDir . '/config.php';
if (file_exists($baseDir . '/bootstrap.php')) require_once $baseDir . '/bootstrap.php';

// محاكاة مستخدم مسجل دخول بصلاحية الأدمن لتجاوز الميدلوير
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

echo "<h3>1. اختبار تشغيل IntegrationsController مباشرة:</h3>";
try {
    $controllerFile = $baseDir . '/app/Controllers/IntegrationsController.php';
    if (!file_exists($controllerFile)) {
        throw new Exception("الملف غير موجود في: {$controllerFile}");
    }
    require_once $controllerFile;

    $ctrl = new IntegrationsController();
    
    // محاولة استدعاء الدالة المربوطة بـ GET /api/integrations
    $method = method_exists($ctrl, 'index') ? 'index' : (method_exists($ctrl, 'list') ? 'list' : 'getAll');
    echo "<div>الدالة المستهدفة: {$method}()</div>";
    
    $result = $ctrl->$method();
    echo "<div style='color: #4ade80;'>✔ تم تنفيذ الدالة بنجاح دون أخطاء برمجية!</div>";
    echo "<pre style='background:#1e293b; color:#a5f3fc; padding:10px;'>" . htmlspecialchars(print_r($result, true)) . "</pre>";

} catch (Throwable $t) {
    echo "<div style='background: #450a0a; border: 2px solid #ef4444; padding: 15px; border-radius: 6px; margin: 15px 0;'>";
    echo "<h3 style='color: #fca5a5; margin:0 0 10px 0;'>🚨 هذا هو الخطأ الفعلي الذي يكسر صفحة التكاملات:</h3>";
    echo "<strong>نوع الخطأ:</strong> " . get_class($t) . "<br>";
    echo "<strong>الرسالة:</strong> " . htmlspecialchars($t->getMessage()) . "<br>";
    echo "<strong>الملف:</strong> " . htmlspecialchars($t->getFile()) . "<br>";
    echo "<strong>السطر:</strong> " . $t->getLine() . "<br><br>";
    echo "<strong>تتبع الاستدعاء (Trace):</strong><pre style='background:#1e1e2e; color:#f87171; padding:10px; overflow-x:auto;'>" 
        . htmlspecialchars($t->getTraceAsString()) . "</pre>";
    echo "</div>";
}

// 2. قراءة آخر أسطر سُجلت في اللوج لحظة ضغطك على الصفحة الآن
echo "<h3>2. آخر الأخطاء من storage/logs/app.log:</h3>";
$logPath = $baseDir . '/storage/logs/app.log';
if (file_exists($logPath) && is_readable($logPath)) {
    $lines = file($logPath);
    $last = array_slice($lines, -10);
    echo "<pre style='background: #1e293b; color: #e2e8f0; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;'>" 
        . htmlspecialchars(implode("", $last)) . "</pre>";
}

echo "</div>";