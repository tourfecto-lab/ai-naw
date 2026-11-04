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
}
