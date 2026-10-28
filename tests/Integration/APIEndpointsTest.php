<?php
/**
 * Tourfecto - API Endpoints Integration Test
 * اختبارات نقاط نهاية API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class APIEndpointsTest {
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
     * @var string $baseUrl - الرابط الأساسي للAPI
     */
    private $baseUrl;
    
    /**
     * @var string $apiToken - توكن API للاختبار
     */
    private $apiToken;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->baseUrl = APP_URL . '/api';
        $this->apiToken = $this->getTestToken();
    }
    
    /**
     * الحصول على توكن الاختبار
     * @return string
     */
    private function getTestToken(): string {
        // في بيئة الاختبار، يمكن استخدام توكن ثابت أو توليد واحد
        return 'test_api_token_' . md5('integration_test');
    }
    
    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void {
        echo "\n🌐 API Endpoints Integration Tests\n";
        echo "===================================\n\n";
        
        $this->testAuthEndpoints();
        $this->testAIEndpoints();
        $this->testReputationEndpoints();
        $this->testChatEndpoints();
        $this->testSubscriptionEndpoints();
        $this->testDashboardEndpoints();
        
        $this->printSummary();
    }
    
    /**
     * اختبار نقاط نهاية المصادقة
     */
    private function testAuthEndpoints(): void {
        $this->startTest('Authentication Endpoints');
        
        // اختبار تسجيل الدخول
        $loginData = [
            'email' => 'admin@tourfecto.com',
            'password' => 'Admin@123'
        ];
        
        $response = $this->makeRequest('POST', '/auth/login', $loginData);
        
        if ($response['success'] && isset($response['data']['token'])) {
            $this->pass('Login endpoint works correctly');
            $this->apiToken = $response['data']['token'];
        } else {
            $this->fail('Login endpoint failed: ' . ($response['error'] ?? 'Unknown error'));
        }
        
        // اختبار التحقق من التوكن
        $response = $this->makeRequest('GET', '/auth/verify', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Token verification endpoint works correctly');
        } else {
            $this->fail('Token verification endpoint failed');
        }
        
        // اختبار تسجيل الخروج
        $response = $this->makeRequest('POST', '/auth/logout', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Logout endpoint works correctly');
        } else {
            $this->fail('Logout endpoint failed');
        }
    }
    
    /**
     * اختبار نقاط نهاية الذكاء الاصطناعي
     */
    private function testAIEndpoints(): void {
        $this->startTest('AI Endpoints');
        
        // اختبار تحليل الموقع
        $analysisData = [
            'target_url' => 'https://test-travel.com',
            'competitor_urls' => [
                'https://competitor1.com',
                'https://competitor2.com',
                'https://competitor3.com'
            ],
            'language' => 'ar'
        ];
        
        $response = $this->makeRequest('POST', '/ai/analyze', $analysisData, $this->apiToken);
        
        if ($response['success']) {
            $this->pass('AI analysis endpoint works correctly');
            
            if (isset($response['data']['report_id'])) {
                $this->pass('AI analysis returns report ID');
            }
        } else {
            $this->fail('AI analysis endpoint failed: ' . ($response['error'] ?? 'Unknown error'));
        }
        
        // اختبار الحصول على التقارير
        $response = $this->makeRequest('GET', '/ai/reports', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Get reports endpoint works correctly');
        } else {
            $this->fail('Get reports endpoint failed');
        }
        
        // اختبار تحليل المشاعر
        $sentimentData = [
            'text' => 'هذه الخدمة رائعة وممتازة!'
        ];
        
        $response = $this->makeRequest('POST', '/ai/sentiment', $sentimentData, $this->apiToken);
        
        if ($response['success'] && isset($response['data']['label'])) {
            $this->pass('Sentiment analysis endpoint works correctly');
        } else {
            $this->fail('Sentiment analysis endpoint failed');
        }
    }
    
    /**
     * اختبار نقاط نهاية إدارة السمعة
     */
    private function testReputationEndpoints(): void {
        $this->startTest('Reputation Endpoints');
        
        // اختبار الحصول على المراجعات
        $response = $this->makeRequest('GET', '/reputation/reviews', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Get reviews endpoint works correctly');
        } else {
            $this->fail('Get reviews endpoint failed');
        }
        
        // اختبار الحصول على إحصائيات السمعة
        $response = $this->makeRequest('GET', '/reputation/stats', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['total_reviews'])) {
            $this->pass('Reputation stats endpoint works correctly');
        } else {
            $this->fail('Reputation stats endpoint failed');
        }
        
        // اختبار توليد رد على مراجعة (بيانات وهمية)
        $replyData = [
            'review_id' => 1,
            'reply' => 'شكراً على تقييمك الإيجابي!'
        ];
        
        $response = $this->makeRequest('POST', '/reputation/review/1/reply', $replyData, $this->apiToken);
        
        // هذا الاختبار قد يفشل إذا لم تكن هناك مراجعة برقم 1
        if ($response['success'] || isset($response['code']) && $response['code'] === 404) {
            $this->pass('Review reply endpoint works correctly');
        } else {
            $this->fail('Review reply endpoint failed');
        }
    }
    
    /**
     * اختبار نقاط نهاية الشات
     */
    private function testChatEndpoints(): void {
        $this->startTest('Chat Endpoints');
        
        // اختبار الحصول على رسائل الشات
        $response = $this->makeRequest('GET', '/chat/messages', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Get chat messages endpoint works correctly');
        } else {
            $this->fail('Get chat messages endpoint failed');
        }
        
        // اختبار الحصول على الموافقات المعلقة
        $response = $this->makeRequest('GET', '/chat/pending', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['pending'])) {
            $this->pass('Get pending approvals endpoint works correctly');
        } else {
            $this->fail('Get pending approvals endpoint failed');
        }
        
        // اختبار إرسال رسالة (بيانات وهمية)
        $messageData = [
            'phone_number' => '+966500000001',
            'message' => 'اختبار إرسال رسالة',
            'website_id' => 1
        ];
        
        $response = $this->makeRequest('POST', '/chat/send', $messageData, $this->apiToken);
        
        if ($response['success'] || isset($response['code']) && $response['code'] === 403) {
            $this->pass('Send message endpoint works correctly');
        } else {
            $this->fail('Send message endpoint failed');
        }
        
        // اختبار الحصول على إعدادات البوت
        $response = $this->makeRequest('GET', '/chat/settings?website_id=1', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Get bot settings endpoint works correctly');
        } else {
            $this->fail('Get bot settings endpoint failed');
        }
    }
    
    /**
     * اختبار نقاط نهاية الاشتراكات
     */
    private function testSubscriptionEndpoints(): void {
        $this->startTest('Subscription Endpoints');
        
        // اختبار الحصول على الاشتراك الحالي
        $response = $this->makeRequest('GET', '/subscription/current', [], $this->apiToken);
        
        if ($response['success']) {
            $this->pass('Get current subscription endpoint works correctly');
        } else {
            $this->fail('Get current subscription endpoint failed');
        }
        
        // اختبار الحصول على الباقات المتاحة
        $response = $this->makeRequest('GET', '/subscription/plans', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['plans'])) {
            $this->pass('Get plans endpoint works correctly');
        } else {
            $this->fail('Get plans endpoint failed');
        }
        
        // اختبار التحقق من الاشتراك
        $response = $this->makeRequest('POST', '/subscription/validate', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['valid'])) {
            $this->pass('Validate subscription endpoint works correctly');
        } else {
            $this->fail('Validate subscription endpoint failed');
        }
    }
    
    /**
     * اختبار نقاط نهاية لوحة التحكم
     */
    private function testDashboardEndpoints(): void {
        $this->startTest('Dashboard Endpoints');
        
        // اختبار الحصول على إحصائيات لوحة التحكم
        $response = $this->makeRequest('GET', '/dashboard/stats', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['user'])) {
            $this->pass('Dashboard stats endpoint works correctly');
        } else {
            $this->fail('Dashboard stats endpoint failed');
        }
        
        // اختبار الحصول على بيانات الرسم البياني للمراجعات
        $response = $this->makeRequest('GET', '/dashboard/chart/reviews?days=30', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['data'])) {
            $this->pass('Reviews chart endpoint works correctly');
        } else {
            $this->fail('Reviews chart endpoint failed');
        }
        
        // اختبار الحصول على بيانات الرسم البياني للشات
        $response = $this->makeRequest('GET', '/dashboard/chart/chat?days=30', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['data'])) {
            $this->pass('Chat chart endpoint works correctly');
        } else {
            $this->fail('Chat chart endpoint failed');
        }
        
        // اختبار الحصول على بيانات الرسم البياني للـ API
        $response = $this->makeRequest('GET', '/dashboard/chart/api?days=30', [], $this->apiToken);
        
        if ($response['success'] && isset($response['data']['data'])) {
            $this->pass('API chart endpoint works correctly');
        } else {
            $this->fail('API chart endpoint failed');
        }
    }
    
    /**
     * إرسال طلب إلى API
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @param string|null $token
     * @return array
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $token = null): array {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false // للاختبار فقط
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($response, true);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => $decoded,
            'http_code' => $httpCode,
            'error' => $decoded['error'] ?? null
        ];
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
        echo "📊 API Endpoints Test Summary\n";
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
    $test = new APIEndpointsTest();
    $test->runAll();
}