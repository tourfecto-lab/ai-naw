<?php
/** Stable external identity and idempotency helpers; deliberately does not write to the DB. */
final class OtaIdentity
{
    public static function externalKey(int $ownerId, string $platform, string $accountId, string $entityType, string $externalId): string
    {
        if ($ownerId < 1 || trim($platform)==='' || trim($accountId)==='' || trim($entityType)==='' || trim($externalId)==='') {
            throw new InvalidArgumentException('Owner, platform, account, entity type and external ID are required.');
        }
        return hash('sha256', json_encode([$ownerId, OtaPlatformCatalog::normalizeKey($platform), trim($accountId), trim($entityType), trim($externalId)], JSON_THROW_ON_ERROR));
    }
    public static function normalizeBooking(array $source, string $platform, string $accountId): array
    {
        $reference = trim((string)($source['booking_reference'] ?? $source['reservation_id'] ?? $source['id'] ?? ''));
        if ($reference === '') throw new InvalidArgumentException('External booking reference is missing.');
        $status = strtolower(trim((string)($source['status'] ?? 'unknown')));
        $allowed = ['pending','confirmed','cancelled','modified','completed','no_show','unknown'];
        if (!in_array($status, $allowed, true)) $status = 'unknown';
        $currency = strtoupper(trim((string)($source['currency'] ?? '')));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/D', $currency)) $currency = '';
        $amount = $source['total_amount'] ?? $source['amount'] ?? null;
        if ($amount !== null && (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount < 0)) $amount = null;
        return [
            'platform'=>OtaPlatformCatalog::normalizeKey($platform), 'account_id'=>trim($accountId),
            'external_reference'=>$reference, 'status'=>$status,
            'currency'=>$currency ?: null, 'total_amount'=>$amount === null ? null : round((float)$amount, 2),
            'guest_name'=>self::safeText($source['guest_name'] ?? $source['customer_name'] ?? null, 191),
            'start_date'=>self::safeDate($source['start_date'] ?? $source['check_in'] ?? null),
            'end_date'=>self::safeDate($source['end_date'] ?? $source['check_out'] ?? null),
            'raw_payload'=>$source,
        ];
    }
    private static function safeText($value, int $max): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : (function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max));
    }
    private static function safeDate($value): ?string
    {
        if (!is_string($value) || trim($value)==='') return null;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($value), 0, 10));
        return $dt && $dt->format('Y-m-d') === substr(trim($value), 0, 10) ? $dt->format('Y-m-d') : null;
    }
}
