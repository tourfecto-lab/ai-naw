<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic guards for the WhatsApp webhook path (no network, no sends): signature verification and the
 * ordering rules for delivery statuses. A delayed "sent" must never overwrite a "read".
 */
final class WhatsAppWebhookInboxTest extends TestCase
{
    private const WID = 987654;
    private const UID = 987655;

    protected function tearDown(): void
    {
        Database::getInstance()->query('DELETE FROM whatsapp_delivery_statuses WHERE website_id = ?', [self::WID]);
    }

    private function rowFor(string $wamid): ?array
    {
        return Database::getInstance()->query(
            'SELECT status, status_rank, event_timestamp FROM whatsapp_delivery_statuses WHERE website_id = ? AND wamid = ?',
            [self::WID, $wamid]
        )[0] ?? null;
    }

    public function testSignatureAcceptsOnlyTheExactHmac(): void
    {
        $raw = '{"entry":[]}';
        $good = 'sha256=' . hash_hmac('sha256', $raw, 'app-secret');

        $this->assertTrue(WhatsAppWebhookInbox::verifiedSignature($raw, $good, 'app-secret'));
        $this->assertFalse(WhatsAppWebhookInbox::verifiedSignature($raw . ' ', $good, 'app-secret'), 'body changed');
        $this->assertFalse(WhatsAppWebhookInbox::verifiedSignature($raw, $good, 'other-secret'), 'wrong secret');
        $this->assertFalse(WhatsAppWebhookInbox::verifiedSignature($raw, $good, ''), 'empty secret must never verify');
        $this->assertFalse(WhatsAppWebhookInbox::verifiedSignature($raw, strtoupper($good), 'app-secret'), 'format is strict lowercase hex');
        $this->assertFalse(WhatsAppWebhookInbox::verifiedSignature($raw, '', 'app-secret'), 'missing header');
        $this->assertFalse(WhatsAppWebhookInbox::verifiedSignature($raw, 'sha1=' . str_repeat('a', 40), 'app-secret'), 'wrong algorithm');
    }

    public function testDeliveryStatusNeverMovesBackwards(): void
    {
        $id = 'wamid.TEST' . bin2hex(random_bytes(4));
        $base = time();

        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => $id, 'status' => 'read', 'timestamp' => $base + 30]);
        // Reordered webhooks arrive late: they must not downgrade the message.
        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => $id, 'status' => 'delivered', 'timestamp' => $base + 20]);
        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => $id, 'status' => 'sent', 'timestamp' => $base + 10]);

        $row = $this->rowFor($id);
        $this->assertSame('read', $row['status']);
        $this->assertSame(4, (int) $row['status_rank']);
    }

    public function testDeliveryStatusAdvancesAndRecordsFailureText(): void
    {
        $id = 'wamid.TEST' . bin2hex(random_bytes(4));
        $base = time();

        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => $id, 'status' => 'sent', 'timestamp' => $base]);
        $this->assertSame('sent', $this->rowFor($id)['status']);

        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => $id, 'status' => 'delivered', 'timestamp' => $base + 5]);
        $this->assertSame('delivered', $this->rowFor($id)['status']);

        $failed = 'wamid.TEST' . bin2hex(random_bytes(4));
        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, [
            'id' => $failed, 'status' => 'failed', 'timestamp' => $base,
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]);
        $row = Database::getInstance()->query(
            'SELECT status, error_message FROM whatsapp_delivery_statuses WHERE website_id = ? AND wamid = ?',
            [self::WID, $failed]
        )[0];
        $this->assertSame('failed', $row['status']);
        $this->assertNotSame('', (string) $row['error_message']);
    }

    public function testMalformedStatusesAreIgnored(): void
    {
        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => '', 'status' => 'sent']);
        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => 'wamid.X', 'status' => 'bogus']);
        WhatsAppWebhookInbox::recordDelivery(self::WID, self::UID, ['id' => str_repeat('a', 300), 'status' => 'sent']);

        $n = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM whatsapp_delivery_statuses WHERE website_id = ?', [self::WID])[0]['c'];
        $this->assertSame(0, $n);
    }

    public function testEnqueueRejectsInvalidEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WhatsAppWebhookInbox::enqueue(0, 1, 'message', 'k', []);
    }
}
