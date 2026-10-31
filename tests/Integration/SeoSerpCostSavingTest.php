<?php

use PHPUnit\Framework\TestCase;

/** تقليل صرف DataForSEO: كاش مشترك لنتايج جوجل بين المواقع، فاصل أطول للمراكز 11-20، وسقف يومي لكل موقع */
final class SeoSerpCostSavingTest extends TestCase
{
    private PDO $pdo;
    private const KW = 'phpunit serp saving keyword';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM system_settings LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (900701, 'serp-900701@tourfecto.test', 'x', 'Serp', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, created_at) VALUES (990701, 900701, 'https://mytours.example', NOW()) ON DUPLICATE KEY UPDATE user_id = user_id");
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
            $this->pdo->exec("DELETE FROM seo_serp_cache WHERE keyword = '" . self::KW . "'");
        } catch (Throwable $e) {
        }
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = 'serp_daily_keywords_per_site'");
        $this->pdo->exec('DELETE FROM tracked_keywords WHERE user_id = 900701');
        $this->pdo->exec('DELETE FROM websites WHERE user_id = 900701');
        $this->pdo->exec('DELETE FROM users WHERE id = 900701');
    }

    /** عميل DataForSEO بدفتر حسابات وهمي (من غير محفظة ولا باقة) ورد جوجل وهمي بيعدّ الطلبات المدفوعة */
    private function source(int &$calls, int $websiteId = 990701, int $location = 2818, string $language = 'ar'): DataForSeoRankingSource
    {
        $settings = ['enabled' => '1', 'login' => 'x', 'password' => 'y', 'location_code' => $location, 'language_code' => $language, 'device' => 'desktop', 'monthly_budget_usd' => 50];
        $transport = static function (string $endpoint, array $tasks) use (&$calls): array {
            $calls++;
            $items = [['type' => 'paid', 'rank_group' => 1, 'domain' => 'ad.example', 'url' => 'https://ad.example/']];
            foreach (['rival.example' => 1, 'mytours.example' => 7, 'other.example' => 12] as $d => $rank) {
                $items[] = ['type' => 'organic', 'rank_group' => $rank, 'domain' => $d, 'url' => 'https://' . $d . '/page', 'title' => 'x', 'description' => 'y'];
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
        return new DataForSeoRankingSource($settings, null, new DataForSeoClient($settings, $transport, $ledger), $websiteId);
    }

    public function testSecondSiteReusesTheSameSerpWithoutPayingAgain(): void
    {
        $calls = 0;
        $a = $this->source($calls)->check('mytours.example', [self::KW]);
        $b = $this->source($calls)->check('rival.example', [self::KW]);
        $c = $this->source($calls)->check('missing.example', [self::KW]);
        $this->assertTrue($a['available']);
        $this->assertSame(1, $calls, 'طلب مدفوع واحد لثلاث مواقع بنفس الكلمة والسوق');
        $this->assertSame(7, $a['rankings'][0]['position']);
        $this->assertSame(1, $b['rankings'][0]['position'], 'نفس نتايج جوجل، ترتيب دومين تاني');
        $this->assertNull($c['rankings'][0]['position'], 'دومين مش في النتايج');
    }

    public function testDifferentMarketIsNotShared(): void
    {
        $calls = 0;
        $this->source($calls)->check('mytours.example', [self::KW]);
        $this->source($calls, 990701, 2840, 'en')->check('mytours.example', [self::KW]);
        $this->assertSame(2, $calls, 'سوق تاني = نتايج تانية');
    }

    public function testExpiredCacheIsRefreshed(): void
    {
        $calls = 0;
        $this->source($calls)->check('mytours.example', [self::KW]);
        $this->pdo->exec("UPDATE seo_serp_cache SET fetched_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE keyword = '" . self::KW . "'");
        $this->source($calls)->check('mytours.example', [self::KW]);
        $this->assertSame(2, $calls);
    }

    public function testCachedHitsAreCounted(): void
    {
        $calls = 0;
        $this->source($calls)->check('mytours.example', [self::KW]);
        $this->source($calls)->check('mytours.example', [self::KW]);
        $this->source($calls)->check('mytours.example', [self::KW]);
        $this->assertSame(2, (int) $this->pdo->query("SELECT hits FROM seo_serp_cache WHERE keyword = '" . self::KW . "'")->fetchColumn());
    }

    public function testCachedRowKeepsOnlyOrganicCompactItems(): void
    {
        $calls = 0;
        $this->source($calls)->check('mytours.example', [self::KW]);
        $items = json_decode((string) $this->pdo->query("SELECT items_json FROM seo_serp_cache WHERE keyword = '" . self::KW . "'")->fetchColumn(), true);
        $this->assertCount(3, $items, 'الإعلانات مابتتخزّنش');
        $this->assertSame(['type', 'url', 'domain', 'rank_group'], array_keys($items[0]));
    }

    private function addKeyword(string $kw, ?int $pos, ?string $checkedAgo): void
    {
        $st = $this->pdo->prepare('INSERT INTO tracked_keywords (user_id, website_id, keyword, current_position, last_checked_at) VALUES (900701, 990701, ?, ?, ' . ($checkedAgo === null ? 'NULL' : "DATE_SUB(UTC_TIMESTAMP(), INTERVAL $checkedAgo)") . ')');
        $st->execute([$kw, $pos]);
    }

    public function testCadenceDailyTopTenEveryThreeDaysMidWeeklyRest(): void
    {
        // مستحقة
        $this->addKeyword('new keyword', null, null);
        $this->addKeyword('top5 day old', 5, '30 HOUR');
        $this->addKeyword('mid 4 days old', 15, '4 DAY');
        $this->addKeyword('low 8 days old', 60, '8 DAY');
        // مش مستحقة
        $this->addKeyword('top5 fresh', 5, '5 HOUR');
        $this->addKeyword('mid 2 days old', 15, '2 DAY');
        $this->addKeyword('low 3 days old', 60, '3 DAY');
        $due = $this->pdo->query('SELECT keyword FROM tracked_keywords k WHERE k.website_id = 990701 AND ' . RankTrackingService::dueSql('k') . ' ORDER BY keyword')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['low 8 days old', 'mid 4 days old', 'new keyword', 'top5 day old'], $due);
    }

    public function testDailyKeywordCapDefaultsAndIsConfigurable(): void
    {
        $this->assertSame(60, RankTrackingService::dailyKeywordCap());
        $this->pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('serp_daily_keywords_per_site', '25') ON DUPLICATE KEY UPDATE setting_value = '25'");
        $this->assertSame(25, RankTrackingService::dailyKeywordCap());
        $this->pdo->exec("UPDATE system_settings SET setting_value = '9999' WHERE setting_key = 'serp_daily_keywords_per_site'");
        $this->assertSame(500, RankTrackingService::dailyKeywordCap(), 'حد أقصى');
    }
}
