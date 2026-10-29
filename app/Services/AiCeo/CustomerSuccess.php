<?php
require_once __DIR__ . '/SalesEngine.php';

/**
 * TOURFECTO AI CEO (المرحلة 6) — نجاح العملاء والإيرادات.
 *
 * مبني على الخدمات الموجودة: SaasMetricsService (MRR/ARR/Churn/LTV)، CustomerHealthService (درجة صحة كل عميل وربحيته)،
 * TrialFollowUpService، SalesEngine (CAC). الجديد هنا:
 *   - رحلة العميل من التسجيل لحد الدفع وأماكن الوقوع، والعملاء الجدد الواقفين في خطوة (مع الخطوة الجاية ورسالة مقترحة، مفيش إرسال).
 *   - مشاكل الدفع، والتجديدات الجاية واللي هتتلغي، وأسباب الإلغاء.
 *   - فرص الترقية (Upsell) وربط خدمات إضافية (Cross-sell).
 *   - رسائل الدعم (contact_submissions): اللي مااتردش عليها، والمواضيع المتكررة (بتتحول لمهام تطوير)، وتكلفة الدعم لو صاحب المنصة حدد تكلفته.
 *   - جدوى الخدمات (استخدام وتكلفة كل خدمة) وهامش كل باقة، واقتراحات مراجعة الأسعار (اقتراحات بس، مفيش تغيير تلقائي).
 *   - توقع الإيراد بثلاث نطاقات (متحفظ / متوقع / متفائل) والافتراضات مكتوبة.
 * كل الأرقام من قاعدة البيانات. لو جدول أو عمود مش موجود، القسم بيرجع null بدل رقم وهمي.
 */
final class CustomerSuccess
{
    private const CUSTOMER = "u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND (u.owner_user_id IS NULL OR u.owner_user_id = 0)";
    private const MONTHLY = "(CASE WHEN COALESCE(sp.billing_cycle, s.plan_type) IN ('yearly','annual') THEN COALESCE(sp.price, s.price) / 12 ELSE COALESCE(sp.price, s.price) END)";
    private const PAID = "s.status IN ('active','past_due') AND COALESCE(s.payment_gateway, '') <> 'admin_grant' AND COALESCE(s.price, 0) > 0";
    /** مواضيع رسائل الدعم (كلمات إنجليزي وعربي) */
    public const THEMES = [
        'login' => ['login', 'log in', 'password', 'sign in', 'دخول', 'باسورد', 'كلمة السر', 'كلمة المرور'],
        'payment' => ['payment', 'pay', 'card', 'invoice', 'refund', 'charge', 'wallet', 'دفع', 'فلوس', 'فاتورة', 'استرداد', 'محفظة', 'كارت'],
        'whatsapp' => ['whatsapp', 'واتساب', 'واتس'],
        'facebook_instagram' => ['facebook', 'instagram', 'meta', 'messenger', 'فيسبوك', 'فيس', 'انستجرام', 'انستا'],
        'google' => ['google', 'gbp', 'business profile', 'reviews', 'جوجل', 'تقييم'],
        'seo' => ['seo', 'ranking', 'keyword', 'سيو', 'ترتيب', 'كلمات'],
        'booking' => ['booking', 'reservation', 'حجز', 'حجوزات'],
        'bug' => ['error', 'bug', 'broken', 'not working', "doesn't work", 'crash', 'خطأ', 'مشكلة', 'مش شغال', 'لا يعمل', 'عطل'],
        'pricing' => ['price', 'expensive', 'plan', 'subscription', 'cancel', 'سعر', 'غالي', 'باقة', 'اشتراك', 'إلغاء', 'الغاء'],
    ];
    public const STEPS = ['website' => 'Add the website', 'connected' => 'Connect Google / social / WhatsApp', 'used' => 'Use a service for the first time (SEO, chat, post)', 'paying' => 'Choose a plan'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    private function safe(string $sql, array $p = []): ?array
    {
        try { return $this->db->query($sql, $p) ?: []; } catch (Throwable $e) { if (class_exists('Logger')) Logger::warning('CustomerSuccess query failed', ['error' => $e->getMessage()]); return null; }
    }

    private static function notTest(string $col): string
    {
        return class_exists('TestAccounts') ? TestAccounts::exclude($col) : '';
    }

    // ------------------------------------------------------------------ رحلة العميل

    /** كل عميل سجّل في الفترة: وصل لأنهي خطوة */
    public function funnel(int $days = 90): ?array
    {
        $days = max(7, min(365, $days));
        $rows = $this->safe("SELECT u.id, u.email, u.company_name, u.created_at, u.last_login,
                (SELECT COUNT(*) FROM websites w WHERE w.user_id = u.id) AS sites,
                ((SELECT COUNT(*) FROM websites w WHERE w.user_id = u.id AND w.is_connected = 1) + (SELECT COUNT(*) FROM platform_connections pc WHERE pc.user_id = u.id AND pc.status = 'connected')) AS connected,
                (SELECT COUNT(*) FROM api_usage_logs a WHERE a.user_id = u.id) AS ai_calls,
                (SELECT COUNT(*) FROM subscriptions s WHERE s.user_id = u.id AND s.trial_ends_at IS NOT NULL) AS trials,
                (SELECT COUNT(*) FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.user_id = u.id AND " . self::PAID . ") AS paying
            FROM users u WHERE " . self::CUSTOMER . self::notTest('u.id') . ' AND u.created_at >= ? ORDER BY u.id DESC LIMIT 5000', [date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
        if ($rows === null) return null;
        $stage = ['signed_up' => 0, 'website' => 0, 'connected' => 0, 'used' => 0, 'trial' => 0, 'paying' => 0];
        $stuck = [];
        foreach ($rows as $r) {
            $s = self::progress($r);
            $stage['signed_up']++;
            foreach (['website', 'connected', 'used', 'trial', 'paying'] as $k) if ($s[$k]) $stage[$k]++;
            if ($s['next'] && strtotime((string) $r['created_at']) < time() - 3 * 86400) {
                $stuck[] = ['user_id' => (int) $r['id'], 'company' => $r['company_name'] ?: $r['email'], 'email' => $r['email'], 'signed_up' => $r['created_at'], 'last_login' => $r['last_login'],
                    'next_step' => $s['next'], 'next_step_label' => self::STEPS[$s['next']], 'message' => self::nudge($s['next'], (string) ($r['company_name'] ?: ''))];
            }
        }
        $drop = [];
        $prev = 'signed_up';
        foreach (['website', 'connected', 'used', 'paying'] as $k) {
            $drop[] = ['from' => $prev, 'to' => $k, 'lost' => max(0, $stage[$prev] - $stage[$k]), 'rate' => $stage[$prev] > 0 ? round($stage[$k] / $stage[$prev] * 100, 1) : null];
            $prev = $k;
        }
        usort($drop, static fn ($a, $b) => $b['lost'] <=> $a['lost']);
        return ['days' => $days, 'stages' => $stage, 'biggest_drop' => $drop[0]['lost'] > 0 ? $drop[0] : null, 'drops' => $drop, 'stuck' => array_slice($stuck, 0, 50), 'stuck_count' => count($stuck)];
    }

    /** الخطوة اللي العميل وصلها والخطوة الجاية (منطق نقي) */
    public static function progress(array $r): array
    {
        $s = ['website' => (int) ($r['sites'] ?? 0) > 0, 'connected' => (int) ($r['connected'] ?? 0) > 0, 'used' => (int) ($r['ai_calls'] ?? 0) > 0,
            'trial' => (int) ($r['trials'] ?? 0) > 0, 'paying' => (int) ($r['paying'] ?? 0) > 0];
        $s['next'] = null;
        foreach (['website', 'connected', 'used', 'paying'] as $k) if (!$s[$k]) { $s['next'] = $k; break; }
        return $s;
    }

    /** رسالة مساعد الإعداد المقترحة للخطوة الجاية (صاحب المنصة بيراجعها ويبعتها بنفسه) */
    public static function nudge(string $step, string $company): array
    {
        $n = trim($company) !== '' ? ' ' . trim($company) : '';
        return [
            'website' => ['ar' => "أهلًا{$n} 👋 فاضل خطوة واحدة عشان Tourfecto يبدأ يشتغل لك: ضيف موقعك من صفحة المواقع، وهنعمل لك فحص سيو مجاني في دقايق.", 'en' => "Hi{$n} 👋 One step left for Tourfecto to start working for you: add your website from the Websites page and we'll run a free SEO check in minutes."],
            'connected' => ['ar' => "أهلًا{$n} 👋 اربط جوجل بيزنس أو صفحاتك أو واتساب عشان نرد على التقييمات والرسايل وننشر لك بوستات. لو محتاج مساعدة في الربط ردّ على الرسالة دي.", 'en' => "Hi{$n} 👋 Connect Google Business, your social pages or WhatsApp so we can answer reviews and messages and publish posts for you. Reply if you need help connecting."],
            'used' => ['ar' => "أهلًا{$n} 👋 موقعك جاهز. جرّب أول خدمة: خطة السيو أو أول بوست من استوديو التصميم أو رد الشات الذكي، وكلها من لوحة التحكم.", 'en' => "Hi{$n} 👋 Your site is ready. Try your first service: the SEO plan, a first post from the studio, or the AI chat reply, all from your dashboard."],
            'paying' => ['ar' => "أهلًا{$n} 👋 شايفين إنك بتستخدم Tourfecto. لو حابب نكمل معاك بعد التجربة، الباقات في صفحة الاشتراك، ولو عندك سؤال عن الأنسب لك ردّ علينا.", 'en' => "Hi{$n} 👋 We see you're using Tourfecto. To keep going after the trial, the plans are on the subscription page. Reply if you'd like help choosing."],
        ][$step] ?? ['ar' => '', 'en' => ''];
    }

    // ------------------------------------------------------------------ الدفع والتجديد والإلغاء

    public function payments(int $days = 30): ?array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $tx = $this->safe('SELECT status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM payment_transactions WHERE created_at >= ?' . self::notTest('user_id') . ' GROUP BY status', [$since]);
        $stuck = $this->safe("SELECT COUNT(*) AS n FROM payment_transactions WHERE status IN ('pending','processing') AND created_at < NOW() - INTERVAL 1 HOUR AND created_at >= ?" . self::notTest('user_id'), [$since]);
        $pastDue = $this->safe("SELECT s.user_id, u.email, u.company_name, ROUND(" . self::MONTHLY . ", 2) AS mrr, s.updated_at FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id JOIN users u ON u.id = s.user_id
            WHERE s.status = 'past_due' AND " . self::CUSTOMER . self::notTest('s.user_id') . ' ORDER BY mrr DESC LIMIT 50');
        if ($tx === null && $pastDue === null) return null;
        $by = [];
        foreach ($tx ?? [] as $r) $by[$r['status']] = ['count' => (int) $r['n'], 'amount' => round((float) $r['amount'], 2)];
        $total = array_sum(array_column($by, 'count'));
        $failed = ($by['failed']['count'] ?? 0);
        return ['days' => $days, 'by_status' => $by, 'failure_rate' => $total > 0 ? round($failed / $total * 100, 1) : null, 'stuck_pending' => (int) ($stuck[0]['n'] ?? 0),
            'past_due' => array_map(static fn ($r) => ['user_id' => (int) $r['user_id'], 'company' => $r['company_name'] ?: $r['email'], 'mrr' => (float) $r['mrr'], 'since' => $r['updated_at']], $pastDue ?? [])];
    }

    /** تاريخ التجديد من الأعمدة الموجودة فعلًا (السيرفرات مختلفة: current_period_end / next_billing_at / expires_at / expiry_date) */
    private function renewExpr(): ?string
    {
        $have = array_column($this->safe("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions'
            AND COLUMN_NAME IN ('current_period_end','next_billing_at','expires_at','expiry_date')") ?? [], 'COLUMN_NAME');
        $cols = array_values(array_filter(['current_period_end', 'next_billing_at', 'expires_at', 'expiry_date'], static fn ($c) => in_array($c, $have, true)));
        return $cols ? 'COALESCE(' . implode(', ', array_map(static fn ($c) => 's.' . $c, $cols)) . ', NULL)' : null;
    }

    /** التجديدات في الـ 30 يوم الجايين، واللي طالب إلغاء آخر الفترة */
    public function renewals(int $days = 30): ?array
    {
        $renew = $this->renewExpr();
        if ($renew === null) return null;
        $rows = $this->safe("SELECT s.user_id, u.email, u.company_name, COALESCE(sp.name, s.plan_name) AS plan, ROUND(" . self::MONTHLY . ", 2) AS mrr, s.cancel_at_period_end,
                {$renew} AS renews_at
            FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id JOIN users u ON u.id = s.user_id
            WHERE " . self::PAID . ' AND ' . self::CUSTOMER . self::notTest('s.user_id') . "
              AND {$renew} BETWEEN NOW() AND NOW() + INTERVAL " . max(1, min(90, $days)) . ' DAY ORDER BY renews_at ASC LIMIT 200');
        if ($rows === null) return null;
        $items = array_map(static fn ($r) => ['user_id' => (int) $r['user_id'], 'company' => $r['company_name'] ?: $r['email'], 'plan' => $r['plan'], 'mrr' => (float) $r['mrr'], 'renews_at' => $r['renews_at'], 'cancelling' => (bool) $r['cancel_at_period_end']], $rows);
        $cancelling = array_values(array_filter($items, static fn ($i) => $i['cancelling']));
        return ['days' => $days, 'count' => count($items), 'mrr' => round(array_sum(array_column($items, 'mrr')), 2), 'cancelling' => count($cancelling),
            'mrr_cancelling' => round(array_sum(array_column($cancelling, 'mrr')), 2), 'items' => array_slice($items, 0, 50)];
    }

    public function cancellations(int $days = 180): ?array
    {
        $rows = $this->safe("SELECT s.cancellation_reason AS reason, DATE_FORMAT(COALESCE(s.cancelled_at, s.updated_at), '%Y-%m') AS month, ROUND(" . self::MONTHLY . ", 2) AS mrr
            FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.status IN ('cancelled','canceled','expired') AND COALESCE(s.price, 0) > 0 AND COALESCE(s.payment_gateway, '') <> 'admin_grant' AND COALESCE(s.cancelled_at, s.updated_at) >= ?" . self::notTest('s.user_id'),
            [date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
        if ($rows === null) return null;
        $reasons = $months = [];
        foreach ($rows as $r) {
            $k = trim(mb_strtolower((string) $r['reason'])) ?: '(no reason given)';
            $k = mb_substr($k, 0, 80);
            $reasons[$k] = ($reasons[$k] ?? 0) + 1;
            $months[$r['month']]['count'] = ($months[$r['month']]['count'] ?? 0) + 1;
            $months[$r['month']]['mrr'] = round(($months[$r['month']]['mrr'] ?? 0) + (float) $r['mrr'], 2);
        }
        arsort($reasons);
        ksort($months);
        return ['days' => $days, 'total' => count($rows), 'reasons' => array_slice($reasons, 0, 12, true), 'themes' => self::themes(array_column($rows, 'reason')), 'by_month' => $months];
    }

    // ------------------------------------------------------------------ الدعم

    /** تصنيف النصوص لمواضيع (منطق نقي) */
    public static function themes(array $texts): array
    {
        $out = [];
        foreach ($texts as $t) {
            $t = mb_strtolower((string) $t);
            if ($t === '') continue;
            foreach (self::THEMES as $theme => $words) {
                foreach ($words as $w) if (str_contains($t, $w)) { $out[$theme] = ($out[$theme] ?? 0) + 1; break; }
            }
        }
        arsort($out);
        return $out;
    }

    public function support(int $days = 90): ?array
    {
        $rows = $this->safe('SELECT c.id, c.message, c.status, c.user_id, c.created_at, (s.id IS NOT NULL) AS paying FROM contact_submissions c
            LEFT JOIN subscriptions s ON s.user_id = c.user_id AND s.status IN (\'active\',\'past_due\') AND COALESCE(s.price, 0) > 0
            WHERE c.created_at >= ? GROUP BY c.id ORDER BY c.id DESC LIMIT 2000', [date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
        if ($rows === null) return null;
        $open = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'new'));
        $themes = self::themes(array_column($rows, 'message'));
        $set = $this->settings();
        $cost = $set['support_minutes'] !== null && $set['support_cost_hour'] !== null ? round(count($rows) * $set['support_minutes'] / 60 * $set['support_cost_hour'], 2) : null;
        $customers = count(array_unique(array_filter(array_map('intval', array_column($rows, 'user_id')))));
        return ['days' => $days, 'total' => count($rows), 'unanswered' => count($open), 'oldest_unanswered' => $open ? end($open)['created_at'] : null,
            'from_paying' => count(array_filter($rows, static fn ($r) => (int) $r['paying'] > 0)), 'themes' => $themes,
            'recurring' => array_keys(array_filter($themes, static fn ($n) => $n >= 3)), 'cost_usd' => $cost, 'cost_per_customer_usd' => $cost !== null && $customers > 0 ? round($cost / $customers, 2) : null,
            'cost_note' => $cost === null ? 'Set the minutes per message and the support cost per hour to compute support cost.' : 'Messages × minutes per message × cost per hour.'];
    }

    // ------------------------------------------------------------------ الترقية والخدمات والأسعار

    /** فرص الترقية والربط من صحة العملاء الموجودة */
    public function opportunities(): ?array
    {
        try { $h = (new CustomerHealthService($this->db))->list('paying', '', 300); } catch (Throwable $e) { return null; }
        $items = $h['items'] ?? [];
        $calls = array_map(static fn ($i) => (int) ($i['ai_calls_30d'] ?? 0), $items);
        sort($calls);
        $p75 = $calls ? $calls[(int) floor(0.75 * (count($calls) - 1))] : 0;
        $upsell = $cross = [];
        foreach ($items as $i) {
            $base = ['user_id' => (int) ($i['id'] ?? 0), 'company' => $i['name'] ?? '', 'plan' => $i['plan'] ?? null, 'mrr' => (float) ($i['mrr'] ?? 0), 'score' => (int) ($i['score'] ?? 0)];
            if (($i['band'] ?? '') === 'healthy' && (int) ($i['ai_calls_30d'] ?? 0) > 0 && (int) ($i['ai_calls_30d'] ?? 0) >= $p75) $upsell[] = $base + ['why' => 'Heavy, healthy usage (' . (int) $i['ai_calls_30d'] . ' AI actions in 30 days): offer the next plan or extra credits.'];
            if ((int) ($i['connections'] ?? 0) === 0) $cross[] = $base + ['why' => 'Paying but no Google/social/WhatsApp connection: help them connect (chat, reviews and publishing need it).'];
        }
        return ['upsell' => array_slice($upsell, 0, 30), 'cross_sell' => array_slice($cross, 0, 30), 'health' => $h['counts'] ?? [], 'mrr_at_risk' => $h['mrr_at_risk'] ?? 0, 'profit' => $h['profit'] ?? null,
            'at_risk' => array_slice(array_map(static fn ($i) => ['user_id' => (int) ($i['id'] ?? 0), 'company' => $i['name'] ?? '', 'score' => (int) ($i['score'] ?? 0), 'mrr' => (float) ($i['mrr'] ?? 0), 'reasons' => $i['reasons'] ?? []],
                array_filter($items, static fn ($i) => ($i['band'] ?? '') === 'at_risk')), 0, 20)];
    }

    /** استخدام وتكلفة كل خدمة (مهمة AI) آخر 30 يوم، ومين بيستخدمها من اللي بيدفعوا */
    public function services(): ?array
    {
        $rows = $this->safe("SELECT a.feature, COUNT(*) AS calls, COUNT(DISTINCT a.user_id) AS users, ROUND(SUM(a.cost_in_usd), 4) AS cost,
                COUNT(DISTINCT CASE WHEN s.user_id IS NOT NULL THEN a.user_id END) AS paying_users
            FROM api_usage_logs a LEFT JOIN (SELECT DISTINCT user_id FROM subscriptions WHERE status IN ('active','past_due') AND COALESCE(price, 0) > 0 AND COALESCE(payment_gateway, '') <> 'admin_grant') s ON s.user_id = a.user_id
            WHERE a.created_at >= NOW() - INTERVAL 30 DAY AND COALESCE(a.feature, '') NOT LIKE 'ai\\_ceo%'" . self::notTest('a.user_id') . ' GROUP BY a.feature ORDER BY cost DESC LIMIT 60');
        if ($rows === null) return null;
        $paying = (int) ($this->safe('SELECT COUNT(DISTINCT s.user_id) AS n FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id WHERE ' . self::PAID . self::notTest('s.user_id'))[0]['n'] ?? 0);
        return ['paying_customers' => $paying, 'items' => array_map(static fn ($r) => ['feature' => (string) ($r['feature'] ?: 'unknown'), 'calls' => (int) $r['calls'], 'users' => (int) $r['users'], 'paying_users' => (int) $r['paying_users'],
            'cost_usd' => (float) $r['cost'], 'cost_per_user' => (int) $r['users'] > 0 ? round((float) $r['cost'] / (int) $r['users'], 4) : null,
            'adoption_pct' => $paying > 0 ? round((int) $r['paying_users'] / $paying * 100, 1) : null], $rows)];
    }

    /** اقتراحات مراجعة الباقات (منطق نقي، اقتراحات بس) */
    public static function pricingSuggestions(array $byPlan, ?float $marketMedian = null): array
    {
        $out = [];
        foreach ($byPlan as $p) {
            $name = (string) ($p['plan'] ?? '?');
            $m = $p['margin_pct'] ?? null;
            if ($m !== null && $m < 50) $out[] = ['plan' => $name, 'type' => 'low_margin', 'text' => "AI cost takes " . round(100 - $m) . "% of the {$name} price. Review its AI limits or price (no change is made automatically)."];
            if ((int) ($p['customers'] ?? 0) === 0) $out[] = ['plan' => $name, 'type' => 'unused', 'text' => "Nobody is on {$name}: consider merging or hiding it."];
            if ($marketMedian !== null && (float) ($p['price'] ?? 0) > 0 && (float) $p['price'] < 0.5 * $marketMedian) $out[] = ['plan' => $name, 'type' => 'below_market', 'text' => "{$name} costs less than half the market median (\${$marketMedian}). There may be room to raise it for new customers."];
        }
        return $out;
    }

    // ------------------------------------------------------------------ الأرقام والتوقع

    public function metrics(): array
    {
        $k = (new SaasMetricsService($this->db))->summary(6)['kpis'];
        $v = static fn ($key) => $k[$key]['value'] ?? null;
        $cac = null;
        try { $cac = (new SalesEngine($this->db))->pipeline()['cac_usd'] ?? null; } catch (Throwable $e) {}
        $ltv = $v('ltv');
        return ['mrr' => $v('mrr'), 'arr' => $v('arr'), 'paying' => $v('paying'), 'arpu' => $v('arpu'), 'logo_churn' => $v('logo_churn'), 'revenue_churn' => $v('revenue_churn'),
            'retention' => $v('logo_churn') !== null ? round(100 - (float) $v('logo_churn'), 1) : null, 'ltv' => $ltv, 'cac' => $cac,
            'ltv_cac' => $ltv !== null && $cac ? round($ltv / $cac, 2) : null, 'trial_conversion' => $v('trial_conversion'), 'mrr_prev' => $k['mrr']['prev'] ?? null];
    }

    /** MRR جديد في الشهر (متوسط آخر 90 يوم) */
    public function newMrrPerMonth(): ?float
    {
        $r = $this->safe("SELECT COALESCE(SUM(" . self::MONTHLY . "), 0) AS v FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.created_at >= NOW() - INTERVAL 90 DAY AND COALESCE(s.price, 0) > 0 AND COALESCE(s.payment_gateway, '') <> 'admin_grant' AND s.status IN ('active','past_due','cancelled','canceled','expired')" . self::notTest('s.user_id'));
        return $r === null ? null : round((float) ($r[0]['v'] ?? 0) / 3, 2);
    }

    /**
     * توقع الإيراد (منطق نقي): كل شهر MRR = MRR × (1 - churn) + MRR جديد.
     * متحفظ: الإلغاء × 1.5 والجديد × 0.5 · متوقع: زي آخر فترة · متفائل: الإلغاء × 0.5 والجديد × 1.5
     */
    public static function forecast(float $mrr, float $monthlyChurnPct, float $newMrr, array $horizons = [3, 6, 12], int $paying = 0): array
    {
        $cases = ['conservative' => [1.5, 0.5], 'expected' => [1.0, 1.0], 'optimistic' => [0.5, 1.5]];
        $out = [];
        foreach ($cases as $name => [$cm, $nm]) {
            $c = min(100, max(0, $monthlyChurnPct * $cm)) / 100;
            $m = $mrr;
            $pts = [];
            for ($i = 1; $i <= max($horizons); $i++) {
                $m = $m * (1 - $c) + $newMrr * $nm;
                if (in_array($i, $horizons, true)) $pts[$i] = round($m, 2);
            }
            $out[$name] = $pts;
        }
        return ['mrr_now' => round($mrr, 2), 'cases' => $out, 'assumptions' => ['monthly_churn_pct' => round($monthlyChurnPct, 2), 'new_mrr_per_month' => round($newMrr, 2),
            'conservative' => 'churn ×1.5, new MRR ×0.5', 'expected' => 'same as the last period', 'optimistic' => 'churn ×0.5, new MRR ×1.5'],
            'confidence' => $paying >= 10 ? 'medium' : 'low', 'note' => 'An estimate from recent trends, not a promise. With few paying customers one sign-up or cancellation changes it a lot.'];
    }

    // ------------------------------------------------------------------ الإعدادات

    public function settings(): array
    {
        $rows = $this->safe("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('cs_support_minutes', 'cs_support_cost_hour', 'cs_market_median_price')") ?? [];
        $m = array_column($rows, 'setting_value', 'setting_key');
        $num = static fn ($k) => isset($m[$k]) && $m[$k] !== '' && is_numeric($m[$k]) ? (float) $m[$k] : null;
        return ['support_minutes' => $num('cs_support_minutes'), 'support_cost_hour' => $num('cs_support_cost_hour'), 'market_median_price' => $num('cs_market_median_price')];
    }

    public function saveSettings(array $in): array
    {
        foreach (['support_minutes' => 'cs_support_minutes', 'support_cost_hour' => 'cs_support_cost_hour', 'market_median_price' => 'cs_market_median_price'] as $f => $key) {
            if (!array_key_exists($f, $in)) continue;
            $v = trim((string) $in[$f]);
            if ($v !== '' && (!is_numeric($v) || (float) $v < 0 || (float) $v > 100000)) throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $f));
            $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, $v]);
        }
        return $this->settings();
    }

    /** كل حاجة لتبويب Customer Success */
    public function report(): array
    {
        $metrics = $this->metrics();
        $opp = $this->opportunities();
        $newMrr = $this->newMrrPerMonth();
        $set = $this->settings();
        return ['metrics' => $metrics, 'funnel' => $this->funnel(90), 'payments' => $this->payments(30), 'renewals' => $this->renewals(30), 'cancellations' => $this->cancellations(180),
            'support' => $this->support(90), 'opportunities' => $opp, 'services' => $this->services(),
            'pricing' => self::pricingSuggestions($opp['profit']['by_plan'] ?? [], $set['market_median_price']),
            'forecast' => $newMrr === null ? null : self::forecast((float) ($metrics['mrr'] ?? 0), (float) ($metrics['revenue_churn'] ?? 0), $newMrr, [3, 6, 12], (int) ($metrics['paying'] ?? 0)),
            'settings' => $set];
    }
}
