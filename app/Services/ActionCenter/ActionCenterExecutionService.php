<?php

/**
 * Tourfecto - Action Center Execution Service v1.0.0
 *
 * بيحوّل عناصر Action Center الموحّدة (من ActionCenterService::getActionItems)
 * إلى "إجراءات" جاهزة لطبقة التنفيذ (ActionCenterExecutor). بيفلتر المصادر
 * اللي ليها تدفّق تنفيذ خاص بيها بالفعل (Website Optimizer / Outreach /
 * المهام اليدوية) وبيخلّي التحليل الخام اللي مستني تنفيذ حقيقي:
 *   - competitor   : رؤى/تهديدات/فرص/توصيات Competitor Intelligence
 *   - ceo_advisor  : تنبيهات المخاطر + فرص النمو (CEO Advisor)
 *   - marketing    : نواتج Marketing Assistant
 *
 * @version 1.0.0
 * @date 2026-08-20
 */
class ActionCenterExecutionService
{
    /** المصادر اللي بنحوّلها لإجراءات (الباقي ليه تدفّق تنفيذ خاص). */
    private const EXECUTABLE_SOURCES = ['competitor', 'ceo_advisor', 'marketing'];

    /** @var ActionCenterService|null */
    private $actionCenterService;

    public function __construct(?ActionCenterService $actionCenterService = null)
    {
        $this->actionCenterService = $actionCenterService
            ?? (class_exists('ActionCenterService') ? new ActionCenterService() : null);
    }

    /**
     * توصيات جاهزة لطبقة التنفيذ من عناصر Action Center.
     *
     * @param Database $db
     * @param int $userId
     * @param int|null $websiteId
     * @param int $limit
     * @return array قائمة إجراءات (source_type/source_category/affected_area/...)
     */
    public function getNextBestActions(Database $db, int $userId, ?int $websiteId = null, int $limit = 20): array
    {
        if ($this->actionCenterService === null) {
            return [];
        }
        $items = $this->actionCenterService->getActionItems($db, $userId, $websiteId);

        $actions = [];
        foreach ($items as $item) {
            $source = (string) ($item['source'] ?? '');
            if (!in_array($source, self::EXECUTABLE_SOURCES, true)) {
                continue;
            }
            $actions[] = $this->mapItemToAction($item);
            if (count($actions) >= $limit) {
                break;
            }
        }
        return $actions;
    }

    /**
     * عنصر Action Center → إجراء لطبقة التنفيذ.
     */
    private function mapItemToAction(array $item): array
    {
        $priority = (string) ($item['priority'] ?? 'medium');
        $severity = in_array($priority, ['critical', 'high'], true) ? 'high'
            : ($priority === 'low' ? 'low' : 'medium');

        $createdAt = (string) ($item['created_at'] ?? '');
        $period = $createdAt !== '' ? substr($createdAt, 0, 10) : null;

        return [
            'action' => (string) ($item['title'] ?? 'إجراء متابعة'),
            'source_type' => (string) ($item['source'] ?? 'action_center'),
            'source_category' => (string) ($item['category'] ?? $item['action_type'] ?? 'general'),
            'affected_area' => (string) ($item['source'] ?? 'x') . ':' . (int) $item['id'],
            'affected_area_id' => isset($item['id']) ? (int) $item['id'] : null,
            'severity' => $severity,
            'confidence' => 'high',
            'reason' => (string) ($item['description'] ?? ''),
            'recommended_action' => $item['action_hint'] ?? null,
            'period' => $period,
        ];
    }
}
