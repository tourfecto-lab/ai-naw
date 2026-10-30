<?php

use PHPUnit\Framework\TestCase;

/**
 * A customer moved their official WhatsApp number to a new account without disconnecting it from the old one.
 * The number was then "connected" twice, the platform webhook could not tell whose message it was, and every
 * incoming message was dropped as "unknown number". The newest connection (just verified with Meta) now wins.
 */
final class WhatsAppNumberMovedAccountTest extends TestCase
{
    /** @var int[] */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $id) {
            Database::getInstance()->query('DELETE FROM platform_connections WHERE id = ?', [$id]);
        }
        $this->connections = [];
    }

    private function connect(string $pnid, string $connectedAt): array
    {
        $userId = createTestUser();
        $siteId = createTestWebsite($userId);
        $id = (int) Database::getInstance()->query(
            "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_account_id, external_location_id, external_location_name, status, connected_at, meta_json)
             VALUES (?, ?, 'whatsapp_cloud', 'enc', ?, 'waba', 'Test · +20', 'connected', ?, '{}')",
            [$siteId, $userId, $pnid, $connectedAt]
        );
        $this->connections[] = $id;
        return ['id' => $id, 'site' => $siteId];
    }

    private static function connStatus(int $id): string
    {
        return (string) Database::getInstance()->query('SELECT status FROM platform_connections WHERE id = ?', [$id])[0]['status'];
    }

    public function testAMessageForANumberConnectedTwiceReachesTheNewestConnection(): void
    {
        $pnid = (string) random_int(100000000000000, 999999999999999);
        $this->connect($pnid, '2026-10-01 10:00:00');
        $this->connect($pnid, '2026-10-30 10:00:00');

        $r = WhatsAppCloudController::routePlatformPayload(['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $pnid]]]]]]]);
        $this->assertSame(1, $r['matched'], 'the event is routed instead of being dropped');
        $this->assertSame(0, $r['unknown']);
    }

    public function testConnectingTheNumberReleasesItFromTheOldAccountOnly(): void
    {
        $pnid = (string) random_int(100000000000000, 999999999999999);
        $old = $this->connect($pnid, '2026-10-01 10:00:00');
        $new = $this->connect($pnid, '2026-10-30 10:00:00');
        $other = $this->connect((string) random_int(100000000000000, 999999999999999), '2026-10-01 10:00:00');

        $this->assertSame(1, WhatsAppCloudController::releaseNumberElsewhere($pnid, $new['id']));
        $this->assertSame('disconnected', self::connStatus($old['id']), 'the old account no longer claims the number');
        $this->assertSame('connected', self::connStatus($new['id']));
        $this->assertSame('connected', self::connStatus($other['id']), 'other numbers are not touched');
        $row = Database::getInstance()->query('SELECT access_token, last_error FROM platform_connections WHERE id = ?', [$old['id']])[0];
        $this->assertNull($row['access_token'], 'the old account loses its key, like «disconnect»');
        $this->assertNotEmpty($row['last_error'], 'the old account sees why');

        $this->assertSame(0, WhatsAppCloudController::releaseNumberElsewhere($pnid, $new['id']), 'nothing left to release');
        $this->assertSame(0, WhatsAppCloudController::releaseNumberElsewhere('', $new['id']));
    }

    public function testTheCheckButtonInTheOldAccountDoesNotTakeTheNumberBack(): void
    {
        $pnid = (string) random_int(100000000000000, 999999999999999);
        $old = $this->connect($pnid, '2026-10-01 10:00:00');
        $new = $this->connect($pnid, '2026-10-30 10:00:00');

        $this->assertSame(0, WhatsAppCloudController::releaseNumberElsewhere($pnid, $old['id'], true), 'checking from the old account moves nothing');
        $this->assertSame('connected', self::connStatus($new['id']));

        $this->assertSame(1, WhatsAppCloudController::releaseNumberElsewhere($pnid, $new['id'], true), 'checking from the new account clears the old one');
        $this->assertSame('disconnected', self::connStatus($old['id']));
    }
}
