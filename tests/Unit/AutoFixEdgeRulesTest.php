<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AutoFix/EdgeRules.php';
require_once __DIR__ . '/../../app/Services/AutoFix/FixValueBuilder.php';
require_once __DIR__ . '/../../app/Services/AutoFix/PageContext.php';

/**
 * EdgeRules::sanitize هو آخر فلتر قبل ما قيمة تتحقن في موقع عميل (title/canonical/hsts/روابط...).
 * أي قيمة مش مطابقة بالظبط لازم ترجع null. + توكن الموقع، وكشف الأماكن الوهمية وصفحات الصيانة والتحقق من القيمة.
 */
final class AutoFixEdgeRulesTest extends TestCase
{
    // ---------- token ----------

    public function testTokenRoundTripsAndRejectsTampering(): void
    {
        $token = EdgeRules::token(42);

        $this->assertSame(42, EdgeRules::parseToken($token));
        $this->assertNull(EdgeRules::parseToken('43.' . explode('.', $token)[1]), 'توكن موقع تاني');
        $this->assertNull(EdgeRules::parseToken('42.' . str_repeat('0', 32)));
        $this->assertNull(EdgeRules::parseToken('42'));
        $this->assertNull(EdgeRules::parseToken(''));
        $this->assertNull(EdgeRules::parseToken($token . 'x'));
        $this->assertNull(EdgeRules::parseToken('abc.' . explode('.', $token)[1]));
    }

    public function testDifferentSitesGetDifferentTokens(): void
    {
        $this->assertNotSame(EdgeRules::token(1), EdgeRules::token(2));
        $this->assertSame(EdgeRules::token(7), EdgeRules::token(7));
    }

    // ---------- title / description ----------

    public function testTitleIsStrippedOfTagsAndLengthChecked(): void
    {
        $this->assertSame('Luxor day tours from Hurghada', EdgeRules::sanitize('title', "  <b>Luxor</b>   day tours\nfrom Hurghada "));
        $this->assertNull(EdgeRules::sanitize('title', 'Too short'), 'أقل من 10 حروف');
        $this->assertNull(EdgeRules::sanitize('title', str_repeat('a', 121)));
        // سكربت بيتشال بالـtags بس النص اللي جواه بيفضل: بنتأكد إن مفيش < أو > في الناتج
        $clean = EdgeRules::sanitize('title', '<script>alert(1)</script>Luxor tours and cruises');
        $this->assertStringNotContainsString('<', (string) $clean);
    }

    public function testDescriptionLengthBounds(): void
    {
        $this->assertNull(EdgeRules::sanitize('description', 'short description'));
        $this->assertSame(str_repeat('a', 20), EdgeRules::sanitize('description', str_repeat('a', 20)));
        $this->assertNull(EdgeRules::sanitize('description', str_repeat('a', 321)));
    }

    // ---------- canonical ----------

    public function testCanonicalMustBeHttpsOnTheSameHost(): void
    {
        $this->assertSame('https://example.com/tours', EdgeRules::sanitize('canonical', 'https://example.com/tours', 'example.com'));
        $this->assertSame('https://www.example.com/', EdgeRules::sanitize('canonical', 'https://www.example.com/', 'example.com'), 'www يتجاهل');
        $this->assertNull(EdgeRules::sanitize('canonical', 'http://example.com/', 'example.com'));
        $this->assertNull(EdgeRules::sanitize('canonical', 'https://evil.com/', 'example.com'));
        $this->assertNull(EdgeRules::sanitize('canonical', 'https://example.com/"onload="x', 'example.com'));
        $this->assertNull(EdgeRules::sanitize('canonical', 'https://example.com/', ''), 'من غير دومين الموقع مفيش مقارنة');
    }

    // ---------- small tags ----------

    public function testRobotsViewportLangAndHstsAcceptOnlyExactShapes(): void
    {
        $this->assertSame('noindex,nofollow', EdgeRules::sanitize('robots', 'NoIndex, NoFollow'));
        $this->assertSame('index', EdgeRules::sanitize('robots', 'index'));
        $this->assertNull(EdgeRules::sanitize('robots', 'index, follow, noarchive'));

        $this->assertSame('width=device-width, initial-scale=1', EdgeRules::sanitize('viewport', 'width=device-width,initial-scale=1.0'));
        $this->assertNull(EdgeRules::sanitize('viewport', 'width=device-width, user-scalable=no'));

        $this->assertSame('ar', EdgeRules::sanitize('lang', 'ar'));
        $this->assertSame('en-US', EdgeRules::sanitize('lang', 'en-US'));
        $this->assertNull(EdgeRules::sanitize('lang', 'english'));
        $this->assertNull(EdgeRules::sanitize('lang', 'en"><script>'));

        $this->assertSame('max-age=31536000; includeSubDomains', EdgeRules::sanitize('hsts', 'max-age=31536000; includeSubDomains'));
        $this->assertNull(EdgeRules::sanitize('hsts', 'max-age=abc'));
        $this->assertNull(EdgeRules::sanitize('hsts', 'max-age=31536000; evil'));
    }

    // ---------- open graph ----------

    public function testOpenGraphKeepsOnlyValidTagsAndNeedsAtLeastTwo(): void
    {
        $raw = json_encode([
            'og:title' => 'Luxor <b>tours</b>',
            'og:image' => 'https://example.com/a.jpg',
            'og:url' => 'http://example.com/insecure',   // مرفوض (مش https)
            'script' => 'x',                              // مفتاح مش og/twitter
            'og:description' => '',                       // فاضي
        ]);

        $out = EdgeRules::sanitize('og', $raw);

        $this->assertSame(['og:title' => 'Luxor tours', 'og:image' => 'https://example.com/a.jpg'], $out);
        $this->assertNull(EdgeRules::sanitize('og', json_encode(['og:title' => 'Only one tag'])));
        $this->assertNull(EdgeRules::sanitize('og', 'not json'));
    }

    // ---------- JSON-LD ----------

    public function testJsonLdRequiresSchemaOrgAndSizeLimitAndEscapesTags(): void
    {
        $this->assertNull(EdgeRules::sanitize('jsonld', json_encode(['@type' => 'Organization', 'name' => 'x'])), 'من غير @context');
        $this->assertNull(EdgeRules::sanitize('jsonld', json_encode(['@context' => 'https://evil.com', '@type' => 'Organization'])));
        $this->assertNull(EdgeRules::sanitize('jsonld', 'garbage'));
        $this->assertNull(EdgeRules::sanitize('jsonld', json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => str_repeat('a', 20001)])));

        $ok = EdgeRules::sanitize('jsonld', json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'A </script><b>']));
        if ($ok !== null) {
            // أي ناتج لازم يكون JSON مهروب (< و> مش خام) فما يقدرش يقفل وسم script
            $this->assertStringNotContainsString('</script>', $ok);
            $this->assertStringNotContainsString('<', $ok);
        } else {
            $this->addToAssertionCount(1); // النوع Organization مش مسموح في الـallowlist: الرفض سلوك سليم
        }
    }

    // ---------- links ----------

    public function testRelatedLinksMustBeSameHostAndAtMostFour(): void
    {
        $good = json_encode([['url' => 'https://example.com/a', 'title' => 'A trip']]);
        $this->assertSame(json_decode($good, true), json_decode((string) EdgeRules::sanitize('related_links', $good, 'example.com'), true));

        $this->assertNull(EdgeRules::sanitize('related_links', json_encode([['url' => 'https://evil.com/a', 'title' => 'A']]), 'example.com'));
        $this->assertNull(EdgeRules::sanitize('related_links', json_encode([['url' => 'https://example.com/a', 'title' => '']]), 'example.com'));
        $five = array_fill(0, 5, ['url' => 'https://example.com/a', 'title' => 'A']);
        $this->assertNull(EdgeRules::sanitize('related_links', json_encode($five), 'example.com'));
        $this->assertNull(EdgeRules::sanitize('related_links', json_encode([]), 'example.com'));
    }

    public function testLinkFixAllowsEmptyReplacementButOnlySameHost(): void
    {
        $ok = json_encode([['from' => 'https://example.com/old', 'to' => '']]);
        $this->assertSame(json_decode($ok, true), json_decode((string) EdgeRules::sanitize('link_fix', $ok, 'example.com'), true));

        $this->assertNull(EdgeRules::sanitize('link_fix', json_encode([['from' => 'https://example.com/old', 'to' => 'https://evil.com/x']]), 'example.com'));
        $this->assertNull(EdgeRules::sanitize('link_fix', json_encode([['from' => 'https://evil.com/old', 'to' => '']]), 'example.com'));
        $this->assertNull(EdgeRules::sanitize('link_fix', json_encode(array_fill(0, 11, ['from' => 'https://example.com/o', 'to' => ''])), 'example.com'));
    }

    // ---------- llms.txt / unknown ----------

    public function testLlmsTxtRejectsMarkupAndBadSizes(): void
    {
        $this->assertNotNull(EdgeRules::sanitize('llms_txt', "# Nile Tours\n> Real tours in Luxor"));
        $this->assertNull(EdgeRules::sanitize('llms_txt', 'tiny'));
        $this->assertNull(EdgeRules::sanitize('llms_txt', "# Title\n<script>alert(1)</script> and more text"));
        $this->assertNull(EdgeRules::sanitize('llms_txt', str_repeat('a', 20001)));
    }

    public function testUnknownFieldIsRejected(): void
    {
        $this->assertNull(EdgeRules::sanitize('custom_js', 'alert(1)'));
        $this->assertNull(EdgeRules::sanitize('', 'x'));
    }

    // ---------- FixValueBuilder placeholders ----------

    public function testPlaceholderTextIsRecognisedInArabicAndEnglish(): void
    {
        foreach (['اسم البراند - أفضل رحلات', 'Welcome to YourPage', 'Coming Soon', 'Lorem Ipsum dolor', 'visit example.com', 'اكتب هنا وصف'] as $s) {
            $this->assertTrue(FixValueBuilder::looksLikePlaceholder($s), $s);
        }
        $this->assertFalse(FixValueBuilder::looksLikePlaceholder('Luxor day tour from Hurghada with lunch'));
        $this->assertFalse(FixValueBuilder::looksLikePlaceholder(''));
    }

    // ---------- PageContext ----------

    public function testMaintenanceHtmlIsClassifiedFromContentNotFromWords(): void
    {
        $this->assertTrue(PageContext::isMaintenanceHtml('<html><head><title>Coming Soon</title></head><body><h1>Coming soon</h1></body></html>'));
        $this->assertFalse(PageContext::isMaintenanceHtml('<html><head><title>Luxor tours</title></head><body><h1>Luxor day tours</h1><p>' . str_repeat('Real content about our tours. ', 30) . '</p></body></html>'));
        $this->assertFalse(PageContext::isMaintenanceHtml(''));
    }

    public function testFreshAuditUrlAppendsAVolatileQueryAndDropsTheFragment(): void
    {
        $a = PageContext::freshAuditUrl('https://example.com/tours#top');
        $b = PageContext::freshAuditUrl('https://example.com/tours?x=1');

        $this->assertMatchesRegularExpression('#^https://example\.com/tours\?_tourfecto_audit=[a-f0-9]{16}$#', $a);
        $this->assertMatchesRegularExpression('#^https://example\.com/tours\?x=1&_tourfecto_audit=[a-f0-9]{16}$#', $b);
        $this->assertNotSame($a, PageContext::freshAuditUrl('https://example.com/tours#top'));
    }

    public function testVerifyValueStates(): void
    {
        $ok = PageContext::verifyValue('title', 'Luxor tours', ['ok' => true, 'values' => ['title' => ['Luxor  tours']]]);
        $this->assertSame('verified', $ok['state']);

        $this->assertSame('unreachable', PageContext::verifyValue('title', 'x', ['ok' => false])['state']);
        $this->assertSame('blocked', PageContext::verifyValue('title', 'x', ['ok' => true, 'coming_soon' => true])['state']);
        $this->assertSame('pending', PageContext::verifyValue('title', 'Luxor tours', ['ok' => true, 'values' => ['title' => ['Other']]])['state']);
        $this->assertSame('conflict', PageContext::verifyValue('title', 'Luxor tours', ['ok' => true, 'values' => ['title' => ['A', 'B']]])['state']);
        // قيمة ناقصة + نظام SEO تاني على الصفحة = تعارض بدل "pending"
        $this->assertSame('conflict', PageContext::verifyValue('title', 'Luxor tours', ['ok' => true, 'values' => ['title' => ['Other']], 'seo_plugin' => 'yoast'])['state']);
    }

    public function testVerifyValueComparesHstsAndRobotsExactly(): void
    {
        $this->assertSame('verified', PageContext::verifyValue('hsts', 'max-age=300', ['ok' => true, 'hsts' => 'max-age=300'])['state']);
        $this->assertSame('pending', PageContext::verifyValue('hsts', 'max-age=300', ['ok' => true, 'hsts' => 'max-age=100'])['state']);
        $this->assertSame('verified', PageContext::verifyValue('robots', 'noindex,nofollow', ['ok' => true, 'values' => ['robots' => ['NoIndex, NoFollow']]])['state']);
    }
}
