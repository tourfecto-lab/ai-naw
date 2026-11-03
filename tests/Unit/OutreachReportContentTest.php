<?php

use PHPUnit\Framework\TestCase;

/** The report and the messages must not promise what is not live or state unsupported facts, and must not leak internal labels. */
final class OutreachReportContentTest extends TestCase
{
    /** @return string[] every string constant of the class, flattened */
    private function allStrings(string $class): array
    {
        $out = [];
        $walk = static function ($v) use (&$walk, &$out): void {
            if (is_string($v)) {
                $out[] = $v;
            } elseif (is_array($v)) {
                foreach ($v as $x) {
                    $walk($x);
                }
            }
        };
        foreach ((new ReflectionClass($class))->getConstants() as $c) {
            $walk($c);
        }
        return $out;
    }

    public function testNoTextPromisesWeeklyArticlesOrAutoPublishing(): void
    {
        // weekly articles and keyword tracking are paused by the data budget, and publishing to Facebook/Instagram depends on the Meta review
        $banned = ['كل أسبوع', 'أسبوعية', 'لوحدها', 'لوحده', 'بتتنشر', 'بينشرها', 'every week', 'weekly', 'auto-published', 'publishes them for you', 'her hafta', 'haftalık', 'otomatik yayın',
            'تتبّع الكلمات', 'keyword and ranking tracking', 'sıralama takibi', 'published to your site', 'yayınlanan'];
        foreach ([OutreachNeeds::class, OutreachAudit::class] as $class) {
            foreach ($this->allStrings($class) as $s) {
                foreach ($banned as $b) {
                    $this->assertStringNotContainsStringIgnoringCase($b, $s, "$class promises \"$b\": " . mb_substr($s, 0, 80));
                }
            }
        }
    }

    public function testNoTextStatesUnsourcedFactsAboutTravellers(): void
    {
        $banned = ['أكتر أماكن المسافرين', 'أكتر طريقة شركات', 'أغلب المسافرين', 'most travellers', 'most tour companies', 'gezginlerin çoğu', 'tur şirketlerinin çoğu', 'en çok buralarda'];
        foreach ($this->allStrings(OutreachAudit::class) as $s) {
            foreach ($banned as $b) {
                $this->assertStringNotContainsStringIgnoringCase($b, $s, "unsourced claim \"$b\"");
            }
        }
    }

    public function testArabicTextsUseOneVoiceNotAMixOfISingularAndWePlural(): void
    {
        foreach ($this->allStrings(OutreachAudit::class) as $s) {
            $this->assertStringNotContainsString('مالقيتش', $s, 'we, not I: مالقيناش');
        }
    }

    public function testInternalCategoryLabelsAreNeverShownToTheCompany(): void
    {
        $this->assertSame('', OutreachAudit::publicCategory('attraction'));
        $this->assertSame('', OutreachAudit::publicCategory(' Attraction '));
        $this->assertSame('', OutreachAudit::publicCategory(null));
        $this->assertSame('رحلات خاصة', OutreachAudit::publicCategory("\u{202A}رحلات خاصة\u{202C}, الرحلات النهارية"));
        $this->assertSame('Tours', OutreachAudit::publicCategory('Tours, Outdoor Activities'));
        $this->assertSame('الجولات السياحية', OutreachAudit::GENERIC_CATEGORY['ar']);
    }

    public function testZeroPhotosOnTripadvisorIsARealGapNotUnknown(): void
    {
        $lead = ['reviews' => 454, 'rating' => '4.9', 'domain' => 'x.test', 'website' => 'https://x.test'];
        $gapIds = static fn (array $ta) => array_column(OutreachAudit::gaps($lead, $ta, null, ['checked' => false], []), 'id');
        $this->assertContains('few_photos', $gapIds(['photos' => 0]));
        $this->assertContains('few_photos', $gapIds(['photos' => 5]));
        $this->assertNotContains('few_photos', $gapIds(['photos' => 200]));
        $this->assertNotContains('few_photos', $gapIds([]), 'no photo count at all: unknown, not a gap');
    }

    public function testBenchmarkTextNeverShowsTheInternalCategoryAndLongNamesAreShortened(): void
    {
        $lead = ['company' => 'Nile Tours', 'domain' => 'nile.test'];
        $text = OutreachAudit::gapText(['id' => 'few_reviews', 'vars' => ['reviews' => 78, 'median' => 453, 'category' => 'attraction']], $lead, 'ar');
        $this->assertStringNotContainsString('attraction', $text);
        $this->assertStringContainsString('الجولات السياحية', $text);

        $long = 'Amazing Cairo local guide - licensed Egyptologist specializing in tours, excursions and sightseeing around Cairo and surrounding areas';
        $this->assertSame('Amazing Cairo local guide', OutreachAudit::shortName($long));
        $this->assertSame('Nile Tours', OutreachAudit::shortName('Nile Tours'));
    }

    public function testGuestFollowUpTextDoesNotClaimThousandsOfGuestsFromAFewHundredReviews(): void
    {
        $text = OutreachAudit::gapText(['id' => 'many_guests', 'vars' => ['reviews' => 305]], ['company' => 'X'], 'ar');
        $this->assertStringNotContainsString('آلاف', $text);
        $this->assertStringNotContainsString('أغلبهم', $text);
    }

    public function testASiteIsOnlyCalledDownWhenWeAreSureOfIt(): void
    {
        $this->assertSame('down', PlatformOutreachService::classifyStatus(0, 'Could not resolve host: x.test'));
        $this->assertSame('down', PlatformOutreachService::classifyStatus(503, ''));
        $this->assertNotSame('down', PlatformOutreachService::classifyStatus(404, ''), 'a 404 home page is not a dead site');
        $this->assertNotSame('down', PlatformOutreachService::classifyStatus(0, 'Operation timed out after 25000 ms'));
        $this->assertNotSame('down', PlatformOutreachService::classifyStatus(0, 'schannel: SEC_E_ILLEGAL_MESSAGE'));
    }

    public function testGoogleMissingIsWordedAsOurSearchNotAsAFactAboutThem(): void
    {
        $text = OutreachAudit::gapText(['id' => 'no_google', 'vars' => []], ['company' => 'Nile Tours', 'domain' => 'nile.test'], 'ar');
        $this->assertStringContainsString('بالاسم ده', $text);
        $this->assertStringContainsString('مالقيناش', $text);
    }
}
