<?php

use PHPUnit\Framework\TestCase;

/**
 * /r/{token} (report share) is registered before /r/{code} (short ad tracking links), so the router sends every /r/ request
 * to the share controller. Short ad links (10 hex) must still reach AdsController::redirectUtmClick.
 */
final class ReportShareRouteCollisionTest extends TestCase
{
    public function testShareRouteIsRegisteredFirstAndDelegatesNonTokensToAdTracking(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes/web.php');
        preg_match_all("/\\\$router->get\\('\\/r\\/\\{(\\w+)\\}',\\s*'(\\w+)',\\s*'(\\w+)'/", $routes, $m, PREG_SET_ORDER);
        $this->assertCount(2, $m, 'exactly two /r/{x} routes are expected');
        $this->assertSame('SeoReportShareController', $m[0][2], 'router uses the first match');
        $this->assertSame('AdsController', $m[1][2]);

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/SeoReportShareController.php');
        $this->assertStringContainsString('redirectUtmClick', $src, 'non-token /r/ requests must fall through to ad tracking');
        $this->assertStringContainsString('{64}', $src);
    }

    public function testAdCodesAreNeverShapedLikeShareTokens(): void
    {
        $ads = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ads/AdTrackingService.php');
        $this->assertStringContainsString('bin2hex(random_bytes(5))', $ads, 'ad codes are 10 hex chars; a 64-hex code would be shadowed by the share route');
        $share = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Visibility/SeoReportShareService.php');
        $this->assertStringContainsString('bin2hex(random_bytes(32))', $share);
    }
}
