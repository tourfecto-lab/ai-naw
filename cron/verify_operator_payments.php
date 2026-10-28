<?php
/**
 * Tourfecto - التحقق من دفع الحجوزات اللي اتعملت على حساب Stripe بتاع صاحب النشاط
 * (كل 10 دقايق من المنظّم)
 *
 * حساب Stripe بتاع العميل مش بيبعتلنا Webhook. لو الزائر دفع وقفل الصفحة قبل ما يرجع
 * لصفحة التأكيد، الكرون ده هو اللي بيأكد الحجز.
 */
require_once __DIR__ . '/bootstrap.php';

$rows = Database::getInstance()->query(
    "SELECT DISTINCT booking_id FROM payment_transactions
      WHERE gateway = 'stripe_operator' AND status IN ('pending','processing') AND booking_id IS NOT NULL
        AND created_at > (NOW() - INTERVAL 2 DAY)
      ORDER BY booking_id DESC LIMIT 100"
);
$out = ['paid' => 0, 'expired' => 0, 'unpaid' => 0, 'error' => 0, 'none' => 0];
foreach ($rows as $r) {
    $res = OperatorPayments::verifyBooking((int) $r['booking_id']);
    $out[$res] = ($out[$res] ?? 0) + 1;
}
echo json_encode($out) . "\n";
