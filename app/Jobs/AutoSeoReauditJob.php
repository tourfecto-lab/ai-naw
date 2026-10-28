<?php

/**
 * Tourfecto - Auto SEO Re-Audit Job (إعادة التدقيق الدورية)
 * @version 1.0.0
 *
 * بينفّذ دورة إعادة تدقيق كاملة لموقع مربوط، في الخلفية (مش في HTTP request):
 * إعادة فحص SEO -> تطبيق الإصلاحات المؤهلة تلقائيًا حسب وضع Auto-Pilot ->
 * إبلاغ محركات البحث فورًا (IndexNow) -> تسجيل لقطة قبل/بعد في التقارير.
 *
 * بيشتغل من طابور المهام (cron/auto_seo_scheduler.php بيضيفه)، وIdempotent:
 * كل تشغيل بيعمل wo_audit جديد طبيعي والإصلاحات بتتطبق بنفس منطق
 * AutoSeoEmbedService::applyFix (ON DUPLICATE KEY UPDATE - مفيش تكرار فعلي).
 */
class AutoSeoReauditJob implements QueueJobInterface
{
    /**
     * أمان دفاعي: نفس نمط $optionalNewClassFiles في public_html/index.php.
     * الكلاسات دي ممكن تكون مش مسجّلة في classmap بتاع composer على السيرفر،
     * فلازم تتحمّل هنا عشان الـ worker يقدر يناديها.
     */
    private function loadDependencies(): void
    {
        $deps = [
            '/Controllers/WebsiteOptimizerController.php',
            '/Services/AutoSeo/AutoSeoEmbedService.php',
            '/Services/Seo/SeoPerformanceService.php',
            '/Services/Seo/SeoSchedulerService.php',
            '/Services/Seo/SeoAbTestService.php',
            '/Services/Indexing/IndexNowService.php',
            '/Services/SearchConsole/GoogleSearchConsoleAPI.php',
            '/Services/Analytics/GoogleAnalyticsAPI.php',
        ];
        foreach ($deps as $rel) {
            $file = APP_PATH . $rel;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }

    public function handle(array $payload): void
    {
        $this->loadDependencies();

        $websiteId = (int) ($payload['website_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($websiteId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('AutoSeoReauditJob: missing website_id/user_id');
        }

        $db = Database::getInstance();

        $siteRows = $db->query("SELECT * FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        if (empty($siteRows) || (int) $siteRows[0]['is_connected'] !== 1) {
            return; // الموقع اتشال أو اتصل - مفيش حاجة نعملها
        }
        $site = $siteRows[0];

        $userRows = $db->query("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (empty($userRows)) {
            return; // المستخدم اتحذف بعد الجدولة
        }

        // نفس آلية AuthMiddleware: أي Controller بيتنشأ بعد السطر ده هيلاقي
        // مستخدم "مصادق" من $_SERVER['auth_user']، وwebsite_id من $_GET.
        $_SERVER['auth_user_id'] = $userId;
        $_SERVER['auth_user'] = $userRows[0];
        $_GET['website_id'] = (string) $websiteId;

        $auditId = null;
        $overallScore = null;
        $findingsTotal = 0;
        $applied = 0;

        try {
            $controller = new WebsiteOptimizerController();
            $auditResult = $controller->runAudit();

            if (empty($auditResult['success'])) {
                throw new RuntimeException('re-audit failed: ' . ($auditResult['error'] ?? 'unknown'));
            }

            $auditId = (int) ($auditResult['data']['audit']['id'] ?? 0);
            $overallScore = (float) ($auditResult['data']['audit']['overall_score'] ?? 0);
            $findings = $auditResult['data']['findings'] ?? [];
            $findingsTotal = count($findings);

            // تطبيق الإصلاحات المؤهلة تلقائيًا حسب وضع Auto-Pilot الحالي
            $embed = new AutoSeoEmbedService($db);
            $mode = (string) ($site['auto_pilot_mode'] ?? 'conservative');
            // مركز الأتمتة: الاختيار بتاع العميل هو اللي بيحكم. لو مش "يدوي"
            // بنرشّح كل الإصلاحات الآمنة (balanced)، وكل إصلاح بيعدّي على
            // البوابة: يتطبق دلوقتي أو يستنى موافقة حسب الوضع والفئة.
            $engine = class_exists('AutomationEngine') ? AutomationEngine::instance() : null;
            if ($engine) {
                $policyMode = $engine->getPolicy($userId, 'website_fixes')['mode'];
                if ($policyMode === 'manual') {
                    $mode = 'off';
                } elseif ($mode !== 'aggressive') {
                    $mode = 'balanced';
                }
            }
            $appliedFields = [];
            foreach ($findings as $finding) {
                if (!$embed->shouldAutoApply($finding, $mode)) {
                    continue;
                }
                $gate = null;
                if ($engine) {
                    $finding['audit_id'] = $finding['audit_id'] ?? $auditId;
                    $gate = $engine->gate(
                        $userId,
                        $websiteId,
                        'website_fixes',
                        AutomationCatalog::websiteFixBucket($finding),
                        'auto_seo_finding',
                        $websiteId . ':' . ($finding['field_name'] ?? $finding['check_key'] ?? '') . ':' . md5((string) ($finding['suggested_value'] ?? $finding['fix_snippet'] ?? '')),
                        [
                            'title' => (string) ($finding['title'] ?? $finding['field_name'] ?? 'إصلاح'),
                            'context' => (string) ($finding['description'] ?? ''),
                            'draft' => (string) ($finding['suggested_value'] ?? $finding['fix_snippet'] ?? ''),
                            'payload' => ['kind' => 'embed', 'website_id' => $websiteId, 'finding' => $finding],
                        ]
                    );
                    if ($gate['action'] !== 'execute') {
                        continue;
                    }
                }
                $res = $embed->applyFix($userId, $websiteId, $finding, 'scheduled_cron', $mode);
                if ($gate) {
                    $engine->complete($gate['decision_id'] ?? null, !empty($res['success']), $res['error'] ?? null);
                }
                if (!empty($res['success'])) {
                    $applied++;
                    $appliedFields[] = $finding['field_name'] ?? null;
                }
            }

            // IndexNow بعد التطبيق (لو مفعّل)
            if ($applied > 0) {
                $this->submitToIndexNow($websiteId);
            }

            if (class_exists('Logger')) {
                Logger::info('AutoSeoReauditJob finished', [
                    'website_id' => $websiteId,
                    'audit_id' => $auditId,
                    'findings' => $findingsTotal,
                    'applied' => $applied,
                ]);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('AutoSeoReauditJob error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            }
            throw $e; // نرجّع للطابور يعيد المحاولة
        }

        // تسجيل لقطة قبل/بعد في التقارير (best-effort)
        try {
            if (class_exists('SeoPerformanceService')) {
                $perf = new SeoPerformanceService($db);
                $perf->snapshot($websiteId, $userId, $auditId, $overallScore, $findingsTotal, $applied, [], 'scheduled_cron');
            }
        } catch (Throwable $e) {
            // لقطة اختيارية - مش حاسمة
        }

        // تحديث آخر موعد إعادة تدقيق
        try {
            if (class_exists('SeoSchedulerService')) {
                (new SeoSchedulerService($db))->markReaudited($websiteId);
            } else {
                $db->exec("UPDATE websites SET last_seo_audit_at = NOW() WHERE id = ?", [$websiteId]);
            }
        } catch (Throwable $e) {
            // لا شيء حاسم
        }
    }

    /** إبلاغ IndexNow بعد التطبيق (best-effort، لا يكسر الدورة) */
    private function submitToIndexNow(int $websiteId): void
    {
        try {
            $db = Database::getInstance();
            $site = $db->query(
                "SELECT main_url, indexnow_key, indexnow_enabled FROM websites WHERE id = ? LIMIT 1",
                [$websiteId]
            );
            if (empty($site) || empty($site[0]['indexnow_key']) || (int) $site[0]['indexnow_enabled'] !== 1) {
                return;
            }
            $host = parse_url($site[0]['main_url'] ?? '', PHP_URL_HOST);
            if (!$host) {
                return;
            }
            $base = rtrim((string) $site[0]['main_url'], '/');
            (new IndexNowService())->submitUrls($host, (string) $site[0]['indexnow_key'], [$base . '/']);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
