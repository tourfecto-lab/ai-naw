<?php

/**
 * Connects Facebook Pages (and their Instagram professional accounts) for Messenger / Instagram DMs through the separate
 * messaging-only Meta app (see MetaMessagingApp). Independent of the main Meta login: new URLs, own credentials, off
 * until META_MSG_APP_ID / META_MSG_APP_SECRET exist. Page tokens are stored encrypted as platform "messenger_page".
 */
class ChatMetaConnectController extends Controller
{
    /** GET /chat/connect/meta?website_id=N - sends the user to Facebook's consent dialog. */
    public function start(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/chat/settings'));
            exit;
        }
        $uid = (int) ($this->user['id'] ?? 0);
        $wid = (int) $this->get('website_id');
        if (!MetaMessagingApp::configured()) {
            header('Location: /chat/settings?meta_msg=notconfigured');
            exit;
        }
        if ($wid < 1 || empty($this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $uid]))) {
            return $this->error('Website not found', 404);
        }
        header('Location: ' . MetaMessagingApp::authUrl(MetaMessagingApp::makeState($uid, $wid), (bool) $this->get('rerequest')));
        exit;
    }

    /** GET /chat/connect/meta/callback - back from Facebook: keep the user's pages, return to the chat settings. */
    public function callback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $uid = (int) ($this->user['id'] ?? 0);
        $back = static function (string $result): void {
            header('Location: /chat/settings?meta_msg=' . ($result === 'server' || strncmp($result, 'server&', 7) === 0 ? $result : rawurlencode($result)));
            exit;
        };
        if ($this->get('error')) {
            $back('denied');
        }
        $state = MetaMessagingApp::readState((string) $this->get('state'));
        if ($state === null || $state[0] !== $uid) {
            $back('state');
        }
        $wid = $state[1];
        if (empty($this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $uid]))) {
            $back('website');
        }
        try {
            $code = (string) $this->get('code');
            $ex = $code === '' ? ['success' => false] : MetaMessagingApp::exchange($code);
            if (empty($ex['success'])) {
                Logger::warning('Meta messaging login failed', ['website_id' => $wid, 'error' => mb_substr((string) ($ex['error'] ?? ''), 0, 200)]);
                $back('exchange');
            }
            $pages = MetaMessagingApp::pages((string) $ex['access_token']);
            if (empty($pages['success'])) {
                Logger::warning('Meta messaging pages failed', ['website_id' => $wid, 'error' => mb_substr((string) ($pages['error'] ?? ''), 0, 200)]);
                $back('pages');
            }
            self::savePages($this->db, $uid, $wid, $pages['pages']);
        } catch (Throwable $e) {
            Logger::error('Meta messaging callback crashed', ['website_id' => $wid, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            // The connecting user is the site owner: a short class + message (no secrets) helps them report the cause.
            $back('server&detail=' . rawurlencode(mb_substr(get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), 0, 160)));
        }
        $back($pages['pages'] ? 'connected' : 'nopages');
    }

    /**
     * Replace this website's messaging-app pages with the fresh list (tokens encrypted).
     * Pages already answering (platform "messenger") keep their row; only the choice list is refreshed.
     * @param array<int,array{id:string,name:string,token:string,ig_id:string,ig_username:string}> $pages
     */
    public static function savePages(Database $db, int $uid, int $wid, array $pages): int
    {
        $enc = new Encryption();
        $db->query("DELETE FROM platform_connections WHERE website_id = ? AND user_id = ? AND platform = 'messenger_page'", [$wid, $uid]);
        $n = 0;
        foreach ($pages as $p) {
            $db->query(
                "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_account_id, external_location_id, external_location_name, status, meta_json, connected_at)
                 VALUES (?, ?, 'messenger_page', ?, ?, ?, ?, 'connected', ?, NOW())",
                [$wid, $uid, $enc->encrypt($p['token']), $p['ig_id'] !== '' ? $p['ig_id'] : null, $p['id'], $p['name'],
                    json_encode(['ig_id' => $p['ig_id'], 'ig_username' => $p['ig_username'], 'source' => 'messaging_app'], JSON_UNESCAPED_UNICODE)]
            );
            $n++;
        }
        return $n;
    }
}
