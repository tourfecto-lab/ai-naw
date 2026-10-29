<?php

use PHPUnit\Framework\TestCase;

/**
 * The optional messaging-only Meta app (Messenger + Instagram DMs): off by default, its own redirect URI and scopes,
 * a signed short-lived state, and a webhook that accepts signatures from either app.
 */
final class MetaMessagingAppTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('META_MSG_APP_ID=123456789012345');
        putenv('META_MSG_APP_SECRET=msg-secret-for-tests');
        putenv('META_MSG_LOGIN_CONFIG_ID');
        putenv('META_MSG_REDIRECT_URI');
    }

    protected function tearDown(): void
    {
        putenv('META_MSG_APP_ID');
        putenv('META_MSG_APP_SECRET');
        putenv('META_MSG_LOGIN_CONFIG_ID');
        putenv('META_MSG_REDIRECT_URI');
    }

    public function testOffUntilBothCredentialsExist(): void
    {
        $this->assertTrue(MetaMessagingApp::configured());
        putenv('META_MSG_APP_SECRET');
        $this->assertFalse(MetaMessagingApp::configured());
        putenv('META_MSG_APP_SECRET=x');
        putenv('META_MSG_APP_ID');
        $this->assertFalse(MetaMessagingApp::configured());
    }

    public function testStateRoundTripExpiryAndTampering(): void
    {
        $now = 1_800_000_000;
        $s = MetaMessagingApp::makeState(42, 7, $now);
        $this->assertSame([42, 7], MetaMessagingApp::readState($s, $now + 60));
        $this->assertNull(MetaMessagingApp::readState($s, $now + 901), 'expires after 15 minutes');
        $this->assertNull(MetaMessagingApp::readState($s, $now - 5), 'not from the future');
        $parts = explode('.', $s);
        $parts[0] = '43';
        $this->assertNull(MetaMessagingApp::readState(implode('.', $parts), $now + 1), 'another user id breaks the signature');
        $this->assertNull(MetaMessagingApp::readState('1.2.3.4.5', $now));
        $this->assertNull(MetaMessagingApp::readState('', $now));
        putenv('META_MSG_APP_SECRET=a-different-secret');
        $this->assertNull(MetaMessagingApp::readState($s, $now + 1), 'a state signed with another secret is refused');
    }

    public function testAuthUrlUsesTheMessagingAppAndItsOwnRedirect(): void
    {
        $url = MetaMessagingApp::authUrl('STATE1');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('123456789012345', $q['client_id']);
        $this->assertSame('STATE1', $q['state']);
        $this->assertStringEndsWith('/chat/connect/meta/callback', $q['redirect_uri']);
        $this->assertStringNotContainsString('/ads/connect/meta', $q['redirect_uri'], 'never the main app callback');
        $scopes = explode(',', $q['scope']);
        foreach (['pages_messaging', 'pages_manage_metadata', 'instagram_manage_messages'] as $s) {
            $this->assertContains($s, $scopes);
        }
        $this->assertNotContains('ads_management', $scopes, 'messaging only');
        $this->assertArrayNotHasKey('config_id', $q);
    }

    public function testLoginConfigReplacesScopes(): void
    {
        putenv('META_MSG_LOGIN_CONFIG_ID=987654321');
        parse_str((string) parse_url(MetaMessagingApp::authUrl('S', true), PHP_URL_QUERY), $q);
        $this->assertSame('987654321', $q['config_id']);
        $this->assertArrayNotHasKey('scope', $q);
        $this->assertSame('rerequest', $q['auth_type']);
    }

    public function testExchangeAndPagesWithAFakeGraph(): void
    {
        $calls = [];
        $get = function (string $url) use (&$calls): array {
            $calls[] = $url;
            if (strpos($url, 'fb_exchange_token') !== false) {
                return [200, json_encode(['access_token' => 'LONG'])];
            }
            if (strpos($url, 'oauth/access_token') !== false) {
                return [200, json_encode(['access_token' => 'SHORT'])];
            }
            return [200, json_encode(['data' => [
                ['id' => '111', 'name' => 'Page One', 'access_token' => 'PT1', 'instagram_business_account' => ['id' => '999', 'username' => 'page_one']],
                ['id' => '222', 'name' => 'No token'],
                ['id' => '333', 'name' => 'Page Three', 'access_token' => 'PT3'],
            ]])];
        };
        $ex = MetaMessagingApp::exchange('CODE', $get);
        $this->assertSame(['success' => true, 'access_token' => 'LONG'], $ex);
        $this->assertStringContainsString('code=CODE', $calls[0]);
        $this->assertStringContainsString('client_secret=msg-secret-for-tests', $calls[0]);

        $p = MetaMessagingApp::pages('LONG', $get);
        $this->assertTrue($p['success']);
        $this->assertCount(2, $p['pages'], 'a page without a token is skipped');
        $this->assertSame(['id' => '111', 'name' => 'Page One', 'token' => 'PT1', 'ig_id' => '999', 'ig_username' => 'page_one'], $p['pages'][0]);
        $this->assertSame('', $p['pages'][1]['ig_id']);
    }

    public function testBusinessOwnedPagesComeFromTheTokensGranularScopes(): void
    {
        $get = function (string $url): array {
            if (strpos($url, 'debug_token') !== false) {
                return [200, json_encode(['data' => ['granular_scopes' => [
                    ['scope' => 'pages_messaging', 'target_ids' => ['647362815136492']],
                    ['scope' => 'instagram_basic', 'target_ids' => ['17841475954414841']],
                    ['scope' => 'pages_show_list', 'target_ids' => ['647362815136492', 'not-an-id']],
                ]]])];
            }
            if (strpos($url, '/647362815136492?') !== false) {
                return [200, json_encode(['id' => '647362815136492', 'name' => 'tourfecto', 'access_token' => 'PTB', 'instagram_business_account' => ['id' => '17841475954414841', 'username' => 'tourfecto']])];
            }
            return [200, json_encode(['data' => []])]; // /me/accounts is empty for business-owned pages
        };
        $p = MetaMessagingApp::pages('USER', $get);
        $this->assertTrue($p['success']);
        $this->assertSame([['id' => '647362815136492', 'name' => 'tourfecto', 'token' => 'PTB', 'ig_id' => '17841475954414841', 'ig_username' => 'tourfecto']], $p['pages']);
    }

    public function testFacebookErrorsAreReportedNotThrown(): void
    {
        $bad = static fn (string $u): array => [400, json_encode(['error' => ['message' => 'Invalid code']])];
        $r = MetaMessagingApp::exchange('X', $bad);
        $this->assertFalse($r['success']);
        $this->assertSame('Invalid code', $r['error']);
        $this->assertFalse(MetaMessagingApp::pages('T', $bad)['success']);
    }

    public function testWebhookAcceptsEitherAppSecret(): void
    {
        $raw = '{"object":"page","entry":[]}';
        $sig = static fn (string $secret): string => 'sha256=' . hash_hmac('sha256', $raw, $secret);
        $both = ['main-secret', 'msg-secret'];
        $this->assertTrue(MetaWebhookController::signatureValid($raw, $sig('main-secret'), $both));
        $this->assertTrue(MetaWebhookController::signatureValid($raw, $sig('msg-secret'), $both));
        $this->assertFalse(MetaWebhookController::signatureValid($raw, $sig('other'), $both));
        $this->assertFalse(MetaWebhookController::signatureValid($raw, $sig('msg-secret'), ['main-secret', '']), 'an unset second secret never matches');
        $this->assertFalse(MetaWebhookController::signatureValid($raw, '', $both));
    }

    public function testPagesAreStoredEncryptedAndListedWithTheirInstagram(): void
    {
        $db = Database::getInstance();
        $site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$site) {
            $this->markTestSkipped('no website fixture');
        }
        [$wid, $uid] = [(int) $site['id'], (int) $site['user_id']];
        try {
            $n = ChatMetaConnectController::savePages($db, $uid, $wid, [
                ['id' => 'PG1', 'name' => 'Test Page', 'token' => 'PAGE-TOKEN-1', 'ig_id' => 'IG1', 'ig_username' => 'test_ig'],
            ]);
            $this->assertSame(1, $n);
            $row = $db->query("SELECT access_token, external_location_id FROM platform_connections WHERE website_id = ? AND platform = 'messenger_page'", [$wid])[0];
            $this->assertNotSame('PAGE-TOKEN-1', $row['access_token'], 'the token is not stored in clear');
            $this->assertSame('PAGE-TOKEN-1', (new Encryption())->decrypt($row['access_token']));

            $ov = (new MetaChatPages($db))->overview($uid, $wid);
            $mine = array_values(array_filter($ov['pages'], static fn ($p) => ($p['via'] ?? '') === 'messaging_app'));
            $this->assertCount(1, $mine);
            $this->assertSame('test_ig', $mine[0]['instagram']);
            $this->assertFalse($mine[0]['messaging']);
            $this->assertTrue($ov['messaging_app']['configured']);
            $this->assertSame('/chat/connect/meta?website_id=' . $wid, $ov['messaging_app']['connect_url']);
            $this->assertFalse($ov['missing_permissions'], 'pages from the messaging app have the messaging permissions');
        } finally {
            $db->query("DELETE FROM platform_connections WHERE website_id = ? AND platform = 'messenger_page'", [$wid]);
        }
    }

    public function testRoutesAreNewUrlsOnly(): void
    {
        $web = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes/web.php');
        $this->assertStringContainsString("'/chat/connect/meta', 'ChatMetaConnectController', 'start'", $web);
        $this->assertStringContainsString("'/chat/connect/meta/callback', 'ChatMetaConnectController', 'callback'", $web);
        $this->assertTrue(method_exists(ChatMetaConnectController::class, 'callback'));
    }
}
