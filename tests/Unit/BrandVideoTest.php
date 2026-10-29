<?php

use PHPUnit\Framework\TestCase;

/** Videos must come out clean and on-brand for any company: guardrails in the prompt, and a free logo copy that stays in safe areas. */
final class BrandVideoTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', dirname(__DIR__, 2));
        }
    }

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

    public function testEndCardIsTheLogoCentredOnTheBrandColour(): void
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
            $im = imagecreatefromstring((string) BrandVideoFinisher::endCardPng(720, 1280, $name, ['primary' => '#14284b']));
            $this->assertSame([720, 1280], [imagesx($im), imagesy($im)]);
            $this->assertSame(0x14284b, imagecolorat($im, 5, 5) & 0xFFFFFF, 'background is the brand colour');
            $this->assertSame(200 << 16 | 30 << 8 | 60, imagecolorat($im, 360, 640) & 0xFFFFFF, 'the logo sits in the centre');
            $this->assertNull(BrandVideoFinisher::endCardPng(720, 1280, '/../composer.json'));
        } finally {
            @unlink(ROOT_PATH . '/public_html' . $name);
        }
        $this->assertSame([20, 40, 75], BrandVideoFinisher::brandColor([]));
        $this->assertSame([255, 0, 16], BrandVideoFinisher::brandColor(['primary' => 'nope', 'accent' => '#ff0010']));
    }

    public function testEndCardFilterAppendsTheCardAfterTheClip(): void
    {
        $f = VideoEditing::endCardFilter(720, 1280, 8.0, true);
        $this->assertStringContainsString('trim=duration=8', $f);
        $this->assertStringContainsString('concat=n=2:v=1:a=1[v][a]', $f);
        $this->assertStringContainsString('anullsrc', $f);
        $silent = VideoEditing::endCardFilter(720, 1280, 5.5, false);
        $this->assertStringContainsString('concat=n=2:v=1:a=0[v]', $silent);
        $this->assertStringNotContainsString('[a]', $silent);
    }

    public function testLogoCopyOptionDefaultsOnAndCanBeSwitchedOff(): void
    {
        $this->assertTrue(GenerationOptions::normalize([], true)['brand_overlay']);
        $this->assertFalse(GenerationOptions::normalize(['brand_overlay' => '0'], true)['brand_overlay']);
        $this->assertArrayNotHasKey('brand_overlay', GenerationOptions::normalize([], false));
        $this->expectException(InvalidArgumentException::class);
        GenerationOptions::normalize(['brand_overlay' => 'maybe'], true);
    }

    public function testVideoQaTakesTheWorstFrameAndFailsOpen(): void
    {
        $scores = [8, 2];
        $gate = new CreativeQualityGate(function () use (&$scores) {
            $s = array_shift($scores);
            return ['success' => true, 'data' => json_encode(['score' => $s, 'issues' => ['text in frame'], 'fix' => 'remove text'])];
        });
        $png = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $qa = new VideoQualityGate(fn () => $bytes, $gate);
        $r = $qa->review('x.mp4', 'a beach', 8.0);
        $this->assertTrue($r['checked']);
        $this->assertSame(2, $r['score']);
        $this->assertTrue($r['reject']);

        $none = (new VideoQualityGate(fn () => null, $gate))->review('x.mp4', 'a beach', 8.0);
        $this->assertFalse($none['checked']);
        $this->assertTrue($none['passed']);
    }
}
