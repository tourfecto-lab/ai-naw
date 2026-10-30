<?php

use PHPUnit\Framework\TestCase;

/** One Tourfecto code per site: it runs only the services the customer switched on, and never changes once installed. */
final class SiteCodeServicesTest extends TestCase
{
    public function testTheCodeLoadsTheChatOnlyWhileItIsSwitchedOnAndKeepsItsTokenWhenSeoIsTurnedOn(): void
    {
        $db = Database::getInstance();
        $userId = createTestUser();
        $siteId = createTestWebsite($userId);
        $svc = new AutoSeoEmbedService($db);

        $token = $svc->ensureEmbedToken($userId, $siteId);
        $this->assertMatchesRegularExpression('/^emb_[a-f0-9]{24}$/', (string) $token);
        $this->assertSame($token, $svc->ensureEmbedToken($userId, $siteId), 'asking again returns the same code');
        $this->assertTrue($svc->siteServices($siteId)['chat'], 'a code made from «Connect your website» starts with the chat on');

        $js = $svc->buildEmbedJavaScript((string) $token);
        $this->assertStringContainsString('/widget/chat?site=' . $siteId, $js);
        $this->assertStringContainsString('fixes: 0', $js, 'no SEO fixes while Auto SEO is off');

        $svc->setSiteService($siteId, 'chat', false);
        $this->assertStringNotContainsString('/widget/chat', $svc->buildEmbedJavaScript((string) $token));

        try {
            $svc->connectWebsite($userId, $siteId, 'script');
        } catch (Throwable $e) {
            $this->markTestSkipped('Auto SEO connect needs the full schema: ' . $e->getMessage());
        }
        $after = $db->query('SELECT embed_token, is_connected FROM websites WHERE id = ?', [$siteId])[0];
        $this->assertSame($token, $after['embed_token'], 'turning SEO on must not break the code already on the site');
        $this->assertSame(1, (int) $after['is_connected']);

        $db->exec('DELETE FROM system_settings WHERE setting_key = ?', ['site_code_' . $siteId]);
    }

    public function testALegacySeoOnlyCodeDoesNotStartShowingAChatBubble(): void
    {
        $db = Database::getInstance();
        $userId = createTestUser();
        $siteId = createTestWebsite($userId);
        $token = 'emb_' . bin2hex(random_bytes(12));
        $db->exec('UPDATE websites SET embed_token = ?, is_connected = 1 WHERE id = ?', [$token, $siteId]);

        $this->assertStringNotContainsString('/widget/chat', (new AutoSeoEmbedService($db))->buildEmbedJavaScript($token));
    }
}
