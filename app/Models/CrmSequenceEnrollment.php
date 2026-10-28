<?php

/** Tourfecto - CRM Sequence Enrollment Model (المرحلة 15 - G12) @version 1.0.0 */
class CrmSequenceEnrollment extends Model
{
    protected $table = 'crm_sequence_enrollments';
    protected $fillable = [
        'user_id', 'sequence_id', 'related_type', 'related_id',
        'current_step', 'next_run_at', 'status', 'completed_at',
    ];

    public function forUser(int $userId, string $status = 'active', int $limit = 100): array
    {
        return $this->db->query(
            "SELECT e.*, s.name AS sequence_name
             FROM crm_sequence_enrollments e
             LEFT JOIN crm_sequences s ON s.id = e.sequence_id
             WHERE e.user_id = ? AND e.status = ?
             ORDER BY e.next_run_at ASC LIMIT " . (int) $limit,
            [$userId, $status]
        );
    }

    /** تسجيل جارٍ نشط لنفس الكيان في نفس الـSequence (منع التكرار) */
    public function findActiveEnrollment(int $userId, int $sequenceId, string $relatedType, int $relatedId): ?CrmSequenceEnrollment
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_sequence_enrollments
             WHERE user_id = ? AND sequence_id = ? AND related_type = ? AND related_id = ?
               AND status = 'active' LIMIT 1",
            [$userId, $sequenceId, $relatedType, $relatedId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    public function findOwned(int $userId, int $enrollmentId): ?CrmSequenceEnrollment
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_sequence_enrollments WHERE id = ? AND user_id = ? LIMIT 1",
            [$enrollmentId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}
