<?php

/**
 * Tourfecto - Encryption Test
 * اختبارات نظام التشفير
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class EncryptionTest
{
    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;

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
    public function __construct()
    {
        $this->encryption = new Encryption();
    }

    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void
    {
        echo "\n🔐 Encryption Tests\n";
        echo "===================\n\n";

        $this->testEncryption();
        $this->testDecryption();
        $this->testCustomerDataEncryption();
        $this->testSignatureEncryption();
        $this->testDataMasking();
        $this->testKeyRotation();

        $this->printSummary();
    }

    /**
     * اختبار التشفير
     */
    private function testEncryption(): void
    {
        $this->startTest('Encryption');

        try {
            $testData = 'Test Data for Encryption';
            $encrypted = $this->encryption->encrypt($testData);

            if (!empty($encrypted) && $encrypted !== $testData) {
                $this->pass('Encryption successful: ' . substr($encrypted, 0, 30) . '...');
            } else {
                $this->fail('Encryption failed');
            }
        } catch (Exception $e) {
            $this->fail('Encryption error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار فك التشفير
     */
    private function testDecryption(): void
    {
        $this->startTest('Decryption');

        try {
            $testData = 'Test Data for Decryption';
            $encrypted = $this->encryption->encrypt($testData);
            $decrypted = $this->encryption->decrypt($encrypted);

            if ($decrypted === $testData) {
                $this->pass('Decryption successful: data matches original');
            } else {
                $this->fail('Decryption failed: data does not match');
            }
        } catch (Exception $e) {
            $this->fail('Decryption error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار تشفير بيانات العميل
     */
    private function testCustomerDataEncryption(): void
    {
        $this->startTest('Customer Data Encryption');

        try {
            $testData = 'customer@example.com';
            $identifier = '+966500000001';

            $encrypted = $this->encryption->encryptCustomerData($testData, $identifier);
            $decrypted = $this->encryption->decryptCustomerData($encrypted, $identifier);

            if ($decrypted === $testData) {
                $this->pass('Customer data encryption/decryption successful');
            } else {
                $this->fail('Customer data encryption/decryption failed');
            }

            // اختبار مع معرف مختلف
            $wrongIdentifier = '+966500000002';
            $decryptedWrong = $this->encryption->decryptCustomerData($encrypted, $wrongIdentifier);

            if ($decryptedWrong !== $testData) {
                $this->pass('Customer data encryption with wrong identifier failed as expected');
            } else {
                $this->fail('Customer data encryption with wrong identifier should fail');
            }

        } catch (Exception $e) {
            $this->fail('Customer data encryption error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار التشفير مع التوقيع
     */
    private function testSignatureEncryption(): void
    {
        $this->startTest('Encryption with Signature');

        try {
            $testData = 'Data with Integrity Check';
            $salt = 'test_salt_123';

            $encrypted = $this->encryption->encryptWithSignature($testData, $salt);
            $decrypted = $this->encryption->decryptWithSignature($encrypted, $salt);

            if ($decrypted === $testData) {
                $this->pass('Signature encryption/decryption successful');
            } else {
                $this->fail('Signature encryption/decryption failed');
            }

            // اختبار التلاعب بالبيانات
            $tampered = str_replace('Integrity', 'Corrupted', $encrypted);
            try {
                $this->encryption->decryptWithSignature($tampered, $salt);
                $this->fail('Tampered data should fail integrity check');
            } catch (Exception $e) {
                $this->pass('Tampered data detected and rejected');
            }

        } catch (Exception $e) {
            $this->fail('Signature encryption error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار إخفاء البيانات
     */
    private function testDataMasking(): void
    {
        $this->startTest('Data Masking');

        try {
            // اختبار إخفاء البريد الإلكتروني
            $email = 'testuser@example.com';
            $maskedEmail = $this->encryption->maskEmail($email);

            if (strpos($maskedEmail, '*') !== false && strpos($maskedEmail, '@') !== false) {
                $this->pass('Email masking successful: ' . $maskedEmail);
            } else {
                $this->fail('Email masking failed');
            }

            // اختبار إخفاء رقم الهاتف
            $phone = '+966500000001';
            $maskedPhone = $this->encryption->maskPhone($phone);

            if (strpos($maskedPhone, '*') !== false) {
                $this->pass('Phone masking successful: ' . $maskedPhone);
            } else {
                $this->fail('Phone masking failed');
            }

            // اختبار إخفاء النص العام
            $text = 'Confidential Information';
            $maskedText = $this->encryption->maskData($text, 2, 2);

            if (substr($maskedText, 0, 2) === 'Co' && substr($maskedText, -2) === 'on') {
                $this->pass('Data masking successful: ' . $maskedText);
            } else {
                $this->fail('Data masking failed');
            }

        } catch (Exception $e) {
            $this->fail('Data masking error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار تدوير المفاتيح
     */
    private function testKeyRotation(): void
    {
        $this->startTest('Key Rotation');

        try {
            // التحقق من وجود حاجة للتدوير
            $needsRotation = $this->encryption->needsKeyRotation();

            if (is_bool($needsRotation)) {
                $this->pass('Key rotation check successful: ' . ($needsRotation ? 'needs rotation' : 'no rotation needed'));
            } else {
                $this->fail('Key rotation check failed');
            }

            // هذا الاختبار يتطلب كتابة في ملف التكوين، لذا نتحقق فقط من الدالة
            if (method_exists($this->encryption, 'rotateKeys')) {
                $this->pass('Key rotation method exists');
            } else {
                $this->fail('Key rotation method not found');
            }

        } catch (Exception $e) {
            $this->fail('Key rotation error: ' . $e->getMessage());
        }
    }

    /**
     * بدء اختبار
     * @param string $name
     */
    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }

    /**
     * تسجيل نجاح
     * @param string $message
     */
    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    /**
     * تسجيل فشل
     * @param string $message
     */
    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    /**
     * طباعة الملخص
     */
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 Encryption Test Summary\n";
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
    $test = new EncryptionTest();
    $test->runAll();
}
