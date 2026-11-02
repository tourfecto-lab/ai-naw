<?php

use PHPUnit\Framework\TestCase;

/** Defects found in a live article (#62): FAQ shown twice, an Arabic label inside an English article, invented venues. */
final class ArticleGeneratorGuardsTest extends TestCase
{
    public function testFaqWrittenInlineAsBulletsIsNotAppendedAgain(): void
    {
        $content = "## FAQs\n\n- What is the best way to reach Sohail Island? You can reach Sohail Island by booking a boat trip from Aswan.\n- What should I wear when visiting? Light clothing.";
        $faqs = [
            ['question' => 'What is the best way to reach Sohail Island?', 'answer' => 'By boat from Aswan.'],
            ['question' => 'What should I wear when visiting?', 'answer' => 'Light clothing.'],
        ];
        $out = ArticleFaqContent::ensureVisible($content, $faqs);
        $this->assertSame(1, substr_count($out, 'What is the best way to reach Sohail Island?'));
        $this->assertSame(1, substr_count($out, 'What should I wear when visiting?'));
    }

    public function testMissingFaqIsStillAppendedAndExistingHeadingFormStillRecognised(): void
    {
        $content = "Intro\n\n## What time should I go?\n\nMorning.";
        $faqs = [
            ['question' => 'What time should I go?', 'answer' => 'Morning.'],
            ['question' => 'Is it safe for children?', 'answer' => 'Yes, with life jackets.'],
        ];
        $out = ArticleFaqContent::ensureVisible($content, $faqs);
        $this->assertSame(1, substr_count($out, 'What time should I go?'));
        $this->assertStringContainsString("## Is it safe for children?\n\nYes, with life jackets.", $out);
    }

    public function testArabicInsideAnEnglishArticleIsDetected(): void
    {
        $this->assertTrue(ArticleGenerator::languageMixed('en', ['Title', "- التوقيت: Early morning is best."]));
        $this->assertFalse(ArticleGenerator::languageMixed('en', ['Sohail Island guide', 'Plain English text.']));
        $this->assertFalse(ArticleGenerator::languageMixed('ar', ['مقال عربي كامل']), 'Arabic articles are not mixed');
        $this->assertFalse(ArticleGenerator::languageMixed('fa', ['مقاله فارسی']), 'other Arabic-script languages are not mixed either');
    }

    public function testPromptCarriesTheLanguageAndAccuracyRules(): void
    {
        $en = ArticleGenerator::guardRules('en', 'English');
        $this->assertStringContainsString('CRITICAL LANGUAGE RULE', $en);
        $this->assertStringContainsString('CRITICAL ACCURACY RULE', $en);
        $this->assertStringContainsString('museum, restaurant', $en);
        $ar = ArticleGenerator::guardRules('ar', 'العربية');
        $this->assertStringNotContainsString('CRITICAL LANGUAGE RULE', $ar);
        $this->assertStringContainsString('CRITICAL ACCURACY RULE', $ar);
    }

    public function testGenerationRefusesAMixedLanguageArticleAndAcceptsAClean(): void
    {
        $mk = static function (string $content) {
            return new class($content) {
                public function __construct(private string $content) {}
                public function generateContent(string $prompt, array $opts = []): array
                {
                    return ['success' => true, 'data' => json_encode(['title' => 'Visiting Sohail Island', 'meta_description' => 'A short guide to Sohail Island.', 'content' => $this->content, 'suggested_keywords' => ['sohail'], 'faqs' => [['question' => 'Best time?', 'answer' => 'Morning.']], 'internal_link_suggestions' => []], JSON_UNESCAPED_UNICODE), 'tokens_used' => 10, 'cost' => 0.0];
                }
            };
        };
        $bad = (new ArticleGenerator($mk("## Tips\n\n- التوقيت: Early morning.")))->generate('Sohail Island', 'en');
        $this->assertFalse($bad['success']);
        $good = (new ArticleGenerator($mk("## Tips\n\n- Timing: Early morning.")))->generate('Sohail Island', 'en');
        $this->assertTrue($good['success']);
        $this->assertSame(1, substr_count($good['data']['content'], 'Best time?'));
    }
}
