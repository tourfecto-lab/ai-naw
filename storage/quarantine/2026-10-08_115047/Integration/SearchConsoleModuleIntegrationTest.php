<?php

/**
 * Tourfecto - Search Console (Item 3a) Integration Test
 * بيفحص عميل Google Search Console API بمصادر حقيقية **وصفر شبكة** —
 * حقن transport وهمي بنفس بنية رد curl (نفس نمط حقن WordPressPublisher من م3):
 *   1) Connect: listSites() بيرجع المواقع المؤكدة بس وبيفلتر siteUnverifiedUser.
 *   2) Auth fail: 401 من Google → خطأ "Search Console API Error (401): ...".
 *   3) بيانات ملوّثة/فاضية: رد غير JSON أو missing rows/siteEntry → نجاح بصفوف/مواقع فاضية.
 *   4) فشل شبكة: transport يرجّع خطأ → "cURL Error: ...".
 *   5) analytics: تبادل الـ dimensions لصفوف + حساب الـ summary (clicks/impressions/ctr).
 *   6) GoogleSearchConsoleIntegration::request() بدون access_token → رفض مبكر،
 *      ومعاه dispatch صحيح للـ actions (عبر partial mock لـ authorizedRequest).
 *
 * لا يمس أي جدول — كل شيء داخل الذاكرة.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Contracts/IntegrationInterface.php';
require_once __DIR__ . '/../../app/Integrations/OAuth/BaseOAuthIntegration.php';
require_once __DIR__ . '/../../app/Integrations/OAuth/GoogleSearchConsoleIntegration.php';
require_once __DIR__ . '/../../app/Services/SearchConsole/GoogleSearchConsoleAPI.php';

/** transport وهمي بيسجّل الطلبات ويرجّع استجابات مسرّحة بنفس بنية رد curl */
final class SearchConsoleTransportFake
{
    public array $calls = [];
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke(array $req): array
    {
        $this->calls[] = $req;
        $idx = min(count($this->calls) - 1, count($this->responses) - 1);
        return $this->responses[$idx];
    }
}

final class SearchConsoleModuleIntegrationTest extends TestCase
{
    // ================================================================
    // Connect — قائمة المواقع
    // ================================================================

    public function testListSitesReturnsOnlyVerifiedAndSendsBearerToken(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteFullUser'],
                    ['siteUrl' => 'https://unverified.com/', 'permissionLevel' => 'siteUnverifiedUser'],
                ],
            ]), 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('tok-gsc', $transport);

        $result = $api->listSites();
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['sites']);
        $this->assertSame('sc-domain:example.com', $result['sites'][0]['site_url']);
        $this->assertSame('siteFullUser', $result['sites'][0]['permission_level']);

        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertStringContainsString('/webmasters/v3/sites', $transport->calls[0]['url']);
        $this->assertContains('Authorization: Bearer tok-gsc', $transport->calls[0]['headers']);
    }

    public function testListSitesAuthFailure(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 401, 'body' => json_encode([
                'error' => ['code' => 401, 'message' => 'Request is missing required authentication credential.'],
            ]), 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('bad-token', $transport);

        $result = $api->listSites();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Search Console API Error (401)', $result['error']);
        $this->assertStringContainsString('missing required authentication', $result['error']);
        $this->assertSame(401, $result['http_code']);
    }

    public function testListSitesMalformedResponseIsEmptyList(): void
    {
        // رد غير JSON نهائيًا → json_decode = null → مواقع فاضية بنجاح (fail-soft)
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 200, 'body' => '', 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('tok', $transport);

        $result = $api->listSites();
        $this->assertTrue($result['success']);
        $this->assertSame([], $result['sites']);
    }

    public function testListSitesNetworkFailure(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Could not resolve host: www.googleapis.com'],
        ]);
        $api = new GoogleSearchConsoleAPI('tok', $transport);

        $result = $api->listSites();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cURL Error', $result['error']);
        $this->assertStringContainsString('Could not resolve host', $result['error']);
    }

    // ================================================================
    // Search Analytics — صفوف الأداء
    // ================================================================

    public function testSearchAnalyticsMapsRowsWithDimensions(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'rows' => [
                    ['keys' => ['desert safari'], 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 3.5],
                    ['keys' => ['dubai tours'], 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.2, 'position' => 7],
                ],
            ]), 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('tok', $transport);

        $result = $api->getSearchAnalytics('sc-domain:example.com', '2026-07-01', '2026-07-28', ['query'], 25);
        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('desert safari', $result['rows'][0]['query']);
        $this->assertSame(10, $result['rows'][0]['clicks']);
        $this->assertSame(100, $result['rows'][0]['impressions']);
        $this->assertSame(10.0, $result['rows'][0]['ctr']);
        $this->assertSame(3.5, $result['rows'][0]['position']);

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringContainsString('/webmasters/v3/sites/sc-domain%3Aexample.com/searchAnalytics/query', $call['url']);
        $payload = json_decode((string) $call['body'], true);
        $this->assertSame('2026-07-01', $payload['startDate']);
        $this->assertSame('2026-07-28', $payload['endDate']);
        $this->assertSame(['query'], $payload['dimensions']);
        $this->assertSame(25, $payload['rowLimit']);
    }

    public function testSearchAnalyticsAuthFailure(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 403, 'body' => json_encode([
                'error' => ['code' => 403, 'message' => 'The caller does not have permission'],
            ]), 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('no-scope-token', $transport);

        $result = $api->getSearchAnalytics('sc-domain:example.com', '2026-07-01', '2026-07-28');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Search Console API Error (403)', $result['error']);
    }

    public function testSearchAnalyticsMalformedDataIsEmptyRows(): void
    {
        // 200 مع جسم غير JSON → rows فاضية بنجاح من غير crash
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 200, 'body' => 'this is not json', 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('tok', $transport);

        $result = $api->getSearchAnalytics('sc-domain:example.com', '2026-07-01', '2026-07-28');
        $this->assertTrue($result['success']);
        $this->assertSame([], $result['rows']);
    }

    // ================================================================
    // Summary — التجميع
    // ================================================================

    public function testSummaryAggregatesClicksImpressionsCtrPosition(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'rows' => [
                    ['keys' => ['2026-07-01'], 'clicks' => 30, 'impressions' => 300, 'ctr' => 0.1, 'position' => 2.0],
                    ['keys' => ['2026-07-02'], 'clicks' => 70, 'impressions' => 700, 'ctr' => 0.1, 'position' => 4.0],
                ],
            ]), 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('tok', $transport);

        $result = $api->getSummary('sc-domain:example.com', 28);
        $this->assertTrue($result['success']);
        $this->assertSame(100, $result['summary']['clicks']);
        $this->assertSame(1000, $result['summary']['impressions']);
        $this->assertSame(10.0, $result['summary']['ctr']);
        // avg_position = (2.0*300 + 4.0*700) / 1000 = 3400/1000 = 3.4
        $this->assertSame(3.4, $result['summary']['avg_position']);
        $this->assertNotNull($result['summary']['start_date']);
        $this->assertNotNull($result['summary']['end_date']);
    }

    public function testSummaryPropagatesFailure(): void
    {
        $transport = new SearchConsoleTransportFake([
            ['http_code' => 500, 'body' => json_encode(['error' => ['message' => 'Backend Error']]), 'error' => null],
        ]);
        $api = new GoogleSearchConsoleAPI('tok', $transport);

        $result = $api->getSummary('sc-domain:example.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Search Console API Error (500)', $result['error']);
    }

    // ================================================================
    // GoogleSearchConsoleIntegration::request() — dispatch + token requirement
    // ================================================================

    public function testIntegrationRequestListSitesRequiresAccessToken(): void
    {
        $integration = new GoogleSearchConsoleIntegration();
        $result = $integration->request('list_sites', [], []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('access_token', $result['error']);
    }

    public function testIntegrationRequestListSitesDispatchesAuthedGet(): void
    {
        $integration = $this->createPartialMock(GoogleSearchConsoleIntegration::class, ['authorizedRequest']);
        $context = ['access_token' => 'tok-abc'];
        $integration->expects($this->once())
            ->method('authorizedRequest')
            ->with('GET', '/sites', $context)
            ->willReturn(['success' => true, 'data' => ['siteEntry' => []], 'error' => null]);

        $result = $integration->request('list_sites', [], $context);
        $this->assertTrue($result['success']);
    }

    public function testIntegrationRequestSearchAnalyticsBuildsEndpoint(): void
    {
        $integration = $this->createPartialMock(GoogleSearchConsoleIntegration::class, ['authorizedRequest']);
        $integration->expects($this->once())
            ->method('authorizedRequest')
            ->with('POST', '/sites/https%3A%2F%2Fexample.com%2F/searchAnalytics/query', ['access_token' => 'tok'], $this->callback(
                fn (array $body) => $body['startDate'] === '2026-07-01'
                    && $body['endDate'] === '2026-07-28'
                    && $body['dimensions'] === ['query']
            ))
            ->willReturn(['success' => true, 'data' => [], 'error' => null]);

        $result = $integration->request('search_analytics', [
            'site_url' => 'https://example.com/',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-28',
        ], ['access_token' => 'tok']);
        $this->assertTrue($result['success']);
    }
}
