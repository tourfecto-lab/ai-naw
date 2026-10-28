<?php
/** Provider adapter contract. Implementations must only expose capabilities actually approved by the provider. */
interface OtaProviderAdapterInterface
{
    public function platform(): string;
    public function capabilities(): array;
    public function testConnection(array $credentials): array;
    public function fetchBookings(array $credentials, ?string $cursor = null): array;
    public function normalizeBooking(array $payload): array;
    public function createWebhookSubscription(array $credentials, string $callbackUrl): array;
}
