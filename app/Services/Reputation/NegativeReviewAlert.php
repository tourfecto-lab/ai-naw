<?php
/**
 * تقييم سلبي جديد (نجمتين أو أقل، أو مشاعر سلبية) ← تنبيه فوري لصاحب الحساب: إشعار في المنصة + إيميل.
 * الرد السريع على التقييم السلبي هو أكتر حاجة بتفرق في السمعة، فمش بنستنى العميل يفتح الصفحة.
 * أول مزامنة بتسحب تقييمات قديمة: بنبلّغ بس عن تقييم اتكتب آخر 3 أيام، ومرة واحدة لكل تقييم.
 *
 * زي الخبير: تقييم فيه كلام عن أمان أو نصب أو إصابة أو تسمم بيبقى "عاجل" حتى لو نجومه عالية،
 * والإيميل فيه الرد المقترح نفسه وموعد الرد (24 ساعة) عشان صاحب الشركة يقرر من الإيميل.
 */
class NegativeReviewAlert
{
    public const MAX_AGE_DAYS = 3;

    /** كلام لازم صاحب الشركة يشوفه فورًا مهما كانت النجوم */
    private const CRITICAL = '/\b(scam|fraud|stolen|theft|steal|police|accident|injur\w*|unsafe|danger\w*|harass\w*|assault|food poisoning|sick|hospital|refund|chargeback|lawyer|never again|liar|lied|abus\w*|betrug|gestohlen|arnaque|vol[eé]|estafa|robo|truffa|dolandır\w*)\b|نصب|نصاب|سرقة|اتسرق|حرامي|بوليس|شرطة|حادثة|إصابة|اتصاب|تسمم|خطر|تحرش|استرداد|محامي|كذاب|كذب/iu';

    public static function isCritical(string $text): bool
    {
        return $text !== '' && (bool) preg_match(self::CRITICAL, $text);
    }

    public static function isNegative(array $review, array $sentiment): bool
    {
        $rating = (float) ($review['rating'] ?? 0);
        return ($rating > 0 && $rating <= 2) || (($sentiment['label'] ?? '') === 'negative' && $rating <= 3)
            || self::isCritical((string) ($review['review_text'] ?? ''));
    }

    public static function isRecent(?string $date, ?int $now = null): bool
    {
        $ts = $date ? strtotime($date) : false;
        return $ts !== false && $ts >= ($now ?? time()) - self::MAX_AGE_DAYS * 86400;
    }

    /** @return bool اتبعت تنبيه */
    public static function maybeSend(int $userId, int $websiteId, int $reviewId, array $review, array $sentiment, ?Database $db = null, ?callable $mail = null, string $draft = ''): bool
    {
        if ($reviewId <= 0 || !self::isNegative($review, $sentiment) || !self::isRecent($review['review_date'] ?? null)) {
            return false;
        }
        $db = $db ?: Database::getInstance();
        // مرة واحدة لكل تقييم (لو المزامنة اتكررت أو الـ webhook اتبعت مرتين)
        $key = 'neg_review_alert_' . $reviewId;
        try {
            if ($db->query('SELECT 1 FROM system_settings WHERE setting_key = ? LIMIT 1', [$key])) {
                return false;
            }
            $db->query('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)', [$key, gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            return false;
        }
        $stars = (int) round((float) ($review['rating'] ?? 0));
        $platform = ['google_business' => 'Google', 'tripadvisor' => 'TripAdvisor', 'booking' => 'Booking.com'][$review['platform'] ?? ($review['source_platform'] ?? '')] ?? (string) ($review['platform'] ?? '');
        $name = trim((string) ($review['reviewer_name'] ?? '')) ?: 'عميل';
        $text = mb_substr(trim((string) ($review['review_text'] ?? '')), 0, 600);
        $critical = self::isCritical($text);
        $link = '/reputation/reviews?review=' . $reviewId;
        $title = ($critical ? '🚨 تقييم عاجل على ' : '⚠️ تقييم سلبي جديد على ') . $platform . ($stars ? ' (' . $stars . '★)' : '');
        if (class_exists('Notification')) {
            Notification::notify($userId, 'review_negative', $title, $name . ': ' . mb_substr($text, 0, 140), $link);
        }
        $owner = $db->query('SELECT email, company_name FROM users WHERE id = ? LIMIT 1', [$userId])[0] ?? null;
        if (empty($owner['email'])) {
            return true;
        }
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $deadline = date('Y-m-d H:i', time() + 86400);
        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.8;color:#1f2937">'
            . '<h2 style="margin:0 0 8px">' . $e($title) . '</h2>'
            . ($critical ? '<p style="margin:0 0 10px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b"><b>عاجل:</b> التقييم فيه كلام عن أمان أو فلوس أو شكوى رسمية. كلّم العميل لو تقدر، وراجع اللي حصل مع الفريق قبل الرد.</p>' : '')
            . '<p style="margin:0 0 4px"><b>' . $e($name) . '</b>' . ($stars ? ' · ' . str_repeat('★', $stars) . str_repeat('☆', max(0, 5 - $stars)) : '') . '</p>'
            . ($text !== '' ? '<blockquote style="margin:8px 0;padding:10px 14px;background:#f3f4f6;border-radius:8px">' . nl2br($e($text)) . '</blockquote>' : '')
            . ($draft !== '' ? '<p style="margin:14px 0 4px"><b>الرد المقترح</b> (بلغة العميل، عدّله زي ما تحب قبل النشر):</p><blockquote dir="auto" style="margin:0 0 8px;padding:10px 14px;background:#eef2ff;border-radius:8px">' . nl2br($e($draft)) . '</blockquote>' : '')
            . '<p>الرد الهادي خلال 24 ساعة (قبل ' . $e($deadline) . ') بيفرق جدًا مع العملاء اللي بيقروا التقييم: مايتجادلش، يعتذر عن المشكلة نفسها، ويكمّل الكلام مع العميل برا التقييم.</p>'
            . '<p><a href="' . $e($base . $link) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">راجع الرد وابعته</a></p>'
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0"><p dir="ltr" style="font-size:13px;color:#6b7280;text-align:left">' . ($critical ? 'URGENT: ' : '') . 'New negative review on ' . $e($platform) . '. Reply within 24 hours. Open Tourfecto to review the suggested reply: ' . $e($base . $link) . '</p></div>';
        try {
            $subject = ($critical ? 'عاجل: ' : '') . 'تقييم سلبي جديد محتاج ردك';
            if ($mail) {
                $mail((string) $owner['email'], (string) ($owner['company_name'] ?? ''), $subject, $html);
            } elseif (class_exists('Mailer') && (new Mailer())->isConfigured()) {
                (new Mailer())->context('review_negative_alert', $userId)->send((string) $owner['email'], (string) ($owner['company_name'] ?? ''), $subject . ' - New negative review', $html);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Negative review email failed', ['review_id' => $reviewId]);
            }
        }
        return true;
    }
}
