<?php

/**
 * اشتراك الموقع: الموقع منتج لوحده بسعر خاص (شهري/سنوي) لكل العملاء،
 * ومشتركين المنصة بياخدوا خصم. الموقع المنشور بيفضل شغال طول ما اشتراكه شغال
 * (+ فترة سماح)، وبعدها الزوار بيشوفوا صفحة «الموقع متوقف مؤقتًا».
 *
 * الباقة المستقلة = موقع كامل + حجز + دومين خاص. شات الذكاء الاصطناعي على الموقع
 * وتحسين SEO الأسبوعي والرد على المراجعات بييجوا مع اشتراك المنصة بس.
 */
class SiteSubscriptionService
{
    public const DEFAULTS = ['enabled' => true, 'monthly' => 29.0, 'yearly' => 290.0, 'platform_discount' => 30, 'grace_days' => 7];
    private const GRANDFATHER_DAYS = 30;

    private PDO $pdo;
    private static bool $schemaReady = false;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS site_subscriptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            website_id INT NOT NULL,
            user_id INT NOT NULL,
            cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            source VARCHAR(16) NOT NULL DEFAULT 'wallet',
            auto_renew TINYINT(1) NOT NULL DEFAULT 1,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_website (website_id),
            KEY idx_user (user_id),
            KEY idx_due (status, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$schemaReady = true;
        $this->grandfatherOnce();
    }

    /**
     * أول مرة الميزة تشتغل: المواقع المنشورة فعلًا بتاخد شهر مجاني
     * عشان محدش موقعه يقف فجأة من غير إنذار.
     */
    private function grandfatherOnce(): void
    {
        $settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        if (!$settings || $settings->get('site_subs_grandfathered', '') === '1') {
            return;
        }
        $end = date('Y-m-d H:i:s', time() + self::GRANDFATHER_DAYS * 86400);
        $this->pdo->prepare("INSERT IGNORE INTO site_subscriptions (website_id, user_id, cycle, price, status, source, auto_renew, period_start, period_end)
            SELECT id, user_id, 'monthly', 0, 'active', 'grandfathered', 1, NOW(), ? FROM generated_websites WHERE status='published'")->execute([$end]);
        $settings->set('site_subs_grandfathered', '1');
    }

    // ------------------------------------------------------------------ الإعدادات

    public static function config(): array
    {
        $raw = class_exists('SystemSettingsService') ? json_decode((string) (new SystemSettingsService())->get('site_plan_config', ''), true) : null;
        $raw = is_array($raw) ? $raw : [];
        $c = self::DEFAULTS;
        if (array_key_exists('enabled', $raw)) $c['enabled'] = !empty($raw['enabled']);
        foreach (['monthly', 'yearly'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k]) && $raw[$k] >= 1) $c[$k] = round((float) $raw[$k], 2);
        }
        if (isset($raw['platform_discount']) && is_numeric($raw['platform_discount'])) $c['platform_discount'] = max(0, min(90, (int) $raw['platform_discount']));
        if (isset($raw['grace_days']) && is_numeric($raw['grace_days'])) $c['grace_days'] = max(0, min(30, (int) $raw['grace_days']));
        return $c;
    }

    public static function saveConfig(array $in): array
    {
        $c = self::config();
        if (array_key_exists('enabled', $in)) $c['enabled'] = !empty($in['enabled']);
        foreach (['monthly', 'yearly'] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k]) && $in[$k] >= 1 && $in[$k] <= 100000) $c[$k] = round((float) $in[$k], 2);
        }
        if (isset($in['platform_discount']) && is_numeric($in['platform_discount'])) $c['platform_discount'] = max(0, min(90, (int) $in['platform_discount']));
        if (isset($in['grace_days']) && is_numeric($in['grace_days'])) $c['grace_days'] = max(0, min(30, (int) $in['grace_days']));
        (new SystemSettingsService())->set('site_plan_config', json_encode($c));
        return $c;
    }

    // ------------------------------------------------------------------ السعر والحالة

    /** مشترك منصة بيدفع (مش في التجربة) → ليه خصم على الموقع */
    public static function hasPaidPlatformPlan(int $uid): bool
    {
        $row = class_exists('Subscription') ? Subscription::activeSubscriptionRow($uid) : null;
        return $row && ($row['lifecycle_status'] ?? $row['status'] ?? '') === 'active' && (float) ($row['price'] ?? 0) > 0;
    }

    /** مشترك منصة (حتى في التجربة) → خدمات المنصة (الشات الذكي على الموقع) شغالة */
    public static function hasPlatformPlan(int $uid): bool
    {
        return class_exists('Subscription') && (bool) Subscription::activeSubscriptionRow($uid);
    }

    public function prices(int $uid): array
    {
        $c = self::config();
        $platform = self::hasPaidPlatformPlan($uid);
        $f = $platform ? (100 - $c['platform_discount']) / 100 : 1;
        return [
            'monthly' => round($c['monthly'] * $f, 2), 'yearly' => round($c['yearly'] * $f, 2),
            'list_monthly' => $c['monthly'], 'list_yearly' => $c['yearly'],
            'discount' => $platform ? $c['platform_discount'] : 0, 'platform_discount' => $c['platform_discount'],
        ];
    }

    public function row(int $websiteId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM site_subscriptions WHERE website_id=?');
        $st->execute([$websiteId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * active: مدفوع وشغال | grace: الفترة خلصت ولسه في السماح | expired: الموقع متوقف | none: مفيش اشتراك
     */
    public function state(int $websiteId): array
    {
        $r = $this->row($websiteId);
        if (!$r) {
            return ['state' => 'none', 'row' => null, 'live_until' => null];
        }
        $end = strtotime($r['period_end']);
        $graceEnd = $end + self::config()['grace_days'] * 86400;
        if ($r['status'] === 'cancelled' && time() >= $end) {
            return ['state' => 'expired', 'row' => $r, 'live_until' => $r['period_end']];
        }
        if (time() < $end) {
            return ['state' => 'active', 'row' => $r, 'live_until' => $r['period_end']];
        }
        if (time() < $graceEnd && $r['status'] !== 'cancelled') {
            return ['state' => 'grace', 'row' => $r, 'live_until' => date('Y-m-d H:i:s', $graceEnd)];
        }
        return ['state' => 'expired', 'row' => $r, 'live_until' => $r['period_end']];
    }

    /** الموقع يظهر للزوار؟ (لو الميزة مقفولة من الأدمن، كل المواقع المنشورة شغالة زي الأول) */
    public function isLive(int $websiteId): bool
    {
        if (!self::config()['enabled']) {
            return true;
        }
        return in_array($this->state($websiteId)['state'], ['active', 'grace'], true);
    }

    // ------------------------------------------------------------------ الدفع

    /**
     * اشتراك/تجديد من المحفظة. الفترة الجديدة بتبدأ من آخر الفترة الحالية لو لسه شغالة (مفيش أيام بتضيع).
     * @return array{success:bool, error?:string, period_end?:string, charged?:float, shortfall?:float}
     */
    public function subscribe(int $uid, int $websiteId, string $cycle, ?string $idem = null): array
    {
        if (!in_array($cycle, ['monthly', 'yearly'], true)) {
            return ['success' => false, 'error' => 'مدة غير معروفة'];
        }
        $st = $this->pdo->prepare('SELECT id, user_id, slug FROM generated_websites WHERE id=?');
        $st->execute([$websiteId]);
        $site = $st->fetch(PDO::FETCH_ASSOC);
        if (!$site || (int) $site['user_id'] !== $uid) {
            return ['success' => false, 'error' => 'الموقع مش موجود'];
        }
        $price = $this->prices($uid)[$cycle];
        $wallet = new WalletService();
        $idem = $idem ?: ('site_sub_' . $websiteId . '_' . bin2hex(random_bytes(8)));
        if (!$wallet->chargeAmount($uid, $price, 'site_subscription', 'اشتراك موقع ' . $site['slug'] . ' (' . ($cycle === 'yearly' ? 'سنوي' : 'شهري') . ')', $idem)) {
            $balance = $wallet->getBalance($uid);
            return ['success' => false, 'error' => 'رصيدك مش كافي', 'required' => $price, 'balance' => $balance, 'shortfall' => round(max(0, $price - $balance), 2)];
        }
        $end = $this->extend($uid, $websiteId, $cycle, $price, 'wallet');
        if (class_exists('ActivityLog')) {
            ActivityLog::record('website', 'site_subscription.paid', ['user_id' => $uid, 'subject_type' => 'generated_websites', 'subject_id' => $websiteId, 'meta' => ['cycle' => $cycle, 'price' => $price, 'period_end' => $end]]);
        }
        return ['success' => true, 'period_end' => $end, 'charged' => $price];
    }

    private function extend(int $uid, int $websiteId, string $cycle, float $price, string $source): string
    {
        $r = $this->row($websiteId);
        $from = $r && strtotime($r['period_end']) > time() ? $r['period_end'] : date('Y-m-d H:i:s');
        $end = date('Y-m-d H:i:s', strtotime($from . ($cycle === 'yearly' ? ' +1 year' : ' +1 month')));
        $this->pdo->prepare("INSERT INTO site_subscriptions (website_id, user_id, cycle, price, status, source, auto_renew, period_start, period_end)
            VALUES (?, ?, ?, ?, 'active', ?, 1, NOW(), ?)
            ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), cycle=VALUES(cycle), price=VALUES(price), status='active', source=VALUES(source), auto_renew=1, period_start=NOW(), period_end=VALUES(period_end)")
            ->execute([$websiteId, $uid, $cycle, $price, $source, $end]);
        return $end;
    }

    public function setAutoRenew(int $uid, int $websiteId, bool $on): bool
    {
        $st = $this->pdo->prepare("UPDATE site_subscriptions SET auto_renew=?, status=IF(?=1 AND status='cancelled','active',status) WHERE website_id=? AND user_id=?");
        $st->execute([$on ? 1 : 0, $on ? 1 : 0, $websiteId, $uid]);
        return $st->rowCount() > 0;
    }

    /** الأدمن: يدّي الموقع أيام مجانية (أو يمدّه) */
    public function adminGrant(int $websiteId, int $days, int $adminId): ?string
    {
        $st = $this->pdo->prepare('SELECT user_id FROM generated_websites WHERE id=?');
        $st->execute([$websiteId]);
        $uid = (int) $st->fetchColumn();
        if (!$uid || $days < 1 || $days > 730) {
            return null;
        }
        $r = $this->row($websiteId);
        $from = $r && strtotime($r['period_end']) > time() ? $r['period_end'] : date('Y-m-d H:i:s');
        $end = date('Y-m-d H:i:s', strtotime($from) + $days * 86400);
        $this->pdo->prepare("INSERT INTO site_subscriptions (website_id, user_id, cycle, price, status, source, auto_renew, period_start, period_end)
            VALUES (?, ?, 'monthly', 0, 'active', 'admin', 1, NOW(), ?)
            ON DUPLICATE KEY UPDATE status='active', source='admin', period_end=VALUES(period_end)")->execute([$websiteId, $uid, $end]);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('website', 'site_subscription.admin_grant', ['user_id' => $adminId, 'subject_type' => 'generated_websites', 'subject_id' => $websiteId, 'meta' => ['days' => $days, 'period_end' => $end]]);
        }
        return $end;
    }

    // ------------------------------------------------------------------ التجديد التلقائي (cron)

    /**
     * الاشتراكات اللي فترتها خلصت: بنجدّد من المحفظة بنفس المدة والسعر الحالي.
     * لو الرصيد مش كافي: past_due + رسالة (والموقع شغال لحد آخر فترة السماح).
     * بعد فترة السماح: الموقع بيقف وبتوصل رسالة.
     */
    public function runRenewals(): array
    {
        $out = ['renewed' => 0, 'past_due' => 0, 'stopped' => 0];
        if (!self::config()['enabled']) {
            return $out;
        }
        $grace = self::config()['grace_days'];
        $rows = $this->pdo->query("SELECT s.*, w.slug FROM site_subscriptions s JOIN generated_websites w ON w.id=s.website_id
            WHERE s.status IN ('active','past_due') AND s.period_end <= NOW()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $wid = (int) $r['website_id'];
            try {
                if ((int) $r['auto_renew'] === 1) {
                    $res = $this->subscribe($uid, $wid, $r['cycle'] === 'yearly' ? 'yearly' : 'monthly', 'site_renew_' . $wid . '_' . date('Ymd', strtotime($r['period_end'])));
                    if (!empty($res['success'])) {
                        $out['renewed']++;
                        $this->notify($uid, 'اشتراك موقعك اتجدد', 'اتجدد اشتراك موقع ' . $r['slug'] . ' من رصيد محفظتك لحد ' . substr($res['period_end'], 0, 10) . '.');
                        continue;
                    }
                }
                $graceEnd = strtotime($r['period_end']) + $grace * 86400;
                if (time() >= $graceEnd) {
                    $this->pdo->prepare("UPDATE site_subscriptions SET status='expired' WHERE id=?")->execute([(int) $r['id']]);
                    $out['stopped']++;
                    $this->notify($uid, 'موقعك اتوقف مؤقتًا', 'اشتراك موقع ' . $r['slug'] . ' خلص والزوار بيشوفوا صفحة «متوقف مؤقتًا». جدّد من منشئ المواقع وموقعك يرجع فورًا بكل بياناته.');
                } elseif ($r['status'] === 'active') {
                    $this->pdo->prepare("UPDATE site_subscriptions SET status='past_due' WHERE id=?")->execute([(int) $r['id']]);
                    $out['past_due']++;
                    $this->notify($uid, 'جدّد اشتراك موقعك', 'اشتراك موقع ' . $r['slug'] . ' خلص. الموقع شغال لحد ' . date('Y-m-d', $graceEnd) . ' - جدّد من منشئ المواقع (بالرصيد أو بالتحويل) عشان مايقفش.');
                }
            } catch (Throwable $e) {
                if (class_exists('Logger')) Logger::error('Site subscription renewal failed', ['website_id' => $wid, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    private function notify(int $uid, string $title, string $body): void
    {
        if (class_exists('Notification')) {
            Notification::notify($uid, 'site_subscription', $title, $body, '/website-builder');
        }
    }

    /** لوحة الأدمن: كل المواقع وحالة اشتراكها */
    public function adminList(): array
    {
        $rows = $this->pdo->query("SELECT w.id AS website_id, w.slug, w.status AS site_status, w.custom_domain, u.email, u.company_name,
                s.cycle, s.price, s.status, s.source, s.auto_renew, s.period_end
            FROM generated_websites w JOIN users u ON u.id=w.user_id
            LEFT JOIN site_subscriptions s ON s.website_id=w.id
            ORDER BY (s.period_end IS NULL), s.period_end DESC, w.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['state'] = $this->state((int) $r['website_id'])['state'];
        }
        return $rows;
    }
}
