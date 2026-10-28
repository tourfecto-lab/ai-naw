<?php

/**
 * Tourfecto - Tax Service
 * @version 1.0.0
 * @date 2026-08-12
 *
 * حساب الضريبة حسب دولة العميل الحقيقية (من billing_profiles.country،
 * الحقل اللي أضفناه في Phase 4). لو مفيش قاعدة ضريبية مُعرَّفة لدولة
 * العميل، الرد صراحةً "Not Configured" - مفيش نسبة افتراضية أبدًا.
 */
class TaxService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @return array{configured: bool, country_code: ?string, tax_type: ?string, rate_percent: ?float, tax_amount: ?float}
     */
    public function computeTax(float $subtotal, ?string $countryCode): array
    {
        if (!$countryCode) {
            return ['configured' => false, 'country_code' => null, 'tax_type' => null, 'rate_percent' => null, 'tax_amount' => null];
        }

        try {
            $rows = $this->db->query(
                "SELECT * FROM tax_rules WHERE country_code = ? AND is_active = 1 LIMIT 1",
                [strtoupper($countryCode)]
            );
        } catch (Exception $e) {
            Logger::error('TaxService::computeTax query failed', ['message' => $e->getMessage()]);
            return ['configured' => false, 'country_code' => $countryCode, 'tax_type' => null, 'rate_percent' => null, 'tax_amount' => null];
        }

        if (empty($rows)) {
            return ['configured' => false, 'country_code' => $countryCode, 'tax_type' => null, 'rate_percent' => null, 'tax_amount' => null];
        }

        $rule = $rows[0];
        $rate = (float) $rule['tax_rate_percent'];
        $amount = round($subtotal * ($rate / 100), 2);

        return [
            'configured' => true,
            'country_code' => $countryCode,
            'tax_type' => $rule['tax_type'],
            'rate_percent' => $rate,
            'tax_amount' => $amount,
        ];
    }

    /** كل قواعد الضرائب (لعرضها/تعديلها في لوحة الأدمن) */
    public function listAll(): array
    {
        try {
            return $this->db->query("SELECT * FROM tax_rules ORDER BY country_code ASC");
        } catch (Exception $e) {
            return [];
        }
    }

    public function upsertRule(string $countryCode, string $taxType, float $ratePercent, bool $isActive): void
    {
        $this->db->exec(
            "INSERT INTO tax_rules (country_code, tax_type, tax_rate_percent, is_active)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tax_rate_percent = VALUES(tax_rate_percent), is_active = VALUES(is_active)",
            [strtoupper($countryCode), $taxType, $ratePercent, $isActive ? 1 : 0]
        );
    }
}
