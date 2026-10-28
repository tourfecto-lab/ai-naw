<?php

/**
 * Tourfecto - إنشاء حساب أدمن (أو ترقية حساب موجود) من سطر الأوامر
 *
 * الاستخدام:
 *   php tools/create_admin.php admin@yourdomain.com
 *   php tools/create_admin.php admin@yourdomain.com --role=admin
 *
 * هيطلب كلمة المرور مرتين (مش بتظهر وانت بتكتب). لو الإيميل موجود بيترقّى
 * لـ super_admin (أو الدور اللي تحدده) وبتتغير كلمة مروره، ولو مش موجود
 * بيتعمل حساب جديد. بديل آمن لـ database/seeds/users_seed.sql اللي فيه
 * كلمة مرور معروفة ("password") - ماتستخدمش الـ seed ده على سيرفر حقيقي.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$email = strtolower(trim((string) ($argv[1] ?? '')));
$role = 'super_admin';
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--role=')) {
        $role = substr($arg, 7);
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['super_admin', 'admin'], true)) {
    fwrite(STDERR, "Usage: php tools/create_admin.php <email> [--role=super_admin|admin]\n");
    exit(2);
}

// قراءة .env بنفس طريقة database/repair_production.php (من غير الاعتماد على vendor/)
$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
if (empty($env['DB_NAME']) || empty($env['DB_USER'])) {
    fwrite(STDERR, "ERROR: .env is missing or DB_NAME/DB_USER are empty.\n");
    exit(2);
}

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    $tty = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $value;
}

$password = prompt_hidden('Password (min 10 chars): ');
if (strlen($password) < 10) {
    fwrite(STDERR, "ERROR: password must be at least 10 characters.\n");
    exit(2);
}
if (prompt_hidden('Repeat password: ') !== $password) {
    fwrite(STDERR, "ERROR: passwords do not match.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', $env['DB_PORT'] ?? '3306', $env['DB_NAME']),
        $env['DB_USER'],
        $env['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: database connection failed: ' . $e->getMessage() . "\n");
    exit(3);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$existing->execute([$email]);
$id = $existing->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE users SET role = ?, password_hash = ?, status = 'active', is_active = 1, email_verified = 1 WHERE id = ?")
        ->execute([$role, $hash, $id]);
    echo "OK: existing user #{$id} ({$email}) is now {$role} with the new password.\n";
} else {
    $pdo->prepare("INSERT INTO users (company_name, email, password_hash, role, status, is_active, email_verified, created_at)
                   VALUES ('Tourfecto Admin', ?, ?, ?, 'active', 1, 1, NOW())")
        ->execute([$email, $hash, $role]);
    echo 'OK: created ' . $role . ' #' . $pdo->lastInsertId() . " ({$email}). Log in at /login then open /admin.\n";
}
