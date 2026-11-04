<?php

use PHPUnit\Framework\TestCase;

/** Unresolved Gemini grounding redirects are not real sources and must not appear in the cited-domains list. */
final class GeoCitationsRedirectTest extends TestCase
{
    private function citations(array $rows, string $ownHost = 'example.com', array $known = []): array
    {
        $m = new ReflectionMethod(GeoInsightsService::class, 'citations');
        $m->setAccessible(true);
        return $m->invoke(null, $rows, $ownHost, $known);
    }

    private function row(string $provider, array $urls): array
    {
        return ['provider' => $provider, '_urls' => $urls];
    }

    public function testVertexRedirectHostIsNotListedAsASource(): void
    {
        $out = $this->citations([
            $this->row('gemini', ['https://vertexaisearch.cloud.google.com/grounding-api-redirect/abc', 'https://tripadvisor.com/x']),
            $this->row('gemini', ['https://vertexaisearch.cloud.google.com/grounding-api-redirect/def']),
        ]);
        $domains = array_column($out['domains'], 'domain');
        $this->assertSame(['tripadvisor.com'], $domains);
    }

    public function testTrailingDotHostVariantIsAlsoSkipped(): void
    {
        $out = $this->citations([$this->row('gemini', ['https://vertexaisearch.cloud.google.com./grounding-api-redirect/abc'])]);
        $this->assertSame([], $out['domains']);
    }

    public function testRealSourcesAndOwnPagesAreStillCounted(): void
    {
        $out = $this->citations([
            $this->row('openai', ['https://www.example.com/tours/cairo', 'https://viator.com/y']),
            $this->row('gemini', ['https://viator.com/z']),
        ], 'example.com', ['viator.com' => true]);
        $byDomain = array_column($out['domains'], null, 'domain');
        $this->assertSame(2, $byDomain['viator.com']['answers']);
        $this->assertSame('competitor', $byDomain['viator.com']['kind']);
        $this->assertSame('own', $byDomain['example.com']['kind']);
        $this->assertSame('https://www.example.com/tours/cairo', $out['own_pages'][0]['url']);
    }

    public function testRepeatedUnregisteredOperatorDomainsAreSuggestedAsCompetitors(): void
    {
        $out = $this->citations([
            $this->row('openai', ['https://rival-tours.com/a', 'https://youtube.com/watch?v=1', 'https://registered.com/x']),
            $this->row('gemini', ['https://www.rival-tours.com/b', 'https://m.youtube.com/z', 'https://registered.com/y']),
            $this->row('openai', ['https://one-off.com/c']),
        ], 'example.com', ['registered.com' => true]);
        $byDomain = array_column($out['domains'], null, 'domain');
        $this->assertSame('platform', $byDomain['youtube.com']['kind']);
        $this->assertSame('platform', $byDomain['m.youtube.com']['kind']);
        $this->assertSame(['rival-tours.com'], array_column($out['suggested_competitors'], 'domain'));
        $this->assertSame(2, $out['suggested_competitors'][0]['answers']);
    }

    public function testSuggestionsAreCappedAtEight(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = $this->row('openai', ["https://rival$i.com/a"]);
            $rows[] = $this->row('gemini', ["https://rival$i.com/b"]);
        }
        $this->assertCount(8, $this->citations($rows)['suggested_competitors']);
    }
}
