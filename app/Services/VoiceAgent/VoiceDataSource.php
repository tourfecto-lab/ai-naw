<?php

/** Tourfecto - AI Voice Agent: everything the call brain reads or writes. Every method is tenant-scoped. */
interface VoiceDataSource
{
    public function getCall(int $tenantId, int $callId): ?array;

    /** @param array<string,mixed> $fields whitelisted columns only */
    public function updateCall(int $tenantId, int $callId, array $fields): void;

    public function addTurn(int $tenantId, int $callId, int $seq, string $role, string $text, array $meta = []): void;

    /** @return list<array{seq:int,role:string,text:string,meta:array}> oldest first */
    public function recentTurns(int $tenantId, int $callId, int $limit): array;

    /** @return list<array{id:int,name:string,description:string,price:float,currency:string}> */
    public function searchTrips(int $tenantId, string $query, int $limit = 4): array;

    /** @return array{found:bool, available?:bool, remaining?:int, unit_price?:float, total_price?:float, currency?:string, reason?:?string, name?:string} */
    public function tripAvailability(int $tenantId, int $productId, string $date, int $guests): array;

    /** @return array{known:bool, contact_id:?int, bookings:array, previous:array} */
    public function customerContext(int $tenantId, string $fromE164): array;

    /** @return array{email:?string, refs:string[]} */
    public function identityFactors(int $tenantId, int $contactId): array;

    public function businessName(int $tenantId): string;

    /** Company knowledge (FAQs, policies, trips) relevant to what the caller just said; '' when the company has none. */
    public function knowledgeContext(int $tenantId, string $lang, string $query): string;
}
