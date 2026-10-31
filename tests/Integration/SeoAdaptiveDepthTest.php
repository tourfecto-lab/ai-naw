<?php

use PHPUnit\Framework\TestCase;

/** عمق بحث متكيّف: 20 للكلمات المستقرة في أول 10، مع فحص كامل (30) قبل ما نسجّل إن الكلمة خرجت - توفير من غير ما الترتيب يتسجّل غلط */
final class SeoAdaptiveDepthTest extends TestCase
{
    private PDO $pdo;
    private array $depths = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM system_settings LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        $this->cleanup();
        $this->depths = [];
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        try {
            $this->pdo->exec("DELETE FROM seo_serp_cache WHERE keyword LIKE 'phpunit depth%'");
        } catch (Throwable $e) {
        }
    }

    /** @param array<string,int> $organic دومين => ترتيبه الحقيقي في جوجل */
    private function source(array $organic): DataForSeoRankingSource
    {
        $settings = ['enabled' => '1', 'login' => 'x', 'password' => 'y', 'location_code' => 2818, 'language_code' => 'ar', 'device' => 'desktop', 'monthly_budget_usd' => 50];
        $transport = function (string $endpoint, array $tasks) use ($organic): array {
            $depth = (int) $tasks[0]['depth'];
            $this->depths[] = $depth;
            $items = [];
            foreach ($organic as $d => $rank) {
                if ($rank <= $depth) {          // جوجل بيرجّع بس لحد العمق المطلوب
                    $items[] = ['type' => 'organic', 'rank_group' => $rank, 'domain' => $d, 'url' => 'https://' . $d . '/'];
                }
            }
            return ['status_code' => 20000, 'cost' => 0.0073, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]];
        };
        $ledger = new class () {
            public function reserve(string $endpoint, int $websiteId, int $units, float $ceiling, float $cap): int
            {
                return 1;
            }

            public function settle(int $id, ?float $cost, string $status): void
            {
            }
        };
        return new DataForSeoRankingSource($settings, null, new DataForSeoClient($settings, $transport, $ledger), 1);
    }

    public function testStableTopTenKeywordUsesTwentyAndStaysAccurate(): void
    {
        $r = $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth stable'], ['phpunit depth stable' => 5]);
        $this->assertSame([20], $this->depths, 'طلب واحد بعمق 20 بدل 30');
        $this->assertSame(4, $r['rankings'][0]['position']);
    }

    public function testKeywordThatFellOutOfTopTwentyIsDoubleCheckedAtFullDepth(): void
    {
        $r = $this->source(['mine.example' => 25])->check('mine.example', ['phpunit depth fell'], ['phpunit depth fell' => 8]);
        $this->assertSame([20, 30], $this->depths, 'لقينا إنها مش في أول 20: نتحقق بالعمق الكامل');
        $this->assertSame(25, $r['rankings'][0]['position'], 'الترتيب الحقيقي اتسجّل مش «اختفت»');
    }

    public function testKeywordTrulyOutsideAnyDepthIsReportedMissingOnlyAfterFullCheck(): void
    {
        $r = $this->source(['mine.example' => 70])->check('mine.example', ['phpunit depth gone'], ['phpunit depth gone' => 6]);
        $this->assertSame([20, 30], $this->depths);
        $this->assertNull($r['rankings'][0]['position']);
    }

    public function testNoHintOrMidPositionUsesFullDepth(): void
    {
        $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth nohint']);
        $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth mid'], ['phpunit depth mid' => 15]);
        $this->assertSame([30, 30], $this->depths);
    }

    public function testDeeperCachedResultServesAShallowerRequestButNotTheOtherWayAround(): void
    {
        $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth cache']);           // 30
        $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth cache'], ['phpunit depth cache' => 3]); // يخدم من كاش 30
        $this->assertSame([30], $this->depths);
        $this->cleanup();
        $this->depths = [];
        $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth cache'], ['phpunit depth cache' => 3]); // 20
        $this->source(['mine.example' => 4])->check('mine.example', ['phpunit depth cache']);                                  // محتاج 30: كاش 20 مش كفاية
        $this->assertSame([20, 30], $this->depths);
    }

    public function testCaseAndWhitespaceVariantsCostOnce(): void
    {
        $src = $this->source(['mine.example' => 9]);
        $src->check('mine.example', ['phpunit depth Foo  Bar']);
        $src->check('mine.example', ['phpunit depth foo bar']);
        $src->check('mine.example', ['  phpunit depth FOO bar ']);
        $this->assertCount(1, $this->depths);
    }

    public function testRankTrackingServicePassesLastKnownPositionsAsHints(): void
    {
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (900801, 'depth-900801@tourfecto.test', 'x', 'Depth', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $res = RankTrackingService::batches($this->source(['mine.example' => 3]), 'mine.example', ['phpunit depth svc'], ['phpunit depth svc' => 2]);
        $this->pdo->exec('DELETE FROM users WHERE id = 900801');
        $this->assertTrue($res['available']);
        $this->assertSame([20], $this->depths);
    }

    public function testApiCacheRoundTripAndExpiry(): void
    {
        $k = hash('sha256', 'phpunit-api-cache');
        $this->assertNull(SeoApiCache::get($k, 3600));
        SeoApiCache::put($k, ['a' => 1, 'ar' => 'منافس']);
        $this->assertSame(['a' => 1, 'ar' => 'منافس'], SeoApiCache::get($k, 3600));
        $this->pdo->exec("UPDATE seo_api_cache SET fetched_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE cache_key = '$k'");
        $this->assertNull(SeoApiCache::get($k, 7 * 86400), 'انتهت صلاحيته');
        $this->pdo->exec("DELETE FROM seo_api_cache WHERE cache_key = '$k'");
    }

    public function testCompetitorAnalysisForTheSameDomainIsPaidOnce(): void
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
                if (str_contains($endpoint, 'domain_rank_overview')) {
                    return ['available' => true, 'cost' => 0.012, 'response' => ['tasks' => [['result' => [['items' => [['metrics' => ['organic' => ['count' => 120, 'etv' => 800, 'pos_1' => 2, 'pos_2_3' => 3, 'pos_4_10' => 10]]]]]]]]]];
                }
                if (str_contains($endpoint, 'competitors_domain')) {
                    return ['available' => true, 'cost' => 0.014, 'response' => ['tasks' => [['result' => [['items' => [['domain' => 'rival-big.com', 'intersections' => 30, 'full_domain_metrics' => ['organic' => ['count' => 400, 'etv' => 900]]]]]]]]]];
                }
                return ['available' => true, 'cost' => 0.01, 'response' => ['tasks' => [['result' => [['items' => []]]]]]];
            }
        };
        $svc = new class ($client) extends SmartOnboardingService {
            protected function verify(array $domains, int $timeout): array
            {
                return ['rival-big.com' => ['title' => 'Rival Big', 'langs' => ['en']]];
            }
        };
        $host = 'phpunit-cache-' . bin2hex(random_bytes(3)) . '.example';
        $first = $svc->competitors('https://' . $host, [], [], 'en', 0, ['US']);
        $callsAfterFirst = $client->n;
        $this->assertGreaterThan(0, $callsAfterFirst);
        $this->assertSame(['rival-big.com'], array_column($first['competitors'], 'domain'));
        $second = $svc->competitors('https://www.' . $host . '/', [], [], 'en', 0, ['US']);
        $this->assertSame($callsAfterFirst, $client->n, 'التحليل التاني من الكاش: صفر طلبات مدفوعة');
        $this->assertSame($first['competitors'], $second['competitors']);
        $svc->competitors('https://' . $host, [], [], 'de', 0, ['DE']);
        $this->assertGreaterThan($callsAfterFirst, $client->n, 'سوق تاني = تحليل تاني');
        $this->pdo->exec("DELETE FROM seo_api_cache WHERE payload LIKE '%rival-big.com%'");
    }
}
