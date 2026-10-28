<?php

/**
 * منحة من الأدمن: يفتح لعميل كل خدمات باقة معيّنة لمدة محددة من غير دفع ومن غير خصم من محفظته.
 * - بتتسجل كاشتراك active سعره 0 وعليه cancel_at_period_end=1، فالتجديد التلقائي مابيسحبش فلوس
 *   ولما المدة تخلص بيتلغي لوحده (SubscriptionLifecycleService::transitionCancelledAtPeriodEnd).
 * - الاشتراك المدفوع اللي عند العميل (لو موجود) مابيتلمسش: الأحدث هو اللي بيتحسب، فبعد المنحة يرجع للمدفوع لو لسه شغال.
 */
final class ComplimentaryGrant
{
    public const GATEWAY = 'admin_grant';
    public const MAX_DAYS = 3650;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** صاحب الحساب (لو العميل عضو في فريق، المنحة بتروح للحساب الأساسي) */
    public function owner(int $userId): int
    {
        $u = $this->db->query('SELECT id, owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId])[0] ?? null;
        if (!$u) throw new InvalidArgumentException('العميل مش موجود');
        return (int) ($u['owner_user_id'] ?: $u['id']);
    }

    /** الباقات الشهرية المفعّلة: [['key'=>'professional','name'=>...,'price'=>...]] */
    public function plans(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT plan_code, name, price, currency FROM subscription_plans WHERE is_active = 1 AND plan_code LIKE '%\\_monthly' ORDER BY price ASC") as $r) {
            $out[] = ['key' => substr((string) $r['plan_code'], 0, -strlen('_monthly')), 'name' => (string) $r['name'], 'price' => (float) $r['price'], 'currency' => (string) ($r['currency'] ?: 'USD')];
        }
        return $out;
    }

    /** الاشتراك الشغال دلوقتي + المنح الشغالة */
    public function status(int $userId): array
    {
        $uid = $this->owner($userId);
        $active = class_exists('Subscription') ? Subscription::activeSubscriptionRow($uid) : null;
        $grants = [];
        try {
            $grants = $this->db->query("SELECT id, plan_name, current_period_end FROM subscriptions WHERE user_id = ? AND status = 'active' AND price = 0 AND cancel_at_period_end = 1 AND current_period_end > NOW() AND payment_gateway = ? ORDER BY id DESC", [$uid, self::GATEWAY]);
        } catch (Throwable $e) {
        }
        return [
            'owner_id' => $uid,
            'active' => $active ? ['plan' => (string) ($active['plan_name'] ?? ''), 'status' => (string) ($active['lifecycle_status'] ?? $active['status'] ?? ''), 'ends_at' => $active['expiry_date'] ?? null, 'price' => (float) ($active['price'] ?? 0)] : null,
            'grants' => array_map(fn ($g) => ['id' => (int) $g['id'], 'plan' => (string) $g['plan_name'], 'ends_at' => (string) $g['current_period_end']], $grants),
        ];
    }

    public function grant(int $userId, string $planKey, int $days, int $adminId): array
    {
        $uid = $this->owner($userId);
        if (!preg_match('/^[a-z0-9-]+$/D', $planKey) || !in_array($planKey, array_column($this->plans(), 'key'), true)) {
            throw new InvalidArgumentException('اختار باقة مفعّلة');
        }
        if ($days < 1 || $days > self::MAX_DAYS) throw new InvalidArgumentException('المدة لازم تكون من يوم لـ 10 سنين');
        // منحة شغالة قبل كده بتتقفل عشان مايبقاش فيه اتنين
        $this->endGrants($uid);
        $sub = Subscription::createSubscription($uid, $planKey, 'monthly');
        if (!$sub) throw new RuntimeException('تعذر إنشاء الاشتراك');
        $id = (int) $sub->getAttribute('id');
        $end = date('Y-m-d H:i:s', time() + $days * 86400);
        $pdo = $this->db->getConnection();
        $st = $pdo->prepare("UPDATE subscriptions SET status = 'active', price = 0, current_period_end = ?, cancel_at_period_end = 1, trial_ends_at = NULL WHERE id = ? AND user_id = ?");
        $st->execute([$end, $id, $uid]);
        if ($st->rowCount() < 1) {
            $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            throw new RuntimeException('تعذر تفعيل المنحة');
        }
        try { $pdo->prepare('UPDATE subscriptions SET payment_gateway = ? WHERE id = ?')->execute([self::GATEWAY, $id]); } catch (Throwable $e) {}
        if (class_exists('ActivityLog')) {
            ActivityLog::record('subscription', 'subscription.admin_grant', ['user_id' => $uid, 'subject_type' => 'subscriptions', 'subject_id' => $id, 'meta' => ['plan' => $planKey, 'days' => $days, 'by_admin' => $adminId]]);
        }
        if (class_exists('Notification')) {
            try { Notification::notify($uid, 'subscription_granted', '🎁 اتفعّلتلك باقة مجانية', 'فريق Tourfecto فعّلك كل خدمات الباقة لحد ' . substr($end, 0, 10) . ' من غير أي دفع.', '/subscription'); } catch (Throwable $e) {}
        }
        return ['subscription_id' => $id, 'plan' => $planKey, 'ends_at' => $end, 'days' => $days];
    }

    /** إنهاء المنح الشغالة (الاشتراكات المدفوعة مابتتلمسش) */
    public function endGrants(int $userId): int
    {
        $uid = $this->owner($userId);
        $st = $this->db->getConnection()->prepare("UPDATE subscriptions SET status = 'cancelled', current_period_end = NOW() WHERE user_id = ? AND status = 'active' AND price = 0 AND cancel_at_period_end = 1 AND payment_gateway = ?");
        try { $st->execute([$uid, self::GATEWAY]); } catch (Throwable $e) { return 0; }
        return $st->rowCount();
    }
}
