<?php

use PHPUnit\Framework\TestCase;

/** TikTok Ads client: request order, paused state, cleanup of half-built campaigns, no secrets in errors. */
final class TikTokAdsApiTest extends TestCase
{
    private array $calls = [];

    protected function tearDown(): void
    {
        TikTokAdsAPI::$testTransport = null;
    }

    /** @param array<string, array> $overrides path => decoded TikTok body */
    private function transport(array $overrides = []): void
    {
        $this->calls = [];
        $ok = fn (array $data) => ['code' => 0, 'message' => 'OK', 'data' => $data];
        $defaults = [
            '/tools/region/' => $ok(['region_info' => [['region_code' => 'EG', 'location_id' => '357994'], ['region_code' => 'DE', 'location_id' => '2921044'], ['region_code' => 'FR', 'location_id' => '3017382']]]),
            '/file/video/ad/upload/' => $ok([['video_id' => 'v123']]),
            '/file/video/ad/info/' => $ok(['list' => [['video_id' => 'v123', 'video_cover_url' => 'https://p16.tiktokcdn.com/cover.jpg']]]),
            '/file/image/ad/upload/' => $ok(['image_id' => 'img9']),
            '/identity/create/' => $ok(['identity_id' => 'id77']),
            '/campaign/create/' => $ok(['campaign_id' => 'c1']),
            '/adgroup/create/' => $ok(['adgroup_id' => 'g1']),
            '/ad/create/' => $ok(['ad_ids' => ['a1']]),
            '/campaign/status/update/' => $ok([]),
        ];
        TikTokAdsAPI::$testTransport = function (string $method, string $path, array $data) use (&$defaults, $overrides): array {
            $this->calls[] = [$method, $path, $data];
            return $overrides[$path] ?? $defaults[$path] ?? ['code' => 40000, 'message' => 'unexpected ' . $path];
        };
    }

    private function called(string $path): array
    {
        return array_values(array_filter($this->calls, static fn ($c) => $c[1] === $path));
    }

    private function create(array $opts = [], array $campaign = []): array
    {
        return (new TikTokAdsAPI('tok'))->createVideoCampaign('adv1', $campaign + ['name' => 'Luxor', 'daily_budget' => 30, 'objective' => 'traffic'], ['primary_text' => 'See Luxor with a local guide.'], 'https://ex.com/luxor', $opts + ['countries' => ['EG', 'DE'], 'video_url' => 'https://cdn.example.com/clip.mp4', 'display_name' => 'Luxor Tours']);
    }

    public function testObjectivePlansAndLinks(): void
    {
        $this->assertSame(['REACH', 'CPM', 'REACH', null], TikTokAdsAPI::objectivePlan('awareness'));
        $this->assertSame('VIDEO_VIEWS', TikTokAdsAPI::objectivePlan('engagement')[0]);
        $this->assertSame('TRAFFIC', TikTokAdsAPI::objectivePlan('sales')[0]);
        $this->assertNotNull(TikTokAdsAPI::objectivePlan('leads')[3], 'a conversion objective without a pixel says it falls back to clicks');
        $this->assertNull(TikTokAdsAPI::objectivePlan('traffic')[3]);
        $url = TikTokAdsAPI::authUrl('APP1', 'https://x.test/cb', 'st4te');
        $this->assertStringStartsWith('https://business-api.tiktok.com/portal/auth?', $url);
        $this->assertStringContainsString('app_id=APP1', $url);
        $this->assertStringContainsString('state=st4te', $url);
        $this->assertTrue(TikTokAdsAPI::validVideoUrl('https://cdn.example.com/a/b'));
        $this->assertFalse(TikTokAdsAPI::validVideoUrl('http://cdn.example.com/a.mp4'));
    }

    public function testVideoCampaignIsBuiltInOrderAndPaused(): void
    {
        $this->transport();
        $r = $this->create();
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(['c1', 'g1', 'a1'], [$r['external_campaign_id'], $r['external_adgroup_id'], $r['ad_id']]);
        $order = array_column($this->calls, 1);
        $this->assertLessThan(array_search('/campaign/create/', $order, true), array_search('/file/video/ad/upload/', $order, true), 'media is uploaded before the campaign exists');
        $this->assertLessThan(array_search('/adgroup/create/', $order, true), array_search('/campaign/create/', $order, true));
        $this->assertLessThan(array_search('/ad/create/', $order, true), array_search('/adgroup/create/', $order, true));
        foreach (['/campaign/create/', '/adgroup/create/', '/ad/create/'] as $path) {
            $this->assertSame('DISABLE', $this->called($path)[0][2]['operation_status'], "$path is created paused");
        }
        $group = $this->called('/adgroup/create/')[0][2];
        $this->assertSame(['357994', '2921044'], $group['location_ids']);
        $this->assertSame(30.0, (float) $group['budget']);
        $this->assertSame('CLICK', $group['optimization_goal']);
        $creative = $this->called('/ad/create/')[0][2]['creatives'][0];
        $this->assertSame('SINGLE_VIDEO', $creative['ad_format']);
        $this->assertSame(['img9'], $creative['image_ids']);
        $this->assertSame('https://ex.com/luxor', $creative['landing_page_url']);
    }

    public function testFailureAfterTheCampaignExistsDeletesIt(): void
    {
        $this->transport(['/adgroup/create/' => ['code' => 40002, 'message' => 'Budget too low']]);
        $r = $this->create();
        $this->assertFalse($r['success']);
        $this->assertSame('adgroup', $r['step']);
        $this->assertStringContainsString('Budget too low', $r['error']);
        $del = $this->called('/campaign/status/update/');
        $this->assertCount(1, $del);
        $this->assertSame('DELETE', $del[0][2]['operation_status']);
        $this->assertSame(['c1'], $del[0][2]['campaign_ids']);
        $this->assertSame([], $this->called('/ad/create/'));
    }

    public function testPreflightAndMediaFailuresCreateNothing(): void
    {
        $this->transport();
        $this->assertSame('preflight', $this->create(['video_url' => 'not a link'])['step']);
        $this->assertSame('preflight', $this->create(['countries' => []])['step']);
        $this->assertSame([], $this->called('/campaign/create/'));

        $this->transport(['/tools/region/' => ['code' => 0, 'message' => 'OK', 'data' => ['region_info' => [['region_code' => 'FR', 'location_id' => '1']]]]]);
        $this->assertSame('targeting', $this->create()['step']);

        $this->transport(['/file/video/ad/info/' => ['code' => 0, 'message' => 'OK', 'data' => ['list' => [['video_id' => 'v123']]]]]);
        $r = $this->create();
        $this->assertSame('video', $r['step']);
        $this->assertTrue($r['uncertain']);
        $this->assertSame([], $this->called('/campaign/create/'), 'an unprocessed video never produces a campaign');
    }

    public function testTokenExchangeErrorsDoNotLeakSecrets(): void
    {
        $this->transport(['/oauth2/access_token/' => ['code' => 40100, 'message' => 'Invalid auth_code']]);
        $r = TikTokAdsAPI::exchangeAuthCode('APP', 'SECRET-VALUE', 'bad');
        $this->assertFalse($r['success']);
        $this->assertStringNotContainsString('SECRET-VALUE', json_encode($r));
        $this->transport(['/oauth2/access_token/' => ['code' => 0, 'message' => 'OK', 'data' => ['access_token' => 'TT-TOKEN', 'advertiser_ids' => [111, 222]]]]);
        $ok = TikTokAdsAPI::exchangeAuthCode('APP', 'S', 'good');
        $this->assertSame(['success' => true, 'access_token' => 'TT-TOKEN', 'advertiser_ids' => ['111', '222']], $ok);
    }
}
