<?php

use PHPUnit\Framework\TestCase;

/** The email offer line comes from the live trial settings: days from the config, "no card" only when no card is required. */
final class OutreachTrialOfferTest extends TestCase
{
    public function testArabicOfferUsesTheConfiguredDaysAndCardRule(): void
    {
        $this->assertSame('جرّبوا Tourfecto 3 أيام مجانًا، من غير كارت', PlatformOutreachService::trialOffer('ar', ['trial_days' => 3], false));
        $this->assertSame('جرّبوا Tourfecto يوم واحد مجانًا، من غير كارت', PlatformOutreachService::trialOffer('ar', ['trial_days' => 1], false));
        $this->assertSame('جرّبوا Tourfecto يومين مجانًا، من غير كارت', PlatformOutreachService::trialOffer('ar', ['trial_days' => 2], false));
        $this->assertSame('جرّبوا Tourfecto 14 يومًا مجانًا', PlatformOutreachService::trialOffer('ar', ['trial_days' => 14], true), 'a card is required, so it must not say no card');
    }

    public function testEnglishAndTurkish(): void
    {
        $this->assertSame('Try Tourfecto free for 7 days, no card needed', PlatformOutreachService::trialOffer('en', ['trial_days' => 7], false));
        $this->assertSame('Try Tourfecto free for 7 days', PlatformOutreachService::trialOffer('en', ['trial_days' => 7], true));
        $this->assertSame("Tourfecto'yu 7 gün ücretsiz deneyin, kart gerekmez", PlatformOutreachService::trialOffer('tr', ['trial_days' => 7], false));
    }

    public function testNoOfferWhenTheTrialIsNotConfigured(): void
    {
        $this->assertSame('', PlatformOutreachService::trialOffer('ar', ['trial_days' => 0], false));
        $this->assertSame('', PlatformOutreachService::trialOffer('ar', [], false));
    }

    public function testSignupLinkCarriesTheCompanySiteOnlyWhenItIsAValidDomain(): void
    {
        $this->assertSame(['site' => 'niledreamtours.com'], PlatformOutreachService::siteParam(['domain' => 'www.NileDreamTours.com']));
        $this->assertSame([], PlatformOutreachService::siteParam(['domain' => '']));
        $this->assertSame([], PlatformOutreachService::siteParam(['domain' => 'bad domain/../x']));
        $this->assertSame([], PlatformOutreachService::siteParam(['domain' => 'javascript:alert(1)']));
        $this->assertSame([], PlatformOutreachService::siteParam([]));
    }
}
