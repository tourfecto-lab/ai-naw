<?php

use PHPUnit\Framework\TestCase;

/**
 * رحلة العميل: تقسيم الجلسات، وقت الصفحة، توحيد المسارات، ورقم واتساب.
 */
final class CustomerJourneyServiceTest extends TestCase
{
    public function testNormalizePathDropsQueryAndNumericIds(): void
    {
        $this->assertSame('/websites/{id}/edit', CustomerJourneyService::normalizePath('/websites/42/edit?x=1'));
        $this->assertSame('/dashboard', CustomerJourneyService::normalizePath('/dashboard/'));
        $this->assertSame('/', CustomerJourneyService::normalizePath('/'));
        $this->assertSame('لوحة التحكم', CustomerJourneyService::pageLabel('/dashboard'));
        $this->assertSame('المواقع', CustomerJourneyService::pageLabel('/websites/{id}'));
    }

    public function testSessionizeSplitsOnGapAndCapsDwell(): void
    {
        $v = [
            ['page_url' => '/dashboard', 'created_at' => '2026-11-05 16:53:00', 'device_type' => 'mobile'],
            ['page_url' => '/websites/5', 'created_at' => '2026-11-05 16:55:00', 'device_type' => 'mobile'],
            ['page_url' => '/websites/9', 'created_at' => '2026-11-05 17:10:00', 'device_type' => 'mobile'],
            ['page_url' => '/dashboard', 'created_at' => '2026-11-06 09:00:00', 'device_type' => 'desktop'],
        ];
        $r = CustomerJourneyService::sessionize($v);
        $this->assertCount(2, $r['sessions']);
        $this->assertSame(3, $r['sessions'][0]['pages']);
        // 120 ث على الداشبورد، و900 ث (الحد الأقصى) على /websites/{id} لأن الفجوة 15 دقيقة بالظبط
        $this->assertSame(1020, $r['sessions'][0]['seconds']);
        $this->assertSame(1020, $r['active_seconds']);
        $this->assertSame(0, $r['sessions'][1]['seconds'], 'آخر صفحة في الجلسة مش بتتحسب');
        $paths = array_column($r['pages'], 'seconds', 'path');
        $this->assertSame(120, $paths['/dashboard']);
        $this->assertSame(900, $paths['/websites/{id}']);
        $this->assertSame('/websites/{id}', $r['pages'][0]['path'], 'الأكتر وقتًا أول');
    }

    public function testStageIsChosenByPriority(): void
    {
        $f = static fn (string $sub, ?int $days, bool $added, bool $conn, int $ai, string $state) => CustomerJourneyService::stageFor($sub, $days, $added, $conn, $ai, $state)['key'];
        $this->assertSame('paid', $f('active', null, true, true, 5, 'active'));
        $this->assertSame('trial_ending', $f('trialing', 1, false, false, 0, 'never_returned'), 'قرب التجربة أهم من أي حاجة');
        $this->assertSame('no_site', $f('trialing', 3, false, false, 0, 'never_returned'));
        $this->assertSame('not_connected', $f('trialing', 3, true, false, 9, 'never_returned'), 'حالة Temple Cruise');
        $this->assertSame('no_ai', $f('trialing', 3, true, true, 0, 'active'));
        $this->assertSame('dormant', $f('trialing', 3, true, true, 4, 'dormant'));
        $this->assertSame('check_in', $f('trialing', 3, true, true, 4, 'active'));
    }

    public function testStageMessagesAreGroundedAndLocalised(): void
    {
        $ar = CustomerJourneyService::stageMessage('not_connected', 'ar', 'Temple Cruise', 'templecruise.com', 3, true, true);
        $this->assertStringContainsString('Temple Cruise', $ar);
        $this->assertStringContainsString('templecruise.com', $ar);
        $this->assertStringContainsString('جرّبتوا تحليل الموقع', $ar);
        $noAi = CustomerJourneyService::stageMessage('not_connected', 'ar', 'X', 'x.com', 3, false, false);
        $this->assertStringNotContainsString('جرّبتوا', $noAi, 'مانقولش إنه جرّب حاجة وهو ماجرّبش');
        $en = CustomerJourneyService::stageMessage('trial_ending', 'en', 'X', '', 1, false, false);
        $this->assertStringContainsString('free trial', $en);
        $this->assertSame(CustomerJourneyService::stageMessage('trial_ending', 'en', 'X', '', 1, false, false), CustomerJourneyService::stageMessage('trial_ending', 'tr', 'X', '', 1, false, false), 'التركي بيرجع للإنجليزي');
        foreach (['trial_ending', 'paid', 'no_site', 'not_connected', 'no_ai', 'dormant', 'check_in', 'unknown'] as $stage) {
            $this->assertNotSame('', CustomerJourneyService::stageMessage($stage, 'ar', 'X', 'x.com', 2, true, false));
        }
    }

    public function testAssistantContextFitsAndTellsItIsASignup(): void
    {
        $j = [
            'user' => ['name' => str_repeat('ا', 120)],
            'plan' => ['name' => 'النمو', 'status' => 'trialing', 'trial_days_left' => 3],
            'hours_since_last_seen' => 15, 'session_count' => 2, 'ai_calls' => 9,
            'milestones' => [['label' => 'سجّل', 'done' => true], ['label' => 'ربط الموقع', 'done' => false]],
            'stage' => ['label' => 'أضاف موقعه وما ربطوش'], 'source' => ['report_viewed' => true],
        ];
        $c = CustomerJourneyService::assistantContext($j);
        $this->assertLessThanOrEqual(950, mb_strlen($c));
        $this->assertStringContainsString('سجّلت فعلاً', $c);
        $this->assertStringContainsString('لسه ما عملش: ربط الموقع', $c);
        $this->assertStringContainsString('فاتح تقرير', $c);
    }

    public function testBrowserBackgroundRequestsAreNotCustomerPages(): void
    {
        $this->assertTrue(CustomerJourneyService::isTechnical('/i18n-dict'));
        $this->assertTrue(CustomerJourneyService::isTechnical('/assets/js/app.js?v=3'));
        $this->assertTrue(CustomerJourneyService::isTechnical('/favicon.ico'));
        $this->assertFalse(CustomerJourneyService::isTechnical('/website-optimizer'));
        $this->assertFalse(CustomerJourneyService::isTechnical('/dashboard'));
    }

    public function testSessionizeHandlesEmpty(): void
    {
        $this->assertSame(['sessions' => [], 'pages' => [], 'active_seconds' => 0], CustomerJourneyService::sessionize([]));
    }

    public function testWaNumber(): void
    {
        $this->assertSame('201092807902', CustomerJourneyService::waNumber('+20 10 92807902'));
        $this->assertSame('201012345678', CustomerJourneyService::waNumber('01012345678', 'EG'));
        $this->assertSame('905551234567', CustomerJourneyService::waNumber('00905551234567', 'TR'));
        $this->assertNull(CustomerJourneyService::waNumber('123'));
        $this->assertNull(CustomerJourneyService::waNumber(null));
    }

    public function testTimestampsAreReadAsUtc(): void
    {
        $this->assertSame(strtotime('2026-11-05 16:53:00 UTC'), CustomerJourneyService::ts('2026-11-05 16:53:00'));
        $this->assertSame(0, CustomerJourneyService::ts(null));
    }
}
