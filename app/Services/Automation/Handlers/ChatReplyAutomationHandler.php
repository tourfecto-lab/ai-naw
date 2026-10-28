<?php

/**
 * تنفيذ رد شات متوافق عليه: بنحدّث نص الرد بالنسخة اللي العميل عدّلها
 * وبعدين نستخدم نفس مسار الموافقة القديم (ApprovalSystem) اللي بيبعت
 * على القناة الصح لكل موقع.
 * @version 1.0.0
 */
class ChatReplyAutomationHandler implements AutomationHandler
{
    public function execute(array $decision, string $finalText): array
    {
        $messageId = (int) $decision['subject_id'];
        $userId = (int) $decision['user_id'];
        $db = Database::getInstance();

        $rows = $db->query('SELECT id, user_id, bot_status FROM chat_messages WHERE id = ? LIMIT 1', [$messageId]);
        if (empty($rows) || (int) $rows[0]['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'الرسالة مش موجودة'];
        }
        if ($rows[0]['bot_status'] !== 'pending_approval') {
            return ['success' => true, 'note' => 'already_handled'];
        }
        if (trim($finalText) !== '') {
            $db->exec('UPDATE chat_messages SET ai_reply_generated = ? WHERE id = ?', [trim($finalText), $messageId]);
        }
        $result = (new ApprovalSystem())->approve($messageId, $userId);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => $result['error'] ?? 'تعذر الإرسال'];
        }
        if (empty($result['sent'])) {
            return ['success' => false, 'error' => $result['message'] ?? 'تعذر الإرسال - تأكد إن القناة لسه متصلة'];
        }
        return ['success' => true];
    }
}
