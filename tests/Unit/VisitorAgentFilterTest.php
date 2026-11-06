<?php

use PHPUnit\Framework\TestCase;

/**
 * التتبع بيتجاهل الوكلاء اللي مش بشر: فحص التشغيل بتاع المنصة نفسها (كان بيطلّع آلاف «الزوار» من فرنسا)،
 * والسكريبتات والزواحف، من غير ما يتجاهل المتصفحات الحقيقية.
 */
final class VisitorAgentFilterTest extends TestCase
{
    public function testOwnOpsCheckAndScriptsAreNotVisitors(): void
    {
        foreach ([
            'TourfectoOpsCheck/1.0 (+https://tourfecto.pro)',
            'python-requests/2.31.0',
            'curl/8.4.0',
            'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)',
            'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0',
            'Go-http-client/2.0',
            'UptimeRobot/2.0',
            'Mozilla/5.0 (compatible; AhrefsBot/7.0)',
            '',
            null,
        ] as $ua) {
            $this->assertTrue(VisitorTrackingMiddleware::isNonHumanAgent($ua), 'لازم يتجاهل: ' . var_export($ua, true));
        }
    }

    public function testRealBrowsersAreStillTracked(): void
    {
        foreach ([
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36',
        ] as $ua) {
            $this->assertFalse(VisitorTrackingMiddleware::isNonHumanAgent($ua), 'مايتجاهلش متصفح حقيقي: ' . $ua);
        }
    }
}
