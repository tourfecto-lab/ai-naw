<?php

/**
 * التعليق يبقى حجز:
 *  - tripFor(): البوست اللي اتعلق عليه كان عن أنهي رحلة؟ (اللينك اللي في البوست نفسه، وإلا تطابق اسم الرحلة مع نص البوست)
 *    والسعر من منتجات العميل المؤكدة (crm_products) بس - مابنخمّنش سعر
 *  - capture(): اللي سأل عن السعر/الحجز بيدخل الـCRM كعميل محتمل (مرة واحدة لكل شخص على كل منصة) ومعاه الرحلة اللي سأل عليها
 * match() نقية (بتتختبر).
 */
final class SocialCommentLeads
{
    private Database $db;
    private static bool $ready = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        if (!self::$ready) {
            $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS social_comment_leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                platform VARCHAR(20) NOT NULL,
                author_id VARCHAR(100) NOT NULL,
                lead_id INT NULL,
                trip_name VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_author (user_id, platform, author_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready = true;
        }
    }

    /**
     * أحسن رحلة لنص البوست (نقية): اللينك في البوست بيكسب، وإلا أعلى تطابق كلمات (≥ 60% من كلمات اسم الرحلة و≥ كلمتين).
     * @param array $trips [['name','url'?,'price'?,'currency'?]]
     */
    public static function match(string $content, array $links, array $trips): ?array
    {
        $links = array_map(fn ($l) => rtrim(strtolower((string) $l), '/'), $links);
        foreach ($trips as $t) {
            $u = rtrim(strtolower((string) ($t['url'] ?? '')), '/');
            if ($u !== '' && (in_array($u, $links, true) || str_contains(strtolower($content), $u))) return $t;
        }
        $have = array_flip(SeasonMarketSignals::tokens($content));
        $best = null;
        $bestScore = 0.0;
        foreach ($trips as $t) {
            $tk = SeasonMarketSignals::tokens((string) $t['name']);
            if (count($tk) < 2) continue;
            $hit = count(array_filter($tk, fn ($w) => isset($have[$w])));
            $score = $hit / count($tk);
            if ($hit >= 2 && $score >= 0.6 && $score > $bestScore) {
                $best = $t;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** رحلات العميل: منتجاته المؤكدة بسعرها + رحلات موقعه بلينكاتها */
    private function trips(int $userId): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT p.name, p.price, p.currency, p.tour_slug, g.slug AS site_slug FROM crm_products p
                    LEFT JOIN generated_websites g ON g.user_id = p.user_id AND g.status = 'published'
                    WHERE p.user_id = ? AND p.is_active = 1 ORDER BY p.id DESC LIMIT 80", [$userId]) as $r) {
                $url = ($r['tour_slug'] && $r['site_slug']) ? rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/sites/' . $r['site_slug'] . '/tours/' . $r['tour_slug'] : '';
                $out[] = ['name' => (string) $r['name'], 'url' => $url, 'price' => (float) $r['price'] > 0 ? (float) $r['price'] : null, 'currency' => (string) ($r['currency'] ?: 'USD')];
            }
        } catch (Throwable $e) {
        }
        if (class_exists('SeasonSiteTrips')) {
            foreach ((new SeasonSiteTrips($this->db))->stored($userId) as $t) {
                $out[] = ['name' => (string) $t['name'], 'url' => (string) $t['url'], 'price' => null, 'currency' => null];
            }
        }
        return $out;
    }

    /** الرحلة اللي البوست المعلَّق عليه بيتكلم عنها (أو null) */
    public function tripFor(int $userId, array $comment): ?array
    {
        $content = (string) ($comment['post_excerpt'] ?? '');
        $links = [];
        if (!empty($comment['target_id'])) {
            $row = $this->db->query('SELECT p.id, p.content FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id WHERE t.id = ? LIMIT 1', [(int) $comment['target_id']])[0] ?? null;
            if ($row) {
                $content = (string) $row['content'] . "\n" . $content;
                try {
                    $links = array_column($this->db->query('SELECT original_url FROM social_links WHERE social_post_id = ? AND original_url IS NOT NULL', [(int) $row['id']]), 'original_url');
                } catch (Throwable $e) {
                }
            }
        }
        if (trim($content) === '') return null;
        $trip = self::match($content, $links, $this->trips($userId));
        if (!$trip) return null;
        // لو اللي اتطابق رحلة من الموقع من غير سعر، ندوّر على منتج بنفس الاسم بسعره المؤكد
        if ($trip['price'] === null) {
            foreach ($this->trips($userId) as $t) if ($t['price'] !== null && mb_strtolower($t['name']) === mb_strtolower($trip['name'])) { $trip['price'] = $t['price']; $trip['currency'] = $t['currency']; break; }
        }
        return $trip;
    }

    public static function priceText(?array $trip): string
    {
        if (!$trip || $trip['price'] === null) return '';
        $n = rtrim(rtrim(number_format((float) $trip['price'], 2, '.', ','), '0'), '.');
        return $n . ' ' . strtoupper((string) ($trip['currency'] ?: 'USD'));
    }

    /** اللي سأل عن السعر/الحجز ← عميل محتمل في الـCRM (مرة واحدة لكل شخص على كل منصة) */
    public function capture(int $userId, array $comment, ?array $trip): ?int
    {
        $author = trim((string) ($comment['author_id'] ?? ''));
        $platform = (string) ($comment['platform'] ?? 'facebook');
        if ($author === '' || !class_exists('CrmLeadService')) return null;
        if ($this->db->query('SELECT id FROM social_comment_leads WHERE user_id = ? AND platform = ? AND author_id = ? LIMIT 1', [$userId, $platform, $author])) return null;
        try {
            $svc = new CrmLeadService();
            $name = trim((string) ($comment['author_name'] ?? '')) ?: ($platform === 'instagram' ? 'Instagram user' : 'Facebook user');
            $contact = $svc->createContact($userId, ['name' => mb_substr($name, 0, 190), 'source' => $platform,
                'notes' => mb_substr('💬 ' . trim((string) ($comment['message'] ?? '')) . (!empty($comment['post_permalink']) ? "\n" . $comment['post_permalink'] : ''), 0, 1000)]);
            $lead = $svc->createLeadWithData((int) $contact->getAttribute('id'), $userId, [
                'source' => $platform . '_comment', 'interest' => $trip ? mb_substr($trip['name'], 0, 250) : null,
                'value' => $trip['price'] ?? null, 'currency' => $trip['currency'] ?? null, 'priority' => ($comment['intent'] ?? '') === 'booking' ? 'high' : 'medium',
            ]);
            $leadId = (int) $lead->getAttribute('id');
            $this->db->query('INSERT IGNORE INTO social_comment_leads (user_id, platform, author_id, lead_id, trip_name) VALUES (?, ?, ?, ?, ?)', [$userId, $platform, $author, $leadId, $trip['name'] ?? null]);
            return $leadId;
        } catch (Throwable $e) {
            Logger::warning('Social comment lead not captured', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
