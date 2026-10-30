<?php

use PHPUnit\Framework\TestCase;

/**
 * One install per site type does everything: tourfecto-connect.php (PHP sites) and the Tourfecto WordPress plugin
 * carry the site code (chat), and «SEO articles» can publish on a PHP site through the file at /guides/{slug}.
 */
final class OneInstallConnectorTest extends TestCase
{
    /** @var int[] the test DB reuses website ids between runs, so articles left behind would clash with new sites */
    private array $sites = [];

    protected function tearDown(): void
    {
        foreach ($this->sites as $id) {
            Database::getInstance()->exec('DELETE FROM ai_articles WHERE website_id = ?', [$id]);
        }
    }

    private function site(int $userId, string $url): int
    {
        $id = createTestWebsite($userId, ['main_url' => $url]);
        Database::getInstance()->exec('DELETE FROM ai_articles WHERE website_id = ?', [$id]);
        return $this->sites[] = $id;
    }

    private static function article(int $userId, int $siteId, string $title, string $url): int
    {
        $db = Database::getInstance();
        $db->exec("INSERT INTO ai_articles (user_id, website_id, topic, title, meta_description, content, status, published_at, published_url) VALUES (?, ?, 'topic', ?, 'desc', ?, 'published', NOW(), ?)",
            [$userId, $siteId, $title, "## Day one\n\nVisit the pyramids early.", $url]);
        return (int) $db->getConnection()->lastInsertId();
    }

    public function testArticlesPublishThroughTheFileAtGuidesWithAUniqueAsciiSlug(): void
    {
        $userId = createTestUser();
        $siteId = $this->site($userId, 'https://www.Example-Tours.com/ar/');
        $base = 'https://www.example-tours.com/guides/';

        $this->assertSame(['success' => true, 'url' => $base], CmsPublisher::publishViaConnector($siteId, [], true), 'the connection test answers with the /guides address');
        $this->assertTrue(CmsPublisher::isCmsEndpoint('connector://' . $siteId));

        $r = (new CmsPublisher())->publish('connector://' . $siteId, '', ['title' => 'Cairo in 3 Days!', 'content_markdown' => 'Body', 'article_id' => 0]);
        $this->assertSame(['success' => true, 'url' => $base . 'cairo-in-3-days'], $r);

        self::article($userId, $siteId, 'Cairo in 3 Days!', $base . 'cairo-in-3-days');
        $again = CmsPublisher::publishViaConnector($siteId, ['title' => 'Cairo in 3 days', 'content_markdown' => 'Body']);
        $this->assertSame($base . 'cairo-in-3-days-2', $again['url'], 'a second article with the same title gets its own address');

        $arabic = CmsPublisher::publishViaConnector($siteId, ['title' => 'القاهرة في ٣ أيام', 'content_markdown' => 'نص', 'article_id' => 77]);
        $this->assertSame($base . 'guide-77', $arabic['url'], 'the file only serves [a-z0-9-] slugs');

        $this->assertFalse(CmsPublisher::publishViaConnector($siteId, ['title' => 'Empty', 'content_html' => '<p> </p>'])['success']);
        $this->assertFalse(CmsPublisher::publishViaConnector(999999999, ['title' => 'x', 'content_markdown' => 'y'])['success']);

        // the server answers /guides/... with 404 before index.php runs: the article lives at /?tf_article=slug
        $q = (new CmsPublisher())->publish('connector://' . $siteId . '/q', '', ['title' => 'Luxor by night', 'content_markdown' => 'Body']);
        $this->assertSame('https://www.example-tours.com/?tf_article=luxor-by-night', $q['url']);
    }

    public function testPublishedArticlesReachTheFileRules(): void
    {
        $userId = createTestUser();
        $siteId = $this->site($userId, 'https://rules-tours.com');
        $id = self::article($userId, $siteId, 'Nile cruise guide', 'https://rules-tours.com/guides/nile-cruise-guide');
        self::article($userId, $siteId, 'Luxor by night', 'https://rules-tours.com/?tf_article=luxor-by-night');
        self::article($userId, $siteId, 'Old blog post', 'https://rules-tours.com/blog/old-post');

        $rules = EdgeRules::rulesFor(Database::getInstance(), $siteId, 'rules-tours.com');
        $this->assertArrayHasKey('nile-cruise-guide', $rules['articles'] ?? [], 'articles published at /guides are served by the file');
        $this->assertSame('Nile cruise guide', $rules['articles']['nile-cruise-guide']['title']);
        $this->assertStringContainsString('Visit the pyramids early.', $rules['articles']['nile-cruise-guide']['body']);
        $this->assertSame('https://rules-tours.com/?tf_article=luxor-by-night', $rules['articles']['luxor-by-night']['url'] ?? null);
        $this->assertCount(2, $rules['articles'], 'articles published elsewhere are not served by the file');
        $this->assertGreaterThan(0, $id);
    }

    public function testTheFileAndThePluginCarryTheSiteCode(): void
    {
        $code = 'https://tourfecto.pro/embed.js?token=emb_' . str_repeat('a', 24) . '&via=connector';

        $php = EdgeRules::phpConnector(5, 'https://tourfecto.pro/api/edge/rules', $code);
        token_get_all($php, TOKEN_PARSE);
        $this->assertStringContainsString("define('TF_SITE_CODE', '" . $code . "');", $php);
        $this->assertStringContainsString("define('TF_SITE_CODE', '');", EdgeRules::phpConnector(5, 'https://tourfecto.pro/api/edge/rules'), 'an old download without the code still works');

        $files = TourfectoPluginBundle::files(5, 'https://tourfecto.pro/api/edge/rules', 'example.com', $code);
        $module = $files['tourfecto-seo/modules/tourfecto-site-code.php'] ?? null;
        $this->assertIsString($module, 'the plugin ships the site code module');
        token_get_all($module, TOKEN_PARSE);
        $this->assertStringContainsString($code, $module);
        $this->assertArrayNotHasKey('tourfecto-seo/modules/tourfecto-site-code.php', TourfectoPluginBundle::files(5, 'https://tourfecto.pro/api/edge/rules', 'example.com'));
    }

    public function testTheCodeLoadedByTheFileRunsServicesOnly(): void
    {
        $db = Database::getInstance();
        $userId = createTestUser();
        $siteId = createTestWebsite($userId);
        $svc = new AutoSeoEmbedService($db);
        $token = (string) $svc->ensureEmbedToken($userId, $siteId);
        $db->exec('UPDATE websites SET is_connected = 1 WHERE id = ?', [$siteId]);

        $connector = $svc->buildEmbedJavaScript($token, true);
        $this->assertStringContainsString('fixes: 0', $connector, 'the file already applies SEO on the server; the browser must not apply it twice');
        $this->assertStringContainsString('/widget/chat?site=' . $siteId, $connector);
        $this->assertStringContainsString('if (window.__tourfectoCode) return;', $connector, 'a code pasted by hand plus the file must not load the chat twice');

        $db->exec('DELETE FROM system_settings WHERE setting_key = ?', ['site_code_' . $siteId]);
    }
}
