<?php
/**
 * الشركة ردت على عميل من تطبيق واتساب بزنس على موبايلها (رقم Coexistence):
 * Meta بتبعت نسخة من الرسالة (webhook field: smb_message_echoes). بنحطها في الوارد كرسالة طالعة
 * عشان المحادثة تبان كاملة، وبنوقف الرد الآلي في المحادثة دي عشان العميل مايوصلوش ردين.
 */
class WhatsAppPhoneEcho
{
    /** نص الرسالة زي ما بيتعرض في الوارد */
    public static function text(array $m): string
    {
        $type = (string) ($m['type'] ?? 'text');
        return trim(match ($type) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'image' => '[Image] ' . (string) ($m['image']['caption'] ?? ''),
            'video' => '[Video] ' . (string) ($m['video']['caption'] ?? ''),
            'audio' => '[Voice message]',
            'document' => '[Document] ' . (string) ($m['document']['filename'] ?? ''),
            'location' => '[Location]',
            'sticker' => '[Sticker]',
            'template' => '[Template] ' . (string) ($m['template']['name'] ?? ''),
            'reaction', 'revoke', 'edit' => '',
            default => '[' . $type . ']',
        });
    }

    /** @return bool اتخزنت (false = مكررة أو فاضية) */
    public static function store(int $websiteId, int $userId, array $m, ?Database $db = null, ?UnifiedInboxService $inbox = null): bool
    {
        $id = (string) ($m['id'] ?? '');
        $to = preg_replace('/\D/', '', (string) ($m['to'] ?? ''));
        $text = self::text($m);
        if ($id === '' || $to === '' || $text === '') {
            return false;
        }
        $db = $db ?: Database::getInstance();
        if ($db->query("SELECT id FROM chat_messages WHERE website_id = ? AND platform = 'whatsapp' AND message_direction = 'outgoing' AND platform_message_id = ? LIMIT 1", [$websiteId, $id])) {
            return false; // Meta بتعيد الإرسال
        }
        $inbox = $inbox ?: new UnifiedInboxService();
        $conv = $inbox->findOrCreateConversation($websiteId, $userId, 'whatsapp', $to, ['phone' => $to]);
        $convId = (int) $conv->getAttribute('id');
        $at = isset($m['timestamp']) && ctype_digit((string) $m['timestamp']) ? date('Y-m-d H:i:s', (int) $m['timestamp']) : date('Y-m-d H:i:s');
        $db->query(
            "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform, platform_message_id,
                customer_name, customer_phone, message_direction, message_text, bot_status, is_auto_pilot, sent_at, created_at)
             VALUES (?, ?, ?, ?, 'whatsapp', ?, ?, ?, 'outgoing', ?, 'sent', 0, ?, ?)",
            [$websiteId, $convId, $userId, 'phone_' . $convId, $id, $conv->getAttribute('customer_name'), $to, $text, $at, $at]
        );
        $fields = ['last_message_at' => $at];
        // اللي بيرد من الموبايل إنسان: الذكاء الاصطناعي يسكت في المحادثة دي لحد ما الشركة ترجّعه من الوارد
        if ((string) $conv->getAttribute('ai_status') === 'ai') {
            $fields += ['ai_status' => 'human', 'handoff_reason' => 'replied_from_phone', 'handoff_at' => $at];
        }
        $inbox->updateConversation($convId, $fields);
        return true;
    }
}
