<?php

/**
 * منافسين السوشيال تلقائي: من منافسين العميل الحقيقيين (مركز المنافسين + منافسين الموقع) بنفتح موقع كل منافس
 * ونطلّع صفحاته على فيسبوك وإنستجرام من اللينكات اللي هو حاططها بنفسه (الفوتر/الهيدر).
 *  - إنستجرام: بيتضاف لمراقبة المنافسين (أرقام حقيقية من Meta Business Discovery) لو العميل رابط إنستجرام بيزنس
 *  - فيسبوك: بنعرض لينك الصفحة بس - Meta مابتسمحش بقراية أرقام صفحات تانية من غير صلاحية خاصة، فمابنخمّنش أرقام
 * extractSocial() نقية (بتتختبر).
 */
class SocialCompetitorDiscovery
{
    private Database $db;
    private static bool $ready = false;
    /** مسارات فيسبوك/إنستجرام اللي مش صفحة (مشاركة/سياسات/بلجنز) */
    private const SKIP = ['sharer', 'share', 'sharer.php', 'dialog', 'plugins', 'tr', 'login', 'policies', 'privacy', 'help', 'about', 'pages', 'groups', 'events', 'watch', 'profile.php', 'p', 'reel', 'reels', 'explore', 'accounts', 'stories', 'tv', 'hashtag', 'photo', 'photos', 'permalink.php', 'story.php', 'home.php', 'business', 'ads', 'legal'];

    /** @var callable|null fn(string $url, string $lang, int $websiteId, array $countries): array (للاختبارات) */
    public static $competitorFinder = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        if (!self::$ready) {
            $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS social_competitor_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                domain VARCHAR(190) NOT NULL,
                name VARCHAR(255) NULL,
                facebook_url VARCHAR(300) NULL,
                instagram_username VARCHAR(60) NULL,
                tracked TINYINT(1) NOT NULL DEFAULT 0,
                track_error VARCHAR(255) NULL,
                checked_at DATETIME NOT NULL,
                UNIQUE KEY uq_user_domain (user_id, domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready = true;
        }
    }

    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    /** لينكات فيسبوك/إنستجرام من HTML الموقع - الأكتر تكرارًا (اللوجو في الهيدر والفوتر) */
    public static function extractSocial(string $html): array
    {
        $fb = $ig = [];
        if (preg_match_all('~https?://(?:www\.|m\.|web\.|ar-ar\.|en-gb\.)?(facebook\.com|fb\.com|instagram\.com)/([A-Za-z0-9_.\-]{2,80})(?:/([A-Za-z0-9_.\-]+))?~i', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $host = strtolower($m[1]);
                $h = strtolower(trim($m[2], '.'));
                if (in_array($h, self::SKIP, true) || preg_match('/\.(php|js|css|png|jpg|svg)$/', $h)) continue;
                if ($host === 'instagram.com') {
                    if (preg_match('/^[a-z0-9._]{1,30}$/', $h)) $ig[$h] = ($ig[$h] ?? 0) + 1;
                } else {
                    // facebook.com/pg/Name أو facebook.com/Name
                    if ($h === 'pg' && !empty($m[3])) $h = strtolower($m[3]);
                    if ($h === 'pg') continue;
                    $fb[$h] = ($fb[$h] ?? 0) + 1;
                }
            }
        }
        arsort($fb);
        arsort($ig);
        return ['facebook' => $fb ? 'https://www.facebook.com/' . array_key_first($fb) : null, 'instagram' => $ig ? array_key_first($ig) : null];
    }

    /** دومينات منافسين العميل (مش ماركت بليس) */
    private function competitorDomains(int $owner): array
    {
        $doms = [];
        foreach ($this->db->query("SELECT competitor_domain, competitor_name FROM competitors WHERE user_id = ? AND COALESCE(is_active, 1) = 1 AND competitor_domain IS NOT NULL ORDER BY id DESC LIMIT 15", [$owner]) as $r) {
            $doms[self::host((string) $r['competitor_domain'])] = (string) ($r['competitor_name'] ?? '');
        }
        $cols = array_values(array_filter(['competitor_1_url', 'competitor_2_url', 'competitor_3_url'], fn ($c) => WebsiteSchema::has($c)));
        if ($cols) {
            foreach ($this->db->query('SELECT ' . implode(', ', $cols) . ' FROM websites WHERE user_id = ?' . WebsiteSchema::alive(), [$owner]) as $w) {
                foreach ($cols as $c) if (!empty($w[$c])) $doms[self::host((string) $w[$c])] ??= '';
            }
        }
        foreach ($this->db->query('SELECT domain, name FROM social_competitor_pages WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$owner]) ?: [] as $r) {
            $doms[(string) $r['domain']] ??= (string) ($r['name'] ?? '');
        }
        unset($doms['']);
        $market = '/(tripadvisor|viator|getyourguide|booking|expedia|airbnb|klook|facebook|instagram|google|youtube|tiktok)\./i';
        return array_slice(array_filter($doms, fn ($d) => !preg_match($market, $d), ARRAY_FILTER_USE_KEY), 0, 10, true);
    }

    private static function host(string $u): string
    {
        $u = trim($u);
        if ($u === '') return '';
        if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
        return preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
    }

    /** نفتح مواقع المنافسين (بالتوازي) ونحفظ صفحاتهم. مرة كل 7 أيام لكل منافس إلا لو force */
    public function discover(int $owner, bool $force = false): array
    {
        $domains = $this->competitorDomains($owner);
        if (!$domains) return ['success' => true, 'found' => 0, 'none' => true];
        $r = $this->fetchPages($owner, $domains, $force);
        $this->trackInstagram($owner);
        return $r;
    }

    /** يفتح مواقع المنافسين (domain => اسم) ويحفظ صفحاتهم على فيسبوك وإنستجرام */
    protected function fetchPages(int $owner, array $domains, bool $force): array
    {
        $known = [];
        foreach ($this->db->query('SELECT domain, checked_at FROM social_competitor_pages WHERE user_id = ?', [$owner]) as $r) $known[$r['domain']] = $r['checked_at'];
        $todo = array_filter(array_keys($domains), fn ($d) => $force || !isset($known[$d]) || strtotime((string) $known[$d]) < time() - 7 * 86400);
        if (!$todo) return ['success' => true, 'found' => 0];
        $mh = curl_multi_init();
        $hs = [];
        foreach ($todo as $d) {
            $url = 'https://' . $d . '/';
            if (class_exists('SsrfGuard') && !SsrfGuard::isSafe($url)) continue;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '', CURLOPT_RANGE => '0-600000',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36']);
            curl_multi_add_handle($mh, $ch);
            $hs[$d] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        $found = 0;
        foreach ($hs as $d => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $s = ($code >= 200 && $code < 400) ? self::extractSocial($body) : ['facebook' => null, 'instagram' => null];
            $title = preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m) ? mb_substr(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')), 0, 120) : '';
            $this->db->query('INSERT INTO social_competitor_pages (user_id, domain, name, facebook_url, instagram_username, checked_at) VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), facebook_url = COALESCE(VALUES(facebook_url), facebook_url), instagram_username = COALESCE(VALUES(instagram_username), instagram_username), checked_at = NOW()',
                [$owner, $d, $domains[$d] ?: $title ?: null, $s['facebook'], $s['instagram']]);
            if ($s['facebook'] || $s['instagram']) $found++;
        }
        curl_multi_close($mh);
        return ['success' => true, 'found' => $found];
    }

    /**
     * «اكتشف منافسيني الحقيقيين». الترتيب من الأرخص للأغلى، ومفيش تخمين في أي مصدر:
     *   1) نتيجة محفوظة لنفس العميل (30 يوم) ← 2) نتيجة محفوظة لأي عميل بنفس الموقع والسوق ← 3) مرشحي المنافسين الموجودين في حسابه (Competitor Intelligence)
     *   ← 4) بحث جوجل المدفوع (طلبين بس، بسقف شهري، والتكلفة الفعلية بتتسجّل) ← 5) دليل السوق: شركات ظهرت كمنافسين لأكتر من عميل (مكتوب إنها مش مقاسة لموقعه).
     * كل نتيجة حقيقية بتتحفظ في دليل السوق عشان العميل الجاي في نفس السوق يستفيد من غير تكلفة.
     * @return array{success:bool, found:int, none?:bool, source:string, cached:bool, cost:float, tracked:int, limit_note:?string, domains:array, reason?:string}
     */
    public function findReal(int $owner, int $websiteId, string $siteUrl, string $language, array $countries = [], bool $force = false): array
    {
        $host = self::host($siteUrl);
        $market = SocialIntelService::marketKey($countries, $language);
        $intel = new SocialIntelService($this->db);
        $ss = new SystemSettingsService();
        $ownKey = 'social_comp_find_' . $owner;
        $globalKey = 'social_comp_global_' . substr(md5($host . '|' . $market), 0, 24);
        $ttl = 30 * 86400;
        $valid = static fn ($c) => is_array($c) && (int) ($c['at'] ?? 0) > time() - $ttl && ($c['site'] ?? '') === $host;
        $found = [];
        $source = '';
        $cost = 0.0;
        $cached = false;
        $reason = null;
        $searchedBefore = false;

        $c = json_decode((string) $ss->get($ownKey, ''), true);
        if (!$force && $valid($c)) {
            $cached = true;
            $found = (array) ($c['competitors'] ?? []);
            $source = $found ? 'cache' : '';
            $searchedBefore = true;
        }
        if (!$found && !$force) {
            $g = json_decode((string) $ss->get($globalKey, ''), true);
            if ($valid($g) && !empty($g['competitors'])) {
                $found = (array) $g['competitors'];
                $source = 'global_cache';
                $cached = true;
            }
        }
        if (!$found && !$searchedBefore) {
            $found = $this->ciCandidates($owner, $host);
            if ($found) {
                $source = 'ci_candidates';
                $ss->set($ownKey, json_encode(['at' => time(), 'site' => $host, 'source' => $source, 'competitors' => $found], JSON_UNESCAPED_UNICODE));
            }
        }
        if (!$found && !$searchedBefore) {
            if (!$intel->budgetAllows()) {
                $reason = SocialIntelService::budget() == 0.0 ? 'search_disabled' : 'monthly_budget_reached';
            } else {
                $r = self::$competitorFinder ? (self::$competitorFinder)($siteUrl, $language, $websiteId, $countries) : (new SmartOnboardingService())->competitorsLean($siteUrl, $language, $countries);
                $cost = (float) ($r['cost'] ?? 0);
                $reason = isset($r['reason']) ? (string) $r['reason'] : null;
                foreach ((array) ($r['competitors'] ?? []) as $x) {
                    if (empty($x['measured']) || empty($x['domain'])) continue;
                    $found[] = ['domain' => self::host((string) $x['domain']), 'title' => (string) ($x['title'] ?? ''), 'shared_keywords' => $x['shared_keywords'] ?? null, 'traffic' => $x['traffic'] ?? null];
                    if (count($found) >= 6) break;
                }
                $source = $found ? 'google_data' : '';
                // النتيجة النهائية بتتحفظ (حتى الفاضية لموقع مش ظاهر في جوجل) عشان مانكرّرش طلب مدفوع.
                // أي فشل مؤقت (ميزانية، حد يومي، خطأ مزوّد) مايتحفظش: العميل يقدر يجرّب تاني بعد ما يتصلّح.
                if ($found || $reason === null || $reason === 'site_not_ranked') {
                    $payload = json_encode(['at' => time(), 'site' => $host, 'source' => $source ?: 'none', 'competitors' => $found], JSON_UNESCAPED_UNICODE);
                    $ss->set($ownKey, $payload);
                    if ($found) $ss->set($globalKey, $payload);
                }
            }
        }
        $measured = $found !== [];
        if (!$found) {
            $found = array_map(static fn ($d) => ['domain' => $d['domain'], 'title' => (string) ($d['name'] ?? ''), 'shared_keywords' => null, 'traffic' => null], $intel->directory($market, [$host]));
            if ($found) $source = 'directory';
        }
        $intel->logRun($owner, $source ?: ($reason ?: 'none'), count($found), $cost);
        if (!$found) {
            return ['success' => true, 'found' => 0, 'none' => true, 'source' => $source ?: 'none', 'cached' => $cached, 'cost' => $cost, 'tracked' => 0, 'limit_note' => null, 'domains' => [], 'reason' => $reason];
        }
        $domains = [];
        foreach ($found as $x) $domains[$x['domain']] = ($x['title'] !== '' && strtolower($x['title']) !== $x['domain']) ? mb_substr($x['title'], 0, 120) : '';
        $this->fetchPages($owner, $domains, $force);
        if (in_array($source, ['google_data', 'ci_candidates', 'global_cache'], true)) {
            $rows = [];
            foreach ($this->db->query('SELECT domain, name, facebook_url, instagram_username FROM social_competitor_pages WHERE user_id = ? AND domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')', array_merge([$owner], array_keys($domains))) ?: [] as $p) {
                $fb = null;
                if (!empty($p['facebook_url']) && preg_match('~facebook\.com/([A-Za-z0-9.\-]+)~i', (string) $p['facebook_url'], $mm)) $fb = strtolower($mm[1]);
                $rows[] = ['domain' => $p['domain'], 'name' => $p['name'], 'facebook' => $fb, 'instagram' => $p['instagram_username']];
            }
            $intel->recordCompetitors($market, $rows);
        }
        [$tracked, $limitNote] = $this->trackWithinPlan($owner);
        return ['success' => true, 'found' => count($found), 'source' => $source, 'cached' => $cached, 'cost' => $cost, 'tracked' => $tracked, 'limit_note' => $limitNote, 'domains' => array_keys($domains), 'reason' => $reason];
    }

    /** مرشحو المنافسين اللي اتجمعوا قبل كده في حساب العميل (Google Places أو اقتراحه اليدوي): مجانًا، ومواقعهم حقيقية */
    private function ciCandidates(int $owner, string $ownHost): array
    {
        $out = [];
        try {
            $rows = $this->db->query("SELECT competitor_name, website FROM ci_discovery_candidates WHERE user_id = ? AND status IN ('pending','added') AND website IS NOT NULL AND website <> '' ORDER BY id DESC LIMIT 12", [$owner]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $h = self::host((string) $r['website']);
            if ($h === '' || $h === $ownHost || SmartOnboardingService::notACompetitor($h) || isset($out[$h])) continue;
            $out[$h] = ['domain' => $h, 'title' => (string) $r['competitor_name'], 'shared_keywords' => null, 'traffic' => null];
            if (count($out) >= 6) break;
        }
        return array_values($out);
    }

    /** متابعة حسابات إنستجرام المنافسين بالأرقام، لحد حدود باقة العميل بس (من غير ما نتخطاها) */
    private function trackWithinPlan(int $owner): array
    {
        if (!class_exists('SocialCompetitors')) return [0, null];
        $svc = new SocialCompetitors($this->db);
        if (!$svc->ready()) return [0, null];
        $n = 0;
        $note = null;
        foreach ($this->db->query("SELECT id, instagram_username FROM social_competitor_pages WHERE user_id = ? AND instagram_username IS NOT NULL AND tracked = 0 ORDER BY id DESC LIMIT 6", [$owner]) ?: [] as $r) {
            try {
                SocialPackages::assertCompetitorSlot($owner);
                $svc->add($owner, (string) $r['instagram_username']);
                $this->db->query('UPDATE social_competitor_pages SET tracked = 1, track_error = NULL WHERE id = ?', [(int) $r['id']]);
                $n++;
            } catch (SocialPackageLimit $e) {
                $note = $e->getMessage();
                break;
            } catch (Throwable $e) {
                $this->db->query('UPDATE social_competitor_pages SET track_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 250), (int) $r['id']]);
                if (str_contains($e->getMessage(), 'اربط')) break;
            }
        }
        return [$n, $note];
    }

    /** حسابات إنستجرام المنافسين بتدخل المراقبة بالأرقام (لو العميل رابط إنستجرام بيزنس) */
    private function trackInstagram(int $owner): void
    {
        if (!class_exists('SocialCompetitors')) return;
        $svc = new SocialCompetitors($this->db);
        if (!$svc->ready()) return;
        foreach ($this->db->query("SELECT id, instagram_username FROM social_competitor_pages WHERE user_id = ? AND instagram_username IS NOT NULL AND tracked = 0 AND (track_error IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) LIMIT 5", [$owner]) as $r) {
            try {
                $svc->add($owner, (string) $r['instagram_username']);
                $this->db->query('UPDATE social_competitor_pages SET tracked = 1, track_error = NULL WHERE id = ?', [(int) $r['id']]);
            } catch (Throwable $e) {
                $this->db->query('UPDATE social_competitor_pages SET track_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 250), (int) $r['id']]);
                // مفيش إنستجرام مربوط أو وصلنا الحد: مانكملش على الباقي
                if (str_contains($e->getMessage(), 'اربط') || str_contains($e->getMessage(), 'الحد')) break;
            }
        }
    }

    /** المنافسين بصفحاتهم + أرقام إنستجرام الحقيقية لو بتتراقب + مقارنة بالعميل */
    /**
     * لينك مكتبة إعلانات Meta الرسمية (نقية): الإعلانات الشغالة دلوقتي للمنافس في كل الدول.
     * بنبحث باسم الصفحة من لينكها (أدق)، وإلا اسم الشركة، وإلا الدومين. مفيش API ولا تكلفة - العميل بيشوفها بنفسه.
     */
    public static function adLibraryUrl(?string $name, ?string $facebookUrl, string $domain): string
    {
        $q = '';
        if ($facebookUrl && preg_match('~facebook\.com/(?:pg/)?([^/?#]+)~i', $facebookUrl, $m) && !in_array(strtolower($m[1]), ['profile.php', 'people', 'pages'], true)) {
            $q = trim(preg_replace('/-\d{6,}$/', '', urldecode($m[1])));
            $q = str_replace(['-', '.', '_'], ' ', $q);
        }
        if ($q === '') $q = trim((string) $name) ?: preg_replace('/^www\./i', '', $domain);
        return 'https://www.facebook.com/ads/library/?' . http_build_query(['active_status' => 'active', 'ad_type' => 'all', 'country' => 'ALL', 'q' => $q, 'search_type' => 'keyword_unordered', 'media_type' => 'all']);
    }

    public function overview(int $owner): array
    {
        $pages = $this->db->query('SELECT domain, name, facebook_url, instagram_username, tracked, track_error FROM social_competitor_pages WHERE user_id = ? ORDER BY (instagram_username IS NOT NULL) DESC, (facebook_url IS NOT NULL) DESC, domain', [$owner]);
        $ig = ['competitors' => [], 'me' => null, 'insights' => []];
        if (class_exists('SocialCompetitors')) {
            try { $ig = (new SocialCompetitors($this->db))->list($owner); } catch (Throwable $e) { error_log('[SocialCompetitorDiscovery] competitors list failed: ' . $e->getMessage()); }
        }
        $byUser = [];
        foreach ((array) ($ig['competitors'] ?? []) as $c) $byUser[strtolower((string) $c['username'])] = $c;
        $out = [];
        foreach ($pages as $p) {
            $m = $p['instagram_username'] ? ($byUser[strtolower((string) $p['instagram_username'])] ?? null) : null;
            $out[] = ['domain' => $p['domain'], 'name' => $p['name'], 'facebook' => $p['facebook_url'], 'instagram' => $p['instagram_username'],
                'ads_url' => self::adLibraryUrl($p['name'], $p['facebook_url'], (string) $p['domain']),
                'metrics' => $m ? ['followers' => $m['followers'] !== null ? (int) $m['followers'] : null, 'posts_per_week' => $m['posts_per_week'] !== null ? (float) $m['posts_per_week'] : null,
                    'avg_engagement' => $m['avg_engagement'] !== null ? (float) $m['avg_engagement'] : null, 'mix' => $m['mix'] ?? [], 'top' => array_slice((array) ($m['top'] ?? []), 0, 2), 'change_30d' => $m['followers_change_30d'] ?? null] : null,
                'note' => !$p['instagram_username'] && !$p['facebook_url'] ? self::L('مالقيناش صفحات سوشيال على موقعه', 'No social links found on their site')
                    : ($p['instagram_username'] && !$m ? (($p['track_error'] && !str_contains((string) $p['track_error'], 'اربط'))
                        ? self::L('ماقدرناش نقرا أرقامه — لازم يكون حساب إنستجرام بيزنس أو كريتور عام', 'Couldn’t read their numbers — it must be a public Instagram Business/Creator account')
                        : self::L('اربط إنستجرام بيزنس عشان نجيب أرقامه الحقيقية', 'Connect Instagram Business to get their real numbers')) : null)];
        }
        $insights = [];
        foreach ((array) ($ig['insights'] ?? []) as $x) $insights[] = is_array($x) ? (self::L('ar', 'en') === 'ar' ? ($x[0] ?? '') : ($x[1] ?? '')) : (string) $x;
        return ['pages' => $out, 'me' => $ig['me'] ?? null, 'insights' => array_values(array_filter($insights)), 'has_competitors' => (bool) $this->competitorDomains($owner)];
    }
}
