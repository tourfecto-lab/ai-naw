<?php

/**
 * التسجيل الذكي (زي heyvisibly): الزائر بيكتب رابط موقعه قبل ما يعمل حساب،
 * بنحلّل الموقع ونوريه نشاطه وسوقه ومنافسينه، وفي الآخر يعمل الحساب.
 * البيانات اللي راجعها بتتحفظ في الجلسة، وبعد التسجيل الإعداد بيكمّل بيها لوحده.
 */
class SmartSignupController extends Controller
{
    /** حد لكل زائر من غير ما نقفل الـ IP على باقي الموقع (معرّف user_ = حد ناعم) */
    /** هوية المتصفح (الجلسة) للحد الأساسي */
    protected function browserKey(): string
    {
        return (string) session_id();
    }

    private function limited(string $scope, int $max): ?array
    {
        // الأدمن بيجرّب التسجيل من نفس المتصفح: مالوش حد محاولات
        if (in_array((string) ($this->user['role'] ?? ''), ['admin', 'super_admin'], true)) return null;
        // الحد الأساسي لكل متصفح (جلسة). عنوان النت (IP) ليه سقف أكبر بكتير، لأن شبكات الموبايل والشركات
        // بتشغّل ناس كتير على نفس الـ IP، فعميل جديد مايتمنعش بسبب غيره؛ السقف ده بس ضد الإساءة
        try {
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $limiter = new RateLimiter();
            $sid = $this->browserKey();
            $checks = $sid !== '' ? [['user_smart_s_' . hash('sha256', $sid), $max], ['user_smart_ip_' . $ip, $max * 8]] : [['user_smart_ip_' . $ip, $max * 2]];
            foreach ($checks as [$key, $limit]) {
                $r = $limiter->checkWithDetails($key, $scope, $limit, 3600);
                if (empty($r['allowed'])) {
                    return $this->error('جرّبت كتير الساعة دي - استنى شوية أو كمّل التسجيل وادخل بياناتك.', 429, ['retry_after' => (int) ($r['reset_in'] ?? 600)]);
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    private function url(bool $fetchable = true): ?string
    {
        $u = trim((string) $this->get('url'));
        if ($u === '' || mb_strlen($u) > 300) return null;
        if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
        $host = (string) parse_url($u, PHP_URL_HOST);
        if (!preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $host)) return null;
        // فحص SSRF للطلبات اللي السيرفر هيفتح فيها الرابط بس - الحفظ مجرد تخزين
        if ($fetchable && class_exists('SsrfGuard') && !SsrfGuard::isSafe($u)) return null;
        return 'https://' . strtolower($host) . rtrim((string) parse_url($u, PHP_URL_PATH), '/');
    }

    /** POST /api/public/smart/analyze {url} */
    public function analyze(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) return $e;
        $url = $this->url();
        if (!$url) return $this->error('اكتب رابط موقع صحيح، مثلًا yourcompany.com', 422);
        // نفس الموقع اتحلل بنجاح قريب: نفس النتيجة فورًا (ثابتة، ومن غير ذكاء اصطناعي ولا استهلاك من حد المحاولات)
        $cacheKey = 'smart_profile:' . SmartOnboardingService::PROFILE_VERSION . ':' . md5(SmartOnboardingService::host($url));
        $cache = class_exists('Cache') ? new Cache() : null;
        $cached = null;
        try { $cached = $cache ? $cache->get($cacheKey) : null; } catch (Throwable $e) { error_log('[SmartSignup] cache read failed: ' . $e->getMessage()); }
        if (is_array($cached) && !empty($cached['ok'])) return $this->success($cached + ['url' => $url, 'cached' => true]);
        if ($e = $this->limited('smart_analyze', 10)) return $e;
        // التحليل ممكن ياخد لحد دقيقة ونص (قراية الموقع + الذكاء الاصطناعي + إعادة الكتابة بلغة الموقع)
        if (function_exists('set_time_limit')) @set_time_limit(110);
        try {
            $r = (new SmartOnboardingService())->analyze($url, 0);
            if (class_exists('ActivityLog')) ActivityLog::record('marketing', $r['ok'] ? 'signup.smart_analyzed' : 'signup.smart_failed', ['meta' => ['host' => SmartOnboardingService::host($url)]]);
            if ($r['ok'] && $cache) { try { $cache->set($cacheKey, $r, 7 * 86400); } catch (Throwable $e) { error_log('[SmartSignup] cache write failed: ' . $e->getMessage()); } }
            if (!$r['ok'] && class_exists('Logger')) Logger::warning('Smart onboarding analyze failed', ['host' => SmartOnboardingService::host($url), 'reason' => $r['reason'] ?? '']);
            return $this->success($r + ['url' => $url]);
        } catch (Throwable $e) {
            Logger::error('Smart signup analyze failed', ['error' => $e->getMessage()]);
            return $this->success(['ok' => false, 'reason' => 'error', 'url' => $url]);
        }
    }

    /** POST /api/public/smart/competitors {url, queries[], candidates[], language} */
    public function competitors(array $params = []): array
    {
        if (($e = $this->verifyCsrf()) || ($e = $this->limited('smart_competitors', 6))) return $e;
        $url = $this->url();
        if (!$url) return $this->error('رابط غير صحيح', 422);
        $list = static fn ($v, int $n, int $len) => array_slice(array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, $len), is_array($v) ? $v : []), 'strlen')), 0, $n);
        try {
            return $this->success((new SmartOnboardingService())->competitors($url, $list($this->get('queries'), 3, 80), $list($this->get('candidates'), 12, 120), substr((string) $this->get('language', 'en'), 0, 2), 0, array_values(array_filter(array_map(fn ($c) => strtoupper(substr(trim((string) $c), 0, 2)), array_slice(is_array($this->get('countries')) ? $this->get('countries') : [], 0, 5)), fn ($c) => (bool) preg_match('/^[A-Z]{2}$/', $c)))));
        } catch (Throwable $e) {
            Logger::error('Smart signup competitors failed', ['error' => $e->getMessage()]);
            return $this->success(['source' => 'none', 'competitors' => []]);
        }
    }

    /** POST /api/public/smart/save {payload} - البيانات بعد المراجعة → الجلسة (تتستخدم بعد التسجيل) */
    public function save(array $params = []): array
    {
        if (($e = $this->verifyCsrf()) || ($e = $this->limited('smart_save', 30))) return $e;
        $p = $this->get('payload');
        if (!is_array($p)) return $this->error('بيانات ناقصة', 422);
        $s = static fn ($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $comps = [];
        foreach (array_slice(is_array($p['competitors'] ?? null) ? $p['competitors'] : [], 0, 3) as $c) {
            if (!is_array($c)) continue;
            $d = SmartOnboardingService::host((string) ($c['domain'] ?? ''));
            if ($d !== '') $comps[] = ['name' => $s($c['name'] ?? $d, 80), 'domain' => $d];
        }
        $url = $this->url(false);
        $_SESSION['smart_onboarding'] = [
            'business_name' => $s($p['business_name'] ?? '', 120),
            'main_url' => $url ?? '',
            'industry' => in_array($p['industry'] ?? '', ['tours', 'hotel', 'travel_agency', 'other'], true) ? $p['industry'] : 'tours',
            'target_country' => implode(',', array_slice(array_filter(array_map(fn ($c) => preg_match('/^[A-Z]{2}$/', strtoupper(trim($c))) ? strtoupper(trim($c)) : '', explode(',', (string) ($p['target_country'] ?? '')))), 0, 10)),
            'target_language' => preg_match('/^[a-z]{2}$/', (string) ($p['target_language'] ?? '')) ? $p['target_language'] : 'en',
            'selling_currency' => preg_match('/^[A-Z]{3}$/', (string) ($p['selling_currency'] ?? '')) ? $p['selling_currency'] : 'USD',
            'target_customers' => $s($p['target_customers'] ?? '', 500),
            'main_services' => $s($p['main_services'] ?? '', 2000),
            'competitors' => $comps,
            'growth_plan' => GrowthPlanService::sanitize($p['growth_plan'] ?? null),
            'saved_at' => time(),
        ];
        if ($url) {
            $_SESSION['signup_site'] = SmartOnboardingService::host($url);
        }
        return $this->success(['saved' => true]);
    }

    /** POST /api/public/smart/titles {profile, keywords[], answers} - عناوين أول مقالات لخطة النمو */
    public function titles(array $params = []): array
    {
        if (($e = $this->verifyCsrf()) || ($e = $this->limited('smart_titles', 8))) return $e;
        $p = is_array($this->get('profile')) ? $this->get('profile') : [];
        $profile = [
            'business_name' => mb_substr((string) ($p['business_name'] ?? ''), 0, 120), 'description' => mb_substr((string) ($p['description'] ?? ''), 0, 400),
            'services' => array_slice(array_map(fn ($x) => mb_substr((string) $x, 0, 60), is_array($p['services'] ?? null) ? $p['services'] : []), 0, 8),
            'site_language' => (string) ($p['site_language'] ?? 'en'), 'location' => mb_substr((string) ($p['location'] ?? ''), 0, 80),
        ];
        $plan = GrowthPlanService::sanitize(['answers' => $this->get('answers'), 'keywords' => $this->get('keywords')]);
        $titles = GrowthPlanService::suggestTitles($profile, $plan['keywords'] ?? [], $plan['answers'] ?? [], (int) ($this->user['id'] ?? 0));
        $trial = class_exists('PlanExtras') ? (int) (PlanExtras::trialCaps()['article_generation'] ?? 2) : 2;
        return $this->success(['articles' => $titles, 'trial_articles' => $trial]);
    }

    /** بيانات التسجيل الذكي (لو لسه صالحة) - الـWizard بيستهلكها مرة واحدة */
    public static function take(): ?array
    {
        $d = $_SESSION['smart_onboarding'] ?? null;
        if (!is_array($d) || (time() - (int) ($d['saved_at'] ?? 0)) > 86400 || ($d['main_url'] ?? '') === '' || ($d['business_name'] ?? '') === '') {
            return null;
        }
        unset($_SESSION['smart_onboarding']);
        unset($d['saved_at']);
        return $d;
    }
}
