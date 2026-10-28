<?php

/**
 * Tourfecto - أفضل مواعيد النشر (الجدولة الذكية)
 *
 * بيحسب من نتايج العميل الفعلية (تفاعل كل منشور اتنشر) أنهي يوم/ساعة بتجيب أعلى تفاعل،
 * بتوقيت الجمهور المختار. لو البيانات لسه قليلة (أقل من MIN_MEASURED منشور متقاس) بنستخدم
 * مواعيد مرجعية لقطاع السياحة ونقول كده صراحة (source=benchmark) - مش بندّعي إنها مقاسة.
 * المنطق نقي (rank/preferencesFrom) عشان يتختبر من غير DB.
 * @version 1.0.0
 */
final class SocialBestTimes
{
    public const MIN_MEASURED = 8;

    /** مواعيد مرجعية لقطاع السياحة (محلي): الصبح في الويك إند، الضهر والمسا في أيام الشغل. 0 = الأحد */
    public const BENCHMARK = [0 => '19:30', 1 => '12:30', 2 => '19:00', 3 => '12:30', 4 => '19:30', 5 => '11:00', 6 => '10:30'];

    /**
     * @param array $samples [['at'=>'Y-m-d H:i:s' UTC,'score'=>int]]
     * @return array أفضل الخانات [['dow'=>0-6,'hour'=>0-23,'avg'=>float,'n'=>int]] مرتبة تنازلي
     */
    public static function rank(array $samples, string $timezone, int $top = 5): array
    {
        $zone = self::zone($timezone);
        $bucket = [];
        foreach ($samples as $s) {
            $ts = strtotime((string) ($s['at'] ?? '') . ' UTC');
            if ($ts === false) {
                continue;
            }
            $d = (new DateTimeImmutable('@' . $ts))->setTimezone($zone);
            $k = $d->format('w') . '-' . $d->format('G');
            $bucket[$k] = $bucket[$k] ?? ['dow' => (int) $d->format('w'), 'hour' => (int) $d->format('G'), 'sum' => 0, 'n' => 0];
            $bucket[$k]['sum'] += max(0, (int) ($s['score'] ?? 0));
            $bucket[$k]['n']++;
        }
        $rows = array_map(static fn ($b) => ['dow' => $b['dow'], 'hour' => $b['hour'], 'avg' => round($b['sum'] / max(1, $b['n']), 1), 'n' => $b['n']], array_values($bucket));
        usort($rows, static fn ($a, $b) => [$b['avg'], $b['n']] <=> [$a['avg'], $a['n']]);
        return array_slice($rows, 0, max(1, $top));
    }

    /**
     * ميعاد مقترح لكل يوم في الأسبوع ['0'=>'HH:MM', ...] + مصدره.
     * measured: أفضل ساعة لليوم ده من بيانات العميل (لو اليوم مالوش بيانات = أفضل ساعة عمومًا).
     * @return array ['times'=>[dow=>'HH:MM'],'source'=>'measured'|'benchmark','measured'=>int]
     */
    public static function preferencesFrom(array $samples, string $timezone): array
    {
        $measured = count(array_filter($samples, static fn ($s) => (int) ($s['score'] ?? 0) > 0));
        if ($measured < self::MIN_MEASURED) {
            return ['times' => self::BENCHMARK, 'source' => 'benchmark', 'measured' => $measured];
        }
        $all = self::rank($samples, $timezone, 200);
        $globalHour = $all[0]['hour'] ?? 19;
        $times = [];
        for ($dow = 0; $dow < 7; $dow++) {
            $best = null;
            foreach ($all as $r) {
                if ($r['dow'] === $dow) {
                    $best = $r;
                    break;
                }
            }
            $hour = $best ? $best['hour'] : $globalHour;
            $times[$dow] = sprintf('%02d:%02d', $hour, 0);
        }
        return ['times' => $times, 'source' => 'measured', 'measured' => $measured];
    }

    /** عينات العميل: آخر 180 يوم من المنشورات المنشورة بوزن التفاعل */
    public static function samples(int $userId, ?int $connectionId = null): array
    {
        try {
            return Database::getInstance()->query(
                "SELECT t.published_at AS at, COALESCE(pm.likes + pm.comments * 2 + pm.shares * 3, 0) AS score
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id AND pm.state = 'ok'
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)" . ($connectionId ? ' AND t.platform_connection_id = ' . (int) $connectionId : '') . '
                  ORDER BY t.published_at DESC LIMIT 500',
                [$userId]
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function preferences(int $userId, string $timezone): array
    {
        return self::preferencesFrom(self::samples($userId), $timezone)['times'];
    }

    private static function zone(string $tz): DateTimeZone
    {
        try {
            return new DateTimeZone($tz !== '' ? $tz : 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }
}
