<?php

use PHPUnit\Framework\TestCase;

/** روابط مشاركة التقرير: التوكن، الانتهاء، الإلغاء، الحد الأقصى، وعزل Tenant. */
final class SeoReportShareServiceTest extends TestCase
{
    private const OWNER = 999821;
    private const OTHER = 999822;

    private function db(): ?Database
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            foreach (['app', 'database', 'encryption'] as $cfg) {
                if (!defined('APP_ENV') && file_exists("$app/Config/$cfg.php")) require_once "$app/Config/$cfg.php";
            }
            foreach (['Database', 'Logger'] as $cls) {
                if (!class_exists($cls) && file_exists("$app/Core/$cls.php")) require_once "$app/Core/$cls.php";
            }
            if (!class_exists('SeoReportShareService')) require_once "$app/Config/project_classmap.php";
            $db = Database::getInstance();
            foreach (['users', 'websites', 'seo_report_shares'] as $t) {
                if (!$db->getConnection()->query("SHOW TABLES LIKE '$t'")->fetchAll()) return null;
            }
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function site(PDO $pdo, int $uid): int
    {
        $pdo->exec("INSERT INTO users (id,email,password,company_name,created_at) VALUES ($uid,'share-$uid@tourfecto.test','x','S',NOW()) ON DUPLICATE KEY UPDATE email=email");
        $pdo->exec("INSERT INTO websites (user_id,main_url,company_name,brand_name) VALUES ($uid,'https://share$uid.example.com','S','S')");
        return (int) $pdo->lastInsertId();
    }

    public function testActivityRulesArePure(): void
    {
        $now = '2026-11-02 12:00:00';
        $this->assertTrue(SeoReportShareService::isActive(['expires_at' => '2026-11-03 00:00:00', 'revoked_at' => null], $now));
        $this->assertFalse(SeoReportShareService::isActive(['expires_at' => '2026-11-01 00:00:00', 'revoked_at' => null], $now));
        $this->assertFalse(SeoReportShareService::isActive(['expires_at' => '2026-11-03 00:00:00', 'revoked_at' => '2026-11-02 11:00:00'], $now));
        $this->assertSame(64, strlen(SeoReportShareService::hash('x')));
    }

    public function testCreateResolveRevokeExpireAndIsolation(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('seo_report_shares غير موجود');
        $pdo = $db->getConnection();
        $pdo->exec('DELETE FROM seo_report_shares WHERE user_id IN (' . self::OWNER . ',' . self::OTHER . ')');
        $pdo->exec('DELETE FROM websites WHERE user_id IN (' . self::OWNER . ',' . self::OTHER . ')');
        $wid = $this->site($pdo, self::OWNER);
        $svc = new SeoReportShareService($db);
        try {
            $c = $svc->create(self::OWNER, $wid, 'en');
            $this->assertSame(64, strlen($c['token']));
            $stored = $pdo->query('SELECT token_hash FROM seo_report_shares WHERE id=' . $c['id'])->fetchColumn();
            $this->assertSame(hash('sha256', $c['token']), $stored);
            $this->assertNotSame($c['token'], $stored); // raw token is never stored

            $grant = $svc->resolve($c['token']);
            $this->assertSame(['user_id' => self::OWNER, 'website_id' => $wid, 'lang' => 'en'], $grant);
            $this->assertNull($svc->resolve(str_repeat('a', 64)));
            $this->assertNull($svc->resolve('not-a-token'));
            $this->assertSame(1, (int) $pdo->query('SELECT view_count FROM seo_report_shares WHERE id=' . $c['id'])->fetchColumn());

            $this->assertNull($svc->resolve($c['token'], gmdate('Y-m-d H:i:s', time() + 31 * 86400))); // expired
            $this->assertFalse($svc->revoke(self::OTHER, $c['id'])); // another tenant cannot revoke it
            $this->assertNotNull($svc->resolve($c['token']));
            $this->assertTrue($svc->revoke(self::OWNER, $c['id']));
            $this->assertNull($svc->resolve($c['token']));

            $this->assertCount(1, $svc->list(self::OWNER, $wid));
            $this->assertFalse($svc->list(self::OWNER, $wid)[0]['active']);
            $this->expectException(InvalidArgumentException::class);
            $svc->list(self::OTHER, $wid);
        } finally {
            $pdo->exec("DELETE FROM seo_report_shares WHERE website_id=$wid");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }

    public function testActiveLinkCapPerSite(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('seo_report_shares غير موجود');
        $pdo = $db->getConnection();
        $pdo->exec('DELETE FROM seo_report_shares WHERE user_id=' . self::OWNER);
        $pdo->exec('DELETE FROM websites WHERE user_id=' . self::OWNER);
        $wid = $this->site($pdo, self::OWNER);
        $svc = new SeoReportShareService($db);
        try {
            for ($i = 0; $i < SeoReportShareService::MAX_ACTIVE; $i++) $svc->create(self::OWNER, $wid, 'ar');
            $this->expectException(InvalidArgumentException::class);
            $svc->create(self::OWNER, $wid, 'ar');
        } finally {
            $pdo->exec("DELETE FROM seo_report_shares WHERE website_id=$wid");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }

    public function testReportPageIsBilingualAndLeaksNoPageDetail(): void
    {
        $overview = ['generated_at' => '2026-11-02 12:00:00',
            'seo' => ['state' => 'measured', 'score' => 95, 'confidence' => 'high', 'stale' => false, 'as_of' => '2026-11-02 04:00:05'],
            'aeo' => ['state' => 'not_measured', 'score' => null], 'geo' => ['state' => 'not_measured', 'score' => null],
            'impact' => ['state' => 'measured', 'pages' => 3, 'confidence' => 'low', 'low_traffic' => true, 'clicks' => ['before' => 2, 'after' => 3, 'change_pct' => 50.0], 'impressions' => ['before' => 10, 'after' => 12, 'change_pct' => 20.0]],
            'actions' => [['title' => 'SECRET-ACTION-TITLE', 'detail' => '/tour/secret-page']]];
        foreach (['ar' => 'تقرير الظهور', 'en' => 'Search and answer visibility report'] as $lang => $needle) {
            $siteName = '<b>Evil</b> Travel';
            ob_start();
            require dirname(__DIR__, 2) . '/app/Views/ReportShare/report.php';
            $html = ob_get_clean();
            $this->assertStringContainsString($needle, $html);
            $this->assertStringContainsString('dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"', $html);
            $this->assertStringContainsString('95', $html);
            $this->assertStringNotContainsString('SECRET-ACTION-TITLE', $html);
            $this->assertStringNotContainsString('/tour/secret-page', $html);
            $this->assertStringNotContainsString('<b>Evil</b>', $html); // site name is escaped
            $this->assertStringContainsString('noindex', $html);
        }
    }
}
