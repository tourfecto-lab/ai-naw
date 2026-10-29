<?php
require_once __DIR__ . '/AiCostIntelligence.php';
require_once __DIR__ . '/SalesEngine.php';

/**
 * TOURFECTO AI CEO — صورة شركة Tourfecto نفسها (مش شركة عميل).
 *
 * بتجمع من خدمات الأدمن الموجودة بدل ما تحسب من أول وجديد:
 *   الإيرادات SaasMetricsService · التجارب TrialFollowUpService · صحة العملاء CustomerHealthService
 *   صحة المنصة PlatformHealthService · الأخطاء ErrorLogService · تكلفة الذكاء الاصطناعي AIUsageStatsService
 *   التسويق للشركات PlatformOutreachService · التسجيلات (users) · التكلفة والميزانيات AiCostIntelligence (المرحلة 2).
 * كل قسم بيرجع null لو مصدره مش متاح، والقرارات والمحادثة بتقول "مش معروف" بدل ما تخمّن.
 * الأرقام مجمّعة على مستوى المنصة؛ مفيش بيانات عميل بعينه هنا غير أعداد ومبالغ إجمالية.
 */
final class PlatformSnapshot
{
    /** نفس تعريف "عميل" في خدمات الأدمن: مش أدمن، ومش عضو فريق تحت حساب تاني */
    private const CUSTOMER = "u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND (u.owner_user_id IS NULL OR u.owner_user_id = 0)";

    private Database $db;
    /** @var array<string,callable> للاختبارات: بدل أي قسم */
    private array $sources;

    public function __construct(?Database $db = null, array $sources = [])
    {
        $this->db = $db ?? Database::getInstance();
        $this->sources = $sources;
    }

    public function collect(): array
    {
        return [
            'revenue' => $this->section('revenue', fn () => $this->revenue()),
            'signups' => $this->section('signups', fn () => $this->signups()),
            'trials' => $this->section('trials', fn () => $this->trials()),
            'customers' => $this->section('customers', fn () => $this->customers()),
            'platform' => $this->section('platform', fn () => $this->platform()),
            'errors' => $this->section('errors', fn () => $this->errors()),
            'ai_cost' => $this->section('ai_cost', fn () => $this->aiCost()),
            'outreach' => $this->section('outreach', fn () => $this->outreach()),
            'sales' => $this->section('sales', fn () => $this->sales()),
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function section(string $name, callable $fn): ?array
    {
        try {
            $out = isset($this->sources[$name]) ? ($this->sources[$name])() : $fn();
            return is_array($out) ? $out : null;
        } catch (Throwable $e) {
            if (class_exists('Logger')) Logger::warning('AI CEO snapshot section unavailable', ['section' => $name, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function revenue(): array
    {
        $k = (new SaasMetricsService($this->db))->summary(3)['kpis'];
        $pick = static fn (string $key) => ['now' => $k[$key]['value'] ?? null, 'prev' => $k[$key]['prev'] ?? null];
        return ['mrr' => $pick('mrr'), 'paying' => $pick('paying'), 'logo_churn' => $pick('logo_churn'), 'revenue_churn' => $pick('revenue_churn'),
            'trial_conversion' => $k['trial_conversion']['value'] ?? null, 'cash_30d' => $pick('cash_30d')];
    }

    private function signups(): array
    {
        $r = $this->db->query('SELECT SUM(u.created_at >= CURDATE()) AS today, SUM(u.created_at >= NOW() - INTERVAL 7 DAY) AS d7,
                SUM(u.created_at < NOW() - INTERVAL 7 DAY AND u.created_at >= NOW() - INTERVAL 14 DAY) AS prev7
            FROM users u WHERE ' . self::CUSTOMER . ' AND u.created_at >= NOW() - INTERVAL 14 DAY')[0] ?? [];
        return ['today' => (int) ($r['today'] ?? 0), 'last_7d' => (int) ($r['d7'] ?? 0), 'prev_7d' => (int) ($r['prev7'] ?? 0)];
    }

    private function trials(): array
    {
        $s = (new TrialFollowUpService($this->db))->report()['stats'] ?? [];
        return ['active' => (int) ($s['active'] ?? 0), 'ending_24h' => (int) ($s['ending_24h'] ?? 0), 'ended_unconverted' => (int) ($s['ended'] ?? 0), 'signed_up_no_trial' => (int) ($s['never'] ?? 0)];
    }

    private function customers(): array
    {
        $h = (new CustomerHealthService($this->db))->list('at_risk', '', 200);
        return ['healthy' => (int) ($h['counts']['healthy'] ?? 0), 'watch' => (int) ($h['counts']['watch'] ?? 0), 'at_risk' => (int) ($h['counts']['at_risk'] ?? 0),
            'paying_at_risk' => count($h['items'] ?? []), 'mrr_at_risk' => (float) ($h['mrr_at_risk'] ?? 0)];
    }

    private function platform(): array
    {
        $r = (new PlatformHealthService($this->db))->report();
        $bad = [];
        foreach ($r['sections'] ?? [] as $section => $s) {
            foreach ($s['checks'] ?? [] as $c) {
                if (in_array($c['status'] ?? '', ['fail', 'warn'], true)) {
                    $bad[] = ['section' => (string) $section, 'key' => (string) ($c['key'] ?? ''), 'status' => $c['status'], 'label' => (string) ($c['label'] ?? ''), 'message' => mb_substr((string) ($c['message'] ?? ''), 0, 220)];
                }
            }
        }
        usort($bad, static fn ($a, $b) => ($a['status'] === 'fail' ? 0 : 1) <=> ($b['status'] === 'fail' ? 0 : 1));
        return ['overall' => $r['overall'] ?? null, 'counts' => $r['counts'] ?? [], 'problems' => array_slice($bad, 0, 12)];
    }

    private function errors(): array
    {
        $r = (new ErrorLogService())->report(24, 'error');
        $top = array_map(static fn ($g) => ['message' => mb_substr((string) ($g['message'] ?? ''), 0, 160), 'count' => (int) ($g['count'] ?? 0), 'last_seen' => (string) ($g['last_seen'] ?? '')], array_slice($r['groups'] ?? [], 0, 5));
        return ['errors_24h' => (int) ($r['totals']['error'] ?? 0), 'errors_last_hour' => (int) ($r['totals']['last_hour_errors'] ?? 0), 'top' => $top];
    }

    private function aiCost(): array
    {
        $stats = new AIUsageStatsService();
        $today = $stats->summary(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
        $week = $stats->summary(date('Y-m-d 00:00:00', strtotime('-7 days')), date('Y-m-d 23:59:59', strtotime('-1 day')));
        $month = $stats->summary(date('Y-m-01 00:00:00'), date('Y-m-d 23:59:59'));
        $out = ['today_usd' => round((float) ($today['total_cost'] ?? 0), 2), 'avg_day_7d_usd' => round((float) ($week['total_cost'] ?? 0) / 7, 2),
            'month_usd' => round((float) ($month['total_cost'] ?? 0), 2), 'requests_today' => (int) ($today['total_requests'] ?? 0), 'failed_today' => (int) ($today['failed_requests'] ?? 0)];
        // المرحلة 2: التوقع، والميزانيات، والاستهلاك غير الطبيعي، ومراجعة توزيع النماذج
        $r = (new AiCostIntelligence($this->db))->report();
        return $out + ['projected_month_usd' => $r['totals']['projected_month'], 'platform_budget' => $r['platform_budget'], 'aiceo' => $r['aiceo'],
            'anomalies' => array_slice($r['anomalies'], 0, 5), 'routing_review' => array_slice($r['routing_review'], 0, 5)];
    }

    /** المرحلة 4: البوابة القانونية والمسار والـ CRM */
    private function sales(): array
    {
        $e = new SalesEngine($this->db);
        $s = $e->settings();
        $q = $e->approvalQueue(0);
        $p = $e->pipeline();
        return ['basis_confirmed' => array_keys(array_filter($s['basis'])), 'has_address' => $s['has_address'], 'queue_ok' => $q['counts']['ok'], 'queue_blocked' => $q['counts']['blocked'],
            'block_reasons' => $q['counts']['reasons'], 'active_noncompliant' => count($e->activeNonCompliant(500)), 'crm_pending' => $p['crm_pending'], 'stages' => $p['stages']];
    }

    private function outreach(): array
    {
        $s = (new PlatformOutreachService())->stats();
        $countries = [];
        foreach ($s['country_stats'] ?? [] as $c) {
            $countries[] = ['country' => (string) ($c['c'] ?? $c['country'] ?? '?'), 'total' => (int) ($c['total'] ?? 0), 'contacted' => (int) ($c['contacted'] ?? 0),
                'interested' => (int) ($c['interested'] ?? 0), 'signed_up' => (int) ($c['signed_up'] ?? 0), 'clicks' => (int) ($c['clicks'] ?? 0)];
        }
        return ['total' => (int) ($s['total'] ?? 0), 'awaiting_approval' => (int) ($s['awaiting_approval'] ?? 0), 'pending_sends' => (int) ($s['pending'] ?? 0),
            'sent' => (int) ($s['messages']['sent'] ?? 0), 'opened' => (int) ($s['messages']['opened'] ?? 0), 'clicked' => (int) ($s['messages']['clicked'] ?? 0),
            'paused_reason' => (string) ($s['paused_reason'] ?? ''), 'countries' => array_slice($countries, 0, 30)];
    }
}
