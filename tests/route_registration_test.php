<?php

/**
 * Tourfecto - AI Chat Platform Routes Test
 * اختبار تسجيل مسارات API الخاصة بـ AI Chat Platform (2026-08-08)
 * بيتأكد إن كل مسارات /api/ai-chat/* و /api/chat/webhook/*
 * مسجّلة في الراوتر وإن الـ regex بتاعها بيطابق عناوين فعلية.
 * مش محتاج قاعدة بيانات - بيشغل نفسه لوحده.
 *
 * التشغيل: php tests/route_registration_test.php
 * @version 1.0.0
 */

require_once __DIR__ . '/../app/Core/Router.php';

class AiChatRoutesTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        $router = new Router();
        require_once __DIR__ . '/../app/routes/api.php';

        $ref = new ReflectionProperty(Router::class, 'routes');
        $ref->setAccessible(true);
        $routes = $ref->getValue($router);

        $expect = [
            ['GET',  '/api/ai-chat/websites/42/conversations', 'ChatInboxController', 'index'],
            ['GET',  '/api/ai-chat/websites/42/conversations/77', 'ChatInboxController', 'show'],
            ['POST', '/api/ai-chat/websites/42/conversations/77/reply', 'ChatInboxController', 'reply'],
            ['POST', '/api/ai-chat/websites/42/conversations/77/handoff', 'ChatInboxController', 'handoff'],
            ['POST', '/api/ai-chat/websites/42/conversations/77/resume-ai', 'ChatInboxController', 'resumeAI'],
            ['PUT',  '/api/ai-chat/websites/42/conversations/77', 'ChatInboxController', 'update'],
            ['GET',  '/api/ai-chat/websites/42/conversations/77/reply-suggestions', 'ChatInboxController', 'suggestReplies'],
            ['GET',  '/api/ai-chat/websites/42/knowledge-base', 'AiKnowledgeBaseController', 'index'],
            ['POST', '/api/ai-chat/websites/42/knowledge-base', 'AiKnowledgeBaseController', 'store'],
            ['GET',  '/api/ai-chat/websites/42/knowledge-base/preview', 'AiKnowledgeBaseController', 'preview'],
            ['PUT',  '/api/ai-chat/websites/42/knowledge-base/9', 'AiKnowledgeBaseController', 'update'],
            ['DELETE', '/api/ai-chat/websites/42/knowledge-base/9', 'AiKnowledgeBaseController', 'destroy'],
            ['GET',  '/api/ai-chat/websites/42/leads', 'AiLeadController', 'index'],
            ['GET',  '/api/ai-chat/websites/42/leads/3', 'AiLeadController', 'show'],
            ['PUT',  '/api/ai-chat/websites/42/leads/3', 'AiLeadController', 'update'],
            ['GET',  '/api/ai-chat/websites/42/followup-settings', 'AiFollowupSettingsController', 'show'],
            ['PUT',  '/api/ai-chat/websites/42/followup-settings', 'AiFollowupSettingsController', 'update'],
            ['GET',  '/api/ai-chat/websites/42/analytics', 'AiAnalyticsController', 'index'],
            ['GET',  '/api/ai-chat/websites/42/quotes', 'AiQuoteController', 'index'],
            ['POST', '/api/ai-chat/websites/42/quotes', 'AiQuoteController', 'store'],
            ['PUT',  '/api/ai-chat/websites/42/quotes/9', 'AiQuoteController', 'update'],
            ['POST', '/api/ai-chat/websites/42/quotes/9/send', 'AiQuoteController', 'send'],
            ['GET',  '/api/ai-chat/websites/42/learning/gaps', 'AiLearningController', 'gaps'],
            ['POST', '/api/ai-chat/websites/42/learning/gaps/9/status', 'AiLearningController', 'updateGapStatus'],
            ['POST', '/api/ai-chat/websites/42/learning/gaps/scan', 'AiLearningController', 'scan'],
            ['POST', '/api/chat/connect/messenger', 'ChatController', 'connectMessenger'],
            ['POST', '/api/chat/connect/instagram', 'ChatController', 'connectInstagram'],
            ['GET',  '/api/chat/webhook/messenger/5', 'ChatController', 'verifyMessengerWebhook'],
            ['GET',  '/api/chat/webhook/instagram/5', 'ChatController', 'verifyInstagramWebhook'],
            ['POST', '/api/chat/webhook/messenger/5', 'ChatController', 'messengerWebhook'],
            ['POST', '/api/chat/webhook/instagram/5', 'ChatController', 'instagramWebhook'],
            ['POST', '/api/chat/webhook/email/5', 'ChatController', 'emailWebhook'],
        ];

        foreach ($expect as [$method, $url, $controller, $action]) {
            $this->assertRoute($routes, $method, $url, $controller, $action);
        }

        echo "\nAI Chat Routes Test: {$this->passed} passed, {$this->failed} failed\n";
        exit($this->failed > 0 ? 1 : 0);
    }

    private function assertRoute(array $routes, string $method, string $url, string $controller, string $action): void
    {
        if (!isset($routes[$method])) {
            echo "FAIL: no routes registered for method $method\n";
            $this->failed++;
            return;
        }
        foreach ($routes[$method] as $route) {
            if (preg_match($route['pattern'], $url)) {
                if ($route['controller'] === $controller && $route['action'] === $action) {
                    echo "OK: $method $url -> $controller::$action\n";
                    $this->passed++;
                } else {
                    echo "FAIL: $method $url matched {$route['controller']}::{$route['action']} instead of $controller::$action\n";
                    $this->failed++;
                }
                return;
            }
        }
        echo "FAIL: $method $url -> expected $controller::$action, no match\n";
        $this->failed++;
    }
}

$test = new AiChatRoutesTest();
$test->runAll();
