<?php

/**
 * Tourfecto - Onboarding Background Audit Job
 * @version 1.0.0
 *
 * Phase 19. بينفّذ خلف الكواليس كامل الـ Setup اللي بتعمله الـ Wizard بعد ما
 * العميل يدوس "ابدأ التحليل": تسجيل المنافسين في نظام Competitor Intelligence
 * (Phase 7) + تشغيل تدقيق SEO حقيقي (Website Optimizer - Phase 5) + محاولة
 * توليد خطة نمو 90 يوم (SEO Strategy Agent - Phase 14).
 *
 * ليه في الخلفية مش في نفس الـ HTTP request؟
 * --------------------------------------------------
 * الـ Audit بيجيب صفحة الموقع كاملة وبيكشف الروابط المكسورة - ممكن ياخد
 * 15-60 ثانية حسب حجم الموقع. في الـ wizard الأصلي (Phase 18) كان بيترشل
 * جوه نفس الـ request، فالعميل كان واقف على شاشة "بنجهّز حسابك" من غير أي
 * تحديث، وأي timeout من الـ webserver كان بيكسر الـ Onboarding بالكامل.
 * الموديول ده بيحط الشغل كله في job على الطابور (نفس نظام الـ Queue
 * المستخدم في Monitoring / GBP / Social) فالطلب بيرجع فورًا والواجهة بتعمل
 * polling على /api/onboarding/status لحد ما النتيجة تخلّص.
 *
 * Idempotent بأمان: المنافسين بيتسجلوا بس لو الدومين مش موجود أصلًا لنفس
 * الموقع (مفيش تكرار لو الـ job اتعاد محاولته)، وكل تشغيل بيعمل wo_audit
 * جديد طبيعي (زي ما أي تدقيق يدوي بيعمل).
 */
class OnboardingAuditJob implements QueueJobInterface
{
    /**
     * أمان دفاعي: نفس نمط $optionalNewClassFiles في public_html/index.php.
     * الـ cron bootstrap بيلود app/Jobs/*.php تلقائيًا، بس الكلاسات دي ممكن
     * تكون مش مسجّلة في classmap بتاع composer (السيرفر مش بيعمل
     * dump-autoload)، فلازم تتحمّل هنا عشان الـ worker يقدر يناديها.
     * الفول تلقائي لو ملف مش موجود (class_exists بيحرس التنفيذ في runSetup).
     */
    private function loadDependencies(): void
    {
        $deps = [
            '/Controllers/OnboardingController.php',
            '/Controllers/WebsiteOptimizerController.php',
            '/Controllers/SeoStrategyController.php',
            '/Services/CompetitorIntelligence/SsrfGuard.php',
            '/Services/CompetitorIntelligence/CompetitorAnalysisService.php',
            '/Models/ActivityLog.php',
            '/Services/CompetitorIntelligence/WebsiteSnapshotFetcher.php',
            '/Jobs/SendOnboardingCompletionEmailJob.php',
            '/Services/Mailer.php',
            '/Core/Queue/QueueManager.php',
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

        $userId = (int) ($payload['user_id'] ?? 0);
        $websiteId = (int) ($payload['website_id'] ?? 0);

        if ($userId <= 0 || $websiteId <= 0) {
            throw new InvalidArgumentException('OnboardingAuditJob: missing user_id/website_id');
        }

        $db = Database::getInstance();
        $userRows = $db->query("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (empty($userRows)) {
            // المستخدم اتحذف بعد ما اتجدولت المهمة - مش خطأ يستاهل Retry
            return;
        }

        // نفس الآلية اللي بيستخدمها AuthMiddleware: أي Controller بيتنشأ بعد
        // السطر ده هيلاقي مستخدم "مصادق" من $_SERVER['auth_user'] فعلًا.
        $_SERVER['auth_user_id'] = $userId;
        $_SERVER['auth_user'] = $userRows[0];

        $controller = new OnboardingController();
        $result = $controller->runSetup([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'competitors' => $payload['competitors'] ?? [],
        ]);

        if (class_exists('Logger')) {
            Logger::info('Onboarding background setup finished', [
                'website_id' => $websiteId,
                'audit_success' => (bool) ($result['audit'] ?? null),
                'competitors_added' => count($result['competitors_added'] ?? []),
                'growth_plan_ready' => (bool) ($result['growth_plan'] ?? null),
            ]);
        }

        // Phase 20 - فونيل Analytics: اكتمال الـOnboarding فعليًا (بعد ما
        // الـAudit و خطة النمو خلصوا في الخلفية، مش بس لحظة الإرسال).
        if (class_exists('ActivityLog')) {
            try {
                ActivityLog::record('onboarding', 'onboarding.completed', [
                    'user_id' => $userId,
                    'subject_type' => 'website',
                    'subject_id' => $websiteId,
                    'meta' => [
                        'audit_success' => (bool) ($result['audit'] ?? null),
                        'growth_plan_ready' => (bool) ($result['growth_plan'] ?? null),
                        'competitors_added' => count($result['competitors_added'] ?? []),
                    ],
                ]);
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('OnboardingAuditJob ActivityLog failed: ' . $e->getMessage());
                }
            }
        }

        // Phase 20: إشعار فوري للمستخدم إن النتيجة جاهزة (زي إشعارات
        // "تم تحليل موقعك" في المنصات العالمية). بأمان تام: لو جدول
        // notifications لسه مش متعمل على السيرفر بنتجاهل بصمت.
        $this->notifyCompletion($userId, $websiteId, $result);
    }

    /**
     * إشعار فوري بأن الإعداد خلص. بيحترم لغة المستخدم المفضلة، وبيفشل
     * بصمت (من غير ما يكسر الجوب) لو جدول notifications مش موجود.
     * كمان بيدفع إيميل "التحليل جاهز" في الطابور (SendOnboardingCompletionEmailJob)
     * لو البريد مظبوط - نفس الـfollow-up في المنتجات العالمية.
     */
    private function notifyCompletion(int $userId, int $websiteId, array $result): void
    {
        try {
            $db = Database::getInstance();
            $userRows = $db->query("SELECT language FROM users WHERE id = ? LIMIT 1", [$userId]);
            $lang = strtolower((string) ($userRows[0]['language'] ?? 'ar'));
            $lang = in_array($lang, ['ar', 'en', 'fr', 'de'], true) ? $lang : 'ar';

            $auditReady = (bool) ($result['audit'] ?? null);
            $growthReady = (bool) ($result['growth_plan'] ?? null);

            if ($lang === 'ar') {
                $title = $auditReady ? 'تحليل موقعك جاهز!' : 'انتهى إعداد حسابك';
                $body = $auditReady
                    ? 'خلصنا تحليل موقعك وبنينا خطة نموك - افتح لوحة النمو عشان تشوف النتيجة والخطوات.'
                    : 'اتسجلنا بيانات نشاطك ومنافسيك. التحليل العميق مستمر في الخلفية ولوحة النمو هتتحدّث تلقائيًا.';
            } elseif ($lang === 'en') {
                $title = $auditReady ? 'Your website analysis is ready!' : 'Your account is set up';
                $body = $auditReady
                    ? 'We finished analyzing your website and built your growth plan - open the Growth dashboard to see results and next steps.'
                    : 'We saved your business and competitors. Deep analysis continues in the background and the Growth dashboard will update automatically.';
            } elseif ($lang === 'fr') {
                $title = $auditReady ? 'L\'analyse de votre site est prête !' : 'Votre compte est configuré';
                $body = $auditReady
                    ? 'Nous avons terminé l\'analyse de votre site et construit votre plan de croissance - ouvrez le tableau de bord Croissance.'
                    : 'Vos informations et concurrents sont enregistrés. L\'analyse approfondie continue en arrière-plan.';
            } else {
                $title = $auditReady ? 'Ihre Website-Analyse ist fertig!' : 'Ihr Konto ist eingerichtet';
                $body = $auditReady
                    ? 'Wir haben Ihre Website analysiert und Ihren Wachstumsplan erstellt - öffnen Sie das Wachstums-Dashboard.'
                    : 'Ihre Daten und Wettbewerber wurden gespeichert. Die Analyse läuft im Hintergrund weiter.';
            }

            $db->exec(
                "INSERT INTO notifications (user_id, type, title, body, link, read_at, created_at)
                 VALUES (?, 'onboarding_complete', ?, ?, '/dashboard/growth', NULL, NOW())",
                [$userId, $title, $body]
            );
        } catch (Throwable $e) {
            // جدول notifications مش موجود على السيرفر لسه - إشعار اختياري مش حاسم
        }

        // إيميل الـfollow-up: بيدفع الجوب في الطابور من غير ما نحظر - لو
        // الطابور/البريد مش متاحين بنتجاهل بصمت (الإشعار الداخلي يكفي).
        try {
            $queue = new QueueManager();
            if ($queue->isReady() && class_exists('SendOnboardingCompletionEmailJob')) {
                $queue->push(SendOnboardingCompletionEmailJob::class, [
                    'user_id' => $userId,
                    'website_id' => $websiteId,
                ]);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Onboarding email dispatch failed: ' . $e->getMessage());
            }
        }
    }
}
