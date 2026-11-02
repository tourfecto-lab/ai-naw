<?php
/**
 * تخزين تغطية AEO لكل صفحة في seo_aeo_pages، ومنها درجة "تغطية الإجابات" للوحة الظهور.
 * كل الدوال بتفشل بأمان (null/false) لو الجدول لسه ما اتعملش له migration، وكلها مقيّدة بـuser_id+website_id.
 */
final class AeoCoverageStore
{
    public const STALE_DAYS = 14;

    public static function record(Database $db, int $uid, int $wid, string $url, array $readiness, int $answersFound): bool
    {
        try {
            $db->exec(
                'INSERT INTO seo_aeo_pages (website_id, user_id, url_hash, url, has_faq, has_howto, answers_found, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE url = VALUES(url), has_faq = VALUES(has_faq), has_howto = VALUES(has_howto), answers_found = VALUES(answers_found), checked_at = UTC_TIMESTAMP()',
                [$wid, $uid, sha1($url), mb_substr($url, 0, 1000), (int) !empty($readiness['has_faq_schema']), (int) !empty($readiness['has_howto_schema']), min(255, max(0, $answersFound))]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return ?array{total:int,with_faq:int,with_howto:int,last_checked:?string} null لو الجدول مش موجود */
    public static function summary(Database $db, int $uid, int $wid): ?array
    {
        try {
            $r = $db->query(
                'SELECT COUNT(*) AS total, COALESCE(SUM(has_faq),0) AS with_faq, COALESCE(SUM(has_howto),0) AS with_howto, MAX(checked_at) AS last_checked
                 FROM seo_aeo_pages WHERE website_id = ? AND user_id = ? AND checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)',
                [$wid, $uid]
            );
            return $r ? ['total' => (int) $r[0]['total'], 'with_faq' => (int) $r[0]['with_faq'], 'with_howto' => (int) $r[0]['with_howto'], 'last_checked' => $r[0]['last_checked']] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** نفس شكل VisibilityScoreService::aeo: score = نسبة صفحات الرحلات اللي عليها FAQPage. */
    public static function measurement(?array $summary, string $now): ?array
    {
        if (!$summary || $summary['total'] <= 0) {
            return null;
        }
        $t = $summary['total'];
        $stale = $summary['last_checked'] === null || (strtotime($now . ' UTC') - strtotime($summary['last_checked'] . ' UTC')) > self::STALE_DAYS * 86400;
        return ['score' => (int) round(100 * $summary['with_faq'] / $t), 'as_of' => $summary['last_checked'], 'stale' => $stale,
            'confidence' => $t >= 20 ? 'high' : ($t >= 5 ? 'medium' : 'low'), 'pages' => $t, 'pages_with_faq' => $summary['with_faq'],
            'basis' => 'scanned_trip_pages_with_faq_schema'];
    }

    /** @return string[] الروابط اللي لسه ما اتفحصتش أو الأقدم فحصًا، بحد أقصى $limit. */
    public static function nextUrls(Database $db, int $uid, int $wid, array $candidates, int $limit): array
    {
        $checked = [];
        try {
            foreach ($db->query('SELECT url_hash, checked_at FROM seo_aeo_pages WHERE website_id = ? AND user_id = ?', [$wid, $uid]) as $r) {
                $checked[$r['url_hash']] = $r['checked_at'];
            }
        } catch (Throwable $e) {
            return [];
        }
        $rank = static fn (string $u): string => $checked[sha1($u)] ?? '0000';
        usort($candidates, static fn ($a, $b) => strcmp($rank($a), $rank($b)));
        return array_slice($candidates, 0, $limit);
    }
}
