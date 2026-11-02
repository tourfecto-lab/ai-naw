<?php

use PHPUnit\Framework\TestCase;

/** "One language only" must not be claimed for sites that switch language with JavaScript (no hreflang, no /en/ links). */
final class OutreachLanguageDetectionTest extends TestCase
{
    private function gapIds(string $html): array
    {
        $page = OutreachAudit::parsePage($html, 'https://example.test/');
        $site = OutreachAudit::mergePages([$page], 800, true, true);
        $lead = ['domain' => 'example.test', 'website' => 'https://example.test/', 'reviews' => 0, 'rating' => null];
        return array_column(OutreachAudit::gaps($lead, [], $site, ['checked' => false], []), 'id');
    }

    public function testLanguageNamesInAMenuCountAsMultilingual(): void
    {
        $html = '<html><body><ul><li><a href="#">English</a></li><li><a href="#">Français</a></li><li><a href="#">Español</a></li></ul></body></html>';
        $this->assertNotContains('one_language', $this->gapIds($html));
    }

    public function testTranslationWidgetCountsAsMultilingual(): void
    {
        $html = '<html><body><div id="google_translate_element"></div></body></html>';
        $this->assertNotContains('one_language', $this->gapIds($html));
    }

    public function testHreflangStillCountsAsMultilingual(): void
    {
        $html = '<html><head><link rel="alternate" hreflang="en" href="/"><link rel="alternate" hreflang="de" href="/de/"></head><body></body></html>';
        $this->assertNotContains('one_language', $this->gapIds($html));
    }

    public function testSingleLanguageSiteIsStillFlagged(): void
    {
        $this->assertContains('one_language', $this->gapIds('<html><body><p>Welcome</p></body></html>'));
        // a lone "English" label is not a switcher
        $this->assertContains('one_language', $this->gapIds('<html><body><a href="#">English</a></body></html>'));
    }
}
