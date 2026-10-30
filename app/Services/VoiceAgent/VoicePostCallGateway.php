<?php

/** Tourfecto - AI Voice Agent: every side effect a finished call can have (CRM, bookings, payment links, messages). Tenant-scoped; the real implementation reuses the existing services. */
interface VoicePostCallGateway
{
    public function settings(int $tenantId): array;

    /** @return array{id:int,name:string,email:?string,phone:?string}|null */
    public function contact(int $tenantId, int $contactId): ?array;

    public function createContact(int $tenantId, string $name, string $phone, ?string $email, string $source): int;

    /** Fill empty fields only; never overwrites what the account already holds. */
    public function fillContactMissing(int $tenantId, int $contactId, array $fields): void;

    public function openLeadId(int $tenantId, int $contactId): ?int;

    public function createLead(int $tenantId, int $contactId, array $data): int;

    public function addNote(int $tenantId, int $contactId, string $body): void;

    public function createTask(int $tenantId, array $data): int;

    /** @return array{id:int, reference:string, total:float} @throws Throwable when the booking engine refuses */
    public function createBooking(int $tenantId, array $data): array;

    public function confirmBooking(int $tenantId, int $bookingId): bool;

    /** @return string|null payment URL, or null when the account cannot collect payments */
    public function createPaymentLink(int $tenantId, int $bookingId, int $pct): ?string;

    /** @return array{name:string, price:float, currency:string}|null */
    public function trip(int $tenantId, int $productId): ?array;

    /** @return array{success:bool, error?:?string} */
    public function sendWhatsApp(int $tenantId, int $contactId, string $text, int $websiteId): array;

    /** @return array{success:bool, error?:?string} */
    public function sendEmail(int $tenantId, int $contactId, string $subject, string $text): array;

    public function notify(int $tenantId, string $title, string $body): void;

    public function triggerAutomation(int $tenantId, string $event, array $context): void;

    /** @return array{status:string, ref:?string, detail:?string}|null what was already done for this call */
    public function action(int $callId, string $action): ?array;

    public function logAction(int $tenantId, int $callId, string $action, string $status, ?string $ref = null, ?string $detail = null): void;
}
