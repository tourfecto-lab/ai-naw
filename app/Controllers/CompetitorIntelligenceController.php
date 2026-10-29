<?php

/**
 * Tourfecto - Competitor Intelligence Controller
 * @version 1.0.0
 *
 * موديول واحد موحّد يغطي: Discovery, Tracking, Monitoring, Analysis,
 * Benchmarking, Change Detection (عبر MonitoringEngine), Alerts,
 * Market Intelligence, AI Insights, Reports. لا يعيد بناء أو يكرر
 * /competitor-monitoring القديم (تتبع سعر/عرض يدوي بسيط) - يبقى شغالاً
 * كما هو لأي عميل يستخدمه بالفعل.
 *
 * عزل الـ Tenant: كل استعلام هنا مفلتر بـ user_id = $this->user['id']
 * دائمًا. أي وصول لمنافس/تنبيه/تقرير يتحقق أولاً إنه ملك المستخدم
 * الحالي عبر assertCompetitorOwnership() قبل أي عملية.
 */
class CompetitorIntelligenceController extends Controller
{
    /** GET /competitor-intelligence */
    public function index(array $params = []): array
    {
        $body = $this->renderShell();
        $script = $this->renderScript();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('competitor_intelligence', $this->tr('ci.page.title'), $this->tr('ci.page.subtitle'), $body, $script);
        exit;
    }

    // ============================================================
    // Dashboard
    // ============================================================

    /** GET /api/competitor-intelligence/dashboard */
    public function apiDashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        $this->seedFromWebsites($userId);

        // 6 استعلامات COUNT + Activity Feed على كل فتح للـ Dashboard - Cache
        // قصير (60 ثانية) بيقلل الحمل على الداتابيز لو المستخدم فاتح
        // الصفحة وسايبها (بولينج متكرر)، من غير ما البيانات تبقى قديمة
        // بشكل ملحوظ. مفتاح الكاش مربوط بالـ user_id فمفيش تسريب بيانات
        // بين المستخدمين.
        $cacheKey = "ci_dashboard:{$userId}";
        $cache = class_exists('Cache') ? new Cache() : null;

        $build = function () use ($userId) {
            $totalCompetitors = (int) ($this->db->query("SELECT COUNT(*) c FROM competitors WHERE user_id = ?", [$userId])[0]['c'] ?? 0);
            $activeCompetitors = (int) ($this->db->query("SELECT COUNT(*) c FROM competitors WHERE user_id = ? AND is_active = 1 AND monitoring_paused = 0", [$userId])[0]['c'] ?? 0);
            $newChanges7d = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_changes WHERE user_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$userId])[0]['c'] ?? 0);
            $highAlerts = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_alerts WHERE user_id = ? AND severity IN ('high','critical') AND is_read = 0", [$userId])[0]['c'] ?? 0);
            $threats = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_insights WHERE user_id = ? AND type = 'threat' AND status = 'new'", [$userId])[0]['c'] ?? 0);
            $opportunities = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_insights WHERE user_id = ? AND type = 'opportunity' AND status = 'new'", [$userId])[0]['c'] ?? 0);
            $recentActivity = (new CompetitorTrackingService())->getActivityFeed($userId, 10);

            // اتجاه التغييرات آخر 14 يوم (لرسم بياني في الـ Dashboard) - مجمّع
            // بالخطورة عشان الرسم يوضح مش بس العدد الكلي.
            $trendRows = $this->db->query(
                "SELECT DATE(detected_at) AS d, severity, COUNT(*) AS c FROM ci_changes
                 WHERE user_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY DATE(detected_at), severity ORDER BY d ASC",
                [$userId]
            );
            $changesTrend = [];
            for ($i = 13; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-{$i} days"));
                $changesTrend[$day] = ['date' => $day, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
            }
            foreach ($trendRows as $r) {
                if (isset($changesTrend[$r['d']])) {
                    $changesTrend[$r['d']][$r['severity']] = (int) $r['c'];
                }
            }

            return [
                'total_competitors' => $totalCompetitors,
                'active_competitors' => $activeCompetitors,
                'new_changes_7d' => $newChanges7d,
                'high_priority_alerts' => $highAlerts,
                'threats' => $threats,
                'opportunities' => $opportunities,
                'recent_activity' => $recentActivity,
                'changes_trend' => array_values($changesTrend),
            ];
        };

        $data = $cache ? $cache->remember($cacheKey, $build, 60) : $build();

        return $this->success($data);
    }

    // ============================================================
    // Competitors: CRUD + Discovery + Profile + Check Now + Timeline
    // ============================================================

    /** GET /api/competitor-intelligence/competitors */
    public function apiListCompetitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        $this->seedFromWebsites($userId);

        $category = (string) $this->get('category', '');
        $search = (string) $this->get('search', '');
        [$page, $perPage, $offset] = $this->paginationParams();
        $sort = $this->sortClause($this->get('sort', 'created_at'), $this->get('order', 'desc'), [
            'created_at', 'competitor_name', 'category', 'last_change_at', 'last_monitored_at',
        ], 'created_at');

        $sql = "SELECT * FROM competitors WHERE user_id = ?";
        $args = [$userId];
        if ($category !== '') {
            $sql .= " AND category = ?";
            $args[] = $category;
        }
        if ($search !== '') {
            $sql .= " AND (competitor_name LIKE ? OR competitor_domain LIKE ?)";
            $args[] = "%{$search}%";
            $args[] = "%{$search}%";
        }

        $totalRows = $this->db->query("SELECT COUNT(*) c FROM ({$sql}) t", $args);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $sql .= " ORDER BY {$sort} LIMIT ? OFFSET ?";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $this->db->query($sql, $args);
        return $this->success(['competitors' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/competitors */
    public function apiAddCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_ADD)) {
            return $this->error('Forbidden', 403);
        }
        if (!$this->validate(['website_id' => 'required', 'competitor_name' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        if (mb_strlen((string) $this->get('competitor_name')) > 255) {
            return $this->error($this->tr('ci.error.input_too_long'), 422);
        }

        $domain = trim((string) $this->get('competitor_domain', ''));
        if ($domain !== '') {
            $normalized = CompetitorDomain::normalizeSafe($domain);
            if ($normalized === null) {
                return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
            }
            $domain = $normalized;
        }

        // نجيب تفضيلات المستخدم الافتراضية (لو ضبطها من تاب Settings) بدل
        // قيم ثابتة مكتوبة بالكود - لو لسه مفيش تفضيلات، نستخدم افتراضيات معقولة.
        $defaults = $this->userDefaults((int) $this->user['id']);

        $competitor = new Competitor([
            'user_id' => (int) $this->user['id'],
            'website_id' => (int) $this->get('website_id'),
            'competitor_name' => (string) $this->get('competitor_name'),
            'competitor_domain' => $domain,
            'notes' => (string) $this->get('notes', ''),
            'industry' => $this->get('industry') ?: null,
            'country' => $this->get('country') ?: null,
            'market_segment' => $this->get('market_segment') ?: null,
            'category' => CiConstants::within(CiConstants::CATEGORIES, $this->get('category'), 'direct'),
            'source' => 'manual',
            'monitoring_frequency' => CiConstants::within(CiConstants::FREQUENCIES, $this->get('monitoring_frequency'), $defaults['frequency']),
            'is_active' => 1,
        ]);
        $competitor->save();

        // إضافة تلقائية لقائمة المتابعة بإعدادات المستخدم الافتراضية
        $watchlist = new CiWatchlistItem([
            'user_id' => (int) $this->user['id'],
            'competitor_id' => (int) $competitor->getAttribute('id'),
            'priority' => 'medium',
            'alert_min_severity' => $defaults['min_severity'],
            'alert_channels' => $defaults['channels'],
            'is_paused' => 0,
        ]);
        $watchlist->save();

        ActivityLog::record('competitor_intelligence', 'competitor.added', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
        ]);
        $this->invalidateDashboardCache((int) $this->user['id']);

        return $this->success(['competitor' => $competitor->toArray()], $this->tr('common.added'), 201);
    }

    /**
     * POST /api/competitor-intelligence/competitors/bulk-import
     * Body: { website_id, rows: [{name, domain, industry, country, category}, ...] }
     * كل صف يُعامَل زي apiAddCompetitor بالظبط (نفس الفحص/نفس الإعدادات
     * الافتراضية) - الفرق الوحيد إنه بيعالج مجموعة صفوف مرة واحدة
     * ويرجّع تقرير نجاح/فشل واضح لكل صف بدل ما يفشل كله لأول خطأ.
     * سقف 200 صف لكل استدعاء لمنع استعلامات ضخمة داخل request واحد.
     */
    public function apiBulkImportCompetitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_ADD)) {
            return $this->error('Forbidden', 403);
        }
        if (!$this->validate(['website_id' => 'required', 'rows' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        $userId = (int) $this->user['id'];
        $websiteId = (int) $this->get('website_id');
        $rows = (array) $this->get('rows');
        if (count($rows) > CiConstants::BULK_IMPORT_MAX_ROWS) {
            return $this->error($this->tr('ci.error.bulk_import_too_many_rows'), 422);
        }

        $existingDomains = array_map(function ($r) {
            return CompetitorDomain::host((string) $r['competitor_domain']) ?? strtolower((string) $r['competitor_domain']);
        }, $this->db->query("SELECT competitor_domain FROM competitors WHERE user_id = ?", [$userId]));

        $defaults = $this->userDefaults($userId);

        $added = 0;
        $skipped = 0;
        $results = [];

        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $domain = trim((string) ($row['domain'] ?? ''));

            if ($name === '') {
                $results[] = ['row' => $i + 1, 'status' => 'error', 'reason' => 'missing_name'];
                $skipped++;
                continue;
            }
            if (mb_strlen($name) > 255) {
                $results[] = ['row' => $i + 1, 'status' => 'error', 'reason' => 'name_too_long', 'name' => $name];
                $skipped++;
                continue;
            }

            if ($domain !== '') {
                $normalized = CompetitorDomain::normalizeSafe($domain);
                if ($normalized === null) {
                    $results[] = ['row' => $i + 1, 'status' => 'error', 'reason' => 'invalid_or_unsafe_url', 'name' => $name];
                    $skipped++;
                    continue;
                }
                $host = CompetitorDomain::host($normalized) ?? $domain;
                if (in_array($host, $existingDomains, true)) {
                    $results[] = ['row' => $i + 1, 'status' => 'skipped', 'reason' => 'already_exists', 'name' => $name];
                    $skipped++;
                    continue;
                }
                $existingDomains[] = $host;
                $domain = $normalized;
            }

            $competitor = new Competitor([
                'user_id' => $userId, 'website_id' => $websiteId, 'competitor_name' => $name, 'competitor_domain' => $domain,
                'industry' => $row['industry'] ?? null, 'country' => $row['country'] ?? null,
                'category' => CiConstants::within(CiConstants::CATEGORIES, $row['category'] ?? '', 'direct'),
                'source' => 'bulk_import', 'monitoring_frequency' => $defaults['frequency'], 'is_active' => 1,
            ]);
            $competitor->save();

            (new CiWatchlistItem([
                'user_id' => $userId, 'competitor_id' => (int) $competitor->getAttribute('id'), 'priority' => 'medium',
                'alert_min_severity' => $defaults['min_severity'], 'alert_channels' => $defaults['channels'], 'is_paused' => 0,
            ]))->save();

            $results[] = ['row' => $i + 1, 'status' => 'added', 'name' => $name, 'competitor_id' => (int) $competitor->getAttribute('id')];
            $added++;
        }

        ActivityLog::record('competitor_intelligence', 'competitor.bulk_imported', [
            'user_id' => $userId, 'subject_type' => 'competitors', 'subject_id' => 0,
            'meta' => ['added' => $added, 'skipped' => $skipped, 'total_rows' => count($rows)],
        ]);
        $this->invalidateDashboardCache($userId);

        return $this->success(['added' => $added, 'skipped' => $skipped, 'results' => $results], $this->tr('ci.bulk_import.done'));
    }

    /** PUT /api/competitor-intelligence/competitors/{id} */
    public function apiUpdateCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_EDIT)) {
            return $this->error('Forbidden', 403);
        }

        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $before = $competitor->toArray();
        foreach (['competitor_name', 'notes', 'industry', 'country', 'market_segment', 'category', 'monitoring_frequency', 'monitoring_paused'] as $field) {
            if ($this->get($field) !== null) {
                $competitor->setAttribute($field, $this->get($field));
            }
        }
        $competitor->save();

        ActivityLog::record('competitor_intelligence', 'competitor.updated', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
            'meta' => ['before' => $before, 'after' => $competitor->toArray()],
        ]);

        return $this->success(['competitor' => $competitor->toArray()], $this->tr('common.updated'));
    }

    /** DELETE /api/competitor-intelligence/competitors/{id} */
    public function apiDeleteCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_DELETE)) {
            return $this->error('Forbidden', 403);
        }

        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        ActivityLog::record('competitor_intelligence', 'competitor.deleted', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
            'meta' => ['before' => $competitor->toArray()],
        ]);

        $competitor->delete();
        $this->invalidateDashboardCache((int) $this->user['id']);
        return $this->success([], $this->tr('common.deleted'));
    }

    /** GET /api/competitor-intelligence/competitors/{id} */
    public function apiCompetitorProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $competitorId = (int) $competitor->getAttribute('id');
        $latestSnapshots = $this->db->query(
            "SELECT s1.* FROM ci_snapshots s1
             INNER JOIN (SELECT page_type, MAX(captured_at) AS max_date FROM ci_snapshots WHERE competitor_id = ? GROUP BY page_type) s2
             ON s1.page_type = s2.page_type AND s1.captured_at = s2.max_date
             WHERE s1.competitor_id = ?",
            [$competitorId, $competitorId]
        );
        $recentChanges = $this->db->query("SELECT * FROM ci_changes WHERE competitor_id = ? ORDER BY detected_at DESC LIMIT 20", [$competitorId]);
        $insights = $this->db->query("SELECT * FROM ci_insights WHERE competitor_id = ? ORDER BY created_at DESC LIMIT 20", [$competitorId]);
        $scorecard = $this->db->query("SELECT * FROM ci_scorecards WHERE competitor_id = ? ORDER BY computed_at DESC LIMIT 1", [$competitorId]);

        return $this->success([
            'competitor' => $competitor->toArray(),
            'latest_snapshots' => $latestSnapshots,
            'recent_changes' => $recentChanges,
            'insights' => $insights,
            'scorecard' => $scorecard[0] ?? null,
        ]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/check-now */
    public function apiCheckNow(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_MONITORING)) {
            return $this->error('Forbidden', 403);
        }

        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        // حد بسيط: مش أكتر من دورة يدوية كل 5 دقايق لنفس المنافس، لمنع
        // استخدام "Check Now" كوسيلة Flooding لموقع المنافس.
        $lastChecked = $competitor->getAttribute('last_monitored_at');
        if ($lastChecked && strtotime($lastChecked) > (time() - 300)) {
            return $this->error($this->tr('ci.error.check_now_rate_limited'), 429);
        }

        $engine = new MonitoringEngine();
        $result = $engine->monitor($competitor);
        $this->invalidateDashboardCache((int) $this->user['id']);

        return $this->success(['result' => $result], $this->tr('ci.monitoring.cycle_done'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/timeline */
    public function apiTimeline(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $timeline = (new CompetitorTrackingService())->getTimeline((int) $competitor->getAttribute('id'), (int) $this->get('months', 12));
        return $this->success(['timeline' => $timeline]);
    }

    // ============================================================
    // Discovery
    // ============================================================

    /** POST /api/competitor-intelligence/discovery/suggest */
    public function apiDiscoverySuggest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'competitor_name' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        // نفس فحص أمان الـ URL اللي بيتم على "أضف منافس" - المرشح اللي
        // هيتم اعتماده هيبقى competitor_domain بعدين، فالخيط لازم يبان
        // آمن من البداية (وليس وقت الاعتماد فقط).
        $website = (string) $this->get('website', '');
        if ($website !== '' && CompetitorDomain::normalizeSafe($website) === null) {
            return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
        }

        $service = new CompetitorDiscoveryService();
        $candidate = $service->suggestManualCandidate(
            (int) $this->user['id'],
            (int) $this->get('website_id'),
            (string) $this->get('competitor_name'),
            (string) $this->get('website', ''),
            (string) $this->get('industry', ''),
            (string) $this->get('country', '')
        );
        return $this->success(['candidate' => $candidate->toArray()], $this->tr('common.added'), 201);
    }

    /** POST /api/competitor-intelligence/discovery/run */
    public function apiDiscoveryRun(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        if (($limited = $this->assertRateLimit('discovery_run')) !== null) {
            return $limited;
        }

        $websiteId = (int) $this->get('website_id');
        $industry = $this->get('industry');
        $country = $this->get('country');

        // لو الصفحة مبعتتش industry/country صراحة، نجيبهم من بيانات
        // الموقع نفسه (اتملوا وقت الـ onboarding) بدل ما نطلب من المستخدم
        // يدخلهم يدويًا كل مرة.
        if (!$industry || !$country) {
            $siteRows = $this->db->query("SELECT industry_niche AS industry, target_country FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, (int) $this->user['id']]);
            if (!empty($siteRows)) {
                $industry = $industry ?: $siteRows[0]['industry'];
                $country = $country ?: $siteRows[0]['target_country'];
            }
        }

        // مصادر مُسجَّلة بترتيب الأولوية: onboarding (مجاني، بيانات المستخدم
        // نفسه) ثم Google Places. مفتاح Google Places بيتحل جوه المصدر نفسه
        // (لوحة الأدمن أولًا، واحتياطيًا .env) - لو مفيش مفتاح في أي منهم،
        // المصدر بيرجع available=false بسبب واضح بدل ما يفشل بصمت.
        $sources = [new WebsiteOnboardingDiscoverySource(), new GooglePlacesDiscoverySource()];

        $service = new CompetitorDiscoveryService($sources);
        $result = $service->runExternalDiscovery((int) $this->user['id'], $websiteId, [
            'industry' => $industry, 'country' => $country,
        ]);
        return $this->success($result);
    }

    /** GET /api/competitor-intelligence/discovery */
    public function apiDiscoveryList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $rows = $this->db->query("SELECT * FROM ci_discovery_candidates WHERE user_id = ? ORDER BY discovered_at DESC", [(int) $this->user['id']]);
        return $this->success(['candidates' => $rows]);
    }

    /** POST /api/competitor-intelligence/discovery/{id}/approve */
    public function apiDiscoveryApprove(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $candidate = $this->assertDiscoveryOwnership((int) ($params['id'] ?? 0));
        if (!$candidate) {
            return $this->error('Not found', 404);
        }

        $competitor = (new CompetitorDiscoveryService())->approveCandidate($candidate);
        return $this->success(['competitor' => $competitor->toArray()], $this->tr('common.added'));
    }

    /** POST /api/competitor-intelligence/discovery/{id}/dismiss */
    public function apiDiscoveryDismiss(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $candidate = $this->assertDiscoveryOwnership((int) ($params['id'] ?? 0));
        if (!$candidate) {
            return $this->error('Not found', 404);
        }

        (new CompetitorDiscoveryService())->dismissCandidate($candidate);
        return $this->success([], $this->tr('common.updated'));
    }

    // ============================================================
    // Watchlist
    // ============================================================

    /** GET /api/competitor-intelligence/watchlist */
    public function apiWatchlist(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $rows = $this->db->query(
            "SELECT w.*, c.competitor_name, c.competitor_domain, c.category, c.last_change_at
             FROM ci_watchlist w JOIN competitors c ON c.id = w.competitor_id
             WHERE w.user_id = ? ORDER BY w.priority DESC, w.created_at DESC",
            [(int) $this->user['id']]
        );
        return $this->success(['watchlist' => $rows]);
    }

    /** POST /api/competitor-intelligence/watchlist */
    public function apiWatchlistUpsert(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_ALERTS)) {
            return $this->error('Forbidden', 403);
        }
        if (!$this->validate(['competitor_id' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        $competitor = $this->assertCompetitorOwnership((int) $this->get('competitor_id'));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $existing = (new CiWatchlistItem())->where(['user_id' => (int) $this->user['id'], 'competitor_id' => (int) $competitor->getAttribute('id')], [], 1);
        $item = $existing[0] ?? new CiWatchlistItem(['user_id' => (int) $this->user['id'], 'competitor_id' => (int) $competitor->getAttribute('id')]);
        $before = $item->toArray();
        $wasPaused = (int) ($before['is_paused'] ?? 0);

        if ($this->get('priority')) {
            $item->setAttribute('priority', $this->get('priority'));
        }
        if ($this->get('alert_min_severity')) {
            $item->setAttribute('alert_min_severity', $this->get('alert_min_severity'));
        }
        if ($this->get('alert_channels')) {
            $item->setAttribute('alert_channels', json_encode($this->get('alert_channels')));
        }
        if ($this->get('keyword_filters') !== null) {
            $item->setAttribute('keyword_filters', json_encode(array_values(array_filter((array) $this->get('keyword_filters')))));
        }
        if ($this->get('is_paused') !== null) {
            $item->setAttribute('is_paused', (int) $this->get('is_paused'));
        }
        $item->save();

        $nowPaused = (int) $item->getAttribute('is_paused');
        $action = $nowPaused !== $wasPaused
            ? ($nowPaused ? 'watchlist.paused' : 'watchlist.resumed')
            : 'watchlist.updated';
        ActivityLog::record('competitor_intelligence', $action, [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'ci_watchlist', 'subject_id' => (int) $item->getAttribute('id'),
            'meta' => ['before' => $before, 'after' => $item->toArray()],
        ]);

        return $this->success(['watchlist_item' => $item->toArray()], $this->tr('common.updated'));
    }

    /** DELETE /api/competitor-intelligence/watchlist/{competitorId} */
    public function apiWatchlistRemove(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitorId = (int) ($params['id'] ?? 0);
        $competitor = $this->assertCompetitorOwnership($competitorId);
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $this->db->query("DELETE FROM ci_watchlist WHERE user_id = ? AND competitor_id = ?", [(int) $this->user['id'], $competitorId]);

        ActivityLog::record('competitor_intelligence', 'watchlist.removed', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => $competitorId,
        ]);

        return $this->success([], $this->tr('common.deleted'));
    }

    // ============================================================
    // Activity Feed / Comparison / Alerts / Insights / AI / Reports
    // ============================================================

    /** GET /api/competitor-intelligence/activity */
    public function apiActivityFeed(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $feed = (new CompetitorTrackingService())->getActivityFeed((int) $this->user['id'], (int) $this->get('limit', 50));
        return $this->success(['activity' => $feed]);
    }

    /** POST /api/competitor-intelligence/comparison */
    public function apiComparison(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'competitor_ids' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $ids = array_map('intval', (array) $this->get('competitor_ids'));
        // نتأكد كل المنافسين المطلوب مقارنتهم ملك نفس المستخدم
        $owned = $this->db->query(
            "SELECT id FROM competitors WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
            array_merge([(int) $this->user['id']], $ids)
        );
        $ownedIds = array_map(fn ($r) => (int) $r['id'], $owned);

        $comparison = (new BenchmarkingService())->compare((int) $this->get('website_id'), $ownedIds, (int) $this->get('days', 90));
        return $this->success($comparison);
    }

    /**
     * POST /api/competitor-intelligence/comparison/export
     * نفس بيانات المقارنة لكن بصيغة CSV جاهزة للتنزيل (ميزة تقارير
     * Excel اللي بتقدمها Prisync). بنرجّع النص كـ JSON والواجهة بتحوّله
     * لملف تحميل عبر Blob - مفيش حاجة تتخزن على السيرفر.
     */
    public function apiComparisonExport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'competitor_ids' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $ids = array_map('intval', (array) $this->get('competitor_ids'));
        $owned = $this->db->query(
            "SELECT id FROM competitors WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
            array_merge([(int) $this->user['id']], $ids)
        );
        $ownedIds = array_map(fn ($r) => (int) $r['id'], $owned);
        if (empty($ownedIds)) {
            return $this->error('Not found', 404);
        }

        $comparison = (new BenchmarkingService())->compare((int) $this->get('website_id'), $ownedIds, (int) $this->get('days', 90));

        $rows = $comparison['rows'] ?? [];
        $labels = array_column($rows, 'label');
        $metrics = ['website_presence', 'content_activity', 'offer_activity', 'product_service_coverage', 'market_position_signals'];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['metric'], $labels));

        foreach ($metrics as $metric) {
            $line = [$metric];
            foreach ($rows as $row) {
                $value = $row[$metric] ?? '';
                $line[] = is_array($value) ? implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($value), array_values($value))) : (string) $value;
            }
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->success([
            'filename' => 'comparison-' . date('Y-m-d') . '.csv',
            'csv' => $csv,
        ]);
    }

    /** GET /api/competitor-intelligence/competitors/{id}/price-history */
    public function apiPriceHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $rows = $this->db->query(
            "SELECT id, change_type, page_type, severity, price_before, price_after, currency, source_url, detected_at
             FROM ci_changes
             WHERE competitor_id = ? AND user_id = ? AND (price_before IS NOT NULL OR price_after IS NOT NULL)
             ORDER BY detected_at DESC LIMIT 200",
            [(int) $competitor->getAttribute('id'), (int) $this->user['id']]
        );
        return $this->success(['price_history' => $rows]);
    }

    /** GET /api/competitor-intelligence/alerts */
    public function apiAlerts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $onlyUnread = $this->get('unread') === '1';
        [$page, $perPage, $offset] = $this->paginationParams();

        $sql = "SELECT a.*, c.competitor_name, c.competitor_domain FROM ci_alerts a JOIN competitors c ON c.id = a.competitor_id WHERE a.user_id = ?";
        $args = [(int) $this->user['id']];
        if ($onlyUnread) {
            $sql .= " AND a.is_read = 0";
        }

        $totalRows = $this->db->query("SELECT COUNT(*) c FROM ({$sql}) t", $args);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
        $args[] = $perPage;
        $args[] = $offset;

        return $this->success(['alerts' => $this->db->query($sql, $args), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/alerts/{id}/read */
    public function apiMarkAlertRead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->db->query("UPDATE ci_alerts SET is_read = 1 WHERE id = ? AND user_id = ?", [(int) ($params['id'] ?? 0), (int) $this->user['id']]);
        return $this->success([], $this->tr('common.updated'));
    }

    /** POST /api/competitor-intelligence/alerts/read-all */
    public function apiMarkAllAlertsRead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->db->query("UPDATE ci_alerts SET is_read = 1 WHERE user_id = ? AND is_read = 0", [(int) $this->user['id']]);
        return $this->success([], $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/alerts/unread-count */
    public function apiUnreadAlertsCount(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $count = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_alerts WHERE user_id = ? AND is_read = 0", [(int) $this->user['id']])[0]['c'] ?? 0);
        return $this->success(['unread_count' => $count]);
    }

    /** POST /api/competitor-intelligence/insights/{id}/status - body: {status: 'reviewed'|'dismissed'|'new'} */
    public function apiInsightStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $status = CiConstants::within(CiConstants::INSIGHT_STATUSES, (string) $this->get('status'), '');
        if ($status === '') {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        // رؤية معزولة بالمستخدم (tenant isolation) - محدش يعدّل رؤية حد تاني.
        $id = (int) ($params['id'] ?? 0);
        $rows = $this->db->query("SELECT id FROM ci_insights WHERE id = ? AND user_id = ? LIMIT 1", [$id, (int) $this->user['id']]);
        if (empty($rows)) {
            return $this->error('Not found', 404);
        }

        $this->db->query("UPDATE ci_insights SET status = ? WHERE id = ? AND user_id = ?", [$status, $id, (int) $this->user['id']]);
        return $this->success(['status' => $status], $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/insights */
    public function apiInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $type = (string) $this->get('type', '');
        [$page, $perPage, $offset] = $this->paginationParams();

        $sql = "SELECT i.*, c.competitor_name, c.competitor_domain FROM ci_insights i LEFT JOIN competitors c ON c.id = i.competitor_id WHERE i.user_id = ?";
        $args = [(int) $this->user['id']];
        if (in_array($type, ['insight', 'threat', 'opportunity', 'recommendation'], true)) {
            $sql .= " AND i.type = ?";
            $args[] = $type;
        }

        $totalRows = $this->db->query("SELECT COUNT(*) c FROM ({$sql}) t", $args);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
        $args[] = $perPage;
        $args[] = $offset;

        return $this->success(['insights' => $this->db->query($sql, $args), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/scan-insights */
    public function apiScanInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        if (($limited = $this->assertRateLimit('ai_insights')) !== null) {
            return $limited;
        }

        $insights = (new ThreatOpportunityService())->scanCompetitor($competitor, (int) $this->get('days', 30));
        return $this->success(['insights' => array_map(fn ($i) => $i->toArray(), $insights)]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/analyze-profile */
    public function apiAnalyzeProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        if (($limited = $this->assertRateLimit('ai_profile')) !== null) {
            return $limited;
        }

        $result = (new AICompetitiveAnalyst())->analyzeProfile($competitor);
        return $this->success($result);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/compute-scorecard */
    public function apiComputeScorecard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $scorecard = (new BenchmarkingService())->computeScorecard((int) $competitor->getAttribute('id'), (int) $this->get('days', 30));
        return $this->success(['scorecard' => $scorecard->toArray()], $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/scorecard-trend */
    public function apiScorecardTrend(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }

        $rows = $this->db->query(
            "SELECT computed_at, visibility_score, content_activity_score, offer_activity_score, product_coverage_score, market_presence_score, basis
             FROM ci_scorecards WHERE competitor_id = ? ORDER BY computed_at ASC LIMIT 52",
            [(int) $competitor->getAttribute('id')]
        );
        return $this->success(['trend' => $rows]);
    }

    /** GET /api/competitor-intelligence/competitors/{id}/keyword-rankings (G1) */
    public function apiKeywordRankings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        $limit = (int) $this->get('limit', 200);
        $limit = $limit > 0 ? min(500, $limit) : 200;
        $rankings = (new KeywordRankingService())->listRankings((int) $competitor->getAttribute('id'), $limit);
        return $this->success(['keyword_rankings' => $rankings]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/keyword-rankings - body: {keyword, position?, url?, source?} (G1) */
    public function apiRecordKeywordRanking(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        if (!$this->validate(['keyword' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        $positionRaw = $this->get('position');
        $position = ($positionRaw === null || $positionRaw === '') ? null : (int) $positionRaw;
        $url = trim((string) $this->get('url', ''));
        if ($url !== '' && mb_strlen($url) > 1000) {
            return $this->error($this->tr('ci.error.input_too_long'), 422);
        }
        $source = trim((string) $this->get('source', 'manual'));
        if ($source === '' || mb_strlen($source) > 100) {
            $source = 'manual';
        }

        $result = (new KeywordRankingService())->recordRanking(
            (int) $competitor->getAttribute('id'),
            (string) $this->get('keyword'),
            $position,
            $url !== '' ? $url : null,
            $source
        );
        if (!$result['success']) {
            return $this->error($result['error'] === 'invalid_keyword' ? $this->tr('ci.js.name_required') : $this->tr('ci.js.failed'), 422);
        }
        return $this->success(['ranking' => $result['ranking']], $this->tr('common.added'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/keyword-rankings/history?keyword= (G1) */
    public function apiKeywordRankingHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        $keyword = trim((string) $this->get('keyword', ''));
        if ($keyword === '') {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $limit = (int) $this->get('limit', 200);
        $limit = $limit > 0 ? min(500, $limit) : 200;
        $history = (new KeywordRankingService())->history((int) $competitor->getAttribute('id'), $keyword, $limit);
        return $this->success(['keyword' => $keyword, 'history' => $history]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/keyword-rankings/check (G1 - scheduled SERP check via configured source) */
    public function apiKeywordRankingCheck(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        if (($limited = $this->assertRateLimit('keyword_rankings_check')) !== null) {
            return $limited;
        }

        $domain = CompetitorDomain::normalizeSafe((string) $competitor->getAttribute('competitor_domain'));
        if ($domain === null) {
            return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
        }

        // الكلمات المفحوصة: من جدول competitor_keywords (المصدر الفعلي
        // للكلمات المرصودة) + أي كلمات مسجلة يدويًا في ci_keyword_rankings.
        $keywords = [];
        foreach ($this->db->query("SELECT keyword FROM competitor_keywords WHERE competitor_id = ? LIMIT 100", [(int) $competitor->getAttribute('id')]) as $k) {
            $keywords[] = (string) $k['keyword'];
        }
        foreach ($this->db->query(
            "SELECT DISTINCT keyword FROM ci_keyword_rankings WHERE competitor_id = ? LIMIT 100",
            [(int) $competitor->getAttribute('id')]
        ) as $k) {
            $keywords[] = (string) $k['keyword'];
        }
        $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));

        $result = (new KeywordRankingService())->runScheduledCheck((int) $competitor->getAttribute('id'), $domain, $keywords);
        if (!$result['available']) {
            return $this->success([
                'available' => false,
                'reason' => $result['reason'],
                'recorded' => 0,
                'results' => [],
            ], $this->tr('ci.js.discovery_insufficient'));
        }
        return $this->success($result, $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/product-prices (G7) */
    public function apiProductPrices(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        $limit = (int) $this->get('limit', 200);
        $limit = $limit > 0 ? min(500, $limit) : 200;
        $products = (new ProductPriceTrackerService())->listProducts((int) $competitor->getAttribute('id'), $limit);
        return $this->success(['products' => $products]);
    }

    /** GET /api/competitor-intelligence/competitors/{id}/product-prices/history?product= (G7) */
    public function apiProductPriceHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        $product = trim((string) $this->get('product', ''));
        if ($product === '') {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $limit = (int) $this->get('limit', 100);
        $limit = $limit > 0 ? min(500, $limit) : 100;
        $history = (new ProductPriceTrackerService())->history((int) $competitor->getAttribute('id'), $product, $limit);
        return $this->success(['product' => $product, 'history' => $history]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/product-prices - body: {product_name, price, currency?, url?} (G7 manual record) */
    public function apiRecordProductPrice(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        if (!$this->validate(['product_name' => 'required', 'price' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $price = (float) $this->get('price');
        if (!is_finite($price) || $price <= 0) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        $result = (new ProductPriceTrackerService())->recordPrice(
            (int) $competitor->getAttribute('id'),
            (string) $this->get('product_name'),
            $price,
            trim((string) $this->get('currency', 'USD')) ?: 'USD',
            trim((string) $this->get('url', '')) ?: null
        );
        if (!$result['success']) {
            return $this->error($this->tr('ci.js.failed'), 422);
        }
        return $this->success(['product_price' => $result['product_price']], $this->tr('common.added'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/battlecard (G6) */
    public function apiBattlecard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        $battlecard = (new BattlecardService())->latest((int) $this->user['id'], (int) $competitor->getAttribute('id'));
        return $this->success(['battlecard' => $battlecard]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/battlecard/generate (G6) */
    public function apiGenerateBattlecard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('Not found', 404);
        }
        if (($limited = $this->assertRateLimit('battlecard_generate')) !== null) {
            return $limited;
        }

        $result = (new BattlecardService())->generate((int) $this->user['id'], (int) $competitor->getAttribute('id'));
        if (!$result['success']) {
            if (!$result['available']) {
                return $this->success(['available' => false, 'reason' => $result['error']], $this->tr('ci.js.discovery_insufficient'));
            }
            return $this->error($result['error'] ?? $this->tr('ci.js.failed'), 422);
        }
        return $this->success(['battlecard' => $result['battlecard']], $this->tr('common.updated'));
    }

    /** POST /api/competitor-intelligence/ai/ask */
    public function apiAiAsk(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['question' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        if (mb_strlen((string) $this->get('question')) > 2000) {
            return $this->error($this->tr('ci.error.input_too_long'), 422);
        }
        if (($limited = $this->assertRateLimit('ai_ask')) !== null) {
            return $limited;
        }

        $result = (new AICompetitiveAnalyst())->ask((int) $this->user['id'], (string) $this->get('question'), (int) $this->get('days', 30));
        return $this->success($result);
    }

    /** GET /api/competitor-intelligence/ai/weekly-summary */
    public function apiAiWeeklySummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        if (($limited = $this->assertRateLimit('ai_weekly_summary')) !== null) {
            return $limited;
        }

        $result = (new AICompetitiveAnalyst())->weeklySummary((int) $this->user['id'], (int) $this->get('website_id'));
        return $this->success($result);
    }

    /** GET /api/competitor-intelligence/reports */
    public function apiListReports(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        [$page, $perPage, $offset] = $this->paginationParams();

        $total = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_reports WHERE user_id = ?", [(int) $this->user['id']])[0]['c'] ?? 0);
        $rows = $this->db->query(
            "SELECT id, type, title, period_start, period_end, generated_at FROM ci_reports WHERE user_id = ? ORDER BY generated_at DESC LIMIT ? OFFSET ?",
            [(int) $this->user['id'], $perPage, $offset]
        );
        return $this->success(['reports' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/reports */
    public function apiGenerateReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_EXPORT)) {
            return $this->error('Forbidden', 403);
        }
        if (!$this->validate(['website_id' => 'required', 'type' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        $competitorId = $this->get('competitor_id') ? (int) $this->get('competitor_id') : null;
        if ($competitorId && !$this->assertCompetitorOwnership($competitorId)) {
            return $this->error('Not found', 404);
        }

        if (($limited = $this->assertRateLimit('report_generate')) !== null) {
            return $limited;
        }

        try {
            $report = (new ReportService())->generate((int) $this->user['id'], (int) $this->get('website_id'), (string) $this->get('type'), [], $competitorId);
            return $this->success(['report' => $report->toArray()], $this->tr('ci.report.generated'), 201);
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** GET /api/competitor-intelligence/reports/{id} */
    public function apiGetReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $rows = $this->db->query("SELECT * FROM ci_reports WHERE id = ? AND user_id = ? LIMIT 1", [(int) ($params['id'] ?? 0), (int) $this->user['id']]);
        if (empty($rows)) {
            return $this->error('Not found', 404);
        }

        $report = $rows[0];
        $report['content'] = json_decode($report['content_json'], true);
        unset($report['content_json']);
        return $this->success(['report' => $report]);
    }

    // ============================================================
    // Settings (تفضيلات افتراضية + معلومات الصلاحيات/التكاملات)
    // ============================================================

    /** GET /api/competitor-intelligence/settings */
    public function apiGetSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        $rows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $rows[0] ?? null;

        $googlePlacesAvailable = false;
        if (class_exists('SystemSettingsService')) {
            $key = (new SystemSettingsService())->get('google_maps_api_key', defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
            $googlePlacesAvailable = $key !== '';
        }

        return $this->success([
            'preferences' => $prefs ? $prefs->toArray() : [
                'default_monitoring_frequency' => 'weekly',
                'default_alert_min_severity' => 'medium',
                'default_alert_channels' => json_encode(['dashboard']),
                'webhook_url' => null,
                'slack_webhook_url' => null,
                'weekly_digest_enabled' => 0,
            ],
            'ci_role' => CiPermissions::ciRole($this->user),
            'permissions' => [
                CiPermissions::PERM_VIEW, CiPermissions::PERM_ADD, CiPermissions::PERM_EDIT, CiPermissions::PERM_DELETE,
                CiPermissions::PERM_MANAGE_MONITORING, CiPermissions::PERM_MANAGE_ALERTS, CiPermissions::PERM_EXPORT, CiPermissions::PERM_MANAGE_SETTINGS,
            ],
            'granted_permissions' => array_values(array_filter([
                CiPermissions::PERM_VIEW, CiPermissions::PERM_ADD, CiPermissions::PERM_EDIT, CiPermissions::PERM_DELETE,
                CiPermissions::PERM_MANAGE_MONITORING, CiPermissions::PERM_MANAGE_ALERTS, CiPermissions::PERM_EXPORT, CiPermissions::PERM_MANAGE_SETTINGS,
            ], fn ($p) => CiPermissions::can($this->user, $p))),
            'integrations' => [
                'google_places_discovery' => $googlePlacesAvailable,
                'ai_analyst' => defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '',
                'email_alerts' => defined('MAIL_HOST') && MAIL_HOST !== '',
            ],
        ]);
    }

    /** PUT /api/competitor-intelligence/settings */
    public function apiUpdateSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_SETTINGS)) {
            return $this->error('Forbidden', 403);
        }

        $userId = (int) $this->user['id'];
        $rows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $rows[0] ?? new CiUserPreference(['user_id' => $userId]);

        if (in_array($this->get('default_monitoring_frequency'), CiConstants::FREQUENCIES, true)) {
            $prefs->setAttribute('default_monitoring_frequency', $this->get('default_monitoring_frequency'));
        }
        if (in_array($this->get('default_alert_min_severity'), CiConstants::SEVERITIES, true)) {
            $prefs->setAttribute('default_alert_min_severity', $this->get('default_alert_min_severity'));
        }
        if ($this->get('default_alert_channels')) {
            $prefs->setAttribute('default_alert_channels', json_encode($this->get('default_alert_channels')));
        }
        if ($this->get('webhook_url') !== null) {
            $url = trim((string) $this->get('webhook_url'));
            if ($url !== '' && !SsrfGuard::isSafe($url)) {
                return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
            }
            $prefs->setAttribute('webhook_url', $url ?: null);
        }
        if ($this->get('slack_webhook_url') !== null) {
            $url = trim((string) $this->get('slack_webhook_url'));
            if ($url !== '' && !SsrfGuard::isSafe($url)) {
                return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
            }
            $prefs->setAttribute('slack_webhook_url', $url ?: null);
        }
        if ($this->get('weekly_digest_enabled') !== null) {
            $prefs->setAttribute('weekly_digest_enabled', (int) $this->get('weekly_digest_enabled') === 1 ? 1 : 0);
        }
        $prefs->save();

        ActivityLog::record('competitor_intelligence', 'settings.updated', [
            'user_id' => $userId, 'subject_type' => 'ci_user_preferences', 'subject_id' => (int) $prefs->getAttribute('id'),
        ]);

        return $this->success(['preferences' => $prefs->toArray()], $this->tr('common.updated'));
    }

    /** POST /api/competitor-intelligence/settings/pause-all */
    public function apiPauseAllMonitoring(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_SETTINGS)) {
            return $this->error('Forbidden', 403);
        }

        $userId = (int) $this->user['id'];
        $pause = (int) $this->get('pause', 1) === 1 ? 1 : 0;
        $this->db->query("UPDATE competitors SET monitoring_paused = ? WHERE user_id = ?", [$pause, $userId]);

        ActivityLog::record('competitor_intelligence', $pause ? 'monitoring.paused_all' : 'monitoring.resumed_all', [
            'user_id' => $userId, 'subject_type' => 'competitors', 'subject_id' => 0,
        ]);

        return $this->success([], $this->tr('common.updated'));
    }

    /**
     * GET /competitor-intelligence/reports/{id}/export?format=pdf|html|csv
     *
     * format=pdf|html (default): نفس أسلوب AIController::exportReport()
     * بالظبط - صفحة HTML بتصميم طباعة نظيف بتفتح ديالوج الطباعة
     * تلقائيًا، والعميل يختار "Save as PDF" من المتصفح نفسه. مفيش أي
     * مكتبة PDF على السيرفر (نفس سبب القرار الموجود بالفعل في
     * AIController - السيرفر مفيهوش SSH لتثبيت مكتبات Composer إضافية).
     *
     * format=csv: تصدير جدول بيانات خام (التغييرات أو الرؤى حسب نوع
     * التقرير) - مفيد لفتحه في Excel/Google Sheets مباشرة، منفصل عن
     * صفحة الطباعة/PDF.
     */
    public function exportReportPrintable(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $reportId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query("SELECT * FROM ci_reports WHERE id = ? AND user_id = ? LIMIT 1", [$reportId, (int) $this->user['id']]);

        if (empty($rows)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(404);
            echo 'Report not found';
            exit;
        }

        $report = $rows[0];
        $content = json_decode($report['content_json'], true) ?: [];

        if ((string) $this->get('format', 'html') === 'csv') {
            $this->streamReportCsv($report, $content);
            exit;
        }

        $html = $this->renderReportPrintableHtml($report, $content);

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private function streamReportCsv(array $report, array $content): void
    {
        $filename = 'competitor-intelligence-' . preg_replace('/[^a-z0-9\-]+/i', '-', (string) $report['type']) . '-' . date('Ymd') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM عشان Excel يقرأ العربي صح

        if (isset($content['changes']) && is_array($content['changes'])) {
            fputcsv($out, ['Competitor', 'Page', 'Change Type', 'Severity', 'Confidence', 'Detected At', 'Source URL']);
            foreach ($content['changes'] as $c) {
                fputcsv($out, [$c['competitor'] ?? '', $c['page_type'] ?? '', $c['change_type'] ?? '', $c['severity'] ?? '', $c['confidence'] ?? '', $c['detected_at'] ?? '', $c['source_url'] ?? '']);
            }
        } elseif (isset($content['items']) && is_array($content['items'])) {
            fputcsv($out, ['Competitor', 'Title', 'Description', 'Confidence', 'Threat Level', 'Recommended Action', 'Created At']);
            foreach ($content['items'] as $i) {
                fputcsv($out, [$i['competitor'] ?? '', $i['title'] ?? '', $i['description'] ?? '', $i['confidence'] ?? '', $i['threat_level'] ?? '', $i['recommended_action'] ?? '', $i['created_at'] ?? '']);
            }
        } else {
            // تقرير profile أو أي شكل تاني - نصدّر JSON كخلية واحدة كحل أخير بدل ملف فاضي
            fputcsv($out, ['content_json']);
            fputcsv($out, [json_encode($content, JSON_UNESCAPED_UNICODE)]);
        }

        fclose($out);
    }

    private function renderReportPrintableHtml(array $report, array $content): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $title = $esc($report['title']);
        $period = $esc(($report['period_start'] ?: '') . ($report['period_end'] ? ' → ' . $report['period_end'] : ''));
        $generatedAt = $esc($report['generated_at']);

        $bodyHtml = '<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;">' . $esc(json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

        // لو التقرير من نوع weekly/monthly/change عندنا شكل أوضح (جدول تغييرات) بدل JSON خام
        if (isset($content['changes']) && is_array($content['changes'])) {
            $rowsHtml = '';
            foreach ($content['changes'] as $c) {
                $rowsHtml .= '<tr><td>' . $esc($c['competitor'] ?? '') . '</td><td>' . $esc($c['page_type'] ?? '') . '</td><td>' . $esc($c['change_type'] ?? '') . '</td><td>' . $esc($c['severity'] ?? '') . '</td><td>' . $esc($c['detected_at'] ?? '') . '</td></tr>';
            }
            $bodyHtml = '<p><strong>Total changes:</strong> ' . $esc($content['total_changes'] ?? count($content['changes'])) . '</p>'
                . '<table style="width:100%;border-collapse:collapse;" border="1" cellpadding="6"><thead><tr><th>Competitor</th><th>Page</th><th>Change</th><th>Severity</th><th>Detected</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>';
        } elseif (isset($content['items']) && is_array($content['items'])) {
            $rowsHtml = '';
            foreach ($content['items'] as $i) {
                $rowsHtml .= '<div style="margin-bottom:14px;padding:10px;border:1px solid #ddd;"><strong>' . $esc($i['title'] ?? '') . '</strong> (' . $esc($i['competitor'] ?? '') . ')<br>'
                    . $esc($i['description'] ?? '') . '<br><em>Confidence: ' . $esc($i['confidence'] ?? '') . '</em></div>';
            }
            $bodyHtml = $rowsHtml ?: '<p>No items in this period.</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
body { font-family: Arial, Tahoma, sans-serif; padding: 30px; color: #222; }
h1 { font-size: 20px; margin-bottom: 4px; }
.meta { color: #777; font-size: 13px; margin-bottom: 20px; }
table { width: 100%; }
th { background: #f4f4f4; text-align: left; }
@media print { .no-print { display: none; } }
</style>
<script>window.addEventListener("load", () => setTimeout(() => window.print(), 400));</script>
</head>
<body>
<h1>{$title}</h1>
<div class="meta">Period: {$period} &middot; Generated: {$generatedAt} &middot; Tourfecto Competitor Intelligence</div>
{$bodyHtml}
</body>
</html>
HTML;
    }

    // ============================================================
    // Ownership helpers (Tenant Isolation)
    // ============================================================

    // ============================================================
    // Pagination / Sorting helpers
    // ============================================================

    /** يفضّي كاش الـ Dashboard لنفس المستخدم بعد أي عملية بتغيّر أرقامه (إضافة/حذف/فحص فوري) */
    private function invalidateDashboardCache(int $userId): void
    {
        if (class_exists('Cache')) {
            (new Cache())->delete("ci_dashboard:{$userId}");
        }
    }

    /**
     * بجيب تفضيلات المستخدم الافتراضية من ci_user_preferences (لو ضبطها من
     * تاب Settings) بقيم افتراضية معقولة لو مفيش تفضيلات محفوظة بعد. مستخدم
     * في نفس الصيغة من 3 أماكن (add + bulk import + المقارنة) - هنا في
     * مكان واحد عشان القيم الافتراضية متتكررش ولا تتحرف.
     * @return array{frequency:string, min_severity:string, channels:string}
     */
    /**
     * المنافسين اللي العميل كتبهم في بيانات موقعه بيتضافوا هنا لوحدهم (مرة واحدة) -
     * كانت الصفحة بتبدأ بـ "0 منافسين" والعميل يدخلهم تاني. لو مسحهم بعد كده مش بيرجعوا.
     */
    private function seedFromWebsites(int $userId): void
    {
        try {
            if ($this->db->query('SELECT 1 FROM competitors WHERE user_id = ? LIMIT 1', [$userId])) {
                return;
            }
            if ((new ActivityLog())->where(['user_id' => $userId, 'module' => 'competitor_intelligence', 'action' => 'competitor.seeded'], [], 1)) {
                return;
            }
            $sites = $this->db->query('SELECT id, competitor_1_url, competitor_2_url, competitor_3_url FROM websites WHERE user_id = ? ORDER BY id LIMIT 10', [$userId]);
            $defaults = $this->userDefaults($userId);
            $added = 0;
            $candidates = 0;
            foreach ($sites as $site) {
                foreach (['competitor_1_url', 'competitor_2_url', 'competitor_3_url'] as $col) {
                    if (trim((string) ($site[$col] ?? '')) !== '') {
                        $candidates++;
                    }
                    $domain = CompetitorDomain::normalizeSafe(trim((string) ($site[$col] ?? '')));
                    if ($domain === null || $domain === '') {
                        continue;
                    }
                    $competitor = new Competitor([
                        'user_id' => $userId, 'website_id' => (int) $site['id'],
                        'competitor_name' => preg_replace('#^www\.#i', '', (string) (parse_url((strpos($domain, '://') ? '' : 'https://') . $domain, PHP_URL_HOST) ?: $domain)),
                        'competitor_domain' => $domain, 'notes' => '', 'category' => 'direct', 'source' => 'website_settings',
                        'monitoring_frequency' => $defaults['frequency'], 'is_active' => 1,
                    ]);
                    $competitor->save();
                    (new CiWatchlistItem([
                        'user_id' => $userId, 'competitor_id' => (int) $competitor->getAttribute('id'), 'priority' => 'medium',
                        'alert_min_severity' => $defaults['min_severity'], 'alert_channels' => $defaults['channels'], 'is_paused' => 0,
                    ]))->save();
                    $added++;
                }
            }
            // نعلّم إنها اتعملت بس لو اتضاف حاجة أو مفيش منافسين أصلًا (فشل مؤقت في فحص الرابط يتعاد المرة الجاية)
            if ($added > 0 || $candidates === 0) {
                ActivityLog::record('competitor_intelligence', 'competitor.seeded', ['user_id' => $userId, 'meta' => ['added' => $added]]);
            }
            if ($added) {
                $this->invalidateDashboardCache($userId);
            }
        } catch (Throwable $e) {
            Logger::warning('Competitor seeding skipped', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function userDefaults(int $userId): array
    {
        $prefRows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $prefRows[0] ?? null;
        return [
            'frequency' => $prefs ? (string) $prefs->getAttribute('default_monitoring_frequency') : 'weekly',
            'min_severity' => $prefs ? (string) $prefs->getAttribute('default_alert_min_severity') : 'medium',
            'channels' => $prefs && $prefs->getAttribute('default_alert_channels') ? (string) $prefs->getAttribute('default_alert_channels') : json_encode(['dashboard']),
        ];
    }

    /**
     * حارس rate limit على الـ endpoints المكلفة - بيدا اتنين: رسالة خطأ
     * (429) لو العدد اتعدّى، أو null لو متاح. بتشتغل لكل مستخدم لوحده
     * (key معزول بالـ user_id) - مفيش أي مستخدم بيأثر على حدود غيره.
     */
    private function assertRateLimit(string $scope): ?array
    {
        $result = CiRateLimiter::hit($scope, 'user:' . (int) $this->user['id']);
        if (!$result['allowed']) {
            return $this->error($this->tr('ci.error.rate_limited'), 429);
        }
        return null;
    }

    /** @return array{0:int,1:int,2:int} [page, per_page, offset] */
    private function paginationParams(): array
    {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = (int) $this->get('per_page', 20);
        $perPage = $perPage > 0 ? min(100, $perPage) : 20; // سقف 100 لكل صفحة لمنع استعلامات ثقيلة
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * يبني ORDER BY آمن - يقبل بس أعمدة من whitelist صريح، بيمنع أي
     * SQL Injection عبر اسم عمود الترتيب المُرسَل من الواجهة.
     */
    private function sortClause(string $requestedColumn, string $requestedOrder, array $allowedColumns, string $defaultColumn): string
    {
        $column = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : $defaultColumn;
        $order = strtolower($requestedOrder) === 'asc' ? 'ASC' : 'DESC';
        return "`{$column}` {$order}";
    }

    private function assertCompetitorOwnership(int $id): ?Competitor
    {
        if ($id <= 0) {
            return null;
        }
        $competitor = (new Competitor())->find($id);
        if (!$competitor || (int) $competitor->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $competitor;
    }

    private function assertDiscoveryOwnership(int $id): ?CiDiscoveryCandidate
    {
        if ($id <= 0) {
            return null;
        }
        $candidate = (new CiDiscoveryCandidate())->find($id);
        if (!$candidate || (int) $candidate->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $candidate;
    }

    // ============================================================
    // Page shell (HTML) + client script (JS)
    // ============================================================

    // ============================================================
    // Professional UI: SVG icon sprite (Lucide-style, stroke-based)
    // مصدر واحد للأيقونات (sprite) يستخدمه كل من الهيكل (PHP) و
    // السكربت (JS) عبر <use href="#ci-icon-..."> - من غير تكرار paths.
    // ============================================================

    /** @var array<string,string> Lucide-style glyph bodies (24x24 grid). */
    private const CI_ICONS = [
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'radar' => '<circle cx="12" cy="12" r="10"/><path d="M12 12 19 5"/><path d="M12 12V2"/><path d="M12 12 5 19"/>',
        'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'trending' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'sparkles' => '<path d="M12 3l1.9 5.8a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-1.9a2 2 0 0 0 1.3-1.3z"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'refresh' => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
        'play' => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'file' => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
        'lightbulb' => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.4 1 2.3h6c0-.9.4-1.8 1-2.3A7 7 0 0 0 12 2z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    ];

    /** Renders a reference to a sprite symbol: <svg class="ci-ico [cls]"><use .../></svg> */
    private function ciIcon(string $name, string $cls = ''): string
    {
        if (!isset(self::CI_ICONS[$name])) {
            return '';
        }
        $class = trim('ci-ico ' . $cls);
        return '<svg class="' . $class . '" aria-hidden="true" focusable="false"><use href="#ci-icon-' . $name . '"></use></svg>';
    }

    /** Renders the hidden SVG symbol sprite (single source of truth for both PHP + JS). */
    private function ciSprite(): string
    {
        $symbols = '';
        foreach (self::CI_ICONS as $name => $body) {
            $symbols .= '<symbol id="ci-icon-' . $name . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</symbol>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">' . $symbols . '</svg>';
    }

    private function renderShell(): string
    {
        $tabDefs = [
            'dashboard' => ['chart', $this->tr('ci.tab.dashboard')],
            'competitors' => ['users', $this->tr('ci.tab.competitors')],
            'discovery' => ['radar', $this->tr('ci.tab.discovery')],
            'watchlist' => ['bell', $this->tr('ci.tab.watchlist')],
            'activity' => ['activity', $this->tr('ci.tab.activity')],
            'comparison' => ['trending', $this->tr('ci.tab.comparison')],
            'alerts' => ['alert', $this->tr('ci.tab.alerts')],
            'insights' => ['sparkles', $this->tr('ci.tab.insights')],
            'reports' => ['file', $this->tr('ci.tab.reports')],
            'settings' => ['sliders', $this->tr('ci.tab.settings')],
        ];
        // 10 تبويبات في صف واحد كانت كتير على العميل: الأساسية ظاهرة والباقي تحت "المزيد"
        $secondary = ['discovery', 'watchlist', 'comparison', 'insights'];
        $tabButtons = '';
        $moreButtons = '';
        foreach ($tabDefs as $key => [$icon, $label]) {
            $button = '<button type="button" class="p-tab ci-tab-btn" id="ciTab-' . $key . '" data-tab="' . $key . '" role="tab" aria-selected="false" onclick="ciSwitchTab(\'' . $key . '\')">'
                . $this->ciIcon($icon) . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></button>';
            if (in_array($key, $secondary, true)) {
                $moreButtons .= $button;
            } else {
                $tabButtons .= $button;
            }
        }
        $moreLabel = current_lang() === 'ar' ? 'المزيد' : 'More';
        $tabButtons .= '<details class="ci-more"><summary class="p-tab">' . $moreLabel . ' ▾</summary><div class="ci-more-menu">' . $moreButtons . '</div></details>';

        $tHint = $this->tr('ci.how.body');

        return $this->ciSprite() . <<<HTML
<style>
    .ci-panel { display: none; }
    .ci-more { position: relative; }
    .ci-more > summary { list-style: none; cursor: pointer; }
    .ci-more > summary::-webkit-details-marker { display: none; }
    .ci-more.has-active > summary { background: var(--panel-accent-light); color: var(--panel-accent); }
    .ci-more-menu { position: absolute; inset-inline-start: 0; top: calc(100% + 6px); z-index: 30; display: grid; gap: 4px; min-width: 200px; padding: 6px; border: 1px solid var(--panel-border); border-radius: 12px; background: var(--panel-card-bg, #fff); box-shadow: 0 10px 30px rgba(0,0,0,.12); }
    .ci-more-menu .p-tab { justify-content: flex-start; width: 100%; }
    .ci-panel.active { display: block; }
    .ci-ico { width: 16px; height: 16px; flex-shrink: 0; vertical-align: middle; }
    .ci-ico.lg { width: 20px; height: 20px; }
    .p-tab { display: inline-flex; align-items: center; gap: 6px; }
    .p-tab .ci-ico { opacity: .7; }
    .p-tab.active .ci-ico { opacity: 1; }
    .ci-head-ico { width: 46px; height: 46px; border-radius: 13px; background: var(--panel-accent-light); color: var(--panel-accent); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ci-head-ico .ci-ico { width: 22px; height: 22px; }
    .ci-ico-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--panel-border); background: var(--panel-card-bg); color: var(--panel-text-muted); cursor: pointer; transition: var(--transition-fast); }
    .ci-ico-btn:hover { border-color: var(--panel-accent); color: var(--panel-accent); }
    .ci-ico-btn.danger:hover { border-color: var(--panel-danger); color: var(--panel-danger); }
    .pill.critical { background: var(--panel-danger); color: #fff; }
    .ci-compare-card { display: flex; align-items: center; gap: 10px; border: 1px solid var(--panel-border); background: var(--panel-card-bg); border-radius: 10px; padding: 10px 12px; cursor: pointer; transition: var(--transition-fast); }
    .ci-compare-card:hover { border-color: var(--panel-accent); }
    .ci-compare-card:has(input:checked) { border-color: var(--panel-accent); background: var(--panel-accent-light); }
    .ci-compare-card.selected { border-color: var(--panel-accent); background: var(--panel-accent-light); }
    .ci-compare-card input { accent-color: var(--panel-accent); flex-shrink: 0; }
    .ci-compare-card .ci-ico { color: var(--panel-text-muted); }
    .ci-settings-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; border: 1px solid var(--panel-border); background: var(--panel-card-bg-2); cursor: pointer; font-size: 12.5px; font-weight: 600; color: var(--panel-text-muted); transition: var(--transition-fast); }
    .ci-settings-chip input { accent-color: var(--panel-accent); margin: 0; }
    .ci-settings-chip:has(input:checked) { border-color: var(--panel-accent); color: var(--panel-text); background: var(--panel-accent-light); }
    .ci-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--panel-border); display: inline-block; flex-shrink: 0; }
    .ci-dot.on { background: var(--panel-success); box-shadow: 0 0 0 3px var(--panel-success-light); }
    .ci-ai-answer { white-space: pre-wrap; font-size: 13.5px; line-height: 1.7; }
    .ci-profile-avatar { width: 44px; height: 44px; border-radius: 12px; background: var(--panel-accent-light); color: var(--panel-accent); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px; flex-shrink: 0; }
    .ci-profile-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 14px 0; }
    .ci-timeline-item { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; border-bottom: 1px dashed var(--panel-border); font-size: 12.5px; }
    .ci-timeline-item:last-child { border-bottom: none; }
    .ci-timeline-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 5px; background: var(--panel-text-muted); flex-shrink: 0; }
    .ci-timeline-item.sev-high .ci-timeline-dot, .ci-timeline-item.sev-critical .ci-timeline-dot { background: var(--panel-danger); }
    .ci-timeline-item.sev-medium .ci-timeline-dot { background: var(--panel-warning); }
    .ci-timeline-item.sev-low .ci-timeline-dot { background: var(--panel-info); }
    .ci-timeline-item.sev-info .ci-timeline-dot { background: var(--panel-info); }
    .ci-table-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .ci-form-note { font-size: 12px; color: var(--panel-text-muted); }
    .ci-avatar-initial { width: 34px; height: 34px; border-radius: 10px; background: var(--panel-accent-light); color: var(--panel-accent); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0; }
    :focus-visible { outline: 2px solid var(--panel-accent); outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.001s !important; transition-duration: 0.001s !important; } }
</style>

<div class="p-card" style="margin-bottom:16px;">
    <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
        <span class="ci-head-ico">{$this->ciIcon('radar')}</span>
        <div style="flex:1;min-width:240px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                <strong style="font-size:14px;">{$this->tr('ci.page.title')}</strong>

            </div>
            <div class="p-cell-muted">{$tHint}</div>
        </div>
    </div>
</div>

<div class="p-grid cols-4" id="ciStats">
    <div class="p-card stat-tile"><div class="stat-icon blue">{$this->ciIcon('users')}</div><div class="stat-info"><div class="stat-value" id="ciStatTotal">—</div><div class="stat-label">{$this->tr('ci.stat.total')}</div></div></div>
    <div class="p-card stat-tile"><div class="stat-icon green">{$this->ciIcon('radar')}</div><div class="stat-info"><div class="stat-value" id="ciStatActive">—</div><div class="stat-label">{$this->tr('ci.stat.active')}</div></div></div>
    <div class="p-card stat-tile"><div class="stat-icon orange">{$this->ciIcon('bell')}</div><div class="stat-info"><div class="stat-value" id="ciStatAlerts">—</div><div class="stat-label">{$this->tr('ci.stat.high_alerts')}</div></div></div>
    <div class="p-card stat-tile"><div class="stat-icon purple">{$this->ciIcon('zap')}</div><div class="stat-info"><div class="stat-value" id="ciStatChanges">—</div><div class="stat-label">{$this->tr('ci.stat.changes_7d')}</div></div></div>
</div>

<div class="p-tabs" id="ciTabBar" role="tablist" aria-label="{$this->tr('ci.page.title')}" style="margin:18px 0 16px;">{$tabButtons}</div>

<div class="ci-panel" id="ciPanel-dashboard" role="tabpanel" aria-labelledby="ciTab-dashboard">
    <div class="p-card" style="margin-bottom:16px;">
        <div class="p-card-head">
            <h3>{$this->tr('ci.dashboard.trend_title')}</h3>
            <span class="pill blue">14d</span>
        </div>
        <div style="height:230px;position:relative;"><canvas id="ciTrendChart" aria-label="{$this->tr('ci.dashboard.trend_title')}" role="img"></canvas></div>
    </div>
    <div class="p-grid cols-2">
        <div class="p-card">
            <div class="p-card-head"><h3 style="display:flex;align-items:center;gap:8px;">{$this->ciIcon('trending')} {$this->tr('ci.dashboard.threats_opportunities')}</h3></div>
            <div id="ciDashboardThreatsOpps" class="p-cell-muted"></div>
        </div>
        <div class="p-card">
            <div class="p-card-head"><h3 style="display:flex;align-items:center;gap:8px;">{$this->ciIcon('clock')} {$this->tr('ci.dashboard.recent_activity')}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="ciDashboardActivityTable"><tbody></tbody></table></div>
        </div>
    </div>
</div>

<div class="ci-panel" id="ciPanel-competitors" role="tabpanel" aria-labelledby="ciTab-competitors">
    <div class="p-toolbar">
        <input type="text" id="ciCompetitorSearch" class="p-input search" placeholder="{$this->tr('ci.competitors.search_placeholder')}" aria-label="{$this->tr('ci.competitors.search_placeholder')}">
        <select id="ciCompetitorCategoryFilter" class="p-select" aria-label="{$this->tr('ci.category.label')}">
            <option value="">{$this->tr('ci.category.all')}</option>
            <option value="direct">{$this->tr('ci.category.direct')}</option>
            <option value="indirect">{$this->tr('ci.category.indirect')}</option>
            <option value="emerging">{$this->tr('ci.category.emerging')}</option>
            <option value="potential">{$this->tr('ci.category.potential')}</option>
        </select>
        <span style="flex:1;"></span>
        <button class="p-btn primary xs" onclick="ciOpenAddCompetitor()">{$this->ciIcon('plus')} {$this->tr('ci.competitors.add_btn')}</button>
        <button class="p-btn outline xs" onclick="ciOpenBulkImport()">{$this->ciIcon('upload')} {$this->tr('ci.bulk_import.open_btn')}</button>
    </div>

    <div id="ciAddCompetitorForm" class="p-card" style="display:none;margin-bottom:12px;">
        <div class="p-card-head"><h3>{$this->tr('ci.competitors.add_btn')}</h3></div>
        <div class="p-grid cols-2">
            <input type="hidden" id="ciNewWebsiteId" value="">
            <div class="form-group"><label for="ciNewName" class="form-label">{$this->tr('ci.form.name')} *</label><input type="text" id="ciNewName" class="p-input" placeholder="{$this->tr('ci.form.name')}"></div>
            <div class="form-group"><label for="ciNewDomain" class="form-label">{$this->tr('ci.form.website')}</label><input type="text" id="ciNewDomain" class="p-input" dir="ltr" placeholder="example.com"></div>
            <div class="form-group"><label for="ciNewIndustry" class="form-label">{$this->tr('ci.form.industry')}</label><input type="text" id="ciNewIndustry" class="p-input" placeholder="{$this->tr('ci.form.industry')}"></div>
            <div class="form-group"><label for="ciNewCountry" class="form-label">{$this->tr('ci.form.country')}</label><input type="text" id="ciNewCountry" class="p-input" placeholder="{$this->tr('ci.form.country')}"></div>
            <div class="form-group"><label for="ciNewCategory" class="form-label">{$this->tr('ci.category.label')}</label><select id="ciNewCategory" class="p-select">
                <option value="direct">{$this->tr('ci.category.direct')}</option>
                <option value="indirect">{$this->tr('ci.category.indirect')}</option>
                <option value="emerging">{$this->tr('ci.category.emerging')}</option>
                <option value="potential">{$this->tr('ci.category.potential')}</option>
            </select></div>
            <div class="form-group"><label for="ciNewFrequency" class="form-label">{$this->tr('ci.settings.default_frequency')}</label><select id="ciNewFrequency" class="p-select">
                <option value="weekly">{$this->tr('ci.frequency.weekly')}</option>
                <option value="daily">{$this->tr('ci.frequency.daily')}</option>
            </select></div>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;">
            <button class="p-btn primary xs" onclick="ciSubmitAddCompetitor()">{$this->ciIcon('check')} {$this->tr('common.save')}</button>
            <button class="p-btn outline xs" onclick="ciToggleForm('ciAddCompetitorForm', false)">{$this->tr('common.close')}</button>
        </div>
    </div>

    <div id="ciBulkImportForm" class="p-card" style="display:none;margin-bottom:12px;">
        <div class="p-card-head"><h3>{$this->tr('ci.bulk_import.open_btn')}</h3></div>
        <p class="ci-form-note">{$this->tr('ci.bulk_import.hint')}</p>
        <textarea id="ciBulkImportText" class="p-input" rows="6" style="width:100%;" placeholder="Booking Rivals, bookingrivals.com, Travel, Egypt, direct&#10;Trip Genie, tripgenie.example, Travel, UAE, indirect"></textarea>
        <div style="margin-top:10px;display:flex;gap:8px;">
            <button class="p-btn primary xs" onclick="ciSubmitBulkImport()">{$this->ciIcon('upload')} {$this->tr('ci.bulk_import.submit_btn')}</button>
            <button class="p-btn outline xs" onclick="ciToggleForm('ciBulkImportForm', false)">{$this->tr('common.close')}</button>
        </div>
        <div id="ciBulkImportResult" style="margin-top:10px;"></div>
    </div>

    <div class="p-table-scroll"><table class="p-table" id="ciCompetitorsTable">
        <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.form.website')}</th><th>{$this->tr('ci.category.label')}</th><th>{$this->tr('ci.table.last_change')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
        <tbody></tbody>
    </table></div>
    <div id="ciCompetitorsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
</div>

<div class="ci-panel" id="ciPanel-discovery" role="tabpanel" aria-labelledby="ciTab-discovery">
    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head"><h3 style="display:flex;align-items:center;gap:8px;">{$this->ciIcon('radar')} {$this->tr('ci.tab.discovery')}</h3></div>
        <div class="p-grid cols-2">
            <input type="hidden" id="ciDiscWebsiteId" value="">
            <div class="form-group"><label for="ciDiscName" class="form-label">{$this->tr('ci.form.name')}</label><input type="text" id="ciDiscName" class="p-input" placeholder="{$this->tr('ci.form.name')}"></div>
            <div class="form-group"><label for="ciDiscWebsite" class="form-label">{$this->tr('ci.form.website')}</label><input type="text" id="ciDiscWebsite" class="p-input" dir="ltr" placeholder="example.com"></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="p-btn outline xs" onclick="ciSuggestCandidate()">{$this->ciIcon('plus')} {$this->tr('ci.discovery.suggest_btn')}</button>
            <button class="p-btn primary xs" onclick="ciRunDiscovery()">{$this->ciIcon('radar')} {$this->tr('ci.discovery.run_btn')}</button>
        </div>
        <div id="ciDiscoveryRunResult" class="p-cell-muted" style="margin-top:10px;"></div>
    </div>
    <div class="p-table-scroll"><table class="p-table" id="ciDiscoveryTable">
        <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.form.website')}</th><th>{$this->tr('ci.category.label')}</th><th>{$this->tr('ci.discovery.confidence')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
        <tbody></tbody>
    </table></div>
</div>

<div class="ci-panel" id="ciPanel-watchlist" role="tabpanel" aria-labelledby="ciTab-watchlist">
    <div class="p-table-scroll"><table class="p-table" id="ciWatchlistTable">
        <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.watchlist.priority')}</th><th>{$this->tr('ci.watchlist.min_severity')}</th><th>{$this->tr('ci.watchlist.keywords')}</th><th>{$this->tr('ci.table.status')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
        <tbody></tbody>
    </table></div>
</div>

<div class="ci-panel" id="ciPanel-activity" role="tabpanel" aria-labelledby="ciTab-activity">
    <div class="p-table-scroll"><table class="p-table" id="ciActivityTable">
        <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.table.event')}</th><th>{$this->tr('ci.table.severity')}</th><th>{$this->tr('ci.table.date')}</th></tr></thead>
        <tbody></tbody>
    </table></div>
</div>

<div class="ci-panel" id="ciPanel-comparison" role="tabpanel" aria-labelledby="ciTab-comparison">
    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head">
            <h3 style="display:flex;align-items:center;gap:8px;">{$this->ciIcon('trending')} {$this->tr('ci.tab.comparison')}</h3>
            <span class="pill gray" id="ciComparisonCount" style="display:none;"></span>
        </div>
        <div id="ciComparisonPicker" class="p-cell-muted"></div>
        <div style="margin-top:14px;">
            <button class="p-btn primary xs" id="ciRunComparisonBtn" onclick="ciRunComparison()">{$this->ciIcon('chart')} {$this->tr('ci.comparison.run_btn')}</button>
        </div>
    </div>
    <div class="p-card" id="ciComparisonChartCard" style="display:none;margin-bottom:12px;">
        <div style="height:240px;position:relative;"><canvas id="ciComparisonChart"></canvas></div>
    </div>
    <div id="ciComparisonResult"></div>
</div>

<div class="ci-panel" id="ciPanel-alerts" role="tabpanel" aria-labelledby="ciTab-alerts">
    <div class="p-toolbar">
        <span id="ciUnreadBadge" class="pill red" style="display:none;"></span>
        <span style="flex:1;"></span>
        <button class="p-btn outline xs" onclick="ciMarkAllAlertsRead()">{$this->ciIcon('check')} {$this->tr('ci.js.mark_all_read')}</button>
    </div>
    <div class="p-table-scroll"><table class="p-table" id="ciAlertsTable">
        <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.table.severity')}</th><th>{$this->tr('ci.table.message')}</th><th>{$this->tr('ci.table.date')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
        <tbody></tbody>
    </table></div>
    <div id="ciAlertsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
</div>

<div class="ci-panel" id="ciPanel-insights" role="tabpanel" aria-labelledby="ciTab-insights">
    <div class="p-toolbar">
        <select id="ciInsightTypeFilter" class="p-select" onchange="ciLoadInsights()" aria-label="{$this->tr('ci.table.type')}">
            <option value="">{$this->tr('ci.category.all')}</option>
            <option value="threat">{$this->tr('ci.insights.threat')}</option>
            <option value="opportunity">{$this->tr('ci.insights.opportunity')}</option>
            <option value="insight">{$this->tr('ci.insights.insight')}</option>
        </select>
    </div>
    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head"><h3 style="display:flex;align-items:center;gap:8px;">{$this->ciIcon('sparkles')} {$this->tr('ci.ai.ask_btn')}</h3></div>
        <textarea id="ciAiQuestion" class="p-input" rows="2" style="width:100%;" placeholder="{$this->tr('ci.ai.ask_placeholder')}" aria-label="{$this->tr('ci.ai.ask_placeholder')}"></textarea>
        <div style="margin-top:10px;">
            <button class="p-btn primary xs" onclick="ciAskAi()">{$this->ciIcon('sparkles')} {$this->tr('ci.ai.ask_btn')}</button>
        </div>
        <div id="ciAiAnswer" class="ci-ai-answer" style="margin-top:12px;" aria-live="polite"></div>
    </div>
    <div id="ciInsightsList"></div>
    <div id="ciInsightsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
</div>

<div class="ci-panel" id="ciPanel-reports" role="tabpanel" aria-labelledby="ciTab-reports">
    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head"><h3 style="display:flex;align-items:center;gap:8px;">{$this->ciIcon('file')} {$this->tr('ci.tab.reports')}</h3></div>
        <div class="p-toolbar" style="margin-bottom:0;">
            <input type="hidden" id="ciReportWebsiteId" value="">
            <select id="ciReportType" class="p-select" aria-label="{$this->tr('ci.table.type')}">
                <option value="weekly">{$this->tr('ci.reports.weekly')}</option>
                <option value="monthly">{$this->tr('ci.reports.monthly')}</option>
                <option value="threat">{$this->tr('ci.reports.threat')}</option>
                <option value="opportunity">{$this->tr('ci.reports.opportunity')}</option>
                <option value="change">{$this->tr('ci.reports.change')}</option>
            </select>
            <button class="p-btn primary xs" onclick="ciGenerateReport()">{$this->ciIcon('file')} {$this->tr('ci.reports.generate_btn')}</button>
        </div>
    </div>
    <div class="p-table-scroll"><table class="p-table" id="ciReportsTable">
        <thead><tr><th>{$this->tr('ci.table.type')}</th><th>{$this->tr('ci.table.title')}</th><th>{$this->tr('ci.table.date')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
        <tbody></tbody>
    </table></div>
    <div id="ciReportsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
    <div id="ciReportViewer" style="margin-top:12px;"></div>
</div>

<div class="ci-panel" id="ciPanel-settings" role="tabpanel" aria-labelledby="ciTab-settings">
    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head"><h3>{$this->tr('ci.settings.role_title')}</h3></div>
        <div id="ciSettingsRoleBox" class="p-cell-muted">{$this->tr('common.loading')}</div>
    </div>

    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head"><h3>{$this->tr('ci.settings.defaults_title')}</h3></div>
        <p class="ci-form-note">{$this->tr('ci.settings.defaults_hint')}</p>
        <div class="p-grid cols-2">
            <div class="form-group">
                <label for="ciSettingsFrequency" class="form-label">{$this->tr('ci.settings.default_frequency')}</label>
                <select id="ciSettingsFrequency" class="p-select">
                    <option value="weekly">{$this->tr('ci.frequency.weekly')}</option>
                    <option value="daily">{$this->tr('ci.frequency.daily')}</option>
                </select>
            </div>
            <div class="form-group">
                <label for="ciSettingsMinSeverity" class="form-label">{$this->tr('ci.watchlist.min_severity')}</label>
                <select id="ciSettingsMinSeverity" class="p-select">
                    <option value="info">info</option>
                    <option value="low">low</option>
                    <option value="medium">medium</option>
                    <option value="high">high</option>
                    <option value="critical">critical</option>
                </select>
            </div>
        </div>
        <div style="margin-top:14px;">
            <label class="form-label">{$this->tr('ci.settings.defaults_title')}</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                <label class="ci-settings-chip"><input type="checkbox" id="ciSettingsChannelDashboard" checked> Dashboard</label>
                <label class="ci-settings-chip"><input type="checkbox" id="ciSettingsChannelEmail"> Email</label>
                <label class="ci-settings-chip"><input type="checkbox" id="ciSettingsChannelWebhook"> Webhook</label>
                <label class="ci-settings-chip"><input type="checkbox" id="ciSettingsChannelSlack"> Slack</label>
            </div>
        </div>
        <div class="p-grid cols-2" style="margin-top:14px;">
            <div class="form-group">
                <label for="ciSettingsWebhookUrl" class="form-label">{$this->tr('ci.settings.webhook_url')}</label>
                <input type="text" id="ciSettingsWebhookUrl" class="p-input" placeholder="https://your-endpoint.example/hooks/ci" dir="ltr">
            </div>
            <div class="form-group">
                <label for="ciSettingsSlackUrl" class="form-label">{$this->tr('ci.settings.slack_webhook_url')}</label>
                <input type="text" id="ciSettingsSlackUrl" class="p-input" placeholder="https://hooks.slack.com/services/..." dir="ltr">
            </div>
        </div>
        <div style="margin-top:14px;">
            <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;"><input type="checkbox" id="ciSettingsWeeklyDigest" style="accent-color:var(--panel-accent);"> {$this->tr('ci.settings.weekly_digest')}</label>
        </div>
        <div style="margin-top:16px;">
            <button class="p-btn primary xs" onclick="ciSaveSettings()">{$this->ciIcon('check')} {$this->tr('common.save')}</button>
        </div>
    </div>

    <div class="p-card" style="margin-bottom:12px;">
        <div class="p-card-head"><h3>{$this->tr('ci.settings.integrations_title')}</h3></div>
        <div id="ciSettingsIntegrations" class="p-cell-muted">{$this->tr('common.loading')}</div>
    </div>

    <div class="p-card" style="border-color:var(--panel-danger);">
        <div class="p-card-head"><h3 style="color:var(--panel-danger);">{$this->tr('ci.settings.danger_title')}</h3></div>
        <p class="ci-form-note">{$this->tr('ci.settings.danger_hint')}</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="p-btn danger xs" onclick="ciPauseAllMonitoring(1)">{$this->ciIcon('pause')} {$this->tr('ci.settings.pause_all')}</button>
            <button class="p-btn outline xs" onclick="ciPauseAllMonitoring(0)">{$this->ciIcon('play')} {$this->tr('ci.settings.resume_all')}</button>
        </div>
    </div>
</div>

<div id="ciProfileOverlay" class="p-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ciProfileName">
    <div class="p-modal wide">
        <div class="p-modal-head">
            <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                <span class="ci-profile-avatar" id="ciProfileAvatar" aria-hidden="true">?</span>
                <div style="min-width:0;">
                    <h3 id="ciProfileName" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{$this->tr('common.loading')}</h3>
                    <div id="ciProfileDomain" class="ci-form-note" dir="ltr" style="text-align:start;"></div>
                </div>
            </div>
            <button type="button" class="p-modal-close" onclick="ciCloseProfile()" aria-label="{$this->tr('common.close')}">{$this->ciIcon('x')}</button>
        </div>

        <div class="ci-profile-tabs" role="tablist" aria-label="{$this->tr('ci.profile.overview')}">
            <button type="button" class="p-tab ci-profile-tab-btn active" data-ptab="overview" role="tab" aria-selected="true" onclick="ciSwitchProfileTab('overview')">{$this->ciIcon('eye')} {$this->tr('ci.profile.overview')}</button>
            <button type="button" class="p-tab ci-profile-tab-btn" data-ptab="changes" role="tab" aria-selected="false" onclick="ciSwitchProfileTab('changes')">{$this->ciIcon('zap')} {$this->tr('ci.profile.changes')}</button>
            <button type="button" class="p-tab ci-profile-tab-btn" data-ptab="timeline" role="tab" aria-selected="false" onclick="ciSwitchProfileTab('timeline')">{$this->ciIcon('clock')} {$this->tr('ci.profile.timeline')}</button>
            <button type="button" class="p-tab ci-profile-tab-btn" data-ptab="insights" role="tab" aria-selected="false" onclick="ciSwitchProfileTab('insights')">{$this->ciIcon('lightbulb')} {$this->tr('ci.profile.insights')}</button>
            <button type="button" class="p-tab ci-profile-tab-btn" data-ptab="keywords" role="tab" aria-selected="false" onclick="ciSwitchProfileTab('keywords')">{$this->ciIcon('search')} {$this->tr('ci.profile.keywords')}</button>
            <button type="button" class="p-tab ci-profile-tab-btn" data-ptab="prices" role="tab" aria-selected="false" onclick="ciSwitchProfileTab('prices')">{$this->ciIcon('chart')} {$this->tr('ci.profile.prices')}</button>
            <button type="button" class="p-tab ci-profile-tab-btn" data-ptab="battlecard" role="tab" aria-selected="false" onclick="ciSwitchProfileTab('battlecard')">{$this->ciIcon('briefcase')} {$this->tr('ci.profile.battlecard')}</button>
        </div>

        <div class="p-modal-body">
            <div class="ci-profile-tab-panel active" id="ciProfileTab-overview">
                <div class="p-grid cols-2">
                    <div class="p-card">
                        <div class="p-card-head"><h4 style="margin:0;">{$this->tr('ci.profile.details')}</h4></div>
                        <div id="ciProfileDetails" class="p-cell-muted"></div>
                    </div>
                    <div class="p-card">
                        <div class="p-card-head"><h4 style="margin:0;">{$this->tr('ci.profile.tech_signals')}</h4></div>
                        <div id="ciProfileTechSignals" class="p-cell-muted"></div>
                    </div>
                </div>
                <div class="p-card" style="margin-top:12px;">
                    <div class="p-card-head">
                        <h4 style="margin:0;">{$this->tr('ci.profile.scorecard')}</h4>
                        <button class="p-btn outline xs" onclick="ciComputeScorecard()">{$this->ciIcon('refresh')} {$this->tr('ci.profile.compute_scorecard_btn')}</button>
                    </div>
                    <div id="ciProfileScorecardBasis" class="ci-form-note" style="margin-top:2px;margin-bottom:8px;"></div>
                    <div style="height:220px;position:relative;"><canvas id="ciScorecardChart"></canvas></div>
                    <div style="margin-top:16px;border-top:1px solid var(--panel-border);padding-top:14px;">
                        <h5 style="margin:0 0 8px;font-size:12.5px;color:var(--panel-text-muted);">{$this->tr('ci.profile.scorecard_trend')}</h5>
                        <div style="height:180px;position:relative;"><canvas id="ciScorecardTrendChart"></canvas></div>
                    </div>
                </div>
                <div class="p-card" style="margin-top:12px;">
                    <div class="p-card-head">
                        <h4 style="margin:0;">{$this->tr('ci.profile.positioning')}</h4>
                        <button class="p-btn outline xs" onclick="ciAnalyzeProfile()">{$this->ciIcon('sparkles')} {$this->tr('ci.profile.analyze_btn')}</button>
                    </div>
                    <div id="ciProfilePositioning" class="p-cell-muted" style="margin-top:8px;white-space:pre-wrap;">{$this->tr('ci.profile.positioning_hint')}</div>
                </div>
            </div>

            <div class="ci-profile-tab-panel" id="ciProfileTab-changes">
                <div class="p-table-scroll"><table class="p-table">
                    <thead><tr><th>{$this->tr('ci.table.date')}</th><th>{$this->tr('ci.table.type')}</th><th>{$this->tr('ci.table.severity')}</th><th>{$this->tr('ci.profile.website')}</th></tr></thead>
                    <tbody id="ciProfileChangesBody"></tbody>
                </table></div>
            </div>

            <div class="ci-profile-tab-panel" id="ciProfileTab-timeline">
                <div id="ciProfileTimeline"></div>
            </div>

            <div class="ci-profile-tab-panel" id="ciProfileTab-insights">
                <div style="text-align:end;margin-bottom:10px;">
                    <button class="p-btn outline xs" onclick="ciScanProfileInsights()">{$this->ciIcon('refresh')} {$this->tr('ci.profile.scan_insights_btn')}</button>
                </div>
                <div id="ciProfileInsights"></div>
            </div>

            <div class="ci-profile-tab-panel" id="ciProfileTab-keywords">
                <div class="p-card" style="margin-bottom:12px;">
                    <div class="p-card-head"><h4 style="margin:0;">{$this->tr('ci.profile.keywords_record')}</h4></div>
                    <div class="ci-form-note" style="margin-top:2px;margin-bottom:10px;">{$this->tr('ci.profile.keywords_desc')}</div>
                    <div class="p-grid cols-3">
                        <div class="form-group"><label for="ciKrKeyword" class="form-label">{$this->tr('ci.profile.keyword')}</label><input type="text" id="ciKrKeyword" class="p-input" placeholder="desert safari dubai"></div>
                        <div class="form-group"><label for="ciKrPosition" class="form-label">{$this->tr('ci.profile.position')}</label><input type="number" id="ciKrPosition" class="p-input" min="1" max="100" placeholder="1-100"></div>
                        <div class="form-group"><label for="ciKrUrl" class="form-label">URL</label><input type="text" id="ciKrUrl" class="p-input" dir="ltr" placeholder="https://competitor.example/safari"></div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn primary xs" onclick="ciRecordKeywordRanking()">{$this->ciIcon('plus')} {$this->tr('ci.profile.keywords_record')}</button>
                        <button class="p-btn outline xs" onclick="ciRunKeywordRankingCheck()">{$this->ciIcon('refresh')} {$this->tr('ci.profile.keywords_check')}</button>
                    </div>
                    <div id="ciKrCheckResult" class="ci-form-note" style="margin-top:8px;"></div>
                </div>
                <div class="p-table-scroll"><table class="p-table">
                    <thead><tr><th>{$this->tr('ci.profile.keyword')}</th><th>{$this->tr('ci.profile.position')}</th><th>{$this->tr('ci.profile.best_position')}</th><th>{$this->tr('ci.profile.trend')}</th><th>{$this->tr('ci.profile.source')}</th><th>{$this->tr('ci.profile.checked_at')}</th><th></th></tr></thead>
                    <tbody id="ciProfileKeywordRankings"></tbody>
                </table></div>
                <div id="ciKrHistory" style="margin-top:12px;"></div>
            </div>

            <div class="ci-profile-tab-panel" id="ciProfileTab-prices">
                <div class="p-card" style="margin-bottom:12px;">
                    <div class="p-card-head"><h4 style="margin:0;">{$this->tr('ci.profile.add_price')}</h4></div>
                    <div class="ci-form-note" style="margin-top:2px;margin-bottom:10px;">{$this->tr('ci.profile.prices_desc')}</div>
                    <div class="p-grid cols-3">
                        <div class="form-group"><label for="ciPpProduct" class="form-label">{$this->tr('ci.profile.product_name')}</label><input type="text" id="ciPpProduct" class="p-input" placeholder="Deluxe Room"></div>
                        <div class="form-group"><label for="ciPpPrice" class="form-label">{$this->tr('ci.profile.price')}</label><input type="number" id="ciPpPrice" class="p-input" min="0.01" step="0.01"></div>
                        <div class="form-group"><label for="ciPpCurrency" class="form-label">{$this->tr('ci.profile.currency')}</label><input type="text" id="ciPpCurrency" class="p-input" value="USD" dir="ltr" maxlength="8"></div>
                    </div>
                    <div style="margin-top:10px;">
                        <button class="p-btn primary xs" onclick="ciRecordProductPrice()">{$this->ciIcon('plus')} {$this->tr('ci.profile.add_price')}</button>
                    </div>
                </div>
                <div class="p-table-scroll"><table class="p-table">
                    <thead><tr><th>{$this->tr('ci.profile.product_name')}</th><th>{$this->tr('ci.profile.latest_price')}</th><th>{$this->tr('ci.profile.first_price')}</th><th>{$this->tr('ci.profile.currency')}</th><th>{$this->tr('ci.profile.readings')}</th><th>{$this->tr('ci.profile.detected_at')}</th><th></th></tr></thead>
                    <tbody id="ciProfileProductPrices"></tbody>
                </table></div>
                <div id="ciPpHistory" style="margin-top:12px;"></div>
            </div>

            <div class="ci-profile-tab-panel" id="ciProfileTab-battlecard">
                <div style="text-align:end;margin-bottom:10px;">
                    <button class="p-btn outline xs" onclick="ciGenerateBattlecard()">{$this->ciIcon('refresh')} {$this->tr('ci.profile.battlecard_generate')}</button>
                </div>
                <div id="ciProfileBattlecard"></div>
            </div>
        </div>
    </div>
</div>

<div id="ciModalOverlay" class="p-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ciModalTitle">
    <div class="p-modal">
        <div class="p-modal-head">
            <h3 id="ciModalTitle"></h3>
            <button type="button" class="p-modal-close" id="ciModalClose" aria-label="{$this->tr('common.close')}">{$this->ciIcon('x')}</button>
        </div>
        <div class="p-modal-body" id="ciModalBody"></div>
        <div class="p-modal-foot" id="ciModalFoot"></div>
    </div>
</div>
HTML;
    }

    private function renderScript(): string
    {
        $script = <<<'JS'
(function () {
    // ============================================================
    // Competitor Intelligence - professional UI client
    // ============================================================
    let allCompetitors = [];
    let currentProfileId = null;
    const pageState = { competitors: 1, alerts: 1, insights: 1, reports: 1 };
    let ciTrendChartInstance = null;
    let ciComparisonChartInstance = null;
    let ciScorecardChartInstance = null;
    let ciScorecardTrendChartInstance = null;
    let ciKrHistoryChartInstance = null;
    let ciPpHistoryChartInstance = null;
    let ciModalResolve = null;
    let ciLastFocused = null;
    window.__ciWatchKeywords = {};

    // ---------- core helpers ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // اسم نوع التغيير بلغة العميل بدل الاسم الداخلي (content_change ...)
    const ciChange = (t) => { const ar = (document.documentElement.lang || '').startsWith('ar'); const m = { content_change: ['تغيير في المحتوى', 'Content change'], headline_change: ['تغيير في العنوان', 'Headline change'], pricing_change: ['تغيير في الأسعار', 'Price change'], offer_change: ['عرض جديد أو متغيّر', 'Offer change'], new_product: ['منتج جديد', 'New product'], new_page: ['صفحة جديدة', 'New page'], price_alert: ['تنبيه سعر', 'Price alert'] }; const v = m[t]; return v ? v[ar ? 0 : 1] : String(t ?? '').replace(/_/g, ' '); };
    const T = (key, vars) => {
        let s = (window.I18N && window.I18N[key]) || key;
        if (vars) Object.keys(vars).forEach(k => { s = s.replace('{' + k + '}', vars[k]); });
        return s;
    };
    const tt = (key) => { const v = T(key); return v === key ? null : v; };
    // كل طلبات الصفحة بتستخدم fetchJSON (من panel.js - فيه CSRF ومعالجة الأخطاء)،
    // بس ماكانتش متعرّفة هنا، فلوحة التحكم كانت بتفضل "—" ومفيش أي زرار شغال.
    const fetchJSON = (url, opts) => window.P.fetchJSON(url, opts);
    const ic = (name, cls) => `<svg class="ci-ico ${cls || ''}" aria-hidden="true" focusable="false"><use href="#ci-icon-${name}"></use></svg>`;
    const cssVar = (name, fallback) => {
        try { const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim(); return v || fallback; }
        catch (e) { return fallback; }
    };
    const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = (v ?? '—'); };
    const initial = (s) => { const t = String(s || '').trim(); return t ? t.charAt(0).toUpperCase() : '?'; };
    const fmtPrice = (amount, currency) => (amount === null || amount === undefined) ? '—' : `${esc(currency || '')}${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();

    const sevLabel = (s) => tt('ci.sev.' + s) || s;
    const sevPill = (s) => { const cls = { info: 'blue', low: 'gray', medium: 'orange', high: 'red', critical: 'critical' }[s] || 'gray'; return `<span class="pill ${cls}">${esc(sevLabel(s))}</span>`; };
    const catPill = (c) => { const cls = { direct: 'orange', indirect: 'blue', emerging: 'green', potential: 'purple' }[c] || 'gray'; return `<span class="pill ${cls}">${esc(tt('ci.category.' + c) || c)}</span>`; };
    const typePill = (t) => { const cls = { threat: 'red', opportunity: 'green', insight: 'blue' }[t] || 'gray'; return `<span class="pill ${cls}">${esc(tt('ci.insights.' + t) || t)}</span>`; };
    const statusPill = (s) => { const cls = { new: 'blue', reviewed: 'green', dismissed: 'gray' }[s] || 'gray'; return `<span class="pill ${cls}">${esc(tt('ci.js.status_' + s) || s)}</span>`; };

    const emptyRow = (colspan, icon, title, desc) => `<tr><td colspan="${colspan}"><div class="p-empty">${ic(icon, 'lg')}<div style="font-weight:700;color:var(--panel-text);margin-top:6px;">${esc(title)}</div>${desc ? `<div style="margin-top:4px;">${esc(desc)}</div>` : ''}</div></td></tr>`;
    const emptyBlock = (icon, title, desc) => `<div class="p-empty">${ic(icon, 'lg')}<div style="font-weight:700;color:var(--panel-text);margin-top:6px;">${esc(title)}</div>${desc ? `<div style="margin-top:4px;">${esc(desc)}</div>` : ''}</div>`;
    const skeletonRows = (colspan, n) => Array.from({ length: n }, () => `<tr><td colspan="${colspan}"><div class="skeleton" style="height:22px;margin:4px 0;"></div></td></tr>`).join('');

    const chartTheme = (extra) => Object.assign({
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: cssVar('--panel-text-muted', '#8996AC'), usePointStyle: true, boxWidth: 8, boxHeight: 8 } } },
        scales: {
            x: { ticks: { color: cssVar('--panel-text-muted', '#8996AC') }, grid: { display: false } },
            y: { beginAtZero: true, ticks: { color: cssVar('--panel-text-muted', '#8996AC'), precision: 0 }, grid: { color: 'rgba(255,255,255,.06)' } },
        },
    }, extra || {});

    // ---------- generic modal (confirm / prompt) ----------
    function ciSetModal({ title, body, okText, cancelText, danger, onOk }) {
        const overlay = document.getElementById('ciModalOverlay');
        document.getElementById('ciModalTitle').textContent = title;
        document.getElementById('ciModalBody').innerHTML = body;
        const foot = document.getElementById('ciModalFoot');
        foot.innerHTML = '';
        if (cancelText) {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'p-btn outline xs'; btn.textContent = cancelText;
            btn.addEventListener('click', () => ciCloseModal(null));
            foot.appendChild(btn);
        }
        if (okText) {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'p-btn xs' + (danger ? ' danger' : ' primary'); btn.textContent = okText;
            btn.id = 'ciModalOk';
            btn.addEventListener('click', () => { if (onOk) onOk(); else ciCloseModal('ok'); });
            foot.appendChild(btn);
        }
        overlay.addEventListener('click', (e) => { if (e.target === overlay) ciCloseModal(null); });
        document.addEventListener('keydown', ciModalKeyHandler);
        ciLastFocused = document.activeElement;
        overlay.classList.add('open');
        const focusTarget = document.getElementById('ciModalOk') || foot.querySelector('.p-btn');
        if (focusTarget) focusTarget.focus();
        return overlay;
    }

    function ciModalKeyHandler(e) { if (e.key === 'Escape') ciCloseModal(null); }

    function ciCloseModal(value) {
        const overlay = document.getElementById('ciModalOverlay');
        if (!overlay || !overlay.classList.contains('open')) return;
        overlay.classList.remove('open');
        document.removeEventListener('keydown', ciModalKeyHandler);
        if (ciModalResolve) { const r = ciModalResolve; ciModalResolve = null; r(value); }
        if (ciLastFocused && ciLastFocused.focus) ciLastFocused.focus();
    }

    function ciConfirm(message, opts) {
        const o = Object.assign({ title: T('ci.js.confirm'), okText: T('ci.js.confirm'), cancelText: T('common.cancel'), danger: false }, opts || {});
        return new Promise((resolve) => {
            ciModalResolve = resolve;
            ciSetModal({
                title: o.title,
                body: `<div style="display:flex;align-items:flex-start;gap:12px;"><span class="ci-head-ico" style="width:40px;height:40px;">${ic(o.danger ? 'alert' : 'shield')}</span><div style="font-size:13.5px;line-height:1.7;">${esc(o.message)}</div></div>`,
                okText: o.okText, cancelText: o.cancelText, danger: o.danger,
                onOk: () => ciCloseModal(true),
            });
        });
    }

    function ciPromptValue(opts) {
        const o = Object.assign({ title: '', message: '', okText: T('common.save'), cancelText: T('common.cancel'), value: '', placeholder: '' }, opts || {});
        return new Promise((resolve) => {
            ciModalResolve = resolve;
            ciSetModal({
                title: o.title,
                body: `${o.message ? `<p class="p-cell-muted" style="margin:0 0 12px;line-height:1.6;">${esc(o.message)}</p>` : ''}<input id="ciPromptInput" class="p-input" style="width:100%;" value="${esc(o.value)}" placeholder="${esc(o.placeholder)}">`,
                okText: o.okText, cancelText: o.cancelText,
                onOk: () => ciCloseModal(document.getElementById('ciPromptInput').value.trim()),
            });
            const input = document.getElementById('ciPromptInput');
            input.focus(); input.select();
        });
    }

    // ---------- pagination ----------
    function renderPagination(elementId, key, pagination, reloadFn) {
        const el = document.getElementById(elementId);
        if (!el || !pagination) return;
        const totalPages = Math.max(1, Math.ceil(pagination.total / pagination.per_page));
        if (totalPages <= 1) { el.innerHTML = ''; return; }
        el.innerHTML = `
            <button class="p-btn outline xs" ${pagination.page <= 1 ? 'disabled' : ''} data-nav="prev">‹ ${esc(T('ci.js.prev'))}</button>
            <span class="p-cell-muted" style="align-self:center;font-size:12px;">${esc(T('ci.js.page_of', { p: pagination.page, n: totalPages }))}</span>
            <button class="p-btn outline xs" ${pagination.page >= totalPages ? 'disabled' : ''} data-nav="next">${esc(T('ci.js.next'))} ›</button>`;
        el.querySelector('[data-nav="prev"]')?.addEventListener('click', () => { pageState[key] = Math.max(1, pageState[key] - 1); reloadFn(); });
        el.querySelector('[data-nav="next"]')?.addEventListener('click', () => { pageState[key] = Math.min(totalPages, pageState[key] + 1); reloadFn(); });
    }

    // ---------- tabs ----------
    window.ciSwitchTab = function (tab) {
        document.querySelectorAll('.ci-tab-btn').forEach(b => {
            const on = b.dataset.tab === tab;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.ci-panel').forEach(p => p.classList.toggle('active', p.id === 'ciPanel-' + tab));
        const more = document.querySelector('.ci-more');
        if (more) { more.open = false; more.classList.toggle('has-active', !!more.querySelector('.ci-tab-btn.active')); }
        const loaders = {
            dashboard: loadDashboard, competitors: loadCompetitors, discovery: loadDiscovery,
            watchlist: loadWatchlist, activity: loadActivity, comparison: loadComparisonPicker,
            alerts: loadAlerts, insights: ciLoadInsights, reports: loadReports, settings: loadSettings,
        };
        if (loaders[tab]) loaders[tab]();
    };

    window.ciToggleForm = function (formId, show) {
        const el = document.getElementById(formId);
        if (el) el.style.display = show ? 'block' : 'none';
    };

    // ---------- dashboard ----------
    async function loadDashboard() {
        const res = await fetchJSON('/api/competitor-intelligence/dashboard');
        if (!res.success) return;
        const d = res.data;
        setText('ciStatTotal', d.total_competitors);
        setText('ciStatActive', d.active_competitors);
        setText('ciStatAlerts', d.high_priority_alerts);
        setText('ciStatChanges', d.new_changes_7d);

        document.getElementById('ciDashboardThreatsOpps').innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
                <span class="pill red">${ic('shield')} ${esc(T('ci.js.threats', { n: d.threats }))}</span>
                <span class="pill green">${ic('trending')} ${esc(T('ci.js.opportunities', { n: d.opportunities }))}</span>
            </div>
            <div class="ci-form-note" style="margin-top:10px;">${esc(T('ci.dashboard.threats_opportunities'))}</div>`;

        const rows = d.recent_activity || [];
        document.querySelector('#ciDashboardActivityTable tbody').innerHTML = rows.length
            ? rows.map(a => `<tr>
                <td><span class="ci-avatar-initial">${esc(initial(a.competitor))}</span> <strong>${esc(a.competitor)}</strong></td>
                <td>${esc(ciChange(a.change_type))}</td>
                <td>${sevPill(a.severity)}</td>
                <td>${esc(a.detected_at)}</td>
              </tr>`).join('')
            : emptyRow(4, 'clock', T('ci.js.no_recent_activity'));

        if (typeof Chart !== 'undefined') {
            const trend = d.changes_trend || [];
            if (ciTrendChartInstance) ciTrendChartInstance.destroy();
            ciTrendChartInstance = new Chart(document.getElementById('ciTrendChart'), {
                type: 'bar',
                data: {
                    labels: trend.map(t => t.date.slice(5)),
                    datasets: [
                        { label: T('ci.chart.low'), data: trend.map(t => t.low), backgroundColor: '#8FA3C0' },
                        { label: T('ci.chart.medium'), data: trend.map(t => t.medium), backgroundColor: cssVar('--panel-accent', '#EFB05E') },
                        { label: T('ci.chart.high'), data: trend.map(t => t.high), backgroundColor: cssVar('--panel-warning', '#F0916A') },
                        { label: T('ci.chart.critical'), data: trend.map(t => t.critical), backgroundColor: cssVar('--panel-danger', '#E5484D') },
                    ]
                },
                options: chartTheme({ scales: {
                    x: { stacked: true, ticks: { color: cssVar('--panel-text-muted', '#8996AC') }, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { color: cssVar('--panel-text-muted', '#8996AC'), precision: 0 }, grid: { color: 'rgba(255,255,255,.06)' } },
                } })
            });
        }
    }

    // ---------- competitors ----------
    window.ciOpenAddCompetitor = function () { ciToggleForm('ciBulkImportForm', false); ciToggleForm('ciAddCompetitorForm', true); };
    window.ciOpenBulkImport = function () { ciToggleForm('ciAddCompetitorForm', false); ciToggleForm('ciBulkImportForm', true); document.getElementById('ciBulkImportResult').innerHTML = ''; };

    window.ciSubmitAddCompetitor = async function () {
        const payload = {
            website_id: document.getElementById('ciNewWebsiteId').value || window.__CI_WEBSITE_ID__ || '',
            competitor_name: document.getElementById('ciNewName').value,
            competitor_domain: document.getElementById('ciNewDomain').value,
            industry: document.getElementById('ciNewIndustry').value,
            country: document.getElementById('ciNewCountry').value,
            category: document.getElementById('ciNewCategory').value,
            monitoring_frequency: document.getElementById('ciNewFrequency').value,
        };
        if (!payload.competitor_name) { toast(T('ci.js.name_required'), 'error'); return; }
        const res = await fetchJSON('/api/competitor-intelligence/competitors', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            ciToggleForm('ciAddCompetitorForm', false);
            loadCompetitors();
        } else {
            toast(res.error || T('ci.js.error'), 'error');
        }
    };

    window.ciSubmitBulkImport = async function () {
        const raw = document.getElementById('ciBulkImportText').value.trim();
        if (!raw) { toast(T('ci.js.name_required'), 'error'); return; }

        // CSV بسيط: name, domain, industry, country, category - سطر لكل منافس
        const rows = raw.split('\n').map(line => line.trim()).filter(Boolean).map(line => {
            const parts = line.split(',').map(p => p.trim());
            return { name: parts[0] || '', domain: parts[1] || '', industry: parts[2] || '', country: parts[3] || '', category: parts[4] || 'direct' };
        }).filter(r => r.name);

        if (!rows.length) { toast(T('ci.js.name_required'), 'error'); return; }

        const websiteId = window.__CI_WEBSITE_ID__ || document.getElementById('ciNewWebsiteId').value || '';
        const res = await fetchJSON('/api/competitor-intelligence/competitors/bulk-import', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, rows }),
        });

        const box = document.getElementById('ciBulkImportResult');
        if (!res.success) { box.innerHTML = `<div class="pill red">${esc(res.error || T('ci.js.failed'))}</div>`; return; }

        box.innerHTML = `<div class="pill green" style="margin-bottom:8px;">${ic('check')} ${esc(T('ci.bulk_import.summary', { added: res.data.added, skipped: res.data.skipped }))}</div>` +
            (res.data.results || []).filter(r => r.status !== 'added').map(r =>
                `<div style="font-size:12px;color:var(--panel-warning);">${esc(T('ci.table.actions'))}: ${esc(r.row)} — ${esc(r.name || '')} · ${esc(r.reason || r.status)}</div>`
            ).join('');

        toast(T('ci.bulk_import.summary', { added: res.data.added, skipped: res.data.skipped }), 'success');
        loadCompetitors();
    };

    async function loadCompetitors() {
        const tbody = document.querySelector('#ciCompetitorsTable tbody');
        tbody.innerHTML = skeletonRows(5, 4);
        const search = document.getElementById('ciCompetitorSearch').value;
        const category = document.getElementById('ciCompetitorCategoryFilter').value;
        const res = await fetchJSON('/api/competitor-intelligence/competitors?search=' + encodeURIComponent(search) + '&category=' + encodeURIComponent(category) + '&page=' + pageState.competitors);
        if (!res.success) { tbody.innerHTML = emptyRow(5, 'alert', T('ci.js.error')); return; }
        allCompetitors = res.data.competitors || [];
        tbody.innerHTML = allCompetitors.length
            ? allCompetitors.map(c => `<tr>
                <td><strong>${esc(c.competitor_name || c.competitor_domain)}</strong></td>
                <td dir="ltr" style="text-align:start;">${esc(c.competitor_domain || '-')}</td>
                <td>${catPill(c.category)}</td>
                <td>${esc(c.last_change_at || T('ci.js.no_changes_yet'))}</td>
                <td><div class="ci-table-actions">
                    <button class="ci-ico-btn" onclick="ciOpenProfile(${c.id})" title="${esc(T('ci.profile.view_btn'))}" aria-label="${esc(T('ci.profile.view_btn'))}">${ic('eye')}</button>
                    <button class="ci-ico-btn" onclick="ciCheckNow(${c.id})" title="${esc(T('ci.js.check_now'))}" aria-label="${esc(T('ci.js.check_now'))}">${ic('refresh')}</button>
                    <button class="ci-ico-btn danger" onclick="ciDeleteCompetitor(${c.id})" title="${esc(T('ci.js.delete'))}" aria-label="${esc(T('ci.js.delete'))}">${ic('trash')}</button>
                </div></td>
              </tr>`).join('')
            : emptyRow(5, 'inbox', T('ci.empty.competitors_title'), T('ci.empty.competitors_desc'));
        renderPagination('ciCompetitorsPagination', 'competitors', res.data.pagination, loadCompetitors);
    }

    document.addEventListener('input', (e) => { if (e.target && e.target.id === 'ciCompetitorSearch') { pageState.competitors = 1; loadCompetitors(); } });
    document.addEventListener('change', (e) => { if (e.target && e.target.id === 'ciCompetitorCategoryFilter') { pageState.competitors = 1; loadCompetitors(); } });

    window.ciCheckNow = async function (id) {
        toast(T('ci.js.checking'), 'info');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + id + '/check-now', { method: 'POST' });
        toast(res.success ? T('ci.js.cycle_completed') : (res.error || T('ci.js.failed')), res.success ? 'success' : 'error');
        loadCompetitors();
    };

    window.ciDeleteCompetitor = async function (id) {
        const comp = allCompetitors.find(c => c.id === id);
        const name = comp ? (comp.competitor_name || comp.competitor_domain) : '#' + id;
        const ok = await ciConfirm(T('ci.js.confirm_delete', { name }), { danger: true, okText: T('ci.js.delete') });
        if (!ok) return;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + id, { method: 'DELETE' });
        if (res.success) { toast(T('ci.js.deleted'), 'success'); loadCompetitors(); }
    };

    // ---------- discovery ----------
    window.ciSuggestCandidate = async function () {
        const payload = {
            website_id: document.getElementById('ciDiscWebsiteId').value || window.__CI_WEBSITE_ID__ || '',
            competitor_name: document.getElementById('ciDiscName').value,
            website: document.getElementById('ciDiscWebsite').value,
        };
        if (!payload.competitor_name) { toast(T('ci.js.name_required'), 'error'); return; }
        const res = await fetchJSON('/api/competitor-intelligence/discovery/suggest', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (res.success) { toast(T('ci.js.suggested'), 'success'); loadDiscovery(); }
    };

    window.ciRunDiscovery = async function () {
        const websiteId = document.getElementById('ciDiscWebsiteId').value || window.__CI_WEBSITE_ID__ || '';
        const res = await fetchJSON('/api/competitor-intelligence/discovery/run', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ website_id: websiteId }) });
        const box = document.getElementById('ciDiscoveryRunResult');
        if (res.success && res.data.available && res.data.candidates_saved > 0) {
            box.innerHTML = `<span class="pill green">${ic('check')} ${esc(T('ci.js.discovery_found', { n: res.data.candidates_saved }))}</span>`;
        } else if (res.success) {
            // نعرض السبب الحقيقي الراجع من السيرفر بدل رسالة افتراضية ثابتة -
            // ممكن يكون "insufficient data" أو "كل المنافسين من onboarding مضافين بالفعل" وغيره.
            box.innerHTML = `<span class="pill orange">${ic('alert')} ${esc(res.data.reason ? res.data.reason : T('ci.js.discovery_insufficient'))}</span>`;
        } else {
            box.innerHTML = `<span class="pill red">${esc(res.error || T('ci.js.failed'))}</span>`;
        }
        loadDiscovery();
    };

    async function loadDiscovery() {
        const tbody = document.querySelector('#ciDiscoveryTable tbody');
        tbody.innerHTML = skeletonRows(5, 3);
        const res = await fetchJSON('/api/competitor-intelligence/discovery');
        if (!res.success) { tbody.innerHTML = emptyRow(5, 'alert', T('ci.js.error')); return; }
        const rows = res.data.candidates || [];
        tbody.innerHTML = rows.length
            ? rows.map(c => `<tr>
                <td><strong>${esc(c.competitor_name)}</strong></td>
                <td dir="ltr" style="text-align:start;">${esc(c.website || '-')}</td>
                <td>${catPill(c.category)}</td>
                <td><span class="pill ${parseFloat(c.confidence) >= 70 ? 'green' : parseFloat(c.confidence) >= 40 ? 'orange' : 'gray'}">${esc(c.confidence)}%</span></td>
                <td>${c.status === 'pending' ? `<div class="ci-table-actions">
                    <button class="p-btn success xs" onclick="ciApproveCandidate(${c.id})">${ic('check')} ${esc(T('ci.js.approve'))}</button>
                    <button class="ci-ico-btn danger" onclick="ciDismissCandidate(${c.id})" title="${esc(T('ci.js.dismiss'))}" aria-label="${esc(T('ci.js.dismiss'))}">${ic('x')}</button>
                </div>` : statusPill(c.status)}</td>
              </tr>`).join('')
            : emptyRow(5, 'radar', T('ci.empty.discovery_title'), T('ci.empty.discovery_desc'));
    }

    window.ciApproveCandidate = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/discovery/' + id + '/approve', { method: 'POST' });
        if (res.success) { toast(T('ci.js.added_as_competitor'), 'success'); loadDiscovery(); }
    };
    window.ciDismissCandidate = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/discovery/' + id + '/dismiss', { method: 'POST' });
        if (res.success) loadDiscovery();
    };

    // ---------- watchlist ----------
    async function loadWatchlist() {
        const tbody = document.querySelector('#ciWatchlistTable tbody');
        tbody.innerHTML = skeletonRows(6, 3);
        const res = await fetchJSON('/api/competitor-intelligence/watchlist');
        if (!res.success) { tbody.innerHTML = emptyRow(6, 'alert', T('ci.js.error')); return; }
        const rows = res.data.watchlist || [];
        tbody.innerHTML = rows.length
            ? rows.map(w => {
                let keywords = [];
                try { keywords = JSON.parse(w.keyword_filters || '[]'); } catch (e) { keywords = []; }
                window.__ciWatchKeywords[w.competitor_id] = keywords.join(', ');
                return `<tr>
                <td><strong>${esc(w.competitor_name || w.competitor_domain)}</strong></td>
                <td><span class="pill ${w.priority === 'high' ? 'red' : w.priority === 'medium' ? 'orange' : 'gray'}">${esc(w.priority)}</span></td>
                <td>${sevPill(w.alert_min_severity)}</td>
                <td>${keywords.length ? esc(keywords.join(' · ')) : `<span class="ci-form-note">${esc(T('ci.watchlist.no_keywords'))}</span>`}</td>
                <td>${w.is_paused == 1 ? `<span class="pill gray">${esc(T('ci.js.paused'))}</span>` : `<span class="pill green">${esc(T('ci.js.active'))}</span>`}</td>
                <td><div class="ci-table-actions">
                    <button class="p-btn outline xs" onclick="ciEditKeywords(${w.competitor_id})">${ic('edit')} ${esc(T('ci.watchlist.edit_keywords'))}</button>
                    <button class="p-btn outline xs" onclick="ciToggleWatchlistPause(${w.competitor_id}, ${w.is_paused == 1 ? 0 : 1})">${w.is_paused == 1 ? ic('play') + ' ' + esc(T('ci.js.resume')) : ic('pause') + ' ' + esc(T('ci.js.pause'))}</button>
                </div></td>
              </tr>`;
              }).join('')
            : emptyRow(6, 'bell', T('ci.empty.watchlist_title'), T('ci.empty.watchlist_desc'));
    }

    window.ciToggleWatchlistPause = async function (competitorId, pause) {
        const res = await fetchJSON('/api/competitor-intelligence/watchlist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ competitor_id: competitorId, is_paused: pause }) });
        if (res.success) loadWatchlist();
    };

    window.ciEditKeywords = async function (competitorId) {
        const current = (window.__ciWatchKeywords && window.__ciWatchKeywords[competitorId]) || '';
        const input = await ciPromptValue({
            title: T('ci.watchlist.edit_keywords'),
            message: T('ci.watchlist.keywords_hint'),
            value: current,
            placeholder: T('ci.watchlist.keywords_prompt'),
            okText: T('common.save'),
        });
        if (input === null) return; // المستخدم عمل Cancel
        const keywords = input.split(',').map(k => k.trim()).filter(Boolean);
        const res = await fetchJSON('/api/competitor-intelligence/watchlist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ competitor_id: competitorId, keyword_filters: keywords }) });
        if (res.success) { toast(T('ci.js.added'), 'success'); loadWatchlist(); } else { toast(res.error || T('ci.js.failed'), 'error'); }
    };

    // ---------- activity ----------
    async function loadActivity() {
        const tbody = document.querySelector('#ciActivityTable tbody');
        tbody.innerHTML = skeletonRows(4, 4);
        const res = await fetchJSON('/api/competitor-intelligence/activity');
        if (!res.success) { tbody.innerHTML = emptyRow(4, 'alert', T('ci.js.error')); return; }
        const rows = res.data.activity || [];
        tbody.innerHTML = rows.length
            ? rows.map(a => `<tr>
                <td><span class="ci-avatar-initial">${esc(initial(a.competitor))}</span> <strong>${esc(a.competitor)}</strong></td>
                <td>${esc(ciChange(a.change_type))} <span class="ci-form-note">(${esc(a.page_type)})</span></td>
                <td>${sevPill(a.severity)}</td>
                <td>${esc(a.detected_at)}</td>
              </tr>`).join('')
            : emptyRow(4, 'activity', T('ci.empty.activity_title'), T('ci.empty.activity_desc'));
    }

    // ---------- comparison ----------
    async function loadComparisonPicker() {
        if (!allCompetitors.length) { const r = await fetchJSON('/api/competitor-intelligence/competitors'); allCompetitors = (r.success && r.data.competitors) || []; }
        const box = document.getElementById('ciComparisonPicker');
        if (!allCompetitors.length) {
            box.innerHTML = emptyBlock('users', T('ci.empty.competitors_title'), T('ci.empty.competitors_desc'));
            ciUpdateComparisonCount();
            return;
        }
        box.innerHTML = `<div class="p-grid cols-2">` + allCompetitors.map(c => `
            <label class="ci-compare-card">
                <input type="checkbox" class="ci-compare-cb" value="${c.id}" onchange="ciUpdateComparisonCount()">
                <span class="ci-avatar-initial">${esc(initial(c.competitor_name || c.competitor_domain))}</span>
                <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(c.competitor_name || c.competitor_domain)}</span>
            </label>`).join('') + `</div>`;
        ciUpdateComparisonCount();
    }

    window.ciUpdateComparisonCount = function () {
        const n = document.querySelectorAll('.ci-compare-cb:checked').length;
        const count = document.getElementById('ciComparisonCount');
        if (count) { count.style.display = n ? 'inline-flex' : 'none'; count.textContent = T('ci.js.comparison_selected', { n }); }
        const btn = document.getElementById('ciRunComparisonBtn');
        if (btn) btn.disabled = n === 0;
    };

    window.ciRunComparison = async function () {
        const ids = Array.from(document.querySelectorAll('.ci-compare-cb:checked')).map(cb => parseInt(cb.value, 10));
        if (!ids.length) { toast(T('ci.js.comparison_select'), 'error'); return; }
        const btn = document.getElementById('ciRunComparisonBtn');
        if (btn) { btn.disabled = true; btn.textContent = T('ci.js.checking'); }
        try {
            const websiteId = document.getElementById('ciReportWebsiteId').value || window.__CI_WEBSITE_ID__ || '';
            const res = await fetchJSON('/api/competitor-intelligence/comparison', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ website_id: websiteId, competitor_ids: ids }) });
            const box = document.getElementById('ciComparisonResult');
            if (!res.success) { box.innerHTML = emptyBlock('alert', T('ci.js.error'), res.error || T('ci.js.failed')); return; }
            const rows = res.data.rows || {};
            let html = `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>${esc(T('ci.js.col_business'))}</th><th>${esc(T('ci.js.col_website_presence'))}</th><th>${esc(T('ci.js.col_content_activity'))}</th><th>${esc(T('ci.js.col_offer_activity'))}</th><th>${esc(T('ci.js.col_market_signals'))}</th></tr></thead><tbody>`;
            const chartLabels = [], chartContent = [], chartOffers = [];
            Object.values(rows).forEach(r => {
                const presence = typeof r.website_presence === 'object' ? Object.entries(r.website_presence).filter(([, v]) => v === true).map(([k]) => k).join(', ') || T('ci.js.none_detected') : 'N/A';
                html += `<tr><td><strong>${esc(r.label)}</strong></td><td>${esc(presence)}</td><td>${esc(r.content_activity)}</td><td>${esc(r.offer_activity)}</td><td>${esc(r.market_position_signals)}</td></tr>`;
                chartLabels.push(r.label);
                chartContent.push(typeof r.content_activity === 'number' ? r.content_activity : 0);
                chartOffers.push(typeof r.offer_activity === 'number' ? r.offer_activity : 0);
            });
            html += '</tbody></table></div>';
            html += `<div style="margin-top:12px;display:flex;gap:8px;">
                <button class="p-btn outline xs" onclick="ciExportComparisonCsv()">${ic('download')} ${esc(T('ci.js.export_csv'))}</button>
            </div>`;
            window.__ciLastComparison = { ids, websiteId };
            box.innerHTML = html;

            if (typeof Chart !== 'undefined' && chartLabels.length) {
                document.getElementById('ciComparisonChartCard').style.display = 'block';
                if (ciComparisonChartInstance) ciComparisonChartInstance.destroy();
                ciComparisonChartInstance = new Chart(document.getElementById('ciComparisonChart'), {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            { label: T('ci.js.col_content_activity'), data: chartContent, backgroundColor: cssVar('--panel-accent', '#EFB05E') },
                            { label: T('ci.js.col_offer_activity'), data: chartOffers, backgroundColor: cssVar('--panel-teal', '#4ECDC4') },
                        ]
                    },
                    options: chartTheme()
                });
            }
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = `${ic('chart')} ${esc(T('ci.comparison.run_btn'))}`; }
        }
    };

    window.ciExportComparisonCsv = async function () {
        const last = window.__ciLastComparison;
        if (!last || !last.ids.length) return;
        const res = await fetchJSON('/api/competitor-intelligence/comparison/export', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: last.websiteId, competitor_ids: last.ids }),
        });
        if (!res.success) { toast(res.error || T('ci.js.failed'), 'error'); return; }
        const blob = new Blob(['\uFEFF' + res.data.csv], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = res.data.filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(a.href);
        toast(T('ci.js.exported'), 'success');
    };

    // ---------- alerts ----------
    async function loadAlerts() {
        const tbody = document.querySelector('#ciAlertsTable tbody');
        tbody.innerHTML = skeletonRows(5, 4);
        const res = await fetchJSON('/api/competitor-intelligence/alerts?page=' + pageState.alerts);
        if (!res.success) { tbody.innerHTML = emptyRow(5, 'alert', T('ci.js.error')); return; }
        const rows = res.data.alerts || [];
        tbody.innerHTML = rows.length
            ? rows.map(a => `<tr>
                <td><strong>${esc(a.competitor_name || a.competitor_domain)}</strong></td>
                <td>${sevPill(a.severity)}</td>
                <td>${a.type === 'product_price_change' ? '<b>💲 ' + esc(a.title) + '</b><br>' : ''}${esc(a.message)}</td>
                <td>${esc(a.created_at)}</td>
                <td>${a.is_read == 0 ? `<button class="p-btn outline xs" onclick="ciMarkAlertRead(${a.id})">${ic('check')} ${esc(T('ci.js.mark_read'))}</button>` : `<span class="pill gray">${esc(T('ci.js.read'))}</span>`}</td>
              </tr>`).join('')
            : emptyRow(5, 'bell', T('ci.empty.alerts_title'), T('ci.empty.alerts_desc'));
        renderPagination('ciAlertsPagination', 'alerts', res.data.pagination, loadAlerts);
        refreshUnreadBadge();
    }

    async function refreshUnreadBadge() {
        const badge = document.getElementById('ciUnreadBadge');
        if (!badge) return;
        const res = await fetchJSON('/api/competitor-intelligence/alerts/unread-count');
        if (!res.success) return;
        const count = res.data.unread_count || 0;
        badge.textContent = T('ci.js.unread_count', { n: count });
        badge.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    window.ciMarkAlertRead = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/alerts/' + id + '/read', { method: 'POST' });
        if (res.success) loadAlerts();
    };

    window.ciMarkAllAlertsRead = async function () {
        const res = await fetchJSON('/api/competitor-intelligence/alerts/read-all', { method: 'POST' });
        if (res.success) { loadAlerts(); refreshUnreadBadge(); }
    };

    // ---------- insights + AI ----------
    window.ciLoadInsights = async function () {
        const type = document.getElementById('ciInsightTypeFilter').value;
        const box = document.getElementById('ciInsightsList');
        box.innerHTML = `<div class="skeleton" style="height:70px;margin-bottom:10px;"></div>`;
        const res = await fetchJSON('/api/competitor-intelligence/insights?page=' + pageState.insights + (type ? '&type=' + type : ''));
        if (!res.success) { box.innerHTML = emptyBlock('alert', T('ci.js.error')); return; }
        const rows = res.data.insights || [];
        box.innerHTML = rows.length
            ? rows.map(i => `<div class="p-card" style="margin-bottom:10px;${i.status === 'dismissed' ? 'opacity:.55;' : ''}">
                <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                    ${typePill(i.type)}
                    <div style="flex:1;min-width:220px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <strong>${esc(i.title)}</strong>
                            <span class="ci-form-note">${esc(i.competitor_name || i.competitor_domain || T('ci.js.market_wide'))}</span>
                            ${statusPill(i.status)}
                        </div>
                        <div class="p-cell-muted" style="margin:6px 0;">${esc(i.description)}</div>
                        ${i.recommended_action ? `<div style="font-size:12.5px;"><span class="pill blue" style="margin-inline-end:6px;">${esc(T('ci.js.recommended'))}</span>${esc(i.recommended_action)}</div>` : ''}
                        <div class="ci-form-note" style="margin-top:6px;">${esc(T('ci.js.confidence_label'))}: ${esc(i.confidence)} · ${esc(i.created_at)}</div>
                        <div style="margin-top:8px;display:flex;gap:6px;">
                            ${i.status !== 'reviewed' ? `<button class="p-btn success xs" onclick="ciSetInsightStatus(${i.id}, 'reviewed')">${ic('check')} ${esc(T('ci.js.approve'))}</button>` : ''}
                            ${i.status !== 'dismissed' ? `<button class="p-btn outline xs" onclick="ciSetInsightStatus(${i.id}, 'dismissed')">${ic('x')} ${esc(T('ci.js.dismiss'))}</button>` : ''}
                        </div>
                    </div>
                </div>
              </div>`).join('')
            : emptyBlock('lightbulb', T('ci.empty.insights_title'), T('ci.empty.insights_desc'));
        renderPagination('ciInsightsPagination', 'insights', res.data.pagination, ciLoadInsights);
    };

    window.ciSetInsightStatus = async function (id, status) {
        const res = await fetchJSON('/api/competitor-intelligence/insights/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        });
        if (res.success) { toast(res.message || T('common.updated'), 'success'); ciLoadInsights(); } else { toast(res.error || T('ci.js.error'), 'error'); }
    };

    document.addEventListener('change', (e) => { if (e.target && e.target.id === 'ciInsightTypeFilter') pageState.insights = 1; });

    window.ciAskAi = async function () {
        const question = document.getElementById('ciAiQuestion').value;
        if (!question) return;
        const answer = document.getElementById('ciAiAnswer');
        answer.innerHTML = `<span class="pill blue">${ic('sparkles')} ${esc(T('ci.js.thinking'))}</span>`;
        const res = await fetchJSON('/api/competitor-intelligence/ai/ask', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question }) });
        answer.textContent = res.success ? (res.data.answer || T('ci.js.no_answer')) : (res.error || T('ci.js.failed'));
    };

    // ---------- reports ----------
    async function loadReports() {
        const tbody = document.querySelector('#ciReportsTable tbody');
        tbody.innerHTML = skeletonRows(4, 3);
        const res = await fetchJSON('/api/competitor-intelligence/reports?page=' + pageState.reports);
        if (!res.success) { tbody.innerHTML = emptyRow(4, 'alert', T('ci.js.error')); return; }
        const rows = res.data.reports || [];
        tbody.innerHTML = rows.length
            ? rows.map(r => `<tr>
                <td>${typePill(r.type)}</td>
                <td><strong>${esc(r.title)}</strong></td>
                <td>${esc(r.generated_at)}</td>
                <td><button class="p-btn outline xs" onclick="ciViewReport(${r.id})">${ic('eye')} ${esc(T('ci.js.view'))}</button></td>
              </tr>`).join('')
            : emptyRow(4, 'file', T('ci.empty.reports_title'), T('ci.empty.reports_desc'));
        renderPagination('ciReportsPagination', 'reports', res.data.pagination, loadReports);
    }

    window.ciGenerateReport = async function () {
        const websiteId = document.getElementById('ciReportWebsiteId').value || window.__CI_WEBSITE_ID__ || '';
        const type = document.getElementById('ciReportType').value;
        const res = await fetchJSON('/api/competitor-intelligence/reports', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ website_id: websiteId, type }) });
        if (res.success) { toast(T('ci.js.report_generated'), 'success'); loadReports(); } else { toast(res.error || T('ci.js.failed'), 'error'); }
    };

    window.ciViewReport = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/reports/' + id);
        if (!res.success) return;
        document.getElementById('ciReportViewer').innerHTML = `<div class="p-card">
            <div style="text-align:end;margin-bottom:10px;display:flex;gap:8px;justify-content:flex-end;">
                <a class="p-btn outline xs" href="/competitor-intelligence/reports/${id}/export" target="_blank" rel="noopener">${ic('file')} ${esc(T('ci.js.export_pdf'))}</a>
                <a class="p-btn outline xs" href="/competitor-intelligence/reports/${id}/export?format=csv">${ic('download')} ${esc(T('ci.js.export_csv'))}</a>
            </div>
            <pre style="white-space:pre-wrap;font-size:12px;margin:0;">${esc(JSON.stringify(res.data.report.content, null, 2))}</pre>
        </div>`;
    };

    // ---------- settings ----------
    async function loadSettings() {
        const res = await fetchJSON('/api/competitor-intelligence/settings');
        if (!res.success) return;
        const d = res.data;

        document.getElementById('ciSettingsRoleBox').innerHTML = `
            <div class="p-kv"><span class="k">${esc(T('ci.settings.role_title'))}</span><span class="v"><span class="pill purple">${esc(d.ci_role)}</span></span></div>
            <div class="p-kv"><span class="k">${esc(T('ci.settings.can_label'))}</span><span class="v" style="font-weight:500;">${d.granted_permissions.map(p => `<span class="pill gray" style="margin-inline-end:4px;">${esc(p)}</span>`).join('')}</span></div>`;

        const prefs = d.preferences || {};
        document.getElementById('ciSettingsFrequency').value = prefs.default_monitoring_frequency || 'weekly';
        document.getElementById('ciSettingsMinSeverity').value = prefs.default_alert_min_severity || 'medium';
        let channels = [];
        try { channels = JSON.parse(prefs.default_alert_channels || '["dashboard"]'); } catch (e) { channels = ['dashboard']; }
        document.getElementById('ciSettingsChannelDashboard').checked = channels.includes('dashboard');
        document.getElementById('ciSettingsChannelEmail').checked = channels.includes('email');
        document.getElementById('ciSettingsChannelWebhook').checked = channels.includes('webhook');
        document.getElementById('ciSettingsChannelSlack').checked = channels.includes('slack');
        document.getElementById('ciSettingsWebhookUrl').value = prefs.webhook_url || '';
        document.getElementById('ciSettingsSlackUrl').value = prefs.slack_webhook_url || '';
        document.getElementById('ciSettingsWeeklyDigest').checked = prefs.weekly_digest_enabled == 1;

        const integ = d.integrations || {};
        const line = (label, ok) => `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed var(--panel-border);"><span class="ci-dot ${ok ? 'on' : ''}"></span><span style="flex:1;font-size:13px;">${esc(label)}</span><span class="pill ${ok ? 'green' : 'gray'}">${ok ? esc(T('ci.settings.active')) : esc(T('ci.settings.inactive'))}</span></div>`;
        document.getElementById('ciSettingsIntegrations').innerHTML =
            [
                [T('ci.settings.integration_google'), !!integ.google_places_discovery],
                [T('ci.settings.integration_ai'), !!integ.ai_analyst],
                [T('ci.settings.integration_email'), !!integ.email_alerts],
            ].map(([l, ok]) => line(l, ok)).join('');

        // مفيش صلاحية manage_settings -> نعطّل الحفظ/الأزرار الخطيرة بدل ما نخفيها بالكامل (شفافية أفضل)
        const canManage = d.granted_permissions.includes('manage_settings');
        document.querySelectorAll('#ciPanel-settings button, #ciPanel-settings select, #ciPanel-settings input').forEach(el => {
            if (el.tagName !== 'INPUT' || el.type !== 'text') el.disabled = !canManage;
        });
    }

    window.ciSaveSettings = async function () {
        const channels = [];
        if (document.getElementById('ciSettingsChannelDashboard').checked) channels.push('dashboard');
        if (document.getElementById('ciSettingsChannelEmail').checked) channels.push('email');
        if (document.getElementById('ciSettingsChannelWebhook').checked) channels.push('webhook');
        if (document.getElementById('ciSettingsChannelSlack').checked) channels.push('slack');

        const payload = {
            default_monitoring_frequency: document.getElementById('ciSettingsFrequency').value,
            default_alert_min_severity: document.getElementById('ciSettingsMinSeverity').value,
            default_alert_channels: channels,
            webhook_url: document.getElementById('ciSettingsWebhookUrl').value.trim(),
            slack_webhook_url: document.getElementById('ciSettingsSlackUrl').value.trim(),
            weekly_digest_enabled: document.getElementById('ciSettingsWeeklyDigest').checked ? 1 : 0,
        };
        const res = await fetchJSON('/api/competitor-intelligence/settings', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        toast(res.success ? T('ci.js.added') : (res.error || T('ci.js.failed')), res.success ? 'success' : 'error');
    };

    window.ciPauseAllMonitoring = async function (pause) {
        const ok = await ciConfirm(pause ? T('ci.settings.confirm_pause_all') : T('ci.settings.confirm_resume_all'));
        if (!ok) return;
        const res = await fetchJSON('/api/competitor-intelligence/settings/pause-all', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pause }) });
        toast(res.success ? T('ci.js.added') : (res.error || T('ci.js.failed')), res.success ? 'success' : 'error');
    };

    // ============================================================
    // Competitor Profile (modal) - Overview / Changes / Timeline / Insights
    // ============================================================
    function ciProfileKeyHandler(e) { if (e.key === 'Escape') ciCloseProfile(); }

    window.ciOpenProfile = async function (id) {
        currentProfileId = id;
        const overlay = document.getElementById('ciProfileOverlay');
        ciLastFocused = document.activeElement;
        document.getElementById('ciProfileName').textContent = T('common.loading');
        document.getElementById('ciProfileAvatar').textContent = '?';
        document.getElementById('ciProfilePositioning').textContent = T('ci.profile.positioning_hint');
        ciSwitchProfileTab('overview');
        overlay.classList.add('open');
        document.addEventListener('keydown', ciProfileKeyHandler);
        await ciLoadProfileOverview(id);
    };

    window.ciCloseProfile = function () {
        const overlay = document.getElementById('ciProfileOverlay');
        if (!overlay || !overlay.classList.contains('open')) return;
        overlay.classList.remove('open');
        document.removeEventListener('keydown', ciProfileKeyHandler);
        currentProfileId = null;
        if (ciLastFocused && ciLastFocused.focus) ciLastFocused.focus();
    };
    document.getElementById('ciProfileOverlay').addEventListener('click', (e) => { if (e.target === document.getElementById('ciProfileOverlay')) ciCloseProfile(); });

    window.ciSwitchProfileTab = function (tab) {
        document.querySelectorAll('.ci-profile-tab-btn').forEach(b => {
            const on = b.dataset.ptab === tab;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.ci-profile-tab-panel').forEach(p => p.classList.toggle('active', p.id === 'ciProfileTab-' + tab));
        if (!currentProfileId) return;
        if (tab === 'changes') ciLoadProfileChanges();
        if (tab === 'timeline') ciLoadProfileTimeline();
        if (tab === 'insights') ciLoadProfileInsights();
        if (tab === 'keywords') ciLoadKeywordRankings();
        if (tab === 'prices') ciLoadProductPrices();
        if (tab === 'battlecard') ciLoadBattlecard();
    };

    async function ciLoadProfileOverview(id) {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + id);
        if (!res.success) { toast(res.error || T('ci.js.failed'), 'error'); ciCloseProfile(); return; }
        const c = res.data.competitor;

        const name = c.competitor_name || c.competitor_domain;
        document.getElementById('ciProfileName').textContent = name;
        document.getElementById('ciProfileAvatar').textContent = initial(name);
        document.getElementById('ciProfileDomain').textContent = c.competitor_domain || '';

        document.getElementById('ciProfileDetails').innerHTML = [
            [T('ci.profile.industry'), c.industry],
            [T('ci.profile.country'), c.country],
            [T('ci.profile.category'), tt('ci.category.' + c.category) || c.category],
            [T('ci.profile.monitoring'), c.monitoring_frequency + (c.monitoring_paused == 1 ? ' · ' + T('ci.settings.paused') : '')],
            [T('ci.profile.last_monitored'), c.last_monitored_at || T('ci.js.no_changes_yet')],
            [T('ci.profile.last_change'), c.last_change_at || T('ci.js.no_changes_yet')],
        ].map(([k, v]) => `<div class="p-kv"><span class="k">${esc(k)}</span><span class="v">${esc(v || '-')}</span></div>`).join('');

        const snapshots = res.data.latest_snapshots || [];
        const techLines = [];
        const pageTypes = [];
        snapshots.forEach(s => {
            if (s.page_type) pageTypes.push(s.page_type);
            if (s.tech_signals) {
                try {
                    const t = JSON.parse(s.tech_signals);
                    Object.entries(t).forEach(([k, v]) => techLines.push(`<span class="pill blue" style="margin:0 4px 6px 0;">${esc(k)}: ${esc(v)}</span>`));
                } catch (e) { /* ignore unparseable */ }
            }
        });
        document.getElementById('ciProfileTechSignals').innerHTML = techLines.length
            ? `<div>${techLines.join('')}</div>${pageTypes.length ? `<div class="ci-form-note" style="margin-top:6px;">${esc(pageTypes.join(' · '))}</div>` : ''}`
            : emptyBlock('globe', T('ci.profile.not_available'));

        // لو فيه AI insight سابق من نوع positioning نعرضه فورًا بدل ما ننتظر ضغطة زرار
        const positioningInsight = (res.data.insights || []).find(i => i.generated_by === 'ai' && i.title && i.title.indexOf('positioning') !== -1);
        if (positioningInsight) {
            document.getElementById('ciProfilePositioning').textContent = positioningInsight.description;
        }

        ciRenderScorecardChart(res.data.scorecard || null);
        ciLoadScorecardTrend();
    }

    async function ciLoadScorecardTrend() {
        if (typeof Chart === 'undefined' || !currentProfileId) return;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/scorecard-trend');
        if (ciScorecardTrendChartInstance) { ciScorecardTrendChartInstance.destroy(); ciScorecardTrendChartInstance = null; }
        if (!res.success) return;

        const rows = res.data.trend || [];
        if (rows.length < 2) return; // نقطة واحدة أو صفر - رسم اتجاه مش مفيد، الرسم الأساسي فوق كافي

        ciScorecardTrendChartInstance = new Chart(document.getElementById('ciScorecardTrendChart'), {
            type: 'line',
            data: {
                labels: rows.map(r => r.computed_at.slice(0, 10)),
                datasets: [
                    { label: T('ci.profile.score_visibility'), data: rows.map(r => r.visibility_score), borderColor: cssVar('--panel-accent', '#EFB05E'), fill: false, tension: 0.25 },
                    { label: T('ci.profile.score_content'), data: rows.map(r => r.content_activity_score), borderColor: cssVar('--panel-teal', '#4ECDC4'), fill: false, tension: 0.25 },
                    { label: T('ci.profile.score_market'), data: rows.map(r => r.market_presence_score), borderColor: cssVar('--panel-success', '#2E9E6C'), fill: false, tension: 0.25 },
                ]
            },
            options: chartTheme({ scales: { x: { ticks: { color: cssVar('--panel-text-muted', '#8996AC') }, grid: { display: false } }, y: { beginAtZero: true, max: 100, ticks: { color: cssVar('--panel-text-muted', '#8996AC'), precision: 0 }, grid: { color: 'rgba(255,255,255,.06)' } } } })
        });
    }

    function ciRenderScorecardChart(scorecard) {
        const basisBox = document.getElementById('ciProfileScorecardBasis');
        if (typeof Chart === 'undefined') return;
        if (ciScorecardChartInstance) { ciScorecardChartInstance.destroy(); ciScorecardChartInstance = null; }

        if (!scorecard) {
            basisBox.textContent = T('ci.profile.scorecard_not_computed');
            return;
        }

        basisBox.textContent = (scorecard.basis === 'data_backed' ? T('ci.profile.basis_data_backed') : T('ci.profile.basis_estimated')) + ' · ' + scorecard.computed_at;

        const labels = [T('ci.profile.score_visibility'), T('ci.profile.score_content'), T('ci.profile.score_offers'), T('ci.profile.score_product'), T('ci.profile.score_market')];
        const values = [scorecard.visibility_score, scorecard.content_activity_score, scorecard.offer_activity_score, scorecard.product_coverage_score, scorecard.market_presence_score].map(v => v === null ? 0 : parseFloat(v));
        const colors = [cssVar('--panel-accent', '#EFB05E'), cssVar('--panel-teal', '#4ECDC4'), cssVar('--panel-warning', '#F0916A'), cssVar('--panel-info', '#8B7CF6'), cssVar('--panel-success', '#2E9E6C')];

        ciScorecardChartInstance = new Chart(document.getElementById('ciScorecardChart'), {
            type: 'bar',
            data: { labels, datasets: [{ label: T('ci.profile.scorecard'), data: values, backgroundColor: colors }] },
            options: chartTheme({ indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100, ticks: { color: cssVar('--panel-text-muted', '#8996AC'), precision: 0 }, grid: { color: 'rgba(255,255,255,.06)' } }, y: { ticks: { color: cssVar('--panel-text-muted', '#8996AC') }, grid: { display: false } } } })
        });
    }

    window.ciComputeScorecard = async function () {
        if (!currentProfileId) return;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/compute-scorecard', { method: 'POST' });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            ciRenderScorecardChart(res.data.scorecard);
            ciLoadScorecardTrend();
        } else {
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    async function ciLoadProfileChanges() {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId);
        if (!res.success) return;
        const rows = res.data.recent_changes || [];
        document.getElementById('ciProfileChangesBody').innerHTML = rows.length
            ? rows.map(c => `<tr>
                <td>${esc(c.detected_at)}</td>
                <td>${esc(ciChange(c.change_type))} <span class="ci-form-note">(${esc(c.page_type)})</span></td>
                <td>${sevPill(c.severity)}</td>
                <td>${c.source_url ? `<a class="p-btn outline xs" href="${esc(c.source_url)}" target="_blank" rel="noopener">${ic('external')} ${esc(T('ci.js.view'))}</a>` : '-'}</td>
              </tr>`).join('')
            : emptyRow(4, 'clock', T('ci.js.no_changes_yet'));
    }

    async function ciLoadProfileTimeline() {
        const box = document.getElementById('ciProfileTimeline');
        box.innerHTML = `<div class="skeleton" style="height:60px;"></div>`;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/timeline');
        if (!res.success) { box.innerHTML = emptyBlock('alert', res.error || T('ci.js.failed')); return; }
        const timeline = res.data.timeline || {};
        const months = Object.keys(timeline).sort().reverse();
        let html = months.length
            ? months.map(m => `<div class="p-card" style="margin-bottom:10px;">
                <div class="p-card-head"><h4 style="margin:0;">${esc(m)}</h4></div>
                ${timeline[m].map(c => `<div class="ci-timeline-item sev-${esc(c.severity)}"><span class="ci-timeline-dot"></span><div>${sevPill(c.severity)} <strong>${esc(c.change_type)}</strong> — ${esc(c.page_type)} <span class="ci-form-note">(${esc(c.detected_at)})</span></div></div>`).join('')}
              </div>`).join('')
            : emptyBlock('clock', T('ci.js.no_changes_yet'));

        const ph = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/price-history');
        if (ph.success && (ph.data.price_history || []).length) {
            html += `<div class="p-card" style="margin-top:12px;">
                <div class="p-card-head"><h4 style="margin:0;display:flex;align-items:center;gap:8px;">${ic('trending')} ${esc(T('ci.js.price_history'))}</h4></div>
                ${ph.data.price_history.map(p => `<div class="ci-timeline-item sev-${esc(p.change_type)}"><span class="ci-timeline-dot"></span><div>${esc(p.detected_at)} — <strong>${fmtPrice(p.price_before, p.currency)}</strong> → <strong>${fmtPrice(p.price_after, p.currency)}</strong> <span class="ci-form-note">(${esc(p.change_type)} · ${esc(p.page_type)})</span></div></div>`).join('')}
            </div>`;
        }

        box.innerHTML = html;
    }

    async function ciLoadProfileInsights() {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId);
        if (!res.success) return;
        const rows = res.data.insights || [];
        document.getElementById('ciProfileInsights').innerHTML = rows.length
            ? rows.map(i => `<div class="p-card" style="margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><strong>${esc(i.title)}</strong> ${typePill(i.type)}</div>
                <div class="p-cell-muted" style="margin:6px 0;">${esc(i.description)}</div>
                ${i.recommended_action ? `<div style="font-size:12.5px;"><span class="pill blue" style="margin-inline-end:6px;">${esc(T('ci.js.recommended'))}</span>${esc(i.recommended_action)}</div>` : ''}
                <div class="ci-form-note" style="margin-top:6px;">${esc(T('ci.js.confidence_label'))}: ${esc(i.confidence)} · ${esc(i.created_at)}</div>
              </div>`).join('')
            : emptyBlock('lightbulb', T('ci.empty.insights_title'), T('ci.empty.insights_desc'));
    }

    window.ciScanProfileInsights = async function () {
        if (!currentProfileId) return;
        toast(T('ci.js.checking'), 'info');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/scan-insights', { method: 'POST' });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            ciLoadProfileInsights();
        } else {
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    window.ciAnalyzeProfile = async function () {
        if (!currentProfileId) return;
        document.getElementById('ciProfilePositioning').textContent = T('ci.js.thinking');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/analyze-profile', { method: 'POST' });
        if (res.success && res.data.available) {
            document.getElementById('ciProfilePositioning').textContent = res.data.insight.description;
        } else {
            document.getElementById('ciProfilePositioning').textContent = res.success ? T('ci.profile.not_available') : (res.error || T('ci.js.failed'));
        }
    };

    // ---------- M5 (G1/G6/G7): keyword rankings / product prices / battlecard ----------
    function trendPill(trend) {
        if (!trend) return '<span class="pill gray">' + esc(T('ci.js.no_changes_yet')) + '</span>';
        if (trend < 0) return '<span class="pill green" title="' + esc(trend) + '">' + ic('trending') + ' ' + esc(Math.abs(trend)) + '</span>';
        if (trend > 0) return '<span class="pill red" title="' + esc(trend) + '">' + ic('trending') + ' -' + esc(trend) + '</span>';
        return '<span class="pill gray">0</span>';
    }

    function rankPositionPill(pos) {
        if (pos === null || pos === undefined) return '<span class="pill gray">-</span>';
        return '<span class="pill ' + (pos <= 5 ? 'green' : pos <= 20 ? 'blue' : pos <= 50 ? 'gray' : 'red') + '">' + esc(pos) + '</span>';
    }

    async function ciLoadKeywordRankings() {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/keyword-rankings');
        const rows = (res.success && res.data.keyword_rankings) || [];
        document.getElementById('ciProfileKeywordRankings').innerHTML = rows.length
            ? rows.map(r => `<tr>
                <td><strong>${esc(r.keyword)}</strong></td>
                <td>${rankPositionPill(r.position)}</td>
                <td>${rankPositionPill(r.best_position)}</td>
                <td>${trendPill(r.trend)}</td>
                <td>${esc(r.source)}</td>
                <td>${esc(r.checked_at)}</td>
                <td>${r.url ? `<a class="p-btn outline xs" href="${esc(r.url)}" target="_blank" rel="noopener">${ic('external')} ${esc(T('ci.js.view'))}</a>` : ''}</td>
              </tr>`).join('')
            : emptyRow(7, 'search', T('ci.profile.keywords_empty'));
    }

    async function ciLoadKeywordHistory(keyword) {
        const box = document.getElementById('ciKrHistory');
        if (!keyword) { box.innerHTML = ''; return; }
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/keyword-rankings/history?keyword=' + encodeURIComponent(keyword));
        if (!res.success || !(res.data.history || []).length) { box.innerHTML = ''; return; }
        box.innerHTML = `<div class="p-card">
            <div class="p-card-head"><h4 style="margin:0;">${ic('trending')} ${esc(T('ci.profile.rank_history'))}: <span dir="ltr">${esc(keyword)}</span></h4></div>
            <div class="ci-form-note" style="margin-top:2px;margin-bottom:8px;">${res.data.history.map(h => `${esc(h.checked_at)} → ${rankPositionPill(h.position)}`).join(' · ')}</div>
            <div style="height:150px;position:relative;"><canvas id="ciKrHistoryChart"></canvas></div>
        </div>`;
        if (typeof Chart === 'undefined' || !document.getElementById('ciKrHistoryChart')) return;
        if (ciKrHistoryChartInstance) { ciKrHistoryChartInstance.destroy(); ciKrHistoryChartInstance = null; }
        const series = res.data.history;
        ciKrHistoryChartInstance = new Chart(document.getElementById('ciKrHistoryChart'), {
            type: 'line',
            data: {
                labels: series.map(h => h.checked_at.slice(0, 10)),
                datasets: [{ label: T('ci.profile.position'), data: series.map(h => h.position === null ? null : h.position), borderColor: cssVar('--panel-accent', '#EFB05E'), backgroundColor: 'rgba(239,176,94,.12)', fill: true, tension: 0.25, spanGaps: true }]
            },
            options: chartTheme({ plugins: { legend: { display: false } }, scales: { x: { ticks: { color: cssVar('--panel-text-muted', '#8996AC'), maxRotation: 45, font: { size: 9 } }, grid: { display: false } }, y: { reverse: true, beginAtZero: false, ticks: { color: cssVar('--panel-text-muted', '#8996AC'), precision: 0 }, grid: { color: 'rgba(255,255,255,.06)' } } } })
        });
    }

    window.ciRecordKeywordRanking = async function () {
        if (!currentProfileId) return;
        const keyword = document.getElementById('ciKrKeyword').value.trim();
        if (!keyword) { toast(T('ci.js.name_required'), 'error'); return; }
        const position = document.getElementById('ciKrPosition').value;
        const url = document.getElementById('ciKrUrl').value.trim();
        const body = { keyword };
        if (position !== '') body.position = parseInt(position, 10);
        if (url) body.url = url;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/keyword-rankings', { method: 'POST', body: JSON.stringify(body) });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            document.getElementById('ciKrKeyword').value = '';
            document.getElementById('ciKrPosition').value = '';
            document.getElementById('ciKrUrl').value = '';
            ciLoadKeywordRankings();
        } else {
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    window.ciRunKeywordRankingCheck = async function () {
        if (!currentProfileId) return;
        const box = document.getElementById('ciKrCheckResult');
        box.textContent = T('ci.js.checking');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/keyword-rankings/check', { method: 'POST' });
        if (res.success) {
            if (res.data.available) {
                box.textContent = T('ci.js.added') + ' (' + res.data.recorded + ')';
                toast(T('ci.js.cycle_completed'), 'success');
            } else {
                box.textContent = T('ci.profile.keywords_no_source');
                toast(T('ci.profile.keywords_no_source'), 'info');
            }
            ciLoadKeywordRankings();
        } else {
            box.textContent = res.error || T('ci.js.failed');
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    let ciPriceLoadVersion = 0;
    async function ciLoadProductPrices() {
        const profileId = currentProfileId;
        if (!profileId) return;
        const version = ++ciPriceLoadVersion;
        const box = document.getElementById('ciProfileProductPrices');
        box.innerHTML = '<tr><td colspan="7">جارِ تحميل الأسعار...</td></tr>';
        let res;
        try { res = await fetchJSON('/api/competitor-intelligence/competitors/' + profileId + '/product-prices'); } catch (error) { res = null; }
        if (version !== ciPriceLoadVersion || profileId !== currentProfileId) return;
        if (!res || !res.success || !res.data || !Array.isArray(res.data.products)) {
            box.innerHTML = '<tr><td colspan="7" role="alert">تعذر تحميل الأسعار. <button class="p-btn outline xs" onclick="ciReloadProductPrices()">إعادة المحاولة</button></td></tr>';
            return;
        }
        const rows = res.data.products;
        document.getElementById('ciProfileProductPrices').innerHTML = rows.length
            ? rows.map(p => `<tr>
                <td><strong>${esc(p.product_name)}</strong>${p.page_type ? ` <span class="pill gray">${esc(p.page_type)}</span>` : ''}</td>
                <td><strong>${fmtPrice(p.latest_price, p.currency)}</strong></td>
                <td>${fmtPrice(p.first_price, p.currency)}</td>
                <td>${esc(p.currency)}</td>
                <td>${esc(p.readings)}</td>
                <td>${esc(p.last_detected_at)}</td>
                <td><button class="p-btn outline xs" data-product="${esc(p.product_name)}" onclick="ciLoadProductPriceHistory(this.dataset.product)">${ic('chart')} ${esc(T('ci.profile.price_history'))}</button></td>
              </tr>`).join('')
            : emptyRow(7, 'chart', T('ci.profile.prices_empty'));
    }

    async function ciLoadProductPriceHistory(product) {
        const box = document.getElementById('ciPpHistory');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/product-prices/history?product=' + encodeURIComponent(product));
        if (!res.success || !(res.data.history || []).length) { box.innerHTML = ''; return; }
        box.innerHTML = `<div class="p-card">
            <div class="p-card-head"><h4 style="margin:0;">${ic('trending')} ${esc(T('ci.profile.price_history'))}: <span dir="ltr">${esc(product)}</span></h4></div>
            <div style="height:150px;position:relative;"><canvas id="ciPpHistoryChart"></canvas></div>
        </div>`;
        if (typeof Chart === 'undefined' || !document.getElementById('ciPpHistoryChart')) return;
        if (ciPpHistoryChartInstance) { ciPpHistoryChartInstance.destroy(); ciPpHistoryChartInstance = null; }
        const series = res.data.history;
        ciPpHistoryChartInstance = new Chart(document.getElementById('ciPpHistoryChart'), {
            type: 'line',
            data: {
                labels: series.map(h => h.detected_at.slice(0, 10)),
                datasets: [{ label: product, data: series.map(h => h.price), borderColor: cssVar('--panel-teal', '#4ECDC4'), backgroundColor: 'rgba(78,205,196,.12)', fill: true, tension: 0.25 }]
            },
            options: chartTheme({ plugins: { legend: { display: false } }, scales: { x: { ticks: { color: cssVar('--panel-text-muted', '#8996AC'), maxRotation: 45, font: { size: 9 } }, grid: { display: false } }, y: { ticks: { color: cssVar('--panel-text-muted', '#8996AC') }, grid: { color: 'rgba(255,255,255,.06)' } } } })
        });
    }

    window.ciRecordProductPrice = async function () {
        if (!currentProfileId) return;
        const product_name = document.getElementById('ciPpProduct').value.trim();
        const price = document.getElementById('ciPpPrice').value;
        if (!product_name || !price || parseFloat(price) <= 0) { toast(T('ci.js.name_required'), 'error'); return; }
        const body = { product_name, price: parseFloat(price), currency: document.getElementById('ciPpCurrency').value.trim() || 'USD' };
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/product-prices', { method: 'POST', body: JSON.stringify(body) });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            document.getElementById('ciPpProduct').value = '';
            document.getElementById('ciPpPrice').value = '';
            ciLoadProductPrices();
        } else {
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    async function ciLoadBattlecard() {
        const box = document.getElementById('ciProfileBattlecard');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/battlecard');
        if (!res.success || !res.data.battlecard) {
            box.innerHTML = emptyBlock('briefcase', T('ci.profile.battlecard_empty'), T('ci.profile.battlecard_insufficient'));
            return;
        }
        box.innerHTML = ciRenderBattlecard(res.data.battlecard);
    }

    function ciRenderBattlecard(b) {
        const strengths = (b.strengths || []).map(s => `<li>${esc(s)}</li>`).join('');
        const weaknesses = (b.weaknesses || []).map(w => `<li>${esc(w)}</li>`).join('');
        const prices = (b.price_position || []).map(p => `<li dir="ltr"><strong>${esc(p.product)}</strong>: ${fmtPrice(p.latest_price, p.currency)} <span class="ci-form-note">(first: ${fmtPrice(p.first_price, p.currency)} · ${esc(p.readings)} readings)</span></li>`).join('');
        const content = b.content_position || {};
        const contentLines = [];
        if (typeof content.total_changes === 'number') contentLines.push(`<li>${esc(T('ci.profile.readings'))}: ${esc(content.total_changes)} change(s), ${esc(content.high_severity)} high-severity</li>`);
        Object.entries(content.by_page || {}).forEach(([k, v]) => contentLines.push(`<li>${esc(k)}: ${esc(v)}</li>`));
        const actions = (b.recommended_actions || []).map(a => `<li><strong>${esc(a.action)}</strong><div class="ci-form-note">${esc(a.rationale)}</div></li>`).join('');
        const evidence = b.evidence ? `<div class="ci-form-note" style="margin-top:6px;white-space:pre-wrap;">${esc(JSON.stringify(b.evidence, null, 2))}</div>` : '';

        return `<div class="p-card" style="margin-bottom:12px;">
            <div class="p-card-head"><h4 style="margin:0;">${ic('briefcase')} ${esc(b.title)}</h4><span class="ci-form-note">${esc(T('ci.profile.generated_at'))}: ${esc(b.generated_at)}</span></div>
            ${b.positioning_summary ? `<div class="p-cell-muted" style="margin-top:8px;">${esc(b.positioning_summary)}</div>` : ''}
        </div>
        <div class="p-grid cols-2" style="gap:12px;margin-bottom:12px;">
            <div class="p-card"><div class="p-card-head"><h4 style="margin:0;">${ic('trending')} ${esc(T('ci.profile.strengths'))}</h4></div><ul style="margin:10px 0 0;padding-inline-start:18px;">${strengths || `<li class="ci-form-note">-</li>`}</ul></div>
            <div class="p-card"><div class="p-card-head"><h4 style="margin:0;">${ic('alert')} ${esc(T('ci.profile.weaknesses'))}</h4></div><ul style="margin:10px 0 0;padding-inline-start:18px;">${weaknesses || `<li class="ci-form-note">-</li>`}</ul></div>
        </div>
        <div class="p-grid cols-2" style="gap:12px;margin-bottom:12px;">
            <div class="p-card"><div class="p-card-head"><h4 style="margin:0;">${ic('chart')} ${esc(T('ci.profile.price_position'))}</h4></div><ul style="margin:10px 0 0;padding-inline-start:18px;">${prices || `<li class="ci-form-note">-</li>`}</ul></div>
            <div class="p-card"><div class="p-card-head"><h4 style="margin:0;">${ic('zap')} ${esc(T('ci.profile.content_position'))}</h4></div><ul style="margin:10px 0 0;padding-inline-start:18px;">${contentLines.join('') || `<li class="ci-form-note">-</li>`}</ul></div>
        </div>
        <div class="p-card" style="margin-bottom:12px;">
            <div class="p-card-head"><h4 style="margin:0;">${ic('check')} ${esc(T('ci.profile.recommended_actions'))}</h4></div>
            <ul style="margin:10px 0 0;padding-inline-start:18px;">${actions || `<li class="ci-form-note">-</li>`}</ul>
        </div>
        <div class="p-card">
            <div class="p-card-head"><h4 style="margin:0;">${ic('shield')} ${esc(T('ci.profile.evidence'))}</h4></div>
            ${evidence}
        </div>`;
    }

    window.ciGenerateBattlecard = async function () {
        if (!currentProfileId) return;
        document.getElementById('ciProfileBattlecard').innerHTML = `<div class="skeleton" style="height:80px;"></div>`;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/battlecard/generate', { method: 'POST' });
        if (res.success && res.data.battlecard) {
            document.getElementById('ciProfileBattlecard').innerHTML = ciRenderBattlecard(res.data.battlecard);
            toast(T('ci.js.cycle_completed'), 'success');
        } else if (res.success && res.data.available === false) {
            document.getElementById('ciProfileBattlecard').innerHTML = emptyBlock('briefcase', T('ci.profile.battlecard_empty'), T('ci.profile.battlecard_insufficient'));
            toast(T('ci.profile.battlecard_insufficient'), 'info');
        } else {
            document.getElementById('ciProfileBattlecard').innerHTML = emptyBlock('alert', res.error || T('ci.js.failed'));
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    window.ciLoadProductPriceHistory = ciLoadProductPriceHistory;
    window.ciReloadProductPrices = ciLoadProductPrices;

    // ---------- boot ----------
    document.getElementById('ciModalClose').addEventListener('click', () => ciCloseModal(null));
    ciSwitchTab('dashboard');
    refreshUnreadBadge();
})();
JS;
        return $script;
    }
}
