<?php

use PHPUnit\Framework\TestCase;

/**
 * Double click / retry after a drop must not create a second cycle (or a second charge):
 * the DB rejects a repeated request key, and a site operation held by another connection blocks a second one.
 */
final class SeoCycleDuplicateGuardsTest extends TestCase
{
    private ?Database $db = null;
    private const WID = 999931;
    private const UID = 999931;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            require_once $app . '/Services/Execution/ExternalExecution.php';
            $this->db = Database::getInstance();
            if (!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'seo_expert_runs'")) {
                $this->markTestSkipped('seo_expert_runs not present');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query('DELETE FROM seo_expert_runs WHERE website_id = ?', [self::WID]);
    }

    private function insert(string $key): void
    {
        $this->db->query("INSERT INTO seo_expert_runs (website_id, user_id, request_key, usage_token, status, snapshot_json, created_at, updated_at) VALUES (?, ?, ?, ?, 'queued', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [self::WID, self::UID, $key, 'tok' . bin2hex(random_bytes(8))]);
    }

    public function testTheSameRequestKeyCannotCreateASecondCycle(): void
    {
        $this->insert('guide_double_click_0001');
        $this->expectException(Throwable::class);
        $this->insert('guide_double_click_0001');
    }

    public function testRequestKeyIsPerUserSoAnotherAccountIsUnaffected(): void
    {
        $this->insert('shared_key_name_000001');
        $this->db->query("INSERT INTO seo_expert_runs (website_id, user_id, request_key, usage_token, status, snapshot_json, created_at, updated_at) VALUES (?, ?, 'shared_key_name_000001', ?, 'queued', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [self::WID, self::UID + 1, 'tok' . bin2hex(random_bytes(8))]);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) AS n FROM seo_expert_runs WHERE website_id = ?', [self::WID])[0]['n']);
    }

    public function testASiteOperationHeldByAnotherConnectionBlocksASecondOne(): void
    {
        $key = 'seo-expert-site:' . self::WID;
        $cfg = $this->db->getConnection();
        $dsn = null;
        // second connection = the second request of a double click (a different PHP process)
        $other = new PDO(sprintf('mysql:host=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_NAME') ?: 'tourfecto_test'), getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '');
        $name = 'tf_exec_' . substr(hash('sha256', $key), 0, 56);
        $this->assertTrue(ExternalExecution::lock($key), 'first request takes the site lock');
        try {
            $s = $other->prepare('SELECT GET_LOCK(?, 0)');
            $s->execute([$name]);
            $this->assertSame(0, (int) $s->fetchColumn(), 'the second request cannot enter while the first runs');
        } finally {
            ExternalExecution::unlock($key);
        }
        $s = $other->prepare('SELECT GET_LOCK(?, 0)');
        $s->execute([$name]);
        $this->assertSame(1, (int) $s->fetchColumn(), 'it can proceed once the first finished');
        $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}
