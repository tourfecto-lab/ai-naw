<?php
/** Tourfecto - CRM Task Model @version 1.0.0 */
class CrmTask extends Model {
    protected $table = 'crm_tasks';
    protected $fillable = ['assigned_to_user_id', 'related_type', 'related_id', 'title', 'description', 'due_date', 'priority', 'status', 'completed_at'];
}
