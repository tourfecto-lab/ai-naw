<?php

use PHPUnit\Framework\TestCase;

/** Google Performance Max: text assets, image crops and the single atomic googleAds:mutate request. */
final class GooglePmaxTest extends TestCase
{
    private function copies(): array
    {
        return [
            ['headline' => 'Luxor Day Tour', 'description' => 'Private guide and hotel pickup', 'primary_text' => 'See Karnak and the Valley of the Kings. Skip the queues with a local Egyptologist.'],
            ['headline' => 'Rated 4.9 by guests', 'description' => 'Free cancellation up to 24h', 'primary_text' => 'Small groups, no hidden fees.'],
            ['headline' => 'Book Your Luxor Trip', 'description' => 'Instant confirmation', 'primary_text' => 'Choose your date and pay online.'],
        ];
    }

    private function image(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagejpeg($im);
        $b = (string) ob_get_clean();
        imagedestroy($im);
        return $b;
    }

    private function fake(): GoogleAdsAPI
    {
        return new class('tok') extends GoogleAdsAPI {
            public array $calls = [];

            protected function request(string $method, string $path, ?array $body, ?string $loginId = null): array
            {
                $this->calls[] = [$path, $body];
                return ['success' => true, 'data' => ['mutateOperationResponses' => [
                    ['campaignBudgetResult' => ['resourceName' => 'customers/123456/campaignBudgets/777']],
                    ['campaignResult' => ['resourceName' => 'customers/123456/campaigns/999']],
                ]]];
            }
        };
    }

    public function testTextAssetsMeetGoogleMinimums(): void
    {
        $t = GoogleAdsAPI::buildPmaxTextAssets($this->copies(), [], 'Luxor Safe Tours Company International');
        $this->assertGreaterThanOrEqual(3, count($t['headlines']));
        $this->assertNotEmpty($t['long_headlines']);
        $this->assertGreaterThanOrEqual(2, count($t['descriptions']));
        $this->assertLessThanOrEqual(25, mb_strlen($t['business_name']));
        $this->assertTrue((bool) array_filter($t['descriptions'], fn ($d) => mb_strlen($d) <= 60), 'one description of 60 characters or fewer');
        foreach ($t['headlines'] as $h) { $this->assertLessThanOrEqual(30, mb_strlen($h)); }
        foreach ($t['long_headlines'] as $h) { $this->assertLessThanOrEqual(90, mb_strlen($h)); }
    }

    public function testImagesAreCroppedToGoogleRatios(): void
    {
        $src = $this->image(1600, 900);
        $land = GoogleAdsAPI::cropImage($src, 1.91, 600, 314, 1200);
        [$w, $h] = getimagesizefromstring((string) $land);
        $this->assertSame([1200, 628], [$w, $h]);
        [$w, $h] = getimagesizefromstring((string) GoogleAdsAPI::cropImage($src, 1.0, 300, 300, 1200));
        $this->assertSame([900, 900], [$w, $h], 'a square cannot be larger than the source height');
        $this->assertNull(GoogleAdsAPI::cropImage($this->image(400, 200), 1.91, 600, 314, 1200), 'too small for a marketing image');
        $this->assertNull(GoogleAdsAPI::cropImage('not an image', 1.0, 300, 300, 1200));
    }

    public function testCampaignIsOneAtomicPausedMutate(): void
    {
        $api = $this->fake();
        $r = $api->createPerformanceMaxCampaign('123-456', ['name' => 'Luxor PMax', 'daily_budget' => 25], $this->copies(), 'https://ex.com/luxor', [
            'countries' => ['EG', 'DE'], 'languages' => ['en'], 'image_bytes' => [$this->image(1600, 900)], 'business_name' => 'Luxor Safe Tours',
        ]);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame('999', $r['external_campaign_id']);
        $this->assertCount(1, $api->calls, 'a single request, so nothing is left half-created');
        [$path, $body] = $api->calls[0];
        $this->assertSame('/customers/123456/googleAds:mutate', $path);
        $ops = $body['mutateOperations'];
        $campaign = array_values(array_filter($ops, fn ($o) => isset($o['campaignOperation'])))[0]['campaignOperation']['create'];
        $this->assertSame('PERFORMANCE_MAX', $campaign['advertisingChannelType']);
        $this->assertSame('PAUSED', $campaign['status']);
        $this->assertCount(2, array_filter($ops, fn ($o) => isset($o['campaignCriterionOperation']) && isset($o['campaignCriterionOperation']['create']['location'])));
        $types = array_count_values(array_map(fn ($o) => $o['assetGroupAssetOperation']['create']['fieldType'], array_values(array_filter($ops, fn ($o) => isset($o['assetGroupAssetOperation'])))));
        $this->assertSame(1, $types['MARKETING_IMAGE']);
        $this->assertSame(1, $types['SQUARE_MARKETING_IMAGE']);
        $this->assertSame(1, $types['LOGO']);
        $this->assertSame(1, $types['BUSINESS_NAME']);
        $this->assertGreaterThanOrEqual(3, $types['HEADLINE']);
        $group = array_values(array_filter($ops, fn ($o) => isset($o['assetGroupOperation'])))[0]['assetGroupOperation']['create'];
        $this->assertSame(['https://ex.com/luxor'], $group['finalUrls']);
        $this->assertSame($campaign['resourceName'], $group['campaign']);
    }

    public function testPreflightFailuresSendNothing(): void
    {
        $api = $this->fake();
        $noImage = $api->createPerformanceMaxCampaign('123', ['name' => 'X', 'daily_budget' => 10], $this->copies(), 'https://ex.com', ['countries' => ['EG'], 'image_bytes' => [], 'business_name' => 'Biz']);
        $noCountry = $api->createPerformanceMaxCampaign('123', ['name' => 'X', 'daily_budget' => 10], $this->copies(), 'https://ex.com', ['countries' => [], 'image_bytes' => [$this->image(1600, 900)], 'business_name' => 'Biz']);
        $noName = $api->createPerformanceMaxCampaign('123', ['name' => 'X', 'daily_budget' => 10], $this->copies(), 'https://ex.com', ['countries' => ['EG'], 'image_bytes' => [$this->image(1600, 900)], 'business_name' => '']);
        foreach ([$noImage, $noCountry, $noName] as $r) {
            $this->assertFalse($r['success']);
            $this->assertSame('preflight', $r['step']);
        }
        $this->assertSame([], $api->calls);
    }
}
