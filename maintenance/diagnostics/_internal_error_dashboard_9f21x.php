<?php
/**
 * Tourfecto - Error Dashboard
 * صفحة عرض أخطاء الموقع للمطورين والمشرفين
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// 1. المصادقة والصلاحيات
// ============================================
session_start();

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

// التحقق من صلاحية المشرف (Admin or Super Admin)
$allowedRoles = ['admin', 'super_admin'];
if (!in_array($_SESSION['user_role'] ?? 'user', $allowedRoles)) {
    die('❌ غير مصرح لك بالوصول إلى هذه الصفحة.');
}

// ============================================
// 2. تحميل الكلاسات
// ============================================
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Logger.php';

$db = Database::getInstance();

// ============================================
// 3. معالجة الإجراءات
// ============================================
$action = $_GET['action'] ?? 'view';
$logFile = $_GET['file'] ?? 'error.log';
$lines = (int) ($_GET['lines'] ?? 100);
$filter = $_GET['filter'] ?? '';

if ($action === 'clear' && isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    clearLogFile($logFile);
    header('Location: ?file=' . $logFile . '&cleared=1');
    exit;
}

if ($action === 'download') {
    downloadLogFile($logFile);
    exit;
}

// ============================================
// 4. دوال مساعدة
// ============================================

/**
 * تنظيف ملف السجل
 */
function clearLogFile(string $logFile): void
{
    $logPath = __DIR__ . '/../storage/logs/' . $logFile;
    if (file_exists($logPath)) {
        file_put_contents($logPath, '');
    }
}

/**
 * تحميل ملف السجل
 */
function downloadLogFile(string $logFile): void
{
    $logPath = __DIR__ . '/../storage/logs/' . $logFile;
    if (file_exists($logPath)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $logFile . '"');
        readfile($logPath);
    }
}

/**
 * قراءة محتوى ملف السجل
 */
function readLogFile(string $logFile, int $lines = 100, string $filter = ''): array
{
    $logPath = __DIR__ . '/../storage/logs/' . $logFile;

    if (!file_exists($logPath)) {
        return ['error' => 'ملف السجل غير موجود: ' . $logFile];
    }

    $content = file_get_contents($logPath);
    $logLines = explode("\n", $content);
    $logLines = array_filter($logLines);

    // تطبيق الفلتر
    if (!empty($filter)) {
        $logLines = array_filter($logLines, function ($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
    }

    // جلب آخر N سطر
    $logLines = array_slice($logLines, -$lines);

    return $logLines;
}

/**
 * تحليل سطر السجل
 */
function parseLogLine(string $line): array
{
    // تنسيق: [2026-01-09 14:30:25] ERROR: message
    preg_match('/\[(.*?)\]\s+(\w+):\s+(.*)/', $line, $matches);

    if (empty($matches)) {
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'INFO',
            'message' => $line,
            'raw' => $line
        ];
    }

    return [
        'timestamp' => $matches[1] ?? date('Y-m-d H:i:s'),
        'level' => $matches[2] ?? 'INFO',
        'message' => $matches[3] ?? $line,
        'raw' => $line
    ];
}

/**
 * الحصول على لون مستوى الخطأ
 */
function getLevelColor(string $level): string
{
    $colors = [
        'EMERGENCY' => '#dc3545',
        'ALERT' => '#fd7e14',
        'CRITICAL' => '#dc3545',
        'ERROR' => '#dc3545',
        'WARNING' => '#ffc107',
        'NOTICE' => '#17a2b8',
        'INFO' => '#28a745',
        'DEBUG' => '#6c757d'
    ];

    return $colors[strtoupper($level)] ?? '#6c757d';
}

/**
 * الحصول على قائمة ملفات السجلات
 */
function getLogFiles(): array
{
    $logPath = __DIR__ . '/../storage/logs/';
    $files = glob($logPath . '*.log');
    $result = [];

    foreach ($files as $file) {
        $filename = basename($file);
        $size = filesize($file);
        $modified = filemtime($file);

        $result[] = [
            'name' => $filename,
            'size' => $size,
            'size_human' => formatSize($size),
            'modified' => date('Y-m-d H:i:s', $modified),
            'lines' => count(file($file))
        ];
    }

    return $result;
}

/**
 * تنسيق الحجم
 */
function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * الحصول على إحصائيات الأخطاء
 */
function getErrorStats(string $logFile): array
{
    $logPath = __DIR__ . '/../storage/logs/' . $logFile;
    if (!file_exists($logPath)) {
        return ['total' => 0, 'by_level' => []];
    }

    $content = file_get_contents($logPath);
    $lines = explode("\n", $content);
    $lines = array_filter($lines);

    $stats = [
        'total' => count($lines),
        'by_level' => [],
        'recent' => []
    ];

    foreach ($lines as $line) {
        if (preg_match('/\]\s+(\w+):/', $line, $matches)) {
            $level = $matches[1];
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
        }
    }

    // آخر 5 أخطاء
    $stats['recent'] = array_slice($lines, -5);

    return $stats;
}

// ============================================
// 5. الحصول على البيانات
// ============================================
$logFiles = getLogFiles();
$currentFile = $logFile;
$logLines = readLogFile($currentFile, $lines, $filter);
$errorStats = getErrorStats($currentFile);

// ============================================
// 6. عرض الصفحة
// ============================================
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم الأخطاء - Tourfecto</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
            color: #1a1a1a;
            padding: 20px;
            direction: rtl;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 800;
        }
        
        .header h1 i {
            color: #ff6b6b;
            margin-left: 10px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .header-actions .badge {
            background: rgba(255,255,255,0.15);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
        }
        
        /* ============================================
           Sidebar
           ============================================ */
        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        
        .sidebar-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #1a1a2e;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }
        
        .sidebar-title i {
            margin-left: 8px;
            color: #0077be;
        }
        
        .file-list {
            list-style: none;
        }
        
        .file-list li {
            margin-bottom: 4px;
        }
        
        .file-list a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            color: #495057;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .file-list a:hover {
            background: #f8f9fa;
        }
        
        .file-list a.active {
            background: #0077be;
            color: white;
        }
        
        .file-list a .file-size {
            font-size: 12px;
            color: #868e96;
        }
        
        .file-list a.active .file-size {
            color: rgba(255,255,255,0.7);
        }
        
        .file-list .file-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-list .file-info i {
            width: 18px;
            text-align: center;
        }
        
        /* ============================================
           Main Content
           ============================================ */
        .main-content {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .toolbar .controls {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .toolbar .controls input,
        .toolbar .controls select {
            padding: 8px 14px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            background: white;
        }
        
        .toolbar .controls input:focus,
        .toolbar .controls select:focus {
            outline: none;
            border-color: #0077be;
            box-shadow: 0 0 0 3px rgba(0,119,190,0.15);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-weight: 500;
        }
        
        .btn-primary {
            background: #0077be;
            color: white;
        }
        
        .btn-primary:hover {
            background: #005a8c;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #1a1a2e;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-sm {
            padding: 5px 12px;
            font-size: 13px;
        }
        
        /* ============================================
           Stats Cards
           ============================================ */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        
        .stat-card .number {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
        }
        
        .stat-card .label {
            font-size: 13px;
            color: #6c757d;
            margin-top: 4px;
        }
        
        .stat-card .level-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-left: 6px;
        }
        
        /* ============================================
           Log Entries
           ============================================ */
        .log-entries {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: #1a1a2e;
            color: #e0e0e0;
            padding: 15px;
            border-radius: 10px;
            max-height: 600px;
            overflow-y: auto;
            direction: ltr;
            text-align: left;
            line-height: 1.8;
        }
        
        .log-entries::-webkit-scrollbar {
            width: 8px;
        }
        
        .log-entries::-webkit-scrollbar-track {
            background: #2a2a3e;
            border-radius: 4px;
        }
        
        .log-entries::-webkit-scrollbar-thumb {
            background: #0077be;
            border-radius: 4px;
        }
        
        .log-entry {
            padding: 2px 0;
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }
        
        .log-entry .timestamp {
            color: #6c757d;
            margin-right: 10px;
        }
        
        .log-entry .level {
            font-weight: 700;
            padding: 0 8px;
            border-radius: 4px;
            margin: 0 8px;
        }
        
        .log-entry .level-ERROR {
            color: #ff6b6b;
        }
        
        .log-entry .level-WARNING {
            color: #ffd93d;
        }
        
        .log-entry .level-INFO {
            color: #6bcb77;
        }
        
        .log-entry .level-DEBUG {
            color: #6c757d;
        }
        
        .log-entry .level-CRITICAL {
            color: #ff4757;
        }
        
        .log-entry .message {
            color: #f8f9fa;
        }
        
        .log-empty {
            text-align: center;
            color: #6c757d;
            padding: 40px;
        }
        
        .log-empty i {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        /* ============================================
           Recent Errors
           ============================================ */
        .recent-errors {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }
        
        .recent-errors h4 {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .recent-error {
            background: #fff3f3;
            border-right: 3px solid #dc3545;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 5px;
            font-size: 13px;
            font-family: 'Courier New', monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* ============================================
           Flash Messages
           ============================================ */
        .flash-message {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .flash-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .flash-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* ============================================
           Responsive
           ============================================ */
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                position: static;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .toolbar .controls {
                flex-wrap: wrap;
            }
            
            .log-entries {
                font-size: 12px;
                max-height: 400px;
            }
        }
        
        @media print {
            .sidebar,
            .toolbar .controls,
            .btn {
                display: none;
            }
            
            .header {
                background: #1a1a2e !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- ============================================
    Header
    ============================================ -->
    <div class="header">
        <h1>
            <i class="fas fa-bug"></i>
            لوحة تحكم الأخطاء
        </h1>
        <div class="header-actions">
            <span class="badge">
                <i class="fas fa-file"></i>
                <?php echo htmlspecialchars($currentFile); ?>
            </span>
            <span class="badge">
                <i class="fas fa-list"></i>
                <?php echo count($logLines); ?> سطر
            </span>
            <span class="badge">
                <i class="fas fa-clock"></i>
                <?php echo date('Y-m-d H:i:s'); ?>
            </span>
        </div>
    </div>
    
    <!-- Flash Messages -->
    <?php if (isset($_GET['cleared'])): ?>
    <div class="flash-message flash-success">
        <i class="fas fa-check-circle"></i>
        تم تنظيف ملف السجل بنجاح.
    </div>
    <?php endif; ?>
    
    <!-- ============================================
    Grid
    ============================================ -->
    <div class="grid">
        
        <!-- ============================================
        Sidebar
        ============================================ -->
        <div class="sidebar">
            <div class="sidebar-title">
                <i class="fas fa-folder-open"></i>
                ملفات السجلات
            </div>
            
            <ul class="file-list">
                <?php foreach ($logFiles as $file): ?>
                <li>
                    <a href="?file=<?php echo urlencode($file['name']); ?>&lines=<?php echo $lines; ?>" 
                       class="<?php echo $file['name'] === $currentFile ? 'active' : ''; ?>">
                        <span class="file-info">
                            <i class="fas fa-file-alt"></i>
                            <?php echo htmlspecialchars($file['name']); ?>
                        </span>
                        <span class="file-size">
                            <?php echo $file['size_human']; ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <hr style="margin: 15px 0; border-color: #e9ecef;">
            
            <div style="font-size: 13px; color: #6c757d;">
                <div><i class="fas fa-database"></i> إجمالي الأخطاء: <?php echo $errorStats['total'] ?? 0; ?></div>
                <?php if (!empty($errorStats['by_level'])): ?>
                <div style="margin-top: 8px;">
                    <?php foreach ($errorStats['by_level'] as $level => $count): ?>
                    <div style="display: flex; justify-content: space-between; padding: 2px 0;">
                        <span>
                            <span class="level-indicator" style="background: <?php echo getLevelColor($level); ?>;"></span>
                            <?php echo $level; ?>
                        </span>
                        <span><?php echo $count; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ============================================
        Main Content
        ============================================ -->
        <div class="main-content">
            
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="controls">
                    <input type="text" id="filterInput" placeholder="🔍 فلتر..." 
                           value="<?php echo htmlspecialchars($filter); ?>"
                           onkeyup="applyFilter()">
                    
                    <select id="linesSelect" onchange="applyFilter()">
                        <option value="50" <?php echo $lines == 50 ? 'selected' : ''; ?>>50 سطر</option>
                        <option value="100" <?php echo $lines == 100 ? 'selected' : ''; ?>>100 سطر</option>
                        <option value="200" <?php echo $lines == 200 ? 'selected' : ''; ?>>200 سطر</option>
                        <option value="500" <?php echo $lines == 500 ? 'selected' : ''; ?>>500 سطر</option>
                        <option value="1000" <?php echo $lines == 1000 ? 'selected' : ''; ?>>1000 سطر</option>
                        <option value="0" <?php echo $lines == 0 ? 'selected' : ''; ?>>الكل</option>
                    </select>
                </div>
                
                <div class="controls">
                    <a href="?file=<?php echo urlencode($currentFile); ?>&action=download" 
                       class="btn btn-success btn-sm">
                        <i class="fas fa-download"></i> تحميل
                    </a>
                    <a href="?file=<?php echo urlencode($currentFile); ?>&action=clear&confirm=yes" 
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('هل أنت متأكد من رغبتك في تنظيف هذا الملف؟');">
                        <i class="fas fa-trash"></i> تنظيف
                    </a>
                    <a href="?file=<?php echo urlencode($currentFile); ?>&lines=<?php echo $lines; ?>" 
                       class="btn btn-secondary btn-sm">
                        <i class="fas fa-sync"></i> تحديث
                    </a>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="number"><?php echo count($logLines); ?></div>
                    <div class="label">إجمالي السطور</div>
                </div>
                <?php
                $levelCounts = [];
foreach ($logLines as $line) {
    if (preg_match('/\]\s+(\w+):/', $line, $m)) {
        $levelCounts[$m[1]] = ($levelCounts[$m[1]] ?? 0) + 1;
    }
}
foreach ($levelCounts as $level => $count):
    ?>
                <div class="stat-card">
                    <div class="number" style="color: <?php echo getLevelColor($level); ?>;">
                        <span class="level-indicator" style="background: <?php echo getLevelColor($level); ?>; display: inline-block;"></span>
                        <?php echo $count; ?>
                    </div>
                    <div class="label"><?php echo $level; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Log Entries -->
            <div class="log-entries" id="logEntries">
                <?php if (empty($logLines)): ?>
                <div class="log-empty">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                    <div>✨ لا توجد أخطاء لعرضها</div>
                    <div style="font-size: 12px; margin-top: 5px;">النظام يعمل بشكل طبيعي</div>
                </div>
                <?php else: ?>
                <?php foreach ($logLines as $line): ?>
                <?php $parsed = parseLogLine($line); ?>
                <div class="log-entry">
                    <span class="timestamp"><?php echo htmlspecialchars($parsed['timestamp']); ?></span>
                    <span class="level level-<?php echo htmlspecialchars($parsed['level']); ?>">
                        [<?php echo htmlspecialchars($parsed['level']); ?>]
                    </span>
                    <span class="message"><?php echo htmlspecialchars($parsed['message']); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Recent Errors -->
            <?php if (!empty($errorStats['recent'])): ?>
            <div class="recent-errors">
                <h4><i class="fas fa-clock"></i> آخر 5 أخطاء</h4>
                <?php foreach ($errorStats['recent'] as $recent): ?>
                <div class="recent-error">
                    <?php echo htmlspecialchars($recent); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<!-- ============================================
JavaScript
============================================ -->
<script>
function applyFilter() {
    const filter = document.getElementById('filterInput').value;
    const lines = document.getElementById('linesSelect').value;
    const currentFile = '<?php echo urlencode($currentFile); ?>';
    window.location.href = '?file=' + currentFile + '&lines=' + lines + '&filter=' + encodeURIComponent(filter);
}

// Auto-refresh (اختياري)
// setInterval(function() {
//     window.location.reload();
// }, 30000);
</script>

</body>
</html>