<?php

use PHPUnit\Framework\TestCase;

/** ذكاء السوشيال: سجل الفحص العام، دليل المنافسين، تكلفة البحث الفعلية، وقياس «اكتشف منافسيني» بدون شبكة */
final class SocialIntelServiceTest extends TestCase
{
    private PDO $pdo;
    private const MARKET = 'ZZ-xx';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM system_settings LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        new SocialIntelService();
        $this->cleanup();
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
        $this->pdo->exec("DELETE FROM social_check_log WHERE fb_handle LIKE 'phpunit%' OR ig_handle LIKE 'phpunit%'");
        $this->pdo->exec("DELETE FROM social_market_directory WHERE market_key = '" . self::MARKET . "'");
        $this->pdo->exec('DELETE FROM social_finder_runs WHERE user_id IN (900401, 900402)');
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key LIKE 'social_comp_find_9004%' OR setting_key LIKE 'social_comp_global_%' OR setting_key = 'social_packages_config'");
        $this->pdo->exec('DELETE FROM social_competitor_pages WHERE user_id IN (900401, 900402)');
        $this->pdo->exec('DELETE FROM ci_discovery_candidates WHERE user_id IN (900401, 900402)');
        $r = new ReflectionProperty(SocialPackages::class, 'cfgCache');
        $r->setAccessible(true);
        $r->setValue(null, null);
    }

    public function testMarketKeyIsNormalised(): void
    {
        $this->assertSame('EG-ar', SocialIntelService::marketKey(['eg', 'US'], 'ar'));
        $this->assertSame('XX-en', SocialIntelService::marketKey([], 'zzz'));
    }

    public function testCheckLogStoresHandlesOnlyAndMarksConversion(): void
    {
        $svc = new SocialIntelService();
        $svc->logCheck('ar', 'phpunit_fb', 'phpunit_ig', ['instagram:rival', 'facebook:rival2'], []);
        $svc->logCheck('en', null, null, [], ['facebook', 'competitor_0', 'competitor_1']);
        $row = $this->pdo->query("SELECT * FROM social_check_log WHERE fb_handle = 'phpunit_fb'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ar', $row['lang']);
        $this->assertSame(2, (int) $row['n_comp']);
        $this->assertSame(0, (int) $row['converted']);
        $cols = array_keys($row);
        $this->assertSame([], array_values(array_intersect($cols, ['ip', 'user_agent', 'email'])), 'مفيش بيانات شخصية');

        $svc->markConverted('phpunit_fb', null);
        $this->assertSame(1, (int) $this->pdo->query("SELECT converted FROM social_check_log WHERE fb_handle = 'phpunit_fb'")->fetchColumn());
        $svc->markConverted('someone_else', null);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM social_check_log WHERE converted = 1 AND fb_handle = 'phpunit_fb'")->fetchColumn());

        $last = $this->pdo->query('SELECT errors FROM social_check_log ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('facebook,competitor', $last, 'مفاتيح الأخطاء من غير أرقام التكرار');
    }

    public function testDirectoryCountsSightingsAndNeedsTwoToSuggest(): void
    {
        $svc = new SocialIntelService();
        $svc->recordCompetitors(self::MARKET, [['domain' => 'rival-a.com', 'name' => 'Rival A', 'instagram' => 'rival_a'], ['domain' => 'rival-b.com']]);
        $this->assertSame([], $svc->directory(self::MARKET), 'ظهور واحد مش كفاية');
        $svc->recordCompetitors(self::MARKET, [['domain' => 'rival-a.com']]);
        $d = $svc->directory(self::MARKET);
        $this->assertSame(['rival-a.com'], array_column($d, 'domain'));
        $this->assertSame(2, $d[0]['seen']);
        $this->assertSame('Rival A', $d[0]['name'], 'الاسم القديم مايتمسحش');
        $this->assertSame([], $svc->directory(self::MARKET, ['rival-a.com']), 'استبعاد موقع العميل نفسه');
        $this->assertSame([], $svc->directory('ZZ-yy'), 'سوق تاني');
    }

    public function testBudgetGate(): void
    {
        $svc = new SocialIntelService();
        $this->assertTrue($svc->budgetAllows());
        SocialPackages::saveConfig(['finder_budget_usd' => 0.10], null);
        $svc->logRun(900401, 'google_data', 3, 0.0450);
        $this->assertEqualsWithDelta(0.045, $svc->spentThisMonth(), 0.0001);
        $this->assertTrue($svc->budgetAllows());
        $svc->logRun(900401, 'google_data', 2, 0.0700);
        $this->assertFalse($svc->budgetAllows(), 'وصل السقف');
        SocialPackages::saveConfig(['finder_budget_usd' => -1], null);
        $this->assertTrue($svc->budgetAllows(), '-1 = من غير سقف');
        SocialPackages::saveConfig(['finder_budget_usd' => 0], null);
        $this->assertFalse($svc->budgetAllows(), '0 = مقفول');
    }

    /** نسخة من الاكتشاف من غير شبكة */
    private function discovery(): SocialCompetitorDiscovery
    {
        return new class () extends SocialCompetitorDiscovery {
            protected function fetchPages(int $owner, array $domains, bool $force): array
            {
                foreach ($domains as $d => $name) {
                    Database::getInstance()->query("INSERT INTO social_competitor_pages (user_id, domain, name, instagram_username, checked_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE checked_at = NOW()", [$owner, $d, $name ?: null, str_replace(['.com', '-'], ['', '_'], $d)]);
                }
                return ['success' => true, 'found' => 0];
            }
        };
    }

    private function paid(array $competitors, float $cost = 0.05, ?int &$calls = null): void
    {
        SocialCompetitorDiscovery::$competitorFinder = function () use ($competitors, $cost, &$calls): array {
            $calls = ($calls ?? 0) + 1;
            return ['source' => 'google_data', 'competitors' => $competitors, 'cost' => $cost];
        };
    }

    public function testPaidSearchRecordsActualCostAndFeedsTheDirectory(): void
    {
        $calls = 0;
        $this->paid([['domain' => 'rival-a.com', 'title' => 'Rival A', 'measured' => true]], 0.05, $calls);
        $r = $this->discovery()->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $this->assertSame('google_data', $r['source']);
        $this->assertEqualsWithDelta(0.05, $r['cost'], 0.0001);
        $this->assertEqualsWithDelta(0.05, (new SocialIntelService())->spentThisMonth(), 0.0001);
        $this->assertSame(1, (int) $this->pdo->query("SELECT seen_count FROM social_market_directory WHERE domain = 'rival-a.com' AND market_key = 'ZZ-xx'")->fetchColumn());
        $this->assertSame('rival_a', $this->pdo->query("SELECT instagram_handle FROM social_market_directory WHERE domain = 'rival-a.com'")->fetchColumn());
    }

    public function testSecondCustomerWithTheSameSiteIsServedFromSharedCacheForFree(): void
    {
        $calls = 0;
        $this->paid([['domain' => 'rival-a.com', 'title' => 'Rival A', 'measured' => true]], 0.05, $calls);
        $svc = $this->discovery();
        $svc->findReal(900401, 1, 'https://same-site.example', 'xx', ['ZZ']);
        $r2 = $svc->findReal(900402, 2, 'https://same-site.example', 'xx', ['ZZ']);
        $this->assertSame(1, $calls, 'الطلب المدفوع اتعمل مرة واحدة بس');
        $this->assertSame('global_cache', $r2['source']);
        $this->assertSame(0.0, $r2['cost']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT seen_count FROM social_market_directory WHERE domain = 'rival-a.com' AND market_key = 'ZZ-xx'")->fetchColumn(), 'عميل تاني = ظهور تاني');
    }

    public function testRepeatClicksDoNotInflateTheDirectory(): void
    {
        $this->paid([['domain' => 'rival-a.com', 'title' => 'Rival A', 'measured' => true]]);
        $svc = $this->discovery();
        $svc->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $svc->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $svc->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT seen_count FROM social_market_directory WHERE domain = 'rival-a.com' AND market_key = 'ZZ-xx'")->fetchColumn());
    }

    public function testTemporaryProviderFailureIsNotCached(): void
    {
        $calls = 0;
        SocialCompetitorDiscovery::$competitorFinder = function () use (&$calls): array {
            $calls++;
            return $calls === 1
                ? ['source' => 'none', 'reason' => 'seo_monthly_budget_reached', 'competitors' => [], 'cost' => 0.0]
                : ['source' => 'google_data', 'competitors' => [['domain' => 'rival-a.com', 'title' => 'A', 'measured' => true]], 'cost' => 0.03];
        };
        $svc = $this->discovery();
        $first = $svc->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $this->assertTrue($first['none']);
        $this->assertSame('seo_monthly_budget_reached', $first['reason']);
        $second = $svc->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $this->assertSame(2, $calls, 'الفشل المؤقت مايتحفظش 30 يوم');
        $this->assertSame('google_data', $second['source']);
    }

    public function testSiteNotRankedIsCachedBecauseItIsAFinalAnswer(): void
    {
        $calls = 0;
        SocialCompetitorDiscovery::$competitorFinder = function () use (&$calls): array {
            $calls++;
            return ['source' => 'none', 'reason' => 'site_not_ranked', 'competitors' => [], 'cost' => 0.0121];
        };
        $svc = $this->discovery();
        $svc->findReal(900401, 1, 'https://brand-new.example', 'xx', ['ZZ']);
        $svc->findReal(900401, 1, 'https://brand-new.example', 'xx', ['ZZ']);
        $this->assertSame(1, $calls);
    }

    public function testBudgetReachedSkipsThePaidCall(): void
    {
        SocialPackages::saveConfig(['finder_budget_usd' => 0], null);
        $calls = 0;
        $this->paid([['domain' => 'rival-a.com', 'title' => 'A', 'measured' => true]], 0.05, $calls);
        $r = $this->discovery()->findReal(900401, 1, 'https://mine.example', 'xx', ['ZZ']);
        $this->assertTrue($r['none']);
        $this->assertSame(0, $calls);
        $this->assertSame('search_disabled', $r['reason']);
    }

    public function testFreeAccountCandidatesAreUsedBeforeAnyPaidCall(): void
    {
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (900401, 'intel-900401@tourfecto.test', 'x', 'Intel', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, created_at) VALUES (990401, 900401, 'https://mine.example', NOW()) ON DUPLICATE KEY UPDATE user_id = user_id");
        $this->pdo->exec("INSERT INTO ci_discovery_candidates (user_id, website_id, competitor_name, website, source, status) VALUES (900401, 990401, 'Local Rival', 'https://www.local-rival.com/about', 'google_places', 'pending')");
        $calls = 0;
        $this->paid([['domain' => 'x.com', 'measured' => true]], 0.05, $calls);
        try {
            $r = $this->discovery()->findReal(900401, 990401, 'https://mine.example', 'xx', ['ZZ']);
        } finally {
            $this->pdo->exec('DELETE FROM ci_discovery_candidates WHERE user_id = 900401');
            $this->pdo->exec('DELETE FROM websites WHERE id = 990401');
            $this->pdo->exec('DELETE FROM users WHERE id = 900401');
        }
        $this->assertSame('ci_candidates', $r['source']);
        $this->assertSame(0, $calls);
        $this->assertSame(['local-rival.com'], $r['domains']);
    }

    public function testDirectoryFallbackIsLabelledAndOnlyWhenNothingElseFound(): void
    {
        $intel = new SocialIntelService();
        $intel->recordCompetitors(self::MARKET, [['domain' => 'popular.com', 'name' => 'Popular']]);
        $intel->recordCompetitors(self::MARKET, [['domain' => 'popular.com']]);
        $this->paid([]);
        $r = $this->discovery()->findReal(900401, 1, 'https://new-site.example', 'xx', ['ZZ']);
        $this->assertSame('directory', $r['source']);
        $this->assertSame(['popular.com'], $r['domains']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT seen_count FROM social_market_directory WHERE domain = 'popular.com' AND market_key = 'ZZ-xx'")->fetchColumn(), 'اقتراح الدليل مايزودش عدّاده');
    }

    public function testInsightsAggregate(): void
    {
        $svc = new SocialIntelService();
        $svc->logCheck('ar', 'phpunit_a', null, ['instagram:r'], []);
        $svc->logRun(900401, 'google_data', 2, 0.05);
        $svc->logRun(900401, 'cache', 2, 0);
        $svc->recordCompetitors(self::MARKET, [['domain' => 'rival-a.com']]);
        $i = $svc->insights();
        $this->assertGreaterThanOrEqual(1, $i['checks_30d']);
        $this->assertGreaterThanOrEqual(2, $i['runs_30d']);
        $this->assertContains('phpunit_a', array_column($i['recent'], 'fb'));
        $this->assertGreaterThanOrEqual(1, $i['directory_size']);
        $this->assertEqualsWithDelta(5.0, $i['budget'], 0.001);
    }

    public function testLeanCompetitorSearchUsesTwoCallsAndReportsActualCost(): void
    {
        $client = new class () extends DataForSeoClient {
            public array $calls = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function callPlatform(string $endpoint, array $tasks, float $ceiling): array
            {
                $this->calls[] = $endpoint;
                if (str_contains($endpoint, 'domain_rank_overview')) {
                    return ['available' => true, 'cost' => 0.0201, 'response' => ['tasks' => [['result' => [['items' => [['metrics' => ['organic' => ['count' => 120, 'etv' => 800]]]]]]]]]];
                }
                $item = static fn ($d, $shared, $kw) => ['domain' => $d, 'intersections' => $shared, 'full_domain_metrics' => ['organic' => ['count' => $kw, 'etv' => 100]]];
                return ['available' => true, 'cost' => 0.0302, 'response' => ['tasks' => [['result' => [['items' => [
                    $item('rival-big.com', 40, 900), $item('booking.com', 99, 5000), $item('mytours.example', 10, 50), $item('giant-publisher.com', 70, 5000000), $item('rival-small.com', 15, 300),
                ]]]]]]];
            }
        };
        $svc = new class ($client) extends SmartOnboardingService {
            protected function verify(array $domains, int $timeout): array
            {
                return ['rival-big.com' => ['title' => 'Rival Big', 'langs' => ['en']]];
            }
        };
        $r = $svc->competitorsLean('https://mytours.example', 'en', ['US']);
        $this->assertSame(['dataforseo_labs/google/domain_rank_overview/live', 'dataforseo_labs/google/competitors_domain/live'], $client->calls);
        $this->assertEqualsWithDelta(0.0503, $r['cost'], 0.00001);
        $domains = array_column($r['competitors'], 'domain');
        $this->assertSame(['rival-big.com', 'rival-small.com'], $domains, 'من غير موقعه ولا ماركت بليس ولا ناشر عملاق، والمفتوح الأول');
        $this->assertSame('google_data', $r['source']);
        $this->assertTrue($r['competitors'][0]['measured']);
    }

    public function testLeanSearchStopsEarlyForSitesGoogleDoesNotRank(): void
    {
        $client = new class () extends DataForSeoClient {
            public int $n = 0;

            public function isConfigured(): bool
            {
                return true;
            }

            public function callPlatform(string $endpoint, array $tasks, float $ceiling): array
            {
                $this->n++;
                return ['available' => true, 'cost' => 0.0201, 'response' => ['tasks' => [['result' => [['items' => [['metrics' => ['organic' => ['count' => 0]]]]]]]]]];
            }
        };
        $r = (new SmartOnboardingService($client))->competitorsLean('https://brand-new.example', 'en');
        $this->assertSame(1, $client->n, 'طلب واحد بس بدل خمسة');
        $this->assertSame('site_not_ranked', $r['reason']);
        $this->assertSame([], $r['competitors']);
    }
}
