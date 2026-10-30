<?php

/**
 * Tourfecto - AI Voice Agent: telephony provider adapter contract.
 * Adapters do exactly what they are asked (real HTTP); the safety decision to simulate or go live lives in
 * VoiceProviderService/VoiceLiveGuard. Not every provider supports every country or feature: callers must read
 * capabilities() and the live search results instead of assuming.
 */
interface VoiceProviderAdapter
{
    public function key(): string;

    /** @return array<string,bool> numbers_search, numbers_purchase, inbound, outbound, sip, transfer, recording, streaming, porting, cost_lookup */
    public function capabilities(): array;

    /** @return string[] credential field names that must be supplied */
    public static function credentialFields(): array;

    /** @return array{ok:bool, account:string, detail:string} */
    public function testConnection(): array;

    /** @return list<array{iso:string,name:string,types:string[]}> */
    public function listCountries(): array;

    /** @return list<array<string,mixed>> normalised: number,country,type,monthly_cost,setup_cost,currency,voice,sms,address_required,provider */
    public function searchNumbers(string $iso, string $type, array $filters = [], int $limit = 20): array;

    /** @return array{number_id:string, number:string, status:string} */
    public function purchaseNumber(string $e164, array $opts = []): array;

    public function releaseNumber(string $numberId): bool;

    /** @return array{call_id:string, status:string} */
    public function startCall(string $from, string $to, array $opts = []): array;

    /** @return array{call_id:string, status:string} */
    public function transferCall(string $callId, string $toE164): array;

    public function hangupCall(string $callId): bool;

    /** Answer instructions for an inbound call. ['content_type'=>..., 'body'=>...] */
    public function inboundInstructions(array $opts): array;

    /**
     * One conversational turn for providers that can run speech-gather turns (capability turn_mode).
     * opts: say, voice, language (speech locale), gather_url (null = no further input), transfer_to (E.164), hangup (bool)
     * @return array{content_type:string, body:string}
     */
    public function turnInstructions(array $opts): array;

    public function verifyWebhook(string $url, array $headers, string $rawBody, array $params): bool;

    /** @return array<string,mixed>|null normalised: event_id,call_id,status,direction,from,to,duration_s,cost_usd,cost_source,recording_url */
    public function parseStatusEvent(array $headers, string $rawBody, array $params): ?array;

    /** Numbers already in the customer's own provider account (authoritative ownership proof for "bring your own account").
     * @return list<array{number:string, number_id:string}> */
    public function listOwnedNumbers(): array;

    /** Published per-minute usage rates for a country, or null when the provider API does not expose them.
     * @return array{inbound_per_min:?float, outbound_per_min:?float, currency:string}|null */
    public function usageRates(string $iso, string $type): ?array;

    /** @return array{cost_usd:float, currency:string}|null null = provider cannot look this up */
    public function fetchCallCost(string $callId): ?array;
}
