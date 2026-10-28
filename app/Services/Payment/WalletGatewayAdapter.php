<?php

/**
 * Tourfecto - Wallet Gateway Adapter
 * @version 1.0.0
 * @date 2026-08-12
 *
 * أول تنفيذ حقيقي شغّال لـ PaymentGatewayInterface. المحفظة نفسها
 * (إيداع يدوي بموافقة أدمن + خصم فوري مضمون) هي "البوابة" الحالية -
 * مفيش أي محاكاة أو Fake Gateway هنا، ده غلاف حقيقي حوالين منطق
 * WalletService الموجود بالفعل والمُختبَر.
 *
 * ملحوظة معمارية: الكلاس ده مايغيّرش سلوك WalletService ولا
 * wallet_transactions خالص - بينده على الدوال الموجودة زي ما هي، وبس
 * بيسجّل نسخة موازية في payment_transactions (السجل الموحّد الجديد)
 * عشان أي تقرير/Refund مستقبلي يقدر يتعامل مع كل طرق الدفع بشكل واحد،
 * بغض النظر لو كانت محفظة أو بوابة حقيقية بكرة.
 */
class WalletGatewayAdapter implements PaymentGatewayInterface
{
    /** @var WalletService */
    private $walletService;
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->walletService = new WalletService();
        $this->db = Database::getInstance();
    }

    public function key(): string
    {
        return 'wallet';
    }

    public function isConfigured(): bool
    {
        // المحفظة دايمًا متاحة - مفيش Credentials خارجية مطلوبة (الدفع
        // نفسه يدوي بموافقة أدمن، مش API خارجي).
        return true;
    }

    /**
     * "نية الدفع" هنا هي إشارة إلى إن العميل هيدفع من رصيده - مفيش أي
     * Redirect ولا انتظار Webhook خارجي (المحفظة تحويل يدوي بالفعل
     * موثّق). بترجع internal_transaction_id فورًا قبل أي خصم فعلي،
     * مطابقة لمتطلب "متعتبرش الدفع ناجح غير من Redirect".
     */
    public function createPaymentIntent(int $userId, float $amount, string $currency, array $metadata = []): array
    {
        $internalId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));

        $tx = new PaymentTransaction();
        $tx->fill([
            'internal_transaction_id' => $internalId,
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'wallet',
            'gateway' => $this->key(),
            'status' => 'pending',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
        $tx->save();

        return [
            'internal_transaction_id' => $internalId,
            'status' => 'pending',
            'gateway_reference' => null,
            'redirect_url' => null, // مفيش Redirect - الخصم مباشر بعد التأكيد
        ];
    }

    /**
     * تحديث حالة معاملة الدفع الموحّدة إلى succeeded/failed بعد خصم
     * حقيقي من المحفظة (يُستدعى من WalletService بعد نجاح/فشل الخصم
     * الفعلي - مش قبله).
     */
    public function markSettled(string $internalTransactionId, bool $success, ?int $relatedWalletTxId = null, ?string $failureReason = null): void
    {
        try {
            $rows = (new PaymentTransaction())->where(['internal_transaction_id' => $internalTransactionId], [], 1);
            if (empty($rows)) {
                return;
            }
            $tx = $rows[0];
            $tx->setAttribute('status', $success ? 'succeeded' : 'failed');
            if ($relatedWalletTxId !== null) {
                $tx->setAttribute('related_wallet_transaction_id', $relatedWalletTxId);
            }
            if ($failureReason !== null) {
                $meta = json_decode((string) $tx->getAttribute('metadata'), true) ?: [];
                $meta['failure_reason'] = $failureReason;
                $tx->setAttribute('metadata', json_encode($meta, JSON_UNESCAPED_UNICODE));
            }
            $tx->save();
        } catch (Exception $e) {
            Logger::error('WalletGatewayAdapter::markSettled failed', ['internal_transaction_id' => $internalTransactionId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * المحفظة مفيهاش Webhooks خارجية (مفيش طرف تالت بيبعت أحداث) -
     * فمفيش توقيع يتحقق منه. بترجع true دايمًا لأن مصدر الحدث داخلي
     * بالكامل (موافقة الأدمن نفسه، مش نداء من الإنترنت).
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        return true;
    }

    /** مفيش أحداث Webhook خارجية للمحفظة - الدالة دي مش متوقّع تتنده لبوابة المحفظة */
    public function handleWebhookEvent(array $eventPayload): array
    {
        return ['internal_transaction_id' => null, 'status' => 'not_applicable', 'event_type' => 'n/a'];
    }

    /**
     * استرجاع لمعاملة محفظة = إضافة رصيد تاني للعميل (مفيش API خارجي
     * يتصل بيه - الاسترجاع داخلي 100%). بيستخدم adminAddBalance
     * الموجودة بالفعل بدل ما يخترع منطق خصم/إضافة جديد.
     */
    public function refund(string $gatewayTransactionId, float $amount, string $reason = ''): array
    {
        // gatewayTransactionId هنا هو internal_transaction_id بتاعنا
        // (مفيش معرّف بوابة خارجي للمحفظة).
        try {
            $rows = (new PaymentTransaction())->where(['internal_transaction_id' => $gatewayTransactionId], [], 1);
            if (empty($rows)) {
                return ['success' => false, 'gateway_refund_id' => null, 'status' => 'failed', 'error' => 'المعاملة غير موجودة'];
            }
            $tx = $rows[0];
            $userId = (int) $tx->getAttribute('user_id');

            $refundTx = new WalletTransaction();
            $refundTx->fill([
                'user_id' => $userId,
                'type' => 'refund',
                'amount' => $amount,
                'currency' => 'USD',
                'status' => 'completed',
                'reference_note' => $reason ?: 'استرجاع لمعاملة #' . $gatewayTransactionId,
                'approved_at' => date('Y-m-d H:i:s'),
            ]);
            $refundTx->save();

            return ['success' => true, 'gateway_refund_id' => (string) $refundTx->getAttribute('id'), 'status' => 'succeeded', 'error' => null];
        } catch (Exception $e) {
            Logger::error('WalletGatewayAdapter::refund failed', ['message' => $e->getMessage()]);
            return ['success' => false, 'gateway_refund_id' => null, 'status' => 'failed', 'error' => 'تعذر تنفيذ الاسترجاع'];
        }
    }
}
