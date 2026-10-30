<?php

/** AI Voice & Sales Agent (Phase 6 additions): automatic follow-ups from bookings, WhatsApp template fallback. No DB, no network. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceLanguages', 'VoiceReplyParser', 'VoiceIdentity', 'VoiceDataSource', 'VoicePromptBuilder', 'VoiceAgentBrain', 'VoicePostCallRules', 'VoiceFollowUpMessages', 'VoicePostCallGateway', 'VoicePostCallService', 'VoiceSettingsService', 'VoiceNumberRules', 'VoiceMarkets', 'VoiceAutoFollowUps'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/' . $c . '.php';
}
require_once __DIR__ . '/../Fixtures/VoiceMemoryData.php';
require_once __DIR__ . '/../Fixtures/VoiceFakeGateway.php';

final class VoiceFollowUpAutomationTest extends TestCase
{
    public function testOnlyNumbersWithACountryCodeAreUsed(): void
    {
        $this->assertSame('+201001234567', VoiceAutoFollowUps::phoneToE164('+20 100 123-4567'));
        $this->assertSame('+201001234567', VoiceAutoFollowUps::phoneToE164('0020 (100) 1234567'));
        $this->assertNull(VoiceAutoFollowUps::phoneToE164('01001234567'));   // local format: never guessed into a country
        $this->assertNull(VoiceAutoFollowUps::phoneToE164('+12'));
        $this->assertNull(VoiceAutoFollowUps::phoneToE164(''));
        $this->assertNull(VoiceAutoFollowUps::phoneToE164(null));
    }

    public function testOneJobPerBookingAndPurpose(): void
    {
        $this->assertSame('booking:42:trip_reminder', VoiceAutoFollowUps::sourceRef(42, 'trip_reminder'));
        $this->assertNotSame(VoiceAutoFollowUps::sourceRef(42, 'trip_reminder'), VoiceAutoFollowUps::sourceRef(42, 'booking_followup'));
        $out = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceOutboundService.php');
        $this->assertStringContainsString("'existing' => true", $out);                     // asking twice returns the same job
        $this->assertStringContainsString('UNIQUE KEY uq_voice_job_source (tenant_user_id, source_ref)', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_31_000010_voice_outbound.sql'));
    }

    public function testContextCarriesOnlyWhatTheAssistantNeeds(): void
    {
        $c = VoiceAutoFollowUps::contextFor(['customer_name' => 'Sara El-Sayed', 'booking_reference' => 'TF-A1B2C3', 'trip_name' => 'Luxor Day Trip', 'start_date' => '2026-12-05', 'total_amount' => '240.00', 'currency' => 'USD', 'customer_phone' => '+2010', 'customer_email' => 'x@y.z']);
        $this->assertSame(['first_name' => 'Sara', 'booking_ref' => 'TF-A1B2C3', 'trip_name' => 'Luxor Day Trip', 'trip_date' => '2026-12-05', 'amount' => '240', 'currency' => 'USD'], $c);
        $this->assertSame([], VoiceAutoFollowUps::contextFor([]));
        // the model never sees the booking reference or e-mail (they are the verification answers): VoicePromptBuilder::outboundSection removes them
        $p = VoicePromptBuilder::outboundSection(['purpose' => 'trip_reminder', 'facts' => $c], false);
        $this->assertStringNotContainsString('TF-A1B2C3', $p);
        $this->assertStringNotContainsString('240', $p);
    }

    public function testEachKindIsOffUntilTheCompanyTurnsItOnAndAutomaticCallsNeedAStandingApproval(): void
    {
        $this->assertSame(['auto_abandoned_recovery', 'auto_trip_reminders', 'auto_booking_confirmation'], array_column(VoiceAutoFollowUps::KINDS, 'flag'));
        $mig = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_31_000006_voice_agent_foundation.sql');
        foreach (['auto_abandoned_recovery', 'auto_trip_reminders', 'auto_booking_confirmation', 'auto_followups_live_approved'] as $f) {
            $this->assertStringContainsString("$f TINYINT(1) NOT NULL DEFAULT 0", $mig);
        }
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceAutoFollowUps.php');
        $this->assertStringContainsString("'approve_live' => (bool) \$settings['auto_followups_live_approved']", $src);
        $this->assertStringContainsString('enabled = 1 AND outbound_enabled = 1', $src);
        $this->assertStringContainsString('b.user_id = ?', $src);                            // one company's bookings at a time
        $this->assertStringNotContainsString('->dial', $src);                                // queues only; the dialler and compliance decide
        $this->assertSame(['purpose' => 'booking_followup', 'flag' => 'auto_abandoned_recovery'], VoiceAutoFollowUps::KINDS['abandoned']);
    }

    public function testSettingsForTemplatesAreValidated(): void
    {
        $this->assertSame(['post_call_wa_template' => 'trip_details_v1', 'post_call_wa_template_lang' => 'en_US'], array_intersect_key(VoiceSettingsService::sanitizeSettings(['post_call_wa_template' => 'trip_details_v1', 'post_call_wa_template_lang' => 'en_US']), ['post_call_wa_template' => 1, 'post_call_wa_template_lang' => 1]));
        $this->assertNull(VoiceSettingsService::sanitizeSettings(['post_call_wa_template' => ''])['post_call_wa_template']);
        foreach ([['post_call_wa_template' => 'Bad Name!'], ['post_call_wa_template_lang' => 'english']] as $bad) {
            try {
                VoiceSettingsService::sanitizeSettings($bad);
                $this->fail('accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testClosedWhatsAppWindowUsesTheApprovedTemplateBeforeFallingBackToEmail(): void
    {
        $d = new VoiceMemoryData(['outcome' => 'resolved', 'from_e164' => '+201001234567', 'language' => 'en', 'identity_verified' => 0, 'verify_attempts' => 0, 'post_processed_at' => null, 'booking_id' => null, 'crm_lead_id' => null,
            'send_requests' => json_encode([['channel' => 'any', 'item' => 'trip_details', 'product_id' => 3]]), 'qualification' => json_encode(['email' => 'sara@example.com'])]);
        $d->addTurn(7, 1, 100, 'caller', 'send me details');
        $gw = new VoiceFakeGateway();
        $gw->trips[3] = ['name' => 'Luxor Day Trip', 'price' => 120.0, 'currency' => 'USD'];
        $gw->settings = ['post_call_whatsapp' => 1, 'post_call_email' => 1, 'post_call_wa_template' => 'trip_details_v1', 'post_call_wa_template_lang' => 'en_US', 'whatsapp_website_id' => 5, 'booking_mode' => 'request_only'];
        $gw->waResult = ['success' => false, 'error' => 'No active 24-hour customer conversation window'];
        $r = (new VoicePostCallService($d, $gw, fn ($p, $o) => ['success' => false]))->process(7, 1);
        $this->assertSame('done', $r['message']);
        $this->assertCount(1, $gw->wa);
        $this->assertCount(1, $gw->waTemplates);
        $this->assertSame('trip_details_v1', $gw->waTemplates[0][1]);
        $this->assertSame('voice_postcall:1', $gw->waTemplates[0][3]);
        $this->assertSame([], $gw->mails);
        $this->assertSame([], $gw->tasks);

        // template not approved either: e-mail next
        [$d2, $gw2] = [new VoiceMemoryData(['outcome' => 'resolved', 'from_e164' => '+201001234567', 'language' => 'en', 'identity_verified' => 0, 'verify_attempts' => 0, 'post_processed_at' => null, 'booking_id' => null, 'crm_lead_id' => null,
            'send_requests' => json_encode([['channel' => 'any', 'item' => 'trip_details', 'product_id' => 3]]), 'qualification' => json_encode(['email' => 'sara@example.com'])]), new VoiceFakeGateway()];
        $d2->addTurn(7, 1, 100, 'caller', 'send me details');
        $gw2->trips[3] = ['name' => 'Luxor Day Trip', 'price' => 120.0, 'currency' => 'USD'];
        $gw2->settings = $gw->settings;
        $gw2->waResult = ['success' => false, 'error' => 'window'];
        $gw2->waTemplateResult = ['success' => false, 'error' => 'Approve the exact utility template'];
        (new VoicePostCallService($d2, $gw2, fn ($p, $o) => ['success' => false]))->process(7, 1);
        $this->assertCount(1, $gw2->waTemplates);
        $this->assertCount(1, $gw2->mails);
    }

    public function testTemplateSendReusesThePlatformServiceAndCollapsesLineBreaks(): void
    {
        $g = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoicePostCallCrmGateway.php');
        $this->assertStringContainsString('WhatsAppTransactionalService::send(', $g);
        $this->assertStringContainsString("preg_replace('/\\s+/u', ' ', \$text)", $g);   // WhatsApp template parameters cannot contain line breaks
        $this->assertStringContainsString('mb_substr(', $g);
    }
}
