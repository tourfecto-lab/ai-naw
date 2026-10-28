<?php

/**
 * Tourfecto - SEO A/B Testing Service
 * @version 1.0.0
 *
 * تجارب A/B على عناصر الـ SEO (title/meta description/canonical/...):
 * بنخدم نسخ مختلفة من نفس الصفحة لمحركات البحث، وبعد فترة بنقيس CTR من
 * Google Search Console ونرقّي الفائز تلقائيًا - نفس فكرة SearchPilot.
 *
 * التوزيع بيتم بشكل حتمي (deterministic) على مستوى الصفحة عشان كل روبوت
 * يشوف نسخة ثابتة (مش متغيرة بين كل طلب)، وده شرط أساسي لصحة تجربة SEO.
 */
class SeoAbTestService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** إنشاء تجربة جديدة */
    public function createTest(int $userId, int $websiteId, string $name, string $targetField, ?string $targetPath = null): array
    {
        $testId = $this->db->query(
            "INSERT INTO seo_ab_tests (website_id, user_id, name, target_field, target_path, status)
             VALUES (?, ?, ?, ?, ?, 'draft')",
            [$websiteId, $userId, $name, $targetField, $targetPath]
        );
        return ['success' => true, 'test_id' => (int) $testId];
    }

    /** إضافة نسخة (control أو variant) لتجربة */
    public function addVariant(int $testId, string $name, string $value, bool $isControl = false, int $weight = 50): array
    {
        $variantId = $this->db->query(
            "INSERT INTO seo_ab_variants (test_id, name, value, is_control, traffic_weight)
             VALUES (?, ?, ?, ?, ?)",
            [$testId, $name, $value, $isControl ? 1 : 0, $weight]
        );
        return ['success' => true, 'variant_id' => (int) $variantId];
    }

    /** بدء التجربة (نقلها من draft لـ running) */
    public function startTest(int $testId): array
    {
        $this->db->exec(
            "UPDATE seo_ab_tests SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'draft'",
            [$testId]
        );
        return ['success' => true];
    }

    /** إيقاف تجربة مع تحديد النسخة الفائزة */
    public function completeTest(int $testId, int $winnerVariantId): array
    {
        $this->db->exec(
            "UPDATE seo_ab_tests
              SET status = 'completed', winner_variant_id = ?, ended_at = NOW()
              WHERE id = ?",
            [$winnerVariantId, $testId]
        );
        return ['success' => true];
    }

    /**
     * اختيار النسخة الحتمية (deterministic) لصفحة معيّنة أثناء تجربة نشطة.
     * @return array|null بيانات النسخة المختارة أو null لو مفيش تجربة نشطة
     */
    public function pickVariant(int $websiteId, string $targetField, string $pageUrl, ?string $userAgent = null): ?array
    {
        $tests = $this->db->query(
            "SELECT id, target_path, started_at, created_at FROM seo_ab_tests
              WHERE website_id = ? AND target_field = ? AND status = 'running'
              ORDER BY id DESC LIMIT 1",
            [$websiteId, $targetField]
        );
        if (empty($tests)) {
            return null;
        }

        $test = $tests[0];
        // لو التجربة محددة على مسار معين، تحقق إن الصفحة ضمنه
        if (!empty($test['target_path']) && strpos($pageUrl, (string) $test['target_path']) === false) {
            return null;
        }

        $variants = $this->db->query(
            "SELECT id, name, value, is_control, traffic_weight FROM seo_ab_variants WHERE test_id = ? ORDER BY id ASC",
            [$test['id']]
        );
        if (count($variants) < 2) {
            return null;
        }

        // تجربة عنوان على صفحة واحدة: كل نسخة بتاخد أسبوع كامل بالتبادل (A ثم B ثم A...)
        // عشان جوجل يلحق يعرض كل عنوان ونقارن نسبة النقر (CTR) من Search Console أسبوع بأسبوع.
        // (التوزيع القديم حسب رابط الصفحة كان بيدّي نفس النسخة دايمًا فالتجربة ماكانتش بتقارن حاجة)
        $totalWeight = array_sum(array_column($variants, 'traffic_weight'));
        $start = strtotime((string) ($test['started_at'] ?: $test['created_at'])) ?: time();
        $week = intdiv(max(0, time() - $start), 7 * 86400);
        $bucket = (int) (($week % count($variants)) * max(1, $totalWeight) / count($variants));
        $acc = 0;
        $chosen = $variants[0];
        foreach ($variants as $v) {
            $acc += (int) $v['traffic_weight'];
            if ($bucket < $acc) {
                $chosen = $v;
                break;
            }
        }

        $isBot = $this->isBot((string) $userAgent);

        // سجل العرض (من غير ما نثقل الطلب - best effort)
        $this->db->exec(
            "INSERT INTO seo_ab_servings (test_id, variant_id, page_url, user_agent, is_bot)
             VALUES (?, ?, ?, ?, ?)",
            [$test['id'], $chosen['id'], $pageUrl, mb_substr((string) $userAgent, 0, 255), $isBot ? 1 : 0]
        );
        $this->db->exec("UPDATE seo_ab_variants SET served_count = served_count + 1 WHERE id = ?", [$chosen['id']]);

        return [
            'test_id' => $test['id'],
            'variant_id' => (int) $chosen['id'],
            'field' => $targetField,
            'value' => (string) $chosen['value'],
            'is_control' => (bool) $chosen['is_control'],
        ];
    }

    /** قائمة التجارب لموقع (مع نسخها) */
    public function listTests(int $websiteId): array
    {
        $tests = $this->db->query(
            "SELECT * FROM seo_ab_tests WHERE website_id = ? ORDER BY id DESC",
            [$websiteId]
        );
        foreach ($tests as &$t) {
            $t['variants'] = $this->db->query(
                "SELECT id, name, value, is_control, traffic_weight, served_count FROM seo_ab_variants WHERE test_id = ? ORDER BY id ASC",
                [$t['id']]
            );
        }
        return $tests;
    }

    /** ملخص النسخ (بدون قياس GSC) - يستخدم كـ fallback لو الموقع مش متربط */
    public function variantBreakdown(int $testId): array
    {
        return $this->db->query(
            "SELECT id, name, value, is_control, traffic_weight, served_count
               FROM seo_ab_variants WHERE test_id = ? ORDER BY id ASC",
            [$testId]
        );
    }

    /**
     * حساب مقاييس CTR الفعلية لكل نسخة من التجربة باستخدام بيانات GSC لكل صفحة.
     *
     * الفكرة: كل صفحة اتعرضت داخل التجربة (seo_ab_servings) ليها بيانات أداء
     * في GSC (clicks/impressions/ctr/position). بنجمع المقاييس دي لكل نسخة،
     * وبالتالي بنقدر نقارن "أي نسخة بتجيب CTR أعلى فعليًا" بدل الاعتماد على
     * عدد مرات العرض (served_count) اللي مش بيعكس جودة النسخة.
     *
     * @param array $pageMetrics خريطة page_path => ['clicks'=>, 'impressions'=>, 'ctr'=>, 'position'=>]
     * @return array ['variants'=> [...], 'suggested_winner_variant_id'=> int|null]
     */
    public function aggregateMetrics(int $testId, array $pageMetrics): array
    {
        $minImpressions = 100; // عتبة دنيا للثقة الإحصائية قبل اقتراح فائز

        $agg = [];
        foreach ($this->variantBreakdown($testId) as $v) {
            $agg[(int) $v['id']] = [
                'id' => (int) $v['id'],
                'name' => (string) $v['name'],
                'is_control' => (bool) $v['is_control'],
                'served_count' => (int) $v['served_count'],
                'pages' => 0,
                'clicks' => 0,
                'impressions' => 0,
                'weighted_position' => 0.0,
            ];
        }

        // الصفحات اللي اتعرضت داخل التجربة مع النسخة المختارة (حتمي لكل صفحة)
        $servings = $this->db->query(
            "SELECT page_url, variant_id FROM seo_ab_servings
              WHERE test_id = ? GROUP BY page_url, variant_id",
            [$testId]
        );

        foreach ($servings as $s) {
            $vid = (int) $s['variant_id'];
            if (!isset($agg[$vid])) {
                continue;
            }
            $path = self::normalizePagePath((string) $s['page_url']);
            $metrics = $pageMetrics[$path] ?? null;
            if ($metrics === null) {
                continue; // الصفحة مظهرتش في GSC (مفيش بيانات ليها)
            }
            $imp = (int) ($metrics['impressions'] ?? 0);
            $agg[$vid]['pages']++;
            $agg[$vid]['clicks'] += (int) ($metrics['clicks'] ?? 0);
            $agg[$vid]['impressions'] += $imp;
            $agg[$vid]['weighted_position'] += (float) ($metrics['position'] ?? 0) * $imp;
        }

        $result = [];
        foreach ($agg as $vid => $m) {
            $imp = $m['impressions'];
            $result[$vid] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'is_control' => $m['is_control'],
                'served_count' => $m['served_count'],
                'pages' => $m['pages'],
                'clicks' => $m['clicks'],
                'impressions' => $imp,
                'ctr' => $imp > 0 ? round(($m['clicks'] / $imp) * 100, 2) : 0,
                'avg_position' => $imp > 0 ? round($m['weighted_position'] / $imp, 1) : 0,
            ];
        }

        // اقتراح الفائز: أعلى CTR بشرط (1) تجاوز عتبة الظهور و(2) تفوق
        // بدلالة إحصائية (chi-squared vs control) — مش مجرد فرق رقمي عشوائي.
        $suggested = null;
        $bestCtr = -1.0;

        $control = null;
        foreach ($result as $vid => $m) {
            if ($m['is_control']) {
                $control = $m;
                break;
            }
        }

        foreach ($result as $vid => $m) {
            if ($m['impressions'] < $minImpressions || $m['is_control']) {
                continue;
            }
            if ($m['ctr'] <= $bestCtr) {
                continue;
            }

            // لو في نسخة control نقارن بيها، لازم التفوق يكون معنوي إحصائيًا
            if ($control !== null && $control['impressions'] >= $minImpressions) {
                $sig = self::chiSquare2x2($m['clicks'], $m['impressions'], $control['clicks'], $control['impressions']);
                if (!$sig['significant']) {
                    continue;
                }
            }

            $bestCtr = $m['ctr'];
            $suggested = $vid;
        }

        // لو مفيش نسخة variant غلبت الـ control بدلالة إحصائية، والـ control
        // عنده بيانات كافية (تجاوز العتبة)، يفضل الـ control كخيار افتراضي
        // (الافتراض الآمن = الإبقاء على النسخة الحالية بدل ترقية عشوائية).
        if ($suggested === null && $control !== null && $control['impressions'] >= $minImpressions) {
            $suggested = $control['id'];
        }

        return [
            'variants' => array_values($result),
            'suggested_winner_variant_id' => $suggested,
            'min_impressions_threshold' => $minImpressions,
            'significance' => [
                'method' => 'chi_squared',
                'alpha' => 0.05,
                'critical_value' => 3.841,
            ],
        ];
    }

    /**
     * اختبار chi-squared (2x2) مع تصحيح Yates لمقارنة CTR نسختين.
     * الخلايا: a=نقرات النسخة، b=غير نقراتها، c=نقرات control، d=غير نقراته.
     * @return array ['chi_square'=>float, 'significant'=>bool, 'reliable'=>bool]
     */
    private static function chiSquare2x2(int $aClicks, int $aImp, int $bClicks, int $bImp): array
    {
        $a = $aClicks;
        $b = max(0, $aImp - $aClicks);
        $c = $bClicks;
        $d = max(0, $bImp - $bClicks);

        $n = $a + $b + $c + $d;
        if ($n === 0) {
            return ['chi_square' => 0.0, 'significant' => false, 'reliable' => false];
        }

        $row1 = $a + $b;
        $row2 = $c + $d;
        $col1 = $a + $c;
        $col2 = $b + $d;

        // أي خلية متوقعة أقل من 5 => الاختبار مش موثوق تمامًا (نموذج صغير)
        $expected = [$row1 * $col1 / $n, $row1 * $col2 / $n, $row2 * $col1 / $n, $row2 * $col2 / $n];
        $reliable = min($expected) >= 5;

        if ($row1 === 0 || $row2 === 0 || $col1 === 0 || $col2 === 0) {
            return ['chi_square' => 0.0, 'significant' => false, 'reliable' => false];
        }

        // chi-square مع تصحيح الاستمرارية (Yates)
        $num = abs($a * $d - $b * $c) - ($n / 2);
        $chi = ($n * $num * $num) / ($row1 * $row2 * $col1 * $col2);

        return [
            'chi_square' => round($chi, 3),
            'significant' => $chi > 3.841, // p < 0.05 عند درجة حرية واحدة
            'reliable' => $reliable,
        ];
    }

    /**
     * توحيد صيغة الـ URL لمقارنة صفحة التجربة (ممكن تكون كاملة أو نسبية)
     * مع بُعد "page" في GSC (بيرجع URL كامل). بنحتفظ بالـ path + query فقط.
     */
    public static function normalizePagePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = ($path !== false && $path !== null && $path !== '') ? $path : '/';
        $query = parse_url($url, PHP_URL_QUERY);
        return $query !== null && $query !== false ? $path . '?' . $query : $path;
    }

    private function isBot(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }
        return (bool) preg_match('/(bot|crawler|spider|google|bing|baidu|yandex|gptbot|perplexity|claude|slurp|facebookexternalhit)/i', $ua);
    }
}
