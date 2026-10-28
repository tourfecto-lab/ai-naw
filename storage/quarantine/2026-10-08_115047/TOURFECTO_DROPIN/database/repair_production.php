<?php
/**
 * Tourfecto Production Repair / Migration Runner
 *
 * Applies every SQL migration in database/migrations in lexical order,
 * records completed migrations, tolerates already-applied duplicate DDL,
 * and verifies that all tables declared by CREATE TABLE migrations exist.
 *
 * Usage:
 *   php database/repair_production.php --dry-run
 *   php database/repair_production.php --apply
 *
 * IMPORTANT: take a database backup before --apply.
 */

declare(strict_types=1);

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "ERROR: PHP extension pdo_mysql is required. Enable it before applying production migrations.\n");
    exit(2);
}

$root = dirname(__DIR__);
$envFile = $root . '/.env';
$migrationsDir = $root . '/database/migrations';

if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: .env not found. Copy .env.example to .env and configure DB_* first.\n");
    exit(2);
}
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "ERROR: database/migrations not found.\n");
    exit(2);
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $v = trim($v);
    if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
        $v = substr($v, 1, -1);
    }
    $_ENV[$k] = $v;
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

if ($db === '' || $user === '') {
    fwrite(STDERR, "ERROR: DB_NAME/DB_USER are missing in .env.\n");
    exit(2);
}

$dryRun = in_array('--dry-run', $argv, true);
$apply = in_array('--apply', $argv, true);
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Usage: php database/repair_production.php --dry-run | --apply\n");
    exit(2);
}

function splitSqlStatements(string $sql): array {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($lineComment) {
            $buf .= $c;
            if ($c === "\n") $lineComment = false;
            continue;
        }
        if ($blockComment) {
            $buf .= $c;
            if ($c === '*' && $n === '/') {
                $buf .= $n; $i++; $blockComment = false;
            }
            continue;
        }
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $sql[++$i];
                continue;
            }
            if ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buf .= $sql[++$i];
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($c === '#' || ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))) {
            $buf .= $c;
            if ($c === '-') { $buf .= $n; $i++; }
            $lineComment = true;
            continue;
        }
        if ($c === '/' && $n === '*') {
            $buf .= $c . $n; $i++; $blockComment = true; continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c; $buf .= $c; continue;
        }
        if ($c === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') $out[] = $stmt;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $stmt = trim($buf);
    if ($stmt !== '') $out[] = $stmt;
    return $out;
}


if ($dryRun) {
    $files = glob($migrationsDir . '/*.sql') ?: [];
    $files = array_values(array_filter($files, static fn(string $f): bool => basename($f)[0] !== '_'));
    sort($files, SORT_STRING);
    echo "DRY RUN: database connection is not required.
";
    echo "Migration files: " . count($files) . "
";
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        $count = $sql === false ? 0 : count(splitSqlStatements($sql));
        echo "PLAN " . basename($file) . " (" . $count . " statements)\n";
    }
    echo "\nNo database changes were made.
";
    exit(0);
}

function isIgnorableDuplicate(PDOException $e): bool {
    $code = (int)$e->errorInfo[1];
    return in_array($code, [1050, 1060, 1061, 1826, 1831], true);
}

function tableNamesFromMigration(string $sql): array {
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $m);
    return array_values(array_unique($m[1] ?? []));
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset={$charset}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Database connection failed: " . $e->getMessage() . "\n");
    exit(3);
}

$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
echo "MySQL/MariaDB: {$version}\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `checksum` CHAR(64) NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$done = [];
foreach ($pdo->query('SELECT migration, checksum FROM schema_migrations') as $row) {
    $done[$row['migration']] = $row['checksum'];
}

$files = glob($migrationsDir . '/*.sql') ?: [];
$files = array_values(array_filter($files, static fn(string $f): bool => basename($f)[0] !== '_'));
sort($files, SORT_STRING);
$expectedTables = [];
$pending = 0;
$applied = 0;
$skipped = 0;
$failed = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) { $failed++; echo "FAIL READ {$name}\n"; continue; }
    $checksum = hash('sha256', $sql);
    foreach (tableNamesFromMigration($sql) as $t) $expectedTables[$t] = true;

    if (isset($done[$name]) && $done[$name] === $checksum) {
        $skipped++;
        continue;
    }
    $pending++;
    $statements = splitSqlStatements($sql);
    echo ($dryRun ? 'PLAN ' : 'RUN  ') . $name . ' (' . count($statements) . " statements)\n";
    if ($dryRun) continue;

    $migrationFailed = false;
    foreach ($statements as $idx => $statement) {
        $trim = ltrim($statement);
        if ($trim === '' || str_starts_with($trim, '--')) continue;
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (isIgnorableDuplicate($e)) {
                echo "  WARN duplicate/already-applied statement #" . ($idx + 1) . ": " . ($e->errorInfo[2] ?? $e->getMessage()) . "\n";
                continue;
            }
            $migrationFailed = true;
            $failed++;
            echo "  FAIL statement #" . ($idx + 1) . ": " . ($e->errorInfo[2] ?? $e->getMessage()) . "\n";
            break;
        }
    }
    if ($migrationFailed) {
        echo "  STOPPED: {$name}\n";
        continue;
    }
    $st = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), applied_at=CURRENT_TIMESTAMP');
    $st->execute([$name, $checksum]);
    $applied++;
}

$missing = [];
$check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach (array_keys($expectedTables) as $table) {
    $check->execute([$table]);
    if ((int)$check->fetchColumn() === 0) $missing[] = $table;
}

echo "\nSummary\n";
echo "  Migration files: " . count($files) . "\n";
echo "  Already recorded: {$skipped}\n";
echo "  Pending/processed: {$pending}\n";
echo "  Applied: {$applied}\n";
echo "  Failed: {$failed}\n";
echo "  Expected tables: " . count($expectedTables) . "\n";
echo "  Missing tables after run: " . count($missing) . "\n";
if ($missing) echo "    - " . implode("\n    - ", $missing) . "\n";

exit(($failed === 0 && !$missing) ? 0 : 4);
