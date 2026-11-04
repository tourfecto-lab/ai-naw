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

    public function testReserveButtonsAndEscapedCheckoutPathsCountAsBooking(): void
    {
        $this->assertTrue($this->page('<a class="btn">Rezervasyon Yap</a>')['booking_engine']);
        $this->assertTrue($this->page('<a class="btn">Book Now</a>')['booking_engine']);
        $this->assertTrue($this->page('<script>{"url":"https:\/\/x.test\/checkout\/"}</script>')['booking_engine']);
        $this->assertFalse($this->page('<a href="tel:+90000">call</a><p>Contact us to arrange your tour</p>')['booking_engine']);
    }
}
