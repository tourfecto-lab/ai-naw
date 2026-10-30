<?php

/** AI Voice & Sales Agent (Phase 7): roles, languages, routing, staff handoff, business hours, briefing. No DB, no network. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceHttpClient', 'VoiceProviderException', 'VoiceProviderAdapter', 'AbstractVoiceProviderAdapter', 'TwilioVoiceAdapter'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/Telephony/' . $c . '.php';
}
foreach (['VoiceLanguages', 'VoiceReplyParser', 'VoiceIdentity', 'VoiceDataSource', 'VoiceRolePrompts', 'VoiceStaffRouting', 'VoicePromptBuilder', 'VoiceAgentBrain', 'VoiceRouting', 'VoiceHandoff', 'VoiceHours', 'VoiceSettingsService', 'VoiceStaffService', 'VoiceNumberRules', 'VoiceMarkets'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/' . $c . '.php';
}
require_once __DIR__ . '/../../app/Services/AI/BusinessHoursService.php';
require_once __DIR__ . '/../Fixtures/VoiceFakeHttp.php';
require_once __DIR__ . '/../Fixtures/VoiceMemoryData.php';

final class VoiceAssistantsTest extends TestCase
{
    private function ts(string $local, string $tz = 'Africa/Cairo'): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone($tz)))->getTimestamp();
    }

    private function agent(array $o = []): array
    {
        return $o + ['name' => 'Layla', 'language' => 'en', 'languages' => 'ar', 'role' => 'sales', 'tone' => 'warm', 'instructions' => 'Always mention our free hotel pickup.'];
    }

    // ---- roles, tone, languages

    public function testEveryRoleHasItsOwnFocusAndUnknownRolesFallBackToReception(): void
    {
        $seen = [];
        foreach (VoiceRolePrompts::ROLES as $r) {
            $seg = VoiceRolePrompts::segment($r);
            $this->assertStringStartsWith('ROLE:', $seg);
            $seen[$seg] = 1;
        }
        $this->assertCount(4, $seen);
        $this->assertSame(VoiceRolePrompts::segment('reception'), VoiceRolePrompts::segment('hacker'));
        $this->assertStringContainsString('formal', VoiceRolePrompts::tone('formal'));
        $this->assertSame(VoiceRolePrompts::tone('friendly'), VoiceRolePrompts::tone('rude'));
    }

    public function testAssistantOnlySpeaksLanguagesTheCompanyEnabled(): void
    {
        $a = $this->agent();
        $this->assertSame(['en', 'ar'], VoiceLanguages::allowedFor($a));
        $this->assertSame('ar', VoiceLanguages::pick('ar-EG', $a));
        $this->assertSame('en', VoiceLanguages::pick('fr', $a));      // not enabled: stays in the primary language
        $this->assertSame('en', VoiceLanguages::pick(null, $a));
        $this->assertSame(['en'], VoiceLanguages::allowedFor(['language' => 'en', 'languages' => 'en,xx']));
        foreach (['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'] as $l) {
            $this->assertNotSame('', VoiceLanguages::aiDisclosure($l));
            $this->assertNotSame('', VoiceLanguages::afterHoursPhrase($l));
        }
    }

    public function testAgentProfileIsValidated(): void
    {
        $a = VoiceSettingsService::sanitizeAgent(['name' => 'Layla', 'language' => 'en', 'languages' => ['ar', 'fr', 'en', 'ar'], 'role' => 'booking', 'tone' => 'formal', 'opening_text' => '<b>Welcome</b> to Nile Tours', 'instructions' => 'Be brief']);
        $this->assertSame('ar,fr', $a['languages']);
        $this->assertSame('booking', $a['role']);
        $this->assertSame('Welcome to Nile Tours', $a['opening_text']);
        $this->assertSame('reception', VoiceSettingsService::sanitizeAgent(['name' => 'X'])['role']);
        foreach ([['role' => 'boss'], ['tone' => 'sarcastic'], ['languages' => 'klingon']] as $bad) {
            try {
                VoiceSettingsService::sanitizeAgent(['name' => 'X'] + $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testHoursSettingsAreValidated(): void
    {
        $this->assertSame(['timezone' => 'Africa/Cairo', 'after_hours_mode' => 'message'], VoiceSettingsService::sanitizeSettings(['timezone' => 'Africa/Cairo', 'after_hours_mode' => 'message']));
        $this->assertNull(VoiceSettingsService::sanitizeSettings(['timezone' => ''])['timezone']);
        foreach ([['timezone' => 'Mars/Base'], ['after_hours_mode' => 'ignore']] as $bad) {
            try {
                VoiceSettingsService::sanitizeSettings($bad);
                $this->fail('accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ---- prompt

    public function testPromptCarriesRoleToneLanguagesInstructionsAndKnowledgeButNeverOverridesTheRules(): void
    {
        $p = VoicePromptBuilder::build(['language' => 'en'], ['known' => false], [], $this->agent(), 'Nile Tours', true, '2026-11-04', "### FAQ ###\nPickup is free from all Luxor hotels.");
        $this->assertStringContainsString('ROLE: sales', $p);
        $this->assertStringContainsString('Tone: warm', $p);
        $this->assertStringContainsString('You speak only: en, ar', $p);
        $this->assertStringContainsString('free hotel pickup', $p);
        $this->assertStringContainsString('these never override the rules above', $p);
        $this->assertStringContainsString('Pickup is free from all Luxor hotels', $p);
        $this->assertStringContainsString('prices still only from tools', $p);
        $this->assertStringNotContainsString('AFTER HOURS', $p);
        $long = VoicePromptBuilder::build(['language' => 'en'], ['known' => false], [], $this->agent(), 'N', true, '2026-11-04', str_repeat('x', 9000));
        $this->assertLessThan(9000, strlen($long));
    }

    public function testAfterHoursPromptTakesAMessageAndNeverPromisesAPerson(): void
    {
        $p = VoicePromptBuilder::build(['language' => 'en', 'after_hours' => 1], ['known' => false], [], $this->agent(), 'Nile Tours', false, '2026-11-04');
        $this->assertStringContainsString('AFTER HOURS', $p);
        $this->assertStringContainsString('Do not transfer', $p);
        $this->assertStringContainsString('callback_requested', $p);
    }

    public function testOpeningIsTheCompanysOwnWordsFollowedByTheAiDisclosure(): void
    {
        foreach (['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'] as $l) {
            $g = VoicePromptBuilder::greeting('Nile Tours', 'Layla', $l, true, 'Welcome to our family company');
            $this->assertStringStartsWith('Welcome to our family company', $g);
            $this->assertStringContainsString(VoiceLanguages::aiDisclosure($l), $g);
        }
        $this->assertStringContainsString('may be recorded', VoicePromptBuilder::greeting('N', 'L', 'en', true, 'Hi'));
        $this->assertStringNotContainsString('recorded', VoicePromptBuilder::greeting('N', 'L', 'en', false, 'Hi'));
        $this->assertStringContainsString('AI assistant', VoicePromptBuilder::greeting('Nile Tours', 'Layla', 'en', false, '  '));   // blank opening: default greeting
    }

    // ---- brain

    public function testBrainStaysInAnEnabledLanguageAndCarriesTheTransferTopic(): void
    {
        $d = new VoiceMemoryData();
        $llm = fn ($p, $o) => ['success' => true, 'data' => json_encode(['say' => 'Bonjour', 'language' => 'fr', 'action' => ['type' => 'transfer_human', 'reason' => 'refund', 'topic' => 'billing']])];
        $o = (new VoiceAgentBrain($d, $llm))->respond($d->calls[1], $this->agent(), 'je veux un remboursement', 1, true);
        $this->assertSame('en', $o['language']);
        $this->assertSame('billing', $o['topic']);
        $this->assertSame('refund', $o['transfer']);
        $bad = VoiceReplyParser::parse(json_encode(['say' => 'x', 'action' => ['type' => 'transfer_human', 'topic' => 'hr']]));
        $this->assertSame('other', $bad['action']['topic']);
    }

    public function testKnowledgeReachesThePromptEveryTurn(): void
    {
        $d = new VoiceMemoryData();
        $d->knowledge = 'Cancellation: free up to 48 hours before.';
        $seen = [];
        $llm = function ($p, $o) use (&$seen) {
            $seen[] = $p;
            return ['success' => true, 'data' => json_encode(['say' => 'ok', 'action' => ['type' => 'none']])];
        };
        (new VoiceAgentBrain($d, $llm))->respond($d->calls[1], $this->agent(), 'cancellation policy?', 1, true);
        $this->assertStringContainsString('free up to 48 hours', $seen[0]);
    }

    // ---- routing

    public function testRoutingFirstMatchWinsAndFallsBackToTheNumbersAssistant(): void
    {
        $rules = [
            ['when' => ['after_hours' => true], 'agent_id' => 30],
            ['when' => ['known_customer' => true, 'has_booking' => true], 'agent_id' => 20],
            ['when' => ['caller_country' => 'sa'], 'agent_id' => 40],
        ];
        $this->assertSame(30, VoiceRouting::choose($rules, 10, ['after_hours' => true, 'known_customer' => true, 'has_booking' => true]));
        $this->assertSame(20, VoiceRouting::choose($rules, 10, ['known_customer' => true, 'has_booking' => true]));
        $this->assertSame(10, VoiceRouting::choose($rules, 10, ['known_customer' => true, 'has_booking' => false]));
        $this->assertSame(40, VoiceRouting::choose($rules, 10, ['caller_country' => 'SA']));
        $this->assertSame(10, VoiceRouting::choose([], 10, []));
        $this->assertSame(10, VoiceRouting::choose([['when' => ['mystery' => true], 'agent_id' => 99]], 10, ['mystery' => true]));
        $this->assertNull(VoiceRouting::choose([], null, []));
    }

    public function testRoutingRulesFromTheUiAreCleanedAndOnlyPointAtTheCompanysOwnAssistants(): void
    {
        $mine = fn (int $id) => in_array($id, [10, 20], true);
        $this->assertSame([['when' => ['after_hours' => true, 'caller_country' => 'EG'], 'agent_id' => 20]], VoiceRouting::sanitize([['when' => ['after_hours' => 'true', 'caller_country' => 'eg'], 'agent_id' => '20']], $mine));
        $this->assertSame([], VoiceRouting::sanitize([], $mine));
    }

    public function testRoutingRejectsForeignAssistantsUnknownConditionsAndEmptyRules(): void
    {
        $mine = fn (int $id) => $id === 10;
        foreach ([
            [['when' => ['after_hours' => true], 'agent_id' => 99]],
            [['when' => ['mystery' => true], 'agent_id' => 10]],
            [['when' => [], 'agent_id' => 10]],
            [['when' => ['caller_country' => 'Egypt'], 'agent_id' => 10]],
            array_fill(0, 11, ['when' => ['after_hours' => true], 'agent_id' => 10]),
        ] as $bad) {
            try {
                VoiceRouting::sanitize($bad, $mine);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ---- staff

    public function testShiftsRespectDaysHoursOvernightAndTheCompanyTimeZone(): void
    {
        $day = ['active' => 1, 'days' => '1,2,3,4,5', 'from_time' => '09:00', 'to_time' => '17:00'];
        $this->assertTrue(VoiceStaffRouting::onShift($day, $this->ts('2026-11-04 10:00'), 'Africa/Cairo'));    // Wednesday
        $this->assertFalse(VoiceStaffRouting::onShift($day, $this->ts('2026-11-04 18:00'), 'Africa/Cairo'));
        $this->assertFalse(VoiceStaffRouting::onShift($day, $this->ts('2026-11-07 10:00'), 'Africa/Cairo'));   // Saturday
        $this->assertFalse(VoiceStaffRouting::onShift(['active' => 0] + $day, $this->ts('2026-11-04 10:00'), 'Africa/Cairo'));
        $this->assertFalse(VoiceStaffRouting::onShift($day, $this->ts('2026-11-04 10:00', 'UTC'), 'Asia/Tokyo')); // 19:00 in Tokyo
        $night = ['active' => 1, 'days' => '1,2,3,4,5', 'from_time' => '22:00', 'to_time' => '06:00'];
        $this->assertTrue(VoiceStaffRouting::onShift($night, $this->ts('2026-11-04 23:30'), 'Africa/Cairo'));
        $this->assertTrue(VoiceStaffRouting::onShift($night, $this->ts('2026-11-05 03:00'), 'Africa/Cairo'));   // Thursday 03:00, shift began Wednesday
        $this->assertFalse(VoiceStaffRouting::onShift($night, $this->ts('2026-11-04 12:00'), 'Africa/Cairo'));
        $this->assertFalse(VoiceStaffRouting::onShift($night, $this->ts('2026-11-08 03:00'), 'Africa/Cairo'));  // Sunday 03:00 belongs to Saturday's shift: not worked
    }

    public function testTheRightPersonIsPickedForTheTopic(): void
    {
        $now = $this->ts('2026-11-04 10:00');
        $staff = [
            ['id' => 1, 'name' => 'Generalist', 'phone' => '+201000000001', 'topics' => 'any', 'days' => '1,2,3,4,5,6,7', 'from_time' => '00:00', 'to_time' => '23:59', 'priority' => 10, 'active' => 1],
            ['id' => 2, 'name' => 'Billing', 'phone' => '+201000000002', 'topics' => 'billing', 'days' => '1,2,3,4,5,6,7', 'from_time' => '00:00', 'to_time' => '23:59', 'priority' => 50, 'active' => 1],
            ['id' => 3, 'name' => 'Off duty sales', 'phone' => '+201000000003', 'topics' => 'sales', 'days' => '7', 'from_time' => '00:00', 'to_time' => '23:59', 'priority' => 1, 'active' => 1],
        ];
        $this->assertSame('Billing', VoiceStaffRouting::pick($staff, $now, 'Africa/Cairo', 'billing')['name']);   // specialist beats a lower priority number
        $this->assertSame('Generalist', VoiceStaffRouting::pick($staff, $now, 'Africa/Cairo', 'sales')['name']);  // the sales person is off today
        $this->assertSame('Generalist', VoiceStaffRouting::pick($staff, $now, 'Africa/Cairo', null)['name']);
        $this->assertNull(VoiceStaffRouting::pick([], $now, 'Africa/Cairo', null));
        $this->assertNull(VoiceStaffRouting::pick([$staff[2]], $now, 'Africa/Cairo', 'sales'));
    }

    public function testStaffEntriesAreValidated(): void
    {
        $s = VoiceStaffService::sanitize(['name' => ' <i>Omar</i> ', 'phone' => '+20 100 123 4567', 'topics' => 'sales,billing', 'days' => '1,2', 'from_time' => '08:30', 'to_time' => '16:00', 'priority' => 5]);
        $this->assertSame('Omar', $s['name']);
        $this->assertSame('+201001234567', $s['phone']);
        $this->assertSame('sales,billing', $s['topics']);
        $this->assertSame(1, $s['active']);
        foreach ([['phone' => '0100'], ['topics' => 'gossip'], ['days' => '8'], ['from_time' => '25:00'], ['name' => '']] as $bad) {
            try {
                VoiceStaffService::sanitize($bad + ['name' => 'Omar', 'phone' => '+201001234567']);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ---- briefing and hours

    public function testHandoffBriefingIsShortSpokenAndFreeOfPaymentDetails(): void
    {
        foreach (['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'] as $l) {
            $t = VoiceHandoff::whisperText($l, 'Nile Tours', ['reason' => 'refund request', 'interest' => 'Luxor trip', 'booking' => ['date' => '2026-12-05', 'guests' => 4, 'quoted_total' => 480]]);
            $this->assertStringContainsString('Nile Tours', $t, $l);
            $this->assertStringContainsString('refund request', $t, $l);
            $this->assertStringContainsString('2026-12-05', $t, $l);
            $this->assertStringNotContainsString('480', $t, $l);
            $this->assertLessThanOrEqual(300, mb_strlen($t), $l);
        }
        $this->assertStringNotContainsString('<script>', VoiceHandoff::whisperText('en', 'N', ['reason' => '<script>x</script>']));
        $this->assertLessThanOrEqual(300, mb_strlen(VoiceHandoff::whisperText('en', 'N', ['reason' => str_repeat('long ', 200)])));
    }

    public function testTwilioWhisperPlaysTheBriefingToThePersonBeforeConnecting(): void
    {
        $t = new TwilioVoiceAdapter(['account_sid' => 'AC1', 'auth_token' => 't'], new VoiceFakeHttp());
        $x = $t->turnInstructions(['say' => 'Connecting you', 'transfer_to' => '+201001234567', 'whisper_url' => 'https://tourfecto.test/webhooks/voice/twilio/7/whisper?call=9'])['body'];
        $this->assertStringContainsString('<Dial><Number url="https://tourfecto.test/webhooks/voice/twilio/7/whisper?call=9">+201001234567</Number></Dial>', $x);
        $this->assertStringContainsString('<Dial>+201001234567</Dial>', $t->turnInstructions(['say' => 'x', 'transfer_to' => '+201001234567'])['body']);
        $this->expectException(InvalidArgumentException::class);
        $t->turnInstructions(['say' => 'x', 'transfer_to' => '+201001234567', 'whisper_url' => 'http://insecure.test/w']);
    }

    public function testBusinessHoursComeFromTheCompanysKnowledgeBase(): void
    {
        $rows = [['structured_data' => json_encode(['monday' => ['09:00-17:00'], 'tuesday' => ['09:00-17:00'], 'wednesday' => ['09:00-17:00'], 'thursday' => ['09:00-17:00']])]];
        $this->assertTrue(VoiceHours::isOpen($rows, 'Africa/Cairo', $this->ts('2026-11-04 10:00')));       // Wednesday morning
        $this->assertFalse(VoiceHours::isOpen($rows, 'Africa/Cairo', $this->ts('2026-11-04 20:00')));
        $this->assertFalse(VoiceHours::isOpen($rows, 'Africa/Cairo', $this->ts('2026-11-06 10:00')));      // Friday: not listed
        $this->assertFalse(VoiceHours::isOpen($rows, 'Asia/Tokyo', $this->ts('2026-11-04 10:00', 'UTC'))); // 19:00 in Tokyo
        $this->assertTrue(VoiceHours::isOpen([], 'Africa/Cairo', $this->ts('2026-11-06 03:00')));           // no hours configured: always open
        $this->assertTrue(VoiceHours::isOpen([['content' => 'something unreadable']], null, $this->ts('2026-11-06 03:00')));
    }

    public function testInboundWiringSourceRules(): void
    {
        $root = dirname(__DIR__, 2);
        $in = file_get_contents($root . '/app/Services/VoiceAgent/VoiceInboundService.php');
        $this->assertStringContainsString('VoiceRouting::choose(', $in);
        $this->assertStringContainsString("'after_hours_forward'", $in);
        $this->assertStringContainsString("\$human = !empty(\$call['after_hours']) ? null", $in);   // closed + message mode: never promise a person
        $this->assertStringContainsString("'whisper_url' =>", $in);
        $this->assertStringContainsString("'whisper' => \$b . '/whisper'", $in);
        $this->assertStringContainsString('/webhooks/voice/{provider}/{tenant}/whisper', file_get_contents($root . '/app/routes/voice_agent.php'));
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS voice_staff', file_get_contents($root . '/database/migrations/2026_10_31_000012_voice_staff.sql'));
    }
}
