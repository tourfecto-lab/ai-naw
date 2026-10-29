<?php

/**
 * A SECOND, messaging-only Meta app (Messenger + Instagram DMs) next to the main Tourfecto.pro app.
 *
 * Why: the main app's App Review does not include pages_messaging / instagram_manage_messages, and changing the main
 * app while that review is open is risky. A separate app in Development mode lets the app's own admins / developers /
 * testers receive and answer Messenger + Instagram messages today; it gets its own (small) review later.
 *
 * Everything here is ADDITIVE and OFF until META_MSG_APP_ID and META_MSG_APP_SECRET are set: it never touches the main
 * app's login, callback, scopes or webhook path. It uses its own redirect URI (/chat/connect/meta/callback).
 *
 * Settings (system settings first, then .env): meta_msg_app_id / META_MSG_APP_ID, meta_msg_app_secret / META_MSG_APP_SECRET,
 * optional meta_msg_login_config_id / META_MSG_LOGIN_CONFIG_ID (Facebook Login for Business), optional meta_msg_redirect_uri.
 */
final class MetaMessagingApp
{
    public const SCOPES = ['pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'instagram_basic', 'instagram_manage_messages'];
    private const STATE_TTL = 900;

    public static function appId(): string
    {
        return preg_replace('/\D/', '', MetaOAuthClient::setting('meta_msg_app_id', 'META_MSG_APP_ID'));
    }

    public static function secret(): string
    {
        return MetaOAuthClient::setting('meta_msg_app_secret', 'META_MSG_APP_SECRET');
    }

    public static function configured(): bool
    {
        return self::appId() !== '' && self::secret() !== '';
    }

    public static function redirectUri(): string
    {
        $custom = MetaOAuthClient::setting('meta_msg_redirect_uri', 'META_MSG_REDIRECT_URI');
        return $custom !== '' ? $custom : MetaOAuthClient::baseUrl() . '/chat/connect/meta/callback';
    }

    /** Signed, short-lived value tying the Facebook round trip to this user and website (CSRF protection). */
    public static function makeState(int $userId, int $websiteId, ?int $now = null): string
    {
        $payload = $userId . '.' . $websiteId . '.' . ($now ?? time()) . '.' . bin2hex(random_bytes(6));
        return $payload . '.' . self::sign($payload);
    }

    /** @return array{0:int,1:int}|null [userId, websiteId] when the state is genuine and fresh */
    public static function readState(string $state, ?int $now = null): ?array
    {
        $parts = explode('.', $state);
        if (count($parts) !== 5) {
            return null;
        }
        $payload = implode('.', array_slice($parts, 0, 4));
        if (!hash_equals(self::sign($payload), $parts[4])) {
            return null;
        }
        $age = ($now ?? time()) - (int) $parts[2];
        if ($age < 0 || $age > self::STATE_TTL) {
            return null;
        }
        return [(int) $parts[0], (int) $parts[1]];
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, 'meta-msg-state|' . self::secret());
    }

    public static function authUrl(string $state, bool $rerequest = false): string
    {
        $params = ['client_id' => self::appId(), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code', 'state' => $state];
        $config = preg_replace('/\D/', '', MetaOAuthClient::setting('meta_msg_login_config_id', 'META_MSG_LOGIN_CONFIG_ID'));
        if ($config !== '') {
            $params['config_id'] = $config; // permissions come from that Facebook Login for Business configuration
        } else {
            $params['scope'] = implode(',', self::SCOPES);
        }
        if ($rerequest) {
            $params['auth_type'] = 'rerequest';
        }
        return 'https://www.facebook.com/' . MetaOAuthClient::apiVersion() . '/dialog/oauth?' . http_build_query($params);
    }

    /**
     * Authorization code -> long-lived user token.
     * @param callable|null $get fn(string $url): array{0:int,1:string} [http code, body] (tests inject a fake)
     * @return array{success:bool,access_token?:string,error?:string}
     */
    public static function exchange(string $code, ?callable $get = null): array
    {
        $v = MetaOAuthClient::apiVersion();
        $short = self::graph("https://graph.facebook.com/$v/oauth/access_token?" . http_build_query([
            'client_id' => self::appId(), 'client_secret' => self::secret(), 'redirect_uri' => self::redirectUri(), 'code' => $code,
        ]), $get);
        if (empty($short['access_token'])) {
            return ['success' => false, 'error' => (string) ($short['error']['message'] ?? 'The authorization code was refused')];
        }
        $long = self::graph("https://graph.facebook.com/$v/oauth/access_token?" . http_build_query([
            'grant_type' => 'fb_exchange_token', 'client_id' => self::appId(), 'client_secret' => self::secret(), 'fb_exchange_token' => $short['access_token'],
        ]), $get);
        $token = (string) ($long['access_token'] ?? $short['access_token']);
        return ['success' => true, 'access_token' => $token];
    }

    /**
     * The Pages the user manages, with their own page tokens and the linked Instagram professional account.
     * @return array{success:bool,pages:array,error?:string}
     */
    public static function pages(string $userToken, ?callable $get = null): array
    {
        $v = MetaOAuthClient::apiVersion();
        $d = self::graph("https://graph.facebook.com/$v/me/accounts?" . http_build_query([
            'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100, 'access_token' => $userToken,
        ]), $get);
        if (!isset($d['data']) || !is_array($d['data'])) {
            return ['success' => false, 'pages' => [], 'error' => (string) ($d['error']['message'] ?? 'Facebook did not return your pages')];
        }
        $out = [];
        foreach ($d['data'] as $p) {
            if (empty($p['id']) || empty($p['access_token'])) {
                continue; // a page without a token cannot be answered for
            }
            $out[] = [
                'id' => (string) $p['id'],
                'name' => mb_substr((string) ($p['name'] ?? ''), 0, 200),
                'token' => (string) $p['access_token'],
                'ig_id' => (string) ($p['instagram_business_account']['id'] ?? ''),
                'ig_username' => mb_substr((string) ($p['instagram_business_account']['username'] ?? ''), 0, 100),
            ];
        }
        // Pages owned by a business portfolio are not in /me/accounts; Facebook Login for Business lists the ones the
        // user picked as "granular scopes" of the token, so read those pages one by one.
        $have = array_column($out, 'id');
        foreach (self::grantedPageIds($userToken, $get) as $pid) {
            if (in_array($pid, $have, true)) {
                continue;
            }
            $p = self::graph("https://graph.facebook.com/$v/" . rawurlencode($pid) . '?' . http_build_query([
                'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'access_token' => $userToken,
            ]), $get);
            if (empty($p['id']) || empty($p['access_token'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $p['id'],
                'name' => mb_substr((string) ($p['name'] ?? ''), 0, 200),
                'token' => (string) $p['access_token'],
                'ig_id' => (string) ($p['instagram_business_account']['id'] ?? ''),
                'ig_username' => mb_substr((string) ($p['instagram_business_account']['username'] ?? ''), 0, 100),
            ];
        }
        return ['success' => true, 'pages' => $out];
    }

    /** @return string[] ids of the Pages the user chose in the login dialog (pages_messaging target ids) */
    private static function grantedPageIds(string $userToken, ?callable $get): array
    {
        $v = MetaOAuthClient::apiVersion();
        $d = self::graph("https://graph.facebook.com/$v/debug_token?" . http_build_query([
            'input_token' => $userToken, 'access_token' => self::appId() . '|' . self::secret(),
        ]), $get);
        $ids = [];
        foreach ((array) ($d['data']['granular_scopes'] ?? []) as $g) {
            if (in_array((string) ($g['scope'] ?? ''), ['pages_messaging', 'pages_show_list', 'pages_manage_metadata'], true)) {
                foreach ((array) ($g['target_ids'] ?? []) as $id) {
                    if (preg_match('/^\d{5,25}$/', (string) $id)) {
                        $ids[(string) $id] = true;
                    }
                }
            }
        }
        return array_keys($ids);
    }

    /** @return array decoded JSON (or ['error'=>['message'=>...]]) */
    private static function graph(string $url, ?callable $get): array
    {
        if (strpos($url, 'https://graph.facebook.com/') !== 0) {
            return ['error' => ['message' => 'Blocked URL']];
        }
        if ($get) {
            [$code, $body] = $get($url);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return ['error' => ['message' => 'HTTP ' . (int) $code]];
        }
        return $data;
    }
}
