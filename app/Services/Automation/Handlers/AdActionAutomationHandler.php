<?php

/**
 * تنفيذ تعديل إعلاني متوافق عليه عن طريق نفس مسار الموافقة في محرك الإعلانات.
 * @version 1.0.0
 */
class AdActionAutomationHandler implements AutomationHandler
{
    public function execute(array $decision, string $finalText): array
    {
        $res = (new AdAutopilotEngine())->approvePendingAction((int) $decision['user_id'], (int) $decision['subject_id'], true);
        $status = $res['status'] ?? '';
        if ($status === 'not_found') {
            return ['success' => false, 'error' => 'الإجراء مش موجود أو اتعمل قبل كده'];
        }
        return ['success' => $status === 'executed', 'error' => $status === 'executed' ? null : ($res['error'] ?? $status)];
    }
}
