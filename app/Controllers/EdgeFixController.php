<?php

/**
 * Tourfecto - Edge Fix Controller (تنفيذ إصلاحات التدقيق تلقائي على موقع WordPress)
 *
 *  GET  /api/edge/rules?token=&site=                       (عام، بتوكن موقّع) القواعد اللي كود Tourfecto Edge بيسحبها
 *  GET  /api/website-optimizer/edge/status?website_id=     حالة الاتصال + الكود الجاهز + إيه اللي ينفع يتنفّذ
 *  POST /api/website-optimizer/fixes/{id}/edge-preview     القيمة الحقيقية اللي هتتطبّق (قبل أي تغيير)
 *  POST /api/website-optimizer/fixes/{id}/edge-apply       تنفيذ (value اختياري لو العميل عدّلها)
 *  POST /api/website-optimizer/fixes/{id}/edge-rollback    تراجع
 *  POST /api/website-optimizer/edge-apply-all              تنفيذ كل الإصلاحات منخفضة الخطورة في تدقيق معيّن
 *
 * @version 1.0.0
 */
class EdgeFixController extends Controller
{
    // ------------------------------------------------------------------ مساعدات

    protected function pageContext(): PageContext
    {
        return new PageContext();
    }

    protected function ai()
    {
        if (class_exists('AIOrchestrator')) {
            return new AIOrchestrator();
        }
        return class_exists('GeminiClient') ? new GeminiClient() : null;
    }

    private array $reportContext=[];

    private function appUrl(): string
    {
        return rtrim((string) (defined('APP_URL') ? APP_URL : (function_exists('env') ? env('APP_URL') : '')), '/');
    }

    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    private function siteHost(?string $url): string
    {
        return strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    }

    /** إصلاح + تدقيق + موقع، بعد التأكد إنهم بتوع العميل. */
    private function loadFix(int $fixId): ?array
    {
        $rows = $this->db->query(
            "SELECT f.id, f.audit_id, f.check_key, f.status, f.title, a.website_id, a.user_id, w.main_url, w.auto_pilot_mode
               FROM wo_fixes f
         INNER JOIN wo_audits a ON a.id = f.audit_id
          INNER JOIN websites w ON w.id = a.website_id AND w.user_id = a.user_id
              WHERE f.id = ? AND a.user_id = ? LIMIT 1",
            [$fixId, $this->uid()]
        );
        return $rows[0] ?? null;
    }

    private function targetUrl(array $fix): string
    {
        // التدقيق بيتعمل على websites.main_url (مفيش عمود url في wo_audits)
        $u = (string) ($fix['main_url'] ?? '');
        if ($u !== '' && !preg_match('#^https?://#i', $u)) {
            $u = 'https://' . $u;
        }
        return $u;
    }

    private function connectionRow(int $websiteId): ?array
    {
        try {
            $r = $this->db->query('SELECT last_seen_at, hits, rules_version FROM edge_connections WHERE website_id = ? LIMIT 1', [$websiteId]);
            return $r[0] ?? null;
        } catch (Throwable $e) {
            return null;     // الجدول لسه ما اتعملش (قبل TOURFECTO_AUTOFIX.sql)
        }
    }

    private function isConnected(?array $row): bool
    {
        return $row && !empty($row['last_seen_at']) && strtotime((string) $row['last_seen_at']) > time() - 172800;
    }

    // ------------------------------------------------------------------ القواعد (عام)

    public function rules(array $params = []): array
    {
        header('Cache-Control: no-store, private');
        $websiteId = EdgeRules::parseToken((string) $this->get('token', ''));
        if ($websiteId === null) {
            http_response_code(404);
            return ['success' => false, 'error' => 'not_found'];
        }
        $site = $this->db->query('SELECT main_url FROM websites WHERE id = ? LIMIT 1', [$websiteId]);
        if (empty($site)) {
            http_response_code(404);
            return ['success' => false, 'error' => 'not_found'];
        }
        $host = $this->siteHost(WordPressConnector::normalizeSiteUrl((string) $site[0]['main_url']));
        $rules = EdgeRules::rulesFor($this->db, $websiteId, $host);
        $version = EdgeRules::version($rules);

        // تسجيل إن الكود شغّال على موقع العميل (آخر ظهور) من غير تخزين أي بيانات شخصية
        try {
            $this->db->query(
                'INSERT INTO edge_connections (website_id, last_seen_at, hits, rules_version) VALUES (?, NOW(), 1, ?)
                 ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hits = hits + 1, rules_version = VALUES(rules_version)',
                [$websiteId, $version]
            );
        } catch (Throwable $e) {
            Logger::error('Edge heartbeat could not be saved', ['website_id'=>$websiteId]);
            return $this->error('Connection unavailable. Please contact support.',503);
        }
        return $this->success(['version' => $version, 'rules' => $rules]);
    }

    // ------------------------------------------------------------------ الحالة

    public function status(array $params = []): array
    {
        header('Cache-Control: no-store, private');
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        $site = $this->db->query('SELECT id, main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $this->uid()]);
        if (empty($site)) {
            return $this->error('Not found', 404);
        }
        $row = $this->connectionRow($websiteId);
        $active = $this->db->query('SELECT COUNT(DISTINCT field_name) c FROM auto_seo_applied_fixes WHERE website_id = ? AND is_active = 1', [$websiteId]);
        return $this->success([
            'connected' => $this->isConnected($row),
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'active_rules' => (int) ($active[0]['c'] ?? 0),
            'snippet' => EdgeRules::snippet($websiteId, $this->appUrl()),
            'plugin_url' => '/api/website-optimizer/edge/plugin?website_id=' . $websiteId,
            'csrf_token'=>Csrf::token(),
            'capabilities' => FixCapability::all(),
        ]);
    }

    /** GET /api/website-optimizer/edge/plugin?website_id= - إضافة WordPress جاهزة (zip) بالتوكن بتاع الموقع */
    public function plugin(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        $site = $this->db->query('SELECT id, main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $this->uid()]);
        if (empty($site)) {
            return $this->error('Not found', 404);
        }
        if (!class_exists('ZipArchive')) {
            return $this->error('ضغط الملفات مش متاح على السيرفر - استخدم الكود اليدوي', 500);
        }
        require_once __DIR__.'/../Services/AutoFix/TourfectoPluginBundle.php';
        $tmp = false;
        $zip = null;
        try {
            $files = TourfectoPluginBundle::files($websiteId, $this->appUrl(), $this->siteHost((string) $site[0]['main_url']));
            $tmp = tempnam(sys_get_temp_dir(), 'tfwp');
            if ($tmp === false) throw new RuntimeException('Temporary archive unavailable.');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Archive unavailable.');
            foreach ($files as $name=>$bytes) {
                if (!$zip->addFromString($name, $bytes)) throw new RuntimeException('Module packaging failed.');
            }
            if (!$zip->close()) throw new RuntimeException('Archive finalization failed.');
            $zip = null;
        } catch (Throwable $e) {
            if ($zip !== null) { try { $zip->close(); } catch (Throwable $ignored) {} }
            if ($tmp !== false) @unlink($tmp);
            return $this->error('تعذر تجهيز الإضافة الموحدة. تأكد من رفع جميع ملفات التحديث.', 500);
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="tourfecto-connect.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * GET /api/website-optimizer/edge/php-connector?website_id=
     * ملف ربط لمواقع PHP المبرمجة خصيصًا (نفس عمل إضافة WordPress).
     */
    public function phpConnector(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        $site = $this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $this->uid()]);
        if (empty($site)) {
            return $this->error('Not found', 404);
        }
        $code = EdgeRules::phpConnector($websiteId, $this->appUrl());
        header('Content-Type: application/x-httpd-php; charset=utf-8');
        header('Content-Disposition: attachment; filename="tourfecto-connect.php"');
        header('Content-Length: ' . strlen($code));
        header('Cache-Control: no-store');
        echo $code;
        exit;
    }

    /**
     * POST /api/website-optimizer/edge/php-auto-install - المنصة تركّب ملف الربط بنفسها
     * ببيانات FTP (بتتستخدم مرة واحدة ومش بتتحفظ ولا بتتسجل).
     */
    public function phpAutoInstall(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        $websiteId = (int) $this->get('website_id', 0);
        $site = $this->db->query('SELECT id, main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $this->uid()]);
        if (empty($site)) {
            return $this->error('Not found', 404);
        }
        // العنوان بأي شكل: ftp://1.2.3.4:21 أو 1.2.3.4 أو اسم الموقع. ولو اتكتب مسار
        // فولدر (/www/wwwroot/...) بالغلط بناخده كفولدر الموقع ونستخدم دومين الموقع.
        $raw = trim((string) $this->get('host', ''));
        $path = trim((string) $this->get('path', ''));
        $port = (int) $this->get('port', 21);
        $siteHost = (string) parse_url(WordPressConnector::normalizeSiteUrl((string) $site[0]['main_url']), PHP_URL_HOST);
        $host = preg_replace('#^(s?ftps?://)#i', '', $raw);
        if ($host === '' || $host[0] === '/' || $host[0] === '~') {
            if ($host !== '' && $path === '') {
                $path = $host;
            }
            $host = $siteHost;
        } else {
            $host = explode('/', $host, 2)[0];
            if (preg_match('/^(.+):(\d{2,5})$/', $host, $m)) {
                $host = $m[1];
                $port = (int) $m[2];
            }
        }
        $host = strtolower(trim($host, " .\t"));
        $user = trim((string) $this->get('username', ''));
        $pass = (string) $this->get('password', '');
        if ($user === '' || $pass === '') {
            return $this->error('اكتب اسم المستخدم وكلمة السر بتوع الـ FTP', 422);
        }
        if (!PhpConnectorInstaller::publicHost($host)) {
            return $this->error('عنوان الـ FTP مش صحيح. هتلاقيه في لوحة الاستضافة في صفحة FTP مكتوب "FTP address" - مثال: ftp://123.45.67.89', 422);
        }
        $key = 'edge_ftp_install:' . $this->uid();
        $cache = new Cache();
        $tries = (int) $cache->get($key, 0);
        if ($tries >= 6) {
            return $this->error('حاولت كذا مرة - استنى 10 دقايق وجرّب تاني', 429);
        }
        $cache->set($key, $tries + 1, 600);
        @set_time_limit(120);
        $result = (new PhpConnectorInstaller())->install(
            ['host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass, 'path' => $path],
            WordPressConnector::normalizeSiteUrl((string) $site[0]['main_url']),
            EdgeRules::phpConnector($websiteId, $this->appUrl())
        );
        Logger::info('PHP connector auto-install', ['website_id' => $websiteId, 'ok' => $result['ok'], 'root' => $result['root'] ?? null]);
        if ($result['ok'] && class_exists('SeoExpertService')) {
            try {
                (new SeoExpertService($this->db))->retryAfterConnector($this->uid(), $websiteId);
            } catch (Throwable $e) {
                // التعديلات هتتعاد مع أول "اختبار الربط" أو الدورة الجاية
            }
        }
        return $result['ok'] ? $this->success(['root' => $result['root'] ?? ''], $result['message']) : $this->error($result['message'], 422);
    }

    // ------------------------------------------------------------------ معاينة

    public function preview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $fix = $this->loadFix((int) ($params['id'] ?? 0));
        if (!$fix) {
            return $this->error('Not found', 404);
        }
        $res = $this->buildValue($fix, $this->pageContext()->fetch($this->targetUrl($fix)));
        if (!$res['success']) {
            return $this->error($res['error'], 422, ['reason' => $res['error']]);
        }
        $cap = FixCapability::forCheck($fix['check_key']);
        return $this->success(['field' => $cap['field'], 'label' => $cap['label'], 'risk' => $cap['risk'], 'value' => $res['value'], 'warnings' => $res['warnings'], 'source' => $res['source'] ?? 'page']);
    }

    /** @return array{success:bool, value?:string, warnings?:array, error?:string, source?:string} */
    private function buildValue(array $fix, array $ctx): array
    {
        return (new FixValueBuilder($this->ai()))->build($fix, $ctx, $this->uid());
    }

    // ------------------------------------------------------------------ تنفيذ

    public function apply(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $fix = $this->loadFix((int) ($params['id'] ?? 0));
        if (!$fix) {
            return $this->error('Not found', 404);
        }
        $cap = FixCapability::forCheck($fix['check_key']);
        if ($cap === null) {
            return $this->error('not_automatable', 422, ['reason' => 'not_automatable']);
        }
        if ($cap['risk'] === 'high' && !$this->get('confirm_risk')) {
            return $this->error('risk_not_confirmed', 422, ['reason' => 'risk_not_confirmed']);
        }
        $value = $this->get('value');
        if (!is_string($value) || trim($value) === '') {
            $built = $this->buildValue($fix, $this->pageContext()->fetch($this->targetUrl($fix)));
            if (!$built['success']) {
                return $this->error($built['error'], 422, ['reason' => $built['error']]);
            }
            $value = $built['value'];
        }
        return $this->persist($fix, $cap, $value);
    }

    /** @return array API response */
    private function persist(array $fix, array $cap, string $value): array
    {
        $websiteId = (int) $fix['website_id'];
        $host = $this->siteHost(WordPressConnector::normalizeSiteUrl((string) $fix['main_url']));
        if ($websiteId <= 0 || $host === '') {
            return $this->error('website_missing', 422, ['reason' => 'website_missing']);
        }
        if (FixValueBuilder::looksLikePlaceholder($value)) {
            return $this->error('placeholder_value', 422, ['reason' => 'placeholder_value']);
        }
        $clean = EdgeRules::sanitize($cap['field'], $value, $host);
        if ($clean === null) {
            return $this->error('invalid_value', 422, ['reason' => 'invalid_value']);
        }
        $stored = is_array($clean) ? json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $clean;

        $ctx=$this->reportContext[$websiteId]??($this->reportContext[$websiteId]=$this->pageContext()->fetch($this->targetUrl($fix)));
        if(!empty($ctx['coming_soon']))return $this->error('coming_soon_page',422,['reason'=>'coming_soon_page']);
        if(empty($ctx['ok']))return $this->error('page_unreachable',422,['reason'=>'page_unreachable']);
        $pdo=$this->db->getConnection();$lock='edgefix:'.$websiteId.':'.$cap['field'];
        $statement=$pdo->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);
        if((int)$statement->fetchColumn()!==1)return $this->error('operation_in_progress',409);
        try{
            $pdo->beginTransaction();
        $old = $this->db->query(
            'SELECT injected_code FROM auto_seo_applied_fixes WHERE website_id = ? AND field_name = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
            [$websiteId, $cap['db_field']]
        );
        $mode = in_array($fix['auto_pilot_mode'] ?? '', ['off', 'conservative', 'balanced', 'aggressive'], true) ? $fix['auto_pilot_mode'] : 'conservative';

        // قاعدة واحدة فعّالة لكل حقل: نقفل القديمة ونضيف الجديدة (الجدول مفيهوش unique key)
        $this->db->query('UPDATE auto_seo_applied_fixes SET is_active = 0 WHERE website_id = ? AND field_name = ? AND is_active = 1', [$websiteId, $cap['db_field']]);
        $appliedId = $this->db->query(
            'INSERT INTO auto_seo_applied_fixes (website_id, user_id, fix_id, category, check_key, field_name, injected_code, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
            [$websiteId, $this->uid(), (int) $fix['id'], 'seo', $fix['check_key'], $cap['db_field'], $stored]
        );
        $logId = $this->db->query(
            "INSERT INTO auto_seo_change_log (website_id, user_id, audit_id, applied_fix_id, field_name, old_value, new_value, `trigger`, mode) VALUES (?, ?, ?, ?, ?, ?, ?, 'manual_click', ?)",
            [$websiteId, $this->uid(), (int) $fix['audit_id'], (int) $appliedId, $cap['db_field'], $old[0]['injected_code'] ?? null, $stored, $mode]
        );
        $this->db->query(
            "UPDATE wo_fixes SET status = 'applied', applied_by = 'edge', applied_at = NOW(), suggested_value = ? WHERE id = ?",
            [mb_substr($stored, 0, 65000), (int) $fix['id']]
        );
        $beforeValue=$ctx['values'][$cap['field']]??($cap['field']==='jsonld'?($ctx['schema']??[]):($cap['field']==='hsts'?($ctx['hsts']??''):($ctx['og']??[])));
        $this->db->query('INSERT INTO edge_fix_verifications(applied_fix_id,website_id,user_id,before_json,expected_value,state) VALUES(?,?,?,?,?,?)',[(int)$appliedId,$websiteId,$this->uid(),json_encode($beforeValue,JSON_UNESCAPED_UNICODE),$stored,'saved']);
        $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$statement=$pdo->prepare('SELECT RELEASE_LOCK(?)');$statement->execute([$lock]);}
        $this->log('AutoFix Applied', ['fix_id' => $fix['id'], 'field' => $cap['field'], 'website_id' => $websiteId]);

        $row = $this->connectionRow($websiteId);
        return $this->success([
            'id' => (int) $fix['id'], 'status' => 'applied', 'field' => $cap['field'], 'log_id' => (int) $logId,
            'connected' => $this->isConnected($row), 'verification_state'=>'saved', 'expected_value'=>$stored,
        ]);
    }

    public function rollback(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $fix = $this->loadFix((int) ($params['id'] ?? 0));
        if (!$fix) {
            return $this->error('Not found', 404);
        }
        $cap = FixCapability::forCheck($fix['check_key']);
        if ($cap === null) {
            return $this->error('not_automatable', 422);
        }
        $websiteId = (int) $fix['website_id'];
        $pdo=$this->db->getConnection();$lock='edgefix:'.$websiteId.':'.$cap['field'];$statement=$pdo->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);if((int)$statement->fetchColumn()!==1)return $this->error('operation_in_progress',409);
        $restored=false;
        try{$pdo->beginTransaction();
        $last = $this->db->query(
            'SELECT id FROM auto_seo_applied_fixes WHERE website_id = ? AND field_name = ? AND fix_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
            [$websiteId, $cap['db_field'], (int) $fix['id']]
        );
        if (empty($last)) {
            $pdo->rollBack();return $this->error('nothing_to_rollback', 404);
        }
        $this->db->query('UPDATE auto_seo_applied_fixes SET is_active = 0 WHERE id = ?', [(int) $last[0]['id']]);
        $this->db->query('UPDATE auto_seo_change_log SET rolled_back_at = NOW() WHERE applied_fix_id = ? AND rolled_back_at IS NULL', [(int) $last[0]['id']]);
        $this->db->query("UPDATE wo_fixes SET status = 'pending', applied_by = NULL, applied_at = NULL WHERE id = ?", [(int) $fix['id']]);
        $this->db->query("UPDATE edge_fix_verifications SET state='inactive',checked_at=NULL WHERE applied_fix_id=?",[(int)$last[0]['id']]);
        $previous=$this->db->query('SELECT a.id,a.fix_id FROM auto_seo_applied_fixes a WHERE a.website_id=? AND a.field_name=? AND a.id<? AND NOT EXISTS(SELECT 1 FROM auto_seo_change_log l WHERE l.applied_fix_id=a.id AND l.rolled_back_at IS NOT NULL) ORDER BY a.id DESC LIMIT 1',[$websiteId,$cap['db_field'],(int)$last[0]['id']]);
        if($previous){$this->db->query('UPDATE auto_seo_applied_fixes SET is_active=1 WHERE id=?',[(int)$previous[0]['id']]);$this->db->query("UPDATE edge_fix_verifications SET state='saved',checked_at=NULL,observed_json=NULL WHERE applied_fix_id=?",[(int)$previous[0]['id']]);$this->db->query("UPDATE wo_fixes SET status='applied',applied_by='edge' WHERE id=?",[(int)$previous[0]['fix_id']]);$restored=(int)$previous[0]['fix_id']===(int)$fix['id'];}
        $pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{$statement=$pdo->prepare('SELECT RELEASE_LOCK(?)');$statement->execute([$lock]);}
        $this->log('AutoFix Rolled Back', ['fix_id' => $fix['id'], 'website_id' => $websiteId]);
        return $this->success(['id' => (int) $fix['id'], 'status' => $restored?'applied':'pending', 'verification_state'=>'saved']);
    }

    // ------------------------------------------------------------------ تنفيذ الكل (المنخفض الخطورة)

    public function applyAll(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $auditId = (int) $this->get('audit_id', 0);
        $own = $this->db->query('SELECT a.id, a.website_id FROM wo_audits a WHERE a.id = ? AND a.user_id = ? LIMIT 1', [$auditId, $this->uid()]);
        if (empty($own)) {
            return $this->error('Not found', 404);
        }
        $fixes = $this->db->query(
            "SELECT f.id, f.audit_id, f.check_key, f.status, f.title, a.website_id, a.user_id, w.main_url, w.auto_pilot_mode
               FROM wo_fixes f INNER JOIN wo_audits a ON a.id = f.audit_id INNER JOIN websites w ON w.id = a.website_id AND w.user_id = a.user_id
              WHERE f.audit_id = ? AND f.status = 'pending' ORDER BY f.id ASC",
            [$auditId]
        );
        $ctx = null;
        $builder = new FixValueBuilder($this->ai());
        $results = [];
        foreach ($fixes as $fix) {
            $cap = FixCapability::forCheck($fix['check_key']);
            if ($cap === null || $cap['risk'] !== 'low' || $cap['field']==='robots') {
                continue;       // الخطير (HSTS) لازم تأكيد صريح لوحده
            }
            if ($ctx === null) {
                $ctx = $this->pageContext()->fetch($this->targetUrl($fix));
            }
            $built = $builder->build($fix, $ctx, $this->uid());
            if (!$built['success']) {
                $results[] = ['id' => (int) $fix['id'], 'check_key' => $fix['check_key'], 'ok' => false, 'reason' => $built['error']];
                continue;
            }
            $r = $this->persist($fix, $cap, $built['value']);
            $ok = !empty($r['success']);
            $results[] = ['id' => (int) $fix['id'], 'check_key' => $fix['check_key'], 'ok' => $ok, 'reason' => $ok ? null : ($r['details']['reason'] ?? $r['error'] ?? 'error')];
        }
        $row = $this->connectionRow((int) $own[0]['website_id']);
        return $this->success(['results' => $results, 'applied' => count(array_filter($results, function ($x) { return $x['ok']; })), 'connected' => $this->isConnected($row)]);
    }

    /** Read-only approved changes. Public-site fetching is a separate explicit action. */
    public function report(array $params=[]): array
    {
        header('Cache-Control: no-store, private');
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        $wid=(int)$this->get('website_id');
        if(!$this->db->query('SELECT id FROM websites WHERE id=? AND user_id=?',[$wid,$this->uid()]))return $this->error('Not found',404);
        $rows=$this->db->query("SELECT a.id,a.fix_id,a.check_key,a.field_name,a.injected_code,a.is_active,v.before_json,v.expected_value,v.state,v.observed_json,v.reason,v.checked_at FROM auto_seo_applied_fixes a LEFT JOIN edge_fix_verifications v ON v.applied_fix_id=a.id WHERE a.website_id=? AND a.user_id=? ORDER BY a.id DESC LIMIT 50",[$wid,$this->uid()]);
        $reverse=array_flip(FixCapability::DB_FIELD);
        foreach($rows as &$row){$field=$reverse[$row['field_name']]??'';$row['field']=$field;$row['benefit']=PageContext::benefit($field);$row['state']=!(int)$row['is_active']?'inactive':($row['state']?:'saved');$row['expected_value']=$row['expected_value']??$row['injected_code'];$row['before']=json_decode($row['before_json']??'null',true);$row['observed']=json_decode($row['observed_json']??'null',true);unset($row['injected_code'],$row['before_json'],$row['observed_json']);}
        return $this->success(['changes'=>$rows,'scope'=>'Public homepage. A saved value is not a verified live result.']);
    }
    public function verifyFix(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if($error=$this->rateLimitGuard('user','edge_verify',12,60))return $error;
        $fix=$this->loadFix((int)($params['id']??0));if(!$fix)return $this->error('Not found',404);
        $cap=FixCapability::forCheck($fix['check_key']);if(!$cap)return $this->error('not_automatable',422);
        $rows=$this->db->query('SELECT id,injected_code FROM auto_seo_applied_fixes WHERE website_id=? AND fix_id=? AND is_active=1 ORDER BY id DESC LIMIT 1',[(int)$fix['website_id'],(int)$fix['id']]);if(!$rows)return $this->error('No active approval to verify',404);
        $row=$rows[0];$ctx=$this->pageContext()->fetch($this->targetUrl($fix));
        if($cap['field']==='llms_txt'){$parts=parse_url($this->targetUrl($fix));$textUrl=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').'/llms.txt';$ctx['llms_body']=$this->pageContext()->fetchText($textUrl);}
        $result=PageContext::verifyValue($cap['field'],(string)$row['injected_code'],$ctx);
        if(!$this->db->query('SELECT id FROM auto_seo_applied_fixes WHERE id=? AND is_active=1',[(int)$row['id']]))return $this->error('Approval changed during verification. Please retry.',409);
        $this->db->query('INSERT INTO edge_fix_verifications(applied_fix_id,website_id,user_id,expected_value,state,observed_json,reason,checked_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE expected_value=VALUES(expected_value),state=VALUES(state),observed_json=VALUES(observed_json),reason=VALUES(reason),checked_at=UTC_TIMESTAMP()',[(int)$row['id'],(int)$fix['website_id'],$this->uid(),$row['injected_code'],$result['state'],json_encode($result['observed'],JSON_UNESCAPED_UNICODE),$result['reason']]);
        return $this->success($result);
    }

}
