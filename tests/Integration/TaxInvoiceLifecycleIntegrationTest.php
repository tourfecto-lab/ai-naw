<?php

/**
 * TaxService and InvoiceLifecycleService against the test DB.
 * Skipped automatically when the DB or a user row is unavailable. Uses fake country codes (ZZ, QQ)
 * and removes every row it creates.
 */

use PHPUnit\Framework\TestCase;

final class TaxInvoiceLifecycleIntegrationTest extends TestCase
{
    private ?Database $db = null;
    private int $userId = 0;
    private array $invoiceNumbers = [];

    protected function setUp(): void
    {
        try {
            $this->db = Database::getInstance();
            $this->db->query('SELECT 1 FROM tax_rules LIMIT 1');
            $this->db->query('SELECT 1 FROM invoices LIMIT 1');
            $u = $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }
        if (empty($u)) {
            $this->markTestSkipped('No user row available for the invoices FK');
        }
        $this->userId = (int) $u[0]['id'];
        $this->db->exec("DELETE FROM tax_rules WHERE country_code IN ('ZZ','QQ')");
    }

    protected function tearDown(): void
    {
        if (!$this->db) {
            return;
        }
        $this->db->exec("DELETE FROM tax_rules WHERE country_code IN ('ZZ','QQ')");
        foreach ($this->invoiceNumbers as $n) {
            $this->db->exec('DELETE FROM invoices WHERE invoice_number = ?', [$n]);
        }
    }

    private function invoice(string $status, string $dueDate): string
    {
        $n = 'TST-' . bin2hex(random_bytes(6));
        $this->invoiceNumbers[] = $n;
        $this->db->exec(
            "INSERT INTO invoices (user_id, invoice_number, plan_name, plan_type, amount, status, due_date)
             VALUES (?, ?, 'Test', 'monthly', 10.00, ?, ?)",
            [$this->userId, $n, $status, $dueDate]
        );
        return $n;
    }

    private function invoiceStatus(string $number): string
    {
        return (string) $this->db->query('SELECT status FROM invoices WHERE invoice_number = ?', [$number])[0]['status'];
    }

    public function testNoCountryMeansNotConfigured(): void
    {
        $r = (new TaxService())->computeTax(100.0, null);
        $this->assertFalse($r['configured']);
        $this->assertNull($r['tax_amount']);
    }

    public function testUnknownCountryHasNoDefaultRate(): void
    {
        $r = (new TaxService())->computeTax(100.0, 'ZZ');
        $this->assertFalse($r['configured']);
        $this->assertNull($r['rate_percent']);
        $this->assertNull($r['tax_amount']);
    }

    public function testConfiguredRuleComputesRoundedTaxAndIsCaseInsensitive(): void
    {
        $svc = new TaxService();
        $svc->upsertRule('zz', 'VAT', 14.0, true);
        $r = $svc->computeTax(99.99, 'zz');
        $this->assertTrue($r['configured']);
        $this->assertSame('VAT', $r['tax_type']);
        $this->assertSame(14.0, $r['rate_percent']);
        $this->assertSame(14.0, $r['tax_amount']); // 99.99 * 0.14 = 13.9986 -> 14.00
    }

    public function testInactiveRuleIsNotApplied(): void
    {
        $svc = new TaxService();
        $svc->upsertRule('QQ', 'VAT', 20.0, false);
        $this->assertFalse($svc->computeTax(100.0, 'QQ')['configured']);
    }

    public function testUpsertUpdatesExistingRuleInsteadOfDuplicating(): void
    {
        $svc = new TaxService();
        $svc->upsertRule('ZZ', 'VAT', 10.0, true);
        $svc->upsertRule('ZZ', 'VAT', 12.5, true);
        $rows = array_filter($svc->listAll(), fn($r) => $r['country_code'] === 'ZZ');
        $this->assertCount(1, $rows);
        $this->assertSame(12.5, $svc->computeTax(100.0, 'ZZ')['tax_amount']);
    }

    public function testZeroSubtotalGivesZeroTax(): void
    {
        $svc = new TaxService();
        $svc->upsertRule('ZZ', 'GST', 5.0, true);
        $this->assertSame(0.0, $svc->computeTax(0.0, 'ZZ')['tax_amount']);
    }

    public function testOverduePendingInvoiceIsMarkedOverdue(): void
    {
        $late = $this->invoice('pending', date('Y-m-d', strtotime('-3 days')));
        $r = (new InvoiceLifecycleService())->runLifecycleChecks();
        $this->assertGreaterThanOrEqual(1, $r['marked_overdue']);
        $this->assertSame('overdue', $this->invoiceStatus($late));
    }

    public function testNotYetDuePaidAndCancelledInvoicesAreUntouched(): void
    {
        $future = $this->invoice('pending', date('Y-m-d', strtotime('+5 days')));
        $paid = $this->invoice('paid', date('Y-m-d', strtotime('-10 days')));
        $cancelled = $this->invoice('cancelled', date('Y-m-d', strtotime('-10 days')));
        (new InvoiceLifecycleService())->runLifecycleChecks();
        $this->assertSame('pending', $this->invoiceStatus($future));
        $this->assertSame('paid', $this->invoiceStatus($paid));
        $this->assertSame('cancelled', $this->invoiceStatus($cancelled));
    }

    public function testRunningTwiceDoesNotReprocessOverdueInvoice(): void
    {
        $late = $this->invoice('pending', date('Y-m-d', strtotime('-1 day')));
        $svc = new InvoiceLifecycleService();
        $svc->runLifecycleChecks();
        $this->assertSame('overdue', $this->invoiceStatus($late));
        $second = $svc->runLifecycleChecks();
        $this->assertSame('overdue', $this->invoiceStatus($late));
        $this->assertIsInt($second['marked_overdue']);
    }

    public function testPaidInvoiceWithoutWalletReferenceIsNotRefunded(): void
    {
        $paid = $this->invoice('paid', date('Y-m-d'));
        (new InvoiceLifecycleService())->runLifecycleChecks();
        $this->assertSame('paid', $this->invoiceStatus($paid));
    }
}
