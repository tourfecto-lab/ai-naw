<?php

/**
 * Tourfecto - عرض أسماء الأعمدة الحقيقية
 * ==========================================================
 * ⚠️ سكريبت تشخيص مؤقت:
 *   1) ارفعه في public_html باسم show_columns.php
 *   2) افتحه من المتصفح
 *   3) ابعتلي سكرين شوت
 *   4) امسحه فورًا بعد كده
 * ==========================================================
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (file_exists($root . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($root);
    $dotenv->load();
}

define('TOURFECTO_ROOT', $root);
define('TOURFECTO_STORAGE', $root . '/storage');
require_once $root . '/app/Config/app.php';
require_once $root . '/app/Config/constants.php';
require_once $root . '/app/Config/database.php';

echo "<!DOCTYPE html><html lang='ar' dir='rtl'><head><meta charset='utf-8'><title>أعمدة الجداول</title>";
echo "<style>body{font-family:monospace;background:#111;color:#eee;padding:20px;} table{border-collapse:collapse;width:100%;margin-bottom:30px;} td,th{padding:6px 10px;border:1px solid #333;text-align:right;} th{background:#222;color:#fbbf24;} h2{color:#93c5fd;}</style>";
echo "</head><body>";

try {
    $db = Database::getInstance();

    $tables = ['websites', 'users', 'subscriptions', 'reviews', 'chat_messages', 'ai_reports'];

    foreach ($tables as $table) {
        echo "<h2>جدول: {$table}</h2>";

        $exists = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
        if (empty($exists)) {
            echo "<p style='color:#f87171;'>❌ الجدول غير موجود أصلاً</p>";
            continue;
        }

        $columns = $db->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$table]
        );

        echo "<table><tr><th>اسم العمود</th><th>النوع</th><th>NULL؟</th><th>الافتراضي</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td><strong>" . htmlspecialchars($col['COLUMN_NAME'], ENT_QUOTES, 'UTF-8') . "</strong></td>";
            echo "<td>" . htmlspecialchars($col['COLUMN_TYPE'], ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . htmlspecialchars($col['IS_NULLABLE'], ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . htmlspecialchars((string) $col['COLUMN_DEFAULT'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
        }
        echo "</table>";
    }
} catch (Throwable $e) {
    echo "<p style='color:#f87171;'>خطأ: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}

echo "<h2 style='color:#f87171;'>⚠️ امسح الملف ده (show_columns.php) دلوقتي</h2>";
echo "</body></html>";
