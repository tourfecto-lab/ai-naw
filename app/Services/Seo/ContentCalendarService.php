<?php

/**
 * خطة المقالات (تقويم المحتوى): مقالات الشهر الجاي متوزعة على أيام، كل مقال بعنوان وكلمة وصورة وسبب.
 * مبنية على بيانات حقيقية مربوطة بباقي الخدمات:
 *  - رحلات العميل الحقيقية من موقعه (season_site_trips) وصورها
 *  - مواسم كل وجهة (SeasonMarketSignals) - بننشر قبل الموسم بشهر لشهرين عشان المقال يلحق يترتب في جوجل
 *  - أحجام البحث الحقيقية (seo_keyword_research + كلمات خطة النمو)
 *  - إجابات خطة النمو (الهدف/الموسم) والمقالات اللي اتكتبت قبل كده (مانكررش)
 * في يوم النشر: المقال بيدخل محرك المحتوى (نفس التوليد/النشر/الفهرسة) بالعنوان والصورة اللي العميل وافق عليهم.
 */
class ContentCalendarService
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    private const MONTHS_AR = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    public const MAX_PER_MONTH = 20;
    public const WRITE_AHEAD_DAYS = 2;

    private Database $db;
    private static bool $ready = false;
    /** سبب آخر فشل في تجهيز العناوين (كود قصير للتشخيص، من غير بيانات حساسة) */
    public string $lastReason = '';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$ready) return;
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS content_calendar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            website_id INT NOT NULL,
            publish_date DATE NOT NULL,
            title VARCHAR(200) NOT NULL,
            keyword VARCHAR(120) NULL,
            volume INT NULL,
            destination VARCHAR(30) NULL,
            trip_name VARCHAR(255) NULL,
            trip_url VARCHAR(700) NULL,
            reason VARCHAR(300) NULL,
            image_url VARCHAR(700) NULL,
            image_source ENUM('none','trip','upload','ai') NOT NULL DEFAULT 'none',
            image_prompt TEXT NULL,
            image_error VARCHAR(60) NULL,
            status ENUM('planned','queued','skipped') NOT NULL DEFAULT 'planned',
            seo_item_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_site_date (website_id, publish_date),
            KEY idx_due (status, publish_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    // ===================== القراءة =====================

    /** كل عناصر الخطة مع حالتها الحقيقية من محرك المحتوى (اتكتب/اتنشر/فشل) */
    public function items(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT c.*, i.status AS item_status, i.error_message AS item_error, i.article_id, a.status AS article_status, a.published_url, " . (self::imageCols() ? 'a.featured_image_path' : 'NULL AS featured_image_path') . "
               FROM content_calendar c
               LEFT JOIN seo_content_items i ON i.id = c.seo_item_id
               LEFT JOIN ai_articles a ON a.id = i.article_id
              WHERE c.website_id = ? AND c.status <> 'skipped' AND (c.publish_date >= DATE_SUB(CURDATE(), INTERVAL 45 DAY))
              ORDER BY c.publish_date ASC, c.id ASC LIMIT 200",
            [$websiteId]
        );
        $out = [];
        foreach ($rows as $r) {
            $state = 'planned';
            if ($r['status'] === 'queued') {
                $state = match (true) {
                    ($r['article_status'] ?? '') === 'published' || in_array($r['item_status'], ['indexed', 'published'], true) => 'published',
                    $r['item_status'] === 'failed' => 'failed',
                    $r['item_status'] === 'generated' => 'written',
                    default => 'writing',
                };
            }
            $img = $r['image_url'] ?: (!empty($r['featured_image_path']) && class_exists('ArticleImageService') ? ArticleImageService::publicUrl($r['featured_image_path']) : null);
            $out[] = [
                'id' => (int) $r['id'], 'date' => $r['publish_date'], 'title' => $r['title'], 'keyword' => $r['keyword'], 'volume' => $r['volume'] !== null ? (int) $r['volume'] : null,
                'destination' => $r['destination'], 'trip' => $r['trip_name'], 'trip_url' => $r['trip_url'],
                // السبب بلغة اللوحة (مش لغة المقال) ومحسوب على شهر النشر نفسه
                'reason' => self::reason($r['destination'] ? self::seasonOf((string) $r['destination'], (int) date('n', strtotime((string) $r['publish_date']))) : null, $r['volume'] !== null ? (int) $r['volume'] : null, self::L('ar', 'en') === 'ar') ?: null,
                'image' => $img, 'image_source' => $r['image_source'], 'image_error' => $r['image_error'],
                'state' => $state, 'error' => $state === 'failed' ? self::friendlyError($r['item_error']) : null, 'url' => $state === 'published' ? $r['published_url'] : null,
                'editable' => $r['status'] === 'planned',
                'article_id' => !empty($r['article_id']) ? (int) $r['article_id'] : null,
            ];
        }
        return $out;
    }

    /** أعمدة صورة المقال موجودة على السيرفر ده؟ (لو مش موجودة الـSELECT كله كان هيرجع فاضي بصمت) */
    private static function imageCols(): bool
    {
        return class_exists('ArticleImageService') && ArticleImageService::columnsReady();
    }

    public function get(int $id, int $userId): ?array
    {
        $r = $this->db->query('SELECT * FROM content_calendar WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        return $r[0] ?? null;
    }

    /** المقالات في الشهر حسب الباقة (التجربة أقل) */
    public static function perMonth(int $userId): int
    {
        if (!class_exists('PlanUsage')) return 4;
        $q = PlanUsage::quota($userId, 'article_generation');
        $limit = (int) ($q['limit'] ?? 0);
        if ($limit < 0) return 12;
        return max(2, min(self::MAX_PER_MONTH, $limit ?: 4));
    }

    // ===================== المواسم =====================

    /** موسم الوجهة بالنسبة للشهر الحالي: جوه الموسم؟ كام شهر لحد ما يبدأ؟ */
    public static function seasonOf(string $dest, ?int $month = null): ?array
    {
        $d = SeasonMarketSignals::DESTINATIONS[$dest] ?? null;
        if (!$d) return null;
        $peak = $d[1];
        $m = $month ?? (int) date('n');
        $start = null;
        foreach ($peak as $p) {
            $prev = $p === 1 ? 12 : $p - 1;
            if (!in_array($prev, $peak, true)) { $start = $p; break; }
        }
        $start ??= $peak[0];
        $until = ($start - $m + 12) % 12;
        return ['in_peak' => in_array($m, $peak, true), 'start' => $start, 'months_to_start' => $until, 'ar' => $d[2], 'en' => $d[3]];
    }

    /** أولوية النشر دلوقتي: قبل الموسم بشهر-تلات شهور هو أهم وقت (المقال محتاج وقت يترتب) */
    private static function urgency(?array $s): int
    {
        if (!$s) return 1;
        if (!$s['in_peak'] && $s['months_to_start'] >= 1 && $s['months_to_start'] <= 3) return 4;
        if ($s['in_peak']) return 3;
        return 1;
    }

    /** سبب الموعد بالنسبة لشهر النشر: قبل الموسم (ميعاد مثالي) / في الموسم / بيمهّد للموسم الجاي */
    private static function reason(?array $s, ?int $volume, bool $ar): string
    {
        $bits = [];
        if ($s) {
            $name = $ar ? $s['ar'] : $s['en'];
            $mon = fn (int $m) => $ar ? self::MONTHS_AR[$m] : date('F', mktime(0, 0, 0, $m, 1));
            if (!$s['in_peak'] && $s['months_to_start'] >= 1 && $s['months_to_start'] <= 3) {
                $bits[] = $ar ? "موسم {$name} بيبدأ في {$mon($s['start'])} — بننشر قبله عشان المقال يلحق يترتب في جوجل" : "{$name} season starts in {$mon($s['start'])} — published ahead so it ranks in time";
            } elseif ($s['in_peak']) {
                $bits[] = $ar ? "بيتنشر في موسم {$name} — وقت البحث والحجز" : "Goes live during {$name} season — when people search and book";
            } else {
                $bits[] = $ar ? "بيمهّد لموسم {$name} ({$mon($s['start'])})" : "Builds up for {$name} season ({$mon($s['start'])})";
            }
        }
        if ($volume) $bits[] = $ar ? '≈ ' . number_format($volume) . ' بحث/شهر' : '≈ ' . number_format($volume) . ' searches/mo';
        return mb_substr(implode(' · ', $bits), 0, 300);
    }

    // ===================== بناء الخطة =====================

    /** بيانات العميل الحقيقية اللي الخطة بتتبني عليها */
    private function context(int $userId, int $websiteId): array
    {
        $site = $this->db->query('SELECT id, `' . WebsiteSchema::url() . '` AS main_url, ' . WebsiteSchema::nameExpr() . ' AS name, ' . WebsiteSchema::col('main_services', 'main_services') . ', ' . WebsiteSchema::col('target_customers', 'target_customers') . ' FROM websites WHERE id = ? AND user_id = ?' . WebsiteSchema::alive() . ' LIMIT 1', [$websiteId, $userId])[0] ?? null;
        if (!$site) return [];
        $trips = [];
        if (class_exists('SeasonSiteTrips')) {
            foreach ((new SeasonSiteTrips($this->db))->stored($userId) as $t) {
                if ((int) $t['website_id'] !== $websiteId) continue;
                $dest = SeasonMarketSignals::destinations((string) $t['name'] . ' ' . (string) ($t['description'] ?? ''))[0] ?? null;
                $trips[] = ['name' => (string) $t['name'], 'url' => (string) $t['url'], 'image' => (string) ($t['image_url'] ?? ''), 'dest' => $dest];
                if (count($trips) >= 25) break;
            }
        }
        $kw = [];
        foreach ($this->db->query('SELECT keyword, MAX(volume) v FROM seo_keyword_research WHERE website_id = ? AND volume > 0 GROUP BY keyword ORDER BY v DESC LIMIT 25', [$websiteId]) as $r) {
            $kw[mb_strtolower($r['keyword'])] = ['keyword' => $r['keyword'], 'volume' => (int) $r['v']];
        }
        $answers = [];
        try {
            $gp = $this->db->query('SELECT answers_json, keywords_json FROM growth_plans WHERE user_id = ? LIMIT 1', [$userId])[0] ?? null;
            if ($gp) {
                $answers = json_decode((string) $gp['answers_json'], true) ?: [];
                foreach (json_decode((string) $gp['keywords_json'], true) ?: [] as $k) {
                    $key = mb_strtolower((string) ($k['keyword'] ?? ''));
                    if ($key !== '' && !isset($kw[$key])) $kw[$key] = ['keyword' => $k['keyword'], 'volume' => isset($k['volume']) ? (int) $k['volume'] : null];
                }
            }
        } catch (Throwable $e) {
        }
        $done = array_column($this->db->query("SELECT title FROM ai_articles WHERE website_id = ? ORDER BY id DESC LIMIT 40", [$websiteId]), 'title');
        $done = array_merge($done, array_column($this->db->query("SELECT title FROM content_calendar WHERE website_id = ? AND status <> 'skipped' ORDER BY id DESC LIMIT 40", [$websiteId]), 'title'));
        // كلمات مستهدفة قبل كده (مقالات/الخطة): مقالين على نفس الكلمة بيتنافسوا مع بعض في جوجل
        $usedKw = array_column($this->db->query("SELECT keyword FROM content_calendar WHERE website_id = ? AND status <> 'skipped' AND keyword IS NOT NULL", [$websiteId]), 'keyword');
        return ['site' => $site, 'trips' => $trips, 'keywords' => array_values($kw), 'answers' => $answers, 'done' => $done, 'used_kw' => $usedKw];
    }

    /**
     * يبني مقالات الأيام الجاية (من غير ما يمسح اللي العميل عدّله). $days = مدة الخطة.
     * @return array{success:bool, added?:int, error?:string}
     */
    public function build(int $userId, int $websiteId, int $days = 30): array
    {
        $ctx = $this->context($userId, $websiteId);
        if (!$ctx) return ['success' => false, 'error' => self::L('الموقع مش موجود', 'Website not found')];
        $per = self::perMonth($userId);
        $want = max(1, (int) round($per * $days / 30));
        $have = (int) ($this->db->query("SELECT COUNT(*) c FROM content_calendar WHERE website_id = ? AND status = 'planned' AND publish_date > CURDATE()", [$websiteId])[0]['c'] ?? 0);
        $need = $want - $have;
        if ($need <= 0) return ['success' => true, 'added' => 0];

        $lang = class_exists('ContentLanguageService') ? ContentLanguageService::forWebsite($websiteId, $userId) : 'en';
        // عناوين خطة النمو اللي لسه مستنية (من التسجيل): بتدخل الخطة بدل ما تتكتب لوحدها برّاها
        $ideas = [];
        $gp = $this->db->query('SELECT backlog_json FROM growth_plans WHERE user_id = ? AND website_id = ? LIMIT 1', [$userId, $websiteId])[0] ?? null;
        $vol = [];
        foreach ($ctx['keywords'] as $k) $vol[mb_strtolower($k['keyword'])] = $k['volume'];
        $backlog = json_decode((string) ($gp['backlog_json'] ?? ''), true) ?: [];
        $taken = array_splice($backlog, 0, $need);
        foreach ($taken as $b) {
            $t = mb_substr(trim((string) ($b['title'] ?? '')), 0, 150);
            if ($t === '') continue;
            $kw = (string) ($b['keyword'] ?? '');
            $ideas[] = ['title' => $t, 'keyword' => $kw, 'volume' => $vol[mb_strtolower($kw)] ?? null, 'trip' => null, 'dest' => SeasonMarketSignals::destinations($t . ' ' . $kw)[0] ?? null];
        }
        if (count($ideas) < $need) $ideas = array_merge($ideas, $this->ideas($ctx, $need - count($ideas), (string) $lang, $userId));
        if ($taken) $this->db->query('UPDATE growth_plans SET backlog_json = ? WHERE user_id = ?', [json_encode(array_values($backlog), JSON_UNESCAPED_UNICODE), $userId]);
        if (!$ideas) return ['success' => false, 'error' => self::L('تعذر تجهيز الخطة دلوقتي — جرّب كمان شوية', 'Could not prepare the plan right now — please try again shortly'), 'reason' => $this->lastReason ?: 'no_new_ideas'];

        // الترتيب: اللي موسمه قرّب الأول، وبعدين حجم البحث
        foreach ($ideas as &$i) {
            $i['season'] = $i['dest'] ? self::seasonOf($i['dest']) : null;
            $i['score'] = self::urgency($i['season']) * 1000000 + min(999999, (int) ($i['volume'] ?? 0));
        }
        unset($i);
        usort($ideas, fn ($a, $b) => $b['score'] <=> $a['score']);

        $dates = $this->freeDates($websiteId, count($ideas), $days);
        $ins = $this->db->getConnection()->prepare('INSERT INTO content_calendar (user_id, website_id, publish_date, title, keyword, volume, destination, trip_name, trip_url, reason, image_url, image_source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $added = 0;
        foreach ($ideas as $k => $i) {
            if (!isset($dates[$k])) break;
            $tripImg = $i['trip'] && preg_match('~^https?://~i', (string) $i['trip']['image']) ? $i['trip']['image'] : null;
            $ins->execute([$userId, $websiteId, $dates[$k], $i['title'], $i['keyword'] ?: null, $i['volume'], $i['dest'], $i['trip']['name'] ?? null, $i['trip']['url'] ?? null,
                null, $tripImg, $tripImg ? 'trip' : 'none']);
            $added++;
        }
        return ['success' => true, 'added' => $added];
    }

    /** أيام النشر: من بكرة، متوزعة بالتساوي، ومن غير يومين على نفس اليوم */
    private function freeDates(int $websiteId, int $n, int $days): array
    {
        $taken = array_flip(array_column($this->db->query("SELECT publish_date FROM content_calendar WHERE website_id = ? AND status <> 'skipped' AND publish_date > CURDATE()", [$websiteId]), 'publish_date'));
        $step = max(1, intdiv($days, max(1, $n + count($taken))));
        $out = [];
        $d = new DateTimeImmutable('tomorrow');
        for ($guard = 0; count($out) < $n && $guard < $days * 3; $guard++) {
            $s = $d->format('Y-m-d');
            if (!isset($taken[$s])) {
                $out[] = $s;
                $taken[$s] = true;
                $d = $d->modify('+' . $step . ' day');
            } else {
                $d = $d->modify('+1 day');
            }
        }
        return $out;
    }

    /**
     * عناوين بالذكاء الاصطناعي مربوطة برحلة حقيقية + كلمة بحث، وبعدين بنتأكد إن الربط سليم.
     * على دفعات (8 في الطلب): 20 عنوان في طلب واحد كان بيتقطع (الموديل بيصرف جزء من السقف في التفكير)
     * والـJSON الناقص كان بيترمي كله.
     */
    private function ideas(array $ctx, int $n, string $lang, int $userId): array
    {
        $out = [];
        for ($round = 0; count($out) < $n && $round < 4; $round++) {
            $batch = $this->ideasBatch($ctx, min(8, $n - count($out)), $lang, $userId);
            if (!$batch) break;
            foreach ($batch as $b) {
                $out[] = $b;
                $ctx['done'][] = $b['title']; // الدفعة الجاية ماتكررهاش
                if ($b['keyword'] !== '') $ctx['used_kw'][] = $b['keyword'];
            }
        }
        return array_slice($out, 0, $n);
    }

    /** يقرا JSON الرد، ولو اتقطع في النص بياخد العناوين الكاملة اللي فيه بدل ما يرمي الرد كله */
    private static function parseArticles(string $raw): array
    {
        $txt = trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
        $j = json_decode($txt, true);
        if (!is_array($j) && preg_match('/\{.*\}/s', $txt, $m)) $j = json_decode($m[0], true);
        if (is_array($j) && isset($j['articles']) && is_array($j['articles'])) return $j['articles'];
        $items = [];
        if (preg_match_all('/\{[^{}]*"title"\s*:\s*"(?:[^"\\\\]|\\\\.)*"[^{}]*\}/u', $txt, $mm)) {
            foreach ($mm[0] as $obj) {
                $o = json_decode($obj, true);
                if (is_array($o)) $items[] = $o;
            }
        }
        return $items;
    }

    private function ideasBatch(array $ctx, int $n, string $lang, int $userId): array
    {
        $trips = $ctx['trips'];
        $tripLines = [];
        foreach ($trips as $k => $t) {
            $s = $t['dest'] ? self::seasonOf($t['dest']) : null;
            $tripLines[] = $k . ') ' . $t['name'] . ($s ? ' [' . $s['en'] . ($s['in_peak'] ? ', in season now' : ', season starts in ' . date('F', mktime(0, 0, 0, $s['start'], 1))) . ']' : '');
        }
        $kwLines = array_map(fn ($k) => $k['keyword'] . ($k['volume'] ? ' (' . $k['volume'] . '/mo)' : ''), array_slice($ctx['keywords'], 0, 20));
        $goal = (string) ($ctx['answers']['goal'] ?? '');
        $prompt = "You plan the next blog articles for a tour company. Write exactly {$n} article ideas in language '{$lang}'.\n"
            . 'Company: ' . mb_substr((string) $ctx['site']['name'], 0, 100) . ' (' . $ctx['site']['main_url'] . ")\n"
            . (!empty($ctx['site']['main_services']) ? 'Services: ' . mb_substr((string) $ctx['site']['main_services'], 0, 400) . "\n" : '')
            . ($tripLines ? "Their real trips (index) name [season]:\n" . implode("\n", $tripLines) . "\n" : '')
            . ($kwLines ? 'Real search keywords with monthly volume: ' . implode(' | ', $kwLines) . "\n" : '')
            . ($goal === 'direct_bookings' ? "Goal: direct bookings instead of OTA commission.\n" : '')
            . ($ctx['done'] ? 'Already written or planned (do NOT repeat these topics): ' . implode(' | ', array_slice($ctx['done'], -40)) . "\n" : '')
            . "Rules:\n- Each article must promote one of THEIR real trips when trips are listed (set \"trip\" to its index), prefer trips whose season is starting soon or is now.\n"
            . "- Each targets one search keyword a traveller really types (use the listed keywords when they fit; otherwise a natural long-tail phrase in the article language). Every article must have a DIFFERENT keyword.\n"
            . (!empty($ctx['used_kw']) ? 'Keywords already targeted (do NOT reuse): ' . implode(' | ', array_slice($ctx['used_kw'], -40)) . "\n" : '')
            . "- Mix angles: prices and what's included, itinerary, best time to go, how to choose, practical tips, comparisons. Helpful and specific, no clickbait, max 70 characters per title.\n"
            . "- Never invent prices, awards or facts in titles.\n"
            . 'Return JSON only: {"articles":[{"title":"","keyword":"","trip":null}]}';
        try {
            $res = (new GeminiClient())->generateContent($prompt, ['task' => 'website_builder', 'user_id' => $userId, 'temperature' => 0.6, 'maxOutputTokens' => 6144, 'responseMimeType' => 'application/json']);
            if (empty($res['success'])) {
                Logger::warning('Content calendar ideas failed', ['error' => $res['error'] ?? '', 'website' => $ctx['site']['id'] ?? null]);
                $this->lastReason = 'ai_failed:' . mb_substr(preg_replace('/key=[^&\s]+/i', 'key=***', (string) ($res['error'] ?? '')), 0, 120);
                return [];
            }
            $articles = self::parseArticles((string) $res['data']);
            if (!$articles) {
                Logger::warning('Content calendar ideas unreadable', ['length' => strlen((string) $res['data']), 'start' => mb_substr((string) $res['data'], 0, 200)]);
                $this->lastReason = 'unreadable:' . strlen((string) $res['data']);
                return [];
            }
        } catch (Throwable $e) {
            Logger::warning('Content calendar ideas failed', ['error' => $e->getMessage()]);
            $this->lastReason = 'exception';
            return [];
        }
        $vol = [];
        foreach ($ctx['keywords'] as $k) $vol[mb_strtolower($k['keyword'])] = $k['volume'];
        $seen = array_flip(array_map('mb_strtolower', $ctx['done']));
        $kwSeen = array_flip(array_map('mb_strtolower', $ctx['used_kw'] ?? []));
        $out = [];
        foreach ($articles as $a) {
            if (!is_array($a)) continue;
            $title = mb_substr(trim(strip_tags((string) ($a['title'] ?? ''))), 0, 150);
            if ($title === '' || isset($seen[mb_strtolower($title)])) continue;
            $seen[mb_strtolower($title)] = true;
            $keyword = mb_substr(trim(strip_tags((string) ($a['keyword'] ?? ''))), 0, 100);
            if ($keyword !== '' && isset($kwSeen[mb_strtolower($keyword)])) continue; // نفس الكلمة لمقال تاني
            if ($keyword !== '') $kwSeen[mb_strtolower($keyword)] = true;
            $trip = (isset($a['trip']) && is_numeric($a['trip']) && isset($trips[(int) $a['trip']])) ? $trips[(int) $a['trip']] : null;
            // الوجهة من نص المقال نفسه (مش من كلام الموديل) عشان سبب الموسم يبقى صح
            $dest = SeasonMarketSignals::destinations($title . ' ' . $keyword)[0] ?? ($trip['dest'] ?? null);
            if ($trip && $trip['dest'] && $dest && $trip['dest'] !== $dest) $trip = null; // رحلة لوجهة تانية = ربط غلط
            $out[] = ['title' => $title, 'keyword' => $keyword, 'volume' => $vol[mb_strtolower($keyword)] ?? null, 'trip' => $trip, 'dest' => $dest];
            if (count($out) >= $n) break;
        }
        return $out;
    }

    // ===================== تعديل العميل =====================

    /**
     * تعديل عنوان/كلمة/تاريخ. لو التاريخ الجديد عليه مقال تاني: المقال ده واللي بعده بيتأخروا يوم يوم.
     */
    public function update(int $id, int $userId, array $f): array
    {
        $row = $this->get($id, $userId);
        if (!$row) return ['success' => false, 'error' => self::L('غير موجود', 'Not found')];
        if ($row['status'] !== 'planned') return ['success' => false, 'error' => self::L('المقال ده بدأ يتكتب ومينفعش يتعدل من هنا', 'This article is already being written and can’t be edited here')];
        $title = isset($f['title']) ? mb_substr(trim(strip_tags((string) $f['title'])), 0, 150) : $row['title'];
        $keyword = isset($f['keyword']) ? mb_substr(trim(strip_tags((string) $f['keyword'])), 0, 100) : $row['keyword'];
        if ($title === '') return ['success' => false, 'error' => self::L('العنوان مطلوب', 'Title is required')];
        $date = $row['publish_date'];
        if (isset($f['date'])) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $f['date']);
            if (!$d || $d->format('Y-m-d') !== (string) $f['date']) return ['success' => false, 'error' => self::L('تاريخ غير صحيح', 'Invalid date')];
            if ($d < new DateTimeImmutable('today')) return ['success' => false, 'error' => self::L('اختار تاريخ من النهارده أو بعده', 'Pick today or a later date')];
            if ($d > new DateTimeImmutable('+180 days')) return ['success' => false, 'error' => self::L('أقصى حد 6 شهور قدام', 'Up to 6 months ahead')];
            $date = $d->format('Y-m-d');
        }
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            if ($date !== $row['publish_date']) $this->makeRoom((int) $row['website_id'], $date, $id);
            // volume الأول: MySQL بيقيّم التعيينات بالترتيب، فلو keyword اتغيّر قبله المقارنة هتبقى دايمًا صح
            $pdo->prepare('UPDATE content_calendar SET volume = IF(keyword <=> ?, volume, NULL), title = ?, keyword = ?, publish_date = ? WHERE id = ?')->execute([$keyword ?: null, $title, $keyword ?: null, $date, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['success' => true];
    }

    /** لو اليوم محجوز: نزحلق المقالات المخططة من اليوم ده لقدّام يوم يوم لحد أول فراغ */
    private function makeRoom(int $websiteId, string $date, int $exceptId): void
    {
        $rows = $this->db->query("SELECT id, publish_date FROM content_calendar WHERE website_id = ? AND status = 'planned' AND id <> ? AND publish_date >= ? ORDER BY publish_date ASC, id ASC", [$websiteId, $exceptId, $date]);
        $cursor = $date;
        foreach ($rows as $r) {
            if ($r['publish_date'] > $cursor) break; // فيه فراغ قبله: خلاص
            $cursor = (new DateTimeImmutable($cursor))->modify('+1 day')->format('Y-m-d');
            $this->db->query('UPDATE content_calendar SET publish_date = ? WHERE id = ?', [$cursor, (int) $r['id']]);
        }
    }

    public function skip(int $id, int $userId): bool
    {
        $row = $this->get($id, $userId);
        if (!$row || $row['status'] !== 'planned') return false;
        $this->db->query("UPDATE content_calendar SET status = 'skipped' WHERE id = ?", [$id]);
        return true;
    }

    /** صورة من العميل (اترفعت وعدّت على إعادة الرسم في SiteMediaService) */
    public function setImage(int $id, int $userId, string $url, string $source): bool
    {
        $row = $this->get($id, $userId);
        if (!$row || $row['status'] !== 'planned') return false;
        $this->db->query('UPDATE content_calendar SET image_url = ?, image_source = ?, image_error = NULL WHERE id = ?', [$url, $source, $id]);
        return true;
    }

    /** صورة بالذكاء الاصطناعي لعنصر واحد (زرار «اعمل صورة» أو الكرون) */
    public function generateImage(int $id, int $userId): array
    {
        $row = $this->get($id, $userId);
        if (!$row || $row['status'] !== 'planned') return ['success' => false, 'error' => self::L('غير موجود', 'Not found')];
        if (!class_exists('ArticleImageService')) return ['success' => false, 'error' => 'unavailable'];
        $lang = class_exists('ContentLanguageService') ? ContentLanguageService::forWebsite((int) $row['website_id'], $userId) : 'en';
        $r = ArticleImageService::make()->generateStandalone((string) $row['title'], (string) $row['keyword'], (string) $lang, $userId);
        if (empty($r['success'])) {
            $this->db->query('UPDATE content_calendar SET image_error = ? WHERE id = ?', [mb_substr((string) ($r['reason'] ?? 'failed'), 0, 60), $id]);
            return ['success' => false, 'error' => self::imageError((string) ($r['reason'] ?? ''))];
        }
        $this->db->query("UPDATE content_calendar SET image_url = ?, image_source = 'ai', image_prompt = ?, image_error = NULL WHERE id = ?", [$r['rel'], mb_substr((string) ($r['prompt'] ?? ''), 0, 2000), $id]);
        return ['success' => true, 'url' => $r['url']];
    }

    private static function imageError(string $reason): string
    {
        return match ($reason) {
            'daily_limit' => self::L('وصلت لحد الصور النهارده — بكرة نكمّل', 'Daily image limit reached — we continue tomorrow'),
            'disabled' => self::L('صور المقالات مقفولة من الإعدادات', 'Article images are turned off'),
            default => self::L('تعذر عمل الصورة دلوقتي — جرّب تاني أو ارفع صورتك', 'Could not create the image right now — try again or upload your own'),
        };
    }

    // ===================== التشغيل التلقائي (الكرون) =====================

    /**
     * 1) صور بالذكاء الاصطناعي للمقالات اللي جاية خلال 10 أيام ومالهاش صورة (كام واحدة كل دورة)
     * 2) المقالات اللي يومها جه بتدخل محرك المحتوى (على قد حصة الباقة - الباقي بيستنى)
     * 3) بعد ما المقال يتكتب وقبل ما يتنشر: العنوان اللي العميل وافق عليه + الصورة بتاعة الخطة
     */
    public function tick(int $imageBudget = 3): array
    {
        $stats = ['images' => 0, 'queued' => 0, 'prepared' => 0, 'extended' => 0, 'auto_built' => 0];
        $stats['auto_built'] = $this->autoBuild(2);
        // مرة في اليوم (الساعة 4 الفجر): الخطة اللي فاضل فيها أقل من أسبوع بتتمد شهر لوحدها (للمشتركين بس)
        if ((int) date('G') === 4) {
            foreach ($this->db->query("SELECT website_id, user_id FROM content_calendar WHERE status <> 'skipped' GROUP BY website_id, user_id HAVING MAX(publish_date) < DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND MAX(created_at) < DATE_SUB(NOW(), INTERVAL 20 HOUR) LIMIT 5") as $s) {
                try {
                    if (class_exists('PlanUsage') && empty(PlanUsage::quota((int) $s['user_id'], 'article_generation')['subscription_id'])) continue;
                    $r = $this->build((int) $s['user_id'], (int) $s['website_id'], 30);
                    if (!empty($r['added'])) $stats['extended']++;
                } catch (Throwable $e) {
                    Logger::warning('Calendar extend failed', ['website_id' => $s['website_id'], 'error' => $e->getMessage()]);
                }
            }
        }
        foreach ($this->db->query("SELECT id, user_id FROM content_calendar WHERE status = 'planned' AND image_source = 'none' AND (image_error IS NULL OR updated_at < DATE_SUB(NOW(), INTERVAL 20 HOUR)) AND publish_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 10 DAY) ORDER BY publish_date ASC LIMIT 25") as $r) {
            if ($imageBudget-- <= 0) break;
            try {
                // مانصرفش على صور مقالات مش هتتكتب (مفيش باقة نشطة)
                if (class_exists('PlanUsage') && empty(PlanUsage::quota((int) $r['user_id'], 'article_generation')['subscription_id'])) { $imageBudget++; continue; }
                if (!empty($this->generateImage((int) $r['id'], (int) $r['user_id'])['success'])) $stats['images']++;
            } catch (Throwable $e) {
                Logger::warning('Calendar image failed', ['id' => $r['id'], 'error' => $e->getMessage()]);
            }
        }
        // المقال بيتكتب قبل يومه بيومين (العميل يقدر يعاينه ويعدّله)، والنشر نفسه بيستنى يومه (SeoContentService::pendingIndexItems)
        foreach ($this->db->query("SELECT * FROM content_calendar WHERE status = 'planned' AND publish_date <= DATE_ADD(CURDATE(), INTERVAL " . self::WRITE_AHEAD_DAYS . " DAY) ORDER BY publish_date ASC, id ASC LIMIT 30") as $r) {
            try {
                if ($this->queue($r)) $stats['queued']++;
            } catch (Throwable $e) {
                Logger::warning('Calendar queue failed', ['id' => $r['id'], 'error' => $e->getMessage()]);
            }
        }
        foreach ($this->db->query("SELECT c.*, i.article_id FROM content_calendar c JOIN seo_content_items i ON i.id = c.seo_item_id WHERE c.status = 'queued' AND i.status = 'generated' AND i.article_id IS NOT NULL AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)") as $r) {
            try {
                if ($this->prepareArticle($r)) $stats['prepared']++;
            } catch (Throwable $e) {
                Logger::warning('Calendar prepare failed', ['id' => $r['id'], 'error' => $e->getMessage()]);
            }
        }
        return $stats;
    }

    /**
     * العميل يلاقي خطته جاهزة من غير ما يعمل حاجة: أي موقع لعميل عنده باقة نشطة ومالوش خطة لسه
     * (عميل جديد أو قديم) - بنقرا رحلاته من موقعه لو لسه ماتقرتش، وبعدين نبني خطة الشهر.
     * محاولة واحدة كل 6 ساعات لكل موقع عشان لو الذكاء الاصطناعي واقع مانفضلش نعيد كل دورة.
     */
    private function autoBuild(int $max): int
    {
        $done = 0;
        $cache = class_exists('Cache') ? new Cache() : null;
        // عملاء عندهم موقع مبني ومنشور بس (مفيش موقع متضاف): بنربطه لوحده عشان الخطة توصله هو كمان
        try {
            foreach ($this->db->query("SELECT g.id, g.user_id, g.slug, g.custom_domain, g.content_json FROM generated_websites g
                  WHERE g.status = 'published' AND NOT EXISTS (SELECT 1 FROM websites w WHERE w.user_id = g.user_id" . WebsiteSchema::alive('w') . ")
                  ORDER BY g.id DESC LIMIT 5") as $g) {
                if (class_exists('PlanUsage') && empty(PlanUsage::quota((int) $g['user_id'], 'article_generation')['subscription_id'])) continue;
                $this->linkBuiltSite((int) $g['user_id'], $g);
            }
        } catch (Throwable $e) {
            Logger::warning('Calendar built-site link failed', ['error' => $e->getMessage()]);
        }
        $sites = $this->db->query("SELECT w.id, w.user_id FROM websites w
              WHERE COALESCE(w.`" . WebsiteSchema::url() . "`, '') <> ''" . WebsiteSchema::alive('w') . "
                AND NOT EXISTS (SELECT 1 FROM content_calendar c WHERE c.website_id = w.id)
              ORDER BY w.id DESC LIMIT 40");
        foreach ($sites as $w) {
            if ($done >= $max) break;
            $uid = (int) $w['user_id'];
            $wid = (int) $w['id'];
            $key = 'ccal_auto_' . $wid;
            if ($cache && $cache->get($key)) continue;
            if (class_exists('PlanUsage') && empty(PlanUsage::quota($uid, 'article_generation')['subscription_id'])) continue;
            if ($cache) $cache->set($key, 1, 6 * 3600);
            try {
                // الخطة أقوى لما تبقى مبنية على رحلاته الحقيقية: نقراها من موقعه الأول لو مش موجودة
                if (class_exists('SeasonSiteTrips') && !$this->db->query('SELECT 1 FROM season_site_trips WHERE website_id = ? LIMIT 1', [$wid])) {
                    try { (new SeasonSiteTrips($this->db))->sync($uid); } catch (Throwable $e) { Logger::warning('Calendar trips sync failed', ['website_id' => $wid, 'error' => $e->getMessage()]); }
                }
                $r = $this->build($uid, $wid, 30);
                $done++;
                if (!empty($r['added']) && class_exists('Notification')) {
                    // الإشعار بلغة العميل نفسه (الكرون مالوش لغة)
                    $ar = str_starts_with((string) ($this->db->query('SELECT language FROM users WHERE id = ? LIMIT 1', [$uid])[0]['language'] ?? 'ar'), 'ar');
                    Notification::notify($uid, 'content_calendar_ready', $ar ? 'خطة مقالات الشهر جاهزة 📅' : 'Your article plan is ready 📅',
                        sprintf($ar ? 'جهزنالك %d مقال بمواعيدها وصورها حسب رحلاتك ومواسمها — تقدر تعدّل أي حاجة قبل يومها.' : 'We planned %d articles with dates and images based on your trips and their seasons — edit anything before its day.', (int) $r['added']),
                        '/content-calendar');
                }
            } catch (Throwable $e) {
                Logger::warning('Calendar auto build failed', ['website_id' => $wid, 'error' => $e->getMessage()]);
            }
        }
        return $done;
    }

    /** رابط الموقع المبني العام (الدومين المخصص لو متأكد، وإلا /sites/slug) */
    public static function builtUrl(array $g): string
    {
        $d = strtolower(trim((string) ($g['custom_domain'] ?? '')));
        if ($d !== '' && class_exists('CustomDomainService') && CustomDomainService::isVerified((int) $g['id'], $d)) return 'https://' . $d;
        return rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/sites/' . $g['slug'];
    }

    /**
     * موقع مبني عندنا ← موقع في المنصة (بنفس رابطه العام) + مدونة مستضافة للنشر لو مفيش ربط نشر.
     * بيرجع id الموقع (أو 0). نفس الدالة للزرار وللكرون.
     */
    public function linkBuiltSite(int $owner, array $g): int
    {
        $url = self::builtUrl($g);
        $col = Website::urlColumn();
        $existing = $this->db->query("SELECT id FROM websites WHERE user_id = ? AND `{$col}` = ?" . WebsiteSchema::alive() . " LIMIT 1", [$owner, $url]);
        if ($existing) {
            $wid = (int) $existing[0]['id'];
        } else {
            $name = mb_substr((string) ((json_decode((string) ($g['content_json'] ?? ''), true) ?: [])['business_name'] ?? ''), 0, 250);
            // INSERT بالأعمدة الموجودة فعلًا بس (جدول الإنتاج مختلف)
            $cols = ['user_id' => $owner, $col => $url];
            if (($nc = WebsiteSchema::nameColumn()) && $name !== '') $cols[$nc] = $name;
            foreach (['created_at', 'updated_at'] as $t) if (WebsiteSchema::has($t)) $cols[$t] = date('Y-m-d H:i:s');
            $wid = (int) $this->db->query('INSERT INTO websites (`' . implode('`, `', array_keys($cols)) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
        }
        if ($wid <= 0) return 0;
        if (!$this->db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform IN ('wordpress','custom_api') AND status = 'connected' LIMIT 1", [$wid])) {
            (new HostedBlogService())->activate($owner, $wid, $url);
            PublishConnection::saveCustomApi($this->db, $wid, $owner, (new Encryption())->encrypt(bin2hex(random_bytes(8))), HostedBlogService::endpoint($wid), 'Tourfecto hosted blog');
        }
        return $wid;
    }

    private function capacity(int $userId): int
    {
        if (!class_exists('PlanUsage')) return 0;
        $q = PlanUsage::quota($userId, 'article_generation');
        if (empty($q['subscription_id'])) return 0;
        if ($q['remaining'] === null) return 10;
        $pending = (int) ($this->db->query("SELECT COUNT(*) c FROM seo_content_items i JOIN seo_content_campaigns c ON c.id = i.campaign_id WHERE c.user_id = ? AND i.status = 'queued'", [$userId])[0]['c'] ?? 0);
        return max(0, (int) $q['remaining'] - $pending);
    }

    /** «اكتبه دلوقتي»: يدخل الكتابة فورًا (من حصة الباقة) ويفضل مستني يومه للنشر */
    public function writeNow(int $id, int $userId): array
    {
        $row = $this->get($id, $userId);
        if (!$row || $row['status'] !== 'planned') return ['success' => false, 'error' => self::L('المقال ده بدأ يتكتب بالفعل', 'This article is already being written')];
        if ($this->capacity($userId) <= 0) return ['success' => false, 'error' => self::L('حصة مقالات الشهر خلصت — المقال هيتكتب بعد التجديد', 'This month’s article quota is used — it will be written after renewal')];
        if (!$this->queue($row)) return ['success' => false, 'error' => self::L('تعذر البدء دلوقتي — جرّب تاني', 'Could not start now — try again')];
        $sid = (int) ($this->db->query('SELECT seo_item_id FROM content_calendar WHERE id = ?', [$id])[0]['seo_item_id'] ?? 0);
        if ($sid > 0) $this->dispatch($sid);
        return ['success' => true];
    }

    /** يبدأ كتابة الحملة فورًا (طابور كل دقيقة) بدل ما يستنى دورة الكرون الجاية (لحد 15 دقيقة) */
    private function dispatch(int $seoItemId): void
    {
        try {
            $cid = (int) ($this->db->query('SELECT campaign_id FROM seo_content_items WHERE id = ?', [$seoItemId])[0]['campaign_id'] ?? 0);
            if ($cid > 0 && class_exists('QueueManager')) (new QueueManager($this->db))->push('SeoContentGenerateJob', ['campaign_id' => $cid]);
        } catch (Throwable $e) {
            Logger::warning('Calendar dispatch failed', ['item' => $seoItemId, 'error' => $e->getMessage()]);
        }
    }

    /** «جرّب تاني» لمقال الكتابة فشلت فيه */
    public function retry(int $id, int $userId): array
    {
        $row = $this->get($id, $userId);
        if (!$row || $row['status'] !== 'queued' || empty($row['seo_item_id'])) return ['success' => false, 'error' => self::L('المقال ده مش محتاج إعادة', 'This article doesn’t need a retry')];
        if ($this->capacity($userId) <= 0) return ['success' => false, 'error' => self::L('حصة مقالات الشهر خلصت — المقال هيتكتب بعد التجديد', 'This month’s article quota is used — it will be written after renewal')];
        $n = (int) $this->db->query("UPDATE seo_content_items SET status = 'queued', error_message = NULL, updated_at = NOW() WHERE id = ? AND status = 'failed'", [(int) $row['seo_item_id']]);
        if ($n < 1) return ['success' => false, 'error' => self::L('المقال ده مش في حالة فشل', 'This article isn’t in a failed state')];
        $this->db->query("UPDATE seo_content_campaigns c JOIN seo_content_items i ON i.campaign_id = c.id SET c.status = IF(c.status IN ('completed','draft'), 'generating', c.status) WHERE i.id = ?", [(int) $row['seo_item_id']]);
        $this->dispatch((int) $row['seo_item_id']);
        return ['success' => true];
    }

    /** رسالة فشل مفهومة للعميل (بدل الخطأ التقني) */
    public static function friendlyError(?string $err): ?string
    {
        if ($err === null || $err === '') return null;
        if (str_contains($err, 'Confirm current customer facts')) return self::L('أكّد بيانات شركتك في «بيانات الشركة» عشان نكتب بمعلومات صحيحة، وبعدين دوس «جرّب تاني»', 'Confirm your company profile in “Company details” so we write accurate facts, then press “Try again”');
        if (str_contains($err, 'SERP brief') || str_contains($err, 'budget')) return self::L('تحليل نتائج جوجل ماكانش متاح وقتها — دوس «جرّب تاني» وهنكتبه من بيانات شركتك المؤكدة', 'Google results analysis wasn’t available — press “Try again” and we’ll write it from your confirmed company facts');
        if (preg_match('/\p{Arabic}/u', $err)) return mb_substr($err, 0, 200); // رسايل الحصة/المحفظة عربي أصلًا
        return self::L('ماقدرناش نكتبه المرة دي — دوس «جرّب تاني»', 'We couldn’t write it this time — press “Try again”');
    }

    /** المعاينة: المقال بعد ما اتكتب (نفس اللي هيتنشر) */
    public function preview(int $id, int $userId): ?array
    {
        $row = $this->get($id, $userId);
        if (!$row || empty($row['seo_item_id'])) return null;
        $aid = (int) ($this->db->query('SELECT article_id FROM seo_content_items WHERE id = ? LIMIT 1', [(int) $row['seo_item_id']])[0]['article_id'] ?? 0);
        if ($aid <= 0) return null;
        $a = (new AIArticle())->find($aid);
        if (!$a) return null;
        $html = HostedBlogService::clean(ContentFormatter::markdownToHtml($a->contentForPresentation()));
        $img = null;
        if (self::imageCols()) {
            $p = $this->db->query('SELECT featured_image_path FROM ai_articles WHERE id = ? LIMIT 1', [$aid])[0]['featured_image_path'] ?? null;
            if ($p) $img = ArticleImageService::publicUrl($p);
        }
        return ['article_id' => $aid, 'title' => (string) $a->getAttribute('title'), 'meta' => (string) $a->getAttribute('meta_description'),
            'words' => (int) $a->getAttribute('word_count'), 'html' => $html, 'image' => $img ?: ($row['image_url'] ?: null), 'date' => $row['publish_date'],
            'status' => (string) $a->getAttribute('status'), 'url' => $a->getAttribute('published_url') ?: null];
    }

    private function queue(array $r): bool
    {
        $uid = (int) $r['user_id'];
        if ($this->capacity($uid) <= 0) return false; // الحصة خلصت: يفضل مستني لحد التجديد
        $camp = $this->db->query("SELECT id FROM seo_content_campaigns WHERE user_id = ? AND website_id = ? AND name = 'Content calendar' ORDER BY id DESC LIMIT 1", [$uid, (int) $r['website_id']]);
        if ($camp) {
            $cid = (int) $camp[0]['id'];
        } else {
            $cid = (int) $this->db->query("INSERT INTO seo_content_campaigns (user_id, website_id, name, topic_source, status, total_items, generated_items, created_at, updated_at) VALUES (?, ?, 'Content calendar', 'manual', 'generating', 0, 0, NOW(), NOW())", [$uid, (int) $r['website_id']]);
        }
        if ($cid <= 0) return false;
        $itemId = (int) $this->db->query("INSERT INTO seo_content_items (campaign_id, topic, keyword, status, created_at, updated_at) VALUES (?, ?, ?, 'queued', NOW(), NOW())", [$cid, $r['title'], $r['keyword'] ?: null]);
        if ($itemId <= 0) return false;
        $this->db->query("UPDATE seo_content_campaigns SET total_items = total_items + 1, status = IF(status IN ('completed','draft'), 'generating', status), updated_at = NOW() WHERE id = ?", [$cid]);
        $this->db->query("UPDATE content_calendar SET status = 'queued', seo_item_id = ? WHERE id = ? AND status = 'planned'", [$itemId, (int) $r['id']]);
        return true;
    }

    /** العنوان اللي العميل وافق عليه + صورة الخطة على المقال قبل النشر */
    private function prepareArticle(array $r): bool
    {
        $aid = (int) $r['article_id'];
        $a = $this->db->query('SELECT id, user_id, title' . (self::imageCols() ? ', featured_image_path' : '') . ' FROM ai_articles WHERE id = ? LIMIT 1', [$aid])[0] ?? null;
        if (!$a) return false;
        $changed = false;
        if (trim((string) $a['title']) !== trim((string) $r['title'])) {
            $this->db->query('UPDATE ai_articles SET title = ? WHERE id = ?', [$r['title'], $aid]);
            $this->db->query('UPDATE seo_content_items SET title = ? WHERE id = ?', [$r['title'], (int) $r['seo_item_id']]);
            $changed = true;
        }
        if (self::imageCols() && empty($a['featured_image_path']) && !empty($r['image_url'])) {
            $rel = $this->localImage((string) $r['image_url'], (int) $a['user_id']);
            if ($rel) {
                $this->db->query('UPDATE ai_articles SET featured_image_path = ?, featured_image_alt = ?, featured_image_prompt = ?, wp_media_id = NULL WHERE id = ?', [$rel, mb_substr((string) $r['title'], 0, 125), $r['image_prompt'], $aid]);
                $changed = true;
            }
        }
        return $changed;
    }

    /** الصورة لازم تبقى ملف عندنا (عشان ووردبريس/المدونة): صورة رحلة من موقع العميل بننزّلها ونعيد رسمها */
    private function localImage(string $url, int $userId): ?string
    {
        if (str_starts_with($url, '/uploads/')) return is_file(ROOT_PATH . '/public_html' . $url) ? $url : null;
        if (!preg_match('~^https?://~i', $url) || (class_exists('SsrfGuard') && !SsrfGuard::isSafe($url))) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', CURLOPT_MAXFILESIZE => 12 * 1024 * 1024]);
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($bin) || $code !== 200 || strlen($bin) < 2000) return null;
        $norm = class_exists('ArticleImageService') ? ArticleImageService::make()->normalize($bin) : null;
        if (!$norm) return null;
        $dir = ROOT_PATH . '/public_html/uploads/articles/' . $userId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $name = 'trip-' . bin2hex(random_bytes(6)) . '.' . $norm['ext'];
        return @file_put_contents($dir . '/' . $name, $norm['bytes']) === false ? null : '/uploads/articles/' . $userId . '/' . $name;
    }
}
