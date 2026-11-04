<?php

/**
 * Reputation: monthly white-label report for one website (agencies send it to their clients).
 * One printable page under the customer's own brand (name, logo, colours from the brand kit), no Tourfecto branding,
 * "Save as PDF" from the browser. Numbers come only from the reviews table; nothing is estimated.
 * build() gathers the numbers, insights() is pure (tested), render() produces the HTML.
 */
require_once __DIR__ . '/../SocialMedia/SocialBrandStudio.php';

class ReputationReport
{
    public static function monthOrDefault(?string $month): string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) ? $month : gmdate('Y-m', strtotime('first day of last month'));
    }

    /** @return array<string,mixed> */
    public static function build(int $userId, int $websiteId, string $month, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $start = $month . '-01 00:00:00';
        $end = gmdate('Y-m-d H:i:s', strtotime($start . ' +1 month'));
        $prevStart = gmdate('Y-m-d H:i:s', strtotime($start . ' -1 month'));
        $date = 'COALESCE(review_date, created_at)';
        $site = $db->query('SELECT id, company_name, main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $userId])[0] ?? null;
        if (!$site) {
            throw new InvalidArgumentException('Website not found');
        }
        $period = static function (string $from, string $to) use ($db, $userId, $websiteId, $date): array {
            $r = $db->query(
                "SELECT COUNT(*) AS total, ROUND(AVG(NULLIF(rating,0)),2) AS avg_rating,
                        SUM(sentiment = 'positive') AS positive, SUM(sentiment = 'neutral') AS neutral, SUM(sentiment = 'negative') AS negative,
                        SUM(reply_sent_at IS NOT NULL) AS replied,
                        AVG(CASE WHEN reply_sent_at IS NOT NULL AND reply_sent_at >= review_date THEN TIMESTAMPDIFF(HOUR, review_date, reply_sent_at) END) AS avg_reply_hours
                   FROM reviews WHERE user_id = ? AND website_id = ? AND $date >= ? AND $date < ?",
                [$userId, $websiteId, $from, $to]
            )[0] ?? [];
            $total = (int) ($r['total'] ?? 0);
            return [
                'total' => $total,
                'avg_rating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null,
                'positive' => (int) ($r['positive'] ?? 0),
                'neutral' => (int) ($r['neutral'] ?? 0),
                'negative' => (int) ($r['negative'] ?? 0),
                'replied' => (int) ($r['replied'] ?? 0),
                'response_rate' => $total ? round(((int) $r['replied']) / $total * 100) : null,
                'avg_reply_hours' => $r['avg_reply_hours'] !== null ? round((float) $r['avg_reply_hours'], 1) : null,
            ];
        };
        $data = ['month' => $month, 'site' => ['name' => $site['company_name'] ?: $site['main_url'], 'url' => $site['main_url']],
            'brand' => self::brand($userId, $websiteId), 'current' => $period($start, $end), 'previous' => $period($prevStart, $start)];

        $data['by_rating'] = array_fill_keys([5, 4, 3, 2, 1], 0);
        foreach ($db->query("SELECT ROUND(rating) AS r, COUNT(*) c FROM reviews WHERE user_id = ? AND website_id = ? AND $date >= ? AND $date < ? GROUP BY ROUND(rating)", [$userId, $websiteId, $start, $end]) as $row) {
            if (isset($data['by_rating'][(int) $row['r']])) {
                $data['by_rating'][(int) $row['r']] = (int) $row['c'];
            }
        }
        $data['by_source'] = $db->query("SELECT source_platform AS source, COUNT(*) AS total, ROUND(AVG(NULLIF(rating,0)),2) AS avg_rating FROM reviews WHERE user_id = ? AND website_id = ? AND $date >= ? AND $date < ? GROUP BY source_platform ORDER BY total DESC", [$userId, $websiteId, $start, $end]) ?: [];

        $texts = $db->query("SELECT review_text, sentiment, rating FROM reviews WHERE user_id = ? AND website_id = ? AND $date >= ? AND $date < ? AND review_text <> '' LIMIT 500", [$userId, $websiteId, $start, $end]) ?: [];
        $ex = new ReviewTopicExtractor();
        $tax = $ex->taxonomy();
        $data['topics'] = array_map(static function ($t) use ($tax) {
            $t['label_en'] = $tax[$t['key']]['label_en'] ?? $t['label'];
            return $t;
        }, $ex->extractTopics(array_map(static fn ($r) => ['review_text' => $r['review_text'], 'sentiment' => $r['sentiment'], 'rating' => $r['rating']], $texts), 6));

        $data['needs_reply'] = $db->query("SELECT reviewer_name, rating, review_text, $date AS at FROM reviews WHERE user_id = ? AND website_id = ? AND $date >= ? AND $date < ? AND rating > 0 AND rating <= 3 AND reply_sent_at IS NULL ORDER BY rating ASC, $date DESC LIMIT 5", [$userId, $websiteId, $start, $end]) ?: [];
        $data['best'] = $db->query("SELECT reviewer_name, rating, review_text, $date AS at FROM reviews WHERE user_id = ? AND website_id = ? AND $date >= ? AND $date < ? AND rating >= 5 AND CHAR_LENGTH(review_text) >= 40 ORDER BY CHAR_LENGTH(review_text) DESC LIMIT 3", [$userId, $websiteId, $start, $end]) ?: [];
        $data['insights'] = self::insights($data);
        return $data;
    }

    private static function brand(int $userId, int $websiteId): array
    {
        $brand = ['name' => null, 'logo' => null, 'primary' => '#0f766e', 'accent' => '#f59e0b'];
        try {
            $kit = (new SocialBrandStudio())->kit($userId, $websiteId);
            $brand = ['name' => $kit['brand_name'] ?: null, 'logo' => $kit['logo_url'], 'primary' => $kit['primary'], 'accent' => $kit['accent']];
        } catch (Throwable $e) {
        }
        return $brand;
    }

    /**
     * Practical recommendations from the numbers (pure). Each item is [arabic, english].
     * @return array<int,array{0:string,1:string}>
     */
    public static function insights(array $d): array
    {
        $c = $d['current'];
        $p = $d['previous'];
        $out = [];
        if ($c['total'] === 0) {
            return [['مفيش تقييمات جديدة الشهر ده. ابعت طلبات تقييم للضيوف بعد كل رحلة وحط الـ QR في مكان ظاهر.', 'No new reviews this month. Send review requests after every trip and put the QR code where guests see it.']];
        }
        if ($c['negative'] > 0 && count($d['needs_reply'] ?? []) > 0) {
            $out[] = ['فيه ' . count($d['needs_reply']) . ' تقييم 3 نجوم أو أقل لسه من غير رد. الرد الهادي خلال 24 ساعة بيحسّن الصورة عند اللي بيقرا.', count($d['needs_reply']) . ' review(s) of 3 stars or less still have no reply. A calm reply within 24 hours improves the impression for everyone reading.'];
        }
        if ($c['response_rate'] !== null && $c['response_rate'] < 80) {
            $out[] = ['نسبة الرد ' . $c['response_rate'] . '%. فعّل الرد التلقائي على التقييمات الإيجابية وسيب وقتك للسلبية.', 'Response rate is ' . $c['response_rate'] . '%. Turn on auto-reply for positive reviews and spend your time on the negative ones.'];
        }
        if ($p['avg_rating'] !== null && $c['avg_rating'] !== null && $c['avg_rating'] < $p['avg_rating'] - 0.1) {
            $out[] = ['متوسط التقييم نزل من ' . $p['avg_rating'] . ' إلى ' . $c['avg_rating'] . '. راجع المواضيع المتكررة في التقييمات السلبية.', 'Average rating dropped from ' . $p['avg_rating'] . ' to ' . $c['avg_rating'] . '. Review the topics that repeat in the negative reviews.'];
        }
        if ($p['total'] > 0 && $c['total'] < $p['total'] * 0.7) {
            $out[] = ['عدد التقييمات قلّ عن الشهر اللي فات (' . $p['total'] . ' ← ' . $c['total'] . '). زوّد طلبات التقييم.', 'Fewer reviews than last month (' . $p['total'] . ' to ' . $c['total'] . '). Send more review requests.'];
        }
        if ($c['avg_reply_hours'] !== null && $c['avg_reply_hours'] > 48) {
            $out[] = ['متوسط وقت الرد ' . round($c['avg_reply_hours']) . ' ساعة. الهدف أقل من 24 ساعة.', 'Average reply time is ' . round($c['avg_reply_hours']) . ' hours. Aim for under 24.'];
        }
        if (!$out) {
            $out[] = ['الأداء ثابت وكويس. كمّل على نفس النظام وشارك أحسن التقييمات في السوشيال والموقع.', 'Performance is steady. Keep the same routine and share the best reviews on social media and your site.'];
        }
        return $out;
    }

    public static function render(array $d, bool $ar): string
    {
        $t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $nf = static fn ($n, int $dec = 0): string => $n === null ? '–' : number_format((float) $n, $dec);
        $c = $d['current'];
        $p = $d['previous'];
        $brand = $d['brand'];
        $primary = preg_match('/^#[0-9a-f]{6}$/i', (string) $brand['primary']) ? $brand['primary'] : '#0f766e';
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) $brand['accent']) ? $brand['accent'] : '#f59e0b';
        $delta = static function ($now, $before, bool $lowerIsBetter = false): string {
            if ($now === null || $before === null || (float) $before == 0.0) {
                return '';
            }
            $diff = round(((float) $now - (float) $before) / (float) $before * 100);
            $good = $lowerIsBetter ? $diff <= 0 : $diff >= 0;
            return '<span class="delta ' . ($good ? 'up' : 'down') . '">' . ($diff >= 0 ? '▲ +' : '▼ ') . $diff . '%</span>';
        };
        $kpi = static fn (string $label, string $value, string $sub = ''): string => '<div class="kpi"><div class="kv">' . $value . '</div><div class="kl">' . $label . '</div>' . ($sub !== '' ? '<div class="ks">' . $sub . '</div>' : '') . '</div>';
        $kpis = $kpi($t('تقييمات جديدة', 'New reviews'), $nf($c['total']), $delta($c['total'], $p['total']))
            . $kpi($t('متوسط التقييم', 'Average rating'), ($c['avg_rating'] === null ? '–' : $nf($c['avg_rating'], 2) . ' ★'), $delta($c['avg_rating'], $p['avg_rating']))
            . $kpi($t('نسبة الرد', 'Response rate'), $c['response_rate'] === null ? '–' : $c['response_rate'] . '%', $delta($c['response_rate'], $p['response_rate']))
            . $kpi($t('متوسط وقت الرد', 'Avg reply time'), $c['avg_reply_hours'] === null ? '–' : $nf($c['avg_reply_hours'], 1) . ' h', $delta($c['avg_reply_hours'], $p['avg_reply_hours'], true));

        $max = max(1, max($d['by_rating']));
        $bars = '';
        foreach ($d['by_rating'] as $stars => $n) {
            $bars .= '<div class="bar"><span class="bl">' . (int) $stars . ' ★</span><span class="bt"><i style="width:' . round($n / $max * 100) . '%"></i></span><span class="bn">' . (int) $n . '</span></div>';
        }
        $sent = '<div class="sent"><span class="s pos">' . $t('إيجابي', 'Positive') . ' ' . (int) $c['positive'] . '</span><span class="s neu">' . $t('محايد', 'Neutral') . ' ' . (int) $c['neutral'] . '</span><span class="s neg">' . $t('سلبي', 'Negative') . ' ' . (int) $c['negative'] . '</span></div>';

        $names = ['google_business' => 'Google', 'tripadvisor' => 'TripAdvisor', 'booking' => 'Booking.com', 'expedia' => 'Expedia', 'trustpilot' => 'Trustpilot', 'custom' => $t('أخرى', 'Other'), 'other' => $t('أخرى', 'Other')];
        $src = '';
        foreach ($d['by_source'] as $s) {
            $src .= '<tr><td>' . $e($names[$s['source']] ?? $s['source']) . '</td><td>' . (int) $s['total'] . '</td><td>' . $nf($s['avg_rating'], 2) . '</td></tr>';
        }
        $topics = '';
        foreach ($d['topics'] as $tp) {
            $topics .= '<tr><td>' . $e($ar ? $tp['label'] : $tp['label_en']) . '</td><td>' . (int) $tp['count'] . '</td><td class="g">' . (int) $tp['positive'] . '</td><td class="r">' . (int) $tp['negative'] . '</td></tr>';
        }
        $quote = static function (array $r) use ($e): string {
            return '<blockquote><div class="q">' . $e(mb_substr((string) $r['review_text'], 0, 280)) . '</div><div class="qa">' . $e(trim((string) $r['reviewer_name']) ?: '—') . ' · ' . str_repeat('★', max(0, min(5, (int) round((float) $r['rating'])))) . '</div></blockquote>';
        };
        $best = implode('', array_map($quote, $d['best']));
        $needs = implode('', array_map($quote, $d['needs_reply']));
        $ins = '';
        foreach ($d['insights'] as $i) {
            $ins .= '<li>' . $e($ar ? $i[0] : $i[1]) . '</li>';
        }
        $logo = $brand['logo'] ? '<img class="logo" src="' . $e($brand['logo']) . '" alt="">' : '';
        $title = $e($brand['name'] ?: $d['site']['name']);
        $dir = $ar ? 'rtl' : 'ltr';
        return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $title . ' - ' . $t('تقرير السمعة', 'Reputation report') . ' ' . $e($d['month']) . '</title><style>'
            . ':root{--p:' . $primary . ';--a:' . $accent . '}*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#111827;font-family:system-ui,-apple-system,Segoe UI,Tahoma,sans-serif;line-height:1.55}'
            . '.page{max-width:900px;margin:0 auto;background:#fff;padding:32px}header{display:flex;align-items:center;gap:16px;border-bottom:4px solid var(--p);padding-bottom:14px;margin-bottom:20px}.logo{max-height:56px;max-width:160px}'
            . 'h1{margin:0;font-size:24px;color:var(--p)}.sub{color:#6b7280;font-size:14px}h2{font-size:17px;margin:26px 0 10px;border-inline-start:5px solid var(--a);padding-inline-start:10px}'
            . '.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.kpi{border:1px solid #e5e7eb;border-radius:12px;padding:14px;text-align:center}.kv{font-size:26px;font-weight:800;color:var(--p)}.kl{font-size:12.5px;color:#6b7280}.ks{font-size:12px;margin-top:4px}'
            . '.delta.up{color:#15803d}.delta.down{color:#b91c1c}.bar{display:flex;align-items:center;gap:8px;margin:5px 0}.bl{width:36px;font-size:13px}.bt{flex:1;background:#eef2f7;height:12px;border-radius:6px;overflow:hidden}.bt i{display:block;height:100%;background:var(--a)}.bn{width:30px;font-size:13px}'
            . '.sent{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.s{padding:4px 12px;border-radius:999px;font-size:13px;font-weight:600}.pos{background:#dcfce7;color:#166534}.neu{background:#f3f4f6;color:#374151}.neg{background:#fee2e2;color:#991b1b}'
            . 'table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:7px 8px;border-bottom:1px solid #e5e7eb;text-align:start}th{color:#6b7280;font-weight:600}.g{color:#15803d}.r{color:#b91c1c}'
            . 'blockquote{margin:8px 0;padding:10px 14px;background:#f9fafb;border-inline-start:4px solid var(--p);border-radius:6px}.q{font-size:14px}.qa{font-size:12.5px;color:#6b7280;margin-top:4px}'
            . 'li{margin:6px 0}footer{margin-top:28px;color:#9ca3af;font-size:12px;text-align:center}'
            . '.actions{max-width:900px;margin:12px auto;text-align:end}.actions button{padding:8px 16px;border:0;border-radius:8px;background:var(--p);color:#fff;font-weight:700;cursor:pointer}'
            . '@media(max-width:640px){.kpis{grid-template-columns:repeat(2,1fr)}.page{padding:18px}}@media print{body{background:#fff}.actions{display:none}.page{padding:0;max-width:none}}'
            . '</style></head><body><div class="actions"><button onclick="window.print()">' . $t('طباعة / حفظ PDF', 'Print / Save as PDF') . '</button></div><main class="page">'
            . '<header>' . $logo . '<div><h1>' . $title . '</h1><div class="sub">' . $t('تقرير السمعة الشهري', 'Monthly reputation report') . ' · ' . $e($d['month']) . '</div></div></header>'
            . '<div class="kpis">' . $kpis . '</div>'
            . '<h2>' . $t('توزيع التقييمات', 'Rating distribution') . '</h2>' . $bars . $sent
            . ($src !== '' ? '<h2>' . $t('حسب المصدر', 'By source') . '</h2><table><thead><tr><th>' . $t('المصدر', 'Source') . '</th><th>' . $t('العدد', 'Reviews') . '</th><th>' . $t('المتوسط', 'Average') . '</th></tr></thead><tbody>' . $src . '</tbody></table>' : '')
            . ($topics !== '' ? '<h2>' . $t('أكتر المواضيع ذكرًا', 'Most mentioned topics') . '</h2><table><thead><tr><th>' . $t('الموضوع', 'Topic') . '</th><th>' . $t('مرات الذكر', 'Mentions') . '</th><th>' . $t('إيجابي', 'Positive') . '</th><th>' . $t('سلبي', 'Negative') . '</th></tr></thead><tbody>' . $topics . '</tbody></table>' : '')
            . ($best !== '' ? '<h2>' . $t('أحلى ما قاله الضيوف', 'What guests loved') . '</h2>' . $best : '')
            . ($needs !== '' ? '<h2>' . $t('محتاجة رد', 'Needs a reply') . '</h2>' . $needs : '')
            . '<h2>' . $t('توصيات للشهر الجاي', 'Recommendations for next month') . '</h2><ul>' . $ins . '</ul>'
            . '<footer>' . $t('الأرقام من تقييمات حقيقية تم جمعها في الفترة.', 'Figures come from real reviews collected in the period.') . '</footer></main></body></html>';
    }
}
