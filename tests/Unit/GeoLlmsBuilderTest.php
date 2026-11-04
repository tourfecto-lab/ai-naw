<?php

use PHPUnit\Framework\TestCase;

/** The llms.txt draft must stay factual: no empty summary line when the home page has no description. */
final class GeoLlmsBuilderTest extends TestCase
{
    private function build(array $home): string
    {
        $evidence = new class($home) extends AiVisibilityEvidence {
            public function __construct(private array $home) {}
            public function beginBudget(int $seconds): void {}
            public function page(string $url): array { return $this->home; }
        };
        return (new GeoLlmsBuilder($evidence))->build('https://example.com');
    }

    private function home(string $description): array
    {
        return ['ok' => true, 'url' => 'https://example.com', 'title' => 'Example Tours', 'description' => $description,
            'languages' => [], 'phone' => [], 'email' => [], 'links' => [], 'prices' => []];
    }

    public function testDescriptionBecomesTheSummaryLine(): void
    {
        $out = $this->build($this->home('Private day tours in Cairo.'));
        $this->assertStringContainsString("# Example Tours\n\n> Private day tours in Cairo.\n\n## Website", $out);
    }

    public function testMissingDescriptionLeavesNoEmptySummaryLine(): void
    {
        $out = $this->build($this->home('  '));
        $this->assertStringNotContainsString("> \n", $out);
        $this->assertStringContainsString("# Example Tours\n\n## Website\n- https://example.com", $out);
    }

    public function testHollowPageWithNoTitleDescriptionOrLinksIsRejected(): void
    {
        $home = $this->home('');
        $home['title'] = '';
        $this->expectException(RuntimeException::class);
        $this->build($home);
    }

    public function testHollowFirstFetchIsRetriedOnceWithACacheBusterAndTheParameterIsNotShown(): void
    {
        $good = $this->home('Private day tours in Cairo.');
        $evidence = new class($good) extends AiVisibilityEvidence {
            public array $urls = [];
            public function __construct(private array $good) {}
            public function beginBudget(int $seconds): void {}
            public function page(string $url): array
            {
                $this->urls[] = $url;
                if (!str_contains($url, '_tourfecto_audit=')) {
                    return ['ok' => true, 'url' => $url, 'title' => '', 'description' => '', 'languages' => [], 'phone' => [], 'email' => [], 'links' => [], 'prices' => [], 'bytes' => 0];
                }
                return ['url' => $url] + $this->good;
            }
        };
        $out = (new GeoLlmsBuilder($evidence))->build('https://example.com');
        $this->assertCount(2, $evidence->urls);
        $this->assertStringContainsString('> Private day tours in Cairo.', $out);
        $this->assertStringNotContainsString('_tourfecto_audit', $out);
        $this->assertStringContainsString('- https://example.com', $out);
    }

    private function buildWithTrip(array $trip): string
    {
        $home = $this->home('Private day tours in Cairo.');
        $home['links'] = [['url' => 'https://example.com/tour/cairo', 'label' => 'Cairo trip']];
        $evidence = new class($home, $trip) extends AiVisibilityEvidence {
            public function __construct(private array $home, private array $trip) {}
            public function beginBudget(int $seconds): void {}
            public function page(string $url): array { return $url === 'https://example.com/tour/cairo' ? $this->trip : $this->home; }
        };
        return (new GeoLlmsBuilder($evidence))->build('https://example.com');
    }

    private function trip(array $schema, array $prices): array
    {
        return ['ok' => true, 'url' => 'https://example.com/tour/cairo', 'title' => 'Cairo Day Tour', 'description' => 'A private day in Cairo.',
            'schema' => $schema, 'prices' => $prices, 'h1' => 'Cairo Day Tour', 'text' => 'tour itinerary', 'languages' => [], 'phone' => [], 'email' => [], 'links' => [], 'has_schema' => (bool) $schema];
    }

    public function testTripPriceComesFromStructuredOfferNotFromPageText(): void
    {
        $schema = [['@type' => 'Product', 'offers' => ['@type' => 'Offer', 'price' => '135.00', 'priceCurrency' => 'USD']]];
        $out = $this->buildWithTrip($this->trip($schema, ['from $135.00 per person', '$2362', 'From $97.00', 'From $38.00']));
        $this->assertStringContainsString('| Published price: USD 135.00', $out);
        $this->assertStringNotContainsString('$2362', $out);
        $this->assertStringNotContainsString('$97.00', $out);
    }

    public function testWithoutSchemaOnlyACleanFirstPriceIsUsed(): void
    {
        $clean = $this->buildWithTrip($this->trip([], ['from $135.00 per person', 'From $97.00']));
        $this->assertStringContainsString('| Published price: from $135.00 per person', $clean);
        $this->assertStringNotContainsString('$97.00', $clean);
        $noisy = $this->buildWithTrip($this->trip([], ['$2362', 'From $97.00']));
        $this->assertStringNotContainsString('Published price', $noisy);
    }
}
