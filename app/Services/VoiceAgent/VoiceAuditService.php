<?php

/**
 * Tourfecto - AI Voice Agent: audit trail. Reuses the platform's ActivityLog (module "voice") instead of a table of its own.
 * user_id is the ACCOUNT (tenant) so an account's trail is one indexed lookup; who acted goes in meta.actor_user_id.
 */
class VoiceAuditService
{
    public static function record(int $tenantId, ?int $actorUserId, string $action, ?string $subjectType = null, $subjectId = null, array $meta = []): void
    {
        try {
            $data = ['user_id' => $tenantId, 'subject_type' => $subjectType, 'meta' => ['actor_user_id' => $actorUserId] + $meta];
            if ($subjectId !== null && ctype_digit((string) $subjectId)) {
                $data['subject_id'] = (int) $subjectId; // activity_logs.subject_id is numeric
            } elseif ($subjectId !== null) {
                $data['meta']['subject'] = (string) $subjectId;
            }
            ActivityLog::record('voice', $action, $data);
        } catch (Throwable $e) {
            // auditing must never break a call or a request
        }
    }

    /** @return list<array<string,mixed>> newest first, this account only */
    public static function recent(int $tenantId, int $limit = 50): array
    {
        return Database::getInstance()->query(
            "SELECT action, subject_type, subject_id, meta, created_at FROM activity_logs WHERE module = 'voice' AND user_id = ? ORDER BY id DESC LIMIT " . max(1, min(200, $limit)),
            [$tenantId]
        ) ?: [];
    }
}
