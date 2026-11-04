<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoBusinessBriefBuilder.php';

/** A fresh WordPress install must not be imported as "facts about the company" (seen on a new test site). */
final class SeoPlaceholderTextTest extends TestCase
{
    public function testDefaultWordPressTextIsPlaceholder(): void
    {
        $this->assertTrue(SeoBusinessBriefBuilder::isPlaceholderText('Skip to content example.test Uncategorized Hello world! e9773f / November 4, 2026 Welcome to WordPress. This is your first post.'));
        $this->assertTrue(SeoBusinessBriefBuilder::isPlaceholderText('Sample Page - Just another WordPress site'));
    }

    public function testRealCompanyTextIsNotPlaceholder(): void
    {
        $this->assertFalse(SeoBusinessBriefBuilder::isPlaceholderText('Private day tours from Cairo with licensed Egyptologists, hotel pick-up included.'));
    }
}
