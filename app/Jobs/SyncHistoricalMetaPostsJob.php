<?php
/** Periodic read-only Meta history refresh. Never calls publishing endpoints. */
require_once __DIR__ . '/../Services/SocialMedia/MetaSocialAPI.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialAccounts.php';

class SyncHistoricalMetaPostsJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 3600;
    private const ACCOUNTS_PER_RUN = 12;
    private const POSTS_PER_ACCOUNT = 30;

    public function handle(array $payload): void
    {
        @set_time_limit(240);
        try { $this->refresh(); }
        finally { self::schedule(); }
    }

    public static function ensureScheduled(): void
    {
        try {
            $db = Database::getInstance();
            if (empty($db->query("SHOW TABLES LIKE 'social_meta_history'"))) return;
            if (empty($db->query("SHOW TABLES LIKE 'social_meta_history_sync'"))) return;
            if (!$db->query("SELECT id FROM jobs WHERE job_class = 'SyncHistoricalMetaPostsJob' AND status = 'pending' LIMIT 1")) self::schedule();
        } catch (Throwable $e) {
            Logger::warning('Meta history schedule check failed', ['message' => $e->getMessage()]);
        }
    }

    private static function schedule(): void
    {
        try { Container::getInstance()->make(QueueManager::class)->push(self::class, [], 'social', self::INTERVAL_SECONDS); }
        catch (Throwable $e) { Logger::warning('Meta history job schedule failed', ['message' => $e->getMessage()]); }
    }

    private function refresh(): void
    {
        $db = Database::getInstance();
        // Select account owner through the website when a connection belongs to one.
        // The profile join excludes paused connections from automatic jobs.
        $rows = $db->query(
            "SELECT c.id, c.user_id, c.website_id, COALESCE(w.user_id,c.user_id) AS owner_id,
                    c.platform, c.external_location_id, c.access_token
               FROM platform_connections c
               LEFT JOIN websites w ON w.id=c.website_id
               LEFT JOIN social_account_profiles p ON p.platform_connection_id=c.id
               LEFT JOIN social_meta_history_sync s ON s.connection_id=c.id
              WHERE c.platform IN ('facebook','instagram') AND c.status='connected'
                AND COALESCE(p.is_active, 1)=1 AND COALESCE(w.user_id,c.user_id) IS NOT NULL
                AND c.external_location_id IS NOT NULL AND c.external_location_id <> ''
                AND (s.last_attempt_at IS NULL OR s.last_attempt_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 50 MINUTE))
              ORDER BY COALESCE(s.last_attempt_at,'2000-01-01') ASC, c.id ASC
              LIMIT " . self::ACCOUNTS_PER_RUN
        ) ?: [];
        $encryption = new Encryption();
        $summary = ['accounts_selected' => count($rows), 'accounts_synced' => 0, 'accounts_skipped' => 0, 'accounts_failed' => 0, 'posts_updated' => 0];
        foreach ($rows as $row) {
            $cid=(int)$row['id']; $owner=(int)$row['owner_id']; $platform=(string)$row['platform'];
            // Prevent concurrent workers from syncing the same account: row lock in MySQL.
            $db->query("INSERT IGNORE INTO social_meta_history_sync (connection_id, user_id, platform, status) VALUES (?, ?, ?, 'pending')", [$cid,$owner,$platform]);
            $claim=$db->query("UPDATE social_meta_history_sync SET status='running',last_attempt_at=UTC_TIMESTAMP(),last_error=NULL WHERE connection_id=? AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 50 MINUTE))",[$cid]);
            // Affected-row count is atomic: only the worker that claimed this connection may sync it.
            if ((int)$claim !== 1) { $summary['accounts_skipped']++; continue; }
            try {
                $token=$encryption->decrypt((string)$row['access_token']);
                $api=new MetaSocialAPI($token);
                $response=$platform==='facebook'
                    ?$api->getFacebookPostsWithStats((string)$row['external_location_id'],$token,self::POSTS_PER_ACCOUNT)
                    :$api->getInstagramMediaWithStats((string)$row['external_location_id'],$token,self::POSTS_PER_ACCOUNT);
                if (empty($response['success'])) throw new RuntimeException(substr((string)($response['error'] ?? 'Meta API request failed'),0,350));
                $processed=0;
                foreach ((array)($response['posts'] ?? []) as $post) {
                    $external=(string)($post['id'] ?? '');
                    if ($external==='') continue;
                    $date=!empty($post['created_time']) ? strtotime((string)$post['created_time']) : false;
                    $db->query(
                        "INSERT INTO social_meta_history (user_id,connection_id,platform,external_post_id,content,media_type,media_url,permalink,published_at,likes,comments,shares,fetched_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE content=VALUES(content),media_type=VALUES(media_type),media_url=VALUES(media_url),permalink=VALUES(permalink),published_at=VALUES(published_at),likes=VALUES(likes),comments=VALUES(comments),shares=VALUES(shares),fetched_at=UTC_TIMESTAMP()",
                        [$owner,$cid,$platform,$external,(string)($post['message'] ?? ''),(string)($post['type'] ?? 'text'),(string)($post['media_url'] ?? ''),(string)($post['permalink'] ?? ''),$date?gmdate('Y-m-d H:i:s',$date):null,$post['likes'] ?? null,$post['comments'] ?? null,$platform==='facebook' ? ($post['shares'] ?? null) : null]
                    );
                    $processed++;
                }
                $db->query("UPDATE social_meta_history_sync SET status='success',last_success_at=UTC_TIMESTAMP(),last_count=?,last_error=NULL WHERE connection_id=?",[$processed,$cid]);
                $summary['accounts_synced']++;
                $summary['posts_updated'] += $processed;
            } catch (Throwable $e) {
                $summary['accounts_failed']++;
                $db->query("UPDATE social_meta_history_sync SET status='error',last_error=? WHERE connection_id=?",[mb_substr($e->getMessage(),0,500),$cid]);
                Logger::warning('Historical Meta auto sync failed',['connection_id'=>$cid,'message'=>$e->getMessage()]);
            }
        }
        Logger::info('Historical Meta sync batch finished', $summary);
    }
}
