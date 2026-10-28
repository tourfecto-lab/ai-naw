<?php
/** Standalone integration regression test; uses session-local TEMPORARY tables only.
 * TEST_DB_DSN, TEST_DB_USER, TEST_DB_PASS must point to a disposable MySQL test database.
 * Run separately from PHPUnit (the Database adapter below isolates application bootstrapping).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) { fwrite(STDERR, "TEST_DB_DSN is required (disposable MySQL database).\n"); exit(1); }
class OutreachHealthPDO extends PDO
{
    public bool $readOnly = false;
    public array $healthStatements = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->readOnly) {
            if (!preg_match('/^SELECT\b/i', ltrim($query))) { throw new RuntimeException('Diagnostic attempted a write'); }
            $this->healthStatements[] = $query;
        }
        return parent::prepare($query, $options);
    }
}
class Database
{
    public static self $instance;
    public array $queries = [];
    public function __construct(public PDO $pdo) { self::$instance = $this; }
    public static function getInstance(): self { return self::$instance; }
    public function getConnection(): PDO { return $this->pdo; }
    public function query(string $sql, array $args = []): array
    {
        if (!preg_match('/^SELECT\b/i', ltrim($sql))) { throw new RuntimeException('Health check attempted a write'); }
        $this->queries[] = $sql;
        $s = $this->pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
$root = dirname(__DIR__);
define('ENCRYPTION_METHOD', 'openssl');
define('ENCRYPTION_KEY', base64_encode(random_bytes(32)));
define('MAIL_HOST', 'smtp.example.test');
define('MAIL_USERNAME', 'sender@example.test');
define('MAIL_PASSWORD', 'test-only-password');
require $root . '/app/Core/Encryption.php';
require $root . '/app/Services/System/SystemSettingsService.php';
require $root . '/app/Services/Mailer.php';
require $root . '/app/Services/Admin/PlatformHealthService.php';
$pdo = new OutreachHealthPDO($dsn, getenv('TEST_DB_USER'), getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = new Database($pdo);
$pdo->exec('CREATE TEMPORARY TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, is_secret TINYINT DEFAULT 0)');
$pdo->exec("CREATE TEMPORARY TABLE platform_leads (id INT PRIMARY KEY AUTO_INCREMENT, status VARCHAR(30), step INT DEFAULT 0, email VARCHAR(100), track VARCHAR(40), approved INT DEFAULT 0, scanned_at DATETIME, next_send_at DATETIME, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TEMPORARY TABLE platform_lead_messages (status VARCHAR(20), sent_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
function setting(string $key, string $value): void
{
    global $pdo;
    $s = $pdo->prepare('REPLACE INTO system_settings (setting_key,setting_value) VALUES (?,?)');
    $s->execute(['outreach_' . $key, $value]);
}
function check(bool $ok, string $label): void
{
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $passed++; echo "PASS: $label\n";
}
$passed = 0;
$cron = ['checks' => [['status' => 'ok']], 'jobs' => [['key' => 'platform_outreach', 'status' => 'ok', 'message' => 'success']]];
$health = new PlatformHealthService($db);
function report(?array $override = null): array
{
    global $health, $cron, $pdo;
    $pdo->readOnly = true;
    try {
        return array_column($health->outreach($override ?? $cron)['checks'], null, 'key');
    } finally {
        $pdo->readOnly = false;
    }
}
setting('enabled', '1'); setting('hour_from', '0'); setting('hour_to', '24'); setting('skip_days', '');
setting('from_email', 'sender@example.test'); setting('signup_url', 'https://example.test/register');
$r = report();
check($r['outreach_cron']['status'] === 'ok', 'successful scheduler job distinguished from successful delivery');
check($r['outreach_window']['status'] === 'ok', 'valid sending window');
check($r['outreach_smtp']['status'] === 'ok', 'global SMTP fallback');
check($r['outreach_leads']['status'] === 'warn', 'zero eligible leads is not called ready');
$pdo->exec("INSERT INTO platform_leads(status,email,track,approved,scanned_at) VALUES ('new','a@example.test','seo',0,NOW()),('new','b@example.test',NULL,0,NULL),('no_email',NULL,NULL,0,NULL)");
$pdo->exec("INSERT INTO platform_leads(status,email,track,approved,step,next_send_at) VALUES ('contacted','c@example.test','seo',1,1,DATE_ADD(NOW(),INTERVAL 1 DAY))");
$r = report();
check(str_contains($r['outreach_approval']['message'], ': 1.'), 'unapproved diagnosed lead counted separately');
check(str_contains($r['outreach_leads']['message'], 'الآن: 0 '), 'future followups and undiagnosed leads cannot inflate ready count');
check(str_contains($r['outreach_leads']['message'], 'ميعادها مجاش: 1'), 'future followup reported: ' . $r['outreach_leads']['message']);
$pdo->exec('UPDATE platform_leads SET approved=1 WHERE id=1');
$r = report(); check(str_contains($r['outreach_leads']['message'], 'الآن: 1 '), 'approved first contact becomes ready');
$pdo->exec("UPDATE platform_leads SET next_send_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=4");
$r = report(); check(str_contains($r['outreach_leads']['message'], 'الآن: 2 '), 'due followup included');
setting('require_approval', '0'); $pdo->exec('UPDATE platform_leads SET approved=0 WHERE id=1');
check(report()['outreach_leads']['status'] === 'ok', 'approval-disabled selection follows sender policy');
setting('skip_days', (string) (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('w'));
check(report()['outreach_window']['status'] === 'warn', 'excluded weekday waits without claiming cron failure');
setting('skip_days', '0,1,2,3,4,5,6'); check(report()['outreach_window']['status'] === 'fail', 'excluding every weekday detected');
setting('timezone', 'Invalid/Timezone'); check(report()['outreach_window']['status'] === 'fail', 'invalid timezone cannot break whole report');
setting('timezone', 'Africa/Cairo'); setting('hour_from', '17'); setting('hour_to', '9');
check(report()['outreach_window']['status'] === 'fail', 'invalid hour range detected');
setting('smtp_host', 'smtp.custom.test'); setting('smtp_password', 'broken-encrypted-password');
check(report()['outreach_smtp']['status'] === 'fail', 'broken campaign password detected despite global SMTP being configured');
setting('smtp_password', (new Encryption())->encrypt('campaign-test-secret', 'platform-outreach'));
$r = report(); check($r['outreach_smtp']['status'] === 'ok', 'valid encrypted campaign password');
check(!str_contains(json_encode($r), 'campaign-test-secret') && !str_contains(json_encode($r), 'test-only-password'), 'credentials never returned');
setting('enabled', '0'); check(report()['outreach_enabled']['status'] === 'warn', 'disabled campaign identified');
$off = $cron; $off['jobs'][0]['status'] = 'off'; check(report($off)['outreach_cron']['status'] === 'fail', 'scheduler override disabling campaign detected');
check(report(['checks' => [['status' => 'ok']], 'jobs' => []])['outreach_cron']['status'] === 'fail', 'missing scheduled job detected');
$stale = $cron; $stale['checks'][0]['status'] = 'fail'; check(report($stale)['outreach_cron']['status'] === 'fail', 'stale heartbeat overrides old successful job result');
setting('enabled', '1');
$pdo->exec("INSERT INTO platform_lead_messages(status) SELECT 'sent' FROM information_schema.COLUMNS LIMIT 30");
$pdo->exec("INSERT INTO platform_lead_messages(status) VALUES ('failed')");
$pdo->exec("INSERT INTO platform_leads(status) VALUES ('bounced'),('bounced'),('bounced')");
$r = report(); check($r['outreach_limit']['status'] === 'warn', 'warmup daily allowance exhausted');
check($r['outreach_delivery']['status'] === 'warn', 'failed attempts are visible without leaking raw SMTP errors');
check($r['outreach_bounces']['status'] === 'fail', 'bounce protection matches sender threshold');
$pdo->exec('ALTER TABLE platform_leads DROP COLUMN approved');
$r = report(); check($r['outreach_leads']['status'] === 'fail' && isset($r['outreach_limit']), 'missing schema isolated and remaining checks continue');
$pdo->exec('ALTER TABLE system_settings DROP COLUMN setting_value');
check(report()['outreach_settings']['status'] === 'fail', 'unreadable settings cannot be mistaken for defaults');
check(count($pdo->healthStatements) > 0 && count(array_filter($pdo->healthStatements, fn ($sql) => !preg_match('/^SELECT\b/i', $sql))) === 0, 'all diagnostic queries are read-only');
echo "OK: $passed checks\n";
