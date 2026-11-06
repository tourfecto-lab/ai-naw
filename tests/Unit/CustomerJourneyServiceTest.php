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
