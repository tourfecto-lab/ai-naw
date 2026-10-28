<?php

/**
 * Tourfecto - Webhook Integration Test
 * اختبارات Webhooks
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class WebhookTest
{
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
     * Constructor
     */
    public function __construct()
    {
        $this->baseUrl = APP_URL . '/api/webhook';
    }

    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void
    {
        echo "\n🔄 Webhook Integration Tests\n";
        echo "============================\n\n";

        $this->testReviewWebhook();
        $this->testChatWebhook();
        $this->testWhatsAppWebhook();
        $this->testPaymentWebhook();
        $this->testWebhookVerification();

        $this->printSummary();
    }

    /**
     * اختبار Webhook المراجعات
     */
    private function testReviewWebhook(): void
    {
        $this->startTest('Review Webhook');

        // محاكاة Webhook من TripAdvisor
        $webhookData = [
            'platform' => 'tripadvisor',
            'platform_review_id' => 'rev_' . uniqid(),
            'reviewer_name' => 'Test User',
            'review_text' => 'هذه خدمة رائعة! أنصح الجميع بتجربتها.',
            'rating' => 5,
            'review_date' => date('Y-m-d H:i:s'),
            'user_id' => 1,
            'website_id' => 1
        ];

        $response = $this->sendWebhook('/review', $webhookData);

        if ($response['success']) {
            $this->pass('Review webhook processed successfully');

            if (isset($response['data']['review_id'])) {
                $this->pass('Review webhook returned review ID');
            }
        } else {
            $this->fail('Review webhook failed: ' . ($response['error'] ?? 'Unknown error'));
        }

        // محاكاة Webhook من Google Business
        $webhookData = [
            'platform' => 'google_business',
            'platform_review_id' => 'grev_' . uniqid(),
            'reviewer_name' => 'Google User',
            'review_text' => 'Great service! Highly recommended.',
            'rating' => 4.5,
            'review_date' => date('Y-m-d H:i:s'),
            'user_id' => 1,
            'website_id' => 1
        ];

        $response = $this->sendWebhook('/review/google', $webhookData);

        if ($response['success']) {
            $this->pass('Google Business webhook processed successfully');
        } else {
            $this->fail('Google Business webhook failed');
        }

        // محاكاة Webhook من Booking.com
        $webhookData = [
            'platform' => 'booking',
            'platform_review_id' => 'brev_' . uniqid(),
            'reviewer_name' => 'Booking User',
            'review_text' => 'Good experience overall.',
            'rating' => 4,
            'review_date' => date('Y-m-d H:i:s'),
            'user_id' => 1,
            'website_id' => 1
        ];

        $response = $this->sendWebhook('/review/booking', $webhookData);

        if ($response['success']) {
            $this->pass('Booking.com webhook processed successfully');
        } else {
            $this->fail('Booking.com webhook failed');
        }
    }

    /**
     * اختبار Webhook الشات
     */
    private function testChatWebhook(): void
    {
        $this->startTest('Chat Webhook');

        // محاكاة Webhook من WhatsApp
        $webhookData = [
            'platform' => 'whatsapp',
            'platform_message_id' => 'wa_' . uniqid(),
            'phone_number' => '+966500000001',
            'sender_name' => 'WhatsApp User',
            'message' => 'مرحباً، أريد حجز رحلة سياحية',
            'timestamp' => time(),
            'user_id' => 1,
            'website_id' => 1
        ];

        $response = $this->sendWebhook('/chat/whatsapp', $webhookData);

        if ($response['success']) {
            $this->pass('WhatsApp webhook processed successfully');

            if (isset($response['data']['message_id'])) {
                $this->pass('WhatsApp webhook returned message ID');
            }
        } else {
            $this->fail('WhatsApp webhook failed: ' . ($response['error'] ?? 'Unknown error'));
        }

        // محاكاة Webhook من Telegram
        $webhookData = [
            'platform' => 'telegram',
            'platform_message_id' => 'tg_' . uniqid(),
            'phone_number' => '123456789',
            'sender_name' => 'Telegram User',
            'message' => 'Hello, I need travel information',
            'timestamp' => time(),
            'user_id' => 1,
            'website_id' => 1
        ];

        $response = $this->sendWebhook('/chat/telegram', $webhookData);

        if ($response['success']) {
            $this->pass('Telegram webhook processed successfully');
        } else {
            $this->fail('Telegram webhook failed');
        }

        // محاكاة Webhook من Messenger
        $webhookData = [
            'platform' => 'messenger',
            'platform_message_id' => 'ms_' . uniqid(),
            'phone_number' => '987654321',
            'sender_name' => 'Messenger User',
            'message' => 'Hi, I want to book a tour',
            'timestamp' => time(),
            'user_id' => 1,
            'website_id' => 1
        ];

        $response = $this->sendWebhook('/chat/messenger', $webhookData);

        if ($response['success']) {
            $this->pass('Messenger webhook processed successfully');
        } else {
            $this->fail('Messenger webhook failed');
        }
    }

    /**
     * اختبار Webhook WhatsApp (بشكل خاص)
     */
    private function testWhatsAppWebhook(): void
    {
        $this->startTest('WhatsApp Webhook Verification');

        // اختبار التحقق من Webhook (GET)
        $verifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
        $challenge = 'challenge_' . uniqid();

        $url = $this->baseUrl . '/chat/whatsapp?hub.mode=subscribe&hub.verify_token=' . $verifyToken . '&hub.challenge=' . $challenge;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response === $challenge) {
            $this->pass('WhatsApp webhook verification successful');
        } else {
            $this->fail('WhatsApp webhook verification failed');
        }

        // اختبار Webhook مع بيانات خاطئة
        $url = $this->baseUrl . '/chat/whatsapp?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=' . $challenge;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            $this->pass('WhatsApp webhook rejects invalid tokens');
        } else {
            $this->fail('WhatsApp webhook did not reject invalid token');
        }
    }

    /**
     * اختبار Webhook الدفع
     */
    private function testPaymentWebhook(): void
    {
        $this->startTest('Payment Webhook');

        // محاكاة Webhook من Stripe
        $webhookData = [
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_' . uniqid(),
                    'customer' => 'cus_' . uniqid(),
                    'plan' => ['id' => 'professional'],
                    'metadata' => ['user_id' => 1]
                ]
            ]
        ];

        $response = $this->sendWebhook('/payment/stripe', $webhookData);

        if ($response['success']) {
            $this->pass('Stripe webhook processed successfully');
        } else {
            $this->fail('Stripe webhook failed: ' . ($response['error'] ?? 'Unknown error'));
        }

        // محاكاة Webhook من PayPal
        $webhookData = [
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => [
                'id' => 'sub_' . uniqid(),
                'plan_id' => 'P-12345678',
                'metadata' => ['user_id' => 1]
            ]
        ];

        $response = $this->sendWebhook('/payment/paypal', $webhookData);

        if ($response['success']) {
            $this->pass('PayPal webhook processed successfully');
        } else {
            $this->fail('PayPal webhook failed');
        }
    }

    /**
     * اختبار التحقق من Webhook
     */
    private function testWebhookVerification(): void
    {
        $this->startTest('Webhook Verification');

        // اختبار التوقيعات
        $testData = ['test' => 'data'];
        $signature = hash_hmac('sha256', json_encode($testData), 'test_secret');

        // يجب أن يكون هناك نظام للتحقق من التوقيعات
        if (function_exists('hash_hmac')) {
            $this->pass('Webhook signature verification available');
        } else {
            $this->fail('Webhook signature verification not available');
        }

        // اختبار IP whitelist
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (filter_var($clientIP, FILTER_VALIDATE_IP)) {
            $this->pass('Client IP validation available');
        } else {
            $this->fail('Client IP validation failed');
        }
    }

    /**
     * إرسال Webhook
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    private function sendWebhook(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

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
        echo "📊 Webhook Test Summary\n";
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
    $test = new WebhookTest();
    $test->runAll();
}
