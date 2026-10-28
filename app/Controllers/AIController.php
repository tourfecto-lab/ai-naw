<?php

/**
 * Tourfecto - AI Controller
 * متحكم الذكاء الاصطناعي لتحليل SEO/AEO/GEO
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AIController extends Controller
{
    /**
     * @var TourfectoAIEngine $aiEngine - محرك الذكاء الاصطناعي
     */
    private $aiEngine;

    /**
     * @var SubscriptionValidator $subscription - مدقق الاشتراكات
     */
    private $subscription;

    /**
     * @var ArticleGenerator $articleGenerator - مولّد المقالات التسويقية
     */
    private $articleGenerator;

    /**
     * @var array $user - بيانات المستخدم الحالي (تم إضافة التعريف)
     */
    protected $user = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->aiEngine = new TourfectoAIEngine();
        $this->subscription = new SubscriptionValidator();
        $this->articleGenerator = new ArticleGenerator();

        // ✅ تعريف المتغير $user من الجلسة
        $this->loadUser();
    }

    /**
     * تحميل بيانات المستخدم الحالي
     */
    private function loadUser(): void
    {
        // محاولة الحصول على المستخدم من الجلسة
        if (isset($_SESSION['user_id'])) {
            try {
                $userModel = new User();
                $userData = $userModel->find($_SESSION['user_id']);
                if ($userData) {
                    $this->user = $userData->toArray();
                }
            } catch (Exception $e) {
                Logger::warning('Failed to load user', [
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'error' => CustomerError::message($e->getMessage(), 500)
                ]);
            }
        }

        // محاولة الحصول على المستخدم من التوكن
        if (empty($this->user) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
            if ($token) {
                try {
                    $userModel = new User();
                    $userData = $userModel->findByApiToken($token);
                    if ($userData) {
                        $this->user = $userData->toArray();
                    }
                } catch (Exception $e) {
                    Logger::warning('Failed to load user from token', [
                        'error' => CustomerError::message($e->getMessage(), 500)
                    ]);
                }
            }
        }
    }

    /**
     * التحقق من المصادقة
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        return !empty($this->user) && isset($this->user['id']);
    }

    /**
     * الحصول على معرف المستخدم
     * @return int|null
     */
    protected function getUserId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    /**
     * تحليل موقع جديد
     * POST /api/ai/analyze
     * @param array $params
     * @return array
     */
    public function analyze(array $params = []): array
    {
        try {
            // ✅ التحقق من المصادقة مع رسالة واضحة
            if (!$this->isAuthenticated()) {
                return $this->error('يجب تسجيل الدخول أولاً', 401);
            }

            // حارس معدل الطلبات: حد AI صارم لكل مستخدم (20/دقيقة مشتركة
            // بين كل نقط نهايات الذكاء الاصطناعي).
            if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
                return $rateError;
            }

            // ✅ التأكد من وجود معرف المستخدم
            $userId = $this->getUserId();
            if (!$userId) {
                return $this->error('معرف المستخدم غير موجود', 401);
            }

            // التحقق من البيانات
            $required = ['target_url', 'competitor_urls'];
            foreach ($required as $field) {
                if (!$this->has($field)) {
                    return $this->error("الحقل '{$field}' مطلوب", 400);
                }
            }

            // التحقق من عدد المنافسين
            $competitorUrls = $this->get('competitor_urls');
            if (!is_array($competitorUrls)) {
                $competitorUrls = [];
            }
            // المنافسين اختياريين (من 0 لـ 5) - كان لازم 3 بالظبط
            $competitorUrls = array_values(array_filter(array_map('trim', array_map('strval', $competitorUrls)), fn($u) => $u !== ''));
            if (count($competitorUrls) > 5) {
                return $this->error('أقصى حاجة 5 منافسين', 400);
            }

            // التحقق من صحة الروابط
            foreach (array_merge([$this->get('target_url')], $competitorUrls) as $url) {
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    return $this->error("الرابط غير صحيح: {$url}", 400);
                }
            }

            // الحصول على معرف الموقع
            $websiteId = $this->get('website_id');
            if (!$websiteId) {
                // البحث عن الموقع أو إنشاؤه
                $websiteId = $this->findOrCreateWebsite(
                    $userId,
                    $this->get('target_url'),
                    $this->get('company_name'),
                    $this->get('target_language', 'en')
                );
            }

            // تنفيذ التحليل
            $result = $this->aiEngine->analyzeWebsite(
                $userId,
                $websiteId,
                $this->get('target_url'),
                $competitorUrls,
                ($this->get('target_language','auto')==='auto' ? ContentLanguageService::forWebsite((int)$websiteId,(int)$userId) : $this->get('target_language'))
            );

            if (!$result['success']) {
                return $this->error($result['error'], $result['code'] ?? 500);
            }

            // تسجيل النشاط
            $this->log('AI Analysis', [
                'website_id' => $websiteId,
                'from_cache' => $result['from_cache'] ?? false
            ]);

            return $this->success([
                'report_id' => $result['report_id'] ?? null,
                'from_cache' => $result['from_cache'] ?? false,
                'data' => $result['data']
            ], 'تم التحليل بنجاح');

        } catch (Exception $e) {
            Logger::error('AI Analysis Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('فشل التحليل: ' . CustomerError::message($e->getMessage(), 500), 500);
        }
    }

    /**
     * الحصول على تقرير محدد
     * GET /api/ai/report/{id}
     * @param array $params
     * @return array
     */
    public function getReport(array $params): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('يجب تسجيل الدخول أولاً', 401);
            }

            $userId = $this->getUserId();
            if (!$userId) {
                return $this->error('معرف المستخدم غير موجود', 401);
            }

            $reportId = $params['id'] ?? 0;
            if (!$reportId) {
                return $this->error('معرف التقرير مطلوب', 400);
            }

            // جلب التقرير
            $report = new AIReport();
            $reportData = $report->find($reportId);

            if (!$reportData) {
                return $this->error('التقرير غير موجود', 404);
            }

            // ✅ التحقق من صلاحية المستخدم
            if ($reportData->getAttribute('user_id') != $userId) {
                return $this->error('غير مصرح بالوصول إلى هذا التقرير', 403);
            }

            // التقارير القديمة اتحفظت أقسامها فاضية لأن الذكاء الاصطناعي سمّى
            // الحقول بأسماء تانية - بنكمّلها من التقرير الكامل المحفوظ.
            require_once dirname(__DIR__) . '/Services/AI/SeoReportNormalizer.php';
            $fullData = $reportData->getFullReport();
            return $this->success([
                'report' => SeoReportNormalizer::present($reportData->toArray()),
                'full_data' => is_array($fullData) ? SeoReportNormalizer::normalize($fullData) : $fullData
            ]);

        } catch (Exception $e) {
            Logger::error('Get Report Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('فشل في جلب التقرير', 500);
        }
    }

    /**
     * الحصول على جميع تقارير المستخدم
     * GET /api/ai/reports
     * @param array $params
     * @return array
     */
    public function getReports(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('يجب تسجيل الدخول أولاً', 401);
            }

            $userId = $this->getUserId();
            if (!$userId) {
                return $this->error('معرف المستخدم غير موجود', 401);
            }

            $page = max(1, (int) ($this->get('page', 1)));
            $limit = min(100, max(1, (int) ($this->get('limit', 20))));
            $offset = ($page - 1) * $limit;

            // جلب التقارير
            $sql = "SELECT * FROM ai_reports 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";

            $reports = $this->db->query($sql, [
                $userId,
                $limit,
                $offset
            ]);

            require_once dirname(__DIR__) . '/Services/AI/SeoReportNormalizer.php';
            $reports = array_map([SeoReportNormalizer::class, 'present'], $reports);

            // جلب العدد الإجمالي
            $sqlCount = "SELECT COUNT(*) as total FROM ai_reports WHERE user_id = ?";
            $countResult = $this->db->query($sqlCount, [$userId]);
            $total = (int) ($countResult[0]['total'] ?? 0);

            return $this->success([
                'reports' => $reports,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Get Reports Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('فشل في جلب التقارير', 500);
        }
    }

    /**
     * تصدير تقرير بصيغة محددة
     * GET /api/ai/report/{id}/export
     * @param array $params
     * @return array
     */
    public function exportReport(array $params): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $userId = $this->getUserId();
        $reportId = (int) ($params['id'] ?? 0);
        $format = $this->get('format', 'csv');

        $report = (new AIReport())->find($reportId);

        if (!$report || (int) $report->getAttribute('user_id') !== (int) $userId) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(404);
            echo 'التقرير غير موجود';
            exit;
        }

        $filename = 'tourfecto-report-' . $reportId;

        if ($format === 'html' || $format === 'pdf') {
            // "PDF": بنولّد صفحة HTML بتصميم طباعة نظيف، وبتفتح تلقائيًا
            // نافذة الطباعة في المتصفح - العميل يختار "Save as PDF" من
            // ديالوج الطباعة نفسه. حل موثوق 100% ومحتاجش أي مكتبة PDF
            // على السيرفر (السيرفر ده مفيهوش SSH لتثبيت مكتبات Composer).
            $html = $report->export('html');
            $html = str_replace('</head>', '<style>@media print{.no-print{display:none}}</style><script>window.addEventListener("load",()=>setTimeout(()=>window.print(),400));</script></head>', $html);
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        // Excel: CSV بيفتح في Excel مباشرة بدون أي مكتبة، وده الصيغة
        // القياسية لأي تصدير جداول بيانات بسيط.
        $csv = $report->export('csv');
        // BOM عشان Excel يعرض العربي صح من غير ما يحتاج المستخدم يحدد الترميز يدوي
        $csv = "\xEF\xBB\xBF" . $csv;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /**
     * البحث عن موقع أو إنشاؤه
     * @param int $userId
     * @param string $url
     * @param string|null $companyName
     * @param string $language
     * @return int
     */
    private function findOrCreateWebsite(int $userId, string $url, ?string $companyName, string $language): int
    {
        try {
            $urlCol = Website::urlColumn();
            $sql = "SELECT id FROM websites WHERE user_id = ? AND {$urlCol} = ? LIMIT 1";
            $result = $this->db->query($sql, [$userId, $url]);

            if (!empty($result)) {
                return (int) $result[0]['id'];
            }

            // إنشاء موقع جديد
            $website = new Website([
                'user_id' => $userId,
                'main_url' => $url,
                'company_name' => $companyName ?? parse_url($url, PHP_URL_HOST),
                'target_language' => $language==='auto'?'en':$language,
                'is_verified' => 0
            ]);

            $id = $website->save();

            if ($id) {
                Logger::info('Website created', [
                    'user_id' => $userId,
                    'url' => $url,
                    'website_id' => $id
                ]);
            }

            return (int) $id;

        } catch (Exception $e) {
            Logger::error('Find or Create Website Error', [
                'user_id' => $userId,
                'url' => $url,
                'error' => CustomerError::message($e->getMessage(), 500)
            ]);
            throw $e;
        }
    }

    /**
     * تسجيل النشاط
     * @param string $action
     * @param array $data
     */
    protected function log(string $action, array $data = []): void
    {
        try {
            $logData = array_merge([
                'user_id' => $this->getUserId(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ], $data);

            Logger::info('AI Action: ' . $action, $logData);

        } catch (Exception $e) {
            // تجاهل أخطاء التسجيل
        }
    }

    // ============================================
    // صفحات الويب الفعلية (كانت بترجع JSON فاضي بدل صفحة حقيقية)
    // ============================================

    /** GET /ai/analyze */
    public function showAnalyze(array $params = []): array
    {
        $tNewAnalysis = $this->tr('ai.analyze.new_title');
        $tCompareSub = $this->tr('ai.analyze.compare_sub');
        $tYourUrl = $this->tr('ai.analyze.your_url');
        $tYourUrlHelp = $this->tr('ai.analyze.your_url_help');
        $tCompanyOptional = $this->tr('ai.analyze.company_optional');
        $tCompanyHelp = $this->tr('ai.analyze.company_help');
        $tAnalysisLang = $this->tr('ai.analyze.analysis_lang');
        $tLangHelp = $this->tr('ai.analyze.lang_help');
        $tCompetitorUrls = $this->tr('ai.analyze.competitor_urls');
        $tCompetitorsHelp = $this->tr('ai.analyze.competitors_help');
        $tStartAnalysis = $this->tr('ai.analyze.start');
        $tStartNote = $this->tr('ai.analyze.start_note');
        $tCreditNote = $this->tr('ai.analyze.credit_note');
        $tResultTitle = $this->tr('ai.analyze.result_title');
        $tEmptyResult = $this->tr('ai.analyze.empty_result');
        $tSeePastReports = $this->tr('ai.analyze.see_past_reports');

        $body = <<<HTML
        <style>
            .aiz-badges{display:flex;gap:8px;margin:-4px 0 16px;flex-wrap:wrap;}
            .aiz-competitor-row{display:flex;align-items:center;gap:10px;margin-bottom:4px;}
            .aiz-competitor-num{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:var(--panel-card-bg-2);border:1px solid var(--panel-border);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--panel-text-muted);}
            .aiz-competitor-row input{flex:1;}
            .aiz-field-error{display:block;color:var(--panel-danger);font-size:11.5px;margin:2px 0 10px;min-height:14px;}
            .aiz-note{font-size:11.5px;color:var(--panel-text-muted);text-align:center;margin-top:12px;line-height:1.7;}
            .aiz-loading-track{height:6px;border-radius:20px;background:rgba(255,255,255,.06);overflow:hidden;margin:16px auto 0;max-width:260px;}
            .aiz-loading-bar{height:100%;width:6%;background:linear-gradient(90deg,var(--panel-accent),#29c2ff);border-radius:20px;transition:width .6s ease;}
            .aiz-spin{animation:aiz-pulse 1.4s ease-in-out infinite;display:inline-block;}
            @keyframes aiz-pulse{0%,100%{opacity:.5;transform:scale(.92);}50%{opacity:1;transform:scale(1.08);}}
            .aiz-score-wrap{display:flex;align-items:center;gap:16px;padding:14px;background:var(--panel-card-bg-2);border-radius:14px;margin-bottom:16px;}
            .aiz-score-circle{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:21px;font-weight:800;flex-shrink:0;border:4px solid;}
            .aiz-score-circle.good{border-color:var(--panel-success);color:var(--panel-success);background:var(--panel-success-light);}
            .aiz-score-circle.mid{border-color:var(--panel-warning);color:var(--panel-warning);background:var(--panel-warning-light);}
            .aiz-score-circle.low{border-color:var(--panel-danger);color:var(--panel-danger);background:var(--panel-danger-light);}
            .aiz-score-num-label{font-size:12.5px;color:var(--panel-text-muted);font-weight:600;}
            .aiz-score-verdict{font-size:14px;font-weight:700;margin-top:3px;}
            .aiz-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px;}
            .aiz-chip{font-size:12px;font-weight:600;padding:5px 11px;border-radius:20px;background:var(--panel-card-bg-2);border:1px solid var(--panel-border);color:var(--panel-text);}
            .aiz-section{margin-top:16px;}
            .aiz-section h4{font-size:13px;margin:0 0 8px;display:flex;align-items:center;gap:6px;}
            .aiz-list{margin:0;padding-inline-start:18px;line-height:1.9;font-size:13px;color:var(--panel-text);}
            .aiz-more{font-size:11.5px;color:var(--panel-text-muted);margin-top:2px;}
            .aiz-result-actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;}
            .aiz-result-actions .btn,.aiz-result-actions .p-btn{flex:1;min-width:150px;text-align:center;}
            .form-control.is-invalid{border-color:var(--panel-danger) !important;box-shadow:0 0 0 3px var(--panel-danger-light) !important;}
        </style>
        <div class="p-grid cols-2" id="analyzeGrid">
            <div class="p-card">
                <div class="p-card-head"><h3>✨ {$tNewAnalysis}</h3><span class="p-card-sub">{$tCompareSub}</span></div>
                <div class="aiz-badges">
                    <span class="aiz-badge">SEO</span>
                    <span class="aiz-badge">AEO</span>
                    <span class="aiz-badge">GEO</span>
                </div>
                <form id="analyzeForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="target_url">🌐 {$tYourUrl} *</label>
                        <input type="url" id="target_url" class="form-control" placeholder="https://example.com" required autocomplete="off">
                        <small class="form-text">{$tYourUrlHelp}</small>
                        <small class="aiz-field-error" id="err_target_url"></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="company_name">🏢 {$tCompanyOptional}</label>
                        <input type="text" id="company_name" class="form-control" autocomplete="off">
                        <small class="form-text">{$tCompanyHelp}</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="target_language">🈯 {$tAnalysisLang}</label>
                        <select id="target_language" class="form-control">
                            <option value="ar">العربية</option>
                            <option value="auto" selected>جمهور الموقع</option>
                            <option value="en">English</option>
                        </select>
                        <small class="form-text">{$tLangHelp}</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🏁 {$tCompetitorUrls} <span class="p-cell-muted" style="font-weight:400">(اختياري - لحد 3)</span></label>
                        <small class="form-text" style="display:block;margin-bottom:10px;">{$tCompetitorsHelp}</small>
                        <div class="aiz-competitor-row">
                            <span class="aiz-competitor-num">1</span>
                            <input type="url" id="competitor_1" class="form-control" placeholder="https://competitor1.com (اختياري)" autocomplete="off">
                        </div>
                        <small class="aiz-field-error" id="err_competitor_1"></small>
                        <div class="aiz-competitor-row">
                            <span class="aiz-competitor-num">2</span>
                            <input type="url" id="competitor_2" class="form-control" placeholder="https://competitor2.com (اختياري)" autocomplete="off">
                        </div>
                        <small class="aiz-field-error" id="err_competitor_2"></small>
                        <div class="aiz-competitor-row">
                            <span class="aiz-competitor-num">3</span>
                            <input type="url" id="competitor_3" class="form-control" placeholder="https://competitor3.com (اختياري)" autocomplete="off">
                        </div>
                        <small class="aiz-field-error" id="err_competitor_3"></small>
                    </div>
                    <div id="analyzeAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="btn btn-primary btn-block" id="analyzeBtn">🚀 {$tStartAnalysis}</button>
                    <div class="aiz-note">{$tStartNote}<br>{$tCreditNote}</div>
                </form>
            </div>

            <div class="p-card" id="resultCard" style="display:none;">
                <div class="p-card-head"><h3>📊 {$tResultTitle}</h3><span class="p-card-sub" id="resultBadge"></span></div>
                <div id="resultBody"></div>
            </div>

            <div class="p-card" id="loadingResultCard" style="display:none;">
                <div class="p-empty">
                    <div class="p-empty-icon aiz-spin">⏳</div>
                    <div id="loadingStageText"></div>
                    <div class="aiz-loading-track"><div class="aiz-loading-bar" id="loadingProgressBar"></div></div>
                </div>
            </div>

            <div class="p-card" id="emptyResultCard">
                <div class="p-empty">
                    <div class="p-empty-icon">🤖</div>
                    {$tEmptyResult}<br>
                    <a href="/ai/reports" style="color:var(--panel-accent);">{$tSeePastReports}</a>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const form = document.getElementById('analyzeForm');
    const fieldIds = ['target_url', 'competitor_1', 'competitor_2', 'competitor_3'];

    function normalizeUrl(raw) {
        let v = (raw || '').trim();
        if (v && !/^https?:\/\//i.test(v)) {
            v = 'https://' + v;
        }
        return v;
    }

    function isValidUrl(v) {
        try {
            const u = new URL(v);
            return (u.protocol === 'http:' || u.protocol === 'https:') && !!u.hostname && u.hostname.indexOf('.') > -1;
        } catch (e) {
            return false;
        }
    }

    function setFieldError(id, msg) {
        const input = document.getElementById(id);
        const err = document.getElementById('err_' + id);
        if (msg) {
            input.classList.add('is-invalid');
            if (err) err.textContent = msg;
            return false;
        }
        input.classList.remove('is-invalid');
        if (err) err.textContent = '';
        return true;
    }

    function validateField(id, silent) {
        const input = document.getElementById(id);
        let v = input.value.trim();
        if (!v) {
            // المنافسين اختياريين - الرابط الأساسي بس هو المطلوب
            return (silent || id !== 'target_url') ? setFieldError(id, '') || true : setFieldError(id, __ERR_REQUIRED__);
        }
        v = normalizeUrl(v);
        input.value = v;
        if (!isValidUrl(v)) {
            return setFieldError(id, __ERR_URL_INVALID__);
        }
        const values = fieldIds.map(fid => normalizeUrl(document.getElementById(fid).value.trim().toLowerCase()));
        const idx = fieldIds.indexOf(id);
        const dup = values.some((val, i) => i !== idx && val && val === values[idx]);
        if (dup) {
            return setFieldError(id, __ERR_DUPLICATE__);
        }
        return setFieldError(id, '');
    }

    fieldIds.forEach(id => {
        const input = document.getElementById(id);
        input.addEventListener('blur', () => validateField(id, false));
        input.addEventListener('input', () => {
            if (input.classList.contains('is-invalid')) validateField(id, false);
        });
    });

    const stageMessages = [__STAGE_1__, __STAGE_2__, __STAGE_3__, __STAGE_4__];
    let stageTimer = null;

    function startLoadingAnimation() {
        document.getElementById('emptyResultCard').style.display = 'none';
        document.getElementById('resultCard').style.display = 'none';
        const loadingCard = document.getElementById('loadingResultCard');
        loadingCard.style.display = 'block';
        const textEl = document.getElementById('loadingStageText');
        const barEl = document.getElementById('loadingProgressBar');
        let step = 0;
        const widths = [15, 40, 70, 92];
        function tick() {
            textEl.textContent = stageMessages[step % stageMessages.length];
            barEl.style.width = widths[Math.min(step, widths.length - 1)] + '%';
            step++;
        }
        tick();
        stageTimer = setInterval(tick, 4200);
    }

    function stopLoadingAnimation() {
        if (stageTimer) {
            clearInterval(stageTimer);
            stageTimer = null;
        }
        document.getElementById('loadingResultCard').style.display = 'none';
    }

    function scoreClass(score) {
        if (score >= 80) return 'good';
        if (score >= 50) return 'mid';
        return 'low';
    }

    function scoreVerdict(score) {
        if (score >= 80) return __SCORE_GOOD__;
        if (score >= 50) return __SCORE_MID__;
        return __SCORE_LOW__;
    }

    function chipsHtml(items, max) {
        if (!Array.isArray(items) || !items.length) return '';
        const shown = items.slice(0, max);
        let html = '<div class="aiz-chips">' + shown.map(i => `<span class="aiz-chip">${esc(typeof i === 'object' ? (i.keyword || i.term || JSON.stringify(i)) : i)}</span>`).join('') + '</div>';
        if (items.length > max) {
            html += `<div class="aiz-more">+${items.length - max} __MORE_LABEL__</div>`;
        }
        return html;
    }

    function listHtml(title, icon, items, max) {
        if (!Array.isArray(items) || !items.length) return '';
        const shown = items.slice(0, max);
        let html = `<div class="aiz-section"><h4>${icon} ${esc(title)}</h4><ul class="aiz-list">` +
            shown.map(i => `<li>${esc(typeof i === 'object' ? JSON.stringify(i) : i)}</li>`).join('') + '</ul>';
        if (items.length > max) {
            html += `<div class="aiz-more">+${items.length - max} __MORE_LABEL__</div>`;
        }
        html += '</div>';
        return html;
    }

    function renderResult(reportId, fromCache, data) {
        data = data || {};
        const seo = data.seo || {};
        const aeo = data.aeo || {};
        const geo = data.geo || {};
        const score = (typeof data.score === 'number') ? data.score : parseInt(data.score, 10) || 0;

        document.getElementById('resultBadge').textContent = fromCache ? __BADGE_CACHED__ : __BADGE_NEW__;

        let html = `
            <div class="aiz-score-wrap">
                <div class="aiz-score-circle ${scoreClass(score)}">${esc(score)}</div>
                <div>
                    <div class="aiz-score-num-label">__SCORE_LABEL__ · ${esc(score)}/100</div>
                    <div class="aiz-score-verdict">${scoreVerdict(score)}</div>
                </div>
            </div>`;

        if (Array.isArray(seo.keywords) && seo.keywords.length) {
            html += `<div class="aiz-section"><h4>🔑 __KEYWORDS_LABEL__</h4>${chipsHtml(seo.keywords, 8)}</div>`;
        }

        html += listHtml(__TITLE_SUGGESTIONS_LABEL__, '📝', seo.title_suggestions, 3);
        html += listHtml(__CONTENT_GAPS_LABEL__, '🕳️', seo.content_gaps, 3);
        html += listHtml(__TRUST_SIGNALS_LABEL__, '🛡️', aeo.trust_signals, 3);
        if (geo.improvement_suggestions) {
            html += listHtml(__GEO_IMPROVEMENTS_LABEL__, '🗺️', [geo.improvement_suggestions], 1);
        }

        html += '<div class="aiz-result-actions">';
        if (reportId) {
            html += `<a href="/ai/report/${reportId}" class="btn btn-primary">__VIEW_FULL_REPORT__</a>`;
        } else {
            html += `<div class="p-cell-muted" style="flex:1;">__NO_DETAILS__</div>`;
        }
        html += `<button type="button" class="p-btn outline" id="newAnalysisBtn">__NEW_ANALYSIS_BTN__</button>`;
        html += '</div>';

        document.getElementById('resultBody').innerHTML = html;
        document.getElementById('resultCard').style.display = 'block';

        const newBtn = document.getElementById('newAnalysisBtn');
        if (newBtn) {
            newBtn.addEventListener('click', () => {
                document.getElementById('resultCard').style.display = 'none';
                document.getElementById('emptyResultCard').style.display = 'block';
                form.reset();
                fieldIds.forEach(id => setFieldError(id, ''));
                document.getElementById('target_url').focus();
            });
        }
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('analyzeAlert');
        alertBox.style.display = 'none';

        let allValid = true;
        fieldIds.forEach(id => {
            if (!validateField(id, false)) allValid = false;
        });
        if (!allValid) {
            alertBox.textContent = __ANALYSIS_ERROR__;
            alertBox.style.display = 'block';
            return;
        }

        const payload = {
            target_url: document.getElementById('target_url').value.trim(),
            company_name: document.getElementById('company_name').value.trim(),
            target_language: document.getElementById('target_language').value,
            competitor_urls: [
                document.getElementById('competitor_1').value.trim(),
                document.getElementById('competitor_2').value.trim(),
                document.getElementById('competitor_3').value.trim(),
            ].filter(Boolean),
        };

        const btn = document.getElementById('analyzeBtn');
        btn.disabled = true;
        startLoadingAnimation();

        try {
            const res = await fetchJSON('/api/ai/analyze', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            stopLoadingAnimation();

            if (!res.success) {
                document.getElementById('emptyResultCard').style.display = 'block';
                alertBox.textContent = res.error || __ANALYSIS_ERROR__;
                alertBox.style.display = 'block';
                return;
            }

            toast(__ANALYSIS_SUCCESS__, 'success');
            renderResult(res.data.report_id, res.data.from_cache, res.data.data);
        } catch (err) {
            stopLoadingAnimation();
            document.getElementById('emptyResultCard').style.display = 'block';
            alertBox.textContent = __CONNECTION_ERROR__;
            alertBox.style.display = 'block';
        } finally {
            btn.disabled = false;
        }
    });
})();
JS;
        $script = str_replace(
            [
                '__ERR_REQUIRED__', '__ERR_URL_INVALID__', '__ERR_DUPLICATE__',
                '__STAGE_1__', '__STAGE_2__', '__STAGE_3__', '__STAGE_4__',
                '__ANALYSIS_ERROR__', '__ANALYSIS_SUCCESS__', '__CONNECTION_ERROR__',
                '__SCORE_GOOD__', '__SCORE_MID__', '__SCORE_LOW__',
                '__BADGE_CACHED__', '__BADGE_NEW__',
                '__MORE_LABEL__', '__SCORE_LABEL__', '__KEYWORDS_LABEL__',
                '__TITLE_SUGGESTIONS_LABEL__', '__CONTENT_GAPS_LABEL__', '__TRUST_SIGNALS_LABEL__', '__GEO_IMPROVEMENTS_LABEL__',
                '__VIEW_FULL_REPORT__', '__NO_DETAILS__', '__NEW_ANALYSIS_BTN__',
            ],
            [
                $this->trJs('ai.analyze.err_required'),
                $this->trJs('ai.analyze.err_url_invalid'),
                $this->trJs('ai.analyze.err_duplicate'),
                $this->trJs('ai.analyze.stage_1'),
                $this->trJs('ai.analyze.stage_2'),
                $this->trJs('ai.analyze.stage_3'),
                $this->trJs('ai.analyze.stage_4'),
                $this->trJs('ai.analyze.error'),
                $this->trJs('ai.analyze.success'),
                $this->trJs('ai.analyze.connection_error'),
                $this->trJs('ai.analyze.score_good'),
                $this->trJs('ai.analyze.score_mid'),
                $this->trJs('ai.analyze.score_low'),
                $this->trJs('ai.analyze.badge_cached'),
                $this->trJs('ai.analyze.badge_new'),
                $this->tr('ai.analyze.more_label'),
                $this->tr('ai.report.score'),
                $this->tr('ai.report.seo_keywords'),
                $this->trJs('ai.report.seo_title_suggestions'),
                $this->trJs('ai.report.content_gaps'),
                $this->trJs('ai.report.aeo_trust_signals'),
                $this->trJs('ai.report.geo_improvements'),
                $this->tr('ai.analyze.view_full_report'),
                $this->tr('ai.analyze.no_details'),
                $this->tr('ai.analyze.new_analysis_btn'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_analyze', $this->tr('sidebar.seo_analysis'), $this->tr('ai.analyze.page_subtitle'), $body, $script);
        exit;
    }

    /** GET /ai/reports */
    public function showReports(array $params = []): array
    {
        $tReportsTitle = $this->tr('ai.reports.title');
        $tNewAnalysis = $this->tr('ai.reports.new_analysis');
        $tColSite = $this->tr('ai.reports.col.site');
        $tColStatus = $this->tr('ai.reports.col.status');
        $tColScore = $this->tr('ai.reports.col.score');
        $tColDate = $this->tr('ai.reports.col.date');
        $tLoading = $this->tr('common.loading');

        $body = <<<HTML
        <div class="p-card no-pad">
            <div class="p-card-head" style="padding:18px 20px 0;">
                <h3>{$tReportsTitle}</h3>
                <a href="/ai/analyze" class="p-btn primary xs">+ {$tNewAnalysis}</a>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="reportsTable">
                <thead><tr><th>{$tColSite}</th><th>{$tColStatus}</th><th>{$tColScore}</th><th>{$tColDate}</th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5">{$tLoading}</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    const statusLabels = {
        completed: '<span class="pill green">✔ __STATUS_COMPLETED__</span>',
        processing: '<span class="pill blue">⏳ __STATUS_PROCESSING__</span>',
        failed: '<span class="pill red">✖ __STATUS_FAILED__</span>',
        pending: '<span class="pill">⏱ __STATUS_PENDING__</span>',
    };

    window.deleteReport = async function (id) {
        if (!confirm(__DELETE_CONFIRM__)) return;
        const res = await fetchJSON('/api/ai/report/' + id, { method: 'DELETE' });
        if (res.success) { toast(__DELETED__, 'success'); load(); }
        else { toast(res.error || __DELETE_FAILED__, 'error'); }
    };

    async function load() {
        const res = await fetchJSON('/api/ai/reports?limit=100');
        const tbody = document.querySelector('#reportsTable tbody');
        if (!res.success) {
            // كان الفشل بيظهر كأن مفيش تحليلات خالص
            tbody.innerHTML = `<tr><td colspan="5" class="p-cell-muted text-center">⚠️ ${esc(res.error || 'تعذر تحميل التقارير')} - <a href="#" onclick="location.reload();return false;">حاول تاني</a></td></tr>`;
            return;
        }
        if (res.success && Array.isArray(res.data.reports) && res.data.reports.length) {
            tbody.innerHTML = res.data.reports.map(r => `
                <tr>
                    <td>${esc(r.target_url || '-')}</td>
                    <td>${r.presentation?.state === 'incomplete' ? ((document.documentElement.lang || '').startsWith('ar') ? 'غير مكتمل' : 'Incomplete') : (statusLabels[r.status] || esc(r.status || '-'))}</td>
                    <td>${(r.presentation ? r.presentation.score : r.analysis_score) !== null && (r.presentation ? r.presentation.score : r.analysis_score) !== undefined ? esc(r.presentation ? r.presentation.score : r.analysis_score) + '/100' : '—'}</td>
                    <td class="p-cell-muted">${formatDate(r.created_at)}</td>
                    <td style="white-space:nowrap;">
                        <a href="/ai/report/${r.id}" class="p-btn outline xs">__VIEW__</a>
                        <button class="p-btn danger xs" onclick="deleteReport(${r.id})">__DELETE__</button>
                    </td>
                </tr>`).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="5" class="p-cell-muted text-center">__NO_ANALYSIS_YET__ <a href="/ai/analyze">__START_FIRST__</a></td></tr>';
        }
    }
    load();
})();
JS;
        $script = str_replace(
            ['__STATUS_COMPLETED__', '__STATUS_PROCESSING__', '__STATUS_FAILED__', '__STATUS_PENDING__', '__DELETE_CONFIRM__', '__DELETED__', '__DELETE_FAILED__', '__VIEW__', '__DELETE__', '__NO_ANALYSIS_YET__', '__START_FIRST__'],
            [
                $this->tr('ai.reports.status.completed'),
                $this->tr('ai.reports.status.processing'),
                $this->tr('ai.reports.status.failed'),
                $this->tr('ai.reports.status.pending'),
                $this->trJs('ai.reports.delete_confirm'),
                $this->trJs('common.deleted'),
                $this->trJs('common.delete_failed'),
                $this->tr('common.view'),
                $this->tr('common.delete'),
                $this->tr('ai.reports.no_analysis_yet'),
                $this->tr('ai.reports.start_first'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_reports', $this->tr('sidebar.ai_reports'), $this->tr('ai.reports.page_subtitle'), $body, $script);
        exit;
    }

    /** GET /ai/report/{id} */
    public function showReport(array $params): array
    {
        $reportId = (int) ($params['id'] ?? 0);
        $tLoadingReport = $this->tr('ai.report.loading');

        $tExportExcel = $this->tr('ai.report.export_excel');
        $tExportPdf = $this->tr('ai.report.export_pdf');

        $body = <<<HTML
        <div class="p-toolbar" style="justify-content:flex-end;margin-bottom:14px;">
            <a href="/api/ai/report/{$reportId}/export?format=csv" class="p-btn outline xs">📊 {$tExportExcel}</a>
            <a href="/api/ai/report/{$reportId}/export?format=pdf" target="_blank" rel="noopener" class="p-btn outline xs">📄 {$tExportPdf}</a>
        </div>
        <div id="loadingReport" class="p-empty"><div class="p-empty-icon">⏳</div>{$tLoadingReport}</div>
        <div id="reportBody" style="display:none;"></div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, formatDate = P.formatDate;
    const reportId = __REPORT_ID__;
    const L = __LABELS_JSON__;

    function section(title, icon, items) {
        if (!items || (Array.isArray(items) && !items.length)) return '';
        const rows = Array.isArray(items)
            ? items.map(i => `<li>${typeof i === 'object' ? esc(JSON.stringify(i)) : esc(i)}</li>`).join('')
            : `<li>${esc(items)}</li>`;
        return `
            <div class="p-card" style="margin-top:14px;">
                <div class="p-card-head"><h3>${icon} ${esc(title)}</h3></div>
                <ul style="margin:0;padding-inline-start:20px;line-height:2;">${rows}</ul>
            </div>`;
    }

    async function load() {
        const res = await fetchJSON('/api/ai/report/' + reportId);
        document.getElementById('loadingReport').style.display = 'none';
        const box = document.getElementById('reportBody');
        box.style.display = 'block';

        if (!res.success) {
            box.innerHTML = `<div class="p-empty"><div class="p-empty-icon">⚠️</div>${esc(res.error || L.loadFailed)}</div>`;
            return;
        }

        const r = res.data.report || {};
        const scoreVal = r.presentation ? (r.presentation.score ?? '—') : (r.analysis_score ?? '—');

        let html = `
            <div class="p-grid cols-4">
                <div class="p-card stat-tile"><div class="stat-icon blue">🎯</div><div class="stat-info"><div class="stat-value">${esc(scoreVal)}</div><div class="stat-label">${L.score} / 100</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon green">🌐</div><div class="stat-info"><div class="stat-value" style="font-size:14px;">${esc(r.target_url || '-')}</div><div class="stat-label">${L.website}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon purple">🈯</div><div class="stat-info"><div class="stat-value">${esc((r.target_language || '-').toUpperCase())}</div><div class="stat-label">${L.language}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon orange">🗓️</div><div class="stat-info"><div class="stat-value" style="font-size:14px;">${formatDate(r.created_at)}</div><div class="stat-label">${L.analysisDate}</div></div></div>
            </div>`;

        if (Array.isArray(r.competitor_urls) && r.competitor_urls.length) {
            html += `<div class="p-card" style="margin-top:14px;"><div class="p-card-head"><h3>🏁 ${L.competitors}</h3></div>
                <ul style="margin:0;padding-inline-start:20px;line-height:2;">${r.competitor_urls.map(u => `<li>${esc(u)}</li>`).join('')}</ul></div>`;
        }

        if (r.presentation?.message) html += `<div class="p-card" role="status">${esc(r.presentation.message)}</div>`;
        const full = (res.data.full_data && typeof res.data.full_data === 'object') ? res.data.full_data : {};
        const parts = full.score_breakdown && typeof full.score_breakdown === 'object' ? full.score_breakdown : null;
        if (parts) {
            const rows = [['technical_onpage', L.partTechnical, 30], ['content_depth', L.partContent, 25], ['aeo', L.partAeo, 20], ['geo', L.partGeo, 15], ['trust', L.partTrust, 10]]
                .map(([k, label, max]) => `<tr><td>${label}</td><td style="text-align:end;font-weight:700;">${esc(parts[k] ?? '-')} / ${max}</td></tr>`).join('');
            html += `<div class="p-card" style="margin-top:14px;"><div class="p-card-head"><h3>🧮 ${L.scoreBreakdown}</h3></div><table class="p-table"><tbody>${rows}</tbody></table></div>`;
        }
        const metrics = Array.isArray(full.keyword_metrics) ? full.keyword_metrics : [];
        if (metrics.some(m => m.volume !== null || m.difficulty !== null || m.cpc !== null)) {
            const cell = v => v === null || v === undefined ? `<span class="p-cell-muted">${L.noData}</span>` : esc(v);
            const rows = metrics.map(m => `<tr><td>${esc(m.keyword)}</td><td>${cell(m.volume)}</td><td>${cell(m.difficulty)}</td><td>${m.cpc === null || m.cpc === undefined ? cell(null) : '$' + esc(m.cpc)}</td></tr>`).join('');
            html += `<div class="p-card" style="margin-top:14px;"><div class="p-card-head"><h3>🔑 ${L.seoKeywords}</h3></div><div style="overflow:auto;"><table class="p-table"><thead><tr><th>${L.keyword}</th><th>${L.keywordVolume}</th><th>${L.keywordDifficulty}</th><th>${L.keywordCpc}</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
        } else {
            html += section(L.seoKeywords, '🔑', r.seo_keywords);
        }
        html += section(L.seoTitleSuggestions, '📝', r.seo_title_suggestions);
        html += section(L.seoMetaSuggestions, '🏷️', r.seo_meta_suggestions);
        html += section(L.contentGaps, '🕳️', r.seo_content_gaps);
        html += section(L.aeoDirectAnswers, '💬', r.aeo_direct_answers);
        html += section(L.aeoTrustSignals, '🛡️', r.aeo_trust_signals);
        if (r.aeo_positioning_strategy) html += section(L.positioningStrategy, '🎯', [r.aeo_positioning_strategy]);
        html += section(L.geoQuestions, '📍', r.geo_questions_generated);
        html += section(L.geoImprovements, '🤖', r.geo_improvement_suggestions);
        const geoFull = full.geo && typeof full.geo === 'object' ? full.geo : {};
        html += section(L.localSeo, '📍', Array.isArray(geoFull.map_integration) ? geoFull.map_integration : []);
        const faq = geoFull.faq_schema;
        if (faq && typeof faq === 'object' && Array.isArray(faq.mainEntity) && faq.mainEntity.length) {
            const code = '<script type="application/ld+json">\n' + JSON.stringify(faq, null, 2) + '\n</' + 'script>';
            html += `<div class="p-card" style="margin-top:14px;"><div class="p-card-head"><h3>🧩 ${L.faqSchema}</h3><button type="button" class="p-btn outline xs" id="copyFaqSchema">${L.copy}</button></div>
                <p class="p-cell-muted" style="margin:0 0 8px;">${L.faqSchemaHint}</p><pre id="faqSchemaCode" dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:320px;overflow:auto;margin:0;">${esc(code)}</pre></div>`;
        }

        if (r.status === 'failed' && r.error_message) {
            html += `<div class="alert alert-danger" style="margin-top:14px;">${esc(r.error_message)}</div>`;
        }

        box.innerHTML = html;
        const copyBtn = document.getElementById('copyFaqSchema');
        if (copyBtn) copyBtn.onclick = () => navigator.clipboard.writeText(document.getElementById('faqSchemaCode').textContent).then(() => { copyBtn.textContent = L.copied; });
    }
    load();
})();
JS;
        $labels = [
            'loadFailed' => $this->tr('ai.report.load_failed'),
            'score' => $this->tr('ai.report.score'),
            'website' => $this->tr('ai.report.website'),
            'language' => $this->tr('ai.report.language'),
            'analysisDate' => $this->tr('ai.report.analysis_date'),
            'competitors' => $this->tr('ai.report.competitors'),
            'seoKeywords' => $this->tr('ai.report.seo_keywords'),
            'seoTitleSuggestions' => $this->tr('ai.report.seo_title_suggestions'),
            'seoMetaSuggestions' => $this->tr('ai.report.seo_meta_suggestions'),
            'contentGaps' => $this->tr('ai.report.content_gaps'),
            'aeoDirectAnswers' => $this->tr('ai.report.aeo_direct_answers'),
            'aeoTrustSignals' => $this->tr('ai.report.aeo_trust_signals'),
            'positioningStrategy' => $this->tr('ai.report.positioning_strategy'),
            'geoQuestions' => $this->tr('ai.report.geo_questions'),
            'geoImprovements' => $this->tr('ai.report.geo_improvements'),
            'scoreBreakdown' => $this->tr('ai.report.score_breakdown'),
            'partTechnical' => $this->tr('ai.report.part_technical'),
            'partContent' => $this->tr('ai.report.part_content'),
            'partAeo' => $this->tr('ai.report.part_aeo'),
            'partGeo' => $this->tr('ai.report.part_geo'),
            'partTrust' => $this->tr('ai.report.part_trust'),
            'keyword' => $this->tr('ai.report.keyword'),
            'keywordVolume' => $this->tr('ai.report.keyword_volume'),
            'keywordDifficulty' => $this->tr('ai.report.keyword_difficulty'),
            'keywordCpc' => $this->tr('ai.report.keyword_cpc'),
            'noData' => $this->tr('ai.report.no_data'),
            'localSeo' => $this->tr('ai.report.local_seo'),
            'faqSchema' => $this->tr('ai.report.faq_schema'),
            'faqSchemaHint' => $this->tr('ai.report.faq_schema_hint'),
            'copy' => $this->tr('ai.report.copy'),
            'copied' => $this->tr('ai.report.copied'),
        ];
        $script = str_replace(
            ['__REPORT_ID__', '__LABELS_JSON__'],
            [(string) $reportId, json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS)],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_reports', $this->tr('ai.report.title_prefix') . ' #' . $reportId, $this->tr('ai.report.subtitle'), $body, $script);
        exit;
    }

    /** GET /ai/competitors */
    public function showCompetitors(array $params = []): array
    {
        $tAddCompetitor = $this->tr('ai.competitors.add');
        $tLoading = $this->tr('common.loading');
        $tNewCompetitor = $this->tr('ai.competitors.new');
        $tLinkedWebsite = $this->tr('ai.competitors.linked_website');
        $tCompetitorName = $this->tr('ai.competitors.name');
        $tCompetitorUrl = $this->tr('ai.competitors.url');
        $tAdd = $this->tr('common.add');

        $body = <<<HTML
        <div class="p-toolbar">
            <button class="p-btn" onclick="document.getElementById('newCompModal').classList.add('open')">+ {$tAddCompetitor}</button>
        </div>
        <div class="p-grid cols-2" id="compGrid"><div class="p-empty">{$tLoading}</div></div>

        <div class="p-modal-overlay" id="newCompModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$tNewCompetitor}</h3><button class="p-modal-close" onclick="document.getElementById('newCompModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>{$tLinkedWebsite}</label>
                    <select id="compWebsiteId" class="p-select" style="width:100%;margin-bottom:10px;"></select>
                    <label>{$tCompetitorName}</label>
                    <input type="text" id="compName" class="p-select" style="width:100%;margin-bottom:10px;">
                    <label>{$tCompetitorUrl}</label>
                    <input type="text" id="compUrl" class="p-select" style="width:100%;" placeholder="https://...">
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="addCompetitor()">{$tAdd}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const jsonHeaders = { 'Content-Type': 'application/json' };

    async function loadWebsites() {
        const res = await fetchJSON('/api/websites');
        const sel = document.getElementById('compWebsiteId');
        if (res.success && res.data.websites) {
            sel.innerHTML = res.data.websites.map(w => `<option value="${w.id}">${esc(w.company_name || w.main_url)}</option>`).join('');
            P.syncWebsiteSelect('compWebsiteId');
        }
    }

    async function load() {
        const res = await fetchJSON('/api/ai/competitors');
        const grid = document.getElementById('compGrid');
        if (res.success && res.data.competitors && res.data.competitors.length) {
            grid.innerHTML = res.data.competitors.map(c => `
                <div class="p-card">
                    <div class="p-card-head"><h3>${esc(c.competitor_name || c.competitor_domain)}</h3><span class="p-card-sub">${esc(c.competitor_domain)}</span></div>
                    <button class="p-btn outline" onclick="analyzeCompetitor(${c.id})">__ANALYZE_AI__ ✨</button>
                    <div id="rec-${c.id}" style="margin-top:10px;">${(c.recommendations||[]).map(r=>`<div class="p-kv"><span class="v" dir="auto">✓ ${esc(r.recommendation)}</span></div>`).join('')}</div>
                    ${renderCompetitorExecution(c)}
                </div>
            `).join('');
            res.data.competitors.forEach(c=>{if(c.execution)watchCompetitorExecution(c.id,c.execution);});
        } else {
            grid.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🏁</div>__NO_COMPETITORS__</div>';
        }
    }

    window.addCompetitor = async function () {
        const website_id = document.getElementById('compWebsiteId').value;
        const name = document.getElementById('compName').value.trim();
        const url = document.getElementById('compUrl').value.trim();
        if (!website_id || !name || !url) return;
        // تصحيح: كان بينادي /api/ai/competitors (endpoint محجوز فاضي بيرجّع 501)
        // بدل /api/ai/competitors/add (الحقيقي اللي فعليًا بيضيف المنافس).
        const res = await fetchJSON('/api/ai/competitors/add', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ website_id, name, url }) });
        document.getElementById('newCompModal').classList.remove('open');
        if (res.success) { P.toast(__ADDED__, 'success'); load(); }
        else P.toast(res.error || __ADD_FAILED__, 'error');
    };

    // التحليل بيشتغل في الخلفية: بنسأل على النتيجة كل 4 ثواني (لحد 5 دقايق)
    async function waitForAnalysis(id, box) {
        const t0 = Date.now();
        while (Date.now() - t0 < 300000) {
            await new Promise(r => setTimeout(r, 4000));
            const r = await fetchJSON('/api/ai/competitors/' + id + '/analysis');
            if (!r.success) return r;
            if (r.data.status === 'done') return { success: true, data: r.data };
            if (r.data.status === 'error' || r.data.status === 'none') return { success: false, error: r.data.error };
            box.innerHTML = __ANALYZING__ + ' <span class="p-cell-muted">(' + Math.round((Date.now() - t0) / 1000) + 's)</span>';
        }
        return { success: false, error: 'التحليل أخد وقت أطول من المتوقع. جرّب تاني بعد شوية.' };
    }

    window.analyzeCompetitor = async function (id) {
        const box = document.getElementById('rec-' + id);
        box.innerHTML = __ANALYZING__;
        let res = await fetchJSON('/api/ai/competitors/' + id + '/analyze', { method: 'POST', headers: jsonHeaders, body: '{}' });
        if (res.success && res.data && res.data.pending) res = await waitForAnalysis(id, box);
        if (res.success && res.data.recommendations) {
            box.innerHTML = res.data.recommendations.map(r => `<div class="p-kv"><span class="v">✓ ${esc(r.recommendation)}</span></div>`).join('');
            if(res.data.execution){if(res.data.execution.id)watchCompetitorExecution(id,res.data.execution);else document.getElementById('comp-exec-status-'+id).textContent=res.data.execution.error||'';}
        } else {
            box.innerHTML = '<span class="p-cell-muted">' + esc(res.error || __ANALYSIS_FAILED__) + '</span>';
        }
    };

    loadWebsites();
    load();
})();
JS;
        $script = str_replace(
            ['__ANALYZE_AI__', '__NO_COMPETITORS__', '__ADDED__', '__ADD_FAILED__', '__ANALYZING__', '__ANALYSIS_FAILED__'],
            [
                $this->tr('ai.competitors.analyze_ai'),
                $this->tr('ai.competitors.none_yet'),
                $this->trJs('common.added'),
                $this->trJs('ai.competitors.add_failed'),
                $this->trJs('ai.competitors.analyzing'),
                $this->trJs('ai.competitors.analysis_failed'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        $script=file_get_contents(ROOT_PATH.'/public_html/assets/js/competitor-execution.js')."\n".$script;
        echo $this->renderPanelPage('ai_competitors', $this->tr('sidebar.ai_competitors'), $this->tr('ai.competitors.page_subtitle'), $body, $script);
        exit;
    }

    /** GET /api/ai/competitors */
    public function listCompetitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $items = (new Competitor())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC']);
        $executionService=new CompetitorSiteExecutionService();$pdo=$this->db->getConnection();$rows=[];
        foreach($items as $c){
            $row=$c->toArray();$row['content_policy']=$executionService->policy((int)$this->user['id'],(int)$row['website_id']);
            $q=$pdo->prepare('SELECT id,recommendation,priority,status FROM competitor_recommendations WHERE competitor_id=? AND website_id=? ORDER BY id DESC LIMIT 6');$q->execute([$row['id'],$row['website_id']]);$row['recommendations']=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT id FROM competitor_site_executions WHERE competitor_id=? AND user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$row['id'],$this->user['id']]);$executionId=$q->fetchColumn();
            $row['execution']=$executionId?$executionService->status((int)$this->user['id'],(int)$executionId):null;$rows[]=$row;
        }
        return $this->success(['competitors'=>$rows]);
    }

    /** POST /api/ai/competitors */
    public function createCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'name' => 'required', 'url' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        try {
            $service = new CompetitorAnalysisService();
            $competitor = $service->addCompetitor(
                (int) $this->user['id'],
                (int) $this->get('website_id'),
                (string) $this->get('name'),
                (string) $this->get('url'),
                (string) $this->get('notes', '')
            );
            return $this->success(['competitor' => $competitor->toArray()], 'تم الإضافة', 201);
        } catch (Exception $e) {
            Logger::error('createCompetitor Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الإضافة', 500);
        }
    }

    /**
     * POST /api/ai/competitors/{id}/analyze
     *
     * التحليل بيفتح موقعين ويكلّم الذكاء الاصطناعي، وده ممكن ياخد أكتر من
     * دقيقة. nginx (aaPanel) بيقفل الطلب بعد 60 ثانية ويرجّع صفحة HTML بدل
     * JSON، فالعميل كان بيشوف "استجابة غير صالحة من الخادم".
     * الحل: على PHP-FPM بنرد فورًا {pending:true} ونكمّل التحليل في الخلفية
     * (fastcgi_finish_request)، والصفحة بتسأل كل كام ثانية على
     * GET /api/ai/competitors/{id}/analysis لحد ما النتيجة تجهز.
     * لو السيرفر مش FPM بيشتغل زي الأول (في نفس الطلب).
     */
    public function analyzeCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $id = (int) ($params['id'] ?? 0);
        try {
            $competitor = (new Competitor())->find($id);
        } catch (Throwable $e) {
            $competitor = null;
        }
        if (!$competitor || (int) $competitor->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('المنافس غير موجود', 404);
        }

        // تحليل شغّال بالفعل لنفس المنافس: مانبدأش واحد تاني
        $current = $this->readCompetitorAnalysis($id);
        if (($current['status'] ?? '') === 'running' && time() - (int) ($current['started_at'] ?? 0) < 300) {
            return $this->success(['pending' => true, 'status' => 'running']);
        }

        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }

        $myUrl = $this->competitorOwnSiteUrl((int) $competitor->getAttribute('website_id'));

        if (function_exists('fastcgi_finish_request')) {
            $this->writeCompetitorAnalysis($id, ['status' => 'running', 'started_at' => time()]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // عشان طلبات المتابعة ماتستناش قفل الجلسة
            }
            ignore_user_abort(true);
            @set_time_limit(300);

            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($this->success(['pending' => true, 'status' => 'running']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            fastcgi_finish_request();

            $out = $this->runCompetitorAnalysis($competitor, $myUrl);
            $this->writeCompetitorAnalysis($id, $out['success']
                ? ['status' => 'done', 'finished_at' => time(), 'result' => $out['data']]
                : ['status' => 'error', 'finished_at' => time(), 'error' => $out['error']]);
            exit;
        }

        // من غير FPM: نفس الطلب (زي الأول)
        @set_time_limit(180);
        $out = $this->runCompetitorAnalysis($competitor, $myUrl);
        if ($out['success']) {
            $this->writeCompetitorAnalysis($id, ['status' => 'done', 'finished_at' => time(), 'result' => $out['data']]);
            return $this->success($out['data']);
        }
        // 503 مش 502: nginx/Cloudflare بيبدّلوا الـ 502 بصفحة HTML بتاعتهم
        return $this->error($out['error'], 503);
    }

    /** GET /api/ai/competitors/{id}/analysis - نتيجة آخر تحليل (للمتابعة) */
    public function competitorAnalysisStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $competitor = (new Competitor())->find($id);
        if (!$competitor || (int) $competitor->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('المنافس غير موجود', 404);
        }
        $state = $this->readCompetitorAnalysis($id);
        $status = (string) ($state['status'] ?? 'none');
        if ($status === 'running' && time() - (int) ($state['started_at'] ?? 0) > 360) {
            // العملية اتقفلت من السيرفر قبل ما تخلص
            $status = 'error';
            $state['error'] = 'التحليل وقف قبل ما يخلص. جرّب تاني.';
        }
        $data = ['status' => $status];
        if ($status === 'done') {
            $data = array_merge($data, (array) ($state['result'] ?? []));
        } elseif ($status === 'error') {
            $data['error'] = (string) ($state['error'] ?? 'فشل التحليل');
        }
        return $this->success($data);
    }

    /** التحليل نفسه - بيرجّع success + data أو error (رسالة متفلترة للعميل) */
    private function runCompetitorAnalysis($competitor, string $myUrl): array
    {
        try {
            $result = (new CompetitorAnalysisService())->analyze($competitor, $myUrl);
            $executor=new CompetitorSiteExecutionService();$policy=$executor->policy((int)$this->user['id'],(int)$competitor->getAttribute('website_id'));$execution=null;
            if($policy['competitor_auto_apply']){try{$execution=$executor->request((int)$this->user['id'],(int)$competitor->getAttribute('id'));}catch(Throwable $error){$execution=['status'=>'failed','error'=>CustomerError::message($error->getMessage(),409),'output_language'=>$policy['resolved_language']];}}
            return ['success' => true, 'data' => [
                'content_language'=>$policy['resolved_language'],'execution'=>$execution,
                'recommendations' => array_map(fn ($r) => $r->toArray(), $result['recommendations']),
                'my_score' => $result['my_score'],
                'competitor_score' => $result['competitor_score'],
                'my_summary' => $result['my_summary'],
                'competitor_summary' => $result['competitor_summary'],
            ]];
        } catch (Throwable $e) {
            Logger::error('analyzeCompetitor Error', ['message' => $e->getMessage(), 'type' => get_class($e)]);
            $msg = $e instanceof Exception ? $e->getMessage() : 'Unexpected server error';
            // نفس فلترة رسايل الخطأ اللي بتحصل في error() (إخفاء أسماء المزوّدين)
            $masked = $this->error($msg, 503);
            return ['success' => false, 'error' => (string) ($masked['error'] ?? $msg)];
        }
    }

    private function competitorOwnSiteUrl(int $websiteId): string
    {
        try {
            $website = (new Website())->find($websiteId);
        } catch (Throwable $e) {
            return '';
        }
        if (!$website) {
            return '';
        }
        $url = trim((string) $website->getAttribute('domain'));
        return $url !== '' ? $url : trim((string) $website->getAttribute('main_url'));
    }

    private function competitorAnalysisFile(int $id): string
    {
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/storage/cache/competitor_analysis';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . $id . '.json';
    }

    private function readCompetitorAnalysis(int $id): array
    {
        $raw = @file_get_contents($this->competitorAnalysisFile($id));
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    private function writeCompetitorAnalysis(int $id, array $state): void
    {
        @file_put_contents(
            $this->competitorAnalysisFile($id),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            LOCK_EX
        );
    }

    /** GET /ai/keywords */
    public function showKeywords(array $params = []): array
    {
        return (new SeoDataController())->index($params);
    }

    /** GET /api/ai/keywords?website_id= */
    public function listKeywords(array $params = []): array
    {
        return (new SeoDataController())->keywords($params);
    }

    /** POST /api/ai/keywords */
    public function createKeyword(array $params = []): array
    {
        return (new SeoDataController())->addKeyword($params);
    }

    /** DELETE /api/ai/keywords/{id} */
    public function deleteKeyword(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $keyword = (new TrackedKeyword())->find((int) ($params['id'] ?? 0));
            if (!$keyword || (int) $keyword->getAttribute('user_id') !== (int) $this->user['id']) {
                return $this->error('الكلمة غير موجودة', 404);
            }
            $keyword->delete();
            return $this->success([], 'تم حذف الكلمة');
        } catch (Exception $e) {
            Logger::error('deleteKeyword Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحذف', 500);
        }
    }

    /** POST /api/ai/keywords/bulk-add  { website_id, keywords: ["k1","k2",...] } */
    public function bulkAddKeywords(array $params = []): array
    {
        return (new SeoDataController())->bulkAddKeywords($params);
    }

    private function normalizeKeywordText(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /** Compatibility endpoint: research is measured by DataForSEO, never inferred by AI. */
    public function enrichKeywords(array $params = []): array
    {
        return (new SeoDataController())->enrich($params);
    }

    /**
     * POST /api/ai/tour-page/generate  { generated_website_id, topic, language? }
     * Phase 8 (Content Agent): توليد صفحة رحلة كاملة (Draft - مش متحفوظة لسه)
     * لموقع Website Builder بتاع نفس المستخدم. الناتج بيترجع للواجهة عشان
     * العميل يراجعه، وبعدين يستخدم applyTourPage() لو عايز يضيفه فعليًا.
     */
    public function generateTourPageDraft(array $params = []): array
    {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $generatedWebsiteId = (int) $this->get('generated_website_id');
        $topic = trim((string) $this->get('topic', ''));
        if (!$generatedWebsiteId || $topic === '') {
            return $this->error('بيانات ناقصة', 422);
        }

        if (!class_exists('GeneratedWebsite')) {
            return $this->error('الخدمة غير متاحة', 500);
        }
        $site = (new GeneratedWebsite())->find($generatedWebsiteId);
        if (!$site || (int) $site->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        $creditsCheck = $this->subscription->reserveAICredits((int) $this->user['id'], 1);
        if (!$creditsCheck['available']) {
            return $this->error($creditsCheck['message'] ?? 'رصيد الذكاء الاصطناعي غير كافٍ', 403);
        }

        $language = (string) $this->get('language', ($site->getContent()['language'] ?? ContentLanguageService::forUser((int)$this->user['id'])));
        $content = $site->getContent();
        $companyName = $content['business_name'] ?? null;
        $existingSlugs = array_map(fn ($t) => $t['slug'] ?? '', $content['tours'] ?? []);

        $result = $this->articleGenerator->generateTourPage($topic, $language, $companyName, $existingSlugs);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر توليد صفحة الرحلة', 502);
        }

        if (!$this->subscription->consumeAICredits((int) $this->user['id'], 1, $creditsCheck['source'] === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');
        $this->log('AI Tour Page Draft Generated', ['generated_website_id' => $generatedWebsiteId, 'topic' => $topic]);

        return $this->success(['tour' => $result['data']], 'تم توليد مسودة صفحة الرحلة');
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * POST /api/ai/tour-page/apply  { generated_website_id, tour: {...} }
     * Phase 8 + Auto-Apply (زي Phase 5 بالظبط): بيضيف صفحة الرحلة فعليًا
     * لموقع Website Builder - عندنا صلاحية كتابة حقيقية عليه (عكس أي موقع
     * خارجي). بيتحقق من الحقول الأساسية المطلوبة قبل الإضافة.
     */
    public function applyTourPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $generatedWebsiteId = (int) $this->get('generated_website_id');
        $tour = $this->get('tour');
        if (!$generatedWebsiteId || !is_array($tour) || empty($tour['name']) || empty($tour['slug'])) {
            return $this->error('بيانات الرحلة ناقصة', 422);
        }

        if (!class_exists('GeneratedWebsite')) {
            return $this->error('الخدمة غير متاحة', 500);
        }
        $site = (new GeneratedWebsite())->find($generatedWebsiteId);
        if (!$site || (int) $site->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        $content = $site->getContent();
        $content['tours'] = $content['tours'] ?? [];

        // منع تكرار نفس الـslug (مثلاً لو العميل ضغط "طبّق" مرتين بالغلط)
        foreach ($content['tours'] as $existing) {
            if (($existing['slug'] ?? '') === $tour['slug']) {
                return $this->error('فيه رحلة بنفس الـslug ده موجودة بالفعل', 422);
            }
        }

        $content['tours'][] = [
            'name' => (string) $tour['name'],
            'slug' => (string) $tour['slug'],
            'short_description' => (string) ($tour['short_description'] ?? ''),
            'price' => (string) ($tour['price'] ?? ''),
            'duration' => (string) ($tour['duration'] ?? ''),
            'group_size' => (string) ($tour['group_size'] ?? ''),
            'image_url' => (string) ($tour['image_url'] ?? ''),
            'highlights' => is_array($tour['highlights'] ?? null) ? array_values($tour['highlights']) : [],
            'includes' => is_array($tour['includes'] ?? null) ? array_values($tour['includes']) : [],
            'excludes' => is_array($tour['excludes'] ?? null) ? array_values($tour['excludes']) : [],
            'itinerary' => is_array($tour['itinerary'] ?? null) ? array_values($tour['itinerary']) : [],
        ];

        $site->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $site->save();

        $this->log('AI Tour Page Applied', ['generated_website_id' => $generatedWebsiteId, 'slug' => $tour['slug']]);

        return $this->success(['slug' => $tour['slug']], 'تمت إضافة صفحة الرحلة للموقع');
    }

    /** Discover real demand for the selected market; AI credits are not used. */
    public function discoverKeywords(array $params = []): array
    {
        return (new SeoDataController())->discover($params);
    }

    private function normalizeUrlForAnalysis(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * POST /api/ai/keywords/sync-gsc  { website_id }
     * مزامنة حقيقية للترتيب (position) وحجم الظهور (impressions) لكل
     * كلمة متابَعة من Google Search Console الفعلي - مش تقدير ولا رقم
     * ملفّق. لازم الموقع يكون متربط بـ Search Console أولاً من صفحة
     * "الربط والتكاملات". نفس منطق SearchConsoleController::stats بالظبط
     * (تجديد التوكن لو قرب ينتهي) عشان نفس السلوك في كل الصفحة.
     */
    public function syncSearchConsoleKeywords(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('يجب تسجيل الدخول أولاً', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('اختر الموقع الأول', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        SeoDataSchema::ensureSchema($this->db);

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
            'status' => 'connected',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('الموقع ده لسه مش متربط بـ Google Search Console - اربطه الأول من صفحة الربط والتكاملات', 404);
        }

        $connection = $connections[0];
        $encryption = new Encryption();

        try {
            $accessToken = $encryption->decrypt($connection->getAttribute('access_token'));

            if ((new PlatformConnection($connection->toArray()))->isTokenExpired() && $connection->getAttribute('refresh_token')) {
                $refreshToken = $encryption->decrypt($connection->getAttribute('refresh_token'));
                $redirectUri = defined('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI')
                    ? GOOGLE_SEARCH_CONSOLE_REDIRECT_URI
                    : (getenv('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI') ?: '');
                $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_SEARCH_CONSOLE, $redirectUri ?: null);
                $refreshed = $oauth->refreshAccessToken($refreshToken);
                if ($refreshed['success']) {
                    $accessToken = $refreshed['access_token'];
                    $connection->setAttribute('access_token', $encryption->encrypt($accessToken));
                    $connection->setAttribute('token_expires_at', date('Y-m-d H:i:s', time() + (int) $refreshed['expires_in']));
                    $connection->save();
                }
            }

            $api = new GoogleSearchConsoleAPI($accessToken);
            $siteUrl = (string) $connection->getAttribute('external_location_id');

            $endDate = date('Y-m-d', strtotime('-2 days'));
            $startDate = date('Y-m-d', strtotime('-28 days', strtotime($endDate)));
            $result = $api->getSearchAnalytics($siteUrl, $startDate, $endDate, ['query'], 250);

            if (!$result['success']) {
                return $this->error('تعذر جلب بيانات Search Console: ' . ($result['error'] ?? ''), 502);
            }

            $tracked = (new TrackedKeyword())->where(['user_id' => $this->user['id'], 'website_id' => $websiteId]);
            $trackedByNorm = [];
            foreach ($tracked as $k) {
                $trackedByNorm[$this->normalizeKeywordText((string) $k->getAttribute('keyword'))][] = $k;
            }

            $updated = 0;
            $opportunities = [];
            foreach ($result['rows'] as $row) {
                $query = trim((string) ($row['query'] ?? ''));
                if ($query === '') {
                    continue;
                }
                $norm = $this->normalizeKeywordText($query);

                if (isset($trackedByNorm[$norm])) {
                    foreach ($trackedByNorm[$norm] as $k) {
                        $k->setAttribute('gsc_position', (float) $row['position']);
                        $k->setAttribute('impressions', (int) $row['impressions']);
                        $k->setAttribute('gsc_checked_at', gmdate('Y-m-d H:i:s'));
                        $k->save();
                        $updated++;
                    }
                } else {
                    $opportunities[] = [
                        'keyword' => $query,
                        'position' => $row['position'],
                        'impressions' => $row['impressions'],
                        'clicks' => $row['clicks'],
                    ];
                }
            }

            // أعلى الفرص فقط (أكتر ظهور في نتائج البحث) عشان الواجهة متتغرقش
            usort($opportunities, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
            $opportunities = array_slice($opportunities, 0, 15);

            $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
            $connection->save();

            $this->log('Search Console Keyword Sync', ['website_id' => $websiteId, 'updated' => $updated]);

            return $this->success([
                'updated_count' => $updated,
                'opportunities' => $opportunities,
                'site_url' => $siteUrl,
            ], "اتحدّث ظهور ومتوسط ترتيب GSC لـ {$updated} كلمة؛ ده مش حجم البحث الشهري");
        } catch (Exception $e) {
            Logger::error('Sync Search Console Keywords Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('تعذر المزامنة', 500);
        }
    }


    /** DELETE /api/ai/report/{id} */
    public function deleteReport(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "DELETE FROM ai_reports WHERE id = ? AND user_id = ?";
            $this->db->exec($sql, [(int) ($params['id'] ?? 0), $this->getUserId()]);
            return $this->success([], 'تم حذف التقرير');
        } catch (Exception $e) {
            Logger::error('Delete Report Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حذف التقرير', 500);
        }
    }

    /**
     * الدوال التالية تتطلب تكاملاً فعليًا مع Gemini API لتحليل حقيقي.
     * الإعدادات موجودة في app/Config/gemini.php و.env (GEMINI_API_KEY) لكن لا يوجد
     * كود فعلي هنا ينفذ هذه التحليلات المحددة، لذا نُعيد استجابة صريحة بدل تلفيق نتائج.
     */
    public function analyzeKeywords(array $params = []): array
    {
        return (new SeoDataController())->discover($params);
    }

    public function analyzeCompetitors(array $params = []): array
    {
        return $this->error('تحليل المنافسين المخصص غير مفعّل بعد (استخدم /api/ai/analyze العام)', 501);
    }

    public function analyzeSentiment(array $params = []): array
    {
        return $this->error('تحليل المشاعر غير مفعّل بعد في هذه النسخة', 501);
    }

    public function translate(array $params = []): array
    {
        return $this->error('خدمة الترجمة غير مفعّلة بعد في هذه النسخة', 501);
    }

    // ============================================
    // مولّد المقالات التسويقية (Article Generator)
    // ============================================
    // ملاحظة: بما إن أغلب مواقع العملاء مبنية بشكل مخصوص (مش WordPress
    // موحّد)، مفيش نشر تلقائي مباشر على موقع العميل. المقال بيتولّد
    // جاهز، والعميل يحمّله/ينسخه وينشره بنفسه أو مع مطوّر موقعه.

    /** GET /ai/articles */
    public function showArticles(array $params = []): array
    {
        $tGenerateNew = $this->tr('ai.articles.generate_new');
        $tReadySeconds = $this->tr('ai.articles.ready_seconds');
        $tTopicLabel = $this->tr('ai.articles.topic_label');
        $tTopicPlaceholder = $this->tr('ai.articles.topic_placeholder');
        $tLanguage = $this->tr('ai.articles.language');
        $tTone = $this->tr('ai.articles.tone');
        $tToneProfessional = $this->tr('ai.articles.tone.professional');
        $tToneFriendly = $this->tr('ai.articles.tone.friendly');
        $tToneLuxury = $this->tr('ai.articles.tone.luxury');
        $tToneAdventurous = $this->tr('ai.articles.tone.adventurous');
        $tGenerateBtn = $this->tr('ai.articles.generate_btn');
        $tPreviousArticles = $this->tr('ai.articles.previous');
        $tColTitle = $this->tr('ai.articles.col.title');
        $tColKeywords = $this->tr('ai.articles.col.keywords');
        $tColWordCount = $this->tr('ai.articles.col.word_count');
        $tColStatus = $this->tr('ai.articles.col.status');
        $tColDate = $this->tr('ai.articles.col.date');
        $tLoading = $this->tr('common.loading');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>✨ {$tGenerateNew}</h3><span class="p-card-sub">{$tReadySeconds}</span></div>
            <form id="articleForm">
                <div class="form-group">
                    <label class="form-label" for="topic">{$tTopicLabel} *</label>
                    <input type="text" id="topic" class="form-control" placeholder="{$tTopicPlaceholder}" required>
                </div>
                <div class="p-grid cols-2">
                    <div class="form-group">
                        <label class="form-label" for="art_language">{$tLanguage}</label>
                        <select id="art_language" class="form-control">
                            <option value="ar">العربية</option>
                            <option value="auto" selected>جمهور الموقع</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="art_tone">{$tTone}</label>
                        <select id="art_tone" class="form-control">
                            <option value="professional">{$tToneProfessional}</option>
                            <option value="friendly">{$tToneFriendly}</option>
                            <option value="luxury">{$tToneLuxury}</option>
                            <option value="adventurous">{$tToneAdventurous}</option>
                        </select>
                    </div>
                </div>
                <div id="articleAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="submit" class="p-btn primary" id="genArticleBtn">{$tGenerateBtn}</button>
            </form>
        </div>

        <div class="p-card no-pad" style="margin-top:16px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$tPreviousArticles}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="articlesTable">
                <thead><tr><th>{$tColTitle}</th><th>{$tColKeywords}</th><th>{$tColWordCount}</th><th>{$tColStatus}</th><th>{$tColDate}</th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5">{$tLoading}</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    const statusBadge = (a) => {
        if (a.status === 'published') {
            return `<a href="${esc(a.published_url || '#')}" target="_blank" rel="noopener" class="pill green">🌍 __PUBLISHED__</a>`;
        }
        if (a.status === 'scheduled') {
            return `<span class="pill blue" title="${esc(a.scheduled_at || '')}">🗓️ __SCHEDULED__ · ${esc(formatDate(a.scheduled_at))}</span>`;
        }
        if (a.status === 'schedule_failed') return '<span class="pill red">✖ __SCHEDULE_FAILED__</span>';
        if (a.status === 'failed') return '<span class="pill red">✖ __FAILED__</span>';
        if (a.status === 'generating') return '<span class="pill blue">⏳ __GENERATING__</span>';
        return '<span class="pill">📝 __READY_NOT_PUBLISHED__</span>';
    };

    window.cancelSchedule = async function (id) {
        if (!confirm(__CANCEL_SCHEDULE_CONFIRM__)) return;
        const res = await fetchJSON('/api/ai/article/' + id + '/schedule/cancel', { method: 'POST' });
        if (res.success) { toast(__SCHEDULE_CANCELLED__, 'success'); loadArticles(); }
        else { toast(res.error || __CANCEL_SCHEDULE_FAILED__, 'error'); }
    };

    const IMG_ERRORS = __IMG_ERRORS_JSON__;

    // فحص مجاني لسبب عدم توليد الصور (من غير ما يصرف على صورة)
    window.imageCheck = async function (btn) {
        const old = btn.textContent; btn.disabled = true; btn.textContent = '…';
        const res = await fetchJSON('/api/ai/articles/image-check');
        btn.disabled = false; btn.textContent = old;
        if (!res.success) { toast(__IMG_FAILED__, 'error'); return; }
        const rows = res.data.checks.map(c => `<div style="display:flex;gap:10px;align-items:flex-start;padding:7px 0;border-bottom:1px solid var(--ap-line,#e3e6ec);">
            <span style="flex:none;font-weight:700;color:${c.ok ? '#0b7a43' : '#b42318'}">${c.ok ? '✓' : '✗'}</span>
            <div><div>${esc(c.label)}${c.detail ? ' <span dir="ltr" style="opacity:.7;font-size:12px;">· ' + esc(c.detail) + '</span>' : ''}</div>${c.hint ? '<div style="font-size:12.5px;opacity:.8;margin-top:2px;">' + esc(c.hint) + '</div>' : ''}</div></div>`).join('');
        const bg = document.createElement('div'); bg.className = 'af-modal-bg';
        bg.innerHTML = '<div class="af-modal"><h3>🩺 ' + esc(__IMG_CHECK_TITLE__) + '</h3><div>' + rows + '</div><p class="af-sub" style="margin-top:12px;">' + esc(res.data.all_ok ? __IMG_CHECK_OK__ : __IMG_CHECK_BAD__) + '</p><div class="af-actions"><button type="button" class="p-btn primary" data-x>OK</button></div></div>';
        document.body.appendChild(bg);
        const close = () => bg.remove(); bg.addEventListener('mousedown', e => { if (e.target === bg) close(); }); bg.querySelector('[data-x]').onclick = close;
    };

    // صورة المقال: بتتولّد (10 لـ 30 ثانية) وبتتحط على المقال المنشور في WordPress لو موجود
    window.articleImage = async function (id, hasImage, btn) {
        const old = btn.textContent; btn.disabled = true; btn.textContent = __IMG_WORKING__;
        try {
            const res = await fetchJSON('/api/ai/article/' + id + '/image', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ regenerate: !!hasImage })
            });
            if (!res.success) {
                // الرسالة جاهزة من السيرفر (عامة للعميل). الكود التقني بيوصل للأدمن بس (details.http).
                const d0 = res.details || {};
                toast((res.error || IMG_ERRORS[d0.reason] || __IMG_FAILED__) + (d0.http ? ' [HTTP ' + d0.http + (d0.api_status ? ' ' + d0.api_status : '') + ']' : ''), 'error');
                return;
            }
            const d = res.data, warn = { upload_failed: __IMG_WARN_UPLOAD__, apply_failed: __IMG_WARN_UPLOAD__, no_wordpress_connection: __IMG_WARN_NOCONN__ }[d.warning];
            toast(d.applied_to_wordpress ? __IMG_DONE_WP__ : (warn || __IMG_DONE__), warn ? 'error' : 'success');
            loadArticles();
        } catch (e) { toast(__IMG_FAILED__, 'error'); } finally { btn.disabled = false; btn.textContent = old; }
    };

    window.deleteArticle = async function (id) {
        if (!confirm(__DELETE_CONFIRM__)) return;
        const res = await fetchJSON('/api/ai/article/' + id, { method: 'DELETE' });
        if (res.success) { toast(__DELETED__, 'success'); loadArticles(); }
        else { toast(res.error || __DELETE_FAILED__, 'error'); }
    };

    async function loadArticles() {
        const res = await fetchJSON('/api/ai/articles');
        const tbody = document.querySelector('#articlesTable tbody');
        if (res.success) { window.__maybeAddCheckButton(!!res.data.staff); }
        if (res.success && Array.isArray(res.data.articles) && res.data.articles.length) {
            tbody.innerHTML = res.data.articles.map(a => `
                <tr>
                    <td style="display:flex;align-items:center;gap:10px;">${a.image_url ? `<img src="${esc(a.image_url)}" alt="" loading="lazy" style="width:56px;height:32px;object-fit:cover;border-radius:6px;flex:none;">` : ''}<strong>${esc(a.title || a.topic || '-')}</strong></td>
                    <td class="p-cell-muted">${esc((a.suggested_keywords_preview || []).join(', '))}</td>
                    <td>${esc(a.word_count || 0)}</td>
                    <td>${statusBadge(a)}</td>
                    <td class="p-cell-muted">${formatDate(a.created_at)}</td>
                    <td style="white-space:nowrap;">
                        <a href="/ai/article/${a.id}" class="p-btn outline xs">__OPEN__</a>
                        ${a.status === 'scheduled' ? `<button class="p-btn outline xs" onclick="cancelSchedule(${a.id})">__CANCEL_SCHEDULE__</button>` : ''}
                        ${(a.images_ready && (a.status === 'completed' || a.status === 'published' || a.status === 'scheduled')) ? `<button class="p-btn outline xs" onclick="articleImage(${a.id}, ${a.image_url ? 'true' : 'false'}, this)">${a.image_url ? '__IMG_BTN_NEW__' : '__IMG_BTN__'}</button>` : ''}
                        <button class="p-btn danger xs" onclick="deleteArticle(${a.id})">__DELETE__</button>
                    </td>
                </tr>`).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="p-cell-muted text-center">__NO_ARTICLES__</td></tr>';
        }
    }

    document.getElementById('articleForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('articleAlert');
        alertBox.style.display = 'none';
        const btn = document.getElementById('genArticleBtn');
        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = __WRITING__;

        try {
            const res = await fetchJSON('/api/ai/article', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    topic: document.getElementById('topic').value.trim(),
                    target_language: document.getElementById('art_language').value,
                    tone: document.getElementById('art_tone').value,
                }),
            });

            if (!res.success) {
                alertBox.textContent = res.error || __GENERATE_FAILED__;
                alertBox.style.display = 'block';
                return;
            }

            toast(__GENERATED_SUCCESS__, 'success');
            window.location.href = '/ai/article/' + res.data.article.id;
        } catch (err) {
            alertBox.textContent = __CONNECTION_ERROR__;
            alertBox.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = originalLabel;
        }
    });

    // زرار الفحص بيتضاف بعد ما نعرف إن المستخدم أدمن (من قايمة المقالات). العميل مبيشوفوش.
    window.__maybeAddCheckButton = function (isStaff) {
        if (!isStaff) return;
        const th = document.querySelector('table thead');
        const card = th ? th.closest('.p-card') : null;
        if (!card || document.getElementById('imgCheckBtn')) return;
        const b = document.createElement('button');
        b.type = 'button'; b.id = 'imgCheckBtn'; b.className = 'p-btn outline xs'; b.style.margin = '0 0 10px'; b.textContent = '🩺 ' + __IMG_CHECK_BTN__;
        b.onclick = function () { imageCheck(b); };
        card.insertBefore(b, card.firstChild);
    };

    loadArticles();
})();
JS;
        $script = str_replace(
            ['__PUBLISHED__', '__FAILED__', '__GENERATING__', '__READY_NOT_PUBLISHED__', '__SCHEDULED__', '__SCHEDULE_FAILED__', '__CANCEL_SCHEDULE__', '__CANCEL_SCHEDULE_CONFIRM__', '__SCHEDULE_CANCELLED__', '__CANCEL_SCHEDULE_FAILED__', '__DELETE_CONFIRM__', '__DELETED__', '__DELETE_FAILED__', '__OPEN__', '__DELETE__', '__NO_ARTICLES__', '__WRITING__', '__GENERATE_FAILED__', '__GENERATED_SUCCESS__', '__CONNECTION_ERROR__',
             '__IMG_WORKING__', '__IMG_FAILED__', '__IMG_ERR_SQL__', '__IMG_ERR_LIMIT__', '__IMG_WARN_UPLOAD__', '__IMG_WARN_NOCONN__', '__IMG_DONE_WP__', '__IMG_DONE__', '__IMG_BTN_NEW__', '__IMG_BTN__',
             '__IMG_ERRORS_JSON__', '__IMG_CHECK_TITLE__', '__IMG_CHECK_OK__', '__IMG_CHECK_BAD__', '__IMG_CHECK_BTN__'],
            [
                $this->tr('ai.articles.status.published'),
                $this->tr('ai.articles.status.failed'),
                $this->tr('ai.articles.status.generating'),
                $this->tr('ai.articles.status.ready'),
                $this->tr('ai.articles.status.scheduled'),
                $this->tr('ai.articles.status.schedule_failed'),
                $this->tr('ai.articles.cancel_schedule'),
                $this->trJs('ai.articles.cancel_schedule_confirm'),
                $this->trJs('ai.articles.schedule_cancelled'),
                $this->trJs('ai.articles.cancel_schedule_failed'),
                $this->trJs('ai.articles.delete_confirm'),
                $this->trJs('common.deleted'),
                $this->trJs('common.delete_failed'),
                $this->tr('common.open'),
                $this->tr('common.delete'),
                $this->tr('ai.articles.none_yet'),
                $this->trJs('ai.articles.writing'),
                $this->trJs('ai.articles.generate_failed'),
                $this->trJs('ai.articles.generated_success'),
                $this->trJs('ai.analyze.connection_error'),
                $this->trJs('ai.articles.image_working'),
                $this->trJs('ai.articles.image_failed'),
                $this->trJs('ai.articles.image_err.columns_missing'),
                $this->trJs('ai.articles.image_err.daily_limit'),
                $this->trJs('ai.articles.image_warn.upload_failed'),
                $this->trJs('ai.articles.image_warn.no_wordpress_connection'),
                $this->trJs('ai.articles.image_done_wp'),
                $this->trJs('ai.articles.image_done'),
                $this->tr('ai.articles.image_btn_new'),
                $this->tr('ai.articles.image_btn'),
                $this->imageErrorsJson(),
                $this->trJs('ai.articles.imgchk.title'),
                $this->trJs('ai.articles.imgchk.ok'),
                $this->trJs('ai.articles.imgchk.bad'),
                $this->trJs('ai.articles.imgchk.btn'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_articles', $this->tr('sidebar.ai_articles'), $this->tr('ai.articles.page_subtitle'), $body, $script);
        exit;
    }

    /** GET /ai/article/{id} */
    public function showArticle(array $params): array
    {
        $articleId = (int) ($params['id'] ?? 0);
        $tLoadingArticle = $this->tr('ai.article.loading');
        $tPublishTitle = $this->tr('ai.article.publish_title');
        $tSelectSite = $this->tr('ai.article.select_site');
        $tWillPublishOn = $this->tr('ai.article.will_publish_on');
        $tAsDraft = $this->tr('ai.article.as_draft');
        $tDisconnectRelink = $this->tr('ai.article.disconnect_relink');
        $tNotConnectedChoose = $this->tr('ai.article.not_connected_choose');
        $tWordpressTab = $this->tr('ai.article.wordpress_tab');
        $tCustomSiteTab = $this->tr('ai.article.custom_site_tab');
        $tSiteUrl = $this->tr('ai.article.site_url');
        $tWpUsername = $this->tr('ai.article.wp_username');
        $tAppPasswordHint = $this->tr('ai.article.app_password_hint');
        $tEndpointUrl = $this->tr('ai.article.endpoint_url');
        $tSecretKeyOptional = $this->tr('ai.article.secret_key_optional');
        $tSecretKeyPlaceholder = $this->tr('ai.article.secret_key_placeholder');
        $tCustomSiteHint = $this->tr('ai.article.custom_site_hint');
        $tAsDraftWpOnly = $this->tr('ai.article.as_draft_wp_only');
        $tPublishNow = $this->tr('ai.article.publish_now');
        $tScheduleForLater = $this->tr('ai.article.schedule_datetime_label');
        $tScheduleBtn = $this->tr('ai.article.schedule_btn');

        $body = <<<HTML
        <div id="loadingArticle" class="p-empty"><div class="p-empty-icon">⏳</div>{$tLoadingArticle}</div>
        <div id="articleBody" style="display:none;"></div>

        <div class="p-modal-overlay" id="publishModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>🚀 {$tPublishTitle}</h3><button class="p-modal-close" onclick="closePublishModal()">×</button></div>
                <div class="p-modal-body">
                    <div id="publishStep_selectSite">
                        <label class="form-label">{$tSelectSite}</label>
                        <select id="pubWebsiteId" class="p-select" style="width:100%;margin-bottom:14px;"></select>
                    </div>

                    <div id="publishStep_alreadyConnected" style="display:none;">
                        <p class="p-cell-muted">{$tWillPublishOn} <strong id="pubConnectedSite" style="direction:ltr;display:inline-block;"></strong> <span id="pubConnectedPlatform" class="pill"></span></p>
                        <label style="display:flex;align-items:center;gap:8px;margin:10px 0;" id="pubDraftRowConnected">
                            <input type="checkbox" id="pubAsDraft"> {$tAsDraft}
                        </label>
                        <button type="button" class="p-btn outline xs" onclick="disconnectAndRelink()">🔌 {$tDisconnectRelink}</button>
                    </div>

                    <div id="publishStep_needsConnection" style="display:none;">
                        <p class="p-cell-muted">{$tNotConnectedChoose}</p>

                        <div style="display:flex;gap:10px;margin-bottom:14px;">
                            <button type="button" class="p-btn outline xs" id="pubTabWpBtn" onclick="switchPublishTab('wordpress')">🅆 {$tWordpressTab}</button>
                            <button type="button" class="p-btn outline xs" id="pubTabCustomBtn" onclick="switchPublishTab('custom_api')">🔧 {$tCustomSiteTab}</button>
                        </div>

                        <div id="pubTab_wordpress">
                            <label class="form-label">{$tSiteUrl}</label>
                            <input type="url" id="pubSiteUrl" class="form-control" placeholder="https://example.com" style="margin-bottom:10px;">
                            <label class="form-label">{$tWpUsername}</label>
                            <input type="text" id="pubUsername" class="form-control" style="margin-bottom:10px;">
                            <label class="form-label">Application Password</label>
                            <input type="text" id="pubAppPassword" class="form-control" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" style="margin-bottom:6px;">
                            <p class="p-cell-muted" style="font-size:12.5px;">{$tAppPasswordHint}</p>
                        </div>

                        <div id="pubTab_custom_api" style="display:none;">
                            <label class="form-label">{$tEndpointUrl}</label>
                            <input type="url" id="pubEndpointUrl" class="form-control" placeholder="https://example.com/tourfecto-publish" style="margin-bottom:10px;">
                            <label class="form-label">{$tSecretKeyOptional}</label>
                            <input type="text" id="pubAccessToken" class="form-control" placeholder="{$tSecretKeyPlaceholder}" style="margin-bottom:6px;">
                            <p class="p-cell-muted" style="font-size:12.5px;">
                                {$tCustomSiteHint}
                            </p>
                        </div>

                        <label style="display:flex;align-items:center;gap:8px;margin:10px 0;" id="pubDraftRowNew">
                            <input type="checkbox" id="pubAsDraft2"> {$tAsDraftWpOnly}
                        </label>
                    </div>

                    <div id="publishAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>

                    <div style="border-top:1px solid var(--border-color,#e5e7eb);margin-top:16px;padding-top:16px;">
                        <label class="form-label" for="pubScheduleAt">🗓️ {$tScheduleForLater}</label>
                        <input type="datetime-local" id="pubScheduleAt" class="form-control" style="margin-bottom:8px;">
                    </div>
                </div>
                <div class="p-modal-foot" style="display:flex;gap:10px;">
                    <button class="p-btn outline" id="scheduleConfirmBtn" onclick="confirmSchedule()">{$tScheduleBtn}</button>
                    <button class="p-btn primary" id="publishConfirmBtn" onclick="confirmPublish()">{$tPublishNow}</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const articleId = __ARTICLE_ID__;
    let currentArticle = null;
    let connectionKnown = false; // عرفنا حالة الربط لآخر موقع تم اختياره ولا لسه

    // تحويل بسيط لـ Markdown -> HTML (عناوين + فقرات، كفاية للمعاينة)
    function mdToHtml(md) {
        return esc(md)
            .replace(/^### (.*)$/gm, '<h4>$1</h4>')
            .replace(/^## (.*)$/gm, '<h3>$1</h3>')
            .replace(/^# (.*)$/gm, '<h2>$1</h2>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .split(/\n{2,}/).map(p => p.startsWith('<h') ? p : `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');
    }

    window.copyArticle = function () {
        const text = document.getElementById('rawMarkdown').value;
        navigator.clipboard.writeText(text).then(() => toast(__COPIED__, 'success'));
    };

    window.cancelArticleSchedule = async function () {
        if (!confirm(__CANCEL_SCHEDULE_CONFIRM__)) return;
        const res = await fetchJSON('/api/ai/article/' + articleId + '/schedule/cancel', { method: 'POST' });
        if (res.success) { toast(__SCHEDULE_CANCELLED__, 'success'); load(); }
        else { toast(res.error || __CANCEL_SCHEDULE_FAILED__, 'error'); }
    };

    function renderPublishStatus(a) {
        if (a.status === 'published' && a.published_url) {
            return `
                <div class="alert" style="background:#e8f8ee;color:#1a7f4c;border:1px solid #b6ecd0;">
                    ✅ __ALREADY_PUBLISHED__
                </div>
                <div style="display:flex;gap:10px;margin-top:10px;">
                    <a href="${esc(a.published_url)}" target="_blank" rel="noopener" class="p-btn primary">🌍 __OPEN_PUBLISHED__</a>
                    <button class="p-btn outline" onclick="openPublishModal()">🔁 __REPUBLISH__</button>
                </div>`;
        }
        if (a.status === 'scheduled' && a.scheduled_at) {
            return `
                <div class="alert" style="background:#eaf2ff;color:#1b4fa0;border:1px solid #bcd6ff;">
                    🗓️ __ALREADY_SCHEDULED__ <strong>${esc(a.scheduled_at)}</strong>
                </div>
                <div style="display:flex;gap:10px;margin-top:10px;">
                    <button class="p-btn danger outline" onclick="cancelArticleSchedule()">✖ __CANCEL_SCHEDULE__</button>
                </div>`;
        }
        if (a.status === 'schedule_failed') {
            return `
                <div class="alert alert-danger">✖ __SCHEDULE_FAILED_MSG__ ${esc(a.error_message || '')}</div>
                <button class="p-btn primary" onclick="openPublishModal()">🚀 __PUBLISH_TO_SITE__</button>`;
        }
        return `<button class="p-btn primary" onclick="openPublishModal()">🚀 __PUBLISH_TO_SITE__</button>`;
    }

    async function load() {
        const res = await fetchJSON('/api/ai/article/' + articleId);
        document.getElementById('loadingArticle').style.display = 'none';
        const box = document.getElementById('articleBody');
        box.style.display = 'block';

        if (!res.success) {
            box.innerHTML = `<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>${esc(res.error || __LOAD_FAILED__)}</div></div>`;
            return;
        }

        const a = res.data.article;
        currentArticle = a;
        box.innerHTML = `
            <div class="p-card">
                <div class="p-card-head"><h3>${esc(a.title || '-')}</h3><span class="p-card-sub">${esc(a.word_count || 0)} __WORDS__ · ${esc((a.target_language || '').toUpperCase())}</span></div>
                <p class="p-cell-muted">${esc(a.meta_description || '')}</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0;">
                    <a href="/api/ai/article/${articleId}/export" class="p-btn outline">⬇️ __DOWNLOAD_MD__</a>
                    <button class="p-btn outline" onclick="copyArticle()">📋 __COPY_TEXT__</button>
                </div>
                <div id="publishArea" style="margin-bottom:16px;">${renderPublishStatus(a)}</div>
                <div style="background:var(--panel-bg,#f7f8fa);padding:20px;border-radius:10px;line-height:1.9;">${mdToHtml(a.content || '')}</div>
                <textarea id="rawMarkdown" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip-path:inset(50%);">${esc(a.content || '')}</textarea>
            </div>
        `;
    }

    async function loadWebsitesIntoSelect() {
        const res = await fetchJSON('/api/websites');
        const sel = document.getElementById('pubWebsiteId');
        if (res.success && res.data.websites && res.data.websites.length) {
            sel.innerHTML = res.data.websites.map(w => `<option value="${w.id}">${esc(w.company_name || w.main_url)}</option>`).join('');
            if (currentArticle && currentArticle.website_id) sel.value = currentArticle.website_id;
        } else {
            sel.innerHTML = '<option value="">__NO_WEBSITES__</option>';
        }
    }

    async function checkConnectionForSelectedSite() {
        const websiteId = document.getElementById('pubWebsiteId').value;
        document.getElementById('publishStep_alreadyConnected').style.display = 'none';
        document.getElementById('publishStep_needsConnection').style.display = 'none';
        document.getElementById('scheduleConfirmBtn').style.display = 'none';
        if (!websiteId) return;

        const res = await fetchJSON('/api/publishing/status/' + websiteId);
        if (res.success && res.data.connected) {
            document.getElementById('pubConnectedSite').textContent = res.data.target;
            document.getElementById('pubConnectedPlatform').textContent = res.data.label;
            document.getElementById('pubDraftRowConnected').style.display = res.data.platform === 'wordpress' ? 'flex' : 'none';
            document.getElementById('publishStep_alreadyConnected').style.display = 'block';
            // الجدولة محتاجة اتصال جاهز مقدمًا (مفيش تفاعل بشري وقت
            // التنفيذ الفعلي للجدولة عشان يجمع بيانات اتصال جديدة)
            document.getElementById('scheduleConfirmBtn').style.display = 'inline-block';
        } else {
            document.getElementById('publishStep_needsConnection').style.display = 'block';
            switchPublishTab('wordpress');
        }
    }

    window.switchPublishTab = function (tab) {
        document.getElementById('pubTab_wordpress').style.display = tab === 'wordpress' ? 'block' : 'none';
        document.getElementById('pubTab_custom_api').style.display = tab === 'custom_api' ? 'block' : 'none';
        document.getElementById('pubDraftRowNew').style.display = tab === 'wordpress' ? 'flex' : 'none';
        document.getElementById('pubTabWpBtn').classList.toggle('primary', tab === 'wordpress');
        document.getElementById('pubTabCustomBtn').classList.toggle('primary', tab === 'custom_api');
        document.getElementById('publishModal').dataset.activeTab = tab;
    };

    window.disconnectAndRelink = async function () {
        const websiteId = document.getElementById('pubWebsiteId').value;
        if (!websiteId) return;
        const res = await fetchJSON('/api/publishing/disconnect/' + websiteId, { method: 'POST' });
        if (res.success) {
            document.getElementById('publishStep_alreadyConnected').style.display = 'none';
            document.getElementById('publishStep_needsConnection').style.display = 'block';
            switchPublishTab('wordpress');
        } else {
            toast(res.error || __DISCONNECT_FAILED__, 'error');
        }
    };

    window.openPublishModal = function () {
        document.getElementById('publishAlert').style.display = 'none';
        document.getElementById('publishModal').classList.add('open');
        loadWebsitesIntoSelect().then(checkConnectionForSelectedSite);
    };

    window.closePublishModal = function () {
        document.getElementById('publishModal').classList.remove('open');
    };

    document.getElementById('pubWebsiteId').addEventListener('change', checkConnectionForSelectedSite);

    window.confirmPublish = async function () {
        const alertBox = document.getElementById('publishAlert');
        alertBox.style.display = 'none';
        const websiteId = document.getElementById('pubWebsiteId').value;
        if (!websiteId) { alertBox.textContent = __SELECT_SITE_FIRST__; alertBox.style.display = 'block'; return; }

        const needsConnectionVisible = document.getElementById('publishStep_needsConnection').style.display !== 'none';
        const payload = { website_id: websiteId };

        if (needsConnectionVisible) {
            const activeTab = document.getElementById('publishModal').dataset.activeTab || 'wordpress';
            payload.platform = activeTab;

            if (activeTab === 'custom_api') {
                payload.endpoint_url = document.getElementById('pubEndpointUrl').value.trim();
                payload.access_token = document.getElementById('pubAccessToken').value.trim();
                if (!payload.endpoint_url) {
                    alertBox.textContent = __ENDPOINT_FIRST__;
                    alertBox.style.display = 'block';
                    return;
                }
            } else {
                payload.site_url = document.getElementById('pubSiteUrl').value.trim();
                payload.username = document.getElementById('pubUsername').value.trim();
                payload.app_password = document.getElementById('pubAppPassword').value.trim();
                payload.draft = document.getElementById('pubAsDraft2').checked;
                if (!payload.site_url || !payload.username || !payload.app_password) {
                    alertBox.textContent = __COMPLETE_WP_DATA__;
                    alertBox.style.display = 'block';
                    return;
                }
            }
        } else {
            payload.draft = document.getElementById('pubAsDraft').checked;
        }

        const btn = document.getElementById('publishConfirmBtn');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = __PUBLISHING__;

        try {
            const res = await fetchJSON('/api/ai/article/' + articleId + '/publish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            if (!res.success) {
                alertBox.textContent = res.error || __PUBLISH_FAILED__;
                alertBox.style.display = 'block';
                return;
            }

            toast(__PUBLISH_SUCCESS__, 'success');
            if (res.data && res.data.image_warning_text) { toast(res.data.image_warning_text, 'error'); }
            closePublishModal();
            load();
        } catch (err) {
            alertBox.textContent = __CONNECTION_ERROR__;
            alertBox.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    };

    window.confirmSchedule = async function () {
        const alertBox = document.getElementById('publishAlert');
        alertBox.style.display = 'none';
        const websiteId = document.getElementById('pubWebsiteId').value;
        if (!websiteId) { alertBox.textContent = __SELECT_SITE_FIRST__; alertBox.style.display = 'block'; return; }

        const scheduledAtLocal = document.getElementById('pubScheduleAt').value; // 'YYYY-MM-DDTHH:MM'
        if (!scheduledAtLocal) {
            alertBox.textContent = __SELECT_DATETIME_FIRST__;
            alertBox.style.display = 'block';
            return;
        }

        // الجدولة بتفترض اتصال جاهز مسبقًا - لو المستخدم لسه في خطوة
        // "محتاج ربط جديد"، مفيش معنى نجدول (زرار الجدولة أصلاً بيتخفي
        // في الحالة دي من checkConnectionForSelectedSite، الفحص هنا
        // مجرد حماية إضافية لو حصل تلاعب بالـ DOM يدويًا).
        const needsConnectionVisible = document.getElementById('publishStep_needsConnection').style.display !== 'none';
        if (needsConnectionVisible) {
            alertBox.textContent = __SCHEDULE_NEEDS_CONNECTION__;
            alertBox.style.display = 'block';
            return;
        }

        const payload = {
            website_id: websiteId,
            scheduled_at: scheduledAtLocal.replace('T', ' ') + ':00',
            draft: document.getElementById('pubAsDraft').checked,
        };

        const btn = document.getElementById('scheduleConfirmBtn');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = __SCHEDULING__;

        try {
            const res = await fetchJSON('/api/ai/article/' + articleId + '/schedule', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            if (!res.success) {
                alertBox.textContent = res.error || __SCHEDULE_FAILED__;
                alertBox.style.display = 'block';
                return;
            }

            toast(__SCHEDULE_SUCCESS__, 'success');
            closePublishModal();
            load();
        } catch (err) {
            alertBox.textContent = __CONNECTION_ERROR__;
            alertBox.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    };

    load();
})();
JS;
        $script = str_replace(
            [
                '__ARTICLE_ID__', '__COPIED__', '__ALREADY_PUBLISHED__', '__OPEN_PUBLISHED__', '__REPUBLISH__', '__PUBLISH_TO_SITE__',
                '__LOAD_FAILED__', '__WORDS__', '__DOWNLOAD_MD__', '__COPY_TEXT__', '__NO_WEBSITES__', '__DISCONNECT_FAILED__',
                '__SELECT_SITE_FIRST__', '__ENDPOINT_FIRST__', '__COMPLETE_WP_DATA__', '__PUBLISHING__', '__PUBLISH_FAILED__',
                '__PUBLISH_SUCCESS__', '__CONNECTION_ERROR__',
                '__ALREADY_SCHEDULED__', '__CANCEL_SCHEDULE__', '__CANCEL_SCHEDULE_CONFIRM__', '__SCHEDULE_CANCELLED__',
                '__CANCEL_SCHEDULE_FAILED__', '__SCHEDULE_FAILED_MSG__', '__SELECT_DATETIME_FIRST__', '__SCHEDULE_NEEDS_CONNECTION__',
                '__SCHEDULING__', '__SCHEDULE_FAILED__', '__SCHEDULE_SUCCESS__',
            '__IMG_WORKING__', '__IMG_FAILED__', '__IMG_ERR_SQL__', '__IMG_ERR_LIMIT__', '__IMG_WARN_UPLOAD__', '__IMG_WARN_NOCONN__', '__IMG_DONE_WP__', '__IMG_DONE__', '__IMG_BTN_NEW__', '__IMG_BTN__',
            ],
            [
                (string) $articleId,
                $this->trJs('ai.article.copied'),
                $this->tr('ai.article.already_published'),
                $this->tr('ai.article.open_published'),
                $this->tr('ai.article.republish'),
                $this->tr('ai.article.publish_to_site'),
                $this->trJs('ai.article.load_failed'),
                $this->tr('ai.article.words'),
                $this->tr('ai.article.download_md'),
                $this->tr('ai.article.copy_text'),
                $this->tr('ai.article.no_websites'),
                $this->trJs('common.disconnect_failed'),
                $this->trJs('ai.article.select_site_first'),
                $this->trJs('ai.article.endpoint_first'),
                $this->trJs('ai.article.complete_wp_data'),
                $this->trJs('ai.article.publishing'),
                $this->trJs('ai.article.publish_failed'),
                $this->trJs('ai.article.publish_success'),
                $this->trJs('ai.analyze.connection_error'),
                $this->tr('ai.article.already_scheduled'),
                $this->tr('ai.article.cancel_schedule'),
                $this->trJs('ai.article.cancel_schedule_confirm'),
                $this->trJs('ai.article.schedule_cancelled'),
                $this->trJs('ai.article.cancel_schedule_failed'),
                $this->tr('ai.article.schedule_failed') . ':',
                $this->trJs('ai.article.select_datetime_first'),
                $this->trJs('ai.article.schedule_needs_connection'),
                $this->trJs('ai.article.scheduling'),
                $this->trJs('ai.article.schedule_failed'),
                $this->trJs('ai.article.schedule_success'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_articles', $this->tr('ai.article.page_title'), $this->tr('ai.article.page_subtitle'), $body, $script);
        exit;
    }

    /** POST /api/ai/article */
    public function generateArticle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }

        $topic = trim((string) $this->get('topic', ''));
        if ($topic === '') {
            return $this->error('الموضوع مطلوب', 422);
        }

        $language = $this->get('target_language', 'en');
        $tone = $this->get('tone', 'professional');
        $websiteId = $this->get('website_id');
        $userId = $this->getUserId();
        if (!$this->has('target_language') || $language==='auto') $language=ContentLanguageService::forUser((int)$userId,(int)$websiteId?:null);

        // 🔒 توليد المقالات كان ببلاش: من حصة المقالات في الباقة، وإلا من المحفظة
        if (class_exists('PlanUsage')) {
            $planCheck = PlanUsage::check((int) $userId, 'article_generation', 'article_generation', 'رصيد المحفظة مش كافي لتوليد مقال');
            if (!$planCheck['ok']) {
                return $this->error($planCheck['error'], 402, ['shortfall' => $planCheck['shortfall'] ?? null]);
            }
        }

        // بيانات إضافية اختيارية من الموقع المرتبط (لو موجود) عشان المقال يبقى مخصص
        $companyName = null;
        $websiteUrl = null;
        $existingPages = [];
        if ($websiteId) {
            try {
                $website = (new Website())->find((int) $websiteId);
                if ($website && (int) $website->getAttribute('user_id') === (int) $userId) {
                    $companyName = $website->getAttribute('company_name');
                    $websiteUrl = $website->getAttribute('main_url');
                    // Phase 8: مقالات سابقة لنفس الموقع كمرشحين لروابط داخلية حقيقية
                    $prevArticles = $this->db->query(
                        "SELECT title, slug FROM ai_articles WHERE website_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 15",
                        [$websiteId]
                    );
                    foreach ($prevArticles as $pa) {
                        $existingPages[] = ['title' => $pa['title'], 'path' => '/blog/' . $pa['slug']];
                    }
                }
            } catch (Exception $e) {
                // تجاهل - المقال هيتولد من غير تخصيص إضافي
            }
        }

        try {
            $article = new AIArticle([
                'user_id' => $userId,
                'website_id' => $websiteId ?: null,
                'topic' => $topic,
                'target_language' => $language==='auto'?'en':$language,
                'tone' => $tone,
                'status' => 'generating',
            ]);
            $articleId = $article->save();

            if ($websiteId) $existingPages=array_merge((new ContentQualityService($this->db))->knownPages($userId,(int)$websiteId),$existingPages);
            $contentBrief = $websiteId ? (new ContentBriefService($this->db))->forKeyword($userId, $websiteId, $topic) : null;
            $result = $this->articleGenerator->generate($topic, $language, $tone, $companyName, $websiteUrl, $existingPages, $contentBrief);

            $saved = (new AIArticle())->find($articleId);
            if (!$saved) {
                return $this->error('تعذر حفظ المقال', 500);
            }

            if (!$result['success']) {
                $saved->setAttribute('status', 'failed');
                $saved->setAttribute('error_message', $result['error'] ?? 'خطأ غير معروف');
                $saved->save();
                return $this->error($result['error'] ?? 'تعذر توليد المقال', 500);
            }

            $data = $result['data'];
            $saved->setAttribute('title', $data['title']);
            $saved->setAttribute('meta_description', $data['meta_description']);
            $saved->setAttribute('slug', $data['slug']);
            $saved->setAttribute('content', $data['content']);
            $saved->setAttribute('suggested_keywords', json_encode($data['suggested_keywords'], JSON_UNESCAPED_UNICODE));
            $saved->setAttribute('word_count', $data['word_count']);
            $saved->setAttribute('status', 'completed');
            $saved->save();
            if ($contentBrief !== null) (new ContentQualityService($this->db))->remember($userId, $websiteId, 'article:'.(int)$articleId, $contentBrief, $data, (int)$articleId, null, $existingPages, (string)$websiteUrl);
            if (class_exists('PlanUsage')) {
                PlanUsage::charge((int) $userId, 'article_generation', 'article_generation', 'مقال: ' . mb_substr((string) $data['title'], 0, 120));
            }

            // Phase 8 (Content Agent): الحقول الجديدة دي بتتسجل بـUPDATE منفصل
            // ومعزول تمامًا عن save() الأساسية فوق - لو الـMigration بتاعة
            // Phase 8 لسه ما اتطبقتش (الأعمدة الجديدة مش موجودة)، الخطأ هنا
            // بيتلقط ومبيوصلش لمقال العميل، اللي فعلًا اتحفظ بنجاح فوق
            // بحقوله الأساسية. (تجربة setAttribute+save() واحدة للكل كانت
            // هتخلي أي عمود جديد ناقص يكسر حفظ المقال بالكامل - Model::update()
            // بيبني UPDATE من كل الـattributes مرة واحدة، مفيش تجاهل تلقائي
            // لعمود مش موجود زي ما كان مفترض غلط في نسخة سابقة من الكود ده).
            try {
                $this->db->exec(
                    "UPDATE ai_articles SET faqs_json = ?, schema_suggestion = ?, internal_link_suggestions_json = ? WHERE id = ?",
                    [
                        json_encode($data['faqs'] ?? [], JSON_UNESCAPED_UNICODE),
                        $data['schema_suggestion'] ?? null,
                        json_encode($data['internal_link_suggestions'] ?? [], JSON_UNESCAPED_UNICODE),
                        $articleId,
                    ]
                );
                $saved->setAttribute('faqs_json', json_encode($data['faqs'] ?? [], JSON_UNESCAPED_UNICODE));
                $saved->setAttribute('schema_suggestion', $data['schema_suggestion'] ?? null);
                $saved->setAttribute('internal_link_suggestions_json', json_encode($data['internal_link_suggestions'] ?? [], JSON_UNESCAPED_UNICODE));
            } catch (Exception $e) {
                Logger::error('Phase 8 content-agent columns not available yet (migration pending?)', ['message' => $e->getMessage()]);
            }

            $this->log('AI Article Generated', ['article_id' => $articleId, 'topic' => $topic]);

            return $this->success(['article' => $saved->toArray()], 'تم توليد المقال بنجاح', 201);

        } catch (Exception $e) {
            Logger::error('Generate Article Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر توليد المقال', 500);
        }
    }

    /** GET /api/ai/articles */
    public function getArticles(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $imgCols = (class_exists('ArticleImageService') && ArticleImageService::columnsReady()) ? ', featured_image_path, wp_post_id' : '';
            $sql = "SELECT id, topic, title, target_language, word_count, status, created_at, suggested_keywords, published_url{$imgCols}
                    FROM ai_articles WHERE user_id = ? ORDER BY id DESC LIMIT 100";
            $rows = $this->db->query($sql, [$this->getUserId()]);

            foreach ($rows as &$row) {
                $row['image_url'] = !empty($row['featured_image_path']) ? ArticleImageService::publicUrl($row['featured_image_path']) : null;
                $row['images_ready'] = $imgCols !== '';
                $row['staff'] = VendorMask::isStaff($this->user ?? null);
                unset($row['featured_image_path']);
                $keywords = json_decode($row['suggested_keywords'] ?? '[]', true) ?: [];
                $row['suggested_keywords_preview'] = array_slice($keywords, 0, 3);
                unset($row['suggested_keywords']);
            }

            return $this->success(['articles' => $rows, 'staff' => VendorMask::isStaff($this->user ?? null)]);
        } catch (Exception $e) {
            Logger::error('Get Articles Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المقالات', 500);
        }
    }

    /**
     * POST /api/ai/article/{id}/image  {regenerate?: bool, apply?: bool}
     * يولّد صورة بارزة للمقال (أو يستخدم اللي موجودة)، ولو المقال منشور على WordPress بيرفعها ويحطها على المقال المنشور نفسه.
     */
    public function generateArticleImage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        @set_time_limit(150);
        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('Not found', 404);
        }
        if (!class_exists('ArticleImageService') || !ArticleImageService::columnsReady()) {
            return $this->error('columns_missing', 409, ['reason' => 'columns_missing']);
        }
        $userId = (int) $this->getUserId();
        $svc = ArticleImageService::make();
        $gen = $svc->generate($article->toArray(), $userId, (bool) $this->get('regenerate', false));
        if (empty($gen['success'])) {
            $reason = (string) ($gen['reason'] ?? 'generation_failed');
            $staff = VendorMask::isStaff($this->user ?? null);
            // العميل: رسالة عامة (من غير مزوّد ولا كود). الأدمن: السبب التقني + كود HTTP + ترتيب المزودين اللي اتجرّبوا.
            return $this->error($this->imageMessage($reason, $staff), 422, [
                'reason' => $this->imagePublicReason($reason),
                'http' => $gen['http'] ?? 0, 'api_status' => $gen['api_status'] ?? '', 'provider' => $gen['tried'] ?? [],
                'technical_reason' => $reason,
            ]);
        }

        $applied = false;
        $warning = null;
        $postId = (int) $article->getAttribute('wp_post_id');
        if ($postId > 0 && (bool) $this->get('apply', true)) {
            $connection = $this->findPublishingConnection((int) $article->getAttribute('website_id'));
            if ($connection && (string) $connection->getAttribute('platform') === 'wordpress') {
                $creds = (new Encryption())->decrypt($connection->getAttribute('access_token'));
                [$username, $appPassword] = array_pad(explode(':', $creds, 2), 2, '');
                $siteUrl = (string) $connection->getAttribute('external_location_id');
                $publisher = new WordPressPublisher();
                $fresh = $this->db->query('SELECT id, user_id, title, slug, featured_image_path, featured_image_alt, wp_media_id FROM ai_articles WHERE id = ? LIMIT 1', [(int) $article->getAttribute('id')]);
                $push = $svc->pushToWordPress($fresh[0] ?? $article->toArray(), $publisher, $siteUrl, $username, $appPassword);
                if ($push['media_id']) {
                    $set = $publisher->setFeaturedImage($siteUrl, $username, $appPassword, $postId, (int) $push['media_id']);
                    $applied = !empty($set['success']);
                    $warning = $applied ? null : 'apply_failed';
                } else {
                    $warning = $push['warning'];
                }
            } else {
                $warning = 'no_wordpress_connection';
            }
        }
        $this->log('Article Image', ['article_id' => $article->getAttribute('id'), 'applied' => $applied]);
        return $this->success(['image_url' => $gen['url'], 'alt' => $gen['alt'] ?? '', 'existing' => !empty($gen['existing']), 'applied_to_wordpress' => $applied, 'warning' => $warning]);
    }

    /** السبب اللي العميل يشوفه: ٣ أنواع بس (حظر محتوى / حد يومي / الخدمة مش متاحة). كل الباقي "مش متاحة" من غير تفاصيل. */
    private function imagePublicReason(string $reason): string
    {
        if (in_array($reason, ['blocked', 'daily_limit'], true)) {
            return $reason;
        }
        return 'unavailable';
    }

    /** رسالة الصور حسب الدور: العميل = عامة. الأدمن = السبب التقني المفصّل. */
    private function imageMessage(string $reason, bool $staff): string
    {
        if ($staff) {
            $key = 'ai.articles.image_err.' . $reason;
            $t = $this->tr($key);
            if ($t !== $key) {
                return html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            }
        }
        return html_entity_decode($this->tr('ai.articles.image_pub.' . $this->imagePublicReason($reason)), ENT_QUOTES, 'UTF-8');
    }

    /** JSON بالرسائل اللي بتتحقن في JS صفحة المقالات (عامة للعميل، مفصّلة للأدمن) */
    private function imageErrorsJson(): string
    {
        $staff = VendorMask::isStaff($this->user ?? null);
        $out = [];
        foreach (['columns_missing', 'daily_limit', 'model_unavailable', 'access_denied', 'quota', 'busy', 'blocked', 'billing_required', 'network', 'invalid_image', 'storage_failed', 'generation_failed'] as $k) {
            $out[$k] = $this->imageMessage($k, $staff);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** GET /api/ai/articles/image-check - فحص مجاني لسبب عدم توليد الصور (من غير ما يصرف على صورة) */
    public function articleImageCheck(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        @set_time_limit(60);
        if (!VendorMask::isStaff($this->user ?? null)) {
            return $this->error('Forbidden', 403);      // الفحص بيكشف مزوّدين وإعدادات: للأدمن بس
        }
        if (!class_exists('ArticleImageService')) {
            return $this->error('Not available', 404);
        }
        $checks = ArticleImageService::make()->diagnose((int) $this->getUserId());
        foreach ($checks as &$c) {
            $c['label'] = $this->tr('ai.articles.imgchk.' . $c['key']);
            $c['hint'] = $c['ok'] ? '' : $this->tr('ai.articles.imgchk.hint.' . $c['key']);
        }
        unset($c);
        return $this->success(['checks' => $checks, 'all_ok' => !in_array(false, array_column($checks, 'ok'), true)]);
    }

    /** GET /api/ai/article/{id} */
    public function getArticle(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('المقال غير موجود', 404);
        }

        $data = $article->toArray();
        $data['suggested_keywords'] = $article->getSuggestedKeywordsArray();
        $data['content'] = $article->contentForPresentation();

        return $this->success(['article' => $data]);
    }

    /** DELETE /api/ai/article/{id} */
    public function deleteArticle(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('المقال غير موجود', 404);
        }

        $article->delete();
        return $this->success([], 'تم حذف المقال');
    }

    // ============================================
    // نشر المقال على موقع العميل. بيدعم نوعين:
    //   - platform='wordpress'  : WP REST API + Application Passwords
    //   - platform='custom_api' : webhook عام يجهّزه مبرمج أي موقع تاني
    //     (شوف docs/CUSTOM_PUBLISHING_INTEGRATION.md) - ده اللي بيغطي
    //     المواقع المبرمجة بشكل خاص واللي مش ووردبريس.
    // بيانات الاتصال بتتخزن مشفّرة في platform_connections، كل موقع
    // (website_id) له اتصال واحد بس في كل مرة (النوع اللي اختاره العميل).
    // ============================================

    /** GET /api/publishing/status/{website_id} - بيرجع نوع الاتصال الحالي لو موجود (أي منهم) */
    public function publishingStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connection = $this->findPublishingConnection($websiteId);
        if (!$connection) {
            return $this->success(['connected' => false]);
        }

        $platform = (string) $connection->getAttribute('platform');
        return $this->success([
            'connected' => true,
            'platform' => $platform,
            'label' => $platform === 'wordpress' ? 'ووردبريس' : 'API مخصص',
            'target' => $connection->getAttribute('external_location_id'),
        ]);
    }

    /** بحث موحّد عن أي اتصال نشر (ووردبريس أو API مخصص) لموقع معيّن */
    private function findPublishingConnection(int $websiteId): ?PlatformConnection
    {
        foreach (['wordpress', 'custom_api'] as $platform) {
            $connections = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => $platform,
                'status' => 'connected',
            ], [], 1);
            if (!empty($connections)) {
                return $connections[0];
            }
        }
        return null;
    }

    /**
     * POST /api/publishing/wordpress/connect
     * بيانات مطلوبة: website_id, site_url, username, app_password
     * بنجرّب الاتصال فعليًا الأول (users/me) قبل ما نحفظ أي حاجة، عشان
     * منخزّنش بيانات غلط ونكتشف بس وقت النشر.
     */
    public function connectWordPress(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id', 0);
        $siteUrl = trim((string) $this->get('site_url', ''));
        $username = trim((string) $this->get('username', ''));
        $appPassword = trim((string) $this->get('app_password', ''));

        if (!$websiteId || !$siteUrl || !$username || !$appPassword) {
            return $this->error('كل الحقول مطلوبة (الموقع، الرابط، اسم المستخدم، Application Password)', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        // حماية: كان بيقبل أي رابط ويبعتله Basic Auth (SSRF). دلوقتي: HTTPS + عنوان عام + نفس دومين الموقع المضاف.
        if (class_exists('WordPressConnector')) {
            $targetError = (new WordPressConnector())->checkTarget($siteUrl, (string) $website->getAttribute('main_url'), false);
            if ($targetError !== null) {
                return $this->error($targetError === 'host_mismatch'
                    ? 'Site URL must belong to the same domain as the website you added'
                    : ($targetError === 'not_https' ? 'WordPress site must use HTTPS' : 'Site is not reachable from the internet'), 422);
            }
        }

        if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            return $this->error('رابط الموقع غير صحيح', 422);
        }

        $publisher = new WordPressPublisher();
        $test = $publisher->testConnection($siteUrl, $username, $appPassword);

        if (!$test['success']) {
            return $this->error($test['error'] ?? 'تعذر الاتصال بالموقع', 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'wordpress',
            ], [], 1);

            $data = [
                'website_id' => $websiteId,
                'user_id' => $this->getUserId(),
                'platform' => 'wordpress',
                'access_token' => $encryption->encrypt($username . ':' . $appPassword), // مفيش OAuth هنا، بنشفّر بيانات الاعتماد نفسها
                'refresh_token' => null,
                'token_expires_at' => null,
                'external_account_id' => $test['user']['id'] ?? null,
                'external_location_id' => rtrim($siteUrl, '/'),
                'external_location_name' => $test['user']['name'] ?? $siteUrl,
                'status' => 'connected',
                'last_error' => null,
            ];

            $connection = new PlatformConnection($data);
            if (!empty($existing)) {
                $connection->setKeyValue($existing[0]->getAttribute('id'));
                $connection->save();
            } else {
                $connection->save();
            }

            $this->log('WordPress Connected', ['website_id' => $websiteId, 'site_url' => $siteUrl]);

            return $this->success(['site_url' => rtrim($siteUrl, '/')], 'تم ربط ووردبريس بنجاح');
        } catch (Exception $e) {
            Logger::error('Connect WordPress Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ بيانات الاتصال', 500);
        }
    }

    /**
     * POST /api/publishing/custom/connect
     * لأي موقع تاني (برمجة خاصة/مش ووردبريس). بيانات مطلوبة:
     * website_id, endpoint_url, وaccess_token اختياري (لكن يُنصح بيه).
     * بنبعت طلب اختباري (is_test=true) الأول عشان نتأكد إن الـ endpoint
     * شغّال قبل ما نحفظ الاتصال.
     */
    public function connectCustomApi(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id', 0);
        $endpointUrl = trim((string) $this->get('endpoint_url', ''));
        $authToken = trim((string) $this->get('access_token', ''));

        if (!$websiteId || !$endpointUrl) {
            return $this->error('اختار الموقع وحط رابط نقطة الاستقبال (endpoint)', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            return $this->error('رابط نقطة الاستقبال غير صحيح', 422);
        }

        $publisher = new CustomApiPublisher();
        $test = $publisher->publish($endpointUrl, $authToken, [
            'article_id' => 0,
            'title' => 'اختبار الربط من Tourfecto',
            'content_html' => '<p>test</p>',
            'content_markdown' => 'test',
            'meta_description' => '',
            'slug' => 'tourfecto-test',
            'suggested_keywords' => [],
        ], true);

        if (!$test['success']) {
            return $this->error($test['error'] ?? 'تعذر الاتصال بنقطة الاستقبال - تأكد من الرابط ومن إن الموقع بيرد بكود 200/201', 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'custom_api',
            ], [], 1);

            $data = [
                'website_id' => $websiteId,
                'user_id' => $this->getUserId(),
                'platform' => 'custom_api',
                'access_token' => $authToken !== '' ? $encryption->encrypt($authToken) : null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'external_account_id' => null,
                'external_location_id' => $endpointUrl,
                'external_location_name' => parse_url($endpointUrl, PHP_URL_HOST) ?: $endpointUrl,
                'status' => 'connected',
                'last_error' => null,
            ];

            $connection = new PlatformConnection($data);
            if (!empty($existing)) {
                $connection->setKeyValue($existing[0]->getAttribute('id'));
                $connection->save();
            } else {
                $connection->save();
            }

            $this->log('Custom API Publishing Connected', ['website_id' => $websiteId, 'endpoint' => $endpointUrl]);

            return $this->success(['endpoint_url' => $endpointUrl], 'تم الربط بنجاح - جرّبنا الاتصال وشغّال');
        } catch (Exception $e) {
            Logger::error('Connect Custom API Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ بيانات الاتصال', 500);
        }
    }

    /** POST /api/publishing/disconnect/{website_id} - بيفصل أي نوع اتصال نشر متفعّل لهذا الموقع */
    public function disconnectPublishing(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connection = $this->findPublishingConnection($websiteId);
        if (!$connection) {
            return $this->error('مفيش اتصال نشر لهذا الموقع', 404);
        }

        $connection->setAttribute('status', 'disconnected');
        $connection->setAttribute('access_token', null);
        $connection->save();

        $this->log('Publishing Disconnected', ['website_id' => $websiteId, 'platform' => $connection->getAttribute('platform')]);

        return $this->success([], 'تم فصل الاتصال');
    }

    /**
     * POST /api/ai/article/{id}/publish
     * لو الموقع مش متربط لسه، ممكن يتبعت website_id + بيانات اتصال
     * (WP أو Custom API حسب platform) في نفس الطلب فنربط وننشر مرة واحدة.
     * لو متربط بالفعل، يكفي إرسال website_id.
     */
    public function publishArticle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('المقال غير موجود', 404);
        }

        if ($article->getAttribute('status') !== 'completed' && $article->getAttribute('status') !== 'published') {
            return $this->error('المقال لسه مش جاهز للنشر', 422);
        }

        $websiteId = (int) $this->get('website_id', (int) $article->getAttribute('website_id'));
        if (!$websiteId) {
            return $this->error('اختار الموقع اللي عايز تنشر عليه الأول', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connection = $this->findPublishingConnection($websiteId);

        // مفيش اتصال محفوظ - لو بعت بيانات اتصال جديدة في نفس الطلب، نربط الأول حسب platform المختار
        if (!$connection) {
            $platform = (string) $this->get('platform', 'wordpress');

            if ($platform === 'custom_api') {
                if (!$this->get('endpoint_url')) {
                    return $this->error('الموقع ده لسه مش مربوط بأي طريقة نشر', 409, ['needs_connection' => true]);
                }
                $connectResult = $this->connectCustomApi($params);
            } else {
                if (!$this->get('site_url') || !$this->get('username') || !$this->get('app_password')) {
                    return $this->error('الموقع ده لسه مش مربوط بأي طريقة نشر', 409, ['needs_connection' => true]);
                }
                $connectResult = $this->connectWordPress($params);
            }

            if (!$connectResult['success']) {
                return $connectResult;
            }

            $connection = $this->findPublishingConnection($websiteId);
            if (!$connection) {
                return $this->error('تعذر إتمام الربط', 500);
            }
        }

        $platform = (string) $connection->getAttribute('platform');
        $encryption = new Encryption();

        try {
            (new ContentQualityService($this->db))->assertCurrent($article->toArray(),$websiteId);
            $title = (string) $article->getAttribute('title');
            $markdown = $article->contentForPresentation();
            $excerpt = (string) $article->getAttribute('meta_description');
            $draft = (bool) $this->get('draft', false);

            $imageWarning = null;
            if ($platform === 'wordpress') {
                $credentials = $encryption->decrypt($connection->getAttribute('access_token'));
                [$username, $appPassword] = array_pad(explode(':', $credentials, 2), 2, '');
                $siteUrl = (string) $connection->getAttribute('external_location_id');
                $html = ContentFormatter::markdownToHtml($markdown);
                $publishStatus = $draft ? 'draft' : 'publish';

                $publisher = new WordPressPublisher();
                $existingPostId = $article->getAttribute('wp_post_id');

                // الصورة البارزة: بتتولّد وبتترفع لمكتبة WordPress قبل النشر. أي فشل بيتنشر المقال من غيرها مع تحذير.
                $featuredMedia = null;
                if (class_exists('ArticleImageService') && !$this->get('no_image', false)) {
                    $img = ArticleImageService::make()->prepareForWordPress($article->toArray(), (int) $this->getUserId(), $publisher, $siteUrl, $username, $appPassword);
                    $featuredMedia = $img['media_id'];
                    $imageWarning = $img['warning'];
                    ArticleImageService::refreshModel($article);
                }

                $result = $existingPostId
                    ? $publisher->updatePost($siteUrl, $username, $appPassword, (int) $existingPostId, $title, $html, $excerpt, $featuredMedia)
                    : $publisher->createPost($siteUrl, $username, $appPassword, $title, $html, $excerpt, $publishStatus, $featuredMedia);

                $newPostId = $result['success'] ? ($result['post_id'] ?? $existingPostId) : null;
            } else {
                // custom_api - أي موقع تاني (برمجة خاصة/CMS مش ووردبريس)
                $authToken = $connection->getAttribute('access_token') ? $encryption->decrypt($connection->getAttribute('access_token')) : '';
                $endpointUrl = (string) $connection->getAttribute('external_location_id');

                $publisher = new CustomApiPublisher();
                $result = $publisher->publish($endpointUrl, $authToken, [
                    'article_id' => (int) $article->getAttribute('id'),
                    'title' => $title,
                    'content_html' => ContentFormatter::markdownToHtml($markdown),
                    'content_markdown' => $markdown,
                    'meta_description' => $excerpt,
                    'slug' => (string) $article->getAttribute('slug'),
                    'suggested_keywords' => $article->getSuggestedKeywordsArray(),
                ], false);

                $newPostId = null; // مفيش post_id بمعنى ووردبريس هنا - الموقع نفسه بيتصرف
            }

            if (!$result['success']) {
                $connection->setAttribute('last_error', $result['error'] ?? 'Unknown error');
                $connection->save();

                // حالة نشر واضحة + سبب الفشل (بدل ما يفضل completed ويكتشف العميل الفشل من الـ error بس)
                $article->setAttribute('status', 'publish_failed');
                $article->setAttribute('error_message', $result['error'] ?? 'خطأ غير معروف أثناء النشر');
                $article->save();

                if (class_exists('Notification')) {
                    Notification::notify(
                        (int) $this->getUserId(),
                        'post_failed',
                        'فشل نشر مقال',
                        'مقال "' . $article->getAttribute('title') . '" تعذر نشره: ' . ($result['error'] ?? ''),
                        '/ai/article/' . $article->getAttribute('id')
                    );
                }

                return $this->error('تعذر النشر: ' . ($result['error'] ?? ''), 502);
            }

            if ($platform === 'wordpress') {
                $qualityArticle=$article->toArray();$qualityArticle['website_id']=$websiteId;
                try {
                    $seo=(new ContentQualityService($this->db))->syncWordPress($qualityArticle,$publisher,$siteUrl,$username,$appPassword,(int)$newPostId,(string)($result['url']??''),$draft);
                    if (!$seo['success']) Logger::warning('Article SEO needs plugin update',['article_id'=>$article->getAttribute('id'),'error'=>$seo['error']??'']);
                } catch (Throwable $e) { Logger::warning('Article SEO sync needs review',['error'=>$e->getMessage()]); }
            }

            $article->setAttribute('website_id', $websiteId);
            if ($platform === 'wordpress') {
                $article->setAttribute('wp_post_id', $newPostId);
            }
            // لو موقع custom_api رجّع رابط فعلي استخدمه، غير كده سيب اللي كان محفوظ قبل كده (لو ده تحديث)
            if (!empty($result['url'])) {
                $article->setAttribute('published_url', $result['url']);
            }
            $article->setAttribute('published_at', date('Y-m-d H:i:s'));
            $article->setAttribute('status', 'published');
            $article->save();

            $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
            $connection->setAttribute('last_error', null);
            $connection->save();

            $this->log('Article Published', ['article_id' => $article->getAttribute('id'), 'platform' => $platform, 'url' => $result['url'] ?? null]);

            if (class_exists('Notification')) {
                Notification::notify(
                    (int) $this->getUserId(),
                    'article_published',
                    'تم نشر مقالك بنجاح',
                    'مقال "' . $article->getAttribute('title') . '" اتنشر على موقعك.',
                    $article->getAttribute('published_url') ?: ('/ai/article/' . $article->getAttribute('id'))
                );
            }

            return $this->success([
                'published_url' => $article->getAttribute('published_url'),
                'platform' => $platform,
                'image_warning' => $imageWarning,
                'image_warning_text' => $imageWarning
                    ? ($this->tr('ai.articles.image_warning') . ' ' . $this->tr('ai.articles.image_err.' . $imageWarning))
                    : null,
            ], 'تم النشر بنجاح');
        } catch (Exception $e) {
            Logger::error('Publish Article Error', ['article_id' => $article->getAttribute('id'), 'message' => $e->getMessage()]);
            return $this->error('تعذر النشر', 500);
        }
    }

    // جدولة نشر المقالات - بتستخدم نظام الـ Queue الموجود بالفعل
    // (جدول jobs + cron/process_queue.php) بدل أي نظام جديد. المقال
    // لازم يكون متصل بموقع منشور عليه قبل كده أو مربوط بطريقة نشر
    // بالفعل، لإن وقت التنفيذ الفعلي مفيش تفاعل بشري يجمع بيانات اتصال
    // جديدة لو مش موجودة.
    // ============================================

    /**
     * POST /api/ai/article/{id}/schedule
     * body: { scheduled_at: 'YYYY-MM-DD HH:MM:SS' (توقيت السيرفر), website_id, draft? }
     */
    public function scheduleArticle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('المقال غير موجود', 404);
        }

        if (!in_array($article->getAttribute('status'), ['completed', 'schedule_failed'], true)) {
            return $this->error('المقال لازم يكون جاهز (مش قيد التوليد أو منشور بالفعل) عشان تجدوله', 422);
        }

        $scheduledAtRaw = (string) $this->get('scheduled_at', '');
        $timestamp = $scheduledAtRaw ? strtotime($scheduledAtRaw) : false;
        if (!$timestamp) {
            return $this->error('scheduled_at مطلوب بصيغة تاريخ/وقت صالحة', 400);
        }

        // حد أدنى دقيقتين في المستقبل - أقل من كده مفيش فايدة عملية من
        // الجدولة (الكرون بيشتغل كل دقيقة أصلاً)، وبيقلل احتمال إن
        // الوقت يبقى فات فعليًا لحظة ما الطلب يوصل للسيرفر.
        if ($timestamp < time() + 120) {
            return $this->error('وقت الجدولة لازم يكون بعد دقيقتين على الأقل من دلوقتي', 422);
        }

        $websiteId = (int) $this->get('website_id', (int) $article->getAttribute('website_id'));
        if (!$websiteId) {
            return $this->error('اختار الموقع اللي عايز تجدول النشر عليه', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        // على عكس النشر الفوري، الجدولة مش بتقبل بيانات اتصال جديدة في
        // نفس الطلب - لازم الموقع يكون متصل بالفعل، لإن الـ Job وقت
        // التنفيذ مفيش قدامه مستخدم يدخل باسورد لو الاتصال ناقص.
        $connection = $this->findPublishingConnection($websiteId);
        if (!$connection) {
            return $this->error('الموقع ده لسه مش مربوط بأي طريقة نشر - اربطه الأول من زرار "نشر الآن"', 409, ['needs_connection' => true]);
        }

        try {
            $queue = new QueueManager();
            $delaySeconds = $timestamp - time();

            $jobId = $queue->push(PublishScheduledArticleJob::class, [
                'article_id' => (int) $article->getAttribute('id'),
                'website_id' => $websiteId,
                'draft' => (bool) $this->get('draft', false),
            ], 'default', $delaySeconds);

            if (!$jobId) {
                return $this->error('تعذر جدولة المقال - نظام الطابور غير جاهز، تأكد من تشغيل migration جدول jobs', 500);
            }

            $article->setAttribute('website_id', $websiteId);
            $article->setAttribute('status', 'scheduled');
            $article->setAttribute('scheduled_at', date('Y-m-d H:i:s', $timestamp));
            $article->setAttribute('scheduled_job_id', $jobId);
            $article->setAttribute('error_message', null);
            $article->save();

            $this->log('Article Scheduled', ['article_id' => $article->getAttribute('id'), 'scheduled_at' => date('Y-m-d H:i:s', $timestamp)]);

            return $this->success([
                'scheduled_at' => date('Y-m-d H:i:s', $timestamp),
            ], 'تمت جدولة المقال بنجاح - هيتنشر أوتوماتيك في الموعد ده');
        } catch (Throwable $e) {
            Logger::error('Schedule Article Error', ['article_id' => $article->getAttribute('id'), 'message' => $e->getMessage()]);
            return $this->error('تعذر جدولة المقال', 500);
        }
    }

    /**
     * POST /api/ai/article/{id}/schedule/cancel
     * بيلغي الجدولة لو لسه معلقة (pending) وميتنفذتش، ويرجّع المقال
     * لحالة "جاهز" عادي عشان تقدر تنشره فورًا أو تجدوله تاني بوقت مختلف.
     */
    public function cancelScheduledArticle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('المقال غير موجود', 404);
        }

        if ($article->getAttribute('status') !== 'scheduled') {
            return $this->error('المقال ده مش مجدول أصلاً', 422);
        }

        $jobId = (int) $article->getAttribute('scheduled_job_id');
        if ($jobId > 0) {
            try {
                // حذف مباشر بدل تعديل جدول jobs (اللي ENUM حالته معرّف
                // مسبقًا بدون قيمة 'cancelled') - أبسط وأأمن من تعديل
                // schema الجدول ده لمجرد الإلغاء. الشرط status='pending'
                // بيمنع إننا نحذف مهمة بدأت تتنفذ بالفعل باللحظة دي.
                $this->db->query("DELETE FROM `jobs` WHERE id = ? AND status = 'pending'", [$jobId]);
            } catch (Throwable $e) {
                // لو فشل الحذف لأي سبب، منوقفش الإلغاء المنطقي للمقال -
                // أسوأ سيناريو محتمل: الـ Job القديم يشتغل ويلاقي المقال
                // مرجوع لحالة completed فيتجاهله (مش published) - آمن.
            }
        }

        $article->setAttribute('status', 'completed');
        $article->setAttribute('scheduled_at', null);
        $article->setAttribute('scheduled_job_id', null);
        $article->save();

        return $this->success([], 'تم إلغاء الجدولة');
    }

    /** GET /api/ai/article/{id}/export */
    public function exportArticle(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $article = (new AIArticle())->find((int) ($params['id'] ?? 0));
        if (!$article || (int) $article->getAttribute('user_id') !== (int) $this->getUserId()) {
            return $this->error('المقال غير موجود', 404);
        }

        $title = (string) $article->getAttribute('title');
        $metaDescription = (string) $article->getAttribute('meta_description');
        $content = $article->contentForPresentation();

        $fileContent = "# {$title}\n\n> {$metaDescription}\n\n{$content}\n";
        $filename = ($article->getAttribute('slug') ?: 'article') . '.md';

        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($fileContent));
        echo $fileContent;
        exit;
    }
}
