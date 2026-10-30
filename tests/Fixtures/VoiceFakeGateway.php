<?php

/** Recording fake of VoicePostCallGateway for AI Voice Agent tests. */
final class VoiceFakeGateway implements VoicePostCallGateway
{
    public array $settings = ['booking_mode' => 'request_only', 'booking_max_amount' => null, 'post_call_whatsapp' => 0, 'post_call_email' => 0, 'payment_link_pct' => 0, 'whatsapp_website_id' => null];
    public array $contacts = [];
    public int $nextContact = 100;
    public array $created = [];      // createContact calls
    public array $filled = [];
    public array $leads = [];
    public array $notes = [];
    public array $tasks = [];
    public array $bookings = [];
    public array $confirmed = [];
    public array $wa = [];
    public array $mails = [];
    public array $notified = [];
    public array $automations = [];
    public array $log = [];          // callId:action => [status, ref, detail]
    public ?int $openLead = null;
    public array $trips = [];
    public ?string $payUrl = null;
    public ?Throwable $bookingError = null;
    public array $waResult = ['success' => true];
    public array $mailResult = ['success' => true];
    public int $failContactTimes = 0;

    public function settings(int $t): array
    {
        return $this->settings;
    }

    public function contact(int $t, int $id): ?array
    {
        return $this->contacts[$id] ?? null;
    }

    public function createContact(int $t, string $name, string $phone, ?string $email, string $source): int
    {
        if ($this->failContactTimes > 0) {
            $this->failContactTimes--;
            throw new RuntimeException('crm down');
        }
        $id = $this->nextContact++;
        $this->contacts[$id] = ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone];
        $this->created[] = compact('name', 'phone', 'email', 'source');
        return $id;
    }

    public function fillContactMissing(int $t, int $id, array $fields): void
    {
        $this->filled[] = [$id, $fields];
    }

    public function openLeadId(int $t, int $cid): ?int
    {
        return $this->openLead;
    }

    public function createLead(int $t, int $cid, array $data): int
    {
        $this->leads[] = [$cid, $data];
        return 900 + count($this->leads);
    }

    public function addNote(int $t, int $cid, string $body): void
    {
        $this->notes[] = [$cid, $body];
    }

    public function createTask(int $t, array $data): int
    {
        $this->tasks[] = $data;
        return 500 + count($this->tasks);
    }

    public function createBooking(int $t, array $data): array
    {
        if ($this->bookingError) {
            throw $this->bookingError;
        }
        $this->bookings[] = $data;
        return ['id' => 700 + count($this->bookings), 'reference' => 'TF-TEST01', 'total' => (float) $data['expected_total']];
    }

    public function confirmBooking(int $t, int $id): bool
    {
        $this->confirmed[] = $id;
        return true;
    }

    public function createPaymentLink(int $t, int $bid, int $pct): ?string
    {
        return $this->payUrl;
    }

    public function trip(int $t, int $pid): ?array
    {
        return $this->trips[$pid] ?? null;
    }

    public function sendWhatsApp(int $t, int $cid, string $text, int $site): array
    {
        $this->wa[] = [$cid, $text];
        return $this->waResult;
    }

    public array $waTemplates = [];
    public array $waTemplateResult = ['success' => true];

    public function sendWhatsAppTemplate(int $t, int $cid, string $text, int $site, string $template, string $lang, string $op): array
    {
        $this->waTemplates[] = [$cid, $template, $lang, $op, $text];
        return $this->waTemplateResult;
    }

    public function sendEmail(int $t, int $cid, string $subject, string $text): array
    {
        $this->mails[] = [$cid, $subject, $text];
        return $this->mailResult;
    }

    public function notify(int $t, string $title, string $body): void
    {
        $this->notified[] = [$title, $body];
    }

    public function triggerAutomation(int $t, string $event, array $ctx): void
    {
        $this->automations[] = [$event, $ctx];
    }

    public function action(int $tenantId, int $callId, string $action): ?array
    {
        $r = $this->log[$callId . ':' . $action] ?? null;
        return $r ? ['status' => $r[0], 'ref' => $r[1], 'detail' => $r[2]] : null;
    }

    public function logAction(int $t, int $callId, string $action, string $status, ?string $ref = null, ?string $detail = null): void
    {
        $this->log[$callId . ':' . $action] = [$status, $ref, $detail];
    }
}
