<?php

/**
 * Tourfecto - الميزات اللي الباقة بتغطيها قبل ما نخصم من المحفظة
 *
 * المشكلة: المساعد الذكي ومنشئ المواقع كانوا بيخصموا من المحفظة على طول،
 * حتى للمشترك اللي دافع باقة. دلوقتي:
 *   1. لو عنده اشتراك شغّال (active / trialing / past_due في فترة السماح)
 *      ولسه فاضله من حصة الباقة في الفترة الحالية → ببلاش (بيتحسب على الباقة).
 *   2. لو خلصت الحصة أو مفيش اشتراك → من المحفظة بالسعر العادي (زي الأول).
 *
 * الحصص (لكل فترة اشتراك) - تقدر تغيّرها لأي باقة من features_json:
 *   ai_assistant_messages  رسائل المساعد الذكي  (افتراضي = ai_message_limit بتاع الباقة)
 *   website_generations    توليد موقع كامل      (افتراضي: starter 3 / professional 10 / enterprise 30)
 *   website_items          إضافة رحلة/غرفة لموقع (افتراضي: starter 30 / professional 150 / enterprise 1000)
 *   -1 = غير محدود، 0 = مش مشمولة في الباقة.
 *
 * العدّاد في جدول plan_usage_events (بيتعد من بداية الفترة الحالية، فمفيش
 * حاجة محتاجة تتصفّر عند التجديد).
 */
require_once __DIR__.'/SubscriptionPeriod.php';
class PlanUsage
{
    /** feature key (نفس مفتاح تسعير المحفظة أو مفتاح فرعي) => مفتاح features_json */
    private const FEATURES = [
        'seo_data' => 'seo_data',
        'ai_visibility' => 'ai_visibility',
        'ai_assistant_message' => 'ai_assistant_messages',
        'website_generation' => 'website_generations',
        'website_item' => 'website_items',
        'article_generation' => 'articles',
        'marketing_tool' => 'marketing_tools',
        'media_image' => 'media_images',
        'media_video' => 'media_videos',
        // أدوات AI الصغيرة: كابشن، نسخ إعلانات، رد مقترح، مساعد الإعلانات، أسئلة المنافسين...
        'ai_tool' => 'ai_tools',
    ];

    private const TIER_DEFAULTS = [
        'website_generations' => ['starter' => 3, 'professional' => 10, 'enterprise' => 30],
        'website_items' => ['starter' => 30, 'professional' => 150, 'enterprise' => 1000],
        'articles' => ['starter' => 10, 'professional' => 40, 'enterprise' => 150],
        'marketing_tools' => ['starter' => 100, 'professional' => 500, 'enterprise' => 2000],
        'media_images' => ['starter' => 20, 'professional' => 100, 'enterprise' => 400],
        'media_videos' => ['starter' => 0, 'professional' => 5, 'enterprise' => 20],
        'ai_tools' => ['starter' => 150, 'professional' => 600, 'enterprise' => 2000],
    ];

    private static ?bool $tableReady = null;

    /**
     * حصة الميزة دي للعميل في الفترة الحالية.
     * @return array{covered:bool, limit:int, used:int, remaining:int|null, subscription_id:int|null, period_start:?string}
     */
    public static function quota(int $userId, string $feature): array
    {
        $none = ['covered' => false, 'limit' => 0, 'used' => 0, 'remaining' => 0, 'subscription_id' => null, 'period_start' => null];
        if (!isset(self::FEATURES[$feature]) || !self::tableReady() || !class_exists('Subscription')) {
            return $none;
        }
        $sub = Subscription::activeSubscriptionRow(self::accountOwner($userId));
        if (!$sub) {
            return $none;
        }
        $limit = self::limitFor($sub, self::FEATURES[$feature]);
        // التجربة المجانية ليها حد أقل للخدمات اللي بتكلّف، والإضافات فوق الحصة
        require_once __DIR__ . '/PlanExtras.php';
        $limit = PlanExtras::capForTrial($sub, $feature, $limit);
        if ($limit >= 0) {
            $limit += self::extraFor((int) $sub['id'], $feature);
        }
        if ($limit === 0) {
            return ['subscription_id' => (int) $sub['id']] + $none;
        }
        $start = SubscriptionPeriod::quotaStart((string) ($sub['current_period_start'] ?? $sub['start_date'] ?? date('Y-m-d H:i:s')));
        $used = self::used((int) $sub['id'], $feature, $start);
        $held=class_exists('CreativeReservations')?CreativeReservations::planHeld((int)$sub['id'],$feature):0;
        $remaining = $limit < 0 ? null : max(0, $limit - $used - $held);
        return [
            'covered' => $limit < 0 || $remaining > 0,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'subscription_id' => (int) $sub['id'],
            'period_start' => $start ?: null,
        ];
    }

    /**
     * فحص قبل التنفيذ: الباقة بتغطي ولا المحفظة كفاية؟
     * @return array{ok:bool, source:string, error?:string, shortfall?:float|null, price?:float}
     */
    public static function check(int $userId, string $feature, string $walletKey, string $noFundsMsg): array
    {
        $q = self::quota($userId, $feature);
        if ($q['covered']) {
            return ['ok' => true, 'source' => 'plan', 'remaining' => $q['remaining']];
        }
        $w = (new WalletService())->canAffordUsage($userId, $walletKey);
        if (!empty($w['can_afford'])) {
            return ['ok' => true, 'source' => 'wallet', 'price' => $w['price'] ?? null];
        }
        $msg = $noFundsMsg;
        if (!isset($w['balance']) && empty($w['shortfall'])) {
            // الميزة مش متاحة بالدفع من المحفظة - مش مشكلة رصيد. كانت الرسالة بتقول
            // "رصيدك مش كافي" للعميل حتى لو المحفظة مليانة.
            $msg = $q['subscription_id']
                ? 'خلصت حصة باقتك من الميزة دي للفترة دي. تقدر ترقّي باقتك من صفحة الاشتراك.'
                : 'الميزة دي ضمن باقات الاشتراك. اختار باقة من صفحة الاشتراك وادفعها من رصيد محفظتك.';
        } elseif ($q['subscription_id'] && $q['limit'] > 0) {
            $msg = 'خلصت حصة باقتك من الميزة دي للفترة دي (' . $q['limit'] . ')، و' . $noFundsMsg;
        }
        return ['ok' => false, 'source' => 'none', 'error' => $msg, 'shortfall' => $w['shortfall'] ?? null];
    }

    /**
     * الخصم بعد النجاح: من الباقة لو لسه فيه حصة، وإلا من المحفظة.
     * @return string 'plan' | 'wallet' | 'failed'
     */
    public static function charge(int $userId, string $feature, string $walletKey, string $note): string
    {
        if (self::consume($userId, $feature, $note)) {
            return 'plan';
        }
        if($feature==='ai_visibility'){
            // Paid measurement overage requires an active, positive USD price. No free unlimited fallback.
            $pdo=Database::getInstance()->getConnection();$lock=$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $stmt=$pdo->prepare('SELECT price,currency,is_active FROM pay_per_use_pricing WHERE feature_key=?'.$lock);$stmt->execute([$walletKey]);$price=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$price||!(int)$price['is_active']||!is_finite((float)$price['price'])||(float)$price['price']<=0||$price['currency']!=='USD')return 'failed';
        }
        return (new WalletService())->chargeForUsage($userId, $walletKey, $note) ? 'wallet' : 'failed';
    }

    /** يسجّل استخدام على الباقة لو فيه حصة (ذرّي: مايعدّيش الحد حتى مع طلبات متزامنة) */
    public static function consume(int $userId, string $feature, string $note = ''): bool
    {
        if (!isset(self::FEATURES[$feature]) || !self::tableReady() || !class_exists('Subscription')) {
            return false;
        }
        $sub = Subscription::activeSubscriptionRow(self::accountOwner($userId));
        if (!$sub) {
            return false;
        }
        $limit = self::limitFor($sub, self::FEATURES[$feature]);
        // التجربة المجانية ليها حد أقل للخدمات اللي بتكلّف، والإضافات فوق الحصة
        require_once __DIR__ . '/PlanExtras.php';
        $limit = PlanExtras::capForTrial($sub, $feature, $limit);
        if ($limit >= 0) {
            $limit += self::extraFor((int) $sub['id'], $feature);
        }
        if ($limit === 0) {
            return false;
        }
        $subId = (int) $sub['id'];
        $start = SubscriptionPeriod::quotaStart((string) ($sub['current_period_start'] ?? $sub['start_date'] ?? date('Y-m-d H:i:s')));
        $db = Database::getInstance();
        try {
            return (bool) $db->transaction(function () use ($db, $subId, $userId, $feature, $note, $limit, $start) {
                // قفل صف الاشتراك عشان طلبين في نفس اللحظة مايعدّوش الحد
                $pdo=$db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$stmt=$pdo->prepare('SELECT id FROM subscriptions WHERE id = ?'.$lock);$stmt->execute([$subId]);if(!$stmt->fetchColumn())return false;
                if ($limit > 0 && self::used($subId, $feature, $start)+(class_exists('CreativeReservations')?CreativeReservations::planHeld($subId,$feature):0) >= $limit) {
                    return false;
                }
                $stmt=$pdo->prepare('INSERT INTO plan_usage_events (subscription_id, user_id, feature, note, created_at) VALUES (?, ?, ?, ?, NOW())');if(!$stmt->execute([$subId,$userId,$feature,mb_substr($note,0,190)]))throw new RuntimeException('Plan event persistence failed');
                return true;
            });
        } catch (Throwable $e) {
            Logger::error('PlanUsage consume failed', ['user_id' => $userId, 'feature' => $feature, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /** ملخص كل الحصص (لصفحة الاشتراك) */
    public static function summary(int $userId): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $f) {
            $out[$f] = self::quota($userId, $f);
        }
        return $out;
    }

    /** أسماء الميزات للعرض */
    private const LABELS = [
        'seo_data' => ['قياس كلمة SEO', 'seo_data'],
        'ai_visibility' => ['سؤال ومزوّد AI', 'ai_visibility'],
        'ai_assistant_message' => ['رسالة', 'ai_assistant_message'],
        'website_generation' => ['موقع', 'website_generation'],
        'website_item' => ['عنصر', 'website_generation'],
        'article_generation' => ['مقال', 'article_generation'],
        'marketing_tool' => ['استخدام', 'marketing_tool'],
        'media_image' => ['صورة', 'media_image'],
        'media_video' => ['فيديو', 'media_video'],
    ];

    /**
     * نص الحصة اللي بيظهر للعميل (فاضل كام من الباقة، أو سعر المحفظة).
     * @return array{text:string, state:string, remaining:?int, limit:int}
     */
    public static function describe(int $userId, string $feature): array
    {
        $q = self::quota($userId, $feature);
        [$unit, $walletKey] = self::LABELS[$feature] ?? ['استخدام', $feature];
        $price = null;
        try {
            $pr = (new WalletService())->getUsagePricing();
            $price = isset($pr[$walletKey]) ? (float) $pr[$walletKey]['price'] : null;
        } catch (Throwable $e) {
        }
        $priceTxt = $price !== null ? '$' . rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.') : '';
        if ($q['covered']) {
            $text = $q['remaining'] === null
                ? 'مشمول في باقتك بلا حدود ✓'
                : 'من باقتك: فاضل ' . $q['remaining'] . ' ' . $unit . ' من ' . $q['limit'] . ' الفترة دي';
            return ['text' => $text, 'state' => 'plan', 'remaining' => $q['remaining'], 'limit' => $q['limit']];
        }
        if ($q['subscription_id'] && $q['limit'] > 0) {
            $text = 'خلصت حصة باقتك (' . $q['limit'] . ') للفترة دي' . ($priceTxt ? ' - دلوقتي ' . $priceTxt . ' لكل ' . $unit . ' من المحفظة' : '');
            return ['text' => $text, 'state' => 'exhausted', 'remaining' => 0, 'limit' => $q['limit']];
        }
        $text = ($priceTxt ? $priceTxt . ' لكل ' . $unit . ' من المحفظة' : 'بيتخصم من المحفظة') . ' · اشترك في باقة عشان يبقى مشمول';
        return ['text' => $text, 'state' => 'wallet', 'remaining' => null, 'limit' => 0];
    }

    /** شريط صغير جاهز للعرض في الصفحة + بيتحدّث لوحده بعد كل استخدام (refreshPlanUsage()) */
    public static function badgeHtml(int $userId, string $feature): string
    {
        $d = self::describe($userId, $feature);
        $color = ['plan' => '#0E9F6E', 'exhausted' => '#D97706', 'wallet' => 'var(--panel-text-muted,#5D687C)'][$d['state']];
        $txt = htmlspecialchars($d['text'], ENT_QUOTES, 'UTF-8');
        $f = htmlspecialchars($feature, ENT_QUOTES, 'UTF-8');
        $link = $d['state'] === 'plan' ? '' : ' <a href="/subscription" style="color:inherit;text-decoration:underline">الباقات</a>';
        return '<div class="plan-usage-badge" data-feature="' . $f . '" style="font-size:12.5px;margin-top:8px;color:' . $color . '">'
            . '<span data-pu-text>' . $txt . '</span>' . $link . '</div>'
            . '<script>window.refreshPlanUsage=window.refreshPlanUsage||function(){document.querySelectorAll(".plan-usage-badge").forEach(function(el){'
            . 'fetch("/api/plan-usage?feature="+encodeURIComponent(el.dataset.feature),{credentials:"same-origin",headers:{"Accept":"application/json"}})'
            . '.then(function(r){return r.json();}).then(function(d){if(d&&d.success&&d.data){el.querySelector("[data-pu-text]").textContent=d.data.text;'
            . 'el.style.color=({plan:"#0E9F6E",exhausted:"#D97706"})[d.data.state]||"";}}).catch(function(){});});};</script>';
    }

    // ------------------------------------------------------------------

    private static function extraFor(int $subId, string $feature): int
    {
        require_once __DIR__ . '/PlanExtras.php';
        return PlanExtras::extra($subId, $feature);
    }

    private static function limitFor(array $sub, string $jsonKey): int
    {
        require_once __DIR__.'/BillingPlanCatalog.php';
        // Catalog and runtime use the same row and fallback allowances.
        $row=['plan_code'=>($sub['plan_name']??'starter').'_'.($sub['plan_type']??'monthly'),
            'ai_message_limit'=>$sub['chat_credits']??0,
            'features_json'=>$sub['plan_features_json']??$sub['features_json']??'{}'];
        if (!empty($sub['plan_id'])) {
            $stored=Database::getInstance()->query('SELECT * FROM subscription_plans WHERE id=? LIMIT 1',[(int)$sub['plan_id']])[0]??null;
            if ($stored) $row=$stored;
        }
        $features=BillingPlanCatalog::features($row);
        $limit=(int)($features[$jsonKey]??0);
        return in_array($jsonKey,['seo_data','ai_visibility'],true)?max(0,$limit):$limit;
    }

    private static function used(int $subId, string $feature, string $since): int
    {
        try {
            if (in_array($feature,['seo_data','ai_visibility'],true)) {
                $s=Database::getInstance()->getConnection()->prepare('SELECT COUNT(*) FROM plan_usage_events WHERE subscription_id=? AND feature=? AND created_at>=?');
                $s->execute([$subId,$feature,$since ?: '1970-01-01 00:00:00']);
                return (int)$s->fetchColumn();
            }
            $r = Database::getInstance()->query(
                'SELECT COUNT(*) AS c FROM plan_usage_events WHERE subscription_id = ? AND feature = ? AND created_at >= ?',
                [$subId, $feature, $since ?: '1970-01-01 00:00:00']
            );
            return (int) ($r[0]['c'] ?? 0);
        } catch (Throwable $e) {
            return PHP_INT_MAX;
        }
    }

    /** أعضاء الفريق بيستخدموا باقة صاحب الحساب */
    private static function accountOwner(int $userId): int
    {
        try {
            $r = Database::getInstance()->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId]);
            $owner = (int) ($r[0]['owner_user_id'] ?? 0);
            return $owner > 0 ? $owner : $userId;
        } catch (Throwable $e) {
            return $userId;
        }
    }

    private static function tableReady(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = !empty(Database::getInstance()->query("SHOW TABLES LIKE 'plan_usage_events'"));
            } catch (Throwable $e) {
                self::$tableReady = false;
            }
        }
        return self::$tableReady;
    }
}
