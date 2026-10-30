<?php

/**
 * Tourfecto - AI Voice Agent: monthly statement and invoice per company. The invoice is one row per (company, month), so re-running never double-bills.
 * Charging the wallet is OFF unless the platform owner sets voice_billing_enabled=1; until then invoices stay "open" and nothing is deducted.
 */
class VoiceBillingService
{
    private VoiceUsageService $usage;

    public function __construct(?VoiceUsageService $usage = null)
    {
        $this->usage = $usage ?? new VoiceUsageService();
    }

    public function statement(int $tenantId, ?string $month = null): array
    {
        $month = $month ?? gmdate('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Invalid month');
        }
        $m = $this->usage->month($tenantId, $month);
        $inv = Database::getInstance()->query('SELECT status, price_usd, error FROM voice_invoices WHERE tenant_user_id = ? AND month = ? LIMIT 1', [$tenantId, $month])[0] ?? null;
        return ['month' => $month, 'total_usd' => $m['price_usd'], 'lines' => array_map(static fn ($c) => ['usd' => $c['price_usd'], 'quantity' => $c['quantity']], $m['by_component']), 'invoice' => $inv];
    }

    /** Create or refresh the open invoice for a closed month; a charged invoice is never changed. */
    public function invoice(int $tenantId, string $month): array
    {
        $m = $this->usage->month($tenantId, $month);
        Database::getInstance()->exec(
            "INSERT INTO voice_invoices (tenant_user_id, month, cost_usd, price_usd, status, created_at, updated_at) VALUES (?,?,?,?,'open',NOW(),NOW())
             ON DUPLICATE KEY UPDATE cost_usd=IF(status IN ('charged','waived'),cost_usd,VALUES(cost_usd)), price_usd=IF(status IN ('charged','waived'),price_usd,VALUES(price_usd)), updated_at=NOW()",
            [$tenantId, $month, $m['cost_usd'], $m['price_usd']]
        );
        return $this->statement($tenantId, $month);
    }

    /** @return string charged | skipped_disabled | skipped_zero | already | failed */
    public function settle(int $tenantId, string $month, ?WalletService $wallet = null): string
    {
        $db = Database::getInstance();
        $inv = $db->query('SELECT id, status, price_usd FROM voice_invoices WHERE tenant_user_id = ? AND month = ? LIMIT 1', [$tenantId, $month])[0] ?? null;
        if (!$inv || in_array($inv['status'], ['charged', 'waived'], true)) {
            return 'already';
        }
        if ((new SystemSettingsService())->get('voice_billing_enabled', '0') !== '1') {
            return 'skipped_disabled';
        }
        $amount = round((float) $inv['price_usd'], 2);
        if ($amount <= 0) {
            $db->exec("UPDATE voice_invoices SET status='waived', updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$inv['id'], $tenantId]);
            return 'skipped_zero';
        }
        // claim the invoice first so two runs cannot both deduct
        $db->exec("UPDATE voice_invoices SET status='failed', error='in progress', updated_at=NOW() WHERE id=? AND tenant_user_id=? AND status IN ('open','failed')", [$inv['id'], $tenantId]);
        try {
            $tx = ($wallet ?? new WalletService())->adminDeductBalance($tenantId, $amount, 0, 'AI Voice Agent usage ' . $month);
            $db->exec("UPDATE voice_invoices SET status='charged', wallet_tx_id=?, error=NULL, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$tx->id ?? null, $inv['id'], $tenantId]);
            return 'charged';
        } catch (Throwable $e) {
            $db->exec("UPDATE voice_invoices SET status='failed', error=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [mb_substr($e->getMessage(), 0, 250), $inv['id'], $tenantId]);
            return 'failed';
        }
    }
}
