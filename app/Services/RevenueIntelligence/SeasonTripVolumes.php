<?php
/**
 * حجم البحث الحقيقي لكل رحلة بالاسم (DataForSEO keyword_overview) في السوق الأساسي لموقع العميل.
 * لكل رحلة كلمة أو اتنين من اسمها (الاسم كامل، ومن غير «from …/private/by car»)، وبناخد الأعلى.
 * النتيجة متخزنة 30 يوم؛ الطلب بيتحسب من حصة بيانات SEO زي باقي القياسات.
 */
final class SeasonTripVolumes
{
    public const MAX_SEEDS = 40;
    private const PER_CALL = 20;
    private const CEILING = 0.10;
    private Database $db;
    private DataForSeoClient $client;

    public function __construct(?Database $db = null, ?DataForSeoClient $client = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->client = $client ?? new DataForSeoClient();
    }

    public static function ensureSchema(Database $db): void
    {
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS `season_trip_volumes` (
            `user_id` INT(11) NOT NULL,
            `name_hash` CHAR(40) NOT NULL,
            `trip_name` VARCHAR(255) NOT NULL,
            `keyword` VARCHAR(255) NOT NULL,
            `volume` INT NULL,
            `market_label` VARCHAR(120) NOT NULL DEFAULT '',
            `fetched_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`, `name_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function nameHash(string $name): string
    {
        return sha1(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name))));
    }

    /** كلمات بحث من اسم الرحلة: [الاسم المنضّف، والأساس من غير مكان القيام والتفاصيل] */
    public static function keywords(string $name): array
    {
        $k = mb_strtolower($name);
        $k = preg_replace('/\([^)]*\)|\$?\d+(?:[.,]\d+)?\s*(?:usd|eur|egp|\$|€)/iu', ' ', $k);
        $k = preg_replace('/[|:–—,&+\/]+/u', ' ', $k);
        $k = preg_replace('/\b(?:private|shared|small group|guided|luxury|vip|best|top|the|with lunch|including[^-]*)\b/u', ' ', $k);
        $k = preg_replace('/\bby\s+(?:car|bus|van|train|air|flight|plane|boat)\b/u', ' ', $k);
        $k = preg_replace('/(\d)\s*-\s*(day|night)/u', '$1 $2', $k);
        $full = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $k)));
        $core = trim(preg_replace('/\s+/u', ' ', preg_replace('/\s+(?:from|departing from|pick ?up from|من)\s+.*$/u', '', $full)));
        $out = [];
        foreach ([$full, $core] as $kw) {
            $words = preg_split('/\s+/u', $kw) ?: [];
            if (count($words) >= 2 && mb_strlen($kw) <= 80 && !in_array($kw, $out, true)) $out[] = $kw;
        }
        return $out;
    }

    /** @return array<string,array{keyword:string,volume:?int,market:string,fetched_at:string}> name_hash => قياس */
    public function stored(int $uid): array
    {
        try {
            self::ensureSchema($this->db);
            $out = [];
            foreach ($this->db->query('SELECT name_hash, keyword, volume, market_label, fetched_at FROM season_trip_volumes WHERE user_id = ? AND fetched_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)', [$uid]) ?: [] as $r) {
                $out[$r['name_hash']] = ['keyword' => (string) $r['keyword'], 'volume' => $r['volume'] === null ? null : (int) $r['volume'], 'market' => (string) $r['market_label'], 'fetched_at' => (string) $r['fetched_at']];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * بيقيس الرحلات اللي ملهاش قياس في آخر 30 يوم (لحد MAX_SEEDS كلمة في المرة).
     * @param array $trips [{name}] بالترتيب اللي عاوزين نقيسه بيه
     */
    public function measure(int $uid, array $trips): array
    {
        if (!$this->client->isConfigured()) return ['available' => false, 'reason' => 'not_measured', 'measured' => 0];
        self::ensureSchema($this->db);
        $site = $this->db->query('SELECT w.id FROM websites w JOIN seo_markets m ON m.website_id = w.id WHERE w.user_id = ? ORDER BY m.is_primary DESC, w.id LIMIT 1', [$uid])[0] ?? null;
        if (!$site) $site = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id LIMIT 1', [$uid])[0] ?? null;
        if (!$site) return ['available' => false, 'reason' => 'no_website', 'measured' => 0];
        $wid = (int) $site['id'];
        $market = (new SeoMarketService($this->db))->resolve($wid, $uid);
        $label = (string) ($market['label'] ?? '');
        $have = $this->stored($uid);
        $byTrip = [];
        $seeds = [];
        foreach ($trips as $t) {
            $h = self::nameHash((string) $t['name']);
            if (isset($have[$h]) || isset($byTrip[$h])) continue;
            $kws = self::keywords((string) $t['name']);
            if (!$kws || count($seeds) + count($kws) > self::MAX_SEEDS) continue;
            $byTrip[$h] = ['name' => (string) $t['name'], 'keywords' => $kws];
            foreach ($kws as $k) $seeds[$k] = true;
        }
        if (!$seeds) return ['available' => true, 'measured' => 0, 'market' => $label];
        $vol = [];
        foreach (array_chunk(array_keys($seeds), self::PER_CALL) as $chunk) {
            $r = $this->client->call('dataforseo_labs/google/keyword_overview/live', [[
                'location_code' => (int) $market['location_code'], 'language_code' => (string) $market['language_code'], 'keywords' => $chunk, 'include_serp_info' => false]], $wid, count($chunk), self::CEILING);
            if (empty($r['available'])) {
                // ميزانية/حصة/مزود: بنوقف ونحفظ اللي اتقاس، ومش بنكتب أصفار مكان القياس الناقص
                $this->save($uid, $byTrip, $vol, $label, array_slice(array_keys($seeds), 0, count($vol)));
                return ['available' => false, 'reason' => (string) ($r['reason'] ?? 'dataforseo_request_failed'), 'measured' => count($vol), 'market' => $label];
            }
            foreach ($chunk as $k) $vol[$k] = 0; // اتقاس ومرجعش = مفيش بحث ملحوظ
            foreach (DataForSeoClient::items($r) as $item) {
                if (!is_array($item)) continue;
                $row = DataForSeoResearchService::parse($item);
                $kw = mb_strtolower($row['keyword']);
                if (isset($vol[$kw])) $vol[$kw] = (int) ($row['volume'] ?? 0);
            }
        }
        $this->save($uid, $byTrip, $vol, $label, array_keys($vol));
        return ['available' => true, 'measured' => count($byTrip), 'market' => $label];
    }

    private function save(int $uid, array $byTrip, array $vol, string $label, array $measuredKeywords): void
    {
        $measured = array_flip($measuredKeywords);
        $now = gmdate('Y-m-d H:i:s');
        foreach ($byTrip as $h => $t) {
            $best = null;
            foreach ($t['keywords'] as $k) {
                if (!isset($measured[$k]) || !array_key_exists($k, $vol)) continue 2; // الرحلة لسه متقاستش كلها
                if ($best === null || $vol[$k] > $vol[$best]) $best = $k;
            }
            $this->db->query('REPLACE INTO season_trip_volumes (user_id, name_hash, trip_name, keyword, volume, market_label, fetched_at) VALUES (?,?,?,?,?,?,?)',
                [$uid, $h, mb_substr($t['name'], 0, 255), $best, $vol[$best], mb_substr($label, 0, 120), $now]);
        }
    }
}
