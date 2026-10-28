<?php

/**
 * Tourfecto - AI Report Model
 * نموذج تقرير الذكاء الاصطناعي مع نظام الكاش
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AIReport extends Model
{
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'ai_reports';

    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'website_id',
        'user_id',
        'report_type',
        'target_url',
        'competitor_urls',
        'target_language',
        'seo_keywords',
        'seo_title_suggestions',
        'seo_meta_suggestions',
        'seo_content_gaps',
        'aeo_direct_answers',
        'aeo_trust_signals',
        'aeo_positioning_strategy',
        'geo_faq_schema',
        'geo_questions_generated',
        'geo_map_integration',
        'geo_improvement_suggestions',
        'full_report_json',
        'analysis_score',
        'keywords_found',
        'competitors_analyzed',
        'is_cached',
        'cached_until',
        // من غيره التاريخ مكانش بيتحمّل من الداتابيز فصفحة التقرير تعرض "-"
        'created_at',
        'status',
        'error_message',
        'tokens_used',
        'cost_in_usd'
    ];

    /**
     * @var array $casts - تحويل البيانات
     */
    protected $casts = [
        'competitor_urls' => 'array',
        'seo_keywords' => 'array',
        'seo_title_suggestions' => 'array',
        'seo_meta_suggestions' => 'array',
        'seo_content_gaps' => 'array',
        'aeo_direct_answers' => 'array',
        'aeo_trust_signals' => 'array',
        'geo_faq_schema' => 'array',
        'geo_questions_generated' => 'array',
        'geo_map_integration' => 'array',
        'full_report_json' => 'array'
    ];

    /**
     * Constructor - معالجة تحويل JSON
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // تحويل JSON إلى مصفوفات
        foreach ($this->casts as $field => $type) {
            if (isset($this->attributes[$field]) && is_string($this->attributes[$field])) {
                $this->attributes[$field] = json_decode($this->attributes[$field], true);
            }
        }
    }

    /**
     * إنشاء تقرير جديد
     * @param array $data
     * @return AIReport|false
     */
    public static function createReport(array $data)
    {
        // تحويل المصفوفات إلى JSON
        foreach (['competitor_urls', 'full_report_json'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        // تحويل الحقول الأخرى
        $jsonFields = [
            'seo_keywords', 'seo_title_suggestions', 'seo_meta_suggestions', 'seo_content_gaps',
            'aeo_direct_answers', 'aeo_trust_signals', 'geo_faq_schema',
            'geo_questions_generated', 'geo_map_integration'
        ];

        foreach ($jsonFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        $report = new static($data);
        $id = $report->save();

        if ($id) {
            return $report->find($id);
        }

        return false;
    }

    /**
     * الحصول على التقرير الكامل كمصفوفة
     * @return array
     */
    public function getFullReport(): array
    {
        if (is_string($this->attributes['full_report_json'])) {
            return json_decode($this->attributes['full_report_json'], true);
        }
        return $this->attributes['full_report_json'] ?? [];
    }

    /**
     * الحصول على كلمات SEO الرئيسية
     * @return array
     */
    public function getSEOKeywords(): array
    {
        if (is_string($this->attributes['seo_keywords'])) {
            return json_decode($this->attributes['seo_keywords'], true);
        }
        return $this->attributes['seo_keywords'] ?? [];
    }

    /**
     * الحصول على استراتيجية AEO
     * @return array
     */
    public function getAEOStrategy(): array
    {
        return [
            'direct_answers' => $this->getAEOAnswers(),
            'trust_signals' => $this->getAEOTrustSignals(),
            'positioning' => $this->attributes['aeo_positioning_strategy'] ?? ''
        ];
    }

    /**
     * الحصول على إجابات AEO المباشرة
     * @return array
     */
    public function getAEOAnswers(): array
    {
        if (is_string($this->attributes['aeo_direct_answers'])) {
            return json_decode($this->attributes['aeo_direct_answers'], true);
        }
        return $this->attributes['aeo_direct_answers'] ?? [];
    }

    /**
     * الحصول على إشارات الثقة لـ AEO
     * @return array
     */
    public function getAEOTrustSignals(): array
    {
        if (is_string($this->attributes['aeo_trust_signals'])) {
            return json_decode($this->attributes['aeo_trust_signals'], true);
        }
        return $this->attributes['aeo_trust_signals'] ?? [];
    }

    /**
     * الحصول على استراتيجية GEO
     * @return array
     */
    public function getGEOStrategy(): array
    {
        return [
            'faq_schema' => $this->getGEOSchema(),
            'questions' => $this->getGEOQuestions(),
            'map_integration' => $this->getGEOMapIntegration(),
            'improvements' => $this->attributes['geo_improvement_suggestions'] ?? ''
        ];
    }

    /**
     * الحصول على مخطط FAQ
     * @return array
     */
    public function getGEOSchema(): array
    {
        if (is_string($this->attributes['geo_faq_schema'])) {
            return json_decode($this->attributes['geo_faq_schema'], true);
        }
        return $this->attributes['geo_faq_schema'] ?? [];
    }

    /**
     * الحصول على الأسئلة المولدة
     * @return array
     */
    public function getGEOQuestions(): array
    {
        if (is_string($this->attributes['geo_questions_generated'])) {
            return json_decode($this->attributes['geo_questions_generated'], true);
        }
        return $this->attributes['geo_questions_generated'] ?? [];
    }

    /**
     * الحصول على تكامل الخرائط
     * @return array
     */
    public function getGEOMapIntegration(): array
    {
        if (is_string($this->attributes['geo_map_integration'])) {
            return json_decode($this->attributes['geo_map_integration'], true);
        }
        return $this->attributes['geo_map_integration'] ?? [];
    }

    /**
     * الحصول على الموقع
     * @return Website|null
     */
    public function getWebsite(): ?Website
    {
        $sql = "SELECT * FROM websites WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['website_id']]);

        if (empty($result)) {
            return null;
        }

        return new Website($result[0]);
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
     * التحقق من صحة التقرير (مخبأ)
     * @return bool
     */
    public function isValidCache(): bool
    {
        if (!$this->attributes['is_cached']) {
            return false;
        }

        $cachedUntil = $this->attributes['cached_until'];
        if ($cachedUntil && strtotime($cachedUntil) < time()) {
            return false;
        }

        return true;
    }

    /**
     * تصدير التقرير بصيغة محددة
     * @param string $format
     * @return string
     */
    public function export(string $format = 'json'): string
    {
        // بنستخدم الأعمدة المنظّمة الحقيقية (نفس اللي صفحة التقرير بتعرضها
        // على الشاشة) بدل full_report_json الخام، لأنه ممكن يكون فاضي أو
        // مش بنفس شكل البيانات المعروضة فعليًا للمستخدم.
        $sections = $this->buildExportSections();

        switch ($format) {
            case 'json':
                return json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            case 'csv':
                return $this->exportCSV($sections);

            case 'html':
                return $this->exportHTML($sections);

            default:
                return json_encode($sections, JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * بناء قائمة أقسام منظّمة من التقرير (عنوان + قايمة نصوص لكل قسم)
     * جاهزة للعرض في أي صيغة تصدير.
     */
    private function buildExportSections(): array
    {
        $asList = function ($value): array {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }
            if (is_array($value)) {
                return array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v, $value);
            }
            return $value !== null && $value !== '' ? [(string) $value] : [];
        };

        $sections = [];
        $sections['summary'] = [
            'title' => 'ملخص التحليل',
            'items' => array_filter([
                'الموقع' => $this->attributes['target_url'] ?? '-',
                'اللغة' => strtoupper((string) ($this->attributes['target_language'] ?? '-')),
                'نتيجة التحليل' => ($this->attributes['analysis_score'] ?? '-') . ' / 100',
                'تاريخ التحليل' => $this->attributes['created_at'] ?? '-',
            ]),
        ];

        $competitorUrls = $asList($this->attributes['competitor_urls'] ?? []);
        if ($competitorUrls) {
            $sections['competitors'] = ['title' => 'المنافسون', 'items' => $competitorUrls];
        }

        $map = [
            'seo_keywords' => 'كلمات SEO مفتاحية',
            'seo_title_suggestions' => 'اقتراحات عناوين SEO',
            'seo_meta_suggestions' => 'اقتراحات Meta',
            'seo_content_gaps' => 'فجوات محتوى',
            'aeo_direct_answers' => 'إجابات AEO المباشرة',
            'aeo_trust_signals' => 'إشارات الثقة (AEO)',
            'geo_questions_generated' => 'أسئلة GEO مقترحة',
            'geo_improvement_suggestions' => 'تحسينات GEO',
        ];

        foreach ($map as $column => $title) {
            $items = $asList($this->attributes[$column] ?? []);
            if ($items) {
                $sections[$column] = ['title' => $title, 'items' => $items];
            }
        }

        if (!empty($this->attributes['aeo_positioning_strategy'])) {
            $sections['positioning'] = ['title' => 'استراتيجية التموضع', 'items' => [(string) $this->attributes['aeo_positioning_strategy']]];
        }

        return $sections;
    }

    /**
     * تصدير CSV - كل صف عبارة عن قسم + بند واحد منه، عشان يفتح
     * كجدول بيانات مرتّب في Excel/Google Sheets مباشرة.
     * @param array $sections
     * @return string
     */
    private function exportCSV(array $sections): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['القسم', 'البند']);

        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                fputcsv($output, [$section['title'], $item]);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * تصدير HTML بتصميم نظيف جاهز للطباعة/الحفظ كـ PDF من المتصفح.
     * @param array $sections
     * @return string
     */
    private function exportHTML(array $sections): string
    {
        $targetUrl = htmlspecialchars((string) ($this->attributes['target_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $score = htmlspecialchars((string) ($this->attributes['analysis_score'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string) ($this->attributes['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');

        $sectionsHtml = '';
        foreach ($sections as $key => $section) {
            if ($key === 'summary') {
                continue; // بيتعرض في الهيدر فوق بدل ما يتكرر جوه الجسم
            }
            $title = htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8');
            $itemsHtml = '';
            foreach ($section['items'] as $item) {
                $itemsHtml .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $sectionsHtml .= "<div class=\"section\"><h2>{$title}</h2><ul>{$itemsHtml}</ul></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تقرير تحليل - Tourfecto</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl; padding: 40px; color: #1a1a1a; max-width: 800px; margin: 0 auto; }
    .header { border-bottom: 3px solid #EFB05E; padding-bottom: 20px; margin-bottom: 30px; }
    .header h1 { margin: 0 0 8px; font-size: 24px; }
    .header .url { color: #666; direction: ltr; text-align: right; font-size: 14px; }
    .score-badge { display: inline-block; background: #EFB05E; color: #1a1a1a; font-weight: bold; padding: 6px 18px; border-radius: 20px; margin-top: 10px; }
    .section { margin-bottom: 24px; page-break-inside: avoid; }
    .section h2 { font-size: 16px; border-right: 4px solid #EFB05E; padding-right: 10px; margin-bottom: 10px; }
    .section ul { margin: 0; padding-inline-start: 24px; line-height: 1.9; }
    .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; color: #999; font-size: 11px; }
    @media print { body { padding: 15px; } }
</style>
</head>
<body>
    <div class="header">
        <h1>📊 تقرير تحليل SEO / AEO / GEO</h1>
        <div class="url">{$targetUrl}</div>
        <div class="score-badge">النتيجة: {$score} / 100</div>
    </div>
    {$sectionsHtml}
    <div class="footer">تم إنشاء هذا التقرير بواسطة Tourfecto بتاريخ {$date}</div>
</body>
</html>
HTML;
    }
}
