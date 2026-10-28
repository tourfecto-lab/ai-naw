<?php

/**
 * Tourfecto - Webhook Routes
 * تعريف مسارات Webhooks للتكامل مع المنصات الخارجية
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// نقطة نهاية Webhooks الرئيسية
// ============================================
$router->any('/api/webhook', 'WebhookController', 'handle');

// ============================================
// Webhooks للمراجعات
// ============================================
$router->post('/api/webhook/review', 'WebhookController', 'handleReview');
$router->post('/api/webhook/review/tripadvisor', 'WebhookController', 'handleTripAdvisorReview');
$router->post('/api/webhook/review/google', 'WebhookController', 'handleGoogleBusinessReview');
$router->post('/api/webhook/review/booking', 'WebhookController', 'handleBookingReview');
$router->post('/api/webhook/review/expedia', 'WebhookController', 'handleExpediaReview');

// ============================================
// Webhooks للشات
// ============================================
$router->any('/api/webhook/chat', 'WebhookController', 'handleChat');
$router->post('/api/webhook/chat/whatsapp', 'WebhookController', 'handleWhatsApp');
$router->get('/api/webhook/chat/whatsapp', 'ChatController', 'verifyWebhook');
$router->post('/api/webhook/chat/telegram', 'WebhookController', 'handleTelegram');
$router->post('/api/webhook/chat/messenger', 'WebhookController', 'handleMessenger');
$router->post('/api/webhook/chat/ultramsg', 'WebhookController', 'handleUltraMsg');

// ============================================
// Webhooks للدفع
// ============================================
$router->post('/api/webhook/payment/stripe', 'WebhookController', 'handleStripe');
$router->post('/api/webhook/payment/paypal', 'WebhookController', 'handlePayPal');
$router->post('/api/webhook/payment/sadad', 'WebhookController', 'handleSadad');
$router->post('/api/webhook/payment/mada', 'WebhookController', 'handleMada');

// ============================================
// Webhooks للجداولة
// ============================================
$router->post('/api/webhook/calendar', 'WebhookController', 'handleCalendar');
$router->post('/api/webhook/calendar/google', 'WebhookController', 'handleGoogleCalendar');
$router->post('/api/webhook/calendar/outlook', 'WebhookController', 'handleOutlookCalendar');

// ============================================
// Webhooks لأنظمة الطرف الثالث
// ============================================
$router->post('/api/webhook/crm', 'WebhookController', 'handleCRM');
$router->post('/api/webhook/crm/salesforce', 'WebhookController', 'handleSalesforce');
$router->post('/api/webhook/crm/hubspot', 'WebhookController', 'handleHubspot');

$router->post('/api/webhook/analytics', 'WebhookController', 'handleAnalytics');
$router->post('/api/webhook/analytics/google', 'WebhookController', 'handleGoogleAnalytics');
$router->post('/api/webhook/analytics/facebook', 'WebhookController', 'handleFacebookAnalytics');

$router->post('/api/webhook/notification', 'WebhookController', 'handleNotification');
$router->post('/api/webhook/notification/slack', 'WebhookController', 'handleSlack');
$router->post('/api/webhook/notification/teams', 'WebhookController', 'handleTeams');

// ============================================
// Webhooks للمزامنة
// ============================================
$router->post('/api/webhook/sync', 'WebhookController', 'handleSync');
$router->post('/api/webhook/sync/database', 'WebhookController', 'handleDatabaseSync');
$router->post('/api/webhook/sync/files', 'WebhookController', 'handleFileSync');

// ============================================
// Webhooks مخصصة للمستخدمين
// ============================================
$router->post('/api/webhook/custom/{endpoint}', 'WebhookController', 'handleCustom');
$router->any('/api/webhook/custom/{endpoint}/{action}', 'WebhookController', 'handleCustomAction');

// ============================================
// مسارات اختبار Webhooks
// ============================================
$router->post('/api/webhook/test', 'WebhookController', 'testWebhook');
$router->get('/api/webhook/test', 'WebhookController', 'testWebhookGet');

// ============================================
// مسارات تسجيل Webhooks
// ============================================
$router->get('/api/webhooks/registered', 'WebhookController', 'getRegisteredWebhooks', ['AuthMiddleware']);
$router->post('/api/webhooks/register', 'WebhookController', 'registerWebhook', ['AuthMiddleware']);
$router->delete('/api/webhooks/register/{id}', 'WebhookController', 'unregisterWebhook', ['AuthMiddleware']);
$router->post('/api/webhooks/retry/{id}', 'WebhookController', 'retryWebhook', ['AuthMiddleware']);
$router->get('/api/webhooks/logs', 'WebhookController', 'getWebhookLogs', ['AuthMiddleware']);

// ============================================
// نقاط نهاية صحية للمنصات
// ============================================
$router->get('/api/webhook/health', 'WebhookController', 'healthCheck');
$router->get('/api/webhook/ping', 'WebhookController', 'ping');
$router->get('/api/webhook/status', 'WebhookController', 'status');

// ============================================
// معالجة طلبات OPTIONS لـ Webhooks
// ============================================
$router->options('/api/webhook/{path}', 'WebhookController', 'handleOptions');
$router->options('/api/webhook', 'WebhookController', 'handleOptions');
