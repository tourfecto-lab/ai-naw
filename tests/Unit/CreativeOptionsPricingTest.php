<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/CreativeStudio/GenerationOptions.php';
require_once __DIR__ . '/../../app/Services/CreativeStudio/CreativePricing.php';

/** Creative Studio: input validation before any provider call or charge. No DB or network is used. */
class CreativeOptionsPricingTest extends TestCase
{
    public function testNormalizeDefaults(): void
    {
        $o = GenerationOptions::normalize([]);
        $this->assertSame('auto', $o['lighting']);
        $this->assertSame('720p', $o['resolution']);
        $this->assertSame(0, $o['reference_id']);
        $this->assertSame('custom', $o['scene']);
        $this->assertArrayNotHasKey('brand_overlay', $o);
    }

    public function testVideoBrandOverlayDefaultsOnAndCanBeDisabled(): void
    {
        $this->assertTrue(GenerationOptions::normalize([], true)['brand_overlay']);
        $this->assertFalse(GenerationOptions::normalize(['brand_overlay' => '0'], true)['brand_overlay']);
    }

    /** @dataProvider invalidInputs */
    public function testNormalizeRejectsInvalidInput(array $input, bool $video): void
    {
        $this->expectException(InvalidArgumentException::class);
        GenerationOptions::normalize($input, $video);
    }

    public static function invalidInputs(): array
    {
        return [
            'subject too long' => [['subject' => str_repeat('a', 301)], false],
            'subject not string' => [['subject' => ['x']], false],
            'bad lighting' => [['lighting' => 'neon'], false],
            'bad camera' => [['camera' => 'drone'], true],
            'image ratio on video' => [['aspect_ratio' => '1:1'], true],
            'unknown ratio' => [['aspect_ratio' => '5:7'], false],
            'non numeric reference' => [['reference_id' => '1; DROP'], false],
            'reference too long' => [['reference_id' => '12345678901'], false],
            '1080p on image' => [['resolution' => '1080p'], false],
            'unknown resolution' => [['resolution' => '8k'], true],
            'bad scene' => [['scene' => 'beach'], true],
            'bad overlay' => [['brand_overlay' => 'yes'], true],
        ];
    }

    public function testImageAcceptsImageRatiosVideoOnlyWideAndTall(): void
    {
        $this->assertSame('4:5', GenerationOptions::normalize(['aspect_ratio' => '4:5'])['aspect_ratio']);
        $this->assertSame('9:16', GenerationOptions::normalize(['aspect_ratio' => '9:16'], true)['aspect_ratio']);
    }

    public function testRequiredTextNeedsEightCharacters(): void
    {
        $this->assertSame('hello world', GenerationOptions::text('  hello world ', 'x', 50, true));
        $this->expectException(InvalidArgumentException::class);
        GenerationOptions::text('short', 'x', 50, true);
    }

    public function testDirectionNeverPromisesPricesOrBranding(): void
    {
        $o = GenerationOptions::normalize(['subject' => 'Pyramids at dawn', 'lighting' => 'golden_hour', 'avoid' => 'crowds']);
        $d = GenerationOptions::direction($o);
        $this->assertStringContainsString('Pyramids at dawn', $d);
        $this->assertStringContainsString('golden-hour', $d);
        $this->assertStringContainsString('Avoid these elements: crowds.', $d);
        $this->assertStringContainsString('Do not invent prices', $d);
    }

    public function testVideoMovesAvoidListToNegativePrompt(): void
    {
        $o = GenerationOptions::normalize(['avoid' => 'crowds'], true);
        $this->assertStringNotContainsString('Avoid these elements', GenerationOptions::direction($o, true));
        $neg = GenerationOptions::videoNegativePrompt($o);
        $this->assertStringStartsWith('crowds, ', $neg);
        $this->assertStringContainsString('watermark', $neg);
    }

    /** @dataProvider invalidQuotes */
    public function testQuoteRejectsInvalidRequestsBeforeAnyChargeOrDbAccess(string $kind, array $options, int $count, int $duration, bool $storyboard): void
    {
        $this->expectException(InvalidArgumentException::class);
        CreativePricing::quote(1, $kind, $options, $count, $duration, $storyboard);
    }

    public static function invalidQuotes(): array
    {
        return [
            'unknown kind' => ['audio', [], 1, 8, false],
            'zero images' => ['image', [], 0, 8, false],
            'three images' => ['image', [], 3, 8, false],
            'two videos' => ['video', [], 2, 8, false],
            'storyboard on image' => ['image', [], 2, 8, true],
            'storyboard with 0 scenes' => ['video', [], 0, 8, true],
            'storyboard over max scenes' => ['video', [], CreativePricing::STORYBOARD_MAX_SCENES + 1, 8, true],
            'bad video duration' => ['video', [], 1, 5, false],
            'invalid option propagates' => ['image', ['resolution' => '4k'], 1, 8, false],
        ];
    }
}
