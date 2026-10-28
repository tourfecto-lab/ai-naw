<?php

/**
 * Tourfecto - Payment Gateway Contract
 * @version 1.0.0
 * @date 2026-08-12
 *
 * أي بوابة دفع حقيقية (Stripe, PayPal, Paymob...) لازم تعمل implement
 * للـ interface ده عشان تتوصل بنظام الفوترة من غير ما تحتاج إعادة بناء
 * أي حاجة في Subscription/Wallet/Invoice. المسار المطلوب:
 *
 *   User → Checkout → createPaymentIntent() → [التحويل الفعلي للبوابة]
 *   → Webhook من البوابة → verifyWebhookSignature() → handleWebhookEvent()
 *   → PaymentTransaction (نجح/فشل) → Invoice → Subscription/Wallet
 *
 * ملحوظة مهمة: مفيش أي بوابة دفع حقيقية مفعّلة في المشروع وقت كتابة
 * الـ interface ده (Stripe/PayPal معطّلين في .env). الـ WalletGatewayAdapter
 * هو أول وتنفيذ حقيقي شغّال فعليًا (المحفظة نفسها كـ"بوابة" - العميل
 * بيودّع يدويًا، الأدمن يوافق، والخصم فوري ومضمون من غير أي طرف تالت).
 * أي بوابة حقيقية مستقبلية (Stripe مثلاً) هتضيف كلاس جديد implements
 * PaymentGatewayInterface من غير أي تعديل على Subscription/Invoice/Wallet.
 */
interface PaymentGatewayInterface
{
    /** المفتاح الفريد للبوابة - زي ما هيتخزن في payment_transactions.gateway */
    public function key(): string;

    /**
     * إنشاء نية دفع (Payment Intent) - الخطوة الأولى قبل أي تحويل فعلي.
     * لازم ترجع internal_transaction_id فريد (uuid) قبل ما نثق في أي
     * نتيجة نهائية - مطابقة صريحة لمتطلب "لا تعتبر الدفع ناجحًا اعتمادًا
     * على Redirect فقط".
     *
     * @return array{internal_transaction_id: string, status: string, gateway_reference: ?string, redirect_url: ?string}
     */
    public function createPaymentIntent(int $userId, float $amount, string $currency, array $metadata = []): array;

    /**
     * التحقق من توقيع الـ Webhook (HMAC أو ما يعادله حسب البوابة) - إلزامي
     * قبل أي معالجة. لازم يرجع false لأي طلب مش موقّع صح، من غير استثناء
     * ولا تسجيل بيانات حساسة.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /**
     * معالجة حدث Webhook متحقق من توقيعه بالفعل. لازم تكون Idempotent -
     * نفس الـ event_id لو اتبعت تاني (إعادة إرسال من البوابة) ميعملش
     * أي تأثير إضافي.
     *
     * @return array{internal_transaction_id: ?string, status: string, event_type: string}
     */
    public function handleWebhookEvent(array $eventPayload): array;

    /**
     * استرجاع فعلي (كامل أو جزئي) عبر الـ API الحقيقي للبوابة - لو
     * البوابة بتدعم Refund API. لازم ترجع الحالة الحقيقية من الطرف
     * التالت، مش نجاح مفترض.
     *
     * @return array{success: bool, gateway_refund_id: ?string, status: string, error: ?string}
     */
    public function refund(string $gatewayTransactionId, float $amount, string $reason = ''): array;

    /** هل البوابة فعليًا مفعّلة وجاهزة (Credentials موجودة) وقت التشغيل؟ */
    public function isConfigured(): bool;
}
