<?php

use PHPUnit\Framework\TestCase;

final class SeoSchemaValidatorTest extends TestCase
{
    private function codes(array $r, string $sev = null): array
    {
        return array_values(array_map(static fn ($i) => $i['code'] . ':' . $i['detail'], array_filter($r['issues'], static fn ($i) => $sev === null || $i['severity'] === $sev)));
    }

    public function testValidTouristTripPasses(): void
    {
        $r = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@graph' => [[
            '@type' => 'TouristTrip', 'name' => 'Nile', 'description' => 'd', 'image' => 'https://x.com/a.jpg',
            'provider' => ['@type' => 'TravelAgency', 'name' => 'T', 'url' => 'https://x.com/', 'address' => 'Cairo', 'telephone' => '1', 'image' => 'https://x.com/l.png'],
            'offers' => ['@type' => 'Offer', 'price' => '60.00', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock', 'url' => 'https://x.com/t'],
        ]]]);
        $this->assertTrue($r['valid'], json_encode($r['issues']));
        $this->assertSame(0, $r['errors']);
    }

    public function testMissingRequiredAndBadPriceAreErrors(): void
    {
        $r = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@type' => 'Offer', 'price' => 'free', 'priceCurrency' => 'usd']);
        $this->assertFalse($r['valid']);
        $this->assertContains('bad_price:', $this->codes($r, 'error'));
        $this->assertContains('bad_currency:', $this->codes($r, 'error'));
    }

    public function testHotelAndReviewAreRecognisedAndChecked(): void
    {
        $r = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@graph' => [['@type' => 'Hotel', 'name' => 'H'], ['@type' => 'Review', 'author' => 'A']]]);
        $this->assertContains('missing_recommended:Hotel.address', $this->codes($r, 'warning'));
        $this->assertContains('missing_required:Review.reviewRating', $this->codes($r, 'error'));
        $this->assertNotContains('unknown_type:Hotel', $this->codes($r));
    }

    public function testFaqNeedsQuestionsWithAnswers(): void
    {
        $bad = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'Q?']]]);
        $this->assertContains('missing_required:Question.acceptedAnswer', $this->codes($bad, 'error'));
        $empty = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []]);
        $this->assertFalse($empty['valid']);
    }

    public function testBreadcrumbPositionsMustBeSequential(): void
    {
        $r = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'a'], ['@type' => 'ListItem', 'position' => 3, 'name' => 'b']]]);
        $this->assertContains('bad_position:', $this->codes($r, 'error'));
    }

    public function testAggregateRatingNeedsACount(): void
    {
        $r = SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@type' => 'AggregateRating', 'ratingValue' => '4.8']);
        $this->assertContains('rating_count:', $this->codes($r, 'error'));
    }

    public function testStringInputHandlesScriptTagBadJsonHtmlAndContext(): void
    {
        $ok = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"O","url":"https://x.com","logo":"https://x.com/l.png"}</script>';
        $this->assertTrue(SeoSchemaValidator::validate($ok)['valid']);
        $this->assertSame(['invalid_json:'], $this->codes(SeoSchemaValidator::validate('{nope'), 'error'));
        $this->assertSame(['html_inside:'], $this->codes(SeoSchemaValidator::validate('{"a":"<script>alert(1)</script>"}'), 'error'));
        $this->assertContains('bad_context:', $this->codes(SeoSchemaValidator::validate(['@context' => 'http://evil.test', '@type' => 'Organization', 'name' => 'x']), 'error'));
        $this->assertSame(['too_large:'], $this->codes(SeoSchemaValidator::validate(str_repeat('a', 200001)), 'error'));
    }

    public function testTooManyNodesAndUnknownTypeWarn(): void
    {
        $nodes = array_fill(0, 11, ['@type' => 'Organization', 'name' => 'x', 'url' => 'https://x.com', 'logo' => 'https://x.com/l.png']);
        $this->assertContains('too_many_nodes:', $this->codes(SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@graph' => $nodes]), 'error'));
        $this->assertContains('unknown_type:Spaceship', $this->codes(SeoSchemaValidator::validate(['@context' => 'https://schema.org', '@type' => 'Spaceship', 'name' => 'x']), 'warning'));
    }

    public function testBuilderOutputForATourPageValidatesAndHotelIsPublishable(): void
    {
        $built = SeoSchemaBuilder::forPage([
            'url' => 'https://x.com/tour/nile-day-trip', 'page_path' => '/tour/nile-day-trip', 'h1' => 'Nile Day Trip', 'title' => 'Nile Day Trip | X',
            'description' => 'A private full day trip on the Nile with a licensed guide and lunch included for all guests.',
            'text' => 'Nile Day Trip from $60.00 per person', 'schema' => [],
        ], ['site_url' => 'https://x.com', 'business_name' => 'X']);
        $this->assertNotNull($built);
        $this->assertSame(0, SeoSchemaValidator::validate($built['graph'])['errors'], json_encode(SeoSchemaValidator::validate($built['graph'])['issues']));
        $this->assertTrue(SeoSchemaBuilder::typesAllowed(['@type' => 'Hotel', 'name' => 'H', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'EG']]));
    }
}
