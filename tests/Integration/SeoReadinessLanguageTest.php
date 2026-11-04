<?php

use PHPUnit\Framework\TestCase;

/** An English dashboard must not receive fixed Arabic text from the readiness check (found on a live English account). */
final class SeoReadinessLanguageTest extends TestCase
{
    private ?Database $db = null;
    private const ID = 999941;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, 'readiness-lang@tourfecto.test', 'x', 'T', NOW()) ON DUPLICATE KEY UPDATE email = email", [self::ID]);
            $this->db->query("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (?, ?, 'https://readiness-lang.test', 'T', 'tourism', 1, NOW()) ON DUPLICATE KEY UPDATE user_id = user_id", [self::ID, self::ID]);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query('DELETE FROM websites WHERE id = ?', [self::ID]);
            $this->db->query('DELETE FROM users WHERE id = ?', [self::ID]);
        }
    }

    public function testEnglishInterfaceGetsNoArabicText(): void
    {
        if (function_exists('current_lang') && current_lang() === 'ar') {
            $this->markTestSkipped('test runs with an English interface');
        }
        $r = (new SeoServiceReadiness($this->db))->inspect(self::ID, self::ID);
        $texts = [$r['note']];
        foreach ($r['checks'] as $c) $texts[] = $c['hint'];
        foreach ($texts as $t) {
            $this->assertSame(0, preg_match('/[\x{0600}-\x{06FF}]/u', (string) $t), 'Arabic text in an English interface: ' . $t);
        }
    }
}
