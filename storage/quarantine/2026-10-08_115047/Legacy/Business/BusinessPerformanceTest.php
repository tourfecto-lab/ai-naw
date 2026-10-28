<?php

/**
 * Tourfecto - Business Performance Audit Fixes Test (Phase 27)
 * @version 1.0.0
 *
 * بيختبر أن إصلاحات الأداء بتشتغل فعليًا:
 *   - H1: roleOf() بتعمل استعلام واحد لكل (businessId, userId) - النداء
 *     التاني بنفس المفاتيح بيرجع من الكاش من غير استعلامات جديدة.
 *   - H2: getAccessibleBusiness() مش بيعمل استعلام Business تاني بعد
 *     فحص الدور - بيرجع الـBusiness المحمّل من الكاش.
 *
 * بنستخدم stubs معدودة (query counters) عشان نقيس الاستعلامات فعلًا،
 * مش مجرد إننا بنثق إن الكود "مفروض" يشتغل كده.
 */

if (!class_exists('Model')) {
    class Model
    {
        protected $table = '';
        protected $fillable = [];
        protected $hidden = [];
        protected $attrs = [];
        public function __construct(array $data = [])
        {
            $this->attrs = $data;
        }
        public function getAttribute(string $key)
        {
            return $this->attrs[$key] ?? null;
        }
        public function setAttribute(string $key, $value)
        {
            $this->attrs[$key] = $value;
        }
        public function save()
        {
            return true;
        }
        public function toArray(): array
        {
            $out = [];
            foreach ($this->attrs as $k => $v) {
                if (in_array($k, $this->hidden, true)) {
                    continue;
                }
                $out[$k] = $v;
            }
            return $out;
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

// Stubs معدودة: كل استعلام بيزود العدّاد العالمي - عشان نعرف عدد
// الاستعلامات الفعلي اللي عمله الـService في الطلب الواحد.
if (!class_exists('Business')) {
    class Business extends Model
    {
        protected $table = 'businesses';
        protected $fillable = ['id', 'owner_user_id', 'legal_name', 'trade_name'];
        protected static $counters = ['find' => 0, 'where' => 0];
        public function find(int $id)
        {
            self::$counters['find']++;
            return new static(['id' => $id, 'owner_user_id' => 10, 'legal_name' => 'Stub Co', 'trade_name' => 'Stub']);
        }
        public function where(array $conditions = [], array $order = [], int $limit = 0)
        {
            self::$counters['where']++;
            return [];
        }
        public static function resetCounters(): void
        {
            self::$counters = ['find' => 0, 'where' => 0];
        }
        public static function findCount(): int
        {
            return self::$counters['find'];
        }
        public static function whereCount(): int
        {
            return self::$counters['where'];
        }
    }
}
if (!class_exists('BusinessMember')) {
    class BusinessMember extends Model
    {
        protected $table = 'business_members';
        protected $fillable = ['id', 'business_id', 'user_id', 'role', 'status'];
        protected static $counter = 0;
        public function where(array $conditions = [], array $order = [], int $limit = 0)
        {
            self::$counter++;
            // user 11 هو member نشط دور member - أي مستخدم تاني مالهوش وصول.
            if (($conditions['user_id'] ?? null) == 11 && ($conditions['status'] ?? '') === 'active') {
                return [new static(['id' => 1, 'business_id' => $conditions['business_id'], 'user_id' => 11, 'role' => 'member', 'status' => 'active'])];
            }
            return [];
        }
        public static function resetCounter(): void
        {
            self::$counter = 0;
        }
        public static function count(): int
        {
            return self::$counter;
        }
    }
}

require_once dirname(__DIR__, 3) . '/app/Services/BusinessAccessService.php';

class BusinessPerformanceTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nBusiness Performance Audit Fixes (Phase 27) Tests\n";
        echo "===================================================\n\n";

        $this->testRoleOfSingleQueryPerPair();
        $this->testGetAccessibleBusinessNoDuplicateBusinessQuery();
        $this->testGetAccessibleBusinessReturnsLoadedBusiness();

        $this->printSummary();
    }

    private function testRoleOfSingleQueryPerPair(): void
    {
        $this->startTest('H1: roleOf() query once, cached on repeat (same pair)');
        Business::resetCounters();
        BusinessMember::resetCounter();
        $svc = new BusinessAccessService();

        $first = $svc->roleOf(1, 11);
        $queriesAfterFirst = Business::findCount() + BusinessMember::count();

        $second = $svc->roleOf(1, 11);
        $queriesAfterSecond = Business::findCount() + BusinessMember::count();

        $ok = $first === 'member'
            && $queriesAfterFirst === 2
            && $queriesAfterSecond === $queriesAfterFirst;
        $ok ? $this->pass("first call={$queriesAfterFirst} queries, second call added 0 (total={$queriesAfterSecond})")
            : $this->fail("expected no repeat queries, total went {$queriesAfterFirst} -> {$queriesAfterSecond}");
    }

    private function testGetAccessibleBusinessNoDuplicateBusinessQuery(): void
    {
        $this->startTest('H2: getAccessibleBusiness() reuses business from role check');
        Business::resetCounters();
        BusinessMember::resetCounter();
        $svc = new BusinessAccessService();

        $business = $svc->getAccessibleBusiness(1, 11);
        $findCalls = Business::findCount();
        $whereCalls = BusinessMember::count();

        $ok = $business !== null && $findCalls === 1 && $whereCalls === 1;
        $ok ? $this->pass("business fetched once (find={$findCalls}, members={$whereCalls})")
            : $this->fail("expected 1+1 queries, got find={$findCalls} members={$whereCalls}");
    }

    private function testGetAccessibleBusinessReturnsLoadedBusiness(): void
    {
        $this->startTest('H2: returned Business carries correct owner data');
        Business::resetCounters();
        BusinessMember::resetCounter();
        $svc = new BusinessAccessService();

        $business = $svc->getAccessibleBusiness(1, 11);
        $ok = $business !== null
            && (int) $business->getAttribute('id') === 1
            && (int) $business->getAttribute('owner_user_id') === 10;
        $ok ? $this->pass('returned business id=1 owner=10') : $this->fail('returned wrong business');
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
        echo "\n" . str_repeat('=', 55) . "\n";
        echo "Business Performance Test Summary\n";
        echo str_repeat('=', 55) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 55) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessPerformanceTest())->runAll();
}
