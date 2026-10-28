<?php

/** Tourfecto - Outreach Prospect Model (Phase 10) @version 1.0.0 */
class OutreachProspect extends Model
{
    protected $table = 'outreach_prospects';
    protected $fillable = [
        'user_id', 'website_id', 'domain', 'contact_name', 'contact_email',
        'business_type', 'relevant_page', 'collaboration_idea', 'status',
        'link_url', 'notes',
    ];
}
