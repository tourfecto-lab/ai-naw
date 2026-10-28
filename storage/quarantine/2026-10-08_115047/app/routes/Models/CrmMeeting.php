<?php
/** Tourfecto - CRM Meeting Model @version 1.0.0 */
class CrmMeeting extends Model {
    protected $table = 'crm_meetings';
    protected $fillable = ['organizer_user_id', 'related_type', 'related_id', 'title', 'meeting_link', 'starts_at', 'ends_at', 'status', 'summary'];
}
