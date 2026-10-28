<?php

/**
 * Tourfecto - Invoice Lifecycle Service
 * @version 1.0.0
 * @date 2026-08-14
 *
 * دورة حياة الفاتورة (Section 10) - مبنية على الـ ENUM الحقيقي الموسّع
 * (pending/paid/failed/cancelled/draft/issued/partially_paid/overdue/refunded)
 * المؤكد من dump قاعدة البيانات الفعلية.
 */
class InvoiceLifecycleService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** @return array{marked_overdue: int, marked_refunded: int} */
    public function runLifecycleChecks(): array
    {
        return [
            'marked_overdue' => $this->markOverdueInvoices(),
            'marked_refunded' => $this->syncRefundedInvoices(),
        ];
    }

    /** pending + due_date فاتت → overdue */
    private function markOverdueInvoices(): int
    {
        try {
            $rows = $this->db->query(
                "SELECT id, user_id FROM invoices WHERE status = 'pending' AND due_date < CURDATE()"
            );
            foreach ($rows as $row) {
                $this->db->exec("UPDATE invoices SET status = 'overdue' WHERE id = ?", [(int) $row['id']]);
                if (class_exists('Notification')) {
                    Notification::notify(
                        (int) $row['user_id'],
                        'invoice_overdue',
                        'فاتورة متأخرة السداد',
                        'عندك فاتورة تجاوزت تاريخ استحقاقها.',
                        '/subscription'
                    );
                }
            }
            return count($rows);
        } catch (Exception $e) {
            Logger::error('InvoiceLifecycleService::markOverdueInvoices failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * ربط حالة الفاتورة بحالة الاسترجاع الفعلية - لو معاملة الدفع
     * المرتبطة بالفاتورة (عبر transaction_id = 'wallet_tx_{id}') اتسجّل
     * عليها استرجاع كامل ناجح في refunds، الفاتورة تتحدّث لـ 'refunded'.
     * الربط عن طريق نص transaction_id لأنه المصدر الوحيد المتاح حاليًا
     * (مفيش FK مباشر بين invoices و payment_transactions في التصميم
     * الحالي) - موثّق هنا كقيد معماري معروف، مش سلوك مضمون 100%.
     */
    private function syncRefundedInvoices(): int
    {
        try {
            $rows = $this->db->query(
                "SELECT i.id, i.transaction_id
                 FROM invoices i
                 WHERE i.status = 'paid' AND i.transaction_id LIKE 'wallet_tx_%'"
            );
            $updated = 0;
            foreach ($rows as $row) {
                $walletTxId = (int) str_replace('wallet_tx_', '', (string) $row['transaction_id']);
                if ($walletTxId <= 0) {
                    continue;
                }
                $ptx = $this->db->query(
                    "SELECT id FROM payment_transactions WHERE related_wallet_transaction_id = ? LIMIT 1",
                    [$walletTxId]
                );
                if (empty($ptx)) {
                    continue;
                }
                $fullyRefunded = $this->db->query(
                    "SELECT pt.amount, COALESCE(SUM(r.amount), 0) AS refunded
                     FROM payment_transactions pt
                     LEFT JOIN refunds r ON r.payment_transaction_id = pt.id AND r.status = 'succeeded'
                     WHERE pt.id = ? GROUP BY pt.id",
                    [(int) $ptx[0]['id']]
                );
                if (!empty($fullyRefunded) && (float) $fullyRefunded[0]['refunded'] >= (float) $fullyRefunded[0]['amount']) {
                    $this->db->exec("UPDATE invoices SET status = 'refunded' WHERE id = ?", [(int) $row['id']]);
                    $updated++;
                }
            }
            return $updated;
        } catch (Exception $e) {
            Logger::error('InvoiceLifecycleService::syncRefundedInvoices failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }
}
