<?php
require_once __DIR__ . '/../ExecutiveDashboard/ExecutiveDashboardService.php';
require_once __DIR__ . '/../Growth/GrowthSignalsService.php';
require_once __DIR__ . '/../BusinessHub/BusinessHubService.php';
require_once __DIR__ . '/MarketIntelligenceService.php';

/**
 * Tourfecto - العقل الاستراتيجي لكل شركة (الفهم)
 *
 * بيجمع اللي المنصة عارفاه فعلًا عن الشركة من الخدمات الموجودة (ملف الشركة، الدرجات التنفيذية،
 * إشارات الحجوزات، مركز السيو، السمعة، الشات، المنافسين، السوشيال، الإعلانات) في صورة واحدة.
 *
 * قواعد:
 * - كل استعلام مقيّد بـ user_id، والموقع بيتأكد إنه ملك المستخدم قبل أي قراءة.
 * - مصدر مش متاح (جدول ناقص أو خطأ) = null، مش صفر: العقل مايبنيش قرار على رقم مش مقاس.
 * - مفيش أي طلب ذكاء اصطناعي هنا؛ الفهم ده ببلاش ومن بيانات محفوظة.
 */
final class CompanyBrainService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** المواقع اللي يملكها المستخدم (لاختيار الموقع في الصفحة) */
    public function websites(int $uid): array
    {
        return $this->rows('SELECT id, main_url, company_name FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$uid]) ?? [];
    }

    /** @param bool $refreshMarket يحدّث مصدر سوق قديم واحد (مهلة قصيرة)؛ false في الاختبارات أو وقت الحفظ */
    public function snapshot(int $uid, int $wid, bool $refreshMarket = true): array
    {
        $site = $this->first('SELECT id, main_url, company_name FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $uid]);
        if (!$site) throw new InvalidArgumentException('Website not found');

        $profile = $this->safe(fn () => (new BusinessHubService($this->db))->profile($uid, $wid));
        $facts = is_array($profile['facts'] ?? null) ? $profile['facts'] : [];
        $scores = $this->safe(fn () => (new ExecutiveDashboardService())->getScores($this->db, $uid, $wid)) ?? [];
        $growth = $this->safe(fn () => (new GrowthSignalsService($this->db))->forWebsite($uid, $wid, gmdate('Y-m'), 12));

        $reviews = $this->first("SELECT COUNT(*) AS total, AVG(rating) AS avg_rating,
                SUM(rating <= 3 AND reply_sent_at IS NULL) AS unanswered_negative
            FROM reviews WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", [$uid]);
        $chat = $this->first("SELECT COUNT(*) AS messages, SUM(bot_status = 'pending_approval') AS pending_approval
            FROM chat_messages WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", [$uid]);
        $bookings = $this->first("SELECT SUM(created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) AS last_30, SUM(created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)) AS last_90,
                SUM(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_amount ELSE 0 END) AS revenue_30
            FROM bookings WHERE user_id = ? AND status IN ('confirmed','completed')", [$uid]);
        $seoSite = $this->first('SELECT status, policy_json, cycles_used, budget_month FROM seo_expert_sites WHERE website_id = ? AND user_id = ? LIMIT 1', [$wid, $uid]);
        $seoTasks = $this->first("SELECT COUNT(*) AS c FROM seo_expert_tasks t JOIN seo_expert_runs r ON r.id = t.run_id
            WHERE r.website_id = ? AND t.user_id = ? AND t.status = 'needs_client'
              AND r.id = (SELECT MAX(id) FROM seo_expert_runs WHERE website_id = ? AND user_id = ?)", [$wid, $uid, $wid, $uid]);
        $competitors = $this->first('SELECT COUNT(*) AS c FROM competitors WHERE user_id = ?', [$uid]);
        $social = $this->first("SELECT SUM(status = 'published' AND updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) AS published_30,
                SUM(status = 'scheduled') AS scheduled FROM social_posts WHERE user_id = ?", [$uid]);
        $ads = $this->first("SELECT SUM(status = 'active') AS active, COALESCE(SUM(spend), 0) AS spend_total FROM ad_campaigns WHERE user_id = ?", [$uid]);
        $aiQuestions = $this->first('SELECT COUNT(*) AS sets, SUM(approved_at IS NOT NULL) AS approved FROM seo_ai_prompt_sets WHERE website_id = ? AND user_id = ?', [$wid, $uid]);

        // ذكاء السوق: الوجهة والأسواق من نص الشركة نفسه، والأرقام من مصادر رسمية عامة
        $place = MarketIntelligenceService::detect((string) ($facts['business_facts'] ?? ''), (string) ($facts['audience'] ?? ''), (array) ($growth['markets']['countries'] ?? []));
        $market = $this->safe(fn () => (new MarketIntelligenceService($this->db))->forCompany($place['destination'], $place['markets'], $growth['lead_time_days'] ?? null, $refreshMarket));
        if ($market) $market['markets_default'] = $place['markets_default'];

        $policy = json_decode((string) ($seoSite['policy_json'] ?? ''), true) ?: [];
        $month = gmdate('Y-m');
        $int = static fn ($row, string $k) => is_array($row) && isset($row[$k]) && $row[$k] !== null ? (int) $row[$k] : null;

        return [
            'website' => ['id' => (int) $site['id'], 'url' => (string) $site['main_url'], 'name' => (string) ($facts['business_name'] ?? $site['company_name'] ?? '')],
            'understanding' => [
                'profile_confirmed' => !empty($facts),
                'business_name' => $facts['business_name'] ?? null,
                'services' => $facts['business_facts'] ?? null,
                'audience' => $facts['audience'] ?? null,
                'goal' => $facts['goal'] ?? null,
                'offers' => $facts['offers'] ?? null,
                'markets' => $growth['markets'] ?? [],
            ],
            'scores' => $scores,
            'growth' => $growth ? [
                'season' => $growth['season']['level'] ?? 'unknown',
                'season_index' => $growth['season']['index'] ?? null,
                'lead_time_days' => $growth['lead_time_days'] ?? null,
                'focus_trips' => array_slice($growth['focus_trips'] ?? [], 0, 8),
                'evidence' => $growth['evidence'] ?? [],
                'confidence' => $growth['confidence'] ?? 'low',
                'revenue' => $growth['revenue'] ?? null,
            ] : null,
            'reputation' => $reviews === null ? null : ['reviews_30' => $int($reviews, 'total'), 'avg_rating' => isset($reviews['avg_rating']) ? round((float) $reviews['avg_rating'], 2) : null, 'unanswered_negative' => $int($reviews, 'unanswered_negative')],
            'chat' => $chat === null ? null : ['messages_30' => $int($chat, 'messages'), 'pending_approval' => $int($chat, 'pending_approval')],
            'bookings' => $bookings === null ? null : ['last_30' => $int($bookings, 'last_30') ?? 0, 'last_90' => $int($bookings, 'last_90') ?? 0, 'revenue_30' => isset($bookings['revenue_30']) ? round((float) $bookings['revenue_30'], 2) : null],
            'seo' => [
                'autopilot' => $seoSite ? ($seoSite['status'] === 'active' && !empty($policy['autopilot'])) : false,
                'configured' => (bool) $seoSite,
                'checks_used' => $seoSite && ($seoSite['budget_month'] ?? '') === $month ? (int) $seoSite['cycles_used'] : 0,
                'checks_limit' => isset($policy['max_cycles']) ? (int) $policy['max_cycles'] : null,
                'client_tasks' => $int($seoTasks, 'c'),
                'ai_questions_approved' => $int($aiQuestions, 'approved'),
            ],
            'competitors' => ['tracked' => $int($competitors, 'c')],
            'social' => $social === null ? null : ['published_30' => $int($social, 'published_30') ?? 0, 'scheduled' => $int($social, 'scheduled') ?? 0],
            'ads' => $ads === null ? null : ['active' => $int($ads, 'active') ?? 0, 'spend_total' => isset($ads['spend_total']) ? round((float) $ads['spend_total'], 2) : null],
            'market' => $market,
            'generated_at' => gmdate('c'),
        ];
    }

    /** @return array<int,array<string,mixed>>|null null لو المصدر مش متاح */
    private function rows(string $sql, array $params): ?array
    {
        try {
            $r = $this->db->query($sql, $params);
            return is_array($r) ? $r : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** أول صف، أو null لو المصدر مش متاح */
    private function first(string $sql, array $params): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows === null ? null : ($rows[0] ?? []);
    }

    private function safe(callable $fn)
    {
        try { return $fn(); } catch (Throwable $e) { return null; }
    }
}
