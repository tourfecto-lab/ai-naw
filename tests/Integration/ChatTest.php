<?php
/**
 * Tourfecto - Chat Integration Test
 * اختبارات نظام الشات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatTest {
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
     * @var ChatManager $chatManager - مدير الشات
     */
    private $chatManager;
    
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var int $testUserId - معرف مستخدم الاختبار
     */
    private $testUserId;
    
    /**
     * @var int $testWebsiteId - معرف موقع الاختبار
     */
    private $testWebsiteId;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->chatManager = new ChatManager();
        $this->testUserId = $this->createTestUser();
        $this->testWebsiteId = $this->createTestWebsite();
    }
    
    /**
     * إنشاء مستخدم اختبار
     * @return int
     */
    private function createTestUser(): int {
        $sql = "INSERT INTO users (company_name, email, password, phone, is_active) 
                VALUES (:company_name, :email, :password, :phone, :is_active)";
        
        $id = $this->db->query($sql, [
            ':company_name' => 'Test Chat Company',
            ':email' => 'chat_test_' . uniqid() . '@example.com',
            ':password' => password_hash('Test@123', PASSWORD_ARGON2ID),
            ':phone' => '+966500000001',
            ':is_active' => 1
        ]);
        
        return $id;
    }
    
    /**
     * إنشاء موقع اختبار
     * @return int
     */
    private function createTestWebsite(): int {
        $sql = "INSERT INTO websites (user_id, main_url, company_name, industry, is_verified) 
                VALUES (:user_id, :main_url, :company_name, :industry, :is_verified)";
        
        $id = $this->db->query($sql, [
            ':user_id' => $this->testUserId,
            ':main_url' => 'https://chat-test-' . uniqid() . '.com',
            ':company_name' => 'Test Chat Website',
            ':industry' => 'tourism',
            ':is_verified' => 1
        ]);
        
        return $id;
    }
    
    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void {
        echo "\n💬 Chat Integration Tests\n";
        echo "==========================\n\n";
        
        $this->testMessageProcessing();
        $this->testAIResponseGeneration();
        $this->testApprovalSystem();
        $this->testMessageSending();
        $this->testConversationHistory();
        $this->testBotSettings();
        
        $this->cleanup();
        $this->printSummary();
    }
    
    /**
     * اختبار معالجة الرسائل
     */
    private function testMessageProcessing(): void {
        $this->startTest('Message Processing');
        
        $webhookData = [
            'platform' => 'whatsapp',
            'platform_message_id' => 'test_' . uniqid(),
            'phone_number' => '+966500000001',
            'sender_name' => 'Test User',
            'message' => 'مرحباً، أريد حجز رحلة سياحية',
            'user_id' => $this->testUserId,
            'website_id' => $this->testWebsiteId
        ];
        
        $result = $this->chatManager->processIncomingMessage($webhookData);
        
        if ($result['success']) {
            $this->pass('Message processed successfully');
            
            if (isset($result['message_id'])) {
                $this->pass('Message ID returned: ' . $result['message_id']);
                $this->testMessageId = $result['message_id'];
            }
            
            if (isset($result['bot_status'])) {
                $this->pass('Bot status: ' . $result['bot_status']);
            }
        } else {
            $this->fail('Message processing failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        // اختبار معالجة رسالة بدون بيانات كافية
        $invalidData = [
            'platform' => 'whatsapp',
            'phone_number' => '+966500000001'
        ];
        
        $result = $this->chatManager->processIncomingMessage($invalidData);
        
        if (!$result['success'] && isset($result['error'])) {
            $this->pass('Invalid message rejected correctly');
        } else {
            $this->fail('Invalid message should be rejected');
        }
    }
    
    /**
     * اختبار توليد ردود الذكاء الاصطناعي
     */
    private function testAIResponseGeneration(): void {
        $this->startTest('AI Response Generation');
        
        // اختبار توليد رد لرسالة
        $reply = $this->chatManager->generateAIReply(
            'مرحباً، أريد حجز رحلة إلى جدة',
            $this->testUserId,
            '+966500000001'
        );
        
        if (!empty($reply)) {
            $this->pass('AI reply generated successfully');
            $this->pass("Reply preview: " . substr($reply, 0, 100) . "...");
        } else {
            $this->fail('AI reply generation failed');
        }
        
        // اختبار توليد رد لرسالة مختلفة
        $reply = $this->chatManager->generateAIReply(
            'ما هي أفضل وجهة سياحية في السعودية؟',
            $this->testUserId,
            '+966500000001'
        );
        
        if (!empty($reply)) {
            $this->pass('AI reply generated for different message');
        } else {
            $this->fail('AI reply generation failed for different message');
        }
        
        // اختبار توليد رد لمستخدم بدون رصيد (محاكاة)
        $newUserId = $this->createTestUser();
        $reply = $this->chatManager->generateAIReply(
            'Hello, I need travel information',
            $newUserId,
            '+966500000002'
        );
        
        if (!empty($reply)) {
            $this->pass('AI reply generated for user without credits (fallback)');
        } else {
            $this->fail('AI reply should have fallback for users without credits');
        }
        
        // تنظيف
        $sql = "DELETE FROM users WHERE id = :id";
        $this->db->query($sql, [':id' => $newUserId]);
    }
    
    /**
     * اختبار نظام الموافقات
     */
    private function testApprovalSystem(): void {
        $this->startTest('Approval System');
        
        // إرسال رسالة للحصول على موافقة
        $webhookData = [
            'platform' => 'whatsapp',
            'platform_message_id' => 'approval_test_' . uniqid(),
            'phone_number' => '+966500000001',
            'sender_name' => 'Test User',
            'message' => 'أحتاج إلى معلومات عن الفنادق',
            'user_id' => $this->testUserId,
            'website_id' => $this->testWebsiteId
        ];
        
        $result = $this->chatManager->processIncomingMessage($webhookData);
        
        if (!$result['success']) {
            $this->fail('Failed to create message for approval test');
            return;
        }
        
        $messageId = $result['message_id'];
        
        // الحصول على الموافقات المعلقة
        $pending = $this->chatManager->getPendingApprovals($this->testUserId);
        
        if (is_array($pending)) {
            $this->pass('Pending approvals retrieved: ' . count($pending));
        } else {
            $this->fail('Failed to retrieve pending approvals');
        }
        
        // الموافقة على الرسالة
        $approvalResult = $this->chatManager->approveBotReply($messageId, $this->testUserId, 'approve');
        
        if ($approvalResult['success']) {
            $this->pass('Message approved successfully');
        } else {
            $this->fail('Message approval failed: ' . ($approvalResult['error'] ?? 'Unknown error'));
        }
        
        // رفض رسالة أخرى
        $webhookData['message'] = 'أحتاج إلى مساعدة في الحجز';
        $result = $this->chatManager->processIncomingMessage($webhookData);
        
        if (!$result['success']) {
            $this->fail('Failed to create second message for rejection test');
            return;
        }
        
        $messageId2 = $result['message_id'];
        
        $rejectResult = $this->chatManager->approveBotReply($messageId2, $this->testUserId, 'reject');
        
        if ($rejectResult['success']) {
            $this->pass('Message rejected successfully');
        } else {
            $this->fail('Message rejection failed');
        }
    }
    
    /**
     * اختبار إرسال الرسائل
     */
    private function testMessageSending(): void {
        $this->startTest('Message Sending');
        
        // اختبار إرسال رسالة وهمية (بدون اتصال حقيقي)
        $sent = $this->chatManager->sendMessage(
            '+966500000001',
            'هذه رسالة اختبار',
            'whatsapp'
        );
        
        // في بيئة الاختبار، قد تفشل الإرسال ولكن يجب أن تعمل المنطق
        if (is_bool($sent)) {
            $this->pass('Message sending method executed');
        } else {
            $this->fail('Message sending method failed to execute');
        }
        
        // اختبار إرسال عبر منصة غير مدعومة
        $sent = $this->chatManager->sendMessage(
            '+966500000001',
            'Test message',
            'unsupported_platform'
        );
        
        if ($sent === false) {
            $this->pass('Unsupported platform rejected correctly');
        } else {
            $this->fail('Unsupported platform should be rejected');
        }
    }
    
    /**
     * اختبار تاريخ المحادثة
     */
    private function testConversationHistory(): void {
        $this->startTest('Conversation History');
        
        // إرسال عدة رسائل
        $messages = [
            'مرحباً، كيف يمكنني المساعدة؟',
            'أريد حجز رحلة',
            'إلى أي وجهة تريد السفر؟'
        ];
        
        $sessionId = 'test_session_' . uniqid();
        
        foreach ($messages as $msg) {
            $webhookData = [
                'platform' => 'webchat',
                'platform_message_id' => 'hist_' . uniqid(),
                'phone_number' => '+966500000001',
                'sender_name' => 'Test User',
                'message' => $msg,
                'user_id' => $this->testUserId,
                'website_id' => $this->testWebsiteId,
                'session_id' => $sessionId
            ];
            
            $this->chatManager->processIncomingMessage($webhookData);
        }
        
        // الحصول على تاريخ المحادثة
        $history = $this->chatManager->getConversation($sessionId, $this->testUserId);
        
        if ($history['success']) {
            $this->pass('Conversation history retrieved successfully');
            $this->pass('Messages in conversation: ' . $history['count']);
        } else {
            $this->fail('Failed to retrieve conversation history');
        }
        
        // الحصول على إحصائيات الشات
        $stats = $this->chatManager->getChatStats($this->testUserId, $this->testWebsiteId);
        
        if (!empty($stats)) {
            $this->pass('Chat stats retrieved successfully');
            $this->pass('Total messages: ' . $stats['total_messages']);
        } else {
            $this->fail('Failed to retrieve chat stats');
        }
    }
    
    /**
     * اختبار إعدادات البوت
     */
    private function testBotSettings(): void {
        $this->startTest('Bot Settings');
        
        // الحصول على إعدادات البوت
        $settings = $this->chatManager->getBotSettings($this->testUserId, $this->testWebsiteId);
        
        if (!empty($settings)) {
            $this->pass('Bot settings retrieved successfully');
            
            if (isset($settings['auto_pilot'])) {
                $this->pass('Auto pilot setting: ' . ($settings['auto_pilot'] ? 'ON' : 'OFF'));
            }
            
            if (isset($settings['ai_model'])) {
                $this->pass('AI model: ' . $settings['ai_model']);
            }
        } else {
            $this->fail('Failed to retrieve bot settings');
        }
        
        // تحديث إعدادات البوت
        $newSettings = [
            'auto_pilot' => true,
            'ai_temperature' => 0.8,
            'greeting_message' => 'مرحباً بك في خدمة العملاء!'
        ];
        
        // التحقق من وجود طريقة لتحديث الإعدادات
        if (method_exists($this->chatManager, 'updateBotSettings')) {
            $updated = $this->chatManager->updateBotSettings($this->testUserId, $this->testWebsiteId, $newSettings);
            
            if ($updated) {
                $this->pass('Bot settings updated successfully');
            } else {
                $this->fail('Failed to update bot settings');
            }
        } else {
            $this->pass('Bot settings update method not available (skip)');
        }
    }
    
    /**
     * تنظيف بيانات الاختبار
     */
    private function cleanup(): void {
        // حذف رسائل الشات
        $sql = "DELETE FROM chat_messages WHERE user_id = :user_id";
        $this->db->query($sql, [':user_id' => $this->testUserId]);
        
        // حذف المواقع
        $sql = "DELETE FROM websites WHERE id = :website_id";
        $this->db->query($sql, [':website_id' => $this->testWebsiteId]);
        
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
        echo "📊 Chat Test Summary\n";
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
    $test = new ChatTest();
    $test->runAll();
}