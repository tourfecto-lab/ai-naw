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

        $lead = $this->leadFor((string) $u['email'], $sites, $id);
        $next = $this->nextSteps($state, $hoursSince, $siteAdded, $siteConnected, $daysLeft, $lead, (int) $ai['calls']);

        $phone = $u['phone'] ?: ($lead['phone'] ?? null);
        $wa = self::waNumber($phone, (string) ($lead['country'] ?? 'EG'));
        $domain = $sites ? (string) preg_replace('/^www\./', '', strtolower((string) (parse_url((string) $sites[0]['main_url'], PHP_URL_HOST) ?: ''))) : '';
        $stage = self::stageFor($sub ? (string) $sub['status'] : '', $daysLeft, $siteAdded, $siteConnected, (int) $ai['calls'], $state);
        $lang = in_array($lead['language'] ?? '', ['ar', 'en', 'tr'], true) ? $lead['language'] : 'ar';
        $msg = self::stageMessage($stage['key'], $lang, $name, $domain, $daysLeft, (int) $ai['calls'] > 0, $state === 'never_returned');

        $journey = [
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
            'source' => $lead ? [
                'lead_id' => (int) $lead['id'], 'company' => $lead['company'], 'track' => $lead['track'], 'report_viewed' => !empty($lead['report_viewed_at']),
                'emailed' => (int) $lead['step'] > 0, 'whatsapp' => !empty($lead['desk_action']) && $lead['desk_action'] !== 'wrong_number',
                // الموظف اللي بعت لها الرسالة/التقرير على واتساب (هو المسؤول عن متابعتها بعد ما سجّلت)
                'staff' => !empty($lead['desk_user_id']) ? ['id' => (int) $lead['desk_user_id'], 'name' => (string) ($lead['desk_by'] ?? '')] : null,
            ] : null,
            'next_steps' => $next,
            'stage' => $stage,
            'language' => $lang,
            'contact' => ['wa_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null, 'message' => $msg],
        ];
        $journey['assistant_context'] = self::assistantContext($journey);
        return $journey;
    }

    /**
     * المرحلة اللي العميل واقف عندها (بالأولوية): ودي اللي بتحدد الرسالة المناسبة.
     * @return array{key:string,label:string}
     */
    public static function stageFor(string $subStatus, ?int $daysLeft, bool $siteAdded, bool $siteConnected, int $aiCalls, string $state): array
    {
        if ($subStatus === 'active') {
            return ['key' => 'paid', 'label' => 'مشترك بفلوس: متابعة ودّية'];
        }
        if ($subStatus === 'trialing' && $daysLeft !== null && $daysLeft <= 1) {
            return ['key' => 'trial_ending', 'label' => 'التجربة بتخلص: ساعده يكمّل'];
        }
        if (!$siteAdded) {
            return ['key' => 'no_site', 'label' => 'لسه ما أضافش موقعه'];
        }
        if (!$siteConnected) {
            return ['key' => 'not_connected', 'label' => 'أضاف موقعه وما ربطوش'];
        }
        if ($aiCalls === 0) {
            return ['key' => 'no_ai', 'label' => 'مربوط ولسه ما جرّبش أداة'];
        }
        if ($state === 'dormant' || $state === 'idle') {
            return ['key' => 'dormant', 'label' => 'غاب بعد ما بدأ'];
        }
        return ['key' => 'check_in', 'label' => 'شغّال: اطمّن عليه'];
    }

    /** رسالة واتساب جاهزة حسب المرحلة (عربي مصري / English)، من غير أي وعد مش حقيقي. التركي بيرجع للإنجليزي */
    public static function stageMessage(string $stage, string $lang, string $name, string $domain, ?int $daysLeft, bool $triedAi, bool $neverReturned): string
    {
        $site = $domain !== '' ? $domain : ($lang === 'ar' ? 'موقعكم' : 'your website');
        $anon = strpos($name, '@') !== false || trim($name) === '';
        if ($lang === 'ar') {
            $hi = ($anon ? 'أهلاً بحضراتكم' : 'أهلاً فريق ' . $name) . ' 👋 معاكم من Tourfecto.';
            $t = [
                'trial_ending' => $hi . "\nالتجربة المجانية بتاعتكم قربت تخلص" . ($daysLeft !== null && $daysLeft > 0 ? ' (باقي ' . $daysLeft . ' يوم)' : '') . "، وماحبّيتش تضيع منكم قبل ما تشوفوا النتيجة الحقيقية.\nتحبوا أساعدكم نكمّل الإعداد دلوقتي في كام دقيقة؟",
                'paid' => $hi . "\nشكراً إنكم معانا 🙏 حبيت أطمّن إن كل حاجة ماشية معاكم، وإن مفيش أي خطوة محتاجة مساعدة. أنا موجود لأي سؤال.",
                'no_site' => $hi . "\nشفت إنكم فتحتوا حسابكم، وأول خطوة بسيطة هي إضافة موقع الشركة عشان المنصة تبدأ تفحصه وتقترح عليكم تحسينات.\nتحبوا أساعدكم تضيفوه دلوقتي؟ بياخد دقيقتين.",
                'not_connected' => $hi . "\nشفت إنكم فعّلتوا حسابكم" . ($triedAi ? ' وجرّبتوا تحليل الموقع، وده ممتاز 🙌' : '.') . "\nبس لسه " . $site . " ما اتربطش، والربط هو اللي بيخلّي المنصة تبدأ تحسّن ظهوركم في جوجل فعلاً.\nتحبوا أربطه معاكم دلوقتي؟ بياخد حوالي 5 دقايق وأنا موجود أساعدكم خطوة بخطوة.",
                'no_ai' => $hi . "\nموقعكم اتربط بنجاح 👏 والخطوة الجاية إنكم تجرّبوا أول أداة (مثلاً تحليل الظهور في جوجل) وتشوفوا النتيجة.\nتحبوا أوريكم أسرع طريقة تبدأوا بيها؟",
                'dormant' => $hi . "\nلاحظت إنكم غبتوا شوية عن المنصة، فحبيت أسأل: في أي حاجة وقفت معاكم أو محتاجة توضيح؟ أنا موجود أساعدكم.",
                'check_in' => $hi . "\nحبيت أطمّن عليكم وأسمع رأيكم في المنصة لحد دلوقتي. في أي حاجة تحبوا نحسّنها أو أساعدكم فيها؟",
            ];
            return $t[$stage] ?? $t['check_in'];
        }
        $hi = ($anon ? 'Hi there' : 'Hi ' . $name . ' team') . ' 👋 This is the Tourfecto team.';
        $t = [
            'trial_ending' => $hi . "\nYour free trial is about to end" . ($daysLeft !== null && $daysLeft > 0 ? ' (' . $daysLeft . ' day(s) left)' : '') . ", and I would hate for you to miss seeing real results.\nWould you like me to help you finish the setup in a few minutes?",
            'paid' => $hi . "\nThank you for being with us 🙏 Just checking that everything is going well and nothing needs help. I'm here for any question.",
            'no_site' => $hi . "\nI saw you opened your account. The first simple step is adding your company website so the platform can scan it and suggest improvements.\nWant me to help you add it now? It takes two minutes.",
            'not_connected' => $hi . "\nI saw you activated your account" . ($triedAi ? ' and tried the website analysis, great 🙌' : '.') . "\nBut " . $site . " is not connected yet, and connecting it is what lets the platform start improving your Google visibility.\nWant me to connect it with you now? It takes about 5 minutes and I'll guide you step by step.",
            'no_ai' => $hi . "\nYour website is connected 👏 The next step is trying your first tool (for example the Google visibility analysis) and seeing the result.\nWant me to show you the fastest way to start?",
            'dormant' => $hi . "\nI noticed you haven't been on the platform for a bit. Did anything get stuck or need explaining? I'm happy to help.",
            'check_in' => $hi . "\nJust checking in to hear what you think of the platform so far. Anything you'd like us to improve or help with?",
        ];
        return $t[$stage] ?? $t['check_in'];
    }

    /** ملخص قصير (أقل من 950 حرف) بيتحط في سياق المساعد الذكي عشان يرد على العميل وهو عارف حالته */
    public static function assistantContext(array $j): string
    {
        $done = array_map(static fn ($m) => $m['label'], array_filter($j['milestones'], static fn ($m) => $m['done']));
        $todo = array_map(static fn ($m) => $m['label'], array_filter($j['milestones'], static fn ($m) => !$m['done']));
        $lines = [
            'مهم: الشركة دي سجّلت فعلاً في المنصة (مش عميل محتمل جديد). هدفك تساعدها تفعّل وتستفيد وتكمّل، مش تبيعلها من الصفر.',
            'الاسم: ' . $j['user']['name'],
            $j['plan'] ? 'الباقة: ' . ($j['plan']['name'] ?? '') . ' (' . $j['plan']['status'] . ')' . ($j['plan']['trial_days_left'] !== null ? ' · باقي ' . max(0, $j['plan']['trial_days_left']) . ' يوم في التجربة' : '') : '',
            'آخر ظهور من ' . $j['hours_since_last_seen'] . ' ساعة · جلسات: ' . $j['session_count'] . ' · استدعاءات AI: ' . $j['ai_calls'],
            $done ? 'عمل: ' . implode('، ', $done) : '',
            $todo ? 'لسه ما عملش: ' . implode('، ', $todo) : '',
            'الخطوة المقترحة: ' . $j['stage']['label'],
            !empty($j['source']['report_viewed']) ? 'كان فاتح تقرير موقعه قبل التسجيل.' : '',
        ];
        return mb_substr(implode("\n", array_filter($lines)), 0, 950);
    }

    /**
     * أحدث المشتركين مرتبين بالأولى بالمتابعة (التجربة هتخلص + مرجعش + موقعه مش مربوط).
     * @return array<int,array<string,mixed>>
     */
    public function recentSignups(int $days = 14, int $limit = 30, ?int $now = null, ?int $staffId = null): array
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
            unset($j['sessions'], $j['top_pages'], $j['assistant_context']);
            if ($staffId !== null && (int) ($j['source']['staff']['id'] ?? 0) !== $staffId) {
                continue;
            }
            $out[] = $j;
        }
        usort($out, static fn ($a, $b) => [$b['urgency'], $b['user']['id']] <=> [$a['urgency'], $a['user']['id']]);
        return $out;
    }

    /**
     * استعلام خفيف (للتنبيه الفوري): آخر مشتركين جدد، منسوبين لموظف بعينه (اللي بعتلهم واتساب) أو الكل لو $staffId = null.
     * مفيش تفاصيل رحلة هنا عشان المكتب بيسأل عليه كل دقيقة.
     * @return array<int,array{id:int,name:string,created_at:string,staff_id:?int,staff_name:?string}>
     */
    public function newSignupAlerts(?int $staffId, int $days = 3, int $limit = 20): array
    {
        $days = max(1, min(14, $days));
        $limit = max(1, min(50, $limit));
        $domainOf = "LOWER(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(w.main_url,'://',-1),'/',1),'www.',''))";
        if ($staffId !== null) {
            $sql = "SELECT DISTINCT u.id, COALESCE(NULLIF(u.company_name,''), u.email) name, u.created_at, l.desk_user_id staff_id, l.desk_by staff_name
                      FROM users u
                      LEFT JOIN websites w ON w.user_id = u.id
                      JOIN platform_leads l ON (l.signup_user_id = u.id OR LOWER(l.email) = LOWER(u.email) OR (w.main_url IS NOT NULL AND l.domain = {$domainOf}))
                     WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin')
                       AND l.desk_user_id = ? ORDER BY u.id DESC LIMIT {$limit}";
            $rows = $this->rows($sql, [$staffId]);
        } else {
            $sql = "SELECT u.id, COALESCE(NULLIF(u.company_name,''), u.email) name, u.created_at, MAX(l.desk_user_id) staff_id, MAX(l.desk_by) staff_name
                      FROM users u
                      LEFT JOIN websites w ON w.user_id = u.id
                      LEFT JOIN platform_leads l ON (l.signup_user_id = u.id OR LOWER(l.email) = LOWER(u.email) OR (w.main_url IS NOT NULL AND l.domain = {$domainOf}))
                     WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin')
                     GROUP BY u.id, u.company_name, u.email, u.created_at ORDER BY u.id DESC LIMIT {$limit}";
            $rows = $this->rows($sql);
        }
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'created_at' => (string) $r['created_at'],
            'staff_id' => $r['staff_id'] !== null ? (int) $r['staff_id'] : null, 'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
        ], $rows);
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
    private function leadFor(string $email, array $sites, int $userId = 0): ?array
    {
        $domains = [];
        foreach ($sites as $s) {
            $h = strtolower((string) (parse_url((string) $s['main_url'], PHP_URL_HOST) ?: ''));
            if ($h !== '') {
                $domains[] = preg_replace('/^www\./', '', $h);
            }
        }
        // الأقوى: الشركة اللي حسابه اتربط بيها من لينك التقرير، وبعدها المطابقة بالإيميل أو دومين الموقع
        $where = 'signup_user_id = ? OR LOWER(email) = ?';
        $bind = [$userId, strtolower($email)];
        if ($domains) {
            $where .= ' OR domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
            $bind = array_merge($bind, $domains);
        }
        return $this->rows("SELECT id, company, phone, country, language, track, step, report_viewed_at, desk_action, desk_user_id, desk_by FROM platform_leads WHERE {$where} ORDER BY (signup_user_id = {$userId}) DESC, (report_viewed_at IS NOT NULL) DESC, id LIMIT 1", $bind)[0] ?? null;
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
