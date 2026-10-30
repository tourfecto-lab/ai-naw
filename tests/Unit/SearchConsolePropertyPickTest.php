<?php

use PHPUnit\Framework\TestCase;

/** Connecting Search Console should not ask the customer to pick a property when their site is obvious. */
final class SearchConsolePropertyPickTest extends TestCase
{
    private function site(string $url, string $perm = 'siteOwner'): array
    {
        return ['site_url' => $url, 'permission_level' => $perm];
    }

    public function testTheOnlyPropertyForTheSiteIsConnectedWithoutAsking(): void
    {
        $sites = [$this->site('https://other-company.com/'), $this->site('https://www.tourfecto.com/')];
        $matches = SearchConsoleController::matchingProperties($sites, 'https://tourfecto.com');
        $this->assertSame('https://www.tourfecto.com/', SearchConsoleController::autoProperty($matches));
    }

    public function testADomainPropertyWinsOverUrlPrefixesOfTheSameSite(): void
    {
        $sites = [$this->site('http://tourfecto.com/'), $this->site('https://tourfecto.com/'), $this->site('sc-domain:tourfecto.com')];
        $matches = SearchConsoleController::matchingProperties($sites, 'tourfecto.com');
        $this->assertSame('sc-domain:tourfecto.com', $matches[0]['site_url']);
        $this->assertSame('sc-domain:tourfecto.com', SearchConsoleController::autoProperty($matches));
    }

    public function testSeveralUrlPrefixesStillLetTheCustomerChooseWithHttpsFirst(): void
    {
        $matches = SearchConsoleController::matchingProperties([$this->site('http://tourfecto.com/'), $this->site('https://tourfecto.com/')], 'https://tourfecto.com');
        $this->assertNull(SearchConsoleController::autoProperty($matches));
        $this->assertSame('https://tourfecto.com/', $matches[0]['site_url']);
    }

    public function testUnverifiedOrOtherSitesAreNeverPickedAutomatically(): void
    {
        $this->assertNull(SearchConsoleController::autoProperty(SearchConsoleController::matchingProperties([$this->site('sc-domain:tourfecto.com', 'siteUnverifiedUser')], 'tourfecto.com')));
        $this->assertSame([], SearchConsoleController::matchingProperties([$this->site('sc-domain:nottourfecto.com')], 'tourfecto.com'));
    }
}
