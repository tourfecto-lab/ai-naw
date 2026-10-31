<?php

use PHPUnit\Framework\TestCase;

/** «اكتشف منافسيني الحقيقيين»: بيعرض بس اللي اتقاس من جوجل، بكاش 7 أيام، ومن غير ما يتخطى حدود الباقة */
final class SocialCompetitorFinderTest extends TestCase
{
    private const OWNER = 900301;
    private PDO $pdo;
    private int $calls = 0;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM system_settings LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        new SocialCompetitorDiscovery(); // يعمل الجدول
        $this->cleanup();
        $this->calls = 0;
    }

    protected function tearDown(): void
    {
        SocialCompetitorDiscovery::$competitorFinder = null;
        if (isset($this->pdo)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = 'social_comp_find_" . self::OWNER . "'");
        $this->pdo->exec('DELETE FROM social_competitor_pages WHERE user_id = ' . self::OWNER);
    }

    /** نسخة من الاكتشاف من غير شبكة: بتسجّل صفحات وهمية بدل ما تفتح مواقع */
    private function service(): SocialCompetitorDiscovery
    {
        return new class () extends SocialCompetitorDiscovery {
            public array $fetched = [];

            protected function fetchPages(int $owner, array $domains, bool $force): array
            {
                $this->fetched = array_keys($domains);
                foreach ($domains as $d => $name) {
                    Database::getInstance()->query('INSERT INTO social_competitor_pages (user_id, domain, name, checked_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE checked_at = NOW()', [$owner, $d, $name ?: null]);
                }
                return ['success' => true, 'found' => 0];
            }
        };
    }

    private function finder(array $competitors, string $source = 'google_data'): void
    {
        SocialCompetitorDiscovery::$competitorFinder = function () use ($competitors, $source): array {
            $this->calls++;
            return ['source' => $source, 'competitors' => $competitors];
        };
    }

    public function testOnlyGoogleMeasuredCompetitorsAreShown(): void
    {
        $this->finder([
            ['domain' => 'rival-one.com', 'title' => 'Rival One', 'measured' => true, 'shared_keywords' => 12, 'traffic' => 900],
            ['domain' => 'ai-guess.com', 'title' => 'AI Guess', 'measured' => false],
            ['domain' => 'www.rival-two.com', 'title' => 'rival-two.com', 'measured' => true],
        ]);
        $svc = $this->service();
        $r = $svc->findReal(self::OWNER, 1, 'https://mytours.example', 'en');
        $this->assertSame(2, $r['found']);
        $this->assertSame(['rival-one.com', 'rival-two.com'], $svc->fetched);
        $this->assertSame('google_data', $r['source']);
        $names = $this->pdo->query('SELECT domain, name FROM social_competitor_pages WHERE user_id = ' . self::OWNER . ' ORDER BY domain')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('Rival One', $names['rival-one.com']);
        $this->assertNull($names['rival-two.com'], 'العنوان = الدومين مايتحفظش كاسم');
    }

    public function testNoMeasuredDataSaysNoneInsteadOfInventing(): void
    {
        $this->finder([['domain' => 'ai-guess.com', 'title' => 'AI', 'measured' => false]], 'ai_verified');
        $svc = $this->service();
        $r = $svc->findReal(self::OWNER, 1, 'https://mytours.example', 'en');
        $this->assertTrue($r['none']);
        $this->assertSame(0, $r['found']);
        $this->assertSame([], $svc->fetched);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_competitor_pages WHERE user_id = ' . self::OWNER)->fetchColumn());
    }

    public function testResultIsCachedForSevenDaysUnlessForced(): void
    {
        $this->finder([['domain' => 'rival-one.com', 'title' => 'Rival One', 'measured' => true]]);
        $svc = $this->service();
        $svc->findReal(self::OWNER, 1, 'https://mytours.example', 'en');
        $again = $svc->findReal(self::OWNER, 1, 'https://mytours.example', 'en');
        $this->assertTrue($again['cached']);
        $this->assertSame(1, $this->calls, 'الطلب المدفوع مايتكررش');
        $svc->findReal(self::OWNER, 1, 'https://mytours.example', 'en', [], true);
        $this->assertSame(2, $this->calls, 'force بيتخطى الكاش');
        // موقع تاني = كاش تاني
        $svc->findReal(self::OWNER, 1, 'https://other-site.example', 'en');
        $this->assertSame(3, $this->calls);
    }

    public function testFoundPagesJoinTheWeeklyRefreshList(): void
    {
        $this->finder([['domain' => 'rival-one.com', 'title' => 'Rival One', 'measured' => true]]);
        $this->service()->findReal(self::OWNER, 1, 'https://mytours.example', 'en');
        $m = new ReflectionMethod(SocialCompetitorDiscovery::class, 'competitorDomains');
        $m->setAccessible(true);
        $this->assertArrayHasKey('rival-one.com', $m->invoke(new SocialCompetitorDiscovery(), self::OWNER));
    }

    public function testCountryForLocationMapsSeoMarkets(): void
    {
        $this->assertSame('GB', SmartOnboardingService::countryForLocation(2826));
        $this->assertNull(SmartOnboardingService::countryForLocation(1));
    }
}
