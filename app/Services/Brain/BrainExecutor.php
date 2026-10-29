<?php
require_once __DIR__ . '/../Execution/ActionExecutor.php';

/**
 * Tourfecto - منفّذ العقل الاستراتيجي
 *
 * بيستخدم طبقة التنفيذ الموحّدة (ActionExecutor): مهمة CRM بتاريخ استحقاق حسب الأثر، وإشعار داخلي للقرارات
 * عالية الأثر، وتسجيل في action_executions يمنع تكرار نفس القرار خلال 30 يوم.
 * وكمان بيكتب الفرص والمخاطر في جداول مستشار المدير التنفيذي اللي اللوحة التنفيذية بتقراها (كانت فاضية دايمًا).
 *
 * مابينفّذش أي حاجة فيها صرف فلوس أو تعديل برّة المنصة: القرارات دي بتروح للخدمة نفسها بموافقة صاحب الشركة.
 */
final class BrainExecutor extends ActionExecutor
{
    public const WINDOW_DAYS = 30;
    private const RISK_KEYS = '/^(travel_advice_|demand_down_|economy_|reply_negative_reviews|fix_site_health|price_above_)/';

    protected function table(): string { return 'action_executions'; }
    protected function notificationType(): string { return 'brain'; }
    protected function notificationUrl(): string { return '/brain'; }
    protected function taskPrefix(): string { return ''; }

    protected function buildTaskBody(array $action): string
    {
        return trim((string) ($action['reason'] ?? '') . "\n" . (string) ($action['recommended_action'] ?? ''));
    }

    /** قرار العقل → إجراء بالشكل اللي ActionExecutor فاهمه (نقي) */
    public static function actionFor(array $decision, int $websiteId, string $lang = 'ar'): array
    {
        $l = static fn ($o) => is_array($o) ? (string) ($o[$lang] ?? $o['ar'] ?? '') : (string) $o;
        $evidence = array_map($l, $decision['evidence'] ?? []);
        $impact = (int) ($decision['impact'] ?? 1);
        return [
            'action' => $l($decision['title'] ?? ''),
            'reason' => $l($decision['why'] ?? '') . ($evidence ? "\n• " . implode("\n• ", $evidence) : ''),
            'recommended_action' => ($lang === 'en' ? 'Open: ' : 'افتح: ') . (string) ($decision['action_url'] ?? '/brain'),
            'source_type' => 'brain',
            'source_category' => (string) ($decision['area'] ?? 'general'),
            'affected_area' => 'website:' . $websiteId,
            'dedup_key' => (string) ($decision['key'] ?? ''),
            'severity' => $impact >= 3 ? 'high' : ($impact === 2 ? 'medium' : 'low'),
            'confidence' => 'high',
        ];
    }

    /** المخاطر للوحة التنفيذية، والباقي فرص */
    public static function isRisk(array $decision): bool
    {
        return (bool) preg_match(self::RISK_KEYS, (string) ($decision['key'] ?? ''));
    }

    /** بينفّذ قرار واحد: مهمة + إشعار (لو أثره عالي) + فرصة/مخاطرة في اللوحة التنفيذية. بيرجع ملخص التنفيذ */
    public function executeDecision(int $userId, int $websiteId, array $decision, string $lang = 'ar'): array
    {
        if (!empty($decision['requires_spend'])) return ['executed' => 0, 'skipped' => 1, 'reason' => 'requires_spend'];
        $action = self::actionFor($decision, $websiteId, $lang);
        $summary = $this->executeActions($userId, [$action], ['window_days' => self::WINDOW_DAYS]);
        if (($summary['executed'] ?? 0) > 0) $this->mirror($userId, $decision, $action);
        return $summary;
    }

    /** نفس القرار في لوحة "فرص نمو ومخاطر" ومهام المدير التنفيذي (من غير تكرار نفس العنوان المفتوح) */
    private function mirror(int $userId, array $decision, array $action): void
    {
        try {
            $title = mb_substr($action['action'], 0, 250);
            if (self::isRisk($decision)) {
                if (!$this->db->query('SELECT id FROM ceo_risk_alerts WHERE user_id = ? AND title = ? AND is_resolved = 0 LIMIT 1', [$userId, $title]))
                    $this->db->query('INSERT INTO ceo_risk_alerts (user_id, title, description, severity, source_module, is_resolved, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())',
                        [$userId, $title, $action['reason'], $action['severity'], 'brain']);
            } elseif (!$this->db->query("SELECT id FROM ceo_growth_opportunities WHERE user_id = ? AND title = ? AND status NOT IN ('done','dismissed') LIMIT 1", [$userId, $title])) {
                $this->db->query("INSERT INTO ceo_growth_opportunities (user_id, title, description, estimated_impact, status, created_at) VALUES (?, ?, ?, ?, 'new', NOW())",
                    [$userId, $title, $action['reason'], $action['severity']]);
            }
            if (!$this->db->query("SELECT id FROM cc_ai_tasks WHERE user_id = ? AND title = ? AND status = 'open' LIMIT 1", [$userId, $title]))
                $this->db->query("INSERT INTO cc_ai_tasks (user_id, title, status, created_at) VALUES (?, ?, 'open', NOW())", [$userId, $title]);
        } catch (Throwable $e) {
            // اللوحة التنفيذية إضافة؛ المهمة الأساسية اتعملت خلاص
            if (class_exists('Logger')) Logger::warning('Brain mirror to executive tables failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
