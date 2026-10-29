<?php

use PHPUnit\Framework\TestCase;

/** The generated customer sites need complete share / search metadata, and private pages must stay out of search. */
final class SiteHeadSeoTest extends TestCase
{
    private function head(...$args): string
    {
        $c = (new ReflectionClass(WebsiteBuilderController::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($c, 'siteHeadHtml');
        $m->setAccessible(true);
        return $m->invoke($c, ...$args);
    }

    public function testPublicPageCarriesTwitterCardThemeColorAndFavicon(): void
    {
        $h = $this->head('Room | Hotel', 'gold', 'A calm room', 'https://x.test/rooms/a', 'https://x.test/a.jpg');
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $h);
        $this->assertStringContainsString('name="twitter:image" content="https://x.test/a.jpg"', $h);
        $this->assertStringContainsString('name="theme-color"', $h);
        $this->assertStringContainsString('rel="icon"', $h);
        $this->assertStringNotContainsString('noindex', $h);
    }

    public function testWithoutAnImageTheCardIsTheSmallOne(): void
    {
        $h = $this->head('Home', 'gold', 'd', 'https://x.test/');
        $this->assertStringContainsString('content="summary"', $h);
        $this->assertStringNotContainsString('twitter:image', $h);
    }

    public function testPrivatePagesCanBeMarkedNoindex(): void
    {
        $this->assertStringContainsString('name="robots" content="noindex,nofollow"', $this->head('Booking', 'gold', null, null, null, '', true));
    }
}
