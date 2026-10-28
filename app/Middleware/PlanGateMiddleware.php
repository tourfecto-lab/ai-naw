<?php
require_once __DIR__ . '/../Services/Subscription/PlanUsage.php';

/**
 * Tourfecto - بوابة الباقة
 *
 * PlanGateMiddleware:ai_tool            أدوات AI الصغيرة (كابشن، نسخ إعلانات، مساعد الإعلانات، رد مقترح...):
 *                                       بتتخصم من حصة "أدوات الذكاء الصناعي" في الباقة، وبعدها من المحفظة
 * PlanGateMiddleware:flag_auto_pilot    خدمة متاحة في باقات معينة بس (الطيار الآلي، التحليلات المتقدمة...)
 */
class PlanGateMiddleware
{
    public const AI_TOOL_PRICE = 0.02;
    private string $mode = '';

    public function applyModifier(string $modifier): self
    {
        $this->mode = $modifier;
        return $this;
    }

    private function userId(): int
    {
        return (int) ($_SERVER['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
    }

    public function handle(): ?array
    {
        $uid = $this->userId();
        if (!$uid) {
            return $this->error('سجّل الدخول الأول', 401);
        }
        if ($this->mode === 'ai_tool') {
            self::ensureWalletPrice();
            // أدوات السوشيال (كابشن...): بعد حصة باقة المنصة، بتتخصم من حصة باقة السوشيال قبل المحفظة
            if (str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/api/social/') && empty(PlanUsage::quota($uid, 'ai_tool')['covered'])) {
                require_once __DIR__ . '/../Services/SocialMedia/SocialPackages.php';
                if (SocialPackages::consumeAi(class_exists('SocialTeam') ? (int) SocialTeam::context($uid)['account_id'] : $uid)) {
                    return null;
                }
            }
            $check = PlanUsage::check($uid, 'ai_tool', 'ai_tool', 'خلصت أدوات الذكاء الصناعي في باقتك الشهر ده، ورصيد المحفظة مش كفاية. اشحن المحفظة أو رقّي باقتك.');
            if (empty($check['ok'])) {
                return $this->error((string) ($check['error'] ?? 'خلصت حصتك'), 402, ['upgrade_url' => '/subscription']);
            }
            // الأدوات دي رخيصة وسريعة: بتتحسب وقت الطلب
            if (PlanUsage::charge($uid, 'ai_tool', 'ai_tool', 'أداة ذكاء صناعي: ' . substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 0, 80)) === 'failed') {
                return $this->error('مقدرناش نحسب الاستخدام، جرّب تاني.', 402, ['upgrade_url' => '/subscription']);
            }
            return null;
        }
        if (str_starts_with($this->mode, 'flag_')) {
            $flag = substr($this->mode, 5);
            if (!self::hasFlag($uid, $flag)) {
                $names = ['auto_pilot' => 'الطيار الآلي', 'advanced_analytics' => 'التحليلات المتقدمة', 'whatsapp_bot' => 'شات بوت واتساب'];
                return $this->error((isset($names[$flag]) ? '"' . $names[$flag] . '"' : 'الخدمة دي') . ' مش ضمن باقتك. رقّي باقتك عشان تفتحها.', 403, ['upgrade_url' => '/subscription', 'feature' => $flag]);
            }
            return null;
        }
        return null;
    }

    /** ميزة في الباقة؟ (الباقة + أي استثناء من الأدمن) - التجربة المجانية بتاخد ميزات باقتها */
    public static function hasFlag(int $uid, string $flag): bool
    {
        try {
            $r = Database::getInstance()->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$uid]);
            $owner = (int) ($r[0]['owner_user_id'] ?? 0) ?: $uid;
            return class_exists('FeatureResolver') && FeatureResolver::has($owner, $flag);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** سعر أداة AI بالمحفظة (بعد ما حصة الباقة تخلص) */
    public static function ensureWalletPrice(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            Database::getInstance()->getConnection()->prepare("INSERT IGNORE INTO pay_per_use_pricing(feature_key,label,price,currency,currency_symbol,is_active) VALUES('ai_tool','أداة ذكاء صناعي (كابشن، نسخ إعلان، رد مقترح...)',?,'USD','$',1)")->execute([self::AI_TOOL_PRICE]);
        } catch (Throwable $e) {
        }
    }

    private function error(string $message, int $code, array $extra = []): array
    {
        http_response_code($code);
        return ['success' => false, 'error' => $message, 'code' => $code] + $extra;
    }
}
