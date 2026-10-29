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

    public function testCampaignListingMapsStatusObjectiveBudgetAndThirtyDayTotals(): void
    {
        $ok = fn (array $data) => ['code' => 0, 'message' => 'OK', 'data' => $data];
        $this->transport([
            '/campaign/get/' => $ok(['list' => [
                ['campaign_id' => 'c1', 'campaign_name' => 'Luxor', 'objective_type' => 'REACH', 'operation_status' => 'ENABLE', 'create_time' => '2026-10-01 10:00:00'],
                ['campaign_id' => 'c2', 'campaign_name' => 'Cairo', 'objective_type' => 'WEB_CONVERSIONS', 'operation_status' => 'DISABLE'],
                ['campaign_id' => 'c3', 'campaign_name' => 'Old', 'objective_type' => 'ODD', 'operation_status' => 'DELETE'],
            ]]),
            '/advertiser/info/' => $ok(['list' => [['advertiser_id' => 'adv1', 'name' => 'Biz', 'currency' => 'egp', 'timezone' => 'x']]]),
            '/adgroup/get/' => $ok(['list' => [
                ['campaign_id' => 'c1', 'budget' => 30, 'budget_mode' => 'BUDGET_MODE_DAY'],
                ['campaign_id' => 'c1', 'budget' => 20, 'budget_mode' => 'BUDGET_MODE_DAY'],
                ['campaign_id' => 'c2', 'budget' => 999, 'budget_mode' => 'BUDGET_MODE_TOTAL'],
            ]]),
            '/report/integrated/get/' => $ok(['list' => [
                ['dimensions' => ['campaign_id' => 'c1', 'stat_time_day' => '2026-10-28 00:00:00'], 'metrics' => ['spend' => '10.5', 'impressions' => '1000', 'clicks' => '40', 'conversion' => '2']],
                ['dimensions' => ['campaign_id' => 'c1', 'stat_time_day' => '2026-10-29 00:00:00'], 'metrics' => ['spend' => '4.25', 'impressions' => '500', 'clicks' => '10', 'conversion' => '0']],
            ]]),
        ]);
        $r = (new TikTokAdsAPI('tok'))->listCampaignsWithInsights('adv1');
        $this->assertTrue($r['success']);
        [$c1, $c2, $c3] = $r['campaigns'];
        $this->assertSame(['awareness', 'active', 50.0, 1500, 50, 14.75, 'EGP'], [$c1['objective'], $c1['status'], $c1['daily_budget'], $c1['impressions'], $c1['clicks'], $c1['spend'], $c1['currency']]);
        $this->assertSame('2026-10-28', $c1['daily'][0]['date']);
        $this->assertSame(['sales', 'paused', 0.0, 0.0], [$c2['objective'], $c2['status'], $c2['daily_budget'], $c2['spend']], 'a lifetime ad-group budget is not a daily budget');
        $this->assertSame(['traffic', 'removed'], [$c3['objective'], $c3['status']]);
    }

    public function testListingErrorIsReportedNotSwallowed(): void
    {
        $this->transport(['/campaign/get/' => ['code' => 40105, 'message' => 'Access token expired']]);
        $r = (new TikTokAdsAPI('tok'))->listCampaignsWithInsights('adv1');
        $this->assertFalse($r['success']);
        $this->assertSame('Access token expired', $r['error']);
    }

    public function testPlatformValueIsUnchangedForMetaAndGoogle(): void
    {
        $this->assertSame('meta_ads', AdsSyncService::platformValue('meta_ads'));
        $this->assertSame('google_ads', AdsSyncService::platformValue('google_ads'));
        $this->assertContains(AdsSyncService::platformValue('tiktok_ads'), ['tiktok_ads', 'manual'], 'falls back to manual until the ENUM migration is applied');
    }

    public function testPauseResumeDeleteAndBudgetWrites(): void
    {
        $this->transport(['/adgroup/update/' => ['code' => 0, 'message' => 'OK', 'data' => []]]);
        $api = new TikTokAdsAPI('tok');
        $this->assertTrue($api->pauseCampaign('adv1', 'c1')['success']);
        $this->assertTrue($api->resumeCampaign('adv1', 'c1')['success']);
        $this->assertTrue($api->deleteCampaign('adv1', 'c1')['success']);
        $this->assertSame(['DISABLE', 'ENABLE', 'DELETE'], array_map(fn ($c) => $c[2]['operation_status'], $this->called('/campaign/status/update/')));
        $this->assertTrue($api->updateAdGroupBudget('adv1', 'g1', 45.5)['success']);
        $upd = $this->called('/adgroup/update/')[0][2];
        $this->assertSame(['adv1', 'g1', 'BUDGET_MODE_DAY'], [$upd['advertiser_id'], $upd['adgroup_id'], $upd['budget_mode']]);
        $this->assertSame(45.5, (float) $upd['budget']);
    }

    public function testBudgetWriteRefusesAnAmbiguousOrEmptyTarget(): void
    {
        $this->transport();
        $api = new TikTokAdsAPI('tok');
        $this->assertFalse($api->updateAdGroupBudget('adv1', '', 20)['success']);
        $this->assertFalse($api->updateAdGroupBudget('adv1', 'g1', 0)['success']);
        $this->assertSame([], $this->called('/adgroup/update/'));
    }

    public function testListingOnlyExposesAnAdGroupWhenThereIsExactlyOne(): void
    {
        $ok = fn (array $data) => ['code' => 0, 'message' => 'OK', 'data' => $data];
        $this->transport([
            '/campaign/get/' => $ok(['list' => [['campaign_id' => 'c1', 'campaign_name' => 'One', 'objective_type' => 'TRAFFIC', 'operation_status' => 'ENABLE'], ['campaign_id' => 'c2', 'campaign_name' => 'Two', 'objective_type' => 'TRAFFIC', 'operation_status' => 'ENABLE']]]),
            '/advertiser/info/' => $ok(['list' => []]),
            '/adgroup/get/' => $ok(['list' => [
                ['adgroup_id' => 'g1', 'campaign_id' => 'c1', 'budget' => 10, 'budget_mode' => 'BUDGET_MODE_DAY'],
                ['adgroup_id' => 'g2', 'campaign_id' => 'c2', 'budget' => 10, 'budget_mode' => 'BUDGET_MODE_DAY'],
                ['adgroup_id' => 'g3', 'campaign_id' => 'c2', 'budget' => 10, 'budget_mode' => 'BUDGET_MODE_DAY'],
            ]]),
            '/report/integrated/get/' => $ok(['list' => []]),
        ]);
        [$one, $two] = (new TikTokAdsAPI('tok'))->listCampaignsWithInsights('adv1')['campaigns'];
        $this->assertSame('g1', $one['external_adset_id']);
        $this->assertNull($two['external_adset_id'], 'two ad groups: an automatic budget change would be ambiguous');
        $this->assertSame(20.0, $two['daily_budget']);
    }

    public function testScheduledSyncRunsOverTheConnectionsAndReportsCounts(): void
    {
        $r = (new AdsSyncService())->syncAll();
        $this->assertSame(['accounts', 'synced', 'failed', 'skipped'], array_keys($r));
        $this->assertSame($r['accounts'], $r['synced'] + $r['failed'] + $r['skipped']);
    }

    public function testTheScheduledJobFileExists(): void
    {
        $schedule = (string) file_get_contents(dirname(__DIR__, 2) . '/cron/schedule.php');
        preg_match_all("/'file'\s*=>\s*'([a-z0-9_]+\.php)'/i", $schedule, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $file) {
            $this->assertFileExists(dirname(__DIR__, 2) . '/cron/' . $file, "cron/schedule.php points to $file");
        }
    }
}
