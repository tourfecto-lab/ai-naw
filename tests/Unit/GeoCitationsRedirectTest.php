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
}
