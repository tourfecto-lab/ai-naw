<?php

/**
 * Tourfecto - Integrations (Item 3b) Integration Test
 * بيفحص خدمات التكاملات الثمانية (Slack/Algolia/Calendly/HubSpot/Mixpanel/
 * OneSignal/Zapier/Zoom) عبر `BaseIntegrationService::httpJson/httpForm` مع
 * حقن transport وهمي — **صفر شبكة** (نفس نمط حقن WordPressPublisher من م3):
 *   1) النجاح: كل خدمة بتبعت الـ headers/URL/body الصح (Bearer/Basic/X-Algolia/
 *      webhook بدون auth) وتفك الاستجابة.
 *   2) فشل الـ API: HTTP 401/500 أو `ok:false` من Slack → فشل نظيف.
 *   3) فشل شبكة: transport يرجّع خطأ curl → `success=false` + نص الخطأ.
 *   4) تحقق محلي: Algolia بدون objectID / action غير مدعوم → رفض قبل أي طلب.
 *   5) Zoom: token exchange (httpForm) + createMeeting (httpJson) + فشل بدون token.
 *
 * البيانات في `system_settings` (integration_*) بقيم اختبارية تُمسح بعد كل اختبار.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Encryption.php';
require_once __DIR__ . '/../../app/Services/System/SystemSettingsService.php';
require_once __DIR__ . '/../../app/Core/Contracts/IntegrationInterface.php';
require_once __DIR__ . '/../../app/Services/Integrations/BaseIntegrationService.php';
require_once __DIR__ . '/../../app/Services/Integrations/SlackService.php';
require_once __DIR__ . '/../../app/Services/Integrations/AlgoliaService.php';
require_once __DIR__ . '/../../app/Services/Integrations/CalendlyService.php';
require_once __DIR__ . '/../../app/Services/Integrations/HubSpotService.php';
require_once __DIR__ . '/../../app/Services/Integrations/MixpanelService.php';
require_once __DIR__ . '/../../app/Services/Integrations/OneSignalService.php';
require_once __DIR__ . '/../../app/Services/Integrations/ZapierService.php';
require_once __DIR__ . '/../../app/Services/Integrations/ZoomService.php';

/** transport وهمي بيسجّل الطلبات ويرجّع استجابات مسرّحة بنفس بنية رد curl */
final class IntegrationsTransportFake
{
    public array $calls = [];
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke(array $req): array
    {
        $this->calls[] = $req;
        $idx = min(count($this->calls) - 1, count($this->responses) - 1);
        return $this->responses[$idx];
    }
}

final class IntegrationsModuleIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static array $seededKeys = [];
    private static string $zoomCacheFile = '';
    private static string $zoomCacheBackup = '';

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php', $app . '/Config/encryption.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }
            if (empty($conn->query("SHOW TABLES LIKE 'system_settings'")->fetchAll())) {
                self::$pdo = null;
                return null;
            }
            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة (مطلوبة لإعدادات system_settings)');
        }
        $this->seedSettings();
        $this->backupZoomCache();
    }

    protected function tearDown(): void
    {
        if (self::$pdo === null) {
            return;
        }
        $this->cleanup();
        $this->restoreZoomCache();
    }

    private function seedSettings(): void
    {
        $svc = new SystemSettingsService();
        $map = [
            'integration_slack_bot_token' => 'xoxb-test',
            'integration_slack_default_channel' => '#general',
            'integration_algolia_app_id' => 'test-algolia-app',
            'integration_algolia_search_api_key' => 'search-key',
            'integration_algolia_write_api_key' => 'write-key',
            'integration_calendly_api_token' => 'cal-pat',
            'integration_hubspot_api_key' => 'hs-token',
            'integration_mixpanel_token' => 'mp-token',
            'integration_onesignal_app_id' => 'os-app',
            'integration_onesignal_rest_api_key' => 'os-key',
            'integration_zapier_webhook_url' => 'https://hooks.zapier.com/hooks/catch/abc/def',
            'integration_zoom_account_id' => 'zoom-account',
            'integration_zoom_client_id' => 'zoom-client',
            'integration_zoom_client_secret' => 'zoom-secret',
        ];
        foreach ($map as $key => $value) {
            $svc->set($key, $value);
            self::$seededKeys[] = $key;
        }
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        if (!empty(self::$seededKeys)) {
            $keys = implode(',', array_map(fn ($k) => "'" . $k . "'", self::$seededKeys));
            $pdo->exec("DELETE FROM system_settings WHERE setting_key IN ({$keys})");
            self::$seededKeys = [];
        }
    }

    private function zoomCacheFile(): string
    {
        $dir = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE . '/cache' : sys_get_temp_dir() . '/tourfecto';
        return $dir . '/zoom_access_token.json';
    }

    private function backupZoomCache(): void
    {
        self::$zoomCacheFile = $this->zoomCacheFile();
        self::$zoomCacheBackup = is_file(self::$zoomCacheFile) ? (string) file_get_contents(self::$zoomCacheFile) : '';
        // نضمن بداية نظيفة: كاش منتهي (لا حذف - استبدال بقيمة غير صالحة)
        @file_put_contents(self::$zoomCacheFile, json_encode(['access_token' => '', 'expires_at' => 1]));
    }

    private function restoreZoomCache(): void
    {
        if (self::$zoomCacheFile === '') {
            return;
        }
        if (self::$zoomCacheBackup !== '') {
            @file_put_contents(self::$zoomCacheFile, self::$zoomCacheBackup);
        } else {
            // كان مش موجود - نكتب كاش منتهي بلا أثر تشغيلي بدل الحذف
            @file_put_contents(self::$zoomCacheFile, json_encode(['access_token' => '', 'expires_at' => 1]));
        }
        self::$zoomCacheFile = '';
    }

    // ================================================================
    // Slack
    // ================================================================

    public function testSlackSendMessageSuccess(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['ok' => true, 'channel' => '#general', 'ts' => '123.456']), 'error' => null],
        ]);
        $slack = new SlackService($transport);

        $result = $slack->sendMessage('مرحبا بكم في Tourfecto');
        $this->assertTrue($result['success']);

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringContainsString('slack.com/api/chat.postMessage', $call['url']);
        $this->assertContains('Authorization: Bearer xoxb-test', $call['headers']);
        $this->assertContains('Content-Type: application/json', $call['headers']);
        $payload = json_decode((string) $call['body'], true);
        $this->assertSame('#general', $payload['channel']);
        $this->assertSame('مرحبا بكم في Tourfecto', $payload['text']);
    }

    public function testSlackApiErrorHandled(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['ok' => false, 'error' => 'invalid_auth']), 'error' => null],
        ]);
        $slack = new SlackService($transport);

        $result = $slack->sendMessage('hello');
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_auth', $result['error']);
    }

    public function testSlackHttpError(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 401, 'body' => '{}', 'error' => null],
        ]);
        $slack = new SlackService($transport);

        $result = $slack->sendMessage('hello');
        $this->assertFalse($result['success']);
        $this->assertSame('HTTP 401', $result['error']);
        $this->assertSame(401, $result['http_code']);
    }

    public function testSlackUnsupportedActionRejected(): void
    {
        $slack = new SlackService(new IntegrationsTransportFake([]));
        $result = $slack->request('bogus_action');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('غير مدعوم', $result['error']);
    }

    // ================================================================
    // Algolia
    // ================================================================

    public function testAlgoliaSearchSendsHeadersAndQuery(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['hits' => [], 'nbHits' => 0]), 'error' => null],
        ]);
        $algolia = new AlgoliaService($transport);

        $result = $algolia->searchIndex('places', 'dubai');
        $this->assertTrue($result['success']);

        $call = $transport->calls[0];
        $this->assertStringContainsString('test-algolia-app-dsn.algolia.net/1/indexes/places/query', $call['url']);
        $this->assertContains('X-Algolia-Application-Id: test-algolia-app', $call['headers']);
        $this->assertContains('X-Algolia-API-Key: search-key', $call['headers']);
        $payload = json_decode((string) $call['body'], true);
        $this->assertSame('dubai', $payload['query']);
    }

    public function testAlgoliaIndexObjectRequiresObjectId(): void
    {
        $algolia = new AlgoliaService(new IntegrationsTransportFake([]));
        $result = $algolia->indexObject('places', ['name' => 'X']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('objectID', $result['error']);
    }

    public function testAlgoliaIndexObjectsBatchPayload(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['objectIDs' => ['a', 'b']]), 'error' => null],
        ]);
        $algolia = new AlgoliaService($transport);

        $result = $algolia->indexObjects('places', [['objectID' => 'a'], ['objectID' => 'b']]);
        $this->assertTrue($result['success']);

        $payload = json_decode((string) $transport->calls[0]['body'], true);
        $this->assertCount(2, $payload['requests']);
        $this->assertSame('addObject', $payload['requests'][0]['action']);
        $this->assertContains('X-Algolia-API-Key: write-key', $transport->calls[0]['headers']);
    }

    // ================================================================
    // Calendly / HubSpot / OneSignal / Zapier
    // ================================================================

    public function testCalendlyMeReturnsResource(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'resource' => ['uri' => 'https://api.calendly.com/users/u123', 'name' => 'Travel Agency'],
            ]), 'error' => null],
        ]);
        $calendly = new CalendlyService($transport);

        $result = $calendly->me();
        $this->assertTrue($result['success']);
        $this->assertSame('https://api.calendly.com/users/u123', $result['data']['uri']);
        $this->assertContains('Authorization: Bearer cal-pat', $transport->calls[0]['headers']);
        $this->assertStringContainsString('api.calendly.com/users/me', $transport->calls[0]['url']);
    }

    public function testCalendlyEventTypesUsesUserUri(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['collection' => []]), 'error' => null],
        ]);
        $calendly = new CalendlyService($transport);

        $result = $calendly->getEventTypes('https://api.calendly.com/users/u123');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('event_types?user=' . urlencode('https://api.calendly.com/users/u123'), $transport->calls[0]['url']);
    }

    public function testHubSpotCreateContact(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id' => '501', 'properties' => ['email' => 'x@example.com']]), 'error' => null],
        ]);
        $hubspot = new HubSpotService($transport);

        $result = $hubspot->createOrUpdateContact('x@example.com', ['firstname' => 'Ahmed']);
        $this->assertTrue($result['success']);
        $this->assertSame('501', $result['data']['id']);

        $call = $transport->calls[0];
        $this->assertStringContainsString('api.hubapi.com/crm/v3/objects/contacts', $call['url']);
        $this->assertContains('Authorization: Bearer hs-token', $call['headers']);
        $payload = json_decode((string) $call['body'], true);
        $this->assertSame('x@example.com', $payload['properties']['email']);
        $this->assertSame('Ahmed', $payload['properties']['firstname']);
    }

    public function testMixpanelTrackBase64Data(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => '1', 'error' => null],
        ]);
        $mixpanel = new MixpanelService($transport);

        $result = $mixpanel->track('booking.completed', ['hotel' => 'Nile'], 'user-1');
        $this->assertTrue($result['success']);
        $this->assertSame('1', $result['data']['response']);

        $call = $transport->calls[0];
        $this->assertStringContainsString('api.mixpanel.com/track', $call['url']);
        parse_str((string) $call['body'], $parsed);
        $this->assertArrayHasKey('data', $parsed);
        $decoded = json_decode(base64_decode($parsed['data']), true);
        $this->assertSame('booking.completed', $decoded[0]['event']);
        $this->assertSame('user-1', $decoded[0]['properties']['distinct_id']);
    }

    public function testMixpanelTrackFailureResponse(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => '0', 'error' => null],
        ]);
        $mixpanel = new MixpanelService($transport);

        $result = $mixpanel->track('booking.completed');
        $this->assertFalse($result['success']);
    }

    public function testOneSignalSendNotificationBasicAuth(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id' => 'notif-1', 'recipients' => 5]), 'error' => null],
        ]);
        $onesignal = new OneSignalService($transport);

        $result = $onesignal->sendNotification(['contents' => ['en' => 'Hi']]);
        $this->assertTrue($result['success']);
        $this->assertSame('notif-1', $result['data']['id']);

        $call = $transport->calls[0];
        $this->assertStringContainsString('onesignal.com/api/v1/notifications', $call['url']);
        $this->assertContains('Authorization: Basic os-key', $call['headers']);
        $payload = json_decode((string) $call['body'], true);
        $this->assertSame('os-app', $payload['app_id']);
    }

    public function testZapierTriggerSendsToWebhookWithoutAuth(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['status' => 'success']), 'error' => null],
        ]);
        $zapier = new ZapierService($transport);

        $result = $zapier->trigger('review.received', ['stars' => 5]);
        $this->assertTrue($result['success']);

        $call = $transport->calls[0];
        $this->assertSame('https://hooks.zapier.com/hooks/catch/abc/def', $call['url']);
        // Zapier مفيش أي Authorization header
        $this->assertNotContains('Authorization', array_map(fn ($h) => explode(':', $h, 2)[0], $call['headers']));
        $payload = json_decode((string) $call['body'], true);
        $this->assertSame('review.received', $payload['event']);
        $this->assertSame(5, $payload['stars']);
    }

    // ================================================================
    // فشل شبكة عام
    // ================================================================

    public function testNetworkFailurePropagatesFromHttpJson(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Connection timed out'],
        ]);
        $slack = new SlackService($transport);

        $result = $slack->sendMessage('hello');
        $this->assertFalse($result['success']);
        $this->assertSame('Connection timed out', $result['error']);
        $this->assertSame(0, $result['http_code']);
    }

    // ================================================================
    // Zoom
    // ================================================================

    public function testZoomCreateMeetingSuccessAfterTokenExchange(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'ztok', 'expires_in' => 3600]), 'error' => null],
            ['http_code' => 201, 'body' => json_encode(['id' => 'm123', 'join_url' => 'https://zoom.us/j/123']), 'error' => null],
        ]);
        $zoom = new ZoomService($transport);

        $result = $zoom->createMeeting(['topic' => 'Client Meeting', 'duration' => 45]);
        $this->assertTrue($result['success']);
        $this->assertSame('m123', $result['data']['id']);

        // call[0] = token exchange (httpForm) بـ Basic auth
        $tokenCall = $transport->calls[0];
        $this->assertStringContainsString('zoom.us/oauth/token', $tokenCall['url']);
        $this->assertContains('Authorization: Basic ' . base64_encode('zoom-client:zoom-secret'), $tokenCall['headers']);
        $this->assertStringContainsString('grant_type=account_credentials', $tokenCall['body']);

        // call[1] = إنشاء اللقاء بـ Bearer ztok
        $meetingCall = $transport->calls[1];
        $this->assertStringContainsString('api.zoom.us/v2/users/me/meetings', $meetingCall['url']);
        $this->assertContains('Authorization: Bearer ztok', $meetingCall['headers']);
        $payload = json_decode((string) $meetingCall['body'], true);
        $this->assertSame('Client Meeting', $payload['topic']);
        $this->assertSame(45, $payload['duration']);
    }

    public function testZoomCreateMeetingFailsWithoutToken(): void
    {
        // token exchange بيفشل → getAccessToken() = '' → createMeeting ترفض نظيف
        $transport = new IntegrationsTransportFake([
            ['http_code' => 400, 'body' => json_encode(['error' => 'invalid_client']), 'error' => null],
        ]);
        $zoom = new ZoomService($transport);

        $result = $zoom->createMeeting(['topic' => 'X']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Zoom access token', $result['error']);
    }

    public function testZoomTokenExchangeNetworkFailure(): void
    {
        $transport = new IntegrationsTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Connection refused'],
        ]);
        $zoom = new ZoomService($transport);

        $this->assertSame('', $zoom->getAccessToken());
    }
}
