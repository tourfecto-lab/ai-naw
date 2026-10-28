<?php
/**
 * Tourfecto - إلغاء الحجوزات اللي ماتدفعتش (كل 15 دقيقة من المنظّم)
 *
 * قبل كده الحجز من الموقع كان بياخد مكان من التوفر أول ما يتعمل، ولو الزائر ماكملش الدفع
 * الحجز بيفضل pending للأبد والمكان محجوز. هنا:
 *  1) حجز بدأ دفع Stripe: بنسأل Stripe عن الجلسة - لو اتدفعت بنأكد، لو انتهت بنلغي ونرجّع المكان.
 *  2) بوابة تانية (Paymob...): بنلغي بعد مهلة الدفع + 3 ساعات لو مفيش دفع ناجح.
 *  3) طلب حجز من غير دفع أونلاين وتاريخه عدى من غير ما صاحب النشاط يأكده: بيتلغي.
 *  (الحجوزات اللي صاحب الشركة بعت لها "لينك دفع" مابتتلغيش لو العميل ماكملش الدفع - القرار له.)
 */
require_once __DIR__ . '/bootstrap.php';

$db = Database::getInstance();
$engine = new BookingEngine();
$window = class_exists('StripeCheckoutService') ? StripeCheckoutService::paymentWindowMinutes() : 60;
$out = ['cancelled' => 0, 'confirmed' => 0, 'still_open' => 0, 'past_requests' => 0, 'errors' => 0];
$perUser = [];

$cancel = function (array $b, string $reason) use ($engine, $db, &$out, &$perUser): bool {
    try {
        $engine->cancelBooking((int) $b['user_id'], (int) $b['id'], $reason);
        $db->exec("UPDATE payment_transactions SET status = 'cancelled' WHERE booking_id = ? AND status IN ('pending','processing')", [(int) $b['id']]);
        $perUser[(int) $b['user_id']][] = (string) $b['booking_reference'];
        return true;
    } catch (Throwable $e) {
        $out['errors']++;
        Logger::warning('expire booking failed', ['booking_id' => $b['id'], 'error' => $e->getMessage()]);
        return false;
    }
};

// 1+2) حجوزات بدأت دفع أونلاين ومافيش دفع ناجح
$rows = $db->query(
    "SELECT b.id, b.user_id, b.booking_reference, t.id AS tx_id, t.gateway, t.gateway_transaction_id, t.created_at AS tx_created
       FROM bookings b
       JOIN payment_transactions t ON t.id = (SELECT MAX(t2.id) FROM payment_transactions t2 WHERE t2.booking_id = b.id)
      WHERE b.status = 'pending'
        AND t.status IN ('pending','processing','failed','cancelled')
        AND t.created_at < (NOW() - INTERVAL ? MINUTE)
        AND NOT EXISTS (SELECT 1 FROM payment_transactions s WHERE s.booking_id = b.id AND s.status = 'succeeded')
        AND (JSON_EXTRACT(t.metadata, '$.payment_link_id') IS NULL)
      ORDER BY b.id LIMIT 200",
    [$window + 10]
);
foreach ($rows as $b) {
    $gw = (string) $b['gateway'];
    if (str_starts_with($gw, 'stripe') && !empty($b['gateway_transaction_id'])) {
        try {
            if ($gw === 'stripe_operator') {
                $res = OperatorPayments::verifyBooking((int) $b['id']);
                if ($res === 'paid') { $out['confirmed']++; continue; }
                if ($res === 'unpaid' || $res === 'error') { $out['still_open']++; continue; }
                // expired / none → نلغي
            } else {
                $session = (new StripeCheckoutService())->retrieveSession((string) $b['gateway_transaction_id']);
                if (($session['payment_status'] ?? '') === 'paid') { $out['still_open']++; continue; } // الـ Webhook هيأكده
                if (($session['status'] ?? '') === 'open') { $out['still_open']++; continue; }
            }
        } catch (Throwable $e) {
            // Stripe مش راد: مانلغيش غير بعد 25 ساعة (أقصى عمر للجلسة)
            if (strtotime((string) $b['tx_created']) > time() - 25 * 3600) { $out['still_open']++; continue; }
        }
    } elseif (strtotime((string) $b['tx_created']) > time() - ($window + 180) * 60) {
        $out['still_open']++;
        continue;
    }
    if ($cancel($b, 'إلغاء تلقائي: انتهت مهلة الدفع')) {
        $out['cancelled']++;
    }
}

// 3) طلبات حجز من غير دفع أونلاين وتاريخها عدى ومحدش أكدها
$past = $db->query(
    "SELECT b.id, b.user_id, b.booking_reference FROM bookings b
      WHERE b.status = 'pending' AND b.start_date < CURDATE()
        AND NOT EXISTS (SELECT 1 FROM payment_transactions t WHERE t.booking_id = b.id AND t.status IN ('pending','processing','succeeded'))
      ORDER BY b.id LIMIT 200"
);
foreach ($past as $b) {
    if ($cancel($b, 'إلغاء تلقائي: تاريخ الرحلة عدى والحجز ماتأكدش')) {
        $out['past_requests']++;
    }
}

foreach ($perUser as $uid => $refs) {
    Notification::notify($uid, 'bookings_auto_expired',
        'اتلغى ' . count($refs) . ' حجز ماتدفعش',
        'الحجوزات دي اتلغت تلقائي والأماكن رجعت متاحة: ' . implode('، ', array_slice($refs, 0, 10)) . (count($refs) > 10 ? ' …' : ''),
        '/bookings');
}

echo json_encode($out) . "\n";
