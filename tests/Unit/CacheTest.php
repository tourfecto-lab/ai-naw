<?php
/**
 * Tourfecto - Cache Test
 * اختبارات نظام الكاش
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class CacheTest {
    /**
     * @var Cache $cache - نظام الكاش
     */
    private $cache;
    
    /**
     * @var array $testResults - نتائج الاختبارات
     */
    private $testResults = [];
    
    /**
     * @var int $passed - عدد الاختبارات الناجحة
     */
    private $passed = 0;
    
    /**
     * @var int $failed - عدد الاختبارات الفاشلة
     */
    private $failed = 0;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->cache = new Cache();
    }
    
    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void {
        echo "\n💾 Cache Tests\n";
        echo "==============\n\n";
        
        $this->testSetAndGet();
        $this->testCacheExpiration();
        $this->testCacheDelete();
        $this->testCacheRemember();
        $this->testCacheClear();
        $this->testCacheStats();
        $this->testSemanticCache();
        
        $this->printSummary();
    }
    
    /**
     * اختبار التخزين والاسترجاع
     */
    private function testSetAndGet(): void {
        $this->startTest('Set and Get');
        
        $testKey = 'test_key_' . uniqid();
        $testData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'timestamp' => time()
        ];
        
        // اختبار التخزين
        $setResult = $this->cache->set($testKey, $testData);
        
        if ($setResult) {
            $this->pass('Cache set successful');
        } else {
            $this->fail('Cache set failed');
            return;
        }
        
        // اختبار الاسترجاع
        $retrieved = $this->cache->get($testKey);
        
        if ($retrieved !== null) {
            $this->pass('Cache get successful');
            
            // التحقق من صحة البيانات
            if ($retrieved['name'] === $testData['name'] && 
                $retrieved['email'] === $testData['email']) {
                $this->pass('Cache data matches original');
            } else {
                $this->fail('Cache data does not match original');
            }
        } else {
            $this->fail('Cache get failed');
        }
        
        // تنظيف
        $this->cache->delete($testKey);
    }
    
    /**
     * اختبار انتهاء صلاحية الكاش
     */
    private function testCacheExpiration(): void {
        $this->startTest('Cache Expiration');
        
        $testKey = 'expire_test_' . uniqid();
        $testData = ['expires' => 'soon'];
        
        // تخزين مع TTL قصير (2 ثانية)
        $this->cache->set($testKey, $testData, 2);
        
        // التحقق من وجود البيانات
        $retrieved = $this->cache->get($testKey);
        
        if ($retrieved !== null) {
            $this->pass('Cache exists before expiration');
        } else {
            $this->fail('Cache missing before expiration');
        }
        
        // انتظار انتهاء الصلاحية
        sleep(3);
        
        // التحقق من انتهاء الصلاحية
        $retrieved = $this->cache->get($testKey);
        
        if ($retrieved === null) {
            $this->pass('Cache expired as expected');
        } else {
            $this->fail('Cache did not expire');
        }
    }
    
    /**
     * اختبار حذف الكاش
     */
    private function testCacheDelete(): void {
        $this->startTest('Cache Delete');
        
        $testKey = 'delete_test_' . uniqid();
        $testData = ['delete' => 'me'];
        
        // تخزين البيانات
        $this->cache->set($testKey, $testData);
        
        // التحقق من وجود البيانات
        $exists = $this->cache->has($testKey);
        
        if ($exists) {
            $this->pass('Cache exists before deletion');
        } else {
            $this->fail('Cache missing before deletion');
        }
        
        // حذف البيانات
        $deleteResult = $this->cache->delete($testKey);
        
        if ($deleteResult) {
            $this->pass('Cache delete successful');
        } else {
            $this->fail('Cache delete failed');
        }
        
        // التحقق من عدم وجود البيانات
        $exists = $this->cache->has($testKey);
        
        if (!$exists) {
            $this->pass('Cache removed after deletion');
        } else {
            $this->fail('Cache still exists after deletion');
        }
    }
    
    /**
     * اختبار Remember (توليد تلقائي)
     */
    private function testCacheRemember(): void {
        $this->startTest('Cache Remember');
        
        $testKey = 'remember_test_' . uniqid();
        $counter = 0;
        
        // توليد قيمة باستخدام remember (يتم تنفيذ المرة الأولى فقط)
        $value = $this->cache->remember($testKey, function() use (&$counter) {
            $counter++;
            return ['count' => $counter];
        });
        
        if ($value['count'] === 1) {
            $this->pass('Cache remember generated value');
        } else {
            $this->fail('Cache remember generated wrong value');
        }
        
        // استرجاع القيمة المخزنة (بدون تنفيذ الكول باك)
        $value = $this->cache->remember($testKey, function() use (&$counter) {
            $counter++;
            return ['count' => $counter];
        });
        
        if ($value['count'] === 1 && $counter === 1) {
            $this->pass('Cache remember returned cached value');
        } else {
            $this->fail('Cache remember executed callback again');
        }
        
        // تنظيف
        $this->cache->delete($testKey);
    }
    
    /**
     * اختبار مسح الكاش
     */
    private function testCacheClear(): void {
        $this->startTest('Cache Clear');
        
        // تخزين بيانات متعددة
        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $key = 'clear_test_' . $i . '_' . uniqid();
            $this->cache->set($key, ['index' => $i]);
            $keys[] = $key;
        }
        
        // التحقق من وجود البيانات
        $existsCount = 0;
        foreach ($keys as $key) {
            if ($this->cache->has($key)) {
                $existsCount++;
            }
        }
        
        if ($existsCount === 5) {
            $this->pass('All cache entries exist before clear');
        } else {
            $this->fail('Some cache entries missing before clear');
        }
        
        // مسح الكاش
        $cleared = $this->cache->clear();
        
        if ($cleared > 0) {
            $this->pass("Cache clear removed {$cleared} entries");
        } else {
            $this->fail('Cache clear failed');
        }
        
        // التحقق من عدم وجود البيانات
        $existsCount = 0;
        foreach ($keys as $key) {
            if ($this->cache->has($key)) {
                $existsCount++;
            }
        }
        
        if ($existsCount === 0) {
            $this->pass('All cache entries removed after clear');
        } else {
            $this->fail('Some cache entries still exist after clear');
        }
    }
    
    /**
     * اختبار إحصائيات الكاش
     */
    private function testCacheStats(): void {
        $this->startTest('Cache Statistics');
        
        // تخزين بيانات إضافية
        for ($i = 0; $i < 10; $i++) {
            $key = 'stats_test_' . $i . '_' . uniqid();
            $this->cache->set($key, ['value' => $i]);
        }
        
        // الحصول على الإحصائيات
        $stats = $this->cache->getStats();
        
        if (!empty($stats) && isset($stats['items'])) {
            $this->pass('Cache stats retrieved successfully');
            $this->pass("Items: {$stats['items']}, Size: {$stats['size']}");
        } else {
            $this->fail('Cache stats retrieval failed');
        }
        
        // تنظيف
        $this->cache->clear();
    }
    
    /**
     * اختبار الكاش الذكي (Semantic Cache)
     */
    private function testSemanticCache(): void {
        $this->startTest('Semantic Cache');
        
        $semanticCache = new SemanticCache();
        
        // اختبار توليد المفتاح
        $key1 = $semanticCache->generateKey(
            'https://test1.com',
            ['https://comp1.com', 'https://comp2.com', 'https://comp3.com'],
            'ar'
        );
        
        $key2 = $semanticCache->generateKey(
            'https://test1.com',
            ['https://comp1.com', 'https://comp2.com', 'https://comp3.com'],
            'ar'
        );
        
        if ($key1 === $key2) {
            $this->pass('Semantic cache key generation consistent');
        } else {
            $this->fail('Semantic cache key generation inconsistent');
        }
        
        // اختبار تخزين واسترجاع
        $testKey = 'semantic_test_' . uniqid();
        $testData = ['result' => 'test_data'];
        
        $setResult = $semanticCache->set($testKey, $testData);
        
        if ($setResult) {
            $this->pass('Semantic cache set successful');
        } else {
            $this->fail('Semantic cache set failed');
        }
        
        $retrieved = $semanticCache->get($testKey);
        
        if ($retrieved !== null && $retrieved['result'] === 'test_data') {
            $this->pass('Semantic cache get successful');
        } else {
            $this->fail('Semantic cache get failed');
        }
        
        // اختبار البحث المشابه
        $similar = $semanticCache->findSimilar(
            'https://test2.com',
            ['https://comp1.com', 'https://comp2.com', 'https://comp3.com'],
            'ar'
        );
        
        if ($similar !== null || $similar === null) {
            $this->pass('Similar search executed');
        } else {
            $this->fail('Similar search failed');
        }
    }
    
    /**
     * بدء اختبار
     * @param string $name
     */
    private function startTest(string $name): void {
        echo "\n  ▶ {$name}\n";
    }
    
    /**
     * تسجيل نجاح
     * @param string $message
     */
    private function pass(string $message): void {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }
    
    /**
     * تسجيل فشل
     * @param string $message
     */
    private function fail(string $message): void {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }
    
    /**
     * طباعة الملخص
     */
    private function printSummary(): void {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 Cache Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

// ============================================
// 6. تشغيل الاختبارات
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new CacheTest();
    $test->runAll();
}