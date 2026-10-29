<?php

/**
 * When Facebook refuses a send because the page / Instagram token is no longer valid (the owner changed their password,
 * removed the app, the token expired), the connection is marked and the owner is told ONCE - instead of the inbox silently
 * "failing to deliver" forever. Reconnecting from the chat settings brings it back (the connection row is re-created).
 */
final class MetaChannelHealth
{
    /** Graph API codes that mean "this access token is no longer usable". */
    private const AUTH_CODES = [190, 102, 463, 467, 458, 459, 460, 492];

    public static function isAuthError(int $code): bool
    {
        return in_array($code, self::AUTH_CODES, true);
    }

    /** @return string[] platform_connections.platform values that carry a channel's token */
    private static function platforms(string $channel): array
    {
        return $channel === 'instagram' ? ['instagram_dm', 'instagram'] : ['messenger'];
    }

    /** @return bool true when the connection was just marked (the owner has been notified) */
    public static function note(int $websiteId, string $channel, int $code, string $message = '', ?Database $db = null): bool
    {
        if (!self::isAuthError($code) || !in_array($channel, ['messenger', 'instagram'], true)) {
            return false;
        }
        try {
            $db = $db ?: Database::getInstance();
            $in = implode(',', array_fill(0, count(self::platforms($channel)), '?'));
            $params = array_merge([$websiteId], self::platforms($channel));
            $rows = $db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform IN ($in) AND status = 'connected'", $params);
            if (!$rows) {
                return false; // already marked: the owner was told
            }
            $db->query("UPDATE platform_connections SET status = 'token_expired', last_error = ? WHERE website_id = ? AND platform IN ($in) AND status = 'connected'", array_merge([mb_substr($message !== '' ? $message : 'Facebook token no longer valid', 0, 200)], $params));
            $uid = (int) ($db->query('SELECT user_id FROM websites WHERE id = ? LIMIT 1', [$websiteId])[0]['user_id'] ?? 0);
            if ($uid > 0) {
                $name = $channel === 'instagram' ? 'Instagram' : 'Messenger';
                Notification::notify($uid, 'ai_chat_channel_error', $name . ' needs to be reconnected', 'Facebook no longer accepts the connection for ' . $name . ' messages, so replies cannot be delivered. Open the chat settings and connect again.', '/chat/settings');
            }
            return true;
        } catch (Throwable $e) {
            Logger::warning('Meta channel health note failed', ['error' => mb_substr($e->getMessage(), 0, 160)]);
            return false;
        }
    }
}
