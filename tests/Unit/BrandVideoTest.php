<?php

use PHPUnit\Framework\TestCase;

/** Videos must come out clean and on-brand for any company: guardrails in the prompt, and a free logo copy that stays in safe areas. */
final class BrandVideoTest extends TestCase
{
    private function brand(array $profile, array $palette): BrandProfile
    {
        return new BrandProfile(['id' => 0, 'profile_json' => json_encode($profile), 'palette_json' => json_encode($palette)]);
    }

    public function testEveryVideoGetsTheCleanShotGuardrailsEvenWithoutABrand(): void
    {
        $p = BrandPromptBuilder::videoBrief(null);
        $this->assertStringContainsString('One continuous shot', $p);
        $this->assertStringContainsString('No text, captions, subtitles, logos, brand names', $p);
        $this->assertStringContainsString('Avoid:', $p);
        $this->assertStringNotContainsString('Made for a', $p);
    }

    public function testBrandIdentityShapesTheVideoPrompt(): void
    {
        $p = BrandPromptBuilder::videoBrief($this->brand(
            ['business_type' => 'boutique desert camp', 'visual_style' => 'warm editorial, earthy tones', 'mood_keywords' => ['calm', 'authentic'], 'do' => ['show real hospitality'], 'avoid' => ['crowded beaches']],
            ['primary' => '#14284b', 'accent' => '#d9ae55']
        ));
        $this->assertStringContainsString('Made for a boutique desert camp.', $p);
        $this->assertStringContainsString('Art direction: warm editorial, earthy tones.', $p);
        $this->assertStringContainsString('Mood: calm, authentic.', $p);
        $this->assertStringContainsString('brand palette (', $p);
        $this->assertStringContainsString('Do: show real hospitality.', $p);
        $this->assertStringContainsString('crowded beaches', $p);
        $this->assertStringNotContainsString('boutique desert camp\' ', $p);
    }

    public function testBrandNameNeverEntersThePrompt(): void
    {
        $p = BrandPromptBuilder::videoBrief($this->brand(['business_type' => 'travel agency', 'name' => 'ACME TOURS'], []));
        $this->assertStringNotContainsString('ACME TOURS', $p, 'models draw brand names as text inside the scene');
    }

    public function testOverlayIsATransparentPngWithTheLogoInThePortraitSafeZone(): void
    {
        $dir = ROOT_PATH . '/public_html/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $logo = imagecreatetruecolor(300, 100);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 200, 30, 60));
        $name = '/uploads/_test_logo_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($logo, ROOT_PATH . '/public_html' . $name);
        imagedestroy($logo);
        try {
            $png = BrandVideoFinisher::overlayPng(720, 1280, $name);
            $this->assertNotNull($png);
            $im = imagecreatefromstring($png);
            $this->assertSame([720, 1280], [imagesx($im), imagesy($im)]);
            $alphaAt = fn (int $x, int $y) => (imagecolorat($im, $x, $y) >> 24) & 127;
            $this->assertSame(127, $alphaAt(360, 40), 'the top (platform UI zone) stays fully transparent');
            $this->assertSame(127, $alphaAt(360, 1200), 'the bottom (caption / buttons zone) stays fully transparent');
            $found = false;
            for ($y = 700; $y < 900 && !$found; $y += 4) {
                for ($x = 40; $x < 260; $x += 4) {
                    $found = $found || $alphaAt($x, $y) < 100;
                }
            }
            $this->assertTrue($found, 'the logo badge sits in the lower-left safe area');
            $land = imagecreatefromstring((string) BrandVideoFinisher::overlayPng(1280, 720, $name));
            $this->assertSame([1280, 720], [imagesx($land), imagesy($land)]);
        } finally {
            @unlink(ROOT_PATH . '/public_html' . $name);
        }
    }

    public function testOverlayRefusesMissingLogosAndPathsOutsideThePublicFolder(): void
    {
        $this->assertNull(BrandVideoFinisher::overlayPng(720, 1280, null));
        $this->assertNull(BrandVideoFinisher::overlayPng(720, 1280, '/uploads/does-not-exist.png'));
        $this->assertNull(BrandVideoFinisher::overlayPng(720, 1280, '/../composer.json'), 'no reading outside public_html');
        $this->assertNull(BrandVideoFinisher::overlayPng(10, 10, '/uploads/x.png'));
    }
}
