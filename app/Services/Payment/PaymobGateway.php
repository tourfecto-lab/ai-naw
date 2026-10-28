<?php

/**
 * Tourfecto - Paymob Gateway (Booking Engine - Phase 2)
 *
 * بوابة دفع ثانية جنب Stripe بنفس توقيعات الـ methods بالحرف
 * (isConfigured / createCheckoutSession / verifyWebhookSignature /
 * handleWebhook) عشان الاتنين يبقوا قابلين للاستبدال من نفس نقطة
 * الاستدعاء في BookingController.
 *
 * دورة حياة الدفع لحجز:
 *   1) createCheckoutSession(): بياخد حجز pending ويبني جلسة Paymob
 *      (auth token → order → payment key → iframe URL) على المبلغ الفعلي
 *      للحجز ويسجّل معاملة pending في payment_transactions قبل إرسال
 *      العميل للبوابة.
 *   2) handleWebhook(): بيتحقق من توقيع الـ Webhook من Paymob (HMAC
 *      SHA-512 عبر query param hmac) ويأكد/يفشل الحجز ويحدّث حالة
 *      المعاملة - لو التوقيع غلط بيرفض.
 *
 * ملاحظات:
 *   - بدون SDK - اتصال مباشر بـ REST API (نفس نهج StripeCheckoutService).
 *   - الأمان: المفاتيح من .env (PAYMOB_API_KEY / PAYMOB_INTEGRATION_ID /
 *     PAYMOB_IFRAME_ID / PAYMOB_HMAC_SECRET) - مش بتتخزن ولا بتتسجّل.
 *   - كل الأوامر idempotent: إعادة تسليم نفس الـ Webhook مش هتعمل حجز
 *     مزدوج ولا هتفشل (بتتحقق من حالة المعاملة أولاً).
 *
 * @version 1.0.0  @date 2026-08-25
 */
class PaymobGateway
{
    private const PAYMOB_API_BASE = 'https://accept.paymob.com/api';

    private Database $db;

    private $transport;
    public function __construct(?Database $db = null, ?callable $transport = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->transport = $transport;
    }

    /** مفتاح البوابة في payment_transactions.gateway */
    public function key(): string
    {
        return 'paymob';
    }

    /** هل Paymob مُفعّل ومفاتيحه موجودة؟ */
    public function isConfigured(): bool
    {
        $key = $this->apiKey();
        return $key !== ''
            && strpos($key, 'your-') !== 0
            && $this->integrationId() > 0
            && $this->iframeId() > 0;
    }

    /**
     * إنشاء جلسة Checkout للحجز (نفس توقيع StripeCheckoutService بالحرف).
     *
     * @param int    $userId     صاحب الحساب (يُستخدم لفرض الملكية)
     * @param int    $bookingId  معرّف الحجز
     * @param string $successUrl الرابط اللي بيرجع له العميل بعد الدفع الناجح
     * @param string $cancelUrl  الرابط اللي بيرجع له العميل لو ألغى
     *
     * @return array { checkout_url, session_id }
     * @throws Exception لو Paymob غير مُفعّل، الحجز غير موجود/مش مملوك،
     *                   الحجز مش pending، أو فشل إنشاء الجلسة.
     */
    public function createCheckoutSession(int $userId, int $bookingId, string $successUrl, string $cancelUrl): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('بوابة الدفع Paymob غير مُفعّلة. أضف PAYMOB_API_KEY و PAYMOB_INTEGRATION_ID و PAYMOB_IFRAME_ID في .env');
        }
        if ($successUrl === '' || $cancelUrl === '') {
            throw new Exception('success_url و cancel_url مطلوبين');
        }

        $booking = (new Booking())->findOwned($userId, $bookingId);
        if (!$booking) {
            throw new Exception('الحجز غير موجود');
        }
        if ($booking->status !== 'pending') {
            throw new Exception('لا يمكن الدفع لحجز بحالة ' . $booking->status);
        }

        $amountMinor = $this->toMinorUnits((float) $booking->total_amount, $booking->currency);
        $reference   = $booking->booking_reference;
        $idempotency = 'booking-checkout-paymob-' . $bookingId . '-' . $reference;

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
        $internalId = 'PTX-' . strtoupper(bin2hex(random_bytes(12)));
        $paymentId  = $this->db->query(
            'INSERT INTO payment_transactions
                (internal_transaction_id, user_id, amount, currency, payment_method,
                 gateway, status, reference, booking_id, metadata, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $internalId, $userId, $booking->total_amount, $booking->currency, 'card',
                'paymob', 'pending', $reference, $bookingId,
                json_encode(['booking_reference' => $reference]), $idempotency,
            ]
        );

        // 2) أنشئ الجلسة عند Paymob (token → order → payment key → iframe)
        try {
            $checkoutUrl = $this->buildCheckoutUrl(
                $booking,
                $amountMinor,
                $reference,
                $successUrl,
                $cancelUrl
            );
        } catch (Exception $e) {
            $this->markPaymentFailed($paymentId, 'Paymob session creation failed');
            throw $e;
        }

        if ($checkoutUrl === '') {
            $this->markPaymentFailed($paymentId, 'Paymob returned invalid session payload');
            throw new Exception('تعذر إنشاء جلسة الدفع - استجابة غير صالحة من Paymob');
        }

        // 3) اربط session_id (payment token) + رابط الدفع بالمعاملة وحدّث الميتاداتا
        $this->db->query(
            'UPDATE payment_transactions
             SET gateway_transaction_id = ?, status = ?, metadata = ?
             WHERE id = ?',
            [$checkoutUrl['token'], 'pending', json_encode([
                'booking_reference' => $reference,
                'checkout_url'      => $checkoutUrl['url'],
                'paymob_order_id' => (string)$checkoutUrl['order_id'],
            ]), $paymentId]
        );

        return [
            'checkout_url' => $checkoutUrl['url'],
            'session_id'   => $checkoutUrl['token'],
        ];
    }

    /**
     * التحقق من توقيع Webhook من Paymob (HMAC-SHA512 بمفتاح
     * PAYMOB_HMAC_SECRET). `$signatureHeader` هنا هو قيمة `hmac` اللي
     * بيبعتتها Paymob كـ query param في طلب الـ Webhook.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $secret = $this->hmacSecret();
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return false;
        }

        $data = $data['obj'] ?? $data;
        if (!is_array($data)) { return false; }
        $concatenated = '';
        foreach ($this->hmacKeys() as $key) {
            $value = $this->getValueByKey($data, $key);
            if ($value === null || is_array($value) || is_object($value)) { return false; }
            $concatenated .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $expected = hash_hmac('sha512', $concatenated, $secret);
        return hash_equals($expected, strtolower($signatureHeader));
    }

    /**
     * معالجة Webhook من Paymob - تأكيد/إخفاق الحجز وتحديث المعاملة.
     *
     * @return array { handled: bool, event: string, message?: string }
     */
    public function handleWebhook(string $payload, string $signatureHeader): array
    {
        if (!$this->verifyWebhookSignature($payload, $signatureHeader)) {
            throw new Exception('Invalid Paymob webhook signature', 401);
        }

        $envelope = json_decode($payload, true);
        $data = $envelope['obj'] ?? $envelope;
        // Refund, void and authorisation events cannot be interpreted as captured payments.
        if (!empty($data['is_refunded']) || !empty($data['is_voided']) || (!empty($data['has_parent_transaction']) && empty($data['is_capture'])) || (!empty($data['is_auth']) && empty($data['is_capture']))) {
            return ['handled' => false, 'event' => 'non_payment'];
        }
        if (!empty($data['pending'])) { return ['handled' => false, 'event' => 'pending']; }
        $transactionId = (string) ($data['id'] ?? '');
        $reference = (string) ($data['order']['merchant_order_id'] ?? '');
        if ($transactionId === '') { throw new RuntimeException('Missing transaction reference'); }
        $tx = $this->findByGatewayTx($transactionId);
        if (!$tx && $reference !== '') { $tx = $this->findByReference($reference); }
        if (!$tx) { return ['handled'=>false,'event'=>'transaction.response']; }
        $meta=json_decode((string)($tx['metadata']??'{}'),true) ?: [];
        if (empty($meta['paymob_order_id']) || (string)$meta['paymob_order_id'] !== (string)($data['order']['id']??'')) { throw new RuntimeException('Payment order does not match'); }
        if ((string)$tx['gateway'] !== 'paymob' || strtoupper((string)($data['currency'] ?? '')) !== strtoupper((string)$tx['currency']) || !isset($data['amount_cents']) || (string)$this->toMinorUnits((float)$tx['amount'], (string)$tx['currency']) !== (string)$data['amount_cents'] || (int)($data['integration_id'] ?? 0) !== $this->integrationId()) {
            throw new RuntimeException('Payment details do not match');
        }
        if (($data['success'] ?? false) === true && empty($data['error_occured'])) {
            $this->db->transaction(function () use ($tx,$transactionId) {
                $bookingId=(int)($tx['booking_id'] ?? 0);
                $bookingRow=$bookingId>0 ? ($this->db->query('SELECT id,status FROM bookings WHERE id=? FOR UPDATE',[$bookingId])[0]??null) : null;
                if($bookingId>0 && !$bookingRow)throw new RuntimeException('Missing booking');
                $fresh=$this->db->query('SELECT * FROM payment_transactions WHERE id=? FOR UPDATE',[$tx['id']])[0] ?? null;
                if (!$fresh) { throw new RuntimeException('Missing payment'); }
                if (in_array($fresh['status'],['succeeded','partially_refunded','refunded'],true)) { return; }
                if ($bookingId > 0 && $bookingRow['status']!=='cancelled') { (new BookingEngine())->confirmBookingFromPayment($bookingId); }
                $this->db->exec('UPDATE payment_transactions SET status=?,gateway_transaction_id=? WHERE id=?',['succeeded',$transactionId,$tx['id']]);
            });
            PaymentLinkService::afterPayment((int)$tx['id']);
            return ['handled'=>true,'event'=>'transaction.response','booking_id'=>(int)($tx['booking_id']??0)];
        }
        $this->db->exec("UPDATE payment_transactions SET status='failed',gateway_transaction_id=? WHERE id=? AND status IN ('pending','processing')",[$transactionId,$tx['id']]);
        return ['handled'=>true,'event'=>'transaction.response'];
    }

    public function isRefundConfigured(): bool
    {
        $secret=(string)(getenv('PAYMOB_SECRET_KEY') ?: ($_ENV['PAYMOB_SECRET_KEY']??''));
        return $secret!=='' && !str_starts_with($secret,'your-');
    }
    public function refundTransaction(string $transactionId, float $amount, string $currency): array
    {
        if (!ctype_digit($transactionId) || (int)$transactionId < 1) { throw new InvalidArgumentException('Invalid payment reference'); }
        $secret=(string)(getenv('PAYMOB_SECRET_KEY') ?: ($_ENV['PAYMOB_SECRET_KEY'] ?? ''));
        if ($secret === '') { throw new RuntimeException('Paymob refund credentials are not configured'); }
        $minor=$this->toMinorUnits($amount,$currency);
        $reply=$this->request('POST','/acceptance/void_refund/refund',['transaction_id'=>(int)$transactionId,'amount_cents'=>$minor],['Authorization: Token '.$secret]);
        return $this->refundOutcome($reply,$transactionId,$minor,$currency);
    }
    public function retrieveRefund(string $reference, string $transactionId, float $amount, string $currency): array
    {
        if (!ctype_digit($reference)) { throw new InvalidArgumentException('Invalid refund reference'); }
        $auth=$this->request('POST','/auth/tokens',['api_key'=>$this->apiKey()]);
        if (empty($auth['token'])) { throw new RuntimeException('Paymob authentication failed'); }
        $reply=$this->request('GET','/acceptance/transactions/'.$reference,[],['Authorization: Bearer '.$auth['token']]);
        return $this->refundOutcome($reply,$transactionId,$this->toMinorUnits($amount,$currency),$currency);
    }
    public function refundOutcome(array $reply, string $transactionId, int $minor, string $currency): array
    {
        $parent=$reply['parent_transaction'] ?? null;
        if (is_array($parent)) { $parent=$parent['id'] ?? null; }
        if (empty($reply['id']) || (string)$parent !== $transactionId || (string)($reply['amount_cents']??'') !== (string)$minor || strtoupper((string)($reply['currency']??'')) !== strtoupper($currency) || empty($reply['is_refund'])) {
            throw new RuntimeException('Unverified refund result');
        }
        $status= !empty($reply['pending']) ? 'processing' : (($reply['success']??null) === true ? 'succeeded' : (($reply['success']??null) === false ? 'failed' : 'processing'));
        return ['id'=>(string)$reply['id'],'status'=>$status];
    }

    // ============================ Paymob API ============================

    /**
     * بناء رابط iframe الدفع: auth token → order → payment key → iframe.
     * @return array { url, token }
     * @throws Exception على أي رفض من Paymob.
     */
    private function buildCheckoutUrl(Booking $booking, int $amountMinor, string $reference, string $successUrl, string $cancelUrl): array
    {
        $auth = $this->request('POST', '/auth/tokens', [
            'api_key' => $this->apiKey(),
        ]);
        if (empty($auth['token'])) {
            throw new Exception('تعذر الحصول على توكن Paymob');
        }
        $authToken = $auth['token'];

        $order = $this->request('POST', '/ecommerce/orders', [
            'auth_token'         => $authToken,
            'amount_cents'       => (string) $amountMinor,
            'currency'           => strtoupper($booking->currency),
            'merchant_order_id'  => $reference,
            'delivery_needed'    => 'false',
            'items'              => [[
                'name'         => 'Booking ' . $reference,
                'amount_cents' => (string) $amountMinor,
                'quantity'     => '1',
            ]],
        ]);
        if (empty($order['id'])) {
            throw new Exception('تعذر إنشاء طلب Paymob');
        }
        $orderId = (int) $order['id'];

        $paymentKey = $this->request('POST', '/acceptance/payment_keys', [
            'auth_token'          => $authToken,
            'amount_cents'        => (string) $amountMinor,
            'currency'            => strtoupper($booking->currency),
            'expiration'          => 3600,
            'order_id'            => $orderId,
            'integration_id'      => $this->integrationId(),
            'lock_order_when_paid' => 'false',
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'billing_data'        => [
                'apartment'      => 'NA',
                'email'          => (string) ($booking->customer_email ?: 'customer@example.com'),
                'floor'          => 'NA',
                'first_name'     => substr((string) ($booking->customer_name ?: 'Guest'), 0, 50),
                'street'         => 'NA',
                'building'       => 'NA',
                'phone_number'   => (string) ($booking->customer_phone ?: '+000000000000'),
                'shipping_method' => 'PKG',
                'postal_code'    => 'NA',
                'city'           => 'NA',
                'country'        => 'EG',
                'last_name'      => 'NA',
                'state'          => 'NA',
            ],
        ]);
        if (empty($paymentKey['token'])) {
            throw new Exception('تعذر إنشاء مفتاح الدفع Paymob');
        }
        $paymentToken = $paymentKey['token'];

        return [
            'url'   => self::PAYMOB_API_BASE . '/acceptance/iframes/' . $this->iframeId() . '?payment_token=' . rawurlencode($paymentToken),
            'token' => $paymentToken,
            'order_id' => $order['id'],
        ];
    }

    /** مفاتيح ترتيب حساب HMAC في Paymob (بالترتيب المحدد في توثيقهم). */
    private function hmacKeys(): array
    {
        return [
            'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction',
            'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
            'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];
    }

    /** قراءة قيمة مسار نقطي (order.id مثلًا) من مصفوفة الحدث. */
    private function getValueByKey(array $data, string $key)
    {
        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private function findByGatewayTx(string $transactionId): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM payment_transactions WHERE gateway = 'paymob' AND gateway_transaction_id = ? LIMIT 1",
            [$transactionId]
        );
        return $rows[0] ?? null;
    }

    private function findByReference(string $reference): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM payment_transactions WHERE gateway = 'paymob' AND reference = ? AND booking_id IS NOT NULL ORDER BY id DESC LIMIT 1",
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
     * طلب HTTP مباشر لـ Paymob (JSON مثل ما Paymob بيستقبل).
     * @throws Exception على أخطاء الاتصال أو رفض Paymob (بدون تسريب التفاصيل).
     */
    private function request(string $method, string $path, array $payload, array $headers = []): array
    {
        if ($this->transport) { return ($this->transport)($method,$path,$payload,$headers); }
        $ch = curl_init(self::PAYMOB_API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $method === 'GET' ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 5,
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
            $this->logWarning('Paymob API error', [
                'path'   => $path,
                'status' => $httpCode,
            ]);
            throw new Exception('تعذر تنفيذ الطلب لدى بوابة الدفع');
        }

        return $decoded;
    }

    private function apiKey(): string
    {
        return defined('PAYMOB_API_KEY') ? (string) constant('PAYMOB_API_KEY') : (string) ((getenv('PAYMOB_API_KEY') ?: (isset($_ENV['PAYMOB_API_KEY']) ? (string) $_ENV['PAYMOB_API_KEY'] : false)) ?: '');
    }

    private function integrationId(): int
    {
        $value = defined('PAYMOB_INTEGRATION_ID') ? (string) constant('PAYMOB_INTEGRATION_ID') : (string) ((getenv('PAYMOB_INTEGRATION_ID') ?: (isset($_ENV['PAYMOB_INTEGRATION_ID']) ? (string) $_ENV['PAYMOB_INTEGRATION_ID'] : false)) ?: '');
        return (int) preg_replace('/\D/', '', $value);
    }

    private function iframeId(): int
    {
        $value = defined('PAYMOB_IFRAME_ID') ? (string) constant('PAYMOB_IFRAME_ID') : (string) ((getenv('PAYMOB_IFRAME_ID') ?: (isset($_ENV['PAYMOB_IFRAME_ID']) ? (string) $_ENV['PAYMOB_IFRAME_ID'] : false)) ?: '');
        return (int) preg_replace('/\D/', '', $value);
    }

    private function hmacSecret(): string
    {
        return defined('PAYMOB_HMAC_SECRET') ? (string) constant('PAYMOB_HMAC_SECRET') : (string) ((getenv('PAYMOB_HMAC_SECRET') ?: (isset($_ENV['PAYMOB_HMAC_SECRET']) ? (string) $_ENV['PAYMOB_HMAC_SECRET'] : false)) ?: '');
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
    private function toMinorUnits(float $amount, string $currency): int
    {
        return StripeCheckoutService::minorUnits($amount, $currency);
    }
}
