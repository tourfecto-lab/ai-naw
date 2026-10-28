<?php

/**
 * Tourfecto - Semantic Cache
 * نظام كاش ذكي لتخزين استجابات الـ AI
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SemanticCache
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var int $cacheDuration - مدة الكاش (بالأيام)
     */
    private $cacheDuration = 7;

    /**
     * @var string $table - اسم جدول الكاش
     */
    private $table = 'ai_reports';

    /**
     * @var float $similarityThreshold - عتبة التشابه
     */
    private $similarityThreshold = 0.85;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cacheDuration = defined('CACHE_DURATION_DAYS') ? CACHE_DURATION_DAYS : 7;
    }

    /**
     * توليد مفتاح الكاش
     * @param string $targetUrl
     * @param array $competitorUrls
     * @param string $language
     * @return string
     */
    public function generateKey(string $targetUrl, array $competitorUrls, string $language): string
    {
        // ترتيب روابط المنافسين لضمان اتساق المفتاح
        sort($competitorUrls);

        $data = [
            'target' => $targetUrl,
            'competitors' => $competitorUrls,
            'language' => $language
        ];

        return 'ai_analysis_' . md5(json_encode($data));
    }

    /**
     * تخزين النتيجة في الكاش
     * @param string $key
     * @param array $data
     * @return bool
     */
    public function set(string $key, array $data, ?int $reportId = null): bool
    {
        try {
            // التحقق من وجود سجل بنفس المفتاح
            $sql = "SELECT id FROM {$this->table} WHERE cache_key = :key LIMIT 1";
            $result = $this->db->query($sql, [':key' => $key]);

            if (!empty($result)) {
                // تحديث السجل الموجود
                $sql = "UPDATE {$this->table} 
                        SET full_report_json = :data,
                            is_cached = 1,
                            cached_until = DATE_ADD(NOW(), INTERVAL :duration DAY),
                            updated_at = NOW()
                        WHERE cache_key = :key";
            } elseif ($reportId) {
                // جدول ai_reports فيه user_id/website_id/target_url... إجباريين،
                // فالـ INSERT القديم (cache_key + json بس) كان بيفشل دايمًا بـ
                // "Field 'user_id' doesn't have a default value" والكاش مابيشتغلش.
                // الحل: نربط مفتاح الكاش بالتقرير اللي لسه متحفظ بدل صف جديد.
                $this->db->query(
                    "UPDATE {$this->table}
                        SET cache_key = :key, is_cached = 1,
                            cached_until = DATE_ADD(NOW(), INTERVAL :duration DAY)
                      WHERE id = :id",
                    [':key' => $key, ':duration' => $this->cacheDuration, ':id' => $reportId]
                );
                return true;
            } else {
                // مفيش تقرير نربطه بيه - منحاولش INSERT ناقص هيفشل
                return false;
            }

            $this->db->query($sql, [
                ':key' => $key,
                ':data' => json_encode($data),
                ':duration' => $this->cacheDuration
            ]);

            return true;

        } catch (Exception $e) {
            Logger::error('Semantic Cache Set Error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * الحصول على النتيجة من الكاش
     * @param string $key
     * @return array|null
     */
    public function get(string $key): ?array
    {
        try {
            $sql = "SELECT full_report_json, cached_until 
                    FROM {$this->table} 
                    WHERE cache_key = :key 
                    AND is_cached = 1 
                    AND cached_until > NOW()
                    ORDER BY created_at DESC 
                    LIMIT 1";

            $result = $this->db->query($sql, [':key' => $key]);

            if (empty($result)) {
                return null;
            }

            $data = json_decode($result[0]['full_report_json'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $data;

        } catch (Exception $e) {
            Logger::error('Semantic Cache Get Error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * الحصول على معرف التقرير المرتبط بمفتاح الكاش (لعرض "التقرير الكامل"
     * حتى لو النتيجة راجعة من الكاش وملهاش استدعاء AI جديد).
     * إصلاح: get() كانت بترجع بيانات التقرير بس من غير الـ id، فكانت
     * النتائج المخزنة (from_cache=true) تفضل من غير رابط لتقرير كامل.
     * @param string $key
     * @return int|null
     */
    public function getReportId(string $key): ?int
    {
        try {
            $sql = "SELECT id
                    FROM {$this->table}
                    WHERE cache_key = :key
                    AND is_cached = 1
                    AND cached_until > NOW()
                    ORDER BY created_at DESC
                    LIMIT 1";

            $result = $this->db->query($sql, [':key' => $key]);

            if (empty($result)) {
                return null;
            }

            return (int) $result[0]['id'];

        } catch (Exception $e) {
            Logger::error('Semantic Cache Get Report ID Error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * البحث عن نتيجة مشابهة في الكاش
     * @param string $targetUrl
     * @param array $competitorUrls
     * @param string $language
     * @return array|null
     */
    public function findSimilar(
        string $targetUrl,
        array $competitorUrls,
        string $language
    ): ?array {
        try {
            // الحصول على جميع السجلات النشطة في الكاش
            $sql = "SELECT id, cache_key, full_report_json, target_url, competitor_urls 
                    FROM {$this->table} 
                    WHERE is_cached = 1 
                    AND cached_until > NOW()
                    AND target_language = :language
                    ORDER BY created_at DESC 
                    LIMIT 50";

            $results = $this->db->query($sql, [':language' => $language]);

            if (empty($results)) {
                return null;
            }

            $bestMatch = null;
            $bestScore = 0;

            foreach ($results as $result) {
                // حساب درجة التشابه
                $score = $this->calculateSimilarity(
                    $targetUrl,
                    $competitorUrls,
                    $result['target_url'],
                    json_decode($result['competitor_urls'], true) ?? []
                );

                if ($score > $this->similarityThreshold && $score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = json_decode($result['full_report_json'], true);
                }
            }

            return $bestMatch;

        } catch (Exception $e) {
            Logger::error('Semantic Cache Find Similar Error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * حساب درجة التشابه بين تحليلين
     * @param string $url1
     * @param array $competitors1
     * @param string $url2
     * @param array $competitors2
     * @return float
     */
    private function calculateSimilarity(
        string $url1,
        array $competitors1,
        string $url2,
        array $competitors2
    ): float {
        // حساب تشابه النطاقات
        $domain1 = parse_url($url1, PHP_URL_HOST) ?? $url1;
        $domain2 = parse_url($url2, PHP_URL_HOST) ?? $url2;

        // حساب تشابه النطاق
        $domainSimilarity = $this->stringSimilarity($domain1, $domain2);

        // حساب تشابه قائمة المنافسين
        $competitorSimilarity = $this->arraySimilarity($competitors1, $competitors2);

        // الوزن: 30% للنطاق، 70% للمنافسين
        return ($domainSimilarity * 0.3) + ($competitorSimilarity * 0.7);
    }

    /**
     * حساب تشابه بين نصين
     * @param string $str1
     * @param string $str2
     * @return float
     */
    private function stringSimilarity(string $str1, string $str2): float
    {
        // استخدام خوارزمية Levenshtein
        $length = max(strlen($str1), strlen($str2));
        if ($length === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $length);
    }

    /**
     * حساب تشابه بين مصفوفتين
     * @param array $arr1
     * @param array $arr2
     * @return float
     */
    private function arraySimilarity(array $arr1, array $arr2): float
    {
        if (empty($arr1) && empty($arr2)) {
            return 1.0;
        }

        if (empty($arr1) || empty($arr2)) {
            return 0.0;
        }

        // استخراج النطاقات
        $domains1 = array_map(function ($url) {
            return parse_url($url, PHP_URL_HOST) ?? $url;
        }, $arr1);

        $domains2 = array_map(function ($url) {
            return parse_url($url, PHP_URL_HOST) ?? $url;
        }, $arr2);

        // حساب عدد النطاقات المشتركة
        $common = array_intersect($domains1, $domains2);
        $total = max(count($domains1), count($domains2));

        return count($common) / $total;
    }

    /**
     * حذف الكاش منتهي الصلاحية
     * @return int
     */
    public function cleanExpired(): int
    {
        try {
            $sql = "UPDATE {$this->table} 
                    SET is_cached = 0 
                    WHERE is_cached = 1 
                    AND cached_until < NOW()";

            return (int) $this->db->query($sql);

        } catch (Exception $e) {
            Logger::error('Clean Expired Cache Error', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * حذف الكاش لمستخدم معين
     * @param int $userId
     * @return int
     */
    public function clearUserCache(int $userId): int
    {
        try {
            $sql = "UPDATE {$this->table} 
                    SET is_cached = 0 
                    WHERE user_id = :user_id 
                    AND is_cached = 1";

            return (int) $this->db->query($sql, [':user_id' => $userId]);

        } catch (Exception $e) {
            Logger::error('Clear User Cache Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * الحصول على إحصائيات الكاش
     * @return array
     */
    public function getStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_cached = 1 AND cached_until > NOW() THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN is_cached = 1 AND cached_until < NOW() THEN 1 ELSE 0 END) as expired,
                        AVG(LENGTH(full_report_json)) as avg_size
                    FROM {$this->table}";

            $result = $this->db->query($sql);

            if (empty($result)) {
                return [
                    'total' => 0,
                    'active' => 0,
                    'expired' => 0,
                    'avg_size' => 0
                ];
            }

            return [
                'total' => (int) ($result[0]['total'] ?? 0),
                'active' => (int) ($result[0]['active'] ?? 0),
                'expired' => (int) ($result[0]['expired'] ?? 0),
                'avg_size' => (int) ($result[0]['avg_size'] ?? 0)
            ];

        } catch (Exception $e) {
            return [
                'total' => 0,
                'active' => 0,
                'expired' => 0,
                'avg_size' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
