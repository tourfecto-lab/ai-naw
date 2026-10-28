<?php

/**
 * Tourfecto - Agency Domain Model (White-Label)
 * @version 1.0.0
 */
class AgencyDomain extends Model
{
    protected $table = 'agency_domains';
    protected $fillable = [
        'agency_id', 'domain', 'status', 'ssl_status', 'verified_at'
    ];
}
