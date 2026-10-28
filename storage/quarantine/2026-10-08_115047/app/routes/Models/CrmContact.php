<?php
/** Tourfecto - CRM Contact Model @version 1.0.0 */
class CrmContact extends Model {
    protected $table = 'crm_contacts';
    protected $fillable = ['user_id', 'agency_id', 'name', 'email', 'phone', 'source', 'notes'];
}
