<?php

/**
 * Tourfecto - Website Controller
 * إدارة مواقع المستخدم (CRUD) + التحقق الحقيقي من الملكية
 * @version 1.1.0
 *
 * تصحيح (2026-07-12): كان verify() بيوافق على أي طلب فورًا من غير أي فحص
 * حقيقي (rubber-stamp) — أي حد يقدر يدّعي ملكية أي موقع. دلوقتي بنعمل تحقق
 * فعلي بطريقتين قياسيتين (زي Google Search Console بالظبط):
 *   1) meta tag في <head> الموقع المستهدف.
 *   2) سجل DNS TXT على الدومين.
 * التوكن نفسه HMAC deterministic (مش عمود جديد في قاعدة البيانات) عشان منحتاجش
 * migration على قاعدة بيانات حية معندناش وصول ليها مباشر.
 */

class WebsiteController extends Controller
{
    private function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * يوحّد الرابط (يضيف https لو ناقص، يشيل المسافات) ويتأكد إنه موقع عام حقيقي.
     * @return array{0:string,1:?string} [الرابط، رسالة الخطأ أو null]
     */
    private function normalizePublicUrl(string $raw, bool $checkReach = true): array
    {
        $u = trim($raw);
        if ($u === '' || mb_strlen($u) > 500) {
            return ['', 'اكتب رابط الموقع'];
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) {
            $u = 'https://' . $u;
        }
        $p = parse_url($u);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
            return ['', 'الرابط مش صحيح - اكتبه كده: https://example.com'];
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && (!str_contains($host, '.') || !preg_match('/^[a-z0-9.-]+$/', idn_to_ascii($host) ?: $host))) {
            return ['', 'الدومين مش صحيح - اكتبه كده: https://example.com'];
        }
        if ($checkReach && class_exists('SafeHttp')) {
            $chk = SafeHttp::check($u);
            if (empty($chk['ok'])) {
                return ['', 'مش قادرين نوصل للموقع ده: ' . ($chk['error'] ?? '')];
            }
        }
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
        return [$scheme . '://' . $host . $port . $path, null];
    }

    /** نفس اللي فوق بس الحقل اختياري (فاضي = null) */
    private function normalizeOptionalUrl(string $raw): array
    {
        if (trim($raw) === '') {
            return [null, null];
        }
        return $this->normalizePublicUrl($raw);
    }

    private static function hostKey(string $url): string
    {
        $h = strtolower((string) parse_url($url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $h);
    }

    /** id الموقع المكرر عند نفس الحساب (نفس الدومين، مع أو من غير www) */
    private function findDuplicateWebsite(int $userId, string $url): ?int
    {
        $key = self::hostKey($url);
        $rows = $this->db()->query('SELECT id, main_url FROM websites WHERE user_id = ?', [$userId]);
        foreach ($rows as $r) {
            if (self::hostKey((string) $r['main_url']) === $key) {
                return (int) $r['id'];
            }
        }
        return null;
    }

    private function countWebsites(int $userId): int
    {
        $r = $this->db()->query('SELECT COUNT(*) AS c FROM websites WHERE user_id = ?', [$userId]);
        return (int) ($r[0]['c'] ?? 0);
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * عدد المواقع المسموح في الباقة: features_json.websites لو موجود (true = مفتوح)،
     * وإلا افتراضي حسب الباقة. من غير اشتراك نشط: موقع واحد.
     */
    public static function websitesLimit(int $userId): int
    {
        $defaults = ['starter' => 2, 'professional' => 5, 'enterprise' => 25];
        try {
            if (!class_exists('Subscription')) {
                return -1;
            }
            $sub = Subscription::activeSubscriptionRow($userId);
            if (!$sub) {
                return 1;
            }
            $features = json_decode((string) ($sub['features_json'] ?? ''), true) ?: [];
            if (!$features && !empty($sub['plan_id'])) {
                $r = Database::getInstance()->query('SELECT features_json FROM subscription_plans WHERE id = ? LIMIT 1', [(int) $sub['plan_id']]);
                $features = json_decode((string) ($r[0]['features_json'] ?? ''), true) ?: [];
            }
            if (array_key_exists('websites', $features)) {
                return $features['websites'] === true ? -1 : max(1, (int) $features['websites']);
            }
            $tier = strtolower((string) ($sub['plan_name'] ?? ''));
            foreach ($defaults as $k => $v) {
                if (str_contains($tier, $k)) {
                    return $v;
                }
            }
            return $defaults['starter'];
        } catch (Throwable $e) {
            return -1;
        }
    }

    /**
     * هل الطلب الحالي API (JSON) ولا صفحة ويب عادية؟
     * نفس فكرة AuthMiddleware::isWebPageRequest لكن هنا بنستخدمها عشان
     * index()/show() يقدروا يخدموا الاتنين (الصفحة و الـ API) من غير تكرار.
     */
    private function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return strpos($path, '/api/') === 0;
    }

    // ============================================
    // توليد والتحقق من توكن الملكية
    // ============================================

    private function verificationSecret(): string
    {
        return (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-verification-secret';
    }

    private function verificationToken(int $userId, int $websiteId): string
    {
        return substr(hash_hmac('sha256', 'tourfecto-site-verify:' . $userId . ':' . $websiteId, $this->verificationSecret()), 0, 32);
    }

    private function normalizeUrl(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    private function verificationInstructions(Website $website): array
    {
        $userId = (int) $website->getAttribute('user_id');
        $id = (int) $website->getAttribute('id');
        $token = $this->verificationToken($userId, $id);
        $url = $this->normalizeUrl((string) $website->getAttribute('main_url'));
        $host = parse_url($url, PHP_URL_HOST) ?: $website->getAttribute('main_url');

        return [
            'token' => $token,
            'meta_tag' => '<meta name="tourfecto-verification" content="' . $token . '">',
            'dns_record' => [
                'type' => 'TXT',
                'host' => $host,
                'value' => 'tourfecto-verify=' . $token,
            ],
            // طريقة الملف: ملف نصي في المجلد الرئيسي محتواه التوكن بالظبط. مش بيتأثر بالكاش، ومناسب لـ WordPress.
            'file' => [
                'name' => 'tourfecto-verification-' . $token . '.txt',
                'url' => $this->originOf($url) . '/tourfecto-verification-' . $token . '.txt',
                'content' => $token,
            ],
        ];
    }

    /**
     * حماية SSRF: امنع الفحص على IP خاص/داخلي/loopback عشان محدش يستخدم
     * فورم "أضف موقع" عشان يخلي السيرفر يعمل طلبات لشبكته الداخلية.
     */
    protected function isPubliclyRoutableHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $resolved = gethostbyname($host);
            // gethostbyname() بترجع الـ host نفسه لو فشل الحل، فده يبقى معناه فشل
            if ($resolved === $host) {
                return false;
            }
            $ip = $resolved;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    // ============================================
    // التحقق من ملكية الموقع (3 طرق: ملف / meta tag / DNS TXT)
    // ============================================

    private function originOf(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $port = parse_url($url, PHP_URL_PORT);
        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    protected function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * طلب GET واحد لأغراض التحقق، مع حماية SSRF (الهدف الأصلي + أي redirect لازم يبقى IP عام).
     * بيقرا أول 300 كيلو بس. القطع المقصود (curl error 23) مش فشل: كان بيفشّل صفحات WordPress الكبيرة.
     *
     * @return array{http:int, body:?string, error:string}
     */
    protected function fetchForVerification(string $url, int $maxBytes = 300000): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !$this->isPubliclyRoutableHost($host)) {
            return ['http' => 0, 'body' => null, 'error' => 'host_not_public'];
        }

        try {
            $ch = curl_init();
            $downloaded = '';
            $truncated = false;
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'TourfectoVerificationBot/1.0 (+https://tourfecto.pro)',
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$downloaded, &$truncated, $maxBytes) {
                    $downloaded .= $chunk;
                    if (strlen($downloaded) > $maxBytes) {
                        $truncated = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch);
            $errMsg = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            curl_close($ch);

            // redirect إلى عنوان داخلي: نرفض (SSRF)
            if ($ip !== '' && !$this->isPublicIp($ip)) {
                return ['http' => $http, 'body' => null, 'error' => 'redirect_blocked'];
            }
            if ($errno && !($errno === 23 && $truncated)) {
                return ['http' => $http, 'body' => null, 'error' => $errMsg !== '' ? $errMsg : ('curl_' . $errno)];
            }
            return ['http' => $http, 'body' => $downloaded, 'error' => ''];
        } catch (Throwable $e) {
            Logger::warning('Website verification fetch failed', ['url' => $url, 'error' => CustomerError::message($e->getMessage(), 500)]);
            return ['http' => 0, 'body' => null, 'error' => 'exception'];
        }
    }

    /** يدوّر على <meta name="tourfecto-verification" content="TOKEN"> بأي ترتيب للخصائص وأي نوع علامات اقتباس */
    private function htmlHasVerificationMeta(string $html, string $token): bool
    {
        if (!preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
            return false;
        }
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\bname\s*=\s*(["\'])tourfecto-verification\1/i', $tag)) {
                continue;
            }
            if (preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $c) && hash_equals(strtolower($token), strtolower(trim($c[2])))) {
                return true;
            }
        }
        return false;
    }

    private function fetchFailureReason(array $r): string
    {
        return in_array($r['error'], ['redirect_blocked', 'host_not_public'], true) ? $r['error'] : 'fetch_failed';
    }

    /** @return array{ok:bool, reason:string, http:int} */
    protected function checkMetaTag(string $url, string $token): array
    {
        $r = $this->fetchForVerification($url);
        if ($r['body'] === null) {
            return ['ok' => false, 'reason' => $this->fetchFailureReason($r), 'http' => $r['http']];
        }
        if ($r['http'] >= 400) {
            return ['ok' => false, 'reason' => 'fetch_blocked', 'http' => $r['http']];
        }
        return $this->htmlHasVerificationMeta($r['body'], $token)
            ? ['ok' => true, 'reason' => '', 'http' => $r['http']]
            : ['ok' => false, 'reason' => 'tag_not_found', 'http' => $r['http']];
    }

    /**
     * ملف التحقق: لازم 200 والمحتوى هو التوكن بالظبط (بعد trim). ما بنقبلش "يحتوي على" عشان صفحات 404 اللي
     * بتعرض الرابط جواها كانت هتتحسب تحقق خاطئ.
     * @return array{ok:bool, reason:string, http:int}
     */
    protected function checkVerificationFile(string $origin, string $token): array
    {
        $r = $this->fetchForVerification($origin . '/tourfecto-verification-' . $token . '.txt', 4096);
        if ($r['body'] === null) {
            return ['ok' => false, 'reason' => $this->fetchFailureReason($r), 'http' => $r['http']];
        }
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', $r['body']));
        if ($r['http'] === 200 && strlen($body) <= 200 && hash_equals(strtolower($token), strtolower($body))) {
            return ['ok' => true, 'reason' => '', 'http' => 200];
        }
        return ['ok' => false, 'reason' => 'file_not_found', 'http' => $r['http']];
    }

    protected function dnsTxtRecords(string $host): array
    {
        $r = @dns_get_record($host, DNS_TXT);
        return is_array($r) ? $r : [];
    }

    /**
     * سجل TXT على الـ host، أو على الدومين الرئيسي لو الرابط www (صاحب الدومين الرئيسي بيتحكم في www).
     * @return array{ok:bool, reason:string, http:int}
     */
    protected function checkDnsTxt(string $host, string $token): array
    {
        $hosts = [strtolower($host)];
        if (stripos($host, 'www.') === 0) {
            $hosts[] = strtolower(substr($host, 4));
        }
        $needle = strtolower('tourfecto-verify=' . $token);
        $anyPublic = false;
        foreach (array_unique($hosts) as $h) {
            if (!$this->isPubliclyRoutableHost($h)) {
                continue;
            }
            $anyPublic = true;
            try {
                foreach ($this->dnsTxtRecords($h) as $rec) {
                    $values = [];
                    if (isset($rec['txt'])) {
                        $values[] = $rec['txt'];
                    }
                    if (isset($rec['entries']) && is_array($rec['entries'])) {
                        $values[] = implode('', $rec['entries']);   // TXT طويل بيتقسّم لأجزاء
                        foreach ($rec['entries'] as $e) {
                            $values[] = $e;
                        }
                    }
                    foreach ($values as $v) {
                        if (strtolower(trim((string) $v, " \t\n\r\0\x0B\"'")) === $needle) {
                            return ['ok' => true, 'reason' => '', 'http' => 0];
                        }
                    }
                }
            } catch (Throwable $e) {
                Logger::warning('Website DNS-TXT verification failed', ['host' => $h, 'error' => CustomerError::message($e->getMessage(), 500)]);
            }
        }
        return ['ok' => false, 'reason' => $anyPublic ? 'dns_not_found' : 'host_not_public', 'http' => 0];
    }

    /**
     * بيجرّب الطرق الثلاث بالترتيب (ملف ← meta ← DNS) ويرجّع أول نجاح، أو تفاصيل كل فشل.
     * @return array{verified:bool, method:?string, checks:array}
     */
    protected function runVerificationChecks(string $mainUrl, string $host, string $token): array
    {
        $checks = [];
        $order = [
            'file' => function () use ($mainUrl, $token) { return $this->checkVerificationFile($this->originOf($mainUrl), $token); },
            'meta_tag' => function () use ($mainUrl, $token) { return $this->checkMetaTag($mainUrl, $token); },
            'dns_txt' => function () use ($host, $token) { return $this->checkDnsTxt($host, $token); },
        ];
        foreach ($order as $method => $fn) {
            $res = $fn();
            $checks[$method] = $res;
            if (!empty($res['ok'])) {
                return ['verified' => true, 'method' => $method, 'checks' => $checks];
            }
        }
        return ['verified' => false, 'method' => null, 'checks' => $checks];
    }

    /** رسالة واضحة بسبب فشل كل طريقة (بدل رسالة واحدة عامة) */
    protected function verificationFailureMessage(array $checks): string
    {
        $map = [
            'fetch_blocked' => 'websites.verify_reason_blocked',
            'fetch_failed' => 'websites.verify_reason_fetch',
            'redirect_blocked' => 'websites.verify_reason_fetch',
            'host_not_public' => 'websites.verify_reason_fetch',
            'tag_not_found' => 'websites.verify_reason_tag',
            'file_not_found' => 'websites.verify_reason_file',
            'dns_not_found' => 'websites.verify_reason_dns',
        ];
        $lines = [$this->tr('websites.verify_fail_intro')];
        $seen = [];
        foreach (['file', 'meta_tag', 'dns_txt'] as $m) {
            $reason = $checks[$m]['reason'] ?? '';
            $key = $map[$reason] ?? null;
            if ($key && !isset($seen[$key])) {
                $seen[$key] = true;
                $code = (int) ($checks[$m]['http'] ?? 0);
                $lines[] = '• ' . str_replace('{code}', $code > 0 ? (string) $code : '—', $this->tr($key));
            }
        }
        return implode("\n", $lines);
    }

    // ============================================
    // GET /websites و GET /api/websites
    // ============================================

    public function index(array $params = []): array
    {
        $userId = $this->userId();
        if (!$userId) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/websites'));
            exit;
        }

        $model = new Website();
        $sites = $model->where(['user_id' => $userId], ['created_at' => 'DESC']);

        // تصحيح: where() بترجع كائنات Website (Model)، والكلاس ده مالوش أي
        // public properties ومش عامل implements JsonSerializable، فلو اتبعت
        // زي ما هي في JSON response كانت هتتحول لـ {} فاضية بدل بيانات الموقع
        // الفعلية. لازم نعمل toArray() على كل واحد قبل الإرجاع.
        $sitesArray = array_map(function ($site) {
            $data = $site->toArray();
            $data['is_verified'] = (int) ($data['is_verified'] ?? 0);
            return $data;
        }, $sites);

        if ($this->isApiRequest()) {
            return $this->success(['websites' => $sitesArray]);
        }

        $this->renderIndexPage($sitesArray);
        exit;
    }

    private function renderIndexPage(array $sites): void
    {
        $tAddNew = $this->tr('websites.add_new');
        $tUrlLabel = $this->tr('websites.url_label');
        $tCompanyLabel = $this->tr('websites.company_label');
        $tAddButton = $this->tr('websites.add_button');
        $tYourSites = $this->tr('websites.your_sites');
        $tColSite = $this->tr('websites.col.site');
        $tColCompany = $this->tr('websites.col.company');
        $tColStatus = $this->tr('websites.col.status');
        $tColDate = $this->tr('websites.col.date');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>+ {$tAddNew}</h3></div>
            <form id="addWebsiteForm">
                <div class="p-grid cols-2">
                    <div class="form-group">
                        <label class="form-label" for="main_url">{$tUrlLabel} *</label>
                        <input type="text" id="main_url" class="form-control" placeholder="https://example.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="company_name">{$tCompanyLabel}</label>
                        <input type="text" id="company_name" class="form-control">
                    </div>
                </div>
                <div id="addWebsiteAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="submit" class="p-btn primary">{$tAddButton}</button>
            </form>
        </div>
        <div class="p-card no-pad" style="margin-top:16px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$tYourSites}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="websitesTable">
                <thead><tr><th>{$tColSite}</th><th>{$tColCompany}</th><th>{$tColStatus}</th><th>{$tColDate}</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
        </div>
HTML;

        $sitesJson = json_encode($sites, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $tNoSites = $this->trJs('websites.no_sites');
        $tVerified = $this->trJs('websites.verified');
        $tUnverified = $this->trJs('websites.unverified');
        $tManage = $this->trJs('websites.manage');
        $tAddFailed = $this->trJs('websites.add_failed');
        $tAddedSuccess = $this->trJs('websites.added_success');

        $script = <<<JS
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let sites = __SITES_JSON__;

    function renderRows() {
        const tbody = document.querySelector('#websitesTable tbody');
        if (!sites.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-cell-muted text-center">' + {$tNoSites} + '</td></tr>';
            return;
        }
        tbody.innerHTML = sites.map(w => `
            <tr>
                <td>\${esc(w.main_url || '-')}</td>
                <td>\${esc(w.company_name || '-')}</td>
                <td>\${w.is_verified == 1 ? '<span class="pill green">✔ ' + {$tVerified} + '</span>' : '<span class="pill">' + {$tUnverified} + '</span>'}</td>
                <td class="p-cell-muted">\${formatDate(w.created_at)}</td>
                <td><a href="/websites/\${w.id}" class="p-btn outline xs">{$tManage}</a></td>
            </tr>`).join('');
    }
    renderRows();

    document.getElementById('addWebsiteForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('addWebsiteAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/websites', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                main_url: document.getElementById('main_url').value.trim(),
                company_name: document.getElementById('company_name').value.trim(),
            }),
        });

        if (!res.success) {
            alertBox.textContent = res.error || {$tAddFailed};
            alertBox.style.display = 'block';
            return;
        }

        toast({$tAddedSuccess}, 'success');
        window.location.href = '/websites/' + res.data.website.id;
    });
})();
JS;
        $script = str_replace('__SITES_JSON__', $sitesJson, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', $this->tr('sidebar.websites'), $this->tr('websites.page_subtitle'), $body, $script);
    }

    /** GET /websites/create -> بدل صفحة منفصلة، الفورم بقى جزء من /websites */
    public function create(array $params = []): array
    {
        if ($this->isApiRequest()) {
            return $this->success(['form' => 'create_website']);
        }
        header('Location: /websites');
        exit;
    }

    /** POST /websites/store و POST /api/websites */
    public function store(array $params = []): array
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate(['main_url' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // 🔒 الرابط لازم يكون موقع عام حقيقي (مش عنوان داخلي)، ومايتكررش، وفي حدود الباقة
        [$mainUrl, $urlError] = $this->normalizePublicUrl((string) $this->get('main_url'), false);
        if ($urlError !== null) {
            return $this->error($urlError, 422, ['main_url' => [$urlError]]);
        }
        if ($this->findDuplicateWebsite((int) $userId, $mainUrl) !== null) {
            return $this->error('الموقع ده متضاف عندك قبل كده', 409, ['main_url' => ['الموقع ده متضاف عندك قبل كده']]);
        }
        $limit = self::websitesLimit((int) $userId);
        if ($limit >= 0 && $this->countWebsites((int) $userId) >= $limit) {
            return $this->error('وصلت للحد الأقصى للمواقع في باقتك (' . $limit . ') - رقّي الباقة أو امسح موقع قديم', 403, ['upgrade_url' => '/subscription']);
        }
        [, $urlError] = $this->normalizePublicUrl($mainUrl);
        if ($urlError !== null) {
            return $this->error($urlError, 422, ['main_url' => [$urlError]]);
        }
        $competitors = [];
        foreach (['competitor_1_url', 'competitor_2_url', 'competitor_3_url'] as $f) {
            [$u, $e] = $this->normalizeOptionalUrl((string) $this->get($f, ''));
            if ($e !== null) {
                return $this->error('رابط المنافس: ' . $e, 422, [$f => [$e]]);
            }
            $competitors[$f] = $u;
        }

        try {
            $website = new Website([
                'user_id' => $userId,
                'main_url' => $mainUrl,
                'company_name' => $this->get('company_name'),
                'industry' => $this->get('industry', 'tourism'),
                'target_language' => $this->get('target_language', 'en'),
                'target_country' => $this->get('target_country'),
                'meta_description' => $this->get('meta_description'),
                'competitor_1_url' => $competitors['competitor_1_url'],
                'competitor_2_url' => $competitors['competitor_2_url'],
                'competitor_3_url' => $competitors['competitor_3_url'],
                'is_verified' => 0,
            ]);

            $id = $website->save();
            if (!$id) {
                return $this->error('تعذر إضافة الموقع', 500);
            }

            $saved = $website->find($id);

            return $this->success([
                'website' => $saved->toArray(),
                'verification' => $this->verificationInstructions($saved),
            ], 'تم إضافة الموقع بنجاح، وتفعيله محتاج تأكيد الملكية', 201);

        } catch (Exception $e) {
            Logger::error('Website Store Error', ['message' => $e->getMessage()]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'تعذر إضافة الموقع: ' . $e->getMessage()
                : 'تعذر إضافة الموقع';
            return $this->error($debugMsg, 500);
        }
    }

    // ============================================
    // GET /websites/{id} و GET /api/websites/{id}
    // ============================================

    public function show(array $params): array
    {
        $userId = $this->userId();
        if (!$userId) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/websites/' . ($params['id'] ?? '')));
            exit;
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            if ($this->isApiRequest()) {
                return $this->error('الموقع غير موجود', 404);
            }
            header('Content-Type: text/html; charset=utf-8');
            $tNotFoundTitle = $this->tr('websites.not_found_title');
            $tNotFoundBody = $this->tr('websites.not_found_body');
            $tBackToSites = $this->tr('websites.back_to_sites');
            echo $this->renderPanelPage('_websites', $tNotFoundTitle, '', '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🌐</div>' . $tNotFoundBody . ' <a href="/websites">' . $tBackToSites . '</a></div></div>', '');
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success([
                'website' => $website->toArray(),
                'verification' => $this->verificationInstructions($website),
            ]);
        }

        $this->renderShowPage($website);
        exit;
    }

    private function renderShowPage(Website $website): void
    {
        $id = (int) $website->getAttribute('id');
        $mainUrl = htmlspecialchars((string) $website->getAttribute('main_url'), ENT_QUOTES, 'UTF-8');
        $isVerified = (int) $website->getAttribute('is_verified') === 1;
        $instructions = $this->verificationInstructions($website);
        $metaTagSafe = htmlspecialchars($instructions['meta_tag'], ENT_QUOTES, 'UTF-8');
        $dnsHostSafe = htmlspecialchars($instructions['dns_record']['host'], ENT_QUOTES, 'UTF-8');
        $dnsValueSafe = htmlspecialchars($instructions['dns_record']['value'], ENT_QUOTES, 'UTF-8');
        $fileNameSafe = htmlspecialchars($instructions['file']['name'], ENT_QUOTES, 'UTF-8');
        $fileContentSafe = htmlspecialchars($instructions['file']['content'], ENT_QUOTES, 'UTF-8');
        $fileUrlSafe = htmlspecialchars($instructions['file']['url'], ENT_QUOTES, 'UTF-8');
        $tFileTitle = $this->tr('websites.file_title');
        $tFileDesc = $this->tr('websites.file_desc');
        $wpSnippetSafe = htmlspecialchars(
            "add_action('wp_head', function () {\n    echo '" . $instructions['meta_tag'] . "' . \"\\n\";\n});",
            ENT_QUOTES,
            'UTF-8'
        );
        $codeBox = 'display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;word-break:break-all;user-select:all;';
        $wpGuideHtml = '<details class="p-card" style="margin-top:12px;"><summary style="cursor:pointer;font-weight:600;">' . $this->tr('websites.wp_guide_title') . '</summary>'
            . '<div style="margin-top:10px;line-height:1.9;">'
            . '<p><strong>' . $this->tr('websites.wp_guide_intro') . '</strong></p>'
            . '<ol style="padding-inline-start:20px;margin:0;">'
            . '<li>' . $this->tr('websites.wp_step_file') . '</li>'
            . '<li>' . $this->tr('websites.wp_step_dns') . '</li>'
            . '<li>' . $this->tr('websites.wp_step_meta') . '</li>'
            . '</ol>'
            . '<p style="margin:12px 0 6px;">' . $this->tr('websites.wp_step_snippet') . '</p>'
            . '<pre style="' . $codeBox . 'margin:0;white-space:pre-wrap;">' . $wpSnippetSafe . '</pre>'
            . '<p style="margin-top:12px;">' . $this->tr('websites.wp_step_cache') . '</p>'
            . '<p>' . $this->tr('websites.wp_step_blocked') . '</p>'
            . '<p><a href="/integrations">' . $this->tr('websites.wp_after_verify') . '</a></p>'
            . '</div></details>';

        $tVerifiedMsg = $this->tr('websites.verified_msg');
        $tNotVerifiedWarning = $this->tr('websites.not_verified_warning');
        $tChooseMethod = $this->tr('websites.choose_method');
        $tMetaTagTitle = $this->tr('websites.meta_tag_title');
        $tMetaTagDesc = $this->tr('websites.meta_tag_desc');
        $tDnsTitle = $this->tr('websites.dns_title');
        $tDnsDesc = $this->tr('websites.dns_desc');
        $tVerifyNow = $this->tr('websites.verify_now');
        $tManualSummary = $this->tr('sc.alt_title');

        $scKeys = ['sc.title', 'sc.step1', 'sc.step2', 'sc.step3', 'sc.detecting', 'sc.wp_detected', 'sc.not_wp', 'sc.not_wp_hint', 'sc.btn_force_wp',
            'sc.oneclick_title', 'sc.oneclick_desc', 'sc.btn_oneclick', 'sc.oneclick_unavailable', 'sc.manual_title', 'sc.field_user', 'sc.field_pass',
            'sc.field_pass_hint', 'sc.btn_connect', 'sc.connecting', 'sc.howto_title', 'sc.howto_1', 'sc.howto_2', 'sc.howto_3', 'sc.howto_4', 'sc.howto_5',
            'sc.howto_missing', 'sc.trouble_title', 'sc.trouble_body', 'sc.safe_note', 'sc.verified_title', 'sc.connected_title', 'sc.connected_as',
            'sc.btn_test', 'sc.btn_disconnect', 'sc.disconnect_confirm', 'sc.test_ok', 'sc.connect_wp_title', 'sc.connect_wp_desc', 'sc.result_ok',
            'sc.result_ok_unverified', 'sc.result_rejected', 'sc.copy', 'sc.copied', 'sc.err.bad_credentials', 'sc.err.auth_header_stripped',
            'sc.err.app_passwords_disabled', 'sc.err.rest_disabled', 'sc.err.not_wordpress', 'sc.err.not_https', 'sc.err.host_mismatch', 'sc.err.host_not_public',
            'sc.err.network', 'sc.err.forbidden', 'sc.err.invalid_response', 'sc.err.state_invalid', 'sc.err.missing_fields', 'sc.err.redirect_other_host', 'sc.err.unknown',
            'sc.easy_title', 'sc.easy_desc', 'sc.easy_1', 'sc.easy_2', 'sc.easy_3', 'sc.easy_4', 'sc.easy_5', 'sc.btn_verify', 'sc.verifying',
            'sc.no_plugin', 'sc.verify_failed_title', 'sc.apdisabled_title', 'sc.apdisabled_body', 'sc.pub_title', 'sc.pub_desc', 'sc.pub_warn',
            'sc.btn_recheck', 'sc.advanced_title', 'sc.easy_alt_title', 'sc.err.wrong_password', 'sc.err.wrong_username', 'sc.err.unauthorized', 'sc.err.tech', 'sc.err.enable_with_snippet',
            'websites.verify_reason_blocked', 'websites.verify_reason_fetch', 'websites.verify_reason_tag', 'websites.verify_reason_file', 'websites.verify_reason_dns', 'websites.verified_msg'];
        $scI18n = [];
        foreach ($scKeys as $k) {
            $scI18n[$k] = t($k);
        }
        // كود جاهز يتلصق في Code Snippets / WPCode (من غير <?php): بيطبع وسم التحقق في <head>
        $scSnippet = "add_action( 'wp_head', function () {\n    echo '" . $instructions['meta_tag'] . "' . \"\\n\";\n}, 1 );";
        // اختياري للنشر: يفعّل Application Passwords لو مخبّية (إضافة أمان أو WordPress ورا CDN مش شايف HTTPS)
        $scAppSnippet = "add_filter( 'wp_is_application_passwords_available', '__return_true', 999 );\nadd_filter( 'wp_is_application_passwords_available_for_user', '__return_true', 999 );";
        $scConfigJson = json_encode(
            ['id' => $id, 'verified' => $isVerified, 'url' => (string) $website->getAttribute('main_url'), 'i18n' => $scI18n,
                'snippet' => $scSnippet, 'appSnippet' => $scAppSnippet, 'meta' => $instructions['meta_tag']],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $notVerifiedAlert = $isVerified ? '' : '<div class="alert alert-warning">⚠️ ' . $tNotVerifiedWarning . '</div>';
        $verifyBlock = $isVerified
            ? '<div class="alert alert-success">✔ ' . $tVerifiedMsg . '</div>'
            : <<<HTML
                <details class="sc-manual" id="scManual"><summary>{$tManualSummary}</summary>
                <p class="p-cell-muted">{$tChooseMethod}</p>
                <div class="p-grid cols-2">
                    <div class="p-card" style="background:var(--panel-bg,#f7f8fa);">
                        <div class="p-card-head"><h3>1) {$tMetaTagTitle}</h3></div>
                        <p class="p-cell-muted">{$tMetaTagDesc}</p>
                        <code style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;">{$metaTagSafe}</code>
                    </div>
                    <div class="p-card" style="background:var(--panel-bg,#f7f8fa);">
                        <div class="p-card-head"><h3>2) {$tDnsTitle}</h3></div>
                        <p class="p-cell-muted">{$tDnsDesc}</p>
<div style="margin:6px 0 2px;font-size:12px;opacity:.7;">Host</div><code style="{$codeBox}">{$dnsHostSafe}</code>
<div style="margin:10px 0 2px;font-size:12px;opacity:.7;">Value</div><code style="{$codeBox}">{$dnsValueSafe}</code>
                    </div>
                </div>
                <div class="p-card" style="background:var(--panel-bg,#f7f8fa);">
<div class="p-card-head"><h3>3) {$tFileTitle}</h3></div>
<p style="margin:0 0 8px;">{$tFileDesc}</p>
<div style="margin:6px 0 2px;font-size:12px;opacity:.7;">File name</div><code style="{$codeBox}">{$fileNameSafe}</code>
<div style="margin:10px 0 2px;font-size:12px;opacity:.7;">File content</div><code style="{$codeBox}">{$fileContentSafe}</code>
<div style="margin:10px 0 2px;font-size:12px;opacity:.7;">URL</div><code style="{$codeBox}">{$fileUrlSafe}</code>
</div>
{$wpGuideHtml}
<button class="p-btn primary" id="verifyBtn" onclick="verifyNow()" style="margin-top:14px;">🔍 {$tVerifyNow}</button>
                <div id="verifyAlert" class="alert alert-danger" style="display:none;margin-top:10px;white-space:pre-line;"></div>
                </details>
HTML;

        $tSiteSettingsSub = $this->tr('websites.site_settings_sub');
        $tDefaultCompetitors = $this->tr('websites.default_competitors');
        $tDefaultCompetitorsSub = $this->tr('websites.default_competitors_sub');
        $tCompetitorLabel = $this->tr('websites.competitor_label');
        $tCompetitor1 = $tCompetitorLabel . ' 1';
        $tCompetitor2 = $tCompetitorLabel . ' 2';
        $tCompetitor3 = $tCompetitorLabel . ' 3';
        $tSaveCompetitors = $this->tr('websites.save_competitors');
        $tGscSub = $this->tr('websites.gsc_sub');
        $tLoading = $this->tr('common.loading');
        $tDeleteWebsite = $this->tr('websites.delete_website');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>{$mainUrl}</h3><span class="p-card-sub">{$tSiteSettingsSub}</span></div>
            {$notVerifiedAlert}
            <div id="siteConnect"></div>
            {$verifyBlock}
        </div>
        <script>window.SC_CFG = {$scConfigJson};</script>
        <script src="/assets/js/site-connect.js"></script>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>{$tDefaultCompetitors}</h3><span class="p-card-sub">{$tDefaultCompetitorsSub}</span></div>
            <div class="form-group"><input type="url" id="c1" class="form-control" placeholder="{$tCompetitor1}" style="margin-bottom:8px;"></div>
            <div class="form-group"><input type="url" id="c2" class="form-control" placeholder="{$tCompetitor2}" style="margin-bottom:8px;"></div>
            <div class="form-group"><input type="url" id="c3" class="form-control" placeholder="{$tCompetitor3}"></div>
            <button class="p-btn outline" onclick="saveCompetitors()" style="margin-top:10px;">{$tSaveCompetitors}</button>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>🔍 Google Search Console</h3><span class="p-card-sub">{$tGscSub}</span></div>
            <div id="gscContent"><div class="p-loading-row">{$tLoading}</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <button class="p-btn danger" onclick="deleteWebsite()">🗑 {$tDeleteWebsite}</button>
        </div>
HTML;

        $tSavedMsg = $this->trJs('common.saved');
        $tSaveFailedMsg = $this->trJs('common.save_failed');
        $tDeleteConfirm = $this->trJs('websites.delete_confirm');
        $tDeletedMsg = $this->trJs('common.deleted');
        $tDeleteFailedMsg = $this->trJs('common.delete_failed');
        $tVerifying = $this->trJs('websites.verifying');
        $tVerifiedSuccess = $this->trJs('websites.verified_success');
        $tVerifyNowBtn = $this->trJs('websites.verify_now_btn');
        $tVerifyFailed = $this->trJs('websites.verify_failed');
        $tNotConnectedGsc = $this->trJs('websites.not_connected_gsc');
        $tConnectAccount = $this->trJs('websites.connect_account');
        $tClicks = $this->trJs('websites.clicks');
        $tImpressions = $this->trJs('websites.impressions');
        $tAvgPosition = $this->trJs('websites.avg_position');
        $tSearchQuery = $this->trJs('websites.search_query');
        $tRank = $this->trJs('websites.rank');
        $tNoDataYet = $this->trJs('websites.no_data_yet');
        $tDisconnectGsc = $this->trJs('websites.disconnect_gsc');
        $tDisconnectGscConfirm = $this->trJs('websites.disconnect_gsc_confirm');
        $tDisconnected = $this->trJs('common.disconnected');
        $tDisconnectFailed = $this->trJs('common.disconnect_failed');

        $script = <<<JS
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const websiteId = __WEBSITE_ID__;

    async function loadCompetitors() {
        const res = await fetchJSON('/api/websites/' + websiteId);
        if (res.success) {
            const w = res.data.website;
            document.getElementById('c1').value = w.competitor_1_url || '';
            document.getElementById('c2').value = w.competitor_2_url || '';
            document.getElementById('c3').value = w.competitor_3_url || '';
        }
    }

    window.saveCompetitors = async function () {
        const res = await fetchJSON('/api/websites/' + websiteId + '/competitors', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                competitor_1_url: document.getElementById('c1').value.trim(),
                competitor_2_url: document.getElementById('c2').value.trim(),
                competitor_3_url: document.getElementById('c3').value.trim(),
            }),
        });
        if (res.success) toast({$tSavedMsg}, 'success');
        else toast(res.error || {$tSaveFailedMsg}, 'error');
    };

    window.deleteWebsite = async function () {
        if (!confirm({$tDeleteConfirm})) return;
        const res = await fetchJSON('/api/websites/' + websiteId, { method: 'DELETE' });
        if (res.success) { toast({$tDeletedMsg}, 'success'); window.location.href = '/websites'; }
        else toast(res.error || {$tDeleteFailedMsg}, 'error');
    };

    window.verifyNow = async function () {
        const btn = document.getElementById('verifyBtn');
        const alertBox = document.getElementById('verifyAlert');
        alertBox.style.display = 'none';
        btn.disabled = true;
        btn.textContent = {$tVerifying};

        const res = await fetchJSON('/api/websites/' + websiteId + '/verify', { method: 'POST' });

        if (res.success) {
            toast({$tVerifiedSuccess}, 'success');
            window.location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = {$tVerifyNowBtn};
            alertBox.textContent = res.error || {$tVerifyFailed};
            alertBox.style.display = 'block';
        }
    };

    async function loadSearchConsole() {
        const box = document.getElementById('gscContent');
        const res = await fetchJSON('/api/search-console/stats/' + websiteId);
        const L = {
            notConnected: {$tNotConnectedGsc}, connect: {$tConnectAccount},
            clicks: {$tClicks}, impressions: {$tImpressions}, avgPosition: {$tAvgPosition},
            searchQuery: {$tSearchQuery}, rank: {$tRank}, noData: {$tNoDataYet}, disconnect: {$tDisconnectGsc},
        };

        if (!res.success) {
            // لسه مش متربط (أو حصل خطأ) - نعرض زرار الربط
            box.innerHTML = `
                <p class="p-cell-muted">\${L.notConnected}</p>
                <a href="/search-console/connect/\${websiteId}" class="p-btn primary xs">🔗 \${L.connect}</a>`;
            return;
        }

        const d = res.data;
        const s = d.summary;
        const rows = (d.top_queries || []).map(q => `
            <tr>
                <td style="direction:ltr;text-align:left;">\${esc(q.query || '')}</td>
                <td>\${q.clicks}</td>
                <td>\${q.impressions}</td>
                <td>\${q.ctr}%</td>
                <td>\${q.position}</td>
            </tr>`).join('');

        box.innerHTML = `
            <div class="p-cell-muted" style="direction:ltr;text-align:left;margin-bottom:10px;">\${esc(d.site_url)}</div>
            <div class="p-grid cols-4" style="margin-bottom:14px;">
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.clicks}</div><div class="stat-label">\${L.clicks}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.impressions}</div><div class="stat-label">\${L.impressions}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.ctr}%</div><div class="stat-label">CTR</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">\${s.avg_position}</div><div class="stat-label">\${L.avgPosition}</div></div></div>
            </div>
            <div class="p-table-scroll">
            <table class="p-table" style="min-width:520px;">
                <thead><tr><th style="direction:ltr;text-align:left;">\${L.searchQuery}</th><th>\${L.clicks}</th><th>\${L.impressions}</th><th>CTR</th><th>\${L.rank}</th></tr></thead>
                <tbody>\${rows || '<tr><td colspan="5" class="p-cell-muted">' + {$tNoDataYet} + '</td></tr>'}</tbody>
            </table>
            </div>
            <button class="p-btn outline xs" style="margin-top:10px;" onclick="disconnectGSC()">\${L.disconnect}</button>`;
    }

    window.disconnectGSC = async function () {
        if (!confirm({$tDisconnectGscConfirm})) return;
        const res = await fetchJSON('/api/search-console/disconnect/' + websiteId, { method: 'POST' });
        if (res.success) { toast({$tDisconnected}, 'success'); loadSearchConsole(); }
        else toast(res.error || {$tDisconnectFailed}, 'error');
    };

    loadCompetitors();
    loadSearchConsole();
})();
JS;
        $script = str_replace('__WEBSITE_ID__', (string) $id, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', $this->tr('websites.manage_site'), $mainUrl, $body, $script);
    }

    /** GET /websites/{id}/edit */
    public function edit(array $params): array
    {
        return $this->show($params);
    }

    /** PUT /websites/{id} و PUT /api/websites/{id} */
    public function update(array $params): array
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        $clean = [];
        if ($this->has('main_url')) {
            [$u, $e] = $this->normalizePublicUrl((string) $this->get('main_url'));
            if ($e !== null) {
                return $this->error($e, 422, ['main_url' => [$e]]);
            }
            $dup = $this->findDuplicateWebsite((int) $userId, $u);
            if ($dup !== null && $dup !== (int) $website->getAttribute('id')) {
                return $this->error('الموقع ده متضاف عندك قبل كده', 409);
            }
            $clean['main_url'] = $u;
        }
        foreach (['competitor_1_url', 'competitor_2_url', 'competitor_3_url'] as $f) {
            if ($this->has($f)) {
                [$u, $e] = $this->normalizeOptionalUrl((string) $this->get($f));
                if ($e !== null) {
                    return $this->error('رابط المنافس: ' . $e, 422, [$f => [$e]]);
                }
                $clean[$f] = $u;
            }
        }

        foreach ([
            'main_url', 'company_name', 'industry', 'target_language', 'target_country',
            'meta_description', 'competitor_1_url', 'competitor_2_url', 'competitor_3_url'
        ] as $field) {
            if ($this->has($field)) {
                // تصحيح: لو المستخدم غيّر main_url، لازم يعيد التحقق من الملكية
                // من جديد (رابط جديد = ملكية لازم تتأكد تاني)، وإلا هيفضل الموقع
                // "موثّق" على رابط مختلف تمامًا عن اللي اتحقق منه فعليًا.
                $value = array_key_exists($field, $clean) ? $clean[$field] : $this->get($field);
                if ($field === 'main_url' && $value !== $website->getAttribute('main_url')) {
                    $website->setAttribute('is_verified', 0);
                }
                $website->setAttribute($field, $value);
            }
        }

        if ($website->save() === false) {
            return $this->error('تعذر تحديث الموقع', 500);
        }

        return $this->success(['website' => $website->toArray()], 'تم تحديث الموقع');
    }

    /** DELETE /websites/{id} و DELETE /api/websites/{id} */
    public function destroy(array $params): array
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        if (!$website->delete()) {
            return $this->error('تعذر حذف الموقع', 500);
        }

        return $this->success([], 'تم حذف الموقع');
    }

    /**
     * POST /api/websites/{id}/verify
     * تحقق حقيقي من ملكية الموقع بطريقتين: meta tag أو DNS TXT.
     * محدش بيوافق تلقائيًا؛ لازم واحدة من الطريقتين تتحقق فعليًا.
     */
    public function verify(array $params): array
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        $mainUrl = $this->normalizeUrl((string) $website->getAttribute('main_url'));
        $host = parse_url($mainUrl, PHP_URL_HOST);
        $instructions = $this->verificationInstructions($website);
        $token = $instructions['token'];

        if (!$host) {
            return $this->error('رابط الموقع غير صالح', 422);
        }

        $result = $this->runVerificationChecks($mainUrl, $host, $token);

        if (!$result['verified']) {
            return $this->error(
                $this->verificationFailureMessage($result['checks']),
                422,
                ['verification' => $instructions, 'checks' => $result['checks']]
            );
        }

        $website->setAttribute('is_verified', 1);
        $website->save();

        $this->log('Website Verified', ['website_id' => $website->getAttribute('id'), 'method' => $result['method']]);

        return $this->success([
            'website' => $website->toArray(),
            'method' => $result['method'],
        ], $this->tr('websites.verified_msg'));
    }

    /** POST /api/websites/{id}/competitors */
    public function updateCompetitors(array $params): array
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        $model = new Website();
        $website = $model->find((int) ($params['id'] ?? 0));

        if (!$website || (int) $website->getAttribute('user_id') !== (int) $userId) {
            return $this->error('الموقع غير موجود', 404);
        }

        foreach (['competitor_1_url', 'competitor_2_url', 'competitor_3_url'] as $field) {
            if ($this->has($field)) {
                [$u, $e] = $this->normalizeOptionalUrl((string) $this->get($field));
                if ($e !== null) {
                    return $this->error('رابط المنافس: ' . $e, 422, [$field => [$e]]);
                }
                $website->setAttribute($field, $u);
            }
        }

        if ($website->save() === false) {
            return $this->error('تعذر تحديث المنافسين', 500);
        }

        return $this->success(['website' => $website->toArray()], 'تم تحديث المنافسين');
    }
}
