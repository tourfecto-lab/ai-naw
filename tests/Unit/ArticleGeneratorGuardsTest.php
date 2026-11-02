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

    public function testRawFaqJsonInsideTheContentIsRemovedAndTheQuestionsRecovered(): void
    {
        $live = "## Conclusion\n\nA boat trip is a lovely way to spend an afternoon.\n\n## FAQs\n\n{\"question\":\"What is the best time to visit Sohail Island?\",\"answer\":\"October to March.\"},{\"question\":\"Can I swim in the Nile?\",\"answer\":\"Swimming is not recommended due to strong currents.\"}]";
        [$clean, $faqs] = ArticleGenerator::stripEmbeddedFaqJson($live);
        $this->assertSame("## Conclusion\n\nA boat trip is a lovely way to spend an afternoon.", $clean);
        $this->assertSame([
            ['question' => 'What is the best time to visit Sohail Island?', 'answer' => 'October to March.'],
            ['question' => 'Can I swim in the Nile?', 'answer' => 'Swimming is not recommended due to strong currents.'],
        ], $faqs);
        $plain = "## Tips\n\nBring water.";
        $this->assertSame([$plain, []], ArticleGenerator::stripEmbeddedFaqJson($plain));
    }

    public function testGenerationRebuildsTheFaqFromJsonLeftInTheContent(): void
    {
        $client = new class {
            public function generateContent(string $prompt, array $opts = []): array
            {
                $content = "## Intro\n\nSohail Island is near Aswan.\n\n## FAQs\n\n{\"question\":\"Best time?\",\"answer\":\"Morning.\"}]";
                return ['success' => true, 'data' => json_encode(['title' => 'Sohail Island', 'meta_description' => 'Guide.', 'content' => $content, 'suggested_keywords' => [], 'faqs' => [], 'internal_link_suggestions' => []]), 'tokens_used' => 1, 'cost' => 0.0];
            }
        };
        $r = (new ArticleGenerator($client))->generate('Sohail Island', 'en');
        $this->assertTrue($r['success']);
        $this->assertStringNotContainsString('"question"', $r['data']['content']);
        $this->assertSame(1, substr_count($r['data']['content'], 'Best time?'));
        $this->assertSame('Morning.', $r['data']['faqs'][0]['answer']);
    }

    private static function longBody(int $words): string
    {
        return "## Intro\n\n" . trim(str_repeat('Practical travel detail for readers. ', (int) ceil($words / 5)));
    }

    public function testThinArticlesAreDetected(): void
    {
        $faq = static fn (int $n) => array_map(static fn ($i) => ['question' => "Question $i?", 'answer' => "Answer $i."], range(1, $n));
        $this->assertNull(ArticleGenerator::thinReason(['content' => self::longBody(800), 'faqs' => $faq(3)]));
        $this->assertStringContainsString('only', (string) ArticleGenerator::thinReason(['content' => self::longBody(300), 'faqs' => $faq(3)]));
        $short = (string) ArticleGenerator::thinReason(['content' => self::longBody(800), 'faqs' => $faq(1)]);
        $this->assertStringContainsString('1 FAQ', $short);
        $both = (string) ArticleGenerator::thinReason(['content' => self::longBody(200), 'faqs' => []]);
        $this->assertStringContainsString('words', $both);
        $this->assertStringContainsString('FAQ', $both);
    }

    public function testThinFirstAnswerGetsOneRetryAndTheBetterArticleWins(): void
    {
        $client = new class(self::longBody(300), self::longBody(820)) {
            public int $calls = 0;
            public function __construct(private string $thin, private string $good) {}
            public function generateContent(string $prompt, array $opts = []): array
            {
                $this->calls++;
                $first = $this->calls === 1;
                $faqs = $first ? [['question' => 'Only one?', 'answer' => 'Yes.']] : [['question' => 'Q1?', 'answer' => 'A1.'], ['question' => 'Q2?', 'answer' => 'A2.'], ['question' => 'Q3?', 'answer' => 'A3.']];
                return ['success' => true, 'data' => json_encode(['title' => 'Sohail', 'meta_description' => 'Guide', 'content' => $first ? $this->thin : $this->good, 'suggested_keywords' => [], 'faqs' => $faqs, 'internal_link_suggestions' => []]), 'tokens_used' => 100, 'cost' => 0.01];
            }
        };
        $r = (new ArticleGenerator($client))->generate('Sohail Island', 'en');
        $this->assertTrue($r['success']);
        $this->assertSame(2, $client->calls, 'exactly one retry');
        $this->assertGreaterThan(700, $r['data']['word_count']);
        $this->assertCount(3, $r['data']['faqs']);
        $this->assertSame(200, $r['tokens_used']);
        $this->assertEqualsWithDelta(0.02, $r['cost'], 0.0001);
    }

    public function testAGoodFirstAnswerIsNotRetried(): void
    {
        $client = new class(self::longBody(820)) {
            public int $calls = 0;
            public function __construct(private string $good) {}
            public function generateContent(string $prompt, array $opts = []): array
            {
                $this->calls++;
                return ['success' => true, 'data' => json_encode(['title' => 'Sohail', 'meta_description' => 'Guide', 'content' => $this->good, 'suggested_keywords' => [], 'faqs' => [['question' => 'Q1?', 'answer' => 'A1.'], ['question' => 'Q2?', 'answer' => 'A2.'], ['question' => 'Q3?', 'answer' => 'A3.']], 'internal_link_suggestions' => []]), 'tokens_used' => 100, 'cost' => 0.01];
            }
        };
        (new ArticleGenerator($client))->generate('Sohail Island', 'en');
        $this->assertSame(1, $client->calls);
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
