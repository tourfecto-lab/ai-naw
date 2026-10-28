<?php
/** Tourfecto - CRM Deal Model @version 1.0.0 */
class CrmDeal extends Model {
    protected $table = 'crm_deals';
    protected $fillable = ['owner_user_id', 'lead_id', 'contact_id', 'stage_id', 'title', 'value', 'currency', 'probability', 'expected_close_date', 'closed_at', 'status', 'lost_reason'];
}
