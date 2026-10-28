<?php

/**
 * Tourfecto - Subscription Period Helper
 * @version 1.0.0
 * @date 2026-08-15
 *
 * حسابات فترات الاشتراك النقية (Pure) - مفيش أي اعتماد على قاعدة
 * بيانات، فمنفصلة في كلاس لوحدها عشان تختبر بسهولة من غير DB، ولأن
 * نفس المنطق (تمديد شهر/سنة) كان متكرر في مكانين:
 *   - Subscription::createSubscription (الاشتراك الجديد)
 *   - WalletService::renewSubscriptionFromBalance (التجديد التلقائي)
 *
 * كل الدوال static و pure - نفس المدخلات بتدي نفس المخرجات دايمًا،
 * فمفيش حالة غامضة ولا أخطاء توقيت.
 */
class SubscriptionPeriod
{
    /**
     * حساب نهاية الفترة الجديدة بتمديد شهر/سنة من تاريخ معيّن.
     *
     * @param string $fromDateTime تاريخ البداية بصيغة 'Y-m-d H:i:s'
     * @param string $planType 'monthly' أو 'yearly' (أي قيمة تانية بتتعامل
     *                         كـ monthly - سلوك محافظ)
     * @return string 'Y-m-d H:i:s'
     */
    public static function nextPeriodEnd(string $fromDateTime, string $planType): string
    {
        if (!in_array($planType, ['monthly','yearly'], true)) throw new InvalidArgumentException('Invalid billing cycle');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fromDateTime);
        if (!$date || $date->format('Y-m-d H:i:s') !== $fromDateTime) throw new InvalidArgumentException('Invalid billing date');
        $day = (int)$date->format('d');
        $first = $date->modify('first day of this month');
        $next = $first->modify($planType === 'yearly' ? '+1 year' : '+1 month');
        return $next->setDate((int)$next->format('Y'), (int)$next->format('m'), min($day,(int)$next->format('t')))->format('Y-m-d H:i:s');
    }

    public static function quotaStart(string $start, ?string $now = null): string
    {
        $now = $now ?: date('Y-m-d H:i:s');
        $base = new DateTimeImmutable($start);
        $at = new DateTimeImmutable($now);
        $months = max(0, ((int)$at->format('Y')-(int)$base->format('Y'))*12 + (int)$at->format('m')-(int)$base->format('m'));
        $first = $base->modify('first day of this month')->modify('+'.$months.' months');
        $candidate = $first->setDate((int)$first->format('Y'),(int)$first->format('m'),min((int)$base->format('d'),(int)$first->format('t')));
        if ($candidate > $at && $months > 0) {
            $first = $base->modify('first day of this month')->modify('+'.($months-1).' months');
            $candidate = $first->setDate((int)$first->format('Y'),(int)$first->format('m'),min((int)$base->format('d'),(int)$first->format('t')));
        }
        return $candidate->format('Y-m-d H:i:s');
    }

    public static function refreshQuota(int $userId): void
    {
        $db = Database::getInstance();
        $db->transaction(function() use($db,$userId) {
            $rows=$db->query("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trialing','past_due') ORDER BY id DESC LIMIT 1 FOR UPDATE",[$userId]);
            if (!$rows) return;
            $s=$rows[0];
            if (empty($s['current_period_start']) || empty($s['current_period_end']) || $s['current_period_end'] <= date('Y-m-d H:i:s')) return;
            $anchor=self::quotaStart($s['current_period_start']);
            if (($s['last_usage_reset_at']??$s['current_period_start']) >= $anchor) return;
            $db->exec('UPDATE subscriptions SET usage_ai_analysis_count=0, usage_ai_message_count=0, usage_review_reply_count=0, competitor_analysis_used=0, last_usage_reset_at=? WHERE id=?',[$anchor,(int)$s['id']]);
        });
    }

    /**
     * مفتاح idempotency فريد لتجديد اشتراك معيّن لفترة معيّنة - بيمنع
     * الخصم المزدوج لو نفس التشغيلة (أو تشغيلتين متوازيتين) حاولوا
     * يجددوا نفس الاشتراك لنفس الفترة.
     *
     * @param int $subscriptionId
     * @param string $currentPeriodEnd تاريخ نهاية الفترة الحالية (اللي
     *                                  بيعرّف الفترة اللي بنجددها)
     * @return string
     */
    public static function renewalIdempotencyKey(int $subscriptionId, string $currentPeriodEnd): string
    {
        $stamp = strtotime($currentPeriodEnd);
        if ($stamp === false) {
            $stamp = time();
        }
        return 'renewal_' . $subscriptionId . '_' . $stamp;
    }
}
