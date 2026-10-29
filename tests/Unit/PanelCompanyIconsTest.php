<?php

use PHPUnit\Framework\TestCase;

/** The account entry in the sidebar shows the company's Facebook page picture, else its logo, else its favicon (https only). */
final class PanelCompanyIconsTest extends TestCase
{
    public function testPriorityIsFacebookPageThenLogoThenFavicon(): void
    {
        $i = PanelCompanyController::icons('647362815136492', 'https://example.com/logo.png', 'https://www.example.com/tours');
        $this->assertSame([
            'https://graph.facebook.com/647362815136492/picture?type=square&width=96&height=96',
            'https://example.com/logo.png',
            'https://www.example.com/favicon.ico',
        ], $i);
    }

    public function testOnlySafeValuesAreKept(): void
    {
        $this->assertSame([], PanelCompanyController::icons('abc', 'http://insecure.example/logo.png', ''));
        $this->assertSame([], PanelCompanyController::icons('12', 'javascript:alert(1)', 'not a url'));
        $this->assertSame(['https://tourfecto.pro/favicon.ico'], PanelCompanyController::icons('', 'https://x.com/"><script>', 'tourfecto.pro'));
    }
}
