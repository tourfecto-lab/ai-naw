<?php
/** Tourfecto - CRM Lead Model @version 1.0.0 */
class CrmLead extends Model {
    protected $table = 'crm_leads';
    protected $fillable = ['contact_id', 'owner_user_id', 'status', 'score', 'last_engagement_at'];
}
