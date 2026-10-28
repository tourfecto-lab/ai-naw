<?php

/**
 * أداة تشخيص السكيمة الحقيقية — Phase 0
 * ====================================
 * الغرض: سحب البنية الفعلية لكل جداول قاعدة البيانات الحية مباشرة من
 * INFORMATION_SCHEMA، دفعة واحدة، بدلًا من الاعتماد على ملفات
 * database/migrations/*.sql التي أثبت الكود نفسه (User.php, Website.php,
 * Subscription.php) أنها غير مطابقة للواقع في أكثر من موضع.
 *
 * الاستخدام (من CLI على الاستضافة، أو أي بيئة عندها اتصال بنفس القاعدة):
 *   php database/audit_real_schema.php
 *
 * المخرجات:
 *   - يطبع في الشاشة تقريرًا مقروءًا لكل جدول (أعمدة + أنواع + مفاتيح).
 *   - يكتب أيضًا storage/database/real_schema_snapshot.json بنفس البيانات
 *     بصيغة JSON، لاستخدامها لاحقًا في مقارنة آلية مع ملفات الـ migrations.
 *
 * هذا السكريبت للقراءة فقط (SELECT من INFORMATION_SCHEMA) — لا يعدّل أي
 * بيانات أو جدول إطلاقًا. آمن للتشغيل على قاعدة بيانات الإنتاج مباشرة.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

// تحميل .env بنفس طريقة index.php الحالية
if (class_exists('Dotenv\\Dotenv') && file_exists($root . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($root);
    $dotenv->load();
}

function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false ? $default : $value;
}

$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', 3306);
$name = env('DB_NAME');
$user = env('DB_USER');
$pass = env('DB_PASS');

if (!$name || !$user) {
    fwrite(STDERR, "خطأ: DB_NAME/DB_USER غير موجودين في .env — لا يمكن الاتصال.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n");
    exit(1);
}

// كل الجداول في القاعدة الحالية
$tables = $pdo->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

$snapshot = [];

foreach ($tables as $table) {
    $columns = $pdo->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
         ORDER BY ORDINAL_POSITION"
    );
    $columns->execute(['t' => $table]);
    $cols = $columns->fetchAll();

    $fks = $pdo->prepare(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $fks->execute(['t' => $table]);
    $foreignKeys = $fks->fetchAll();

    $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

    $snapshot[$table] = [
        'columns' => $cols,
        'foreign_keys' => $foreignKeys,
        'row_count' => $rowCount,
    ];

    echo "=== {$table} ({$rowCount} صف) ===\n";
    foreach ($cols as $c) {
        $key = $c['COLUMN_KEY'] ? " [{$c['COLUMN_KEY']}]" : '';
        $null = $c['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $c['COLUMN_DEFAULT'] !== null ? " DEFAULT {$c['COLUMN_DEFAULT']}" : '';
        echo "  - {$c['COLUMN_NAME']}: {$c['COLUMN_TYPE']} {$null}{$default}{$key}\n";
    }
    foreach ($foreignKeys as $fk) {
        echo "  FK: {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
    }
    echo "\n";
}

$outPath = $root . '/storage/database/real_schema_snapshot.json';
@mkdir(dirname($outPath), 0755, true);
file_put_contents($outPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "تم حفظ نسخة JSON كاملة في: storage/database/real_schema_snapshot.json\n";
echo "إجمالي الجداول المفحوصة: " . count($tables) . "\n";
