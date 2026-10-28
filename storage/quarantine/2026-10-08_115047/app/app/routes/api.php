<?php
/**
 * Tourfecto - API Routes
 * تعريف جميع مسارات API الخاصة بالتطبيق
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// مسارات المصادقة (Authentication)
// ============================================
$router->post('/api/auth/login', 'AuthController', 'login');
$router->post('/api/auth/register', 'AuthController', 'register');
$router->post('/api/auth/logout', 'AuthController', 'logout');
$router->post('/api/auth/refresh', 'AuthController', 'refresh');
$router->post('/api/auth/forgot-password', 'AuthController', 'forgotPassword');
$router->post('/api/auth/reset-password', 'AuthController', 'resetPassword');
$router->post('/api/auth/verify-email', 'AuthController', 'verifyEmail');
$router->post('/api/auth/resend-verification', 'AuthController', 'resendVerification');

// ============================================
// مسارات المستخدم (User)
// ============================================
$router->get('/api/user/profile', 'UserController', 'profile', ['AuthMiddleware']);
$router->put('/api/user/profile', 'UserController', 'updateProfile', ['AuthMiddleware']);
$router->post('/api/user/avatar', 'UserController', 'uploadAvatar', ['AuthMiddleware']);
$router->put('/api/user/password', 'UserController', 'updatePassword', ['AuthMiddleware']);
$router->get('/api/user/settings', 'UserController', 'getSettings', ['AuthMiddleware']);
$router->put('/api/user/settings', 'UserController', 'updateSettings', ['AuthMiddleware']);
$router->delete('/api/user/account', 'UserController', 'deleteAccount', ['AuthMiddleware']);

// ============================================
// مسارات الذكاء الاصطناعي (AI)
// ============================================
$router->post('/api/ai/analyze', 'AIController', 'analyze', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_competitor_analysis'
]);
$router->get('/api/ai/report/{id}', 'AIController', 'getReport', ['AuthMiddleware']);
$router->get('/api/ai/reports', 'AIController', 'getReports', ['AuthMiddleware']);
$router->get('/api/ai/report/{id}/export', 'AIController', 'exportReport', ['AuthMiddleware']);
$router->delete('/api/ai/report/{id}', 'AIController', 'deleteReport', ['AuthMiddleware']);

// مولّد المقالات التسويقية
$router->post('/api/ai/article', 'AIController', 'generateArticle', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/ai/articles', 'AIController', 'getArticles', ['AuthMiddleware']);
$router->get('/api/ai/article/{id}', 'AIController', 'getArticle', ['AuthMiddleware']);
$router->get('/api/ai/article/{id}/export', 'AIController', 'exportArticle', ['AuthMiddleware']);
$router->delete('/api/ai/article/{id}', 'AIController', 'deleteArticle', ['AuthMiddleware']);

// نشر المقال مباشرة على ووردبريس
$router->get('/api/publishing/status/{website_id}', 'AIController', 'publishingStatus', ['AuthMiddleware']);
$router->post('/api/publishing/wordpress/connect', 'AIController', 'connectWordPress', ['AuthMiddleware']);
$router->post('/api/publishing/custom/connect', 'AIController', 'connectCustomApi', ['AuthMiddleware']);
$router->post('/api/publishing/disconnect/{website_id}', 'AIController', 'disconnectPublishing', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/publish', 'AIController', 'publishArticle', ['AuthMiddleware']);

// تحليلات إضافية
$router->post('/api/ai/keywords', 'AIController', 'analyzeKeywords', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->post('/api/ai/competitors', 'AIController', 'analyzeCompetitors', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_competitor_analysis'
]);
$router->post('/api/ai/sentiment', 'AIController', 'analyzeSentiment', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->post('/api/ai/translate', 'AIController', 'translate', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);

// ============================================
// مسارات إدارة السمعة (Reputation)
// ============================================
$router->get('/api/reputation/overview-data', 'ReputationController', 'getOverviewData', ['AuthMiddleware']);
$router->post('/api/reputation/review/{id}/dismiss', 'ReputationController', 'dismissReply', ['AuthMiddleware']);
$router->get('/api/reputation/reviews', 'ReputationController', 'getReviews', ['AuthMiddleware']);

$router->post('/api/revenue/records', 'RevenueController', 'createRecord', ['AuthMiddleware']);
$router->get('/api/revenue/kpis', 'RevenueController', 'getKpis', ['AuthMiddleware']);

$router->get('/api/website-optimizer/websites', 'WebsiteOptimizerController', 'listWebsites', ['AuthMiddleware']);
$router->post('/api/website-optimizer/audit', 'WebsiteOptimizerController', 'runAudit', ['AuthMiddleware']);

$router->get('/api/competitor-monitoring/detail', 'CompetitorMonitoringController', 'getDetail', ['AuthMiddleware']);
$router->post('/api/competitor-monitoring/pricing', 'CompetitorMonitoringController', 'addPricing', ['AuthMiddleware']);
$router->post('/api/competitor-monitoring/offers', 'CompetitorMonitoringController', 'addOffer', ['AuthMiddleware']);
$router->get('/api/competitor-monitoring/alerts', 'CompetitorMonitoringController', 'getAlerts', ['AuthMiddleware']);

$router->get('/api/executive/extras', 'ExecutiveExtrasController', 'getExtras', ['AuthMiddleware']);
$router->post('/api/executive/notes', 'ExecutiveExtrasController', 'addNote', ['AuthMiddleware']);
$router->post('/api/executive/tasks/{id}/complete', 'ExecutiveExtrasController', 'completeTask', ['AuthMiddleware']);
$router->post('/api/executive/alerts/{id}/read', 'ExecutiveExtrasController', 'markAlertRead', ['AuthMiddleware']);
$router->get('/api/reputation/review/{id}', 'ReputationController', 'getReview', ['AuthMiddleware']);
$router->get('/api/reputation/stats', 'ReputationController', 'getStats', ['AuthMiddleware']);
$router->post('/api/reputation/review/{id}/reply', 'ReputationController', 'sendReply', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_review_credits'
]);
$router->post('/api/reputation/review/{id}/generate-reply', 'ReputationController', 'generateReply', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->put('/api/reputation/review/{id}/reply', 'ReputationController', 'updateReply', ['AuthMiddleware']);
$router->delete('/api/reputation/review/{id}', 'ReputationController', 'deleteReview', ['AuthMiddleware']);

// منصات المراجعات
$router->post('/api/reputation/connect/tripadvisor', 'ReputationController', 'finalizeTripAdvisorConnection', ['AuthMiddleware']);
$router->get('/api/reputation/tripadvisor/search', 'ReputationController', 'searchTripAdvisor', ['AuthMiddleware']);
$router->post('/api/reputation/disconnect/tripadvisor/{website_id}', 'ReputationController', 'disconnectTripAdvisor', ['AuthMiddleware']);
// ملحوظة: ربط Google Business بقى GET redirect حقيقي تحت /reputation/connect/google/{website_id}
// (شوف web.php) مش POST API - محتاج يفتح نافذة موافقة Google، مش نداء AJAX.
$router->post('/api/reputation/connect/google/finalize', 'ReputationController', 'finalizeGoogleConnection', ['AuthMiddleware']);
$router->post('/api/reputation/disconnect/google/{website_id}', 'ReputationController', 'disconnectGoogleBusiness', ['AuthMiddleware']);
$router->get('/api/reputation/platforms', 'ReputationController', 'getPlatforms', ['AuthMiddleware']);

// Google Search Console (نفس منطق ربط Google Business: GET redirect حقيقي
// تحت /search-console/connect/{website_id} - شوف web.php - مش POST API)
$router->post('/api/search-console/finalize', 'SearchConsoleController', 'finalize', ['AuthMiddleware']);
$router->post('/api/search-console/disconnect/{website_id}', 'SearchConsoleController', 'disconnect', ['AuthMiddleware']);
$router->get('/api/search-console/stats/{website_id}', 'SearchConsoleController', 'stats', ['AuthMiddleware']);

// تصحيح: نفس المشكلة - webhook() كان method حقيقي شغال في ReputationController
// من غير ما يتسجل في أي route. ملحوظة مهمة: ده مش webhook رسمي من
// TripAdvisor/Google (المنصتين دول مبيقدموش webhooks عامة لأي طرف تالت
// أصلاً) - ده endpoint مخصص محتاج حد (أنت، أو أداة وسيطة زي Zapier/Make)
// يبعتله بيانات المراجعة يدويًا بصيغة معينة + secret. مش ربط تلقائي حقيقي
// بالمنصات لسه.
$router->post('/api/reputation/webhook', 'ReputationController', 'webhook');

// ============================================
// مسارات الشات (Chat)
// ============================================
$router->get('/api/chat/messages', 'ChatController', 'getMessages', ['AuthMiddleware']);
$router->get('/api/chat/conversation/{session_id}', 'ChatController', 'getConversation', ['AuthMiddleware']);
$router->get('/api/chat/pending', 'ChatController', 'getPendingApprovals', ['AuthMiddleware']);
$router->post('/api/chat/approve', 'ChatController', 'approveReply', ['AuthMiddleware']);
$router->post('/api/chat/send', 'ChatController', 'sendMessage', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_chat_credits'
]);
$router->post('/api/chat/generate-reply', 'ChatController', 'generateReply', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/chat/stats', 'ChatController', 'getStats', ['AuthMiddleware']);
$router->get('/api/chat/settings', 'ChatController', 'getSettings', ['AuthMiddleware']);
$router->put('/api/chat/settings', 'ChatController', 'updateSettings', ['AuthMiddleware']);
$router->delete('/api/chat/message/{id}', 'ChatController', 'deleteMessage', ['AuthMiddleware']);

// تصحيح: هذين المسارين كانا موجودين كـ methods حقيقية وشغالة في
// ChatController (webhook/verifyWebhook) من غير ما يتسجلوا في أي مكان
// في الراوتر أبدًا. النتيجة: مفيش أي طريقة كانت متاحة فعليًا لاستقبال
// رسائل واتساب واردة من Meta WhatsApp Cloud API - أي رسالة عميل ترسلها
// كانت هتضيع (404). من غير AuthMiddleware لأن Meta بتتحقق بتوكن verify
// خاص (WHATSAPP_WEBHOOK_VERIFY_TOKEN) مش بجلسة مستخدم.
$router->get('/api/chat/webhook', 'ChatController', 'verifyWebhook');
$router->post('/api/chat/webhook', 'ChatController', 'webhook');

// ربط UltraMsg (واتساب) لكل عميل بحسابه الخاص
$router->get('/api/chat/ultramsg/status', 'ChatController', 'getUltraMsgStatus', ['AuthMiddleware']);
$router->post('/api/chat/connect/ultramsg', 'ChatController', 'connectUltraMsg', ['AuthMiddleware']);
$router->post('/api/chat/disconnect/ultramsg/{website_id}', 'ChatController', 'disconnectUltraMsg', ['AuthMiddleware']);
// من غير AuthMiddleware - ده بيتنده من سيرفرات UltraMsg نفسها، مش من متصفح
// مستخدم عندنا. الحماية عن طريق secret موقّع لكل موقع (شوف ultraMsgWebhookSecret).
$router->post('/api/chat/webhook/ultramsg/{website_id}', 'ChatController', 'ultraMsgWebhook');

// ============================================
// مسارات المواقع الإلكترونية (Websites)
// ============================================
$router->get('/api/websites', 'WebsiteController', 'index', ['AuthMiddleware']);
$router->post('/api/websites', 'WebsiteController', 'store', ['AuthMiddleware']);
$router->get('/api/websites/{id}', 'WebsiteController', 'show', ['AuthMiddleware']);
$router->put('/api/websites/{id}', 'WebsiteController', 'update', ['AuthMiddleware']);
$router->delete('/api/websites/{id}', 'WebsiteController', 'destroy', ['AuthMiddleware']);
$router->post('/api/websites/{id}/verify', 'WebsiteController', 'verify', ['AuthMiddleware']);
$router->post('/api/websites/{id}/competitors', 'WebsiteController', 'updateCompetitors', ['AuthMiddleware']);

// ============================================
// مسارات الاشتراكات (Subscription)
// ============================================
$router->post('/api/subscription/validate', 'SubscriptionController', 'validateSubscriptionStatus', ['AuthMiddleware']);
$router->get('/api/subscription/current', 'SubscriptionController', 'current', ['AuthMiddleware']);
$router->post('/api/subscription/create', 'SubscriptionController', 'create', ['AuthMiddleware']);
$router->post('/api/subscription/renew', 'SubscriptionController', 'renew', ['AuthMiddleware']);
$router->post('/api/subscription/cancel', 'SubscriptionController', 'cancel', ['AuthMiddleware']);
$router->post('/api/subscription/upgrade', 'SubscriptionController', 'upgrade', ['AuthMiddleware']);
$router->get('/api/subscription/plans', 'SubscriptionController', 'getPlans');
$router->get('/api/subscription/invoices', 'SubscriptionController', 'getInvoices', ['AuthMiddleware']);
$router->get('/api/subscription/invoice/{id}', 'SubscriptionController', 'getInvoice', ['AuthMiddleware']);
$router->post('/api/subscription/payment', 'SubscriptionController', 'processPayment', ['AuthMiddleware']);

// ============================================
// مسارات لوحة التحكم (Dashboard)
// ============================================
$router->get('/api/dashboard/stats', 'DashboardController', 'getStats', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/reviews', 'DashboardController', 'getReviewChart', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/chat', 'DashboardController', 'getChatChart', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/api', 'DashboardController', 'getApiChart', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/ai', 'DashboardController', 'getAIChart', ['AuthMiddleware']);
$router->get('/api/dashboard/activity', 'DashboardController', 'getRecentActivity', ['AuthMiddleware']);
$router->get('/api/dashboard/notifications', 'DashboardController', 'getNotifications', ['AuthMiddleware']);
$router->post('/api/dashboard/notifications/{id}/read', 'DashboardController', 'markNotificationRead', ['AuthMiddleware']);
$router->get('/api/dashboard/login-history', 'DashboardController', 'getLoginHistory', ['AuthMiddleware']);

// ============================================
// مسارات إدارية (Admin)
// ============================================
$router->group('/api/admin', function($router) {
    // المستخدمين
    $router->get('/users', 'AdminController', 'getUsers', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}', 'AdminController', 'getUserById', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/users/{id}', 'AdminController', 'updateUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/users/{id}', 'AdminController', 'deleteUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/suspend', 'AdminController', 'suspendUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/activate', 'AdminController', 'activateUser', ['AuthMiddleware', 'AdminMiddleware']);
    
    // الاشتراكات
    $router->get('/subscriptions', 'AdminController', 'getSubscriptions', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/subscriptions/{id}', 'AdminController', 'getSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/subscriptions/{id}/cancel', 'AdminController', 'cancelSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    
    // النظام
    $router->get('/system/health', 'AdminController', 'systemHealth', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system/logs', 'AdminController', 'getLogs', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/system/cache/clear', 'AdminController', 'clearCache', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system/stats', 'AdminController', 'getSystemStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/platform-overview', 'AdminController', 'getPlatformOverview', ['AuthMiddleware', 'AdminMiddleware']);

    // سجل تسجيل الدخول (Login History)
    $router->get('/login-history', 'AdminController', 'getAllLoginHistory', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}/login-history', 'AdminController', 'getUserLoginHistory', ['AuthMiddleware', 'AdminMiddleware']);

    // انتحال حساب العميل (Impersonate)
    $router->post('/users/{id}/impersonate', 'AdminController', 'impersonateUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/impersonate/stop', 'AdminController', 'stopImpersonation', ['AuthMiddleware']);

    // تتبع الزوار (Visitor Analytics)
    $router->get('/visitors/stats', 'AdminController', 'visitorStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/visitors/log', 'AdminController', 'visitorLog', ['AuthMiddleware', 'AdminMiddleware']);
}, ['AuthMiddleware', 'AdminMiddleware']);

// ============================================
// مسارات التقارير (Reports)
// ============================================
$router->get('/api/reports/export', 'ReportController', 'export', ['AuthMiddleware']);
$router->get('/api/reports/scheduled', 'ReportController', 'getScheduled', ['AuthMiddleware']);
$router->post('/api/reports/schedule', 'ReportController', 'schedule', ['AuthMiddleware']);
$router->delete('/api/reports/schedule/{id}', 'ReportController', 'deleteSchedule', ['AuthMiddleware']);

// ============================================
// مسارات إعدادات المستخدم (Settings)
// ============================================
$router->get('/api/settings/general', 'SettingsController', 'getGeneral', ['AuthMiddleware']);
$router->put('/api/settings/general', 'SettingsController', 'updateGeneral', ['AuthMiddleware']);
$router->get('/api/settings/notifications', 'SettingsController', 'getNotifications', ['AuthMiddleware']);
$router->put('/api/settings/notifications', 'SettingsController', 'updateNotifications', ['AuthMiddleware']);
$router->get('/api/settings/api', 'SettingsController', 'getAPI', ['AuthMiddleware']);
$router->post('/api/settings/api/regenerate', 'SettingsController', 'regenerateAPIKey', ['AuthMiddleware']);
$router->get('/api/settings/languages', 'SettingsController', 'getLanguages');
$router->get('/api/settings/timezones', 'SettingsController', 'getTimezones');

// ============================================
// مسارات صحية (Health)
// ============================================
$router->get('/api/health', 'HealthController', 'check');
$router->get('/api/health/detailed', 'HealthController', 'detailed', ['AuthMiddleware']);
$router->get('/api/ping', 'HealthController', 'ping');

// ============================================
// مسارات التوثيق (Documentation)
// ============================================
$router->get('/api/docs', 'DocumentationController', 'index');
$router->get('/api/docs/openapi.json', 'DocumentationController', 'openapi');

// ============================================
// دمج الموديولات (2026-07-14)
// ============================================

// السوشيال ميديا
$router->get('/api/social/posts', 'SocialMediaController', 'listPosts', ['AuthMiddleware']);
$router->post('/api/social/posts', 'SocialMediaController', 'createPost', ['AuthMiddleware']);
$router->post('/api/social/generate-caption', 'SocialMediaController', 'generateCaption', ['AuthMiddleware']);
$router->get('/api/social/calendar', 'SocialMediaController', 'getCalendar', ['AuthMiddleware']);

// Creative Studio
$router->get('/api/creative-studio/media', 'CreativeStudioController', 'listMedia', ['AuthMiddleware']);
$router->post('/api/creative-studio/media', 'CreativeStudioController', 'requestMedia', ['AuthMiddleware']);
$router->get('/api/creative-studio/video-scripts', 'CreativeStudioController', 'listVideoScripts', ['AuthMiddleware']);
$router->post('/api/creative-studio/video-scripts', 'CreativeStudioController', 'requestVideoScript', ['AuthMiddleware']);

// مساعد التسويق الذكي
$router->post('/api/marketing-assistant/run', 'MarketingAssistantController', 'run', ['AuthMiddleware']);
$router->get('/api/marketing-assistant/history', 'MarketingAssistantController', 'history', ['AuthMiddleware']);

// White-Label
$router->get('/api/agency/list', 'AgencyController', 'list', ['AuthMiddleware']);
$router->post('/api/agency/create', 'AgencyController', 'create', ['AuthMiddleware']);

// إدارة الإعلانات
$router->get('/api/ads/campaigns', 'AdsController', 'list', ['AuthMiddleware']);
$router->post('/api/ads/campaigns', 'AdsController', 'create', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/copies', 'AdsController', 'listCopies', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/generate-copies', 'AdsController', 'generateCopies', ['AuthMiddleware']);
$router->get('/api/ads/meta/status', 'AdsController', 'getMetaConnectionStatus', ['AuthMiddleware']);
$router->post('/api/ads/meta/choose-account', 'AdsController', 'chooseMetaAdAccount', ['AuthMiddleware']);
$router->post('/api/ads/meta/sync', 'AdsController', 'syncMetaCampaigns', ['AuthMiddleware']);
$router->post('/api/ads/meta/disconnect', 'AdsController', 'disconnectMeta', ['AuthMiddleware']);

// CRM
$router->get('/api/crm/overview', 'CrmController', 'overview', ['AuthMiddleware']);

// ============================================
// دمج الموديولات (2026-07-14 - الدفعة الثانية)
// ============================================

// تتبع المنافسين المستمر (يسدّ فجوة /ai/competitors القديمة الفاضية)
// ملاحظة: /api/ai/competitors [POST] محجوز فعليًا لميزة أخرى غير مفعّلة
// (analyzeCompetitors - يتطلب require_competitor_analysis)، فاستُخدمت
// مسارات مميزة هنا لتفادي أي تعارض معها.
$router->get('/api/ai/competitors', 'AIController', 'listCompetitors', ['AuthMiddleware']);
$router->post('/api/ai/competitors/add', 'AIController', 'createCompetitor', ['AuthMiddleware']);
$router->post('/api/ai/competitors/{id}/analyze', 'AIController', 'analyzeCompetitor', ['AuthMiddleware']);

// الكلمات المفتاحية المتابَعة (يسدّ فجوة /ai/keywords القديمة الفاضية)
// نفس الملاحظة: /api/ai/keywords [POST] محجوز لـ analyzeKeywords الأصلية
$router->get('/api/ai/keywords', 'AIController', 'listKeywords', ['AuthMiddleware']);
$router->post('/api/ai/keywords/add', 'AIController', 'createKeyword', ['AuthMiddleware']);

// محتوى Google Business Profile وجدولته
$router->get('/api/gbp/content', 'GoogleBusinessContentController', 'listContent', ['AuthMiddleware']);
$router->post('/api/gbp/content', 'GoogleBusinessContentController', 'generate', ['AuthMiddleware']);
$router->post('/api/gbp/content/{id}/schedule', 'GoogleBusinessContentController', 'schedule', ['AuthMiddleware']);

// CRM - Leads/Contacts
$router->get('/api/crm/leads', 'CrmController', 'listLeads', ['AuthMiddleware']);
$router->post('/api/crm/leads', 'CrmController', 'createLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/status', 'CrmController', 'updateLeadStatus', ['AuthMiddleware']);
$router->get('/api/crm/pipeline-stages', 'CrmController', 'listPipelineStages', ['AuthMiddleware']);
$router->get('/api/crm/deals', 'CrmController', 'listDeals', ['AuthMiddleware']);
$router->post('/api/crm/deals', 'CrmController', 'createDeal', ['AuthMiddleware']);