<?php

/**
 * Tourfecto - Stripe Checkout Service (Booking Engine - Phase 2)
 *
 * مسؤول عن كامل دورة حياة الدفع لحجز:
 *   1) createCheckoutSession(): بياخد حجز pending ويبني Stripe Checkout
 *      Session على المبلغ الفعلي للحجز (مش سعر عميل/كوبون) ويسجّل معاملة
 *      pending في payment_transactions قبل ما يبعت العميل للبوابة.
 *   2) handleWebhook(): بيتحقق من توقيع الـ Webhook من Stripe (HMAC)
 *      وبيلغي/يأكد الحجز وبيحدّث حالة المعاملة - لو التوقيع غلط بيرفض.
 *
 * ملاحظات:
 *   - بدون Stripe SDK - اتصال مباشر بـ REST API (نفس نهج بقية التكاملات).
 *   - الأمان: المفاتيح من .env (STRIPE_API_KEY / STRIPE_WEBHOOK_SECRET) -
 *     مش بتتخزن ولا بتتسجّل في اللوجات.
 *   - كل الأوامر idempotent: إعادة تسليم نفس الـ Webhook مش هتعمل حجز
 *     مزدوج ولا هتفشل (بتتحقق من حالة المعاملة أولاً).
 *
 * @version 1.0.0  @date 2026-08-21
 */
class StripeCheckoutService
{
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';

    private Database $db;

    /** مفتاح Stripe خاص بصاحب النشاط (الفلوس تروح حسابه هو مش حساب المنصة) */
    private ?string $keyOverride = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** استخدم حساب Stripe بتاع صاحب النشاط بدل حساب المنصة */
    public function withApiKey(string $secretKey): self
    {
        $c = clone $this;
        $c->keyOverride = $secretKey;
        return $c;
    }

    public function retrieveRefund(string $refundId): array
    {
        if (!preg_match('/^re_[A-Za-z0-9]+$/', $refundId)) { throw new InvalidArgumentException('Invalid Stripe refund'); }
        return $this->request('GET', '/refunds/' . $refundId, []);
    }

    public function refundCheckout(string $sessionId, float $amount, string $currency, int $refundId): array
    {
        $session = $this->retrieveSession($sessionId);
        if (($session['payment_status'] ?? '') !== 'paid' || strtolower((string) ($session['currency'] ?? '')) !== strtolower($currency)) {
            throw new RuntimeException('Refund original payment not verified');
        }
        $intent = $session['payment_intent'] ?? '';
        if (!is_string($intent) || !preg_match('/^pi_[A-Za-z0-9]+$/', $intent)) { throw new RuntimeException('Missing Stripe PaymentIntent'); }
        return $this->request('POST', '/refunds', [
            'payment_intent' => $intent, 'amount' => self::minorUnits($amount, $currency),
            'metadata' => ['local_refund_id' => $refundId],
        ], 'tourfecto-refund-' . hash('sha256', $sessionId . '|' . $refundId));
    }

    public function usesOperatorKey(): bool
    {
        return $this->keyOverride !== null;
    }

    /** جلب جلسة Checkout (للتحقق من الدفع من غير Webhook - حسابات أصحاب النشاط) */
    /** مهلة دفع الحجز بالدقايق (BOOKING_PAYMENT_WINDOW_MINUTES، افتراضي 60) */
    public static function paymentWindowMinutes(): int
    {
        $v = function_exists('env') ? env('BOOKING_PAYMENT_WINDOW_MINUTES') : null;
        $m = is_numeric($v) ? (int) $v : 60;
        return max(31, min(1440, $m));
    }

    public function retrieveSession(string $sessionId): array
    {
        return $this->request('GET', '/checkout/sessions/' . rawurlencode($sessionId), []);
    }

    /** اختبار إن المفتاح شغّال */
    public function testKey(): array
    {
        return $this->request('GET', '/balance', []);
    }

    /** هل Stripe مُفعّل ومفاتيحه موجودة؟ */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey()) && strpos($this->apiKey(), 'your-') !== 0;
    }

    /**
     * إنشاء جلسة Checkout لشحن محفظة المستخدم عبر بطاقة (Stripe).
     *
     * عند اكتمال الدفع، الـ Webhook (checkout.session.completed) بيحدّث
     * معاملة payment_transactions وبيشحن الرصيد فورًا عبر
     * WalletService::applyCardTopUp() (مش محتاج موافقة أدمن زي الـ
     * IBAN/PayPal اليدويين).
     *
     * @return array { checkout_url, session_id }
     * @throws Exception لو Stripe غير مُفعّل أو فشل إنشاء الجلسة.
     */
    public function createWalletTopUpSession(int $userId, float $amount, string $currency, string $successUrl, string $cancelUrl): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('بوابة الدفع Stripe غير مُفعّلة. أضف STRIPE_API_KEY و STRIPE_ENABLED في .env');
        }
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        if ($successUrl === '' || $cancelUrl === '') {
            throw new Exception('success_url و cancel_url مطلوبين');
        }

        $amountMinor = $this->toMinorUnits($amount, $currency);
        $reference   = 'WTX-' . strtoupper(bin2hex(random_bytes(8)));
        $idempotency = 'wallet-topup-' . $userId . '-' . $reference;

        // افحص إن في معاملة pending موجودة بالفعل بنفس مفتاح الـ idempotency
        $existing = $this->db->query(
            'SELECT * FROM payment_transactions
             WHERE idempotency_key = ? AND status IN ("pending","processing") LIMIT 1',
            [$idempotency]
        );
        if (!empty($existing)) {
            $stored = json_decode($existing[0]['metadata'] ?? '{}', true);
            if (!empty($stored['checkout_url'])) {
                return ['checkout_url' => $stored['checkout_url'], 'session_id' => $existing[0]['gateway_transaction_id']];
            }
        }

        // 1) سجّل المعاملة محليًا (pending) قبل أي اتصال بالبوابة
        $internalId = 'PTX-' . strtoupper(bin2hex(random_bytes(12)));
        $paymentId  = $this->db->query(
            'INSERT INTO payment_transactions
                (internal_transaction_id, user_id, amount, currency, payment_method,
                 gateway, status, reference, metadata, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $internalId, $userId, $amount, strtoupper($currency), 'card',
                'stripe', 'pending', $reference,
                json_encode(['purpose' => 'wallet_topup']), $idempotency,
            ]
        );

        // 2) أنشئ الجلسة عند Stripe
        $session = $this->request('POST', '/checkout/sessions', [
            'mode'                 => 'payment',
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'client_reference_id'  => $reference,
            'metadata[user_id]'    => (string) $userId,
            'metadata[purpose]'    => 'wallet_topup',
            'line_items[0][quantity]'                          => '1',
            'line_items[0][price_data][currency]'              => strtolower($currency),
            'line_items[0][price_data][unit_amount]'           => (string) $amountMinor,
            'line_items[0][price_data][product_data][name]'    => 'Wallet Top-Up',
        ]);

        if (empty($session['id']) || empty($session['url'])) {
            $this->markPaymentFailed($paymentId, 'Stripe returned invalid session payload');
            throw new Exception('تعذر إنشاء جلسة الدفع - استجابة غير صالحة من Stripe');
        }

        // 3) اربط session_id + رابط الدفع بالمعاملة وحدّث الميتاداتا
        $this->db->query(
            'UPDATE payment_transactions
             SET gateway_transaction_id = ?, status = ?, metadata = ?
             WHERE id = ?',
            [$session['id'], 'pending', json_encode([
                'purpose'      => 'wallet_topup',
                'checkout_url' => $session['url'],
            ]), $paymentId]
        );

        return [
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ];
    }

    /**
     * إنشاء جلسة Checkout للحجز.
     *
     * @param int    $userId     صاحب الحساب (يُستخدم لفرض الملكية)
     * @param int    $bookingId  معرّف الحجز
     * @param string $successUrl الرابط اللي بيرجع له العميل بعد الدفع الناجح
     * @param string $cancelUrl  الرابط اللي بيرجع له العميل لو ألغى
     *
     * @return array { checkout_url, session_id }
     * @throws Exception لو Stripe غير مُفعّل، الحجز غير موجود/مش مملوك،
     *                   الحجز مش pending، أو فشل إنشاء الجلسة.
     */
    public function createCheckoutSession(int $userId, int $bookingId, string $successUrl, string $cancelUrl, array $opts = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('بوابة الدفع Stripe غير مُفعّلة. أضف STRIPE_API_KEY و STRIPE_ENABLED في .env');
        }
        if ($successUrl === '' || $cancelUrl === '') {
            throw new Exception('success_url و cancel_url مطلوبين');
        }

        $booking = (new Booking())->findOwned($userId, $bookingId);
        if (!$booking) {
            throw new Exception('الحجز غير موجود');
        }
        $allowed = !empty($opts['allow_confirmed']) ? ['pending', 'confirmed'] : ['pending'];
        if (!in_array($booking->status, $allowed, true)) {
            throw new Exception('لا يمكن الدفع لحجز بحالة ' . $booking->status);
        }

        // $opts (لينك دفع/عربون): amount = جزء من المبلغ، idempotency_key مختلف، metadata إضافية، label للوصف
        $chargeAmount = isset($opts['amount']) ? round((float) $opts['amount'], 2) : (float) $booking->total_amount;
        if ($chargeAmount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        $amountMinor = $this->toMinorUnits($chargeAmount, $booking->currency);
        $reference   = $booking->booking_reference;
        $idempotency = (string) ($opts['idempotency_key'] ?? ('booking-checkout-' . $bookingId . '-' . $reference));
        $extraMeta   = is_array($opts['metadata'] ?? null) ? $opts['metadata'] : [];

        // افحص إن في معاملة pending مسجّلة بالفعل لنفس الحجز/المرجع (idempotent)
        $existing = $this->db->query(
            'SELECT * FROM payment_transactions
             WHERE idempotency_key = ? AND status IN ("pending","processing") LIMIT 1',
            [$idempotency]
        );
        if (!empty($existing)) {
            $stored = json_decode($existing[0]['metadata'] ?? '{}', true);
            if (!empty($stored['checkout_url'])) {
                return ['checkout_url' => $stored['checkout_url'], 'session_id' => $existing[0]['gateway_transaction_id']];
            }
        }

        // 1) سجّل المعاملة محليًا (pending) قبل أي اتصال بالبوابة
        $internalId = 'BTX-' . strtoupper(bin2hex(random_bytes(12)));
        $paymentId  = $this->db->query(
            'INSERT INTO payment_transactions
                (internal_transaction_id, user_id, amount, currency, payment_method,
                 gateway, status, reference, booking_id, metadata, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $internalId, $userId, $chargeAmount, $booking->currency, 'card',
                $this->keyOverride !== null ? 'stripe_operator' : 'stripe', 'pending', $reference, $bookingId,
                json_encode(['booking_reference' => $reference] + $extraMeta), $idempotency,
            ]
        );

        // 2) أنشئ الجلسة عند Stripe
        if ($this->keyOverride !== null) {
            // حساب صاحب النشاط مش بيبعتلنا Webhook - بنتحقق من الدفع بنفسنا لما الزائر يرجع (وبكرون احتياطي)
            $successUrl .= (strpos($successUrl, '?') === false ? '?' : '&') . 'session_id={CHECKOUT_SESSION_ID}';
        }
        $session = $this->request('POST', '/checkout/sessions', array_filter([
            'mode'                 => 'payment',
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'client_reference_id'  => $reference,
            'customer_email'       => $booking->customer_email ?: null,
            'metadata[booking_id]' => (string) $bookingId,
            // مهلة الدفع: بعدها الجلسة بتقفل والكرون بيلغي الحجز ويرجّع المكان (Stripe: 30 دقيقة لـ 24 ساعة)
            'expires_at'           => (string) (time() + 60 * self::paymentWindowMinutes()),
            'line_items[0][quantity]'                              => '1',
            'line_items[0][price_data][currency]'                  => $booking->currency,
            'line_items[0][price_data][unit_amount]'               => (string) $amountMinor,
            'line_items[0][price_data][product_data][name]'        => (string) ($opts['label'] ?? ('Booking ' . $reference)),
        ], fn($v) => $v !== null));

        if (empty($session['id']) || empty($session['url'])) {
            $this->markPaymentFailed($paymentId, 'Stripe returned invalid session payload');
            throw new Exception('تعذر إنشاء جلسة الدفع - استجابة غير صالحة من Stripe');
        }

        // 3) اربط session_id + رابط الدفع بالمعاملة وحدّث الميتاداتا
        $this->db->query(
            'UPDATE payment_transactions
             SET gateway_transaction_id = ?, status = ?, metadata = ?
             WHERE id = ?',
            [$session['id'], 'pending', json_encode([
                'booking_reference' => $reference,
                'checkout_url'      => $session['url'],
            ] + $extraMeta), $paymentId]
        );

        return [
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ];
    }

    /**
     * التحقق من توقيع Webhook (HMAC-SHA256 مع STRIPE_WEBHOOK_SECRET)
     * ونافذة زمنية 5 دقايق ضد هجمات replay.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signed = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * معالجة Webhook من Stripe - تأكيد/إلغاء الحجز وتحديث المعاملة.
     *
     * @return array { handled: bool, event: string, message?: string }
     */
    public function handleWebhook(string $payload, string $signatureHeader): array
    {
        if (!$this->verifyWebhookSignature($payload, $signatureHeader)) {
            throw new Exception('Invalid Stripe webhook signature', 401);
        }

        $event  = json_decode($payload, true);
        $type   = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        switch ($type) {
            case 'checkout.session.completed':
                return $this->handleSessionCompleted($object);

            case 'checkout.session.async_payment_succeeded':
                return $this->handleSessionCompleted($object);

            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                return $this->handleSessionFailed($object);

            default:
                return ['handled' => false, 'event' => $type];
        }
    }

    /**
     * جلسة اكتملت: المبلغ اتقبض فعليًا → فعّل الحجز وسجّل المعاملة succeeded.
     */
    private function handleSessionCompleted(array $session): array
    {
        $sessionId = $session['id'] ?? '';
        $reference = $session['client_reference_id'] ?? '';
        if ($sessionId === '') {
            throw new Exception('Webhook session missing id', 400);
        }

        $tx = $this->findBySessionId($sessionId);
        if (!$tx) {
            // ممكن Stripe توصّل الإيفنت قبل ما التحديث المحلي يلحق؟ البحث بالمرجع
            if ($reference !== '') {
                $tx = $this->findByReference($reference);
            }
            if (!$tx) {
                return ['handled' => false, 'event' => 'checkout.session.completed', 'message' => 'no local transaction'];
            }
        }

        // Idempotent: لو المعاملة succeeded قبل كده، ما تعيدش التأكيد
        if (in_array($tx['status'], ['succeeded', 'partially_refunded', 'refunded'], true)) {
            if (class_exists('PaymentLinkService')) { PaymentLinkService::afterPayment((int) $tx['id']); }
            return ['handled' => true, 'event' => 'checkout.session.completed', 'message' => 'already handled'];
        }

        if (!self::matchesPaidSession($session, $tx)) {
            if (($session['payment_status'] ?? '') !== 'paid') {
                return ['handled' => true, 'message' => 'awaiting verified payment'];
            }
            throw new Exception('Payment amount or currency mismatch', 400);
        }

        $metadata = json_decode($tx['metadata'] ?? '{}', true) ?: [];

        // غرض "شحن محفظة": الدفع اتقبض فعليًا → اشحن الرصيد فورًا
        if (($metadata['purpose'] ?? '') === 'wallet_topup') {
            if (!class_exists('WalletService')) {
                throw new Exception('نظام المحفظة غير متاح');
            }
            // 🔒 مانشحنش غير لو Stripe بيقول إن الفلوس اتدفعت فعلًا (مش "unpaid" لطرق الدفع المؤجلة)
            if (isset($session['payment_status']) && $session['payment_status'] !== 'paid') {
                return ['handled' => true, 'event' => 'checkout.session.completed', 'message' => 'awaiting payment: ' . $session['payment_status']];
            }
            // 🔒 المبلغ اللي اتشحن = اللي Stripe قبضه فعلًا بالدولار، مش اللي متسجّل عندنا
            $credit = (float) $tx['amount'];
            if (isset($session['amount_total'], $session['currency'])) {
                if (strtolower((string) $session['currency']) !== 'usd') {
                    $this->logWarning('Wallet top-up in non-USD currency - needs manual review', ['payment_transaction_id' => $tx['id'], 'currency' => $session['currency']]);
                    return ['handled' => true, 'event' => 'checkout.session.completed', 'message' => 'non-usd top-up held for review'];
                }
                $credit = min($credit, ((int) $session['amount_total']) / 100);
            }
            $topUp = (new WalletService())->applyCardTopUp(
                (int) $tx['user_id'],
                $credit,
                'USD',
                $sessionId,
                (int) $tx['id']
            );
            if (empty($topUp['success'])) {
                // ماتعلّمهاش succeeded: نرجّع خطأ عشان Stripe يعيد إرسال الـ Webhook ونحاول تاني
                throw new Exception('Wallet top-up failed after payment: ' . ($topUp['error'] ?? 'unknown'), 500);
            }
            $this->db->query(
                'UPDATE payment_transactions SET status = ?, gateway_transaction_id = ? WHERE id = ?',
                ['succeeded', $sessionId, $tx['id']]
            );
            return ['handled' => true, 'event' => 'checkout.session.completed', 'wallet_topup' => true];
        }

        $bookingId = (int) ($tx['booking_id'] ?? 0);
        if ($bookingId > 0) {
            (new BookingEngine())->confirmBookingFromPayment($bookingId);
        }

        $this->db->query(
            'UPDATE payment_transactions SET status = ?, gateway_transaction_id = ? WHERE id = ?',
            ['succeeded', $sessionId, $tx['id']]
        );
        if (class_exists('PaymentLinkService')) {
            PaymentLinkService::afterPayment((int) $tx['id']);
        }

        return ['handled' => true, 'event' => 'checkout.session.completed', 'booking_id' => $bookingId];
    }

    /**
     * الجلسة فشلت/انتهت صلاحيتها: خفّض حالة المعاملة، الحجز يفضل pending
     * عشان العميل يقدر يعيد المحاولة بجلسة جديدة.
     */
    private function handleSessionFailed(array $session): array
    {
        $sessionId = $session['id'] ?? '';
        $tx = $this->findBySessionId($sessionId);
        if ($tx && !in_array($tx['status'], ['succeeded', 'refunded'], true)) {
            $this->db->query(
                'UPDATE payment_transactions SET status = ? WHERE id = ?',
                ['failed', $tx['id']]
            );
        }
        return ['handled' => true, 'event' => 'checkout.session.failed'];
    }

    private function findBySessionId(string $sessionId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM payment_transactions WHERE gateway_transaction_id = ? LIMIT 1',
            [$sessionId]
        );
        return $rows[0] ?? null;
    }

    private function findByReference(string $reference): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM payment_transactions WHERE reference = ? AND booking_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$reference]
        );
        return $rows[0] ?? null;
    }

    private function markPaymentFailed(int $paymentId, string $reason): void
    {
        $this->db->query(
            'UPDATE payment_transactions SET status = ?, metadata = ? WHERE id = ?',
            ['failed', json_encode(['error' => $reason]), $paymentId]
        );
    }

    /**
     * طلب HTTP مباشر لـ Stripe (form-encoded مثل ما Stripe بيستقبل).
     * @throws Exception على أخطاء الاتصال أو رفض Stripe (بدون تسريب التفاصيل).
     */
    private function request(string $method, string $path, array $formParams, ?string $idempotencyKey = null): array
    {
        $isGet = strtoupper($method) === 'GET';
        $ch = curl_init(self::STRIPE_API_BASE . $path . ($isGet && $formParams ? '?' . http_build_query($formParams) : ''));
        if (!$isGet) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formParams));
        }
        $headers = ['Authorization: Bearer ' . $this->apiKey(), 'Content-Type: application/x-www-form-urlencoded'];
        if ($idempotencyKey !== null) { $headers[] = 'Idempotency-Key: ' . $idempotencyKey; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new Exception('تعذر الاتصال ببوابة الدفع - حاول تاني');
        }

        $decoded = json_decode((string) $response, true) ?? [];

        if ($httpCode >= 400) {
            $this->logWarning('Stripe API error', [
                'path'   => $path,
                'status' => $httpCode,
                'code'   => $decoded['error']['code'] ?? null,
            ]);
            $message = $decoded['error']['message'] ?? 'Stripe API error';
            throw new Exception('بوابة الدفع رفضت الطلب: ' . $message);
        }

        return $decoded;
    }

    private function apiKey(): string
    {
        if ($this->keyOverride !== null) {
            return $this->keyOverride;
        }
        return defined('STRIPE_API_KEY') ? (string) constant('STRIPE_API_KEY') : (string) ((getenv('STRIPE_API_KEY') ?: (isset($_ENV['STRIPE_API_KEY']) ? (string) $_ENV['STRIPE_API_KEY'] : false)) ?: '');
    }

    private function webhookSecret(): string
    {
        return defined('STRIPE_WEBHOOK_SECRET') ? (string) constant('STRIPE_WEBHOOK_SECRET') : (string) ((getenv('STRIPE_WEBHOOK_SECRET') ?: (isset($_ENV['STRIPE_WEBHOOK_SECRET']) ? (string) $_ENV['STRIPE_WEBHOOK_SECRET'] : false)) ?: '');
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (class_exists('Logger')) {
            Logger::warning($message, $context);
        }
    }

    /**
     * تحويل المبلغ إلى أصغر وحدة نقدية (cents) مع مراعاة العملات صفرية
     * الكسور (JPY مثلاً) لتجنب مضاعفة خاطئة بـ 100.
     */
    public static function minorUnits(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        if (!is_finite($amount) || $amount < 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Invalid amount or currency');
        }
        $zero = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'];
        $integer = in_array($currency, array_merge($zero, ['ISK', 'UGX']), true);
        $factor = in_array($currency, $zero, true) ? 1 : 100;
        $scaled = $amount * ($integer ? 1 : $factor);
        if (abs($scaled - round($scaled)) > 0.000001 || $amount * $factor > PHP_INT_MAX) {
            throw new InvalidArgumentException('Unsupported fractional amount');
        }
        return (int) round($amount * $factor);
    }

    public static function matchesPaidSession(array $session, array $transaction): bool
    {
        if (($session['payment_status'] ?? '') !== 'paid'
            || !isset($session['amount_total'], $session['currency'])
            || strtolower((string) $session['currency']) !== strtolower((string) ($transaction['currency'] ?? ''))) {
            return false;
        }
        try {
            return is_int($session['amount_total']) && $session['amount_total'] === self::minorUnits((float) $transaction['amount'], (string) $transaction['currency']);
        } catch (InvalidArgumentException $e) { return false; }
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        return self::minorUnits($amount, $currency);
    }
}
