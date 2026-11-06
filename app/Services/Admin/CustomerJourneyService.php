<?php

/**
 * Tourfecto - رحلة العميل الجديد (للأدمن)
 *
 * بيجمّع من الجداول الموجودة (visitor_logs, login_history, websites, subscriptions, api_usage_logs, platform_leads)
 * إجابة: العميل ده عمل إيه في المنصة؟ دخل أنهي صفحات؟ قعد قد إيه؟ وصل لفين في التفعيل؟ ومين الأولى نكلمه؟
 *
 * مفيش تتبع جديد ولا كوكيز ولا بيانات زيادة: كله قراءة من سجلات المنصة الحالية.
 * وقت القعدة في الصفحة = الفرق بين زيارتها وزيارة اللي بعدها في نفس الجلسة (بحد أقصى 15 دقيقة)،
 * وآخر صفحة في الجلسة مش بتتحسب لأننا مانعرفش مشي إمتى.
 */
class CustomerJourneyService
{
    /** فجوة أكبر من كده بين صفحتين = جلسة جديدة (ثواني) */
    public const SESSION_GAP = 1800;
    /** أقصى وقت بنحسبه لصفحة واحدة (ثواني) */
    public const MAX_DWELL = 900;

    private const PAGE_LABELS = [
        '/dashboard' => 'لوحة التحكم', '/websites' => 'المواقع', '/integrations' => 'الربط والتكاملات',
        '/chat' => 'الشات الذكي', '/pricing' => 'الأسعار', '/plans' => 'الباقات', '/subscription' => 'الاشتراك',
        '/profile' => 'الملف الشخصي', '/support' => 'الدعم', '/seo' => 'السيو', '/social' => 'السوشيال',
        '/crm' => 'الـ CRM', '/onboarding' => 'الإعداد الأولي', '/wallet' => 'المحفظة', '/ads' => 'الإعلانات',
        '/creative' => 'استوديو التصميم', '/reputation' => 'السمعة', '/ai-visibility' => 'ظهور الذكاء الاصطناعي',
    ];

    /** @var Database */
    private $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /** وقت من قاعدة البيانات (UTC) كـ timestamp، بغض النظر عن توقيت PHP */
    public static function ts(?string $s): int
    {
        return $s ? (int) strtotime($s . ' UTC') : 0;
    }

    /** طلبات المتصفح التلقائية (ترجمة، أيقونات، ملفات) مش صفحات العميل دخلها بنفسه */
    public static function isTechnical(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? $url));
        return (bool) preg_match('#^/(i18n-dict|assets|favicon|robots\.txt|manifest|sw\.js|service-worker|\.well-known|health|ping)#', $path)
            || (bool) preg_match('#\.(js|css|map|png|jpe?g|gif|svg|ico|webp|woff2?|json|xml|txt)$#', $path);
    }

    /** المسار من غير query وبدون أرقام الـ id (عشان /websites/5 و /websites/9 يتعدّوا صفحة واحدة) */
    public static function normalizePath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);
        $path = '/' . trim($path, '/');
        $path = preg_replace('#/\d+(?=/|$)#', '/{id}', $path) ?: $path;
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function pageLabel(string $path): string
    {
        foreach (self::PAGE_LABELS as $prefix => $label) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return $label;
            }
        }
        return $path;
    }

    /**
     * يقسّم الزيارات (مرتبة من الأقدم للأحدث) لجلسات ويحسب وقت كل صفحة.
     * @param array<int,array{page_url:string,created_at:string}> $visits
     * @return array{sessions:array,pages:array,active_seconds:int}
     */
    public static function sessionize(array $visits): array
    {
        $sessions = [];
        $cur = null;
        $pages = [];
        $active = 0;
        $flush = function () use (&$cur, &$sessions) {
            if ($cur) {
                $sessions[] = $cur;
                $cur = null;
            }
        };
        $n = count($visits);
        for ($i = 0; $i < $n; $i++) {
            $t = self::ts((string) $visits[$i]['created_at']);
            $path = self::normalizePath((string) $visits[$i]['page_url']);
            $nextT = $i + 1 < $n ? (int) self::ts((string) $visits[$i + 1]['created_at']) : null;
            $gap = $nextT !== null ? $nextT - $t : null;
            if ($cur === null || $t - $cur['end_ts'] > self::SESSION_GAP) {
                $flush();
                $cur = ['start_ts' => $t, 'end_ts' => $t, 'pages' => 0, 'seconds' => 0, 'device' => (string) ($visits[$i]['device_type'] ?? '')];
            }
            $cur['pages']++;
            $cur['end_ts'] = $t;
            $pages[$path] ??= ['path' => $path, 'label' => self::pageLabel($path), 'views' => 0, 'seconds' => 0];
            $pages[$path]['views']++;
            if ($gap !== null && $gap <= self::SESSION_GAP) {
                $d = min($gap, self::MAX_DWELL);
                $cur['seconds'] += $d;
                $pages[$path]['seconds'] += $d;
                $active += $d;
            }
        }
        $flush();
        usort($pages, static fn ($a, $b) => [$b['seconds'], $b['views']] <=> [$a['seconds'], $a['views']]);
        return ['sessions' => $sessions, 'pages' => array_values($pages), 'active_seconds' => $active];
    }

    /** رقم تليفون دولي من رقم محلي (مصر افتراضيًا) أو null لو مش واضح */
    public static function waNumber(?string $phone, string $country = 'EG'): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strncmp($d, '00', 2) === 0) {
            $d = substr($d, 2);
        }
        if ($d === '') {
            return null;
        }
        if ($d[0] === '0' && strtoupper($country) === 'EG') {
            $d = '20' . substr($d, 1);
        }
        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function forUser(int $id, ?int $now = null): ?array
    {
        $now ??= time();
        $u = $this->rows('SELECT id, email, company_name, first_name, last_name, phone, email_verified_at, created_at FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id])[0] ?? null;
        if (!$u) {
            return null;
        }
        $signupTs = (int) self::ts((string) $u['created_at']);
        $name = trim((string) ($u['company_name'] ?: trim($u['first_name'] . ' ' . $u['last_name']))) ?: (string) $u['email'];

        $sub = $this->rows('SELECT s.status, s.current_period_end, sp.name plan_name, sp.price, sp.currency FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1', [$id])[0] ?? null;
        $sites = $this->rows('SELECT main_url, is_connected, created_at FROM websites WHERE user_id = ? ORDER BY id ASC LIMIT 20', [$id]);
        $logins = $this->rows("SELECT created_at FROM login_history WHERE user_id = ? AND status = 'success' ORDER BY id ASC LIMIT 200", [$id]);
        $visits = array_reverse($this->rows("SELECT page_url, created_at, device_type FROM visitor_logs WHERE user_id = ? AND page_url NOT LIKE '/api/%' ORDER BY id DESC LIMIT 1500", [$id]));
        $ai = $this->rows('SELECT COUNT(*) calls, MIN(created_at) first_at, MAX(created_at) last_at FROM api_usage_logs WHERE user_id = ?', [$id])[0] ?? ['calls' => 0, 'first_at' => null, 'last_at' => null];

        $visits = array_values(array_filter($visits, static fn ($v) => !self::isTechnical((string) $v['page_url'])));
        $j = self::sessionize($visits);
        $lastTs = $signupTs;
        foreach ($logins as $l) {
            $lastTs = max($lastTs, (int) self::ts((string) $l['created_at']));
        }
        if ($visits) {
            $lastTs = max($lastTs, (int) self::ts((string) end($visits)['created_at']));
        }

        // رجع بعد التسجيل؟ أي نشاط بعد 6 ساعات من أول تسجيل
        $returned = $lastTs - $signupTs >= 6 * 3600;
        $hoursSince = (int) floor(max(0, $now - $lastTs) / 3600);
        $state = $hoursSince < 24 && $returned ? 'active' : (!$returned ? 'never_returned' : ($hoursSince < 72 ? 'idle' : 'dormant'));

        $siteAdded = $sites !== [];
        $siteConnected = false;
        foreach ($sites as $s) {
            $siteConnected = $siteConnected || (int) $s['is_connected'] === 1;
        }
        $trialing = $sub && $sub['status'] === 'trialing';
        $daysLeft = $trialing && $sub['current_period_end'] ? (int) ceil(((int) self::ts((string) $sub['current_period_end']) - $now) / 86400) : null;
        $milestones = [
            ['key' => 'signup', 'label' => 'سجّل في المنصة', 'done' => true, 'at' => $u['created_at']],
            ['key' => 'email', 'label' => 'أكّد بريده', 'done' => !empty($u['email_verified_at']), 'at' => $u['email_verified_at']],
            ['key' => 'site', 'label' => 'أضاف موقعه', 'done' => $siteAdded, 'at' => $siteAdded ? $sites[0]['created_at'] : null],
            ['key' => 'connected', 'label' => 'ربط الموقع (بيفعّل السيو)', 'done' => $siteConnected, 'at' => null],
            ['key' => 'ai', 'label' => 'جرّب أداة ذكاء اصطناعي', 'done' => (int) $ai['calls'] > 0, 'at' => $ai['first_at']],
            ['key' => 'returned', 'label' => 'رجع بعد أول يوم', 'done' => $returned, 'at' => $returned ? gmdate('Y-m-d H:i:s', $lastTs) : null],
            ['key' => 'paid', 'label' => 'اشترك بفلوس', 'done' => $sub && $sub['status'] === 'active', 'at' => null],
        ];
        $doneCount = count(array_filter($milestones, static fn ($m) => $m['done']));

        $lead = $this->leadFor((string) $u['email'], $sites);
        $next = $this->nextSteps($state, $hoursSince, $siteAdded, $siteConnected, $daysLeft, $lead, (int) $ai['calls']);

        $phone = $u['phone'] ?: ($lead['phone'] ?? null);
        $wa = self::waNumber($phone, (string) ($lead['country'] ?? 'EG'));
        $msg = 'أهلاً فريق ' . $name . ' 👋 معاكم من Tourfecto. شفت إنكم بدأتوا تجربة المنصة، تحبوا أساعدكم في أول خطوة (ربط موقعكم أو تشغيل أول تحليل)؟ أنا موجود.';

        return [
            'user' => ['id' => (int) $u['id'], 'name' => $name, 'email' => $u['email'], 'phone' => $phone, 'created_at' => $u['created_at']],
            'plan' => $sub ? ['name' => $sub['plan_name'], 'status' => $sub['status'], 'trial_days_left' => $daysLeft, 'ends' => $sub['current_period_end']] : null,
            'state' => $state,
            'last_seen' => gmdate('Y-m-d H:i:s', $lastTs),
            'hours_since_last_seen' => $hoursSince,
            'visits' => count($visits),
            'logins' => count($logins),
            'sessions' => array_map(static fn ($s) => ['start' => gmdate('Y-m-d H:i:s', $s['start_ts']), 'pages' => $s['pages'], 'seconds' => $s['seconds'], 'device' => $s['device']], array_slice(array_reverse($j['sessions']), 0, 10)),
            'session_count' => count($j['sessions']),
            'active_seconds' => $j['active_seconds'],
            'top_pages' => array_slice($j['pages'], 0, 12),
            'ai_calls' => (int) $ai['calls'],
            'milestones' => $milestones,
            'activation' => ['done' => $doneCount, 'total' => count($milestones)],
            'source' => $lead ? ['company' => $lead['company'], 'track' => $lead['track'], 'report_viewed' => !empty($lead['report_viewed_at']), 'emailed' => (int) $lead['step'] > 0, 'whatsapp' => !empty($lead['desk_action']) && $lead['desk_action'] !== 'wrong_number'] : null,
            'next_steps' => $next,
            'contact' => ['wa_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null, 'message' => $msg],
        ];
    }

    /**
     * أحدث المشتركين مرتبين بالأولى بالمتابعة (التجربة هتخلص + مرجعش + موقعه مش مربوط).
     * @return array<int,array<string,mixed>>
     */
    public function recentSignups(int $days = 14, int $limit = 30, ?int $now = null): array
    {
        $now ??= time();
        $ids = $this->rows("SELECT id FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . max(1, min(60, $days)) . " DAY) AND deleted_at IS NULL AND role NOT IN ('admin','super_admin') ORDER BY id DESC LIMIT " . max(1, min(50, $limit)));
        $out = [];
        foreach ($ids as $r) {
            $j = $this->forUser((int) $r['id'], $now);
            if (!$j) {
                continue;
            }
            $urgent = 0;
            if (($j['plan']['trial_days_left'] ?? 99) <= 2 && ($j['plan']['status'] ?? '') === 'trialing') {
                $urgent += 3;
            }
            $urgent += $j['state'] === 'never_returned' ? 2 : ($j['state'] === 'dormant' ? 1 : 0);
            $urgent += !array_values(array_filter($j['milestones'], static fn ($m) => $m['key'] === 'connected'))[0]['done'] ? 1 : 0;
            $j['urgency'] = $j['plan'] && $j['plan']['status'] === 'active' ? 0 : $urgent;
            unset($j['sessions'], $j['top_pages'], $j['contact']['message']);
            $out[] = $j;
        }
        usort($out, static fn ($a, $b) => [$b['urgency'], $b['user']['id']] <=> [$a['urgency'], $a['user']['id']]);
        return $out;
    }

    private function nextSteps(string $state, int $hours, bool $siteAdded, bool $siteConnected, ?int $daysLeft, ?array $lead, int $aiCalls): array
    {
        $n = [];
        if ($daysLeft !== null && $daysLeft <= 2) {
            $n[] = ['level' => 'high', 'text' => $daysLeft <= 0 ? 'التجربة خلصت: كلّمه دلوقتي وفكّره باللي استفاده، وامدّ له التجربة لو محتاج وقت.' : 'التجربة هتخلص بعد ' . $daysLeft . ' يوم: كلّمه النهارده قبل ما يضيع.'];
        }
        if ($state === 'never_returned') {
            $n[] = ['level' => 'high', 'text' => 'دخل مرة واحدة بس ومرجعش (من ' . $hours . ' ساعة). رسالة واتساب شخصية أحسن من أي إيميل.'];
        } elseif ($state === 'dormant') {
            $n[] = ['level' => 'medium', 'text' => 'ماظهرش من أكتر من 3 أيام.'];
        }
        if ($siteAdded && !$siteConnected) {
            $n[] = ['level' => 'high', 'text' => 'أضاف موقعه بس ما ربطوش: الربط هو اللي بيفعّل السيو. اعرض تربطه معاه.'];
        }
        if (!$siteAdded) {
            $n[] = ['level' => 'medium', 'text' => 'لسه ما أضافش موقعه: دي أول خطوة، ساعده فيها.'];
        }
        if ($aiCalls === 0) {
            $n[] = ['level' => 'low', 'text' => 'لسه ما جرّبش أي أداة ذكاء اصطناعي.'];
        }
        if ($lead && !empty($lead['report_viewed_at'])) {
            $n[] = ['level' => 'info', 'text' => 'كان فاتح تقرير موقعه قبل التسجيل: ابدأ الكلام من نتايج التقرير.'];
        }
        return $n;
    }

    /** الشركة اللي جه منها من حملة التسويق: بنفس الإيميل أو بدومين الموقع */
    private function leadFor(string $email, array $sites): ?array
    {
        $domains = [];
        foreach ($sites as $s) {
            $h = strtolower((string) (parse_url((string) $s['main_url'], PHP_URL_HOST) ?: ''));
            if ($h !== '') {
                $domains[] = preg_replace('/^www\./', '', $h);
            }
        }
        $where = 'LOWER(email) = ?';
        $bind = [strtolower($email)];
        if ($domains) {
            $where .= ' OR domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
            $bind = array_merge($bind, $domains);
        }
        return $this->rows("SELECT company, phone, country, track, step, report_viewed_at, desk_action FROM platform_leads WHERE {$where} ORDER BY (report_viewed_at IS NOT NULL) DESC, id LIMIT 1", $bind)[0] ?? null;
    }

    private function rows(string $sql, array $bind = []): array
    {
        try {
            $r = $this->db->query($sql, $bind);
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('CustomerJourney query failed', ['message' => $e->getMessage(), 'sql' => substr($sql, 0, 120)]);
            }
            return [];
        }
    }
}
