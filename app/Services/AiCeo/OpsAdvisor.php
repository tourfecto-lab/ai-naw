<?php
require_once __DIR__ . '/../Brain/BrainSchema.php';
require_once __DIR__ . '/PlatformDecisions.php';

/**
 * TOURFECTO AI CEO (المرحلة 7) — مراقبة المنصة ومستشار التطوير.
 *
 * مبني على PlatformHealthService (الكرونات، التكاملات، البريد، الطابور، المدفوعات، السيرفر، الجداول) و ErrorLogService.
 * الجديد:
 *   - فحص صناعي (synthetic) لصفحات المنصة من غير تسجيل دخول: الصفحات العامة لازم ترجع 200، والصفحات المحمية لازم تحوّل
 *     للدخول (302/401/403). 500 أو مفيش رد = صفحة واقعة. 200 في صفحة محمية = تحذير أمني. وأكتر من 3 ثواني = بطيئة.
 *     مفيش حسابات ولا بيانات عملاء بتتلمس. الروابط ثابتة على دومين المنصة نفسها.
 *   - مزودي الـ API: نسبة الأخطاء آخر 24 ساعة لكل مزود، والبديل المقترح.
 *   - إساءة الاستخدام: عميل استهلاكه للذكاء الاصطناعي أضعاف الطبيعي، وعناوين IP فيها محاولات دخول فاشلة كتير. عرض بس، مفيش إجراء تلقائي.
 *   - النسخ الاحتياطية: عمر آخر نسخة في المجلد اللي صاحب المنصة يحدده.
 *   - الحوادث: بتتفتح من الفحوصات الفاشلة وبتتقفل لوحدها لما المشكلة تختفي، والتكرار محفوظ.
 *   - قائمة التطوير: بنود بالحقول العشرة المطلوبة، من الحوادث المتكررة ومواضيع الدعم المتكررة ومشاكل سيو موقع Tourfecto،
 *     أو يدويًا. القياس قبل/بعد بيتسجل لما البند يخلص. وإنشاء GitHub Issue بس لو صاحب المنصة فعّله وفيه توكن.
 */
final class OpsAdvisor
{
    public const PUBLIC_PAGES = ['/', '/login', '/register', '/pricing', '/features', '/blog', '/help', '/contact', '/privacy', '/terms', '/status', '/sitemap.xml', '/robots.txt', '/api/health'];
    public const PROTECTED_PAGES = ['/dashboard', '/websites', '/social', '/crm', '/bookings', '/creative-studio', '/reputation/overview', '/ads', '/whatsapp-marketing', '/admin', '/api/websites'];
    public const SLOW_MS = 3000;
    /** بدائل مقترحة لو مزود وقع */
    public const ALTERNATIVES = ['gemini' => 'Route AI tasks to the OpenAI or Claude fallback in AI service routing.', 'openai' => 'Route AI tasks to Gemini or Claude in AI service routing.',
        'anthropic' => 'Route AI tasks to Gemini or OpenAI in AI service routing.', 'claude' => 'Route AI tasks to Gemini or OpenAI in AI service routing.',
        'whatsapp' => 'Check the WhatsApp Cloud API token and phone number status in Meta Business Manager; there is no automatic alternative.',
        'dataforseo' => 'Raise the DataForSEO cap or fall back to the free Google Suggest/Trends sources for keyword ideas.', 'google' => 'Check the Google OAuth connection and API quota in Google Cloud Console.',
        'apify' => 'Check the Apify plan and actor status; imports can wait.', 'stripe' => 'Check the Stripe dashboard; wallet payments still work.', 'paypal' => 'Check the PayPal app status; other gateways still work.'];

    private Database $db;
    /** @var callable fn(string $url): array{code:int, ms:int} */
    private $fetch;

    public function __construct(?Database $db = null, ?callable $fetch = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->fetch = $fetch ?? [self::class, 'head'];
        BrainSchema::ensure($this->db);
    }

    private function safe(string $sql, array $p = []): ?array
    {
        try { return $this->db->query($sql, $p) ?: []; } catch (Throwable $e) { return null; }
    }

    public function baseUrl(): string
    {
        $v = $this->safe("SELECT setting_value FROM system_settings WHERE setting_key = 'ops_base_url' LIMIT 1")[0]['setting_value'] ?? '';
        $url = trim((string) ($v ?: (defined('APP_URL') ? APP_URL : 'https://tourfecto.pro')));
        return rtrim(preg_match('#^https?://#', $url) ? $url : 'https://' . $url, '/');
    }

    // ------------------------------------------------------------------ الفحص الصناعي

    /** حكم على نتيجة صفحة واحدة (منطق نقي) */
    public static function verdict(string $path, string $kind, int $code, int $ms): array
    {
        $status = 'ok';
        $note = '';
        if ($code === 0 || $code >= 500) { $status = 'fail'; $note = $code === 0 ? 'No response' : 'Server error ' . $code; }
        elseif ($kind === 'public' && $code >= 400) { $status = 'fail'; $note = 'Public page returns ' . $code; }
        elseif ($kind === 'protected' && $code === 200) { $status = 'warn'; $note = 'Protected page answered 200 without login (check it redirects to login)'; }
        elseif ($kind === 'protected' && !in_array($code, [301, 302, 303, 307, 401, 403], true)) { $status = 'warn'; $note = 'Unexpected ' . $code . ' without login'; }
        if ($status === 'ok' && $ms > self::SLOW_MS) { $status = 'slow'; $note = 'Took ' . round($ms / 1000, 1) . 's'; }
        return ['path' => $path, 'kind' => $kind, 'code' => $code, 'ms' => $ms, 'status' => $status, 'note' => $note];
    }

    public function synthetic(): array
    {
        $base = $this->baseUrl();
        $results = [];
        foreach (['public' => self::PUBLIC_PAGES, 'protected' => self::PROTECTED_PAGES] as $kind => $paths) {
            foreach ($paths as $p) {
                $r = ($this->fetch)($base . $p);
                $results[] = self::verdict($p, $kind, (int) $r['code'], (int) $r['ms']);
            }
        }
        $failed = count(array_filter($results, static fn ($r) => $r['status'] === 'fail'));
        $slow = count(array_filter($results, static fn ($r) => $r['status'] === 'slow'));
        $this->db->query('INSERT INTO aiceo_ops_checks (base_url, checked, failed, slow, results_json, run_at) VALUES (?, ?, ?, ?, ?, ?)', [$base, count($results), $failed, $slow, json_encode($results), gmdate('Y-m-d H:i:s')]);
        $this->db->query('DELETE FROM aiceo_ops_checks WHERE run_at < ?', [gmdate('Y-m-d H:i:s', strtotime('-30 days'))]);
        return ['base_url' => $base, 'checked' => count($results), 'failed' => $failed, 'slow' => $slow, 'results' => $results];
    }

    public function lastChecks(int $n = 24): array
    {
        $rows = $this->db->query('SELECT id, base_url, checked, failed, slow, results_json, run_at FROM aiceo_ops_checks ORDER BY id DESC LIMIT ' . max(1, min(200, $n))) ?: [];
        $out = [];
        foreach ($rows as $i => $r) $out[] = ['run_at' => $r['run_at'], 'checked' => (int) $r['checked'], 'failed' => (int) $r['failed'], 'slow' => (int) $r['slow']] + ($i === 0 ? ['base_url' => $r['base_url'], 'results' => json_decode((string) $r['results_json'], true)] : []);
        return $out;
    }

    /** طلب بدون تتبع التحويلات (عشان نشوف 302 للصفحات المحمية) */
    public static function head(string $url): array
    {
        $t = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15, CURLOPT_MAXFILESIZE => 3000000,
            CURLOPT_USERAGENT => 'TourfectoOpsCheck/1.0 (+https://tourfecto.pro)']);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'ms' => (int) round((microtime(true) - $t) * 1000)];
    }

    // ------------------------------------------------------------------ المزودين وإساءة الاستخدام والنسخ الاحتياطية

    public function providers(): ?array
    {
        $rows = $this->safe("SELECT api_type AS provider, COUNT(*) AS calls, SUM(status_code >= 400) AS errors, ROUND(AVG(duration_ms)) AS avg_ms, MAX(created_at) AS last_call
            FROM api_usage_logs WHERE created_at >= NOW() - INTERVAL 24 HOUR GROUP BY api_type ORDER BY calls DESC");
        if ($rows === null) return null;
        return array_map(static function ($r) {
            $calls = (int) $r['calls'];
            $rate = $calls > 0 ? round((int) $r['errors'] / $calls * 100, 1) : 0.0;
            $p = strtolower((string) $r['provider']);
            return ['provider' => $p, 'calls' => $calls, 'errors' => (int) $r['errors'], 'error_rate' => $rate, 'avg_ms' => (int) $r['avg_ms'], 'last_call' => $r['last_call'],
                'status' => $calls >= 10 && $rate >= 20 ? 'fail' : ($calls >= 10 && $rate >= 5 ? 'warn' : 'ok'), 'alternative' => self::ALTERNATIVES[$p] ?? null];
        }, $rows);
    }

    public function abuse(): array
    {
        $ai = $this->safe("SELECT a.user_id, u.email, u.company_name, COUNT(*) AS calls, ROUND(SUM(a.cost_in_usd), 2) AS cost FROM api_usage_logs a JOIN users u ON u.id = a.user_id
            WHERE a.created_at >= NOW() - INTERVAL 24 HOUR AND u.role NOT IN ('admin','super_admin') GROUP BY a.user_id, u.email, u.company_name ORDER BY calls DESC LIMIT 200") ?? [];
        $calls = array_map(static fn ($r) => (int) $r['calls'], $ai);
        sort($calls);
        $median = $calls ? $calls[(int) floor((count($calls) - 1) / 2)] : 0;
        $limit = max(200, 10 * $median);
        $heavy = array_values(array_filter(array_map(static fn ($r) => ['user_id' => (int) $r['user_id'], 'company' => $r['company_name'] ?: $r['email'], 'calls' => (int) $r['calls'], 'cost' => (float) $r['cost']], $ai), static fn ($r) => $r['calls'] >= $limit));
        $ips = $this->safe("SELECT ip_address, COUNT(*) AS failed, COUNT(DISTINCT email_attempted) AS emails FROM login_history WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 1 HOUR GROUP BY ip_address HAVING failed >= 20 ORDER BY failed DESC LIMIT 20");
        return ['ai_heavy_users' => $heavy, 'ai_threshold' => $limit, 'login_attack_ips' => array_map(static fn ($r) => ['ip' => (string) $r['ip_address'], 'failed' => (int) $r['failed'], 'emails' => (int) $r['emails']], $ips ?? []),
            'login_data' => $ips !== null, 'note' => 'Shown for review only. Nobody is blocked automatically; the existing rate limits still apply.'];
    }

    public function backups(): array
    {
        $dir = trim((string) ($this->safe("SELECT setting_value FROM system_settings WHERE setting_key = 'ops_backup_dir' LIMIT 1")[0]['setting_value'] ?? ''));
        if ($dir === '') return ['configured' => false, 'status' => 'warn', 'message' => 'Backup folder not set. Run tools/backup.php from a daily cron to a folder outside the site, then enter that folder here so freshness is checked.'];
        if (!is_dir($dir) || !is_readable($dir)) return ['configured' => true, 'dir' => $dir, 'status' => 'fail', 'message' => 'The backup folder is missing or not readable by the web user.'];
        $newest = null;
        foreach (glob(rtrim($dir, '/') . '/*.tfb') ?: [] as $f) { $m = filemtime($f); if ($m && (!$newest || $m > $newest['mtime'])) $newest = ['file' => basename($f), 'mtime' => $m, 'size' => filesize($f)]; }
        if (!$newest) return ['configured' => true, 'dir' => $dir, 'status' => 'fail', 'message' => 'No encrypted backups (*.tfb) found in the folder.'];
        $age = (time() - $newest['mtime']) / 3600;
        return ['configured' => true, 'dir' => $dir, 'status' => $age > 48 ? 'fail' : ($age > 26 ? 'warn' : 'ok'), 'latest' => $newest['file'], 'age_hours' => round($age, 1), 'size_mb' => round($newest['size'] / 1048576, 1),
            'message' => 'Latest backup is ' . round($age, 1) . ' hours old. Test a restore with tools/restore_backup.php on a separate server or database, never on production.'];
    }

    // ------------------------------------------------------------------ الحوادث

    /** الإشارات الحالية → حوادث [ikey => [service, title, severity, evidence]] (منطق نقي) */
    public static function signals(array $synthetic, array $healthProblems, array $errorGroups, ?array $providers, array $backups): array
    {
        $out = [];
        foreach ($synthetic as $r) {
            if ($r['status'] === 'fail') $out['page:' . $r['path']] = ['service' => 'pages', 'title' => 'Page ' . $r['path'] . ' is down', 'severity' => $r['kind'] === 'public' ? 'critical' : 'high', 'evidence' => $r['note']];
            elseif ($r['status'] === 'warn' && $r['kind'] === 'protected' && $r['code'] === 200) $out['auth:' . $r['path']] = ['service' => 'security', 'title' => 'Protected page ' . $r['path'] . ' opens without login', 'severity' => 'high', 'evidence' => $r['note']];
            elseif ($r['status'] === 'slow') $out['slow:' . $r['path']] = ['service' => 'performance', 'title' => 'Page ' . $r['path'] . ' is slow', 'severity' => 'low', 'evidence' => $r['note']];
        }
        foreach ($healthProblems as $p) {
            if (($p['status'] ?? '') !== 'fail') continue;
            $out['health:' . $p['section'] . ':' . $p['key']] = ['service' => (string) $p['section'], 'title' => (string) ($p['label'] ?: $p['key']), 'severity' => in_array($p['section'], ['payments', 'cron', 'queue'], true) ? 'high' : 'medium', 'evidence' => (string) $p['message']];
        }
        foreach ($errorGroups as $g) {
            if ((int) ($g['count'] ?? 0) < 20) continue;
            $out['error:' . substr(md5((string) $g['message']), 0, 12)] = ['service' => 'errors', 'title' => 'Repeated error: ' . mb_substr((string) $g['message'], 0, 120), 'severity' => (int) $g['count'] >= 100 ? 'high' : 'medium', 'evidence' => $g['count'] . ' times in 24h'];
        }
        foreach ($providers ?? [] as $p) {
            if ($p['status'] === 'fail') $out['provider:' . $p['provider']] = ['service' => 'integrations', 'title' => 'Provider ' . $p['provider'] . ' is failing', 'severity' => 'high', 'evidence' => $p['error_rate'] . '% errors in ' . $p['calls'] . ' calls (24h)'];
        }
        if (($backups['status'] ?? 'ok') === 'fail') $out['backup'] = ['service' => 'backups', 'title' => 'Backups are missing or stale', 'severity' => 'high', 'evidence' => (string) $backups['message']];
        return $out;
    }

    /** الإشارات اللي صاحب المنصة قال إنها متوقعة على السيرفر ده (مثلًا: موقع التطوير مالوش منظّم كرون) [ikey => reason] */
    public function ignored(): array
    {
        $v = $this->safe("SELECT setting_value FROM system_settings WHERE setting_key = 'ops_ignored' LIMIT 1")[0]['setting_value'] ?? '';
        $a = json_decode((string) $v, true);
        return is_array($a) ? $a : [];
    }

    /** "متوقع هنا": الحادثة بتتقفل ومش بتتفتح تاني لحد ما ترجّعها. مفيش حاجة بتتمسح */
    public function ignoreIncident(int $id, string $reason): string
    {
        $key = (string) ($this->db->query('SELECT ikey FROM aiceo_incidents WHERE id = ? LIMIT 1', [$id])[0]['ikey'] ?? '');
        if ($key === '') throw new InvalidArgumentException('Incident not found');
        $list = $this->ignored();
        $list[$key] = mb_substr(trim($reason) ?: 'Expected on this server', 0, 200);
        $this->upsert('ops_ignored', json_encode($list, JSON_UNESCAPED_UNICODE));
        $this->db->query("UPDATE aiceo_incidents SET status = 'resolved', resolved_at = ?, root_cause = COALESCE(root_cause, ?) WHERE ikey = ? AND status = 'open'", [gmdate('Y-m-d H:i:s'), 'Marked expected: ' . $list[$key], $key]);
        return $key;
    }

    public function unignore(string $key): void
    {
        $list = $this->ignored();
        unset($list[$key]);
        $this->upsert('ops_ignored', json_encode($list, JSON_UNESCAPED_UNICODE));
    }

    /** فتح/تحديث/قفل الحوادث حسب الإشارات الحالية */
    public function syncIncidents(array $signals): array
    {
        $signals = array_diff_key($signals, $this->ignored());
        $now = gmdate('Y-m-d H:i:s');
        $open = [];
        foreach ($this->db->query("SELECT id, ikey FROM aiceo_incidents WHERE status = 'open'") ?: [] as $r) $open[$r['ikey']] = (int) $r['id'];
        $opened = $resolved = 0;
        foreach ($signals as $key => $s) {
            if (isset($open[$key])) {
                $this->db->query('UPDATE aiceo_incidents SET last_seen = ?, evidence_json = ?, severity = ? WHERE id = ?', [$now, json_encode([$s['evidence']]), $s['severity'], $open[$key]]);
                unset($open[$key]);
                continue;
            }
            $prev = (int) ($this->db->query('SELECT COUNT(*) AS n FROM aiceo_incidents WHERE ikey = ?', [$key])[0]['n'] ?? 0);
            $this->db->query('INSERT INTO aiceo_incidents (ikey, service, title, severity, status, occurrence, evidence_json, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$key, mb_substr($s['service'], 0, 80), mb_substr($s['title'], 0, 255), $s['severity'], 'open', $prev + 1, json_encode([$s['evidence']]), $now, $now]);
            $opened++;
        }
        foreach ($open as $id) { $this->db->query("UPDATE aiceo_incidents SET status = 'resolved', resolved_at = ? WHERE id = ?", [$now, $id]); $resolved++; }
        return ['opened' => $opened, 'resolved' => $resolved];
    }

    public function incidents(string $status = 'open', int $limit = 100): array
    {
        $rows = $this->db->query('SELECT * FROM aiceo_incidents WHERE status = ? ORDER BY FIELD(severity, \'critical\', \'high\', \'medium\', \'low\'), last_seen DESC LIMIT ' . max(1, min(500, $limit)), [$status === 'resolved' ? 'resolved' : 'open']) ?: [];
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'key' => $r['ikey'], 'service' => $r['service'], 'title' => $r['title'], 'severity' => $r['severity'], 'status' => $r['status'], 'occurrence' => (int) $r['occurrence'],
            'evidence' => json_decode((string) $r['evidence_json'], true) ?: [], 'root_cause' => $r['root_cause'], 'first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen'], 'resolved_at' => $r['resolved_at']], $rows);
    }

    /** الأعطال المتكررة: مفاتيح اتفتحت 3 مرات أو أكتر آخر 30 يوم */
    public function recurring(): array
    {
        return array_map(static fn ($r) => ['key' => $r['ikey'], 'service' => $r['service'], 'title' => $r['title'], 'times' => (int) $r['n'], 'last_seen' => $r['last_seen']],
            $this->db->query("SELECT ikey, MAX(service) AS service, MAX(title) AS title, COUNT(*) AS n, MAX(last_seen) AS last_seen FROM aiceo_incidents WHERE first_seen >= ? GROUP BY ikey HAVING n >= 3 ORDER BY n DESC LIMIT 30", [gmdate('Y-m-d H:i:s', strtotime('-30 days'))]) ?: []);
    }

    public function setRootCause(int $id, string $text): void
    {
        $this->db->query('UPDATE aiceo_incidents SET root_cause = ? WHERE id = ?', [mb_substr(trim($text), 0, 4000), $id]);
    }

    // ------------------------------------------------------------------ قائمة التطوير

    public const SEV_SCORES = ['critical' => ['impact' => 5, 'urgency' => 5], 'high' => ['impact' => 4, 'urgency' => 4], 'medium' => ['impact' => 3, 'urgency' => 3], 'low' => ['impact' => 2, 'urgency' => 2]];
    public const COST_SCORES = ['S' => 1, 'M' => 3, 'L' => 5];

    public static function backlogPriority(string $severity, string $cost, int $confidence = 4): float
    {
        $s = self::SEV_SCORES[$severity] ?? self::SEV_SCORES['medium'];
        $c = self::COST_SCORES[$cost] ?? 3;
        return PlatformDecisions::priority(['impact' => $s['impact'], 'roi' => max(1, $s['impact'] - $c + 3), 'urgency' => $s['urgency'], 'confidence' => $confidence, 'risk' => 2, 'cost' => $c, 'effort' => $c]);
    }

    /** إضافة بند (أو تحديث الدليل لو نفس المفتاح موجود ومش مقفول). بيرجع id */
    public function addBacklog(array $b): int
    {
        foreach (['service', 'problem'] as $f) if (trim((string) ($b[$f] ?? '')) === '') throw new InvalidArgumentException('Missing ' . $f);
        $sev = in_array($b['severity'] ?? '', ['critical', 'high', 'medium', 'low'], true) ? $b['severity'] : 'medium';
        $cost = in_array($b['cost_estimate'] ?? '', ['S', 'M', 'L'], true) ? $b['cost_estimate'] : 'M';
        $now = gmdate('Y-m-d H:i:s');
        $key = isset($b['bkey']) && $b['bkey'] !== '' ? mb_substr((string) $b['bkey'], 0, 190) : null;
        if ($key) {
            $ex = $this->db->query('SELECT id, status FROM aiceo_dev_backlog WHERE bkey = ? LIMIT 1', [$key])[0] ?? null;
            if ($ex) {
                if (!in_array($ex['status'], ['done', 'rejected'], true)) $this->db->query('UPDATE aiceo_dev_backlog SET evidence = ?, updated_at = ? WHERE id = ?', [mb_substr((string) ($b['evidence'] ?? ''), 0, 4000), $now, (int) $ex['id']]);
                return (int) $ex['id'];
            }
        }
        $this->db->query('INSERT INTO aiceo_dev_backlog (bkey, source, service, problem, evidence, severity, improvement, priority, cost_estimate, benefit, test_method, needs_approval, status, metric_before, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$key, mb_substr((string) ($b['source'] ?? 'manual'), 0, 40), mb_substr(trim((string) $b['service']), 0, 80), mb_substr(trim((string) $b['problem']), 0, 500),
            mb_substr((string) ($b['evidence'] ?? ''), 0, 4000), $sev, mb_substr((string) ($b['improvement'] ?? ''), 0, 4000), self::backlogPriority($sev, $cost), $cost, mb_substr((string) ($b['benefit'] ?? ''), 0, 500),
            mb_substr((string) ($b['test_method'] ?? ''), 0, 500), array_key_exists('needs_approval', $b) ? (int) (bool) $b['needs_approval'] : 1, 'open', mb_substr((string) ($b['metric_before'] ?? ''), 0, 255), $now, $now]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM aiceo_dev_backlog')[0]['id'] ?? 0);
    }

    /** بنود تلقائية من الحوادث المتكررة ومواضيع الدعم ومشاكل سيو موقع Tourfecto */
    public function generateBacklog(array $supportRecurring = [], array $brandIssueCounts = []): int
    {
        $n = 0;
        foreach ($this->recurring() as $r) {
            $this->addBacklog(['bkey' => 'incident:' . $r['key'], 'source' => 'incident', 'service' => $r['service'], 'problem' => 'Recurring: ' . $r['title'], 'evidence' => 'Opened ' . $r['times'] . ' times in 30 days, last ' . $r['last_seen'] . ' UTC',
                'severity' => str_starts_with($r['key'], 'page:') || str_starts_with($r['key'], 'auth:') ? 'high' : 'medium', 'improvement' => 'Find the root cause (logs around the incident times) and fix it so it stops recurring.',
                'cost_estimate' => 'M', 'benefit' => 'Fewer outages and support messages.', 'test_method' => 'The incident does not reopen for 14 days after the fix.', 'metric_before' => $r['times'] . ' incidents / 30 days']);
            $n++;
        }
        foreach ($supportRecurring as $theme => $count) {
            $this->addBacklog(['bkey' => 'support:' . $theme, 'source' => 'support', 'service' => (string) $theme, 'problem' => 'Customers keep writing about ' . str_replace('_', ' / ', (string) $theme), 'evidence' => $count . ' support messages in 90 days',
                'severity' => 'medium', 'improvement' => 'Read the messages, reproduce the problem, then fix it or add an in-app guide.', 'cost_estimate' => 'M', 'benefit' => 'Less support load and churn.',
                'test_method' => 'Messages on this topic drop in the next 30 days.', 'metric_before' => $count . ' messages / 90 days']);
            $n++;
        }
        foreach (['http_error' => 'high', 'noindex' => 'high', 'title_missing' => 'medium', 'sitemap_missing' => 'high', 'canonical_other_host' => 'high'] as $code => $sev) {
            if (empty($brandIssueCounts[$code])) continue;
            $this->addBacklog(['bkey' => 'brand_seo:' . $code, 'source' => 'brand_audit', 'service' => 'tourfecto.pro SEO', 'problem' => 'Site audit: ' . str_replace('_', ' ', $code), 'evidence' => $brandIssueCounts[$code] . ' pages in the last audit',
                'severity' => $sev, 'improvement' => 'Fix the pages listed in Brand Growth → Pages with issues.', 'cost_estimate' => 'S', 'benefit' => 'More pages indexed; more organic sign-ups.',
                'test_method' => 'The next weekly audit shows 0 for this issue.', 'metric_before' => $brandIssueCounts[$code] . ' pages']);
            $n++;
        }
        return $n;
    }

    public function backlog(?string $status = null, int $limit = 200): array
    {
        $where = $status ? 'WHERE status = ?' : "WHERE status IN ('open','approved','in_progress')";
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'source' => $r['source'], 'service' => $r['service'], 'problem' => $r['problem'], 'evidence' => $r['evidence'], 'severity' => $r['severity'],
            'improvement' => $r['improvement'], 'priority' => (float) $r['priority'], 'cost_estimate' => $r['cost_estimate'], 'benefit' => $r['benefit'], 'test_method' => $r['test_method'],
            'needs_approval' => (bool) $r['needs_approval'], 'status' => $r['status'], 'metric_before' => $r['metric_before'], 'metric_after' => $r['metric_after'], 'issue_url' => $r['issue_url'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at']],
            $this->db->query("SELECT * FROM aiceo_dev_backlog {$where} ORDER BY priority DESC, id ASC LIMIT " . max(1, min(500, $limit)), $status ? [$status] : []) ?: []);
    }

    /** تغيير الحالة؛ لما البند يخلص بنسجل القياس بعد (لو اتبعت) */
    public function setBacklogStatus(int $id, string $status, ?string $metricAfter = null): void
    {
        if (!in_array($status, ['open', 'approved', 'in_progress', 'done', 'rejected'], true)) throw new InvalidArgumentException('Invalid status');
        $row = $this->db->query('SELECT id, bkey FROM aiceo_dev_backlog WHERE id = ? LIMIT 1', [$id])[0] ?? null;
        if (!$row) throw new InvalidArgumentException('Item not found');
        if ($status === 'done' && ($metricAfter === null || $metricAfter === '') && $row['bkey'] && str_starts_with((string) $row['bkey'], 'incident:')) {
            $k = substr((string) $row['bkey'], 9);
            $metricAfter = ((int) ($this->db->query("SELECT COUNT(*) AS n FROM aiceo_incidents WHERE ikey = ? AND first_seen >= ?", [$k, gmdate('Y-m-d H:i:s', strtotime('-30 days'))])[0]['n'] ?? 0)) . ' incidents / 30 days (at completion)';
        }
        $this->db->query('UPDATE aiceo_dev_backlog SET status = ?, metric_after = COALESCE(?, metric_after), updated_at = ? WHERE id = ?', [$status, $metricAfter !== null && $metricAfter !== '' ? mb_substr($metricAfter, 0, 255) : null, gmdate('Y-m-d H:i:s'), $id]);
    }

    /** نص الـ Issue (للنسخ، أو للإرسال لـ GitHub لو متفعّل) */
    public static function issueText(array $b): array
    {
        $body = "**Service:** {$b['service']}\n**Problem:** {$b['problem']}\n**Evidence:** {$b['evidence']}\n**Severity:** {$b['severity']}\n**Improvement:** {$b['improvement']}\n"
            . "**Priority:** {$b['priority']}/50\n**Estimated cost:** {$b['cost_estimate']}\n**Benefit:** {$b['benefit']}\n**How to test:** {$b['test_method']}\n**Needs approval:** " . ($b['needs_approval'] ? 'yes' : 'no')
            . ($b['metric_before'] ? "\n**Before:** {$b['metric_before']}" : '') . "\n\n_From the Tourfecto AI CEO development backlog #{$b['id']}._";
        return ['title' => '[' . $b['service'] . '] ' . mb_substr($b['problem'], 0, 120), 'body' => $body];
    }

    /** GitHub Issue: بس لو صاحب المنصة فعّله (ops_github_issues=1) وفيه GITHUB_ISSUES_TOKEN و GITHUB_ISSUES_REPO */
    public function githubConfig(): ?array
    {
        $on = ($this->safe("SELECT setting_value FROM system_settings WHERE setting_key = 'ops_github_issues' LIMIT 1")[0]['setting_value'] ?? '') === '1';
        $token = (string) (getenv('GITHUB_ISSUES_TOKEN') ?: ($_ENV['GITHUB_ISSUES_TOKEN'] ?? ''));
        $repo = (string) (getenv('GITHUB_ISSUES_REPO') ?: ($_ENV['GITHUB_ISSUES_REPO'] ?? ''));
        if (!$on || $token === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) return null;
        return ['token' => $token, 'repo' => $repo];
    }

    public function createIssue(int $id): string
    {
        $cfg = $this->githubConfig();
        if (!$cfg) throw new RuntimeException('github_not_enabled');
        $b = array_values(array_filter($this->backlog(null, 500), static fn ($x) => $x['id'] === $id))[0] ?? null;
        if (!$b) throw new InvalidArgumentException('Item not found');
        if ($b['issue_url']) return $b['issue_url'];
        $t = self::issueText($b);
        $ch = curl_init('https://api.github.com/repos/' . $cfg['repo'] . '/issues');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => json_encode(['title' => $t['title'], 'body' => $t['body'], 'labels' => ['ai-ceo']]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Accept: application/vnd.github+json', 'User-Agent: Tourfecto-AI-CEO', 'Content-Type: application/json']]);
        $res = json_decode((string) curl_exec($ch), true);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 201 || empty($res['html_url'])) throw new RuntimeException('github_failed');
        $this->db->query('UPDATE aiceo_dev_backlog SET issue_url = ?, updated_at = ? WHERE id = ?', [mb_substr((string) $res['html_url'], 0, 255), gmdate('Y-m-d H:i:s'), $id]);
        return (string) $res['html_url'];
    }

    // ------------------------------------------------------------------ الجولة الكاملة (الكرون)

    /** جولة: فحص الصفحات + الإشارات + الحوادث + قائمة التطوير */
    public function cycle(array $healthProblems, array $errorGroups, array $supportRecurring = [], array $brandIssueCounts = []): array
    {
        $syn = $this->synthetic();
        $signals = self::signals($syn['results'], $healthProblems, $errorGroups, $this->providers(), $this->backups());
        $inc = $this->syncIncidents($signals);
        $gen = $this->generateBacklog($supportRecurring, $brandIssueCounts);
        return ['checked' => $syn['checked'], 'failed' => $syn['failed'], 'slow' => $syn['slow'], 'signals' => count($signals)] + $inc + ['backlog_touched' => $gen];
    }

    /** الجولة بالبيانات الحقيقية من خدمات الأدمن (الكرون وزرار "Run checks now") */
    public function runCycle(): array
    {
        $problems = [];
        try {
            foreach ((new PlatformHealthService($this->db))->report()['sections'] ?? [] as $section => $sec) {
                foreach ($sec['checks'] ?? [] as $c) if (($c['status'] ?? '') === 'fail') $problems[] = ['section' => (string) $section, 'key' => (string) ($c['key'] ?? ''), 'status' => 'fail', 'label' => (string) ($c['label'] ?? ''), 'message' => mb_substr((string) ($c['message'] ?? ''), 0, 220)];
            }
        } catch (Throwable $e) {}
        $errors = [];
        try { $errors = (new ErrorLogService())->report(24, 'error')['groups'] ?? []; } catch (Throwable $e) {}
        $support = [];
        try { require_once __DIR__ . '/CustomerSuccess.php'; $support = array_filter((new CustomerSuccess($this->db))->support(90)['themes'] ?? [], static fn ($n) => $n >= 3); } catch (Throwable $e) {}
        $brand = [];
        try { require_once __DIR__ . '/BrandSiteAudit.php'; $brand = (new BrandSiteAudit($this->db))->latest()['summary']['issue_counts'] ?? []; } catch (Throwable $e) {}
        return $this->cycle($problems, $errors, $support, $brand);
    }

    /** ملخص للصورة (من غير أي طلبات شبكة) */
    public function summary(): array
    {
        $last = $this->lastChecks(1)[0] ?? null;
        $sev = [];
        foreach ($this->db->query("SELECT severity, COUNT(*) AS n FROM aiceo_incidents WHERE status = 'open' GROUP BY severity") ?: [] as $r) $sev[$r['severity']] = (int) $r['n'];
        $providers = $this->providers() ?? [];
        $abuse = $this->abuse();
        return ['last_check_at' => $last['run_at'] ?? null, 'pages_failed' => $last['failed'] ?? null, 'pages_slow' => $last['slow'] ?? null, 'incidents_open' => $sev,
            'recurring' => count($this->recurring()), 'backlog_open' => (int) ($this->db->query("SELECT COUNT(*) AS n FROM aiceo_dev_backlog WHERE status IN ('open','approved','in_progress')")[0]['n'] ?? 0),
            'providers_failing' => array_values(array_map(static fn ($p) => $p['provider'], array_filter($providers, static fn ($p) => $p['status'] === 'fail'))),
            'abuse_users' => count($abuse['ai_heavy_users']), 'abuse_ips' => count($abuse['login_attack_ips']), 'backup_status' => $this->backups()['status']];
    }

    public function settings(): array
    {
        $m = array_column($this->safe("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ops_base_url','ops_backup_dir','ops_github_issues')") ?? [], 'setting_value', 'setting_key');
        return ['base_url' => $m['ops_base_url'] ?? '', 'backup_dir' => $m['ops_backup_dir'] ?? '', 'github_issues' => ($m['ops_github_issues'] ?? '') === '1', 'github_ready' => $this->githubConfig() !== null];
    }

    public function saveSettings(array $in): array
    {
        if (array_key_exists('base_url', $in)) {
            $u = trim((string) $in['base_url']);
            // عنوان المنصة نفسها بس (مش أي موقع): نفس دومين APP_URL أو نطاق فرعي منه
            $own = strtolower((string) parse_url(defined('APP_URL') ? APP_URL : 'https://tourfecto.pro', PHP_URL_HOST));
            $h = strtolower((string) parse_url($u, PHP_URL_HOST));
            if ($u !== '' && (!preg_match('#^https://#', $u) || ($h !== $own && !str_ends_with($h, '.' . preg_replace('#^www\.#', '', $own)) && preg_replace('#^www\.#', '', $h) !== preg_replace('#^www\.#', '', $own)))) throw new InvalidArgumentException('The check address must be https on the platform domain (' . $own . ').');
            $this->upsert('ops_base_url', $u);
        }
        if (array_key_exists('backup_dir', $in)) {
            $d = trim((string) $in['backup_dir']);
            if ($d !== '' && (!str_starts_with($d, '/') || str_contains($d, '..'))) throw new InvalidArgumentException('Backup folder must be an absolute path.');
            $this->upsert('ops_backup_dir', $d);
        }
        if (array_key_exists('github_issues', $in)) $this->upsert('ops_github_issues', !empty($in['github_issues']) ? '1' : '0');
        return $this->settings();
    }

    private function upsert(string $k, string $v): void
    {
        $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$k, $v]);
    }
}
