<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/ArticleGenerator.php';

/** حماية المدة في المقالات: المدة المكتوبة في بيانات الشركة المؤكدة تعدّي، والمخترعة تترفض */
class ArticleDurationClaimTest extends TestCase
{
    private const MESSAGE = 'Confirm the exact duration or family-suitability statement in business facts before publishing.';

    private function check(string $title, string $facts): ?string
    {
        return ArticleGenerator::validateBriefOutput(
            ['title' => $title, 'meta_description' => '', 'content' => 'Plan your trip with a local Egyptologist.', 'faqs' => []],
            ['confirmed_facts' => ['business_facts' => $facts]]
        );
    }

    public function testDurationStatedInFactsPasses(): void
    {
        $this->assertNull($this->check('4-Day Egypt Private Tours Itinerary: Cairo to Aswan', 'We run 4 days private tours from Cairo to Aswan.'));
        $this->assertNull($this->check('Nile cruise: 3 nights from Luxor', 'Our Nile cruise is 3 nights from Luxor to Aswan.'));
    }

    public function testDurationMissingFromFactsIsRejected(): void
    {
        $this->assertSame(self::MESSAGE, $this->check('5-Day Egypt Private Tours Itinerary', 'We run 4 days private tours from Cairo to Aswan.'));
        $this->assertSame(self::MESSAGE, $this->check('A 14 day tour of Egypt', 'We run 4 days private tours.'));
    }

    public function testFamilyClaimStillNeedsTheFullSentence(): void
    {
        $this->assertSame(self::MESSAGE, $this->check('Our tours are perfect for families', 'We run 4 days private tours.'));
    }
}
