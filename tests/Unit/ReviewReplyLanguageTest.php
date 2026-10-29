<?php

/** لغة الرد على التقييم: تقييم نجوم من غير كلام وصاحبه اسمه عربي بيترد عليه بالعربي */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Reputation/ReviewReplyExpert.php';

final class ReviewReplyLanguageTest extends TestCase
{
    public function testStarsOnlyReviewFromAnArabicNameGetsAnArabicReply(): void
    {
        $this->assertSame('ar', ReviewReplyExpert::language('', 'en', 'خالد محمد'));
        $this->assertSame('en', ReviewReplyExpert::language('', 'en', 'John Smith'));
        $this->assertSame('de', ReviewReplyExpert::language('', 'de', 'Hans'));
        $this->assertSame('en', ReviewReplyExpert::language(''));
    }

    public function testWrittenTextStillDecidesTheLanguage(): void
    {
        $this->assertSame('en', ReviewReplyExpert::language('The guide was late and the bus was dirty', 'en', 'خالد'));
        $this->assertSame('ar', ReviewReplyExpert::language('الرحلة كانت متأخرة', 'en', 'John'));
    }

    public function testThePromptAsksForArabicForAStarsOnlyArabicReviewer(): void
    {
        $p = ReviewReplyExpert::prompt(['review_text' => '', 'rating' => 2, 'reviewer_name' => 'خالد', 'review_language' => 'en', 'platform' => 'google_business'], ['name' => 'Nile Tours']);
        $this->assertStringContainsString(ReviewReplyExpert::languageName('ar'), $p);
    }
}
