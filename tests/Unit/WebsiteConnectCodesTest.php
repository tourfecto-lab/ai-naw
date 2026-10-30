<?php

use PHPUnit\Framework\TestCase;

/** "Connect your website" checks the live home page for the platform code and the chat code. */
final class WebsiteConnectCodesTest extends TestCase
{
    public function testThePlatformAndChatCodesAreFoundInTheHomePage(): void
    {
        $html = '<head><script>(function(){var s=document.createElement(\'script\');s.src=\'https://tourfecto.pro/embed.js?token=emb_0123456789abcdef01234567\';s.async=true;s.dataset.tourfecto=\'auto-seo\';})();</script>'
            . '<script src="https://tourfecto.pro/widget/chat?site=41&t=abc" async></script></head>';
        $this->assertSame(['seo' => true, 'chat' => true], WebsiteController::codesInHtml($html));
    }

    public function testASiteWithoutTheCodesIsReportedAsMissingThem(): void
    {
        $this->assertSame(['seo' => false, 'chat' => false], WebsiteController::codesInHtml('<html><head><title>Tours</title></head><body>Hi</body></html>'));
    }
}
