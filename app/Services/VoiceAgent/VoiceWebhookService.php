<?php

/**
 * Tourfecto - AI Voice Agent: provider status webhooks. Verifies the signature with the tenant's own credentials
 * BEFORE trusting anything, de-duplicates by event id, and records duration/cost. Returns [httpStatus, body].
 */
class VoiceWebhookService
{
    private VoiceProviderService $providers;

    public function __construct(?VoiceProviderService $providers = null)
    {
        $this->providers = $providers ?? new VoiceProviderService();
    }

    /** @param array<string,string> $headers lower-case header names */
    public function handle(string $provider, int $tenantId, string $url, array $headers, string $rawBody, array $params): array
    {
        if (!VoiceProviderRegistry::has($provider) || $tenantId < 1) {
            return [404, 'Not found'];
        }
        try {
            $adapter = $this->providers->adapter($tenantId, $provider);
        } catch (Throwable $e) {
            return [404, 'Not found'];
        }
        if (!$adapter->verifyWebhook($url, $headers, $rawBody, $params)) {
            if (class_exists('Logger')) {
                Logger::warning('VoiceWebhookService: invalid signature', ['provider' => $provider, 'tenant' => $tenantId]);
            }
            return [403, 'Invalid signature'];
        }
        $ev = $adapter->parseStatusEvent($headers, $rawBody, $params);
        if ($ev === null || $ev['event_id'] === '') {
            return [200, 'ignored'];
        }
        $db = Database::getInstance();
        if ($db->query('SELECT id FROM voice_webhook_events WHERE provider = ? AND event_id = ? LIMIT 1', [$provider, $ev['event_id']])) {
            return [200, 'duplicate'];
        }
        // INSERT IGNORE + the unique key keep a concurrent retry from double-inserting; cost recording is an idempotent upsert.
        $db->exec('INSERT IGNORE INTO voice_webhook_events (tenant_user_id, provider, event_id, call_id, status, created_at) VALUES (?,?,?,?,?,NOW())', [$tenantId, $provider, $ev['event_id'], $ev['call_id'], $ev['status']]);
        if (in_array($ev['status'], ['completed', 'busy', 'failed', 'no-answer', 'canceled'], true)) {
            try {
                (new VoiceInboundService($this->providers))->onStatus($tenantId, $provider, $ev);
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('VoiceWebhookService: call close failed', ['error' => $e->getMessage()]);
                }
            }
            $cost = null;
            $source = 'none';
            if ($adapter->capabilities()['cost_lookup']) {
                try {
                    $c = $adapter->fetchCallCost($ev['call_id']);
                    $cost = $c['cost_usd'] ?? null;
                    $source = $cost === null ? 'none' : 'provider';
                } catch (VoiceProviderException $e) {
                    VoiceProviderErrorLog::record($tenantId, $provider, 'fetch_call_cost', $e);
                }
            }
            VoiceCostTracker::record($tenantId, $provider, $ev['call_id'], $ev['direction'], $ev['duration_s'], $cost, $source);
        }
        return [200, 'ok'];
    }
}
