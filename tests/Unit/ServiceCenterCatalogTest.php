<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/ServiceCenter/ServiceCenterCatalog.php';

/** مركز الخدمات: كتالوج الـ36 خدمة لازم يفضل متسق (مفاتيح فريدة، روابط داخلية، نفس شروط القبول). */
final class ServiceCenterCatalogTest extends TestCase
{
    public function testCatalogListsTheKnownNumberOfServices(): void
    {
        $this->assertCount(36, ServiceCenterCatalog::all());
    }

    public function testEveryEntryIsWellFormed(): void
    {
        foreach (ServiceCenterCatalog::all() as $key => $service) {
            $this->assertSame($key, $service['key']);
            $this->assertNotSame('', trim($service['name']), $key);
            $this->assertNotSame('', trim($service['note']), $key);
            // الروابط داخلية بس (ما يتحولش المستخدم لموقع خارجي من مركز الخدمات)
            $this->assertStringStartsWith('/', $service['url'], $key);
            $this->assertStringStartsWith('/', $service['setup_url'], $key);
            $this->assertStringNotContainsString('//', $service['url'], $key);
        }
    }

    public function testEveryServiceHasTheSameFiveAcceptanceChecks(): void
    {
        foreach (ServiceCenterCatalog::all() as $key => $service) {
            $this->assertSame(['page', 'success', 'failure', 'isolation', 'mobile'], array_keys($service['acceptance_checks']), $key);
        }
    }

    public function testGetReturnsAServiceAndRejectsUnknownKeys(): void
    {
        $this->assertSame('crm', ServiceCenterCatalog::get('crm')['key']);

        $this->expectException(InvalidArgumentException::class);
        ServiceCenterCatalog::get('not-a-service');
    }

    public function testNamesAreUnique(): void
    {
        $names = array_column(ServiceCenterCatalog::all(), 'name');
        $this->assertSame(count($names), count(array_unique($names)));
    }
}
