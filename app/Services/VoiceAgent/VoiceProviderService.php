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

    /** Capability map of a provider class without credentials. */
    public static function capabilitiesOf(string $provider): array
    {
        return (new ReflectionClass(VoiceProviderRegistry::classFor($provider)))->newInstanceWithoutConstructor()->capabilities();
    }

    public static function ownAccountsAllowed(): bool
    {
        return (new SystemSettingsService())->get('voice_allow_own_provider_accounts', '0') === '1';
    }

    /** First provider with a configured platform account (the full cost/quality selector comes with Phase 9). */
    public static function defaultProvider(): ?string
    {
        foreach (VoiceProviderRegistry::keys() as $k) {
            if (VoicePlatformCredentials::forProvider($k) !== null) {
                return $k;
            }
        }
        return null;
    }

    public function listProviders(int $tenantId): array
    {
        $rows = [];
        foreach (Database::getInstance()->query('SELECT provider, mode, provider_account_id, status, last_test_at, last_test_detail FROM voice_providers WHERE tenant_user_id = ?', [$tenantId]) ?: [] as $r) {
            $rows[$r['provider']] = $r;
        }
        $out = [];
        foreach (VoiceProviderRegistry::keys() as $k) {
            $r = $rows[$k] ?? null;
            $platform = VoicePlatformCredentials::forProvider($k) !== null;
            $out[] = [
                'provider' => $k,
                'available' => $platform || ($r !== null && $r['mode'] === 'own'),
                'mode' => $r['mode'] ?? ($platform ? 'platform' : null),
                'provisioned' => $r !== null,
                'status' => $r['status'] ?? ($platform ? 'platform_account' : 'not_configured'),
                'last_test_at' => $r['last_test_at'] ?? null, 'last_test_detail' => $r['last_test_detail'] ?? null,
                'own_account_allowed' => self::ownAccountsAllowed(),
                'webhook_urls' => VoiceInboundService::webhookUrls(defined('APP_URL') ? APP_URL : '', $k, $tenantId),
                'capabilities' => self::capabilitiesOf($k),
            ];
        }
        return $out;
    }

    /** A company's OWN provider keys. Off unless the platform owner allows it; the default is Tourfecto's own provider account. */
    public function saveCredentials(int $tenantId, string $provider, array $input): void
    {
        if (!self::ownAccountsAllowed()) {
            throw new InvalidArgumentException('Your phone numbers are provided by Tourfecto; bringing your own provider account is not enabled');
        }
        $creds = self::sanitizeCredentials($provider, $input);
        Database::getInstance()->exec(
            "INSERT INTO voice_providers (tenant_user_id, provider, mode, credentials_enc, status, created_at, updated_at) VALUES (?,?,'own',?,'untested',NOW(),NOW())
             ON DUPLICATE KEY UPDATE mode='own', provider_account_id=NULL, credentials_enc=VALUES(credentials_enc), status='untested', last_test_at=NULL, last_test_detail=NULL, updated_at=NOW()",
            [$tenantId, $provider, $this->enc()->encrypt(json_encode($creds), self::salt($tenantId, $provider))]
        );
    }

    public function removeCredentials(int $tenantId, string $provider): bool
    {
        return Database::getInstance()->exec("DELETE FROM voice_providers WHERE tenant_user_id = ? AND provider = ? AND mode = 'own'", [$tenantId, $provider]); // never the platform sub-account link
    }

    /** @return array<string,string>|null decrypted credentials stored for this company, or null when it has no row */
    private function storedCredentials(int $tenantId, string $provider): ?array
    {
        $r = Database::getInstance()->query('SELECT mode, credentials_enc FROM voice_providers WHERE tenant_user_id = ? AND provider = ? LIMIT 1', [$tenantId, $provider]);
        if (!$r) {
            return null;
        }
        $creds = json_decode($this->enc()->decrypt((string) $r[0]['credentials_enc'], self::salt($tenantId, $provider)), true);
        if (!is_array($creds)) {
            throw new RuntimeException('Stored credentials could not be read');
        }
        // platform mode: the shared platform key, overridden by the company's own sub-account keys when it has them
        return $r[0]['mode'] === 'platform' ? $creds + (VoicePlatformCredentials::forProvider($provider) ?? []) : $creds;
    }

    /** Adapter bound to THIS company's account: its sub-account / own keys. Used for everything that touches the company's numbers and calls. */
    public function adapter(int $tenantId, string $provider): VoiceProviderAdapter
    {
        $creds = $this->storedCredentials($tenantId, $provider);
        if ($creds === null) {
            throw new InvalidArgumentException('Provider is not set up for this account');
        }
        return VoiceProviderRegistry::make($provider, $creds, $this->http);
    }

    /** Adapter for catalogue reads (countries, number search, prices): the company's own keys if it brought them, else the platform account. Nothing company-specific is touched. */
    public function readAdapter(int $tenantId, string $provider): VoiceProviderAdapter
    {
        $r = Database::getInstance()->query("SELECT mode FROM voice_providers WHERE tenant_user_id = ? AND provider = ? AND mode = 'own' LIMIT 1", [$tenantId, $provider]);
        if ($r) {
            return $this->adapter($tenantId, $provider);
        }
        $platform = VoicePlatformCredentials::forProvider($provider);
        if ($platform === null) {
            throw new InvalidArgumentException('This provider is not available yet');
        }
        return VoiceProviderRegistry::make($provider, $platform, $this->http);
    }

    /**
     * Creates the company's isolated account at the provider (a Twilio sub-account; Telnyx uses the shared platform key, attributed in our own records).
     * Only reached from the live branch of guarded(), so it never runs in sandbox.
     */
    private function ensureProvisioned(int $tenantId, string $provider): void
    {
        if (Database::getInstance()->query('SELECT id FROM voice_providers WHERE tenant_user_id = ? AND provider = ? LIMIT 1', [$tenantId, $provider])) {
            return;
        }
        $platform = VoicePlatformCredentials::forProvider($provider);
        if ($platform === null) {
            throw new InvalidArgumentException('This provider is not available yet');
        }
        $accountId = null;
        $creds = [];
        if (!empty(self::capabilitiesOf($provider)['subaccounts'])) {
            $sub = VoiceProviderRegistry::make($provider, $platform, $this->http)->createSubaccount('Tourfecto company ' . $tenantId);
            $accountId = $sub['account_id'];
            $creds = $sub['credentials'];
        }
        Database::getInstance()->exec(
            "INSERT IGNORE INTO voice_providers (tenant_user_id, provider, mode, provider_account_id, credentials_enc, status, created_at, updated_at) VALUES (?,?,'platform',?,?,'ok',NOW(),NOW())",
            [$tenantId, $provider, $accountId, $this->enc()->encrypt(json_encode((object) $creds), self::salt($tenantId, $provider))]
        );
        VoiceAuditService::record($tenantId, null, 'provider.account_created', 'provider', $provider, ['account' => $accountId]);
    }

    /** Read-only provider call with error monitoring. */
    private function read(int $tenantId, string $provider, string $operation, callable $fn)
    {
        try {
            return $fn($this->readAdapter($tenantId, $provider));
        } catch (VoiceProviderException $e) {
            VoiceProviderErrorLog::record($tenantId, $provider, $operation, $e);
            throw $e;
        }
    }

    public function test(int $tenantId, string $provider): array
    {
        try {
            $r = $this->readAdapter($tenantId, $provider)->testConnection();
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

    /** Would this operation really reach the provider right now? (same decision `guarded` makes) */
    public function wouldGoLive(int $tenantId, string $operation, bool $approved): bool
    {
        return VoiceLiveGuard::decide($operation, $this->settings->get($tenantId), $this->platformLive(), $approved)['live'];
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
            $this->ensureProvisioned($tenantId, $provider);
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
