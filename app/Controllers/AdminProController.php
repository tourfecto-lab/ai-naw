<?php

/**
 * Tourfecto - Admin Pro Controller
 * طبقة التحليلات والتشغيل للأدمن: مؤشرات أداء بمقارنة بالفترة السابقة،
 * تنبيهات تحتاج تدخل، سجل نشاط موحّد، بحث شامل، وفحص صحة النظام.
 *
 * كل الاستعلامات للقراءة فقط، وكل استعلام معزول (safe) عشان فشل جدول
 * واحد ما يوقفش الصفحة كلها. محمي بنفس AuthMiddleware + AdminMiddleware.
 *
 * @version 1.0.0
 */

class AdminProController extends Controller
{
    /** مسارات بتعتبر مسح/استكشاف آلي (بوتات بتجرب ووردبريس، .env ... إلخ) */
    private const PROBE = "page_url REGEXP '(wp-admin|wp-login|wp-content|wp-includes|xmlrpc|phpmyadmin|/[.]env|/[.]git|[.]php$|cgi-bin|/vendor/|/actuator)'";

    /** زيارة حقيقية = مش طلب API ومش محاولة مسح آلي */
    private const CLEAN = "page_url NOT LIKE '/api/%' AND page_url NOT LIKE '/i18n-dict%' AND page_url NOT LIKE '/admin%' AND COALESCE(user_agent,'') NOT REGEXP 'TourfectoOpsCheck|HeadlessChrome|python-requests|curl/|wget|Go-http-client|bot|crawl|spider|monitor' AND page_url NOT REGEXP '(wp-admin|wp-login|wp-content|wp-includes|xmlrpc|phpmyadmin|/[.]env|/[.]git|[.]php$|cgi-bin|/vendor/|/actuator|[.]js$|/widget/)'";

    /** GET /api/admin/pro/overview?range=7|30|90 */
    public function overview(array $params = []): array
    {
        $days = (int) ($this->get('range', 30));
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $dayList = $this->dayList($days * 2);          // السابقة + الحالية
        $from = $dayList[0] . ' 00:00:00';
        $split = $days;                                // أول index للفترة الحالية

        // ---------- سلاسل يومية (فترة حالية + سابقة في استعلام واحد) ----------
        $signups = $this->series("SELECT DATE(created_at) d, COUNT(*) v FROM users WHERE created_at >= :from" . TestAccounts::exclude('id') . " GROUP BY d", $from, $dayList);
        $revenue = $this->series("SELECT DATE(created_at) d, SUM(amount) v FROM wallet_transactions WHERE type = 'deposit' AND status = 'completed' AND created_at >= :from" . TestAccounts::exclude('user_id') . " GROUP BY d", $from, $dayList);
        $visits = $this->series("SELECT DATE(created_at) d, COUNT(*) v FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :from GROUP BY d", $from, $dayList);
        $uniques = $this->series("SELECT DATE(created_at) d, COUNT(DISTINCT visitor_id) v FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :from GROUP BY d", $from, $dayList);
        $probes = $this->series("SELECT DATE(created_at) d, COUNT(DISTINCT visitor_id) v FROM visitor_logs WHERE " . self::PROBE . " AND created_at >= :from GROUP BY d", $from, $dayList);
        $aiCost = $this->series("SELECT DATE(created_at) d, SUM(cost_in_usd) v FROM api_usage_logs WHERE created_at >= :from GROUP BY d", $from, $dayList);
        $logins = $this->series("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) v FROM login_history WHERE status = 'success' AND created_at >= :from GROUP BY d", $from, $dayList);
        $failedLogins = $this->series("SELECT DATE(created_at) d, COUNT(*) v FROM login_history WHERE status = 'failed' AND created_at >= :from GROUP BY d", $from, $dayList);
        $cancels = $this->series("SELECT DATE(updated_at) d, COUNT(*) v FROM subscriptions WHERE status IN ('cancelled','expired') AND updated_at >= :from AND COALESCE(payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('user_id') . " GROUP BY d", $from, $dayList);
        $newSubs = $this->series("SELECT DATE(created_at) d, COUNT(*) v FROM subscriptions WHERE status IN ('active','trialing','past_due') AND created_at >= :from AND COALESCE(payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('user_id') . " GROUP BY d", $from, $dayList);

        // ---------- MRR (الحالي + تقدير لبداية الفترة) ----------
        $mrrExpr = "CASE WHEN sp.billing_cycle = 'yearly' THEN sp.price / 12 ELSE sp.price END";
        $mrrNow = (float) ($this->one("SELECT COALESCE(SUM($mrrExpr), 0) v FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.status IN ('active','past_due') AND COALESCE(s.payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('s.user_id'))['v'] ?? 0);
        $periodStart = $dayList[$split] . ' 00:00:00';
        $mrrThen = (float) ($this->one(
            "SELECT COALESCE(SUM($mrrExpr), 0) v FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.created_at < :ps AND (s.status IN ('active','past_due') OR (s.status IN ('cancelled','expired') AND s.updated_at >= :ps2)) AND COALESCE(s.payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('s.user_id'),
            [':ps' => $periodStart, ':ps2' => $periodStart]
        )['v'] ?? 0);

        $totalUsers = (int) ($this->one("SELECT COUNT(*) v FROM users WHERE deleted_at IS NULL" . TestAccounts::exclude('id'))['v'] ?? 0);
        $activeSubs = (int) ($this->one("SELECT COUNT(*) v FROM subscriptions WHERE status = 'active' AND COALESCE(payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('user_id'))['v'] ?? 0);
        $paying = (int) ($this->one("SELECT COUNT(DISTINCT user_id) v FROM subscriptions WHERE status IN ('active','past_due') AND COALESCE(payment_gateway, '') <> 'admin_grant'" . TestAccounts::exclude('user_id'))['v'] ?? 0);
        $arpu = $paying > 0 ? round($mrrNow / $paying, 2) : 0;

        // جودة الزيارات: نسبة الزوار اللي شافوا أكتر من صفحة (بيفلتر الروبوتات تقريبًا)
        $q = $this->one(
            "SELECT COUNT(*) total, SUM(pages > 1) engaged FROM (
                SELECT visitor_id, COUNT(*) pages FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :ps GROUP BY visitor_id
             ) t",
            [':ps' => $periodStart]
        );
        $engagedRate = ((int) ($q['total'] ?? 0)) > 0 ? round(100 * (int) $q['engaged'] / (int) $q['total'], 1) : 0;

        $kpis = [
            'mrr' => $this->kpi($mrrNow, $mrrThen, null, 'money'),
            'revenue' => $this->kpi(array_sum(array_slice($revenue, $split)), array_sum(array_slice($revenue, 0, $split)), array_slice($revenue, $split), 'money'),
            'signups' => $this->kpi(array_sum(array_slice($signups, $split)), array_sum(array_slice($signups, 0, $split)), array_slice($signups, $split), 'int'),
            'active_users' => $this->kpi($this->distinctLogins($periodStart), $this->distinctLogins($from, $periodStart), array_slice($logins, $split), 'int'),
            'visitors' => $this->kpi($this->distinctVisitors($periodStart), $this->distinctVisitors($from, $periodStart), array_slice($uniques, $split), 'int'),
            'churn' => $this->kpi(array_sum(array_slice($cancels, $split)), array_sum(array_slice($cancels, 0, $split)), array_slice($cancels, $split), 'int', true),
            'new_subs' => $this->kpi(array_sum(array_slice($newSubs, $split)), array_sum(array_slice($newSubs, 0, $split)), array_slice($newSubs, $split), 'int'),
            'ai_cost' => $this->kpi(round(array_sum(array_slice($aiCost, $split)), 4), round(array_sum(array_slice($aiCost, 0, $split)), 4), array_slice($aiCost, $split), 'usd', true),
            'probes' => $this->kpi($this->distinctProbes($periodStart), $this->distinctProbes($from, $periodStart), array_slice($probes, $split), 'int', true),
            'failed_logins' => $this->kpi(array_sum(array_slice($failedLogins, $split)), array_sum(array_slice($failedLogins, 0, $split)), array_slice($failedLogins, $split), 'int', true),
        ];
        $kpis['arpu'] = ['value' => $arpu, 'format' => 'money'];
        $kpis['engaged_rate'] = ['value' => $engagedRate, 'format' => 'pct'];
        $kpis['total_users'] = ['value' => $totalUsers, 'format' => 'int'];
        $kpis['active_subs'] = ['value' => $activeSubs, 'format' => 'int'];

        // ---------- توزيعات ----------
        $plans = $this->rows(
            "SELECT sp.name, sp.billing_cycle, COUNT(*) subs, SUM($mrrExpr) mrr
             FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.status IN ('active','past_due') GROUP BY sp.id, sp.name, sp.billing_cycle ORDER BY mrr DESC"
        );
        $countries = $this->rows(
            "SELECT COALESCE(NULLIF(country, ''), 'Unknown') country, COUNT(DISTINCT visitor_id) visitors
             FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :ps GROUP BY 1 ORDER BY visitors DESC LIMIT 8",
            [':ps' => $periodStart]
        );
        // كل الدول (للخريطة) + مواقع أنشطة العملاء اللي ليها إحداثيات حقيقية
        $countriesAll = $this->rows(
            "SELECT COALESCE(NULLIF(country, ''), 'Unknown') country, COUNT(DISTINCT visitor_id) visitors
             FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :ps GROUP BY 1 ORDER BY visitors DESC LIMIT 80",
            [':ps' => $periodStart]
        );
        $pins = $this->rows(
            "SELECT COALESCE(NULLIF(brand_name,''), NULLIF(company_name,''), domain) name, latitude lat, longitude lng
             FROM websites WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND (latitude <> 0 OR longitude <> 0) LIMIT 300"
        );
        $devices = $this->rows(
            "SELECT COALESCE(NULLIF(device_type, ''), 'unknown') device, COUNT(DISTINCT visitor_id) visitors
             FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :ps GROUP BY 1 ORDER BY visitors DESC",
            [':ps' => $periodStart]
        );
        $pages = $this->rows(
            "SELECT page_url, COUNT(*) views, COUNT(DISTINCT visitor_id) visitors
             FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :ps GROUP BY page_url ORDER BY views DESC LIMIT 6",
            [':ps' => $periodStart]
        );
        $aiFeatures = $this->rows(
            "SELECT COALESCE(NULLIF(feature, ''), api_type) feature, COUNT(*) calls, ROUND(SUM(cost_in_usd), 4) cost, SUM(tokens_used) tokens
             FROM api_usage_logs WHERE created_at >= :ps GROUP BY 1 ORDER BY cost DESC, calls DESC LIMIT 6",
            [':ps' => $periodStart]
        );

        // ---------- مسار التحويل لعملاء الفترة ----------
        $f = $this->one(
            "SELECT COUNT(*) signed,
                    SUM(email_verified_at IS NOT NULL) verified,
                    SUM(EXISTS (SELECT 1 FROM websites w WHERE w.user_id = u.id)) has_site,
                    SUM(EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status IN ('active','trialing','past_due'))) subscribed
             FROM users u WHERE u.created_at >= :ps AND u.deleted_at IS NULL",
            [':ps' => $periodStart]
        );
        $funnel = [
            ['key' => 'signed', 'value' => (int) ($f['signed'] ?? 0)],
            ['key' => 'verified', 'value' => (int) ($f['verified'] ?? 0)],
            ['key' => 'has_site', 'value' => (int) ($f['has_site'] ?? 0)],
            ['key' => 'subscribed', 'value' => (int) ($f['subscribed'] ?? 0)],
        ];

        return $this->success([
            'range' => $days,
            'generated_at' => gmdate('c'),
            'labels' => array_slice($dayList, $split),
            'kpis' => $kpis,
            'series' => [
                'revenue' => array_slice($revenue, $split),
                'signups' => array_slice($signups, $split),
                'visits' => array_slice($visits, $split),
                'uniques' => array_slice($uniques, $split),
                'ai_cost' => array_slice($aiCost, $split),
            ],
            'plans' => $plans,
            'countries' => $countries,
            'countries_all' => $countriesAll,
            'pins' => array_map(function ($p) { return ['name' => $p['name'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']]; }, $pins),
            'devices' => $devices,
            'pages' => $pages,
            'ai_features' => $aiFeatures,
            'funnel' => $funnel,
        ]);
    }

    /** GET /api/admin/pro/attention - حاجات محتاجة تدخل دلوقتي (مرتبة بالأهمية) */
    public function attention(array $params = []): array
    {
        $items = [];
        $add = function (string $key, string $severity, int $count, string $link, array $extra = []) use (&$items) {
            if ($count > 0) {
                $items[] = array_merge(['key' => $key, 'severity' => $severity, 'count' => $count, 'link' => $link], $extra);
            }
        };

        $v = function (string $sql, array $b = []) {
            return (int) ($this->one($sql, $b)['v'] ?? 0);
        };

        $add('pending_deposits', 'high', $v("SELECT COUNT(*) v FROM wallet_transactions WHERE type = 'deposit' AND status = 'pending'"), '/admin/plans');
        $add('past_due', 'high', $v("SELECT COUNT(*) v FROM subscriptions WHERE status = 'past_due'"), '/admin/subscriptions');
        $add('admins_no_2fa', 'high', $v("SELECT COUNT(*) v FROM users WHERE role IN ('admin','super_admin') AND two_factor_enabled = 0 AND deleted_at IS NULL"), '/admin/users');
        $add('failed_jobs', 'medium', $v("SELECT COUNT(*) v FROM jobs WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"), '/admin/system');
        $add('suspicious_ips', 'medium', $v("SELECT COUNT(*) v FROM (SELECT ip_address FROM login_history WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY ip_address HAVING COUNT(*) >= 5) t"), '/admin/login-history');
        $add('new_messages', 'medium', $v("SELECT COUNT(*) v FROM contact_submissions WHERE status = 'new'"), '/admin/contact-messages');
        $add('expiring_soon', 'medium', $v("SELECT COUNT(*) v FROM subscriptions WHERE status IN ('active','trialing') AND current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"), '/admin/subscriptions');
        $add('pending_refunds', 'medium', $v("SELECT COUNT(*) v FROM refunds WHERE status IN ('pending','processing')"), '/admin/plans');
        $add('active_blocks', 'low', $v("SELECT COUNT(*) v FROM rate_limit_blocks WHERE expires_at > NOW()"), '/admin/login-history');
        $add('unverified_old', 'low', $v("SELECT COUNT(*) v FROM users WHERE email_verified_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) AND deleted_at IS NULL AND role NOT IN ('admin','super_admin')"), '/admin/users');

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($items, function ($a, $b) use ($order) {
            return [$order[$a['severity']], -$a['count']] <=> [$order[$b['severity']], -$b['count']];
        });

        return $this->success(['items' => $items, 'total' => array_sum(array_column($items, 'count'))]);
    }

    /** GET /api/admin/pro/activity?limit=20 - سجل نشاط موحّد من كل المصادر */
    public function activity(array $params = []): array
    {
        $limit = max(5, min(60, (int) $this->get('limit', 20)));

        // ملاحظة: الأعمدة النصية بتتحوّل لـ utf8mb4_unicode_ci صراحةً عشان
        // الـ UNION ما يفشلش لو جداول مختلفة في الـ collation.
        $c = function (string $expr) {
            return "CONVERT($expr USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        };
        $sql = "
            (SELECT 'signup' type, {$c("COALESCE(NULLIF(company_name,''), email)")} title, {$c('email')} sub, created_at ts, CONCAT('/admin/users?user=', id) link FROM users WHERE created_at IS NOT NULL ORDER BY created_at DESC LIMIT $limit)
            UNION ALL
            (SELECT 'subscription', {$c("COALESCE(NULLIF(u.company_name,''), u.email)")}, {$c("CONCAT(COALESCE(sp.plan_code,'plan'), ' · ', s.status)")}, s.created_at, '/admin/subscriptions' FROM subscriptions s JOIN users u ON u.id = s.user_id LEFT JOIN subscription_plans sp ON sp.id = s.plan_id ORDER BY s.created_at DESC LIMIT $limit)
            UNION ALL
            (SELECT CONCAT('wallet_', w.status), {$c("COALESCE(NULLIF(u.company_name,''), u.email)")}, {$c("CONCAT(w.type, ' · ', w.amount, ' ', w.currency)")}, w.created_at, '/admin/plans' FROM wallet_transactions w JOIN users u ON u.id = w.user_id WHERE w.type IN ('deposit','refund') ORDER BY w.created_at DESC LIMIT $limit)
            UNION ALL
            (SELECT 'contact', {$c('name')}, {$c('LEFT(message, 90)')}, created_at, '/admin/contact-messages' FROM contact_submissions ORDER BY created_at DESC LIMIT $limit)
            UNION ALL
            (SELECT 'impersonation', {$c("CONCAT('#', admin_id, ' → #', target_user_id)")}, {$c("COALESCE(reason, '')")}, started_at, '/admin/login-history' FROM impersonation_logs ORDER BY started_at DESC LIMIT $limit)
            UNION ALL
            (SELECT CONCAT('audit_', result), {$c('action')}, {$c("CONCAT(COALESCE(object_type,''), ' ', COALESCE(object_id,''))")}, created_at, '/admin/logs' FROM audit_logs ORDER BY created_at DESC LIMIT $limit)
            ORDER BY ts DESC LIMIT $limit";

        return $this->success(['items' => $this->rows($sql)]);
    }

    /** GET /api/admin/pro/search?q= - بحث شامل عبر المستخدمين والمواقع والفواتير والرسائل */
    public function search(array $params = []): array
    {
        $q = trim((string) $this->get('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->success(['groups' => []]);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $id = ctype_digit($q) ? (int) $q : 0;
        $groups = [];

        $users = $this->rows(
            "SELECT id, email, company_name, display_name, role, status FROM users
             WHERE deleted_at IS NULL AND (email LIKE :l1 OR company_name LIKE :l2 OR display_name LIKE :l3 OR phone LIKE :l4 OR id = :id)
             ORDER BY (id = :id2) DESC, created_at DESC LIMIT 6",
            [':l1' => $like, ':l2' => $like, ':l3' => $like, ':l4' => $like, ':id' => $id, ':id2' => $id]
        );
        if ($users) {
            $groups[] = ['type' => 'users', 'items' => array_map(function ($u) {
                return [
                    'title' => $u['company_name'] ?: ($u['display_name'] ?: $u['email']),
                    'sub' => $u['email'] . ' · ' . $u['role'] . ' · ' . $u['status'],
                    'url' => '/admin/users?user=' . $u['id'],
                ];
            }, $users)];
        }

        $sites = $this->rows(
            "SELECT id, user_id, COALESCE(NULLIF(brand_name,''), company_name) name, domain, main_url FROM websites
             WHERE domain LIKE :l1 OR brand_name LIKE :l2 OR company_name LIKE :l3 OR main_url LIKE :l4
             GROUP BY COALESCE(NULLIF(domain,''), main_url), user_id LIMIT 5",
            [':l1' => $like, ':l2' => $like, ':l3' => $like, ':l4' => $like]
        );
        if ($sites) {
            $groups[] = ['type' => 'websites', 'items' => array_map(function ($w) {
                return ['title' => $w['name'] ?: $w['domain'], 'sub' => $w['domain'] ?: $w['main_url'], 'url' => '/admin/users?user=' . $w['user_id']];
            }, $sites)];
        }

        $inv = $this->rows(
            "SELECT invoice_number, amount, currency, status, user_id FROM invoices WHERE invoice_number LIKE :l1 OR transaction_id LIKE :l2 ORDER BY id DESC LIMIT 4",
            [':l1' => $like, ':l2' => $like]
        );
        if ($inv) {
            $groups[] = ['type' => 'invoices', 'items' => array_map(function ($i) {
                return ['title' => $i['invoice_number'], 'sub' => $i['amount'] . ' ' . $i['currency'] . ' · ' . $i['status'], 'url' => '/admin/users?user=' . $i['user_id']];
            }, $inv)];
        }

        $msgs = $this->rows(
            "SELECT name, email, LEFT(message, 70) m FROM contact_submissions WHERE name LIKE :l1 OR email LIKE :l2 OR message LIKE :l3 ORDER BY id DESC LIMIT 4",
            [':l1' => $like, ':l2' => $like, ':l3' => $like]
        );
        if ($msgs) {
            $groups[] = ['type' => 'messages', 'items' => array_map(function ($m) {
                return ['title' => $m['name'], 'sub' => $m['m'], 'url' => '/admin/contact-messages'];
            }, $msgs)];
        }

        return $this->success(['groups' => $groups]);
    }

    /** GET /api/admin/pro/health - فحص صحة النظام مع درجات */
    public function health(array $params = []): array
    {
        $checks = [];

        $t = microtime(true);
        $ok = $this->one('SELECT 1 v');
        $ms = (int) round((microtime(true) - $t) * 1000);
        $checks[] = ['key' => 'database', 'status' => $ok ? ($ms < 150 ? 'ok' : 'warn') : 'fail', 'value' => $ms . ' ms'];

        $pending = (int) ($this->one("SELECT COUNT(*) v FROM jobs WHERE status = 'pending' AND available_at <= NOW()")['v'] ?? 0);
        $oldest = $this->one("SELECT TIMESTAMPDIFF(MINUTE, MIN(available_at), NOW()) v FROM jobs WHERE status = 'pending' AND available_at <= NOW()");
        $lag = (int) ($oldest['v'] ?? 0);
        $checks[] = ['key' => 'queue', 'status' => $pending === 0 ? 'ok' : ($lag > 30 ? 'fail' : 'warn'), 'value' => $pending . ' · ' . $lag . ' min'];

        $failed = (int) ($this->one("SELECT COUNT(*) v FROM jobs WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")['v'] ?? 0);
        $done = (int) ($this->one("SELECT COUNT(*) v FROM jobs WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")['v'] ?? 0);
        $rate = ($failed + $done) > 0 ? round(100 * $failed / ($failed + $done)) : 0;
        $checks[] = ['key' => 'job_failures', 'status' => $rate === 0 ? 'ok' : ($rate < 20 ? 'warn' : 'fail'), 'value' => $rate . '%'];

        $apiErr = $this->one("SELECT COUNT(*) total, SUM(status_code >= 400) bad FROM api_usage_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $tot = (int) ($apiErr['total'] ?? 0);
        $bad = (int) ($apiErr['bad'] ?? 0);
        $checks[] = ['key' => 'ai_api', 'status' => $tot === 0 || $bad === 0 ? 'ok' : (($bad / $tot) < 0.1 ? 'warn' : 'fail'), 'value' => $bad . '/' . $tot];

        $root = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : dirname(__DIR__, 2);
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free !== false && $total) {
            $pct = round(100 * $free / $total);
            $checks[] = ['key' => 'disk', 'status' => $pct > 20 ? 'ok' : ($pct > 8 ? 'warn' : 'fail'), 'value' => $pct . '% free'];
        }

        $checks[] = ['key' => 'storage_writable', 'status' => is_writable($root) ? 'ok' : 'fail', 'value' => is_writable($root) ? 'rw' : 'ro'];
        $checks[] = ['key' => 'php', 'status' => version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', 'value' => PHP_VERSION];

        $weights = ['ok' => 1, 'warn' => 0.5, 'fail' => 0];
        $score = (int) round(100 * array_sum(array_map(function ($c) use ($weights) { return $weights[$c['status']]; }, $checks)) / max(1, count($checks)));

        return $this->success(['score' => $score, 'checks' => $checks]);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return string[] تواريخ Y-m-d من أقدم يوم لحد النهارده */
    private function dayList(int $n): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = gmdate('Y-m-d', strtotime("-$i day"));
        }
        return $out;
    }

    /** @return float[] قيمة لكل يوم في $dayList (أصفار للأيام الفاضية) */
    private function series(string $sql, string $from, array $dayList): array
    {
        $map = [];
        foreach ($this->rows($sql, [':from' => $from]) as $r) {
            $map[$r['d']] = (float) $r['v'];
        }
        return array_map(function ($d) use ($map) { return $map[$d] ?? 0; }, $dayList);
    }

    private function kpi($now, $prev, ?array $spark, string $format, bool $lowerIsBetter = false): array
    {
        $now = (float) $now;
        $prev = (float) $prev;
        $delta = $prev > 0 ? round(100 * ($now - $prev) / $prev, 1) : ($now > 0 ? null : 0.0);
        return [
            'value' => $now,
            'prev' => $prev,
            'delta' => $delta,            // null = جديد (مفيش فترة سابقة للمقارنة)
            'spark' => $spark,
            'format' => $format,
            'lower_is_better' => $lowerIsBetter,
        ];
    }

    private function distinctLogins(string $from, ?string $to = null): int
    {
        $sql = "SELECT COUNT(DISTINCT user_id) v FROM login_history WHERE status = 'success' AND created_at >= :f";
        $b = [':f' => $from];
        if ($to) { $sql .= ' AND created_at < :t'; $b[':t'] = $to; }
        return (int) ($this->one($sql, $b)['v'] ?? 0);
    }

    private function distinctVisitors(string $from, ?string $to = null): int
    {
        $sql = "SELECT COUNT(DISTINCT visitor_id) v FROM visitor_logs WHERE " . self::CLEAN . " AND created_at >= :f";
        $b = [':f' => $from];
        if ($to) { $sql .= ' AND created_at < :t'; $b[':t'] = $to; }
        return (int) ($this->one($sql, $b)['v'] ?? 0);
    }

    private function distinctProbes(string $from, ?string $to = null): int
    {
        $sql = "SELECT COUNT(DISTINCT visitor_id) v FROM visitor_logs WHERE " . self::PROBE . " AND created_at >= :f";
        $b = [':f' => $from];
        if ($to) { $sql .= ' AND created_at < :t'; $b[':t'] = $to; }
        return (int) ($this->one($sql, $b)['v'] ?? 0);
    }

    private function rows(string $sql, array $bind = []): array
    {
        try {
            $r = $this->db->query($sql, $bind);
            return is_array($r) ? $r : [];
        } catch (Exception $e) {
            Logger::error('AdminPro query failed', ['sql' => $sql, 'message' => $e->getMessage()]);
            return [];
        }
    }

    private function one(string $sql, array $bind = []): array
    {
        $r = $this->rows($sql, $bind);
        return $r[0] ?? [];
    }
}
