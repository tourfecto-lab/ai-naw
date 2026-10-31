<?php

use PHPUnit\Framework\TestCase;

/** Company names copied from Google Maps carry invisible direction marks that break the Places lookup and show up in emails. */
final class OutreachCleanNameTest extends TestCase
{
    public function testDirectionMarksAreRemovedAndRealTextIsKept(): void
    {
        $this->assertSame('Nile Dream Tours', OutreachAudit::cleanName("\u{202A}Nile Dream Tours\u{202C}"));
        $this->assertSame('رماسيد تورز - رحلات يومية خاصة', OutreachAudit::cleanName("\u{200F}رماسيد تورز - رحلات يومية خاصة\u{200E}"));
        $this->assertSame('Gem Explore Egypt', OutreachAudit::cleanName("  \u{2066}Gem Explore Egypt\u{2069}\u{FEFF} "));
    }

    public function testPlainAndEmptyNamesAreUntouched(): void
    {
        $this->assertSame('Travel Joy', OutreachAudit::cleanName('Travel Joy'));
        $this->assertSame('', OutreachAudit::cleanName("\u{202A}\u{202C}"));
    }
}
