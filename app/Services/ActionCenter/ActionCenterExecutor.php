<?php

/**
 * Tourfecto - Action Center Executor (منفّذ مركز الإجراءات الموحّد) v1.0.0
 *
 * بيشتق من الفئة الموحّدة ActionExecutor وينفّذ توصيات كل وحدات التحليل
 * المجمّعة في Action Center (Competitor Intelligence + CEO Advisor +
 * Marketing Assistant) كإجراءات فعلية: مهام CRM + إشعارات للأولوية العالية،
 * مع تسجيل التدقيق في جدول action_executions لمنع التكرار.
 *
 * @version 1.0.0
 * @date 2026-08-20
 */

require_once __DIR__ . '/../Execution/ActionExecutor.php';

class ActionCenterExecutor extends ActionExecutor
{
    protected function table(): string
    {
        return 'action_executions';
    }

    protected function notificationType(): string
    {
        return 'action_center';
    }

    protected function notificationUrl(): string
    {
        return '/dashboard';
    }

    protected function taskPrefix(): string
    {
        return 'إجراء: ';
    }

    protected function buildTaskBody(array $action): string
    {
        $parts = [];
        if (!empty($action['recommended_action'])) {
            $parts[] = (string) $action['recommended_action'];
        }
        if (!empty($action['reason'])) {
            $parts[] = (string) $action['reason'];
        }
        return implode("\n", $parts);
    }

    /** وسم رؤية المنافسين الجديدة بحالة actioned (ما يشتغلش لو الجدول مش موجود). */
    protected function afterExecution(int $userId, array $intent): void
    {
        if (($intent['source_type'] ?? '') === 'competitor') {
            $id = (int) $intent['affected_area_id'];
            if ($id > 0) {
                $this->db->exec(
                    "UPDATE ci_insights SET status = 'actioned'
                      WHERE id = ? AND user_id = ? AND status = 'new'",
                    [$id, $userId]
                );
            }
        }
    }
}
