<?php

use PHPUnit\Framework\TestCase;

/**
 * Request bodies for the official WhatsApp API: reply context and emoji reactions. Pure (no network, nothing is sent).
 */
final class WhatsAppReplyReactionTest extends TestCase
{
    private const WAMID = 'wamid.HBgLMjAxMDAwMDAwMDAwFQIAEhggQUJDREVGMDEyMzQ1Njc4OTA=';

    public function testPlainTextHasNoContext(): void
    {
        $b = WhatsAppCloudAPI::textBody('+20 100 000 0000', 'hello');
        $this->assertSame('text', $b['type']);
        $this->assertSame('hello', $b['text']['body']);
        $this->assertArrayNotHasKey('context', $b);
        $this->assertSame('201000000000', $b['to']);
        $this->assertSame('individual', $b['recipient_type']);
    }

    public function testReplyAddsContextMessageId(): void
    {
        $b = WhatsAppCloudAPI::textBody('201000000000', 'yes please', self::WAMID);
        $this->assertSame(['message_id' => self::WAMID], $b['context']);
    }

    public function testBadReplyIdsAreDroppedNotSent(): void
    {
        foreach ([null, '', 'x', "wamid.bad id with spaces", str_repeat('a', 300)] as $bad) {
            $b = WhatsAppCloudAPI::textBody('201000000000', 'hi', $bad);
            $this->assertArrayNotHasKey('context', $b, 'an invalid id must not reach the provider');
        }
    }

    public function testBsuidRecipientUsesRecipientField(): void
    {
        $b = WhatsAppCloudAPI::textBody('US.13491208655302741918', 'hi', self::WAMID);
        $this->assertSame('US.13491208655302741918', $b['recipient']);
        $this->assertArrayNotHasKey('to', $b);
    }

    public function testInvalidRecipientYieldsNoBody(): void
    {
        $this->assertNull(WhatsAppCloudAPI::textBody('abc', 'hi'));
        $this->assertNull(WhatsAppCloudAPI::textBody('', 'hi'));
        $this->assertNull(WhatsAppCloudAPI::reactionBody('', self::WAMID, '👍'));
    }

    public function testReactionBody(): void
    {
        $b = WhatsAppCloudAPI::reactionBody('201000000000', self::WAMID, '👍');
        $this->assertSame('reaction', $b['type']);
        $this->assertSame(['message_id' => self::WAMID, 'emoji' => '👍'], $b['reaction']);
        $this->assertSame('201000000000', $b['to']);
    }

    public function testEmptyEmojiRemovesTheReaction(): void
    {
        $b = WhatsAppCloudAPI::reactionBody('201000000000', self::WAMID, '');
        $this->assertSame('', $b['reaction']['emoji']);
    }

    public function testReactionRejectsTextAndBadIds(): void
    {
        $this->assertNull(WhatsAppCloudAPI::reactionBody('201000000000', self::WAMID, 'ok'), 'letters are not an emoji');
        $this->assertNull(WhatsAppCloudAPI::reactionBody('201000000000', self::WAMID, '<b>'), 'markup');
        $this->assertNull(WhatsAppCloudAPI::reactionBody('201000000000', self::WAMID, '👍👍👍👍👍👍👍👍👍'), 'too long');
        $this->assertNull(WhatsAppCloudAPI::reactionBody('201000000000', 'short', '👍'), 'invalid message id');
    }

    public function testReactionAndReplyRoutesAreRegistered(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes/api.php');
        $this->assertStringContainsString("/messages/{messageId}/react', 'ChatInboxController', 'react', ['AuthMiddleware']", $src);
        $this->assertTrue(method_exists(ChatInboxController::class, 'react'));
        $m = new ReflectionMethod(ChatManager::class, 'sendMessageForWebsite');
        $this->assertSame('replyToWamid', $m->getParameters()[4]->getName(), 'channel stays 4th, reply id is the new optional 5th');
        $this->assertTrue($m->getParameters()[4]->isOptional());
    }
}
