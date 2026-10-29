<?php
require_once __DIR__ . '/../Brain/BrainSchema.php';

/**
 * TOURFECTO AI CEO (المرحلة 9) — الذكاء التنفيذي والتعلم ومؤشر صحة الشركة.
 *
 * TOURFECTO BUSINESS HEALTH SCORE (0-100) — 8 مكونات بأوزان ثابتة (المجموع 100):
 *   النمو 15 · الإيرادات والربحية 15 · رضا العملاء 10 · الاحتفاظ بالعملاء 15 · جودة الخدمات 10 · استقرار المنصة 15 · الأمان 10 · كفاءة التشغيل 10
 * كل مكون من بيانات حقيقية، ولو بياناته مش موجودة بيبقى NULL. الدرجة الكاملة بتطلع بس لو كل المكونات موجودة؛
 * غير كده بنعرض "غير مكتمل" + درجة جزئية على المكونات الموجودة + نسبة التغطية. مفيش درجة مخترعة.
 *
 * وكمان: تقرير يومي وأسبوعي (حصل إيه، اتحسن إيه، اتراجع إيه، المشكلة، الأولوية، محتاج موافقتك، النتايج الحقيقية، الخطوة الجاية)،
 * مراجعة قرارات AI CEO (اللي نجحت واللي بترجع تاني) والدروس المستفادة، جودة البيانات قبل القرارات، جودة مخرجات الذكاء الاصطناعي،
 * المنافسين (يدوي + متابعة تغيير صفحتهم بطلب آمن SafeHttp)، طلبات العملاء، ومؤشرات السوق.
 */
final class ExecutiveIntel
{
    public const WEIGHTS = ['growth' => 15, 'profitability' => 15, 'satisfaction' => 10, 'retention' => 15, 'quality' => 10, 'stability' => 15, 'security' => 10, 'efficiency' => 10];
    public const LABELS = ['growth' => 'Growth', 'profitability' => 'Revenue & profitability', 'satisfaction' => 'Customer satisfaction', 'retention' => 'Retention', 'quality' => 'Service quality',
        'stability' => 'Platform stability', 'security' => 'Security', 'efficiency' => 'Operating efficiency'];
    public const METHOD = [
        'growth' => 'MRR change vs 30 days ago (60 at 0%, +2 per 1%) averaged with sign-ups this week vs last week (50 at equal).',
        'profitability' => 'Last full month margin after AI, SEO data and fixed costs: 50 + margin %. Needs fixed costs entered.',
        'satisfaction' => 'Average Google rating of the Tourfecto business profile: 1★ = 0, 5★ = 100. Needs reviews.',
        'retention' => '100 − 5 × monthly customer churn % (20% churn = 0). Needs paying customers 30 days ago.',
        'quality' => '100 − 20 per page down − 5 per slow page − 2 per unanswered support message (max 10). Needs a page check.',
        'stability' => '100 − 15 per failing health check − 3 per warning − 10 per open high/critical incident.',
        'security' => '100 − 30 per protected page open without login − 10 per IP attacking logins − 5 per customer with abnormal usage.',
        'efficiency' => 'AI cost as a share of revenue over 30 days: 10% or less = 100, 100% or more = 0.',
    ];
    private const REQUEST_WORDS = ['please add', 'feature request', 'would be great', 'can you add', 'suggest', 'wish', 'اقتراح', 'ياريت', 'يا ريت', 'أتمنى', 'اتمنى', 'ممكن تضيفوا', 'ممكن تضيف', 'محتاجين خاصية'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        BrainSchema::ensure($this->db);
    }

    private function q(string $sql, array $p = []): array
    {
        try { return $this->db->query($sql, $p) ?: []; } catch (Throwable $e) { return []; }
    }

    private static function clamp(float $v): int
    {
        return (int) round(max(0, min(100, $v)));
    }

    // ------------------------------------------------------------------ مؤشر صحة الشركة

    /** حساب المكونات (منطق نقي). $x: margin_pct, fixed_entered, revenue_30d, ai_cost_30d, brand_rating, brand_reviews, auth_incidents */
    public static function components(array $snap, array $x): array
    {
        $rev = $snap['revenue'] ?? null;
        $sg = $snap['signups'] ?? null;
        $op = $snap['ops'] ?? null;
        $pl = $snap['platform'] ?? null;
        $c = [];
        // النمو
        if ($rev || $sg) {
            $parts = [];
            if ($rev && $rev['mrr']['prev'] !== null) {
                $now = (float) $rev['mrr']['now'];
                $prev = (float) $rev['mrr']['prev'];
                if ($prev > 0) $parts[] = 60 + (($now - $prev) / $prev * 100) * 2;
                elseif ($now > 0) $parts[] = 100;
            }
            if ($sg) $parts[] = 50 + (($sg['last_7d'] - $sg['prev_7d']) / max(1, $sg['prev_7d'])) * 50;
            $c['growth'] = $parts ? self::clamp(array_sum($parts) / count($parts)) : null;
        } else $c['growth'] = null;
        // الربحية
        $c['profitability'] = !empty($x['fixed_entered']) && array_key_exists('margin_pct', $x) ? ($x['margin_pct'] === null ? 0 : self::clamp(50 + (float) $x['margin_pct'])) : null;
        // الرضا
        $c['satisfaction'] = !empty($x['brand_reviews']) && $x['brand_rating'] !== null ? self::clamp(((float) $x['brand_rating'] - 1) / 4 * 100) : null;
        // الاحتفاظ
        $c['retention'] = $rev && (int) ($rev['paying']['prev'] ?? 0) > 0 ? self::clamp(100 - 5 * (float) ($rev['logo_churn']['now'] ?? 0)) : null;
        // جودة الخدمات
        $c['quality'] = $op && $op['last_check_at'] ? self::clamp(100 - 20 * (int) $op['pages_failed'] - 5 * (int) $op['pages_slow'] - 2 * min(10, (int) ($snap['success']['support_unanswered'] ?? 0))) : null;
        // الاستقرار
        if ($pl) {
            $inc = $op ? (int) ($op['incidents_open']['critical'] ?? 0) + (int) ($op['incidents_open']['high'] ?? 0) : 0;
            $c['stability'] = self::clamp(100 - 15 * (int) ($pl['counts']['fail'] ?? 0) - 3 * (int) ($pl['counts']['warn'] ?? 0) - 10 * $inc);
        } else $c['stability'] = null;
        // الأمان
        $c['security'] = $op ? self::clamp(100 - 30 * (int) ($x['auth_incidents'] ?? 0) - 10 * (int) ($op['abuse_ips'] ?? 0) - 5 * (int) ($op['abuse_users'] ?? 0)) : null;
        // الكفاءة
        $r = (float) ($x['revenue_30d'] ?? 0);
        $a = (float) ($x['ai_cost_30d'] ?? 0);
        $c['efficiency'] = $r > 0 ? self::clamp(100 - (($a / $r * 100) - 10) / 90 * 100) : ($a > 0 ? 0 : null);
        return $c;
    }

    /** الدرجة من المكونات (منطق نقي) */
    public static function score(array $components): array
    {
        $have = array_filter($components, static fn ($v) => $v !== null);
        $cov = array_sum(array_intersect_key(self::WEIGHTS, $have));
        $partial = $cov > 0 ? (int) round(array_sum(array_map(static fn ($k) => $have[$k] * self::WEIGHTS[$k], array_keys($have))) / $cov) : null;
        return ['score' => $cov === 100 ? $partial : null, 'partial_score' => $partial, 'coverage' => $cov, 'complete' => $cov === 100,
            'missing' => array_values(array_keys(array_filter($components, static fn ($v) => $v === null)))];
    }

    /** البيانات الإضافية من قاعدة البيانات */
    public function extra(array $snap): array
    {
        $x = ['fixed_entered' => (bool) ($snap['finance']['fixed_entered'] ?? false)];
        // آخر شهر كامل
        try {
            require_once __DIR__ . '/FinanceStrategy.php';
            $m = (new FinanceStrategy($this->db))->pnl(2)['months'][0] ?? null;
            if ($m) $x['margin_pct'] = $m['margin_pct'];
        } catch (Throwable $e) {}
        $x['revenue_30d'] = (float) ($this->q("SELECT COALESCE(-SUM(amount), 0) AS v FROM wallet_transactions WHERE type = 'subscription_charge' AND status = 'completed' AND amount < 0 AND created_at >= NOW() - INTERVAL 30 DAY")[0]['v'] ?? 0);
        $x['ai_cost_30d'] = (float) ($this->q('SELECT COALESCE(SUM(cost_in_usd), 0) AS v FROM api_usage_logs WHERE created_at >= NOW() - INTERVAL 30 DAY')[0]['v'] ?? 0);
        try {
            require_once __DIR__ . '/BrandGrowth.php';
            require_once __DIR__ . '/BrandSiteAudit.php';
            $p = (new BrandGrowth($this->db))->presence((new BrandSiteAudit($this->db))->siteUrl());
            $x['brand_reviews'] = (int) ($p['reviews']['total'] ?? 0);
            $x['brand_rating'] = $p['reviews']['avg_rating'] ?? null;
        } catch (Throwable $e) { $x['brand_reviews'] = 0; $x['brand_rating'] = null; }
        $x['auth_incidents'] = (int) ($this->q("SELECT COUNT(*) AS n FROM aiceo_incidents WHERE status = 'open' AND ikey LIKE 'auth:%'")[0]['n'] ?? 0);
        return $x;
    }

    /** الدرجة دلوقتي، وتتحفظ لليوم (صف واحد لكل يوم) */
    public function health(array $snap, bool $save = true): array
    {
        $comp = self::components($snap, $this->extra($snap));
        $s = self::score($comp);
        if ($save) {
            $this->db->query('INSERT INTO aiceo_health_scores (day, score, partial_score, coverage, components_json, created_at) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE score = VALUES(score), partial_score = VALUES(partial_score), coverage = VALUES(coverage), components_json = VALUES(components_json), created_at = VALUES(created_at)',
                [gmdate('Y-m-d'), $s['score'], $s['partial_score'], $s['coverage'], json_encode($comp), gmdate('Y-m-d H:i:s')]);
        }
        $prev = $this->q('SELECT day, score, partial_score, components_json FROM aiceo_health_scores WHERE day < ? ORDER BY day DESC LIMIT 1', [gmdate('Y-m-d')])[0] ?? null;
        $prevC = $prev ? (json_decode((string) $prev['components_json'], true) ?: []) : [];
        $trend = array_map(static fn ($r) => ['day' => $r['day'], 'score' => $r['score'] !== null ? (int) $r['score'] : null, 'partial_score' => $r['partial_score'] !== null ? (int) $r['partial_score'] : null, 'coverage' => (int) $r['coverage']],
            array_reverse($this->q('SELECT day, score, partial_score, coverage FROM aiceo_health_scores ORDER BY day DESC LIMIT 30')));
        $items = [];
        foreach (self::WEIGHTS as $k => $w) $items[] = ['key' => $k, 'label' => self::LABELS[$k], 'weight' => $w, 'value' => $comp[$k], 'previous' => $prevC[$k] ?? null, 'method' => self::METHOD[$k]];
        return $s + ['components' => $items, 'previous_day' => $prev['day'] ?? null, 'trend' => $trend];
    }

    /** مكونات اتحسنت/اتراجعت (منطق نقي) */
    public static function changes(array $items, int $min = 3): array
    {
        $up = $down = [];
        foreach ($items as $i) {
            if ($i['value'] === null || $i['previous'] === null) continue;
            $d = $i['value'] - $i['previous'];
            if ($d >= $min) $up[] = $i['label'] . ' +' . $d;
            elseif ($d <= -$min) $down[] = $i['label'] . ' ' . $d;
        }
        return ['improved' => $up, 'declined' => $down];
    }

    // ------------------------------------------------------------------ التقرير اليومي والأسبوعي

    public function report(string $period, array $snap, array $health, array $decisions): array
    {
        $days = $period === 'weekly' ? 7 : 1;
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $acts = [];
        foreach ($this->q('SELECT action, COUNT(*) AS n FROM aiceo_activity WHERE created_at >= ? GROUP BY action', [$since]) as $r) $acts[$r['action']] = (int) $r['n'];
        $inc = $this->q("SELECT SUM(first_seen >= ?) AS opened, SUM(status = 'resolved' AND resolved_at >= ?) AS resolved FROM aiceo_incidents", [$since, $since])[0] ?? [];
        $signups = (int) ($this->q("SELECT COUNT(*) AS n FROM users u WHERE u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND u.created_at >= ?", [$since])[0]['n'] ?? 0);
        $charges = (float) ($this->q("SELECT COALESCE(-SUM(amount), 0) AS v FROM wallet_transactions WHERE type = 'subscription_charge' AND status = 'completed' AND amount < 0 AND created_at >= ?", [$since])[0]['v'] ?? 0);
        $open = array_values(array_filter($decisions, static fn ($d) => in_array($d['status'], ['proposed', 'approved'], true)));
        usort($open, static fn ($a, $b) => $b['priority'] <=> $a['priority']);
        $approval = array_values(array_filter($open, static fn ($d) => $d['status'] === 'proposed' && ($d['requires_approval'] ?? $d['risk'] !== 'low')));
        $backlogApproval = (int) ($this->q("SELECT COUNT(*) AS n FROM aiceo_dev_backlog WHERE status = 'open' AND needs_approval = 1")[0]['n'] ?? 0);
        $resolved = array_map(static fn ($r) => (json_decode((string) $r['detail_json'], true) ?: [])['key'] ?? '', $this->q("SELECT detail_json FROM aiceo_activity WHERE action = 'decision_resolved' AND created_at >= ? LIMIT 20", [$since]));
        $done = $this->q("SELECT service, problem, metric_before, metric_after FROM aiceo_dev_backlog WHERE status = 'done' AND updated_at >= ? LIMIT 10", [$since]);
        $ch = self::changes($health['components']);
        $title = static fn ($d) => $d['title']['en'] ?? $d['key'] ?? '';
        $r = [
            'period' => $period, 'since' => $since,
            'happened' => array_values(array_filter([$signups . ' new sign-ups', '$' . round($charges, 2) . ' subscription charges', ($acts['decision_proposed'] ?? 0) . ' new decisions', ($acts['decision_resolved'] ?? 0) . ' problems resolved',
                (int) ($inc['opened'] ?? 0) . ' incidents opened, ' . (int) ($inc['resolved'] ?? 0) . ' resolved', ($acts['decision_auto_approved'] ?? 0) ? ($acts['decision_auto_approved'] . ' low-risk decisions auto-approved') : null])),
            'improved' => $ch['improved'], 'declined' => $ch['declined'],
            'problem' => $open ? $title($open[0]) : null,
            'priorities' => array_map($title, array_slice($open, 0, 3)),
            'needs_approval' => array_values(array_filter(array_merge(array_map($title, array_slice($approval, 0, 5)), [$backlogApproval ? $backlogApproval . ' development items await your approval' : null]))),
            'results' => array_values(array_merge(array_map(static fn ($k) => 'Resolved: ' . $k, array_filter($resolved)), array_map(static fn ($b) => 'Done: [' . $b['service'] . '] ' . $b['problem'] . ' (before ' . ($b['metric_before'] ?: '—') . ', after ' . ($b['metric_after'] ?: '—') . ')', $done))),
            'next_step' => $open ? (($open[0]['action']['en'] ?? 'Review') . ': ' . $title($open[0])) : 'Nothing urgent. Review goals and the backlog.',
            'health' => ['score' => $health['score'], 'partial_score' => $health['partial_score'], 'coverage' => $health['coverage']],
        ];
        return $r;
    }

    public static function reportText(array $r): string
    {
        $l = static fn (string $h, array $items) => $h . ":\n" . ($items ? '- ' . implode("\n- ", $items) : '- none') . "\n";
        return 'Business health: ' . ($r['health']['score'] !== null ? $r['health']['score'] . '/100' : 'incomplete (' . ($r['health']['partial_score'] ?? '—') . ' on ' . $r['health']['coverage'] . '% of the weight)') . "\n\n"
            . $l('What happened', $r['happened']) . $l('Improved', $r['improved']) . $l('Declined', $r['declined']) . 'Main problem: ' . ($r['problem'] ?? 'none') . "\n"
            . $l('Priorities', $r['priorities']) . $l('Needs your approval', $r['needs_approval']) . $l('Real results', $r['results']) . 'Next step: ' . $r['next_step'];
    }

    /** يكتب التقرير اليومي (والأسبوعي يوم الاتنين) في الذاكرة مرة واحدة. بيرجع اللي اتكتب */
    public function writeReports(AiCeoStore $store, array $snap, array $decisions, ?int $now = null): array
    {
        $now = $now ?? time();
        $health = $this->health($snap);
        $out = [];
        foreach (['daily' => true, 'weekly' => gmdate('N', $now) === '1'] as $p => $due) {
            if (!$due) continue;
            $t = 'Executive report ' . $p . ' ' . gmdate('Y-m-d', $now);
            if ($this->q('SELECT 1 FROM aiceo_memory WHERE kind = ? AND title = ? LIMIT 1', ['result', $t])) continue;
            $store->remember('result', $t, self::reportText($this->report($p, $snap, $health, $decisions)), null, null);
            $out[] = $p;
        }
        return $out;
    }

    // ------------------------------------------------------------------ مراجعة القرارات والدروس

    public function decisionReview(int $days = 30): array
    {
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $by = [];
        foreach ($this->q("SELECT action, detail_json FROM aiceo_activity WHERE created_at >= ? AND action IN ('decision_proposed','decision_resolved','decision_reopened','decision_dismissed','decision_approved','decision_done','decision_auto_approved')", [$since]) as $r) {
            $k = (json_decode((string) $r['detail_json'], true) ?: [])['key'] ?? null;
            if (!$k) continue;
            $a = substr($r['action'], 9);
            $by[$k][$a] = ($by[$k][$a] ?? 0) + 1;
        }
        $worked = $recurring = $ignored = [];
        foreach ($by as $k => $c) {
            if (($c['reopened'] ?? 0) >= 2) $recurring[] = ['key' => $k, 'reopened' => $c['reopened'], 'lesson' => 'This problem keeps coming back after it is resolved: fix the root cause, not the symptom.'];
            elseif (($c['resolved'] ?? 0) > 0 && (($c['approved'] ?? 0) + ($c['done'] ?? 0) + ($c['auto_approved'] ?? 0)) > 0) $worked[] = ['key' => $k, 'resolved' => $c['resolved']];
            if (($c['dismissed'] ?? 0) >= 2) $ignored[] = ['key' => $k, 'dismissed' => $c['dismissed'], 'lesson' => 'You dismissed this more than once: it may matter less than its score says.'];
        }
        $totals = ['proposed' => 0, 'resolved' => 0, 'reopened' => 0, 'dismissed' => 0, 'approved' => 0, 'done' => 0, 'auto_approved' => 0];
        foreach ($by as $c) foreach ($c as $a => $n) if (isset($totals[$a])) $totals[$a] += $n;
        return ['days' => $days, 'totals' => $totals, 'worked' => array_slice($worked, 0, 20), 'recurring' => $recurring, 'ignored' => $ignored,
            'resolution_rate' => $totals['proposed'] > 0 ? round($totals['resolved'] / $totals['proposed'] * 100, 1) : null];
    }

    /** دروس تلقائية من المراجعة (مرة واحدة لكل درس) */
    public function learn(AiCeoStore $store, array $review): int
    {
        $n = 0;
        foreach (array_merge($review['recurring'], $review['ignored']) as $x) {
            $t = 'Lesson: ' . $x['key'] . ' — ' . (isset($x['reopened']) ? 'keeps coming back' : 'often dismissed');
            if ($this->q('SELECT 1 FROM aiceo_memory WHERE kind = ? AND title = ? LIMIT 1', ['note', $t])) continue;
            $store->remember('note', $t, $x['lesson'], null, null);
            $n++;
        }
        return $n;
    }

    public function lessons(int $limit = 30): array
    {
        return $this->q("SELECT id, title, body, created_at FROM aiceo_memory WHERE kind = 'note' AND title LIKE 'Lesson:%' ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
    }

    // ------------------------------------------------------------------ جودة البيانات وجودة الذكاء الاصطناعي

    /** هل البيانات اللي بنقرر عليها حديثة وكاملة؟ */
    public function dataQuality(array $snap): array
    {
        $checks = [];
        $age = isset($snap['generated_at']) ? (time() - strtotime($snap['generated_at'] . (str_contains((string) $snap['generated_at'], 'T') ? '' : ' UTC'))) / 3600 : null;
        $checks[] = ['source' => 'Platform snapshot', 'ok' => $age !== null && $age < 3, 'detail' => $age === null ? 'never built' : round($age, 1) . ' hours old'];
        foreach (array_diff_key($snap, ['generated_at' => 1]) as $k => $v) if ($v === null) $checks[] = ['source' => 'Section ' . $k, 'ok' => false, 'detail' => 'could not load; decisions in this area are missing'];
        foreach (['visitor_logs' => ['Visitor tracking', 24], 'api_usage_logs' => ['AI usage log', 48], 'aiceo_ops_checks' => ['Page checks', 3], 'market_fetch_log' => ['Market sources', 72], 'platform_leads' => ['Tourism companies import', 24 * 14]] as $t => [$label, $maxH]) {
            $col = $t === 'aiceo_ops_checks' ? 'run_at' : ($t === 'market_fetch_log' ? 'fetched_at' : 'created_at');
            $last = $this->q("SELECT MAX({$col}) AS t FROM {$t}")[0]['t'] ?? null;
            $h = $last ? (time() - strtotime($last . ($t === 'aiceo_ops_checks' || $t === 'market_fetch_log' ? ' UTC' : ''))) / 3600 : null;
            $checks[] = ['source' => $label, 'ok' => $h !== null && $h <= $maxH, 'detail' => $h === null ? 'no data' : 'last ' . round($h, 1) . ' hours ago (expected within ' . $maxH . 'h)'];
        }
        $ok = count(array_filter($checks, static fn ($c) => $c['ok']));
        return ['score' => $checks ? (int) round($ok / count($checks) * 100) : null, 'checks' => $checks];
    }

    public function aiQuality(): array
    {
        $features = array_map(static fn ($r) => ['feature' => (string) ($r['feature'] ?: 'unknown'), 'calls' => (int) $r['calls'], 'errors' => (int) $r['errors'], 'error_rate' => (int) $r['calls'] > 0 ? round((int) $r['errors'] / (int) $r['calls'] * 100, 1) : 0.0, 'avg_ms' => (int) $r['avg_ms']],
            $this->q('SELECT feature, COUNT(*) AS calls, SUM(status_code >= 400) AS errors, ROUND(AVG(duration_ms)) AS avg_ms FROM api_usage_logs WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY feature HAVING calls >= 5 ORDER BY errors DESC, calls DESC LIMIT 20'));
        $replies = [];
        foreach ($this->q("SELECT COALESCE(reply_status, 'none') AS s, COUNT(*) AS n FROM reviews WHERE updated_at >= NOW() - INTERVAL 30 DAY AND reply_status IS NOT NULL GROUP BY reply_status") as $r) $replies[$r['s']] = (int) $r['n'];
        $plans = $this->q("SELECT body FROM aiceo_memory WHERE kind = 'plan' AND title LIKE 'Brand content plan%' ORDER BY id DESC LIMIT 5");
        $warn = array_sum(array_map(static fn ($p) => count((json_decode((string) $p['body'], true) ?: [])['_warnings'] ?? []), $plans));
        return ['features' => $features, 'review_replies_30d' => $replies, 'brand_plan_warnings' => $warn, 'brand_plans' => count($plans),
            'note' => 'Errors are failed AI calls. Review replies: rejected or failed drafts point to weak outputs. Plan warnings are promises the AI wrote that had to be removed.'];
    }

    // ------------------------------------------------------------------ المنافسين

    public function competitors(): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'url' => $r['url'], 'starting_price_usd' => $r['starting_price_usd'] !== null ? (float) $r['starting_price_usd'] : null, 'pricing_notes' => $r['pricing_notes'],
            'features' => $r['features'], 'strengths' => $r['strengths'], 'weaknesses' => $r['weaknesses'], 'page_title' => $r['page_title'], 'page_changed_at' => $r['page_changed_at'], 'checked_at' => $r['checked_at'], 'updated_at' => $r['updated_at']],
            $this->db->query('SELECT * FROM aiceo_competitors ORDER BY name') ?: []);
    }

    public function saveCompetitor(array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Enter the competitor name.');
        $url = trim((string) ($in['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) throw new InvalidArgumentException('The website must start with http:// or https://');
        $price = $in['starting_price_usd'] ?? '';
        if ($price !== '' && $price !== null && (!is_numeric($price) || (float) $price < 0)) throw new InvalidArgumentException('Price must be a number in USD.');
        $now = gmdate('Y-m-d H:i:s');
        $vals = [mb_substr($name, 0, 120), $url !== '' ? mb_substr($url, 0, 255) : null, $price === '' || $price === null ? null : round((float) $price, 2), mb_substr((string) ($in['pricing_notes'] ?? ''), 0, 500),
            mb_substr((string) ($in['features'] ?? ''), 0, 4000), mb_substr((string) ($in['strengths'] ?? ''), 0, 4000), mb_substr((string) ($in['weaknesses'] ?? ''), 0, 4000)];
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            $this->db->query('UPDATE aiceo_competitors SET name = ?, url = ?, starting_price_usd = ?, pricing_notes = ?, features = ?, strengths = ?, weaknesses = ?, updated_at = ? WHERE id = ?', [...$vals, $now, $id]);
            return $id;
        }
        $this->db->query('INSERT INTO aiceo_competitors (name, url, starting_price_usd, pricing_notes, features, strengths, weaknesses, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $now, $now]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM aiceo_competitors')[0]['id'] ?? 0);
    }

    public function deleteCompetitor(int $id): void
    {
        $this->db->query('DELETE FROM aiceo_competitors WHERE id = ?', [$id]);
    }

    /** بصمة نص الصفحة (منطق نقي): بنشيل السكربتات والأرقام المتغيرة عشان التغيير الحقيقي بس يتحسب */
    public static function pageFingerprint(string $html): array
    {
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $text = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html);
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text))));
        $text = preg_replace('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}:\d{2}\b/', '', $text);
        return ['title' => mb_substr($title, 0, 255), 'hash' => sha1((string) $text)];
    }

    /** فحص صفحات المنافسين (طلبات آمنة SafeHttp: روابط مكتوبة يدويًا). $fetch للاختبار */
    public function checkCompetitors(?callable $fetch = null): int
    {
        $changed = 0;
        foreach ($this->competitors() as $c) {
            if (!$c['url']) continue;
            $html = $fetch ? $fetch($c['url']) : self::safeGet($c['url']);
            if ($html === null) continue;
            $fp = self::pageFingerprint($html);
            $row = $this->db->query('SELECT page_hash FROM aiceo_competitors WHERE id = ?', [$c['id']])[0] ?? [];
            $isChange = !empty($row['page_hash']) && $row['page_hash'] !== $fp['hash'];
            $now = gmdate('Y-m-d H:i:s');
            $this->db->query('UPDATE aiceo_competitors SET page_title = ?, page_hash = ?, checked_at = ?' . ($isChange ? ', page_changed_at = ?' : '') . ' WHERE id = ?', array_merge([$fp['title'], $fp['hash'], $now], $isChange ? [$now] : [], [$c['id']]));
            if ($isChange) $changed++;
        }
        return $changed;
    }

    private static function safeGet(string $url): ?string
    {
        if (!class_exists('SafeHttp')) require_once __DIR__ . '/../../Security/SafeHttp.php';
        $r = SafeHttp::exec($url, [CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_MAXFILESIZE => 3000000, CURLOPT_USERAGENT => 'TourfectoMarketWatch/1.0 (+https://tourfecto.pro)'], 3);
        return ($r['code'] ?? 0) === 200 && is_string($r['response'] ?? null) ? $r['response'] : null;
    }

    /** مقارنة الأسعار (منطق نقي) */
    public static function priceComparison(array $competitors, array $ourPlans): array
    {
        $prices = array_values(array_filter(array_map(static fn ($c) => $c['starting_price_usd'], $competitors), static fn ($p) => $p !== null && $p > 0));
        sort($prices);
        $n = count($prices);
        $median = $n ? ($n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2) : null;
        $ours = array_values(array_filter(array_map(static fn ($p) => (float) $p['price'], $ourPlans), static fn ($p) => $p > 0));
        sort($ours);
        return ['competitor_median' => $median, 'competitors_priced' => $n, 'our_lowest' => $ours[0] ?? null, 'our_plans' => $ourPlans,
            'position' => $median === null || !$ours ? null : ($ours[0] < 0.8 * $median ? 'below market' : ($ours[0] > 1.2 * $median ? 'above market' : 'around market'))];
    }

    public function ourPlans(): array
    {
        return array_map(static fn ($r) => ['name' => $r['name'], 'price' => (float) $r['price'], 'cycle' => $r['billing_cycle']],
            $this->q("SELECT name, price, billing_cycle FROM subscription_plans WHERE COALESCE(is_active, 1) = 1 AND price > 0 ORDER BY price"));
    }

    // ------------------------------------------------------------------ طلبات العملاء ومؤشرات السوق

    public function customerRequests(int $days = 90): array
    {
        $rows = $this->q('SELECT id, message, user_id, created_at FROM contact_submissions WHERE created_at >= ? ORDER BY id DESC LIMIT 1000', [gmdate('Y-m-d H:i:s', strtotime("-{$days} days"))]);
        $out = [];
        foreach ($rows as $r) {
            $t = mb_strtolower((string) $r['message']);
            foreach (self::REQUEST_WORDS as $w) if (str_contains($t, $w)) { $out[] = ['id' => (int) $r['id'], 'from_customer' => (int) $r['user_id'] > 0, 'text' => mb_substr((string) $r['message'], 0, 300), 'at' => $r['created_at']]; break; }
        }
        return array_slice($out, 0, 30);
    }

    public function marketSignals(): array
    {
        return array_map(static fn ($r) => ['source' => $r['source'], 'series' => $r['series_key'], 'period' => $r['period'], 'value' => $r['value'] !== null ? (float) $r['value'] : null, 'fetched_at' => $r['fetched_at']],
            $this->q('SELECT source, series_key, period, value, fetched_at FROM market_signals ORDER BY fetched_at DESC, id DESC LIMIT 15'));
    }

    public function knowledge(int $limit = 30): array
    {
        return $this->q("SELECT id, title, body, created_at FROM aiceo_memory WHERE kind = 'note' AND title LIKE 'Knowledge:%' ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
    }
}
