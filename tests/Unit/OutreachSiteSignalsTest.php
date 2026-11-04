<?php

use PHPUnit\Framework\TestCase;

/** "No WhatsApp / no online booking" is said only when the page shows no sign of either, even when links are JSON-escaped or are buttons. */
final class OutreachSiteSignalsTest extends TestCase
{
    private function page(string $body): array
    {
        return OutreachAudit::parsePage('<html><head><title>T</title></head><body>' . $body . '</body></html>', 'https://x.test/');
    }

    public function testWhatsappInJsonWithEscapedSlashesOrAsAnIconIsStillWhatsapp(): void
    {
        $this->assertTrue($this->page('<script>var c={"link":"https:\/\/api.whatsapp.com\/send?phone=90"}</script>')['whatsapp']);
        $this->assertTrue($this->page('<a href="https://wa.me/905551112233">x</a>')['whatsapp']);
        $this->assertTrue($this->page('<i class="fa fa-whatsapp"></i>')['whatsapp']);
        $this->assertFalse($this->page('<a href="mailto:a@b.test">mail</a>')['whatsapp']);
    }

    public function testTurkishAndEnglishMessagesContainNoArabicPunctuationAndTheRankingIsLocalised(): void
    {
        $this->assertSame('5. sıra / 147 · Concerts & Shows, Istanbul', OutreachNeeds::localizeRanking('#5 of 147 Concerts & Shows in Istanbul', 'tr'));
        $this->assertSame('#5 of 147 Concerts & Shows in Istanbul', OutreachNeeds::localizeRanking('#5 of 147 Concerts & Shows in Istanbul', 'en'));
        $this->assertSame('رقم 267 من 2,709 رحلات وأنشطة في القاهرة', OutreachNeeds::localizeRanking('رقم 267 من 2,709 رحلات وأنشطة في القاهرة', 'ar'));
        foreach (['tr', 'en'] as $lang) {
            $m = OutreachNeeds::deskMessage(['lang' => $lang, 'company' => 'X Tours', 'name' => 'Lamar', 'title' => '', 'trial' => '', 'report_url' => 'https://tourfecto.pro/p/r/abc?s=1', 'signup_url' => 'https://tourfecto.pro/p/c/abc/1',
                'seo' => ['state' => 'missing', 'fails' => ['title', 'description', 'h1']],
                'gaps' => [['id' => 'no_booking', 'vars' => []], ['id' => 'no_chat', 'vars' => []], ['id' => 'one_language', 'vars' => []]],
                'scorecard' => ['tripadvisor' => ['status' => 'found', 'rating' => 4.3, 'reviews' => 214, 'ranking' => '#5 of 147 Concerts & Shows in Istanbul', 'photos' => 5, 'negative' => 40],
                    'google' => ['status' => 'found', 'rating' => 4.5, 'reviews' => 50]]]);
            $this->assertSame(0, preg_match('/[\x{0600}-\x{06FF}]/u', $m), "$lang message must not contain Arabic characters (an Arabic comma used to leak in)");
        }
    }

    public function testTripadvisorShoppingFoodAndNightlifeCategoriesAreFlaggedForAHumanCheck(): void
    {
        $this->assertSame('تسوق', OutreachNeeds::nonTourSignal('رقم 6 من 300 تسوق في القاهرة'));
        $this->assertSame('أطعمة ومشروبات', OutreachNeeds::nonTourSignal('رقم 13 من 345 أطعمة ومشروبات في القاهرة'));
        $this->assertSame('Concerts', OutreachNeeds::nonTourSignal('#5 of 147 Concerts & Shows in Istanbul'));
        $this->assertSame('Shopping', OutreachNeeds::nonTourSignal('', 'Shopping, Tours'));
        $this->assertNull(OutreachNeeds::nonTourSignal('رقم 2 من 584 رحلات بالقوارب ورياضات مائية في القاهرة', 'Tours'));
        $this->assertNull(OutreachNeeds::nonTourSignal('#360 of 2,579 Tours in Istanbul'));
    }

    public function testStarterPriceFollowsTheSettingsInsteadOfAHardCodedNumber(): void
    {
        $this->assertSame('3,950 جنيه', OutreachNeeds::starterPrice('EG', 'ar'));
        $this->assertSame('EGP 4,500', OutreachNeeds::starterPrice('EG', 'en', 90.0, 50.0));
        $this->assertSame('5,151 جنيه', OutreachNeeds::starterPrice('EG', 'ar', 79.0, 65.2), '79 x 65.2 = 5150.8, rounded');
        $this->assertSame('$79', OutreachNeeds::starterPrice('TR', 'tr'));
        $this->assertSame('$89.5', OutreachNeeds::starterPrice('TR', 'en', 89.5, 50.0));
    }

    public function testReserveButtonsAndEscapedCheckoutPathsCountAsBooking(): void
    {
        $this->assertTrue($this->page('<a class="btn">Rezervasyon Yap</a>')['booking_engine']);
        $this->assertTrue($this->page('<a class="btn">Book Now</a>')['booking_engine']);
        $this->assertTrue($this->page('<script>{"url":"https:\/\/x.test\/checkout\/"}</script>')['booking_engine']);
        $this->assertFalse($this->page('<a href="tel:+90000">call</a><p>Contact us to arrange your tour</p>')['booking_engine']);
    }

    public function testLinkPreviewBotsAreNotCountedAsTheCompanyOpeningTheReport(): void
    {
        foreach (['WhatsApp/2.23.20.0 A', 'facebookexternalhit/1.1', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'TelegramBot (like TwitterBot)', ''] as $ua) {
            $this->assertTrue(PlatformOutreachService::isLinkBot($ua), $ua);
        }
        $this->assertFalse(PlatformOutreachService::isLinkBot('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertFalse(PlatformOutreachService::isLinkBot('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36'));
    }
}
