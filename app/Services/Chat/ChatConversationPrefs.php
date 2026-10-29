<?php

/**
 * Per-agent inbox preferences (pin, archive, mark unread), stored in chat_conversation_prefs.
 * The table comes from an optional hand-applied migration: while it is missing every method degrades to "not ready"
 * and the inbox keeps these choices in the browser instead.
 */
class ChatConversationPrefs
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = !empty(Database::getInstance()->query("SHOW TABLES LIKE 'chat_conversation_prefs'"));
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Forget the cached table check (tests create the table after the first call). */
    public static function reset(): void
    {
        self::$ready = null;
    }

    /**
     * Preferences of one agent for the conversations of one website.
     * @return array<int,array{pinned:bool,archived:bool,unread:bool}> keyed by conversation id
     */
    public static function forUser(int $userId, int $websiteId): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        $rows = Database::getInstance()->query(
            'SELECT p.conversation_id, p.pinned, p.archived, p.marked_unread
               FROM chat_conversation_prefs p JOIN ai_conversations c ON c.id = p.conversation_id
              WHERE p.user_id = ? AND c.website_id = ? AND (p.pinned = 1 OR p.archived = 1 OR p.marked_unread = 1)',
            [$userId, $websiteId]
        ) ?: [];
        foreach ($rows as $r) {
            $out[(int) $r['conversation_id']] = ['pinned' => (bool) $r['pinned'], 'archived' => (bool) $r['archived'], 'unread' => (bool) $r['marked_unread']];
        }
        return $out;
    }

    /**
     * Change some flags (only the keys that are present). Returns the stored state, or null when the table is missing.
     * @param array{pinned?:bool,archived?:bool,unread?:bool} $flags
     * @return array{pinned:bool,archived:bool,unread:bool}|null
     */
    public static function set(int $userId, int $conversationId, array $flags): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $db = Database::getInstance();
        $cur = $db->query('SELECT pinned, archived, marked_unread FROM chat_conversation_prefs WHERE user_id = ? AND conversation_id = ?', [$userId, $conversationId])[0]
            ?? ['pinned' => 0, 'archived' => 0, 'marked_unread' => 0];
        $pinned = array_key_exists('pinned', $flags) ? (int) (bool) $flags['pinned'] : (int) $cur['pinned'];
        $archived = array_key_exists('archived', $flags) ? (int) (bool) $flags['archived'] : (int) $cur['archived'];
        $unread = array_key_exists('unread', $flags) ? (int) (bool) $flags['unread'] : (int) $cur['marked_unread'];
        if (!$pinned && !$archived && !$unread) {
            $db->query('DELETE FROM chat_conversation_prefs WHERE user_id = ? AND conversation_id = ?', [$userId, $conversationId]);
        } else {
            $db->query(
                'INSERT INTO chat_conversation_prefs (user_id, conversation_id, pinned, archived, marked_unread) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE pinned = VALUES(pinned), archived = VALUES(archived), marked_unread = VALUES(marked_unread)',
                [$userId, $conversationId, $pinned, $archived, $unread]
            );
        }
        return ['pinned' => (bool) $pinned, 'archived' => (bool) $archived, 'unread' => (bool) $unread];
    }
}
