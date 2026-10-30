<?php

/** Tourfecto - AI Voice Agent: real post-call side effects, delegating to the platform's own CRM, booking, payment, WhatsApp and email services. */
class VoicePostCallCrmGateway implements VoicePostCallGateway
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    public function settings(int $tenantId): array
    {
        return (new VoiceSettingsService())->get($tenantId);
    }

    public function contact(int $tenantId, int $contactId): ?array
    {
        $r = $this->db()->query('SELECT id, name, email, phone FROM crm_contacts WHERE id = ? AND user_id = ? LIMIT 1', [$contactId, $tenantId]);
        return $r ? ['id' => (int) $r[0]['id'], 'name' => (string) $r[0]['name'], 'email' => $r[0]['email'], 'phone' => $r[0]['phone']] : null;
    }

    public function createContact(int $tenantId, string $name, string $phone, ?string $email, string $source): int
    {
        return (int) (new CrmContactService())->create($tenantId, ['name' => $name, 'phone' => $phone, 'email' => $email, 'source' => $source])->getAttribute('id');
    }

    public function fillContactMissing(int $tenantId, int $contactId, array $fields): void
    {
        foreach (['email', 'name'] as $f) {
            if (!empty($fields[$f])) {
                $this->db()->exec("UPDATE crm_contacts SET $f = ? WHERE id = ? AND user_id = ? AND ($f IS NULL OR $f = '')", [$fields[$f], $contactId, $tenantId]);
            }
        }
    }

    public function openLeadId(int $tenantId, int $contactId): ?int
    {
        $r = $this->db()->query(
            "SELECT l.id FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id WHERE c.user_id = ? AND l.contact_id = ? AND l.status IN ('new','nurturing','qualified') ORDER BY l.id DESC LIMIT 1",
            [$tenantId, $contactId]
        );
        return $r ? (int) $r[0]['id'] : null;
    }

    public function createLead(int $tenantId, int $contactId, array $data): int
    {
        return (int) (new CrmLeadService())->createLeadWithData($contactId, $tenantId, $data)->getAttribute('id');
    }

    public function addNote(int $tenantId, int $contactId, string $body): void
    {
        (new CrmNoteService())->create($tenantId, ['related_type' => 'contact', 'related_id' => $contactId, 'body' => $body], $tenantId);
    }

    public function createTask(int $tenantId, array $data): int
    {
        return (int) (new CrmTaskService())->create($tenantId, $data, $tenantId)->getAttribute('id');
    }

    public function createBooking(int $tenantId, array $data): array
    {
        $r = (new BookingEngine())->createBooking($tenantId, $data);
        return ['id' => (int) $r['id'], 'reference' => (string) $r['booking_reference'], 'total' => (float) $r['total_amount']];
    }

    public function confirmBooking(int $tenantId, int $bookingId): bool
    {
        return (new BookingEngine())->confirmBooking($tenantId, $bookingId);
    }

    public function createPaymentLink(int $tenantId, int $bookingId, int $pct): ?string
    {
        $r = (new PaymentLinkService())->create($tenantId, $tenantId, $bookingId, 'deposit_pct', (float) $pct, 48);
        return !empty($r['ok']) ? (string) $r['link']['url'] : null;
    }

    public function trip(int $tenantId, int $productId): ?array
    {
        $r = $this->db()->query('SELECT name, price, currency FROM crm_products WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1', [$productId, $tenantId]);
        return $r ? ['name' => (string) $r[0]['name'], 'price' => (float) $r[0]['price'], 'currency' => (string) $r[0]['currency']] : null;
    }

    public function sendWhatsApp(int $tenantId, int $contactId, string $text, int $websiteId): array
    {
        return (new CrmWhatsAppService($tenantId, $websiteId))->sendToContact($tenantId, $contactId, $text, $websiteId);
    }

    public function sendEmail(int $tenantId, int $contactId, string $subject, string $text): array
    {
        $html = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
        return (new CrmEmailService())->sendToContact($tenantId, $contactId, $subject, $html);
    }

    public function notify(int $tenantId, string $title, string $body): void
    {
        Notification::notify($tenantId, 'voice_call', $title, $body, '/voice-agent');
    }

    public function triggerAutomation(int $tenantId, string $event, array $context): void
    {
        (new CrmAutomationService())->trigger($event, $tenantId, $context);
    }

    public function action(int $callId, string $action): ?array
    {
        $r = $this->db()->query('SELECT status, ref, detail FROM voice_post_call_actions WHERE call_id = ? AND action = ? LIMIT 1', [$callId, $action]);
        return $r ? ['status' => (string) $r[0]['status'], 'ref' => $r[0]['ref'], 'detail' => $r[0]['detail']] : null;
    }

    public function logAction(int $tenantId, int $callId, string $action, string $status, ?string $ref = null, ?string $detail = null): void
    {
        $this->db()->exec(
            'INSERT INTO voice_post_call_actions (tenant_user_id, call_id, action, status, ref, detail, created_at) VALUES (?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE status=VALUES(status), ref=VALUES(ref), detail=VALUES(detail), created_at=NOW()',
            [$tenantId, $callId, $action, $status, $ref, $detail !== null ? mb_substr($detail, 0, 255) : null]
        );
    }
}
