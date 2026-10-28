<?php
/**
 * Tourfecto - Subscription Integration Test
 * اختبارات نظام الاشتراكات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SubscriptionTest {
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
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var SubscriptionValidator $validator - مدقق الاشتراكات
     */
    private $validator;
    
    /**
     * @var int $testUserId - معرف مستخدم الاختبار
     */
    private $testUserId;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->validator = new SubscriptionValidator();
        $this->testUserId = $this->createTestUser();
    }
    
    /**
     * إنشاء مستخدم اختبار
     * @return int
     */
    private function createTestUser(): int {
        $sql = "INSERT INTO users (company_name, email, password, phone, is_active) 
                VALUES (:company_name, :email, :password, :phone, :is_active)";
        
        $id = $this->db->query($sql, [
            ':company_name' => 'Test Subscription Company',
            ':email' => 'sub_test_' . uniqid() . '@example.com',
            ':password' => password_hash('Test@123', PASSWORD_ARGON2ID),
            ':phone' => '+966500000001',
            ':is_active' => 1
        ]);
        
        return $id;
    }
    
    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void {
        echo "\n📋 Subscription Integration Tests\n";
        echo "==================================\n\n";
        
        $this->testCreateSubscription();
        $this->testValidateSubscription();
        $this->testCreditsManagement();
        $this->testPlanUpgrade();
        $this->testSubscriptionRenewal();
        $this->testSubscriptionCancellation();
        $this->testFeatureAccess();
        
        $this->cleanup();
        $this->printSummary();
    }
    
    /**
     * اختبار إنشاء اشتراك
     */
    private function testCreateSubscription(): void {
        $this->startTest('Create Subscription');
        
        // اختبار إنشاء اشتراك Starter
        $subscription = Subscription::createSubscription($this->testUserId, 'starter', 'monthly');
        
        if ($subscription) {
            $this->pass('Starter subscription created successfully');
            
            // التحقق من البيانات
            if ($subscription->getAttribute('plan_name') === 'starter') {
                $this->pass('Subscription plan name is correct');
            } else {
                $this->fail('Subscription plan name is incorrect');
            }
            
            if ($subscription->getAttribute('status') === 'active') {
                $this->pass('Subscription status is active');
            } else {
                $this->fail('Subscription status is not active');
            }
        } else {
            $this->fail('Failed to create subscription');
        }
        
        // اختبار إنشاء اشتراك Professional
        $subscription = Subscription::createSubscription($this->testUserId, 'professional', 'monthly');
        
        if ($subscription && $subscription->getAttribute('plan_name') === 'professional') {
            $this->pass('Professional subscription created successfully');
        } else {
            $this->fail('Failed to create professional subscription');
        }
    }
    
    /**
     * اختبار التحقق من الاشتراك
     */
    private function testValidateSubscription(): void {
        $this->startTest('Validate Subscription');
        
        // إنشاء اشتراك للتحقق
        Subscription::createSubscription($this->testUserId, 'professional', 'monthly');
        
        $result = $this->validator->validateSubscription($this->testUserId);
        
        if ($result['valid']) {
            $this->pass('Subscription validation passed');
            
            if ($result['plan'] === 'professional') {
                $this->pass('Subscription plan is correct');
            } else {
                $this->fail('Subscription plan is incorrect');
            }
            
            if ($result['days_remaining'] > 0) {
                $this->pass('Subscription has remaining days: ' . $result['days_remaining']);
            } else {
                $this->fail('Subscription has no remaining days');
            }
        } else {
            $this->fail('Subscription validation failed: ' . ($result['message'] ?? 'Unknown error'));
        }
        
        // اختبار التحقق من مستخدم بدون اشتراك
        $newUserId = $this->createTestUser();
        $result = $this->validator->validateSubscription($newUserId);
        
        if (!$result['valid']) {
            $this->pass('Validation correctly rejects user without subscription');
        } else {
            $this->fail('Validation should reject user without subscription');
        }
        
        // تنظيف
        $sql = "DELETE FROM users WHERE id = :id";
        $this->db->query($sql, [':id' => $newUserId]);
    }
    
    /**
     * اختبار إدارة الرصيد
     */
    private function testCreditsManagement(): void {
        $this->startTest('Credits Management');
        
        // إنشاء اشتراك
        Subscription::createSubscription($this->testUserId, 'professional', 'monthly');
        
        // التحقق من رصيد AI
        $aiCheck = $this->validator->checkAICredits($this->testUserId, 10);
        
        if ($aiCheck['available']) {
            $this->pass('AI credits available: ' . $aiCheck['remaining']);
        } else {
            $this->fail('AI credits not available: ' . ($aiCheck['message'] ?? 'Unknown error'));
        }
        
        // استهلاك رصيد AI
        $consumed = $this->validator->consumeAICredits($this->testUserId, 5);
        
        if ($consumed) {
            $this->pass('AI credits consumed successfully');
            
            // التحقق من الرصيد المتبقي
            $aiCheck = $this->validator->checkAICredits($this->testUserId, 10);
            $this->pass('Remaining AI credits: ' . $aiCheck['remaining']);
        } else {
            $this->fail('Failed to consume AI credits');
        }
        
        // اختبار رصيد الشات
        $chatCheck = $this->validator->checkChatCredits($this->testUserId, 10);
        
        if ($chatCheck['available']) {
            $this->pass('Chat credits available: ' . $chatCheck['remaining']);
        } else {
            $this->fail('Chat credits not available');
        }
        
        // اختبار تحليل المنافسين
        $competitorCheck = $this->validator->checkCompetitorAnalysisCredits($this->testUserId);
        
        if ($competitorCheck['available']) {
            $this->pass('Competitor analysis credits available: ' . $competitorCheck['remaining']);
        } else {
            $this->fail('Competitor analysis credits not available');
        }
    }
    
    /**
     * اختبار ترقية الباقة
     */
    private function testPlanUpgrade(): void {
        $this->startTest('Plan Upgrade');
        
        // إنشاء اشتراك Starter
        $subscription = Subscription::createSubscription($this->testUserId, 'starter', 'monthly');
        
        if (!$subscription) {
            $this->fail('Failed to create starter subscription for upgrade test');
            return;
        }
        
        // ترقية إلى Professional
        $upgraded = $subscription->upgrade('professional');
        
        if ($upgraded) {
            $this->pass('Plan upgraded from starter to professional');
            
            // التحقق من تغيير الباقة
            $updatedSubscription = $subscription->find($subscription->getAttribute('id'));
            
            if ($updatedSubscription && $updatedSubscription->getAttribute('plan_name') === 'professional') {
                $this->pass('Plan name updated correctly');
            } else {
                $this->fail('Plan name not updated correctly');
            }
        } else {
            $this->fail('Plan upgrade failed');
        }
        
        // محاولة ترقية إلى باقة غير موجودة
        $invalidUpgrade = $subscription->upgrade('invalid_plan');
        
        if (!$invalidUpgrade) {
            $this->pass('Invalid plan upgrade rejected');
        } else {
            $this->fail('Invalid plan upgrade should be rejected');
        }
    }
    
    /**
     * اختبار تجديد الاشتراك
     */
    private function testSubscriptionRenewal(): void {
        $this->startTest('Subscription Renewal');
        
        // إنشاء اشتراك منتهي
        $sql = "INSERT INTO subscriptions (user_id, plan_name, plan_type, status, price, currency,
                ai_credits, chat_credits, review_credits, competitor_analysis_limit,
                start_date, expiry_date, created_at) 
                VALUES (:user_id, 'starter', 'monthly', 'expired', 49.00, 'USD',
                50, 100, 10, 5,
                DATE_SUB(NOW(), INTERVAL 2 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH), NOW())";
        
        $this->db->query($sql, [':user_id' => $this->testUserId]);
        
        // جلب الاشتراك المنتهي
        $sql = "SELECT * FROM subscriptions WHERE user_id = :user_id AND status = 'expired' LIMIT 1";
        $result = $this->db->query($sql, [':user_id' => $this->testUserId]);
        
        if (empty($result)) {
            $this->fail('Failed to create expired subscription');
            return;
        }
        
        $subscription = new Subscription($result[0]);
        $renewed = $subscription->renew();
        
        if ($renewed) {
            $this->pass('Subscription renewed successfully');
            
            // التحقق من الحالة الجديدة
            $updatedSubscription = $subscription->find($subscription->getAttribute('id'));
            
            if ($updatedSubscription && $updatedSubscription->getAttribute('status') === 'active') {
                $this->pass('Subscription status updated to active');
            } else {
                $this->fail('Subscription status not updated');
            }
        } else {
            $this->fail('Subscription renewal failed');
        }
    }
    
    /**
     * اختبار إلغاء الاشتراك
     */
    private function testSubscriptionCancellation(): void {
        $this->startTest('Subscription Cancellation');
        
        // إنشاء اشتراك نشط
        Subscription::createSubscription($this->testUserId, 'professional', 'monthly');
        
        // جلب الاشتراك النشط
        $sql = "SELECT * FROM subscriptions WHERE user_id = :user_id AND status = 'active' LIMIT 1";
        $result = $this->db->query($sql, [':user_id' => $this->testUserId]);
        
        if (empty($result)) {
            $this->fail('Failed to create active subscription for cancellation test');
            return;
        }
        
        $subscription = new Subscription($result[0]);
        $cancelled = $subscription->cancel();
        
        if ($cancelled) {
            $this->pass('Subscription cancelled successfully');
            
            // التحقق من الحالة الجديدة
            $updatedSubscription = $subscription->find($subscription->getAttribute('id'));
            
            if ($updatedSubscription && $updatedSubscription->getAttribute('status') === 'cancelled') {
                $this->pass('Subscription status updated to cancelled');
            } else {
                $this->fail('Subscription status not updated to cancelled');
            }
        } else {
            $this->fail('Subscription cancellation failed');
        }
    }
    
    /**
     * اختبار الوصول إلى الميزات
     */
    private function testFeatureAccess(): void {
        $this->startTest('Feature Access');
        
        // إنشاء اشتراك Starter
        Subscription::createSubscription($this->testUserId, 'starter', 'monthly');
        
        // اختبار الوصول إلى ميزات Starter
        $canUseAI = $this->validator->canUseFeature($this->testUserId, 'ai_analysis');
        $canUseCompetitor = $this->validator->canUseFeature($this->testUserId, 'competitor_analysis');
        $canUseAutoPilot = $this->validator->canUseFeature($this->testUserId, 'auto_pilot');
        
        if ($canUseAI) {
            $this->pass('Starter plan can use AI analysis');
        } else {
            $this->fail('Starter plan should be able to use AI analysis');
        }
        
        if (!$canUseCompetitor) {
            $this->pass('Starter plan cannot use competitor analysis as expected');
        } else {
            $this->fail('Starter plan should not be able to use competitor analysis');
        }
        
        if (!$canUseAutoPilot) {
            $this->pass('Starter plan cannot use auto pilot as expected');
        } else {
            $this->fail('Starter plan should not be able to use auto pilot');
        }
        
        // ترقية إلى Professional
        $sql = "UPDATE subscriptions SET plan_name = 'professional' WHERE user_id = :user_id AND status = 'active'";
        $this->db->query($sql, [':user_id' => $this->testUserId]);
        
        // اختبار الوصول إلى ميزات Professional
        $canUseCompetitor = $this->validator->canUseFeature($this->testUserId, 'competitor_analysis');
        $canUseAutoPilot = $this->validator->canUseFeature($this->testUserId, 'auto_pilot');
        
        if ($canUseCompetitor) {
            $this->pass('Professional plan can use competitor analysis');
        } else {
            $this->fail('Professional plan should be able to use competitor analysis');
        }
        
        if ($canUseAutoPilot) {
            $this->pass('Professional plan can use auto pilot');
        } else {
            $this->fail('Professional plan should be able to use auto pilot');
        }
    }
    
    /**
     * تنظيف بيانات الاختبار
     */
    private function cleanup(): void {
        // حذف بيانات الاشتراكات
        $sql = "DELETE FROM subscriptions WHERE user_id = :user_id";
        $this->db->query($sql, [':user_id' => $this->testUserId]);
        
        // حذف المستخدم
        $sql = "DELETE FROM users WHERE id = :user_id";
        $this->db->query($sql, [':user_id' => $this->testUserId]);
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
        echo "📊 Subscription Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

// ============================================
// تشغيل الاختبارات
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new SubscriptionTest();
    $test->runAll();
}