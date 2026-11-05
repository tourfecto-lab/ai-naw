<?php

use PHPUnit\Framework\TestCase;

/** Scorecard, honest SEO assessment, send priority, and the two WhatsApp messages built from them. */
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
            'site' => ['ok' => true, 'https' => true, 'viewport' => true, 'load_ms' => 900, 'social' => ['facebook' => 'nileTours'], 'title' => 'Nile Tours - private tours in Cairo', 'meta_description' => true, 'h1' => true,
                'schema' => ['TravelAgency'], 'sitemap' => true, 'blog' => true, 'images' => 20, 'images_no_alt' => 2],
            'audit' => [
                'google' => ['checked' => true, 'found' => true, 'rating' => 4.7, 'reviews' => 120, 'hours' => false],
                'bench' => ['median_reviews' => 340],
                'score' => ['total' => 78, 'pillars' => ['website' => 70]],
                'gaps' => [['id' => 'no_booking', 'pillar' => 'booking', 'track' => 'direct_booking', 'sev' => 6, 'vars' => ['domain' => 'nile.test']], ['id' => 'no_chat', 'pillar' => 'communication', 'track' => 'chat', 'sev' => 7, 'vars' => ['domain' => 'nile.test']]],
            ],
        ];
    }

    // ---------------------------------------------------------------- scorecard

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
        $this->assertStringNotContainsString('مربوطة بموقعكم', $m, 'never claims a real link that is a placeholder');
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

    public function testWhatTheStaffMemberSawOnThePageIsCarriedInTheScorecard(): void
    {
        $lead = $this->taLead() + ['desk_social' => json_encode(['facebook' => 'stale', 'instagram' => 'bogus'])];
        $sc = OutreachAudit::scorecard($lead, $this->data());
        $this->assertSame('stale', $sc['facebook']['activity']);
        $this->assertNull($sc['instagram']['activity'], 'only the known values are accepted');
        $this->assertNull(OutreachAudit::scorecard($this->taLead(), $this->data())['facebook']['activity']);
    }

    // ---------------------------------------------------------------- honest SEO

    public function testSeoIsGoodWhenTheBasicsAreThere(): void
    {
        $a = OutreachAudit::seoAssessment($this->data());
        $this->assertSame('good', $a['state']);
        $this->assertSame([], $a['fails']);
    }

    public function testSeoPartialNamesExactlyWhatIsMissing(): void
    {
        $d = $this->data();
        $d['site']['schema'] = [];
        $d['site']['blog'] = false;
        $d['site']['sitemap'] = false;
        $a = OutreachAudit::seoAssessment($d);
        $this->assertSame('partial', $a['state']);
        $this->assertEqualsCanonicalizing(['schema', 'blog', 'sitemap'], $a['fails']);
        $this->assertContains('title', $a['passes']);
    }

    public function testSeoIsMissingOnlyWhenMostBasicsFail(): void
    {
        $bare = ['site' => ['ok' => true, 'title' => 'Home', 'meta_description' => false, 'h1' => false, 'schema' => [], 'sitemap' => false, 'blog' => false, 'images' => 20, 'images_no_alt' => 20]];
        $a = OutreachAudit::seoAssessment($bare);
        $this->assertSame('missing', $a['state']);
        $this->assertGreaterThanOrEqual(5, count($a['fails']));
    }

    public function testSeoIsUnknownWhenTheSiteWasNotChecked(): void
    {
        $this->assertSame('unknown', OutreachAudit::seoAssessment([])['state']);
        $this->assertSame('unknown', OutreachAudit::seoAssessment(['site' => ['ok' => false]])['state']);
        $d = $this->data();
        unset($d['site']['sitemap']);
        $this->assertNotContains('sitemap', OutreachAudit::seoAssessment($d)['fails'], 'a check that was not measured is neither a pass nor a fail');
    }

    // ---------------------------------------------------------------- priority

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

    // ---------------------------------------------------------------- message 1: the real findings

    private function ctx(array $over = []): array
    {
        $data = $this->data();
        $d = $data;
        $d['site']['schema'] = [];
        $d['site']['blog'] = false;
        return $over + ['lang' => 'ar', 'company' => 'Nile Tours', 'name' => 'سارة', 'title' => 'Tourfecto', 'scorecard' => OutreachAudit::scorecard($this->taLead(), $data),
            'seo' => OutreachAudit::seoAssessment($d), 'gaps' => $data['audit']['gaps'], 'report_url' => 'https://tourfecto.pro/p/r/abc?s=1',
            'signup_url' => 'https://tourfecto.pro/p/c/abc/1', 'trial' => 'جرّبوا Tourfecto 3 أيام مجانًا، من غير كارت'];
    }

    public function testMessageIsShortAndBuiltOnlyFromRealFindings(): void
    {
        $m = OutreachNeeds::deskMessage($this->ctx());
        $this->assertStringContainsString('Nile Tours', $m);
        $this->assertStringContainsString('مفيهوش حجز ودفع أونلاين', $m, 'a real gap from the audit');
        $this->assertStringContainsString('مفيهوش واتساب ولا شات', $m);
        $this->assertStringContainsString('السيو:* عندكم أساسيات، بس ناقص', $m, 'partial SEO is described as partial, never as missing');
        $this->assertStringContainsString('بيانات منظّمة (Schema)', $m);
        $this->assertStringContainsString('مدونة ومقالات', $m);
        $this->assertStringContainsString('4.7', $m);
        $this->assertStringContainsString('مواعيد العمل مش مكتوبة', $m);
        $this->assertStringContainsString('فيسبوك مربوطة بموقعكم', $m);
        $this->assertStringContainsString('إنستجرام مش مربوطة بموقعكم', $m);
        $this->assertStringContainsString('السيو هو اللي بيخلّي المسافر يلاقيكم', $m, 'why SEO matters, in one sentence');
        $this->assertStringContainsString('بيفحص الموقع ويجهّز إصلاح كل نقطة ناقصة', $m, 'the SEO service answers the listed gaps');
        $this->assertStringContainsString('https://tourfecto.pro/p/r/abc?s=1', $m);
        $this->assertStringContainsString('جرّبوا Tourfecto 3 أيام مجانًا', $m);
        $this->assertStringContainsString("سارة\nTourfecto", $m);
        $this->assertLessThan(1100, mb_strlen($m), 'short enough to be read');
        $this->assertStringNotContainsString('كل أسبوع', $m, 'no promise about weekly articles');
    }

    public function testTripadvisorLineUsesTheImportedRatingRankingAndItsRealProblems(): void
    {
        $d = $this->data(['ta' => ['ranking' => 'رقم 267 من 2,709 رحلات وأنشطة في القاهرة', 'award' => true, 'negative' => 60, 'photos' => 5, 'url' => 'https://www.tripadvisor.com/Attraction_Review-x']]);
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => OutreachAudit::scorecard($this->taLead(), $d)]));
        $this->assertStringContainsString('تريب أدفايزر:* ⭐ 4.9 (450 تقييم)', $m);
        $this->assertStringContainsString('رقم 267 من 2,709', $m);
        $this->assertStringContainsString('🏆', $m);
        $this->assertStringContainsString('صور قليلة (5)', $m, 'a real gap from the listing');
        $this->assertStringContainsString('تقييمات سلبية محتاجة رد', $m, '60 of 450 reviews are 1-2 stars');

        $fine = $this->data(['ta' => ['ranking' => '', 'award' => false, 'negative' => 2, 'photos' => 200]]);
        $ok = OutreachNeeds::deskMessage($this->ctx(['scorecard' => OutreachAudit::scorecard($this->taLead(), $fine)]));
        $this->assertStringNotContainsString('صور قليلة', $ok);
        $this->assertStringNotContainsString('سلبية', $ok);
    }

    public function testSeoIsNeverClaimedWhenItIsGoodOrUnknown(): void
    {
        foreach ([['state' => 'good', 'fails' => ['blog'], 'passes' => [], 'known' => 6], ['state' => 'unknown', 'fails' => [], 'passes' => [], 'known' => 0]] as $seo) {
            $m = OutreachNeeds::deskMessage($this->ctx(['seo' => $seo]));
            $this->assertStringNotContainsString('🔎', $m, 'no SEO line when SEO is fine or not measured: ' . $seo['state']);
            $this->assertStringNotContainsString('السيو هو اللي', $m);
        }
        $missing = OutreachNeeds::deskMessage($this->ctx(['seo' => ['state' => 'missing', 'fails' => ['title', 'description', 'h1', 'schema'], 'passes' => [], 'known' => 6]]));
        $this->assertStringContainsString('شبه معدوم', $missing);
        $this->assertStringContainsString('عنوان صفحة واضح', $missing);
        $this->assertStringNotContainsString('بيانات منظّمة', $missing, 'only the first three missing points, the fourth is left out to keep it short');
    }

    public function testStaffObservationAboutThePostsIsUsedAndNothingIsInventedWithoutIt(): void
    {
        $stale = OutreachAudit::scorecard($this->taLead() + ['desk_social' => json_encode(['facebook' => 'stale', 'instagram' => 'empty'])], $this->data());
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => $stale, 'gaps' => [], 'seo' => ['state' => 'good', 'fails' => [], 'passes' => [], 'known' => 6]]));
        $this->assertStringContainsString('فيسبوك آخر منشور قديم', $m);
        $this->assertStringContainsString('إنستجرام مفيهاش منشورات', $m);
        $this->assertStringContainsString('السوشيال: بوستات وصور وفيديوهات', $m, 'the social service is offered when the pages are weak');

        $linkedOnly = OutreachNeeds::deskMessage($this->ctx(['gaps' => [], 'seo' => ['state' => 'good', 'fails' => [], 'passes' => [], 'known' => 6]]));
        $this->assertStringNotContainsString('نشيطة', $linkedOnly, 'without a staff check we never claim a page is active');
        $this->assertStringNotContainsString('آخر منشور', $linkedOnly);
        $active = OutreachAudit::scorecard($this->taLead() + ['desk_social' => json_encode(['facebook' => 'active', 'instagram' => 'active'])], $this->data());
        $ok = OutreachNeeds::deskMessage($this->ctx(['scorecard' => $active, 'gaps' => [], 'seo' => ['state' => 'good', 'fails' => [], 'passes' => [], 'known' => 6]]));
        $this->assertStringNotContainsString('السوشيال: بوستات', $ok, 'active pages are complimented, not sold a service');
    }

    public function testMessageDropsWhatWeDoNotKnow(): void
    {
        $d = $this->data();
        unset($d['site'], $d['audit']['google'], $d['ta']);
        $lead = ['rating' => null, 'reviews' => null, 'website' => 'https://nile.test'];
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => OutreachAudit::scorecard($lead, $d), 'seo' => OutreachAudit::seoAssessment($d), 'gaps' => [], 'trial' => '']));
        foreach (['فيسبوك', 'إنستجرام', 'جوجل ماب', '🔎', '🌐'] as $absent) {
            $this->assertStringNotContainsString($absent, $m, "$absent must not be guessed");
        }
        $this->assertStringContainsString('https://tourfecto.pro/p/r/abc?s=1', $m);
        $this->assertStringContainsString('ردّوا بكلمة «تمام»', $m, 'no trial text: a plain call to reply');
    }

    public function testAStrongCompanyIsComplimentedAndOfferedGrowthNotProblems(): void
    {
        $strong = ['google' => ['status' => 'found', 'rating' => 4.9, 'reviews' => 900, 'hours' => true, 'median' => 300], 'facebook' => ['status' => 'linked', 'activity' => 'active'], 'instagram' => ['status' => 'linked', 'activity' => 'active']];
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => $strong, 'gaps' => [], 'seo' => ['state' => 'good', 'fails' => [], 'passes' => [], 'known' => 6]]));
        $this->assertStringContainsString('ملف قوي', $m);
        $this->assertStringContainsString('العقل الاستراتيجي', $m);
        $this->assertStringNotContainsString('وفي Tourfecto بنحلّها', $m);
    }

    public function testMessageIsAvailableInEnglishAndTurkish(): void
    {
        $en = OutreachNeeds::deskMessage($this->ctx(['lang' => 'en', 'trial' => 'Try Tourfecto free for 3 days, no card needed']));
        $this->assertStringContainsString('Hello Nile Tours team', $en);
        $this->assertStringContainsString('Google Maps', $en);
        $this->assertStringContainsString('you have the basics, but missing', $en);
        $this->assertStringContainsString('Try Tourfecto free for 3 days', $en);
        $tr = OutreachNeeds::deskMessage($this->ctx(['lang' => 'tr', 'trial' => '']));
        $this->assertStringContainsString('Merhaba Nile Tours ekibi', $tr);
        $this->assertStringContainsString('Google Haritalar', $tr);
        $this->assertStringContainsString('temeller var ama eksik', $tr);
    }

    // ---------------------------------------------------------------- message 2: the platform services

    public function testServicesMessageExplainsThePlatformAndCarriesTheirSignupLink(): void
    {
        $m = OutreachNeeds::deskServicesMessage($this->ctx());
        $this->assertStringContainsString('العقل الاستراتيجي', $m);
        $this->assertStringContainsString('إنتم اللي بتوافقوا', $m, 'the brain proposes, they approve');
        // الخدمات اللي شغّالة دلوقتي بس: السمعة والسوشيال بيعتمدوا على مراجعة Meta وجوجل فمش في الرسالة
        foreach (['السيو', 'موقع وحجز', 'مراقبة المنافسين', 'CRM', 'Starter', 'مكالمة 15 دقيقة'] as $service) {
            $this->assertStringContainsString($service, $m);
        }
        $this->assertStringContainsString('https://tourfecto.pro/p/c/abc/1', $m);
        $this->assertStringContainsString('جرّبوا Tourfecto 3 أيام مجانًا', $m);
        $this->assertLessThan(1000, mb_strlen($m));
        $this->assertStringContainsString('Strategic Brain', OutreachNeeds::deskServicesMessage($this->ctx(['lang' => 'en'])));
        $this->assertStringContainsString('Stratejik Beyin', OutreachNeeds::deskServicesMessage($this->ctx(['lang' => 'tr', 'trial' => ''])));
    }

    // ---------------------------------------------------------------- public report card

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
        $this->assertStringContainsString('Your presence on the platforms', $this->platformCard($sc, 'en'));
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

    public function testMessageOpensWithAWarmGreetingAndPraiseFromRealReviewsOnlyWhenThereAreGapsToFix(): void
    {
        $sc = ['tripadvisor' => ['status' => 'found', 'rating' => 4.9, 'reviews' => 2623], 'google' => ['status' => 'found', 'rating' => 4.4, 'reviews' => 37, 'hours' => true, 'median' => 400]];
        $gaps = [['id' => 'no_booking', 'vars' => []]];
        $m = OutreachNeeds::deskMessage($this->ctx(['scorecard' => $sc, 'gaps' => $gaps, 'seo' => ['state' => 'good', 'fails' => [], 'passes' => [], 'known' => 6]]));
        $this->assertStringNotContainsString('السلام عليكم', $m);
        $this->assertStringNotContainsString('يا فريق', $m);
        $this->assertStringStartsWith('أهلًا بحضراتكم في', $m);
        $this->assertStringContainsString('⭐ 4.9 من 2,623 تقييم على تريب أدفايزر', $m);
        $seo = ['state' => 'good', 'fails' => [], 'passes' => [], 'known' => 6];
        $weak = ['tripadvisor' => ['status' => 'found', 'rating' => 4.1, 'reviews' => 2623]];
        $this->assertStringNotContainsString('ما شاء الله', OutreachNeeds::deskMessage($this->ctx(['scorecard' => $weak, 'gaps' => $gaps, 'seo' => $seo])), 'no praise without a strong rating');
        $strong = ['tripadvisor' => ['status' => 'found', 'rating' => 4.9, 'reviews' => 2623]];
        $this->assertStringNotContainsString('ما شاء الله', OutreachNeeds::deskMessage($this->ctx(['scorecard' => $strong, 'gaps' => [], 'seo' => $seo])), 'nothing to fix: the strong-company message is used instead');
    }
}
