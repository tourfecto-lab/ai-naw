<?php
/**
 * Tourfecto integration sync audit helper.
 * Stores counts and sanitized error summaries; never pass API tokens or payment data.
 */
class IntegrationSyncLogger
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function start(int $userId, int $websiteId, string $platform, string $resourceType, array $metadata = []): int
    {
        $this->assertIdentity($userId, $websiteId, $platform, $resourceType);
        $stmt = $this->pdo->prepare(
            "INSERT INTO integration_sync_logs (user_id, website_id, platform, resource_type, status, started_at, metadata_json)
             VALUES (?, ?, ?, ?, 'running', NOW(), ?)"
        );
        $stmt->execute([$userId, $websiteId, $platform, $resourceType, $this->json($this->safeMetadata($metadata))]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $logId, string $status, array $counts = [], ?string $errorCode = null, ?string $errorMessage = null): bool
    {
        if ($logId < 1 || !in_array($status, ['success', 'partial', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid integration sync completion state.');
        }
        $started = $this->pdo->prepare('SELECT started_at FROM integration_sync_logs WHERE id = ?');
        $started->execute([$logId]);
        $startedAt = $started->fetchColumn();
        if (!$startedAt) {
            return false;
        }
        $durationMs = max(0, (int) round((microtime(true) - strtotime((string) $startedAt)) * 1000));
        $stmt = $this->pdo->prepare(
            'UPDATE integration_sync_logs SET status = ?, items_read = ?, items_created = ?, items_updated = ?, items_skipped = ?,
             error_code = ?, error_message = ?, finished_at = NOW(), duration_ms = ? WHERE id = ?'
        );
        $stmt->execute([
            $status,
            max(0, (int) ($counts['read'] ?? 0)),
            max(0, (int) ($counts['created'] ?? 0)),
            max(0, (int) ($counts['updated'] ?? 0)),
            max(0, (int) ($counts['skipped'] ?? 0)),
            $this->limit($errorCode, 100),
            $this->limit($this->redact($errorMessage), 1000),
            $durationMs,
            $logId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function fail(int $logId, Throwable $error, ?string $errorCode = null): bool
    {
        // Persist only a concise message. Do not store stack traces or request headers.
        return $this->finish($logId, 'failed', [], $errorCode ?? get_class($error), $error->getMessage());
    }

    private function assertIdentity(int $userId, int $websiteId, string $platform, string $resourceType): void
    {
        if ($userId < 1 || $websiteId < 1 || !preg_match('/^[a-z0-9_]{2,50}$/i', $platform)
            || !preg_match('/^[a-z0-9_]{2,40}$/i', $resourceType)) {
            throw new InvalidArgumentException('Invalid integration sync identity.');
        }
    }

    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/token|secret|password|authorization|cookie|card|payment|email|phone/i', (string) $key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[(string) $key] = is_string($value) ? substr($value, 0, 250) : $value;
            }
        }
        return $safe;
    }

    private function redact(?string $message): ?string
    {
        if ($message === null) return null;
        $message = preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message);
        $message = preg_replace('/(access_token|refresh_token|api[_-]?key|client_secret)=([^&\s]+)/i', '$1=[REDACTED]', $message);
        return $message;
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function json(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
