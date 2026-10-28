<?php

/**
 * Tourfecto - Email Marketing Renderer Test
 * اختبار وحدة لمحرك تخصيص/تجهيز قوالب البريد:
 *   - تخصيص المتغيرات ({{first_name}}, {{email}}, {{unsubscribe_url}}...)
 *   - إعادة كتابة الروابط للتتبع (click tracking) مع استثناء الروابط الداخلية
 *   - حقن بكسل الفتح + حماية من HTML injection في المتغيرات
 *   - تغليف HTML كامل لو مش موجود
 * @version 1.0.0  @date 2026-08-21
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailRenderer.php';

final class EmailRendererTest extends TestCase
{
    private EmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new EmailRenderer();
    }

    public function testPersonalizeReplacesCoreVariables(): void
    {
        $html = 'مرحبًا {{first_name}}، بريدك {{email}} من {{company_name}} لحملة {{campaign_name}}';
        $out = $this->renderer->personalize($html, [
            'first_name' => 'أحمد',
            'name' => 'أحمد علي',
            'email' => 'ahmed@example.com',
            'company_name' => 'شركة السفر',
            'campaign_name' => 'عرض الصيف',
        ]);

        $this->assertStringContainsString('مرحبًا أحمد،', $out);
        $this->assertStringContainsString('ahmed@example.com', $out);
        $this->assertStringContainsString('شركة السفر', $out);
        $this->assertStringContainsString('عرض الصيف', $out);
        $this->assertStringNotContainsString('{{', $out);
    }

    public function testPersonalizeEscapesHtmlInjection(): void
    {
        $html = 'مرحبًا {{first_name}}';
        $out = $this->renderer->personalize($html, [
            'first_name' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testPersonalizeUsesAttributes(): void
    {
        $html = 'مدينتك: {{city}}';
        $out = $this->renderer->personalize($html, [
            'attributes' => ['city' => 'الرياض'],
        ]);
        $this->assertStringContainsString('مدينتك: الرياض', $out);
    }

    public function testRewriteLinksWrapsExternalUrls(): void
    {
        $html = '<a href="https://example.com/offer">عرض</a>';
        $out = $this->renderer->rewriteLinks($html, 'token123', 'https://app.tourfecto.com');

        $this->assertStringContainsString('/api/email-marketing/track/click/token123?u=', $out);
        // الرابط الأصلي مقنّع كـ base64 داخل الرابط الجديد
        $this->assertStringNotContainsString('href="https://example.com/offer"', $out);
    }

    public function testRewriteLinksSkipsRelativeAndTrackingUrls(): void
    {
        $html = '<a href="/dashboard">داخلية</a>'
            . '<a href="https://app.tourfecto.com/api/email-marketing/unsubscribe/xyz">إلغاء</a>';
        $out = $this->renderer->rewriteLinks($html, 'token123', 'https://app.tourfecto.com');

        // الروابط الداخلية والمحلية تفضل كما هي
        $this->assertStringContainsString('href="/dashboard"', $out);
        $this->assertStringContainsString('/api/email-marketing/unsubscribe/xyz', $out);
        $this->assertSame(2, substr_count($out, '<a '));
    }

    public function testPixelHtmlIsTransparentGifTag(): void
    {
        $pixel = $this->renderer->pixelHtml('openabc', 'https://app.tourfecto.com');
        $this->assertStringContainsString('/api/email-marketing/track/open/openabc.gif', $pixel);
        $this->assertStringContainsString('width="1" height="1"', $pixel);
    }

    public function testFinalizeWrapsHtmlAndAddsTrackingAndUnsubscribe(): void
    {
        $out = $this->renderer->finalize(
            '<p>مرحبًا {{first_name}}</p>',
            ['first_name' => 'سارة'],
            'open1',
            'click1',
            'https://app.tourfecto.com',
            'https://app.tourfecto.com/api/email-marketing/unsubscribe/tok'
        );

        $this->assertStringContainsString('<!DOCTYPE html>', $out);
        $this->assertStringContainsString('مرحبًا سارة', $out);
        $this->assertStringContainsString('track/open/open1.gif', $out);
        $this->assertStringContainsString('unsubscribe/tok', $out);
        $this->assertStringContainsString('إلغاء الاشتراك', $out);
    }

    public function testVariablesExposesSupportedPlaceholders(): void
    {
        $vars = EmailRenderer::variables();
        $this->assertArrayHasKey('{{first_name}}', $vars);
        $this->assertArrayHasKey('{{email}}', $vars);
        $this->assertArrayHasKey('{{unsubscribe_url}}', $vars);
    }
}
