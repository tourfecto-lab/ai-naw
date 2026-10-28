<?php

/**
 * Tourfecto - Send Booking Confirmation Email Job
 * يبعت إيميل تأكيد حجز للعميل (customer_email) بشكل غير متزامن بعد تأكيد
 * الحجز (يدوي أو بعد نجاح الدفع). بيتشغّل بواسطة cron/process_queue.php
 * مثل أي job تاني عبر QueueManager.
 *
 * الأمان:
 *   - بيشتغل فقط لو الحجز confirmed وله customer_email صالح - غياب الإيميل
 *     يفشل الـ Job بأمان (retry من الطابور ثم سجل failed) ولا يكسر تدفق
 *     التأكيد أبدًا (الدفع/التأكيد بيتموا قبل الجدولة أصلاً).
 *   - بيستخدم كلاس Mailer الأساسي (نفس القاعدة اللي اتبنت في شغل
 *     List-Unsubscribe) لبناء هيدرز صحيحة (UTF-8 + منع header injection) —
 *     إيميل معاملات من غير هيدرز إلغاء اشتراك (لا يوجد اشتراك هنا).
 *   - المحتوى مبني من بيانات الحجز فقط (لا إدخال مستخدم خام) مع تهريب كل
 *     القيم عبر htmlspecialchars.
 *   - لو الـ Mailer مش متظبط (MAIL_* في .env) يتخطى بسجل warning — لا
 *     إزعاج بمحاولات Retry بلا جدوى.
 * @version 1.0.0  @date 2026-08-28
 */
class SendBookingConfirmationJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new Exception('booking_id مفقود في payload');
        }

        $db = Database::getInstance();

        $rows = $db->query(
            "SELECT b.id, b.booking_reference, b.customer_name, b.customer_email,
                    b.start_date, b.total_amount, b.currency,
                    p.name AS tour_name,
                    u.company_name
             FROM bookings b
             JOIN crm_products p ON p.id = b.product_id
             LEFT JOIN users u ON u.id = b.user_id
             WHERE b.id = ? AND b.status = 'confirmed'
             LIMIT 1",
            [$bookingId]
        );
        if (empty($rows)) {
            throw new Exception("Booking #{$bookingId} غير موجود أو لسه مش confirmed");
        }
        $booking = $rows[0];

        $toEmail = (string) ($booking['customer_email'] ?? '');
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('SendBookingConfirmationJob: لا يوجد customer_email صالح للحجز #' . $bookingId);
        }

        $mailer = $this->makeMailer();
        if (!$mailer->isConfigured()) {
            if (class_exists('Logger')) {
                Logger::warning('SendBookingConfirmationJob: mailer غير مضبوط، تم التخطي', ['booking_id' => $bookingId]);
            }
            return;
        }

        $subject = 'تأكيد الحجز ' . $booking['booking_reference'];
        $html = self::buildConfirmationHtml($booking);

        $result = $mailer->send(
            $toEmail,
            (string) ($booking['customer_name'] ?: ''),
            $subject,
            $html
        );

        if (!($result['success'] ?? false)) {
            throw new Exception('SendBookingConfirmationJob: فشل إرسال البريد - ' . ($result['error'] ?? 'unknown'));
        }

        if (class_exists('Logger')) {
            Logger::info('Booking confirmation email sent', ['booking_id' => $bookingId, 'to' => $toEmail]);
        }
    }

    /** Factory قابلة للاستبدال في الاختبارات (منع أي اتصال SMTP حقيقي) */
    protected function makeMailer(): Mailer
    {
        return new Mailer();
    }

    /**
     * Pure function - يبني HTML إيميل التأكيد من بيانات الحجز فقط (قابل
     * للاختبار من غير DB/socket). كل القيم تُهرب عبر htmlspecialchars.
     *
     * @param array $booking صف bookings مع tour_name و company_name
     */
    public static function buildConfirmationHtml(array $booking): string
    {
        $esc = static function ($v) {
            return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        };

        $companyName = trim((string) ($booking['company_name'] ?? '')) !== ''
            ? $esc($booking['company_name'])
            : 'Tourfecto';

        $customerName = trim((string) ($booking['customer_name'] ?? '')) !== ''
            ? $esc($booking['customer_name'])
            : 'عميلنا العزيز';

        $reference = $esc($booking['booking_reference'] ?? '');
        $tourName = $esc($booking['tour_name'] ?? '');
        $startDate = trim((string) ($booking['start_date'] ?? '')) !== ''
            ? date('d/m/Y', strtotime((string) $booking['start_date']))
            : '';
        $amount = number_format((float) ($booking['total_amount'] ?? 0), 2);
        $currency = $esc($booking['currency'] ?? 'USD');

        return '<div style="font-family:Arial,sans-serif;line-height:1.7;color:#1F2937;direction:rtl;text-align:right;">'
            . '<h2 style="margin:0 0 14px;color:#111827;">تأكيد الحجز — ' . $reference . '</h2>'
            . '<p style="margin:0 0 12px;">مرحبًا ' . $customerName . '،</p>'
            . '<p style="margin:0 0 12px;">تم تأكيد حجزك بنجاح، وفيما يلي تفاصيل رحلتك:</p>'
            . '<div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px 16px;">'
            . '<p style="margin:0 0 4px;">رقم الحجز: <b>' . $reference . '</b></p>'
            . ($tourName !== '' ? '<p style="margin:0 0 4px;">الرحلة: <b>' . $tourName . '</b></p>' : '')
            . ($startDate !== '' ? '<p style="margin:0 0 4px;">تاريخ البداية: <b>' . $startDate . '</b></p>' : '')
            . '<p style="margin:0;">المبلغ المدفوع: <b>' . $amount . ' ' . $currency . '</b></p>'
            . '</div>'
            . '<p style="color:#888;font-size:12px;margin-top:18px;">في حال وجود أي استفسار، تواصل معنا. — ' . $companyName . '</p>'
            . '</div>';
    }
}
