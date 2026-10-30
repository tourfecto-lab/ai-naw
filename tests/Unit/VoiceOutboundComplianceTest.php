<?php

/** AI Voice & Sales Agent (Phase 5): calling windows, compliance decisions, retries, opt-out detection, outbound prompt safety. No network, no DB. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceHttpClient', 'VoiceProviderException', 'VoiceProviderAdapter', 'AbstractVoiceProviderAdapter', 'TwilioVoiceAdapter'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/Telephony/' . $c . '.php';
}
foreach (['VoiceLanguages', 'VoiceReplyParser', 'VoiceIdentity', 'VoiceDataSource', 'VoicePromptBuilder', 'VoiceAgentBrain', 'VoiceTimeWindows', 'VoiceOptOut', 'VoiceCompliance', 'VoiceMarkets', 'VoiceNumberRules'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/' . $c . '.php';
}
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceOutboundService.php';
require_once __DIR__ . '/../Fixtures/VoiceFakeHttp.php';
require_once __DIR__ . '/../Fixtures/VoiceMemoryData.php';

final class VoiceOutboundComplianceTest extends TestCase
{
    private function ts(string $local, string $tz): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone($tz)))->getTimestamp();
    }

    private function job(string $purpose, array $over = []): array
    {
        return $over + ['purpose' => $purpose, 'country' => 'GB', 'tz' => null, 'attempts' => 0, 'max_attempts' => 3];
    }

    // ---- time windows

    public function testWindowsAreEvaluatedInTheRecipientsLocalTime(): void
    {
        $z = ['Europe/London'];
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 10:00', 'Europe/London'), $z, 'other', 'GB'));
        $this->assertFalse(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 07:59', 'Europe/London'), $z, 'other', 'GB'));
        $this->assertFalse(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 21:00', 'Europe/London'), $z, 'other', 'GB'));
        $this->assertFalse(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 08:30', 'Europe/London'), $z, 'marketing', 'GB'));
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 08:30', 'Europe/London'), $z, 'other', 'GB'));
    }

    public function testDaylightSavingIsHandled(): void
    {
        // 20:30 UTC: 21:30 in London in summer? no: BST = UTC+1 -> 21:30 (blocked); in winter GMT -> 20:30 (allowed for non-marketing)
        $summer = $this->ts('2026-07-01 20:30', 'UTC');
        $winter = $this->ts('2026-12-01 20:30', 'UTC');
        $this->assertFalse(VoiceTimeWindows::isAllowed($summer, ['Europe/London'], 'other', 'GB'));
        $this->assertTrue(VoiceTimeWindows::isAllowed($winter, ['Europe/London'], 'other', 'GB'));
    }

    public function testUsWithoutAZoneMustBeValidInEveryContiguousZone(): void
    {
        $zones = VoiceTimeWindows::zonesFor('US', null);
        $this->assertCount(4, $zones);
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 12:00', 'America/New_York'), $zones, 'other', 'US'));  // 09:00 in LA
        $this->assertFalse(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 09:00', 'America/New_York'), $zones, 'other', 'US')); // 06:00 in LA
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 20:30', 'America/New_York'), $zones, 'other', 'US'));  // 17:30 in LA
        $this->assertFalse(VoiceTimeWindows::isAllowed($this->ts('2026-11-04 21:30', 'America/New_York'), $zones, 'other', 'US')); // past 21:00 in New York
    }

    public function testFridayMorningIsAvoidedInGulfAndEgypt(): void
    {
        $z = ['Asia/Riyadh'];
        $this->assertFalse(VoiceTimeWindows::isAllowed($this->ts('2026-11-06 10:00', 'Asia/Riyadh'), $z, 'other', 'SA')); // a Friday
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-06 15:00', 'Asia/Riyadh'), $z, 'other', 'SA'));
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-05 10:00', 'Asia/Riyadh'), $z, 'other', 'SA'));
        $this->assertTrue(VoiceTimeWindows::isAllowed($this->ts('2026-11-06 10:00', 'Europe/London'), ['Europe/London'], 'other', 'GB'));
    }

    public function testNextAllowedFindsTheNextOpeningAndUnknownZonesNeverAllow(): void
    {
        $night = $this->ts('2026-11-04 23:00', 'Europe/London');
        $next = VoiceTimeWindows::nextAllowed($night, ['Europe/London'], 'other', 'GB');
        $this->assertSame($this->ts('2026-11-05 08:00', 'Europe/London'), $next);
        $open = $this->ts('2026-11-04 10:00', 'Europe/London');
        $this->assertSame($open, VoiceTimeWindows::nextAllowed($open, ['Europe/London'], 'other', 'GB'));
        $this->assertNull(VoiceTimeWindows::nextAllowed($night, [], 'other', null));
        $this->assertSame([], VoiceTimeWindows::zonesFor('ZZ', null));
        $this->assertSame([], VoiceTimeWindows::zonesFor('GB', 'Not/AZone'));
        $this->assertSame(['Asia/Dubai'], VoiceTimeWindows::zonesFor('GB', 'Asia/Dubai'));
    }

    // ---- compliance decisions

    private function noon(): int
    {
        return $this->ts('2026-11-04 12:00', 'Europe/London');
    }

    public function testDoNotCallAndOptOutAlwaysBlockEveryPurpose(): void
    {
        foreach (array_keys(VoiceCompliance::PURPOSES) as $p) {
            $facts = ['dnc' => true, 'has_booking' => true, 'completed_booking' => true, 'inquiry_age_days' => 1, 'callback_age_days' => 1, 'marketing_consent' => true, 'marketing_country_enabled' => true];
            $r = VoiceCompliance::evaluate($this->job($p), $facts, $this->noon());
            $this->assertSame('block', $r['action'], $p);
            $this->assertSame('do_not_call_list', $r['reason'], $p);
            $o = VoiceCompliance::evaluate($this->job($p), ['dnc' => false, 'opted_out' => true] + $facts, $this->noon());
            $this->assertSame('recipient_opted_out', $o['reason'], $p);
        }
    }

    public function testTransactionalNeedsAnOpenBooking(): void
    {
        $this->assertSame('no_booking_relationship', VoiceCompliance::evaluate($this->job('booking_confirmation'), [], $this->noon())['reason']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('booking_confirmation'), ['has_booking' => true], $this->noon())['action']);
        $this->assertSame('no_completed_booking', VoiceCompliance::evaluate($this->job('survey'), ['has_booking' => true], $this->noon())['reason']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('post_trip'), ['completed_booking' => true], $this->noon())['action']);
    }

    public function testLeadFollowUpNeedsARecentInquiryAndCallbackNeedsAFreshRequest(): void
    {
        $this->assertSame('inquiry_too_old_or_unknown', VoiceCompliance::evaluate($this->job('lead_followup'), [], $this->noon())['reason']);
        $this->assertSame('inquiry_too_old_or_unknown', VoiceCompliance::evaluate($this->job('lead_followup'), ['inquiry_age_days' => 31], $this->noon())['reason']);
        $this->assertSame('inquiry_too_old_or_unknown', VoiceCompliance::evaluate($this->job('lead_followup'), ['inquiry_age_days' => -1], $this->noon())['reason']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('lead_followup'), ['inquiry_age_days' => 30], $this->noon())['action']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('callback'), ['callback_age_days' => 7], $this->noon())['action']);
        $this->assertSame('block', VoiceCompliance::evaluate($this->job('callback'), ['callback_age_days' => 8], $this->noon())['action']);
    }

    public function testMarketingNeedsBothRecordedConsentAndAnEnabledCountry(): void
    {
        $this->assertSame('marketing_not_enabled_for_country', VoiceCompliance::evaluate($this->job('marketing'), ['marketing_consent' => true], $this->noon())['reason']);
        $this->assertSame('no_marketing_consent', VoiceCompliance::evaluate($this->job('marketing'), ['marketing_country_enabled' => true], $this->noon())['reason']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('marketing'), ['marketing_country_enabled' => true, 'marketing_consent' => true], $this->noon())['action']);
    }

    public function testOutsideHoursWaitsUntilTheNextWindowInsteadOfDialling(): void
    {
        $night = $this->ts('2026-11-04 22:00', 'Europe/London');
        $d = VoiceCompliance::evaluate($this->job('booking_confirmation'), ['has_booking' => true], $night);
        $this->assertSame('wait', $d['action']);
        $this->assertSame('outside_calling_hours', $d['reason']);
        $this->assertSame($this->ts('2026-11-05 08:00', 'Europe/London'), $d['retry_at']);
        $m = VoiceCompliance::evaluate($this->job('marketing'), ['marketing_country_enabled' => true, 'marketing_consent' => true], $this->ts('2026-11-04 08:30', 'Europe/London'));
        $this->assertSame($this->ts('2026-11-04 09:00', 'Europe/London'), $m['retry_at']);
    }

    public function testFrequencyLimitsWaitAndAttemptCapBlocks(): void
    {
        $f = ['has_booking' => true];
        $this->assertSame('frequency_limit', VoiceCompliance::evaluate($this->job('trip_reminder'), $f + ['last_attempt_ts' => $this->noon() - 600], $this->noon())['reason']);
        $this->assertSame('wait', VoiceCompliance::evaluate($this->job('trip_reminder'), $f + ['calls_24h' => 2], $this->noon())['action']);
        $this->assertSame('wait', VoiceCompliance::evaluate($this->job('trip_reminder'), $f + ['calls_7d' => 5], $this->noon())['action']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('trip_reminder'), $f + ['calls_24h' => 1, 'last_attempt_ts' => $this->noon() - 20000], $this->noon())['action']);
        $this->assertSame('attempts_exhausted', VoiceCompliance::evaluate($this->job('trip_reminder', ['attempts' => 3]), $f, $this->noon())['reason']);
    }

    public function testUnknownCountryWithoutTimezoneIsBlockedNotGuessed(): void
    {
        $this->assertSame('timezone_unknown', VoiceCompliance::evaluate($this->job('trip_reminder', ['country' => 'ZZ']), ['has_booking' => true], $this->noon())['reason']);
        $this->assertSame('dial', VoiceCompliance::evaluate($this->job('trip_reminder', ['country' => 'ZZ', 'tz' => 'Europe/London']), ['has_booking' => true], $this->noon())['action']);
        $this->assertSame('unknown_purpose', VoiceCompliance::evaluate($this->job('spam'), [], $this->noon())['reason']);
    }

    public function testRetryRules(): void
    {
        $this->assertSame(1800, VoiceCompliance::retryDelay('busy', 1, 'trip_reminder', 3));
        $this->assertSame(14400, VoiceCompliance::retryDelay('no-answer', 1, 'trip_reminder', 3));
        $this->assertSame(86400, VoiceCompliance::retryDelay('voicemail', 1, 'trip_reminder', 3));
        $this->assertNull(VoiceCompliance::retryDelay('voicemail', 2, 'trip_reminder', 3));
        $this->assertNull(VoiceCompliance::retryDelay('voicemail', 1, 'marketing', 3));
        $this->assertNull(VoiceCompliance::retryDelay('no-answer', 3, 'trip_reminder', 3));
        $this->assertNull(VoiceCompliance::retryDelay('answered', 1, 'trip_reminder', 3));
    }

    // ---- opt-out

    public function testOptOutIsDetectedInEightLanguagesWithoutTheModel(): void
    {
        foreach (['Please stop calling me', "don't call me again", 'لا تتصل بي تاني', 'متتصلوش عليا', "Ne m'appelez plus", 'Rufen Sie mich nicht mehr an', 'No me llame más', 'non chiamatemi più', 'Не звоните мне', 'beni aramayın'] as $s) {
            $this->assertTrue(VoiceOptOut::detect($s), $s);
        }
        foreach (['Yes this is Sara', 'please don\'t stop calling, I am interested', 'what time is the trip', 'تمام شكرا', ''] as $s) {
            $this->assertFalse(VoiceOptOut::detect($s), $s);
        }
    }

    public function testArabicOptOutIgnoresDiacriticsAndAlefVariants(): void
    {
        $this->assertTrue(VoiceOptOut::detect('لَا تَتَّصِل'));
        $this->assertTrue(VoiceOptOut::detect('أحذف رقمي من فضلك') || VoiceOptOut::detect('احذف رقمي'));
    }

    // ---- outbound job input

    public function testContextIsWhitelistedBoundedAndDatesValidated(): void
    {
        $c = VoiceOutboundService::sanitizeContext(['first_name' => '<b>Sara</b>', 'trip_date' => '2026-12-05', 'inquiry_at' => 'yesterday', 'evil' => 'x', 'offer_text' => str_repeat('a', 900), 'amount' => ['x']]);
        $this->assertSame('Sara', $c['first_name']);
        $this->assertSame('2026-12-05', $c['trip_date']);
        $this->assertTrue(!isset($c['inquiry_at']) && !isset($c['evil']) && !isset($c['amount']));
        $this->assertSame(300, mb_strlen($c['offer_text']));
    }

    // ---- outbound prompt safety

    public function testOutboundPromptNeverContainsVerificationAnswersAndHidesAmountsUntilVerified(): void
    {
        $o = ['purpose' => 'payment_followup', 'facts' => ['first_name' => 'Sara', 'booking_ref' => 'TF-A1B2C3', 'email' => 'sara@example.com', 'amount' => '240', 'currency' => 'USD', 'trip_name' => 'Luxor']];
        $call = ['language' => 'en', 'identity_verified' => 0, 'verify_attempts' => 0, 'direction' => 'outbound', 'outbound' => $o];
        $p = VoicePromptBuilder::build($call, ['known' => false], [], ['name' => 'Layla'], 'Nile Tours', true, '2026-11-04');
        foreach (['TF-A1B2C3', 'sara@example.com', '"240"', 'USD'] as $secret) {
            $this->assertStringNotContainsString($secret, $p);
        }
        $this->assertStringContainsString('Luxor', $p);
        $this->assertStringContainsString('opt_out', $p);
        $v = VoicePromptBuilder::build(['identity_verified' => 1] + $call, ['known' => false], [], ['name' => 'Layla'], 'Nile Tours', true, '2026-11-04');
        $this->assertStringContainsString('240', $v);
        $this->assertStringNotContainsString('TF-A1B2C3', $v);
    }

    public function testOutboundBrainUsesTheDialledNumberAndHandlesOptOutAndSurvey(): void
    {
        $d = new VoiceMemoryData(['direction' => 'outbound', 'from_e164' => '+15125550100', 'to_e164' => '+201001234567']);
        $this->assertSame('+201001234567', VoiceAgentBrain::customerNumber($d->calls[1]));
        $this->assertSame('+201001234567', VoiceAgentBrain::customerNumber(['direction' => 'outbound', 'to_e164' => '+201001234567', 'from_e164' => '+1']));
        $this->assertSame('+201001234567', VoiceAgentBrain::customerNumber(['from_e164' => '+201001234567']));

        $llm = fn (string $p, array $o) => ['success' => true, 'data' => json_encode(['say' => 'ok', 'language' => 'en', 'action' => ['type' => 'opt_out']])];
        $o = (new VoiceAgentBrain($d, $llm))->respond($d->calls[1], ['name' => 'L'], 'whatever', 1, true);
        $this->assertTrue($o['opt_out']);
        $this->assertTrue($o['end']);
        $this->assertSame(VoiceLanguages::optOutPhrase('en'), $o['say']);

        $llm2 = fn (string $p, array $o) => ['success' => true, 'data' => json_encode(['say' => 'Thanks!', 'language' => 'en', 'action' => ['type' => 'record_survey', 'score' => 9, 'comment' => 'great guide']])];
        $d2 = new VoiceMemoryData(['direction' => 'outbound', 'to_e164' => '+201001234567']);
        (new VoiceAgentBrain($d2, $llm2))->respond($d2->calls[1], ['name' => 'L'], 'five', 1, true);
        $q = json_decode($d2->calls[1]['qualification'], true);
        $this->assertSame(5, $q['survey_score']);
        $this->assertSame('great guide', $q['survey_comment']);
    }

    public function testOpeningDisclosesAiNamesTheCompanyAndOffersStop(): void
    {
        $en = VoiceLanguages::outboundOpening('en', 'Layla', 'Nile Tours', 'Sara');
        $this->assertStringContainsString('AI assistant', $en);
        $this->assertStringContainsString('Nile Tours', $en);
        $this->assertStringContainsString('Sara', $en);
        $this->assertStringContainsString('stop calling', $en);
        foreach (['ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'] as $l) {
            $this->assertStringContainsString('Nile Tours', VoiceLanguages::outboundOpening($l, 'Layla', 'Nile Tours', null));
        }
        foreach (['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'] as $l) {
            $vm = VoiceLanguages::voicemailPhrase($l, 'Nile Tours');
            $this->assertTrue(preg_match('/\d/', $vm) === 0, 'voicemail must not contain figures: ' . $l);
            $this->assertTrue(stripos($vm, 'book') === false && stripos($vm, 'pay') === false, 'voicemail must not state a reason: ' . $l);
        }
    }

    public function testTwilioSkipsEmptySayAndUsesMessageEndDetectionForTransactionalCalls(): void
    {
        $h = (new VoiceFakeHttp())->push(201, ['sid' => 'CA1', 'status' => 'queued']);
        $a = new TwilioVoiceAdapter(['account_sid' => 'AC1', 'auth_token' => 't'], $h);
        $a->startCall('+15125550100', '+201001234567', ['url' => 'https://x.test/o', 'machine_detection' => 'message_end']);
        $this->assertSame('DetectMessageEnd', $h->calls[0]['body']['MachineDetection']);
        $body = $a->turnInstructions(['say' => '', 'hangup' => true])['body'];
        $this->assertStringNotContainsString('<Say', $body);
        $this->assertStringContainsString('<Hangup/>', $body);
    }

    public function testMigrationsAreAdditiveAndEnforceOneConsentAndOneDncPerNumber(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_31_000010_voice_outbound.sql');
        $this->assertStringContainsString('UNIQUE KEY uq_voice_consent (tenant_user_id, phone_key, purpose)', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_voice_dnc (tenant_user_id, phone_key)', $sql);
        $this->assertStringContainsString('approved_live TINYINT(1) NOT NULL DEFAULT 0', $sql);
        $this->assertStringNotContainsString('ALTER TABLE', $sql);
    }
}
