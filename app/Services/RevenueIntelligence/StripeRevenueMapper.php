<?php

/**
 * Tourfecto - Stripe Revenue Mapper
 * @version 2.0.0
 *
 * v1.5.0 (section A): تحويل بيانات Stripe إلى سجلات الموديول الداخلية.
 *
 * شفافية كاملة: هذا mapper خالص (pure) يطبّع فقط أحداث Stripe القياسية
 * إلى صفوف `biz_subscriptions` / `biz_subscription_events` الجاهزة للإدراج.
 * لا يتصل بشبكة، لا يخترع قيمًا، ولا يتطلب مفاتيح Stripe في هذا الكود.
 * المفتاح السري يبقى حصريًا في إعدادات المستخدم (STRIPE_SECRET_KEY)
 * ولا يُسجَّل أبدًا.
 *
 * Pure functions (قابلة للاختبار بفيكسشرات):
 *   - normalizeAmountForCurrency(int $amount, string $currency): float  - من سنتات إلى عملة
 *   - mapIntervalToCycle(string $interval): string                       - month/week -> monthly...
 *   - convertSubscriptionToMrr(float $amount, string $cycle): float      - تحويل إلى MRR شهري
 *   - mapSubscriptionCreated(array $payload): array                      - سجلات للجداول
 *   - mapInvoicePaymentSucceeded(array $payload): array                  - سجل إيراد نقدي (v2.0)
 *   - mapSubscriptionDeleted(array $payload): array                      - سجل event churn
 *   - mapSubscriptionUpdated(array $payload): array                      - تحديث خطة (v2.0)
 *   - mrrChangeEvent(...)                                                - expansion/contraction بالفرق (v2.0)
 *   - mapChargeEvent(array $payload): ?array                             - charge/refund/dispute (v2.0)
 *
 * v2.0 إصلاح جوهري: قبل كده كل فاتورة تجديد شهرية كانت بتتسجل
 * "expansion" بقيمة الـ MRR كامل => الـ Expansion والـ NRR بيتضخموا كل
 * شهر بدون أي توسّع حقيقي. دلوقتي الفاتورة = إيراد نقدي فعلي فقط،
 * والتوسّع/الانكماش بييجي حصريًا من تغيير الخطة (subscription.updated).
 */
class StripeRevenueMapper
{
    /**
     * تحويل مبلغ Stripe (سنتات) إلى العملة، للأرقام القياسية بـ 6 خانات عشرية
     * (USD/EUR تبقى دولارَين كما هي مع تقسيم على 100).
     */
    public static function normalizeAmountForCurrency(int $amount, string $currency): float
    {
        $currency = strtolower($currency);
        // عملات بلا كسور عشرية حسب توثيق Stripe الرسمي
        $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
        if (in_array($currency, $zeroDecimal, true)) {
            return (float) $amount;
        }
        // v2.0: عملات بثلاث خانات عشرية (مهمة للسوق العربي) - Stripe بيبعتها بالـ 1/1000.
        // قبل كده كانت بتتعامل كأنها بلا كسور => المبلغ بيتضخم ×1000.
        $threeDecimal = ['bhd', 'jod', 'kwd', 'omr', 'tnd', 'lyd', 'iqd'];
        if (in_array($currency, $threeDecimal, true)) {
            return round($amount / 1000, 6);
        }
        return round($amount / 100, 6);
    }

    /** تعيين interval خطط Stripe إلى دورة فوترة الموديول. */
    public static function mapIntervalToCycle(string $interval): string
    {
        $map = [
            'day' => 'monthly',
            'week' => 'monthly',
            'month' => 'monthly',
            'year' => 'yearly',
            'quarter' => 'quarterly',
            '6-month' => 'quarterly',
        ];
        return $map[strtolower(trim($interval))] ?? 'monthly';
    }

    /** تحويل مبلغ أي دورة إلى MRR شهري حقيقي (لا تقدير). */
    public static function convertSubscriptionToMrr(float $amount, string $cycle): float
    {
        switch ($cycle) {
            case 'yearly':
                return round($amount / 12, 2);
            case 'quarterly':
                return round($amount / 3, 2);
            default:
                return round($amount, 2);
        }
    }

    /** سجلات الاشتراك لحدث customer.subscription.created. */
    public static function mapSubscriptionCreated(array $payload): array
    {
        $subscription = $payload['data']['object'] ?? $payload;
        $customer = (string) ($subscription['customer'] ?? '');
        $currency = (string) ($subscription['currency'] ?? 'usd');
        $plan = $subscription['plan'] ?? $subscription['items']['data'][0]['plan'] ?? [];
        $amount = isset($plan['amount']) ? (int) $plan['amount'] : 0;
        $interval = (string) ($plan['interval'] ?? 'month');
        $cycle = self::mapIntervalToCycle($interval);
        $amountNormalized = self::normalizeAmountForCurrency($amount, $currency);
        $mrr = self::convertSubscriptionToMrr($amountNormalized, $cycle);

        $status = (string) ($subscription['status'] ?? 'active');

        $subscriptionRow = [
            'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
            'stripe_customer_id' => $customer,
            'customer_name' => (string) ($subscription['customer_name'] ?? ''),
            'customer_email' => (string) ($subscription['customer_email'] ?? ''),
            'plan_name' => (string) ($plan['nickname'] ?? $plan['id'] ?? ''),
            'status' => in_array($status, ['active', 'trialing'], true) ? $status : 'active',
            'billing_cycle' => $cycle,
            'amount' => $amountNormalized,
            'currency' => strtoupper($currency),
            'mrr' => $mrr,
            'current_period_start' => isset($subscription['current_period_start']) ? gmdate('Y-m-d H:i:s', (int) $subscription['current_period_start']) : null,
            'current_period_end' => isset($subscription['current_period_end']) ? gmdate('Y-m-d H:i:s', (int) $subscription['current_period_end']) : null,
        ];

        $eventRow = [
            'stripe_event_id' => (string) ($payload['id'] ?? ''),
            'event_type' => 'new',
            'mrr_delta' => $mrr,
            'occurred_at' => isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s'),
            'description' => 'subscription.created via Stripe',
        ];

        return ['subscription' => $subscriptionRow, 'event' => $eventRow];
    }

    /**
     * v2.0: فاتورة Stripe ناجحة = إيراد نقدي فعلي اتحصّل (rev_revenue_records)،
     * مش حدث تغيير MRR. نستخدم amount_paid (المبلغ المدفوع فعلًا بعد الخصومات
     * والرصيد) ولو مش موجود نجمع البنود.
     */
    public static function mapInvoicePaymentSucceeded(array $payload): array
    {
        $invoice = $payload['data']['object'] ?? $payload;
        $lines = $invoice['lines']['data'] ?? [];
        $currency = (string) ($invoice['currency'] ?? 'usd');

        if (isset($invoice['amount_paid'])) {
            $minor = (int) $invoice['amount_paid'];
        } else {
            $minor = 0;
            foreach ($lines as $line) {
                $minor += isset($line['amount']) ? (int) $line['amount'] : (int) ($line['price']['unit_amount'] ?? 0);
            }
        }
        $amount = self::normalizeAmountForCurrency($minor, $currency);
        $invoiceId = (string) ($invoice['id'] ?? '');
        $ref = $invoiceId !== '' ? $invoiceId : (string) ($payload['id'] ?? '');

        $productName = null;
        foreach ($lines as $line) {
            $desc = (string) ($line['description'] ?? ($line['price']['nickname'] ?? ''));
            if ($desc !== '') {
                $productName = mb_substr($desc, 0, 255);
                break;
            }
        }

        $revenue = ($amount > 0 && $ref !== '') ? [
            'source' => 'stripe_invoice',
            'reference_id' => $ref,
            'amount' => round($amount, 2),
            'currency' => strtoupper($currency),
            'recorded_at' => isset($invoice['status_transitions']['paid_at'])
                ? gmdate('Y-m-d H:i:s', (int) $invoice['status_transitions']['paid_at'])
                : (isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s')),
            'product_name' => $productName,
            'notes' => 'Stripe invoice paid' . (!empty($invoice['subscription']) ? ' (subscription ' . $invoice['subscription'] . ')' : ''),
            'stripe_customer_id' => (string) ($invoice['customer'] ?? ''),
            'stripe_subscription_id' => (string) ($invoice['subscription'] ?? ''),
        ] : null;

        return ['event' => null, 'revenue' => $revenue];
    }

    /** MRR من كائن اشتراك Stripe (يجمع كل البنود × الكمية). Pure. */
    public static function subscriptionMrr(array $subscription): float
    {
        $currency = (string) ($subscription['currency'] ?? 'usd');
        $items = $subscription['items']['data'] ?? [];
        if (empty($items) && isset($subscription['plan'])) {
            $items = [['plan' => $subscription['plan'], 'quantity' => $subscription['quantity'] ?? 1]];
        }
        $mrr = 0.0;
        foreach ($items as $item) {
            $plan = $item['price'] ?? $item['plan'] ?? [];
            $unit = (int) ($plan['unit_amount'] ?? $plan['amount'] ?? 0);
            $interval = (string) ($plan['recurring']['interval'] ?? $plan['interval'] ?? 'month');
            $count = max(1, (int) ($plan['recurring']['interval_count'] ?? $plan['interval_count'] ?? 1));
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $amount = self::normalizeAmountForCurrency($unit * $qty, $currency);
            switch ($interval) {
                case 'year':
                    $monthly = $amount / (12 * $count);
                    break;
                case 'week':
                    $monthly = $amount * 52 / 12 / $count;
                    break;
                case 'day':
                    $monthly = $amount * 365 / 12 / $count;
                    break;
                default:
                    $monthly = $amount / $count;
            }
            $mrr += $monthly;
        }
        return round($mrr, 2);
    }

    /** تحديث اشتراك (تغيير خطة/كمية/حالة). */
    public static function mapSubscriptionUpdated(array $payload): array
    {
        $created = self::mapSubscriptionCreated($payload);
        $subscription = $payload['data']['object'] ?? $payload;
        $mrr = self::subscriptionMrr($subscription);
        $row = $created['subscription'];
        $row['mrr'] = $mrr;
        $status = (string) ($subscription['status'] ?? 'active');
        $row['status'] = in_array($status, ['active', 'trialing', 'past_due'], true) ? $status : ($status === 'canceled' ? 'cancelled' : 'active');

        // لو Stripe بعت previous_attributes بالخطة القديمة نحسب منها الـ MRR السابق بدقة
        $previousMrr = null;
        $prev = $payload['data']['previous_attributes'] ?? null;
        if (is_array($prev) && (isset($prev['items']) || isset($prev['plan']) || isset($prev['quantity']))) {
            $prevSub = array_replace($subscription, $prev);
            $previousMrr = self::subscriptionMrr($prevSub);
        }
        return ['subscription' => $row, 'previous_mrr' => $previousMrr];
    }

    /** حدث expansion/contraction بالفرق الفعلي فقط (لا حدث لو الفرق صفر/غير معروف). Pure. */
    public static function mrrChangeEvent(array $payload, string $stripeSubscriptionId, ?float $previousMrr, float $newMrr): ?array
    {
        if ($previousMrr === null) {
            return null;
        }
        $delta = round($newMrr - $previousMrr, 2);
        if (abs($delta) < 0.01) {
            return null;
        }
        return [
            'stripe_event_id' => (string) ($payload['id'] ?? ''),
            'stripe_subscription_id' => $stripeSubscriptionId,
            'event_type' => $delta > 0 ? 'expansion' : 'contraction',
            'mrr_delta' => $delta,
            'occurred_at' => isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s'),
            'description' => 'customer.subscription.updated via Stripe (' . $previousMrr . ' -> ' . $newMrr . ')',
        ];
    }

    /**
     * charge.succeeded (بدون فاتورة = دفعة لمرة واحدة) / charge.refunded /
     * charge.dispute.created / charge.dispute.closed -> سجل إيراد (موجب/سالب). Pure.
     */
    public static function mapChargeEvent(array $payload): ?array
    {
        $type = (string) ($payload['type'] ?? '');
        $obj = $payload['data']['object'] ?? [];
        $currency = (string) ($obj['currency'] ?? 'usd');
        $when = isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s');

        switch ($type) {
            case 'charge.succeeded':
                // الشحنات المرتبطة بفاتورة بتتسجل من invoice.payment_succeeded - منع العد مرتين
                if (!empty($obj['invoice']) || empty($obj['id']) || empty($obj['paid'])) {
                    return null;
                }
                $amount = self::normalizeAmountForCurrency((int) ($obj['amount'] ?? 0), $currency);
                return $amount > 0 ? ['source' => 'stripe_charge', 'reference_id' => (string) $obj['id'], 'amount' => round($amount, 2),
                    'currency' => strtoupper($currency), 'recorded_at' => $when, 'notes' => 'Stripe one-time charge'] : null;

            case 'charge.refunded':
                // amount_refunded تراكمي - نسجل الفرق لكل استرداد عبر مرجع الحدث نفسه
                $prevRefunded = (int) ($payload['data']['previous_attributes']['amount_refunded'] ?? 0);
                $minor = (int) ($obj['amount_refunded'] ?? 0) - $prevRefunded;
                $amount = self::normalizeAmountForCurrency(max(0, $minor), $currency);
                $ref = (string) ($payload['id'] ?? '');
                return ($amount > 0 && $ref !== '') ? ['source' => 'stripe_refund', 'reference_id' => $ref, 'amount' => -round($amount, 2),
                    'currency' => strtoupper($currency), 'recorded_at' => $when, 'notes' => 'Stripe refund for charge ' . ($obj['id'] ?? '')] : null;

            case 'charge.dispute.created':
                $amount = self::normalizeAmountForCurrency((int) ($obj['amount'] ?? 0), $currency);
                return ($amount > 0 && !empty($obj['id'])) ? ['source' => 'stripe_dispute', 'reference_id' => (string) $obj['id'], 'amount' => -round($amount, 2),
                    'currency' => strtoupper($currency), 'recorded_at' => $when, 'notes' => 'Stripe dispute opened (' . ($obj['reason'] ?? 'unknown') . ')'] : null;

            case 'charge.dispute.closed':
                // كسبنا النزاع => المبلغ يرجع
                if (($obj['status'] ?? '') !== 'won' || empty($obj['id'])) {
                    return null;
                }
                $amount = self::normalizeAmountForCurrency((int) ($obj['amount'] ?? 0), $currency);
                return $amount > 0 ? ['source' => 'stripe_dispute_reversal', 'reference_id' => (string) $obj['id'], 'amount' => round($amount, 2),
                    'currency' => strtoupper($currency), 'recorded_at' => $when, 'notes' => 'Stripe dispute won'] : null;
        }
        return null;
    }

    /** سجل event عند إلغاء الاشتراك (churn). */
    public static function mapSubscriptionDeleted(array $payload): array
    {
        $subscription = $payload['data']['object'] ?? $payload;
        $customer = (string) ($subscription['customer'] ?? '');
        $currency = (string) ($subscription['currency'] ?? 'usd');
        $plan = $subscription['plan'] ?? $subscription['items']['data'][0]['plan'] ?? [];
        $amount = isset($plan['amount']) ? (int) $plan['amount'] : 0;
        $interval = (string) ($plan['interval'] ?? 'month');
        $cycle = self::mapIntervalToCycle($interval);
        $amountNormalized = self::normalizeAmountForCurrency($amount, $currency);
        $mrr = self::convertSubscriptionToMrr($amountNormalized, $cycle);

        $eventRow = [
            'stripe_event_id' => (string) ($payload['id'] ?? ''),
            'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
            'event_type' => 'churn',
            'mrr_delta' => -abs($mrr),
            'occurred_at' => isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s'),
            'description' => 'customer.subscription.deleted via Stripe',
            'stripe_customer_id' => $customer,
        ];

        // صف اشتراك (status=cancelled) حتى يتمكن الخادم من تحديث حالة الاشتراك
        // المرتبط عند استلام حدث الحذف - إضافة فقط بدون فقدان البيانات.
        $subscriptionRow = [
            'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
            'stripe_customer_id' => $customer,
            'customer_name' => (string) ($subscription['customer_name'] ?? ''),
            'customer_email' => (string) ($subscription['customer_email'] ?? ''),
            'plan_name' => (string) ($plan['nickname'] ?? $plan['id'] ?? ''),
            'status' => 'cancelled',
            'billing_cycle' => $cycle,
            'amount' => $amountNormalized,
            'currency' => strtoupper($currency),
            'mrr' => 0.0,
            'current_period_start' => null,
            'current_period_end' => null,
        ];

        return ['event' => $eventRow, 'subscription' => $subscriptionRow];
    }
}
