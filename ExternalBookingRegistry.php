<?php
/**
 * Idempotent identity registry for external bookings.
 * This is the foundation layer only: platform-specific adapters must validate incoming data
 * and BookingEngine must own creation/update/cancellation of internal bookings.
 */
class ExternalBookingRegistry
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Register or refresh an external booking identity. Returns the binding row ID.
     * Does not create or modify bookings itself.
     */
    public function register(
        int $userId,
        int $websiteId,
        string $platform,
        string $externalAccountId,
        string $externalBookingId,
        ?int $connectionId = null,
        ?int $internalBookingId = null,
        ?string $externalStatus = null,
        ?string $normalizedPayload = null
    ): int {
        if ($userId < 1 || $websiteId < 1 || trim($platform) === '' || trim($externalBookingId) === '') {
            throw new InvalidArgumentException('External booking identity is incomplete.');
        }
        if (strlen($externalBookingId) > 255 || strlen($externalAccountId) > 255) {
            throw new InvalidArgumentException('External booking identity is too long.');
        }
        $hash = $normalizedPayload === null ? null : hash('sha256', $normalizedPayload);
        $sql = 'INSERT INTO external_booking_bindings
                (user_id, website_id, connection_id, platform, external_account_id, external_booking_id, booking_id, external_status, payload_hash, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                  connection_id = IF(user_id = VALUES(user_id), COALESCE(VALUES(connection_id), connection_id), connection_id),
                  booking_id = IF(user_id = VALUES(user_id), COALESCE(VALUES(booking_id), booking_id), booking_id),
                  external_status = IF(user_id = VALUES(user_id), VALUES(external_status), external_status),
                  payload_hash = IF(user_id = VALUES(user_id), VALUES(payload_hash), payload_hash),
                  last_seen_at = IF(user_id = VALUES(user_id), NOW(), last_seen_at),
                  updated_at = IF(user_id = VALUES(user_id), NOW(), updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $userId, $websiteId, $connectionId, strtolower(trim($platform)), trim($externalAccountId),
            trim($externalBookingId), $internalBookingId, $this->limit($externalStatus, 50), $hash,
        ]);

        $find = $this->pdo->prepare(
            'SELECT id, user_id FROM external_booking_bindings WHERE platform = ? AND website_id = ? AND external_account_id = ? AND external_booking_id = ? LIMIT 1'
        );
        $find->execute([strtolower(trim($platform)), $websiteId, trim($externalAccountId), trim($externalBookingId)]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('External booking identity is already bound to a different account.');
        }
        return (int) $row['id'];
    }

    public function find(int $websiteId, string $platform, string $externalAccountId, string $externalBookingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM external_booking_bindings WHERE website_id = ? AND platform = ? AND external_account_id = ? AND external_booking_id = ? LIMIT 1'
        );
        $stmt->execute([$websiteId, strtolower(trim($platform)), trim($externalAccountId), trim($externalBookingId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
