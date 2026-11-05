<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AutoFix/FixCapability.php';

/**
 * FixCapability بتحدد أنهي إصلاحات بتتنفّذ تلقائي على موقع العميل وبأنهي مستوى خطورة.
 * أي مفتاح ناقص أو خطورة مش معروفة معناها إصلاح ممكن يتطبّق من غير حدود.
 */
final class AutoFixCapabilityTest extends TestCase
{
    public function testEveryCapabilityMapsToADatabaseField(): void
    {
        foreach (FixCapability::MAP as $check => [$field]) {
            $this->assertArrayHasKey($field, FixCapability::DB_FIELD, $check);
        }
        // وكل حقل في جدول الحقول له إصلاح بيستخدمه
        $usedFields = array_column(array_values(FixCapability::MAP), 0);
        foreach (array_keys(FixCapability::DB_FIELD) as $field) {
            $this->assertContains($field, $usedFields, $field);
        }
    }

    public function testRiskLevelsAreKnownAndLabelsAreNotEmpty(): void
    {
        foreach (FixCapability::MAP as $check => [, $risk, $label]) {
            $this->assertContains($risk, ['low', 'medium', 'high'], $check);
            $this->assertNotSame('', trim($label), $check);
        }
    }

    public function testHstsIsHighRiskAndMetaTagsAreLowRisk(): void
    {
        $this->assertSame('high', FixCapability::forCheck('hsts')['risk']);
        $this->assertSame('medium', FixCapability::forCheck('structured_data')['risk']);
        foreach (['title_tag', 'meta_description', 'canonical_tag', 'robots_meta', 'viewport', 'html_lang', 'open_graph', 'llms_txt'] as $check) {
            $this->assertSame('low', FixCapability::forCheck($check)['risk'], $check);
        }
    }

    public function testForCheckReturnsNullForUnknownOrMissingKeys(): void
    {
        $this->assertNull(FixCapability::forCheck(null));
        $this->assertNull(FixCapability::forCheck(''));
        $this->assertNull(FixCapability::forCheck('page_speed'));
        $this->assertNull(FixCapability::forCheck('broken_links'));
    }

    public function testForCheckReturnsTheDocumentedShape(): void
    {
        $cap = FixCapability::forCheck('title_tag');

        $this->assertSame(['field', 'db_field', 'risk', 'label'], array_keys($cap));
        $this->assertSame('title', $cap['field']);
        $this->assertSame('seo_title', $cap['db_field']);
    }

    public function testAllCoversEveryCapability(): void
    {
        $all = FixCapability::all();

        $this->assertSame(array_keys(FixCapability::MAP), array_keys($all));
        foreach ($all as $cap) {
            $this->assertNotNull($cap);
        }
    }
}
