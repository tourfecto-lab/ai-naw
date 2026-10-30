<?php

/**
 * Tourfecto - AI Voice Agent: creating CRM tasks on either crm_tasks definition. The migration chain contains two (status open/done and
 * pending/completed) and CrmTaskService only writes 'open'. We try the CRM service first and, if the live table refuses it, insert with the
 * status the live column actually supports. This does not change the CRM; it only keeps follow-up tasks from being lost.
 */
class VoiceCrmCompat
{
    /** Pure: the "open" status of a column type like enum('open','in_progress','done','cancelled'). */
    public static function openStatusFrom(string $columnType): string
    {
        return stripos($columnType, "'open'") !== false ? 'open' : 'pending';
    }

    public static function createTask(int $tenantId, array $data): int
    {
        try {
            return (int) (new CrmTaskService())->create($tenantId, $data, $tenantId)->getAttribute('id');
        } catch (Throwable $e) {
            $db = Database::getInstance();
            $col = $db->query("SHOW COLUMNS FROM crm_tasks LIKE 'status'")[0]['Type'] ?? '';
            $prio = in_array($data['priority'] ?? 'medium', ['low', 'medium', 'high'], true) ? $data['priority'] : 'high';
            $ok = $db->exec(
                'INSERT INTO crm_tasks (user_id, created_by_user_id, assigned_to_user_id, related_type, related_id, title, description, due_date, priority, status) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$tenantId, $tenantId, $tenantId, $data['related_type'] ?? null, $data['related_id'] ?? null, mb_substr((string) $data['title'], 0, 200), $data['description'] ?? null, $data['due_date'] ?? null, $prio, self::openStatusFrom((string) $col)]
            );
            if (!$ok) {
                throw $e; // neither path worked: let the caller log it and retry later
            }
            return (int) $db->getConnection()->lastInsertId();
        }
    }
}
