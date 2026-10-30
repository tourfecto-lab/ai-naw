<?php

/**
 * Tourfecto - AI Voice Agent: tenant-scoped provider management (credentials, tests, search, guarded operations).
 * Credentials are stored encrypted and are never returned; reads expose only masked hints.
 */
class VoiceProviderService
{
    private ?VoiceHttpClient $http;
    private VoiceSettingsService $settings;

    public function __construct(?VoiceHttpClient $http = null, ?VoiceSettingsService $settings = null)
    {
        $this->http = $http;
        $this->settings = $settings ?? new VoiceSettingsService();
    }

    /** Pure: keep only the fields the provider needs, trimmed. */
    public static function sanitizeCredentials(string $provider, array $in): array
    {
        $class = VoiceProviderRegistry::classFor($provider);
        $out = [];
        foreach ($class::credentialFields() as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            if ($v === '' || strlen($v) > 512) {
                throw new InvalidArgumentException('Missing or invalid credential: ' . $f);
            }
            $out[$f] = $v;
        }
        return $out;
    }

    /** Pure: last 4 characters only. */
    public static function mask(string $v): string
    {
        return strlen($v) <= 4 ? '****' : str_repeat('*', 6) . substr($v, -4);
    }

    private function enc(): Encryption
    {
        return new Encryption();
    }

    private static function salt(int $tenantId, string $provider): string
    {
        return 'voice:' . $tenantId . ':' . $provider;
    }

    public function listProviders(int $tenantId): array
    {
        $rows = [];
        foreach (Database::getInstance()->query('SELECT provider, status, last_test_at, last_test_detail FROM voice_providers WHERE tenant_user_id = ?', [$tenantId]) ?: [] as $r) {
            $rows[$r['provider']] = $r;
        }
        $out = [];
        foreach (VoiceProviderRegistry::keys() as $k) {
            $class = VoiceProviderRegistry::classFor($k);
            $r = $rows[$k] ?? null;
            $out[] = [
                'provider' => $k, 'configured' => $r !== null, 'status' => $r['status'] ?? 'not_configured',
                'last_test_at' => $r['last_test_at'] ?? null, 'last_test_detail' => $r['last_test_detail'] ?? null,
                'credential_fields' => $class::credentialFields(),
                'capabilities' => (new ReflectionClass($class))->newInstanceWithoutConstructor()->capabilities(),
            ];
        }
        return $out;
    }

    public function saveCredentials(int $tenantId, string $provider, array $input): void
    {
        $creds = self::sanitizeCredentials($provider, $input);
        Database::getInstance()->exec(
            "INSERT INTO voice_providers (tenant_user_id, provider, credentials_enc, status, created_at, updated_at) VALUES (?,?,?,'untested',NOW(),NOW())
             ON DUPLICATE KEY UPDATE credentials_enc=VALUES(credentials_enc), status='untested', last_test_at=NULL, last_test_detail=NULL, updated_at=NOW()",
            [$tenantId, $provider, $this->enc()->encrypt(json_encode($creds), self::salt($tenantId, $provider))]
        );
    }

    public function removeCredentials(int $tenantId, string $provider): bool
    {
        return Database::getInstance()->exec('DELETE FROM voice_providers WHERE tenant_user_id = ? AND provider = ?', [$tenantId, $provider]);
    }

    public function adapter(int $tenantId, string $provider): VoiceProviderAdapter
    {
        $r = Database::getInstance()->query('SELECT credentials_enc FROM voice_providers WHERE tenant_user_id = ? AND provider = ? LIMIT 1', [$tenantId, $provider]);
        if (!$r) {
            throw new InvalidArgumentException('Provider is not configured');
        }
        $json = $this->enc()->decrypt((string) $r[0]['credentials_enc'], self::salt($tenantId, $provider));
        $creds = json_decode($json, true);
        if (!is_array($creds)) {
            throw new RuntimeException('Stored credentials could not be read; please re-enter them');
        }
        return VoiceProviderRegistry::make($provider, $creds, $this->http);
    }

    /** Read-only provider call with error monitoring. */
    private function read(int $tenantId, string $provider, string $operation, callable $fn)
    {
        try {
            return $fn($this->adapter($tenantId, $provider));
        } catch (VoiceProviderException $e) {
            VoiceProviderErrorLog::record($tenantId, $provider, $operation, $e);
            throw $e;
        }
    }

    public function test(int $tenantId, string $provider): array
    {
        try {
            $r = $this->adapter($tenantId, $provider)->testConnection();
        } catch (VoiceProviderException $e) {
            VoiceProviderErrorLog::record($tenantId, $provider, 'test_connection', $e);
            $r = ['ok' => false, 'account' => '', 'detail' => $e->getMessage()];
        }
        Database::getInstance()->exec(
            'UPDATE voice_providers SET status=?, last_test_at=NOW(), last_test_detail=? WHERE tenant_user_id=? AND provider=?',
            [$r['ok'] ? 'ok' : 'failed', mb_substr($r['detail'], 0, 255), $tenantId, $provider]
        );
        return $r;
    }

    public function countries(int $tenantId, string $provider): array
    {
        return $this->read($tenantId, $provider, 'list_countries', fn (VoiceProviderAdapter $a) => $a->listCountries());
    }

    public function search(int $tenantId, string $provider, string $iso, string $type, array $filters = []): array
    {
        return $this->read($tenantId, $provider, 'search_numbers', fn (VoiceProviderAdapter $a) => $a->searchNumbers($iso, $type, $filters));
    }

    private function platformLive(): bool
    {
        return (new SystemSettingsService())->get('voice_live_operations_enabled', '0') === '1';
    }

    /**
     * Run a mutating operation: simulated unless VoiceLiveGuard allows it. Returns the result plus 'simulated' and 'reason'.
     * @param callable(VoiceProviderAdapter):array $live
     */
    public function guarded(int $tenantId, int $actorUserId, string $provider, string $operation, array $args, bool $approved, callable $live): array
    {
        $d = VoiceLiveGuard::decide($operation, $this->settings->get($tenantId), $this->platformLive(), $approved);
        if (!$d['live']) {
            $res = VoiceLiveGuard::simulate($operation, $args) + ['simulated' => true, 'reason' => $d['reason']];
            VoiceAuditService::record($tenantId, $actorUserId, $operation . '.simulated', 'provider', $provider, ['reason' => $d['reason']]);
            return $res;
        }
        try {
            $res = $live($this->adapter($tenantId, $provider));
        } catch (VoiceProviderException $e) {
            VoiceProviderErrorLog::record($tenantId, $provider, $operation, $e);
            VoiceAuditService::record($tenantId, $actorUserId, $operation . '.failed', 'provider', $provider);
            throw $e;
        }
        VoiceAuditService::record($tenantId, $actorUserId, $operation, 'provider', $provider);
        return $res + ['simulated' => false, 'reason' => $d['reason']];
    }
}
