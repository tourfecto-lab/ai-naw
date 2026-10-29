<?php

use PHPUnit\Framework\TestCase;

/** Hotel sites get the same multi-page professional kit as tour sites, with room wording (Arabic / English). */
final class HotelSitePagesTest extends TestCase
{
    private function content(string $lang = 'ar'): array
    {
        return [
            'language' => $lang, 'industry' => 'hotel', 'business_name' => 'Nile Royal Hotel', 'tagline' => 'Calm stay',
            'about' => 'Family run hotel.', 'contact' => ['whatsapp' => '+201000000000', 'email' => 'info@example.test'],
            'rooms' => [
                ['slug' => 'nile-view', 'name' => 'Nile View Room', 'short_description' => 'Balcony over the Nile.', 'price' => '2200 EGP', 'amenities' => ['WiFi', 'Breakfast']],
                ['slug' => 'family-suite', 'name' => 'Family Suite', 'short_description' => 'Two bedrooms.', 'price' => '3800 EGP', 'amenities' => ['WiFi']],
            ],
        ];
    }

    private function controller(): WebsiteBuilderController
    {
        $c = (new ReflectionClass(WebsiteBuilderController::class))->newInstanceWithoutConstructor();
        $p = new ReflectionProperty(Controller::class, 'db');
        $p->setAccessible(true);
        $p->setValue($c, Database::getInstance());
        return $c;
    }

    private function call(object $c, string $method, ...$args)
    {
        $m = new ReflectionMethod($c, $method);
        $m->setAccessible(true);
        return $m->invoke($c, ...$args);
    }

    private function site(array $content): GeneratedWebsite
    {
        $w = new GeneratedWebsite();
        $w->fill(['id' => 1, 'user_id' => 1, 'slug' => 'demo', 'status' => 'published', 'theme_color' => 'gold', 'content_json' => json_encode($content, JSON_UNESCAPED_UNICODE)]);
        return $w;
    }

    public function testRoomWordingReplacesTourWordingInArabicAndEnglish(): void
    {
        foreach (['ar' => 'رحلة', 'en' => 'tour'] as $lang => $forbidden) {
            $html = $this->call($this->controller(), 'renderHotelSitePage', 'tours', 'demo', $this->content($lang), $this->site($this->content($lang)));
            $text = str_replace('tourfecto', '', mb_strtolower(strip_tags(preg_replace('~<script.*?</script>|<style.*?</style>~is', '', $html))));
            $this->assertStringNotContainsString($forbidden, $text, "no tour wording left in the $lang hotel page");
            $this->assertStringContainsString('Nile View Room', $html);
        }
    }

    public function testRoomCardsLinkToRoomPagesNotTourPages(): void
    {
        $html = $this->call($this->controller(), 'renderHotelSitePage', 'tours', 'demo', $this->content('en'), $this->site($this->content('en')));
        $this->assertStringContainsString('href="/sites/demo/rooms/nile-view"', $html);
        $this->assertStringContainsString('href="/sites/demo/rooms/family-suite"', $html);
        $this->assertStringNotContainsString('/sites/demo/tours/nile-view', $html);
    }

    public function testFaqAboutAndContactPagesRenderForAHotel(): void
    {
        foreach (['faq', 'about', 'contact'] as $page) {
            $html = $this->call($this->controller(), 'renderHotelSitePage', $page, 'demo', $this->content('en'), $this->site($this->content('en')));
            $this->assertStringContainsString('Nile Royal Hotel', $html, $page);
            $this->assertStringContainsString('<main', $html, $page);
        }
        $faq = $this->call($this->controller(), 'renderHotelSitePage', 'faq', 'demo', $this->content('en'), $this->site($this->content('en')));
        $this->assertStringContainsString('How do I book a room?', $faq);
    }

    public function testOnlyArabicAndEnglishHotelsUseTheKit(): void
    {
        $c = $this->controller();
        $this->assertTrue($this->call($c, 'hotelUsesSiteKit', $this->content('ar')));
        $this->assertTrue($this->call($c, 'hotelUsesSiteKit', $this->content('en')));
        $this->assertFalse($this->call($c, 'hotelUsesSiteKit', $this->content('fr')), 'other languages keep the single-page hotel home');
    }

    public function testThemeHotelModeIsOffByDefaultAndFallsBackPerKey(): void
    {
        $t = new TourSiteTheme('en', 'ltr');
        $this->assertSame('Tours', $t->t('tours'));
        $t->setHotel(true);
        $this->assertSame('Rooms', $t->t('tours'));
        $this->assertSame('Menu', $t->t('menu'), 'keys without a hotel word fall back to the normal text');
        $fr = new TourSiteTheme('fr', 'ltr');
        $fr->setHotel(true);
        $this->assertNotSame('Rooms', $fr->t('tours'), 'hotel mode is limited to ar / en');
    }

    public function testHotelHomeLinksToTheInnerPages(): void
    {
        $c = $this->controller();
        $html = $this->call($c, 'renderHotelHome', 'demo', $this->content('en'), 'Nile Royal Hotel', 'Calm', 'https://x.test/sites/demo', '', 'gold', '', 1);
        foreach (['/sites/demo/tours', '/sites/demo/about', '/sites/demo/faq', '/sites/demo/contact', '/sites/demo/terms'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $html);
        }
    }
}
