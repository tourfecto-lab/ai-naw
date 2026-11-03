<?php

/**
 * The AI-visibility API keys use the names the provider reads (geo_<provider>_api_key). They must be registered as
 * secrets: saved encrypted, and an older plaintext row is encrypted in place the first time it is read.
 */

use PHPUnit\Framework\TestCase;

final class SystemSettingsGeoKeysTest extends TestCase
{
    private const KEYS = ['geo_openai_api_key', 'geo_gemini_api_key', 'geo_perplexity_api_key'];

    private ?PDO $pdo = null;
    private array $saved = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            foreach (self::KEYS as $k) {
                $r = $this->pdo->query("SELECT setting_value, is_secret, category FROM system_settings WHERE setting_key = '$k'")->fetch(PDO::FETCH_ASSOC);
                $this->saved[$k] = $r ?: null;
                $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        foreach (self::KEYS as $k) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            if ($this->saved[$k]) {
                $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?,?,?,?)')->execute([$k, $this->saved[$k]['setting_value'], $this->saved[$k]['is_secret'], $this->saved[$k]['category']]);
            }
        }
    }

    private function row(string $key): array
    {
        return $this->pdo->query("SELECT setting_value, is_secret FROM system_settings WHERE setting_key = '$key'")->fetch(PDO::FETCH_ASSOC);
    }

    public function testSavedGeoKeysAreEncryptedAndReadBackInPlain(): void
    {
        $s = new SystemSettingsService();
        foreach (self::KEYS as $k) {
            $s->set($k, 'sk-test-' . $k);
            $row = $this->row($k);
            $this->assertSame(1, (int) $row['is_secret'], "$k must be stored as a secret");
            $this->assertStringNotContainsString('sk-test', $row['setting_value'], "$k must not be stored in clear text");
            $this->assertSame('sk-test-' . $k, $s->get($k));
        }
    }

    public function testLegacyPlaintextRowIsEncryptedInPlaceOnFirstRead(): void
    {
        $this->pdo->exec("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('geo_openai_api_key', 'sk-legacy-plain', 0, 'general')");
        $s = new SystemSettingsService();
        $this->assertSame('sk-legacy-plain', $s->get('geo_openai_api_key'), 'the key keeps working');
        $row = $this->row('geo_openai_api_key');
        $this->assertSame(1, (int) $row['is_secret']);
        $this->assertStringNotContainsString('sk-legacy-plain', $row['setting_value']);
        $this->assertSame('sk-legacy-plain', $s->get('geo_openai_api_key'), 'still readable after the upgrade');
    }

    /** The admin form pre-fills every field from this listing; a missing key leaves the default 0 and the next save zeroes the cap. */
    public function testMonthlyBudgetIsListedForTheAdminFormWithItsStoredValue(): void
    {
        $saved = $this->pdo->query("SELECT setting_value, is_secret, category FROM system_settings WHERE setting_key = 'geo_monthly_budget_usd'")->fetch(PDO::FETCH_ASSOC);
        try {
            $s = new SystemSettingsService();
            $s->set('geo_monthly_budget_usd', '5');
            $found = null;
            foreach ($s->getAllForAdmin() as $items) {
                foreach ($items as $item) {
                    if ($item['key'] === 'geo_monthly_budget_usd') {
                        $found = $item;
                    }
                }
            }
            $this->assertNotNull($found, 'geo_monthly_budget_usd must be in the admin listing');
            $this->assertSame('5', $found['value']);
        } finally {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = 'geo_monthly_budget_usd'");
            if ($saved) {
                $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?,?,?,?)')->execute(['geo_monthly_budget_usd', $saved['setting_value'], $saved['is_secret'], $saved['category']]);
            }
        }
    }
}
