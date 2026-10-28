<?php
/** Read-only customer readiness. Stored bindings are evidence of configuration, never of delivery. */
final class CustomerReadinessService
{
    private PDO $pdo;
    private string $root;
    private array $tables = [];
    public function __construct(?PDO $pdo = null, ?string $root = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->root = $root ?? dirname(__DIR__, 2);
    }
    private function read(string $sql, array $args = []): array
    {
        $s = $this->pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function has(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->read('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
    }
    public static function connectionState(?array $row, ?int $now = null): string
    {
        if (!$row) return 'not_connected';
        if (in_array($row['status'] ?? '', ['token_expired', 'error', 'disconnected', 'revoked'], true)) return 'action_required';
        if (($row['status'] ?? '') !== 'connected') return 'needs_check';
        $expiry = !empty($row['token_expires_at']) ? strtotime($row['token_expires_at'] . ' UTC') : false;
        return $expiry && $expiry < ($now ?? time()) ? 'needs_check' : 'configured';
    }
    public function report(int $owner, ?int $website = null): array
    {
        if ($owner < 1) throw new RuntimeException('Unauthorized', 401);
        $sites = $this->read('SELECT id,company_name,main_url FROM websites WHERE user_id=? ORDER BY id', [$owner]);
        $site = null;
        foreach ($sites as $s) if (!$website || (int) $s['id'] === $website) { $site = $s; break; }
        if ($website && !$site) throw new RuntimeException('Website not found', 404);
        $checks = [];
        $add = static function (string $key, string $label, string $state, string $message, string $href, array $extra = []) use (&$checks) {
            $checks[] = array_merge(compact('key', 'label', 'state', 'message', 'href'), $extra);
        };
        $add('website', 'Website', $site ? 'configured' : 'action_required', $site ? 'Website selected. Ownership and publishing permissions are checked by each service.' : 'Add and verify a website first.', '/websites');
        if ($site) {
            $wid = (int) $site['id'];
            try {
                $profile = $this->has('business_hub_profiles') ? $this->read('SELECT facts_json,confirmed_at FROM business_hub_profiles WHERE user_id=? AND website_id=? LIMIT 1', [$owner, $wid]) : [];
                $facts = json_decode((string) ($profile[0]['facts_json'] ?? ''), true) ?: [];
                $confirmed = !empty($profile[0]['confirmed_at']) && !empty($facts['business_name']) && !empty($facts['business_facts']);
                // Never approve imported facts, or copy them into another module, merely by viewing readiness.
                require_once __DIR__.'/BusinessHub/BusinessHubService.php';
                $confirmed = $confirmed && !empty($facts['source_site_url']) && BusinessHubService::siteKey((string)$site['main_url']) === BusinessHubService::siteKey((string)$facts['source_site_url']);
                $add('company', 'Confirmed company details', $confirmed ? 'configured' : 'action_required', $confirmed ? 'Shared company facts are confirmed for this website.' : 'Review and confirm the shared company facts; imported information is not approved automatically.', '/business-hub');
            } catch (Throwable $e) { $add('company', 'Confirmed company details', 'unknown', 'Could not read company readiness.', '/business-hub'); }
            try {
                $count = $this->has('ai_knowledge_base') ? (int) $this->read('SELECT COUNT(*) n FROM ai_knowledge_base WHERE website_id=? AND is_active=1', [$wid])[0]['n'] : 0;
                $add('assistant', 'Assistant knowledge', $count ? 'configured' : 'action_required', $count ? 'Active knowledge is available. Preview the assistant before automatic replies.' : 'The assistant has no active company knowledge. Add verified services, prices and policies before relying on its replies.', '/chat/knowledge-base', ['count' => $count]);
            } catch (Throwable $e) { $add('assistant', 'Assistant knowledge', 'unknown', 'Could not read assistant knowledge.', '/chat/knowledge-base'); }
            try {
                $rows = $this->has('platform_connections') ? $this->read('SELECT platform,website_id,status,token_expires_at,last_synced_at,meta_json FROM platform_connections WHERE user_id=? AND (website_id=? OR website_id IS NULL OR website_id=0) ORDER BY id', [$owner, $wid]) : [];
                $map = [];
                foreach ($rows as $row) { $k = $row['platform']; if (!isset($map[$k]) || (int) $row['website_id'] === $wid) $map[$k] = $row; }
                foreach (['search_console' => 'Google Search Console', 'google_analytics' => 'Google Analytics', 'google_business' => 'Google Business Profile', 'meta_ads' => 'Meta Ads', 'whatsapp_cloud' => 'WhatsApp'] as $key => $label) {
                    $row = $map[$key] ?? null;
                    $state = self::connectionState($row);
                    if ($row && in_array($key, ['search_console', 'google_analytics'], true) && (int) $row['website_id'] !== $wid) $state = 'needs_check';
                    $message = $state === 'configured' ? 'Connection stored for this scope. This is not a live permission or delivery test.' : ($state === 'not_connected' ? 'No connection is stored for this website.' : 'Check website selection, permissions and token renewal in the service.');
                    $href = match ($key) { 'whatsapp_cloud' => '/chat/settings', 'google_business' => '/gbp-content', 'meta_ads' => '/ads/connections', default => '/integrations' };
                    $add($key, $label, $state, $message, $href, ['last_success' => $row['last_synced_at'] ?? null, 'observed_scope' => $row && (int) $row['website_id'] === $wid ? 'website' : 'account', 'token_expires_at' => $row['token_expires_at'] ?? null]);
                    if ($key === 'whatsapp_cloud' && $row) {
                        $meta = json_decode((string) ($row['meta_json'] ?? ''), true) ?: [];
                        $templates = $meta['templates'] ?? [];
                        $approved = 0;
                        foreach (['tf_trip_reminder', 'tf_review_request'] as $name) foreach ((array) ($templates[$name] ?? []) as $status) if (is_string($status) && strtoupper($status) === 'APPROVED') $approved++;
                        $add('whatsapp_templates', 'WhatsApp service templates', $approved ? 'needs_check' : 'waiting_approval', $approved ? 'Approved templates exist. Check that each required service template is approved in the recipient language.' : 'Reminder and review-request templates have no recorded approval. Wait for Meta and refresh their status; do not send to customers as a test.', '/chat/settings');
                    }
                }
            } catch (Throwable $e) { $add('connections', 'Connections', 'unknown', 'Could not read saved connection states.', '/integrations'); }
            try {
                if ($this->has('social_posts') && $this->has('social_post_targets')) {
                    $r = $this->read("SELECT COUNT(DISTINCT p.id) n, MIN(t.scheduled_at) next_at FROM social_posts p JOIN social_post_targets t ON t.social_post_id=p.id WHERE p.user_id=? AND p.website_id=? AND t.status='scheduled'", [$owner, $wid])[0];
                    $overdue = $r['next_at'] && strtotime($r['next_at'].' UTC') < time()-900;
                    $add('social_schedule', 'Scheduled social posts', $overdue ? 'needs_check' : ($r['n'] ? 'scheduled' : 'not_configured'), $overdue ? 'The scheduled time has passed. Review publishing results and the background worker.' : ($r['n'] ? 'Posts are scheduled; waiting for their time is not a publishing failure.' : 'No scheduled posts were found for this website.'), '/social#posts', ['count' => (int) $r['n'], 'next_run' => $r['next_at']]);
                }
            } catch (Throwable $e) { $add('social_schedule', 'Scheduled social posts', 'unknown', 'Could not read the publishing schedule.', '/social#posts'); }
        }
        $heartbeat = $this->root . '/storage/cron/heartbeat';
        $age = is_file($heartbeat) ? time() - (int) filemtime($heartbeat) : null;
        $add('scheduler', 'Background tasks', $age !== null && $age < 300 ? 'observed' : 'needs_check', $age !== null && $age < 300 ? 'A recent scheduler heartbeat was observed. Individual task and delivery results remain separate.' : 'No recent scheduler heartbeat was observed. Contact support to check background processing.', '/service-center');
        $counts = array_count_values(array_column($checks, 'state'));
        return ['website' => $site, 'websites' => $sites, 'checks' => $checks, 'counts' => $counts, 'generated_at' => gmdate('c'), 'scope' => 'Selected website and shared account connections', 'note' => 'Read-only checks: no messages, charges, publishing, automatic reconnects or changes to confirmed company facts.'];
    }
}
