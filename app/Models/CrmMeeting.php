<?php

/** Tourfecto - CRM Meeting / Appointment Model (بند 18) @version 1.1.0 */
class CrmMeeting extends Model
{
    protected $table = 'crm_meetings';
    protected $fillable = [
        'user_id', 'organizer_user_id', 'related_type', 'related_id', 'contact_id',
        'title', 'purpose', 'meeting_link', 'location', 'timezone',
        'starts_at', 'ends_at', 'status', 'summary', 'notes',
    ];

    public function allForUser(int $userId, int $limit = 200): array
    {
        return $this->where(['user_id' => $userId], ['starts_at' => 'ASC'], $limit);
    }

    public function forContact(int $contactId): array
    {
        return $this->where(['contact_id' => $contactId], ['starts_at' => 'DESC']);
    }
}
