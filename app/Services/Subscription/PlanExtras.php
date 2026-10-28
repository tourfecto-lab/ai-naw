<?php
require_once __DIR__ . '/BillingPlanCatalog.php';

/**
 * Tourfecto - الباقات المرنة
 *
 * 1) تجربة مجانية (3 أيام على باقة النمو) - مرة واحدة لكل حساب، بعد ما العميل يدخل الكارت
 *    (أو الأدمن يفعّلها يدوي للي مش عايز يدخل كارت)
 * 2) إضافات حسب الطلب: باكدجات تتشترى من المحفظة وتزوّد حصة الشهر (صالحة 30 يوم)
 * 3) "ابني باقتك": باقة أساسية + زيادات شهرية في الخدمات اللي محتاجها، بسعر محسوب
 *    تلقائي، وبتتجدد بنفس السعر والزيادات
 * 4) الباقات المقترحة: تشكيلة جاهزة احترافية للأدمن يطبقها بضغطة
 *
 * كل الحسابات بالدولار؛ العرض بأي عملة عن طريق CurrencyService.
 */
class PlanExtras
{
    /**
     * الخدمات اللي ينفع تتزوّد. kind=usage: حصة PlanUsage | kind=credit: رصيد SubscriptionValidator
     * unit = سعر الوحدة شهريًا في "ابني باقتك" | step = الزيادة في كل ضغطة
     */
    public const SERVICES = [
        'article_generation' => ['kind' => 'usage', 'ar' => 'مقالات SEO', 'en' => 'SEO articles', 'unit' => 1.20, 'step' => 5, 'max' => 300],
        'media_image' => ['kind' => 'usage', 'ar' => 'صور بالذكاء الصناعي', 'en' => 'AI images', 'unit' => 0.20, 'step' => 25, 'max' => 2000],
        'media_video' => ['kind' => 'usage', 'ar' => 'فيديوهات قصيرة', 'en' => 'Short videos', 'unit' => 3.00, 'step' => 2, 'max' => 100],
        'marketing_tool' => ['kind' => 'usage', 'ar' => 'استخدامات أدوات التسويق (خطط سوشيال وحملات)', 'en' => 'Marketing tool uses (social plans, campaigns)', 'unit' => 0.40, 'step' => 10, 'max' => 2000],
        'ai_assistant_message' => ['kind' => 'usage', 'ar' => 'رسائل المساعد الذكي', 'en' => 'AI assistant messages', 'unit' => 0.01, 'step' => 250, 'max' => 50000],
        'chat' => ['kind' => 'credit', 'ar' => 'ردود الشات بوت على العملاء', 'en' => 'Chatbot replies to customers', 'unit' => 0.008, 'step' => 500, 'max' => 100000],
        'review' => ['kind' => 'credit', 'ar' => 'ردود على تقييمات جوجل', 'en' => 'Google review replies', 'unit' => 0.05, 'step' => 50, 'max' => 10000],
        'ai_analysis' => ['kind' => 'credit', 'ar' => 'تحليلات بالذكاء الصناعي', 'en' => 'AI analyses', 'unit' => 0.60, 'step' => 5, 'max' => 1000],
        'competitor' => ['kind' => 'credit', 'ar' => 'تحليلات منافسين', 'en' => 'Competitor analyses', 'unit' => 1.00, 'step' => 2, 'max' => 300],
        'ai_tool' => ['kind' => 'usage', 'ar' => 'أدوات الذكاء الصناعي (كابشن، إعلانات، ردود مقترحة)', 'en' => 'AI tools (captions, ad copy, suggested replies)', 'unit' => 0.03, 'step' => 100, 'max' => 20000],
        'ai_visibility' => ['kind' => 'usage', 'ar' => 'قياسات ظهورك في ChatGPT و Gemini و Perplexity', 'en' => 'AI visibility checks (ChatGPT, Gemini, Perplexity)', 'unit' => 0.19, 'step' => 100, 'max' => 5000],
        'seo_data' => ['kind' => 'usage', 'ar' => 'بيانات بحث الكلمات (لكتابة المقالات وتحليل السوق)', 'en' => 'Keyword research data (for articles and market research)', 'unit' => 0.015, 'step' => 1000, 'max' => 50000],
    ];

    /** إضافات جاهزة (مرة واحدة - صالحة 30 يوم) */
    public const PACKS = [
        'articles_10' => ['service' => 'article_generation', 'qty' => 10, 'price' => 10, 'icon' => '📝'],
        'images_100' => ['service' => 'media_image', 'qty' => 100, 'price' => 15, 'icon' => '🖼️'],
        'videos_5' => ['service' => 'media_video', 'qty' => 5, 'price' => 12, 'icon' => '🎬'],
        'marketing_30' => ['service' => 'marketing_tool', 'qty' => 30, 'price' => 10, 'icon' => '📣'],
        'chat_2000' => ['service' => 'chat', 'qty' => 2000, 'price' => 12, 'icon' => '💬'],
        'reviews_200' => ['service' => 'review', 'qty' => 200, 'price' => 8, 'icon' => '⭐'],
        'analysis_20' => ['service' => 'ai_analysis', 'qty' => 20, 'price' => 10, 'icon' => '📊'],
        'assistant_2000' => ['service' => 'ai_assistant_message', 'qty' => 2000, 'price' => 15, 'icon' => '🤖'],
        'ai_tools_500' => ['service' => 'ai_tool', 'qty' => 500, 'price' => 12, 'icon' => '✨'],
        'ai_visibility_100' => ['service' => 'ai_visibility', 'qty' => 100, 'price' => 19, 'icon' => '🤖'],
        'seo_data_1000' => ['service' => 'seo_data', 'qty' => 1000, 'price' => 15, 'icon' => '🔎'],
    ];

    /**
     * تشكيلة الباقات المقترحة (أسعار بالدولار - الأدمن يقدر يعدّلها بعد التطبيق).
     * محسوبة بحيث تكسب حتى لو العميل استهلك الحصة كلها (أغلى حاجة: الفيديو ثم الصور):
     * الانطلاقة أقصى تكلفة ~$13 | النمو ~$44 | الريادة ~$128 (شاملة رسوم الدفع والسيرفر)
     */
    public const PRESET = [
        'starter' => ['name' => 'الانطلاقة', 'price_monthly' => 59, 'price_yearly' => 590, 'ai_analysis' => 10, 'chat_credits' => 1000, 'review_credits' => 80, 'competitor_analysis' => 2, 'multiple_websites' => 1,
            'whatsapp_bot' => 1, 'auto_pilot' => 0, 'advanced_analytics' => 0,
            'extra' => ['ai_assistant_messages' => 200, 'website_generations' => 2, 'website_items' => 30, 'articles' => 6, 'media_images' => 30, 'media_videos' => 1, 'marketing_tools' => 40, 'ai_tools' => 200], 'team' => 2],
        'professional' => ['name' => 'النمو', 'price_monthly' => 149, 'price_yearly' => 1490, 'ai_analysis' => 40, 'chat_credits' => 3500, 'review_credits' => 300, 'competitor_analysis' => 8, 'multiple_websites' => 3,
            'whatsapp_bot' => 1, 'auto_pilot' => 1, 'advanced_analytics' => 0,
            'extra' => ['ai_assistant_messages' => 1000, 'website_generations' => 6, 'website_items' => 100, 'articles' => 20, 'media_images' => 120, 'media_videos' => 4, 'marketing_tools' => 150, 'ai_tools' => 800], 'team' => 5],
        'enterprise' => ['name' => 'الريادة', 'price_monthly' => 349, 'price_yearly' => 3490, 'ai_analysis' => 120, 'chat_credits' => 10000, 'review_credits' => 1000, 'competitor_analysis' => 25, 'multiple_websites' => 8,
            'whatsapp_bot' => 1, 'auto_pilot' => 1, 'advanced_analytics' => 1,
            'extra' => ['ai_assistant_messages' => 3000, 'website_generations' => 20, 'website_items' => 600, 'articles' => 60, 'media_images' => 350, 'media_videos' => 12, 'marketing_tools' => 600, 'ai_tools' => 2500], 'team' => 15],
    ];

    /** استخدام بسيط لكل خدمة في التجربة المجانية (الأدمن يقدر يغيّره) - لما يخلص يخلص */
    public const TRIAL_CAPS = [
        'article_generation' => 2, 'media_image' => 10, 'media_video' => 1, 'marketing_tool' => 5, 'ai_assistant_message' => 30,
        'website_generation' => 1, 'website_item' => 10, 'ai_tool' => 20, 'chat' => 100, 'review' => 10, 'ai_analysis' => 2, 'competitor' => 1,
    ];
    /** أسماء الخدمات (للأدمن) */
    public const TRIAL_LABELS = [
        'article_generation' => 'مقالات', 'media_image' => 'صور', 'media_video' => 'فيديو', 'marketing_tool' => 'أدوات تسويق', 'ai_assistant_message' => 'رسائل المساعد الذكي',
        'website_generation' => 'إنشاء موقع', 'website_item' => 'عناصر الموقع', 'ai_tool' => 'أدوات AI (كابشن، إعلانات، ردود)', 'chat' => 'ردود الشات بوت', 'review' => 'ردود التقييمات', 'ai_analysis' => 'تحليلات AI', 'competitor' => 'تحليل منافسين',
    ];
    private static ?array $capsCache = null;

    /** حدود التجربة الفعلية (افتراضي + تعديلات الأدمن) */
    public static function trialCaps(): array
    {
        if (self::$capsCache !== null) {
            return self::$capsCache;
        }
        $caps = self::TRIAL_CAPS;
        try {
            $raw = class_exists('SystemSettingsService') ? json_decode((new SystemSettingsService())->get('plan_extras_config', ''), true) : null;
            foreach ((array) ($raw['trial_caps'] ?? []) as $k => $v) {
                if (isset($caps[$k]) && is_numeric($v) && $v >= 0) {
                    $caps[$k] = (int) $v;
                }
            }
        } catch (Throwable $e) {
        }
        return self::$capsCache = $caps;
    }

    /** حد التجربة: الأقل بين حصة الباقة وحد التجربة */
    public static function capForTrial(array $sub, string $feature, int $limit): int
    {
        $status = (string) ($sub['lifecycle_status'] ?? $sub['status'] ?? '');
        $caps = self::trialCaps();
        if ($status !== 'trialing' || !isset($caps[$feature])) {
            return $limit;
        }
        $cap = $caps[$feature];
        return $limit < 0 ? $cap : min($limit, $cap);
    }

    private Database $db;
    private PDO $pdo;
    private ?SystemSettingsService $settings;

    public function __construct(?Database $db = null, ?SystemSettingsService $settings = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->pdo = $this->db->getConnection();
        $this->settings = $settings ?? (class_exists('SystemSettingsService') ? new SystemSettingsService() : null);
        self::ensureSchema($this->pdo);
    }

    /** الجداول موجودة؟ (قراءة بس - آمنة جوه أي transaction) */
    public static function ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready) {
            return true;
        }
        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('plan_extra_credits','plan_custom')")->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;
        }
        return $ready = $n === 2;
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        // CREATE TABLE بيعمل commit ضمني في MySQL: مينفعش جوه transaction (زي التجديد)
        if ($done || $pdo->inTransaction() || self::ready($pdo)) {
            $done = $done || self::ready($pdo);
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS plan_extra_credits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subscription_id INT NOT NULL,
            feature VARCHAR(40) NOT NULL,
            amount INT NOT NULL,
            source VARCHAR(20) NOT NULL,
            ref VARCHAR(64) NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pec (subscription_id, feature, expires_at)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS plan_custom (
            subscription_id INT PRIMARY KEY,
            user_id INT NOT NULL,
            base_plan VARCHAR(40) NOT NULL,
            cycle VARCHAR(10) NOT NULL,
            extras_json TEXT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    private function q(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }

    // ------------------------------------------------------------------ إعدادات الأدمن

    public function config(): array
    {
        $raw = $this->settings ? json_decode($this->settings->get('plan_extras_config', ''), true) : null;
        $raw = is_array($raw) ? $raw : [];
        $services = [];
        foreach (self::SERVICES as $k => $s) {
            $o = $raw['services'][$k] ?? [];
            $services[$k] = $s + ['enabled' => true];
            if (isset($o['unit']) && is_numeric($o['unit']) && $o['unit'] >= 0) {
                $services[$k]['unit'] = (float) $o['unit'];
            }
            if (array_key_exists('enabled', $o)) {
                $services[$k]['enabled'] = !empty($o['enabled']);
            }
        }
        $packs = [];
        foreach (self::PACKS as $k => $p) {
            $o = $raw['packs'][$k] ?? [];
            $packs[$k] = $p + ['enabled' => true];
            foreach (['price', 'qty'] as $f) {
                if (isset($o[$f]) && is_numeric($o[$f]) && $o[$f] > 0) {
                    $packs[$k][$f] = $f === 'qty' ? (int) $o[$f] : (float) $o[$f];
                }
            }
            if (array_key_exists('enabled', $o)) {
                $packs[$k]['enabled'] = !empty($o['enabled']);
            }
        }
        return [
            'trial_enabled' => ($raw['trial_enabled'] ?? true) !== false,
            // الإعداد القديم (14 يوم) مابيتحسبش: التجربة بقت 3 أيام افتراضي لحد ما الأدمن يغيّرها
            'trial_days' => max(1, min(60, (int) (((int) ($raw['trial_v'] ?? 1)) >= 2 ? ($raw['trial_days'] ?? 3) : 3))),
            // مفيش بوابة دفع بالكارت لسه: التجربة من غير كارت افتراضي. الأدمن يفعّل الكارت بنفسه من Plans Studio
            // (القيمة القديمة المحفوظة قبل card_v=2 مابتتحسبش - كانت بتقفل التجربة التلقائية على كل عميل جديد)
            'trial_requires_card' => ((int) ($raw['card_v'] ?? 0)) >= 2 && !empty($raw['trial_requires_card']),
            'trial_card_usd' => max(0.5, min(20, (float) ($raw['trial_card_usd'] ?? 1))),
            // prepay: العميل يدفع أول شهر بالكارت قبل التجربة (بيتحط في محفظته) والاشتراك بيتخصم تلقائي بعد التجربة
            // verify: مبلغ صغير لتأكيد الكارت بس
            'trial_card_mode' => in_array($raw['trial_card_mode'] ?? 'prepay', ['prepay', 'verify'], true) ? ($raw['trial_card_mode'] ?? 'prepay') : 'prepay',
            'trial_caps' => self::trialCaps(),
            'trial_plan' => in_array($raw['trial_plan'] ?? '', ['starter', 'professional', 'enterprise'], true) ? $raw['trial_plan'] : 'professional',
            'builder_enabled' => ($raw['builder_enabled'] ?? true) !== false,
            'services' => $services,
            'packs' => $packs,
        ];
    }

    public function saveConfig(array $in): array
    {
        $raw = $this->settings ? json_decode($this->settings->get('plan_extras_config', ''), true) : [];
        $raw = is_array($raw) ? $raw : [];
        foreach (['trial_enabled', 'builder_enabled', 'trial_requires_card'] as $k) {
            if (array_key_exists($k, $in)) {
                $raw[$k] = !empty($in[$k]);
                if ($k === 'trial_requires_card') {
                    $raw['card_v'] = 2;
                }
            }
        }
        if (isset($in['trial_days'])) {
            $raw['trial_days'] = max(1, min(60, (int) $in['trial_days']));
            $raw['trial_v'] = 2;
        }
        if (isset($in['trial_card_mode']) && in_array($in['trial_card_mode'], ['prepay', 'verify'], true)) {
            $raw['trial_card_mode'] = $in['trial_card_mode'];
        }
        if (isset($in['trial_card_usd']) && is_numeric($in['trial_card_usd'])) {
            $raw['trial_card_usd'] = max(0.5, min(20, round((float) $in['trial_card_usd'], 2)));
        }
        foreach ((array) ($in['trial_caps'] ?? []) as $k => $v) {
            if (isset(self::TRIAL_CAPS[$k]) && is_numeric($v)) {
                $raw['trial_caps'][$k] = max(0, min(100000, (int) $v));
            }
        }
        self::$capsCache = null;
        if (isset($in['trial_plan']) && in_array($in['trial_plan'], ['starter', 'professional', 'enterprise'], true)) {
            $raw['trial_plan'] = $in['trial_plan'];
        }
        foreach ((array) ($in['services'] ?? []) as $k => $o) {
            if (isset(self::SERVICES[$k]) && is_array($o)) {
                $raw['services'][$k] = array_intersect_key($o, ['unit' => 1, 'enabled' => 1]);
            }
        }
        foreach ((array) ($in['packs'] ?? []) as $k => $o) {
            if (isset(self::PACKS[$k]) && is_array($o)) {
                $raw['packs'][$k] = array_intersect_key($o, ['price' => 1, 'qty' => 1, 'enabled' => 1]);
            }
        }
        $this->settings?->set('plan_extras_config', json_encode($raw));
        return $this->config();
    }

    // ------------------------------------------------------------------ الحصص الإضافية

    /** الزيادة الشغالة دلوقتي على خدمة معينة في اشتراك */
    public static function extra(int $subscriptionId, string $feature): int
    {
        if ($subscriptionId < 1) {
            return 0;
        }
        try {
            $pdo = Database::getInstance()->getConnection();
            if (!self::ready($pdo)) {
                return 0;
            }
            // الإضافات الجاهزة مربوطة بالعميل (بتفضل لو رقّى أو غيّر باقته)، والمخصصة بالاشتراك نفسه
            $u = $pdo->prepare('SELECT user_id FROM subscriptions WHERE id=?');
            $u->execute([$subscriptionId]);
            $uid = (int) $u->fetchColumn();
            $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM plan_extra_credits WHERE feature=? AND expires_at>NOW() AND ((source='addon' AND user_id=?) OR (source='custom' AND subscription_id=?))");
            $s->execute([$feature, $uid, $subscriptionId]);
            return (int) $s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function grant(int $uid, int $subId, string $feature, int $qty, string $source, string $ref, string $expires): void
    {
        $this->q('INSERT INTO plan_extra_credits(user_id,subscription_id,feature,amount,source,ref,expires_at) VALUES(?,?,?,?,?,?,?)', [$uid, $subId, $feature, $qty, $source, $ref, $expires]);
    }

    private function activeSub(int $uid): ?array
    {
        return class_exists('Subscription') ? (Subscription::activeSubscriptionRow($uid) ?: null) : null;
    }

    // ------------------------------------------------------------------ التجربة المجانية

    public function trialEligible(int $uid): bool
    {
        if (!$this->config()['trial_enabled']) {
            return false;
        }
        return !(int) $this->q('SELECT COUNT(*) FROM subscriptions WHERE user_id=?', [$uid])->fetchColumn();
    }

    /**
     * التجربة بتبدأ لوحدها مع التسجيل (لو مفعّلة ومش محتاجة كارت).
     * قبل كده العميل كان لازم يلاقي زرار «ابدأ التجربة» بنفسه - و6 من 6 سجّلوا ومابدأوهاش.
     * @return array|null بيانات التجربة، أو null لو مابدأتش
     */
    public function autoStartOnSignup(int $uid): ?array
    {
        try {
            if ($uid <= 0 || !$this->trialEligible($uid) || $this->cardRequired()) {
                return null;
            }
            if ($reason = $this->trialBlockedReason($uid)) {
                if (class_exists('ActivityLog')) {
                    ActivityLog::record('subscription', 'subscription.trial_blocked_duplicate', ['user_id' => $uid, 'meta' => ['reason' => $reason, 'at' => 'signup']]);
                }
                return null;
            }
            return $this->startTrial($uid);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Auto trial on signup failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /** الحساب ده شكله حساب مكرر عشان تجربة تانية؟ (إيميل/جهاز/شبكة/موقع اتجربوا قبل كده) */
    public function trialBlockedReason(int $uid): ?string
    {
        require_once __DIR__ . '/TrialGuard.php';
        return TrialGuard::blockedReason($uid);
    }

    /** العميل أكّد كارته (دفع ناجح بالكارت عن طريق XPay) */
    public function cardVerified(int $uid): bool
    {
        try {
            return (bool) $this->q("SELECT 1 FROM payment_transactions WHERE user_id=? AND gateway='xpay' AND status IN ('succeeded','partially_refunded','refunded') LIMIT 1", [$uid])->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** التجربة محتاجة كارت الأول؟ */
    public function trialNeedsCard(int $uid): bool
    {
        return $this->cardRequired() && !$this->cardVerifiedFor($uid, $this->trialCardAmount());
    }

    /**
     * التجربة بتطلب كارت بس لو الإعداد مفعّل وفيه بوابة دفع بالكارت شغالة فعلًا.
     * من غير بوابة، طلب الكارت كان بيقفل التجربة على كل عميل جديد («كلّمنا على واتساب»)،
     * فالتجربة بتبدأ بضغطة واحدة لحد ما البوابة تتفعّل.
     */
    public function cardRequired(): bool
    {
        if (!$this->config()['trial_requires_card']) {
            return false;
        }
        try {
            return class_exists('XPayGateway') && (new XPayGateway())->isReady();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** المبلغ اللي العميل بيدفعه بالكارت عشان يبدأ التجربة */
    public function trialCardAmount(): float
    {
        $cfg = $this->config();
        if ($cfg['trial_card_mode'] === 'prepay') {
            $p = BillingPlanCatalog::read()[$cfg['trial_plan']] ?? null;
            if ($p && (float) $p['price_monthly'] > 0) {
                return round((float) $p['price_monthly'], 2);
            }
        }
        return $cfg['trial_card_usd'];
    }

    /** الكارت لازم يكون اتأكد بنفس مبلغ التجربة المطلوب (مش أي دفعة صغيرة قديمة) */
    public function cardVerifiedFor(int $uid, float $amount): bool
    {
        try {
            return (bool) $this->q("SELECT 1 FROM payment_transactions WHERE user_id=? AND gateway='xpay' AND status='succeeded' AND amount >= ? LIMIT 1", [$uid, $amount - 0.01])->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** العميل يلغي التحويل التلقائي بعد التجربة (التجربة تكمل لآخرها، والرصيد يفضل في محفظته) */
    public function cancelTrialRenewal(int $uid, bool $cancel = true): bool
    {
        $st = $this->q("UPDATE subscriptions SET cancel_at_period_end=? WHERE user_id=? AND status='trialing' AND trial_ends_at > NOW()", [$cancel ? 1 : 0, $uid]);
        return $st->rowCount() > 0;
    }

    /**
     * التجربة خلصت: نخصم الاشتراك من المحفظة تلقائي (لو العميل ماألغاش).
     * @return string converted | cancelled | no_funds
     */
    public function convertExpiredTrial(int $subId, int $uid): string
    {
        $row = $this->q('SELECT * FROM subscriptions WHERE id=? AND user_id=?', [$subId, $uid])->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'trialing') {
            return 'cancelled';
        }
        if (!empty($row['cancel_at_period_end'])) {
            $this->q("UPDATE subscriptions SET status='expired', updated_at=NOW() WHERE id=?", [$subId]);
            return 'cancelled';
        }
        $plan = preg_replace('/_(monthly|yearly)$/', '', (string) $row['plan_name']);
        $r = (new WalletService())->subscribeWithBalance($uid, $plan, 'monthly', 'trial_convert_' . $subId);
        if (!empty($r['success'])) {
            $this->q("UPDATE subscriptions SET status='expired', updated_at=NOW() WHERE id=? AND status='trialing'", [$subId]);
            if (class_exists('ActivityLog')) {
                ActivityLog::record('subscription', 'subscription.trial_converted', ['user_id' => $uid, 'subject_type' => 'subscriptions', 'subject_id' => $subId, 'meta' => ['plan' => $plan, 'charged' => $r['charged'] ?? null]]);
            }
            return 'converted';
        }
        return 'no_funds';
    }

    /**
     * @param bool $byAdmin الأدمن بيفعّلها يدوي (للعميل اللي مش عايز يدخل كارت)
     */
    public function startTrial(int $uid, bool $byAdmin = false): array
    {
        if (!$this->trialEligible($uid) && !($byAdmin && !Subscription::activeSubscriptionRow($uid) && !(int) $this->q("SELECT COUNT(*) FROM subscriptions WHERE user_id=? AND status='trialing'", [$uid])->fetchColumn())) {
            throw new InvalidArgumentException('التجربة المجانية متاحة مرة واحدة بس للحسابات الجديدة.');
        }
        if (!$byAdmin && ($reason = $this->trialBlockedReason($uid))) {
            throw new InvalidArgumentException(TrialGuard::reasonText($reason));
        }
        if (!$byAdmin && $this->cardRequired() && !$this->cardVerifiedFor($uid, $this->trialCardAmount())) {
            throw new DomainException(json_encode(['error' => 'card_required', 'card_usd' => $this->trialCardAmount(), 'mode' => $this->config()['trial_card_mode']]));
        }
        $cfg = $this->config();
        $sub = Subscription::createSubscription($uid, $cfg['trial_plan'], 'monthly');
        if (!$sub) {
            throw new RuntimeException('Trial plan unavailable');
        }
        $id = (int) $sub->getAttribute('id');
        $end = date('Y-m-d H:i:s', time() + $cfg['trial_days'] * 86400);
        $this->q("UPDATE subscriptions SET status='trialing', price=0, trial_ends_at=?, current_period_end=?, cancel_at_period_end=0 WHERE id=? AND user_id=?", [$end, $end, $id, $uid]);
        TrialGuard::record($uid, !$byAdmin);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('subscription', 'subscription.trial_started', ['user_id' => $uid, 'subject_type' => 'subscriptions', 'subject_id' => $id, 'meta' => ['plan' => $cfg['trial_plan'], 'days' => $cfg['trial_days'], 'by_admin' => $byAdmin, 'card' => !$byAdmin && $this->cardRequired()]]);
        }
        return ['subscription_id' => $id, 'plan' => $cfg['trial_plan'], 'ends_at' => $end, 'days' => $cfg['trial_days']];
    }

    // ------------------------------------------------------------------ الإضافات الجاهزة

    public function buyPack(int $uid, string $key, ?string $idem = null): array
    {
        $cfg = $this->config();
        $pack = $cfg['packs'][$key] ?? null;
        if (!$pack || !$pack['enabled']) {
            throw new InvalidArgumentException('الإضافة دي مش متاحة');
        }
        $sub = $this->activeSub($uid);
        if (!$sub) {
            throw new InvalidArgumentException('الإضافات بتتضاف على باقة شغالة - اشترك في باقة أو ابدأ التجربة المجانية الأول.');
        }
        $idem = $idem ? 'addon:' . $uid . ':' . substr(hash('sha256', $idem), 0, 32) : null;
        if ($idem && $this->q('SELECT 1 FROM plan_extra_credits WHERE ref=? LIMIT 1', [$idem])->fetchColumn()) {
            return ['already' => true];
        }
        $svc = self::SERVICES[$pack['service']];
        if (!(new WalletService())->chargeAmount($uid, (float) $pack['price'], 'addon_' . $key, 'إضافة: ' . $pack['qty'] . ' ' . $svc['ar'], $idem)) {
            $bal = (new WalletService())->getBalance($uid);
            throw new DomainException(json_encode(['balance' => $bal, 'required' => (float) $pack['price'], 'shortfall' => round(max(0, $pack['price'] - $bal), 2)]));
        }
        $expires = date('Y-m-d H:i:s', time() + 30 * 86400);
        $this->grant($uid, (int) $sub['id'], $pack['service'], (int) $pack['qty'], 'addon', $idem ?? ('addon:' . $key . ':' . bin2hex(random_bytes(6))), $expires);
        return ['pack' => $key, 'service' => $pack['service'], 'qty' => (int) $pack['qty'], 'charged' => (float) $pack['price'], 'expires_at' => $expires];
    }

    // ------------------------------------------------------------------ ابني باقتك

    /** @param array<string,int> $extras الخدمة => الزيادة الشهرية */
    public function quote(string $base, string $cycle, array $extras): array
    {
        $cfg = $this->config();
        if (!$cfg['builder_enabled']) {
            throw new InvalidArgumentException('الباقات المخصصة مش متاحة دلوقتي');
        }
        if (!in_array($cycle, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('اختار شهري أو سنوي');
        }
        $plans = BillingPlanCatalog::read();
        if (!isset($plans[$base])) {
            throw new InvalidArgumentException('اختار الباقة الأساسية');
        }
        $basePrice = (float) $plans[$base]['price_' . $cycle];
        $items = [];
        $monthly = 0.0;
        foreach ($extras as $k => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $s = $cfg['services'][$k] ?? null;
            if (!$s || !$s['enabled']) {
                throw new InvalidArgumentException('خدمة غير متاحة: ' . $k);
            }
            if ($qty > $s['max'] || $qty % $s['step'] !== 0) {
                throw new InvalidArgumentException('كمية غير صالحة لـ ' . $s['ar']);
            }
            $line = round($qty * $s['unit'], 2);
            $monthly += $line;
            $items[] = ['service' => $k, 'qty' => $qty, 'monthly' => $line, 'ar' => $s['ar'], 'en' => $s['en']];
        }
        // السنوي = 10 شهور (زي الباقات: شهرين مجانًا)
        $extrasPrice = round($cycle === 'yearly' ? $monthly * 10 : $monthly, 2);
        return [
            'base' => $base, 'base_name' => (string) $plans[$base]['name'], 'cycle' => $cycle,
            'base_price' => $basePrice, 'extras_price' => $extrasPrice, 'total' => round($basePrice + $extrasPrice, 2),
            'items' => $items, 'features' => $plans[$base]['features'],
        ];
    }

    public function buyCustom(int $uid, string $base, string $cycle, array $extras, ?string $idem = null): array
    {
        $q = $this->quote($base, $cycle, $extras);
        if (!$q['items']) {
            throw new InvalidArgumentException('زوّد خدمة واحدة على الأقل - أو اشترك في الباقة العادية.');
        }
        $wallet = new WalletService();
        $balance = $wallet->getBalance($uid);
        $current = $this->activeSub($uid);
        if ($balance < $q['total'] - 0.001 && !$current) {
            throw new DomainException(json_encode(['balance' => $balance, 'required' => $q['total'], 'shortfall' => round($q['total'] - $balance, 2)]));
        }
        $key = 'custom:' . $uid . ':' . substr(hash('sha256', ($idem ?: bin2hex(random_bytes(8))) . json_encode($q['items'])), 0, 32);
        // 1) الباقة الأساسية (بنفس منطق الاشتراك/الترقية والفواتير الموجود)
        $r = $wallet->subscribeWithBalance($uid, $base, $cycle, $key . ':base');
        if (empty($r['success'])) {
            throw new DomainException(json_encode(['error' => $r['error'] ?? $r['message'] ?? 'تعذر الاشتراك', 'balance' => $r['balance'] ?? $balance, 'required' => $r['required'] ?? $q['total'], 'shortfall' => $r['shortfall'] ?? null]));
        }
        $sub = $this->activeSub($uid);
        if (!$sub) {
            throw new RuntimeException('Subscription not active after purchase');
        }
        $subId = (int) $sub['id'];
        // 2) الزيادات
        if (!$wallet->chargeAmount($uid, $q['extras_price'], 'custom_plan_extras', 'زيادات الباقة المخصصة', $key . ':extras')) {
            // الأساسية اتدفعت؛ الزيادات لأ - بنسجلها مستنية الدفع عشان العميل يعرف
            Logger::warning('Custom plan extras could not be charged', ['user_id' => $uid, 'subscription_id' => $subId]);
            throw new DomainException(json_encode(['error' => 'اشتركنا لك في الباقة الأساسية، لكن رصيدك ماكفاش الزيادات. اشحن المحفظة وضيف الزيادات من "إضافات حسب الطلب".', 'balance' => $wallet->getBalance($uid), 'required' => $q['extras_price']]));
        }
        $this->q('REPLACE INTO plan_custom(subscription_id,user_id,base_plan,cycle,extras_json,price) VALUES(?,?,?,?,?,?)', [$subId, $uid, $base, $cycle, json_encode(array_column($q['items'], 'qty', 'service')), $q['total']]);
        $this->q('UPDATE subscriptions SET price=? WHERE id=?', [$q['total'], $subId]);
        $this->grantCustom($uid, $subId, (string) $sub['current_period_end']);
        return ['subscription_id' => $subId, 'total' => $q['total'], 'items' => $q['items'], 'period_end' => $sub['current_period_end']];
    }

    private function grantCustom(int $uid, int $subId, string $periodEnd): void
    {
        $c = $this->q('SELECT * FROM plan_custom WHERE subscription_id=?', [$subId])->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            return;
        }
        $extras = json_decode((string) $c['extras_json'], true) ?: [];
        $cycle = (string) $c['cycle'];
        $expires = $periodEnd ?: date('Y-m-d H:i:s', time() + 31 * 86400);
        foreach ($extras as $feature => $qty) {
            // السنوي: الزيادة الشهرية × 12 على طول الفترة
            $amount = (int) $qty * ($cycle === 'yearly' ? 12 : 1);
            $this->grant($uid, $subId, (string) $feature, $amount, 'custom', 'custom:' . $subId . ':' . substr($expires, 0, 19), $expires);
        }
    }

    /** سعر التجديد للباقة المخصصة (null = باقة عادية) */
    public static function renewalPrice(int $subId): ?float
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            if (!self::ready($pdo)) {
                return null;
            }
            $s = $pdo->prepare('SELECT price FROM plan_custom WHERE subscription_id=?');
            $s->execute([$subId]);
            $p = $s->fetchColumn();
            return $p === false ? null : (float) $p;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** بعد التجديد: الزيادات الشهرية ترجع للفترة الجديدة */
    public static function onRenewed(int $subId, int $uid, string $newPeriodEnd): void
    {
        try {
            $self = new self();
            $ref = 'custom:' . $subId . ':' . substr($newPeriodEnd, 0, 19);
            if (!$self->q('SELECT 1 FROM plan_extra_credits WHERE ref=? LIMIT 1', [$ref])->fetchColumn()) {
                $self->grantCustom($uid, $subId, $newPeriodEnd);
            }
        } catch (Throwable $e) {
            Logger::warning('Custom plan renewal grant failed', ['subscription_id' => $subId, 'error' => $e->getMessage()]);
        }
    }

    public function customFor(int $subId): ?array
    {
        $c = $this->q('SELECT * FROM plan_custom WHERE subscription_id=?', [$subId])->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            return null;
        }
        $c['extras'] = json_decode((string) $c['extras_json'], true) ?: [];
        unset($c['extras_json']);
        return $c;
    }

    /** إضافات العميل الشغالة دلوقتي */
    public function activeExtras(int $subId): array
    {
        $uid = (int) $this->q('SELECT user_id FROM subscriptions WHERE id=?', [$subId])->fetchColumn();
        return $this->q("SELECT feature,SUM(amount) amount,MIN(expires_at) expires_at,source FROM plan_extra_credits WHERE expires_at>NOW() AND ((source='addon' AND user_id=?) OR (source='custom' AND subscription_id=?)) GROUP BY feature,source", [$uid, $subId])->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------ الباقات المقترحة

    /**
     * الباقات الجديدة بتتطبق لوحدها مرة واحدة لو الأسعار لسه القديمة بالظبط ($49 / $99 / $299)
     * (لو الأدمن غيّر الأسعار بنفسه مابنلمسهاش).
     */
    public function autoApplyPresetIfLegacy(): bool
    {
        if (!$this->settings || $this->settings->get('plans_preset_auto', '') !== '') {
            return false;
        }
        $cat = BillingPlanCatalog::read();
        $now = [(float) ($cat['starter']['price_monthly'] ?? 0), (float) ($cat['professional']['price_monthly'] ?? 0), (float) ($cat['enterprise']['price_monthly'] ?? 0)];
        if ($now !== [49.0, 99.0, 299.0]) {
            $this->settings->set('plans_preset_auto', 'skipped ' . date('Y-m-d H:i'));
            return false;
        }
        $this->applyPreset();
        $this->settings->set('plans_preset_auto', 'applied ' . date('Y-m-d H:i'));
        if (class_exists('Logger')) Logger::info('Recommended plans applied automatically (legacy prices found)');
        return true;
    }

    public function applyPreset(): array
    {
        return $this->db->transaction(function () {
            $done = [];
            foreach (self::PRESET as $key => $p) {
                $rows = $this->q('SELECT * FROM subscription_plans WHERE plan_code IN (?,?) FOR UPDATE', [$key . '_monthly', $key . '_yearly'])->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) !== 2) {
                    continue;
                }
                foreach ($rows as $row) {
                    $cycle = str_ends_with($row['plan_code'], '_yearly') ? 'yearly' : 'monthly';
                    $f = json_decode((string) ($row['features_json'] ?: '{}'), true) ?: [];
                    $f['competitor_analysis'] = $p['competitor_analysis'];
                    $f['multiple_websites'] = $p['multiple_websites'];
                    $f['team_members'] = $p['team'];
                    foreach (['whatsapp_bot', 'auto_pilot', 'advanced_analytics'] as $flag) {
                        $f[$flag] = (bool) $p[$flag];
                    }
                    foreach ($p['extra'] as $k => $v) {
                        $f[$k] = $v;
                    }
                    $this->q('UPDATE subscription_plans SET name=?,price=?,ai_analysis_limit=?,ai_message_limit=?,review_auto_reply_limit=?,websites_limit=?,is_active=1,features_json=? WHERE id=?', [
                        $p['name'], $p['price_' . $cycle], $p['ai_analysis'], $p['chat_credits'], $p['review_credits'], $p['multiple_websites'], json_encode($f), $row['id'],
                    ]);
                }
                try {
                    $this->q('UPDATE subscription_plans SET team_members_limit=? WHERE plan_code IN (?,?)', [$p['team'], $key . '_monthly', $key . '_yearly']);
                } catch (Throwable $e) {
                    // العمود مش موجود في قواعد قديمة
                }
                $this->q('UPDATE plan_pricing_display SET name=?,price_monthly=?,price_yearly=?,ai_analysis=?,competitor_analysis=?,chat_credits=?,review_credits=?,multiple_websites=?,whatsapp_bot=?,auto_pilot=?,advanced_analytics=?,is_active=1 WHERE plan_key=?', [
                    $p['name'], $p['price_monthly'], $p['price_yearly'], $p['ai_analysis'], $p['competitor_analysis'], $p['chat_credits'], $p['review_credits'], $p['multiple_websites'], $p['whatsapp_bot'], $p['auto_pilot'], $p['advanced_analytics'], $key,
                ]);
                $done[] = $key;
            }
            return $done;
        });
    }
}
