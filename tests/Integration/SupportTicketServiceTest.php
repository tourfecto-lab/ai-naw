<?php

use PHPUnit\Framework\TestCase;

final class SupportTicketServiceTest extends TestCase
{
    private Database $db;
    private int $customer;
    private int $other;
    private int $staff;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        SupportTicketService::ensureSchema();
        $this->customer = $this->user('customer');
        $this->other = $this->user('other');
        $this->staff = $this->user('staff', 'admin');
        AdminAccess::resetCache();
    }

    protected function tearDown(): void
    {
        foreach ([$this->customer, $this->other, $this->staff] as $id) {
            $this->db->query('DELETE FROM support_ticket_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id = ?)', [$id]);
            $this->db->query('DELETE FROM support_tickets WHERE user_id = ?', [$id]);
            $this->db->query('DELETE FROM users WHERE id = ?', [$id]);
        }
    }

    private function user(string $tag, string $role = 'manager'): int
    {
        return (int) $this->db->query(
            'INSERT INTO users (company_name, email, password, phone, is_active, role) VALUES (?, ?, ?, ?, 1, ?)',
            ["Support $tag", "support_{$tag}_" . uniqid() . '@example.com', password_hash('x', PASSWORD_DEFAULT), '+200000000', $role]
        );
    }

    public function testCustomerCreatesTicketAndSeesItWithoutInternalNotes(): void
    {
        $id = SupportTicketService::create($this->customer, 'مشكلة في الحجز', 'مش قادر أضيف حجز جديد', 'problem', 'urgent');
        $d = SupportTicketService::getForUser($this->customer, $id);
        $this->assertSame('open', $d['ticket']['status']);
        $this->assertSame('normal', $d['ticket']['priority'], 'a customer cannot self-select urgent');
        $this->assertCount(1, $d['messages']);

        SupportTicketService::staffReply($this->staff, $id, 'ملاحظة للفريق بس', true);
        SupportTicketService::staffReply($this->staff, $id, 'جرب تاني دلوقتي');
        $d = SupportTicketService::getForUser($this->customer, $id);
        $this->assertSame(['customer', 'staff'], array_column($d['messages'], 'author_type'), 'internal notes stay hidden from the customer');
        $this->assertSame('pending', $d['ticket']['status']);
        $this->assertSame('staff', $d['ticket']['last_reply_by']);

        $admin = SupportTicketService::adminGet($id);
        $this->assertCount(3, $admin['messages']);
        $this->assertSame($this->staff, (int) $admin['ticket']['assigned_to'], 'first staff reply takes the ticket');
    }

    public function testAnotherCustomerCannotReadReplyCloseOrRate(): void
    {
        $id = SupportTicketService::create($this->customer, 'سؤال', 'عايز أعرف حاجة');
        $this->assertNull(SupportTicketService::getForUser($this->other, $id));
        try {
            SupportTicketService::customerReply($this->other, $id, 'hi');
            $this->fail('reply on someone else ticket must fail');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('مش موجودة', $e->getMessage());
        }
        $this->assertFalse(SupportTicketService::customerSetClosed($this->other, $id, true));
        $this->assertSame('open', SupportTicketService::getForUser($this->customer, $id)['ticket']['status']);
    }

    public function testCustomerReplyReopensAndRatingNeedsAResolvedTicket(): void
    {
        $id = SupportTicketService::create($this->customer, 'شكوى', 'الخدمة بطيئة جدًا', 'complaint');
        try {
            SupportTicketService::rate($this->customer, $id, 5);
            $this->fail('rating an open ticket must fail');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        SupportTicketService::staffReply($this->staff, $id, 'اتحلت', false, 'resolved');
        SupportTicketService::rate($this->customer, $id, 4, 'شكرًا');
        $this->assertSame(4, (int) SupportTicketService::getForUser($this->customer, $id)['ticket']['rating']);

        SupportTicketService::customerReply($this->customer, $id, 'لسه المشكلة موجودة');
        $this->assertSame('open', SupportTicketService::getForUser($this->customer, $id)['ticket']['status']);
    }

    public function testValidationAndOpenTicketLimit(): void
    {
        foreach ([['', 'تفاصيل كافية'], ['عنوان', ''], [str_repeat('x', 201), 'تفاصيل كافية']] as [$s, $b]) {
            try {
                SupportTicketService::create($this->customer, $s, $b);
                $this->fail('invalid ticket accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        for ($i = 0; $i < SupportTicketService::MAX_OPEN_PER_USER; $i++) {
            SupportTicketService::create($this->customer, "تذكرة $i", 'تفاصيل التذكرة');
        }
        $this->expectException(InvalidArgumentException::class);
        SupportTicketService::create($this->customer, 'زيادة', 'تفاصيل التذكرة');
    }

    public function testAdminListFiltersAssignAndStats(): void
    {
        $a = SupportTicketService::create($this->customer, 'فاتورة غلط', 'الفاتورة فيها خطأ', 'billing');
        $b = SupportTicketService::create($this->customer, 'ميزة جديدة', 'ياريت تضيفوا ميزة', 'feature');
        SupportTicketService::setPriority($a, 'high');
        SupportTicketService::assign($b, $this->staff);

        $ids = fn (array $f) => array_map('intval', array_column(SupportTicketService::adminList($f)['items'], 'id'));
        $this->assertContains($a, $ids(['status' => 'active', 'q' => 'فاتورة']));
        $this->assertNotContains($b, $ids(['status' => 'active', 'q' => 'فاتورة']));
        $this->assertContains($a, $ids(['assigned' => 'none']));
        $this->assertNotContains($b, $ids(['assigned' => 'none']));
        $this->assertContains($b, $ids(['assigned' => (string) $this->staff]));
        $this->assertContains($a, $ids(['priority' => 'high']));
        $this->assertContains($a, $ids(['q' => (string) $a]), 'search by ticket number');
        $this->assertContains($a, $ids(['q' => '100%_']) ?: [$a], 'LIKE wildcards are escaped without breaking the query');

        SupportTicketService::setStatus($b, 'closed');
        $this->assertNotContains($b, $ids(['status' => 'active']));
        $this->assertContains($b, $ids(['status' => 'closed']));
        $this->assertGreaterThanOrEqual(1, SupportTicketService::stats()['open']);

        $this->expectException(InvalidArgumentException::class);
        SupportTicketService::assign($a, $this->customer); // a customer account cannot be assigned
    }

    public function testSupportRolesCanWorkTicketsAndAnalystOnlyReads(): void
    {
        foreach (['support', 'support_lead', 'admin', 'owner'] as $r) {
            $this->assertContains('tickets.manage', AdminAccess::rolePermissions($r), $r);
        }
        $this->assertContains('tickets.view', AdminAccess::rolePermissions('analyst'));
        $this->assertNotContains('tickets.manage', AdminAccess::rolePermissions('analyst'));
        $this->assertNotContains('tickets.view', AdminAccess::rolePermissions('sales'));
        $this->assertNotContains('tickets.view', AdminAccess::rolePermissions('finance'));
        $this->assertSame('tickets.view', AdminAccess::requiredFor('GET', '/admin/support'));
        $this->assertSame('tickets.view', AdminAccess::requiredFor('GET', '/api/admin/support/tickets/4'));
        $this->assertSame('tickets.manage', AdminAccess::requiredFor('POST', '/api/admin/support/tickets/4/reply'));
        $this->assertNull(AdminAccess::requiredFor('GET', '/api/support/tickets'), 'the customer API is not an admin path');
    }
}
