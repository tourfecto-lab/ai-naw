<?php

use PHPUnit\Framework\TestCase;

/** Request bodies for WhatsApp quick-reply buttons and option lists (pure: nothing is sent). */
final class WhatsAppInteractiveBodyTest extends TestCase
{
    private const TO = '201000000000';

    public function testButtonsBody(): void
    {
        $b = WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => 'Pick a time', 'buttons' => ['06:00', '07:30', 'Other']]);
        $this->assertSame('interactive', $b['type']);
        $this->assertSame('button', $b['interactive']['type']);
        $this->assertSame('Pick a time', $b['interactive']['body']['text']);
        $this->assertCount(3, $b['interactive']['action']['buttons']);
        $this->assertSame(['id' => 'btn_2', 'title' => '07:30'], $b['interactive']['action']['buttons'][1]['reply']);
        $this->assertSame(self::TO, $b['to']);
    }

    public function testListBody(): void
    {
        $b = WhatsAppCloudAPI::interactiveBody(self::TO, [
            'kind' => 'list', 'body' => 'Our tours', 'button_label' => 'Choose', 'footer' => 'Tourfecto',
            'rows' => ['Sunrise balloon', ['title' => 'Luxor day tour', 'description' => 'Full day, lunch included']],
        ]);
        $this->assertSame('list', $b['interactive']['type']);
        $this->assertSame('Choose', $b['interactive']['action']['button']);
        $rows = $b['interactive']['action']['sections'][0]['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('Full day, lunch included', $rows[1]['description']);
        $this->assertSame('Tourfecto', $b['interactive']['footer']['text']);
    }

    public function testBsuidRecipient(): void
    {
        $b = WhatsAppCloudAPI::interactiveBody('US.13491208655302741918', ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => ['Yes']]);
        $this->assertSame('US.13491208655302741918', $b['recipient']);
        $this->assertArrayNotHasKey('to', $b);
    }

    public function testLimitsAreEnforcedNotTruncated(): void
    {
        $ok = ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => ['A']];
        $this->assertNotNull(WhatsAppCloudAPI::interactiveBody(self::TO, $ok));

        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => ['A', 'B', 'C', 'D']]), 'max 3 buttons');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => ['A', 'a']]), 'duplicate titles');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => [str_repeat('x', 21)]]), 'title over 20');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => []]), 'no buttons');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => ['  ']]), 'blank title');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => '', 'buttons' => ['A']]), 'empty body');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'buttons', 'body' => str_repeat('y', 1025), 'buttons' => ['A']]), 'body over 1024');

        $rows = array_map(static fn ($i) => 'Row ' . $i, range(1, 11));
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'list', 'body' => 'Hi', 'button_label' => 'Go', 'rows' => $rows]), 'max 10 rows');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'list', 'body' => 'Hi', 'button_label' => '', 'rows' => ['A']]), 'list needs a button label');
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'list', 'body' => 'Hi', 'button_label' => 'Go', 'rows' => [str_repeat('z', 25)]]), 'row title over 24');
    }

    public function testUnknownKindOrRecipientYieldsNothing(): void
    {
        $this->assertNull(WhatsAppCloudAPI::interactiveBody(self::TO, ['kind' => 'carousel', 'body' => 'Hi']));
        $this->assertNull(WhatsAppCloudAPI::interactiveBody('abc', ['kind' => 'buttons', 'body' => 'Hi', 'buttons' => ['A']]));
    }

    public function testRouteAndHandlerExist(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes/api.php');
        $this->assertStringContainsString("/conversations/{conversationId}/interactive', 'ChatInboxController', 'sendInteractive', ['AuthMiddleware']", $src);
        $this->assertTrue(method_exists(ChatInboxController::class, 'sendInteractive'));
    }
}
