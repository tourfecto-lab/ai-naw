<?php

use PHPUnit\Framework\TestCase;

/**
 * AutoReplyEngine::postProcessReply (fallback reply path) used to flatten every line break, put a "." after the
 * Arabic question mark and append an Arabic closing phrase to replies in any language.
 */
final class AutoReplyEnginePostProcessTest extends TestCase
{
    private function post(string $reply, string $sentiment = 'neutral'): string
    {
        $engine = (new ReflectionClass(AutoReplyEngine::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($engine, 'postProcessReply');
        $m->setAccessible(true);
        return $m->invoke($engine, $reply, ['sentiment' => ['label' => $sentiment]]);
    }

    public function testLineBreaksAreKept(): void
    {
        $out = $this->post("Options:\n1) Sunrise tour\n2) Old town walk\n\n\n\nReply with a number");
        $this->assertStringContainsString("Options:\n1) Sunrise tour\n2) Old town walk\n\nReply with a number", $out);
        $this->assertStringNotContainsString("\n\n\n", $out);
    }

    public function testEnglishReplyGetsNoArabicClosingPhrase(): void
    {
        $this->assertSame('The tour starts at 8am.', $this->post('The tour starts at 8am'));
        $this->assertSame('Anything else?', $this->post('Anything else?'));
    }

    public function testArabicPunctuationIsNotFollowedByAStop(): void
    {
        $out = $this->post('تحب نحجزلك الرحلة؟');
        $this->assertStringStartsWith('تحب نحجزلك الرحلة؟ ', $out);
        $this->assertStringNotContainsString('؟.', $out);
    }

    public function testArabicReplyWithoutPunctuationGetsAStopAndAClosingPhrase(): void
    {
        $out = $this->post('الرحلة تبدأ الساعة الثامنة');
        $this->assertStringStartsWith('الرحلة تبدأ الساعة الثامنة. ', $out);
        $this->assertGreaterThan(mb_strlen('الرحلة تبدأ الساعة الثامنة. '), mb_strlen($out));
    }

    public function testEmojiEndingGetsNoStop(): void
    {
        $this->assertSame('See you soon 🙂', $this->post('See you soon 🙂'));
    }
}
