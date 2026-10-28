<?php

/**
 * Tourfecto - جلب تفاعل المنشورات من Meta Graph (لايكات/تعليقات/مشاركات/وصول) مع كاش.
 * أي نقص في الصلاحيات (read_insights / pages_read_engagement) بيتسجل "غير متاح" بدل ما يكسر الإحصائيات.
 * @version 1.0.0
 */
class SocialInsightsService
{
    private const STALE_SECONDS = 1800;

    /** @return int عدد المنشورات اللي اتحدّثت */
    public function refreshForUser(int $userId, int $max = 8): int
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT t.id AS target_id, t.external_post_id, c.platform, c.access_token
               FROM social_post_targets t
               JOIN social_posts p ON p.id = t.social_post_id
               JOIN platform_connections c ON c.id = t.platform_connection_id
          LEFT JOIN social_post_metrics m ON m.target_id = t.id
              WHERE p.user_id = :u AND t.status = 'published' AND t.external_post_id IS NOT NULL AND t.external_post_id <> ''
                AND c.platform IN ('facebook','instagram')
                AND (m.target_id IS NULL OR m.fetched_at < (NOW() - INTERVAL " . self::STALE_SECONDS . " SECOND))
              ORDER BY t.published_at DESC LIMIT " . (int) $max,
            [':u' => $userId]
        );
        $done = 0;
        $enc = new Encryption();
        foreach ((array) $rows as $r) {
            try {
                $token = $enc->decrypt((string) $r['access_token']);
                $m = $r['platform'] === 'facebook'
                    ? $this->facebook((string) $r['external_post_id'], $token)
                    : $this->instagram((string) $r['external_post_id'], $token);
            } catch (Throwable $e) {
                $m = ['state' => 'unavailable', 'note' => 'تعذر قراءة التوكن'];
            }
            $db->exec(
                'REPLACE INTO social_post_metrics (target_id, likes, comments, shares, reach, state, note, fetched_at)
                 VALUES (:t, :l, :c, :s, :r, :st, :n, NOW())',
                [':t' => (int) $r['target_id'], ':l' => (int) ($m['likes'] ?? 0), ':c' => (int) ($m['comments'] ?? 0), ':s' => (int) ($m['shares'] ?? 0),
                 ':r' => isset($m['reach']) ? (int) $m['reach'] : null, ':st' => $m['state'] ?? 'ok', ':n' => isset($m['note']) ? mb_substr((string) $m['note'], 0, 300) : null]
            );
            $done++;
        }
        return $done;
    }

    private function facebook(string $postId, string $token): array
    {
        $r = $this->graph($postId, ['fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0),shares'], $token);
        if (!$r['ok']) {
            return ['state' => 'unavailable', 'note' => $r['error']];
        }
        $d = $r['data'];
        $out = [
            'state' => 'ok',
            'likes' => (int) ($d['reactions']['summary']['total_count'] ?? 0),
            'comments' => (int) ($d['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($d['shares']['count'] ?? 0),
        ];
        $ins = $this->graph($postId . '/insights', ['metric' => 'post_impressions_unique'], $token);
        if ($ins['ok'] && isset($ins['data']['data'][0]['values'][0]['value'])) {
            $out['reach'] = (int) $ins['data']['data'][0]['values'][0]['value'];
        }
        return $out;
    }

    private function instagram(string $mediaId, string $token): array
    {
        $r = $this->graph($mediaId, ['fields' => 'like_count,comments_count'], $token);
        if (!$r['ok']) {
            return ['state' => 'unavailable', 'note' => $r['error']];
        }
        $out = ['state' => 'ok', 'likes' => (int) ($r['data']['like_count'] ?? 0), 'comments' => (int) ($r['data']['comments_count'] ?? 0), 'shares' => 0];
        $ins = $this->graph($mediaId . '/insights', ['metric' => 'reach'], $token);
        if ($ins['ok'] && isset($ins['data']['data'][0]['values'][0]['value'])) {
            $out['reach'] = (int) $ins['data']['data'][0]['values'][0]['value'];
        }
        return $out;
    }

    /** @return array{ok:bool, data:array, error:string} */
    private function graph(string $path, array $query, string $token): array
    {
        $ver = (function_exists('env') ? env('META_API_VERSION') : '') ?: 'v25.0';
        $query['access_token'] = $token;
        $ch = curl_init('https://graph.facebook.com/' . $ver . '/' . ltrim($path, '/') . '?' . http_build_query($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Tourfecto/1.0']);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['ok' => false, 'data' => [], 'error' => 'network'];
        }
        $d = json_decode((string) $resp, true);
        $d = is_array($d) ? $d : [];
        if ($http < 200 || $http >= 300 || isset($d['error'])) {
            $msg = (string) ($d['error']['message'] ?? ('HTTP ' . $http));
            return ['ok' => false, 'data' => [], 'error' => mb_substr($msg, 0, 200)];
        }
        return ['ok' => true, 'data' => $d, 'error' => ''];
    }
}
