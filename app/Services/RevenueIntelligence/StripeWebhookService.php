<?php

/**
 * Tourfecto - Stripe Webhook Service
 * @version 2.0.0
 *
 * v1.6.0 (Section A - live integration): استقبال أحداث Stripe الحقيقية عبر
 * webhook، التحقق من توقيع Stripe-Signature (HMAC-SHA256)، ثم تحويلها إلى
 * سجلات `biz_subscriptions`/`biz_subscription_events` عبر StripeRevenueMapper.
 *
 * الشفافية والأمان:
 *   - السر (webhook secret) يأتي مشفرًا من revai_stripe_settings ولا يُسجَّل.
 *   - التحقق من التوقيع إجباري لأي حدث - أي حدث بدون توقيع صحيح يُرفض 401.
 *   - الـ ingestion idempotent عبر stripe_subscription_id / stripe_event_id
 *     (لو الحدث مستلم من قبل، نعيد نفس النتيجة بدون تكرار).
 *   - لا أرقام مخترعة: mapper يحوّل فقط ما هو موجود في الحمولة.
 *
 * Pure functions قابلة للاختبار بفيكسشرات:
 *   - verifySignature(payload, header, secret)
 *   - handleEvent(userId, payload, settingsRow) - يحوّل ويستدعي gateway
 */
class StripeWebhookService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /** أقصى عمر مقبول للتوقيع (ثواني) - نفس قيمة Stripe الرسمية لمنع Replay. */
    public const SIGNATURE_TOLERANCE = 300;

    /**
     * التحقق من توقيع Stripe القياسي:
     * header = "t=<ts>,v1=<hex>[,v1=<hex>...]"; signed_payload = "<ts>.<payload>";
     * متوقع = HMAC-SHA256(signed_payload, secret) hex.
     *
     * v2.0:
     *   - رفض أي توقيع أقدم/أحدث من SIGNATURE_TOLERANCE (حماية Replay).
     *   - قبول أكتر من v1 (Stripe بيبعت توقيعين وقت تدوير السر).
     * Pure function - بدون أي I/O ($now قابل للحقن في الاختبارات).
     */
    public static function verifySignature(string $payload, string $signatureHeader, string $secret, int $tolerance = self::SIGNATURE_TOLERANCE, ?int $now = null): bool
    {
        if ($secret === '' || $signatureHeader === '' || $payload === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = $kv[1];
            } elseif ($kv[0] === 'v1' && $kv[1] !== '') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || !ctype_digit($timestamp) || empty($signatures)) {
            return false;
        }
        if ($tolerance > 0 && abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * معالجة حدث Stripe واحد وإدخاله في جداول الموديول (idempotent).
     *
     * v2.0: أحداث مدعومة:
     *   customer.subscription.created  -> اشتراك جديد (new)
     *   customer.subscription.updated  -> تغيير خطة: expansion/contraction بالفرق الفعلي فقط
     *   customer.subscription.deleted  -> churn
     *   invoice.payment_succeeded      -> إيراد نقدي فعلي (rev_revenue_records) - مش "expansion"
     *   invoice.payment_failed         -> الاشتراك past_due + مخاطرة
     *   charge.succeeded (بدون فاتورة) -> إيراد نقدي لمرة واحدة
     *   charge.refunded                -> إيراد سالب (استرداد)
     *   charge.dispute.created/closed  -> خصم/استرجاع مبلغ النزاع
     *
     * @param int   $userId   صاحب الحساب
     * @param array $payload  جسم الحدث المكتمل (مع data.object)
     * @param array $settings صف revai_stripe_settings (اختياري للـ last_event)
     * @return array النتيجة مع status واضح
     */
    public function handleEvent(int $userId, array $payload, array $settings = []): array
    {
        $type = (string) ($payload['type'] ?? '');
        $eventId = (string) ($payload['id'] ?? '');

        // Idempotency: نفس الـ stripe_event_id مسجل من قبل؟
        if ($eventId !== '' && $this->gateway->stripeEventExists($userId, $eventId)) {
            return ['handled' => true, 'status' => 'duplicate', 'event_id' => $eventId, 'type' => $type];
        }

        switch ($type) {
            case 'customer.subscription.created':
                $mapped = StripeRevenueMapper::mapSubscriptionCreated($payload);
                $this->gateway->upsertBizSubscriptionFromStripe($userId, $mapped['subscription']);
                $this->gateway->insertBizSubscriptionEvent($userId, $mapped['event']);
                break;

            case 'customer.subscription.updated':
                $mapped = StripeRevenueMapper::mapSubscriptionUpdated($payload);
                $subId = (string) ($mapped['subscription']['stripe_subscription_id'] ?? '');
                $previousMrr = $mapped['previous_mrr'] ?? ($subId !== '' ? $this->gateway->getBizSubscriptionMrr($userId, $subId) : null);
                $this->gateway->upsertBizSubscriptionFromStripe($userId, $mapped['subscription']);
                $event = StripeRevenueMapper::mrrChangeEvent($payload, $subId, $previousMrr, (float) $mapped['subscription']['mrr']);
                if ($event !== null) {
                    $this->gateway->insertBizSubscriptionEvent($userId, $event);
                }
                break;

            case 'invoice.payment_succeeded':
            case 'invoice.paid':
                $mapped = StripeRevenueMapper::mapInvoicePaymentSucceeded($payload);
                if (!empty($mapped['revenue'])) {
                    $this->gateway->recordStripeRevenue($userId, $mapped['revenue']);
                }
                break;

            case 'invoice.payment_failed':
                $invoice = $payload['data']['object'] ?? [];
                $this->gateway->markBizSubscriptionPastDue($userId, (string) ($invoice['subscription'] ?? ''));
                break;

            case 'charge.succeeded':
            case 'charge.refunded':
            case 'charge.dispute.created':
            case 'charge.dispute.closed':
                $row = StripeRevenueMapper::mapChargeEvent($payload);
                if ($row !== null) {
                    $this->gateway->recordStripeRevenue($userId, $row);
                }
                break;

            case 'customer.subscription.deleted':
                $mapped = StripeRevenueMapper::mapSubscriptionDeleted($payload);
                $this->gateway->upsertBizSubscriptionFromStripe($userId, $mapped['subscription'], 'cancelled');
                $this->gateway->insertBizSubscriptionEvent($userId, $mapped['event']);
                break;

            default:
                // أحداث أخرى: نستقبلها بصمت (Stripe ترسل كثيرًا) لكن لا نستحدث صفوفًا.
                $this->gateway->touchStripeEvent($userId, $eventId, $type);
                return ['handled' => true, 'status' => 'ignored_type', 'event_id' => $eventId, 'type' => $type];
        }

        $this->gateway->touchStripeEvent($userId, $eventId, $type);
        return ['handled' => true, 'status' => 'processed', 'event_id' => $eventId, 'type' => $type];
    }

    /** قائمة الأحداث اللي لازم تتفعل في لوحة Stripe (للعرض في الإعدادات). */
    public static function supportedEvents(): array
    {
        return [
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
            'invoice.payment_succeeded', 'invoice.payment_failed',
            'charge.succeeded', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed',
        ];
    }
}
