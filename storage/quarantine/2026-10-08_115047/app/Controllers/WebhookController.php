<?php

/**
 * Tourfecto - Webhook Controller
 * متحكم معالجة الـ Webhooks من المنصات المختلفة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class WebhookController extends Controller
{
    /**
     * @var ReputationManager $reputationManager - مدير السمعة
     */
    private $reputationManager;

    /**
     * @var ChatManager $chatManager - مدير الشات
     */
    private $chatManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->reputationManager = new ReputationManager();
        $this->chatManager = new ChatManager();
    }

    /**
     * نقطة نهاية Webhook الرئيسية
     * POST /api/webhook
     * @param array $params
     * @return array
     */
    public function handle(array $params = []): array
    {
        try {
            $type = $this->get('type');
            $provider = $this->get('provider');

            if (!$type) {
                return $this->error('Webhook type is required', 400);
            }

            switch ($type) {
                case 'review':
                    return $this->handleReviewWebhook($provider);

                case 'chat':
                    return $this->handleChatWebhook($provider);

                case 'subscription':
                    return $this->handleSubscriptionWebhook($provider);

                default:
                    return $this->error('Unknown webhook type: ' . $type, 400);
            }

        } catch (Exception $e) {
            Logger::error('Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Webhook processing failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * معالجة Webhook المراجعات
     * @param string|null $provider
     * @return array
     */
    private function handleReviewWebhook(?string $provider): array
    {
        $provider = $provider ?? 'tripadvisor';

        // تحويل البيانات حسب المزود
        switch ($provider) {
            case 'tripadvisor':
                return $this->handleTripAdvisorWebhook();

            case 'google_business':
                return $this->handleGoogleBusinessWebhook();

            case 'booking':
                return $this->handleBookingWebhook();

            default:
                return $this->error('Unsupported provider: ' . $provider, 400);
        }
    }

    /**
     * معالجة Webhook من TripAdvisor
     * @return array
     */
    private function handleTripAdvisorWebhook(): array
    {
        try {
            $data = $this->all();

            // التحقق من صحة التوقيع
            if (!$this->verifyTripAdvisorSignature($data)) {
                return $this->error('Invalid signature', 401);
            }

            // تحويل البيانات إلى صيغة موحدة
            $reviewData = [
                'platform' => 'tripadvisor',
                'platform_review_id' => $data['review_id'] ?? null,
                'reviewer_name' => $data['reviewer']['name'] ?? null,
                'review_text' => $data['review_text'] ?? '',
                'rating' => $data['rating'] ?? 0,
                'review_date' => $data['review_date'] ?? null,
                'webhook_raw_data' => $data
            ];

            return $this->reputationManager->processWebhook($reviewData);

        } catch (Exception $e) {
            Logger::error('TripAdvisor Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process TripAdvisor webhook', 500);
        }
    }

    /**
     * معالجة Webhook من Google Business
     * @return array
     */
    private function handleGoogleBusinessWebhook(): array
    {
        try {
            $data = $this->all();

            // التحقق من صحة التوقيع
            if (!$this->verifyGoogleSignature($data)) {
                return $this->error('Invalid signature', 401);
            }

            // تحويل البيانات إلى صيغة موحدة
            $reviewData = [
                'platform' => 'google_business',
                'platform_review_id' => $data['reviewId'] ?? null,
                'reviewer_name' => $data['reviewer']['displayName'] ?? null,
                'review_text' => $data['comment'] ?? '',
                'rating' => $data['starRating'] ?? 0,
                'review_date' => $data['createTime'] ?? null,
                'webhook_raw_data' => $data
            ];

            return $this->reputationManager->processWebhook($reviewData);

        } catch (Exception $e) {
            Logger::error('Google Business Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process Google Business webhook', 500);
        }
    }

    /**
     * معالجة Webhook من Booking.com
     * @return array
     */
    private function handleBookingWebhook(): array
    {
        try {
            $data = $this->all();

            // تحويل البيانات إلى صيغة موحدة
            $reviewData = [
                'platform' => 'booking',
                'platform_review_id' => $data['id'] ?? null,
                'reviewer_name' => $data['reviewer']['name'] ?? null,
                'review_text' => $data['review']['text'] ?? '',
                'rating' => $data['review']['score'] ?? 0,
                'review_date' => $data['review']['date'] ?? null,
                'webhook_raw_data' => $data
            ];

            return $this->reputationManager->processWebhook($reviewData);

        } catch (Exception $e) {
            Logger::error('Booking Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process Booking webhook', 500);
        }
    }

    /**
     * معالجة Webhook الشات
     * @param string|null $provider
     * @return array
     */
    private function handleChatWebhook(?string $provider): array
    {
        $provider = $provider ?? 'whatsapp';

        switch ($provider) {
            case 'whatsapp':
                return $this->handleWhatsAppWebhook();

            case 'telegram':
                return $this->handleTelegramWebhook();

            case 'messenger':
                return $this->handleMessengerWebhook();

            default:
                return $this->error('Unsupported chat provider: ' . $provider, 400);
        }
    }

    /**
     * معالجة Webhook من WhatsApp
     * @return array
     */
    private function handleWhatsAppWebhook(): array
    {
        try {
            $data = $this->all();

            // التحقق من صحة التوقيع
            if (!$this->verifyWhatsAppSignature($data)) {
                return $this->error('Invalid signature', 401);
            }

            // استخراج الرسالة من بيانات WhatsApp
            $entry = $data['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value = $changes['value'] ?? null;
            $messages = $value['messages'] ?? [];
            $contacts = $value['contacts'] ?? [];

            if (empty($messages)) {
                return $this->success([], 'No messages to process');
            }

            $message = $messages[0];
            $contact = $contacts[0] ?? [];

            // تحويل البيانات إلى صيغة موحدة
            $chatData = [
                'platform' => 'whatsapp',
                'platform_message_id' => $message['id'] ?? null,
                'phone_number' => $message['from'] ?? '',
                'sender_name' => $contact['profile']['name'] ?? null,
                'message' => $message['text']['body'] ?? '',
                'timestamp' => $message['timestamp'] ?? null,
                'webhook_raw_data' => $data
            ];

            return $this->chatManager->processIncomingMessage($chatData);

        } catch (Exception $e) {
            Logger::error('WhatsApp Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process WhatsApp webhook', 500);
        }
    }

    /**
     * معالجة Webhook من Telegram
     * @return array
     */
    private function handleTelegramWebhook(): array
    {
        try {
            $data = $this->all();
            $message = $data['message'] ?? [];

            $chatData = [
                'platform' => 'telegram',
                'platform_message_id' => $message['message_id'] ?? null,
                'phone_number' => $message['from']['id'] ?? '',
                'sender_name' => $message['from']['first_name'] ?? null,
                'message' => $message['text'] ?? '',
                'timestamp' => $message['date'] ?? null,
                'webhook_raw_data' => $data
            ];

            return $this->chatManager->processIncomingMessage($chatData);

        } catch (Exception $e) {
            Logger::error('Telegram Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process Telegram webhook', 500);
        }
    }

    /**
     * معالجة Webhook من Facebook Messenger
     * @return array
     */
    private function handleMessengerWebhook(): array
    {
        try {
            $data = $this->all();
            $entry = $data['entry'][0] ?? null;
            $messaging = $entry['messaging'][0] ?? null;
            $message = $messaging['message'] ?? [];
            $sender = $messaging['sender'] ?? [];

            $chatData = [
                'platform' => 'messenger',
                'platform_message_id' => $message['mid'] ?? null,
                'phone_number' => $sender['id'] ?? '',
                'sender_name' => null,
                'message' => $message['text'] ?? '',
                'timestamp' => $messaging['timestamp'] ?? null,
                'webhook_raw_data' => $data
            ];

            return $this->chatManager->processIncomingMessage($chatData);

        } catch (Exception $e) {
            Logger::error('Messenger Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process Messenger webhook', 500);
        }
    }

    /**
     * معالجة Webhook الاشتراكات
     * @param string|null $provider
     * @return array
     */
    private function handleSubscriptionWebhook(?string $provider): array
    {
        try {
            $data = $this->all();

            // معالجة Webhook من بوابة الدفع
            switch ($provider) {
                case 'stripe':
                    return $this->handleStripeWebhook($data);

                case 'paypal':
                    return $this->handlePayPalWebhook($data);

                default:
                    return $this->error('Unsupported payment provider: ' . $provider, 400);
            }

        } catch (Exception $e) {
            Logger::error('Subscription Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process subscription webhook', 500);
        }
    }

    /**
     * معالجة Webhook من Stripe
     * @param array $data
     * @return array
     */
    private function handleStripeWebhook(array $data): array
    {
        // التحقق من صحة التوقيع
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if (!$this->verifyStripeSignature($data, $signature)) {
            return $this->error('Invalid signature', 401);
        }

        $eventType = $data['type'] ?? '';
        $object = $data['data']['object'] ?? [];

        switch ($eventType) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                return $this->handleSubscriptionEvent($object);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionCancelled($object);

            case 'invoice.payment_succeeded':
                return $this->handlePaymentSucceeded($object);

            case 'invoice.payment_failed':
                return $this->handlePaymentFailed($object);

            default:
                return $this->success([], 'Event received but not processed: ' . $eventType);
        }
    }

    /**
     * Webhook تتبع تسليم البريد (بند 1): ارتداد/شكوى/سبام من مزوّد SMTP.
     * POST /webhooks/email/delivery-status/{user_id}
     * بدون Auth — التوثيق بتوقيع HMAC/مفتاح سري (عبر verifyDeliverySignature
     * في ContactManagementService). المزوّد بيبقى هو اللي بيكتب الـ user_id
     * في الـ URL، والمفتاح هو الضمان إنه محدش غيره يقدّر يرسل نيابة عنه.
     *
     * ملاحظة مسار: الـ route اتسجّل في app/routes/api.php (الملف المحمّل فعلًا
     * في index.php) وليس app/routes/webhooks.php — الملف ده مش متضمّن في
     * index.php إطلاقًا (بيحمّل web.php + api.php بس) فأي route فيه ميت.
     */
    public function emailDeliveryStatusWebhook(array $params = []): array
    {
        $userId = (int) ($params['user_id'] ?? 0);
        $rawBody = file_get_contents('php://input') ?: '';
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = $_POST ?: [];
        }

        $headers = [
            'x-twilio-email-event-webhook-signature' => $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '',
            'x-twilio-email-event-webhook-timestamp' => $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '',
            'x-postmark-server-token' => $_SERVER['HTTP_X_POSTMARK_SERVER_TOKEN'] ?? '',
            'x-delivery-webhook-secret' => $_SERVER['HTTP_X_DELIVERY_WEBHOOK_SECRET'] ?? '',
        ];

        $result = (new ContactManagementService())->handleDeliveryWebhook($userId, $rawBody, $payload, $headers);
        if (!empty($result['error'])) {
            return $this->error($result['error'], 401);
        }
        return $this->success([
            'handled' => (bool) ($result['handled'] ?? false),
            'type' => $result['type'] ?? '',
            'email' => $result['email'] ?? '',
        ]);
    }

    /**
     * Webhook Stripe لشحن المحفظة (wallet top-up) + تأكيد الحجوزات.
     * مسار موحّد لكل Stripe Checkout Sessions (يحدد الغرض من metadata).
     * بدون Auth - التوثيق بالتوقيع HMAC.
     */
    public function walletStripeWebhook(array $params = []): array
    {
        $payload = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            if (!class_exists('StripeCheckoutService')) {
                return $this->error('نظام الدفع غير متاح', 500);
            }
            $result = (new StripeCheckoutService())->handleWebhook($payload, $signature);
            return $this->success(['handled' => $result['handled'] ?? false, 'event' => $result['event'] ?? '']);
        } catch (Exception $e) {
            $code = $e->getCode() === 401 ? 401 : 500;
            http_response_code($code);
            return $this->error($e->getMessage(), $code);
        }
    }

    /**
     * معالجة Webhook من PayPal
     * @param array $data
     * @return array
     */
    private function handlePayPalWebhook(array $data): array
    {
        // التحقق من صحة التوقيع
        if (!$this->verifyPayPalSignature($data)) {
            return $this->error('Invalid signature', 401);
        }

        $eventType = $data['event_type'] ?? '';
        $resource = $data['resource'] ?? [];

        switch ($eventType) {
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                return $this->handleSubscriptionEvent($resource);

            case 'BILLING.SUBSCRIPTION.CANCELLED':
                return $this->handleSubscriptionCancelled($resource);

            case 'PAYMENT.SALE.COMPLETED':
                return $this->handlePaymentSucceeded($resource);

            case 'PAYMENT.SALE.DENIED':
                return $this->handlePaymentFailed($resource);

            default:
                return $this->success([], 'Event received but not processed: ' . $eventType);
        }
    }

    /**
     * معالجة حدث الاشتراك
     * @param array $data
     * @return array
     */
    private function handleSubscriptionEvent(array $data): array
    {
        $userId = $this->extractUserIdFromMetadata($data);

        if (!$userId) {
            return $this->error('User not found', 404);
        }

        $planName = $this->extractPlanFromSubscription($data);

        if (!$planName) {
            return $this->error('Plan not found', 404);
        }

        // تحديث أو إنشاء الاشتراك
        $sql = "SELECT id FROM subscriptions 
                WHERE user_id = ? 
                AND status = 'active' 
                LIMIT 1";

        $result = $this->db->query($sql, [$userId]);

        if (empty($result)) {
            // إنشاء اشتراك جديد
            Subscription::createSubscription($userId, $planName);
        }

        return $this->success([], 'Subscription updated successfully');
    }

    /**
     * معالجة إلغاء الاشتراك
     * @param array $data
     * @return array
     */
    private function handleSubscriptionCancelled(array $data): array
    {
        $userId = $this->extractUserIdFromMetadata($data);

        if (!$userId) {
            return $this->error('User not found', 404);
        }

        // تحديث حالة الاشتراك
        $sql = "UPDATE subscriptions 
                SET status = 'cancelled' 
                WHERE user_id = ? 
                AND status = 'active'";

        $this->db->query($sql, [$userId]);

        return $this->success([], 'Subscription cancelled successfully');
    }

    /**
     * معالجة نجاح الدفع
     * @param array $data
     * @return array
     */
    private function handlePaymentSucceeded(array $data): array
    {
        $userId = $this->extractUserIdFromMetadata($data);

        if (!$userId) {
            return $this->error('User not found', 404);
        }

        // تحديث تاريخ الفوترة
        $sql = "UPDATE subscriptions 
                SET last_billed_at = NOW(), 
                    next_billing_at = DATE_ADD(NOW(), INTERVAL 1 MONTH) 
                WHERE user_id = ? 
                AND status = 'active'";

        $this->db->query($sql, [$userId]);

        return $this->success([], 'Payment recorded successfully');
    }

    /**
     * معالجة فشل الدفع
     * @param array $data
     * @return array
     */
    private function handlePaymentFailed(array $data): array
    {
        // تسجيل فشل الدفع
        Logger::warning('Payment Failed', ['data' => $data]);

        return $this->success([], 'Payment failure recorded');
    }

    /**
     * استخراج معرف المستخدم من البيانات الوصفية
     * @param array $data
     * @return int|null
     */
    private function extractUserIdFromMetadata(array $data): ?int
    {
        $metadata = $data['metadata'] ?? [];
        return $metadata['user_id'] ?? null;
    }

    /**
     * استخراج اسم الخطة من الاشتراك
     * @param array $data
     * @return string|null
     */
    private function extractPlanFromSubscription(array $data): ?string
    {
        $plan = $data['plan']['id'] ?? $data['plan_id'] ?? null;

        if (!$plan) {
            return null;
        }

        // تحويل معرف الخطة إلى اسم
        $plans = Subscription::getAvailablePlans();
        foreach ($plans as $key => $planData) {
            if (strpos($plan, $key) !== false || $planData['id'] === $plan) {
                return $key;
            }
        }

        return null;
    }

    /**
     * التحقق من توقيع TripAdvisor
     * @param array $data
     * @return bool
     */
    private function verifyTripAdvisorSignature(array $data): bool
    {
        // Fail closed: this legacy handler has no verified provider-specific
        // signature implementation. Never accept an unauthenticated webhook.
        Logger::warning('Rejected legacy TripAdvisor webhook: signature verifier is not configured');
        return false;
    }

    /**
     * التحقق من توقيع Google
     * @param array $data
     * @return bool
     */
    private function verifyGoogleSignature(array $data): bool
    {
        // Fail closed until the exact Google webhook product/signature scheme
        // is configured. Accepting by default would allow forged reviews.
        Logger::warning('Rejected legacy Google webhook: signature verifier is not configured');
        return false;
    }

    /**
     * التحقق من توقيع WhatsApp
     * @param array $data
     * @return bool
     */
    private function verifyWhatsAppSignature(array $data): bool
    {
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (empty($signature)) {
            return false;
        }

        $secret = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
        if ($secret === '') {
            return false;
        }

        $payload = file_get_contents('php://input');
        $computed = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($signature, $computed);
    }

    /**
     * التحقق من توقيع Stripe
     * @param array $data
     * @param string $signature
     * @return bool
     */
    private function verifyStripeSignature(array $data, string $signature): bool
    {
        // This is a legacy subscription handler. The active wallet/booking
        // Stripe route uses StripeCheckoutService, which performs real HMAC
        // verification and idempotent processing. Never trust this fallback.
        Logger::warning('Rejected legacy Stripe webhook: use the verified StripeCheckoutService route');
        return false;
    }

    /**
     * التحقق من توقيع PayPal
     * @param array $data
     * @return bool
     */
    private function verifyPayPalSignature(array $data): bool
    {
        // Fail closed until PayPal's transmission signature verification is
        // implemented for this legacy handler.
        Logger::warning('Rejected legacy PayPal webhook: signature verifier is not configured');
        return false;
    }
}
