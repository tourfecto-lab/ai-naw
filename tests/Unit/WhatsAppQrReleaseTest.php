<?php

use PHPUnit\Framework\TestCase;

/** Deleting a website or an account must unlink its WhatsApp QR session at the gateway, not just forget it locally. */
final class WhatsAppQrReleaseTest extends TestCase
{
    public function testEachDeletedWebsiteSessionIsLoggedOutOnce(): void
    {
        $calls = [];
        $gw = new WhatsAppQrGateway('https://gw.example.test', 'key', function (string $method, string $url) use (&$calls) {
            $calls[] = $method . ' ' . parse_url($url, PHP_URL_PATH);
            return ['code' => 200, 'body' => '{}'];
        });

        WhatsAppQrGateway::releaseWebsites([7, '7', 0, 9], $gw);

        $this->assertSame([
            'DELETE /instance/logout/' . WhatsAppQrGateway::instanceName(7),
            'DELETE /instance/delete/' . WhatsAppQrGateway::instanceName(7),
            'DELETE /instance/logout/' . WhatsAppQrGateway::instanceName(9),
            'DELETE /instance/delete/' . WhatsAppQrGateway::instanceName(9),
        ], $calls);
    }

    public function testAGatewayFailureNeverStopsTheDeletion(): void
    {
        $gw = new WhatsAppQrGateway('https://gw.example.test', 'key', function () {
            throw new RuntimeException('gateway down');
        });

        WhatsAppQrGateway::releaseWebsites([3], $gw);

        $this->addToAssertionCount(1);
    }
}
