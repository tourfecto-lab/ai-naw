<?php

/**
 * Tourfecto - Ad Context Builder
 * @version 1.0.0
 *
 * الفرق بين إعلان عام وإعلان خبير هو "المعلومة": الخبير بيقرا ملف النشاط،
 * والتقييمات الحقيقية، وصفحة الهبوط، وبيعرف المنافسين واللي نجح قبل كده.
 * الكلاس ده بيجمّع كل ده (من بيانات المنصة نفسها) في نص واحد يدخل للذكاء
 * الاصطناعي، مع حدود حجم واضحة. أي مصدر مش متاح بيتجاهل بهدوء.
 */
class AdContextBuilder
{
    private const MAX_REVIEWS = 5;
    private const MAX_PAGE_TEXT = 1800;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * @return array{text:string, facts:array} النص الجاهز للـPrompt + الحقائق الخام (للاستخدام في الإضافات)
     */
    public function build(int $userId, ?int $websiteId, ?AdCampaign $campaign = null, ?string $landingUrl = null): array
    {
        $facts = [];
        $sections = [];

        $website = $this->website($userId, $websiteId);
        $business = $this->business($userId, $website);

        if ($business) {
            $facts['business'] = $business;
            $businessText = '';
            if (class_exists('BusinessContextService')) {
                try {
                    $businessText = (new BusinessContextService())->toPromptContext((int) $business['id']);
                } catch (Throwable $e) {
                    $businessText = '';
                }
            }
            if ($businessText === '') {
                $businessText = trim(implode("\n", array_filter([
                    !empty($business['trade_name']) ? 'Business Name: ' . $business['trade_name'] : null,
                    !empty($business['description']) ? 'About: ' . mb_substr((string) $business['description'], 0, 600) : null,
                ])));
            }
            if ($businessText !== '') {
                $sections[] = "## ملف النشاط\n" . $businessText;
            }
        }

        if ($website) {
            $facts['website'] = $website;
            $lines = array_filter([
                !empty($website['company_name']) ? 'Company: ' . $website['company_name'] : null,
                !empty($website['main_url']) ? 'Website: ' . $website['main_url'] : null,
                !empty($website['meta_description']) ? 'Website description: ' . mb_substr((string) $website['meta_description'], 0, 300) : null,
            ]);
            if ($lines) {
                $sections[] = "## الموقع\n" . implode("\n", $lines);
            }
        }

        $reviews = $this->topReviews($userId, $website ? (int) $website['id'] : null);
        if ($reviews) {
            $facts['reviews'] = $reviews;
            $sections[] = "## تقييمات عملاء حقيقية (استخدم معناها كدليل اجتماعي، ممنوع تخترع أرقام أو اقتباسات غيرها)\n"
                . implode("\n", array_map(fn ($r) => '- (' . $r['rating'] . '★ ' . $r['platform'] . ') ' . $r['text'], $reviews));
        }

        $pageText = $this->landingText($campaign, $landingUrl);
        if ($pageText !== '') {
            $facts['landing_text'] = $pageText;
            $sections[] = "## محتوى صفحة الهبوط (الحقائق: أسعار/مدة/مميزات - استخدم الموجود هنا بس)\n" . $pageText;
        }

        $competitors = $this->competitorNotes($website ? (int) $website['id'] : null);
        if ($competitors !== '') {
            $sections[] = "## ملاحظات عن المنافسين (اتميّز عنهم، متذكرش أسماءهم)\n" . $competitors;
        }

        $winners = $this->winningAds($userId);
        if ($winners) {
            $sections[] = "## إعلانات سابقة للعميل حققت أعلى نسبة ضغط (اتعلم من أسلوبها، متنسخهاش)\n"
                . implode("\n", array_map(fn ($w) => '- CTR ' . $w['ctr'] . '%: "' . $w['headline'] . '" — ' . $w['text'], $winners));
        }

        return ['text' => implode("\n\n", $sections), 'facts' => $facts];
    }

    /**
     * روابط داخلية حقيقية من صفحة الموقع (لـ Sitelinks بتاعة Google) - Google بترفض
     * Sitelinks بتودّي على نفس الصفحة، فلازم روابط فعلية مختلفة من نفس الدومين.
     * @return array<int, array{url:string,text:string}>
     */
    public function internalLinks(string $url, int $max = 25): array
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return [];
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $links = [];
        if (!preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $match) {
            $href = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '' || mb_strlen($text) > 60 || preg_match('#^(mailto|tel|javascript|whatsapp):#i', $href)) {
                continue;
            }
            if (str_starts_with($href, '//')) {
                $href = $scheme . ':' . $href;
            } elseif (str_starts_with($href, '/')) {
                $href = $scheme . '://' . $host . $href;
            } elseif (!preg_match('#^https?://#i', $href)) {
                continue;
            }
            $linkHost = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($linkHost !== $host && $linkHost !== 'www.' . $host && 'www.' . $linkHost !== $host) {
                continue;
            }
            $normalized = rtrim(strtok($href, '?') ?: $href, '/');
            if ($normalized === rtrim(strtok($url, '?') ?: $url, '/') || isset($links[$normalized])) {
                continue;
            }
            $links[$normalized] = ['url' => $href, 'text' => $text];
            if (count($links) >= $max) {
                break;
            }
        }
        return array_values($links);
    }

    private function website(int $userId, ?int $websiteId): ?array
    {
        try {
            $rows = $websiteId
                ? $this->db->query('SELECT * FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $userId])
                : $this->db->query('SELECT * FROM websites WHERE user_id = ?' . WebsiteSchema::alive() . ' ORDER BY id ASC LIMIT 1', [$userId]);
            return $rows[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function business(int $userId, ?array $website): ?array
    {
        try {
            if (!empty($website['business_id'])) {
                $rows = $this->db->query('SELECT * FROM businesses WHERE id = ? AND owner_user_id = ? LIMIT 1', [(int) $website['business_id'], $userId]);
                if ($rows) {
                    return $rows[0];
                }
            }
            $rows = $this->db->query('SELECT * FROM businesses WHERE owner_user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);
            return $rows[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function topReviews(int $userId, ?int $websiteId): array
    {
        try {
            $rows = $websiteId
                ? $this->db->query(
                    "SELECT platform, rating, review_text FROM reviews
                     WHERE website_id = ? AND rating >= 4 AND CHAR_LENGTH(review_text) BETWEEN 40 AND 1500
                     ORDER BY rating DESC, review_date DESC LIMIT " . self::MAX_REVIEWS,
                    [$websiteId]
                )
                : $this->db->query(
                    "SELECT platform, rating, review_text FROM reviews
                     WHERE user_id = ? AND rating >= 4 AND CHAR_LENGTH(review_text) BETWEEN 40 AND 1500
                     ORDER BY rating DESC, review_date DESC LIMIT " . self::MAX_REVIEWS,
                    [$userId]
                );
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn ($r) => [
            'platform' => (string) $r['platform'],
            'rating' => rtrim(rtrim(number_format((float) $r['rating'], 1), '0'), '.'),
            'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r['review_text'])), 0, 220),
        ], $rows);
    }

    private function landingText(?AdCampaign $campaign, ?string $landingUrl): string
    {
        if ($campaign && $campaign->getAttribute('landing_page_last_analysis')) {
            $analysis = json_decode((string) $campaign->getAttribute('landing_page_last_analysis'), true);
            if (!empty($analysis['page_text'])) {
                return mb_substr((string) $analysis['page_text'], 0, self::MAX_PAGE_TEXT);
            }
        }
        $url = $landingUrl ?: ($campaign ? (string) $campaign->getAttribute('landing_page_url') : '');
        if ($url === '') {
            return '';
        }
        $html = $this->fetch($url);
        if ($html === null) {
            return '';
        }
        $html = preg_replace('/<(script|style|noscript|svg|nav|footer)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_PAGE_TEXT);
    }

    private function competitorNotes(?int $websiteId): string
    {
        if (!$websiteId) {
            return '';
        }
        try {
            $rows = $this->db->query(
                'SELECT insights_json FROM ad_competitor_insights WHERE website_id = ? ORDER BY created_at DESC LIMIT 2',
                [$websiteId]
            );
        } catch (Throwable $e) {
            return '';
        }
        $notes = [];
        foreach ($rows as $r) {
            $data = json_decode((string) $r['insights_json'], true);
            if (!is_array($data)) {
                continue;
            }
            foreach (['summary', 'positioning', 'weaknesses', 'differentiation'] as $k) {
                if (!empty($data[$k]) && is_string($data[$k])) {
                    $notes[] = '- ' . mb_substr($data[$k], 0, 250);
                }
            }
            foreach (array_slice((array) ($data['recommendations'] ?? []), 0, 3) as $rec) {
                $text = is_array($rec) ? (string) ($rec['text'] ?? '') : (string) $rec;
                if ($text !== '') {
                    $notes[] = '- ' . mb_substr($text, 0, 200);
                }
            }
        }
        return implode("\n", array_slice($notes, 0, 6));
    }

    private function winningAds(int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT headline, primary_text, impressions, clicks FROM ad_creative_variants
                 WHERE user_id = ? AND impressions >= 500 AND headline IS NOT NULL
                 ORDER BY (clicks / impressions) DESC LIMIT 3",
                [$userId]
            );
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn ($r) => [
            'headline' => mb_substr((string) $r['headline'], 0, 80),
            'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r['primary_text'])), 0, 160),
            'ctr' => round(((int) $r['clicks'] / max(1, (int) $r['impressions'])) * 100, 2),
        ], $rows);
    }

    /** جلب صفحة عامة بأمان (منع SSRF) وبحد أقصى للحجم والوقت */
    private function fetch(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!function_exists('curl_init') || (class_exists('SsrfGuard') && !SsrfGuard::isSafe($url))) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0; +https://tourfecto.com/bot)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        // تحويل واحد بس ولازم يعدي فحص الأمان برضه
        if ($code >= 300 && $code < 400 && $redirect !== '' && (!class_exists('SsrfGuard') || SsrfGuard::isSafe($redirect))) {
            $ch = curl_init($redirect);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0; +https://tourfecto.com/bot)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if (!is_string($body) || $code < 200 || $code >= 300) {
            return null;
        }
        return substr($body, 0, 600000);
    }
}
