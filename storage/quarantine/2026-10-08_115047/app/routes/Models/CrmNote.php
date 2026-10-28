<?php
/** Tourfecto - CRM Note Model @version 1.0.0 */
class CrmNote extends Model {
    protected $table = 'crm_notes';
    protected $fillable = ['author_user_id', 'related_type', 'related_id', 'body', 'pinned'];
}
