<?php

/**
 * تنفيذ رد على تقييم Google بعد موافقة العميل (أو أوتوماتيك).
 * @version 1.0.0
 */
class ReviewReplyAutomationHandler implements AutomationHandler
{
    public function execute(array $decision, string $finalText): array
    {
        $reviewId = (int) $decision['subject_id'];
        $text = trim($finalText);
        if ($text === '') {
            return ['success' => false, 'error' => 'الرد فاضي'];
        }
        return ReviewAutoReplyRunner::sendReply($reviewId, (int) $decision['user_id'], $text, (int) ($decision['decided_by'] ?? 0) ?: null);
    }
}
