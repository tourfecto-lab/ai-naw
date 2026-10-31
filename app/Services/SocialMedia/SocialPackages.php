<?php

/**
 * Tourfecto - باقات السوشيال ميديا
 *
 * 4 مستويات: مجاني / أساسي / احترافي / وكالة. المستوى الفعلي للحساب = الأعلى بين:
 *   1) باقة المنصة (الانطلاقة → أساسي، النمو → احترافي، الريادة → وكالة) - الأدمن يغيّر الربط،
 *      أو يحط "social_tier" في features_json لباقة أو كاستثناء لعميل (FeatureResolver).
 *   2) اشتراك سوشيال منفصل بيتدفع من المحفظة (شهري/سنوي، تجديد تلقائي، ترقية بفرق السعر).
 *   3) أو «فصّل باقتك»: العميل يختار عدد الحسابات والمنصات والميزات اللي محتاجها بس، والسعر بيتحسب بالوحدة.
 * المستوى الفعلي = مجموع (أحسن قيمة من كل مصدر) بين باقة المنصة واشتراك السوشيال.
 * فوق المستوى: إضافات من المحفظة صالحة 30 يوم (حسابات/منشورات/منافسين/كابشن AI).
 * منصات ممكن تتقفل للكل من الأدمن (LinkedIn مقفولة افتراضيًا).
 *
 * الحدود: عدد الحسابات المربوطة، المنشورات في الشهر (كل منصة = منشور)، الخطط الشهرية، المنافسين،
 * كابشن AI (بعد حصة باقة المنصة)، المنصات المتاحة، والميزات (رد خاص، رد خاص تلقائي، تتبع الحجوزات،
 * رابط موافقة العميل، فريق العمل، تحليلات النمو). -1 = غير محدود.
 * الحسابات اللي فوق الحد مش بتتمسح: بتتقفل للنشر بس (الأقدم هو اللي بيفضل شغال).
 * كل الأسعار بالدولار؛ العرض بأي عملة عن طريق CurrencyService.
 * @version 1.0.0
 */
require_once __DIR__ . '/SocialAccounts.php';

final class SocialPackageLimit extends InvalidArgumentException
{
    public string $feature;

    public function __construct(string $message, string $feature)
    {
        parent::__construct($message);
        $this->feature = $feature;
    }
}

final class SocialPackages
{
    public const PLATFORMS = ['facebook', 'instagram', 'tiktok', 'youtube', 'google_business', 'threads', 'linkedin'];
    public const RANK = ['free' => 0, 'basic' => 1, 'pro' => 2, 'agency' => 3, 'enterprise' => 4];
    public const LIMIT_KEYS = ['accounts', 'posts', 'monthly_plans', 'competitors', 'ai_captions'];
    public const FEATURE_KEYS = ['private_reply', 'auto_private', 'attribution', 'client_review', 'team', 'growth'];

    /** أسعار وحدود افتراضية (الأدمن يعدّلها من صفحة الباقات) */
    public const DEFAULTS = [
        'free' => ['name' => 'مجاني', 'name_en' => 'Free', 'price_monthly' => 0, 'price_yearly' => 0, 'for_sale' => false,
            'accounts' => 2, 'posts' => 15, 'monthly_plans' => 0, 'competitors' => 0, 'ai_captions' => 10,
            'platforms' => ['facebook', 'instagram'],
            'private_reply' => false, 'auto_private' => false, 'attribution' => false, 'client_review' => false, 'team' => false, 'growth' => false],
        'basic' => ['name' => 'أساسي', 'name_en' => 'Basic', 'price_monthly' => 19, 'price_yearly' => 190, 'for_sale' => true,
            'accounts' => 3, 'posts' => 90, 'monthly_plans' => 1, 'competitors' => 0, 'ai_captions' => 100,
            'platforms' => self::PLATFORMS,
            'private_reply' => true, 'auto_private' => false, 'attribution' => false, 'client_review' => false, 'team' => false, 'growth' => false],
        'pro' => ['name' => 'احترافي', 'name_en' => 'Pro', 'price_monthly' => 49, 'price_yearly' => 490, 'for_sale' => true,
            'accounts' => 8, 'posts' => 250, 'monthly_plans' => 3, 'competitors' => 3, 'ai_captions' => 400,
            'platforms' => self::PLATFORMS,
            'private_reply' => true, 'auto_private' => true, 'attribution' => true, 'client_review' => true, 'team' => true, 'growth' => true],
        'agency' => ['name' => 'وكالة', 'name_en' => 'Agency', 'price_monthly' => 129, 'price_yearly' => 1290, 'for_sale' => true,
            'accounts' => 25, 'posts' => -1, 'monthly_plans' => -1, 'competitors' => 10, 'ai_captions' => 1500,
            'platforms' => self::PLATFORMS,
            'private_reply' => true, 'auto_private' => true, 'attribution' => true, 'client_review' => true, 'team' => true, 'growth' => true],
        // مستوى المؤسسات: مقفول للبيع افتراضيًا (الأدمن يفعّله من الصفحة بعد ما يراجع الأسعار)، ومش بيظهر للعملاء طول ما هو مقفول
        'enterprise' => ['name' => 'مؤسسات', 'name_en' => 'Enterprise', 'price_monthly' => 299, 'price_yearly' => 2990, 'for_sale' => false,
            'accounts' => 100, 'posts' => -1, 'monthly_plans' => -1, 'competitors' => 25, 'ai_captions' => 5000,
            'platforms' => self::PLATFORMS,
            'private_reply' => true, 'auto_private' => true, 'attribution' => true, 'client_review' => true, 'team' => true, 'growth' => true],
    ];

    /** إضافات (مرة واحدة - صالحة 30 يوم) */
    public const ADDONS = [
        'accounts_3' => ['kind' => 'accounts', 'qty' => 3, 'price' => 9, 'icon' => '🔗'],
        'posts_100' => ['kind' => 'posts', 'qty' => 100, 'price' => 6, 'icon' => '🗓️'],
        'competitors_3' => ['kind' => 'competitors', 'qty' => 3, 'price' => 5, 'icon' => '🔍'],
        'ai_200' => ['kind' => 'ai_captions', 'qty' => 200, 'price' => 5, 'icon' => '✨'],
    ];

    /**
     * «فصّل باقتك» - أسعار الوحدة شهريًا بالدولار (قريبة من السوق: Buffer ‏$6 للحساب، Publer ‏$4-5، Metricool ‏$20-25 لـ 5 براندات).
     * كل حساب بيجي معاه منشورات وكابشن AI، والباقي بلوكات. السنوي = 10 شهور (شهرين ببلاش).
     * الباقات الجاهزة أوفر من نفس المكونات مفصّلة (خصم الحزمة)، والمفصّلة للي محتاج حاجات معينة بس.
     */
    public const BUILDER = [
        'enabled' => true, 'channel' => 5.0, 'posts_per_channel' => 20, 'ai_per_channel' => 20, 'max_channels' => 50,
        'posts_block' => ['qty' => 100, 'price' => 3.0], 'ai_block' => ['qty' => 100, 'price' => 3.0],
        'competitors_block' => ['qty' => 3, 'price' => 4.0], 'monthly_plan' => 3.0,
        'features' => ['private_reply' => 3.0, 'auto_private' => 6.0, 'attribution' => 7.0, 'client_review' => 4.0, 'team' => 6.0, 'growth' => 3.0],
        'yearly_months' => 10,
    ];

    /** منصات مقفولة للكل افتراضيًا (الأدمن يفتحها) */
    public const DISABLED_PLATFORMS = ['linkedin'];

    /** باقة المنصة → مستوى السوشيال */
    public const PLAN_MAP = ['starter' => 'basic', 'professional' => 'pro', 'enterprise' => 'agency'];

    public const LABELS = [
        'accounts' => ['حسابات مربوطة', 'Connected accounts'],
        'posts' => ['منشور في الشهر', 'Posts / month'],
        'monthly_plans' => ['خطة شهرية بالذكاء الصناعي', 'AI monthly plans'],
        'competitors' => ['منافسين على إنستجرام', 'Instagram competitors'],
        'ai_captions' => ['كابشن بالذكاء الصناعي', 'AI captions'],
        'private_reply' => ['الرد في الخاص على التعليقات', 'Private replies to comments'],
        'auto_private' => ['رد خاص تلقائي على أسئلة السعر والحجز', 'Auto private reply to price/booking comments'],
        'attribution' => ['تتبع الحجوزات والإيراد لكل منشور', 'Bookings & revenue per post'],
        'client_review' => ['رابط موافقة العميل على الخطة', 'Client approval link'],
        'team' => ['فريق عمل بصلاحيات واعتماد', 'Team roles & approvals'],
        'growth' => ['تحليلات النمو والمتابعين', 'Growth & follower analytics'],
    ];

    public const PLATFORM_NAMES = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك', 'youtube' => 'يوتيوب', 'google_business' => 'جوجل بيزنس', 'threads' => 'Threads', 'linkedin' => 'LinkedIn'];

    private const SETTING = 'social_packages_config';

    private static ?array $cfgCache = null;
    private static array $entCache = [];
    private static ?bool $ready = null;

    // ------------------------------------------------------------------ الجداول

    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            $n = (int) Database::getInstance()->getConnection()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('social_subscriptions','social_addons','social_usage_monthly')")->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;
        }
        return self::$ready = $n === 3;
    }

    /** نفس migration ‏2026_10_31_000001 (سجل التعديلات + أكواد الخصم) - احتياطي لو اتنسى */
    private static function ensureExtras(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_packages_audit (
                id INT(11) NOT NULL AUTO_INCREMENT, actor_id INT(11) DEFAULT NULL, changes_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_promo_redemptions (
                id INT(11) NOT NULL AUTO_INCREMENT, promo_code VARCHAR(24) NOT NULL, user_id INT(11) NOT NULL, ref VARCHAR(80) NOT NULL,
                discount DECIMAL(10,2) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uniq_ref (ref), KEY idx_code (promo_code), KEY idx_user_code (user_id, promo_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (self::ready() && !(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_subscriptions' AND COLUMN_NAME = 'promo_code'")->fetchColumn()) {
                $pdo->exec('ALTER TABLE social_subscriptions ADD COLUMN promo_code VARCHAR(24) DEFAULT NULL');
            }
            $done = self::ready();
        } catch (Throwable $e) {
            Logger::warning('Social packages extras schema failed', ['message' => $e->getMessage()]);
        }
    }

    /** نفس migration ‏2026_10_22_000001 - احتياطي لو اتنسى (CREATE TABLE بيقفل أي transaction، فمش جوّاها) */
    public static function ensureSchema(): void
    {
        $pdo = Database::getInstance()->getConnection();
        if ($pdo->inTransaction()) {
            return;
        }
        self::ensureExtras($pdo);
        if (self::ready()) {
            // نسخة الجدول الأولى من غير أعمدة الباقة المفصّلة
            try {
                if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_subscriptions' AND COLUMN_NAME = 'custom_json'")->fetchColumn()) {
                    $pdo->exec('ALTER TABLE social_subscriptions ADD COLUMN custom_json TEXT DEFAULT NULL, ADD COLUMN pending_custom_json TEXT DEFAULT NULL');
                }
            } catch (Throwable $e) {
                Logger::warning('Social packages schema upgrade failed', ['message' => $e->getMessage()]);
            }
            return;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_subscriptions (
                id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, tier VARCHAR(16) NOT NULL, cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
                price_paid DECIMAL(10,2) NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'active', auto_renew TINYINT(1) NOT NULL DEFAULT 1,
                pending_tier VARCHAR(16) DEFAULT NULL, pending_cycle VARCHAR(10) DEFAULT NULL, period_start DATETIME NOT NULL, period_end DATETIME NOT NULL,
                last_ref VARCHAR(80) DEFAULT NULL, reminded_at DATETIME DEFAULT NULL, custom_json TEXT DEFAULT NULL, pending_custom_json TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uniq_user (user_id), KEY idx_due (status, period_end)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_addons (
                id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, addon_key VARCHAR(30) NOT NULL, kind VARCHAR(20) NOT NULL, qty INT(11) NOT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0, ref VARCHAR(80) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uniq_ref (ref), KEY idx_user (user_id, expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_usage_monthly (
                user_id INT(11) NOT NULL, month_key CHAR(7) NOT NULL, posts INT(11) NOT NULL DEFAULT 0, ai_captions INT(11) NOT NULL DEFAULT 0, monthly_plans INT(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, month_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready = null;
        } catch (Throwable $e) {
            Logger::warning('Social packages schema failed', ['message' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ إعدادات الأدمن

    /** تكلفة تشغيل تقديرية بالدولار (الأدمن يعدّلها بالتكلفة الفعلية): كابشن AI بموديل رخيص، منشور (تخزين + طابور)، حساب (مراقبة الصحة + المزامنة) */
    public const COSTS = [
        'ai_caption' => 0.003, 'post' => 0.001, 'channel_month' => 0.30, 'competitor_month' => 0.50, 'monthly_plan' => 0.05,
        'unlimited_posts_assumed' => 600, 'gateway_fee_pct' => 3.0,
    ];

    /** @return array<string,float|int> */
    private static function cleanCosts($raw): array
    {
        $c = self::COSTS;
        foreach ((array) $raw as $k => $v) {
            if (isset($c[$k]) && is_numeric($v) && $v >= 0 && $v <= ($k === 'gateway_fee_pct' ? 30 : 100000)) {
                $c[$k] = $k === 'unlimited_posts_assumed' ? (int) $v : round((float) $v, 4);
            }
        }
        return $c;
    }

    /**
     * أكواد الخصم والعروض: خصم % على أول دورة بس (التجديد بالسعر العادي).
     * @return list<array{code:string,label:string,percent:float,tiers:list<string>,cycles:list<string>,starts:string,ends:string,max_uses:int,enabled:bool}>
     */
    private static function cleanPromos($raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $p) {
            if (!is_array($p) || count($out) >= 30) {
                continue;
            }
            $code = strtoupper(trim((string) ($p['code'] ?? '')));
            $pct = is_numeric($p['percent'] ?? null) ? round((float) $p['percent'], 2) : 0.0;
            if (!preg_match('/^[A-Z0-9_-]{3,24}$/', $code) || $pct <= 0 || $pct > 100 || isset($out[$code])) {
                continue;
            }
            $date = static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
            $out[$code] = [
                'code' => $code,
                'label' => mb_substr(trim(strip_tags((string) ($p['label'] ?? ''))), 0, 60),
                'percent' => $pct,
                'tiers' => array_values(array_intersect(['basic', 'pro', 'agency', 'enterprise', 'custom'], array_map('strval', (array) ($p['tiers'] ?? [])))),
                'cycles' => array_values(array_intersect(['monthly', 'yearly'], array_map('strval', (array) ($p['cycles'] ?? [])))),
                'starts' => $date($p['starts'] ?? ''),
                'ends' => $date($p['ends'] ?? ''),
                'max_uses' => max(0, min(1000000, (int) ($p['max_uses'] ?? 0))),
                'enabled' => !array_key_exists('enabled', $p) || !empty($p['enabled']),
            ];
        }
        return array_values($out);
    }

    /**
     * يتحقق من الكود ويرجّع السعر بعد الخصم. الأخطاء رسائل مفهومة للعميل.
     * @param int $uses عدد مرات استخدام الكود لحد دلوقتي (الكل)، @param bool $usedByUser العميل استخدمه قبل كده
     * @return array{price:float,discount:float,code:string,percent:float}
     */
    public static function applyPromo(array $promos, string $code, string $tier, string $cycle, float $price, int $uses = 0, bool $usedByUser = false, ?int $now = null): array
    {
        $code = strtoupper(trim($code));
        $p = null;
        foreach ($promos as $x) {
            if ($x['code'] === $code) {
                $p = $x;
            }
        }
        $today = gmdate('Y-m-d', $now ?? time());
        if (!$p || !$p['enabled']) {
            throw new InvalidArgumentException('كود الخصم مش صحيح');
        }
        if (($p['starts'] !== '' && $today < $p['starts']) || ($p['ends'] !== '' && $today > $p['ends'])) {
            throw new InvalidArgumentException('كود الخصم مش ساري دلوقتي');
        }
        if (($p['tiers'] && !in_array($tier, $p['tiers'], true)) || ($p['cycles'] && !in_array($cycle, $p['cycles'], true))) {
            throw new InvalidArgumentException('كود الخصم مش بينطبق على الباقة أو الدورة دي');
        }
        if ($p['max_uses'] > 0 && $uses >= $p['max_uses']) {
            throw new InvalidArgumentException('كود الخصم خلصت استخداماته');
        }
        if ($usedByUser) {
            throw new InvalidArgumentException('استخدمت كود الخصم ده قبل كده');
        }
        $discount = round($price * $p['percent'] / 100, 2);
        return ['price' => round(max(0, $price - $discount), 2), 'discount' => $discount, 'code' => $p['code'], 'percent' => $p['percent']];
    }

    /**
     * تكلفة وهامش كل مستوى لو العميل استهلك الحدود كلها (أسوأ حالة). الوحدات من cfg.costs.
     * @return array<string,array{cost:float,price_monthly:float,price_yearly_per_month:float,margin_monthly:float,margin_pct_monthly:float,margin_pct_yearly:float}>
     */
    public static function economics(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $c = $cfg['costs'];
        $out = [];
        foreach ($cfg['tiers'] as $k => $t) {
            $posts = $t['posts'] < 0 ? $c['unlimited_posts_assumed'] : $t['posts'];
            $plans = $t['monthly_plans'] < 0 ? 5 : $t['monthly_plans'];
            $cost = $t['accounts'] * $c['channel_month'] + $posts * $c['post'] + $t['ai_captions'] * $c['ai_caption']
                + max(0, $t['competitors']) * $c['competitor_month'] + $plans * $c['monthly_plan'];
            $net = static fn (float $p) => $p * (1 - $c['gateway_fee_pct'] / 100);
            $pm = (float) $t['price_monthly'];
            $py = (float) $t['price_yearly'] / 12;
            $out[$k] = [
                'cost' => round($cost, 2), 'price_monthly' => $pm, 'price_yearly_per_month' => round($py, 2),
                'margin_monthly' => round($net($pm) - $cost, 2),
                'margin_pct_monthly' => $pm > 0 ? round(($net($pm) - $cost) / $pm * 100, 1) : 0.0,
                'margin_pct_yearly' => $py > 0 ? round(($net($py) - $cost) / $py * 100, 1) : 0.0,
            ];
        }
        return $out;
    }

    public static function config(): array
    {
        if (self::$cfgCache !== null) {
            return self::$cfgCache;
        }
        $raw = [];
        try {
            $raw = class_exists('SystemSettingsService') ? (json_decode((new SystemSettingsService())->get(self::SETTING, ''), true) ?: []) : [];
        } catch (Throwable $e) {
        }
        $tiers = [];
        foreach (self::DEFAULTS as $k => $d) {
            $o = is_array($raw['tiers'][$k] ?? null) ? $raw['tiers'][$k] : [];
            $t = $d;
            foreach (['name', 'name_en'] as $f) {
                if (isset($o[$f]) && is_string($o[$f]) && trim($o[$f]) !== '') {
                    $t[$f] = mb_substr(trim($o[$f]), 0, 40);
                }
            }
            if ($k !== 'free') {
                foreach (['price_monthly', 'price_yearly'] as $f) {
                    if (isset($o[$f]) && is_numeric($o[$f]) && $o[$f] >= 0 && $o[$f] <= 100000) {
                        $t[$f] = round((float) $o[$f], 2);
                    }
                }
                if (array_key_exists('for_sale', $o)) {
                    $t['for_sale'] = !empty($o['for_sale']);
                }
            }
            foreach (self::LIMIT_KEYS as $f) {
                if (isset($o[$f]) && is_numeric($o[$f]) && (int) $o[$f] >= -1 && (int) $o[$f] <= 1000000) {
                    $t[$f] = (int) $o[$f];
                }
            }
            if (isset($o['platforms']) && is_array($o['platforms'])) {
                $t['platforms'] = array_values(array_intersect(self::PLATFORMS, $o['platforms']));
            }
            foreach (self::FEATURE_KEYS as $f) {
                if (array_key_exists($f, $o)) {
                    $t[$f] = !empty($o[$f]);
                }
            }
            $tiers[$k] = $t;
        }
        $addons = [];
        foreach (self::ADDONS as $k => $a) {
            $o = is_array($raw['addons'][$k] ?? null) ? $raw['addons'][$k] : [];
            $a['enabled'] = !array_key_exists('enabled', $o) || !empty($o['enabled']);
            if (isset($o['price']) && is_numeric($o['price']) && $o['price'] > 0 && $o['price'] <= 10000) {
                $a['price'] = round((float) $o['price'], 2);
            }
            if (isset($o['qty']) && is_numeric($o['qty']) && (int) $o['qty'] > 0 && (int) $o['qty'] <= 100000) {
                $a['qty'] = (int) $o['qty'];
            }
            $addons[$k] = $a;
        }
        $map = self::PLAN_MAP;
        foreach ((array) ($raw['plan_map'] ?? []) as $plan => $tier) {
            if (is_string($plan) && preg_match('/^[a-z0-9-]+$/', $plan) && isset(self::RANK[$tier])) {
                $map[$plan] = $tier;
            }
        }
        $b = self::BUILDER;
        $o = is_array($raw['builder'] ?? null) ? $raw['builder'] : [];
        if (array_key_exists('enabled', $o)) {
            $b['enabled'] = !empty($o['enabled']);
        }
        foreach (['channel', 'monthly_plan'] as $f) {
            if (isset($o[$f]) && is_numeric($o[$f]) && $o[$f] >= 0 && $o[$f] <= 10000) {
                $b[$f] = round((float) $o[$f], 2);
            }
        }
        foreach (['posts_per_channel', 'ai_per_channel', 'max_channels'] as $f) {
            if (isset($o[$f]) && is_numeric($o[$f]) && (int) $o[$f] >= ($f === 'max_channels' ? 1 : 0) && (int) $o[$f] <= 100000) {
                $b[$f] = (int) $o[$f];
            }
        }
        foreach (['posts_block', 'ai_block', 'competitors_block'] as $f) {
            if (isset($o[$f]['qty']) && is_numeric($o[$f]['qty']) && (int) $o[$f]['qty'] > 0 && (int) $o[$f]['qty'] <= 100000) {
                $b[$f]['qty'] = (int) $o[$f]['qty'];
            }
            if (isset($o[$f]['price']) && is_numeric($o[$f]['price']) && $o[$f]['price'] >= 0 && $o[$f]['price'] <= 10000) {
                $b[$f]['price'] = round((float) $o[$f]['price'], 2);
            }
        }
        foreach (self::FEATURE_KEYS as $f) {
            if (isset($o['features'][$f]) && is_numeric($o['features'][$f]) && $o['features'][$f] >= 0 && $o['features'][$f] <= 10000) {
                $b['features'][$f] = round((float) $o['features'][$f], 2);
            }
        }
        if (isset($o['yearly_months']) && is_numeric($o['yearly_months']) && $o['yearly_months'] >= 1 && $o['yearly_months'] <= 12) {
            $b['yearly_months'] = (float) $o['yearly_months'];
        }
        $enabled = isset($raw['platforms_enabled']) && is_array($raw['platforms_enabled'])
            ? array_values(array_intersect(self::PLATFORMS, $raw['platforms_enabled']))
            : array_values(array_diff(self::PLATFORMS, self::DISABLED_PLATFORMS));
        return self::$cfgCache = [
            'promos' => self::cleanPromos($raw['promos'] ?? []),
            'costs' => self::cleanCosts($raw['costs'] ?? []),
            'renewal_policy' => ($raw['renewal_policy'] ?? '') === 'grandfather' ? 'grandfather' : 'at_renewal',
            'builder' => $b,
            'platforms_enabled' => $enabled,
            // إيقاف التطبيق = كل حساب شغال من غير حدود (زي ما كان قبل الباقات)
            'enforce' => ($raw['enforce'] ?? true) !== false,
            'plan_default' => isset(self::RANK[$raw['plan_default'] ?? '']) ? $raw['plan_default'] : 'basic',
            'tiers' => $tiers,
            'addons' => $addons,
            'plan_map' => $map,
        ];
    }

    /** @return array<string,mixed> مسطّح: مسار.بنقط => قيمة (للمقارنة في سجل التعديلات) */
    private static function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
            }
        }
        return $out;
    }

    /** آخر تعديلات الأدمن على الباقات (الأحدث الأول) */
    public static function auditLog(int $limit = 50): array
    {
        self::ensureSchema();
        try {
            $rows = Database::getInstance()->query('SELECT a.id, a.actor_id, a.changes_json, a.created_at, u.email AS actor_email FROM social_packages_audit a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit))) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'actor' => $r['actor_email'] ?: ($r['actor_id'] ? '#' . $r['actor_id'] : '—'), 'at' => $r['created_at'], 'changes' => json_decode((string) $r['changes_json'], true) ?: []], $rows);
    }

    public static function saveConfig(array $in, ?int $actorId = null): array
    {
        $before = self::flatten(self::config());
        $raw = [];
        try {
            $raw = json_decode((new SystemSettingsService())->get(self::SETTING, ''), true) ?: [];
        } catch (Throwable $e) {
        }
        if (array_key_exists('enforce', $in)) {
            $raw['enforce'] = !empty($in['enforce']);
        }
        if (isset($in['plan_default']) && isset(self::RANK[$in['plan_default']])) {
            $raw['plan_default'] = $in['plan_default'];
        }
        foreach ((array) ($in['tiers'] ?? []) as $k => $o) {
            if (!isset(self::DEFAULTS[$k]) || !is_array($o)) {
                continue;
            }
            $clean = [];
            foreach (['name', 'name_en'] as $f) {
                if (isset($o[$f]) && is_string($o[$f])) {
                    $clean[$f] = mb_substr(trim(strip_tags($o[$f])), 0, 40);
                }
            }
            foreach (['price_monthly', 'price_yearly'] as $f) {
                if (isset($o[$f]) && is_numeric($o[$f])) {
                    $clean[$f] = max(0, min(100000, round((float) $o[$f], 2)));
                }
            }
            foreach (self::LIMIT_KEYS as $f) {
                if (isset($o[$f]) && is_numeric($o[$f])) {
                    $clean[$f] = max(-1, min(1000000, (int) $o[$f]));
                }
            }
            if (isset($o['platforms']) && is_array($o['platforms'])) {
                $clean['platforms'] = array_values(array_intersect(self::PLATFORMS, $o['platforms']));
            }
            foreach (array_merge(self::FEATURE_KEYS, ['for_sale']) as $f) {
                if (array_key_exists($f, $o)) {
                    $clean[$f] = !empty($o[$f]);
                }
            }
            $raw['tiers'][$k] = $clean + (is_array($raw['tiers'][$k] ?? null) ? $raw['tiers'][$k] : []);
        }
        foreach ((array) ($in['addons'] ?? []) as $k => $o) {
            if (isset(self::ADDONS[$k]) && is_array($o)) {
                $raw['addons'][$k] = array_intersect_key($o, ['price' => 1, 'qty' => 1, 'enabled' => 1]);
            }
        }
        foreach ((array) ($in['plan_map'] ?? []) as $plan => $tier) {
            if (is_string($plan) && preg_match('/^[a-z0-9-]+$/', $plan) && isset(self::RANK[$tier])) {
                $raw['plan_map'][$plan] = $tier;
            }
        }
        if (isset($in['platforms_enabled']) && is_array($in['platforms_enabled'])) {
            $raw['platforms_enabled'] = array_values(array_intersect(self::PLATFORMS, $in['platforms_enabled']));
        }
        if (isset($in['builder']) && is_array($in['builder'])) {
            $bi = $in['builder'];
            $clean = [];
            if (array_key_exists('enabled', $bi)) {
                $clean['enabled'] = !empty($bi['enabled']);
            }
            foreach (['channel', 'monthly_plan', 'posts_per_channel', 'ai_per_channel', 'max_channels', 'yearly_months'] as $f) {
                if (isset($bi[$f]) && is_numeric($bi[$f])) {
                    $clean[$f] = max(0, min(100000, (float) $bi[$f]));
                }
            }
            foreach (['posts_block', 'ai_block', 'competitors_block'] as $f) {
                foreach (['qty', 'price'] as $g) {
                    if (isset($bi[$f][$g]) && is_numeric($bi[$f][$g])) {
                        $clean[$f][$g] = max(0, min(100000, (float) $bi[$f][$g]));
                    }
                }
            }
            foreach (self::FEATURE_KEYS as $f) {
                if (isset($bi['features'][$f]) && is_numeric($bi['features'][$f])) {
                    $clean['features'][$f] = max(0, min(10000, round((float) $bi['features'][$f], 2)));
                }
            }
            $raw['builder'] = array_replace_recursive(is_array($raw['builder'] ?? null) ? $raw['builder'] : [], $clean);
        }
        if (array_key_exists('promos', $in)) {
            $raw['promos'] = self::cleanPromos($in['promos']);
        }
        if (isset($in['costs']) && is_array($in['costs'])) {
            $raw['costs'] = self::cleanCosts($in['costs'] + (array) ($raw['costs'] ?? []));
        }
        if (isset($in['renewal_policy'])) {
            $raw['renewal_policy'] = $in['renewal_policy'] === 'grandfather' ? 'grandfather' : 'at_renewal';
        }
        (new SystemSettingsService())->set(self::SETTING, json_encode($raw, JSON_UNESCAPED_UNICODE));
        self::$cfgCache = null;
        self::$entCache = [];
        $cfg = self::config();
        $after = self::flatten($cfg);
        $changes = [];
        foreach ($after + $before as $path => $_) {
            if (($before[$path] ?? null) != ($after[$path] ?? null)) {
                $changes[$path] = ['old' => $before[$path] ?? null, 'new' => $after[$path] ?? null];
            }
        }
        if ($changes) {
            self::ensureSchema();
            try {
                Database::getInstance()->query('INSERT INTO social_packages_audit (actor_id, changes_json) VALUES (?, ?)', [$actorId, json_encode($changes, JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $e) {
                Logger::warning('Social packages audit insert failed', ['message' => $e->getMessage()]);
            }
        }
        return $cfg;
    }

    // ------------------------------------------------------------------ المستوى الفعلي

    /** أعضاء الفريق بيستخدموا باقة صاحب الحساب (نفس PlanUsage) */
    public static function owner(int $userId): int
    {
        try {
            $r = Database::getInstance()->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId]);
            $o = (int) ($r[0]['owner_user_id'] ?? 0);
            return $o > 0 ? $o : $userId;
        } catch (Throwable $e) {
            return $userId;
        }
    }

    /** مستوى السوشيال اللي باقة المنصة بتديه (null = مفيش باقة شغالة) */
    public static function tierFromPlan(int $owner): ?string
    {
        if (!class_exists('Subscription')) {
            return null;
        }
        try {
            $sub = Subscription::activeSubscriptionRow($owner);
        } catch (Throwable $e) {
            $sub = null;
        }
        if (!$sub) {
            return null;
        }
        $cfg = self::config();
        try {
            $forced = class_exists('FeatureResolver') ? FeatureResolver::value($owner, 'social_tier') : null;
        } catch (Throwable $e) {
            $forced = null;
        }
        if (is_string($forced) && isset(self::RANK[$forced])) {
            return $forced;
        }
        return $cfg['plan_map'][(string) ($sub['plan_name'] ?? '')] ?? $cfg['plan_default'];
    }

    /** اشتراك السوشيال المنفصل الشغال (أو null) */
    public static function standalone(int $owner, bool $lock = false): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $sql = 'SELECT * FROM social_subscriptions WHERE user_id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $row = Database::getInstance()->query($sql, [$owner])[0] ?? null;
        return $row ?: null;
    }

    /** الاشتراك المنفصل شغال؟ (مع يوم سماح لو التجديد التلقائي لسه ماتنفذش) */
    public static function standaloneActive(?array $row): bool
    {
        if (!$row || $row['status'] !== 'active') {
            return false;
        }
        $end = strtotime($row['period_end'] . ' UTC');
        return $end > time() || (!empty($row['auto_renew']) && $end > time() - 86400);
    }

    /** حدود/ميزات/منصات مستوى جاهز */
    private static function tierSpec(string $tier): array
    {
        $t = self::config()['tiers'][$tier] ?? self::config()['tiers']['free'];
        $limits = [];
        foreach (self::LIMIT_KEYS as $k) {
            $limits[$k] = (int) $t[$k];
        }
        $features = [];
        foreach (self::FEATURE_KEYS as $k) {
            $features[$k] = !empty($t[$k]);
        }
        return ['limits' => $limits, 'features' => $features, 'platforms' => $t['platforms']];
    }

    /**
     * «فصّل باقتك»: بيتأكد من الاختيارات ويحسب السعر والحدود.
     * $in = {channels, platforms[], features{key:bool}, posts_blocks, ai_blocks, competitor_blocks, monthly_plans}
     * @return array{spec:array, monthly:float, yearly:float, lines:array, limits:array, features:array, platforms:array}
     */
    public static function quoteCustom(array $in): array
    {
        $cfg = self::config();
        $b = $cfg['builder'];
        if (empty($b['enabled'])) {
            throw new InvalidArgumentException('«فصّل باقتك» مش متاحة دلوقتي');
        }
        $int = static fn ($v, int $min, int $max): int => max($min, min($max, (int) (is_numeric($v) ? $v : 0)));
        $platforms = array_values(array_intersect($cfg['platforms_enabled'], array_map('strval', (array) ($in['platforms'] ?? []))));
        if (!$platforms) {
            throw new InvalidArgumentException('اختار منصة واحدة على الأقل');
        }
        $channels = $int($in['channels'] ?? 1, 1, (int) $b['max_channels']);
        if ($channels < count($platforms)) {
            $channels = count($platforms);
        }
        $features = [];
        foreach (self::FEATURE_KEYS as $k) {
            $features[$k] = !empty($in['features'][$k]);
        }
        if ($features['auto_private']) {
            $features['private_reply'] = true; // التلقائي مبني على الرد الخاص
        }
        $spec = [
            'channels' => $channels, 'platforms' => $platforms, 'features' => $features,
            'posts_blocks' => $int($in['posts_blocks'] ?? 0, 0, 100), 'ai_blocks' => $int($in['ai_blocks'] ?? 0, 0, 100),
            'competitor_blocks' => $int($in['competitor_blocks'] ?? 0, 0, 10), 'monthly_plans' => $int($in['monthly_plans'] ?? 0, 0, 30),
        ];
        $lines = [];
        $lines[] = ['channels', $channels, round($channels * $b['channel'], 2)];
        if ($spec['posts_blocks']) {
            $lines[] = ['posts', $spec['posts_blocks'] * $b['posts_block']['qty'], round($spec['posts_blocks'] * $b['posts_block']['price'], 2)];
        }
        if ($spec['ai_blocks']) {
            $lines[] = ['ai_captions', $spec['ai_blocks'] * $b['ai_block']['qty'], round($spec['ai_blocks'] * $b['ai_block']['price'], 2)];
        }
        if ($spec['competitor_blocks']) {
            $lines[] = ['competitors', $spec['competitor_blocks'] * $b['competitors_block']['qty'], round($spec['competitor_blocks'] * $b['competitors_block']['price'], 2)];
        }
        if ($spec['monthly_plans']) {
            $lines[] = ['monthly_plans', $spec['monthly_plans'], round($spec['monthly_plans'] * $b['monthly_plan'], 2)];
        }
        foreach ($features as $k => $on) {
            if ($on) {
                $lines[] = [$k, 1, round((float) $b['features'][$k], 2)];
            }
        }
        $monthly = round(array_sum(array_column($lines, 2)), 2);
        $limits = [
            'accounts' => $channels,
            'posts' => $channels * (int) $b['posts_per_channel'] + $spec['posts_blocks'] * (int) $b['posts_block']['qty'],
            'ai_captions' => $channels * (int) $b['ai_per_channel'] + $spec['ai_blocks'] * (int) $b['ai_block']['qty'],
            'competitors' => $spec['competitor_blocks'] * (int) $b['competitors_block']['qty'],
            'monthly_plans' => $spec['monthly_plans'],
        ];
        return ['spec' => $spec, 'monthly' => $monthly, 'yearly' => round($monthly * (float) $b['yearly_months'], 2), 'lines' => $lines,
            'limits' => $limits, 'features' => $features, 'platforms' => $platforms];
    }

    /** مواصفات الاشتراك المنفصل (جاهز أو مفصّل) */
    private static function subscriptionSpec(array $row): ?array
    {
        if ($row['tier'] === 'custom') {
            try {
                $q = self::quoteCustom(json_decode((string) $row['custom_json'], true) ?: []);
            } catch (Throwable $e) {
                // الأدمن قفل «فصّل باقتك» بعد ما العميل اشترك: اللي دفعه بيفضل شغال لآخر المدة
                $spec = json_decode((string) $row['custom_json'], true) ?: [];
                if (!$spec) {
                    return null;
                }
                return ['limits' => ['accounts' => (int) ($spec['channels'] ?? 1), 'posts' => (int) ($spec['channels'] ?? 1) * 20, 'ai_captions' => (int) ($spec['channels'] ?? 1) * 20, 'competitors' => 0, 'monthly_plans' => 0],
                    'features' => array_map('boolval', (array) ($spec['features'] ?? [])) + array_fill_keys(self::FEATURE_KEYS, false), 'platforms' => (array) ($spec['platforms'] ?? [])];
            }
            return ['limits' => $q['limits'], 'features' => $q['features'], 'platforms' => $q['platforms']];
        }
        return isset(self::DEFAULTS[$row['tier']]) ? self::tierSpec($row['tier']) : null;
    }

    public static function entitlements(int $account): array
    {
        $owner = self::owner($account);
        if (isset(self::$entCache[$owner])) {
            return self::$entCache[$owner];
        }
        $cfg = self::config();
        $planTier = self::tierFromPlan($owner);
        $row = self::standalone($owner);
        $active = self::standaloneActive($row);
        $socialTier = $active ? $row['tier'] : null;
        // كل مصدر شغال بيضيف: أحسن حد، أي ميزة، وكل المنصات (باقة المنصة + اشتراك السوشيال)
        $specs = [];
        $names = [];
        if ($planTier) {
            $specs[] = self::tierSpec($planTier);
            $names[] = [$cfg['tiers'][$planTier]['name'], $cfg['tiers'][$planTier]['name_en']];
        }
        if ($active && ($sp = self::subscriptionSpec($row))) {
            $specs[] = $sp;
            $names[] = $socialTier === 'custom' ? ['باقة مفصّلة', 'Custom plan'] : [$cfg['tiers'][$socialTier]['name'], $cfg['tiers'][$socialTier]['name_en']];
        }
        if (!$specs) {
            $specs[] = self::tierSpec('free');
            $names[] = [$cfg['tiers']['free']['name'], $cfg['tiers']['free']['name_en']];
        }
        $limits = array_fill_keys(self::LIMIT_KEYS, 0);
        $features = array_fill_keys(self::FEATURE_KEYS, false);
        $platforms = [];
        foreach ($specs as $sp) {
            foreach (self::LIMIT_KEYS as $k) {
                $v = (int) ($sp['limits'][$k] ?? 0);
                $limits[$k] = ($limits[$k] < 0 || $v < 0) ? -1 : max($limits[$k], $v);
            }
            foreach (self::FEATURE_KEYS as $k) {
                $features[$k] = $features[$k] || !empty($sp['features'][$k]);
            }
            $platforms = array_merge($platforms, (array) $sp['platforms']);
        }
        $extra = self::addonTotals($owner);
        foreach (self::LIMIT_KEYS as $k) {
            if ($limits[$k] >= 0) {
                $limits[$k] += $extra[$k] ?? 0;
            }
        }
        $platforms = array_values(array_intersect($cfg['platforms_enabled'], array_unique($platforms)));
        // المستوى المعروض: الأعلى بين الجاهزين، أو «مفصّلة» لو هي اللي العميل دافعها
        $tier = 'free';
        foreach ([$planTier, $socialTier] as $t) {
            if ($t && isset(self::RANK[$t]) && self::RANK[$t] > self::RANK[$tier]) {
                $tier = $t;
            }
        }
        if ($socialTier === 'custom' && $tier === 'free') {
            $tier = 'custom';
        }
        $source = $socialTier ? 'social' : ($planTier ? 'plan' : 'free');
        if (!$cfg['enforce']) {
            $limits = array_fill_keys(self::LIMIT_KEYS, -1);
            $features = array_fill_keys(self::FEATURE_KEYS, true);
            $platforms = $cfg['platforms_enabled'];
        }
        return self::$entCache[$owner] = [
            'owner' => $owner, 'tier' => $tier, 'tier_name' => implode(' + ', array_column($names, 0)), 'tier_name_en' => implode(' + ', array_column($names, 1)), 'source' => $source,
            'plan_tier' => $planTier, 'social_tier' => $socialTier, 'enforced' => $cfg['enforce'],
            'limits' => $limits, 'features' => $features, 'platforms' => $platforms, 'subscription' => $row,
            'custom' => $socialTier === 'custom' ? (json_decode((string) $row['custom_json'], true) ?: null) : null,
        ];
    }

    public static function flush(): void
    {
        self::$entCache = [];
    }

    private static function addonTotals(int $owner): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::getInstance()->query('SELECT kind, SUM(qty) AS q FROM social_addons WHERE user_id = ? AND expires_at > UTC_TIMESTAMP() GROUP BY kind', [$owner]) ?: [] as $r) {
                $out[$r['kind']] = (int) $r['q'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    // ------------------------------------------------------------------ الاستخدام

    public static function monthKey(): string
    {
        return gmdate('Y-m');
    }

    /** الحسابات المربوطة (الأقدم الأول) - نفس شرط الملكية بتاع النشر. active=0: العميل وقّفها من تبويب الحسابات */
    public static function connections(int $account): array
    {
        try {
            $rows = Database::getInstance()->query(
                "SELECT id, platform, external_location_name AS name FROM platform_connections
                 WHERE (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?))
                   AND platform IN ('" . implode("','", self::PLATFORMS) . "') AND status = 'connected' ORDER BY id",
                [$account, $account]
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $off = class_exists('SocialAccounts') ? array_flip(SocialAccounts::inactiveIds($account)) : [];
        foreach ($rows as &$r) {
            $r['active'] = !isset($off[(int) $r['id']]);
        }
        return $rows;
    }

    /** id => سبب القفل ('inactive' العميل وقّفها | 'platform' المنصة مش في الباقة | 'accounts' فوق عدد الحسابات) */
    public static function locked(int $account, ?array $ent = null): array
    {
        $ent = $ent ?? self::entitlements($account);
        $out = [];
        $n = 0;
        $max = $ent['limits']['accounts'];
        foreach (self::connections($account) as $c) {
            if (empty($c['active'])) {
                $out[(int) $c['id']] = 'inactive';
                continue;
            }
            if (!in_array($c['platform'], $ent['platforms'], true)) {
                $out[(int) $c['id']] = 'platform';
                continue;
            }
            $n++;
            if ($max >= 0 && $n > $max) {
                $out[(int) $c['id']] = 'accounts';
            }
        }
        return $out;
    }

    public static function usage(int $account): array
    {
        $owner = self::owner($account);
        $ent = self::entitlements($account);
        $u = ['posts' => 0, 'ai_captions' => 0, 'monthly_plans' => 0];
        if (self::ready()) {
            try {
                $r = Database::getInstance()->query('SELECT posts, ai_captions, monthly_plans FROM social_usage_monthly WHERE user_id = ? AND month_key = ?', [$owner, self::monthKey()])[0] ?? null;
                if ($r) {
                    $u = array_map('intval', $r);
                }
            } catch (Throwable $e) {
            }
        }
        $accounts = 0;
        foreach (self::connections($account) as $c) {
            if (!empty($c['active']) && in_array($c['platform'], $ent['platforms'], true)) {
                $accounts++;
            }
        }
        $competitors = 0;
        try {
            $competitors = (int) (Database::getInstance()->query('SELECT COUNT(*) AS n FROM social_competitors WHERE user_id = ?', [$account])[0]['n'] ?? 0);
        } catch (Throwable $e) {
        }
        return ['accounts' => $accounts, 'competitors' => $competitors] + $u;
    }

    /**
     * أعلى الحسابات استهلاكًا للشهر الحالي مع التكلفة التقديرية (من cfg.costs) وعلامة تنبيه عند 80% من أي حد.
     * @return list<array{user_id:int,email:string,tier:string,posts:int,posts_limit:int,ai:int,ai_limit:int,est_cost:float,alert:bool}>
     */
    public static function usageReport(int $limit = 30, ?string $month = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $cfg = self::config();
        $c = $cfg['costs'];
        $out = [];
        try {
            $rows = Database::getInstance()->query('SELECT m.user_id, m.posts, m.ai_captions, u.email FROM social_usage_monthly m LEFT JOIN users u ON u.id = m.user_id WHERE m.month_key = ? ORDER BY (m.posts * ? + m.ai_captions * ?) DESC LIMIT ' . max(1, min(100, $limit)), [$month ?? self::monthKey(), $c['post'], $c['ai_caption']]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $ent = self::entitlements($uid);
            $lim = $ent['limits'];
            $pl = (int) $lim['posts'];
            $al = (int) $lim['ai_captions'];
            $posts = (int) $r['posts'];
            $ai = (int) $r['ai_captions'];
            $hot = static fn (int $used, int $max) => $max > 0 && $used >= 0.8 * $max;
            $out[] = ['user_id' => $uid, 'email' => (string) ($r['email'] ?? '#' . $uid), 'tier' => (string) $ent['tier'], 'posts' => $posts, 'posts_limit' => $pl, 'ai' => $ai, 'ai_limit' => $al,
                'est_cost' => round($posts * $c['post'] + $ai * $c['ai_caption'], 2), 'alert' => $hot($posts, $pl) || $hot($ai, $al)];
        }
        return $out;
    }

    /** بيسجّل استخدام (منشورات/خطط) للشهر الحالي */
    public static function record(int $account, string $kind, int $n = 1): void
    {
        if ($n < 1 || !in_array($kind, ['posts', 'monthly_plans', 'ai_captions'], true) || !self::ready()) {
            return;
        }
        try {
            Database::getInstance()->query("INSERT INTO social_usage_monthly (user_id, month_key, {$kind}) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE {$kind} = {$kind} + VALUES({$kind})", [self::owner($account), self::monthKey(), $n]);
        } catch (Throwable $e) {
            Logger::warning('Social usage record failed', ['kind' => $kind, 'message' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ الفحوصات

    /** "متاحة من باقة X" لأول مستوى بيشمل الميزة/المنصة */
    private static function upgradeHint(string $feature, ?string $platform = null): string
    {
        foreach (['basic', 'pro', 'agency', 'enterprise'] as $k) {
            $t = self::config()['tiers'][$k];
            if (($platform !== null && in_array($platform, $t['platforms'], true)) || ($platform === null && !empty($t[$feature]))) {
                return ' - متاحة من باقة «' . $t['name'] . '» أو ضيفها لوحدها في «فصّل باقتك» من صفحة الاشتراكات.';
            }
        }
        return ' - رقّي باقتك من صفحة الاشتراكات.';
    }

    public static function has(int $account, string $feature): bool
    {
        return !empty(self::entitlements($account)['features'][$feature]);
    }

    public static function assertFeature(int $account, string $feature): void
    {
        if (!self::has($account, $feature)) {
            throw new SocialPackageLimit('«' . (self::LABELS[$feature][0] ?? $feature) . '» مش ضمن باقتك' . self::upgradeHint($feature), $feature);
        }
    }

    /** الحسابات المختارة مش مقفولة (منصة برا الباقة أو فوق عدد الحسابات) */
    public static function assertConnections(int $account, array $connectionIds): void
    {
        $ent = self::entitlements($account);
        if (!$ent['enforced']) {
            return;
        }
        $locked = self::locked($account, $ent);
        foreach (array_unique(array_map('intval', $connectionIds)) as $cid) {
            if (!isset($locked[$cid])) {
                continue;
            }
            $p = (string) (Database::getInstance()->query('SELECT platform FROM platform_connections WHERE id = ? LIMIT 1', [$cid])[0]['platform'] ?? '');
            $name = self::PLATFORM_NAMES[$p] ?? $p;
            if ($locked[$cid] === 'inactive') {
                throw new SocialPackageLimit('حساب ' . $name . ' ده موقوف من تبويب «الحسابات» - شغّله الأول عشان تنشر عليه', 'inactive');
            }
            if ($locked[$cid] === 'platform' && !in_array($p, self::config()['platforms_enabled'], true)) {
                throw new SocialPackageLimit('النشر على ' . $name . ' مش متاح على المنصة دلوقتي', 'platform');
            }
            if ($locked[$cid] === 'platform') {
                throw new SocialPackageLimit('النشر على ' . $name . ' مش ضمن باقة «' . $ent['tier_name'] . '»' . self::upgradeHint('platform', $p), 'platform');
            }
            throw new SocialPackageLimit('حساب ' . $name . ' ده فوق عدد الحسابات في باقتك (' . $ent['limits']['accounts'] . ') - رقّي باقتك أو اشتري إضافة حسابات من صفحة الاشتراكات.', 'accounts');
        }
    }

    /** قبل إنشاء أهداف نشر: الحسابات مش مقفولة، وفاضل منشورات كفاية في الشهر */
    public static function assertPublish(int $account, array $connectionIds, int $count): void
    {
        $ent = self::entitlements($account);
        if (!$ent['enforced'] || $count < 1) {
            return;
        }
        self::assertConnections($account, $connectionIds);
        $limit = $ent['limits']['posts'];
        if ($limit >= 0) {
            $used = self::usage($account)['posts'];
            if ($used + $count > $limit) {
                throw new SocialPackageLimit('وصلت لحد المنشورات الشهري في باقتك (' . $used . ' من ' . $limit . '، والمطلوب ' . $count . '). كل منصة بتتحسب منشور. رقّي باقتك أو اشتري إضافة منشورات من صفحة الاشتراكات.', 'posts');
            }
        }
    }

    /**
     * Reserve a monthly publish allowance atomically in the caller's transaction.
     * The caller MUST roll back the same PDO transaction if creating targets/jobs fails.
     * Unlike assertPublish()+record(), concurrent approvals cannot both consume the
     * final available slot. This intentionally does not reserve AI generation credits.
     */
    public static function reservePostsAtomic(int $account, array $connectionIds, int $count): void
    {
        if ($count < 1) {
            return;
        }
        $ent = self::entitlements($account);
        if (!$ent['enforced']) {
            return;
        }
        self::assertConnections($account, $connectionIds);
        if (!self::ready()) {
            throw new RuntimeException('Social usage tracking is unavailable; cannot reserve publishing quota safely.');
        }
        $pdo = Database::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('reservePostsAtomic requires an active database transaction.');
        }
        $owner = self::owner($account);
        $month = self::monthKey();
        $insert = $pdo->prepare('INSERT IGNORE INTO social_usage_monthly (user_id, month_key, posts) VALUES (?, ?, 0)');
        $insert->execute([$owner, $month]);
        $limit = (int)$ent['limits']['posts'];
        $sql = 'UPDATE social_usage_monthly SET posts = posts + ? WHERE user_id = ? AND month_key = ?';
        if ($limit >= 0) {
            $sql .= ' AND posts <= ?';
        }
        $stmt = $pdo->prepare($sql);
        $args = [$count, $owner, $month];
        if ($limit >= 0) {
            $args[] = $limit - $count;
        }
        $stmt->execute($args);
        if ($stmt->rowCount() !== 1) {
            throw new SocialPackageLimit('Monthly publishing allowance reached. Review your plan or purchase more posts.', 'posts');
        }
    }

    /** قبل ربط حساب جديد على منصة */
    public static function assertConnect(int $account, string $platform): void
    {
        $ent = self::entitlements($account);
        if (!$ent['enforced']) {
            return;
        }
        if (!in_array($platform, self::config()['platforms_enabled'], true)) {
            throw new SocialPackageLimit((self::PLATFORM_NAMES[$platform] ?? $platform) . ' مش متاحة على المنصة دلوقتي', 'platform');
        }
        if (!in_array($platform, $ent['platforms'], true)) {
            throw new SocialPackageLimit('ربط ' . (self::PLATFORM_NAMES[$platform] ?? $platform) . ' مش ضمن باقة «' . $ent['tier_name'] . '»' . self::upgradeHint('platform', $platform), 'platform');
        }
        $max = $ent['limits']['accounts'];
        if ($max < 0) {
            return;
        }
        $mine = array_filter(self::connections($account), static fn($c) => !empty($c['active']) && in_array($c['platform'], $ent['platforms'], true));
        $samePlatform = array_filter($mine, static fn($c) => $c['platform'] === $platform);
        // إعادة ربط حساب موجود (تجديد التوكن) مسموحة حتى لو وصل للحد
        if (count($mine) >= $max && !$samePlatform) {
            throw new SocialPackageLimit('وصلت لحد الحسابات في باقتك (' . $max . '). رقّي باقتك أو اشتري إضافة حسابات من صفحة الاشتراكات.', 'accounts');
        }
    }

    public static function assertCompetitorSlot(int $account): void
    {
        $ent = self::entitlements($account);
        $max = $ent['limits']['competitors'];
        if ($max >= 0 && self::usage($account)['competitors'] >= $max) {
            throw new SocialPackageLimit($max === 0
                ? 'متابعة المنافسين مش ضمن باقة «' . $ent['tier_name'] . '» - متاحة من باقة «' . self::config()['tiers']['pro']['name'] . '». رقّي من صفحة الاشتراكات.'
                : 'وصلت لحد المنافسين في باقتك (' . $max . '). شيل منافس أو اشتري إضافة من صفحة الاشتراكات.', 'competitors');
        }
    }

    public static function assertMonthlyPlan(int $account, array $connectionIds): void
    {
        $ent = self::entitlements($account);
        if (!$ent['enforced']) {
            return;
        }
        $max = $ent['limits']['monthly_plans'];
        if ($max >= 0 && self::usage($account)['monthly_plans'] >= $max) {
            throw new SocialPackageLimit($max === 0
                ? 'الخطة الشهرية بالذكاء الصناعي مش ضمن باقة «' . $ent['tier_name'] . '» - رقّي من صفحة الاشتراكات.'
                : 'استخدمت كل الخطط الشهرية في باقتك الشهر ده (' . $max . '). رقّي باقتك من صفحة الاشتراكات.', 'monthly_plans');
        }
        self::assertConnections($account, $connectionIds);
    }

    /** كابشن AI من حصة باقة السوشيال (بعد حصة باقة المنصة وقبل المحفظة). ذرّي. */
    public static function consumeAi(int $account): bool
    {
        $ent = self::entitlements($account);
        if (!$ent['enforced'] || !self::ready()) {
            return false;
        }
        $limit = $ent['limits']['ai_captions'];
        if ($limit === 0) {
            return false;
        }
        $owner = $ent['owner'];
        $db = Database::getInstance();
        try {
            $db->query('INSERT IGNORE INTO social_usage_monthly (user_id, month_key) VALUES (?, ?)', [$owner, self::monthKey()]);
            $s = $db->getConnection()->prepare('UPDATE social_usage_monthly SET ai_captions = ai_captions + 1 WHERE user_id = ? AND month_key = ?' . ($limit > 0 ? ' AND ai_captions < ' . (int) $limit : ''));
            $s->execute([$owner, self::monthKey()]);
            return $s->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ الشراء من المحفظة

    private static function periodEnd(int $from, string $cycle): int
    {
        return (int) strtotime(gmdate('Y-m-d H:i:s', $from) . ' UTC +' . ($cycle === 'yearly' ? '1 year' : '1 month'));
    }

    /** سعر الدورة ومواصفات الباقة المطلوبة (جاهزة أو مفصّلة) */
    private static function offer(string $tier, string $cycle, ?array $custom): array
    {
        $cfg = self::config();
        if (!in_array($cycle, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('اختار شهري أو سنوي');
        }
        if ($tier === 'custom') {
            $q = self::quoteCustom($custom ?? []);
            if ($q['monthly'] <= 0) {
                throw new InvalidArgumentException('الباقة فاضية');
            }
            return ['price' => $q[$cycle], 'name' => 'باقة مفصّلة', 'custom_json' => json_encode($q['spec'], JSON_UNESCAPED_UNICODE)];
        }
        $t = $cfg['tiers'][$tier] ?? null;
        if (!$t || $tier === 'free' || empty($t['for_sale'])) {
            throw new InvalidArgumentException('الباقة دي مش متاحة');
        }
        return ['price' => (float) $t['price_' . $cycle], 'name' => $t['name'], 'custom_json' => null];
    }

    /** سعر شهري مكافئ (للمقارنة بين ترقية وتخفيض) */
    private static function perMonth(float $price, string $cycle): float
    {
        return $cycle === 'yearly' ? $price / 12 : $price;
    }

    /**
     * اشتراك/ترقية/تغيير (جاهزة أو مفصّلة). الأغلى فوري بفرق السعر (الباقي من المدة بيتحسب رصيد)،
     * والأرخص أو تغيير الدورة بيتطبق عند التجديد.
     * @return array{action:string, tier:string, cycle:string, charged:float, period_end:string}
     */
    public static function subscribe(int $owner, string $tier, string $cycle, ?string $requestKey = null, ?array $custom = null, ?string $promoCode = null): array
    {
        self::ensureSchema();
        if (!self::ready()) {
            throw new InvalidArgumentException('شغّل migration باقات السوشيال (2026_10_22_000001_social_packages.sql) الأول');
        }
        $cfg = self::config();
        $offer = self::offer($tier, $cycle, $custom);
        $planTier = self::tierFromPlan($owner);
        if ($tier !== 'custom' && $planTier && self::RANK[$planTier] >= self::RANK[$tier]) {
            throw new InvalidArgumentException('باقة المنصة بتاعتك بتشمل سوشيال «' . $cfg['tiers'][$planTier]['name'] . '» بالفعل');
        }
        $price = (float) $offer['price'];
        $promo = null;
        if ($promoCode !== null && trim($promoCode) !== '') {
            $promo = self::checkPromo($owner, $promoCode, $tier, $cycle, $price);
            $price = $promo['price'];
        }
        $customJson = $offer['custom_json'];
        $ref = 'social_pkg:' . $owner . ':' . substr(hash('sha256', $requestKey ?: bin2hex(random_bytes(8))), 0, 32);
        $db = Database::getInstance();
        // حد الاستخدام الكلي للكود: قفل باسم الكود بيسلسل الطلبات المتزامنة (الفحص + التسجيل) لحد ما الـtransaction تخلص
        $lockName = null;
        if ($promo) {
            $lockName = 'social_promo_' . substr(hash('sha256', $promo['code']), 0, 32);
            if (!(int) ($db->query('SELECT GET_LOCK(?, 5) AS l', [$lockName])[0]['l'] ?? 0)) {
                throw new InvalidArgumentException('الكود بيتستخدم دلوقتي - جرّب تاني بعد ثانية');
            }
        }
        try {
        $result = $db->transaction(function () use ($db, $owner, $tier, $cycle, $price, $ref, $offer, $customJson, $promo) {
            $db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$owner]);
            $row = self::standalone($owner, true);
            if ($row && $row['last_ref'] === $ref) {
                return ['action' => 'already', 'tier' => $row['tier'], 'cycle' => $row['cycle'], 'charged' => 0.0, 'period_end' => $row['period_end']];
            }
            if ($promo) {
                // إعادة فحص تحت القفل: الكود لسه صالح وماخلصش حده
                self::checkPromo($owner, $promo['code'], $tier, $cycle, (float) $offer['price']);
            }
            $now = time();
            $active = $row && $row['status'] === 'active' && strtotime($row['period_end'] . ' UTC') > $now;
            if ($active) {
                $same = (string) $row['tier'] === $tier && $row['cycle'] === $cycle && ($tier !== 'custom' || self::sameSpec((string) $row['custom_json'], (string) $customJson));
                if ($same) {
                    if (!empty($row['auto_renew']) && !$row['pending_tier']) {
                        throw new InvalidArgumentException('انت مشترك في الباقة دي بالفعل');
                    }
                    $db->query('UPDATE social_subscriptions SET auto_renew = 1, pending_tier = NULL, pending_cycle = NULL, pending_custom_json = NULL, last_ref = ? WHERE id = ?', [$ref, (int) $row['id']]);
                    return ['action' => 'resumed', 'tier' => $tier, 'cycle' => $cycle, 'charged' => 0.0, 'period_end' => $row['period_end']];
                }
                if (self::perMonth($price, $cycle) <= self::perMonth((float) $row['price_paid'], (string) $row['cycle'])) {
                    $db->query('UPDATE social_subscriptions SET pending_tier = ?, pending_cycle = ?, pending_custom_json = ?, auto_renew = 1, last_ref = ? WHERE id = ?', [$tier, $cycle, $customJson, $ref, (int) $row['id']]);
                    return ['action' => 'scheduled', 'tier' => $tier, 'cycle' => $cycle, 'charged' => 0.0, 'period_end' => $row['period_end']];
                }
                $start = strtotime($row['period_start'] . ' UTC');
                $end = strtotime($row['period_end'] . ' UTC');
                $credit = $end > $start ? round((float) $row['price_paid'] * max(0, $end - $now) / ($end - $start), 2) : 0.0;
                $charge = round(max(0, $price - $credit), 2);
            } else {
                $charge = $price;
            }
            if ($charge > 0 && !(new WalletService())->chargeAmount($owner, $charge, 'social_package_' . $tier, 'باقة السوشيال «' . $offer['name'] . '» (' . ($cycle === 'yearly' ? 'سنوي' : 'شهري') . ')', $ref)) {
                $bal = (new WalletService())->getBalance($owner);
                throw new DomainException(json_encode(['balance' => $bal, 'required' => $charge, 'shortfall' => round(max(0, $charge - $bal), 2)]));
            }
            $from = gmdate('Y-m-d H:i:s', $now);
            $to = gmdate('Y-m-d H:i:s', self::periodEnd($now, $cycle));
            if ($row) {
                $db->query("UPDATE social_subscriptions SET tier = ?, cycle = ?, price_paid = ?, custom_json = ?, status = 'active', auto_renew = 1, pending_tier = NULL, pending_cycle = NULL, pending_custom_json = NULL, period_start = ?, period_end = ?, last_ref = ?, reminded_at = NULL, promo_code = ? WHERE id = ?", [$tier, $cycle, $price, $customJson, $from, $to, $ref, $promo['code'] ?? null, (int) $row['id']]);
            } else {
                $db->query("INSERT INTO social_subscriptions (user_id, tier, cycle, price_paid, custom_json, status, auto_renew, period_start, period_end, last_ref, promo_code) VALUES (?, ?, ?, ?, ?, 'active', 1, ?, ?, ?, ?)", [$owner, $tier, $cycle, $price, $customJson, $from, $to, $ref, $promo['code'] ?? null]);
            }
            if ($promo) {
                $db->query('INSERT INTO social_promo_redemptions (promo_code, user_id, ref, discount) VALUES (?, ?, ?, ?)', [$promo['code'], $owner, $ref, $promo['discount']]);
            }
            return ['action' => $active ? 'upgraded' : 'subscribed', 'tier' => $tier, 'cycle' => $cycle, 'charged' => $charge, 'period_end' => $to] + ($promo ? ['promo' => $promo['code'], 'discount' => $promo['discount']] : []);
        });
        } finally {
            if ($lockName !== null) {
                $db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
        self::flush();
        if (in_array($result['action'], ['subscribed', 'upgraded'], true) && class_exists('ActivityLog')) {
            try {
                ActivityLog::record('subscription', 'social_package.' . $result['action'], ['user_id' => $owner, 'subject_type' => 'social_subscriptions', 'subject_id' => $owner, 'meta' => $result]);
            } catch (Throwable $e) {
            }
        }
        return $result;
    }

    /** كود خصم لعميل بعينه (الاستخدام الكلي واستخدام العميل من الجدول) */
    public static function checkPromo(int $owner, string $code, string $tier, string $cycle, float $price): array
    {
        $code = strtoupper(trim($code));
        $db = Database::getInstance();
        $uses = 0;
        $mine = false;
        try {
            $uses = (int) ($db->query('SELECT COUNT(*) AS n FROM social_promo_redemptions WHERE promo_code = ?', [$code])[0]['n'] ?? 0);
            $mine = (int) ($db->query('SELECT COUNT(*) AS n FROM social_promo_redemptions WHERE promo_code = ? AND user_id = ?', [$code, $owner])[0]['n'] ?? 0) > 0;
        } catch (Throwable $e) {
        }
        return self::applyPromo(self::config()['promos'], $code, $tier, $cycle, $price, $uses, $mine);
    }

    private static function sameSpec(string $a, string $b): bool
    {
        return (json_decode($a, true) ?: []) == (json_decode($b, true) ?: []);
    }

    /** إلغاء التجديد التلقائي (الباقة بتفضل شغالة لآخر المدة) أو رجوعه */
    public static function setAutoRenew(int $owner, bool $on): array
    {
        $row = self::standalone($owner);
        if (!self::standaloneActive($row)) {
            throw new InvalidArgumentException('مفيش اشتراك سوشيال شغال');
        }
        Database::getInstance()->query('UPDATE social_subscriptions SET auto_renew = ?' . ($on ? '' : ', pending_tier = NULL, pending_cycle = NULL, pending_custom_json = NULL') . ' WHERE id = ?', [$on ? 1 : 0, (int) $row['id']]);
        self::flush();
        return ['auto_renew' => $on, 'period_end' => $row['period_end']];
    }

    public static function buyAddon(int $owner, string $key, ?string $requestKey = null): array
    {
        self::ensureSchema();
        if (!self::ready()) {
            throw new InvalidArgumentException('شغّل migration باقات السوشيال الأول');
        }
        $a = self::config()['addons'][$key] ?? null;
        if (!$a || !$a['enabled']) {
            throw new InvalidArgumentException('الإضافة دي مش متاحة');
        }
        $ent = self::entitlements($owner);
        if (($ent['limits'][$a['kind']] ?? 0) < 0) {
            throw new InvalidArgumentException('باقتك فيها «' . self::LABELS[$a['kind']][0] . '» غير محدود بالفعل');
        }
        if ($a['kind'] === 'competitors' && ($ent['limits']['competitors'] ?? 0) === 0) {
            throw new InvalidArgumentException('متابعة المنافسين مش ضمن باقتك - اختار باقة «' . self::config()['tiers']['pro']['name'] . '» أو ضيف منافسين في «فصّل باقتك»');
        }
        $ref = 'social_addon:' . $owner . ':' . substr(hash('sha256', $requestKey ?: bin2hex(random_bytes(8))), 0, 32);
        $db = Database::getInstance();
        if ($db->query('SELECT id FROM social_addons WHERE ref = ? LIMIT 1', [$ref])) {
            return ['already' => true];
        }
        $out = $db->transaction(function () use ($db, $owner, $key, $a, $ref) {
            if (!(new WalletService())->chargeAmount($owner, (float) $a['price'], 'social_addon_' . $key, 'إضافة سوشيال: ' . $a['qty'] . ' ' . self::LABELS[$a['kind']][0], $ref)) {
                $bal = (new WalletService())->getBalance($owner);
                throw new DomainException(json_encode(['balance' => $bal, 'required' => (float) $a['price'], 'shortfall' => round(max(0, $a['price'] - $bal), 2)]));
            }
            $exp = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
            $db->query('INSERT INTO social_addons (user_id, addon_key, kind, qty, price, ref, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$owner, $key, $a['kind'], (int) $a['qty'], (float) $a['price'], $ref, $exp]);
            return ['addon' => $key, 'kind' => $a['kind'], 'qty' => (int) $a['qty'], 'charged' => (float) $a['price'], 'expires_at' => $exp];
        });
        self::flush();
        return $out;
    }

    public static function activeAddons(int $owner): array
    {
        if (!self::ready()) {
            return [];
        }
        try {
            return Database::getInstance()->query('SELECT addon_key, kind, qty, expires_at FROM social_addons WHERE user_id = ? AND expires_at > UTC_TIMESTAMP() ORDER BY expires_at', [$owner]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------ التجديد

    /** الدورة الجاية هتبقى إيه وبكام (بالأسعار الحالية). null = مش متاحة للبيع تاني */
    private static function nextTerm(array $r): ?array
    {
        $tier = $r['pending_tier'] ?: $r['tier'];
        $cycle = $r['pending_cycle'] ?: $r['cycle'];
        $custom = $tier === 'custom' ? (json_decode((string) ($r['pending_tier'] ? $r['pending_custom_json'] : $r['custom_json']), true) ?: []) : null;
        try {
            $o = self::offer($tier, $cycle, $custom);
        } catch (Throwable $e) {
            return null;
        }
        $price = (float) $o['price'];
        // سياسة «المشتركين القدام»: نفس الباقة والدورة ومن غير خصم = يفضل على سعره لو السعر الجديد أغلى
        if (self::config()['renewal_policy'] === 'grandfather' && !$r['pending_tier'] && !$r['pending_cycle'] && $tier !== 'custom' && empty($r['promo_code'])
            && (float) $r['price_paid'] > 0 && (float) $r['price_paid'] < $price) {
            $price = (float) $r['price_paid'];
        }
        return ['tier' => $tier, 'cycle' => $cycle, 'price' => $price, 'name' => $o['name'], 'custom_json' => $o['custom_json']];
    }

    /** بتتنادى من SocialPackagesRenewJob كل ساعة. بترجّع عدد الاشتراكات اللي اتعالجت */
    public static function renewDue(int $limit = 200): int
    {
        if (!self::ready()) {
            return 0;
        }
        $db = Database::getInstance();
        $cfg = self::config();
        $done = 0;
        // تذكير قبل التجديد بـ 3 أيام لو الرصيد مش كفاية
        foreach ($db->query("SELECT * FROM social_subscriptions WHERE status = 'active' AND auto_renew = 1 AND reminded_at IS NULL AND period_end BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY) LIMIT " . (int) $limit) ?: [] as $r) {
            $next = self::nextTerm($r);
            $price = $next['price'] ?? 0;
            try {
                if ($price > 0 && (new WalletService())->getBalance((int) $r['user_id']) < $price) {
                    Notification::notify((int) $r['user_id'], 'social_package', 'باقة السوشيال هتتجدد قريب', 'رصيد المحفظة مش كفاية لتجديد «' . $next['name'] . '» (' . $price . '$). اشحن المحفظة قبل ' . substr((string) $r['period_end'], 0, 10) . '.', '/subscription#wallet');
                }
            } catch (Throwable $e) {
            }
            $db->query('UPDATE social_subscriptions SET reminded_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $r['id']]);
        }
        foreach ($db->query("SELECT id FROM social_subscriptions WHERE status = 'active' AND period_end <= UTC_TIMESTAMP() ORDER BY period_end LIMIT " . (int) $limit) ?: [] as $due) {
            try {
                $db->transaction(function () use ($db, $cfg, $due) {
                    $r = $db->query("SELECT * FROM social_subscriptions WHERE id = ? AND status = 'active' AND period_end <= UTC_TIMESTAMP() FOR UPDATE", [(int) $due['id']])[0] ?? null;
                    if (!$r) {
                        return;
                    }
                    $uid = (int) $r['user_id'];
                    $next = self::nextTerm($r);
                    $name = $next['name'] ?? ($r['tier'] === 'custom' ? 'باقة مفصّلة' : ($cfg['tiers'][$r['tier']]['name'] ?? $r['tier']));
                    if (empty($r['auto_renew']) || !$next) {
                        $db->query("UPDATE social_subscriptions SET status = 'expired' WHERE id = ?", [(int) $r['id']]);
                        Notification::notify($uid, 'social_package', 'باقة السوشيال خلصت', 'باقة «' . $name . '» انتهت. تقدر تجددها في أي وقت من صفحة الاشتراكات.', '/subscription#social');
                        return;
                    }
                    $tier = $next['tier'];
                    $cycle = $next['cycle'];
                    $price = $next['price'];
                    // مفتاح الدورة نفسها (بداية+نهاية): نفس الدورة ماتتخصمش مرتين حتى لو المهمة اشتغلت مرتين
                    $ref = 'social_renew:' . (int) $r['id'] . ':' . substr(hash('sha256', $r['period_start'] . '|' . $r['period_end']), 0, 24);
                    if ($price > 0 && !(new WalletService())->chargeAmount($uid, $price, 'social_package_' . $tier, 'تجديد باقة السوشيال «' . $name . '»', $ref)) {
                        $db->query("UPDATE social_subscriptions SET status = 'expired' WHERE id = ?", [(int) $r['id']]);
                        Notification::notify($uid, 'social_package', 'مقدرناش نجدد باقة السوشيال', 'رصيد المحفظة مش كفاية لتجديد «' . $name . '» (' . $price . '$). اشحن المحفظة واشترك تاني من صفحة الاشتراكات.', '/subscription#wallet');
                        return;
                    }
                    $from = max(strtotime($r['period_end'] . ' UTC'), time() - 86400);
                    $db->query("UPDATE social_subscriptions SET tier = ?, cycle = ?, price_paid = ?, custom_json = ?, pending_tier = NULL, pending_cycle = NULL, pending_custom_json = NULL, period_start = ?, period_end = ?, last_ref = ?, reminded_at = NULL, promo_code = NULL WHERE id = ?",
                        [$tier, $cycle, $price, $next['custom_json'], gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', self::periodEnd($from, $cycle)), $ref, (int) $r['id']]);
                });
                $done++;
            } catch (Throwable $e) {
                Logger::warning('Social package renewal failed', ['id' => (int) $due['id'], 'message' => $e->getMessage()]);
            }
        }
        self::flush();
        return $done;
    }

    // ------------------------------------------------------------------ للعرض

    /** كل اللي صفحة الاشتراكات محتاجه */
    public static function summary(int $account): array
    {
        $ent = self::entitlements($account);
        $locked = self::locked($account, $ent);
        $sub = $ent['subscription'];
        return [
            'tier' => $ent['tier'], 'tier_name' => $ent['tier_name'], 'tier_name_en' => $ent['tier_name_en'], 'source' => $ent['source'],
            'plan_tier' => $ent['plan_tier'], 'enforced' => $ent['enforced'],
            'limits' => $ent['limits'], 'usage' => self::usage($account), 'features' => $ent['features'], 'platforms' => $ent['platforms'],
            'locked' => $locked,
            'subscription' => $sub ? ['tier' => $sub['tier'], 'cycle' => $sub['cycle'], 'status' => self::standaloneActive($sub) ? 'active' : 'expired', 'auto_renew' => (bool) $sub['auto_renew'],
                'pending_tier' => $sub['pending_tier'], 'pending_cycle' => $sub['pending_cycle'], 'period_end' => $sub['period_end'], 'price_paid' => (float) $sub['price_paid'],
                'custom' => json_decode((string) ($sub['custom_json'] ?? ''), true) ?: null] : null,
            'addons_active' => self::activeAddons($ent['owner']),
            'ready' => self::ready(),
        ];
    }
}
