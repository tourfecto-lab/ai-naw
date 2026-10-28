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
$router->get('/api/user/profile', 'UserController', 'profile', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:read']);
$router->put('/api/user/profile', 'UserController', 'updateProfile', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:write']);
$router->post('/api/user/avatar', 'UserController', 'uploadAvatar', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:write']);
$router->put('/api/user/password', 'UserController', 'updatePassword', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:write']);
$router->get('/api/user/settings', 'UserController', 'getSettings', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:read']);
$router->put('/api/user/settings', 'UserController', 'updateSettings', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:write']);
// Profile Center Phase 5 (2026-08-10): Two-Factor Authentication + OAuth disconnect
$router->delete('/api/user/oauth/{provider}', 'UserController', 'disconnectOAuth', ['AuthMiddleware']);
$router->post('/api/user/2fa/setup', 'UserController', 'setupTwoFactor', ['AuthMiddleware']);
$router->post('/api/user/2fa/enable', 'UserController', 'enableTwoFactor', ['AuthMiddleware']);
$router->post('/api/user/2fa/disable', 'UserController', 'disableTwoFactor', ['AuthMiddleware']);
$router->post('/api/user/2fa/re-enroll', 'UserController', 'reEnrollTwoFactor', ['AuthMiddleware']);
$router->post('/api/user/2fa/recovery-codes/regenerate', 'UserController', 'regenerateRecoveryCodes', ['AuthMiddleware']);
// Profile Center Phase 9 (2026-08-10): Data Export
$router->post('/api/user/data-export', 'UserController', 'requestDataExport', ['AuthMiddleware']);
$router->get('/api/user/data-export', 'UserController', 'listDataExports', ['AuthMiddleware']);
$router->delete('/api/user/account', 'UserController', 'deleteAccount', ['AuthMiddleware']);
// Tourfecto Account & Workspace Settings Center (Phases 2-8, 2026-08-09)
$router->get('/api/user/sessions', 'UserController', 'listSessions', ['AuthMiddleware']);
$router->post('/api/user/sessions/{id}/logout', 'UserController', 'logoutSession', ['AuthMiddleware']);
$router->patch('/api/user/sessions/{id}/name', 'UserController', 'renameSession', ['AuthMiddleware', 'ApiKeyScopeMiddleware:profile:write']);
$router->post('/api/user/sessions/logout-others', 'UserController', 'logoutOtherSessions', ['AuthMiddleware']);
$router->get('/api/user/api-keys', 'UserController', 'listApiKeys', ['AuthMiddleware']);
$router->post('/api/user/api-keys', 'UserController', 'createApiKey', ['AuthMiddleware']);
$router->post('/api/user/api-keys/{id}/revoke', 'UserController', 'revokeApiKey', ['AuthMiddleware']);
$router->get('/api/user/audit-log', 'UserController', 'listAuditLog', ['AuthMiddleware', 'ApiKeyScopeMiddleware:audit:read']);
$router->get('/api/user/audit-log/export', 'UserController', 'exportAuditLog', ['AuthMiddleware', 'ApiKeyScopeMiddleware:audit:read']);
$router->post('/api/user/deactivate', 'UserController', 'deactivateAccount', ['AuthMiddleware']);
$router->get('/api/workspace', 'WorkspaceController', 'getWorkspace', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:read']);
$router->put('/api/workspace', 'WorkspaceController', 'updateWorkspace', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->post('/api/workspace/logo', 'WorkspaceController', 'uploadLogo', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->get('/api/workspace/members', 'WorkspaceController', 'listMembers', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:read']);
$router->post('/api/workspace/invite', 'WorkspaceController', 'inviteMember', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->get('/api/workspace/invites', 'WorkspaceController', 'listInvites', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:read']);
$router->post('/api/workspace/invites/{id}/revoke', 'WorkspaceController', 'revokeInvite', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->put('/api/workspace/members/{id}/role', 'WorkspaceController', 'changeRole', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->post('/api/workspace/members/{id}/deactivate', 'WorkspaceController', 'deactivateMember', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->post('/api/workspace/members/{id}/reactivate', 'WorkspaceController', 'reactivateMember', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->delete('/api/workspace/members/{id}', 'WorkspaceController', 'removeMember', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->post('/api/workspace/leave', 'WorkspaceController', 'leaveWorkspace', ['AuthMiddleware', 'ApiKeyScopeMiddleware:workspace:write']);
$router->get('/api/workspace/invite/{token}', 'WorkspaceController', 'showInvite');
$router->post('/api/workspace/invite/{token}/accept', 'WorkspaceController', 'acceptInvite');

// ============================================
// Business Control Center (Phases 1-7, 2026-08-14)
// Business Profile منفصل عن User Profile + Locations + Services +
// Target Markets + AI Business Context + Brand Settings.
// كل المسارات AuthMiddleware-protected (زي باقي /api/user/*).
// ============================================
$router->get('/api/business', 'BusinessController', 'show', ['AuthMiddleware']);
$router->get('/api/business/overview', 'BusinessController', 'overview', ['AuthMiddleware']);
$router->post('/api/business', 'BusinessController', 'store', ['AuthMiddleware']);
$router->put('/api/business/{id}', 'BusinessController', 'update', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/locations', 'BusinessLocationController', 'index', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/locations', 'BusinessLocationController', 'store', ['AuthMiddleware']);
$router->put('/api/business/locations/{id}', 'BusinessLocationController', 'update', ['AuthMiddleware']);
$router->delete('/api/business/locations/{id}', 'BusinessLocationController', 'destroy', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/services', 'BusinessServiceController', 'index', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/services', 'BusinessServiceController', 'store', ['AuthMiddleware']);
$router->put('/api/business/services/{id}', 'BusinessServiceController', 'update', ['AuthMiddleware']);
$router->delete('/api/business/services/{id}', 'BusinessServiceController', 'destroy', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/markets', 'BusinessTargetMarketController', 'show', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/markets', 'BusinessTargetMarketController', 'upsert', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/ai-context', 'BusinessAiContextController', 'show', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/ai-context/full', 'BusinessAiContextController', 'full', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/ai-context', 'BusinessAiContextController', 'upsert', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/brand', 'BusinessBrandSettingsController', 'show', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/brand', 'BusinessBrandSettingsController', 'upsert', ['AuthMiddleware']);
// ============================================
// Business Control Center - Team Management + RBAC (Phase 10-11)
// كل المسارات AuthMiddleware-protected + فحص صلاحية عبر BusinessAccessService
// ============================================
$router->get('/api/business/{businessId}/team', 'BusinessTeamController', 'index', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/team/invite', 'BusinessTeamController', 'invite', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/team/invite/{token}/accept', 'BusinessTeamController', 'acceptInvite', ['AuthMiddleware']);
$router->delete('/api/business/{businessId}/team/members/{memberId}', 'BusinessTeamController', 'remove', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/team/members/{memberId}/role', 'BusinessTeamController', 'changeRole', ['AuthMiddleware']);

// ============================================
// Business Control Center - Integrations/API Keys/Audit Log/Onboarding
// (Phases 8-9, 12, 13-14, 17 - 2026-08-15)
// كل المسارات AuthMiddleware-protected + فحص صلاحية عبر BusinessAccessService
// ============================================
$router->get('/api/business/{businessId}/integrations', 'BusinessIntegrationsController', 'index', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/api-keys', 'BusinessApiKeyController', 'index', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/api-keys', 'BusinessApiKeyController', 'store', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/api-keys/{id}/revoke', 'BusinessApiKeyController', 'revoke', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/audit-log', 'BusinessAuditLogController', 'index', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/onboarding', 'BusinessOnboardingController', 'status', ['AuthMiddleware']);

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
$router->get('/api/ai/articles/image-check', 'AIController', 'articleImageCheck', ['AuthMiddleware']);
$router->get('/api/ai/article/{id}', 'AIController', 'getArticle', ['AuthMiddleware']);
$router->get('/api/ai/article/{id}/export', 'AIController', 'exportArticle', ['AuthMiddleware']);
$router->delete('/api/ai/article/{id}', 'AIController', 'deleteArticle', ['AuthMiddleware']);

// نشر المقال مباشرة على ووردبريس
$router->get('/api/publishing/status/{website_id}', 'AIController', 'publishingStatus', ['AuthMiddleware']);
$router->post('/api/publishing/wordpress/connect', 'AIController', 'connectWordPress', ['AuthMiddleware']);
$router->post('/api/publishing/custom/connect', 'AIController', 'connectCustomApi', ['AuthMiddleware']);
$router->post('/api/publishing/disconnect/{website_id}', 'AIController', 'disconnectPublishing', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/publish', 'AIController', 'publishArticle', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/image', 'AIController', 'generateArticleImage', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/schedule', 'AIController', 'scheduleArticle', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/schedule/cancel', 'AIController', 'cancelScheduledArticle', ['AuthMiddleware']);

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
$router->get('/api/reputation/export-reviews', 'ReputationController', 'exportReviewsCsv', ['AuthMiddleware']);

$router->post('/api/revenue/records', 'RevenueController', 'createRecord', ['AuthMiddleware']);
$router->get('/api/revenue/kpis', 'RevenueController', 'getKpis', ['AuthMiddleware']);

// ============================================
// TOURFECTO AI REVENUE INTELLIGENCE (module, additive only)
// ============================================
$router->get('/api/revenue-intelligence/overview', 'RevenueIntelligenceController', 'apiOverview', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/sources', 'RevenueIntelligenceController', 'apiSources', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/products', 'RevenueIntelligenceController', 'apiProducts', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/forecast', 'RevenueIntelligenceController', 'apiForecast', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/forecast/accuracy', 'RevenueIntelligenceController', 'apiForecastAccuracy', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/opportunities', 'RevenueIntelligenceController', 'apiOpportunities', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/risks', 'RevenueIntelligenceController', 'apiRisks', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/anomalies', 'RevenueIntelligenceController', 'apiAnomalies', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/customers', 'RevenueIntelligenceController', 'apiCustomers', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/segments', 'RevenueIntelligenceController', 'apiSegments', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/pipeline', 'RevenueIntelligenceController', 'apiPipeline', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/actions', 'RevenueIntelligenceController', 'apiActions', ['AuthMiddleware']);
$router->post('/api/revenue-intelligence/actions/execute', 'RevenueIntelligenceController', 'apiActionsExecute', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/actions/history', 'RevenueIntelligenceController', 'apiActionsHistory', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/executive-summary', 'RevenueIntelligenceController', 'apiExecutiveSummary', ['AuthMiddleware']);
$router->post('/api/revenue-intelligence/assistant/ask', 'RevenueIntelligenceController', 'apiAssistantAsk', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/retention', 'RevenueIntelligenceController', 'apiRetention', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/subscriptions', 'RevenueIntelligenceController', 'apiSubscriptionMetrics', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/forecast/deals', 'RevenueIntelligenceController', 'apiDealForecast', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/attribution', 'RevenueIntelligenceController', 'apiSalesAttribution', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/benchmarks', 'RevenueIntelligenceController', 'apiBenchmarks', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/quotas', 'RevenueIntelligenceController', 'apiQuotas', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/churn', 'RevenueIntelligenceController', 'apiChurnAnalytics', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/dashboard-prefs', 'RevenueIntelligenceController', 'apiDashboardPrefsGet', ['AuthMiddleware']);
$router->post('/api/revenue-intelligence/dashboard-prefs', 'RevenueIntelligenceController', 'apiDashboardPrefsSave', ['AuthMiddleware']);
$router->post('/api/revenue-intelligence/dashboard-prefs/reset', 'RevenueIntelligenceController', 'apiDashboardPrefsReset', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/stripe/settings', 'RevenueIntelligenceController', 'apiStripeSettingsGet', ['AuthMiddleware']);
$router->post('/api/revenue-intelligence/stripe/settings', 'RevenueIntelligenceController', 'apiStripeSettingsSave', ['AuthMiddleware']);
// Webhook من Stripe نفسه - بدون AuthMiddleware (يُتحقق بالتوقيع)، ولا يمس
// أي بيانات مالك الحساب خارج نطاق user_id المحدد.
$router->post('/api/revenue-intelligence/stripe/webhook/{user_id}', 'RevenueIntelligenceController', 'apiStripeWebhook');
$router->get('/api/revenue-intelligence/reports/export', 'RevenueIntelligenceController', 'apiExportReport', ['AuthMiddleware']);

$router->get('/api/website-optimizer/websites', 'WebsiteOptimizerController', 'listWebsites', ['AuthMiddleware']);
$router->post('/api/website-optimizer/audit', 'WebsiteOptimizerController', 'runAudit', ['AuthMiddleware']);
$router->get('/api/website-optimizer/history', 'WebsiteOptimizerController', 'history', ['AuthMiddleware']);
$router->get('/api/website-optimizer/fixes', 'WebsiteOptimizerController', 'listFixes', ['AuthMiddleware']);
$router->post('/api/website-optimizer/fixes/{id}/status', 'WebsiteOptimizerController', 'updateFixStatus', ['AuthMiddleware']);
// تنفيذ الإصلاحات تلقائي على موقع WordPress (Tourfecto Edge)
$router->get('/api/edge/rules', 'EdgeFixController', 'rules');
$router->get('/api/website-optimizer/edge/status', 'EdgeFixController', 'status', ['AuthMiddleware']);
$router->post('/api/website-optimizer/fixes/{id}/edge-preview', 'EdgeFixController', 'preview', ['AuthMiddleware']);
$router->post('/api/website-optimizer/fixes/{id}/edge-apply', 'EdgeFixController', 'apply', ['AuthMiddleware']);
$router->post('/api/website-optimizer/fixes/{id}/edge-rollback', 'EdgeFixController', 'rollback', ['AuthMiddleware']);
$router->post('/api/website-optimizer/edge-apply-all', 'EdgeFixController', 'applyAll', ['AuthMiddleware']);

$router->get('/api/competitor-monitoring/detail', 'CompetitorMonitoringController', 'getDetail', ['AuthMiddleware']);
$router->post('/api/competitor-monitoring/pricing', 'CompetitorMonitoringController', 'addPricing', ['AuthMiddleware']);
$router->post('/api/competitor-monitoring/offers', 'CompetitorMonitoringController', 'addOffer', ['AuthMiddleware']);
$router->get('/api/competitor-monitoring/alerts', 'CompetitorMonitoringController', 'getAlerts', ['AuthMiddleware']);
$router->get('/api/competitor-monitoring/summary', 'CompetitorMonitoringController', 'getSummary', ['AuthMiddleware']);

// ============================================
// Competitor Intelligence (موديول موحّد جديد)
// ============================================
$router->get('/api/competitor-intelligence/dashboard', 'CompetitorIntelligenceController', 'apiDashboard', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/competitors', 'CompetitorIntelligenceController', 'apiListCompetitors', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors', 'CompetitorIntelligenceController', 'apiAddCompetitor', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/bulk-import', 'CompetitorIntelligenceController', 'apiBulkImportCompetitors', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}', 'CompetitorIntelligenceController', 'apiCompetitorProfile', ['AuthMiddleware']);
$router->put('/api/competitor-intelligence/competitors/{id}', 'CompetitorIntelligenceController', 'apiUpdateCompetitor', ['AuthMiddleware']);
$router->delete('/api/competitor-intelligence/competitors/{id}', 'CompetitorIntelligenceController', 'apiDeleteCompetitor', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/check-now', 'CompetitorIntelligenceController', 'apiCheckNow', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/timeline', 'CompetitorIntelligenceController', 'apiTimeline', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/price-history', 'CompetitorIntelligenceController', 'apiPriceHistory', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/scan-insights', 'CompetitorIntelligenceController', 'apiScanInsights', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/analyze-profile', 'CompetitorIntelligenceController', 'apiAnalyzeProfile', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/compute-scorecard', 'CompetitorIntelligenceController', 'apiComputeScorecard', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/scorecard-trend', 'CompetitorIntelligenceController', 'apiScorecardTrend', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/keyword-rankings', 'CompetitorIntelligenceController', 'apiKeywordRankings', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/keyword-rankings', 'CompetitorIntelligenceController', 'apiRecordKeywordRanking', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/keyword-rankings/history', 'CompetitorIntelligenceController', 'apiKeywordRankingHistory', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/keyword-rankings/check', 'CompetitorIntelligenceController', 'apiKeywordRankingCheck', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/product-prices', 'CompetitorIntelligenceController', 'apiProductPrices', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/product-prices', 'CompetitorIntelligenceController', 'apiRecordProductPrice', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/product-prices/history', 'CompetitorIntelligenceController', 'apiProductPriceHistory', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/battlecard', 'CompetitorIntelligenceController', 'apiBattlecard', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/battlecard/generate', 'CompetitorIntelligenceController', 'apiGenerateBattlecard', ['AuthMiddleware']);

$router->post('/api/competitor-intelligence/discovery/suggest', 'CompetitorIntelligenceController', 'apiDiscoverySuggest', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/discovery/run', 'CompetitorIntelligenceController', 'apiDiscoveryRun', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/discovery', 'CompetitorIntelligenceController', 'apiDiscoveryList', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/discovery/{id}/approve', 'CompetitorIntelligenceController', 'apiDiscoveryApprove', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/discovery/{id}/dismiss', 'CompetitorIntelligenceController', 'apiDiscoveryDismiss', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/watchlist', 'CompetitorIntelligenceController', 'apiWatchlist', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/watchlist', 'CompetitorIntelligenceController', 'apiWatchlistUpsert', ['AuthMiddleware']);
$router->delete('/api/competitor-intelligence/watchlist/{id}', 'CompetitorIntelligenceController', 'apiWatchlistRemove', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/activity', 'CompetitorIntelligenceController', 'apiActivityFeed', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/comparison', 'CompetitorIntelligenceController', 'apiComparison', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/comparison/export', 'CompetitorIntelligenceController', 'apiComparisonExport', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/alerts', 'CompetitorIntelligenceController', 'apiAlerts', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/alerts/{id}/read', 'CompetitorIntelligenceController', 'apiMarkAlertRead', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/alerts/read-all', 'CompetitorIntelligenceController', 'apiMarkAllAlertsRead', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/alerts/unread-count', 'CompetitorIntelligenceController', 'apiUnreadAlertsCount', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/insights', 'CompetitorIntelligenceController', 'apiInsights', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/insights/{id}/status', 'CompetitorIntelligenceController', 'apiInsightStatus', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/ai/ask', 'CompetitorIntelligenceController', 'apiAiAsk', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/ai/weekly-summary', 'CompetitorIntelligenceController', 'apiAiWeeklySummary', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/reports', 'CompetitorIntelligenceController', 'apiListReports', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/reports', 'CompetitorIntelligenceController', 'apiGenerateReport', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/reports/{id}', 'CompetitorIntelligenceController', 'apiGetReport', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/settings', 'CompetitorIntelligenceController', 'apiGetSettings', ['AuthMiddleware']);
$router->put('/api/competitor-intelligence/settings', 'CompetitorIntelligenceController', 'apiUpdateSettings', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/settings/pause-all', 'CompetitorIntelligenceController', 'apiPauseAllMonitoring', ['AuthMiddleware']);

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
// Google Analytics 4 (نفس نمط Search Console: GET redirect في web.php)
$router->post('/api/google-analytics/finalize', 'GoogleAnalyticsController', 'finalize', ['AuthMiddleware']);
$router->post('/api/google-analytics/disconnect/{website_id}', 'GoogleAnalyticsController', 'disconnect', ['AuthMiddleware']);
$router->get('/api/google-analytics/stats/{website_id}', 'GoogleAnalyticsController', 'stats', ['AuthMiddleware']);
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
// ملحوظة: /api/chat/stats كانت مسجّلة قبل كده لدالة getStats غير موجودة
// أصلاً في ChatController ومفيش أي زرار بينده عليها - اتشالت عشان متسببش
// خطأ فادح لو حد فتحها. لو محتاجينها فعليًا (كارت إحصائيات شات مثلاً)، تقدر تتبنى بسهولة.
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
// منصات الحجز والوساطة السياحية - OTA
// (GetYourGuide, Viator) - كل عميل بيربط حساب الـ Partner بتاعه هو
// ============================================
$router->get('/api/ota/status', 'OTAController', 'getStatus', ['AuthMiddleware']);
$router->post('/api/ota/connect', 'OTAController', 'connect', ['AuthMiddleware']);
$router->post('/api/ota/disconnect/{platform}/{website_id}', 'OTAController', 'disconnect', ['AuthMiddleware']);

// ============================================
// مسارات المواقع الإلكترونية (Websites)
// ============================================
$router->get('/api/websites', 'WebsiteController', 'index', ['AuthMiddleware']);
$router->post('/api/websites', 'WebsiteController', 'store', ['AuthMiddleware']);
$router->get('/api/websites/{id}', 'WebsiteController', 'show', ['AuthMiddleware']);
$router->put('/api/websites/{id}', 'WebsiteController', 'update', ['AuthMiddleware']);
$router->delete('/api/websites/{id}', 'WebsiteController', 'destroy', ['AuthMiddleware']);
$router->post('/api/websites/{id}/verify', 'WebsiteController', 'verify', ['AuthMiddleware']);
// ربط WordPress (بضغطة واحدة أو يدوي) + توثيق الملكية تلقائيًا لحساب الأدمن
$router->get('/api/websites/{id}/platform', 'WordPressConnectController', 'platform', ['AuthMiddleware']);
$router->post('/api/websites/{id}/wordpress/connect', 'WordPressConnectController', 'connect', ['AuthMiddleware']);
$router->get('/api/websites/{id}/wordpress/status', 'WordPressConnectController', 'status', ['AuthMiddleware']);
$router->post('/api/websites/{id}/wordpress/disconnect', 'WordPressConnectController', 'disconnect', ['AuthMiddleware']);
$router->post('/api/websites/{id}/competitors', 'WebsiteController', 'updateCompetitors', ['AuthMiddleware']);

// ============================================
// مسارات الاشتراكات (Subscription)
// ============================================
$router->post('/api/subscription/validate', 'SubscriptionController', 'validateSubscriptionStatus', ['AuthMiddleware']);
$router->get('/api/subscription/current', 'SubscriptionController', 'current', ['AuthMiddleware']);
$router->post('/api/subscription/create', 'SubscriptionController', 'create', ['AuthMiddleware']);
$router->post('/api/subscription/renew', 'SubscriptionController', 'renew', ['AuthMiddleware']);
$router->post('/api/subscription/cancel', 'SubscriptionController', 'cancel', ['AuthMiddleware']);
$router->get('/api/wallet/balance', 'WalletController', 'getBalance', ['AuthMiddleware']);
$router->post('/api/wallet/redeem-card', 'WalletController', 'redeemCard', ['AuthMiddleware']);
$router->get('/api/wallet/history', 'WalletController', 'getHistory', ['AuthMiddleware']);
$router->post('/api/wallet/deposit', 'WalletController', 'requestDeposit', ['AuthMiddleware']);
$router->post('/api/wallet/subscribe', 'WalletController', 'subscribeWithBalance', ['AuthMiddleware']);
$router->post('/api/subscription/upgrade', 'SubscriptionController', 'upgrade', ['AuthMiddleware']);
$router->get('/api/subscription/plans', 'SubscriptionController', 'getPlans');
$router->get('/api/subscription/invoices', 'SubscriptionController', 'getInvoices', ['AuthMiddleware']);
$router->get('/api/subscription/invoice/{id}', 'SubscriptionController', 'getInvoice', ['AuthMiddleware']);
$router->post('/api/subscription/payment', 'SubscriptionController', 'processPayment', ['AuthMiddleware']);
$router->get('/api/subscription/billing-profile', 'SubscriptionController', 'getBillingProfile', ['AuthMiddleware']);
$router->put('/api/subscription/billing-profile', 'SubscriptionController', 'updateBillingProfile', ['AuthMiddleware']);

// ============================================
// مسارات لوحة التحكم (Dashboard)
// ============================================
$router->get('/api/dashboard/stats', 'DashboardController', 'getStats', ['AuthMiddleware']);
$router->get('/api/dashboard/smart-insights', 'DashboardController', 'smartInsights', ['AuthMiddleware']);
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
$router->group('/api/admin', function ($router) {
    // Phase 4 - AI Usage & Cost Tracking
    $router->get('/ai-usage-stats', 'AdminController', 'aiUsageStats', ['AuthMiddleware', 'AdminMiddleware']);
    // Profile Center Phase 5 - مسار طوارئ لإلغاء 2FA لمستخدم فقد جهازه
    $router->post('/users/{id}/reset-2fa', 'AdminController', 'resetUserTwoFactor', ['AuthMiddleware', 'AdminMiddleware']);
    // المستخدمين
    $router->get('/users', 'AdminController', 'getUsers', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}', 'AdminController', 'getUserById', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/users/{id}', 'AdminController', 'updateUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/broadcast', 'AdminController', 'broadcast', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/branding', 'AdminController', 'updateBranding', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/branding/logo', 'AdminController', 'uploadLogo', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/branding/favicon', 'AdminController', 'uploadFavicon', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/new-features-stats', 'AdminController', 'newFeaturesStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/features', 'AdminController', 'listFeatures', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/features/{key}', 'AdminController', 'updateFeature', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/legal-content', 'AdminController', 'updateLegalContent', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system-settings', 'AdminController', 'getSystemSettings', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/system-settings', 'AdminController', 'updateSystemSettings', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/faq', 'AdminController', 'listFaqAdmin', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/faq', 'AdminController', 'createFaq', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/faq/{id}', 'AdminController', 'updateFaq', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/faq/{id}', 'AdminController', 'deleteFaq', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/users/{id}', 'AdminController', 'deleteUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/suspend', 'AdminController', 'suspendUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/activate', 'AdminController', 'activateUser', ['AuthMiddleware', 'AdminMiddleware']);

    // الاشتراكات
    $router->get('/subscriptions', 'AdminController', 'getSubscriptions', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/subscriptions/{id}', 'AdminController', 'getSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    // إصلاح: زر "تفعيل اشتراك يدوي" كان بينادي المسار ده وهو مش متسجّل (404)
    $router->post('/subscriptions/activate', 'AdminController', 'activateSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/subscriptions/{id}/cancel', 'AdminController', 'cancelSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/subscriptions/run-lifecycle-checks', 'AdminController', 'runSubscriptionLifecycleChecks', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/invoices/run-lifecycle-checks', 'AdminController', 'runInvoiceLifecycleChecks', ['AuthMiddleware', 'BillingAdminMiddleware']);

    // رسائل التواصل
    $router->get('/contact-messages', 'AdminController', 'getContactMessages', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/contact-messages/{id}/read', 'AdminController', 'markContactMessageRead', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/plans', 'AdminController', 'listPlansAdmin', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/wallet/pending', 'WalletController', 'listPendingDeposits', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->post('/users/{id}/add-balance', 'WalletController', 'adminAddBalance', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/users/{id}/deduct-balance', 'WalletController', 'adminDeductBalance', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/wallet/platform-balance', 'WalletController', 'getPlatformBalance', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->post('/wallet/platform-balance/reset', 'WalletController', 'resetPlatformBalance', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/wallet/platform-balance/restore', 'WalletController', 'restorePlatformBalance', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/wallet/zero-all-balances', 'WalletController', 'zeroAllBalances', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/wallet/cards/generate', 'WalletController', 'generateCards', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/wallet/cards', 'WalletController', 'listCards', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->get('/wallet/stats', 'WalletController', 'getAdminStats', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->get('/wallet/mrr-trend', 'WalletController', 'getMrrTrend', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->get('/wallet/usage-revenue', 'WalletController', 'getUsageRevenueBreakdown', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->post('/wallet/{id}/approve', 'WalletController', 'approveDeposit', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/wallet/{id}/reject', 'WalletController', 'rejectDeposit', ['AuthMiddleware', 'BillingAdminMiddleware']);
    // getPaymentSettingsAdmin بيكشف تفاصيل IBAN/PayPal الحقيقية اللي
    // المنصة بتستقبل فيها الفلوس - فسيبناه Billing Admin (manager+) بس،
    // مش متاح حتى لدور "اطّلاع فقط" (agent).
    $router->get('/wallet/settings', 'WalletController', 'getPaymentSettingsAdmin', ['AuthMiddleware', 'BillingAdminMiddleware']);
    // تصحيح (2026-08-09 / Phase 7): ده أخطر إجراء في القسم ده - بيغيّر
    // IBAN/PayPal اللي بتستلم فيها المنصة الفلوس فعليًا - فسيبناه Admin
    // كامل بس (مش Billing Admin) عن قصد، حتى لو باقي عمليات الفوترة
    // بقت متاحة لـ manager.
    $router->put('/wallet/settings', 'WalletController', 'updatePaymentSettingsAdmin', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/wallet/usage-pricing', 'WalletController', 'listUsagePricingAdmin', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->put('/wallet/usage-pricing/{id}', 'WalletController', 'updateUsagePricingAdmin', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/refunds', 'WalletController', 'listRefunds', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/refunds', 'WalletController', 'createRefund', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/tax-rules', 'WalletController', 'listTaxRules', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/tax-rules', 'WalletController', 'upsertTaxRule', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->put('/plans/{id}', 'AdminController', 'updatePlan', ['AuthMiddleware', 'AdminMiddleware']);

    // النظام
    $router->get('/system/health', 'AdminController', 'systemHealth', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system/logs', 'AdminController', 'getLogs', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/system/cache/clear', 'AdminController', 'clearCache', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system/stats', 'AdminController', 'getSystemStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/platform-overview', 'AdminController', 'getPlatformOverview', ['AuthMiddleware', 'AdminMiddleware']);

    // Admin Pro - مؤشرات الأداء، التنبيهات، النشاط، البحث الشامل، صحة النظام
    $router->get('/pro/overview', 'AdminProController', 'overview', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/pro/attention', 'AdminProController', 'attention', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/pro/activity', 'AdminProController', 'activity', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/pro/search', 'AdminProController', 'search', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/pro/health', 'AdminProController', 'health', ['AuthMiddleware', 'AdminMiddleware']);
    // Admin Ops - الفريق والصلاحيات، التدقيق، الاستهلاك، المهام، الأمان، ملف العميل 360
    $router->get('/ops/me', 'AdminOpsController', 'me', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/team', 'AdminOpsController', 'team', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/team/invite', 'AdminOpsController', 'teamInvite', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/team/{id}/role', 'AdminOpsController', 'teamSetRole', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/team/{id}/remove', 'AdminOpsController', 'teamRemove', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/audit', 'AdminOpsController', 'audit', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/audit/export', 'AdminOpsController', 'auditExport', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/ai-usage', 'AdminOpsController', 'aiUsage', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/ai-routing', 'AdminOpsController', 'aiRouting', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/jobs', 'AdminOpsController', 'jobs', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/jobs/retry-failed', 'AdminOpsController', 'jobsRetryFailed', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/jobs/{id}/retry', 'AdminOpsController', 'jobRetry', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/jobs/{id}/cancel', 'AdminOpsController', 'jobCancel', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/ops/security', 'AdminOpsController', 'security', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/security/unblock', 'AdminOpsController', 'securityUnblock', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/ops/security/force-logout', 'AdminOpsController', 'securityForceLogout', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/pro/user/{id}', 'AdminOpsController', 'user360', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/pro/user/{id}/notes', 'AdminOpsController', 'userNoteAdd', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/pro/user/{id}/notes/{noteId}', 'AdminOpsController', 'userNoteDelete', ['AuthMiddleware', 'AdminMiddleware']);

    // سجل تسجيل الدخول (Login History)
    $router->get('/login-history', 'AdminController', 'getAllLoginHistory', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}/login-history', 'AdminController', 'getUserLoginHistory', ['AuthMiddleware', 'AdminMiddleware']);
    // Phase 20.1: لوحة فونيل الـOnboarding (viewed → step N → submitted → completed)
    $router->get('/onboarding-funnel', 'AdminController', 'onboardingFunnel', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/feature-overrides', 'AdminController', 'addUserFeatureOverride', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/users/{id}/feature-overrides/{key}', 'AdminController', 'removeUserFeatureOverride', ['AuthMiddleware', 'AdminMiddleware']);

    // انتحال حساب العميل (Impersonate)
    $router->post('/users/{id}/impersonate', 'AdminController', 'impersonateUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/impersonate/stop', 'AdminController', 'stopImpersonation', ['AuthMiddleware']);

    // تتبع الزوار (Visitor Analytics)
    $router->get('/visitors/stats', 'AdminController', 'visitorStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/visitors/log', 'AdminController', 'visitorLog', ['AuthMiddleware', 'AdminMiddleware']);

    // مفاتيح API الخاصة بالشركاء الخارجيين (Partner API Keys)
    // جزء من المرحلة 2 من خطة API Gateway - إدارة كاملة من لوحة الأدمن
    $router->get('/partner-keys', 'PartnerKeyController', 'list', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/partner-keys', 'PartnerKeyController', 'create', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/partner-keys/{id}', 'PartnerKeyController', 'revoke', ['AuthMiddleware', 'AdminMiddleware']);
}, ['AuthMiddleware', 'AdminMiddleware']);

// ============================================
// Partner API - نقاط وصول للشركاء الخارجيين
// مصادقة مستقلة تمامًا عن المستخدم العادي: X-API-Key header بدل
// Session/Bearer token، وصلاحيات (scopes) محدودة لكل مفتاح.
// راجع PartnerAuthMiddleware للتفاصيل.
// ============================================
$router->get('/api/partner/ping', 'PartnerController', 'ping', ['PartnerAuthMiddleware']);
$router->get(
    '/api/partner/websites/{website_id}/reputation-summary',
    'PartnerController',
    'reputationSummary',
    ['PartnerAuthMiddleware:reputation:read']
);

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
// ملحوظة: /api/docs/openapi.json كانت مسجّلة قبل كده لدالة openapi غير
// موجودة أصلاً في DocumentationController - اتشالت عشان متسببش خطأ فادح.
// لو محتاجينها فعليًا (مواصفة OpenAPI JSON للمطورين)، تقدر تتبنى بسهولة
// بنفس منطق تجميع الـ routes الموجود بالفعل في DocumentationController::api().

// ============================================
// دمج الموديولات (2026-07-14)
// ============================================

// السوشيال ميديا
$router->get('/api/social/connections', 'SocialMediaController', 'listConnections', ['AuthMiddleware']);
$router->get('/api/social/posts', 'SocialMediaController', 'listPosts', ['AuthMiddleware']);
$router->post('/api/social/posts', 'SocialMediaController', 'createPost', ['AuthMiddleware']);
$router->post('/api/social/generate-caption', 'SocialMediaController', 'generateCaption', ['AuthMiddleware']);
$router->get('/api/social/calendar', 'SocialMediaController', 'getCalendar', ['AuthMiddleware']);

// Creative Studio
$router->get('/api/creative-studio/media', 'CreativeStudioController', 'listMedia', ['AuthMiddleware']);
$router->post('/api/creative-studio/media', 'CreativeStudioController', 'requestMedia', ['AuthMiddleware']);
$router->get('/api/creative-studio/video-scripts', 'CreativeStudioController', 'listVideoScripts', ['AuthMiddleware']);
$router->post('/api/creative-studio/video-scripts', 'CreativeStudioController', 'requestVideoScript', ['AuthMiddleware']);
$router->post('/api/creative-studio/video', 'CreativeStudioController', 'requestVideo', ['AuthMiddleware']);
$router->post('/api/creative-studio/enhance-prompt', 'CreativeStudioController', 'enhancePrompt', ['AuthMiddleware']);

// مساعد التسويق الذكي
$router->post('/api/marketing-assistant/run', 'MarketingAssistantController', 'run', ['AuthMiddleware']);
$router->get('/api/marketing-assistant/history', 'MarketingAssistantController', 'history', ['AuthMiddleware']);

// White-Label
$router->get('/api/agency/list', 'AgencyController', 'list', ['AuthMiddleware']);
$router->post('/api/agency/create', 'AgencyController', 'create', ['AuthMiddleware']);
$router->get('/api/agency/{id}/clients', 'AgencyController', 'listClients', ['AuthMiddleware']);
$router->post('/api/agency/{id}/clients', 'AgencyController', 'addClient', ['AuthMiddleware']);
$router->delete('/api/agency/{id}/clients/{clientId}', 'AgencyController', 'removeClient', ['AuthMiddleware']);
$router->get('/api/agency/{id}/commissions', 'AgencyController', 'listCommissions', ['AuthMiddleware']);
$router->post('/api/agency/commissions/{id}/paid', 'AgencyController', 'markCommissionPaid', ['AuthMiddleware']);
$router->get('/api/agency/{id}/performance', 'AgencyController', 'performanceReport', ['AuthMiddleware']);
$router->get('/api/agency/{id}/dashboard', 'AgencyController', 'agencyDashboard', ['AuthMiddleware']);
$router->post('/api/agency/{id}/invitations', 'AgencyController', 'createInvitation', ['AuthMiddleware']);
$router->get('/api/agency/{id}/invitations', 'AgencyController', 'listInvitations', ['AuthMiddleware']);
$router->delete('/api/agency/{id}/invitations/{inviteId}', 'AgencyController', 'revokeInvitation', ['AuthMiddleware']);
$router->post('/api/agency/invitations/accept', 'AgencyController', 'acceptInvitation', ['AuthMiddleware']);

// إدارة الإعلانات
$router->get('/api/ads/campaigns', 'AdsController', 'list', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/search', 'AdsController', 'searchCampaigns', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}', 'AdsController', 'getCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns', 'AdsController', 'create', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/ai-generate', 'AdsController', 'aiGenerateCampaign', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/copies', 'AdsController', 'listCopies', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/generate-copies', 'AdsController', 'generateCopies', ['AuthMiddleware']);
$router->patch('/api/ads/copies/{id}/approve', 'AdsController', 'approveCopy', ['AuthMiddleware']);
$router->patch('/api/ads/copies/{id}/reject', 'AdsController', 'rejectCopy', ['AuthMiddleware']);
$router->get('/api/ads/meta/status', 'AdsController', 'getMetaConnectionStatus', ['AuthMiddleware']);
$router->post('/api/ads/meta/choose-account', 'AdsController', 'chooseMetaAdAccount', ['AuthMiddleware']);
$router->post('/api/ads/meta/sync', 'AdsController', 'syncMetaCampaigns', ['AuthMiddleware']);
$router->post('/api/ads/meta/disconnect', 'AdsController', 'disconnectMeta', ['AuthMiddleware']);

$router->get('/api/ads/google/status', 'AdsController', 'getGoogleAdsConnectionStatus', ['AuthMiddleware']);
$router->get('/api/ads/connections/status', 'AdsController', 'getConnectionsStatus', ['AuthMiddleware']);
$router->post('/api/ads/google/choose-account', 'AdsController', 'chooseGoogleAdsAccount', ['AuthMiddleware']);
$router->post('/api/ads/google/sync', 'AdsController', 'syncGoogleAdsCampaigns', ['AuthMiddleware']);
$router->post('/api/ads/google/disconnect', 'AdsController', 'disconnectGoogleAds', ['AuthMiddleware']);

$router->get('/api/ads/autopilot/settings', 'AdsController', 'getAutopilotSettings', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/settings', 'AdsController', 'saveAutopilotSettings', ['AuthMiddleware']);
$router->get('/api/ads/autopilot/pending', 'AdsController', 'listPendingActions', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/pending/{id}/approve', 'AdsController', 'approvePendingAction', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/pending/{id}/reject', 'AdsController', 'rejectPendingAction', ['AuthMiddleware']);
$router->get('/api/ads/autopilot/logs', 'AdsController', 'listOptimizationLogs', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/logs/{id}/rollback', 'AdsController', 'rollbackOptimizationLog', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/run', 'AdsController', 'runAutopilotNow', ['AuthMiddleware']);

// ============================================
// موديول تسويق البريد (Email Marketing) - منافس Brevo، مبني بالكامل على بنية Tourfecto
// ============================================
// Dashboard & Stats
$router->get('/api/email-marketing/dashboard', 'EmailMarketingController', 'dashboard', ['AuthMiddleware']);
$router->get('/api/email-marketing/stats', 'EmailMarketingController', 'stats', ['AuthMiddleware']);
// Lists
$router->get('/api/email-marketing/lists', 'EmailMarketingController', 'lists', ['AuthMiddleware']);
$router->post('/api/email-marketing/lists', 'EmailMarketingController', 'createList', ['AuthMiddleware']);
$router->patch('/api/email-marketing/lists/{id}', 'EmailMarketingController', 'updateList', ['AuthMiddleware']);
$router->delete('/api/email-marketing/lists/{id}', 'EmailMarketingController', 'deleteList', ['AuthMiddleware']);
// Subscribers
$router->get('/api/email-marketing/subscribers', 'EmailMarketingController', 'allSubscribers', ['AuthMiddleware']);
$router->get('/api/email-marketing/lists/{id}/subscribers', 'EmailMarketingController', 'listSubscribers', ['AuthMiddleware']);
$router->post('/api/email-marketing/subscribers', 'EmailMarketingController', 'createSubscriber', ['AuthMiddleware']);
$router->post('/api/email-marketing/subscribers/import', 'EmailMarketingController', 'importSubscribers', ['AuthMiddleware']);
$router->post('/api/email-marketing/subscribers/import-advanced', 'EmailMarketingController', 'importContactsAdvanced', ['AuthMiddleware']);
$router->get('/api/email-marketing/subscribers/export', 'EmailMarketingController', 'exportSubscribers', ['AuthMiddleware']);
$router->get('/api/email-marketing/subscribers/{id}', 'EmailMarketingController', 'getSubscriber', ['AuthMiddleware']);
$router->patch('/api/email-marketing/subscribers/{id}', 'EmailMarketingController', 'updateSubscriber', ['AuthMiddleware']);
$router->post('/api/email-marketing/subscribers/{id}/unsubscribe', 'EmailMarketingController', 'unsubscribeSubscriber', ['AuthMiddleware']);
$router->delete('/api/email-marketing/subscribers/{id}', 'EmailMarketingController', 'deleteSubscriber', ['AuthMiddleware']);
// Contact Management (custom fields / tags / segments / suppressions)
$router->get('/api/email-marketing/contacts/custom-fields', 'EmailMarketingController', 'customFields', ['AuthMiddleware']);
$router->post('/api/email-marketing/contacts/custom-fields', 'EmailMarketingController', 'createCustomField', ['AuthMiddleware']);
$router->patch('/api/email-marketing/contacts/custom-fields/{id}', 'EmailMarketingController', 'updateCustomField', ['AuthMiddleware']);
$router->delete('/api/email-marketing/contacts/custom-fields/{id}', 'EmailMarketingController', 'deleteCustomField', ['AuthMiddleware']);
$router->get('/api/email-marketing/contacts/tags', 'EmailMarketingController', 'tags', ['AuthMiddleware']);
$router->post('/api/email-marketing/contacts/tags', 'EmailMarketingController', 'createTag', ['AuthMiddleware']);
$router->patch('/api/email-marketing/contacts/tags/{id}', 'EmailMarketingController', 'updateTag', ['AuthMiddleware']);
$router->delete('/api/email-marketing/contacts/tags/{id}', 'EmailMarketingController', 'deleteTag', ['AuthMiddleware']);
$router->post('/api/email-marketing/contacts/subscribers/{id}/tags', 'EmailMarketingController', 'assignSubscriberTag', ['AuthMiddleware']);
$router->delete('/api/email-marketing/contacts/subscribers/{id}/tags/{tagId}', 'EmailMarketingController', 'removeSubscriberTag', ['AuthMiddleware']);
$router->get('/api/email-marketing/contacts/segments', 'EmailMarketingController', 'segments', ['AuthMiddleware']);
$router->post('/api/email-marketing/contacts/segments', 'EmailMarketingController', 'createSegment', ['AuthMiddleware']);
$router->patch('/api/email-marketing/contacts/segments/{id}', 'EmailMarketingController', 'updateSegment', ['AuthMiddleware']);
$router->delete('/api/email-marketing/contacts/segments/{id}', 'EmailMarketingController', 'deleteSegment', ['AuthMiddleware']);
$router->get('/api/email-marketing/contacts/segments/{id}/preview', 'EmailMarketingController', 'segmentPreview', ['AuthMiddleware']);
$router->get('/api/email-marketing/contacts/suppressions', 'EmailMarketingController', 'suppressions', ['AuthMiddleware']);
$router->post('/api/email-marketing/contacts/suppressions', 'EmailMarketingController', 'addSuppression', ['AuthMiddleware']);
$router->delete('/api/email-marketing/contacts/suppressions/{id}', 'EmailMarketingController', 'removeSuppression', ['AuthMiddleware']);
// Templates
$router->get('/api/email-marketing/templates', 'EmailMarketingController', 'templates', ['AuthMiddleware']);
$router->get('/api/email-marketing/templates/catalog', 'EmailMarketingController', 'templateCatalog', ['AuthMiddleware']);
$router->post('/api/email-marketing/templates/from-gallery', 'EmailMarketingController', 'createTemplateFromGallery', ['AuthMiddleware']);
$router->post('/api/email-marketing/templates/blocks/render', 'EmailMarketingController', 'renderBlocks', ['AuthMiddleware']);
$router->get('/api/email-marketing/templates/{id}', 'EmailMarketingController', 'getTemplate', ['AuthMiddleware']);
$router->post('/api/email-marketing/templates/{id}/duplicate', 'EmailMarketingController', 'duplicateTemplate', ['AuthMiddleware']);
$router->post('/api/email-marketing/templates/{id}/share', 'EmailMarketingController', 'shareTemplate', ['AuthMiddleware']);
$router->post('/api/email-marketing/templates/preview-html', 'EmailMarketingController', 'previewHtml', ['AuthMiddleware']);
$router->post('/api/email-marketing/templates/{id}/preview', 'EmailMarketingController', 'previewTemplate', ['AuthMiddleware']);
$router->patch('/api/email-marketing/templates/{id}', 'EmailMarketingController', 'updateTemplate', ['AuthMiddleware']);
$router->delete('/api/email-marketing/templates/{id}', 'EmailMarketingController', 'deleteTemplate', ['AuthMiddleware']);
// Public share: جلب واستيراد قالب مشترك (من غير ملكية)
$router->get('/api/email-marketing/templates/shared/{token}', 'EmailMarketingController', 'getSharedTemplate', []);
$router->post('/api/email-marketing/templates/shared/{token}/import', 'EmailMarketingController', 'importSharedTemplate', ['AuthMiddleware']);
// Campaigns
$router->get('/api/email-marketing/campaigns', 'EmailMarketingController', 'campaigns', ['AuthMiddleware']);
$router->post('/api/email-marketing/campaigns', 'EmailMarketingController', 'createCampaign', ['AuthMiddleware']);
$router->get('/api/email-marketing/campaigns/{id}', 'EmailMarketingController', 'getCampaign', ['AuthMiddleware']);
$router->patch('/api/email-marketing/campaigns/{id}', 'EmailMarketingController', 'updateCampaign', ['AuthMiddleware']);
$router->delete('/api/email-marketing/campaigns/{id}', 'EmailMarketingController', 'deleteCampaign', ['AuthMiddleware']);
$router->post('/api/email-marketing/campaigns/{id}/duplicate', 'EmailMarketingController', 'duplicateCampaign', ['AuthMiddleware']);
$router->post('/api/email-marketing/campaigns/{id}/send', 'EmailMarketingController', 'sendCampaign', ['AuthMiddleware']);
$router->post('/api/email-marketing/campaigns/{id}/send-test', 'EmailMarketingController', 'sendTestEmail', ['AuthMiddleware']);
$router->post('/api/email-marketing/campaigns/{id}/schedule', 'EmailMarketingController', 'scheduleCampaign', ['AuthMiddleware']);
$router->post('/api/email-marketing/campaigns/{id}/cancel', 'EmailMarketingController', 'cancelCampaign', ['AuthMiddleware']);
$router->get('/api/email-marketing/campaigns/{id}/report', 'EmailMarketingController', 'campaignReport', ['AuthMiddleware']);
// Public tracking endpoints (بيطلبها عملاء البريد والمتصفحات - من غير Auth)
$router->get('/api/email-marketing/track/open/{token}.gif', 'EmailMarketingController', 'trackOpen', []);
$router->get('/api/email-marketing/track/click/{click_token}', 'EmailMarketingController', 'trackClick', []);
$router->get('/api/email-marketing/unsubscribe/{unsubscribe_token}', 'EmailMarketingController', 'unsubscribeLink', []);
// RFC 8058 one-click: Gmail/Yahoo يرسلون POST لرابط List-Unsubscribe
$router->post('/api/email-marketing/unsubscribe/{unsubscribe_token}', 'EmailMarketingController', 'unsubscribeLink', []);
// Automations (المرحلة 3)
$router->get('/api/email-marketing/automations', 'EmailMarketingController', 'automations', ['AuthMiddleware']);
$router->post('/api/email-marketing/automations', 'EmailMarketingController', 'createAutomation', ['AuthMiddleware']);
$router->get('/api/email-marketing/automations/{id}', 'EmailMarketingController', 'getAutomation', ['AuthMiddleware']);
$router->patch('/api/email-marketing/automations/{id}', 'EmailMarketingController', 'updateAutomation', ['AuthMiddleware']);
$router->delete('/api/email-marketing/automations/{id}', 'EmailMarketingController', 'deleteAutomation', ['AuthMiddleware']);
$router->post('/api/email-marketing/automations/{id}/steps', 'EmailMarketingController', 'setAutomationSteps', ['AuthMiddleware']);
$router->post('/api/email-marketing/automations/{id}/status', 'EmailMarketingController', 'setAutomationStatus', ['AuthMiddleware']);
$router->post('/api/email-marketing/automations/run-due', 'EmailMarketingController', 'runAutomationsDue', ['AuthMiddleware']);

$router->get('/api/email-marketing/smtp-settings', 'EmailMarketingController', 'smtpSettings', ['AuthMiddleware']);
$router->post('/api/email-marketing/smtp-settings', 'EmailMarketingController', 'saveSmtpSettings', ['AuthMiddleware']);
$router->post('/api/email-marketing/smtp-settings/test', 'EmailMarketingController', 'testSmtpSettings', ['AuthMiddleware']);
$router->delete('/api/email-marketing/smtp-settings', 'EmailMarketingController', 'deleteSmtpSettings', ['AuthMiddleware']);
// بند 1: webhook تتبع التسليم (توليد مفتاح + تفعيل/تعطيل)
$router->get('/api/email-marketing/smtp-settings/webhook', 'EmailMarketingController', 'deliveryWebhookSettings', ['AuthMiddleware']);
$router->post('/api/email-marketing/smtp-settings/webhook', 'EmailMarketingController', 'saveDeliveryWebhook', ['AuthMiddleware']);

$router->get('/api/email-marketing/transactional/templates', 'EmailMarketingController', 'transactionalTemplates', ['AuthMiddleware']);
$router->post('/api/email-marketing/transactional/templates', 'EmailMarketingController', 'createTransactionalTemplate', ['AuthMiddleware']);
$router->get('/api/email-marketing/transactional/templates/{id}', 'EmailMarketingController', 'getTransactionalTemplate', ['AuthMiddleware']);
$router->patch('/api/email-marketing/transactional/templates/{id}', 'EmailMarketingController', 'updateTransactionalTemplate', ['AuthMiddleware']);
$router->delete('/api/email-marketing/transactional/templates/{id}', 'EmailMarketingController', 'deleteTransactionalTemplate', ['AuthMiddleware']);
$router->post('/api/email-marketing/transactional/templates/{id}/duplicate', 'EmailMarketingController', 'duplicateTransactionalTemplate', ['AuthMiddleware']);
$router->post('/api/email-marketing/transactional/send', 'EmailMarketingController', 'sendTransactionalEmail', ['AuthMiddleware']);
$router->get('/api/email-marketing/transactional/logs', 'EmailMarketingController', 'transactionalLogs', ['AuthMiddleware']);
$router->get('/api/email-marketing/transactional/stats', 'EmailMarketingController', 'transactionalStats', ['AuthMiddleware']);

$router->get('/api/email-marketing/ab-tests', 'EmailMarketingController', 'abTests', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests', 'EmailMarketingController', 'createAbTest', ['AuthMiddleware']);
$router->get('/api/email-marketing/ab-tests/{id}', 'EmailMarketingController', 'getAbTest', ['AuthMiddleware']);
$router->delete('/api/email-marketing/ab-tests/{id}', 'EmailMarketingController', 'deleteAbTest', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests/{id}/variant', 'EmailMarketingController', 'setAbTestVariant', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests/{id}/start', 'EmailMarketingController', 'startAbTest', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests/{id}/send-batch', 'EmailMarketingController', 'sendAbTestBatch', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests/{id}/send-test', 'EmailMarketingController', 'sendAbTestTestEmail', ['AuthMiddleware']);
$router->get('/api/email-marketing/ab-tests/{id}/report', 'EmailMarketingController', 'abTestReport', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests/{id}/winner', 'EmailMarketingController', 'declareAbTestWinner', ['AuthMiddleware']);
$router->post('/api/email-marketing/ab-tests/{id}/apply-winner', 'EmailMarketingController', 'applyAbTestWinner', ['AuthMiddleware']);

$router->get('/api/ads/alerts/rules', 'AdsController', 'getAlertRules', ['AuthMiddleware']);
$router->post('/api/ads/alerts/rules', 'AdsController', 'saveAlertRules', ['AuthMiddleware']);
$router->get('/api/ads/alerts', 'AdsController', 'listAlerts', ['AuthMiddleware']);
$router->post('/api/ads/alerts/run', 'AdsController', 'runAlertsNow', ['AuthMiddleware']);
$router->post('/api/ads/alerts/read-all', 'AdsController', 'markAllAlertsRead', ['AuthMiddleware']);
$router->post('/api/ads/alerts/{id}/dismiss', 'AdsController', 'dismissAlert', ['AuthMiddleware']);
$router->get('/api/ads/alerts/rule-types', 'AdRuleAlertController', 'ruleTypes', ['AuthMiddleware']);

// ---- Creative Assets (بند 1) - أصول إعلانية (نص/صورة/فيديو) + تنويعات A/B/C
$router->get('/api/ads/creatives', 'AdCreativeController', 'list', ['AuthMiddleware']);
$router->get('/api/ads/creatives/{id}', 'AdCreativeController', 'show', ['AuthMiddleware']);
$router->post('/api/ads/creatives', 'AdCreativeController', 'create', ['AuthMiddleware']);
$router->patch('/api/ads/creatives/{id}', 'AdCreativeController', 'update', ['AuthMiddleware']);
$router->post('/api/ads/creatives/{id}/status', 'AdCreativeController', 'setStatus', ['AuthMiddleware']);
$router->delete('/api/ads/creatives/{id}', 'AdCreativeController', 'delete', ['AuthMiddleware']);
$router->post('/api/ads/creatives/{id}/variants', 'AdCreativeController', 'addVariant', ['AuthMiddleware']);
$router->patch('/api/ads/creative-variants/{id}', 'AdCreativeController', 'updateVariant', ['AuthMiddleware']);
$router->post('/api/ads/creative-variants/{id}/performance', 'AdCreativeController', 'recordPerformance', ['AuthMiddleware']);

// ---- Ad A/B Testing (بند 2) - تجارب على تنويعات الأصول الإعلانية
$router->get('/api/ads/ab-tests', 'AdAbTestController', 'list', ['AuthMiddleware']);
$router->get('/api/ads/ab-tests/{id}', 'AdAbTestController', 'show', ['AuthMiddleware']);
$router->post('/api/ads/ab-tests', 'AdAbTestController', 'create', ['AuthMiddleware']);
$router->post('/api/ads/ab-tests/{id}/start', 'AdAbTestController', 'start', ['AuthMiddleware']);
$router->post('/api/ads/ab-tests/{id}/complete', 'AdAbTestController', 'complete', ['AuthMiddleware']);
$router->delete('/api/ads/ab-tests/{id}', 'AdAbTestController', 'delete', ['AuthMiddleware']);
$router->post('/api/ads/ab-tests/{id}/variants', 'AdAbTestController', 'addVariant', ['AuthMiddleware']);
$router->patch('/api/ads/ab-test-variants/{id}', 'AdAbTestController', 'updateVariantWeight', ['AuthMiddleware']);
$router->delete('/api/ads/ab-test-variants/{id}', 'AdAbTestController', 'removeVariant', ['AuthMiddleware']);
$router->get('/api/ads/ab-tests/{id}/statistics', 'AdAbTestController', 'statistics', ['AuthMiddleware']);
$router->get('/api/ads/ab-tests/{id}/predict-winner', 'AdAbTestController', 'predictWinner', ['AuthMiddleware']);
$router->get('/api/ads/ab-tests/pick-variant', 'AdAbTestController', 'pickVariantForTraffic', ['AuthMiddleware']);

// ---- Ad/Variant Reports (بند 3) - تقارير مستوى الإعلان/الـ variant
// المسارات الثابتة قبل المسارات الديناميكية عشان التطابق الصحيح
$router->get('/api/ads/reports/variants', 'AdVariantReportController', 'report', ['AuthMiddleware']);
$router->get('/api/ads/reports/variants/summary', 'AdVariantReportController', 'summary', ['AuthMiddleware']);
$router->get('/api/ads/reports/best-variant', 'AdVariantReportController', 'bestVariant', ['AuthMiddleware']);
$router->get('/api/ads/reports/creatives/{id}', 'AdVariantReportController', 'creativeBreakdown', ['AuthMiddleware']);
$router->get('/api/ads/reports/campaigns/{id}', 'AdVariantReportController', 'campaignBreakdown', ['AuthMiddleware']);
$router->get('/api/ads/reports/variants/{id}', 'AdVariantReportController', 'variantBreakdown', ['AuthMiddleware']);

// ---- Ad Next-Best-Action (بند 5) - توصيات من ترند إحصائي حقيقي
$router->get('/api/ads/recommendations', 'AdNextBestActionController', 'recommend', ['AuthMiddleware']);
$router->get('/api/ads/recommendations/history', 'AdNextBestActionController', 'history', ['AuthMiddleware']);
$router->post('/api/ads/recommendations/{id}/applied', 'AdNextBestActionController', 'apply', ['AuthMiddleware']);
$router->post('/api/ads/recommendations/{id}/dismiss', 'AdNextBestActionController', 'dismiss', ['AuthMiddleware']);

$router->post('/api/ads/copilot/ask', 'AdsController', 'askCopilot', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/keywords/generate', 'AdsController', 'generateKeywords', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/keywords', 'AdsController', 'listKeywords', ['AuthMiddleware']);
$router->post('/api/ads/keywords/{id}/assign-group', 'AdsController', 'assignKeywordToGroup', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/ad-groups', 'AdsController', 'createAdGroup', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/ad-groups', 'AdsController', 'listAdGroups', ['AuthMiddleware']);
$router->post('/api/ads/ad-groups/{id}/status', 'AdsController', 'updateAdGroupStatus', ['AuthMiddleware']);
$router->delete('/api/ads/ad-groups/{id}', 'AdsController', 'deleteAdGroup', ['AuthMiddleware']);

$router->post('/api/ads/market-research', 'AdsController', 'marketResearch', ['AuthMiddleware']);
$router->get('/api/ads/market-research/history', 'AdsController', 'marketResearchHistory', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/status', 'AdsController', 'updateCampaignStatus', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/publish', 'AdsController', 'publishCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/toggle-status', 'AdsController', 'toggleCampaignStatus', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/cancel', 'AdsController', 'cancelCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/update-budget', 'AdsController', 'updateCampaignBudget', ['AuthMiddleware']);
$router->delete('/api/ads/campaigns/{id}', 'AdsController', 'deleteCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/bulk-status', 'AdsController', 'bulkUpdateCampaignStatus', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/landing-page/analyze', 'AdsController', 'analyzeLandingPage', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/utm-links', 'AdsController', 'createUtmLink', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/utm-links', 'AdsController', 'listUtmLinks', ['AuthMiddleware']);

$router->get('/api/ads/dashboard/summary', 'AdsController', 'getDashboardSummary', ['AuthMiddleware']);
$router->get('/api/ads/reports', 'AdsController', 'getReport', ['AuthMiddleware']);
$router->get('/api/ads/reports/trend', 'AdsController', 'getReportTrend', ['AuthMiddleware']);
$router->get('/api/ads/reports/comparison', 'AdsController', 'getCampaignComparison', ['AuthMiddleware']);

$router->post('/api/ads/competitors/{id}/analyze', 'AdsController', 'analyzeAdsCompetitor', ['AuthMiddleware']);
$router->get('/api/ads/competitors/{id}/insights', 'AdsController', 'listAdsCompetitorInsights', ['AuthMiddleware']);
$router->get('/api/ads/competitors', 'AdsController', 'listMyCompetitors', ['AuthMiddleware']);

$router->get('/api/ads/team', 'AdsController', 'listTeamMembers', ['AuthMiddleware']);
$router->post('/api/ads/team', 'AdsController', 'addTeamMember', ['AuthMiddleware']);
$router->post('/api/ads/team/{id}/role', 'AdsController', 'updateTeamMemberRole', ['AuthMiddleware']);
$router->post('/api/ads/team/{id}/remove', 'AdsController', 'removeTeamMember', ['AuthMiddleware']);

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
$router->delete('/api/ai/keywords/{id}', 'AIController', 'deleteKeyword', ['AuthMiddleware']);
$router->post('/api/ai/keywords/bulk-add', 'AIController', 'bulkAddKeywords', ['AuthMiddleware']);
// تحليل SEO/AEO/GEO حقيقي بنفس محرك /api/ai/analyze (يستهلك نفس الرصيد) +
// اكتشاف كلمات جديدة، ومزامنة الترتيب الفعلي من Google Search Console
$router->post('/api/ai/keywords/discover', 'AIController', 'discoverKeywords', ['AuthMiddleware']);
$router->post('/api/ai/keywords/sync-gsc', 'AIController', 'syncSearchConsoleKeywords', ['AuthMiddleware']);

// محتوى Google Business Profile وجدولته
$router->get('/api/gbp/content', 'GoogleBusinessContentController', 'listContent', ['AuthMiddleware']);
$router->post('/api/gbp/content', 'GoogleBusinessContentController', 'generate', [
    'AuthMiddleware',
    // Round 7 (2026-08-14 - Production Finalization): كان بيستهلك AI
    // credits من غير فحص رصيد - نفس نمط باقي endpoints الـ AI في المشروع
    'SubscriptionMiddleware:require_ai_credits'
]);
// GBP Module Upgrade (2026-08-11, Round 6): Posts Edit/Delete/Cancel
$router->put('/api/gbp/content/{id}', 'GoogleBusinessContentController', 'updateContent', ['AuthMiddleware']);
$router->delete('/api/gbp/content/{id}', 'GoogleBusinessContentController', 'deleteContent', ['AuthMiddleware']);
$router->post('/api/gbp/content/{id}/schedule/{scheduleId}/cancel', 'GoogleBusinessContentController', 'cancelSchedule', ['AuthMiddleware']);
$router->post('/api/gbp/content/{id}/schedule', 'GoogleBusinessContentController', 'schedule', ['AuthMiddleware']);
$router->get('/api/gbp/location', 'GoogleBusinessContentController', 'getLocation', ['AuthMiddleware']);
$router->post('/api/gbp/location', 'GoogleBusinessContentController', 'saveLocation', ['AuthMiddleware']);

// GBP Module Upgrade (2026-08-09/10): Setup Wizard/Connection Center/Sync/Profile/Photos/Insights/AI/Attributes
$router->get('/api/gbp/status', 'GbpProfileController', 'status', ['AuthMiddleware']);
$router->get('/api/gbp/health', 'GbpProfileController', 'health', ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/api/gbp/competitors', 'GbpProfileController', 'competitors', ['AuthMiddleware']);
$router->get('/api/gbp/analytics', 'GbpProfileController', 'analytics', ['AuthMiddleware']);
$router->get('/api/gbp/risk-signals', 'GbpProfileController', 'riskSignals', ['AuthMiddleware']);
$router->get('/api/gbp/share-of-voice', 'GbpProfileController', 'shareOfVoice', ['AuthMiddleware']);
$router->get('/api/gbp/reply-rules', 'GbpProfileController', 'listReplyRules', ['AuthMiddleware']);
$router->post('/api/gbp/reply-rules', 'GbpProfileController', 'createReplyRule', ['AuthMiddleware']);
$router->put('/api/gbp/reply-rules/{id}', 'GbpProfileController', 'updateReplyRule', ['AuthMiddleware']);
$router->delete('/api/gbp/reply-rules/{id}', 'GbpProfileController', 'deleteReplyRule', ['AuthMiddleware']);
$router->post('/api/gbp/reply-rules/apply/{review_id}', 'GbpProfileController', 'applyReplyRules', ['AuthMiddleware']);
$router->get('/api/gbp/local-seo-audit', 'GbpProfileController', 'localSeoAudit', ['AuthMiddleware']);
$router->post('/api/gbp/sync/{website_id}', 'GbpProfileController', 'sync', ['AuthMiddleware']);
$router->get('/api/gbp/profile', 'GbpProfileController', 'getProfile', ['AuthMiddleware']);
$router->post('/api/gbp/profile', 'GbpProfileController', 'updateProfile', ['AuthMiddleware']);
$router->get('/api/gbp/attributes', 'GbpProfileController', 'getAttributes', ['AuthMiddleware']);
$router->post('/api/gbp/attributes', 'GbpProfileController', 'updateAttributes', ['AuthMiddleware']);
$router->get('/api/gbp/photos', 'GbpProfileController', 'listPhotos', ['AuthMiddleware']);
$router->post('/api/gbp/photos', 'GbpProfileController', 'uploadPhoto', ['AuthMiddleware']);
$router->delete('/api/gbp/photos/{id}', 'GbpProfileController', 'deletePhoto', ['AuthMiddleware']);
$router->post('/api/gbp/photos/{id}/primary', 'GbpProfileController', 'setPrimaryPhoto', ['AuthMiddleware']);
$router->get('/api/gbp/insights', 'GbpProfileController', 'insights', ['AuthMiddleware']);
$router->get('/api/gbp/ai-insights', 'GbpProfileController', 'aiInsights', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/gbp/recommendations', 'GbpProfileController', 'recommendations', ['AuthMiddleware']);
// مركز الأتمتة
$router->get('/api/automation', 'AutomationController', 'overview', ['AuthMiddleware']);
$router->get('/api/automation/pending', 'AutomationController', 'pending', ['AuthMiddleware']);
$router->get('/api/automation/activity', 'AutomationController', 'activity', ['AuthMiddleware']);
$router->post('/api/automation/approve-all', 'AutomationController', 'approveAll', ['AuthMiddleware']);
$router->post('/api/automation/decisions/{id}/approve', 'AutomationController', 'approve', ['AuthMiddleware']);
$router->post('/api/automation/decisions/{id}/reject', 'AutomationController', 'reject', ['AuthMiddleware']);
$router->post('/api/automation/decisions/{id}/feedback', 'AutomationController', 'feedback', ['AuthMiddleware']);
$router->get('/api/automation/{feature}/connections', 'AutomationController', 'connectionsList', ['AuthMiddleware']);
$router->put('/api/automation/{feature}/scope', 'AutomationController', 'updateScope', ['AuthMiddleware']);
$router->put('/api/automation/{feature}', 'AutomationController', 'updatePolicy', ['AuthMiddleware']);
$router->get('/api/review-requests', 'ReviewRequestController', 'listRequests', ['AuthMiddleware']);
$router->post('/api/review-requests', 'ReviewRequestController', 'createRequest', ['AuthMiddleware']);
$router->get('/api/review-requests/stats', 'ReviewRequestController', 'getStats', ['AuthMiddleware']);
$router->get('/api/review-requests/analytics', 'ReviewRequestController', 'getAnalytics', ['AuthMiddleware']);
$router->get('/api/review-requests/channel-status', 'ReviewRequestController', 'getChannelStatus', ['AuthMiddleware']);
$router->get('/api/review-requests/destinations', 'ReviewRequestController', 'getDestinations', ['AuthMiddleware']);
$router->get('/api/review-requests/crm-contacts', 'ReviewRequestController', 'getCrmContacts', ['AuthMiddleware']);
$router->get('/api/review-requests/smart-timing', 'ReviewRequestController', 'getSmartTiming', ['AuthMiddleware']);
$router->get('/api/review-requests/templates', 'ReviewRequestController', 'getTemplates', ['AuthMiddleware']);
$router->post('/api/review-requests/templates', 'ReviewRequestController', 'createTemplate', ['AuthMiddleware']);
$router->delete('/api/review-requests/templates/{id}', 'ReviewRequestController', 'deleteTemplate', ['AuthMiddleware']);
$router->get('/api/review-requests/export', 'ReviewRequestController', 'exportCsv', ['AuthMiddleware']);
$router->post('/api/review-requests/ai-assist', 'ReviewRequestController', 'aiAssist', ['AuthMiddleware']);
$router->get('/api/review-requests/settings', 'ReviewRequestController', 'getSettings', ['AuthMiddleware']);
$router->put('/api/review-requests/settings', 'ReviewRequestController', 'saveSettings', ['AuthMiddleware']);
$router->post('/api/review-requests/{id}/opt-out', 'ReviewRequestController', 'optOut', ['AuthMiddleware']);
$router->post('/api/review-requests/{id}/retry', 'ReviewRequestController', 'retryRequest', ['AuthMiddleware']);
$router->put('/api/review-requests/{id}', 'ReviewRequestController', 'updateRequest', ['AuthMiddleware']);
$router->get('/api/review-requests/{id}', 'ReviewRequestController', 'getRequest', ['AuthMiddleware']);
$router->get('/api/ai-assistant/conversations', 'AiAssistantController', 'listConversations', ['AuthMiddleware']);
$router->post('/api/ai-assistant/conversations', 'AiAssistantController', 'createConversation', ['AuthMiddleware']);
$router->get('/api/ai-assistant/conversations/{id}/messages', 'AiAssistantController', 'getMessages', ['AuthMiddleware']);
$router->post('/api/ai-assistant/conversations/{id}/messages', 'AiAssistantController', 'sendMessage', ['AuthMiddleware']);
$router->delete('/api/ai-assistant/conversations/{id}', 'AiAssistantController', 'deleteConversation', ['AuthMiddleware']);
$router->post('/api/ai-assistant/conversations/{id}/regenerate', 'AiAssistantController', 'regenerateMessage', ['AuthMiddleware']);
$router->patch('/api/ai-assistant/conversations/{id}', 'AiAssistantController', 'renameConversation', ['AuthMiddleware']);
$router->get('/api/website-builder/state', 'WebsiteBuilderController', 'getState', ['AuthMiddleware']);
$router->post('/api/website-builder/answer', 'WebsiteBuilderController', 'submitAnswer', ['AuthMiddleware']);
$router->post('/api/website-builder/reset', 'WebsiteBuilderController', 'resetWizard', ['AuthMiddleware']);
$router->post('/api/website-builder/generate', 'WebsiteBuilderController', 'generateSite', ['AuthMiddleware']);
$router->get('/api/website-builder/my-websites', 'WebsiteBuilderController', 'myWebsites', ['AuthMiddleware']);
$router->get('/api/website-builder/{id}', 'WebsiteBuilderController', 'getWebsite', ['AuthMiddleware']);
$router->put('/api/website-builder/{id}', 'WebsiteBuilderController', 'updateWebsite', ['AuthMiddleware']);
$router->post('/api/website-builder/{id}/tours', 'WebsiteBuilderController', 'addTour', ['AuthMiddleware']);
$router->put('/api/website-builder/{id}/tours/{tourSlug}', 'WebsiteBuilderController', 'updateTour', ['AuthMiddleware']);
$router->delete('/api/website-builder/{id}/tours/{tourSlug}', 'WebsiteBuilderController', 'deleteTour', ['AuthMiddleware']);
$router->post('/api/website-builder/{id}/publish', 'WebsiteBuilderController', 'publish', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/overview', 'SiteDashboardController', 'overview', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/templates', 'SiteDashboardController', 'templates', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/design', 'SiteDashboardController', 'updateDesign', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/seo', 'SiteDashboardController', 'updateSeo', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/reviews', 'SiteDashboardController', 'reviews', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/reviews/{reviewId}', 'SiteDashboardController', 'updateReview', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/leads', 'SiteDashboardController', 'leads', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/leads/{leadId}', 'SiteDashboardController', 'updateLead', ['AuthMiddleware']);

// CRM - Leads/Contacts
$router->get('/api/crm/leads', 'CrmController', 'listLeads', ['AuthMiddleware']);
$router->post('/api/crm/leads', 'CrmController', 'createLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/status', 'CrmController', 'updateLeadStatus', ['AuthMiddleware']);
$router->get('/api/crm/pipeline-stages', 'CrmController', 'listPipelineStages', ['AuthMiddleware']);
$router->get('/api/crm/deals', 'CrmController', 'listDeals', ['AuthMiddleware']);
$router->post('/api/crm/deals', 'CrmController', 'createDeal', ['AuthMiddleware']);
$router->post('/api/crm/deals/{id}/stage', 'CrmController', 'updateDealStage', ['AuthMiddleware']);
$router->get('/api/crm/leads/search', 'CrmApiController', 'searchLeads', ['AuthMiddleware']);
$router->get('/api/crm/leads/export', 'CrmApiController', 'exportLeads', ['AuthMiddleware']);
$router->get('/api/crm/deals/search', 'CrmApiController', 'searchDeals', ['AuthMiddleware']);
$router->get('/api/crm/deals/export', 'CrmApiController', 'exportDeals', ['AuthMiddleware']);

// ============================================================
// موديول AI CRM - نقاط API إضافية (CrmApiController) - بند 41/45
// ============================================================
// Companies
$router->get('/api/crm/companies', 'CrmApiController', 'listCompanies', ['AuthMiddleware']);
$router->get('/api/crm/companies/search', 'CrmApiController', 'searchCompanies', ['AuthMiddleware']);
$router->post('/api/crm/companies', 'CrmApiController', 'createCompany', ['AuthMiddleware']);
$router->put('/api/crm/companies/{id}', 'CrmApiController', 'updateCompany', ['AuthMiddleware']);

// Contacts
$router->get('/api/crm/contacts', 'CrmApiController', 'listContacts', ['AuthMiddleware']);
// إصلاح المرحلة 8: مسارات GET الحرفية (export/search) لازم تُسجَّل قبل
// GET /api/crm/contacts/{id} - الـRouter بيطابق بترتيب التسجيل (أول Pattern
// يطابق يكسب)، وPattern الـ{id} بيطابق أي جزء واحد بعد /contacts/ بما فيها
// "export"/"search" حرفيًا. كانت /api/crm/contacts/export (بند 20 من
// المرحلة 1) فعليًا مسار ميت (Shadowed) من غير ما يُكتشف - تم اكتشافه
// وإصلاحه الآن أثناء إضافة /search.
$router->get('/api/crm/contacts/export', 'CrmApiController', 'exportContacts', ['AuthMiddleware']);
$router->get('/api/crm/contacts/search', 'CrmApiController', 'searchContacts', ['AuthMiddleware']);
$router->get('/api/crm/contacts/{id}', 'CrmApiController', 'getContact', ['AuthMiddleware']);
$router->post('/api/crm/contacts', 'CrmApiController', 'createContact', ['AuthMiddleware']);
$router->put('/api/crm/contacts/{id}', 'CrmApiController', 'updateContact', ['AuthMiddleware']);
$router->get('/api/crm/contacts/{id}/duplicates', 'CrmApiController', 'contactDuplicates', ['AuthMiddleware']);
$router->post('/api/crm/contacts/merge', 'CrmApiController', 'mergeContacts', ['AuthMiddleware']);
$router->get('/api/crm/contacts/{id}/360', 'CrmApiController', 'customer360', ['AuthMiddleware']);

// Leads - عمليات إضافية
$router->post('/api/crm/leads/{id}/assign', 'CrmApiController', 'assignLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/convert', 'CrmApiController', 'convertLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/archive', 'CrmApiController', 'archiveLead', ['AuthMiddleware']);

// Deals - عمليات إضافية
$router->put('/api/crm/deals/{id}', 'CrmApiController', 'updateDeal', ['AuthMiddleware']);
$router->delete('/api/crm/deals/{id}', 'CrmApiController', 'deleteDeal', ['AuthMiddleware']);
$router->get('/api/crm/deals/at-risk', 'CrmApiController', 'dealsAtRisk', ['AuthMiddleware']);

// Pipelines متعددة
$router->get('/api/crm/pipelines', 'CrmApiController', 'listPipelines', ['AuthMiddleware']);
$router->post('/api/crm/pipelines', 'CrmApiController', 'createPipeline', ['AuthMiddleware']);
$router->get('/api/crm/pipelines/{id}/stages', 'CrmApiController', 'pipelineStages', ['AuthMiddleware']);
$router->post('/api/crm/pipelines/{id}/stages', 'CrmApiController', 'createPipelineStage', ['AuthMiddleware']);

// Tasks / Follow-ups
$router->get('/api/crm/tasks', 'CrmApiController', 'listTasks', ['AuthMiddleware']);
$router->get('/api/crm/tasks/search', 'CrmApiController', 'searchTasks', ['AuthMiddleware']);
$router->get('/api/crm/tasks/export', 'CrmApiController', 'exportTasks', ['AuthMiddleware']);
$router->get('/api/crm/tasks/overdue', 'CrmApiController', 'overdueTasks', ['AuthMiddleware']);
$router->post('/api/crm/tasks', 'CrmApiController', 'createTask', ['AuthMiddleware']);
$router->post('/api/crm/tasks/{id}/status', 'CrmApiController', 'updateTaskStatus', ['AuthMiddleware']);

// Notes
$router->post('/api/crm/notes', 'CrmApiController', 'createNote', ['AuthMiddleware']);

// Appointments
$router->get('/api/crm/appointments', 'CrmApiController', 'listAppointments', ['AuthMiddleware']);
$router->get('/api/crm/appointments/search', 'CrmApiController', 'searchAppointments', ['AuthMiddleware']);
$router->post('/api/crm/appointments', 'CrmApiController', 'createAppointment', ['AuthMiddleware']);
$router->post('/api/crm/appointments/{id}/status', 'CrmApiController', 'updateAppointmentStatus', ['AuthMiddleware']);

// Dashboard / Reports
$router->get('/api/crm/dashboard/stats', 'CrmApiController', 'dashboardStats', ['AuthMiddleware']);

// Global Search
$router->get('/api/crm/search', 'CrmApiController', 'globalSearch', ['AuthMiddleware']);

// Lead Sources قابلة للتخصيص
$router->get('/api/crm/lead-sources', 'CrmApiController', 'listLeadSources', ['AuthMiddleware']);
$router->post('/api/crm/lead-sources', 'CrmApiController', 'createLeadSource', ['AuthMiddleware']);

// Import / Export
$router->post('/api/crm/contacts/import/preview', 'CrmApiController', 'importPreview', ['AuthMiddleware']);
$router->post('/api/crm/contacts/import/commit', 'CrmApiController', 'importCommit', ['AuthMiddleware']);
$router->post('/api/crm/contacts/import/commit-async', 'CrmApiController', 'importCommitAsync', ['AuthMiddleware']);
$router->get('/api/crm/contacts/import/status/{id}', 'CrmApiController', 'importBatchStatus', ['AuthMiddleware']);

// المرحلة 8: Segments (بند 19) - استكمال لهذه القائمة
$router->get('/api/crm/segments', 'CrmApiController', 'listSegments', ['AuthMiddleware']);
$router->post('/api/crm/segments', 'CrmApiController', 'createSegment', ['AuthMiddleware']);
$router->delete('/api/crm/segments/{id}', 'CrmApiController', 'deleteSegment', ['AuthMiddleware']);
$router->get('/api/crm/segments/{id}/run', 'CrmApiController', 'runSegment', ['AuthMiddleware']);

// ============================================================
// موديول AI CRM - المرحلة 2 (AI Lead Scoring / Next Best Action /
// Forecasting / AI Sales Assistant / AI Summary) - بند 8/9/10/25/27
// ============================================================
// الطبقة الإحصائية على تقييم الـLeads (بند 6) - المسار الثابت يُسجَّل
// قبل أي مسار ديناميكي يبدأ بـ /api/crm/leads/{id}
$router->get('/api/crm/leads/scoring/stats', 'CrmLeadScoringStatController', 'stats', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/scoring', 'CrmLeadScoringStatController', 'scoreLead', ['AuthMiddleware']);
$router->get('/api/crm/leads/{id}/scoring', 'CrmLeadScoringStatController', 'getScoring', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/score', 'CrmApiController', 'scoreLead', ['AuthMiddleware']);
$router->get('/api/crm/leads/{id}/next-best-action', 'CrmApiController', 'leadNextBestAction', ['AuthMiddleware']);
$router->get('/api/crm/deals/{id}/next-best-action', 'CrmApiController', 'dealNextBestAction', ['AuthMiddleware']);
$router->get('/api/crm/forecast', 'CrmApiController', 'forecast', ['AuthMiddleware']);
// نقطتا الـAPI التاليتان فقط هما اللي بيستدعوا GeminiClient فعليًا (توليد
// نص) - لذلك مربوطتين بنفس بوابة استهلاك AI Credits المستخدمة في باقي
// ميزات الذكاء الاصطناعي بالمشروع (راجع /api/ai/* أعلاه في نفس الملف).
$router->post('/api/crm/assistant/ask', 'CrmApiController', 'assistantAsk', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/crm/contacts/{id}/ai-summary', 'CrmApiController', 'contactAiSummary', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);

// ============================================================
// المرحلة 3: Automation Workflows + WhatsApp/Email Communication (بند 12/15/16/17/36)
// ============================================================
$router->get('/api/crm/automation/rules', 'CrmApiController', 'listAutomationRules', ['AuthMiddleware']);
$router->get('/api/crm/automation/templates', 'CrmApiController', 'automationTemplates', ['AuthMiddleware']);
$router->get('/api/crm/automation/schema', 'CrmApiController', 'automationSchema', ['AuthMiddleware']);
$router->post('/api/crm/automation/rules', 'CrmApiController', 'createAutomationRule', ['AuthMiddleware']);
$router->put('/api/crm/automation/rules/{id}', 'CrmApiController', 'updateAutomationRule', ['AuthMiddleware']);
$router->post('/api/crm/automation/rules/from-template', 'CrmApiController', 'createAutomationRuleFromTemplate', ['AuthMiddleware']);
$router->post('/api/crm/automation/rules/{id}/toggle', 'CrmApiController', 'toggleAutomationRule', ['AuthMiddleware']);
$router->delete('/api/crm/automation/rules/{id}', 'CrmApiController', 'deleteAutomationRule', ['AuthMiddleware']);

$router->get('/api/crm/conversations', 'CrmApiController', 'listConversations', ['AuthMiddleware']);
$router->get('/api/crm/conversations/{id}/messages', 'CrmApiController', 'conversationMessages', ['AuthMiddleware']);
$router->post('/api/crm/contacts/{id}/send-whatsapp', 'CrmApiController', 'sendWhatsApp', ['AuthMiddleware']);
$router->post('/api/crm/contacts/{id}/send-email', 'CrmApiController', 'sendEmail', ['AuthMiddleware']);
$router->post('/api/crm/contacts/{id}/send-sms', 'CrmApiController', 'sendSms', ['AuthMiddleware']);
$router->get('/api/crm/communication/status', 'CrmApiController', 'communicationStatus', ['AuthMiddleware']);

// المرحلة 5: Team & Roles/Permissions (بند 30)
$router->get('/api/crm/team', 'CrmApiController', 'listTeam', ['AuthMiddleware']);
$router->post('/api/crm/team', 'CrmApiController', 'addTeamMember', ['AuthMiddleware']);
$router->put('/api/crm/team/{id}', 'CrmApiController', 'updateTeamMemberRole', ['AuthMiddleware']);
$router->delete('/api/crm/team/{id}', 'CrmApiController', 'removeTeamMember', ['AuthMiddleware']);

// المرحلة 12 (G1) - Message Templates
$router->get('/api/crm/templates', 'CrmApiController', 'listTemplates', ['AuthMiddleware']);
$router->get('/api/crm/templates/variables', 'CrmApiController', 'templateVariables', ['AuthMiddleware']);
$router->post('/api/crm/templates', 'CrmApiController', 'createTemplate', ['AuthMiddleware']);
$router->put('/api/crm/templates/{id}', 'CrmApiController', 'updateTemplate', ['AuthMiddleware']);
$router->delete('/api/crm/templates/{id}', 'CrmApiController', 'deleteTemplate', ['AuthMiddleware']);
$router->post('/api/crm/templates/{id}/render', 'CrmApiController', 'renderTemplate', ['AuthMiddleware']);

// المرحلة 12 (G4) - Win/Loss Analysis + Sales Goals
$router->get('/api/crm/reports/win-loss', 'CrmApiController', 'winLossReport', ['AuthMiddleware']);
$router->get('/api/crm/reports/sales-goals', 'CrmApiController', 'salesGoalsReport', ['AuthMiddleware']);
$router->post('/api/crm/reports/sales-goals', 'CrmApiController', 'setSalesGoal', ['AuthMiddleware']);
$router->delete('/api/crm/reports/sales-goals/{id}', 'CrmApiController', 'deleteSalesGoal', ['AuthMiddleware']);

// المرحلة 12 (G2) - Custom Fields
$router->get('/api/crm/custom-fields', 'CrmApiController', 'listCustomFields', ['AuthMiddleware']);
$router->post('/api/crm/custom-fields', 'CrmApiController', 'createCustomField', ['AuthMiddleware']);
$router->put('/api/crm/custom-fields/{id}', 'CrmApiController', 'updateCustomField', ['AuthMiddleware']);
$router->delete('/api/crm/custom-fields/{id}', 'CrmApiController', 'deleteCustomField', ['AuthMiddleware']);
$router->get('/api/crm/entities/{entityType}/{id}/custom-fields', 'CrmApiController', 'getEntityCustomFields', ['AuthMiddleware']);
$router->post('/api/crm/entities/{entityType}/{id}/custom-fields', 'CrmApiController', 'setEntityCustomFields', ['AuthMiddleware']);

// المرحلة 13 (G3) - Product Catalog & Deal Line Items
$router->get('/api/crm/products', 'CrmApiController', 'listProducts', ['AuthMiddleware']);
$router->post('/api/crm/products', 'CrmApiController', 'createProduct', ['AuthMiddleware']);
$router->put('/api/crm/products/{id}', 'CrmApiController', 'updateProduct', ['AuthMiddleware']);
$router->delete('/api/crm/products/{id}', 'CrmApiController', 'deleteProduct', ['AuthMiddleware']);
$router->get('/api/crm/deals/{id}/items', 'CrmApiController', 'listDealItems', ['AuthMiddleware']);
$router->post('/api/crm/deals/{id}/items', 'CrmApiController', 'addDealItem', ['AuthMiddleware']);
$router->put('/api/crm/deals/{id}/items/{itemId}', 'CrmApiController', 'updateDealItem', ['AuthMiddleware']);
$router->delete('/api/crm/deals/{id}/items/{itemId}', 'CrmApiController', 'removeDealItem', ['AuthMiddleware']);

// المرحلة 13 (G5) - Lead Routing Rules
$router->get('/api/crm/routing-rules', 'CrmApiController', 'listRoutingRules', ['AuthMiddleware']);
$router->post('/api/crm/routing-rules', 'CrmApiController', 'createRoutingRule', ['AuthMiddleware']);
$router->put('/api/crm/routing-rules/{id}', 'CrmApiController', 'updateRoutingRule', ['AuthMiddleware']);
$router->delete('/api/crm/routing-rules/{id}', 'CrmApiController', 'deleteRoutingRule', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/route', 'CrmApiController', 'routeLead', ['AuthMiddleware']);

// المرحلة 13 (G6) - Contact Lifecycle
$router->get('/api/crm/lifecycle/stages', 'CrmApiController', 'listLifecycleStages', ['AuthMiddleware']);
$router->post('/api/crm/lifecycle/stages', 'CrmApiController', 'createLifecycleStage', ['AuthMiddleware']);
$router->put('/api/crm/lifecycle/stages/{id}', 'CrmApiController', 'updateLifecycleStage', ['AuthMiddleware']);
$router->delete('/api/crm/lifecycle/stages/{id}', 'CrmApiController', 'deleteLifecycleStage', ['AuthMiddleware']);
$router->put('/api/crm/contacts/{id}/lifecycle', 'CrmApiController', 'setContactLifecycle', ['AuthMiddleware']);
$router->get('/api/crm/lifecycle/contacts', 'CrmApiController', 'contactsByLifecycle', ['AuthMiddleware']);

// المرحلة 13 (G9) - Team Invite (via WorkspaceInvite)
$router->post('/api/crm/team/invite', 'CrmApiController', 'inviteTeamMember', ['AuthMiddleware']);
$router->get('/api/crm/team/invites', 'CrmApiController', 'listTeamInvites', ['AuthMiddleware']);
$router->post('/api/crm/team/invites/{id}/revoke', 'CrmApiController', 'revokeTeamInvite', ['AuthMiddleware']);
$router->get('/api/crm/team/invite/{token}', 'CrmApiController', 'showTeamInvite', []);
$router->post('/api/crm/team/invite/{token}/accept', 'CrmApiController', 'acceptTeamInvite', []);

// المرحلة 14 (G7) - Charts & Visualizations
$router->get('/api/crm/charts/pipeline', 'CrmApiController', 'chartPipeline', ['AuthMiddleware']);
$router->get('/api/crm/charts/revenue-trend', 'CrmApiController', 'chartRevenueTrend', ['AuthMiddleware']);
$router->get('/api/crm/charts/win-loss', 'CrmApiController', 'chartWinLoss', ['AuthMiddleware']);
$router->get('/api/crm/charts/lead-sources', 'CrmApiController', 'chartLeadSources', ['AuthMiddleware']);
$router->get('/api/crm/charts/deal-status', 'CrmApiController', 'chartDealStatus', ['AuthMiddleware']);
$router->get('/api/crm/charts/lifecycle', 'CrmApiController', 'chartLifecycle', ['AuthMiddleware']);

// المرحلة 14 (G8) - Email Open Tracking
$router->get('/api/crm/email-track/{token}.gif', 'CrmApiController', 'emailTrackingPixel', []);
$router->post('/api/crm/email-track', 'CrmApiController', 'sendTrackedEmail', ['AuthMiddleware']);
$router->get('/api/crm/email-track/stats', 'CrmApiController', 'emailTrackingStats', ['AuthMiddleware']);

// المرحلة 14 (G10) - Custom Activity Types
$router->get('/api/crm/activity-types', 'CrmApiController', 'listActivityTypes', ['AuthMiddleware']);
$router->post('/api/crm/activity-types', 'CrmApiController', 'createActivityType', ['AuthMiddleware']);
$router->put('/api/crm/activity-types/{id}', 'CrmApiController', 'updateActivityType', ['AuthMiddleware']);
$router->delete('/api/crm/activity-types/{id}', 'CrmApiController', 'deleteActivityType', ['AuthMiddleware']);
$router->get('/api/crm/activities', 'CrmApiController', 'listActivities', ['AuthMiddleware']);
$router->post('/api/crm/activities', 'CrmApiController', 'createActivity', ['AuthMiddleware']);
$router->delete('/api/crm/activities/{id}', 'CrmApiController', 'deleteActivity', ['AuthMiddleware']);

// المرحلة 15: Web Forms لالتقاط Leads (G11) - إدارة النماذج والإرسالات
$router->get('/api/crm/web-forms', 'CrmApiController', 'listWebForms', ['AuthMiddleware']);
$router->post('/api/crm/web-forms', 'CrmApiController', 'createWebForm', ['AuthMiddleware']);
$router->put('/api/crm/web-forms/{id}', 'CrmApiController', 'updateWebForm', ['AuthMiddleware']);
$router->delete('/api/crm/web-forms/{id}', 'CrmApiController', 'deleteWebForm', ['AuthMiddleware']);
$router->get('/api/crm/web-forms/submissions', 'CrmApiController', 'webFormSubmissions', ['AuthMiddleware']);
// إرسال عام للنموذج - بدون AuthMiddleware عمدًا (الزائر الخارجي لا يملك جلسة)
$router->post('/api/crm/public/web-forms/{slug}/submit', 'CrmApiController', 'submitWebForm', []);

// المرحلة 15: Sales Sequences (G12) - تسلسلات مبيعات متعددة الخطوات
$router->get('/api/crm/sequences/schema', 'CrmApiController', 'sequenceSchema', ['AuthMiddleware']);
$router->get('/api/crm/sequences', 'CrmApiController', 'listSequences', ['AuthMiddleware']);
$router->post('/api/crm/sequences', 'CrmApiController', 'createSequence', ['AuthMiddleware']);
$router->put('/api/crm/sequences/{id}', 'CrmApiController', 'updateSequence', ['AuthMiddleware']);
$router->delete('/api/crm/sequences/{id}', 'CrmApiController', 'deleteSequence', ['AuthMiddleware']);
$router->post('/api/crm/sequences/{id}/enroll', 'CrmApiController', 'enrollInSequence', ['AuthMiddleware']);
$router->get('/api/crm/sequences/enrollments', 'CrmApiController', 'listSequenceEnrollments', ['AuthMiddleware']);
$router->post('/api/crm/sequences/enrollments/process-due', 'CrmApiController', 'processDueSequences', ['AuthMiddleware']);
$router->post('/api/crm/sequences/enrollments/{id}/pause', 'CrmApiController', 'pauseSequenceEnrollment', ['AuthMiddleware']);
$router->post('/api/crm/sequences/enrollments/{id}/resume', 'CrmApiController', 'resumeSequenceEnrollment', ['AuthMiddleware']);
$router->post('/api/crm/sequences/enrollments/{id}/cancel', 'CrmApiController', 'cancelSequenceEnrollment', ['AuthMiddleware']);

// المرحلة 15: Report Builder (G13) - تقارير مخصصة قابلة للحفظ
$router->get('/api/crm/reports/builder/schema', 'CrmApiController', 'reportBuilderSchema', ['AuthMiddleware']);
$router->get('/api/crm/reports/builder', 'CrmApiController', 'listSavedReports', ['AuthMiddleware']);
$router->post('/api/crm/reports/builder', 'CrmApiController', 'saveReport', ['AuthMiddleware']);
$router->put('/api/crm/reports/builder/{id}', 'CrmApiController', 'updateSavedReport', ['AuthMiddleware']);
$router->delete('/api/crm/reports/builder/{id}', 'CrmApiController', 'deleteSavedReport', ['AuthMiddleware']);
$router->post('/api/crm/reports/builder/run', 'CrmApiController', 'runReport', ['AuthMiddleware']);
$router->get('/api/crm/reports/builder/{id}/run', 'CrmApiController', 'runSavedReport', ['AuthMiddleware']);

// المرحلة 15: استيراد من CRMs خارجية (G14) - HubSpot / Zoho / Pipedrive / Freshsales
$router->get('/api/crm/import/presets', 'CrmApiController', 'importPresets', ['AuthMiddleware']);
$router->post('/api/crm/import/preview-external', 'CrmApiController', 'previewExternalImport', ['AuthMiddleware']);
$router->post('/api/crm/import/commit-external', 'CrmApiController', 'commitExternalImport', ['AuthMiddleware']);

// Webhook عام (بدون AuthMiddleware عمدًا - Meta هي اللي بتنادي عليه، راجع
// تعليق CrmWhatsAppWebhookController للتفاصيل الأمنية).
$router->get('/webhooks/crm/whatsapp', 'CrmWhatsAppWebhookController', 'verify', []);
$router->post('/webhooks/crm/whatsapp', 'CrmWhatsAppWebhookController', 'receive', []);
$router->post('/webhooks/crm/sms', 'CrmSmsWebhookController', 'receive', []);
$router->post('/webhooks/crm/email-inbound', 'CrmEmailWebhookController', 'receive', []);

// Webhook تتبع تسليم البريد (بند 1): ارتداد/شكوى/سبام من مزوّد SMTP.
// بدون AuthMiddleware - التوثيق بتوقيع HMAC/مفتاح سري لكل مستخدم
// (راجع WebhookController::emailDeliveryStatusWebhook). المزوّد يكتب
// user_id في الـ URL والمفتاح السري هو الضمان.
$router->post('/webhooks/email/delivery-status/{user_id}', 'WebhookController', 'emailDeliveryStatusWebhook', []);

// Double Opt-In (بند 2): اشتراك عام + تأكيد من رابط البريد — بدون Auth
// عمدًا (نموذج عام). حماية الإساءة: RateLimitMiddleware العام + تحقق بريد.
$router->post('/webhooks/email/subscribe', 'EmailMarketingController', 'publicSubscribe', []);
$router->get('/webhooks/email/confirm/{token}', 'EmailMarketingController', 'publicConfirmOptin', []);

// ============================================
// AI Chat Platform - Knowledge Base (بند 4 + 13 Brand Voice)
// Phase 1: يدير صاحب الشركة معلومات شركته/خدماته/أسعاره/سياساته التي
// يعتمد عليها AI Conversation Engine حصريًا (بدون اختلاق معلومات).
// ============================================
$router->get('/api/ai-chat/websites/{id}/knowledge-base', 'AiKnowledgeBaseController', 'index', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/knowledge-base', 'AiKnowledgeBaseController', 'store', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/knowledge-base/{entryId}', 'AiKnowledgeBaseController', 'update', ['AuthMiddleware']);
$router->delete('/api/ai-chat/websites/{id}/knowledge-base/{entryId}', 'AiKnowledgeBaseController', 'destroy', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/knowledge-base/preview', 'AiKnowledgeBaseController', 'preview', ['AuthMiddleware']);

// ============================================
// AI Chat Platform - Custom Tags (بند 11: صاحب الشركة ينشئ Tags إضافية)
// ============================================
$router->get('/api/ai-chat/websites/{id}/custom-tags', 'AiCustomTagController', 'index', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/custom-tags', 'AiCustomTagController', 'store', ['AuthMiddleware']);
$router->delete('/api/ai-chat/websites/{id}/custom-tags/{tagId}', 'AiCustomTagController', 'destroy', ['AuthMiddleware']);

// ============================================
// AI Chat Platform - Leads & Follow-up Automation (بند 5، 6، 7)
// Phase 3: AI Sales Agent (Lead Scoring) + Follow-up Automation settings.
// ============================================
$router->get('/api/ai-chat/websites/{id}/leads', 'AiLeadController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/leads/{leadId}', 'AiLeadController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/leads/{leadId}', 'AiLeadController', 'update', ['AuthMiddleware']);

$router->get('/api/ai-chat/websites/{id}/followup-settings', 'AiFollowupSettingsController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/followup-settings', 'AiFollowupSettingsController', 'update', ['AuthMiddleware']);

// ============================================
// AI Chat Platform - Analytics & Reply Suggestions (بند 12، 18)
// Phase 4: AI Analytics Dashboard + AI Reply Suggestions للموظف.
// ============================================
$router->get('/api/ai-chat/websites/{id}/analytics', 'AiAnalyticsController', 'index', ['AuthMiddleware']);
// سطح صحة/حالة إضافي (بند 8): معدل الحل + جودة استدعاء الـAI
$router->get('/api/ai-chat/websites/{id}/resolution-rate', 'AiResolutionRateController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations', 'ChatInboxController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations/{conversationId}', 'ChatInboxController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/conversations/{conversationId}', 'ChatInboxController', 'update', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/reply', 'ChatInboxController', 'reply', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/handoff', 'ChatInboxController', 'handoff', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/resume-ai', 'ChatInboxController', 'resumeAI', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations/{conversationId}/reply-suggestions', 'ChatInboxController', 'suggestReplies', ['AuthMiddleware']);

// ============================================
// Consolidated Multi-Phase Module (2026-08-08) - إضافات جديدة فقط
// ============================================
// Phase 5 (Auto-Apply)
$router->post('/api/website-optimizer/fixes/{id}/apply-auto', 'WebsiteOptimizerController', 'applyFixAutomatically', ['AuthMiddleware']);
// Phase 13 (Auto Pilot)
$router->post('/api/website-optimizer/auto-pilot-mode', 'WebsiteOptimizerController', 'setAutoPilotMode', ['AuthMiddleware']);
$router->get('/api/website-optimizer/auto-pilot-log', 'WebsiteOptimizerController', 'getAutoPilotLog', ['AuthMiddleware']);
$router->post('/api/website-optimizer/auto-pilot-log/{id}/rollback', 'WebsiteOptimizerController', 'rollbackChange', ['AuthMiddleware']);
// Phase 14 (SEO Strategy Agent)
$router->post('/api/seo-strategy/generate', 'SeoStrategyController', 'generate', ['AuthMiddleware']);
$router->get('/api/seo-strategy/latest', 'SeoStrategyController', 'getLatestPlan', ['AuthMiddleware']);
$router->post('/api/seo-strategy/tasks/{id}/status', 'SeoStrategyController', 'updateTaskStatus', ['AuthMiddleware']);

// Phase 21 (Auto SEO - تنفيذ تلقائي فعلي على المواقع الخارجية عبر embed.js)
$router->post('/api/auto-seo/connect', 'AutoSeoController', 'connect', ['AuthMiddleware']);
$router->delete('/api/auto-seo/connect', 'AutoSeoController', 'disconnect', ['AuthMiddleware']);
$router->post('/api/auto-seo/mode', 'AutoSeoController', 'setMode', ['AuthMiddleware']);
$router->post('/api/auto-seo/apply', 'AutoSeoController', 'apply', ['AuthMiddleware']);
$router->get('/api/auto-seo/logs', 'AutoSeoController', 'logs', ['AuthMiddleware']);
$router->post('/api/auto-seo/rollback/{id}', 'AutoSeoController', 'rollback', ['AuthMiddleware']);
$router->post('/api/auto-seo/preview', 'AutoSeoController', 'preview', ['AuthMiddleware']);
$router->get('/api/auto-seo/report', 'AutoSeoController', 'report', ['AuthMiddleware']);
// Phase 25 (hreflang تلقائي لمواقع متعددة اللغات)
$router->post('/api/auto-seo/hreflang', 'AutoSeoController', 'updateHreflang', ['AuthMiddleware']);
// Phase 25 (Baidu Active Push - فهرسة فورية للمواقع المستهدفة للسوق الصيني)
$router->post('/api/baidu/submit', 'BaiduIndexingController', 'submit', ['AuthMiddleware']);
$router->post('/api/baidu/token', 'BaiduIndexingController', 'setToken', ['AuthMiddleware']);
// Phase 22 (IndexNow - فهرسة فورية لمحركات البحث بعد التطبيق)
$router->post('/api/indexnow/generate-key', 'SeoIndexingController', 'generateKey', ['AuthMiddleware']);
$router->post('/api/indexnow/toggle', 'SeoIndexingController', 'toggle', ['AuthMiddleware']);
$router->get('/api/indexnow/status', 'SeoIndexingController', 'status', ['AuthMiddleware']);
$router->post('/api/indexnow/submit', 'SeoIndexingController', 'submit', ['AuthMiddleware']);
// Phase 26 (M6 SEO: زحف متعدد الصفحات - G1)
$router->post('/api/website-optimizer/crawl', 'WebsiteOptimizerController', 'crawl', ['AuthMiddleware']);
$router->get('/api/website-optimizer/crawl', 'WebsiteOptimizerController', 'crawlResults', ['AuthMiddleware']);
// Phase 26 (M6 SEO: Google Indexing API - G3)
$router->post('/api/google-indexing/toggle', 'SeoIndexingController', 'googleToggle', ['AuthMiddleware']);
$router->post('/api/google-indexing/submit', 'SeoIndexingController', 'googleSubmit', ['AuthMiddleware']);
$router->get('/api/google-indexing/status', 'SeoIndexingController', 'googleStatus', ['AuthMiddleware']);
// Phase 26 (M6 SEO Insights: Rank Tracking G7 + Charts/Schedules G6 + Keyword Research G4)
$router->get('/api/seo/rank-tracking', 'SeoInsightsController', 'rankTracking', ['AuthMiddleware']);
$router->post('/api/seo/rank-tracking/check', 'SeoInsightsController', 'rankTrackingCheck', ['AuthMiddleware']);
$router->get('/api/seo/rank-tracking/history', 'SeoInsightsController', 'rankTrackingHistory', ['AuthMiddleware']);
$router->get('/api/seo/report/charts', 'SeoInsightsController', 'reportCharts', ['AuthMiddleware']);
$router->get('/api/seo/report/schedules', 'SeoInsightsController', 'reportSchedules', ['AuthMiddleware']);
$router->post('/api/seo/report/schedules', 'SeoInsightsController', 'reportScheduleSave', ['AuthMiddleware']);
$router->delete('/api/seo/report/schedules/{id}', 'SeoInsightsController', 'reportScheduleDelete', ['AuthMiddleware']);
$router->get('/api/seo/keyword-research/status', 'SeoInsightsController', 'keywordResearchStatus', ['AuthMiddleware']);
$router->post('/api/seo/keyword-research/enrich', 'SeoInsightsController', 'keywordResearchEnrich', ['AuthMiddleware']);
// Phase 23 (SEO A/B Testing - تجارب العناوين والأوصاف وقياس الفائز)
$router->post('/api/seo-ab-tests', 'SeoAbTestController', 'create', ['AuthMiddleware']);
$router->get('/api/seo-ab-tests', 'SeoAbTestController', 'list', ['AuthMiddleware']);
$router->post('/api/seo-ab-tests/{id}/variants', 'SeoAbTestController', 'addVariant', ['AuthMiddleware']);
$router->post('/api/seo-ab-tests/{id}/start', 'SeoAbTestController', 'start', ['AuthMiddleware']);
$router->post('/api/seo-ab-tests/{id}/complete', 'SeoAbTestController', 'complete', ['AuthMiddleware']);
$router->get('/api/seo-ab-tests/{id}/results', 'SeoAbTestController', 'results', ['AuthMiddleware']);
// Phase 24 (SEO Content Engine)
$router->get('/api/seo-content/discover/{website_id}', 'SeoContentController', 'discover', ['AuthMiddleware']);
$router->post('/api/seo-content/campaigns', 'SeoContentController', 'createCampaign', ['AuthMiddleware']);
$router->get('/api/seo-content/campaigns/{website_id}', 'SeoContentController', 'listCampaigns', ['AuthMiddleware']);
$router->get('/api/seo-content/campaigns/{id}/items', 'SeoContentController', 'listItems', ['AuthMiddleware']);
$router->get('/api/seo-content/campaigns/{id}/stats', 'SeoContentController', 'stats', ['AuthMiddleware']);
$router->post('/api/seo-content/campaigns/{id}/generate', 'SeoContentController', 'generateCampaign', ['AuthMiddleware']);
$router->post('/api/seo-content/items/{id}/generate', 'SeoContentController', 'generateItem', ['AuthMiddleware']);
$router->post('/api/seo-content/items/{id}/index', 'SeoContentController', 'indexItem', ['AuthMiddleware']);
$router->post('/api/seo-content/items/{id}/ab-test', 'SeoContentController', 'abTest', ['AuthMiddleware']);
$router->post('/api/seo-content/engine/run', 'SeoContentController', 'runEngine', ['AuthMiddleware']);
// Phase 15 (Executive Dashboard)
$router->get('/api/executive-dashboard', 'ExecutiveDashboardController', 'getDashboard', ['AuthMiddleware']);
// Phase 16 (Onboarding Wizard)
$router->post('/api/onboarding/complete', 'OnboardingController', 'complete', ['AuthMiddleware']);
$router->post('/api/onboarding/kick', 'OnboardingController', 'kick', ['AuthMiddleware']);
$router->get('/api/onboarding/status', 'OnboardingController', 'status', ['AuthMiddleware']);
// Phase 20 (Onboarding Competitive): كشف تلقائي لاسم النشاط من الموقع
$router->get('/api/onboarding/preview', 'OnboardingController', 'preview', ['AuthMiddleware']);
// Phase 20.1 (Onboarding Professional): مسودة سيرفر (استئناف عبر الأجهزة) + فونيل
$router->get('/api/onboarding/draft', 'OnboardingController', 'getDraft', ['AuthMiddleware']);
$router->put('/api/onboarding/draft', 'OnboardingController', 'saveDraft', ['AuthMiddleware']);
$router->delete('/api/onboarding/draft', 'OnboardingController', 'clearDraft', ['AuthMiddleware']);
$router->post('/api/onboarding/step', 'OnboardingController', 'recordStep', ['AuthMiddleware']);
// Phase 11 (AI CEO Advisor)
$router->post('/api/executive/ceo-advisor/ask', 'ExecutiveExtrasController', 'askCeoAdvisor', ['AuthMiddleware']);
// Phase 12 (Action Center)
$router->get('/api/action-center', 'ActionCenterController', 'list', ['AuthMiddleware']);
$router->get('/api/action-center/actions', 'ActionCenterController', 'actions', ['AuthMiddleware']);
$router->post('/api/action-center/actions/execute', 'ActionCenterController', 'execute', ['AuthMiddleware']);
$router->get('/api/action-center/actions/history', 'ActionCenterController', 'history', ['AuthMiddleware']);
// Phase 9 (Google Business Agent) - درجة اكتمال بروفايل Google Business
$router->get('/api/reputation/google/profile-completeness', 'ReputationController', 'getProfileCompleteness', ['AuthMiddleware']);
// Phase 6 (Keyword Intelligence)
$router->post('/api/ai/keywords/enrich', 'AIController', 'enrichKeywords', ['AuthMiddleware']);
// Phase 8 (Content Agent)
$router->post('/api/ai/tour-page/generate', 'AIController', 'generateTourPageDraft', ['AuthMiddleware']);
$router->post('/api/ai/tour-page/apply', 'AIController', 'applyTourPage', ['AuthMiddleware']);
// Phase 10 (Backlink/Outreach Agent)
$router->get('/api/outreach/prospects', 'OutreachController', 'listProspects', ['AuthMiddleware']);
$router->post('/api/outreach/prospects', 'OutreachController', 'addProspect', ['AuthMiddleware']);
$router->post('/api/outreach/prospects/{id}/status', 'OutreachController', 'updateProspectStatus', ['AuthMiddleware']);
$router->post('/api/outreach/discover', 'OutreachController', 'discover', ['AuthMiddleware']);
$router->post('/api/outreach/emails/generate', 'OutreachController', 'generateEmail', ['AuthMiddleware']);
$router->get('/api/outreach/emails', 'OutreachController', 'listEmails', ['AuthMiddleware']);
$router->post('/api/outreach/emails/{id}/edit', 'OutreachController', 'editEmail', ['AuthMiddleware']);
$router->post('/api/outreach/emails/{id}/approve', 'OutreachController', 'approveEmail', ['AuthMiddleware']);
$router->post('/api/outreach/emails/{id}/send', 'OutreachController', 'sendEmail', ['AuthMiddleware']);
$router->get('/api/outreach/backlinks', 'OutreachController', 'listBacklinks', ['AuthMiddleware']);
$router->post('/api/outreach/backlinks/{id}/check', 'OutreachController', 'checkBacklink', ['AuthMiddleware']);
$router->get('/api/outreach/performance', 'OutreachController', 'performanceReport', ['AuthMiddleware']);

// ============================================
// AI Chat & Customer Communication Platform (2026-08-08)
// Unified Inbox / AI Sales Agent / Knowledge Base / Leads /
// Follow-up Automation / Analytics / Messenger+Instagram+Email
// ============================================
// Unified Inbox - المحادثات
$router->get('/api/ai-chat/websites/{id}/conversations', 'ChatInboxController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations/{conversationId}', 'ChatInboxController', 'show', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/reply', 'ChatInboxController', 'reply', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/handoff', 'ChatInboxController', 'handoff', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/resume-ai', 'ChatInboxController', 'resumeAI', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/conversations/{conversationId}', 'ChatInboxController', 'update', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations/{conversationId}/reply-suggestions', 'ChatInboxController', 'suggestReplies', ['AuthMiddleware']);
// Knowledge Base
$router->get('/api/ai-chat/websites/{id}/knowledge-base', 'AiKnowledgeBaseController', 'index', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/knowledge-base', 'AiKnowledgeBaseController', 'store', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/knowledge-base/preview', 'AiKnowledgeBaseController', 'preview', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/knowledge-base/{entryId}', 'AiKnowledgeBaseController', 'update', ['AuthMiddleware']);
$router->delete('/api/ai-chat/websites/{id}/knowledge-base/{entryId}', 'AiKnowledgeBaseController', 'destroy', ['AuthMiddleware']);
// Leads
$router->get('/api/ai-chat/websites/{id}/leads', 'AiLeadController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/leads/{leadId}', 'AiLeadController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/leads/{leadId}', 'AiLeadController', 'update', ['AuthMiddleware']);
// Follow-up Automation
$router->get('/api/ai-chat/websites/{id}/followup-settings', 'AiFollowupSettingsController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/followup-settings', 'AiFollowupSettingsController', 'update', ['AuthMiddleware']);
// AI Analytics
$router->get('/api/ai-chat/websites/{id}/analytics', 'AiAnalyticsController', 'index', ['AuthMiddleware']);
// In-Chat Quotes (بيع داخل الشات - عروض أسعار جوه المحادثة)
$router->get('/api/ai-chat/websites/{id}/quotes', 'AiQuoteController', 'index', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/quotes', 'AiQuoteController', 'store', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/quotes/{quoteId}', 'AiQuoteController', 'update', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/quotes/{quoteId}/send', 'AiQuoteController', 'send', ['AuthMiddleware']);
// Learning Loop (Zendesk/Fin): فجوات المعرفة المقترحة + إدارتها + إعادة مسح
$router->get('/api/ai-chat/websites/{id}/learning/gaps', 'AiLearningController', 'gaps', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/learning/gaps/{gapId}/status', 'AiLearningController', 'updateGapStatus', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/learning/gaps/scan', 'AiLearningController', 'scan', ['AuthMiddleware']);
// ربط قنوات Messenger/Instagram (بيحتاج AuthMiddleware - بيكلم Meta Graph API)
$router->post('/api/chat/connect/messenger', 'ChatController', 'connectMessenger', ['AuthMiddleware']);
$router->post('/api/chat/connect/instagram', 'ChatController', 'connectInstagram', ['AuthMiddleware']);
// Webhooks للقنوات الجديدة - من غير AuthMiddleware (بتتيجي من Meta/SMTP servers)
$router->get('/api/chat/webhook/messenger/{website_id}', 'ChatController', 'verifyMessengerWebhook');
$router->get('/api/chat/webhook/instagram/{website_id}', 'ChatController', 'verifyInstagramWebhook');
$router->post('/api/chat/webhook/messenger/{website_id}', 'ChatController', 'messengerWebhook');
$router->post('/api/chat/webhook/instagram/{website_id}', 'ChatController', 'instagramWebhook');
$router->post('/api/chat/webhook/email/{website_id}', 'ChatController', 'emailWebhook');

// ============================================
// محرك الحجز والتوفر (Booking Engine - Phase 2)
// ============================================
$router->get('/api/bookings', 'BookingController', 'index', ['AuthMiddleware']);
$router->get('/api/bookings/dashboard', 'BookingController', 'dashboard', ['AuthMiddleware']);
$router->get('/api/bookings/{id}', 'BookingController', 'show', ['AuthMiddleware']);
$router->post('/api/bookings', 'BookingController', 'store', ['AuthMiddleware']);
$router->post('/api/bookings/{id}/confirm', 'BookingController', 'confirm', ['AuthMiddleware']);
$router->post('/api/bookings/{id}/cancel', 'BookingController', 'cancel', ['AuthMiddleware']);
$router->post('/api/bookings/{id}/checkout', 'BookingController', 'checkout', ['AuthMiddleware']);
// Webhook دفع Stripe للحجوزات - بدون Auth (التحقق بالتوقيع داخل الـ service)
$router->post('/api/webhook/booking/stripe', 'BookingController', 'stripeWebhook');
// Paymob gateway (جنب Stripe - نفس النقطة القابلة للاستبدال في BookingController)
$router->post('/api/webhook/booking/paymob', 'BookingController', 'paymobWebhook');
// Webhook Stripe موحّد لشحن المحفظة وتأكيد الحجوزات - بدون Auth (التوقيع HMAC)
$router->post('/api/webhook/stripe', 'WebhookController', 'walletStripeWebhook');
$router->get('/api/inventory/{productId}/calendar', 'BookingController', 'calendar', ['AuthMiddleware']);
$router->post('/api/inventory/{productId}', 'BookingController', 'setInventory', ['AuthMiddleware']);

// ============================================
// التكاملات الخارجية (Integrations Center)
// إدارة مفاتيح Slack/Zapier/HubSpot/Algolia/... من لوحة الأدمن
// الصلاحية: admin/super_admin (متفرضة داخل الـ Controller)
// ============================================
$router->get('/api/integrations', 'IntegrationsController', 'index', ['AuthMiddleware']);
$router->get('/api/integrations/{key}/status', 'IntegrationsController', 'status', ['AuthMiddleware']);
$router->post('/api/integrations/{key}/save', 'IntegrationsController', 'save', ['AuthMiddleware']);
$router->post('/api/integrations/{key}/test', 'IntegrationsController', 'test', ['AuthMiddleware']);
