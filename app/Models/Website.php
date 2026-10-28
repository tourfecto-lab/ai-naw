<?php

/**
 * Tourfecto - Website Model
 * نموذج الموقع الإلكتروني مع إدارة المنافسين
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Website extends Model
{
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'websites';

    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'user_id',
        // Business Control Center Phase 2: ربط website بالـBusiness اللي بيمثلها
        // (1:1 حاليًا - migration 2026_08_14_000049). نفس عمود جدول websites.
        'business_id',
        'main_url',
        'company_name',
        'industry',
        'target_language',
        'target_country',
        'meta_description',
        'competitor_1_url',
        'competitor_2_url',
        'competitor_3_url',
        'last_analysis_at',
        'is_verified',
        'latitude',
        'longitude',
        'formatted_address',
        'location_updated_at',
        // Phase 16 (Onboarding Wizard) - إضافي، الحقول القديمة فوق متلمستش
        'target_customers',
        'main_services',
        'onboarding_completed_at',
    ];

    /**
     * Constructor - تصحيح عام (بعد ما واجهنا نفس المشكلة أكتر من مرة:
     * main_url، وبعدين company_name...): بدل ما نصلح عمود واحد كل مرة
     * يظهر فيها "Unknown column"، بنكتشف كل الأعمدة الحقيقية لجدول
     * websites مرة واحدة، وأي حقل من fillable مش موجود بالظبط بنفس
     * الاسم، بنحاول نلاقيه بأسماء بديلة معروفة ونربطه تلقائيًا عن طريق
     * columnAliases (الآلية العامة في Model.php).
     */
    public function __construct(array $attributes = [])
    {
        $this->autoDetectColumnAliases();
        parent::__construct($attributes);
    }

    /** @var array|null $realColumnsCache - كاش ثابت لكل أعمدة جدول websites الحقيقية */
    private static $realColumnsCache = null;

    private static function realColumns(): array
    {
        if (self::$realColumnsCache !== null) {
            return self::$realColumnsCache;
        }

        try {
            $db = Database::getInstance();
            // Shared hosting (including Hostinger) may deny access to
            // INFORMATION_SCHEMA for the application DB user.
            // SHOW COLUMNS reads metadata from the current table directly
            // and works with the permissions normally granted to that user.
            $result = $db->query("SHOW COLUMNS FROM `websites`");
            $columnNames = [];
            foreach ((array) $result as $row) {
                $name = $row['Field'] ?? $row['COLUMN_NAME'] ?? null;
                if ($name !== null && $name !== '') {
                    $columnNames[] = strtolower((string) $name);
                }
            }
            self::$realColumnsCache = array_values(array_unique($columnNames));
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::warning('Could not list websites real columns', ['error' => $e->getMessage()]);
            }
            self::$realColumnsCache = [];
        }

        return self::$realColumnsCache;
    }

    private function autoDetectColumnAliases(): void
    {
        $realCols = self::realColumns();
        if (empty($realCols)) {
            return; // فشل الاكتشاف (مثلاً قاعدة البيانات مش متاحة) - خليه زي ما هو
        }

        // تصحيح مؤكد 100% من phpMyAdmin مباشرة (2026-07-13) - الأعمدة
        // الحقيقية لجدول websites مختلفة تمامًا عن المفترض في الكود:
        //   main_url -> domain | company_name -> brand_name
        //   industry -> industry_niche | target_country -> target_countries
        //   is_verified -> is_active
        // وده أهم حاجة: الأعمدة دي مش موجودة خالص في الجدول الحقيقي:
        //   competitor_1_url / competitor_2_url / competitor_3_url / last_analysis_at
        // (فيه جدول منفصل اسمه `competitors` غالبًا هو المكان الصح لبيانات
        // المنافسين - محتاج مراجعة منفصلة لاحقًا). بدل ما نسيبها تكسر كل
        // إضافة/تعديل موقع، بنشيلها تمامًا من أي استعلام SQL فعلي.
        $candidateMap = [
            'main_url' => ['domain', 'main_url', 'url', 'website_url', 'site_url'],
            'company_name' => ['brand_name', 'company_name', 'business_name', 'name', 'title'],
            'industry' => ['industry_niche', 'industry'],
            'target_country' => ['target_countries', 'target_country'],
            'is_verified' => ['is_active', 'is_verified'],
        ];

        // حقول مؤكد إنها مش موجودة في الجدول أصلاً - تتشال من أي INSERT/UPDATE
        $knownMissing = ['competitor_1_url', 'competitor_2_url', 'competitor_3_url', 'last_analysis_at'];

        foreach ($this->fillable as $field) {
            if (in_array(strtolower($field), $realCols, true)) {
                continue; // العمود موجود بالظبط بنفس الاسم، مفيش داعي نغيّره
            }

            if (in_array($field, $knownMissing, true)) {
                $this->unmappableFields[] = $field;
                continue;
            }

            $matched = false;
            foreach (($candidateMap[$field] ?? [$field]) as $candidate) {
                if (in_array(strtolower($candidate), $realCols, true)) {
                    $this->columnAliases[$field] = $candidate;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                // مفيش عمود مطابق خالص - نشيله من أي استعلام SQL بدل ما نسيبه يفشل
                $this->unmappableFields[] = $field;
            }
        }

        // 🐛 باگ حقيقي اتلقى في اللوج: onboarding كان بيفشل بشكل متكرر
        // بـ"SQLSTATE[23000]: Integrity constraint violation: 4025
        // CONSTRAINT `websites.target_countries` failed" - العمود الحقيقي
        // target_countries بقى JSON فعليًا (نفس نمط JSON column التاني
        // business_target_markets.target_countries)، ومعاه CHECK CONSTRAINT
        // بيرفض أي قيمة مش JSON صالح. لكن الكود لسه بيبعت target_country
        // كنص عادي (مثال: "مصر") - columnAliases غيّرت اسم العمود بس مش
        // شكل القيمة، فكانت كل محاولة onboarding بترمي خطأ integrity
        // constraint وتفشل. هنا بنضيف تحويل: لو العمود اتربط فعليًا بـ
        // target_countries، لفّ القيمة في JSON array صحيح قبل الحفظ.
        if (($this->columnAliases['target_country'] ?? null) === 'target_countries') {
            $this->columnValueTransforms['target_country'] = static function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                // لو القيمة أصلاً JSON صالح (array) متبعتاش من الكود مرتين،
                // سيبها زي ما هي بدل ما نلفّها مرتين.
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $value; // أصلًا JSON array صالح
                    }
                    return json_encode([$value], JSON_UNESCAPED_UNICODE);
                }
                if (is_array($value)) {
                    return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
                }
                return json_encode([(string) $value], JSON_UNESCAPED_UNICODE);
            };
        }
    }

    /** @var array $unmappableFields - حقول مفيش لها عمود حقيقي مطابق خالص، تتشال من أي استعلام */
    protected $unmappableFields = [];

    /**
     * Override: نشيل أي حقل معروف إنه مش موجود في الجدول الحقيقي قبل ما
     * نبني SQL، بدل ما نسيبه يسبب "Unknown column" في كل مرة.
     */
    protected function toDbAttributes(array $attrs): array
    {
        foreach ($this->unmappableFields as $field) {
            unset($attrs[$field]);
        }
        return parent::toDbAttributes($attrs);
    }

    /** @var string|null $urlColumnCache - كاش ثابت لاسم عمود رابط الموقع الحقيقي (متوافق مع كود قديم بيستخدم urlColumn() مباشرة) */
    private static $urlColumnCache = null;

    /**
     * اكتشاف اسم عمود رابط الموقع الحقيقي في قاعدة البيانات، مرة واحدة
     * ومحفوظ (cache) لباقي الطلب.
     * @return string
     */
    public static function urlColumn(): string
    {
        if (self::$urlColumnCache !== null) {
            return self::$urlColumnCache;
        }

        $candidates = ['main_url', 'url', 'website_url', 'site_url', 'domain'];
        $realCols = self::realColumns();

        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $realCols, true)) {
                self::$urlColumnCache = $c;
                return $c;
            }
        }

        self::$urlColumnCache = 'main_url';
        return 'main_url';
    }

    /**
     * الحصول على جميع المنافسين كمصفوفة
     * @return array
     */
    public function getCompetitors(): array
    {
        $competitors = [];

        for ($i = 1; $i <= 3; $i++) {
            $urlKey = "competitor_{$i}_url";
            if (!empty($this->attributes[$urlKey])) {
                $competitors[] = $this->attributes[$urlKey];
            }
        }

        return $competitors;
    }

    /**
     * تعيين المنافسين
     * @param array $urls
     * @return bool
     */
    public function setCompetitors(array $urls): bool
    {
        $maxCompetitors = 3;
        $count = 0;

        for ($i = 1; $i <= $maxCompetitors; $i++) {
            $urlKey = "competitor_{$i}_url";
            if (isset($urls[$i - 1]) && !empty($urls[$i - 1])) {
                $this->attributes[$urlKey] = $urls[$i - 1];
                $count++;
            } else {
                $this->attributes[$urlKey] = null;
            }
        }

        return $this->save() !== false;
    }

    /**
     * الحصول على المستخدم
     * @return User|null
     */
    public function getUser(): ?User
    {
        $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['user_id']]);

        if (empty($result)) {
            return null;
        }

        return new User($result[0]);
    }

    /**
     * الحصول على التقارير
     * @param int $limit
     * @return array
     */
    public function getReports(int $limit = 10): array
    {
        $sql = "SELECT * FROM ai_reports 
                WHERE website_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";

        $result = $this->db->query($sql, [
            $this->attributes['id'],
            $limit
        ]);

        return array_map(function ($data) {
            return new AIReport($data);
        }, $result);
    }

    /**
     * الحصول على آخر تقرير
     * @return AIReport|null
     */
    public function getLatestReport(): ?AIReport
    {
        $sql = "SELECT * FROM ai_reports 
                WHERE website_id = ? 
                AND status = 'completed'
                ORDER BY created_at DESC 
                LIMIT 1";

        $result = $this->db->query($sql, [$this->attributes['id']]);

        if (empty($result)) {
            return null;
        }

        return new AIReport($result[0]);
    }

    /**
     * الحصول على المراجعات
     * @param int $limit
     * @return array
     */
    public function getReviews(int $limit = 10): array
    {
        $sql = "SELECT * FROM reviews 
                WHERE website_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";

        $result = $this->db->query($sql, [
            $this->attributes['id'],
            $limit
        ]);

        return array_map(function ($data) {
            return new Review($data);
        }, $result);
    }

    /**
     * الحصول على إحصائيات المراجعات
     * @return array
     */
    public function getReviewStats(): array
    {
        $stats = [
            'total' => 0,
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
            'average_rating' => 0
        ];

        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
                    SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
                    SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative,
                    AVG(rating) as avg_rating
                FROM reviews 
                WHERE website_id = ?";

        $result = $this->db->query($sql, [$this->attributes['id']]);

        if (!empty($result)) {
            $stats = [
                'total' => (int) ($result[0]['total'] ?? 0),
                'positive' => (int) ($result[0]['positive'] ?? 0),
                'neutral' => (int) ($result[0]['neutral'] ?? 0),
                'negative' => (int) ($result[0]['negative'] ?? 0),
                'average_rating' => round((float) ($result[0]['avg_rating'] ?? 0), 2)
            ];
        }

        return $stats;
    }

    /**
     * التحقق من صحة URL
     * @param string $url
     * @return bool
     */
    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * تطهير URL
     * @param string $url
     * @return string
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        $url = rtrim($url, '/');

        // إضافة https:// إذا لم تكن موجودة
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /**
     * استخراج اسم النطاق
     * @return string
     */
    public function getDomain(): string
    {
        $url = $this->attributes['main_url'];
        $parsed = parse_url($url);
        return $parsed['host'] ?? $url;
    }

    /**
     * تحديث وقت آخر تحليل
     * @return bool
     */
    public function updateLastAnalysis(): bool
    {
        $this->attributes['last_analysis_at'] = date('Y-m-d H:i:s');
        return $this->save() !== false;
    }

    /**
     * التحقق من وجود تحليل حديث
     * @param int $days
     * @return bool
     */
    public function hasRecentAnalysis(int $days = 7): bool
    {
        if (empty($this->attributes['last_analysis_at'])) {
            return false;
        }

        $lastAnalysis = strtotime($this->attributes['last_analysis_at']);
        $now = time();
        $diff = ($now - $lastAnalysis) / (60 * 60 * 24);

        return $diff <= $days;
    }
}
