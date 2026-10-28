<?php
/**
 * Tourfecto production preflight.
 * Usage: php tools/production_preflight.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
define('ROOT_PATH', $root);
define('APP_PATH', $root . '/app');
define('TOURFECTO_STORAGE', $root . '/storage');

require_once APP_PATH . '/Config/runtime_requirements.php';

$checks = [];
$missing = tourfecto_missing_runtime_extensions();
$checks['php_version'] = [PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=')];
$checks['extensions'] = [implode(', ', array_keys($missing)) ?: 'all required extensions present', empty($missing)];
$checks['vendor_autoload'] = [$root . '/vendor/autoload.php', is_file($root . '/vendor/autoload.php')];
$checks['env_file'] = [$root . '/.env', is_file($root . '/.env')];
$checks['storage_logs_writable'] = [TOURFECTO_STORAGE . '/logs', is_dir(TOURFECTO_STORAGE . '/logs') && is_writable(TOURFECTO_STORAGE . '/logs')];
$checks['storage_sessions_writable'] = [TOURFECTO_STORAGE . '/sessions', is_dir(TOURFECTO_STORAGE . '/sessions') && is_writable(TOURFECTO_STORAGE . '/sessions')];
$checks['public_html_index'] = [$root . '/public_html/index.php', is_file($root . '/public_html/index.php')];
$checks['migrations_dir'] = [$root . '/database/migrations', is_dir($root . '/database/migrations')];

$diagnostics = ['deep_debug.php','diagnose_onboarding.php','show_columns.php','system_check.php','_internal_error_dashboard_9f21x.php'];
$exposed = [];
foreach ($diagnostics as $file) {
    if (is_file($root . '/public_html/' . $file)) $exposed[] = $file;
}
$checks['diagnostic_files'] = [$exposed ? implode(', ', $exposed) : 'none', !$exposed];

$failed=0;
foreach ($checks as $name => [$value,$ok]) {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . str_pad($name, 28) . ' ' . $value . PHP_EOL;
    if (!$ok) $failed++;
}

// DB check is intentionally optional; it requires the production .env credentials and pdo_mysql.
if (!$missing && is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line=trim($line); if ($line==='' || $line[0]==='#' || !str_contains($line,'=')) continue;
        [$k,$v]=explode('=', $line, 2); $_ENV[$k]=trim($v, " \t\n\r\0\x0B\"'");
    }
    $dbName=$_ENV['DB_NAME'] ?? ''; $dbUser=$_ENV['DB_USER'] ?? ''; $dbPass=$_ENV['DB_PASS'] ?? '';
    $dbHost=$_ENV['DB_HOST'] ?? '127.0.0.1'; $dbPort=$_ENV['DB_PORT'] ?? '3306';
    if ($dbName!=='' && $dbUser!=='') {
        try {
            $pdo=new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT 1');
            echo '[OK]   ' . str_pad('database_connection',28) . ' connected' . PHP_EOL;
        } catch (Throwable $e) {
            echo '[FAIL] ' . str_pad('database_connection',28) . ' ' . $e->getMessage() . PHP_EOL;
            $failed++;
        }
    } else {
        echo '[WARN] ' . str_pad('database_connection',28) . ' DB_NAME/DB_USER missing in .env' . PHP_EOL;
    }
} else {
    echo '[WARN] ' . str_pad('database_connection',28) . ' skipped (missing pdo_mysql or .env)' . PHP_EOL;
}

exit($failed ? 1 : 0);
