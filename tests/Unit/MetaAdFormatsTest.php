<?php

use PHPUnit\Framework\TestCase;

/** Carousel and video ads on Meta: pure builders plus the createCampaign flow with a fake transport. */
final class MetaAdFormatsTest extends TestCase
{
    private array $calls = [];

    protected function tearDown(): void
    {
        MetaAdsAPI::$testTransport = null;
    }

    private function copies(): array
    {
        return [
            ['headline' => 'Luxor Day Tour', 'description' => 'Private guide', 'primary_text' => 'See Karnak.', 'call_to_action' => 'Book Now', 'variant_label' => 'A'],
            ['headline' => 'Rated 4.9', 'description' => 'Hotel pickup', 'primary_text' => 'Guests love us.', 'call_to_action' => 'Learn More', 'variant_label' => 'B'],
        ];
    }

    private function transport(string $videoStatus = 'ready'): void
    {
        $this->calls = [];
        MetaAdsAPI::$testTransport = function ($method, $path, $fields) use ($videoStatus) {
            $this->calls[] = [$method, $path, $fields];
            if ($method === 'GET' && ($fields['fields'] ?? '') === 'id,status,effective_status') return [200, ['id' => $path, 'status' => 'PAUSED', 'effective_status' => 'PAUSED']];
            if (str_ends_with($path, '/campaigns')) return [200, ['id' => 'C1']];
            if (str_ends_with($path, '/adsets')) return [200, ['id' => 'AS1']];
            if (str_ends_with($path, '/advideos')) return [200, ['id' => 'V1']];
            if ($method === 'GET' && $path === 'V1') return [200, ['status' => ['video_status' => $videoStatus], 'picture' => 'https://cdn.example/thumb.jpg']];
            if (str_ends_with($path, '/adcreatives')) return [200, ['id' => 'CR' . count($this->calls)]];
            if (str_ends_with($path, '/ads')) return [200, ['id' => 'AD' . count($this->calls)]];
            return [200, ['success' => true]];
        };
    }

    private function posted(string $suffix): array
    {
        return array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'POST' && str_ends_with($c[1], $suffix)));
    }

    public function testFormatAndVideoLinkValidation(): void
    {
        $this->assertSame('image', MetaAdsAPI::adFormat([]));
        $this->assertSame('image', MetaAdsAPI::adFormat(['format' => 'gif']));
        $this->assertSame('carousel', MetaAdsAPI::adFormat(['format' => 'Carousel']));
        $this->assertSame('video', MetaAdsAPI::adFormat(['format' => 'video']));
        $this->assertTrue(MetaAdsAPI::validVideoUrl('https://cdn.example.com/a/clip.mp4?x=1'));
        $this->assertFalse(MetaAdsAPI::validVideoUrl('http://cdn.example.com/clip.mp4'));
        $this->assertFalse(MetaAdsAPI::validVideoUrl('https://cdn.example.com/clip.exe'));
        $this->assertFalse(MetaAdsAPI::validVideoUrl('https://cdn.example.com/signed?sig=1'), 'a link without a video extension needs the trusted flag');
        $this->assertTrue(MetaAdsAPI::validVideoUrl('https://cdn.example.com/signed?sig=1', true));
    }

    public function testCarouselCardsRotateThroughTheCopies(): void
    {
        $api = new MetaAdsAPI('tok');
        $images = [['url' => 'u1', 'hash' => 'h1'], ['url' => 'u2', 'hash' => 'h2'], ['url' => 'u3', 'hash' => null], ['url' => 'u4', 'hash' => 'h4']];
        $d = $api->carouselLinkData($this->copies(), 'https://ex.com', $images, ['cta' => null], []);
        $this->assertCount(3, $d['child_attachments'], 'images that failed to upload are skipped');
        $this->assertSame(['h1', 'h2', 'h4'], array_column($d['child_attachments'], 'image_hash'));
        $this->assertSame(['Luxor Day Tour', 'Rated 4.9', 'Luxor Day Tour'], array_column($d['child_attachments'], 'name'));
        $this->assertSame('See Karnak.', $d['message']);
        $this->assertSame('https://ex.com', $d['child_attachments'][0]['link']);
    }

    public function testVideoAdUploadsWaitsAndUsesTheThumbnail(): void
    {
        $this->transport();
        $r = (new MetaAdsAPI('tok'))->createCampaign('act_1', 'PAGE1', ['name' => 'Clip', 'objective' => 'traffic', 'daily_budget' => 10], ['locations' => ['EG']], $this->copies(), 'https://ex.com', null, ['currency' => 'USD', 'format' => 'video', 'video_url' => 'https://cdn.example.com/clip.mp4']);
        $this->assertTrue($r['success'], json_encode($r));
        $up = $this->posted('/advideos')[0][2];
        $this->assertSame('https://cdn.example.com/clip.mp4', $up['file_url']);
        $spec = json_decode($this->posted('/adcreatives')[0][2]['object_story_spec'], true);
        $this->assertArrayNotHasKey('link_data', $spec);
        $this->assertSame('V1', $spec['video_data']['video_id']);
        $this->assertSame('https://cdn.example/thumb.jpg', $spec['video_data']['image_url']);
        $this->assertSame('Luxor Day Tour', $spec['video_data']['title']);
        $this->assertCount(2, $r['ad_ids'], 'one ad per approved copy');
    }

    public function testVideoStillProcessingStopsAfterTheCampaignAndAdSetOnly(): void
    {
        $this->transport('processing');
        $r = (new MetaAdsAPI('tok'))->createCampaign('act_1', 'PAGE1', ['name' => 'Clip', 'objective' => 'traffic', 'daily_budget' => 10], ['locations' => ['EG']], $this->copies(), 'https://ex.com', null, ['currency' => 'USD', 'format' => 'video', 'video_url' => 'https://cdn.example.com/clip.mp4']);
        $this->assertFalse($r['success']);
        $this->assertSame('video', $r['step']);
        $this->assertSame('C1', $r['external_campaign_id']);
        $this->assertSame([], $this->posted('/adcreatives'), 'no creative is built from an unprocessed video');
    }

    public function testVideoWithoutALinkFailsBeforeAnyMetaWrite(): void
    {
        $this->transport();
        $r = (new MetaAdsAPI('tok'))->createCampaign('act_1', 'PAGE1', ['name' => 'Clip', 'objective' => 'traffic', 'daily_budget' => 10], ['locations' => ['EG']], $this->copies(), 'https://ex.com', null, ['currency' => 'USD', 'format' => 'video', 'video_url' => 'not a link']);
        $this->assertFalse($r['success']);
        $this->assertSame('preflight', $r['step']);
        $this->assertSame([], array_filter($this->calls, static fn ($c) => $c[0] === 'POST'));
    }

    public function testCarouselWithoutUsableImagesFallsBackToASingleImageAd(): void
    {
        $this->transport();
        $r = (new MetaAdsAPI('tok'))->createCampaign('act_1', 'PAGE1', ['name' => 'Cards', 'objective' => 'traffic', 'daily_budget' => 10], ['locations' => ['EG']], $this->copies(), 'https://ex.com', null, ['currency' => 'USD', 'format' => 'carousel', 'image_urls' => []]);
        $this->assertTrue($r['success'], json_encode($r));
        $spec = json_decode($this->posted('/adcreatives')[0][2]['object_story_spec'], true);
        $this->assertArrayNotHasKey('child_attachments', $spec['link_data']);
        $this->assertStringContainsString('الكاروسيل', json_encode($r['warnings'] ?? $r['warning'] ?? '', JSON_UNESCAPED_UNICODE));
    }
}
