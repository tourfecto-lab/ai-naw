<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/AiText.php';

/** The weekly SEO plan asks for 12000 output tokens; the OpenAI path used to cap everything at 4096 and could cut the JSON. */
final class AiTextOutputCapTest extends TestCase
{
    public function testModernOpenAiModelsKeepTheRequestedBudgetUpTo16k(): void
    {
        $this->assertSame(12000, AiText::openAiOutputCap('gpt-4o-mini', 12000));
        $this->assertSame(16000, AiText::openAiOutputCap('gpt-4.1-mini', 40000));
        $this->assertSame(2000, AiText::openAiOutputCap('gpt-4o', 2000));
    }

    public function testOlderOrUnknownModelsStayAtTheSafeCap(): void
    {
        $this->assertSame(4096, AiText::openAiOutputCap('gpt-3.5-turbo', 12000));
        $this->assertSame(4096, AiText::openAiOutputCap('some-custom-model', 12000));
    }

    public function testNeverBelowTheFloor(): void
    {
        $this->assertSame(256, AiText::openAiOutputCap('gpt-4o-mini', 10));
    }
}
