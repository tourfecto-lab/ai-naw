<?php
require_once __DIR__ . '/AiCostIntelligence.php';

/**
 * TOURFECTO AI CEO (المرحلة 5) — نمو علامة Tourfecto نفسها: منين بييجي الزوار واللي بيسجلوا، وحالة حسابات Tourfecto
 * في خدمات المنصة، وخطة محتوى وإعلانات شهرية.
 *
 * - الاكتساب: من visitor_logs الموجود (VisitorTrackingMiddleware). صفحة الدخول = أول صفحة للزائر في الفترة.
 *   التسجيل بيتنسب لأول صفحة ومصدر للزائر اللي سجّل (لو اتسجلت زيارات قبل التسجيل)، وإلا "غير معروف".
 * - الحضور: موقع Tourfecto جوه حساب صاحب المنصة (نفس دومين الموقع): الربط بجوجل والسوشيال، والتقييمات، والبوستات.
 * - الخطة الشهرية: بالذكاء الاصطناعي (AIOrchestrator، مهمة ai_ceo_brand_plan، على ميزانية AI CEO)، والخدمات المذكورة فيها
 *   من MarketingContent::features() بس (اللي موجود فعلًا). الإعلانات مسودات وخطط بس؛ الإعلانات المدفوعة متوقفة.
 */
final class BrandGrowth
{
    private const NON_PUBLIC = "(v.page_url LIKE '%/admin%' OR v.page_url LIKE '%/api/%' OR v.page_url LIKE '%/dashboard%' OR v.page_url LIKE '%/assets/%' OR v.page_url LIKE '%/login%' OR v.page_url LIKE '%/register%' OR v.page_url LIKE '%/logout%')";
    private const CUSTOMER = "u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND (u.owner_user_id IS NULL OR u.owner_user_id = 0)";

    private Database $db;
    /** @var callable|null للاختبارات: fn(string $prompt): ?string */
    private $ai;

    public function __construct(?Database $db = null, ?callable $ai = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ai = $ai;
    }

    public static function path(string $url): string
    {
        $p = rtrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        return $p === '' ? '/' : $p;
    }

    public static function host(?string $url): string
    {
        $h = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        return $h === '' ? '(direct)' : preg_replace('#^www\.#', '', $h);
    }

    /** الزوار وصفحات الدخول والمصادر، والتسجيلات منسوبة لأول صفحة ومصدر */
    public function acquisition(int $days = 30): array
    {
        $days = max(1, min(180, $days));
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $land = $this->db->query("SELECT v.visitor_id, v.page_url, v.referrer, v.country FROM visitor_logs v
            JOIN (SELECT visitor_id, MIN(id) AS first_id FROM visitor_logs WHERE created_at >= ? AND is_authenticated = 0 GROUP BY visitor_id) f ON f.first_id = v.id
            WHERE NOT " . self::NON_PUBLIC . ' LIMIT 50000', [$since]) ?: [];
        $own = self::host(defined('APP_URL') ? APP_URL : '');
        $pages = $refs = $countries = [];
        foreach ($land as $r) {
            $p = self::path((string) $r['page_url']);
            $h = self::host($r['referrer']);
            if ($h === $own) $h = '(internal)';
            $pages[$p]['visitors'] = ($pages[$p]['visitors'] ?? 0) + 1;
            $refs[$h]['visitors'] = ($refs[$h]['visitors'] ?? 0) + 1;
            $c = (string) ($r['country'] ?: '?');
            $countries[$c]['visitors'] = ($countries[$c]['visitors'] ?? 0) + 1;
        }
        // التسجيلات: أول زيارة للزائر اللي سجّل
        $signups = $this->db->query('SELECT u.id, (SELECT v.visitor_id FROM visitor_logs v WHERE v.user_id = u.id ORDER BY v.id ASC LIMIT 1) AS visitor_id FROM users u WHERE ' . self::CUSTOMER . ' AND u.created_at >= ?', [$since]) ?: [];
        $unknown = 0;
        foreach ($signups as $s) {
            $first = $s['visitor_id'] ? ($this->db->query('SELECT page_url, referrer, country FROM visitor_logs WHERE visitor_id = ? ORDER BY id ASC LIMIT 1', [$s['visitor_id']])[0] ?? null) : null;
            if (!$first) { $unknown++; continue; }
            $p = self::path((string) $first['page_url']);
            $h = self::host($first['referrer']);
            if ($h === $own) $h = '(internal)';
            $pages[$p]['signups'] = ($pages[$p]['signups'] ?? 0) + 1;
            $refs[$h]['signups'] = ($refs[$h]['signups'] ?? 0) + 1;
            $c = (string) ($first['country'] ?: '?');
            $countries[$c]['signups'] = ($countries[$c]['signups'] ?? 0) + 1;
        }
        $shape = static function (array $rows, int $limit): array {
            $out = [];
            foreach ($rows as $k => $v) {
                $vis = (int) ($v['visitors'] ?? 0);
                $sig = (int) ($v['signups'] ?? 0);
                $out[] = ['key' => (string) $k, 'visitors' => $vis, 'signups' => $sig, 'conversion' => $vis > 0 ? round($sig / $vis * 100, 2) : null];
            }
            usort($out, static fn ($a, $b) => [$b['signups'], $b['visitors']] <=> [$a['signups'], $a['visitors']]);
            return array_slice($out, 0, $limit);
        };
        return ['days' => $days, 'visitors' => count($land), 'signups' => count($signups), 'signups_unattributed' => $unknown,
            'landing_pages' => $shape($pages, 25), 'referrers' => $shape($refs, 20), 'countries' => $shape($countries, 20)];
    }

    /** موقع Tourfecto نفسه جوه حساب صاحب المنصة: الربط والتقييمات والسوشيال */
    public function presence(string $siteUrl): array
    {
        $host = self::host($siteUrl);
        $site = $this->db->query("SELECT w.id, w.user_id, w.company_name, w.main_url FROM websites w JOIN users u ON u.id = w.user_id
            WHERE u.role IN ('admin','super_admin') AND LOWER(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(w.main_url, '://', -1), '/', 1), 'www.', '')) = ? ORDER BY w.id LIMIT 1", [$host])[0] ?? null;
        if (!$site) return ['website' => null, 'note' => 'No website for ' . $host . ' in an admin account. Add tourfecto.pro as a website in the owner account to use the platform\'s own SEO, social, reviews and studio for Tourfecto.'];
        $wid = (int) $site['id'];
        $uid = (int) $site['user_id'];
        $safe = function (string $sql, array $p) { try { return $this->db->query($sql, $p) ?: []; } catch (Throwable $e) { return null; } };
        $conns = $safe("SELECT platform, status FROM platform_connections WHERE website_id = ?", [$wid]);
        $reviews = $safe("SELECT COUNT(*) AS total, AVG(rating) AS avg_rating, SUM(rating <= 3 AND reply_sent_at IS NULL AND COALESCE(reply_status, '') <> 'rejected') AS unanswered_negative FROM reviews WHERE website_id = ?", [$wid]);
        $social = $safe("SELECT SUM(status = 'scheduled') AS scheduled, SUM(status = 'published' AND updated_at > NOW() - INTERVAL 30 DAY) AS published_30 FROM social_posts WHERE user_id = ?", [$uid]);
        return ['website' => ['id' => $wid, 'name' => $site['company_name'], 'url' => $site['main_url'], 'owner_user_id' => $uid],
            'connections' => $conns === null ? null : array_map(static fn ($c) => ['platform' => $c['platform'], 'status' => $c['status']], $conns),
            'reviews' => $reviews === null ? null : ['total' => (int) ($reviews[0]['total'] ?? 0), 'avg_rating' => isset($reviews[0]['avg_rating']) ? round((float) $reviews[0]['avg_rating'], 2) : null, 'unanswered_negative' => (int) ($reviews[0]['unanswered_negative'] ?? 0)],
            'social' => $social === null ? null : ['scheduled' => (int) ($social[0]['scheduled'] ?? 0), 'published_30d' => (int) ($social[0]['published_30'] ?? 0)]];
    }

    /** الخدمات الموجودة فعلًا (من صفحات المميزات العامة): اللي الخطة مسموح تتكلم عنه بس */
    public static function verifiedFeatures(): array
    {
        $out = [];
        try {
            foreach (MarketingContent::features() as $slug => $f) {
                $out[] = ['slug' => $slug, 'title' => $f['title'][1] ?? $slug, 'capabilities' => array_map(static fn ($c) => $c[0][1] ?? '', $f['capabilities'] ?? [])];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * خطة محتوى وإعلانات للشهر (مسودة، مفيش نشر). بتتحفظ في الذاكرة المؤسسية (plan).
     * @return array{plan:array, warnings:string[], title:string}
     */
    public function monthlyPlan(int $ownerId, string $month, array $markets, array $acquisition, ?AiCostIntelligence $costs = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new InvalidArgumentException('Month must be YYYY-MM');
        $markets = array_values(array_intersect(['us', 'eu', 'gcc', 'other'], $markets)) ?: ['us', 'eu', 'gcc'];
        if ($costs && ($b = $costs->aiCeoBlocked())) throw new RuntimeException('budget_' . $b);
        $features = self::verifiedFeatures();
        if (!$features) throw new RuntimeException('features_unavailable');
        $evidence = ['top_landing_pages' => array_slice($acquisition['landing_pages'] ?? [], 0, 8), 'top_referrers' => array_slice($acquisition['referrers'] ?? [], 0, 8), 'top_countries' => array_slice($acquisition['countries'] ?? [], 0, 8)];
        $prompt = "You are the content and growth lead of Tourfecto, a SaaS platform for tourism businesses (tour operators, travel agencies, DMCs).\n"
            . "Write a marketing plan for {$month} for these target markets: " . implode(', ', $markets) . " (us = United States, eu = Europe, gcc = Gulf, other = rest of world).\n"
            . "STRICT RULES:\n- Mention ONLY the Tourfecto features listed in FEATURES. Never claim results, rankings, guarantees or features that are not listed.\n"
            . "- Paid ads are DISABLED: ad items are drafts for the owner to review, with no budget committed.\n- Use the language of each market (English for us and eu; Arabic and English for gcc).\n"
            . "- Prefer topics and landing pages that already bring sign-ups in DATA.\n"
            . "Return ONLY JSON: {\"positioning\": string, \"markets\": [{\"market\": string, \"language\": string, \"posts\": [{\"channel\": string, \"format\": \"image|carousel|video|text\", \"topic\": string, \"hook\": string, \"feature\": string, \"landing_page\": string}], \"articles\": [{\"keyword\": string, \"title\": string, \"outline\": [string]}], \"videos\": [{\"title\": string, \"script_outline\": [string], \"feature\": string}]}], \"ad_drafts\": {\"google\": [{\"market\": string, \"keywords\": [string], \"headlines\": [string], \"landing_page\": string}], \"meta\": [{\"market\": string, \"audience\": string, \"primary_text\": string, \"landing_page\": string}]}, \"kpis\": [string]}\n"
            . "Per market: 8 posts, 2 articles, 1 video. Keep each text short.\n\nFEATURES:\n" . json_encode($features, JSON_UNESCAPED_UNICODE) . "\n\nDATA (last 30 days):\n" . json_encode($evidence, JSON_UNESCAPED_UNICODE);
        if ($this->ai) {
            $raw = call_user_func($this->ai, $prompt);
        } else {
            $r = (new AIOrchestrator())->generateContent($prompt, ['task' => 'ai_ceo_brand_plan', 'user_id' => $ownerId, 'maxOutputTokens' => 6000, 'temperature' => 0.5, 'responseMimeType' => 'application/json']);
            $raw = !empty($r['success']) ? (string) ($r['data'] ?? '') : null;
        }
        if (!$raw) throw new RuntimeException('ai_unavailable');
        $plan = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw))), true);
        if (!is_array($plan) || empty($plan['markets']) || !is_array($plan['markets'])) throw new RuntimeException('invalid_plan');
        return ['plan' => $plan, 'warnings' => self::claimWarnings($plan), 'title' => 'Brand content plan ' . $month];
    }

    /** عبارات ممنوعة (وعود ونتايج مضمونة) لازم صاحب المنصة يشيلها قبل النشر */
    public static function claimWarnings(array $plan): array
    {
        $text = mb_strtolower(json_encode($plan, JSON_UNESCAPED_UNICODE));
        $out = [];
        foreach (['guarantee' => 'promises a guarantee', 'guaranteed' => 'promises a guarantee', '#1' => 'claims to be number one', 'number one' => 'claims to be number one',
            'first page' => 'promises a search ranking', 'rank first' => 'promises a search ranking', '100%' => 'claims 100%', 'نضمن' => 'promises a guarantee', 'مضمون' => 'promises a guarantee'] as $needle => $why) {
            if (str_contains($text, $needle)) $out[] = '"' . $needle . '" ' . $why;
        }
        return array_values(array_unique($out));
    }
}
