<?php
/**
 * Tourfecto - Helpers Test
 * اختبارات الدوال المساعدة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class HelpersTest {
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
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void {
        echo "\n🛠️ Helpers Tests\n";
        echo "=================\n\n";
        
        $this->testStringHelpers();
        $this->testArrayHelpers();
        $this->testFormatHelpers();
        $this->testValidationHelpers();
        $this->testURLHelpers();
        $this->testDateTimeHelpers();
        $this->testSecurityHelpers();
        
        $this->printSummary();
    }
    
    /**
     * اختبار دوال النصوص
     */
    private function testStringHelpers(): void {
        $this->startTest('String Helpers');
        
        // اختبار truncate_text
        $text = "هذا نص طويل جداً يجب أن يتم قصه";
        $truncated = truncate_text($text, 10);
        
        if (strlen($truncated) <= 13) { // 10 + '...'
            $this->pass('truncate_text works correctly');
        } else {
            $this->fail('truncate_text failed');
        }
        
        // اختبار slugify
        $slug = slugify('مرحباً بك في Tourfecto');
        
        if (!empty($slug)) {
            $this->pass('slugify works correctly');
        } else {
            $this->fail('slugify failed');
        }
        
        // اختبار sanitize_text
        $dirty = '<script>alert("XSS")</script>Hello';
        $clean = sanitize_text($dirty);
        
        if (strpos($clean, '<script>') === false) {
            $this->pass('sanitize_text removes HTML');
        } else {
            $this->fail('sanitize_text failed to remove HTML');
        }
        
        // اختبار strip_html
        $html = '<p>Hello <strong>World</strong></p>';
        $plain = strip_html($html);
        
        if ($plain === 'Hello World') {
            $this->pass('strip_html works correctly');
        } else {
            $this->fail('strip_html failed');
        }
    }
    
    /**
     * اختبار دوال المصفوفات
     */
    private function testArrayHelpers(): void {
        $this->startTest('Array Helpers');
        
        $testArray = [
            'user' => [
                'name' => 'Ahmed',
                'email' => 'ahmed@example.com',
                'profile' => [
                    'age' => 30,
                    'city' => 'Riyadh'
                ]
            ]
        ];
        
        // اختبار array_get
        $name = array_get($testArray, 'user.name');
        
        if ($name === 'Ahmed') {
            $this->pass('array_get works correctly');
        } else {
            $this->fail('array_get failed');
        }
        
        // اختبار array_get مع قيمة افتراضية
        $notFound = array_get($testArray, 'user.nonexistent', 'default');
        
        if ($notFound === 'default') {
            $this->pass('array_get returns default for missing key');
        } else {
            $this->fail('array_get failed to return default');
        }
        
        // اختبار array_set
        $newArray = array_set($testArray, 'user.profile.country', 'Saudi Arabia');
        
        if (array_get($newArray, 'user.profile.country') === 'Saudi Arabia') {
            $this->pass('array_set works correctly');
        } else {
            $this->fail('array_set failed');
        }
        
        // اختبار array_has
        $hasName = array_has($testArray, 'user.name');
        
        if ($hasName) {
            $this->pass('array_has finds existing key');
        } else {
            $this->fail('array_has failed to find existing key');
        }
        
        $hasNonexistent = array_has($testArray, 'user.nonexistent');
        
        if (!$hasNonexistent) {
            $this->pass('array_has correctly returns false for missing key');
        } else {
            $this->fail('array_has incorrectly returns true for missing key');
        }
        
        // اختبار array_only
        $only = array_only($testArray['user'], ['name', 'email']);
        
        if (isset($only['name']) && isset($only['email']) && !isset($only['profile'])) {
            $this->pass('array_only works correctly');
        } else {
            $this->fail('array_only failed');
        }
        
        // اختبار array_except
        $except = array_except($testArray['user'], ['email']);
        
        if (isset($except['name']) && !isset($except['email'])) {
            $this->pass('array_except works correctly');
        } else {
            $this->fail('array_except failed');
        }
    }
    
    /**
     * اختبار دوال التنسيق
     */
    private function testFormatHelpers(): void {
        $this->startTest('Format Helpers');
        
        // اختبار format_currency
        $formatted = format_currency(1234.56, 'USD');
        
        if (strpos($formatted, '$') !== false) {
            $this->pass('format_currency works correctly');
        } else {
            $this->fail('format_currency failed');
        }
        
        // اختبار format_number
        $formatted = format_number(1234567.89, 2);
        
        if (strpos($formatted, ',') !== false) {
            $this->pass('format_number works correctly');
        } else {
            $this->fail('format_number failed');
        }
        
        // اختبار format_percentage
        $percent = format_percentage(85.5);
        
        if (strpos($percent, '%') !== false) {
            $this->pass('format_percentage works correctly');
        } else {
            $this->fail('format_percentage failed');
        }
        
        // اختبار format_phone
        $phone = format_phone('966500000001', 'international');
        
        if (!empty($phone)) {
            $this->pass('format_phone works correctly');
        } else {
            $this->fail('format_phone failed');
        }
        
        // اختبار format_duration
        $duration = format_duration(3665);
        
        if ($duration === '01:01:05') {
            $this->pass('format_duration works correctly');
        } else {
            $this->fail('format_duration failed, got: ' . $duration);
        }
    }
    
    /**
     * اختبار دوال التحقق
     */
    private function testValidationHelpers(): void {
        $this->startTest('Validation Helpers');
        
        // اختبار validate_email
        $valid = validate_email('test@example.com');
        $invalid = validate_email('invalid-email');
        
        if ($valid && !$invalid) {
            $this->pass('validate_email works correctly');
        } else {
            $this->fail('validate_email failed');
        }
        
        // اختبار validate_url
        $valid = validate_url('https://example.com');
        $invalid = validate_url('invalid-url');
        
        if ($valid && !$invalid) {
            $this->pass('validate_url works correctly');
        } else {
            $this->fail('validate_url failed');
        }
        
        // اختبار validate_phone
        $valid = validate_phone('+966500000001');
        $invalid = validate_phone('123');
        
        if ($valid && !$invalid) {
            $this->pass('validate_phone works correctly');
        } else {
            $this->fail('validate_phone failed');
        }
        
        // اختبار validate_min
        $valid = validate_min(10, 5);
        $invalid = validate_min(3, 5);
        
        if ($valid && !$invalid) {
            $this->pass('validate_min works correctly');
        } else {
            $this->fail('validate_min failed');
        }
        
        // اختبار validate_between
        $valid = validate_between(50, 10, 100);
        $invalid = validate_between(5, 10, 100);
        
        if ($valid && !$invalid) {
            $this->pass('validate_between works correctly');
        } else {
            $this->fail('validate_between failed');
        }
    }
    
    /**
     * اختبار دوال URLs
     */
    private function testURLHelpers(): void {
        $this->startTest('URL Helpers');
        
        // اختبار get_current_url
        $url = get_current_url();
        
        if (!empty($url)) {
            $this->pass('get_current_url works correctly');
        } else {
            $this->fail('get_current_url failed');
        }
        
        // اختبار is_valid_url
        $valid = is_valid_url('https://example.com');
        $invalid = is_valid_url('not-a-url');
        
        if ($valid && !$invalid) {
            $this->pass('is_valid_url works correctly');
        } else {
            $this->fail('is_valid_url failed');
        }
        
        // اختبار is_https
        $isHttps = is_https();
        
        if (is_bool($isHttps)) {
            $this->pass('is_https returns boolean');
        } else {
            $this->fail('is_https does not return boolean');
        }
    }
    
    /**
     * اختبار دوال التاريخ والوقت
     */
    private function testDateTimeHelpers(): void {
        $this->startTest('DateTime Helpers');
        
        // اختبار format_datetime
        $formatted = format_datetime('2026-01-09 14:30:00');
        
        if (!empty($formatted)) {
            $this->pass('format_datetime works correctly');
        } else {
            $this->fail('format_datetime failed');
        }
        
        // اختبار get_time_ago
        $timeAgo = get_time_ago(date('Y-m-d H:i:s', time() - 60));
        
        if (strpos($timeAgo, 'منذ') !== false) {
            $this->pass('get_time_ago works correctly');
        } else {
            $this->fail('get_time_ago failed');
        }
        
        // اختبار get_days_between
        $days = get_days_between('2026-01-01', '2026-01-09');
        
        if ($days === 8) {
            $this->pass('get_days_between works correctly');
        } else {
            $this->fail('get_days_between failed, got: ' . $days);
        }
        
        // اختبار validate_date
        $valid = validate_date('2026-01-09');
        $invalid = validate_date('2026-13-09');
        
        if ($valid && !$invalid) {
            $this->pass('validate_date works correctly');
        } else {
            $this->fail('validate_date failed');
        }
    }
    
    /**
     * اختبار دوال الأمان
     */
    private function testSecurityHelpers(): void {
        $this->startTest('Security Helpers');
        
        // اختبار generate_uuid
        $uuid = generate_uuid();
        
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            $this->pass('generate_uuid generates valid UUID');
        } else {
            $this->fail('generate_uuid generated invalid UUID: ' . $uuid);
        }
        
        // اختبار generate_random_string
        $random = generate_random_string(32);
        
        if (strlen($random) === 32) {
            $this->pass('generate_random_string generates correct length');
        } else {
            $this->fail('generate_random_string generated wrong length: ' . strlen($random));
        }
        
        // اختبار generate_secure_token
        $token = generate_secure_token();
        
        if (!empty($token)) {
            $this->pass('generate_secure_token works correctly');
        } else {
            $this->fail('generate_secure_token failed');
        }
        
        // اختبار get_client_ip
        $ip = get_client_ip();
        
        if (!empty($ip)) {
            $this->pass('get_client_ip works correctly');
        } else {
            $this->fail('get_client_ip failed');
        }
        
        // اختبار is_ajax_request
        $isAjax = is_ajax_request();
        
        if (is_bool($isAjax)) {
            $this->pass('is_ajax_request returns boolean');
        } else {
            $this->fail('is_ajax_request does not return boolean');
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
        echo "📊 Helpers Test Summary\n";
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
    $test = new HelpersTest();
    $test->runAll();
}