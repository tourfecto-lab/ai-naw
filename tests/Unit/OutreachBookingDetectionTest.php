<?php

use PHPUnit\Framework\TestCase;

/** A JavaScript booking app has no /checkout link in its HTML: it must not be reported as "no online booking". */
final class OutreachBookingDetectionTest extends TestCase
{
    public function testBookingAppInsideScriptJsonIsDetected(): void
    {
        $html = '<html><body><div id="root"></div><script>window.__D={"book_now":"Book Now","checkout":{"safe_secure":"Safe & secure checkout"}}</script></body></html>';
        $this->assertTrue(OutreachAudit::parsePage($html, 'https://x.test/')['booking_engine']);
    }

    public function testEscapedJsonKeysAreDetectedToo(): void
    {
        $html = '<script>self.__next_f.push([1,"{\"book_now\":\"Book Now\",\"checkout\":{\"total_amount\":\"Total\"}}"])</script>';
        $this->assertTrue(OutreachAudit::parsePage($html, 'https://x.test/')['booking_engine']);
    }

    public function testPlainSiteWithAWhatsappInquiryIsNotABookingEngine(): void
    {
        $html = '<html><body><a href="https://wa.me/201000000000?text=I%20would%20like%20to%20inquire%20about%20booking">WhatsApp</a></body></html>';
        $this->assertFalse(OutreachAudit::parsePage($html, 'https://x.test/')['booking_engine']);
    }
}
