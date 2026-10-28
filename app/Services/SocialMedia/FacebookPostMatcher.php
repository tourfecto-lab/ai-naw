<?php

/**
 * Tourfecto - مطابقة منشور uncertain مع منشورات الصفحة الفعلية على فيسبوك.
 *
 * منشور uncertain = الطلب وصل لـ Meta ومعرفناش النتيجة (timeout مثلًا). ممكن يكون اتنشر فعلًا.
 * إعادة النشر العمياء = منشور مكرر؛ عدم الإعادة = منشور ضايع. الحل: نقرا آخر منشورات
 * الصفحة (GET /{page}/published_posts) ونطابق النص + الوقت، وبعدين نحسم.
 * منطق نقي (من غير DB أو شبكة) عشان يتختبر.
 * @version 1.0.0
 */
final class FacebookPostMatcher
{
    public const STATUS_MATCH = 'match';
    public const STATUS_NONE = 'none';
    public const STATUS_AMBIGUOUS = 'ambiguous';

    /**
     * @param array  $candidates منشورات الصفحة [['id','message','created_time','permalink_url']]
     * @param string $expected   النص اللي كنا بننشره (بعد دمج الهاشتاجات)
     * @param int    $windowStart unix: أول وقت ممكن يكون النشر حصل فيه
     * @param int    $windowEnd   unix: آخر وقت
     * @return array ['status'=>match|none|ambiguous,'post'=>?array,'confidence'=>?string]
     */
    public static function match(array $candidates, string $expected, int $windowStart, int $windowEnd): array
    {
        $want = self::normalize($expected);
        $hits = [];
        foreach ($candidates as $c) {
            $ts = isset($c['created_time']) ? strtotime((string) $c['created_time']) : false;
            if ($ts === false || $ts < $windowStart || $ts > $windowEnd) {
                continue;
            }
            $have = self::normalize((string) ($c['message'] ?? ''));
            if ($want === '' && $have === '') {
                $hits[] = ['post' => $c, 'confidence' => 'time_only'];
            } elseif ($want !== '' && $have === $want) {
                $hits[] = ['post' => $c, 'confidence' => 'exact'];
            } elseif ($want !== '' && $have !== '' && mb_strlen($want) >= 20 && (str_starts_with($have, mb_substr($want, 0, 60)) || str_starts_with($want, mb_substr($have, 0, 60)))) {
                $hits[] = ['post' => $c, 'confidence' => 'prefix'];
            }
        }
        if (!$hits) {
            return ['status' => self::STATUS_NONE, 'post' => null, 'confidence' => null];
        }
        // لو فيه تطابق exact واحد نعتمده حتى لو فيه prefix معاه
        $exact = array_values(array_filter($hits, fn ($h) => $h['confidence'] === 'exact'));
        $pool = $exact ?: $hits;
        if (count($pool) > 1) {
            return ['status' => self::STATUS_AMBIGUOUS, 'post' => null, 'confidence' => null, 'count' => count($pool)];
        }
        return ['status' => self::STATUS_MATCH, 'post' => $pool[0]['post'], 'confidence' => $pool[0]['confidence']];
    }

    /** توحيد النص: مسافات متتالية/أسطر جديدة/حالة أحرف - فيسبوك بيعدّل المسافات أحيانًا */
    public static function normalize(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s));
        return mb_strtolower((string) $s);
    }
}
