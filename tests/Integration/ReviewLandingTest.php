<?php

/**
 * Reputation: public "review us" page and QR (signed URL, only http(s) destinations, escaped output, no gating).
 */

use PHPUnit\Framework\TestCase;

final class ReviewLandingTest extends TestCase
{
    public function testCodeRoundTripsAndRejectsTampering(): void
    {
        $code = ReviewLanding::code(42);
        $this->assertSame(42, ReviewLanding::verify($code));
        $this->assertSame(0, ReviewLanding::verify('43' . substr($code, 2)), 'changing the id must invalidate the signature');
        $this->assertSame(0, ReviewLanding::verify('42-' . str_repeat('0', 20)));
        $this->assertSame(0, ReviewLanding::verify('abc'));
        $this->assertSame(0, ReviewLanding::verify('0-' . str_repeat('a', 20)));
        $this->assertSame(0, ReviewLanding::verify("42-' OR 1=1"));
    }

    public function testDestinationsKeepOnlyValidHttpLinksWithoutDuplicates(): void
    {
        $d = ReviewLanding::destinations([
            'google_review_link' => 'https://g.page/r/abc/review',
            'tripadvisor_review_link' => 'javascript:alert(1)',
            'default_review_link' => 'https://g.page/r/abc/review',
        ]);
        $this->assertCount(1, $d);
        $this->assertSame('google', $d[0]['key']);
        $this->assertSame([], ReviewLanding::destinations([]));
    }

    public function testPageShowsEveryDestinationAndEscapesTheCompanyName(): void
    {
        $html = ReviewLanding::page('<script>alert(1)</script> Tours', [
            ['key' => 'google', 'label' => 'Google', 'url' => 'https://g.page/r/abc/review?a=1&b=2'],
            ['key' => 'tripadvisor', 'label' => 'TripAdvisor', 'url' => 'https://www.tripadvisor.com/UserReviewEdit-g1'],
        ]);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertFalse(strpos($html, '<script>alert') !== false);
        $this->assertStringContainsString('https://g.page/r/abc/review?a=1&amp;b=2', $html);
        $this->assertStringContainsString('TripAdvisor', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testEmptyPageExplainsInsteadOfShowingDeadButtons(): void
    {
        $html = ReviewLanding::page('Co', []);
        $this->assertStringContainsString('has not set up its review links yet', $html);
        $this->assertFalse(strpos($html, 'class="btn"') !== false);
    }

    public function testQrIsAnSvgForTheUrl(): void
    {
        $svg = ReviewLanding::qrSvg(ReviewLanding::url(7, 'https://tourfecto.pro'));
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);
    }
}
