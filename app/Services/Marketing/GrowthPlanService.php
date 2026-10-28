<?php

/**
 * خطة النمو اللي العميل بيراجعها في التسجيل الذكي:
 *  - إجابات 3 أسئلة (إزاي بيتحجز، الموسم، الهدف) → بتظهر للأدمن في متابعة التجارب
 *  - الكلمات المستهدفة + عناوين أول مقالات (مربوطة بخدماته الحقيقية)
 * المقالات بتدخل حملة محتوى SEO (نفس محرك المقالات اللي شغال)، بس على قد الحصة المتاحة:
 * الزيادة بتستنى في backlog وبتتنزل لوحدها أول ما الحصة تسمح (اشتراك/تجديد) - مابنعملش مقالات «فاشلة».
 */
class GrowthPlanService
{
    public const BOOKING = ['whatsapp', 'website', 'otas', 'agents', 'social'];
    public const SEASON = ['winter', 'summer', 'all_year'];
    public const GOAL = ['direct_bookings', 'google', 'reviews', 'beat_competitors'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS growth_plans (
            user_id INT NOT NULL PRIMARY KEY,
            website_id INT NULL,
            answers_json TEXT NULL,
            keywords_json TEXT NULL,
            campaign_id INT NULL,
            backlog_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** تنضيف الخطة اللي جاية من المتصفح */
    public static function sanitize($plan): ?array
    {
        if (!is_array($plan)) return null;
        $s = static fn ($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $a = is_array($plan['answers'] ?? null) ? $plan['answers'] : [];
        $answers = [
            'booking' => array_values(array_intersect(self::BOOKING, (array) ($a['booking'] ?? []))),
            'season' => in_array($a['season'] ?? '', self::SEASON, true) ? $a['season'] : null,
            'goal' => in_array($a['goal'] ?? '', self::GOAL, true) ? $a['goal'] : null,
            // النظام اللي اكتشفناه للموقع + نظام الحجز (للأدمن ولخطوة الربط)
            'platform' => preg_match('/^[a-z]{2,20}$/', (string) ($a['platform'] ?? '')) ? $a['platform'] : null,
            'booking_engines' => array_slice(array_map(fn ($b) => mb_substr((string) $b, 0, 30), is_array($a['booking_engines'] ?? null) ? $a['booking_engines'] : []), 0, 4),
        ];
        $keywords = [];
        foreach (array_slice((array) ($plan['keywords'] ?? []), 0, 10) as $k) {
            $kw = $s(is_array($k) ? ($k['keyword'] ?? '') : $k, 80);
            if ($kw !== '') $keywords[] = ['keyword' => $kw, 'volume' => is_array($k) && is_numeric($k['volume'] ?? null) ? (int) $k['volume'] : null];
        }
        $articles = [];
        foreach (array_slice((array) ($plan['articles'] ?? []), 0, 8) as $x) {
            $title = $s(is_array($x) ? ($x['title'] ?? '') : $x, 150);
            if ($title !== '') $articles[] = ['title' => $title, 'keyword' => $s(is_array($x) ? ($x['keyword'] ?? '') : '', 80)];
        }
        return ['answers' => $answers, 'keywords' => $keywords, 'articles' => $articles];
    }

    /** بعد ما الإعداد يعمل الموقع: نحفظ الخطة ونبدأ أول مقالات على قد الحصة */
    public function apply(int $userId, int $websiteId, array $plan): array
    {
        $articles = $plan['articles'] ?? [];
        $this->pdo->prepare('INSERT INTO growth_plans (user_id, website_id, answers_json, keywords_json, backlog_json) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE website_id=VALUES(website_id), answers_json=VALUES(answers_json), keywords_json=VALUES(keywords_json), backlog_json=VALUES(backlog_json)')
            ->execute([$userId, $websiteId, json_encode($plan['answers'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($plan['keywords'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($articles, JSON_UNESCAPED_UNICODE)]);
        $queued = $this->release($userId);
        return ['queued' => $queued, 'waiting' => max(0, count($articles) - $queued)];
    }

    /** كام مقال نقدر نبدأه دلوقتي من غير ما يفشل (null = مفتوح) */
    private function capacity(int $userId): int
    {
        if (!class_exists('PlanUsage')) return 0;
        $q = PlanUsage::quota($userId, 'article_generation');
        if (empty($q['subscription_id'])) return 0;
        $remaining = $q['remaining'] === null ? 5 : (int) $q['remaining'];
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM seo_content_items i JOIN seo_content_campaigns c ON c.id=i.campaign_id WHERE c.user_id=? AND i.status='queued'");
        $st->execute([$userId]);
        return max(0, min(5, $remaining - (int) $st->fetchColumn()));
    }

    /** ننزّل مقالات من الـbacklog للحملة على قد الحصة المتاحة. @return عدد اللي اتنزل */
    public function release(int $userId): int
    {
        $st = $this->pdo->prepare('SELECT * FROM growth_plans WHERE user_id=?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $backlog = $row ? (json_decode((string) $row['backlog_json'], true) ?: []) : [];
        if (!$row || !$backlog || !(int) $row['website_id']) return 0;
        $n = min(count($backlog), $this->capacity($userId));
        if ($n <= 0) return 0;
        $take = array_splice($backlog, 0, $n);
        $topics = array_map(fn ($a) => ['topic' => $a['title'], 'keyword' => $a['keyword'] ?: null], $take);
        $svc = new SeoContentService(Database::getInstance());
        $campaignId = (int) $row['campaign_id'];
        if ($campaignId) {
            $ins = $this->pdo->prepare("INSERT INTO seo_content_items (campaign_id, topic, keyword, status, created_at, updated_at) VALUES (?, ?, ?, 'queued', NOW(), NOW())");
            foreach ($topics as $t) $ins->execute([$campaignId, $t['topic'], $t['keyword']]);
            $this->pdo->prepare("UPDATE seo_content_campaigns SET total_items=total_items+?, status=IF(status='completed','generating',status), updated_at=NOW() WHERE id=?")->execute([count($topics), $campaignId]);
        } else {
            $r = $svc->createCampaign($userId, (int) $row['website_id'], 'خطة النمو - أول مقالات', $topics, 'manual');
            if (empty($r['success'])) return 0;
            $campaignId = (int) $r['campaign_id'];
        }
        $this->pdo->prepare('UPDATE growth_plans SET campaign_id=?, backlog_json=? WHERE user_id=?')->execute([$campaignId, json_encode(array_values($backlog), JSON_UNESCAPED_UNICODE), $userId]);
        return count($topics);
    }

    /** الـcron: أي خطة لسه فيها مقالات مستنية وصاحبها بقى عنده حصة */
    public function releaseDue(int $limit = 50): int
    {
        $n = 0;
        $rows = $this->pdo->query("SELECT user_id FROM growth_plans WHERE backlog_json IS NOT NULL AND backlog_json NOT IN ('','[]') LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $uid) {
            try { $n += $this->release((int) $uid); } catch (Throwable $e) { Logger::warning('Growth plan release failed', ['user_id' => $uid, 'error' => $e->getMessage()]); }
        }
        return $n;
    }

    /** للأدمن (متابعة التجارب): ملخص إجابات العميل */
    public function answersFor(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $st = $this->pdo->prepare('SELECT user_id, answers_json, keywords_json FROM growth_plans WHERE user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')');
        $st->execute($userIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['user_id']] = ['answers' => json_decode((string) $r['answers_json'], true) ?: [], 'keywords' => array_slice(json_decode((string) $r['keywords_json'], true) ?: [], 0, 5)];
        }
        return $out;
    }

    /**
     * عناوين أول مقالات بالذكاء الاصطناعي: كل عنوان مربوط بكلمة بحث وبخدمة العميل بيقدمها فعلًا،
     * ونيته حجز (مش مقالات عامة).
     */
    public static function suggestTitles(array $profile, array $keywords, array $answers, int $userId = 0): array
    {
        $lang = preg_match('/^[a-z]{2}$/', (string) ($profile['site_language'] ?? '')) ? $profile['site_language'] : 'en';
        $kws = array_slice(array_values(array_filter(array_map(fn ($k) => trim((string) (is_array($k) ? ($k['keyword'] ?? '') : $k)), $keywords))), 0, 8);
        if (!$kws) return [];
        $services = implode(', ', array_slice((array) ($profile['services'] ?? []), 0, 8));
        $goal = (string) ($answers['goal'] ?? '');
        $prompt = "You plan the first blog articles for a tour company website.\n"
            . "Business: " . mb_substr((string) ($profile['business_name'] ?? ''), 0, 100) . " — " . mb_substr((string) ($profile['description'] ?? ''), 0, 300) . "\n"
            . "Services they actually sell: {$services}\nLocation: " . mb_substr((string) ($profile['location'] ?? ''), 0, 80) . "\n"
            . "Target search keywords: " . implode(' | ', $kws) . "\n"
            . ($goal === 'direct_bookings' ? "Goal: more direct bookings instead of paying OTA commission.\n" : '')
            . "Write 5 article titles in language '{$lang}'. Each title must target ONE of the keywords above, be about a service they really sell, help a traveller decide and book (prices, itinerary, best time, what's included, how to choose), and sound natural (max 70 characters). No clickbait, no years unless needed.\n"
            . 'Return JSON only: {"articles":[{"title":"","keyword":""}]}';
        try {
            $res = (new GeminiClient())->generateContent($prompt, ['task' => 'website_builder', 'user_id' => $userId, 'output_language' => $lang, 'temperature' => 0.6, 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json']);
            if (empty($res['success'])) return [];
            $txt = preg_replace('/^```(?:json)?|```$/m', '', trim((string) $res['data']));
            $j = json_decode(trim((string) $txt), true);
            if (!is_array($j) && preg_match('/\{.*\}/s', (string) $txt, $m)) $j = json_decode($m[0], true);
            $out = [];
            foreach ((array) ($j['articles'] ?? []) as $a) {
                $t = mb_substr(trim(strip_tags((string) ($a['title'] ?? ''))), 0, 150);
                if ($t !== '') $out[] = ['title' => $t, 'keyword' => mb_substr(trim((string) ($a['keyword'] ?? '')), 0, 80)];
            }
            return array_slice($out, 0, 5);
        } catch (Throwable $e) {
            Logger::warning('Growth plan titles failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
