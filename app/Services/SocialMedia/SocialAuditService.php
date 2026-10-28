<?php

/**
 * «حلّل صفحتي»: درجة صحة صفحة فيسبوك/إنستجرام من 100 بأسباب واضحة - من بيانات Meta الحقيقية للصفحة المربوطة بس.
 *  النشاط (كام بوست في الأسبوع) · آخر بوست · التفاعل نسبةً للمتابعين · تنوع المحتوى (فيديو/كاروسيل) ·
 *  طريق الحجز (موقع/واتساب/لينكات في البوستات) · سرعة الرد على أسئلة السعر/الحجز (من صندوق التعليقات) · لغة الجمهور.
 * أي مقياس مش متاح (مثلًا مفيش تعليقات كفاية) مابيتحسبش لا له ولا عليه - الدرجة من اللي اتقاس فعلًا.
 * analyze() نقية (بتتختبر من غير Meta ولا DB).
 */
require_once __DIR__ . '/MetaSocialAPI.php';

final class SocialAuditService
{
    /** @var callable|null fn(string $token): MetaSocialAPI (للاختبارات) */
    public static $apiFactory = null;

    private Database $db;
    private static bool $ready = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        if (!self::$ready) {
            $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS social_audits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                platform_connection_id INT NOT NULL,
                score INT NOT NULL,
                data_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_conn (platform_connection_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready = true;
        }
    }

    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    // ===================== التحليل (نقي) =====================

    /** لغة نص البوست تقريبًا: ar / de / it / es / fr / en / ru (أو null لو قصير) */
    public static function lang(string $text): ?string
    {
        $t = mb_strtolower(trim(preg_replace('~https?://\S+|#\S+|@\S+~u', ' ', $text)));
        if (mb_strlen($t) < 12) return null;
        $ar = preg_match_all('/\p{Arabic}/u', $t);
        $lat = preg_match_all('/[a-zà-ÿ]/u', $t);
        if (preg_match_all('/\p{Cyrillic}/u', $t) > 8) return 'ru';
        if ($ar > $lat) return 'ar';
        $words = array_count_values(preg_split('/[^a-zà-ÿ]+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
        $score = ['de' => ['und', 'die', 'der', 'mit', 'nicht', 'ist', 'für', 'ein', 'eine', 'sie'], 'it' => ['il', 'della', 'con', 'per', 'una', 'che', 'del', 'gli', 'sono'],
            'es' => ['el', 'los', 'las', 'con', 'para', 'una', 'que', 'del', 'por'], 'fr' => ['le', 'les', 'des', 'avec', 'pour', 'une', 'est', 'dans', 'vous'], 'en' => ['the', 'and', 'with', 'for', 'your', 'you', 'our', 'tour', 'from']];
        $best = 'en';
        $max = 0;
        foreach ($score as $l => $ws) {
            $n = 0;
            foreach ($ws as $w) $n += $words[$w] ?? 0;
            if ($n > $max) { $max = $n; $best = $l; }
        }
        return $best;
    }

    /**
     * @param array $in ['platform','followers'=>?int,'page'=>?array,'posts'=>[...],'comments'=>['hot'=>int,'answered_24h'=>int],'markets'=>['en',..],'site_host'=>?string,'now'=>int]
     * @return array ['score'=>int,'checks'=>[...],'facts'=>[...]]
     */
    public static function analyze(array $in): array
    {
        $now = (int) ($in['now'] ?? time());
        $posts = array_values(array_filter((array) ($in['posts'] ?? []), fn ($p) => !empty($p['created_time'])));
        usort($posts, fn ($a, $b) => strtotime((string) $b['created_time']) <=> strtotime((string) $a['created_time']));
        $isIg = ($in['platform'] ?? '') === 'instagram';
        $followers = isset($in['followers']) && $in['followers'] !== null ? (int) $in['followers'] : null;
        $checks = [];
        $add = function (string $key, int $max, ?int $got, array $value) use (&$checks) {
            $checks[] = ['key' => $key, 'max' => $max, 'points' => $got, 'measured' => $got !== null, 'value' => $value,
                'status' => $got === null ? 'unknown' : ($got >= $max * 0.75 ? 'good' : ($got >= $max * 0.4 ? 'warn' : 'bad'))];
        };

        // 1) النشاط: بوستات آخر 30 يوم
        $last30 = array_filter($posts, fn ($p) => strtotime((string) $p['created_time']) >= $now - 30 * 86400);
        $perWeek = round(count($last30) / (30 / 7), 1);
        $add('activity', 20, $perWeek >= 4 ? 20 : ($perWeek >= 3 ? 16 : ($perWeek >= 2 ? 12 : ($perWeek >= 1 ? 7 : ($perWeek > 0 ? 3 : 0)))), ['per_week' => $perWeek, 'count_30d' => count($last30)]);

        // 2) آخر بوست
        $lastDays = $posts ? (int) floor(($now - strtotime((string) $posts[0]['created_time'])) / 86400) : null;
        $add('recency', 10, $lastDays === null ? 0 : ($lastDays <= 3 ? 10 : ($lastDays <= 7 ? 7 : ($lastDays <= 14 ? 4 : 0))), ['days' => $lastDays]);

        // 3) التفاعل لكل بوست نسبةً للمتابعين (آخر 20 بوست)
        $recent = array_slice($posts, 0, 20);
        if ($recent && $followers && $followers >= 50) {
            $avg = array_sum(array_map(fn ($p) => (int) $p['likes'] + (int) $p['comments'] + (int) $p['shares'], $recent)) / count($recent);
            $rate = round($avg / $followers * 100, 2);
            // مرجع تقريبي لقطاع السياحة: فيسبوك ~0.5–1% كويس، إنستجرام ~1.5–3%
            [$hi, $mid, $lo] = $isIg ? [3.0, 1.5, 0.7] : [1.0, 0.5, 0.2];
            $add('engagement', 15, $rate >= $hi ? 15 : ($rate >= $mid ? 11 : ($rate >= $lo ? 6 : 2)), ['rate' => $rate, 'avg' => round($avg, 1), 'followers' => $followers, 'good_from' => $mid]);
        } else {
            $add('engagement', 15, null, ['followers' => $followers]);
        }

        // 4) تنوع المحتوى: الفيديو/الريلز بيوصل أكتر
        if (count($recent) >= 5) {
            $video = count(array_filter($recent, fn ($p) => ($p['type'] ?? '') === 'video'));
            $carousel = count(array_filter($recent, fn ($p) => ($p['type'] ?? '') === 'carousel'));
            $share = $video / count($recent);
            $add('video', 10, $share >= 0.25 ? 10 : ($video > 0 ? 6 : 0), ['video' => $video, 'carousel' => $carousel, 'of' => count($recent)]);
        } else {
            $add('video', 10, null, []);
        }

        // 5) طريق الحجز (فيسبوك): موقع + واتساب/تليفون + لينكات في البوستات
        if (!$isIg && !empty($in['page'])) {
            $pg = (array) $in['page'];
            $site = trim((string) ($pg['website'] ?? ''));
            $siteOk = $site !== '' && (!($in['site_host'] ?? '') || str_contains(strtolower($site), strtolower((string) $in['site_host'])));
            $contact = !empty($pg['whatsapp_number']) || !empty($pg['phone']);
            $linkShare = $recent ? count(array_filter($recent, fn ($p) => !empty($p['has_link']))) / count($recent) : 0;
            $pts = ($siteOk ? 5 : 0) + ($contact ? 5 : 0) + ($linkShare >= 0.3 ? 10 : ($linkShare > 0 ? 5 : 0));
            $add('booking_path', 20, $pts, ['website' => $site, 'website_matches' => $siteOk, 'whatsapp' => !empty($pg['whatsapp_number']), 'phone' => !empty($pg['phone']), 'link_share' => round($linkShare * 100)]);
        } else {
            $add('booking_path', 20, null, []);
        }

        // 6) سرعة الرد على أسئلة السعر/الحجز (صندوق التعليقات)
        $hot = (int) ($in['comments']['hot'] ?? 0);
        if ($hot >= 3) {
            $r = ((int) ($in['comments']['answered_24h'] ?? 0)) / $hot;
            $add('replies', 15, $r >= 0.9 ? 15 : ($r >= 0.6 ? 10 : ($r >= 0.3 ? 5 : 0)), ['hot' => $hot, 'answered_pct' => (int) round($r * 100)]);
        } else {
            $add('replies', 15, null, ['hot' => $hot]);
        }

        // 7) لغة الجمهور: الأسواق اللي العميل بيستهدفها مقابل لغة بوستاته
        $markets = array_values(array_unique(array_filter((array) ($in['markets'] ?? []))));
        $langs = array_filter(array_map(fn ($p) => self::lang((string) $p['message']), $recent));
        if ($markets && count($langs) >= 4) {
            $counts = array_count_values($langs);
            $inMarket = array_sum(array_intersect_key($counts, array_flip($markets)));
            $share = $inMarket / count($langs);
            arsort($counts);
            $add('language', 10, $share >= 0.6 ? 10 : ($share >= 0.3 ? 6 : 1), ['markets' => $markets, 'in_market_pct' => (int) round($share * 100), 'main' => array_key_first($counts)]);
        } else {
            $add('language', 10, null, ['markets' => $markets]);
        }

        $max = array_sum(array_map(fn ($c) => $c['measured'] ? $c['max'] : 0, $checks));
        $got = array_sum(array_map(fn ($c) => (int) $c['points'], array_filter($checks, fn ($c) => $c['measured'])));
        $best = null;
        foreach ($recent as $p) {
            $e = (int) $p['likes'] + (int) $p['comments'] + (int) $p['shares'];
            if (!$best || $e > $best['engagement']) $best = ['engagement' => $e, 'type' => $p['type'] ?? null, 'permalink' => $p['permalink'] ?? null, 'excerpt' => mb_substr((string) $p['message'], 0, 140), 'date' => $p['created_time']];
        }
        return ['score' => $max > 0 ? (int) round($got / $max * 100) : 0, 'measured_max' => $max, 'checks' => $checks,
            'facts' => ['posts_analyzed' => count($recent), 'followers' => $followers, 'best_post' => $best]];
    }

    // ===================== النصوص (بلغة اللوحة وقت العرض) =====================

    public static function describe(array $result): array
    {
        $out = [];
        foreach ($result['checks'] as $c) {
            $v = $c['value'];
            [$title, $text, $tip, $action] = match ($c['key']) {
                'activity' => [self::L('النشاط', 'Activity'),
                    sprintf(self::L('%s بوست في الأسبوع (آخر 30 يوم)', '%s posts a week (last 30 days)'), $v['per_week']),
                    self::L('الصفحات السياحية اللي بتبيع بتنشر 3–5 مرات في الأسبوع. خطة البوستات بتنظّملك ده لوحدها.', 'Tour pages that sell post 3–5 times a week. The post plan schedules this for you.'), 'calendar'],
                'recency' => [self::L('آخر بوست', 'Last post'),
                    $v['days'] === null ? self::L('مفيش بوستات', 'No posts yet') : sprintf(self::L('من %d يوم', '%d days ago'), $v['days']),
                    self::L('الصفحة اللي بقالها أسبوعين ساكتة بتبان للعميل إنها مقفولة.', 'A page silent for two weeks looks closed to travellers.'), 'calendar'],
                'engagement' => [self::L('التفاعل', 'Engagement'),
                    $c['measured'] ? sprintf(self::L('%s%% من المتابعين بيتفاعلوا مع البوست (متوسط %s تفاعل)', '%s%% of followers engage per post (avg %s)'), $v['rate'], $v['avg']) : self::L('مش كفاية بيانات لسه', 'Not enough data yet'),
                    self::L('صور رحلات حقيقية وأسئلة للجمهور وريلز بيرفعوا التفاعل أكتر من الصور الجاهزة.', 'Real trip photos, questions and reels lift engagement more than stock images.'), 'calendar'],
                'video' => [self::L('الفيديو والريلز', 'Video & reels'),
                    $c['measured'] ? sprintf(self::L('%d فيديو من آخر %d بوست', '%d videos in the last %d posts'), $v['video'], $v['of']) : self::L('مش كفاية بوستات', 'Not enough posts'),
                    self::L('الريلز بتوصل لناس جديدة أكتر بكتير من الصور. خلي ربع بوستاتك على الأقل فيديو.', 'Reels reach far more new people than photos. Aim for at least a quarter video.'), 'calendar'],
                'booking_path' => [self::L('طريق الحجز', 'Path to booking'),
                    $c['measured'] ? implode(' · ', array_filter([
                        $v['website'] === '' ? self::L('مفيش لينك موقع في الصفحة', 'No website on the page') : ($v['website_matches'] ? self::L('لينك الموقع ✓', 'Website link ✓') : self::L('لينك الموقع مش لموقعك الحالي', 'Website link isn’t your current site')),
                        $v['whatsapp'] ? self::L('واتساب ✓', 'WhatsApp ✓') : ($v['phone'] ? self::L('تليفون ✓ (من غير واتساب)', 'Phone ✓ (no WhatsApp)') : self::L('مفيش واتساب ولا تليفون', 'No WhatsApp or phone')),
                        sprintf(self::L('%d%% من البوستات فيها لينك حجز', '%d%% of posts have a booking link'), $v['link_share']),
                    ])) : self::L('بيتقاس لصفحات فيسبوك', 'Measured for Facebook pages'),
                    self::L('كل بوست رحلة لازم يبقى فيه لينك حجز (بنحوّله لرابط بيتتبع الحجوزات)، والصفحة لازم يبقى فيها زرار واتساب.', 'Every trip post needs a booking link (we track bookings from it), and the page needs a WhatsApp button.'), 'calendar'],
                'replies' => [self::L('الرد على «بكام؟»', 'Answering “how much?”'),
                    $c['measured'] ? sprintf(self::L('اترد على %d%% من أسئلة السعر والحجز خلال 24 ساعة', '%d%% of price/booking questions answered within 24h'), $v['answered_pct']) : self::L('مفيش أسئلة كفاية في صندوق التعليقات لسه', 'Not enough questions in the comment inbox yet'),
                    self::L('السائح اللي بيسأل عن السعر ومابيترّدش عليه في نفس اليوم بيحجز من حد تاني.', 'A traveller who asks for the price and gets no same-day answer books elsewhere.'), 'inbox'],
                'language' => [self::L('لغة جمهورك', 'Your audience’s language'),
                    $c['measured'] ? sprintf(self::L('%d%% من بوستاتك بلغة السوق اللي بتستهدفه (%s)', '%d%% of your posts are in your target market language (%s)'), $v['in_market_pct'], strtoupper(implode(', ', $v['markets']))) : self::L('مش كفاية بيانات', 'Not enough data'),
                    self::L('لو بتبيع لأجانب، البوست لازم يبقى بلغتهم. خطة البوستات بتكتب بلغة كل سوق.', 'Selling to foreigners? Post in their language — the post plan writes in each market’s language.'), 'calendar'],
                default => [$c['key'], '', '', null],
            };
            $out[] = $c + ['title' => $title, 'text' => $text, 'tip' => $c['status'] === 'good' ? '' : $tip, 'action' => $action];
        }
        return $out;
    }

    // ===================== التشغيل =====================

    /** صفحات فيسبوك/إنستجرام المربوطة للعميل (أو فريقه) */
    public function connections(int $owner): array
    {
        return $this->db->query(
            "SELECT c.id, c.platform, c.external_location_id, c.external_location_name, p.username, p.display_name, p.avatar_url, p.followers
               FROM platform_connections c LEFT JOIN social_account_profiles p ON p.platform_connection_id = c.id
              WHERE c.platform IN ('facebook','instagram') AND c.status = 'connected'
                AND (c.user_id = ? OR c.website_id IN (SELECT id FROM websites WHERE user_id = ?))
              ORDER BY c.platform = 'facebook' DESC, c.id ASC LIMIT 30",
            [$owner, $owner]
        );
    }

    /** لينك صفحة فيسبوك/إنستجرام ← الصفحة المربوطة المطابقة (باليوزرنيم أو الـID) */
    public function matchUrl(int $owner, string $url): ?array
    {
        $u = strtolower(trim($url));
        if (!preg_match('~(?:facebook\.com|fb\.com|instagram\.com)/(?:profile\.php\?id=)?([a-z0-9_.\-]+)~i', $u, $m)) return null;
        $handle = trim($m[1], '/.');
        $platform = str_contains($u, 'instagram.com') ? 'instagram' : 'facebook';
        foreach ($this->connections($owner) as $c) {
            if ($c['platform'] !== $platform) continue;
            if ($handle === strtolower((string) $c['username']) || $handle === strtolower((string) $c['external_location_id'])) return $c;
            $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($c['display_name'] ?: $c['external_location_name'])));
            if ($name !== '' && $name === preg_replace('/[^a-z0-9]/', '', $handle)) return $c;
        }
        return null;
    }

    /** التحليل الأخير لكل صفحة (بلغة اللوحة) */
    public function latest(int $owner): array
    {
        $out = [];
        foreach ($this->connections($owner) as $c) {
            $rows = $this->db->query('SELECT score, data_json, created_at FROM social_audits WHERE platform_connection_id = ? ORDER BY id DESC LIMIT 2', [(int) $c['id']]);
            $res = $rows ? json_decode((string) $rows[0]['data_json'], true) : null;
            $out[] = ['connection' => self::publicConn($c), 'audit' => $res ? ['score' => (int) $rows[0]['score'], 'previous' => isset($rows[1]) ? (int) $rows[1]['score'] : null, 'at' => $rows[0]['created_at'],
                'checks' => self::describe($res), 'facts' => $res['facts'] ?? []] : null];
        }
        return $out;
    }

    private static function publicConn(array $c): array
    {
        return ['id' => (int) $c['id'], 'platform' => $c['platform'], 'name' => (string) ($c['display_name'] ?: $c['external_location_name'] ?: $c['username']), 'username' => $c['username'], 'avatar' => $c['avatar_url'], 'followers' => $c['followers'] !== null ? (int) $c['followers'] : null];
    }

    /** تحليل جديد لصفحة مربوطة (بيانات حقيقية من Meta) */
    public function run(int $owner, int $connectionId): array
    {
        $conn = null;
        foreach ($this->connections($owner) as $c) if ((int) $c['id'] === $connectionId) $conn = $c;
        if (!$conn) return ['success' => false, 'error' => self::L('الصفحة دي مش مربوطة بحسابك', 'This page isn’t connected to your account')];
        $row = $this->db->query('SELECT access_token FROM platform_connections WHERE id = ? LIMIT 1', [$connectionId])[0] ?? null;
        try {
            $token = (new Encryption())->decrypt((string) ($row['access_token'] ?? ''));
            $api = self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
            $id = (string) $conn['external_location_id'];
            $isIg = $conn['platform'] === 'instagram';
            $postsR = $isIg ? $api->getInstagramMediaWithStats($id, $token, 30) : $api->getFacebookPostsWithStats($id, $token, 30);
            if (empty($postsR['success'])) {
                return ['success' => false, 'error' => self::L('Meta رفضت قراءة بوستات الصفحة — اربط الصفحة تاني من «السوشيال» ← الحسابات', 'Meta refused to read the page posts — reconnect the page in Social → Accounts')];
            }
            $page = null;
            if (!$isIg) {
                $pr = $api->getPageDetails($id, $token);
                $page = !empty($pr['success']) ? $pr['page'] : null;
            }
            $stats = $api->getAccountStats((string) $conn['platform'], $id, $token);
        } catch (Throwable $e) {
            Logger::warning('Social audit failed', ['connection' => $connectionId, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => self::L('تعذر التحليل دلوقتي — جرّب كمان شوية', 'Could not analyse right now — try again shortly')];
        }
        $cm = $this->db->query("SELECT COUNT(*) hot, SUM(COALESCE(replied_at, private_replied_at) IS NOT NULL AND COALESCE(replied_at, private_replied_at) <= DATE_ADD(commented_at, INTERVAL 24 HOUR)) ans
              FROM social_comments WHERE platform_connection_id = ? AND intent IN ('price','booking','question') AND commented_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)", [$connectionId])[0] ?? [];
        [$markets, $host] = $this->markets($owner);
        $res = self::analyze([
            'platform' => $conn['platform'], 'followers' => $stats['followers'] ?? ($page['followers_count'] ?? $page['fan_count'] ?? null),
            'page' => $page, 'posts' => $postsR['posts'], 'comments' => ['hot' => (int) ($cm['hot'] ?? 0), 'answered_24h' => (int) ($cm['ans'] ?? 0)],
            'markets' => $markets, 'site_host' => $host, 'now' => time(),
        ]);
        $this->db->query('INSERT INTO social_audits (user_id, platform_connection_id, score, data_json) VALUES (?, ?, ?, ?)', [$owner, $connectionId, $res['score'], json_encode($res, JSON_UNESCAPED_UNICODE)]);
        return ['success' => true];
    }

    /** لغات الأسواق اللي العميل بيستهدفها + دومين موقعه */
    private function markets(int $owner): array
    {
        $site = $this->db->query('SELECT id, `' . WebsiteSchema::url() . '` AS url FROM websites WHERE user_id = ?' . WebsiteSchema::alive() . ' ORDER BY id ASC LIMIT 1', [$owner])[0] ?? null;
        if (!$site) return [[], null];
        $langs = [];
        if (class_exists('ContentLanguageService')) $langs[] = substr((string) ContentLanguageService::forWebsite((int) $site['id'], $owner), 0, 2);
        if (WebsiteSchema::has('target_languages')) {
            $tl = $this->db->query('SELECT target_languages FROM websites WHERE id = ? LIMIT 1', [(int) $site['id']])[0]['target_languages'] ?? '';
            foreach ((array) (json_decode((string) $tl, true) ?: preg_split('/[,\s]+/', (string) $tl)) as $l) if (preg_match('/^[a-z]{2}/', strtolower((string) $l), $m)) $langs[] = $m[0];
        }
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url((string) $site['url'], PHP_URL_HOST)));
        return [array_values(array_unique(array_filter($langs))), $host ?: null];
    }
}
