<?php

/**
 * Name and picture of a Messenger / Instagram customer. The webhook only carries an id (PSID / IGSID), so the inbox used to
 * show a number. After a message is saved we ask Graph once for the profile (page token) and keep the name on the
 * conversation and a small square picture next to the other contact photos. Always best effort: a failure never touches
 * message handling.
 */
final class MetaContactProfile
{
    private const MAX_PHOTO_BYTES = 2000000;

    public static function fields(string $platform): string
    {
        return $platform === 'instagram' ? 'name,username,profile_pic' : 'first_name,last_name,profile_pic';
    }

    /** @return array{name:string,pic:string} */
    public static function parse(string $platform, array $d): array
    {
        if ($platform === 'instagram') {
            $name = trim((string) ($d['name'] ?? ''));
            if ($name === '' && !empty($d['username'])) {
                $name = '@' . ltrim((string) $d['username'], '@');
            }
        } else {
            $name = trim(((string) ($d['first_name'] ?? '')) . ' ' . ((string) ($d['last_name'] ?? '')));
        }
        $pic = (string) ($d['profile_pic'] ?? '');
        return ['name' => mb_substr($name, 0, 120), 'pic' => MetaWebhookController::isMetaCdn($pic) ? $pic : ''];
    }

    /**
     * @param string[] $secrets app secrets to sign the call with (the first one Facebook accepts wins); '' = unsigned
     * @param callable|null $get fn(string $url): array{0:int,1:string} (tests inject a fake)
     * @return array{name:string,pic:string}|null
     */
    public static function fetch(string $platform, string $id, string $token, array $secrets, ?callable $get = null): ?array
    {
        if (!preg_match('/^\d{5,25}$/', $id) || $token === '') {
            return null;
        }
        $v = MetaOAuthClient::apiVersion();
        foreach (array_values(array_unique(array_merge($secrets, ['']))) as $secret) {
            $q = ['fields' => self::fields($platform), 'access_token' => $token];
            if ($secret !== '') {
                $q['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
            }
            $url = "https://graph.facebook.com/$v/$id?" . http_build_query($q);
            if ($get) {
                [$code, $body] = $get($url);
            } else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
                $body = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
            $d = json_decode((string) $body, true);
            if ($code === 200 && is_array($d) && empty($d['error'])) {
                return self::parse($platform, $d);
            }
        }
        return null;
    }

    /**
     * Fill the conversation's name and picture when they are still missing.
     * @param callable|null $get see fetch()
     * @param callable|null $download fn(string $url): string picture bytes ('' on failure); tests inject a fake
     */
    public static function enrich(Database $db, int $websiteId, string $platform, string $id, string $token, array $secrets, ?callable $get = null, ?callable $download = null): void
    {
        try {
            $row = $db->query('SELECT id, customer_name FROM ai_conversations WHERE website_id = ? AND channel = ? AND channel_thread_id = ? LIMIT 1', [$websiteId, $platform, $id])[0] ?? null;
            if (!$row) {
                return;
            }
            $cid = (int) $row['id'];
            $needName = trim((string) ($row['customer_name'] ?? '')) === '';
            $none = ChatContactPhotos::path($websiteId, $cid) . '.none'; // "asked Meta, there is nothing to show" - ask again after a day
            $asked = is_file($none) && (time() - (int) @filemtime($none)) < 86400;
            $needPhoto = ChatContactPhotos::read($websiteId, $cid) === null && !$asked;
            if (!$needName && !$needPhoto) {
                return;
            }
            $p = self::fetch($platform, $id, $token, $secrets, $get);
            if ($p === null || ($p['pic'] === '' && $needPhoto)) {
                self::markAsked($none);
            }
            if ($p === null) {
                return;
            }
            if ($needName && $p['name'] !== '') {
                $db->query('UPDATE ai_conversations SET customer_name = ? WHERE id = ? AND website_id = ?', [$p['name'], $cid, $websiteId]);
            }
            if ($needPhoto && $p['pic'] !== '') {
                $bytes = $download ? $download($p['pic']) : self::download($p['pic']);
                if ($bytes !== '') {
                    ChatContactPhotos::save($websiteId, $cid, $bytes);
                }
            }
        } catch (Throwable $e) {
            Logger::warning('Meta contact profile failed', ['website_id' => $websiteId, 'platform' => $platform, 'error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    private static function markAsked(string $file): void
    {
        $dir = dirname($file);
        if (is_dir($dir) || @mkdir($dir, 0750, true) || is_dir($dir)) {
            @touch($file);
        }
    }

    /**
     * Same as enrich() for a conversation that is already in the inbox (opened before this feature existed, or whose first
     * lookup failed): finds the website's page / Instagram connection and its token.
     */
    public static function enrichForWebsite(Database $db, int $websiteId, string $platform, string $id): void
    {
        try {
            $types = $platform === 'instagram' ? ['instagram_dm', 'instagram'] : ['messenger'];
            $in = implode(',', array_fill(0, count($types), '?'));
            $row = $db->query("SELECT access_token FROM platform_connections WHERE website_id = ? AND platform IN ($in) AND status = 'connected' ORDER BY (platform = ?) DESC, id DESC LIMIT 1", array_merge([$websiteId], $types, [$types[0]]))[0] ?? null;
            $token = $row && !empty($row['access_token']) ? (new Encryption())->decrypt((string) $row['access_token']) : '';
            if ($token === '') {
                return;
            }
            $secrets = array_values(array_filter([
                class_exists('MetaMessagingApp') ? MetaMessagingApp::secret() : '',
                MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET'),
            ]));
            self::enrich($db, $websiteId, $platform, $id, $token, $secrets);
        } catch (Throwable $e) {
            Logger::warning('Meta contact profile (backfill) failed', ['error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    /** Only Meta's own CDN hosts, size capped, through the SSRF-safe client. */
    private static function download(string $url): string
    {
        if (!MetaWebhookController::isMetaCdn($url)) {
            return '';
        }
        $r = SafeHttp::exec($url, [CURLOPT_TIMEOUT => 8, CURLOPT_MAXFILESIZE => self::MAX_PHOTO_BYTES], 2);
        $body = is_string($r['response']) ? $r['response'] : '';
        return $r['code'] === 200 && strlen($body) <= self::MAX_PHOTO_BYTES ? $body : '';
    }
}
