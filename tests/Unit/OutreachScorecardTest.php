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

    public function testTemplatePlaceholderSocialLinksAreNotTheCompanyPage(): void
    {
        $d = $this->data();
        $d['site']['social'] = ['facebook' => 'facebook', 'instagram' => 'instagram'];
        $sc = OutreachAudit::scorecard($this->taLead(), $d);
        $this->assertSame('placeholder', $sc['facebook']['status'], 'facebook.com/facebook is the template default, not their page');
        $this->assertSame('placeholder', $sc['instagram']['status']);
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => $sc]));
        $this->assertStringNotContainsString('مربوط بموقعكم', $m, 'never claims a real link that is a placeholder');
        $this->assertStringContainsString('مش بيودّي لصفحتكم', $m);
        $d['site']['social'] = ['facebook' => 'https', 'instagram' => 'whatsapp'];
        $bad = OutreachAudit::scorecard($this->taLead(), $d);
        $this->assertSame(['placeholder', 'placeholder'], [$bad['facebook']['status'], $bad['instagram']['status']], 'a mis-read link and another brand account are not their pages');
        $d['site']['social'] = ['facebook' => 'profile.php'];
        $pp = OutreachAudit::scorecard($this->taLead(), $d);
        $this->assertSame('linked', $pp['facebook']['status'], 'a real profile whose id was cut off');
        $this->assertSame('', $pp['facebook']['url'], 'but never a link that opens the wrong page');
        foreach (['facebook', 'fb', 'sharer', 'share', 'instagram', 'explore', 'yourpage', 'whatsapp'] as $generic) {
            $this->assertFalse(OutreachAudit::socialHandleOk('facebook', $generic), $generic);
        }
        $this->assertTrue(OutreachAudit::socialHandleOk('facebook', 'NileDreamTours'));
        $this->assertTrue(OutreachAudit::socialHandleOk('facebook', 'pages/Nile-Tours/123456'));
    }

    public function testParserSkipsPlaceholderLinksAndFindsTheRealOnesAndTripadvisor(): void
    {
        $html = '<html><body><a href="https://www.facebook.com/facebook">f</a><a href="https://www.facebook.com/NileTours">real</a>'
            . '<a href="https://www.instagram.com/instagram">i</a><a href="https://www.instagram.com/nile.tours/">real</a>'
            . '<a href="https://www.tripadvisor.com/Attraction_Review-g294201-d123-Reviews-Nile_Tours-Cairo.html">ta</a></body></html>';
        $p = OutreachAudit::parsePage($html, 'https://nile.test/');
        $this->assertSame('NileTours', $p['social']['facebook']);
        $this->assertSame('nile.tours', $p['social']['instagram']);
        $this->assertStringStartsWith('Attraction_Review-g294201', $p['social']['tripadvisor']);
        $this->assertSame(2, $p['social_v']);

        $only = OutreachAudit::parsePage('<a href="https://www.facebook.com/facebook">x</a>', 'https://nile.test/');
        $this->assertArrayNotHasKey('facebook', $only['social'], 'only a placeholder: no real page');
    }

    public function testTripadvisorLinkFromTheWebsiteWhenThereIsNoImportedRating(): void
    {
        $lead = ['rating' => null, 'reviews' => null, 'website' => 'https://nile.test', 'maps_url' => ''];
        $site = fn (array $social, int $v) => ['site' => ['ok' => true, 'social' => $social, 'social_v' => $v], 'audit' => ['gaps' => []]];
        $linked = OutreachAudit::scorecard($lead, $site(['tripadvisor' => 'Attraction_Review-g1-d2-Reviews-X.html'], 2));
        $this->assertSame('linked', $linked['tripadvisor']['status']);
        $this->assertStringStartsWith('https://www.tripadvisor.com/Attraction_Review', $linked['tripadvisor']['url']);
        $this->assertSame('missing', OutreachAudit::scorecard($lead, $site([], 2))['tripadvisor']['status'], 'scanned with the Tripadvisor check and no link');
        $this->assertSame('unknown', OutreachAudit::scorecard($lead, $site([], 0))['tripadvisor']['status'], 'old scan never looked for it: unknown, not missing');
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => $linked]));
        $this->assertStringContainsString('تريب أدفايزر:* ✅ مربوط بموقعكم', $m);
        $this->assertStringNotContainsString('⭐ 4.9', $m, 'no rating is invented for a linked-only page');
    }

    public function testStaffNameIsARealNameNeverTheEmailPrefix(): void
    {
        $ctl = (new ReflectionClass(PlatformOutreachController::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(PlatformOutreachController::class, 'user');
        $prop->setAccessible(true);
        $name = new ReflectionMethod(PlatformOutreachController::class, 'staffName');
        $label = new ReflectionMethod(PlatformOutreachController::class, 'staffLabel');
        $name->setAccessible(true);
        $label->setAccessible(true);

        $prop->setValue($ctl, ['first_name' => 'Sara Ali', 'email' => 'sara@x.test']);
        $this->assertSame('Sara', $name->invoke($ctl));
        $prop->setValue($ctl, ['email' => 'tourfectonet@gmail.com']);
        $this->assertSame('', $name->invoke($ctl), 'no name: the message falls back to the configured sender name');
        $this->assertSame('tourfectonet', $label->invoke($ctl), 'the contact log still identifies who did it');
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
