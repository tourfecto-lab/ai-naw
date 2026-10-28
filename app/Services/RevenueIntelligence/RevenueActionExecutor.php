<?php

/**
 * Tourfecto - Revenue Action Executor (طبقة تنفيذ الإيرادات) v1.1.0
 *
 * بتشتق من الفئة الموحّدة ActionExecutor، وبتنفّذ توصيات الإيرادات
 * (المخاطر/الفرص/الشذوذ) كإجراءات فعلية:
 *   - إنشاء مهمة CRM بتاريخ استحقاق وأولوية حسب الخطورة.
 *   - إشعار داخلي للأعمال عالية الخطورة (severity = high).
 *   - تسجيل في `revai_action_executions` لمنع التكرار + وسم الرؤية
 *     المصدر في `revai_insights` بحالة actioned.
 *
 * ملاحظة: بيشتغل مع أي منفّذ خارجي عن طريق حقن دوال (taskCreator/notifier)
 * عشان يكون قابلًا للاختبار offline - في الإنتاج بيتأسسوا تلقائيًا على
 * CrmTaskService + Notification::notify.
 *
 * @version 1.1.0
 * @date 2026-08-20
 */

require_once __DIR__ . '/../Execution/ActionExecutor.php';

class RevenueActionExecutor extends ActionExecutor
{
    protected function table(): string
    {
        return 'revai_action_executions';
    }

    protected function notificationType(): string
    {
        return 'revenue_action';
    }

    protected function notificationUrl(): string
    {
        return '/revenue/intelligence';
    }

    protected function taskPrefix(): string
    {
        return 'إجراء إيرادات: ';
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

    /** وسم الرؤية المصدر (risk/opportunity/anomaly) بحالة actioned. */
    protected function afterExecution(int $userId, array $intent): void
    {
        $this->db->exec(
            "UPDATE revai_insights SET status = 'actioned'
              WHERE user_id = ? AND type = ? AND category = ? AND status = 'active'",
            [$userId, $intent['source_type'], $intent['source_category']]
        );
    }
}
