<?php

/**
 * TOURFECTO AI CEO (المرحلة 5): التسجيلات بتتنسب لأول صفحة للزائر، فحص الموقع بيتحفظ والتحسن بيتقاس،
 * وخطة المحتوى بتقف على ميزانية AI CEO وبترفض الرد اللي مش JSON.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/BrandSiteAudit.php';
require_once __DIR__ . '/../../app/Services/AiCeo/BrandGrowth.php';
require_once __DIR__ . '/../../app/Services/AiCeo/PlatformDecisions.php';

final class BrandGrowthIntegrationTest extends TestCase
{
    private const USER = 999870;
    private const SITE = 'https://brand-audit.test';
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            if (!class_exists('MarketingContent')) require_once $app . '/Services/Marketing/MarketingContent.php';
            $this->db = Database::getInstance();
            new BrandSiteAudit($this->db);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query("DELETE FROM visitor_logs WHERE visitor_id LIKE 'phpunit-brand-%'");
        $this->db->query('DELETE FROM users WHERE id = ?', [self::USER]);
        $this->db->query('DELETE FROM aiceo_brand_audits WHERE site_url = ?', [self::SITE]);
        $this->db->query("DELETE FROM system_settings WHERE setting_key IN ('brand_site_url', 'aiceo_ai_budget_usd_day')");
        $this->db->query("DELETE FROM api_usage_logs WHERE feature = 'ai_ceo_phpunit_brand'");
    }

    private function visit(string $visitor, string $page, ?string $ref, string $country = 'US', ?int $user = null): void
    {
        $this->db->query('INSERT INTO visitor_logs (visitor_id, user_id, page_url, referrer, country, is_authenticated, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$visitor, $user, $page, $ref, $country, $user ? 1 : 0]);
    }

    private function row(array $rows, string $key): array
    {
        foreach ($rows as $r) if ($r['key'] === $key) return $r;
        return ['visitors' => 0, 'signups' => 0];
    }

    public function testSignupIsCreditedToFirstLandingPage(): void
    {
        $bg = new BrandGrowth($this->db);
        $before = $bg->acquisition(30);
        $this->visit('phpunit-brand-a', '/phpunit-brand/seo-for-tours', 'https://www.google.com/search?q=x');
        $this->visit('phpunit-brand-a', '/pricing', null);
        $this->visit('phpunit-brand-b', '/phpunit-brand/seo-for-tours', 'https://facebook.com/', 'AE');
        $this->visit('phpunit-brand-c', '/admin/ai-ceo', null);
        $this->db->query("INSERT INTO users (id, email, password, company_name, role, created_at) VALUES (?, 'brand-signup@tourfecto.test', 'x', 'Brand Signup Test', 'user', NOW())", [self::USER]);
        $this->visit('phpunit-brand-a', '/dashboard', null, 'US', self::USER);

        $a = $bg->acquisition(30);
        $this->assertSame($before['visitors'] + 2, $a['visitors'], 'two public landings; the admin page is excluded');
        $this->assertSame($before['signups'] + 1, $a['signups']);
        $page = $this->row($a['landing_pages'], '/phpunit-brand/seo-for-tours');
        $this->assertSame(2, $page['visitors']);
        $this->assertSame(1, $page['signups']);
        $this->assertSame(50.0, (float) $page['conversion']);
        $this->assertSame(0, $this->row($a['landing_pages'], '/admin/ai-ceo')['visitors']);
        $this->assertSame(1, $this->row($a['referrers'], 'google.com')['signups'] - $this->row($before['referrers'], 'google.com')['signups']);
    }

    public function testAuditRunsOnSitemapAndTracksImprovement(): void
    {
        $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('brand_site_url', ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [self::SITE]);
        $good = '<title>Tourfecto — AI marketing for tour operators</title><meta name="description" content="SEO, AI chat, social publishing and bookings for tourism businesses in one platform.">'
            . '<link rel="canonical" href="' . self::SITE . '/"><h1>x</h1>' . str_repeat('word ', 300);
        $fixed = false;
        $seen = [];
        $fetch = function (string $url) use (&$fixed, &$seen, $good) {
            $seen[] = $url;
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/robots.txt') return ['code' => 200, 'body' => "User-agent: *\nSitemap: " . self::SITE . "/sitemap.xml\n", 'ms' => 10];
            if ($path === '/sitemap.xml') return ['code' => 200, 'body' => '<urlset><url><loc>' . self::SITE . '/features</loc></url><url><loc>https://evil.test/x</loc></url></urlset>', 'ms' => 10];
            if ($path === '/features') return $fixed ? ['code' => 200, 'body' => $good, 'ms' => 50] : ['code' => 500, 'body' => '', 'ms' => 50];
            if ($path === '/') return ['code' => 200, 'body' => $good, 'ms' => 50];
            return ['code' => 404, 'body' => '', 'ms' => 5];
        };
        $audit = new BrandSiteAudit($this->db, $fetch);
        $this->assertSame(self::SITE, $audit->siteUrl());
        $r = $audit->run();
        $this->assertSame(2, $r['summary']['pages'], 'home + sitemap page; other hosts are skipped');
        $this->assertNotContains('https://evil.test/x', $seen);
        $this->assertSame(1, $r['summary']['critical']);
        $fixed = true;
        $audit->run();
        $latest = $audit->latest();
        $this->assertSame(0, $latest['summary']['critical']);
        $this->assertSame($r['summary']['score'], $latest['previous_score']);
        $this->assertTrue($latest['score'] > $latest['previous_score']);

        // القرار بيظهر لما فيه مشاكل خطيرة
        $snap = ['brand' => ['audit_at' => $latest['run_at'], 'audit_critical' => 3, 'audit_score' => 40, 'visitors_30d' => 80, 'signups_30d' => 0, 'brand_site_in_platform' => true]];
        $keys = array_column(PlatformDecisions::decide($snap), 'key');
        $this->assertContains('brand_site_critical', $keys);
        $this->assertContains('brand_no_conversions', $keys);
        $this->assertNotContains('brand_site_not_in_platform', $keys);
    }

    public function testMonthlyPlanUsesFakeAiAndRejectsBadOutput(): void
    {
        $calls = 0;
        $prompt = '';
        $plan = ['positioning' => 'x', 'markets' => [['market' => 'us', 'posts' => [['hook' => 'Guaranteed bookings']]]], 'kpis' => ['sign-ups']];
        $bg = new BrandGrowth($this->db, function (string $p) use (&$calls, &$prompt, $plan) { $calls++; $prompt = $p; return "```json\n" . json_encode($plan) . "\n```"; });
        $out = $bg->monthlyPlan(1, '2026-12', ['us', 'xx'], ['landing_pages' => [['key' => '/features/seo', 'visitors' => 9, 'signups' => 2]]]);
        $this->assertSame('Brand content plan 2026-12', $out['title']);
        $this->assertSame('us', $out['plan']['markets'][0]['market']);
        $this->assertContains('"guarantee" promises a guarantee', $out['warnings']);
        $this->assertStringContainsString('/features/seo', $prompt);
        $this->assertStringContainsString('target markets: us ', $prompt);

        $bad = new BrandGrowth($this->db, fn () => 'not json');
        try { $bad->monthlyPlan(1, '2026-12', ['us'], []); $this->fail('expected invalid_plan'); } catch (RuntimeException $e) { $this->assertSame('invalid_plan', $e->getMessage()); }
        try { $bad->monthlyPlan(1, 'December', ['us'], []); $this->fail('expected bad month'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        $this->assertSame(1, $calls);
    }
}
