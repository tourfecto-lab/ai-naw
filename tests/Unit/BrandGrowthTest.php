<?php

/** TOURFECTO AI CEO (المرحلة 5): فحص صفحات موقع Tourfecto، والدرجة، وتحذيرات الوعود في خطة المحتوى */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/BrandSiteAudit.php';
require_once __DIR__ . '/../../app/Services/AiCeo/BrandGrowth.php';

final class BrandGrowthTest extends TestCase
{
    private function good(): string
    {
        return '<html><head><title>Tourfecto — AI marketing for tour operators</title><meta name="description" content="SEO, AI chat, social publishing and bookings for tourism businesses in one platform.">'
            . '<link rel="canonical" href="https://tourfecto.pro/features"><meta property="og:title" content="x"><meta property="og:image" content="y.png">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication"}</script></head><body><h1>Features</h1><p>'
            . str_repeat('tourism marketing word ', 120) . '</p><a href="/pricing">Pricing</a><a href="https://tourfecto.pro/blog">Blog</a><a href="https://other.com">x</a></body></html>';
    }

    private function codes(array $page): array
    {
        return array_column($page['issues'], 1);
    }

    public function testGoodPageHasNoIssues(): void
    {
        $p = BrandSiteAudit::analyzePage('https://tourfecto.pro/features', 200, $this->good(), 800, 'tourfecto.pro');
        $this->assertSame([], $p['issues']);
        $this->assertSame(['SoftwareApplication'], $p['structured_data']);
        $this->assertSame(2, $p['internal_links']);
        $this->assertSame(true, $p['open_graph']);
    }

    public function testDetectsProblems(): void
    {
        $html = '<html><head><meta name="robots" content="noindex,follow"><link rel="canonical" href="https://elsewhere.com/x"></head><body><h1>a</h1><h1>b</h1>short</body></html>';
        $codes = $this->codes(BrandSiteAudit::analyzePage('https://tourfecto.pro/x', 200, $html, 4200, 'tourfecto.pro'));
        foreach (['noindex', 'title_missing', 'canonical_other_host', 'slow', 'meta_desc_missing', 'h1_multiple', 'structured_data_missing', 'open_graph_missing', 'thin_content'] as $c) {
            $this->assertContains($c, $codes, $c);
        }
        $this->assertSame(['http_error'], $this->codes(BrandSiteAudit::analyzePage('https://tourfecto.pro/gone', 404, '', 100, 'tourfecto.pro')));
    }

    public function testScore(): void
    {
        $ok = BrandSiteAudit::analyzePage('https://tourfecto.pro/', 200, $this->good(), 500, 'tourfecto.pro');
        $this->assertSame(100, BrandSiteAudit::summarize([$ok], [])['score']);
        $bad = BrandSiteAudit::analyzePage('https://tourfecto.pro/gone', 500, '', 100, 'tourfecto.pro');
        $s = BrandSiteAudit::summarize([$ok, $bad], [['critical', 'sitemap_missing', 'x'], ['info', 'llms_txt_missing', 'y']]);
        // صفحة واقعة (25 ÷ 2 صفحة) + sitemap مفقود 25 + llms.txt 2
        $this->assertSame(61, $s['score']);
        $this->assertSame(2, $s['critical']);
        $this->assertSame(1, $s['ok_pages']);
        $this->assertSame(1, $s['issue_counts']['http_error']);
    }

    public function testPathAndHost(): void
    {
        $this->assertSame('/', BrandGrowth::path('/'));
        $this->assertSame('/', BrandGrowth::path('https://tourfecto.pro'));
        $this->assertSame('/features/seo', BrandGrowth::path('/features/seo/?utm_source=x'));
        $this->assertSame('google.com', BrandGrowth::host('https://www.google.com/search?q=1'));
        $this->assertSame('(direct)', BrandGrowth::host(null));
        $this->assertSame('(direct)', BrandGrowth::host(''));
    }

    public function testClaimWarnings(): void
    {
        $this->assertSame([], BrandGrowth::claimWarnings(['markets' => [['posts' => [['hook' => 'Reply to WhatsApp leads faster']]]]]));
        $w = BrandGrowth::claimWarnings(['markets' => [['posts' => [['hook' => 'Guaranteed first page on Google'], ['hook' => 'نضمن لك عملاء']]]]]);
        $this->assertContains('"guaranteed" promises a guarantee', $w);
        $this->assertContains('"first page" promises a search ranking', $w);
        $this->assertContains('"نضمن" promises a guarantee', $w);
    }
}
