<?php

/**
 * Tourfecto - Business Service Manager Test (Phase 25)
 * @version 1.0.0
 *
 * اختبار offline لمنطق توليد الـslug الفريد في BusinessServiceManager.
 * المنطق الوحيد المرتبط بالـDB هو slugExists() (استعلام على
 * business_services) - فبنستبدله في subclass بمحاكاة in-memory عشان
 * نختبر generateUniqueSlug وslugify بشكل كامل من غير قاعدة بيانات.
 */

if (!class_exists('Model')) {
    class Model
    {
        protected $table = '';
        protected $fillable = [];
        public function getAttribute(string $key)
        {
            return null;
        }
        public function setAttribute(string $key, $value)
        {
        }
        public function save()
        {
            return true;
        }
        public function toArray(): array
        {
            return [];
        }
        public function where(array $conditions = [], array $order = [], int $limit = 0)
        {
            return [];
        }
        public function find(int $id)
        {
            return null;
        }
        public function delete()
        {
            return true;
        }
    }
}

class BusinessService
{
}

require_once dirname(__DIR__, 3) . '/app/Services/BusinessServiceManager.php';

/**
 * نسخة اختبارية بتستبدل استعلام الـDB بـ"قاعدة" in-memory بسيطة:
 * $takenSlugs = [businessId => [slug => serviceId, ...]]
 */
class TestableBusinessServiceManager extends BusinessServiceManager
{
    public $takenSlugs = [];

    protected function slugExists(int $businessId, string $slug, ?int $excludeServiceId): bool
    {
        $map = $this->takenSlugs[$businessId] ?? [];
        foreach ($map as $existingSlug => $serviceId) {
            if ($existingSlug === $slug && $serviceId !== $excludeServiceId) {
                return true;
            }
        }
        return false;
    }
}

class BusinessServiceManagerTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nBusiness Service Manager (Phase 25) Tests\n";
        echo "==========================================\n\n";

        $this->testSlugifyEnglish();
        $this->testSlugifyArabic();
        $this->testSlugifyStripsSpecialChars();
        $this->testEmptyNameFallsBackToService();
        $this->testUniqueSlugNoConflict();
        $this->testUniqueSlugAppendsSuffixOnConflict();
        $this->testUniqueSlugExcludesSelfOnUpdate();
        $this->testUniqueSlugScopedPerBusiness();
        $this->testMultipleConflictsIncrementSuffix();

        $this->printSummary();
    }

    private function slugify(string $name): string
    {
        $mgr = new TestableBusinessServiceManager();
        $ref = new ReflectionMethod(BusinessServiceManager::class, 'slugify');
        $ref->setAccessible(true);
        return $ref->invoke($mgr, $name);
    }

    private function testSlugifyEnglish(): void
    {
        $this->startTest('slugify english name');
        $s = $this->slugify('Nile Cruise Tours');
        $s === 'nile-cruise-tours' ? $this->pass('got: ' . $s) : $this->fail('expected nile-cruise-tours, got: ' . $s);
    }

    private function testSlugifyArabic(): void
    {
        $this->startTest('slugify arabic name (keeps unicode letters)');
        $s = $this->slugify('رحلات النيل');
        $s === 'رحلات-النيل' ? $this->pass('got: ' . $s) : $this->fail('expected رحلات-النيل, got: ' . $s);
    }

    private function testSlugifyStripsSpecialChars(): void
    {
        $this->startTest('slugify strips special characters');
        $s = $this->slugify('  Cairo - Giza! @2026  ');
        $s === 'cairo-giza-2026' ? $this->pass('got: ' . $s) : $this->fail('expected cairo-giza-2026, got: ' . $s);
    }

    private function testEmptyNameFallsBackToService(): void
    {
        $this->startTest('empty name falls back to "service" via generateUniqueSlug');
        $mgr = new TestableBusinessServiceManager();
        $slug = $mgr->generateUniqueSlug(1, '   !!! ');
        $slug === 'service' ? $this->pass('got: ' . $slug) : $this->fail('expected service, got: ' . $slug);
    }

    private function testUniqueSlugNoConflict(): void
    {
        $this->startTest('unique slug without conflicts');
        $mgr = new TestableBusinessServiceManager();
        $slug = $mgr->generateUniqueSlug(1, 'Luxor Tours');
        $slug === 'luxor-tours' ? $this->pass('got: ' . $slug) : $this->fail('expected luxor-tours, got: ' . $slug);
    }

    private function testUniqueSlugAppendsSuffixOnConflict(): void
    {
        $this->startTest('conflicting slug appends numeric suffix');
        $mgr = new TestableBusinessServiceManager();
        $mgr->takenSlugs[1] = ['nile-cruise' => 10];
        $slug = $mgr->generateUniqueSlug(1, 'Nile Cruise');
        $slug === 'nile-cruise-2' ? $this->pass('got: ' . $slug) : $this->fail('expected nile-cruise-2, got: ' . $slug);
    }

    private function testUniqueSlugExcludesSelfOnUpdate(): void
    {
        $this->startTest('update excludes the service itself from conflict check');
        $mgr = new TestableBusinessServiceManager();
        $mgr->takenSlugs[1] = ['nile-cruise' => 10];
        $slug = $mgr->generateUniqueSlug(1, 'Nile Cruise', 10);
        $slug === 'nile-cruise' ? $this->pass('got: ' . $slug) : $this->fail('expected nile-cruise (self excluded), got: ' . $slug);
    }

    private function testUniqueSlugScopedPerBusiness(): void
    {
        $this->startTest('uniqueness is scoped per business');
        $mgr = new TestableBusinessServiceManager();
        $mgr->takenSlugs[1] = ['nile-cruise' => 10];
        $slugForBusiness1 = $mgr->generateUniqueSlug(1, 'Nile Cruise');
        $slugForBusiness2 = $mgr->generateUniqueSlug(2, 'Nile Cruise');
        $ok = $slugForBusiness1 === 'nile-cruise-2' && $slugForBusiness2 === 'nile-cruise';
        $ok ? $this->pass('business 1: ' . $slugForBusiness1 . ', business 2: ' . $slugForBusiness2) : $this->fail('scope broken');
    }

    private function testMultipleConflictsIncrementSuffix(): void
    {
        $this->startTest('suffix increments past multiple conflicts');
        $mgr = new TestableBusinessServiceManager();
        $mgr->takenSlugs[1] = ['hotel' => 1, 'hotel-2' => 2, 'hotel-3' => 3];
        $slug = $mgr->generateUniqueSlug(1, 'Hotel');
        $slug === 'hotel-4' ? $this->pass('got: ' . $slug) : $this->fail('expected hotel-4, got: ' . $slug);
    }

    private function startTest(string $name): void
    {
        echo "\n  > {$name}\n";
    }
    private function pass(string $message): void
    {
        echo "    [PASS] {$message}\n";
        $this->passed++;
    }
    private function fail(string $message): void
    {
        echo "    [FAIL] {$message}\n";
        $this->failed++;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Business Service Manager Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessServiceManagerTest())->runAll();
}
