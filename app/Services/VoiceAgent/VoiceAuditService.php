<?php

/** Tourfecto - AI Voice & Sales Agent: append-only audit trail, always tenant-scoped. */
class VoiceAuditService
{
    public static function record(int $tenantId, ?int $actorUserId, string $action, ?string $subjectType = null, $subjectId = null, array $meta = []): void
    {
        try {
            Database::getInstance()->exec(
                'INSERT INTO voice_audit_log (tenant_user_id, actor_user_id, action, subject_type, subject_id, meta, created_at) VALUES (?,?,?,?,?,?,NOW())',
                [$tenantId, $actorUserId, $action, $subjectType, $subjectId === null ? null : (string) $subjectId, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]
            );
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('VoiceAuditService: write failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
