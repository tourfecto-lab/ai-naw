<?php

/**
 * إرسال طلب تقييم متوافق عليه فورًا.
 * @version 1.0.0
 */
class ReviewRequestAutomationHandler implements AutomationHandler
{
    public function execute(array $decision, string $finalText): array
    {
        $requestId = (int) $decision['subject_id'];
        $rows = Database::getInstance()->query('SELECT * FROM review_requests WHERE id = ? LIMIT 1', [$requestId]);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'الطلب مش موجود'];
        }
        if ($rows[0]['status'] !== 'scheduled') {
            return ['success' => true, 'note' => 'already_handled'];
        }
        $ok = (new ReviewRequestService())->sendScheduledRequest($rows[0], $finalText !== '' ? $finalText : null);
        return $ok ? ['success' => true] : ['success' => false, 'error' => 'تعذر الإرسال - راجع إعدادات القناة'];
    }
}
