<?php

use PHPUnit\Framework\TestCase;

final class FreeSocialCheckServiceTest extends TestCase
{
    private function svc(string $lang = 'en'): FreeSocialCheckService
    {
        return new FreeSocialCheckService($lang);
    }

    /** @dataProvider validLinks */
    public function testValidLinksAreNormalised(string $raw, string $expect, string $platform, string $handle, string $url): void
    {
        $r = $this->svc()->parseLink($raw, $expect);
        $this->assertTrue($r['ok'], $raw);
        $this->assertSame($platform, $r['platform']);
        $this->assertSame($handle, $r['handle']);
        $this->assertSame($url, $r['url']);
    }

    public static function validLinks(): array
    {
        return [
            'fb page' => ['https://www.facebook.com/LuxorTours', 'facebook', 'facebook', 'luxortours', 'https://www.facebook.com/luxortours'],
            'fb mobile with query' => ['https://m.facebook.com/luxortours?ref=bookmarks', 'facebook', 'facebook', 'luxortours', 'https://www.facebook.com/luxortours'],
            'fb no scheme' => ['facebook.com/luxor.tours/', 'any', 'facebook', 'luxor.tours', 'https://www.facebook.com/luxor.tours'],
            'fb numeric profile' => ['https://www.facebook.com/profile.php?id=100012345678901', 'facebook', 'facebook', '100012345678901', 'https://www.facebook.com/profile.php?id=100012345678901'],
            'fb legacy pages url' => ['https://www.facebook.com/pages/Luxor-Tours/1234567890', 'facebook', 'facebook', '1234567890', 'https://www.facebook.com/1234567890'],
            'fb plain handle in fb field' => ['luxor.tours', 'facebook', 'facebook', 'luxor.tours', 'https://www.facebook.com/luxor.tours'],
            'ig profile with query' => ['https://www.instagram.com/Luxor_Tours/?hl=en', 'instagram', 'instagram', 'luxor_tours', 'https://www.instagram.com/luxor_tours/'],
            'ig at-handle' => ['@luxor_tours', 'instagram', 'instagram', 'luxor_tours', 'https://www.instagram.com/luxor_tours/'],
            'ig competitor any' => ['instagram.com/redsea.kite', 'any', 'instagram', 'redsea.kite', 'https://www.instagram.com/redsea.kite/'],
        ];
    }

    /** @dataProvider invalidLinks */
    public function testInvalidLinksAreRejected(string $raw, string $expect): void
    {
        $this->assertFalse($this->svc()->parseLink($raw, $expect)['ok'], $raw);
    }

    public static function invalidLinks(): array
    {
        return [
            'fb post' => ['https://www.facebook.com/permalink.php?story_fbid=1&id=2', 'facebook'],
            'fb share dialog' => ['https://www.facebook.com/sharer/sharer.php?u=x', 'facebook'],
            'fb group page' => ['https://www.facebook.com/groups/luxor', 'facebook'],
            'fb home' => ['https://www.facebook.com/', 'facebook'],
            'fb too-short name' => ['https://www.facebook.com/abc', 'facebook'],
            'ig post' => ['https://www.instagram.com/p/CxyZ123/', 'instagram'],
            'ig reel' => ['https://www.instagram.com/reel/CxyZ123/', 'instagram'],
            'ig explore' => ['https://www.instagram.com/explore/tags/luxor/', 'instagram'],
            'ig in fb field' => ['https://www.instagram.com/luxor/', 'facebook'],
            'fb in ig field' => ['https://www.facebook.com/luxortours', 'instagram'],
            'other site' => ['https://www.tiktok.com/@luxor', 'any'],
            'plain word in any' => ['luxor', 'any'],
            'empty' => ['  ', 'any'],
            'ig bad chars' => ['https://www.instagram.com/lu$xor/', 'instagram'],
        ];
    }

    public function testRunNeedsAtLeastOnePage(): void
    {
        $r = $this->svc()->run(['facebook' => '', 'instagram' => '', 'competitors' => []]);
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('own', $r['field_errors']);
    }

    public function testRunReportsErrorPerField(): void
    {
        $r = $this->svc()->run(['facebook' => 'https://www.instagram.com/x1/', 'instagram' => 'https://www.instagram.com/p/AAA/', 'competitors' => ['https://example.com']]);
        $this->assertFalse($r['ok']);
        $this->assertSame(['facebook', 'instagram', 'competitor_0'], array_keys($r['field_errors']));
    }

    public function testRunBuildsPlanWithoutAnyNumbers(): void
    {
        $r = $this->svc('ar')->run([
            'facebook' => 'https://facebook.com/luxortours', 'instagram' => 'https://instagram.com/luxortours',
            'competitors' => ['https://instagram.com/rival_one', 'https://facebook.com/rivaltwo.page', 'https://instagram.com/luxortours', 'https://instagram.com/rival_one'],
        ]);
        // 4 منافسين: الحد 3 → خطأ الحد، فنكمل بالمتبقي من غير الأخطاء
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('competitors', $r['field_errors']);

        $r = $this->svc('ar')->run([
            'facebook' => 'https://facebook.com/luxortours', 'instagram' => 'https://instagram.com/luxortours',
            'competitors' => ['https://instagram.com/rival_one', 'https://facebook.com/rivaltwo.page'],
        ]);
        $this->assertTrue($r['ok']);
        $this->assertSame('luxortours', $r['own']['instagram']['handle']);
        $this->assertCount(2, $r['competitors']);
        $this->assertTrue($r['competitors'][0]['trackable']);
        $this->assertFalse($r['competitors'][1]['trackable']);
        $this->assertNotEmpty($r['measures']['own']);
        $this->assertNotEmpty($r['measures']['competitors']);
        $this->assertCount(4, $r['steps']);
        // مفيش أي رقم عن الصفحات: الفحص مابيقراش حاجة من Meta
        $this->assertSame(0, preg_match('~"(followers|likes|engagement|score)"~', json_encode($r)));
    }

    public function testOwnPageAsCompetitorAndDuplicatesAreRejected(): void
    {
        $r = $this->svc()->run(['instagram' => 'instagram.com/mine', 'competitors' => ['instagram.com/mine', 'instagram.com/other', 'https://www.instagram.com/other/']]);
        $this->assertFalse($r['ok']);
        $this->assertSame(['competitor_0', 'competitor_2'], array_keys($r['field_errors']));
    }

    public function testInstagramCompetitorsWithoutOwnInstagramWarn(): void
    {
        $r = $this->svc()->run(['facebook' => 'facebook.com/luxortours', 'competitors' => ['instagram.com/rival_one']]);
        $this->assertTrue($r['ok']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function testOnlyFacebookCompetitorsHaveNoInstagramRequirement(): void
    {
        $r = $this->svc()->run(['facebook' => 'facebook.com/luxortours', 'competitors' => ['facebook.com/rivaltwo']]);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['warnings']);
        $this->assertCount(2, $r['requirements']);
    }

    public function testRecommendedTierFollowsAdminConfig(): void
    {
        $cfg = SocialPackages::config();
        $none = FreeSocialCheckService::recommendedTier(0);
        $this->assertNotNull($none);
        $this->assertSame('basic', $none['key']);
        // basic عنده 0 منافسين بالإعدادات الافتراضية → أول باقة فيها منافسين
        $three = FreeSocialCheckService::recommendedTier(3);
        $this->assertNotNull($three);
        $this->assertGreaterThanOrEqual(3, $three['competitors']);
        $this->assertEquals($cfg['tiers'][$three['key']]['price_monthly'], $three['price_monthly']);
        $this->assertNotSame('enterprise', $three['key']);
    }

    public function testSealedIntakeRoundTripsAndRevalidatesLinks(): void
    {
        if (!defined('JWT_SECRET') && !defined('ENCRYPTION_KEY')) {
            $this->markTestSkipped('مفتاح التوقيع مش متاح في بيئة الاختبار');
        }
        $tok = FreeSocialCheckService::seal(['fb' => 'https://www.facebook.com/luxortours', 'ig' => 'https://www.instagram.com/luxortours/', 'comp' => ['https://www.instagram.com/rival_one/']]);
        $this->assertNotNull($tok);
        $o = FreeSocialCheckService::open($tok);
        $this->assertSame('luxortours', $o['fb']['handle']);
        $this->assertSame('rival_one', $o['comp'][0]['handle']);

        $this->assertNull(FreeSocialCheckService::open($tok, time() + 15 * 86400), 'منتهية');
        $this->assertNull(FreeSocialCheckService::open(substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a')), 'متلاعب بيها');
        [$body, $sig] = explode('.', $tok);
        $forged = rtrim(strtr(base64_encode(json_encode(['fb' => 'https://www.facebook.com/evilpage', 'ig' => null, 'comp' => [], 'exp' => time() + 999])), '+/', '-_'), '=') . '.' . $sig;
        $this->assertNull(FreeSocialCheckService::open($forged), 'توقيع مش بتاع المحتوى');
    }
}
