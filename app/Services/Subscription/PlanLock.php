<?php

/**
 * Tourfecto - قفل الخدمات اللي مش في باقة العميل
 *
 * الخدمة اللي مش في باقته (أو مفيش اشتراك شغّال) بتظهر في اللوحة متغبّشة
 * وعليها «اشترك دلوقتي» بدل ما يدخلها ويتفاجئ إن مفيش رصيد.
 * الحالة جاية من PlatformServiceRegistry (نفس منطق صفحة الأسعار)؛ والخصم
 * الفعلي لسه بيتحكم فيه PlanUsage / BillingUsageReservation على السيرفر.
 */
class PlanLock
{
    /** بداية مسار الصفحة => مفتاح الخدمة في ServiceCenterCatalog (الخدمات المدفوعة بس) */
    private const PATHS = [
        '/reputation' => 'reputation',
        '/chat' => 'inbox',
        '/chat/knowledge-base' => '',
        '/chat/settings' => '',
        '/whatsapp-marketing' => 'whatsapp',
        '/creative-studio' => 'images',
        '/marketing-assistant' => 'assistant',
        '/ai/articles' => 'articles',
        '/ai/keywords' => 'keywords',
        '/competitor-intelligence' => 'competitors',
        '/competitor-monitoring' => 'competitors',
        '/website-builder' => 'builder',
        '/website-optimizer' => 'seo',
        '/auto-seo' => 'seo',
        '/social' => 'social',
        '/ads' => 'ads',
        '/gbp-content' => 'gbp',
        '/outreach' => 'outreach',
        '/revenue/intelligence' => 'revenue',
        '/agency' => 'agency',
    ];

    private static array $cache = [];

    public static function serviceForPath(string $path): ?string
    {
        $path = '/' . trim((string) parse_url($path, PHP_URL_PATH), '/');
        $best = null;
        $len = 0;
        foreach (self::PATHS as $prefix => $key) {
            if (($path === $prefix || str_starts_with($path, $prefix . '/')) && strlen($prefix) > $len) {
                $best = $key;
                $len = strlen($prefix);
            }
        }
        return $best === '' ? null : $best;
    }

    /** null = مفتوحة، وإلا ['service' => key, 'reason' => no_subscription|not_in_plan] */
    public static function check(array $user, string $path): ?array
    {
        $service = self::serviceForPath($path);
        if ($service === null || empty($user['id']) || in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
            return null;
        }
        $state = self::states($user)[$service] ?? null;
        if ($state === null) {
            return null;
        }
        $lock = ['service' => $service, 'reason' => $state, 'trial' => null];
        // عميل جديد لسه ماجربش: نعرض له «ابدأ تجربتك المجانية» بدل «اشتراكك خلص»
        if ($state === 'no_subscription' && class_exists('PlanExtras')) {
            try {
                $owner = !empty($user['owner_user_id']) ? (int) $user['owner_user_id'] : (int) $user['id'];
                $x = new PlanExtras();
                if ($x->trialEligible($owner)) {
                    $lock['trial'] = ['days' => (int) ($x->config()['trial_days'] ?? 3), 'needs_card' => $x->trialNeedsCard($owner)];
                }
            } catch (Throwable $e) {
            }
        }
        return $lock;
    }

    /** خريطة الخدمات المقفولة للمستخدم ده: key => reason */
    public static function states(array $user): array
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid < 1 || in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
            return [];
        }
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }
        $locked = [];
        try {
            $owner = !empty($user['owner_user_id']) ? (int) $user['owner_user_id'] : $uid;
            $sub = class_exists('Subscription') ? Subscription::activeSubscriptionRow($owner) : null;
            $features = $sub && class_exists('FeatureResolver') ? (FeatureResolver::resolve($owner)['features'] ?? []) : [];
            $balance = null;
            foreach (array_filter(array_unique(array_values(self::PATHS))) as $key) {
                [, $ents] = PlatformServiceRegistry::MAP[$key] ?? [null, []];
                if (!$ents) continue;
                $meta = array_map(fn ($e) => PlatformServiceRegistry::entitlementMeta($e[0], $e[1]), $ents);
                // الإضافات (الطيار الآلي، السعات، رسائل المساعد) مش بتفتح الخدمة لوحدها - الحصة الأساسية هي اللي بتفتحها
                $meta = array_values(array_filter($meta, fn ($m) => $m['gate'] || !($m['kind'] === 'flag' || $m['kind'] === 'limit' || $m['feature'] === 'ai_assistant_messages')));
                if (!$meta) continue;
                if (!$sub) {
                    $locked[$key] = 'no_subscription';
                    continue;
                }
                $state = PlatformServiceRegistry::coverage($meta, $features)['state'];
                if ($state === 'pay_per_use') {
                    // مفيش بوابة دفع: الدفع بالاستخدام بيشتغل بس لو فيه رصيد اتضاف يدوي
                    $balance ??= class_exists('WalletService') ? (new WalletService())->getBalance($owner) : 0.0;
                    if ($balance <= 0) $state = 'not_included';
                }
                if ($state === 'not_included') $locked[$key] = 'not_in_plan';
            }
        } catch (Throwable $e) {
            // لو حصل خطأ مانقفلش حاجة - الخصم على السيرفر لسه بيحمي الاستخدام
            $locked = [];
        }
        // منشئ المواقع منتج لوحده باشتراك موقع: مفتوح للكل (معاينة مجانية، والنشر محتاج اشتراك الموقع)
        if (isset($locked['builder']) && class_exists('SiteSubscriptionService')) {
            try {
                if (SiteSubscriptionService::config()['enabled']) {
                    unset($locked['builder']);
                }
            } catch (Throwable $e) {
            }
        }
        return self::$cache[$uid] = $locked;
    }

    /** نفس الصفحة متغبّشة ومقفولة، وفوقها كارت «اشترك دلوقتي» */
    public static function wrap(string $bodyHtml, array $lock, string $serviceTitle): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $trial = $lock['trial'] ?? null;
        $cta = 'اشترك دلوقتي';
        $ctaHref = '/plans';
        $note = '';
        if ($lock['reason'] === 'no_subscription' && $trial) {
            $title = 'ابدأ تجربتك المجانية عشان تستخدم الخدمة دي';
            $text = $trial['needs_card']
                ? 'كل خدمات المنصة مفتوحة لك في التجربة المجانية، بحد استخدام بسيط لكل خدمة. تقدر تلغي في أي وقت قبل ما التجربة تخلص.'
                : 'كل خدمات المنصة مفتوحة لك في التجربة المجانية، بحد استخدام بسيط لكل خدمة. من غير كارت ومن غير أي التزام.';
            $note = '<div style="margin:-6px 0 16px;font-size:13px;opacity:.75"><span>مدة التجربة</span>: <b dir="ltr">' . (int) $trial['days'] . '</b> <span>' . ((int) $trial['days'] <= 10 ? 'أيام' : 'يوم') . '</span></div>';
            $cta = $trial['needs_card'] ? 'اربط كارتك وابدأ التجربة' : 'ابدأ التجربة المجانية';
            $ctaHref = '/subscription#trial';
        } elseif ($lock['reason'] === 'no_subscription') {
            $title = 'فعّل اشتراكك عشان تستخدم الخدمة دي';
            $text = 'مفيش اشتراك شغال على حسابك دلوقتي. اختار باقتك وادفع بالتحويل البنكي أو PayPal، والباقة بتتفعّل أول ما نأكد التحويل - وبياناتك كلها زي ما هي.';
        } else {
            $title = 'الخدمة دي مش ضمن باقتك الحالية';
            $text = 'رقّي باقتك عشان تفتح «' . $serviceTitle . '» وتبدأ تستخدمها على طول.';
        }
        return '<div class="tf-plan-lock" style="position:relative;min-height:420px">'
            . '<div aria-hidden="true" inert style="filter:blur(6px);opacity:.55;pointer-events:none;user-select:none;max-height:900px;overflow:hidden">' . $bodyHtml . '</div>'
            . '<div role="dialog" aria-labelledby="tfLockTitle" style="position:absolute;inset:0;display:flex;align-items:flex-start;justify-content:center;padding:70px 16px">'
            . '<div style="max-width:440px;width:100%;text-align:center;background:var(--panel-card-bg,#fff);border:1px solid rgba(201,154,59,.55);border-radius:18px;padding:28px 24px;box-shadow:0 20px 60px rgba(10,26,46,.25)">'
            . '<div style="width:56px;height:56px;margin:0 auto 12px;border-radius:16px;background:#0A1A2E;color:#DDB255;display:grid;place-items:center;font-size:26px">🔒</div>'
            . '<h2 id="tfLockTitle" style="margin:0 0 8px;font-size:20px">' . $e($title) . '</h2>'
            . '<p style="margin:0 0 18px;opacity:.8;line-height:1.8;font-size:14px">' . $e($text) . '</p>'
            . $note
            . '<a href="' . $e($ctaHref) . '" class="p-btn primary" style="display:inline-flex;padding:11px 26px;font-size:15px;background:linear-gradient(135deg,#C99A3B,#DDB255);color:#0A1A2E;border:0;font-weight:700">' . $e($cta) . '</a>'
            . '<div style="margin-top:12px;font-size:13px"><a href="/subscription">شوف باقتك واستهلاكك</a></div>'
            . '</div></div></div>';
    }
}
