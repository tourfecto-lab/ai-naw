<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BusinessHub/BusinessHubService.php';
require_once __DIR__ . '/../../app/Services/BusinessHub/CompetitorBlueprintService.php';

/**
 * Business Hub: الحقائق المؤكدة للشركة اللي بتتشارك بين الخدمات (تحقق المدخلات، مطابقة النسخة)،
 * وحماية فحص المنافسين (روابط عامة بس، ما بتتحولش لحقائق عميل). دوال نقية بدون DB.
 */
final class BusinessHubTest extends TestCase
{
    private function validInput(array $override = []): array
    {
        return array_merge([
            'confirmed' => true,
            'business_name' => 'Nile Tours',
            'business_facts' => 'Licensed operator in Luxor since 2010',
            'audience' => 'Families from Europe',
            'goal' => 'More direct bookings',
            'offers' => '',
            'cta' => 'Book now',
            'phone' => '+20 100 000 0000',
            'email' => 'info@example.com',
            'logo_url' => 'https://example.com/logo.png',
            'language' => 'en',
            'timezone' => 'Africa/Cairo',
        ], $override);
    }

    // ---------- BusinessHubService::validate ----------

    public function testValidInputReturnsTrimmedFacts(): void
    {
        $facts = BusinessHubService::validate($this->validInput(['business_name' => '  Nile Tours  ']));

        $this->assertSame('Nile Tours', $facts['business_name']);
        $this->assertSame('Africa/Cairo', $facts['timezone']);
        $this->assertSame('en', $facts['language']);
    }

    public function testFactsMustBeExplicitlyConfirmed(): void
    {
        foreach ([false, 'true', 1, null] as $value) {
            try {
                BusinessHubService::validate($this->validInput(['confirmed' => $value]));
                $this->fail('كان لازم يرفض لو confirmed مش true صريحة');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Confirm', $e->getMessage());
            }
        }
    }

    /** @dataProvider badInputs */
    public function testInvalidFieldsAreRejected(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        BusinessHubService::validate($this->validInput($override));
    }

    public static function badInputs(): array
    {
        return [
            'name too short' => [['business_name' => 'ab']],
            'name too long' => [['business_name' => str_repeat('a', 151)]],
            'name not a string' => [['business_name' => ['x']]],
            'control character' => [['goal' => "bad\x07goal"]],
            'bad email' => [['email' => 'not-an-email']],
            'logo over http' => [['logo_url' => 'http://example.com/logo.png']],
            'logo with credentials' => [['logo_url' => 'https://user:pass@example.com/logo.png']],
            'logo not a url' => [['logo_url' => 'javascript:alert(1)']],
            'bad phone' => [['phone' => 'call me']],
            'unsupported language' => [['language' => 'fr']],
            'unknown timezone' => [['timezone' => 'Mars/Base']],
        ];
    }

    public function testOptionalFieldsMayBeEmpty(): void
    {
        $facts = BusinessHubService::validate($this->validInput(['phone' => '', 'email' => '', 'logo_url' => '', 'offers' => '']));

        $this->assertSame('', $facts['phone']);
        $this->assertSame('', $facts['email']);
    }

    // ---------- siteKey / matchesProfile ----------

    public function testSiteKeyNormalizesSchemeCaseAndTrailingSlash(): void
    {
        $this->assertSame(BusinessHubService::siteKey('https://Example.com/'), BusinessHubService::siteKey('example.com'));
        $this->assertSame(BusinessHubService::siteKey('http://example.com/tours/'), BusinessHubService::siteKey('https://example.com/tours'));
        $this->assertNotSame(BusinessHubService::siteKey('example.com'), BusinessHubService::siteKey('other.com'));
        $this->assertNotSame(BusinessHubService::siteKey('example.com:8080'), BusinessHubService::siteKey('example.com'));
    }

    private function profile(array $override = []): array
    {
        $facts = ['business_name' => 'Nile Tours', 'source_site_url' => 'https://example.com'];
        return array_merge(['version' => 3, 'facts' => $facts], $override);
    }

    private function snapshot(array $profile): array
    {
        return ['brief' => [
            'source_site_url' => 'https://example.com',
            'shared_profile' => ['version' => $profile['version'], 'facts' => $profile['facts']],
        ]];
    }

    public function testSnapshotMatchesTheCurrentProfile(): void
    {
        $profile = $this->profile();

        $this->assertTrue(BusinessHubService::matchesProfile($this->snapshot($profile), $profile, 'https://example.com/'));
    }

    public function testSnapshotDoesNotMatchWhenVersionFactsOrSiteChange(): void
    {
        $profile = $this->profile();
        $snapshot = $this->snapshot($profile);

        $newVersion = $this->profile(['version' => 4]);
        $this->assertFalse(BusinessHubService::matchesProfile($snapshot, $newVersion, 'https://example.com'));

        $editedFacts = $this->profile();
        $editedFacts['facts']['business_name'] = 'Changed';
        $this->assertFalse(BusinessHubService::matchesProfile($snapshot, $editedFacts, 'https://example.com'));

        $this->assertFalse(BusinessHubService::matchesProfile($snapshot, $profile, 'https://other.com'));
    }

    public function testSnapshotNeverMatchesMissingOrStaleProfiles(): void
    {
        $profile = $this->profile();
        $snapshot = $this->snapshot($profile);

        $this->assertFalse(BusinessHubService::matchesProfile($snapshot, null, 'https://example.com'));
        $this->assertFalse(BusinessHubService::matchesProfile($snapshot, $profile + ['needs_reconfirmation' => true], 'https://example.com'));
        $this->assertFalse(BusinessHubService::matchesProfile(['brief' => []], $profile, 'https://example.com'));
    }

    // ---------- CompetitorBlueprintService ----------

    public function testPublicUrlAddsHttpsAndRejectsRiskyUrls(): void
    {
        $this->assertSame('https://competitor.com', CompetitorBlueprintService::publicUrl(' competitor.com '));
        $this->assertSame('http://competitor.com/tours', CompetitorBlueprintService::publicUrl('http://competitor.com/tours'));

        foreach ([
            'https://user:pass@competitor.com',
            'https://competitor.com/?token=1',
            'https://competitor.com/#frag',
            'ftp://competitor.com',
            'https://competitor.com/' . str_repeat('a', 500),
        ] as $bad) {
            try {
                CompetitorBlueprintService::publicUrl($bad);
                $this->fail('كان لازم يرفض: ' . substr($bad, 0, 40));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDemoPagesAreRecognised(): void
    {
        $this->assertTrue(CompetitorBlueprintService::demo(['title' => 'Coming Soon']));
        $this->assertTrue(CompetitorBlueprintService::demo(['text' => 'contact travelerwp@gmail.com']));
        $this->assertFalse(CompetitorBlueprintService::demo(['title' => 'Nile cruises', 'text' => 'Real content']));
    }

    public function testCategoriesAreCollectedFromSameHostLinksOnly(): void
    {
        $page = [
            'final_url' => 'https://competitor.com/',
            'title' => 'Home',
            'links' => [
                ['url' => 'https://competitor.com/tours/luxor', 'title' => 'Luxor tour'],
                ['url' => 'https://competitor.com/faq', 'title' => 'FAQ'],
                ['url' => 'https://evil.com/tours', 'title' => 'Tours elsewhere'],
                ['url' => 'javascript:void(0)', 'title' => 'tour'],
            ],
        ];

        $found = CompetitorBlueprintService::categories([$page]);

        $this->assertSame(['https://competitor.com/tours/luxor'], $found['tours']);
        $this->assertSame(['https://competitor.com/faq'], $found['faq']);
        $this->assertArrayNotHasKey('cruises', $found);
    }

    public function testCategoriesAreCappedAtFourUniqueUrls(): void
    {
        $links = [];
        for ($i = 1; $i <= 8; $i++) {
            $links[] = ['url' => "https://competitor.com/tour-$i", 'title' => 'tour'];
            $links[] = ['url' => "https://competitor.com/tour-$i", 'title' => 'tour']; // مكرر
        }

        $found = CompetitorBlueprintService::categories([['final_url' => 'https://competitor.com/', 'links' => $links]]);

        $this->assertCount(4, $found['tours']);
        $this->assertSame(4, count(array_unique($found['tours'])));
    }

    public function testRecommendationsSkipOptionalSectionsThatWereNotObserved(): void
    {
        $rows = CompetitorBlueprintService::recommendations([], [], false);
        $keys = array_column($rows, 'key');

        $this->assertContains('home', $keys);
        $this->assertContains('policies', $keys);
        foreach (['packages', 'cruises', 'transfers'] as $optional) {
            $this->assertNotContains($optional, $keys);
        }

        $withCruises = array_column(CompetitorBlueprintService::recommendations(['cruises' => ['https://x.com/nile']], [], false), 'key');
        $this->assertContains('cruises', $withCruises);
    }

    public function testRecommendationsCarryBothLanguages(): void
    {
        foreach (CompetitorBlueprintService::recommendations([], [], false) as $row) {
            $this->assertNotSame('', $row['title_ar']);
            $this->assertNotSame('', $row['title_en']);
            $this->assertNotSame('', $row['instructions_ar']);
            $this->assertNotSame('', $row['instructions_en']);
        }
    }
}
