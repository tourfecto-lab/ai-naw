<?php

// Serve static files directly when running under the PHP built-in dev server.
// (Returning false lets PHP serve the file as-is; production Apache/Nginx are unaffected.)
if (PHP_SAPI === 'cli-server') {
    $__staticPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $__staticFile = __DIR__ . $__staticPath;
    if ($__staticPath !== '/' && is_file($__staticFile)) {
        return false;
    }
}

/**
 * Tourfecto - نقطة الدخول الرئيسية
 * @version 1.0.1
 *
 * ملاحظة: هذا الملف تمت إعادة بنائه بالكامل بتاريخ 2026-07-09
 * لأن النسخة الأصلية استُبدلت بالخطأ بأمر Terminal (heredoc) بدلاً من كود PHP فعلي،
 * مما كان يعطّل الموقع بالكامل.
 */

// ============================================
// 1. المسارات الأساسية
// ============================================
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_ROOT', ROOT_PATH);
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');

// ============================================
// 2. تحميل Composer Autoload
// ============================================
require_once APP_PATH . '/Config/runtime_requirements.php';

$__tourfectoMissingExtensions = tourfecto_missing_runtime_extensions();
if ($__tourfectoMissingExtensions) {
    $message = 'Tourfecto requires missing PHP extensions: ' . implode(', ', array_values($__tourfectoMissingExtensions));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(78);
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Tourfecto unavailable</title>'
       . '<style>body{font-family:Arial,sans-serif;background:#060A13;color:#F2F4F8;display:grid;place-items:center;min-height:100vh}main{max-width:680px;padding:32px;text-align:center}p{color:#AEB8C8;line-height:1.7}</style>'
       . '<main><h1>Tourfecto is temporarily unavailable</h1><p>تم اكتشاف متطلب تشغيل ناقص في خادم PHP. يرجى تفعيل الإضافات المطلوبة ثم إعادة تحميل الموقع.</p></main>';
    exit;
}

tourfecto_register_fallback_autoloader();

require_once ROOT_PATH . '/vendor/autoload.php';

// ============================================
// 2.1. كلاسات جديدة مش مسجّلة لسه في classmap بتاع Composer
// (السيرفر ده مفيهوش Terminal/SSH لتشغيل composer dump-autoload،
// فبنحمّلها يدويًا هنا لحد ما تتسجل تلقائيًا في مرة رفع لاحقة عادية)
// كل واحد منهم محاط بـ file_exists عمدًا: لو ملف لسه ما اترفعش، الميزة
// دي بس هي اللي هتتعطل (Controller not found) - الموقع كله يفضل شغال
// بدل ما يقع بالكامل بـ 500 خام قبل حتى ما يوصل لأي try/catch.
// ============================================
$optionalNewClassFiles = [
    APP_PATH . '/Services/SearchConsole/GoogleSearchConsoleAPI.php',
    APP_PATH . '/Controllers/SearchConsoleController.php',
    APP_PATH . '/Services/Publishing/ContentFormatter.php',
    APP_PATH . '/Services/Publishing/WordPressPublisher.php',
    APP_PATH . '/Services/Publishing/CustomApiPublisher.php',
    APP_PATH . '/Controllers/IntegrationsController.php',
    APP_PATH . '/Services/AvatarUploadHandler.php',
    APP_PATH . '/Models/ContactSubmission.php',
    APP_PATH . '/Services/Mailer.php',
    APP_PATH . '/Models/PasswordResetToken.php',
    APP_PATH . '/Models/EmailVerificationToken.php',
    APP_PATH . '/Services/SocialMedia/MetaSocialAPI.php',
    APP_PATH . '/Services/Ads/MetaAdsAPI.php',
    APP_PATH . '/Services/Ads/GoogleAdsAPI.php',
    APP_PATH . '/Services/Ads/AdPiiHasher.php',
    APP_PATH . '/Jobs/SendAdConversionJob.php',
    APP_PATH . '/Jobs/SendBookingConfirmationJob.php',
    APP_PATH . '/Services/OAuth/MetaOAuthClient.php',
    // تسجيل الدخول الاجتماعي (Google/Facebook/Microsoft/Apple): بيتنادوا من
    // AuthController::socialRedirect/socialCallback ومش في classmap بتاع composer،
    // فكان بيطلع "Class SocialLoginClient not found" ويظهر للمستخدم
    // "تعذر بدء تسجيل الدخول بواسطة Google".
    APP_PATH . '/Services/OAuth/SocialLoginClient.php',
    APP_PATH . '/Services/OAuth/AppleSignInClient.php',
    APP_PATH . '/Models/SubscriptionPlan.php',
    APP_PATH . '/Models/WalletTransaction.php',
    APP_PATH . '/Models/WalletRechargeCard.php',
    // Billing Phase 19/21: WalletService بينادي SubscriptionPeriod
    // (renewSubscriptionFromBalance) وBillingRules (planChangeCharge) مباشرة
    // من غير class_exists - لازم يتحمّلوا قبله هنا وإلا "Class not found"
    // على أي تجديد/تغيير باقة (السيرفر مفيهوش composer dump-autoload).
    APP_PATH . '/Services/Subscription/SubscriptionPeriod.php',
    APP_PATH . '/Services/Subscription/BillingRules.php',
    APP_PATH . '/Services/Subscription/WalletService.php',
    APP_PATH . '/Controllers/WalletController.php',
    APP_PATH . '/Models/FaqItem.php',
    APP_PATH . '/Models/ReviewRequest.php',
    APP_PATH . '/Models/ReviewRequestSettings.php',
    APP_PATH . '/Models/ReviewRequestOptOut.php',
    APP_PATH . '/Models/ReviewRequestTemplate.php',
    APP_PATH . '/Services/Reputation/ReviewRequestService.php',
    APP_PATH . '/Services/Reputation/ReviewTopicExtractor.php',
    APP_PATH . '/Controllers/ReviewRequestController.php',
    APP_PATH . '/Models/SystemSetting.php',
    APP_PATH . '/Services/System/SystemSettingsService.php',
    APP_PATH . '/Services/SiteLogoUploadHandler.php',
    APP_PATH . '/Models/FeatureFlag.php',
    APP_PATH . '/Models/UserFeatureOverride.php',
    APP_PATH . '/Services/System/FeatureFlagService.php',
    APP_PATH . '/Models/AiConversation.php',
    APP_PATH . '/Models/AiMessage.php',
    APP_PATH . '/Services/AiAssistantService.php',
    APP_PATH . '/Controllers/AiAssistantController.php',
    APP_PATH . '/Models/GeneratedWebsite.php',
    APP_PATH . '/Services/WebsiteBuilderService.php',
    APP_PATH . '/Controllers/WebsiteBuilderController.php',
    APP_PATH . '/Controllers/ServicesController.php',
    // Partner API (المرحلة 2 من خطة API Gateway - 2026-08-06)
    APP_PATH . '/Models/PartnerApiKey.php',
    APP_PATH . '/Middleware/PartnerAuthMiddleware.php',
    APP_PATH . '/Middleware/ApiKeyScopeMiddleware.php',
    APP_PATH . '/Controllers/PartnerController.php',
    APP_PATH . '/Controllers/PartnerKeyController.php',
    APP_PATH . '/Security/Csrf.php',
    // JWT Gateway (المرحلة 2): الكلاسات دي بتتنادى من AuthController::login()
    // (issueJwtTokenPair) لكنها مش موجودة في classmap القديم بتاع composer
    // (السيرفر مفيهوش SSH لتشغيل composer dump-autoload)، فكانت أي محاولة
    // دخول ناجحة (بعد ما تعدّي فحص الباسورد) بتقع بخطأ فادح "Class not found"
    // غير ملتقط بـ catch(Exception) في login()، فيرجع للفرونت إند رد مش JSON
    // خالص، وده اللي بيظهر للمستخدم كـ "تعذر الاتصال بالخادم".
    APP_PATH . '/Core/Security/JwtService.php',
    APP_PATH . '/Models/RefreshToken.php',
    // Creative Studio: توليد فيديو قصير بالذكاء الاصطناعي (Veo) + نشر
    // الوسائط مباشرة على السوشيال ميديا (2026-08-07)
    APP_PATH . '/Services/AI/VeoClient.php',
    APP_PATH . '/Jobs/GenerateVideoJob.php',
    // AI Chat Platform: الكلاس ده بينادى من ChatManager::__construct()
    // لكنه مش مسجّل في classmap القديم بتاع composer (السيرفر مفيهوش SSH
    // لتشغيل composer dump-autoload)، فكانت أي زيارة لصفحة /chat بتقع
    // بـ Fatal Error "Class UnifiedInboxService not found" وتظهر للمستخدم
    // كصفحة "حصل خطأ غير متوقع" (2026-08-09).
    APP_PATH . '/Services/Chat/UnifiedInboxService.php',
    // UnifiedInboxService::__construct() بينادي new AiChatConversation()
    // على طول - فبرضه لازم يتحمّل هنا، وإلا هيقع بنفس النوع من الخطأ
    // بس على كلاس تاني (AiChatConversation not found) حتى بعد ما نضيف
    // UnifiedInboxService نفسه (2026-08-09).
    APP_PATH . '/Models/AiChatConversation.php',
    // تلات قنوات شات دول بيتنادوا جوه ChatManager (Messenger/Instagram/
    // Email) ومش مسجّلين في الـ classmap برضه - مش هيوقعوا صفحة /chat
    // نفسها (بيتحمّلوا وقت الاستخدام الفعلي بس)، بس بنضيفهم هنا زي بقيّة
    // كلاسات الشات عشان نقفل الباب ده تمامًا.
    APP_PATH . '/Services/Chat/MessengerAPI.php',
    APP_PATH . '/Services/Chat/InstagramAPI.php',
    APP_PATH . '/Services/Chat/EmailChannelAPI.php',
    APP_PATH . '/Services/AI/Providers/AIProviderInterface.php',
    APP_PATH . '/Services/AI/Providers/OpenAICompatibleProvider.php',
    APP_PATH . '/Services/AI/Providers/GeminiProvider.php',
    APP_PATH . '/Services/AI/Providers/OpenAIProvider.php',
    APP_PATH . '/Services/AI/Providers/DeepSeekProvider.php',
    APP_PATH . '/Services/AI/Providers/KimiProvider.php',
    APP_PATH . '/Services/AI/Providers/AnthropicProvider.php',
    APP_PATH . '/Services/AI/Providers/AIProviderManager.php',
    APP_PATH . '/Services/AI/KnowledgeBaseService.php',
    APP_PATH . '/Services/AI/AIConversationEngine.php',
    APP_PATH . '/Services/AI/LeadScoringService.php',
    APP_PATH . '/Services/AI/FollowUpAutomationService.php',
    APP_PATH . '/Services/AI/AiAnalyticsService.php',
    APP_PATH . '/Services/AI/AiReplySuggestionsService.php',
    APP_PATH . '/Services/AI/LearningLoopService.php',
    APP_PATH . '/Models/AiUsageLog.php',
    APP_PATH . '/Models/AiKnowledgeBase.php',
    APP_PATH . '/Models/AiCustomerMemory.php',
    APP_PATH . '/Models/AiLead.php',
    APP_PATH . '/Models/AiFollowup.php',
    APP_PATH . '/Models/AiFollowupRule.php',
    APP_PATH . '/Models/AiCustomTag.php',
    APP_PATH . '/Services/AI/KnowledgeBaseService.php',
    APP_PATH . '/Services/AI/AIConversationEngine.php',
    APP_PATH . '/Services/AI/LeadScoringService.php',
    APP_PATH . '/Services/AI/BusinessHoursService.php',
    APP_PATH . '/Services/AI/FollowUpAutomationService.php',
    APP_PATH . '/Services/AI/AiAnalyticsService.php',
    APP_PATH . '/Services/AI/AiReplySuggestionsService.php',
    APP_PATH . '/Controllers/AiKnowledgeBaseController.php',
    APP_PATH . '/Controllers/ChatInboxController.php',
    APP_PATH . '/Controllers/AiLeadController.php',
    APP_PATH . '/Controllers/AiFollowupSettingsController.php',
    APP_PATH . '/Controllers/AiAnalyticsController.php',
    APP_PATH . '/Controllers/AiCustomTagController.php',
    APP_PATH . '/Controllers/AiLearningController.php',
    APP_PATH . '/Models/AiQuote.php',
    APP_PATH . '/Controllers/AiQuoteController.php',
    // OTA Integration: نفس المشكلة بالظبط - الكنترولر ده مش مسجّل في
    // classmap القديم، فأي طلب لـ /api/ota/status كان بيرمي
    // "Controller OTAController not found" (2026-08-09).
    APP_PATH . '/Controllers/OTAController.php',
    // OTA (Item 1, 2026-08-31): عملاء OTA + ربط حجوزات OTA بالإيرادات.
    // OtaBookingService كلاس جديد مش في classmap القديم على السيرفر.
    APP_PATH . '/Services/OTA/GetYourGuideAPI.php',
    APP_PATH . '/Services/OTA/ViatorAPI.php',
    APP_PATH . '/Services/OTA/OtaBookingService.php',
    // White-Label (Module 2, 2026-08-31): AgencyInvitation كلاس جديد
    // مش في classmap القديم على السيرفر - يستخدمه AgencyService/AgencyController
    // في تدفّق دعوات العملاء.
    APP_PATH . '/Models/AgencyInvitation.php',
    // ============================================
    // Competitor Intelligence (موديول موحّد جديد - 2026-08-09)
    // كل الكلاسات دي جديدة على المشروع ومش موجودة في classmap القديم
    // بتاع composer، فلازم تتحمّل يدويًا هنا زي بقيّة الكلاسات فوق،
    // وإلا هتقع صفحة /competitor-intelligence أو أي API بتاعها بنفس
    // نوع الخطأ اللي كان بيحصل في /chat.
    // ملحوظة على الترتيب: الـ Interface لازم يتحمّل قبل أي كلاس بيعمله
    // implements (GooglePlacesDiscoverySource, NullDiscoverySource,
    // WebsiteOnboardingDiscoverySource).
    // ============================================
    APP_PATH . '/Core/Contracts/CompetitorDiscoverySourceInterface.php',
    APP_PATH . '/Core/Contracts/KeywordRankingSourceInterface.php',
    APP_PATH . '/Services/CompetitorIntelligence/SsrfGuard.php',
    APP_PATH . '/Services/CompetitorIntelligence/WebsiteSnapshotFetcher.php',
    APP_PATH . '/Services/CompetitorIntelligence/ChangeDetectionService.php',
    APP_PATH . '/Services/CompetitorIntelligence/MonitoringEngine.php',
    APP_PATH . '/Services/CompetitorIntelligence/SitemapMonitor.php',
    APP_PATH . '/Services/CompetitorIntelligence/NullDiscoverySource.php',
    APP_PATH . '/Services/CompetitorIntelligence/NullKeywordRankingSource.php',
    APP_PATH . '/Services/CompetitorIntelligence/KeywordRankingService.php',
    APP_PATH . '/Services/CompetitorIntelligence/ProductPriceTrackerService.php',
    APP_PATH . '/Services/CompetitorIntelligence/BattlecardService.php',
    APP_PATH . '/Services/CompetitorIntelligence/WebsiteOnboardingDiscoverySource.php',
    APP_PATH . '/Services/CompetitorIntelligence/GooglePlacesDiscoverySource.php',
    APP_PATH . '/Services/CompetitorIntelligence/CompetitorDiscoveryService.php',
    APP_PATH . '/Services/CompetitorIntelligence/CompetitorTrackingService.php',
    APP_PATH . '/Services/CompetitorIntelligence/BenchmarkingService.php',
    APP_PATH . '/Services/CompetitorIntelligence/ThreatOpportunityService.php',
    APP_PATH . '/Services/CompetitorIntelligence/AlertService.php',
    APP_PATH . '/Services/CompetitorIntelligence/AICompetitiveAnalyst.php',
    APP_PATH . '/Services/CompetitorIntelligence/ReportService.php',
    APP_PATH . '/Services/CompetitorIntelligence/CiPermissions.php',
    APP_PATH . '/Services/CompetitorIntelligence/CompetitorAnalysisService.php',
    APP_PATH . '/Models/CiDiscoveryCandidate.php',
    APP_PATH . '/Models/CiSnapshot.php',
    APP_PATH . '/Models/CiChange.php',
    APP_PATH . '/Models/CiWatchlistItem.php',
    APP_PATH . '/Models/CiAlert.php',
    APP_PATH . '/Models/CiScorecard.php',
    APP_PATH . '/Models/CiInsight.php',
    APP_PATH . '/Models/CiReport.php',
    APP_PATH . '/Models/CiUserPreference.php',
    APP_PATH . '/Models/CiKeywordRanking.php',
    APP_PATH . '/Models/CiProductPrice.php',
    APP_PATH . '/Models/CiBattlecard.php',
    APP_PATH . '/Jobs/MonitorCompetitorJob.php',
    APP_PATH . '/Jobs/SendCompetitorAlertEmailJob.php',
    APP_PATH . '/Jobs/SendOnboardingCompletionEmailJob.php',
    // Profile Center Phase 9 (2026-08-10): Data Export
    APP_PATH . '/Jobs/ExportUserDataJob.php',
    APP_PATH . '/Controllers/CompetitorIntelligenceController.php',
    // TOURFECTO AI REVENUE INTELLIGENCE (2026-08-09) - موديول مستقل، شوف CHANGELOG.md
    APP_PATH . '/Models/RevaiForecast.php',
    APP_PATH . '/Models/RevaiInsight.php',
    APP_PATH . '/Models/RevaiAiQuery.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueDataGateway.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueOverviewService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAnomalyService.php',
    APP_PATH . '/Services/RevenueIntelligence/CustomerRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/PipelineRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueActionService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueActionExecutor.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAssistantService.php',
    APP_PATH . '/Services/RevenueIntelligence/ExecutiveSummaryService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightPersister.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCacheService.php',
    // v1.4.0 (2026-08-15): Copilot + Retention - كلاسات جديدة مش في classmap
    // بتاع composer على السيرفر (مفيهوش SSH لتشغيل composer dump-autoload)،
    // فلازم تتحمّل يدويًا زي باقي كلاسات الموديول. الترتيب مهم: الـController
    // بينادي new RevenueRetentionService وnew RevenueCopilotService (اللي
    // بيستخدم GeminiClient جوه RevenueAssistantService::askWithCopilot).
    APP_PATH . '/Services/RevenueIntelligence/RevenueRetentionService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCopilotService.php',
    // v1.5.0 (2026-08-16): Subscriptions (MRR/ARR/NRR/GRR) + Deal-level
    // forecast & attribution + Benchmarks & Churn + Stripe mapper - كلاسات
    // جديدة مش في classmap composer (لا SSH)، تتحمّل يدويًا بنفس النمط.
    // الترتيب مهم: Controller الجديد بيناديهم في الـ actions.
    APP_PATH . '/Services/RevenueIntelligence/BizSubscriptionService.php',
    APP_PATH . '/Services/RevenueIntelligence/DealLevelForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueBenchmarkService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueChurnService.php',
    APP_PATH . '/Services/RevenueIntelligence/StripeRevenueMapper.php',
    // v1.6.0 (2026-08-17): Dashboard personalization + Stripe webhook live -
    // كلاسات جديدة مش في classmap composer (لا SSH)، تتحمّل يدويًا بنفس النمط.
    APP_PATH . '/Services/RevenueIntelligence/RevenueDashboardService.php',
    APP_PATH . '/Services/RevenueIntelligence/StripeWebhookService.php',
    // G7 (2026-08-29): Quotas/Goals - كلاس جديد مش في classmap composer
    // (لا SSH)، يتحمّل يدويًا بنفس النمط.
    APP_PATH . '/Services/RevenueIntelligence/RevenueQuotaService.php',
    APP_PATH . '/Controllers/RevenueIntelligenceController.php',
    APP_PATH . '/Jobs/RecomputeRevenueInsightsJob.php',
    APP_PATH . '/Jobs/SendRevenueDigestJob.php',
    // ============================================
    // Tourfecto Account & Workspace Settings Center (Phases 1-8, 2026-08-09)
    // نفس المبدأ بالظبط زي كل الكلاسات فوق: كلاسات جديدة مش موجودة في
    // classmap القديم بتاع composer، فلازم تتحمّل يدويًا هنا وإلا هيقع
    // "Class not found" على أي endpoint بيستخدمها.
    // ============================================
    APP_PATH . '/Models/UserApiKey.php',
    APP_PATH . '/Models/AuditLog.php',
    APP_PATH . '/Models/WorkspaceInvite.php',
    APP_PATH . '/Services/WorkspacePermissions.php',
    APP_PATH . '/Controllers/WorkspaceController.php',
    // 2FA حقيقي (TOTP) في Settings Center (Phase 9 - 2026-08-11)
    APP_PATH . '/Services/TotpService.php',
    // ============================================
    // CRM Module (Contacts/Companies/Tasks/Pipelines/Automation/Segments)
    // نفس المبدأ بالظبط زي كل الكلاسات فوق: كلاسات جديدة مش موجودة في
    // classmap القديم بتاع composer، فلازم تتحمّل يدويًا هنا وإلا هتقع
    // "Class not found" على /crm وكل الـ API بتاعه (2026-08-12).
    // ============================================
    APP_PATH . '/Models/CrmContact.php',
    APP_PATH . '/Models/CrmCompany.php',
    APP_PATH . '/Models/CrmLead.php',
    APP_PATH . '/Models/CrmLeadSource.php',
    APP_PATH . '/Models/CrmDeal.php',
    APP_PATH . '/Models/CrmPipeline.php',
    APP_PATH . '/Models/CrmPipelineStage.php',
    APP_PATH . '/Models/CrmTask.php',
    APP_PATH . '/Models/CrmNote.php',
    APP_PATH . '/Models/CrmMeeting.php',
    APP_PATH . '/Models/CrmMessage.php',
    APP_PATH . '/Models/CrmConversation.php',
    APP_PATH . '/Models/CrmSegment.php',
    APP_PATH . '/Models/CrmAutomationRule.php',
    APP_PATH . '/Models/CrmTeamMember.php',
    APP_PATH . '/Services/Crm/CrmPermissionService.php',
    APP_PATH . '/Services/Crm/CrmContactService.php',
    APP_PATH . '/Services/Crm/CrmCompanyService.php',
    APP_PATH . '/Services/Crm/CrmLeadService.php',
    APP_PATH . '/Services/Crm/CrmDealService.php',
    APP_PATH . '/Services/Crm/CrmTaskService.php',
    APP_PATH . '/Services/Crm/CrmNoteService.php',
    APP_PATH . '/Services/Crm/CrmAppointmentService.php',
    APP_PATH . '/Services/Crm/CrmSegmentService.php',
    APP_PATH . '/Services/Crm/CrmAutomationService.php',
    APP_PATH . '/Services/Crm/CrmTeamService.php',
    APP_PATH . '/Services/Crm/CrmSearchService.php',
    APP_PATH . '/Services/Crm/CrmDashboardService.php',
    APP_PATH . '/Services/Crm/CrmCustomer360Service.php',
    APP_PATH . '/Services/Crm/CrmImportExportService.php',
    APP_PATH . '/Services/Crm/CrmForecastService.php',
    APP_PATH . '/Services/Crm/CrmLeadScoringService.php',
    APP_PATH . '/Services/Crm/CrmNextBestActionService.php',
    APP_PATH . '/Services/Crm/CrmAiAssistantService.php',
    APP_PATH . '/Services/Crm/CrmAiSummaryService.php',
    APP_PATH . '/Services/Crm/CrmEmailService.php',
    APP_PATH . '/Services/Crm/CrmSmsService.php',
    APP_PATH . '/Services/Crm/CrmWhatsAppService.php',
    APP_PATH . '/Controllers/CrmController.php',
    APP_PATH . '/Controllers/CrmApiController.php',
    APP_PATH . '/Controllers/CrmWhatsAppWebhookController.php',
    APP_PATH . '/Controllers/CrmSmsWebhookController.php',
    APP_PATH . '/Controllers/CrmEmailWebhookController.php',
    APP_PATH . '/Models/CrmImportBatch.php',
    APP_PATH . '/Services/Crm/CrmPaginationHelper.php',
    APP_PATH . '/Jobs/CrmImportContactsJob.php',
    // CRM Upgrade Phase 12 (2026-08-15) - Message Templates / Sales Goals /
    // Custom Fields. نفس السبب: مش مسجّلة في classmap القديم.
    APP_PATH . '/Models/CrmMessageTemplate.php',
    APP_PATH . '/Models/CrmSalesGoal.php',
    APP_PATH . '/Models/CrmCustomField.php',
    APP_PATH . '/Models/CrmCustomFieldValue.php',
    APP_PATH . '/Services/Crm/CrmMessageTemplateService.php',
    APP_PATH . '/Services/Crm/CrmReportService.php',
    APP_PATH . '/Services/Crm/CrmCustomFieldService.php',
    // CRM Upgrade Phase 13 (2026-08-16) - Product Catalog / Lead Routing /
    // Contact Lifecycle / Team Invite. نفس السبب: مش مسجّلة في classmap القديم.
    APP_PATH . '/Models/CrmProduct.php',
    APP_PATH . '/Models/CrmDealItem.php',
    APP_PATH . '/Models/CrmLeadRoutingRule.php',
    APP_PATH . '/Models/CrmLifecycleStage.php',
    APP_PATH . '/Services/Crm/CrmProductService.php',
    APP_PATH . '/Services/Crm/CrmLeadRoutingService.php',
    APP_PATH . '/Services/Crm/CrmLifecycleService.php',
    APP_PATH . '/Services/Crm/CrmTeamInviteService.php',
    // CRM Upgrade Phase 14 (2026-08-16) - Charts / Email Open Tracking /
    // Custom Activity Types. نفس السبب: مش مسجّلة في classmap القديم.
    APP_PATH . '/Models/CrmEmailTracking.php',
    APP_PATH . '/Models/CrmActivityType.php',
    APP_PATH . '/Models/CrmActivity.php',
    APP_PATH . '/Services/Crm/CrmChartService.php',
    APP_PATH . '/Services/Crm/CrmEmailTrackingService.php',
    APP_PATH . '/Services/Crm/CrmActivityService.php',
    // CRM Upgrade Phase 15 (2026-08-16) - Web Forms (G11) / Sales Sequences
    // (G12) / Report Builder (G13) / External CRM Import (G14). نفس السبب:
    // مش مسجّلة في classmap القديم.
    APP_PATH . '/Models/CrmWebForm.php',
    APP_PATH . '/Models/CrmWebFormSubmission.php',
    APP_PATH . '/Models/CrmSequence.php',
    APP_PATH . '/Models/CrmSequenceEnrollment.php',
    APP_PATH . '/Models/CrmSavedReport.php',
    APP_PATH . '/Services/Crm/CrmWebFormService.php',
    APP_PATH . '/Services/Crm/CrmSequenceService.php',
    APP_PATH . '/Services/Crm/CrmReportBuilderService.php',
    APP_PATH . '/Services/Crm/CrmExternalImportService.php',
    // GBP Module Upgrade (2026-08-09/10) - Setup Wizard/Connection Center/
    // Sync/Profile/Photos/Insights/AI/Attributes. نفس السبب زي كل
    // الكلاسات فوق: مش مسجّلة في classmap القديم بتاع composer.
    APP_PATH . '/Services/GoogleBusiness/GbpSetupStatusService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpSyncService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpProfileService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpMediaUploadHandler.php',
    APP_PATH . '/Services/GoogleBusiness/GbpPhotoService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpInsightsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpAIInsightsService.php',
    APP_PATH . '/Controllers/GbpProfileController.php',
    // GBP Module Upgrade (2026-08-14, Round 7): Audit Log
    APP_PATH . '/Services/GoogleBusiness/GbpAuditLogger.php',
    // GBP Module Upgrade (2026-08-14, Round 8): Health Check Service
    APP_PATH . '/Services/GoogleBusiness/GbpHealthCheckService.php',
    // GBP Competitive Benchmark (2026-08-15): مقارنة تنافسية مع المنافسين القريبين
    APP_PATH . '/Services/GoogleBusiness/GbpCompetitorBenchmarkService.php',
    // GBP Reputation Intelligence (2026-08-15): KPIs + اتجاهات + مخاطر + حصة ظهور
    APP_PATH . '/Services/GoogleBusiness/GbpReputationAnalyticsService.php',
    // GBP Automated Reply Rules (2026-08-15): قواعد الرد التلقائي BirdAI-style
    APP_PATH . '/Services/GoogleBusiness/GbpReplyRuleService.php',
    // GBP Local SEO Audit (2026-08-15, Tier 3): تدقيق الحضور في البحث المحلي
    APP_PATH . '/Services/GoogleBusiness/GbpLocalSeoAuditService.php',
    // Consolidated Multi-Phase Module (2026-08-08) - إضافات جديدة فقط.
    // ملحوظة: تعمّدنا استبعاد ملفات AI Orchestrator/Providers الجديدة
    // (AIOrchestrator/ModelRouter/TaskClassifier/BaseOpenAICompatibleProvider/
    // OpenAIProvider/DeepSeekProvider/KimiProvider/AIProviderInterface الجديدة)
    // لأنها بتعيد تعريف كلاسات موجودة بالفعل وشغّالة (Phase 2 القديم) بعقد
    // مختلف (generateContent بدل generateReply) - كانت هتكسر أي حاجة شغالة
    // عليها حاليًا. الخدمات اللي بتحتاج AIOrchestrator (ArticleGenerator،
    // CompetitorAnalysisService، SeoStrategyService، CeoAdvisorService،
    // OutreachEmailGenerator) مصمّمة بـfallback آمن لـGeminiClient القديم
    // (class_exists('AIOrchestrator') ? ... : new GeminiClient()) فهتشتغل
    // عادي زي ما هي دلوقتي، لحد ما موديول Orchestrator متوافق يتوفر.
    APP_PATH . '/Services/Admin/AIUsageStatsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpProfileScoreService.php',
    APP_PATH . '/Models/OutreachProspect.php',
    APP_PATH . '/Models/OutreachEmail.php',
    APP_PATH . '/Models/MonitoredBacklink.php',
    APP_PATH . '/Services/Outreach/OutreachEmailGenerator.php',
    APP_PATH . '/Services/Outreach/ProspectDiscoverySourceInterface.php',
    APP_PATH . '/Services/Outreach/CompetitorBacklinkDiscoverySource.php',
    APP_PATH . '/Services/Outreach/ProspectDiscoveryService.php',
    APP_PATH . '/Services/Outreach/BacklinkMonitorService.php',
    APP_PATH . '/Services/Outreach/OutreachFollowUpDraftService.php',
    APP_PATH . '/Services/Outreach/OutreachPerformanceService.php',
    APP_PATH . '/Controllers/OutreachController.php',
    APP_PATH . '/Services/CeoAdvisor/CeoAdvisorService.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterService.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterExecutionService.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterExecutor.php',
    APP_PATH . '/Services/Execution/ActionExecutor.php',
    APP_PATH . '/Controllers/ActionCenterController.php',
    APP_PATH . '/Models/SeoStrategyPlan.php',
    APP_PATH . '/Models/SeoStrategyTask.php',
    APP_PATH . '/Services/SeoStrategy/SeoStrategyService.php',
    APP_PATH . '/Controllers/SeoStrategyController.php',
    APP_PATH . '/Services/ExecutiveDashboard/ExecutiveDashboardService.php',
    APP_PATH . '/Controllers/ExecutiveDashboardController.php',
    APP_PATH . '/Controllers/OnboardingController.php',
    // Billing Center Merge (2026-08-09/10): SubscriptionController كان
    // فعليًا بينادي BillingProfile::forUser() و new UsageAlertService()
    // من قبل كده (محمي بـclass_exists) بس الملفات دي متبعتش لحد دلوقتي -
    // يعني الميزتين دول كانوا معطّلين بصمت. إضافة الكلاسين دول بس تفعّلهم.
    APP_PATH . '/Models/BillingProfile.php',
    APP_PATH . '/Services/Subscription/UsageAlertService.php',
    // ============================================
    // Business Control Center (Phases 1-7, 2026-08-14)
    // نفس المبدأ زي كل الكلاسات فوق: كلاسات جديدة مش موجودة في classmap
    // القديم بتاع composer، فلازم تتحمّل يدويًا هنا وإلا هتقع "Class not
    // found" على أي API من /api/business/* (نفس سبب بيقية الموديولات).
    // الترتيب مهم: الـModels قبل الـServices قبل الـControllers (كل
    // Controller بينادي Services، وكل Service بينادي Models).
    // ============================================
    APP_PATH . '/Models/Business.php',
    APP_PATH . '/Models/BusinessLocation.php',
    APP_PATH . '/Models/BusinessService.php',
    APP_PATH . '/Models/BusinessTargetMarket.php',
    APP_PATH . '/Models/BusinessAiContext.php',
    APP_PATH . '/Models/BusinessBrandSettings.php',
    APP_PATH . '/Models/BusinessMember.php',
    // Business Control Center - Phase 8-9/12/13-14/15-16/17 (2026-08-15)
    APP_PATH . '/Models/BusinessApiKey.php',
    APP_PATH . '/Models/BusinessAuditLog.php',
    APP_PATH . '/Services/BusinessIntegrationsService.php',
    APP_PATH . '/Services/BusinessApiKeyService.php',
    APP_PATH . '/Services/BusinessAuditService.php',
    APP_PATH . '/Services/BusinessOnboardingService.php',
    APP_PATH . '/Services/BusinessAccountClosureService.php',
    APP_PATH . '/Controllers/BusinessIntegrationsController.php',
    APP_PATH . '/Controllers/BusinessApiKeyController.php',
    APP_PATH . '/Controllers/BusinessAuditLogController.php',
    APP_PATH . '/Controllers/BusinessOnboardingController.php',
    APP_PATH . '/Services/BusinessServiceManager.php',
    APP_PATH . '/Services/BusinessLocationService.php',
    APP_PATH . '/Services/BusinessContextService.php',
    APP_PATH . '/Services/BusinessReadinessService.php',
    APP_PATH . '/Services/BusinessAccessService.php',
    APP_PATH . '/Services/BusinessTeamService.php',
    APP_PATH . '/Services/BusinessNotificationService.php',
    APP_PATH . '/Controllers/BusinessController.php',
    APP_PATH . '/Controllers/BusinessLocationController.php',
    APP_PATH . '/Controllers/BusinessServiceController.php',
    APP_PATH . '/Controllers/BusinessTargetMarketController.php',
    APP_PATH . '/Controllers/BusinessAiContextController.php',
    APP_PATH . '/Controllers/BusinessBrandSettingsController.php',
    APP_PATH . '/Controllers/BusinessTeamController.php',
    APP_PATH . '/Controllers/BusinessCenterController.php',
    // Phase 24 (SEO Content Engine): بيجيب الفرص -> مولّد مقالات -> فهرسة
    // -> A/B -> قياس CTR. الكلاسات دي مش مسجّلة لسه في classmap بتاع
    // composer على السيرفر (مفيهوش composer dump-autoload)، فلازم تتحمّل
    // يدويًا هنا وإلا أي زيارة لـ /seo-content هتقع بـ "Class not found".
    APP_PATH . '/Services/Seo/SeoContentService.php',
    APP_PATH . '/Controllers/SeoContentController.php',
    APP_PATH . '/Jobs/SeoContentGenerateJob.php',
    // Booking & Availability Engine (merge remote 2026-08-21): الكلاسات دي
    // مش مسجّلة في classmap composer القديم، فلازم تتحمّل يدويًا هنا وإلا
    // أي API من /api/bookings أو /api/inventory هتقع "Class not found".
    // الترتيب: Models قبل Services قبل Controller.
    APP_PATH . '/Models/Booking.php',
    APP_PATH . '/Models/InventoryDay.php',
    APP_PATH . '/Services/BookingEngine.php',
    APP_PATH . '/Services/InventoryService.php',
    APP_PATH . '/Controllers/BookingController.php',
    // إصلاح حرج (2026-09-06): SeoProxyService نفسه ما كانش متسجّل هنا
    // خالص (بس الكلاسات اللي هو بيستخدمها كانت متسجّلة!) - يعني أي طلب
    // عادي لأي صفحة (بما فيها الصفحة الرئيسية "/") كان بيقع فورًا بخطأ
    // فادح "Class SeoProxyService not found" غير ملتقط (يحصل قبل try/catch
    // بتاع الراوتر في index.php)، فيظهر كـ HTTP ERROR 500 خام للزائر.
    // SeoAbTestService كمان كلاس جديد بيستخدمه SeoProxyService::render()
    // ومكانش متسجّل هو التاني.
    APP_PATH . '/Services/Seo/SeoAbTestService.php',
    APP_PATH . '/Services/Seo/SeoProxyService.php',
    // نفس المشكلة بالظبط: SeoAbTestController وSeoPerformanceService (اللي
    // بيستخدمه) مسجّلين في routes/api.php لكن مش في القائمة دي - أي طلب
    // لـ /api/seo-ab-tests/* كان هيقع بـ "Class not found" برضه.
    APP_PATH . '/Services/Seo/SeoPerformanceService.php',
    APP_PATH . '/Controllers/SeoAbTestController.php',
    // Phase 25 (SEO: image optimization + Baidu indexing): الكلاسات دي مش
    // مسجّلة في classmap composer القديم على السيرفر (مفيهوش composer
    // dump-autoload)، فلازم تتحمّل يدويًا هنا وإلا أي استدعاء لـ
    // ImageOptimizationService (من جوه SeoProxyService/AutoSeoEmbedService)
    // أو أي طلب لـ /api/baidu/* هيقع بـ "Class not found".
    APP_PATH . '/Services/Seo/ImageOptimizationService.php',
    APP_PATH . '/Services/Seo/BaiduIndexingService.php',
    APP_PATH . '/Controllers/BaiduIndexingController.php',
    // Phase 26 (M6 SEO: زحف متعدد G1 + Google Indexing G3 + Rank Tracking
    // G7 + تقارير/رسوم G6 + Keyword Research G4): كلاسات جديدة مش في
    // classmap composer القديم على السيرفر، فلازم تتحمّل يدويًا هنا
    // (Models -> Contracts -> Services -> Controller).
    APP_PATH . '/Models/SeoCrawlPage.php',
    APP_PATH . '/Models/SeoRankTrackingHistory.php',
    APP_PATH . '/Models/SeoReportSchedule.php',
    APP_PATH . '/Core/Contracts/KeywordResearchSourceInterface.php',
    APP_PATH . '/Services/Seo/SeoCrawlerService.php',
    APP_PATH . '/Services/Seo/GoogleIndexingService.php',
    APP_PATH . '/Services/Seo/NullKeywordResearchSource.php',
    APP_PATH . '/Services/Seo/HttpKeywordResearchSource.php',
    APP_PATH . '/Services/Seo/KeywordResearchService.php',
    APP_PATH . '/Services/Seo/RankTrackingService.php',
    APP_PATH . '/Services/Seo/SeoChartService.php',
    APP_PATH . '/Services/Seo/SeoScheduledReportService.php',
    APP_PATH . '/Controllers/SeoInsightsController.php',
    // Social Media Expansion (TikTok + YouTube Shorts): PublishSocialPostJob
    // بينادي new TikTokAPI()/new YouTubeAPI() مباشرة من غير class_exists -
    // لازم يتحمّلوا هنا وإلا أي محاولة نشر عبر cron/process_queue.php
    // هتقع بـ "Class not found" (السيرفر مفيهوش composer dump-autoload).
    APP_PATH . '/Services/SocialMedia/TikTokAPI.php',
    APP_PATH . '/Services/SocialMedia/YouTubeAPI.php',
    // Email Marketing (منافس Brevo) - موديول تسويق البريد (2026-08-21)
    APP_PATH . '/Models/EmailList.php',
    APP_PATH . '/Models/EmailSubscriber.php',
    APP_PATH . '/Models/EmailTemplate.php',
    APP_PATH . '/Models/EmailCampaign.php',
    APP_PATH . '/Models/EmailCampaignRecipient.php',
    APP_PATH . '/Models/EmailCustomField.php',
    APP_PATH . '/Models/EmailSubscriberCustomValue.php',
    APP_PATH . '/Models/EmailTag.php',
    APP_PATH . '/Models/EmailSegment.php',
    APP_PATH . '/Models/EmailSuppression.php',
    APP_PATH . '/Models/EmailAutomation.php',
    APP_PATH . '/Models/EmailAutomationStep.php',
    APP_PATH . '/Models/EmailAutomationEntry.php',
    APP_PATH . '/Models/EmailSmtpSetting.php',
    APP_PATH . '/Models/EmailTransactionalTemplate.php',
    APP_PATH . '/Models/EmailTransactionalLog.php',
    APP_PATH . '/Models/EmailAbTest.php',
    APP_PATH . '/Services/EmailMarketing/EmailRenderer.php',
    APP_PATH . '/Services/EmailMarketing/EmailListService.php',
    APP_PATH . '/Services/EmailMarketing/ContactManagementService.php',
    APP_PATH . '/Services/EmailMarketing/EmailCampaignService.php',
    APP_PATH . '/Services/EmailMarketing/EmailTrackingService.php',
    APP_PATH . '/Services/EmailMarketing/EmailTemplateEditorService.php',
    APP_PATH . '/Services/EmailMarketing/EmailAutomationService.php',
    APP_PATH . '/Services/EmailMarketing/SmtpSettingsService.php',
    APP_PATH . '/Services/EmailMarketing/TransactionalEmailService.php',
    APP_PATH . '/Services/EmailMarketing/AbTestService.php',
    APP_PATH . '/Jobs/SendEmailCampaignBatchJob.php',
    APP_PATH . '/Jobs/SendAbTestBatchJob.php',
    APP_PATH . '/Controllers/EmailMarketingController.php',

    // ============================================
    // إصلاح 2026-09-10: نفس المبدأ - كلاسين موجودين فعليًا كملفات لكن
    // ناقصين من هنا، فكانوا بيرموا "Class not found" على:
    //   - GET /dashboard/sites/{id}  (لوحة تحكم كل موقع منشأ)
    //   - صفحة الموقع المنشأ العامة (تقييمات الزوار)
    // ============================================
    APP_PATH . '/Controllers/SiteDashboardController.php',
    APP_PATH . '/Models/WebsiteReview.php',
    // Google Analytics 4 (not in composer classmap; fixes 'Class GoogleAnalyticsAPI not found')
    APP_PATH . '/Services/Analytics/GoogleAnalyticsAPI.php',
    APP_PATH . '/Controllers/GoogleAnalyticsController.php',
];
foreach ($optionalNewClassFiles as $classFile) {
    if (file_exists($classFile)) {
        require_once $classFile;
    }
}

// ============================================
// 2.2. Helper Functions العامة (event(), container(), listen(), enqueue())
// إصلاح اكتُشف أثناء Settings Center (Phase 11): طبقة
// Container/EventDispatcher كلها (app/Core/Container.php،
// app/Core/Events/*) كانت موجودة ومسجّلة في composer classmap فعليًا
// (يعني الكلاسات نفسها شغالة)، لكن app/Helpers/enterprise_helpers.php
// اللي بيعرّف الدوال العامة المختصرة (event()، container()...) عمره
// ما كان بيتحمّل من أي مكان - يعني أي نداء event('...') في أي مكان
// في الكود كله كان هيرمي Fatal Error "Call to undefined function"
// فورًا. بعد إصلاح التحميل ده، أي موديول (GBP، Revenue Intelligence،
// Reputation...) بقى يقدر يستخدم الأحداث بشكل حقيقي.
// ============================================
if (file_exists(APP_PATH . '/Helpers/enterprise_helpers.php')) {
    require_once APP_PATH . '/Helpers/enterprise_helpers.php';
}

// ============================================
// 3. تحميل متغيرات البيئة (.env)
// ============================================
if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        error_log('Failed to load .env: ' . $e->getMessage());
    }
}

// ============================================
// 4. تحميل ملفات الإعدادات (الترتيب مهم)
// ============================================
require_once APP_PATH . '/Config/app.php';
require_once APP_PATH . '/Config/constants.php';
require_once APP_PATH . '/Config/database.php';
require_once APP_PATH . '/Config/encryption.php';

if (file_exists(APP_PATH . '/Config/gemini.php')) {
    require_once APP_PATH . '/Config/gemini.php';
}
if (file_exists(APP_PATH . '/Config/anthropic.php')) {
    require_once APP_PATH . '/Config/anthropic.php';
}
if (file_exists(APP_PATH . '/Config/whatsapp.php')) {
    require_once APP_PATH . '/Config/whatsapp.php';
}
if (file_exists(APP_PATH . '/Config/revenue_intelligence_events.php')) {
    require_once APP_PATH . '/Config/revenue_intelligence_events.php';
}
// AI Chat Platform - AI Provider Abstraction (بند 20): كل ملف اختياري،
// المزود يُعتبر غير مهيّأ تلقائيًا لو مفتاحه فاضي في .env.
foreach (['/Config/openai.php', '/Config/deepseek.php', '/Config/kimi.php', '/Config/anthropic.php'] as $aiProviderConfig) {
    if (file_exists(APP_PATH . $aiProviderConfig)) {
        require_once APP_PATH . $aiProviderConfig;
    }
}
if (file_exists(APP_PATH . '/Config/third_party.php')) {
    require_once APP_PATH . '/Config/third_party.php';
}

// ============================================
// 5. بدء الجلسة (Session)
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    if (!is_dir(TOURFECTO_STORAGE . '/sessions')) {
        @mkdir(TOURFECTO_STORAGE . '/sessions', 0755, true);
    }
    // تصحيح أمني: كوكي الجلسة مكانش عليها SameSite خالص، يعني أي موقع
    // خارجي كان يقدر يخلي متصفح المستخدم المسجّل دخول يبعت طلبات نيابة
    // عنه من غير ما يحس (CSRF) - زي تغيير باسورد أو حذف موقع بدون علمه.
    // SameSite=Lax بيمنع الغالبية العظمى من هجمات CSRF من غير ما يكسر
    // أي استخدام عادي للموقع (لسه بيشتغل عادي لو المستخدم فتح رابط من
    // إيميل أو واتساب مثلاً).
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================
// 6. رؤوس أمان أساسية
// ============================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self)');
// CSP متوازن: بيمنع تحميل سكربتات/إطارات من مصادر خارجية غير موثوقة
// (defense-in-depth ضد XSS)، لكن سايبين 'unsafe-inline' مفعّلة لأن
// الموقع فيه كمية كبيرة من <script>/<style> جوه الصفحات نفسها (heredoc)
// - تشديدها أكتر من كده يحتاج نقل كل ده لملفات خارجية أول (خطوة تانية).
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' data: https:; connect-src 'self' https:; frame-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; object-src 'none';");
if (!empty($_SERVER['HTTPS'])) {
    // HSTS: يجبر المتصفح يستخدم HTTPS بس لمدة سنة - آمن بس لو الموقع
    // شغّال على SSL دايمًا (بيبقى مفعّل تلقائيًا بس على طلبات https).
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ============================================
// دوال مساعدة محلية لهذا الملف (يجب تعريفها قبل الاستخدام)
// ============================================

/**
 * إرسال الاستجابة كـ JSON بشكل موحّد - لكن لو الخطأ 404/500 وكان طلب
 * تصفح صفحة عادي (مش نداء API)، نعرض صفحة خطأ HTML احترافية بدل JSON
 * خام - كان أي رابط غلط أو صفحة اتمسحت بيوري الزائر نص JSON مباشر،
 * أسوأ انطباع ممكن يحصل لزائر جديد.
 */
function send_response(array $data, ?int $httpCode = null): void
{
    // بند خاص بـ AI Chat Platform: بعض الـWebhooks الخارجية (مثال: Meta
    // hub.challenge verification handshake الخاص بـMessenger/Instagram)
    // تتطلب استجابة نصية خام حرفية، وليست JSON مغلَّفة. مفتاح محجوز جديد
    // (_raw_text) يتيح هذا فقط عند وجوده صراحة - لا يغيّر أي استجابة
    // حالية لأي Controller آخر لا يستخدم هذا المفتاح إطلاقًا.
    if (array_key_exists('_raw_text', $data)) {
        if (!headers_sent()) {
            http_response_code($httpCode ?? 200);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo (string) $data['_raw_text'];
        return;
    }

    if ($httpCode === null) {
        $httpCode = $data['code'] ?? (($data['success'] ?? true) ? 200 : 400);
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isApiRequest = strpos($requestUri, '/api/') === 0;
    $wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    if (in_array($httpCode, [404, 500], true) && !$isApiRequest && !$wantsJson) {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo render_error_page($httpCode, $data['error'] ?? default_error_message($httpCode));
        return;
    }

    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** صفحة خطأ HTML بسيطة ومتّسقة مع هوية الموقع - بدل JSON خام */
function render_error_page(int $code, string $message): string
{
    $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
    $title = $code === 404 ? 'الصفحة مش موجودة' : 'حصل خطأ غير متوقع';
    $emoji = $code === 404 ? '🧭' : '⚠️';
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} | {$appName}</title>
    <link rel="icon" type="image/png" href="/assets/icons/favicon-32.png">
    <style>
        body{font-family:'IBM Plex Sans Arabic',sans-serif;background:#060A13;color:#F2F4F8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px;}
        .box{max-width:420px;}
        .emoji{font-size:52px;margin-bottom:18px;}
        h1{font-size:22px;margin:0 0 10px;}
        p{color:#8996AC;font-size:14px;margin:0 0 28px;line-height:1.7;}
        a{display:inline-block;background:#EFB05E;color:#0b0f1a;padding:13px 30px;border-radius:30px;text-decoration:none;font-weight:700;font-size:14px;}
    </style>
</head>
<body>
    <div class="box">
        <div class="emoji">{$emoji}</div>
        <h1>{$title}</h1>
        <p>{$safeMessage}</p>
        <a href="/">ارجع للصفحة الرئيسية</a>
    </div>
</body>
</html>
HTML;
}

/**
 * رسالة خطأ عامة آمنة حسب كود الحالة (لا تكشف تفاصيل داخلية في الإنتاج)
 */
function default_error_message(int $code): string
{
    $messages = [
        400 => 'طلب غير صالح',
        401 => 'غير مصرح بالوصول',
        403 => 'ممنوع الوصول لهذا المورد',
        404 => 'الصفحة أو المورد غير موجود',
        405 => 'طريقة الطلب غير مسموحة',
        429 => 'عدد الطلبات كبير جدًا، حاول لاحقًا',
        500 => 'حدث خطأ غير متوقع في الخادم',
    ];
    return $messages[$code] ?? 'حدث خطأ غير متوقع';
}

// ============================================
// 7. تشغيل الميدل وير العام (Global Middleware)
// ============================================
try {
    $globalMiddleware = ['CORSMiddleware', 'LoggingMiddleware', 'VisitorTrackingMiddleware'];
    if (defined('RATE_LIMIT_ENABLED') && RATE_LIMIT_ENABLED) {
        $globalMiddleware[] = 'RateLimitMiddleware';
    }

    foreach ($globalMiddleware as $middlewareClass) {
        if (!class_exists($middlewareClass)) {
            continue;
        }
        $middlewareObj = new $middlewareClass();
        $result = $middlewareObj->handle();
        if ($result !== null) {
            send_response($result);
            exit;
        }
    }
} catch (Throwable $e) {
    if (class_exists('Logger')) {
        Logger::error('Global middleware error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
}

// ============================================
// 8. تجهيز الراوتر وتحميل المسارات
// ============================================
$router = new Router();

require_once APP_PATH . '/routes/web.php';
require_once APP_PATH . '/routes/api.php';

// ============================================
// 9. تحديد الطلب الحالي (Method + Path)
// ============================================
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// إزالة سلاش زائد في النهاية (عدا الجذر نفسه)
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/');
}

// ============================================
// 9.1. API Versioning (المرحلة 2 من خطة API Gateway - 2026-08-06)
// ============================================
// كل الـ 418 مسار الحالية معرّفة بصيغة /api/... من غير رقم نسخة.
// بدل ما نعيد كتابتهم كلهم بصيغة /api/v1/... (تغيير ضخم عالي الخطورة
// على نظام شغّال فعليًا)، بنعمل alias: أي طلب يوصل لـ /api/v1/xxx
// بيتعامل معاه بالظبط زي /api/xxx (نفس الـ Controller ونفس الـ Middleware)
// - وده كافي دلوقتي عشان أي عميل جديد (موبايل/شريك) يبدأ يستخدم مسارات
// versioned من أول يوم، من غير ما نكسر أي حاجة شغالة بالفعل على /api/...
// القديمة. لو حصل تغيير غير متوافق (breaking change) في شكل رد أي
// endpoint مستقبلاً، وقتها فقط تتفرّع نسخة v2 فعلية بمنطق مختلف.
$isVersionedApiRequest = false;
if (preg_match('#^/api/v(\d+)/(.*)$#', $path, $vMatches)) {
    $isVersionedApiRequest = true;
    $apiVersion = (int) $vMatches[1];
    $path = '/api/' . $vMatches[2];
}

// ============================================
// 9.2. SEO Proxy Passthrough (Phase 2 - Server-Side Edge)
// ============================================
// مسار /s/{token}[/{path}...] بيخدم صفحة موقع خارجي بعد إعادة كتابتها
// Server-Side. المسار هنا ممكن يكون متعدد المقاطع (مش مجرد مقطع واحد)
// فبنعالجه يدويًا قبل الـ Router العادي - مش هيقدر يطابقه بـ{param} الواحد.
// التحقق بيتم عبر embed_token نفسه (من غير AuthMiddleware).
if (strpos($path, '/s/') === 0) {
    if (preg_match('#^/s/([A-Za-z0-9_]+)#', $path, $sMatches)) {
        $controllerFile = APP_PATH . '/Controllers/SeoProxyController.php';
        if (file_exists($controllerFile)) {
            require_once $controllerFile;
            $seoProxy = new SeoProxyController();
            $seoProxy->serve(['token' => $sMatches[1]]);
        }
    }
    // توكن غير صالح أو كنترولر غير موجود - نكمّل للـ Router عشان يطلع 404 عادي
}

// ============================================
// 9.2b. SEO Proxy Edge Health (لفحص الـ Load Balancer - عام من غير Auth)
// ============================================
if ($path === '/seo-proxy/health') {
    $controllerFile = APP_PATH . '/Controllers/SeoProxyController.php';
    if (file_exists($controllerFile) && !class_exists('SeoProxyController', false)) {
        require_once $controllerFile;
    }
    if (class_exists('SeoProxyController', false)) {
        $seoProxy = new SeoProxyController();
        $seoProxy->edgeHealth();
    }
}

// ============================================
// 9.3. SEO Proxy CNAME Passthrough (Phase 2 - Custom Domain)
// ============================================
// لما العميل يشير CNAME من subdomain بتاعه ناحية سيرفرنا، الطلب بيوصل من
// غير مسار /s/{token} والـ Host header بيبقى دومينه هو. بنكشف الموقع من
// الـ Host (مطابقة main_url) ونخدم الصفحة server-side مباشرة. لو الـ Host
// مش مربوط بموقع، بنكمّل للـ Router عادي (لوحة التحكم والصفحات الداخلية).
if (strpos($path, '/s/') !== 0 && strpos($path, '/api/') !== 0 && strpos($path, '/assets/') !== 0) {
    $controllerFile = APP_PATH . '/Controllers/SeoProxyController.php';
    if (file_exists($controllerFile) && !class_exists('SeoProxyController', false)) {
        require_once $controllerFile;
    }
    if (class_exists('SeoProxyController', false)) {
        $seoProxy = new SeoProxyController();
        if ($seoProxy->shouldHandleCname()) {
            $seoProxy->serveCname();
        }
    }
}

// ============================================
// 9.3b. Website Builder Custom Domain (CNAME/DNS A -> /sites/{slug})
// ============================================
// لما العميل يربط دومين مخصص (custom_domain) بموقع من الـ Website Builder
// ويشير الـ DNS ناحية سيرفرنا، الطلب بيوصل بـ Host هو الدومين بتاعه ومسار
// عام (/ أو /booking/...). بنكشف الموقع من الـ Host (بمطابقة custom_domain
// مع تطبيع للـ www/حالة الأحرف/البروتوكول) ونعيد كتابة المسار لـ
// /sites/{slug} عشان المسار الجاهز يخدمه بنفس قواعد العرض والثيم والمراجعات.
// لو الـ Host مش مربوط بموقع منشور، بنكمّل للـ Router عادي (لوحة التحكم).
if (strpos($path, '/sites/') !== 0
    && strpos($path, '/api/') !== 0
    && strpos($path, '/assets/') !== 0
    && strpos($path, '/s/') !== 0
) {
    $__wbHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $__wbHost = preg_replace('/:\d+$/', '', $__wbHost);
    $__wbHost = preg_replace('/^www\./', '', $__wbHost);
    if ($__wbHost !== '' && class_exists('GeneratedWebsite', false)) {
        $__wbCandidates = [
            $__wbHost,
            'www.' . $__wbHost,
            'https://' . $__wbHost,
            'https://www.' . $__wbHost,
            'http://' . $__wbHost,
            'http://www.' . $__wbHost,
        ];
        $__wbSite = null;
        foreach ($__wbCandidates as $__wbCandidate) {
            $__wbRows = (new GeneratedWebsite())->where(['custom_domain' => $__wbCandidate], [], 1);
            if (!empty($__wbRows)) {
                $__wbSite = $__wbRows[0];
                break;
            }
        }
        if ($__wbSite && (string) $__wbSite->getAttribute('status') === 'published') {
            $path = '/sites/' . rawurlencode((string) $__wbSite->getAttribute('slug'));
        }
    }
}

// ============================================
// 10. تنفيذ التوجيه (Dispatch) ومعالجة الأخطاء
// ============================================
try {
    $result = $router->dispatch($requestMethod, $path);
    send_response($result);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = ($code >= 400 && $code < 600) ? $code : 500;

    if ($code >= 500 && class_exists('Logger')) {
        Logger::error('Unhandled Exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'path' => $path,
            'method' => $requestMethod
        ]);
    }

    $payload = [
        'success' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : default_error_message($code),
        'code' => $code
    ];

    if (defined('APP_DEBUG') && APP_DEBUG) {
        $payload['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    send_response($payload, $code);

} catch (Throwable $e) {
    // أي خطأ PHP فادح آخر (TypeError, Error...) يُلتقط هنا بدل أن يظهر كصفحة بيضاء
    if (class_exists('Logger')) {
        Logger::error('Fatal Error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'path' => $path,
            'method' => $requestMethod
        ]);
    }

    send_response([
        'success' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'حدث خطأ غير متوقع في الخادم',
        'code' => 500
    ], 500);
}