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
}
