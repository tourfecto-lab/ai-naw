<?php

/**
 * Tourfecto - CLI Bootstrap لسكريبتات الـ Cron
 * @version 1.0.0
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: هذا السكريبت لسطر الأوامر فقط (cron)، مش للمتصفح.');
}
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_ROOT', ROOT_PATH);
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');
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

require_once ROOT_PATH . '/vendor/autoload.php';
// Composer prepends its loader. Register the release map afterwards so stale
// generated maps cannot load retired billing/service implementations first.
tourfecto_register_fallback_autoloader();

// ============================================================
// Fallback Autoloader (للـ Cron/Queue Worker فقط)
// السيرفر مفيهوش SSH لتشغيل composer dump-autoload، فأي كلاس جديد مش في
// classmap كان بيطلّع "Class not found" ويوقف المهمة. الـ autoloader ده
// بيتنفّذ *بعد* composer وبس لو الكلاس لسه مش موجود: بيدوّر عليه بالاسم
// جوه app/ ويحمّله. مش بيغيّر سلوك أي كلاس متحمّل فعلًا.
// ============================================================
// Canonical release classmap is registered by runtime_requirements.php.
if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Failed to load .env: ' . $e->getMessage() . PHP_EOL);
    }
}
require_once APP_PATH . '/Config/app.php';
require_once APP_PATH . '/Config/constants.php';
require_once APP_PATH . '/Config/database.php';
require_once APP_PATH . '/Config/encryption.php';
// مفاتيح لوحة الأدمن ← التكاملات (لازم قبل gemini.php / openai.php ...)
if (file_exists(APP_PATH . '/Config/integration_overlay.php')) {
    require_once APP_PATH . '/Config/integration_overlay.php';
}
if (file_exists(APP_PATH . '/Config/gemini.php')) {
    require_once APP_PATH . '/Config/gemini.php';
}
if (file_exists(APP_PATH . '/Config/whatsapp.php')) {
    require_once APP_PATH . '/Config/whatsapp.php';
}
foreach (['/Config/openai.php', '/Config/deepseek.php', '/Config/kimi.php'] as $aiProviderConfig) {
    if (file_exists(APP_PATH . $aiProviderConfig)) {
        require_once APP_PATH . $aiProviderConfig;
    }
}
foreach (glob(APP_PATH . '/Jobs/*.php') as $jobFile) {
    require_once $jobFile;
}
$optionalJobDependencyFiles = [
    APP_PATH . '/Services/SocialMedia/MetaSocialAPI.php',
    // Social Media Expansion (TikTok + YouTube Shorts) - PublishSocialPostJob
    // بينادي new TikTokAPI()/new YouTubeAPI() مباشرة في سياق الـ Queue Worker.
    APP_PATH . '/Services/SocialMedia/TikTokAPI.php',
    APP_PATH . '/Services/SocialMedia/YouTubeAPI.php',
    APP_PATH . '/Services/AI/VeoClient.php',
    // GBP Module Upgrade (2026-08-10) - GbpBackgroundSyncJob محتاج
    // الكلاسات دي في سياق الـ Cron/Queue Worker (مختلف عن
    // public_html/index.php اللي بيخدم طلبات الـ web فقط).
    APP_PATH . '/Services/GoogleBusiness/GbpSetupStatusService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpSyncService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpProfileService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpMediaUploadHandler.php',
    APP_PATH . '/Services/GoogleBusiness/GbpPhotoService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpInsightsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpAIInsightsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpAuditLogger.php',
    // GBP Reputation Intelligence (2026-08-15) - apply_reply_rules.php محتاجهم
    APP_PATH . '/Services/GoogleBusiness/GbpReputationAnalyticsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpReplyRuleService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpCompetitorBenchmarkService.php',
    // GBP Local SEO Audit (2026-08-15, Tier 3) - تستخدمه أدوات تقارير الكرون
    APP_PATH . '/Services/GoogleBusiness/GbpLocalSeoAuditService.php',
    // AI Chat Platform (2026-08-08) - process_ai_followups.php محتاج
    // FollowUpAutomationService + ChatManager + UnifiedInboxService
    // (بالترتيب: ChatManager بينادي new UnifiedInboxService في
    // __construct، وUnifiedInboxService بينادي new AiChatConversation).
    // Providers لازم تتحمّل قبل AIProviderManager (الـ interface أولًا).
    APP_PATH . '/Services/Chat/ChatManager.php',
    APP_PATH . '/Services/Chat/UnifiedInboxService.php',
    APP_PATH . '/Services/Chat/MessengerAPI.php',
    APP_PATH . '/Services/Chat/InstagramAPI.php',
    APP_PATH . '/Services/Chat/EmailChannelAPI.php',
    APP_PATH . '/Models/AiChatConversation.php',
    APP_PATH . '/Services/AI/Providers/AIProviderInterface.php',
    APP_PATH . '/Services/AI/Providers/OpenAICompatibleProvider.php',
    APP_PATH . '/Services/AI/Providers/OpenAIProvider.php',
    APP_PATH . '/Services/AI/Providers/DeepSeekProvider.php',
    APP_PATH . '/Services/AI/Providers/KimiProvider.php',
    APP_PATH . '/Services/AI/Providers/AnthropicProvider.php',
    APP_PATH . '/Services/AI/Providers/GeminiProvider.php',
    APP_PATH . '/Services/AI/Providers/AIProviderManager.php',
    APP_PATH . '/Services/AI/KnowledgeBaseService.php',
    APP_PATH . '/Services/AI/AIConversationEngine.php',
    APP_PATH . '/Services/AI/LeadScoringService.php',
    APP_PATH . '/Services/AI/FollowUpAutomationService.php',
    APP_PATH . '/Services/AI/LearningLoopService.php',
    APP_PATH . '/Models/AiFollowup.php',
    APP_PATH . '/Models/AiFollowupRule.php',
    APP_PATH . '/Models/AiLead.php',
    APP_PATH . '/Models/AiCustomerMemory.php',
    APP_PATH . '/Models/AiKnowledgeBase.php',
    APP_PATH . '/Models/AiUsageLog.php',
    // Billing Phase 19/21: run_billing_lifecycle.php بينادي WalletService
    // (renewSubscriptionFromBalance) اللي بيستخدم SubscriptionPeriod +
    // BillingRules مباشرة - لازم يتحمّلوا قبل الـ Services عشان ميفشلش
    // "Class not found" في سياق الـ Cron/Worker.
    // SubscriptionPlan مش في classmap ولا كان بيتحمّل في الـ Cron: "Class SubscriptionPlan not found"
    APP_PATH . '/Models/SubscriptionPlan.php',
    APP_PATH . '/Services/Subscription/SubscriptionPeriod.php',
    APP_PATH . '/Services/Subscription/BillingRules.php',
    APP_PATH . '/Services/Subscription/SubscriptionLifecycleService.php',
    APP_PATH . '/Services/Subscription/WalletService.php',
    APP_PATH . '/Services/Payment/InvoiceLifecycleService.php',
    APP_PATH . '/Services/Payment/TaxService.php',
    APP_PATH . '/Services/Payment/RefundService.php',
    // WalletGatewayAdapter بيعمل implements PaymentGatewayInterface والـ interface
    // مش في classmap بتاع composer، فلازم يتحمّل قبله وإلا Fatal "Interface not found"
    // وسكريبت process_queue.php كله بيقع قبل ما ينفّذ أي مهمة.
    APP_PATH . '/Core/Contracts/PaymentGatewayInterface.php',
    APP_PATH . '/Services/Payment/WalletGatewayAdapter.php',
    // AI Revenue Intelligence (2026-08-15): SendRevenueDigestJob +
    // RecomputeRevenueInsightsJob بيتنفذوا من process_queue.php (الـ queue
    // worker) فمحتاجين كل خدمات الموديول متحمّلة في سياق الـ Cron/Worker -
    // مختلف عن public_html/index.php اللي بيخدم الـ web. الترتيب: الـ Gateway
    // الأول، وبعده الـ Services، وبعده الـ Mailer (الـ Jobs بتنادي new).
    APP_PATH . '/Services/RevenueIntelligence/RevenueDataGateway.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueOverviewService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAnomalyService.php',
    APP_PATH . '/Services/RevenueIntelligence/CustomerRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/PipelineRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueActionService.php',
    // ActionExecutor هو الكلاس الأب (abstract) لـ RevenueActionExecutor فلازم يتحمّل قبله
    APP_PATH . '/Services/Execution/ActionExecutor.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueActionExecutor.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterService.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterExecutionService.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterExecutor.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCacheService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAssistantService.php',
    APP_PATH . '/Services/RevenueIntelligence/ExecutiveSummaryService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightPersister.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueRetentionService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCopilotService.php',
    // v1.5.0: Subscriptions (MRR/ARR/NRR/GRR) + Forecast/Attribution + Benchmarks/Churn + Stripe mapper
    APP_PATH . '/Services/RevenueIntelligence/BizSubscriptionService.php',
    APP_PATH . '/Services/RevenueIntelligence/DealLevelForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueBenchmarkService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueChurnService.php',
    APP_PATH . '/Services/RevenueIntelligence/StripeRevenueMapper.php',
    // v1.6.0: Dashboard personalization + Stripe webhook
    APP_PATH . '/Services/RevenueIntelligence/RevenueDashboardService.php',
    APP_PATH . '/Services/RevenueIntelligence/StripeWebhookService.php',
    APP_PATH . '/Services/Mailer.php',
    APP_PATH . '/Models/User.php',
    // Competitor Intelligence (2026-08-16) - MonitorCompetitorJob + SendCompetitorAlertEmailJob
    // بينتعاملوا من process_queue.php (الـ queue worker) ومحتاجين موديول CI كامل
    // متحمّل في سياق الـ Cron/Worker - مختلف عن public_html/index.php اللي بيخدم
    // الـ web. الترتيب نفس index.php: الـ Interface الأول، وبعده الخدمات اللي
    // بتدور على بعضها، وبعده الـ Models.
    APP_PATH . '/Core/Contracts/CompetitorDiscoverySourceInterface.php',
    APP_PATH . '/Services/CompetitorIntelligence/SsrfGuard.php',
    APP_PATH . '/Services/CompetitorIntelligence/WebsiteSnapshotFetcher.php',
    APP_PATH . '/Services/CompetitorIntelligence/ChangeDetectionService.php',
    APP_PATH . '/Services/CompetitorIntelligence/MonitoringEngine.php',
    APP_PATH . '/Services/CompetitorIntelligence/SitemapMonitor.php',
    APP_PATH . '/Services/CompetitorIntelligence/NullDiscoverySource.php',
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
    // M5 (2026-08-29): KeywordRankingService + ProductPriceTrackerService +
    // BattlecardService + NullKeywordRankingSource (G1/G6/G7) - كلاسات جديدة
    // مش في classmap القديم على السيرفر، لازم تتحمّل في سياق الـ Cron/Worker.
    APP_PATH . '/Core/Contracts/KeywordRankingSourceInterface.php',
    APP_PATH . '/Services/CompetitorIntelligence/NullKeywordRankingSource.php',
    APP_PATH . '/Services/CompetitorIntelligence/KeywordRankingService.php',
    APP_PATH . '/Services/CompetitorIntelligence/ProductPriceTrackerService.php',
    APP_PATH . '/Services/CompetitorIntelligence/BattlecardService.php',
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
    APP_PATH . '/Models/Competitor.php',
    APP_PATH . '/Models/CompetitorRecommendation.php',
    // Phase 16C: Settings > Notifications بتتحكم في digest_weekly، فـ
    // ci_weekly_digest محتاج الـ Notification model عشان يقدر يستبعد
    // أي حد قفّل الملخص الأسبوعي (digestEnabledFor).
    APP_PATH . '/Models/Notification.php',
    // Auto SEO Phase 23 (2026-08-20): SeoSchedulerService/SeoPerformanceService
    // مستخدمين من cron/auto_seo_scheduler.php وAutoSeoReauditJob - مش مسجّلين
    // في classmap القديم على السيرفر، فلازم يتحمّلوا هنا.
    APP_PATH . '/Services/Indexing/IndexNowService.php',
    APP_PATH . '/Services/AutoSeo/AutoSeoEmbedService.php',
    APP_PATH . '/Services/Seo/SeoAbTestService.php',
    APP_PATH . '/Services/Seo/SeoProxyService.php',
    APP_PATH . '/Services/Seo/SeoPerformanceService.php',
    APP_PATH . '/Services/Seo/SeoSchedulerService.php',
    APP_PATH . '/Services/Seo/SeoContentService.php',
    // M6 (2026-08-29): RankTrackingService (cron/seo_rank_tracking.php) +
    // SeoScheduledReportService (cron/seo_scheduled_reports.php) + SeoCrawlerService
    // + GoogleIndexingService + KeywordResearchService (G1/G3/G4/G6/G7) - كلاسات
    // جديدة مش في classmap القديم، فلازم تتحمّل هنا.
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
    APP_PATH . '/Services/SearchConsole/GoogleSearchConsoleAPI.php',
    APP_PATH . '/Services/Analytics/GoogleAnalyticsAPI.php',
    // Booking & Availability Engine (merge remote 2026-08-21): الكلاسات دي
    // مش مسجّلة في classmap القديم على السيرفر، فلازم تتحمّل هنا لو أي كرون
    // هيتعامل مع الحجوزات/المخزون. الترتيب: Models قبل Services.
    APP_PATH . '/Models/Booking.php',
    APP_PATH . '/Models/InventoryDay.php',
    APP_PATH . '/Services/BookingEngine.php',
    APP_PATH . '/Services/InventoryService.php',
    // Email Marketing (منافس Brevo) - SendEmailCampaignBatchJob و
    // SendAbTestBatchJob بينادوا على خدمات EmailMarketing في سياق الـ Queue
    // Worker (cron/process_queue.php) اللي مختلف عن public_html/index.php.
    // الترتيب: Models قبل Services قبل Jobs.
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
    APP_PATH . '/Services/Mailer.php',
    // Item 2 (2026-08-31): Backlink monitoring + Outreach follow-up drafts +
    // Outreach performance. الكلاسات دي مش في classmap القديم على السيرفر،
    // و cron/monitor_backlinks.php و cron/generate_outreach_followups.php
    // محتاجينها في سياق الـ Cron.
    APP_PATH . '/Models/MonitoredBacklink.php',
    APP_PATH . '/Models/OutreachProspect.php',
    APP_PATH . '/Models/OutreachEmail.php',
    APP_PATH . '/Services/Outreach/OutreachEmailGenerator.php',
    APP_PATH . '/Services/Outreach/BacklinkMonitorService.php',
    APP_PATH . '/Services/Outreach/OutreachFollowUpDraftService.php',
    APP_PATH . '/Services/Outreach/OutreachPerformanceService.php',
    APP_PATH . '/Models/Notification.php',
    // OTA (Item 1, 2026-08-31): ربط حجوزات OTA بالإيرادات في سياق الـ Cron.
    // الكلاسات مش في classmap القديم على السيرفر (composer dump-autoload غير متاح).
    APP_PATH . '/Services/OTA/GetYourGuideAPI.php',
    APP_PATH . '/Services/OTA/ViatorAPI.php',
    APP_PATH . '/Services/OTA/OtaBookingService.php',
    // White-Label (Module 2, 2026-08-31): AgencyInvitation في سياق الـ Cron.
    APP_PATH . '/Models/AgencyInvitation.php',
];
foreach ($optionalJobDependencyFiles as $depFile) {
    if (file_exists($depFile)) {
        require_once $depFile;
    }
}

// AI Chat Platform (2026-08-08/09): نفس المشكلة بالظبط مع كود cron
// follow-up automation (cron/process_ai_followups.php). سكريبت الكرون
// بيعتمد على FollowUpAutomationService + كل Models/Services بتاعته، ولو
// السيرفر معندوش composer dump-autoload حديث، أي كلاس جديد مش هيبقى
// محمّل فكان الكرون بيسكت في صمت (class_exists بيفشل ويخرج بسلام).
// الترتيب مهم: الـModels الأول، بعدين الـServices اللي بتعتمد عليها.
foreach ([
    // Models
    APP_PATH . '/Models/AiKnowledgeBase.php',
    APP_PATH . '/Models/AiChatConversation.php',
    APP_PATH . '/Models/AiCustomerMemory.php',
    APP_PATH . '/Models/AiLead.php',
    APP_PATH . '/Models/AiFollowup.php',
    APP_PATH . '/Models/AiFollowupRule.php',
    APP_PATH . '/Models/AiCustomTag.php',
    APP_PATH . '/Models/AiUsageLog.php',
    // AI Providers (بند 20) - Interface أولاً
    APP_PATH . '/Services/AI/Providers/AIProviderInterface.php',
    APP_PATH . '/Services/AI/Providers/OpenAICompatibleProvider.php',
    APP_PATH . '/Services/AI/Providers/GeminiProvider.php',
    APP_PATH . '/Services/AI/Providers/OpenAIProvider.php',
    APP_PATH . '/Services/AI/Providers/DeepSeekProvider.php',
    APP_PATH . '/Services/AI/Providers/KimiProvider.php',
    APP_PATH . '/Services/AI/Providers/AnthropicProvider.php',
    APP_PATH . '/Services/AI/Providers/AIProviderManager.php',
    // Services (بترتيب الاعتماد)
    APP_PATH . '/Services/AI/KnowledgeBaseService.php',
    APP_PATH . '/Services/AI/BusinessHoursService.php',
    APP_PATH . '/Services/Chat/UnifiedInboxService.php',
    APP_PATH . '/Services/AI/AIConversationEngine.php',
    APP_PATH . '/Services/AI/LeadScoringService.php',
    APP_PATH . '/Services/AI/FollowUpAutomationService.php',
    APP_PATH . '/Services/AI/AiAnalyticsService.php',
    APP_PATH . '/Services/AI/AiReplySuggestionsService.php',
    APP_PATH . '/Services/Chat/MessengerAPI.php',
    APP_PATH . '/Services/Chat/InstagramAPI.php',
    APP_PATH . '/Services/Chat/EmailChannelAPI.php',
] as $aiChatClassFile) {
    if (file_exists($aiChatClassFile)) {
        require_once $aiChatClassFile;
    }
}
