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

    public function testEveryTemplateLanguageUsesTheKitAndUnknownOnesKeepTheLegacyHome(): void
    {
        $c = $this->controller();
        foreach (['ar', 'en', 'fr', 'de', 'it', 'es'] as $lang) {
            $this->assertTrue($this->call($c, 'hotelUsesSiteKit', $this->content($lang)), $lang);
        }
        $this->assertFalse($this->call($c, 'hotelUsesSiteKit', $this->content('ja')), 'languages without room wording keep the single-page hotel home');
    }

    public function testHotelWordingExistsForEveryLanguageWithTheSameKeysAndPlaceholders(): void
    {
        $all = (new ReflectionClass(TourSiteTheme::class))->getReflectionConstant('HOTEL')->getValue();
        $this->assertEqualsCanonicalizing(TourSiteTheme::HOTEL_LANGS, array_keys($all));
        $en = $all['en'];
        foreach (TourSiteTheme::HOTEL_LANGS as $lang) {
            $this->assertArrayHasKey($lang, $all);
            $this->assertSame([], array_diff(array_keys($en), array_keys($all[$lang])), "$lang is missing keys");
            foreach (['cancel_a', 'n_tours'] as $k) {
                $this->assertSame(preg_match_all('/%d|%s/', $en[$k]), preg_match_all('/%d|%s/', $all[$lang][$k]), "$lang $k placeholders");
            }
        }
    }

    public function testHotelPagesInEveryLanguageHaveNoTourWording(): void
    {
        $words = ['fr' => 'excursion', 'de' => 'tour', 'it' => 'escursion', 'es' => 'excursi'];
        foreach ($words as $lang => $word) {
            $content = $this->content($lang);
            foreach (['home', 'tours', 'faq'] as $page) {
                $html = $this->call($this->controller(), 'renderHotelSitePage', $page, 'demo', $content, $this->site($content));
                $text = str_replace('tourfecto', '', mb_strtolower(strip_tags(preg_replace('~<script.*?</script>|<style.*?</style>~is', '', $html))));
                $this->assertStringNotContainsString($word, $text, "$lang $page");
                $this->assertStringContainsString('/sites/demo/rooms/nile-view', $html, "$lang $page");
            }
        }
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
        $this->assertSame('Chambres', $fr->t('tours'));
        $ja = new TourSiteTheme('ja', 'ltr');
        $ja->setHotel(true);
        $this->assertSame('Rooms', $ja->t('tours'), 'a language the theme does not know renders in English, with English room wording');
    }

    public function testHotelHomeLinksToTheInnerPages(): void
    {
        $c = $this->controller();
        $html = $this->call($c, 'renderHotelHome', 'demo', $this->content('en'), 'Nile Royal Hotel', 'Calm', 'https://x.test/sites/demo', '', 'gold', '', 1);
        foreach (['/sites/demo/tours', '/sites/demo/about', '/sites/demo/faq', '/sites/demo/contact', '/sites/demo/terms'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $html);
        }
    }

    public function testHotelHomeUsesTheKitWithRoomsAndKeepsLegacyForOtherLanguages(): void
    {
        $c = $this->controller();
        $html = $this->call($c, 'renderHotelSitePage', 'home', 'demo', $this->content('en'), $this->site($this->content('en')), 'Nile Royal Hotel', 'Calm stay');
        $this->assertStringContainsString('wsx-hero', $html);
        $this->assertStringContainsString('Popular rooms', $html);
        $this->assertStringContainsString('href="/sites/demo/rooms/nile-view"', $html);
        $this->assertStringContainsString('See our rooms', $html);
    }

    public function testRoomPageUsesTheKitAndEmbedsTheBookingFormUntouched(): void
    {
        $content = $this->content('en');
        $content['rooms'][0]['highlights'] = ['Private balcony'];
        $form = '<form id="booking-form-marker" data-x="1"><input name="check_in"><button>Pay & book</button></form>';
        $html = $this->call($this->controller(), 'renderHotelRoomPage', 'demo', 'nile-view', $content['rooms'][0], $content, $this->site($content), 'en', 'ltr', $form);
        $this->assertStringContainsString($form, $html, 'the booking form markup is passed through byte for byte');
        $this->assertGreaterThan(strpos($html, 'class="wsx-bookcard"'), strpos($html, $form), 'inside the booking card');
        $this->assertStringContainsString('Room highlights', $html);
        $this->assertStringContainsString('Private balcony', $html);
        $this->assertStringContainsString('Room amenities', $html);
        $this->assertStringContainsString('Book this room', $html);
        $this->assertStringContainsString('href="/sites/demo/rooms/family-suite"', $html, 'other rooms link to room pages');
        $this->assertStringContainsString('wa.me/201000000000', $html);
        $text = str_replace('tourfecto', '', mb_strtolower(strip_tags(preg_replace('~<script.*?</script>|<style.*?</style>~is', '', $html))));
        $this->assertStringNotContainsString('tour', $text);
    }

    public function testRoomPageWithoutAProductFallsBackToWhatsAppOnly(): void
    {
        $content = $this->content('ar');
        $html = $this->call($this->controller(), 'renderHotelRoomPage', 'demo', 'nile-view', $content['rooms'][0], $content, $this->site($content), 'ar', 'rtl', '');
        $card = substr($html, (int) strpos($html, 'class="wsx-bookcard"'), 1500);
        $this->assertStringNotContainsString('<form', substr($card, 0, (int) strpos($card, '</aside>') ?: 1500), 'no booking form without a linked product');
        $this->assertStringContainsString('href="/sites/demo/rooms/family-suite"', $html);
        $this->assertStringNotContainsString('/sites/demo/tours/', $html, 'footer room links point to room pages');
        $this->assertStringContainsString('احجز على واتساب', $html);
        $this->assertStringContainsString('احجز الغرفة دي', $html);
    }
}
