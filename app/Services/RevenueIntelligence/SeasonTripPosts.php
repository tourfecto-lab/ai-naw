<?php

/**
 * Tourfecto - رحلات الموسم ← منشورات سوشيال جاهزة
 *
 * 1) demand(): بيرتّب رحلات موقع العميل حسب الطلب في الموسم الحالي من الحجوزات الحقيقية:
 *    - حجوزات سفر في الـ 90 يوم الجايين (الموسم اللي داخلين عليه)  ×3
 *    - حجوزات اتعملت آخر 30 يوم (الزخم)                           ×2  + اتجاه مقارنة بالـ 30 اللي قبلهم
 *    - حجوزات نفس الموسم السنة اللي فاتت (سفر من 365 لـ 275 يوم فاتوا) ×1.5
 *    الملغية مش بتتحسب. لو مفيش حجوزات كفاية بيقول كده بصراحة وبيعرض الرحلات بترتيب الموقع.
 * 2) generate(): لكل رحلة مختارة بيعمل منشور كامل:
 *    - نص احترافي بالذكاء الصناعي (تفاصيل الرحلة + السعر + المدة + أبرز النقاط + دعوة للحجز) + هاشتاجات + أول تعليق
 *    - رابط الرحلة على موقع العميل (بيتحول لرابط تتبع حجوزات وقت النشر)
 *    - الصورة: صورة الرحلة من الموقع بعد ما الذكاء الصناعي يحللها ويديها درجة جودة؛
 *      لو جودتها 4 أو 5 ومفيهاش كلام مكتوب بتتستخدم زي ما هي، ولو أقل أو مفيش صورة بيتولّد صورة احترافية
 *      من وصف الرحلة وتحليل الصورة (بتتحسب من حصة الصور في الباقة أو المحفظة زي استوديو المحتوى).
 * * المنشورات بتتحفظ "جاهزة للمراجعة"، والعميل بيفتحها في محرر السوشيال يختار الصفحات والميعاد وينشر.
 * @version 1.0.0
 */
require_once __DIR__ . '/../SocialMedia/SocialBrandStudio.php';
require_once __DIR__ . '/SeasonSiteTrips.php';
require_once __DIR__ . '/SeasonMarketSignals.php';
require_once __DIR__ . '/SeasonTripVolumes.php';
require_once __DIR__ . '/../SocialMedia/SiteImageImporter.php';

final class SeasonTripPosts
{
    public const MAX_TRIPS = 5;
    public const GOOD_QUALITY = 4;

    /** @var callable|null fn(int $uid, array $trip, string $lang): array{success:bool, content?:string, hashtags?:array, first_comment?:string, error?:string} */
    public static $captioner = null;
    /** @var callable|null fn(string $jpeg, string $prompt): array (رؤية الصور - للاختبارات) */
    public static $vision = null;
    /** @var callable|null fn(int $uid, string $prompt, string $headline, string $key): int media id (توليد صورة - للاختبارات) */
    public static $imageGenerator = null;
    /** @var callable|null fn(string $url, int $max): ?array (تحميل صورة من رابط - للاختبارات) */
    public static $fetch = null;

    private Database $db;
    private PDO $pdo;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    public static function ready(): bool
    {
        try {
            return (bool) Database::getInstance()->query("SHOW TABLES LIKE 'season_post_drafts'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /** نفس migration ‏2026_10_24_000001 (احتياطي برا أي transaction) */
    public static function ensureSchema(): void
    {
        $pdo = Database::getInstance()->getConnection();
        if (self::ready() || $pdo->inTransaction()) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS season_post_drafts (
                id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, batch_key VARCHAR(40) NOT NULL, product_id INT(11) DEFAULT NULL,
                website_id INT(11) DEFAULT NULL, trip_slug VARCHAR(190) DEFAULT NULL, trip_name VARCHAR(255) NOT NULL, trip_url VARCHAR(1000) DEFAULT NULL,
                language VARCHAR(5) NOT NULL DEFAULT 'ar', content TEXT DEFAULT NULL, hashtags_json TEXT DEFAULT NULL, first_comment VARCHAR(1000) DEFAULT NULL,
                media_item_id INT(11) DEFAULT NULL, image_source VARCHAR(20) DEFAULT NULL, image_note VARCHAR(300) DEFAULT NULL, vision_json TEXT DEFAULT NULL,
                demand_json TEXT DEFAULT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', error VARCHAR(300) DEFAULT NULL, social_post_id INT(11) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_user (user_id, created_at), KEY idx_batch (batch_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            Logger::warning('Season posts schema failed', ['message' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ الرحلات

    /**
     * كل رحلات/غرف مواقع العميل من محتوى الموقع نفسه (حتى لو ماتسجلتش في جدول المنتجات)،
     * ومربوطة بالمنتج لو موجود عشان نقرا حجوزاتها. key ثابت لكل رحلة: p{product_id} أو w{site}-{slug}.
     */
    public function trips(int $uid): array
    {
        $sites = $this->db->query('SELECT id, slug, status, content_json, custom_domain FROM generated_websites WHERE user_id = ? ORDER BY id', [$uid]) ?: [];
        $products = [];
        foreach ($this->db->query("SELECT id, website_id, tour_slug, price, currency FROM crm_products WHERE user_id = ? AND is_active = 1 AND tour_slug IS NOT NULL AND tour_slug <> ''", [$uid]) ?: [] as $p) {
            $products[(int) $p['website_id'] . '|' . $p['tour_slug']] = $p;
        }
        $out = [];
        foreach ($sites as $site) {
            $wid = (int) $site['id'];
            $c = json_decode((string) $site['content_json'], true) ?: [];
            foreach (['tours', 'rooms'] as $key) {
                foreach ((array) ($c[$key] ?? []) as $item) {
                    $slug = is_array($item) ? trim((string) ($item['slug'] ?? '')) : '';
                    if ($slug === '' || trim((string) ($item['name'] ?? '')) === '') {
                        continue;
                    }
                    $pr = $products[$wid . '|' . $slug] ?? null;
                    $pid = $pr ? (int) $pr['id'] : null;
                    $out[] = [
                        'key' => $pid ? 'p' . $pid : 'w' . $wid . '-' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $slug), 0, 60),
                        'product_id' => $pid,
                        'website_id' => $wid,
                        'slug' => $slug,
                        'name' => (string) $item['name'],
                        'description' => mb_substr(trim((string) ($item['short_description'] ?? $item['description'] ?? '')), 0, 600),
                        'price' => trim((string) ($item['price'] ?? '')) ?: ($pr && (float) $pr['price'] > 0 ? rtrim(rtrim(number_format((float) $pr['price'], 2, '.', ''), '0'), '.') . ' ' . $pr['currency'] : ''),
                        'duration' => trim((string) ($item['duration'] ?? '')),
                        'group_size' => trim((string) ($item['group_size'] ?? $item['capacity'] ?? '')),
                        'highlights' => array_slice(array_values(array_filter(array_map(static fn ($h) => is_string($h) ? trim($h) : '', (array) ($item['highlights'] ?? [])))), 0, 6),
                        'includes' => array_slice(array_values(array_filter(array_map(static fn ($h) => is_string($h) ? trim($h) : '', (array) ($item['includes'] ?? [])))), 0, 6),
                        'image_url' => trim((string) ($item['image_url'] ?? '')),
                        'url' => self::tripUrl((string) $site['slug'], $slug, (string) ($site['custom_domain'] ?? ''), $wid),
                        'company' => trim((string) ($c['company_name'] ?? $c['name'] ?? $c['business_name'] ?? '')),
                        'published' => ($site['status'] ?? '') === 'published',
                    ];
                }
            }
        }
        // رحلات اتضافت من «الحجوزات والتوفر» أو المنتجات ومش جوه موقع من منشئ المواقع (زي موقع خارجي)
        $listed = array_flip(array_filter(array_column($out, 'product_id')));
        $siteUrl = [];
        try {
            foreach ($this->db->query('SELECT p.product_id, w.main_url FROM seo_platform_booking_products p JOIN websites w ON w.id = p.website_id AND w.user_id = p.user_id WHERE p.user_id = ?', [$uid]) ?: [] as $m) {
                $siteUrl[(int) $m['product_id']] = (string) $m['main_url'];
            }
        } catch (Throwable $e) {
            // جدول ربط الرحلات بالمواقع مش موجود على كل السيرفرات؛ الرحلة بتظهر من غير رابط.
        }
        foreach ($this->db->query('SELECT id, name, description, price, currency FROM crm_products WHERE user_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 200', [$uid]) ?: [] as $p) {
            $pid = (int) $p['id'];
            if (isset($listed[$pid]) || trim((string) $p['name']) === '') {
                continue;
            }
            $url = $siteUrl[$pid] ?? '';
            $out[] = [
                'key' => 'p' . $pid,
                'product_id' => $pid,
                'website_id' => null,
                'slug' => '',
                'name' => (string) $p['name'],
                'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 600),
                'price' => (float) $p['price'] > 0 ? rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.') . ' ' . $p['currency'] : '',
                'duration' => '',
                'group_size' => '',
                'highlights' => [],
                'includes' => [],
                'image_url' => '',
                'url' => filter_var($url, FILTER_VALIDATE_URL) ? $url : '',
                'company' => '',
                'published' => true,
            ];
        }
        // رحلات اتقرت من موقع العميل نفسه (زرار «اسحب الرحلات من موقعك»)
        $seenUrl = array_flip(array_filter(array_map(static fn ($t) => rtrim(strtolower((string) $t['url']), '/'), $out)));
        $seenName = array_flip(array_map(static fn ($t) => mb_strtolower(trim((string) $t['name'])), $out));
        foreach ((new SeasonSiteTrips($this->db))->stored($uid) as $r) {
            $u = rtrim(strtolower((string) $r['url']), '/');
            $n = mb_strtolower(trim((string) $r['name']));
            $img = (string) ($r['image_url'] ?? '');
            if (isset($seenUrl[$u]) || isset($seenName[$n])) {
                // نفس الرحلة موجودة كمنتج من غير صورة أو رابط: ناخدهم من صفحتها على الموقع
                $j = $seenUrl[$u] ?? $seenName[$n];
                if (is_int($j) && isset($out[$j])) {
                    if ($out[$j]['image_url'] === '' && $img !== '') $out[$j]['image_url'] = $img;
                    if ($out[$j]['url'] === '') $out[$j]['url'] = (string) $r['url'];
                }
                continue;
            }
            $seenUrl[$u] = count($out);
            $seenName[$n] = count($out);
            $out[] = ['key' => 's' . (int) $r['id'], 'product_id' => null, 'website_id' => null, 'slug' => '', 'name' => (string) $r['name'], 'description' => (string) ($r['description'] ?? ''),
                'price' => (string) ($r['price'] ?? ''), 'duration' => '', 'group_size' => '', 'highlights' => [], 'includes' => [], 'image_url' => $img, 'url' => (string) $r['url'], 'company' => '', 'published' => true];
        }
        return $out;
    }

    /** مفتاح ثابت للرحلة بين trips() وصفوف المنشورات المحفوظة */
    public static function rowKey($websiteId, $slug, $productId, $url): string
    {
        if ((string) $slug !== '') return (int) $websiteId . '|' . $slug;
        if ((int) $productId > 0) return 'p' . (int) $productId;
        return 'u' . sha1(rtrim(strtolower((string) $url), '/'));
    }

    /** رابط صفحة الرحلة: الدومين الخاص لو متأكد منه، وإلا رابط المنصة */
    public static function tripUrl(string $siteSlug, string $tripSlug, string $customDomain = '', int $websiteId = 0): string
    {
        $domain = preg_replace('/^www\./', '', strtolower(trim($customDomain)));
        if ($domain !== '' && $websiteId > 0 && class_exists('CustomDomainService')) {
            try {
                if (CustomDomainService::isVerified($websiteId, $domain)) {
                    return 'https://' . $domain . '/tours/' . rawurlencode($tripSlug);
                }
            } catch (Throwable $e) {
            error_log('[SeasonTripPosts] ignored error: ' . $e->getMessage());
            }
        }
        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        return $base . '/sites/' . rawurlencode($siteSlug) . '/tours/' . rawurlencode($tripSlug);
    }

    // ------------------------------------------------------------------ الطلب في الموسم

    /**
     * ترتيب الرحلات حسب الطلب في الموسم الحالي.
     * @return array{season:array, has_data:bool, trips:array}
     */
    public function demand(int $uid): array
    {
        $trips = $this->trips($uid);
        $stats = [];
        if ($trips) {
            $ids = implode(',', array_map('intval', array_filter(array_column($trips, 'product_id')))) ?: '0';
            $rows = $this->db->query(
                "SELECT product_id,
                    SUM(start_date >= CURDATE() AND start_date < DATE_ADD(CURDATE(), INTERVAL 90 DAY)) AS upcoming,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS previous,
                    SUM(start_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) AND start_date < DATE_SUB(CURDATE(), INTERVAL 275 DAY)) AS last_year,
                    SUM(CASE WHEN start_date >= CURDATE() AND start_date < DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN adults_count + COALESCE(children_count, 0) ELSE 0 END) AS travelers,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN total_amount ELSE 0 END) AS revenue_90,
                    MAX(currency) AS currency
                   FROM bookings
                  WHERE user_id = ? AND product_id IN ({$ids}) AND status NOT IN ('cancelled', 'no_show')
                  GROUP BY product_id",
                [$uid]
            ) ?: [];
            foreach ($rows as $r) {
                $stats[(int) $r['product_id']] = array_map(static fn ($v) => is_numeric($v) ? (float) $v : $v, $r);
            }
        }
        $hasData = false;
        $ar = function_exists('current_lang') ? current_lang() === 'ar' : true;
        $upcomingMonths = [];
        for ($m = 0; $m < 3; $m++) {
            $upcomingMonths[] = (int) gmdate('n', strtotime('first day of +' . $m . ' month'));
        }
        [, $rivals] = SeasonMarketSignals::load($this->db, $uid);
        $volumes = (new SeasonTripVolumes($this->db))->stored($uid);
        $market = SeasonMarketSignals::score($trips, $volumes, $rivals, $upcomingMonths, $ar);
        $hasBookings = false;
        foreach ($trips as $i => &$t) {
            $s = $t['product_id'] ? ($stats[$t['product_id']] ?? []) : [];
            $up = (int) ($s['upcoming'] ?? 0);
            $rec = (int) ($s['recent'] ?? 0);
            $prev = (int) ($s['previous'] ?? 0);
            $ly = (int) ($s['last_year'] ?? 0);
            $score = $up * 3 + $rec * 2 + $ly * 1.5 + ($rec > $prev ? min(5, $rec - $prev) : 0);
            $hasBookings = $hasBookings || $score > 0;
            $mk = $market[$i] ?? ['score' => 0, 'reasons' => [], 'search' => 0, 'competitors' => 0, 'peak' => false];
            $score += $mk['score'];
            $hasData = $hasData || $score > 0;
            $reasons = [];
            if ($up) {
                $reasons[] = $ar ? $up . ' حجز سفر في الـ 90 يوم الجايين' . ((int) ($s['travelers'] ?? 0) ? ' (' . (int) $s['travelers'] . ' مسافر)' : '') : $up . ' bookings travelling in the next 90 days';
            }
            if ($rec) {
                $trend = $prev > 0 ? (int) round(($rec - $prev) * 100 / $prev) : null;
                $reasons[] = $ar ? $rec . ' حجز اتعمل آخر 30 يوم' . ($trend !== null && $trend !== 0 ? ' (' . ($trend > 0 ? '+' : '') . $trend . '%)' : ($prev === 0 ? ' (جديد)' : '')) : $rec . ' bookings in the last 30 days' . ($trend ? ' (' . ($trend > 0 ? '+' : '') . $trend . '%)' : '');
            }
            if ($ly) {
                $reasons[] = $ar ? 'كانت مطلوبة في نفس الموسم السنة اللي فاتت (' . $ly . ' حجز)' : 'In demand this season last year (' . $ly . ' bookings)';
            }
            $reasons = array_merge($reasons, $mk['reasons']);
            $t['demand'] = ['score' => round($score, 2), 'search' => $mk['search'], 'keyword' => $mk['keyword'] ?? null, 'competitors' => $mk['competitors'], 'peak' => $mk['peak'], 'upcoming' => $up, 'recent' => $rec, 'previous' => $prev, 'last_year' => $ly, 'travelers' => (int) ($s['travelers'] ?? 0),
                'revenue_90' => round((float) ($s['revenue_90'] ?? 0), 2), 'currency' => $s['currency'] ?? null, 'reasons' => $reasons, 'order' => $i];
        }
        unset($t);
        usort($trips, static fn ($a, $b) => [$b['demand']['score'], $a['demand']['order']] <=> [$a['demand']['score'], $b['demand']['order']]);
        // المستوى بالترتيب مش بالنسبة لأعلى رقم: لما الأرقام قريبة من بعض كل الرحلات كانت بتطلع «طلب عالي».
        // أول 20% عالي، لحد 60% متوسط، والباقي قليل، ونفس الرقم بياخد نفس المستوى.
        $positive = array_values(array_filter(array_map(static fn ($t) => $t['demand']['score'], $trips), static fn ($s) => $s > 0));
        $cutHigh = $positive ? $positive[max(0, (int) ceil(count($positive) * .2) - 1)] : 0;
        $cutMid = $positive ? $positive[max(0, (int) ceil(count($positive) * .6) - 1)] : 0;
        foreach ($trips as $k => &$t) {
            $sc = $t['demand']['score'];
            $t['demand']['level'] = $sc <= 0 ? 'none' : ($sc >= $cutHigh && $sc > $cutMid ? 'high' : ($sc >= $cutMid ? 'medium' : 'low'));
            $t['demand']['rank'] = $k + 1;
        }
        unset($t);
        $months = [];
        for ($m = 0; $m < 3; $m++) {
            $months[] = gmdate('Y-m', strtotime('first day of +' . $m . ' month'));
        }
        return ['season' => ['months' => $months, 'from' => gmdate('Y-m-d'), 'to' => gmdate('Y-m-d', time() + 90 * 86400)], 'has_data' => $hasData, 'has_bookings' => $hasBookings,
            'sources' => ['search' => count($volumes) > 0, 'competitors' => count($rivals) > 0, 'search_configured' => (new DataForSeoClient())->isConfigured()], 'site_sync' => (new SeasonSiteTrips($this->db))->summary($uid), 'trips' => $trips];
    }

    // ------------------------------------------------------------------ توليد المنشورات

    /**
     * بيبدأ دفعة منشورات للرحلات المختارة (بيحفظها "pending" وبيرجّع مفتاح الدفعة؛ الشغل الفعلي في process()).
     * @param string[] $tripKeys مفاتيح الرحلات (key من trips())
     */
    public function start(int $uid, array $tripKeys, string $language, string $requestKey): array
    {
        self::ensureSchema();
        if (!self::ready()) {
            throw new InvalidArgumentException('شغّل migration رحلات الموسم (2026_10_24_000001_season_posts.sql) الأول');
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn ($k) => is_scalar($k) ? (is_numeric($k) ? 'p' . (int) $k : (string) $k) : '', $tripKeys))));
        if (!$ids) {
            throw new InvalidArgumentException('اختار رحلة واحدة على الأقل');
        }
        if (count($ids) > self::MAX_TRIPS) {
            throw new InvalidArgumentException('اختار لحد ' . self::MAX_TRIPS . ' رحلات في المرة');
        }
        $language = in_array($language, ['ar', 'en'], true) ? $language : 'ar';
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $requestKey)) {
            throw new InvalidArgumentException('Invalid request key');
        }
        $batch = 'sp_' . $uid . '_' . substr(hash('sha256', $requestKey), 0, 16);
        if ($this->db->query('SELECT id FROM season_post_drafts WHERE user_id = ? AND batch_key = ? LIMIT 1', [$uid, $batch])) {
            return ['batch' => $batch, 'already' => true];
        }
        $byId = [];
        foreach ($this->demand($uid)['trips'] as $t) {
            $byId[$t['key']] = $t;
        }
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                throw new InvalidArgumentException('رحلة من المختارين مش موجودة على موقعك');
            }
        }
        foreach ($ids as $id) {
            $t = $byId[$id];
            $this->db->query(
                "INSERT INTO season_post_drafts (user_id, batch_key, product_id, website_id, trip_slug, trip_name, trip_url, language, demand_json, status) VALUES (?,?,?,?,?,?,?,?,?, 'pending')",
                [$uid, $batch, $t['product_id'], $t['website_id'], $t['slug'], mb_substr($t['name'], 0, 255), $t['url'], $language, json_encode($t['demand'], JSON_UNESCAPED_UNICODE)]
            );
        }
        return ['batch' => $batch, 'count' => count($ids)];
    }

    /** بيشتغل على المنشورات المعلّقة في الدفعة (من المهمة أو مباشرة). بيرجّع عدد اللي خلص */
    public function process(int $uid, string $batch, int $max = self::MAX_TRIPS): int
    {
        $rows = $this->db->query("SELECT * FROM season_post_drafts WHERE user_id = ? AND batch_key = ? AND status = 'pending' ORDER BY id LIMIT " . max(1, (int) $max), [$uid, $batch]) ?: [];
        if (!$rows) {
            return 0;
        }
        $trips = [];
        foreach ($this->trips($uid) as $t) {
            $trips[self::rowKey($t['website_id'], $t['slug'], $t['product_id'], $t['url'])] = $t;
        }
        $done = 0;
        foreach ($rows as $row) {
            // نحجز الصف عشان مهمتين ما يشتغلوش على نفس المنشور
            $s = $this->pdo->prepare("UPDATE season_post_drafts SET status = 'working' WHERE id = ? AND status = 'pending'");
            $s->execute([(int) $row['id']]);
            if ($s->rowCount() !== 1) {
                continue;
            }
            $trip = $trips[self::rowKey($row['website_id'], $row['trip_slug'], $row['product_id'], $row['trip_url'])] ?? null;
            try {
                if (!$trip) {
                    throw new RuntimeException('الرحلة اتشالت من الموقع');
                }
                $this->buildOne($uid, (int) $row['id'], $trip, (string) $row['language']);
                $done++;
            } catch (Throwable $e) {
                Logger::warning('Season post failed', ['id' => (int) $row['id'], 'message' => $e->getMessage()]);
                $this->db->query("UPDATE season_post_drafts SET status = 'failed', error = ? WHERE id = ?", [mb_substr(CustomerError::message($e->getMessage(), 422), 0, 300), (int) $row['id']]);
            }
        }
        return $done;
    }

    private function buildOne(int $uid, int $draftId, array $trip, string $lang): void
    {
        // 1) الصورة: صورة الرحلة من الموقع ← تحليل ← لو احترافية نستخدمها، وإلا نولّد صورة احترافية
        $media = null;
        $vision = null;
        $source = null;
        $note = '';
        $siteMedia = $trip['image_url'] !== '' ? $this->importTripImage($uid, $trip) : null;
        if ($siteMedia) {
            $vision = (new SocialBrandStudio($this->pdo))->learn($uid, $siteMedia, self::$vision);
            $q = (int) ($vision['quality'] ?? 0);
            if ($vision && $q >= self::GOOD_QUALITY && empty($vision['has_text'])) {
                $media = $siteMedia;
                $source = 'site_photo';
                $note = $lang === 'ar' ? 'صورة الرحلة من موقعك (جودة ' . $q . '/5)' : 'Trip photo from your site (quality ' . $q . '/5)';
            } else {
                $note = $lang === 'ar'
                    ? ($vision ? 'صورة الموقع مش احترافية كفاية للسوشيال (جودة ' . $q . '/5' . (!empty($vision['has_text']) ? '، وعليها كلام' : '') . ') - اتعملت صورة احترافية بالذكاء الصناعي' : 'ماقدرناش نحلل صورة الموقع - اتعملت صورة احترافية بالذكاء الصناعي')
                    : ($vision ? 'Site photo is not social-ready (quality ' . $q . '/5) — generated a professional image' : 'Could not analyse the site photo — generated a professional image');
            }
        } else {
            $note = $lang === 'ar' ? 'الرحلة مالهاش صورة على الموقع - اتعملت صورة احترافية بالذكاء الصناعي' : 'No trip photo on the site — generated a professional image';
        }
        if (!$media) {
            $media = $this->generateImage($uid, $trip, $vision, $draftId);
            $source = 'ai_generated';
        }

        // 2) النص: تفاصيل الرحلة + رابطها + هاشتاجات + أول تعليق
        $cap = self::$captioner ? (self::$captioner)($uid, $trip, $lang) : $this->caption($uid, $trip, $lang);
        if (empty($cap['success'])) {
            throw new RuntimeException($cap['error'] ?? 'Caption generation failed');
        }
        $content = self::ensureLink(trim((string) $cap['content']), $trip['url'], $lang);
        $tags = self::cleanTags((array) ($cap['hashtags'] ?? []), $trip);
        $this->db->query(
            "UPDATE season_post_drafts SET content = ?, hashtags_json = ?, first_comment = ?, media_item_id = ?, image_source = ?, image_note = ?, vision_json = ?, status = 'ready', error = NULL WHERE id = ?",
            [$content, json_encode($tags, JSON_UNESCAPED_UNICODE), mb_substr(trim((string) ($cap['first_comment'] ?? '')), 0, 1000) ?: null, $media, $source, mb_substr($note, 0, 300), $vision ? json_encode($vision, JSON_UNESCAPED_UNICODE) : null, $draftId]
        );
    }

    /** صورة الرحلة من الموقع ← مكتبة الوسائط (من غير تكرار) */
    private function importTripImage(int $uid, array $trip): ?int
    {
        $src = $trip['image_url'];
        // صورة على نفس السيرفر: نقراها من الملف مباشرة
        if (str_starts_with($src, '/') && !str_starts_with($src, '//')) {
            $file = realpath(ROOT_PATH . '/public_html' . parse_url($src, PHP_URL_PATH));
            $root = realpath(ROOT_PATH . '/public_html/uploads');
            if (!$file || !$root || !str_starts_with($file, $root) || !is_file($file)) {
                return null;
            }
            $existing = $this->db->query("SELECT id FROM media_items WHERE user_id = ? AND status = 'completed' AND deleted_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(generation_meta, '$.src')) = ? LIMIT 1", [$uid, $src]);
            if ($existing) {
                return (int) $existing[0]['id'];
            }
            $jpeg = SiteImageImporter::prepare((string) file_get_contents($file));
            if ($jpeg === null) {
                return null;
            }
            $dir = ROOT_PATH . '/public_html/uploads/social/' . $uid;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return null;
            }
            $path = '/uploads/social/' . $uid . '/trip_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.jpg';
            file_put_contents(ROOT_PATH . '/public_html' . $path, $jpeg[0]);
            $meta = ['source' => 'site_import', 'src' => $src, 'page_title' => $trip['name']];
            $this->pdo->prepare("INSERT INTO media_items (user_id, type, prompt, file_path, thumbnail_path, width, height, status, generation_meta) VALUES (?, 'social_image', ?, ?, ?, ?, ?, 'completed', ?)")
                ->execute([$uid, mb_substr('من موقعك: ' . $trip['name'], 0, 250), $path, $path, $jpeg[1], $jpeg[2], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            return (int) $this->pdo->lastInsertId();
        }
        if (!preg_match('#^https?://#i', $src)) {
            return null;
        }
        $existing = $this->db->query("SELECT id FROM media_items WHERE user_id = ? AND status = 'completed' AND deleted_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(generation_meta, '$.src')) = ? LIMIT 1", [$uid, $src]);
        if ($existing) {
            return (int) $existing[0]['id'];
        }
        $r = (new SiteImageImporter($this->pdo, null, self::$fetch))->importItems($uid, [['src' => $src, 'title' => $trip['name'], 'page' => $trip['url']]]);
        return $r['added'][0] ?? null;
    }

    /** صورة احترافية من وصف الرحلة (+ تحليل صورة الموقع لو موجود) - بتتحسب زي استوديو المحتوى */
    private function generateImage(int $uid, array $trip, ?array $vision, int $draftId): int
    {
        $place = $vision['place'] ?? '';
        $prompt = 'Professional travel marketing photo for a social media post about the tour "' . $trip['name'] . '"'
            . ($trip['description'] !== '' ? '. Tour: ' . mb_substr($trip['description'], 0, 300) : '')
            . ($trip['highlights'] ? '. Highlights: ' . implode(', ', array_slice($trip['highlights'], 0, 4)) : '')
            . ($place !== '' ? '. Location: ' . $place : '')
            . ($vision && !empty($vision['description']) ? '. The current site photo shows: ' . $vision['description'] . ' — create a far more attractive, realistic, well-lit version of the same experience' : '')
            . '. Authentic place, real travellers enjoying it, golden-hour natural light, vivid but realistic colours, sharp focus, no text, no logos, no watermark.';
        $key = 'season_' . $draftId;
        if (self::$imageGenerator) {
            return (int) (self::$imageGenerator)($uid, $prompt, $trip['name'], $key);
        }
        $item = (new MediaGenerationService())->requestGeneration($uid, 'instagram_post', mb_substr($prompt, 0, 1900), 'photo', null, '', [], $key);
        return (int) $item->getAttribute('id');
    }

    /** نص المنشور بالذكاء الصناعي: نفس كاتب السوشيال الاحترافي، بموضوع فيه كل تفاصيل الرحلة */
    private function caption(int $uid, array $trip, string $lang): array
    {
        $facts = [];
        $facts[] = ($lang === 'ar' ? 'الرحلة: ' : 'Tour: ') . $trip['name'];
        if ($trip['description'] !== '') {
            $facts[] = $trip['description'];
        }
        if ($trip['price'] !== '') {
            $facts[] = ($lang === 'ar' ? 'السعر: ' : 'Price: ') . $trip['price'];
        }
        if ($trip['duration'] !== '') {
            $facts[] = ($lang === 'ar' ? 'المدة: ' : 'Duration: ') . $trip['duration'];
        }
        if ($trip['highlights']) {
            $facts[] = ($lang === 'ar' ? 'أبرز ما فيها: ' : 'Highlights: ') . implode('، ', $trip['highlights']);
        }
        if ($trip['includes']) {
            $facts[] = ($lang === 'ar' ? 'شامل: ' : 'Includes: ') . implode('، ', $trip['includes']);
        }
        if ($trip['url'] !== '') {
            $facts[] = ($lang === 'ar' ? 'رابط الحجز (لازم يتكتب في المنشور زي ما هو): ' : 'Booking link (must appear as-is): ') . $trip['url'];
        }
        $topic = ($lang === 'ar'
                ? 'منشور يبيع رحلة مطلوبة في الموسم ده. اكتب السعر والمدة وأبرز النقاط بإيموجي مرتب، ودعوة واضحة للحجز من الرابط. ما تخترعش أي معلومة مش موجودة هنا. '
                : 'A post that sells a tour that is in demand this season. Include price, duration and highlights with tidy emoji bullets and a clear call to book via the link. Do not invent any facts not listed here. ')
            . implode(' | ', $facts);
        $r = (new SocialPostService())->generateCaptionPro($uid, null, mb_substr($topic, 0, 1900), ['facebook', 'instagram'], $lang, 'friendly', 'bookings', 'feed');
        if (empty($r['success'])) {
            return $r;
        }
        $v = $r['variants'][0] ?? ['content' => $r['content'] ?? '', 'hashtags' => $r['hashtags'] ?? [], 'first_comment' => ''];
        return ['success' => true, 'content' => (string) $v['content'], 'hashtags' => (array) ($v['hashtags'] ?? []), 'first_comment' => (string) ($v['first_comment'] ?? '')];
    }

    /** الرابط لازم يبقى في النص (لو الذكاء الصناعي نسيه بنضيفه في الآخر) - نقية */
    public static function ensureLink(string $content, string $url, string $lang): string
    {
        if ($url === '' || str_contains($content, $url)) {
            return $content;
        }
        return rtrim($content) . "\n\n" . ($lang === 'ar' ? '🔗 احجز من هنا: ' : '🔗 Book here: ') . $url;
    }

    /** هاشتاجات نضيفة: من غير # ولا مسافات ولا تكرار، 3-12، وبيكمّل من اسم الرحلة لو قليلة - نقية */
    public static function cleanTags(array $tags, array $trip): array
    {
        $out = [];
        foreach ($tags as $t) {
            $t = preg_replace('/[^\p{L}\p{N}_]+/u', '', (string) $t);
            if ($t !== '' && mb_strlen($t) <= 40 && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = $t;
            }
        }
        if (count($out) < 3) {
            foreach (preg_split('/[\s\-–|,،]+/u', (string) ($trip['name'] ?? '')) ?: [] as $w) {
                $w = preg_replace('/[^\p{L}\p{N}_]+/u', '', $w);
                // كلمات عامة ما تنفعش هاشتاج
                if (in_array(mb_strtolower($w), ['trip', 'tour', 'tours', 'day', 'from', 'with', 'and', 'the', 'plane', 'رحلة', 'رحلات', 'يوم', 'من', 'إلى', 'الى', 'مع'], true)) {
                    continue;
                }
                if (mb_strlen($w) >= 3 && !in_array($w, $out, true)) {
                    $out[] = $w;
                }
                if (count($out) >= 5) {
                    break;
                }
            }
        }
        return array_slice($out, 0, 12);
    }

    // ------------------------------------------------------------------ العرض

    /** منشورات دفعة (أو آخر المنشورات) بحالتها وحالة الصورة */
    public function list(int $uid, ?string $batch = null, int $limit = 30): array
    {
        if (!self::ready()) {
            return [];
        }
        $rows = $batch
            ? $this->db->query('SELECT * FROM season_post_drafts WHERE user_id = ? AND batch_key = ? ORDER BY id', [$uid, $batch])
            : $this->db->query("SELECT * FROM season_post_drafts WHERE user_id = ? AND status <> 'dismissed' ORDER BY id DESC LIMIT " . max(1, min(100, $limit)), [$uid]);
        $out = [];
        foreach ($rows ?: [] as $r) {
            $m = null;
            if ($r['media_item_id']) {
                $m = $this->db->query('SELECT id, file_path, status, error_message FROM media_items WHERE id = ? AND user_id = ? LIMIT 1', [(int) $r['media_item_id'], $uid])[0] ?? null;
            }
            $out[] = [
                'id' => (int) $r['id'], 'batch' => $r['batch_key'], 'trip_name' => $r['trip_name'], 'trip_url' => $r['trip_url'], 'language' => $r['language'],
                'content' => $r['content'], 'hashtags' => json_decode((string) $r['hashtags_json'], true) ?: [], 'first_comment' => $r['first_comment'],
                'status' => $r['status'], 'error' => $r['error'], 'image_source' => $r['image_source'], 'image_note' => $r['image_note'],
                'media' => $m ? ['id' => (int) $m['id'], 'status' => $m['status'], 'url' => $m['status'] === 'completed' && $m['file_path'] ? '/api/creative-studio/media/' . (int) $m['id'] . '/content' : null] : null,
                'demand' => json_decode((string) $r['demand_json'], true) ?: null, 'social_post_id' => $r['social_post_id'] ? (int) $r['social_post_id'] : null,
                'created_at' => $r['created_at'],
            ];
        }
        return $out;
    }

    public function get(int $uid, int $id): ?array
    {
        foreach ($this->list($uid, null, 100) as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }
        return null;
    }

    public function markUsed(int $uid, int $id, ?int $postId = null): void
    {
        $this->db->query("UPDATE season_post_drafts SET status = 'used', social_post_id = COALESCE(?, social_post_id) WHERE id = ? AND user_id = ? AND status = 'ready'", [$postId, $id, $uid]);
    }

    public function dismiss(int $uid, int $id): void
    {
        $this->db->query("UPDATE season_post_drafts SET status = 'dismissed' WHERE id = ? AND user_id = ?", [$id, $uid]);
    }
}
