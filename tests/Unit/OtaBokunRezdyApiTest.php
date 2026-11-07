<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/OTA/OtaSupplierHttp.php';
require_once __DIR__ . '/../../app/Services/OTA/BokunAPI.php';
require_once __DIR__ . '/../../app/Services/OTA/RezdyAPI.php';

/** Bokun and Rezdy clients run on a fake transport, with no real network call. */
class OtaBokunRezdyApiTest extends TestCase
{
    private function transport(string $body, int $code = 200, array &$calls = []): callable
    {
        return function ($method, $url, $headers, $payload) use ($body, $code, &$calls) {
            $calls[] = compact('method', 'url', 'headers', 'payload');
            return ['response' => $body, 'http_code' => $code, 'error' => false];
        };
    }

    public function testBokunSignatureIsDeterministicHmac(): void
    {
        $expected = base64_encode(hash_hmac('sha1', 'DKPOST/p', 'S', true));
        $this->assertSame($expected, BokunAPI::signature('D', 'K', 'S', 'post', '/p'));
    }

    public function testBokunRejectsBadConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BokunAPI('k', 's', 0);
    }

    public function testBokunSandboxUsesTestHostAndSignedHeaders(): void
    {
        $calls = [];
        $api = new BokunAPI('key', 'secret', 5, 'sandbox', $this->transport('{"items":[]}', 200, $calls));
        $r = $api->products(2);
        $this->assertTrue($r['success']);
        $this->assertStringStartsWith('https://api.bokuntest.com/', $calls[0]['url']);
        $h = implode("\n", $calls[0]['headers']);
        $this->assertStringContainsString('X-Bokun-AccessKey: key', $h);
        $this->assertStringContainsString('X-Bokun-Signature: ', $h);
        $this->assertSame(2, json_decode($calls[0]['payload'], true)['page']);
    }

    public function testBokunVerifyTokenFlagsUnexpectedShape(): void
    {
        $api = new BokunAPI('k', 's', 5, 'production', $this->transport('{"foo":1}'));
        $this->assertFalse($api->verifyToken()['success']);
    }

    public function testBokunAvailabilityRejectsNonNumericId(): void
    {
        $api = new BokunAPI('k', 's', 5, 'production', $this->transport('{}'));
        $this->expectException(InvalidArgumentException::class);
        $api->availability('1; DROP', '2026-01-01', '2026-01-02');
    }

    public function testRezdyRejectsBadEnvironment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RezdyAPI('k', 'staging');
    }

    public function testRezdyRejectsBadPage(): void
    {
        $api = new RezdyAPI('k', 'production', $this->transport('{}'));
        $this->expectException(InvalidArgumentException::class);
        $api->products(0);
    }

    public function testRezdyRejectedRequestStatusIsFailure(): void
    {
        $api = new RezdyAPI('k', 'production', $this->transport('{"requestStatus":{"success":false}}'));
        $r = $api->products();
        $this->assertFalse($r['success']);
        $this->assertSame('Rezdy rejected the request', $r['error']);
    }

    public function testRezdyVerifyTokenOkAndPaginationOffset(): void
    {
        $calls = [];
        $api = new RezdyAPI('k', 'sandbox', $this->transport('{"requestStatus":{"success":true},"products":[]}', 200, $calls));
        $this->assertTrue($api->verifyToken()['success']);
        $api->products(3);
        $this->assertStringContainsString('api.rezdy-staging.com', $calls[1]['url']);
        $this->assertStringContainsString('offset=100', $calls[1]['url']);
    }

    public function testRezdyAvailabilityRejectsBadCode(): void
    {
        $api = new RezdyAPI('k', 'production', $this->transport('{}'));
        $this->expectException(InvalidArgumentException::class);
        $api->availability('a b', '2026-01-01', '2026-01-02');
    }

    /** @dataProvider httpCodes */
    public function testHttpErrorsMapToSafeMessages(int $code, string $needle): void
    {
        $api = new RezdyAPI('secret-key-123', 'production', $this->transport('{}', $code));
        $r = $api->products();
        $this->assertFalse($r['success']);
        $this->assertStringContainsString($needle, $r['error']);
        $this->assertStringNotContainsString('secret-key-123', $r['error']);
    }

    public static function httpCodes(): array
    {
        return [[401, 'denied access'], [403, 'denied access'], [429, 'rate limit'], [500, 'HTTP 500']];
    }

    public function testRejectsHeaderInjection(): void
    {
        $api = new RezdyAPI("bad\r\nX: y", 'production', $this->transport('{}'));
        $this->expectException(InvalidArgumentException::class);
        $api->products();
    }

    public function testRejectsForeignHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OtaSupplierHttp::request('GET', 'https://evil.com/x', [], null, $this->transport('{}'));
    }
}
