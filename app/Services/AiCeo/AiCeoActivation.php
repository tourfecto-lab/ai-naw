<?php
require_once __DIR__ . '/AiCeoStore.php';
require_once __DIR__ . '/AiCostIntelligence.php';

/**
 * TOURFECTO AI CEO (المرحلة 10) — الدمج والتفعيل الآمن.
 *
 * قائمة تجهيز واحدة بتقول إيه اللي جاهز وإيه اللي ناقص من صاحب المنصة، ومرحلة التفعيل الحالية (تفعيل تدريجي):
 *   0. مراقبة: الكرون شغال، وضع الموافقة، ومفيش أي إجراء تلقائي.
 *   1. البيانات: المدخلات الأساسية (المصاريف، العنوان، الأساس القانوني، الميزانيات، النسخ الاحتياطي...) كاملة.
 *   2. تلقائي محدود: AUTO MODE للقرارات منخفضة الخطر بس (الحالة "موافق عليه"، مفيش إرسال ولا صرف).
 *   3. نمو: أول رسائل التسويق للشركات المسموح بيها قانونيًا (بموافقتك على كل دفعة) وخطة المحتوى الشهرية.
 * ومعاها حالة التكاملات (من سجل التكاملات الموجود) واللي محتاج مفاتيح أو موافقات. مفيش أي قيمة سرية بتطلع، بس "موجود/مش موجود".
 */
final class AiCeoActivation
{
    public const STAGES = [0 => 'Observe (approval mode, nothing automatic)', 1 => 'Data complete', 2 => 'Limited auto mode (low-risk decisions only)', 3 => 'Growth (compliant outreach and monthly content plan, with your approval)'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    private function q(string $sql, array $p = []): array
    {
        try { return $this->db->query($sql, $p) ?: []; } catch (Throwable $e) { return []; }
    }

    private function setting(string $k): ?string
    {
        $v = $this->q('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$k])[0]['setting_value'] ?? null;
        return $v === null || trim((string) $v) === '' ? null : (string) $v;
    }

    private static function env(string $k): bool
    {
        $v = getenv($k);
        if ($v === false || $v === '') $v = $_ENV[$k] ?? '';
        if ($v === '' && defined($k)) $v = (string) constant($k);
        return trim((string) $v) !== '';
    }

    /** بنود التجهيز: [key, stage, label, ok, how, tab] */
    public function checklist(): array
    {
        $store = new AiCeoStore($this->db);
        $set = $store->settings();
        $lastCycle = $this->q("SELECT MAX(created_at) AS t FROM aiceo_activity WHERE action = 'cycle'")[0]['t'] ?? null;
        $cronOk = $lastCycle && strtotime($lastCycle . ' UTC') > time() - 3 * 3600;
        $budgets = (new AiCostIntelligence($this->db))->budgets();
        $address = $this->setting('outreach_company_address');
        $basis = array_filter(['us', 'eu', 'gcc', 'other'], fn ($r) => $this->setting('sales_basis_' . $r) === '1');
        $fixed = (int) ($this->q('SELECT COUNT(*) AS n FROM aiceo_cost_items WHERE active = 1')[0]['n'] ?? 0);
        $goals = (int) ($this->q("SELECT COUNT(*) AS n FROM aiceo_goals WHERE status = 'active'")[0]['n'] ?? 0);
        $audit = (int) ($this->q('SELECT COUNT(*) AS n FROM aiceo_brand_audits')[0]['n'] ?? 0);
        $checks = (int) ($this->q('SELECT COUNT(*) AS n FROM aiceo_ops_checks')[0]['n'] ?? 0);
        $competitors = (int) ($this->q('SELECT COUNT(*) AS n FROM aiceo_competitors')[0]['n'] ?? 0);
        $brandSite = false;
        try {
            require_once __DIR__ . '/BrandGrowth.php';
            require_once __DIR__ . '/BrandSiteAudit.php';
            $brandSite = (new BrandGrowth($this->db))->presence((new BrandSiteAudit($this->db))->siteUrl())['website'] !== null;
        } catch (Throwable $e) {}
        $c = static fn ($key, $stage, $label, $ok, $how, $tab) => ['key' => $key, 'stage' => $stage, 'label' => $label, 'ok' => (bool) $ok, 'how' => $how, 'tab' => $tab];
        return [
            $c('cron', 0, 'AI CEO cron runs hourly', $cronOk, $lastCycle ? 'Last cycle ' . $lastCycle . ' UTC. Expected within 3 hours.' : 'Add cron/ai_ceo_cycle.php hourly in aaPanel.', 'overview'),
            $c('not_paused', 0, 'Emergency stop is off', !$set['paused'], 'Resume from the top of Mission Control when ready.', 'overview'),
            $c('page_checks', 0, 'Platform page checks have run', $checks > 0, 'Platform & Dev Advisor → Run checks now (or wait for the cron).', 'ops'),
            $c('fixed_costs', 1, 'Fixed monthly costs entered', $fixed > 0, 'Finance & Strategy → Fixed monthly costs (server, tools, people, marketing).', 'finance'),
            $c('ai_platform_budget', 1, 'Platform AI budget set', $budgets['ai_platform_budget_usd_month'] !== null, 'AI Costs → monthly platform AI budget.', 'costs'),
            $c('backups', 1, 'Backup folder set and fresh', $this->setting('ops_backup_dir') !== null, 'Run tools/backup.php daily to a folder outside the site, then set the folder in Platform & Dev Advisor.', 'ops'),
            $c('goals', 1, 'Quarter goals set', $goals > 0, 'Finance & Strategy → Quarterly goals.', 'finance'),
            $c('brand_site', 1, 'tourfecto.pro added as a website in the owner account', $brandSite, 'Websites → Add website (so SEO, reviews and social work for Tourfecto).', 'brand'),
            $c('brand_audit', 1, 'Tourfecto site audited', $audit > 0, 'Brand Growth → Run audit now.', 'brand'),
            $c('competitors', 1, 'Competitors added', $competitors > 0, 'Business Health & Reports → Add a competitor.', 'executive'),
            $c('postal_address', 3, 'Company postal address in outreach emails', $address !== null && stripos($address, 'Tourfecto · Cairo, Egypt') === false, 'Platform outreach settings (/admin/growth).', 'sales'),
            $c('lawful_basis', 3, 'Lawful basis confirmed for at least one region', (bool) $basis, 'Leads & Sales → Lawful basis & targeting (ask your lawyer when in doubt).', 'sales'),
        ];
    }

    /** المرحلة الحالية (منطق نقي): أعلى مرحلة كل بنودها ومراحلها اللي قبلها جاهزة */
    public static function stage(array $checklist, string $mode): array
    {
        $ready = static fn (int $s) => !array_filter($checklist, static fn ($c) => $c['stage'] <= $s && !$c['ok']);
        $stage = 0;
        if ($ready(0) && $ready(1)) $stage = 1;
        if ($stage === 1 && $mode === 'auto') $stage = 2;
        if ($stage === 2 && $ready(3)) $stage = 3;
        $next = array_values(array_filter($checklist, static fn ($c) => !$c['ok']));
        usort($next, static fn ($a, $b) => $a['stage'] <=> $b['stage']);
        $advice = match (true) {
            !$ready(0) => 'Get the basics running: the cron and the page checks. Stay in approval mode.',
            $stage === 0 || ($stage === 1 && !$ready(1)) => 'Complete the data inputs below. Stay in approval mode for at least one week and review every decision.',
            $stage === 1 => 'Data is complete. After a week of reviewing decisions in approval mode, you can switch to AUTO MODE: only low-risk decisions are marked approved; nothing is sent or spent.',
            $stage === 2 => 'Auto mode is on for low-risk decisions. To start outreach, set the postal address and confirm a lawful basis, then approve each batch in Leads & Sales.',
            default => 'All stages are active. Keep reviewing the daily report and the approvals.',
        };
        return ['stage' => $stage, 'label' => self::STAGES[$stage], 'advice' => $advice, 'next' => array_slice($next, 0, 5)];
    }

    /** التكاملات والمفاتيح (موجود/مش موجود بس، من غير أي قيمة) */
    public function integrations(): array
    {
        $items = [];
        try {
            foreach ((new PlatformHealthService($this->db))->integrations()['items'] ?? [] as $i) {
                $items[] = ['key' => $i['key'], 'label' => $i['label'], 'importance' => $i['importance'], 'configured' => (bool) $i['configured'], 'ok' => $i['last_check_ok'], 'message' => $i['last_check_message'], 'checked_at' => $i['last_checked_at']];
            }
        } catch (Throwable $e) {}
        $extra = [
            ['key' => 'github_issues', 'label' => 'GitHub issues from the development backlog', 'configured' => self::env('GITHUB_ISSUES_TOKEN') && self::env('GITHUB_ISSUES_REPO'), 'needs' => 'GITHUB_ISSUES_TOKEN (fine-grained, issues:write on one repo) and GITHUB_ISSUES_REPO in .env, then the switch in Platform & Dev Advisor.'],
            ['key' => 'backup_key', 'label' => 'Encrypted backups', 'configured' => self::env('BACKUP_ENCRYPTION_KEY'), 'needs' => 'BACKUP_ENCRYPTION_KEY (base64, 32 bytes) for tools/backup.php, kept outside the site.'],
            ['key' => 'dataforseo', 'label' => 'DataForSEO (SEO data)', 'configured' => self::env('DATAFORSEO_LOGIN') || self::env('DATAFORSEO_API_LOGIN'), 'needs' => 'Raise the monthly cap in the DataForSEO dashboard if SEO data stops.'],
            ['key' => 'apify', 'label' => 'Apify (tourism companies import, licensed actors only)', 'configured' => self::env('APIFY_TOKEN') || self::env('APIFY_API_TOKEN'), 'needs' => 'APIFY_TOKEN; use only actors whose terms allow your use.'],
        ];
        return ['registry' => $items, 'ai_ceo' => $extra,
            'pending_reviews' => ['Meta app review (Facebook/Instagram/WhatsApp publishing and replies) — do not change scopes, redirect URIs or webhooks until approved.', 'Google OAuth verification (Business Profile, Search Console) — same rule.']];
    }

    /** كل حاجة لتبويب Setup */
    public function report(): array
    {
        $store = new AiCeoStore($this->db);
        $set = $store->settings();
        $list = $this->checklist();
        $costs = null;
        try { $r = (new AiCostIntelligence($this->db))->report(); $costs = ['aiceo' => $r['aiceo'] ?? null, 'platform_month' => $r['totals']['month'] ?? null, 'projected_month' => $r['totals']['projected_month'] ?? null]; } catch (Throwable $e) {}
        return ['settings' => $set, 'checklist' => $list, 'stage' => self::stage($list, (string) $set['mode']), 'integrations' => $this->integrations(), 'costs' => $costs,
            'automation' => [
                'Hourly cron (no AI): snapshot, decisions, auto-approval of low-risk decisions in AUTO MODE, page checks and incidents, sales gate re-check, daily/weekly sales and executive reports, lessons.',
                'Weekly (cron): Tourfecto site audit. Daily at 06 UTC: competitor page checks.',
                'Never automatic: sending emails or WhatsApp, approving outreach, paid ads, publishing, price or plan changes, blocking users, deploying code, deleting data.',
                'AI calls happen only when you press a button (chat, monthly content plan, boardroom) and stop at the AI CEO budget.',
            ]];
    }
}
