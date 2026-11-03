<?php

use PHPUnit\Framework\TestCase;

/** Scorecard (Google Maps, Tripadvisor, Facebook, Instagram, website), send priority and the WhatsApp message built from them. */
final class OutreachScorecardTest extends TestCase
{
    private function taLead(): array
    {
        return ['rating' => '4.90', 'reviews' => 450, 'website' => 'https://nile.test', 'maps_url' => 'https://www.tripadvisor.com/Attraction_Review-x', 'needs_json' => ''];
    }

    private function data(array $over = []): array
    {
        return $over + [
            'ta' => ['ranking' => '#12 of 420 Tours in Cairo', 'award' => true, 'negative' => 3, 'url' => 'https://www.tripadvisor.com/Attraction_Review-x'],
            'site' => ['ok' => true, 'https' => true, 'viewport' => true, 'load_ms' => 900, 'social' => ['facebook' => 'nileTours']],
            'audit' => [
                'google' => ['checked' => true, 'found' => true, 'rating' => 4.7, 'reviews' => 120, 'hours' => false],
                'bench' => ['median_reviews' => 340],
                'score' => ['total' => 78, 'pillars' => ['website' => 70]],
                'gaps' => [['id' => 'no_booking', 'pillar' => 'booking', 'track' => 'direct_booking', 'sev' => 6, 'vars' => ['domain' => 'nile.test']], ['id' => 'no_chat', 'pillar' => 'communication', 'track' => 'chat', 'sev' => 7, 'vars' => ['domain' => 'nile.test']]],
            ],
        ];
    }

    public function testScorecardReadsEachPlatformFromStoredData(): void
    {
        $sc = OutreachAudit::scorecard($this->taLead(), $this->data());
        $this->assertSame('found', $sc['tripadvisor']['status']);
        $this->assertSame(4.9, $sc['tripadvisor']['rating']);
        $this->assertSame(450, $sc['tripadvisor']['reviews']);
        $this->assertTrue($sc['tripadvisor']['award']);
        $this->assertSame('found', $sc['google']['status']);
        $this->assertSame(4.7, $sc['google']['rating']);
        $this->assertFalse($sc['google']['hours']);
        $this->assertSame(340, $sc['google']['median']);
        $this->assertSame('linked', $sc['facebook']['status']);
        $this->assertSame('https://www.facebook.com/nileTours', $sc['facebook']['url']);
        $this->assertSame('missing', $sc['instagram']['status'], 'the site was checked and has no Instagram link');
        $this->assertSame('ok', $sc['website']['status']);
        $this->assertSame(78, $sc['website']['score']);
    }

    public function testUnknownIsNotReportedAsMissing(): void
    {
        $d = $this->data();
        unset($d['site'], $d['audit']['google']);
        $sc = OutreachAudit::scorecard($this->taLead(), $d);
        $this->assertSame('unknown', $sc['facebook']['status'], 'site not scanned: we do not know the social links');
        $this->assertSame('unknown', $sc['instagram']['status']);
        $this->assertSame('unknown', $sc['google']['status']);
        $d['audit']['google'] = ['checked' => true, 'found' => false];
        $this->assertSame('missing', OutreachAudit::scorecard($this->taLead(), $d)['google']['status']);
        $d['site'] = ['ok' => false];
        $this->assertSame('unknown', OutreachAudit::scorecard($this->taLead(), $d)['facebook']['status'], 'site down: still unknown');
    }

    public function testGoogleSourcedLeadUsesItsRatingAsGoogleNotTripadvisor(): void
    {
        $lead = ['rating' => '4.5', 'reviews' => 80, 'website' => 'https://x.test', 'maps_url' => 'https://www.google.com/maps/place/x'];
        $sc = OutreachAudit::scorecard($lead, ['audit' => ['gaps' => []]]);
        $this->assertSame('found', $sc['google']['status']);
        $this->assertSame(4.5, $sc['google']['rating']);
        $this->assertSame('unknown', $sc['tripadvisor']['status']);
    }

    public function testPriorityFavoursRealNeedAndRealActivity(): void
    {
        $needy = OutreachAudit::priority($this->taLead(), $this->data());
        $this->assertGreaterThan(0, $needy['score']);
        $noGaps = OutreachAudit::priority($this->taLead(), ['audit' => ['gaps' => []]]);
        $this->assertSame(0, $noGaps['score'], 'no need means no priority however famous the company is');
        $this->assertSame('low', $noGaps['tier']);

        $busy = ['rating' => '4.9', 'reviews' => 2000];
        $quiet = ['rating' => '3.5', 'reviews' => 3];
        $gaps = ['audit' => ['gaps' => [['sev' => 8], ['sev' => 7], ['sev' => 6], ['sev' => 6]]]];
        $this->assertGreaterThan(OutreachAudit::priority($quiet, $gaps)['score'], OutreachAudit::priority($busy, $gaps)['score']);
        $this->assertSame('high', OutreachAudit::priority($busy, $gaps)['tier']);
        $this->assertLessThanOrEqual(100, OutreachAudit::priority($busy, ['audit' => ['gaps' => array_fill(0, 30, ['sev' => 10])]])['score']);
    }

    public function testPriorityFallsBackToStoredNeedsForOldAudits(): void
    {
        $lead = ['rating' => '4.8', 'reviews' => 500, 'needs_json' => json_encode([['track' => 'seo', 'score' => 120], ['track' => 'chat', 'score' => 70]])];
        $this->assertGreaterThan(40, OutreachAudit::priority($lead, [])['score']);
    }

    private function ctx(array $over = []): array
    {
        $sc = OutreachAudit::scorecard($this->taLead(), $this->data());
        return $over + ['lang' => 'ar', 'company' => 'Nile Tours', 'name' => 'سارة', 'title' => 'Tourfecto', 'score' => 78, 'scorecard' => $sc,
            'gap' => 'nile.test مافيهوش حجز ودفع أونلاين.', 'track' => 'direct_booking', 'report_url' => 'https://tourfecto.pro/p/r/abc?s=1', 'trial' => 'جرّبوا Tourfecto 3 أيام مجانًا، من غير كارت'];
    }

    public function testMessageCarriesTheScorecardTheReportLinkAndTheOffer(): void
    {
        $m = OutreachNeeds::deskMessage($this->ctx());
        $this->assertStringContainsString('Nile Tours', $m);
        $this->assertStringContainsString('78/100', $m);
        $this->assertStringContainsString('جوجل ماب', $m);
        $this->assertStringContainsString('4.7', $m);
        $this->assertStringContainsString('تريب أدفايزر', $m);
        $this->assertStringContainsString('4.9', $m);
        $this->assertStringContainsString('450', $m);
        $this->assertStringContainsString('فيسبوك:* ✅', $m);
        $this->assertStringContainsString('إنستجرام:* ❌', $m);
        $this->assertStringContainsString('nile.test مافيهوش حجز ودفع أونلاين.', $m);
        $this->assertStringContainsString('https://tourfecto.pro/p/r/abc?s=1', $m);
        $this->assertStringContainsString('جرّبوا Tourfecto 3 أيام مجانًا', $m);
        $this->assertStringContainsString("سارة\nTourfecto", $m);
        $this->assertLessThan(1500, mb_strlen($m), 'a WhatsApp message people actually read');
    }

    public function testMessageDropsWhatWeDoNotKnow(): void
    {
        $d = $this->data();
        unset($d['site'], $d['audit']['google'], $d['ta']);
        $lead = ['rating' => null, 'reviews' => null, 'website' => 'https://nile.test'];
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => OutreachAudit::scorecard($lead, $d), 'score' => null, 'trial' => '']));
        foreach (['فيسبوك', 'إنستجرام', 'تريب أدفايزر', 'جوجل ماب', '/100'] as $absent) {
            $this->assertStringNotContainsString($absent, $m, "$absent must not be guessed");
        }
        $this->assertStringContainsString('https://tourfecto.pro/p/r/abc?s=1', $m);
        $this->assertStringContainsString('لو حابين نبدأ', $m, 'no trial text: a plain call to reply');
    }

    private function platformCard(array $sc, string $lang = 'ar'): string
    {
        $m = new ReflectionMethod(PlatformOutreachController::class, 'platformCard');
        $m->setAccessible(true);
        $c = (new ReflectionClass(PlatformOutreachController::class))->newInstanceWithoutConstructor();
        return $m->invoke($c, $sc, $lang, static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
    }

    public function testPublicReportShowsThePlatformsWeKnowAndEscapesTheirText(): void
    {
        $sc = OutreachAudit::scorecard($this->taLead(), $this->data(['ta' => ['ranking' => '<script>alert(1)</script>', 'url' => '']]));
        $html = $this->platformCard($sc);
        $this->assertStringContainsString('حضوركم على المنصات', $html);
        $this->assertStringContainsString('Google Maps', $html);
        $this->assertStringContainsString('Tripadvisor', $html);
        $this->assertStringContainsString('facebook.com/nileTours', $html);
        $this->assertStringContainsString('Instagram', $html);
        $this->assertStringNotContainsString('<script>', $html, 'text coming from imported data is escaped');
        $this->assertStringContainsString('&lt;script&gt;', $html);

        $this->assertSame('', $this->platformCard([]), 'nothing known: no empty card');
        $unknown = OutreachAudit::scorecard(['rating' => null, 'reviews' => null, 'website' => ''], []);
        $this->assertStringNotContainsString('Facebook', $this->platformCard($unknown));
        $this->assertStringContainsString('Presence', $this->platformCard($sc, 'en') . 'Presence', 'english card renders');
        $this->assertStringContainsString('Your presence on the platforms', $this->platformCard($sc, 'en'));
    }

    public function testMessageIsAvailableInEnglishAndTurkish(): void
    {
        $en = OutreachNeeds::deskMessage($this->ctx(['lang' => 'en', 'trial' => 'Try Tourfecto free for 3 days, no card needed']));
        $this->assertStringContainsString('Hello Nile Tours team', $en);
        $this->assertStringContainsString('Google Maps', $en);
        $this->assertStringContainsString('Try Tourfecto free for 3 days', $en);
        $tr = OutreachNeeds::deskMessage($this->ctx(['lang' => 'tr', 'trial' => '']));
        $this->assertStringContainsString('Merhaba Nile Tours ekibi', $tr);
        $this->assertStringContainsString('Google Haritalar', $tr);
    }
}
