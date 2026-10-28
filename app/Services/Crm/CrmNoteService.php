<?php

/** Tourfecto - CRM Note Service @version 1.1.0 */
class CrmNoteService
{
    /**
     * تصحيح: الجدول ENUM('lead','contact','deal'). أي صيغة تانية (crm_leads / leads / Contact...)
     * كانت بتوقع INSERT بخطأ 500 "Data truncated". بنوحّدها، والقيمة غير المعروفة = خطأ واضح.
     */
    private static function normalizeRelatedType($type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $t = strtolower(trim((string) $type));
        $t = preg_replace('/^crm_/', '', $t);
        $map = ['lead' => 'lead', 'leads' => 'lead', 'contact' => 'contact', 'contacts' => 'contact', 'deal' => 'deal', 'deals' => 'deal'];
        if (!isset($map[$t])) {
            throw new Exception('نوع السجل المرتبط غير مدعوم (المسموح: lead, contact, deal)');
        }
        return $map[$t];
    }

    /** $actorUserId اختياري (بند 30 - استكمال) - راجع نفس الشرح في CrmTaskService::create() */
    public function create(int $userId, array $data, ?int $actorUserId = null): CrmNote
    {
        if (empty($data['body'])) {
            throw new Exception('نص الملاحظة مطلوب');
        }
        $actorUserId = $actorUserId ?? $userId;
        $note = new CrmNote([
            'user_id' => $userId,
            'author_user_id' => $actorUserId,
            'related_type' => self::normalizeRelatedType($data['related_type'] ?? null),
            'related_id' => $data['related_id'] ?? null,
            'body' => $data['body'],
            'pinned' => !empty($data['pinned']) ? 1 : 0,
        ]);
        $note->save();

        ActivityLog::record('crm', 'note.created', [
            'user_id' => $actorUserId, 'subject_type' => 'crm_notes', 'subject_id' => (int) $note->getAttribute('id'),
        ]);

        return $note;
    }

    public function forRelated(string $relatedType, int $relatedId): array
    {
        return (new CrmNote())->forRelated($relatedType, $relatedId);
    }
}
