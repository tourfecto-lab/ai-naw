<?php

use PHPUnit\Framework\TestCase;

/** A shared contact card used to arrive as the useless text "[contacts]"; it is now "[Contact] Name · phone". */
final class WhatsAppContactsTextTest extends TestCase
{
    private function text(array $cards): string
    {
        $m = new ReflectionMethod(WhatsAppCloudController::class, 'contactsText');
        $m->setAccessible(true);
        return $m->invoke(null, $cards);
    }

    public function testNameAndPhone(): void
    {
        $t = $this->text([['name' => ['formatted_name' => 'Ahmed Guide'], 'phones' => [['phone' => '+20 100 123 4567']]]]);
        $this->assertSame('[Contact] Ahmed Guide · +20 100 123 4567', $t);
    }

    public function testSeveralCardsAreCappedAndJoined(): void
    {
        $cards = array_map(static fn ($i) => ['name' => ['formatted_name' => 'Person ' . $i], 'phones' => []], range(1, 5));
        $this->assertSame('[Contact] Person 1 | Person 2 | Person 3', $this->text($cards));
    }

    public function testControlCharactersAndMarkupInPhonesAreStripped(): void
    {
        $t = $this->text([['name' => ['formatted_name' => "A\x07B"], 'phones' => [['phone' => '<b>+201000000000</b>']]]]);
        $this->assertStringNotContainsString("\x07", $t);
        $this->assertStringNotContainsString('<', $t);
        $this->assertStringContainsString('+201000000000', $t);
    }

    public function testEmptyCards(): void
    {
        $this->assertSame('[Contact]', $this->text([]));
        $this->assertSame('[Contact]', $this->text([['name' => [], 'phones' => []]]));
    }
}
