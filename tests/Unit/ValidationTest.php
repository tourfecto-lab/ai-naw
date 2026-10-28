<?php
/**
 * Tourfecto - Validation Test
 * اختبارات نظام التحقق من البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ValidationTest {
    /**
     * @var Validator $validator - نظام التحقق
     */
    private $validator;
    
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
        $this->validator = new Validator();
    }
    
    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void {
        echo "\n✅ Validation Tests\n";
        echo "==================\n\n";
        
        $this->testRequired();
        $this->testEmail();
        $this->testNumeric();
        $this->testString();
        $this->testArray();
        $this->testBetween();
        $this->testIn();
        $this->testRegex();
        $this->testAlpha();
        $this->testCustomRules();
        
        $this->printSummary();
    }
    
    /**
     * اختبار التحقق من الحقول المطلوبة
     */
    private function testRequired(): void {
        $this->startTest('Required Validation');
        
        $rules = ['name' => 'required', 'email' => 'required'];
        
        // اختبار البيانات الصحيحة
        $data = ['name' => 'Ahmed', 'email' => 'ahmed@example.com'];
        $result = $this->validator->validate($data, $rules);
        
        if ($result['valid']) {
            $this->pass('Required validation passed for valid data');
        } else {
            $this->fail('Required validation failed for valid data');
        }
        
        // اختبار البيانات الناقصة
        $data = ['name' => 'Ahmed'];
        $result = $this->validator->validate($data, $rules);
        
        if (!$result['valid'] && isset($result['errors']['email'])) {
            $this->pass('Required validation caught missing field');
        } else {
            $this->fail('Required validation missed missing field');
        }
        
        // اختبار الحقول الفارغة
        $data = ['name' => '', 'email' => ''];
        $result = $this->validator->validate($data, $rules);
        
        if (!$result['valid'] && count($result['errors']) === 2) {
            $this->pass('Required validation caught empty fields');
        } else {
            $this->fail('Required validation missed empty fields');
        }
    }
    
    /**
     * اختبار التحقق من البريد الإلكتروني
     */
    private function testEmail(): void {
        $this->startTest('Email Validation');
        
        $rules = ['email' => 'required|email'];
        
        $validEmails = [
            'user@example.com',
            'user.name@example.com',
            'user+filter@example.com',
            'user@sub.example.com'
        ];
        
        $invalidEmails = [
            'user@',
            '@example.com',
            'user@example',
            'user example.com',
            'user@example..com'
        ];
        
        foreach ($validEmails as $email) {
            $data = ['email' => $email];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Valid email accepted: {$email}");
            } else {
                $this->fail("Valid email rejected: {$email}");
            }
        }
        
        foreach ($invalidEmails as $email) {
            $data = ['email' => $email];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Invalid email rejected: {$email}");
            } else {
                $this->fail("Invalid email accepted: {$email}");
            }
        }
    }
    
    /**
     * اختبار التحقق من الأرقام
     */
    private function testNumeric(): void {
        $this->startTest('Numeric Validation');
        
        $rules = ['age' => 'required|numeric|min:18|max:65'];
        
        // اختبار الأرقام الصحيحة
        $validNumbers = [18, 25, 30, 45, 65];
        foreach ($validNumbers as $num) {
            $data = ['age' => $num];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Valid number accepted: {$num}");
            } else {
                $this->fail("Valid number rejected: {$num}");
            }
        }
        
        // اختبار الأرقام غير الصحيحة
        $invalidNumbers = [17, 66, 'abc', '25a', null];
        foreach ($invalidNumbers as $num) {
            $data = ['age' => $num];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Invalid number rejected: " . var_export($num, true));
            } else {
                $this->fail("Invalid number accepted: " . var_export($num, true));
            }
        }
        
        // اختبار الأرقام العشرية
        $rules = ['price' => 'required|numeric|between:10.50,99.99'];
        $data = ['price' => 50.75];
        $result = $this->validator->validate($data, $rules);
        
        if ($result['valid']) {
            $this->pass('Decimal number validation passed');
        } else {
            $this->fail('Decimal number validation failed');
        }
    }
    
    /**
     * اختبار التحقق من النصوص
     */
    private function testString(): void {
        $this->startTest('String Validation');
        
        $rules = ['name' => 'required|string|min_length:3|max_length:50'];
        
        // اختبار النصوص الصحيحة
        $validStrings = ['Ahmed', 'Mohamed', 'Ali Hassan', 'A' . str_repeat('a', 48)];
        foreach ($validStrings as $str) {
            $data = ['name' => $str];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Valid string accepted: {$str}");
            } else {
                $this->fail("Valid string rejected: {$str}");
            }
        }
        
        // اختبار النصوص غير الصحيحة
        $invalidStrings = ['A', str_repeat('a', 51), 123, null];
        foreach ($invalidStrings as $str) {
            $data = ['name' => $str];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Invalid string rejected: " . var_export($str, true));
            } else {
                $this->fail("Invalid string accepted: " . var_export($str, true));
            }
        }
    }
    
    /**
     * اختبار التحقق من المصفوفات
     */
    private function testArray(): void {
        $this->startTest('Array Validation');
        
        $rules = ['items' => 'required|array|min:1|max:5'];
        
        // اختبار المصفوفات الصحيحة
        $validArrays = [
            [1],
            [1, 2, 3],
            ['a', 'b', 'c', 'd'],
            [1, 2, 3, 4, 5]
        ];
        
        foreach ($validArrays as $arr) {
            $data = ['items' => $arr];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Valid array accepted: " . count($arr) . " items");
            } else {
                $this->fail("Valid array rejected: " . count($arr) . " items");
            }
        }
        
        // اختبار المصفوفات غير الصحيحة
        $invalidArrays = [[], [1, 2, 3, 4, 5, 6], 'not_array', null];
        foreach ($invalidArrays as $arr) {
            $data = ['items' => $arr];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Invalid array rejected: " . var_export($arr, true));
            } else {
                $this->fail("Invalid array accepted: " . var_export($arr, true));
            }
        }
    }
    
    /**
     * اختبار التحقق من المدى
     */
    private function testBetween(): void {
        $this->startTest('Between Validation');
        
        // اختبار للأرقام
        $rules = ['score' => 'required|between:0,100'];
        
        $validScores = [0, 50, 100];
        foreach ($validScores as $score) {
            $data = ['score' => $score];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Score {$score} is valid");
            } else {
                $this->fail("Score {$score} is invalid");
            }
        }
        
        $invalidScores = [-1, 101, 150];
        foreach ($invalidScores as $score) {
            $data = ['score' => $score];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Score {$score} is invalid as expected");
            } else {
                $this->fail("Score {$score} is valid but should be invalid");
            }
        }
        
        // اختبار للنصوص (طول النص)
        $rules = ['username' => 'required|between_length:3,10'];
        
        $validStrings = ['abc', 'abcdef', 'abcdefghij'];
        foreach ($validStrings as $str) {
            $data = ['username' => $str];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Username '{$str}' is valid (length: " . strlen($str) . ")");
            } else {
                $this->fail("Username '{$str}' is invalid");
            }
        }
        
        $invalidStrings = ['ab', 'abcdefghijk'];
        foreach ($invalidStrings as $str) {
            $data = ['username' => $str];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Username '{$str}' is invalid as expected");
            } else {
                $this->fail("Username '{$str}' is valid but should be invalid");
            }
        }
    }
    
    /**
     * اختبار التحقق من القيم المسموحة
     */
    private function testIn(): void {
        $this->startTest('In Validation');
        
        $rules = ['status' => 'required|in:pending,active,completed,cancelled'];
        
        $validStatuses = ['pending', 'active', 'completed', 'cancelled'];
        foreach ($validStatuses as $status) {
            $data = ['status' => $status];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Status '{$status}' is valid");
            } else {
                $this->fail("Status '{$status}' is invalid");
            }
        }
        
        $invalidStatuses = ['pendingd', 'activee', 'complete', 'cancel', 'unknown'];
        foreach ($invalidStatuses as $status) {
            $data = ['status' => $status];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Status '{$status}' is invalid as expected");
            } else {
                $this->fail("Status '{$status}' is valid but should be invalid");
            }
        }
    }
    
    /**
     * اختبار التحقق من النمط (Regex)
     */
    private function testRegex(): void {
        $this->startTest('Regex Validation');
        
        // اختبار رقم الهاتف السعودي
        $rules = ['phone' => 'required|regex:/^(05|5)[0-9]{8}$/'];
        
        $validPhones = ['0501234567', '0559876543', '501234567'];
        foreach ($validPhones as $phone) {
            $data = ['phone' => $phone];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Phone '{$phone}' is valid");
            } else {
                $this->fail("Phone '{$phone}' is invalid");
            }
        }
        
        $invalidPhones = ['051234567', '05012345678', '050abc4567', '050123456'];
        foreach ($invalidPhones as $phone) {
            $data = ['phone' => $phone];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Phone '{$phone}' is invalid as expected");
            } else {
                $this->fail("Phone '{$phone}' is valid but should be invalid");
            }
        }
        
        // اختبار الرقم التعريفي (ID)
        $rules = ['id' => 'required|regex:/^[A-Z0-9]{10}$/'];
        
        $data = ['id' => 'ABC1234567'];
        $result = $this->validator->validate($data, $rules);
        
        if ($result['valid']) {
            $this->pass('ID validation passed for valid format');
        } else {
            $this->fail('ID validation failed for valid format');
        }
        
        $data = ['id' => 'ABC123456'];
        $result = $this->validator->validate($data, $rules);
        
        if (!$result['valid']) {
            $this->pass('ID validation failed for invalid format as expected');
        } else {
            $this->fail('ID validation passed for invalid format');
        }
    }
    
    /**
     * اختبار التحقق من الأحرف
     */
    private function testAlpha(): void {
        $this->startTest('Alpha Validation');
        
        $rules = ['name' => 'required|alpha'];
        
        $validNames = ['Ahmed', 'محمد', 'خالد', 'Mohamed'];
        foreach ($validNames as $name) {
            $data = ['name' => $name];
            $result = $this->validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Alpha name '{$name}' is valid");
            } else {
                $this->fail("Alpha name '{$name}' is invalid");
            }
        }
        
        $invalidNames = ['Ahmed123', 'Mohamed Ali', 'خالد@', '123', 'User!'];
        foreach ($invalidNames as $name) {
            $data = ['name' => $name];
            $result = $this->validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Alpha name '{$name}' is invalid as expected");
            } else {
                $this->fail("Alpha name '{$name}' is valid but should be invalid");
            }
        }
        
        // اختبار alpha_num
        $rules = ['username' => 'required|alpha_num'];
        
        $validUsernames = ['Ahmed123', 'Mohamed_123', 'user_1', 'user2'];
        foreach ($validUsernames as $username) {
            // تجاهل الشرطات السفلية لأن alpha_num لا يسمح بها
            if (strpos($username, '_') === false) {
                $data = ['username' => $username];
                $result = $this->validator->validate($data, $rules);
                
                if ($result['valid']) {
                    $this->pass("Alpha_num username '{$username}' is valid");
                } else {
                    $this->fail("Alpha_num username '{$username}' is invalid");
                }
            }
        }
    }
    
    /**
     * اختبار القواعد المخصصة
     */
    private function testCustomRules(): void {
        $this->startTest('Custom Rules');
        
        // إضافة قاعدة مخصصة للتحقق من كلمة المرور
        $customMessages = [
            'password.strong' => 'كلمة المرور يجب أن تحتوي على حرف كبير وحرف صغير ورقم'
        ];
        
        $validator = new Validator($customMessages);
        
        // إضافة قاعدة مخصصة
        $validator->addRule('strong', function($value, $parameter) {
            return preg_match('/[A-Z]/', $value) && 
                   preg_match('/[a-z]/', $value) && 
                   preg_match('/[0-9]/', $value);
        });
        
        $rules = ['password' => 'required|strong'];
        
        $validPasswords = ['Password123', 'StrongP@ss1', 'Abcdefg1'];
        foreach ($validPasswords as $pass) {
            $data = ['password' => $pass];
            $result = $validator->validate($data, $rules);
            
            if ($result['valid']) {
                $this->pass("Custom rule: '{$pass}' is strong");
            } else {
                $this->fail("Custom rule: '{$pass}' is weak but should be strong");
            }
        }
        
        $invalidPasswords = ['password', 'PASSWORD', '123456', 'abc123'];
        foreach ($invalidPasswords as $pass) {
            $data = ['password' => $pass];
            $result = $validator->validate($data, $rules);
            
            if (!$result['valid']) {
                $this->pass("Custom rule: '{$pass}' is weak as expected");
            } else {
                $this->fail("Custom rule: '{$pass}' is strong but should be weak");
            }
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
        echo "📊 Validation Test Summary\n";
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
    $test = new ValidationTest();
    $test->runAll();
}