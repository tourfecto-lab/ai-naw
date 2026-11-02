<?php

/**
 * The customer is told once a day when the connector on their site stopped pulling the rules while SEO rules are waiting to be applied.
 */

use PHPUnit\Framework\TestCase;

final class EdgeConnectionWatchTest extends TestCase
{
    private const USER = 999601;
    private const SITE = 999602;
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM edge_connections LIMIT 1');
            $this->pdo->query('SELECT 1 FROM seo_expert_tasks LIMIT 1');
            $this->pdo->query('SELECT 1 FROM notifications LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('tables not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::USER . ", 'edge-watch@tourfecto.test', 'x', 'W', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name) VALUES (" . self::SITE . ", " . self::USER . ", 'https://edge-watch.test', 'W')");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM notifications WHERE user_id = ' . self::USER);
        $this->pdo->exec('DELETE FROM seo_expert_tasks WHERE website_id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM seo_expert_runs WHERE website_id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM edge_connections WHERE website_id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM websites WHERE id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM users WHERE id = ' . self::USER);
    }

    private function seen(string $when): void
    {
        $this->pdo->exec("INSERT INTO edge_connections (website_id, last_seen_at, hits) VALUES (" . self::SITE . ", '$when', 1)");
    }

    private function insertRow(string $table, array $data): int
    {
        $info = $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        $filler = [];
        foreach ($info as $c) {
            if ($c['Null'] === 'NO' && $c['Default'] === null && stripos((string) $c['Extra'], 'auto_increment') === false) {
                $filler[$c['Field']] = preg_match('/int|decimal|float|double|tinyint/i', $c['Type']) ? 0 : (preg_match('/date|time/i', $c['Type']) ? date('Y-m-d H:i:s') : '');
            }
        }
        $row = array_intersect_key($data + $filler, array_flip(array_column($info, 'Field')));
        $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    private function rule(): void
    {
        $runId = $this->insertRow('seo_expert_runs', ['website_id' => self::SITE, 'user_id' => self::USER, 'status' => 'completed', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->insertRow('seo_expert_tasks', ['website_id' => self::SITE, 'user_id' => self::USER, 'run_id' => $runId, 'kind' => 'metadata', 'field_name' => 'title', 'page_url' => 'https://edge-watch.test/', 'page_path' => '/', 'status' => 'active', 'is_active' => 1, 'title' => 't', 'expected_value' => 'x', 'before_json' => '{}', 'evidence_json' => '{}', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function notes(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::USER . " AND type = 'edge_stale'")->fetchColumn();
    }

    public function testStaleConnectorWithRulesWaitingAlertsOnceADay(): void
    {
        $now = strtotime('2026-11-02 12:00:00');
        $this->seen('2026-10-30 20:00:00');
        $this->rule();
        $w = new EdgeConnectionWatch();
        $this->assertSame(['checked' => 1, 'alerted' => 1], $w->run($now));
        $this->assertSame(1, $this->notes());
        $this->assertSame(['checked' => 1, 'alerted' => 0], $w->run($now + 2 * 3600), 'no second alert within 24 hours');
        $this->assertSame(1, $this->notes());
        $n = $this->pdo->query("SELECT body FROM notifications WHERE user_id = " . self::USER)->fetchColumn();
        $this->assertStringContainsString('edge-watch.test', (string) $n);
    }

    public function testFreshConnectorAndSitesWithoutRulesAreNotAlerted(): void
    {
        $now = strtotime('2026-11-02 12:00:00');
        $this->seen('2026-11-02 11:00:00');
        $this->rule();
        $this->assertSame(['checked' => 1, 'alerted' => 0], (new EdgeConnectionWatch())->run($now));
        $this->pdo->exec('DELETE FROM seo_expert_tasks WHERE website_id = ' . self::SITE);
        $this->pdo->exec("UPDATE edge_connections SET last_seen_at = '2026-10-01 00:00:00' WHERE website_id = " . self::SITE);
        $this->assertSame(['checked' => 0, 'alerted' => 0], (new EdgeConnectionWatch())->run($now), 'nothing waiting, nothing to warn about');
        $this->assertSame(0, $this->notes());
    }
}
