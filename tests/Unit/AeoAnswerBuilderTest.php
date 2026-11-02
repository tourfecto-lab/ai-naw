<?php

use PHPUnit\Framework\TestCase;

final class AeoAnswerBuilderTest extends TestCase
{
    private const TEXT = 'Home Aswan Nubian Village Trip ⏱ Duration 4 hours 📍 Location Aswan 🚐 Pick-up From your hotel 🎓 Guide Licensed Egyptologist ↺ Cancellation Free up to 24h Overview Quick answer: lasts 4 hours, from $60.00 per person.';

    private function page(string $text = self::TEXT, ?string $price = '60.00', string $lang = 'en'): array
    {
        return ['url' => 'https://x.com/tour/nubian', 'lang' => $lang, 'text' => $text, 'schema' => [[
            '@type' => 'TouristTrip', 'name' => 'Nubian Village Trip',
            'offers' => $price === null ? [] : ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'USD'],
        ]]];
    }

    private function byKind(array $d): array
    {
        return array_column($d['pairs'], null, 'kind');
    }

    public function testEveryAnswerHasASourceAndLiteralEvidence(): void
    {
        $d = AeoAnswerBuilder::draft($this->page());
        $this->assertSame('drafted', $d['state']);
        $p = $this->byKind($d);
        $this->assertSame(['duration', 'price', 'cancellation', 'pickup', 'guide'], array_keys($p));
        foreach ($d['pairs'] as $pair) {
            $this->assertNotSame('', $pair['source']);
            $this->assertNotSame('', $pair['evidence']);
            $this->assertSame([], AeoAnswerBuilder::foreignNumbers($pair['answer'], $pair['evidence'] . ' ' . $pair['question']), $pair['kind']);
        }
        $this->assertSame('Duration: 4 hours.', $p['duration']['answer']);
        $this->assertSame('From USD 60.00 per person.', $p['price']['answer']); // literal, not rounded to "60"
        $this->assertSame('Cancellation: Free up to 24h.', $p['cancellation']['answer']);
        $this->assertStringNotContainsString('before', $p['cancellation']['answer']); // no invented policy wording
        $this->assertSame($d['missing'], []);
    }

    public function testMissingFieldsGenerateNoAnswerAndAreReported(): void
    {
        $d = AeoAnswerBuilder::draft($this->page('Home Aswan Nubian Village Trip Overview a lovely boat ride.', null));
        $this->assertSame('no_stated_facts', $d['state']);
        $this->assertSame([], $d['pairs']);
        $this->assertSame(['duration', 'price', 'cancellation', 'pickup', 'guide'], array_column($d['missing'], 'kind'));
        $this->assertStringStartsWith('بيانات ناقصة: أضف', $d['missing'][0]['add_ar']);
        $this->assertStringStartsWith('Missing data: add', $d['missing'][0]['add_en']);
    }

    public function testNoPriceInSchemaMeansNoPriceAnswerEvenIfTextHasADollarFigure(): void
    {
        $d = AeoAnswerBuilder::draft($this->page(self::TEXT, null));
        $this->assertArrayNotHasKey('price', $this->byKind($d));
        $this->assertContains('price', array_column($d['missing'], 'kind'));
    }

    public function testNonEnglishAndNoTripPagesAreRefused(): void
    {
        $this->assertSame('unsupported_language', AeoAnswerBuilder::draft($this->page(self::TEXT, '60.00', 'fr'))['state']);
        $this->assertSame('no_trip_found', AeoAnswerBuilder::draft(['url' => 'https://x.com/', 'lang' => 'en', 'text' => 'hi', 'schema' => []])['state']);
    }

    public function testBuildRejectsNumbersLinksAndMissingEvidence(): void
    {
        $ok = ['question' => 'How long?', 'answer' => 'Duration: 4 hours.', 'evidence' => 'Duration 4 hours'];
        $badNumber = ['question' => 'How long?', 'answer' => 'Duration: 5 hours.', 'evidence' => 'Duration 4 hours'];
        $badPrice = ['question' => 'Cost?', 'answer' => 'From USD 45.', 'evidence' => 'offers.price = USD 60.00'];
        $link = ['question' => 'Q?', 'answer' => 'See https://evil.test', 'evidence' => 'x'];
        $noEv = ['question' => 'Q?', 'answer' => 'A.', 'evidence' => ''];
        $out = AeoAnswerBuilder::build([$ok, $badNumber, $badPrice, $link, $noEv]);
        $this->assertSame(1, $out['count']);
        $this->assertSame(['number_not_in_evidence', 'number_not_in_evidence', 'link', 'no_evidence'], array_column($out['rejected'], 'reason'));
        $this->assertTrue($out['validation']['valid']);
        $this->assertStringContainsString('"FAQPage"', $out['script']);
        $this->assertSame(0, AeoAnswerBuilder::build([$badNumber])['count']);
    }

    public function testBuildStripsHtmlFromEditedText(): void
    {
        $out = AeoAnswerBuilder::build([['question' => 'How long?', 'answer' => 'Duration: <b>4 hours</b>.', 'evidence' => 'Duration 4 hours']]);
        $this->assertStringNotContainsString('<b>', json_encode($out['jsonld']));
    }

    public function testReadinessDetectsExistingFaqAndSameSiteGuard(): void
    {
        $page = $this->page();
        $page['schema'][] = ['@context' => 'https://schema.org', '@graph' => [['@type' => 'FAQPage', 'mainEntity' => []]]];
        $this->assertTrue(AeoAnswerBuilder::draft($page)['readiness']['has_faq_schema']);
        $this->assertTrue(SeoSameSiteUrl::allowed('https://www.x.com', 'https://x.com/a'));
        $this->assertFalse(SeoSameSiteUrl::allowed('https://x.com', 'http://x.com/a'));
        $this->assertFalse(SeoSameSiteUrl::allowed('https://x.com', 'https://evil.com/a'));
        $this->assertFalse(SeoSameSiteUrl::allowed('https://x.com', 'https://u:p@x.com/a'));
        $this->assertFalse(SeoSameSiteUrl::allowed('https://x.com', 'https://x.com.evil.com/a'));
    }

    public function testArabicPageDraftsLiteralAnswersAndReportsMissingInArabic(): void
    {
        $text = 'رحلة النوبة المدة: ٤ ساعات الإلغاء: مجاني حتى 24 ساعة الاستقبال: من الفندق المرشد: معتمد مصري للشخص';
        $page = ['url' => 'https://x.com/ar/tour/nubian', 'lang' => 'ar', 'text' => $text, 'schema' => [['@type' => 'TouristTrip', 'name' => 'رحلة النوبة', 'offers' => ['@type' => 'Offer', 'price' => '60.00', 'priceCurrency' => 'USD']]]];
        $d = AeoAnswerBuilder::draft($page);
        $by = array_column($d['pairs'], null, 'kind');
        $this->assertSame('كم مدة رحلة النوبة؟', $by['duration']['question']);
        $this->assertSame('المدة: ٤ ساعات.', $by['duration']['answer']);
        $this->assertSame('الإلغاء: مجاني حتى 24 ساعة.', $by['cancellation']['answer']);
        $this->assertSame('يبدأ من USD 60.00 للشخص.', $by['price']['answer']);
        $this->assertSame('الاستقبال: من الفندق.', $by['pickup']['answer']);
        foreach ($d['pairs'] as $pair) {
            $this->assertSame([], AeoAnswerBuilder::foreignNumbers($pair['answer'], $pair['evidence'] . ' ' . $pair['question']), $pair['kind']);
        }
        $noOffer = [['@type' => 'TouristTrip', 'name' => 'رحلة النوبة']];
        $none = AeoAnswerBuilder::draft(['url' => 'u', 'lang' => 'ar', 'text' => 'رحلة جميلة', 'schema' => $noOffer]);
        $this->assertSame('no_stated_facts', $none['state']);
        $this->assertStringStartsWith('بيانات ناقصة: أضف', $none['missing'][0]['add_ar']);
        $this->assertSame('unsupported_language', AeoAnswerBuilder::draft(['lang' => 'fr'] + $page)['state']);
    }

    public function testArabicIndicDigitsAreCheckedAgainstEvidence(): void
    {
        $this->assertSame([], AeoAnswerBuilder::foreignNumbers('المدة: ٤ ساعات', 'المدة: ٤ ساعات'));
        $this->assertSame(['٥'], AeoAnswerBuilder::foreignNumbers('المدة: ٥ ساعات', 'المدة: ٤ ساعات'));
    }

    public function testCoverageMeasurementIsHonestAboutSampleSizeAndAge(): void
    {
        $m = AeoCoverageStore::measurement(['total' => 4, 'with_faq' => 1, 'with_howto' => 0, 'last_checked' => '2026-10-01 00:00:00'], '2026-11-02 00:00:00');
        $this->assertSame(25, $m['score']);
        $this->assertSame('low', $m['confidence']);
        $this->assertTrue($m['stale']);
        $this->assertNull(AeoCoverageStore::measurement(['total' => 0, 'with_faq' => 0, 'with_howto' => 0, 'last_checked' => null], 'x'));
        $this->assertNull(AeoCoverageStore::measurement(null, 'x'));
        $this->assertTrue(SeoSchemaBuilder::isTourPath('/tour/nile-day-trip'));
        $this->assertFalse(SeoSchemaBuilder::isTourPath('/about'));
    }
}
