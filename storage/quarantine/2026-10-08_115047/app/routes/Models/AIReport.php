<?php
/**
 * Tourfecto - AI Report Model
 * نموذج تقرير الذكاء الاصطناعي مع نظام الكاش
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AIReport extends Model {
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
    public function __construct(array $attributes = []) {
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
    public static function createReport(array $data) {
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
    public function getFullReport(): array {
        if (is_string($this->attributes['full_report_json'])) {
            return json_decode($this->attributes['full_report_json'], true);
        }
        return $this->attributes['full_report_json'] ?? [];
    }
    
    /**
     * الحصول على كلمات SEO الرئيسية
     * @return array
     */
    public function getSEOKeywords(): array {
        if (is_string($this->attributes['seo_keywords'])) {
            return json_decode($this->attributes['seo_keywords'], true);
        }
        return $this->attributes['seo_keywords'] ?? [];
    }
    
    /**
     * الحصول على استراتيجية AEO
     * @return array
     */
    public function getAEOStrategy(): array {
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
    public function getAEOAnswers(): array {
        if (is_string($this->attributes['aeo_direct_answers'])) {
            return json_decode($this->attributes['aeo_direct_answers'], true);
        }
        return $this->attributes['aeo_direct_answers'] ?? [];
    }
    
    /**
     * الحصول على إشارات الثقة لـ AEO
     * @return array
     */
    public function getAEOTrustSignals(): array {
        if (is_string($this->attributes['aeo_trust_signals'])) {
            return json_decode($this->attributes['aeo_trust_signals'], true);
        }
        return $this->attributes['aeo_trust_signals'] ?? [];
    }
    
    /**
     * الحصول على استراتيجية GEO
     * @return array
     */
    public function getGEOStrategy(): array {
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
    public function getGEOSchema(): array {
        if (is_string($this->attributes['geo_faq_schema'])) {
            return json_decode($this->attributes['geo_faq_schema'], true);
        }
        return $this->attributes['geo_faq_schema'] ?? [];
    }
    
    /**
     * الحصول على الأسئلة المولدة
     * @return array
     */
    public function getGEOQuestions(): array {
        if (is_string($this->attributes['geo_questions_generated'])) {
            return json_decode($this->attributes['geo_questions_generated'], true);
        }
        return $this->attributes['geo_questions_generated'] ?? [];
    }
    
    /**
     * الحصول على تكامل الخرائط
     * @return array
     */
    public function getGEOMapIntegration(): array {
        if (is_string($this->attributes['geo_map_integration'])) {
            return json_decode($this->attributes['geo_map_integration'], true);
        }
        return $this->attributes['geo_map_integration'] ?? [];
    }
    
    /**
     * الحصول على الموقع
     * @return Website|null
     */
    public function getWebsite(): ?Website {
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
    public function getUser(): ?User {
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
    public function isValidCache(): bool {
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
    public function export(string $format = 'json'): string {
        $data = $this->getFullReport();
        
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            case 'csv':
                return $this->exportCSV($data);
                
            case 'html':
                return $this->exportHTML($data);
                
            default:
                return json_encode($data);
        }
    }
    
    /**
     * تصدير CSV
     * @param array $data
     * @return string
     */
    private function exportCSV(array $data): string {
        $output = fopen('php://temp', 'r+');
        
        // رؤوس الأعمدة
        $headers = ['التصنيف', 'القيمة'];
        fputcsv($output, $headers);
        
        // البيانات
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($output, [$key, $value]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * تصدير HTML
     * @param array $data
     * @return string
     */
    private function exportHTML(array $data): string {
        $html = '<html><head><meta charset="UTF-8"><title>تقرير Tourfecto</title>';
        $html .= '<style>
            body { font-family: Arial, sans-serif; direction: rtl; padding: 20px; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 12px; text-align: right; }
            th { background-color: #0077be; color: white; }
            tr:nth-child(even) { background-color: #f9f9f9; }
        </style></head><body>';
        $html .= '<h1>تقرير Tourfecto</h1>';
        $html .= '<table>';
        
        foreach ($data as $key => $value) {
            $displayValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
            $html .= "<tr><td><strong>{$key}</strong></td><td>{$displayValue}</td></tr>";
        }
        
        $html .= '</table></body></html>';
        return $html;
    }
}