<?php

/**
 * A reply sent to a Messenger / Instagram customer from OUTSIDE this platform (the Page inbox, Business Suite, the Instagram
 * app): Meta posts a copy of it (an "echo"). It is added to the conversation so the thread is complete, and the AI stops
 * answering that conversation until the owner hands it back - the same rule as a WhatsApp reply from the phone.
 * Echoes of messages WE sent (the bot, the inbox) are recognised and ignored.
 */
final class MetaDmEcho
{
    /** App ids that mean "this platform sent it": the messaging app and the main Meta app. */
    private static function ourAppIds(): array
    {
        $ids = [];
        if (class_exists('MetaMessagingApp')) {
            $ids[] = MetaMessagingApp::appId();
        }
        $ids[] = preg_replace('/\D/', '', MetaOAuthClient::setting('meta_app_id', 'META_APP_ID'));
        return array_values(array_filter($ids));
    }

    /**
     * @param array $message the webhook "message" object of the echo (text / attachments / mid / app_id)
     * @return string stored | own | duplicate | empty
     */
    public static function store(int $websiteId, int $userId, string $platform, string $customerId, array $message, ?Database $db = null, ?UnifiedInboxService $inbox = null): string
    {
        $mid = (string) ($message['mid'] ?? '');
        [$text, $media] = MetaWebhookController::describeAttachment($message);
        $text = trim((string) (($message['text'] ?? '') !== '' ? $message['text'] : $text));
        if ($customerId === '' || $text === '') {
            return 'empty';
        }
        $appId = preg_replace('/\D/', '', (string) ($message['app_id'] ?? ''));
        if ($appId !== '' && in_array($appId, self::ourAppIds(), true)) {
            return 'own';
        }
        $db = $db ?: Database::getInstance();
        $stored = ChatManager::storedPlatform($platform);
        // Meta re-delivers; and an echo without an app id may be our own reply (same text just saved, as a reply or as the AI's answer).
        if ($mid !== '' && $db->query("SELECT id FROM chat_messages WHERE website_id = ? AND platform_message_id = ? LIMIT 1", [$websiteId, $mid])) {
            return 'duplicate';
        }
        if ($appId === '' && $db->query(
            "SELECT id FROM chat_messages WHERE website_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
               AND ((message_direction = 'outgoing' AND message_text = ?) OR ai_reply_generated = ?) LIMIT 1",
            [$websiteId, $text, $text]
        )) {
            return 'own';
        }
        $inbox = $inbox ?: new UnifiedInboxService();
        $conv = $inbox->findOrCreateConversation($websiteId, $userId, $platform, $customerId, []);
        $convId = (int) $conv->getAttribute('id');
        $raw = $media !== null ? json_encode(['media_ext' => $media], JSON_UNESCAPED_UNICODE) : null;
        $db->query(
            "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform, platform_message_id,
                customer_name, customer_phone, message_direction, message_text, webhook_raw_data, bot_status, is_auto_pilot, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'outgoing', ?, ?, 'sent', 0, NOW(), NOW())",
            [$websiteId, $convId, $userId, 'app_' . $convId, $stored, $mid !== '' ? $mid : null, $conv->getAttribute('customer_name'), $customerId, mb_substr($text, 0, 4000), $raw]
        );
        $fields = ['last_message_at' => gmdate('Y-m-d H:i:s')];
        if ((string) $conv->getAttribute('ai_status') === 'ai') {
            $fields += ['ai_status' => 'human', 'handoff_reason' => 'replied_from_app', 'handoff_at' => gmdate('Y-m-d H:i:s')];
        }
        $inbox->updateConversation($convId, $fields);
        return 'stored';
    }
}
